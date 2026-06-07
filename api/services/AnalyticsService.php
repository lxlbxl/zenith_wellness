<?php
/**
 * AnalyticsService — User behavior tracking, retention metrics, and business intelligence.
 * Phase 4.2: Analytics & Tracking
 */

class AnalyticsService
{
    private $db;

    // Common event types
    public const EVENTS = [
        'page_view' => 'Page View',
        'login' => 'Login',
        'registration' => 'Registration',
        'cohort_enroll' => 'Cohort Enrollment',
        'cohort_complete' => 'Cohort Completion',
        'habit_create' => 'Habit Created',
        'habit_complete' => 'Habit Completed',
        'meal_logged' => 'Meal Logged',
        'mood_logged' => 'Mood Logged',
        'ai_message' => 'AI Message',
        'payment_initiated' => 'Payment Initiated',
        'payment_completed' => 'Payment Completed',
        'subscription_change' => 'Subscription Changed',
        'profile_update' => 'Profile Updated',
        'referral_share' => 'Referral Shared',
        'achievement_unlocked' => 'Achievement Unlocked',
        'feature_used' => 'Feature Used',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Track an event.
     */
    public function trackEvent(string $userId, string $eventType, array $properties = []): void
    {
        if (!isset(self::EVENTS[$eventType]) && $eventType !== 'custom') {
            error_log("[Analytics] Unknown event: $eventType");
        }

        $id = bin2hex(random_bytes(16));
        $propertiesJson = json_encode($properties);

        $stmt = $this->db->prepare(
            "INSERT INTO analytics_events (id, user_id, event_type, properties, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$id, $userId, $eventType, $propertiesJson]);
    }

    /**
     * Track a page view.
     */
    public function trackPageView(string $userId, string $page, ?string $referrer = null, ?string $userAgent = null): void
    {
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            "INSERT INTO page_views (id, user_id, page_path, referrer, user_agent, viewed_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$id, $userId, $page, $referrer, $userAgent]);
    }

    // ─── User Dashboards ───────────────────────────────

    /**
     * Get personal analytics for the user's dashboard.
     */
    public function getUserDashboard(string $userId): array
    {
        return [
            'streaks' => $this->getUserStreaks($userId),
            'weekly_activity' => $this->getWeeklyActivity($userId),
            'habit_completion_rate' => $this->getHabitCompletionRate($userId),
            'top_habits' => $this->getTopHabits($userId),
            'mood_trend' => $this->getMoodTrend($userId, 30),
            'total_points' => $this->getTotalPoints($userId),
            'cohorts_completed' => $this->getCohortsCompleted($userId),
            'daily_average' => $this->getDailyAverage($userId),
            'current_streak' => $this->getCurrentStreak($userId),
            'longest_streak' => $this->getLongestStreak($userId),
        ];
    }

    private function getUserStreaks(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT DATE(created_at)) as active_days
             FROM analytics_events 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([$userId]);
        return ['active_days_30' => (int) $stmt->fetchColumn()];
    }

    private function getWeeklyActivity(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as event_count
             FROM analytics_events 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getHabitCompletionRate(string $userId): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(CASE WHEN event_type = 'habit_complete' THEN 1 ELSE 0 END), 0) as completed,
                COALESCE(SUM(CASE WHEN event_type = 'habit_create' THEN 1 ELSE 0 END), 0) as created
             FROM analytics_events 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['created'] > 0 ? round($row['completed'] / $row['created'] * 100, 1) : 0;
    }

    private function getTopHabits(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT JSON_EXTRACT(properties, '$.habit_name') as habit_name,
                    COUNT(*) as completion_count
             FROM analytics_events 
             WHERE user_id = ? AND event_type = 'habit_complete'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY habit_name
             ORDER BY completion_count DESC
             LIMIT 5"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getMoodTrend(string $userId, int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date,
                    AVG(CAST(JSON_EXTRACT(properties, '$.mood_score') AS UNSIGNED)) as avg_mood
             FROM analytics_events 
             WHERE user_id = ? AND event_type = 'mood_logged'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$userId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTotalPoints(string $userId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(total_points, 0) FROM user_points WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function getCohortsCompleted(string $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cohort_completions WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function getDailyAverage(string $userId): float
    {
        $stmt = $this->db->prepare(
            "SELECT ROUND(COUNT(*) / 30, 1)
             FROM analytics_events 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([$userId]);
        return (float) $stmt->fetchColumn();
    }

    private function getCurrentStreak(string $userId): int
    {
        // Calculate current consecutive days with activity
        $stmt = $this->db->prepare(
            "SELECT DISTINCT DATE(created_at) as activity_date
             FROM analytics_events 
             WHERE user_id = ?
             ORDER BY activity_date DESC
             LIMIT 100"
        );
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($dates))
            return 0;

        $streak = 1;
        $today = new DateTime();
        $lastDate = new DateTime($dates[0]);

        // Check if active today or yesterday to continue streak
        if ($today->diff($lastDate)->days > 1)
            return 0;

        for ($i = 1; $i < count($dates); $i++) {
            $prevDate = new DateTime($dates[$i]);
            if ($lastDate->diff($prevDate)->days === 1) {
                $streak++;
                $lastDate = $prevDate;
            } else {
                break;
            }
        }

        return $streak;
    }

    private function getLongestStreak(string $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT DATE(created_at) as activity_date
             FROM analytics_events 
             WHERE user_id = ?
             ORDER BY activity_date ASC"
        );
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($dates))
            return 0;

        $longest = 1;
        $current = 1;
        $lastDate = new DateTime($dates[0]);

        for ($i = 1; $i < count($dates); $i++) {
            $currDate = new DateTime($dates[$i]);
            if ($lastDate->diff($currDate)->days === 1) {
                $current++;
                $longest = max($longest, $current);
            } else {
                $current = 1;
            }
            $lastDate = $currDate;
        }

        return $longest;
    }

    // ─── Business Intelligence Metrics ──────────────────

    /**
     * Get overall platform metrics (admin dashboard).
     */
    public function getPlatformMetrics(): array
    {
        return [
            'total_users' => $this->countTable('users'),
            'active_users_today' => $this->getActiveUsersToday(),
            'active_users_7d' => $this->getActiveUsers(7),
            'active_users_30d' => $this->getActiveUsers(30),
            'total_cohort_completions' => $this->countTable('cohort_completions'),
            'total_habits_logged' => $this->countEvents('habit_complete'),
            'total_meals_logged' => $this->countEvents('meal_logged'),
            'total_moods_logged' => $this->countEvents('mood_logged'),
            'total_ai_messages' => $this->countEvents('ai_message'),
            'registration_trend' => $this->getRegistrationTrend(30),
            'revenue' => $this->getRevenueMetrics(),
            'retention_rate' => $this->getRetentionRate(),
            'conversion_rate' => $this->getConversionRate(),
            'active_subscriptions' => $this->getActiveSubscriptions(),
            'top_features' => $this->getTopFeatures(10),
            'daily_active_users_trend' => $this->getDAUTrend(30),
        ];
    }

    private function countTable(string $table): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM $table");
        return (int) $stmt->fetchColumn();
    }

    private function getActiveUsersToday(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE DATE(created_at) = CURDATE()"
        );
        return (int) $stmt->fetchColumn();
    }

    private function getActiveUsers(int $days): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM analytics_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    }

    private function countEvents(string $eventType): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM analytics_events WHERE event_type = ?"
        );
        $stmt->execute([$eventType]);
        return (int) $stmt->fetchColumn();
    }

    private function getRegistrationTrend(int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as registrations
             FROM users 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getRevenueMetrics(): array
    {
        $stmt = $this->db->query(
            "SELECT 
                COALESCE(SUM(amount), 0) as total_revenue,
                COUNT(*) as total_payments,
                COALESCE(AVG(amount), 0) as avg_payment
             FROM payments WHERE status = 'completed'"
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getRetentionRate(): float
    {
        // Day-30 retention: users who were active 30 days after registration
        $stmt = $this->db->query(
            "SELECT 
                COUNT(DISTINCT u.id) as total_users,
                COUNT(DISTINCT CASE WHEN ae.created_at >= DATE_ADD(u.created_at, INTERVAL 30 DAY) THEN u.id END) as retained
             FROM users u
             LEFT JOIN analytics_events ae ON ae.user_id = u.id
             WHERE u.created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total_users'] > 0
            ? round($row['retained'] / $row['total_users'] * 100, 1)
            : 0;
    }

    private function getConversionRate(): float
    {
        // Free/trial to paid conversion
        $stmt = $this->db->query(
            "SELECT 
                COUNT(DISTINCT CASE WHEN status IN ('active', 'trialing') THEN user_id END) as total_trials,
                COUNT(DISTINCT CASE WHEN status = 'active' AND plan != 'free' THEN user_id END) as paid
             FROM subscriptions"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total_trials'] > 0
            ? round($row['paid'] / $row['total_trials'] * 100, 1)
            : 0;
    }

    private function getActiveSubscriptions(): array
    {
        $stmt = $this->db->query(
            "SELECT plan, COUNT(*) as count
             FROM subscriptions 
             WHERE status IN ('active', 'trialing') AND ends_at > NOW()
             GROUP BY plan
             ORDER BY count DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTopFeatures(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT event_type, COUNT(*) as usage_count
             FROM analytics_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY event_type
             ORDER BY usage_count DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getDAUTrend(int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(DISTINCT user_id) as dau
             FROM analytics_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Retention Cohorts ──────────────────────────────

    public function getWeeklyRetention(): array
    {
        // Week-by-week retention analysis
        $stmt = $this->db->query(
            "SELECT 
                YEARWEEK(created_at, 1) as cohort_week,
                COUNT(*) as new_users,
                COUNT(DISTINCT CASE WHEN last_login >= DATE_ADD(created_at, INTERVAL 1 WEEK) THEN id END) as week1_retained,
                COUNT(DISTINCT CASE WHEN last_login >= DATE_ADD(created_at, INTERVAL 4 WEEK) THEN id END) as week4_retained
             FROM users
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
             GROUP BY cohort_week
             ORDER BY cohort_week ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Export ─────────────────────────────────────────

    public function exportMetrics(string $metric, int $days = 30): array
    {
        return match ($metric) {
            'users' => $this->getRegistrationTrend($days),
            'dau' => $this->getDAUTrend($days),
            'revenue' => $this->getRevenueByDay($days),
            'features' => $this->getTopFeatures(20),
            default => throw new \InvalidArgumentException("Unknown metric: $metric"),
        };
    }

    private function getRevenueByDay(int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, SUM(amount) as daily_revenue, COUNT(*) as payments
             FROM payments 
             WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}