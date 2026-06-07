<?php

// In a real Composer environment:
// \Sentry\init(['dsn' => '...']);

// For this implementation without Composer access, we'll create a simple mockup or 
// check if the class exists to avoid crashing if the user hasn't run composer install.

if (class_exists('\Sentry\SentrySdk')) {
    $dsn = getenv('SENTRY_DSN');
    if ($dsn) {
        \Sentry\init([
            'dsn' => $dsn,
            'environment' => getenv('APP_ENV') ?: 'production',
        ]);
    }
}

// Global Exception Handler Wrapper to ensure JSON response even with Sentry
// (Sentry usually registers its own, but we want to ensure API friendly output)
// This is already handled in index.php but we can enhance it here if needed.
