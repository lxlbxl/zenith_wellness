<?php
/**
 * Decay Insights Cron Script
 *
 * Nightly maintenance sweep for variant_insights:
 * 1. Decay weight on insights > 90 days old
 * 2. Deactivate contradicted insights
 *
 * Run nightly via cron: 0 2 * * * php /path/to/decay_insights.php
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/InsightExtractionService.php';

// Initialize
$database = new Database();
$db = $database->getConnection();

$extractionService = new InsightExtractionService($db);

echo "=== Insight Decay Sweep ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $result = $extractionService->nightlySweep();

    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

    if (!empty($result['success'])) {
        echo "✓ Decayed weight on {$result['decayed']} old insights\n";
        echo "✓ Deactivated {$result['deactivated']} contradicted/expired insights\n";
    } else {
        echo "✗ Error during sweep\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Fatal error: {$e->getMessage()}\n";
    exit(1);
}

echo "\n=== Done ===\n";
