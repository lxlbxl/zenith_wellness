<?php
/**
 * Zenith Wellness Database Migration Runner
 * 
 * Detects current schema state and runs necessary migrations.
 * Usage: php api/migrations/migrate.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/Database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "[ERROR] Database connection failed. Check your .env configuration.\n";
    exit(1);
}

echo "═══════════════════════════════════════════\n";
echo "  Zenith Wellness - Database Migration\n";
echo "═══════════════════════════════════════════\n\n";

$driver = getenv('DB_HOST') ? 'mysql' : (getenv('DATABASE_URL') ? 'pgsql' : 'sqlite');
echo "Detected driver: {$driver}\n\n";

// ────────────────────────────────────────────
// Check current state
// ────────────────────────────────────────────
$existingTables = [];
try {
    if ($driver === 'sqlite') {
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    } elseif ($driver === 'pgsql') {
        $tables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    } else {
        $tables = $db->query("SHOW TABLES");
    }

    foreach ($tables as $row) {
        $existingTables[] = array_values($row)[0];
    }

    echo "Tables found: " . count($existingTables) . "\n";
} catch (Exception $e) {
    echo "[WARN] Could not list tables: " . $e->getMessage() . "\n";
}

// ────────────────────────────────────────────
// Define expected tables
// ────────────────────────────────────────────
$expectedTables = [
    'users',
    'user_stats',
    'cycle_logs',
    'cohorts',
    'cohort_progress',
    'cohort_messages',
    'meal_logs',
    'chat_sessions',
    'password_resets',
    'routine_templates',
    'routine_template_items',
    'user_routines',
    'user_routine_items',
    'routine_completions',
    'habit_templates',
    'user_habits',
    'habit_logs',
    'daily_wellness_logs',
    'daily_symptom_logs',
    'exercise_library',
    'workout_logs',
    'personal_goals',
    'goal_milestones',
    'goal_progress_logs',
    'journal_entries',
    'reflection_prompts',
    'weekly_reflections',
    'achievements',
    'user_achievements',
    'user_points',
    'points_history',
    'notifications',
    'notification_preferences',
    'admin_logs',
    'system_metrics',
    'payments',
    'leads',
    'system_settings',
    'ai_prompts',
    'cohort_enrollments',
    'exchange_rates',
    'cohort_prices',
    'email_logs',
    'cohort_completions',
    'user_activity_logs',
    'ai_usage_logs',
    'request_logs',
    'token_blacklist',
    'error_logs',
    'compliance_requests',
    'user_consents',
    'ai_conversations',
    'ai_usage_limits',
    'expiry_reminders_sent',
    'cohort_modules',
    'user_module_progress',
    'event_log',

    // Experiment Engine tables
    'experiments',
    'experiment_variants',
    'experiment_assignments',
    'experiment_events',
    'experiment_segment_stats',
    'experiment_decisions',

    // AI Variant Generation tables
    'variant_briefs',
    'variant_generations',
    'variant_candidates',
    'variant_insights',
];

$missing = array_diff($expectedTables, $existingTables);
$extra = array_diff($existingTables, $expectedTables);

if (empty($missing) && empty($extra)) {
    echo "[OK] All 56 expected tables exist.\n\n";
    echo "No migrations needed.\n";
    exit(0);
}

if (!empty($missing)) {
    echo "\n[MISSING] " . count($missing) . " tables need to be created:\n";
    echo "  " . implode(", ", $missing) . "\n";
}

if (!empty($extra)) {
    echo "\n[EXTRA] " . count($extra) . " unexpected tables detected:\n";
    echo "  " . implode(", ", $extra) . "\n";
}

// ────────────────────────────────────────────
// Run migrations from the canonical schema
// ────────────────────────────────────────────
echo "\n═══════════════════════════════════════════\n";
echo "  Creating missing tables...\n";
echo "═══════════════════════════════════════════\n\n";

// Read and execute the SQL schema file
$schemaFile = __DIR__ . '/mysql_schema.sql';
if (!file_exists($schemaFile)) {
    echo "[ERROR] Schema file not found: {$schemaFile}\n";
    exit(1);
}

$schema = file_get_contents($schemaFile);

// Remove DROP/CREATE DATABASE statements for safety on existing DBs
$schema = preg_replace('/^CREATE DATABASE.*$/m', '-- SKIPPED: DB already exists', $schema);
$schema = preg_replace('/^USE .*$/m', '-- SKIPPED: USING current DB', $schema);

// For non-MySQL drivers, filter DROP TABLE
if ($driver !== 'mysql') {
    $schema = preg_replace('/^DROP TABLE.*$/m', '-- SKIPPED: DROP not needed', $schema);
}

// Split into individual statements
$statements = array_filter(
    array_map('trim', explode(';', $schema)),
    function ($stmt) {
        return !empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, 'SET ');
    }
);

$created = 0;
$failed = 0;
$skipped = 0;

foreach ($statements as $statement) {
    $statement .= ';';

    // Skip statements that are just comments
    if (preg_match('/^\s*--/', $statement)) {
        continue;
    }

    // Skip SET FOREIGN_KEY_CHECKS
    if (str_contains($statement, 'FOREIGN_KEY_CHECKS')) {
        continue;
    }

    // Extract table name for reporting
    preg_match('/CREATE TABLE.*?(\w+)\s*\(/', $statement, $matches);
    $tableName = $matches[1] ?? 'unknown';

    // Skip if table already exists
    if (in_array($tableName, $existingTables)) {
        $skipped++;
        continue;
    }

    try {
        $db->exec($statement);
        echo "  [OK] Created: {$tableName}\n";
        $existingTables[] = $tableName;
        $created++;
    } catch (PDOException $e) {
        echo "  [FAIL] {$tableName}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n═══════════════════════════════════════════\n";
echo "  Migration Summary\n";
echo "═══════════════════════════════════════════\n";
echo "  Created:  {$created}\n";
echo "  Skipped:  {$skipped}\n";
echo "  Failed:   {$failed}\n";
echo "═══════════════════════════════════════════\n\n";

// ────────────────────────────────────────────
// Run experiment engine schema (A.1)
// ────────────────────────────────────────────
$expSchemaFile = __DIR__ . '/experiment_engine_schema.sql';
if (file_exists($expSchemaFile)) {
    echo "\n─── Experiment Engine Schema ───\n";
    $expSchema = file_get_contents($expSchemaFile);
    $expStatements = array_filter(
        array_map('trim', explode(';', $expSchema)),
        function ($stmt) {
            return !empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, 'SELECT ');
        }
    );

    foreach ($expStatements as $statement) {
        $statement .= ';';
        if (preg_match('/^\s*--/', $statement)) continue;
        preg_match('/CREATE TABLE.*?(\w+)\s*\(/', $statement, $matches);
        $tableName = $matches[1] ?? 'unknown';

        if (in_array($tableName, $existingTables)) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "  [OK] Created: {$tableName}\n";
            $existingTables[] = $tableName;
            $created++;
        } catch (PDOException $e) {
            echo "  [FAIL] {$tableName}: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
} else {
    echo "\n─── Experiment Engine Schema ───\n";
    echo "  [SKIP] File not found: {$expSchemaFile}\n";
}

// ────────────────────────────────────────────
// Experiment events user_id migration (A.3)
// ────────────────────────────────────────────
$uidMigrationFile = __DIR__ . '/experiment_events_user_id.sql';
if (file_exists($uidMigrationFile)) {
    echo "\n─── Experiment Events user_id ───\n";
    $uidSchema = file_get_contents($uidMigrationFile);
    $uidStatements = array_filter(
        array_map('trim', explode(';', $uidSchema)),
        fn($stmt) => !empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, 'SELECT ')
    );
    foreach ($uidStatements as $statement) {
        $statement .= ';';
        try {
            $db->exec($statement);
            echo "  [OK] experiment_events.user_id\n";
            $created++;
        } catch (PDOException $e) {
            echo "  [SKIP] experiment_events.user_id: {$e->getMessage()}\n";
        }
    }
}

// ────────────────────────────────────────────
// Run variant generation schema (B.1)
// ────────────────────────────────────────────
$vgSchemaFile = __DIR__ . '/variant_generation_schema.sql';
if (file_exists($vgSchemaFile)) {
    echo "\n─── Variant Generation Schema ───\n";
    $vgSchema = file_get_contents($vgSchemaFile);
    $vgStatements = array_filter(
        array_map('trim', explode(';', $vgSchema)),
        function ($stmt) {
            return !empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, 'SELECT ')
                && !str_starts_with($stmt, 'SET ') && !str_starts_with($stmt, 'USE ');
        }
    );

    foreach ($vgStatements as $statement) {
        $statement .= ';';
        if (preg_match('/^\s*--/', $statement)) continue;
        if (str_contains($statement, 'FOREIGN_KEY_CHECKS')) continue;
        preg_match('/CREATE TABLE.*?(\w+)\s*\(/', $statement, $matches);
        $tableName = $matches[1] ?? 'unknown';

        if (in_array($tableName, $existingTables)) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "  [OK] Created: {$tableName}\n";
            $existingTables[] = $tableName;
            $created++;
        } catch (PDOException $e) {
            echo "  [FAIL] {$tableName}: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
} else {
    echo "\n─── Variant Generation Schema ───\n";
    echo "  [SKIP] File not found: {$vgSchemaFile}\n";
}

echo "\n";

// ────────────────────────────────────────────
// Run data seeders (settings, AI prompts)
// ────────────────────────────────────────────
echo "Checking seed data...\n";

// Seed system settings
try {
    $check = $db->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
    if ($check == 0) {
        // Read INSERT from schema
        preg_match('/INSERT INTO system_settings.*?ON DUPLICATE.*?;/s', $schema, $insertMatch);
        if (!empty($insertMatch[0])) {
            $db->exec($insertMatch[0]);
            echo "  [OK] Default system settings seeded\n";
        }
    } else {
        echo "  [SKIP] System settings already have {$check} entries\n";
    }
} catch (Exception $e) {
    echo "  [WARN] Settings seed: " . $e->getMessage() . "\n";
}

// Seed AI prompts
try {
    $check = $db->query("SELECT COUNT(*) FROM ai_prompts")->fetchColumn();
    if ($check == 0) {
        // Run the AI prompt seeding from setup_db.php context
        echo "  [INFO] AI prompts not seeded; run setup_db.php for full agent config\n";
    } else {
        echo "  [SKIP] AI prompts already have {$check} entries\n";
    }
} catch (Exception $e) {
    echo "  [WARN] AI prompts: " . $e->getMessage() . "\n";
}

// Seed exchange rates
try {
    $check = $db->query("SELECT COUNT(*) FROM exchange_rates")->fetchColumn();
    if ($check == 0) {
        require_once __DIR__ . '/2024_01_multi_currency_security.php';
    } else {
        echo "  [SKIP] Exchange rates already have {$check} entries\n";
    }
} catch (Exception $e) {
    echo "  [WARN] Exchange rates: " . $e->getMessage() . "\n";
}

// Clean up old tokens
try {
    $db->exec("DELETE FROM token_blacklist WHERE expires_at < NOW()");
    $db->exec("DELETE FROM password_resets WHERE expires_at < NOW() AND used_at IS NULL");
    echo "  [OK] Expired tokens cleaned up\n";
} catch (Exception $e) {
    // Token blacklist table might not exist yet
}

echo "\nMigration complete!\n";