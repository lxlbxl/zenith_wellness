<?php
/**
 * Cohort Completion Service
 * 
 * Handles post-cohort experience: completion certificates, 
 * summary emails, alumni status, and renewal offers.
 */

require_once __DIR__ . '/../config/Database.php';

class CohortCompletionService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Process completed cohorts and send completion emails
     * Called by cron job after cohort end dates
     */
    public function processCompletedCohorts()
    {
        // Find enrollments that just expired (within last 24 hours) and haven't been processed
        $stmt = $this->db->prepare("
            SELECT 
                e.id as enrollment_id,
                e.user_id,
                e.cohort_id,
                e.access_starts_at,
                e.access_expires_at,
                u.name,
                u.email,
                c.title as cohort_name,
                c.duration_days
            FROM cohort_enrollments e
            JOIN users u ON e.user_id = u.id
            JOIN cohorts c ON e.cohort_id = c.id
            WHERE e.status = 'active'
            AND e.access_expires_at < CURRENT_TIMESTAMP
            AND e.access_expires_at > datetime('now', '-24 hours')
            AND NOT EXISTS (
                SELECT 1 FROM cohort_completions 
                WHERE enrollment_id = e.id
            )
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark enrollment as completed and update to alumni status
     */
    public function markCompleted($enrollmentId, $userId, $cohortId)
    {
        // Update enrollment status to 'completed'
        $stmt = $this->db->prepare("
            UPDATE cohort_enrollments 
            SET status = 'completed' 
            WHERE id = ?
        ");
        $stmt->execute([$enrollmentId]);

        // Get user stats during cohort
        $stats = $this->getCohortStats($userId, $cohortId);

        // Record completion
        $completionId = uniqid('comp_');
        $stmt = $this->db->prepare("
            INSERT INTO cohort_completions (
                id, enrollment_id, user_id, cohort_id, 
                completion_stats, completed_at
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $completionId,
            $enrollmentId,
            $userId,
            $cohortId,
            json_encode($stats)
        ]);

        return [
            'completion_id' => $completionId,
            'stats' => $stats
        ];
    }

    /**
     * Get user's accumulated stats during cohort
     */
    private function getCohortStats($userId, $cohortId)
    {
        // Get enrollment dates
        $stmt = $this->db->prepare("
            SELECT access_starts_at, access_expires_at 
            FROM cohort_enrollments 
            WHERE user_id = ? AND cohort_id = ?
        ");
        $stmt->execute([$userId, $cohortId]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enrollment) {
            return [];
        }

        $startDate = $enrollment['access_starts_at'];
        $endDate = $enrollment['access_expires_at'];

        // Journal entries
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM journal_entries 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC);

        // Habits completed
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM habit_logs 
            WHERE user_id = ? 
            AND logged_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $habits = $stmt->fetch(PDO::FETCH_ASSOC);

        // Workouts logged
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM workout_logs 
            WHERE user_id = ? 
            AND logged_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $workouts = $stmt->fetch(PDO::FETCH_ASSOC);

        // Meals logged
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count, 
                   COALESCE(AVG(calories), 0) as avg_calories,
                   COALESCE(AVG(protein), 0) as avg_protein
            FROM meal_logs 
            WHERE user_id = ? 
            AND logged_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $meals = $stmt->fetch(PDO::FETCH_ASSOC);

        // Points earned
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(points), 0) as total 
            FROM points_history 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $points = $stmt->fetch(PDO::FETCH_ASSOC);

        // Achievements earned
        $stmt = $this->db->prepare("
            SELECT a.name, a.icon FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ? 
            AND ua.earned_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Final streak
        $stmt = $this->db->prepare("SELECT streak FROM user_stats WHERE user_id = ?");
        $stmt->execute([$userId]);
        $streak = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'journal_entries' => $journal['count'] ?? 0,
            'habits_completed' => $habits['count'] ?? 0,
            'workouts_logged' => $workouts['count'] ?? 0,
            'meals_logged' => $meals['count'] ?? 0,
            'avg_calories' => round($meals['avg_calories'] ?? 0),
            'avg_protein' => round($meals['avg_protein'] ?? 0),
            'points_earned' => $points['total'] ?? 0,
            'achievements' => $achievements,
            'final_streak' => $streak['streak'] ?? 0
        ];
    }

    /**
     * Generate certificate data (can be used by frontend to render PDF)
     */
    public function getCertificateData($completionId)
    {
        $stmt = $this->db->prepare("
            SELECT 
                cc.*,
                u.name as user_name,
                c.title as cohort_name,
                c.duration_days,
                e.access_starts_at,
                e.access_expires_at
            FROM cohort_completions cc
            JOIN users u ON cc.user_id = u.id
            JOIN cohorts c ON cc.cohort_id = c.id
            JOIN cohort_enrollments e ON cc.enrollment_id = e.id
            WHERE cc.id = ?
        ");
        $stmt->execute([$completionId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data)
            return null;

        $stats = json_decode($data['completion_stats'], true) ?? [];

        return [
            'certificate_id' => $completionId,
            'user_name' => $data['user_name'],
            'cohort_name' => $data['cohort_name'],
            'duration_days' => $data['duration_days'],
            'start_date' => date('F j, Y', strtotime($data['access_starts_at'])),
            'end_date' => date('F j, Y', strtotime($data['access_expires_at'])),
            'completion_date' => date('F j, Y', strtotime($data['completed_at'])),
            'stats' => $stats,
            'verification_code' => strtoupper(substr($completionId, 5, 8))
        ];
    }

    /**
     * Check if user has valid enrollment (including grace period)
     */
    public function hasAccessWithGrace($userId, $cohortId, $graceDays = 2)
    {
        $stmt = $this->db->prepare("
            SELECT id FROM cohort_enrollments 
            WHERE user_id = ? 
            AND cohort_id = ?
            AND status IN ('active', 'completed')
            AND access_expires_at > datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$userId, $cohortId, $graceDays]);
        return (bool) $stmt->fetch();
    }

    /**
     * Get renewal offer for user
     */
    public function getRenewalOffer($userId, $cohortId)
    {
        // Get next available cohort in same category or general upgrade
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   CASE WHEN cc.id IS NOT NULL THEN 1 ELSE 0 END as previously_enrolled
            FROM cohorts c
            LEFT JOIN cohort_enrollments cc ON cc.cohort_id = c.id AND cc.user_id = ?
            WHERE c.status = 'open'
            AND c.start_date > CURRENT_TIMESTAMP
            ORDER BY c.start_date ASC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $nextCohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Apply alumni discount (15% off)
        $offers = [];
        foreach ($nextCohorts as $cohort) {
            $originalPrice = $cohort['price'] ?? 8900;
            $discountedPrice = round($originalPrice * 0.85);

            $offers[] = [
                'cohort_id' => $cohort['id'],
                'title' => $cohort['title'],
                'start_date' => $cohort['start_date'],
                'original_price' => $originalPrice,
                'alumni_price' => $discountedPrice,
                'discount_percent' => 15,
                'previously_enrolled' => $cohort['previously_enrolled']
            ];
        }

        return $offers;
    }
}
