<?php
// api/recent_enrollments.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/Database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Query real recent enrollments
    // Join with users for names and cohorts for titles
    $stmt = $db->query("
        SELECT u.name, c.title as program, e.enrolled_at
        FROM cohort_enrollments e
        JOIN users u ON e.user_id = u.id
        JOIN cohorts c ON e.cohort_id = c.id
        ORDER BY e.enrolled_at DESC
        LIMIT 10
    ");

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cities = ['New York', 'London', 'Toronto', 'Sydney', 'Berlin', 'Los Angeles', 'Chicago', 'Austin', 'Denver', 'Lagos', 'Nairobi', 'Dubai'];

    $data = [];
    foreach ($results as $row) {
        // Anonymize name (just first name)
        $nameParts = explode(' ', trim($row['name']));
        $firstName = $nameParts[0];

        // Calculate relative time
        $time = strtotime($row['enrolled_at']);
        $diff = time() - $time;

        if ($diff < 60) {
            $timeLabel = "just now";
        } elseif ($diff < 3600) {
            $timeLabel = floor($diff / 60) . " minutes ago";
        } elseif ($diff < 86400) {
            $timeLabel = floor($diff / 3600) . " hours ago";
        } else {
            $timeLabel = floor($diff / 86400) . " days ago";
        }

        $data[] = [
            'name' => $firstName,
            'location' => $cities[array_rand($cities)], // Randomize location as it's not in DB
            'program' => $row['program'],
            'time' => $timeLabel
        ];
    }

    // Fallback to some mocks if no real data yet to keep UI alive
    if (count($data) < 3) {
        $mockNames = ['Sarah', 'Jessica', 'Emily', 'Emma', 'Olivia'];
        $mockPrograms = ['Metabolic Reset', 'BioSync Protocol', 'Cognitive Clear'];
        for ($i = count($data); $i < 5; $i++) {
            $data[] = [
                'name' => $mockNames[array_rand($mockNames)],
                'location' => $cities[array_rand($cities)],
                'program' => $mockPrograms[array_rand($mockPrograms)],
                'time' => rand(2, 59) . " minutes ago"
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>