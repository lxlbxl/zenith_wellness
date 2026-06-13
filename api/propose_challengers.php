<?php
/**
 * propose_challengers.php
 *
 * B.8 — Auto-generate challenger variants when experiments ship.
 *
 * Usage (cron — weekly):
 *   0 6 * * 1 php /path/to/api/propose_challengers.php
 *
 * Usage (on ship event — via webhook/CI):
 *   php /path/to/api/propose_challengers.php --ship-event=<experiment_id>
 *
 * Kill switch: EXPERIMENT_AI_GENERATION_ENABLED system_setting = '0'
 * Cost ceiling: AI_USAGE_COST_CEILING system_setting (default $50/mo)
 */

// ---- Bootstrap ----

// Allow CLI or web invocation
if (php_sapi_name() === 'cli') {
    // Parse CLI args
    $options = getopt('', ['ship-event::']);
    $shipEventId = $options['ship-event'] ?? null;
} else {
    http_response_code(403);
    echo json_encode(["error" => "CLI only"]);
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/services/VariantGeneratorService.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "ERROR: Database connection failed\n";
    exit(1);
}

// ---- Config ----

$aiEnabled = getSystemSetting($db, 'EXPERIMENT_AI_GENERATION_ENABLED');
if ($aiEnabled === '0' || $aiEnabled === 'false') {
    echo "SKIP: AI variant generation disabled (kill switch EXPERIMENT_AI_GENERATION_ENABLED=0)\n";
    exit(0);
}

$costCeiling = (float) (getSystemSetting($db, 'AI_USAGE_COST_CEILING') ?: 50.00);
$monthlySpend = getMonthlyAISpend($db);

if ($monthlySpend >= $costCeiling) {
    echo "SKIP: AI usage cost ceiling reached (\${$monthlySpend} / \${$costCeiling})\n";
    exit(0);
}

// ---- Determine target surfaces ----

$targetSurfaces = [];

if ($shipEventId) {
    // Ship event mode: find the experiment, determine its surface
    $stmt = $db->prepare("SELECT id, surface, name FROM experiments WHERE id = :id AND status = 'shipped'");
    $stmt->execute([':id' => $shipEventId]);
    $experiment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$experiment) {
        echo "SKIP: Experiment {$shipEventId} not found or not shipped\n";
        exit(0);
    }

    $targetSurfaces[] = $experiment['surface'];
    echo "Ship event: generating challengers for '{$experiment['surface']}' (experiment: {$experiment['name']})\n";

    // Log the ship decision
    logDecision($db, $experiment['id'], 'shipped', null, [
        'trigger' => 'propose_challengers',
        'surface' => $experiment['surface'],
    ]);
} else {
    // Weekly mode: find shipped experiments with running/runnable experiments
    $stmt = $db->query("
        SELECT DISTINCT e.id, e.surface, e.name
        FROM experiments e
        WHERE e.status IN ('shipped', 'running')
          AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY e.updated_at DESC
    ");
    $experiments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seenSurfaces = [];
    foreach ($experiments as $exp) {
        if (!in_array($exp['surface'], $seenSurfaces)) {
            $targetSurfaces[] = $exp['surface'];
            $seenSurfaces[] = $exp['surface'];
        }
    }

    echo "Weekly run: checking " . count($experiments) . " recent experiments, " . count($targetSurfaces) . " unique surfaces\n";
}

if (empty($targetSurfaces)) {
    echo "No target surfaces found\n";
    exit(0);
}

// ---- Generate challengers per surface ----

$service = new VariantGeneratorService($db);
$results = [];

foreach ($targetSurfaces as $surface) {
    // Check budget mid-run
    $monthlySpend = getMonthlyAISpend($db);
    if ($monthlySpend >= $costCeiling) {
        echo "BUDGET STOP: Ceiling reached after processing {$surface}\n";
        break;
    }

    // Check if we already have pending candidates for this surface
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM variant_candidates vc
        JOIN variant_generations vg ON vg.id = vc.generation_id
        WHERE vg.surface = :surface AND vc.review_status = 'pending'
    ");
    $stmt->execute([':surface' => $surface]);
    $pendingCount = (int) $stmt->fetchColumn();

    if ($pendingCount >= 5) {
        echo "SKIP {$surface}: {$pendingCount} pending candidates already in queue\n";
        continue;
    }

    // Determine candidate count based on remaining budget
    $budgetRatio = 1 - ($monthlySpend / $costCeiling);
    $candidateCount = max(3, min(5, (int) round(5 * $budgetRatio)));

    echo "Generating {$candidateCount} challengers for surface '{$surface}'...\n";

    $result = $service->generate($surface, null, null, $candidateCount);

    if ($result['success']) {
        echo "  OK: " . count($result['candidates']) . " candidates generated (gen_id: {$result['generationId']})\n";
        $results[] = [
            'surface' => $surface,
            'generation_id' => $result['generationId'],
            'count' => count($result['candidates']),
            'tokens' => $result['candidates'][0]['tokens_used'] ?? null,
        ];
    } else {
        echo "  FAIL: {$result['error']}\n";
    }
}

// ---- Summary ----

$totalGenerated = array_sum(array_column($results, 'count'));
$finalSpend = getMonthlyAISpend($db);

echo "\n===== CHALLENGER GENERATION COMPLETE =====\n";
echo "Surfaces processed: " . count($results) . "\n";
echo "Total candidates: {$totalGenerated}\n";
echo "Monthly AI spend: \${$finalSpend} / \${$costCeiling}\n";

if ($totalGenerated === 0) {
    echo "No new candidates generated this run.\n";
} else {
    echo "Check the admin review queue to approve/reject candidates.\n";
}

exit(0);

// ===========================================
// Helper functions
// ===========================================

function getSystemSetting(PDO $db, string $key): ?string
{
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function getMonthlyAISpend(PDO $db): float
{
    try {
        $stmt = $db->query("
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

function logDecision(PDO $db, string $expId, string $type, ?string $winnerId, array $rationale): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO experiment_decisions (experiment_id, decision_type, winning_variant_id, actor, rationale)
            VALUES (:expId, :type, :winner, :actor, :rationale)
        ");
        $stmt->execute([
            ':expId' => $expId,
            ':type' => $type,
            ':winner' => $winnerId,
            ':actor' => 'engine',
            ':rationale' => json_encode($rationale),
        ]);
    } catch (Exception $e) {
        echo "WARN: Failed to log decision: {$e->getMessage()}\n";
    }
}
