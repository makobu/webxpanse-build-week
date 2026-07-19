<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\MobileConversationDraftService;
use CRM\Tests\DatabaseTestCase;

class MobileConversationDraftServiceTest extends DatabaseTestCase
{
    private MobileConversationDraftService $service;
    private int $salesRoleId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MobileConversationDraftService();
        $this->salesRoleId = (int) ((Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1"))['id'] ?? 0);
    }

    public function testMobileUserCanSaveListAndUpdateManualConversationDraft(): void
    {
        $userId = $this->createUser();
        $contactId = $this->createContact();
        $communicationId = $this->createCommunication($contactId);

        $saved = $this->service->saveManual($userId, $this->getUser($userId), [
            'conversation_id' => $communicationId,
            'channel' => 'email',
            'subject' => 'Re: Mobile draft',
            'body' => 'I will follow up from mobile.',
            'save' => true,
            'owner_scope' => 'mine_unassigned',
        ]);

        $draftId = (int) ($saved['draft']['draft_id'] ?? 0);
        $this->assertGreaterThan(0, $draftId);
        $this->assertSame('I will follow up from mobile.', $saved['draft']['body']);

        $drafts = $this->service->list($userId, 'draft');
        $this->assertNotEmpty($drafts);
        $this->assertSame($draftId, (int) ($drafts[0]['draft_id'] ?? 0));

        $updated = $this->service->update($draftId, $userId, [
            'subject' => 'Re: Updated from mobile',
            'body' => 'Updated body from mobile.',
        ]);

        $this->assertSame('Re: Updated from mobile', $updated['subject']);
        $this->assertSame('Updated body from mobile.', $updated['body']);
    }

    public function testMobileDraftCannotBeUpdatedByAnotherUser(): void
    {
        $ownerUserId = $this->createUser();
        $otherUserId = $this->createUser();
        $contactId = $this->createContact();
        $communicationId = $this->createCommunication($contactId);

        $saved = $this->service->saveManual($ownerUserId, $this->getUser($ownerUserId), [
            'conversation_id' => $communicationId,
            'channel' => 'email',
            'subject' => 'Private draft',
            'body' => 'Only the author can update this draft.',
            'owner_scope' => 'mine_unassigned',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Draft not found or not accessible.');

        $this->service->update((int) ($saved['draft']['draft_id'] ?? 0), $otherUserId, [
            'body' => 'Attempted overwrite.',
        ]);
    }

    private function createUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role)
             VALUES (?, ?, ?, 'sales')",
            [$this->uuid(), 'mobile-draft.' . bin2hex(random_bytes(3)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        if ($this->salesRoleId > 0) {
            Authorization::assignUserRole($userId, $this->salesRoleId, $userId);
        }

        return $userId;
    }

    private function createContact(): int
    {
        $created = (new Contacts())->create([
            'first_name' => 'Mobile',
            'last_name' => 'Draft',
            'email' => 'mobile-draft-contact.' . bin2hex(random_bytes(3)) . '@example.test',
            'assigned_to' => null,
        ]);

        return (int) ($created['id'] ?? 0);
    }

    private function createCommunication(int $contactId): int
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, channel, direction, subject, body, status, from_email, created_at)
             VALUES
                (1, ?, ?, 'email', 'inbound', 'Mobile draft', 'Can you send details?', 'received', 'client@example.test', NOW())",
            [$this->uuid(), $contactId]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function getUser(int $userId): array
    {
        return Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
