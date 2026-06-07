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
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $this->handlePaymentFailed($paymentIntent);
                break;
            case 'charge.refunded':
                $charge = $event->data->object;
                $this->handleChargeRefunded($charge);
                break;
            case 'charge.dispute.created':
                $dispute = $event->data->object;
                $this->handleDispute($dispute);
                break;
            default:
                error_log("Stripe webhook: Unhandled event type: " . $event->type);
                break;
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
    }

    private function handlePaymentSucceeded($paymentIntent)
    {
        $gatewayId = $paymentIntent->id;
        $amount = $paymentIntent->amount;
        $currency = $paymentIntent->currency;
        $metadata = $paymentIntent->metadata;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_payment_id = ? OR gateway_reference = ? FOR UPDATE");
            $stmt->execute([$gatewayId, $gatewayId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                    }
                }
            } else {
                $userId = $metadata->user_id ?? null;
                $cohortId = $metadata->cohort_id ?? null;

                if ($userId && $cohortId) {
                    $txRef = 'wh_' . bin2hex(random_bytes(12));
                    $stmt = $this->db->prepare("
                        INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                        VALUES (?, ?, ?, ?, 'success', 'stripe', ?, ?, 'Webhook Created', ?)
                    ");
                    $stmt->execute([$txRef, $userId, $amount, $currency, $gatewayId, $gatewayId, $cohortId]);

                    $this->enrollmentService->grantAccess($userId, $cohortId, $txRef);
                }
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Stripe webhook error: " . $e->getMessage());
        }
    }

    private function handlePaymentFailed($paymentIntent)
    {
        $gatewayId = $paymentIntent->id;
        $stmt = $this->db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE gateway_payment_id = ? AND status = 'pending'");
        $stmt->execute([$gatewayId]);
    }

    private function handleChargeRefunded($charge)
    {
        $gatewayId = $charge->payment_intent;
        $stmt = $this->db->prepare("
            UPDATE payments SET
            status = 'refunded',
            refunded_at = CURRENT_TIMESTAMP,
            refund_reason = 'Stripe chargeback/refund'
            WHERE gateway_payment_id = ? AND status = 'success'
        ");
        $stmt->execute([(string)$gatewayId]);

        // Revoke access
        $stmt = $this->db->prepare("SELECT user_id, cohort_id FROM payments WHERE gateway_payment_id = ?");
        $stmt->execute([(string)$gatewayId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment && $payment['cohort_id']) {
            $this->enrollmentService->revokeAccess($payment['user_id'], $payment['cohort_id']);
        }
    }

    private function handleDispute($dispute)
    {
        $paymentIntentId = $dispute->payment_intent ?? null;
        if ($paymentIntentId) {
            $stmt = $this->db->prepare("
                UPDATE payments SET
                status = 'disputed',
                refund_reason = ?
                WHERE gateway_payment_id = ? AND status = 'success'
            ");
            $stmt->execute([$dispute->reason, $paymentIntentId]);
        }
    }
}
