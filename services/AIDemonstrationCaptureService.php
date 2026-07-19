<?php

namespace CRM\Services;

class AIDemonstrationCaptureService
{
    public function __construct(
        private ?AIOperatorDemonstrationService $demonstrations = null,
        private ?AITenantPolicyMemoryService $memory = null,
        private ?AIAutonomyDomainControlService $controls = null
    ) {
        $this->demonstrations = $this->demonstrations ?? new AIOperatorDemonstrationService();
        $this->memory = $this->memory ?? new AITenantPolicyMemoryService();
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
    }

    public function capture(array $payload): int
    {
        $context = (array) ($payload['context'] ?? []);
        $tenantKey = (string) ($payload['tenant_key'] ?? $this->demonstrations->resolveTenantKey($context, !empty($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null));
        $domainKey = (string) ($payload['domain_key'] ?? 'commercial_mvp');
        $control = $this->controls->get($tenantKey, $domainKey);
        if (empty($control['demonstration_capture_enabled'])) {
            return 0;
        }

        $record = [
            'tenant_key' => $tenantKey,
            'actor_user_id' => !empty($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
            'actor_type' => (string) ($payload['actor_type'] ?? 'user'),
            'source_surface' => (string) ($payload['source_surface'] ?? 'manual'),
            'domain_key' => $domainKey,
            'entity_type' => (string) ($payload['entity_type'] ?? 'unknown'),
            'entity_id' => $payload['entity_id'] ?? null,
            'related_entity_type' => $payload['related_entity_type'] ?? null,
            'related_entity_id' => $payload['related_entity_id'] ?? null,
            'action_key' => (string) ($payload['action_key'] ?? 'manual_action'),
            'prior_state' => (array) ($payload['prior_state'] ?? []),
            'action_payload' => (array) ($payload['action_payload'] ?? []),
            'outcome_state' => (array) ($payload['outcome_state'] ?? []),
            'outcome_label' => (string) ($payload['outcome_label'] ?? 'observed'),
            'free_text_reason' => $payload['free_text_reason'] ?? null,
            'metadata' => (array) ($payload['metadata'] ?? []),
            'linked_commercial_run_id' => !empty($payload['linked_commercial_run_id']) ? (int) $payload['linked_commercial_run_id'] : null,
            'linked_approval_id' => !empty($payload['linked_approval_id']) ? (int) $payload['linked_approval_id'] : null,
            'linked_task_id' => !empty($payload['linked_task_id']) ? (int) $payload['linked_task_id'] : null,
            'was_successful' => !empty($payload['was_successful']),
            'was_reversed' => !empty($payload['was_reversed']),
            'was_edited' => !empty($payload['was_edited']),
            'observed_at' => (string) ($payload['observed_at'] ?? date('Y-m-d H:i:s')),
        ];

        $demoId = $this->demonstrations->record($record);
        if ($demoId > 0 && !empty($control['policy_learning_enabled'])) {
            $this->memory->recordActionObservation([
                'tenant_key' => $tenantKey,
                'scope_key' => $domainKey,
                'action_key' => $record['action_key'],
                'channel' => (string) ($record['metadata']['channel'] ?? ''),
                'document_type' => (string) ($record['metadata']['document_type'] ?? ''),
                'deal_stage' => (string) ($record['metadata']['deal_stage'] ?? ''),
                'task_intent' => (string) ($record['metadata']['task_intent'] ?? ''),
                'thread_caution_level' => (string) ($record['metadata']['thread_caution_level'] ?? ''),
                'next_step_timing_hours' => !empty($record['metadata']['next_step_timing_hours']) ? (int) $record['metadata']['next_step_timing_hours'] : 0,
                'workflow_trigger_type' => (string) ($record['metadata']['workflow_trigger_type'] ?? ''),
                'workflow_queue_state' => (string) ($record['metadata']['workflow_queue_state'] ?? ''),
                'workflow_customer_facing' => $record['metadata']['workflow_customer_facing'] ?? null,
                'actor_user_id' => $record['actor_user_id'],
                'was_successful' => $record['was_successful'],
                'was_reversed' => $record['was_reversed'],
                'was_edited' => $record['was_edited'],
                'observed_at' => $record['observed_at'],
            ]);
        }

        return $demoId;
    }
}
