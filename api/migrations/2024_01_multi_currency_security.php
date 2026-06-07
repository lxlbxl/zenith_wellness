<?php
/**
 * Migration: Multi-currency support, security fixes, and performance indexes
 * Run: php api/migrations/2024_01_multi_currency_security.php
 */
require_once __DIR__ . '/../config.php';
include_once __DIR__ . '/../config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Running migration: Multi-currency + Security + Performance\n";

// 1. Multi-Currency: cohort_prices table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cohort_prices (
            id VARCHAR(36) PRIMARY KEY,
            cohort_id VARCHAR(50) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            amount INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
            UNIQUE(cohort_id, currency)
        )
    ");
    echo "  [OK] cohort_prices table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] cohort_prices: " . $e->getMessage() . "\n";
}

// 2. Multi-Currency: exchange_rates table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS exchange_rates (
            id VARCHAR(36) PRIMARY KEY,
            base_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            target_currency VARCHAR(3) NOT NULL,
            rate DECIMAL(12,6) NOT NULL,
            source VARCHAR(50) DEFAULT 'manual',
            valid_from TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            valid_until TIMESTAMP,
            is_active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(base_currency, target_currency, valid_from)
        )
    ");
    echo "  [OK] exchange_rates table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] exchange_rates: " . $e->getMessage() . "\n";
}

// 3. Add max_participants to cohorts
try {
    $db->exec("ALTER TABLE cohorts ADD COLUMN max_participants INTEGER DEFAULT NULL");
    echo "  [OK] max_participants column added to cohorts\n";
} catch (PDOException $e) {
    echo "  [SKIP] max_participants: column exists\n";
}

// 4. Add display_currency to user_stats
try {
    $db->exec("ALTER TABLE user_stats ADD COLUMN display_currency VARCHAR(3) DEFAULT 'USD'");
    echo "  [OK] display_currency column added to user_stats\n";
} catch (PDOException $e) {
    echo "  [SKIP] display_currency: column exists\n";
}

// 5. Add email_logs table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS email_logs (
            id VARCHAR(36) PRIMARY KEY,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255),
            status VARCHAR(20) DEFAULT 'sent',
            error_message TEXT,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "  [OK] email_logs table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] email_logs: " . $e->getMessage() . "\n";
}

// 6. Add cohort_completions table if missing
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cohort_completions (
            id VARCHAR(36) PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            cohort_id VARCHAR(50) NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completion_stats TEXT,
            certificate_downloaded INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (cohort_id) REFERENCES cohorts(id)
        )
    ");
    echo "  [OK] cohort_completions table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] cohort_completions: " . $e->getMessage() . "\n";
}

// 7. Add user_activity_logs table if missing
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_activity_logs (
            id VARCHAR(36) PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            action_type VARCHAR(50),
            description TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "  [OK] user_activity_logs table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] user_activity_logs: " . $e->getMessage() . "\n";
}

// 8. Add ai_usage_logs table if missing
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_usage_logs (
            id VARCHAR(36) PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            agent_name VARCHAR(50),
            tokens_used INTEGER,
            cost_estimate DECIMAL(10,4),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "  [OK] ai_usage_logs table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] ai_usage_logs: " . $e->getMessage() . "\n";
}

// 9. Add request_logs table if missing
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS request_logs (
            id VARCHAR(36) PRIMARY KEY,
            ip_address VARCHAR(45),
            endpoint VARCHAR(255),
            method VARCHAR(10),
            status_code INTEGER,
            response_time_ms INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "  [OK] request_logs table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] request_logs: " . $e->getMessage() . "\n";
}

// 10. Add token_blacklist table for JWT revocation
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS token_blacklist (
            id VARCHAR(36) PRIMARY KEY,
            jti VARCHAR(100) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(jti)
        )
    ");
    echo "  [OK] token_blacklist table created\n";
} catch (PDOException $e) {
    echo "  [SKIP] token_blacklist: " . $e->getMessage() . "\n";
}

// 11. Performance indexes
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_payments_user_created ON payments(user_id, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)",
    "CREATE INDEX IF NOT EXISTS idx_payments_gateway ON payments(gateway)",
    "CREATE INDEX IF NOT EXISTS idx_payments_currency ON payments(currency)",
    "CREATE INDEX IF NOT EXISTS idx_cohort_enrollments_user ON cohort_enrollments(user_id, status)",
    "CREATE INDEX IF NOT EXISTS idx_cohort_enrollments_cohort ON cohort_enrollments(cohort_id, status)",
    "CREATE INDEX IF NOT EXISTS idx_cohort_enrollments_expires ON cohort_enrollments(access_expires_at)",
    "CREATE INDEX IF NOT EXISTS idx_daily_wellness_logs_user_date ON daily_wellness_logs(user_id, log_date)",
    "CREATE INDEX IF NOT EXISTS idx_habit_logs_user_date ON habit_logs(user_id, log_date)",
    "CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_request_logs_ip_endpoint ON request_logs(ip_address, endpoint, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_ai_usage_logs_user_date ON ai_usage_logs(user_id, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_cohort_prices_currency ON cohort_prices(currency, is_active)",
    "CREATE INDEX IF NOT EXISTS idx_exchange_rates_active ON exchange_rates(target_currency, is_active, valid_from)"
];

foreach ($indexes as $indexSql) {
    try {
        $db->exec($indexSql);
    } catch (PDOException $e) {
        // Index already exists or not supported by this DB engine
    }
}
echo "  [OK] Performance indexes created\n";

// 12. Seed default Flutterwave exchange rates
try {
    $check = $db->query("SELECT COUNT(*) FROM exchange_rates")->fetchColumn();
    if ($check == 0) {
        $rates = [
            ['NGN', 1550], ['GHS', 15.5], ['KES', 129], ['UGX', 3750],
            ['TZS', 2650], ['RWF', 1350], ['ZAR', 18.5], ['EGP', 50],
            ['MAD', 10], ['GBP', 0.79], ['EUR', 0.92], ['AED', 3.67],
            ['INR', 83], ['CNY', 7.2], ['AUD', 1.53], ['CAD', 1.36],
            ['JPY', 155], ['KRW', 1350], ['BHD', 0.376], ['KWD', 0.307],
            ['OMR', 0.385], ['QAR', 3.64], ['SAR', 3.75], ['CFA', 605],
            ['XOF', 605], ['XAF', 605], ['BIF', 2850], ['ETB', 57],
            ['GMD', 71], ['GNF', 8600], ['LRD', 190], ['LSL', 18.5],
            ['MGA', 4500], ['MWK', 1730], ['MUR', 46], ['MZN', 64],
            ['NAD', 18.5], ['SCR', 13.5], ['SLL', 22000], ['SOS', 570],
            ['SZL', 18.5], ['TND', 3.1], ['ZMW', 26]
        ];

        $stmt = $db->prepare("
            INSERT INTO exchange_rates (id, base_currency, target_currency, rate, source, valid_from, is_active)
            VALUES (?, 'USD', ?, ?, 'system', CURRENT_TIMESTAMP, 1)
        ");

        foreach ($rates as [$currency, $rate]) {
            $id = 'fx_' . bin2hex(random_bytes(8));
            $stmt->execute([$id, $currency, $rate]);
        }
        echo "  [OK] Default exchange rates seeded (" . count($rates) . " currencies)\n";
    } else {
        echo "  [SKIP] Exchange rates already seeded\n";
    }
} catch (PDOException $e) {
    echo "  [ERROR] Seeding rates: " . $e->getMessage() . "\n";
}

// 13. Add flutterwave_webhook_secret to system_settings if missing
try {
    $check = $db->query("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'flutterwave_webhook_secret'")->fetchColumn();
    if ($check == 0) {
        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['flutterwave_webhook_secret', '', 'payment', 'Flutterwave Webhook Secret (for HMAC verification)', 1]);
        echo "  [OK] flutterwave_webhook_secret setting added\n";
    }
} catch (PDOException $e) {
    echo "  [SKIP] flutterwave_webhook_secret: " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
