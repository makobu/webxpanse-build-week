<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\AIService;
use CRM\Services\AIAutoResponderDispatcher;
use CRM\Services\AIAutoResponderService;
use CRM\Services\WorkspaceAIAutoResponderQuietHoursService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AIAutoResponderServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at) VALUES (?, ?, ?, 'admin', 'Auto', 'Responder', NOW())",
            [uuid_v4(), 'autoresponder@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, assigned_to, created_at)
             VALUES (1, ?, 'Auto', 'Responder', 'auto@example.com', '+15550001111', ?, NOW())",
            [uniqid('contact_', true), $this->userId]
        );

        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
             VALUES (1, ?, 1, 'email', 'inbound', 'Need pricing', 'Can you share pricing?', 'received', '{}', NOW())",
            [uniqid('comm_', true)]
        );
    }

    public function testProcessQueueItemSkipsWhenFeatureDisabled(): void
    {
        $service = new AIAutoResponderService();

        $result = $service->processQueueItem([
            'id' => 1,
            'workspace_id' => 1,
            'communication_id' => 1,
            'contact_id' => 1,
            'channel' => 'email',
            'message_text' => 'Hello there',
            'normalized_payload' => json_encode([
                'communication_id' => 1,
                'contact_id' => 1,
                'channel' => 'email',
                'direction' => 'inbound',
                'message_text' => 'Hello there',
            ]),
        ]);

        $this->assertSame('skipped', $result['action']);
        $this->assertSame('feature_disabled', $result['reason']);

        $log = Database::queryOne("SELECT * FROM ai_autoresponder_logs ORDER BY id DESC LIMIT 1");
        $this->assertSame('skipped', $log['decision']);
        $this->assertSame('feature_disabled', $log['reason_code']);
        $this->assertSame(1, (int) ($log['workspace_id'] ?? 0));
    }

    public function testProcessQueueItemDraftsWhenConfidenceFallsBelowConfiguredThreshold(): void
    {
        (new UserStrategyProfile())->save($this->userId, [
            'draft_tone_preset' => 'warm',
            'draft_voice_notes' => 'Keep it warm and concise.',
            'draft_cta_style' => 'soft',
            'draft_reading_level' => 'junior_high',
        ]);
        (new AIAutoResponderConfig())->save([
            'enabled' => true,
            'mode' => 'hybrid',
            'default_confidence_threshold' => 0.88,
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.88,
                    'max_chars' => 4000,
                    'draft_length_band' => 'balanced',
                    'draft_fullness' => 'concise',
                    'draft_include_clear_cta' => true,
                ],
            ],
        ]);

        $service = new AIAutoResponderService();
        $this->injectPrivate($service, 'aiService', new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                \PHPUnit\Framework\Assert::assertStringContainsString(
                    'Keep it warm and concise.',
                    (string) ($data['options']['draft_style_instructions'] ?? '')
                );
                \PHPUnit\Framework\Assert::assertStringContainsString(
                    'Language level: Level 1',
                    (string) ($data['options']['draft_style_instructions'] ?? '')
                );
                return json_encode([
                    'subject' => 'Re: Need pricing',
                    'reply_text' => 'Here is our pricing information.',
                    'confidence' => 0.61,
                    'requires_human' => false,
                ]);
            }
        });
        $this->injectPrivate($service, 'dispatcher', new class extends AIAutoResponderDispatcher {
            public function dispatch(string $channel, int $contactId, array $sourceCommunication, array $reply): array
            {
                return ['success' => true];
            }
        });

        $result = $service->processQueueItem([
            'id' => 2,
            'workspace_id' => 1,
            'communication_id' => 1,
            'contact_id' => 1,
            'channel' => 'email',
            'message_text' => 'Can you share pricing?',
            'normalized_payload' => json_encode([
                'communication_id' => 1,
                'contact_id' => 1,
                'channel' => 'email',
                'direction' => 'inbound',
                'message_text' => 'Can you share pricing?',
            ]),
        ]);

        $this->assertSame('draft', $result['action']);

        $log = Database::queryOne("SELECT * FROM ai_autoresponder_logs ORDER BY id DESC LIMIT 1");
        $this->assertSame('draft', $log['decision']);
        $this->assertSame('pending_review', $log['status']);
        $this->assertSame('below_threshold', $log['reason_code']);
        $this->assertSame(1, (int) ($log['workspace_id'] ?? 0));
    }

    public function testProcessQueueItemAutoSentEmailIncludesDefaultUserSignature(): void
    {
        Database::execute(
            "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default, created_at, updated_at)
             VALUES (?, 'Primary', '<div><strong>Auto Responder</strong><br>Support Team</div>', 'Auto Responder\nSupport Team', 1, NOW(), NOW())",
            [$this->userId]
        );

        (new AIAutoResponderConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'default_confidence_threshold' => 0.80,
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.80,
                    'max_chars' => 4000,
                    'draft_length_band' => 'balanced',
                    'draft_fullness' => 'concise',
                    'draft_include_clear_cta' => true,
                ],
            ],
        ]);

        $service = new AIAutoResponderService();
        $this->injectPrivate($service, 'aiService', new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                return json_encode([
                    'subject' => 'Re: Need pricing',
                    'reply_text' => 'Thanks for reaching out. We can send the latest pricing today.',
                    'confidence' => 0.97,
                    'requires_human' => false,
                ]);
            }
        });

        $capture = new \stdClass();
        $capture->reply = null;
        $this->injectPrivate($service, 'dispatcher', new class($capture) extends AIAutoResponderDispatcher {
            private \stdClass $capture;

            public function __construct(\stdClass $capture)
            {
                $this->capture = $capture;
            }

            public function dispatch(string $channel, int $contactId, array $sourceCommunication, array $reply): array
            {
                $this->capture->reply = $reply;
                return ['success' => true, 'subject' => $reply['subject'] ?? '', 'body_text' => $reply['body_text'] ?? '', 'body_html' => $reply['body_html'] ?? ''];
            }
        });

        $result = $service->processQueueItem([
            'id' => 3,
            'workspace_id' => 1,
            'communication_id' => 1,
            'contact_id' => 1,
            'channel' => 'email',
            'message_text' => 'Can you share pricing?',
            'normalized_payload' => json_encode([
                'communication_id' => 1,
                'contact_id' => 1,
                'channel' => 'email',
                'direction' => 'inbound',
                'message_text' => 'Can you share pricing?',
            ]),
        ]);

        $this->assertSame('auto_sent', $result['action']);
        $this->assertIsArray($capture->reply);
        $this->assertStringContainsString('Auto Responder', (string) ($capture->reply['body_text'] ?? ''));
        $this->assertStringContainsString('<strong>Auto Responder</strong>', (string) ($capture->reply['body_html'] ?? ''));
        $this->assertSame($this->userId, (int) ($capture->reply['sender_user_id'] ?? 0));
    }

    public function testProcessQueueItemUsesStoredWorkspaceInsteadOfAmbientRuntimeWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Auto Workspace', 'auto-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, assigned_to, created_at)
             VALUES (2, ?, 'Workspace', 'Two', 'auto-two@example.com', '+15550002222', ?, NOW())",
            [uniqid('contact_', true), $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
             VALUES (2, ?, ?, 'email', 'inbound', 'Need follow-up', 'Please follow up', 'received', '{}', NOW())",
            [uniqid('comm_', true), $contactId]
        );
        $communicationId = (int) Database::lastInsertId();

        WorkspaceContext::activateRuntimeWorkspace(1);

        $service = new AIAutoResponderService();
        $result = $service->processQueueItem([
            'id' => 4,
            'workspace_id' => 2,
            'communication_id' => $communicationId,
            'contact_id' => $contactId,
            'channel' => 'email',
            'message_text' => 'Please follow up',
            'normalized_payload' => json_encode([
                'communication_id' => $communicationId,
                'contact_id' => $contactId,
                'channel' => 'email',
                'direction' => 'inbound',
                'message_text' => 'Please follow up',
            ]),
        ]);

        $this->assertSame('skipped', $result['action']);
        $log = Database::queryOne("SELECT * FROM ai_autoresponder_logs ORDER BY id DESC LIMIT 1");
        $this->assertSame(2, (int) ($log['workspace_id'] ?? 0));
        $this->assertSame($contactId, (int) ($log['contact_id'] ?? 0));
        $this->assertSame($communicationId, (int) ($log['communication_id'] ?? 0));
    }

    public function testProcessQueueItemUsesWorkspaceQuietHoursFromQueueWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Quiet Policy Workspace', 'quiet-policy-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000003']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, assigned_to, created_at)
             VALUES (2, ?, 'Quiet', 'Policy', 'quiet-policy@example.com', '+15550003333', ?, NOW())",
            [uniqid('contact_', true), $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
             VALUES (2, ?, ?, 'email', 'inbound', 'Need an answer', 'Can you answer this?', 'received', '{}', NOW())",
            [uniqid('comm_', true), $contactId]
        );
        $communicationId = (int) Database::lastInsertId();

        (new AIAutoResponderConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'default_confidence_threshold' => 0.80,
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.80,
                    'max_chars' => 4000,
                    'draft_length_band' => 'balanced',
                    'draft_fullness' => 'concise',
                    'draft_include_clear_cta' => true,
                ],
            ],
            'quiet_hours' => [
                'enabled' => false,
                'start' => '20:00',
                'end' => '08:00',
                'timezone' => 'UTC',
            ],
        ]);

        $quietHours = new WorkspaceAIAutoResponderQuietHoursService();
        $quietHours->save(1, [
            'enabled' => false,
            'start' => '20:00',
            'end' => '08:00',
            'timezone' => 'UTC',
        ], $this->userId);
        $quietHours->save(2, [
            'enabled' => true,
            'start' => '00:00',
            'end' => '23:59',
            'timezone' => 'UTC',
        ], $this->userId);

        WorkspaceContext::activateRuntimeWorkspace(1);

        $service = new AIAutoResponderService();
        $result = $service->processQueueItem([
            'id' => 5,
            'workspace_id' => 2,
            'communication_id' => $communicationId,
            'contact_id' => $contactId,
            'channel' => 'email',
            'message_text' => 'Can you answer this?',
            'normalized_payload' => json_encode([
                'communication_id' => $communicationId,
                'contact_id' => $contactId,
                'channel' => 'email',
                'direction' => 'inbound',
                'message_text' => 'Can you answer this?',
            ]),
        ]);

        $this->assertSame('review', $result['action']);
        $this->assertSame('quiet_hours', $result['reason']);

        $log = Database::queryOne("SELECT * FROM ai_autoresponder_logs ORDER BY id DESC LIMIT 1");
        $this->assertSame(2, (int) ($log['workspace_id'] ?? 0));
        $this->assertSame('draft', $log['decision']);
        $this->assertSame('pending_review', $log['status']);
        $this->assertSame('quiet_hours', $log['reason_code']);
    }

    private function injectPrivate(object $target, string $property, object $value): void
    {
        $reflection = new \ReflectionClass($target);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
