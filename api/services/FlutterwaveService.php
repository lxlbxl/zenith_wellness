<?php
require_once __DIR__ . '/PaymentGatewayInterface.php';

class FlutterwaveService implements PaymentGatewayInterface
{
    private $publicKey;
    private $secretKey;

    public function initialize(array $keys): void
    {
        $this->publicKey = $keys['public_key'];
        $this->secretKey = $keys['secret_key'];
        // Note: The official Flutterwave SDK usually wants keys in environment variables or passed differently.
        // For simplicity and dependency injection, we might mostly use direct CURL requests or setup the SDK globally if needed.
        // Assuming SDK setup:
        // FLW_SECRET_KEY env var is what the SDK looks for usually, but we can try to use standard HTTP calls 
        // if the SDK is rigid about env vars to avoid modifying global state.
        // Checking SDK usage... The SDK initializes from Config::setUp or env.
        // We will assume environment variables are set or we manually call endpoints if easier.
        // Actually, the SDK is a bit heavy, let's just use standard CURL for cleaner service isolation if SDK is troublesome,
        // BUT the plan said install SDK. Let's try to use it.
        // \Flutterwave\Rave::setUp(...)
    }

    public function createPayment(array $data): array
    {
        // Flutterwave Standard (Link)
        $txRef = 'flw_' . uniqid() . '_' . time();

        $payload = [
            'tx_ref' => $txRef,
            'amount' => $data['amount'] / 100, // Flutterwave takes main currency unit usually? API docs say main unit.
            'currency' => $data['currency'],
            'payment_options' => 'card,mobilemoney,ussd',
            'redirect_url' => getenv('FRONTEND_URL') . '/payment/callback', // Frontend callback
            'customer' => [
                'email' => $data['email'],
                'name' => $data['name']
            ],
            'meta' => [
                'user_id' => $data['user_id'],
                'cohort_id' => $data['cohort_id'] ?? null,
            ],
            'customizations' => [
                'title' => "Zenith Wellness",
                'description' => $data['description']
            ]
        ];

        // Using direct API call for reliability as PHP SDKs can be finicky with config
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.flutterwave.com/v3/payments',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return ['status' => 'error', 'message' => $err];
        }

        $res = json_decode($response, true);

        if ($res['status'] === 'success') {
            return [
                'status' => 'success',
                'gateway_id' => $txRef,
                'authorization_url' => $res['data']['link'],
                'requires_redirect' => true
            ];
        } else {
            return [
                'status' => 'error',
                'message' => $res['message'] ?? 'Unknown error'
            ];
        }
    }

    public function verifyPayment(string $reference): array
    {
        // Verify by Transaction ID (which we get from webhook or callback)
        // Note: Flutterwave verification usually takes Transaction ID, not TX Ref.
        // Frontend redirect usually returns ?transaction_id=...

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.flutterwave.com/v3/transactions/{$reference}/verify",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $this->secretKey,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return ['status' => 'error', 'message' => $err];
        }

        $res = json_decode($response, true);

        if ($res['status'] === 'success' && $res['data']['status'] === 'successful') {
            return [
                'status' => 'success',
                'amount' => $res['data']['amount'] * 100, // Convert back to cents for consistency if needed
                'currency' => $res['data']['currency'],
                'metadata' => $res['data']['meta'] ?? []
            ];
        }

        return ['status' => 'failed', 'message' => $res['message'] ?? 'Verification failed'];
    }

    public function refundPayment(string $paymentId, int $amount): array
    {
        // PaymentId here must be the numeric transaction ID from Flutterwave
        $payload = [
            'amount' => $amount / 100, // to main unit
        ];

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.flutterwave.com/v3/transactions/{$paymentId}/refund",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $this->secretKey,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err)
            return ['status' => 'error', 'message' => $err];

        $res = json_decode($response, true);
        if ($res['status'] === 'success') {
            return ['status' => 'success', 'refund_id' => $res['data']['id']];
        }

        return ['status' => 'error', 'message' => $res['message'] ?? 'Refund failed'];
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
}
