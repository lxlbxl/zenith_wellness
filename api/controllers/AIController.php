<?php
class AIController
{
    private $db;
    private $encryptionKey;

    public function __construct($db = null)
    {
        $this->db = $db ?? (new Database())->getConnection();
        $this->encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'zenith_default_key_change_me_32ch';
    }

    // Get setting from DB (with decryption support)
    private function getSetting($key)
    {
        $stmt = $this->db->prepare("SELECT setting_value, is_encrypted FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Fallback to constants if no DB entry
            $constName = strtoupper($key);
            return defined($constName) ? constant($constName) : null;
        }
        return $row['is_encrypted'] ? $this->decrypt($row['setting_value']) : $row['setting_value'];
    }

    private function decrypt($value)
    {
        if (empty($value))
            return $value;
        try {
            $parts = explode('::', base64_decode($value), 2);
            if (count($parts) !== 2)
                return $value;
            list($iv, $encrypted) = $parts;
            return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        } catch (Exception $e) {
            return $value;
        }
    }

    // Get current AI provider from settings
    private function getProvider()
    {
        return $this->getSetting('ai_provider') ?? 'gemini';
    }

    // Get current model from settings
    private function getModel()
    {
        return $this->getSetting('ai_default_model') ?? 'gemini-1.5-flash';
    }

    // Get API key for the given provider
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

    public function handleRequest($action)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET' && $action === 'prompts') {
            $this->getPrompts();
            return;
        }

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed"]);
            return;
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid JSON"]);
            return;
        }

        switch ($action) {
            case 'chat':
                $this->handleChat($input);
                break;
            case 'analyze-meal':
                $this->handleMealAnalysis($input);
                break;
            default:
                http_response_code(404);
                echo json_encode(["message" => "AI action not found"]);
        }
    }

    private function getPrompts()
    {
        $stmt = $this->db->query("SELECT * FROM ai_prompts");
        $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return full prompts array for admin panel
        echo json_encode($prompts);
    }

    private function handleChat($input)
    {
        // Use provider from input or fall back to settings
        $provider = $input['provider'] ?? $this->getProvider();
        $prompt = $input['message'];
        $systemPrompt = $input['system'] ?? "You are a helpful wellness coach.";
        $config = $input['config'] ?? [];
        $userId = $input['userId'] ?? null;
        $agentName = $input['agent'] ?? null;

        // Check for agent-specific prompt
        if ($agentName) {
            $agentPrompt = $this->getAgentPrompt($agentName);
            if ($agentPrompt) {
                $systemPrompt = $agentPrompt['system_prompt'];
                $agentConfig = json_decode($agentPrompt['model_config'], true) ?? [];
                $config = array_merge($agentConfig, $config);

                // Use agent-specific provider if configured
                if (!empty($agentConfig['provider'])) {
                    $provider = $agentConfig['provider'];
                }

                // Inject user context variables into prompt
                if ($userId) {
                    $context = $this->getUserContext($userId);

                    // For memory-enabled agents, fetch recent conversation history
                    if (in_array($agentName, ['coach_sara', 'companion'])) {
                        try {
                            $chatHistory = $this->getConversationHistory($userId, $agentName, 5);
                            if (!empty($chatHistory)) {
                                $historyText = "\n\n## RECENT CONVERSATION HISTORY\n";
                                foreach ($chatHistory as $msg) {
                                    $role = $msg['role'] === 'user' ? 'User' : 'You';
                                    $historyText .= "**{$role}**: " . substr($msg['content'], 0, 200) . "...\n";
                                }
                                $systemPrompt .= $historyText;
                            }
                        } catch (Exception $e) {
                            // Silently ignore - conversation history is optional
                            error_log("Conversation history failed: " . $e->getMessage());
                        }
                    }

                    $systemPrompt = $this->injectVariables($systemPrompt, $context);
                }
            }
        }

        $apiKey = $this->getApiKey($provider);
        if (empty($apiKey)) {
            echo json_encode(["error" => "API key not configured for provider: $provider"]);
            return;
        }

        // Save user message to conversation history (wrapped in try-catch)
        if ($userId && $agentName && in_array($agentName, ['coach_sara', 'companion'])) {
            try {
                $this->saveConversationMessage($userId, $agentName, 'user', $prompt);
            } catch (Exception $e) {
                error_log("Failed to save user message: " . $e->getMessage());
            }
        }

        // Call AI provider and capture response
        ob_start();
        switch ($provider) {
            case 'openrouter':
                $this->callOpenRouter($prompt, $systemPrompt, $apiKey, $config);
                break;
            case 'openai':
                $this->callOpenAI($prompt, $systemPrompt, $apiKey, $config);
                break;
            default:
                $this->callGemini($prompt, $systemPrompt, $apiKey, $config);
        }
        $response = ob_get_contents();
        ob_end_clean();

        // Save AI response to conversation history (wrapped in try-catch)
        if ($userId && $agentName && in_array($agentName, ['coach_sara', 'companion'])) {
            try {
                $responseData = json_decode($response, true);
                $aiText = $this->extractAIResponseText($responseData);
                if ($aiText) {
                    $this->saveConversationMessage($userId, $agentName, 'assistant', $aiText);
                }
            } catch (Exception $e) {
                error_log("Failed to save AI response: " . $e->getMessage());
            }
        }

        echo $response;
    }

    /**
     * Get recent conversation history for an agent
     */
    private function getConversationHistory($userId, $agentName, $limit = 5)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT role, content, created_at FROM ai_conversations 
                WHERE user_id = :uid AND agent_name = :agent 
                ORDER BY created_at DESC LIMIT :lim
            ");
            $stmt->bindValue(':uid', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':agent', $agentName, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Save a conversation message to history
     */
    private function saveConversationMessage($userId, $agentName, $role, $content)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_conversations (id, user_id, agent_name, role, content) 
                VALUES (:id, :uid, :agent, :role, :content)
            ");
            $stmt->execute([
                ':id' => uniqid('conv_'),
                ':uid' => $userId,
                ':agent' => $agentName,
                ':role' => $role,
                ':content' => substr($content, 0, 5000) // Limit content size
            ]);
        } catch (Exception $e) {
            error_log("Failed to save conversation: " . $e->getMessage());
        }
    }

    /**
     * Extract text from AI response (handles different provider formats)
     */
    private function extractAIResponseText($response)
    {
        if (!$response)
            return null;

        // Gemini format
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }
        // OpenAI/OpenRouter format
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
        return null;
    }

    private function getAgentPrompt($agentName)
    {
        $stmt = $this->db->prepare("SELECT * FROM ai_prompts WHERE agent_name = :name AND is_active = 1");
        $stmt->execute([':name' => $agentName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch comprehensive user context for variable injection
     * Enhanced with 60+ personalization variables for deeply personal AI responses
     */
    public function getUserContext($userId)
    {
        // Time-aware context
        $now = new DateTime();
        $hour = (int) $now->format('H');
        $timeOfDay = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        $dayOfWeek = $now->format('l');

        $context = [
            // User Identity
            'user_name' => 'Friend',
            'persona' => 'newbie',
            'join_date' => '',
            'member_tier' => 'free',

            // Time Context
            'local_time' => $now->format('g:i A'),
            'time_of_day' => $timeOfDay,
            'day_of_week' => $dayOfWeek,
            'greeting_time' => $timeOfDay === 'morning' ? 'Good morning' : ($timeOfDay === 'afternoon' ? 'Good afternoon' : 'Good evening'),

            // Streaks & Points
            'streak_days' => 0,
            'streak' => 0,
            'current_streak' => 0,
            'points' => 0,

            // Today's Stats
            'focus_minutes' => 0,
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fats' => 0,
            'fiber' => 0,
            'mood' => 'neutral',
            'mood_today' => 'neutral',
            'energy' => 5,
            'energy_level' => 5,

            // Sleep Data
            'sleep_hours' => 7,
            'sleep_quality' => 'good',
            'sleep_last_night' => 7,

            // Workout Data
            'workout_completed_today' => false,
            'workout_type' => 'none',
            'workouts_this_week' => 0,
            'last_workout_date' => 'never',

            // Hydration & Steps
            'water_glasses' => 0,
            'step_count' => 0,

            // Cycle Phase
            'cycle_phase' => 'unknown',
            'cycle_day' => 0,

            // Cohort/Program
            'active_cohort' => 'None',
            'cohort_day' => 0,
            'cohort_name' => 'None',
            'cohort_total_days' => 21,
            'current_phase' => 'Week 1',
            'completion_rate' => 0,
            'current_week' => 1,
            'total_weeks' => 3,
            'days_remaining' => 21,
            'cohort_objectives' => '',

            // Cohort Rankings
            'cohort_rank' => 0,
            'cohort_size' => 1,
            'pulse_index' => 50,
            'vs_cohort_avg' => 'on track',

            // Weekly Aggregates
            'weekly_avg_mood' => 5,
            'weekly_avg_energy' => 5,
            'weekly_focus_total' => 0,
            'weekly_workouts' => 0,
            'weekly_meals_logged' => 0,
            'sleep_avg' => 7,
            'focus_avg' => 0,
            'energy_trend' => 'stable',
            'dominant_mood_week' => 'neutral',

            // Habits
            'active_habits_count' => 0,
            'habits_completed_today' => 0,
            'habit_completion_rate' => 0,
            'habit_completion_rates' => '{}',

            // Journal
            'journal_streak' => 0,
            'journal_entries_week' => 0,
            'last_journal_mood' => 'neutral',
            'word_count' => 0,

            // Achievements
            'recent_achievements' => '',

            // Nutrition Goals
            'daily_calorie_goal' => 1800,
            'daily_protein_goal' => 100,
            'dietary_restrictions' => 'none',
            'meals_today' => 0,
            'running_calories' => 0,
            'running_protein' => 0,
            'calories_remaining' => 1800,
            'protein_remaining' => 100,

            // Progress Insights
            'recent_wins' => '',
            'areas_to_improve' => '',
            'yesterday_summary' => '',
            'weight_trend' => 'stable',

            // Conversation Memory
            'last_coach_topic' => '',
            'conversation_count' => 0
        ];

        try {
            // Get user info with join date
            $stmt = $this->db->prepare("SELECT name, persona, role, created_at FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $context['user_name'] = $user['name'] ?? 'Friend';
                $context['persona'] = $user['persona'] ?? 'newbie';
                $context['member_tier'] = $user['role'] === 'admin' ? 'founder' : 'member';
                $context['join_date'] = $user['created_at'] ? date('M j, Y', strtotime($user['created_at'])) : '';
            }

            // Get user stats
            $stmt = $this->db->prepare("SELECT * FROM user_stats WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stats) {
                $context['streak_days'] = $stats['streak'] ?? 0;
                $context['streak'] = $stats['streak'] ?? 0;
                $context['current_streak'] = $stats['streak'] ?? 0;
                $context['focus_minutes'] = $stats['focus_minutes'] ?? 0;
                $context['focus_avg'] = $stats['focus_minutes'] ?? 0;
                $context['calories'] = $stats['calories'] ?? 0;
                $context['protein'] = $stats['protein'] ?? 0;
                $context['carbs'] = $stats['carbs'] ?? 0;
                $context['fats'] = $stats['fats'] ?? 0;
                $context['mood'] = $stats['mood'] ?? 'neutral';
                $context['mood_today'] = $stats['mood'] ?? 'neutral';
                $context['energy'] = $stats['energy'] ?? 5;
                $context['energy_level'] = $stats['energy'] ?? 5;
            }

            // Get active cohort enrollment with rankings
            $stmt = $this->db->prepare("
                SELECT cp.*, c.title, c.duration_days, c.objectives
                FROM cohort_progress cp
                JOIN cohorts c ON cp.cohort_id = c.id
                WHERE cp.user_id = :id AND cp.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cohort) {
                $context['active_cohort'] = $cohort['title'] ?? 'None';
                $context['cohort_name'] = $cohort['title'] ?? 'None';
                $context['cohort_total_days'] = $cohort['duration_days'] ?? 21;
                $context['cohort_objectives'] = $cohort['objectives'] ?? '';

                // Calculate day in program
                $startDate = new DateTime($cohort['joined_at'] ?? 'now');
                $context['cohort_day'] = $startDate->diff($now)->days + 1;
                $context['days_remaining'] = max(0, $context['cohort_total_days'] - $context['cohort_day']);
                $context['current_week'] = ceil($context['cohort_day'] / 7);
                $context['total_weeks'] = ceil($context['cohort_total_days'] / 7);
                $context['current_phase'] = 'Week ' . $context['current_week'];
                $context['completion_rate'] = round(($context['cohort_day'] / $context['cohort_total_days']) * 100);

                // Get cohort size and rank
                $cohortId = $cohort['cohort_id'];
                $stmt = $this->db->prepare("SELECT COUNT(*) as size FROM cohort_progress WHERE cohort_id = :cid AND status = 'active'");
                $stmt->execute([':cid' => $cohortId]);
                $sizeRow = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['cohort_size'] = $sizeRow['size'] ?? 1;

                // Simple rank calculation based on points
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) + 1 as rank FROM cohort_progress cp2
                    JOIN user_points up ON cp2.user_id = up.user_id
                    WHERE cp2.cohort_id = :cid AND cp2.status = 'active'
                    AND up.total_points > COALESCE((SELECT total_points FROM user_points WHERE user_id = :uid), 0)
                ");
                $stmt->execute([':cid' => $cohortId, ':uid' => $userId]);
                $rankRow = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['cohort_rank'] = $rankRow['rank'] ?? 1;

                // Pulse index (engagement score 0-100)
                $baseScore = min(100, ($context['streak'] * 5) + ($context['completion_rate'] / 2));
                $context['pulse_index'] = round($baseScore);
            }

            // Get cycle phase from recent log
            $stmt = $this->db->prepare("
                SELECT cycle_day, flow_intensity FROM cycle_logs 
                WHERE user_id = :id ORDER BY log_date DESC LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cycle) {
                $cycleDay = $cycle['cycle_day'] ?? 1;
                $context['cycle_day'] = $cycleDay;
                if ($cycleDay <= 5)
                    $context['cycle_phase'] = 'menstrual';
                elseif ($cycleDay <= 13)
                    $context['cycle_phase'] = 'follicular';
                elseif ($cycleDay <= 17)
                    $context['cycle_phase'] = 'ovulatory';
                else
                    $context['cycle_phase'] = 'luteal';
            }

            // Get user points
            $stmt = $this->db->prepare("SELECT total_points FROM user_points WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            $points = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($points) {
                $context['points'] = $points['total_points'] ?? 0;
            }

            // Get journal stats (last 7 days)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count, 
                       (SELECT mood FROM journal_entries WHERE user_id = :id2 ORDER BY created_at DESC LIMIT 1) as last_mood
                FROM journal_entries 
                WHERE user_id = :id AND created_at >= datetime('now', '-7 days')
            ");
            $stmt->execute([':id' => $userId, ':id2' => $userId]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);
            $context['journal_entries_week'] = $journal['count'] ?? 0;
            $context['journal_streak'] = $journal['count'] ?? 0;
            $context['last_journal_mood'] = $journal['last_mood'] ?? 'neutral';

            // Get recent achievements
            $stmt = $this->db->prepare("
                SELECT a.name FROM user_achievements ua
                JOIN achievements a ON ua.achievement_id = a.id
                WHERE ua.user_id = :id
                ORDER BY ua.earned_at DESC LIMIT 3
            ");
            $stmt->execute([':id' => $userId]);
            $achievements = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $context['recent_achievements'] = implode(', ', $achievements) ?: 'None yet';
            $context['recent_wins'] = $context['recent_achievements'];

            // Get today's meal totals
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count, 
                       COALESCE(SUM(calories), 0) as cal, 
                       COALESCE(SUM(protein), 0) as prot,
                       COALESCE(SUM(carbs), 0) as carbs,
                       COALESCE(SUM(fats), 0) as fats
                FROM meal_logs WHERE user_id = :id AND DATE(logged_at) = DATE('now')
            ");
            $stmt->execute([':id' => $userId]);
            $meals = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($meals) {
                $context['meals_today'] = $meals['count'] ?? 0;
                $context['running_calories'] = $meals['cal'] ?? 0;
                $context['running_protein'] = $meals['prot'] ?? 0;
                $context['calories_remaining'] = max(0, $context['daily_calorie_goal'] - $context['running_calories']);
                $context['protein_remaining'] = max(0, $context['daily_protein_goal'] - $context['running_protein']);
            }

            // Get sleep data (last night) - wrapped in try-catch for optional table
            try {
                $stmt = $this->db->prepare("
                    SELECT hours, quality FROM sleep_logs 
                    WHERE user_id = :id ORDER BY log_date DESC LIMIT 1
                ");
                $stmt->execute([':id' => $userId]);
                $sleep = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sleep) {
                    $context['sleep_hours'] = $sleep['hours'] ?? 7;
                    $context['sleep_last_night'] = $sleep['hours'] ?? 7;
                    $context['sleep_quality'] = $sleep['quality'] ?? 'good';
                }

                // Get weekly sleep average
                $stmt = $this->db->prepare("
                    SELECT AVG(hours) as avg_hours FROM sleep_logs 
                    WHERE user_id = :id AND log_date >= date('now', '-7 days')
                ");
                $stmt->execute([':id' => $userId]);
                $sleepAvg = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['sleep_avg'] = round($sleepAvg['avg_hours'] ?? 7, 1);
            } catch (Exception $e) {
                // sleep_logs table may not exist - use defaults
            }

            // Get workout data - wrapped in try-catch for optional table
            try {
                $stmt = $this->db->prepare("
                    SELECT workout_type FROM workout_logs 
                    WHERE user_id = :id AND DATE(logged_at) = DATE('now') LIMIT 1
                ");
                $stmt->execute([':id' => $userId]);
                $workout = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['workout_completed_today'] = $workout ? true : false;
                $context['workout_type'] = $workout['workout_type'] ?? 'none';

                // Get weekly workouts
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM workout_logs 
                    WHERE user_id = :id AND logged_at >= datetime('now', '-7 days')
                ");
                $stmt->execute([':id' => $userId]);
                $weeklyWorkouts = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['workouts_this_week'] = $weeklyWorkouts['count'] ?? 0;
                $context['weekly_workouts'] = $weeklyWorkouts['count'] ?? 0;
            } catch (Exception $e) {
                // workout_logs table may not exist - use defaults
            }

            // Get habit stats - wrapped in try-catch for optional table
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as total FROM user_habits WHERE user_id = :id AND is_active = 1
                ");
                $stmt->execute([':id' => $userId]);
                $habits = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['active_habits_count'] = $habits['total'] ?? 0;

                // Get today's habit completions
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as completed FROM habit_logs 
                    WHERE user_id = :id AND DATE(logged_at) = DATE('now')
                ");
                $stmt->execute([':id' => $userId]);
                $habitLogs = $stmt->fetch(PDO::FETCH_ASSOC);
                $context['habits_completed_today'] = $habitLogs['completed'] ?? 0;

                if ($context['active_habits_count'] > 0) {
                    $context['habit_completion_rate'] = round(($context['habits_completed_today'] / $context['active_habits_count']) * 100);
                }
            } catch (Exception $e) {
                // habit tables may not exist - use defaults
            }

            // Weekly mood/energy aggregates
            $stmt = $this->db->prepare("
                SELECT AVG(CASE mood 
                    WHEN 'ecstatic' THEN 10
                    WHEN 'happy' THEN 8
                    WHEN 'neutral' THEN 5
                    WHEN 'tired' THEN 4
                    WHEN 'stressed' THEN 3
                    WHEN 'down' THEN 2
                    ELSE 5 END) as avg_mood
                FROM journal_entries 
                WHERE user_id = :id AND created_at >= datetime('now', '-7 days')
            ");
            $stmt->execute([':id' => $userId]);
            $moodAvg = $stmt->fetch(PDO::FETCH_ASSOC);
            $context['weekly_avg_mood'] = round($moodAvg['avg_mood'] ?? 5, 1);

            // Determine energy trend
            $context['energy_trend'] = $context['energy'] > 6 ? 'rising' : ($context['energy'] < 4 ? 'low' : 'stable');

        } catch (Exception $e) {
            // Log error but continue with defaults
            error_log("Failed to fetch user context: " . $e->getMessage());
        }

        return $context;
    }

    /**
     * Replace {{variable}} placeholders with actual values
     */
    private function injectVariables($template, $variables)
    {
        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    private function handleMealAnalysis($input)
    {
        if (!isset($input['image'])) {
            http_response_code(400);
            echo json_encode(["message" => "Image required"]);
            return;
        }
        $base64Image = $input['image'];

        // Use meal analyzer agent prompt if available
        $agentPrompt = $this->getAgentPrompt('meal_analyzer');
        $prompt = $agentPrompt ? $agentPrompt['system_prompt'] : "Analyze this meal and provide nutritional information.";
        $prompt .= "\n\n" . ($input['prompt'] ?? "Analyze this meal.");

        $apiKey = $this->getApiKey('gemini');
        $this->callGeminiVision($base64Image, $prompt, $apiKey);
    }

    private function callGemini($prompt, $systemPrompt, $apiKey, $config = [])
    {
        $model = $config['model'] ?? $this->getModel();
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $data = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [["text" => $systemPrompt . "\n\nUser: " . $prompt]]
                ]
            ]
        ];

        // Add generation config
        $genConfig = [];
        if (isset($config['temperature']))
            $genConfig['temperature'] = $config['temperature'];
        if (isset($config['maxTokens']))
            $genConfig['maxOutputTokens'] = $config['maxTokens'];
        if (!empty($genConfig))
            $data['generationConfig'] = $genConfig;

        $response = $this->makeRequest($url, $data);
        echo $response;
    }

    private function callGeminiVision($base64Image, $prompt, $apiKey)
    {
        $model = $this->getModel();
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $base64Image = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);

        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt],
                        [
                            "inline_data" => [
                                "mime_type" => "image/jpeg",
                                "data" => $base64Image
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->makeRequest($url, $data);
        echo $response;
    }

    private function callOpenAI($prompt, $systemPrompt, $apiKey, $config = [])
    {
        $url = "https://api.openai.com/v1/chat/completions";
        $model = $config['model'] ?? 'gpt-3.5-turbo';

        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ]
        ];

        if (isset($config['temperature']))
            $data['temperature'] = $config['temperature'];
        if (isset($config['maxTokens']))
            $data['max_tokens'] = $config['maxTokens'];

        $headers = ["Authorization: Bearer " . $apiKey];
        $response = $this->makeRequest($url, $data, $headers);
        echo $response;
    }

    private function callOpenRouter($prompt, $systemPrompt, $apiKey, $config = [])
    {
        $url = "https://openrouter.ai/api/v1/chat/completions";
        $model = $config['model'] ?? 'openai/gpt-3.5-turbo';

        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $prompt]
            ]
        ];

        if (isset($config['temperature']))
            $data['temperature'] = $config['temperature'];
        if (isset($config['maxTokens']))
            $data['max_tokens'] = $config['maxTokens'];

        $headers = [
            "Authorization: Bearer " . $apiKey,
            "HTTP-Referer: https://zenithwellness.app",
            "X-Title: Zenith Wellness"
        ];

        $response = $this->makeRequest($url, $data, $headers);
        echo $response;
    }

    private function makeRequest($url, $data, $customHeaders = [])
    {
        // Build headers array for stream context
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
                'ignore_errors' => true // Get response body even on error status codes
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            return json_encode(["error" => "HTTP request failed: " . ($error['message'] ?? 'Unknown error')]);
        }

        return $result;
    }
}
?>