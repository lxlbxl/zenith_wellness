<?php

class ActivityController
{
    private $db;
    private $currentUser;
    private $userRole;

    public function __construct($db)
    {
        $this->db = $db;
        $this->resolveUser();
    }

    private function resolveUser()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token_parts = explode('.', $matches[1]);
            if (count($token_parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])), true);
                $this->currentUser = $payload['data']['id'] ?? $payload['id'] ?? null;
                $this->userRole = $payload['data']['role'] ?? $payload['role'] ?? 'user';
                return;
            }
        }
        $this->currentUser = null;
        $this->userRole = null;
    }

    private function isAdmin(): bool
    {
        return $this->userRole === 'admin';
    }

    public function handleRequest($action, $subId = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($action) {
            case 'log':
                if ($method === 'POST') {
                    $this->log();
                }
                break;

            case 'list':
                // GET /api/activity/list - Admin only
                if ($method === 'GET') {
                    $this->getAll();
                }
                break;

            case 'user':
                // GET /api/activity/user/{userId} - Admin or own data
                if ($method === 'GET' && $subId) {
                    $this->getByUser($subId);
                }
                break;

            case 'stats':
                // GET /api/activity/stats - Admin only
                if ($method === 'GET') {
                    $this->getStats();
                }
                break;

            case 'recent':
                // GET /api/activity/recent - Admin only, last 100 activities
                if ($method === 'GET') {
                    $this->getRecent();
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(['message' => 'Endpoint not found']);
        }
    }

    /**
     * Log user activity
     * POST /api/activity/log
     */
    public function log()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        $userId = $this->currentUser;
        $actionType = $data['action_type'] ?? 'unknown';
        $description = $data['description'] ?? '';
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $stmt = $this->db->prepare("INSERT INTO user_activity_logs (user_id, action_type, description, metadata, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $actionType, $description, $metadata, $ip, $userAgent]);

        echo json_encode(['status' => 'logged']);
    }

    /**
     * Get all activity logs (Admin only)
     * GET /api/activity/list?page=1&limit=50&action_type=login&user_id=xxx
     */
    private function getAll()
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Admin access required']);
            return;
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        // Filter by action type
        if (!empty($_GET['action_type'])) {
            $where[] = "action_type = ?";
            $params[] = $_GET['action_type'];
        }

        // Filter by user
        if (!empty($_GET['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = $_GET['user_id'];
        }

        // Filter by date range
        if (!empty($_GET['from'])) {
            $where[] = "created_at >= ?";
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = "created_at <= ?";
            $params[] = $_GET['to'] . ' 23:59:59';
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM user_activity_logs $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Get paginated results
        $sql = "
            SELECT a.*, u.name as user_name, u.email as user_email
            FROM user_activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.created_at DESC
            LIMIT $limit OFFSET $offset
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse metadata JSON
        foreach ($logs as &$log) {
            if ($log['metadata']) {
                $log['metadata'] = json_decode($log['metadata'], true);
            }
        }

        echo json_encode([
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Get activity logs for a specific user
     * GET /api/activity/user/{userId}
     */
    private function getByUser($userId)
    {
        // Allow admin or user viewing their own data
        if (!$this->isAdmin() && $this->currentUser !== $userId) {
            http_response_code(403);
            echo json_encode(['message' => 'Forbidden']);
            return;
        }

        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));

        $stmt = $this->db->prepare("
            SELECT * FROM user_activity_logs
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse metadata JSON
        foreach ($logs as &$log) {
            if ($log['metadata']) {
                $log['metadata'] = json_decode($log['metadata'], true);
            }
        }

        echo json_encode($logs);
    }

    /**
     * Get activity statistics (Admin only)
     * GET /api/activity/stats
     */
    private function getStats()
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Admin access required']);
            return;
        }

        $stats = [];

        // Total activities
        $stmt = $this->db->query("SELECT COUNT(*) FROM user_activity_logs");
        $stats['total_activities'] = (int)$stmt->fetchColumn();

        // Activities today
        $stmt = $this->db->query("SELECT COUNT(*) FROM user_activity_logs WHERE DATE(created_at) = DATE('now')");
        $stats['activities_today'] = (int)$stmt->fetchColumn();

        // Activities this week
        $stmt = $this->db->query("SELECT COUNT(*) FROM user_activity_logs WHERE created_at >= DATE('now', '-7 days')");
        $stats['activities_this_week'] = (int)$stmt->fetchColumn();

        // Unique users today
        $stmt = $this->db->query("SELECT COUNT(DISTINCT user_id) FROM user_activity_logs WHERE DATE(created_at) = DATE('now') AND user_id IS NOT NULL");
        $stats['unique_users_today'] = (int)$stmt->fetchColumn();

        // By action type (top 10)
        $stmt = $this->db->query("
            SELECT action_type, COUNT(*) as count
            FROM user_activity_logs
            GROUP BY action_type
            ORDER BY count DESC
            LIMIT 10
        ");
        $stats['by_action_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Activity trend (last 7 days)
        $stmt = $this->db->query("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM user_activity_logs
            WHERE created_at >= DATE('now', '-7 days')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stats['trend_7_days'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Most active users (last 7 days)
        $stmt = $this->db->query("
            SELECT a.user_id, u.name, u.email, COUNT(*) as activity_count
            FROM user_activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.created_at >= DATE('now', '-7 days')
            AND a.user_id IS NOT NULL
            GROUP BY a.user_id
            ORDER BY activity_count DESC
            LIMIT 10
        ");
        $stats['most_active_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($stats);
    }

    /**
     * Get recent activity logs (Admin only)
     * GET /api/activity/recent
     */
    private function getRecent()
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Admin access required']);
            return;
        }

        $limit = min(100, max(10, (int)($_GET['limit'] ?? 100)));

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as user_name, u.email as user_email
            FROM user_activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse metadata JSON
        foreach ($logs as &$log) {
            if ($log['metadata']) {
                $log['metadata'] = json_decode($log['metadata'], true);
            }
        }

        echo json_encode($logs);
    }
}
