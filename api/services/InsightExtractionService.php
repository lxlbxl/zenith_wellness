<?php
// api/services/InsightExtractionService.php
// ===========================================
// B.5 — Insight Extraction from Completed Experiments
// When an experiment ships or archives, this service:
// 1. Pulls the experiment report
// 2. Attributes wins/losses to pattern_tags
// 3. Upserts variant_insights rows
// 4. Optionally generates AI summary text
// ===========================================

class InsightExtractionService
{
    private PDO $db;
    private ExperimentStatsService $statsService;
    private array $providerCache = [];

    public function __construct(PDO $db, ?ExperimentStatsService $statsService = null)
    {
        $this->db = $db;
        $this->statsService = $statsService ?? new ExperimentStatsService($db);
    }

    // ===========================================
    // PUBLIC API
    // ===========================================

    /**
     * Extract insights from a completed experiment.
     *
     * @param string $experimentId
     * @param array  $options  {
     *   ai_summary: bool  — whether to generate AI summary text (default: false)
     * }
     * @return array
     */
    public function extractFromExperiment(string $experimentId, array $options = []): array
    {
        $report = $this->statsService->getReport($experimentId);
        if (!$report) {
            return ['success' => false, 'error' => 'Experiment not found or has no data.'];
        }

        $variants = $report['variants'] ?? [];
        $comparisons = $report['comparisons'] ?? [];
        $experiment = $report['experiment'];

        if (empty($variants)) {
            return ['success' => false, 'error' => 'No variant data in experiment report.'];
        }

        // Identify control (is_control=1)
        $control = null;
        foreach ($variants as $v) {
            if ((int) ($v['is_control'] ?? 0) === 1) {
                $control = $v;
                break;
            }
        }

        // Find winner: highest prob_best among non-control variants
        $treatments = array_filter($variants, fn($v) => (int) ($v['is_control'] ?? 0) === 0);
        usort($treatments, fn($a, $b) => ($b['prob_best'] ?? 0) <=> ($a['prob_best'] ?? 0));
        $winner = !empty($treatments) ? $treatments[0] : null;

        // Loser for comparison: control if not winner, else lowest prob_best
        $loser = null;
        if ($control && $control['variant_id'] !== ($winner['variant_id'] ?? null)) {
            $loser = $control;
        } elseif (count($treatments) > 1) {
            $loser = $treatments[count($treatments) - 1];
        }

        if (!$winner) {
            return ['success' => false, 'error' => 'No winner variant identified.'];
        }

        if (!$loser) {
            return ['success' => false, 'error' => 'No comparison variant available.'];
        }

        // Find uplift from comparisons
        $uplift = null;
        foreach ($comparisons as $cmp) {
            if (($cmp['variant_id'] ?? '') === ($winner['variant_id'] ?? '')) {
                $uplift = $cmp['relative_uplift'] ?? null;
                break;
            }
        }

        // Load pattern_tags for winner and loser from promoted candidates
        $winnerTags = $this->loadPatternTags($experimentId, $winner['variant_id']);
        $loserTags = $this->loadPatternTags($experimentId, $loser['variant_id']);
        $surface = $experiment['surface'];

        // Total sample size
        $sampleSize = array_sum(array_column($variants, 'exposures'));

        // Compute per-tag attribution
        $tagAttributions = $this->attributeTags(
            $winnerTags, $loserTags, $winner, $loser, $control, $uplift
        );

        $insights = [];
        $aiSummaries = [];

        foreach ($tagAttributions as $tag => $attr) {
            $insight = $this->upsertInsight(
                $surface,
                $report['segment_key'] ?? null,
                $tag,
                $attr['direction'],
                $attr['effect_size'],
                $attr['confidence'],
                $sampleSize,
                $experimentId,
                $attr['summary'],
                $attr['weight']
            );
            $insights[] = $insight;

            // Generate AI summaries if requested
            if (!empty($options['ai_summary'])) {
                try {
                    $aiSummary = $this->generateAISummary($surface, $tag, $attr, $experiment);
                    if ($aiSummary) {
                        $this->updateInsightSummary($insight['id'], $aiSummary);
                        $aiSummaries[] = ['insight_id' => $insight['id'], 'summary' => $aiSummary];
                    }
                } catch (Exception $e) {
                    $aiSummaries[] = ['insight_id' => $insight['id'], 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'success' => true,
            'experiment_id' => $experimentId,
            'surface' => $surface,
            'winner_variant' => $winner['key_slug'],
            'uplift' => $uplift,
            'insights_created' => count($insights),
            'insights' => $insights,
            'ai_summaries' => !empty($options['ai_summary']) ? $aiSummaries : [],
        ];
    }

    /**
     * Extract insights for all shipped/archived experiments that haven't
     * had their insights extracted yet.
     *
     * @param array $options  Options passed through to extractFromExperiment
     * @return array
     */
    public function extractAllPending(array $options = []): array
    {
        $pending = $this->loadPendingExperiments();
        $results = [];

        foreach ($pending as $exp) {
            try {
                $result = $this->extractFromExperiment($exp['id'], $options);
                if ($result['success']) {
                    $this->markExtracted($exp['id']);
                }
                $results[] = [
                    'experiment_id' => $exp['id'],
                    'key_slug' => $exp['key_slug'],
                    'success' => $result['success'],
                    'insights_count' => $result['insights_created'] ?? 0,
                    'error' => $result['error'] ?? null,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'experiment_id' => $exp['id'],
                    'key_slug' => $exp['key_slug'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'total_processed' => count($results),
            'results' => $results,
        ];
    }

    // ===========================================
    // TAG ATTRIBUTION
    // ===========================================

    /**
     * Attribute win/loss to pattern tags by comparing winner vs loser.
     *
     * @param array      $winnerTags
     * @param array      $loserTags
     * @param array      $winner
     * @param array      $loser
     * @param array|null $control
     * @param float|null $uplift
     * @return array  [tag => {direction, effect_size, confidence, summary, weight}]
     */
    private function attributeTags(
        array  $winnerTags,
        array  $loserTags,
        array  $winner,
        array  $loser,
        ?array $control,
        ?float $uplift
    ): array {
        $attributions = [];
        $winnerTagSet = array_unique($winnerTags);
        $loserTagSet = array_unique($loserTags);

        // Base confidence from winner's prob_best
        $confidence = min(0.99, (float) ($winner['prob_best'] ?? 0.5));

        // Tags present only in winner → "lifts"
        $winnerOnly = array_diff($winnerTagSet, $loserTagSet);
        foreach ($winnerOnly as $tag) {
            $attributions[$tag] = [
                'direction' => 'lifts',
                'effect_size' => $uplift,
                'confidence' => $confidence,
                'summary' => "Pattern '{$tag}' associated with higher conversion rate",
                'weight' => $this->calculateWeight($uplift, $confidence),
                'source' => 'winner_only',
            ];
        }

        // Tags present only in loser → "hurts"
        $loserOnly = array_diff($loserTagSet, $winnerTagSet);
        foreach ($loserOnly as $tag) {
            $attributions[$tag] = [
                'direction' => 'hurts',
                'effect_size' => $uplift ? -$uplift : null,
                'confidence' => $confidence,
                'summary' => "Pattern '{$tag}' associated with lower conversion rate",
                'weight' => $this->calculateWeight($uplift ? -$uplift : 0, $confidence),
                'source' => 'loser_only',
            ];
        }

        // Tags present in both → "neutral" by default, or direction based on other factors
        $shared = array_intersect($winnerTagSet, $loserTagSet);
        foreach ($shared as $tag) {
            // Shared tags with a positive overall experiment → slight lifts
            // but lower confidence since we can't attribute specifically
            $attributions[$tag] = [
                'direction' => $uplift && $uplift > 0.01 ? 'lifts' : ($uplift && $uplift < -0.01 ? 'hurts' : 'neutral'),
                'effect_size' => $uplift && abs($uplift) > 0.01 ? $uplift * 0.5 : 0.0, // discount shared tags
                'confidence' => $confidence * 0.5, // lower confidence for shared tags
                'summary' => "Pattern '{$tag}' present in both winner and control — net effect uncertain",
                'weight' => $this->calculateWeight($uplift ?? 0, $confidence * 0.5),
                'source' => 'shared',
            ];
        }

        return $attributions;
    }

    /**
     * Calculate insight weight based on effect size and confidence.
     * Weight = 0.5 + (|effect_size| * 2) * (confidence / 0.95)
     * Capped at 1.0, floor at 0.1.
     */
    private function calculateWeight(?float $effectSize, float $confidence): float
    {
        $effectMagnitude = abs($effectSize ?? 0);
        $weight = 0.5 + ($effectMagnitude * 2) * min(1, $confidence / 0.95);
        return max(0.1, min(1.0, $weight));
    }

    // ===========================================
    // INSIGHT UPSERT
    // ===========================================

    /**
     * Upsert a variant_insight row — weighted blend if insight already exists.
     */
    private function upsertInsight(
        string  $surface,
        ?string $segmentKey,
        string  $patternTag,
        string  $direction,
        ?float  $effectSize,
        float   $confidence,
        int     $sampleSize,
        string  $experimentId,
        string  $summary,
        float   $weight
    ): array {
        // Check for existing insight with same surface + pattern_tag (+ optional segment)
        $existing = $this->findExistingInsight($surface, $patternTag, $segmentKey);

        if ($existing) {
            // Weighted blend: new_weight = old_weight * 0.6 + new_weight * 0.4
            // Effect is weighted average. Do NOT overwrite — blend.
            $oldWeight = (float) ($existing['weight'] ?? 1.0) * 0.6;
            $newWeight = $weight * 0.4;
            $blendedWeight = min(1.0, $oldWeight + $newWeight);

            $blendedEffect = null;
            if ($existing['effect_size'] !== null && $effectSize !== null) {
                $blendedEffect = round(
                    (float) $existing['effect_size'] * 0.6 + $effectSize * 0.4,
                    4
                );
            } elseif ($effectSize !== null) {
                $blendedEffect = $effectSize;
            } elseif ($existing['effect_size'] !== null) {
                $blendedEffect = (float) $existing['effect_size'];
            }

            $blendedConfidence = round(
                (float) ($existing['confidence'] ?? 0.5) * 0.6 + $confidence * 0.4,
                4
            );

            // If directions differ, the new experiment provides evidence —
            // keep the direction with higher blended weight
            if ($existing['direction'] !== $direction) {
                // Use direction from whichever has higher weight
                $direction = $blendedWeight >= (float) ($existing['weight'] ?? 1.0)
                    ? $direction : $existing['direction'];
            }

            // Update existing insight
            $id = $existing['id'];
            $stmt = $this->db->prepare("
                UPDATE variant_insights SET
                    direction = :direction,
                    effect_size = :effectSize,
                    confidence = :confidence,
                    sample_size = sample_size + :sampleSize,
                    weight = :weight,
                    summary = :summary,
                    is_active = 1
                WHERE id = :id
            ");
            $stmt->execute([
                ':direction' => $direction,
                ':effectSize' => $blendedEffect,
                ':confidence' => $blendedConfidence,
                ':sampleSize' => $sampleSize,
                ':weight' => $blendedWeight,
                ':summary' => $summary,
                ':id' => $id,
            ]);

            return [
                'id' => $id,
                'surface' => $surface,
                'pattern_tag' => $patternTag,
                'direction' => $direction,
                'effect_size' => $blendedEffect,
                'confidence' => $blendedConfidence,
                'sample_size' => (int) ($existing['sample_size'] ?? 0) + $sampleSize,
                'weight' => $blendedWeight,
                'is_update' => true,
            ];
        }

        // Create new insight
        $id = $this->uuid();
        $stmt = $this->db->prepare("
            INSERT INTO variant_insights
                (id, surface, segment_key, pattern_tag, direction, effect_size,
                 confidence, sample_size, source_experiment_id, summary, weight, is_active)
            VALUES
                (:id, :surface, :segmentKey, :patternTag, :direction, :effectSize,
                 :confidence, :sampleSize, :sourceExperimentId, :summary, :weight, 1)
        ");
        $stmt->execute([
            ':id'                  => $id,
            ':surface'             => $surface,
            ':segmentKey'          => $segmentKey,
            ':patternTag'          => $patternTag,
            ':direction'           => $direction,
            ':effectSize'          => $effectSize,
            ':confidence'          => $confidence,
            ':sampleSize'          => $sampleSize,
            ':sourceExperimentId'  => $experimentId,
            ':summary'            => $summary,
            ':weight'             => $weight,
        ]);

        return [
            'id' => $id,
            'surface' => $surface,
            'pattern_tag' => $patternTag,
            'direction' => $direction,
            'effect_size' => $effectSize,
            'confidence' => $confidence,
            'sample_size' => $sampleSize,
            'weight' => $weight,
            'is_update' => false,
        ];
    }

    private function findExistingInsight(string $surface, string $patternTag, ?string $segmentKey): ?array
    {
        $sql = "
            SELECT id, direction, effect_size, confidence, sample_size, weight
            FROM variant_insights
            WHERE surface = :surface AND pattern_tag = :patternTag
        ";
        $params = [':surface' => $surface, ':patternTag' => $patternTag];

        if ($segmentKey !== null) {
            $sql .= " AND (segment_key = :seg OR segment_key IS NULL)";
            $params[':seg'] = $segmentKey;
        }

        $sql .= " ORDER BY weight DESC, created_at DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ===========================================
    // AI SUMMARY GENERATION
    // ===========================================

    /**
     * Generate a readable AI summary for an insight.
     */
    private function generateAISummary(string $surface, string $tag, array $attr, array $experiment): ?string
    {
        $direction = $attr['direction'];
        $effectSize = $attr['effect_size'] ?? 0;
        $confidence = $attr['confidence'] ?? 0;
        $directionText = $direction === 'lifts' ? 'improves' : ($direction === 'hurts' ? 'hurts' : 'has neutral effect on');

        $prompt = <<<PROMPT
Write one concise sentence (max 200 chars) explaining the insight for a marketing team:

On the "{$surface}" surface, the "{$tag}" pattern {$directionText} conversion.

Effect size: {$effectSize}
Confidence: {$confidence}

Generate a readable, actionable insight sentence. Do not use markdown. Just the sentence.
PROMPT;

        try {
            $response = $this->callAI($prompt);
            $clean = trim($response);
            $clean = preg_replace('/^["\']|["\']$/', '', $clean);
            $clean = preg_replace('/```.*?```/s', '', $clean);
            return mb_substr(trim($clean), 0, 500);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update an insight's summary text.
     */
    private function updateInsightSummary(string $insightId, string $summary): void
    {
        $stmt = $this->db->prepare("UPDATE variant_insights SET summary = :summary WHERE id = :id");
        $stmt->execute([':summary' => $summary, ':id' => $insightId]);
    }

    // ===========================================
    // NIGHTLY SWEEP
    // ===========================================

    /**
     * Nightly maintenance sweep:
     * 1. Decay weight on insights > 90 days old (halve weight)
     * 2. Deactivate contradicted insights (same tag + surface, opposite directions)
     *
     * @return array  Counts of affected rows.
     */
    public function nightlySweep(): array
    {
        $decayedCount = 0;
        $deactivatedCount = 0;

        // 1. Decay weight on old insights
        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
        $stmt = $this->db->prepare("
            UPDATE variant_insights
            SET weight = CASE WHEN weight * 0.5 < 0.05 THEN 0.05 ELSE weight * 0.5 END,
                is_active = CASE WHEN weight * 0.5 < 0.1 THEN 0 ELSE is_active END
            WHERE created_at < :cutoff
              AND is_active = 1
              AND weight > 0.05
        ");
        $stmt->execute([':cutoff' => $cutoff]);
        $decayedCount = $stmt->rowCount();

        // 2. Find contradicted insights (same surface + pattern_tag, opposite active directions)
        $stmt = $this->db->prepare("
            SELECT v1.id AS id1, v1.pattern_tag, v1.surface, v1.direction, v1.weight AS weight1,
                   v2.id AS id2, v2.direction, v2.weight AS weight2
            FROM variant_insights v1
            JOIN variant_insights v2
              ON v1.surface = v2.surface
             AND v1.pattern_tag = v2.pattern_tag
             AND v1.id < v2.id
             AND v1.is_active = 1
             AND v2.is_active = 1
            WHERE (
                (v1.direction = 'lifts' AND v2.direction = 'hurts')
                OR (v1.direction = 'hurts' AND v2.direction = 'lifts')
            )
        ");
        $stmt->execute();
        $contradictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contradictions as $c) {
            // Deactivate the one with lower weight
            $toDeactivate = (float) ($c['weight1'] ?? 0) < (float) ($c['weight2'] ?? 0)
                ? $c['id1'] : $c['id2'];

            $upd = $this->db->prepare("UPDATE variant_insights SET is_active = 0 WHERE id = :id");
            $upd->execute([':id' => $toDeactivate]);
            $deactivatedCount += $upd->rowCount();
        }

        // Also deactivate insights with very low weight that somehow stayed active
        $stmt = $this->db->prepare("
            UPDATE variant_insights
            SET is_active = 0
            WHERE weight < 0.05 AND is_active = 1
        ");
        $stmt->execute();
        $deactivatedCount += $stmt->rowCount();

        return [
            'success' => true,
            'decayed' => $decayedCount,
            'deactivated' => $deactivatedCount,
        ];
    }

    // ===========================================
    // HELPERS
    // ===========================================

    /**
     * Load pattern_tags for a variant by tracing back to the promoted variant_candidate.
     * Falls back to inferring from variant key_slug and name.
     */
    private function loadPatternTags(string $experimentId, string $variantId): array
    {
        // Trace via promoted_variant_id in variant_candidates
        $stmt = $this->db->prepare("
            SELECT vc.pattern_tags
            FROM variant_candidates vc
            WHERE vc.promoted_variant_id = :variantId
              AND vc.experiment_id = :experimentId
            LIMIT 1
        ");
        $stmt->execute([
            ':variantId' => $variantId,
            ':experimentId' => $experimentId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['pattern_tags'])) {
            $tags = json_decode($row['pattern_tags'], true);
            if (is_array($tags)) {
                return $tags;
            }
        }

        // Fallback: try extracting from experiment_variants config
        $stmt = $this->db->prepare("
            SELECT config FROM experiment_variants WHERE id = :id AND experiment_id = :expId LIMIT 1
        ");
        $stmt->execute([':id' => $variantId, ':expId' => $experimentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['config']) {
            $config = json_decode($row['config'], true);
            if (is_array($config)) {
                $tags = $config['pattern_tags'] ?? $config['_pattern_tags'] ?? null;
                if (is_array($tags)) {
                    return $tags;
                }
            }
        }

        return [];
    }

    /**
     * Load experiments that have shipped/archived but haven't been extracted yet.
     */
    private function loadPendingExperiments(): array
    {
        // Track extraction via experiment_decisions or by checking variant_insights source_experiment_id.
        // We check if ANY variant_insight references this experiment already.
        $stmt = $this->db->prepare("
            SELECT e.id, e.key_slug, e.name, e.surface, e.status
            FROM experiments e
            WHERE e.status IN ('shipped', 'archived')
              AND NOT EXISTS (
                  SELECT 1 FROM variant_insights vi
                  WHERE vi.source_experiment_id = e.id
              )
            ORDER BY e.updated_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark an experiment as extracted by inserting a decision log entry.
     */
    private function markExtracted(string $experimentId): void
    {
        $rationale = json_encode(['action' => 'insights_extracted']);

        // Check if a decision already exists; upsert.
        $check = $this->db->prepare("SELECT 1 FROM experiment_decisions WHERE experiment_id = :id LIMIT 1");
        $check->execute([':id' => $experimentId]);

        if ($check->fetch()) {
            $stmt = $this->db->prepare("UPDATE experiment_decisions SET actor = 'engine', rationale = :rationale WHERE experiment_id = :id");
        } else {
            $stmt = $this->db->prepare("INSERT INTO experiment_decisions (experiment_id, decision_type, actor, rationale) VALUES (:id, 'shipped', 'engine', :rationale)");
        }
        $stmt->execute([':id' => $experimentId, ':rationale' => $rationale]);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ===========================================
    // AI PROVIDER (cheap model for summaries)
    // ===========================================

    private function callAI(string $prompt): string
    {
        $provider = $this->getSetting('ai_provider') ?: 'openrouter';
        $model = $this->getSetting('cheap_ai_model') ?: 'gemini-1.5-flash';

        switch ($provider) {
            case 'openrouter':
                $apiKey = $this->getSetting('openrouter_api_key') ?: (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
                if ($apiKey) {
                    return $this->callOpenRouter($prompt, $apiKey, $model);
                }
            default:
                $apiKey = $this->getSetting('gemini_api_key') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
                if (!$apiKey) {
                    throw new RuntimeException('No AI API key configured for insight summary.');
                }
                return $this->callGemini($prompt, $apiKey, $model);
        }
    }

    private function callOpenRouter(string $prompt, string $apiKey, string $model): string
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $data = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.5,
            'max_tokens' => 300,
        ];
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://zenithwellness.app',
            'X-Title: Zenith Wellness',
        ];
        return $this->makeRequest($url, $data, $headers);
    }

    private function callGemini(string $prompt, string $apiKey, string $model): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $data = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 300],
        ];
        return $this->makeRequest($url, $data);
    }

    private function makeRequest(string $url, array $data, array $customHeaders = []): string
    {
        $headers = "Content-Type: application/json\r\n";
        foreach ($customHeaders as $header) {
            $headers .= $header . "\r\n";
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        return $result;
    }

    private function getSetting(string $key): ?string
    {
        if (isset($this->providerCache[$key])) {
            return $this->providerCache[$key];
        }

        $stmt = $this->db->prepare("SELECT setting_value, is_encrypted FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $constName = strtoupper($key);
            $value = defined($constName) ? constant($constName) : null;
            $this->providerCache[$key] = $value;
            return $value;
        }

        $value = $row['is_encrypted'] ? $this->decrypt($row['setting_value']) : $row['setting_value'];
        $this->providerCache[$key] = $value;
        return $value;
    }

    private function decrypt(string $value): string
    {
        if (empty($value)) {
            return $value;
        }
        try {
            $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'zenith_default_key_change_me_32ch';
            $parts = explode('::', base64_decode($value), 2);
            if (count($parts) !== 2) {
                return $value;
            }
            [$iv, $encrypted] = $parts;
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
            return $decrypted !== false ? $decrypted : $value;
        } catch (Exception $e) {
            return $value;
        }
    }
}
