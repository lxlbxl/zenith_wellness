<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
class JournalController
{
    private $db;
    private $currentUser;

    public function __construct($db)
    {
        $this->db = $db;
        $this->currentUser = $this->verifyAuth();
    }

    private function verifyAuth()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        $payload = AuthMiddleware::verifyToken($token);
        if ($payload) {
            return $payload;
        }

        return null;
    }

    public function handleRequest($action, $userId = null)
    {
        if (!$this->currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        switch ($action) {
            case 'entries':
                // GET /api/journal/entries/{userId}
                if ($method === 'GET' && $userId) {
                    $this->getEntries($userId);
                }
                // POST /api/journal/entries/{userId}
                else if ($method === 'POST' && $userId) {
                    $this->createEntry($userId);
                }
                break;

            case 'entry':
                // GET /api/journal/entry/{entryId}
                // PUT /api/journal/entry/{entryId}
                // DELETE /api/journal/entry/{entryId}
                if ($method === 'GET') {
                    $this->getEntry($userId);
                } else if ($method === 'PUT') {
                    $this->updateEntry($userId);
                } else if ($method === 'DELETE') {
                    $this->deleteEntry($userId);
                }
                break;

            case 'prompts':
                // GET /api/journal/prompts
                if ($method === 'GET') {
                    $this->getPrompts();
                }
                break;

            case 'weekly':
                // GET /api/journal/weekly/{userId}
                // POST /api/journal/weekly/{userId}
                if ($method === 'GET') {
                    $this->getWeeklyReflection($userId);
                } else if ($method === 'POST') {
                    $this->saveWeeklyReflection($userId);
                }
                break;

            case 'stats':
                // GET /api/journal/stats/{userId}
                if ($method === 'GET') {
                    $this->getStats($userId);
                }
                break;

            case 'search':
                // GET /api/journal/search/{userId}?q=keyword&mood=happy
                if ($method === 'GET') {
                    $this->searchEntries($userId);
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(["message" => "Endpoint not found"]);
        }
    }

    /**
     * Get all journal entries
     * GET /api/journal/entries/{userId}?from=2026-01-01&to=2026-01-31&mood=happy
     */
    private function getEntries($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $mood = $_GET['mood'] ?? null;

        $query = "SELECT * FROM journal_entries WHERE user_id = :uid";
        $params = [':uid' => $userId];

        if ($from) {
            $query .= " AND entry_date >= :from";
            $params[':from'] = $from;
        }
        if ($to) {
            $query .= " AND entry_date <= :to";
            $params[':to'] = $to;
        }
        if ($mood) {
            $query .= " AND mood = :mood";
            $params[':mood'] = $mood;
        }

        $query .= " ORDER BY entry_date DESC, created_at DESC LIMIT 100";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse tags JSON
        foreach ($entries as &$entry) {
            $entry['tags'] = $entry['tags'] ? json_decode($entry['tags'], true) : [];
        }

        echo json_encode($entries);
    }

    /**
     * Get specific entry
     * GET /api/journal/entry/{entryId}
     */
    private function getEntry($entryId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM journal_entries 
            WHERE id = :eid AND user_id = :uid
        ");
        $stmt->execute([':eid' => $entryId, ':uid' => $this->currentUser['id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            http_response_code(404);
            echo json_encode(["message" => "Entry not found"]);
            return;
        }

        $entry['tags'] = $entry['tags'] ? json_decode($entry['tags'], true) : [];
        echo json_encode($entry);
    }

    /**
     * Create new entry
     * POST /api/journal/entries/{userId}
     */
    private function createEntry($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['content'])) {
            http_response_code(400);
            echo json_encode(["message" => "Content is required"]);
            return;
        }

        $entryId = 'journal_' . uniqid();
        $wordCount = str_word_count(strip_tags($data['content']));
        $tags = isset($data['tags']) ? json_encode($data['tags']) : null;

        $stmt = $this->db->prepare("
            INSERT INTO journal_entries 
            (id, user_id, title, content, mood, energy_level, tags, 
             is_favorite, is_private, word_count, entry_date)
            VALUES (:id, :uid, :title, :content, :mood, :energy, :tags,
                    :fav, :private, :wc, :date)
        ");

        $stmt->execute([
            ':id' => $entryId,
            ':uid' => $userId,
            ':title' => $data['title'] ?? null,
            ':content' => $data['content'],
            ':mood' => $data['mood'] ?? null,
            ':energy' => $data['energy_level'] ?? null,
            ':tags' => $tags,
            ':fav' => $data['is_favorite'] ?? 0,
            ':private' => $data['is_private'] ?? 1,
            ':wc' => $wordCount,
            ':date' => $data['entry_date'] ?? date('Y-m-d')
        ]);

        echo json_encode([
            "message" => "Entry created",
            "entry_id" => $entryId,
            "word_count" => $wordCount
        ]);
    }

    /**
     * Update entry
     * PUT /api/journal/entry/{entryId}
     */
    private function updateEntry($entryId)
    {
        // Verify ownership
        $checkStmt = $this->db->prepare("SELECT user_id FROM journal_entries WHERE id = :eid");
        $checkStmt->execute([':eid' => $entryId]);
        $entry = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry || $entry['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        $updates = [];
        $params = [':id' => $entryId];

        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['content'])) {
            $updates[] = "content = :content";
            $params[':content'] = $data['content'];
            $updates[] = "word_count = :wc";
            $params[':wc'] = str_word_count(strip_tags($data['content']));
        }
        if (isset($data['mood'])) {
            $updates[] = "mood = :mood";
            $params[':mood'] = $data['mood'];
        }
        if (isset($data['energy_level'])) {
            $updates[] = "energy_level = :energy";
            $params[':energy'] = $data['energy_level'];
        }
        if (isset($data['tags'])) {
            $updates[] = "tags = :tags";
            $params[':tags'] = json_encode($data['tags']);
        }
        if (isset($data['is_favorite'])) {
            $updates[] = "is_favorite = :fav";
            $params[':fav'] = $data['is_favorite'];
        }

        $updates[] = "updated_at = CURRENT_TIMESTAMP";

        if (!empty($updates)) {
            $query = "UPDATE journal_entries SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
        }

        echo json_encode(["message" => "Entry updated"]);
    }

    /**
     * Delete entry
     * DELETE /api/journal/entry/{entryId}
     */
    private function deleteEntry($entryId)
    {
        $checkStmt = $this->db->prepare("SELECT user_id FROM journal_entries WHERE id = :eid");
        $checkStmt->execute([':eid' => $entryId]);
        $entry = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry || $entry['user_id'] !== $this->currentUser['id']) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM journal_entries WHERE id = :eid");
        $stmt->execute([':eid' => $entryId]);

        echo json_encode(["message" => "Entry deleted"]);
    }

    /**
     * Get reflection prompts
     * GET /api/journal/prompts?category=gratitude
     */
    private function getPrompts()
    {
        $category = $_GET['category'] ?? null;

        $query = "SELECT * FROM reflection_prompts WHERE is_system = 1";
        $params = [];

        if ($category) {
            $query .= " AND category = :cat";
            $params[':cat'] = $category;
        }

        $query .= " ORDER BY RANDOM() LIMIT 3";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($prompts);
    }

    /**
     * Get weekly reflection
     * GET /api/journal/weekly/{userId}?week=2026-01-20
     */
    private function getWeeklyReflection($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));

        $stmt = $this->db->prepare("
            SELECT * FROM weekly_reflections 
            WHERE user_id = :uid AND week_start_date = :week
        ");
        $stmt->execute([':uid' => $userId, ':week' => $weekStart]);
        $reflection = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($reflection ?: null);
    }

    /**
     * Save weekly reflection
     * POST /api/journal/weekly/{userId}
     */
    private function saveWeeklyReflection($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $weekStart = $data['week_start_date'] ?? date('Y-m-d', strtotime('monday this week'));

        // Check if exists
        $checkStmt = $this->db->prepare("
            SELECT id FROM weekly_reflections 
            WHERE user_id = :uid AND week_start_date = :week
        ");
        $checkStmt->execute([':uid' => $userId, ':week' => $weekStart]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            $stmt = $this->db->prepare("
                UPDATE weekly_reflections 
                SET highlights = :high, challenges = :chal, 
                    lessons_learned = :lessons, next_week_intentions = :intentions,
                    mood_summary = :mood
                WHERE user_id = :uid AND week_start_date = :week
            ");
        } else {
            // Insert
            $stmt = $this->db->prepare("
                INSERT INTO weekly_reflections 
                (id, user_id, week_start_date, highlights, challenges, 
                 lessons_learned, next_week_intentions, mood_summary)
                VALUES (:id, :uid, :week, :high, :chal, :lessons, :intentions, :mood)
            ");
            $data['id'] = 'weekly_' . uniqid();
        }

        $params = [
            ':uid' => $userId,
            ':week' => $weekStart,
            ':high' => $data['highlights'] ?? null,
            ':chal' => $data['challenges'] ?? null,
            ':lessons' => $data['lessons_learned'] ?? null,
            ':intentions' => $data['next_week_intentions'] ?? null,
            ':mood' => $data['mood_summary'] ?? null
        ];

        if (!$existing) {
            $params[':id'] = $data['id'];
        }

        $stmt->execute($params);

        echo json_encode(["message" => "Weekly reflection saved"]);
    }

    /**
     * Get journaling statistics
     * GET /api/journal/stats/{userId}
     */
    private function getStats($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        // Total entries
        $totalStmt = $this->db->prepare("SELECT COUNT(*) as total FROM journal_entries WHERE user_id = :uid");
        $totalStmt->execute([':uid' => $userId]);
        $stats = $totalStmt->fetch(PDO::FETCH_ASSOC);

        // Total words
        $wordsStmt = $this->db->prepare("SELECT SUM(word_count) as total_words FROM journal_entries WHERE user_id = :uid");
        $wordsStmt->execute([':uid' => $userId]);
        $wordsData = $wordsStmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_words'] = $wordsData['total_words'] ?? 0;

        // Current streak
        $stats['current_streak'] = $this->calculateStreak($userId);

        // Mood distribution
        $moodStmt = $this->db->prepare("
            SELECT mood, COUNT(*) as count 
            FROM journal_entries 
            WHERE user_id = :uid AND mood IS NOT NULL
            GROUP BY mood
        ");
        $moodStmt->execute([':uid' => $userId]);
        $stats['mood_distribution'] = $moodStmt->fetchAll(PDO::FETCH_ASSOC);

        // Favorite count
        $favStmt = $this->db->prepare("SELECT COUNT(*) as favorites FROM journal_entries WHERE user_id = :uid AND is_favorite = 1");
        $favStmt->execute([':uid' => $userId]);
        $favData = $favStmt->fetch(PDO::FETCH_ASSOC);
        $stats['favorites'] = $favData['favorites'];

        echo json_encode($stats);
    }

    /**
     * Search entries
     * GET /api/journal/search/{userId}?q=keyword&mood=happy
     */
    private function searchEntries($userId)
    {
        if ($this->currentUser['id'] !== $userId) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden"]);
            return;
        }

        $query = $_GET['q'] ?? '';
        $mood = $_GET['mood'] ?? null;

        $sql = "SELECT * FROM journal_entries WHERE user_id = :uid";
        $params = [':uid' => $userId];

        if ($query) {
            $sql .= " AND (title LIKE :q OR content LIKE :q)";
            $params[':q'] = "%{$query}%";
        }

        if ($mood) {
            $sql .= " AND mood = :mood";
            $params[':mood'] = $mood;
        }

        $sql .= " ORDER BY entry_date DESC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse tags
        foreach ($results as &$entry) {
            $entry['tags'] = $entry['tags'] ? json_decode($entry['tags'], true) : [];
        }

        echo json_encode($results);
    }

    /**
     * Calculate journaling streak
     */
    private function calculateStreak($userId): int
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT entry_date 
            FROM journal_entries 
            WHERE user_id = :uid 
            ORDER BY entry_date DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($dates))
            return 0;

        $streak = 0;
        $currentDate = new DateTime();

        // Check if journaled today or yesterday
        $lastEntry = new DateTime($dates[0]);
        $daysDiff = $currentDate->diff($lastEntry)->days;

        if ($daysDiff > 1)
            return 0; // Streak broken

        if ($daysDiff == 0)
            $streak = 1; // Today
        else if ($daysDiff == 1)
            $streak = 1; // Yesterday

        // Count backwards
        for ($i = 1; $i < count($dates); $i++) {
            $prev = new DateTime($dates[$i - 1]);
            $curr = new DateTime($dates[$i]);
            $diff = $prev->diff($curr)->days;

            if ($diff == 1) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}
?>