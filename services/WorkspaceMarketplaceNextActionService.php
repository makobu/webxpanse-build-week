<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Notifications;

class WorkspaceMarketplaceNextActionService
{
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspaceMarketplaceAccessService $access;
    private WorkspaceMarketplaceRecommendationService $recommendations;

    public function __construct(
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceSkillInstallService $installer = null,
        ?WorkspaceMarketplaceAccessService $access = null,
        ?WorkspaceMarketplaceRecommendationService $recommendations = null
    ) {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
        $this->access = $access ?? new WorkspaceMarketplaceAccessService($this->catalog, $this->installer);
        $this->recommendations = $recommendations ?? new WorkspaceMarketplaceRecommendationService($this->catalog, $this->installer, null, null, $this->access);
    }

    public function nextActionForWorkspace(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return $this->browseAction();
        }

        $available = $this->catalog->availableForWorkspace($workspaceId, false);
        $installedByKey = [];
        foreach ($this->installer->installedForWorkspace($workspaceId) as $installed) {
            $installedKey = (string) ($installed['key'] ?? '');
            if ($installedKey !== '') {
                $installedByKey[$installedKey] = $installed;
            }
        }
        $modules = [];

        foreach ($available as $definition) {
            $module = (array) $definition;
            $skillKey = (string) ($module['key'] ?? '');
            if ($skillKey === '') {
                continue;
            }

            $isInstalled = isset($installedByKey[$skillKey]);
            $readiness = $isInstalled
                ? (array) $this->installer->buildReadinessForModule($workspaceId, $userId, $skillKey)
                : ['ready' => false];
            $modules[] = [
                'key' => $skillKey,
                'type' => (string) ($module['type'] ?? 'plugin'),
                'module' => $module,
                'is_installed' => $isInstalled,
                'readiness' => $readiness,
                'access' => $this->access->accessForDefinition($workspaceId, $userId, $module),
            ];
        }

        $finishAction = $this->nextActionFromMarketplaceModules($modules, false);
        if (!empty($finishAction['is_actionable'])) {
            return $finishAction;
        }

        if (!$this->canManageMarketplace()) {
            return $this->browseAction();
        }

        foreach ($this->recommendations->recommendationsForWorkspace($workspaceId, $userId, 5, 'marketplace') as $recommendation) {
            $skillKey = (string) ($recommendation['skill_key'] ?? '');
            if ($skillKey === '' || !empty($recommendation['is_installed'])) {
                continue;
            }

            $accessState = (string) ($recommendation['access_state'] ?? '');
            if (in_array($accessState, [
                WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PREREQUISITES,
                WorkspaceMarketplaceAccessService::STATE_INSTALLED_LOCKED,
                WorkspaceMarketplaceAccessService::STATE_DEGRADED,
            ], true)) {
                continue;
            }

            $label = (string) (($recommendation['label'] ?? '') ?: ucwords(str_replace('_', ' ', $skillKey)));
            $url = (string) (($recommendation['setup_url'] ?? '') ?: ('workspace_skills.php?module=' . rawurlencode($skillKey)));

            return [
                'skill_key' => $skillKey,
                'label' => 'Install ' . $label,
                'url' => $url,
                'kind' => 'install',
                'message' => (string) (($recommendation['why_now'] ?? '') ?: ($recommendation['reason'] ?? 'This is the strongest Marketplace fit for the workspace right now.')),
                'is_actionable' => true,
                'module_label' => $label,
                'score' => (int) ($recommendation['score'] ?? 0),
                'thumbnail_url' => (string) ($recommendation['thumbnail_url'] ?? ''),
            ];
        }

        return $this->browseAction();
    }

    public function nextActionFromMarketplaceModules(array $marketplaceModules, bool $canManageMarketplace): array
    {
        foreach ($marketplaceModules as $marketplaceItem) {
            $itemAccess = (array) ($marketplaceItem['access'] ?? []);
            $skillKey = (string) ($marketplaceItem['key'] ?? '');
            $module = (array) ($marketplaceItem['module'] ?? []);
            $label = (string) (($module['label'] ?? '') ?: ucwords(str_replace('_', ' ', $skillKey)));

            if (!empty($itemAccess['is_locked']) && !empty($marketplaceItem['is_installed'])) {
                $blockedSkillKey = (string) (($itemAccess['root_blocker_skill_key'] ?? '') ?: $skillKey);
                $message = $this->finishSetupMessage(
                    $label,
                    (string) ($itemAccess['message'] ?? ''),
                    $label . ' needs prerequisite setup finished first.'
                );

                return [
                    'skill_key' => $blockedSkillKey,
                    'label' => (string) (($itemAccess['next_action_label'] ?? '') ?: 'Finish ' . $label),
                    'url' => (string) (($itemAccess['next_action_url'] ?? '') ?: 'workspace_skills.php'),
                    'kind' => 'finish_setup',
                    'message' => $message,
                    'is_actionable' => true,
                    'module_label' => $label,
                ];
            }

            if (!empty($marketplaceItem['is_installed']) && empty($marketplaceItem['readiness']['ready'])) {
                $url = 'workspace_skills.php?module=' . rawurlencode($skillKey);
                $message = $this->finishSetupMessage(
                    $label,
                    (string) ($marketplaceItem['readiness']['message'] ?? ''),
                    $label . ' is installed, but setup is not complete.'
                );

                return [
                    'skill_key' => $skillKey,
                    'label' => 'Finish ' . $label,
                    'url' => $url,
                    'kind' => 'finish_setup',
                    'message' => $message,
                    'is_actionable' => true,
                    'module_label' => $label,
                ];
            }
        }

        if (!$canManageMarketplace) {
            return $this->browseAction();
        }

        foreach ($marketplaceModules as $marketplaceItem) {
            $module = (array) ($marketplaceItem['module'] ?? []);
            $access = (array) ($marketplaceItem['access'] ?? []);
            $skillKey = (string) ($marketplaceItem['key'] ?? '');
            if ($skillKey === '' || !empty($marketplaceItem['is_installed'])) {
                continue;
            }
            if ((string) ($marketplaceItem['type'] ?? '') !== 'plugin') {
                continue;
            }
            if ((string) ($access['state'] ?? '') !== WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL) {
                continue;
            }
            if ((string) ($module['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE) !== WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE) {
                continue;
            }

            $label = (string) (($module['label'] ?? '') ?: ucwords(str_replace('_', ' ', $skillKey)));
            $url = (string) (($module['plugin_metadata']['setup_url'] ?? '') ?: ($module['navigation']['url'] ?? ('workspace_skills.php?module=' . rawurlencode($skillKey))));

            return [
                'skill_key' => $skillKey,
                'label' => 'Install ' . $label,
                'url' => $url,
                'kind' => 'install',
                'message' => (string) (($module['summary'] ?? '') ?: $label . ' is the strongest Marketplace fit to add next.'),
                'is_actionable' => true,
                'module_label' => $label,
            ];
        }

        return $this->browseAction();
    }

    public function ensureNotification(int $workspaceId, int $userId, array $action): ?int
    {
        if ($workspaceId <= 0 || $userId <= 0 || empty($action['is_actionable'])) {
            return null;
        }

        $url = trim((string) ($action['url'] ?? ''));
        if ($url === '' || $url === '#marketplace-card-grid' || !Database::tableExists('notifications')) {
            return null;
        }

        $existing = Database::query(
            "SELECT id FROM notifications
             WHERE workspace_id = ? AND user_id = ? AND type = 'marketplace_next_action' AND link = ? AND is_read = 0
             ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId, $url]
        );
        if ($existing !== []) {
            return (int) ($existing[0]['id'] ?? 0);
        }

        $kind = (string) ($action['kind'] ?? '');
        $title = $kind === 'finish_setup' ? 'Finish Marketplace setup' : 'Install recommended module';
        $message = trim((string) ($action['message'] ?? ''));
        if ($message === '') {
            $message = (string) (($action['label'] ?? '') ?: $title);
        }

        return (new Notifications())->create($userId, 'marketplace_next_action', $title, $message, [
            'link' => $url,
            'severity' => 'info',
            'ai_insight' => $message,
            'ai_action' => (string) ($action['label'] ?? $title),
        ]);
    }

    private function canManageMarketplace(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.manage', $user);
    }

    private function finishSetupMessage(string $label, string $candidate, string $fallback): string
    {
        $message = trim($candidate);
        if ($message === '') {
            return $fallback;
        }

        $normalized = strtolower($message);
        foreach (['checking setup', 'setup readiness', 'ready to check'] as $placeholder) {
            if (strpos($normalized, $placeholder) !== false) {
                return $fallback;
            }
        }

        return $message;
    }

    private function browseAction(): array
    {
        return [
            'skill_key' => '',
            'label' => 'Browse catalog',
            'url' => '#marketplace-card-grid',
            'kind' => 'browse',
            'message' => '',
            'is_actionable' => false,
        ];
    }
}
