<?php
/**
 * VariantGenerationController
 *
 * Admin-only endpoints for AI variant generation (B.6).
 * All endpoints require AuthMiddleware + role=admin.
 */
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class VariantGenerationController
{
    private PDO $db;
    private ?array $currentUser = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Route incoming requests.
     */
    public function handleRequest(array $uriParts, int $apiIndex): void
    {
        // 1. Verify admin auth — all endpoints are admin-only
        if (!$this->verifyAdmin()) {
            return;
        }

        // /api/admin/variants/{action}/{id?}
        $action = $uriParts[$apiIndex + 3] ?? '';

        switch ($action) {
            // --- BRIEFS ---
            case 'briefs':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->listBriefs();
                } else {
                    $this->methodNotAllowed();
                }
                break;

            case 'brief':
                $surface = $uriParts[$apiIndex + 4] ?? null;
                if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $surface) {
                    $this->updateBrief($surface);
                } else {
                    $this->methodNotAllowed();
                }
                break;

            // --- GENERATE ---
            case 'generate':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->generate();
                } else {
                    $this->methodNotAllowed();
                }
                break;

            // --- CANDIDATES ---
            case 'candidates':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->listCandidates();
                } else {
                    $this->methodNotAllowed();
                }
                break;

            case 'approve':
                $candidateId = $uriParts[$apiIndex + 4] ?? null;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $candidateId) {
                    $this->approveCandidate($candidateId);
                } else {
                    $this->methodNotAllowed();
                }
                break;

            case 'reject':
                $candidateId = $uriParts[$apiIndex + 4] ?? null;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $candidateId) {
                    $this->rejectCandidate($candidateId);
                } else {
                    $this->methodNotAllowed();
                }
                break;

            // --- INSIGHTS ---
            case 'insights':
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->listInsights();
                } else {
                    $this->methodNotAllowed();
                }
                break;

            case 'toggle':
                $insightId = $uriParts[$apiIndex + 4] ?? null;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $insightId) {
                    $this->toggleInsight($insightId);
                } else {
                    $this->methodNotAllowed();
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(["error" => "Variant action not found: {$action}"]);
        }
    }

    // ===========================================
    // BRIEFS
    // ===========================================

    /**
     * GET — list all variant briefs.
     */
    private function listBriefs(): void
    {
        try {
            $stmt = $this->db->query("
                SELECT id, surface, updated_at,
                       JSON_LENGTH(value_props) AS value_prop_count,
                       JSON_LENGTH(forbidden_claims) AS forbidden_count
                FROM variant_briefs
                ORDER BY surface
            ");
            $briefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($briefs);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to list briefs: " . $e->getMessage()]);
        }
    }

    /**
     * PUT — update a brief for a given surface.
     */
    private function updateBrief(string $surface): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid JSON body"]);
            return;
        }

        // Build allowed fields
        $allowed = ['brand_voice', 'value_props', 'forbidden_claims', 'target_personas', 'reference_winners', 'config_schema'];
        $sets = [];
        $params = [':surface' => $surface];

        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $val = is_string($input[$field]) ? $input[$field] : json_encode($input[$field]);
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = $val;
            }
        }

        if (empty($sets)) {
            http_response_code(400);
            echo json_encode(["error" => "No valid fields to update"]);
            return;
        }

        try {
            $sql = "UPDATE variant_briefs SET " . implode(', ', $sets) . " WHERE surface = :surface";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(["error" => "Brief not found for surface: {$surface}"]);
                return;
            }

            $this->logAction('update_brief', 'variant_brief', $surface, 'Updated brief for: ' . $surface);
            echo json_encode(["success" => true, "message" => "Brief updated for surface: {$surface}"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to update brief: " . $e->getMessage()]);
        }
    }

    // ===========================================
    // GENERATE
    // ===========================================

    /**
     * POST — generate variant candidates.
     */
    private function generate(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['surface'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required field: surface"]);
            return;
        }

        // Check kill switch
        $aiEnabled = $this->getSystemSetting('EXPERIMENT_AI_GENERATION_ENABLED');
        if ($aiEnabled === '0' || $aiEnabled === 'false') {
            http_response_code(403);
            echo json_encode(["error" => "AI variant generation is disabled (kill switch active)"]);
            return;
        }

        // Cost ceiling check
        $costCeiling = (float) ($this->getSystemSetting('AI_USAGE_COST_CEILING') ?: 50.00);
        $monthlySpend = $this->getMonthlyAISpend();
        if ($monthlySpend >= $costCeiling) {
            http_response_code(429);
            echo json_encode([
                "error" => "AI usage cost ceiling reached",
                "monthly_spend" => $monthlySpend,
                "ceiling" => $costCeiling
            ]);
            return;
        }

        $count = min((int) ($input['count'] ?? 3), 10);
        if ($count < 3) $count = 3;

        require_once __DIR__ . '/../services/VariantGeneratorService.php';
        $service = new VariantGeneratorService($this->db);

        $result = $service->generate(
            $input['surface'],
            $input['experiment_id'] ?? null,
            $input['segment_key'] ?? null,
            $count
        );

        if ($result['success']) {
            $this->logAction('generate_variants', 'variant_generation', $result['generationId'],
                "Generated {$count} candidates for surface: {$input['surface']}");
        }

        echo json_encode($result);
    }

    // ===========================================
    // CANDIDATES — Review Queue
    // ===========================================

    /**
     * GET — list candidates with optional status filter.
     */
    private function listCandidates(): void
    {
        $status = $_GET['status'] ?? 'pending';
        $surface = $_GET['surface'] ?? null;
        $limit = min((int) ($_GET['limit'] ?? 50), 100);

        try {
            $sql = "
                SELECT vc.id, vc.generation_id, vc.experiment_id, vc.config,
                       vc.rationale, vc.pattern_tags, vc.safety_flag, vc.safety_notes,
                       vc.review_status, vc.edited_config, vc.promoted_variant_id,
                       vc.reviewed_by, vc.reviewed_at, vc.created_at,
                       vg.surface, vg.segment_key, vg.model,
                       vg.tokens_used
                FROM variant_candidates vc
                JOIN variant_generations vg ON vg.id = vc.generation_id
                WHERE 1=1
            ";
            $params = [];

            if ($status !== 'all') {
                $sql .= " AND vc.review_status = :status";
                $params[':status'] = $status;
            }

            if ($surface) {
                $sql .= " AND vg.surface = :surface";
                $params[':surface'] = $surface;
            }

            $sql .= " ORDER BY vc.created_at DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Decode JSON fields for clean output
            foreach ($candidates as &$c) {
                $c['config'] = json_decode($c['config'], true);
                $c['pattern_tags'] = json_decode($c['pattern_tags'], true);
                if ($c['edited_config']) {
                    $c['edited_config'] = json_decode($c['edited_config'], true);
                }
            }

            echo json_encode($candidates);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to list candidates: " . $e->getMessage()]);
        }
    }

    /**
     * POST — approve a candidate, optionally with edited config.
     */
    private function approveCandidate(string $candidateId): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            // 1. Load candidate
            $stmt = $this->db->prepare("
                SELECT vc.*, vg.experiment_id AS gen_experiment_id, vg.surface
                FROM variant_candidates vc
                JOIN variant_generations vg ON vg.id = vc.generation_id
                WHERE vc.id = :id
            ");
            $stmt->execute([':id' => $candidateId]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                http_response_code(404);
                echo json_encode(["error" => "Candidate not found"]);
                return;
            }

            if ($candidate['review_status'] !== 'pending') {
                http_response_code(409);
                echo json_encode(["error" => "Candidate already reviewed (status: {$candidate['review_status']})"]);
                return;
            }

            $experimentId = $candidate['gen_experiment_id'] ?? $input['experiment_id'] ?? null;
            if (!$experimentId) {
                http_response_code(400);
                echo json_encode(["error" => "No experiment_id associated with this candidate. Provide one in the request body."]);
                return;
            }

            // 2. Determine final config
            $finalConfig = $input['edited_config'] ?? json_decode($candidate['config'], true);

            // 3. Create experiment_variant
            $variantId = $this->uuid();
            $variantSlug = 'ai_' . substr($candidateId, 0, 8);

            $stmt = $this->db->prepare("
                INSERT INTO experiment_variants (id, experiment_id, key_slug, name, config, is_control)
                VALUES (:id, :expId, :slug, :name, :config, 0)
            ");
            $stmt->execute([
                ':id' => $variantId,
                ':expId' => $experimentId,
                ':slug' => $variantSlug,
                ':name' => 'AI Variant (' . ($candidate['surface'] ?? 'unknown') . ')',
                ':config' => json_encode($finalConfig),
            ]);

            // 4. Update candidate record
            $editStatus = isset($input['edited_config']) ? 'edited' : 'approved';
            $stmt = $this->db->prepare("
                UPDATE variant_candidates
                SET review_status = :status,
                    edited_config = :editedConfig,
                    promoted_variant_id = :variantId,
                    reviewed_by = :reviewer,
                    reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $editStatus,
                ':editedConfig' => isset($input['edited_config']) ? json_encode($input['edited_config']) : null,
                ':variantId' => $variantId,
                ':reviewer' => $this->currentUser['id'] ?? null,
                ':id' => $candidateId,
            ]);

            $this->logAction('approve_candidate', 'variant_candidate', $candidateId,
                "Candidate {$editStatus} for experiment {$experimentId}, promoted to variant {$variantId}");

            echo json_encode([
                "success" => true,
                "status" => $editStatus,
                "promoted_variant_id" => $variantId,
                "message" => "Candidate approved and promoted to experiment variant"
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to approve candidate: " . $e->getMessage()]);
        }
    }

    /**
     * POST — reject a candidate with optional note.
     */
    private function rejectCandidate(string $candidateId): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $note = $input['note'] ?? null;

        try {
            $stmt = $this->db->prepare("
                SELECT review_status FROM variant_candidates WHERE id = :id
            ");
            $stmt->execute([':id' => $candidateId]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                http_response_code(404);
                echo json_encode(["error" => "Candidate not found"]);
                return;
            }

            if ($candidate['review_status'] !== 'pending') {
                http_response_code(409);
                echo json_encode(["error" => "Candidate already reviewed (status: {$candidate['review_status']})"]);
                return;
            }

            $stmt = $this->db->prepare("
                UPDATE variant_candidates
                SET review_status = 'rejected',
                    safety_notes = :note,
                    reviewed_by = :reviewer,
                    reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':note' => $note,
                ':reviewer' => $this->currentUser['id'] ?? null,
                ':id' => $candidateId,
            ]);

            $this->logAction('reject_candidate', 'variant_candidate', $candidateId, $note ? "Rejected: {$note}" : 'Rejected');

            echo json_encode(["success" => true, "message" => "Candidate rejected"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to reject candidate: " . $e->getMessage()]);
        }
    }

    // ===========================================
    // INSIGHTS
    // ===========================================

    /**
     * GET — list insights with optional surface/segment filter.
     */
    private function listInsights(): void
    {
        $surface = $_GET['surface'] ?? null;
        $segmentKey = $_GET['segment_key'] ?? null;

        try {
            $sql = "
                SELECT vi.id, vi.surface, vi.segment_key, vi.pattern_tag,
                       vi.direction, vi.effect_size, vi.confidence,
                       vi.sample_size, vi.summary, vi.weight, vi.is_active,
                       vi.created_at,
                       (SELECT COUNT(*) FROM experiment_variants ev
                        JOIN variant_candidates vc ON vc.promoted_variant_id = ev.id
                        WHERE vc.id IN (
                            SELECT vc2.id FROM variant_candidates vc2
                            JOIN variant_generations vg2 ON vg2.id = vc2.generation_id
                            WHERE vg2.surface = vi.surface
                        )
                       ) AS experiment_count
                FROM variant_insights vi
                WHERE 1=1
            ";
            $params = [];

            if ($surface) {
                $sql .= " AND vi.surface = :surface";
                $params[':surface'] = $surface;
            }
            if ($segmentKey) {
                $sql .= " AND (vi.segment_key = :seg OR vi.segment_key IS NULL)";
                $params[':seg'] = $segmentKey;
            }

            $sql .= " ORDER BY vi.is_active DESC, vi.weight DESC, vi.confidence DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $insights = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($insights);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to list insights: " . $e->getMessage()]);
        }
    }

    /**
     * POST — toggle insight active/inactive.
     */
    private function toggleInsight(string $insightId): void
    {
        try {
            $stmt = $this->db->prepare("SELECT id, is_active, summary FROM variant_insights WHERE id = :id");
            $stmt->execute([':id' => $insightId]);
            $insight = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$insight) {
                http_response_code(404);
                echo json_encode(["error" => "Insight not found"]);
                return;
            }

            $newState = $insight['is_active'] ? 0 : 1;
            $stmt = $this->db->prepare("UPDATE variant_insights SET is_active = :active WHERE id = :id");
            $stmt->execute([':active' => $newState, ':id' => $insightId]);

            $actionLabel = $newState ? 'activated' : 'deactivated';
            $this->logAction("{$actionLabel}_insight", 'variant_insight', $insightId,
                "{$actionLabel}: {$insight['summary']}");

            echo json_encode([
                "success" => true,
                "is_active" => (bool) $newState,
                "message" => "Insight {$actionLabel}"
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to toggle insight: " . $e->getMessage()]);
        }
    }

    // ===========================================
    // INTERNAL HELPERS
    // ===========================================

    private function verifyAdmin(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(["message" => "No token provided"]);
            return false;
        }

        $claims = AuthMiddleware::verifyToken($matches[1]);
        if (!$claims) {
            http_response_code(401);
            echo json_encode(["message" => "Invalid or expired token"]);
            return false;
        }

        $userId = $claims['data']['id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["message" => "No user ID in token"]);
            return false;
        }

        $stmt = $this->db->prepare("SELECT id, role, name FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            return false;
        }

        $this->currentUser = $user;
        return true;
    }

    private function logAction(string $action, string $targetType, ?string $targetId, ?string $details = null): void
    {
        try {
            if (!$this->currentUser) return;
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (id, admin_id, action, target_type, target_id, details, ip_address)
                VALUES (:id, :admin, :action, :type, :tid, :details, :ip)
            ");
            $stmt->execute([
                ':id' => 'log_' . bin2hex(random_bytes(8)),
                ':admin' => $this->currentUser['id'],
                ':action' => $action,
                ':type' => $targetType,
                ':tid' => $targetId,
                ':details' => $details,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }

    private function getSystemSetting(string $key): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key");
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['setting_value'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getMonthlyAISpend(): float
    {
        try {
            $stmt = $this->db->query("
                SELECT COALESCE(SUM(COALESCE(cost, 0)), 0) AS monthly_spend
                FROM (
                    SELECT (tokens_used * 0.000002) AS cost
                    FROM variant_generations
                    WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                ) sub
            ");
            return (float) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0.0;
        }
    }

    private function methodNotAllowed(): void
    {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
