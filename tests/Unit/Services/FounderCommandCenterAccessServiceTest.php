<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderCommandCenterAccessService;
use PHPUnit\Framework\TestCase;

final class FounderCommandCenterAccessServiceTest extends TestCase
{
    public function testOwnerAndWorkspaceAdminMembershipsCanView(): void
    {
        $this->assertTrue(FounderCommandCenterAccessService::membershipCanView([
            'role_slug' => 'viewer',
            'is_owner' => 1,
        ]));
        $this->assertTrue(FounderCommandCenterAccessService::membershipCanView([
            'role_slug' => 'owner',
            'is_owner' => 0,
        ]));
        $this->assertTrue(FounderCommandCenterAccessService::membershipCanView([
            'role_slug' => 'admin',
            'is_owner' => 0,
        ]));
    }

    public function testOrdinaryMembershipsAndMissingMembershipCannotView(): void
    {
        $this->assertFalse(FounderCommandCenterAccessService::membershipCanView([
            'role_slug' => 'manager',
            'is_owner' => 0,
        ]));
        $this->assertFalse(FounderCommandCenterAccessService::membershipCanView([
            'role_slug' => 'viewer',
            'is_owner' => 0,
        ]));
        $this->assertFalse(FounderCommandCenterAccessService::membershipCanView(null));
    }
}
