<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\AIContextAssemblyService;
use CRM\Services\AIOperatingContextService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOperatingBriefService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceOperatingBriefServiceTest extends DatabaseTestCase
{
    public function testGeneratesBriefWithCoreSectionsAndMissingContext(): void
    {
        $provisioned = $this->provisionWorkspace('brief-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        (new CompanyProfile())->update([
            'company_name' => 'Brief Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Brief Co helps teams follow up faster.',
            'company_website' => 'https://brief.example',
        ]);
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, pricing_info, target_audience, is_active)
             VALUES (?, ?, ?, ?, ?, 1)",
            [$workspaceId, 'Follow-up System', 'A CRM operating workflow.', 'Starts at 500 USD', 'Service teams']
        );

        $brief = (new WorkspaceOperatingBriefService())->generate($workspaceId, $userId);

        $this->assertSame('Brief Co', $brief['sections']['company']['name'] ?? '');
        $this->assertSame('Follow-up System', $brief['sections']['customer_and_offer']['offer_name'] ?? '');
        $this->assertArrayHasKey('voice_and_tone', $brief['sections']);
        $this->assertArrayHasKey('language_level', $brief['sections']['voice_and_tone']);
        $this->assertArrayHasKey('automation_preferences', $brief['sections']);
        $this->assertArrayHasKey('channels', $brief['sections']);
        $this->assertArrayHasKey('automation', $brief['sections']);
        $this->assertArrayHasKey('deferred_operational_setup', $brief['sections']);
        $this->assertArrayHasKey('billing_readiness', $brief['sections']);
        $this->assertNotEmpty($brief['missing_context']);
        $this->assertStringContainsString('Workspace Operating Brief', (string) ($brief['markdown'] ?? ''));

        $latest = (new WorkspaceOperatingBriefService())->latest($workspaceId);
        $this->assertIsArray($latest);
        $this->assertSame($brief['generated_at'], $latest['generated_at'] ?? null);
    }

    public function testOnboardingStateMarksIncompleteAndOperationalWorkspaces(): void
    {
        $provisioned = $this->provisionWorkspace('brief-state-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOperatingBriefService();
        $state = $service->buildOnboardingState($workspaceId, $userId);
        $this->assertFalse((bool) ($state['is_operational'] ?? true));
        $this->assertNotEmpty($state['setup_actions']);

        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 readiness_score = 100,
                 completed_steps_json = ?,
                 skipped_optional_json = JSON_ARRAY(),
                 optional_setup_json = JSON_OBJECT('lean_canvas', JSON_OBJECT('enabled', true), 'invoice_setup', JSON_OBJECT('enabled', true, 'has_payment_instructions', true)),
                 communication_channel = 'email',
                 technical_level = 'run_quietly',
                 automation_launch_mode = 'full_auto',
                 launch_summary_json = JSON_OBJECT('generated_at', NOW()),
                 completed_at = NOW()
             WHERE workspace_id = ?",
            [json_encode(['company', 'products', 'voice', 'automation', 'review']), $workspaceId]
        );
        $_ENV['SMTP_HOST'] = 'smtp.example.test';
        $_ENV['SMTP_USERNAME'] = 'owner@example.test';
        (new CompanyProfile())->update([
            'company_name' => 'Operational Co',
            'company_industry' => 'Services',
            'company_description' => 'A complete operating workspace.',
        ]);
        (new UserStrategyProfile())->save($userId, [
            'ideal_customer_profile' => 'Complete buyers',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
        ]);
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, pricing_info, target_audience, is_active)
             VALUES (?, ?, ?, ?, ?, 1)",
            [$workspaceId, 'Complete Offer', 'Complete offer.', '100 USD', 'Complete buyers']
        );
        (new CompanyProfile())->update([
            'company_legal_name' => 'Operational Co',
        ]);
        (new InvoiceSettings())->save([
            'enabled' => true,
            'bank_instructions' => 'Pay by bank transfer',
        ]);
        Database::execute(
            "UPDATE deal_automation_config SET enabled = 1, mode = 'auto_safe' WHERE id = 1"
        );
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'sales', NOW())",
            ['teammate@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $teamUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, created_at)
             VALUES (?, ?, 'admin', 'active', NOW())",
            [$workspaceId, $teamUserId]
        );

        $state = $service->buildOnboardingState($workspaceId, $userId);
        $this->assertTrue((bool) ($state['is_operational'] ?? false));
        $this->assertGreaterThanOrEqual(80, (int) ($state['operational_score'] ?? 0));
    }

    public function testClarityContextIncludesOnboardingStateBlock(): void
    {
        $provisioned = $this->provisionWorkspace('brief-context-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $operating = (new AIOperatingContextService())->buildForSurface($userId, 'clarity_chat');
        $this->assertArrayHasKey('onboarding_state', $operating);
        $this->assertFalse((bool) ($operating['onboarding_state']['is_operational'] ?? true));

        $bundle = (new AIContextAssemblyService())->buildContextBundle('clarity_chat', 'clarity_question_answer', [
            'user_id' => $userId,
            'current_page' => 'dashboard.php',
            'question' => 'What should I do next?',
        ]);
        $blocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'onboarding_state'));
        $this->assertCount(1, $blocks);
        $this->assertSame('required', $blocks[0]['block_priority'] ?? null);
    }

    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Operating Brief ' . uniqid('', true),
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }
}
