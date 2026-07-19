<?php
/**
 * Deals Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Deals;
use CRM\Database;
use CRM\Services\WorkspaceMembershipService;

class DealsTest extends DatabaseTestCase
{
    private Deals $deals;
    private int $testUserId;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->deals = new Deals();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('user_', true), 'test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->testUserId, 'member', false, $this->testUserId);
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at) 
             VALUES (1, ?, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'John', 'Doe', 'john@example.com']
        );
        $this->testContactId = (int) Database::lastInsertId();
    }
    
    public function testCreateDeal()
    {
        $id = $this->deals->create([
            'title' => 'Test Deal',
            'contact_id' => $this->testContactId,
            'value' => 5000.00,
            'currency' => 'USD',
            'stage' => 'prospecting',
            'probability' => 25,
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $deal = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$id]);
        $this->assertEquals('Test Deal', $deal['title']);
        $this->assertEquals(5000.00, (float) $deal['value']);
        $this->assertEquals('USD', $deal['currency']);
    }
    
    public function testCreateDealRequiresTitle()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Deal title is required");
        
        $this->deals->create([]);
    }

    public function testCreateDealRejectsBlankTitleAfterSanitizing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Deal title is required");

        $this->deals->create([
            'title' => '   ',
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateDealRejectsInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid deal stage');

        $this->deals->create([
            'title' => 'Bad Stage Deal',
            'stage' => 'not_a_stage',
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateDealRejectsNegativeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deal value cannot be negative');

        $this->deals->create([
            'title' => 'Negative Deal',
            'value' => -1,
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateDealRejectsAssigneeOutsideActiveWorkspace(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('user_', true), 'foreign-deal-assignee@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $foreignUserId = (int) Database::lastInsertId();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a member of the active workspace');
        $this->deals->create([
            'title' => 'Forged assignment',
            'assigned_to' => $foreignUserId,
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateDealRejectsInvalidCloseDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected close date must be a valid date');

        $this->deals->create([
            'title' => 'Bad Date Deal',
            'expected_close_date' => '2026-02-31',
            'created_by' => $this->testUserId,
        ]);
    }
    
    public function testGetDealById()
    {
        $id = $this->deals->create([
            'title' => 'Test Deal',
            'created_by' => $this->testUserId
        ]);
        
        $deal = $this->deals->getById($id);
        
        $this->assertIsArray($deal);
        $this->assertEquals('Test Deal', $deal['title']);
    }
    
    public function testUpdateDeal()
    {
        $id = $this->deals->create([
            'title' => 'Original Deal',
            'value' => 1000,
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->deals->update($id, [
            'title' => 'Updated Deal',
            'value' => 2000,
            'stage' => 'negotiation'
        ]);
        
        $this->assertTrue($result);
        
        $deal = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$id]);
        $this->assertEquals('Updated Deal', $deal['title']);
        $this->assertEquals(2000, (float) $deal['value']);
        $this->assertEquals('negotiation', $deal['stage']);
    }

    public function testUpdateDealRejectsBlankTitleAfterSanitizing(): void
    {
        $id = $this->deals->create([
            'title' => 'Original Deal',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Deal title is required");

        $this->deals->update($id, ['title' => '   ']);
    }

    public function testUpdateDealRejectsOutOfRangeProbability(): void
    {
        $id = $this->deals->create([
            'title' => 'Original Deal',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deal probability must be between 0 and 100');

        $this->deals->update($id, ['probability' => 150]);
    }

    public function testUpdateDealDoesNotCauseInstrumentationMemoryRegression(): void
    {
        $id = $this->deals->create([
            'title' => 'Regression Deal',
            'value' => 1500,
            'stage' => 'prospecting',
            'created_by' => $this->testUserId
        ]);

        $beforeMemory = memory_get_usage(true);
        $result = $this->deals->update($id, [
            'title' => 'Regression Deal Updated',
            'value' => 1750,
            'stage' => 'proposal'
        ]);
        $afterMemory = memory_get_usage(true);

        $this->assertTrue($result);
        $this->assertLessThan(16 * 1024 * 1024, max(0, $afterMemory - $beforeMemory));
    }
    
    public function testGetPipelineStats()
    {
        // Create deals in different stages
        $this->deals->create([
            'title' => 'Deal 1',
            'value' => 1000,
            'stage' => 'prospecting',
            'created_by' => $this->testUserId
        ]);
        
        $this->deals->create([
            'title' => 'Deal 2',
            'value' => 2000,
            'stage' => 'closed_won',
            'created_by' => $this->testUserId
        ]);
        
        $stats = $this->deals->getPipelineStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_pipeline_value', $stats);
        $this->assertArrayHasKey('won', $stats);
        $this->assertArrayHasKey('by_stage', $stats);
    }

    public function testGetByIdReturnsNullForAnotherWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "INSERT INTO deals (workspace_id, title, stage, created_by, created_at)
             VALUES (2, 'Foreign Deal', 'prospecting', ?, NOW())",
            [$this->testUserId]
        );
        $foreignDealId = (int) Database::lastInsertId();

        $this->assertNull($this->deals->getById($foreignDealId));
    }
}
