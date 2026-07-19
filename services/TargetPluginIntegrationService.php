<?php

namespace CRM\Services;

use CRM\Database;

class TargetPluginIntegrationService
{
    private const NATIVE_PROVIDERS = [
        'deals' => ['closed_won_count', 'open_count', 'proposal_negotiation_count', 'won_value', 'pipeline_value'],
        'invoices' => ['paid_total', 'sent_total', 'paid_count', 'active_quote_count'],
        'tasks' => ['completed_count', 'open_count'],
        'contacts' => ['created_count', 'qualified_count', 'won_count'],
        'communications' => ['outbound_count', 'inbound_count', 'reply_count'],
        'meetings' => ['completed_count'],
        'events' => ['completed_count'],
        'documents' => ['created_count', 'proposal_created_count'],
    ];

    private PluginRuntimeRegistryService $registry;
    private PluginRuntimeEventService $events;

    public function __construct(?PluginRuntimeRegistryService $registry = null, ?PluginRuntimeEventService $events = null)
    {
        $this->registry = $registry ?? new PluginRuntimeRegistryService();
        $this->events = $events ?? new PluginRuntimeEventService();
    }

    public function normalizeRollupDefinition(int $workspaceId, mixed $definition): array
    {
        $definition = is_array($definition) ? $definition : [];
        $source = strtolower(trim((string) ($definition['source'] ?? '')));
        $metric = strtolower(trim((string) ($definition['metric'] ?? '')));
        if ($source === '' || $metric === '') {
            return [];
        }

        if (isset(self::NATIVE_PROVIDERS[$source]) && in_array($metric, self::NATIVE_PROVIDERS[$source], true)) {
            return ['source' => $source, 'metric' => $metric, 'filters' => (array) ($definition['filters'] ?? [])];
        }

        $provider = $this->findMetricProvider($workspaceId, $source, $metric);
        if (!$provider) {
            return [];
        }

        return [
            'source' => $source,
            'metric' => $metric,
            'provider_skill_key' => (string) ($provider['skill_key'] ?? ''),
            'provider_capability_key' => (string) ($provider['capability_key'] ?? ($provider['metric_source'] . '.' . $provider['metric_key'])),
        ];
    }

    public function computePluginRollup(array $target, array $definition, bool $withEvidence = false): ?array
    {
        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        $provider = $this->findMetricProvider($workspaceId, (string) ($definition['source'] ?? ''), (string) ($definition['metric'] ?? ''));
        if (!$provider) {
            return null;
        }

        $capabilityKey = (string) ($provider['capability_key'] ?? ((string) $provider['metric_source'] . '.' . (string) $provider['metric_key']));
        $capability = $this->registry->findCapability($workspaceId, $capabilityKey, true) ?? [
            'workspace_id' => $workspaceId,
            'skill_key' => (string) ($provider['skill_key'] ?? ''),
            'capability_key' => $capabilityKey,
            'capability_type' => PluginRuntimeRegistryService::TYPE_TARGET_METRIC_PROVIDER,
            'handler_class' => (string) ($provider['handler_class'] ?? BuiltInPluginCapabilityHandler::class),
            'handler_method' => 'computeTargetMetric',
            'schema' => (array) ($provider['schema'] ?? []),
            'status' => (string) ($provider['status'] ?? 'active'),
        ];

        $handler = $this->registry->resolveHandler($capability);
        if (!$handler instanceof TargetPluginCapabilityInterface) {
            return null;
        }

        $start = microtime(true);
        $result = $handler->computeTargetMetric($capability, $target, $definition, $withEvidence);
        if ($result !== null) {
            $this->events->record([
                'workspace_id' => $workspaceId,
                'skill_key' => (string) ($capability['skill_key'] ?? ''),
                'capability_key' => (string) ($capability['capability_key'] ?? ''),
                'entity_type' => 'target',
                'entity_id' => (int) ($target['id'] ?? 0),
                'event_type' => 'target_rollup_computed',
                'status' => 'success',
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'metadata' => ['source' => $definition['source'] ?? '', 'metric' => $definition['metric'] ?? ''],
            ]);
        }

        return $result;
    }

    public function providersForWorkspace(int $workspaceId): array
    {
        $providers = [];
        foreach (self::NATIVE_PROVIDERS as $source => $metrics) {
            foreach ($metrics as $metric) {
                $providers[] = [
                    'workspace_id' => $workspaceId,
                    'skill_key' => 'core',
                    'metric_source' => $source,
                    'metric_key' => $metric,
                    'label' => ucwords(str_replace('_', ' ', $source . ' ' . $metric)),
                    'native' => true,
                    'status' => 'active',
                ];
            }
        }

        if (!Database::tableExists('target_metric_providers')) {
            return $providers;
        }

        return array_merge($providers, Database::query(
            "SELECT workspace_id, skill_key, metric_source, metric_key, label, handler_class, schema_json, status
             FROM target_metric_providers
             WHERE workspace_id = ?
               AND status = 'active'
             ORDER BY metric_source ASC, metric_key ASC",
            [$workspaceId]
        ));
    }

    private function findMetricProvider(int $workspaceId, string $source, string $metric): ?array
    {
        $source = strtolower(trim($source));
        $metric = strtolower(trim($metric));
        if ($workspaceId <= 0 || $source === '' || $metric === '') {
            return null;
        }

        if (Database::tableExists('target_metric_providers')) {
            $row = Database::queryOne(
                "SELECT *
                 FROM target_metric_providers
                 WHERE workspace_id = ?
                   AND metric_source = ?
                   AND metric_key = ?
                   AND status = 'active'
                 LIMIT 1",
                [$workspaceId, $source, $metric]
            );
            if ($row) {
                $row['capability_key'] = $source . '.' . $metric;
                $row['schema'] = is_string($row['schema_json'] ?? null) ? (json_decode((string) $row['schema_json'], true) ?: []) : [];
                return $row;
            }
        }

        foreach ($this->registry->capabilitiesByType($workspaceId, PluginRuntimeRegistryService::TYPE_TARGET_METRIC_PROVIDER, 0, true) as $capability) {
            $schema = (array) ($capability['schema'] ?? []);
            if ((string) ($schema['source'] ?? '') === $source && (string) ($schema['metric'] ?? '') === $metric) {
                return [
                    'workspace_id' => $workspaceId,
                    'skill_key' => (string) ($capability['skill_key'] ?? ''),
                    'metric_source' => $source,
                    'metric_key' => $metric,
                    'label' => ucwords(str_replace('_', ' ', $source . ' ' . $metric)),
                    'handler_class' => (string) ($capability['handler_class'] ?? ''),
                    'capability_key' => (string) ($capability['capability_key'] ?? ''),
                    'schema' => $schema,
                    'status' => (string) ($capability['status'] ?? 'active'),
                ];
            }
        }

        return null;
    }
}
