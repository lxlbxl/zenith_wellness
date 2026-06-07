<?php
class SettingsController
{
    private $db;
    private $currentUser;
    private $encryptionKey;

    public function __construct($db)
    {
        $this->db = $db;
        // Use a constant or environment variable in production
        $this->encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'zenith_default_key_change_me_32ch';
    }

    public function handleRequest($action, $key = null)
    {
        // fetch-models endpoint doesn't require admin for read-only model list
        if ($action === 'fetch-models' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->fetchModels();
            return;
        }

        if (!$this->verifyAdmin()) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        if ($action === 'list' && $method === 'GET') {
            $this->getAllSettings();
        } elseif ($action === 'update' && $method === 'POST') {
            $this->updateSetting();
        } elseif ($action === 'test-smtp' && $method === 'POST') {
            $this->testSMTP();
        } elseif ($action === 'email-logs' && $method === 'GET') {
            $this->getEmailLogs();
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $token_parts = explode('.', $matches[1]);
        if (count($token_parts) !== 3) {
            return false;
        }

        try {
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])), true);
            if (!$payload)
                return false;

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return false;
            }

            $userId = $payload['data']['id'] ?? $payload['id'] ?? null;
            if (!$userId)
                return false;

            $stmt = $this->db->prepare("SELECT role FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['role'] === 'admin') {
                $this->currentUser = $user;
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    // Encryption helpers
    private function encrypt($value)
    {
        if (empty($value))
            return $value;
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    private function decrypt($value)
    {
        if (empty($value))
            return $value;
        try {
            $parts = explode('::', base64_decode($value), 2);
            if (count($parts) !== 2)
                return $value; // Not encrypted, return as-is
            list($iv, $encrypted) = $parts;
            return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        } catch (Exception $e) {
            return $value;
        }
    }

    // Get a single setting value (decrypted if needed)
    public function getSetting($key)
    {
        $stmt = $this->db->prepare("SELECT setting_value, is_encrypted FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row)
            return null;
        return $row['is_encrypted'] ? $this->decrypt($row['setting_value']) : $row['setting_value'];
    }

    private function getAllSettings()
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value, setting_group, description, is_encrypted FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mask encrypted values for display
        foreach ($settings as &$s) {
            if ($s['is_encrypted']) {
                $s['setting_value'] = '********';
            }
        }

        echo json_encode($settings);
    }

    private function updateSetting()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $key = $data['key'] ?? '';
        $value = $data['value'] ?? '';
        $group = $data['group'] ?? 'general';
        $encrypted = $data['is_encrypted'] ?? 0;

        if (!$key) {
            http_response_code(400);
            echo json_encode(["message" => "Key is required"]);
            return;
        }

        // Skip update if value is masked (unchanged)
        if ($value === '********') {
            echo json_encode(["message" => "Setting unchanged"]);
            return;
        }

        // Encrypt sensitive values
        $storedValue = $encrypted ? $this->encrypt($value) : $value;

        $stmt = $this->db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group, is_encrypted, updated_at) 
            VALUES (:k, :v, :g, :e, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v, updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            ':k' => $key,
            ':v' => $storedValue,
            ':g' => $group,
            ':e' => $encrypted
        ]);

        echo json_encode(["message" => "Setting updated"]);
    }

    private function testSMTP()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $to = $data['email'] ?? '';

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid email address"]);
            return;
        }

        // Use EmailService to test settings
        try {
            require_once __DIR__ . '/../services/EmailService.php';
            $emailService = new EmailService($this->db);

            $subject = "Test Email from Zenith Wellness";
            $body = "This is a test email from your admin panel.\n\nIf you received this, your SMTP settings are correct.\n\nTimestamp: " . date('Y-m-d H:i:s');

            $result = $emailService->send($to, $subject, nl2br($body), true);

            if ($result['status'] === 'success') {
                echo json_encode([
                    "success" => true,
                    "message" => "Test email sent successfully to $to"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "SMTP Error: " . ($result['message'] ?? 'Unknown error')
                ]);
            }

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Fetch available models from the specified AI provider
     */
    public function fetchModels()
    {
        $provider = $_GET['provider'] ?? 'gemini';

        try {
            $models = [];

            switch ($provider) {
                case 'gemini':
                    $models = $this->fetchGeminiModels();
                    break;
                case 'openai':
                    $models = $this->fetchOpenAIModels();
                    break;
                case 'openrouter':
                    $models = $this->fetchOpenRouterModels();
                    break;
                default:
                    $models = [];
            }

            echo json_encode(['success' => true, 'models' => $models]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'models' => []]);
        }
    }

    private function fetchGeminiModels()
    {
        $apiKey = $this->getSetting('gemini_api_key');
        if (empty($apiKey)) {
            return $this->getDefaultGeminiModels();
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);
        $response = @file_get_contents($url);

        if ($response === false) {
            return $this->getDefaultGeminiModels();
        }

        $data = json_decode($response, true);
        $models = [];

        if (isset($data['models'])) {
            foreach ($data['models'] as $model) {
                $name = $model['name'] ?? '';
                // Extract model ID (e.g., "models/gemini-1.5-flash" -> "gemini-1.5-flash")
                $id = str_replace('models/', '', $name);
                if (!empty($id) && strpos($id, 'gemini') !== false) {
                    $models[] = [
                        'id' => $id,
                        'name' => $model['displayName'] ?? $id,
                        'description' => $model['description'] ?? ''
                    ];
                }
            }
        }

        return empty($models) ? $this->getDefaultGeminiModels() : $models;
    }

    private function getDefaultGeminiModels()
    {
        return [
            ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'description' => 'Fast and efficient'],
            ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro', 'description' => 'Most capable'],
            ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash', 'description' => 'Latest generation'],
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'description' => 'Newest model']
        ];
    }

    private function fetchOpenAIModels()
    {
        $apiKey = $this->getSetting('openai_api_key');
        if (empty($apiKey)) {
            return $this->getDefaultOpenAIModels();
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer $apiKey\r\nContent-Type: application/json\r\n",
                'timeout' => 10
            ]
        ]);

        $response = @file_get_contents('https://api.openai.com/v1/models', false, $context);

        if ($response === false) {
            return $this->getDefaultOpenAIModels();
        }

        $data = json_decode($response, true);
        $models = [];

        if (isset($data['data'])) {
            foreach ($data['data'] as $model) {
                $id = $model['id'] ?? '';
                // Filter to only GPT models
                if (strpos($id, 'gpt') !== false) {
                    $models[] = [
                        'id' => $id,
                        'name' => $id,
                        'description' => ''
                    ];
                }
            }
            // Sort by ID
            usort($models, fn($a, $b) => strcmp($b['id'], $a['id']));
        }

        return empty($models) ? $this->getDefaultOpenAIModels() : $models;
    }

    private function getDefaultOpenAIModels()
    {
        return [
            ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'description' => 'Most capable'],
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'description' => 'Fast and affordable'],
            ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo', 'description' => 'High performance'],
            ['id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo', 'description' => 'Legacy model']
        ];
    }

    private function fetchOpenRouterModels()
    {
        $apiKey = $this->getSetting('openrouter_api_key');

        $headers = "Content-Type: application/json\r\n";
        if (!empty($apiKey)) {
            $headers .= "Authorization: Bearer $apiKey\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 15
            ]
        ]);

        $response = @file_get_contents('https://openrouter.ai/api/v1/models', false, $context);

        if ($response === false) {
            return $this->getDefaultOpenRouterModels();
        }

        $data = json_decode($response, true);
        $models = [];

        if (isset($data['data'])) {
            foreach ($data['data'] as $model) {
                $id = $model['id'] ?? '';
                $name = $model['name'] ?? $id;
                $models[] = [
                    'id' => $id,
                    'name' => $name,
                    'description' => $model['description'] ?? '',
                    'context_length' => $model['context_length'] ?? 0,
                    'pricing' => $model['pricing'] ?? null
                ];
            }
            // Limit to most popular/relevant models (first 100)
            $models = array_slice($models, 0, 100);
        }

        return empty($models) ? $this->getDefaultOpenRouterModels() : $models;
    }

    private function getDefaultOpenRouterModels()
    {
        return [
            ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet', 'description' => 'Anthropic flagship'],
            ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o', 'description' => 'OpenAI flagship'],
            ['id' => 'google/gemini-pro-1.5', 'name' => 'Gemini Pro 1.5', 'description' => 'Google flagship'],
            ['id' => 'meta-llama/llama-3.1-70b-instruct', 'name' => 'Llama 3.1 70B', 'description' => 'Meta open-source']
        ];
    }
}
?>