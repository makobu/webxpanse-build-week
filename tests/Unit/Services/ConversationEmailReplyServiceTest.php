<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\ColdOutreachGovernanceService;
use CRM\Services\ConversationEmailReplyService;
use CRM\Services\EmailQueue;
use CRM\Services\EmailService;
use CRM\Services\EmailTemplates;
use CRM\Services\SMTPClient;
use CRM\Tests\DatabaseTestCase;

class ConversationEmailReplyServiceTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private int $contactId = 0;
    private int $userId = 0;
    private int $communicationId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['SMTP_FROM_EMAIL'] = 'sales@example.test';
        $_ENV['SMTP_FROM_NAME'] = 'Sales Team';
        $_ENV['APP_URL'] = 'http://workspace.example.test';

        $this->contacts = new Contacts();
        $contact = $this->contacts->create([
            'first_name' => 'Reply',
            'last_name' => 'Target',
            'email' => 'reply.target@example.test',
        ]);
        $this->contactId = (int) ($contact['id'] ?? 0);

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role)
             VALUES (?, ?, ?, 'admin')",
            [$this->uuid(), 'agent@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO communications
                (uuid, contact_id, channel, direction, subject, body, status, from_email, to_email, message_id, created_at)
             VALUES
                (?, ?, 'email', 'inbound', ?, ?, 'sent', ?, ?, ?, DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [
                $this->uuid(),
                $this->contactId,
                'Pricing follow-up',
                'Could you send the latest rollout plan?',
                'reply.target@example.test',
                'sales@example.test',
                '<seed-inbound@example.test>',
            ]
        );
        $this->communicationId = (int) Database::lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->contactId > 0) {
            $this->contacts->delete($this->contactId);
        }

        parent::tearDown();
    }

    public function testSendReplyCreatesSentEmailSyncedCommunicationAndAudit(): void
    {
        Database::execute(
            "UPDATE demo_mode_state
             SET is_enabled = 1, simulation_only = 1, updated_by = NULL
             WHERE id = 1"
        );

        $service = new ConversationEmailReplyService();
        $result = $service->sendReply($this->communicationId, $this->userId, 'Thanks, sharing it now.', null, 'mobile');

        $this->assertTrue((bool) ($result['success'] ?? false));

        $email = Database::queryOne(
            "SELECT *
             FROM emails
             WHERE id = ?",
            [(int) ($result['email_id'] ?? 0)]
        );
        $this->assertNotNull($email);
        $this->assertSame('sent', $email['status']);
        $this->assertSame('<seed-inbound@example.test>', $email['in_reply_to']);
        $this->assertNotEmpty($email['message_id']);
        $this->assertStringContainsString('<seed-inbound@example.test>', (string) $email['references_header']);

        $communication = Database::queryOne(
            "SELECT *
             FROM communications
             WHERE email_id = ?
             LIMIT 1",
            [(int) $email['id']]
        );
        $this->assertNotNull($communication);
        $this->assertSame('outbound', $communication['direction']);
        $this->assertSame('email', $communication['channel']);
        $this->assertSame($email['to_email'], $communication['to_email']);
        $this->assertSame($email['from_email'], $communication['from_email']);
        $this->assertSame($email['message_id'], $communication['message_id']);
        $this->assertSame($email['in_reply_to'], $communication['in_reply_to']);

        $audit = Database::queryOne(
            "SELECT *
             FROM email_reply_delivery_audit
             WHERE source_communication_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$this->communicationId]
        );
        $this->assertNotNull($audit);
        $this->assertSame('sent', $audit['status']);
        $this->assertSame('mobile', $audit['reply_surface']);
        $this->assertSame((string) $email['message_id'], (string) $audit['generated_message_id']);
        $this->assertSame('demo_simulation', $audit['smtp_method']);
    }

    public function testSendReplyRecordsFailureAuditWhenTransportFails(): void
    {
        Database::execute(
            "UPDATE demo_mode_state
             SET is_enabled = 0, simulation_only = 1, updated_by = NULL
             WHERE id = 1"
        );

        $smtp = new class extends SMTPClient {
            public function __construct()
            {
            }

            public function send(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null): bool
            {
                throw new \RuntimeException('Simulated SMTP failure');
            }

            public function sendWithHeaders(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): bool
            {
                throw new \RuntimeException('Simulated SMTP failure');
            }

            public function getProfileKey(): string
            {
                return 'default';
            }

            public function getLastMethodUsed(): ?string
            {
                return 'fake_failure';
            }
        };

        $emailService = new EmailService($smtp, new EmailQueue(), new EmailTemplates(), new ColdOutreachGovernanceService());
        $service = new ConversationEmailReplyService($emailService);

        $this->expectExceptionMessage('Simulated SMTP failure');
        try {
            $service->sendReply($this->communicationId, $this->userId, 'This should fail.', null, 'web');
        } finally {
            $email = Database::queryOne(
                "SELECT *
                 FROM emails
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $this->assertNotNull($email);
            $this->assertSame('failed', $email['status']);

            $audit = Database::queryOne(
                "SELECT *
                 FROM email_reply_delivery_audit
                 WHERE source_communication_id = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$this->communicationId]
            );
            $this->assertNotNull($audit);
            $this->assertSame('failed', $audit['status']);
            $this->assertStringContainsString('Simulated SMTP failure', (string) $audit['transport_error']);
            $this->assertSame('web', $audit['reply_surface']);
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
