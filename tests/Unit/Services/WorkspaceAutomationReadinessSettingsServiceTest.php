<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceAutomationReadinessSettingsService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceAutomationReadinessSettingsServiceTest extends DatabaseTestCase
{
    public function testDefaultsToSixHourCadenceWhenNoSettingExists(): void
    {
        Database::execute("UPDATE workspaces SET settings_json = NULL WHERE id = 1");
        $service = new WorkspaceAutomationReadinessSettingsService();

        $policy = $service->getPolicy(1);

        $this->assertSame('six_hours', $policy['cadence']);
        $this->assertSame('Every 6 hours', $policy['label']);
        $this->assertSame(21600, $policy['seconds']);
        $this->assertTrue($policy['automatic_enabled']);
    }

    public function testSavesCadenceIntoWorkspaceSettingsAndPreservesUnrelatedKeys(): void
    {
        Database::execute(
            "UPDATE workspaces SET settings_json = ? WHERE id = 1",
            [json_encode([
                'billing_disabled' => true,
                'automation_readiness' => [
                    'existing_key' => 'keep-me',
                ],
            ])]
        );
        $service = new WorkspaceAutomationReadinessSettingsService();

        $policy = $service->savePolicy(1, 'daily', 123);
        $row = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = 1 LIMIT 1") ?: [];
        $settings = json_decode((string) ($row['settings_json'] ?? ''), true);

        $this->assertSame('daily', $policy['cadence']);
        $this->assertSame(86400, $policy['seconds']);
        $this->assertTrue((bool) ($settings['billing_disabled'] ?? false));
        $this->assertSame('keep-me', (string) ($settings['automation_readiness']['existing_key'] ?? ''));
        $this->assertSame('daily', (string) ($settings['automation_readiness']['refresh_cadence'] ?? ''));
        $this->assertSame(123, (int) ($settings['automation_readiness']['refresh_cadence_updated_by_user_id'] ?? 0));
    }

    public function testInvalidCadenceNormalizesToDefault(): void
    {
        $service = new WorkspaceAutomationReadinessSettingsService();

        $policy = $service->savePolicy(1, 'every-five-minutes', 0);

        $this->assertSame('six_hours', $policy['cadence']);
        $this->assertSame(21600, $policy['seconds']);
    }

    public function testManualOnlyDisablesAutomaticRefresh(): void
    {
        $service = new WorkspaceAutomationReadinessSettingsService();

        $policy = $service->savePolicy(1, 'manual_only', 0);

        $this->assertSame('manual_only', $policy['cadence']);
        $this->assertNull($policy['seconds']);
        $this->assertFalse($service->isAutomaticEnabled($policy));
    }
}
