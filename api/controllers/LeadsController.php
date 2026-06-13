<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class LeadsController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action, $id = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Public endpoint to capture leads
        if ($action === 'capture' && $method === 'POST') {
            $this->captureLead();
            return;
        }

        // Admin only for the rest
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $payload = AuthMiddleware::verifyToken($matches[1]);
        if (!$payload || ($payload['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        if ($action === 'list' && $method === 'GET') {
            $this->getLeads();
        } elseif ($action === 'status' && $method === 'POST') {
            $this->updateStatus();
        }
    }

    private function captureLead()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['email'])) {
            http_response_code(400);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO leads (id, name, email, source, notes)
            VALUES (:id, :name, :email, :source, :notes)
        ");
        $stmt->execute([
            ':id' => uniqid('lead_'),
            ':name' => $data['name'] ?? 'Unknown',
            ':email' => $data['email'],
            ':source' => $data['source'] ?? 'website',
            ':notes' => $data['notes'] ?? ''
        ]);
        echo json_encode(["message" => "Lead captured"]);
    }

    private function getLeads()
    {
        $stmt = $this->db->query("SELECT * FROM leads ORDER BY created_at DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function updateStatus()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $this->db->prepare("UPDATE leads SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $data['status'], ':id' => $data['id']]);
        echo json_encode(["message" => "Status updated"]);
    }
}
?>