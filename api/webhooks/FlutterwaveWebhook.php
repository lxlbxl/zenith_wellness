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
        $currency = strtoupper($data->currency ?? 'NGN');
        $amount = isset($data->amount) ? (int)($data->amount * 100) : 0; // Convert to cents

        // Extract metadata for quote verification
        $meta = $data->meta ?? $data->metadata ?? null;
        $quoteId = null;
        if ($meta) {
            $quoteId = is_object($meta) ? ($meta->quote_id ?? null) : ($meta['quote_id'] ?? null);
        }

        // Verify quote if present
        if ($quoteId) {
            $quoteCheck = $this->verifyQuote($quoteId, $amount, $currency);
            if ($quoteCheck !== true) {
                error_log("[Flutterwave] Quote verification failed: {$quoteCheck} — refunding payment {$flwId}");
                $this->refundFlwPayment($flwId);
                return;
            }
        }

        $this->db->beginTransaction();
        try {
            $grantedUserId = null;
            $grantedCohortId = null;

            $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_reference = ? FOR UPDATE");
            $stmt->execute([$txRef]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                if ($payment['status'] !== 'success') {
                    $stmt = $this->db->prepare("UPDATE payments SET status = 'success', gateway_payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$flwId, $payment['id']]);

                    if ($payment['cohort_id']) {
                        $this->enrollmentService->grantAccess($payment['user_id'], $payment['cohort_id'], $payment['id']);
                        $grantedUserId = $payment['user_id'];
                        $grantedCohortId = $payment['cohort_id'];
                    }
                }
            } else {
                // Create from metadata if available
                if ($meta) {
                    $userId = is_object($meta) ? ($meta->user_id ?? null) : ($meta['user_id'] ?? null);
                    $cohortId = is_object($meta) ? ($meta->cohort_id ?? null) : ($meta['cohort_id'] ?? null);

                    if ($userId && $cohortId) {
                        $newRef = 'wh_' . bin2hex(random_bytes(12));
                        $stmt = $this->db->prepare("
                            INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_reference, gateway_payment_id, description, cohort_id)
                            VALUES (?, ?, ?, ?, 'success', 'flutterwave', ?, ?, 'Webhook Created', ?)
                        ");
                        $stmt->execute([$newRef, $userId, $amount, $currency, $txRef, $flwId, $cohortId]);

                        $this->enrollmentService->grantAccess($userId, $cohortId, $newRef);
                        $grantedUserId = $userId;
                        $grantedCohortId = $cohortId;
                    }
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

    private function refundFlwPayment(string $transactionId): void
    {
        $secretKey = getenv('FLUTTERWAVE_SECRET_KEY') ?: (defined('FLUTTERWAVE_SECRET_KEY') ? FLUTTERWAVE_SECRET_KEY : '');
        if (empty($secretKey)) {
            error_log("[Flutterwave] Cannot refund {$transactionId}: no secret key configured");
            return;
        }

        $url = "https://api.flutterwave.com/v3/transactions/{$transactionId}/refund";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$secretKey}\r\nContent-Type: application/json",
                'content' => '{}',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        error_log("[Flutterwave] Refunded transaction {$transactionId}: " . ($response ?: 'no response'));
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
