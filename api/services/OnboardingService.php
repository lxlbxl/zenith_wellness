<?php
/**
 * OnboardingService — Progressive onboarding wizard, checklists, and user activation.
 * Phase 3.2: Progressive Onboarding
 */

class OnboardingService
{
    private $db;

    // Onboarding stages
    public const STAGES = [
        'welcome' => [
            'step' => 1,
            'label' => 'Welcome',
            'description' => 'Set up your profile',
            'required' => true,
        ],
        'goals' => [
            'step' => 2,
            'label' => 'Set Your Goals',
            'description' => 'Tell us what you want to achieve',
            'required' => true,
        ],
        'habits' => [
            'step' => 3,
            'label' => 'First Habit',
            'description' => 'Create your first daily habit',
            'required' => true,
        ],
        'mood' => [
            'step' => 4,
            'label' => 'Check In',
            'description' => 'Log your first mood',
            'required' => true,
        ],
        'cohort' => [
            'step' => 5,
            'label' => 'Find Your Tribe',
            'description' => 'Join a cohort for accountability',
            'required' => false,
        ],
        'profile' => [
            'step' => 6,
            'label' => 'Complete Profile',
            'description' => 'Add details for a personalized experience',
            'required' => false,
        ],
    ];

    // Goal templates
    public const GOAL_TEMPLATES = [
        'weight_loss' => 'Lose Weight',
        'muscle_gain' => 'Build Muscle',
        'better_sleep' => 'Improve Sleep',
        'stress_management' => 'Manage Stress',
        'mindfulness' => 'Practice Mindfulness',
        'nutrition' => 'Eat Healthier',
        'fitness' => 'Get Fitter',
        'hydration' => 'Drink More Water',
        'mental_health' => 'Better Mental Health',
        'productivity' => 'Be More Productive',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Initialize onboarding for a new user.
     */
    public function initializeOnboarding(string $userId): string
    {
        $id = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO user_onboarding (id, user_id, current_stage, stage_progress, started_at)
             VALUES (?, ?, 'welcome', '{}', NOW())"
        );
        $stmt->execute([$id, $userId]);

        // Create initial checklist items
        $this->initializeChecklist($userId);

        return $id;
    }

    /**
     * Get current onboarding state for a user.
     */
    public function getOnboardingState(string $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_onboarding WHERE user_id = ?");
        $stmt->execute([$userId]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$state) {
            return ['completed' => false, 'current_stage' => 'welcome', 'stages' => self::STAGES, 'progress' => 0];
        }

        $completedStages = json_decode($state['stage_progress'], true) ?: [];
        $totalRequired = count(array_filter(self::STAGES, fn($s) => $s['required']));
        $completedRequired = count(array_intersect(array_keys($completedStages), array_keys(array_filter(self::STAGES, fn($s) => $s['required']))));

        return [
            'completed' => $state['completed'],
            'current_stage' => $state['current_stage'],
            'stages' => self::STAGES,
            'completed_stages' => $completedStages,
            'progress' => $totalRequired > 0 ? round($completedRequired / $totalRequired * 100) : 0,
            'started_at' => $state['started_at'],
            'completed_at' => $state['completed_at'],
        ];
    }

    /**
     * Advance to the next onboarding stage.
     */
    public function advanceStage(string $userId, string $stageData = '{}'): array
    {
        $state = $this->getOnboardingState($userId);
        $currentKey = $state['current_stage'];
        $completedStages = $state['completed_stages'];

        // Mark current stage as completed
        $completedStages[$currentKey] = [
            'completed_at' => date('Y-m-d H:i:s'),
            'data' => json_decode($stageData, true),
        ];

        // Find next stage
        $stageKeys = array_keys(self::STAGES);
        $currentIndex = array_search($currentKey, $stageKeys);

        if ($currentIndex === false || $currentIndex >= count($stageKeys) - 1) {
            // All stages complete
            $this->db->prepare(
                "UPDATE user_onboarding SET completed = TRUE, completed_at = NOW(), stage_progress = ?
                 WHERE user_id = ?"
            )->execute([json_encode($completedStages), $userId]);

            // Award onboarding completion points
            $this->awardPoints($userId, 75, 'onboarding_complete');

            return [
                'completed' => true,
                'message' => 'Onboarding complete! 🎉',
                'stages' => $completedStages,
                'points_earned' => 75,
            ];
        }

        $nextStage = $stageKeys[$currentIndex + 1];

        $this->db->prepare(
            "UPDATE user_onboarding SET current_stage = ?, stage_progress = ?, updated_at = NOW()
             WHERE user_id = ?"
        )->execute([$nextStage, json_encode($completedStages), $userId]);

        return [
            'completed' => false,
            'next_stage' => $nextStage,
            'next_label' => self::STAGES[$nextStage]['label'],
            'stages' => $completedStages,
        ];
    }

    /**
     * Skip an optional stage.
     */
    public function skipStage(string $userId, string $stage): array
    {
        $stageInfo = self::STAGES[$stage] ?? null;

        if (!$stageInfo) {
            throw new \InvalidArgumentException("Unknown stage: $stage");
        }

        if ($stageInfo['required']) {
            throw new \RuntimeException("Cannot skip required stage: $stage");
        }

        return $this->advanceStage($userId, json_encode(['skipped' => true]));
    }

    // ─── Goal Management ────────────────────────────────

    /**
     * Set user's wellness goals.
     */
    public function setGoals(string $userId, array $goals): void
    {
        // Delete existing goals
        $this->db->prepare("DELETE FROM user_goals WHERE user_id = ?")->execute([$userId]);

        foreach ($goals as $goal) {
            $id = $this->uuid();
            $stmt = $this->db->prepare(
                "INSERT INTO user_goals (id, user_id, goal_type, target_value, current_value, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'active', NOW())"
            );
            $stmt->execute([
                $id,
                $userId,
                $goal['type'],
                $goal['target'] ?? null,
                $goal['current'] ?? 0,
            ]);
        }

        // Advance past goals stage
        $this->advanceStage($userId, json_encode(['goals_set' => count($goals)]));
    }

    /**
     * Get user's goals.
     */
    public function getUserGoals(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_goals WHERE user_id = ? AND status = 'active' ORDER BY created_at ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update progress toward a goal.
     */
    public function updateGoalProgress(string $goalId, float $currentValue): array
    {
        $stmt = $this->db->prepare(
            "UPDATE user_goals SET current_value = ?, 
                status = CASE WHEN ? >= target_value THEN 'achieved' ELSE 'active' END,
                updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$currentValue, $currentValue, $goalId]);

        $stmt = $this->db->prepare("SELECT * FROM user_goals WHERE id = ?");
        $stmt->execute([$goalId]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($goal && $goal['status'] === 'achieved') {
            $this->awardPoints($goal['user_id'], 50, 'goal_achieved');
        }

        return $goal ?: [];
    }

    // ─── Checklist ──────────────────────────────────────

    private function initializeChecklist(string $userId): void
    {
        $items = [
            ['key' => 'complete_profile', 'label' => 'Complete your profile', 'category' => 'profile'],
            ['key' => 'set_goals', 'label' => 'Set your wellness goals', 'category' => 'goals'],
            ['key' => 'first_habit', 'label' => 'Create your first habit', 'category' => 'habits'],
            ['key' => 'first_mood', 'label' => 'Log your first mood', 'category' => 'mood'],
            ['key' => 'join_cohort', 'label' => 'Join a cohort', 'category' => 'cohort'],
            ['key' => 'first_meal', 'label' => 'Log your first meal', 'category' => 'nutrition'],
            ['key' => 'invite_friend', 'label' => 'Invite a friend', 'category' => 'social'],
            ['key' => 'try_ai', 'label' => 'Chat with the AI coach', 'category' => 'ai'],
        ];

        $order = 1;
        foreach ($items as $item) {
            $id = $this->uuid();
            $stmt = $this->db->prepare(
                "INSERT INTO onboarding_checklists (id, user_id, item_key, label, category, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $userId, $item['key'], $item['label'], $item['category'], $order]);
            $order++;
        }
    }

    /**
     * Get user's onboarding checklist.
     */
    public function getChecklist(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM onboarding_checklists WHERE user_id = ? ORDER BY sort_order ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a checklist item as complete.
     */
    public function completeChecklistItem(string $userId, string $itemKey): void
    {
        $this->db->prepare(
            "UPDATE onboarding_checklists SET completed = TRUE, completed_at = NOW()
             WHERE user_id = ? AND item_key = ?"
        )->execute([$userId, $itemKey]);

        // Check if all items are complete for bonus
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as total, SUM(CASE WHEN completed THEN 1 ELSE 0 END) as completed
             FROM onboarding_checklists WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row['total'] > 0 && $row['completed'] === $row['total']) {
            $this->awardPoints($userId, 50, 'checklist_complete');
        }
    }

    // ─── Helpers ────────────────────────────────────────

    private function awardPoints(string $userId, int $points, string $reason): void
    {
        $this->db->prepare(
            "INSERT IGNORE INTO user_points (id, user_id, total_points) VALUES (?, ?, 0)"
        )->execute([$this->uuid(), $userId]);

        $this->db->prepare(
            "INSERT INTO points_history (id, user_id, points, reason, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$this->uuid(), $userId, $points, $reason]);

        $this->db->prepare(
            "UPDATE user_points SET total_points = total_points + ?, updated_at = NOW()
             WHERE user_id = ?"
        )->execute([$points, $userId]);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}