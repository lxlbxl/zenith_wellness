<?php
require_once __DIR__ . '/api/config/Database.php';
$db = (new Database())->getConnection();
$tables = ['users', 'cohort_enrollments', 'cohorts', 'user_results'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $stmt = $db->query("PRAGMA table_info($table)");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['name'] . " (" . $row['type'] . ")\n";
    }
}
