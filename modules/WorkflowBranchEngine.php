<?php
/**
 * Workflow Branch Engine
 * Builds execution graph from visual_data and workflow_branches, traverses for execution
 */

namespace CRM\Modules;

use CRM\Database;

class WorkflowBranchEngine
{
    private ConditionEvaluator $conditionEvaluator;

    public function __construct()
    {
        $this->conditionEvaluator = new ConditionEvaluator();
    }

    /**
     * Build execution graph from workflow visual_data and branches
     *
     * @param array $workflow Workflow row with visual_data (JSON decoded), and optionally branches
     * @param array $branches Rows from workflow_branches (source_node_id, target_node_id, branch_type, condition_value)
     * @return array Graph: [nodeId => ['type' => trigger|condition|action|delay, 'config' => ..., 'outgoing' => [...]]]
     */
    public function buildExecutionGraph(array $workflow, array $branches = []): array
    {
        $graph = [];
        $visualData = is_string($workflow['visual_data'] ?? null)
            ? json_decode($workflow['visual_data'], true)
            : ($workflow['visual_data'] ?? null);

        if (!$visualData || empty($visualData['nodes'])) {
            return [];
        }

        $nodes = $visualData['nodes'];
        $connections = $branches;

        // If branches from DB, convert to connection format
        if (!empty($branches) && isset($branches[0]['source_node_id'])) {
            $connections = array_map(function ($b) {
                return [
                    'from' => $b['source_node_id'],
                    'to' => $b['target_node_id'],
                    'branchType' => $b['condition_value'] ?? ($b['branch_type'] === 'conditional' ? null : $b['branch_type']),
                    'type' => $b['branch_type']
                ];
            }, $branches);
        } elseif (!empty($visualData['connections'])) {
            $connections = $visualData['connections'];
        }

        // Build node map
        foreach ($nodes as $node) {
            $nodeId = $node['id'] ?? null;
            if (!$nodeId) {
                continue;
            }

            $type = $node['type'] ?? 'action';
            $key = $node['key'] ?? ($node['data']['type'] ?? '');
            $data = $node['data'] ?? [];

            if ($type === 'delay') {
                $type = 'action';
                $data = ['type' => 'wait_for_days', 'days' => $data['days'] ?? 1];
            } elseif ($type === 'action' && empty($data['type']) && $key) {
                $data['type'] = $key;
            }

            $graph[$nodeId] = [
                'type' => $type,
                'key' => $key,
                'config' => $data,
                'outgoing' => []
            ];
        }

        // Build outgoing edges
        foreach ($connections as $conn) {
            $from = $conn['from'] ?? $conn['source_node_id'] ?? null;
            $to = $conn['to'] ?? $conn['target_node_id'] ?? null;
            $branchType = $conn['branchType'] ?? $conn['condition_value'] ?? 'success';
            $type = $conn['type'] ?? 'success';

            if (!$from || !$to || !isset($graph[$from])) {
                continue;
            }

            $graph[$from]['outgoing'][] = [
                'target' => $to,
                'branchType' => $branchType,
                'type' => $type
            ];
        }

        return $graph;
    }

    /**
     * Get execution path (ordered list of nodes to execute)
     * Traverses graph from trigger, following branches based on condition results
     *
     * @param array $graph From buildExecutionGraph
     * @param array $context Event data for condition evaluation
     * @param bool $dryRun If true, don't execute actions, just return path
     * @return array ['path' => [...], 'conditionResults' => [nodeId => bool]]
     */
    public function getExecutionPath(array $graph, array $context, bool $dryRun = false): array
    {
        $path = [];
        $conditionResults = [];
        $visited = [];
        $triggerId = null;

        foreach ($graph as $nodeId => $node) {
            if (($node['type'] ?? '') === 'trigger') {
                $triggerId = $nodeId;
                break;
            }
        }

        if (!$triggerId) {
            return ['path' => [], 'conditionResults' => []];
        }

        $this->traverse($graph, $triggerId, $context, $path, $conditionResults, $visited);

        return ['path' => $path, 'conditionResults' => $conditionResults];
    }

    /**
     * Traverse graph from node, appending action nodes to path
     */
    private function traverse(
        array $graph,
        string $nodeId,
        array $context,
        array &$path,
        array &$conditionResults,
        array &$visited
    ): void {
        if (isset($visited[$nodeId])) {
            return;
        }
        $visited[$nodeId] = true;

        $node = $graph[$nodeId] ?? null;
        if (!$node) {
            return;
        }

        $type = $node['type'] ?? '';

        if ($type === 'condition') {
            $conditions = $this->extractConditionsFromNode($node);
            $result = $this->conditionEvaluator->evaluateConditions($conditions, $context);
            $conditionResults[$nodeId] = $result;

            $branchToFollow = $result ? 'true' : 'false';

            foreach ($node['outgoing'] ?? [] as $out) {
                $outBranch = (string)($out['branchType'] ?? $out['condition_value'] ?? $out['type'] ?? '');
                if ($outBranch === $branchToFollow) {
                    $this->traverse($graph, $out['target'], $context, $path, $conditionResults, $visited);
                    return;
                }
            }
            $first = $node['outgoing'][0] ?? null;
            if ($first) {
                $this->traverse($graph, $first['target'], $context, $path, $conditionResults, $visited);
            }
            return;
        }

        if ($type === 'action') {
            $path[] = ['nodeId' => $nodeId, 'config' => $node['config'], 'type' => 'action'];
        }

        foreach ($node['outgoing'] ?? [] as $out) {
            $this->traverse($graph, $out['target'], $context, $path, $conditionResults, $visited);
        }
    }

    /**
     * Extract ConditionEvaluator format from condition node config
     */
    private function extractConditionsFromNode(array $node): array
    {
        $config = $node['config'] ?? [];
        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'equals';
        $value = $config['value'] ?? '';

        if (empty($field)) {
            return [];
        }

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $value
        ];
    }

    /**
     * Get next nodes from a node (for single-step traversal)
     *
     * @param string $nodeId
     * @param array $graph
     * @param bool|null $conditionResult For condition nodes: true=Yes, false=No
     * @param bool $actionSuccess For action nodes: true=success, false=failure
     * @return array List of next node IDs
     */
    public function getNextNodes(array $graph, string $nodeId, ?bool $conditionResult = null, bool $actionSuccess = true): array
    {
        $node = $graph[$nodeId] ?? null;
        if (!$node || empty($node['outgoing'])) {
            return [];
        }

        $type = $node['type'] ?? '';

        if ($type === 'condition' && $conditionResult !== null) {
            $branchToFollow = $conditionResult ? 'true' : 'false';
            foreach ($node['outgoing'] as $out) {
                $outBranch = $out['branchType'] ?? $out['type'] ?? 'success';
                if ($outBranch === $branchToFollow || ($branchToFollow === 'true' && in_array($outBranch, ['success', 'true']))) {
                    return [$out['target']];
                }
            }
            foreach ($node['outgoing'] as $out) {
                if (($out['branchType'] ?? '') === $branchToFollow) {
                    return [$out['target']];
                }
            }
        }

        if ($type === 'action') {
            $followType = $actionSuccess ? 'success' : 'failure';
            foreach ($node['outgoing'] as $out) {
                if (($out['type'] ?? $out['branchType'] ?? 'success') === $followType) {
                    return [$out['target']];
                }
            }
        }

        return array_map(fn($o) => $o['target'], $node['outgoing']);
    }
}
