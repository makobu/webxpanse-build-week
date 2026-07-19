<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\AutoAdminService;
use CRM\Services\PlatformAutoAdminSettingsService;
use CRM\Services\WorkspaceAutoAdminSettingsService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class AutoAdminServiceTest extends DatabaseTestCase
{
    public function testProductsSettingsRemainEditableOutsideEveryAutoAdminLevel(): void
    {
        $service = new AutoAdminService();
        $workspaceSettings = new WorkspaceAutoAdminSettingsService();

        $this->assertFalse($service->isManagedTab('products'));
        $this->assertNotContains('products', $service->getManagedTabs());

        foreach (['suggest_only', 'auto_safe', 'full_auto'] as $automationLevel) {
            $state = $workspaceSettings->setTargetModes(1, ['deal_automation' => $automationLevel]);
            $this->assertSame($automationLevel, (string) ($state['target_modes']['deal_automation'] ?? ''));
            $this->assertNotContains('products', (array) ($state['managed_tabs'] ?? []));
            $this->assertArrayNotHasKey(
                'products',
                (array) ($state['target_modes'] ?? []),
                'Products must remain business-editable at ' . $automationLevel . '.'
            );
        }
    }

    public function testPlatformAndWorkspaceAutoAdminStateAreSeparated(): void
    {
        $actorUserId = (int) Auth::createUser(
            'auto-admin-workspace-coach@example.test',
            'P@ssword123!',
            'admin',
            'Auto',
            'Coach'
        );
        Authorization::assignUserRoleBySlug($actorUserId, 'superadmin', $actorUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $actorUserId, 'superadmin', true, $actorUserId);

        $service = new AutoAdminService();
        $service->setPlatformEnabled(true, $actorUserId);
        $service->setWorkspaceEnabled(1, true, $actorUserId);
        $service->applyManagedDefaultsForWorkspace(1, $actorUserId);

        $this->assertTrue((new PlatformAutoAdminSettingsService())->isEnabled());
        $this->assertTrue($service->isEnabledForWorkspace(1));
        $this->assertTrue((new AICoachWorkspaceSetupService())->isWorkspaceEnabled(1));
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT enabled FROM platform_auto_admin_settings WHERE id = 1")['enabled'] ?? 0)
        );

        $service->setWorkspaceEnabled(1, false, $actorUserId);

        $this->assertTrue((new PlatformAutoAdminSettingsService())->isEnabled());
        $this->assertFalse($service->isEnabledForWorkspace(1));
        $this->assertTrue((new AICoachWorkspaceSetupService())->isWorkspaceEnabled(1));
    }

    public function testLegacySetEnabledDoesNotMutateWorkspaceStateOrApplyDefaults(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-legacy-platform-only@example.test');
        $service = new AutoAdminService();
        (new WorkspaceAutoAdminSettingsService())->setEnabled(1, false, $actorUserId);
        (new AICoachWorkspaceSetupService())->setWorkspaceEnabled(1, $actorUserId, false);

        $service->setEnabled(true, $actorUserId, 1);

        $this->assertTrue((new PlatformAutoAdminSettingsService())->isEnabled());
        $this->assertFalse($service->isEnabledForWorkspace(1));
        $this->assertFalse((new AICoachWorkspaceSetupService())->isWorkspaceEnabled(1));
    }

    public function testWorkspaceSettingsRowsAndCompanionConfigAreBackfilled(): void
    {
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS total FROM workspace_auto_admin_settings WHERE workspace_id = 1")['total'] ?? 0)
        );
        $this->assertGreaterThanOrEqual(
            WorkspaceAutoAdminSettingsService::DEFAULTS_VERSION,
            (int) (Database::queryOne("SELECT managed_defaults_version FROM workspace_auto_admin_settings WHERE workspace_id = 1")['managed_defaults_version'] ?? 0)
        );
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS total FROM workspace_deal_automation_config WHERE workspace_id = 1")['total'] ?? 0)
        );
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS total FROM workspace_ai_autoresponder_config WHERE workspace_id = 1")['total'] ?? 0)
        );
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS total FROM workspace_commercial_automation_config WHERE workspace_id = 1")['total'] ?? 0)
        );
        $this->assertSame(
            2,
            (int) (Database::queryOne("SELECT COUNT(*) AS total FROM workspace_cold_outreach_warmup_config WHERE workspace_id = 1")['total'] ?? 0)
        );
    }

    public function testNewWorkspaceStateInheritsCurrentPlatformAvailability(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-new-workspace@example.test');
        (new PlatformAutoAdminSettingsService())->setEnabled(true, $actorUserId);

        $workspaceId = $this->createWorkspace('auto-admin-inherited', $actorUserId);
        $state = (new WorkspaceAutoAdminSettingsService())->getSettings($workspaceId);

        $this->assertTrue((bool) ($state['enabled'] ?? false));
        $this->assertTrue((new AutoAdminService())->isEnabledForWorkspace($workspaceId));
    }

    public function testManagedDefaultsApplyOnlyToEnabledWorkspaceAndRespectV1Caps(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-isolation@example.test');
        $workspaceTwoId = $this->createWorkspace('auto-admin-isolation-b', $actorUserId);
        $workspaceSettings = new WorkspaceAutoAdminSettingsService();
        $workspaceSettings->setEnabled($workspaceTwoId, false, $actorUserId);

        Database::execute(
            "UPDATE workspace_auto_admin_settings
             SET target_modes_json = ?
             WHERE workspace_id = 1",
            [json_encode([
                'deal_automation' => 'full_auto',
                'workflow_automation' => 'full_auto',
                'ai_autoresponder' => 'full_auto',
                'commercial_automation' => 'full_auto',
            ])]
        );

        $dealConfig = new DealAutomationConfig();
        $aiResponderConfig = new AIAutoResponderConfig();
        $commercialConfig = new CommercialAutomationConfig();

        $dealConfig->save(['enabled' => true, 'mode' => 'full_auto', 'min_confidence' => 0.71], $workspaceTwoId);
        $aiResponderConfig->save(['enabled' => true, 'mode' => 'full_auto'], $workspaceTwoId);
        $commercialConfig->save(['enabled' => true, 'mode' => 'full_auto', 'auto_send_enabled' => true], $workspaceTwoId);

        $autoAdminService = new AutoAdminService();
        $autoAdminService->setPlatformEnabled(true, $actorUserId);
        $workspaceSettings->setEnabled(1, true, $actorUserId);
        $autoAdminService->applyManagedDefaultsForWorkspace(1, $actorUserId);

        $workspaceOneState = $workspaceSettings->getSettings(1);
        $workspaceOneDealConfig = $dealConfig->get(1);
        $workspaceOneResponderConfig = $aiResponderConfig->get(1);
        $workspaceOneCommercialConfig = $commercialConfig->get(1);

        $this->assertTrue((bool) ($workspaceOneState['enabled'] ?? false));
        $this->assertFalse((bool) ($workspaceSettings->getSettings($workspaceTwoId)['enabled'] ?? true));
        $this->assertSame('auto_safe', (string) ($workspaceOneState['effective_modes']['deal_automation'] ?? ''));
        $this->assertSame('draft_only', (string) ($workspaceOneState['effective_modes']['ai_autoresponder'] ?? ''));
        $this->assertSame('auto_safe', (string) ($workspaceOneState['effective_modes']['commercial_automation'] ?? ''));
        $this->assertSame('auto_safe', (string) ($workspaceOneDealConfig['mode'] ?? ''));
        $this->assertSame('draft_only', (string) ($workspaceOneResponderConfig['mode'] ?? ''));
        $this->assertSame('auto_safe', (string) ($workspaceOneCommercialConfig['mode'] ?? ''));
        $this->assertFalse((bool) ($workspaceOneCommercialConfig['auto_send_enabled'] ?? true));

        $this->assertSame('full_auto', (string) ($dealConfig->get($workspaceTwoId)['mode'] ?? ''));
        $this->assertSame('full_auto', (string) ($aiResponderConfig->get($workspaceTwoId)['mode'] ?? ''));
        $this->assertSame('full_auto', (string) ($commercialConfig->get($workspaceTwoId)['mode'] ?? ''));

        $appliedEvents = (int) (Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM workspace_auto_admin_events
             WHERE workspace_id = 1
               AND event_type = 'defaults_applied'"
        )['total'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $appliedEvents);
        $downgradedEvents = (int) (Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM workspace_auto_admin_events
             WHERE workspace_id = 1
               AND event_type = 'downgraded'"
        )['total'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $downgradedEvents);
    }

    public function testFrozenWorkspaceSkipsManagedConfigWritesAndLogsEvent(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-frozen@example.test');
        $workspaceSettings = new WorkspaceAutoAdminSettingsService();
        (new PlatformAutoAdminSettingsService())->setEnabled(true, $actorUserId);
        $workspaceSettings->setEnabled(1, true, $actorUserId);

        $dealConfig = new DealAutomationConfig();
        $dealConfig->save(['enabled' => true, 'mode' => 'full_auto', 'min_confidence' => 0.73], 1);
        Database::execute(
            "UPDATE workspace_auto_admin_settings
             SET manual_freeze = 1,
                 freeze_reason = 'Waiting for owner review'
             WHERE workspace_id = 1"
        );

        $result = (new AutoAdminService())->applyManagedDefaultsForWorkspace(1, $actorUserId);

        $this->assertFalse((bool) ($result['applied'] ?? true));
        $this->assertSame('workspace_auto_admin_frozen', (string) ($result['reason'] ?? ''));
        $this->assertSame('full_auto', (string) ($dealConfig->get(1)['mode'] ?? ''));
        $this->assertSame(
            1,
            (int) (Database::queryOne(
                "SELECT COUNT(*) AS total
                 FROM workspace_auto_admin_events
                 WHERE workspace_id = 1
                   AND event_type = 'skipped_frozen'"
            )['total'] ?? 0)
        );
    }

    public function testDryRunDoesNotWriteEvaluationEventsOrManagedConfig(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-dry-run@example.test');
        (new PlatformAutoAdminSettingsService())->setEnabled(true, $actorUserId);
        $workspaceSettings = new WorkspaceAutoAdminSettingsService();
        $workspaceSettings->setEnabled(1, true, $actorUserId);
        $workspaceSettings->setTargetModes(1, [
            'deal_automation' => 'full_auto',
            'workflow_automation' => 'full_auto',
            'ai_autoresponder' => 'full_auto',
            'commercial_automation' => 'full_auto',
        ], $actorUserId);

        $dealConfig = new DealAutomationConfig();
        $dealConfig->save(['enabled' => true, 'mode' => 'full_auto', 'min_confidence' => 0.73], 1);
        $beforeState = $workspaceSettings->getSettings(1);
        $beforeEventCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM workspace_auto_admin_events
             WHERE workspace_id = 1"
        )['total'] ?? 0);

        $result = (new AutoAdminService())->applyManagedDefaultsForWorkspace(1, $actorUserId, true);
        $afterState = $workspaceSettings->getSettings(1);

        $this->assertFalse((bool) ($result['applied'] ?? true));
        $this->assertTrue((bool) ($result['dry_run'] ?? false));
        $this->assertSame($beforeState['last_evaluated_at'] ?? null, $afterState['last_evaluated_at'] ?? null);
        $this->assertSame($beforeState['last_applied_at'] ?? null, $afterState['last_applied_at'] ?? null);
        $this->assertSame('full_auto', (string) ($dealConfig->get(1)['mode'] ?? ''));
        $this->assertSame(
            $beforeEventCount,
            (int) (Database::queryOne(
                "SELECT COUNT(*) AS total
                 FROM workspace_auto_admin_events
                 WHERE workspace_id = 1"
            )['total'] ?? 0)
        );
    }

    public function testWorkspaceZeroAndMissingWorkspaceFailClosed(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-workspace-zero@example.test');
        (new PlatformAutoAdminSettingsService())->setEnabled(true, $actorUserId);
        $service = new AutoAdminService();

        $this->assertFalse($service->isEnabledForWorkspace(0));
        $this->assertFalse($service->isEnabledForWorkspace(null));

        $result = $service->applyManagedDefaultsForWorkspace(0, $actorUserId, true);
        $this->assertFalse((bool) ($result['applied'] ?? true));
        $this->assertSame('workspace_auto_admin_disabled', (string) ($result['reason'] ?? ''));
    }

    public function testRuntimeControlsCapManagedDefaultsAndLogRuntimeBlocked(): void
    {
        $actorUserId = $this->createSuperAdmin('auto-admin-runtime-blocked@example.test');
        (new PlatformAutoAdminSettingsService())->setEnabled(true, $actorUserId);
        $workspaceSettings = new WorkspaceAutoAdminSettingsService();
        $workspaceSettings->setEnabled(1, true, $actorUserId);
        $workspaceSettings->setTargetModes(1, [
            'deal_automation' => 'full_auto',
            'workflow_automation' => 'full_auto',
            'ai_autoresponder' => 'full_auto',
            'commercial_automation' => 'full_auto',
        ], $actorUserId);
        (new AIRuntimeControlService())->setControl('global', 'paused', $actorUserId, 'Unit test pause', null, [], 1);

        $result = (new AutoAdminService())->applyManagedDefaultsForWorkspace(1, $actorUserId);
        $state = (array) ($result['state'] ?? []);

        $this->assertTrue((bool) ($result['applied'] ?? false));
        $this->assertSame('suggest_only', (string) ($state['effective_modes']['deal_automation'] ?? ''));
        $this->assertSame('suggest_only', (string) ($state['effective_modes']['workflow_automation'] ?? ''));
        $this->assertSame('draft_only', (string) ($state['effective_modes']['ai_autoresponder'] ?? ''));
        $this->assertSame('suggest_only', (string) ($state['effective_modes']['commercial_automation'] ?? ''));
        $this->assertGreaterThanOrEqual(
            1,
            (int) (Database::queryOne(
                "SELECT COUNT(*) AS total
                 FROM workspace_auto_admin_events
                 WHERE workspace_id = 1
                   AND event_type = 'runtime_blocked'"
            )['total'] ?? 0)
        );
    }

    private function createSuperAdmin(string $email): int
    {
        $actorUserId = (int) Auth::createUser(
            $email,
            'P@ssword123!',
            'admin',
            'Auto',
            'Admin'
        );
        Authorization::assignUserRoleBySlug($actorUserId, 'superadmin', $actorUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $actorUserId, 'superadmin', true, $actorUserId);

        return $actorUserId;
    }

    private function createWorkspace(string $slugPrefix, int $actorUserId): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by, created_at, updated_at)
             VALUES (UUID(), ?, ?, 'active', 'active', ?, NOW(), NOW())",
            ['Auto Admin Test Workspace', $slug, $actorUserId]
        );
        $workspaceId = (int) Database::lastInsertId();
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $actorUserId, 'superadmin', true, $actorUserId);
        WorkspaceContext::activateRuntimeWorkspace(1, $actorUserId, 'superadmin');

        return $workspaceId;
    }
}
