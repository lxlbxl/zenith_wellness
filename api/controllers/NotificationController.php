<?php
require_once __DIR__ . '/../config.php';

class NotificationController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
    }

    public function handleRequest($action)
    {
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        switch ($action) {
            case 'unread':
                $this->getUnread();
                break;
            case 'mark-read':
                $this->markRead();
                break;
            case 'mark-all-read':
                $this->markAllRead();
                break;
            default:
                http_response_code(404);
                echo json_encode(['message' => 'Action not found']);
                break;
        }
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }
        $parts = explode('.', $matches[1]);
        if (count($parts) < 2)
            return false;
        return json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    }

    private function getUnread()
    {
        $stmt = $this->db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? AND is_read = 0 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$this->currentUser['id']]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($notifications);
    }

    private function markRead()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = $data['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            return;
        }

        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $this->currentUser['id']]);
        echo json_encode(['success' => true]);
    }

    private function markAllRead()
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$this->currentUser['id']]);
        echo json_encode(['success' => true]);
    }
}