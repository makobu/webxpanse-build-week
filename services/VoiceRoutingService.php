<?php

namespace CRM\Services;

use CRM\Database;

class VoiceRoutingService
{
    public function assignNextAgent(int $workspaceId, int $callId, ?int $queueId = null): array
    {
        Database::beginTransaction();
        try {
            $call = Database::queryOne('SELECT id, state, agent_id FROM voice_calls WHERE workspace_id = ? AND id = ? FOR UPDATE', [$workspaceId, $callId]);
            if (!$call) {
                throw new \RuntimeException('Voice call not found.');
            }
            if (!empty($call['agent_id'])) {
                $agent = Database::queryOne('SELECT * FROM voice_agents WHERE workspace_id = ? AND id = ?', [$workspaceId, (int) $call['agent_id']]);
                Database::commit();
                return $agent ?: [];
            }
            if ($queueId === null) {
                $queueId = (int) (Database::queryOne(
                    'SELECT id FROM voice_queues WHERE workspace_id = ? AND enabled = 1 ORDER BY is_default DESC, id ASC LIMIT 1',
                    [$workspaceId]
                )['id'] ?? 0);
            }
            $params = [$workspaceId];
            $membership = '';
            if ($queueId > 0) {
                $membership = 'INNER JOIN voice_queue_members qm ON qm.agent_id = a.id AND qm.workspace_id = a.workspace_id AND qm.queue_id = ? AND qm.enabled = 1';
                array_unshift($params, $queueId);
            }
            $agent = Database::queryOne(
                "SELECT a.* FROM voice_agents a {$membership}
                 WHERE a.workspace_id = ? AND a.enabled = 1 AND a.presence_status = 'available'
                 ORDER BY " . ($queueId > 0 ? 'qm.priority ASC, ' : '') . "a.last_assigned_at IS NOT NULL, a.last_assigned_at ASC, a.id ASC
                 LIMIT 1 FOR UPDATE",
                $params
            );
            if (!$agent) {
                Database::commit();
                return [];
            }
            Database::execute(
                "UPDATE voice_agents SET pre_call_presence_status = 'available', presence_status = 'busy', last_assigned_at = NOW() WHERE workspace_id = ? AND id = ? AND presence_status = 'available'",
                [$workspaceId, (int) $agent['id']]
            );
            Database::execute(
                'UPDATE voice_calls SET agent_id = ?, agent_user_id = ?, queue_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                [(int) $agent['id'], (int) $agent['user_id'], $queueId > 0 ? $queueId : null, $workspaceId, $callId]
            );
            Database::commit();
            return $agent;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
