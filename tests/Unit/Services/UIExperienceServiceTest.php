<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\UserPreferences;
use CRM\Services\UIExperienceService;
use CRM\Tests\DatabaseTestCase;

class UIExperienceServiceTest extends DatabaseTestCase
{
    private UIExperienceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UIExperienceService();
    }

    public function testNormalUsersDefaultToBeginnerMode(): void
    {
        $user = ['id' => $this->createUser('beginner-mode@example.test'), 'role' => 'user'];

        $this->assertSame(UIExperienceService::MODE_BEGINNER, $this->service->modeForUser($user));
    }

    public function testPreferenceCanPromoteUserToAdvancedMode(): void
    {
        $user = ['id' => $this->createUser('advanced-mode@example.test'), 'role' => 'user'];
        (new UserPreferences())->setPreference((int) $user['id'], UIExperienceService::PREFERENCE_KEY, UIExperienceService::MODE_ADVANCED);

        $this->assertSame(UIExperienceService::MODE_ADVANCED, $this->service->modeForUser($user));
    }

    public function testBeginnerMarketingNavigationIsHiddenUntilCapabilityOrContextRequiresIt(): void
    {
        $user = ['id' => $this->createUser('beginner-marketing-nav@example.test'), 'role' => 'user'];

        $this->assertFalse($this->service->shouldShowAdvancedMarketingNavigation($user, null, 'dashboard.php'));
        $this->assertTrue($this->service->shouldShowAdvancedMarketingNavigation($user, null, 'dashboard.php', true));
        $this->assertTrue($this->service->shouldShowAdvancedMarketingNavigation($user, null, 'marketing.php'));
    }

    public function testMarketplaceLabelSoftensInBeginnerMode(): void
    {
        $this->assertSame('Add capabilities', $this->service->marketplaceLabel(UIExperienceService::MODE_BEGINNER));
        $this->assertSame('Marketplace', $this->service->marketplaceLabel(UIExperienceService::MODE_ADVANCED));
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('ui-experience-user-', true), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }
}
