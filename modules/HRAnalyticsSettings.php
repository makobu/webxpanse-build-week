<?php

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceScopeService;

class HRAnalyticsSettings
{
    private const DEFAULTS = [
        'id' => 1,
        'workspace_id' => 1,
        'ai_enabled' => true,
        'scoring_weights' => [
            'marketing' => [
                'task_completion' => 0.18,
                'timeliness' => 0.12,
                'activity_consistency' => 0.18,
                'outcome_impact' => 0.17,
                'pipeline_movement' => 0.05,
                'campaign_output' => 0.20,
                'workload_balance' => 0.10,
            ],
            'sales' => [
                'task_completion' => 0.18,
                'timeliness' => 0.14,
                'activity_consistency' => 0.13,
                'outcome_impact' => 0.20,
                'pipeline_movement' => 0.22,
                'campaign_output' => 0.03,
                'workload_balance' => 0.10,
            ],
            'general' => [
                'task_completion' => 0.26,
                'timeliness' => 0.19,
                'activity_consistency' => 0.14,
                'outcome_impact' => 0.12,
                'pipeline_movement' => 0.04,
                'campaign_output' => 0.03,
                'workload_balance' => 0.22,
            ],
        ],
        'thresholds' => [
            'high_performer' => 75,
            'at_risk' => 45,
            'needs_coaching' => 55,
            'overloaded_task_count' => 7,
            'inactive_days' => 10,
        ],
        'department_mappings' => [
            'admin' => 'Leadership',
            'owner' => 'Leadership',
            'marketing' => 'Marketing',
            'sales' => 'Sales',
            'viewer' => 'Operations',
        ],
        'prompt_config' => [
            'manager_focus' => 'Keep recommendations concrete, explainable, and tied to observable team behaviour.',
            'swot_focus' => 'Evaluate people execution, coordination, role fit, workload balance, and momentum.',
        ],
    ];

    public function get(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = Database::queryOne("SELECT * FROM hr_analytics_settings WHERE workspace_id = ?", [$workspaceId]);
        if (!$row) {
            return array_merge(self::DEFAULTS, ['workspace_id' => $workspaceId]);
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => $workspaceId,
            'ai_enabled' => !empty($row['ai_enabled']),
            'scoring_weights' => $this->normalizeWeights($this->decodeJson($row['scoring_weights_json'] ?? null, self::DEFAULTS['scoring_weights'])),
            'thresholds' => $this->normalizeThresholds($this->decodeJson($row['thresholds_json'] ?? null, self::DEFAULTS['thresholds'])),
            'department_mappings' => $this->normalizeDepartmentMappings($this->decodeJson($row['department_mappings_json'] ?? null, self::DEFAULTS['department_mappings'])),
            'prompt_config' => $this->normalizePromptConfig($this->decodeJson($row['prompt_config_json'] ?? null, self::DEFAULTS['prompt_config'])),
        ];
    }

    public function save(array $data, ?int $updatedBy = null, ?int $workspaceId = null): void
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $current = $this->get($workspaceId);
        $settings = [
            'workspace_id' => $workspaceId,
            'ai_enabled' => array_key_exists('ai_enabled', $data) ? !empty($data['ai_enabled']) : $current['ai_enabled'],
            'scoring_weights' => $this->normalizeWeights($data['scoring_weights'] ?? $current['scoring_weights']),
            'thresholds' => $this->normalizeThresholds($data['thresholds'] ?? $current['thresholds'], true),
            'department_mappings' => $this->normalizeDepartmentMappings($data['department_mappings'] ?? $current['department_mappings']),
            'prompt_config' => $this->normalizePromptConfig($data['prompt_config'] ?? $current['prompt_config']),
        ];

        $params = [
            $settings['workspace_id'],
            $settings['ai_enabled'] ? 1 : 0,
            json_encode($settings['scoring_weights']),
            json_encode($settings['thresholds']),
            json_encode($settings['department_mappings']),
            json_encode($settings['prompt_config']),
            $updatedBy,
        ];

        Database::execute(
            "INSERT INTO hr_analytics_settings (
                workspace_id, ai_enabled, scoring_weights_json, thresholds_json, department_mappings_json, prompt_config_json, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ai_enabled = VALUES(ai_enabled),
                scoring_weights_json = VALUES(scoring_weights_json),
                thresholds_json = VALUES(thresholds_json),
                department_mappings_json = VALUES(department_mappings_json),
                prompt_config_json = VALUES(prompt_config_json),
                updated_by = VALUES(updated_by)",
            $params
        );
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId($workspaceId);
    }

    private function decodeJson(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function normalizeWeights(array $weights): array
    {
        $result = self::DEFAULTS['scoring_weights'];
        foreach ($result as $role => $defaults) {
            $source = isset($weights[$role]) && is_array($weights[$role]) ? $weights[$role] : [];
            $normalized = [];
            $sum = 0.0;
            foreach ($defaults as $metric => $defaultValue) {
                $value = max(0.0, min(1.0, (float) ($source[$metric] ?? $defaultValue)));
                $normalized[$metric] = $value;
                $sum += $value;
            }
            if ($sum <= 0.0) {
                $normalized = $defaults;
                $sum = array_sum($normalized);
            }
            foreach ($normalized as $metric => $value) {
                $normalized[$metric] = round($value / $sum, 4);
            }
            $result[$role] = $normalized;
        }
        return $result;
    }

    private function normalizeThresholds(array $thresholds, bool $strict = false): array
    {
        $normalized = [
            'high_performer' => max(50, min(100, (int) ($thresholds['high_performer'] ?? self::DEFAULTS['thresholds']['high_performer']))),
            'at_risk' => max(0, min(80, (int) ($thresholds['at_risk'] ?? self::DEFAULTS['thresholds']['at_risk']))),
            'needs_coaching' => max(0, min(90, (int) ($thresholds['needs_coaching'] ?? self::DEFAULTS['thresholds']['needs_coaching']))),
            'overloaded_task_count' => max(1, min(50, (int) ($thresholds['overloaded_task_count'] ?? self::DEFAULTS['thresholds']['overloaded_task_count']))),
            'inactive_days' => max(1, min(60, (int) ($thresholds['inactive_days'] ?? self::DEFAULTS['thresholds']['inactive_days']))),
        ];
        if (!($normalized['at_risk'] < $normalized['needs_coaching'] && $normalized['needs_coaching'] < $normalized['high_performer'])) {
            if ($strict) {
                throw new \InvalidArgumentException('Thresholds must satisfy: at risk < needs coaching < high performer.');
            }
            $normalized['at_risk'] = self::DEFAULTS['thresholds']['at_risk'];
            $normalized['needs_coaching'] = self::DEFAULTS['thresholds']['needs_coaching'];
            $normalized['high_performer'] = self::DEFAULTS['thresholds']['high_performer'];
        }
        return $normalized;
    }

    private function normalizeDepartmentMappings(array $mappings): array
    {
        $result = self::DEFAULTS['department_mappings'];
        foreach ($mappings as $key => $value) {
            $role = strtolower(trim((string) $key));
            $department = trim((string) $value);
            if ($role !== '' && $department !== '') {
                $result[$role] = $department;
            }
        }
        return $result;
    }

    private function normalizePromptConfig(array $config): array
    {
        return [
            'manager_focus' => trim((string) ($config['manager_focus'] ?? self::DEFAULTS['prompt_config']['manager_focus'])),
            'swot_focus' => trim((string) ($config['swot_focus'] ?? self::DEFAULTS['prompt_config']['swot_focus'])),
        ];
    }
}
