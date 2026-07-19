<?php

namespace CRM\Services;

use CRM\Database;

class VoiceCallControlService
{
    private VoiceProviderInterface $provider;
    private WorkspaceVoiceConfigService $configs;
    private VoiceCallStateMachineService $states;

    public function __construct(?VoiceProviderInterface $provider = null, ?WorkspaceVoiceConfigService $configs = null, ?VoiceCallStateMachineService $states = null)
    {
        $this->provider = $provider ?? new AfricaTalkingVoiceProvider();
        $this->configs = $configs ?? new WorkspaceVoiceConfigService();
        $this->states = $states ?? new VoiceCallStateMachineService();
    }

    public function end(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedCall($workspaceId, $callId, $userId, $viewAll);
        if ($call === []) throw new \RuntimeException('Active voice call not found.');
        if ($this->states->isTerminal((string) $call['state'])) return $call;
        if (!empty($call['provider_session_id'])) {
            $this->provider->endCall($this->configs->get($workspaceId, true), (string) $call['provider_session_id']);
        }
        return $this->states->transition($workspaceId, $callId, 'cancelled');
    }

    public function transfer(int $workspaceId, int $callId, int $targetAgentId, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedCall($workspaceId, $callId, $userId, $viewAll);
        if ($call === [] || (string) $call['state'] !== 'in_progress' || empty($call['provider_session_id'])) {
            throw new \RuntimeException('Only an active connected call can be transferred.');
        }
        (new VoiceCallPolicyService())->assertRuntimeReady($workspaceId, (string) $call['direction']);
        if ((int) ($call['agent_id'] ?? 0) === $targetAgentId) throw new \RuntimeException('Choose a different transfer agent.');
        $target = (new VoiceAgentService($this->configs))->findById($workspaceId, $targetAgentId, true);
        if ($target === [] || empty($target['enabled']) || (string) $target['presence_status'] !== 'available') {
            throw new \RuntimeException('The transfer agent is not available.');
        }
        $leased = Database::execute(
            "UPDATE voice_agents SET pre_call_presence_status = 'available', presence_status = 'busy', last_assigned_at = NOW()
             WHERE workspace_id = ? AND id = ? AND enabled = 1 AND presence_status = 'available'",
            [$workspaceId, $targetAgentId]
        );
        if ($leased !== 1) throw new \RuntimeException('The transfer agent was assigned to another call.');
        try {
            $this->provider->transfer(
                $this->configs->get($workspaceId, true),
                (string) $call['provider_session_id'],
                (string) $target['endpoint']
            );
            Database::beginTransaction();
            try {
                Database::queryOne('SELECT id FROM voice_calls WHERE workspace_id = ? AND id = ? FOR UPDATE', [$workspaceId, $callId]);
                if (!empty($call['agent_id'])) {
                    Database::execute(
                        "UPDATE voice_agents SET presence_status = COALESCE(pre_call_presence_status, 'available'), pre_call_presence_status = NULL
                         WHERE workspace_id = ? AND id = ?",
                        [$workspaceId, (int) $call['agent_id']]
                    );
                }
                Database::execute(
                    'UPDATE voice_calls SET agent_id = ?, agent_user_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                    [$targetAgentId, (int) $target['user_id'], $workspaceId, $callId]
                );
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack(); throw $e;
            }
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE voice_agents SET presence_status = 'available', pre_call_presence_status = NULL WHERE workspace_id = ? AND id = ? AND presence_status = 'busy'",
                [$workspaceId, $targetAgentId]
            );
            throw $e;
        }
        (new VoiceCallService())->recordInternalEvent($workspaceId, $callId, 'voice.call.transferred', 'in_progress');
        return (new VoiceCallService())->get($workspaceId, $callId, 0, true);
    }

    public function transferToFallback(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedCall($workspaceId, $callId, $userId, $viewAll);
        if ($call === [] || (string) $call['state'] !== 'in_progress' || empty($call['provider_session_id'])) {
            throw new \RuntimeException('Only an active connected call can be transferred.');
        }
        $runtime = (new VoiceCallPolicyService())->assertRuntimeReady($workspaceId, (string) $call['direction']);
        $config = (array) $runtime['config'];
        $fallback = trim((string) (($config['settings']['fallback_number'] ?? '')));
        if ($fallback === '') {
            throw new \RuntimeException('A verified fallback number is not configured.');
        }
        $fallback = (new VoiceCallPolicyService())->assertDestinationAllowed($workspaceId, $fallback, $config);
        $this->provider->transfer($config, (string) $call['provider_session_id'], $fallback);

        Database::beginTransaction();
        try {
            Database::queryOne('SELECT id FROM voice_calls WHERE workspace_id = ? AND id = ? FOR UPDATE', [$workspaceId, $callId]);
            if (!empty($call['agent_id'])) {
                Database::execute(
                    "UPDATE voice_agents SET presence_status = COALESCE(pre_call_presence_status, 'available'), pre_call_presence_status = NULL
                     WHERE workspace_id = ? AND id = ?",
                    [$workspaceId, (int) $call['agent_id']]
                );
            }
            Database::execute(
                "UPDATE voice_calls SET agent_id = NULL,
                    provider_metadata_json = JSON_SET(COALESCE(provider_metadata_json, JSON_OBJECT()), '$.transferred_to_verified_fallback', true),
                    updated_at = NOW() WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $callId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        (new VoiceCallService())->recordInternalEvent($workspaceId, $callId, 'voice.call.transferred_to_fallback', 'in_progress');
        return (new VoiceCallService())->get($workspaceId, $callId, 0, true);
    }

    public function disposition(int $workspaceId, int $callId, string $disposition, string $notes, int $userId, bool $viewAll): array
    {
        $allowed = ['connected', 'follow_up', 'qualified', 'not_interested', 'wrong_number', 'voicemail', 'other'];
        if (!in_array($disposition, $allowed, true)) throw new \InvalidArgumentException('Invalid call disposition.');
        $call = $this->authorizedCall($workspaceId, $callId, $userId, $viewAll);
        if ($call === [] || !$this->states->isTerminal((string) $call['state'])) {
            throw new \RuntimeException('A disposition can be saved only after the call ends.');
        }
        Database::execute(
            'UPDATE voice_calls SET disposition = ?, disposition_notes = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
            [$disposition, substr(trim($notes), 0, 1000), $workspaceId, $callId]
        );
        return (new VoiceCallService())->get($workspaceId, $callId, 0, true);
    }

    private function authorizedCall(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $params = [$workspaceId, $callId];
        $scope = '';
        if (!$viewAll) {
            $scope = ' AND (agent_user_id = ? OR created_by_user_id = ?)';
            $params[] = $userId; $params[] = $userId;
        }
        return Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ?' . $scope . ' LIMIT 1', $params) ?: [];
    }
}
