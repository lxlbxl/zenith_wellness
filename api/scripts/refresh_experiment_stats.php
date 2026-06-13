<?php
/**
 * refresh_experiment_stats.php — Cron script to refresh A/B experiment statistics.
 *
 * Runs every 5 minutes via cron:
 *   1. Refresh experiment_segment_stats from raw experiment_events
 *   2. Compute Monte Carlo prob_best (N=20000)
 *   3. SRM detection via chi-square test
 *   4. Auto-promote check
 *
 * Usage:
 *   php api/scripts/refresh_experiment_stats.php
 *
 * Cron entry:
 *   [star]/5 * * * * php /path/to/api/scripts/refresh_experiment_stats.php > /dev/null 2>&1
 */

// ─── Bootstrap ─────────────────────────────────────────────────

// Resolve base path — handle both direct and api/scripts/ relative calls
$basePath = dirname(__DIR__); // Goes up from scripts/ to api/

require_once $basePath . '/config.php';
require_once $basePath . '/config/Database.php';
require_once $basePath . '/services/BanditEngine.php';
require_once $basePath . '/services/ExperimentAssignmentService.php';
require_once $basePath . '/services/ExperimentTrackingService.php';
require_once $basePath . '/services/ExperimentStatsService.php';

// ─── Main ──────────────────────────────────────────────────────

$startTime = microtime(true);
$output = [];

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new RuntimeException('Failed to connect to database');
    }

    $statsService = new ExperimentStatsService($db);

    // 1. Refresh all segment stats from raw events
    $refreshResult = $statsService->refreshAllStats();
    $output['refresh'] = $refreshResult;

    // 2. SRM detection
    $srmAlerts = $statsService->detectSRM();
    $output['srm_alerts_count'] = count($srmAlerts);
    if (!empty($srmAlerts)) {
        $output['srm_alerts'] = $srmAlerts;
        foreach ($srmAlerts as $alert) {
            error_log(sprintf(
                '[AB-SRM] SRM detected: experiment=%s chi_square=%.4f p_value=%.6f',
                $alert['key_slug'],
                $alert['chi_square'],
                $alert['p_value']
            ));
        }
    }

    // 3. Auto-promote check
    $autoPromotions = $statsService->autoPromoteCheck();
    $output['auto_promotions_count'] = count($autoPromotions);
    if (!empty($autoPromotions)) {
        $output['auto_promotions'] = $autoPromotions;
        foreach ($autoPromotions as $promo) {
            error_log(sprintf(
                '[AB-PROMOTE] Auto-promoted: experiment=%s variant=%s',
                $promo['key_slug'],
                $promo['winning_variant_name']
            ));
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    $output['elapsed_ms'] = $elapsed;
    $output['status'] = 'success';

} catch (Throwable $e) {
    $output['status'] = 'error';
    $output['error'] = $e->getMessage();
    $output['elapsed_ms'] = round((microtime(true) - $startTime) * 1000, 2);

    error_log('[AB-CRON] Refresh failed: ' . $e->getMessage());
}

// ─── Output ────────────────────────────────────────────────────

echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
