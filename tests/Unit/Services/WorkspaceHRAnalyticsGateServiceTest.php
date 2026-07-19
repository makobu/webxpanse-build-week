<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceHRAnalyticsGateService;
use PHPUnit\Framework\TestCase;

class WorkspaceHRAnalyticsGateServiceTest extends TestCase
{
    public function testSetupRequiredUrlPointsAdminsToDedicatedSetupCenter(): void
    {
        $this->assertSame(
            'organization_intelligence_setup.php?setup_required=hr_analytics',
            (new WorkspaceHRAnalyticsGateService())->setupRequiredUrl()
        );
    }
}
