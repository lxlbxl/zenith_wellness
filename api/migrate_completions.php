<?php
/**
 * Migration: Create cohort_completions table
 * 
 * Tracks cohort completions with stats and certificate data.
 */

require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Create cohort_completions table
    $db->exec("
        CREATE TABLE IF NOT EXISTS cohort_completions (
            id VARCHAR(36) PRIMARY KEY,
            enrollment_id VARCHAR(36) NOT NULL,
            user_id VARCHAR(36) NOT NULL,
            cohort_id VARCHAR(50) NOT NULL,
            completion_stats TEXT, -- JSON with accumulated stats
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            certificate_downloaded INTEGER DEFAULT 0,
            FOREIGN KEY (enrollment_id) REFERENCES cohort_enrollments(id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (cohort_id) REFERENCES cohorts(id),
            UNIQUE(enrollment_id)
        )
    ");
    echo "[OK] Created cohort_completions table\n";

    // Create index for faster lookups
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_completions_user 
        ON cohort_completions(user_id)
    ");
    echo "[OK] Created user index on cohort_completions\n";

    // Add memory_enabled column to ai_prompts table
    $db->exec("
        ALTER TABLE ai_prompts ADD COLUMN memory_enabled INTEGER DEFAULT 0
    ");
    echo "[OK] Added memory_enabled column to ai_prompts\n";

    // Update existing memory-enabled agents
    $db->exec("
        UPDATE ai_prompts SET memory_enabled = 1 
        WHERE agent_name IN ('coach_sara', 'companion')
    ");
    echo "[OK] Set memory_enabled for coach_sara and companion\n";

} catch (PDOException $e) {
    // Check if it's a duplicate column error (safe to ignore)
    if (
        strpos($e->getMessage(), 'duplicate column') !== false ||
        strpos($e->getMessage(), 'already exists') !== false
    ) {
        echo "[SKIP] Some columns already exist\n";
    } else {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
}
