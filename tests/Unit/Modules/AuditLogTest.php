<?php
/**
 * Audit Log Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\AuditLog;
use CRM\Database;
use CRM\Auth;

class AuditLogTest extends DatabaseTestCase
{
    private AuditLog $auditLog;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->auditLog = new AuditLog();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Set session for Auth
        $_SESSION['user_id'] = $this->testUserId;
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM audit_log WHERE user_id = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        unset($_SESSION['user_id']);
        parent::tearDown();
    }
    
    public function testLogAction(): void
    {
        $logId = $this->auditLog->log(
            'contact_created',
            'contact',
            1,
            null,
            ['first_name' => 'John', 'email' => 'john@example.com']
        );
        
        $this->assertIsInt($logId);
        $this->assertGreaterThan(0, $logId);
    }
    
    public function testLogActionWithOldAndNewValues(): void
    {
        $logId = $this->auditLog->log(
            'contact_updated',
            'contact',
            1,
            ['first_name' => 'John'],
            ['first_name' => 'Jane']
        );
        
        $this->assertIsInt($logId);
        
        // Verify log entry
        $logs = $this->auditLog->getLogs(['entity_type' => 'contact', 'entity_id' => 1]);
        $this->assertGreaterThan(0, count($logs));
    }
    
    public function testGetLogs(): void
    {
        // Create some log entries
        $this->auditLog->log('contact_created', 'contact', 1);
        $this->auditLog->log('contact_updated', 'contact', 2);
        $this->auditLog->log('task_created', 'task', 1);
        
        $logs = $this->auditLog->getLogs();
        
        $this->assertIsArray($logs);
        $this->assertGreaterThanOrEqual(3, count($logs));
    }
    
    public function testGetLogsWithFilters(): void
    {
        // Create log entries
        $this->auditLog->log('contact_created', 'contact', 1);
        $this->auditLog->log('contact_created', 'contact', 2);
        $this->auditLog->log('task_created', 'task', 1);
        
        // Filter by entity type
        $contactLogs = $this->auditLog->getLogs(['entity_type' => 'contact']);
        $this->assertGreaterThanOrEqual(2, count($contactLogs));
        
        // Filter by action
        $createdLogs = $this->auditLog->getLogs(['action' => 'contact_created']);
        $this->assertGreaterThanOrEqual(2, count($createdLogs));
    }
    
    public function testGetLogsWithPagination(): void
    {
        // Create multiple log entries
        for ($i = 0; $i < 15; $i++) {
            $this->auditLog->log('test_action', 'test', $i);
        }
        
        // Get first page
        $page1 = $this->auditLog->getLogs([], 10, 0);
        $this->assertCount(10, $page1);
        
        // Get second page
        $page2 = $this->auditLog->getLogs([], 10, 10);
        $this->assertGreaterThanOrEqual(5, count($page2));
    }
    
    public function testGetCount(): void
    {
        // Create log entries
        $this->auditLog->log('contact_created', 'contact', 1);
        $this->auditLog->log('contact_created', 'contact', 2);
        $this->auditLog->log('task_created', 'task', 1);
        
        $total = $this->auditLog->getCount();
        $this->assertGreaterThanOrEqual(3, $total);
        
        // Count with filter
        $contactCount = $this->auditLog->getCount(['entity_type' => 'contact']);
        $this->assertGreaterThanOrEqual(2, $contactCount);
    }
    
    public function testGetByEntity(): void
    {
        // Create log entries for an entity
        $this->auditLog->log('contact_created', 'contact', 100);
        $this->auditLog->log('contact_updated', 'contact', 100);
        $this->auditLog->log('contact_viewed', 'contact', 100);
        
        $entityLogs = $this->auditLog->getByEntity('contact', 100);
        
        $this->assertIsArray($entityLogs);
        $this->assertGreaterThanOrEqual(3, count($entityLogs));
        
        // Verify all are for the same entity
        foreach ($entityLogs as $log) {
            $this->assertEquals('contact', $log['entity_type']);
            $this->assertEquals(100, $log['entity_id']);
        }
    }
    
    public function testGetByUser(): void
    {
        // Create log entries
        $this->auditLog->log('action1', 'entity', 1);
        $this->auditLog->log('action2', 'entity', 2);
        
        $userLogs = $this->auditLog->getByUser($this->testUserId);
        
        $this->assertIsArray($userLogs);
        $this->assertGreaterThanOrEqual(2, count($userLogs));
        
        // Verify all are from the same user
        foreach ($userLogs as $log) {
            $this->assertEquals($this->testUserId, $log['user_id']);
        }
    }
}
