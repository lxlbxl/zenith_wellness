<?php
/**
 * Database Backup Script for Zenith Wellness
 *
 * Usage:
 *   php backup_db.php                    # Full backup
 *   php backup_db.php --tables users     # Backup specific table
 *   php backup_db.php --days 7           # Cleanup backups older than 7 days
 *
 * Supports both SQLite (local) and PostgreSQL (production)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/Database.php';

// Configuration
$BACKUP_DIR = __DIR__ . '/../../backups/database';
$MAX_BACKUPS = 30; // Keep last 30 backups
$DATE_FORMAT = 'Y-m-d_H-i-s';

// Parse command line arguments
$options = getopt('', ['tables:', 'days:', 'cleanup', 'help']);

if (isset($options['help'])) {
    echo "Zenith Wellness Database Backup Script\n";
    echo "======================================\n\n";
    echo "Usage: php backup_db.php [options]\n\n";
    echo "Options:\n";
    echo "  --tables TABLE1,TABLE2   Backup specific tables only\n";
    echo "  --days N                 Remove backups older than N days\n";
    echo "  --cleanup                Run cleanup only (no new backup)\n";
    echo "  --help                   Show this help message\n\n";
    exit(0);
}

// Ensure backup directory exists
if (!is_dir($BACKUP_DIR)) {
    mkdir($BACKUP_DIR, 0755, true);
    echo "Created backup directory: $BACKUP_DIR\n";
}

// Initialize database
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "ERROR: Could not connect to database\n";
    exit(1);
}

$timestamp = date($DATE_FORMAT);
$isProduction = defined('PRODUCTION') && PRODUCTION === true;

/**
 * Cleanup old backups
 */
function cleanupOldBackups($backupDir, $maxAge = 30) {
    $files = glob("$backupDir/*.sql") + glob("$backupDir/*.sqlite") + glob("$backupDir/*.gz");
    $deleted = 0;

    foreach ($files as $file) {
        $fileAge = (time() - filemtime($file)) / 86400; // Age in days
        if ($fileAge > $maxAge) {
            unlink($file);
            $deleted++;
            echo "Deleted old backup: " . basename($file) . "\n";
        }
    }

    return $deleted;
}

/**
 * Backup SQLite database (development)
 */
function backupSqlite($db, $backupDir, $timestamp, $tables = null) {
    $sourceDb = __DIR__ . '/../../database.sqlite';

    if (!file_exists($sourceDb)) {
        echo "ERROR: SQLite database not found at $sourceDb\n";
        return false;
    }

    $backupFile = "$backupDir/zenith_backup_$timestamp.sqlite";

    if ($tables) {
        // Partial backup - export specific tables to SQL
        $backupFile = "$backupDir/zenith_partial_$timestamp.sql";
        $sql = "";

        foreach ($tables as $table) {
            $table = trim($table);

            // Get table schema
            $stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
            $schema = $stmt->fetchColumn();

            if (!$schema) {
                echo "WARNING: Table '$table' not found, skipping...\n";
                continue;
            }

            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS $table;\n";
            $sql .= "$schema;\n\n";

            // Get table data
            $dataStmt = $db->query("SELECT * FROM $table");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $columns = implode(', ', array_keys($row));
                $values = implode(', ', array_map(function($v) use ($db) {
                    return $v === null ? 'NULL' : $db->quote($v);
                }, array_values($row)));
                $sql .= "INSERT INTO $table ($columns) VALUES ($values);\n";
            }
            $sql .= "\n";
        }

        file_put_contents($backupFile, $sql);
        echo "Partial backup created: $backupFile\n";

    } else {
        // Full backup - copy the entire file
        if (copy($sourceDb, $backupFile)) {
            echo "Full SQLite backup created: $backupFile\n";

            // Compress if larger than 10MB
            if (filesize($backupFile) > 10 * 1024 * 1024) {
                $gzFile = "$backupFile.gz";
                $fp = gzopen($gzFile, 'w9');
                gzwrite($fp, file_get_contents($backupFile));
                gzclose($fp);
                unlink($backupFile);
                echo "Compressed backup: $gzFile\n";
            }
        } else {
            echo "ERROR: Failed to create backup\n";
            return false;
        }
    }

    return true;
}

/**
 * Backup PostgreSQL database (production)
 */
function backupPostgres($backupDir, $timestamp, $tables = null) {
    $databaseUrl = getenv('DATABASE_URL');

    if (!$databaseUrl) {
        echo "ERROR: DATABASE_URL not set\n";
        return false;
    }

    $dbopts = parse_url($databaseUrl);
    $host = $dbopts['host'];
    $port = $dbopts['port'] ?? 5432;
    $dbname = ltrim($dbopts['path'], '/');
    $user = $dbopts['user'];
    $pass = $dbopts['pass'];

    $backupFile = "$backupDir/zenith_backup_$timestamp.sql";

    // Set password for pg_dump
    putenv("PGPASSWORD=$pass");

    $tableOption = '';
    if ($tables) {
        foreach ($tables as $table) {
            $tableOption .= " -t " . escapeshellarg(trim($table));
        }
    }

    $command = sprintf(
        'pg_dump -h %s -p %d -U %s %s %s > %s',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        $tableOption,
        escapeshellarg($dbname),
        escapeshellarg($backupFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        echo "PostgreSQL backup created: $backupFile\n";

        // Compress
        $gzFile = "$backupFile.gz";
        $fp = gzopen($gzFile, 'w9');
        gzwrite($fp, file_get_contents($backupFile));
        gzclose($fp);
        unlink($backupFile);
        echo "Compressed backup: $gzFile\n";

        return true;
    } else {
        echo "ERROR: pg_dump failed with code $returnCode\n";
        return false;
    }
}

// Run cleanup if requested
if (isset($options['cleanup'])) {
    $days = isset($options['days']) ? (int)$options['days'] : 30;
    $deleted = cleanupOldBackups($BACKUP_DIR, $days);
    echo "Cleanup complete. Deleted $deleted old backups.\n";
    exit(0);
}

// Parse tables option
$tables = null;
if (isset($options['tables'])) {
    $tables = explode(',', $options['tables']);
}

// Run backup based on environment
echo "Starting database backup...\n";
echo "Environment: " . ($isProduction ? 'PRODUCTION' : 'DEVELOPMENT') . "\n";
echo "Timestamp: $timestamp\n\n";

if ($isProduction || getenv('DATABASE_URL')) {
    $success = backupPostgres($BACKUP_DIR, $timestamp, $tables);
} else {
    $success = backupSqlite($db, $BACKUP_DIR, $timestamp, $tables);
}

// Run cleanup
if ($success) {
    $days = isset($options['days']) ? (int)$options['days'] : 30;
    cleanupOldBackups($BACKUP_DIR, $days);
}

echo "\nBackup " . ($success ? "completed successfully" : "FAILED") . "\n";
exit($success ? 0 : 1);
