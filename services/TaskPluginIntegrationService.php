<?php

namespace CRM\Services;

class TaskPluginIntegrationService
{
    private PluginRuntimeRegistryService $registry;
    private PluginRuntimeEventService $events;

    public function __construct(?PluginRuntimeRegistryService $registry = null, ?PluginRuntimeEventService $events = null)
    {
        $this->registry = $registry ?? new PluginRuntimeRegistryService();
        $this->events = $events ?? new PluginRuntimeEventService();
    }

    public function prepareCreate(int $workspaceId, int $actorUserId, array $taskPayload): array
    {
        $taskPayload = $this->normalizeAttribution($taskPayload);
        foreach ($this->taskCapabilities($workspaceId, $actorUserId) as $capability) {
            $handler = $this->registry->resolveHandler($capability);
            if (!$handler instanceof TaskPluginCapabilityInterface) {
                continue;
            }

            $start = microtime(true);
            try {
                $validation = $handler->validateTaskPayload($capability, $taskPayload, ['workspace_id' => $workspaceId, 'actor_user_id' => $actorUserId]);
                if (empty($validation['allowed'])) {
                    $this->record($workspaceId, $actorUserId, $capability, 'capability_failed', 'blocked', $start, [
                        'reasons' => (array) ($validation['reasons'] ?? ['task_payload_rejected']),
                    ]);
                    throw new \RuntimeException('Task payload was rejected by plugin capability ' . (string) ($capability['capability_key'] ?? ''));
                }
                $before = $taskPayload;
                $taskPayload = $this->protectRestrictedFields($before, $handler->enrichTaskPayload($capability, $taskPayload, [
                    'workspace_id' => $workspaceId,
                    'actor_user_id' => $actorUserId,
                ]));
                if ($before !== $taskPayload) {
                    $this->record($workspaceId, $actorUserId, $capability, 'task_enriched', 'success', $start);
                }
            } catch (\Throwable $e) {
                $this->record($workspaceId, $actorUserId, $capability, 'capability_failed', 'failed', $start, ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        return $taskPayload;
    }

    public function handleLifecycle(string $eventName, int $workspaceId, int $actorUserId, array $taskPayload, array $context = []): void
    {
        foreach ($this->taskCapabilities($workspaceId, $actorUserId) as $capability) {
            $handler = $this->registry->resolveHandler($capability);
            if (!$handler instanceof TaskPluginCapabilityInterface) {
                continue;
            }
            $start = microtime(true);
            try {
                $handler->handleTaskLifecycleEvent($eventName, $capability, $taskPayload, $context);
                $this->record($workspaceId, $actorUserId, $capability, 'capability_succeeded', 'success', $start, ['event_name' => $eventName]);
            } catch (\Throwable $e) {
                $this->record($workspaceId, $actorUserId, $capability, 'capability_failed', 'failed', $start, ['event_name' => $eventName, 'error' => $e->getMessage()]);
            }
        }
    }

    public function normalizeAttribution(array $payload): array
    {
        $metadata = (array) ($payload['metadata_json'] ?? []);
        $skillKey = $this->normalizeKey((string) ($payload['source_skill_key'] ?? $metadata['marketplace_skill_key'] ?? ''));
        $pluginKey = $this->normalizeKey((string) ($payload['source_plugin_key'] ?? $metadata['marketplace_plugin_key'] ?? ''));
        $sourceSurface = $this->normalizeKey((string) ($payload['source_surface'] ?? $metadata['source_surface'] ?? ''));
        $capabilityKey = $this->normalizeCapabilityKey((string) ($payload['source_capability_key'] ?? $metadata['source_capability_key'] ?? ''));
        $runId = (string) ($payload['source_run_id'] ?? $metadata['guidance_run_id'] ?? $metadata['assistant_run_id'] ?? $metadata['commercial_run_id'] ?? '');

        if ($skillKey !== '') {
            $payload['source_skill_key'] = $skillKey;
            $metadata['marketplace_skill_key'] = $metadata['marketplace_skill_key'] ?? $skillKey;
        }
        if ($pluginKey !== '') {
            $payload['source_plugin_key'] = $pluginKey;
            $metadata['marketplace_plugin_key'] = $metadata['marketplace_plugin_key'] ?? $pluginKey;
        }
        if ($sourceSurface !== '') {
            $payload['source_surface'] = $sourceSurface;
            $metadata['source_surface'] = $metadata['source_surface'] ?? $sourceSurface;
        }
        if ($capabilityKey !== '') {
            $payload['source_capability_key'] = $capabilityKey;
            $metadata['source_capability_key'] = $metadata['source_capability_key'] ?? $capabilityKey;
        }
        if ($runId !== '') {
            $payload['source_run_id'] = substr($runId, 0, 120);
        }
        $payload['metadata_json'] = $metadata;

        return $payload;
    }

    private function taskCapabilities(int $workspaceId, int $actorUserId): array
    {
        return array_merge(
            $this->registry->capabilitiesByType($workspaceId, PluginRuntimeRegistryService::TYPE_TASK_PROVIDER, $actorUserId),
            $this->registry->capabilitiesByType($workspaceId, PluginRuntimeRegistryService::TYPE_TASK_ENRICHER, $actorUserId)
        );
    }

    private function protectRestrictedFields(array $before, array $after): array
    {
        foreach (['workspace_id', 'created_by'] as $field) {
            if (array_key_exists($field, $before)) {
                $after[$field] = $before[$field];
            }
        }
        if (array_key_exists('contact_id', $before) && !empty($before['contact_id']) && (int) ($after['contact_id'] ?? 0) !== (int) $before['contact_id']) {
            $after['contact_id'] = $before['contact_id'];
        }

        return $after;
    }

    private function record(int $workspaceId, int $actorUserId, array $capability, string $eventType, string $status, float $start, array $metadata = []): void
    {
        $this->events->record([
            'workspace_id' => $workspaceId,
            'user_id' => $actorUserId,
            'skill_key' => (string) ($capability['skill_key'] ?? ''),
            'capability_key' => (string) ($capability['capability_key'] ?? ''),
            'event_type' => $eventType,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'metadata' => $metadata,
        ]);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim($key)) ?? '');
    }

    private function normalizeCapabilityKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_.]+/', '_', trim($key)) ?? '');
    }
}
