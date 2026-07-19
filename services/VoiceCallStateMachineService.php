<?php

namespace CRM\Services;

use CRM\Database;

class VoiceCallStateMachineService
{
    private const TERMINAL = ['completed', 'cancelled', 'busy', 'no_answer', 'rejected', 'expired', 'provider_failed', 'policy_blocked'];
    private const TRANSITIONS = [
        'requested' => ['queued', 'dialing_agent', 'cancelled', 'provider_failed', 'policy_blocked'],
        'queued' => ['dialing_agent', 'received', 'completed', 'cancelled', 'expired', 'provider_failed', 'policy_blocked'],
        'received' => ['consent_pending', 'queued', 'dialing_agent', 'completed', 'rejected', 'expired', 'provider_failed'],
        'consent_pending' => ['queued', 'dialing_agent', 'completed', 'rejected', 'expired', 'provider_failed'],
        'dialing_agent' => ['agent_answered', 'ringing', 'completed', 'busy', 'no_answer', 'rejected', 'provider_failed', 'cancelled'],
        'agent_answered' => ['dialing_customer', 'in_progress', 'completed', 'cancelled', 'provider_failed'],
        'dialing_customer' => ['ringing', 'in_progress', 'completed', 'busy', 'no_answer', 'rejected', 'provider_failed', 'cancelled'],
        'ringing' => ['in_progress', 'completed', 'busy', 'no_answer', 'rejected', 'provider_failed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled', 'provider_failed'],
    ];

    public function transition(int $workspaceId, int $callId, string $nextState, array $attributes = []): array
    {
        if ($workspaceId <= 0 || $callId <= 0) {
            throw new \InvalidArgumentException('Workspace and call are required.');
        }
        Database::beginTransaction();
        try {
            $call = Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ? FOR UPDATE', [$workspaceId, $callId]);
            if (!$call) {
                throw new \RuntimeException('Voice call not found.');
            }
            $current = (string) $call['state'];
            if ($current === $nextState) {
                Database::commit();
                return $call;
            }
            if (in_array($current, self::TERMINAL, true)) {
                throw new \LogicException('A terminal voice call cannot transition backwards.');
            }
            if (!in_array($nextState, self::TRANSITIONS[$current] ?? [], true)) {
                throw new \LogicException('Invalid voice call transition: ' . $current . ' -> ' . $nextState);
            }

            [$set, $params] = $this->transitionUpdate($nextState, $attributes);
            $params[] = $workspaceId;
            $params[] = $callId;
            Database::execute('UPDATE voice_calls SET state = ?, ' . implode(', ', $set) . ', updated_at = NOW() WHERE workspace_id = ? AND id = ?', array_merge([$nextState], $params));
            if (in_array($nextState, self::TERMINAL, true) && !empty($call['agent_id'])) {
                $successfulCallUpdate = $nextState === 'completed' ? ', last_successful_call_at = NOW()' : '';
                Database::execute(
                    "UPDATE voice_agents SET presence_status = COALESCE(pre_call_presence_status, 'available'), pre_call_presence_status = NULL{$successfulCallUpdate} WHERE workspace_id = ? AND id = ?",
                    [$workspaceId, (int) $call['agent_id']]
                );
            }
            Database::commit();
            return Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ?', [$workspaceId, $callId]) ?: [];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public function canTransition(string $currentState, string $nextState): bool
    {
        if ($currentState === $nextState) {
            return true;
        }
        return !in_array($currentState, self::TERMINAL, true)
            && in_array($nextState, self::TRANSITIONS[$currentState] ?? [], true);
    }

    private function transitionUpdate(string $state, array $attributes): array
    {
        $set = [];
        $params = [];
        $timestamps = [
            'requested' => 'requested_at', 'queued' => 'queued_at', 'ringing' => 'ringing_at',
            'in_progress' => 'answered_at', 'completed' => 'completed_at',
        ];
        if (isset($timestamps[$state])) {
            $set[] = $timestamps[$state] . ' = COALESCE(' . $timestamps[$state] . ', NOW())';
        }
        $allowed = ['provider_session_id', 'provider_parent_session_id', 'duration_seconds', 'failure_category', 'failure_message', 'consent_status', 'recording_status', 'transcription_status', 'disposition', 'disposition_notes'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $attributes)) {
                $set[] = $field . ' = ?';
                $params[] = is_string($attributes[$field]) ? substr($attributes[$field], 0, $field === 'failure_message' ? 500 : 191) : $attributes[$field];
            }
        }
        if ($set === []) {
            $set[] = 'updated_at = updated_at';
        }
        return [$set, $params];
    }
}
