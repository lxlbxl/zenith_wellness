<?php
require_once __DIR__ . '/../services/NotificationService.php';

class GoalsController
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
            case 'user':
                // GET /api/goals/user/{userId}
                if ($method === 'GET' && $userId) {
                    $this->getUserGoals($userId);
                }
                // POST /api/goals/user/{userId}
                else if ($method === 'POST' && $userId) {
                    $this->createGoal($userId);
                }
                break;

            case 'goal':
                // GET /api/goals/goal/{goalId}
                // PUT /api/goals/goal/{goalId}
                // DELETE /api/goals/goal/{goalId}
                if ($method === 'GET') {
                    $this->getGoal($userId);
                } else if ($method === 'PUT') {
                    $this->updateGoal($userId);
                } else if ($method === 'DELETE') {
                    $this->deleteGoal($userId);
                }
                break;

            case 'progress':
                // POST /api/goals/progress/{goalId}
                if ($method === 'POST') {
                    $this->logProgress($userId);
                }
                break;

            case 'history':
                // GET /api/goals/history/{goalId}
                if ($method === 'GET') {
                    $this->getProgressHistory($userId);
                }
                break;

            case 'stats':
                // GET /api/goals/stats/{userId}
                if ($method === 'GET') {
                    $this->getGoalStats($userId);
                }
                break;

            case 'check-deadlines':
                // GET /api/goals/check-deadlines (for cron or manual trigger)
                if ($method === 'GET') {
                    $this->checkUpcomingDeadlines();
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    /**
     * Get all goals for a user
     * GET /api/goals/user/{userId}
     */
    private function getUserGoals($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $status = $_GET['status'] ?? null;
        $category = $_GET['category'] ?? null;

        $query = "SELECT g.*, 
                  COUNT(DISTINCT m.id) as total_milestones,
                  COUNT(DISTINCT CASE WHEN m.achieved = 1 THEN m.id END) as achieved_milestones
                  FROM personal_goals g
                  LEFT JOIN goal_milestones m ON g.id = m.goal_id
                  WHERE g.user_id = :uid";

        if ($status) {
            $query .= " AND g.status = :status";
        }
        if ($category) {
            $query .= " AND g.category = :category";
        }

        $query .= " GROUP BY g.id ORDER BY g.created_at DESC";

        $stmt = $this->db->prepare($query);
        $params = [':uid' => $userId];
        if ($status)
            $params[':status'] = $status;
        if ($category)
            $params[':category'] = $category;

        $stmt->execute($params);
        $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate progress percentage for each goal
        foreach ($goals as &$goal) {
            if ($goal['target_value'] > 0) {
                $goal['progress_percent'] = min(100, ($goal['current_value'] / $goal['target_value']) * 100);
            } else {
                $goal['progress_percent'] = 0;
            }
        }

        echo json_encode($goals);
    }

    /**
     * Get specific goal with details
     * GET /api/goals/goal/{goalId}
     */
    private function getGoal($goalId)
    {
        $stmt = $this->db->prepare("
            SELECT g.* FROM personal_goals g 
            WHERE g.id = :gid AND g.user_id = :uid
        ");
        $stmt->execute([':gid' => $goalId, ':uid' => $this->currentUser['id']]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$goal) {
            http_response_code(404);
            echo json_encode(["message" => "Goal not found"]);
            return;
        }

        // Get milestones
        $milestonesStmt = $this->db->prepare("
            SELECT * FROM goal_milestones 
            WHERE goal_id = :gid 
            ORDER BY target_value ASC
        ");
        $milestonesStmt->execute([':gid' => $goalId]);
        $goal['milestones'] = $milestonesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate progress
        if ($goal['target_value'] > 0) {
            $goal['progress_percent'] = min(100, ($goal['current_value'] / $goal['target_value']) * 100);
        } else {
            $goal['progress_percent'] = 0;
        }

        echo json_encode($goal);
    }

    /**
     * Create new goal
     * POST /api/goals/user/{userId}
     */
    private function createGoal($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // Validation
        if (empty($data['title'])) {
            http_response_code(400);
            echo json_encode(["message" => "Title is required"]);
            return;
        }

        $goalId = 'goal_' . uniqid();

        $stmt = $this->db->prepare("
            INSERT INTO personal_goals 
            (id, user_id, title, description, category, target_value, current_value, 
             unit, start_date, target_date, status, priority)
            VALUES (:id, :uid, :title, :desc, :cat, :target, :current, 
                    :unit, :start, :end, :status, :priority)
        ");

        $stmt->execute([
            ':id' => $goalId,
            ':uid' => $userId,
            ':title' => $data['title'],
            ':desc' => $data['description'] ?? null,
            ':cat' => $data['category'] ?? 'custom',
            ':target' => $data['target_value'] ?? 0,
            ':current' => $data['current_value'] ?? 0,
            ':unit' => $data['unit'] ?? null,
            ':start' => $data['start_date'] ?? date('Y-m-d'),
            ':end' => $data['target_date'] ?? null,
            ':status' => 'active',
            ':priority' => $data['priority'] ?? 'medium'
        ]);

        // Create milestones if provided
        if (!empty($data['milestones'])) {
            $milestoneStmt = $this->db->prepare("
                INSERT INTO goal_milestones (id, goal_id, title, target_value)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($data['milestones'] as $milestone) {
                $milestoneStmt->execute([
                    'ms_' . uniqid(),
                    $goalId,
                    $milestone['title'],
                    $milestone['target_value']
                ]);
            }
        }

        echo json_encode(["message" => "Goal created", "goal_id" => $goalId]);
    }

    /**
     * Update goal
     * PUT /api/goals/goal/{goalId}
     */
    private function updateGoal($goalId)
    {
        // Verify ownership
        $checkStmt = $this->db->prepare("SELECT user_id FROM personal_goals WHERE id = :gid");
        $checkStmt->execute([':gid' => $goalId]);
        $goal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$goal || $goal['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        $updates = [];
        $params = [':id' => $goalId];

        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['description'])) {
            $updates[] = "description = :desc";
            $params[':desc'] = $data['description'];
        }
        if (isset($data['target_value'])) {
            $updates[] = "target_value = :target";
            $params[':target'] = $data['target_value'];
        }
        if (isset($data['target_date'])) {
            $updates[] = "target_date = :date";
            $params[':date'] = $data['target_date'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];

            // Set completed_at if status is completed
            if ($data['status'] === 'completed') {
                $updates[] = "completed_at = CURRENT_TIMESTAMP";
            }
        }
        if (isset($data['priority'])) {
            $updates[] = "priority = :priority";
            $params[':priority'] = $data['priority'];
        }

        $updates[] = "updated_at = CURRENT_TIMESTAMP";

        if (!empty($updates)) {
            $query = "UPDATE personal_goals SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
        }

        echo json_encode(["message" => "Goal updated"]);
    }

    /**
     * Delete goal
     * DELETE /api/goals/goal/{goalId}
     */
    private function deleteGoal($goalId)
    {
        // Verify ownership
        $checkStmt = $this->db->prepare("SELECT user_id FROM personal_goals WHERE id = :gid");
        $checkStmt->execute([':gid' => $goalId]);
        $goal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$goal || $goal['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM personal_goals WHERE id = :gid");
        $stmt->execute([':gid' => $goalId]);

        echo json_encode(["message" => "Goal deleted"]);
    }

    /**
     * Log progress update
     * POST /api/goals/progress/{goalId}
     */
    private function logProgress($goalId)
    {
        // Verify ownership
        $checkStmt = $this->db->prepare("SELECT user_id, target_value FROM personal_goals WHERE id = :gid");
        $checkStmt->execute([':gid' => $goalId]);
        $goal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$goal || $goal['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $value = $data['value'] ?? 0;
        $notes = $data['notes'] ?? null;

        // Log progress entry
        $logId = 'prog_' . uniqid();
        $logStmt = $this->db->prepare("
            INSERT INTO goal_progress_logs (id, goal_id, value, notes)
            VALUES (:id, :gid, :val, :notes)
        ");
        $logStmt->execute([
            ':id' => $logId,
            ':gid' => $goalId,
            ':val' => $value,
            ':notes' => $notes
        ]);

        // Update current_value in goal
        $updateStmt = $this->db->prepare("
            UPDATE personal_goals 
            SET current_value = :val, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :gid
        ");
        $updateStmt->execute([':val' => $value, ':gid' => $goalId]);

        // Check and update milestones
        $milestonesStmt = $this->db->prepare("
            SELECT * FROM goal_milestones 
            WHERE goal_id = :gid AND achieved = 0 AND target_value <= :val
        ");
        $milestonesStmt->execute([':gid' => $goalId, ':val' => $value]);
        $achievedMilestones = $milestonesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get goal title for notifications
        $goalTitleStmt = $this->db->prepare("SELECT title FROM personal_goals WHERE id = :gid");
        $goalTitleStmt->execute([':gid' => $goalId]);
        $goalTitle = $goalTitleStmt->fetchColumn();

        foreach ($achievedMilestones as $milestone) {
            $achieveStmt = $this->db->prepare("
                UPDATE goal_milestones
                SET achieved = 1, achieved_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $achieveStmt->execute([':mid' => $milestone['id']]);

            // Send milestone achievement notification
            $this->notificationService->create(
                $this->currentUser['id'],
                'success',
                'Milestone Reached!',
                "You achieved \"{$milestone['title']}\" for your goal: {$goalTitle}",
                '/goals'
            );
        }

        // Auto-complete goal if target reached
        $goalCompleted = $goal['target_value'] > 0 && $value >= $goal['target_value'];
        if ($goalCompleted) {
            $completeStmt = $this->db->prepare("
                UPDATE personal_goals
                SET status = 'completed', completed_at = CURRENT_TIMESTAMP
                WHERE id = :gid
            ");
            $completeStmt->execute([':gid' => $goalId]);

            // Send goal completion notification
            $this->notificationService->create(
                $this->currentUser['id'],
                'success',
                'Goal Completed!',
                "Congratulations! You've achieved your goal: {$goalTitle}",
                '/goals'
            );
        }

        echo json_encode([
            "message" => "Progress logged",
            "milestones_achieved" => count($achievedMilestones),
            "goal_completed" => $goalCompleted
        ]);
    }

    /**
     * Get progress history
     * GET /api/goals/history/{goalId}
     */
    private function getProgressHistory($goalId)
    {
        // Verify ownership
        $checkStmt = $this->db->prepare("
            SELECT user_id FROM personal_goals WHERE id = :gid
        ");
        $checkStmt->execute([':gid' => $goalId]);
        $goal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$goal || $goal['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM goal_progress_logs 
            WHERE goal_id = :gid 
            ORDER BY logged_at DESC
        ");
        $stmt->execute([':gid' => $goalId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($history);
    }

    /**
     * Get goal statistics
     * GET /api/goals/stats/{userId}
     */
    private function getGoalStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Total goals by status
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_goals,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_goals,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_goals,
                COUNT(CASE WHEN status = 'abandoned' THEN 1 END) as abandoned_goals
            FROM personal_goals 
            WHERE user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Completion rate
        if ($stats['total_goals'] > 0) {
            $stats['completion_rate'] = round(($stats['completed_goals'] / $stats['total_goals']) * 100, 1);
        } else {
            $stats['completion_rate'] = 0;
        }

        // Goals by category
        $catStmt = $this->db->prepare("
            SELECT category, COUNT(*) as count 
            FROM personal_goals 
            WHERE user_id = :uid 
            GROUP BY category
        ");
        $catStmt->execute([':uid' => $userId]);
        $stats['by_category'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($stats);
    }

    /**
     * Check for upcoming goal deadlines and send reminders
     * GET /api/goals/check-deadlines
     * Can be triggered by cron job daily
     */
    private function checkUpcomingDeadlines()
    {
        // Find goals with deadlines in the next 3 days that haven't been notified
        $stmt = $this->db->prepare("
            SELECT g.id, g.user_id, g.title, g.target_date, g.current_value, g.target_value
            FROM personal_goals g
            WHERE g.status = 'active'
            AND g.target_date IS NOT NULL
            AND g.target_date BETWEEN DATE('now') AND DATE('now', '+3 days')
            AND g.deadline_notified = 0
        ");
        $stmt->execute();
        $upcomingGoals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $notificationsSent = 0;

        foreach ($upcomingGoals as $goal) {
            $daysLeft = (strtotime($goal['target_date']) - strtotime(date('Y-m-d'))) / 86400;
            $daysText = $daysLeft <= 0 ? 'today' : ($daysLeft == 1 ? 'tomorrow' : "in {$daysLeft} days");

            $progress = $goal['target_value'] > 0
                ? round(($goal['current_value'] / $goal['target_value']) * 100)
                : 0;

            $this->notificationService->create(
                $goal['user_id'],
                'warning',
                'Goal Deadline Approaching',
                "Your goal \"{$goal['title']}\" is due {$daysText}. Current progress: {$progress}%",
                '/goals'
            );

            // Mark as notified
            $updateStmt = $this->db->prepare("
                UPDATE personal_goals SET deadline_notified = 1 WHERE id = ?
            ");
            $updateStmt->execute([$goal['id']]);

            $notificationsSent++;
        }

        // Also check for overdue goals
        $overdueStmt = $this->db->prepare("
            SELECT g.id, g.user_id, g.title, g.target_date
            FROM personal_goals g
            WHERE g.status = 'active'
            AND g.target_date IS NOT NULL
            AND g.target_date < DATE('now')
            AND g.overdue_notified = 0
        ");
        $overdueStmt->execute();
        $overdueGoals = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($overdueGoals as $goal) {
            $this->notificationService->create(
                $goal['user_id'],
                'error',
                'Goal Overdue',
                "Your goal \"{$goal['title']}\" is past its deadline. Consider updating your target date or marking it complete.",
                '/goals'
            );

            // Mark as notified
            $updateStmt = $this->db->prepare("
                UPDATE personal_goals SET overdue_notified = 1 WHERE id = ?
            ");
            $updateStmt->execute([$goal['id']]);

            $notificationsSent++;
        }

        echo json_encode([
            'message' => 'Deadline check completed',
            'notifications_sent' => $notificationsSent,
            'upcoming_goals' => count($upcomingGoals),
            'overdue_goals' => count($overdueGoals)
        ]);
    }
}
?>