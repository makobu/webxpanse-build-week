<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\CacheManager;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\BeginnerGuidanceService;
use CRM\Services\UIExperienceService;
use CRM\Tests\DatabaseTestCase;

class BeginnerGuidanceServiceTest extends DatabaseTestCase
{
    private BeginnerGuidanceService $service;
    private int $userId;
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'SMTP_HOST' => $_ENV['SMTP_HOST'] ?? null,
            'EMAIL_ASSISTANT_SMTP_HOST' => $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] ?? null,
        ];
        $_ENV['SMTP_HOST'] = '';
        $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] = '';

        $this->resetGuidanceTables();
        $this->userId = $this->createUser('beginner.guidance@example.test');
        $this->service = new BeginnerGuidanceService();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function testWaitingInboundRanksBeforeDueTasks(): void
    {
        $contactId = $this->createContact('Waiting', 'Customer');
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, read_at, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Need help', 'Can you call me?', 'sent', NULL, NOW())",
            [$this->uuid(), $contactId]
        );
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, due_date, created_at)
             VALUES (1, 'Follow up quote', ?, ?, ?, 'pending', NOW(), NOW())",
            [$contactId, $this->userId, $this->userId]
        );

        $payload = $this->guidance();

        $this->assertSame('reply_to_customer', $payload['primary_action']['key']);
        $this->assertSame('Can draft replies', $payload['primary_action']['readiness_label']);
        $this->assertTrue($payload['primary_action']['requires_approval']);
    }

    public function testDueFollowupRanksBeforeQuietDealWithoutInbound(): void
    {
        $contactId = $this->createContact('Due', 'Customer');
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, due_date, created_at)
             VALUES (1, 'Call customer today', ?, ?, ?, 'pending', NOW(), NOW())",
            [$contactId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, updated_at, created_at)
             VALUES (1, 'Quiet repair job', ?, ?, ?, 'proposal', 1200, DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY))",
            [$contactId, $this->userId, $this->userId]
        );

        $payload = $this->guidance();

        $this->assertSame('finish_due_followup', $payload['primary_action']['key']);
        $this->assertSame('Follow up a quiet deal', $payload['secondary_hints'][0]['label'] ?? '');
    }

    public function testEmptyPipelineFallsBackToAddFirstCustomer(): void
    {
        $payload = $this->guidance();

        $this->assertSame('add_first_customer', $payload['primary_action']['key']);
        $this->assertSame('Add your first customer', $payload['primary_action']['label']);
        $this->assertCount(1, $payload['secondary_hints']);
        $this->assertSame('connect_channel', $payload['secondary_hints'][0]['key']);
    }

    public function testMissingChannelReturnsPracticalBlockedLabel(): void
    {
        $this->createContact('Channel', 'Missing');

        $payload = $this->guidance();

        $this->assertSame('connect_channel', $payload['primary_action']['key']);
        $this->assertSame('Blocked until a channel is connected', $payload['primary_action']['readiness_label']);
        $this->assertContains('channel', $payload['blocked_by']);
    }

    public function testInvoiceReadyStateUsesPracticalInvoiceLabel(): void
    {
        $contactId = $this->createContact('Invoice', 'Ready');
        Database::execute("UPDATE invoice_settings SET enabled = 1 WHERE id = 1");
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, updated_at, created_at)
             VALUES (1, 'Ready money step', ?, ?, ?, 'proposal', 1500, NOW(), NOW())",
            [$contactId, $this->userId, $this->userId]
        );

        $payload = $this->guidance();

        $this->assertSame('prepare_invoice', $payload['primary_action']['key']);
        $this->assertSame('Ready to create invoices', $payload['primary_action']['readiness_label']);
    }

    public function testAdvancedModeDoesNotReceiveBeginnerOnlyReadinessCopy(): void
    {
        $contactId = $this->createContact('Advanced', 'User');
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, due_date, created_at)
             VALUES (1, 'Call customer today', ?, ?, ?, 'pending', NOW(), NOW())",
            [$contactId, $this->userId, $this->userId]
        );

        $payload = $this->service->guidanceFor(1, $this->userId, [
            'mode' => UIExperienceService::MODE_ADVANCED,
            'skip_cache' => true,
        ]);

        $this->assertSame(UIExperienceService::MODE_ADVANCED, $payload['mode']);
        $this->assertSame('finish_due_followup', $payload['primary_action']['key']);
        $this->assertStringStartsWith('task_view.php?id=', (string) ($payload['primary_action']['href'] ?? ''));
        $this->assertArrayNotHasKey('readiness_label', $payload['primary_action']);
        $this->assertArrayNotHasKey('requires_approval', $payload['primary_action']);
    }

    public function testFallbackPayloadNeverUsesDashboardAsCtaTarget(): void
    {
        $payload = $this->service->fallbackPayload($this->userId, UIExperienceService::MODE_BEGINNER, 1);
        $action = $payload['primary_action'];

        $this->assertSame('add_first_customer', $action['key']);
        $this->assertSame('contacts_create.php', $action['href']);
        $this->assertSame('Add customer', $action['cta_label']);
        $this->assertNotSame('dashboard.php', $action['href']);
        $this->assertNotSame('Stay on dashboard', $action['cta_label']);
    }

    public function testUnmappableFallbackHidesCtaInsteadOfUsingSamePageAction(): void
    {
        $outcomes = new class extends OutcomeMetrics {
            public function getTodayRevenueFocus(int $userId): array
            {
                return ['Review the business direction.'];
            }
        };
        $payload = (new BeginnerGuidanceService(null, $outcomes))->fallbackPayload(
            $this->userId,
            UIExperienceService::MODE_BEGINNER,
            1
        );
        $action = $payload['primary_action'];

        $this->assertSame('outcome_focus', $action['key']);
        $this->assertArrayNotHasKey('href', $action);
        $this->assertArrayNotHasKey('cta_label', $action);
    }

    public function testQuietDealFallbackRoutesToDealSurface(): void
    {
        $outcomes = new class extends OutcomeMetrics {
            public function getTodayRevenueFocus(int $userId): array
            {
                return ['Follow up 1 customer whose deal has gone quiet.'];
            }
        };

        $payload = (new BeginnerGuidanceService(null, $outcomes))->fallbackPayload(
            $this->userId,
            UIExperienceService::MODE_BEGINNER,
            1
        );
        $action = $payload['primary_action'];

        $this->assertSame('follow_up_quiet_deal', $action['key']);
        $this->assertSame('deals.php', $action['href']);
        $this->assertSame('Open deals', $action['cta_label']);
    }

    public function testGuidanceCacheUsesPhaseThreeVersion(): void
    {
        $cache = new class extends CacheManager {
            public array $keys = [];

            public function __construct()
            {
            }

            public function get(string $key)
            {
                $this->keys[] = $key;
                return null;
            }

            public function set(string $key, $value, int $ttl = 300): void
            {
                $this->keys[] = $key;
            }
        };

        (new BeginnerGuidanceService($cache))->guidanceFor(1, $this->userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
        ]);

        $this->assertNotEmpty(array_filter(
            $cache->keys,
            static fn(string $key): bool => str_contains($key, 'beginner_guidance:' . BeginnerGuidanceService::CACHE_VERSION . ':')
        ));
        $this->assertFalse((bool) array_filter(
            $cache->keys,
            static fn(string $key): bool => str_contains($key, 'beginner_guidance:v1:')
        ));
    }

    private function guidance(): array
    {
        return $this->service->guidanceFor(1, $this->userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
    }

    private function resetGuidanceTables(): void
    {
        foreach ([
            'communications',
            'tasks',
            'deals',
            'contacts',
            'email_integrations',
            'workspace_whatsapp_integrations',
            'activation_progress',
        ] as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }
            $where = Database::columnExists($table, 'workspace_id') ? ' WHERE workspace_id = 1' : '';
            Database::execute('DELETE FROM ' . $table . $where);
        }
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [$this->uuid(), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(string $firstName, string $lastName): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_by, created_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $this->uuid(),
                $firstName,
                $lastName,
                strtolower($firstName . '.' . $lastName) . '@example.test',
                $this->userId,
                $this->userId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
