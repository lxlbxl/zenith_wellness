<?php
require_once __DIR__ . '/../services/EnrollmentService.php';
require_once __DIR__ . '/../services/CohortCompletionService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class EnrollmentController
{
    private $db;
    private const GRACE_PERIOD_DAYS = 2;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action)
    {
        switch ($action) {
            case 'mine':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->getMyEnrollments();
                } else {
                    http_response_code(405);
                }
                break;
            case 'access':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->checkAccess();
                } else {
                    http_response_code(405);
                }
                break;
            case 'renewal-offers':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->getRenewalOffers();
                } else {
                    http_response_code(405);
                }
                break;
            case 'completions':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->getCompletions();
                } else {
                    http_response_code(405);
                }
                break;
            case 'certificate':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->getCertificate();
                } else {
                    http_response_code(405);
                }
                break;
            case 'admin-grant':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->adminGrant();
                } else {
                    http_response_code(405);
                }
                break;
            case 'admin-revoke':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->adminRevoke();
                } else {
                    http_response_code(405);
                }
                break;
            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    private function getUserIdFromToken()
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            $claims = AuthMiddleware::verifyToken($matches[1]);
            if ($claims) {
                return $claims['data']['id'] ?? null;
            }
        }
        return null;
    }

    private function isAdmin()
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            $claims = AuthMiddleware::verifyToken($matches[1]);
            if ($claims) {
                return ($claims['data']['role'] ?? '') === 'admin';
            }
        }
        return false;
    }

    private function getMyEnrollments()
    {
        $userId = $this->getUserIdFromToken();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        // Include enrollments within grace period
        $stmt = $this->db->prepare("
            SELECT e.*, c.title, c.image, c.price, c.duration_days, c.start_date,
                   CASE 
                       WHEN e.access_expires_at > CURRENT_TIMESTAMP THEN 'active'
                       WHEN e.access_expires_at > datetime('now', '-' || :grace || ' days') THEN 'grace_period'
                       ELSE 'expired'
                   END as access_status
            FROM cohort_enrollments e
            JOIN cohorts c ON e.cohort_id = c.id
            WHERE e.user_id = :uid 
            AND e.status IN ('active', 'completed')
            ORDER BY e.enrolled_at DESC
        ");
        $stmt->execute([':uid' => $userId, ':grace' => self::GRACE_PERIOD_DAYS]);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($enrollments);
    }

    private function checkAccess()
    {
        $userId = $this->getUserIdFromToken();
        $cohortId = $_GET['cohort_id'] ?? null;
        $includeGrace = ($_GET['include_grace'] ?? 'false') === 'true';

        if (!$userId || !$cohortId) {
            http_response_code(400);
            echo json_encode(["message" => "Missing parameters"]);
            return;
        }

        // Check for active access
        $stmt = $this->db->prepare("
            SELECT id, access_expires_at FROM cohort_enrollments 
            WHERE user_id = :uid 
            AND cohort_id = :cid
            AND status IN ('active', 'completed')
            AND access_expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([':uid' => $userId, ':cid' => $cohortId]);
        $activeEnrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeEnrollment) {
            echo json_encode([
                "has_access" => true,
                "cohort_id" => $cohortId,
                "access_type" => "active",
                "expires_at" => $activeEnrollment['access_expires_at']
            ]);
            return;
        }

        // Check for grace period access if requested
        if ($includeGrace) {
            $stmt = $this->db->prepare("
                SELECT id, access_expires_at FROM cohort_enrollments 
                WHERE user_id = :uid 
                AND cohort_id = :cid
                AND status IN ('active', 'completed')
                AND access_expires_at > datetime('now', '-' || :grace || ' days')
            ");
            $stmt->execute([':uid' => $userId, ':cid' => $cohortId, ':grace' => self::GRACE_PERIOD_DAYS]);
            $graceEnrollment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($graceEnrollment) {
                echo json_encode([
                    "has_access" => true,
                    "cohort_id" => $cohortId,
                    "access_type" => "grace_period",
                    "expires_at" => $graceEnrollment['access_expires_at'],
                    "grace_ends_at" => date('Y-m-d H:i:s', strtotime($graceEnrollment['access_expires_at'] . ' + ' . self::GRACE_PERIOD_DAYS . ' days'))
                ]);
                return;
            }
        }

        echo json_encode([
            "has_access" => false,
            "cohort_id" => $cohortId
        ]);
    }

    private function getRenewalOffers()
    {
        $userId = $this->getUserIdFromToken();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $cohortId = $_GET['cohort_id'] ?? null;

        $completionService = new CohortCompletionService($this->db);
        $offers = $completionService->getRenewalOffer($userId, $cohortId);

        echo json_encode([
            "offers" => $offers,
            "alumni_discount" => 15
        ]);
    }

    private function getCompletions()
    {
        $userId = $this->getUserIdFromToken();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT cc.*, c.title as cohort_name, c.image
            FROM cohort_completions cc
            JOIN cohorts c ON cc.cohort_id = c.id
            WHERE cc.user_id = ?
            ORDER BY cc.completed_at DESC
        ");
        $stmt->execute([$userId]);
        $completions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse stats JSON
        foreach ($completions as &$completion) {
            $completion['stats'] = json_decode($completion['completion_stats'], true);
            unset($completion['completion_stats']);
        }

        echo json_encode($completions);
    }

    private function getCertificate()
    {
        $userId = $this->getUserIdFromToken();
        $completionId = $_GET['completion_id'] ?? null;

        if (!$userId || !$completionId) {
            http_response_code(400);
            echo json_encode(["message" => "Missing parameters"]);
            return;
        }

        $completionService = new CohortCompletionService($this->db);
        $certData = $completionService->getCertificateData($completionId);

        if (!$certData) {
            http_response_code(404);
            echo json_encode(["message" => "Certificate not found"]);
            return;
        }

        // Mark certificate as downloaded
        $stmt = $this->db->prepare("UPDATE cohort_completions SET certificate_downloaded = 1 WHERE id = ?");
        $stmt->execute([$completionId]);

        echo json_encode($certData);
    }

    private function adminGrant()
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['user_id']) || empty($data['cohort_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "User ID and Cohort ID required"]);
            return;
        }

        $service = new EnrollmentService($this->db);
        $service->grantAccess($data['user_id'], $data['cohort_id'], null, $data['duration_days'] ?? null);

        echo json_encode(["message" => "Access granted successfully"]);
    }

    private function adminRevoke()
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        $service = new EnrollmentService($this->db);
        $service->revokeAccess($data['user_id'], $data['cohort_id']);

        echo json_encode(["message" => "Access revoked"]);
    }
}
