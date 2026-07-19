<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\WhatsAppAssistantConfig;
use CRM\Services\WhatsAppAssistantDigestService;
use CRM\Services\WhatsAppAssistantFormatter;
use CRM\Services\WhatsAppAssistantSessionService;
use CRM\Services\WhatsAppService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;

class WhatsAppAssistantDigestServiceTest extends DatabaseTestCase
{
    public function testDigestEnabledWorkspaceIdsReturnOnlyActiveEnabledWhatsappConfigs(): void
    {
        Database::execute('DELETE FROM workspace_assistant_configs');
        $this->createWorkspace(2, 'Second Workspace', 'wa-digest-second');
        $this->createWorkspace(3, 'Archived Workspace', 'wa-digest-archived', 'archived');

        $this->saveWorkspaceWhatsappConfig(1, true, true);
        $this->saveWorkspaceWhatsappConfig(2, true, false);
        $this->saveWorkspaceWhatsappConfig(3, true, true);

        $this->assertTrue(WhatsAppAssistantDigestService::hasWorkspaceDigestConfigRows());
        $this->assertSame([1], WhatsAppAssistantDigestService::getDigestEnabledWorkspaceIds());
        $this->assertSame([1, 2], WhatsAppAssistantDigestService::getDigestEnabledWorkspaceIds(true));
    }

    public function testWorkspaceSenderInheritanceMakesAssistantReadyWithAuthorizedNumber(): void
    {
        $this->resetWhatsAppAssistantRuntime();
        $userId = $this->createAdminUser('wa-inherit-ready@example.test');
        $this->addWorkspaceMembership(1, $userId, 'owner');
        $this->connectWorkspaceWhatsapp(1, $userId, '973354845864175', '+254 700 000 111', 'workspace-ready-token');
        $this->saveAssistantRuntimeConfig(1, $userId, [
            'sender_mode' => 'workspace',
            'digest_enabled' => true,
        ]);
        $this->insertAuthorizedNumber(1, $userId, '254700000222', 'Owner line');
        $this->installWhatsAppAssistantModule(1, $userId);
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $validation = (new WhatsAppAssistantConfig())->validate();

        $this->assertTrue((bool) ($validation['uses_workspace_sender'] ?? false));
        $this->assertSame('973354845864175', (string) ($validation['assistant_phone_number_id'] ?? ''));
        $this->assertTrue((bool) ($validation['outbound_ready'] ?? false));
        $this->assertTrue((bool) ($validation['inbound_ready'] ?? false));
        $this->assertSame(1, (int) ($validation['authorized_number_count'] ?? 0));

        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);

        $this->assertTrue((bool) ($readiness['ready'] ?? false));
        $this->assertSame('ready', (string) ($readiness['status'] ?? ''));
        $this->assertNotContains('Authorized team numbers', (array) ($readiness['blockers'] ?? []));
    }

    public function testWhatsAppAssistantReadinessRequiresAuthorizedTeamNumber(): void
    {
        $this->resetWhatsAppAssistantRuntime();
        $userId = $this->createAdminUser('wa-no-authorized@example.test');
        $this->addWorkspaceMembership(1, $userId, 'owner');
        $this->connectWorkspaceWhatsapp(1, $userId, '973354845864176', '+254 700 000 112', 'workspace-no-auth-token');
        $this->saveAssistantRuntimeConfig(1, $userId, [
            'sender_mode' => 'workspace',
            'digest_enabled' => true,
        ]);
        $this->installWhatsAppAssistantModule(1, $userId);
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $validation = (new WhatsAppAssistantConfig())->validate();
        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);

        $this->assertTrue((bool) ($validation['outbound_ready'] ?? false));
        $this->assertTrue((bool) ($validation['inbound_ready'] ?? false));
        $this->assertSame(0, (int) ($validation['authorized_number_count'] ?? -1));
        $this->assertStringContainsString('authorized team number', (string) ($validation['message'] ?? ''));
        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertContains('Authorized team numbers', (array) ($readiness['blockers'] ?? []));
    }

    public function testCustomAssistantNumberRequiresWebhookMetadataMatch(): void
    {
        $this->resetWhatsAppAssistantRuntime();
        $userId = $this->createAdminUser('wa-custom-webhook@example.test');
        $this->addWorkspaceMembership(1, $userId, 'owner');
        $this->connectWorkspaceWhatsapp(1, $userId, '111111111111111', '+254 700 000 113', 'workspace-webhook-token');
        $this->saveAssistantRuntimeConfig(1, $userId, [
            'sender_mode' => 'custom',
            'assistant_phone_number' => '+254 700 000 333',
            'assistant_phone_number_id' => '222222222222222',
            'access_token' => 'custom-assistant-token',
            'digest_enabled' => true,
        ]);
        $this->insertAuthorizedNumber(1, $userId, '254700000333', 'Custom owner line');
        $this->installWhatsAppAssistantModule(1, $userId);
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $validation = (new WhatsAppAssistantConfig())->validate();
        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);

        $this->assertFalse((bool) ($validation['uses_workspace_sender'] ?? true));
        $this->assertTrue((bool) ($validation['outbound_ready'] ?? false));
        $this->assertFalse((bool) ($validation['custom_webhook_ready'] ?? true));
        $this->assertFalse((bool) ($validation['inbound_ready'] ?? true));
        $this->assertStringContainsString('custom assistant number', (string) ($validation['message'] ?? ''));
        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertContains('Webhook ready', (array) ($readiness['blockers'] ?? []));
    }

    public function testDigestRecipientsAreScopedToActiveRuntimeWorkspace(): void
    {
        $firstUser = $this->createAdminUser('wa-first-recipient@example.test');
        $secondUser = $this->createAdminUser('wa-second-recipient@example.test');
        $this->createWorkspace(2, 'Second Workspace', 'wa-recipient-second');
        $this->addWorkspaceMembership(1, $firstUser, 'owner');
        $this->addWorkspaceMembership(2, $secondUser, 'owner');
        $this->insertAuthorizedNumber(1, $firstUser, '15550000001', 'First Recipient');
        $this->insertAuthorizedNumber(2, $secondUser, '15550000002', 'Second Recipient');

        $config = new WhatsAppAssistantConfig();

        WorkspaceContext::activateRuntimeWorkspace(1);
        $firstRecipients = $config->getDigestRecipients();
        WorkspaceContext::activateRuntimeWorkspace(2);
        $secondRecipients = $config->getDigestRecipients();

        $this->assertSame(['15550000001'], array_column($firstRecipients, 'phone_number'));
        $this->assertSame(['15550000002'], array_column($secondRecipients, 'phone_number'));
    }

    public function testRunScheduledForWorkspacesProcessesEnabledWorkspacesIndependentlyWithoutRecipients(): void
    {
        Database::execute('DELETE FROM workspace_assistant_configs');
        Database::execute('DELETE FROM whatsapp_assistant_authorized_numbers');
        $this->createWorkspace(2, 'Second Workspace', 'wa-worker-second');
        $this->saveWorkspaceWhatsappConfig(1, true, true);
        $this->saveWorkspaceWhatsappConfig(2, true, true);

        $result = (new WhatsAppAssistantDigestService())->runScheduledForWorkspaces(true, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertTrue((bool) ($result['ran'] ?? false));
        $this->assertSame(0, (int) ($result['sent'] ?? -1));
        $this->assertSame(0, (int) ($result['failed'] ?? -1));
        $this->assertSame([1, 2], array_map(
            static fn(array $workspaceResult): int => (int) ($workspaceResult['workspace_id'] ?? 0),
            (array) ($result['workspace_results'] ?? [])
        ));
        foreach ((array) ($result['workspace_results'] ?? []) as $workspaceResult) {
            $this->assertSame(0, (int) ($workspaceResult['sent'] ?? -1));
            $this->assertSame(0, (int) ($workspaceResult['failed'] ?? -1));
            $this->assertSame([], (array) ($workspaceResult['results'] ?? ['unexpected']));
        }
    }

    public function testExplicitDigestRejectsMappedUserMismatchBeforeSend(): void
    {
        $mappedUser = $this->createAdminUser('wa-mapped@example.test');
        $wrongUser = $this->createAdminUser('wa-wrong@example.test');
        $this->addWorkspaceMembership(1, $mappedUser, 'owner');
        $this->addWorkspaceMembership(1, $wrongUser, 'admin');
        $this->insertAuthorizedNumber(1, $mappedUser, '15550000003', 'Mapped Recipient');
        WorkspaceContext::activateRuntimeWorkspace(1);

        $whatsApp = new WhatsAppDigestFakeWhatsAppService();
        $emailDigest = new WhatsAppDigestFakeEmailDigestService();
        $service = $this->makeService($whatsApp, $emailDigest);

        $result = $service->sendDigestToNumber($wrongUser, '15550000003', true, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertSame('recipient_user_mismatch', (string) ($result['status'] ?? ''));
        $this->assertSame([], $whatsApp->sentMessages);
        $this->assertSame([], $emailDigest->requestedUserIds);
    }

    public function testExplicitDigestUsesMappedRecipientUserPayload(): void
    {
        $mappedUser = $this->createAdminUser('wa-payload@example.test');
        $this->addWorkspaceMembership(1, $mappedUser, 'owner');
        $this->insertAuthorizedNumber(1, $mappedUser, '15550000004', 'Payload Recipient');
        WorkspaceContext::activateRuntimeWorkspace(1);

        $whatsApp = new WhatsAppDigestFakeWhatsAppService();
        $emailDigest = new WhatsAppDigestFakeEmailDigestService([
            [
                'title' => 'Mapped user task',
                'priority' => 'high',
                'due_date' => '2026-06-10 09:00:00',
            ],
        ]);
        $service = $this->makeService($whatsApp, $emailDigest);

        $result = $service->sendDigestToNumber($mappedUser, '+1 (555) 000-0004', true, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame([$mappedUser], $emailDigest->requestedUserIds);
        $this->assertCount(1, $whatsApp->sentMessages);
        $this->assertStringContainsString('Mapped user task', (string) ($whatsApp->sentMessages[0]['text'] ?? ''));
    }

    public function testSessionBlockedIsNotCountedAsSent(): void
    {
        $userId = $this->createAdminUser('wa-blocked@example.test');
        $this->addWorkspaceMembership(1, $userId, 'owner');
        $this->insertAuthorizedNumber(1, $userId, '15550000005', 'Blocked Recipient');
        $this->saveWorkspaceWhatsappConfig(1, true, true);
        WorkspaceContext::activateRuntimeWorkspace(1);

        $session = new WhatsAppDigestFakeSessionService(false, 'blocked', 'Assistant session expired.');
        $service = $this->makeService(new WhatsAppDigestFakeWhatsAppService(), new WhatsAppDigestFakeEmailDigestService(), null, $session);

        $result = $service->runScheduled(true, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertTrue((bool) ($result['ran'] ?? false));
        $this->assertSame(0, (int) ($result['sent'] ?? -1));
        $this->assertSame(0, (int) ($result['failed'] ?? -1));
        $this->assertSame(1, (int) ($result['skipped'] ?? -1));
        $this->assertSame('blocked', (string) ($result['results'][0]['reason'] ?? ''));

        $log = Database::queryOne("SELECT status FROM whatsapp_assistant_digest_log WHERE phone_number = ? LIMIT 1", ['15550000005']);
        $this->assertSame('blocked', (string) ($log['status'] ?? ''));
    }

    public function testPartialSendFailureIsLoggedAndBlocksBlindRetry(): void
    {
        $userId = $this->createAdminUser('wa-partial@example.test');
        $this->addWorkspaceMembership(1, $userId, 'owner');
        $this->insertAuthorizedNumber(1, $userId, '15550000006', 'Partial Recipient');
        $this->saveWorkspaceWhatsappConfig(1, true, true);
        WorkspaceContext::activateRuntimeWorkspace(1);

        $whatsApp = new WhatsAppDigestFakeWhatsAppService(2);
        $formatter = new WhatsAppDigestFixedFormatter(['Digest chunk one', 'Digest chunk two']);
        $service = $this->makeService($whatsApp, new WhatsAppDigestFakeEmailDigestService(), $formatter);

        $result = $service->sendDigestToNumber($userId, '15550000006', false, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertSame('partial_failed', (string) ($result['status'] ?? ''));
        $this->assertSame(2, (int) ($result['message_count'] ?? 0));
        $this->assertSame(1, (int) ($result['sent_message_count'] ?? 0));
        $this->assertSame(['wamid.fake.1'], $result['provider_ids'] ?? []);
        $this->assertFalse($service->hasSuccessfulDigestForToday($userId, '15550000006', '2026-06-10'));
        $this->assertTrue($service->hasTerminalDigestForToday($userId, '15550000006', '2026-06-10'));

        $log = Database::queryOne(
            "SELECT status, message_count, sent_message_count, provider_message_ids_json
             FROM whatsapp_assistant_digest_log
             WHERE user_id = ? AND phone_number = ?
             LIMIT 1",
            [$userId, '15550000006']
        );
        $this->assertSame('partial_failed', (string) ($log['status'] ?? ''));
        $this->assertSame(2, (int) ($log['message_count'] ?? 0));
        $this->assertSame(1, (int) ($log['sent_message_count'] ?? 0));
        $this->assertSame(['wamid.fake.1'], json_decode((string) ($log['provider_message_ids_json'] ?? '[]'), true));

        $retryResult = $service->runScheduled(false, new \DateTimeImmutable('2026-06-10 09:00:00'));
        $this->assertSame(0, (int) ($retryResult['sent'] ?? -1));
        $this->assertSame(0, (int) ($retryResult['failed'] ?? -1));
        $this->assertSame(1, (int) ($retryResult['skipped'] ?? -1));
        $this->assertSame('already_attempted_today', (string) ($retryResult['results'][0]['reason'] ?? ''));
    }

    private function makeService(
        WhatsAppDigestFakeWhatsAppService $whatsApp,
        WhatsAppDigestFakeEmailDigestService $emailDigest,
        ?WhatsAppAssistantFormatter $formatter = null,
        ?WhatsAppDigestFakeSessionService $session = null
    ): WhatsAppAssistantDigestService {
        return new WhatsAppAssistantDigestService(
            new WhatsAppAssistantConfig(),
            $formatter ?? new WhatsAppAssistantFormatter(),
            $whatsApp,
            $emailDigest,
            $session ?? new WhatsAppDigestFakeSessionService()
        );
    }

    private function createAdminUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [$this->uuid(), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function createWorkspace(int $workspaceId, string $name, string $slug, string $status = 'active'): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), status = VALUES(status), updated_at = NOW()",
            [
                $workspaceId,
                sprintf('00000000-0000-4000-8000-%012d', $workspaceId),
                $name,
                $slug,
                $status,
            ]
        );
    }

    private function addWorkspaceMembership(int $workspaceId, int $userId, string $roleSlug = 'admin'): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active', updated_at = NOW()",
            [$workspaceId, $userId, $roleSlug, $roleSlug === 'owner' ? 1 : 0]
        );
    }

    private function resetWhatsAppAssistantRuntime(): void
    {
        Database::execute("DELETE FROM workspace_skill_installs WHERE skill_key = ?", [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]);
        Database::execute("DELETE FROM workspace_assistant_configs WHERE assistant_type = 'whatsapp'");
        Database::execute('DELETE FROM whatsapp_assistant_authorized_numbers');
        Database::execute('DELETE FROM workspace_whatsapp_integrations');
    }

    private function connectWorkspaceWhatsapp(int $workspaceId, int $userId, string $phoneNumberId, string $displayNumber, string $accessToken): void
    {
        (new WorkspaceConnectService())->storeManualWhatsAppIntegration($workspaceId, $userId, [
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => $displayNumber,
            'access_token' => $accessToken,
            'verified_name' => 'Workspace WhatsApp',
        ]);
    }

    private function saveAssistantRuntimeConfig(int $workspaceId, int $userId, array $settings): void
    {
        (new WorkspaceAssistantConfigService())->save($workspaceId, 'whatsapp', array_merge([
            'sender_mode' => 'workspace',
            'digest_enabled' => false,
            'digest_time' => '07:00',
            'auto_reopen_enabled' => false,
        ], $settings), true, $userId);
    }

    private function installWhatsAppAssistantModule(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = 'installed',
                uninstalled_at = NULL,
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [$workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $userId, $userId]
        );
    }

    private function saveWorkspaceWhatsappConfig(int $workspaceId, bool $enabled, bool $digestEnabled): void
    {
        Database::execute(
            "INSERT INTO workspace_assistant_configs (workspace_id, assistant_type, enabled, settings_json)
             VALUES (?, 'whatsapp', ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), settings_json = VALUES(settings_json), updated_at = NOW()",
            [
                $workspaceId,
                $enabled ? 1 : 0,
                json_encode([
                    'assistant_phone_number_id' => 'phone-id-' . $workspaceId,
                    'access_token' => 'token-' . $workspaceId,
                    'digest_enabled' => $digestEnabled,
                    'digest_time' => '07:00',
                    'auto_reopen_enabled' => false,
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function insertAuthorizedNumber(int $workspaceId, int $userId, string $phoneNumber, string $label): int
    {
        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers
                (workspace_id, phone_number, user_id, label, is_active, digest_enabled, last_used_at)
             VALUES (?, ?, ?, ?, 1, 1, ?)",
            [$workspaceId, $phoneNumber, $userId, $label, '2026-06-10 07:00:00']
        );

        return (int) Database::lastInsertId();
    }
}

class WhatsAppDigestFakeWhatsAppService extends WhatsAppService
{
    public array $sentMessages = [];
    private int $failOnSendNumber;

    public function __construct(int $failOnSendNumber = 0)
    {
        $this->failOnSendNumber = $failOnSendNumber;
    }

    public function sendTextMessage(string $to, string $text): array
    {
        $sendNumber = count($this->sentMessages) + 1;
        if ($this->failOnSendNumber === $sendNumber) {
            throw new \RuntimeException('Provider failed after partial delivery.');
        }

        $this->sentMessages[] = ['to' => $to, 'text' => $text];

        return ['messages' => [['id' => 'wamid.fake.' . $sendNumber]]];
    }
}

class WhatsAppDigestFakeEmailDigestService extends EmailAssistantDigestService
{
    public array $requestedUserIds = [];
    private array $tasks;

    public function __construct(array $tasks = [])
    {
        $this->tasks = $tasks ?: [
            [
                'title' => 'Default WhatsApp digest task',
                'priority' => 'high',
                'due_date' => '2026-06-10 09:00:00',
            ],
        ];
    }

    public function buildDigestPayload(int $userId, ?\DateTimeImmutable $runAt = null): array
    {
        $this->requestedUserIds[] = $userId;

        return [
            'tasks' => $this->tasks,
            'recommendations' => [
                'priorities' => [['title' => 'Keep today focused']],
                'quick_wins' => [['title' => 'Clear one overdue task']],
            ],
            'workspace' => ['name' => 'Workspace ' . ((int) (WorkspaceContext::currentWorkspaceId() ?? 0))],
        ];
    }
}

class WhatsAppDigestFakeSessionService extends WhatsAppAssistantSessionService
{
    private bool $ready;
    private string $action;
    private string $message;
    public int $recordedOutboundCount = 0;

    public function __construct(bool $ready = true, string $action = 'send_session_message', string $message = '')
    {
        $this->ready = $ready;
        $this->action = $action;
        $this->message = $message;
    }

    public function ensureSessionReadyForOutbound(array $authorizedNumber, string $reason = 'assistant_reply', bool $allowReopen = true): array
    {
        return [
            'ready' => $this->ready,
            'action' => $this->action,
            'message' => $this->message,
        ];
    }

    public function recordOutboundMessage(array $authorizedNumber, ?\DateTimeImmutable $now = null): void
    {
        $this->recordedOutboundCount++;
    }
}

class WhatsAppDigestFixedFormatter extends WhatsAppAssistantFormatter
{
    private array $messages;

    public function __construct(array $messages)
    {
        parent::__construct();
        $this->messages = $messages;
    }

    public function formatDigest(array $tasks, array $recommendations, bool $isTest = false, array $context = []): array
    {
        return $this->messages;
    }
}
