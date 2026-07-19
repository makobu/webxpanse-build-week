<?php

namespace CRM\Services;

use CRM\Database;

class PluginRuntimeRegistryService
{
    public const TYPE_TASK_PROVIDER = 'task_provider';
    public const TYPE_TASK_ENRICHER = 'task_enricher';
    public const TYPE_TARGET_METRIC_PROVIDER = 'target_metric_provider';
    public const TYPE_TARGET_ADVICE_PROVIDER = 'target_advice_provider';
    public const TYPE_WORKFLOW_ACTION = 'workflow_action';
    public const TYPE_WORKFLOW_TRIGGER = 'workflow_trigger';
    public const TYPE_AI_CONTEXT_PROVIDER = 'ai_context_provider';
    public const TYPE_RUNTIME_GATE = 'runtime_gate';
    public const TYPE_BACKGROUND_JOB = 'background_job';

    private const VALID_TYPES = [
        self::TYPE_TASK_PROVIDER,
        self::TYPE_TASK_ENRICHER,
        self::TYPE_TARGET_METRIC_PROVIDER,
        self::TYPE_TARGET_ADVICE_PROVIDER,
        self::TYPE_WORKFLOW_ACTION,
        self::TYPE_WORKFLOW_TRIGGER,
        self::TYPE_AI_CONTEXT_PROVIDER,
        self::TYPE_RUNTIME_GATE,
        self::TYPE_BACKGROUND_JOB,
    ];

    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;

    public function __construct(?WorkspaceSkillCatalogService $catalog = null, ?WorkspaceSkillInstallService $installer = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
    }

    public function capabilitiesForWorkspace(int $workspaceId, int $userId = 0, bool $includeUnavailable = false): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        if (!$this->tableReady()) {
            return $this->fallbackCapabilities($workspaceId, $userId, $includeUnavailable);
        }

        $this->syncWorkspaceCapabilities($workspaceId);
        $rows = Database::query(
            "SELECT *
             FROM workspace_plugin_capabilities
             WHERE workspace_id = ?
             ORDER BY capability_type ASC, skill_key ASC, capability_key ASC",
            [$workspaceId]
        );

        $capabilities = [];
        foreach ($rows as $row) {
            $capability = $this->normalizeCapabilityRow($row);
            $validation = $this->validateCapability($capability, $workspaceId, $userId);
            $capability['available'] = (bool) ($validation['available'] ?? false);
            $capability['unavailable_reason'] = (string) ($validation['reason'] ?? '');
            if ($includeUnavailable || $capability['available']) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    public function capabilitiesByType(int $workspaceId, string $type, int $userId = 0, bool $includeUnavailable = false): array
    {
        $type = $this->normalizeType($type);
        if ($type === '') {
            return [];
        }

        return array_values(array_filter(
            $this->capabilitiesForWorkspace($workspaceId, $userId, $includeUnavailable),
            static fn(array $capability): bool => (string) ($capability['capability_type'] ?? '') === $type
        ));
    }

    public function findCapability(int $workspaceId, string $capabilityKey, bool $includeUnavailable = false, int $userId = 0): ?array
    {
        $capabilityKey = $this->normalizeCapabilityKey($capabilityKey);
        if ($capabilityKey === '') {
            return null;
        }

        foreach ($this->capabilitiesForWorkspace($workspaceId, $userId, $includeUnavailable) as $capability) {
            if ((string) ($capability['capability_key'] ?? '') === $capabilityKey) {
                return $capability;
            }
        }

        return null;
    }

    public function syncWorkspaceCapabilities(int $workspaceId): void
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        foreach ($this->installer->installedForWorkspace($workspaceId) as $module) {
            $skillKey = (string) ($module['key'] ?? '');
            if ($skillKey === '') {
                continue;
            }
            foreach ($this->defaultCapabilitiesForModule($module) as $capability) {
                Database::execute(
                    "INSERT INTO workspace_plugin_capabilities (
                        workspace_id, skill_key, capability_key, capability_type, handler_class,
                        handler_method, schema_json, permissions_json, status
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE
                        capability_type = VALUES(capability_type),
                        handler_class = VALUES(handler_class),
                        handler_method = VALUES(handler_method),
                        schema_json = VALUES(schema_json),
                        permissions_json = VALUES(permissions_json),
                        status = IF(status = 'inactive', status, VALUES(status)),
                        updated_at = NOW()",
                    [
                        $workspaceId,
                        $skillKey,
                        (string) $capability['capability_key'],
                        (string) $capability['capability_type'],
                        (string) $capability['handler_class'],
                        (string) $capability['handler_method'],
                        json_encode((array) ($capability['schema'] ?? []), JSON_UNESCAPED_SLASHES),
                        json_encode((array) ($capability['permissions'] ?? ['workspace.skills.view']), JSON_UNESCAPED_SLASHES),
                    ]
                );
            }
        }
    }

    public function validateCapability(array $capability, int $workspaceId, int $userId = 0): array
    {
        if ((string) ($capability['status'] ?? '') !== 'active') {
            return ['available' => false, 'reason' => 'capability_inactive'];
        }

        $skillKey = (string) ($capability['skill_key'] ?? '');
        if ($skillKey === '' || $this->catalog->isGloballyDeactivated($skillKey)) {
            return ['available' => false, 'reason' => 'module_deactivated'];
        }
        if (!$this->installer->isInstalled($workspaceId, $skillKey)) {
            return ['available' => false, 'reason' => 'module_not_installed'];
        }
        if (!$this->installer->canExposeRuntimeModule($workspaceId, $skillKey)) {
            (new PluginRuntimeEventService())->record([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'skill_key' => $skillKey,
                'capability_key' => (string) ($capability['capability_key'] ?? ''),
                'event_type' => 'readiness_blocked',
                'status' => 'blocked',
            ]);
            return ['available' => false, 'reason' => 'module_not_ready'];
        }

        $handler = $this->resolveHandler($capability);
        if (!$handler) {
            return ['available' => false, 'reason' => 'handler_missing'];
        }
        if (!$this->handlerSupportsType($handler, (string) ($capability['capability_type'] ?? ''))) {
            return ['available' => false, 'reason' => 'handler_contract_mismatch'];
        }
        if (!$handler->supportsCapability($capability)) {
            return ['available' => false, 'reason' => 'handler_rejected'];
        }

        return ['available' => true, 'reason' => ''];
    }

    public function resolveHandler(array $capability): ?PluginCapabilityHandlerInterface
    {
        $class = trim((string) ($capability['handler_class'] ?? ''));
        if ($class === '' || !class_exists($class)) {
            return null;
        }

        $handler = new $class();
        return $handler instanceof PluginCapabilityHandlerInterface ? $handler : null;
    }

    public function tableReady(): bool
    {
        return Database::tableExists('workspace_plugin_capabilities');
    }

    private function defaultCapabilitiesForModule(array $module): array
    {
        $skillKey = (string) ($module['key'] ?? '');
        $moduleType = (string) ($module['module_type'] ?? 'skill');
        $capabilities = [];

        if (!empty($module['capabilities']['ai_context']) || (string) ($module['ai_context_provider'] ?? '') !== '') {
            $capabilities[] = $this->builtInCapability($skillKey, self::TYPE_AI_CONTEXT_PROVIDER, $skillKey . '.ai_context', 'provideAIContext');
        }

        if ($moduleType === 'skill' || !empty($module['task_templates'])) {
            $capabilities[] = $this->builtInCapability($skillKey, self::TYPE_TASK_ENRICHER, $skillKey . '.task_enricher', 'enrichTaskPayload');
        }

        if ($moduleType === 'plugin') {
            $capabilities[] = $this->builtInCapability($skillKey, self::TYPE_RUNTIME_GATE, $skillKey . '.runtime_gate', 'provideAIContext');
        }

        if (in_array($skillKey, [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
            WorkspaceSkillCatalogService::PLUGIN_FINANCE,
            WorkspaceSkillCatalogService::SKILL_AI_COACH,
        ], true)) {
            $capabilities[] = $this->builtInCapability($skillKey, self::TYPE_TARGET_METRIC_PROVIDER, $skillKey . '.runtime_activity_count', 'computeTargetMetric', [
                'source' => $skillKey,
                'metric' => 'runtime_activity_count',
            ]);
        }

        return $capabilities;
    }

    private function builtInCapability(string $skillKey, string $type, string $key, string $method, array $schema = []): array
    {
        return [
            'skill_key' => $skillKey,
            'capability_key' => $this->normalizeCapabilityKey($key),
            'capability_type' => $type,
            'handler_class' => BuiltInPluginCapabilityHandler::class,
            'handler_method' => $method,
            'schema' => $schema,
            'permissions' => ['workspace.skills.view'],
        ];
    }

    private function fallbackCapabilities(int $workspaceId, int $userId, bool $includeUnavailable): array
    {
        $capabilities = [];
        foreach ($this->installer->installedForWorkspace($workspaceId) as $module) {
            foreach ($this->defaultCapabilitiesForModule($module) as $capability) {
                $capability['workspace_id'] = $workspaceId;
                $capability['status'] = 'active';
                $capability['schema'] = (array) ($capability['schema'] ?? []);
                $capability['available'] = true;
                $capabilities[] = $capability;
            }
        }
        return $capabilities;
    }

    private function normalizeCapabilityRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'skill_key' => (string) ($row['skill_key'] ?? ''),
            'capability_key' => $this->normalizeCapabilityKey((string) ($row['capability_key'] ?? '')),
            'capability_type' => $this->normalizeType((string) ($row['capability_type'] ?? '')),
            'handler_class' => (string) ($row['handler_class'] ?? ''),
            'handler_method' => (string) ($row['handler_method'] ?? ''),
            'schema' => $this->decodeAssoc($row['schema_json'] ?? null),
            'permissions' => $this->decodeList($row['permissions_json'] ?? null),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }

    private function handlerSupportsType(PluginCapabilityHandlerInterface $handler, string $type): bool
    {
        return match ($type) {
            self::TYPE_TASK_PROVIDER, self::TYPE_TASK_ENRICHER => $handler instanceof TaskPluginCapabilityInterface,
            self::TYPE_TARGET_METRIC_PROVIDER, self::TYPE_TARGET_ADVICE_PROVIDER => $handler instanceof TargetPluginCapabilityInterface,
            self::TYPE_WORKFLOW_ACTION, self::TYPE_WORKFLOW_TRIGGER => $handler instanceof WorkflowPluginCapabilityInterface,
            self::TYPE_AI_CONTEXT_PROVIDER, self::TYPE_RUNTIME_GATE, self::TYPE_BACKGROUND_JOB => $handler instanceof AIContextPluginCapabilityInterface,
            default => false,
        };
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::VALID_TYPES, true) ? $type : '';
    }

    private function normalizeCapabilityKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_.]+/', '_', trim($key)) ?? '');
    }

    private function decodeAssoc(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeList(mixed $json): array
    {
        $decoded = $this->decodeAssoc($json);
        return array_values(array_filter(array_map('strval', $decoded)));
    }
}
