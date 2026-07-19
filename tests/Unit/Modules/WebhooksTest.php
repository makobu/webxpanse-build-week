<?php
/**
 * Webhooks Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Webhooks;
use CRM\Database;
use CRM\Auth;
use CRM\Services\WorkspaceContext;

class WebhooksTest extends DatabaseTestCase
{
    private Webhooks $webhooks;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->webhooks = new Webhooks();
        
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
    
    public function testCreateWebhook()
    {
        $id = $this->webhooks->create([
            'name' => 'Test Webhook',
            'url' => 'https://example.com/webhook',
            'method' => 'POST',
            'events' => ['contact.created'],
            'is_active' => true
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $webhook = Database::queryOne("SELECT * FROM webhooks WHERE id = ?", [$id]);
        $this->assertEquals('Test Webhook', $webhook['name']);
        $this->assertEquals('https://example.com/webhook', $webhook['url']);
    }
    
    public function testCreateWebhookRequiresNameAndUrl()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Name and URL are required");
        
        $this->webhooks->create([]);
    }
    
    public function testCreateWebhookValidatesUrl()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Invalid URL format");
        
        $this->webhooks->create([
            'name' => 'Test',
            'url' => 'invalid-url'
        ]);
    }

    public function testCreateWebhookRestrictsUrlScheme()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Webhook URL must use http or https");

        $this->webhooks->create([
            'name' => 'FTP Webhook',
            'url' => 'ftp://example.com/webhook',
            'events' => ['contact.created'],
            'is_active' => true
        ]);
    }

    public function testUpdatePreservesAndClearsSecretIntentionally()
    {
        $id = $this->webhooks->create([
            'name' => 'Secret Webhook',
            'url' => 'https://example.com/webhook',
            'secret' => 'old-secret',
            'events' => ['contact.created'],
            'is_active' => true
        ]);

        $this->webhooks->update($id, [
            'name' => 'Renamed Secret Webhook',
            'url' => 'https://example.com/webhook-updated',
        ]);
        $preserved = Database::queryOne("SELECT secret FROM webhooks WHERE id = ?", [$id]);
        $this->assertSame('old-secret', (string) ($preserved['secret'] ?? ''));

        $this->webhooks->update($id, ['secret' => null]);
        $cleared = Database::queryOne("SELECT secret FROM webhooks WHERE id = ?", [$id]);
        $this->assertNull($cleared['secret']);
    }

    public function testWebhooksAreScopedToActiveWorkspace()
    {
        Database::execute(
            "INSERT INTO workspaces (name, slug, status, plan_status, created_at, updated_at)
             VALUES ('Other Workspace', 'webhook-other', 'active', 'trial', NOW(), NOW())"
        );
        $otherWorkspaceId = (int) Database::lastInsertId();

        $id = $this->webhooks->create([
            'name' => 'Workspace Webhook',
            'url' => 'https://example.com/webhook',
            'events' => ['contact.created'],
            'is_active' => true
        ]);
        $row = Database::queryOne("SELECT workspace_id FROM webhooks WHERE id = ?", [$id]);
        $this->assertEquals(1, (int) $row['workspace_id']);

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId);
        $this->assertNull($this->webhooks->getById($id));
        $this->assertSame([], $this->webhooks->getLogs($id));

        WorkspaceContext::activateRuntimeWorkspace(1);
    }
}
