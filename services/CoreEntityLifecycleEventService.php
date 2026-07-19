<?php

namespace CRM\Services;

use CRM\EventBus;

class CoreEntityLifecycleEventService
{
    public function emit(string $eventName, string $entityType, int $entityId, array $entity = [], array $context = []): void
    {
        $workspaceId = (int) ($context['workspace_id'] ?? $entity['workspace_id'] ?? 0);
        $payload = [
            'workspace_id' => $workspaceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_user_id' => !empty($context['actor_user_id']) ? (int) $context['actor_user_id'] : null,
            'source_skill_key' => (string) ($context['source_skill_key'] ?? $entity['source_skill_key'] ?? ''),
            'source_plugin_key' => (string) ($context['source_plugin_key'] ?? $entity['source_plugin_key'] ?? ''),
            'source_capability_key' => (string) ($context['source_capability_key'] ?? $entity['source_capability_key'] ?? ''),
            'changes' => (array) ($context['changes'] ?? []),
            'metadata' => (array) ($context['metadata'] ?? []),
            $entityType => $entity,
        ];

        if ($entityType === 'task') {
            $payload['task_id'] = $entityId;
            $payload['contact_id'] = $entity['contact_id'] ?? null;
            $payload['task'] = $entity;
        } elseif ($entityType === 'target') {
            $payload['target_id'] = $entityId;
            $payload['target'] = $entity;
        }

        EventBus::publish($eventName, $payload);
    }
}
