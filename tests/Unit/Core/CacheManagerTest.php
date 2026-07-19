<?php

namespace CRM\Tests\Unit\Core;

use CRM\CacheManager;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CACHE_DRIVER',
            'CACHE_FILE_FALLBACK',
            'MEMCACHED_HOST',
            'MEMCACHED_PORT',
        ] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }

        parent::tearDown();
    }

    public function testFileDriverCachesAndDeletesValues(): void
    {
        $_ENV['CACHE_DRIVER'] = 'file';
        $_ENV['CACHE_FILE_FALLBACK'] = 'true';

        $cache = new CacheManager();
        $key = 'phpunit:file-cache:' . uniqid('', true);

        try {
            $cache->set($key, ['ok' => true], 60);

            $this->assertSame(['ok' => true], $cache->get($key));
            $cache->delete($key);
            $this->assertNull($cache->get($key));
        } finally {
            $cache->delete($key);
        }
    }

    public function testFileDriverRoundTripsJsonStrings(): void
    {
        $_ENV['CACHE_DRIVER'] = 'file';
        $_ENV['CACHE_FILE_FALLBACK'] = 'true';

        $cache = new CacheManager();
        $key = 'phpunit:file-cache-json:' . uniqid('', true);
        $value = '{"why_this_matters":"cached"}';

        try {
            $cache->set($key, $value, 60);

            $this->assertSame($value, $cache->get($key));
        } finally {
            $cache->delete($key);
        }
    }

    public function testMemcachedDriverFallsBackToFileWhenBackendIsUnavailable(): void
    {
        $_ENV['CACHE_DRIVER'] = 'memcached';
        $_ENV['CACHE_FILE_FALLBACK'] = 'true';
        $_ENV['MEMCACHED_HOST'] = '127.0.0.1';
        $_ENV['MEMCACHED_PORT'] = '1';

        $cache = new CacheManager();
        $key = 'phpunit:memcached-fallback:' . uniqid('', true);

        try {
            $cache->set($key, ['fallback' => true], 60);

            $this->assertSame(['fallback' => true], $cache->get($key));
        } finally {
            $cache->delete($key);
        }
    }
}
