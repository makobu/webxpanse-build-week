<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Authorization;
use CRM\Session;

class WorkspaceMembershipService
{
    private const ACTIVE_STATUS = 'active';

    public function listForUser(int $userId): array
    {
        if ($userId <= 0 || !Database::tableExists('workspace_memberships')) {
            return [];
        }

        $memberships = Database::query(
            "SELECT wm.*, w.uuid AS workspace_uuid, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status, w.plan_status
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE wm.user_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, w.name ASC",
            [$userId]
        );

        return array_values(array_filter($memberships, function (array $membership) use ($userId): bool {
            $workspaceId = (int) ($membership['workspace_id'] ?? 0);
            if (!(new DemoWorkspaceService())->isDemoWorkspace($workspaceId)) {
                return true;
            }

            $activeDemoWorkspaceId = (int) (Session::get('demo_workspace_id') ?? 0);
            $activeDemoSessionId = (int) (Session::get('demo_visitor_session_id') ?? 0);
            if ($activeDemoWorkspaceId === $workspaceId && $activeDemoSessionId > 0) {
                return true;
            }

            $user = Database::queryOne("SELECT id, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?: [];
            return Authorization::isSuperAdmin($user);
        }));
    }

    public function getMembership(int $workspaceId, int $userId): ?array
    {
        return $this->findMembership($workspaceId, $userId, false);
    }

    public function getActiveMembership(int $workspaceId, int $userId): ?array
    {
        return $this->findMembership($workspaceId, $userId, true);
    }

    public function getMembershipBySlug(string $workspaceSlug, int $userId): ?array
    {
        return $this->findMembershipBySlug($workspaceSlug, $userId, false);
    }

    public function getActiveMembershipBySlug(string $workspaceSlug, int $userId): ?array
    {
        return $this->findMembershipBySlug($workspaceSlug, $userId, true);
    }

    private function findMembership(int $workspaceId, int $userId, bool $activeOnly): ?array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('workspace_memberships')) {
            return null;
        }

        return Database::queryOne(
            "SELECT wm.*, w.uuid AS workspace_uuid, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status, w.plan_status
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE wm.workspace_id = ? AND wm.user_id = ?" . ($activeOnly ? " AND wm.membership_status = '" . self::ACTIVE_STATUS . "'" : '') . "
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    private function findMembershipBySlug(string $workspaceSlug, int $userId, bool $activeOnly): ?array
    {
        $workspaceSlug = trim($workspaceSlug);
        if ($workspaceSlug === '' || $userId <= 0 || !Database::tableExists('workspace_memberships')) {
            return null;
        }

        return Database::queryOne(
            "SELECT wm.*, w.uuid AS workspace_uuid, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status, w.plan_status
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE w.slug = ? AND wm.user_id = ?" . ($activeOnly ? " AND wm.membership_status = '" . self::ACTIVE_STATUS . "'" : '') . "
             LIMIT 1",
            [$workspaceSlug, $userId]
        );
    }

    public function addOrUpdateMembership(
        int $workspaceId,
        int $userId,
        string $roleSlug = 'viewer',
        bool $isOwner = false,
        ?int $invitedBy = null
    ): int {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \RuntimeException('Workspace and user are required.');
        }

        $roleSlug = trim($roleSlug) !== '' ? trim($roleSlug) : 'viewer';
        $existing = Database::queryOne(
            "SELECT id, membership_status FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        );

        if ($existing) {
            $existingStatus = (string) ($existing['membership_status'] ?? '');
            if ($existingStatus !== self::ACTIVE_STATUS) {
                $this->assertSeatAvailable($workspaceId, $userId);
            }
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = ?, membership_status = 'active', is_owner = ?, joined_at = COALESCE(joined_at, NOW()), invited_by = ?, updated_at = NOW()
                 WHERE id = ?",
                [$roleSlug, $isOwner ? 1 : 0, $invitedBy, (int) $existing['id']]
            );

            Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, $roleSlug, $invitedBy);
            if ($isOwner || $roleSlug === 'owner') {
                (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, $userId);
            }

            return (int) $existing['id'];
        }

        $this->assertSeatAvailable($workspaceId, $userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, ?, 'active', ?, NOW(), ?)",
            [$workspaceId, $userId, $roleSlug, $isOwner ? 1 : 0, $invitedBy]
        );
        $membershipId = (int) Database::lastInsertId();

        Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, $roleSlug, $invitedBy);
        if ($isOwner || $roleSlug === 'owner') {
            (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, $userId);
        }

        return $membershipId;
    }

    public function userCanAccessWorkspace(int $workspaceId, int $userId): bool
    {
        return $this->getActiveMembership($workspaceId, $userId) !== null;
    }

    private function assertSeatAvailable(int $workspaceId, int $userId): void
    {
        if ((new DemoWorkspaceService())->isDemoWorkspace($workspaceId)
            || (new PresentationWorkspaceGuardService())->isPresentationWorkspace($workspaceId)) {
            return;
        }

        $limit = (new WorkspacePlanEntitlementService())->seatLimit($workspaceId);
        if ($limit <= 0) {
            return;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND user_id <> ?",
            [$workspaceId, $userId]
        );

        if ((int) ($row['c'] ?? 0) >= $limit) {
            throw new \RuntimeException('This workspace has reached the seat limit for its current package.');
        }
    }
}
