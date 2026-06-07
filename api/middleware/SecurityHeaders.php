<?php

require_once __DIR__ . '/../config.php';

class SecurityHeadersMiddleware
{
    public function handle()
    {
        $isProduction = defined('PRODUCTION') && PRODUCTION === true;

        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Block MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Referrer Policy - don't leak full URL to third parties
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions Policy (formerly Feature-Policy)
        header("Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()");

        // Production-only headers
        if ($isProduction) {
            // HTTP Strict Transport Security (HSTS)
            // Forces HTTPS for 1 year, including subdomains
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

            // Content Security Policy
            // Adjust based on your needs (e.g., if using external scripts/fonts)
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://checkout.paystack.com https://api.flutterwave.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' https://api.stripe.com https://api.paystack.co https://api.flutterwave.com",
                "frame-src https://js.stripe.com https://checkout.paystack.com https://flutterwave.com",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'"
            ]);
            header("Content-Security-Policy: $csp");
        } else {
            // Development - more relaxed CSP for debugging
            header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; img-src 'self' data: https: blob:;");
        }

        // Prevent caching of sensitive data
        if ($this->isSensitiveEndpoint()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    private function isSensitiveEndpoint(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $sensitivePatterns = [
            '/auth/',
            '/admin/',
            '/payment/',
            '/privacy/',
            '/password/'
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
