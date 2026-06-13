<?php
/**
 * Unit tests for InsightExtractionService
 * Uses in-memory SQLite with a stub ExperimentStatsService.
 */

require_once __DIR__ . '/../api/services/ExperimentStatsService.php';
require_once __DIR__ . '/../api/services/InsightExtractionService.php';

define('ENCRYPTION_KEY', 'test_encryption_key_32ch................');

$pass = 0;
$fail = 0;

function assert_eq($expected, $actual, string $label): void {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
    }
}

function assert_true(bool $value, string $label): void {
    global $pass, $fail;
    if ($value) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    Expected true, got false\n";
    }
}

function assert_count(int $expected, array $array, string $label): void {
    global $pass, $fail;
    if (count($array) === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    Expected count {$expected}, got " . count($array) . "\n";
    }
}

// Stub ExperimentStatsService that returns predefined reports
class StubExperimentStatsService extends ExperimentStatsService {
    private array $reports;

    public function __construct(array $reports) {
        $this->reports = $reports;
    }

    public function getReport(string $experimentId, string $segmentKey = 'global'): ?array {
        return $this->reports[$experimentId] ?? null;
    }
}

function setup_db(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE TABLE variant_candidates (
        id TEXT PRIMARY KEY,
        generation_id TEXT,
        config TEXT,
        pattern_tags TEXT,
        promoted_variant_id TEXT,
        experiment_id TEXT
    )");

    $db->exec("CREATE TABLE experiment_variants (
        id TEXT PRIMARY KEY,
        experiment_id TEXT NOT NULL,
        key_slug TEXT,
        config TEXT,
        is_control INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE variant_insights (
        id TEXT PRIMARY KEY,
        surface TEXT NOT NULL,
        segment_key TEXT,
        pattern_tag TEXT NOT NULL,
        direction TEXT NOT NULL,
        effect_size REAL,
        confidence REAL,
        sample_size INTEGER,
        source_experiment_id TEXT,
        summary TEXT,
        weight REAL DEFAULT 0.5,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE experiment_decisions (
        experiment_id TEXT PRIMARY KEY,
        decision_type TEXT NOT NULL,
        actor TEXT,
        rationale TEXT
    )");

    $db->exec("CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        is_encrypted INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE experiments (
        id TEXT PRIMARY KEY, key_slug TEXT, name TEXT, surface TEXT, status TEXT, updated_at TEXT
    )");

    return $db;
}

echo "=== InsightExtractionService Tests ===\n\n";

// =========================================
// GROUP 1: Basic extraction
// =========================================
echo "--- Group 1: Basic Extraction ---\n";

// Winner tags: personalization, curiosity-gap, emojis
// Loser (control) tags: empty (control has no VC record)
// Expected: 3 winner-only insights (winner tags vs empty loser)
$report1 = [
    'experiment' => [
        'id' => 'exp-1', 'key_slug' => 'test-email-welcome',
        'surface' => 'email', 'status' => 'shipped', 'segment_key' => null,
    ],
    'variants' => [
        ['variant_id' => 'var-control-1', 'key_slug' => 'control',   'is_control' => 1, 'prob_best' => 0.15, 'exposures' => 5000],
        ['variant_id' => 'var-winner-1',  'key_slug' => 'personalized-subject', 'is_control' => 0, 'prob_best' => 0.75, 'exposures' => 5000],
        ['variant_id' => 'var-loser-1',   'key_slug' => 'urgency-subject',       'is_control' => 0, 'prob_best' => 0.10, 'exposures' => 5000],
    ],
    'comparisons' => [
        ['variant_id' => 'var-winner-1', 'relative_uplift' => 0.12],
        ['variant_id' => 'var-loser-1',  'relative_uplift' => -0.03],
    ],
];

$db1 = setup_db();
$db1->exec("INSERT INTO variant_candidates (id, experiment_id, promoted_variant_id, pattern_tags) VALUES
    ('vc-win-1', 'exp-1', 'var-winner-1', '[\"personalization\",\"curiosity-gap\",\"emojis\"]'),
    ('vc-lose-1', 'exp-1', 'var-loser-1', '[\"urgency\",\"fomo\",\"emojis\"]')
");

$service1 = new InsightExtractionService($db1, new StubExperimentStatsService(['exp-1' => $report1]));
$result1 = $service1->extractFromExperiment('exp-1');

assert_true($result1['success'], 'Basic extraction succeeds');
assert_eq('personalized-subject', $result1['winner_variant'], 'Correct winner variant');
assert_eq(0.12, $result1['uplift'], 'Uplift extracted from comparisons');

// Loser is control (no pattern_tags), so winner-only = 3 tags
assert_count(3, $result1['insights'], '3 insights (winner vs control with no tags)');

$insightTags = array_column($result1['insights'], 'pattern_tag');
assert_true(in_array('personalization', $insightTags), 'personalization insight created');
assert_true(in_array('curiosity-gap', $insightTags), 'curiosity-gap insight created');
assert_true(in_array('emojis', $insightTags), 'emojis insight created');

// =========================================
// GROUP 2: Edge cases
// =========================================
echo "\n--- Group 2: Edge Cases ---\n";

// 2a: Experiment not found
$db2 = setup_db();
$result2a = (new InsightExtractionService($db2, new StubExperimentStatsService([])))
    ->extractFromExperiment('nonexistent');
assert_true(!$result2a['success'], 'Missing experiment returns success=false');

// 2b: Treatment vs treatment (no control) — loser is lowest prob_best treatment
$report2b = [
    'experiment' => ['id' => 'exp-2b', 'key_slug' => 'test-ab', 'surface' => 'email', 'status' => 'shipped', 'segment_key' => null],
    'variants' => [
        ['variant_id' => 'v2b-1', 'key_slug' => 'var-a', 'is_control' => 0, 'prob_best' => 0.80, 'exposures' => 5000],
        ['variant_id' => 'v2b-2', 'key_slug' => 'var-b', 'is_control' => 0, 'prob_best' => 0.20, 'exposures' => 5000],
    ],
    'comparisons' => [['variant_id' => 'v2b-1', 'relative_uplift' => 0.08]],
];
$db2b = setup_db();
$db2b->exec("INSERT INTO variant_candidates (id, experiment_id, promoted_variant_id, pattern_tags) VALUES
    ('v2b-vc-w', 'exp-2b', 'v2b-1', '[\"tag-a\"]'),
    ('v2b-vc-l', 'exp-2b', 'v2b-2', '[\"tag-b\"]')
");
$result2b = (new InsightExtractionService($db2b, new StubExperimentStatsService(['exp-2b' => $report2b])))
    ->extractFromExperiment('exp-2b');
assert_true($result2b['success'], 'Treatment vs treatment extraction succeeds');
assert_count(2, $result2b['insights'], '2 insights (tag-a winner-only, tag-b loser-only)');
$insightTags2b = array_column($result2b['insights'], 'pattern_tag');
assert_true(in_array('tag-a', $insightTags2b), 'tag-a insight created (winner-only)');
assert_true(in_array('tag-b', $insightTags2b), 'tag-b insight created (loser-only)');

// =========================================
// GROUP 3: Weighted upsert (blending)
// =========================================
echo "\n--- Group 3: Weighted Upsert ---\n";

$report3 = [
    'experiment' => ['id' => 'exp-blend-1', 'key_slug' => 'test-blend-1', 'surface' => 'landing_page', 'status' => 'shipped', 'segment_key' => null],
    'variants' => [
        ['variant_id' => 'b1-c', 'key_slug' => 'control',    'is_control' => 1, 'prob_best' => 0.20, 'exposures' => 2000],
        ['variant_id' => 'b1-w', 'key_slug' => 'hero-social', 'is_control' => 0, 'prob_best' => 0.80, 'exposures' => 2000],
        ['variant_id' => 'b1-l', 'key_slug' => 'hero-generic', 'is_control' => 0, 'prob_best' => 0.00, 'exposures' => 2000],
    ],
    'comparisons' => [['variant_id' => 'b1-w', 'relative_uplift' => 0.15]],
];

$db3 = setup_db();
$db3->exec("INSERT INTO variant_candidates (id, experiment_id, promoted_variant_id, pattern_tags) VALUES
    ('b1-vc-w', 'exp-blend-1', 'b1-w', '[\"social-proof\",\"hero-image\"]'),
    ('b1-vc-l', 'exp-blend-1', 'b1-l', '[\"generic\"]')
");

$service3 = new InsightExtractionService($db3, new StubExperimentStatsService(['exp-blend-1' => $report3]));
$result3a = $service3->extractFromExperiment('exp-blend-1');
assert_true($result3a['success'], 'First blend extraction succeeds');
// Loser is control (no tags), so 2 insights (winner-only tags)
assert_count(2, $result3a['insights'], '2 insights from first extraction');

// Second extraction — re-blends into existing insights
$result3b = $service3->extractFromExperiment('exp-blend-1');
assert_true($result3b['success'], 'Second extraction succeeds (re-blends)');
assert_true($result3b['insights_created'] > 0, 'Insights returned on second extraction');

// Verify insights persisted in database
$stmt = $db3->query("SELECT COUNT(*) FROM variant_insights WHERE surface = 'landing_page'");
assert_true((int) $stmt->fetchColumn() > 0, 'Insights persisted in database');

// =========================================
// GROUP 4: Nightly sweep
// =========================================
echo "\n--- Group 4: Nightly Sweep ---\n";

$db4 = setup_db();
$service4 = new InsightExtractionService($db4, new StubExperimentStatsService([]));

// Insert fresh (30 days) and old (95+, 100+ days) insights
$db4->exec("INSERT INTO variant_insights (id, surface, pattern_tag, direction, effect_size, confidence, sample_size, weight, is_active, created_at) VALUES
    ('ins-fresh-1', 'email', 'personalization', 'lifts', 0.10, 0.90, 5000, 0.8, 1, datetime('now', '-30 days')),
    ('ins-old-1',   'email', 'urgency', 'hurts', -0.05, 0.70, 3000, 0.6, 1, datetime('now', '-95 days')),
    ('ins-old-2',   'email', 'fomo', 'lifts', 0.08, 0.80, 4000, 0.9, 1, datetime('now', '-100 days'))
");

$sweepResult = $service4->nightlySweep();
assert_true($sweepResult['success'], 'Nightly sweep succeeds');
assert_true($sweepResult['decayed'] >= 2, 'At least 2 old insights decayed');

// Check old insight weight reduced
$stmt = $db4->query("SELECT weight FROM variant_insights WHERE id = 'ins-old-2'");
$oldWeight = (float) $stmt->fetchColumn();
assert_true($oldWeight < 0.9, 'Old insight weight decayed');

// Fresh insight weight unchanged
$stmt = $db4->query("SELECT weight FROM variant_insights WHERE id = 'ins-fresh-1'");
$freshWeight = (float) $stmt->fetchColumn();
assert_eq(0.8, $freshWeight, 'Fresh insight weight unchanged');

// =========================================
// GROUP 5: ExtractAllPending
// =========================================
echo "\n--- Group 5: ExtractAllPending ---\n";

$db5 = setup_db();
$db5->exec("INSERT INTO experiments (id, key_slug, name, surface, status) VALUES
    ('exp-pending-1', 'test-pending', 'Test Pending', 'email', 'shipped')
");
$db5->exec("INSERT INTO variant_candidates (id, experiment_id, promoted_variant_id, pattern_tags) VALUES
    ('p5-vc-w', 'exp-pending-1', 'p5-w', '[\"test-tag\"]'),
    ('p5-vc-l', 'exp-pending-1', 'p5-l', '[]')
");

$report5 = [
    'experiment' => ['id' => 'exp-pending-1', 'key_slug' => 'test-pending', 'surface' => 'email', 'status' => 'shipped', 'segment_key' => null],
    'variants' => [
        ['variant_id' => 'p5-c', 'key_slug' => 'control', 'is_control' => 1, 'prob_best' => 0.20, 'exposures' => 1000],
        ['variant_id' => 'p5-w', 'key_slug' => 'winner', 'is_control' => 0, 'prob_best' => 0.80, 'exposures' => 1000],
        ['variant_id' => 'p5-l', 'key_slug' => 'loser', 'is_control' => 0, 'prob_best' => 0.00, 'exposures' => 1000],
    ],
    'comparisons' => [['variant_id' => 'p5-w', 'relative_uplift' => 0.10]],
];

$pendingResult = (new InsightExtractionService($db5, new StubExperimentStatsService(['exp-pending-1' => $report5])))
    ->extractAllPending();

assert_true($pendingResult['success'], 'ExtractAllPending succeeds');
assert_eq(1, $pendingResult['total_processed'], 'One pending experiment processed');
assert_true($pendingResult['results'][0]['success'], 'Pending experiment extracted successfully');

// =========================================
// SUMMARY
// =========================================
echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";

exit($fail > 0 ? 1 : 0);
