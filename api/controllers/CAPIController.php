<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

/**
 * CAPI Controller — Meta Conversion API (server-side event proxy)
 *
 * Receives client-side events forwarded via POST /api/capi,
 * optionally enriches them server-side (e.g., IP, user-agent),
 * and proxies them to Meta's Conversions API.
 *
 * This provides privacy-resilient event matching using server-side
 * signals (IP + User-Agent) that complement Meta Pixel browser events.
 *
 * Setup: Set META_CAPI_ACCESS_TOKEN and META_PIXEL_ID in api/.env
 */
class CAPIController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->getCurrentUser();
    }

    public function handleRequest()
    {
        // Accept events from authenticated users or anonymous clients
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'POST') {
            $this->processEvent();
        } elseif ($method === 'OPTIONS') {
            // CORS preflight
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: POST, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization");
            exit;
        } else {
            http_response_code(405);
            echo json_encode(['message' => 'Method not allowed']);
        }
    }

    private function processEvent()
    {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input || !isset($input['event'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Missing event name']);
            return;
        }

        $eventName = $input['event'];
        $properties = $input['properties'] ?? [];
        $source = $input['source'] ?? 'unknown';
        $timestamp = $input['timestamp'] ?? date('c');

        // Get server-side signals for improved event matching
        $serverSignals = $this->getServerSignals();

        // Merge properties with server signals
        $payload = array_merge($properties, $serverSignals, [
            'event_source' => $source,
            'event_name' => $eventName,
            'event_time' => strtotime($timestamp),
            'action_source' => 'website',
        ]);

        // Include user ID if authenticated
        if ($this->currentUser) {
            $payload['em'] = $this->currentUser['email'] ?? null;
            $payload['user_id'] = $this->currentUser['id'] ?? null;
        }

        // Log to activity_logs for internal analytics
        $this->logEvent($eventName, $properties, $source, $serverSignals);

        // Proxy to Meta CAPI if configured
        $capiResult = $this->sendToMetaCAPI($eventName, $payload);

        echo json_encode([
            'success' => true,
            'event' => $eventName,
            'capi_status' => $capiResult ? 'sent' : 'skipped',
        ]);
    }

    private function getServerSignals(): array
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';

        // Normalize IP (take first in comma-separated list)
        $ip = explode(',', $ip)[0];

        return [
            'client_ip_address' => trim($ip),
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
    }

    private function sendToMetaCAPI(string $eventName, array $payload): bool
    {
        $accessToken = getenv('META_ACCESS_TOKEN') ?: (defined('META_CAPI_ACCESS_TOKEN') ? META_CAPI_ACCESS_TOKEN : '');
        $pixelId = getenv('META_PIXEL_ID') ?: (defined('META_PIXEL_ID') ? META_PIXEL_ID : '');

        if (empty($accessToken) || empty($pixelId)) {
            // CAPI not configured — skip silently
            return false;
        }

        $url = "https://graph.facebook.com/v21.0/{$pixelId}/events";

        $data = [
            'access_token' => $accessToken,
            'data' => [
                [
                    'event_name' => $eventName,
                    'event_time' => $payload['event_time'] ?? time(),
                    'action_source' => $payload['action_source'] ?? 'website',
                    'user_data' => [
                        'client_ip_address' => $payload['client_ip_address'] ?? '',
                        'client_user_agent' => $payload['client_user_agent'] ?? '',
                        'em' => isset($payload['em']) ? hash('sha256', strtolower($payload['em'])) : null,
                    ],
                    'custom_data' => array_filter([
                        'currency' => $payload['currency'] ?? null,
                        'value' => isset($payload['value']) ? (float) $payload['value'] : null,
                        'content_name' => $payload['program_title'] ?? null,
                    ]),
                ],
            ],
        ];

        $jsonData = json_encode($data);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($jsonData),
                'content' => $jsonData,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
            $httpCode = (int) ($matches[1] ?? 0);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        // Log CAPI failures but don't fail the client request
        error_log("[CAPI] Failed to send event '{$eventName}': HTTP {$httpCode} - " . ($response ?: 'no response'));
        return false;
    }

    private function logEvent(string $eventName, array $properties, string $source, array $serverSignals)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO capi_event_log (event_name, properties, source, ip_address, user_agent, user_id, created_at)
                VALUES (:event_name, :properties, :source, :ip, :ua, :user_id, NOW())
            ");
            $stmt->execute([
                ':event_name' => substr($eventName, 0, 100),
                ':properties' => json_encode($properties),
                ':source' => substr($source, 0, 50),
                ':ip' => substr($serverSignals['client_ip_address'] ?? '', 0, 45),
                ':ua' => $serverSignals['client_user_agent'] ?? '',
                ':user_id' => isset($this->currentUser['id']) ? substr($this->currentUser['id'], 0, 100) : null,
            ]);
        } catch (Exception $e) {
            error_log("[CAPI] Failed to log event: " . $e->getMessage());
        }
    }

    private function getCurrentUser()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return AuthMiddleware::verifyToken($matches[1]) ?: null;
        }

        return null;
    }
}