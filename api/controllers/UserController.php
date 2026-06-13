<?php
class UserController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($userId, $resource, $resourceId = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // GET /api/users/count — public count of users with role "user"
        if ($userId === 'count' && $resource === null && $method === 'GET') {
            $this->getUserCount();
            return;
        }

        // Validate userId
        if (!$userId) {
            http_response_code(400);
            echo json_encode(["message" => "User ID required"]);
            return;
        }

        switch ($resource) {
            case 'stats':
                $this->handleStats($userId, $method);
                break;
            case 'meals':
                $this->handleMeals($userId, $method, $resourceId);
                break;
            case 'cycles':
                $this->handleCycles($userId, $method, $resourceId);
                break;
            case 'symptoms':
                $this->handleSymptoms($userId, $method);
                break;
            case 'chat':
                $this->handleChat($userId, $method);
                break;
            default:
                http_response_code(404);
                echo json_encode(["message" => "Resource not found"]);
        }
    }

    // ==================== DAILY SYMPTOMS (Quick Log) ====================
    private function handleSymptoms($userId, $method)
    {
        if ($method === 'GET') {
            $this->getTodaySymptoms($userId);
        } elseif ($method === 'POST') {
            $this->saveTodaySymptoms($userId);
        } else {
            http_response_code(405);
        }
    }

    private function getTodaySymptoms($userId)
    {
        $today = date('Y-m-d');
        $query = "SELECT symptoms FROM daily_symptom_logs WHERE user_id = :uid AND log_date = :date LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':uid' => $userId, ':date' => $today]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['symptoms']) {
            echo $row['symptoms']; // Already JSON array
        } else {
            echo json_encode([]);
        }
    }

    private function saveTodaySymptoms($userId)
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $symptoms = isset($data['symptoms']) ? json_encode($data['symptoms']) : '[]';
        $today = date('Y-m-d');

        // Upsert: update if exists, insert if not
        $checkQuery = "SELECT id FROM daily_symptom_logs WHERE user_id = :uid AND log_date = :date";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':uid' => $userId, ':date' => $today]);

        if ($checkStmt->fetch()) {
            $query = "UPDATE daily_symptom_logs SET symptoms = :symptoms, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND log_date = :date";
        } else {
            $query = "INSERT INTO daily_symptom_logs (id, user_id, log_date, symptoms) VALUES (:id, :uid, :date, :symptoms)";
            $id = uniqid('sym_');
        }

        $stmt = $this->db->prepare($query);
        $params = [':uid' => $userId, ':date' => $today, ':symptoms' => $symptoms];
        if (isset($id)) {
            $params[':id'] = $id;
        }

        if ($stmt->execute($params)) {
            http_response_code(200);
            echo json_encode(["message" => "Symptoms saved", "date" => $today]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to save symptoms"]);
        }
    }

    // ==================== STATS ====================
    private function handleStats($userId, $method)
    {
        if ($method === 'GET') {
            $this->getStats($userId);
        } elseif ($method === 'POST' || $method === 'PUT') {
            $this->saveStats($userId);
        } else {
            http_response_code(405);
        }
    }

    private function getStats($userId)
    {
        $query = "SELECT * FROM user_stats WHERE user_id = :uid LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':uid', $userId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Parse JSON fields
            $stats = [
                'focusMinutes' => $row['focus_minutes'] ?? 0,
                'completedTasks' => $row['completed_tasks'] ?? 0,
                'dailyStreak' => $row['daily_streak'] ?? 0,
                'moodHistory' => $row['mood_history'] ? json_decode($row['mood_history'], true) : [],
                'goals' => $row['goals'] ? json_decode($row['goals'], true) : ['focusMinutes' => 120, 'targetMood' => 'energized'],
                'purchasedProgramIds' => $row['purchased_programs'] ? json_decode($row['purchased_programs'], true) : [],
                'macros' => $row['macros'] ? json_decode($row['macros'], true) : ['protein' => 0, 'carbs' => 0, 'fats' => 0, 'calories' => 0],
                'cohortProgress' => $row['cohort_progress'] ? json_decode($row['cohort_progress'], true) : []
            ];
            echo json_encode($stats);
        } else {
            // Return default stats if none exist
            echo json_encode([
                'focusMinutes' => 0,
                'completedTasks' => 0,
                'dailyStreak' => 0,
                'moodHistory' => [],
                'goals' => ['focusMinutes' => 120, 'targetMood' => 'energized'],
                'purchasedProgramIds' => [],
                'macros' => ['protein' => 0, 'carbs' => 0, 'fats' => 0, 'calories' => 0],
                'cohortProgress' => []
            ]);
        }
    }

    private function saveStats($userId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        // Build update query dynamically
        $updates = [];
        $params = [':uid' => $userId];

        if (isset($data['focusMinutes'])) {
            $updates[] = "focus_minutes = :focus";
            $params[':focus'] = $data['focusMinutes'];
        }
        if (isset($data['completedTasks'])) {
            $updates[] = "completed_tasks = :tasks";
            $params[':tasks'] = $data['completedTasks'];
        }
        if (isset($data['dailyStreak'])) {
            $updates[] = "daily_streak = :streak";
            $params[':streak'] = $data['dailyStreak'];
        }
        if (isset($data['moodHistory'])) {
            $updates[] = "mood_history = :mood";
            $params[':mood'] = json_encode($data['moodHistory']);
        }
        if (isset($data['goals'])) {
            $updates[] = "goals = :goals";
            $params[':goals'] = json_encode($data['goals']);
        }
        if (isset($data['macros'])) {
            $updates[] = "macros = :macros";
            $params[':macros'] = json_encode($data['macros']);
        }
        if (isset($data['cohortProgress'])) {
            $updates[] = "cohort_progress = :cohort";
            $params[':cohort'] = json_encode($data['cohortProgress']);
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(["message" => "No data to update"]);
            return;
        }

        $query = "UPDATE user_stats SET " . implode(', ', $updates) . " WHERE user_id = :uid";
        $stmt = $this->db->prepare($query);

        if ($stmt->execute($params)) {
            http_response_code(200);
            echo json_encode(["message" => "Stats updated"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to update stats"]);
        }
    }

    // ==================== MEALS ====================
    private function handleMeals($userId, $method, $mealId = null)
    {
        if ($method === 'GET') {
            $this->getMeals($userId);
        } elseif ($method === 'POST') {
            $this->saveMeal($userId);
        } elseif ($method === 'DELETE' && $mealId) {
            $this->deleteMeal($userId, $mealId);
        } else {
            http_response_code(405);
        }
    }

    private function getMeals($userId)
    {
        $query = "SELECT * FROM meal_logs WHERE user_id = :uid ORDER BY logged_at DESC LIMIT 50";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':uid', $userId);
        $stmt->execute();
        $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse JSON fields
        $result = array_map(function ($meal) {
            return [
                'id' => $meal['id'],
                'timestamp' => $meal['logged_at'],
                'foodItems' => $meal['food_items'] ? json_decode($meal['food_items'], true) : [],
                'macros' => $meal['macros'] ? json_decode($meal['macros'], true) : [],
                'wellnessScore' => $meal['wellness_score'] ?? 0,
                'summary' => $meal['summary'] ?? ''
            ];
        }, $meals);

        echo json_encode($result);
    }

    private function saveMeal($userId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        $id = $data['id'] ?? uniqid();
        $foodItems = isset($data['foodItems']) ? json_encode($data['foodItems']) : '[]';
        $macros = isset($data['macros']) ? json_encode($data['macros']) : '{}';
        $wellnessScore = $data['wellnessScore'] ?? 0;
        $summary = $data['summary'] ?? '';

        $query = "INSERT INTO meal_logs (id, user_id, food_items, macros, wellness_score, summary) 
                  VALUES (:id, :uid, :food, :macros, :score, :summary)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':uid', $userId);
        $stmt->bindParam(':food', $foodItems);
        $stmt->bindParam(':macros', $macros);
        $stmt->bindParam(':score', $wellnessScore);
        $stmt->bindParam(':summary', $summary);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(["message" => "Meal logged", "id" => $id]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to log meal"]);
        }
    }

    private function deleteMeal($userId, $mealId)
    {
        $query = "DELETE FROM meal_logs WHERE id = :id AND user_id = :uid";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $mealId);
        $stmt->bindParam(':uid', $userId);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["message" => "Meal deleted"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to delete meal"]);
        }
    }

    // ==================== CYCLES ====================
    private function handleCycles($userId, $method, $cycleId = null)
    {
        if ($method === 'GET') {
            $this->getCycles($userId);
        } elseif ($method === 'POST') {
            $this->saveCycle($userId);
        } elseif ($method === 'PUT' && $cycleId) {
            $this->updateCycle($userId, $cycleId);
        } elseif ($method === 'DELETE' && $cycleId) {
            $this->deleteCycle($userId, $cycleId);
        } else {
            http_response_code(405);
        }
    }

    private function getCycles($userId)
    {
        $query = "SELECT * FROM cycle_logs WHERE user_id = :uid ORDER BY start_date DESC LIMIT 24";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':uid', $userId);
        $stmt->execute();
        $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse and format for frontend
        $result = array_map(function ($cycle) {
            return [
                'id' => $cycle['id'],
                'startDate' => $cycle['start_date'],
                'endDate' => $cycle['end_date'],
                'symptoms' => $cycle['symptoms'] ? json_decode($cycle['symptoms'], true) : [],
                'flowIntensity' => $cycle['flow_intensity'],
                'notes' => $cycle['notes'],
                'cycleLength' => $cycle['cycle_length'],
                'periodLength' => $cycle['period_length']
            ];
        }, $cycles);

        echo json_encode($result);
    }

    private function saveCycle($userId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        $id = $data['id'] ?? uniqid();
        $startDate = $data['startDate'] ?? date('Y-m-d');
        $endDate = $data['endDate'] ?? null;
        $symptoms = isset($data['symptoms']) ? json_encode($data['symptoms']) : '[]';
        $flowIntensity = $data['flowIntensity'] ?? null;
        $notes = $data['notes'] ?? null;

        $query = "INSERT INTO cycle_logs (id, user_id, start_date, end_date, symptoms, flow_intensity, notes) 
                  VALUES (:id, :uid, :start, :end, :symptoms, :flow, :notes)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':uid', $userId);
        $stmt->bindParam(':start', $startDate);
        $stmt->bindParam(':end', $endDate);
        $stmt->bindParam(':symptoms', $symptoms);
        $stmt->bindParam(':flow', $flowIntensity);
        $stmt->bindParam(':notes', $notes);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(["message" => "Cycle logged", "id" => $id]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to log cycle"]);
        }
    }

    private function updateCycle($userId, $cycleId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        $updates = [];
        $params = [':id' => $cycleId, ':uid' => $userId];

        if (isset($data['endDate'])) {
            $updates[] = "end_date = :end";
            $params[':end'] = $data['endDate'];

            // Calculate period length if we now have both dates
            if ($data['endDate']) {
                $updates[] = "period_length = CAST((julianday(:end2) - julianday(start_date)) AS INTEGER) + 1";
                $params[':end2'] = $data['endDate'];
            }
        }
        if (isset($data['symptoms'])) {
            $updates[] = "symptoms = :symptoms";
            $params[':symptoms'] = json_encode($data['symptoms']);
        }
        if (isset($data['flowIntensity'])) {
            $updates[] = "flow_intensity = :flow";
            $params[':flow'] = $data['flowIntensity'];
        }
        if (isset($data['notes'])) {
            $updates[] = "notes = :notes";
            $params[':notes'] = $data['notes'];
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(["message" => "No data to update"]);
            return;
        }

        $query = "UPDATE cycle_logs SET " . implode(', ', $updates) . " WHERE id = :id AND user_id = :uid";
        $stmt = $this->db->prepare($query);

        if ($stmt->execute($params)) {
            http_response_code(200);
            echo json_encode(["message" => "Cycle updated"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to update cycle"]);
        }
    }

    private function deleteCycle($userId, $cycleId)
    {
        $query = "DELETE FROM cycle_logs WHERE id = :id AND user_id = :uid";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $cycleId);
        $stmt->bindParam(':uid', $userId);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["message" => "Cycle deleted"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to delete cycle"]);
        }
    }

    // ==================== CHAT ====================
    private function handleChat($userId, $method)
    {
        if ($method === 'GET') {
            $this->getChatHistory($userId);
        } elseif ($method === 'POST') {
            $this->saveChatHistory($userId);
        } else {
            http_response_code(405);
        }
    }

    private function getChatHistory($userId)
    {
        $query = "SELECT messages FROM chat_sessions WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':uid', $userId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['messages']) {
            echo $row['messages']; // Already JSON
        } else {
            echo json_encode([]);
        }
    }

    private function saveChatHistory($userId)
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $messages = isset($data['messages']) ? json_encode($data['messages']) : '[]';

        // Check if session exists
        $checkQuery = "SELECT id FROM chat_sessions WHERE user_id = :uid";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':uid', $userId);
        $checkStmt->execute();

        if ($checkStmt->fetch()) {
            // Update existing
            $query = "UPDATE chat_sessions SET messages = :messages, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid";
        } else {
            // Insert new
            $query = "INSERT INTO chat_sessions (user_id, messages) VALUES (:uid, :messages)";
        }

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':uid', $userId);
        $stmt->bindParam(':messages', $messages);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["message" => "Chat saved"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to save chat"]);
        }
    }

    // ==================== USER COUNT ====================
    public function getUserCount()
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
            echo json_encode((int) $stmt->fetchColumn());
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(0);
        }
    }
}
?>