<?php
// Zenith Wellness Verification Script
// Tests: Cohorts, Meals, Routines

$baseUrl = 'http://localhost/api';
$userId = 'test_user_' . uniqid();

function callAPI($method, $url, $data = false)
{
    $fullUrl = $GLOBALS['baseUrl'] . $url;

    $options = [
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'ignore_errors' => true
        ]
    ];

    if ($data) {
        $options['http']['content'] = json_encode($data);
        if ($method == 'GET') {
            $fullUrl = sprintf("%s?%s", $fullUrl, http_build_query($data));
            unset($options['http']['content']);
        }
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($fullUrl, false, $context);

    if ($result === FALSE) {
        echo "Error: Failed to connect to $fullUrl\n";
        return ['code' => 500, 'response' => null];
    }

    $httpCode = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('|^HTTP/\d\.\d (\d+)|', $header, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    return ['code' => $httpCode, 'response' => json_decode($result, true)];
}

echo "=== Zenith Wellness Verification ===\n";
echo "User ID: $userId\n\n";

// --- MEALS ---
echo "[Meal] Logging meal...\n";
$mealData = ['user_id' => $userId, 'name' => 'API Salad', 'calories' => 300, 'type' => 'lunch'];
$res = callAPI('POST', '/meals/log', $mealData);
$mealId = $res['response']['id'] ?? null;
echo "Result: " . ($res['code'] == 201 ? "PASS" : "FAIL ({$res['code']})") . "\n";

echo "[Meal] Getting history...\n";
$res = callAPI('GET', "/meals/history?user_id=$userId");
echo "Result: " . ($res['code'] == 200 && count($res['response']) > 0 ? "PASS" : "FAIL") . "\n";

// --- ROUTINES ---
echo "\n[Routine] Generating AI Routine (Standalone)...\n";
$res = callAPI('POST', "/routines/generate/$userId", []);
$routineId = $res['response']['routine_id'] ?? null;
echo "Result: " . ($res['code'] == 200 && $routineId ? "PASS" : "FAIL ({$res['code']})") . "\n";

if ($routineId) {
    echo "[Routine] Getting generated specific routine...\n";
    $res = callAPI('GET', "/routines/$routineId");
    $items = $res['response']['items'] ?? [];
    echo "Items count: " . count($items) . "\n";

    if (count($items) > 0) {
        $itemId = $items[0]['id'];
        echo "[Routine] Deleting item $itemId...\n";
        $res = callAPI('DELETE', "/routines/items/$itemId");
        echo "Result: " . ($res['code'] == 200 ? "PASS" : "FAIL") . "\n";
    }
}

// --- COHORTS ---
echo "\n[Cohort] Creating test cohort...\n";
$cohortData = [
    'title' => 'API Test Cohort',
    'description' => 'Created via test script',
    'start_date' => date('Y-m-d'),
    'duration_days' => 7,
    'current_participants' => 0,
    'max_participants' => 10,
    'status' => 'active',
    'category' => 'fitness',
    'created_by' => $userId
];
$res = callAPI('POST', '/cohorts', $cohortData);
$cohortId = $res['response']['id'] ?? null;
echo "Result: " . ($res['code'] == 201 ? "PASS" : "FAIL ({$res['code']})") . "\n";

if ($cohortId) {
    echo "[Cohort] Adding participant...\n";
    $pData = [
        'user_id' => $userId,
        'user_name' => 'Test User',
        'persona' => 'The Tester'
    ];
    $res = callAPI('POST', "/cohorts/$cohortId/participants", $pData);
    echo "Result: " . ($res['code'] == 201 ? "PASS" : "FAIL") . "\n";
}

echo "\n=== Verification Complete ===\n";
?>