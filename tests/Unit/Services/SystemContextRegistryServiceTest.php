<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\SystemContextRegistryService;
use CRM\Tests\DatabaseTestCase;

class SystemContextRegistryServiceTest extends DatabaseTestCase
{
    public function testDefaultWorkspaceContractCentralizesPlatformOpsSettings(): void
    {
        $registry = new SystemContextRegistryService();

        $contract = $registry->defaultWorkspaceContract();
        $validation = $registry->validateDefaultWorkspaceSettings([
            'workspace_purpose' => 'platform_ops',
            'internal_channel_ready' => true,
            'internal_team_ready' => true,
            'owner_helpline_enabled' => true,
        ]);

        $this->assertSame(DefaultWorkspaceService::DEFAULT_ID, (int) ($contract['workspace_id'] ?? 0));
        $this->assertSame(DefaultWorkspaceService::DEFAULT_SLUG, (string) ($contract['slug'] ?? ''));
        $this->assertSame('platform_ops', (string) ($contract['purpose'] ?? ''));
        $this->assertTrue((bool) ($validation['ok'] ?? false));
        $this->assertSame([], (array) ($validation['missing'] ?? ['missing']));
        $this->assertSame([], (array) ($validation['mismatched'] ?? ['mismatched']));
    }

    public function testWorkspaceContextIdentifiesDefaultWorkspaceMode(): void
    {
        $context = (new SystemContextRegistryService())->workspaceContext(DefaultWorkspaceService::DEFAULT_ID);

        $this->assertTrue((bool) ($context['is_default_workspace'] ?? false));
        $this->assertSame('platform_ops', (string) ($context['purpose'] ?? ''));
        $this->assertSame('platform_ops_hq', (string) ($context['operating_mode']['mode'] ?? ''));
        $this->assertTrue((bool) ($context['settings_validation']['ok'] ?? false));
    }

    public function testPlatformOpsContextAndTemplateBaselineAreReusable(): void
    {
        $registry = new SystemContextRegistryService();
        $platformOps = $registry->platformOpsContext(DefaultWorkspaceService::DEFAULT_ID);
        $templates = $registry->defaultWorkspaceTemplateBaseline();

        $this->assertStringContainsString('platform success', (string) ($platformOps['mission'] ?? ''));
        $this->assertContains('workspace owners', (array) ($platformOps['primary_subjects'] ?? []));
        $this->assertArrayHasKey('qualified_workspace_lead', (array) ($platformOps['owner_contact_counts'] ?? []));
        $this->assertGreaterThanOrEqual(8, (int) ($templates['minimum_platform_ops_email_templates'] ?? 0));
        $this->assertContains(
            'platform_ops_security_review',
            (array) ($templates['required_platform_ops_workflow_template_keys'] ?? [])
        );
    }

    public function testOwnerContactPoliciesCentralizeDefaultWorkspaceScopes(): void
    {
        $registry = new SystemContextRegistryService();

        $lead = $registry->ownerContactPolicy(false);
        $customer = $registry->ownerContactPolicy(true);
        $inactive = $registry->inactiveOwnerContactPolicy();

        $this->assertSame('qualified_workspace_lead', (string) ($lead['scope'] ?? ''));
        $this->assertSame('qualified', (string) ($lead['stage'] ?? ''));
        $this->assertTrue((bool) ($lead['marketing_conversion_allowed'] ?? false));
        $this->assertSame('current_paying_customer', (string) ($customer['scope'] ?? ''));
        $this->assertSame('won', (string) ($customer['stage'] ?? ''));
        $this->assertFalse((bool) ($customer['marketing_conversion_allowed'] ?? true));
        $this->assertSame('inactive_workspace_owner', (string) ($inactive['scope'] ?? ''));
        $this->assertSame('ineligible', (string) ($inactive['customer_state'] ?? ''));
    }

    public function testAutomationDomainDefaultsCentralizeDomainPolicy(): void
    {
        $registry = new SystemContextRegistryService();

        $workflow = $registry->automationDomainDefaults('workflow_execution');
        $customerCare = $registry->automationDomainDefaults('customer_care');
        $unknown = $registry->automationDomainDefaults('unknown_domain');

        $this->assertSame('auto_safe', (string) ($workflow['autonomy_mode'] ?? ''));
        $this->assertSame('auto_safe', (string) ($workflow['promotion_status'] ?? ''));
        $this->assertSame('suggest_only', (string) ($customerCare['autonomy_mode'] ?? ''));
        $this->assertContains('draft_customer_reply', (array) ($customerCare['metadata']['allowed_actions'] ?? []));
        $this->assertTrue((bool) ($customerCare['metadata']['block_customer_facing_full_auto'] ?? false));
        $this->assertSame('suggest_only', (string) ($unknown['autonomy_mode'] ?? ''));
        $this->assertSame(50, (int) ($unknown['metadata']['max_daily_auto_actions'] ?? 0));
    }

    public function testProductionContextValidationPassesSeededRegistry(): void
    {
        $report = (new SystemContextRegistryService())->validateProductionContext();

        $this->assertSame('ok', $report['status'] ?? null);
        $this->assertSame(0, (int) ($report['summary']['critical'] ?? -1));
        $this->assertSame(0, (int) ($report['summary']['dummy_language_hits'] ?? -1));
        $this->assertTrue((bool) ($report['summary']['default_workspace_settings_ok'] ?? false));
    }

    public function testProductionContextValidationFlagsDefaultWorkspaceContextDrift(): void
    {
        Database::execute(
            "UPDATE workspaces SET settings_json = ? WHERE id = ?",
            [json_encode(['workspace_purpose' => 'demo'], JSON_UNESCAPED_SLASHES), DefaultWorkspaceService::DEFAULT_ID]
        );

        $report = (new SystemContextRegistryService())->validateProductionContext();
        $rules = $this->findingRules($report);

        $this->assertSame('critical', $report['status'] ?? null);
        $this->assertContains('default_workspace_required_context_settings_missing', $rules);
        $this->assertFalse((bool) ($report['summary']['default_workspace_settings_ok'] ?? true));
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function findingRules(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => (string) ($finding['rule'] ?? ''),
            array_filter((array) ($report['findings'] ?? []), 'is_array')
        ));
    }
}
