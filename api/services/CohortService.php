<?php
/**
 * CohortService — Manages cohort learning experience, enrollments, modules, and progress.
 * Phase 3.1: Real Cohort Learning Experience
 */

class CohortService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── Cohort CRUD ────────────────────────────────────

    public function getAllCohorts(array $filters = []): array
    {
        $sql = "SELECT c.*, 
                (SELECT COUNT(*) FROM cohort_enrollments ce WHERE ce.cohort_id = c.id AND ce.status = 'active') as enrolled_count,
                (SELECT AVG(cp.progress_percent) FROM cohort_progress cp WHERE cp.cohort_id = c.id) as avg_progress
                FROM cohorts c WHERE c.status = 'published'";

        $params = [];

        if (!empty($filters['category'])) {
            $sql .= " AND c.category = ?";
            $params[] = $filters['category'];
        }
        if (!empty($filters['difficulty'])) {
            $sql .= " AND c.difficulty = ?";
            $params[] = $filters['difficulty'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (c.name LIKE ? OR c.description LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY c.sort_order ASC, c.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }
        if (!empty($filters['offset'])) {
            $sql .= " OFFSET " . (int) $filters['offset'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCohort(string $cohortId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cohorts WHERE id = ?");
        $stmt->execute([$cohortId]);
        $cohort = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cohort) {
            $cohort['modules'] = $this->getModules($cohortId);
            $cohort['enrolled_count'] = $this->getEnrollmentCount($cohortId);
        }

        return $cohort ?: null;
    }

    public function createCohort(array $data): string
    {
        $id = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO cohorts (id, name, description, category, difficulty, duration_weeks, 
             start_date, end_date, max_participants, status, image_url, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? '',
            $data['category'] ?? 'general',
            $data['difficulty'] ?? 'beginner',
            $data['duration_weeks'] ?? 4,
            $data['start_date'] ?? date('Y-m-d'),
            $data['end_date'] ?? date('Y-m-d', strtotime('+4 weeks')),
            $data['max_participants'] ?? 50,
            $data['status'] ?? 'draft',
            $data['image_url'] ?? null,
            $data['created_by'] ?? null,
        ]);
        return $id;
    }

    // ─── Enrollment ─────────────────────────────────────

    public function enroll(string $userId, string $cohortId, ?string $planName = null): array
    {
        // Check if already enrolled
        $stmt = $this->db->prepare(
            "SELECT id, status FROM cohort_enrollments WHERE user_id = ? AND cohort_id = ?"
        );
        $stmt->execute([$userId, $cohortId]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'active') {
                return ['success' => false, 'message' => 'Already enrolled'];
            }
            // Reactivate
            $stmt = $this->db->prepare(
                "UPDATE cohort_enrollments SET status = 'active', enrolled_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$existing['id']]);
            return ['success' => true, 'enrollment_id' => $existing['id'], 'message' => 'Re-enrolled'];
        }

        $enrollmentId = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO cohort_enrollments (id, user_id, cohort_id, status, plan, enrolled_at)
             VALUES (?, ?, ?, 'active', ?, NOW())"
        );
        $stmt->execute([$enrollmentId, $userId, $cohortId, $planName ?? 'free']);

        // Initialize progress
        $stmt = $this->db->prepare(
            "INSERT INTO cohort_progress (id, user_id, cohort_id, progress_percent, last_activity_at)
             VALUES (?, ?, ?, 0, NOW())"
        );
        $stmt->execute([$this->uuid(), $userId, $cohortId]);

        return ['success' => true, 'enrollment_id' => $enrollmentId, 'message' => 'Enrolled successfully'];
    }

    public function unenroll(string $userId, string $cohortId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE cohort_enrollments SET status = 'dropped', dropped_at = NOW() 
             WHERE user_id = ? AND cohort_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId, $cohortId]);
        return $stmt->rowCount() > 0;
    }

    public function getUserEnrollments(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ce.*, c.name as cohort_name, c.description as cohort_description, 
                    c.category, c.difficulty, c.image_url, c.duration_weeks,
                    cp.progress_percent
             FROM cohort_enrollments ce
             JOIN cohorts c ON ce.cohort_id = c.id
             LEFT JOIN cohort_progress cp ON cp.user_id = ce.user_id AND cp.cohort_id = ce.cohort_id
             WHERE ce.user_id = ? AND ce.status = 'active'
             ORDER BY ce.enrolled_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Modules ────────────────────────────────────────

    public function getModules(string $cohortId): array
    {
        $stmt = $this->db->prepare(
            "SELECT cm.*, 
                    (SELECT COUNT(*) FROM cohort_module_items cmi WHERE cmi.module_id = cm.id) as item_count
             FROM cohort_modules cm 
             WHERE cm.cohort_id = ? 
             ORDER BY cm.sort_order ASC"
        );
        $stmt->execute([$cohortId]);
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($modules as &$module) {
            $module['items'] = $this->getModuleItems($module['id']);
        }

        return $modules;
    }

    public function getModuleItems(string $moduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM cohort_module_items 
             WHERE module_id = ? 
             ORDER BY sort_order ASC"
        );
        $stmt->execute([$moduleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addModule(string $cohortId, array $data): string
    {
        $id = $this->uuid();
        $sortOrder = $data['sort_order'] ?? $this->getNextModuleOrder($cohortId);

        $stmt = $this->db->prepare(
            "INSERT INTO cohort_modules (id, cohort_id, title, description, duration_days, sort_order, week_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $cohortId,
            $data['title'],
            $data['description'] ?? '',
            $data['duration_days'] ?? 7,
            $sortOrder,
            $data['week_number'] ?? 1,
        ]);
        return $id;
    }

    // ─── Progress Tracking ──────────────────────────────

    public function updateModuleProgress(string $userId, string $moduleId, float $percent): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO user_module_progress (id, user_id, module_id, progress_percent, completed, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE progress_percent = ?, completed = ?, updated_at = NOW()"
        );
        $completed = $percent >= 100;
        $pId = $this->uuid();
        $stmt->execute([$pId, $userId, $moduleId, $percent, $completed, $percent, $completed]);
    }

    public function recalculateCohortProgress(string $userId, string $cohortId): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(ump.progress_percent) 
             FROM cohort_modules cm
             LEFT JOIN user_module_progress ump ON ump.module_id = cm.id AND ump.user_id = ?
             WHERE cm.cohort_id = ?"
        );
        $stmt->execute([$userId, $cohortId]);
        $avg = (float) ($stmt->fetchColumn() ?: 0);

        $stmt = $this->db->prepare(
            "UPDATE cohort_progress SET progress_percent = ?, last_activity_at = NOW()
             WHERE user_id = ? AND cohort_id = ?"
        );
        $stmt->execute([$avg, $userId, $cohortId]);

        return $avg;
    }

    public function getUserProgress(string $userId, string $cohortId): array
    {
        $stmt = $this->db->prepare(
            "SELECT cp.*, c.name as cohort_name, c.duration_weeks
             FROM cohort_progress cp
             JOIN cohorts c ON cp.cohort_id = c.id
             WHERE cp.user_id = ? AND cp.cohort_id = ?"
        );
        $stmt->execute([$userId, $cohortId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($progress) {
            $progress['modules'] = $this->getUserModuleProgresses($userId, $cohortId);
        }

        return $progress ?: [];
    }

    private function getUserModuleProgresses(string $userId, string $cohortId): array
    {
        $stmt = $this->db->prepare(
            "SELECT cm.id as module_id, cm.title, cm.duration_days,
                    COALESCE(ump.progress_percent, 0) as progress_percent,
                    COALESCE(ump.completed, FALSE) as completed,
                    ump.updated_at
             FROM cohort_modules cm
             LEFT JOIN user_module_progress ump ON ump.module_id = cm.id AND ump.user_id = ?
             WHERE cm.cohort_id = ?
             ORDER BY cm.sort_order ASC"
        );
        $stmt->execute([$userId, $cohortId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Messages / Community ───────────────────────────

    public function getCohortMessages(string $cohortId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT cm.*, u.name as user_name, u.avatar_url
             FROM cohort_messages cm
             JOIN users u ON cm.user_id = u.id
             WHERE cm.cohort_id = ?
             ORDER BY cm.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$cohortId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function postMessage(string $userId, string $cohortId, string $content, string $type = 'text'): string
    {
        $id = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO cohort_messages (id, cohort_id, user_id, content, message_type, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$id, $cohortId, $userId, $content, $type]);
        return $id;
    }

    // ─── Completions ────────────────────────────────────

    public function markCohortComplete(string $userId, string $cohortId): array
    {
        // Verify all modules are complete
        $progress = $this->getUserProgress($userId, $cohortId);
        $allComplete = true;
        foreach ($progress['modules'] ?? [] as $mod) {
            if (!$mod['completed']) {
                $allComplete = false;
                break;
            }
        }

        if (!$allComplete) {
            return ['success' => false, 'message' => 'Not all modules are complete'];
        }

        // Record completion
        $stmt = $this->db->prepare(
            "INSERT INTO cohort_completions (id, user_id, cohort_id, completed_at, certificate_url)
             VALUES (?, ?, ?, NOW(), ?)"
        );
        $completionId = $this->uuid();
        $certUrl = '/api/certificates/' . $completionId . '.pdf';
        $stmt->execute([$completionId, $userId, $cohortId, $certUrl]);

        // Award points
        $this->awardCompletionPoints($userId);

        return [
            'success' => true,
            'completion_id' => $completionId,
            'certificate_url' => $certUrl,
            'message' => 'Cohort completed! 🎉',
        ];
    }

    // ─── Gamification Integration ───────────────────────

    private function awardCompletionPoints(string $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO points_history (id, user_id, points, reason, created_at)
             VALUES (?, ?, 100, 'cohort_completion', NOW())"
        );
        $stmt->execute([$this->uuid(), $userId]);

        $this->db->prepare(
            "UPDATE user_points SET total_points = total_points + 100, updated_at = NOW()
             WHERE user_id = ?"
        )->execute([$userId]);
    }

    // ─── Helpers ────────────────────────────────────────

    private function getEnrollmentCount(string $cohortId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cohort_enrollments WHERE cohort_id = ? AND status = 'active'"
        );
        $stmt->execute([$cohortId]);
        return (int) $stmt->fetchColumn();
    }

    private function getNextModuleOrder(string $cohortId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cohort_modules WHERE cohort_id = ?"
        );
        $stmt->execute([$cohortId]);
        return (int) $stmt->fetchColumn();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}