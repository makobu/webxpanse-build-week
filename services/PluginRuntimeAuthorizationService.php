<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class PluginRuntimeAuthorizationService
{
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;

    public function __construct(?WorkspaceSkillCatalogService $catalog = null, ?WorkspaceSkillInstallService $installer = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
    }

    public function canAccessRuntimeModule(int $workspaceId, int $userId, string $skillKey): bool
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($workspaceId <= 0 || $skillKey === '') {
            return false;
        }
        if ($this->catalog->isGloballyDeactivated($skillKey)) {
            return false;
        }

        return $this->installer->canExposeRuntimeModule($workspaceId, $skillKey);
    }

    public function canUseCapability(int $workspaceId, int $userId, string $skillKey, string $capabilityKey): bool
    {
        if (!$this->canAccessRuntimeModule($workspaceId, $userId, $skillKey)) {
            return false;
        }

        $capability = (new PluginRuntimeRegistryService($this->catalog, $this->installer))->findCapability(
            $workspaceId,
            $capabilityKey,
            true
        );
        if (!$capability || (string) ($capability['status'] ?? '') !== 'active') {
            return false;
        }
        if ((string) ($capability['skill_key'] ?? '') !== $skillKey) {
            return false;
        }

        foreach ((array) ($capability['permissions'] ?? []) as $permission) {
            if ($permission === '') {
                continue;
            }
            if (!Authorization::can((string) $permission, $this->loadUser($userId))) {
                return false;
            }
        }

        return true;
    }

    public function assertCanUseCapability(int $workspaceId, int $userId, string $skillKey, string $capabilityKey): void
    {
        if (!$this->canUseCapability($workspaceId, $userId, $skillKey, $capabilityKey)) {
            (new PluginRuntimeEventService())->record([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'skill_key' => $skillKey,
                'capability_key' => $capabilityKey,
                'event_type' => 'authorization_blocked',
                'status' => 'blocked',
            ]);
            throw new \RuntimeException('You do not have access to this plugin capability.');
        }
    }

    private function loadUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return Database::queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim($key)) ?? '');
    }
}
