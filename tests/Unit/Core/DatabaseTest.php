<?php
/**
 * Database Tests
 */

namespace CRM\Tests\Unit\Core;

use CRM\Tests\DatabaseTestCase;
use CRM\Database;

class DatabaseTest extends DatabaseTestCase
{
    public function testDatabaseConnection(): void
    {
        $pdo = Database::getInstance();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }
    
    public function testQueryExecution(): void
    {
        $result = Database::query("SELECT 1 as test");
        $this->assertIsArray($result);
        $this->assertEquals(1, $result[0]['test']);
    }
    
    public function testQueryOne(): void
    {
        $result = Database::queryOne("SELECT 42 as answer");
        $this->assertIsArray($result);
        $this->assertEquals(42, $result['answer']);
    }
    
    public function testQueryOneReturnsNullWhenNoResults(): void
    {
        $result = Database::queryOne("SELECT * FROM users WHERE id = ?", [99999]);
        $this->assertNull($result);
    }
    
    public function testExecuteInsert(): void
    {
        $uuid = 'test-uuid-' . uniqid();
        $rows = Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, ?)",
            [$uuid, 'test@example.com', password_hash('password', PASSWORD_DEFAULT), 'viewer']
        );
        
        $this->assertEquals(1, $rows);
        
        // Clean up
        Database::execute("DELETE FROM users WHERE uuid = ?", [$uuid]);
    }
    
    public function testLastInsertId(): void
    {
        $uuid = 'test-uuid-' . uniqid();
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, ?)",
            [$uuid, 'test2@example.com', password_hash('password', PASSWORD_DEFAULT), 'viewer']
        );
        
        $id = Database::lastInsertId();
        $this->assertIsNumeric($id);
        $this->assertGreaterThan(0, $id);
        
        // Clean up
        Database::execute("DELETE FROM users WHERE id = ?", [$id]);
    }
    
    public function testTransaction(): void
    {
        Database::beginTransaction();
        
        $uuid = 'test-uuid-' . uniqid();
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, ?)",
            [$uuid, 'test3@example.com', password_hash('password', PASSWORD_DEFAULT), 'viewer']
        );
        
        Database::rollBack();
        
        $user = Database::queryOne("SELECT * FROM users WHERE uuid = ?", [$uuid]);
        $this->assertNull($user);
    }
}
