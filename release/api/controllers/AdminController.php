<?php
class AdminController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action, $targetId = null)
    {
        // 1. Verify Admin Auth
        $authCheck = $this->verifyAdmin();
        if ($authCheck !== true) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required: " . $authCheck]);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        switch ($action) {
            case 'users':
                if ($method === 'GET')
                    $this->getAllUsers();
                break;

            case 'user':
                if ($method === 'GET' && $targetId)
                    $this->getUserDetails($targetId);
                break;

            case 'stats':
                if ($method === 'GET')
                    $this->getSystemStats();
                break;

            case 'logs':
                if ($method === 'GET')
                    $this->getAdminLogs();
                break;

            case 'ban':
                if ($method === 'POST')
                    $this->banUser();
                break;

            case 'role':
                if ($method === 'POST')
                    $this->updateUserRole();
                break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return "No Bearer token found. Header dump: " . json_encode($headers) . " SERVER_AUTH: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'null');
        }

        $token_parts = explode('.', $matches[1]);
        if (count($token_parts) !== 3) {
            return "Invalid token structure";
        }

        try {
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])), true);
            if (!$payload)
                return "Token payload decode failed";

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return "Token expired";
            }

            // Fix: Check for 'data' key which AuthController uses to wrap user info
            $userId = $payload['data']['id'] ?? $payload['id'] ?? null;

            if (!$userId)
                return "No User ID in token";

            // Verify against DB for role changes
            $stmt = $this->db->prepare("SELECT id, role, name FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user)
                return "User ID $userId not found in DB";

            if ($user['role'] === 'admin') {
                $this->currentUser = $user;
                return true;
            }
            return "User role is '" . ($user['role'] ?? 'none') . "', expected 'admin'";
        } catch (Exception $e) {
            return "Exception: " . $e->getMessage();
        }
    }

    private function logAction($action, $targetType, $targetId, $details = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (id, admin_id, action, target_type, target_id, details, ip_address)
                VALUES (:id, :admin, :action, :type, :tid, :details, :ip)
            ");
            $stmt->execute([
                ':id' => uniqid('log_'),
                ':admin' => $this->currentUser['id'],
                ':action' => $action,
                ':type' => $targetType,
                ':tid' => $targetId,
                ':details' => $details,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Silently fail logging
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }

    private function getAllUsers()
    {
        $limit = $_GET['limit'] ?? 50;
        $search = $_GET['search'] ?? '';

        $sql = "
            SELECT u.id, u.name, u.email, u.role, u.created_at, u.persona,
                   COUNT(us.id) as has_stats,
                   (SELECT COUNT(*) FROM user_points up WHERE up.user_id = u.id) as gamified
            FROM users u
            LEFT JOIN user_stats us ON u.id = us.user_id
            WHERE 1=1
        ";

        if ($search) {
            $sql .= " AND (u.name LIKE :s OR u.email LIKE :s)";
        }

        $sql .= " GROUP BY u.id ORDER BY u.created_at DESC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        if ($search)
            $stmt->bindValue(':s', "%$search%");
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getSystemStats()
    {
        // Snapshot current metrics

        // Calculate real revenue from successful payments (amount is in cents)
        $revenueCents = $this->db->query("SELECT SUM(amount) FROM payments WHERE status = 'success'")->fetchColumn();
        $revenue = $revenueCents ? ($revenueCents / 100) : 0;

        $stats = [
            'total_users' => $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_today' => $this->db->query("SELECT COUNT(DISTINCT user_id) FROM daily_wellness_logs WHERE date(created_at) = date('now')")->fetchColumn(),
            'total_journal_entries' => $this->db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(),
            'total_goals' => $this->db->query("SELECT COUNT(*) FROM personal_goals")->fetchColumn(),
            'total_achievements_earned' => $this->db->query("SELECT COUNT(*) FROM user_achievements")->fetchColumn(),
            'revenue' => $revenue
        ];

        echo json_encode($stats);
    }

    private function getAdminLogs()
    {
        $limit = $_GET['limit'] ?? 50;
        $stmt = $this->db->prepare("
            SELECT al.*, u.name as admin_name 
            FROM admin_logs al
            JOIN users u ON al.admin_id = u.id
            ORDER BY al.created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function banUser()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $data['userId'] ?? null;

        if (!$userId) {
            http_response_code(400);
            return;
        }

        if ($userId === $this->currentUser['id']) {
            http_response_code(400);
            echo json_encode(["message" => "Cannot ban yourself"]);
            return;
        }

        $stmt = $this->db->prepare("UPDATE users SET role = 'banned' WHERE id = :id");
        $stmt->execute([':id' => $userId]);

        $this->logAction('ban_user', 'user', $userId, 'Banned via admin panel');
        echo json_encode(["message" => "User banned"]);
    }

    private function updateUserRole()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $data['userId'] ?? null;
        $role = $data['role'] ?? null;

        if (!$userId || !$role || !in_array($role, ['user', 'admin', 'moderator'])) {
            http_response_code(400);
            return;
        }

        if ($userId === $this->currentUser['id'] && $role !== 'admin') {
            http_response_code(400);
            echo json_encode(["message" => "Cannot demote yourself"]);
            return;
        }

        $stmt = $this->db->prepare("UPDATE users SET role = :role WHERE id = :id");
        $stmt->execute([':role' => $role, ':id' => $userId]);

        $this->logAction('update_role', 'user', $userId, "Role changed to $role");
        echo json_encode(["message" => "User role updated"]);
    }

    private function getUserDetails($id)
    {
        try {
            // 1. Basic Info
            $stmt = $this->db->prepare("SELECT id, name, email, role, persona, created_at FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
                return;
            }

            // 2. Stats
            $stmt = $this->db->prepare("SELECT * FROM user_stats WHERE user_id = ?");
            $stmt->execute([$id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Enrollments
            $stmt = $this->db->prepare("
                SELECT ce.*, c.title as cohort_title, c.start_date
                FROM cohort_enrollments ce
                JOIN cohorts c ON ce.cohort_id = c.id
                WHERE ce.user_id = ?
            ");
            $stmt->execute([$id]);
            $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Payments
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Logs (related to this user as target)
            $stmt = $this->db->prepare("SELECT * FROM admin_logs WHERE target_id = ? ORDER BY created_at DESC");
            $stmt->execute([$id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);


            echo json_encode([
                'user' => $user,
                'stats' => $stats,
                'enrollments' => $enrollments,
                'payments' => $payments,
                'logs' => $logs
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
    }
}
?>