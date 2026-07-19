<?php
/**
 * Cache Manager
 * Redis, Memcached, and file caching layer
 */

namespace CRM;

class CacheManager
{
    private ?\Redis $redis = null;
    /**
     * @var \Memcached|null
     */
    private $memcached = null;
    private ?string $fileCacheDirectory = null;
    private string $driver = 'file';
    private array $cacheConfig = [
        'dashboard_metrics' => 300,
        'contact_details' => 1800,
        'user_sessions' => 3600,
        'ai_responses' => 86400,
    ];
    
    public function __construct()
    {
        $requestedDriver = $this->resolveCacheDriver();
        $this->fileCacheDirectory = $this->resolveFileCacheDirectory();

        if (($requestedDriver === 'auto' || $requestedDriver === 'redis') && $this->connectRedis()) {
            $this->driver = 'redis';
            return;
        }

        if (($requestedDriver === 'auto' || $requestedDriver === 'memcached') && $this->connectMemcached()) {
            $this->driver = 'memcached';
            return;
        }

        $this->driver = 'file';
    }
    
    /**
     * Get with cache
     */
    public function getWithCache(string $key, callable $callback, ?int $ttl = null)
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $data = $callback();
        
        $ttl = $ttl ?? $this->cacheConfig[$key] ?? 300;
        
        $this->set($key, $data, $ttl);
        
        return $data;
    }
    
    /**
     * Get from cache
     */
    public function get(string $key)
    {
        if ($this->driver === 'redis' && $this->redis) {
            try {
                $cached = $this->redis->get($key);
                return $cached !== false ? $this->decodeValue($cached) : null;
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }

        if ($this->driver === 'memcached' && $this->memcached) {
            try {
                $cached = $this->memcached->get($key);
                if ($cached !== false) {
                    return $this->decodeValue((string) $cached);
                }

                if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
                    return null;
                }

                $this->memcached = null;
            } catch (\Throwable $e) {
                $this->memcached = null;
            }
        }

        return $this->getFromFileCache($key);
    }
    
    /**
     * Set cache
     */
    public function set(string $key, $value, int $ttl = 300): void
    {
        $encoded = $this->encodeValue($value);
        if ($encoded === null || $ttl <= 0) {
            return;
        }

        if ($this->driver === 'redis' && $this->redis) {
            try {
                if ($this->redis->setex($key, $ttl, $encoded) !== false) {
                    return;
                }

                $this->redis = null;
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }

        if ($this->driver === 'memcached' && $this->memcached) {
            try {
                if ($this->memcached->set($key, $encoded, $ttl)) {
                    return;
                }

                $this->memcached = null;
            } catch (\Throwable $e) {
                $this->memcached = null;
            }
        }

        $this->setFileCache($key, $value, $ttl);
    }
    
    /**
     * Delete from cache
     */
    public function delete(string $key): void
    {
        if ($this->driver === 'redis' && $this->redis) {
            try {
                $this->redis->del($key);
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }

        if ($this->driver === 'memcached' && $this->memcached) {
            try {
                $this->memcached->delete($key);
            } catch (\Throwable $e) {
                $this->memcached = null;
            }
        }

        $this->deleteFileCache($key);
    }

    private function resolveCacheDriver(): string
    {
        $driver = strtolower(trim((string) ($_ENV['CACHE_DRIVER'] ?? 'auto')));
        if (!in_array($driver, ['auto', 'redis', 'memcached', 'file'], true)) {
            return 'auto';
        }

        return $driver;
    }

    private function connectRedis(): bool
    {
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            return false;
        }

        try {
            $redis = new \Redis();
            $host = $_ENV['REDIS_HOST'] ?? 'localhost';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);

            if ($redis->connect($host, $port) === false) {
                return false;
            }

            $this->redis = $redis;
            return true;
        } catch (\Throwable $e) {
            $this->redis = null;
            return false;
        }
    }

    private function connectMemcached(): bool
    {
        if (!extension_loaded('memcached') || !class_exists(\Memcached::class)) {
            return false;
        }

        try {
            $memcached = new \Memcached();
            $host = $_ENV['MEMCACHED_HOST'] ?? '127.0.0.1';
            $port = (int) ($_ENV['MEMCACHED_PORT'] ?? 11211);
            if ($port <= 0) {
                $port = 11211;
            }

            if (!$memcached->addServer($host, $port)) {
                return false;
            }

            $this->memcached = $memcached;
            return true;
        } catch (\Throwable $e) {
            $this->memcached = null;
            return false;
        }
    }

    private function encodeValue($value): ?string
    {
        $encoded = json_encode($value);
        return is_string($encoded) ? $encoded : null;
    }

    private function decodeValue(string $payload)
    {
        return json_decode($payload, true);
    }

    private function deleteFileCache(string $key): void
    {
        $path = $this->filePathForKey($key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function resolveFileCacheDirectory(): ?string
    {
        if (isset($_ENV['CACHE_FILE_FALLBACK']) && !filter_var($_ENV['CACHE_FILE_FALLBACK'], FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $directory = dirname(__DIR__) . '/cache/app';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return is_dir($directory) && is_writable($directory) ? $directory : null;
    }

    private function getFromFileCache(string $key)
    {
        $path = $this->filePathForKey($key);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) <= time()) {
            @unlink($path);
            return null;
        }

        return $payload['value'] ?? null;
    }

    private function setFileCache(string $key, $value, int $ttl): void
    {
        $path = $this->filePathForKey($key);
        if ($path === null || $ttl <= 0) {
            return;
        }

        $payload = json_encode([
            'expires_at' => time() + $ttl,
            'value' => $value,
        ]);
        if (!is_string($payload)) {
            return;
        }

        @file_put_contents($path, $payload, LOCK_EX);
    }

    private function filePathForKey(string $key): ?string
    {
        if ($this->fileCacheDirectory === null) {
            return null;
        }

        return $this->fileCacheDirectory . '/' . hash('sha256', $key) . '.json';
    }
}
