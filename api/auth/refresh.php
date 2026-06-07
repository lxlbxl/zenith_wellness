<?php
/**
 * POST /api/auth/refresh
 * Exchange a long-lived refresh token for a new access + refresh pair.
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

$data = json_decode(file_get_contents('php://input'), true);
$refreshToken = $data['refresh_token'] ?? '';

if (empty($refreshToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'refresh_token is required']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $auth = new AuthService($db);

    $tokens = $auth->refreshAccessToken($refreshToken);

    if (!$tokens) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired refresh token']);
        exit();
    }

    http_response_code(200);
    echo json_encode($tokens);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[refresh] ' . $e->getMessage());
}