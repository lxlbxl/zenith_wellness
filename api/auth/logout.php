<?php
/**
 * POST /api/auth/logout
 * Server-side logout: blacklists access token + revokes all refresh tokens.
 * Phase 1.2: Authentication Overhaul
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/AuthService.php';

// Extract Bearer token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$token = '';

if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization token required']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $auth = new AuthService($db);

    $result = $auth->logout($token);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[logout] ' . $e->getMessage());
}