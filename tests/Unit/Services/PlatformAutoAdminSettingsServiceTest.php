<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\UserPreferences;
use CRM\Services\PlatformAutoAdminSettingsService;
use CRM\Tests\DatabaseTestCase;

class PlatformAutoAdminSettingsServiceTest extends DatabaseTestCase
{
    public function testDefaultsToDisabled(): void
    {
        $service = new PlatformAutoAdminSettingsService();

        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->getSettings()['enabled']);
    }

    public function testPersistsPlatformSingletonWithoutDependingOnLegacyUserOne(): void
    {
        $actorUserId = (int) Auth::createUser(
            'auto-admin-platform-actor@example.test',
            'P@ssword123!',
            'admin',
            'Auto',
            'Admin'
        );
        $service = new PlatformAutoAdminSettingsService();

        $service->setEnabled(true, $actorUserId);

        $row = Database::queryOne("SELECT enabled, updated_by_user_id FROM platform_auto_admin_settings WHERE id = 1");
        $this->assertSame(1, (int) ($row['enabled'] ?? 0));
        $this->assertSame($actorUserId, (int) ($row['updated_by_user_id'] ?? 0));
        $this->assertTrue($service->isEnabled());

        $service->setEnabled(false, $actorUserId);

        $this->assertFalse($service->isEnabled());
    }

    public function testRecreatesMissingSingletonFromLegacyPreference(): void
    {
        (new UserPreferences())->setAutoAdminEnabled(1, true);
        Database::execute("DELETE FROM platform_auto_admin_settings WHERE id = 1");

        $service = new PlatformAutoAdminSettingsService();

        $this->assertTrue($service->isEnabled());
        $row = Database::queryOne("SELECT enabled FROM platform_auto_admin_settings WHERE id = 1");
        $this->assertSame(1, (int) ($row['enabled'] ?? 0));
    }

    public function testCanPersistSystemActorWithoutUserOneDependency(): void
    {
        $service = new PlatformAutoAdminSettingsService();

        $service->setEnabled(true, 0);

        $row = Database::queryOne("SELECT enabled, updated_by_user_id FROM platform_auto_admin_settings WHERE id = 1");
        $this->assertSame(1, (int) ($row['enabled'] ?? 0));
        $this->assertNull($row['updated_by_user_id'] ?? null);
    }
}
