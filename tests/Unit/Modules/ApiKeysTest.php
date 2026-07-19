<?php
/**
 * API Keys Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\ApiKeys;
use CRM\Database;
use CRM\Services\WorkspaceContext;

class ApiKeysTest extends DatabaseTestCase
{
    private ApiKeys $apiKeys;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKeys = new ApiKeys();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Mock session
        $_SESSION['user_id'] = $this->testUserId;
    }
    
    public function testCreateApiKey()
    {
        $result = $this->apiKeys->create([
            'name' => 'Test API Key',
            'permissions' => ['read', 'write']
        ]);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('api_key', $result);
        $this->assertArrayHasKey('key_prefix', $result);
        $this->assertNotEmpty($result['api_key']);
    }
    
    public function testCreateApiKeyRequiresName()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("API key name is required");
        
        $this->apiKeys->create([]);
    }
    
    public function testGetApiKeyById()
    {
        $created = $this->apiKeys->create([
            'name' => 'Test Key'
        ]);
        
        $key = $this->apiKeys->getById($created['id']);
        
        $this->assertIsArray($key);
        $this->assertEquals('Test Key', $key['name']);
        $this->assertEquals($created['key_prefix'], $key['key_prefix']);
    }

    public function testApiKeysAreScopedToActiveWorkspace()
    {
        Database::execute(
            "INSERT INTO workspaces (name, slug, status, plan_status, created_at, updated_at)
             VALUES ('Other Workspace', 'api-key-other', 'active', 'trial', NOW(), NOW())"
        );
        $otherWorkspaceId = (int) Database::lastInsertId();

        $created = $this->apiKeys->create(['name' => 'Workspace Key']);
        $row = Database::queryOne("SELECT workspace_id FROM api_keys WHERE id = ?", [$created['id']]);
        $this->assertEquals(1, (int) $row['workspace_id']);

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId);
        $this->assertNull($this->apiKeys->getById($created['id']));
        $this->assertSame([], $this->apiKeys->getUsageLogs($created['id']));

        WorkspaceContext::activateRuntimeWorkspace(1);
    }
}
