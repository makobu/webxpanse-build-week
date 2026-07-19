<?php
/**
 * Database Wrapper Class
 * 
 * PDO wrapper with connection pooling and query helpers
 */

namespace CRM;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];
    private static int $queryCount = 0;
    private static array $tableExistsCache = [];
    private static array $columnExistsCache = [];
    
    /**
     * Initialize database connection
     */
    public static function init(array $config): void
    {
        self::$config = $config;
        self::$queryCount = 0;
        self::clearSchemaCache();
    }
    
    /**
     * Get database connection instance (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        
        return self::$instance;
    }
    
    /**
     * Create new database connection
     */
    private static function createConnection(): PDO
    {
        $config = self::$config;

        // Recover from accidental reset() calls where init() is not invoked again.
        if (
            !isset($config['host'], $config['name'], $config['user'], $config['pass']) ||
            $config['host'] === '' ||
            $config['name'] === ''
        ) {
            $configFile = __DIR__ . '/../config/database.php';
            if (file_exists($configFile)) {
                $loaded = require $configFile;
                if (is_array($loaded)) {
                    self::$config = $loaded;
                    $config = $loaded;
                }
            }
        }

        if (!isset($config['host'], $config['name'], $config['user'], $config['pass'])) {
            throw DatabaseConnectionException::configurationMissing($config);
        }
        
        $dsnBase = 'mysql:host=' . $config['host'] . ';dbname=' . $config['name'];
        $dsnWithCharset = $dsnBase;
        if (!empty($config['charset'])) {
            $dsnWithCharset .= ';charset=' . $config['charset'];
        }
        
        try {
            $pdo = new PDO(
                $dsnWithCharset,
                $config['user'],
                $config['pass'],
                $config['options'] ?? []
            );
            self::applySessionTimezone($pdo);
            return $pdo;
        } catch (PDOException $e) {
            // Retry when server doesn't support configured charset (error 2019)
            $isCharsetError = (strpos($e->getMessage(), '2019') !== false || stripos($e->getMessage(), 'Unknown character set') !== false);
            if ($isCharsetError) {
                // Try no charset first (works on older MySQL), then latin1, utf8
                $lastError = $e;
                foreach ([$dsnBase, $dsnBase . ';charset=latin1', $dsnBase . ';charset=utf8'] as $tryDsn) {
                    try {
                        $pdo = new PDO(
                            $tryDsn,
                            $config['user'],
                            $config['pass'],
                            $config['options'] ?? []
                        );
                        self::applySessionTimezone($pdo);
                        return $pdo;
                    } catch (PDOException $retryEx) {
                        $lastError = $retryEx;
                    }
                }
                throw DatabaseConnectionException::fromPdoException($lastError, $config);
            }
            throw DatabaseConnectionException::fromPdoException($e, $config);
        }
    }

    /**
     * Align the database session timezone with the PHP app timezone so
     * DATETIME values written via NOW() are comparable to PHP time().
     */
    private static function applySessionTimezone(PDO $pdo): void
    {
        try {
            $timezone = new \DateTimeZone(date_default_timezone_get());
            $offset = (new \DateTimeImmutable('now', $timezone))->format('P');
            $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        } catch (\Throwable $e) {
            // Keep the connection usable even if the host rejects session timezone changes.
        }
    }
    
    /**
     * Execute a query and return results
     */
    public static function query(string $sql, array $params = []): array
    {
        self::guardQueryVolume($sql, $params);
        $startTime = microtime(true);
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Track performance
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql, $executionTime);
            }
            
            return $result;
        } catch (PDOException $e) {
            // Track failed query
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql . ' [FAILED]', $executionTime);
            }
            throw $e;
        }
    }
    
    /**
     * Execute a query and return single row
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        self::guardQueryVolume($sql, $params);
        $startTime = microtime(true);
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Track performance
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql, $executionTime);
            }
            
            return $result ?: null;
        } catch (PDOException $e) {
            // Track failed query
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql . ' [FAILED]', $executionTime);
            }
            throw $e;
        }
    }
    
    /**
     * Execute an insert/update/delete query
     */
    public static function execute(string $sql, array $params = []): int
    {
        self::guardQueryVolume($sql, $params);
        $startTime = microtime(true);
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            $rowCount = $stmt->rowCount();
            
            // Track performance
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql, $executionTime);
            }
            
            return $rowCount;
        } catch (PDOException $e) {
            // Track failed query
            $executionTime = microtime(true) - $startTime;
            if (self::shouldTrackPerformance()) {
                \CRM\Modules\PerformanceMonitor::trackQuery($sql . ' [FAILED]', $executionTime);
            }
            throw $e;
        }
    }
    
    /**
     * Get last insert ID
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }
    
    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public static function rollBack(): bool
    {
        return self::getInstance()->rollBack();
    }

    public static function tableExists(string $table): bool
    {
        if (!self::isSafeIdentifier($table)) {
            return false;
        }

        $cacheKey = strtolower($table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        try {
            $pdo = self::getInstance();
            $pattern = self::escapeLikePattern($table);
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($pattern));
            self::$tableExistsCache[$cacheKey] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
        } catch (\Throwable $e) {
            self::$tableExistsCache[$cacheKey] = false;
        }

        return self::$tableExistsCache[$cacheKey];
    }

    public static function columnExists(string $table, string $column): bool
    {
        if (!self::isSafeIdentifier($table) || !self::isSafeIdentifier($column)) {
            return false;
        }

        $cacheKey = strtolower($table . '.' . $column);
        if (array_key_exists($cacheKey, self::$columnExistsCache)) {
            return self::$columnExistsCache[$cacheKey];
        }

        try {
            $pdo = self::getInstance();
            $sql = 'SHOW COLUMNS FROM ' . self::quoteIdentifier($table) . ' WHERE Field = ' . $pdo->quote($column);
            $stmt = $pdo->query($sql);
            self::$columnExistsCache[$cacheKey] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            self::$columnExistsCache[$cacheKey] = false;
        }

        return self::$columnExistsCache[$cacheKey];
    }
    
    /**
     * Close connection
     */
    public static function close(): void
    {
        self::$instance = null;
        self::$queryCount = 0;
    }
    
    /**
     * Reset connection (close and clear config)
     * Useful when tables are created after connection is established
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$config = [];
        self::$queryCount = 0;
        self::clearSchemaCache();
    }

    public static function clearSchemaCache(): void
    {
        self::$tableExistsCache = [];
        self::$columnExistsCache = [];
    }

    private static function guardQueryVolume(string $sql, array $params = []): void
    {
        self::$queryCount++;

        $limit = self::intEnv('DB_QUERY_GUARD_LIMIT');
        if ($limit <= 0 || self::$queryCount <= $limit) {
            return;
        }

        $sampleSql = strlen($sql) > 300 ? substr($sql, 0, 297) . '...' : $sql;
        throw new \RuntimeException(
            sprintf(
                'Database query guard tripped after %d queries. Last SQL: %s. Param count: %d',
                self::$queryCount,
                preg_replace('/\s+/', ' ', trim($sampleSql)) ?: $sampleSql,
                count($params)
            )
        );
    }

    private static function shouldTrackPerformance(): bool
    {
        if (!class_exists('\CRM\Modules\PerformanceMonitor')) {
            return false;
        }

        if (self::boolEnv('DISABLE_QUERY_MONITORING')) {
            return false;
        }

        return true;
    }

    private static function intEnv(string $key): int
    {
        $value = getenv($key);
        if ($value !== false) {
            return (int) $value;
        }

        return (int) ($_ENV[$key] ?? 0);
    }

    private static function boolEnv(string $key): bool
    {
        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }

        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private static function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function escapeLikePattern(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
