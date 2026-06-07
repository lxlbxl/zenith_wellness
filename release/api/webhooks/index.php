<?php
// Simple router for webhooks
require_once __DIR__ . '/../config/Database.php';

// Allow from any origin? Usually webhooks verify signature not origin.
// But we might want basic CORS or just allow all since webhooks come from external servers.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Forwarded-For, x-paystack-signature, verif-hash");

$database = new Database();
$db = $database->getConnection();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);

// URL Expected: /api/webhooks/{gateway}
// Index: 0="", 1="api", 2="webhooks", 3="stripe"

$gateway = $uri[3] ?? null;

if (!$gateway) {
    http_response_code(404);
    echo json_encode(['message' => 'Webhook gateway not specified']);
    exit();
}

try {
    switch ($gateway) {
        case 'stripe':
            require_once 'StripeWebhook.php';
            $handler = new StripeWebhook($db);
            $handler->handle();
            break;
        case 'paystack':
            require_once 'PaystackWebhook.php';
            $handler = new PaystackWebhook($db);
            $handler->handle();
            break;
        case 'flutterwave':
            require_once 'FlutterwaveWebhook.php';
            $handler = new FlutterwaveWebhook($db);
            $handler->handle();
            break;
        default:
            http_response_code(404);
            echo json_encode(['message' => 'Unknown gateway webhook']);
            break;
    }
} catch (Exception $e) {
    error_log("Webhook Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'Internal Server Error']);
}
