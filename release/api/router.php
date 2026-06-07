<?php
// Router for PHP built-in server when running with -t api
// This ensures that requests to /auth/login (which are not files) 
// are correctly routed to index.php instead of returning 404 HTML.

$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// If the requested path corresponds to an actual file, serve it directly.
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // Let PHP serve the file
}

// Otherwise, route everything to index.php
require __DIR__ . '/index.php';
?>