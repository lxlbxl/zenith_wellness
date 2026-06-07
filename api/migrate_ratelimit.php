<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS request_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        route VARCHAR(255),
        created_at DATETIMEDEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip_address, created_at)
    )");

    // SQLite syntax adjustment if needed, but above is MySQL-ish. 
    // Since we use SQLite in this environment usually (based on previous logs showing .sqlite file or PDO), let's check.
    // Actually, user context says "The user's OS version is windows", and PHP code uses PDO. 
    // Previous logs showed `c:\Users\Alex...` so likely local dev.
    // If SQLite: AUTO_INCREMENT -> AUTOINCREMENT (and INTEGER PRIMARY KEY), DATETIME logic differs.
    // Let's make it generic or robust.

    // Check driver
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS request_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            route TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_created ON request_logs(ip_address, created_at)");
    } else {
        // Assume Mysql
        $db->exec("CREATE TABLE IF NOT EXISTS request_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            route VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_created (ip_address, created_at)
        )");
    }

    echo "Rate limiting table created.\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
