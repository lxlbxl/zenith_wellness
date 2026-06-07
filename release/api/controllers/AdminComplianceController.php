<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/PrivacyController.php';

class AdminComplianceController
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
            case 'list':
                $this->listRequests();
                break;
            case 'process':
                $this->processRequest();
                break;
            case 'export-user':
                $this->generateUserExport();
                break;
            case 'stats':
                $this->getComplianceStats();
                break;
            default:
                http_response_code(404);
                echo json_encode(['message' => 'Action not found']);
                break;
        }
    }

    private function verifyAdmin()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches))
            return false;
        $parts = explode('.', $matches[1]);
        if (count($parts) < 2)
            return false;
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

        // Handle nested payload structure
        $role = $payload['role'] ?? $payload['data']['role'] ?? '';
        return $role === 'admin' ? $payload : false;
    }

    private function listRequests()
    {
        $status = $_GET['status'] ?? null;
        $type = $_GET['type'] ?? null;

        $sql = "
            SELECT r.*, u.name, u.email
            FROM compliance_requests r
            JOIN users u ON r.user_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        if ($type) {
            $sql .= " AND r.type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY r.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function processRequest()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null; // 'completed', 'rejected'
        $notes = $data['notes'] ?? '';

        if (!$id || !$status) {
            http_response_code(400);
            echo json_encode(['message' => 'ID and status required']);
            return;
        }

        $stmt = $this->db->prepare("SELECT user_id, type FROM compliance_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            http_response_code(404);
            echo json_encode(['message' => 'Request not found']);
            return;
        }

        // Get user info for notifications
        $stmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$req['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        try {
            $notif = new NotificationService($this->db);
            $emailService = new EmailService($this->db);

            if ($req['type'] === 'delete' && $status === 'completed') {
                // Process deletion using anonymization
                $result = PrivacyController::anonymizeUser($this->db, $req['user_id']);

                if (!$result['success']) {
                    http_response_code(500);
                    echo json_encode(['message' => 'Failed to anonymize user: ' . $result['error']]);
                    return;
                }

                // Update request status
                $stmt = $this->db->prepare("UPDATE compliance_requests SET status = 'completed', admin_notes = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$notes, $id]);

                // Note: Can't send notification to deleted user, but we log it
                error_log("Processed deletion request $id for user {$req['user_id']}");

            } elseif ($req['type'] === 'export' && $status === 'completed') {
                // Export is usually auto-processed, but admin can trigger it manually
                $stmt = $this->db->prepare("UPDATE compliance_requests SET status = 'completed', admin_notes = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$notes, $id]);

                $notif->create($req['user_id'], 'success', 'Export Ready', 'Your data export is now available for download.', '/settings?tab=privacy');

            } elseif ($status === 'rejected') {
                $stmt = $this->db->prepare("UPDATE compliance_requests SET status = 'rejected', admin_notes = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$notes, $id]);

                $notif->create($req['user_id'], 'error', 'Request Rejected',
                    "Your {$req['type']} request was rejected." . ($notes ? " Reason: $notes" : ''),
                    '/settings?tab=privacy');

                // Send email
                if ($user) {
                    $emailService->send(
                        $user['email'],
                        'Compliance Request Update',
                        "<p>Hi {$user['name']},</p>
                        <p>Your {$req['type']} request has been rejected.</p>" .
                        ($notes ? "<p>Reason: $notes</p>" : '') .
                        "<p>If you have questions, please contact support.</p>
                        <p>Best regards,<br>Zenith Wellness Team</p>"
                    );
                }
            } else {
                // Generic completion
                $stmt = $this->db->prepare("UPDATE compliance_requests SET status = ?, admin_notes = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$status, $notes, $id]);

                $notif->create($req['user_id'], 'info', 'Request Processed', "Your {$req['type']} request has been processed.");
            }

            echo json_encode(['success' => true, 'message' => 'Request processed successfully']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()]);
        }
    }

    private function generateUserExport()
    {
        $userId = $_GET['user_id'] ?? null;

        if (!$userId) {
            http_response_code(400);
            echo json_encode(['message' => 'User ID required']);
            return;
        }

        // Verify user exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['message' => 'User not found']);
            return;
        }

        // Use PrivacyController's export logic
        $privacyController = new PrivacyController($this->db);
        $method = new ReflectionMethod('PrivacyController', 'generateDataExport');
        $method->setAccessible(true);
        $result = $method->invoke($privacyController, $userId);

        if ($result['success']) {
            // Create compliance request record
            $id = uniqid('admin_');
            $stmt = $this->db->prepare("INSERT INTO compliance_requests (id, user_id, type, status, file_path, admin_notes, processed_at) VALUES (?, ?, 'export', 'completed', ?, 'Admin generated', CURRENT_TIMESTAMP)");
            $stmt->execute([$id, $userId, $result['filename']]);

            echo json_encode([
                'success' => true,
                'filename' => $result['filename'],
                'message' => 'Export generated successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $result['error']]);
        }
    }

    private function getComplianceStats()
    {
        $stats = [
            'pending_exports' => 0,
            'pending_deletions' => 0,
            'processed_this_month' => 0,
            'total_consents' => 0
        ];

        // Pending exports
        $stmt = $this->db->query("SELECT COUNT(*) FROM compliance_requests WHERE type = 'export' AND status = 'pending'");
        $stats['pending_exports'] = (int) $stmt->fetchColumn();

        // Pending deletions
        $stmt = $this->db->query("SELECT COUNT(*) FROM compliance_requests WHERE type = 'delete' AND status = 'pending'");
        $stats['pending_deletions'] = (int) $stmt->fetchColumn();

        // Processed this month
        $stmt = $this->db->query("SELECT COUNT(*) FROM compliance_requests WHERE status IN ('completed', 'rejected') AND processed_at >= DATE('now', 'start of month')");
        $stats['processed_this_month'] = (int) $stmt->fetchColumn();

        // Total users with any consent recorded
        $stmt = $this->db->query("SELECT COUNT(DISTINCT user_id) FROM user_consents");
        $stats['total_consents'] = (int) $stmt->fetchColumn();

        echo json_encode($stats);
    }
}
