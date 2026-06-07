<?php
require_once __DIR__ . '/PaymentGatewayInterface.php';

class PaystackService implements PaymentGatewayInterface
{
    private $paystack;
    private $publicKey;
    private $secretKey;

    public function initialize(array $keys): void
    {
        $this->publicKey = $keys['public_key'];
        $this->secretKey = $keys['secret_key'];
        // Yabacon Paystack wrapper
        if (class_exists('Yabacon\Paystack')) {
            $this->paystack = new \Yabacon\Paystack($this->secretKey);
        }
    }

    public function createPayment(array $data): array
    {
        // Paystack uses Initialize Transaction
        try {
            // Paystack amount is in kobo (cents)
            $tranx = $this->paystack->transaction->initialize([
                'amount' => $data['amount'],
                'email' => $data['email'], // Required for Paystack
                'reference' => 'pstk_' . uniqid() . '_' . time(),
                'metadata' => [
                    'user_id' => $data['user_id'],
                    'cohort_id' => $data['cohort_id'] ?? null,
                    'custom_fields' => [
                        [
                            'display_name' => "Cohort",
                            'variable_name' => "cohort_title",
                            'value' => $data['description']
                        ]
                    ]
                ]
            ]);

            return [
                'status' => 'success',
                'gateway_id' => $tranx->data->reference, // Use reference as ID
                'authorization_url' => $tranx->data->authorization_url,
                'access_code' => $tranx->data->access_code,
                'requires_redirect' => true
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
        try {
            $tranx = $this->paystack->transaction->verify([
                'reference' => $reference,
            ]);

            $status = 'pending';
            if ($tranx->data->status === 'success') {
                $status = 'success';
            } elseif ($tranx->data->status === 'failed') {
                $status = 'failed';
            }

            return [
                'status' => $status,
                'amount' => $tranx->data->amount,
                'currency' => $tranx->data->currency,
                'metadata' => (array) $tranx->data->metadata
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
            // Paystack Refund API
            $refund = $this->paystack->refund->create([
                'transaction' => $paymentId,
                'amount' => $amount
            ]);

            return [
                'status' => 'success',
                'refund_id' => $refund->data->id
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
