<?php

namespace CRM\Services;

final class FounderContextAttentionSignalProvider implements FounderAttentionSignalProviderInterface
{
    public function provide(int $workspaceId, int $userId, array $snapshot): array
    {
        $signals = [];
        $health = (array) ($snapshot['health'] ?? []);
        $healthStatus = (string) ($health['status'] ?? 'building');
        $missing = array_values(array_filter((array) ($snapshot['missing_context'] ?? []), 'is_array'));

        if ($missing !== [] && in_array($healthStatus, ['blocked', 'building'], true)) {
            $first = (array) $missing[0];
            $blocked = $healthStatus === 'blocked';
            $signals[] = [
                'id' => 'context:missing-foundation',
                'domain' => 'business_context',
                'kind' => 'context_confirmation',
                'constraint_key' => 'business_context_incomplete',
                'dedupe_key' => 'business_context:missing',
                'title' => $blocked ? 'Complete the business foundation' : 'Confirm the remaining business context',
                'summary' => (string) ($first['label'] ?? 'A core business assumption still needs confirmation.'),
                'why_now' => $blocked
                    ? 'The system cannot rank the next business move reliably until this foundation is clear.'
                    : 'Confirming this prevents recommendations from relying on an incomplete assumption.',
                'impact' => $blocked ? 'high' : 'medium',
                'urgency' => $blocked ? 'high' : 'low',
                'confidence' => 1.0,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => $blocked,
                'effort' => 'low',
                'reversibility' => 'easy',
                'recommended_action' => 'Review the next incomplete Clarity Journey stage.',
                'action_label' => 'Continue Clarity Journey',
                'action_url' => 'startup_journey.php',
                'owner_user_id' => $userId,
                'evidence' => array_slice(array_map(static fn(array $item): array => [
                    'label' => (string) ($item['label'] ?? 'Missing context'),
                    'source' => (string) ($item['source_key'] ?? 'business_context'),
                ], $missing), 0, 3),
                'source_entity_type' => 'business_context_snapshot',
                'source_entity_id' => (string) ($snapshot['snapshot_id'] ?? ''),
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        $staleSources = [];
        foreach (['strategy_snapshot', 'startup_journey'] as $sourceKey) {
            $source = (array) ($snapshot['source_versions'][$sourceKey] ?? []);
            if ((string) ($source['freshness_status'] ?? '') === 'stale') {
                $source['key'] = $sourceKey;
                $staleSources[] = $source;
            }
        }
        if ($staleSources !== []) {
            $signals[] = [
                'id' => 'context:stale-foundation',
                'domain' => 'business_context',
                'kind' => 'context_confirmation',
                'constraint_key' => 'business_context_stale',
                'dedupe_key' => 'business_context:stale',
                'title' => 'Confirm the business context still holds',
                'summary' => 'A saved customer, offer, or Journey assumption is old enough to need a quick human check.',
                'why_now' => 'Fresh confirmation keeps the next constraint and growth move grounded in the business as it operates now.',
                'impact' => 'medium',
                'urgency' => 'medium',
                'confidence' => 1.0,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => false,
                'effort' => 'low',
                'reversibility' => 'easy',
                'recommended_action' => 'Review the saved business assumptions and confirm or update what changed.',
                'action_label' => 'Review business context',
                'action_url' => 'startup_journey.php',
                'owner_user_id' => $userId,
                'evidence' => array_map(static fn(array $source): array => [
                    'label' => str_replace('_', ' ', (string) ($source['key'] ?? 'saved context')),
                    'source' => (string) ($source['source'] ?? $source['key'] ?? 'business_context'),
                    'observed_at' => $source['observed_at'] ?? null,
                ], $staleSources),
                'source_entity_type' => 'business_context_snapshot',
                'source_entity_id' => (string) ($snapshot['snapshot_id'] ?? ''),
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        foreach (array_slice(array_values(array_filter((array) ($snapshot['conflicts'] ?? []), 'is_array')), 0, 3) as $index => $conflict) {
            $severity = (string) ($conflict['severity'] ?? 'medium');
            $type = trim((string) ($conflict['type'] ?? ('assumption_' . $index)));
            $signals[] = [
                'id' => 'context:conflict:' . ($type !== '' ? $type : $index),
                'domain' => 'business_context',
                'kind' => 'context_confirmation',
                'constraint_key' => 'assumption_conflict:' . ($type !== '' ? $type : $index),
                'dedupe_key' => 'business_context:conflict:' . ($type !== '' ? $type : $index),
                'title' => 'Resolve a business assumption conflict',
                'summary' => (string) ($conflict['operating_evidence'] ?? 'Live evidence differs from a saved business assumption.'),
                'why_now' => (string) ($conflict['journey_assumption'] ?? 'The saved strategy and current operating evidence do not agree.'),
                'impact' => $severity === 'high' ? 'high' : 'medium',
                'urgency' => $severity === 'high' ? 'high' : 'medium',
                'confidence' => 0.9,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => false,
                'effort' => 'low',
                'reversibility' => 'easy',
                'recommended_action' => (string) ($conflict['suggested_next_action'] ?? 'Review and confirm the current assumption.'),
                'action_label' => 'Review assumption',
                'action_url' => 'startup_journey.php',
                'owner_user_id' => $userId,
                'evidence' => [[
                    'label' => (string) ($conflict['operating_evidence'] ?? 'Conflicting operating evidence'),
                    'source' => 'assumption_conflict_detector',
                ]],
                'source_entity_type' => 'assumption_conflict',
                'source_entity_id' => $type,
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        return $signals;
    }
}
