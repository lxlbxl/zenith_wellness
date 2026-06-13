<?php
/**
 * test_aa_experiment.php — A/A Test Validation
 *
 * B.9 Validation — Creates an A/A experiment (two identical variants),
 * simulates 10,000 visitor assignments, then verifies:
 *   - No SRM detected (p >= 0.001)
 *   - 50/50 allocation drift within exploration bounds (±8pp)
 *   - No false winner declared (prob_best < 0.95 for both)
 *
 * Uses direct DB inserts for bulk data, then exercises the real services
 * for SRM detection, stats refresh, and report generation.
 *
 * Usage:
 *   DATABASE_URL='' DB_CONNECTION=sqlite php tests/test_aa_experiment.php
 */

// Bootstrap services
$basePath = __DIR__ . '/../api';
require_once $basePath . '/config/Database.php';
require_once $basePath . '/services/BanditEngine.php';
require_once $basePath . '/services/ExperimentAssignmentService.php';
require_once $basePath . '/services/ExperimentStatsService.php';

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$passCount = 0;
$failCount = 0;
$assertions = [];

function assert_true(bool $condition, string $label): void {
    global $passCount, $failCount, $assertions;
    if ($condition) {
        $passCount++;
        $assertions[] = "  PASS: {$label}";
    } else {
        $failCount++;
        $assertions[] = "  FAIL: {$label}";
    }
}

function assert_close(float $expected, float $actual, float $tolerance, string $label): void {
    $diff = abs($expected - $actual);
    global $passCount, $failCount, $assertions;
    if ($diff <= $tolerance) {
        $passCount++;
        $assertions[] = "  PASS: {$label} (expected={$expected}, actual={$actual}, diff={$diff})";
    } else {
        $failCount++;
        $assertions[] = "  FAIL: {$label} (expected={$expected}, actual={$actual}, diff={$diff} > tol={$tolerance})";
    }
}

// ─── Setup DB ─────────────────────────────────────────────────

echo "=== A/A Test Validation ===\n\n";

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

// Create the A/A test experiment
$expId = uuid();
$controlId = uuid();
$treatmentId = uuid();

$db->exec("
    INSERT INTO experiments (id, key_slug, name, description, surface, status,
        allocation_mode, primary_goal_event, exploration_floor,
        min_samples_per_variant, auto_promote, confidence_threshold, holdout)
    VALUES ('{$expId}', 'aa_test_v1', 'A/A Validation Test', 'Two identical variants',
        'sales_trust', 'running', 'bandit', 'payment_success', 0.10, 100, 0, 0.95, 0)
");

$db->exec("
    INSERT INTO experiment_variants (id, experiment_id, key_slug, name, is_control, config)
    VALUES ('{$controlId}', '{$expId}', 'control', 'Control', 1, '{\"headline\":\"Test\",\"cta\":\"Start\"}')
");
$db->exec("
    INSERT INTO experiment_variants (id, experiment_id, key_slug, name, is_control, config)
    VALUES ('{$treatmentId}', '{$expId}', 'treatment_a', 'Treatment A (identical)', 0, '{\"headline\":\"Test\",\"cta\":\"Start\"}')
");

echo "Created A/A test experiment\n";
echo "  Control ID:   {$controlId}\n";
echo "  Treatment ID: {$treatmentId}\n\n";

// ─── Phase 1: Batch Insert 10,000 Assignments ────────────────

echo "--- Phase 1: Generating 10,000 assignments ---\n";

$visitorCount = 10000;
$assignmentCounts = [$controlId => 0, $treatmentId => 0];

// Bandit Engine for allocation
$bandit = new BanditEngine($db);

// Load experiment and variants
$stmt = $db->prepare("SELECT * FROM experiments WHERE id = ?");
$stmt->execute([$expId]);
$experiment = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT * FROM experiment_variants WHERE experiment_id = ?");
$stmt->execute([$expId]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$insertAssign = $db->prepare("
    INSERT OR IGNORE INTO experiment_assignments
        (experiment_id, variant_id, visitor_id, segment_key, context)
    VALUES (?, ?, ?, ?, ?)
");

$insertExposure = $db->prepare("
    INSERT INTO experiment_events
        (experiment_id, variant_id, visitor_id, event_type, segment_key)
    VALUES (?, ?, ?, 'exposure', ?)
");

$start = microtime(true);
$db->beginTransaction();

for ($i = 0; $i < $visitorCount; $i++) {
    $visitorId = 'aa_visitor_' . $i;
    $segmentKey = $i % 2 === 0 ? 'src:direct|dev:desktop' : 'src:direct|dev:mobile';
    $context = json_encode(['source' => 'direct', 'device' => $i % 2 === 0 ? 'desktop' : 'mobile']);

    // Use BanditEngine for Thompson Sampling allocation (same as real flow)
    $selected = $bandit->selectVariant($experiment, $variants, 'global');

    $insertAssign->execute([$expId, $selected['id'], $visitorId, $segmentKey, $context]);
    $insertExposure->execute([$expId, $selected['id'], $visitorId, $segmentKey]);

    $assignmentCounts[$selected['id']]++;
}

$db->commit();
$elapsed = round(microtime(true) - $start, 2);

$totalAssigned = array_sum($assignmentCounts);
echo "  Time: {$elapsed}s\n";
echo "  Total: {$totalAssigned}\n";
echo "  Control:   {$assignmentCounts[$controlId]} (" . round($assignmentCounts[$controlId]/$totalAssigned*100, 1) . "%)\n";
echo "  Treatment: {$assignmentCounts[$treatmentId]} (" . round($assignmentCounts[$treatmentId]/$totalAssigned*100, 1) . "%)\n\n";

$controlShare = $assignmentCounts[$controlId] / $totalAssigned;
assert_close(0.50, $controlShare, 0.08, "Control allocation share ~50% (±8pp)");
assert_close(0.50, 1 - $controlShare, 0.08, "Treatment allocation share ~50% (±8pp)");

// ─── Phase 2: Simulate Conversions (equal 5% rate for both) ─

echo "--- Phase 2: Generating conversion events (5% baseline) ---\n";

$baseRate = 0.05;
$controlConversions = 0;
$treatmentConversions = 0;

$stmt = $db->prepare("
    SELECT ea.variant_id, ea.visitor_id, ea.segment_key
    FROM experiment_assignments ea
    WHERE ea.experiment_id = ?
");
$stmt->execute([$expId]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$insertConv = $db->prepare("
    INSERT INTO experiment_events
        (experiment_id, variant_id, visitor_id, event_type, is_goal, value_cents, segment_key)
    VALUES (?, ?, ?, 'payment_success', 1, ?, ?)
");

$db->beginTransaction();
foreach ($assignments as $a) {
    if (mt_rand() / mt_getrandmax() < $baseRate) {
        $valueCents = mt_rand(2900, 9900);
        $insertConv->execute([$expId, $a['variant_id'], $a['visitor_id'], $valueCents, $a['segment_key']]);

        if ($a['variant_id'] === $controlId) $controlConversions++;
        else $treatmentConversions++;
    }
}
$db->commit();

echo "  Control:   {$controlConversions} (" . round($controlConversions/$assignmentCounts[$controlId]*100, 2) . "%)\n";
echo "  Treatment: {$treatmentConversions} (" . round($treatmentConversions/$assignmentCounts[$treatmentId]*100, 2) . "%)\n\n";

// ─── Phase 3: SRM Detection ──────────────────────────────────

echo "--- Phase 3: SRM Detection ---\n";

$statsService = new ExperimentStatsService($db, $bandit);
$srmResult = $statsService->detectSRMForExperiment($experiment);

if ($srmResult === null) {
    assert_true(true, "No SRM detected (p >= 0.001)");
    echo "  Result: No SRM ✓\n";
} else {
    assert_true(false, "SRM detected: chi_sq={$srmResult['chi_square']} p={$srmResult['p_value']}");
    echo "  Result: SRM DETECTED ✗\n";
    echo "  Chi-square: {$srmResult['chi_square']}, p-value: {$srmResult['p_value']}\n";
}
echo "\n";

// ─── Phase 4: Refresh Stats & Check for False Winner ─────────

echo "--- Phase 4: Stats Refresh + False Winner Check ---\n";

$statsService->refreshAllStats();
$report = $statsService->getReport($expId);

$probsAboveThreshold = 0;
if ($report) {
    foreach ($report['variants'] as $v) {
        echo "  {$v['key_slug']}: prob_best=" . round($v['prob_best']*100, 2) . "%, exposures={$v['exposures']}, conv_rate={$v['conversion_rate']}\n";
        if ($v['prob_best'] >= 0.95) $probsAboveThreshold++;
    }
    if (!empty($report['comparisons'])) {
        foreach ($report['comparisons'] as $c) {
            echo "  vs control: uplift={$c['relative_uplift']}%, p={$c['z_test_p_value']}\n";
        }
    }
}

assert_true($probsAboveThreshold === 0,
    "No false winner: zero variants with prob_best >= 0.95 (found {$probsAboveThreshold})");
echo "\n";

// ─── Summary ──────────────────────────────────────────────────

echo "=== Results ===\n";
foreach ($assertions as $a) echo $a . "\n";
echo "\n========================================\n";
echo "  {$passCount} passed, {$failCount} failed\n";
echo "========================================\n";

exit($failCount > 0 ? 1 : 0);
