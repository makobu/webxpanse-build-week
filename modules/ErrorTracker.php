<?php
/**
 * Error Tracking Module
 * 
 * Tracks, analyzes, and manages application errors
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Logger;

class ErrorTracker
{
    private Logger $logger;
    
    public function __construct()
    {
        $this->logger = new Logger();
    }
    
    /**
     * Track an error
     */
    public function trackError(\Throwable $error, array $context = []): int
    {
        $errorData = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'type' => get_class($error),
            'context' => $context,
            'user_id' => \CRM\Auth::userId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'resolved' => false,
            'resolved_at' => null,
            'resolved_by' => null
        ];
        
        // Log to file
        $this->logger->error($error->getMessage(), $errorData);
        
        // Store in database
        try {
            Database::execute(
                "INSERT INTO error_logs (message, code, file, line, trace, error_type, context, user_id, ip_address, user_agent, url, method, resolved) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $errorData['message'],
                    $errorData['code'],
                    $errorData['file'],
                    $errorData['line'],
                    $errorData['trace'],
                    $errorData['type'],
                    json_encode($errorData['context']),
                    $errorData['user_id'],
                    $errorData['ip_address'],
                    $errorData['user_agent'],
                    $errorData['url'],
                    $errorData['method'],
                    false
                ]
            );
            
            return (int) Database::lastInsertId();
        } catch (\Exception $e) {
            // If database insert fails, at least we have the log file
            return 0;
        }
    }
    
    /**
     * Get errors with filters
     */
    public function getErrors(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['resolved'])) {
            $where[] = "resolved = ?";
            $params[] = (bool) $filters['resolved'];
        }
        
        if (!empty($filters['error_type'])) {
            $where[] = "error_type = ?";
            $params[] = $filters['error_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "SELECT el.*, u.email as user_email
                FROM error_logs el
                LEFT JOIN users u ON el.user_id = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY el.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $errors = Database::query($sql, $params);
        
        foreach ($errors as &$error) {
            $error['context'] = !empty($error['context']) ? json_decode($error['context'], true) : [];
        }
        
        return $errors;
    }
    
    /**
     * Get error by ID
     */
    public function getErrorById(int $id): ?array
    {
        $error = Database::queryOne(
            "SELECT el.*, u.email as user_email
             FROM error_logs el
             LEFT JOIN users u ON el.user_id = u.id
             WHERE el.id = ?",
            [$id]
        );
        
        if ($error) {
            $error['context'] = !empty($error['context']) ? json_decode($error['context'], true) : [];
        }
        
        return $error;
    }
    
    /**
     * Mark error as resolved
     */
    public function resolveError(int $id, int $resolvedBy): bool
    {
        Database::execute(
            "UPDATE error_logs 
             SET resolved = TRUE, resolved_at = NOW(), resolved_by = ? 
             WHERE id = ?",
            [$resolvedBy, $id]
        );
        
        return true;
    }
    
    /**
     * Get error statistics
     */
    public function getErrorStats(int $days = 7): array
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_errors,
                COUNT(CASE WHEN resolved = FALSE THEN 1 END) as unresolved_errors,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as errors_today,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as errors_this_week
             FROM error_logs
             WHERE created_at >= ?",
            [$cutoffDate]
        );
        
        $byType = Database::query(
            "SELECT error_type, COUNT(*) as count
             FROM error_logs
             WHERE created_at >= ?
             GROUP BY error_type
             ORDER BY count DESC",
            [$cutoffDate]
        );
        
        $byDay = Database::query(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM error_logs
             WHERE created_at >= ?
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$cutoffDate]
        );
        
        return [
            'total_errors' => (int) ($stats['total_errors'] ?? 0),
            'unresolved_errors' => (int) ($stats['unresolved_errors'] ?? 0),
            'errors_today' => (int) ($stats['errors_today'] ?? 0),
            'errors_this_week' => (int) ($stats['errors_this_week'] ?? 0),
            'by_type' => $byType,
            'by_day' => $byDay
        ];
    }
    
    /**
     * Get similar errors (for grouping)
     */
    public function getSimilarErrors(string $message, int $limit = 10): array
    {
        // Find errors with similar messages
        $errors = Database::query(
            "SELECT id, message, created_at, COUNT(*) as occurrence_count
             FROM error_logs
             WHERE message LIKE ?
             GROUP BY message
             ORDER BY occurrence_count DESC, created_at DESC
             LIMIT ?",
            ['%' . substr($message, 0, 50) . '%', $limit]
        );
        
        return $errors;
    }
}
