<?php
/**
 * ExperimentController — REST endpoints for the A/B testing engine.
 *
 * A.6 — Public endpoints (no auth):
 *   POST /api/exp/assign      — Assign visitor to experiment variant
 *   POST /api/exp/event       — Record experiment event (204 No Content)
 *   POST /api/exp/identify    — Link visitor_id to authenticated user_id
 *
 * Admin endpoints (AuthMiddleware, role=admin):
 *   GET    /api/admin/experiments          — List all experiments
 *   POST   /api/admin/experiments          — Create experiment
 *   GET    /api/admin/experiments/{id}     — Show experiment detail
 *   PUT    /api/admin/experiments/{id}     — Update experiment
 *   POST   /api/admin/experiments/{id}/start    — Start experiment
 *   POST   /api/admin/experiments/{id}/pause    — Pause experiment
 *   POST   /api/admin/experiments/{id}/ship     — Ship (promote) winner
 *   POST   /api/admin/experiments/{id}/rollback — Rollback to running
 *   GET    /api/admin/experiments/{id}/report   — Stats report
 *   GET    /api/admin/experiments/{id}/timeline — Decision timeline
 */

class ExperimentController
{
    private ExperimentAssignmentService $assignmentService;
    private ExperimentTrackingService $trackingService;
    private ExperimentStatsService $statsService;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $bandit = new BanditEngine($db);
        $this->assignmentService = new ExperimentAssignmentService($db, $bandit);
        $this->trackingService = new ExperimentTrackingService($db);
        $this->statsService = new ExperimentStatsService($db, $bandit);
    }

    // ─── Public Endpoints ───────────────────────────────────────

    /**
     * Handle a public request sub-action.
     */
    public function handleRequest(string $action): void
    {
        switch ($action) {
            case 'event':
                $this->handleEvent();
                break;
            case 'assign':
                $this->handleAssign();
                break;
            case 'identify':
                $this->handleIdentify();
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown experiment action']);
                break;
        }
    }

    /**
     * POST /api/exp/event — Record an experiment event.
     *
     * Returns 204 No Content for efficiency (called on every page/screen).
     * Body: { visitor_id, event_type, properties?: {}, user_id?: string }
     */
    private function handleEvent(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        $visitorId = $input['visitor_id'] ?? '';
        $eventType = $input['event_type'] ?? '';
        $properties = $input['properties'] ?? [];
        if (isset($input['value_cents'])) {
            $properties['value_cents'] = (int) $input['value_cents'];
        }
        $userId = $input['user_id'] ?? null;

        if (empty($visitorId) || empty($eventType)) {
            http_response_code(400);
            echo json_encode(['error' => 'visitor_id and event_type are required']);
            return;
        }

        $this->trackingService->recordEvent($visitorId, $eventType, $properties, $userId);

        // 204 No Content — cheap, no response body
        http_response_code(204);
    }

    /**
     * POST /api/exp/assign — Assign a visitor to an experiment variant.
     *
     * Body: { experiment_key, visitor_id, source, device, country, user_agent?, user_id? }
     */
    private function handleAssign(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $experimentKey = $input['experiment_key'] ?? ($_GET['experiment_key'] ?? '');
        $visitorId = $input['visitor_id'] ?? ($_GET['visitor_id'] ?? '');

        if (empty($experimentKey) || empty($visitorId)) {
            http_response_code(400);
            echo json_encode(['error' => 'experiment_key and visitor_id are required']);
            return;
        }

        $context = [
            'source' => $input['source'] ?? $_GET['source'] ?? 'direct',
            'device' => $input['device'] ?? $_GET['device'] ?? 'desktop',
            'country' => $input['country'] ?? $_GET['country'] ?? '',
            'user_agent' => $input['user_agent'] ?? $_GET['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'quiz_profile' => $input['quiz_profile'] ?? $_GET['quiz_profile'] ?? '',
        ];

        $userId = $input['user_id'] ?? $_GET['user_id'] ?? null;

        $result = $this->assignmentService->assign($experimentKey, $visitorId, $context, $userId);

        if ($result === null) {
            echo json_encode([
                'assigned' => false,
                'reason' => 'visitor_excluded',
            ]);
            return;
        }

        echo json_encode([
            'assigned' => true,
            'experiment' => $result['experiment'],
            'variant' => $result['variant'],
            'segment_key' => $result['segment_key'],
        ]);
    }

    /**
     * POST /api/exp/identify — Link a visitor_id to an authenticated user.
     *
     * When a visitor logs in or signs up, their pre-login zen_vid gets linked
     * to their user account so that post-login events are tracked to the
     * same experiment assignment.
     *
     * Body: { visitor_id, user_id }
     */
    private function handleIdentify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        $visitorId = $input['visitor_id'] ?? '';
        $userId = $input['user_id'] ?? '';

        if (empty($visitorId) || empty($userId)) {
            http_response_code(400);
            echo json_encode(['error' => 'visitor_id and user_id are required']);
            return;
        }

        // Backfill user_id on all existing assignments for this visitor
        $stmt = $this->db->prepare(
            "UPDATE experiment_assignments SET user_id = ? WHERE visitor_id = ? AND user_id IS NULL"
        );
        $stmt->execute([$userId, $visitorId]);
        $updated = $stmt->rowCount();

        // Backfill on events if the column exists
        $eventsUpdated = 0;
        try {
            $stmt = $this->db->prepare(
                "UPDATE experiment_events SET user_id = ? WHERE visitor_id = ? AND user_id IS NULL"
            );
            $stmt->execute([$userId, $visitorId]);
            $eventsUpdated = $stmt->rowCount();
        } catch (PDOException $e) {
            // Column may not exist yet — run migration
            $this->db->exec("ALTER TABLE experiment_events ADD COLUMN user_id VARCHAR(36) NULL");
        }

        echo json_encode([
            'success' => true,
            'assignments_updated' => (int) $updated,
            'events_updated' => (int) $eventsUpdated,
        ]);
    }

    // ─── Admin Endpoints ────────────────────────────────────────

    /**
     * Handle admin experiment request.
     *
     * URL patterns:
     *   /api/admin/experiments              → $id = null, $verb = null
     *   /api/admin/experiments/{id}         → $id = {id}, $verb = null
     *   /api/admin/experiments/{id}/{verb}  → $id = {id}, $verb = {verb}
     */
    public function handleAdminRequest(?string $id, ?string $verb): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // No experiment ID → list or create
        if ($id === null || $id === '') {
            if ($method === 'GET') {
                $this->handleAdminList();
            } elseif ($method === 'POST') {
                $this->handleAdminCreate();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            return;
        }

        // Has ID, no verb → show or update
        if ($verb === null || $verb === '') {
            if ($method === 'GET') {
                $this->handleAdminShow($id);
            } elseif ($method === 'PUT') {
                $this->handleAdminUpdate($id);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            return;
        }

        // Has ID and verb → lifecycle or report action
        switch ($verb) {
            case 'start':
                $this->handleAdminStart($id);
                break;
            case 'pause':
                $this->handleAdminPause($id);
                break;
            case 'ship':
                $this->handleAdminShip($id);
                break;
            case 'rollback':
                $this->handleAdminRollback($id);
                break;
            case 'report':
                $this->handleAdminReport($id);
                break;
            case 'timeline':
                $this->handleAdminTimeline($id);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown admin experiment action']);
                break;
        }
    }

    /**
     * GET /api/admin/experiments — List all experiments.
     */
    private function handleAdminList(): void
    {
        $stmt = $this->db->query(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM experiment_variants WHERE experiment_id = e.id) as variant_count,
                    (SELECT COUNT(*) FROM experiment_assignments WHERE experiment_id = e.id) as assignment_count
             FROM experiments e
             ORDER BY e.created_at DESC"
        );
        $experiments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['experiments' => $experiments]);
    }

    /**
     * POST /api/admin/experiments — Create a new experiment.
     */
    private function handleAdminCreate(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        $required = ['key_slug', 'name', 'surface', 'primary_goal_event'];
        $missing = [];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields: ' . implode(', ', $missing)]);
            return;
        }

        // Generate UUID
        $id = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO experiments
                (id, key_slug, name, description, surface, status, allocation_mode,
                 primary_goal_event, guardrail_events, targeting, exploration_floor,
                 min_samples_per_variant, auto_promote, confidence_threshold, holdout,
                 created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $input['key_slug'],
            $input['name'],
            $input['description'] ?? null,
            $input['surface'],
            'draft',
            $input['allocation_mode'] ?? 'bandit',
            $input['primary_goal_event'],
            isset($input['guardrail_events']) ? json_encode($input['guardrail_events']) : null,
            isset($input['targeting']) ? json_encode($input['targeting']) : null,
            $input['exploration_floor'] ?? 0.05,
            $input['min_samples_per_variant'] ?? 300,
            $input['auto_promote'] ?? 0,
            $input['confidence_threshold'] ?? 0.95,
            $input['holdout'] ?? 0,
            $input['created_by'] ?? null,
        ]);

        // Create default control and first treatment variants if provided
        if (!empty($input['variants']) && is_array($input['variants'])) {
            foreach ($input['variants'] as $i => $v) {
                $vId = $this->generateUuid();
                $vStmt = $this->db->prepare(
                    "INSERT INTO experiment_variants
                        (id, experiment_id, key_slug, name, is_control, config, fixed_weight, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
                );
                $vStmt->execute([
                    $vId,
                    $id,
                    $v['key_slug'] ?? ('variant_' . ($i + 1)),
                    $v['name'] ?? ('Variant ' . ($i + 1)),
                    ($i === 0 && !isset($v['is_control'])) ? 1 : ($v['is_control'] ?? 0),
                    isset($v['config']) ? json_encode($v['config']) : null,
                    $v['fixed_weight'] ?? null,
                ]);
            }
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'experiment_id' => $id,
            'key_slug' => $input['key_slug'],
        ]);
    }

    /**
     * GET /api/admin/experiments/{id} — Show experiment detail with variants.
     */
    private function handleAdminShow(string $id): void
    {
        $stmt = $this->db->prepare("SELECT * FROM experiments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $experiment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$experiment) {
            http_response_code(404);
            echo json_encode(['error' => 'Experiment not found']);
            return;
        }

        // Decode JSON fields
        if (isset($experiment['guardrail_events']) && is_string($experiment['guardrail_events'])) {
            $experiment['guardrail_events'] = json_decode($experiment['guardrail_events'], true);
        }
        if (isset($experiment['targeting']) && is_string($experiment['targeting'])) {
            $experiment['targeting'] = json_decode($experiment['targeting'], true);
        }

        $vStmt = $this->db->prepare(
            "SELECT * FROM experiment_variants WHERE experiment_id = ? ORDER BY is_control DESC, created_at ASC"
        );
        $vStmt->execute([$id]);
        $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($variants as &$v) {
            if (isset($v['config']) && is_string($v['config'])) {
                $v['config'] = json_decode($v['config'], true);
            }
        }
        unset($v);

        $experiment['variants'] = $variants;

        echo json_encode($experiment);
    }

    /**
     * PUT /api/admin/experiments/{id} — Update experiment fields.
     */
    private function handleAdminUpdate(string $id): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        // Check exists
        $check = $this->db->prepare("SELECT id FROM experiments WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Experiment not found']);
            return;
        }

        // Build dynamic update
        $allowedFields = [
            'name', 'description', 'surface', 'allocation_mode',
            'primary_goal_event', 'exploration_floor', 'min_samples_per_variant',
            'auto_promote', 'confidence_threshold', 'holdout',
        ];
        $jsonFields = ['guardrail_events', 'targeting'];

        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "$field = ?";
                $params[] = $input[$field];
            }
        }

        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "$field = ?";
                $params[] = is_string($input[$field]) ? $input[$field] : json_encode($input[$field]);
            }
        }

        if (empty($sets)) {
            http_response_code(400);
            echo json_encode(['error' => 'No updatable fields provided']);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare(
            "UPDATE experiments SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute($params);

        echo json_encode(['success' => true]);
    }

    /**
     * POST /api/admin/experiments/{id}/start — Start a draft experiment.
     */
    private function handleAdminStart(string $id): void
    {
        $authData = AuthMiddleware::authenticate();
        $actor = $authData['id'] ?? 'admin';

        $success = $this->statsService->startExperiment($id, $actor);
        if (!$success) {
            http_response_code(409);
            echo json_encode(['error' => 'Experiment must be in draft status to start']);
            return;
        }

        echo json_encode(['success' => true, 'status' => 'running']);
    }

    /**
     * POST /api/admin/experiments/{id}/pause — Pause a running experiment.
     */
    private function handleAdminPause(string $id): void
    {
        $authData = AuthMiddleware::authenticate();
        $actor = $authData['id'] ?? 'admin';

        $success = $this->statsService->pauseExperiment($id, $actor);
        if (!$success) {
            http_response_code(409);
            echo json_encode(['error' => 'Experiment must be running to pause']);
            return;
        }

        echo json_encode(['success' => true, 'status' => 'paused']);
    }

    /**
     * POST /api/admin/experiments/{id}/ship — Ship the winner.
     *
     * Body: { winning_variant_id }
     */
    private function handleAdminShip(string $id): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $winningVariantId = $input['winning_variant_id'] ?? '';

        if (empty($winningVariantId)) {
            http_response_code(400);
            echo json_encode(['error' => 'winning_variant_id is required']);
            return;
        }

        $authData = AuthMiddleware::authenticate();
        $actor = $authData['id'] ?? 'admin';

        $success = $this->statsService->shipExperiment($id, $winningVariantId, $actor);
        if (!$success) {
            http_response_code(409);
            echo json_encode(['error' => 'Experiment must be running or paused to ship']);
            return;
        }

        echo json_encode(['success' => true, 'status' => 'shipped']);
    }

    /**
     * POST /api/admin/experiments/{id}/rollback — Rollback shipped experiment.
     */
    private function handleAdminRollback(string $id): void
    {
        $authData = AuthMiddleware::authenticate();
        $actor = $authData['id'] ?? 'admin';

        $success = $this->statsService->rollbackExperiment($id, $actor);
        if (!$success) {
            http_response_code(409);
            echo json_encode(['error' => 'Experiment must be shipped to rollback']);
            return;
        }

        echo json_encode(['success' => true, 'status' => 'running']);
    }

    /**
     * GET /api/admin/experiments/{id}/report — Get statistical report.
     */
    private function handleAdminReport(string $id): void
    {
        $segmentKey = $_GET['segment'] ?? 'global';
        $report = $this->statsService->getReport($id, $segmentKey);

        if ($report === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Experiment not found']);
            return;
        }

        echo json_encode($report);
    }

    /**
     * GET /api/admin/experiments/{id}/timeline — Get decision timeline.
     */
    private function handleAdminTimeline(string $id): void
    {
        $timeline = $this->statsService->getTimeline($id);
        echo json_encode(['timeline' => $timeline]);
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Generate a v4 UUID.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
