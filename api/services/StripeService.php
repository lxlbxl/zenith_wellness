<?php
require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/../config/Database.php';

class StripeService implements PaymentGatewayInterface
{
    private $stripe;
    private $publicKey;
    private $secretKey;

    public function initialize(array $keys): void
    {
        $this->publicKey = $keys['public_key'];
        $this->secretKey = $keys['secret_key'];
        \Stripe\Stripe::setApiKey($this->secretKey);
    }

    public function createPayment(array $data): array
    {
        // Stripe uses PaymentIntents
        // Amount is in cents
        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'metadata' => [
                    'user_id' => $data['user_id'],
                    'cohort_id' => $data['cohort_id'] ?? null,
                    'description' => $data['description']
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            return [
                'status' => 'success',
                'gateway_id' => $intent->id,
                'client_secret' => $intent->client_secret,
                'requires_redirect' => false // Stripe Elements handles this on frontend
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function verifyPayment(string $reference): array
    {
        // For Stripe, reference is the PaymentIntent ID
        try {
            $intent = \Stripe\PaymentIntent::retrieve($reference);

            $status = 'pending';
            if ($intent->status === 'succeeded') {
                $status = 'success';
            } elseif ($intent->status === 'canceled') {
                $status = 'failed';
            }

            return [
                'status' => $status,
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'metadata' => $intent->metadata->toArray()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function refundPayment(string $paymentId, int $amount): array
    {
        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $paymentId,
                'amount' => $amount, // partial refunds possible
            ]);

            return [
                'status' => 'success',
                'refund_id' => $refund->id
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
}
