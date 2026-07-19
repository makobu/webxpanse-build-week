<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\SMSQueue;
use CRM\Services\SMSService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class SMSQueueTest extends DatabaseTestCase
{
    private ?string $originalAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'sms-queue-test-key';
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->originalAppKey;
        }
        parent::tearDown();
    }

    public function testPopBackfillsWorkspaceIdFromOwnedMessageRow(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'SMS Queue Workspace', 'sms-queue-workspace', 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active'",
            ['00000000-0000-4000-8000-000000000002']
        );
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uuid_v4(), 'sms-queue-user@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $_SESSION['user_id'] = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (2, ?, 'Queue', 'SMS', 'queue-sms@example.com', '+254700222999', NOW())",
            [uuid_v4()]
        );
        $contactId = (int) Database::lastInsertId();
        (new WorkspaceSmsChannelConfigService())->save(2, [
            'enabled' => true,
            'account_sid' => 'test_sid',
            'auth_token' => 'test_token',
            'from_number' => '+254700000001',
        ]);
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_at)
             VALUES (2, ?, 'installed', '{}', NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL",
            [WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );

        $service = new SMSService();
        $uuid = $service->storeMessage($contactId, '+254700222999', 'Queue SMS body', ['workspace_id' => 2]);
        $message = Database::queryOne("SELECT id FROM sms_messages WHERE uuid = ? LIMIT 1", [$uuid]);
        $this->assertNotNull($message);

        Database::execute(
            "UPDATE sms_queue
             SET workspace_id = NULL
             WHERE message_id = ?",
            [(int) $message['id']]
        );

        $queue = new SMSQueue();
        $job = $queue->pop();
        $queueRow = Database::queryOne(
            "SELECT workspace_id, status
             FROM sms_queue
             WHERE message_id = ?",
            [(int) $message['id']]
        );

        $this->assertNotNull($job);
        $this->assertSame(2, (int) ($job['queue_workspace_id'] ?? 0));
        $this->assertSame(2, (int) ($queueRow['workspace_id'] ?? 0));
        $this->assertSame('processing', (string) ($queueRow['status'] ?? ''));
        $this->assertNotEmpty($job['claim_token'] ?? null);

        $this->assertNull($queue->pop(2, (int) $job['queue_id']));
        Database::execute(
            "UPDATE sms_queue SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?",
            [(int) $job['queue_id']]
        );
        $reclaimed = $queue->pop(2, (int) $job['queue_id']);
        $this->assertNotNull($reclaimed);
        $this->assertNotSame((string) $job['claim_token'], (string) $reclaimed['claim_token']);

        Database::execute(
            "UPDATE sms_queue
             SET status = 'failed', attempts = max_attempts, claim_token = NULL,
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = ?",
            [(int) $job['queue_id']]
        );
        $operatorReplay = $queue->pop(2, (int) $job['queue_id'], true);
        $this->assertNotNull($operatorReplay);
        $this->assertNotEmpty($operatorReplay['claim_token'] ?? null);

        $this->expectException(\RuntimeException::class);
        $queue->complete((int) $job['queue_id'], 2, (string) $job['claim_token']);
    }
}
