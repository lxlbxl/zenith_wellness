<?php
/**
 * POST /api/auth/forgot-password
 * Sends a password reset email with a secure token.
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
require_once __DIR__ . '/../services/EmailService.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'A valid email address is required']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $auth = new AuthService($db);

    // Rate limit: 3 requests per hour per email
    $rate = $auth->checkRateLimit('pwr:' . $email, 3, 3600);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests', 'retry_after' => $rate['retry_after']]);
        exit();
    }

    $encodedToken = $auth->createPasswordReset($email);

    // Always return success (prevent email enumeration)
    if ($encodedToken) {
        $emailService = new EmailService();
        $emailService->sendPasswordReset($email, $encodedToken);
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link has been sent']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[forgot-password] ' . $e->getMessage());
}