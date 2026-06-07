<?php
require_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Migrating Cohorts Table...\n";

try {
    // Add price column if not exists
    try {
        $db->exec("ALTER TABLE cohorts ADD COLUMN price INTEGER DEFAULT 0"); // Price in cents
        echo "Added 'price' column.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column') !== false) {
            echo "'price' column already exists.\n";
        } else {
            // SQLite might not support adding multiple columns easily or specific checks, ignore if exists
            echo "Note: " . $e->getMessage() . "\n";
        }
    }

    // Add duration_days column if not exists
    try {
        $db->exec("ALTER TABLE cohorts ADD COLUMN duration_days INTEGER DEFAULT 21");
        echo "Added 'duration_days' column.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column') !== false) {
            echo "'duration_days' column already exists.\n";
        } else {
            echo "Note: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration complete.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>