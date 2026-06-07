<?php
class LeaderboardController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            if ($action === 'rank') {
                $this->getUserRank();
            } elseif ($action === 'cohort-pulse') {
                $this->getCohortPulse();
            } else {
                $this->getLeaderboard();
            }
        } else {
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed"]);
        }
    }

    private function getLeaderboard()
    {
        // Get top users by performance metrics
        $query = "SELECT 
            u.id, u.name, u.persona,
            COALESCE(s.focus_minutes, 0) as focus_minutes,
            COALESCE(s.daily_streak, 0) as daily_streak,
            COALESCE(s.completed_tasks, 0) as completed_tasks,
            (COALESCE(s.focus_minutes, 0) * 0.4 + COALESCE(s.daily_streak, 0) * 10 + COALESCE(s.completed_tasks, 0) * 5) as score
        FROM users u
        LEFT JOIN user_stats s ON u.id = s.user_id
        WHERE u.role != 'banned'
        ORDER BY score DESC
        LIMIT 20";

        $stmt = $this->db->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add rank
        $ranked = [];
        foreach ($results as $index => $user) {
            $user['rank'] = $index + 1;
            $ranked[] = $user;
        }

        echo json_encode([
            "leaderboard" => $ranked,
            "total_users" => $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn()
        ]);
    }

    private function getUserRank()
    {
        $userId = $_GET['user_id'] ?? null;
        if (!$userId) {
            http_response_code(400);
            echo json_encode(["message" => "user_id required"]);
            return;
        }

        // Get all users with scores
        $query = "SELECT 
            u.id,
            (COALESCE(s.focus_minutes, 0) * 0.4 + COALESCE(s.daily_streak, 0) * 10 + COALESCE(s.completed_tasks, 0) * 5) as score
        FROM users u
        LEFT JOIN user_stats s ON u.id = s.user_id
        WHERE u.role != 'banned'
        ORDER BY score DESC";

        $stmt = $this->db->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rank = 0;
        $userScore = 0;
        $totalUsers = count($results);
        $avgScore = 0;
        $totalScore = 0;

        foreach ($results as $index => $user) {
            $totalScore += $user['score'];
            if ($user['id'] === $userId) {
                $rank = $index + 1;
                $userScore = floatval($user['score']);
            }
        }

        $avgScore = $totalUsers > 0 ? $totalScore / $totalUsers : 0;
        $percentAboveMedian = $avgScore > 0 ? (($userScore - $avgScore) / $avgScore) * 100 : 0;

        echo json_encode([
            "rank" => $rank,
            "total" => $totalUsers,
            "score" => round($userScore, 1),
            "avg_score" => round($avgScore, 1),
            "percent_above_median" => round($percentAboveMedian, 1)
        ]);
    }

    private function getCohortPulse()
    {
        $programId = $_GET['program_id'] ?? null;
        $userId = $_GET['user_id'] ?? null;

        // Calculate cohort-wide progress
        $query = "SELECT 
            COUNT(*) as total_members,
            AVG(performance_score) as avg_performance,
            MAX(performance_score) as max_performance
        FROM cohort_progress";

        if ($programId) {
            $query .= " WHERE program_id = :program_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':program_id' => $programId]);
        } else {
            $stmt = $this->db->query($query);
        }

        $cohortData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get user's performance in cohort
        $userScore = 0;
        $mastery = 0;
        if ($userId) {
            $userQuery = "SELECT performance_score FROM cohort_progress WHERE user_id = :user_id";
            if ($programId) {
                $userQuery .= " AND program_id = :program_id";
            }
            $userQuery .= " LIMIT 1";

            $params = [':user_id' => $userId];
            if ($programId)
                $params[':program_id'] = $programId;

            $userStmt = $this->db->prepare($userQuery);
            $userStmt->execute($params);
            $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($userResult) {
                $userScore = $userResult['performance_score'];
                $maxPossible = $cohortData['max_performance'] ?: 100;
                $mastery = $maxPossible > 0 ? ($userScore / $maxPossible) * 100 : 0;
            }
        }

        echo json_encode([
            "total_members" => (int) ($cohortData['total_members'] ?? 0),
            "avg_mastery" => round(floatval($cohortData['avg_performance'] ?? 0), 1),
            "user_mastery" => round($mastery, 1),
            "calibration" => $cohortData['avg_performance'] > 0
                ? round((($userScore - $cohortData['avg_performance']) / $cohortData['avg_performance']) * 100, 1)
                : 0
        ]);
    }
}
?>