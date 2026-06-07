<?php
require_once __DIR__ . '/../services/EnrollmentService.php';

class StripeWebhook
{
    private $db;
    private $enrollmentService;
    private $secret;

    public function __construct($db)
    {
        $this->db = $db;
        $this->enrollmentService = new EnrollmentService($db);

        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'stripe_webhook_secret'");
        $stmt->execute();
        $this->secret = $stmt->fetchColumn();
    }

    public function handle()
    {
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $this->secret
            );
        } catch (\UnexpectedValueException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
            exit();
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $this->handlePaymentSucceeded($paymentIntent);
                break;
            case 'charge.refunded':
                // Handle refund logic if needed
                break;
            default:
                // Unexpected event type
                break;
        }

        http_response_code(200);
    }

    private function handlePaymentSucceeded($paymentIntent)
    {
        $gatewayId = $paymentIntent->id;
        $amount = $paymentIntent->amount;
        $currency = $paymentIntent->currency;
        $metadata = $paymentIntent->metadata;

        // Use our internal txRef if stored in metadata, or look up by gateway ID
        // Stripe PaymentIntent ID is unique, so looking up by gateway_reference or gateway_payment_id works.

        // Find existing payment record or update it
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_payment_id = ? OR gateway_reference = ?");
        $stmt->execute([$gatewayId, $gatewayId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        // Sometimes webhook comes before internal record creation (rare but possible with async)
        // Or if record exists:
        if ($payment) {
            if ($payment['status'] !== 'success') {
                $stmt = $this->db->prepare("UPDATE payments SET status = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$payment['id']]);

                if ($payment['cohort_id']) {
                    $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                }
            }
        } else {
            // Payment happened but our system doesn't know about it?
            // Could be created here if we trust metadata
            $userId = $metadata->user_id ?? null;
            $cohortId = $metadata->cohort_id ?? null;

            if ($userId && $cohortId) {
                // Create successful payment record
                $txRef = uniqid('wh_');
                $stmt = $this->db->prepare("
                    INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                    VALUES (?, ?, ?, ?, 'success', 'stripe', ?, ?, 'Webhook Created', ?)
                ");
                $stmt->execute([$txRef, $userId, $amount, $currency, $gatewayId, $gatewayId, $cohortId]);

                $this->enrollmentService->grantAccess($userId, $cohortId, $txRef);
            }
        }
    }
}
