<?php

namespace CRM\Services;

use CRM\Database;

class VoiceContactPhoneIndexService
{
    private WorkspaceVoiceConfigService $phones;

    public function __construct(?WorkspaceVoiceConfigService $phones = null)
    {
        $this->phones = $phones ?? new WorkspaceVoiceConfigService();
    }

    public function syncContact(int $workspaceId, int $contactId): void
    {
        if (!Database::tableExists('voice_contact_phone_index')) {
            return;
        }
        $contact = Database::queryOne('SELECT id, phone FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1', [$workspaceId, $contactId]);
        $normalized = $contact ? $this->phones->normalizePhone((string) ($contact['phone'] ?? '')) : '';
        if (!$contact || $normalized === '') {
            Database::execute('DELETE FROM voice_contact_phone_index WHERE workspace_id = ? AND contact_id = ?', [$workspaceId, $contactId]);
            return;
        }
        Database::execute(
            "INSERT INTO voice_contact_phone_index (workspace_id, contact_id, phone_hash) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE phone_hash = VALUES(phone_hash), updated_at = NOW()",
            [$workspaceId, $contactId, $this->phones->numberHash($normalized)]
        );
    }

    public function refreshWorkspace(int $workspaceId): int
    {
        if (!Database::tableExists('voice_contact_phone_index')) {
            return 0;
        }
        $contacts = Database::query("SELECT id, phone FROM contacts WHERE workspace_id = ? AND phone IS NOT NULL AND phone <> ''", [$workspaceId]);
        Database::beginTransaction();
        try {
            Database::execute('DELETE FROM voice_contact_phone_index WHERE workspace_id = ?', [$workspaceId]);
            $count = 0;
            foreach ($contacts as $contact) {
                $normalized = $this->phones->normalizePhone((string) ($contact['phone'] ?? ''));
                if ($normalized === '') continue;
                Database::execute(
                    'INSERT INTO voice_contact_phone_index (workspace_id, contact_id, phone_hash) VALUES (?, ?, ?)',
                    [$workspaceId, (int) $contact['id'], $this->phones->numberHash($normalized)]
                );
                $count++;
            }
            Database::commit();
            return $count;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function findUniqueContactId(int $workspaceId, string $number): ?int
    {
        $normalized = $this->phones->normalizePhone($number);
        if ($normalized === '' || !Database::tableExists('voice_contact_phone_index')) {
            return null;
        }
        $rows = Database::query(
            "SELECT i.contact_id FROM voice_contact_phone_index i
             INNER JOIN contacts c ON c.id = i.contact_id AND c.workspace_id = i.workspace_id
             WHERE i.workspace_id = ? AND i.phone_hash = ? ORDER BY i.contact_id ASC LIMIT 2",
            [$workspaceId, $this->phones->numberHash($normalized)]
        );
        return count($rows) === 1 ? (int) $rows[0]['contact_id'] : null;
    }
}
