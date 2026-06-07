<?php
// api/seed_results.php
include_once __DIR__ . '/config/Database.php';

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

// Create table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_results (
        id TEXT PRIMARY KEY,
        user_name TEXT,
        program_name TEXT,
        metric_label TEXT,
        metric_value TEXT,
        description TEXT,
        image_url TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die(json_encode(["error" => "Table creation failed: " . $e->getMessage()]));
}

$results = [
    [
        'user_name' => 'Alex M.',
        'program_name' => 'Metabolic Reset',
        'metric_label' => 'Weight Loss',
        'metric_value' => '14 lbs',
        'description' => 'I feel lighter and more energetic than I have in years.',
        'image_url' => 'https://images.unsplash.com/photo-1571019614242-c5c5dee9f50b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
    ],
    [
        'user_name' => 'Sarah K.',
        'program_name' => 'PCOS Protocol',
        'metric_label' => 'Cycle Regularity',
        'metric_value' => '100%',
        'description' => 'My cycle is finally predictable without medication.',
        'image_url' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
    ],
    [
        'user_name' => 'David L.',
        'program_name' => 'Executive Focus',
        'metric_label' => 'Deep Work',
        'metric_value' => '+3hrs/day',
        'description' => 'Brain fog is gone. Productivity has doubled.',
        'image_url' => 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
    ]
];

try {
    $stmt = $db->prepare("INSERT INTO user_results (id, user_name, program_name, metric_label, metric_value, description, image_url) VALUES (:id, :user_name, :program_name, :metric_label, :metric_value, :description, :image_url)");

    foreach ($results as $r) {
        $id = uniqid();
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_name', $r['user_name']);
        $stmt->bindValue(':program_name', $r['program_name']);
        $stmt->bindValue(':metric_label', $r['metric_label']);
        $stmt->bindValue(':metric_value', $r['metric_value']);
        $stmt->bindValue(':description', $r['description']);
        $stmt->bindValue(':image_url', $r['image_url']);
        $stmt->execute();
    }

    echo json_encode(["message" => "User results seeded successfully", "count" => count($results)]);

} catch (Exception $e) {
    echo json_encode(["message" => "Error seeding results", "error" => $e->getMessage()]);
}
?>