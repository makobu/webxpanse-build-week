<?php
/**
 * Comprehensive Logging System
 * 
 * Structured logging with JSON format, log levels, and rotation
 */

namespace CRM;

class Logger
{
    private const LOG_LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARN' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    private string $logDir;
    private string $minLevel;
    private bool $jsonFormat;
    
    public function __construct()
    {
        $this->logDir = $_ENV['LOG_DIR'] ?? __DIR__ . '/../logs';
        $this->minLevel = $_ENV['LOG_LEVEL'] ?? 'INFO';
        $this->jsonFormat = ($_ENV['LOG_FORMAT'] ?? 'json') === 'json';
        
        // Ensure log directory exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * Log a message
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s.v'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'request_id' => $this->getRequestId(),
            'user_id' => Auth::userId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null
        ];
        
        $logFile = $this->getLogFile($level);
        $logLine = $this->jsonFormat 
            ? json_encode($logEntry) . "\n"
            : $this->formatTextLog($logEntry);
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Rotate logs if needed
        $this->rotateLogs($logFile);
    }
    
    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warn(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }
    
    /**
     * Check if should log at this level
     */
    private function shouldLog(string $level): bool
    {
        $levelValue = self::LOG_LEVELS[strtoupper($level)] ?? 999;
        $minLevelValue = self::LOG_LEVELS[$this->minLevel] ?? 0;
        
        return $levelValue >= $minLevelValue;
    }
    
    /**
     * Get log file path
     */
    private function getLogFile(string $level): string
    {
        $date = date('Y-m-d');
        $levelLower = strtolower($level);
        return $this->logDir . "/{$levelLower}-{$date}.log";
    }
    
    /**
     * Format text log entry
     */
    private function formatTextLog(array $entry): string
    {
        $context = !empty($entry['context']) ? ' ' . json_encode($entry['context']) : '';
        return sprintf(
            "[%s] %s: %s%s\n",
            $entry['timestamp'],
            $entry['level'],
            $entry['message'],
            $context
        );
    }
    
    /**
     * Get request ID for tracking
     */
    private function getRequestId(): string
    {
        if (!isset($_SERVER['REQUEST_ID'])) {
            $_SERVER['REQUEST_ID'] = bin2hex(random_bytes(8));
        }
        return $_SERVER['REQUEST_ID'];
    }
    
    /**
     * Rotate logs (keep last 30 days)
     */
    private function rotateLogs(string $logFile): void
    {
        $maxAge = 30 * 24 * 60 * 60; // 30 days in seconds
        $cutoffTime = time() - $maxAge;
        
        $logDir = dirname($logFile);
        $files = glob($logDir . '/*.log');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get log entries
     */
    public function getLogs(string $level = null, int $limit = 100, int $offset = 0): array
    {
        $logs = [];
        $logDir = $this->logDir;
        
        if ($level) {
            $pattern = $logDir . '/' . strtolower($level) . '-*.log';
        } else {
            $pattern = $logDir . '/*.log';
        }
        
        $files = glob($pattern);
        rsort($files); // Most recent first
        
        $count = 0;
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines); // Most recent first
            
            foreach ($lines as $line) {
                if ($count < $offset) {
                    $count++;
                    continue;
                }
                
                if (count($logs) >= $limit) {
                    break 2;
                }
                
                if ($this->jsonFormat) {
                    $logEntry = json_decode($line, true);
                    if ($logEntry) {
                        $logs[] = $logEntry;
                    }
                } else {
                    // Parse text format
                    if (preg_match('/\[([^\]]+)\] (\w+): (.+)/', $line, $matches)) {
                        $logs[] = [
                            'timestamp' => $matches[1],
                            'level' => $matches[2],
                            'message' => $matches[3]
                        ];
                    }
                }
                
                $count++;
            }
        }
        
        return $logs;
    }
    
    /**
     * Get log statistics
     */
    public function getStats(int $days = 7): array
    {
        $stats = [
            'total' => 0,
            'by_level' => [],
            'by_day' => [],
            'errors_today' => 0,
            'errors_this_week' => 0
        ];
        
        $cutoffDate = date('Y-m-d', strtotime("-{$days} days"));
        $logDir = $this->logDir;
        $files = glob($logDir . '/*.log');
        
        foreach ($files as $file) {
            $fileDate = basename($file);
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $fileDate, $matches)) {
                if ($matches[1] < $cutoffDate) {
                    continue;
                }
            }
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                if ($this->jsonFormat) {
                    $entry = json_decode($line, true);
                    if (!$entry) continue;
                    
                    $level = $entry['level'] ?? 'UNKNOWN';
                    $timestamp = $entry['timestamp'] ?? '';
                    $date = substr($timestamp, 0, 10);
                } else {
                    if (preg_match('/\[([^\]]+)\] (\w+):/', $line, $matches)) {
                        $level = $matches[2];
                        $date = substr($matches[1], 0, 10);
                    } else {
                        continue;
                    }
                }
                
                $stats['total']++;
                $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
                $stats['by_day'][$date] = ($stats['by_day'][$date] ?? 0) + 1;
                
                if (in_array($level, ['ERROR', 'CRITICAL'])) {
                    $stats['errors_this_week']++;
                    if ($date === date('Y-m-d')) {
                        $stats['errors_today']++;
                    }
                }
            }
        }
        
        return $stats;
    }
}
