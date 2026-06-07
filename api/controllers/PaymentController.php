<?php
require_once __DIR__ . '/../services/PaymentGatewayFactory.php';
require_once __DIR__ . '/../services/EnrollmentService.php';
require_once __DIR__ . '/../services/FXRateService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class PaymentController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function process($action)
    {
        // Maintenance mode: block payment creation
        if (!defined('SALES_ENABLED') || !SALES_ENABLED) {
            // Allow admin endpoints
            $adminOnly = ['transactions', 'refund'];
            // Allow confirmation (webhook processing must work)
            $readOnly = ['config', 'confirm'];

            if (!in_array($action, array_merge($adminOnly, $readOnly))) {
                http_response_code(503);
                echo json_encode([
                    'error' => 'maintenance_mode',
                    'message' => 'Platform undergoing upgrades. Payments temporarily paused. Existing users retain access.'
                ]);
                return;
            }
        }

        // Public endpoints
        if ($action === 'create-intent') {
            $this->createPaymentIntent();
            return;
        } elseif ($action === 'confirm') {
            $this->confirmPayment();
            return;
        } elseif ($action === 'config') {
            $this->getConfig();
            return;
        }

        // Admin endpoints
        if ($action === 'transactions') {
            if ($this->verifyAdmin()) {
                $this->getTransactions();
            } else {
                http_response_code(403);
            }
            return;
        } elseif ($action === 'refund') {
            if ($this->verifyAdmin()) {
                $this->refundPayment();
            } else {
                http_response_code(403);
            }
            return;
        }

        http_response_code(404);
        echo json_encode(["message" => "Payment action not found"]);
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches))
            return false;

        $claims = AuthMiddleware::verifyToken($matches[1]);
        if (!$claims)
            return false;

        return ($claims['data']['role'] ?? '') === 'admin';
    }

    private function getUserIdFromToken()
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            $claims = AuthMiddleware::verifyToken($matches[1]);
            if ($claims) {
                return $claims['data']['id'] ?? null;
            }
        }
        return null;
    }

    private function getConfig()
    {
        try {
            $activeGateway = PaymentGatewayFactory::getActiveGatewayName($this->db);
            $service = PaymentGatewayFactory::create($activeGateway, $this->db);

            $fxService = new FXRateService($this->db);

            // Get all available currencies (Flutterwave-focused)
            $supportedCurrencies = PaymentGatewayFactory::getSupportedCurrencies($activeGateway);

            // Get exchange rates
            $rates = $fxService->getAllRates();
            $rateMap = [];
            foreach ($rates as $r) {
                $rateMap[$r['target_currency']] = (float) $r['rate'];
            }

            echo json_encode([
                'gateway' => $activeGateway,
                'publicKey' => $service->getPublicKey(),
                'currencies' => $supportedCurrencies,
                'rates' => $rateMap,
                'flutterwaveCurrencies' => PaymentGatewayFactory::FLUTTERWAVE_CURRENCIES
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function createPaymentIntent()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $userId = $input['user_id'] ?? ($this->getUserIdFromToken());

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        try {
            $amount = $input['amount'];
            $currency = strtoupper($input['currency'] ?? 'USD');
            $cohortId = $input['cohort_id'] ?? null;

            // If amount is not provided but cohort_id is, get price for the currency
            if (!$amount && $cohortId) {
                $fxService = new FXRateService($this->db);
                $priceData = $fxService->getPriceForCohort($cohortId, $currency);
                $amount = $priceData['amount'];

                if ($amount <= 0) {
                    http_response_code(400);
                    echo json_encode(['message' => "Price not available for currency $currency"]);
                    return;
                }
            }

            if (!$amount) {
                http_response_code(400);
                echo json_encode(['message' => 'Amount is required']);
                return;
            }

            // Determine best gateway for the currency
            $activeGateway = PaymentGatewayFactory::getActiveGatewayName($this->db);
            $supportedCurrencies = PaymentGatewayFactory::getSupportedCurrencies($activeGateway);

            if (!in_array($currency, $supportedCurrencies)) {
                // Try to find a gateway that supports this currency
                try {
                    $activeGateway = PaymentGatewayFactory::getBestGatewayForCurrency($currency, $this->db);
                } catch (Exception $e) {
                    http_response_code(400);
                    echo json_encode([
                        'message' => "Currency $currency is not supported by any configured gateway"
                    ]);
                    return;
                }
            }

            $service = PaymentGatewayFactory::create($activeGateway, $this->db);

            // Fetch user email details
            $stmt = $this->db->prepare("SELECT email, name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("User not found");
            }

            $paymentData = [
                'amount' => $amount,
                'currency' => $currency,
                'email' => $user['email'],
                'name' => $user['name'],
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'description' => $input['description'] ?? 'Purchase'
            ];

            $result = $service->createPayment($paymentData);

            if ($result['status'] === 'success') {
                $txRef = 'pay_' . bin2hex(random_bytes(12));
                $stmt = $this->db->prepare("
                    INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                    VALUES (:id, :uid, :amt, :curr, 'pending', :gw, :gref, :gpid, :desc, :cid)
                ");
                $stmt->execute([
                    ':id' => $txRef,
                    ':uid' => $userId,
                    ':amt' => $amount,
                    ':curr' => $currency,
                    ':gw' => $activeGateway,
                    ':gref' => $result['gateway_id'] ?? $txRef,
                    ':gpid' => $result['gateway_id'] ?? null,
                    ':desc' => $input['description'] ?? 'Purchase',
                    ':cid' => $cohortId
                ]);

                echo json_encode(array_merge($result, ['txRef' => $txRef]));
            } else {
                http_response_code(400);
                echo json_encode($result);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
    }

    private function confirmPayment()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $paymentId = $data['payment_id'] ?? null;
        $gatewayRef = $data['gateway_reference'] ?? null;

        if (!$paymentId && !$gatewayRef) {
            http_response_code(400);
            return;
        }

        try {
            // Use transaction to prevent race condition with webhooks
            $this->db->beginTransaction();

            // Lock the row to prevent concurrent processing
            if ($paymentId) {
                $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
                $stmt->execute([$paymentId]);
            } else {
                $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ? FOR UPDATE");
                $stmt->execute([$gatewayRef]);
            }
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(['message' => 'Payment not found']);
                return;
            }

            // Already processed - idempotency guard
            if ($payment['status'] === 'success') {
                $this->db->rollBack();
                echo json_encode(['status' => 'success', 'message' => 'Payment already confirmed']);
                return;
            }

            $service = PaymentGatewayFactory::create($payment['gateway'], $this->db);
            $verifyResult = $service->verifyPayment($payment['gateway_reference']);

            if ($verifyResult['status'] === 'success') {
                // Update status within transaction
                $stmt = $this->db->prepare("UPDATE payments SET status = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$payment['id']]);

                // Grant Access within transaction
                if ($payment['cohort_id']) {
                    $enrollmentService = new EnrollmentService($this->db);
                    $enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                }

                $this->db->commit();

                // Send Receipt Email (outside transaction - don't block on email)
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    require_once __DIR__ . '/../services/NotificationService.php';

                    $emailService = new EmailService($this->db);
                    $notificationService = new NotificationService($this->db);

                    $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
                    $stmt->execute([$payment['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        $amountFormatted = $this->formatCurrencyAmount($payment['amount'], $payment['currency']);
                        $emailService->sendTemplate($user['email'], 'receipt', [
                            'name' => $user['name'],
                            'amount' => $amountFormatted['amount'],
                            'currency' => $amountFormatted['symbol'] . ' ' . strtoupper($payment['currency']),
                            'description' => $payment['description'],
                            'date' => date('F j, Y'),
                            'receipt_id' => $payment['id'],
                            'year' => date('Y')
                        ]);

                        $notificationService->create(
                            $payment['user_id'],
                            'success',
                            'Payment Successful',
                            "Your payment of " . $amountFormatted['formatted'] . " was successful.",
                            '/settings?tab=billing'
                        );
                    }
                } catch (Exception $e) {
                    error_log("Failed to send receipt/notification: " . $e->getMessage());
                }

                echo json_encode(['status' => 'success', 'message' => 'Payment confirmed']);
            } else {
                $this->db->rollBack();
                if ($verifyResult['status'] === 'failed') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$payment['id']]);
                }
                echo json_encode(['status' => 'pending', 'message' => 'Payment verification pending or failed']);
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
    }

    private function formatCurrencyAmount($amountCents, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'BIF', 'CLP', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $currency = strtoupper($currency);

        $divisor = in_array($currency, $zeroDecimalCurrencies) ? 1 : 100;
        $amount = $amountCents / $divisor;

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'NGN' => '₦',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'UGX' => 'USh',
            'TZS' => 'TSh',
            'RWF' => 'RF',
            'ZAR' => 'R',
            'EGP' => 'E£',
            'MAD' => 'MAD',
            'XOF' => 'CFA',
            'XAF' => 'FCFA',
            'INR' => '₹',
            'JPY' => '¥',
            'KRW' => '₩',
            'CNY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        if (in_array($currency, $zeroDecimalCurrencies)) {
            $formatted = $symbol . number_format($amount, 0);
        } else {
            $formatted = $symbol . number_format($amount, 2);
        }

        return [
            'amount' => $amount,
            'symbol' => $symbol,
            'formatted' => $formatted,
            'currency' => $currency,
            'divisor' => $divisor
        ];
    }

    private function refundPayment()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $paymentId = $input['payment_id'];
        $reason = $input['reason'] ?? 'Admin refund';

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment || $payment['status'] !== 'success') {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid payment for refund']);
            return;
        }

        try {
            $service = PaymentGatewayFactory::create($payment['gateway'], $this->db);
            $result = $service->refundPayment($payment['gateway_payment_id'], $payment['amount']);

            if ($result['status'] === 'success') {
                $this->db->beginTransaction();
                try {
                    $stmt = $this->db->prepare("
                        UPDATE payments SET
                        status = 'refunded',
                        refund_id = :rid,
                        refunded_at = CURRENT_TIMESTAMP,
                        refund_reason = :rr
                        WHERE id = :pid
                    ");
                    $stmt->execute([
                        ':rid' => $result['refund_id'],
                        ':rr' => $reason,
                        ':pid' => $paymentId
                    ]);

                    // Revoke Access
                    if ($payment['cohort_id']) {
                        $enrollmentService = new EnrollmentService($this->db);
                        $enrollmentService->revokeAccess($payment['user_id'], $payment['cohort_id']);
                    }

                    $this->db->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Refund processed']);
                } catch (Exception $e) {
                    $this->db->rollBack();
                    throw $e;
                }
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
    }

    private function getTransactions()
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? min(100, max(10, (int) $_GET['per_page'])) : 50;
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM payments p
        ");
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT p.*, u.name as user_name, u.email as user_email
            FROM payments p
            LEFT JOIN users u ON p.user_id = u.id
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'has_next' => ($page * $perPage) < $total,
                'has_prev' => $page > 1
            ]
        ]);
    }
}
?>