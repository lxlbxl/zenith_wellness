<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/StripeService.php';
require_once __DIR__ . '/PaystackService.php';
require_once __DIR__ . '/FlutterwaveService.php';

class PaymentGatewayFactory
{
    public static function create(string $gatewayName, $db): PaymentGatewayInterface
    {
        // Fetch keys from system_settings using the DB connection provided (usually PDO)
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'payment'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Determine active gateway if not explicitly passed (optional behavior)
        if ($gatewayName === 'active') {
            $gatewayName = $settings['active_payment_gateway'] ?? 'stripe';
        }

        switch ($gatewayName) {
            case 'stripe':
                $service = new StripeService();
                $service->initialize([
                    'public_key' => $settings['stripe_public_key'] ?? '',
                    'secret_key' => $settings['stripe_secret_key'] ?? ''
                ]);
                return $service;

            case 'paystack':
                $service = new PaystackService();
                $service->initialize([
                    'public_key' => $settings['paystack_public_key'] ?? '',
                    'secret_key' => $settings['paystack_secret_key'] ?? ''
                ]);
                return $service;

            case 'flutterwave':
                $service = new FlutterwaveService();
                $service->initialize([
                    'public_key' => $settings['flutterwave_public_key'] ?? '',
                    'secret_key' => $settings['flutterwave_secret_key'] ?? ''
                ]);
                return $service;

            default:
                throw new Exception("Unknown payment gateway: " . $gatewayName);
        }
    }

    public static function getActiveGatewayName($db): string
    {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'active_payment_gateway'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val ? $val : 'stripe';
    }
}
