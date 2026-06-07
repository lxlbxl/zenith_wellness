<?php
require_once __DIR__ . '/../config/Database.php';

class EnrollmentService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function grantAccess($userId, $cohortId, $paymentId = null, $durationDays = 21)
    {
        $startsAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$durationDays days"));

        // Check for existing enrollment
        $stmt = $this->db->prepare("SELECT id FROM cohort_enrollments WHERE user_id = ? AND cohort_id = ?");
        $stmt->execute([$userId, $cohortId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE cohort_enrollments 
                SET status='active', access_expires_at = ?, payment_id = ?, enrolled_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$expiresAt, $paymentId, $existing['id']]);
        } else {
            $id = uniqid('enr_');
            $stmt = $this->db->prepare("
                INSERT INTO cohort_enrollments (id, user_id, cohort_id, payment_id, access_starts_at, access_expires_at, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$id, $userId, $cohortId, $paymentId, $startsAt, $expiresAt]);
        }

        // Backward compatibility
        $this->updateLegacyPurchases($userId, $cohortId, 'add');

        // Send Welcome Email
        // Send Welcome Email & Notification
        try {
            require_once __DIR__ . '/../services/EmailService.php';
            require_once __DIR__ . '/../services/NotificationService.php';

            $emailService = new EmailService($this->db);
            $notificationService = new NotificationService($this->db);

            // Fetch User and Cohort details
            $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            // Mock cohort name for now or fetch if we had a cohorts table
            $cohortName = "Wellness Protocol";

            if ($user) {
                // Email
                $emailService->sendTemplate($user['email'], 'cohort_access', [
                    'name' => $user['name'],
                    'cohort_name' => $cohortName,
                    'expires_at' => date('F j, Y', strtotime($expiresAt)),
                    'link' => (getenv('APP_URL') ?: 'http://localhost:3000') . '/dashboard',
                    'year' => date('Y')
                ]);

                // Notification
                $notificationService->create(
                    $userId,
                    'info',
                    'Access Granted',
                    "You have been enrolled in $cohortName. Access expires on " . date('M j, Y', strtotime($expiresAt)),
                    '/dashboard'
                );
            }
        } catch (Exception $e) {
            // Don't fail the transaction if email fails
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
        // Keep user_stats.purchased_programs in sync for existing frontend logic
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
