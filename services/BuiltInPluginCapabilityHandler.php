<?php

namespace CRM\Services;

use CRM\Database;

class BuiltInPluginCapabilityHandler implements TaskPluginCapabilityInterface, TargetPluginCapabilityInterface, WorkflowPluginCapabilityInterface, AIContextPluginCapabilityInterface
{
    public function supportsCapability(array $capability): bool
    {
        return trim((string) ($capability['capability_key'] ?? '')) !== '';
    }

    public function validateTaskPayload(array $capability, array $taskPayload, array $context = []): array
    {
        return ['allowed' => true, 'reasons' => []];
    }

    public function enrichTaskPayload(array $capability, array $taskPayload, array $context = []): array
    {
        $metadata = (array) ($taskPayload['metadata_json'] ?? []);
        $metadata['plugin_runtime'] = array_values(array_unique(array_filter(array_merge(
            (array) ($metadata['plugin_runtime'] ?? []),
            [(string) ($capability['capability_key'] ?? '')]
        ))));
        $taskPayload['metadata_json'] = $metadata;

        return $taskPayload;
    }

    public function handleTaskLifecycleEvent(string $eventName, array $capability, array $taskPayload, array $context = []): void
    {
        // Built-in capabilities currently enrich and audit only.
    }

    public function computeTargetMetric(array $capability, array $target, array $definition, bool $withEvidence = false): ?array
    {
        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        $skillKey = (string) ($capability['skill_key'] ?? $definition['provider_skill_key'] ?? '');
        if ($workspaceId <= 0 || $skillKey === '' || !Database::tableExists('workspace_plugin_runtime_events')) {
            return null;
        }

        $value = (float) (Database::queryOne(
            "SELECT COUNT(*) AS value
             FROM workspace_plugin_runtime_events
             WHERE workspace_id = ?
               AND skill_key = ?
               AND status IN ('success','info')",
            [$workspaceId, $skillKey]
        )['value'] ?? 0);

        $result = [
            'value' => $value,
            'label' => ucwords(str_replace('_', ' ', $skillKey)) . ' runtime activity',
            'evidence' => [],
        ];
        if ($withEvidence) {
            $result['evidence'] = Database::query(
                "SELECT id, event_type, capability_key, entity_type, entity_id, created_at
                 FROM workspace_plugin_runtime_events
                 WHERE workspace_id = ?
                   AND skill_key = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 10",
                [$workspaceId, $skillKey]
            );
        }

        return $result;
    }

    public function enrichTargetIntelligence(array $capability, array $target, array $context = []): array
    {
        return $target;
    }

    public function executeWorkflowAction(array $capability, array $action, array $context = []): array
    {
        return [
            'status' => 'skipped',
            'message' => 'No executable handler is configured for this plugin workflow action.',
        ];
    }

    public function provideAIContext(array $capability, int $workspaceId, int $userId, array $context = []): array
    {
        $responseStyleContract = (array) (
            $context['response_style_contract']
            ?? $context['ai_settings']['response_style_contract']
            ?? []
        );
        if ($responseStyleContract === []) {
            $responseStyleContract = (new WorkspaceLanguageLevelService())->currentResponseStyleContract($userId > 0 ? $userId : null);
        }

        return [
            'skill_key' => (string) ($capability['skill_key'] ?? ''),
            'capability_key' => (string) ($capability['capability_key'] ?? ''),
            'ready' => true,
            'response_style_contract' => $responseStyleContract,
        ];
    }
}
