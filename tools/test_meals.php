<?php
// Test Script for MealController API

$baseUrl = 'http://localhost/api'; // Adjust if needed
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
            // For GET with data (rare), append to URL instead
            $fullUrl = sprintf("%s?%s", $fullUrl, http_build_query($data));
            unset($options['http']['content']);
        }
    }

    $context = stream_context_create($options);
    $result = file_get_contents($fullUrl, false, $context);

    // Parse response headers for status code
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

echo "Testing MealController API...\n";
echo "User ID: $userId\n\n";

// 1. Log a Meal
echo "1. Logging a meal...\n";
$mealData = [
    'user_id' => $userId,
    'name' => 'Test Salad',
    'type' => 'lunch',
    'calories' => 350,
    'macros' => ['protein' => 15, 'carbs' => 30, 'fats' => 10],
    'analysis' => ['wellnessScore' => 8, 'summary' => 'Healthy choice']
];
$res = callAPI('POST', '/meals/log', $mealData);
echo "Status: " . $res['code'] . "\n";
echo "Response: " . json_encode($res['response']) . "\n\n";
$mealId = $res['response']['id'] ?? null;

// 2. Get Meal History
echo "2. Getting meal history...\n";
$res = callAPI('GET', "/meals/history?user_id=$userId");
echo "Status: " . $res['code'] . "\n";
echo "Count: " . count($res['response']) . "\n\n";

// 3. Get Stats
echo "3. Getting meal stats...\n";
$res = callAPI('GET', "/meals/stats?user_id=$userId&range=today");
echo "Status: " . $res['code'] . "\n";
echo "Response: " . json_encode($res['response']) . "\n\n";

// 4. Delete Meal
if ($mealId) {
    echo "4. Deleting meal $mealId...\n";
    $res = callAPI('DELETE', "/meals/$mealId");
    echo "Status: " . $res['code'] . "\n";
    echo "Response: " . json_encode($res['response']) . "\n\n";
}

// 5. Bulk Delete
echo "5. Bulk deleting history...\n";
$res = callAPI('DELETE', "/meals/history?user_id=$userId");
echo "Status: " . $res['code'] . "\n";
echo "Response: " . json_encode($res['response']) . "\n\n";

?>