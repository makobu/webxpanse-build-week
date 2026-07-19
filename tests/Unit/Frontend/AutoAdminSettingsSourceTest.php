<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class AutoAdminSettingsSourceTest extends TestCase
{
    public function testManagedTabsRemainReadableAndWarmupTogglesAreDisabledWhenLocked(): void
    {
        $settings = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');
        $api = (string) file_get_contents(__DIR__ . '/../../../api/deals/automation_mode.php');

        $this->assertStringContainsString('Managed tabs remain visible for review', $settings);
        $this->assertStringContainsString('name="email_cold_outreach_enabled" value="1" <?php echo !empty($emailColdOutreachWarmup[\'enabled\']) ? \'checked\' : \'\'; ?> <?php echo $emailWarmupLocked ? \'disabled\' : \'\'; ?>', $settings);
        $this->assertStringContainsString('name="email_auto_admin_warmup_enabled" value="1" <?php echo !empty($emailColdOutreachWarmup[\'auto_admin_warmup_enabled\']) ? \'checked\' : \'\'; ?> <?php echo $emailWarmupLocked ? \'disabled\' : \'\'; ?>', $settings);
        $this->assertStringContainsString('name="whatsapp_cold_outreach_enabled" value="1" <?php echo !empty($whatsappColdOutreachWarmup[\'enabled\']) ? \'checked\' : \'\'; ?> <?php echo $whatsappWarmupLocked ? \'disabled\' : \'\'; ?>', $settings);
        $this->assertStringContainsString('name="whatsapp_auto_admin_warmup_enabled" value="1" <?php echo !empty($whatsappColdOutreachWarmup[\'auto_admin_warmup_enabled\']) ? \'checked\' : \'\'; ?> <?php echo $whatsappWarmupLocked ? \'disabled\' : \'\'; ?>', $settings);
        $this->assertStringContainsString('new DealAutomationModeService($config))->save', $settings);
        $this->assertStringContainsString('(new DealAutomationModeService())->updateMode', $api);
        $this->assertStringNotContainsString('Managed tabs are locked and hidden', $settings);
    }
}
