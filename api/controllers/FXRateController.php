<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/FXRateService.php';

class FXRateController
{
    private $db;
    private $encryptionKey;

    public function __construct($db)
    {
        $this->db = $db;
        $this->encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'zenith_default_key_change_me_32ch';
    }

    public function handleRequest($action)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($action === 'refresh' && $method === 'POST') {
            $this->refreshRates();
            return;
        }

        if ($action === 'list' && $method === 'GET') {
            $this->listRates();
            return;
        }

        if ($action === 'update' && $method === 'POST') {
            $this->updateRate();
            return;
        }

        http_response_code(404);
        echo json_encode(["message" => "Endpoint not found"]);
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $claims = AuthMiddleware::verifyToken($matches[1]);
        if (!$claims) return false;

        $userId = $claims['data']['id'] ?? null;
        if (!$userId) return false;

        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($user && $user['role'] === 'admin');
    }

    public function listRates()
    {
        $fxService = new FXRateService($this->db);
        $rates = $fxService->getAllRates();
        $source = $fxService->getRateSource();
        
        echo json_encode([
            'success' => true, 
            'rates' => $rates, 
            'base' => 'USD',
            'source' => $source
        ]);
    }

    private function refreshRates()
    {
        if (!$this->verifyAdmin()) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $apiKey = $data['api_key'] ?? null;
        
        $fxService = new FXRateService($this->db);
        $result = $fxService->fetchLiveRates($apiKey);

        if ($result['failed'] === 0) {
            $source = $result['source'] ?? 'unknown';
            echo json_encode([
                'success' => true,
                'message' => "Successfully refreshed " . $result['success'] . " exchange rates",
                'source' => $source,
                'rates_updated' => $result['success']
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch live rates',
                'errors' => $result['errors']
            ]);
        }
    }

    private function updateRate()
    {
        if (!$this->verifyAdmin()) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $target = $data['target_currency'] ?? '';
        $rate = $data['rate'] ?? null;
        $source = $data['source'] ?? 'admin';

        if (!$target || $rate === null) {
            http_response_code(400);
            echo json_encode(["message" => "target_currency and rate are required"]);
            return;
        }

        $fxService = new FXRateService($this->db);
        $success = $fxService->updateRate('USD', $target, (float)$rate, $source);

        if ($success) {
            echo json_encode(["message" => "Exchange rate updated"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to update rate"]);
        }
    }
}
