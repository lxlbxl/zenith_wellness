<?php
/**
 * Migration: Create expiry_reminders_sent table
 * 
 * Tracks which expiry reminder emails have been sent to avoid duplicates.
 */

require_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS expiry_reminders_sent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            enrollment_id TEXT NOT NULL,
            days_before INTEGER NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(enrollment_id, days_before)
        )
    ");

    // Create index for faster lookups
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_expiry_reminders_enrollment 
        ON expiry_reminders_sent(enrollment_id)
    ");

    echo "[OK] Created expiry_reminders_sent table\n";
} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
