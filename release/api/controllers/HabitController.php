<?php
require_once __DIR__ . '/../services/NotificationService.php';

class HabitController
{
    private $db;
    private $currentUser;
    private $notificationService;

    // Streak milestones that trigger notifications
    private const STREAK_MILESTONES = [7, 14, 21, 30, 60, 90, 100, 180, 365];

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
        $this->notificationService = new NotificationService($db);
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
            if ($payload) {
                // Check for 'data' wrapper (AuthController format) or direct 'id'
                $userId = $payload['data']['id'] ?? $payload['id'] ?? null;
                if ($userId) {
                    return ['id' => $userId, 'email' => $payload['data']['email'] ?? $payload['email'] ?? null];
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    public function handleRequest($action, $userId = null, $habitId = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Public endpoints
        if ($action === 'templates') {
            if ($method === 'GET') {
                $this->getTemplates();
                return;
            }
        }

        // Protected endpoints
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        switch ($action) {
            case 'user':
                // /api/habits/user/{userId}
                if ($method === 'GET') {
                    $this->getUserHabits($userId);
                } elseif ($method === 'POST') {
                    $this->createHabit($userId);
                }
                break;

            case 'log':
                // /api/habits/log
                if ($method === 'POST') {
                    $this->logHabit();
                } elseif ($method === 'DELETE') {
                    $this->unlogHabit();
                }
                break;

            case 'today':
                // /api/habits/today/{userId}?date=2026-01-27
                if ($method === 'GET') {
                    $this->getTodayStatus($userId);
                }
                break;

            case 'stats':
                // /api/habits/stats/{userId}
                if ($method === 'GET') {
                    $this->getHabitStats($userId);
                }
                break;

            case 'history':
                // /api/habits/history/{habitId}?days=30
                if ($method === 'GET') {
                    $this->getHabitHistory($habitId);
                }
                break;

            case 'recommend':
                // /api/habits/recommend/{userId}
                if ($method === 'GET') {
                    $this->getRecommendations($userId);
                }
                break;

            default:
                // /api/habits/{habitId} - specific habit operations
                if ($habitId && $method === 'GET') {
                    $this->getHabit($habitId);
                } elseif ($habitId && $method === 'PUT') {
                    $this->updateHabit($habitId);
                } elseif ($habitId && $method === 'DELETE') {
                    $this->deleteHabit($habitId);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Endpoint not found"]);
                }
        }
    }

    /**
     * Get AI-driven habit recommendations
     * GET /api/habits/recommend/{userId}
     */
    private function getRecommendations($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // 1. Get Cycle Phase
        $phase = 'follicular'; // Default
        try {
            require_once __DIR__ . '/CycleController.php';
            $cycleCtrl = new CycleController($this->db);
            $phase = $cycleCtrl->getPhaseForUser($userId);
        } catch (Exception $e) {
            // Fallback if cycle controller fails
        }

        // 2. Get Active Goal Categories
        $stmt = $this->db->prepare("SELECT DISTINCT category FROM personal_goals WHERE user_id = :uid AND status = 'active'");
        $stmt->execute([':uid' => $userId]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($categories)) {
            $categories = ['wellness']; // Default
        }

        // 3. Recommendation Logic (Rule-based AI)
        $recommendations = [];
        $library = [
            'fitness' => [
                'menstrual' => [
                    ['title' => 'Gentle Yoga', 'description' => '30 mins of restorative poses to ease cramps.', 'icon' => 'fa-spa', 'frequency' => 'daily'],
                    ['title' => 'Light Walk', 'description' => 'A 15-min stroll to boost circulation.', 'icon' => 'fa-person-walking', 'frequency' => 'daily']
                ],
                'follicular' => [
                    ['title' => 'HIIT Workout', 'description' => 'High intensity cardio to utilize rising energy.', 'icon' => 'fa-bolt', 'frequency' => '3x/week'],
                    ['title' => 'Strength Training', 'description' => 'Build muscle while estrogen is high.', 'icon' => 'fa-dumbbell', 'frequency' => '3x/week']
                ],
                'ovulation' => [
                    ['title' => 'Personal Best Attempt', 'description' => 'Go for a PR in your favorite lift.', 'icon' => 'fa-trophy', 'frequency' => 'once'],
                    ['title' => 'Group Class', 'description' => 'Social energy is high, try a spin class.', 'icon' => 'fa-users', 'frequency' => 'weekly']
                ],
                'luteal' => [
                    ['title' => 'Pilates', 'description' => 'Low impact strength maintenance.', 'icon' => 'fa-person-praying', 'frequency' => 'daily'],
                    ['title' => 'Long Walk', 'description' => 'Steady state cardio for mood regulation.', 'icon' => 'fa-shoe-prints', 'frequency' => 'daily']
                ]
            ],
            'wellness' => [
                'menstrual' => [
                    ['title' => 'Magnesium Soak', 'description' => 'Warm bath for muscle relaxation.', 'icon' => 'fa-bath', 'frequency' => 'weekly'],
                    ['title' => 'Sleep Early', 'description' => 'Extra rest is crucial right now.', 'icon' => 'fa-bed', 'frequency' => 'daily']
                ],
                'follicular' => [
                    ['title' => 'Learn New Skill', 'description' => 'Brain fog is gone, learn something new.', 'icon' => 'fa-brain', 'frequency' => 'daily'],
                    ['title' => 'Social Lunch', 'description' => 'Connect with friends.', 'icon' => 'fa-utensils', 'frequency' => 'weekly']
                ],
                'ovulation' => [
                    ['title' => 'Networking', 'description' => 'Your communication skills are peaked.', 'icon' => 'fa-handshake', 'frequency' => 'weekly'],
                    ['title' => 'Creative Project', 'description' => 'Start that big idea now.', 'icon' => 'fa-lightbulb', 'frequency' => 'daily']
                ],
                'luteal' => [
                    ['title' => 'Journaling', 'description' => 'Reflect on the month as you wind down.', 'icon' => 'fa-pen-nib', 'frequency' => 'daily'],
                    ['title' => 'Clean Space', 'description' => 'Nesting instinct kicks in, tidy up.', 'icon' => 'fa-broom', 'frequency' => 'weekly']
                ]
            ],
            'nutrition' => [
                'menstrual' => [
                    ['title' => 'Iron-Rich Dinner', 'description' => 'Replenish iron stores (spinach, red meat).', 'icon' => 'fa-carrot', 'frequency' => 'daily'],
                    ['title' => 'Hydration Goal', 'description' => '2.5L water to help with bloating.', 'icon' => 'fa-glass-water', 'frequency' => 'daily']
                ],
                'follicular' => [
                    ['title' => 'Fermented Foods', 'description' => 'Support gut health.', 'icon' => 'fa-jar', 'frequency' => 'daily'],
                    ['title' => 'Complex Carbs', 'description' => 'Fuel for higher activity levels.', 'icon' => 'fa-bowl-rice', 'frequency' => 'daily']
                ],
                'ovulation' => [
                    ['title' => 'Raw Veggies', 'description' => 'Liver support for hormone processing.', 'icon' => 'fa-leaf', 'frequency' => 'daily']
                ],
                'luteal' => [
                    ['title' => 'Dark Chocolate', 'description' => 'Magnesium boost for cravings.', 'icon' => 'fa-cookie', 'frequency' => 'daily'],
                    ['title' => 'Protein Breakfast', 'description' => 'Stabilize blood sugar early.', 'icon' => 'fa-egg', 'frequency' => 'daily']
                ]
            ]
        ];

        // Collect matches
        foreach ($categories as $cat) {
            // Map custom categories to 'wellness' or keeping simple
            $mappedCat = isset($library[$cat]) ? $cat : 'wellness';

            if (isset($library[$mappedCat][$phase])) {
                foreach ($library[$mappedCat][$phase] as $habit) {
                    $habit['category'] = $mappedCat; // Ensure category is set
                    $recommendations[] = $habit;
                }
            }
        }

        // Add a "Phase Specific" generic recommendation if empty
        if (empty($recommendations)) {
            $recommendations[] = [
                'title' => 'Track Cycle',
                'description' => 'Log your symptoms to get better insights.',
                'icon' => 'fa-calendar',
                'frequency' => 'daily',
                'category' => 'wellness'
            ];
        }

        echo json_encode([
            'phase' => $phase,
            'recommendations' => $recommendations
        ]);
    }

    /**
     * Get all habit templates
     */
    private function getTemplates()
    {
        $category = $_GET['category'] ?? null;
        $query = "SELECT * FROM habit_templates WHERE is_system = 1";
        $params = [];

        if ($category) {
            $query .= " AND category = :category";
            $params[':category'] = $category;
        }

        $query .= " ORDER BY title";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($templates);
    }

    /**
     * Get user's habits
     */
    private function getUserHabits($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM user_habits 
            WHERE user_id = :uid 
            ORDER BY is_active DESC, created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $habits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($habits);
    }

    /**
     * Create a new habit
     * POST /api/habits/user/{userId}
     * Body: { template_id?: string, title: string, category: string, frequency: string }
     */
    private function createHabit($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['title'])) {
            http_response_code(400);
            echo json_encode(["message" => "Title is required"]);
            return;
        }

        $habitId = 'habit_' . uniqid();

        $stmt = $this->db->prepare("
            INSERT INTO user_habits (id, user_id, template_id, title, description, category, icon, frequency)
            VALUES (:id, :uid, :tid, :title, :desc, :category, :icon, :freq)
        ");
        $stmt->execute([
            ':id' => $habitId,
            ':uid' => $userId,
            ':tid' => $data['template_id'] ?? null,
            ':title' => $data['title'],
            ':desc' => $data['description'] ?? null,
            ':category' => $data['category'] ?? 'other',
            ':icon' => $data['icon'] ?? 'fa-check',
            ':freq' => $data['frequency'] ?? 'daily'
        ]);

        echo json_encode([
            "message" => "Habit created successfully",
            "habit_id" => $habitId
        ]);
    }

    /**
     * Log habit completion
     * POST /api/habits/log
     * Body: { habit_id: string, log_date: string, notes?: string }
     */
    private function logHabit()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['habit_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Habit ID required"]);
            return;
        }

        // Verify ownership
        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $data['habit_id']]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Habit not found or access denied"]);
            return;
        }

        $logDate = $data['log_date'] ?? date('Y-m-d');
        $logId = 'log_' . uniqid();

        // Insert log
        $logStmt = $this->db->prepare("
            INSERT OR IGNORE INTO habit_logs (id, user_id, habit_id, log_date, notes)
            VALUES (:id, :uid, :hid, :date, :notes)
        ");
        $logStmt->execute([
            ':id' => $logId,
            ':uid' => $this->currentUser['id'],
            ':hid' => $data['habit_id'],
            ':date' => $logDate,
            ':notes' => $data['notes'] ?? null
        ]);

        // Update streak and total completions
        $this->updateHabitStreaks($data['habit_id']);

        // Award Points (Integration)
        try {
            require_once __DIR__ . '/GamificationController.php';
            $gameCtrl = new GamificationController($this->db);

            // Capture output to prevent multiple JSON responses
            ob_start();
            $gameCtrl->awardPoints(
                $this->currentUser['id'],
                10, // 10 points per habit
                "Completed habit: " . $habit['title'],
                "habit",
                $data['habit_id']
            );
            $gameStats = json_decode(ob_get_clean(), true);
        } catch (Exception $e) {
            // Ignore gamification errors to not block habit logging
        }

        echo json_encode([
            "message" => "Habit logged successfully",
            "points_awarded" => 10,
            "gamification" => $gameStats ?? null
        ]);
    }

    /**
     * Unlog habit (remove completion)
     * DELETE /api/habits/log
     * Body: { habit_id: string, log_date: string }
     */
    private function unlogHabit()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $data['habit_id']]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            return;
        }

        $deleteStmt = $this->db->prepare("
            DELETE FROM habit_logs 
            WHERE habit_id = :hid AND log_date = :date
        ");
        $deleteStmt->execute([
            ':hid' => $data['habit_id'],
            ':date' => $data['log_date'] ?? date('Y-m-d')
        ]);

        // Recalculate streaks
        $this->updateHabitStreaks($data['habit_id']);

        echo json_encode(["message" => "Habit unlogged"]);
    }

    /**
     * Get today's habit completion status
     * GET /api/habits/today/{userId}?date=2026-01-27
     */
    private function getTodayStatus($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $date = $_GET['date'] ?? date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT h.*, 
                   l.id as log_id,
                   l.notes as log_notes,
                   (l.id IS NOT NULL) as completed_today
            FROM user_habits h
            LEFT JOIN habit_logs l ON h.id = l.habit_id AND l.log_date = :date
            WHERE h.user_id = :uid AND h.is_active = 1
            ORDER BY completed_today ASC, h.created_at ASC
        ");
        $stmt->execute([':uid' => $userId, ':date' => $date]);
        $habits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert completed_today to boolean
        foreach ($habits as &$habit) {
            $habit['completed_today'] = (bool) $habit['completed_today'];
        }

        echo json_encode($habits);
    }

    /**
     * Get overall habit stats for user
     */
    private function getHabitStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Total active habits
        $activeStmt = $this->db->prepare("SELECT COUNT(*) as count FROM user_habits WHERE user_id = :uid AND is_active = 1");
        $activeStmt->execute([':uid' => $userId]);
        $activeCount = $activeStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Total completions
        $totalStmt = $this->db->prepare("SELECT SUM(total_completions) as total FROM user_habits WHERE user_id = :uid");
        $totalStmt->execute([':uid' => $userId]);
        $totalCompletions = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Best streak
        $streakStmt = $this->db->prepare("SELECT MAX(longest_streak) as max FROM user_habits WHERE user_id = :uid");
        $streakStmt->execute([':uid' => $userId]);
        $bestStreak = $streakStmt->fetch(PDO::FETCH_ASSOC)['max'] ?? 0;

        // Today's completion rate
        $todayDate = date('Y-m-d');
        $todayStmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_habits,
                SUM(CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END) as completed_habits
            FROM user_habits h
            LEFT JOIN habit_logs l ON h.id = l.habit_id AND l.log_date = :date
            WHERE h.user_id = :uid AND h.is_active = 1
        ");
        $todayStmt->execute([':uid' => $userId, ':date' => $todayDate]);
        $todayData = $todayStmt->fetch(PDO::FETCH_ASSOC);
        $completionRate = $todayData['total_habits'] > 0
            ? round(($todayData['completed_habits'] / $todayData['total_habits']) * 100)
            : 0;

        echo json_encode([
            'active_habits' => $activeCount,
            'total_completions' => $totalCompletions,
            'best_streak' => $bestStreak,
            'today_completion_rate' => $completionRate,
            'today_completed' => $todayData['completed_habits'],
            'today_total' => $todayData['total_habits']
        ]);
    }

    /**
     * Get habit completion history
     * GET /api/habits/history/{habitId}?days=30
     */
    private function getHabitHistory($habitId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $habitId]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            return;
        }

        $days = $_GET['days'] ?? 30;

        $histStmt = $this->db->prepare("
            SELECT log_date, completed, notes 
            FROM habit_logs 
            WHERE habit_id = :hid 
            AND log_date >= DATE('now', '-' || :days || ' days')
            ORDER BY log_date DESC
        ");
        $histStmt->execute([':hid' => $habitId, ':days' => $days]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($history);
    }

    /**
     * Get single habit
     */
    private function getHabit($habitId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $habitId]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(404);
            echo json_encode(["message" => "Habit not found"]);
            return;
        }

        echo json_encode($habit);
    }

    /**
     * Update habit
     */
    private function updateHabit($habitId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $habitId]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $updates = [];
        $params = [':id' => $habitId];

        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = :active";
            $params[':active'] = $data['is_active'] ? 1 : 0;
        }
        if (isset($data['category'])) {
            $updates[] = "category = :category";
            $params[':category'] = $data['category'];
        }

        if (!empty($updates)) {
            $query = "UPDATE user_habits SET " . implode(', ', $updates) . " WHERE id = :id";
            $updateStmt = $this->db->prepare($query);
            $updateStmt->execute($params);
        }

        echo json_encode(["message" => "Habit updated"]);
    }

    /**
     * Delete habit
     */
    private function deleteHabit($habitId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $stmt->execute([':id' => $habitId]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit || $habit['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            return;
        }

        $deleteStmt = $this->db->prepare("DELETE FROM user_habits WHERE id = :id");
        $deleteStmt->execute([':id' => $habitId]);

        echo json_encode(["message" => "Habit deleted"]);
    }

    /**
     * Update habit streaks and total completions
     */
    private function updateHabitStreaks($habitId)
    {
        // Get habit info including previous streak
        $habitStmt = $this->db->prepare("SELECT * FROM user_habits WHERE id = :id");
        $habitStmt->execute([':id' => $habitId]);
        $habit = $habitStmt->fetch(PDO::FETCH_ASSOC);

        if (!$habit)
            return;

        $previousStreak = (int) $habit['current_streak'];
        $previousLongest = (int) $habit['longest_streak'];

        // Get all logs ordered by date
        $stmt = $this->db->prepare("
            SELECT log_date FROM habit_logs
            WHERE habit_id = :hid
            ORDER BY log_date DESC
        ");
        $stmt->execute([':hid' => $habitId]);
        $logs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $totalCompletions = count($logs);
        $currentStreak = 0;
        $longestStreak = 0;
        $tempStreak = 0;

        // Calculate current streak (from today backwards)
        $expectedDate = new DateTime();
        foreach ($logs as $logDate) {
            if ($logDate === $expectedDate->format('Y-m-d')) {
                $currentStreak++;
                $expectedDate->modify('-1 day');
            } else {
                break;
            }
        }

        // Calculate longest streak
        if (count($logs) > 0) {
            $tempStreak = 1;
            $longestStreak = 1;

            for ($i = 0; $i < count($logs) - 1; $i++) {
                $current = new DateTime($logs[$i]);
                $next = new DateTime($logs[$i + 1]);
                $diff = $current->diff($next)->days;

                if ($diff === 1) {
                    $tempStreak++;
                    $longestStreak = max($longestStreak, $tempStreak);
                } else {
                    $tempStreak = 1;
                }
            }
        }

        // Update habit
        $updateStmt = $this->db->prepare("
            UPDATE user_habits
            SET current_streak = :current, longest_streak = :longest, total_completions = :total
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':current' => $currentStreak,
            ':longest' => $longestStreak,
            ':total' => $totalCompletions,
            ':id' => $habitId
        ]);

        // Check for streak milestone notifications
        $this->checkStreakMilestones($habit['user_id'], $habit['title'], $previousStreak, $currentStreak, $previousLongest, $longestStreak);
    }

    /**
     * Check and send streak milestone notifications
     */
    private function checkStreakMilestones($userId, $habitTitle, $previousStreak, $currentStreak, $previousLongest, $longestStreak)
    {
        // Check if we hit a new milestone
        foreach (self::STREAK_MILESTONES as $milestone) {
            // Current streak crossed a milestone
            if ($currentStreak >= $milestone && $previousStreak < $milestone) {
                $emoji = $this->getStreakEmoji($milestone);
                $this->notificationService->create(
                    $userId,
                    'success',
                    "{$emoji} {$milestone}-Day Streak!",
                    "Amazing! You've maintained '$habitTitle' for $milestone days straight. Keep it up!",
                    '/habits'
                );
                break; // Only one notification per log
            }
        }

        // Check for new personal best (longest streak)
        if ($longestStreak > $previousLongest && $longestStreak > 1) {
            // Only notify for significant improvements (not every day)
            $significantMilestones = [7, 14, 30, 60, 90];
            foreach ($significantMilestones as $milestone) {
                if ($longestStreak >= $milestone && $previousLongest < $milestone) {
                    $this->notificationService->create(
                        $userId,
                        'success',
                        'New Personal Best!',
                        "You've set a new record! Your longest streak for '$habitTitle' is now $longestStreak days.",
                        '/habits'
                    );
                    break;
                }
            }
        }
    }

    /**
     * Get emoji for streak milestone
     */
    private function getStreakEmoji($days): string
    {
        if ($days >= 365)
            return 'Trophy';
        if ($days >= 100)
            return 'Star';
        if ($days >= 60)
            return 'Medal';
        if ($days >= 30)
            return 'Fire';
        if ($days >= 14)
            return 'Spark';
        return 'Check';
    }
}
?>