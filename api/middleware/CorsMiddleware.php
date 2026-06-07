<?php

require_once __DIR__ . '/../config.php';

class CorsMiddleware
{
    public function handle()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $allowedOriginsStr = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : 'http://localhost:5173,http://localhost:3000';
        $allowedOrigins = array_map('trim', explode(',', $allowedOriginsStr));

        if ($origin && in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400");

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit();
        }
    }
}
