<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\SMSWebhookAuthenticationException;
use CRM\Services\SMSWebhookService;
use CRM\Services\TwilioWebhookSignatureValidator;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Tests\DatabaseTestCase;

class SMSWebhookServiceTest extends DatabaseTestCase
{
    private const URL = 'https://crm.example.test/api/webhooks/sms.php';
    private const TOKEN = 'workspace-webhook-token';
    private ?string $originalAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'sms-webhook-test-key';
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

    public function testSignedInboundMessageWritesToConfiguredWorkspaceAndIsIdempotent(): void
    {
        $workspaceId = $this->createWorkspace('SMS Workspace', 'sms-workspace');
        $this->configureWorkspace($workspaceId, '+14155550199', true, true);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Inbound', 'SMS', 'inbound-sms@example.com', '254711223344', NOW())",
            [$workspaceId, uuid_v4()]
        );
        $contactId = (int) Database::lastInsertId();

        $payload = [
            'MessageSid' => 'SM_workspace_unique_1',
            'From' => '+254711223344',
            'To' => '+14155550199',
            'Body' => 'Hello from workspace two',
        ];
        $signature = (new TwilioWebhookSignatureValidator())->sign(self::URL, $payload, self::TOKEN);
        $service = new SMSWebhookService();
        $first = $service->handle($payload, self::URL, $signature);
        $second = $service->handle($payload, self::URL, $signature);

        $messageCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM sms_messages WHERE workspace_id = ? AND provider_message_id = ?",
            [$workspaceId, 'SM_workspace_unique_1']
        )['c'] ?? 0);
        $communication = Database::queryOne(
            "SELECT workspace_id, contact_id, body FROM communications
             WHERE workspace_id = ? AND channel = 'sms' AND contact_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId, $contactId]
        );

        $this->assertTrue((bool) ($first['handled'] ?? false));
        $this->assertTrue((bool) ($second['handled'] ?? false));
        $this->assertSame(1, $messageCount);
        $this->assertSame('Hello from workspace two', (string) ($communication['body'] ?? ''));
    }

    public function testUnsignedInboundMessageIsRejectedWithoutSideEffects(): void
    {
        $workspaceId = $this->createWorkspace('Unsigned SMS', 'unsigned-sms');
        $this->configureWorkspace($workspaceId, '+14155550200', true, false);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Forged', 'Sender', 'forged@example.com', '+254700000111', NOW())",
            [$workspaceId, uuid_v4()]
        );
        $payload = [
            'MessageSid' => 'SM_forged_webhook',
            'From' => '+254700000111',
            'To' => '+14155550200',
            'Body' => 'Trigger automation',
        ];

        try {
            (new SMSWebhookService())->handle($payload, self::URL, '');
            $this->fail('Unsigned webhook should be rejected.');
        } catch (SMSWebhookAuthenticationException $e) {
            $this->assertStringContainsString('signature', strtolower($e->getMessage()));
        }

        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM sms_messages WHERE provider_message_id = 'SM_forged_webhook'"
        )['c'] ?? 0));
    }

    public function testSignedStatusCallbackRequiresEnabledFlagAndUpdatesOwnedMessage(): void
    {
        $workspaceId = $this->createWorkspace('Status SMS', 'status-sms');
        $this->configureWorkspace($workspaceId, '+14155550201', true, true);
        Database::execute(
            "INSERT INTO sms_messages
                (workspace_id, uuid, to_number, from_number, message_body, status, direction, provider, provider_message_id)
             VALUES (?, ?, '+254700100200', '+14155550201', 'Status test', 'sent', 'outbound', 'twilio', 'SM_status_1')",
            [$workspaceId, uuid_v4()]
        );
        $payload = ['MessageSid' => 'SM_status_1', 'MessageStatus' => 'delivered'];
        $signature = (new TwilioWebhookSignatureValidator())->sign(self::URL, $payload, self::TOKEN);

        $result = (new SMSWebhookService())->handle($payload, self::URL, $signature);
        $row = Database::queryOne(
            "SELECT status FROM sms_messages WHERE workspace_id = ? AND provider_message_id = 'SM_status_1'",
            [$workspaceId]
        );

        $this->assertSame('status_callback', (string) ($result['type'] ?? ''));
        $this->assertSame('delivered', (string) ($row['status'] ?? ''));
    }

    public function testInboundStopPersistsSuppressionAndDoesNotEnqueueAutoReply(): void
    {
        $workspaceId = $this->createWorkspace('Opt Out SMS', 'optout-sms');
        $this->configureWorkspace($workspaceId, '+14155550202', true, false);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Opt', 'Out', 'optout@example.com', '+254733444555', NOW())",
            [$workspaceId, uuid_v4()]
        );
        $payload = ['MessageSid' => 'SM_stop_1', 'From' => '+254733444555', 'To' => '+14155550202', 'Body' => 'STOP'];
        $signature = (new TwilioWebhookSignatureValidator())->sign(self::URL, $payload, self::TOKEN);

        (new SMSWebhookService())->handle($payload, self::URL, $signature);

        $suppression = Database::queryOne(
            "SELECT status, source FROM marketing_suppression_entries
             WHERE workspace_id = ? AND channel = 'sms' AND identifier = '+254733444555'",
            [$workspaceId]
        );
        $this->assertSame('active', (string) ($suppression['status'] ?? ''));
        $this->assertSame('sms_webhook', (string) ($suppression['source'] ?? ''));
    }

    private function configureWorkspace(int $workspaceId, string $fromNumber, bool $webhookEnabled, bool $callbacksEnabled): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_at)
             VALUES (?, ?, 'installed', '{}', NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL",
            [$workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );
        (new WorkspaceSmsChannelConfigService())->save($workspaceId, [
            'enabled' => true,
            'account_sid' => 'ACworkspace',
            'auth_token' => self::TOKEN,
            'from_number' => $fromNumber,
            'webhook_enabled' => $webhookEnabled,
            'status_callbacks_enabled' => $callbacksEnabled,
        ]);
    }

    private function createWorkspace(string $name, string $slug): int
    {
        $suffix = substr(bin2hex(random_bytes(6)), 0, 12);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [uuid_v4(), $name, $slug . '-' . $suffix]
        );
        return (int) Database::lastInsertId();
    }
}
