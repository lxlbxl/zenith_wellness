<?php
require_once __DIR__ . '/../api/services/ExperimentStatsService.php';
require_once __DIR__ . '/../api/services/InsightExtractionService.php';

class StubStats extends ExperimentStatsService {
    public function __construct() {}
    public function getReport(string $id, string $seg = 'global'): ?array {
        if ($id !== 'exp-pending-1') return null;
        return [
            'experiment' => ['id' => 'exp-pending-1', 'key_slug' => 'test-pending', 'surface' => 'email', 'status' => 'shipped', 'segment_key' => null],
            'variants' => [
                ['variant_id' => 'p5-c', 'key_slug' => 'control', 'is_control' => 1, 'prob_best' => 0.20, 'exposures' => 1000],
                ['variant_id' => 'p5-w', 'key_slug' => 'winner', 'is_control' => 0, 'prob_best' => 0.80, 'exposures' => 1000],
                ['variant_id' => 'p5-l', 'key_slug' => 'loser', 'is_control' => 0, 'prob_best' => 0.00, 'exposures' => 1000],
            ],
            'comparisons' => [['variant_id' => 'p5-w', 'relative_uplift' => 0.10]],
        ];
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('CREATE TABLE variant_candidates (id TEXT, experiment_id TEXT, promoted_variant_id TEXT, pattern_tags TEXT)');
$db->exec('CREATE TABLE variant_insights (id TEXT, surface TEXT, segment_key TEXT, pattern_tag TEXT, direction TEXT, effect_size REAL, confidence REAL, sample_size INTEGER, source_experiment_id TEXT, summary TEXT, weight REAL, is_active INTEGER, created_at TEXT)');
$db->exec('CREATE TABLE experiment_decisions (experiment_id TEXT PRIMARY KEY, decision_type TEXT, actor TEXT, rationale TEXT)');
$db->exec('CREATE TABLE experiments (id TEXT PRIMARY KEY, key_slug TEXT, name TEXT, surface TEXT, status TEXT, updated_at TEXT)');

$db->exec("INSERT INTO experiments VALUES ('exp-pending-1', 'test-pending', 'X', 'email', 'shipped', datetime('now'))");
$db->exec("INSERT INTO variant_candidates VALUES ('p5-vc-w', 'exp-pending-1', 'p5-w', '[\"test-tag\"]')");
$db->exec("INSERT INTO variant_candidates VALUES ('p5-vc-l', 'exp-pending-1', 'p5-l', '[]')");

$svc = new InsightExtractionService($db, new StubStats());
$r = $svc->extractFromExperiment('exp-pending-1');
echo "extractFromExperiment:\n" . json_encode($r, JSON_PRETTY_PRINT) . "\n\n";

$r2 = $svc->extractAllPending();
echo "extractAllPending:\n" . json_encode($r2, JSON_PRETTY_PRINT) . "\n";
