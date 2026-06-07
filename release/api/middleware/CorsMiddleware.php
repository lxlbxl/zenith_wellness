<?php

require_once __DIR__ . '/../config.php';

class CorsMiddleware
{
    public function handle()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Get allowed origins from config (comma-separated)
        $allowedOriginsStr = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : 'http://localhost:5173,http://localhost:3000';
        $allowedOrigins = array_map('trim', explode(',', $allowedOriginsStr));

        // Check if production mode
        $isProduction = defined('PRODUCTION') && PRODUCTION === true;

        if ($isProduction) {
            // Strict CORS in production - only allow configured origins
            if (in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
            } else {
                // Don't set any CORS header for unknown origins
                // This effectively blocks cross-origin requests
            }
        } else {
            // Development mode - more permissive but still validate
            if ($origin && in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
            } else if ($origin) {
                // In dev, allow the origin but log it
                header("Access-Control-Allow-Origin: $origin");
                error_log("CORS: Allowing unregistered origin in dev mode: $origin");
            } else {
                // No origin header (same-origin or tools like Postman)
                header("Access-Control-Allow-Origin: *");
            }
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit();
        }
    }
}
