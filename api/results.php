<?php
// api/results.php
include_once __DIR__ . '/config/Database.php';
include_once __DIR__ . '/middleware/CorsMiddleware.php';

$cors = new CorsMiddleware();
$cors->handle();

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

try {
    $query = "SELECT * FROM user_results ORDER BY created_at DESC LIMIT 6";
    $stmt = $db->prepare($query);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = $row;
    }

    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Failed to fetch results", "error" => $e->getMessage()]);
}
?>