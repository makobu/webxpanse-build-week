<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Session;
use CRM\Authorization;

class WorkspaceContext
{
    /** @var array<string,mixed>|null */
    private static ?array $runtimeWorkspace = null;
    private static bool $recoveringSessionWorkspace = false;
    private static ?int $validatedSessionWorkspaceId = null;
    private static ?int $validatedSessionUserId = null;

    /**
     * @return array<string,mixed>|null
     */
    public static function runtimeSnapshot(): ?array
    {
        return self::$runtimeWorkspace;
    }

    public static function currentWorkspaceId(): ?int
    {
        if (self::$runtimeWorkspace !== null) {
            $runtimeWorkspaceId = (int) (self::$runtimeWorkspace['workspace_id'] ?? self::$runtimeWorkspace['id'] ?? 0);
            if ($runtimeWorkspaceId > 0) {
                return $runtimeWorkspaceId;
            }
        }

        $workspaceId = (int) (Session::get('active_workspace_id') ?? 0);
        $userId = (int) (Session::get('user_id') ?? 0);
        if ($workspaceId <= 0) {
            return $userId > 0 ? self::recoverWorkspaceSession($userId) : null;
        }

        if (
            self::$validatedSessionWorkspaceId === $workspaceId
            && self::$validatedSessionUserId === $userId
        ) {
            return $workspaceId;
        }

        if ($userId <= 0 || self::sessionWorkspaceIsValid($workspaceId, $userId)) {
            if ($userId > 0 && self::workspaceRequiresTwoFactorSetup($workspaceId, $userId)) {
                self::clear();
                return null;
            }

            self::$validatedSessionWorkspaceId = $workspaceId;
            self::$validatedSessionUserId = $userId;
            return $workspaceId;
        }

        return self::recoverWorkspaceSession($userId);
    }

    public static function currentWorkspace(): ?array
    {
        if (self::$runtimeWorkspace !== null && !empty(self::$runtimeWorkspace['id'])) {
            return [
                'id' => (int) (self::$runtimeWorkspace['workspace_id'] ?? self::$runtimeWorkspace['id'] ?? 0),
                'uuid' => (string) (self::$runtimeWorkspace['workspace_uuid'] ?? self::$runtimeWorkspace['uuid'] ?? ''),
                'name' => (string) (self::$runtimeWorkspace['workspace_name'] ?? self::$runtimeWorkspace['name'] ?? ''),
                'slug' => (string) (self::$runtimeWorkspace['workspace_slug'] ?? self::$runtimeWorkspace['slug'] ?? ''),
                'status' => (string) (self::$runtimeWorkspace['status'] ?? ''),
                'plan_status' => (string) (self::$runtimeWorkspace['plan_status'] ?? ''),
                'trial_starts_at' => self::$runtimeWorkspace['trial_starts_at'] ?? null,
                'trial_ends_at' => self::$runtimeWorkspace['trial_ends_at'] ?? null,
            ];
        }

        $workspaceId = self::currentWorkspaceId();
        if ($workspaceId === null) {
            return null;
        }

        return Database::queryOne(
            "SELECT id, uuid, name, slug, status, plan_status, trial_starts_at, trial_ends_at
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );
    }

    /**
     * @param array<string,mixed>|null $workspace
     */
    public static function isDefaultWorkspace(?int $workspaceId = null, ?array $workspace = null): bool
    {
        try {
            return (new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId, $workspace);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function activateForUser(int $userId, ?string $workspaceSlug = null): ?array
    {
        $membershipService = new WorkspaceMembershipService();
        $membership = null;

        if ($workspaceSlug !== null && trim($workspaceSlug) !== '') {
            $membership = $membershipService->getActiveMembershipBySlug(trim($workspaceSlug), $userId);
        }

        if ($membership === null && $workspaceSlug === null) {
            $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]) ?: [];
            if (Authorization::isSuperAdmin($user)) {
                try {
                    $defaultWorkspace = (new DefaultWorkspaceService())->resolve();
                } catch (\Throwable $e) {
                    $defaultWorkspace = [];
                }
                if (!empty($defaultWorkspace['id'])) {
                    $membership = $membershipService->getActiveMembership((int) $defaultWorkspace['id'], $userId);
                    if ($membership === null) {
                        $membershipService->addOrUpdateMembership((int) $defaultWorkspace['id'], $userId, 'superadmin', true, $userId);
                        $membership = $membershipService->getActiveMembership((int) $defaultWorkspace['id'], $userId);
                    }
                }
            }
        }

        if ($membership === null) {
            $memberships = $membershipService->listForUser($userId);
            $membership = $memberships[0] ?? null;
        }

        if ($membership === null) {
            self::clear();
            return null;
        }

        if (self::membershipRequiresTwoFactorSetup($membership, $userId)) {
            self::clear();
            return null;
        }

        self::setWorkspaceSession($membership);
        return $membership;
    }

    public static function activateWorkspaceById(int $userId, int $workspaceId): ?array
    {
        $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
        if ($membership === null) {
            return null;
        }

        if (self::membershipRequiresTwoFactorSetup($membership, $userId)) {
            return null;
        }

        self::setWorkspaceSession($membership);
        return $membership;
    }

    public static function clear(): void
    {
        self::$runtimeWorkspace = null;
        self::$validatedSessionWorkspaceId = null;
        self::$validatedSessionUserId = null;
        Session::remove('active_workspace_id');
        Session::remove('active_workspace_uuid');
        Session::remove('active_workspace_slug');
        Session::remove('active_workspace_name');
        Session::remove('active_workspace_role');
        Session::remove('active_workspace_membership_id');
    }

    private static function sessionWorkspaceIsValid(int $workspaceId, int $userId): bool
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            return Database::queryOne(
                "SELECT wm.id
                 FROM workspace_memberships wm
                 JOIN workspaces w ON w.id = wm.workspace_id
                 WHERE wm.workspace_id = ?
                   AND wm.user_id = ?
                   AND wm.membership_status = 'active'
                 LIMIT 1",
                [$workspaceId, $userId]
            ) !== null;
        } catch (\Throwable $e) {
            error_log('WorkspaceContext session validation failed: ' . $e->getMessage());
            self::clear();
            return false;
        }
    }

    private static function recoverWorkspaceSession(int $userId): ?int
    {
        if ($userId <= 0 || self::$recoveringSessionWorkspace) {
            return null;
        }

        self::$recoveringSessionWorkspace = true;
        try {
            $membership = self::activateForUser($userId);
            $workspaceId = (int) ($membership['workspace_id'] ?? $membership['id'] ?? 0);
            return $workspaceId > 0 ? $workspaceId : null;
        } catch (\Throwable $e) {
            error_log('WorkspaceContext session recovery failed: ' . $e->getMessage());
            self::clear();
            return null;
        } finally {
            self::$recoveringSessionWorkspace = false;
        }
    }

    public static function clearRuntimeWorkspace(): void
    {
        self::$runtimeWorkspace = null;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     */
    public static function restoreRuntimeWorkspace(?array $snapshot): void
    {
        self::$runtimeWorkspace = $snapshot;
    }

    /**
     * @param array<string,mixed> $membership
     */
    public static function setWorkspaceSession(array $membership): void
    {
        self::$runtimeWorkspace = null;
        $workspaceId = (int) ($membership['workspace_id'] ?? $membership['id'] ?? 0);
        $userId = (int) (Session::get('user_id') ?? 0);
        self::$validatedSessionWorkspaceId = $workspaceId > 0 ? $workspaceId : null;
        self::$validatedSessionUserId = $userId > 0 ? $userId : null;
        Session::set('active_workspace_id', $workspaceId);
        Session::set('active_workspace_uuid', (string) ($membership['workspace_uuid'] ?? ''));
        Session::set('active_workspace_slug', (string) ($membership['workspace_slug'] ?? ''));
        Session::set('active_workspace_name', (string) ($membership['workspace_name'] ?? ''));
        Session::set('active_workspace_role', (string) ($membership['role_slug'] ?? 'viewer'));
        Session::set('active_workspace_membership_id', (int) ($membership['id'] ?? 0));
    }

    private static function membershipRequiresTwoFactorSetup(array $membership, int $userId): bool
    {
        $workspaceId = (int) ($membership['workspace_id'] ?? $membership['id'] ?? 0);
        if ($workspaceId <= 0 || $userId <= 0) {
            return false;
        }

        return self::workspaceRequiresTwoFactorSetup($workspaceId, $userId, $membership);
    }

    private static function workspaceRequiresTwoFactorSetup(int $workspaceId, int $userId, array $workspace = []): bool
    {
        try {
            $security = new WorkspaceSecuritySettingsService();
            if (!$security->requiresMember2FA($workspaceId) || WorkspaceSecuritySettingsService::userHasTwoFactor($userId)) {
                WorkspaceSecuritySettingsService::clearPendingSetup();
                return false;
            }

            WorkspaceSecuritySettingsService::setPendingSetup($userId, $workspaceId, $workspace);
            return true;
        } catch (\Throwable $e) {
            error_log('Workspace 2FA access check failed: ' . $e->getMessage());
            return true;
        }
    }

    public static function activateRuntimeWorkspace(int $workspaceId, ?int $userId = null, ?string $roleSlug = null): ?array
    {
        if ($workspaceId <= 0) {
            self::$runtimeWorkspace = null;
            return null;
        }

        $workspace = Database::queryOne(
            "SELECT id, uuid, name, slug, status, plan_status, trial_starts_at, trial_ends_at
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if ($workspace === null) {
            self::$runtimeWorkspace = null;
            return null;
        }

        if ($userId !== null && $userId > 0) {
            $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
            if ($membership !== null) {
                $roleSlug = (string) ($membership['role_slug'] ?? $roleSlug ?? 'viewer');
            }
        }

        self::$runtimeWorkspace = [
            'id' => (int) ($workspace['id'] ?? 0),
            'workspace_id' => (int) ($workspace['id'] ?? 0),
            'uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'name' => (string) ($workspace['name'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'status' => (string) ($workspace['status'] ?? ''),
            'plan_status' => (string) ($workspace['plan_status'] ?? ''),
            'trial_starts_at' => $workspace['trial_starts_at'] ?? null,
            'trial_ends_at' => $workspace['trial_ends_at'] ?? null,
            'role_slug' => $roleSlug ?? 'viewer',
        ];

        return self::$runtimeWorkspace;
    }

    public static function currentRoleSlug(): ?string
    {
        if (self::$runtimeWorkspace !== null) {
            $runtimeRole = trim((string) (self::$runtimeWorkspace['role_slug'] ?? ''));
            if ($runtimeRole !== '') {
                return $runtimeRole;
            }
        }

        $role = trim((string) Session::get('active_workspace_role', ''));
        return $role !== '' ? $role : null;
    }
}
