<?php
require_once __DIR__ . '/../services/NotificationService.php';

class CycleController
{
    private $db;
    private $currentUser;
    private $notificationService;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
        $this->notificationService = new NotificationService($db);
        $this->initializeTable();
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
            if ($payload) {
                return $payload['data'] ?? $payload;
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    private function initializeTable()
    {
        $query = "CREATE TABLE IF NOT EXISTS user_cycle_settings (
            user_id VARCHAR(255) PRIMARY KEY,
            last_period_date DATE,
            cycle_length INT DEFAULT 28,
            period_length INT DEFAULT 5,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($query);
    }

    public function handleRequest($resource, $userId = null)
    {
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        if ($resource === 'status' && $method === 'GET') {
            $targetUser = $userId ?? $this->currentUser['id'];
            $this->getStatus($targetUser);
        } elseif ($resource === 'settings' && $method === 'POST') {
            $this->updateSettings();
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    private function getStatus($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM user_cycle_settings WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Default placeholder
            echo json_encode(null);
            return;
        }

        $lastPeriod = new DateTime($settings['last_period_date']);
        $today = new DateTime();
        $diff = $today->diff($lastPeriod)->days;

        // Handle cycles (modulo)
        $dayOfCycle = ($diff % $settings['cycle_length']) + 1;

        // Determine Phase
        $phase = 'follicular';
        if ($dayOfCycle <= $settings['period_length']) {
            $phase = 'menstrual';
        } elseif ($dayOfCycle >= ($settings['cycle_length'] - 14) && $dayOfCycle <= ($settings['cycle_length'] - 12)) {
            $phase = 'ovulation';
        } elseif ($dayOfCycle > ($settings['cycle_length'] - 12)) {
            $phase = 'luteal';
        } else {
            $phase = 'follicular';
        }

        // Calculate next period
        $nextPeriod = clone $lastPeriod;
        $cyclesPassed = floor($diff / $settings['cycle_length']);
        $nextPeriod->modify('+' . (($cyclesPassed + 1) * $settings['cycle_length']) . ' days');

        // Fertile window (rough estimate: 5 days before ovulation + ovulation day)
        $ovulationDay = $settings['cycle_length'] - 14;
        $fertileStart = clone $lastPeriod;
        $fertileStart->modify('+' . ($cyclesPassed * $settings['cycle_length'] + $ovulationDay - 5) . ' days');
        $fertileEnd = clone $fertileStart;
        $fertileEnd->modify('+6 days');

        echo json_encode([
            'averageCycleLength' => (int) $settings['cycle_length'],
            'averagePeriodLength' => (int) $settings['period_length'],
            'currentPhase' => $phase,
            'dayOfCycle' => $dayOfCycle,
            'nextPeriodDate' => $nextPeriod->format('Y-m-d'),
            'fertileWindowStart' => $fertileStart->format('Y-m-d'),
            'fertileWindowEnd' => $fertileEnd->format('Y-m-d'),
            'ovulationDate' => $fertileEnd->modify('-1 day')->format('Y-m-d')
        ]);
    }

    private function updateSettings()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $userId = $this->currentUser['id'];

        // Upsert
        $stmt = $this->db->prepare("
            INSERT INTO user_cycle_settings (user_id, last_period_date, cycle_length, period_length, updated_at)
            VALUES (:uid, :date, :cl, :pl, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id) DO UPDATE SET
            last_period_date = excluded.last_period_date,
            cycle_length = excluded.cycle_length,
            period_length = excluded.period_length,
            updated_at = excluded.updated_at
        ");

        $stmt->execute([
            ':uid' => $userId,
            ':date' => $data['lastPeriodDate'],
            ':cl' => $data['cycleLength'] ?? 28,
            ':pl' => $data['periodLength'] ?? 5
        ]);

        echo json_encode(["message" => "Cycle settings updated"]);
    }

    // Helper to get raw phase (for internal use)
    public function getPhaseForUser($userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM user_cycle_settings WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings)
            return 'follicular'; // Default

        $lastPeriod = new DateTime($settings['last_period_date']);
        $today = new DateTime();
        $diff = $today->diff($lastPeriod)->days;
        $dayOfCycle = ($diff % $settings['cycle_length']) + 1;

        if ($dayOfCycle <= $settings['period_length'])
            return 'menstrual';
        if ($dayOfCycle >= ($settings['cycle_length'] - 14) && $dayOfCycle <= ($settings['cycle_length'] - 12))
            return 'ovulation';
        if ($dayOfCycle > ($settings['cycle_length'] - 12))
            return 'luteal';
        return 'follicular';
    }
}
?>