<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\UserPreferences;
use CRM\Tests\DatabaseTestCase;

class UserPreferencesTest extends DatabaseTestCase
{
    public function testContactsLastOpenedPreferenceCanBeReadAndMarked(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('preferences-user-', true), 'preferences-user@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $preferences = new UserPreferences();
        $this->assertNull($preferences->getContactsLastOpenedAt($userId));

        $preferences->setPreference($userId, 'last_contacts_page_opened_at', '2026-05-19 09:00:00');
        $this->assertSame('2026-05-19 09:00:00', $preferences->getContactsLastOpenedAt($userId));

        $preferences->markContactsPageOpened($userId);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $preferences->getContactsLastOpenedAt($userId)
        );
    }

    public function testNotificationsLastOpenedPreferenceCanBeReadAndMarked(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('notifications-preferences-user-', true), 'notifications-preferences-user@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $preferences = new UserPreferences();
        $this->assertNull($preferences->getNotificationsLastOpenedAt($userId));

        $preferences->setPreference($userId, 'last_notifications_page_opened_at', '2026-05-19 09:00:00');
        $this->assertSame('2026-05-19 09:00:00', $preferences->getNotificationsLastOpenedAt($userId));

        $preferences->markNotificationsPageOpened($userId);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $preferences->getNotificationsLastOpenedAt($userId)
        );
    }

    public function testTasksLastOpenedPreferenceCanBeReadAndMarked(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('tasks-preferences-user-', true), 'tasks-preferences-user@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $preferences = new UserPreferences();
        $this->assertNull($preferences->getTasksLastOpenedAt($userId));

        $preferences->setPreference($userId, 'last_tasks_page_opened_at', '2026-05-19 09:00:00');
        $this->assertSame('2026-05-19 09:00:00', $preferences->getTasksLastOpenedAt($userId));

        $preferences->markTasksPageOpened($userId);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $preferences->getTasksLastOpenedAt($userId)
        );
    }
}
