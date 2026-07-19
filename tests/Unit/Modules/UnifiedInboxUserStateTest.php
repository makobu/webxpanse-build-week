<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\UnifiedInbox;
use CRM\Tests\DatabaseTestCase;

class UnifiedInboxUserStateTest extends DatabaseTestCase
{
    private UnifiedInbox $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbox = new UnifiedInbox();
    }

    public function testArchiveStateIsPerUserAndDoesNotMutateGlobalCommunication(): void
    {
        $userA = $this->createUser('archive-a');
        $userB = $this->createUser('archive-b');
        $communicationId = $this->createCommunication();

        $_SESSION['user_id'] = $userA;
        $this->assertTrue($this->inbox->archive($communicationId));

        $global = Database::queryOne('SELECT archived_at FROM communications WHERE id = ? LIMIT 1', [$communicationId]);
        $this->assertTrue(empty($global['archived_at']));

        $this->assertSame(0, $this->inbox->getCount([
            'viewer_user_id' => $userA,
            'owner_scope' => UnifiedInbox::OWNER_SCOPE_MINE_UNASSIGNED,
        ]));
        $this->assertSame(1, $this->inbox->getCount([
            'viewer_user_id' => $userB,
            'owner_scope' => UnifiedInbox::OWNER_SCOPE_MINE_UNASSIGNED,
        ]));
    }

    public function testMarkUnreadCanOverrideLegacyGlobalReadStateForOneUser(): void
    {
        $userA = $this->createUser('read-a');
        $userB = $this->createUser('read-b');
        $communicationId = $this->createCommunication('2026-05-23 10:00:00');

        $_SESSION['user_id'] = $userA;
        $this->assertTrue($this->inbox->markAsUnread($communicationId));

        $this->assertSame(1, $this->inbox->getCount([
            'viewer_user_id' => $userA,
            'status' => 'unread',
            'owner_scope' => UnifiedInbox::OWNER_SCOPE_MINE_UNASSIGNED,
        ]));
        $this->assertSame(0, $this->inbox->getCount([
            'viewer_user_id' => $userB,
            'status' => 'unread',
            'owner_scope' => UnifiedInbox::OWNER_SCOPE_MINE_UNASSIGNED,
        ]));
    }

    private function createUser(string $prefix): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid($prefix . '-', true), $prefix . '.' . bin2hex(random_bytes(3)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createCommunication(?string $readAt = null): int
    {
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, channel, direction, subject, body, status, from_email, read_at, created_at)
             VALUES (1, ?, 'email', 'inbound', 'Per-user state', 'Message body', 'received', 'sender@example.test', ?, NOW())",
            [uniqid('comm-', true), $readAt]
        );

        return (int) Database::lastInsertId();
    }
}
