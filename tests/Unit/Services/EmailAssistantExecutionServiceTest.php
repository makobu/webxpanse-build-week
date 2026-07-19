<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailAssistantExecutionService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantExecutionServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::activateRuntimeWorkspace(1);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function testWhatsappApprovalQueueAndRunUseWhatsappProvenance(): void
    {
        $userId = $this->createAdminUser('assistant-queue-whatsapp@example.test');
        $service = new EmailAssistantExecutionService('whatsapp');

        $queueId = $service->queueForApproval(
            [
                'resolution_status' => 'resolved',
                'confidence' => 0.91,
                'actions' => [['action' => 'send_customer_reply']],
            ],
            [
                'assistant_type' => 'whatsapp',
                'assistant_source' => 'whatsapp_assistant_inbound',
                'assistant_channel' => 'whatsapp',
                'intent' => 'send_customer_reply',
                'query' => 'Send the customer reply after approval',
            ],
            $userId,
            null,
            'Approval required',
            1
        );

        $row = Database::queryOne(
            "SELECT q.assistant_type AS queue_assistant_type,
                    q.channel AS queue_channel,
                    r.assistant_type AS run_assistant_type,
                    r.source AS run_source
             FROM email_assistant_action_queue q
             INNER JOIN email_assistant_runs r ON r.id = q.run_id
             WHERE q.id = ?
             LIMIT 1",
            [$queueId]
        );

        $this->assertSame('whatsapp', (string) ($row['queue_assistant_type'] ?? ''));
        $this->assertSame('whatsapp', (string) ($row['queue_channel'] ?? ''));
        $this->assertSame('whatsapp', (string) ($row['run_assistant_type'] ?? ''));
        $this->assertSame('whatsapp_assistant_inbound', (string) ($row['run_source'] ?? ''));
    }

    private function createAdminUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }
}
