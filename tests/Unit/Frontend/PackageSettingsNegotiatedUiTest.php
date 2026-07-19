<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class PackageSettingsNegotiatedUiTest extends TestCase
{
    public function testPackageSettingsNegotiatedOffersHaveDedicatedSectionContract(): void
    {
        $settingsSource = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');
        $partialSource = (string) file_get_contents(__DIR__ . '/../../../views/partials/settings_package_settings.php');

        $this->assertStringContainsString("'negotiated_offers'", $settingsSource);
        $this->assertStringContainsString('package_section', $settingsSource);
        $this->assertStringContainsString('settingsPackageSettingsSectionUrl', $partialSource);
        $this->assertStringContainsString("package_section' => \$section", $partialSource);
        $this->assertStringContainsString('Workspaces With Negotiated Packages', $partialSource);
        $this->assertStringContainsString('Card autopay unavailable until private provider plan sync', $partialSource);
        $this->assertStringContainsString('Hidden from public package catalog', $partialSource);
        $this->assertStringContainsString('package-settings-negotiated-create', $partialSource);
        $this->assertStringContainsString('Search workspaces', $partialSource);
        $this->assertStringContainsString('data-workspace-search', $partialSource);
    }
}
