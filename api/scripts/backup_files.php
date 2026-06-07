<?php
/**
 * File Backup Script for Zenith Wellness
 *
 * Backs up user exports and uploaded files
 *
 * Usage:
 *   php backup_files.php                    # Full backup
 *   php backup_files.php --exports-only     # Backup exports only
 *   php backup_files.php --uploads-only     # Backup uploads only
 *   php backup_files.php --days 30          # Cleanup backups older than 30 days
 */

require_once __DIR__ . '/../config.php';

// Configuration
$BACKUP_DIR = __DIR__ . '/../../backups/files';
$EXPORTS_DIR = __DIR__ . '/../../exports';
$UPLOADS_DIR = __DIR__ . '/../../uploads';
$MAX_AGE_DAYS = 90;
$DATE_FORMAT = 'Y-m-d_H-i-s';

// Parse command line arguments
$options = getopt('', ['exports-only', 'uploads-only', 'days:', 'cleanup', 'help']);

if (isset($options['help'])) {
    echo "Zenith Wellness File Backup Script\n";
    echo "===================================\n\n";
    echo "Usage: php backup_files.php [options]\n\n";
    echo "Options:\n";
    echo "  --exports-only     Backup exports directory only\n";
    echo "  --uploads-only     Backup uploads directory only\n";
    echo "  --days N           Remove backups older than N days\n";
    echo "  --cleanup          Run cleanup only (no new backup)\n";
    echo "  --help             Show this help message\n\n";
    exit(0);
}

// Ensure backup directory exists
if (!is_dir($BACKUP_DIR)) {
    mkdir($BACKUP_DIR, 0755, true);
    echo "Created backup directory: $BACKUP_DIR\n";
}

$timestamp = date($DATE_FORMAT);

/**
 * Create a zip archive of a directory
 */
function createZipBackup($sourceDir, $backupDir, $name, $timestamp) {
    if (!is_dir($sourceDir)) {
        echo "WARNING: Source directory not found: $sourceDir\n";
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $fileCount = iterator_count($files);

    if ($fileCount === 0) {
        echo "INFO: $name directory is empty, skipping...\n";
        return true;
    }

    $zipFile = "$backupDir/{$name}_backup_$timestamp.zip";

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "ERROR: Could not create zip file: $zipFile\n";
        return false;
    }

    // Re-iterate for adding to zip
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $addedFiles = 0;
    foreach ($files as $file) {
        if (!$file->isFile()) continue;

        $filePath = $file->getRealPath();
        $relativePath = $name . '/' . substr($filePath, strlen($sourceDir) + 1);

        $zip->addFile($filePath, $relativePath);
        $addedFiles++;
    }

    $zip->close();

    $size = filesize($zipFile);
    $sizeFormatted = formatBytes($size);

    echo "Created $name backup: $zipFile ($sizeFormatted, $addedFiles files)\n";
    return true;
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Cleanup old backup files
 */
function cleanupOldBackups($backupDir, $maxAgeDays) {
    $files = glob("$backupDir/*.zip");
    $deleted = 0;

    foreach ($files as $file) {
        $fileAge = (time() - filemtime($file)) / 86400;
        if ($fileAge > $maxAgeDays) {
            $size = formatBytes(filesize($file));
            unlink($file);
            $deleted++;
            echo "Deleted old backup: " . basename($file) . " ($size)\n";
        }
    }

    return $deleted;
}

/**
 * Get directory size
 */
function getDirectorySize($dir) {
    $size = 0;

    if (!is_dir($dir)) return 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

// Run cleanup if requested
if (isset($options['cleanup'])) {
    $days = isset($options['days']) ? (int)$options['days'] : $MAX_AGE_DAYS;
    $deleted = cleanupOldBackups($BACKUP_DIR, $days);
    echo "Cleanup complete. Deleted $deleted old backups.\n";
    exit(0);
}

echo "Starting file backup...\n";
echo "Timestamp: $timestamp\n\n";

$success = true;
$exportsOnly = isset($options['exports-only']);
$uploadsOnly = isset($options['uploads-only']);

// Backup exports
if (!$uploadsOnly) {
    echo "Exports directory: $EXPORTS_DIR\n";
    echo "Size: " . formatBytes(getDirectorySize($EXPORTS_DIR)) . "\n";

    if (!createZipBackup($EXPORTS_DIR, $BACKUP_DIR, 'exports', $timestamp)) {
        $success = false;
    }
    echo "\n";
}

// Backup uploads
if (!$exportsOnly) {
    echo "Uploads directory: $UPLOADS_DIR\n";
    echo "Size: " . formatBytes(getDirectorySize($UPLOADS_DIR)) . "\n";

    if (!createZipBackup($UPLOADS_DIR, $BACKUP_DIR, 'uploads', $timestamp)) {
        $success = false;
    }
    echo "\n";
}

// Cleanup old backups
$days = isset($options['days']) ? (int)$options['days'] : $MAX_AGE_DAYS;
echo "Running cleanup (removing backups older than $days days)...\n";
$deleted = cleanupOldBackups($BACKUP_DIR, $days);
if ($deleted > 0) {
    echo "Deleted $deleted old backups.\n";
}

// Summary
echo "\n";
echo "Backup " . ($success ? "completed successfully" : "completed with warnings") . "\n";

// List current backups
echo "\nCurrent backups:\n";
$backups = glob("$BACKUP_DIR/*.zip");
usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });

foreach (array_slice($backups, 0, 10) as $backup) {
    $age = round((time() - filemtime($backup)) / 86400, 1);
    echo "  - " . basename($backup) . " (" . formatBytes(filesize($backup)) . ", {$age} days old)\n";
}

if (count($backups) > 10) {
    echo "  ... and " . (count($backups) - 10) . " more\n";
}

exit($success ? 0 : 1);
