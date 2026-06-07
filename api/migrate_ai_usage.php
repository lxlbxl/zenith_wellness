<?php
/**
 * Migration: Create AI token usage tracking table
 */

require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Create ai_usage_logs table for token tracking
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_usage_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id VARCHAR(36) NOT NULL,
            agent_name VARCHAR(50) NOT NULL,
            provider VARCHAR(20) NOT NULL,
            model VARCHAR(50) NOT NULL,
            input_tokens INTEGER DEFAULT 0,
            output_tokens INTEGER DEFAULT 0,
            total_tokens INTEGER DEFAULT 0,
            response_time_ms INTEGER,
            status VARCHAR(20) DEFAULT 'success',
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    echo "[OK] Created ai_usage_logs table\n";

    // Create indexes for efficient queries
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ai_usage_user 
        ON ai_usage_logs(user_id)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ai_usage_date 
        ON ai_usage_logs(created_at)
    ");
    echo "[OK] Created indexes on ai_usage_logs\n";

    // Create daily AI usage limits table
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_usage_limits (
            user_id VARCHAR(36) PRIMARY KEY,
            daily_limit INTEGER DEFAULT 50,
            daily_count INTEGER DEFAULT 0,
            last_reset_date DATE DEFAULT CURRENT_DATE,
            is_premium INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    echo "[OK] Created ai_usage_limits table\n";

} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
