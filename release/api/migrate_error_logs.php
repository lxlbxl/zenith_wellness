<?php
/**
 * Migration: Create error_logs table
 */

require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Create error_logs table
    $db->exec("
        CREATE TABLE IF NOT EXISTS error_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            severity VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context TEXT,
            request_uri VARCHAR(255),
            method VARCHAR(10),
            user_id VARCHAR(36),
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "[OK] Created error_logs table\n";

    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_error_severity ON error_logs(severity)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_error_date ON error_logs(created_at)");
    echo "[OK] Created indexes on error_logs\n";

    // Create logs directory
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
        echo "[OK] Created logs directory\n";
    }

} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
