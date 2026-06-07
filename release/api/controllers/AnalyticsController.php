<?php
require_once __DIR__ . '/../config.php';

class AnalyticsController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAdmin();
    }

    public function handleRequest($action)
    {
        if (!$this->currentUser) {
            http_response_code(403);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        switch ($action) {
            case 'revenue':
                $this->getRevenueData();
                break;
            case 'users':
                $this->getUserGrowth();
                break;
            case 'engagement':
                $this->getEngagementStats();
                break;
            default:
                http_response_code(404);
                echo json_encode(['message' => 'Analytics action not found']);
                break;
        }
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $parts = explode('.', $matches[1]);
        if (count($parts) < 2) {
            return false;
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        if (($payload['role'] ?? '') !== 'admin') {
            return false;
        }

        return $payload;
    }

    private function getRangeClause($table, $dateColumn = 'created_at')
    {
        $range = $_GET['range'] ?? '30d';
        switch ($range) {
            case '7d':
                return "$dateColumn >= DATE('now', '-7 days')";
            case '30d':
                return "$dateColumn >= DATE('now', '-30 days')";
            case '90d':
                return "$dateColumn >= DATE('now', '-90 days')";
            case 'all':
                return "1=1";
            default:
                return "$dateColumn >= DATE('now', '-30 days')";
        }
    }

    private function getRevenueData()
    {
        // Daily revenue aggregation
        $where = $this->getRangeClause('payments');
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date, 
                SUM(amount) as total_cents 
            FROM payments 
            WHERE status = 'success' AND $where 
            GROUP BY DATE(created_at) 
            ORDER BY date ASC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize data (convert cents to dollars/float)
        $result = array_map(function ($row) {
            return [
                'date' => $row['date'],
                'amount' => (float) $row['total_cents'] / 100
            ];
        }, $data);

        echo json_encode($result);
    }

    private function getUserGrowth()
    {
        // Cumulative user growth
        $where = $this->getRangeClause('users');
        // Get all users up to the end of the range
        // For cumulative, simple grouping by date might be tricky if we want total *at that date*
        // A simpler approach for charts is just new users per day, or running total in PHP

        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date, 
                COUNT(*) as new_users 
            FROM users 
            WHERE $where 
            GROUP BY DATE(created_at) 
            ORDER BY date ASC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate running total if needed, or just return new users
        // Let's do running total if 'all', otherwise it's just growth in that period
        // For a growth chart, usually total users is better.
        // Let's get total count before the period starts to initialize

        $range = $_GET['range'] ?? '30d';
        $baseCount = 0;

        if ($range !== 'all') {
            $dateLimit = match ($range) {
                '7d' => "-7 days",
                '30d' => "-30 days",
                '90d' => "-90 days",
                default => "-30 days"
            };
            $stmtBase = $this->db->prepare("SELECT COUNT(*) FROM users WHERE created_at < DATE('now', ?)");
            $stmtBase->execute([$dateLimit]);
            $baseCount = $stmtBase->fetchColumn();
        }

        $result = [];
        $currentTotal = $baseCount;
        foreach ($data as $row) {
            $currentTotal += $row['new_users'];
            $result[] = [
                'date' => $row['date'],
                'total_users' => $currentTotal,
                'new_users' => (int) $row['new_users']
            ];
        }

        echo json_encode($result);
    }

    private function getEngagementStats()
    {
        $whereLog = $this->getRangeClause('daily_wellness_logs');

        // Logs per day
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date, 
                COUNT(*) as count 
            FROM daily_wellness_logs 
            WHERE $whereLog 
            GROUP BY DATE(created_at) 
            ORDER BY date ASC
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // date => count

        // Journal entries per day
        $whereJournal = $this->getRangeClause('journal_entries');
        $stmtJ = $this->db->prepare("
            SELECT 
                DATE(created_at) as date, 
                COUNT(*) as count 
            FROM journal_entries 
            WHERE $whereJournal 
            GROUP BY DATE(created_at) 
            ORDER BY date ASC
        ");
        $stmtJ->execute();
        $journals = $stmtJ->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge dates
        $allDates = array_unique(array_merge(array_keys($logs), array_keys($journals)));
        sort($allDates);

        $result = [];
        foreach ($allDates as $date) {
            $result[] = [
                'date' => $date,
                'logs' => (int) ($logs[$date] ?? 0),
                'journals' => (int) ($journals[$date] ?? 0)
            ];
        }

        echo json_encode($result);
    }
}
