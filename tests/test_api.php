<?php
function callApi($method, $url, $data = false)
{
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => $method,
            'ignore_errors' => true // Capture error responses
        ]
    ];

    if ($data) {
        $options['http']['content'] = $data;
    }

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    if ($result === FALSE) {
        return "Error connecting to endpoint";
    }
    return $result;
}

$baseUrl = "http://localhost:8000/api";

echo "1. Testing Registration...\n";
$email = "test_" . uniqid() . "@zenith.com";
$regData = json_encode([
    "name" => "API Tester",
    "email" => $email,
    "password" => "securepass"
]);
$regResponse = callApi('POST', $baseUrl . '/auth/register', $regData);
echo "Response: " . $regResponse . "\n\n";

echo "2. Testing Login...\n";
$loginData = json_encode([
    "email" => $email,
    "password" => "securepass"
]);
$loginResponse = callApi('POST', $baseUrl . '/auth/login', $loginData);
echo "Response: " . $loginResponse . "\n\n";

// Decode to find token
$json = json_decode($loginResponse, true);
if (isset($json['token'])) {
    echo "SUCCESS: Token received: " . substr($json['token'], 0, 20) . "...\n";
} else {
    echo "FAILURE: No token in response.\n";
}
?>