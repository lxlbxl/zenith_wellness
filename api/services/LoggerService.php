<?php

class LoggerService
{
    private $logFile;

    public function __construct()
    {
        $logDir = __DIR__ . '/../../logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
    }

    public function log($level, $message, $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logEntry = "[$timestamp] [$level] $message $contextStr" . PHP_EOL;

        // Append to file
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    public function info($message, $context = [])
    {
        $this->log('INFO', $message, $context);
    }

    public function error($message, $context = [])
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning($message, $context = [])
    {
        $this->log('WARNING', $message, $context);
    }
}
