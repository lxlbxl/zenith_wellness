<?php
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
        if (!$this->verifyAdmin()) {
            http_response_code(403);
            return;
        }

        if ($action === 'list' && $method === 'GET') {
            $this->getLeads();
        } elseif ($action === 'status' && $method === 'POST') {
            $this->updateStatus();
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

            return ($user && $user['role'] === 'admin');
        } catch (Exception $e) {
            return false;
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