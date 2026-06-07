<?php
require_once __DIR__ . '/../config/Database.php';

class EnrollmentService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function grantAccess($userId, $cohortId, $paymentId = null, $durationDays = null)
    {
        // Fetch cohort to get actual duration_days if not provided
        if ($durationDays === null) {
            $stmt = $this->db->prepare("SELECT duration_days FROM cohorts WHERE id = ?");
            $stmt->execute([$cohortId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
            $durationDays = $cohort ? (int)$cohort['duration_days'] : 21;
        }

        // Check for existing enrollment and capacity
        $stmt = $this->db->prepare("SELECT id FROM cohort_enrollments WHERE user_id = ? AND cohort_id = ? AND status = 'active'");
        $stmt->execute([$userId, $cohortId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            // Check capacity
            $stmt = $this->db->prepare("
                SELECT c.max_participants, COUNT(e.id) as current_count
                FROM cohorts c
                LEFT JOIN cohort_enrollments e ON c.id = e.cohort_id AND e.status = 'active'
                WHERE c.id = ?
                GROUP BY c.id, c.max_participants
            ");
            $stmt->execute([$cohortId]);
            $capacity = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($capacity && $capacity['max_participants'] && (int)$capacity['current_count'] >= (int)$capacity['max_participants']) {
                throw new Exception("Cohort is at maximum capacity");
            }
        }

        $startsAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$durationDays days"));

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE cohort_enrollments
                SET status='active', access_expires_at = ?, payment_id = ?, enrolled_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$expiresAt, $paymentId, $existing['id']]);
        } else {
            $id = 'enr_' . bin2hex(random_bytes(12));
            $stmt = $this->db->prepare("
                INSERT INTO cohort_enrollments (id, user_id, cohort_id, payment_id, access_starts_at, access_expires_at, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$id, $userId, $cohortId, $paymentId, $startsAt, $expiresAt]);
        }

        // Backward compatibility
        $this->updateLegacyPurchases($userId, $cohortId, 'add');

        // Fetch cohort name for email
        $stmt = $this->db->prepare("SELECT title FROM cohorts WHERE id = ?");
        $stmt->execute([$cohortId]);
        $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
        $cohortName = $cohort ? $cohort['title'] : 'Wellness Protocol';

        // Send Welcome Email & Notification (don't fail if email fails)
        try {
            require_once __DIR__ . '/../services/EmailService.php';
            require_once __DIR__ . '/../services/NotificationService.php';

            $emailService = new EmailService($this->db);
            $notificationService = new NotificationService($this->db);

            $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user) {
                $emailService->sendTemplate($user['email'], 'cohort_access', [
                    'name' => $user['name'],
                    'cohort_name' => $cohortName,
                    'expires_at' => date('F j, Y', strtotime($expiresAt)),
                    'link' => (getenv('APP_URL') ?: 'http://localhost:3000') . '/dashboard',
                    'year' => date('Y')
                ]);

                $notificationService->create(
                    $userId,
                    'info',
                    'Access Granted',
                    "You have been enrolled in $cohortName. Access expires on " . date('M j, Y', strtotime($expiresAt)),
                    '/dashboard'
                );
            }
        } catch (Exception $e) {
            error_log("Failed to send welcome email/notification: " . $e->getMessage());
        }
    }

    public function revokeAccess($userId, $cohortId)
    {
        $stmt = $this->db->prepare("UPDATE cohort_enrollments SET status = 'revoked' WHERE user_id = ? AND cohort_id = ?");
        $stmt->execute([$userId, $cohortId]);

        $this->updateLegacyPurchases($userId, $cohortId, 'remove');
    }

    private function updateLegacyPurchases($userId, $cohortId, $action)
    {
        $stmt = $this->db->prepare("SELECT purchased_programs FROM user_stats WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $purchases = [];
        if ($row && !empty($row['purchased_programs'])) {
            $purchases = json_decode($row['purchased_programs'], true) ?? [];
        }

        if ($action === 'add' && !in_array($cohortId, $purchases)) {
            $purchases[] = $cohortId;
        } elseif ($action === 'remove') {
            $purchases = array_values(array_diff($purchases, [$cohortId]));
        }

        $stmt = $this->db->prepare("UPDATE user_stats SET purchased_programs = ? WHERE user_id = ?");
        $stmt->execute([json_encode($purchases), $userId]);
    }
}
