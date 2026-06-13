<?php
/**
 * Unit tests for VariantSafetyService
 * Uses in-memory SQLite to avoid external DB dependency.
 */

// Bootstrap
require_once __DIR__ . '/../api/services/VariantSafetyService.php';

// Override constants used by the service
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

function assert_contains(string $haystack, string $needle, string $label): void {
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    Expected '{$needle}' in:\n    {$haystack}\n";
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

function setup_db(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create tables needed by VariantSafetyService
    $db->exec("CREATE TABLE variant_generations (
        id TEXT PRIMARY KEY,
        surface TEXT NOT NULL,
        status TEXT DEFAULT 'draft'
    )");

    $db->exec("CREATE TABLE variant_candidates (
        id TEXT PRIMARY KEY,
        generation_id TEXT NOT NULL,
        config TEXT,
        rationale TEXT,
        safety_flag TEXT DEFAULT 'pending',
        safety_notes TEXT,
        review_status TEXT DEFAULT 'pending',
        FOREIGN KEY (generation_id) REFERENCES variant_generations(id)
    )");

    $db->exec("CREATE TABLE variant_briefs (
        surface TEXT PRIMARY KEY,
        forbidden_claims TEXT
    )");

    $db->exec("CREATE TABLE variant_safety_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        candidate_id TEXT NOT NULL,
        check_type TEXT NOT NULL,
        verdict TEXT NOT NULL,
        details TEXT,
        model_used TEXT,
        latency_ms INTEGER,
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        is_encrypted INTEGER DEFAULT 0
    )");

    return $db;
}

function seed_safety_test_data(PDO $db): void {
    // Insert a generation
    $db->exec("INSERT INTO variant_generations (id, surface, status) VALUES
        ('gen-test-1', 'email', 'active'),
        ('gen-test-2', 'landing_page', 'active'),
        ('gen-test-3', 'sms', 'active')
    ");

    // Insert briefs with forbidden claims
    $db->exec("INSERT INTO variant_briefs (surface, forbidden_claims) VALUES
        ('email', '[\"guaranteed results\",\"lose weight fast\",\"miracle cure\",\"no risk\"]'),
        ('landing_page', '[\"clinically proven\",\"money back guarantee\",\"secret formula\"]'),
        ('sms', '[\"act now\",\"limited time only\",\"don\\\"t miss out\"]')
    ");
}

echo "=== VariantSafetyService Tests ===\n\n";

// =========================================
// TEST GROUP 1: Deterministic Scan
// =========================================
echo "--- Group 1: Deterministic Scan ---\n";

$db = setup_db();
seed_safety_test_data($db);

// Insert a candidate with a forbidden claim in the config
$candidateId = 'cand-test-1';
$db->exec("INSERT INTO variant_candidates (id, generation_id, config, rationale) VALUES (
    'cand-test-1',
    'gen-test-1',
    '{\"headline\":\"Lose weight fast with our miracle program!\",\"subheadline\":\"See guaranteed results in just 7 days\",\"cta\":\"Start Now\"}',
    'Focus on speed of results'
)");

$service = new VariantSafetyService($db);
$result = $service->check($candidateId);

assert_eq(true, $result['success'], 'Check returns success=true');
assert_eq('reject', $result['overall_verdict'], 'Deterministic scan rejects candidate with forbidden claims');
// Only 1 pass expected — deterministic short-circuits on hard reject before AI pass runs
assert_count(1, $result['passes'], 'One pass (deterministic short-circuits on reject)');

// Check that specific claims were detected
$foundGuaranteed = false;
$foundLoseWeight = false;
foreach ($result['safety_notes'] as $note) {
    if (str_contains($note, 'guaranteed results')) $foundGuaranteed = true;
    if (str_contains($note, 'lose weight fast')) $foundLoseWeight = true;
}
assert_eq(true, $foundGuaranteed, 'Detected "guaranteed results" claim');
assert_eq(true, $foundLoseWeight, 'Detected "lose weight fast" claim');

// =========================================
// TEST GROUP 2: Clean Candidate (no forbidden claims)
// =========================================
echo "\n--- Group 2: Clean Candidate ---\n";

$db2 = setup_db();
seed_safety_test_data($db2);

$db2->exec("INSERT INTO variant_candidates (id, generation_id, config, rationale) VALUES (
    'cand-clean-1',
    'gen-test-1',
    '{\"headline\":\"Start your wellness journey today!\",\"subheadline\":\"Build healthy habits that stick\",\"cta\":\"Learn More\"}',
    'Positive framing'
)");

$service2 = new VariantSafetyService($db2);
$result2 = $service2->check('cand-clean-1');

assert_eq(true, $result2['success'], 'Clean candidate returns success=true');

// The deterministic pass should be clean, but the AI pass may flag things
// since it uses a simulated empty API response (no API key configured)
$passes = $result2['passes'];
assert_eq('clean', $passes[0]['verdict'], 'Deterministic pass verdict is clean');

// =========================================
// TEST GROUP 3: deterministicOnly() method
// =========================================
echo "\n--- Group 3: deterministicOnly() ---\n";

$db3 = setup_db();
seed_safety_test_data($db3);

$db3->exec("INSERT INTO variant_candidates (id, generation_id, config, rationale) VALUES (
    'cand-det-1',
    'gen-test-2',
    '{\"headline\":\"Clinically proven results!\",\"body\":\"Our secret formula works.\",\"cta\":\"Buy Now\"}',
    'Claims heavy'
)");

$service3 = new VariantSafetyService($db3);
$result3 = $service3->deterministicOnly('cand-det-1');

assert_eq('reject', $result3['verdict'], 'deterministicOnly rejects forbidden claims');
assert_count(2, $result3['matches'], 'Two forbidden claim matches found');

// =========================================
// TEST GROUP 4: Missing candidate
// =========================================
echo "\n--- Group 4: Edge Cases ---\n";

$db4 = setup_db();
seed_safety_test_data($db4);
$service4 = new VariantSafetyService($db4);
$result4 = $service4->check('nonexistent-id');

assert_eq(false, $result4['success'], 'Missing candidate returns success=false');
assert_eq('Candidate not found', $result4['error'], 'Missing candidate returns correct error');

// =========================================
// TEST GROUP 5: Case-insensitive stemming check
// =========================================
$db5 = setup_db();
seed_safety_test_data($db5);

$db5->exec("INSERT INTO variant_candidates (id, generation_id, config, rationale) VALUES (
    'cand-case-1',
    'gen-test-3',
    '{\"headline\":\"ACT NOW! Limited Time Only!\",\"body\":\"Dont Miss Out on this offer.\"}',
    'Urgency'
)");

$service5 = new VariantSafetyService($db5);
$result5 = $service5->deterministicOnly('cand-case-1');
assert_eq('reject', $result5['verdict'], 'Case-insensitive: ACT NOW detected');

// =========================================
// TEST GROUP 6: Empty config edge case
// =========================================
$db6 = setup_db();
seed_safety_test_data($db6);

$db6->exec("INSERT INTO variant_candidates (id, generation_id, config, rationale) VALUES (
    'cand-empty-1',
    'gen-test-1',
    '{}',
    ''
)");

$service6 = new VariantSafetyService($db6);
$result6 = $service6->check('cand-empty-1');
assert_eq(true, $result6['success'], 'Empty config still processes successfully');

// =========================================
// TEST GROUP 7: Stemmer tests (white-box)
// =========================================
echo "\n--- Group 7: Stemmer ---\n";

$ref = new ReflectionClass(VariantSafetyService::class);
$stemMethod = $ref->getMethod('stem');
$stemMethod->setAccessible(true);

$service7 = new VariantSafetyService(setup_db());

assert_eq('guarante', $stemMethod->invoke($service7, 'guaranteed'), 'Stem: guaranteed -> guarante');
assert_eq('result', $stemMethod->invoke($service7, 'results'), 'Stem: results -> result');
assert_eq('miracle', $stemMethod->invoke($service7, 'miracle'), 'Stem: miracle -> miracle (unchanged)');
assert_eq('cur', $stemMethod->invoke($service7, 'cures'), 'Stem: cures -> cur');
assert_eq('proven', $stemMethod->invoke($service7, 'proven'), 'Stem: proven -> proven');

// =========================================
// SUMMARY
// =========================================
echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";

exit($fail > 0 ? 1 : 0);
