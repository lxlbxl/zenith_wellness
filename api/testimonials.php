<?php
// api/testimonials.php
include_once __DIR__ . '/config/Database.php';
include_once __DIR__ . '/middleware/CorsMiddleware.php';

$cors = new CorsMiddleware();
$cors->handle();

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

try {
    // Fetch featured testimonials first, then approved ones
    $query = "SELECT * FROM testimonials WHERE is_approved = 1 ORDER BY is_featured DESC, created_at DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();

    $testimonials = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $testimonials[] = $row;
    }

    echo json_encode($testimonials);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Failed to fetch testimonials", "error" => $e->getMessage()]);
}
?>