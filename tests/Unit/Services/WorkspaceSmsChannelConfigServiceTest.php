<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceSmsChannelConfigServiceTest extends DatabaseTestCase
{
    private ?string $originalAppKey = null;
    /** @var array<string,string|null> */
    private array $originalTwilioEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-sms-config-test-key';
        foreach (['TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM_NUMBER'] as $key) {
            $value = getenv($key);
            $this->originalTwilioEnv[$key] = array_key_exists($key, $_ENV)
                ? (string) $_ENV[$key]
                : ($value === false ? null : (string) $value);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalTwilioEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
        if ($this->originalAppKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->originalAppKey;
        }
        parent::tearDown();
    }

    public function testSaveMasksAndDecryptsWorkspaceSecret(): void
    {
        $service = new WorkspaceSmsChannelConfigService();
        $service->save(1, [
            'enabled' => true,
            'account_sid' => 'ACworkspace',
            'auth_token' => 'workspace-token',
            'from_number' => '+15550000001',
        ]);

        $masked = $service->get(1);
        $runtime = $service->runtimeConfig(1);
        $row = Database::queryOne(
            "SELECT encrypted_auth_token, auth_token_fingerprint
             FROM workspace_sms_channel_configs
             WHERE workspace_id = 1"
        );

        $this->assertTrue((bool) ($masked['auth_token_saved'] ?? false));
        $this->assertNull($masked['auth_token'] ?? null);
        $this->assertSame('workspace-token', (string) ($runtime['auth_token'] ?? ''));
        $this->assertSame('+15550000001', (string) ($runtime['from_number'] ?? ''));
        $this->assertNotSame('workspace-token', (string) ($row['encrypted_auth_token'] ?? ''));
        $this->assertSame(substr(hash('sha256', 'workspace-token'), 0, 16), (string) ($row['auth_token_fingerprint'] ?? ''));
    }

    public function testBlankTokenPreservesExistingSecret(): void
    {
        $service = new WorkspaceSmsChannelConfigService();
        $service->save(1, [
            'enabled' => true,
            'account_sid' => 'ACfirst',
            'auth_token' => 'first-token',
            'from_number' => '+15550000001',
        ]);
        $service->save(1, [
            'enabled' => true,
            'account_sid' => 'ACupdated',
            'auth_token' => '',
            'from_number' => '+15550000002',
        ]);

        $runtime = $service->runtimeConfig(1);

        $this->assertSame('ACupdated', (string) ($runtime['account_sid'] ?? ''));
        $this->assertSame('first-token', (string) ($runtime['auth_token'] ?? ''));
        $this->assertSame('+15550000002', (string) ($runtime['from_number'] ?? ''));
    }

    public function testWorkspaceIsolationAndFilteredQueueCounts(): void
    {
        $this->seedWorkspace(2);
        $service = new WorkspaceSmsChannelConfigService();
        $service->save(1, [
            'enabled' => true,
            'account_sid' => 'ACone',
            'auth_token' => 'token-one',
            'from_number' => '+15550000001',
        ]);
        $service->save(2, [
            'enabled' => true,
            'account_sid' => 'ACtwo',
            'auth_token' => 'token-two',
            'from_number' => '+15550000002',
        ]);
        $this->seedSmsQueueRow(1, 'failed');
        $this->seedSmsQueueRow(2, 'pending');

        $first = $service->runtimeConfig(1);
        $second = $service->runtimeConfig(2);
        $firstReadiness = $service->readiness(1);
        $secondReadiness = $service->readiness(2);

        $this->assertSame('+15550000001', (string) ($first['from_number'] ?? ''));
        $this->assertSame('+15550000002', (string) ($second['from_number'] ?? ''));
        $this->assertSame(1, (int) ($firstReadiness['failed_messages'] ?? 0));
        $this->assertSame(0, (int) ($firstReadiness['queued_messages'] ?? 0));
        $this->assertSame(0, (int) ($secondReadiness['failed_messages'] ?? 0));
        $this->assertSame(1, (int) ($secondReadiness['queued_messages'] ?? 0));
    }

    public function testLegacyEnvBackfillImportsInstalledSmsWorkspaces(): void
    {
        $this->seedWorkspace(2);
        $this->seedSmsInstall(1);
        $this->seedSmsInstall(2);
        $_ENV['TWILIO_ACCOUNT_SID'] = 'AClegacy';
        $_ENV['TWILIO_AUTH_TOKEN'] = 'legacy-token';
        $_ENV['TWILIO_FROM_NUMBER'] = '+15550009999';
        putenv('TWILIO_ACCOUNT_SID=AClegacy');
        putenv('TWILIO_AUTH_TOKEN=legacy-token');
        putenv('TWILIO_FROM_NUMBER=+15550009999');

        $service = new WorkspaceSmsChannelConfigService();
        $imported = $service->importLegacyEnvForInstalledWorkspaces();

        $first = $service->runtimeConfig(1);
        $second = $service->runtimeConfig(2);
        $this->assertSame(2, $imported);
        $this->assertSame('workspace', (string) ($first['source'] ?? ''));
        $this->assertSame('AClegacy', (string) ($first['account_sid'] ?? ''));
        $this->assertSame('legacy-token', (string) ($first['auth_token'] ?? ''));
        $this->assertSame('+15550009999', (string) ($second['from_number'] ?? ''));
    }

    private function seedWorkspace(int $workspaceId): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name)",
            [$workspaceId, sprintf('00000000-0000-4000-8000-%012d', $workspaceId), 'SMS Workspace ' . $workspaceId, 'sms-workspace-' . $workspaceId]
        );
    }

    private function seedSmsQueueRow(int $workspaceId, string $status): void
    {
        Database::execute(
            "INSERT INTO sms_messages
                (workspace_id, uuid, contact_id, to_number, from_number, message_body, status, direction, provider, created_at)
             VALUES (?, ?, NULL, '+15551112222', '+15550000000', 'Queue test', 'pending', 'outbound', 'twilio', NOW())",
            [$workspaceId, uuid_v4()]
        );
        Database::execute(
            "INSERT INTO sms_queue (message_id, workspace_id, status, created_at)
             VALUES (?, ?, ?, NOW())",
            [(int) Database::lastInsertId(), $workspaceId, $status]
        );
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
