<?php

namespace CRM\Services;

use CRM\Database;

class AIActionSimilarityService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function findSimilarExamples(
        string $tenantKey,
        string $domainKey,
        string $actionKey,
        array $context = [],
        int $limit = 5
    ): array {
        if (!$this->tableExists('ai_action_similarity_index')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        $rows = Database::query(
            "SELECT s.*, d.action_payload_json, d.metadata_json, d.outcome_state_json, d.outcome_label, d.observed_at, d.was_successful
             FROM ai_action_similarity_index s
             INNER JOIN ai_operator_demonstrations d ON d.id = s.demonstration_id
             WHERE s.domain_key = ? AND s.action_key = ? AND s.outcome_label IN ('accepted', 'approved', 'observed')
               AND s.workspace_id = ?
             ORDER BY d.observed_at DESC
             LIMIT " . max(1, min(20, $limit * 4)),
            [$domainKey, $actionKey, $workspaceId]
        );

        $target = [
            'document_type' => (string) ($context['document_type'] ?? ($context['invoice']['document_type'] ?? '')),
            'channel' => (string) ($context['channel'] ?? ''),
            'deal_stage' => (string) ($context['deal']['stage'] ?? ''),
            'source_surface' => (string) ($context['surface'] ?? 'commercial_automation'),
            'task_status' => (string) ($context['task']['status'] ?? ($context['task_status'] ?? '')),
            'task_intent' => (string) ($context['task_intent'] ?? ''),
            'thread_goal' => (string) ($context['assistant_requested_action'] ?? ''),
            'workflow_trigger_type' => (string) ($context['workflow_trigger_type'] ?? ''),
            'workflow_action_type' => (string) ($context['workflow_action_type'] ?? $actionKey),
            'workflow_queue_state' => (string) ($context['workflow_queue_state'] ?? ''),
            'workflow_customer_facing' => isset($context['workflow_customer_facing']) ? (int) !empty($context['workflow_customer_facing']) : null,
        ];

        $examples = [];
        foreach ($rows as $row) {
            $feature = $this->decodeJson($row['feature_summary_json'] ?? null);
            $score = 0.35 + (float) ($row['score'] ?? 0);
            foreach (['document_type', 'channel', 'deal_stage', 'source_surface', 'task_status', 'task_intent', 'thread_goal', 'workflow_trigger_type', 'workflow_action_type', 'workflow_queue_state'] as $field) {
                if (($target[$field] ?? '') !== '' && ($feature[$field] ?? '') === $target[$field]) {
                    $score += 0.15;
                }
            }
            if ($target['workflow_customer_facing'] !== null && (int) ($feature['workflow_customer_facing'] ?? -1) === $target['workflow_customer_facing']) {
                $score += 0.12;
            }
            $examples[] = [
                'demonstration_id' => (int) $row['demonstration_id'],
                'tenant_key' => (string) $row['tenant_key'],
                'score' => round(min(0.995, $score), 4),
                'feature_summary' => $feature,
                'action_payload' => $this->decodeJson($row['action_payload_json'] ?? null),
                'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
                'outcome_state' => $this->decodeJson($row['outcome_state_json'] ?? null),
                'outcome_label' => (string) ($row['outcome_label'] ?? 'observed'),
                'observed_at' => (string) ($row['observed_at'] ?? ''),
                'was_successful' => !empty($row['was_successful']),
            ];
        }

        usort($examples, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($examples, 0, max(1, min(20, $limit)));
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
