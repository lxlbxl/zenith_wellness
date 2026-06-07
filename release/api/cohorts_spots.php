<?php
// api/cohorts_spots.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db = new SQLite3('../database.sqlite');

    $cohortId = $_GET['id'] ?? null;

    if ($cohortId) {
        $stmt = $db->prepare("SELECT id, title, max_participants, current_participants FROM cohorts WHERE id = :id");
        $stmt->bindValue(':id', $cohortId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $cohort = $result->fetchArray(SQLITE3_ASSOC);
    } else {
        // Just get the next starting cohort or a featured one
        $result = $db->query("SELECT id, title, max_participants, current_participants FROM cohorts ORDER BY start_date ASC LIMIT 1");
        $cohort = $result->fetchArray(SQLITE3_ASSOC);
    }

    if ($cohort) {
        $remaining = $cohort['max_participants'] - $cohort['current_participants'];
        $percentFull = ($cohort['current_participants'] / $cohort['max_participants']) * 100;

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $cohort['id'],
                'total' => $cohort['max_participants'],
                'taken' => $cohort['current_participants'],
                'remaining' => max(0, $remaining),
                'percentFull' => round($percentFull)
            ]
        ]);
    } else {
        // Fallback mock data if no cohort found
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => 'mock-cohort',
                'total' => 50,
                'taken' => 38,
                'remaining' => 12,
                'percentFull' => 76
            ]
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>