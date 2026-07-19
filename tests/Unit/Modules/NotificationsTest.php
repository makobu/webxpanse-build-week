<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Notifications;
use CRM\Tests\DatabaseTestCase;

class NotificationsTest extends DatabaseTestCase
{
    private Notifications $notifications;
    private int $firstUserId;
    private int $secondUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new Notifications();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('notif-user-1-', true), 'notif-user-1@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->firstUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('notif-user-2-', true), 'notif-user-2@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->secondUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
             VALUES (1, ?, 'viewer', 'active', NOW()), (1, ?, 'viewer', 'active', NOW())",
            [$this->firstUserId, $this->secondUserId]
        );

        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at) VALUES (1, ?, 'task', 'A', 'First', 0, NOW())",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at) VALUES (1, ?, 'task', 'B', 'Second', 1, NOW())",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at) VALUES (1, ?, 'deal', 'C', 'Third', 0, NOW())",
            [$this->secondUserId]
        );
    }

    public function testGetNotificationsCanReturnAllUsersWhenScopeIsGlobal(): void
    {
        $items = $this->notifications->getNotifications(10, 0, false, null);

        $this->assertCount(3, $items);
    }

    public function testGetNotificationsCanStayScopedToOneUser(): void
    {
        $items = $this->notifications->getUserNotifications($this->firstUserId, 10, 0, false);

        $this->assertCount(2, $items);
        $this->assertSame([$this->firstUserId, $this->firstUserId], array_column($items, 'user_id'));
    }

    public function testGetUnreadCountSupportsGlobalAndScopedQueries(): void
    {
        $this->assertSame(2, $this->notifications->getUnreadCount(null));
        $this->assertSame(1, $this->notifications->getUnreadCount($this->firstUserId));
        $this->assertSame(1, $this->notifications->getUnreadCount($this->secondUserId));
    }

    public function testGetNewSinceCountFallsBackToUnreadCountWhenNeverOpened(): void
    {
        $this->assertSame(1, $this->notifications->getNewSinceCount($this->firstUserId, null));
    }

    public function testGetNewSinceCountUsesNotificationsPageOpenedTimestamp(): void
    {
        Database::execute(
            "UPDATE notifications SET created_at = '2026-05-19 08:00:00' WHERE user_id = ?",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at)
             VALUES (1, ?, 'task', 'D', 'After opened and already read', 1, '2026-05-19 09:30:00')",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at)
             VALUES (1, ?, 'task', 'E', 'After opened and unread', 0, '2026-05-19 10:00:00')",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at)
             VALUES (1, ?, 'task', 'F', 'Other user after opened', 0, '2026-05-19 10:30:00')",
            [$this->secondUserId]
        );

        $this->assertSame(2, $this->notifications->getNewSinceCount($this->firstUserId, '2026-05-19 09:00:00'));
    }

    public function testGetBadgeCountsReturnsUnreadAndNewCountsFromOneScopedSnapshot(): void
    {
        Database::execute(
            "UPDATE notifications SET created_at = '2026-05-19 08:00:00' WHERE user_id = ?",
            [$this->firstUserId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read, created_at)
             VALUES
                (1, ?, 'task', 'D', 'New but already read', 1, '2026-05-19 09:30:00'),
                (1, ?, 'task', 'E', 'New and unread', 0, '2026-05-19 10:00:00')",
            [$this->firstUserId, $this->firstUserId]
        );

        $neverOpened = $this->notifications->getBadgeCounts($this->firstUserId, null);
        $openedAtNine = $this->notifications->getBadgeCounts($this->firstUserId, '2026-05-19 09:00:00');

        $this->assertSame(['unread_count' => 2, 'new_count' => 2], $neverOpened);
        $this->assertSame(['unread_count' => 2, 'new_count' => 2], $openedAtNine);
    }

    public function testAssignedContactCreationNotifiesAssignedOwnerOnly(): void
    {
        $contactId = $this->createContact($this->firstUserId);

        $rows = Database::query(
            "SELECT user_id
             FROM notifications
             WHERE type = 'contact_created'
               AND entity_type = 'contact'
               AND entity_id = ?
             ORDER BY user_id ASC",
            [$contactId]
        );

        $this->assertSame([$this->firstUserId], array_map('intval', array_column($rows, 'user_id')));
    }

    public function testUnassignedContactCreationNotifiesAllWorkspaceUsers(): void
    {
        $totalUsers = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);
        $contactId = $this->createContact(null);

        $rows = Database::query(
            "SELECT user_id
             FROM notifications
             WHERE type = 'contact_created'
               AND entity_type = 'contact'
               AND entity_id = ?
             ORDER BY user_id ASC",
            [$contactId]
        );
        $userIds = array_map('intval', array_column($rows, 'user_id'));

        $this->assertCount($totalUsers, $rows);
        $this->assertContains($this->firstUserId, $userIds);
        $this->assertContains($this->secondUserId, $userIds);
    }

    public function testClaimedContactNotificationsStopShowingToOtherUsers(): void
    {
        $baselineUnread = $this->notifications->getUnreadCount($this->firstUserId);
        $contactId = $this->createContact(null);
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES (1, ?, 'whatsapp_message_received', 'Shared queue', 'A contact needs triage', 'contact', ?, 0, NOW())",
            [$this->firstUserId, $contactId]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES (1, ?, 'whatsapp_message_received', 'Shared queue', 'A contact needs triage', 'contact', ?, 0, NOW())",
            [$this->secondUserId, $contactId]
        );

        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$this->secondUserId, $contactId]
        );

        $firstUserItems = $this->notifications->getUserNotifications($this->firstUserId, 20, 0, false);
        $matchingForFirstUser = array_values(array_filter(
            $firstUserItems,
            static fn(array $item): bool => (string) ($item['type'] ?? '') === 'whatsapp_message_received'
                && (string) ($item['entity_type'] ?? '') === 'contact'
                && (int) ($item['entity_id'] ?? 0) === $contactId
        ));
        $this->assertCount(0, $matchingForFirstUser);
        $this->assertSame($baselineUnread, $this->notifications->getUnreadCount($this->firstUserId));

        $globalItems = $this->notifications->getNotifications(20, 0, false, null);
        $matchingGlobal = array_values(array_filter(
            $globalItems,
            static fn(array $item): bool => (string) ($item['type'] ?? '') === 'whatsapp_message_received'
                && (string) ($item['entity_type'] ?? '') === 'contact'
                && (int) ($item['entity_id'] ?? 0) === $contactId
        ));
        $this->assertCount(2, $matchingGlobal);
    }

    public function testGroupNotificationsBuildsReadableThreads(): void
    {
        Database::execute("DELETE FROM notifications WHERE user_id = ?", [$this->firstUserId]);
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES
                (1, ?, 'contact_created', 'Contact A', 'First contact', 'contact', 101, 0, '2026-05-19 09:00:00'),
                (1, ?, 'contact_created', 'Contact B', 'Second contact', 'contact', 102, 1, '2026-05-19 10:00:00'),
                (1, ?, 'workspace_created', 'Workspace A', 'First workspace', 'workspace', 201, 0, '2026-05-19 11:00:00'),
                (1, ?, 'task', 'Task A', 'Task reminder', NULL, NULL, 0, '2026-05-19 08:00:00')",
            [$this->firstUserId, $this->firstUserId, $this->firstUserId, $this->firstUserId]
        );

        $groups = $this->notifications->groupNotifications(
            $this->notifications->getUserNotifications($this->firstUserId, 20, 0, false)
        );

        $this->assertCount(3, $groups);
        $this->assertSame('New workspaces', $groups[0]['label']);
        $this->assertSame('Workspace A', $groups[0]['latest_title']);

        $contactGroup = $this->findGroup($groups, 'contact_created', 'contact');
        $this->assertSame('New contacts', $contactGroup['label']);
        $this->assertSame(2, $contactGroup['total_count']);
        $this->assertSame(1, $contactGroup['unread_count']);
        $this->assertSame('Contact B', $contactGroup['latest_title']);

        $taskGroup = $this->findGroup($groups, 'task', '');
        $this->assertSame('Task notifications', $taskGroup['label']);
        $this->assertSame(1, $taskGroup['total_count']);
        $this->assertSame(1, $taskGroup['unread_count']);
    }

    public function testLegacyTaskAssignmentTitlesAreDescriptiveForDisplay(): void
    {
        Database::execute("DELETE FROM notifications WHERE user_id = ?", [$this->firstUserId]);
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES (1, ?, 'task_assigned', 'New Task Assigned', 'You have been assigned a new task: Finish Finance setup', 'task', 501, 0, NOW())",
            [$this->firstUserId]
        );

        $items = $this->notifications->getUserNotifications($this->firstUserId, 20, 0, false);

        $this->assertSame('Finish Finance setup', $items[0]['title']);
        $this->assertSame('You have been assigned this task.', $items[0]['message']);

        $groups = $this->notifications->groupNotifications($items);
        $this->assertSame('Task assignments', $groups[0]['label']);
        $this->assertSame('Finish Finance setup', $groups[0]['latest_title']);
    }

    public function testNotificationPlainTextKeepsApostrophesReadable(): void
    {
        Database::execute("DELETE FROM notifications WHERE user_id = ?", [$this->firstUserId]);

        $notificationId = $this->notifications->create(
            $this->firstUserId,
            'demo_contact_spotlight',
            "Amina's contact updated",
            "Amina's contact now carries Riverside context.",
            [
                'ai_insight' => "Amina's WhatsApp and email context are connected.",
                'ai_action' => 'Open contacts',
            ]
        );

        $stored = Database::queryOne(
            "SELECT title, message, ai_insight
             FROM notifications
             WHERE id = ?
             LIMIT 1",
            [$notificationId]
        ) ?: [];
        $this->assertSame("Amina's contact updated", (string) ($stored['title'] ?? ''));
        $this->assertSame("Amina's contact now carries Riverside context.", (string) ($stored['message'] ?? ''));
        $this->assertSame("Amina's WhatsApp and email context are connected.", (string) ($stored['ai_insight'] ?? ''));

        Database::execute(
            "UPDATE notifications
             SET message = 'Amina&#039;s legacy encoded message'
             WHERE id = ?",
            [$notificationId]
        );

        $items = $this->notifications->getUserNotifications($this->firstUserId, 20, 0, false);
        $this->assertSame("Amina's legacy encoded message", (string) ($items[0]['message'] ?? ''));

        $groups = $this->notifications->groupNotifications($items);
        $this->assertSame("Amina's legacy encoded message", (string) ($groups[0]['latest_message'] ?? ''));
        $this->assertStringNotContainsString('&#039;', (string) ($groups[0]['latest_message'] ?? ''));
    }

    public function testMarkGroupAsReadStaysScopedToUserTypeAndWorkspace(): void
    {
        Database::execute("DELETE FROM notifications WHERE user_id IN (?, ?)", [$this->firstUserId, $this->secondUserId]);
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Second Workspace', 'second-workspace', 'active', 'trial', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name)",
            [uniqid('workspace-', true)]
        );
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
             VALUES
                (1, ?, 'contact_created', 'Contact A', 'First contact', 'contact', 101, 0, NOW()),
                (1, ?, 'contact_created', 'Contact B', 'Second contact', 'contact', 102, 0, NOW()),
                (1, ?, 'task', 'Task A', 'Task reminder', NULL, NULL, 0, NOW()),
                (1, ?, 'contact_created', 'Other user contact', 'Other user', 'contact', 103, 0, NOW()),
                (2, ?, 'contact_created', 'Other workspace contact', 'Other workspace', 'contact', 104, 0, NOW())",
            [$this->firstUserId, $this->firstUserId, $this->firstUserId, $this->secondUserId, $this->firstUserId]
        );

        $this->assertSame(2, $this->notifications->markGroupAsRead($this->firstUserId, 'contact_created', 'contact'));

        $firstUserContactUnread = Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications
             WHERE workspace_id = 1 AND user_id = ? AND type = 'contact_created' AND entity_type = 'contact' AND is_read = 0",
            [$this->firstUserId]
        );
        $firstUserTaskUnread = Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications
             WHERE workspace_id = 1 AND user_id = ? AND type = 'task' AND is_read = 0",
            [$this->firstUserId]
        );
        $secondUserContactUnread = Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications
             WHERE workspace_id = 1 AND user_id = ? AND type = 'contact_created' AND entity_type = 'contact' AND is_read = 0",
            [$this->secondUserId]
        );
        $otherWorkspaceUnread = Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications
             WHERE workspace_id = 2 AND user_id = ? AND type = 'contact_created' AND entity_type = 'contact' AND is_read = 0",
            [$this->firstUserId]
        );

        $this->assertSame(0, (int) ($firstUserContactUnread['c'] ?? 0));
        $this->assertSame(1, (int) ($firstUserTaskUnread['c'] ?? 0));
        $this->assertSame(1, (int) ($secondUserContactUnread['c'] ?? 0));
        $this->assertSame(1, (int) ($otherWorkspaceUnread['c'] ?? 0));
    }

    private function createContact(?int $assignedTo): int
    {
        $created = (new Contacts())->create([
            'first_name' => 'Notif',
            'last_name' => 'Contact',
            'email' => 'notif.' . bin2hex(random_bytes(4)) . '@example.test',
            'assigned_to' => $assignedTo,
        ]);

        return (int) ($created['id'] ?? 0);
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<string,mixed>
     */
    private function findGroup(array $groups, string $type, string $entityType): array
    {
        foreach ($groups as $group) {
            if (($group['type'] ?? '') === $type && ($group['entity_type'] ?? '') === $entityType) {
                return $group;
            }
        }

        $this->fail(sprintf('Group %s/%s was not found.', $type, $entityType));
    }
}
