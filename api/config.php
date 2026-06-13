<?php
// ===========================================
// Zenith Wellness API Configuration
// ===========================================

// Load environment variables
require_once __DIR__ . '/config/EnvLoader.php';
EnvLoader::load(__DIR__ . '/.env');

// ===================
// Environment
// ===================
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:5173');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: true, FILTER_VALIDATE_BOOLEAN));
define('PRODUCTION', filter_var(getenv('PRODUCTION') ?: false, FILTER_VALIDATE_BOOLEAN));

// ===================
// Database
// ===================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'zenith_wellness');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DATABASE_URL', getenv('DATABASE_URL') ?: null);

// ===================
// Security
// ===================
$jwt_secret = getenv('JWT_SECRET');
$enc_key = getenv('ENCRYPTION_KEY');

if (!$jwt_secret || $jwt_secret === 'YOUR_SECRET_KEY_CHANGE_IN_PROD') {
    if (APP_ENV === 'production') {
        http_response_code(500);
        echo json_encode(['error' => 'Server configuration error: JWT_SECRET not set']);
        exit();
    }
    $jwt_secret = 'dev-jwt-secret-change-in-production';
}

if (!$enc_key || $enc_key === 'dev-enc-key-change-in-production') {
    if (APP_ENV === 'production') {
        http_response_code(500);
        echo json_encode(['error' => 'Server configuration error: ENCRYPTION_KEY not set']);
        exit();
    }
    $enc_key = 'dev-enc-key-change-in-production';
}

define('JWT_SECRET', $jwt_secret);
define('ENCRYPTION_KEY', $enc_key);

// ===================
// Sales Toggle
// ===================
define('SALES_ENABLED', filter_var(getenv('SALES_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));

// ===================
// Payment Gateways
// ===================
define('ACTIVE_PAYMENT_GATEWAY', getenv('ACTIVE_PAYMENT_GATEWAY') ?: 'stripe');
define('PAYMENT_CURRENCY', getenv('PAYMENT_CURRENCY') ?: 'USD');
define('PAYMENT_QUOTE_SECRET', getenv('PAYMENT_QUOTE_SECRET') ?: 'dev-quote-secret-change-in-production');

// Stripe
define('STRIPE_PUBLIC_KEY', getenv('STRIPE_PUBLIC_KEY') ?: '');
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');

// Paystack
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: '');
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: '');
define('PAYSTACK_WEBHOOK_SECRET', getenv('PAYSTACK_WEBHOOK_SECRET') ?: '');

// Flutterwave
define('FLUTTERWAVE_PUBLIC_KEY', getenv('FLUTTERWAVE_PUBLIC_KEY') ?: '');
define('FLUTTERWAVE_SECRET_KEY', getenv('FLUTTERWAVE_SECRET_KEY') ?: '');
define('FLUTTERWAVE_WEBHOOK_SECRET', getenv('FLUTTERWAVE_WEBHOOK_SECRET') ?: '');

// ===================
// Email (SMTP)
// ===================
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Zenith Wellness');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');

// ===================
// AI Services
// ===================
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');

// ===================
// Error Monitoring
// ===================
define('SENTRY_DSN', getenv('SENTRY_DSN') ?: '');

// ===================
// CORS
// ===================
define('CORS_ALLOWED_ORIGINS', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:5173,http://localhost:3000');

// ===================
// Rate Limiting
// ===================
define('RATE_LIMIT_REQUESTS', (int) (getenv('RATE_LIMIT_REQUESTS') ?: 60));
define('RATE_LIMIT_WINDOW', (int) (getenv('RATE_LIMIT_WINDOW') ?: 60));
define('AUTH_RATE_LIMIT_REQUESTS', (int) (getenv('AUTH_RATE_LIMIT_REQUESTS') ?: 10));
define('AUTH_RATE_LIMIT_WINDOW', (int) (getenv('AUTH_RATE_LIMIT_WINDOW') ?: 60));
