<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\PlatformSecuritySettingsService;
use CRM\Tests\DatabaseTestCase;

class PlatformSecuritySettingsServiceTest extends DatabaseTestCase
{
    public function testDefaultsToNotRequiringSuperAdminWorkspace2FA(): void
    {
        $service = new PlatformSecuritySettingsService();

        $settings = $service->getSettings();

        $this->assertFalse($settings['require_superadmin_workspace_2fa']);
        $this->assertFalse($service->requiresSuperadminWorkspace2FA());
    }

    public function testCanToggleSuperAdminWorkspace2FARequirement(): void
    {
        $service = new PlatformSecuritySettingsService();

        $service->setRequireSuperadminWorkspace2FA(true, 0);
        $enabled = Database::queryOne("SELECT require_superadmin_workspace_2fa, updated_by_user_id FROM platform_security_settings WHERE id = 1");

        $this->assertSame(1, (int) ($enabled['require_superadmin_workspace_2fa'] ?? 0));
        $this->assertNull($enabled['updated_by_user_id'] ?? null);
        $this->assertTrue($service->requiresSuperadminWorkspace2FA());

        $service->setRequireSuperadminWorkspace2FA(false, 0);

        $this->assertFalse($service->requiresSuperadminWorkspace2FA());
    }
}
