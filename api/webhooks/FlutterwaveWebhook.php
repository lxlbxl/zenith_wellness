<?php
require_once __DIR__ . '/../services/EnrollmentService.php';

class FlutterwaveWebhook
{
    private $db;
    private $enrollmentService;
    private $secret;

    public function __construct($db)
    {
        $this->db = $db;
        $this->enrollmentService = new EnrollmentService($db);

        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'flutterwave_webhook_secret'");
        $stmt->execute();
        $this->secret = $stmt->fetchColumn();
    }

    public function handle()
    {
        $input = @file_get_contents("php://input");

        if (empty($this->secret)) {
            error_log("Flutterwave webhook: Secret not configured");
            http_response_code(500);
            echo json_encode(['error' => 'Webhook secret not configured']);
            return;
        }

        // Verify signature using HMAC-SHA256
        $signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';
        $expectedSignature = hash_hmac('sha256', $input, $this->secret);

        if (empty($signature) || !hash_equals($expectedSignature, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $event = json_decode($input);

        if (!isset($event->event)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing event field']);
            return;
        }

        switch ($event->event) {
            case 'charge.completed':
            case 'charge.success':
                if (isset($event->data->status) && in_array(strtolower($event->data->status), ['successful', 'success'])) {
                    $this->handleSuccess($event->data);
                } else {
                    $this->handleFailed($event->data);
                }
                break;
            case 'transfer.success':
                error_log("Flutterwave webhook: transfer.success event received");
                break;
            default:
                error_log("Flutterwave webhook: Unhandled event: " . $event->event);
                break;
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
    }

    private function handleSuccess($data)
    {
        $txRef = $data->tx_ref;
        $flwId = $data->id;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ? FOR UPDATE");
            $stmt->execute([$txRef]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', gateway_payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$flwId, $payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                    }
                }
            } else {
                // Create from metadata if available
                $meta = $data->meta ?? $data->metadata ?? null;
                if ($meta) {
                    $userId = is_object($meta) ? ($meta->user_id ?? null) : ($meta['user_id'] ?? null);
                    $cohortId = is_object($meta) ? ($meta->cohort_id ?? null) : ($meta['cohort_id'] ?? null);

                    if ($userId && $cohortId) {
                        $newRef = 'wh_' . bin2hex(random_bytes(12));
                        $currency = $data->currency ?? 'NGN';
                        $amount = isset($data->amount) ? (int)($data->amount * 100) : 0; // Convert to cents
                        $stmt = $this->db->prepare("
                            INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                            VALUES (?, ?, ?, ?, 'success', 'flutterwave', ?, ?, 'Webhook Created', ?)
                        ");
                        $stmt->execute([$newRef, $userId, $amount, $currency, $txRef, $flwId, $cohortId]);

                        $this->enrollmentService->grantAccess($userId, $cohortId, $newRef);
                    }
                }
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Flutterwave webhook error: " . $e->getMessage());
        }
    }

    private function handleFailed($data)
    {
        $txRef = $data->tx_ref ?? null;
        if ($txRef) {
            $stmt = $this->db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE gateway_reference = ?");
            $stmt->execute([$txRef]);
        }
    }
}
