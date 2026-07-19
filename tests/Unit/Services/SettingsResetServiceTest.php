<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\DemoWorkspaceRepairService;
use CRM\Services\SettingsResetService;
use CRM\Services\SettingsResetTableCatalog;
use PHPUnit\Framework\TestCase;

class SettingsResetServiceTest extends TestCase
{
    public function testResetDefinitionsKeepExpectedConfirmationTextAndScope(): void
    {
        $definitions = (new SettingsResetService())->definitions();

        $this->assertSame('RESET DATA', $definitions['reset_core_data']['confirm_text'] ?? null);
        $this->assertSame('RESET CONTEXT', $definitions['reset_company_context']['confirm_text'] ?? null);
        $this->assertSame('RESET PLATFORM', $definitions['reset_platform_data']['confirm_text'] ?? null);
        $this->assertNotContains('workspaces', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertNotContains('workspace_memberships', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('workflows', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('campaign_queue', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('campaign_enrollments', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('nurture_enrollments', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('guided_demo_sessions', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('meeting_bot_runs', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('email_template_learning_samples', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('workflow_automation_proposals', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('marketing_execution_queue', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('finance_transactions', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertContains('user_strategy_snapshots', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('user_strategy_profiles', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('startup_journey_artifacts', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('startup_journey_stage_events', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('startup_journey_stage_responses', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('startup_journeys', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertNotContains('workflows', $definitions['reset_company_context']['tables'] ?? []);
        $this->assertContains('workspaces', $definitions['reset_platform_data']['tables'] ?? []);
        $this->assertContains('demo_visitor_sessions', $definitions['reset_platform_data']['tables'] ?? []);
        $this->assertContains('demo_realtime_events', $definitions['reset_platform_data']['tables'] ?? []);
        $this->assertNotContains('workspace_security_settings', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertNotContains('email_integrations', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertNotContains('workspace_skill_installs', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertNotContains('workspace_negotiated_package_offers', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertNotContains('api_keys', $definitions['reset_core_data']['tables'] ?? []);
        $this->assertTrue(class_exists(DemoWorkspaceRepairService::class));
    }

    public function testPlatformResetDeletesGovernanceAfterRuntimeTables(): void
    {
        $tables = (new SettingsResetService())->definitions()['reset_platform_data']['tables'] ?? [];

        $this->assertLessThan(
            array_search('workspaces', $tables, true),
            array_search('workspace_memberships', $tables, true)
        );
        $this->assertLessThan(
            array_search('workspace_wallets', $tables, true),
            array_search('workspace_wallet_ledger', $tables, true)
        );
        $this->assertLessThan(
            array_search('workspace_subscriptions', $tables, true),
            array_search('billing_transactions', $tables, true)
        );
        $this->assertLessThan(
            array_search('workspaces', $tables, true),
            array_search('workspace_slugs', $tables, true)
        );
        $this->assertLessThan(
            array_search('demo_visitor_sessions', $tables, true),
            array_search('demo_realtime_events', $tables, true)
        );
        $this->assertLessThan(
            array_search('workspaces', $tables, true),
            array_search('demo_visitor_sessions', $tables, true)
        );
        $this->assertLessThan(
            array_search('user_strategy_profiles', $tables, true),
            array_search('user_strategy_snapshots', $tables, true)
        );
        $this->assertLessThan(
            array_search('startup_journeys', $tables, true),
            array_search('startup_journey_artifacts', $tables, true)
        );
        $this->assertLessThan(
            array_search('startup_journeys', $tables, true),
            array_search('startup_journey_stage_events', $tables, true)
        );
        $this->assertLessThan(
            array_search('startup_journeys', $tables, true),
            array_search('startup_journey_stage_responses', $tables, true)
        );
    }

    public function testProtectedTablesAreNotResetTargets(): void
    {
        $service = new SettingsResetService();
        $allResetTargets = [];
        foreach ($service->definitions() as $definition) {
            $allResetTargets = array_merge($allResetTargets, (array) ($definition['tables'] ?? []));
        }

        foreach ($service->protectedTables() as $protectedTable) {
            $this->assertNotContains($protectedTable, $allResetTargets);
        }
    }

    public function testCatalogClassifiesResetAndPreservedWorkspaceTables(): void
    {
        $catalog = new SettingsResetTableCatalog();

        $this->assertSame('automation_runtime', $catalog->resetCategoryFor('campaign_queue'));
        $this->assertSame('automation_runtime', $catalog->resetCategoryFor('nurture_enrollments'));
        $this->assertSame('automation_runtime', $catalog->resetCategoryFor('guided_demo_sessions'));
        $this->assertSame('automation_runtime', $catalog->resetCategoryFor('meeting_bot_runs'));
        $this->assertSame('ai_history', $catalog->resetCategoryFor('email_template_learning_samples'));
        $this->assertSame('workspace_config', $catalog->preservedCategoryFor('workspace_security_settings'));
        $this->assertSame('workspace_config', $catalog->preservedCategoryFor('email_integrations'));
        $this->assertSame('workspace_config', $catalog->preservedCategoryFor('workspace_skill_installs'));
    }

    public function testResetServiceKeepsAutoIncrementMaintenanceOutOfDeletePath(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../services/SettingsResetService.php');

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('ALTER TABLE `{$tableName}` AUTO_INCREMENT = 1' . PHP_EOL . '        return $before;', (string) $source);
        $this->assertStringContainsString('private function resetAutoIncrementCounters', (string) $source);
    }
}
