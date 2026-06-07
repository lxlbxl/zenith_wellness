<?php
interface PaymentGatewayInterface
{
    public function initialize(array $keys): void;

    // Returns array with 'client_secret' (Stripe) or 'authorization_url' (Paystack/Flutterwave)
    // plus payment_id and other metadata
    public function createPayment(array $data): array;

    // Verifies payment status with the gateway
    public function verifyPayment(string $reference): array;

    // Refunds a payment
    public function refundPayment(string $paymentId, int $amount): array;

    // Returns the public key for frontend usage
    public function getPublicKey(): string;
}
