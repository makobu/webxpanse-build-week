<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\DemoWorkspaceRepairService;
use CRM\Services\SettingsResetService;
use CRM\Services\SettingsResetTableCatalog;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class SettingsResetIsolationTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testWorkspaceAdminResetDeletesOnlyActiveWorkspaceRowsAndKeepsSlugs(): void
    {
        $adminUserId = $this->createWorkspaceAdmin();
        $this->ensureWorkspace(2, 'reset-workspace-two', 'Reset Workspace Two');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $adminUserId, 'owner', true, $adminUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $adminUserId, 'owner', true, $adminUserId);
        $this->assignWorkspaceRole($adminUserId, 1, 'admin');

        $this->seedWorkspaceResetRows(1, $adminUserId, 'Workspace One Reset Data');
        $this->seedWorkspaceResetRows(2, $adminUserId, 'Workspace Two Reset Data');
        $usersBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($adminUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_company_context',
                'danger_confirm_text' => 'RESET CONTEXT',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, $this->countRows('company_profile', 1));
        $this->assertSame(0, $this->countRows('products', 1));
        $this->assertSame(0, $this->countRows('targets', 1));
        $this->assertSame(1, $this->countRows('company_profile', 2));
        $this->assertSame(1, $this->countRows('products', 2));
        $this->assertSame(1, $this->countRows('targets', 2));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'default'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'reset-workspace-two'")['c'] ?? 0));
        $this->assertSame($usersBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0));
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$adminUserId]));
    }

    public function testWorkspaceDataResetDeletesAutomationAndPreservesGovernanceAndBilling(): void
    {
        $adminUserId = $this->createWorkspaceAdmin();
        $this->ensureWorkspace(2, 'data-reset-two', 'Data Reset Two');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $adminUserId, 'owner', true, $adminUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $adminUserId, 'owner', true, $adminUserId);
        $this->assignWorkspaceRole($adminUserId, 1, 'admin');

        $this->seedWorkspaceResetRows(1, $adminUserId, 'Workspace One Data Reset');
        $this->seedWorkspaceResetRows(2, $adminUserId, 'Workspace Two Data Reset');
        $this->seedAutomationRows(1, $adminUserId, 'Workspace One Automation');
        $this->seedAutomationRows(2, $adminUserId, 'Workspace Two Automation');
        $this->seedBillingGovernanceRows(1, $adminUserId);

        $usersBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);
        $rolesBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM roles")['c'] ?? 0);
        $permissionsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0);
        $rolePermissionsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM role_permissions")['c'] ?? 0);
        $workspaceMembershipsBefore = $this->countRows('workspace_memberships', 1);
        $workspaceSlugsBefore = $this->countRows('workspace_slugs', 1);
        $workspaceWalletsBefore = $this->tableExists('workspace_wallets') ? $this->countRows('workspace_wallets', 1) : 0;
        $workspaceSubscriptionsBefore = $this->tableExists('workspace_subscriptions') ? $this->countRows('workspace_subscriptions', 1) : 0;

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($adminUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_core_data',
                'danger_confirm_text' => 'RESET DATA',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, $this->countRows('company_profile', 1));
        $this->assertSame(0, $this->countRows('products', 1));
        $this->assertSame(0, $this->countRows('targets', 1));
        $this->assertSame(0, $this->countRows('workflows', 1));
        $this->assertSame(0, $this->countRows('tags', 1));
        $this->assertSame(1, $this->countRows('company_profile', 2));
        $this->assertSame(1, $this->countRows('products', 2));
        $this->assertSame(1, $this->countRows('targets', 2));
        $this->assertSame(1, $this->countRows('workflows', 2));
        $this->assertSame(1, $this->countRows('tags', 2));
        $this->assertSame($usersBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0));
        $this->assertSame($rolesBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM roles")['c'] ?? 0));
        $this->assertSame($permissionsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0));
        $this->assertSame($rolePermissionsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM role_permissions")['c'] ?? 0));
        $this->assertSame($workspaceMembershipsBefore, $this->countRows('workspace_memberships', 1));
        $this->assertSame($workspaceSlugsBefore, $this->countRows('workspace_slugs', 1));
        if ($this->tableExists('workspace_wallets')) {
            $this->assertSame($workspaceWalletsBefore, $this->countRows('workspace_wallets', 1));
        }
        if ($this->tableExists('workspace_subscriptions')) {
            $this->assertSame($workspaceSubscriptionsBefore, $this->countRows('workspace_subscriptions', 1));
        }
    }

    public function testWorkspaceContextResetDoesNotDeleteAutomationSetup(): void
    {
        $adminUserId = $this->createWorkspaceAdmin();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $adminUserId, 'owner', true, $adminUserId);
        $this->assignWorkspaceRole($adminUserId, 1, 'admin');

        $this->seedWorkspaceResetRows(1, $adminUserId, 'Context Reset Data');
        $this->seedAutomationRows(1, $adminUserId, 'Context Reset Automation');

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($adminUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_company_context',
                'danger_confirm_text' => 'RESET CONTEXT',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, $this->countRows('company_profile', 1));
        $this->assertSame(0, $this->countRows('products', 1));
        $this->assertSame(0, $this->countRows('targets', 1));
        $this->assertSame(1, $this->countRows('workflows', 1));
        $this->assertSame(1, $this->countRows('tags', 1));
    }

    public function testWrongResetConfirmationDeletesNothing(): void
    {
        $adminUserId = $this->createWorkspaceAdmin();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $adminUserId, 'owner', true, $adminUserId);
        $this->assignWorkspaceRole($adminUserId, 1, 'admin');
        $this->seedWorkspaceResetRows(1, $adminUserId, 'Wrong Confirm Data');
        $companyProfileBefore = $this->countRows('company_profile', 1);
        $productsBefore = $this->countRows('products', 1);
        $targetsBefore = $this->countRows('targets', 1);

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($adminUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_core_data',
                'danger_confirm_text' => 'reset data',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Confirmation text mismatch. Type exactly: RESET DATA', (string) ($response['body'] ?? ''));
        $this->assertSame($companyProfileBefore, $this->countRows('company_profile', 1));
        $this->assertSame($productsBefore, $this->countRows('products', 1));
        $this->assertSame($targetsBefore, $this->countRows('targets', 1));
    }

    public function testNonSuperAdminCannotForgePlatformReset(): void
    {
        $adminUserId = $this->createWorkspaceAdmin();
        $this->ensureWorkspace(2, 'forged-platform-two', 'Forged Platform Two');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $adminUserId, 'owner', true, $adminUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $adminUserId, 'owner', true, $adminUserId);
        $this->assignGlobalRole($adminUserId, 'admin');
        $this->assignWorkspaceRole($adminUserId, 1, 'admin');
        $this->grantRolePermission('admin', 'platform.system.reset');
        $this->seedWorkspaceResetRows(1, $adminUserId, 'Forged Platform One');
        $this->seedWorkspaceResetRows(2, $adminUserId, 'Forged Platform Two');
        $workspaceOneProfileBefore = $this->countRows('company_profile', 1);
        $workspaceTwoProfileBefore = $this->countRows('company_profile', 2);

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($adminUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_platform_data',
                'danger_confirm_text' => 'RESET PLATFORM',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Only Super Admin can perform the platform reset.', (string) ($response['body'] ?? ''));
        $this->assertSame($workspaceOneProfileBefore, $this->countRows('company_profile', 1));
        $this->assertSame($workspaceTwoProfileBefore, $this->countRows('company_profile', 2));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'forged-platform-two'")['c'] ?? 0));
    }

    public function testPlatformResetRollsBackWhenProtectedDemoRepairFails(): void
    {
        $superAdminId = $this->createWorkspaceAdmin();
        $this->ensureWorkspace(2, 'rollback-platform-two', 'Rollback Platform Two');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $superAdminId, 'owner', true, $superAdminId);
        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->seedWorkspaceResetRows(1, $superAdminId, 'Rollback Platform One');
        $this->seedWorkspaceResetRows(2, $superAdminId, 'Rollback Platform Two');

        $workspacesBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces")['c'] ?? 0);
        $slugsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs")['c'] ?? 0);
        $membershipsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_memberships")['c'] ?? 0);
        $profilesBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM company_profile")['c'] ?? 0);
        $workspaceOneProfilesBefore = $this->countRows('company_profile', 1);
        $workspaceTwoProfilesBefore = $this->countRows('company_profile', 2);

        $failingRepair = new class extends DemoWorkspaceRepairService {
            public function ensureBaseline(int $actorUserId = 0, bool $seedPublicStory = true): array
            {
                throw new \RuntimeException('Forced protected demo repair failure.');
            }
        };
        $service = new SettingsResetService(null, $failingRepair);

        try {
            $service->execute($service->definitions()['reset_platform_data'], null, $superAdminId);
            $this->fail('Expected platform reset to fail during protected demo repair.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Forced protected demo repair failure.', $e->getMessage());
        }

        $this->assertSame($workspacesBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces")['c'] ?? 0));
        $this->assertSame($slugsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs")['c'] ?? 0));
        $this->assertSame($membershipsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_memberships")['c'] ?? 0));
        $this->assertSame($profilesBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM company_profile")['c'] ?? 0));
        $this->assertSame($workspaceOneProfilesBefore, $this->countRows('company_profile', 1));
        $this->assertSame($workspaceTwoProfilesBefore, $this->countRows('company_profile', 2));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'rollback-platform-two'")['c'] ?? 0));
    }

    public function testSuperAdminPlatformResetClearsWorkspaceGovernanceAndRecreatesDefaultWorkspace(): void
    {
        $superAdminId = $this->createWorkspaceAdmin();
        $this->ensureWorkspace(2, 'platform-reset-two', 'Platform Reset Two');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $superAdminId, 'owner', true, $superAdminId);
        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');

        $defaultWorkspaceUserId = $this->createWorkspaceAdmin();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $defaultWorkspaceUserId, 'admin', false, $superAdminId);

        $deletedWorkspaceUserId = $this->createWorkspaceAdmin();
        $this->forceWorkspaceMembership(2, $deletedWorkspaceUserId, 'admin', false, $superAdminId);

        $otherSuperAdminId = $this->createWorkspaceAdmin();
        $this->forceWorkspaceMembership(2, $otherSuperAdminId, 'superadmin', true, $superAdminId);
        $this->assignGlobalRole($otherSuperAdminId, 'superadmin');

        $platformOnlyUserId = $this->createWorkspaceAdmin();

        $this->seedWorkspaceResetRows(1, $superAdminId, 'Platform Workspace One Data');
        $this->seedWorkspaceResetRows(2, $superAdminId, 'Platform Workspace Two Data');
        $this->seedClarityJourneyContextRows(1, $superAdminId, 'Platform Workspace One Journey');
        $this->seedClarityJourneyContextRows(2, $deletedWorkspaceUserId, 'Platform Workspace Two Journey');
        $demoWorkspaceBeforeReset = Database::queryOne(
            "SELECT id FROM workspaces WHERE slug = 'protected-demo' LIMIT 1"
        ) ?: [];
        $this->assertGreaterThan(0, (int) ($demoWorkspaceBeforeReset['id'] ?? 0));
        Database::execute(
            "INSERT INTO demo_visitor_sessions
                (session_uuid, workspace_id, consent_privacy, access_source, status, expires_at, purge_after, last_seen_at)
             VALUES (?, ?, 1, 'guest', 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
            ['88888888-8888-4888-8888-888888888888', (int) $demoWorkspaceBeforeReset['id']]
        );
        $rolesBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM roles")['c'] ?? 0);
        $permissionsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0);
        $rolePermissionsBefore = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM role_permissions")['c'] ?? 0);

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_platform_data',
                'danger_confirm_text' => 'RESET PLATFORM',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM company_profile")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM products")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM targets")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM startup_journey_artifacts")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM startup_journey_stage_events")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM startup_journey_stage_responses")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM startup_journeys")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM user_strategy_snapshots")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM user_strategy_profiles")['c'] ?? 0));
        $this->assertSame(2, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'default'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'protected-demo'")['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_slugs WHERE slug = 'platform-reset-two'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_memberships")['c'] ?? 0));
        $demoWorkspace = Database::queryOne(
            "SELECT id, uuid, status, plan_status, settings_json FROM workspaces WHERE slug = 'protected-demo' LIMIT 1"
        ) ?: [];
        $this->assertSame('00000000-0000-4000-8000-000000000461', (string) ($demoWorkspace['uuid'] ?? ''));
        $this->assertSame('active', (string) ($demoWorkspace['status'] ?? ''));
        $this->assertSame('inactive', (string) ($demoWorkspace['plan_status'] ?? ''));
        $demoSettings = json_decode((string) ($demoWorkspace['settings_json'] ?? ''), true) ?: [];
        foreach (['protected_demo_workspace', 'demo_workspace', 'billing_disabled', 'invites_disabled', 'exports_disabled', 'real_integrations_disabled'] as $flag) {
            $this->assertTrue((bool) ($demoSettings[$flag] ?? false), $flag . ' should be enabled for protected demo repair.');
        }
        $demoWorkspaceId = (int) ($demoWorkspace['id'] ?? 0);
        $this->assertSame(4, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND demo_visibility = 'public_seed'",
            [$demoWorkspaceId]
        )['c'] ?? 0));
        $this->assertSame(4, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM communications WHERE workspace_id = ? AND demo_visibility = 'public_seed'",
            [$demoWorkspaceId]
        )['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM demo_visitor_sessions WHERE workspace_id = ? AND status = 'active'",
            [$demoWorkspaceId]
        )['c'] ?? 0));
        $this->assertGreaterThanOrEqual(8, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'demo_viewer'
               AND p.permission_key IN (
                   'demo.workspace.access',
                   'demo.channel.simulate',
                   'demo.realtime.read',
                   'tasks.read',
                   'workspace.skills.view',
                   'startup_journey.view',
                   'founder_loop.view',
                   'feature.automation_battery'
               )
               AND rp.can_access = 1"
        )['c'] ?? 0));
        $membership = Database::queryOne(
            "SELECT wm.workspace_id, wm.user_id, wm.role_slug, wm.membership_status, wm.is_owner, r.slug AS assigned_role_slug
             FROM workspace_memberships wm
             LEFT JOIN workspace_user_roles wur ON wur.workspace_id = wm.workspace_id AND wur.user_id = wm.user_id
             LEFT JOIN roles r ON r.id = wur.role_id
             WHERE wm.user_id = ?
             LIMIT 1",
            [$superAdminId]
        );
        $this->assertNotEmpty($membership);
        $this->assertSame(1, (int) ($membership['workspace_id'] ?? 0));
        $this->assertSame($superAdminId, (int) ($membership['user_id'] ?? 0));
        $this->assertSame('superadmin', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame('active', (string) ($membership['membership_status'] ?? ''));
        $this->assertSame(1, (int) ($membership['is_owner'] ?? 0));
        $this->assertSame('superadmin', (string) ($membership['assigned_role_slug'] ?? ''));
        $this->assertSame($rolesBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM roles")['c'] ?? 0));
        $this->assertSame($permissionsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0));
        $this->assertSame($rolePermissionsBefore, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM role_permissions")['c'] ?? 0));
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$superAdminId]));
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$otherSuperAdminId]));
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$platformOnlyUserId]));
        $this->assertNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$defaultWorkspaceUserId]));
        $this->assertNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$deletedWorkspaceUserId]));
    }

    public function testWorkspaceScopedTablesAreClassifiedByResetCatalog(): void
    {
        $uncategorized = (new SettingsResetTableCatalog())->uncategorizedWorkspaceTables();

        $this->assertSame([], $uncategorized, 'Uncategorized workspace tables: ' . implode(', ', $uncategorized));
    }

    private function seedClarityJourneyContextRows(int $workspaceId, int $userId, string $label): void
    {
        Database::execute(
            "INSERT INTO startup_journeys (workspace_id, user_id, status, current_stage_key)
             VALUES (?, ?, 'active', 'lean_canvas')
             ON DUPLICATE KEY UPDATE status = VALUES(status), current_stage_key = VALUES(current_stage_key)",
            [$workspaceId, $userId]
        );
        $journey = Database::queryOne(
            "SELECT id FROM startup_journeys WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];
        $journeyId = (int) ($journey['id'] ?? 0);
        $this->assertGreaterThan(0, $journeyId);

        Database::execute(
            "INSERT INTO startup_journey_stage_responses (
                journey_id, workspace_id, user_id, stage_key, stage_order, status, responses_json, notes, completed_at
             ) VALUES (?, ?, ?, 'lean_canvas', 4, 'completed', JSON_OBJECT('problem', ?), ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                responses_json = VALUES(responses_json),
                notes = VALUES(notes),
                completed_at = VALUES(completed_at)",
            [$journeyId, $workspaceId, $userId, $label, $label]
        );
        Database::execute(
            "INSERT INTO startup_journey_stage_events (journey_id, workspace_id, user_id, stage_key, event_type, metadata_json)
             VALUES (?, ?, ?, 'lean_canvas', 'stage_completed', JSON_OBJECT('source', 'settings_reset_test'))",
            [$journeyId, $workspaceId, $userId]
        );
        Database::execute(
            "INSERT INTO startup_journey_artifacts (journey_id, workspace_id, user_id, artifact_type, title, content_json, created_by)
             VALUES (?, ?, ?, 'strategy_note', ?, JSON_OBJECT('label', ?), ?)",
            [$journeyId, $workspaceId, $userId, $label, $label, $userId]
        );

        Database::execute(
            "INSERT INTO user_strategy_profiles (
                workspace_id, user_id, target_market_focus, ideal_customer_profile, offer_angle, sales_motion, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 'Founder-led sales', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                target_market_focus = VALUES(target_market_focus),
                ideal_customer_profile = VALUES(ideal_customer_profile),
                offer_angle = VALUES(offer_angle),
                sales_motion = VALUES(sales_motion),
                updated_at = NOW()",
            [$workspaceId, $userId, $label, $label . ' ICP', $label . ' offer']
        );
        $strategy = Database::queryOne(
            "SELECT id FROM user_strategy_profiles WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];
        $strategyProfileId = (int) ($strategy['id'] ?? 0);
        $this->assertGreaterThan(0, $strategyProfileId);

        Database::execute(
            "INSERT INTO user_strategy_snapshots (
                workspace_id, user_id, strategy_profile_id, version, source_hash, status,
                target_market_focus, ideal_customer_profile, offer_angle, sales_motion, source_json, started_at
             ) VALUES (?, ?, ?, 1, SHA2(?, 256), 'active', ?, ?, ?, 'Founder-led sales', JSON_OBJECT('source', 'clarity_journey'), NOW())
             ON DUPLICATE KEY UPDATE
                strategy_profile_id = VALUES(strategy_profile_id),
                source_hash = VALUES(source_hash),
                target_market_focus = VALUES(target_market_focus),
                ideal_customer_profile = VALUES(ideal_customer_profile),
                offer_angle = VALUES(offer_angle),
                updated_at = NOW()",
            [$workspaceId, $userId, $strategyProfileId, $label, $label, $label . ' ICP', $label . ' offer']
        );
    }

    private function seedWorkspaceResetRows(int $workspaceId, int $userId, string $label): void
    {
        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, is_active)
             VALUES (?, ?, TRUE)",
            [$workspaceId, $label]
        );
        Database::execute(
            "INSERT INTO products (workspace_id, name, category, is_active)
             VALUES (?, ?, 'Reset', TRUE)",
            [$workspaceId, $label]
        );
        Database::execute(
            "INSERT INTO targets (workspace_id, user_id, title, target_type, target_value, current_value, start_date, target_date, reminder_frequency, status)
             VALUES (?, ?, ?, 'custom', 100, 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'weekly', 'active')",
            [$workspaceId, $userId, $label]
        );
    }

    private function seedAutomationRows(int $workspaceId, int $userId, string $label): void
    {
        Database::execute(
            "INSERT INTO workflows (workspace_id, name, trigger_config, actions, is_active)
             VALUES (?, ?, JSON_OBJECT('event', 'manual'), JSON_ARRAY(JSON_OBJECT('type', 'notify')), TRUE)",
            [$workspaceId, $label]
        );
        Database::execute(
            "INSERT INTO tags (workspace_id, name, color, description, created_by)
             VALUES (?, ?, '#ef4444', 'Reset isolation tag', ?)",
            [$workspaceId, uniqid(strtolower(str_replace(' ', '-', $label)) . '-', true), $userId]
        );
    }

    private function seedBillingGovernanceRows(int $workspaceId, int $userId): void
    {
        if ($this->tableExists('workspace_wallets')) {
            Database::execute(
                "INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
                 VALUES (?, 'KES', 1000, 0, 1000, 0, NOW())
                 ON DUPLICATE KEY UPDATE token_balance = VALUES(token_balance), last_activity_at = VALUES(last_activity_at)",
                [$workspaceId]
            );
        }

        if (!$this->tableExists('workspace_subscriptions')) {
            return;
        }

        $price = Database::queryOne("SELECT id FROM billing_plan_prices ORDER BY id ASC LIMIT 1");
        if (!$price) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_subscriptions (workspace_id, billing_plan_price_id, provider, provider_reference, subscription_status, created_by)
             VALUES (?, ?, 'test', ?, 'trialing', ?)",
            [$workspaceId, (int) $price['id'], uniqid('reset-sub-', true), $userId]
        );
    }

    private function forceWorkspaceMembership(int $workspaceId, int $userId, string $roleSlug, bool $isOwner, int $invitedBy): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, ?, 'active', ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                role_slug = VALUES(role_slug),
                membership_status = VALUES(membership_status),
                is_owner = VALUES(is_owner),
                joined_at = COALESCE(joined_at, VALUES(joined_at)),
                invited_by = VALUES(invited_by),
                updated_at = NOW()",
            [$workspaceId, $userId, $roleSlug, $isOwner ? 1 : 0, $invitedBy]
        );
        $this->assignWorkspaceRole($userId, $workspaceId, $roleSlug);
    }

    private function countRows(string $table, int $workspaceId): int
    {
        return (int) (Database::queryOne("SELECT COUNT(*) AS c FROM `{$table}` WHERE workspace_id = ?", [$workspaceId])['c'] ?? 0);
    }

    private function tableExists(string $table): bool
    {
        return (bool) Database::queryOne(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1",
            [$table]
        );
    }

    private function createWorkspaceAdmin(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('settings-reset-user-', true), uniqid('settings-reset-', true) . '@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
    }

    private function assignWorkspaceRole(int $userId, int $workspaceId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$workspaceId, $userId, (int) ($role['id'] ?? 0), $userId]
        );
    }

    private function grantRolePermission(string $roleSlug, string $permissionKey): void
    {
        Database::execute(
            "INSERT INTO permissions (permission_key, label, description, is_sensitive)
             VALUES (?, ?, 'Settings reset test permission', 1)
             ON DUPLICATE KEY UPDATE label = VALUES(label)",
            [$permissionKey, $permissionKey]
        );
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = ? LIMIT 1", [$permissionKey]);
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
            [(int) ($role['id'] ?? 0), (int) ($permission['id'] ?? 0)]
        );
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if (!$existing) {
            Database::execute(
                "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
                [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
            );
        }
        Database::execute(
            "INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary)",
            [$id, $slug]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $userId, int $workspaceId, string $roleSlug): array
    {
        $workspace = Database::queryOne("SELECT uuid, name, slug FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ?",
            [$workspaceId, $userId]
        ) ?? [];
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ?", [$userId]) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => $roleSlug,
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
        ];
    }
}
