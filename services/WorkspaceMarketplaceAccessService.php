<?php

namespace CRM\Services;

class WorkspaceMarketplaceAccessService
{
    public const STATE_READY = 'ready';
    public const STATE_AVAILABLE_TO_INSTALL = 'available_to_install';
    public const STATE_LOCKED_BY_PREREQUISITES = 'locked_by_prerequisites';
    public const STATE_LOCKED_BY_PLAN = 'locked_by_plan';
    public const STATE_INSTALLED_LOCKED = 'installed_locked';
    public const STATE_INSTALLED_NEEDS_SETUP = 'installed_needs_setup';
    public const STATE_DEGRADED = 'degraded';

    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspacePlanEntitlementService $planEntitlements;
    private SuperAdminDefaultWorkspaceModuleAccessService $adminBypass;
    private DefaultWorkspacePackageExemptionService $packageExemptions;
    private array $accessCache = [];

    public function __construct(?WorkspaceSkillCatalogService $catalog = null, ?WorkspaceSkillInstallService $installer = null, ?WorkspacePlanEntitlementService $planEntitlements = null, ?SuperAdminDefaultWorkspaceModuleAccessService $adminBypass = null, ?DefaultWorkspacePackageExemptionService $packageExemptions = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
        $this->planEntitlements = $planEntitlements ?? new WorkspacePlanEntitlementService();
        $this->adminBypass = $adminBypass ?? new SuperAdminDefaultWorkspaceModuleAccessService();
        $this->packageExemptions = $packageExemptions ?? new DefaultWorkspacePackageExemptionService();
    }

    public function accessForModule(int $workspaceId, int $userId, string $skillKey): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        $cacheKey = $workspaceId . ':' . $userId . ':' . $skillKey;
        if (isset($this->accessCache[$cacheKey])) {
            return $this->accessCache[$cacheKey];
        }

        $access = $this->accessForModuleInternal($workspaceId, $userId, $skillKey, []);
        $this->accessCache[$cacheKey] = $access;
        return $access;
    }

    public function accessForDefinition(int $workspaceId, int $userId, array $module): array
    {
        $skillKey = $this->normalizeKey((string) ($module['key'] ?? ''));
        $cacheKey = $workspaceId . ':' . $userId . ':' . $skillKey;
        if ($skillKey !== '' && isset($this->accessCache[$cacheKey])) {
            return $this->accessCache[$cacheKey];
        }

        $access = $this->accessForDefinitionInternal($workspaceId, $userId, $module, []);
        if ($skillKey !== '') {
            $this->accessCache[$cacheKey] = $access;
        }

        return $access;
    }

    public function assertCanInstall(int $workspaceId, int $userId, string $skillKey): void
    {
        $access = $this->accessForModule($workspaceId, $userId, $skillKey);
        if (!empty($access['is_installed']) && empty($access['is_locked'])) {
            return;
        }
        if (!empty($access['can_install'])) {
            return;
        }

        throw new \RuntimeException($this->blockedMessage($access));
    }

    public function assertCanConfigure(int $workspaceId, int $userId, string $skillKey): void
    {
        $access = $this->accessForModule($workspaceId, $userId, $skillKey);
        if (!empty($access['can_configure'])) {
            return;
        }

        throw new \RuntimeException($this->blockedMessage($access));
    }

    public function assertCanRun(int $workspaceId, int $userId, string $skillKey): void
    {
        $access = $this->accessForModule($workspaceId, $userId, $skillKey);
        if (!empty($access['can_run'])) {
            return;
        }

        throw new \RuntimeException($this->blockedMessage($access));
    }

    public function isLocked(array $access): bool
    {
        return !empty($access['is_locked']);
    }

    private function accessForModuleInternal(int $workspaceId, int $userId, string $skillKey, array $visited): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            return $this->degradedAccess('', 'Marketplace module', 'Marketplace module key is required.');
        }

        $module = $this->catalog->findForWorkspace($skillKey, $workspaceId, true);
        if ($module === null) {
            return $this->degradedAccess($skillKey, ucwords(str_replace('_', ' ', $skillKey)), 'Marketplace module was not found.');
        }

        return $this->accessForDefinitionInternal($workspaceId, $userId, $module, $visited);
    }

    private function accessForDefinitionInternal(int $workspaceId, int $userId, array $module, array $visited): array
    {
        $skillKey = $this->normalizeKey((string) ($module['key'] ?? ''));
        $label = (string) ($module['label'] ?? ($skillKey !== '' ? ucwords(str_replace('_', ' ', $skillKey)) : 'Marketplace module'));
        if ($skillKey === '') {
            return $this->degradedAccess('', $label, 'Marketplace module key is required.');
        }
        if (isset($visited[$skillKey])) {
            return $this->degradedAccess($skillKey, $label, 'Marketplace access requirements contain a dependency loop.');
        }

        $visited[$skillKey] = true;
        $installed = $this->installer->isInstalled($workspaceId, $skillKey);
        $adminBypass = $this->adminBypass->canBypassModuleAccessGatesForUserId($workspaceId, $userId);
        $bypassedRequirements = [];
        $planLock = $this->planLockForModule($workspaceId, $module);
        if ($planLock !== null && !$adminBypass) {
            return $this->shapeAccess(
                $module,
                self::STATE_LOCKED_BY_PLAN,
                $installed,
                [
                    'ready' => false,
                    'status' => 'locked_by_plan',
                    'message' => (string) ($planLock['message'] ?? ($label . ' is not included in this package.')),
                ],
                [],
                [
                    [
                        'skill_key' => $skillKey,
                        'label' => 'Plan upgrade',
                        'module_label' => $label,
                        'requires_readiness' => false,
                        'status' => 'pending',
                        'message' => (string) ($planLock['message'] ?? ''),
                        'why' => (string) ($planLock['why'] ?? ''),
                        'action_url' => (string) ($planLock['action_url'] ?? 'billing_payment_required.php?tab=packages#workspace-packages'),
                        'action_label' => (string) ($planLock['action_label'] ?? 'View plans'),
                        'access_state' => self::STATE_LOCKED_BY_PLAN,
                        'readiness' => [],
                    ],
                ],
                (string) ($planLock['message'] ?? ($label . ' is not included in this package.')),
                [
                    'skill_key' => $skillKey,
                    'label' => 'Plan upgrade',
                    'url' => (string) ($planLock['action_url'] ?? 'billing_payment_required.php?tab=packages#workspace-packages'),
                    'action_url' => (string) ($planLock['action_url'] ?? 'billing_payment_required.php?tab=packages#workspace-packages'),
                    'action_label' => (string) ($planLock['action_label'] ?? 'View plans'),
                ]
            );
        } elseif ($planLock !== null) {
            $bypassedRequirements[] = [
                'skill_key' => $skillKey,
                'label' => 'Plan upgrade',
                'module_label' => $label,
                'requires_readiness' => false,
                'status' => 'bypassed',
                'message' => (string) ($planLock['message'] ?? ''),
                'why' => (string) ($planLock['why'] ?? ''),
                'action_url' => (string) ($planLock['action_url'] ?? 'billing_payment_required.php?tab=packages#workspace-packages'),
                'action_label' => (string) ($planLock['action_label'] ?? 'View plans'),
                'access_state' => self::STATE_LOCKED_BY_PLAN,
                'readiness' => [],
            ];
        }

        $requirements = $this->normalizeRequirements((array) ($module['access_requirements'] ?? []));
        $completed = [];
        $pending = [];
        $rootBlocker = null;

        foreach ($requirements as $requirement) {
            $requiredKey = (string) ($requirement['skill_key'] ?? '');
            $requiredModule = $this->catalog->findForWorkspace($requiredKey, $workspaceId, true);
            $requiredLabel = (string) ($requiredModule['label'] ?? $requirement['label'] ?? ucwords(str_replace('_', ' ', $requiredKey)));
            $requiresReadiness = !array_key_exists('requires_readiness', $requirement) || !empty($requirement['requires_readiness']);
            $requiredAccess = $this->accessForModuleInternal($workspaceId, $userId, $requiredKey, $visited);
            $requiredInstalled = !empty($requiredAccess['is_installed']);
            $requiredReady = !empty($requiredAccess['readiness']['ready']);
            $requiredLocked = !empty($requiredAccess['is_locked']);
            $ok = $requiredInstalled && !$requiredLocked && (!$requiresReadiness || $requiredReady);
            $item = [
                'skill_key' => $requiredKey,
                'label' => (string) ($requirement['label'] ?? ($requiresReadiness ? $requiredLabel . ' ready' : $requiredLabel . ' installed')),
                'module_label' => $requiredLabel,
                'requires_readiness' => $requiresReadiness,
                'status' => $ok ? 'complete' : 'pending',
                'message' => (string) ($requirement['message'] ?? ''),
                'why' => (string) ($requirement['why'] ?? ''),
                'action_url' => (string) ($requiredAccess['next_action_url'] ?? $this->moduleUrl($requiredKey)),
                'action_label' => (string) ($requiredAccess['next_action_label'] ?? ('Open ' . $requiredLabel)),
                'access_state' => (string) ($requiredAccess['state'] ?? self::STATE_DEGRADED),
                'readiness' => (array) ($requiredAccess['readiness'] ?? []),
            ];

            if ($ok) {
                $completed[] = $item;
                continue;
            }

            $pending[] = $item;
            if ($rootBlocker === null) {
                $rootBlocker = [
                    'skill_key' => (string) ($requiredAccess['root_blocker_skill_key'] ?? '') ?: $requiredKey,
                    'label' => (string) ($requiredAccess['root_blocker_label'] ?? '') ?: $requiredLabel,
                    'url' => (string) ($requiredAccess['root_blocker_url'] ?? '') ?: $this->moduleUrl($requiredKey),
                    'action_url' => (string) ($requiredAccess['next_action_url'] ?? '') ?: $this->moduleUrl($requiredKey),
                    'action_label' => (string) ($requiredAccess['next_action_label'] ?? '') ?: ('Open ' . $requiredLabel),
                    'requirement_label' => (string) ($item['label'] ?? $requiredLabel),
                ];
            }
        }

        if ($pending !== [] && !$adminBypass) {
            $readiness = $installed
                ? $this->installer->buildReadinessForModule($workspaceId, $userId, $skillKey)
                : [
                    'ready' => false,
                    'status' => 'locked',
                    'message' => $label . ' is locked until prerequisite setup is complete.',
                ];
            $state = $installed ? self::STATE_INSTALLED_LOCKED : self::STATE_LOCKED_BY_PREREQUISITES;
            $rootLabel = (string) ($rootBlocker['label'] ?? 'a prerequisite');
            $message = $installed
                ? $label . ' is installed, but it is locked until ' . $rootLabel . ' is ready.'
                : $label . ' is locked until ' . $rootLabel . ' is ready.';

            return $this->shapeAccess(
                $module,
                $state,
                $installed,
                $readiness,
                $completed,
                $pending,
                $message,
                $rootBlocker
            );
        }

        if ($adminBypass && $pending !== []) {
            foreach ($pending as $requirement) {
                $requirement['status'] = 'bypassed';
                $bypassedRequirements[] = $requirement;
            }
        }

        if (!$installed) {
            return $this->shapeAccess(
                $module,
                self::STATE_AVAILABLE_TO_INSTALL,
                false,
                [
                    'ready' => false,
                    'status' => 'available',
                    'message' => $label . ' can be installed for this workspace.',
                ],
                $completed,
                $pending,
                $label . ' can be installed for this workspace.',
                null,
                $adminBypass,
                $bypassedRequirements
            );
        }

        $readiness = $this->installer->buildReadinessForModule($workspaceId, $userId, $skillKey);
        if (!empty($readiness['ready'])) {
            return $this->shapeAccess(
                $module,
                self::STATE_READY,
                true,
                $readiness,
                $completed,
                $pending,
                $label . ' is ready for this workspace.',
                null,
                $adminBypass,
                $bypassedRequirements
            );
        }

        return $this->shapeAccess(
            $module,
            self::STATE_INSTALLED_NEEDS_SETUP,
            true,
            $readiness,
            $completed,
            $pending,
            (string) ($readiness['message'] ?? ($label . ' needs setup before runtime is available.')),
            null,
            $adminBypass,
            $bypassedRequirements
        );
    }

    private function shapeAccess(
        array $module,
        string $state,
        bool $installed,
        array $readiness,
        array $completed,
        array $pending,
        string $message,
        ?array $rootBlocker,
        bool $adminBypass = false,
        array $bypassedRequirements = []
    ): array {
        $skillKey = $this->normalizeKey((string) ($module['key'] ?? ''));
        $label = (string) ($module['label'] ?? ucwords(str_replace('_', ' ', $skillKey)));
        $isLocked = in_array($state, [self::STATE_LOCKED_BY_PREREQUISITES, self::STATE_LOCKED_BY_PLAN, self::STATE_INSTALLED_LOCKED, self::STATE_DEGRADED], true);
        $setupUrl = $this->moduleSetupUrl($module, $skillKey);
        $runtimeUrl = (string) ($module['navigation']['url'] ?? '');
        if ($runtimeUrl === '') {
            $runtimeUrl = $setupUrl;
        }
        $runtimeDuringSetup = !empty($module['capabilities']['runtime_during_setup']);

        $nextActionUrl = $this->moduleUrl($skillKey);
        $nextActionLabel = 'Open ' . $label;
        if ($isLocked && $rootBlocker !== null) {
            $nextActionUrl = (string) ($rootBlocker['action_url'] ?? $rootBlocker['url'] ?? $nextActionUrl);
            $nextActionLabel = (string) ($rootBlocker['action_label'] ?? ('Open ' . (string) ($rootBlocker['label'] ?? 'prerequisite')));
        } elseif ($state === self::STATE_INSTALLED_NEEDS_SETUP) {
            $nextActionUrl = $setupUrl;
            $nextActionLabel = $this->setupActionLabel($label);
        } elseif ($state === self::STATE_READY) {
            $nextActionUrl = $runtimeUrl;
            $nextActionLabel = 'Open ' . $label;
        } elseif ($state === self::STATE_AVAILABLE_TO_INSTALL) {
            $nextActionUrl = $this->moduleUrl($skillKey);
            $nextActionLabel = 'Install ' . $label;
        }

        return [
            'skill_key' => $skillKey,
            'label' => $label,
            'state' => $state,
            'access_state' => $state,
            'is_locked' => $isLocked,
            'is_installed' => $installed,
            'can_install' => !$installed && !$isLocked,
            'can_open' => !$isLocked,
            'can_configure' => $installed && !$isLocked,
            'can_run' => $installed && !$isLocked && (!empty($readiness['ready']) || $runtimeDuringSetup),
            'message' => $message,
            'why' => $this->accessWhy($module, $pending),
            'requirements' => array_values(array_merge($completed, $pending)),
            'completed_requirements' => array_values($completed),
            'pending_requirements' => array_values($pending),
            'root_blocker_skill_key' => (string) ($rootBlocker['skill_key'] ?? ''),
            'root_blocker_label' => (string) ($rootBlocker['label'] ?? ''),
            'root_blocker_url' => (string) ($rootBlocker['url'] ?? ''),
            'next_action_url' => $nextActionUrl,
            'next_action_label' => $nextActionLabel,
            'readiness' => $readiness,
            'admin_bypass' => $adminBypass,
            'admin_bypass_reason' => $adminBypass ? 'Superadmin default workspace access bypasses package and prerequisite gates.' : '',
            'bypassed_requirements' => array_values($bypassedRequirements),
        ];
    }

    private function degradedAccess(string $skillKey, string $label, string $message): array
    {
        return [
            'skill_key' => $skillKey,
            'label' => $label,
            'state' => self::STATE_DEGRADED,
            'access_state' => self::STATE_DEGRADED,
            'is_locked' => true,
            'is_installed' => false,
            'can_install' => false,
            'can_open' => false,
            'can_configure' => false,
            'can_run' => false,
            'message' => $message,
            'why' => 'The workspace cannot safely resolve this module right now.',
            'requirements' => [],
            'completed_requirements' => [],
            'pending_requirements' => [],
            'root_blocker_skill_key' => $skillKey,
            'root_blocker_label' => $label,
            'root_blocker_url' => $skillKey !== '' ? $this->moduleUrl($skillKey) : 'workspace_skills.php',
            'next_action_url' => $skillKey !== '' ? $this->moduleUrl($skillKey) : 'workspace_skills.php',
            'next_action_label' => 'Open Marketplace',
            'readiness' => [],
        ];
    }

    private function normalizeRequirements(array $requirements): array
    {
        $out = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $skillKey = $this->normalizeKey((string) ($requirement['requires_skill'] ?? $requirement['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            $requirement['skill_key'] = $skillKey;
            $out[] = $requirement;
        }

        return $out;
    }

    private function accessWhy(array $module, array $pending): string
    {
        if ($pending === []) {
            return 'This module can use the workspace context it needs.';
        }

        foreach ($pending as $requirement) {
            $why = trim((string) ($requirement['why'] ?? ''));
            if ($why !== '') {
                return $why;
            }
        }

        return 'This gate prevents the module from running with incomplete upstream workspace context.';
    }

    private function planLockForModule(int $workspaceId, array $module): ?array
    {
        if ($this->packageExemptions->isExempt($workspaceId)) {
            return null;
        }

        $skillKey = $this->normalizeKey((string) ($module['key'] ?? ''));
        $capabilities = (array) ($module['capabilities'] ?? []);

        if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
            || !empty($capabilities['hr_analytics_gate'])
            || !empty($capabilities['hr_settings_setup'])
        ) {
            if (!$this->planEntitlements->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_BUSINESS_INTELLIGENCE)) {
                return [
                    'message' => 'Organization Intelligence unlocks on Founder Plus and higher plans.',
                    'why' => 'Business Intelligence plugin access is controlled by the workspace package.',
                    'action_url' => 'billing_payment_required.php?tab=packages#workspace-packages',
                    'action_label' => 'View plans',
                ];
            }
        }

        if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_AI_API
            || !empty($capabilities['workspace_ai_api'])
            || !empty($capabilities['ai_provider_config'])
        ) {
            if (!$this->planEntitlements->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_PERSONAL_API_KEY)) {
                return [
                    'message' => 'Personal API key access unlocks on Growth Studio and higher plans.',
                    'why' => 'Personal API key plugin access is controlled by the workspace package.',
                    'action_url' => 'billing_payment_required.php?tab=packages#workspace-packages',
                    'action_label' => 'View plans',
                ];
            }
        }

        if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER
            || !empty($capabilities['voice_calling'])
        ) {
            if (!$this->planEntitlements->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_VOICE_CALL_CENTER)) {
                return [
                    'message' => 'Voice & Call Center is available on Growth Voice and negotiated Scale Voice packages.',
                    'why' => 'Voice concurrency, agent limits, recording, and transcription are controlled by the workspace package.',
                    'action_url' => 'billing_payment_required.php?tab=packages#workspace-packages',
                    'action_label' => 'View voice packages',
                ];
            }
        }

        return null;
    }

    private function moduleSetupUrl(array $module, string $skillKey): string
    {
        foreach ([
            $module['settings_schema']['settings_url'] ?? null,
            $module['plugin_metadata']['setup_url'] ?? null,
            $module['navigation']['url'] ?? null,
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $this->moduleUrl($skillKey);
    }

    private function moduleUrl(string $skillKey): string
    {
        $skillKey = $this->normalizeKey($skillKey);
        return $skillKey !== '' ? 'workspace_skills.php?module=' . rawurlencode($skillKey) : 'workspace_skills.php';
    }

    private function blockedMessage(array $access): string
    {
        $message = trim((string) ($access['message'] ?? 'This Marketplace module is not available yet.'));
        $nextAction = trim((string) ($access['next_action_label'] ?? ''));
        if ($nextAction !== '') {
            $message .= ' Next action: ' . $nextAction . '.';
        }

        return $message;
    }

    private function setupActionLabel(string $label): string
    {
        return str_contains(strtolower($label), 'setup') ? 'Finish ' . $label : 'Finish ' . $label . ' setup';
    }

    private function normalizeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? '', '_');
    }
}
