<?php
/**
 * POST /api/auth/reset-password
 * Resets a user's password using a valid reset token.
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
$token = $data['token'] ?? '';
$password = $data['password'] ?? '';
$passwordConfirm = $data['password_confirm'] ?? '';

// Validation
if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Reset token is required']);
    exit();
}

if (empty($password) || strlen($password) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must be at least 8 characters']);
    exit();
}

if ($password !== $passwordConfirm) {
    http_response_code(422);
    echo json_encode(['error' => 'Passwords do not match']);
    exit();
}

// Password strength checks
$strength = 0;
if (preg_match('/[a-z]/', $password))
    $strength++;
if (preg_match('/[A-Z]/', $password))
    $strength++;
if (preg_match('/[0-9]/', $password))
    $strength++;
if (preg_match('/[^a-zA-Z0-9]/', $password))
    $strength++;

if ($strength < 3) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must include at least 3 of: lowercase, uppercase, number, special character']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $auth = new AuthService($db);

    $resetData = $auth->verifyPasswordReset($token);

    if (!$resetData) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired reset token']);
        exit();
    }

    $auth->resetPassword($resetData['user_id'], $password);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully. You can now login.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[reset-password] ' . $e->getMessage());
}