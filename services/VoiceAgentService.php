<?php

namespace CRM\Services;

use CRM\Database;

class VoiceAgentService
{
    private WorkspaceVoiceConfigService $crypto;

    public function __construct(?WorkspaceVoiceConfigService $crypto = null)
    {
        $this->crypto = $crypto ?? new WorkspaceVoiceConfigService();
    }

    public function save(int $workspaceId, int $userId, array $settings, int $actorUserId = 0): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Workspace and user are required.');
        }
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
            [$workspaceId, $userId]
        );
        if (!$membership) {
            throw new \InvalidArgumentException('Voice agents must be active members of this workspace.');
        }
        $entitlement = (new WorkspaceVoiceEntitlementService())->assertEnabled($workspaceId);
        $existing = Database::queryOne('SELECT * FROM voice_agents WHERE workspace_id = ? AND user_id = ? LIMIT 1', [$workspaceId, $userId]);
        $endpoint = trim((string) ($settings['endpoint'] ?? ''));
        if ($endpoint === '' && $existing) {
            $endpoint = $this->crypto->decryptValue((string) $existing['encrypted_endpoint']);
        }
        $type = strtolower((string) ($settings['endpoint_type'] ?? $existing['endpoint_type'] ?? 'phone')) === 'sip' ? 'sip' : 'phone';
        if ($type === 'phone') {
            $endpoint = $this->crypto->normalizePhone($endpoint);
        } elseif (!preg_match('/^sip:[^@\s]+@[^\s]+$/i', $endpoint)) {
            $endpoint = '';
        }
        if ($endpoint === '') {
            throw new \InvalidArgumentException('A valid agent phone number or SIP endpoint is required.');
        }
        if (!$existing) {
            $count = (int) (Database::queryOne('SELECT COUNT(*) AS c FROM voice_agents WHERE workspace_id = ? AND enabled = 1', [$workspaceId])['c'] ?? 0);
            if ($count >= (int) $entitlement['agent_limit']) {
                throw new \RuntimeException('The workspace voice agent limit has been reached.');
            }
        }
        $displayName = trim((string) ($settings['display_name'] ?? ''));
        if ($displayName === '') {
            $user = Database::queryOne('SELECT email FROM users WHERE id = ? LIMIT 1', [$userId]);
            $displayName = trim((string) ($user['email'] ?? ('Agent ' . $userId)));
        }
        $presence = (string) ($settings['presence_status'] ?? $existing['presence_status'] ?? 'offline');
        if (!in_array($presence, ['available', 'away', 'offline'], true)) {
            $presence = 'offline';
        }
        $masked = $type === 'phone' ? $this->crypto->maskPhone($endpoint) : preg_replace('/^sip:([^@]{1,3})[^@]*@/', 'sip:$1***@', $endpoint);
        $endpointHash = hash_hmac('sha256', strtolower($endpoint), $this->secretKey());
        $endpointOwner = Database::queryOne(
            'SELECT id, user_id FROM voice_agents WHERE workspace_id = ? AND endpoint_hash = ? LIMIT 1',
            [$workspaceId, $endpointHash]
        );
        if ($endpointOwner && (!$existing || (int) $endpointOwner['id'] !== (int) $existing['id'])) {
            throw new \InvalidArgumentException('That phone or SIP endpoint is already assigned to another voice agent.');
        }

        $values = [
            substr($displayName, 0, 191), $type, $this->crypto->encryptValue($endpoint), $endpointHash,
            substr((string) $masked, 0, 191), $presence,
            array_key_exists('enabled', $settings) ? (!empty($settings['enabled']) ? 1 : 0) : 1,
            $actorUserId > 0 ? $actorUserId : null,
        ];
        if ($existing) {
            Database::execute(
                "UPDATE voice_agents SET display_name = ?, endpoint_type = ?, encrypted_endpoint = ?, endpoint_hash = ?,
                    endpoint_masked = ?, presence_status = IF(presence_status = 'busy', presence_status, ?), enabled = ?,
                    updated_by_user_id = ?, updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                array_merge($values, [$workspaceId, (int) $existing['id']])
            );
        } else {
            Database::execute(
                "INSERT INTO voice_agents (workspace_id, user_id, display_name, endpoint_type, encrypted_endpoint, endpoint_hash,
                    endpoint_masked, presence_status, enabled, created_by_user_id, updated_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $userId, $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[7]]
            );
        }
        return $this->findForUser($workspaceId, $userId, false);
    }

    public function setPresence(int $workspaceId, int $userId, string $presence): array
    {
        if (!in_array($presence, ['available', 'away', 'offline'], true)) {
            throw new \InvalidArgumentException('Invalid voice presence.');
        }
        Database::execute(
            "UPDATE voice_agents SET presence_status = ? WHERE workspace_id = ? AND user_id = ? AND enabled = 1 AND presence_status <> 'busy'",
            [$presence, $workspaceId, $userId]
        );
        return $this->findForUser($workspaceId, $userId, false);
    }

    public function findForUser(int $workspaceId, int $userId, bool $includeEndpoint = false): array
    {
        $row = Database::queryOne('SELECT * FROM voice_agents WHERE workspace_id = ? AND user_id = ? LIMIT 1', [$workspaceId, $userId]);
        return $row ? $this->hydrate($row, $includeEndpoint) : [];
    }

    public function findById(int $workspaceId, int $agentId, bool $includeEndpoint = false): array
    {
        $row = Database::queryOne('SELECT * FROM voice_agents WHERE workspace_id = ? AND id = ? LIMIT 1', [$workspaceId, $agentId]);
        return $row ? $this->hydrate($row, $includeEndpoint) : [];
    }

    public function list(int $workspaceId): array
    {
        return array_map(fn(array $row): array => $this->hydrate($row, false), Database::query(
            'SELECT * FROM voice_agents WHERE workspace_id = ? ORDER BY enabled DESC, display_name ASC', [$workspaceId]
        ));
    }

    private function hydrate(array $row, bool $includeEndpoint): array
    {
        return [
            'id' => (int) $row['id'], 'workspace_id' => (int) $row['workspace_id'], 'user_id' => (int) $row['user_id'],
            'display_name' => (string) $row['display_name'], 'endpoint_type' => (string) $row['endpoint_type'],
            'endpoint' => $includeEndpoint ? $this->crypto->decryptValue((string) $row['encrypted_endpoint']) : null,
            'endpoint_masked' => (string) $row['endpoint_masked'], 'presence_status' => (string) $row['presence_status'],
            'enabled' => !empty($row['enabled']), 'last_assigned_at' => $row['last_assigned_at'] ?? null,
            'last_successful_call_at' => $row['last_successful_call_at'] ?? null,
        ];
    }

    private function secretKey(): string
    {
        return hash('sha256', (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret'), true);
    }
}
