<?php
require_once __DIR__ . '/../services/FXRateService.php';

class PaymentQuoteController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function createQuote()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['message' => 'Method not allowed']);
            return;
        }

        $input = json_decode(file_get_contents("php://input"), true);

        $cohortId = $input['cohort_id'] ?? null;
        $currency = strtoupper($input['currency'] ?? 'USD');
        $discountCode = $input['discount_code'] ?? null;

        if (!$cohortId) {
            http_response_code(400);
            echo json_encode(['message' => 'cohort_id is required']);
            return;
        }

        try {
            // Get user from auth token if available
            $userId = $this->getUserIdFromToken();

            // Fetch cohort price
            $stmt = $this->db->prepare("SELECT id, title, price FROM cohorts WHERE id = ?");
            $stmt->execute([$cohortId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cohort) {
                http_response_code(404);
                echo json_encode(['message' => 'Cohort not found']);
                return;
            }

            $baseAmount = (int) $cohort['price'];

            // Apply FX conversion
            $fxService = new FXRateService($this->db);
            $convertedAmount = $fxService->convertAmount($baseAmount, 'USD', $currency);

            // Apply discount if code provided
            $discountAmount = 0;
            $discountApplied = null;
            if ($discountCode) {
                $stmt = $this->db->prepare("
                    SELECT * FROM discount_codes
                    WHERE code = :code AND cohort_id = :cohort_id
                    AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                    AND (max_uses IS NULL OR used_count < max_uses)
                ");
                $stmt->execute([':code' => $discountCode, ':cohort_id' => $cohortId]);
                $discount = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($discount) {
                    $discountApplied = $discount['code'];

                    if ($discount['discount_type'] === 'percent') {
                        $discountAmount = (int) round($convertedAmount * (float) $discount['discount_value'] / 100);
                    } else {
                        // Fixed discount - convert fixed amount to target currency
                        $discountAmount = min(
                            $fxService->convertAmount((int) $discount['discount_value'], 'USD', $currency),
                            $convertedAmount
                        );
                    }

                    // Increment used_count
                    $stmt = $this->db->prepare("UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ?");
                    $stmt->execute([$discount['id']]);
                }
            }

            $finalAmount = max(0, $convertedAmount - $discountAmount);
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

            // Sign the quote with HMAC-SHA256
            $quoteId = 'qte_' . bin2hex(random_bytes(12));
            $signatureData = implode('|', [$quoteId, $cohortId, $finalAmount, $currency, $expiresAt]);
            $secret = getenv('PAYMENT_QUOTE_SECRET') ?: (defined('PAYMENT_QUOTE_SECRET') ? PAYMENT_QUOTE_SECRET : 'dev-quote-secret-change-in-production');
            $signature = hash_hmac('sha256', $signatureData, $secret);

            // Store quote in DB
            $stmt = $this->db->prepare("
                INSERT INTO payment_quotes (id, cohort_id, user_id, amount, currency, converted_amount,
                    discount_code, order_bump_amount, signature, status, expires_at, created_at)
                VALUES (:id, :cohort_id, :user_id, :amount, :currency, :converted_amount,
                    :discount_code, :order_bump_amount, :signature, 'pending', :expires_at, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':id' => $quoteId,
                ':cohort_id' => $cohortId,
                ':user_id' => $userId,
                ':amount' => $baseAmount,
                ':currency' => $currency,
                ':converted_amount' => $finalAmount,
                ':discount_code' => $discountApplied,
                ':order_bump_amount' => 0,
                ':signature' => $signature,
                ':expires_at' => $expiresAt,
            ]);

            http_response_code(201);
            echo json_encode([
                'quote_id' => $quoteId,
                'cohort_id' => $cohortId,
                'cohort_title' => $cohort['title'],
                'amount' => $baseAmount,
                'currency' => $currency,
                'converted_amount' => $finalAmount,
                'discount_code' => $discountApplied,
                'discount_amount' => $discountAmount,
                'expires_at' => $expiresAt,
                'signature' => $signature,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create quote', 'error' => $e->getMessage()]);
        }
    }

    private function getUserIdFromToken()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            require_once __DIR__ . '/../middleware/AuthMiddleware.php';
            $payload = AuthMiddleware::verifyToken($matches[1]);
            if ($payload) {
                return $payload['data']['id'] ?? null;
            }
        }
        return null;
    }
}
