<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailQueue;
use CRM\Services\EmailService;
use CRM\Tests\DatabaseTestCase;

class EmailQueueTest extends DatabaseTestCase
{
    public function testGetStatsCanBeScopedByWorkspace(): void
    {
        $workspaceTwo = $this->createWorkspace('email-queue-stats');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (1, ?, 'Queue', 'One', 'queue-one@example.test', NOW())",
            [uuid_v4()]
        );
        $contactOne = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, 'Queue', 'Two', 'queue-two@example.test', NOW())",
            [$workspaceTwo, uuid_v4()]
        );
        $contactTwo = (int) Database::lastInsertId();

        $emailService = new EmailService();
        $emailService->send($contactOne, 'one@example.test', 'One', 'Body', ['workspace_id' => 1]);
        $emailService->send($contactTwo, 'two@example.test', 'Two', 'Body', ['workspace_id' => $workspaceTwo]);

        $queue = new EmailQueue();
        $workspaceOneStats = $queue->getStats(1);
        $workspaceTwoStats = $queue->getStats($workspaceTwo);

        $this->assertSame(1, (int) ($workspaceOneStats['total'] ?? 0));
        $this->assertSame(1, (int) ($workspaceTwoStats['total'] ?? 0));
        $this->assertSame(1, (int) ($workspaceOneStats['pending'] ?? 0));
        $this->assertSame(1, (int) ($workspaceTwoStats['pending'] ?? 0));
    }

    public function testPopBackfillsWorkspaceIdFromOwnedEmailRow(): void
    {
        $workspaceId = $this->createWorkspace('email-queue-backfill');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Queue', 'Email', 'queue-email@example.com', '254700111999', NOW())",
            [$workspaceId, uuid_v4()]
        );
        $contactId = (int) Database::lastInsertId();

        $emailService = new EmailService();
        $uuid = $emailService->send(
            $contactId,
            'recipient@example.com',
            'Queue subject',
            'Queue body',
            ['workspace_id' => $workspaceId]
        );

        $email = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
        $this->assertNotNull($email);

        Database::execute(
            "UPDATE email_queue
             SET workspace_id = NULL
             WHERE email_id = ?",
            [(int) $email['id']]
        );

        $queue = new EmailQueue();
        $job = $queue->pop();
        $queueRow = Database::queryOne(
            "SELECT workspace_id, status
             FROM email_queue
             WHERE email_id = ?",
            [(int) $email['id']]
        );

        $this->assertNotNull($job);
        $this->assertSame($workspaceId, (int) ($job['queue_workspace_id'] ?? 0));
        $this->assertSame($workspaceId, (int) ($queueRow['workspace_id'] ?? 0));
        $this->assertSame('processing', (string) ($queueRow['status'] ?? ''));
    }

    public function testClaimManagedDeliveryLeavesFinalizationToQueueOwner(): void
    {
        Database::execute("UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1");
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (1, ?, 'Claim', 'Managed', 'claim-managed@example.test', NOW())",
            [uuid_v4()]
        );
        $contactId = (int) Database::lastInsertId();

        try {
            $emailService = new EmailService();
            $uuid = $emailService->send(
                $contactId,
                'recipient@example.test',
                'Claim-managed queue delivery',
                'Body',
                ['workspace_id' => 1]
            );
            $email = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
            $this->assertNotNull($email);

            $queue = new EmailQueue();
            $job = $queue->pop(1);
            $this->assertNotNull($job);

            $lastError = null;
            $result = $emailService->processEmailDetailed(
                (int) $email['id'],
                $lastError,
                ['queue_claim_managed' => true],
                1
            );
            $this->assertTrue((bool) ($result['success'] ?? false), (string) ($result['error'] ?? ''));

            $processingRow = Database::queryOne(
                "SELECT status FROM email_queue WHERE id = ? AND workspace_id = ?",
                [(int) $job['queue_id'], 1]
            );
            $emailRow = Database::queryOne(
                "SELECT status FROM emails WHERE id = ? AND workspace_id = ?",
                [(int) $email['id'], 1]
            );
            $this->assertSame('processing', (string) ($processingRow['status'] ?? ''));
            $this->assertSame('sent', (string) ($emailRow['status'] ?? ''));

            $queue->ack((int) $job['queue_id'], 1, (string) $job['claim_token']);
            $completedRow = Database::queryOne(
                "SELECT status FROM email_queue WHERE id = ? AND workspace_id = ?",
                [(int) $job['queue_id'], 1]
            );
            $this->assertSame('completed', (string) ($completedRow['status'] ?? ''));
        } finally {
            Database::execute("UPDATE demo_mode_state SET is_enabled = 0, simulation_only = 1 WHERE id = 1");
        }
    }

    private function createWorkspace(string $slugPrefix): int
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [uuid_v4(), 'Email Queue Test Workspace', $slugPrefix . '-' . $suffix]
        );

        return (int) Database::lastInsertId();
    }
}
