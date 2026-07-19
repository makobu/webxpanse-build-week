<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;

class WorkspaceSkillCatalogService
{
    public const SKILL_LEAN_CANVAS = 'lean_canvas';
    public const SKILL_PROFESSIONAL_MARKETER = 'professional_marketer';
    public const SKILL_AI_COACH = 'ai_coach';
    public const PLUGIN_SOCIAL_MEDIA = 'social_media';
    public const PLUGIN_DESIGN = 'design';
    public const PLUGIN_MARKETING_PRO = 'marketing_pro';
    public const PLUGIN_COMMUNICATION_SETUP = 'communication_setup';
    public const PLUGIN_EMAIL = 'email';
    public const PLUGIN_WHATSAPP = 'whatsapp';
    public const PLUGIN_HR_ANALYTICS_SETUP = 'hr_analytics_setup';
    public const PLUGIN_EMAIL_ASSISTANT = 'email_assistant';
    public const PLUGIN_WHATSAPP_ASSISTANT = 'whatsapp_assistant';
    public const PLUGIN_SMS_CHANNEL = 'sms_channel';
    public const PLUGIN_VOICE_CALL_CENTER = 'voice_call_center';
    public const PLUGIN_CALENDAR_MEETINGS = 'calendar_meetings';
    public const PLUGIN_FINANCE = 'finance';
    public const PLUGIN_AI_API = 'ai_api';
    public const CATALOG_STATUS_VISIBLE = 'visible';
    public const CATALOG_STATUS_HIDDEN = 'hidden';
    public const CATALOG_STATUS_DEACTIVATED = 'deactivated';

    private const CONTRACT_COLUMNS = [
        'owner_workspace_id',
        'definition_source',
        'advice_domains_json',
        'context_schema_json',
        'task_templates_json',
        'boundary_policy',
        'routing_examples_json',
        'created_by_user_id',
    ];

    private static ?bool $contractColumnsReady = null;
    private static ?bool $overrideStatusColumnReady = null;
    private static bool $definitionsSynced = false;
    private static array $availableForWorkspaceCache = [];
    private static array $findForWorkspaceCache = [];
    private static ?array $catalogOverridesByKey = null;

    public function available(bool $includeUnavailable = false): array
    {
        return $this->availableForWorkspace(0, $includeUnavailable);
    }

    public function availableForWorkspace(int $workspaceId, bool $includeUnavailable = false): array
    {
        $cacheKey = $workspaceId . ':' . (int) $includeUnavailable;
        if (isset(self::$availableForWorkspaceCache[$cacheKey])) {
            return self::$availableForWorkspaceCache[$cacheKey];
        }

        if (!$this->tableReady()) {
            $definitions = array_values(array_map(
                fn(array $definition): array => $this->withDefaultCatalogStatus($definition),
                $this->definitions()
            ));
            $modules = $includeUnavailable ? $definitions : array_values(array_filter($definitions, fn(array $module): bool => $this->isCatalogVisible($module)));
            self::$availableForWorkspaceCache[$cacheKey] = $modules;
            return $modules;
        }

        $this->syncDefinitions();
        $select = $this->definitionSelectList();
        $where = $this->contractColumnsReady() && $workspaceId > 0
            ? "WHERE is_active = 1 AND (owner_workspace_id IS NULL OR owner_workspace_id = ?)"
            : "WHERE is_active = 1";
        $params = $this->contractColumnsReady() && $workspaceId > 0 ? [$workspaceId] : [];
        $orderBy = $this->contractColumnsReady()
            ? "module_type ASC, definition_source ASC, category ASC, label ASC"
            : "module_type ASC, category ASC, label ASC";
        $rows = Database::query(
            "SELECT {$select}
             FROM workspace_skill_definitions
             {$where}
             ORDER BY {$orderBy}",
            $params
        );

        $modules = array_values(array_map(fn(array $row): array => $this->applyOverride($this->normalizeRow($row)), $rows));
        if (!$includeUnavailable) {
            $modules = array_values(array_filter($modules, fn(array $module): bool => $this->isCatalogVisible($module)));
        }

        self::$availableForWorkspaceCache[$cacheKey] = $modules;
        return $modules;
    }

    public function find(string $key, bool $includeUnavailable = false): ?array
    {
        return $this->findForWorkspace($key, 0, $includeUnavailable);
    }

    public function findForWorkspace(string $key, int $workspaceId = 0, bool $includeUnavailable = false): ?array
    {
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return null;
        }
        $cacheKey = $key . ':' . $workspaceId . ':' . (int) $includeUnavailable;
        if (array_key_exists($cacheKey, self::$findForWorkspaceCache)) {
            return self::$findForWorkspaceCache[$cacheKey];
        }

        if (!$this->tableReady()) {
            $definition = isset($this->definitions()[$key]) ? $this->withDefaultCatalogStatus($this->definitions()[$key]) : null;
            $module = $definition !== null && ($includeUnavailable || $this->isCatalogVisible($definition)) ? $definition : null;
            self::$findForWorkspaceCache[$cacheKey] = $module;
            return $module;
        }

        $this->syncDefinitions();
        $select = $this->definitionSelectList();
        $where = "skill_key = ? AND is_active = 1";
        $params = [$key];
        if ($this->contractColumnsReady() && $workspaceId > 0) {
            $where .= " AND (owner_workspace_id IS NULL OR owner_workspace_id = ?)";
            $params[] = $workspaceId;
        }
        $row = Database::queryOne(
            "SELECT {$select}
             FROM workspace_skill_definitions
             WHERE {$where}
             LIMIT 1",
            $params
        );

        if (!$row) {
            self::$findForWorkspaceCache[$cacheKey] = null;
            return null;
        }

        $module = $this->applyOverride($this->normalizeRow($row));
        $module = $includeUnavailable || $this->isCatalogVisible($module) ? $module : null;
        self::$findForWorkspaceCache[$cacheKey] = $module;
        return $module;
    }

    public function createCustomSkill(int $workspaceId, int $userId, array $data): array
    {
        $this->assertCanManage($workspaceId);
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }
        if (!$this->tableReady() || !$this->contractColumnsReady()) {
            throw new \RuntimeException('Workspace skill contract columns are not installed.');
        }

        $label = $this->sanitizeString((string) ($data['label'] ?? ''), 160);
        if ($label === '') {
            throw new \InvalidArgumentException('Skill name is required.');
        }

        $key = $this->buildCustomSkillKey($workspaceId, $label);
        Database::execute(
            "INSERT INTO workspace_skill_definitions (
                owner_workspace_id, skill_key, definition_source, label, summary, category, module_type, version,
                capabilities_json, advice_domains_json, onboarding_fields_json, context_schema_json,
                task_templates_json, boundary_policy, routing_examples_json, settings_schema_json,
                plugin_metadata_json, ai_context_provider, navigation_json, permissions_json,
                created_by_user_id, is_active
             ) VALUES (?, ?, 'custom', ?, ?, ?, 'skill', '1.0.0', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'custom_skill', ?, ?, ?, 1)",
            [
                $workspaceId,
                $key,
                $label,
                $this->sanitizeString((string) ($data['summary'] ?? ''), 4000),
                $this->normalizeKey((string) ($data['category'] ?? 'custom')) ?: 'custom',
                json_encode(['ai_context' => true, 'custom_skill' => true, 'workspace_guidance' => true], JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeAdviceDomains((array) ($data['advice_domains'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->contextFieldKeys((array) ($data['context_fields'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeContextSchema((array) ($data['context_fields'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeTaskTemplates((array) ($data['task_templates'] ?? [])), JSON_UNESCAPED_SLASHES),
                $this->normalizeBoundaryPolicy((string) ($data['boundary_policy'] ?? 'strict')),
                json_encode($this->sanitizeList((array) ($data['routing_examples'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode(['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=' . rawurlencode($key)], JSON_UNESCAPED_SLASHES),
                json_encode($this->buildCustomMarketplaceProfile($data), JSON_UNESCAPED_SLASHES),
                json_encode(['label' => $label, 'url' => 'workspace_skills.php?module=' . rawurlencode($key)], JSON_UNESCAPED_SLASHES),
                json_encode(['workspace.skills.view'], JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
            ]
        );

        $this->clearRequestCaches();
        return $this->findForWorkspace($key, $workspaceId) ?? [];
    }

    public function updateCustomSkill(int $workspaceId, string $skillKey, int $userId, array $data): array
    {
        $this->assertCanManage($workspaceId);
        $skillKey = $this->normalizeKey($skillKey);
        $current = $this->findForWorkspace($skillKey, $workspaceId);
        if (!$current || (int) ($current['owner_workspace_id'] ?? 0) !== $workspaceId) {
            throw new \InvalidArgumentException('Custom workspace skill not found.');
        }

        $label = $this->sanitizeString((string) ($data['label'] ?? $current['label'] ?? ''), 160);
        if ($label === '') {
            throw new \InvalidArgumentException('Skill name is required.');
        }

        Database::execute(
            "UPDATE workspace_skill_definitions
             SET label = ?,
                 summary = ?,
                 category = ?,
                 advice_domains_json = ?,
                 onboarding_fields_json = ?,
                 context_schema_json = ?,
                 task_templates_json = ?,
                 boundary_policy = ?,
                 routing_examples_json = ?,
                 plugin_metadata_json = ?,
                 navigation_json = ?,
                 created_by_user_id = COALESCE(created_by_user_id, ?),
                 updated_at = NOW()
             WHERE owner_workspace_id = ? AND skill_key = ? AND definition_source IN ('custom','template_clone')",
            [
                $label,
                $this->sanitizeString((string) ($data['summary'] ?? ''), 4000),
                $this->normalizeKey((string) ($data['category'] ?? 'custom')) ?: 'custom',
                json_encode($this->normalizeAdviceDomains((array) ($data['advice_domains'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->contextFieldKeys((array) ($data['context_fields'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeContextSchema((array) ($data['context_fields'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeTaskTemplates((array) ($data['task_templates'] ?? [])), JSON_UNESCAPED_SLASHES),
                $this->normalizeBoundaryPolicy((string) ($data['boundary_policy'] ?? 'strict')),
                json_encode($this->sanitizeList((array) ($data['routing_examples'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->buildCustomMarketplaceProfile($data), JSON_UNESCAPED_SLASHES),
                json_encode(['label' => $label, 'url' => 'workspace_skills.php?module=' . rawurlencode($skillKey)], JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
                $workspaceId,
                $skillKey,
            ]
        );

        $this->clearRequestCaches();
        return $this->findForWorkspace($skillKey, $workspaceId) ?? $current;
    }

    public function archiveCustomSkill(int $workspaceId, string $skillKey): void
    {
        $this->assertCanManage($workspaceId);
        $skillKey = $this->normalizeKey($skillKey);
        if ($workspaceId <= 0 || $skillKey === '' || !$this->contractColumnsReady()) {
            return;
        }

        Database::execute(
            "UPDATE workspace_skill_definitions
             SET is_active = 0, updated_at = NOW()
             WHERE owner_workspace_id = ? AND skill_key = ? AND definition_source IN ('custom','template_clone')",
            [$workspaceId, $skillKey]
        );
        $this->clearRequestCaches();
    }

    public function saveCatalogOverride(string $skillKey, array $data, int $userId = 0): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            throw new \InvalidArgumentException('Marketplace module key is required.');
        }
        if (!$this->tableReady() || !$this->overrideTableReady()) {
            throw new \RuntimeException('Marketplace catalog override table is not installed.');
        }

        $current = $this->find($skillKey, true);
        if ($current === null) {
            throw new \InvalidArgumentException('Marketplace module not found.');
        }

        $profile = (array) ($current['plugin_metadata']['marketplace_profile'] ?? []);
        foreach ([
            'overview_headline',
            'overview_intro',
            'overview_brief_image_url',
            'overview_brief_image_alt',
            'overview_deep_dive_image_url',
            'overview_deep_dive_image_alt',
        ] as $legacyField) {
            unset($profile[$legacyField]);
        }
        foreach (['thumbnail_url', 'thumbnail_alt', 'banner_url', 'banner_alt', 'explainer_video_url', 'explainer_orientation', 'setup_video_url', 'setup_video_uploaded_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $profile[$field] = $this->sanitizeString((string) $data[$field], 500);
            }
        }
        foreach (['pitch'] as $field) {
            if (array_key_exists($field, $data)) {
                $profile[$field] = $this->sanitizeString((string) $data[$field], 4000);
            }
        }
        foreach (['overview_brief', 'overview_deep_dive'] as $richPrefix) {
            $contentField = $richPrefix . '_content';
            $formatField = $richPrefix . '_format';
            if (array_key_exists($contentField, $data) || array_key_exists($formatField, $data)) {
                $format = $this->normalizeRichContentFormat((string) ($data[$formatField] ?? $profile[$formatField] ?? 'text'));
                $content = (string) ($data[$contentField] ?? $profile[$contentField] ?? '');
                $profile[$formatField] = $format;
                $profile[$contentField] = $format === 'html'
                    ? $this->sanitizeMarketplaceHtml($content)
                    : $this->sanitizePlainText($content, 20000);
            }
        }
        foreach (['recommendations', 'prerequisites', 'setup_guide', 'benefit_bullets', 'use_case_bullets', 'outcome_bullets', 'how_it_works_bullets'] as $field) {
            if (array_key_exists($field, $data)) {
                $profile[$field] = $this->sanitizeList((array) $data[$field]);
            }
        }

        $columns = [
            'skill_key',
            'label',
            'summary',
            'thumbnail_url',
            'thumbnail_alt',
            'banner_url',
            'banner_alt',
            'marketplace_profile_json',
            'updated_by_user_id',
        ];
        $values = [
            $skillKey,
            $this->sanitizeString((string) ($data['label'] ?? $current['label'] ?? ''), 160),
            $this->sanitizeString((string) ($data['summary'] ?? $current['summary'] ?? ''), 4000),
            $this->sanitizeString((string) ($profile['thumbnail_url'] ?? ''), 500),
            $this->sanitizeString((string) ($profile['thumbnail_alt'] ?? ''), 255),
            $this->sanitizeString((string) ($profile['banner_url'] ?? ''), 500),
            $this->sanitizeString((string) ($profile['banner_alt'] ?? ''), 255),
            json_encode($profile, JSON_UNESCAPED_SLASHES),
            $userId > 0 ? $userId : null,
        ];
        $updates = [
            'label = VALUES(label)',
            'summary = VALUES(summary)',
            'thumbnail_url = VALUES(thumbnail_url)',
            'thumbnail_alt = VALUES(thumbnail_alt)',
            'banner_url = VALUES(banner_url)',
            'banner_alt = VALUES(banner_alt)',
            'marketplace_profile_json = VALUES(marketplace_profile_json)',
            'updated_by_user_id = VALUES(updated_by_user_id)',
            'updated_at = NOW()',
        ];
        if ($this->overrideStatusColumnReady() && array_key_exists('catalog_status', $data)) {
            $columns[] = 'catalog_status';
            $values[] = $this->normalizeCatalogStatus((string) $data['catalog_status']);
            $updates[] = 'catalog_status = VALUES(catalog_status)';
        }

        Database::execute(
            "INSERT INTO workspace_skill_catalog_overrides (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")
             ON DUPLICATE KEY UPDATE " . implode(', ', $updates),
            $values
        );

        $this->clearRequestCaches();
        return $this->find($skillKey, true) ?? $current;
    }

    public function setCatalogStatus(string $skillKey, string $status, int $userId = 0): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            throw new \InvalidArgumentException('Marketplace module key is required.');
        }
        if (!$this->tableReady() || !$this->overrideTableReady() || !$this->overrideStatusColumnReady()) {
            throw new \RuntimeException('Marketplace catalog status controls are not installed.');
        }

        $current = $this->find($skillKey, true);
        if ($current === null || !empty($current['is_custom'])) {
            throw new \InvalidArgumentException('Platform Marketplace module not found.');
        }

        $status = $this->normalizeCatalogStatus($status);
        Database::execute(
            "INSERT INTO workspace_skill_catalog_overrides (skill_key, catalog_status, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                catalog_status = VALUES(catalog_status),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [$skillKey, $status, $userId > 0 ? $userId : null]
        );

        $this->clearRequestCaches();
        return $this->find($skillKey, true) ?? $current;
    }

    public function resetCatalogOverride(string $skillKey): void
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '' || !$this->overrideTableReady()) {
            return;
        }

        Database::execute(
            "DELETE FROM workspace_skill_catalog_overrides WHERE skill_key = ?",
            [$skillKey]
        );
        $this->clearRequestCaches();
    }

    public function recommendedForOnboarding(): array
    {
        return array_values(array_filter(
            $this->available(),
            static fn(array $skill): bool => in_array((string) ($skill['key'] ?? ''), [
                self::SKILL_LEAN_CANVAS,
                self::PLUGIN_MARKETING_PRO,
            ], true)
        ));
    }

    public function tableReady(): bool
    {
        return Database::tableExists('workspace_skill_definitions');
    }

    public function overrideTableReady(): bool
    {
        return Database::tableExists('workspace_skill_catalog_overrides');
    }

    public function overrideStatusColumnReady(): bool
    {
        if (self::$overrideStatusColumnReady !== null) {
            return self::$overrideStatusColumnReady;
        }

        try {
            self::$overrideStatusColumnReady = $this->overrideTableReady()
                && Database::columnExists('workspace_skill_catalog_overrides', 'catalog_status');
            return self::$overrideStatusColumnReady;
        } catch (\Throwable $e) {
            self::$overrideStatusColumnReady = false;
            return self::$overrideStatusColumnReady;
        }
    }

    public function catalogStatusFor(string $skillKey): string
    {
        $module = $this->find($skillKey, true);
        return $this->normalizeCatalogStatus((string) ($module['catalog_status'] ?? self::CATALOG_STATUS_VISIBLE));
    }

    public function isGloballyHidden(string $skillKey): bool
    {
        return $this->catalogStatusFor($skillKey) === self::CATALOG_STATUS_HIDDEN;
    }

    public function isGloballyDeactivated(string $skillKey): bool
    {
        return $this->catalogStatusFor($skillKey) === self::CATALOG_STATUS_DEACTIVATED;
    }

    public function canInstallForWorkspace(string $skillKey, int $workspaceId): bool
    {
        return $this->findForWorkspace($skillKey, $workspaceId) !== null;
    }

    private function assertCanManage(int $workspaceId): void
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }

        $user = Auth::user();
        if (Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user)) {
            return;
        }

        throw new \RuntimeException('Your access profile does not allow workspace skill management.');
    }

    public function contractColumnsReady(): bool
    {
        if (self::$contractColumnsReady !== null) {
            return self::$contractColumnsReady;
        }

        try {
            foreach (self::CONTRACT_COLUMNS as $column) {
                if (!Database::columnExists('workspace_skill_definitions', $column)) {
                    self::$contractColumnsReady = false;
                    return self::$contractColumnsReady;
                }
            }

            self::$contractColumnsReady = true;
            return self::$contractColumnsReady;
        } catch (\Throwable $e) {
            self::$contractColumnsReady = false;
            return self::$contractColumnsReady;
        }
    }

    public function syncDefinitions(): void
    {
        if (self::$definitionsSynced) {
            return;
        }

        if (!$this->tableReady()) {
            return;
        }

        foreach ($this->definitions() as $definition) {
            if (!$this->contractColumnsReady()) {
                Database::execute(
                    "INSERT INTO workspace_skill_definitions (
                        skill_key, label, summary, category, module_type, version, capabilities_json,
                        onboarding_fields_json, settings_schema_json, plugin_metadata_json, ai_context_provider,
                        navigation_json, permissions_json, is_active
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        label = VALUES(label),
                        summary = VALUES(summary),
                        category = VALUES(category),
                        module_type = VALUES(module_type),
                        version = VALUES(version),
                        capabilities_json = VALUES(capabilities_json),
                        onboarding_fields_json = VALUES(onboarding_fields_json),
                        settings_schema_json = VALUES(settings_schema_json),
                        plugin_metadata_json = VALUES(plugin_metadata_json),
                        ai_context_provider = VALUES(ai_context_provider),
                        navigation_json = VALUES(navigation_json),
                        permissions_json = VALUES(permissions_json),
                        is_active = 1,
                        updated_at = NOW()",
                    [
                        $definition['key'],
                        $definition['label'],
                        $definition['summary'],
                        $definition['category'],
                        $definition['module_type'],
                        $definition['version'],
                        json_encode($definition['capabilities'], JSON_UNESCAPED_SLASHES),
                        json_encode($definition['onboarding_fields'], JSON_UNESCAPED_SLASHES),
                        json_encode($definition['settings_schema'], JSON_UNESCAPED_SLASHES),
                        json_encode($definition['plugin_metadata'], JSON_UNESCAPED_SLASHES),
                        $definition['ai_context_provider'],
                        json_encode($definition['navigation'], JSON_UNESCAPED_SLASHES),
                        json_encode($definition['permissions'], JSON_UNESCAPED_SLASHES),
                    ]
                );
                continue;
            }

            Database::execute(
                "INSERT INTO workspace_skill_definitions (
                    owner_workspace_id, skill_key, definition_source, label, summary, category, module_type,
                    version, capabilities_json, advice_domains_json, onboarding_fields_json, context_schema_json,
                    task_templates_json, boundary_policy, routing_examples_json, settings_schema_json,
                    plugin_metadata_json, ai_context_provider, navigation_json, permissions_json,
                    created_by_user_id, is_active
                 ) VALUES (NULL, ?, 'platform', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1)
                 ON DUPLICATE KEY UPDATE
                    owner_workspace_id = NULL,
                    definition_source = 'platform',
                    label = VALUES(label),
                    summary = VALUES(summary),
                    category = VALUES(category),
                    module_type = VALUES(module_type),
                    version = VALUES(version),
                    capabilities_json = VALUES(capabilities_json),
                    advice_domains_json = VALUES(advice_domains_json),
                    onboarding_fields_json = VALUES(onboarding_fields_json),
                    context_schema_json = VALUES(context_schema_json),
                    task_templates_json = VALUES(task_templates_json),
                    boundary_policy = VALUES(boundary_policy),
                    routing_examples_json = VALUES(routing_examples_json),
                    settings_schema_json = VALUES(settings_schema_json),
                    plugin_metadata_json = VALUES(plugin_metadata_json),
                    ai_context_provider = VALUES(ai_context_provider),
                    navigation_json = VALUES(navigation_json),
                    permissions_json = VALUES(permissions_json),
                    is_active = 1,
                    updated_at = NOW()",
                [
                    $definition['key'],
                    $definition['label'],
                    $definition['summary'],
                    $definition['category'],
                    $definition['module_type'],
                    $definition['version'],
                    json_encode($definition['capabilities'], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['advice_domains'] ?? [], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['onboarding_fields'], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['context_schema'] ?? [], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['task_templates'] ?? [], JSON_UNESCAPED_SLASHES),
                    $definition['boundary_policy'] ?? 'strict',
                    json_encode($definition['routing_examples'] ?? [], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['settings_schema'], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['plugin_metadata'], JSON_UNESCAPED_SLASHES),
                    $definition['ai_context_provider'],
                    json_encode($definition['navigation'], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['permissions'], JSON_UNESCAPED_SLASHES),
                ]
            );
        }

        self::$definitionsSynced = true;
        $this->clearRequestCaches();
    }

    public function definitions(): array
    {
        $definitions = [
            self::SKILL_LEAN_CANVAS => [
                'key' => self::SKILL_LEAN_CANVAS,
                'label' => 'Clarity Journey',
                'summary' => 'Guides teams from customer discovery through JTBD, value proposition, Lean Canvas, MVP, go-to-market, AARRR, and OKRs so AI guidance can reason from the full clarity path.',
                'category' => 'strategy',
                'module_type' => 'skill',
                'version' => '1.0.0',
                'capabilities' => [
                    'ai_context' => true,
                    'onboarding_fields' => true,
                    'strategy_guidance' => true,
                ],
                'advice_domains' => ['startup', 'business_model', 'positioning', 'pricing', 'validation', 'metrics'],
                'onboarding_fields' => [
                    'lean_problem',
                    'lean_customer_segments',
                    'lean_unique_value_proposition',
                    'lean_solution',
                    'lean_channels',
                    'lean_revenue_streams',
                    'lean_cost_structure',
                    'lean_key_metrics',
                    'lean_unfair_advantage',
                ],
                'context_schema' => [
                    'fields' => [
                        ['key' => 'lean_problem', 'label' => 'Problem', 'required' => true],
                        ['key' => 'lean_customer_segments', 'label' => 'Customer Segments', 'required' => true],
                        ['key' => 'lean_unique_value_proposition', 'label' => 'Unique Value Proposition', 'required' => true],
                        ['key' => 'lean_solution', 'label' => 'Solution', 'required' => true],
                        ['key' => 'lean_channels', 'label' => 'Channels', 'required' => true],
                        ['key' => 'lean_revenue_streams', 'label' => 'Revenue Streams', 'required' => true],
                        ['key' => 'lean_cost_structure', 'label' => 'Cost Structure', 'required' => true],
                        ['key' => 'lean_key_metrics', 'label' => 'Key Metrics', 'required' => true],
                        ['key' => 'lean_unfair_advantage', 'label' => 'Unfair Advantage', 'required' => true],
                    ],
                ],
                'task_templates' => [
                    ['title' => 'Complete Clarity Journey gaps', 'description' => 'Fill the highest-impact missing journey stages before asking for advanced growth advice.'],
                    ['title' => 'Run one validation experiment', 'description' => 'Choose one customer/problem assumption and define a CRM-visible validation step.'],
                ],
                'boundary_policy' => 'strict',
                'routing_examples' => ['How should I validate this startup idea?', 'What pricing model fits my customer segment?', 'Which key metric should I watch first?'],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'startup_journey.php'],
                'plugin_metadata' => [
                    'setup_url' => 'startup_journey.php',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/lean-canvas.webp',
                        'thumbnail_alt' => 'Clarity journey cards from customer discovery through growth metrics.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Set up Clarity Journey as the workspace strategy context skill. It prompts the owner to move from customer evidence into jobs, value, business model, MVP, go-to-market, growth metrics, and quarterly execution.',
                        'tags' => ['Setup required', 'AI guidance', 'Strategy'],
                        'benefit_bullets' => [
                            'Moves the owner from customer discovery to execution instead of stopping at a canvas.',
                            'Gives AI guidance a structured source of truth for validation, MVP, launch, growth, and OKRs.',
                            'Keeps legacy Lean Canvas context available while adding broader startup readiness.',
                        ],
                        'use_case_bullets' => [
                            'Clarifying a new startup, product, offer, or business line.',
                            'Testing assumptions before spending on an MVP or channel work.',
                            'Grounding recommendations in customer evidence, business model, optional finance context, and execution context.',
                        ],
                        'outcome_bullets' => [
                            'A complete eight-stage Clarity Journey saved to the workspace.',
                            'Clear readiness signals for missing stages and the next founder action.',
                            'Journey-backed prompts and task templates for strategic advice.',
                        ],
                        'how_it_works_bullets' => [
                            'The dedicated Clarity Journey hub saves each stage and keeps Marketplace readiness current.',
                            'Readiness is based on completing the eight core journey stages.',
                            'Ready journey context is exposed through installed skill contracts.',
                        ],
                        'recommendations' => [
                            'Install before asking for startup, validation, pricing, MVP, growth, or metric advice.',
                            'Use when onboarding answers feel scattered or incomplete.',
                            'Review after major customer, offer, revenue-model, MVP, or go-to-market changes.',
                            'Add Finance context when available for stronger runway, budget, and pricing guidance; it is recommended, not required.',
                        ],
                        'prerequisites' => [
                            'A clear target customer or at least one customer segment hypothesis.',
                            'Basic notes on your customer evidence, offer, MVP, channels, revenue streams, and key metrics.',
                            'Finance context is recommended when the workspace can share budget, pricing, runway, or accounting evidence without replacing an existing finance system.',
                        ],
                        'setup_guide' => [
                            'Install Clarity Journey from Marketplace.',
                            'Complete the eight core stages with concise working assumptions and evidence.',
                            'Optionally connect or summarize Finance context when practical so downstream guidance can use explicit money evidence.',
                            'Return to AI Coach or Clarity for validation experiments and next actions grounded in the journey.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'lean_canvas',
                'navigation' => ['label' => 'Clarity Journey', 'url' => 'startup_journey.php'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['business_model_context_incomplete', 'company_context_missing'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Finance context recommended'],
                'setup_steps' => ['Complete the Clarity Journey stages in the dedicated hub.', 'Review journey-backed guidance in AI Coach or Clarity.'],
                'coach_bucket' => 'foundation_gaps',
                'business_fit' => 'Founder-led teams that need sharper customer, offer, channel, and metric context.',
            ],
            self::SKILL_PROFESSIONAL_MARKETER => [
                'key' => self::SKILL_PROFESSIONAL_MARKETER,
                'label' => 'Marketing Assistants',
                'summary' => 'Adds marketing strategy context for sharper positioning, campaign ideas, content angles, and message review.',
                'category' => 'marketing',
                'module_type' => 'skill',
                'version' => '1.0.0',
                'capabilities' => [
                    'ai_context' => true,
                    'campaign_suggestions' => true,
                    'messaging_review' => true,
                ],
                'advice_domains' => ['marketing', 'campaigns', 'positioning', 'messaging', 'content', 'outreach'],
                'onboarding_fields' => [
                    'target_market_focus',
                    'segment_focus',
                    'outreach_posture',
                    'positioning_notes',
                ],
                'context_schema' => [
                    'fields' => [
                        ['key' => 'target_market_focus', 'label' => 'Target Market Focus', 'required' => true],
                        ['key' => 'ideal_customer_profile', 'label' => 'Ideal Customer Profile', 'required' => false],
                        ['key' => 'offer_angle', 'label' => 'Offer Angle', 'required' => false],
                        ['key' => 'segment_focus', 'label' => 'Segment Focus', 'required' => true],
                        ['key' => 'outreach_posture', 'label' => 'Outreach Posture', 'required' => true],
                        ['key' => 'positioning_notes', 'label' => 'Positioning Notes', 'required' => true],
                    ],
                ],
                'task_templates' => [
                    ['title' => 'Draft one campaign angle', 'description' => 'Create a message angle tied to the saved audience and positioning context.'],
                    ['title' => 'Review customer-facing copy', 'description' => 'Check one email, landing page, or WhatsApp message against the saved positioning notes.'],
                ],
                'boundary_policy' => 'strict',
                'routing_examples' => ['Create campaign ideas for this segment', 'Improve this positioning', 'Review my outreach message'],
                'settings_schema' => ['requires_configuration' => false],
                'plugin_metadata' => [
                    'legacy_hidden_from_marketplace' => true,
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/professional-marketer.webp',
                        'thumbnail_alt' => 'Marketing campaign toolkit with audience cards, charts, and launch assets.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Add a marketing strategist layer to Clarity so ideas become sharper campaigns instead of generic content. This skill helps the assistant reason about audience focus, outreach posture, positioning notes, and better message angles.',
                        'tags' => ['AI guidance', 'Marketing'],
                        'recommendations' => [
                            'Install before planning campaigns, launch emails, social posts, or nurture flows.',
                            'Use when your CRM has leads but the next message angle is unclear.',
                            'Pair with Lean Canvas for stronger positioning and customer-language review.',
                        ],
                        'prerequisites' => [
                            'A target market focus or at least one priority segment.',
                            'Positioning notes, offer claims, or examples of messages you want improved.',
                        ],
                        'setup_guide' => [
                            'Add target market and segment focus in onboarding or strategy profile fields.',
                            'Capture outreach posture and positioning notes.',
                            'Ask Clarity to critique a campaign, draft a sequence, or recommend the next channel test.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'professional_marketer',
                'navigation' => ['label' => 'Marketing Assistants', 'url' => 'marketing_assistants.php'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['marketing_context_incomplete', 'channel_strategy_needed'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['AI Coach ready'],
                'access_requirements' => [[
                    'requires_skill' => self::SKILL_AI_COACH,
                    'requires_readiness' => true,
                    'label' => 'AI Coach ready',
                    'message' => 'Complete AI Coach before installing Marketing Assistants.',
                    'why' => 'Marketing Assistants rely on the matured coaching context so campaigns inherit the workspace strategy, risks, and next-action memory.',
                ]],
                'setup_steps' => ['Add target market, segment, outreach posture, and positioning notes.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Businesses that need campaign, positioning, and message-review guidance.',
            ],
            self::PLUGIN_SOCIAL_MEDIA => [
                'key' => self::PLUGIN_SOCIAL_MEDIA,
                'label' => 'Social Media',
                'summary' => 'Connects Meta and LinkedIn, creates channel variants, controls approvals, schedules live publishing, and measures social performance.',
                'category' => 'marketing',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'runtime_during_setup' => true,
                    'social_connectors' => true,
                    'content_creation' => true,
                    'content_calendar' => true,
                    'social_analytics' => true,
                    'social_ads' => true,
                    'ai_context' => true,
                ],
                'advice_domains' => ['social', 'content', 'distribution', 'ads', 'campaigns'],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=social_media#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'marketing_social_media',
                    'runtime_provider' => 'Marketing',
                    'setup_url' => 'workspace_skills.php?module=social_media#setup',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/professional-marketer.webp',
                        'thumbnail_alt' => 'Social media command board with posts, connectors, and ad readiness cards.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Plan, approve, schedule, publish, and measure Facebook, Instagram, and LinkedIn content from one controlled CRM workspace.',
                        'tags' => ['Marketing', 'Social', 'Content'],
                        'recommendations' => [
                            'Install when campaigns need controlled Facebook, Instagram, or LinkedIn publishing.',
                            'Use when content needs approvals, scheduling, retries, channel variants, or performance evidence.',
                            'Pair with Design when posts need landing pages, forms, or creative assets.',
                        ],
                        'prerequisites' => [
                            'Marketing read/write access.',
                            'Meta or LinkedIn developer credentials for live account connections.',
                        ],
                        'setup_guide' => [
                            'Install Social Media from Marketplace.',
                            'Save the brand voice, audience, claims, approval policy, and publishing timezone.',
                            'Connect a Facebook Page, Instagram Professional account, or LinkedIn Company Page.',
                            'Open Social Media to create, approve, schedule, and measure posts.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'social_media',
                'navigation' => ['label' => 'Social Media', 'url' => 'social_media.php'],
                'permissions' => ['marketing.read'],
                'recommended_when' => ['channel_strategy_needed', 'content_distribution_needed'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Marketing permissions'],
                'setup_steps' => ['Save the brand and publishing policy.', 'Connect at least one supported social account.', 'Run the queue and metrics workers on cron.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Teams that publish social content, package posts for channels, or prepare social ads.',
            ],
            self::PLUGIN_DESIGN => [
                'key' => self::PLUGIN_DESIGN,
                'label' => 'Design',
                'summary' => 'Owns forms, landing pages, email signatures, page previews, and creative design workflows.',
                'category' => 'marketing',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'runtime_during_setup' => true,
                    'forms' => true,
                    'landing_pages' => true,
                    'email_signatures' => true,
                    'creative_assets' => true,
                    'conversion_design' => true,
                    'ai_context' => true,
                ],
                'advice_domains' => ['design', 'forms', 'landing_pages', 'email_signatures', 'conversion'],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=design#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'marketing_design',
                    'runtime_provider' => 'Design',
                    'setup_url' => 'workspace_skills.php?module=design#setup',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/professional-marketer.webp',
                        'thumbnail_alt' => 'Landing page, form, and email signature design workspace with creative asset checks.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Build the conversion surfaces and brand touchpoints around a campaign without carrying the full marketing management toolkit. Design owns forms, landing pages, email signatures, page previews, and creative readiness.',
                        'tags' => ['Marketing', 'Design', 'Landing pages'],
                        'recommendations' => [
                            'Install before launching lead-capture pages, booking pages, or campaign forms.',
                            'Use when content needs a landing page, branded signature, page preview, or creative asset review.',
                            'Pair with Social Media when social campaigns drive traffic to a page.',
                        ],
                        'prerequisites' => [
                            'Marketing read/write access.',
                            'A campaign, offer, or lead-capture goal.',
                        ],
                        'setup_guide' => [
                            'Install Design from Marketplace.',
                            'Open Design and create a landing page, lead form, or email signature.',
                            'Review page media, CTA, SEO, and conversion readiness before publishing.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'design',
                'navigation' => ['label' => 'Design', 'url' => 'design.php'],
                'permissions' => ['marketing.read'],
                'recommended_when' => ['landing_page_needed', 'form_capture_needed', 'creative_assets_needed'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Marketing permissions'],
                'setup_steps' => ['Open the Design workspace.', 'Create or review a landing page or lead form.', 'Complete creative readiness checks.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Teams that need forms, landing pages, branded email signatures, page previews, or campaign creative surfaces.',
            ],
            self::PLUGIN_MARKETING_PRO => [
                'key' => self::PLUGIN_MARKETING_PRO,
                'label' => 'Campaign Manager',
                'summary' => 'Owns marketing management, campaign setup, heavy analytics, attribution, orchestration, and strategic operating context.',
                'category' => 'marketing',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'runtime_during_setup' => true,
                    'ai_context' => true,
                    'campaign_management' => true,
                    'marketing_setup' => true,
                    'heavy_analytics' => true,
                    'attribution' => true,
                    'live_orchestration' => true,
                    'strategy_context' => true,
                ],
                'advice_domains' => ['marketing', 'campaigns', 'positioning', 'messaging', 'analytics', 'attribution', 'management'],
                'onboarding_fields' => [
                    'target_market_focus',
                    'segment_focus',
                    'outreach_posture',
                    'positioning_notes',
                ],
                'context_schema' => [
                    'fields' => [
                        ['key' => 'target_market_focus', 'label' => 'Target Market Focus', 'required' => true],
                        ['key' => 'ideal_customer_profile', 'label' => 'Ideal Customer Profile', 'required' => false],
                        ['key' => 'offer_angle', 'label' => 'Offer Angle', 'required' => false],
                        ['key' => 'segment_focus', 'label' => 'Segment Focus', 'required' => true],
                        ['key' => 'outreach_posture', 'label' => 'Outreach Posture', 'required' => true],
                        ['key' => 'positioning_notes', 'label' => 'Positioning Notes', 'required' => true],
                    ],
                ],
                'task_templates' => [
                    ['title' => 'Review campaign management setup', 'description' => 'Confirm campaign goals, audience, launch readiness, attribution, and next operating action.'],
                    ['title' => 'Run a marketing performance review', 'description' => 'Use campaign, content, attribution, and CRM evidence to decide the next management step.'],
                ],
                'boundary_policy' => 'strict',
                'routing_examples' => ['Plan my campaign management system', 'Review campaign performance', 'Improve our marketing attribution'],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=marketing_pro#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'marketing_pro',
                    'runtime_provider' => 'Marketing',
                    'setup_url' => 'workspace_skills.php?module=marketing_pro#setup',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/professional-marketer.webp',
                        'thumbnail_alt' => 'Marketing management dashboard with analytics, campaign setup, and attribution signals.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Manage strategy context, campaigns, launch readiness, analytics, attribution, and live orchestration in one workspace while Social Media and Design handle focused execution.',
                        'tags' => ['AI guidance', 'Marketing', 'Analytics'],
                        'recommendations' => [
                            'Install before managing multi-step campaigns, launch controls, analytics, or attribution.',
                            'Use when you need the Marketing command center as an operating system, not just posts or pages.',
                            'Pair with Social Media and Design for the complete campaign execution loop.',
                        ],
                        'prerequisites' => [
                            'Marketing permissions for the workspace.',
                            'A target market focus or at least one priority segment.',
                            'Positioning notes, offer claims, or campaign management goals.',
                            'AI Coach is recommended when Marketing Assistants guidance is needed.',
                        ],
                        'setup_guide' => [
                            'Install Campaign Manager from Marketplace.',
                            'Add target market, segment focus, outreach posture, and positioning notes.',
                            'Review onboarding, campaign readiness, analytics, attribution, and launch controls from Marketing.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'marketing_pro',
                'navigation' => ['label' => 'Campaign Manager', 'url' => 'marketing.php'],
                'permissions' => ['marketing.read'],
                'recommended_when' => ['marketing_context_incomplete', 'campaign_management_needed', 'marketing_analytics_needed'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Marketing permissions', 'AI Coach recommended for Marketing Assistants guidance'],
                'setup_steps' => ['Add target market, segment, outreach posture, and positioning notes.', 'Review Marketing onboarding and analytics readiness.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Businesses that need marketing strategy, campaign management, attribution, and analytics.',
            ],
            self::PLUGIN_EMAIL => [
                'key' => self::PLUGIN_EMAIL,
                'label' => 'Email',
                'summary' => 'Owns workspace email channel setup, SMTP/IMAP readiness, email sending, templates, signatures, and email activity surfaces.',
                'category' => 'communication',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'required_core' => true,
                    'protected_install' => true,
                    'email_channel_setup' => true,
                    'email_sending' => true,
                    'email_templates' => true,
                    'email_signatures' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'email',
                    'runtime_provider' => 'EmailIntegrationService',
                    'setup_url' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
                    'required_core' => true,
                    'protected_install' => true,
                    'job_hints' => ['cli/email_worker.php', 'cli/fetch_incoming_emails.php', 'cli/scheduled_email_worker.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/email-assistant.webp',
                        'thumbnail_alt' => 'Email channel setup with inbox, delivery, template, and signature readiness.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Set up the workspace email channel as its own module. Email owns outbound SMTP, optional inbound IMAP, templates, signatures, queued delivery, and the email runtime pages without mixing in assistant behavior.',
                        'tags' => ['Connect channels', 'Communication', 'Email'],
                        'recommendations' => [
                            'Complete this when customer communication happens through email.',
                            'Use outreach and nurture identities when different senders are needed.',
                            'Pair with Email Assistant only after the channel is ready.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'SMTP or supported OAuth details for sending.',
                            'Optional IMAP details for incoming email capture.',
                        ],
                        'setup_guide' => [
                            'Review Email setup in Marketplace.',
                            'Configure outreach or nurture email credentials.',
                            'Run a readiness check before opening live email workflows.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'email',
                'navigation' => ['label' => 'Email', 'url' => 'workspace_skills.php?module=email'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['selected_channel_email', 'email_setup_incomplete'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['SMTP or OAuth setup', 'optional IMAP setup'],
                'setup_steps' => ['Configure outreach or nurture email and run an email readiness check.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Email-led teams that need reliable sending, inbox capture, templates, and signatures.',
            ],
            self::PLUGIN_WHATSAPP => [
                'key' => self::PLUGIN_WHATSAPP,
                'label' => 'WhatsApp',
                'summary' => 'Owns WhatsApp Business setup, webhook/account readiness, message sending, queueing, and WhatsApp activity surfaces.',
                'category' => 'communication',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'required_core' => true,
                    'protected_install' => true,
                    'whatsapp_channel_setup' => true,
                    'whatsapp_sending' => true,
                    'whatsapp_queue' => true,
                    'webhook_readiness' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'settings.php?tab=whatsapp&setup_module=whatsapp&setup_tab=manual'],
                'plugin_metadata' => [
                    'readiness_provider' => 'whatsapp',
                    'runtime_provider' => 'WorkspaceConnectService, WhatsAppService',
                    'setup_url' => 'workspace_skills.php?module=whatsapp&setup_tab=manual#setup',
                    'required_core' => true,
                    'protected_install' => true,
                    'job_hints' => ['api/webhooks/whatsapp.php', 'cli/whatsapp_worker.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/whatsapp-assistant.webp',
                        'thumbnail_alt' => 'WhatsApp Business channel setup with number, webhook, queue, and delivery readiness.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'portrait',
                        'pitch' => 'Set up WhatsApp as its own CRM channel. Manual Cloud API credentials come first, with a workspace webhook URL, number registration or On-Prem migration, and optional Meta embedded signup when available.',
                        'tags' => ['Connect channels', 'Communication', 'WhatsApp'],
                        'recommendations' => [
                            'Complete this when customers respond fastest on WhatsApp.',
                            'Use it for direct messages, bulk WhatsApp, and channel health checks.',
                            'Pair with WhatsApp Assistant only after the WhatsApp Business channel is ready.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'A WhatsApp Business account and phone number.',
                            'Phone number ID and access token for the workspace number.',
                            'Workspace webhook URL and verify token generated from manual setup.',
                        ],
                        'setup_guide' => [
                            'Save the workspace phone number ID, display number, WABA/business details, and access token.',
                            'Copy this workspace webhook callback and verify token into Meta.',
                            'Register pending Cloud API numbers or migrate On-Prem numbers from the Migration tab when needed.',
                            'Use embedded signup only when Meta signup is configured.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'whatsapp',
                'navigation' => ['label' => 'WhatsApp', 'url' => 'workspace_skills.php?module=whatsapp'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['selected_channel_whatsapp', 'whatsapp_setup_incomplete'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['WhatsApp Business number', 'phone number ID', 'access token', 'webhook verify token'],
                'setup_steps' => ['Save manual WhatsApp credentials, configure webhook readiness, and run a WhatsApp readiness check.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'WhatsApp-led teams that need customer messaging, queueing, and delivery health.',
            ],
            self::PLUGIN_HR_ANALYTICS_SETUP => [
                'key' => self::PLUGIN_HR_ANALYTICS_SETUP,
                'label' => 'Organization Intelligence',
                'summary' => 'Required people and responsibility intelligence for business areas, workload, coaching signals, and optional HR team structure.',
                'category' => 'people',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'required_core' => true,
                    'protected_install' => true,
                    'hr_analytics_gate' => true,
                    'hr_settings_setup' => true,
                    'function_setup' => true,
                    'function_assignment_setup' => true,
                    'department_setup' => true,
                    'department_assignment_setup' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=hr_analytics_setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'hr_analytics_setup',
                    'runtime_provider' => 'WorkspaceHRAnalyticsGateService',
                    'setup_url' => 'organization_intelligence_setup.php',
                    'settings_url' => 'workspace_skills.php?module=hr_analytics_setup#setup',
                    'required_core' => true,
                    'protected_install' => true,
                    'job_hints' => ['Review business areas and optional team structure before opening the dashboard.'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'Organization Intelligence with guided business area and team structure checks.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Help owners understand responsibility coverage, workload pressure, coaching signals, and team structure from one guided Organization Intelligence flow.',
                        'tags' => ['People intelligence', 'Responsibilities', 'Required core'],
                        'benefit_bullets' => [
                            'Gives owners one guided setup path for business areas and optional team structure.',
                            'Prevents analytics from opening before the workspace has enough business context.',
                            'Keeps formal departments optional until HR-style reporting is useful.',
                        ],
                        'use_case_bullets' => [
                            'Launching Organization Intelligence for a workspace.',
                            'Checking whether missing business areas block the dashboard.',
                            'Helping owners interpret workload, coaching, and team signals responsibly.',
                        ],
                        'outcome_bullets' => [
                            'Default Organization Intelligence scoring is available.',
                            'At least one active business area exists.',
                            'Owner responsibility coverage is maintained from account and invite flows.',
                        ],
                        'how_it_works_bullets' => [
                            'Organization Intelligence is always installed and cannot be removed.',
                            'The runtime dashboard unlocks after the practical setup checklist is complete.',
                            'Owners continue into the guided setup page while eligible users see setup-required guidance.',
                        ],
                        'recommendations' => [
                            'Complete this before using Organization Intelligence to coach people or compare teams.',
                            'Create business areas that match who owns what today.',
                            'Keep responsibilities managed from user creation, invites, and owner access.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'At least one business area for the workspace.',
                        ],
                        'setup_guide' => [
                            'Continue into the guided Organization Intelligence setup page.',
                            'Create or confirm active business areas.',
                            'Add departments only when formal HR structure is useful.',
                            'Open Organization Intelligence after required setup passes.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'hr_analytics_setup',
                'navigation' => ['label' => 'Organization Intelligence', 'url' => 'workspace_skills.php?module=hr_analytics_setup'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['hr_analytics_setup_incomplete', 'business_areas_missing'],
                'not_recommended_when' => ['hr_analytics_ready'],
                'dependencies' => ['Default Organization Intelligence scoring profile', 'active business areas'],
                'setup_steps' => ['Continue guided setup, confirm business areas, and open Organization Intelligence when ready.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Every workspace that uses Organization Intelligence, business responsibility coverage, department performance, or manager coaching insights.',
            ],
            self::PLUGIN_EMAIL_ASSISTANT => [
                'key' => self::PLUGIN_EMAIL_ASSISTANT,
                'label' => 'Email Assistant',
                'summary' => 'Installs the workspace email assistant plugin for inbound instructions, outbound drafts, customer replies, and daily digests.',
                'category' => 'assistant',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'email_assistant' => true,
                    'ai_context' => true,
                    'background_jobs' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'email_assistant',
                    'runtime_provider' => 'WorkspaceAssistantConfigService',
                    'setup_url' => 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup',
                    'job_hints' => ['cli/daily_digest_worker.php', 'assistant IMAP fetch worker'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/email-assistant.webp',
                        'thumbnail_alt' => 'Email assistant automation with envelopes, reply cards, and digest indicators.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Turn email from a manual pile into an assisted operating channel. Email Assistant can support customer replies, inbound instructions, outbound draft help, and digest workflows once the workspace email identity is configured.',
                        'tags' => ['Connect channels', 'AI guidance', 'Sales follow-up', 'Communication'],
                        'recommendations' => [
                            'Install when most customer work arrives through email.',
                            'Use for safer reply drafting, daily summaries, and operational inbox follow-through.',
                            'Connect inbound IMAP later if you want instruction intake, not only outbound drafts.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'Assistant sender identity with SMTP or supported OAuth details.',
                            'Optional IMAP credentials for inbound instruction workflows.',
                        ],
                        'setup_guide' => [
                            'Install the plugin from this marketplace card.',
                            'Open marketplace setup and enable Email Assistant.',
                            'Configure sender identity, SMTP/OAuth, and optional IMAP.',
                            'Send a test digest or draft before relying on live customer workflows.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'email_assistant',
                'navigation' => ['label' => 'Email Assistant', 'url' => 'workspace_skills.php?module=email_assistant'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['selected_channel_email', 'main_email_available', 'assistant_email_incomplete'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Email module ready', 'assistant email SMTP or OAuth', 'optional IMAP for inbound instructions'],
                'access_requirements' => [[
                    'requires_skill' => self::PLUGIN_EMAIL,
                    'requires_readiness' => true,
                    'label' => 'Email ready',
                    'message' => 'Complete Email setup before installing Email Assistant.',
                    'why' => 'Email Assistant should only open after the workspace email channel is ready.',
                ]],
                'setup_steps' => ['Connect Assistant Gmail or configure assistant SMTP/IMAP.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Email-led businesses that need customer replies, inbound instructions, or daily digests.',
            ],
            self::PLUGIN_WHATSAPP_ASSISTANT => [
                'key' => self::PLUGIN_WHATSAPP_ASSISTANT,
                'label' => 'WhatsApp Assistant',
                'summary' => 'Installs the workspace WhatsApp assistant plugin for internal WhatsApp instructions, short digests, and session keepalive support.',
                'category' => 'assistant',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'whatsapp_assistant' => true,
                    'ai_context' => true,
                    'background_jobs' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'settings.php?tab=whatsapp&setup_module=whatsapp_assistant&setup_tab=identity'],
                'plugin_metadata' => [
                    'readiness_provider' => 'whatsapp_assistant',
                    'runtime_provider' => 'WorkspaceAssistantConfigService',
                    'setup_url' => 'workspace_skills.php?module=whatsapp_assistant#setup',
                    'job_hints' => ['cli/whatsapp_assistant_digest_worker.php', 'cli/whatsapp_assistant_session_worker.php', 'api/webhooks/whatsapp.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/whatsapp-assistant.webp',
                        'thumbnail_alt' => 'Business messaging assistant with phone chat bubbles, webhook nodes, and session indicators.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'portrait',
                        'pitch' => 'Bring assistant operations into the channel your team already watches. WhatsApp Assistant supports internal instructions, short digests, and session continuity when WhatsApp Business and webhook settings are ready.',
                        'tags' => ['Connect channels', 'AI guidance', 'Sales follow-up', 'Communication'],
                        'recommendations' => [
                            'Install when WhatsApp is the fastest operational channel for your business.',
                            'Use for short instruction loops, reminders, digests, and customer-thread awareness.',
                            'Pair with channel health checks so setup blockers are visible before launch.',
                        ],
                        'prerequisites' => [
                            'Connected WhatsApp Business account and phone number.',
                            'Assistant phone number ID, access token, and webhook route.',
                            'Approved reopen/session template if session keepalive is needed.',
                        ],
                        'setup_guide' => [
                            'Install the plugin from this marketplace card.',
                            'Open marketplace setup and configure WhatsApp Assistant.',
                            'Verify outbound and inbound webhook readiness.',
                            'Run a controlled test message before enabling team workflows.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'whatsapp_assistant',
                'navigation' => ['label' => 'WhatsApp Assistant', 'url' => 'workspace_skills.php?module=whatsapp_assistant'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['selected_channel_whatsapp', 'whatsapp_connected', 'whatsapp_setup_incomplete'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['WhatsApp module ready', 'WhatsApp Business number', 'assistant phone number id', 'access token', 'webhook'],
                'access_requirements' => [[
                    'requires_skill' => self::PLUGIN_WHATSAPP,
                    'requires_readiness' => true,
                    'label' => 'WhatsApp ready',
                    'message' => 'Complete WhatsApp setup before installing WhatsApp Assistant.',
                    'why' => 'WhatsApp Assistant should inherit a verified WhatsApp Business channel before assistant messaging is exposed.',
                ]],
                'setup_steps' => ['Connect WhatsApp and configure the assistant phone number settings.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'WhatsApp-led businesses that need instructions, digests, and session continuity in WhatsApp.',
            ],
            self::PLUGIN_SMS_CHANNEL => [
                'key' => self::PLUGIN_SMS_CHANNEL,
                'label' => 'SMS Channel',
                'summary' => 'Installs SMS sending, queueing, delivery tracking, and webhook readiness as an optional workspace communication channel.',
                'category' => 'communication',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'sms_sending' => true,
                    'sms_queue' => true,
                    'delivery_tracking' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=sms_channel#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'sms_channel',
                    'runtime_provider' => 'SMSService',
                    'setup_url' => 'workspace_skills.php?module=sms_channel#setup',
                    'job_hints' => ['services/SMSQueue.php', 'api/webhooks/sms.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'SMS channel module with message delivery and queue indicators.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Add SMS as a fast operational channel for reminders, short updates, and campaign follow-up without making it part of the core CRM surface before it is installed.',
                        'tags' => ['Connect channels', 'Communication', 'Sales follow-up'],
                        'recommendations' => [
                            'Install when customers respond faster to short mobile messages than email.',
                            'Use for reminders, confirmations, urgent follow-up, and compact campaign nudges.',
                            'Pair with delivery health so failed sends and webhook issues are visible.',
                        ],
                        'prerequisites' => [
                            'Twilio account SID, auth token, and sender number.',
                            'A verified sending number or approved messaging service for your region.',
                            'Webhook route configured if inbound/status callbacks are required.',
                        ],
                        'setup_guide' => [
                            'Install the SMS Channel plugin.',
                            'Add Twilio account credentials and sender number.',
                            'Send a controlled test SMS before enabling bulk or automated workflows.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'sms_channel',
                'navigation' => ['label' => 'SMS Channel', 'url' => 'workspace_skills.php?module=sms_channel'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['selected_channel_sms', 'sms_setup_incomplete'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Twilio account SID', 'Twilio auth token', 'Twilio sender number'],
                'setup_steps' => ['Configure Twilio credentials and run an SMS send test.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Teams that need time-sensitive reminders, confirmations, or campaign nudges over SMS.',
            ],
            self::PLUGIN_VOICE_CALL_CENTER => [
                'key' => self::PLUGIN_VOICE_CALL_CENTER,
                'label' => 'Voice & Call Center',
                'summary' => 'Adds secure inbound and outbound business calling, queues, consented recording, transcription, and AI-first customer context. Provider media remains outside the CRM.',
                'category' => 'communication',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'voice_calling' => true,
                    'call_queues' => true,
                    'consented_recording' => true,
                    'post_call_transcription' => true,
                    'customer_voice' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=voice_call_center#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'voice_call_center',
                    'runtime_provider' => 'VoiceCallService',
                    'setup_url' => 'workspace_skills.php?module=voice_call_center#setup',
                    'job_hints' => ['cli/voice_worker.php', 'api/webhooks/voice/africastalking.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'Voice call center with agent routing, consent, transcript, and customer context health.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Bring business calls into the same customer record as messages and meetings. Calls are routed through the client\'s Africa\'s Talking account, then consented recordings can become summaries, next steps, and review-only Customer Voice insights.',
                        'tags' => ['Communication', 'AI guidance', 'Sales follow-up', 'Setup required'],
                        'recommendations' => [
                            'Install for teams that need auditable inbound and outbound calls tied to CRM contacts.',
                            'Use post-call AI to capture commitments and next steps without putting media through the PHP app.',
                            'Keep recording disabled until consent and retention policy are approved.',
                        ],
                        'prerequisites' => [
                            'Funded Africa\'s Talking Voice account and approved virtual number.',
                            'Verified agent phone or SIP endpoints.',
                            'Workspace OpenAI key already saved in Settings for transcription and analysis.',
                            'Approved consent notice and retention policy before recording.',
                        ],
                        'setup_guide' => [
                            'Install Voice & Call Center on an entitled package.',
                            'Connect Africa\'s Talking and configure the provider callback URL.',
                            'Add agents, queues, business hours, and fallback routing.',
                            'Configure consent and AI, then run controlled inbound and outbound tests.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'voice_call_center',
                'navigation' => ['label' => 'Voice', 'url' => 'voice.php'],
                'permissions' => ['voice.calls.use'],
                'recommended_when' => ['call_context_missing', 'phone_led_sales', 'customer_voice_missing'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Africa\'s Talking Voice account', 'virtual number', 'verified agent endpoints', 'workspace OpenAI key', 'consent policy'],
                'setup_steps' => ['Connect the provider, configure agents and queues, approve consent, and run controlled call tests.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Sales and service teams that need call operations and post-call customer context in the CRM.',
            ],
            self::PLUGIN_CALENDAR_MEETINGS => [
                'key' => self::PLUGIN_CALENDAR_MEETINGS,
                'label' => 'Calendar & Meetings',
                'summary' => 'Installs calendar connections, meeting bot scheduling, note ingestion, and meeting prep as one workspace operations module.',
                'category' => 'operations',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'calendar_sync' => true,
                    'meeting_bot' => true,
                    'meeting_notes' => true,
                    'meeting_prep' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=calendar_meetings#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'calendar_meetings',
                    'runtime_provider' => 'CalendarService, MeetingBotService',
                    'setup_url' => 'workspace_skills.php?module=calendar_meetings#setup',
                    'job_hints' => ['api/calendar/oauth/initiate.php', 'api/meeting_bot/schedule.php', 'api/meeting_notes/ingest.php'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'Calendar and meeting automation module with schedule, bot, and notes indicators.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Turn meetings into usable workspace context. Calendar & Meetings connects schedules, bot runs, note ingestion, and prep signals so Clarity understands what happened before and after a call.',
                        'tags' => ['Meetings', 'AI guidance', 'Sales follow-up'],
                        'recommendations' => [
                            'Install when meetings drive sales, onboarding, or delivery decisions.',
                            'Use when calendar sync, bot joins, and meeting notes should be managed together.',
                            'Pair with AI Coach so follow-up recommendations include meeting context.',
                        ],
                        'prerequisites' => [
                            'Google or Outlook calendar connection for the workspace user.',
                            'Meeting bot provider credentials if automated bot joins are needed.',
                            'A consent notice and note ingestion policy for recorded meetings.',
                        ],
                        'setup_guide' => [
                            'Install Calendar & Meetings.',
                            'Connect Google or Outlook Calendar.',
                            'Configure meeting bot identity, provider, and join policy.',
                            'Run a meeting bot or note ingestion readiness check.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'calendar_meetings',
                'navigation' => ['label' => 'Calendar & Meetings', 'url' => 'workspace_skills.php?module=calendar_meetings'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['calendar_not_connected', 'meeting_context_missing'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['calendar integration', 'optional meeting bot provider credentials', 'meeting note ingestion policy'],
                'setup_steps' => ['Connect calendar sync and configure meeting bot or note ingestion readiness.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Meeting-heavy teams that need prep, notes, and follow-up context in one place.',
            ],
            self::PLUGIN_FINANCE => [
                'key' => self::PLUGIN_FINANCE,
                'label' => 'Finance',
                'summary' => 'Gates Finance behind Marketplace setup so opening balances, funding context, and owner equity are reviewed before statements are used.',
                'category' => 'finance',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'finance_gate' => true,
                    'opening_balance_setup' => true,
                    'owner_roi' => true,
                    'ai_context' => true,
                ],
                'advice_domains' => ['finance', 'cash_flow', 'equity', 'roi'],
                'context_schema' => ['fields' => []],
                'task_templates' => [
                    ['title' => 'Review opening balances', 'description' => 'Confirm cash, receivables, payables, loans, assets, and retained equity before relying on Finance.'],
                    ['title' => 'Update owner ROI inputs', 'description' => 'Review owner capital, draws, and ownership percentages after funding or equity changes.'],
                ],
                'boundary_policy' => 'strict',
                'routing_examples' => ['Open Finance after setup', 'Show owner ROI', 'Review opening balances'],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=finance'],
                'plugin_metadata' => [
                    'readiness_provider' => 'finance',
                    'runtime_provider' => 'WorkspaceFinanceGateService',
                    'setup_url' => 'workspace_skills.php?module=finance',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/marketplace/finance.webp',
                        'thumbnail_alt' => 'Finance setup module with opening balances and owner equity checks.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Install Finance when the workspace is ready to track cash, expenses, funding, opening balances, and owner ROI from one calm operator view.',
                        'tags' => ['Setup required', 'Finance', 'Owner ROI'],
                        'benefit_bullets' => [
                            'Prevents incomplete statements from looking trustworthy before opening figures are reviewed.',
                            'Keeps owner ROI private to owner logins.',
                            'Connects capital, funding, loans, draws, and assets to the Finance statement layer.',
                        ],
                        'use_case_bullets' => [
                            'A founder wants P&L, Cash Flow, and Balance Sheet views grounded in opening balances.',
                            'A workspace needs capital and owner equity tracked before finance reports are shared.',
                            'Owners want a private ROI view tied to their login.',
                        ],
                        'outcome_bullets' => [
                            'Finance tab unlocks only after setup is ready.',
                            'Opening cash, receivables, payables, loans, assets, and equity are recorded.',
                            'Owner equity profiles are linked to active workspace owner accounts.',
                        ],
                        'how_it_works_bullets' => [
                            'Marketplace installs Finance as an optional plugin.',
                            'Setup saves opening figures into the Finance ledger.',
                            'Owner equity profiles personalize ROI for owner accounts only.',
                        ],
                        'recommendations' => [
                            'Complete opening balances before relying on statements.',
                            'Tie owner equity to workspace owner accounts for private ROI views.',
                            'Use Finance after invoices, expenses, vendors, and funding entries are being tracked.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'Opening cash, receivables, payables, loans, assets, and equity figures, even when zero.',
                            'Active workspace owner accounts for equity allocation.',
                        ],
                        'setup_guide' => [
                            'Install Finance from Marketplace.',
                            'Save explicit opening figures and currency.',
                            'Allocate owner equity across active owner accounts.',
                            'Open Finance after the readiness check turns ready.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'finance',
                'navigation' => ['label' => 'Finance', 'url' => 'finance.php'],
                'permissions' => ['finance.view'],
                'recommended_when' => ['finance_setup_missing', 'opening_balances_missing', 'owner_roi_needed'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['opening balances', 'owner equity allocation'],
                'setup_steps' => ['Save opening figures.', 'Allocate owner equity to active owner logins.'],
                'coach_bucket' => 'foundation_gaps',
                'business_fit' => 'Founder-led teams that need cash, statements, capital, funding, and owner ROI in one place.',
            ],
            self::PLUGIN_AI_API => [
                'key' => self::PLUGIN_AI_API,
                'label' => 'AI API',
                'summary' => 'Lets each workspace add its own AI provider key while the default workspace common API is used until the shared cap is reached.',
                'category' => 'ai',
                'module_type' => 'plugin',
                'version' => '1.0.0',
                'capabilities' => [
                    'runtime_plugin' => true,
                    'ai_provider_config' => true,
                    'workspace_ai_api' => true,
                    'common_ai_routing' => true,
                    'ai_context' => true,
                ],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=ai_api&setup_tab=provider#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'ai_api',
                    'runtime_provider' => 'WorkspaceAIProviderResolverService',
                    'setup_url' => 'workspace_skills.php?module=ai_api&setup_tab=provider#setup',
                    'job_hints' => ['Use common AI from the default workspace until the cap is reached, then use the workspace key.'],
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'AI API module with workspace provider and common cap routing status.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Expose AI provider setup in Marketplace so workspace owners can add a private API key without needing Settings access. The default workspace common key is used first; once the shared daily cap is reached, Clarity switches to this workspace key.',
                        'tags' => ['AI guidance', 'Setup required'],
                        'benefit_bullets' => [
                            'Keeps founders on the common AI key while you provide the system free.',
                            'Lets a workspace add its own API key only when its usage exceeds the shared cap.',
                            'Shows routing status, cap usage, and provider setup from Marketplace.',
                        ],
                        'use_case_bullets' => [
                            'A workspace needs uninterrupted AI after the common daily cap is reached.',
                            'An owner wants to bring their own OpenAI-compatible key.',
                            'A superadmin configures the common key from the default workspace.',
                        ],
                        'outcome_bullets' => [
                            'AI requests use the default workspace common provider before cap.',
                            'AI requests use the workspace provider after the common cap is exceeded.',
                            'If cap is exceeded and no workspace key exists, the block is explicit.',
                        ],
                        'how_it_works_bullets' => [
                            'Install AI API in the workspace that needs an override.',
                            'Save provider URL, model, and API key from Marketplace setup.',
                            'Use the default workspace AI API module to manage the common cap.',
                        ],
                        'recommendations' => [
                            'Use the common default workspace API for new or low-usage workspaces.',
                            'Install this when AI usage approaches the shared cap.',
                            'Keep each workspace API key scoped to that workspace.',
                        ],
                        'prerequisites' => [
                            'Workspace owner or admin access.',
                            'An OpenAI-compatible API key for workspace override.',
                            'Default workspace common key for platform-funded usage.',
                        ],
                        'setup_guide' => [
                            'Install AI API from Marketplace.',
                            'Add workspace provider URL, model, and API key.',
                            'Save the shared cap only from the default workspace.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'ai_api',
                'navigation' => ['label' => 'AI API', 'url' => 'workspace_skills.php?module=ai_api'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['workspace_ai_key_missing', 'workspace_ai_cap_exceeded', 'ai_provider_missing'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['default workspace common AI config', 'workspace API key after cap'],
                'setup_steps' => ['Add workspace AI provider details.', 'Configure the common cap from the default workspace.'],
                'coach_bucket' => 'foundation_gaps',
                'business_fit' => 'Workspaces that need AI continuity after the platform-funded common cap.',
            ],
            self::SKILL_AI_COACH => [
                'key' => self::SKILL_AI_COACH,
                'label' => 'AI Coach',
                'summary' => 'Adds a decision-ready dashboard coaching layer with readiness checks, trust signals, feedback-aware learning, setup nudges, examples, pairings, and next-step recommendations grounded in installed skills and workspace activity.',
                'category' => 'ai',
                'module_type' => 'skill',
                'version' => '1.0.0',
                'capabilities' => [
                    'ai_context' => true,
                    'recommendations' => true,
                    'follow_through' => true,
                    'workspace_guidance' => true,
                ],
                'advice_domains' => ['coaching', 'follow_through', 'workspace_guidance'],
                'context_schema' => ['fields' => []],
                'task_templates' => [],
                'boundary_policy' => 'orchestrator_only',
                'routing_examples' => ['What should I do next in this workspace?', 'Summarize my recommended actions'],
                'onboarding_fields' => [],
                'settings_schema' => ['requires_configuration' => true, 'settings_url' => 'workspace_skills.php?module=ai_coach&setup_tab=readiness#setup'],
                'plugin_metadata' => [
                    'readiness_provider' => 'ai_coach',
                    'setup_url' => 'workspace_skills.php?module=ai_coach&setup_tab=readiness#setup',
                    'marketplace_profile' => [
                        'thumbnail_url' => 'images/clarity-logo-256.png',
                        'thumbnail_alt' => 'AI Coach module with recommendation cards and guidance signals.',
                        'explainer_video_url' => '',
                        'explainer_orientation' => 'landscape',
                        'pitch' => 'Install AI Coach when the team wants a proactive "what should I do next?" layer on the dashboard. The Marketplace page shows whether AI Coach is ready, what setup is missing, example recommendations, recommended skill pairings, and the boundaries users should understand before relying on it. Recommendation cards also explain why they appeared, show trust signals, and learn conservatively from explicit feedback.',
                        'tags' => ['AI guidance', 'Sales follow-up', 'Strategy'],
                        'benefit_bullets' => [
                            'Gives users an install-or-wait decision guide before they enable proactive coaching.',
                            'Shows readiness for installation, workspace enablement, company context, offer context, Clarity Journey completion, AI services, and advice-skill coverage.',
                            'Shows users what to do next across contacts, deals, tasks, targets, channels, and Marketplace setup.',
                            'Explains why recommendation cards appeared and shows trust signals such as context quality, source, goal match, and confidence.',
                            'Uses explicit feedback to conservatively boost, downrank, or explain similar future recommendations.',
                            'Explains what AI Coach does, what it does not do, which skills pair best with it, and what recommendations may look like.',
                        ],
                        'use_case_bullets' => [
                            'A workspace owner wants dashboard guidance instead of opening chat to ask for next steps.',
                            'A user needs to know which missing setup item blocks better recommendations.',
                            'A sales or marketing team wants follow-through tasks grounded in current CRM activity.',
                            'A founder wants Lean Canvas-backed priorities after the business model context is complete.',
                            'An admin wants to understand which recommendation patterns users accept, dismiss, or mark as not relevant.',
                        ],
                        'outcome_bullets' => [
                            'A visible AI Coach button and recommendation modal for the current workspace user.',
                            'A Marketplace decision guide that shows when to install, when to wait, and what setup remains.',
                            'Clear recommendation sections: foundation gaps, today\'s priorities, quick wins, and missing features.',
                            'Recommendation cards with "why this appeared" chips, trust chips, and feedback learning notes when prior feedback affected ranking.',
                            'Task creation candidates that preserve the recommendation source, guidance run, target, and subtasks.',
                            'Diagnostics insights for accepted, rejected, dismissed, and acted-on Coach recommendation patterns.',
                            'Transparent boundaries so users know AI Coach orchestrates advice but does not replace domain skills or run channel automation.',
                        ],
                        'how_it_works_bullets' => [
                            'Marketplace setup installs AI Coach and enables visibility for the current user.',
                            'Readiness checks confirm AI Coach is installed and enabled, shared company/product context exists, and Clarity Journey is complete.',
                            'Optional personal strategy notes can refine target market focus, market view, strategy hypothesis, and voice without blocking recommendations.',
                            'The description page shows install/wait guidance, readiness status, recommended pairings, and example recommendations.',
                            'AI Coach builds a context bundle from installed skill contracts, CRM activity, active targets, role profile, and user work context.',
                            'Recommendation cards attach stable feedback signatures, why signals, trust signals, and run-level traceability.',
                            'Feedback buttons record useful, not relevant, already done, and show-fewer signals so future cards can be adjusted without changing prompts.',
                            'Diagnostics aggregate Coach feedback metadata so admins can review useful, dismissed, and needs-review patterns.',
                            'Business advice stays limited to ready domain skills; otherwise AI Coach recommends setup instead of guessing.',
                        ],
                        'recommendations' => [
                            'Install when users need proactive dashboard next actions rather than only chat responses.',
                            'Wait to rely on recommendations if company context, product or offer context, Clarity Journey, or AI services are incomplete.',
                            'Pair with Lean Canvas as the Clarity Journey compatibility stage, plus Campaign Manager and channel modules, for stronger recommendation coverage.',
                            'Add personal strategy refinements when an individual wants AI Coach to use their own market lens or drafting voice.',
                            'Add Finance context when available so recommendations can use real budget, runway, margin, and accounting evidence; it is recommended, not required.',
                            'Review AI automation diagnostics after launch to see which Coach recommendations users trust, act on, dismiss, or mark as not relevant.',
                        ],
                        'prerequisites' => [
                            'AI Coach installed for the workspace and enabled for the current user.',
                            'Shared company profile plus product or offer context from Settings.',
                            'Completed Clarity Journey for the current user.',
                            'Optional personal strategy refinements when the user wants a more specific strategy lens.',
                            'Optional Finance evidence when the workspace can safely share money context from its existing finance system or CRM finance module.',
                            'AI services enabled for the workspace.',
                            'At least one ready advice skill when business or strategy recommendations are expected.',
                        ],
                        'setup_guide' => [
                            'Install AI Coach.',
                            'Enable AI Coach for the workspace.',
                            'Complete company profile basics and add products or offers in Settings.',
                            'Complete Clarity Journey so AI Coach can inherit the source-of-truth strategy context.',
                            'Optionally add Finance context for stronger budget, pricing, and runway guidance.',
                            'Optionally save personal strategy refinements for target focus, market view, strategy hypothesis, and voice notes.',
                            'Install and complete at least one advice skill, such as Campaign Manager, for deeper strategic recommendations; Lean Canvas remains available as the Clarity Journey compatibility stage.',
                            'Review recommendations after strategy and channel modules are ready.',
                        ],
                    ],
                ],
                'ai_context_provider' => 'ai_coach',
                'navigation' => ['label' => 'AI Coach', 'url' => 'workspace_skills.php?module=ai_coach'],
                'permissions' => ['workspace.skills.view'],
                'recommended_when' => ['ai_coach_disabled', 'workspace_ready_for_guidance'],
                'not_recommended_when' => ['installed_and_ready'],
                'dependencies' => ['Clarity Journey ready', 'AI services configured', 'workspace activity', 'ready advice skill for strategic recommendations'],
                'access_requirements' => [[
                    'requires_skill' => self::SKILL_LEAN_CANVAS,
                    'requires_readiness' => true,
                    'label' => 'Clarity Journey ready',
                    'message' => 'Complete Clarity Journey before installing AI Coach.',
                    'why' => 'AI Coach recommendations should mature from a completed strategy journey instead of generic workspace activity alone.',
                ]],
                'setup_steps' => ['Enable AI Coach for the current user.', 'Install and complete at least one advice skill for business recommendations.'],
                'coach_bucket' => 'missing_features',
                'business_fit' => 'Teams that want proactive, contextual recommendations and follow-through prompts.',
            ],
        ];

        foreach ($this->marketplacePluginOverviewContent() as $pluginKey => $overviewContent) {
            if (!isset($definitions[$pluginKey])) {
                continue;
            }

            $profile = (array) ($definitions[$pluginKey]['plugin_metadata']['marketplace_profile'] ?? []);
            $definitions[$pluginKey]['plugin_metadata']['marketplace_profile'] = array_merge($profile, $overviewContent);
        }

        return $definitions;
    }

    private function marketplacePluginOverviewContent(): array
    {
        return [
            self::PLUGIN_EMAIL => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Keep email ready for real customer conversations</h3>
<p>Email gives the workspace a clear home for sending, inbox capture, templates, signatures, and delivery checks. It keeps channel setup separate from assistant automation, so owners can confirm the basics before messages move through the CRM.</p>
<blockquote><p>Use this when email supports follow-up, onboarding, support, or nurture work and you want the team to trust the channel before adding automation.</p></blockquote>
<ul>
<li>Connect outreach or nurture sender identities in one place.</li>
<li>Check sending and optional inbox readiness before live workflows depend on them.</li>
<li>Keep templates, signatures, queues, and email activity tied to the same channel setup.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>Email deep dive</h3>
<p>The Email plugin is the foundation for CRM email activity. It owns the practical channel pieces: sender configuration, optional inbound capture, queue visibility, templates, signatures, and readiness checks.</p>
<h4>Setup path</h4>
<ul>
<li>Choose whether the workspace needs outreach, nurture, or both sender identities.</li>
<li>Add SMTP or supported OAuth details for sending, then save optional IMAP details for incoming capture.</li>
<li>Run readiness checks before relying on bulk sends, assistant drafts, or customer follow-up queues.</li>
</ul>
<h4>What changes after activation</h4>
<table>
<thead><tr><th>Area</th><th>What the team gets</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Outbound</td><td>Configured sender details for CRM email workflows.</td><td>Provider limits, credentials, and sender identity accuracy.</td></tr>
<tr><td>Inbound</td><td>Optional inbox capture for customer context.</td><td>IMAP access and folder selection.</td></tr>
<tr><td>Operations</td><td>Templates, signatures, queued sends, and channel checks.</td><td>Testing after credential or domain changes.</td></tr>
</tbody>
</table>
<blockquote><p>Start by making one sender identity reliable, then expand to nurture, inbox capture, and assistant workflows when the readiness check is clean.</p></blockquote>
HTML,
            ],
            self::PLUGIN_WHATSAPP => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Bring WhatsApp into the workspace with clear setup checks</h3>
<p>WhatsApp gives teams a dedicated place to save manual Cloud API credentials, confirm webhook readiness, migrate or register numbers, watch the send queue, and review activity surfaces. It focuses on the channel itself, so customer messaging can be reviewed before assistant behavior is layered on top.</p>
<blockquote><p>Use this when customers respond fastest on WhatsApp and the team needs a steady way to confirm account, webhook, and delivery readiness.</p></blockquote>
<ul>
<li>Keep phone number ID, WABA, access token, and webhook details visible from Marketplace setup.</li>
<li>Register pending Cloud API numbers or migrate On-Premises numbers from the same plugin.</li>
<li>Separate WhatsApp channel health from WhatsApp Assistant session behavior.</li>
<li>Keep Meta embedded signup optional and visible only when the platform app is configured.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>WhatsApp deep dive</h3>
<p>The WhatsApp plugin owns the CRM channel connection. It helps the workspace understand whether a Business number, webhook, token, and message queue are ready enough for real operational use.</p>
<h4>Setup path</h4>
<ul>
<li>Save the workspace phone number ID, display number, WABA or business details, and Cloud API access token.</li>
<li>Copy the workspace webhook callback URL and verify token into Meta before receiving messages.</li>
<li>Use the Migration tab to register pending Cloud API numbers or move On-Premises numbers to Cloud API.</li>
<li>Use Meta embedded signup only when that platform configuration is available.</li>
<li>Run a readiness check after each credential, webhook, or provider change.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Need</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Business number</td><td>Workspace-level WhatsApp identity.</td><td>Correct number, account ownership, and sender expectations.</td></tr>
<tr><td>Webhook route</td><td>Inbound updates and status callbacks.</td><td>Public reachability and matching verify settings.</td></tr>
<tr><td>Queue health</td><td>Safer message processing and visibility.</td><td>Failed sends, expired tokens, and provider pauses.</td></tr>
</tbody>
</table>
<blockquote><p>Make the channel boring and dependable first. Once the checks pass, assistant sessions and higher-volume workflows have a better foundation.</p></blockquote>
HTML,
            ],
            self::PLUGIN_HR_ANALYTICS_SETUP => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Prepare Organization Intelligence before opening the dashboard</h3>
<p>Organization Intelligence helps owners confirm the operating foundation: active business areas, optional teams, and supportive HR signals. Owner responsibility coverage is handled from user accounts, so founder-led and multi-owner workspaces can open the dashboard without a separate plugin assignment step.</p>
<blockquote><p>Use this when leadership wants clearer operating insight without turning setup into technical configuration.</p></blockquote>
<ul>
<li>Guide owners through the minimum setup needed for organization-level insight.</li>
<li>Keep missing business areas and optional teams visible before analytics opens.</li>
<li>Protect users from reading workload, coaching, or team metrics without a clear operating structure.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>Organization Intelligence deep dive</h3>
<p>This required plugin prepares the workspace for people and responsibility analytics. It does not replace the dashboard; it confirms the dashboard has enough company structure to be useful while user and invite flows maintain responsibility ownership.</p>
<h4>Guided setup path</h4>
<ul>
<li>Review the default scoring profile from Marketplace when advanced tuning is needed.</li>
<li>Create or confirm active business areas.</li>
<li>Add departments only when formal HR structure helps reporting and coaching.</li>
</ul>
<h4>Setup progress map</h4>
<table>
<thead><tr><th>Setup area</th><th>Why it matters</th><th>Ready when</th></tr></thead>
<tbody>
<tr><td>Default scoring</td><td>Defines how the workspace treats organization insight.</td><td>Core settings are available for the workspace.</td></tr>
<tr><td>Business areas</td><td>Connects work to real responsibility.</td><td>At least one active area exists.</td></tr>
<tr><td>Owner coverage</td><td>Gives founder-led and multi-owner teams a responsible operating home.</td><td>Owners receive full active business-area coverage from account and invite flows.</td></tr>
<tr><td>Teams & HR structure</td><td>Adds department comparison and manager context.</td><td>Optional departments exist when the business needs them.</td></tr>
</tbody>
</table>
<blockquote><p>Start with the business areas that exist today. The model can mature as the team becomes clearer about formal structure.</p></blockquote>
HTML,
            ],
            self::PLUGIN_EMAIL_ASSISTANT => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Turn email into a calmer assisted workflow</h3>
<p>Email Assistant supports drafts, replies, inbound instructions, and digest workflows after the workspace email channel is configured. It helps teams move faster while keeping sender identity, confidence, and review controls visible.</p>
<blockquote><p>Use this when email work is repetitive or easy to miss, but customer-facing messages still deserve a human review moment.</p></blockquote>
<ul>
<li>Draft replies and outbound help from workspace context instead of blank pages.</li>
<li>Use optional inbound instructions and digests to keep operators informed.</li>
<li>Keep assistant behavior tied to clear permissions, sender settings, and test checks.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>Email Assistant deep dive</h3>
<p>Email Assistant sits above the Email channel. It can help with draft support, customer-thread awareness, inbound instruction handling, and daily digest routines when the underlying sender and inbox setup are ready.</p>
<h4>Setup path</h4>
<ul>
<li>Complete the Email plugin first so the channel can send and, when needed, receive.</li>
<li>Configure assistant identity, sender settings, inbound behavior, skills, and digest preferences.</li>
<li>Run controlled tests before using it in live customer workflows.</li>
</ul>
<h4>Operating controls</h4>
<table>
<thead><tr><th>Control</th><th>Purpose</th><th>Good default</th></tr></thead>
<tbody>
<tr><td>Identity</td><td>Defines who the assistant represents.</td><td>Use a recognizable workspace sender.</td></tr>
<tr><td>Confidence</td><td>Sets the threshold for suggested work.</td><td>Start conservative and adjust from review evidence.</td></tr>
<tr><td>Digest</td><td>Summarizes useful email activity for operators.</td><td>Send to a small accountable group first.</td></tr>
</tbody>
</table>
<blockquote><p>Enable one workflow at a time, review the output, then widen the assistant's responsibilities as the team gains confidence.</p></blockquote>
HTML,
            ],
            self::PLUGIN_WHATSAPP_ASSISTANT => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Support short operational loops inside WhatsApp</h3>
<p>WhatsApp Assistant helps teams use WhatsApp for internal instructions, short digests, reminders, and session-aware follow-through after the WhatsApp channel is ready. It is designed for focused operational help, not unattended customer messaging.</p>
<blockquote><p>Use this when the team already works from WhatsApp and needs assistant support that respects channel readiness and session limits.</p></blockquote>
<ul>
<li>Handle compact instruction loops from a channel operators already watch.</li>
<li>Surface short digests and reminders without opening another dashboard.</li>
<li>Keep session, webhook, and template readiness visible before relying on it.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>WhatsApp Assistant deep dive</h3>
<p>WhatsApp Assistant depends on a ready WhatsApp channel. It focuses on internal assistant workflows such as instructions, summaries, reminders, and session continuity where the setup supports it.</p>
<h4>Setup path</h4>
<ul>
<li>Complete the WhatsApp plugin first so account, number, and webhook checks are ready.</li>
<li>Configure the assistant phone number ID, access token, webhook behavior, and session settings.</li>
<li>Send controlled test messages before inviting wider team use.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Session settings</td><td>Clear rules for when assistant messages can continue.</td><td>Template and reopen expectations.</td></tr>
<tr><td>Webhook health</td><td>Inbound instructions and status awareness.</td><td>Route availability and token freshness.</td></tr>
<tr><td>Team rollout</td><td>Short operational support in a familiar channel.</td><td>Who is allowed to trigger assistant actions.</td></tr>
</tbody>
</table>
<blockquote><p>Start with internal reminders or digests, then add instruction handling after the team understands the session boundaries.</p></blockquote>
HTML,
            ],
            self::PLUGIN_SMS_CHANNEL => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Add SMS for short, time-sensitive communication</h3>
<p>SMS Channel adds a lightweight messaging route for reminders, confirmations, urgent follow-up, and compact campaign nudges. It keeps provider credentials, sender setup, queueing, and delivery checks inside Marketplace before teams depend on SMS.</p>
<blockquote><p>Use this when a brief mobile message is more appropriate than an email or WhatsApp thread, and the team can keep consent and sender expectations clear.</p></blockquote>
<ul>
<li>Connect SMS provider details without exposing the channel before it is ready.</li>
<li>Send controlled tests before using reminders or campaign nudges.</li>
<li>Track delivery health so failed sends do not disappear into the background.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>SMS Channel deep dive</h3>
<p>SMS is best for concise operational messages. This plugin keeps the sender number, provider credentials, queue behavior, and delivery status visible before any workflow relies on mobile delivery.</p>
<h4>Setup path</h4>
<ul>
<li>Add provider credentials and a verified sender number or approved messaging service.</li>
<li>Configure webhook routes when inbound replies or delivery callbacks are needed.</li>
<li>Run a controlled send test before enabling bulk, reminder, or automation workflows.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Need</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Provider credentials</td><td>Authenticated SMS sending.</td><td>Token validity and workspace ownership.</td></tr>
<tr><td>Sender number</td><td>Recognizable outbound identity.</td><td>Regional rules and recipient expectations.</td></tr>
<tr><td>Delivery callbacks</td><td>Better visibility into sent and failed messages.</td><td>Webhook availability and retry behavior.</td></tr>
</tbody>
</table>
<blockquote><p>Keep SMS focused. It works best when the message is short, expected, and useful enough to justify the interruption.</p></blockquote>
HTML,
            ],
            self::PLUGIN_CALENDAR_MEETINGS => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Make meetings part of the workspace memory</h3>
<p>Calendar &amp; Meetings connects scheduling, meeting bot setup, note ingestion, and prep signals so the CRM can understand what happened before and after important calls. It gives meeting-heavy teams one place to review readiness.</p>
<blockquote><p>Use this when calls drive sales, onboarding, delivery, or account decisions and the team needs cleaner follow-through after each meeting.</p></blockquote>
<ul>
<li>Connect calendar context to contacts, deals, tasks, and follow-up work.</li>
<li>Prepare meeting bot and note ingestion settings before live calls depend on them.</li>
<li>Give AI guidance better meeting context without scattering setup across pages.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>Calendar &amp; Meetings deep dive</h3>
<p>This plugin brings schedule context, meeting automation, notes, and preparation signals into one operations module. It helps teams turn calls into usable CRM evidence instead of leaving action items in memory.</p>
<h4>Setup path</h4>
<ul>
<li>Connect Google or Outlook Calendar for the workspace user.</li>
<li>Configure meeting bot identity, provider details, and join policy when bot support is needed.</li>
<li>Confirm note ingestion and consent expectations before recording or importing meeting content.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Calendar sync</td><td>Meeting visibility and prep context.</td><td>Correct account connection and event scope.</td></tr>
<tr><td>Meeting bot</td><td>Automated join and capture support.</td><td>Provider credentials and join policy.</td></tr>
<tr><td>Notes</td><td>Follow-up and recommendation context.</td><td>Consent, accuracy, and review before action.</td></tr>
</tbody>
</table>
<blockquote><p>Begin with calendar sync, then add bot and notes only where the team has clear expectations for capture and review.</p></blockquote>
HTML,
            ],
            self::PLUGIN_FINANCE => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Open Finance from a clean starting point</h3>
<p>Finance stays gated until the workspace records opening figures and owner equity context. That setup protects teams from treating incomplete statements as finished financial truth.</p>
<blockquote><p>Use this when the business is ready to track cash, expenses, funding, assets, liabilities, and owner ROI with a clear opening baseline.</p></blockquote>
<ul>
<li>Save opening cash, receivables, payables, assets, loans, and equity even when values are zero.</li>
<li>Link owner equity to active owner accounts for private ROI views.</li>
<li>Unlock Finance only after the setup review is ready.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>Finance deep dive</h3>
<p>The Finance plugin creates a careful opening gate for financial statements. It asks owners to review the starting position before the workspace relies on cash, balance, expense, funding, and ROI views.</p>
<h4>Setup path</h4>
<ul>
<li>Record the opening date, currency, and cash position.</li>
<li>Add receivables, payables, loans, assets, and other balances, or explicitly mark sections as none.</li>
<li>Allocate owner equity across active owner accounts before opening Finance.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Opening balances</td><td>Statements grounded in an explicit baseline.</td><td>Missing zero-value confirmations.</td></tr>
<tr><td>Owner equity</td><td>Private owner ROI context.</td><td>Active owner accounts and ownership percentages.</td></tr>
<tr><td>Ledger activity</td><td>Useful cash, expense, funding, and asset views.</td><td>Imported or manual entries that need review.</td></tr>
</tbody>
</table>
<blockquote><p>It is better to enter a simple, reviewed starting position than to open Finance with assumptions nobody has confirmed.</p></blockquote>
HTML,
            ],
            self::PLUGIN_AI_API => [
                'overview_brief_format' => 'html',
                'overview_brief_content' => <<<'HTML'
<h3>Give each workspace a clear AI provider path</h3>
<p>AI API lets owners add a workspace-specific provider key while the platform common key remains the first route until the shared cap is reached. It keeps provider setup visible in Marketplace instead of hiding it deep in settings.</p>
<blockquote><p>Use this when a workspace needs AI continuity beyond the shared cap or wants to bring its own OpenAI-compatible key.</p></blockquote>
<ul>
<li>Show common-key routing and workspace override setup from one Marketplace page.</li>
<li>Let owners add provider URL, model, and API key when usage requires it.</li>
<li>Make blocked AI usage easier to explain when no workspace key is available after the cap.</li>
</ul>
HTML,
                'overview_deep_dive_format' => 'html',
                'overview_deep_dive_content' => <<<'HTML'
<h3>AI API deep dive</h3>
<p>AI API manages how a workspace reaches an AI provider. The common platform route can serve early usage, while a workspace key provides a clear fallback when the shared cap is reached or private billing is preferred.</p>
<h4>Setup path</h4>
<ul>
<li>Keep the default workspace common provider configured for platform-funded usage.</li>
<li>Add workspace provider URL, model, and API key when the workspace needs its own route.</li>
<li>Review cap status and provider readiness before troubleshooting AI features elsewhere.</li>
</ul>
<h4>Routing map</h4>
<table>
<thead><tr><th>State</th><th>What happens</th><th>Owner action</th></tr></thead>
<tbody>
<tr><td>Common cap available</td><td>AI requests use the shared platform provider.</td><td>No workspace key required yet.</td></tr>
<tr><td>Common cap reached</td><td>Requests move to the workspace provider when configured.</td><td>Add or verify the workspace key.</td></tr>
<tr><td>No provider available</td><td>The block is explicit so users know setup is needed.</td><td>Complete provider setup before retrying.</td></tr>
</tbody>
</table>
<blockquote><p>Keep the shared route simple for new teams, then add a workspace provider when usage or ownership makes that the cleaner path.</p></blockquote>
HTML,
            ],
        ];
    }

    private function normalizeRow(array $row): array
    {
        $key = (string) ($row['skill_key'] ?? '');
        $fallback = $this->definitions()[$key] ?? [];
        $definitionSource = (string) ($row['definition_source'] ?? ($fallback === [] ? 'custom' : 'platform'));

        return [
            'key' => $key,
            'label' => (string) ($row['label'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'category' => (string) ($row['category'] ?? 'strategy'),
            'module_type' => (string) ($row['module_type'] ?? 'skill'),
            'version' => (string) ($row['version'] ?? '1.0.0'),
            'capabilities' => $this->decodeAssoc($row['capabilities_json'] ?? null),
            'owner_workspace_id' => isset($row['owner_workspace_id']) ? (int) $row['owner_workspace_id'] : null,
            'definition_source' => $definitionSource,
            'is_custom' => in_array($definitionSource, ['custom', 'template_clone'], true),
            'advice_domains' => $this->decodeListOrFallback($row['advice_domains_json'] ?? null, (array) ($fallback['advice_domains'] ?? [])),
            'onboarding_fields' => $this->decodeList($row['onboarding_fields_json'] ?? null),
            'context_schema' => $this->decodeAssocOrFallback($row['context_schema_json'] ?? null, (array) ($fallback['context_schema'] ?? [])),
            'task_templates' => $this->decodeListOfAssoc($row['task_templates_json'] ?? null),
            'boundary_policy' => (string) ($row['boundary_policy'] ?? ($fallback['boundary_policy'] ?? 'strict')),
            'routing_examples' => $this->decodeListOrFallback($row['routing_examples_json'] ?? null, (array) ($fallback['routing_examples'] ?? [])),
            'settings_schema' => $this->decodeAssoc($row['settings_schema_json'] ?? null),
            'plugin_metadata' => $this->decodeAssoc($row['plugin_metadata_json'] ?? null),
            'ai_context_provider' => (string) ($row['ai_context_provider'] ?? ''),
            'navigation' => $this->decodeAssoc($row['navigation_json'] ?? null),
            'permissions' => $this->decodeList($row['permissions_json'] ?? null),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'is_active' => !empty($row['is_active']),
            'catalog_status' => self::CATALOG_STATUS_VISIBLE,
            'recommended_when' => (array) ($fallback['recommended_when'] ?? []),
            'not_recommended_when' => (array) ($fallback['not_recommended_when'] ?? []),
            'dependencies' => (array) ($fallback['dependencies'] ?? []),
            'access_requirements' => (array) ($fallback['access_requirements'] ?? []),
            'setup_steps' => (array) ($fallback['setup_steps'] ?? []),
            'coach_bucket' => (string) ($fallback['coach_bucket'] ?? 'missing_features'),
            'business_fit' => (string) ($fallback['business_fit'] ?? ''),
        ];
    }

    private function applyOverride(array $module): array
    {
        $override = $this->catalogOverridesByKey()[(string) ($module['key'] ?? '')] ?? null;
        if (!$override) {
            return $module;
        }

        $profile = (array) ($module['plugin_metadata']['marketplace_profile'] ?? []);
        $overrideProfile = $this->decodeAssoc($override['marketplace_profile_json'] ?? null);
        $profile = array_merge($profile, $overrideProfile);
        foreach (['thumbnail_url', 'thumbnail_alt', 'banner_url', 'banner_alt'] as $field) {
            $value = trim((string) ($override[$field] ?? ''));
            if ($value !== '') {
                $profile[$field] = $value;
            }
        }

        $module['label'] = trim((string) ($override['label'] ?? '')) !== '' ? (string) $override['label'] : (string) ($module['label'] ?? '');
        $module['summary'] = trim((string) ($override['summary'] ?? '')) !== '' ? (string) $override['summary'] : (string) ($module['summary'] ?? '');
        $module['plugin_metadata']['marketplace_profile'] = $profile;
        $module['catalog_status'] = $this->normalizeCatalogStatus((string) ($override['catalog_status'] ?? self::CATALOG_STATUS_VISIBLE));
        $module['catalog_override'] = true;

        return $module;
    }

    private function catalogOverridesByKey(): array
    {
        if (self::$catalogOverridesByKey !== null) {
            return self::$catalogOverridesByKey;
        }

        if (!$this->overrideTableReady()) {
            self::$catalogOverridesByKey = [];
            return self::$catalogOverridesByKey;
        }

        $statusSelect = $this->overrideStatusColumnReady() ? ', catalog_status' : '';
        $rows = Database::query(
            "SELECT skill_key, label, summary, thumbnail_url, thumbnail_alt, banner_url, banner_alt, marketplace_profile_json{$statusSelect}
             FROM workspace_skill_catalog_overrides"
        );
        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) ($row['skill_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        self::$catalogOverridesByKey = $byKey;
        return self::$catalogOverridesByKey;
    }

    public static function resetRuntimeCaches(): void
    {
        self::$contractColumnsReady = null;
        self::$overrideStatusColumnReady = null;
        self::$definitionsSynced = false;
        self::$availableForWorkspaceCache = [];
        self::$findForWorkspaceCache = [];
        self::$catalogOverridesByKey = null;
    }

    private function clearRequestCaches(): void
    {
        self::$availableForWorkspaceCache = [];
        self::$findForWorkspaceCache = [];
        self::$catalogOverridesByKey = null;
    }

    private function withDefaultCatalogStatus(array $module): array
    {
        $module['catalog_status'] = $this->normalizeCatalogStatus((string) ($module['catalog_status'] ?? self::CATALOG_STATUS_VISIBLE));
        return $module;
    }

    private function isCatalogVisible(array $module): bool
    {
        if (!empty($module['plugin_metadata']['legacy_hidden_from_marketplace'])) {
            return false;
        }

        return $this->normalizeCatalogStatus((string) ($module['catalog_status'] ?? self::CATALOG_STATUS_VISIBLE)) === self::CATALOG_STATUS_VISIBLE;
    }

    private function normalizeCatalogStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, [
            self::CATALOG_STATUS_VISIBLE,
            self::CATALOG_STATUS_HIDDEN,
            self::CATALOG_STATUS_DEACTIVATED,
        ], true) ? $status : self::CATALOG_STATUS_VISIBLE;
    }

    private function decodeAssoc(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeAssocOrFallback(mixed $json, array $fallback): array
    {
        $decoded = $this->decodeAssoc($json);
        return $decoded !== [] ? $decoded : $fallback;
    }

    private function decodeList(mixed $json): array
    {
        return array_values(array_filter($this->decodeAssoc($json), static fn(mixed $value): bool => is_scalar($value)));
    }

    private function decodeListOrFallback(mixed $json, array $fallback): array
    {
        $decoded = $this->decodeList($json);
        return $decoded !== [] ? $decoded : array_values(array_filter(array_map('strval', $fallback)));
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

    private function definitionSelectList(): string
    {
        $columns = [
            'skill_key',
            'label',
            'summary',
            'category',
            'module_type',
            'version',
            'capabilities_json',
            'onboarding_fields_json',
            'settings_schema_json',
            'plugin_metadata_json',
            'ai_context_provider',
            'navigation_json',
            'permissions_json',
            'is_active',
        ];

        if ($this->contractColumnsReady()) {
            $columns = array_merge($columns, self::CONTRACT_COLUMNS);
        }

        return implode(', ', $columns);
    }

    private function buildCustomSkillKey(int $workspaceId, string $label): string
    {
        $slug = trim($this->normalizeKey($label), '_');
        if ($slug === '') {
            $slug = 'skill';
        }
        $base = 'custom_' . $workspaceId . '_' . $slug;
        $candidate = $base;
        $suffix = 2;

        while ($this->skillKeyExists($candidate)) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function skillKeyExists(string $skillKey): bool
    {
        if (!$this->tableReady()) {
            return isset($this->definitions()[$skillKey]);
        }

        return (bool) Database::queryOne(
            "SELECT 1 FROM workspace_skill_definitions WHERE skill_key = ? LIMIT 1",
            [$skillKey]
        );
    }

    private function normalizeAdviceDomains(array $domains): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $clean = trim($this->normalizeKey((string) $domain), '_');
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        $out = array_values(array_unique($out));
        return $out !== [] ? array_slice($out, 0, 16) : ['custom_advice'];
    }

    private function normalizeContextSchema(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $label = $this->sanitizeString((string) ($field['label'] ?? $field['key'] ?? ''), 120);
                $key = trim($this->normalizeKey((string) ($field['key'] ?? $label)), '_');
                $required = array_key_exists('required', $field) ? (bool) $field['required'] : true;
            } else {
                $label = $this->sanitizeString((string) $field, 120);
                $key = trim($this->normalizeKey($label), '_');
                $required = true;
            }
            if ($key === '' || $label === '') {
                continue;
            }
            $normalized[$key] = [
                'key' => $key,
                'label' => $label,
                'required' => $required,
            ];
        }

        return ['fields' => array_values(array_slice($normalized, 0, 20))];
    }

    private function contextFieldKeys(array $fields): array
    {
        return array_values(array_map(
            static fn(array $field): string => (string) $field['key'],
            (array) ($this->normalizeContextSchema($fields)['fields'] ?? [])
        ));
    }

    private function normalizeTaskTemplates(array $templates): array
    {
        $out = [];
        foreach ($templates as $template) {
            if (is_array($template)) {
                $title = $this->sanitizeString((string) ($template['title'] ?? ''), 160);
                $description = $this->sanitizeString((string) ($template['description'] ?? ''), 500);
                $defaultPriority = $this->normalizeKey((string) ($template['default_priority'] ?? ''));
                $dueOffset = $this->sanitizeString((string) ($template['due_offset'] ?? ''), 80);
                $subtasks = $this->sanitizeList((array) ($template['subtasks'] ?? []), 12, 220);
                $completionEvidenceTypes = $this->sanitizeList((array) ($template['completion_evidence_types'] ?? []), 8, 80);
                $targetMetricHint = $this->sanitizeString((string) ($template['target_metric_hint'] ?? ''), 120);
            } else {
                $title = $this->sanitizeString((string) $template, 160);
                $description = '';
                $defaultPriority = '';
                $dueOffset = '';
                $subtasks = [];
                $completionEvidenceTypes = [];
                $targetMetricHint = '';
            }
            if ($title === '') {
                continue;
            }
            $normalized = [
                'title' => $title,
                'description' => $description,
            ];
            if (in_array($defaultPriority, ['low', 'medium', 'high', 'urgent'], true)) {
                $normalized['default_priority'] = $defaultPriority;
            }
            if ($dueOffset !== '') {
                $normalized['due_offset'] = $dueOffset;
            }
            if ($subtasks !== []) {
                $normalized['subtasks'] = $subtasks;
            }
            if ($completionEvidenceTypes !== []) {
                $normalized['completion_evidence_types'] = $completionEvidenceTypes;
            }
            if ($targetMetricHint !== '') {
                $normalized['target_metric_hint'] = $targetMetricHint;
            }
            $out[] = $normalized;
        }

        return array_values(array_slice($out, 0, 12));
    }

    private function normalizeBoundaryPolicy(string $policy): string
    {
        $policy = trim($this->normalizeKey($policy), '_');
        return in_array($policy, ['strict', 'warn', 'orchestrator_only'], true) ? $policy : 'strict';
    }

    private function buildCustomMarketplaceProfile(array $data): array
    {
        $summary = $this->sanitizeString((string) ($data['summary'] ?? ''), 4000);
        $instructions = $this->sanitizeString((string) ($data['guidance_instructions'] ?? ''), 4000);
        $recommendations = $this->sanitizeList((array) ($data['recommendations'] ?? []));
        $setupGuide = $this->sanitizeList((array) ($data['setup_guide'] ?? []));

        return [
            'guidance_instructions' => $instructions,
            'marketplace_profile' => [
                'thumbnail_url' => 'images/clarity-logo-256.png',
                'thumbnail_alt' => 'Custom workspace skill.',
                'pitch' => $summary !== '' ? $summary : 'Custom workspace-owned guidance skill.',
                'tags' => $this->deriveCustomMarketplaceTags($data),
                'recommendations' => $recommendations !== [] ? $recommendations : ['Install when this workspace needs guidance inside this skill domain.'],
                'prerequisites' => $this->sanitizeList((array) ($data['prerequisites'] ?? [])),
                'setup_guide' => $setupGuide !== [] ? $setupGuide : ['Install the skill.', 'Fill the required context fields.', 'Ask Clarity for guidance within the saved domains.'],
            ],
        ];
    }

    private function deriveCustomMarketplaceTags(array $data): array
    {
        $hints = strtolower(implode(' ', array_filter([
            (string) ($data['category'] ?? ''),
            implode(' ', array_map('strval', (array) ($data['advice_domains'] ?? []))),
        ])));
        $tags = [];
        foreach ([
            'Strategy' => ['strategy', 'startup', 'business_model', 'pricing', 'validation', 'metrics'],
            'Marketing' => ['marketing', 'campaign', 'positioning', 'messaging', 'content'],
            'Communication' => ['communication', 'email', 'whatsapp', 'sms', 'channel'],
            'People ops' => ['people', 'hr', 'staff', 'team', 'department'],
            'Meetings' => ['meeting', 'calendar'],
            'Sales follow-up' => ['sales', 'deal', 'follow', 'outreach', 'retention'],
            'AI guidance' => ['ai', 'coach', 'guidance', 'clarity'],
        ] as $tag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($hints, $needle)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return array_values(array_unique(array_slice($tags, 0, 4)));
    }

    private function normalizeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? '');
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function sanitizeList(array $values, int $limit = 12, int $stringLimit = 500): array
    {
        $out = [];
        foreach ($values as $value) {
            $clean = $this->sanitizeString((string) $value, $stringLimit);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_values(array_slice($out, 0, max(1, $limit)));
    }

    private function normalizeRichContentFormat(string $format): string
    {
        return strtolower(trim($format)) === 'html' ? 'html' : 'text';
    }

    private function sanitizePlainText(string $value, int $maxLength): string
    {
        $value = str_replace("\0", '', trim($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function sanitizeMarketplaceHtml(string $html): string
    {
        $html = $this->sanitizePlainText($html, 20000);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*\/?\s*>/is', '', $html) ?? $html;

        $allowedTags = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
            'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li',
            'a', 'blockquote', 'pre', 'code',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'figure', 'figcaption', 'img', 'hr',
        ];
        $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

        $html = preg_replace_callback('/<([a-z0-9]+)([^>]*)>/i', function (array $matches): string {
            $tag = strtolower((string) $matches[1]);
            $attrs = (string) $matches[2];
            $cleanAttrs = [];

            if ($tag === 'a' && preg_match('/\shref\s*=\s*(["\'])(.*?)\1/is', $attrs, $hrefMatch) === 1) {
                $href = html_entity_decode(trim((string) $hrefMatch[2]), ENT_QUOTES, 'UTF-8');
                if (preg_match('#^https?://#i', $href) === 1 || str_starts_with($href, 'mailto:')) {
                    $cleanAttrs[] = 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
                    $cleanAttrs[] = 'target="_blank"';
                    $cleanAttrs[] = 'rel="noopener noreferrer"';
                }
            }

            if ($tag === 'img') {
                if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $attrs, $srcMatch) === 1) {
                    $src = html_entity_decode(trim((string) $srcMatch[2]), ENT_QUOTES, 'UTF-8');
                    if ($this->isAllowedMarketplaceImageSource($src)) {
                        $cleanAttrs[] = 'src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
                        if (preg_match('/\salt\s*=\s*(["\'])(.*?)\1/is', $attrs, $altMatch) === 1) {
                            $cleanAttrs[] = 'alt="' . htmlspecialchars($this->sanitizeString((string) $altMatch[2], 255), ENT_QUOTES, 'UTF-8') . '"';
                        } else {
                            $cleanAttrs[] = 'alt=""';
                        }
                        $cleanAttrs[] = 'loading="lazy"';
                    }
                }

                if ($cleanAttrs === []) {
                    return '';
                }
            }

            return '<' . $tag . ($cleanAttrs !== [] ? ' ' . implode(' ', $cleanAttrs) : '') . '>';
        }, $html) ?? $html;

        return trim($html);
    }

    private function isAllowedMarketplaceImageSource(string $src): bool
    {
        $src = trim($src);
        if ($src === '' || preg_match('/[\x00-\x1F\x7F]/', $src) === 1) {
            return false;
        }

        if (preg_match('#^https://#i', $src) === 1) {
            return true;
        }

        $normalized = ltrim($src, './');
        return str_starts_with($normalized, 'uploads/marketplace/')
            || str_starts_with($normalized, '../uploads/marketplace/')
            || str_starts_with($normalized, '/uploads/marketplace/');
    }
}
