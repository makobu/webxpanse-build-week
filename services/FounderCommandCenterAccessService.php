<?php

namespace CRM\Services;

use CRM\Authorization;

/**
 * Keeps the workspace-wide founder brief on an explicit executive boundary.
 */
final class FounderCommandCenterAccessService
{
    public function canView(array $user, int $workspaceId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $workspaceId <= 0) {
            return false;
        }

        try {
            if ((new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        if (Authorization::isSuperAdmin($user)) {
            return true;
        }

        try {
            $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
        } catch (\Throwable $e) {
            return false;
        }

        return self::membershipCanView($membership);
    }

    public static function membershipCanView(?array $membership): bool
    {
        if (!$membership) {
            return false;
        }

        $roleSlug = strtolower(trim((string) ($membership['role_slug'] ?? '')));
        return !empty($membership['is_owner']) || in_array($roleSlug, ['owner', 'admin'], true);
    }
}
