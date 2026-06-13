<?php
/**
 * BanditEngine — Thompson Sampling with Beta-Binomial posteriors.
 *
 * Pure PHP implementation. No external math libraries required.
 * Uses Marsaglia-Tsang method for Gamma sampling.
 */
class BanditEngine
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── Public API ───────────────────────────────────────────

    /**
     * Select a variant for the given experiment using Thompson Sampling.
     *
     * @param array  $experiment  Experiment row from DB.
     * @param array  $variants    Variant rows for this experiment.
     * @param string $segmentKey  Contextual segment for segment-level stats.
     * @return array  The selected variant row.
     */
    public function selectVariant(array $experiment, array $variants, string $segmentKey = 'global'): array
    {
        // Fixed-weight allocation mode
        if (($experiment['allocation_mode'] ?? 'bandit') === 'fixed') {
            return $this->selectFixedWeight($variants);
        }

        // Bandit mode: Thompson Sampling
        return $this->selectThompson($experiment, $variants, $segmentKey);
    }

    /**
     * Sample from a Beta(alpha, beta) distribution using Marsaglia-Tsang.
     *
     * Public so other services can draw samples independently.
     */
    public function sampleBeta(float $alpha, float $beta): float
    {
        $x = $this->sampleGamma($alpha);
        $y = $this->sampleGamma($beta);
        $total = $x + $y;

        return $total > 0 ? $x / $total : 0.0;
    }

    /**
     * Compute the probability that the variant with the given posterior
     * is the best among all variants. Uses Monte Carlo simulation.
     *
     * @param array $posteriors  [variantId => ['alpha' => float, 'beta' => float], ...]
     * @param int   $samples     Number of Monte Carlo draws.
     * @return array  [variantId => probBest, ...]
     */
    public function computeProbBest(array $posteriors, int $samples = 10000): array
    {
        $variantIds = array_keys($posteriors);
        $wins = array_fill_keys($variantIds, 0);

        for ($i = 0; $i < $samples; $i++) {
            $bestId = null;
            $bestVal = -INF;

            foreach ($posteriors as $vid => $p) {
                $draw = $this->sampleBeta($p['alpha'], $p['beta']);
                if ($draw > $bestVal) {
                    $bestVal = $draw;
                    $bestId = $vid;
                }
            }

            if ($bestId !== null) {
                $wins[$bestId]++;
            }
        }

        $probs = [];
        foreach ($wins as $vid => $count) {
            $probs[$vid] = round($count / $samples, 5);
        }

        return $probs;
    }

    // ─── Variant Selection ────────────────────────────────────

    private function selectThompson(array $experiment, array $variants, string $segmentKey): array
    {
        $expId = $experiment['id'];
        $explorationFloor = (float) ($experiment['exploration_floor'] ?? 0.05);

        $activeVariants = array_filter($variants, fn($v) => (int) ($v['is_active'] ?? 1) === 1);
        if (empty($activeVariants)) {
            $activeVariants = $variants;
        }
        $activeVariants = array_values($activeVariants);

        if (count($activeVariants) === 1) {
            return $activeVariants[0];
        }

        // Fetch segment-level posteriors
        $posteriors = $this->loadPosteriors($expId, $activeVariants, $segmentKey);

        // Draw from each posterior
        $scores = [];
        foreach ($posteriors as $vid => $p) {
            $scores[$vid] = $this->sampleBeta($p['alpha'], $p['beta']);
        }

        // Apply exploration floor: ensure minimum traffic for each variant
        $adjusted = $this->applyExplorationFloor($posteriors, $scores, $explorationFloor);

        // Pick the variant with the highest adjusted score
        $bestVid = array_keys($adjusted, max($adjusted))[0];

        foreach ($activeVariants as $v) {
            if ($v['id'] === $bestVid) {
                return $v;
            }
        }

        // Fallback
        return $activeVariants[array_rand($activeVariants)];
    }

    private function selectFixedWeight(array $variants): array
    {
        $active = array_filter($variants, fn($v) => (int) ($v['is_active'] ?? 1) === 1);
        if (empty($active)) {
            $active = $variants;
        }

        $active = array_values($active);

        if (count($active) === 1) {
            return $active[0];
        }

        // Compute total fixed weight
        $totalWeight = 0.0;
        foreach ($active as $v) {
            $totalWeight += (float) ($v['fixed_weight'] ?? 1.0);
        }

        if ($totalWeight <= 0) {
            return $active[array_rand($active)];
        }

        // Weighted random selection
        $rand = mt_rand() / mt_getrandmax() * $totalWeight;
        $cumulative = 0.0;

        foreach ($active as $v) {
            $cumulative += (float) ($v['fixed_weight'] ?? 1.0);
            if ($rand <= $cumulative) {
                return $v;
            }
        }

        return $active[count($active) - 1];
    }

    // ─── Posterior Loading ────────────────────────────────────

    /**
     * Load Beta posterior parameters for each variant from segment stats.
     * Falls back to uniform prior (alpha=1, beta=1) when no data exists.
     */
    private function loadPosteriors(string $expId, array $variants, string $segmentKey): array
    {
        $variantIds = array_map(fn($v) => $v['id'], $variants);

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $params = array_merge([$expId, $segmentKey], $variantIds);

        $stmt = $this->db->prepare(
            "SELECT variant_id, alpha, beta
             FROM experiment_segment_stats
             WHERE experiment_id = ? AND segment_key = ? AND variant_id IN ($placeholders)"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $posteriors = [];
        $foundIds = [];

        foreach ($rows as $row) {
            $vid = $row['variant_id'];
            $posteriors[$vid] = [
                'alpha' => (float) $row['alpha'],
                'beta'  => (float) $row['beta'],
            ];
            $foundIds[$vid] = true;
        }

        // Uniform prior for variants with no data yet
        foreach ($variantIds as $vid) {
            if (!isset($foundIds[$vid])) {
                $posteriors[$vid] = ['alpha' => 1.0, 'beta' => 1.0];
            }
        }

        return $posteriors;
    }

    // ─── Exploration Floor ────────────────────────────────────

    /**
     * Guarantee each variant at least `floor` share of the selection probability.
     * Uses a mixture: floor / n + (1 - floor) * posterior score.
     */
    private function applyExplorationFloor(array $posteriors, array $scores, float $floor): array
    {
        $n = count($posteriors);
        if ($n === 0 || $floor <= 0) {
            return $scores;
        }

        $uniformShare = $floor / $n;
        $exploitShare = 1.0 - $floor;

        $adjusted = [];
        foreach ($scores as $vid => $score) {
            $adjusted[$vid] = $uniformShare + $exploitShare * $score;
        }

        return $adjusted;
    }

    // ─── Gamma Sampler (Marsaglia-Tsang) ──────────────────────

    /**
     * Sample from Gamma(shape, scale=1) using Marsaglia-Tsang method.
     *
     * For shape < 1, uses the small-shape transformation:
     *   Gamma(shape) = Gamma(shape + 1) * U^(1/shape)
     */
    private function sampleGamma(float $shape): float
    {
        if ($shape <= 0) {
            return 0.0;
        }

        if ($shape < 1) {
            // Small shape: use transformation
            $u = mt_rand() / mt_getrandmax();
            if ($u <= 0) $u = 1e-10;
            return $this->sampleGamma($shape + 1.0) * exp(log($u) / $shape);
        }

        // Marsaglia-Tsang for shape >= 1
        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        for (;;) {
            // Box-Muller for standard normal
            $z = $this->randNormal();

            if ($z <= -1.0 / $c) {
                continue;
            }

            $v = (1.0 + $c * $z);
            $v = $v * $v * $v; // (1 + c*z)^3

            if ($v <= 0) {
                continue;
            }

            $u = mt_rand() / mt_getrandmax();
            if ($u <= 0) {
                continue;
            }

            $logU = log($u);
            $rhs = 0.5 * $z * $z + $d - $d * $v + $d * log($v);

            if ($logU < $rhs) {
                return $d * $v;
            }
        }
    }

    /**
     * Generate a standard normal random variable using Box-Muller.
     */
    private function randNormal(): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        if ($u1 <= 0) $u1 = 1e-10;
        if ($u2 <= 0) $u2 = 1e-10;

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
