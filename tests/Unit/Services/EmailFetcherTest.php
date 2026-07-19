<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailFetcher;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailFetcherTest extends DatabaseTestCase
{
    private EmailFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new EmailFetcher();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [function_exists('uuid_v4') ? uuid_v4() : bin2hex(random_bytes(16)), 'fetch-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
    }

    public function testStartImportRunPersistsWorkspaceId(): void
    {
        $fetchLogId = $this->fetcher->startImportRun(null, 'worker');

        $row = Database::queryOne(
            "SELECT workspace_id, source
             FROM email_fetch_log
             WHERE id = ?",
            [$fetchLogId]
        );

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['workspace_id'] ?? 0));
        $this->assertSame('worker', (string) ($row['source'] ?? ''));
    }

    public function testCreateContactAndCommunicationStayInsideActiveWorkspace(): void
    {
        $contactId = $this->fetcher->createContactIfNotExists('inbound@example.com', 'Inbound Sender');
        $contact = Database::queryOne(
            "SELECT workspace_id, email
             FROM contacts
             WHERE id = ?",
            [$contactId]
        );

        $this->assertNotNull($contact);
        $this->assertSame(1, (int) ($contact['workspace_id'] ?? 0));
        $this->assertSame('inbound@example.com', (string) ($contact['email'] ?? ''));

        $communicationId = $this->fetcher->addToCommunications([
            'from_email' => 'inbound@example.com',
            'to_email' => 'owner@example.com',
            'from_name' => 'Inbound Sender',
            'subject' => 'Hello',
            'body' => 'Workspace-scoped import',
            'body_html' => '<p>Workspace-scoped import</p>',
            'date' => date('Y-m-d H:i:s'),
            'uid' => 123,
            'message_id' => '<workspace-import@example.com>',
            'in_reply_to' => null,
        ], $contactId);

        $communication = Database::queryOne(
            "SELECT workspace_id, contact_id, message_id
             FROM communications
             WHERE id = ?",
            [$communicationId]
        );

        $this->assertNotNull($communication);
        $this->assertSame(1, (int) ($communication['workspace_id'] ?? 0));
        $this->assertSame($contactId, (int) ($communication['contact_id'] ?? 0));
        $this->assertSame('<workspace-import@example.com>', (string) ($communication['message_id'] ?? ''));
    }

    public function testAssistantFetchHistoryIsScopedToActiveWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Second Workspace', 'second-workspace', 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = 'active', plan_status = 'trialing'",
            ['00000000-0000-4000-8000-000000000002']
        );
        Database::execute(
            "INSERT INTO email_assistant_fetch_log (workspace_id, last_uid, last_fetch_at, emails_fetched, status)
             VALUES (1, 25, '2026-01-01 08:00:00', 1, 'success'),
                    (2, 77, '2026-01-01 09:00:00', 1, 'success')"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $workspaceOne = $this->invokeAssistantLastFetchInfo();
        WorkspaceContext::activateRuntimeWorkspace(2);
        $workspaceTwo = $this->invokeAssistantLastFetchInfo();

        $this->assertSame(25, (int) ($workspaceOne['last_uid'] ?? 0));
        $this->assertSame(77, (int) ($workspaceTwo['last_uid'] ?? 0));
    }

    public function testFetchCheckpointAdvancesOnlyAfterExplicitCommit(): void
    {
        $lastFetchedUid = new \ReflectionProperty($this->fetcher, 'lastFetchedUid');
        $lastFetchedUid->setAccessible(true);
        $pendingFetchedUid = new \ReflectionProperty($this->fetcher, 'pendingFetchedUid');
        $pendingFetchedUid->setAccessible(true);

        $lastFetchedUid->setValue($this->fetcher, 10);
        $pendingFetchedUid->setValue($this->fetcher, 25);
        $this->fetcher->discardFetchCheckpoint();
        $this->assertSame(10, $this->fetcher->getLastFetchedUid());

        $pendingFetchedUid->setValue($this->fetcher, 25);
        $this->fetcher->commitFetchCheckpoint();
        $this->assertSame(25, $this->fetcher->getLastFetchedUid());
    }

    public function testAssistantImapCheckpointPersistsOnlyAfterCommit(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        $assistantFetcher = new EmailFetcher('assistant');
        $pendingFetchedUid = new \ReflectionProperty($assistantFetcher, 'pendingFetchedUid');
        $pendingFetchedUid->setAccessible(true);
        $lastFetchedCount = new \ReflectionProperty($assistantFetcher, 'lastFetchedCount');
        $lastFetchedCount->setAccessible(true);

        $before = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM email_assistant_fetch_log WHERE workspace_id = 1"
        )['c'] ?? 0);
        $pendingFetchedUid->setValue($assistantFetcher, 42);
        $lastFetchedCount->setValue($assistantFetcher, 3);
        $assistantFetcher->commitFetchCheckpoint();

        $committed = Database::queryOne(
            "SELECT last_uid, emails_fetched, status
             FROM email_assistant_fetch_log
             WHERE workspace_id = 1
             ORDER BY id DESC
             LIMIT 1"
        );
        $this->assertSame(42, (int) ($committed['last_uid'] ?? 0));
        $this->assertSame(3, (int) ($committed['emails_fetched'] ?? 0));
        $this->assertSame('success', (string) ($committed['status'] ?? ''));

        $pendingFetchedUid->setValue($assistantFetcher, 99);
        $assistantFetcher->discardFetchCheckpoint();
        $after = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM email_assistant_fetch_log WHERE workspace_id = 1"
        )['c'] ?? 0);
        $this->assertSame($before + 1, $after);
    }

    private function invokeAssistantLastFetchInfo(): array
    {
        $fetcher = new EmailFetcher('assistant');
        $method = new \ReflectionMethod($fetcher, 'getLastFetchInfo');
        $method->setAccessible(true);

        return (array) $method->invoke($fetcher);
    }
}
