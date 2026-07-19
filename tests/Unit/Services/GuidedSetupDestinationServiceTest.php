<?php

namespace CRM\Tests\Unit\Services;

use CRM\CacheManager;
use CRM\Services\GuidedSetupDestinationService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class GuidedSetupDestinationServiceTest extends DatabaseTestCase
{
    public function testChannelReadinessGapMapsToEmailSetupEntryPoint(): void
    {
        $payload = (new GuidedSetupDestinationService())->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'source' => 'dashboard_readiness',
            'gap' => 'channel',
            'skip_cache' => true,
        ]);

        $this->assertTrue((bool) ($payload['show_guidance'] ?? false));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL, $payload['module_key']);
        $this->assertSame('Connect email or WhatsApp', $payload['headline']);
        $this->assertSame('Blocked until a channel is connected', $payload['status_label']);
        $this->assertSame('workspace_skills.php?module=email&setup_tab=outreach_email#setup', $payload['primary_step']['href']);
    }

    public function testFinanceGapMapsToInvoiceSetup(): void
    {
        $payload = (new GuidedSetupDestinationService())->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'source' => 'dashboard_readiness',
            'gap' => 'money',
            'skip_cache' => true,
        ]);

        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_FINANCE, $payload['module_key']);
        $this->assertSame('Set up invoices', $payload['headline']);
        $this->assertSame('Needs invoice settings first', $payload['status_label']);
        $this->assertSame('workspace_skills.php?module=finance#setup', $payload['primary_step']['href']);
    }

    public function testAddCapabilitiesFallbackReturnsFocusedRecommendations(): void
    {
        $payload = (new GuidedSetupDestinationService())->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'source' => 'dashboard_guidance',
            'action' => 'add_capabilities_when_blocked',
            'recommendations' => [
                [
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                    'label' => 'Email',
                    'why_now' => 'Marketplace campaign workspace routing is incomplete.',
                    'priority' => 'high',
                ],
                [
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                    'label' => 'Finance',
                    'why_now' => 'Invoice settings are incomplete.',
                    'priority' => 'medium',
                ],
                [
                    'skill_key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                    'label' => 'AI Coach',
                    'why_now' => 'Guidance setup is incomplete.',
                    'priority' => 'medium',
                ],
                [
                    'skill_key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                    'label' => 'Marketing Assistants',
                    'why_now' => 'Campaign workspace context is incomplete.',
                    'priority' => 'low',
                ],
            ],
            'skip_cache' => true,
        ]);

        $this->assertSame('Add only the capability that unblocks work', $payload['headline']);
        $this->assertLessThanOrEqual(3, count((array) ($payload['recommended_modules'] ?? [])));
        $this->assertSame('Connect email or WhatsApp', $payload['recommended_modules'][0]['label'] ?? '');
        $this->assertStringNotContainsString('campaign workspace', strtolower(json_encode($payload) ?: ''));
    }

    public function testNextActionBecomesPrimaryGuidedDestinationAndRecommendationsSkipIt(): void
    {
        $payload = (new GuidedSetupDestinationService())->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'source' => 'dashboard_guidance',
            'action' => 'add_capabilities_when_blocked',
            'next_action' => [
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'label' => 'Finish Email',
                'url' => 'workspace_skills.php?module=email',
                'kind' => 'finish_setup',
                'message' => 'Email needs Outreach or Nurture setup before email runtime is ready.',
                'module_label' => 'Email',
                'is_actionable' => true,
            ],
            'recommendations' => [
                [
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                    'label' => 'Email',
                    'why_now' => 'Email setup is incomplete.',
                    'priority' => 'high',
                ],
                [
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                    'label' => 'Finance',
                    'why_now' => 'Invoice settings are incomplete.',
                    'priority' => 'medium',
                ],
                [
                    'skill_key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                    'label' => 'AI Coach',
                    'why_now' => 'Guidance setup is incomplete.',
                    'priority' => 'medium',
                ],
            ],
            'skip_cache' => true,
        ]);

        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL, $payload['module_key']);
        $this->assertSame('Finish Email', $payload['headline']);
        $this->assertSame('Email needs Outreach or Nurture setup before email runtime is ready.', $payload['reason']);
        $this->assertSame('Setup step needs attention', $payload['status_label']);
        $this->assertSame('Finish Email', $payload['primary_step']['cta_label'] ?? '');
        $this->assertSame('workspace_skills.php?module=email', $payload['primary_step']['href'] ?? '');
        $secondaryLabels = array_map(
            static fn(array $module): string => (string) ($module['label'] ?? ''),
            (array) ($payload['recommended_modules'] ?? [])
        );
        $this->assertNotContains('Connect email or WhatsApp', $secondaryLabels);
        $this->assertContains('Set up invoices', $secondaryLabels);
    }

    public function testAdvancedModeDoesNotShowBeginnerDestinationGuidance(): void
    {
        $payload = (new GuidedSetupDestinationService())->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_ADVANCED,
            'module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            'skip_cache' => true,
        ]);

        $this->assertSame(UIExperienceService::MODE_ADVANCED, $payload['mode']);
        $this->assertFalse((bool) ($payload['show_guidance'] ?? true));
        $this->assertSame([], $payload['steps']);
    }

    public function testCacheKeyUsesGuidedDestinationVersion(): void
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

        (new GuidedSetupDestinationService($cache))->destinationFor(1, 1, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'source' => 'dashboard_readiness',
            'gap' => 'channel',
        ]);

        $this->assertNotEmpty(array_filter(
            $cache->keys,
            static fn(string $key): bool => str_contains($key, 'guided_setup_destination:' . GuidedSetupDestinationService::CACHE_VERSION . ':')
        ));
    }

    public function testCacheKeyIncludesNextActionIdentity(): void
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

        $service = new GuidedSetupDestinationService($cache);
        foreach ([
            ['skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL, 'label' => 'Finish Email', 'url' => 'workspace_skills.php?module=email'],
            ['skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE, 'label' => 'Finish Finance', 'url' => 'workspace_skills.php?module=finance'],
        ] as $nextAction) {
            $service->destinationFor(1, 1, [
                'mode' => UIExperienceService::MODE_BEGINNER,
                'source' => 'dashboard_guidance',
                'action' => 'add_capabilities_when_blocked',
                'next_action' => array_merge($nextAction, [
                    'kind' => 'finish_setup',
                    'message' => 'Setup is incomplete.',
                    'is_actionable' => true,
                ]),
            ]);
        }

        $this->assertCount(2, array_unique($cache->keys));
    }
}
