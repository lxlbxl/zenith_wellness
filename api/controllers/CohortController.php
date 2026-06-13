<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/AIController.php';
require_once __DIR__ . '/../services/FXRateService.php';

class CohortController
{
    private $db;
    private $table = "cohort_messages";

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action, $cohortId = null, $subAction = null, $subId = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Handle cohort CRUD routes: /api/cohorts, /api/cohorts/{id}
        if ($action === null || $action === '' || $action === 'list') {
            if ($method === 'GET') {
                $this->listCohorts();
            } elseif ($method === 'POST') {
                $this->createCohort();
            } else {
                http_response_code(405);
                echo json_encode(["message" => "Method not allowed"]);
            }
            return;
        }

        // Handle named actions first
        switch ($action) {
            case 'messages':
                if ($method === 'GET') {
                    $this->getMessages();
                } elseif ($method === 'POST') {
                    $this->postMessage();
                } else {
                    http_response_code(405);
                }
                return;

            case 'progress':
                if ($method === 'GET') {
                    $this->getProgress();
                } elseif ($method === 'POST') {
                    $this->saveProgress();
                } else {
                    http_response_code(405);
                }
                return;

            case 'ai-briefing':
                if ($method === 'POST') {
                    $this->getAiBriefing();
                } else {
                    http_response_code(405);
                }
                return;

            case 'leaderboard':
                if ($method === 'GET') {
                    $this->getLeaderboard();
                } else {
                    http_response_code(405);
                }
                return;

            case 'tasks':
                if ($method === 'GET') {
                    $this->getTasks();
                } else {
                    http_response_code(405);
                }
                return;
        }

        // Check if action is a cohort ID (numeric or uuid-like string)
        if (is_numeric($action) || preg_match('/^[a-zA-Z0-9_-]+$/', $action)) {
            $cohortId = $action;

            // Handle sub-actions: /api/cohorts/{id}/participants, /api/cohorts/{id}/participants/{uid}/insights
            if ($subAction === 'participants') {
                if ($subId) {
                    $this->getParticipantInsights($cohortId, $subId);
                } else {
                    $this->getParticipants($cohortId);
                }
                return;
            }

            // Single cohort operations
            if ($method === 'GET') {
                $this->getCohort($cohortId);
            } elseif ($method === 'PUT') {
                $this->updateCohort($cohortId);
            } elseif ($method === 'DELETE') {
                $this->deleteCohort($cohortId);
            } else {
                http_response_code(405);
                echo json_encode(["message" => "Method not allowed"]);
            }
            return;
        }

        // Unknown action
        http_response_code(404);
        echo json_encode([
            "message" => "Cohort action not found",
            "action" => $action,
            "available_actions" => ["messages", "progress", "ai-briefing", "leaderboard", "tasks", "{cohort_id}"]
        ]);
    }

    // ==================== COHORT CRUD ====================

    /**
     * List all cohorts with optional filters
     * GET /api/cohorts?status=active|upcoming|past&category=fitness
     */
    private function listCohorts()
    {
        $status = $_GET['status'] ?? null;
        $category = $_GET['category'] ?? null;
        $limit = min((int) ($_GET['limit'] ?? 50), 100);

        try {
            $query = "SELECT * FROM cohorts WHERE 1=1";
            $params = [];

            if ($status) {
                $now = date('Y-m-d');
                switch ($status) {
                    case 'active':
                        $query .= " AND start_date <= :now1 AND (end_date >= :now2 OR end_date IS NULL)";
                        $params[':now1'] = $now;
                        $params[':now2'] = $now;
                        break;
                    case 'upcoming':
                        $query .= " AND start_date > :now";
                        $params[':now'] = $now;
                        break;
                    case 'past':
                        $query .= " AND end_date < :now";
                        $params[':now'] = $now;
                        break;
                }
            }

            if ($category) {
                $query .= " AND category = :cat";
                $params[':cat'] = $category;
            }

            $query .= " ORDER BY start_date DESC LIMIT " . $limit;

            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get participant count and prices for each cohort
            $fxService = new FXRateService($this->db);
            foreach ($cohorts as &$cohort) {
                $countStmt = $this->db->prepare("SELECT COUNT(*) as count FROM cohort_enrollments WHERE cohort_id = :cid AND status = 'active'");
                $countStmt->execute([':cid' => $cohort['id']]);
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $cohort['participant_count'] = $countRow['count'] ?? 0;

                // Add multi-currency pricing
                $cohort['prices'] = $fxService->getAllCohortPrices($cohort['id']);
            }

            echo json_encode([
                'cohorts' => $cohorts,
                'total' => count($cohorts),
                'filters' => ['status' => $status, 'category' => $category]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to fetch cohorts", "error" => $e->getMessage()]);
        }
    }

    /**
     * Get single cohort details
     * GET /api/cohorts/{id}
     */
    private function getCohort($cohortId)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM cohorts WHERE id = :id");
            $stmt->execute([':id' => $cohortId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cohort) {
                http_response_code(404);
                echo json_encode(["message" => "Cohort not found"]);
                return;
            }

            // Get participant count
            $countStmt = $this->db->prepare("SELECT COUNT(*) as count FROM cohort_progress WHERE program_id = :pid");
            $countStmt->execute([':pid' => $cohortId]);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $cohort['participant_count'] = $countRow['count'] ?? 0;

            // Get tasks for this cohort
            try {
                $tasksStmt = $this->db->prepare("SELECT * FROM cohort_tasks WHERE cohort_id = :cid ORDER BY day_number, order_index");
                $tasksStmt->execute([':cid' => $cohortId]);
                $cohort['tasks'] = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $cohort['tasks'] = [];
            }

            echo json_encode($cohort);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to fetch cohort", "error" => $e->getMessage()]);
        }
    }

    /**
     * Create a new cohort
     * POST /api/cohorts
     */
    private function createCohort()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['title'])) {
            http_response_code(400);
            echo json_encode(["message" => "Title is required"]);
            return;
        }

        try {
            $id = $data['id'] ?? uniqid('cohort_');
            $title = $data['title'];
            $description = $data['description'] ?? '';
            $category = $data['category'] ?? 'wellness';
            $objectives = $data['objectives'] ?? '';
            $durationDays = (int) ($data['duration_days'] ?? 21);
            $startDate = $data['start_date'] ?? date('Y-m-d');
            $endDate = $data['end_date'] ?? date('Y-m-d', strtotime("+{$durationDays} days"));
            $maxParticipants = (int) ($data['max_participants'] ?? 100);
            $price = (int) ($data['price'] ?? 0); // Price in cents
            $isPublic = isset($data['is_public']) ? (int) $data['is_public'] : 1;
            $imageUrl = $data['image_url'] ?? $data['image'] ?? '';

            $query = "INSERT INTO cohorts (id, title, description, category, objectives, duration_days, start_date, end_date, max_participants, price, is_public, image_url)
                      VALUES (:id, :title, :desc, :cat, :obj, :dur, :start, :end, :max, :price, :pub, :img)";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':id' => $id,
                ':title' => $title,
                ':desc' => $description,
                ':cat' => $category,
                ':obj' => $objectives,
                ':dur' => $durationDays,
                ':start' => $startDate,
                ':end' => $endDate,
                ':max' => $maxParticipants,
                ':price' => $price,
                ':pub' => $isPublic,
                ':img' => $imageUrl
            ]);

            // Create default tasks if provided
            if (!empty($data['tasks']) && is_array($data['tasks'])) {
                foreach ($data['tasks'] as $index => $task) {
                    $taskId = uniqid('task_');
                    $taskStmt = $this->db->prepare("INSERT INTO cohort_tasks (id, cohort_id, day_number, title, description, points, difficulty, category, order_index) VALUES (:id, :cid, :day, :title, :desc, :points, :diff, :cat, :ord)");
                    $taskStmt->execute([
                        ':id' => $taskId,
                        ':cid' => $id,
                        ':day' => $task['day_number'] ?? 1,
                        ':title' => $task['title'] ?? 'Task',
                        ':desc' => $task['description'] ?? '',
                        ':points' => $task['points'] ?? 10,
                        ':diff' => $task['difficulty'] ?? 'easy',
                        ':cat' => $task['category'] ?? 'wellness',
                        ':ord' => $index
                    ]);
                }
            }

            // Create default prices for supported currencies
            if ($price > 0) {
                $fxService = new FXRateService($this->db);
                $fxService->updateCohortPrice($id, 'USD', $price);

                // Auto-generate prices for major currencies
                $majorCurrencies = ['NGN', 'GHS', 'KES', 'ZAR', 'GBP', 'EUR'];
                foreach ($majorCurrencies as $curr) {
                    $converted = $fxService->convertAmount($price, 'USD', $curr);
                    if ($converted > 0) {
                        $fxService->updateCohortPrice($id, $curr, $converted);
                    }
                }
            }

            http_response_code(201);
            echo json_encode(["message" => "Cohort created", "id" => $id]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to create cohort", "error" => $e->getMessage()]);
        }
    }

    /**
     * Update a cohort
     * PUT /api/cohorts/{id}
     */
    private function updateCohort($cohortId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        try {
            $checkStmt = $this->db->prepare("SELECT id FROM cohorts WHERE id = :id");
            $checkStmt->execute([':id' => $cohortId]);
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(["message" => "Cohort not found"]);
                return;
            }

            $updates = [];
            $params = [':id' => $cohortId];

            $allowedFields = ['title', 'description', 'category', 'objectives', 'duration_days', 'start_date', 'end_date', 'max_participants', 'price', 'is_public', 'image_url'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(["message" => "No data to update"]);
                return;
            }

            $query = "UPDATE cohorts SET " . implode(', ', $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode(["message" => "Cohort updated"]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to update cohort", "error" => $e->getMessage()]);
        }
    }

    /**
     * Delete a cohort
     * DELETE /api/cohorts/{id}
     */
    private function deleteCohort($cohortId)
    {
        try {
            $checkStmt = $this->db->prepare("SELECT id FROM cohorts WHERE id = :id");
            $checkStmt->execute([':id' => $cohortId]);
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(["message" => "Cohort not found"]);
                return;
            }

            $stmt = $this->db->prepare("DELETE FROM cohorts WHERE id = :id");
            $stmt->execute([':id' => $cohortId]);

            // Clean up related data
            $this->db->prepare("DELETE FROM cohort_progress WHERE program_id = :id")->execute([':id' => $cohortId]);
            try {
                $this->db->prepare("DELETE FROM cohort_tasks WHERE cohort_id = :id")->execute([':id' => $cohortId]);
            } catch (Exception $e) {
            }
            $this->db->prepare("DELETE FROM cohort_messages WHERE program_id = :id")->execute([':id' => $cohortId]);

            http_response_code(200);
            echo json_encode(["message" => "Cohort deleted"]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to delete cohort", "error" => $e->getMessage()]);
        }
    }

    // ==================== PARTICIPANT MANAGEMENT ====================

    /**
     * Get participants for a cohort
     * GET /api/cohorts/{id}/participants
     */
    private function getParticipants($cohortId)
    {
        try {
            $query = "
                SELECT 
                    cp.user_id,
                    u.name as user_name,
                    u.email,
                    u.persona,
                    cp.current_day,
                    cp.performance_score,
                    cp.tasks,
                    cp.created_at as joined_at,
                    cp.updated_at as last_active,
                    COALESCE(up.total_points, 0) as total_points
                FROM cohort_progress cp
                JOIN users u ON cp.user_id = u.id
                LEFT JOIN user_points up ON cp.user_id = up.user_id
                WHERE cp.program_id = :pid
                ORDER BY cp.performance_score DESC
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':pid' => $cohortId]);
            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($participants as $idx => &$p) {
                $p['rank'] = $idx + 1;
                $p['tasks'] = $p['tasks'] ? json_decode($p['tasks'], true) : [];
                $p['completed_tasks'] = is_array($p['tasks']) ? count(array_filter($p['tasks'], fn($t) => $t['completed'] ?? false)) : 0;
            }

            echo json_encode([
                'participants' => $participants,
                'total' => count($participants)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to fetch participants", "error" => $e->getMessage()]);
        }
    }

    /**
     * Get AI-generated insights for a specific participant
     * GET /api/cohorts/{id}/participants/{uid}/insights
     */
    private function getParticipantInsights($cohortId, $userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT cp.*, u.name as user_name, c.title as cohort_title, c.duration_days
                FROM cohort_progress cp
                JOIN users u ON cp.user_id = u.id
                JOIN cohorts c ON cp.program_id = c.id
                WHERE cp.program_id = :pid AND cp.user_id = :uid
            ");
            $stmt->execute([':pid' => $cohortId, ':uid' => $userId]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$participant) {
                http_response_code(404);
                echo json_encode(["message" => "Participant not found in this cohort"]);
                return;
            }

            $aiController = new AIController($this->db);
            $context = $aiController->getUserContext($userId);

            $tasks = $participant['tasks'] ? json_decode($participant['tasks'], true) : [];
            $completedTasks = is_array($tasks) ? count(array_filter($tasks, fn($t) => $t['completed'] ?? false)) : 0;
            $totalTasks = is_array($tasks) ? count($tasks) : 0;
            $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

            $prompt = "Analyze this cohort participant's performance and provide brief insights:

Participant: {$participant['user_name']}
Cohort: {$participant['cohort_title']}
Day: {$participant['current_day']} of {$participant['duration_days']}
Performance Score: {$participant['performance_score']}
Task Completion: {$completedTasks}/{$totalTasks} ({$completionRate}%)
Streak: {$context['streak']} days
Energy Level: {$context['energy']}
Sleep Average: {$context['sleep_avg']} hours

Provide:
1. Strengths (1-2 sentences)
2. Areas for improvement (1-2 sentences)
3. Recommended action (1 sentence)

Keep total response under 100 words.";

            $insights = $this->callAIDirect($prompt, "You are an analytics AI for wellness programs. Provide concise, actionable insights.");

            echo json_encode([
                'participant' => [
                    'user_id' => $userId,
                    'name' => $participant['user_name'],
                    'current_day' => $participant['current_day'],
                    'performance_score' => $participant['performance_score'],
                    'completion_rate' => $completionRate
                ],
                'insights' => $insights,
                'generated_at' => date('c')
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to generate insights", "error" => $e->getMessage()]);
        }
    }

    // ==================== AI BRIEFING (FIXED) ====================

    /**
     * Get AI-generated morning briefing for cohort
     * POST /api/cohort/ai-briefing
     */
    private function getAiBriefing()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $programId = $data['program_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (!$programId || !$userId) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID and User ID required", "error_code" => "MISSING_PARAMS"]);
            return;
        }

        $currentDay = 1;

        try {
            $stmt = $this->db->prepare("SELECT title, objectives, duration_days FROM cohorts WHERE id = :id");
            $stmt->execute([':id' => $programId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cohort) {
                http_response_code(404);
                echo json_encode(["message" => "Cohort not found", "error_code" => "COHORT_NOT_FOUND"]);
                return;
            }

            $stmt = $this->db->prepare("SELECT current_day, performance_score FROM cohort_progress WHERE program_id = :pid AND user_id = :uid");
            $stmt->execute([':pid' => $programId, ':uid' => $userId]);
            $progress = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentDay = $progress['current_day'] ?? 1;
            $score = $progress['performance_score'] ?? 0;
            $cohortName = $cohort['title'] ?? 'Wellness Challenge';
            $objectives = $cohort['objectives'] ?? 'Build healthy habits';
            $totalDays = $cohort['duration_days'] ?? 21;

            $aiController = new AIController($this->db);
            $userContext = $aiController->getUserContext($userId);

            $prompt = "Generate a personalized morning briefing for {$userContext['user_name']} who is on Day {$currentDay} of {$totalDays} in the '{$cohortName}' challenge. 
            
Their objectives: {$objectives}
Current performance score: {$score}
Their mood today: {$userContext['mood_today']}
Sleep last night: {$userContext['sleep_hours']} hours ({$userContext['sleep_quality']})

Provide:
1. An energizing greeting (1 line)
2. Today's focus (1-2 sentences)
3. One specific action they should take today
4. A motivational closing (1 line)

Keep it warm, personal, and under 100 words total. Use their name.";

            $systemPrompt = "You are Zenith, a warm and motivating wellness coach. Be encouraging, specific, and personal.";

            $briefing = $this->callAIDirect($prompt, $systemPrompt);

            echo json_encode([
                "briefing" => $briefing,
                "day" => $currentDay,
                "totalDays" => $totalDays,
                "cohortName" => $cohortName,
                "fallback" => false
            ]);

        } catch (Exception $e) {
            error_log("AI Briefing Error: " . $e->getMessage());

            echo json_encode([
                "briefing" => "Good morning! 🌅 Today is Day " . $currentDay . " of your challenge. Stay focused on your goals and remember: every small step counts. You've got this!",
                "fallback" => true,
                "error_code" => "AI_GENERATION_FAILED",
                "error_message" => $e->getMessage()
            ]);
        }
    }

    /**
     * Direct AI call that returns the response text
     */
    private function callAIDirect($prompt, $systemPrompt)
    {
        $provider = $this->getSetting('ai_provider') ?? 'gemini';
        $apiKey = $this->getApiKey($provider);
        $model = $this->getSetting('ai_default_model') ?? 'gemini-1.5-flash';

        if (empty($apiKey)) {
            throw new Exception("No API key configured for provider: $provider");
        }

        switch ($provider) {
            case 'openai':
                return $this->callOpenAIDirect($prompt, $systemPrompt, $apiKey, $model);
            case 'openrouter':
                return $this->callOpenRouterDirect($prompt, $systemPrompt, $apiKey, $model);
            default:
                return $this->callGeminiDirect($prompt, $systemPrompt, $apiKey, $model);
        }
    }

    private function callGeminiDirect($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $data = [
            "contents" => [
                ["role" => "user", "parts" => [["text" => $systemPrompt . "\n\nUser: " . $prompt]]]
            ]
        ];

        $response = $this->makeRequest($url, $data);
        $result = json_decode($response, true);

        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($result['error'])) {
            throw new Exception($result['error']['message'] ?? 'Gemini API error');
        }

        return $response;
    }

    private function callOpenAIDirect($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://api.openai.com/v1/chat/completions";

        $data = [
            "model" => $model ?: 'gpt-3.5-turbo',
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ]
        ];

        $headers = ["Authorization: Bearer " . $apiKey];
        $response = $this->makeRequest($url, $data, $headers);
        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        if (isset($result['error'])) {
            throw new Exception($result['error']['message'] ?? 'OpenAI API error');
        }

        return $response;
    }

    private function callOpenRouterDirect($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://openrouter.ai/api/v1/chat/completions";

        $data = [
            "model" => $model ?: 'openai/gpt-3.5-turbo',
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ]
        ];

        $headers = [
            "Authorization: Bearer " . $apiKey,
            "HTTP-Referer: https://zenithwellness.app",
            "X-Title: Zenith Wellness"
        ];

        $response = $this->makeRequest($url, $data, $headers);
        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        if (isset($result['error'])) {
            throw new Exception($result['error']['message'] ?? 'OpenRouter API error');
        }

        return $response;
    }

    private function makeRequest($url, $data, $customHeaders = [])
    {
        $headers = "Content-Type: application/json\r\n";
        foreach ($customHeaders as $header) {
            $headers .= $header . "\r\n";
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            throw new Exception("HTTP request failed: " . ($error['message'] ?? 'Unknown error'));
        }

        return $result;
    }

    private function getSetting($key)
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key");
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['setting_value'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getApiKey($provider)
    {
        switch ($provider) {
            case 'gemini':
                return $this->getSetting('gemini_api_key') ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
            case 'openai':
                return $this->getSetting('openai_api_key') ?? '';
            case 'openrouter':
                return $this->getSetting('openrouter_api_key') ?? (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
            default:
                return '';
        }
    }

    // ==================== EXISTING FEATURES ====================

    private function getLeaderboard()
    {
        $programId = $_GET['program_id'] ?? null;

        if (!$programId) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID required"]);
            return;
        }

        try {
            $query = "
                SELECT 
                    cp.user_id,
                    u.name as user_name,
                    u.persona,
                    cp.current_day,
                    cp.performance_score,
                    COALESCE(up.total_points, 0) as total_points,
                    cp.updated_at as last_active
                FROM cohort_progress cp
                JOIN users u ON cp.user_id = u.id
                LEFT JOIN user_points up ON cp.user_id = up.user_id
                WHERE cp.program_id = :pid
                ORDER BY cp.performance_score DESC, up.total_points DESC
                LIMIT 50
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':pid' => $programId]);
            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $leaderboard = [];
            foreach ($participants as $idx => $p) {
                $pulseIndex = min(100, ($p['performance_score'] * 2) + ($p['current_day'] * 3));

                $leaderboard[] = [
                    'rank' => $idx + 1,
                    'userId' => $p['user_id'],
                    'name' => $p['user_name'],
                    'persona' => $p['persona'] ?? 'newbie',
                    'currentDay' => $p['current_day'],
                    'performanceScore' => $p['performance_score'],
                    'totalPoints' => $p['total_points'],
                    'pulseIndex' => $pulseIndex,
                    'lastActive' => $p['last_active']
                ];
            }

            echo json_encode([
                'leaderboard' => $leaderboard,
                'totalParticipants' => count($leaderboard)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => "Failed to fetch leaderboard", "error" => $e->getMessage()]);
        }
    }

    private function getTasks()
    {
        $programId = $_GET['program_id'] ?? null;
        $day = $_GET['day'] ?? 1;

        if (!$programId) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID required"]);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id, title, description, points, difficulty, category
                FROM cohort_tasks 
                WHERE cohort_id = :pid AND day_number = :day
                ORDER BY order_index
            ");
            $stmt->execute([':pid' => $programId, ':day' => $day]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tasks)) {
                $tasks = $this->generateDefaultTasks($day);
            }

            echo json_encode(['tasks' => $tasks, 'day' => $day]);

        } catch (Exception $e) {
            echo json_encode([
                'tasks' => $this->generateDefaultTasks($day),
                'day' => $day,
                'fallback' => true
            ]);
        }
    }

    private function generateDefaultTasks($day)
    {
        $baseTasks = [
            ['id' => 'task_hydration', 'title' => 'Drink 8 glasses of water', 'description' => 'Stay hydrated throughout the day', 'points' => 10, 'difficulty' => 'easy', 'category' => 'wellness'],
            ['id' => 'task_movement', 'title' => '30 minutes of movement', 'description' => 'Walk, stretch, or exercise', 'points' => 20, 'difficulty' => 'medium', 'category' => 'fitness'],
            ['id' => 'task_journal', 'title' => 'Write in your journal', 'description' => 'Reflect on your day and feelings', 'points' => 15, 'difficulty' => 'easy', 'category' => 'mindfulness'],
        ];

        if ($day >= 3) {
            $baseTasks[] = ['id' => 'task_meal', 'title' => 'Log all meals', 'description' => 'Track your nutrition today', 'points' => 15, 'difficulty' => 'medium', 'category' => 'nutrition'];
        }
        if ($day >= 7) {
            $baseTasks[] = ['id' => 'task_sleep', 'title' => 'Get 7+ hours of sleep', 'description' => 'Prioritize rest tonight', 'points' => 25, 'difficulty' => 'medium', 'category' => 'wellness'];
        }

        return $baseTasks;
    }

    private function getMessages()
    {
        $programId = $_GET['program_id'] ?? null;
        if (!$programId) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID required"]);
            return;
        }

        $query = "SELECT * FROM " . $this->table . " WHERE program_id = :pid ORDER BY created_at ASC LIMIT 50";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':pid', $programId);
        $stmt->execute();

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($messages);
    }

    private function postMessage()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (!isset($data->program_id) || !isset($data->message)) {
            http_response_code(400);
            echo json_encode(["message" => "Incomplete data"]);
            return;
        }

        $userId = $data->user_id;
        $userName = $data->user_name;
        $userRank = $data->user_rank ?? 'NEWBIE';

        $query = "INSERT INTO " . $this->table . " 
                 (program_id, user_id, user_name, user_rank, message) 
                 VALUES (:pid, :uid, :uname, :urank, :msg)";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':pid', $data->program_id);
        $stmt->bindParam(':uid', $userId);
        $stmt->bindParam(':uname', $userName);
        $stmt->bindParam(':urank', $userRank);
        $stmt->bindParam(':msg', $data->message);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(["message" => "Message sent"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Unable to send message"]);
        }
    }

    private function getProgress()
    {
        $programId = $_GET['program_id'] ?? null;
        $userId = $_GET['user_id'] ?? null;

        if (!$programId) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID required"]);
            return;
        }

        $query = "SELECT * FROM cohort_progress WHERE program_id = :pid";
        $params = [':pid' => $programId];

        if ($userId) {
            $query .= " AND user_id = :uid";
            $params[':uid'] = $userId;
        }

        $query .= " ORDER BY updated_at DESC LIMIT 1";

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $progress = [
                'programId' => $row['program_id'],
                'userId' => $row['user_id'],
                'currentDay' => $row['current_day'] ?? 1,
                'performanceScore' => $row['performance_score'] ?? 0,
                'tasks' => $row['tasks'] ? json_decode($row['tasks'], true) : [],
                'lastActive' => $row['updated_at']
            ];
            echo json_encode($progress);
        } else {
            echo json_encode([
                'programId' => $programId,
                'currentDay' => 1,
                'performanceScore' => 0,
                'tasks' => [],
                'lastActive' => null
            ]);
        }
    }

    private function saveProgress()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (!isset($data->program_id) || !isset($data->user_id)) {
            http_response_code(400);
            echo json_encode(["message" => "Program ID and User ID required"]);
            return;
        }

        $programId = $data->program_id;
        $userId = $data->user_id;
        $currentDay = $data->currentDay ?? 1;
        $performanceScore = $data->performanceScore ?? 0;
        $tasks = isset($data->tasks) ? json_encode($data->tasks) : '[]';

        $checkQuery = "SELECT id FROM cohort_progress WHERE program_id = :pid AND user_id = :uid";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':pid', $programId);
        $checkStmt->bindParam(':uid', $userId);
        $checkStmt->execute();

        if ($checkStmt->fetch()) {
            $query = "UPDATE cohort_progress SET 
                      current_day = :day, 
                      performance_score = :score, 
                      tasks = :tasks,
                      updated_at = CURRENT_TIMESTAMP 
                      WHERE program_id = :pid AND user_id = :uid";
        } else {
            $query = "INSERT INTO cohort_progress (program_id, user_id, current_day, performance_score, tasks) 
                      VALUES (:pid, :uid, :day, :score, :tasks)";
        }

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':pid', $programId);
        $stmt->bindParam(':uid', $userId);
        $stmt->bindParam(':day', $currentDay);
        $stmt->bindParam(':score', $performanceScore);
        $stmt->bindParam(':tasks', $tasks);

        if ($stmt->execute()) {
            // Milestone Notifications Trigger
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $ns = new NotificationService($this->db);
                $ns->sendMilestoneNotification($userId, (int) $currentDay);
            } catch (Exception $e) {
                // Non-critical, don't break response
            }

            http_response_code(200);
            echo json_encode(["message" => "Progress saved"]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Failed to save progress"]);
        }
    }
}
?>