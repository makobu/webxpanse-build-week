<?php

namespace CRM\Services;

class AITenantPolicyResolverService
{
    public function __construct(
        private ?AITenantPolicyMemoryService $memoryService = null
    ) {
        $this->memoryService = $this->memoryService ?? new AITenantPolicyMemoryService();
    }

    public function resolve(string $tenantKey, string $domainKey = 'commercial_mvp', array $context = []): array
    {
        $memory = $this->memoryService->getScopeMemory($tenantKey, $domainKey);
        $preferredChannel = $this->pickLeader($memory, 'preferred_channel:');
        $preferredDocumentType = $this->pickLeader($memory, 'document_type:');
        $preferredDealStage = $this->pickLeader($memory, 'deal_stage:');
        $preferredTaskIntent = $this->pickLeader($memory, 'task_intent:');
        $preferredThreadCaution = $this->pickLeader($memory, 'thread_caution:');
        $preferredNextStepTiming = $this->pickLeader($memory, 'next_step_timing_hours:');
        $preferredWorkflowTrigger = $this->pickLeader($memory, 'workflow_trigger:');
        $preferredWorkflowQueueState = $this->pickLeader($memory, 'workflow_queue_state:');
        $preferredWorkflowCustomerFacing = $this->pickLeader($memory, 'workflow_customer_facing:');
        $currentActionKey = (string) ($context['assistant_requested_action'] ?? $context['action_key'] ?? '');
        $actionMemory = $currentActionKey !== '' && isset($memory['action:' . $currentActionKey]) ? $memory['action:' . $currentActionKey] : null;

        $reversalRisk = 0.0;
        if ($actionMemory) {
            $evidence = max(1, (int) ($actionMemory['evidence_count'] ?? 0));
            $reversalRisk = round(((int) ($actionMemory['reversal_count'] ?? 0) + (int) ($actionMemory['edit_count'] ?? 0)) / $evidence, 4);
        }

        return [
            'tenant_key' => $tenantKey,
            'domain_key' => $domainKey,
            'preferred_channel' => $preferredChannel['value']['channel'] ?? null,
            'preferred_document_type' => $preferredDocumentType['value']['document_type'] ?? null,
            'preferred_deal_stage' => $preferredDealStage['value']['deal_stage'] ?? null,
            'preferred_task_intent' => $preferredTaskIntent['value']['task_intent'] ?? null,
            'customer_thread_send_caution_level' => $preferredThreadCaution['value']['thread_caution_level'] ?? null,
            'preferred_next_step_timing_hours' => $preferredNextStepTiming['value']['next_step_timing_hours'] ?? null,
            'preferred_workflow_trigger_type' => $preferredWorkflowTrigger['value']['workflow_trigger_type'] ?? null,
            'preferred_workflow_queue_state' => $preferredWorkflowQueueState['value']['workflow_queue_state'] ?? null,
            'preferred_workflow_customer_facing' => $preferredWorkflowCustomerFacing['value']['workflow_customer_facing'] ?? null,
            'operator_patterns' => $this->collectOperatorPatterns($memory),
            'action_observation' => $actionMemory,
            'reversal_risk' => $reversalRisk,
            'memory' => $memory,
        ];
    }

    private function pickLeader(array $memory, string $prefix): ?array
    {
        $candidates = [];
        foreach ($memory as $key => $row) {
            if (str_starts_with((string) $key, $prefix)) {
                $candidates[] = $row;
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn(array $a, array $b): int => ($b['success_count'] <=> $a['success_count']) ?: ($b['evidence_count'] <=> $a['evidence_count']));
        return $candidates[0];
    }

    private function collectOperatorPatterns(array $memory): array
    {
        $patterns = [];
        foreach ($memory as $key => $row) {
            if (str_starts_with((string) $key, 'operator:')) {
                $patterns[] = [
                    'key' => $key,
                    'value' => $row['value'] ?? [],
                    'evidence_count' => (int) ($row['evidence_count'] ?? 0),
                    'success_count' => (int) ($row['success_count'] ?? 0),
                ];
            }
        }
        usort($patterns, static fn(array $a, array $b): int => ($b['success_count'] <=> $a['success_count']) ?: ($b['evidence_count'] <=> $a['evidence_count']));
        return array_slice($patterns, 0, 5);
    }
}
