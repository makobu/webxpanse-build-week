<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceService
{
    public const DEFAULT_ID = 1;
    public const DEFAULT_SLUG = 'default';

    /**
     * @return array<string,mixed>
     */
    public function resolve(): array
    {
        $workspace = Database::queryOne(
            "SELECT *
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [self::DEFAULT_ID]
        );

        if (!$workspace) {
            throw new \RuntimeException('Default workspace was not found.');
        }

        if (!$this->isDefaultWorkspace(null, $workspace)) {
            throw new \RuntimeException('Resolved workspace is not the protected default workspace.');
        }

        return $workspace;
    }

    public function id(): int
    {
        return (int) ($this->resolve()['id'] ?? 0);
    }

    /**
     * @param array<string,mixed>|null $workspace
     */
    public function isDefaultWorkspace(?int $workspaceId = null, ?array $workspace = null): bool
    {
        if ($workspace !== null) {
            $rowId = (int) ($workspace['workspace_id'] ?? $workspace['id'] ?? 0);
            $slug = (string) ($workspace['workspace_slug'] ?? $workspace['slug'] ?? '');
            return $rowId === self::DEFAULT_ID && $slug === self::DEFAULT_SLUG;
        }

        if ($workspaceId === null || $workspaceId <= 0) {
            return false;
        }

        try {
            $row = Database::queryOne(
                "SELECT id, slug
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return $row !== null && $this->isDefaultWorkspace(null, $row);
    }

    public function assertDefaultWorkspace(int $workspaceId): void
    {
        if (!$this->isDefaultWorkspace($workspaceId)) {
            throw new \RuntimeException('Resolved workspace is not the protected default workspace.');
        }
    }

    /**
     * @return array{healthy:bool,checks:array<string,bool>,issues:array<int,string>,warnings:array<int,string>,workspace_id:?int}
     */
    public function health(): array
    {
        $checks = [
            'workspace_exists' => false,
            'id_one_is_default' => false,
            'default_slug_unique' => true,
            'workspace_slug_exists' => false,
            'wallet_exists' => false,
            'superadmin_membership_exists' => false,
        ];
        $issues = [];
        $warnings = [];
        $workspaceId = null;

        if (!Database::tableExists('workspaces')) {
            $issues[] = 'Workspaces table is missing.';
            return compact('checks', 'issues', 'warnings') + ['healthy' => false, 'workspace_id' => null];
        }

        $idWorkspace = Database::queryOne("SELECT id, slug, name FROM workspaces WHERE id = ? LIMIT 1", [self::DEFAULT_ID]);
        $slugRows = Database::query("SELECT id, slug, name FROM workspaces WHERE slug = ? ORDER BY id ASC", [self::DEFAULT_SLUG]);
        $slugCount = count($slugRows);
        $workspace = $idWorkspace ?: ($slugRows[0] ?? null);
        $workspaceId = !empty($workspace['id']) ? (int) $workspace['id'] : null;

        $checks['workspace_exists'] = $workspace !== null;
        $checks['id_one_is_default'] = $idWorkspace !== null && (string) ($idWorkspace['slug'] ?? '') === self::DEFAULT_SLUG;
        $checks['default_slug_unique'] = $slugCount <= 1;

        if (!$checks['workspace_exists']) {
            $issues[] = 'Default workspace row is missing.';
        }
        if ($idWorkspace !== null && (string) ($idWorkspace['slug'] ?? '') !== self::DEFAULT_SLUG) {
            $issues[] = 'Workspace id 1 does not use the default slug.';
        }
        if ($slugCount > 1) {
            $issues[] = 'Multiple workspaces use the default slug.';
        }
        if ($idWorkspace === null && $slugCount === 1) {
            $warnings[] = 'A default slug workspace exists but it is not workspace id 1.';
        }

        if ($workspaceId !== null && Database::tableExists('workspace_slugs')) {
            $checks['workspace_slug_exists'] = Database::queryOne(
                "SELECT id FROM workspace_slugs WHERE workspace_id = ? AND slug = ? LIMIT 1",
                [$workspaceId, self::DEFAULT_SLUG]
            ) !== null;
            if (!$checks['workspace_slug_exists']) {
                $warnings[] = 'Default workspace slug alias is missing.';
            }
        }

        if ($workspaceId !== null && Database::tableExists('workspace_wallets')) {
            $checks['wallet_exists'] = Database::queryOne(
                "SELECT workspace_id FROM workspace_wallets WHERE workspace_id = ? LIMIT 1",
                [$workspaceId]
            ) !== null;
            if (!$checks['wallet_exists']) {
                $warnings[] = 'Default workspace wallet is missing.';
            }
        }

        if ($workspaceId !== null && Database::tableExists('workspace_memberships')) {
            $checks['superadmin_membership_exists'] = $this->hasSuperAdminMembership($workspaceId);
            if (!$checks['superadmin_membership_exists']) {
                $warnings[] = 'Default workspace has no active Super Admin membership.';
            }
        }

        return [
            'healthy' => $checks['workspace_exists'] && $checks['id_one_is_default'] && $checks['default_slug_unique'],
            'checks' => $checks,
            'issues' => $issues,
            'warnings' => $warnings,
            'workspace_id' => $workspaceId,
        ];
    }

    private function hasSuperAdminMembership(int $workspaceId): bool
    {
        $roleJoin = Database::tableExists('user_roles') && Database::tableExists('roles')
            ? "LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id"
            : "";
        $roleWhere = Database::tableExists('user_roles') && Database::tableExists('roles')
            ? " OR r.slug = 'superadmin'"
            : "";

        return Database::queryOne(
            "SELECT wm.id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             {$roleJoin}
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
               AND (wm.role_slug = 'superadmin' OR u.role = 'admin'{$roleWhere})
             LIMIT 1",
            [$workspaceId]
        ) !== null;
    }
}
