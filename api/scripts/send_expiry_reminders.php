<?php
/**
 * Cohort Expiry Reminder Cron Script
 * 
 * Run this daily via cron: 0 9 * * * php /path/to/send_expiry_reminders.php
 * 
 * Sends reminder emails to users whose cohort access expires in 3 days or 1 day.
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationService.php';

// Initialize database
$database = new Database();
$db = $database->getConnection();

$emailService = new EmailService($db);
$notificationService = new NotificationService($db);

$appUrl = getenv('APP_URL') ?: 'https://app.zenithwellness.co';

// Get users with enrollments expiring in 3 days or 1 day
$stmt = $db->prepare("
    SELECT 
        e.id as enrollment_id,
        e.user_id,
        e.cohort_id,
        e.access_expires_at,
        u.name,
        u.email,
        c.title as cohort_name,
        CAST(julianday(e.access_expires_at) - julianday('now') AS INTEGER) as days_remaining
    FROM cohort_enrollments e
    JOIN users u ON e.user_id = u.id
    JOIN cohorts c ON e.cohort_id = c.id
    WHERE e.status = 'active'
    AND e.access_expires_at > CURRENT_TIMESTAMP
    AND (
        DATE(e.access_expires_at) = DATE('now', '+3 days')
        OR DATE(e.access_expires_at) = DATE('now', '+1 day')
    )
    AND NOT EXISTS (
        SELECT 1 FROM expiry_reminders_sent 
        WHERE enrollment_id = e.id 
        AND days_before = CAST(julianday(e.access_expires_at) - julianday('now') AS INTEGER)
    )
");
$stmt->execute();
$expiringEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sentCount = 0;
$errorCount = 0;

foreach ($expiringEnrollments as $enrollment) {
    try {
        $daysRemaining = max(1, $enrollment['days_remaining']);
        
        // Send email
        $result = $emailService->sendTemplate($enrollment['email'], 'expiry_reminder', [
            'name' => $enrollment['name'],
            'cohort_name' => $enrollment['cohort_name'],
            'days_remaining' => $daysRemaining,
            'expires_at' => date('F j, Y', strtotime($enrollment['access_expires_at'])),
            'dashboard_link' => $appUrl . '/dashboard',
            'renew_link' => $appUrl . '/programs',
            'year' => date('Y')
        ]);

        if ($result['status'] === 'success') {
            // Create in-app notification
            $notificationService->create(
                $enrollment['user_id'],
                'warning',
                'Access Expiring Soon',
                "Your access to {$enrollment['cohort_name']} expires in {$daysRemaining} day(s).",
                '/dashboard'
            );

            // Track that we sent this reminder
            $trackStmt = $db->prepare("
                INSERT INTO expiry_reminders_sent (enrollment_id, days_before, sent_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ");
            $trackStmt->execute([$enrollment['enrollment_id'], $daysRemaining]);

            $sentCount++;
            echo "[OK] Sent reminder to {$enrollment['email']} ({$daysRemaining} days remaining)\n";
        } else {
            $errorCount++;
            echo "[ERROR] Failed to send to {$enrollment['email']}: {$result['message']}\n";
        }
    } catch (Exception $e) {
        $errorCount++;
        echo "[ERROR] Exception for {$enrollment['email']}: {$e->getMessage()}\n";
    }
}

echo "\n=== Summary ===\n";
echo "Sent: $sentCount\n";
echo "Errors: $errorCount\n";
echo "Total processed: " . count($expiringEnrollments) . "\n";
