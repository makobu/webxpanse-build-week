<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class DashboardMetricIconsTest extends TestCase
{
    public function testDashboardMetricCardsUseSupportedFontAwesomeIcons(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $dashboard = (string) $dashboard;

        $this->assertStringContainsString('<div class="metric-label">Leads Today</div>', $dashboard);
        $this->assertStringContainsString('<div class="metric-icon"><i class="fas fa-users"></i></div>', $dashboard);
        $this->assertStringContainsString('htmlspecialchars($dashboardPossessiveLabel); ?> Tasks</div>', $dashboard);
        $this->assertStringContainsString('<div class="metric-icon"><i class="fas fa-tasks"></i></div>', $dashboard);

        $this->assertStringNotContainsString('fa-people-group', $dashboard);
        $this->assertStringNotContainsString('fa-clipboard-check', $dashboard);
    }
}
