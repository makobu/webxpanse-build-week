<?php

namespace Tests\Unit\Services;

use CRM\CacheManager;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\UIExperienceService;
use PHPUnit\Framework\TestCase;

class BeginnerWorkSurfaceGuidanceServiceTest extends TestCase
{
    public function testInboxWaitingInboundReturnsConversationAction(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'inbox',
            'current_page' => 'inbox.php',
            'communications' => [
                ['id' => 12, 'direction' => 'inbound', 'read_at' => null, 'channel' => 'email'],
            ],
        ]);

        $this->assertTrue($payload['show_guidance']);
        $this->assertSame('Reply to this customer', $payload['goal']);
        $this->assertSame('conversation.php?id=12', $payload['primary_action']['href']);
    }

    public function testTaskQueueRanksOverdueTaskBeforeEmptyFallback(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'tasks',
            'current_page' => 'tasks.php',
            'task_groups' => [
                'overdue' => ['items' => [['id' => 44, 'due_date' => '2025-01-01 09:00:00']]],
                'due_soon' => ['items' => [['id' => 55, 'due_date' => '2030-01-01 09:00:00']]],
            ],
            'can_write_tasks' => true,
        ]);

        $this->assertSame('Finish this overdue follow-up', $payload['goal']);
        $this->assertSame('task_view.php?id=44', $payload['primary_action']['href']);
    }

    public function testEmptyContactsRoutesToCreateCustomer(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'contacts',
            'current_page' => 'contacts.php',
            'total_count' => 0,
        ]);

        $this->assertSame('Add your first customer', $payload['goal']);
        $this->assertStringContainsString('contacts_create.php', $payload['primary_action']['href']);
    }

    public function testInvoiceBlockedStateRoutesToFinanceSetup(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'invoices',
            'current_page' => 'invoices.php',
            'invoice_ready' => false,
        ]);

        $this->assertSame('Set up invoices', $payload['goal']);
        $this->assertSame('workspace_skills.php?module=finance#setup', $payload['primary_action']['href']);
    }

    public function testSamePageCreateSurfaceHidesCta(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'invoice_create',
            'current_page' => 'invoice_create.php',
            'document_type' => 'invoice',
        ]);

        $this->assertSame('Create this invoice', $payload['goal']);
        $this->assertSame([], $payload['primary_action']);
    }

    public function testAdvancedModeDoesNotShowBeginnerGuidance(): void
    {
        $payload = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor(1, 7, [
            'skip_cache' => true,
            'mode' => UIExperienceService::MODE_ADVANCED,
            'surface' => 'contacts',
            'current_page' => 'contacts.php',
            'total_count' => 0,
        ]);

        $this->assertFalse($payload['show_guidance']);
    }

    public function testCacheKeyUsesVersionedPrefix(): void
    {
        $cache = new class extends CacheManager {
            /** @var array<int,string> */
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

        (new BeginnerWorkSurfaceGuidanceService($cache))->guidanceFor(1, 7, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => 'contacts',
            'current_page' => 'contacts.php',
            'total_count' => 0,
        ]);

        $this->assertNotEmpty(array_filter(
            $cache->keys,
            static fn(string $key): bool => str_contains($key, 'work_surface_guidance:' . BeginnerWorkSurfaceGuidanceService::CACHE_VERSION . ':')
        ));
    }
}
