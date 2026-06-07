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

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ? FOR UPDATE");
            $stmt->execute([$reference]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', gateway_payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$data->id, $payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                    }
                }
            } else {
                // Create from metadata
                $userId = $metadata->user_id ?? null;
                $cohortId = $metadata->cohort_id ?? null;

                if ($userId && $cohortId) {
                    $txRef = 'wh_' . bin2hex(random_bytes(12));
                    $currency = $data->currency ?? 'NGN';
                    $stmt = $this->db->prepare("
                        INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                        VALUES (?, ?, ?, ?, 'success', 'paystack', ?, ?, 'Webhook Created', ?)
                    ");
                    $stmt->execute([$txRef, $userId, $amount, $currency, $reference, $data->id, $cohortId]);

                    $this->enrollmentService->grantAccess($userId, $cohortId, $txRef);
                }
            }

            $this->db->commit();
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
}
