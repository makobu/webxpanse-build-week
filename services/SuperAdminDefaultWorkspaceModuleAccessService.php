<?php

namespace CRM\Services;

use CRM\Authorization;

class SuperAdminDefaultWorkspaceModuleAccessService
{
    /**
     * @param array<string,mixed>|null $user
     */
    public function canBypassModuleAccessGates(int $workspaceId, ?array $user = null): bool
    {
        if ($workspaceId <= 0 || !Authorization::isSuperAdmin($user)) {
            return false;
        }

        try {
            return WorkspaceContext::isDefaultWorkspace($workspaceId);
        } catch (\Throwable $e) {
            return (new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId);
        }
    }

    public function canBypassModuleAccessGatesForUserId(int $workspaceId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->canBypassModuleAccessGates($workspaceId, ['id' => $userId]);
    }
}
