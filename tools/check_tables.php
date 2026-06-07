<?php
require_once __DIR__ . '/api/config/Database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . "\n";
}
