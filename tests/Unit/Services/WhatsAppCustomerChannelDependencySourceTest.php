<?php

namespace CRM\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class WhatsAppCustomerChannelDependencySourceTest extends TestCase
{
    public function testCustomerWhatsAppSurfacesDoNotDependOnAssistantPlugin(): void
    {
        $paths = [
            'api/mobile/compose.php',
            'api/mobile/conversations/reply.php',
            'api/bulk_messaging.php',
            'public/bulk_whatsapp.php',
            'public/contacts.php',
            'public/inbox.php',
            'public/whatsapp_compose.php',
            'services/MobileConversationDraftService.php',
        ];

        foreach ($paths as $relativePath) {
            $source = $this->source($relativePath);
            $this->assertStringNotContainsString(
                'PLUGIN_WHATSAPP_ASSISTANT',
                $source,
                $relativePath . ' must be gated by the WhatsApp channel, not WhatsApp Assistant.'
            );
            $this->assertStringNotContainsString(
                'module=whatsapp_assistant',
                $source,
                $relativePath . ' must link customer setup to the base WhatsApp plugin.'
            );
        }
    }

    public function testCustomerSendSurfacesUseChannelSpecificReadiness(): void
    {
        $this->assertStringContainsString(
            "isChannelRuntimeReady(\$workspaceId, \$channel",
            $this->source('api/mobile/compose.php')
        );
        $this->assertStringContainsString(
            "isChannelRuntimeReady(\$workspaceId, 'whatsapp'",
            $this->source('api/bulk_messaging.php')
        );
        $this->assertStringContainsString(
            "enforceWebChannelRuntime(\$workspaceId, 'whatsapp'",
            $this->source('public/bulk_whatsapp.php')
        );
        $this->assertStringContainsString(
            "isChannelRuntimeReady(\$workspaceId, 'whatsapp'",
            $this->source('public/contacts.php')
        );
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
