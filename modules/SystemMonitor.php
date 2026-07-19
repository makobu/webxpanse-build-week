<?php
/**
 * System Monitor Module
 * 
 * Monitors server resources, database, and queue status
 */

namespace CRM\Modules;

use CRM\Database;

class SystemMonitor
{
    /**
     * Get system health status
     */
    public function getHealthStatus(): array
    {
        return [
            'status' => $this->getOverallStatus(),
            'database' => $this->getDatabaseStatus(),
            'cache' => $this->getCacheStatus(),
            'queue' => $this->getQueueStatus(),
            'disk' => $this->getDiskUsage(),
            'memory' => $this->getMemoryUsage()
        ];
    }
    
    /**
     * Get overall system status
     */
    private function getOverallStatus(): string
    {
        $dbStatus = $this->getDatabaseStatus();
        $queueStatus = $this->getQueueStatus();
        
        if ($dbStatus['status'] === 'error' || $queueStatus['status'] === 'error') {
            return 'error';
        }
        
        if ($dbStatus['status'] === 'warning' || $queueStatus['status'] === 'warning') {
            return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * Get database status
     */
    public function getDatabaseStatus(): array
    {
        try {
            $startTime = microtime(true);
            Database::queryOne("SELECT 1");
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            
            $status = 'healthy';
            if ($responseTime > 1000) {
                $status = 'warning';
            }
            if ($responseTime > 5000) {
                $status = 'error';
            }
            
            // Get connection count
            $connections = Database::queryOne("SHOW STATUS LIKE 'Threads_connected'");
            $connectionCount = (int) ($connections['Value'] ?? 0);
            
            return [
                'status' => $status,
                'response_time_ms' => round($responseTime, 2),
                'connections' => $connectionCount,
                'max_connections' => $this->getMaxConnections()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get max database connections
     */
    private function getMaxConnections(): int
    {
        try {
            $result = Database::queryOne("SHOW VARIABLES LIKE 'max_connections'");
            return (int) ($result['Value'] ?? 100);
        } catch (\Exception $e) {
            return 100;
        }
    }
    
    /**
     * Get cache status
     */
    public function getCacheStatus(): array
    {
        try {
            if (class_exists('Redis')) {
                $redis = new \Redis();
                $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', $_ENV['REDIS_PORT'] ?? 6379);
                $info = $redis->info();
                
                return [
                    'status' => 'healthy',
                    'type' => 'redis',
                    'connected' => true,
                    'memory_used' => $info['used_memory'] ?? 0,
                    'memory_peak' => $info['used_memory_peak'] ?? 0
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'warning',
                'type' => 'file',
                'connected' => false,
                'error' => 'Redis not available, using file cache'
            ];
        }
        
        return [
            'status' => 'warning',
            'type' => 'file',
            'connected' => false
        ];
    }
    
    /**
     * Get queue status
     */
    public function getQueueStatus(): array
    {
        try {
            $pending = Database::queryOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE status = 'pending'"
            );
            $failed = Database::queryOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE status = 'failed'"
            );
            $processing = Database::queryOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE status = 'processing'"
            );
            
            $pendingCount = (int) ($pending['count'] ?? 0);
            $failedCount = (int) ($failed['count'] ?? 0);
            $processingCount = (int) ($processing['count'] ?? 0);
            
            $status = 'healthy';
            if ($pendingCount > 100 || $failedCount > 10) {
                $status = 'warning';
            }
            if ($pendingCount > 1000 || $failedCount > 100) {
                $status = 'error';
            }
            
            return [
                'status' => $status,
                'pending' => $pendingCount,
                'processing' => $processingCount,
                'failed' => $failedCount
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get disk usage
     */
    public function getDiskUsage(): array
    {
        $path = __DIR__ . '/../';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $percent = ($total > 0) ? ($used / $total) * 100 : 0;
        
        $status = 'healthy';
        if ($percent > 80) {
            $status = 'warning';
        }
        if ($percent > 90) {
            $status = 'error';
        }
        
        return [
            'status' => $status,
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => round($percent, 2)
        ];
    }
    
    /**
     * Get memory usage
     */
    public function getMemoryUsage(): array
    {
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryUsed = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $percent = ($memoryLimit > 0) ? ($memoryUsed / $memoryLimit) * 100 : 0;
        
        $status = 'healthy';
        if ($percent > 80) {
            $status = 'warning';
        }
        if ($percent > 90) {
            $status = 'error';
        }
        
        return [
            'status' => $status,
            'limit' => $memoryLimit,
            'used' => $memoryUsed,
            'peak' => $memoryPeak,
            'percent' => round($percent, 2)
        ];
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}
