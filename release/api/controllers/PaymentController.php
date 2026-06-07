<?php
require_once __DIR__ . '/../services/PaymentGatewayFactory.php';
require_once __DIR__ . '/../services/EnrollmentService.php';

class PaymentController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function process($action)
    {
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

        $parts = explode('.', $matches[1]);
        if (count($parts) < 2)
            return false;

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        return ($payload['role'] ?? '') === 'admin';
    }

    private function getUserIdFromToken()
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            $parts = explode('.', $matches[1]);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                return $payload['id'] ?? null;
            }
        }
        return null;
    }

    private function getConfig()
    {
        try {
            $activeGateway = PaymentGatewayFactory::getActiveGatewayName($this->db);
            $service = PaymentGatewayFactory::create($activeGateway, $this->db);

            // Get currency from settings or default to USD
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'payment_currency'");
            $stmt->execute();
            $currency = $stmt->fetchColumn() ?: 'USD';

            echo json_encode([
                'gateway' => $activeGateway,
                'publicKey' => $service->getPublicKey(),
                'currency' => $currency
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
            $activeGateway = PaymentGatewayFactory::getActiveGatewayName($this->db);
            $service = PaymentGatewayFactory::create($activeGateway, $this->db);

            $amount = $input['amount']; // In smallest unit (cents/kobo)
            $currency = $input['currency'] ?? 'USD';
            $cohortId = $input['cohort_id'] ?? null;

            // Fetch user email details if needed by gateway
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
                // Save pending record
                $txRef = uniqid('pay_');
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
                    ':gref' => $result['gateway_id'] ?? $txRef, // Reference used for tracking
                    ':gpid' => $result['gateway_id'] ?? null,  // Actual ID from gateway
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
        // This is primarily for frontend confirmation, real security relies on webhooks
        // checking the txRef (our internal ID) or gateway reference

        // However, for immediate frontend feedback if webhooks are slow/delayed:
        $paymentId = $data['payment_id'] ?? null; // Internal txRef
        $gatewayRef = $data['gateway_reference'] ?? null; // Reference from gateway redirect/modal

        if (!$paymentId && !$gatewayRef) {
            http_response_code(400);
            return;
        }

        // Look up payment
        if ($paymentId) {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ?");
            $stmt->execute([$gatewayRef]);
        }
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            http_response_code(404);
            echo json_encode(['message' => 'Payment not found']);
            return;
        }

        // Verification Logic
        try {
            $service = PaymentGatewayFactory::create($payment['gateway'], $this->db);
            // Verify using the gateway's reference (might be different from our internal ID)
            $verifyResult = $service->verifyPayment($payment['gateway_reference']); // Or gateway_payment_id depending on gateway

            if ($verifyResult['status'] === 'success') {
                // Update status
                $stmt = $this->db->prepare("UPDATE payments SET status = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$payment['id']]);

                // Grant Access
                if ($payment['cohort_id']) {
                    $enrollmentService = new EnrollmentService($this->db);
                    $enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                }

                // Send Receipt Email
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    require_once __DIR__ . '/../services/NotificationService.php';

                    $emailService = new EmailService($this->db);
                    $notificationService = new NotificationService($this->db);

                    // Fetch user details mostly existing in $payment sometimes or fetch again
                    $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
                    $stmt->execute([$payment['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        // Send Email
                        $emailService->sendTemplate($user['email'], 'receipt', [
                            'name' => $user['name'],
                            'amount' => ($payment['amount'] / 100), // Assuming cents
                            'currency' => strtoupper($payment['currency']),
                            'description' => $payment['description'],
                            'date' => date('F j, Y'),
                            'receipt_id' => $payment['id'],
                            'year' => date('Y')
                        ]);

                        // Create Notification
                        $notificationService->create(
                            $payment['user_id'],
                            'success',
                            'Payment Successful',
                            "Your payment of " . strtoupper($payment['currency']) . " " . ($payment['amount'] / 100) . " was successful.",
                            '/settings?tab=billing'
                        );
                    }
                } catch (Exception $e) {
                    error_log("Failed to send receipt/notification: " . $e->getMessage());
                }

                echo json_encode(['status' => 'success', 'message' => 'Payment confirmed']);
            } else {
                // Mark failed if definitive
                if ($verifyResult['status'] === 'failed') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$payment['id']]);
                }
                echo json_encode(['status' => 'pending', 'message' => 'Payment verification pending or failed']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
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

                echo json_encode(['status' => 'success', 'message' => 'Refund processed']);
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
        $stmt = $this->db->query("
            SELECT p.*, u.name as user_name, u.email as user_email 
            FROM payments p 
            LEFT JOIN users u ON p.user_id = u.id 
            ORDER BY p.created_at DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
?>