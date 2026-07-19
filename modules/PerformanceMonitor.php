<?php
/**
 * Performance Monitoring Module
 * 
 * Tracks query performance, API performance, and cache performance
 */

namespace CRM\Modules;

use CRM\Database;

class PerformanceMonitor
{
    private const MAX_RECORDED_QUERIES = 500;
    private const MAX_RECORDED_APIS = 500;
    private const MAX_SQL_SAMPLE_LENGTH = 2048;
    private const MAX_ENDPOINT_SAMPLE_LENGTH = 512;
    private static array $queryTimes = [];
    private static array $apiTimes = [];
    private static bool $trackingQuery = false;
    private static bool $trackingApi = false;
    
    public function __construct()
    {
        // CacheManager is optional - only use if available
    }
    
    /**
     * Track query execution time
     */
    public static function trackQuery(string $sql, float $executionTime): void
    {
        if (self::$trackingQuery) {
            return;
        }

        self::$trackingQuery = true;
        try {
            if (count(self::$queryTimes) < self::MAX_RECORDED_QUERIES) {
                self::$queryTimes[] = [
                    'sql' => self::trimSample($sql, self::MAX_SQL_SAMPLE_LENGTH),
                    'time' => $executionTime,
                    'timestamp' => microtime(true)
                ];
            }

            // Log slow queries (> 1 second)
            if ($executionTime > 1.0 && PHP_SAPI !== 'cli') {
                $logger = new \CRM\Logger();
                $logger->warn('Slow query detected', [
                    'sql' => substr(self::trimSample($sql, self::MAX_SQL_SAMPLE_LENGTH), 0, 200),
                    'execution_time' => $executionTime
                ]);
            }
        } finally {
            self::$trackingQuery = false;
        }
    }
    
    /**
     * Track API endpoint performance
     */
    public static function trackAPI(string $endpoint, string $method, float $executionTime, int $statusCode = 200): void
    {
        if (self::$trackingApi) {
            return;
        }

        self::$trackingApi = true;
        try {
            if (count(self::$apiTimes) < self::MAX_RECORDED_APIS) {
                self::$apiTimes[] = [
                    'endpoint' => self::trimSample($endpoint, self::MAX_ENDPOINT_SAMPLE_LENGTH),
                    'method' => $method,
                    'time' => $executionTime,
                    'status_code' => $statusCode,
                    'timestamp' => microtime(true)
                ];
            }

            // Log slow API calls (> 2 seconds)
            if ($executionTime > 2.0 && PHP_SAPI !== 'cli') {
                $logger = new \CRM\Logger();
                $logger->warn('Slow API call detected', [
                    'endpoint' => self::trimSample($endpoint, self::MAX_ENDPOINT_SAMPLE_LENGTH),
                    'method' => $method,
                    'execution_time' => $executionTime
                ]);
            }
        } finally {
            self::$trackingApi = false;
        }
    }
    
    /**
     * Get query performance statistics
     */
    public function getQueryStats(int $hours = 24): array
    {
        $cutoff = microtime(true) - ($hours * 3600);
        $recentQueries = array_filter(self::$queryTimes, fn($q) => $q['timestamp'] > $cutoff);
        
        if (empty($recentQueries)) {
            return [
                'total' => 0,
                'avg_time' => 0,
                'max_time' => 0,
                'min_time' => 0,
                'slow_queries' => 0
            ];
        }
        
        $times = array_column($recentQueries, 'time');
        
        return [
            'total' => count($recentQueries),
            'avg_time' => array_sum($times) / count($times),
            'max_time' => max($times),
            'min_time' => min($times),
            'slow_queries' => count(array_filter($times, fn($t) => $t > 1.0))
        ];
    }
    
    /**
     * Get API performance statistics
     */
    public function getAPIStats(int $hours = 24): array
    {
        $cutoff = microtime(true) - ($hours * 3600);
        $recentAPIs = array_filter(self::$apiTimes, fn($a) => $a['timestamp'] > $cutoff);
        
        if (empty($recentAPIs)) {
            return [
                'total' => 0,
                'avg_time' => 0,
                'max_time' => 0,
                'min_time' => 0,
                'by_endpoint' => [],
                'by_status' => []
            ];
        }
        
        $times = array_column($recentAPIs, 'time');
        $byEndpoint = [];
        $byStatus = [];
        
        foreach ($recentAPIs as $api) {
            $endpoint = $api['endpoint'];
            $status = $api['status_code'];
            
            if (!isset($byEndpoint[$endpoint])) {
                $byEndpoint[$endpoint] = ['count' => 0, 'total_time' => 0, 'avg_time' => 0];
            }
            $byEndpoint[$endpoint]['count']++;
            $byEndpoint[$endpoint]['total_time'] += $api['time'];
            
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }
        
        foreach ($byEndpoint as &$endpoint) {
            $endpoint['avg_time'] = $endpoint['total_time'] / $endpoint['count'];
        }
        
        return [
            'total' => count($recentAPIs),
            'avg_time' => array_sum($times) / count($times),
            'max_time' => max($times),
            'min_time' => min($times),
            'by_endpoint' => $byEndpoint,
            'by_status' => $byStatus
        ];
    }
    
    /**
     * Get cache performance statistics
     */
    public function getCacheStats(): array
    {
        try {
            // Try to get Redis stats if available
            if (class_exists('Redis')) {
                $redis = new \Redis();
                $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', $_ENV['REDIS_PORT'] ?? 6379);
                $info = $redis->info('stats');
                
                return [
                    'hits' => $info['keyspace_hits'] ?? 0,
                    'misses' => $info['keyspace_misses'] ?? 0,
                    'hit_rate' => $this->calculateHitRate($info['keyspace_hits'] ?? 0, $info['keyspace_misses'] ?? 0),
                    'memory_used' => $info['used_memory'] ?? 0,
                    'memory_peak' => $info['used_memory_peak'] ?? 0
                ];
            }
        } catch (\Exception $e) {
            // Redis not available, return empty stats
        }
        
        return [
            'hits' => 0,
            'misses' => 0,
            'hit_rate' => 0,
            'memory_used' => 0,
            'memory_peak' => 0
        ];
    }
    
    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(int $hits, int $misses): float
    {
        $total = $hits + $misses;
        if ($total === 0) {
            return 0;
        }
        return ($hits / $total) * 100;
    }
    
    /**
     * Get slow queries
     */
    public function getSlowQueries(float $threshold = 1.0, int $limit = 10): array
    {
        $slowQueries = array_filter(self::$queryTimes, fn($q) => $q['time'] > $threshold);
        usort($slowQueries, fn($a, $b) => $b['time'] <=> $a['time']);
        
        return array_slice($slowQueries, 0, $limit);
    }

    public static function reset(): void
    {
        self::$queryTimes = [];
        self::$apiTimes = [];
        self::$trackingQuery = false;
        self::$trackingApi = false;
    }

    /**
     * Keep a bounded in-memory sample so long test runs do not exhaust PHP memory.
     *
     * @param array<int, array<string, mixed>> $records
     */
    private static function trimRecords(array &$records, int $maxRecords): void
    {
        if (count($records) > $maxRecords) {
            $records = array_slice($records, -$maxRecords);
        }
    }

    private static function trimSample(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, max(0, $maxLength - 3)) . '...';
    }
}
