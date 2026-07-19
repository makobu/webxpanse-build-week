<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AICoach;
use CRM\Modules\Tasks;
use CRM\Modules\UserPreferences;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\SMTPClient;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantDigestServiceTest extends DatabaseTestCase
{
    public function testTestDigestDoesNotBlockScheduledDigestForToday(): void
    {
        $userId = $this->createAdminUser('digest-test@example.com');
        $service = $this->makeDigestServiceWithDoubles();

        $result = $service->sendDigestToUser($userId, 'digest-test@example.com', true);

        $this->assertTrue($result['success']);
        $this->assertFalse($service->hasSuccessfulDigestForToday($userId));
        if (Database::columnExists('email_digest_log', 'is_test')) {
            $row = Database::queryOne("SELECT is_test, recipient_email, status FROM email_digest_log WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
            $this->assertSame(1, (int) ($row['is_test'] ?? 0));
            $this->assertSame('digest-test@example.com', (string) ($row['recipient_email'] ?? ''));
            $this->assertSame('success', (string) ($row['status'] ?? ''));
        }
    }

    public function testScheduledDigestMarksSuccessfulDigestForToday(): void
    {
        $userId = $this->createAdminUser('digest-live@example.com');
        $service = $this->makeDigestServiceWithDoubles();

        $result = $service->sendDigestToUser($userId, 'digest-live@example.com', false);

        $this->assertTrue($result['success']);
        $this->assertTrue($service->hasSuccessfulDigestForToday($userId));
        if (Database::columnExists('email_digest_log', 'is_test')) {
            $row = Database::queryOne("SELECT is_test FROM email_digest_log WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
            $this->assertSame(0, (int) ($row['is_test'] ?? 1));
        }
    }

    public function testDigestLogIsScopedToActiveWorkspace(): void
    {
        $userId = $this->createAdminUser('digest-workspace@example.com');
        $this->createWorkspace(2, 'Second Workspace', 'second-workspace');
        $service = $this->makeDigestServiceWithDoubles();
        $runAt = new \DateTimeImmutable('2026-06-10 08:00:00');

        WorkspaceContext::activateRuntimeWorkspace(1);
        $result = $service->sendDigestToUser($userId, 'digest-workspace@example.com', false, $runAt);

        $this->assertTrue($result['success']);
        $this->assertTrue($service->hasSuccessfulDigestForToday($userId, '2026-06-10'));

        WorkspaceContext::activateRuntimeWorkspace(2);
        $this->assertFalse($service->hasSuccessfulDigestForToday($userId, '2026-06-10'));
    }

    public function testDigestEnabledWorkspaceIdsReturnOnlyActiveEnabledDigestConfigs(): void
    {
        Database::execute('DELETE FROM workspace_assistant_configs');
        $this->createWorkspace(2, 'Second Workspace', 'digest-second');
        $this->createWorkspace(3, 'Archived Workspace', 'digest-archived', 'archived');

        $this->saveWorkspaceAssistantConfig(1, true, true);
        $this->saveWorkspaceAssistantConfig(2, true, false);
        $this->saveWorkspaceAssistantConfig(3, true, true);

        $this->assertTrue(EmailAssistantDigestService::hasWorkspaceDigestConfigRows());
        $this->assertSame([1], EmailAssistantDigestService::getDigestEnabledWorkspaceIds());
    }

    public function testLegacyDigestEnvironmentIsIgnoredWhenPluginConfigurationIsWorkspaceScoped(): void
    {
        $original = $_ENV['EMAIL_DIGEST_ENABLED'] ?? null;
        $_ENV['EMAIL_DIGEST_ENABLED'] = 'true';

        try {
            Database::execute('DELETE FROM workspace_assistant_configs');
            $this->createWorkspace(2, 'Second Workspace', 'legacy-coexistence');
            $this->saveWorkspaceAssistantConfig(2, true, true);

            $this->assertFalse(EmailAssistantDigestService::isLegacyDigestConfigEnabled());
            $this->assertFalse(EmailAssistantDigestService::workspaceHasEmailAssistantConfig(1));
            $this->assertTrue(EmailAssistantDigestService::workspaceHasEmailAssistantConfig(2));
            $this->assertSame([2], EmailAssistantDigestService::getDigestEnabledWorkspaceIds());
        } finally {
            if ($original === null) {
                unset($_ENV['EMAIL_DIGEST_ENABLED']);
            } else {
                $_ENV['EMAIL_DIGEST_ENABLED'] = $original;
            }
        }
    }

    public function testCustomRecipientsRequireActiveWorkspaceMembershipAndExposeSkippedReasons(): void
    {
        Database::execute('DELETE FROM workspace_assistant_configs');
        $memberId = $this->createAdminUser('digest-member@example.com');
        $this->createAdminUser('digest-outsider@example.com');
        $this->addWorkspaceMembership(1, $memberId, 'admin');
        $this->saveWorkspaceAssistantConfig(
            1,
            true,
            true,
            'digest-member@example.com, digest-outsider@example.com, not-an-email, digest-member@example.com'
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $service = $this->makeDigestServiceWithDoubles();

        $recipients = $service->getConfiguredRecipients();

        $this->assertCount(3, $recipients);
        $this->assertSame($memberId, (int) ($recipients[0]['user_id'] ?? 0));
        $this->assertSame('email_not_linked_to_workspace_user', (string) ($recipients[1]['reason'] ?? ''));
        $this->assertSame('invalid_recipient_email', (string) ($recipients[2]['reason'] ?? ''));
    }

    public function testRunScheduledSkipsInvalidRecipientEmail(): void
    {
        $service = new class extends EmailAssistantDigestService {
            public function __construct()
            {
            }

            public function shouldRunNow(?\DateTimeImmutable $now = null, bool $force = false): array
            {
                return ['due' => true, 'reason' => 'ok', 'send_time' => '07:00'];
            }

            public function getConfiguredRecipients(): array
            {
                return [[
                    'user_id' => 123,
                    'email' => 'not-an-email',
                ]];
            }
        };

        $result = $service->runScheduled(false, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $this->assertTrue($result['ran']);
        $this->assertSame(0, (int) $result['sent']);
        $this->assertSame(0, (int) $result['failed']);
        $this->assertSame(1, (int) $result['skipped']);
        $this->assertSame('invalid_recipient_email', (string) ($result['results'][0]['reason'] ?? ''));
    }

    public function testDigestTaskSelectionKeepsDueItemsAheadOfFutureTasks(): void
    {
        $userId = $this->createAdminUser('digest-tasks@example.com');
        $service = $this->makeDigestServiceWithDoubles();

        for ($i = 1; $i <= 60; $i++) {
            $this->createTask($userId, 'Future urgent task ' . $i, 'urgent', '2026-07-01 09:00:00');
        }
        $this->createTask($userId, 'Today high task', 'high', '2026-06-10 09:00:00');
        $this->createTask($userId, 'Overdue low task', 'low', '2026-06-09 09:00:00');
        $this->createTask($userId, 'No date medium task', 'medium', null);

        $payload = $this->withAppUrl(
            'https://crm.example.test/crm',
            fn() => $service->buildDigestPayload($userId, new \DateTimeImmutable('2026-06-10 08:00:00'))
        );
        $titles = array_map(static fn(array $task): string => (string) ($task['title'] ?? ''), (array) ($payload['tasks'] ?? []));
        $buckets = array_map(static fn(array $task): string => (string) ($task['digest_bucket'] ?? ''), (array) ($payload['tasks'] ?? []));

        $this->assertSame(['Overdue low task', 'Today high task', 'No date medium task'], $titles);
        $this->assertSame(['overdue', 'due_today', 'unscheduled'], $buckets);
        $this->assertStringStartsWith('https://crm.example.test/crm/task_view.php?id=', (string) ($payload['tasks'][0]['task_url'] ?? ''));
    }

    public function testDigestLinksAreOmittedWithoutSafeAbsoluteAppUrl(): void
    {
        $userId = $this->createAdminUser('digest-no-links@example.com');
        $service = $this->makeDigestServiceWithDoubles();
        $this->createTask($userId, 'Today linked task', 'high', '2026-06-10 09:00:00');

        $payload = $this->withAppUrl(
            'not-a-valid-url',
            fn() => $service->buildDigestPayload($userId, new \DateTimeImmutable('2026-06-10 08:00:00'))
        );

        $this->assertSame('', (string) ($payload['tasks'][0]['task_url'] ?? ''));
        $this->assertSame('', (string) ($payload['tasks'][0]['contact_url'] ?? ''));
    }

    public function testDigestTaskPayloadIncludesContactCompanyContext(): void
    {
        $userId = $this->createAdminUser('digest-company@example.com');
        $contactId = $this->createContact('Casey', 'Buyer', 'casey-buyer@example.test', 'Northwind Group');
        $service = $this->makeDigestServiceWithDoubles();
        $this->createTask($userId, 'Follow account renewal', 'high', '2026-06-10 09:00:00', $contactId);

        $payload = $this->withAppUrl(
            'https://crm.example.test/crm',
            fn() => $service->buildDigestPayload($userId, new \DateTimeImmutable('2026-06-10 08:00:00'))
        );

        $this->assertSame('Casey Buyer', (string) ($payload['tasks'][0]['contact_name'] ?? ''));
        $this->assertSame('Northwind Group', (string) ($payload['tasks'][0]['contact_company'] ?? ''));
        $this->assertStringStartsWith('https://crm.example.test/crm/contact_view.php?id=', (string) ($payload['tasks'][0]['contact_url'] ?? ''));
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

    private function saveWorkspaceAssistantConfig(int $workspaceId, bool $enabled, bool $digestEnabled, string $digestRecipients = 'admins'): void
    {
        Database::execute(
            "INSERT INTO workspace_assistant_configs (workspace_id, assistant_type, enabled, settings_json)
             VALUES (?, 'email', ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), settings_json = VALUES(settings_json), updated_at = NOW()",
            [
                $workspaceId,
                $enabled ? 1 : 0,
                json_encode([
                    'digest_enabled' => $digestEnabled,
                    'digest_time' => '07:00',
                    'digest_recipients' => $digestRecipients,
                    'smtp_host' => 'smtp.example.test',
                    'smtp_username' => 'assistant@example.test',
                    'smtp_password' => 'secret',
                    'from_email' => 'assistant@example.test',
                ]),
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

    private function createContact(string $firstName, string $lastName, string $email, string $company): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, created_at)
             VALUES (1, ?, ?, ?, ?, ?, NOW())",
            [$this->uuid(), $firstName, $lastName, $email, $company]
        );

        return (int) Database::lastInsertId();
    }

    private function createTask(int $userId, string $title, string $priority, ?string $dueDate, ?int $contactId = null): int
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, priority, due_date, created_at)
             VALUES (1, ?, ?, ?, ?, 'pending', ?, ?, NOW())",
            [$title, $contactId, $userId, $userId, $priority, $dueDate]
        );

        return (int) Database::lastInsertId();
    }

    private function withAppUrl(?string $appUrl, callable $callback)
    {
        $hadEnv = array_key_exists('APP_URL', $_ENV);
        $originalEnv = $_ENV['APP_URL'] ?? null;
        $originalGetenv = getenv('APP_URL');

        if ($appUrl === null) {
            unset($_ENV['APP_URL']);
            putenv('APP_URL');
        } else {
            $_ENV['APP_URL'] = $appUrl;
            putenv('APP_URL=' . $appUrl);
        }

        try {
            return $callback();
        } finally {
            if ($hadEnv) {
                $_ENV['APP_URL'] = $originalEnv;
            } else {
                unset($_ENV['APP_URL']);
            }

            if ($originalGetenv === false) {
                putenv('APP_URL');
            } else {
                putenv('APP_URL=' . $originalGetenv);
            }
        }
    }

    private function makeDigestServiceWithDoubles(): EmailAssistantDigestService
    {
        $service = new EmailAssistantDigestService();

        $coach = new class extends AICoach {
            public function __construct()
            {
            }

            public function generateStarterTaskRecommendations(int $userId, string $mode = '1'): array
            {
                return [
                    'priorities' => [['title' => 'Follow up leads']],
                    'quick_wins' => [['title' => 'Review overdue tasks']],
                    'why_this_matters' => 'Keep momentum on key work.',
                ];
            }
        };

        $tasks = new class extends Tasks {
            public function __construct()
            {
            }

            public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
            {
                return [
                    [
                        'id' => 101,
                        'title' => 'Call back prospect',
                        'status' => 'pending',
                        'priority' => 'high',
                        'due_date' => date('Y-m-d'),
                    ],
                ];
            }
        };

        $preferences = new class extends UserPreferences {
            public function getEffectiveAIGuidanceMode(int $userId): string
            {
                return '1';
            }
        };

        $smtp = new class extends SMTPClient {
            public array $sentMessages = [];

            public function __construct()
            {
            }

            public function send(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null): bool
            {
                $this->sentMessages[] = [
                    'to' => $to,
                    'from' => $from,
                    'subject' => $subject,
                    'body' => $body,
                    'body_html' => $bodyHtml,
                ];

                return true;
            }
        };

        $this->setPrivateProperty($service, 'coach', $coach);
        $this->setPrivateProperty($service, 'tasks', $tasks);
        $this->setPrivateProperty($service, 'preferences', $preferences);
        $this->setPrivateProperty($service, 'smtp', $smtp);

        return $service;
    }

    private function setPrivateProperty(object $object, string $property, object $value): void
    {
        $reflection = new \ReflectionClass($object);
        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);
        $propertyReflection->setValue($object, $value);
    }
}
