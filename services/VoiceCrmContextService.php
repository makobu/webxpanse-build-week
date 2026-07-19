<?php

namespace CRM\Services;

use CRM\Database;

class VoiceCrmContextService
{
    /**
     * Persist exactly one contact activity and one communication for a
     * completed matched call. A later AI insight upgrades the same records.
     */
    public function sync(int $workspaceId, int $callId, ?array $insight = null): array
    {
        if ($workspaceId <= 0 || $callId <= 0) {
            return [];
        }
        Database::beginTransaction();
        try {
            $call = Database::queryOne(
                "SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ? AND state = 'completed' FOR UPDATE",
                [$workspaceId, $callId]
            );
            if (!$call || (int) ($call['contact_id'] ?? 0) <= 0) {
                Database::commit();
                return [];
            }
            $contactId = (int) $call['contact_id'];
            if (!Database::queryOne('SELECT id FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1', [$workspaceId, $contactId])) {
                Database::commit();
                return [];
            }
            $hasInsight = is_array($insight) && trim((string) ($insight['summary'] ?? '')) !== '';
            $summary = $hasInsight ? trim((string) $insight['summary']) : $this->fallbackSummary($call);
            $metadata = json_encode([
                'voice_call_id' => $callId,
                'voice_insight' => $hasInsight,
                'confidence' => $hasInsight ? (float) ($insight['confidence'] ?? 0) : null,
                'context_source' => 'voice_call',
                'observed_at' => (string) ($call['completed_at'] ?? $call['created_at'] ?? ''),
                'direction' => (string) $call['direction'],
                'state' => (string) $call['state'],
                'duration_seconds' => (int) ($call['duration_seconds'] ?? 0),
                'intent_inferred' => $hasInsight ? mb_substr(trim((string) ($insight['intent'] ?? '')), 0, 120) : '',
                'sentiment_inferred' => $hasInsight ? mb_substr(trim((string) ($insight['sentiment'] ?? '')), 0, 40) : '',
                'next_step' => $hasInsight ? mb_substr(trim((string) ($insight['next_step'] ?? '')), 0, 500) : '',
                'commitments_observed' => $hasInsight ? $this->boundedList($insight['commitments'] ?? []) : [],
            ], JSON_UNESCAPED_SLASHES);
            $activityId = (int) ($call['activity_id'] ?? 0);
            $communicationId = (int) ($call['communication_id'] ?? 0);
            if ($activityId <= 0) {
                Database::execute(
                    "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, metadata)
                     VALUES (?, ?, ?, 'call', ?, ?)",
                    [$workspaceId, $contactId, !empty($call['agent_user_id']) ? (int) $call['agent_user_id'] : null, $summary, $metadata]
                );
                $activityId = (int) Database::lastInsertId();
            } elseif ($hasInsight) {
                Database::execute(
                    "UPDATE activities SET description = ?, metadata = ? WHERE workspace_id = ? AND id = ? AND contact_id = ?",
                    [$summary, $metadata, $workspaceId, $activityId, $contactId]
                );
            }
            if ($communicationId <= 0) {
                Database::execute(
                    "INSERT INTO communications (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status)
                     VALUES (?, ?, ?, ?, 'voice', ?, 'Voice call summary', ?, ?, 'delivered')",
                    [$workspaceId, $this->uuid(), $contactId, 'voice:workspace:' . $workspaceId . ':contact:' . $contactId,
                        (string) $call['direction'], $summary, $metadata]
                );
                $communicationId = (int) Database::lastInsertId();
            } elseif ($hasInsight) {
                Database::execute(
                    "UPDATE communications SET body = ?, metadata = ? WHERE workspace_id = ? AND id = ? AND contact_id = ? AND channel = 'voice'",
                    [$summary, $metadata, $workspaceId, $communicationId, $contactId]
                );
            }
            Database::execute(
                'UPDATE voice_calls SET activity_id = ?, communication_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                [$activityId, $communicationId, $workspaceId, $callId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        try {
            (new ConversationIntelligenceService())->syncForCommunication($communicationId);
            (new ContactIntelligenceService())->computeAndPersist($contactId);
        } catch (\Throwable $e) {
            error_log('Voice CRM intelligence refresh failed: ' . $e->getMessage());
        }

        return ['activity_id' => $activityId, 'communication_id' => $communicationId];
    }

    private function fallbackSummary(array $call): string
    {
        $direction = ucfirst((string) ($call['direction'] ?? 'voice'));
        $seconds = max(0, (int) ($call['duration_seconds'] ?? 0));
        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;
        $summary = $direction . ' voice call completed';
        if ($seconds > 0) {
            $summary .= ' (' . $minutes . ':' . str_pad((string) $remainder, 2, '0', STR_PAD_LEFT) . ')';
        }
        if (trim((string) ($call['disposition'] ?? '')) !== '') {
            $summary .= '. Outcome: ' . str_replace('_', ' ', (string) $call['disposition']);
        }

        return $summary . '.';
    }

    private function boundedList($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_slice(array_values(array_filter(array_map(
            static fn($value): string => mb_substr(trim(is_array($value) ? (string) ($value['text'] ?? $value['value'] ?? '') : (string) $value), 0, 240),
            $values
        ))), 0, 6);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
