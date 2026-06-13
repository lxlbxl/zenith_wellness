<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
class WorkoutController
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
            case 'exercises':
                // GET /api/workouts/exercises
                if ($method === 'GET') {
                    $this->getExercises();
                }
                break;

            case 'log':
                // POST /api/workouts/log
                if ($method === 'POST') {
                    $this->logWorkout();
                }
                break;

            case 'history':
                // GET /api/workouts/history/{userId}
                if ($method === 'GET') {
                    $this->getWorkoutHistory($userId);
                }
                break;
            
            case 'stats':
                 // GET /api/workouts/stats/{userId}
                 if ($method === 'GET') {
                     $this->getWorkoutStats($userId);
                 }
                 break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    private function getExercises()
    {
        $query = "SELECT * FROM exercise_library ORDER BY category, name";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($exercises);
    }

    private function logWorkout()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $this->currentUser['id'];

        if (empty($data['exercise_name']) && empty($data['exercise_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Exercise name or ID required"]);
            return;
        }

        $logId = 'wlog_' . uniqid();
        $stmt = $this->db->prepare("
            INSERT INTO workout_logs 
            (id, user_id, exercise_id, exercise_name, workout_type, duration_minutes, calories_burned, sets, reps, weight_kg, distance_km, notes, workout_date)
            VALUES (:id, :uid, :eid, :ename, :type, :dur, :cal, :sets, :reps, :weight, :dist, :notes, :date)
        ");

        $stmt->execute([
            ':id' => $logId,
            ':uid' => $userId,
            ':eid' => $data['exercise_id'] ?? null,
            ':ename' => $data['exercise_name'] ?? 'Custom Exercise',
            ':type' => $data['workout_type'] ?? 'strength',
            ':dur' => $data['duration_minutes'] ?? null,
            ':cal' => $data['calories_burned'] ?? null,
            ':sets' => $data['sets'] ?? null,
            ':reps' => $data['reps'] ?? null,
            ':weight' => $data['weight_kg'] ?? null,
            ':dist' => $data['distance_km'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':date' => $data['workout_date'] ?? date('Y-m-d')
        ]);

        echo json_encode(["message" => "Workout logged", "id" => $logId]);
    }

    private function getWorkoutHistory($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403); return;
        }

        $limit = $_GET['limit'] ?? 20;
        $stmt = $this->db->prepare("
            SELECT * FROM workout_logs 
            WHERE user_id = :uid 
            ORDER BY workout_date DESC, logged_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getWorkoutStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403); return;
        }
        
        // Example stats: Total workouts this week, calories burned
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_workouts,
                SUM(duration_minutes) as total_minutes,
                SUM(calories_burned) as total_calories
            FROM workout_logs
            WHERE user_id = :uid
            AND workout_date >= DATE('now', '-7 days')
        ");
        $stmt->execute([':uid' => $userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($stats);
    }
}
?>
