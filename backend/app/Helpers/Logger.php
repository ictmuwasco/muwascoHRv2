<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Logger Helper - Structured logging with file and database support.
 */
class Logger
{
    private string $logDir;
    private string $minLevel;

    private const LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public function __construct()
    {
        $this->logDir = \config('app.log_path', STORAGE_PATH . '/logs');
        $this->minLevel = \env('LOG_LEVEL', 'debug');

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }
    }

    /**
     * Log a message at the specified level.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        // Check minimum log level
        if (self::LEVELS[$level] > self::LEVELS[$this->minLevel] ?? 7) {
            return;
        }

        $formatted = $this->formatMessage($level, $message, $context);
        $this->writeToFile($formatted, $level);
    }

    /**
     * Log an emergency message.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Log an alert message.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log a notice message.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Format the log message.
     */
    private function formatMessage(string $level, string $message, array $context = []): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userId = $_SESSION['user_id'] ?? 0;
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

        return "[{$timestamp}] {$level}: {$message} | IP: {$ip} | User: {$userId}{$contextStr}";
    }

    /**
     * Write the log entry to file.
     */
    private function writeToFile(string $entry, string $level): void
    {
        $filename = $this->logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($filename, $entry . PHP_EOL, FILE_APPEND);
    }
}