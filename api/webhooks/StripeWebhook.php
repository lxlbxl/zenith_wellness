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
        $quoteId = $metadata->quote_id ?? null;

        // Verify quote if present
        if ($quoteId) {
            $quoteCheck = $this->verifyQuote($quoteId, $amount, $currency);
            if ($quoteCheck !== true) {
                error_log("[Stripe] Quote verification failed: {$quoteCheck} — refunding payment {$gatewayId}");
                $this->refundStripePayment($gatewayId, $amount);
                return;
            }
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_payment_id = ? OR gateway_reference = ? FOR UPDATE");
            $stmt->execute([$gatewayId, $gatewayId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            $grantedUserId = null;
            $grantedCohortId = null;

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                        $grantedUserId = $payment['user_id'];
                        $grantedCohortId = $payment['cohort_id'];
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
                $this->sendCAPIPaymentSuccess($grantedUserId, $amount, $currency, $grantedCohortId);
            }
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Stripe webhook error: " . $e->getMessage());
        }
    }

    private function refundStripePayment($paymentIntentId, $amount)
    {
        try {
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
            $refund = \Stripe\Refund::create([
                'payment_intent' => $paymentIntentId,
                'amount' => $amount,
                'reason' => 'fraudulent',
            ]);
            error_log("[Stripe] Refunded payment {$paymentIntentId}: {$refund->id}");
        } catch (Exception $e) {
            error_log("[Stripe] Failed to refund payment {$paymentIntentId}: " . $e->getMessage());
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
