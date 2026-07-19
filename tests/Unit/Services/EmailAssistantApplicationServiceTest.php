<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailAssistantApplicationService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantApplicationServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['assistant-app@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "UPDATE company_profile SET company_name = ?, company_description = ?, is_active = 1 WHERE id = 1",
            ['Assistant App Co', 'Structured assistant testing']
        );
    }

    public function testHandleAdviceRequestReturnsCanonicalResponseShape(): void
    {
        $result = (new EmailAssistantApplicationService())->handleAdviceRequest([
            'intent' => 'summarize_thread_state',
            'query' => 'summarize communication 999',
            'confidence' => 0.10,
            'resolved' => true,
            'thread_context' => [],
            'primary_entities' => [],
        ], $this->userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('mode', $result);
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('run_id', $result);
        $this->assertArrayHasKey('resolution_status', $result);
        $this->assertArrayHasKey('execution_status', $result);
        $this->assertArrayHasKey('policy', $result);
        $this->assertArrayHasKey('draft', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('summary_text', $result);
        $this->assertSame('summarize_thread_state', $result['intent']);
        $this->assertSame('admin_command', $result['mode']);
        $this->assertSame('suggest_only', $result['policy']['decision']);
        $this->assertArrayHasKey('can_execute', $result['policy']);
        $this->assertArrayHasKey('approval_required', $result['policy']);
        $this->assertArrayHasKey('reasons', $result['policy']);
        $this->assertNotSame('', trim((string) $result['summary_text']));
    }

    public function testFormatUserFacingSummaryUsesPolicyAndResultData(): void
    {
        $summary = (new EmailAssistantApplicationService())->formatUserFacingSummary([
            'policy' => [
                'decision' => 'approval_required',
                'warnings' => ['manual_review'],
            ],
            'results' => [
                [
                    'action' => 'send_document',
                    'status' => 'queued_for_approval',
                    'approval_id' => 12,
                ],
            ],
        ]);

        $this->assertStringContainsString('Warnings: manual_review', $summary);
        $this->assertStringContainsString('Send document: queued_for_approval', $summary);
        $this->assertStringContainsString('Approval ID: 12', $summary);
    }

    public function testSendLatestQuoteRespectsWorkspaceCustomerSendFlag(): void
    {
        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'customer_thread_enabled' => true,
            'customer_send_enabled' => false,
        ], true, $this->userId);

        $result = (new EmailAssistantApplicationService())->sendCustomerThreadLatestQuote(99999, $this->userId);

        $this->assertSame('blocked', $result['resolution_status']);
        $this->assertSame('rejected', $result['execution_status']);
        $this->assertSame('blocked', $result['policy']['decision']);
        $this->assertContains('customer_send_disabled', $result['policy']['reasons']);
    }
}
