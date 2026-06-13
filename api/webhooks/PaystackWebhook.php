<?php
require_once __DIR__ . '/../services/EnrollmentService.php';

class PaystackWebhook
{
    private $db;
    private $enrollmentService;
    private $secret;

    public function __construct($db)
    {
        $this->db = $db;
        $this->enrollmentService = new EnrollmentService($db);

        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'paystack_webhook_secret'");
        $stmt->execute();
        $this->secret = $stmt->fetchColumn();
    }

    public function handle()
    {
        if ((strtoupper($_SERVER['REQUEST_METHOD'] ?? '') != 'POST') || !array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing signature header']);
            return;
        }

        $input = @file_get_contents("php://input");

        if ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $this->secret)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $event = json_decode($input);

        switch ($event->event) {
            case 'charge.success':
                $this->handleChargeSuccess($event->data);
                break;
            case 'charge.failed':
                $this->handleChargeFailed($event->data);
                break;
            default:
                error_log("Paystack webhook: Unhandled event: " . $event->event);
                break;
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
    }

    private function handleChargeSuccess($data)
    {
        $reference = $data->reference;
        $amount = $data->amount;
        $metadata = $data->metadata;
        $quoteId = $metadata->quote_id ?? null;

        // Determine currency and convert amount to cents if needed
        $currency = strtoupper($data->currency ?? 'NGN');
        $zeroDecimal = ['JPY', 'KRW', 'BIF', 'CLP', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $amountCents = in_array($currency, $zeroDecimal) ? (int) $amount : (int) ($amount * 100);

        // Verify quote if present
        if ($quoteId) {
            $quoteCheck = $this->verifyQuote($quoteId, $amountCents, $currency);
            if ($quoteCheck !== true) {
                error_log("[Paystack] Quote verification failed: {$quoteCheck} — refunding payment {$reference}");
                $this->refundPaystackPayment($reference);
                return;
            }
        }

        $this->db->beginTransaction();
        try {
            $grantedUserId = null;
            $grantedCohortId = null;

            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ? FOR UPDATE");
            $stmt->execute([$reference]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', gateway_payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$data->id, $payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                        $grantedUserId = $payment['user_id'];
                        $grantedCohortId = $payment['cohort_id'];
                    }
                }
            } else {
                // Create from metadata
                $userId = $metadata->user_id ?? null;
                $cohortId = $metadata->cohort_id ?? null;

                if ($userId && $cohortId) {
                    $txRef = 'wh_' . bin2hex(random_bytes(12));
                    $stmt = $this->db->prepare("
                        INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                        VALUES (?, ?, ?, ?, 'success', 'paystack', ?, ?, 'Webhook Created', ?)
                    ");
                    $stmt->execute([$txRef, $userId, $amountCents, $currency, $reference, $data->id, $cohortId]);

                    $this->enrollmentService->grantAccess($userId, $cohortId, $txRef);
                    $grantedUserId = $userId;
                    $grantedCohortId = $cohortId;
                }
            }

            $this->db->commit();

            // Mark quote as used
            if ($quoteId) {
                $this->markQuoteUsed($quoteId);
            }

            // Send CAPI payment_success event
            if ($grantedUserId && $grantedCohortId) {
                $this->sendCAPIPaymentSuccess($grantedUserId, $amountCents, $currency, $grantedCohortId);
            }
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Paystack webhook error: " . $e->getMessage());
        }
    }

    private function handleChargeFailed($data)
    {
        $reference = $data->reference ?? null;
        if ($reference) {
            $stmt = $this->db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE gateway_reference = ?");
            $stmt->execute([$reference]);
        }
    }

    // ==================== QUOTE VERIFICATION ====================

    private function verifyQuote(string $quoteId, int $amountPaid, string $currency): bool|string
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payment_quotes WHERE id = ?");
            $stmt->execute([$quoteId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quote) {
                return "Quote not found";
            }

            if ($quote['status'] !== 'pending') {
                return "Quote status is '{$quote['status']}', expected 'pending'";
            }

            if (strtotime($quote['expires_at']) < time()) {
                return "Quote expired at {$quote['expires_at']}";
            }

            if ((int) $quote['converted_amount'] !== $amountPaid) {
                return "Amount mismatch: expected {$quote['converted_amount']}, got {$amountPaid}";
            }

            if (strtoupper($quote['currency']) !== strtoupper($currency)) {
                return "Currency mismatch: expected {$quote['currency']}, got {$currency}";
            }

            return true;
        } catch (Exception $e) {
            return "DB error: " . $e->getMessage();
        }
    }

    private function markQuoteUsed(string $quoteId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE payment_quotes SET status = 'used' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$quoteId]);
        } catch (Exception $e) {
            error_log("[Quote] Failed to mark quote {$quoteId} as used: " . $e->getMessage());
        }
    }

    private function refundPaystackPayment(string $reference): void
    {
        $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: (defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '');
        if (empty($secretKey)) {
            error_log("[Paystack] Cannot refund {$reference}: no secret key configured");
            return;
        }

        $url = "https://api.paystack.co/refund";
        $postData = json_encode(['transaction' => $reference]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$secretKey}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($postData),
                'content' => $postData,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        error_log("[Paystack] Refunded transaction {$reference}: " . ($response ?: 'no response'));
    }

    // ==================== CAPI PAYMENT EVENT ====================

    private function sendCAPIPaymentSuccess(string $userId, int $amountCents, string $currency, string $cohortId): void
    {
        try {
            $stmt = $this->db->prepare("SELECT email, name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return;
            }

            $accessToken = getenv('META_ACCESS_TOKEN') ?: '';
            $pixelId = getenv('META_PIXEL_ID') ?: '';

            if (empty($accessToken) || empty($pixelId)) {
                return;
            }

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            $ip = explode(',', $ip)[0];

            $eventData = [
                'access_token' => $accessToken,
                'data' => [
                    [
                        'event_name' => 'Purchase',
                        'event_time' => time(),
                        'action_source' => 'website',
                        'user_data' => [
                            'client_ip_address' => trim($ip),
                            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                            'em' => hash('sha256', strtolower($user['email'])),
                            'fn' => hash('sha256', strtolower($user['name'] ?? '')),
                        ],
                        'custom_data' => [
                            'value' => $amountCents / 100,
                            'currency' => strtoupper($currency),
                            'program_id' => $cohortId,
                        ],
                    ],
                ],
            ];

            $jsonData = json_encode($eventData);
            $url = "https://graph.facebook.com/v21.0/{$pixelId}/events";
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($jsonData),
                    'content' => $jsonData,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);

            @file_get_contents($url, false, $context);
        } catch (Exception $e) {
            error_log("[CAPI Webhook] Failed to send payment_success event: " . $e->getMessage());
        }
    }
}
