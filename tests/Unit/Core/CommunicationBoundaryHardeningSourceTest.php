<?php

namespace CRM\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

class CommunicationBoundaryHardeningSourceTest extends TestCase
{
    public function testWhatsAppWebAndApiSurfacesEnforceWorkspaceAndPermissions(): void
    {
        $root = dirname(__DIR__, 3);
        $messages = (string) file_get_contents($root . '/public/whatsapp_messages.php');
        $send = (string) file_get_contents($root . '/api/whatsapp_send.php');
        $process = (string) file_get_contents($root . '/api/process_whatsapp_queue.php');

        $this->assertStringContainsString('WHERE wm.workspace_id = ?', $messages);
        $this->assertStringContainsString("Authorization::requirePermission('whatsapp.messages.view')", $messages);
        $this->assertStringContainsString("Authorization::requirePermission('whatsapp.messages.send', true)", $send);
        $this->assertStringContainsString('Security::validateCSRF($csrfToken)', $send);
        $this->assertStringContainsString("Authorization::can('whatsapp.queue.manage'", $process);
        $this->assertStringContainsString('An active workspace is required', $process);
    }

    public function testWebhookAndWorkersUseAuthenticWorkspaceBoundExecution(): void
    {
        $root = dirname(__DIR__, 3);
        $endpoint = (string) file_get_contents($root . '/api/webhooks/whatsapp.php');
        $processor = (string) file_get_contents($root . '/services/WhatsAppQueueProcessor.php');
        $sessionWorker = (string) file_get_contents($root . '/cli/whatsapp_assistant_session_worker.php');

        $this->assertStringContainsString('MetaWebhookSignatureVerifier', $endpoint);
        $this->assertStringContainsString('HTTP_X_HUB_SIGNATURE_256', $endpoint);
        $this->assertStringNotContainsString('private WhatsAppService $whatsappService', $processor);
        $this->assertStringContainsString('$job = $this->queue->pop($queueWorkspaceScope)', $processor);
        $this->assertStringContainsString('$jobWorkspaceId = (int)', $processor);
        $this->assertStringContainsString('$whatsappService = $this->createWhatsAppService()', $processor);
        $this->assertStringContainsString('runMaintenanceForWorkspaces', $sessionWorker);
    }

    public function testEmailFetchRequiresCsrfAndDefersCheckpointCommit(): void
    {
        $root = dirname(__DIR__, 3);
        $endpoint = (string) file_get_contents($root . '/api/fetch_emails.php');
        $fetcher = (string) file_get_contents($root . '/services/EmailFetcher.php');

        $this->assertStringContainsString("REQUEST_METHOD'] ?? 'GET') !== 'POST'", $endpoint);
        $this->assertStringContainsString('Security::validateCSRF($csrfToken)', $endpoint);
        $this->assertStringContainsString('commitFetchCheckpoint()', $endpoint);
        $this->assertStringNotContainsString('$this->emailIntegrationService->mergeSettings((int) $integration[\'id\']', $fetcher);
        $this->assertStringContainsString('public function commitFetchCheckpoint()', $fetcher);
    }
}
