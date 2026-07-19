<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowGraphService
{
    public function loadGraph(int $workflowId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $workflow = $workspaceId > 0
            ? Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId])
            : Database::queryOne("SELECT * FROM workflows WHERE id = ?", [$workflowId]);
        if (!$workflow) {
            throw new \RuntimeException('Workflow not found');
        }

        return $this->loadGraphFromWorkflowRow($workflow);
    }

    public function loadGraphFromWorkflowRow(array $workflow): array
    {
        $graph = json_decode((string) ($workflow['graph_json'] ?? ''), true);
        if (is_array($graph) && !empty($graph['nodes'])) {
            return $this->normalizeGraph($graph, $workflow);
        }

        return $this->migrateLegacyWorkflow($workflow);
    }

    public function migrateLegacyWorkflow(array $workflow): array
    {
        $visualData = is_string($workflow['visual_data'] ?? null)
            ? json_decode($workflow['visual_data'], true)
            : ($workflow['visual_data'] ?? null);

        if (is_array($visualData) && !empty($visualData['nodes'])) {
            $graph = $this->graphFromVisualData($workflow, $visualData);
        } else {
            $graph = $this->graphFromLegacyFields($workflow);
        }

        return $this->normalizeGraph($graph, $workflow);
    }

    public function validateGraph(array $graph): array
    {
        $issues = [];
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];
        $nodeIds = [];
        $triggers = [];
        $incoming = [];

        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '') {
                $issues[] = ['level' => 'error', 'message' => 'Node without ID found'];
                continue;
            }
            $nodeIds[$nodeId] = true;
            if (($node['type'] ?? '') === 'trigger') {
                $triggers[] = $nodeId;
            }
            $incoming[$nodeId] = $incoming[$nodeId] ?? 0;
        }

        if (count($triggers) !== 1) {
            $issues[] = ['level' => 'error', 'message' => 'Workflow must contain exactly one trigger node'];
        }

        foreach ($edges as $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            if ($source === '' || $target === '') {
                $issues[] = ['level' => 'error', 'message' => 'Edge missing source or target'];
                continue;
            }
            if (!isset($nodeIds[$source]) || !isset($nodeIds[$target])) {
                $issues[] = ['level' => 'error', 'message' => "Edge references missing node: {$source} -> {$target}"];
                continue;
            }
            $incoming[$target] = ($incoming[$target] ?? 0) + 1;
        }

        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');
            if ($type !== 'trigger' && $nodeId !== '' && ($incoming[$nodeId] ?? 0) === 0) {
                $issues[] = ['level' => 'error', 'message' => "Orphaned node detected: {$nodeId}"];
            }
            if ($type === 'condition') {
                $config = $node['config'] ?? [];
                if (($config['field'] ?? '') === '' || ($config['operator'] ?? '') === '') {
                    $issues[] = ['level' => 'error', 'message' => "Condition node {$nodeId} is incomplete"];
                }
            }
        }

        if ($this->hasCycle($graph)) {
            $issues[] = ['level' => 'error', 'message' => 'Graph contains a cycle'];
        }

        return [
            'valid' => empty(array_filter($issues, fn ($issue) => $issue['level'] === 'error')),
            'issues' => $issues,
        ];
    }

    public function deriveLegacyPayload(array $graph): array
    {
        $nodes = $graph['nodes'] ?? [];
        $trigger = ['type' => 'contact_created'];
        $conditions = [];
        $actions = [];

        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            $subtype = $node['subtype'] ?? '';
            $config = $node['config'] ?? [];
            if ($type === 'trigger') {
                $trigger = array_merge(['type' => $subtype ?: 'contact_created'], $config);
            } elseif ($type === 'condition') {
                $conditions[] = [
                    'field' => $config['field'] ?? '',
                    'operator' => $config['operator'] ?? '',
                    'value' => $config['value'] ?? null,
                ];
            } elseif ($type === 'action') {
                $actions[] = array_merge(['type' => $subtype], $config);
            } elseif ($type === 'delay') {
                $actions[] = [
                    'type' => 'wait_for_days',
                    'days' => (int) ($config['days'] ?? 1),
                ];
            }
        }

        if (count($conditions) > 1) {
            $conditions = ['operator' => 'AND', 'conditions' => $conditions];
        } elseif (count($conditions) === 1) {
            $conditions = $conditions[0];
        } else {
            $conditions = [];
        }

        return [
            'trigger' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'visual_data' => $this->graphToVisualData($graph),
            'branches' => $this->graphToBranches($graph),
        ];
    }

    public function graphToVisualData(array $graph): array
    {
        return [
            'nodes' => array_map(function (array $node): array {
                return [
                    'id' => $node['id'],
                    'type' => $node['type'],
                    'key' => $node['subtype'] ?? $node['type'],
                    'x' => $node['position']['x'] ?? 100,
                    'y' => $node['position']['y'] ?? 100,
                    'data' => array_merge(['type' => $node['subtype'] ?? null], $node['config'] ?? []),
                ];
            }, $graph['nodes'] ?? []),
            'connections' => array_map(function (array $edge): array {
                $source = $this->edgeSource($edge);
                $target = $this->edgeTarget($edge);
                return [
                    'from' => $source,
                    'to' => $target,
                    'branchType' => $edge['branch'] ?? 'default',
                    'type' => $edge['branch'] ?? 'default',
                ];
            }, $graph['edges'] ?? []),
        ];
    }

    public function graphToBranches(array $graph): array
    {
        return array_map(function (array $edge): array {
            $branch = (string) ($edge['branch'] ?? 'default');
            $isConditional = in_array($branch, ['true', 'false'], true);
            $source = $this->edgeSource($edge);
            $target = $this->edgeTarget($edge);
            return [
                'from' => $source,
                'to' => $target,
                'type' => $isConditional ? 'conditional' : $branch,
                'branchType' => $branch,
                'condition_value' => $isConditional ? $branch : null,
                'order' => $edge['order'] ?? 0,
            ];
        }, $graph['edges'] ?? []);
    }

    public function normalizeForSave(array $graph, array $workflow = []): array
    {
        return $this->normalizeGraph($graph, $workflow);
    }

    private function normalizeGraph(array $graph, array $workflow = []): array
    {
        $workflowName = $workflow['name'] ?? ($graph['meta']['name'] ?? 'Workflow');
        $mode = $workflow['workflow_mode'] ?? ($graph['meta']['mode'] ?? 'mixed');

        $normalizedNodes = [];
        foreach (($graph['nodes'] ?? []) as $index => $node) {
            $type = $node['type'] ?? 'action';
            $subtype = $node['subtype'] ?? ($node['key'] ?? (($node['data']['type'] ?? null) ?: $type));
            $config = $node['config'] ?? ($node['data'] ?? []);
            if ($type === 'delay') {
                $config = ['days' => (int) ($config['days'] ?? 1)];
            }

            $normalizedNodes[] = [
                'id' => (string) ($node['id'] ?? ('node_' . ($index + 1))),
                'type' => $type,
                'subtype' => $subtype,
                'position' => [
                    'x' => (int) ($node['position']['x'] ?? $node['x'] ?? 100),
                    'y' => (int) ($node['position']['y'] ?? $node['y'] ?? 100 + ($index * 120)),
                ],
                'config' => $config,
                'label' => $node['label'] ?? null,
            ];
        }

        $nodeTypesById = [];
        foreach ($normalizedNodes as $node) {
            $nodeTypesById[(string) ($node['id'] ?? '')] = (string) ($node['type'] ?? '');
        }

        $normalizedEdges = [];
        foreach (($graph['edges'] ?? $graph['connections'] ?? []) as $index => $edge) {
            $source = $this->edgeSource($edge);
            $target = $this->edgeTarget($edge);
            if ($source === '' || $target === '') {
                continue;
            }
            $branch = (string) ($edge['branch'] ?? $edge['branchType'] ?? $edge['condition_value'] ?? $edge['type'] ?? 'default');
            if (($nodeTypesById[$source] ?? '') !== 'condition' || !in_array($branch, ['true', 'false'], true)) {
                $branch = 'default';
            }

            $normalizedEdges[] = [
                'id' => (string) ($edge['id'] ?? ('edge_' . ($index + 1))),
                'source' => $source,
                'target' => $target,
                'branch' => $branch,
                'order' => (int) ($edge['order'] ?? $edge['branch_order'] ?? $index),
            ];
        }

        return [
            'version' => 2,
            'meta' => [
                'name' => $workflowName,
                'mode' => in_array($mode, ['crm', 'journey', 'mixed'], true) ? $mode : 'mixed',
            ],
            'nodes' => $normalizedNodes,
            'edges' => $normalizedEdges,
        ];
    }

    private function graphFromVisualData(array $workflow, array $visualData): array
    {
        $edges = [];
        $connections = $visualData['connections'] ?? [];
        if (empty($connections)) {
            $connections = Database::query(
                "SELECT source_node_id, target_node_id, branch_type, condition_value, branch_order
                 FROM workflow_branches WHERE workflow_id = ? ORDER BY branch_order ASC",
                [(int) $workflow['id']]
            );
        }

        foreach ($connections as $index => $connection) {
            $edges[] = [
                'id' => 'edge_' . ($index + 1),
                'source' => $connection['from'] ?? $connection['source_node_id'] ?? '',
                'target' => $connection['to'] ?? $connection['target_node_id'] ?? '',
                'branch' => $connection['branchType'] ?? $connection['condition_value'] ?? $connection['type'] ?? $connection['branch_type'] ?? 'default',
                'order' => (int) ($connection['order'] ?? $connection['branch_order'] ?? $index),
            ];
        }

        return [
            'version' => 2,
            'meta' => [
                'name' => $workflow['name'] ?? 'Workflow',
                'mode' => $workflow['workflow_mode'] ?? 'mixed',
            ],
            'nodes' => $visualData['nodes'] ?? [],
            'edges' => $edges,
        ];
    }

    private function graphFromLegacyFields(array $workflow): array
    {
        $trigger = is_string($workflow['trigger_config'] ?? null)
            ? (json_decode($workflow['trigger_config'], true) ?? [])
            : ($workflow['trigger_config'] ?? []);
        $conditions = is_string($workflow['conditions'] ?? null)
            ? (json_decode($workflow['conditions'], true) ?? [])
            : ($workflow['conditions'] ?? []);
        $actions = is_string($workflow['actions'] ?? null)
            ? (json_decode($workflow['actions'], true) ?? [])
            : ($workflow['actions'] ?? []);

        $nodes = [];
        $edges = [];
        $cursorY = 140;

        $triggerId = 'trigger_1';
        $nodes[] = [
            'id' => $triggerId,
            'type' => 'trigger',
            'subtype' => $trigger['type'] ?? 'contact_created',
            'position' => ['x' => 120, 'y' => $cursorY],
            'config' => $trigger,
        ];

        $previousId = $triggerId;
        $conditionList = [];
        if (!empty($conditions['conditions']) && is_array($conditions['conditions'])) {
            $conditionList = $conditions['conditions'];
        } elseif (!empty($conditions['field'])) {
            $conditionList = [$conditions];
        }

        foreach ($conditionList as $index => $condition) {
            $conditionId = 'condition_' . ($index + 1);
            $cursorY += 140;
            $nodes[] = [
                'id' => $conditionId,
                'type' => 'condition',
                'subtype' => 'field_check',
                'position' => ['x' => 420, 'y' => $cursorY],
                'config' => $condition,
            ];
            $edges[] = [
                'id' => 'edge_' . (count($edges) + 1),
                'source' => $previousId,
                'target' => $conditionId,
                'branch' => $previousId === $triggerId ? 'default' : 'true',
            ];
            $previousId = $conditionId;
        }

        foreach ($actions as $index => $action) {
            $actionType = $action['type'] ?? 'send_email';
            $nodeType = $actionType === 'wait_for_days' ? 'delay' : 'action';
            $actionId = ($nodeType === 'delay' ? 'delay_' : 'action_') . ($index + 1);
            $cursorY += 140;
            $nodes[] = [
                'id' => $actionId,
                'type' => $nodeType,
                'subtype' => $nodeType === 'delay' ? 'wait_for_days' : $actionType,
                'position' => ['x' => 760, 'y' => $cursorY],
                'config' => $nodeType === 'delay' ? ['days' => (int) ($action['days'] ?? 1)] : $action,
            ];
            $edges[] = [
                'id' => 'edge_' . (count($edges) + 1),
                'source' => $previousId,
                'target' => $actionId,
                'branch' => (($nodes[count($nodes) - 2]['type'] ?? '') === 'condition') ? 'true' : 'default',
            ];
            $previousId = $actionId;
        }

        return [
            'version' => 2,
            'meta' => [
                'name' => $workflow['name'] ?? 'Workflow',
                'mode' => $workflow['workflow_mode'] ?? 'mixed',
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function hasCycle(array $graph): bool
    {
        $adjacency = [];
        foreach (($graph['edges'] ?? []) as $edge) {
            $source = $this->edgeSource($edge);
            $target = $this->edgeTarget($edge);
            if ($source === '' || $target === '') {
                continue;
            }
            $adjacency[$source][] = $target;
        }

        $visiting = [];
        $visited = [];
        foreach (($graph['nodes'] ?? []) as $node) {
            $nodeId = $node['id'] ?? null;
            if ($nodeId && $this->visitCycle($nodeId, $adjacency, $visiting, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function edgeSource(array $edge): string
    {
        return (string) ($edge['source'] ?? $edge['from'] ?? $edge['fromNodeId'] ?? $edge['source_node_id'] ?? '');
    }

    private function edgeTarget(array $edge): string
    {
        return (string) ($edge['target'] ?? $edge['to'] ?? $edge['toNodeId'] ?? $edge['target_node_id'] ?? '');
    }

    private function visitCycle(string $nodeId, array $adjacency, array &$visiting, array &$visited): bool
    {
        if (isset($visited[$nodeId])) {
            return false;
        }
        if (isset($visiting[$nodeId])) {
            return true;
        }
        $visiting[$nodeId] = true;
        foreach ($adjacency[$nodeId] ?? [] as $nextNodeId) {
            if ($this->visitCycle($nextNodeId, $adjacency, $visiting, $visited)) {
                return true;
            }
        }
        unset($visiting[$nodeId]);
        $visited[$nodeId] = true;
        return false;
    }
}
