<?php
/**
 * Master Backup Script for Zenith Wellness
 *
 * Runs all backup tasks and sends notification on completion/failure
 *
 * Usage:
 *   php backup.php              # Run full backup
 *   php backup.php --notify     # Send email notification after backup
 *   php backup.php --verbose    # Show detailed output
 *
 * Cron example (daily at 2 AM):
 *   0 2 * * * cd /path/to/api/scripts && php backup.php --notify >> /var/log/zenith-backup.log 2>&1
 */

require_once __DIR__ . '/../config.php';

// Parse arguments
$options = getopt('', ['notify', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Zenith Wellness Master Backup Script\n";
    echo "=====================================\n\n";
    echo "Usage: php backup.php [options]\n\n";
    echo "Options:\n";
    echo "  --notify     Send email notification after backup\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --help       Show this help message\n\n";
    echo "Cron example (daily at 2 AM):\n";
    echo "  0 2 * * * cd /path/to/api/scripts && php backup.php --notify\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$notify = isset($options['notify']);
$startTime = microtime(true);

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => defined('PRODUCTION') && PRODUCTION ? 'production' : 'development',
    'tasks' => [],
    'success' => true
];

/**
 * Run a backup task and capture output
 */
function runBackupTask($script, $name, &$results, $verbose) {
    $output = [];
    $returnCode = 0;

    $command = "php " . escapeshellarg($script) . " 2>&1";

    exec($command, $output, $returnCode);

    $task = [
        'name' => $name,
        'success' => $returnCode === 0,
        'output' => implode("\n", $output)
    ];

    $results['tasks'][] = $task;

    if ($returnCode !== 0) {
        $results['success'] = false;
    }

    if ($verbose) {
        echo "\n=== $name ===\n";
        echo implode("\n", $output);
        echo "\n";
    } else {
        echo "$name: " . ($returnCode === 0 ? "OK" : "FAILED") . "\n";
    }

    return $returnCode === 0;
}

echo "Zenith Wellness Backup\n";
echo "======================\n";
echo "Started: " . $results['timestamp'] . "\n";
echo "Environment: " . $results['environment'] . "\n\n";

// Run database backup
runBackupTask(__DIR__ . '/backup_db.php', 'Database Backup', $results, $verbose);

// Run file backup
runBackupTask(__DIR__ . '/backup_files.php', 'File Backup', $results, $verbose);

// Calculate duration
$duration = round(microtime(true) - $startTime, 2);
$results['duration_seconds'] = $duration;

echo "\n";
echo "======================\n";
echo "Backup " . ($results['success'] ? "COMPLETED" : "FAILED") . "\n";
echo "Duration: {$duration}s\n";

// Send notification if requested
if ($notify) {
    require_once __DIR__ . '/../config/Database.php';
    require_once __DIR__ . '/../services/EmailService.php';

    $database = new Database();
    $db = $database->getConnection();

    if ($db) {
        $emailService = new EmailService($db);

        $adminEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : null;

        // Get admin email from settings if not in config
        if (!$adminEmail) {
            $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'support_email'");
            $adminEmail = $stmt->fetchColumn();
        }

        if ($adminEmail) {
            $status = $results['success'] ? '✅ SUCCESS' : '❌ FAILED';
            $subject = "[Zenith Backup] $status - " . date('Y-m-d');

            $taskSummary = '';
            foreach ($results['tasks'] as $task) {
                $icon = $task['success'] ? '✓' : '✗';
                $taskSummary .= "<li>$icon {$task['name']}</li>\n";
            }

            $body = "
            <h2>Zenith Wellness Backup Report</h2>
            <p><strong>Status:</strong> $status</p>
            <p><strong>Environment:</strong> {$results['environment']}</p>
            <p><strong>Timestamp:</strong> {$results['timestamp']}</p>
            <p><strong>Duration:</strong> {$duration} seconds</p>

            <h3>Tasks</h3>
            <ul>$taskSummary</ul>
            ";

            if (!$results['success']) {
                $body .= "<h3>Error Details</h3>";
                foreach ($results['tasks'] as $task) {
                    if (!$task['success']) {
                        $body .= "<p><strong>{$task['name']}:</strong></p>";
                        $body .= "<pre>" . htmlspecialchars($task['output']) . "</pre>";
                    }
                }
            }

            try {
                $emailService->send($adminEmail, $subject, $body);
                echo "Notification sent to: $adminEmail\n";
            } catch (Exception $e) {
                echo "Failed to send notification: " . $e->getMessage() . "\n";
            }
        } else {
            echo "No admin email configured, skipping notification\n";
        }
    }
}

// Write backup log
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/backup.log';
$logEntry = date('Y-m-d H:i:s') . " | " .
    ($results['success'] ? 'SUCCESS' : 'FAILED') . " | " .
    "{$duration}s | " .
    $results['environment'] . "\n";

file_put_contents($logFile, $logEntry, FILE_APPEND);

exit($results['success'] ? 0 : 1);
