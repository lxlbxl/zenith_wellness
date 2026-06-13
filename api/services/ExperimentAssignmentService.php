<?php
/**
 * ExperimentAssignmentService — Assign visitors to experiment variants.
 *
 * Flow: bot filter → targeting check → holdout check → sticky check →
 *       BanditEngine selection → exposure event recording.
 */
class ExperimentAssignmentService
{
    private PDO $db;
    private BanditEngine $bandit;

    /** Known bot User-Agent substrings. */
    private const BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests',
        'go-http-client', 'java/', 'libwww', 'httpclient', 'phantomjs', 'headless',
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'facebookexternalhit', 'facebot', 'twitterbot',
        'whatsapp', 'slackbot', 'discordbot', 'telegrambot',
    ];

    public function __construct(PDO $db, ?BanditEngine $bandit = null)
    {
        $this->db = $db;
        $this->bandit = $bandit ?? new BanditEngine($db);
    }

    // ─── Main Assignment ──────────────────────────────────────

    /**
     * Assign a visitor to an experiment variant.
     *
     * @param string $experimentKey  The experiment's key_slug.
     * @param string $visitorId      Unique visitor identifier (zen_vid cookie).
     * @param array  $context        Context data: source, device, country, user_agent, etc.
     * @param string|null $userId    Optional authenticated user ID.
     * @return array|null  Assignment result or null if excluded.
     */
    public function assign(
        string $experimentKey,
        string $visitorId,
        array $context = [],
        ?string $userId = null
    ): ?array {
        // 1. Bot filter
        if ($this->isBot($context['user_agent'] ?? '')) {
            return null;
        }

        // 2. Load experiment
        $experiment = $this->loadExperiment($experimentKey);
        if (!$experiment) {
            return null;
        }

        // 3. Status check — only active experiments
        if ($experiment['status'] !== 'running') {
            return null;
        }

        // 4. Targeting check
        if (!$this->checkTargeting($experiment, $context)) {
            return null;
        }

        // 5. Holdout check
        if ((int) ($experiment['holdout'] ?? 0) === 1) {
            return null;
        }

        // 6. Sticky assignment check
        $sticky = $this->getStickyAssignment($experiment['id'], $visitorId);
        if ($sticky !== null) {
            return $sticky;
        }

        // 7. Load variants
        $variants = $this->loadVariants($experiment['id']);
        if (empty($variants)) {
            return null;
        }

        // 8. Select variant via BanditEngine
        $segmentKey = $this->buildSegmentKey($context);
        $selected = $this->bandit->selectVariant($experiment, $variants, $segmentKey);

        // 9. Persist assignment
        $this->insertAssignment($experiment['id'], $selected['id'], $visitorId, $userId, $segmentKey, $context);

        // 10. Record exposure event
        $this->recordExposure($experiment, $selected, $visitorId, $segmentKey);

        return [
            'experiment' => [
                'id' => $experiment['id'],
                'key_slug' => $experiment['key_slug'],
                'surface' => $experiment['surface'],
            ],
            'variant' => [
                'id' => $selected['id'],
                'key_slug' => $selected['key_slug'],
                'name' => $selected['name'],
                'is_control' => (int) ($selected['is_control'] ?? 0),
                'config' => $selected['config'] ?? null,
            ],
            'segment_key' => $segmentKey,
        ];
    }

    // ─── Segment Key ──────────────────────────────────────────

    /**
     * Build a segment key from context for segment-level stats.
     *
     * Format: "src:{source}|dev:{device}"
     * Unknown values use "unknown".
     */
    public function buildSegmentKey(array $context): string
    {
        $source = $context['source'] ?? 'unknown';
        $device = $context['device'] ?? 'unknown';

        return sprintf('src:%s|dev:%s', $source, $device);
    }

    // ─── Bot Detection ────────────────────────────────────────

    /**
     * Check whether the given User-Agent string represents a bot/crawler.
     */
    public function isBot(string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false; // Missing UA is not necessarily a bot
        }

        $ua = strtolower($userAgent);

        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // ─── Targeting ────────────────────────────────────────────

    /**
     * Check whether the visitor context matches the experiment targeting rules.
     */
    private function checkTargeting(array $experiment, array $context): bool
    {
        $targeting = $experiment['targeting'] ?? null;
        if ($targeting === null) {
            return true; // No targeting = everyone is eligible
        }

        // Decode JSON if stored as string
        if (is_string($targeting)) {
            $targeting = json_decode($targeting, true);
        }

        if (!is_array($targeting) || empty($targeting)) {
            return true;
        }

        // Source check
        if (!empty($targeting['sources'])) {
            $source = $context['source'] ?? '';
            if (!in_array($source, $targeting['sources'], true)) {
                return false;
            }
        }

        // Device check
        if (!empty($targeting['devices'])) {
            $device = $context['device'] ?? '';
            if (!in_array($device, $targeting['devices'], true)) {
                return false;
            }
        }

        // Country check
        if (!empty($targeting['countries'])) {
            $country = $context['country'] ?? '';
            if (!in_array($country, $targeting['countries'], true)) {
                return false;
            }
        }

        // Quiz profile check
        if (!empty($targeting['quiz_profiles'])) {
            $profile = $context['quiz_profile'] ?? '';
            if (!in_array($profile, $targeting['quiz_profiles'], true)) {
                return false;
            }
        }

        return true;
    }

    // ─── Data Access ──────────────────────────────────────────

    private function loadExperiment(string $keySlug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM experiments WHERE key_slug = ? LIMIT 1"
        );
        $stmt->execute([$keySlug]);
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

    /**
     * Check for an existing sticky assignment for this visitor.
     */
    private function getStickyAssignment(string $experimentId, string $visitorId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ea.*, ev.key_slug AS variant_key_slug, ev.name AS variant_name,
                    ev.is_control AS variant_is_control, ev.config AS variant_config,
                    e.key_slug AS experiment_key_slug, e.surface AS experiment_surface
             FROM experiment_assignments ea
             JOIN experiment_variants ev ON ev.id = ea.variant_id
             JOIN experiments e ON e.id = ea.experiment_id
             WHERE ea.experiment_id = ? AND ea.visitor_id = ?
             LIMIT 1"
        );
        $stmt->execute([$experimentId, $visitorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'experiment' => [
                'id' => $row['experiment_id'],
                'key_slug' => $row['experiment_key_slug'],
                'surface' => $row['experiment_surface'],
            ],
            'variant' => [
                'id' => $row['variant_id'],
                'key_slug' => $row['variant_key_slug'],
                'name' => $row['variant_name'],
                'is_control' => (int) ($row['variant_is_control'] ?? 0),
                'config' => $row['variant_config'] ?? null,
            ],
            'segment_key' => $row['segment_key'],
            'assigned_at' => $row['assigned_at'],
        ];
    }

    private function insertAssignment(
        string $experimentId,
        string $variantId,
        string $visitorId,
        ?string $userId,
        string $segmentKey,
        array $context
    ): void {
        $contextJson = json_encode($context);

        // Check for existing assignment (cross-DB compatible)
        $check = $this->db->prepare(
            "SELECT id FROM experiment_assignments WHERE experiment_id = ? AND visitor_id = ?"
        );
        $check->execute([$experimentId, $visitorId]);

        if ($check->fetch()) {
            $stmt = $this->db->prepare(
                "UPDATE experiment_assignments
                 SET variant_id = ?, user_id = ?, segment_key = ?, context = ?
                 WHERE experiment_id = ? AND visitor_id = ?"
            );
            $stmt->execute([$variantId, $userId, $segmentKey, $contextJson, $experimentId, $visitorId]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO experiment_assignments (experiment_id, variant_id, visitor_id, user_id, segment_key, context)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$experimentId, $variantId, $visitorId, $userId, $segmentKey, $contextJson]);
        }
    }

    private function recordExposure(
        array $experiment,
        array $variant,
        string $visitorId,
        string $segmentKey
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO experiment_events (experiment_id, variant_id, visitor_id, event_type, segment_key)
             VALUES (?, ?, ?, 'exposure', ?)"
        );
        $stmt->execute([
            $experiment['id'],
            $variant['id'],
            $visitorId,
            $segmentKey,
        ]);
    }
}
