<?php
include_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Starting Payment System Migration...\n";

try {
    // 1. Update Payments Table
    echo "Updating payments table...\n";
    $columns = [
        "gateway_payment_id" => "VARCHAR(100)",
        "refund_id" => "VARCHAR(100)",
        "refunded_at" => "TIMESTAMP",
        "refund_reason" => "VARCHAR(255)",
        "cohort_id" => "VARCHAR(50)",
        "access_expires_at" => "TIMESTAMP"
    ];

    foreach ($columns as $col => $type) {
        try {
            // SQLite syntax to add column (will fail if exists, so wrap in try-catch)
            $db->exec("ALTER TABLE payments ADD COLUMN $col $type");
            echo " - Added column: $col\n";
        } catch (PDOException $e) {
            // Column likely exists
            echo " - Column $col likely exists or could not be added: " . $e->getMessage() . "\n";
        }
    }

    // 2. Create Cohort Enrollments Table
    echo "Creating cohort_enrollments table...\n";
    $sql_enrollments = "CREATE TABLE IF NOT EXISTS cohort_enrollments (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        cohort_id VARCHAR(50) NOT NULL,
        payment_id VARCHAR(36),
        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        access_starts_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        access_expires_at TIMESTAMP NOT NULL,
        status VARCHAR(20) DEFAULT 'active', -- active, expired, revoked, refunded
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (cohort_id) REFERENCES cohorts(id),
        FOREIGN KEY (payment_id) REFERENCES payments(id),
        UNIQUE(user_id, cohort_id)
    )";
    $db->exec($sql_enrollments);
    echo " - cohort_enrollments table created/verified.\n";

    // 3. Add New System Settings
    echo "Seeding system_settings...\n";
    $new_settings = [
        ['active_payment_gateway', 'stripe', 'payment', 'Active gateway: stripe, paystack, or flutterwave', 0],
        ['flutterwave_public_key', 'FLWPUBK_TEST-xxx', 'payment', 'Flutterwave Public Key', 0],
        ['flutterwave_secret_key', 'FLWSECK_TEST-xxx', 'payment', 'Flutterwave Secret Key', 1],
        ['stripe_webhook_secret', 'whsec_xxx', 'payment', 'Stripe Webhook Secret', 1],
        ['paystack_webhook_secret', '', 'payment', 'Paystack Webhook Secret', 1],
        ['flutterwave_webhook_secret', '', 'payment', 'Flutterwave Webhook Secret', 1]
    ];

    $stmt_check = $db->prepare("SELECT count(*) FROM system_settings WHERE setting_key = ?");
    $stmt_insert = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted) VALUES (?,?,?,?,?)");

    foreach ($new_settings as $setting) {
        $stmt_check->execute([$setting[0]]);
        if ($stmt_check->fetchColumn() == 0) {
            $stmt_insert->execute($setting);
            echo " - Added setting: {$setting[0]}\n";
        } else {
            echo " - Setting {$setting[0]} already exists.\n";
        }
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
