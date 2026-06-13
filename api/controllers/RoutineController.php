<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
class RoutineController
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

    public function handleRequest($action, $userId = null, $routineId = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Public endpoints (templates)
        if ($action === 'templates') {
            if ($method === 'GET') {
                $this->getTemplates($routineId);
                return;
            }
        }

        // Protected endpoints require auth
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        switch ($action) {
            case 'generate':
                // POST /api/routines/generate/{userId}
                if ($method === 'POST') {
                    $this->generateAiRoutine($userId);
                }
                break;

            case 'user':
                // /api/routines/user/{userId}
                if ($method === 'GET') {
                    $this->getUserRoutines($userId);
                } elseif ($method === 'POST') {
                    $this->createUserRoutine($userId);
                }
                break;
            // ... cases continue ...

            case 'today':
                // /api/routines/today/{userId}
                if ($method === 'GET') {
                    $this->getTodayRoutines($userId);
                }
                break;

            case 'complete':
                // /api/routines/complete/{routineId}
                if ($method === 'POST') {
                    $this->completeRoutine($routineId);
                }
                break;

            case 'stats':
                // /api/routines/stats/{userId}
                if ($method === 'GET') {
                    $this->getRoutineStats($userId);
                }
                break;

            case 'items':
                // /api/routines/items/{itemId}
                if ($method === 'DELETE') {
                    $this->deleteRoutineItem($userId); // userId passes as first arg because of handleRequest sig
                } elseif ($method === 'PUT') {
                    $this->updateRoutineItem($userId);
                }
                break;

            default:
                // /api/routines/{routineId} - specific routine operations
                if ($routineId && $method === 'GET') {
                    $this->getRoutine($routineId);
                } elseif ($routineId && $method === 'PUT') {
                    $this->updateRoutine($routineId);
                } elseif ($routineId && $method === 'DELETE') {
                    $this->deleteRoutine($routineId);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Endpoint not found"]);
                }
        }
    }

    /**
     * Get all system routine templates
     * GET /api/routines/templates
     * GET /api/routines/templates/{id}
     */
    private function getTemplates($templateId = null)
    {
        if ($templateId) {
            // Get single template with items
            $stmt = $this->db->prepare("
                SELECT * FROM routine_templates WHERE id = :id
            ");
            $stmt->execute([':id' => $templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$template) {
                http_response_code(404);
                echo json_encode(["message" => "Template not found"]);
                return;
            }

            // Get items
            $itemsStmt = $this->db->prepare("
                SELECT * FROM routine_template_items 
                WHERE template_id = :id 
                ORDER BY order_index
            ");
            $itemsStmt->execute([':id' => $templateId]);
            $template['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($template);
        } else {
            // Get all templates
            $cyclePhase = $_GET['cycle_phase'] ?? null;
            $category = $_GET['category'] ?? null;

            $query = "SELECT * FROM routine_templates WHERE is_system = 1";
            $params = [];

            if ($cyclePhase) {
                $query .= " AND (cycle_phase = :phase OR cycle_phase IS NULL)";
                $params[':phase'] = $cyclePhase;
            }

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
    }

    /**
     * Get user's routines
     * GET /api/routines/user/{userId}
     */
    private function getUserRoutines($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT r.*, 
                   (SELECT COUNT(*) FROM user_routine_items WHERE routine_id = r.id) as item_count
            FROM user_routines r
            WHERE r.user_id = :uid
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items for each routine
        foreach ($routines as &$routine) {
            $itemsStmt = $this->db->prepare("
                SELECT * FROM user_routine_items 
                WHERE routine_id = :rid 
                ORDER BY order_index
            ");
            $itemsStmt->execute([':rid' => $routine['id']]);
            $routine['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($routines);
    }

    /**
     * Get today's applicable routines for user
     * GET /api/routines/today/{userId}?cycle_phase=follicular
     */
    private function getTodayRoutines($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $cyclePhase = $_GET['cycle_phase'] ?? null;
        $today = strtolower(date('l')); // monday, tuesday, etc.

        $query = "
            SELECT r.*, 
                   (SELECT COUNT(*) FROM user_routine_items WHERE routine_id = r.id) as item_count
            FROM user_routines r
            WHERE r.user_id = :uid 
            AND r.is_active = 1
            AND (
                r.type = 'daily' 
                OR (r.type = 'weekly' AND r.schedule_days LIKE :today)
            )
        ";
        $params = [':uid' => $userId, ':today' => '%' . $today . '%'];

        if ($cyclePhase) {
            $query .= " AND (r.cycle_phase = :phase OR r.cycle_phase IS NULL)";
            $params[':phase'] = $cyclePhase;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check completion status for today
        $todayDate = date('Y-m-d');
        foreach ($routines as &$routine) {
            $completionStmt = $this->db->prepare("
                SELECT * FROM routine_completions 
                WHERE routine_id = :rid 
                AND DATE(completed_at) = :today
                ORDER BY completed_at DESC
                LIMIT 1
            ");
            $completionStmt->execute([':rid' => $routine['id'], ':today' => $todayDate]);
            $completion = $completionStmt->fetch(PDO::FETCH_ASSOC);
            $routine['completed_today'] = $completion ? true : false;
            $routine['today_completion'] = $completion;

            // Get items
            $itemsStmt = $this->db->prepare("
                SELECT * FROM user_routine_items 
                WHERE routine_id = :rid 
                ORDER BY order_index
            ");
            $itemsStmt->execute([':rid' => $routine['id']]);
            $routine['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($routines);
    }

    /**
     * Create a new user routine (from template or custom)
     * POST /api/routines/user/{userId}
     * Body: { template_id?: string, title: string, type: string, items: [...] }
     */
    private function createUserRoutine($userId)
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

        $routineId = 'routine_' . uniqid();

        // If from template, copy the template items
        $items = $data['items'] ?? [];
        if (!empty($data['template_id'])) {
            $templateItemsStmt = $this->db->prepare("
                SELECT * FROM routine_template_items 
                WHERE template_id = :tid 
                ORDER BY order_index
            ");
            $templateItemsStmt->execute([':tid' => $data['template_id']]);
            $items = $templateItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Insert routine
        $stmt = $this->db->prepare("
            INSERT INTO user_routines (id, user_id, template_id, title, type, schedule_days, cycle_phase)
            VALUES (:id, :uid, :tid, :title, :type, :days, :phase)
        ");
        $stmt->execute([
            ':id' => $routineId,
            ':uid' => $userId,
            ':tid' => $data['template_id'] ?? null,
            ':title' => $data['title'],
            ':type' => $data['type'] ?? 'daily',
            ':days' => isset($data['schedule_days']) ? json_encode($data['schedule_days']) : null,
            ':phase' => $data['cycle_phase'] ?? null
        ]);

        // Insert items
        foreach ($items as $index => $item) {
            $itemId = 'item_' . uniqid();
            $itemStmt = $this->db->prepare("
                INSERT INTO user_routine_items (id, routine_id, title, description, duration_minutes, order_index, icon)
                VALUES (:id, :rid, :title, :desc, :duration, :order, :icon)
            ");
            $itemStmt->execute([
                ':id' => $itemId,
                ':rid' => $routineId,
                ':title' => $item['title'],
                ':desc' => $item['description'] ?? null,
                ':duration' => $item['duration_minutes'] ?? null,
                ':order' => $item['order_index'] ?? $index,
                ':icon' => $item['icon'] ?? null
            ]);
        }

        echo json_encode([
            "message" => "Routine created successfully",
            "routine_id" => $routineId
        ]);
    }

    /**
     * Complete a routine
     * POST /api/routines/complete/{routineId}
     * Body: { items_completed: string[], notes?: string }
     */
    private function completeRoutine($routineId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        // Verify ownership
        $stmt = $this->db->prepare("SELECT * FROM user_routines WHERE id = :id");
        $stmt->execute([':id' => $routineId]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$routine || $routine['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Routine not found or access denied"]);
            return;
        }

        // Count total items
        $itemsStmt = $this->db->prepare("SELECT COUNT(*) as count FROM user_routine_items WHERE routine_id = :rid");
        $itemsStmt->execute([':rid' => $routineId]);
        $totalItems = $itemsStmt->fetch(PDO::FETCH_ASSOC)['count'];

        $itemsCompleted = $data['items_completed'] ?? [];
        $completionPercent = $totalItems > 0 ? round((count($itemsCompleted) / $totalItems) * 100) : 100;

        // Insert completion
        $completionId = 'comp_' . uniqid();
        $insertStmt = $this->db->prepare("
            INSERT INTO routine_completions (id, user_id, routine_id, items_completed, total_items, completion_percent, duration_actual, notes)
            VALUES (:id, :uid, :rid, :items, :total, :percent, :duration, :notes)
        ");
        $insertStmt->execute([
            ':id' => $completionId,
            ':uid' => $this->currentUser['id'],
            ':rid' => $routineId,
            ':items' => json_encode($itemsCompleted),
            ':total' => $totalItems,
            ':percent' => $completionPercent,
            ':duration' => $data['duration_actual'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        echo json_encode([
            "message" => "Routine completed!",
            "completion_id" => $completionId,
            "completion_percent" => $completionPercent
        ]);
    }

    /**
     * Get routine stats for a user
     * GET /api/routines/stats/{userId}
     */
    private function getRoutineStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Total completions
        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM routine_completions WHERE user_id = :uid
        ");
        $totalStmt->execute([':uid' => $userId]);
        $totalCompletions = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Current streak (consecutive days with completions)
        $streakStmt = $this->db->prepare("
            SELECT DATE(completed_at) as date FROM routine_completions 
            WHERE user_id = :uid 
            GROUP BY DATE(completed_at) 
            ORDER BY DATE(completed_at) DESC
        ");
        $streakStmt->execute([':uid' => $userId]);
        $dates = $streakStmt->fetchAll(PDO::FETCH_COLUMN);

        $streak = 0;
        $expectedDate = new DateTime();
        foreach ($dates as $date) {
            if ($date === $expectedDate->format('Y-m-d')) {
                $streak++;
                $expectedDate->modify('-1 day');
            } else {
                break;
            }
        }

        // Weekly completions
        $weekStmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM routine_completions 
            WHERE user_id = :uid 
            AND completed_at >= DATE('now', '-7 days')
        ");
        $weekStmt->execute([':uid' => $userId]);
        $weeklyCompletions = $weekStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Average completion percent
        $avgStmt = $this->db->prepare("
            SELECT AVG(completion_percent) as avg FROM routine_completions WHERE user_id = :uid
        ");
        $avgStmt->execute([':uid' => $userId]);
        $avgCompletion = round($avgStmt->fetch(PDO::FETCH_ASSOC)['avg'] ?? 0);

        echo json_encode([
            'total_completions' => $totalCompletions,
            'current_streak' => $streak,
            'weekly_completions' => $weeklyCompletions,
            'average_completion_percent' => $avgCompletion
        ]);
    }

    /**
     * Get single routine with items
     */
    private function getRoutine($routineId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_routines WHERE id = :id");
        $stmt->execute([':id' => $routineId]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$routine || $routine['user_id'] !== $this->currentUser['id']) {
            http_response_code(404);
            echo json_encode(["message" => "Routine not found"]);
            return;
        }

        $itemsStmt = $this->db->prepare("
            SELECT * FROM user_routine_items WHERE routine_id = :rid ORDER BY order_index
        ");
        $itemsStmt->execute([':rid' => $routineId]);
        $routine['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($routine);
    }

    /**
     * Update routine
     */
    private function updateRoutine($routineId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_routines WHERE id = :id");
        $stmt->execute([':id' => $routineId]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$routine || $routine['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Routine not found or access denied"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $updates = [];
        $params = [':id' => $routineId];

        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = :active";
            $params[':active'] = $data['is_active'] ? 1 : 0;
        }
        if (isset($data['cycle_phase'])) {
            $updates[] = "cycle_phase = :phase";
            $params[':phase'] = $data['cycle_phase'];
        }
        if (isset($data['schedule_days'])) {
            $updates[] = "schedule_days = :days";
            $params[':days'] = json_encode($data['schedule_days']);
        }

        if (!empty($updates)) {
            $query = "UPDATE user_routines SET " . implode(', ', $updates) . " WHERE id = :id";
            $updateStmt = $this->db->prepare($query);
            $updateStmt->execute($params);
        }

        echo json_encode(["message" => "Routine updated"]);
    }

    private function generateAiRoutine($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Check active cohort/program (Challenge)
        $progStmt = $this->db->prepare("
            SELECT p.*, c.title as program_title, c.category, c.objectives
            FROM cohort_progress p
            JOIN cohorts c ON p.program_id = c.id
            WHERE p.user_id = :uid
            ORDER BY p.updated_at DESC 
            LIMIT 1
        ");
        $progStmt->execute([':uid' => $userId]);
        $activeProgram = $progStmt->fetch(PDO::FETCH_ASSOC);

        // If no active challenge, we still allow generation but with generic context
        $programTitle = $activeProgram['program_title'] ?? 'General Wellness';
        $programCategory = $activeProgram['category'] ?? 'Wellness';
        $programObjectives = $activeProgram['objectives'] ?? 'Maintain healthy habits and balanced lifestyle';

        try {
            // Use AIController with admin-configured provider
            require_once __DIR__ . '/AIController.php';
            $aiController = new AIController($this->db);
            $context = $aiController->getUserContext($userId);

            // Build AI prompt for routine generation
            $prompt = "Generate a personalized daily wellness routine for {$context['user_name']}.

USER CONTEXT:
- Focus: $programTitle (Category: $programCategory)
- Objectives: $programObjectives
- Current Mood: {$context['mood_today']}
- Energy Level: {$context['energy']}/10
- Sleep Last Night: {$context['sleep_hours']} hours ({$context['sleep_quality']})
- Cycle Phase: {$context['cycle_phase']}
- Day in Program: " . ($activeProgram ? $context['cohort_day'] : 'N/A') . "

Generate 4-6 routine items in this exact JSON format:
{
  \"title\": \"Personalized Morning Routine\",
  \"items\": [
    {\"title\": \"Activity Name\", \"duration_minutes\": 5, \"icon\": \"fa-icon-name\", \"description\": \"Brief description\"}
  ]
}

RULES:
1. Adapt intensity based on energy and sleep quality
2. If cycle_phase is 'menstrual' or 'luteal', prefer gentle activities
3. If cycle_phase is 'follicular' or 'ovulatory', include higher intensity options
4. Include a mix of movement, mindfulness, and nutrition tasks
5. Keep total duration under 45 minutes
6. Use Font Awesome 6 icon names (fa-person-running, fa-spa, fa-glass-water, etc.)

Return ONLY the JSON, no markdown formatting.";

            // Call AI via admin settings
            $systemPrompt = "You are a wellness routine generator. Create personalized daily routines based on user data. Always respond with valid JSON only.";

            // Make internal AI call
            $aiResponse = $this->callAIForRoutine($aiController, $prompt, $systemPrompt, $userId);

            // Parse AI response
            $routineData = json_decode($aiResponse, true);

            if (!$routineData || !isset($routineData['items'])) {
                // Fallback to rule-based if AI fails
                $routineData = $this->generateFallbackRoutine(
                    $activeProgram ?: ['category' => 'general', 'program_title' => 'Daily Wellness'],
                    $context
                );
            }

            // Save Generated Routine
            $routineId = 'ai_' . uniqid();
            $routineTitle = $routineData['title'] ?? "AI Daily: " . $activeProgram['program_title'];

            $stmt = $this->db->prepare("
                INSERT INTO user_routines (id, user_id, title, type, is_active, is_generated, generation_date, cycle_phase)
                VALUES (:id, :uid, :title, 'daily', 1, 1, CURRENT_DATE, :phase)
            ");
            $stmt->execute([
                ':id' => $routineId,
                ':uid' => $userId,
                ':title' => $routineTitle,
                ':phase' => $context['cycle_phase'] !== 'unknown' ? $context['cycle_phase'] : null
            ]);

            $items = $routineData['items'] ?? [];
            foreach ($items as $idx => $item) {
                $this->db->prepare("
                    INSERT INTO user_routine_items (id, routine_id, title, description, duration_minutes, order_index, icon)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                            'item_' . uniqid(),
                            $routineId,
                            $item['title'] ?? 'Activity',
                            $item['description'] ?? null,
                            $item['duration_minutes'] ?? 5,
                            $idx,
                            $item['icon'] ?? 'fa-check'
                        ]);
            }

            echo json_encode([
                "message" => "AI Routine Generated",
                "routine_id" => $routineId,
                "program" => $activeProgram['program_title'],
                "items_count" => count($items),
                "ai_generated" => true
            ]);

        } catch (Exception $e) {
            // Fallback to rule-based generation
            error_log("AI Routine generation failed: " . $e->getMessage());
            $this->generateFallbackRoutineAndSave($userId, $activeProgram);
        }
    }

    /**
     * Call AI using admin-configured provider
     */
    private function callAIForRoutine($aiController, $prompt, $systemPrompt, $userId)
    {
        // Get admin settings
        $provider = $this->getSetting('ai_provider') ?? 'gemini';
        $apiKey = $this->getApiKeyForProvider($provider);

        if (empty($apiKey)) {
            throw new Exception("No API key configured for provider: $provider");
        }

        // Build request based on provider
        $model = $this->getSetting('ai_default_model') ?? 'gemini-1.5-flash';

        switch ($provider) {
            case 'openai':
                return $this->callOpenAI($prompt, $systemPrompt, $apiKey, $model);
            case 'openrouter':
                return $this->callOpenRouter($prompt, $systemPrompt, $apiKey, $model);
            default: // gemini
                return $this->callGemini($prompt, $systemPrompt, $apiKey, $model);
        }
    }

    private function getSetting($key)
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : null;
    }

    private function getApiKeyForProvider($provider)
    {
        $keyMap = [
            'gemini' => 'gemini_api_key',
            'openai' => 'openai_api_key',
            'openrouter' => 'openrouter_api_key'
        ];
        return $this->getSetting($keyMap[$provider] ?? 'gemini_api_key');
    }

    private function callGemini($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $data = [
            "contents" => [["role" => "user", "parts" => [["text" => $systemPrompt . "\n\n" . $prompt]]]],
            "generationConfig" => ["temperature" => 0.7, "maxOutputTokens" => 1024]
        ];

        $response = $this->makeAIRequest($url, $data);
        $decoded = json_decode($response, true);
        return $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function callOpenAI($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://api.openai.com/v1/chat/completions";
        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7
        ];

        $response = $this->makeAIRequest($url, $data, ["Authorization: Bearer " . $apiKey]);
        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }

    private function callOpenRouter($prompt, $systemPrompt, $apiKey, $model)
    {
        $url = "https://openrouter.ai/api/v1/chat/completions";
        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ]
        ];

        $headers = [
            "Authorization: Bearer " . $apiKey,
            "HTTP-Referer: https://zenithwellness.app"
        ];

        $response = $this->makeAIRequest($url, $data, $headers);
        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }

    private function makeAIRequest($url, $data, $customHeaders = [])
    {
        $headers = "Content-Type: application/json\r\n";
        foreach ($customHeaders as $h) {
            $headers .= $h . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($data),
                'timeout' => 30
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result ?: '';
    }

    /**
     * Generate fallback routine when AI fails
     */
    private function generateFallbackRoutine($activeProgram, $context)
    {
        $items = [];
        $category = strtolower($activeProgram['category'] ?? 'wellness');
        $isLowEnergy = $context['energy'] < 5 || $context['sleep_hours'] < 6;
        $isLutealOrMenstrual = in_array($context['cycle_phase'], ['luteal', 'menstrual']);

        // Always start with hydration
        $items[] = ['title' => 'Morning Hydration', 'duration_minutes' => 2, 'icon' => 'fa-glass-water', 'description' => 'Drink a full glass of water'];

        if (strpos($category, 'fitness') !== false) {
            if ($isLowEnergy || $isLutealOrMenstrual) {
                $items[] = ['title' => 'Gentle Stretching', 'duration_minutes' => 10, 'icon' => 'fa-person-praying', 'description' => 'Light stretching for energy'];
                $items[] = ['title' => 'Light Walk', 'duration_minutes' => 15, 'icon' => 'fa-person-walking', 'description' => 'Easy paced walk'];
            } else {
                $items[] = ['title' => 'Dynamic Warmup', 'duration_minutes' => 5, 'icon' => 'fa-person-running', 'description' => 'Get your blood flowing'];
                $items[] = ['title' => 'Strength Training', 'duration_minutes' => 20, 'icon' => 'fa-dumbbell', 'description' => 'Focus on major muscle groups'];
            }
        } elseif (strpos($category, 'mindfulness') !== false) {
            $items[] = ['title' => 'Deep Breathing', 'duration_minutes' => 5, 'icon' => 'fa-lungs', 'description' => 'Box breathing technique'];
            $items[] = ['title' => 'Meditation', 'duration_minutes' => 10, 'icon' => 'fa-spa', 'description' => 'Quiet the mind'];
            $items[] = ['title' => 'Gratitude Journaling', 'duration_minutes' => 5, 'icon' => 'fa-pen', 'description' => 'Write 3 things you are grateful for'];
        } else {
            // General wellness
            $items[] = ['title' => 'Morning Stretch', 'duration_minutes' => 10, 'icon' => 'fa-person-praying', 'description' => 'Wake up your body'];
            $items[] = ['title' => 'Mindful Moment', 'duration_minutes' => 5, 'icon' => 'fa-brain', 'description' => 'Set your intention for the day'];
        }

        // Add nutrition task
        $items[] = ['title' => 'Healthy Breakfast', 'duration_minutes' => 15, 'icon' => 'fa-bowl-food', 'description' => 'Fuel your body with nutrients'];

        return [
            'title' => 'AI Daily: ' . $activeProgram['program_title'],
            'items' => $items
        ];
    }

    private function generateFallbackRoutineAndSave($userId, $activeProgram)
    {
        // Ensure activeProgram is an array with defaults
        $activeProgram = $activeProgram ?: [
            'program_title' => 'Daily Wellness',
            'category' => 'Wellness',
            'objectives' => 'Maintain health'
        ];

        $context = ['energy' => 5, 'sleep_hours' => 7, 'cycle_phase' => 'unknown'];
        $routineData = $this->generateFallbackRoutine($activeProgram, $context);

        $routineId = 'ai_' . uniqid();
        $stmt = $this->db->prepare("
            INSERT INTO user_routines (id, user_id, title, type, is_active, is_generated, generation_date)
            VALUES (:id, :uid, :title, 'daily', 1, 1, CURRENT_DATE)
        ");
        $stmt->execute([
            ':id' => $routineId,
            ':uid' => $userId,
            ':title' => $routineData['title']
        ]);

        foreach ($routineData['items'] as $idx => $item) {
            $this->db->prepare("
                INSERT INTO user_routine_items (id, routine_id, title, description, duration_minutes, order_index, icon)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                        'item_' . uniqid(),
                        $routineId,
                        $item['title'],
                        $item['description'] ?? null,
                        $item['duration_minutes'],
                        $idx,
                        $item['icon']
                    ]);
        }

        echo json_encode([
            "message" => "AI Routine Generated (Fallback)",
            "routine_id" => $routineId,
            "program" => $activeProgram['program_title'],
            "items_count" => count($routineData['items']),
            "ai_generated" => false
        ]);
    }

    /**
     * Delete routine
     */
    private function deleteRoutine($routineId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_routines WHERE id = :id");
        $stmt->execute([':id' => $routineId]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$routine || $routine['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Routine not found or access denied"]);
            return;
        }

        $deleteStmt = $this->db->prepare("DELETE FROM user_routines WHERE id = :id");
        $deleteStmt->execute([':id' => $routineId]);

        echo json_encode(["message" => "Routine deleted"]);
    }

    /**
     * Delete a single routine item
     * DELETE /api/routines/items/{itemId}
     */
    private function deleteRoutineItem($itemId)
    {
        // specific check: verify the item belongs to a routine owned by current user
        $stmt = $this->db->prepare("
            SELECT r.user_id 
            FROM user_routine_items i
            JOIN user_routines r ON i.routine_id = r.id
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || $result['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Item not found or access denied"]);
            return;
        }

        $del = $this->db->prepare("DELETE FROM user_routine_items WHERE id = :id");
        $del->execute([':id' => $itemId]);

        echo json_encode(["message" => "Item deleted"]);
    }

    /**
     * Update a single routine item
     * PUT /api/routines/items/{itemId}
     */
    private function updateRoutineItem($itemId)
    {
        $stmt = $this->db->prepare("
            SELECT r.user_id 
            FROM user_routine_items i
            JOIN user_routines r ON i.routine_id = r.id
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || $result['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Item not found or access denied"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $updates = [];
        $params = [':id' => $itemId];

        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['duration_minutes'])) {
            $updates[] = "duration_minutes = :dur";
            $params[':dur'] = $data['duration_minutes'];
        }

        if (!empty($updates)) {
            $sql = "UPDATE user_routine_items SET " . implode(', ', $updates) . " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }

        echo json_encode(["message" => "Item updated"]);
    }
}
?>