<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Compliance Requests Table (Right to be Forgotten / Data Export)
    $db->exec("CREATE TABLE IF NOT EXISTS compliance_requests (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        type TEXT NOT NULL, -- 'export', 'delete'
        status TEXT DEFAULT 'pending', -- 'pending', 'processed', 'rejected'
        reason TEXT, -- Optional reason from user
        admin_notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // User Consents Table (Cookies, Marketing)
    $db->exec("CREATE TABLE IF NOT EXISTS user_consents (
        user_id TEXT NOT NULL,
        consent_type TEXT NOT NULL, -- 'cookie_analytics', 'cookie_marketing', 'email_marketing'
        is_granted INTEGER DEFAULT 0,
        ip_address TEXT,
        user_agent TEXT,
        granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, consent_type),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "Compliance tables created successfully.\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
