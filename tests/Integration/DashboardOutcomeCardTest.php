<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Services\PlainLanguageReadinessService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class DashboardOutcomeCardTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testDashboardKeepsActivationProgressBeforeActivationIsComplete(): void
    {
        $seed = $this->seedOutcomeWorkspace('outcome-card-incomplete');
        $this->enableOutcomeLayer();

        Database::execute(
            "INSERT INTO activation_progress (user_id, first_login_at)
             VALUES (?, NOW())",
            [(int) $seed['user_id']]
        );
        $readiness = (new PlainLanguageReadinessService())->summaryFor(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            ['mode' => UIExperienceService::MODE_BEGINNER, 'skip_cache' => true]
        );

        $response = $this->runWebEndpoint('public/dashboard.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame('setup', (string) ($readiness['phase'] ?? ''));
        $this->assertSame('deterministic', (string) ($readiness['source'] ?? ''));
        $this->assertSame('Profile and products saved', (string) (($readiness['milestones'][0]['label'] ?? '')));
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Setup Progress', $body);
        $this->assertStringContainsString('data-plain-readiness-card', $body);
        $this->assertStringNotContainsString('Business basics saved', $body);
        $this->assertStringNotContainsString('first_login', $body);
    }

    public function testDashboardShowsOutcomeCardForLegacyDefaultConfig(): void
    {
        $seed = $this->seedOutcomeWorkspace('outcome-card-default');

        Database::execute(
            "INSERT INTO activation_progress (user_id, first_login_at)
             VALUES (?, NOW())",
            [(int) $seed['user_id']]
        );

        $response = $this->runWebEndpoint('public/dashboard.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Setup Progress', $body);
        $this->assertStringContainsString('TTFV (14d)', $body);
    }

    public function testDashboardStillShowsOutcomeCardWhenRolloutIsDisabled(): void
    {
        $seed = $this->seedOutcomeWorkspace('outcome-card-disabled');
        (new DealAutomationConfig())->save([
            'enabled' => false,
            'mode' => 'suggest_only',
            'outcome_layer_enabled' => false,
            'outcome_layer_checklist_enabled' => false,
            'outcome_layer_daily_focus_enabled' => false,
            'outcome_layer_metrics_enabled' => false,
            'outcome_layer_rollout_percent' => 0,
        ]);

        Database::execute(
            "INSERT INTO activation_progress (user_id, first_login_at)
             VALUES (?, NOW())",
            [(int) $seed['user_id']]
        );

        $response = $this->runWebEndpoint('public/dashboard.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Setup Progress', $body);
        $this->assertStringContainsString('TTFV (14d)', $body);
    }

    public function testDashboardShowsRevenueMomentumAfterCurrentSetupIsComplete(): void
    {
        $seed = $this->seedOutcomeWorkspace('outcome-card-complete');
        $this->enableOutcomeLayer();
        $userId = (int) $seed['user_id'];
        $workspaceId = (int) $seed['workspace_id'];
        $this->completeCurrentSetupSignals($workspaceId, $userId);
        $readiness = (new PlainLanguageReadinessService())->summaryFor(
            $workspaceId,
            $userId,
            ['mode' => UIExperienceService::MODE_BEGINNER, 'skip_cache' => true]
        );

        $response = $this->runWebEndpoint('public/dashboard.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame('revenue_momentum', (string) ($readiness['phase'] ?? ''), json_encode($readiness));
        $this->assertSame('dashboard_momentum', (string) ($readiness['source'] ?? ''));
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Revenue Momentum', $body);
        $this->assertStringContainsString('moving today', $body);
        $this->assertStringContainsString('Add more leads', $body);
        $this->assertStringContainsString('Complete one revenue action', $body);
        $this->assertStringContainsString('display: none; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.5rem;" data-plain-readiness-actions', $body);
        $this->assertStringContainsString('Follow-ups clear', $body);
        $this->assertStringNotContainsString('followups_clear', $body);
        $this->assertStringNotContainsString('>Create deal</a>', $body);
        $this->assertStringNotContainsString('Activation Progress', $body);
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedOutcomeWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Outcome Card Workspace ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Outcome',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        (new WorkspaceOnboardingService())->completeQuickStart($workspaceId, $userId, [
            'company_name' => 'Outcome Card Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Outcome Card Co helps teams move pipeline faster.',
            'communication_channel' => 'email',
            'product_name' => 'Momentum Engine',
            'product_description' => 'A practical follow-up and pipeline service.',
            'ideal_customer_profile' => 'Growing teams with inconsistent sales follow-up.',
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'warm',
            'escalation_preference' => 'Urgent customer issues',
        ]);

        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
        ];
    }

    private function enableOutcomeLayer(): void
    {
        (new DealAutomationConfig())->save([
            'enabled' => false,
            'mode' => 'suggest_only',
            'outcome_layer_enabled' => true,
            'outcome_layer_checklist_enabled' => true,
            'outcome_layer_daily_focus_enabled' => true,
            'outcome_layer_metrics_enabled' => true,
            'outcome_layer_rollout_percent' => 100,
        ]);
    }

    private function completeCurrentSetupSignals(int $workspaceId, int $userId): void
    {
        $onboarding = new WorkspaceOnboardingService();
        $onboarding->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Outcome Card Co',
            'company_industry' => 'Professional services',
            'company_location' => 'Nairobi',
            'company_website' => 'https://outcome-card.example.test',
            'company_description' => 'Outcome Card Co helps teams move pipeline faster.',
            'success_outcome' => 'Teams follow up consistently and move revenue forward.',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 2, [
            'product_name' => ['Momentum Engine'],
            'product_description' => ['A practical follow-up and pipeline service.'],
            'target_audience' => ['Growing teams with inconsistent sales follow-up.'],
            'pricing_info' => ['Starts at 500 USD'],
            'ideal_customer_profile' => 'Growing teams with inconsistent sales follow-up.',
            'offer_angle' => 'Keep the revenue loop moving every day.',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'escalation_preference' => 'Urgent customer issues',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'guide_me',
            'ai_best_practices_enabled' => '1',
        ]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_by, created_at)
             VALUES (?, ?, 'Momentum', 'Lead', ?, ?, ?, NOW())",
            [
                $workspaceId,
                uniqid('momentum-contact-', true),
                'momentum.lead.' . $workspaceId . '@example.test',
                $userId,
                $userId,
            ]
        );

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
            [$workspaceId, 'momentum.mail.' . $workspaceId . '@example.test', $userId]
        );

        (new CompanyProfile())->update([
            'company_legal_name' => 'Outcome Card Co Ltd',
            'company_email' => 'billing@outcome-card.example.test',
            'company_address' => '500 Outcome Street, Nairobi',
        ]);

        (new InvoiceSettings())->save([
            'enabled' => true,
            'default_currency' => 'USD',
            'default_payment_terms_days' => 14,
            'invoice_prefix' => 'INV-',
            'bank_instructions' => 'Pay by bank transfer.',
        ], $userId);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'outcome-card-user',
            'user_email' => 'outcome.card.owner@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-dashboard-outcome-card',
        ];
    }
}
