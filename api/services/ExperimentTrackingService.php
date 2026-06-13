<?php
/**
 * ExperimentTrackingService — Record experiment events with deduplication.
 *
 * Links analytics events to active experiment assignments for conversion
 * measurement and goal/guardrail tracking.
 */
class ExperimentTrackingService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── Public API ───────────────────────────────────────────

    /**
     * Record an analytics event across all active experiment assignments.
     *
     * For each active assignment the visitor has, this method:
     * 1. Checks if the event matches a goal or guardrail event.
     * 2. Deduplicates goal conversions (only first conversion per experiment counted).
     * 3. Inserts an experiment_events row.
     * 4. Updates segment stats via refreshSegmentStats().
     *
     * @param string $visitorId  Unique visitor identifier.
     * @param string $eventType  Analytics event name (e.g., 'payment_success').
     * @param array  $properties Event properties (value_cents, etc.).
     * @param string|null $userId Optional authenticated user ID.
     * @return int  Number of experiment events recorded.
     */
    public function recordEvent(
        string $visitorId,
        string $eventType,
        array $properties = [],
        ?string $userId = null
    ): int {
        // 1. Find all active assignments for this visitor
        $assignments = $this->getActiveAssignments($visitorId);
        if (empty($assignments)) {
            return 0;
        }

        $recorded = 0;

        foreach ($assignments as $assignment) {
            $isGoal = $this->isGoalEvent($assignment, $eventType);
            $isGuardrail = $this->isGuardrailEvent($assignment, $eventType);

            // Deduplicate goal conversions: only count first per experiment
            if ($isGoal && $this->hasExistingGoalConversion($assignment['experiment_id'], $visitorId)) {
                // Still record the event but not as a goal
                $isGoal = false;
            }

            $valueCents = (int) ($properties['value_cents'] ?? 0);

            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO experiment_events
                        (experiment_id, variant_id, visitor_id, user_id, event_type, is_goal, is_guardrail, value_cents, segment_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $assignment['experiment_id'],
                    $assignment['variant_id'],
                    $visitorId,
                    $userId,
                    $eventType,
                    $isGoal ? 1 : 0,
                    $isGuardrail ? 1 : 0,
                    $valueCents,
                    $assignment['segment_key'],
                ]);
            } catch (PDOException $e) {
                // Fallback without user_id (column may not exist yet)
                $stmt = $this->db->prepare(
                    "INSERT INTO experiment_events
                        (experiment_id, variant_id, visitor_id, event_type, is_goal, is_guardrail, value_cents, segment_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $assignment['experiment_id'],
                    $assignment['variant_id'],
                    $visitorId,
                    $eventType,
                    $isGoal ? 1 : 0,
                    $isGuardrail ? 1 : 0,
                    $valueCents,
                    $assignment['segment_key'],
                ]);
            }

            $recorded++;

            // Refresh segment stats inline
            $this->refreshSegmentStats(
                $assignment['experiment_id'],
                $assignment['variant_id'],
                $assignment['segment_key'] ?? 'global'
            );
        }

        return $recorded;
    }

    /**
     * Refresh the cached segment stats (alpha, beta, exposures, conversions)
     * for a given experiment/variant/segment combination.
     *
     * Called after each experiment event insertion.
     */
    public function refreshSegmentStats(
        string $experimentId,
        string $variantId,
        string $segmentKey = 'global'
    ): void {
        // Count exposures
        $stmtExp = $this->db->prepare(
            "SELECT COUNT(*) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND event_type = 'exposure'"
        );
        $stmtExp->execute([$experimentId, $variantId, $segmentKey]);
        $exposures = (int) $stmtExp->fetchColumn();

        // Count goal conversions
        $stmtConv = $this->db->prepare(
            "SELECT COUNT(*) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND is_goal = 1"
        );
        $stmtConv->execute([$experimentId, $variantId, $segmentKey]);
        $conversions = (int) $stmtConv->fetchColumn();

        // Revenue
        $stmtRev = $this->db->prepare(
            "SELECT COALESCE(SUM(value_cents), 0) FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ? AND is_goal = 1"
        );
        $stmtRev->execute([$experimentId, $variantId, $segmentKey]);
        $revenueCents = (int) $stmtRev->fetchColumn();

        // Beta posterior: alpha = conversions + 1, beta = exposures - conversions + 1
        $alpha = $conversions + 1;
        $beta = ($exposures - $conversions) + 1;

        // Funnel counts
        $stmtFunnel = $this->db->prepare(
            "SELECT event_type, COUNT(*) as cnt FROM experiment_events
             WHERE experiment_id = ? AND variant_id = ? AND segment_key = ?
             GROUP BY event_type"
        );
        $stmtFunnel->execute([$experimentId, $variantId, $segmentKey]);
        $funnelData = $stmtFunnel->fetchAll(PDO::FETCH_KEY_PAIR);

        // Upsert: check for existing row (cross-DB compatible)
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

    // ─── Internal Helpers ────────────────────────────────────

    /**
     * Find all active assignments for a visitor.
     * Joins with experiments to ensure only currently-running experiments.
     */
    private function getActiveAssignments(string $visitorId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ea.experiment_id, ea.variant_id, ea.visitor_id, ea.segment_key,
                    e.key_slug AS experiment_key_slug, e.primary_goal_event, e.guardrail_events
             FROM experiment_assignments ea
             JOIN experiments e ON e.id = ea.experiment_id
             WHERE ea.visitor_id = ? AND e.status = 'running'"
        );
        $stmt->execute([$visitorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if this event type matches the experiment's primary goal.
     */
    private function isGoalEvent(array $assignment, string $eventType): bool
    {
        return strtolower($eventType) === strtolower($assignment['primary_goal_event'] ?? '');
    }

    /**
     * Check if this event type matches any of the experiment's guardrail events.
     */
    private function isGuardrailEvent(array $assignment, string $eventType): bool
    {
        $guardrails = $assignment['guardrail_events'] ?? null;
        if ($guardrails === null) {
            return false;
        }

        if (is_string($guardrails)) {
            $guardrails = json_decode($guardrails, true);
        }

        if (!is_array($guardrails)) {
            return false;
        }

        $eventType = strtolower($eventType);
        foreach ($guardrails as $ge) {
            if (strtolower($ge) === $eventType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether this visitor already has a goal conversion in this experiment.
     * Used to deduplicate — only the first conversion counts toward stats.
     */
    private function hasExistingGoalConversion(string $experimentId, string $visitorId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM experiment_events
             WHERE experiment_id = ? AND visitor_id = ? AND is_goal = 1"
        );
        $stmt->execute([$experimentId, $visitorId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
