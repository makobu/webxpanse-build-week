<?php
/**
 * Base Test Case
 */

namespace CRM\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use CRM\Database;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize database for tests
        $dbConfig = [
            'host' => getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost'),
            'name' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : ($_ENV['DB_NAME'] ?? 'crm_test'),
            'user' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root'),
            'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ''),
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => true,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        ];
        
        Database::init($dbConfig);
    }
    
    protected function tearDown(): void
    {
        Database::close();
        parent::tearDown();
    }
}
