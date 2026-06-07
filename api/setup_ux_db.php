<?php
// api/setup_ux_db.php
// One-off script to update database schema for UX features

include_once __DIR__ . '/config/Database.php';

$database = new Database();
$db = $database->getConnection();

try {

    // 1. Add columns to cohorts table
    // Check if columns exist first to avoid errors
    $result = $db->query("PRAGMA table_info(cohorts)");
    $hasMax = false;
    $hasCurrent = false;

    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        if ($row['name'] === 'max_participants')
            $hasMax = true;
        if ($row['name'] === 'current_participants')
            $hasCurrent = true;
    }

    if (!$hasMax) {
        $db->exec("ALTER TABLE cohorts ADD COLUMN max_participants INTEGER DEFAULT 50");
        echo "Added max_participants to cohorts.\n";
    }

    if (!$hasCurrent) {
        $db->exec("ALTER TABLE cohorts ADD COLUMN current_participants INTEGER DEFAULT 0");
        echo "Added current_participants to cohorts.\n";
    }

    // 2. Initialize some dummy data for spots if 0
    $db->exec("UPDATE cohorts SET current_participants = ABS(RANDOM() % 40) + 5 WHERE current_participants = 0");
    echo "Seeded random participant counts.\n";

    // 3. Create testimonials table
    $db->exec("CREATE TABLE IF NOT EXISTS testimonials (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        cohort_id TEXT,
        quote TEXT NOT NULL,
        name TEXT,
        rating INTEGER DEFAULT 5,
        result_metric TEXT,
        is_featured INTEGER DEFAULT 0,
        is_approved INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Ensured testimonials table exists.\n";

    echo "Database setup complete.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>