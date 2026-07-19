<?php
/**
 * SMS Service Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\SMSService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Database;

class SMSServiceTest extends DatabaseTestCase
{
    private SMSService $smsService;
    private int $testUserId;
    private int $testContactId;
    private ?string $originalAppKey = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'sms-service-test-key';
        
        $this->smsService = new SMSService();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at) 
             VALUES (1, ?, ?, ?, ?, ?, NOW())",
            [uuid_v4(), 'John', 'Doe', 'john@example.com', '+1234567890']
        );
        $this->testContactId = (int) Database::lastInsertId();
        
        $_SESSION['user_id'] = $this->testUserId;

        (new WorkspaceSmsChannelConfigService())->save(1, [
            'enabled' => true,
            'account_sid' => 'test_sid',
            'auth_token' => 'test_token',
            'from_number' => '+1234567890',
        ]);
        $this->seedSmsInstall(1);
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->originalAppKey;
        }
        parent::tearDown();
    }
    
    public function testStoreMessage()
    {
        $uuid = $this->smsService->storeMessage(
            $this->testContactId,
            '+1234567890',
            'Test message'
        );
        
        $this->assertNotEmpty($uuid);
        
        $message = Database::queryOne(
            "SELECT * FROM sms_messages WHERE uuid = ?",
            [$uuid]
        );
        
        $this->assertNotNull($message);
        $this->assertEquals('Test message', $message['message_body']);
        $this->assertEquals('pending', $message['status']);
        $this->assertEquals('+1234567890', $message['from_number']);
    }

    public function testStoreMessageUsesWorkspaceSpecificSender(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'SMS Runtime Workspace', 'sms-runtime-workspace', 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active'",
            ['00000000-0000-4000-8000-000000000002']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (2, ?, ?, ?, ?, ?, NOW())",
            [uuid_v4(), 'Jane', 'Doe', 'jane@example.com', '+254700111222']
        );
        $secondContactId = (int) Database::lastInsertId();
        (new WorkspaceSmsChannelConfigService())->save(2, [
            'enabled' => true,
            'account_sid' => 'workspace_two_sid',
            'auth_token' => 'workspace_two_token',
            'from_number' => '+254700000001',
        ]);
        $this->seedSmsInstall(2);

        $firstUuid = $this->smsService->storeMessage($this->testContactId, '+1234567890', 'First workspace message', ['workspace_id' => 1]);
        $secondUuid = $this->smsService->storeMessage($secondContactId, '+254700111222', 'Second workspace message', ['workspace_id' => 2]);
        $first = Database::queryOne("SELECT workspace_id, from_number FROM sms_messages WHERE uuid = ?", [$firstUuid]);
        $second = Database::queryOne("SELECT workspace_id, from_number FROM sms_messages WHERE uuid = ?", [$secondUuid]);

        $this->assertSame(1, (int) ($first['workspace_id'] ?? 0));
        $this->assertSame('+1234567890', (string) ($first['from_number'] ?? ''));
        $this->assertSame(2, (int) ($second['workspace_id'] ?? 0));
        $this->assertSame('+254700000001', (string) ($second['from_number'] ?? ''));
    }
    
    public function testFormatPhoneNumber()
    {
        $reflection = new \ReflectionClass($this->smsService);
        $method = $reflection->getMethod('formatPhoneNumber');
        $method->setAccessible(true);
        $formatted = $method->invoke($this->smsService, '1234567890');
        
        $this->assertNotEmpty($formatted);
    }

    public function testStoreMessageRejectsCrossWorkspaceContactAndHonorsIdempotency(): void
    {
        $first = $this->smsService->storeMessage($this->testContactId, '+1234567890', 'Once', [
            'workspace_id' => 1,
            'idempotency_key' => 'sms-test-idempotency-key',
        ]);
        $second = $this->smsService->storeMessage($this->testContactId, '+1234567890', 'Once again', [
            'workspace_id' => 1,
            'idempotency_key' => 'sms-test-idempotency-key',
        ]);
        $this->assertSame($first, $second);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM sms_messages WHERE workspace_id = 1 AND idempotency_key IS NOT NULL"
        )['c'] ?? 0));

        $this->expectException(\RuntimeException::class);
        $this->smsService->storeMessage($this->testContactId, '+1234567890', 'Wrong workspace', ['workspace_id' => 2]);
    }

    public function testStoreMessageFailsAfterPluginUninstall(): void
    {
        Database::execute(
            "UPDATE workspace_skill_installs SET status = 'uninstalled', uninstalled_at = NOW()
             WHERE workspace_id = 1 AND skill_key = ?",
            [WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not installed');
        (new SMSService())->storeMessage($this->testContactId, '+1234567890', 'Should not queue', ['workspace_id' => 1]);
    }

    public function testSuppressedRecipientCannotBeQueued(): void
    {
        Database::execute(
            "INSERT INTO marketing_suppression_entries
                (workspace_id, uuid, channel, identifier, identifier_hash, reason, status, source)
             VALUES (1, ?, 'sms', '+11234567890', ?, 'Test opt out', 'active', 'test')",
            [uuid_v4(), hash('sha256', 'sms:+11234567890')]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('suppressed');
        $this->smsService->storeMessage($this->testContactId, '+1234567890', 'Blocked', ['workspace_id' => 1]);
    }

    private function seedSmsInstall(int $workspaceId): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_at)
             VALUES (?, ?, 'installed', '{}', NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL",
            [$workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );
    }
}
