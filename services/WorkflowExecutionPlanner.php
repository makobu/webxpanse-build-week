<?php

namespace CRM\Services;

class WorkflowExecutionPlanner
{
    public function planExecution(array $graph, array $context): array
    {
        $nodeMap = [];
        $adjacency = [];
        $triggerId = null;

        foreach (($graph['nodes'] ?? []) as $node) {
            $nodeMap[$node['id']] = $node;
            if (($node['type'] ?? '') === 'trigger' && $triggerId === null) {
                $triggerId = $node['id'];
            }
        }

        foreach (($graph['edges'] ?? []) as $edge) {
            $adjacency[$edge['source']][] = $edge;
        }

        if ($triggerId === null) {
            return ['nodes' => [], 'branches' => []];
        }

        $plannedNodes = [];
        $branches = [];
        $visited = [];

        $walk = function (string $nodeId) use (&$walk, &$plannedNodes, &$branches, &$visited, $adjacency, $nodeMap, $context): void {
            if (isset($visited[$nodeId]) || !isset($nodeMap[$nodeId])) {
                return;
            }
            $visited[$nodeId] = true;
            $node = $nodeMap[$nodeId];
            $plannedNodes[] = $node;

            if (($node['type'] ?? '') === 'condition') {
                $config = $node['config'] ?? [];
                $left = $context[$config['field'] ?? ''] ?? null;
                $result = $this->evaluateCondition($left, $config['operator'] ?? 'equals', $config['value'] ?? null);
                $branch = $result ? 'true' : 'false';
                $branches[$nodeId] = $branch;
                foreach ($adjacency[$nodeId] ?? [] as $edge) {
                    if (($edge['branch'] ?? 'default') === $branch) {
                        $walk($edge['target']);
                        return;
                    }
                }
            }

            foreach ($adjacency[$nodeId] ?? [] as $edge) {
                if (($node['type'] ?? '') === 'condition' && !in_array($edge['branch'] ?? 'default', ['default', $branches[$nodeId] ?? null], true)) {
                    continue;
                }
                $walk($edge['target']);
            }
        };

        $walk($triggerId);

        return [
            'nodes' => $plannedNodes,
            'branches' => $branches,
        ];
    }

    private function evaluateCondition($left, string $operator, $right): bool
    {
        return match ($operator) {
            'equals' => $left == $right,
            'not_equals' => $left != $right,
            'exists' => !empty($left),
            'contains' => is_string($left) && str_contains($left, (string) $right),
            'gt' => (float) $left > (float) $right,
            'gte' => (float) $left >= (float) $right,
            'lt' => (float) $left < (float) $right,
            'lte' => (float) $left <= (float) $right,
            default => false,
        };
    }
}
