<?php

namespace CRM\Services;

final class FounderAttentionRankingService
{
    /**
     * @param list<array<string,mixed>> $signals
     * @return list<array<string,mixed>>
     */
    public function rank(array $signals, int $limit = 3): array
    {
        $deduped = [];
        foreach ($signals as $index => $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $item = $this->normalize($signal, $index);
            if ($item['title'] === '') {
                continue;
            }
            $key = (string) $item['dedupe_key'];
            if (!isset($deduped[$key])) {
                $deduped[$key] = $item;
                continue;
            }

            if ((float) $item['attention_score'] > (float) $deduped[$key]['attention_score']) {
                $winner = $item;
                $other = $deduped[$key];
            } else {
                $winner = $deduped[$key];
                $other = $item;
            }
            $winner['evidence'] = $this->mergeEvidence((array) $winner['evidence'], (array) $other['evidence']);
            $winner['source_candidates'] = array_values(array_unique(array_filter(array_merge(
                (array) ($winner['source_candidates'] ?? []),
                [(string) ($winner['source_entity_type'] ?? '')],
                [(string) ($other['source_entity_type'] ?? '')]
            ))));
            $deduped[$key] = $winner;
        }

        $ranked = array_values($deduped);
        usort($ranked, static function (array $left, array $right): int {
            $scoreCompare = ((float) ($right['attention_score'] ?? 0)) <=> ((float) ($left['attention_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            $dueLeft = strtotime((string) ($left['due_at'] ?? '')) ?: PHP_INT_MAX;
            $dueRight = strtotime((string) ($right['due_at'] ?? '')) ?: PHP_INT_MAX;
            if ($dueLeft !== $dueRight) {
                return $dueLeft <=> $dueRight;
            }
            $observedCompare = strcmp((string) ($right['observed_at'] ?? ''), (string) ($left['observed_at'] ?? ''));
            return $observedCompare !== 0
                ? $observedCompare
                : strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        return array_slice($ranked, 0, max(1, min(3, $limit)));
    }

    /**
     * @param array<string,mixed> $signal
     * @return array<string,mixed>
     */
    private function normalize(array $signal, int $index): array
    {
        $impact = $this->enum((string) ($signal['impact'] ?? 'medium'), ['low', 'medium', 'high', 'critical'], 'medium');
        $urgency = $this->enum((string) ($signal['urgency'] ?? 'medium'), ['low', 'medium', 'high', 'critical'], 'medium');
        $freshness = $this->enum((string) ($signal['freshness_status'] ?? 'unknown'), ['fresh', 'aging', 'stale', 'unknown'], 'unknown');
        $effort = $this->enum((string) ($signal['effort'] ?? 'medium'), ['low', 'medium', 'high'], 'medium');
        $reversibility = $this->enum((string) ($signal['reversibility'] ?? 'unknown'), ['easy', 'hard', 'irreversible', 'unknown'], 'unknown');
        $confidence = max(0.0, min(1.0, (float) ($signal['confidence'] ?? 0.5)));

        $score = [
            'impact' => ['low' => 8, 'medium' => 18, 'high' => 30, 'critical' => 40][$impact],
            'urgency' => ['low' => 4, 'medium' => 11, 'high' => 20, 'critical' => 25][$urgency],
            'confidence' => round($confidence * 10, 2),
            'freshness' => ['fresh' => 5, 'aging' => 0, 'stale' => -12, 'unknown' => -4][$freshness],
            'human_decision' => !empty($signal['decision_required']) ? 9 : 0,
            'blocked' => !empty($signal['blocked']) ? 12 : 0,
            'constraint' => (string) ($signal['kind'] ?? '') === 'constraint' ? 6 : 0,
            'effort' => ['low' => 4, 'medium' => 0, 'high' => -4][$effort],
            'reversibility' => ['easy' => 2, 'hard' => -2, 'irreversible' => -6, 'unknown' => 0][$reversibility],
            'due' => $this->dueAdjustment((string) ($signal['due_at'] ?? '')),
        ];
        $attentionScore = max(0.0, min(100.0, array_sum($score)));
        $priority = $attentionScore >= 85
            ? 'critical'
            : ($attentionScore >= 68 ? 'high' : ($attentionScore >= 45 ? 'medium' : 'low'));

        $id = trim((string) ($signal['id'] ?? ''));
        if ($id === '') {
            $id = 'attention:' . $index . ':' . substr(hash('sha256', (string) ($signal['title'] ?? '')), 0, 10);
        }
        $dedupeKey = trim((string) ($signal['dedupe_key'] ?? $signal['constraint_key'] ?? $id));

        return [
            'id' => $id,
            'workspace_id' => isset($signal['workspace_id']) ? (int) $signal['workspace_id'] : null,
            'domain' => trim((string) ($signal['domain'] ?? 'operations')),
            'kind' => trim((string) ($signal['kind'] ?? 'decision')),
            'constraint_key' => trim((string) ($signal['constraint_key'] ?? '')),
            'dedupe_key' => $dedupeKey !== '' ? $dedupeKey : $id,
            'title' => trim((string) ($signal['title'] ?? '')),
            'summary' => trim((string) ($signal['summary'] ?? '')),
            'why_now' => trim((string) ($signal['why_now'] ?? '')),
            'impact' => $impact,
            'urgency' => $urgency,
            'confidence' => round($confidence, 4),
            'freshness_status' => $freshness,
            'decision_required' => !empty($signal['decision_required']),
            'blocked' => !empty($signal['blocked']),
            'effort' => $effort,
            'reversibility' => $reversibility,
            'recommended_action' => trim((string) ($signal['recommended_action'] ?? '')),
            'action_label' => trim((string) ($signal['action_label'] ?? 'Open')),
            'action_url' => trim((string) ($signal['action_url'] ?? '')),
            'owner_user_id' => !empty($signal['owner_user_id']) ? (int) $signal['owner_user_id'] : null,
            'due_at' => trim((string) ($signal['due_at'] ?? '')) ?: null,
            'evidence' => array_slice(array_values(array_filter((array) ($signal['evidence'] ?? []), 'is_array')), 0, 5),
            'source_entity_type' => trim((string) ($signal['source_entity_type'] ?? '')),
            'source_entity_id' => trim((string) ($signal['source_entity_id'] ?? '')),
            'observed_at' => trim((string) ($signal['observed_at'] ?? gmdate('c'))),
            'expires_at' => trim((string) ($signal['expires_at'] ?? '')) ?: null,
            'attention_score' => round($attentionScore, 2),
            'score_breakdown' => $score,
            'priority' => $priority,
        ];
    }

    private function dueAdjustment(string $dueAt): int
    {
        $timestamp = $dueAt !== '' ? strtotime($dueAt) : false;
        if ($timestamp === false) {
            return 0;
        }
        $seconds = $timestamp - time();
        if ($seconds < 0) {
            return 8;
        }
        return $seconds <= 172800 ? 4 : 0;
    }

    /**
     * @param list<array<string,mixed>> $left
     * @param list<array<string,mixed>> $right
     * @return list<array<string,mixed>>
     */
    private function mergeEvidence(array $left, array $right): array
    {
        $merged = [];
        foreach (array_merge($left, $right) as $evidence) {
            if (!is_array($evidence)) {
                continue;
            }
            $key = strtolower(trim((string) ($evidence['source'] ?? '') . ':' . (string) ($evidence['label'] ?? '')));
            if ($key === '' || isset($merged[$key])) {
                continue;
            }
            $merged[$key] = $evidence;
        }
        return array_slice(array_values($merged), 0, 5);
    }

    /**
     * @param list<string> $allowed
     */
    private function enum(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
