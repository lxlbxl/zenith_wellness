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
        $signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';

        if (!$signature || ($signature !== $this->secret)) {
            // Check if secret is configured at all
            if (!empty($this->secret)) {
                http_response_code(401);
                exit();
            }
        }

        $input = @file_get_contents("php://input");
        $event = json_decode($input);

        if (!isset($event->event) || $event->event !== 'charge.completed') {
            // Flutterwave sends different events, we care about charge.completed or similar
            // Some versions send event.type = 'CARD_TRANSACTION'
            // Let's assume standardized hook payload
        }

        if (isset($event->data->status) && $event->data->status === 'successful') {
            $this->handleSuccess($event->data);
        }

        http_response_code(200);
    }

    private function handleSuccess($data)
    {
        $txRef = $data->tx_ref;
        $flwId = $data->id;

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ?");
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
        }
    }
}
