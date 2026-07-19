<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\ConversationAssignmentService;
use CRM\Services\ConversationIntelligenceService;
use CRM\Tests\DatabaseTestCase;

class ConversationAssignmentServiceTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private ConversationAssignmentService $service;
    private ConversationIntelligenceService $intelligence;
    private int $ownerRoleId = 0;
    private int $salesRoleId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contacts = new Contacts();
        $this->service = new ConversationAssignmentService();
        $this->intelligence = new ConversationIntelligenceService();
        $this->ownerRoleId = (int) ((Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1"))['id'] ?? 0);
        $this->salesRoleId = (int) ((Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1"))['id'] ?? 0);
    }

    public function testAssignToUserClaimsThreadAndContactWhenContactIsUnassigned(): void
    {
        $actorId = $this->createUser('sales');
        $contactId = $this->createContact(0);
        $communicationId = $this->createCommunication($contactId);

        $result = $this->service->assignToUser($communicationId, $actorId, $this->getUser($actorId));

        $thread = $this->getThreadByCommunication($communicationId);
        $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1", [$contactId]);

        $this->assertSame($actorId, (int) ($result['owner_id'] ?? 0));
        $this->assertSame($actorId, (int) ($thread['current_owner_id'] ?? 0));
        $this->assertSame($actorId, (int) ($contact['assigned_to'] ?? 0));
    }

    public function testAssignToUserRejectsTransferWhenContactBelongsToAnotherUserWithoutPrivilege(): void
    {
        $actorId = $this->createUser('sales');
        $otherUserId = $this->createUser('sales');
        $contactId = $this->createContact($otherUserId);
        $communicationId = $this->createCommunication($contactId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact is assigned to another user.');

        try {
            $this->service->assignToUser($communicationId, $actorId, $this->getUser($actorId));
        } finally {
            $thread = $this->getThreadByCommunication($communicationId);
            $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1", [$contactId]);
            $this->assertTrue(empty($thread['current_owner_id']));
            $this->assertSame($otherUserId, (int) ($contact['assigned_to'] ?? 0));
        }
    }

    public function testAssignToUserTransfersContactWhenUserHasViewAllPermission(): void
    {
        $actorId = $this->createUser('owner');
        $otherUserId = $this->createUser('sales');
        $contactId = $this->createContact($otherUserId);
        $communicationId = $this->createCommunication($contactId);

        $this->service->assignToUser($communicationId, $actorId, $this->getUser($actorId));

        $thread = $this->getThreadByCommunication($communicationId);
        $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1", [$contactId]);

        $this->assertSame($actorId, (int) ($thread['current_owner_id'] ?? 0));
        $this->assertSame($actorId, (int) ($contact['assigned_to'] ?? 0));
        $this->assertTrue(Authorization::can('contacts.view_all', $this->getUser($actorId)));
    }

    public function testAssignToUserReturnsConflictWhenConversationAlreadyClaimed(): void
    {
        $firstUserId = $this->createUser('sales');
        $secondUserId = $this->createUser('sales');
        $contactId = $this->createContact(0);
        $communicationId = $this->createCommunication($contactId);

        $this->service->assignToUser($communicationId, $firstUserId, $this->getUser($firstUserId));

        try {
            $this->service->assignToUser($communicationId, $secondUserId, $this->getUser($secondUserId));
            $this->fail('Expected a claimed conversation to return a conflict.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, (int) $e->getCode());
            $this->assertSame('Conversation is already claimed by another user.', $e->getMessage());
        }
    }

    public function testManualUnassignPersistsAcrossThreadSyncUntilExplicitOwnerReset(): void
    {
        $actorId = $this->createUser('sales');
        $otherUserId = $this->createUser('owner');
        $contactId = $this->createContact($actorId);
        $communicationId = $this->createCommunication($contactId);

        $this->service->assignToUser($communicationId, $actorId, $this->getUser($actorId));
        $this->service->unassign($communicationId);

        $this->intelligence->syncForCommunication($communicationId);
        $thread = $this->getThreadByCommunication($communicationId);
        $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1", [$contactId]);

        $this->assertTrue(empty($thread['current_owner_id']));
        $this->assertSame($actorId, (int) ($contact['assigned_to'] ?? 0));

        Database::execute(
            "UPDATE communications
             SET triage_owner_id = ?
             WHERE id = ?",
            [$otherUserId, $communicationId]
        );
        $this->intelligence->clearManualOwnerOverrideForCommunication($communicationId);
        $this->intelligence->syncForCommunication($communicationId);

        $thread = $this->getThreadByCommunication($communicationId);
        $this->assertSame($otherUserId, (int) ($thread['current_owner_id'] ?? 0));
    }

    public function testAssignToUserRetiresSharedQueueNotificationsWhenClaimingUnassignedContact(): void
    {
        $actorId = $this->createUser('sales');
        $otherUserId = $this->createUser('sales');
        $contactId = $this->createContact(0);
        $communicationId = $this->createCommunication($contactId);

        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES (1, ?, 'whatsapp_message_received', 'Shared queue', 'Claim this contact', 'contact', ?, 0, NOW())",
            [$actorId, $contactId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES (1, ?, 'whatsapp_message_received', 'Shared queue', 'Claim this contact', 'contact', ?, 0, NOW())",
            [$otherUserId, $contactId]
        );

        $this->service->assignToUser($communicationId, $actorId, $this->getUser($actorId));

        $remaining = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM notifications
             WHERE entity_type = 'contact'
               AND entity_id = ?
               AND type IN ('contact_created', 'whatsapp_message_received')",
            [$contactId]
        )['c'] ?? 0);

        $this->assertSame(0, $remaining);
    }

    private function createUser(string $roleSlug): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role)
             VALUES (?, ?, ?, ?)",
            [$this->uuid(), $roleSlug . '.' . bin2hex(random_bytes(3)) . '@example.test', password_hash('password', PASSWORD_DEFAULT), $roleSlug]
        );
        $userId = (int) Database::lastInsertId();
        $roleId = $roleSlug === 'owner' ? $this->ownerRoleId : $this->salesRoleId;
        if ($roleId > 0) {
            Authorization::assignUserRole($userId, $roleId, $userId);
        }
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE
                role_slug = VALUES(role_slug),
                membership_status = 'active',
                is_owner = VALUES(is_owner)",
            [$userId, $roleSlug, $roleSlug === 'owner' ? 1 : 0]
        );

        return $userId;
    }

    private function createContact(int $assignedTo): int
    {
        $created = $this->contacts->create([
            'first_name' => 'Inbox',
            'last_name' => 'Contact',
            'email' => 'contact.' . bin2hex(random_bytes(3)) . '@example.test',
            'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
        ]);

        return (int) ($created['id'] ?? 0);
    }

    private function createCommunication(int $contactId): int
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, channel, direction, subject, body, status, from_email, created_at)
             VALUES
                (1, ?, ?, 'whatsapp', 'inbound', ?, ?, 'received', ?, NOW())",
            [
                $this->uuid(),
                $contactId,
                'Need help',
                'Please assign this conversation.',
                'client@example.test',
            ]
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

    /**
     * @return array<string,mixed>
     */
    private function getThreadByCommunication(int $communicationId): array
    {
        return Database::queryOne(
            "SELECT ct.*
             FROM conversation_threads ct
             JOIN communications c ON c.contact_id = ct.contact_id AND c.channel = ct.channel
             WHERE c.id = ?
             LIMIT 1",
            [$communicationId]
        ) ?? [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
