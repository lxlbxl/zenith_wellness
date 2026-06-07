<?php

require_once __DIR__ . '/../config.php';

class RateLimitMiddleware
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Apply rate limiting
     * @param int $limit Max requests per window
     * @param int $seconds Time window in seconds
     * @param string|null $endpoint Optional endpoint for specific limits
     */
    public function handle($limit = null, $seconds = null, $endpoint = null)
    {
        // Use config defaults if not provided
        $limit = $limit ?? (defined('RATE_LIMIT_REQUESTS') ? RATE_LIMIT_REQUESTS : 60);
        $seconds = $seconds ?? (defined('RATE_LIMIT_WINDOW') ? RATE_LIMIT_WINDOW : 60);

        $ip = $this->getClientIp();
        $endpoint = $endpoint ?? $this->getCurrentEndpoint();

        try {
            // Clean old request logs for this IP (SQLite compatible)
            $cutoff = date('Y-m-d H:i:s', time() - $seconds);
            $stmt = $this->db->prepare("DELETE FROM request_logs WHERE ip_address = ? AND endpoint = ? AND created_at < ?");
            $stmt->execute([$ip, $endpoint, $cutoff]);

            // Count recent requests
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM request_logs WHERE ip_address = ? AND endpoint = ?");
            $stmt->execute([$ip, $endpoint]);
            $count = (int) $stmt->fetchColumn();

            // Check limit
            if ($count >= $limit) {
                $retryAfter = $seconds;
                http_response_code(429);
                header("Retry-After: $retryAfter");
                header("X-RateLimit-Limit: $limit");
                header("X-RateLimit-Remaining: 0");
                header("X-RateLimit-Reset: " . (time() + $retryAfter));

                echo json_encode([
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $retryAfter
                ]);
                exit();
            }

            // Log this request
            $id = uniqid('rl_');
            $stmt = $this->db->prepare("INSERT INTO request_logs (id, ip_address, endpoint, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$id, $ip, $endpoint]);

            // Set rate limit headers
            header("X-RateLimit-Limit: $limit");
            header("X-RateLimit-Remaining: " . max(0, $limit - $count - 1));

        } catch (PDOException $e) {
            // Fail open if DB issue (don't block user if logs fail)
            error_log("Rate limit error: " . $e->getMessage());
        }
    }

    /**
     * Apply stricter rate limiting for auth endpoints
     */
    public function handleAuth()
    {
        $limit = defined('AUTH_RATE_LIMIT_REQUESTS') ? AUTH_RATE_LIMIT_REQUESTS : 10;
        $window = defined('AUTH_RATE_LIMIT_WINDOW') ? AUTH_RATE_LIMIT_WINDOW : 60;

        $this->handle($limit, $window, 'auth');
    }

    /**
     * Apply even stricter rate limiting for password reset (prevent enumeration)
     */
    public function handlePasswordReset()
    {
        $this->handle(5, 300, 'password_reset'); // 5 requests per 5 minutes
    }

    /**
     * Track failed login attempts for potential lockout
     * @param string $identifier Email or user ID
     */
    public function recordFailedLogin($identifier)
    {
        try {
            $ip = $this->getClientIp();
            $id = uniqid('fl_');
            $stmt = $this->db->prepare("INSERT INTO request_logs (id, ip_address, endpoint, user_identifier, created_at) VALUES (?, ?, 'failed_login', ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$id, $ip, $identifier]);

            // Log security event
            error_log("Failed login attempt for $identifier from $ip");
        } catch (PDOException $e) {
            error_log("Failed to record failed login: " . $e->getMessage());
        }
    }

    /**
     * Check if account is temporarily locked due to failed attempts
     * @param string $identifier Email or user ID
     * @return bool
     */
    public function isAccountLocked($identifier): bool
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - 900); // 15 minute window
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM request_logs WHERE endpoint = 'failed_login' AND user_identifier = ? AND created_at > ?");
            $stmt->execute([$identifier, $cutoff]);
            $failedAttempts = (int) $stmt->fetchColumn();

            return $failedAttempts >= 5; // Lock after 5 failed attempts
        } catch (PDOException $e) {
            return false; // Fail open
        }
    }

    /**
     * Get client IP address (handles proxies)
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Standard proxy
            'HTTP_X_REAL_IP',        // Nginx
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get current endpoint category for rate limiting
     */
    private function getCurrentEndpoint(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Categorize endpoints
        if (preg_match('/\/(auth|login|register)/', $uri)) {
            return 'auth';
        }
        if (preg_match('/\/password/', $uri)) {
            return 'password';
        }
        if (preg_match('/\/payment/', $uri)) {
            return 'payment';
        }
        if (preg_match('/\/admin/', $uri)) {
            return 'admin';
        }
        if (preg_match('/\/ai|\/chat/', $uri)) {
            return 'ai';
        }

        return 'general';
    }
}
