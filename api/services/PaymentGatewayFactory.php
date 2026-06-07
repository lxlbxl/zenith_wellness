<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/StripeService.php';
require_once __DIR__ . '/PaystackService.php';
require_once __DIR__ . '/FlutterwaveService.php';

class PaymentGatewayFactory
{
    // Flutterwave-supported currencies (comprehensive list)
    public const FLUTTERWAVE_CURRENCIES = [
        'USD', 'NGN', 'GHS', 'KES', 'UGX', 'TZS', 'RWF', 'ZAR', 'EGP',
        'MAD', 'BIF', 'CDF', 'DJF', 'ERN', 'ETB', 'GMD', 'GNF', 'LRD',
        'LSL', 'MGA', 'MWK', 'MUR', 'MZN', 'NAD', 'SCR', 'SLL', 'SOS',
        'SZL', 'TND', 'ZMW', 'ZWL', 'GBP', 'EUR', 'AED', 'BHD', 'INR',
        'CNY', 'AUD', 'CAD', 'JPY', 'KWD', 'OMR', 'QAR', 'SAR', 'CFA'
    ];

    // Paystack only supports these
    public const PAYSTACK_CURRENCIES = ['NGN', 'GHS', 'USD', 'ZAR', 'KES'];

    // Stripe supports 135+ currencies; listing common ones
    public const STRIPE_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CNY', 'INR', 'SGD',
        'HKD', 'NZD', 'SEK', 'NOK', 'DKK', 'CHF', 'MXN', 'BRL', 'ZAR',
        'KRW', 'TWD', 'THB', 'MYR', 'PHP', 'IDR', 'AED', 'SAR', 'QAR',
        'KWD', 'BHD', 'OMR', 'EGP', 'MAD', 'NGN', 'KES', 'GHS', 'UGX',
        'TZS', 'RWF', 'MUR', 'MZN', 'ZMW', 'CLP', 'COP', 'PEN', 'ARS',
        'UYU', 'CRC', 'GTQ', 'HNL', 'NIO', 'PAB', 'DOP', 'JMD', 'TTD',
        'BBD', 'BZD', 'BSD', 'BMD', 'KYD', 'XCD', 'ANG', 'AWG', 'BND',
        'FJD', 'PGK', 'WST', 'VND', 'LKR', 'PKR', 'BDT', 'MMK', 'KHR',
        'LAK', 'NPR', 'MNT', 'ISK', 'CZK', 'HUF', 'PLN', 'RON', 'BGN',
        'HRK', 'RUB', 'TRY', 'ILS', 'JOD', 'LBP', 'TWD'
    ];

    public static function getSupportedCurrencies(string $gateway): array
    {
        switch ($gateway) {
            case 'stripe':
                return self::STRIPE_CURRENCIES;
            case 'paystack':
                return self::PAYSTACK_CURRENCIES;
            case 'flutterwave':
                return self::FLUTTERWAVE_CURRENCIES;
            default:
                return ['USD'];
        }
    }

    public static function create(string $gatewayName, $db): PaymentGatewayInterface
    {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'payment'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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

    public static function getBestGatewayForCurrency(string $currency, $db): string
    {
        $currency = strtoupper($currency);
        $activeGateway = self::getActiveGatewayName($db);

        // If active gateway supports the currency, use it
        if (in_array($currency, self::getSupportedCurrencies($activeGateway))) {
            return $activeGateway;
        }

        // Fall back to first gateway that supports the currency
        $gateways = ['flutterwave', 'stripe', 'paystack'];
        foreach ($gateways as $gw) {
            if (in_array($currency, self::getSupportedCurrencies($gw))) {
                return $gw;
            }
        }

        throw new Exception("No payment gateway supports currency: $currency");
    }
}
