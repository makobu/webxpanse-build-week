<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceOnboardingService;
use PHPUnit\Framework\TestCase;

class WorkspaceOnboardingLaunchAvailabilityTest extends TestCase
{
    public function testLaunchRequiresCoreReadinessOnly(): void
    {
        $service = new WorkspaceOnboardingService();

        $this->assertTrue($service->launchAvailable([
            'profile_ready' => true,
            'voice_ready' => false,
            'offer_ready' => false,
            'autopilot_ready' => false,
        ]));
    }

    public function testLaunchUnavailableWhenCoreReadinessIsMissing(): void
    {
        $service = new WorkspaceOnboardingService();

        $this->assertFalse($service->launchAvailable([
            'profile_ready' => false,
            'voice_ready' => true,
            'offer_ready' => true,
            'autopilot_ready' => true,
        ]));
        $this->assertSame(1, $service->firstMissingLaunchStep([
            'profile_ready' => false,
            'voice_ready' => false,
            'offer_ready' => true,
            'autopilot_ready' => true,
        ]));
    }
}
