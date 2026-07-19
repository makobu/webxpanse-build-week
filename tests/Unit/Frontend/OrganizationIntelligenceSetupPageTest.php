<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class OrganizationIntelligenceSetupPageTest extends TestCase
{
    public function testSetupRequiredPanelsAndSettingsLinkArePresent(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/organization_intelligence_setup.php');

        $this->assertStringContainsString('Finish setup before opening Organization Intelligence', $source);
        $this->assertStringContainsString('Set up Organization Intelligence', $source);
        $this->assertStringContainsString('Guided Setup', $source);
        $this->assertStringContainsString('Business Areas', $source);
        $this->assertStringContainsString('Teams & HR Structure', $source);
        $this->assertStringContainsString('What This Helps You Understand', $source);
        $this->assertStringContainsString('Advanced area details', $source);
        $this->assertStringContainsString('Owner coverage is handled from user accounts', $source);
        $this->assertStringNotContainsString('People & Responsibilities', $source);
        $this->assertStringNotContainsString('Apply selected responsibilities', $source);
        $this->assertStringNotContainsString('apply_suggested_assignments', $source);
        $this->assertStringContainsString('organization-intelligence-setup.css', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringContainsString('workspace_skills.php?module=', $source);
        $this->assertStringContainsString('#setup', $source);
    }
}
