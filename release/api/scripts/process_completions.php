<?php
/**
 * Process Cohort Completions Cron Script
 * 
 * Run this daily via cron: 0 10 * * * php /path/to/process_completions.php
 * 
 * Processes enrolled users whose cohort access just expired (within 24 hours),
 * sends completion summary emails, and marks them as alumni.
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/CohortCompletionService.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationService.php';

// Initialize
$database = new Database();
$db = $database->getConnection();

$completionService = new CohortCompletionService($db);
$emailService = new EmailService($db);
$notificationService = new NotificationService($db);

$appUrl = getenv('APP_URL') ?: 'https://app.zenithwellness.co';

echo "=== Processing Cohort Completions ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Get recently completed enrollments
$completedEnrollments = $completionService->processCompletedCohorts();
echo "Found " . count($completedEnrollments) . " enrollments to process\n\n";

$successCount = 0;
$errorCount = 0;

foreach ($completedEnrollments as $enrollment) {
    try {
        echo "Processing: {$enrollment['name']} - {$enrollment['cohort_name']}\n";

        // Mark as completed and get stats
        $result = $completionService->markCompleted(
            $enrollment['enrollment_id'],
            $enrollment['user_id'],
            $enrollment['cohort_id']
        );

        $stats = $result['stats'];
        $completionId = $result['completion_id'];

        // Get certificate data
        $certData = $completionService->getCertificateData($completionId);

        // Send completion email
        $emailResult = $emailService->sendTemplate($enrollment['email'], 'cohort_completion', [
            'name' => $enrollment['name'],
            'cohort_name' => $enrollment['cohort_name'],
            'journal_entries' => $stats['journal_entries'] ?? 0,
            'habits_completed' => $stats['habits_completed'] ?? 0,
            'workouts_logged' => $stats['workouts_logged'] ?? 0,
            'points_earned' => $stats['points_earned'] ?? 0,
            'verification_code' => $certData['verification_code'] ?? '',
            'certificate_link' => $appUrl . '/certificate/' . $completionId,
            'dashboard_link' => $appUrl . '/dashboard',
            'programs_link' => $appUrl . '/programs',
            'year' => date('Y')
        ]);

        if ($emailResult['status'] === 'success') {
            echo "  [OK] Completion email sent\n";
        } else {
            echo "  [WARN] Email failed: {$emailResult['message']}\n";
        }

        // Create celebration notification
        $notificationService->create(
            $enrollment['user_id'],
            'achievement',
            '🎉 Cohort Completed!',
            "Congratulations! You've completed {$enrollment['cohort_name']}. Your certificate is ready!",
            '/certificate/' . $completionId
        );

        $successCount++;
        echo "  [OK] Marked as completed\n\n";

    } catch (Exception $e) {
        $errorCount++;
        echo "  [ERROR] {$e->getMessage()}\n\n";
    }
}

echo "=== Summary ===\n";
echo "Processed: $successCount\n";
echo "Errors: $errorCount\n";
