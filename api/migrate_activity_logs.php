<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Setting up Activity Logging...\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS user_activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(36),
        action_type VARCHAR(50) NOT NULL, -- login, page_view, click, error
        description VARCHAR(255),
        metadata TEXT, -- JSON
        ip_address VARCHAR(45),
        user_agent VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )";

    // MySQL compatibility (if not sqlite)
    if (getenv('DATABASE_URL') || (defined('DB_TYPE') && DB_TYPE === 'mysql')) {
        $sql = "CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(36),
            action_type VARCHAR(50) NOT NULL,
            description VARCHAR(255),
            metadata TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_action (user_id, action_type),
            INDEX idx_created (created_at)
        )";
    }

    $db->exec($sql);
    echo "user_activity_logs table created.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
