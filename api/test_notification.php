<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/NotificationService.php';

require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();
$service = new NotificationService($db);

// Simulate a user ID (you might need to replace this with a real ID from your DB)
// For now, let's just pick one if exists or use a dummy
$stmt = $db->query("SELECT id FROM users LIMIT 1");
$userId = $stmt->fetchColumn();

if ($userId) {
    $id = $service->create(
        $userId,
        'info',
        'System Test',
        'This is a test notification generated at ' . date('H:i:s'),
        '/dashboard'
    );
    echo "Notification created with ID: $id for user $userId\n";
} else {
    echo "No users found to test with.\n";
}
