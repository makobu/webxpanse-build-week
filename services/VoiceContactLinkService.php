<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Contacts;

class VoiceContactLinkService
{
    private WorkspaceVoiceConfigService $crypto;

    public function __construct(?WorkspaceVoiceConfigService $crypto = null)
    {
        $this->crypto = $crypto ?? new WorkspaceVoiceConfigService();
    }

    public function linkExisting(int $workspaceId, int $callId, int $contactId, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedUnmatchedCall($workspaceId, $callId, $userId, $viewAll);
        $params = [$workspaceId, $contactId];
        $scope = '';
        if (!$viewAll) {
            $scope = ' AND (assigned_to IS NULL OR assigned_to = ? OR created_by = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }
        if (!Database::queryOne('SELECT id FROM contacts WHERE workspace_id = ? AND id = ?' . $scope . ' LIMIT 1', $params)) {
            throw new \RuntimeException('The selected contact is not available to you in this workspace.');
        }
        return $this->attach($workspaceId, (int) $call['id'], $contactId, $userId);
    }

    public function createAndLink(int $workspaceId, int $callId, array $contactData, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedUnmatchedCall($workspaceId, $callId, $userId, $viewAll);
        $encryptedNumber = (string) ($call['direction'] === 'inbound' ? $call['encrypted_from_number'] : $call['encrypted_to_number']);
        $number = $encryptedNumber !== '' ? $this->crypto->decryptValue($encryptedNumber) : '';
        if ($number === '') {
            throw new \RuntimeException('This call does not contain a usable customer number.');
        }
        $result = (new Contacts())->create([
            'first_name' => trim((string) ($contactData['first_name'] ?? '')),
            'last_name' => trim((string) ($contactData['last_name'] ?? '')),
            'email' => trim((string) ($contactData['email'] ?? '')),
            'phone' => $number,
            'lead_source' => 'other',
            'assigned_to' => $userId,
            'created_by' => $userId,
        ]);
        if (($result['status'] ?? '') === 'duplicate') {
            throw new \RuntimeException('A contact with this email or phone already exists. Link the existing contact instead.');
        }
        $contactId = (int) ($result['id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('The contact could not be created.');
        }
        return $this->attach($workspaceId, (int) $call['id'], $contactId, $userId);
    }

    private function attach(int $workspaceId, int $callId, int $contactId, int $userId): array
    {
        Database::beginTransaction();
        try {
            $updated = Database::execute(
                'UPDATE voice_calls SET contact_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ? AND contact_id IS NULL',
                [$contactId, $workspaceId, $callId]
            );
            if ($updated !== 1) {
                throw new \RuntimeException('This call was already linked by another user.');
            }
            Database::execute(
                'UPDATE voice_call_insights SET contact_id = ?, updated_at = NOW() WHERE workspace_id = ? AND call_id = ?',
                [$contactId, $workspaceId, $callId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        (new VoiceCallService())->recordInternalEvent($workspaceId, $callId, 'voice.call.contact_linked', null);
        (new VoiceIntelligenceService())->syncCrmContextForCall($workspaceId, $callId);
        return (new VoiceCallService())->get($workspaceId, $callId, $userId, true);
    }

    private function authorizedUnmatchedCall(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $params = [$workspaceId, $callId];
        $scope = '';
        if (!$viewAll) {
            $scope = ' AND (agent_user_id = ? OR created_by_user_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }
        $call = Database::queryOne(
            'SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ? AND contact_id IS NULL' . $scope . ' LIMIT 1',
            $params
        );
        if (!$call) {
            throw new \RuntimeException('Unmatched voice call not found.');
        }
        return $call;
    }
}
