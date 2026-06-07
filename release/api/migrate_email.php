<?php
include_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Starting Email System Migration...\n";

try {
    // Create Email Logs Table
    echo "Creating email_logs table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS email_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL, -- sent, failed
        error_message TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    // Check if using MySQL or SQLite for AUTOINCREMENT syntax
    // Assuming SQLite based on previous files, but let's be safe or just use standard SQL
    // If MySQL: id INT AUTO_INCREMENT PRIMARY KEY
    // If SQLite: id INTEGER PRIMARY KEY AUTOINCREMENT

    // Let's use a generic approach or check driver
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $sql = "CREATE TABLE IF NOT EXISTS email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message TEXT,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    }

    $db->exec($sql);
    echo " - email_logs table created/verified.\n";

    // Seed SMTP Settings if not exists
    $settings = [
        ['smtp_host', '', 'smtp', 'SMTP Host (e.g., smtp.gmail.com)', 0],
        ['smtp_port', '587', 'smtp', 'SMTP Port (587 or 465)', 0],
        ['smtp_user', '', 'smtp', 'SMTP Username', 0],
        ['smtp_pass', '', 'smtp', 'SMTP Password', 1],
        ['smtp_secure', 'tls', 'smtp', 'Encryption (tls or ssl)', 0],
        ['smtp_from_email', '', 'smtp', 'From Email Address', 0]
    ];

    $stmt_check = $db->prepare("SELECT count(*) FROM system_settings WHERE setting_key = ?");
    $stmt_insert = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted) VALUES (?,?,?,?,?)");

    foreach ($settings as $setting) {
        $stmt_check->execute([$setting[0]]);
        if ($stmt_check->fetchColumn() == 0) {
            $stmt_insert->execute($setting);
            echo " - Added setting: {$setting[0]}\n";
        }
    }

    echo "Email migration completed!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
