<?php

namespace CRM\Services;

class AIRetrievalQualityService
{
    public function scoreBundle(array $bundle): array
    {
        $blocks = (array) ($bundle['blocks'] ?? []);
        if ($blocks === []) {
            return [
                'context_quality_score' => 0.25,
                'avg_relevance' => 0.0,
                'stale_block_count' => 0,
                'trimmed_block_count' => (int) ($bundle['bundle_quality']['trimmed_block_count'] ?? 0),
                'truncated_required_count' => (int) ($bundle['bundle_quality']['truncated_required_count'] ?? 0),
                'overload_risk' => 'low',
                'priority_mix' => (array) ($bundle['bundle_quality']['priority_mix'] ?? []),
                'warnings' => ['empty_context_bundle'],
            ];
        }

        $avgRelevance = array_sum(array_map(
            static fn(array $block): float => (float) ($block['relevance_score'] ?? 0.0),
            $blocks
        )) / max(1, count($blocks));
        $stale = $this->detectStaleContext($bundle);
        $overload = $this->detectOverloadedPrompt($bundle);
        $lowSignal = $this->detectLowSignalBundle($bundle);
        $trimmed = (int) ($bundle['bundle_quality']['trimmed_block_count'] ?? 0);
        $priorityMix = $this->buildPriorityMix($blocks, (array) ($bundle['bundle_quality']['priority_mix'] ?? []));
        $discardedRequired = (int) ($bundle['bundle_quality']['discarded_required_count'] ?? 0);
        $truncatedRequired = (int) ($bundle['bundle_quality']['truncated_required_count'] ?? 0);

        $score = $avgRelevance;
        $score -= min(0.25, count($stale['stale_blocks']) * 0.07);
        $score -= $overload['overload_risk'] === 'high' ? 0.20 : ($overload['overload_risk'] === 'medium' ? 0.10 : 0.0);
        $score -= $lowSignal['low_signal'] ? 0.15 : 0.0;
        $score -= min(0.10, $trimmed * 0.02);
        $score += (($priorityMix['required'] ?? 0) > 0) ? 0.05 : -0.08;
        $score += (($priorityMix['preferred'] ?? 0) > 0) ? 0.03 : 0.0;
        $score -= min(0.10, $discardedRequired * 0.10);

        return [
            'context_quality_score' => max(0.0, min(1.0, $score)),
            'avg_relevance' => round($avgRelevance, 4),
            'stale_block_count' => count($stale['stale_blocks']),
            'trimmed_block_count' => $trimmed,
            'truncated_required_count' => $truncatedRequired,
            'overload_risk' => $overload['overload_risk'],
            'priority_mix' => $priorityMix,
            'warnings' => array_values(array_unique(array_merge(
                $stale['warnings'],
                $overload['warnings'],
                $lowSignal['warnings'],
                $discardedRequired > 0 ? ['required_context_trimmed'] : [],
                $truncatedRequired > 0 ? ['required_context_truncated'] : [],
                $trimmed > 0 ? ['bundle_trimmed'] : []
            ))),
        ];
    }

    public function detectStaleContext(array $bundle): array
    {
        $staleBlocks = [];
        foreach ((array) ($bundle['blocks'] ?? []) as $block) {
            $freshness = (int) ($block['freshness_seconds'] ?? 0);
            $priority = (string) ($block['block_priority'] ?? 'optional');
            $threshold = $priority === 'required' ? 172800 : 86400;
            if ($freshness > $threshold) {
                $staleBlocks[] = $block['label'] ?? $block['type'] ?? 'context_block';
            }
        }

        return [
            'stale_blocks' => $staleBlocks,
            'warnings' => $staleBlocks ? ['stale_context_present'] : [],
        ];
    }

    public function detectOverloadedPrompt(array $bundle): array
    {
        $chars = 0;
        $discardableCount = 0;
        foreach ((array) ($bundle['blocks'] ?? []) as $block) {
            $chars += strlen((string) json_encode($block['content'] ?? []));
            if (($block['block_priority'] ?? 'optional') === 'discardable') {
                $discardableCount++;
            }
        }

        $risk = 'low';
        if (
            $chars > 12000
            || count((array) ($bundle['blocks'] ?? [])) > 10
            || (int) ($bundle['bundle_quality']['trimmed_block_count'] ?? 0) >= 3
            || $discardableCount >= 3
        ) {
            $risk = 'medium';
        }
        if (
            $chars > 18000
            || count((array) ($bundle['blocks'] ?? [])) > 14
            || (int) ($bundle['bundle_quality']['trimmed_block_count'] ?? 0) >= 6
            || $discardableCount >= 5
        ) {
            $risk = 'high';
        }

        return [
            'overload_risk' => $risk,
            'warnings' => $risk !== 'low' ? ['prompt_overload'] : [],
        ];
    }

    public function detectLowSignalBundle(array $bundle): array
    {
        $blocks = (array) ($bundle['blocks'] ?? []);
        if ($blocks === []) {
            return ['low_signal' => true, 'warnings' => ['low_signal_context']];
        }

        $highSignal = array_filter($blocks, static fn(array $block): bool => (float) ($block['relevance_score'] ?? 0) >= 0.55);
        $requiredCount = count(array_filter($blocks, static fn(array $block): bool => ($block['block_priority'] ?? 'optional') === 'required'));
        $preferredCount = count(array_filter($blocks, static fn(array $block): bool => ($block['block_priority'] ?? 'optional') === 'preferred'));
        $lowSignal = count($highSignal) === 0 || ($requiredCount === 0 && $preferredCount === 0);

        return [
            'low_signal' => $lowSignal,
            'warnings' => $lowSignal ? ['low_signal_context', 'low_signal_context_bundle'] : [],
        ];
    }

    private function buildPriorityMix(array $blocks, array $existingMix): array
    {
        $mix = [
            'required' => 0,
            'preferred' => 0,
            'optional' => 0,
            'discardable' => 0,
        ];

        foreach ($blocks as $block) {
            $priority = (string) ($block['block_priority'] ?? 'optional');
            if (!array_key_exists($priority, $mix)) {
                $mix[$priority] = 0;
            }
            $mix[$priority]++;
        }

        foreach ($existingMix as $priority => $count) {
            if (!array_key_exists($priority, $mix)) {
                $mix[$priority] = 0;
            }
            $mix[$priority] = max($mix[$priority], (int) $count);
        }

        return array_filter($mix, static fn(int $count): bool => $count > 0);
    }
}
