<?php
/**
 * ExperimentStatsService — Statistical computation and reporting for experiments.
 *
 * A.5 — Responsibilities:
 *  - Refresh experiment_segment_stats from raw experiment_events
 *  - Monte Carlo prob_best computation (N=20000) via BanditEngine
 *  - SRM detection via chi-square test (p < 0.001)
 *  - Auto-promote check
 *  - Full report generation (uplift, 95% CI, expected loss, z-test, funnel, revenue)
 *  - Experiment lifecycle actions (start, pause, ship, rollback)
 *  - Timeline from decisions + events
 */
class ExperimentStatsService
{
    private PDO $db;
    private BanditEngine $bandit;

    /** Monte Carlo draws for prob_best and CI estimation. */
    private const MC_SAMPLES = 20000;
    /** SRM significance threshold. */
    private const SRM_P_THRESHOLD = 0.001;

    public function __construct(PDO $db, ?BanditEngine $bandit = null)
    {
        $this->db = $db;
        $this->bandit = $bandit ?? new BanditEngine($db);
    }

    // ─── A.5.1 — Refresh All Segment Stats ──────────────────────

    /**
     * Refresh experiment_segment_stats for all running experiments.
     *
     * Called by cron every 5 minutes. Recomputes alpha/beta posteriors
     * from raw experiment_events, then runs MC for prob_best.
     *
     * @return array{experiments_refreshed: int, segments_updated: int}
     */
    public function refreshAllStats(): array
    {
        $experiments = $this->loadRunningExperiments();
        $totalSegments = 0;

        foreach ($experiments as $experiment) {
            $variants = $this->loadVariants($experiment['id']);
            $segmentKeys = $this->loadSegmentKeys($experiment['id']);

            foreach ($variants as $variant) {
                foreach ($segmentKeys as $segmentKey) {
                    $this->refreshSegmentStats(
                        $experiment['id'],
                        $variant['id'],
                        $segmentKey
                    );
                    $totalSegments++;
                }
            }

            // Compute prob_best via Monte Carlo for each segment
            $this->computeProbBestForExperiment($experiment['id'], $variants, $segmentKeys);
        }

        return [
            'experiments_refreshed' => count($experiments),
            'segments_updated' => $totalSegments,
        ];
    }

    /**
     * Refresh stats for one experiment/variant/segment combination.
     */
    public function refreshSegmentStats(
        string $experimentId,
        string $variantId,
        string $segmentKey = 'global'
    ): void {
        $stmtExp = $this->db->prepare(
            "SELECT COUNT(*) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND event_type = 'exposure'"
        );
        $stmtExp->execute([$experimentId, $variantId, $segmentKey]);
        $exposures = (int) $stmtExp->fetchColumn();

        $stmtConv = $this->db->prepare(
            "SELECT COUNT(*) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND is_goal = 1"
        );
        $stmtConv->execute([$experimentId, $variantId, $segmentKey]);
        $conversions = (int) $stmtConv->fetchColumn();

        $stmtRev = $this->db->prepare(
            "SELECT COALESCE(SUM(value_cents), 0) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND is_goal = 1"
        );
        $stmtRev->execute([$experimentId, $variantId, $segmentKey]);
        $revenueCents = (int) $stmtRev->fetchColumn();

        // Beta posterior: alpha = conversions + 1, beta = exposures - conversions + 1
        $alpha = $conversions + 1;
        $beta = ($exposures - $conversions) + 1;

        $stmtFunnel = $this->db->prepare(
            "SELECT event_type, COUNT(*) as cnt FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?
             GROUP BY event_type"
        );
        $stmtFunnel->execute([$experimentId, $variantId, $segmentKey]);
        $funnelData = $stmtFunnel->fetchAll(PDO::FETCH_KEY_PAIR);

        // Upsert
        $checkStmt = $this->db->prepare(
            "SELECT id FROM experiment_segment_stats
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?"
        );
        $checkStmt->execute([$experimentId, $variantId, $segmentKey]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $updateStmt = $this->db->prepare(
                "UPDATE experiment_segment_stats SET
                    exposures = ?, conversions = ?, revenue_cents = ?,
                    funnel_counts = ?, alpha = ?, beta = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?"
            );
            $updateStmt->execute([
                $exposures, $conversions, $revenueCents,
                json_encode($funnelData), $alpha, $beta,
                $experimentId, $variantId, $segmentKey,
            ]);
        } else {
            $insertStmt = $this->db->prepare(
                "INSERT INTO experiment_segment_stats
                    (experiment_id, variant_id, segment_key, exposures, conversions, revenue_cents, funnel_counts, alpha, beta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->execute([
                $experimentId, $variantId, $segmentKey,
                $exposures, $conversions, $revenueCents,
                json_encode($funnelData), $alpha, $beta,
            ]);
        }
    }

    // ─── A.5.2 — Monte Carlo Prob Best ──────────────────────────

    /**
     * Compute prob_best for all variants in all segments of an experiment.
     * Stores results in experiment_segment_stats.prob_best.
     */
    private function computeProbBestForExperiment(
        string $experimentId,
        array $variants,
        array $segmentKeys
    ): void {
        foreach ($segmentKeys as $segmentKey) {
            $posteriors = [];

            foreach ($variants as $variant) {
                $stats = $this->loadSegmentStatsRow($experimentId, $variant['id'], $segmentKey);
                $posteriors[$variant['id']] = [
                    'alpha' => (float) ($stats['alpha'] ?? 1.0),
                    'beta'  => (float) ($stats['beta'] ?? 1.0),
                ];
            }

            $probBest = $this->bandit->computeProbBest($posteriors, self::MC_SAMPLES);

            foreach ($probBest as $variantId => $prob) {
                $updateStmt = $this->db->prepare(
                    "UPDATE experiment_segment_stats
                     SET prob_best = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?"
                );
                $updateStmt->execute([$prob, $experimentId, $variantId, $segmentKey]);
            }
        }
    }

    // ─── A.5.3 — SRM Detection (Chi-Square) ─────────────────────

    /**
     * Detect Sample Ratio Mismatch for all running experiments.
     *
     * Expected split: uniform (1/n) for bandit mode; uses fixed weights
     * when allocation_mode = 'fixed'.
     *
     * @return array  SRM alerts: [{experiment_id, key_slug, chi_square, p_value, details}, ...]
     */
    public function detectSRM(): array
    {
        $experiments = $this->loadRunningExperiments();
        $alerts = [];

        foreach ($experiments as $experiment) {
            $result = $this->detectSRMForExperiment($experiment);
            if ($result !== null) {
                $alerts[] = $result;
            }
        }

        return $alerts;
    }

    /**
     * Run SRM detection for a single experiment.
     *
     * @param array $experiment  Experiment row from DB.
     * @return array|null  SRM alert or null if no SRM detected / insufficient data.
     */
    public function detectSRMForExperiment(array $experiment): ?array
    {
        $variants = $this->loadVariants($experiment['id']);
        if (count($variants) < 2) {
            return null;
        }

        $isFixed = ($experiment['allocation_mode'] ?? 'bandit') === 'fixed';
        $totalWeight = 0.0;
        $weights = [];

        foreach ($variants as $v) {
            $w = $isFixed ? (float) ($v['fixed_weight'] ?? 1.0) : 1.0;
            if ($w <= 0) $w = 0.001;
            $weights[$v['id']] = $w;
            $totalWeight += $w;
        }

        $stmt = $this->db->prepare(
            "SELECT variant_id, COUNT(*) as cnt
             FROM experiment_assignments
             WHERE experiment_id = ?
             GROUP BY variant_id"
        );
        $stmt->execute([$experiment['id']]);
        $observed = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $totalObserved = array_sum(array_values($observed));
        if ($totalObserved < 10) {
            return null;
        }

        $chiSquare = 0.0;
        $details = [];

        foreach ($variants as $v) {
            $obs = (int) ($observed[$v['id']] ?? 0);
            $expected = $totalObserved * ($weights[$v['id']] / $totalWeight);

            $details[] = [
                'variant_id' => $v['id'],
                'key_slug' => $v['key_slug'],
                'observed' => $obs,
                'expected' => round($expected, 2),
            ];

            if ($expected > 0) {
                $chiSquare += pow($obs - $expected, 2) / $expected;
            }
        }

        $df = count($variants) - 1;
        $pValue = 1.0 - $this->chiSquareCdf($chiSquare, $df);

        if ($pValue < self::SRM_P_THRESHOLD) {
            $alert = [
                'experiment_id' => $experiment['id'],
                'key_slug' => $experiment['key_slug'],
                'chi_square' => round($chiSquare, 4),
                'p_value' => round($pValue, 6),
                'details' => $details,
            ];

            $this->logDecision(
                $experiment['id'],
                'srm_alert',
                null,
                'engine',
                $alert
            );

            return $alert;
        }

        return null;
    }

    // ─── A.5.4 — Auto-Promote Check ─────────────────────────────

    /**
     * Check auto-promote conditions for experiments with auto_promote = 1.
     *
     * Conditions:
     *  1. Experiment status = 'running'
     *  2. auto_promote = 1
     *  3. All variants have >= min_samples_per_variant exposures
     *  4. One variant has prob_best >= confidence_threshold in all segments
     *  5. No active SRM alert
     *
     * @return array  Actions taken: [{experiment_id, key_slug, winning_variant_id, action}, ...]
     */
    public function autoPromoteCheck(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM experiments WHERE status = 'running' AND auto_promote = 1"
        );
        $stmt->execute();
        $actions = [];

        while ($experiment = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $action = $this->checkAutoPromoteForExperiment($experiment);
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Check auto-promote for a single experiment.
     */
    public function checkAutoPromoteForExperiment(array $experiment): ?array
    {
        $experimentId = $experiment['id'];
        $minSamples = (int) ($experiment['min_samples_per_variant'] ?? 300);
        $threshold = (float) ($experiment['confidence_threshold'] ?? 0.95);

        $variants = $this->loadVariants($experimentId);

        // Check min samples
        foreach ($variants as $variant) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM experiment_events
                 WHERE experiment_id = ? AND variant_id = ? AND event_type = 'exposure'"
            );
            $stmt->execute([$experimentId, $variant['id']]);
            $samples = (int) $stmt->fetchColumn();
            if ($samples < $minSamples) {
                return null;
            }
        }

        // Check SRM
        $srm = $this->detectSRMForExperiment($experiment);
        if ($srm !== null) {
            return null;
        }

        // Check prob_best across all segments
        $segmentKeys = $this->loadSegmentKeys($experimentId);
        $bestVariantId = null;

        foreach ($segmentKeys as $segmentKey) {
            $statsList = $this->db->prepare(
                "SELECT variant_id, prob_best FROM experiment_segment_stats
                 WHERE experiment_id = ? AND segment_key = ?"
            );
            $statsList->execute([$experimentId, $segmentKey]);

            $winsThisSegment = [];
            while ($row = $statsList->fetch(PDO::FETCH_ASSOC)) {
                $prob = (float) ($row['prob_best'] ?? 0);
                if ($prob >= $threshold) {
                    $winsThisSegment[] = $row['variant_id'];
                }
            }

            if (count($winsThisSegment) !== 1) {
                return null; // Exactly one winner per segment required
            }

            $segWinner = $winsThisSegment[0];
            if ($bestVariantId === null) {
                $bestVariantId = $segWinner;
            } elseif ($bestVariantId !== $segWinner) {
                return null; // Different winners across segments
            }
        }

        if ($bestVariantId === null) {
            return null;
        }

        // Don't promote if control is the winner
        foreach ($variants as $v) {
            if ($v['id'] === $bestVariantId && (int) ($v['is_control'] ?? 0) === 1) {
                return null;
            }
        }

        // Ship the winner
        $this->shipExperiment($experimentId, $bestVariantId, 'engine');

        $winnerName = '';
        foreach ($variants as $v) {
            if ($v['id'] === $bestVariantId) {
                $winnerName = $v['name'];
                break;
            }
        }

        $action = [
            'experiment_id' => $experimentId,
            'key_slug' => $experiment['key_slug'],
            'winning_variant_id' => $bestVariantId,
            'winning_variant_name' => $winnerName,
            'action' => 'auto_promoted',
            'confidence_threshold' => $threshold,
        ];

        $this->logDecision(
            $experimentId,
            'auto_promote_suggested',
            $bestVariantId,
            'engine',
            $action
        );

        return $action;
    }

    // ─── A.5.5 — Report Generation ──────────────────────────────

    /**
     * Generate a comprehensive statistical report for an experiment.
     *
     * Report includes:
     *  - prob_best per variant
     *  - expected relative uplift with 95% CI (Monte Carlo)
     *  - expected loss
     *  - frequentist z-test p-value
     *  - funnel breakdown per variant
     *  - revenue-per-visitor
     *
     * @param string $experimentId
     * @param string $segmentKey  Optional segment filter (default 'global').
     * @return array|null
     */
    public function getReport(string $experimentId, string $segmentKey = 'global'): ?array
    {
        $experiment = $this->loadExperimentById($experimentId);
        if (!$experiment) {
            return null;
        }

        $variants = $this->loadVariants($experimentId);
        if (empty($variants)) {
            return null;
        }

        $statsPerVariant = [];
        $controlStats = null;
        $controlVariantId = null;

        foreach ($variants as $variant) {
            $stats = $this->loadSegmentStatsRow($experimentId, $variant['id'], $segmentKey);

            $exposures = (int) ($stats['exposures'] ?? 0);
            $conversions = (int) ($stats['conversions'] ?? 0);
            $revenueCents = (int) ($stats['revenue_cents'] ?? 0);
            $alpha = (float) ($stats['alpha'] ?? 1.0);
            $beta = (float) ($stats['beta'] ?? 1.0);
            $probBest = (float) ($stats['prob_best'] ?? 0);
            $funnelCounts = $stats['funnel_counts'] ?? '{}';

            if (is_string($funnelCounts)) {
                $funnelCounts = json_decode($funnelCounts, true) ?: [];
            }

            $conversionRate = $exposures > 0 ? $conversions / $exposures : 0.0;
            $revenuePerVisitor = $exposures > 0 ? round($revenueCents / $exposures, 2) : 0.0;

            $variantReport = [
                'variant_id' => $variant['id'],
                'key_slug' => $variant['key_slug'],
                'name' => $variant['name'],
                'is_control' => (int) ($variant['is_control'] ?? 0),
                'exposures' => $exposures,
                'conversions' => $conversions,
                'conversion_rate' => round($conversionRate, 6),
                'revenue_cents' => $revenueCents,
                'revenue_per_visitor' => $revenuePerVisitor,
                'alpha' => $alpha,
                'beta' => $beta,
                'prob_best' => $probBest,
                'funnel_breakdown' => $funnelCounts,
            ];

            if ((int) ($variant['is_control'] ?? 0) === 1) {
                $controlStats = $variantReport;
                $controlVariantId = $variant['id'];
            }

            $statsPerVariant[$variant['id']] = $variantReport;
        }

        $comparisons = [];

        if ($controlStats && $controlVariantId !== null) {
            $controlRate = $controlStats['conversion_rate'];

            foreach ($variants as $variant) {
                if ((int) ($variant['is_control'] ?? 0) === 1) {
                    continue;
                }

                $variantId = $variant['id'];
                $variantStats = $statsPerVariant[$variantId];
                $variantRate = $variantStats['conversion_rate'];

                $upliftResult = $this->computeUpliftCI(
                    $controlStats['alpha'], $controlStats['beta'],
                    $variantStats['alpha'], $variantStats['beta']
                );

                $expectedLoss = $this->computeExpectedLoss(
                    $controlStats['alpha'], $controlStats['beta'],
                    $variantStats['alpha'], $variantStats['beta']
                );

                $zTestPValue = $this->twoProportionZTest(
                    $controlStats['conversions'], $controlStats['exposures'],
                    $variantStats['conversions'], $variantStats['exposures']
                );

                $comparisons[] = [
                    'variant_id' => $variantId,
                    'key_slug' => $variant['key_slug'],
                    'name' => $variant['name'],
                    'control_rate' => $controlRate,
                    'variant_rate' => $variantRate,
                    'absolute_difference' => round($variantRate - $controlRate, 6),
                    'relative_uplift' => $upliftResult['relative_uplift'],
                    'uplift_ci_lower' => $upliftResult['ci_lower'],
                    'uplift_ci_upper' => $upliftResult['ci_upper'],
                    'expected_loss' => $expectedLoss,
                    'z_test_p_value' => $zTestPValue,
                    'prob_win' => $variantStats['prob_best'],
                ];
            }
        }

        return [
            'experiment' => $this->formatExperiment($experiment),
            'segment_key' => $segmentKey,
            'variants' => array_values($statsPerVariant),
            'comparisons' => $comparisons,
        ];
    }

    /**
     * Get the experiment timeline from decisions and significant events.
     */
    public function getTimeline(string $experimentId): array
    {
        $entries = [];

        $stmt = $this->db->prepare(
            "SELECT ed.*, ev.key_slug AS variant_key_slug, ev.name AS variant_name
             FROM experiment_decisions ed
             LEFT JOIN experiment_variants ev ON ev.id = ed.winning_variant_id
             WHERE ed.experiment_id = ?
             ORDER BY ed.created_at ASC"
        );
        $stmt->execute([$experimentId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = [
                'type' => 'decision',
                'timestamp' => $row['created_at'],
                'decision_type' => $row['decision_type'],
                'actor' => $row['actor'],
                'winning_variant' => $row['variant_key_slug']
                    ? ['key_slug' => $row['variant_key_slug'], 'name' => $row['variant_name']]
                    : null,
                'rationale' => $row['rationale'] ? json_decode($row['rationale'], true) : null,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT ee.event_type, ee.is_goal, COUNT(*) as count,
                    MIN(ee.created_at) as first_at, MAX(ee.created_at) as last_at
             FROM experiment_events ee
             WHERE ee.experiment_id = ? AND ee.event_type != 'exposure'
             GROUP BY ee.event_type, ee.is_goal
             ORDER BY first_at ASC"
        );
        $stmt->execute([$experimentId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = [
                'type' => 'event_milestone',
                'event_type' => $row['event_type'],
                'is_goal' => (int) $row['is_goal'],
                'count' => (int) $row['count'],
                'first_at' => $row['first_at'],
                'last_at' => $row['last_at'],
            ];
        }

        usort($entries, function ($a, $b) {
            $ta = $a['timestamp'] ?? $a['first_at'] ?? '';
            $tb = $b['timestamp'] ?? $b['first_at'] ?? '';
            return strcmp($ta, $tb);
        });

        return $entries;
    }

    // ─── Experiment Lifecycle Actions ──────────────────────────

    /**
     * Start a draft experiment.
     */
    public function startExperiment(string $experimentId, string $actor): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE experiments SET status = 'running', started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'draft'"
        );
        $stmt->execute([$experimentId]);

        if ($stmt->rowCount() > 0) {
            $this->logDecision($experimentId, 'started', null, $actor, ['trigger' => 'manual']);
            return true;
        }
        return false;
    }

    /**
     * Pause a running experiment.
     */
    public function pauseExperiment(string $experimentId, string $actor): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE experiments SET status = 'paused', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'running'"
        );
        $stmt->execute([$experimentId]);

        if ($stmt->rowCount() > 0) {
            $this->logDecision($experimentId, 'paused', null, $actor, ['trigger' => 'manual']);
            return true;
        }
        return false;
    }

    /**
     * Resume a paused experiment.
     */
    public function resumeExperiment(string $experimentId, string $actor): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE experiments SET status = 'running', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'paused'"
        );
        $stmt->execute([$experimentId]);

        if ($stmt->rowCount() > 0) {
            $this->logDecision($experimentId, 'resumed', null, $actor, ['trigger' => 'manual']);
            return true;
        }
        return false;
    }

    /**
     * Ship (promote) a winning variant — set status to 'shipped'.
     */
    public function shipExperiment(string $experimentId, string $winningVariantId, string $actor): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE experiments SET status = 'shipped', ended_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN ('running', 'paused')"
        );
        $stmt->execute([$experimentId]);

        if ($stmt->rowCount() > 0) {
            $winnerName = '';
            $vStmt = $this->db->prepare("SELECT name FROM experiment_variants WHERE id = ?");
            $vStmt->execute([$winningVariantId]);
            $winnerName = $vStmt->fetchColumn() ?: '';

            $report = $this->getReport($experimentId);
            $rationale = ['trigger' => 'manual', 'winner_name' => $winnerName];

            if ($report && !empty($report['comparisons'])) {
                foreach ($report['comparisons'] as $c) {
                    if ($c['variant_id'] === $winningVariantId) {
                        $rationale['prob_best'] = $c['prob_win'];
                        $rationale['uplift'] = $c['relative_uplift'];
                        $rationale['p_value'] = $c['z_test_p_value'];
                        break;
                    }
                }
            }

            $this->logDecision($experimentId, 'shipped', $winningVariantId, $actor, $rationale);
            return true;
        }
        return false;
    }

    /**
     * Rollback a shipped experiment — set status back to 'running'.
     */
    public function rollbackExperiment(string $experimentId, string $actor): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE experiments SET status = 'running', ended_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'shipped'"
        );
        $stmt->execute([$experimentId]);

        if ($stmt->rowCount() > 0) {
            $this->logDecision($experimentId, 'rolled_back', null, $actor, ['trigger' => 'manual']);
            return true;
        }
        return false;
    }

    // ─── Statistical Helpers ────────────────────────────────────

    /**
     * Compute expected relative uplift and 95% CI using Monte Carlo.
     *
     * @return array{relative_uplift: float|null, ci_lower: float|null, ci_upper: float|null}
     */
    private function computeUpliftCI(float $cAlpha, float $cBeta, float $vAlpha, float $vBeta): array
    {
        $draws = [];
        for ($i = 0; $i < self::MC_SAMPLES; $i++) {
            $cRate = $this->bandit->sampleBeta($cAlpha, $cBeta);
            $vRate = $this->bandit->sampleBeta($vAlpha, $vBeta);

            if ($cRate > 0) {
                $draws[] = ($vRate - $cRate) / $cRate;
            } else {
                $draws[] = 0.0;
            }
        }

        sort($draws);

        $lowerIdx = (int) round(self::MC_SAMPLES * 0.025);
        $upperIdx = (int) round(self::MC_SAMPLES * 0.975);
        $medianIdx = (int) round(self::MC_SAMPLES * 0.5);

        $lowerIdx = max(0, min($lowerIdx, self::MC_SAMPLES - 1));
        $upperIdx = max(0, min($upperIdx, self::MC_SAMPLES - 1));
        $medianIdx = max(0, min($medianIdx, self::MC_SAMPLES - 1));

        return [
            'relative_uplift' => round($draws[$medianIdx] * 100, 4),
            'ci_lower' => round($draws[$lowerIdx] * 100, 4),
            'ci_upper' => round($draws[$upperIdx] * 100, 4),
        ];
    }

    /**
     * Compute Expected Loss — the expected reduction in conversion rate
     * when picking the variant despite it being worse than control.
     *
     * @return float  Expected loss as percentage point difference.
     */
    private function computeExpectedLoss(float $cAlpha, float $cBeta, float $vAlpha, float $vBeta): float
    {
        $totalLoss = 0.0;
        $count = 0;

        for ($i = 0; $i < self::MC_SAMPLES; $i++) {
            $cRate = $this->bandit->sampleBeta($cAlpha, $cBeta);
            $vRate = $this->bandit->sampleBeta($vAlpha, $vBeta);

            if ($vRate < $cRate) {
                $totalLoss += ($cRate - $vRate);
                $count++;
            }
        }

        return $count > 0 ? round(($totalLoss / $count) * 100, 4) : 0.0;
    }

    /**
     * Two-proportion z-test (frequentist).
     *
     * H0: p1 = p2 (control = variant).
     * Two-tailed p-value.
     *
     * @return float  p-value (0-1).
     */
    private function twoProportionZTest(int $x1, int $n1, int $x2, int $n2): float
    {
        if ($n1 === 0 || $n2 === 0) {
            return 1.0;
        }

        $p1 = $x1 / $n1;
        $p2 = $x2 / $n2;
        $pBar = ($x1 + $x2) / ($n1 + $n2);

        $se = sqrt($pBar * (1 - $pBar) * (1 / $n1 + 1 / $n2));
        if ($se == 0) {
            return 1.0;
        }

        $z = ($p2 - $p1) / $se;

        // Two-tailed p-value from normal CDF
        $pValue = 2.0 * (1.0 - $this->normalCdf(abs($z)));
        return round(min(1.0, $pValue), 6);
    }

    /**
     * Normal CDF using the error function approximation.
     */
    private function normalCdf(float $x): float
    {
        return 0.5 * (1.0 + $this->erf($x / sqrt(2.0)));
    }

    /**
     * Error function approximation (Abramowitz & Stegun 7.1.26).
     */
    private function erf(float $x): float
    {
        $sign = $x >= 0 ? 1 : -1;
        $x = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $sum = 1.0 - $t * (
            0.254829592 +
            $t * (-0.284496736 +
            $t * (1.421413741 +
            $t * (-1.453152027 +
            $t * 1.061405429)))
        );

        return $sign * $sum;
    }

    /**
     * Chi-square CDF using the regularized lower incomplete gamma function.
     *
     * P(X <= x) for X ~ chi^2(k).
     */
    private function chiSquareCdf(float $x, int $k): float
    {
        if ($x <= 0) {
            return 0.0;
        }
        return $this->regularizedLowerGamma($k / 2.0, $x / 2.0);
    }

    /**
     * Regularized lower incomplete gamma function P(a, x).
     *
     * Uses series expansion for x < a+1, continued fraction (Lentz) otherwise.
     */
    private function regularizedLowerGamma(float $a, float $x): float
    {
        if ($x < 0 || $a <= 0) {
            return 0.0;
        }

        if ($x < $a + 1) {
            // Series representation
            $ap = $a;
            $sum = 1.0 / $a;
            $del = $sum;

            for ($n = 1; $n <= 200; $n++) {
                $ap += 1;
                $del *= $x / $ap;
                $sum += $del;

                if (abs($del) < abs($sum) * 1e-10) {
                    break;
                }
            }

            return $sum * exp(-$x + $a * log($x) - $this->logGamma($a));
        } else {
            // Continued fraction (Lentz's method)
            $b = $x + 1.0 - $a;
            $c = 1.0 / 1e-30;
            $d = 1.0 / $b;
            $h = $d;

            for ($n = 1; $n <= 200; $n++) {
                $an = -$n * ($n - $a);
                $b += 2.0;
                $d = $an * $d + $b;
                if (abs($d) < 1e-30) $d = 1e-30;
                $c = $b + $an / $c;
                if (abs($c) < 1e-30) $c = 1e-30;
                $d = 1.0 / $d;
                $del = $d * $c;
                $h *= $del;

                if (abs($del - 1.0) < 1e-10) {
                    break;
                }
            }

            return 1.0 - $h * exp(-$x + $a * log($x) - $this->logGamma($a));
        }
    }

    /**
     * Log-Gamma function using Lanczos approximation.
     */
    private function logGamma(float $x): float
    {
        static $coefficients = [
            76.18009172947146,
            -86.50532032941677,
            24.01409824083091,
            -1.231739572450155,
            0.1208650973866179e-2,
            -0.5395239384953e-5,
        ];

        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - $this->logGamma(1.0 - $x);
        }

        $x -= 1.0;
        $tmp = $x + 5.5;
        $tmp -= ($x + 0.5) * log($tmp);
        $ser = 1.000000000190015;

        for ($j = 0; $j < 6; $j++) {
            $x += 1.0;
            $ser += $coefficients[$j] / $x;
        }

        return -$tmp + log(2.5066282746310005 * $ser);
    }

    // ─── Data Access ────────────────────────────────────────────

    private function loadRunningExperiments(): array
    {
        $stmt = $this->db->query("SELECT * FROM experiments WHERE status = 'running'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadExperimentById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM experiments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadVariants(string $experimentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM experiment_variants WHERE experiment_id = ? ORDER BY is_control DESC, created_at ASC"
        );
        $stmt->execute([$experimentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadSegmentKeys(string $experimentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT segment_key FROM experiment_events
             WHERE experiment_id = ? AND segment_key IS NOT NULL"
        );
        $stmt->execute([$experimentId]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($keys)) {
            return ['global'];
        }

        if (!in_array('global', $keys, true)) {
            $keys[] = 'global';
        }

        return $keys;
    }

    /**
     * Load segment stats for a specific experiment/variant/segment.
     * Lazily refreshes if no row exists yet.
     */
    private function loadSegmentStatsRow(string $experimentId, string $variantId, string $segmentKey): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM experiment_segment_stats
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?"
        );
        $stmt->execute([$experimentId, $variantId, $segmentKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $this->refreshSegmentStats($experimentId, $variantId, $segmentKey);
            $stmt->execute([$experimentId, $variantId, $segmentKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        return $row ?: [];
    }

    private function formatExperiment(array $experiment): array
    {
        return [
            'id' => $experiment['id'],
            'key_slug' => $experiment['key_slug'],
            'name' => $experiment['name'],
            'surface' => $experiment['surface'],
            'status' => $experiment['status'],
            'allocation_mode' => $experiment['allocation_mode'],
            'primary_goal_event' => $experiment['primary_goal_event'],
            'started_at' => $experiment['started_at'],
            'ended_at' => $experiment['ended_at'],
        ];
    }

    private function logDecision(
        string $experimentId,
        string $decisionType,
        ?string $winningVariantId,
        string $actor,
        array $rationale = []
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO experiment_decisions
                (experiment_id, decision_type, winning_variant_id, actor, rationale, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $experimentId,
            $decisionType,
            $winningVariantId,
            $actor,
            json_encode($rationale),
        ]);
    }
}
