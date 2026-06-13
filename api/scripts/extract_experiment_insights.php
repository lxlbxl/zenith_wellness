<?php
/**
 * Extract Experiment Insights Cron Script
 *
 * Run this after experiments ship/archive to extract pattern insights:
 *   php api/scripts/extract_experiment_insights.php [--experiment-id=<id>] [--ai-summary]
 *
 * Without --experiment-id, processes all pending experiments (shipped/archived
 * that haven't been extracted yet).
 *
 * With --ai-summary, also generates AI-powered summary text for each insight.
 *
 * Recommended cron (nightly): 0 3 * * * php /path/to/extract_experiment_insights.php --ai-summary
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/ExperimentStatsService.php';
require_once __DIR__ . '/../services/InsightExtractionService.php';

// Parse CLI args
$options = getopt('', ['experiment-id:', 'ai-summary']);
$experimentId = $options['experiment-id'] ?? null;
$aiSummary = isset($options['ai-summary']);

// Initialize
$database = new Database();
$db = $database->getConnection();

$statsService = new ExperimentStatsService($db);
$extractionService = new InsightExtractionService($db, $statsService);

echo "=== Insight Extraction ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "AI Summaries: " . ($aiSummary ? 'enabled' : 'disabled') . "\n\n";

try {
    if ($experimentId) {
        echo "Processing experiment: {$experimentId}\n";
        $result = $extractionService->extractFromExperiment($experimentId, [
            'ai_summary' => $aiSummary,
        ]);
    } else {
        echo "Processing all pending experiments...\n";
        $result = $extractionService->extractAllPending([
            'ai_summary' => $aiSummary,
        ]);
    }

    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

    if (!empty($result['success'])) {
        if ($experimentId) {
            echo "✓ Extracted {$result['insights_created']} insights from {$result['experiment_id']}\n";
        } else {
            $successCount = 0;
            foreach ($result['results'] ?? [] as $r) {
                $status = $r['success'] ? "✓ {$r['insights_count']} insights" : "✗ {$r['error']}";
                echo "  {$r['key_slug']}: {$status}\n";
                if ($r['success']) $successCount++;
            }
            echo "\nProcessed {$result['total_processed']} experiments ({$successCount} successful)\n";
        }
    } else {
        echo "✗ Error: {$result['error']}\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Fatal error: {$e->getMessage()}\n";
    exit(1);
}

echo "\n=== Done ===\n";
