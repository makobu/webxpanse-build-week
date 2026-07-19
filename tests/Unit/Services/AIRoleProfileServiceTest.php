<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIRoleProfileService;
use CRM\Tests\DatabaseTestCase;

class AIRoleProfileServiceTest extends DatabaseTestCase
{
    public function testMapsAdminToFounderProfile(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['role-founder@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $profile = (new AIRoleProfileService())->buildProfile($userId);

        $this->assertSame('founder', $profile['role_profile']);
        $this->assertSame('admin', $profile['source_role']);
        $this->assertNotEmpty($profile['priority_focus']);
    }

    public function testMapsSalesToSalesRepProfile(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'sales', NOW())",
            ['role-sales@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $profile = (new AIRoleProfileService())->buildProfile($userId);

        $this->assertSame('sales_rep', $profile['role_profile']);
        $this->assertSame('sales', $profile['source_role']);
        $this->assertStringContainsString('Sales profile', $profile['summary']);
        $this->assertSame('aggressive', $profile['threshold_posture']);
        $this->assertSame('user_record', $profile['source_role_origin']);
    }

    public function testMapsOperationsAndSupportToRicherProfiles(): void
    {
        $service = new AIRoleProfileService();
        $opsProfile = $service->buildProfileFromSourceRole('operations');
        $supportProfile = $service->buildProfileFromSourceRole('support');

        $this->assertSame('ops_admin', $opsProfile['role_profile']);
        $this->assertSame('conservative', $opsProfile['threshold_posture']);
        $this->assertSame('daily_to_weekly', $opsProfile['execution_horizon']);
        $this->assertSame('explicit_input', $opsProfile['source_role_origin']);
        $this->assertSame('support_operator', $supportProfile['role_profile']);
        $this->assertSame('same_day', $supportProfile['execution_horizon']);
        $this->assertNotEmpty($supportProfile['prompt_bias']['framing'] ?? null);
    }
}
