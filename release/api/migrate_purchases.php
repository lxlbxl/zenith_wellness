<?php
include_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Migrating Database...\n";

try {
    // Check if column exists (SQLite specific check, for PG we'd catch exception or query schema)
    // Simple way is to try modify/add and catch error if it exists, or check schema
    // Since we support both, let's try to add it.

    $sql = "ALTER TABLE user_stats ADD COLUMN purchased_programs TEXT DEFAULT '[]'";

    $db->exec($sql);
    echo "Added purchased_programs column to user_stats.\n";

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column') !== false || strpos($e->getMessage(), 'exist') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Migration Error (might be okay if column exists): " . $e->getMessage() . "\n";
    }
}
?>