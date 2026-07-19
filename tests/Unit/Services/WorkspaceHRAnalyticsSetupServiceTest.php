<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceHRAnalyticsSetupService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceHRAnalyticsSetupServiceTest extends DatabaseTestCase
{
    public function testReadinessDoesNotRequireFunctionAssignments(): void
    {
        $workspaceId = 1;
        $this->ensureHrSettings($workspaceId);
        (new OrganizationFunctionService())->ensureDefaults($workspaceId);
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ?", [$workspaceId]);

        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);

        $this->assertTrue((bool) ($status['ready'] ?? false), json_encode($status, JSON_PRETTY_PRINT));
        $this->assertNotContains('Work Ownership assignment', (array) ($status['blockers'] ?? []));
        $this->assertSame(0, (int) ($status['counts']['function_assignments'] ?? -1));
    }

    public function testFounderOnlyWorkspaceCanUnlockWithoutDepartments(): void
    {
        $workspaceId = 1;
        $this->ensureHrSettings($workspaceId);
        Database::execute("UPDATE workspace_memberships SET department_id = NULL WHERE workspace_id = ?", [$workspaceId]);
        Database::execute("UPDATE departments SET is_active = 0 WHERE workspace_id = ?", [$workspaceId]);

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ?", [$workspaceId]);

        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);

        $this->assertTrue((bool) ($status['ready'] ?? false));
        $this->assertSame([], (array) ($status['blockers'] ?? []));
        $this->assertSame(0, (int) ($status['counts']['assigned_members'] ?? -1));
        $this->assertSame(0, (int) ($status['counts']['function_assignments'] ?? -1));
    }

    public function testDefaultSettingsCanUnlockWithoutPhysicalSettingsRow(): void
    {
        $workspaceId = 1;
        Database::execute("DELETE FROM hr_analytics_settings WHERE workspace_id = ?", [$workspaceId]);
        Database::execute("UPDATE workspace_memberships SET department_id = NULL WHERE workspace_id = ?", [$workspaceId]);

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ?", [$workspaceId]);

        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);

        $this->assertTrue((bool) ($status['ready'] ?? false), json_encode($status, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($status['counts']['settings_ready'] ?? 0));
        $this->assertNotContains('Organization Intelligence settings', (array) ($status['blockers'] ?? []));
        $this->assertNull(Database::queryOne("SELECT id FROM hr_analytics_settings WHERE workspace_id = ? LIMIT 1", [$workspaceId]));
    }

    public function testDepartmentsDoNotNeedFunctionAssignmentsToUnlock(): void
    {
        $workspaceId = 1;
        $this->ensureHrSettings($workspaceId);
        $departmentId = $this->ensureDepartment($workspaceId, 'sales', 'Sales');
        $member = $this->firstActiveMember($workspaceId);
        Database::execute(
            "UPDATE workspace_memberships SET department_id = ? WHERE workspace_id = ? AND user_id = ?",
            [$departmentId, $workspaceId, (int) $member['user_id']]
        );
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ?", [$workspaceId]);

        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);

        $this->assertTrue((bool) ($status['ready'] ?? false), json_encode($status, JSON_PRETTY_PRINT));
        $this->assertNotContains('Work Ownership assignment', (array) ($status['blockers'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($status['counts']['assigned_members'] ?? 0));
    }

    public function testMissingActiveBusinessAreasStillBlocksReadiness(): void
    {
        $workspaceId = 1;
        $this->ensureHrSettings($workspaceId);
        (new OrganizationFunctionService())->ensureDefaults($workspaceId);
        Database::execute("UPDATE organization_functions SET is_active = 0 WHERE workspace_id = ?", [$workspaceId]);

        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);

        $this->assertFalse((bool) ($status['ready'] ?? true), json_encode($status, JSON_PRETTY_PRINT));
        $this->assertContains('Active Work Ownership areas', (array) ($status['blockers'] ?? []));
        $this->assertSame(0, (int) ($status['counts']['active_functions'] ?? -1));
    }

    private function ensureHrSettings(int $workspaceId): void
    {
        Database::execute(
            "INSERT INTO hr_analytics_settings (
                workspace_id, ai_enabled, scoring_weights_json, thresholds_json, department_mappings_json, prompt_config_json
            ) VALUES (?, 1, '{}', '{}', '{}', '{}')
            ON DUPLICATE KEY UPDATE ai_enabled = VALUES(ai_enabled)",
            [$workspaceId]
        );
    }

    private function ensureDepartment(int $workspaceId, string $slug, string $name): int
    {
        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
             VALUES (?, ?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)",
            [$workspaceId, $name, $slug, $name . ' department']
        );

        $row = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND slug = ? LIMIT 1",
            [$workspaceId, $slug]
        );

        return (int) ($row['id'] ?? 0);
    }

    private function firstActiveMember(int $workspaceId): array
    {
        $member = Database::queryOne(
            "SELECT user_id FROM workspace_memberships WHERE workspace_id = ? AND membership_status = 'active' ORDER BY id ASC LIMIT 1",
            [$workspaceId]
        );
        if ($member) {
            return $member;
        }

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('oi-ready-user-', true), 'oi-ready@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
             VALUES (?, ?, 'owner', 'active', NOW())",
            [$workspaceId, $userId]
        );

        return ['user_id' => $userId];
    }

}
