<?php

namespace CRM\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class WhatsAppAssistantSessionServiceSourceTest extends TestCase
{
    public function testKeepaliveReminderLinksToWhatsAppAssistantSetup(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../services/WhatsAppAssistantSessionService.php');

        $this->assertStringContainsString('workspace_skills.php?module=whatsapp_assistant&setup_tab=identity#setup', $source);
        $this->assertStringNotContainsString('workspace_skills.php?module=email_assistant&setup_tab=overview#setup', $source);
    }
}
