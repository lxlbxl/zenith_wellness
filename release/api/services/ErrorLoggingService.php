<?php
/**
 * Error Logging Service
 * 
 * Centralized error logging with structured output for monitoring.
 * Can integrate with Sentry or other monitoring services.
 */

class ErrorLoggingService
{
    private $db;
    private $logFile;
    private $sentryDsn;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->logFile = __DIR__ . '/../../logs/app_errors.log';
        $this->sentryDsn = getenv('SENTRY_DSN') ?: null;

        // Ensure logs directory exists
        $logsDir = dirname($this->logFile);
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
    }

    /**
     * Log an error with context
     */
    public function logError($message, $context = [], $severity = 'error')
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
        ];

        // Add user ID if available
        if (isset($context['user_id'])) {
            $logEntry['user_id'] = $context['user_id'];
        }

        // Add stack trace for errors
        if ($severity === 'error' || $severity === 'critical') {
            $logEntry['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        }

        // File-based logging
        $this->writeToFile($logEntry);

        // Database logging (if available)
        if ($this->db) {
            $this->writeToDatabase($logEntry);
        }

        // Sentry integration (if configured)
        if ($this->sentryDsn) {
            $this->sendToSentry($logEntry);
        }

        return $logEntry;
    }

    /**
     * Log warning
     */
    public function warning($message, $context = [])
    {
        return $this->logError($message, $context, 'warning');
    }

    /**
     * Log info
     */
    public function info($message, $context = [])
    {
        return $this->logError($message, $context, 'info');
    }

    /**
     * Log critical error
     */
    public function critical($message, $context = [])
    {
        return $this->logError($message, $context, 'critical');
    }

    /**
     * Write log entry to file
     */
    private function writeToFile($entry)
    {
        $line = json_encode($entry) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write log entry to database
     */
    private function writeToDatabase($entry)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO error_logs (
                    severity, message, context, request_uri, 
                    method, user_id, ip_address, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $entry['severity'],
                $entry['message'],
                json_encode($entry['context']),
                $entry['request_uri'],
                $entry['method'],
                $entry['user_id'] ?? null,
                $entry['ip']
            ]);
        } catch (Exception $e) {
            // Don't throw if logging fails, just write to file
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }

    /**
     * Send to Sentry (placeholder for integration)
     */
    private function sendToSentry($entry)
    {
        // Integrate with Sentry SDK when available
        // \Sentry\captureMessage($entry['message'], $entry['severity']);
    }

    /**
     * Get recent errors for admin dashboard
     */
    public function getRecentErrors($limit = 50, $severity = null)
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM error_logs ";
        $params = [];

        if ($severity) {
            $sql .= "WHERE severity = ? ";
            $params[] = $severity;
        }

        $sql .= "ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get error counts by severity for dashboard
     */
    public function getErrorStats($days = 7)
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT 
                severity,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM error_logs 
            WHERE created_at > datetime('now', '-' || ? || ' days')
            GROUP BY severity, DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
