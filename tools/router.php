<?php
// router.php for PHP built-in server

$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Serve existing files as-is
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Route /api requests to api/index.php
if (strpos($uri, '/api/') === 0) {
    // We need to keep the $_SERVER['REQUEST_URI'] intact for api/index.php to parse
    require __DIR__ . '/api/index.php';
    return;
}

// Default 404
http_response_code(404);
echo "Not Found";
?>