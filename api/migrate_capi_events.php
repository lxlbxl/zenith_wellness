<?php
require_once __DIR__ . '/config.php';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS capi_event_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name VARCHAR(100) NOT NULL,
        properties TEXT,
        source VARCHAR(50) DEFAULT 'client',
        ip_address VARCHAR(45),
        user_agent TEXT,
        user_id VARCHAR(100),
        capi_response TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_name (event_name),
        INDEX idx_created_at (created_at),
        INDEX idx_user_id (user_id)
    )");

    echo "CAPI event log table created successfully.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}