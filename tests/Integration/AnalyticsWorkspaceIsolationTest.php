<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class AnalyticsWorkspaceIsolationTest extends DatabaseTestCase
{
    private int $userId = 0;
    private int $workspaceOneUserId = 0;
    private int $workspaceTwoUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');
        $this->userId = $this->createUser('analytics-owner@example.test', 'Owner', 'Analyst');
        $this->workspaceOneUserId = $this->createUser('workspace-one@example.test', 'Workspace', 'One');
        $this->workspaceTwoUserId = $this->createUser('workspace-two@example.test', 'Workspace', 'Two');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);
        $memberships->addOrUpdateMembership(1, $this->workspaceOneUserId, 'member', true, $this->userId);
        $memberships->addOrUpdateMembership(2, $this->workspaceTwoUserId, 'member', true, $this->userId);
        $this->completeOnboarding(1);
        $this->ensureHrAnalyticsReady(1, $this->workspaceOneUserId);

        $this->grantAnalyticsAccess($this->userId);
        \CRM\Authorization::assignUserRoleBySlug($this->userId, 'superadmin', $this->userId);

        $this->insertContact(1, 'Current', 'Workspace', 'current.workspace.contact@example.test', $this->userId, 82, 76);
        $this->insertContact(2, 'Foreign', 'Workspace', 'foreign.workspace.contact@example.test', $this->workspaceTwoUserId, 91, 88);
    }

    public function testHrAnalyticsUserSelectorExcludesForeignWorkspaceMembers(): void
    {
        $body = $this->renderPage('hr_analytics.php');

        $this->assertStringContainsString('Organization Intelligence', $body);
        $this->assertStringContainsString('Workspace One', $body);
        $this->assertStringNotContainsString('Workspace Two', $body);
    }

    public function testAnalyticsUserSelectorExcludesForeignWorkspaceMembers(): void
    {
        $body = $this->renderPage('analytics.php', ['user_id' => '']);

        $this->assertStringContainsString('Workspace One', $body);
        $this->assertStringNotContainsString('Workspace Two', $body);
    }

    public function testLeadScoringPageExcludesForeignWorkspaceContacts(): void
    {
        $body = $this->renderPage('lead_scoring.php');

        $this->assertStringContainsString('Current Workspace', $body);
        $this->assertStringNotContainsString('Foreign Workspace', $body);
    }

    public function testMlScoringDashboardUsesWorkspaceBoundedContactStats(): void
    {
        $body = $this->renderPage('ml_scoring_dashboard.php');

        $this->assertMatchesRegularExpression('/<span>Total Contacts<\/span>\s*<strong>1<\/strong>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<span>Total Contacts<\/span>\s*<strong>2<\/strong>/', $body);
    }

    public function testNavHidesRuleScoringWithoutScoringPermission(): void
    {
        $mlOnlyUserId = $this->createUser('ml-only-nav@example.test', 'ML', 'Only');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $mlOnlyUserId, 'member', true, $this->userId);
        $this->grantPermissions($mlOnlyUserId, 'ml-only-nav', [
            'feature.ml_scoring_dashboard' => 'View ML scoring dashboard',
        ]);

        $body = $this->renderPage('ml_scoring_dashboard.php', [], $mlOnlyUserId, 'ml-only-nav@example.test');

        $this->assertStringNotContainsString('Rule Scoring', $body);
        $this->assertStringContainsString('ML Models', $body);
    }

    public function testNavHidesMlScoringWithoutMlDashboardPermission(): void
    {
        $scoringOnlyUserId = $this->createUser('scoring-only-nav@example.test', 'Scoring', 'Only');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $scoringOnlyUserId, 'member', true, $this->userId);
        $this->grantPermissions($scoringOnlyUserId, 'scoring-only-nav', [
            'settings.scoring' => 'Manage lead scoring',
        ]);

        $body = $this->renderPage('lead_scoring.php', [], $scoringOnlyUserId, 'scoring-only-nav@example.test');

        $this->assertStringContainsString('Rule Scoring', $body);
        $this->assertStringNotContainsString('ML Models', $body);
        $this->assertStringNotContainsString('ml_scoring_dashboard.php', $body);
    }

    public function testMlScoringDashboardHidesTrainingControlsWithoutTrainingPermission(): void
    {
        $mlOnlyUserId = $this->createUser('ml-no-training@example.test', 'ML', 'Viewer');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $mlOnlyUserId, 'member', true, $this->userId);
        $this->grantPermissions($mlOnlyUserId, 'ml-no-training', [
            'feature.ml_scoring_dashboard' => 'View ML scoring dashboard',
        ]);

        $body = $this->renderPage('ml_scoring_dashboard.php', [], $mlOnlyUserId, 'ml-no-training@example.test');

        $this->assertStringContainsString('ML Scoring Models', $body);
        $this->assertStringNotContainsString('Train New Model', $body);
        $this->assertStringNotContainsString('Recalculate All Scores', $body);
        $this->assertStringNotContainsString('id="trainModelForm"', $body);
    }

    private function createUser(string $email, string $firstName, string $lastName): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, 'admin', ?, ?, NOW())",
            [uniqid('analytics-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $firstName, $lastName]
        );

        return (int) Database::lastInsertId();
    }

    private function insertContact(
        int $workspaceId,
        string $firstName,
        string $lastName,
        string $email,
        int $assignedTo,
        int $leadScore = 0,
        ?int $mlScore = null
    ): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, lead_score, engagement_score, ml_score, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$workspaceId, uniqid('contact-', true), $firstName, $lastName, $email, $assignedTo, $leadScore, $leadScore, $mlScore]
        );
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function ensureHrAnalyticsReady(int $workspaceId, int $memberUserId): void
    {
        Database::execute(
            "INSERT INTO hr_analytics_settings (
                workspace_id, ai_enabled, scoring_weights_json, thresholds_json, department_mappings_json, prompt_config_json
            ) VALUES (?, 1, '{}', '{}', '{}', '{}')
            ON DUPLICATE KEY UPDATE ai_enabled = VALUES(ai_enabled)",
            [$workspaceId]
        );
        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
             VALUES (?, 'Sales', 'sales', 'Sales team', 1, 1)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)",
            [$workspaceId]
        );
        $department = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND slug = 'sales' LIMIT 1",
            [$workspaceId]
        );
        Database::execute(
            "UPDATE workspace_memberships
             SET department_id = ?
             WHERE workspace_id = ? AND user_id = ?",
            [(int) ($department['id'] ?? 0), $workspaceId, $memberUserId]
        );
    }

    private function completeOnboarding(int $workspaceId): void
    {
        Database::execute(
            "INSERT INTO workspace_onboarding_state (workspace_id, status, completed_at, created_at, updated_at)
             VALUES (?, 'completed', NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), completed_at = VALUES(completed_at), updated_at = NOW()",
            [$workspaceId]
        );
    }

    private function grantAnalyticsAccess(int $userId): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES ('Analytics Admin', 'analytics-admin', 'Analytics test role', 1, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active)"
        );

        foreach ([
            'analytics.view_all' => 'View all analytics',
            'feature.dashboard_filter' => 'Use dashboard filter',
            'hr.analytics.view' => 'View HR analytics',
            'settings.scoring' => 'Manage lead scoring',
            'feature.ml_scoring_dashboard' => 'View ML scoring dashboard',
        ] as $permissionKey => $label) {
            Database::execute(
                "INSERT INTO permissions (permission_key, label, description, is_sensitive)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)",
                [$permissionKey, $label, $label . ' for analytics isolation test']
            );
        }

        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'analytics-admin' LIMIT 1");
        $this->assertNotNull($role);
        $roleId = (int) ($role['id'] ?? 0);

        foreach (['analytics.view_all', 'feature.dashboard_filter', 'hr.analytics.view', 'settings.scoring', 'feature.ml_scoring_dashboard'] as $permissionKey) {
            $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = ? LIMIT 1", [$permissionKey]);
            $this->assertNotNull($permission);
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$roleId, (int) ($permission['id'] ?? 0)]
            );
        }

        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, $roleId, $userId]
        );
    }

    /**
     * @param array<string,string> $permissions
     */
    private function grantPermissions(int $userId, string $roleSlug, array $permissions): void
    {
        $roleName = ucwords(str_replace('-', ' ', $roleSlug));
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES (?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active)",
            [$roleName, $roleSlug, $roleName . ' test role']
        );

        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotNull($role);
        $roleId = (int) ($role['id'] ?? 0);

        foreach ($permissions as $permissionKey => $label) {
            Database::execute(
                "INSERT INTO permissions (permission_key, label, description, is_sensitive)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)",
                [$permissionKey, $label, $label . ' for analytics navigation test']
            );

            $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = ? LIMIT 1", [$permissionKey]);
            $this->assertNotNull($permission);
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$roleId, (int) ($permission['id'] ?? 0)]
            );
        }

        \CRM\Authorization::assignUserRoleBySlug($userId, $roleSlug, $this->userId);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function renderPage(
        string $page,
        array $query = [],
        ?int $renderUserId = null,
        ?string $renderUserEmail = null
    ): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::destroy();
        }

        $this->resetSessionStartedFlag();
        Session::start();
        $sessionUserId = $renderUserId ?? $this->userId;
        Session::set('user_id', $sessionUserId);
        Session::set('user_uuid', 'analytics-user-' . $sessionUserId);
        Session::set('user_email', $renderUserEmail ?? 'analytics-owner@example.test');
        Session::set('user_role', 'admin');
        Session::set('active_workspace_id', 1);
        Session::set('active_workspace_uuid', '00000000-0000-4000-8000-000000000001');
        Session::set('active_workspace_slug', 'default');
        Session::set('active_workspace_name', 'Default Workspace');
        Session::set('active_workspace_role', 'owner');
        Session::set('active_workspace_membership_id', 1);
        Session::set('__remember_restore_attempted', true);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/billing_payment_required.php';
        $_SERVER['PHP_SELF'] = '/' . $page;
        $_SERVER['SCRIPT_NAME'] = '/' . $page;
        $_GET = $query;
        $_POST = [];

        $level = ob_get_level();
        ob_start();
        try {
            include __DIR__ . '/../../public/' . $page;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private function resetSessionStartedFlag(): void
    {
        $reflection = new \ReflectionClass(Session::class);
        $property = $reflection->getProperty('started');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}
