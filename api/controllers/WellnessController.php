<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
class WellnessController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        $payload = AuthMiddleware::verifyToken($token);
        if ($payload) {
            return $payload;
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
            case 'today':
                // GET /api/wellness/today/{userId}
                if ($method === 'GET') {
                    $this->getTodayLog($userId);
                }
                break;

            case 'log':
                // POST /api/wellness/log
                // PUT /api/wellness/log
                if ($method === 'POST' || $method === 'PUT') {
                    $this->saveWellnessLog();
                }
                break;

            case 'history':
                // GET /api/wellness/history/{userId}?days=30
                if ($method === 'GET') {
                    $this->getHistory($userId);
                }
                break;

            case 'stats':
                // GET /api/wellness/stats/{userId}
                if ($method === 'GET') {
                    $this->getWellnessStats($userId);
                }
                break;

            case 'water':
                // POST /api/wellness/water (quick increment)
                if ($method === 'POST') {
                    $this->logWater();
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    /**
     * Get today's wellness log
     */
    private function getTodayLog($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $today = date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT * FROM daily_wellness_logs 
            WHERE user_id = :uid AND log_date = :date
        ");
        $stmt->execute([':uid' => $userId, ':date' => $today]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            // Return empty log structure
            echo json_encode([
                'user_id' => $userId,
                'log_date' => $today,
                'water_glasses' => 0,
                'sleep_hours' => 0,
                'sleep_quality' => null,
                'energy_level' => null,
                'stress_level' => null,
                'notes' => null
            ]);
        } else {
            echo json_encode($log);
        }
    }

    /**
     * Save/Update wellness log
     * POST/PUT /api/wellness/log
     * Body: { log_date, water_glasses?, sleep_hours?, sleep_quality?, energy_level?, stress_level?, notes? }
     */
    private function saveWellnessLog()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $this->currentUser['id'];
        $logDate = $data['log_date'] ?? date('Y-m-d');

        // Check if log exists
        $checkStmt = $this->db->prepare("
            SELECT id FROM daily_wellness_logs 
            WHERE user_id = :uid AND log_date = :date
        ");
        $checkStmt->execute([':uid' => $userId, ':date' => $logDate]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing log
            $updates = [];
            $params = [':id' => $existing['id']];

            if (isset($data['water_glasses'])) {
                $updates[] = "water_glasses = :water";
                $params[':water'] = $data['water_glasses'];
            }
            if (isset($data['sleep_hours'])) {
                $updates[] = "sleep_hours = :sleep";
                $params[':sleep'] = $data['sleep_hours'];
            }
            if (isset($data['sleep_quality'])) {
                $updates[] = "sleep_quality = :quality";
                $params[':quality'] = $data['sleep_quality'];
            }
            if (isset($data['energy_level'])) {
                $updates[] = "energy_level = :energy";
                $params[':energy'] = $data['energy_level'];
            }
            if (isset($data['stress_level'])) {
                $updates[] = "stress_level = :stress";
                $params[':stress'] = $data['stress_level'];
            }
            if (isset($data['notes'])) {
                $updates[] = "notes = :notes";
                $params[':notes'] = $data['notes'];
            }

            $updates[] = "updated_at = CURRENT_TIMESTAMP";

            if (!empty($updates)) {
                $query = "UPDATE daily_wellness_logs SET " . implode(', ', $updates) . " WHERE id = :id";
                $updateStmt = $this->db->prepare($query);
                $updateStmt->execute($params);
            }

            echo json_encode(["message" => "Wellness log updated", "log_id" => $existing['id']]);
        } else {
            // Create new log
            $logId = 'wlog_' . uniqid();

            $stmt = $this->db->prepare("
                INSERT INTO daily_wellness_logs 
                (id, user_id, log_date, water_glasses, sleep_hours, sleep_quality, energy_level, stress_level, notes)
                VALUES (:id, :uid, :date, :water, :sleep, :quality, :energy, :stress, :notes)
            ");
            $stmt->execute([
                ':id' => $logId,
                ':uid' => $userId,
                ':date' => $logDate,
                ':water' => $data['water_glasses'] ?? 0,
                ':sleep' => $data['sleep_hours'] ?? 0,
                ':quality' => $data['sleep_quality'] ?? null,
                ':energy' => $data['energy_level'] ?? null,
                ':stress' => $data['stress_level'] ?? null,
                ':notes' => $data['notes'] ?? null
            ]);

            echo json_encode(["message" => "Wellness log created", "log_id" => $logId]);
        }
    }

    /**
     * Quick water increment
     * POST /api/wellness/water
     * Body: { glasses: 1 }
     */
    private function logWater()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $this->currentUser['id'];
        $today = date('Y-m-d');
        $increment = $data['glasses'] ?? 1;

        // Get or create today's log
        $stmt = $this->db->prepare("
            SELECT id, water_glasses FROM daily_wellness_logs 
            WHERE user_id = :uid AND log_date = :date
        ");
        $stmt->execute([':uid' => $userId, ':date' => $today]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($log) {
            // Update
            $newCount = $log['water_glasses'] + $increment;
            $updateStmt = $this->db->prepare("
                UPDATE daily_wellness_logs 
                SET water_glasses = :count, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $updateStmt->execute([':count' => $newCount, ':id' => $log['id']]);
            echo json_encode(["message" => "Water logged", "total_glasses" => $newCount]);
        } else {
            // Create
            $logId = 'wlog_' . uniqid();
            $insertStmt = $this->db->prepare("
                INSERT INTO daily_wellness_logs (id, user_id, log_date, water_glasses)
                VALUES (:id, :uid, :date, :count)
            ");
            $insertStmt->execute([
                ':id' => $logId,
                ':uid' => $userId,
                ':date' => $today,
                ':count' => $increment
            ]);
            echo json_encode(["message" => "Water logged", "total_glasses" => $increment]);
        }
    }

    /**
     * Get wellness history
     * GET /api/wellness/history/{userId}?days=30
     */
    private function getHistory($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $days = $_GET['days'] ?? 30;

        $stmt = $this->db->prepare("
            SELECT * FROM daily_wellness_logs 
            WHERE user_id = :uid 
            AND log_date >= DATE('now', '-' || :days || ' days')
            ORDER BY log_date DESC
        ");
        $stmt->execute([':uid' => $userId, ':days' => $days]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($history);
    }

    /**
     * Get wellness stats/averages
     * GET /api/wellness/stats/{userId}
     */
    private function getWellnessStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Last 7 days averages
        $stmt = $this->db->prepare("
            SELECT 
                AVG(water_glasses) as avg_water,
                AVG(sleep_hours) as avg_sleep,
                AVG(energy_level) as avg_energy,
                AVG(stress_level) as avg_stress,
                COUNT(*) as days_logged
            FROM daily_wellness_logs 
            WHERE user_id = :uid 
            AND log_date >= DATE('now', '-7 days')
        ");
        $stmt->execute([':uid' => $userId]);
        $weekStats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Current streak (consecutive days with logs)
        $streakStmt = $this->db->prepare("
            SELECT log_date FROM daily_wellness_logs 
            WHERE user_id = :uid 
            ORDER BY log_date DESC
        ");
        $streakStmt->execute([':uid' => $userId]);
        $dates = $streakStmt->fetchAll(PDO::FETCH_COLUMN);

        $currentStreak = 0;
        $expectedDate = new DateTime();
        foreach ($dates as $date) {
            if ($date === $expectedDate->format('Y-m-d')) {
                $currentStreak++;
                $expectedDate->modify('-1 day');
            } else {
                break;
            }
        }

        echo json_encode([
            'avg_water_week' => round($weekStats['avg_water'] ?? 0, 1),
            'avg_sleep_week' => round($weekStats['avg_sleep'] ?? 0, 1),
            'avg_energy_week' => round($weekStats['avg_energy'] ?? 0, 1),
            'avg_stress_week' => round($weekStats['avg_stress'] ?? 0, 1),
            'days_logged_week' => $weekStats['days_logged'] ?? 0,
            'logging_streak' => $currentStreak
        ]);
    }
}
?>