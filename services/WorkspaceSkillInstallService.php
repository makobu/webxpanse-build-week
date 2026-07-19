<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;

class WorkspaceSkillInstallService
{
    private WorkspaceSkillCatalogService $catalog;
    private array $installedForWorkspaceCache = [];
    private array $isInstalledCache = [];
    private array $readinessCache = [];
    private ?bool $installTableReady = null;
    private ?bool $eventsTableReady = null;

    public function __construct(?WorkspaceSkillCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
    }

    public function installedForWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        if (isset($this->installedForWorkspaceCache[$workspaceId])) {
            return $this->installedForWorkspaceCache[$workspaceId];
        }
        if (!$this->tableReady()) {
            return [];
        }

        $this->catalog->syncDefinitions();
        $contractSelect = $this->catalog->contractColumnsReady()
            ? ", d.owner_workspace_id, d.definition_source, d.advice_domains_json, d.context_schema_json,
                    d.task_templates_json, d.boundary_policy, d.routing_examples_json, d.created_by_user_id"
            : "";
        $rows = Database::query(
            "SELECT i.id, i.workspace_id, i.skill_key, i.status, i.config_json,
                    i.installed_by_user_id, i.updated_by_user_id, i.installed_at,
                    i.disabled_at, i.uninstalled_at, i.created_at, i.updated_at,
                    d.label, d.summary, d.category, d.module_type, d.version, d.capabilities_json,
                    d.onboarding_fields_json, d.settings_schema_json, d.plugin_metadata_json, d.ai_context_provider,
                    d.navigation_json, d.permissions_json{$contractSelect}
             FROM workspace_skill_installs i
             INNER JOIN workspace_skill_definitions d ON d.skill_key = i.skill_key
             WHERE i.workspace_id = ?
               AND i.status = 'installed'
               AND i.uninstalled_at IS NULL
               AND d.is_active = 1
             ORDER BY d.category ASC, d.label ASC",
            [$workspaceId]
        );

        $installed = array_values(array_map(fn(array $row): array => $this->normalizeInstalledRow($row), $rows));
        $installed = array_values(array_filter(
            $installed,
            fn(array $skill): bool => !$this->catalog->isGloballyDeactivated((string) ($skill['key'] ?? ''))
        ));
        $this->installedForWorkspaceCache[$workspaceId] = $installed;
        foreach ($installed as $skill) {
            $key = $this->normalizeKey((string) ($skill['key'] ?? ''));
            if ($key !== '') {
                $this->isInstalledCache[$workspaceId . ':' . $key] = true;
            }
        }

        return $installed;
    }

    public function install(int $workspaceId, string $skillKey, int $userId, array $config = []): array
    {
        $this->assertCanManage($workspaceId, $userId);
        $skill = $this->catalog->findForWorkspace($skillKey, $workspaceId, true);
        if ($skill === null) {
            throw new \InvalidArgumentException('Unknown workspace skill: ' . $skillKey);
        }
        if (!$this->catalog->canInstallForWorkspace((string) ($skill['key'] ?? $skillKey), $workspaceId)) {
            throw new \RuntimeException((string) ($skill['label'] ?? 'This Marketplace module') . ' is not available for new workspace installs.');
        }
        if ($this->isProtectedInstall($skill)) {
            throw new \RuntimeException((string) ($skill['label'] ?? 'This Marketplace plugin') . ' is required for every workspace and cannot be removed.');
        }
        if (!$this->tableReady()) {
            throw new \RuntimeException('Workspace skills tables are not installed.');
        }
        (new WorkspaceMarketplaceAccessService($this->catalog, $this))->assertCanInstall($workspaceId, $userId, (string) ($skill['key'] ?? $skillKey));

        $normalizedConfig = $this->normalizeConfig($config);
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id,
                updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', ?, ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE
                status = 'installed',
                config_json = VALUES(config_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                disabled_at = NULL,
                uninstalled_at = NULL,
                updated_at = NOW()",
            [
                $workspaceId,
                $skill['key'],
                json_encode($normalizedConfig, JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );

        $this->recordEvent($workspaceId, (string) $skill['key'], 'installed', $userId, ['config' => $normalizedConfig]);
        $this->clearWorkspaceCaches($workspaceId, (string) $skill['key']);
        return $this->getInstalled($workspaceId, (string) $skill['key']) ?? array_merge($skill, [
            'workspace_id' => $workspaceId,
            'status' => 'installed',
            'config' => $normalizedConfig,
        ]);
    }

    public function uninstall(int $workspaceId, string $skillKey, int $userId): void
    {
        $this->assertCanManage($workspaceId, $userId);
        $skill = $this->catalog->findForWorkspace($skillKey, $workspaceId, true);
        if ($skill === null) {
            throw new \InvalidArgumentException('Unknown workspace skill: ' . $skillKey);
        }
        if ($this->isProtectedInstall($skill)) {
            throw new \RuntimeException((string) ($skill['label'] ?? 'This Marketplace plugin') . ' is required for every workspace and cannot be removed.');
        }
        if (!$this->tableReady()) {
            throw new \RuntimeException('Workspace skills tables are not installed.');
        }

        Database::execute(
            "UPDATE workspace_skill_installs
             SET status = 'disabled',
                 disabled_at = NOW(),
                 uninstalled_at = NOW(),
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE workspace_id = ? AND skill_key = ?",
            [$userId > 0 ? $userId : null, $workspaceId, $skill['key']]
        );
        $this->recordEvent($workspaceId, (string) $skill['key'], 'uninstalled', $userId);
        $this->clearWorkspaceCaches($workspaceId, (string) $skill['key']);
    }

    public function isInstalled(int $workspaceId, string $skillKey): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }
        $skillKey = $this->normalizeKey($skillKey);
        $cacheKey = $workspaceId . ':' . $skillKey;
        if (array_key_exists($cacheKey, $this->isInstalledCache)) {
            return (bool) $this->isInstalledCache[$cacheKey];
        }
        if (!$this->tableReady()) {
            return false;
        }
        if ($this->catalog->isGloballyDeactivated($skillKey)) {
            $this->isInstalledCache[$cacheKey] = false;
            return false;
        }

        if (isset($this->installedForWorkspaceCache[$workspaceId])) {
            foreach ($this->installedForWorkspaceCache[$workspaceId] as $skill) {
                if ((string) ($skill['key'] ?? '') === $skillKey) {
                    $this->isInstalledCache[$cacheKey] = true;
                    return true;
                }
            }
            $this->isInstalledCache[$cacheKey] = false;
            return false;
        }

        $row = Database::queryOne(
            "SELECT 1
             FROM workspace_skill_installs
             WHERE workspace_id = ?
               AND skill_key = ?
               AND status = 'installed'
               AND uninstalled_at IS NULL
             LIMIT 1",
            [$workspaceId, $skillKey]
        );

        $this->isInstalledCache[$cacheKey] = $row !== null;
        return $row !== null;
    }

    public function canExposeRuntimeModule(int $workspaceId, string $skillKey): bool
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($this->catalog->isGloballyDeactivated($skillKey)) {
            return false;
        }
        if (!$this->isInstalled($workspaceId, $skillKey)) {
            return false;
        }

        $definition = $this->catalog->findForWorkspace($skillKey, $workspaceId, true);
        if ($definition === null) {
            return true;
        }

        $userId = (int) ((Auth::user() ?: [])['id'] ?? 0);
        if ($skillKey === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
            // The Coach modal owns readiness blocking so it can explain missing Journey or brief context.
            return !empty($this->buildReadinessForModule($workspaceId, $userId, $skillKey)['ready']);
        }

        $access = (new WorkspaceMarketplaceAccessService($this->catalog, $this))->accessForDefinition($workspaceId, $userId, $definition);
        if (empty($access['can_run'])) {
            return false;
        }

        if ((string) ($definition['module_type'] ?? '') !== 'plugin') {
            return true;
        }

        if (empty($definition['settings_schema']['requires_configuration'])) {
            return true;
        }

        return !empty($this->buildReadinessForModule($workspaceId, $userId, $skillKey)['ready']);
    }

    public function installRequiredCorePlugins(int $workspaceId, int $userId = 0): void
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $this->catalog->syncDefinitions();
        foreach ($this->catalog->availableForWorkspace($workspaceId) as $skill) {
            if (!$this->isProtectedInstall($skill)) {
                continue;
            }

            Database::execute(
                "INSERT INTO workspace_skill_installs (
                    workspace_id, skill_key, status, config_json, installed_by_user_id,
                    updated_by_user_id, installed_at, disabled_at, uninstalled_at
                 ) VALUES (?, ?, 'installed', ?, ?, ?, NOW(), NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    status = 'installed',
                    config_json = COALESCE(workspace_skill_installs.config_json, VALUES(config_json)),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    disabled_at = NULL,
                    uninstalled_at = NULL,
                    updated_at = NOW()",
                [
                    $workspaceId,
                    (string) ($skill['key'] ?? ''),
                    json_encode(['source' => 'required_core_plugin'], JSON_UNESCAPED_SLASHES),
                    $userId > 0 ? $userId : null,
                    $userId > 0 ? $userId : null,
                ]
            );
        }
        $this->clearWorkspaceCaches($workspaceId);
    }

    public function buildReadinessForModule(int $workspaceId, int $userId, string $skillKey): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        $cacheKey = $workspaceId . ':' . $userId . ':' . $skillKey;
        if (isset($this->readinessCache[$cacheKey])) {
            return $this->readinessCache[$cacheKey];
        }
        $installed = $this->isInstalled($workspaceId, $skillKey);
        $definition = $this->catalog->findForWorkspace($skillKey, $workspaceId);
        $readiness = match ($skillKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL => $this->buildEmailChannelReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => $this->buildWhatsAppChannelReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP => $this->buildHRAnalyticsSetupReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => $this->buildEmailAssistantReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => $this->buildWhatsAppAssistantReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL => $this->buildSmsChannelReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER => (new WorkspaceVoiceConfigService())->readiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => $this->buildCalendarMeetingsReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_FINANCE => (new WorkspaceFinanceGateService($this))->readiness($workspaceId, $userId),
            WorkspaceSkillCatalogService::PLUGIN_AI_API => $this->buildAiApiReadiness($workspaceId),
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA => $this->buildMarketingSplitPluginReadiness($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA),
            WorkspaceSkillCatalogService::PLUGIN_DESIGN => $this->buildMarketingSplitPluginReadiness($workspaceId, WorkspaceSkillCatalogService::PLUGIN_DESIGN),
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO => $this->buildMarketingProReadiness($workspaceId, $userId),
            WorkspaceSkillCatalogService::SKILL_AI_COACH => $this->buildAiCoachReadiness($workspaceId, $userId),
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS => $this->buildLeanCanvasReadiness($userId),
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER => $this->buildProfessionalMarketerReadiness($userId),
            default => !empty($definition['is_custom'])
                ? $this->buildCustomSkillReadiness($workspaceId, $skillKey, $installed, $definition)
                : [
                    'enabled' => $installed,
                    'ready' => $installed,
                    'status' => $installed ? 'installed' : 'available',
                    'message' => $installed ? 'This module is installed.' : 'Install this module before setup and runtime actions are available.',
                    'checks' => [],
                    'blockers' => [],
                    'next_action' => $installed ? 'Review setup and health.' : 'Install this module from the Marketplace.',
                ],
        };

        $readiness['installed'] = $installed;
        $readiness['skill_key'] = $skillKey;
        if (!$installed) {
            $readiness['ready'] = false;
            $readiness['status'] = 'not_installed';
            $readiness['message'] = 'Install this module before setup and runtime actions are available.';
            $readiness['next_action'] = 'Install this module from the Marketplace.';
        }
        $readiness['checks'] = array_values((array) ($readiness['checks'] ?? []));
        $readiness['blockers'] = array_values(array_filter(array_map('strval', (array) ($readiness['blockers'] ?? []))));
        $readiness['next_action'] = (string) ($readiness['next_action'] ?? ($readiness['ready'] ? 'Monitor usage and recent activity.' : 'Complete the required setup fields.'));
        $readiness['last_checked_at'] = gmdate('c');

        $this->readinessCache[$cacheKey] = $readiness;
        return $readiness;
    }

    public function saveSkillContext(int $workspaceId, string $skillKey, int $userId, array $contextValues): array
    {
        $this->assertCanManage($workspaceId, $userId);
        $skillKey = $this->normalizeKey($skillKey);
        $skill = $this->catalog->findForWorkspace($skillKey, $workspaceId);
        if ($skill === null) {
            throw new \InvalidArgumentException('Unknown workspace skill: ' . $skillKey);
        }
        if (!$this->isInstalled($workspaceId, $skillKey)) {
            $this->install($workspaceId, $skillKey, $userId, ['source' => 'workspace_marketplace_context']);
        }

        $installed = $this->getInstalled($workspaceId, $skillKey);
        $config = (array) ($installed['config'] ?? []);
        $config['context_values'] = $this->normalizeContextValues($contextValues);

        Database::execute(
            "UPDATE workspace_skill_installs
             SET config_json = ?,
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE workspace_id = ? AND skill_key = ? AND status = 'installed'",
            [
                json_encode($config, JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
                $workspaceId,
                $skillKey,
            ]
        );
        $this->recordEvent($workspaceId, $skillKey, 'context_saved', $userId, ['context_keys' => array_keys($config['context_values'])]);
        $this->clearWorkspaceCaches($workspaceId, $skillKey);

        return $this->getInstalled($workspaceId, $skillKey) ?? [];
    }

    public function buildInstalledSkillContracts(int $workspaceId, int $userId): array
    {
        $contracts = [];
        $accessService = new WorkspaceMarketplaceAccessService($this->catalog, $this);
        foreach ($this->installedForWorkspace($workspaceId) as $skill) {
            $key = (string) ($skill['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $readiness = $this->buildReadinessForModule($workspaceId, $userId, $key);
            $access = $accessService->accessForDefinition($workspaceId, $userId, $skill);
            if (empty($access['can_run'])) {
                continue;
            }
            $contextValues = (array) (($skill['config']['context_values'] ?? []) ?: []);
            if ($key === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS && $userId > 0) {
                $contextValues = (new StartupJourneyService())->getContextForAI($workspaceId, $userId);
            } elseif ($key === WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER && $userId > 0) {
                $contextValues = $this->marketingStrategyContextValues($userId);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO && $userId > 0) {
                $contextValues = $this->marketingStrategyContextValues($userId);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
                $contextValues = $this->marketingSplitPluginContextValues($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_DESIGN) {
                $contextValues = $this->marketingSplitPluginContextValues($workspaceId, WorkspaceSkillCatalogService::PLUGIN_DESIGN);
            }

            $contracts[] = [
                'key' => $key,
                'label' => (string) ($skill['label'] ?? $key),
                'definition_source' => (string) ($skill['definition_source'] ?? 'platform'),
                'owner_workspace_id' => $skill['owner_workspace_id'] ?? null,
                'module_type' => (string) ($skill['module_type'] ?? 'skill'),
                'readiness' => [
                    'ready' => (bool) ($readiness['ready'] ?? false),
                    'status' => (string) ($readiness['status'] ?? ''),
                    'message' => (string) ($readiness['message'] ?? ''),
                    'blockers' => array_values((array) ($readiness['blockers'] ?? [])),
                    'next_action' => (string) ($readiness['next_action'] ?? ''),
                ],
                'advice_domains' => array_values(array_filter(array_map('strval', (array) ($skill['advice_domains'] ?? [])))),
                'instructions' => (string) ($skill['plugin_metadata']['guidance_instructions'] ?? $skill['summary'] ?? ''),
                'context_schema' => (array) ($skill['context_schema'] ?? []),
                'context_values' => $contextValues,
                'task_templates' => array_values((array) ($skill['task_templates'] ?? [])),
                'boundary_policy' => (string) ($skill['boundary_policy'] ?? 'strict'),
                'routing_examples' => array_values((array) ($skill['routing_examples'] ?? [])),
            ];
        }

        return $contracts;
    }

    public function buildSetupChecklist(int $workspaceId, int $userId, string $skillKey): array
    {
        $readiness = $this->buildReadinessForModule($workspaceId, $userId, $skillKey);
        $required = [];
        $optional = [];
        foreach ((array) ($readiness['checks'] ?? []) as $check) {
            $item = [
                'label' => (string) ($check['label'] ?? 'Setup check'),
                'status' => !empty($check['ok']) ? 'complete' : 'needs_setup',
                'detail' => (string) ($check['detail'] ?? ''),
            ];
            if (!empty($check['required'])) {
                $required[] = $item;
            } else {
                $optional[] = $item;
            }
        }

        return [
            'skill_key' => $this->normalizeKey($skillKey),
            'required' => $required,
            'optional' => $optional,
            'current_blocker' => (string) (($readiness['blockers'][0] ?? '') ?: ''),
            'next_action' => (string) ($readiness['next_action'] ?? ''),
            'ready' => (bool) ($readiness['ready'] ?? false),
            'status' => (string) ($readiness['status'] ?? ''),
        ];
    }

    public function installLegacyLeanCanvasIfPresent(int $workspaceId, int $userId, array $strategy): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tableReady()) {
            return;
        }

        if ($this->hasInstallHistory($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)) {
            return;
        }

        foreach ([
            'lean_problem',
            'lean_customer_segments',
            'lean_unique_value_proposition',
            'lean_solution',
            'lean_channels',
            'lean_revenue_streams',
            'lean_cost_structure',
            'lean_key_metrics',
            'lean_unfair_advantage',
        ] as $field) {
            if (trim((string) ($strategy[$field] ?? '')) !== '') {
                $this->install($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $userId, ['source' => 'legacy_lean_canvas']);
                return;
            }
        }
    }

    private function hasInstallHistory(int $workspaceId, string $skillKey): bool
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT 1
             FROM workspace_skill_installs
             WHERE workspace_id = ?
               AND skill_key = ?
             LIMIT 1",
            [$workspaceId, $this->normalizeKey($skillKey)]
        );

        return !empty($row);
    }

    public function buildContextForWorkspace(int $workspaceId, int $userId): array
    {
        $installed = $this->installedForWorkspace($workspaceId);
        if ($installed === []) {
            return [
                'installed' => [],
                'installed_keys' => [],
                'skill_keys' => [],
                'plugin_keys' => [],
                'installed_skill_contracts' => [],
                'context' => [],
                'skills_context' => [],
                'plugins_context' => [],
                'readiness' => [],
            ];
        }

        $context = [];
        $skillsContext = [];
        $pluginsContext = [];
        $readiness = [];
        $accessService = new WorkspaceMarketplaceAccessService($this->catalog, $this);
        foreach ($installed as $skill) {
            $key = (string) ($skill['key'] ?? '');
            $access = $accessService->accessForDefinition($workspaceId, $userId, $skill);
            if (!empty($access['is_locked'])) {
                $lockedReadiness = (array) ($access['readiness'] ?? []);
                $lockedReadiness['ready'] = false;
                $lockedReadiness['access_state'] = (string) ($access['state'] ?? WorkspaceMarketplaceAccessService::STATE_INSTALLED_LOCKED);
                $lockedReadiness['access_locked'] = true;
                $lockedReadiness['message'] = (string) ($access['message'] ?? 'This module is locked by Marketplace access requirements.');
                $lockedReadiness['blockers'] = array_values(array_filter(array_map(
                    static fn(array $requirement): string => (string) ($requirement['label'] ?? ''),
                    (array) ($access['pending_requirements'] ?? [])
                )));
                $readiness[$key] = $lockedReadiness;
                continue;
            }
            if ($key === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS && $userId > 0) {
                $strategy = new \CRM\Modules\UserStrategyProfile();
                $skillsContext[$key] = [
                    'canvas' => $strategy->getLeanCanvas($userId),
                    'status' => $strategy->getLeanCanvasStatus($userId),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER && $userId > 0) {
                $strategy = (new \CRM\Modules\UserStrategyProfile())->get($userId) ?: [];
                $skillsContext[$key] = [
                    'target_market_focus' => (string) ($strategy['target_market_focus'] ?? ''),
                    'segment_focus' => (string) ($strategy['segment_focus'] ?? ''),
                    'outreach_posture' => (string) ($strategy['outreach_posture'] ?? ''),
                    'positioning_notes' => (string) ($strategy['positioning_notes'] ?? ''),
                    'guidance' => 'Use this module to review positioning, suggest campaigns, and sharpen customer-facing messages.',
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO && $userId > 0) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = array_merge($this->marketingStrategyContextValues($userId), [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['campaign_management', 'marketing_setup', 'analytics', 'attribution', 'live_orchestration'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=marketing_pro'),
                    'runtime_url' => 'marketing.php',
                ]);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = array_merge($this->marketingSplitPluginContextValues($workspaceId, $key), [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['social_connectors', 'content_creation', 'content_calendar', 'social_analytics', 'social_ads'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=social_media'),
                    'runtime_url' => 'social_media.php',
                ]);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_DESIGN) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = array_merge($this->marketingSplitPluginContextValues($workspaceId, $key), [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['forms', 'landing_pages', 'email_signatures', 'creative_assets', 'conversion_design'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=design'),
                    'runtime_url' => 'design.php',
                ]);
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => true,
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['hr_analytics_gate', 'hr_settings_setup', 'function_setup', 'function_assignment_setup', 'department_setup', 'department_assignment_setup'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=hr_analytics_setup'),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_EMAIL) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => true,
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['email_channel_setup', 'email_sending', 'email_templates', 'email_signatures'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=email'),
                    'runtime_url' => 'emails.php',
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => true,
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['whatsapp_channel_setup', 'whatsapp_sending', 'whatsapp_queue', 'webhook_readiness'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=whatsapp'),
                    'runtime_url' => 'whatsapp_messages.php',
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['inbound_instructions', 'customer_reply_drafts', 'daily_digest'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=email_assistant'),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['whatsapp_instructions', 'short_digest', 'session_keepalive'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=whatsapp_assistant'),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['sms_sending', 'sms_queue', 'delivery_tracking'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=sms_channel'),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['calendar_sync', 'meeting_bot', 'meeting_notes', 'meeting_prep'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=calendar_meetings'),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_FINANCE) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['finance_gate', 'opening_balance_setup', 'owner_roi'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=finance'),
                    'runtime_url' => 'finance.php',
                    'owner_roi_visible' => (bool) ($readiness[$key]['owner_roi_visible'] ?? false),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_AI_API) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $pluginsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['ai_provider_config', 'common_ai_routing', 'workspace_ai_api'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=ai_api'),
                    'provider_source' => (string) ($readiness[$key]['provider_source'] ?? ''),
                    'common_daily_token_cap' => (int) ($readiness[$key]['common_daily_token_cap'] ?? 0),
                    'common_used_today' => (int) ($readiness[$key]['common_used_today'] ?? 0),
                ];
            } elseif ($key === WorkspaceSkillCatalogService::SKILL_AI_COACH && $userId > 0) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $skillsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'capabilities' => ['recommendations', 'follow_through', 'workspace_guidance'],
                    'setup_url' => (string) ($skill['plugin_metadata']['setup_url'] ?? $skill['navigation']['url'] ?? 'workspace_skills.php?module=ai_coach'),
                ];
            } elseif (!empty($skill['is_custom'])) {
                $readiness[$key] = $this->buildReadinessForModule($workspaceId, $userId, $key);
                $skillsContext[$key] = [
                    'enabled' => (bool) ($readiness[$key]['enabled'] ?? false),
                    'ready' => (bool) ($readiness[$key]['ready'] ?? false),
                    'context_values' => (array) ($skill['config']['context_values'] ?? []),
                    'setup_url' => (string) ($skill['navigation']['url'] ?? ('workspace_skills.php?module=' . rawurlencode($key))),
                ];
            }
        }
        $context = array_merge($skillsContext, $pluginsContext);
        $contracts = $this->buildInstalledSkillContracts($workspaceId, $userId);

        return [
            'installed' => $installed,
            'installed_keys' => array_values(array_map(static fn(array $skill): string => (string) ($skill['key'] ?? ''), $installed)),
            'skill_keys' => array_values(array_map(
                static fn(array $skill): string => (string) ($skill['key'] ?? ''),
                array_values(array_filter($installed, static fn(array $skill): bool => (string) ($skill['module_type'] ?? 'skill') === 'skill'))
            )),
            'plugin_keys' => array_values(array_map(
                static fn(array $skill): string => (string) ($skill['key'] ?? ''),
                array_values(array_filter($installed, static fn(array $skill): bool => (string) ($skill['module_type'] ?? 'skill') === 'plugin'))
            )),
            'installed_skill_contracts' => $contracts,
            'context' => $context,
            'skills_context' => $skillsContext,
            'plugins_context' => $pluginsContext,
            'readiness' => $readiness,
        ];
    }

    public function tableReady(): bool
    {
        if ($this->installTableReady !== null) {
            return $this->installTableReady;
        }

        try {
            $this->installTableReady = (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_skill_installs'"
            );
        } catch (\Throwable $e) {
            $this->installTableReady = false;
        }

        return $this->installTableReady;
    }

    private function getInstalled(int $workspaceId, string $skillKey): ?array
    {
        $matches = array_values(array_filter(
            $this->installedForWorkspace($workspaceId),
            static fn(array $skill): bool => (string) ($skill['key'] ?? '') === $skillKey
        ));

        return $matches[0] ?? null;
    }

    private function isProtectedInstall(array $skill): bool
    {
        $metadata = (array) ($skill['plugin_metadata'] ?? []);
        $capabilities = (array) ($skill['capabilities'] ?? []);

        return !empty($metadata['protected_install'])
            || !empty($metadata['required_core'])
            || !empty($capabilities['protected_install'])
            || !empty($capabilities['required_core']);
    }

    private function assertCanManage(int $workspaceId, int $userId): void
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }

        $user = Auth::user();
        if (!$user && $userId > 0) {
            $user = Database::queryOne(
                "SELECT id, uuid, first_name, last_name, email, role, last_login, email_verified_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1",
                [$userId]
            );
        }
        if (Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user)) {
            return;
        }

        throw new \RuntimeException('Your access profile does not allow workspace skill management.');
    }

    private function recordEvent(int $workspaceId, string $skillKey, string $eventType, int $userId, array $metadata = []): void
    {
        if (!$this->eventsTableReady()) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_skill_events (workspace_id, skill_key, event_type, actor_user_id, metadata_json)
             VALUES (?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $skillKey,
                $eventType,
                $userId > 0 ? $userId : null,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function clearWorkspaceCaches(int $workspaceId, ?string $skillKey = null): void
    {
        unset($this->installedForWorkspaceCache[$workspaceId]);
        if ($skillKey !== null) {
            unset($this->isInstalledCache[$workspaceId . ':' . $this->normalizeKey($skillKey)]);
        } else {
            foreach (array_keys($this->isInstalledCache) as $cacheKey) {
                if (str_starts_with((string) $cacheKey, $workspaceId . ':')) {
                    unset($this->isInstalledCache[$cacheKey]);
                }
            }
        }

        foreach (array_keys($this->readinessCache) as $cacheKey) {
            if (str_starts_with((string) $cacheKey, $workspaceId . ':')) {
                unset($this->readinessCache[$cacheKey]);
            }
        }
    }

    private function eventsTableReady(): bool
    {
        if ($this->eventsTableReady !== null) {
            return $this->eventsTableReady;
        }

        try {
            $this->eventsTableReady = (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_skill_events'"
            );
        } catch (\Throwable $e) {
            $this->eventsTableReady = false;
        }

        return $this->eventsTableReady;
    }

    private function normalizeInstalledRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'key' => (string) ($row['skill_key'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'category' => (string) ($row['category'] ?? 'strategy'),
            'module_type' => (string) ($row['module_type'] ?? 'skill'),
            'version' => (string) ($row['version'] ?? '1.0.0'),
            'status' => (string) ($row['status'] ?? 'installed'),
            'config' => $this->decodeAssoc($row['config_json'] ?? null),
            'capabilities' => $this->decodeAssoc($row['capabilities_json'] ?? null),
            'owner_workspace_id' => isset($row['owner_workspace_id']) ? (int) $row['owner_workspace_id'] : null,
            'definition_source' => (string) ($row['definition_source'] ?? 'platform'),
            'is_custom' => in_array((string) ($row['definition_source'] ?? 'platform'), ['custom', 'template_clone'], true),
            'advice_domains' => $this->decodeList($row['advice_domains_json'] ?? null),
            'onboarding_fields' => $this->decodeList($row['onboarding_fields_json'] ?? null),
            'context_schema' => $this->decodeAssoc($row['context_schema_json'] ?? null),
            'task_templates' => $this->decodeListOfAssoc($row['task_templates_json'] ?? null),
            'boundary_policy' => (string) ($row['boundary_policy'] ?? 'strict'),
            'routing_examples' => $this->decodeList($row['routing_examples_json'] ?? null),
            'settings_schema' => $this->decodeAssoc($row['settings_schema_json'] ?? null),
            'plugin_metadata' => $this->decodeAssoc($row['plugin_metadata_json'] ?? null),
            'ai_context_provider' => (string) ($row['ai_context_provider'] ?? ''),
            'navigation' => $this->decodeAssoc($row['navigation_json'] ?? null),
            'permissions' => $this->decodeList($row['permissions_json'] ?? null),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'installed_at' => (string) ($row['installed_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeConfig(array $config): array
    {
        return json_decode(json_encode($config, JSON_UNESCAPED_SLASHES) ?: '{}', true) ?: [];
    }

    private function buildEmailAssistantReadiness(int $workspaceId): array
    {
        $workspaceConfig = [];
        try {
            $workspaceConfig = (new WorkspaceAssistantConfigService())->get($workspaceId, 'email', false);
        } catch (\Throwable $e) {
            $workspaceConfig = [];
        }

        $settings = (array) ($workspaceConfig['settings'] ?? []);
        $enabled = !empty($workspaceConfig['enabled']);
        $smtpReady = $enabled
            && trim((string) ($settings['smtp_host'] ?? '')) !== ''
            && trim((string) ($settings['smtp_username'] ?? '')) !== ''
            && trim((string) ($settings['from_email'] ?? $settings['system_email'] ?? '')) !== ''
            && trim((string) ($settings['smtp_password'] ?? '')) !== '';
        $imapConfigured = trim((string) ($settings['imap_host'] ?? '')) !== ''
            && trim((string) ($settings['imap_username'] ?? '')) !== ''
            && trim((string) ($settings['imap_password'] ?? '')) !== '';
        $oauthReady = false;
        try {
            $providerSummary = (new EmailIntegrationService())->getAssistantProviderSummary($workspaceId);
            $oauthReady = !empty($providerSummary['is_active']) && in_array((string) ($providerSummary['readiness'] ?? ''), ['ready', 'warning'], true);
        } catch (\Throwable $e) {
            $oauthReady = false;
        }
        $outboundReady = $enabled && ($smtpReady || $oauthReady);
        $inboundReady = $imapConfigured || $oauthReady;
        $enabledSkillCount = count(array_filter([
            !empty($settings['qa_enabled']),
            !empty($settings['instructions_enabled']),
            !empty($settings['customer_thread_enabled']),
            !empty($settings['customer_send_enabled']),
            !empty($settings['skill_create_contact']),
            !empty($settings['skill_update_contact']),
            !empty($settings['skill_delete_contact']),
            !empty($settings['skill_enrich_contact']),
            !empty($settings['skill_verify_email']),
            !empty($settings['skill_add_note']),
            !empty($settings['skill_get_pipeline']),
            !empty($settings['skill_list_tasks']),
            !empty($settings['skill_schedule_event']),
            !empty($settings['skill_run_report']),
        ]));
        $reopenTemplateReady = !empty($settings['reopen_template_enabled']) && trim((string) ($settings['reopen_template_name'] ?? '')) !== '';

        if (!$enabled) {
            $message = 'Email Assistant is installed but not enabled in Marketplace setup.';
        } elseif ($outboundReady) {
            $message = $inboundReady
                ? 'Email Assistant outbound and inbound Marketplace setup is ready.'
                : 'Email Assistant outbound is ready; inbound IMAP can be added when needed.';
        } else {
            $message = 'Email Assistant needs Marketplace SMTP identity setup before it can send safely.';
        }
        $checks = [
            ['label' => 'Assistant enabled', 'ok' => $enabled, 'required' => true, 'detail' => $enabled ? 'Enabled for this workspace.' : 'Turn on Email Assistant in setup.'],
            ['label' => 'SMTP host', 'ok' => trim((string) ($settings['smtp_host'] ?? '')) !== '', 'required' => true, 'detail' => 'Required for outbound delivery readiness.'],
            ['label' => 'SMTP username', 'ok' => trim((string) ($settings['smtp_username'] ?? '')) !== '', 'required' => true, 'detail' => 'Required for provider authentication.'],
            ['label' => 'SMTP password', 'ok' => trim((string) ($settings['smtp_password'] ?? '')) !== '', 'required' => true, 'detail' => 'Required for provider authentication.'],
            ['label' => 'From identity', 'ok' => trim((string) ($settings['from_email'] ?? $settings['system_email'] ?? '')) !== '', 'required' => true, 'detail' => 'Required before any outbound assistant email.'],
            ['label' => 'Assistant Gmail', 'ok' => $oauthReady, 'required' => false, 'detail' => 'OAuth can replace manual SMTP/IMAP when connected.'],
            ['label' => 'IMAP inbound', 'ok' => $inboundReady, 'required' => false, 'detail' => 'Optional unless inbound instruction capture is needed.'],
            ['label' => 'Skills', 'ok' => $enabledSkillCount > 0, 'required' => false, 'detail' => $enabledSkillCount . ' Email Assistant command skill(s) enabled.'],
            ['label' => 'Digest controls', 'ok' => !empty($settings['digest_enabled']), 'required' => false, 'detail' => 'Optional daily digest support.'],
            ['label' => 'Reopen template', 'ok' => $reopenTemplateReady, 'required' => false, 'detail' => 'Optional controlled follow-up template.'],
        ];
        if ($oauthReady) {
            foreach ($checks as &$check) {
                if (in_array((string) ($check['label'] ?? ''), ['SMTP host', 'SMTP username', 'SMTP password'], true)) {
                    $check['required'] = false;
                    $check['detail'] = 'Optional when Assistant Gmail OAuth is connected.';
                }
            }
            unset($check);
        }

        return [
            'enabled' => $enabled,
            'ready' => $outboundReady,
            'outbound_ready' => $outboundReady,
            'inbound_ready' => $inboundReady,
            'digest_enabled' => !empty($settings['digest_enabled']),
            'reopen_template_ready' => $reopenTemplateReady,
            'status' => $outboundReady ? 'ready' : ($enabled ? 'needs_setup' : 'disabled'),
            'message' => $message,
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $smtpReady ? 'Run a controlled explicit-recipient test when ready.' : 'Complete the required Email Assistant Marketplace setup fields.',
        ];
    }

    private function buildWhatsAppAssistantReadiness(int $workspaceId): array
    {
        $runtimeSnapshot = WorkspaceContext::runtimeSnapshot();
        if ($workspaceId > 0) {
            WorkspaceContext::activateRuntimeWorkspace($workspaceId);
        }

        try {
            $validation = (new WhatsAppAssistantConfig())->validate();
        } catch (\Throwable $e) {
            return [
                'enabled' => false,
                'ready' => false,
                'outbound_ready' => false,
                'inbound_ready' => false,
                'status' => 'unavailable',
                'message' => 'WhatsApp Assistant readiness is unavailable.',
            ];
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
        }

        $workspaceConfig = [];
        try {
            $workspaceConfig = (new WorkspaceAssistantConfigService())->get($workspaceId, 'whatsapp', false);
        } catch (\Throwable $e) {
            $workspaceConfig = [];
        }
        $settings = (array) ($workspaceConfig['settings'] ?? []);
        $enabledActionCount = count(array_filter(
            AssistantActionRuntimeConfig::ACTION_SETTING_KEYS,
            static fn(string $key): bool => !empty($settings[$key])
        ));
        $hasAuthorizedNumbers = (int) ($validation['authorized_number_count'] ?? 0) > 0;
        $ready = !empty($validation['outbound_ready']) && !empty($validation['inbound_ready']) && $hasAuthorizedNumbers;
        $enabled = !empty($validation['enabled']);
        $usesWorkspaceSender = !empty($validation['uses_workspace_sender']);
        $webhookDetail = $usesWorkspaceSender
            ? 'Inbound messages route through the active workspace WhatsApp webhook.'
            : (!empty($validation['custom_webhook_ready'])
                ? 'Webhook verification and routing are ready for the custom assistant number.'
                : 'The custom assistant number must match the workspace webhook metadata.');
        $checks = [
            ['label' => 'Assistant enabled', 'ok' => $enabled, 'required' => true, 'detail' => $enabled ? 'Enabled for WhatsApp assistant workflows.' : 'Turn on WhatsApp Assistant in setup.'],
            ['label' => 'Sender source', 'ok' => !empty($validation['workspace_sender_ready']) || !$usesWorkspaceSender, 'required' => true, 'detail' => $usesWorkspaceSender ? 'Using the workspace WhatsApp sender.' : 'Using a custom assistant sender.'],
            ['label' => 'Outbound ready', 'ok' => !empty($validation['outbound_ready']), 'required' => true, 'detail' => $usesWorkspaceSender ? 'Workspace phone number ID and access token are inherited.' : 'Custom phone number ID and access token are required.'],
            ['label' => 'Webhook ready', 'ok' => !empty($validation['inbound_ready']), 'required' => true, 'detail' => $webhookDetail],
            ['label' => 'Authorized team numbers', 'ok' => $hasAuthorizedNumbers, 'required' => true, 'detail' => $hasAuthorizedNumbers ? 'At least one team number can use WhatsApp Assistant.' : 'Add an active authorized team number in WhatsApp Assistant setup.'],
            ['label' => 'Action controls', 'ok' => $enabledActionCount > 0, 'required' => false, 'detail' => $enabledActionCount > 0 ? $enabledActionCount . ' WhatsApp Assistant action control(s) enabled.' : 'Optional independent command controls.'],
            ['label' => 'Digest controls', 'ok' => !empty($validation['digest_enabled']), 'required' => false, 'detail' => 'Optional daily summary support.'],
            ['label' => 'Reopen template', 'ok' => !empty($validation['reopen_template_ready']), 'required' => false, 'detail' => 'Optional session continuity support.'],
        ];
        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'outbound_ready' => !empty($validation['outbound_ready']),
            'inbound_ready' => !empty($validation['inbound_ready']),
            'authorized_number_count' => (int) ($validation['authorized_number_count'] ?? 0),
            'enabled_action_count' => $enabledActionCount,
            'digest_enabled' => !empty($validation['digest_enabled']),
            'reopen_template_ready' => !empty($validation['reopen_template_ready']),
            'status' => $ready ? 'ready' : ($enabled ? 'needs_setup' : 'disabled'),
            'message' => (string) ($validation['message'] ?? 'Configure WhatsApp Assistant settings.'),
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Monitor sessions and recent webhook activity.' : 'Complete WhatsApp assistant credentials and webhook setup.',
        ];
    }

    private function buildEmailChannelReadiness(int $workspaceId): array
    {
        $status = (new WorkspaceCommunicationGateService())->status($workspaceId, Auth::user());
        $channels = (array) ($status['channels'] ?? []);
        $mainEmail = (array) ($channels['main_email'] ?? []);
        $outreachEmail = (array) ($channels['outreach_email'] ?? []);
        $nurtureEmail = (array) ($channels['nurture_email'] ?? []);
        $outreachOutboundReady = $this->emailChannelOutboundReady($outreachEmail);
        $nurtureOutboundReady = $this->emailChannelOutboundReady($nurtureEmail);
        $emailReady = $outreachOutboundReady || $nurtureOutboundReady;
        $emailInboundReady = (!empty($outreachEmail['inbound_ready']) && $outreachOutboundReady)
            || (!empty($nurtureEmail['inbound_ready']) && $nurtureOutboundReady);

        $checks = [
            [
                'label' => 'System Mail separate',
                'ok' => $this->emailChannelOutboundReady($mainEmail),
                'required' => false,
                'detail' => 'System Mail is separate and does not make the workspace Email plugin ready.',
            ],
            [
                'label' => 'Outreach email',
                'ok' => $outreachOutboundReady,
                'required' => false,
                'detail' => (string) ($outreachEmail['label'] ?? 'Outreach email is not connected.'),
            ],
            [
                'label' => 'Nurture email',
                'ok' => $nurtureOutboundReady,
                'required' => false,
                'detail' => (string) ($nurtureEmail['label'] ?? 'Nurture email is not connected.'),
            ],
            [
                'label' => 'At least one email identity ready',
                'ok' => $emailReady,
                'required' => true,
                'detail' => $emailReady ? 'Email runtime can send or receive through Outreach or Nurture.' : 'Connect Outreach or Nurture Email before using workspace email runtime.',
            ],
        ];

        return [
            'enabled' => true,
            'ready' => $emailReady,
            'outbound_ready' => $emailReady,
            'inbound_ready' => $emailInboundReady,
            'status' => $emailReady ? ($emailInboundReady ? 'ready' : 'sending_ready') : 'needs_setup',
            'message' => $emailReady
                ? ($emailInboundReady ? 'Email channel is ready.' : 'Email sending is ready; inbox capture is not connected yet.')
                : 'Email needs Outreach or Nurture setup before email runtime is ready.',
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $emailReady ? 'Open Email or run a controlled readiness check.' : 'Complete Email Marketplace setup.',
            'channels' => [
                'main_email' => $mainEmail,
                'outreach_email' => $outreachEmail,
                'nurture_email' => $nurtureEmail,
            ],
        ];
    }

    private function buildWhatsAppChannelReadiness(int $workspaceId): array
    {
        $status = (new WorkspaceCommunicationGateService())->status($workspaceId, Auth::user());
        $channels = (array) ($status['channels'] ?? []);
        $whatsapp = (array) ($channels['whatsapp'] ?? []);
        $ready = (string) ($whatsapp['status'] ?? '') === 'ready';
        $outboundReady = !empty($whatsapp['outbound_ready']) || $ready;
        $inboundReady = !empty($whatsapp['inbound_ready']) || $ready;
        $numberPresent = trim((string) ($whatsapp['phone_number_id'] ?? '')) !== ''
            || trim((string) ($whatsapp['display_phone_number'] ?? '')) !== '';

        $checks = [
            [
                'label' => 'WhatsApp Business number',
                'ok' => $numberPresent,
                'required' => true,
                'detail' => $numberPresent ? 'WhatsApp Business number details are saved.' : 'Save the WhatsApp phone number ID from Meta.',
            ],
            [
                'label' => 'Manual API credentials',
                'ok' => $outboundReady,
                'required' => true,
                'detail' => $outboundReady ? 'Phone number ID and access token are saved for Cloud API sending.' : 'Save the phone number ID and Cloud API access token.',
            ],
            [
                'label' => 'Webhook verification',
                'ok' => $inboundReady,
                'required' => true,
                'detail' => $inboundReady ? 'Workspace webhook URL and verify token are configured for inbound routing.' : 'Save WhatsApp settings, then copy the workspace webhook URL and verify token into Meta.',
            ],
            [
                'label' => 'WhatsApp runtime ready',
                'ok' => $ready,
                'required' => true,
                'detail' => $ready ? 'WhatsApp runtime is ready.' : 'Finish manual credentials and webhook verification.',
            ],
            [
                'label' => 'Embedded signup',
                'ok' => !empty($whatsapp['embedded_signup_configured']),
                'required' => false,
                'detail' => !empty($whatsapp['embedded_signup_configured'])
                    ? 'Optional Meta embedded signup is available.'
                    : 'Optional only. Manual setup works without embedded signup.',
            ],
        ];

        return [
            'enabled' => true,
            'ready' => $ready,
            'outbound_ready' => $outboundReady,
            'inbound_ready' => $inboundReady,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready
                ? 'WhatsApp channel is ready.'
                : ($outboundReady ? 'WhatsApp sending credentials are saved; finish webhook verification before runtime is fully ready.' : 'WhatsApp needs manual Business API credentials before runtime is ready.'),
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Open WhatsApp Messages or run a controlled readiness check.' : ($outboundReady ? 'Complete webhook verification in WhatsApp Marketplace setup.' : 'Complete manual WhatsApp Marketplace setup.'),
            'channels' => ['whatsapp' => $whatsapp],
        ];
    }

    private function emailChannelHealthReady(array $health): bool
    {
        return $this->emailChannelOutboundReady($health);
    }

    private function emailChannelOutboundReady(array $health): bool
    {
        if (array_key_exists('outbound_ready', $health)) {
            return !empty($health['outbound_ready']);
        }

        return in_array((string) ($health['status'] ?? ''), ['ready', 'warning'], true);
    }

    private function buildHRAnalyticsSetupReadiness(int $workspaceId): array
    {
        $status = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);
        $ready = !empty($status['ready']);
        $checks = array_merge([
            [
                'label' => 'Required Marketplace plugin',
                'ok' => true,
                'required' => true,
                'detail' => 'Organization Intelligence stays installed for every workspace.',
            ],
        ], (array) ($status['checks'] ?? []));

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => (string) ($status['message'] ?? ($ready ? 'Organization Intelligence setup is ready.' : 'Complete the guided Organization Intelligence setup before opening the dashboard.')),
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => (string) ($status['next_action'] ?? ($ready ? 'Open Organization Intelligence.' : 'Finish the guided Organization Intelligence setup.')),
            'counts' => (array) ($status['counts'] ?? []),
            'actions' => (array) ($status['actions'] ?? []),
        ];
    }

    private function buildSmsChannelReadiness(int $workspaceId): array
    {
        return (new WorkspaceSmsChannelConfigService())->readiness($workspaceId);
    }

    private function buildCalendarMeetingsReadiness(int $workspaceId): array
    {
        $calendarConnections = 0;
        try {
            if (Database::tableExists('calendar_integrations')) {
                $calendarConnections = (int) ((Database::queryOne(
                    "SELECT COUNT(*) AS c FROM calendar_integrations WHERE workspace_id = ? AND sync_enabled = 1",
                    [$workspaceId]
                )['c'] ?? 0));
            }
        } catch (\Throwable $e) {
            $calendarConnections = 0;
        }

        $meetingBot = [];
        $meetingNotes = [];
        try {
            $meetingBot = (new \CRM\Modules\MeetingBotConfig())->get($workspaceId, false);
        } catch (\Throwable $e) {
            $meetingBot = [];
        }
        try {
            $meetingNotes = (new \CRM\Modules\MeetingNoteTakerConfig())->get($workspaceId, false);
        } catch (\Throwable $e) {
            $meetingNotes = [];
        }
        $botEnabled = !empty($meetingBot['enabled']);
        $botCredentialReady = trim((string) ($meetingBot['zoom_client_id'] ?? $meetingBot['google_workspace_client_id'] ?? '')) !== '';
        $notesEnabled = !empty($meetingNotes['enabled']);
        $noteStorageReady = Database::tableExists('meeting_notes') || Database::tableExists('meeting_note_taker_runs');
        $ready = $calendarConnections > 0 || ($botEnabled && $botCredentialReady) || ($notesEnabled && $noteStorageReady);
        $checks = [
            ['label' => 'Connected calendar', 'ok' => $calendarConnections > 0, 'required' => true, 'detail' => $calendarConnections > 0 ? $calendarConnections . ' calendar connection(s) enabled.' : 'Connect Google or Outlook calendar.'],
            ['label' => 'Meeting bot enabled', 'ok' => $botEnabled, 'required' => false, 'detail' => $botEnabled ? 'Meeting bot is enabled.' : 'Enable when the workspace is ready for bot-assisted meetings.'],
            ['label' => 'Bot credentials', 'ok' => $botCredentialReady, 'required' => false, 'detail' => 'Needed for provider-native bot operations.'],
            ['label' => 'Note ingestion enabled', 'ok' => $notesEnabled, 'required' => false, 'detail' => $notesEnabled ? 'Meeting note ingestion is enabled.' : 'Enable when this workspace is ready to ingest meeting notes.'],
            ['label' => 'Note ingestion storage', 'ok' => $noteStorageReady, 'required' => false, 'detail' => 'Required for transcript/note ingestion workflows.'],
        ];

        return [
            'enabled' => $calendarConnections > 0 || $botEnabled,
            'ready' => $ready,
            'outbound_ready' => $calendarConnections > 0,
            'inbound_ready' => $noteStorageReady,
            'calendar_connections' => $calendarConnections,
            'meeting_bot_enabled' => $botEnabled,
            'meeting_note_taker_enabled' => $notesEnabled,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready
                ? 'Calendar, bot, or note ingestion automation is configured for this workspace.'
                : 'Connect a calendar or configure meeting automation to activate this module.',
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Review meeting bot policy and recent note ingestion.' : 'Connect a calendar from the marketplace setup section.',
        ];
    }

    private function marketingStrategyContextValues(int $userId): array
    {
        $profile = [];
        try {
            $profile = (new \CRM\Modules\UserStrategyProfile())->get($userId) ?: [];
        } catch (\Throwable $e) {
        }

        return [
            'target_market_focus' => (string) ($profile['target_market_focus'] ?? ''),
            'ideal_customer_profile' => (string) ($profile['ideal_customer_profile'] ?? ''),
            'offer_angle' => (string) ($profile['offer_angle'] ?? ''),
            'segment_focus' => (string) ($profile['segment_focus'] ?? ''),
            'sales_motion' => (string) ($profile['sales_motion'] ?? ''),
            'deal_movement_strategy' => (string) ($profile['deal_movement_strategy'] ?? ''),
            'outreach_posture' => (string) ($profile['outreach_posture'] ?? ''),
            'positioning_notes' => (string) ($profile['positioning_notes'] ?? ''),
        ];
    }

    private function marketingSplitPluginContextValues(int $workspaceId, string $pluginKey): array
    {
        $readiness = $this->buildMarketingSplitPluginReadiness($workspaceId, $pluginKey);
        return [
            'surface' => $pluginKey,
            'counts' => (array) ($readiness['counts'] ?? []),
            'guidance' => $pluginKey === WorkspaceSkillCatalogService::PLUGIN_DESIGN
                ? 'Use Design for forms, websites, landing pages, creative assets, and conversion-focused page workflows.'
                : 'Use Social Media for social connectors, content creation, distribution posts, channel exports, social analytics, and ads.',
        ];
    }

    private function buildMarketingSplitPluginReadiness(int $workspaceId, string $pluginKey): array
    {
        if ($pluginKey === WorkspaceSkillCatalogService::PLUGIN_DESIGN) {
            $landingPages = $this->safeCountRows('marketing_landing_pages', $workspaceId, "status <> 'archived'");
            $publishedPages = $this->safeCountRows('marketing_landing_page_publications', $workspaceId, "status = 'published'");
            $forms = $this->safeCountRows('forms', $workspaceId, '1=1');
            $mediaFiles = $this->safeCountRows('marketing_media_files', $workspaceId, '1=1')
                + $this->safeCountRows('marketing_assets', $workspaceId, "asset_type IN ('image','video','document','other')");
            $ready = $landingPages > 0 || $forms > 0 || $publishedPages > 0;
            $checks = [
                ['label' => 'Conversion surface', 'ok' => $ready, 'required' => true, 'detail' => $ready ? 'A landing page or lead capture form is connected.' : 'Create one landing page or lead capture form.'],
                ['label' => 'Landing pages', 'ok' => $landingPages > 0, 'required' => false, 'detail' => $landingPages . ' active landing page(s).'],
                ['label' => 'Lead capture forms', 'ok' => $forms > 0, 'required' => false, 'detail' => $forms . ' workspace form(s).'],
                ['label' => 'Published pages', 'ok' => $publishedPages > 0, 'required' => false, 'detail' => $publishedPages . ' published landing page(s).'],
                ['label' => 'Creative assets', 'ok' => $mediaFiles > 0, 'required' => false, 'detail' => $mediaFiles . ' design/media asset(s).'],
            ];

            return [
                'enabled' => true,
                'ready' => $ready,
                'status' => $ready ? 'ready' : 'needs_setup',
                'message' => $ready ? 'Design has landing page, form, or page publication activity.' : 'Design needs a landing page or lead capture form to be ready.',
                'checks' => $checks,
                'blockers' => $ready ? [] : ['Landing page or lead capture form'],
                'next_action' => $ready ? 'Review landing page and form conversion readiness.' : 'Create a landing page or connect a form from Design setup.',
                'counts' => [
                    'landing_pages' => $landingPages,
                    'published_pages' => $publishedPages,
                    'forms' => $forms,
                    'media_files' => $mediaFiles,
                ],
            ];
        }

        $contentItems = $this->safeCountRows('marketing_content_items', $workspaceId, "status <> 'archived'");
        $socialContent = $this->safeCountRows('marketing_content_items', $workspaceId, "status <> 'archived' AND (content_type = 'social_post' OR channel IN ('linkedin','facebook','instagram'))");
        $distributionPosts = $this->safeCountRows('marketing_distribution_posts', $workspaceId, "status <> 'cancelled'");
        $legacyConnectors = $this->safeCountRows('marketing_channel_connectors', $workspaceId, "connector_type IN ('linkedin','facebook_instagram') AND status <> 'archived'");
        $activeAccounts = $this->safeCountRows('social_media_accounts', $workspaceId, "status = 'active'");
        $accountIssues = $this->safeCountRows('social_media_accounts', $workspaceId, "status IN ('expired','revoked','error')");
        $queuedPosts = $this->safeCountRows('social_media_publish_jobs', $workspaceId, "status IN ('pending_approval','approved','queued','processing')");
        $publishedPosts = $this->safeCountRows('social_media_publish_jobs', $workspaceId, "status = 'published'");
        $settingsReady = $this->safeCountRows('social_media_settings', $workspaceId, "enabled = 1 AND COALESCE(NULLIF(TRIM(brand_name), ''), '') <> ''") > 0;
        $ready = $settingsReady && $activeAccounts > 0;
        $checks = [
            ['label' => 'Brand and publishing policy', 'ok' => $settingsReady, 'required' => true, 'detail' => $settingsReady ? 'Brand profile and publishing policy are saved.' : 'Save a brand name and publishing policy.'],
            ['label' => 'Active social account', 'ok' => $activeAccounts > 0, 'required' => true, 'detail' => $activeAccounts . ' active Facebook, Instagram, or LinkedIn account(s).'],
            ['label' => 'Account health', 'ok' => $accountIssues === 0, 'required' => false, 'detail' => $accountIssues . ' account connection issue(s).'],
            ['label' => 'Publishing queue', 'ok' => $queuedPosts > 0, 'required' => false, 'detail' => $queuedPosts . ' post(s) awaiting approval or publishing.'],
            ['label' => 'Published posts', 'ok' => $publishedPosts > 0, 'required' => false, 'detail' => $publishedPosts . ' provider-confirmed published post(s).'],
        ];

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready ? 'Social Media has a brand policy and at least one healthy live destination.' : 'Social Media needs its brand policy and at least one active Meta or LinkedIn destination.',
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Open Social Media to create, approve, schedule, and measure posts.' : 'Save the brand profile, then connect Meta or LinkedIn.',
            'counts' => [
                'content_items' => $contentItems,
                'social_content' => $socialContent,
                'distribution_posts' => $distributionPosts,
                'connectors' => $activeAccounts,
                'legacy_connectors' => $legacyConnectors,
                'account_issues' => $accountIssues,
                'queued_posts' => $queuedPosts,
                'published_posts' => $publishedPosts,
            ],
        ];
    }

    private function buildMarketingProReadiness(int $workspaceId, int $userId): array
    {
        $strategy = $this->buildProfessionalMarketerReadiness($userId);
        $campaigns = $this->safeCountRows('campaigns', $workspaceId, '1=1');
        $briefs = $this->safeCountRows('marketing_campaign_briefs', $workspaceId, "status <> 'archived'");
        $analytics = $this->safeCountRows('marketing_analytics_snapshots', $workspaceId, '1=1');
        $launchControls = $this->safeCountRows('marketing_launch_control_records', $workspaceId, "status <> 'archived'")
            + $this->safeCountRows('marketing_campaign_launch_checklists', $workspaceId, "status <> 'archived'");
        $executionQueue = $this->safeCountRows('marketing_execution_queue', $workspaceId, "status <> 'archived'");
        $hasOperatingEvidence = ($campaigns + $briefs + $analytics + $launchControls + $executionQueue) > 0;
        $ready = !empty($strategy['ready']) || $hasOperatingEvidence;
        $checks = [
            ['label' => 'Strategy context', 'ok' => !empty($strategy['ready']), 'required' => true, 'detail' => (string) (($strategy['checks'][0]['detail'] ?? '') ?: 'Campaign Manager uses target, segment, outreach, and positioning context.')],
            ['label' => 'Campaign setup', 'ok' => ($campaigns + $briefs) > 0, 'required' => false, 'detail' => ($campaigns + $briefs) . ' campaign or brief record(s).'],
            ['label' => 'Analytics snapshots', 'ok' => $analytics > 0, 'required' => false, 'detail' => $analytics . ' analytics snapshot(s).'],
            ['label' => 'Launch controls', 'ok' => $launchControls > 0, 'required' => false, 'detail' => $launchControls . ' launch control/checklist record(s).'],
            ['label' => 'Execution queue', 'ok' => $executionQueue > 0, 'required' => false, 'detail' => $executionQueue . ' orchestration queue record(s).'],
        ];

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready ? 'Campaign Manager has strategy context or operating evidence.' : 'Campaign Manager needs strategy setup or campaign management activity.',
            'checks' => $checks,
            'blockers' => $ready ? [] : ['Strategy context'],
            'next_action' => $ready ? 'Review campaign management, analytics, and attribution readiness.' : 'Complete Campaign Manager setup fields or create a campaign brief.',
            'counts' => [
                'campaigns' => $campaigns,
                'campaign_briefs' => $briefs,
                'analytics_snapshots' => $analytics,
                'launch_controls' => $launchControls,
                'execution_queue' => $executionQueue,
            ],
            'strategy_context' => $strategy,
        ];
    }

    private function safeCountRows(string $table, int $workspaceId, string $condition): int
    {
        try {
            if ($workspaceId <= 0 || !Database::tableExists($table) || !Database::columnExists($table, 'workspace_id')) {
                return 0;
            }
            $row = Database::queryOne("SELECT COUNT(*) AS c FROM {$table} WHERE workspace_id = ? AND ({$condition})", [$workspaceId]);
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function buildAiApiReadiness(int $workspaceId): array
    {
        $configs = new WorkspaceAIProviderConfigService();
        $isDefaultWorkspace = WorkspaceContext::isDefaultWorkspace($workspaceId);
        $workspaceConfig = [];
        $defaultConfig = [];
        $resolved = [];

        try {
            $workspaceConfig = $configs->get($workspaceId, false);
            $defaultConfig = $configs->getDefaultWorkspaceConfig(false);
            $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace($workspaceId, 'marketplace_setup');
        } catch (\Throwable $e) {
            $resolved = [
                'available' => false,
                'source' => WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK,
                'message' => 'AI provider status is unavailable until migrations and provider setup are complete.',
                'blocked_reason' => 'ai_provider_status_unavailable',
            ];
        }

        $commonConfigured = !empty($defaultConfig['enabled'])
            && !empty($defaultConfig['api_key_present'])
            && trim((string) ($defaultConfig['normalized_api_url'] ?? '')) !== '';
        $workspaceConfigured = !empty($workspaceConfig['enabled'])
            && !empty($workspaceConfig['api_key_present'])
            && trim((string) ($workspaceConfig['normalized_api_url'] ?? '')) !== '';
        $cap = max(0, (int) ($resolved['common_daily_token_cap'] ?? $defaultConfig['shared_daily_token_cap'] ?? 0));
        $used = max(0, (int) ($resolved['common_used_today'] ?? 0));
        $capRemaining = $cap <= 0 ? null : max(0, $cap - $used);
        $capExceeded = $cap > 0 && $used >= $cap;
        $source = (string) ($resolved['source'] ?? WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK);
        $available = !empty($resolved['available']);
        $ready = $isDefaultWorkspace ? $commonConfigured : $available;
        $status = $ready
            ? ($source === WorkspaceAIProviderResolverService::SOURCE_WORKSPACE_API ? 'ready_workspace_api' : 'ready_common_api')
            : ($capExceeded ? 'workspace_key_required' : 'needs_setup');

        $checks = [
            [
                'label' => 'Common AI provider',
                'ok' => $commonConfigured,
                'required' => $isDefaultWorkspace,
                'detail' => $commonConfigured ? 'Default workspace common API is configured.' : 'Configure the default workspace common API before relying on platform-funded AI.',
            ],
            [
                'label' => 'Common cap',
                'ok' => !$capExceeded,
                'required' => false,
                'detail' => $cap <= 0 ? 'No daily common cap is set.' : ($capRemaining ?? 0) . ' common token(s) remain today.',
            ],
            [
                'label' => 'Workspace AI provider',
                'ok' => $workspaceConfigured,
                'required' => !$isDefaultWorkspace && $capExceeded,
                'detail' => $workspaceConfigured ? 'Workspace API key is configured for cap overflow.' : 'Add a workspace API key to continue after the common cap is reached.',
            ],
            [
                'label' => 'Current route',
                'ok' => $available,
                'required' => true,
                'detail' => (string) ($resolved['message'] ?? 'AI provider routing is not configured.'),
            ],
        ];

        return [
            'enabled' => $ready || $workspaceConfigured || $commonConfigured,
            'ready' => $ready,
            'status' => $status,
            'message' => (string) ($resolved['message'] ?? ($ready ? 'AI provider routing is ready.' : 'AI provider routing needs setup.')),
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Monitor AI usage and provider routing.' : ($capExceeded ? 'Add a workspace AI API key.' : 'Configure the common or workspace AI API provider.'),
            'provider_source' => $source,
            'common_daily_token_cap' => $cap,
            'common_used_today' => $used,
            'common_cap_exceeded' => $capExceeded,
            'workspace_api_configured' => $workspaceConfigured,
            'common_api_configured' => $commonConfigured,
            'is_default_workspace' => $isDefaultWorkspace,
        ];
    }

    private function buildAiCoachReadiness(int $workspaceId, int $userId): array
    {
        $enabled = false;
        try {
            $enabled = (new AICoachWorkspaceSetupService($this))->isWorkspaceEnabled($workspaceId);
        } catch (\Throwable $e) {
            $enabled = false;
        }

        $recommendationApiReady = class_exists(\CRM\Modules\AICoach::class);
        $readyAdviceSkills = [];
        try {
            foreach ($this->installedForWorkspace($workspaceId) as $skill) {
                $key = (string) ($skill['key'] ?? '');
                if ($key === '' || $key === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
                    continue;
                }
                if ((string) ($skill['module_type'] ?? 'skill') !== 'skill') {
                    continue;
                }
                if ((string) ($skill['boundary_policy'] ?? 'strict') === 'orchestrator_only') {
                    continue;
                }
                $readiness = $this->buildReadinessForModule($workspaceId, $userId, $key);
                if (!empty($readiness['ready'])) {
                    $readyAdviceSkills[] = (string) ($skill['label'] ?? $key);
                }
            }
        } catch (\Throwable $e) {
            $readyAdviceSkills = [];
        }

        $checks = [
            ['label' => 'Installed for workspace', 'ok' => true, 'required' => true, 'detail' => 'AI Coach orchestration skill is installed.'],
            ['label' => 'Enabled for workspace', 'ok' => $enabled, 'required' => true, 'detail' => $enabled ? 'AI Coach is enabled for workspace members.' : 'Enable AI Coach in Marketplace setup.'],
            ['label' => 'Recommendation API', 'ok' => $recommendationApiReady, 'required' => true, 'detail' => $recommendationApiReady ? 'Coach recommendation module is available.' : 'Coach recommendation module is unavailable.'],
            ['label' => 'Ready advice-skill coverage', 'ok' => $readyAdviceSkills !== [], 'required' => false, 'detail' => $readyAdviceSkills !== [] ? 'Strategic recommendations can use: ' . implode(', ', $readyAdviceSkills) . '.' : 'Install and complete Lean Canvas, Campaign Manager, or another advice skill before expecting business advice.'],
        ];
        $ready = $enabled && $recommendationApiReady;

        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($enabled ? 'needs_setup' : 'disabled'),
            'message' => $ready
                ? 'AI Coach orchestration is enabled for this workspace.'
                : 'Enable AI Coach for this workspace and confirm the recommendation API is available.',
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Open AI Coach to review recommendations from installed skills and workspace activity.' : 'Enable AI Coach for this workspace from Marketplace setup.',
            'ready_advice_skills' => $readyAdviceSkills,
        ];
    }

    private function buildLeanCanvasReadiness(int $userId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0 && \CRM\Database::tableExists('startup_journeys')) {
            try {
                return (new StartupJourneyService())->readiness($workspaceId, $userId);
            } catch (\Throwable $e) {
            }
        }

        $status = ['completeness' => 0, 'missing_blocks' => []];
        try {
            $status = (new \CRM\Modules\UserStrategyProfile())->getLeanCanvasStatus($userId);
        } catch (\Throwable $e) {
        }
        $missingBlocks = array_values(array_filter(array_map('strval', (array) ($status['missing_blocks'] ?? []))));
        $total = 9;
        $completed = max(0, $total - count($missingBlocks));
        $ready = $completed >= $total;
        $missingLabels = array_map(static fn(string $block): string => ucwords(str_replace('_', ' ', $block)), $missingBlocks);

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready ? 'Lean Canvas business-model context is complete.' : 'Lean Canvas needs more business-model context.',
            'checks' => [
                ['label' => 'Business model context', 'ok' => $ready, 'required' => true, 'detail' => $completed . ' of ' . $total . ' Lean Canvas blocks complete' . ($missingLabels !== [] ? '; missing: ' . implode(', ', $missingLabels) : '') . '.'],
            ],
            'blockers' => $ready ? [] : $missingLabels,
            'next_action' => $ready ? 'Review canvas-backed recommendations in AI Coach or Clarity.' : 'Complete the missing Lean Canvas business-model blocks in Marketplace.',
            'completeness' => (int) ($status['completeness'] ?? ($ready ? 100 : 0)),
            'missing_blocks' => $missingBlocks,
        ];
    }

    private function buildProfessionalMarketerReadiness(int $userId): array
    {
        $profile = [];
        try {
            $profile = (new \CRM\Modules\UserStrategyProfile())->get($userId) ?: [];
        } catch (\Throwable $e) {
        }
        $fields = ['target_market_focus', 'ideal_customer_profile', 'offer_angle', 'segment_focus', 'sales_motion', 'deal_movement_strategy', 'outreach_posture', 'positioning_notes'];
        $completed = count(array_filter($fields, static fn(string $field): bool => trim((string) ($profile[$field] ?? '')) !== ''));
        $total = count($fields);
        $ready = $completed >= $total;

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready ? 'Marketing strategy context is complete.' : 'Marketing strategy setup needs more positioning context.',
            'checks' => [
                ['label' => 'Marketing setup fields', 'ok' => $ready, 'required' => true, 'detail' => $completed . ' of ' . $total . ' fields complete.'],
            ],
            'blockers' => $ready ? [] : ['Marketing setup fields'],
            'next_action' => $ready ? 'Use this context for campaigns and positioning guidance.' : 'Complete the missing marketing setup fields.',
        ];
    }

    private function decodeAssoc(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeList(mixed $json): array
    {
        return array_values(array_filter($this->decodeAssoc($json), static fn(mixed $value): bool => is_scalar($value)));
    }

    private function decodeListOfAssoc(mixed $json): array
    {
        $decoded = $this->decodeAssoc($json);
        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $out[] = $item;
            } elseif (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = ['title' => trim((string) $item), 'description' => ''];
            }
        }

        return $out;
    }

    private function normalizeContextValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $cleanKey = trim($this->normalizeKey((string) $key), '_');
            if ($cleanKey === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            $cleanValue = trim(strip_tags((string) $value));
            if ($cleanValue !== '') {
                $out[$cleanKey] = function_exists('mb_substr') ? mb_substr($cleanValue, 0, 4000) : substr($cleanValue, 0, 4000);
            }
        }

        return $out;
    }

    private function buildCustomSkillReadiness(int $workspaceId, string $skillKey, bool $installed, array $definition): array
    {
        $contextValues = [];
        if ($installed) {
            $installedSkill = $this->getInstalled($workspaceId, $skillKey) ?? [];
            $contextValues = (array) ($installedSkill['config']['context_values'] ?? []);
        }

        $checks = [];
        foreach ((array) (($definition['context_schema']['fields'] ?? []) ?: []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $required = array_key_exists('required', $field) ? (bool) $field['required'] : true;
            $ok = trim((string) ($contextValues[$key] ?? '')) !== '';
            $checks[] = [
                'label' => (string) ($field['label'] ?? $key),
                'ok' => !$required || $ok,
                'required' => $required,
                'detail' => $ok ? 'Context saved.' : 'Context value is missing.',
            ];
        }
        $blockers = array_values(array_map(
            static fn(array $check): string => (string) $check['label'],
            array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
        ));
        $ready = $installed && $blockers === [];

        return [
            'enabled' => $installed,
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($installed ? 'needs_setup' : 'not_installed'),
            'message' => $ready ? 'Custom skill context is ready.' : 'Custom skill needs its required context fields.',
            'checks' => $checks,
            'blockers' => $blockers,
            'next_action' => $ready ? 'Use this skill for matching guidance requests.' : 'Fill the required custom skill context fields.',
        ];
    }

    private function normalizeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? '');
    }
}
