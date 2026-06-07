<?php
require_once __DIR__ . '/config.php';

try {
    // Create notifications table
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        type TEXT NOT NULL, -- success, info, warning, error, achievement
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        link TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "Notifications table created successfully.\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
