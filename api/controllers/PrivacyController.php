<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/EmailService.php';

class PrivacyController
{
    private $db;
    private $currentUser;
    private $exportDir;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
        $this->exportDir = __DIR__ . '/../../exports';

        // Create exports directory if it doesn't exist
        if (!file_exists($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    public function handleRequest($action)
    {
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        switch ($action) {
            case 'request-export':
                $this->requestExport();
                break;
            case 'download-export':
                $this->downloadExport();
                break;
            case 'my-exports':
                $this->getMyExports();
                break;
            case 'request-deletion':
                $this->requestDeletion();
                break;
            case 'cancel-deletion':
                $this->cancelDeletion();
                break;
            case 'update-consent':
                $this->updateConsent();
                break;
            case 'get-consents':
                $this->getConsents();
                break;
            default:
                http_response_code(404);
                echo json_encode(['message' => 'Action not found']);
                break;
        }
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $payload = AuthMiddleware::verifyToken($matches[1]);
        // Handle both direct ID and nested data structure
        if (isset($payload['data']['id'])) {
            return ['id' => $payload['data']['id'], 'email' => $payload['data']['email'] ?? ''];
        }
        return $payload ?: false;
    }

    private function getUserId()
    {
        return $this->currentUser['id'] ?? $this->currentUser['data']['id'] ?? null;
    }

    private function requestExport()
    {
        $userId = $this->getUserId();

        // Check for existing pending request
        $stmt = $this->db->prepare("SELECT id FROM compliance_requests WHERE user_id = ? AND type = 'export' AND status = 'pending'");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['message' => 'You already have a pending export request.']);
            return;
        }

        // Generate the export immediately (for small datasets)
        $exportResult = $this->generateDataExport($userId);

        if ($exportResult['success']) {
            $id = uniqid('req_');
            $stmt = $this->db->prepare("INSERT INTO compliance_requests (id, user_id, type, status, file_path, processed_at) VALUES (?, ?, 'export', 'completed', ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$id, $userId, $exportResult['filename']]);

            $notif = new NotificationService($this->db);
            $notif->create($userId, 'success', 'Data Export Ready', 'Your data export is ready for download.', '/settings?tab=privacy');

            echo json_encode([
                'success' => true,
                'message' => 'Export generated successfully.',
                'download_url' => '/api/privacy/download-export?id=' . $id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $exportResult['error']]);
        }
    }

    private function generateDataExport($userId): array
    {
        try {
            $data = [
                'export_date' => date('Y-m-d H:i:s'),
                'user_id' => $userId
            ];

            // 1. User Profile
            $stmt = $this->db->prepare("SELECT id, name, email, persona, role, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $data['profile'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. User Stats
            $stmt = $this->db->prepare("SELECT * FROM user_stats WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Daily Wellness Logs
            $stmt = $this->db->prepare("SELECT * FROM daily_wellness_logs WHERE user_id = ? ORDER BY log_date DESC");
            $stmt->execute([$userId]);
            $data['wellness_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Cycle Logs
            $stmt = $this->db->prepare("SELECT * FROM cycle_logs WHERE user_id = ? ORDER BY start_date DESC");
            $stmt->execute([$userId]);
            $data['cycle_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Journal Entries
            $stmt = $this->db->prepare("SELECT * FROM journal_entries WHERE user_id = ? ORDER BY entry_date DESC");
            $stmt->execute([$userId]);
            $data['journal_entries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Personal Goals
            $stmt = $this->db->prepare("SELECT * FROM personal_goals WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);
            $data['goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 7. Habits
            $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['habits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 8. Habit Logs
            $stmt = $this->db->prepare("SELECT * FROM habit_logs WHERE user_id = ? ORDER BY log_date DESC");
            $stmt->execute([$userId]);
            $data['habit_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 9. Meal Logs
            $stmt = $this->db->prepare("SELECT * FROM meal_logs WHERE user_id = ? ORDER BY logged_at DESC");
            $stmt->execute([$userId]);
            $data['meal_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 10. Workout Logs
            $stmt = $this->db->prepare("SELECT * FROM workout_logs WHERE user_id = ? ORDER BY workout_date DESC");
            $stmt->execute([$userId]);
            $data['workout_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 11. Routines
            $stmt = $this->db->prepare("SELECT * FROM user_routines WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['routines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 12. Achievements
            $stmt = $this->db->prepare("SELECT ua.*, a.title, a.description FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id WHERE ua.user_id = ?");
            $stmt->execute([$userId]);
            $data['achievements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 13. Payments (sanitized)
            $stmt = $this->db->prepare("SELECT id, amount, currency, status, gateway, description, created_at FROM payments WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 14. Enrollments
            $stmt = $this->db->prepare("SELECT e.*, c.title as cohort_title FROM cohort_enrollments e LEFT JOIN cohorts c ON e.cohort_id = c.id WHERE e.user_id = ?");
            $stmt->execute([$userId]);
            $data['enrollments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 15. Notifications
            $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([$userId]);
            $data['notifications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 16. Consents
            $stmt = $this->db->prepare("SELECT consent_type, is_granted, granted_at FROM user_consents WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['consents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate filename
            $filename = "export_{$userId}_" . date('Y-m-d_His') . ".json";
            $filepath = $this->exportDir . '/' . $filename;

            // Write JSON file
            file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];

        } catch (Exception $e) {
            error_log("Data export failed for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to generate export: ' . $e->getMessage()];
        }
    }

    private function downloadExport()
    {
        $userId = $this->getUserId();
        $requestId = $_GET['id'] ?? null;

        if (!$requestId) {
            http_response_code(400);
            echo json_encode(['message' => 'Export ID required']);
            return;
        }

        // Verify ownership
        $stmt = $this->db->prepare("SELECT file_path FROM compliance_requests WHERE id = ? AND user_id = ? AND type = 'export' AND status = 'completed'");
        $stmt->execute([$requestId, $userId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request || !$request['file_path']) {
            http_response_code(404);
            echo json_encode(['message' => 'Export not found']);
            return;
        }

        $filepath = $this->exportDir . '/' . $request['file_path'];

        if (!file_exists($filepath)) {
            http_response_code(404);
            echo json_encode(['message' => 'Export file not found']);
            return;
        }

        // Serve file
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $request['file_path'] . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    }

    private function getMyExports()
    {
        $userId = $this->getUserId();

        $stmt = $this->db->prepare("SELECT id, status, file_path, created_at, processed_at FROM compliance_requests WHERE user_id = ? AND type = 'export' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($exports);
    }

    private function requestDeletion()
    {
        $userId = $this->getUserId();
        $data = json_decode(file_get_contents("php://input"), true);
        $reason = $data['reason'] ?? '';

        // Check pending
        $stmt = $this->db->prepare("SELECT id FROM compliance_requests WHERE user_id = ? AND type = 'delete' AND status = 'pending'");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['message' => 'Deletion request already pending.']);
            return;
        }

        // Schedule for 30 days from now
        $scheduledFor = date('Y-m-d H:i:s', strtotime('+30 days'));

        $id = uniqid('req_');
        $stmt = $this->db->prepare("INSERT INTO compliance_requests (id, user_id, type, reason, status, scheduled_for) VALUES (?, ?, 'delete', ?, 'pending', ?)");
        $stmt->execute([$id, $userId, $reason, $scheduledFor]);

        // Send notification
        $notif = new NotificationService($this->db);
        $notif->create($userId, 'warning', 'Account Deletion Scheduled',
            "Your account is scheduled for deletion on " . date('F j, Y', strtotime($scheduledFor)) . ". You can cancel this request within 30 days.",
            '/settings?tab=privacy');

        // Send email confirmation
        try {
            $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $emailService = new EmailService($this->db);
                $emailService->send(
                    $user['email'],
                    'Account Deletion Request Received',
                    "<p>Hi {$user['name']},</p>
                    <p>We received your request to delete your Zenith Wellness account.</p>
                    <p>Your account is scheduled for permanent deletion on <strong>" . date('F j, Y', strtotime($scheduledFor)) . "</strong>.</p>
                    <p>If you change your mind, you can cancel this request anytime within the next 30 days from your Privacy Settings.</p>
                    <p>Once deleted, your data cannot be recovered.</p>
                    <p>Best regards,<br>Zenith Wellness Team</p>"
                );
            }
        } catch (Exception $e) {
            error_log("Failed to send deletion confirmation email: " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Deletion request submitted. Your account will be deleted in 30 days unless cancelled.',
            'scheduled_for' => $scheduledFor
        ]);
    }

    private function cancelDeletion()
    {
        $userId = $this->getUserId();

        $stmt = $this->db->prepare("UPDATE compliance_requests SET status = 'cancelled' WHERE user_id = ? AND type = 'delete' AND status = 'pending'");
        $stmt->execute([$userId]);

        if ($stmt->rowCount() > 0) {
            $notif = new NotificationService($this->db);
            $notif->create($userId, 'info', 'Deletion Cancelled', 'Your account deletion request has been cancelled. Your account remains active.');

            echo json_encode(['success' => true, 'message' => 'Deletion request cancelled.']);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'No pending deletion request found.']);
        }
    }

    private function updateConsent()
    {
        $userId = $this->getUserId();
        $data = json_decode(file_get_contents("php://input"), true);
        $consents = $data['consents'] ?? [];

        $stmt = $this->db->prepare("INSERT OR REPLACE INTO user_consents (user_id, consent_type, is_granted, ip_address, user_agent, granted_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        foreach ($consents as $type => $granted) {
            $stmt->execute([$userId, $type, $granted ? 1 : 0, $ip, $ua]);
        }

        echo json_encode(['success' => true]);
    }

    private function getConsents()
    {
        $userId = $this->getUserId();

        $stmt = $this->db->prepare("SELECT consent_type, is_granted, granted_at FROM user_consents WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $consents = [];
        foreach ($rows as $row) {
            $consents[$row['consent_type']] = [
                'granted' => (bool) $row['is_granted'],
                'granted_at' => $row['granted_at']
            ];
        }

        echo json_encode($consents);
    }

    /**
     * Anonymize user data (called by admin when processing deletion)
     * This is a static method that can be called from AdminComplianceController
     */
    public static function anonymizeUser($db, $userId)
    {
        try {
            $db->beginTransaction();

            $anonymousEmail = 'deleted_' . md5($userId . time()) . '@deleted.local';
            $anonymousName = 'Deleted User';

            // 1. Anonymize user record
            $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = '', persona = 'deleted', role = 'deleted' WHERE id = ?");
            $stmt->execute([$anonymousName, $anonymousEmail, $userId]);

            // 2. Delete sensitive data from user_stats
            $stmt = $db->prepare("DELETE FROM user_stats WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 3. Delete journal entries (personal content)
            $stmt = $db->prepare("DELETE FROM journal_entries WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 4. Delete cycle logs (sensitive health data)
            $stmt = $db->prepare("DELETE FROM cycle_logs WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 5. Delete symptom logs
            $stmt = $db->prepare("DELETE FROM daily_symptom_logs WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 6. Delete wellness logs
            $stmt = $db->prepare("DELETE FROM daily_wellness_logs WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 7. Delete habits and logs
            $stmt = $db->prepare("DELETE FROM habit_logs WHERE user_id = ?");
            $stmt->execute([$userId]);
            $stmt = $db->prepare("DELETE FROM user_habits WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 8. Delete goals and progress
            $stmt = $db->prepare("DELETE FROM goal_progress_logs WHERE goal_id IN (SELECT id FROM personal_goals WHERE user_id = ?)");
            $stmt->execute([$userId]);
            $stmt = $db->prepare("DELETE FROM goal_milestones WHERE goal_id IN (SELECT id FROM personal_goals WHERE user_id = ?)");
            $stmt->execute([$userId]);
            $stmt = $db->prepare("DELETE FROM personal_goals WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 9. Delete meal logs
            $stmt = $db->prepare("DELETE FROM meal_logs WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 10. Delete workout logs
            $stmt = $db->prepare("DELETE FROM workout_logs WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 11. Delete routines
            $stmt = $db->prepare("DELETE FROM routine_completions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $stmt = $db->prepare("DELETE FROM user_routine_items WHERE routine_id IN (SELECT id FROM user_routines WHERE user_id = ?)");
            $stmt->execute([$userId]);
            $stmt = $db->prepare("DELETE FROM user_routines WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 12. Delete chat sessions
            $stmt = $db->prepare("DELETE FROM chat_sessions WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 13. Delete notifications
            $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 14. Delete consents (they've requested deletion, consent is void)
            $stmt = $db->prepare("DELETE FROM user_consents WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 15. Revoke enrollments
            $stmt = $db->prepare("UPDATE cohort_enrollments SET status = 'deleted' WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 16. Keep payment records but anonymize (for accounting)
            $stmt = $db->prepare("UPDATE payments SET description = 'Deleted User Transaction' WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 17. Delete password reset tokens
            $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 18. Update compliance request
            $stmt = $db->prepare("UPDATE compliance_requests SET status = 'completed', processed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND type = 'delete' AND status = 'pending'");
            $stmt->execute([$userId]);

            $db->commit();

            return ['success' => true, 'message' => 'User data anonymized successfully'];

        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to anonymize user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
