<?php
/**
 * Health Check Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CRM\Database;

header('Content-Type: application/json');

// Database check
try {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
    
    require_once __DIR__ . '/../config/constants.php';
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
    Database::query("SELECT 1");
} catch (\Exception $e) {
    $dbConfig = null;
    $databaseError = true;
    error_log('Health check database failure: ' . $e->getMessage());
}

$health = [
    'status' => empty($databaseError) ? 'ok' : 'error',
    'timestamp' => date('c'),
    'checks' => [
        'database' => empty($databaseError) ? 'ok' : 'error',
        'strict_sql_mode' => 'unknown',
        'opcache' => extension_loaded('Zend OPcache') && filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL) ? 'ok' : 'disabled',
        'session_handler' => (string) ini_get('session.save_handler'),
        'queue_workers' => 'unknown',
    ],
];

if (empty($databaseError)) {
    try {
        $sqlMode = strtoupper((string) (Database::queryOne('SELECT @@SESSION.sql_mode AS mode')['mode'] ?? ''));
        $health['checks']['strict_sql_mode'] = str_contains($sqlMode, 'STRICT_TRANS_TABLES') || str_contains($sqlMode, 'STRICT_ALL_TABLES')
            ? 'ok'
            : 'error';
        if (Database::tableExists('queue_worker_heartbeats')) {
            $heartbeat = Database::queryOne("SELECT MAX(heartbeat_at) AS latest FROM queue_worker_heartbeats WHERE status = 'running'");
            $latest = (string) ($heartbeat['latest'] ?? '');
            $health['checks']['queue_workers'] = $latest === ''
                ? 'not_seen'
                : (strtotime($latest) >= time() - 300 ? 'ok' : 'stale');
        }
    } catch (\Throwable $e) {
        error_log('Health concurrency check failed: ' . $e->getMessage());
    }
}

// Redis check
try {
    if (extension_loaded('redis')) {
        $redis = new \Redis();
        $redis->connect($_ENV['REDIS_HOST'] ?? 'localhost', (int) ($_ENV['REDIS_PORT'] ?? 6379));
        $redis->ping();
        $health['checks']['redis'] = 'ok';
    } else {
        $health['checks']['redis'] = 'not_configured';
    }
} catch (\Exception $e) {
    error_log('Health check Redis failure: ' . $e->getMessage());
    $health['checks']['redis'] = 'error';
}

http_response_code($health['status'] === 'ok' ? 200 : 503);
echo json_encode($health, JSON_PRETTY_PRINT);
