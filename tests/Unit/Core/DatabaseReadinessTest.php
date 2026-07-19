<?php

namespace CRM\Tests\Unit\Core;

use CRM\Database;
use CRM\DatabaseConnectionException;
use CRM\Tests\DatabaseTestCase;
use PDOException;

class DatabaseReadinessTest extends DatabaseTestCase
{
    public function testMissingDatabaseThrowsTypedConnectionException(): void
    {
        $config = $this->currentTestDatabaseConfig();
        $config['name'] = 'crm_missing_' . substr(hash('sha256', __METHOD__), 0, 12);

        Database::close();
        Database::init([
            'host' => $config['host'],
            'name' => $config['name'],
            'user' => $config['user'],
            'pass' => $config['pass'],
            'charset' => $config['charset'],
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ],
        ]);

        try {
            Database::getInstance();
            $this->fail('Expected a typed database connection exception.');
        } catch (DatabaseConnectionException $e) {
            $this->assertSame('missing_database', $e->getCategory());
            $this->assertSame('The configured database does not exist.', $e->getUserMessage());
            $this->assertStringContainsString($config['name'], $e->getMessage());
            $this->assertArrayHasKey('password_configured', $e->getSafeDetails());
            $this->assertArrayNotHasKey('pass', $e->getSafeDetails());
        } finally {
            Database::close();
            Database::init($this->currentTestDatabaseConfig());
        }
    }

    public function testPdoErrorMessageIsSanitized(): void
    {
        $exception = DatabaseConnectionException::fromPdoException(
            new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root' with password phase13-secret"),
            [
                'host' => 'localhost',
                'name' => 'crm_db',
                'user' => 'root',
                'pass' => 'phase13-secret',
                'charset' => 'utf8mb4',
            ]
        );

        $this->assertSame('access_denied', $exception->getCategory());
        $this->assertStringNotContainsString('phase13-secret', $exception->getMessage());
        $this->assertStringNotContainsString('phase13-secret', json_encode($exception->getSafeDetails()) ?: '');
        $this->assertSame('The database rejected the configured username or password.', $exception->getUserMessage());
    }
}
