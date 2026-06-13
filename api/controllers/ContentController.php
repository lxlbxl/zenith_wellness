<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class ContentController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($type, $id = null)
    {
        if (!$this->verifyAdmin()) {
            http_response_code(403);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        if ($type === 'cohorts') {
            if ($method === 'GET')
                $this->getCohorts();
            elseif ($method === 'POST')
                $this->saveCohort();
            elseif ($method === 'DELETE')
                $this->deleteCohort($id);
        } elseif ($type === 'challenges') {
            if ($method === 'GET')
                $this->getChallenges();
            elseif ($method === 'POST')
                $this->saveChallenge();
            elseif ($method === 'DELETE')
                $this->deleteChallenge($id);
        } elseif ($type === 'prompts') {
            if ($method === 'GET')
                $this->getPrompts();
            elseif ($method === 'POST')
                $this->savePrompt();
        }
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $payload = AuthMiddleware::verifyToken($matches[1]);
        if (!$payload)
            return false;

        $role = $payload['role'] ?? $payload['data']['role'] ?? '';
        return $role === 'admin' ? $payload : false;
    }

    private function deleteCohort($id)
    {
        if (!$id)
            return;
        $stmt = $this->db->prepare("DELETE FROM cohorts WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(["message" => "Deleted"]);
    }

    private function getCohorts()
    {
        $stmt = $this->db->query("SELECT * FROM cohorts");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function saveCohort()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        // Basic UPSERT logic with all fields
        $stmt = $this->db->prepare("
            INSERT INTO cohorts (id, title, description, start_date, category, price, image_url, objectives, duration_days)
            VALUES (:id, :title, :desc, :date, :cat, :price, :img, :obj, :dur)
            ON CONFLICT(id) DO UPDATE SET 
                title=:title, description=:desc, start_date=:date, category=:cat, price=:price,
                image_url=:img, objectives=:obj, duration_days=:dur
        ");
        $stmt->execute([
            ':id' => $data['id'] ?: uniqid('prog_'),
            ':title' => $data['title'],
            ':desc' => $data['description'],
            ':date' => $data['startDate'],
            ':cat' => $data['category'],
            ':price' => $data['price'],
            ':img' => $data['image_url'] ?? '',
            ':obj' => $data['objectives'] ?? '',
            ':dur' => $data['duration'] ?? 21
        ]);
        echo json_encode(["message" => "Cohort saved"]);
    }

    private function getChallenges()
    {
        $programId = $_GET['programId'] ?? null;
        if (!$programId) {
            echo json_encode([]);
            return;
        }
        $stmt = $this->db->prepare("SELECT * FROM routine_templates WHERE program_id = :pid ORDER BY created_at DESC");
        $stmt->execute([':pid' => $programId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function saveChallenge()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['programId'])) {
            http_response_code(400);
            return;
        }
        $stmt = $this->db->prepare("
            INSERT INTO routine_templates (id, program_id, title, description, type, duration_minutes)
            VALUES (:id, :pid, :title, :desc, 'challenge', :dur)
            ON CONFLICT(id) DO UPDATE SET title=:title, description=:desc, duration_minutes=:dur
        ");
        $stmt->execute([
            ':id' => $data['id'] ?: uniqid('ch_'),
            ':pid' => $data['programId'],
            ':title' => $data['title'],
            ':desc' => $data['description'],
            ':dur' => $data['duration'] ?? 30
        ]);
        echo json_encode(["message" => "Saved"]);
    }

    private function deleteChallenge($id)
    {
        if (!$id)
            return;
        $stmt = $this->db->prepare("DELETE FROM routine_templates WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(["message" => "Deleted"]);
    }

    private function getPrompts()
    {
        $stmt = $this->db->query("SELECT * FROM ai_prompts");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function savePrompt()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        // Handle modelConfig from frontend
        $config = $data['modelConfig'] ?? $data['config'] ?? [];
        if (is_array($config)) {
            $config = json_encode($config);
        }

        $stmt = $this->db->prepare("
            INSERT INTO ai_prompts (id, agent_name, system_prompt, model_config)
            VALUES (:id, :name, :prompt, :config)
            ON CONFLICT(id) DO UPDATE SET system_prompt=:prompt, model_config=:config
        ");
        $stmt->execute([
            ':id' => $data['id'] ?: uniqid('prm_'),
            ':name' => $data['agentName'] ?? $data['agent_name'] ?? 'unnamed',
            ':prompt' => $data['systemPrompt'] ?? $data['system_prompt'] ?? '',
            ':config' => $config
        ]);
        echo json_encode(["message" => "Prompt saved"]);
    }
}
?>