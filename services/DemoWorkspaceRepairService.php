<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class DemoWorkspaceRepairService
{
    /**
     * Recreate the protected demo baseline without creating live sessions,
     * invites, integrations, exports, or normal workspace memberships.
     *
     * @return array<string,mixed>
     */
    public function ensureBaseline(int $actorUserId = 0, bool $seedPublicStory = true): array
    {
        $this->assertCoreTablesReady();

        $existing = $this->findDemoWorkspace();
        $this->upsertWorkspace($actorUserId);

        $workspace = $this->findDemoWorkspace();
        if (!$workspace) {
            throw new \RuntimeException('Unable to repair the protected demo workspace.');
        }

        $workspaceId = (int) ($workspace['id'] ?? 0);
        $this->ensureWorkspaceSlug($workspaceId);
        $permissions = $this->ensureDemoPermissions();
        $role = $this->ensureDemoViewerRole();
        $rolePermissionCount = $this->ensureDemoViewerRolePermissions();

        $seeded = $seedPublicStory
            ? (new DemoWorkspaceSeedService())->ensureSeeded($workspaceId, $actorUserId)
            : ['created' => 0, 'skipped' => true];

        return [
            'workspace_id' => $workspaceId,
            'created' => $existing === null,
            'slug' => DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
            'permissions_upserted' => $permissions,
            'role_upserted' => $role,
            'role_permissions_granted' => $rolePermissionCount,
            'seeded' => $seeded,
        ];
    }

    private function assertCoreTablesReady(): void
    {
        foreach (['workspaces', 'permissions', 'roles', 'role_permissions'] as $table) {
            if (!Database::tableExists($table)) {
                throw new \RuntimeException('Protected demo repair requires table: ' . $table);
            }
        }
    }

    private function findDemoWorkspace(): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM workspaces
             WHERE slug = ? OR uuid = ?
             ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END, id ASC
             LIMIT 1",
            [
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
                DemoWorkspaceService::DEMO_WORKSPACE_UUID,
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
            ]
        );
    }

    private function upsertWorkspace(int $actorUserId): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, settings_json, created_by)
             SELECT ?, 'Protected Demo Workspace', ?, 'active', 'inactive', ?, ?
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM workspaces
                 WHERE slug = ? OR uuid = ?
             )",
            [
                DemoWorkspaceService::DEMO_WORKSPACE_UUID,
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
                $this->settingsJson(),
                $actorUserId > 0 ? $actorUserId : null,
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
                DemoWorkspaceService::DEMO_WORKSPACE_UUID,
            ]
        );

        Database::execute(
            "UPDATE workspaces
             SET name = 'Protected Demo Workspace',
                 slug = ?,
                 status = 'active',
                 plan_status = 'inactive',
                 settings_json = JSON_SET(
                     COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
                     '$.protected_demo_workspace', TRUE,
                     '$.demo_workspace', TRUE,
                     '$.billing_disabled', TRUE,
                     '$.invites_disabled', TRUE,
                     '$.exports_disabled', TRUE,
                     '$.real_integrations_disabled', TRUE
                 ),
                 updated_at = NOW()
             WHERE slug = ? OR uuid = ?",
            [
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
                DemoWorkspaceService::DEMO_WORKSPACE_SLUG,
                DemoWorkspaceService::DEMO_WORKSPACE_UUID,
            ]
        );
    }

    private function ensureWorkspaceSlug(int $workspaceId): void
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_slugs')) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
                workspace_id = VALUES(workspace_id),
                is_primary = VALUES(is_primary)",
            [$workspaceId, DemoWorkspaceService::DEMO_WORKSPACE_SLUG]
        );
    }

    private function ensureDemoPermissions(): int
    {
        $permissions = [
            ['demo.workspace.access', 'Access Demo Workspace', 'Enter the protected shared demo workspace', false],
            ['demo.channel.simulate', 'Simulate Demo Messages', 'Create simulated email and WhatsApp demo messages', false],
            ['demo.realtime.read', 'Read Demo Realtime Events', 'Read private realtime events for an active demo session', false],
            ['demo.workspace.operate', 'Operate Demo Workspace', 'Manage protected demo workspace sessions, seeding, and cleanup', true],
            ['startup_journey.view', 'View Clarity Journey', 'View the Clarity Journey product story and setup context', false],
        ];

        foreach ($permissions as $permission) {
            Database::execute(
                "INSERT INTO permissions (permission_key, label, description, is_sensitive)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    description = VALUES(description),
                    is_sensitive = VALUES(is_sensitive)",
                [
                    $permission[0],
                    $permission[1],
                    $permission[2],
                    $permission[3] ? 1 : 0,
                ]
            );
        }

        return count($permissions);
    }

    private function ensureDemoViewerRole(): bool
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES ('Demo Viewer', 'demo_viewer', 'Temporary visitor access to the protected shared demo workspace', TRUE, TRUE)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                is_system = VALUES(is_system),
                is_active = VALUES(is_active)"
        );

        return true;
    }

    private function ensureDemoViewerRolePermissions(): int
    {
        $permissionKeys = [
            'demo.workspace.access',
            'demo.channel.simulate',
            'demo.realtime.read',
            'tasks.read',
            'workspace.skills.view',
            'startup_journey.view',
            'founder_loop.view',
            'feature.automation_battery',
        ];

        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             SELECT r.id, p.id, 1
             FROM roles r
             JOIN permissions p ON p.permission_key IN (" . $this->placeholders($permissionKeys) . ")
             WHERE r.slug = 'demo_viewer'
             ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
            $permissionKeys
        );

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'demo_viewer'
               AND p.permission_key IN (" . $this->placeholders($permissionKeys) . ")
               AND rp.can_access = 1",
            $permissionKeys
        )['c'] ?? 0);
    }

    private function settingsJson(): string
    {
        return json_encode([
            'protected_demo_workspace' => true,
            'demo_workspace' => true,
            'billing_disabled' => true,
            'invites_disabled' => true,
            'exports_disabled' => true,
            'real_integrations_disabled' => true,
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param string[] $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
