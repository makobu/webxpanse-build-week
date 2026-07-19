<?php

namespace CRM\Tests\Unit\Services;

use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\OutcomeMetrics;
use CRM\Modules\Products;
use CRM\Services\PlainLanguageReadinessService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class PlainLanguageReadinessServiceTest extends DatabaseTestCase
{
    private array $seed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed = $this->provisionWorkspace('plain-readiness-owner');
        WorkspaceContext::activateRuntimeWorkspace(
            (int) $this->seed['workspace_id'],
            (int) $this->seed['user_id'],
            'owner'
        );
    }

    public function testRawActivationKeysAreTranslatedInFallback(): void
    {
        Database::execute(
            "INSERT INTO activation_progress (user_id, first_login_at)
             VALUES (?, NOW())",
            [(int) $this->seed['user_id']]
        );

        $payload = (new PlainLanguageReadinessService())->fallbackPayload(
            (int) $this->seed['user_id'],
            UIExperienceService::MODE_BEGINNER
        );
        $labels = implode(' | ', array_column((array) ($payload['milestones'] ?? []), 'label'));

        $this->assertSame('Setup Progress', $payload['headline_label']);
        $this->assertSame('setup', $payload['phase']);
        $this->assertStringContainsString('Signed in', $labels);
        $this->assertStringContainsString('Connect email or WhatsApp', $labels);
        $this->assertStringNotContainsString('first_login', $labels);
        $this->assertStringNotContainsString('first_followup', $labels);
        $this->assertStringNotContainsString('Revenue Momentum', json_encode($payload) ?: '');
    }

    public function testCompanyNameAloneDoesNotCompleteProfileAndProductsMilestone(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        (new CompanyProfile())->update([
            'company_name' => 'Name Only Co',
        ]);

        $payload = $this->readinessWithFailingMomentum()->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $firstMilestone = (array) (($payload['milestones'] ?? [])[0] ?? []);

        $this->assertSame('Profile and products saved', $firstMilestone['label'] ?? '');
        $this->assertFalse((bool) ($firstMilestone['complete'] ?? true));
        $this->assertSame('company_profile', $payload['primary_gap']['key']);
        $this->assertSame('settings.php?tab=company', $payload['primary_gap']['href']);
    }

    public function testProductWithoutPricingOrTargetAudienceDoesNotCompleteProfileAndProductsMilestone(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        (new CompanyProfile())->update([
            'company_name' => 'Profile Complete Co',
            'company_description' => 'Profile Complete Co helps service teams follow up with customers.',
            'company_location' => 'Nairobi',
        ]);
        (new Products())->create([
            'name' => 'Support Package',
        ]);

        $payload = $this->readinessWithFailingMomentum()->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $firstMilestone = (array) (($payload['milestones'] ?? [])[0] ?? []);

        $this->assertSame('Profile and products saved', $firstMilestone['label'] ?? '');
        $this->assertFalse((bool) ($firstMilestone['complete'] ?? true));
        $this->assertSame('product_catalog', $payload['primary_gap']['key']);
        $this->assertSame('settings.php?tab=products', $payload['primary_gap']['href']);
    }

    public function testMissingChannelUsesOwnerLanguage(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeCoreSetup($workspaceId, $userId);
        $this->createContact($workspaceId, $userId);

        $payload = (new PlainLanguageReadinessService())->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);

        $this->assertSame('channel', $payload['primary_gap']['key']);
        $this->assertSame('Connect email or WhatsApp', $payload['primary_gap']['label']);
        $this->assertSame('Blocked until a channel is connected', $payload['primary_gap']['status_label']);
        $this->assertSame('workspace_skills.php?module=email&setup_tab=outreach_email#setup', $payload['primary_gap']['href']);
    }

    public function testInvoiceReadyStateReturnsReadyToCreateInvoicesMilestone(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeCoreSetup($workspaceId, $userId);
        $this->createContact($workspaceId, $userId);
        $this->connectEmail($workspaceId, $userId);
        $this->saveReadyInvoiceSettings($userId);

        $payload = $this->readinessWithFailingMomentum()->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $money = $this->milestoneByKey($payload, 'money');

        $this->assertSame('Ready to create invoices', $money['label'] ?? '');
        $this->assertTrue((bool) ($money['complete'] ?? false));
    }

    public function testInvoiceEnabledWithOnlyPaymentInstructionsIsNotReady(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeCoreSetup($workspaceId, $userId);
        $this->createContact($workspaceId, $userId);
        $this->connectEmail($workspaceId, $userId);
        (new InvoiceSettings())->save([
            'enabled' => true,
            'bank_instructions' => 'Pay by bank transfer.',
        ], $userId);

        $payload = (new PlainLanguageReadinessService())->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $money = $this->milestoneByKey($payload, 'document_identity');

        $this->assertSame('document_identity', $payload['primary_gap']['key']);
        $this->assertSame('settings.php?tab=company', $payload['primary_gap']['href']);
        $this->assertFalse((bool) ($money['complete'] ?? true));
    }

    public function testInvoiceIdentityReadyWithoutPaymentInstructionsPointsToInvoicing(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeCoreSetup($workspaceId, $userId);
        $this->createContact($workspaceId, $userId);
        $this->connectEmail($workspaceId, $userId);
        (new CompanyProfile())->update([
            'company_legal_name' => 'Plain Readiness Co Ltd',
            'company_email' => 'billing@plain-readiness.example.test',
            'company_address' => '123 Readiness Street, Nairobi',
        ]);
        (new InvoiceSettings())->save([
            'enabled' => true,
            'default_currency' => 'USD',
            'default_payment_terms_days' => 14,
            'invoice_prefix' => 'INV-',
            'bank_instructions' => '',
        ], $userId);

        $payload = (new PlainLanguageReadinessService())->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $money = $this->milestoneByKey($payload, 'money');

        $this->assertSame('money', $payload['primary_gap']['key']);
        $this->assertSame('settings.php?tab=invoicing', $payload['primary_gap']['href']);
        $this->assertSame('Ready to create invoices', $money['label'] ?? '');
        $this->assertFalse((bool) ($money['complete'] ?? true));
    }

    public function testAiContextIncompleteDoesNotExposeInternalReadinessTerms(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];

        $payload = (new PlainLanguageReadinessService())->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertSame('Setup Progress', $payload['headline_label']);
        $this->assertSame('setup', $payload['phase']);
        $this->assertStringContainsString('AI understands the business', $body);
        $this->assertStringNotContainsString('context_quality', $body);
        $this->assertStringNotContainsString('automation battery', strtolower($body));
    }

    public function testCompletedOnboardingAloneDoesNotClaimAiUnderstandsBusiness(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $onboarding = new WorkspaceOnboardingService();
        $onboarding->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Thin Context Co',
            'company_description' => 'Thin Context Co serves customers with useful operations support.',
            'company_location' => 'Nairobi',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 2, [
            'product_name' => ['Operations Support'],
            'product_description' => ['A service package.'],
            'target_audience' => ['Growing customer service teams'],
            'pricing_info' => ['Starts at 300 USD'],
        ]);
        $onboarding->saveStep($workspaceId, $userId, 5, []);
        $this->createContact($workspaceId, $userId);
        $this->connectEmail($workspaceId, $userId);
        $this->saveReadyInvoiceSettings($userId);

        $payload = $this->readinessWithFailingMomentum()->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);
        $aiContext = $this->milestoneByKey($payload, 'ai_context');

        $this->assertSame('ai_context', $payload['primary_gap']['key']);
        $this->assertFalse((bool) ($aiContext['complete'] ?? true));
    }

    public function testStrictSetupCanReportSixOfSixReadyWhenMomentumIsUnavailable(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeAllSetup($workspaceId, $userId);

        $payload = $this->readinessWithFailingMomentum()->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);

        $this->assertSame('setup', $payload['phase']);
        $this->assertSame('6 of 6 ready', $payload['progress_label']);
        $this->assertCount(6, array_filter(
            (array) ($payload['milestones'] ?? []),
            static fn(array $item): bool => !empty($item['complete'])
        ));
    }

    public function testCompleteSetupSwitchesToRevenueMomentum(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeAllSetup($workspaceId, $userId);

        $payload = $this->readinessWithMomentum($this->momentumItems([
            ['due_followups', false, 2],
            ['stale_deals', false, 1],
            ['refill_pipeline', false, 0],
            ['add_leads', false, 0],
            ['move_deal', false, 0],
            ['action_gap', false, 0],
        ]))->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);

        $this->assertSame('revenue_momentum', $payload['phase']);
        $this->assertSame('Revenue Momentum', $payload['headline_label']);
        $this->assertSame('0 of 6 moving today', $payload['progress_label']);
        $this->assertSame('dashboard_momentum', $payload['source']);
        $this->assertSame('due_followups', $payload['primary_gap']['key']);
        $this->assertSame('Open follow-ups', $payload['primary_gap']['cta_label']);
        $this->assertSame('tasks.php?overdue=1', $payload['primary_gap']['href']);
        $labels = implode(' | ', array_column((array) ($payload['milestones'] ?? []), 'label'));
        $this->assertStringContainsString('Finish due follow-ups', $labels);
        $this->assertStringContainsString('Create your first lead', $labels);
    }

    public function testLegacyActivationFallbackCompletionDoesNotTriggerMomentum(): void
    {
        Database::execute(
            "INSERT INTO activation_progress (
                user_id,
                first_login_at,
                connected_channel_at,
                first_contact_at,
                first_inbound_at,
                first_followup_task_completed_at,
                first_deal_created_at
             ) VALUES (?, NOW(), NOW(), NOW(), NOW(), NOW(), NOW())",
            [(int) $this->seed['user_id']]
        );

        $payload = (new PlainLanguageReadinessService())->fallbackPayload(
            (int) $this->seed['user_id'],
            UIExperienceService::MODE_BEGINNER
        );

        $this->assertSame('setup', $payload['phase']);
        $this->assertSame('Setup Progress', $payload['headline_label']);
        $this->assertSame('6 of 6 ready', $payload['progress_label']);
        $this->assertSame('activation_progress_fallback', $payload['source']);
        $this->assertStringNotContainsString('Revenue Momentum', json_encode($payload) ?: '');
    }

    public function testMomentumPrimaryActionPriorityMovesFromFollowupsToStaleDealsToPipelineToLeads(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeAllSetup($workspaceId, $userId);

        $scenarios = [
            'due_followups' => [
                ['due_followups', false, 1],
                ['stale_deals', false, 1],
                ['refill_pipeline', false, 0],
                ['add_leads', false, 0],
                ['move_deal', false, 0],
                ['action_gap', false, 0],
            ],
            'stale_deals' => [
                ['followups_clear', true, 0],
                ['stale_deals', false, 1],
                ['refill_pipeline', false, 0],
                ['add_leads', false, 0],
                ['move_deal', false, 0],
                ['action_gap', false, 0],
            ],
            'refill_pipeline' => [
                ['followups_clear', true, 0],
                ['no_stale_deals', true, 0],
                ['refill_pipeline', false, 0],
                ['add_leads', false, 0],
                ['move_deal', false, 0],
                ['action_gap', false, 0],
            ],
            'add_leads' => [
                ['followups_clear', true, 0],
                ['no_stale_deals', true, 0],
                ['pipeline_active', true, 1],
                ['add_leads', false, 0],
                ['move_deal', false, 0],
                ['action_gap', false, 0],
            ],
        ];

        foreach ($scenarios as $expectedKey => $items) {
            $payload = $this->readinessWithMomentum($this->momentumItems($items))->summaryFor($workspaceId, $userId, [
                'mode' => UIExperienceService::MODE_BEGINNER,
                'skip_cache' => true,
            ]);
            $this->assertSame($expectedKey, $payload['primary_gap']['key']);
        }
    }

    public function testHealthyMomentumReturnsDailyLoopMoving(): void
    {
        $workspaceId = (int) $this->seed['workspace_id'];
        $userId = (int) $this->seed['user_id'];
        $this->completeAllSetup($workspaceId, $userId);

        $payload = $this->readinessWithMomentum($this->momentumItems([
            ['followups_clear', true, 0],
            ['no_stale_deals', true, 0],
            ['pipeline_active', true, 2],
            ['lead_refill', true, 5],
            ['deal_movement', true, 2],
            ['revenue_action', true, 3],
        ]))->summaryFor($workspaceId, $userId, [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'skip_cache' => true,
        ]);

        $this->assertSame('revenue_momentum', $payload['phase']);
        $this->assertSame('6 of 6 moving today', $payload['progress_label']);
        $this->assertSame('daily_loop_moving', $payload['primary_gap']['key']);
        $this->assertSame('Daily loop is moving', $payload['primary_gap']['status_label']);
        $this->assertSame('Your setup is complete and today\'s revenue loop is moving.', $payload['primary_gap']['reason']);
        $this->assertCount(6, array_filter(
            (array) ($payload['milestones'] ?? []),
            static fn(array $item): bool => !empty($item['complete'])
        ));
    }

    public function testAdvancedModeCanRetainAdvancedHeadline(): void
    {
        $payload = (new PlainLanguageReadinessService())->summaryFor(
            (int) $this->seed['workspace_id'],
            (int) $this->seed['user_id'],
            [
                'mode' => UIExperienceService::MODE_ADVANCED,
                'skip_cache' => true,
            ]
        );

        $this->assertSame(UIExperienceService::MODE_ADVANCED, $payload['mode']);
        $this->assertSame('Activation Progress', $payload['headline_label']);
    }

    public function testCacheKeyUsesPlainReadinessVersion(): void
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

        (new PlainLanguageReadinessService($cache))->summaryFor(
            (int) $this->seed['workspace_id'],
            (int) $this->seed['user_id'],
            ['mode' => UIExperienceService::MODE_BEGINNER]
        );

        $this->assertNotEmpty(array_filter(
            $cache->keys,
            static fn(string $key): bool => str_contains($key, 'plain_readiness:' . PlainLanguageReadinessService::CACHE_VERSION . ':')
        ));
    }

    private function completeCoreSetup(int $workspaceId, int $userId): void
    {
        $service = new WorkspaceOnboardingService();
        $service->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Plain Readiness Co',
            'company_industry' => 'Home services',
            'company_location' => 'Nairobi',
            'company_website' => 'https://plain-readiness.example.test',
            'company_description' => 'Plain Readiness Co helps local owners finish customer work.',
            'success_outcome' => 'Customers get clear updates and reliable service.',
        ]);
        $service->saveStep($workspaceId, $userId, 2, [
            'product_name' => ['Service visit'],
            'product_description' => ['A practical service visit.'],
            'target_audience' => ['Local homeowners'],
            'pricing_info' => ['Starts at 100 USD'],
            'ideal_customer_profile' => 'Local homeowners who need reliable help.',
            'offer_angle' => 'Fast, clear service follow-up.',
        ]);
        $service->saveStep($workspaceId, $userId, 3, [
            'relationship_style' => 'friendly_operator',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'escalation_preference' => 'Complaints and urgent scheduling issues',
        ]);
        $service->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'guide_me',
            'ai_best_practices_enabled' => '1',
        ]);
    }

    private function completeAllSetup(int $workspaceId, int $userId): void
    {
        $this->completeCoreSetup($workspaceId, $userId);
        $this->createContact($workspaceId, $userId);
        $this->connectEmail($workspaceId, $userId);
        $this->saveReadyInvoiceSettings($userId);
    }

    private function saveReadyInvoiceSettings(int $userId): void
    {
        (new CompanyProfile())->update([
            'company_legal_name' => 'Plain Readiness Co Ltd',
            'company_email' => 'billing@plain-readiness.example.test',
            'company_address' => '123 Readiness Street, Nairobi',
        ]);

        (new InvoiceSettings())->save([
            'enabled' => true,
            'default_currency' => 'USD',
            'default_payment_terms_days' => 14,
            'invoice_prefix' => 'INV-',
            'bank_instructions' => 'Pay by bank transfer.',
        ], $userId);
    }

    private function connectEmail(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO email_integrations (workspace_id, provider, scope, access_token, email_address, is_active, connected_by_user_id, settings_json)
             VALUES (?, 'manual_smtp', 'main_email', 'test-token', ?, 1, ?, '{}')
             ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                email_address = VALUES(email_address),
                is_active = VALUES(is_active),
                connected_by_user_id = VALUES(connected_by_user_id),
                settings_json = VALUES(settings_json),
                updated_at = NOW()",
            [$workspaceId, 'plain.email.' . $workspaceId . '@example.test', $userId]
        );
    }

    private function createContact(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_by, created_at)
             VALUES (?, ?, 'Plain', 'Customer', ?, ?, ?, NOW())",
            [
                $workspaceId,
                $this->uuid(),
                'plain.customer.' . $workspaceId . '@example.test',
                $userId,
                $userId,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function milestoneByKey(array $payload, string $key): array
    {
        foreach ((array) ($payload['milestones'] ?? []) as $milestone) {
            if ((string) ($milestone['key'] ?? '') === $key) {
                return (array) $milestone;
            }
        }

        return [];
    }

    /**
     * @param array<int,array{0:string,1:bool,2:int}> $items
     * @return array<int,array<string,mixed>>
     */
    private function momentumItems(array $items): array
    {
        return array_map(static fn(array $item): array => [
            'step_key' => $item[0],
            'complete' => $item[1],
            'count' => $item[2],
        ], $items);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function readinessWithMomentum(array $items): PlainLanguageReadinessService
    {
        $metrics = new class($items) extends OutcomeMetrics {
            /**
             * @param array<int,array<string,mixed>> $items
             */
            public function __construct(private array $items)
            {
            }

            public function getRevenueMomentumChecklist(int $userId): array
            {
                return $this->items;
            }
        };

        return new PlainLanguageReadinessService(null, null, null, $metrics);
    }

    private function readinessWithFailingMomentum(): PlainLanguageReadinessService
    {
        $metrics = new class extends OutcomeMetrics {
            public function getRevenueMomentumChecklist(int $userId): array
            {
                throw new \RuntimeException('Momentum unavailable for setup fallback test.');
            }
        };

        return new PlainLanguageReadinessService(null, null, null, $metrics);
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function provisionWorkspace(string $emailPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Plain Readiness ' . $suffix,
            'workspace_slug' => $emailPrefix . '-' . $suffix,
            'first_name' => 'Plain',
            'last_name' => 'Owner',
            'email' => $emailPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        return [
            'workspace_id' => (int) ($provisioned['workspace_id'] ?? 0),
            'user_id' => (int) ($provisioned['user_id'] ?? 0),
        ];
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
