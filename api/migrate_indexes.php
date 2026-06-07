<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Enhancing Database Performance (Adding Indexes)...\n";

try {
    // Helper to safely add index only if not exists (SQLite/MySQL agnostic ish)
    // For SQLite, "CREATE INDEX IF NOT EXISTS" works.
    // For MySQL, we catch duplicate key error.

    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)",
        "CREATE INDEX IF NOT EXISTS idx_logs_user_date ON daily_wellness_logs(user_id, log_date)",
        "CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)",
        "CREATE INDEX IF NOT EXISTS idx_journal_user ON journal_entries(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_goals_user ON personal_goals(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_meals_user ON meal_logs(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_cohorts_start ON cohorts(start_date)"
    ];

    foreach ($indexes as $sql) {
        try {
            $db->exec($sql);
            echo "Executed: $sql\n";
        } catch (PDOException $e) {
            echo "Skipped (or error): " . $e->getMessage() . "\n";
        }
    }

    echo "Indexing complete.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
