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
            exit();
        }

        $input = @file_get_contents("php://input");

        if ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $this->secret)) {
            exit();
        }

        $event = json_decode($input);

        switch ($event->event) {
            case 'charge.success':
                $this->handleChargeSuccess($event->data);
                break;
        }

        http_response_code(200);
    }

    private function handleChargeSuccess($data)
    {
        $reference = $data->reference;
        $amount = $data->amount; // in kobo
        $metadata = $data->metadata;

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ?");
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
        }
        // Logic to purely rely on metadata to create payment if missing can be added here similar to stripe
    }
}
