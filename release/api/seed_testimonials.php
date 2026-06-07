<?php
// api/seed_testimonials.php
include_once __DIR__ . '/config/Database.php';

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

$testimonials = [
    [
        'quote' => "I was skeptical about the AI coaching, but it actually adapted to my cycle perfectly. Down 12 lbs in 8 weeks.",
        'name' => "Sarah J.",
        'rating' => 5,
        'result_metric' => "Lost 12lbs",
        'is_featured' => 1
    ],
    [
        'quote' => "The PCOS protocols are a game changer. My energy levels are stable for the first time in years.",
        'name' => "Emily R.",
        'rating' => 5,
        'result_metric' => "Reduced Fatigue",
        'is_featured' => 1
    ],
    [
        'quote' => "Creating habits used to be impossible. The gamification makes it addictive in a good way.",
        'name' => "Marcus T.",
        'rating' => 5,
        'result_metric' => "30 Day Streak",
        'is_featured' => 1
    ],
    [
        'quote' => "Finally a program that understands women's physiology. The metabolic reset worked wonders.",
        'name' => "Jessica K.",
        'rating' => 5,
        'result_metric' => "Regulated Cycle",
        'is_featured' => 0
    ],
    [
        'quote' => "Love the community aspect. Doing this with a cohort kept me accountable.",
        'name' => "Anita P.",
        'rating' => 4,
        'result_metric' => "Consistent Workouts",
        'is_featured' => 1
    ]
];

try {
    $stmt = $db->prepare("INSERT INTO testimonials (id, quote, name, rating, result_metric, is_featured, is_approved) VALUES (:id, :quote, :name, :rating, :result_metric, :is_featured, 1)");

    foreach ($testimonials as $t) {
        $id = uniqid();
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':quote', $t['quote']);
        $stmt->bindValue(':name', $t['name']);
        $stmt->bindValue(':rating', $t['rating']);
        $stmt->bindValue(':result_metric', $t['result_metric']);
        $stmt->bindValue(':is_featured', $t['is_featured']);
        $stmt->execute();
    }

    echo json_encode(["message" => "Testimonials seeded successfully", "count" => count($testimonials)]);

} catch (Exception $e) {
    echo json_encode(["message" => "Error seeding testimonials", "error" => $e->getMessage()]);
}
?>