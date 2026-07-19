<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WhatsAppQueue;
use CRM\Services\WhatsAppService;
use CRM\Tests\DatabaseTestCase;

class WhatsAppQueueTest extends DatabaseTestCase
{
    public function testPopBackfillsWorkspaceIdFromOwnedMessageRow(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'WhatsApp Queue Workspace', ?, 'active', 'trialing', NOW(), NOW())",
            [uuid_v4(), 'whatsapp-queue-workspace-' . bin2hex(random_bytes(4))]
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Queue', 'WhatsApp', 'queue-whatsapp@example.com', '254700333999', NOW())",
            [$workspaceId, uuid_v4()]
        );
        $contactId = (int) Database::lastInsertId();

        $service = new WhatsAppService();
        $uuid = $service->storeMessage($contactId, '254700333999', 'text', 'Queue WhatsApp body', ['workspace_id' => $workspaceId]);
        $message = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
        $this->assertNotNull($message);

        Database::execute(
            "UPDATE whatsapp_queue
             SET workspace_id = NULL
             WHERE message_id = ?",
            [(int) $message['id']]
        );

        $queue = new WhatsAppQueue();
        $job = $queue->pop();
        $queueRow = Database::queryOne(
            "SELECT workspace_id, status
             FROM whatsapp_queue
             WHERE message_id = ?",
            [(int) $message['id']]
        );

        $this->assertNotNull($job);
        $this->assertSame($workspaceId, (int) ($job['queue_workspace_id'] ?? 0));
        $this->assertSame($workspaceId, (int) ($queueRow['workspace_id'] ?? 0));
        $this->assertSame('processing', (string) ($queueRow['status'] ?? ''));
    }

    public function testPopHonorsExplicitWorkspaceScope(): void
    {
        $workspaceOne = $this->createWorkspace('whatsapp-queue-scope-one');
        $workspaceTwo = $this->createWorkspace('whatsapp-queue-scope-two');
        $this->createQueuedMessage($workspaceOne, '254700330001');
        $this->createQueuedMessage($workspaceTwo, '254700330002');

        $queue = new WhatsAppQueue();
        $workspaceOneJob = $queue->pop($workspaceOne);
        $workspaceTwoJob = $queue->pop($workspaceTwo);

        $this->assertNotNull($workspaceOneJob);
        $this->assertNotNull($workspaceTwoJob);
        $this->assertSame($workspaceOne, (int) ($workspaceOneJob['queue_workspace_id'] ?? 0));
        $this->assertSame($workspaceTwo, (int) ($workspaceTwoJob['queue_workspace_id'] ?? 0));
    }

    private function createWorkspace(string $slugPrefix): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'WhatsApp Queue Scope Workspace', ?, 'active', 'trialing', NOW(), NOW())",
            [uuid_v4(), $slugPrefix . '-' . bin2hex(random_bytes(4))]
        );

        return (int) Database::lastInsertId();
    }

    private function createQueuedMessage(int $workspaceId, string $phone): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Queue', 'Scope', ?, ?, NOW())",
            [$workspaceId, uuid_v4(), 'queue-' . $workspaceId . '@example.test', $phone]
        );
        $contactId = (int) Database::lastInsertId();

        (new WhatsAppService())->storeMessage(
            $contactId,
            $phone,
            'text',
            'Workspace-scoped queue body',
            ['workspace_id' => $workspaceId]
        );
    }
}
