<?php
require_once __DIR__ . '/../services/NotificationService.php';

class GamificationController
{
    private $db;
    private $currentUser;
    private $notificationService;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
        $this->notificationService = new NotificationService($db);
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
            if ($payload && isset($payload['id'])) {
                return $payload;
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    public function handleRequest($action, $userId = null)
    {
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        switch ($action) {
            case 'points':
                // GET /api/gamification/user/{userId}/points
                if ($method === 'GET' && $userId) {
                    $this->getUserPoints($userId);
                }
                break;

            case 'achievements':
                // GET /api/gamification/achievements
                // GET /api/gamification/user/{userId}/achievements
                if ($method === 'GET') {
                    if ($userId) {
                        $this->getUserAchievements($userId);
                    } else {
                        $this->getAllAchievements();
                    }
                }
                break;

            case 'award':
                // POST /api/gamification/award/{userId}
                if ($method === 'POST' && $userId) {
                    $this->awardPoints($userId);
                }
                break;

            case 'leaderboard':
                // GET /api/gamification/leaderboard
                if ($method === 'GET') {
                    $this->getLeaderboard();
                }
                break;

            case 'history':
                // GET /api/gamification/user/{userId}/history
                if ($method === 'GET' && $userId) {
                    $this->getPointsHistory($userId);
                }
                break;

            case 'summary':
                // GET /api/gamification/summary/{userId}
                if ($method === 'GET' && $userId) {
                    $this->getSummary($userId);
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    /**
     * Get summary including rank
     * GET /api/gamification/summary/{userId}
     */
    private function getSummary($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Get points
        $stmt = $this->db->prepare("SELECT points as xp, level, total_points_earned FROM user_points WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            $data = ['xp' => 0, 'level' => 1, 'total_points_earned' => 0];
        }

        // Calculate Rank (Count users with higher points)
        $rankStmt = $this->db->prepare("SELECT COUNT(*) + 1 FROM user_points WHERE total_points_earned > :pts");
        $rankStmt->execute([':pts' => $data['total_points_earned']]);
        $rank = $rankStmt->fetchColumn();

        echo json_encode([
            'xp' => $data['xp'],
            'level' => $data['level'],
            'total_points' => $data['total_points_earned'],
            'rank' => $rank
        ]);
    }

    /**
     * Get user points and level
     * GET /api/gamification/user/{userId}/points
     */
    private function getUserPoints($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM user_points WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            // Initialize if not exists
            $this->initializeUserPoints($userId);
            $stmt->execute([':uid' => $userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode($data);
    }

    /**
     * Initialize user points record
     */
    private function initializeUserPoints($userId)
    {
        $id = 'pts_' . uniqid();
        $stmt = $this->db->prepare("
            INSERT INTO user_points (id, user_id, points, level, points_to_next_level)
            VALUES (?, ?, 0, 1, 100)
        ");
        $stmt->execute([$id, $userId]);
    }

    /**
     * Get all system achievements
     * GET /api/gamification/achievements
     */
    private function getAllAchievements()
    {
        $stmt = $this->db->query("SELECT * FROM achievements ORDER BY points ASC");
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($achievements);
    }

    /**
     * Get user achievements (both earned and unearned)
     * GET /api/gamification/user/{userId}/achievements
     */
    private function getUserAchievements($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Get all achievements left join user progress
        $query = "
            SELECT a.*, 
                   ua.earned_at, 
                   ua.progress, 
                   ua.is_viewed,
                   CASE WHEN ua.earned_at IS NOT NULL THEN 1 ELSE 0 END as is_earned
            FROM achievements a
            LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = :uid
            ORDER BY is_earned DESC, a.points ASC
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':uid' => $userId]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($achievements);
    }

    /**
     * Award points to user
     * POST /api/gamification/award/{userId}
     * Or internal call with params
     */
    public function awardPoints($userId, $points = null, $reason = null, $sourceType = null, $sourceId = null)
    {
        // If called via API (no args), read from input
        if ($points === null) {
            $data = json_decode(file_get_contents("php://input"), true);
            $points = $data['points'] ?? 0;
            $reason = $data['reason'] ?? 'Action completed';
            $sourceType = $data['source_type'] ?? 'manual';
            $sourceId = $data['source_id'] ?? null;
        }

        // This endpoint should ideally be protected by admin or server-side logic
        // For now allowing it for integration, but basic auth check is there        

        if ($points <= 0) {
            echo json_encode(["message" => "No points to award"]);
            return;
        }

        // 1. Log history
        $histId = 'hist_' . uniqid();
        $histStmt = $this->db->prepare("
            INSERT INTO points_history (id, user_id, points, reason, source_type, source_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $histStmt->execute([$histId, $userId, $points, $reason, $sourceType, $sourceId]);

        // 2. Update user points
        $stmt = $this->db->prepare("SELECT * FROM user_points WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $userPoints = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userPoints) {
            $this->initializeUserPoints($userId);
            $stmt->execute([':uid' => $userId]);
            $userPoints = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $newPoints = $userPoints['points'] + $points;
        $totalPoints = $userPoints['total_points_earned'] + $points;
        $level = $userPoints['level'];
        $needed = $userPoints['points_to_next_level'];

        $leveledUp = false;
        // Simple level up logic: needs 100 * level points
        while ($newPoints >= $needed) {
            $newPoints -= $needed;
            $level++;
            $needed = 100 * $level; // scaling difficulty
            $leveledUp = true;
        }

        $updateStmt = $this->db->prepare("
            UPDATE user_points 
            SET points = :p, level = :l, points_to_next_level = :n, 
                total_points_earned = :t, last_updated = CURRENT_TIMESTAMP
            WHERE user_id = :uid
        ");
        $updateStmt->execute([
            ':p' => $newPoints,
            ':l' => $level,
            ':n' => $needed,
            ':t' => $totalPoints,
            ':uid' => $userId
        ]);

        // Check for achievements based on total points
        $this->checkAndAwardAchievements($userId, $totalPoints, $level);

        // Send level up notification
        if ($leveledUp) {
            $this->notificationService->create(
                $userId,
                'success',
                'Level Up!',
                "Congratulations! You've reached Level $level!",
                '/gamification'
            );
        }

        echo json_encode([
            "message" => "Points awarded",
            "leveled_up" => $leveledUp,
            "new_level" => $level,
            "current_points" => $newPoints
        ]);
    }

    /**
     * Check and award achievements based on user progress
     */
    private function checkAndAwardAchievements($userId, $totalPoints, $level)
    {
        // Get all unearned achievements for this user
        $stmt = $this->db->prepare("
            SELECT a.* FROM achievements a
            WHERE a.id NOT IN (
                SELECT achievement_id FROM user_achievements WHERE user_id = ? AND earned_at IS NOT NULL
            )
        ");
        $stmt->execute([$userId]);
        $unearned = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unearned as $achievement) {
            $earned = false;

            // Check condition based on type
            switch ($achievement['condition_type']) {
                case 'total_points':
                    $earned = $totalPoints >= $achievement['condition_value'];
                    break;
                case 'level':
                    $earned = $level >= $achievement['condition_value'];
                    break;
                case 'streak_days':
                    // Check user's max streak
                    $stmtStreak = $this->db->prepare("SELECT MAX(longest_streak) FROM user_habits WHERE user_id = ?");
                    $stmtStreak->execute([$userId]);
                    $maxStreak = (int) $stmtStreak->fetchColumn();
                    $earned = $maxStreak >= $achievement['condition_value'];
                    break;
                case 'habits_completed':
                    $stmtHabits = $this->db->prepare("SELECT COUNT(*) FROM habit_logs WHERE user_id = ? AND completed = 1");
                    $stmtHabits->execute([$userId]);
                    $habitCount = (int) $stmtHabits->fetchColumn();
                    $earned = $habitCount >= $achievement['condition_value'];
                    break;
                case 'journal_entries':
                    $stmtJournal = $this->db->prepare("SELECT COUNT(*) FROM journal_entries WHERE user_id = ?");
                    $stmtJournal->execute([$userId]);
                    $journalCount = (int) $stmtJournal->fetchColumn();
                    $earned = $journalCount >= $achievement['condition_value'];
                    break;
                case 'goals_completed':
                    $stmtGoals = $this->db->prepare("SELECT COUNT(*) FROM personal_goals WHERE user_id = ? AND status = 'completed'");
                    $stmtGoals->execute([$userId]);
                    $goalsCount = (int) $stmtGoals->fetchColumn();
                    $earned = $goalsCount >= $achievement['condition_value'];
                    break;
            }

            if ($earned) {
                $this->awardAchievement($userId, $achievement);
            }
        }
    }

    /**
     * Award an achievement to user and send notification
     */
    private function awardAchievement($userId, $achievement)
    {
        // Insert achievement record
        $uaId = 'ua_' . uniqid();
        $stmt = $this->db->prepare("
            INSERT INTO user_achievements (id, user_id, achievement_id, progress, earned_at)
            VALUES (?, ?, ?, 100, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$uaId, $userId, $achievement['id']]);

        // Award bonus points if any
        if ($achievement['points'] > 0) {
            $histId = 'hist_' . uniqid();
            $this->db->prepare("
                INSERT INTO points_history (id, user_id, points, reason, source_type, source_id)
                VALUES (?, ?, ?, ?, 'achievement', ?)
            ")->execute([$histId, $userId, $achievement['points'], "Achievement: {$achievement['title']}", $achievement['id']]);

            // Update user points
            $this->db->prepare("
                UPDATE user_points
                SET points = points + ?, total_points_earned = total_points_earned + ?
                WHERE user_id = ?
            ")->execute([$achievement['points'], $achievement['points'], $userId]);
        }

        // Send notification
        $this->notificationService->create(
            $userId,
            'success',
            'Achievement Unlocked!',
            "You earned: {$achievement['title']} - {$achievement['description']}",
            '/gamification'
        );
    }

    /**
     * Get points leaderboard
     * GET /api/gamification/leaderboard
     */
    private function getLeaderboard()
    {
        $limit = $_GET['limit'] ?? 10;

        $query = "
            SELECT u.id, u.name, up.level, up.total_points_earned as score
            FROM user_points up
            JOIN users u ON up.user_id = u.id
            ORDER BY up.total_points_earned DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($leaderboard);
    }

    /**
     * Get points history
     * GET /api/gamification/user/{userId}/history
     */
    private function getPointsHistory($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM points_history 
            WHERE user_id = :uid 
            ORDER BY earned_at DESC 
            LIMIT 50
        ");
        $stmt->execute([':uid' => $userId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($history);
    }
}
?>