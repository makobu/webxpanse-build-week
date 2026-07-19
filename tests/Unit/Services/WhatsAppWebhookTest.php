<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WhatsAppWebhook;
use CRM\Tests\DatabaseTestCase;

class WhatsAppWebhookTest extends DatabaseTestCase
{
    private array $originalEnv = [];
    private Contacts $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contacts = new Contacts();
        $this->originalEnv = [
            'WHATSAPP_ASSISTANT_ENABLED' => $_ENV['WHATSAPP_ASSISTANT_ENABLED'] ?? null,
            'WHATSAPP_ASSISTANT_PHONE_NUMBER_ID' => $_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER_ID'] ?? null,
            'WHATSAPP_ASSISTANT_PHONE_NUMBER' => $_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER'] ?? null,
            'WHATSAPP_ASSISTANT_ACCESS_TOKEN' => $_ENV['WHATSAPP_ASSISTANT_ACCESS_TOKEN'] ?? null,
            'WHATSAPP_ACCESS_TOKEN' => $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? null,
            'WHATSAPP_PHONE_NUMBER_ID' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? null,
            'AUTO_ENRICH_CONTACTS' => $_ENV['AUTO_ENRICH_CONTACTS'] ?? null,
            'AI_LOCAL_ENABLED' => $_ENV['AI_LOCAL_ENABLED'] ?? null,
            'OLLAMA_URL' => $_ENV['OLLAMA_URL'] ?? null,
        ];

        $_ENV['WHATSAPP_ASSISTANT_ENABLED'] = 'true';
        $_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER_ID'] = '973354845864175';
        $_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER'] = '+254737002242';
        $_ENV['WHATSAPP_ASSISTANT_ACCESS_TOKEN'] = 'EAA_test_assistant_token';
        $_ENV['WHATSAPP_ACCESS_TOKEN'] = 'EAA_test_default_token';
        $_ENV['WHATSAPP_PHONE_NUMBER_ID'] = '973354845864175';
        $_ENV['AUTO_ENRICH_CONTACTS'] = 'false';
        $_ENV['AI_LOCAL_ENABLED'] = 'false';
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';

        (new WorkspaceAssistantConfigService())->save(1, 'whatsapp', [
            'assistant_phone_number' => '+254737002242',
            'assistant_phone_number_id' => '973354845864175',
            'access_token' => 'EAA_test_assistant_token',
            'digest_enabled' => true,
        ], true);
        (new WorkspaceConnectService())->storeManualWhatsAppIntegration(1, 0, [
            'phone_number_id' => '973354845864175',
            'display_phone_number' => '+254737002242',
            'access_token' => 'EAA_test_default_token',
            'verified_name' => 'Default WhatsApp',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }
            $_ENV[$key] = $value;
        }

        parent::tearDown();
    }

    public function testUnauthorizedAssistantSenderFallsBackToClientIngestion(): void
    {
        $webhook = new WhatsAppWebhook();
        $startedAt = microtime(true);

        $webhook->handle($this->buildPayload(
            from: '254700111222',
            messageId: 'wamid.unauthorized-client',
            type: 'text',
            messageData: ['text' => ['body' => 'Hello from a new client']],
        ));
        $elapsedSeconds = microtime(true) - $startedAt;

        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE phone = ? LIMIT 1",
            ['254700111222']
        );
        $this->assertNotNull($contact);
        $this->assertSame('whatsapp', (string) ($contact['lead_source'] ?? ''));

        $assistantLogCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM whatsapp_assistant_messages WHERE phone_number = ?",
            ['254700111222']
        )['c'] ?? 0);
        $this->assertSame(0, $assistantLogCount);

        $whatsAppMessage = Database::queryOne(
            "SELECT * FROM whatsapp_messages WHERE whatsapp_message_id = ? LIMIT 1",
            ['wamid.unauthorized-client']
        );
        $this->assertNotNull($whatsAppMessage);
        $this->assertSame((int) $contact['id'], (int) $whatsAppMessage['contact_id']);

        $communication = Database::queryOne(
            "SELECT * FROM communications WHERE contact_id = ? AND channel = 'whatsapp' LIMIT 1",
            [(int) $contact['id']]
        );
        $this->assertNotNull($communication);
        $this->assertSame('Hello from a new client', (string) ($communication['body'] ?? ''));
        $this->assertLessThan(2.5, $elapsedSeconds);

        $metadata = json_decode((string) ($communication['metadata'] ?? '{}'), true);
        $this->assertIsArray($metadata);
        $this->assertSame('neutral', (string) ($metadata['sentiment']['sentiment'] ?? ''));
        $this->assertArrayHasKey('intent', $metadata);
    }

    public function testUnauthorizedAssistantSenderWithNativeContactShareCreatesSharedContact(): void
    {
        $webhook = new WhatsAppWebhook();

        $webhook->handle($this->buildPayload(
            from: '254700121212',
            messageId: 'wamid.unauthorized-contact-share',
            type: 'contacts',
            messageData: [
                'contacts' => [[
                    'name' => [
                        'formatted_name' => 'Jane Share',
                        'first_name' => 'Jane',
                        'last_name' => 'Share',
                    ],
                    'phones' => [
                        ['phone' => '+254 722 333 444', 'type' => 'CELL'],
                    ],
                    'emails' => [
                        ['email' => 'jane.share@example.com', 'type' => 'WORK'],
                    ],
                    'org' => [
                        'company' => 'Acme Interiors',
                        'title' => 'Procurement Lead',
                    ],
                ]],
            ],
        ));

        $sender = Database::queryOne(
            "SELECT * FROM contacts WHERE phone = ? LIMIT 1",
            ['254700121212']
        );
        $this->assertNotNull($sender);

        $shared = Database::queryOne(
            "SELECT * FROM contacts WHERE phone = ? LIMIT 1",
            ['254722333444']
        );
        $this->assertNotNull($shared);
        $this->assertSame('Jane', (string) ($shared['first_name'] ?? ''));
        $this->assertSame('Share', (string) ($shared['last_name'] ?? ''));
        $this->assertSame('jane.share@example.com', (string) ($shared['email'] ?? ''));
        $this->assertSame('Acme Interiors', (string) ($shared['company'] ?? ''));
        $this->assertSame('whatsapp', (string) ($shared['lead_source'] ?? ''));

        $whatsAppMessage = Database::queryOne(
            "SELECT * FROM whatsapp_messages WHERE whatsapp_message_id = ? LIMIT 1",
            ['wamid.unauthorized-contact-share']
        );
        $this->assertNotNull($whatsAppMessage);
        $this->assertSame('contacts', (string) ($whatsAppMessage['message_type'] ?? ''));
        $this->assertStringContainsString('Shared contact: Jane Share', (string) ($whatsAppMessage['message_body'] ?? ''));
        $this->assertSame((int) $sender['id'], (int) ($whatsAppMessage['contact_id'] ?? 0));

        $communication = Database::queryOne(
            "SELECT * FROM communications WHERE contact_id = ? AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1",
            [(int) $sender['id']]
        );
        $this->assertNotNull($communication);
        $this->assertStringContainsString('Shared contact: Jane Share', (string) ($communication['body'] ?? ''));

        $metadata = json_decode((string) ($communication['metadata'] ?? '{}'), true);
        $this->assertIsArray($metadata);
        $this->assertSame('meta_contacts', (string) ($metadata['contact_share']['detection_source'] ?? ''));
        $this->assertSame((int) $shared['id'], (int) ($metadata['contact_share']['linked_contact_id'] ?? 0));

        $activity = Database::queryOne(
            "SELECT * FROM activities WHERE contact_id = ? ORDER BY id DESC LIMIT 1",
            [(int) $shared['id']]
        );
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Added from inbound WhatsApp contact share', (string) ($activity['description'] ?? ''));

        $assistantLogCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM whatsapp_assistant_messages WHERE phone_number = ?",
            ['254700121212']
        )['c'] ?? 0);
        $this->assertSame(0, $assistantLogCount);
    }

    public function testUnauthorizedAssistantSenderReusesExistingContact(): void
    {
        $existing = $this->contacts->create([
            'first_name' => 'Existing',
            'email' => 'existing.whatsapp@example.com',
            'phone' => '254700333444',
            'lead_source' => 'import',
        ]);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700333444',
            messageId: 'wamid.existing-client',
            type: 'text',
            messageData: ['text' => ['body' => 'Following up on my request']],
        ));

        $contactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE phone = ?",
            ['254700333444']
        )['c'] ?? 0);
        $this->assertSame(1, $contactCount);

        $whatsAppMessage = Database::queryOne(
            "SELECT contact_id FROM whatsapp_messages WHERE whatsapp_message_id = ? LIMIT 1",
            ['wamid.existing-client']
        );
        $this->assertNotNull($whatsAppMessage);
        $this->assertSame((int) $existing['id'], (int) $whatsAppMessage['contact_id']);
    }

    public function testIncomingMessageForAssignedContactNotifiesAssignedOwnerOnly(): void
    {
        $ownerUserId = $this->createUser('assigned-owner@example.com');
        $otherUserId = $this->createUser('other-user@example.com');
        $existing = $this->contacts->create([
            'first_name' => 'Assigned',
            'email' => 'assigned.whatsapp@example.com',
            'phone' => '254700909090',
            'lead_source' => 'import',
            'assigned_to' => $ownerUserId,
        ]);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700909090',
            messageId: 'wamid.assigned-contact-notify',
            type: 'text',
            messageData: ['text' => ['body' => 'Please help with my order']],
        ));

        $rows = Database::query(
            "SELECT user_id
             FROM notifications
             WHERE type = 'whatsapp_message_received'
               AND entity_type = 'contact'
               AND entity_id = ?
             ORDER BY user_id ASC",
            [(int) $existing['id']]
        );
        $userIds = array_map('intval', array_column($rows, 'user_id'));

        $this->assertSame([$ownerUserId], $userIds);
        $this->assertNotContains($otherUserId, $userIds);
    }

    public function testIncomingMessageForUnassignedContactNotifiesAllUsers(): void
    {
        $firstUserId = $this->createUser('all-users-one@example.com');
        $secondUserId = $this->createUser('all-users-two@example.com');
        $totalUsers = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700919191',
            messageId: 'wamid.unassigned-contact-notify',
            type: 'text',
            messageData: ['text' => ['body' => 'I need help from the shared queue']],
        ));

        $contact = Database::queryOne("SELECT id FROM contacts WHERE phone = ? LIMIT 1", ['254700919191']);
        $this->assertNotNull($contact);

        $rows = Database::query(
            "SELECT user_id
             FROM notifications
             WHERE type = 'whatsapp_message_received'
               AND entity_type = 'contact'
               AND entity_id = ?
             ORDER BY user_id ASC",
            [(int) ($contact['id'] ?? 0)]
        );
        $userIds = array_map('intval', array_column($rows, 'user_id'));

        $this->assertCount($totalUsers, $rows);
        $this->assertContains($firstUserId, $userIds);
        $this->assertContains($secondUserId, $userIds);
    }

    public function testIncomingMessageUsesResolvedContactWorkspaceForAllWrites(): void
    {
        $workspaceId = $this->ensureWorkspace(20002, 'other-workspace-webhook', 'Other Workspace');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, lead_source, created_at)
             VALUES (?, ?, 'Workspace', 'Two', 'workspace-two@example.com', '254701010101', 'import', NOW())",
            [$workspaceId, function_exists('uuid_v4') ? uuid_v4() : $this->fallbackUuid()]
        );
        $contactId = (int) Database::lastInsertId();

        $webhook = new WhatsAppWebhook();
        $payload = $this->buildPayload(
            from: '254701010101',
            messageId: 'wamid.workspace-two-message',
            type: 'text',
            messageData: ['text' => ['body' => 'Message for workspace two']],
            metadata: ['phone_number_id' => '', 'display_phone_number' => '']
        );

        $webhook->handle($payload);
        $webhook->handle($payload);

        $whatsAppMessage = Database::queryOne(
            "SELECT uuid, workspace_id, contact_id
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?",
            ['wamid.workspace-two-message']
        );
        $this->assertNotNull($whatsAppMessage);
        $this->assertSame($workspaceId, (int) ($whatsAppMessage['workspace_id'] ?? 0));
        $this->assertSame($contactId, (int) ($whatsAppMessage['contact_id'] ?? 0));

        $communication = Database::queryOne(
            "SELECT workspace_id, contact_id, body
             FROM communications
             WHERE workspace_id = ?
               AND channel = 'whatsapp'
               AND contact_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $contactId]
        );
        $this->assertNotNull($communication);
        $this->assertSame('Message for workspace two', (string) ($communication['body'] ?? ''));

        $messageCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM whatsapp_messages WHERE workspace_id = ? AND whatsapp_message_id = ?",
            [$workspaceId, 'wamid.workspace-two-message']
        )['c'] ?? 0);
        $this->assertSame(1, $messageCount);

        $communicationCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM communications
             WHERE workspace_id = ?
               AND contact_id = ?
               AND channel = 'whatsapp'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?",
            [$workspaceId, $contactId, (string) ($whatsAppMessage['uuid'] ?? '')]
        )['c'] ?? 0);
        $this->assertSame(1, $communicationCount);
    }

    public function testIncomingMessageRoutesUnknownSenderByBusinessPhoneNumberId(): void
    {
        $this->ensureWorkspace(2, 'metadata-workspace-two', 'Metadata Workspace Two');
        (new WorkspaceConnectService())->storeManualWhatsAppIntegration(2, 0, [
            'phone_number_id' => 'workspace-two-phone',
            'display_phone_number' => '+254 700 222 222',
            'access_token' => 'workspace-two-token',
            'whatsapp_business_account_id' => 'waba-two',
        ]);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254722222333',
            messageId: 'wamid.metadata-route-unknown',
            type: 'text',
            messageData: ['text' => ['body' => 'Route me by metadata']],
            metadata: [
                'display_phone_number' => '+254 700 222 222',
                'phone_number_id' => 'workspace-two-phone',
            ],
        ));

        $contact = Database::queryOne(
            "SELECT workspace_id, phone FROM contacts WHERE phone = ? LIMIT 1",
            ['254722222333']
        );
        $this->assertNotNull($contact);
        $this->assertSame(2, (int) ($contact['workspace_id'] ?? 0));

        $message = Database::queryOne(
            "SELECT workspace_id, to_number
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?
             LIMIT 1",
            ['wamid.metadata-route-unknown']
        );
        $this->assertNotNull($message);
        $this->assertSame(2, (int) ($message['workspace_id'] ?? 0));
        $this->assertSame('+254 700 222 222', (string) ($message['to_number'] ?? ''));
    }

    public function testIncomingMessageRoutesDuplicateCustomerPhoneByBusinessPhoneNumberId(): void
    {
        $this->ensureWorkspace(2, 'duplicate-phone-workspace-two', 'Duplicate Phone Workspace Two');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, phone, lead_source, created_at)
             VALUES (1, ?, 'Workspace One', 'duplicate.one@example.test', '254733333444', 'import', NOW())",
            [$this->fallbackUuid()]
        );
        $workspaceOneContactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, phone, lead_source, created_at)
             VALUES (2, ?, 'Workspace Two', 'duplicate.two@example.test', '254733333444', 'import', NOW())",
            [$this->fallbackUuid()]
        );
        $workspaceTwoContactId = (int) Database::lastInsertId();

        (new WorkspaceConnectService())->storeManualWhatsAppIntegration(2, 0, [
            'phone_number_id' => 'workspace-two-duplicate-phone',
            'display_phone_number' => '+254 700 222 222',
            'access_token' => 'workspace-two-token',
            'whatsapp_business_account_id' => 'waba-two',
        ]);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254733333444',
            messageId: 'wamid.metadata-route-duplicate',
            type: 'text',
            messageData: ['text' => ['body' => 'Route duplicate by business phone']],
            metadata: [
                'display_phone_number' => '+254 700 222 222',
                'phone_number_id' => 'workspace-two-duplicate-phone',
            ],
        ));

        $message = Database::queryOne(
            "SELECT workspace_id, contact_id
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?
             LIMIT 1",
            ['wamid.metadata-route-duplicate']
        );
        $this->assertNotNull($message);
        $this->assertSame(2, (int) ($message['workspace_id'] ?? 0));
        $this->assertSame($workspaceTwoContactId, (int) ($message['contact_id'] ?? 0));
        $this->assertNotSame($workspaceOneContactId, (int) ($message['contact_id'] ?? 0));
    }

    public function testWorkspaceWebhookTokenRejectsMismatchedBusinessPhoneNumberId(): void
    {
        $this->ensureWorkspace(2, 'mismatch-workspace-two', 'Mismatch Workspace Two');
        (new WorkspaceConnectService())->storeManualWhatsAppIntegration(2, 0, [
            'phone_number_id' => 'workspace-two-mismatch-phone',
            'display_phone_number' => '+254 700 222 222',
            'access_token' => 'workspace-two-token',
            'whatsapp_business_account_id' => 'waba-two',
        ]);

        $webhook = new WhatsAppWebhook(1);
        $webhook->handle($this->buildPayload(
            from: '254744444555',
            messageId: 'wamid.metadata-route-mismatch',
            type: 'text',
            messageData: ['text' => ['body' => 'This should not be accepted']],
            metadata: [
                'display_phone_number' => '+254 700 222 222',
                'phone_number_id' => 'workspace-two-mismatch-phone',
            ],
        ));

        $messageCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?",
            ['wamid.metadata-route-mismatch']
        )['c'] ?? 0);
        $this->assertSame(0, $messageCount);
    }

    public function testNativeContactShareReusesExistingSharedContact(): void
    {
        $existing = $this->contacts->create([
            'first_name' => 'Existing',
            'last_name' => 'Shared',
            'email' => 'shared.contact@example.com',
            'phone' => '254733444555',
            'lead_source' => 'import',
        ]);

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700454545',
            messageId: 'wamid.reuse-shared-contact',
            type: 'contacts',
            messageData: [
                'contacts' => [[
                    'name' => ['formatted_name' => 'Existing Shared'],
                    'phones' => [['phone' => '+254733444555', 'type' => 'CELL']],
                    'emails' => [['email' => 'shared.contact@example.com', 'type' => 'WORK']],
                ]],
            ],
        ));

        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE phone = ?",
            ['254733444555']
        )['c'] ?? 0);
        $this->assertSame(1, $count);

        $sender = Database::queryOne("SELECT * FROM contacts WHERE phone = ? LIMIT 1", ['254700454545']);
        $this->assertNotNull($sender);
        $communication = Database::queryOne(
            "SELECT * FROM communications WHERE contact_id = ? AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1",
            [(int) $sender['id']]
        );
        $this->assertNotNull($communication);

        $metadata = json_decode((string) ($communication['metadata'] ?? '{}'), true);
        $this->assertSame((int) $existing['id'], (int) ($metadata['contact_share']['linked_contact_id'] ?? 0));
        $this->assertFalse((bool) ($metadata['contact_share']['was_created'] ?? true));
    }

    public function testPlainTextContactShareCreatesSharedContactConservatively(): void
    {
        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700565656',
            messageId: 'wamid.plain-text-contact-share',
            type: 'text',
            messageData: [
                'text' => [
                    'body' => "Jane Share\n+254 799 111 222\njane.share@example.com\nCompany: Acme Interiors",
                ],
            ],
        ));

        $shared = Database::queryOne(
            "SELECT * FROM contacts WHERE phone = ? LIMIT 1",
            ['254799111222']
        );
        $this->assertNotNull($shared);
        $this->assertSame('Jane', (string) ($shared['first_name'] ?? ''));
        $this->assertSame('Share', (string) ($shared['last_name'] ?? ''));

        $sender = Database::queryOne("SELECT * FROM contacts WHERE phone = ? LIMIT 1", ['254700565656']);
        $this->assertNotNull($sender);

        $message = Database::queryOne(
            "SELECT * FROM whatsapp_messages WHERE whatsapp_message_id = ? LIMIT 1",
            ['wamid.plain-text-contact-share']
        );
        $this->assertNotNull($message);
        $this->assertSame('contact_share', (string) ($message['message_type'] ?? ''));

        $communication = Database::queryOne(
            "SELECT * FROM communications WHERE contact_id = ? AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1",
            [(int) $sender['id']]
        );
        $metadata = json_decode((string) ($communication['metadata'] ?? '{}'), true);
        $this->assertSame('plain_text_inferred', (string) ($metadata['contact_share']['detection_source'] ?? ''));
    }

    public function testPlainTextFalsePositiveDoesNotCreateSharedContact(): void
    {
        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254700676767',
            messageId: 'wamid.false-positive-contact-share',
            type: 'text',
            messageData: [
                'text' => [
                    'body' => 'Call me tomorrow on 254722888999 about the proposal and next steps.',
                ],
            ],
        ));

        $contactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts",
            []
        )['c'] ?? 0);
        $this->assertSame(1, $contactCount);

        $sender = Database::queryOne("SELECT * FROM contacts WHERE phone = ? LIMIT 1", ['254700676767']);
        $this->assertNotNull($sender);

        $communication = Database::queryOne(
            "SELECT * FROM communications WHERE contact_id = ? AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1",
            [(int) $sender['id']]
        );
        $this->assertNotNull($communication);
        $metadata = json_decode((string) ($communication['metadata'] ?? '{}'), true);
        $this->assertFalse(isset($metadata['contact_share']));
    }

    public function testAuthorizedAssistantSenderIsConsumedByAssistantFlow(): void
    {
        $userId = $this->createUser('assistant-owner@example.com');
        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers (workspace_id, phone_number, user_id, label, is_active, digest_enabled)
             VALUES (1, ?, ?, ?, 1, 1)",
            ['254799888777', $userId, 'Owner phone']
        );
        Database::execute(
            "UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1"
        );

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254799888777',
            messageId: 'wamid.authorized-assistant',
            type: 'audio',
            messageData: ['audio' => ['id' => 'media-audio-1']],
        ));
        $webhook->handle($this->buildPayload(
            from: '254799888777',
            messageId: 'wamid.authorized-assistant',
            type: 'audio',
            messageData: ['audio' => ['id' => 'media-audio-1']],
        ));

        $assistantMessages = Database::query(
            "SELECT direction, status, phone_number
             FROM whatsapp_assistant_messages
             WHERE phone_number = ?
             ORDER BY id ASC",
            ['254799888777']
        );
        $this->assertCount(2, $assistantMessages);
        $this->assertSame('inbound', (string) $assistantMessages[0]['direction']);
        $this->assertSame('unsupported', (string) $assistantMessages[0]['status']);
        $this->assertSame('outbound', (string) $assistantMessages[1]['direction']);

        $clientContactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE phone = ?",
            ['254799888777']
        )['c'] ?? 0);
        $this->assertSame(0, $clientContactCount);

        $communicationCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM communications WHERE channel = 'whatsapp'",
            []
        )['c'] ?? 0);
        $this->assertSame(0, $communicationCount);

        $whatsAppMessageCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM whatsapp_messages WHERE from_number = ?",
            ['254799888777']
        )['c'] ?? 0);
        $this->assertSame(0, $whatsAppMessageCount);
    }

    public function testAuthorizedAssistantContactShareIsStillConsumedByAssistantFlow(): void
    {
        $userId = $this->createUser('assistant-owner-contacts@example.com');
        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers (workspace_id, phone_number, user_id, label, is_active, digest_enabled)
             VALUES (1, ?, ?, ?, 1, 1)",
            ['254799121212', $userId, 'Owner phone']
        );
        Database::execute(
            "UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1"
        );

        $webhook = new WhatsAppWebhook();
        $webhook->handle($this->buildPayload(
            from: '254799121212',
            messageId: 'wamid.authorized-assistant-contact-share',
            type: 'contacts',
            messageData: [
                'contacts' => [[
                    'name' => ['formatted_name' => 'Blocked Client'],
                    'phones' => [['phone' => '+254733000111', 'type' => 'CELL']],
                ]],
            ],
        ));

        $assistantMessages = Database::query(
            "SELECT direction, status
             FROM whatsapp_assistant_messages
             WHERE phone_number = ?
             ORDER BY id ASC",
            ['254799121212']
        );
        $this->assertCount(2, $assistantMessages);
        $this->assertSame('inbound', (string) $assistantMessages[0]['direction']);
        $this->assertSame('unsupported', (string) $assistantMessages[0]['status']);

        $clientContactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE phone = ?",
            ['254733000111']
        )['c'] ?? 0);
        $this->assertSame(0, $clientContactCount);

        $communicationCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM communications WHERE channel = 'whatsapp'",
            []
        )['c'] ?? 0);
        $this->assertSame(0, $communicationCount);
    }

    public function testAssistantOnlyStatusUpdateIsBoundToWebhookWorkspace(): void
    {
        $otherWorkspaceId = $this->ensureWorkspace(20003, 'assistant-status-other', 'Assistant Status Other');
        foreach ([1, $otherWorkspaceId] as $workspaceId) {
            Database::execute(
                "INSERT INTO whatsapp_assistant_messages
                    (workspace_id, uuid, phone_number, direction, message_type, message_body, whatsapp_message_id, status, created_at)
                 VALUES (?, ?, ?, 'outbound', 'text', 'Assistant reply', 'wamid.assistant-status-shared', 'sent', NOW())",
                [$workspaceId, uuid_v4(), '25479910000' . $workspaceId]
            );
        }

        (new WhatsAppWebhook(1))->handle([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'entry-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '+254737002242',
                            'phone_number_id' => '973354845864175',
                        ],
                        'statuses' => [[
                            'id' => 'wamid.assistant-status-shared',
                            'status' => 'delivered',
                            'timestamp' => (string) time(),
                        ]],
                    ],
                ]],
            ]],
        ]);

        $workspaceOne = Database::queryOne(
            "SELECT status FROM whatsapp_assistant_messages WHERE workspace_id = 1 AND whatsapp_message_id = ?",
            ['wamid.assistant-status-shared']
        );
        $workspaceTwo = Database::queryOne(
            "SELECT status FROM whatsapp_assistant_messages WHERE workspace_id = ? AND whatsapp_message_id = ?",
            [$otherWorkspaceId, 'wamid.assistant-status-shared']
        );
        $this->assertSame('delivered', (string) ($workspaceOne['status'] ?? ''));
        $this->assertSame('sent', (string) ($workspaceTwo['status'] ?? ''));
    }

    private function buildPayload(
        string $from,
        string $messageId,
        string $type,
        array $messageData,
        array $metadata = []
    ): array {
        $baseMetadata = [
            'display_phone_number' => '+254737002242',
            'phone_number_id' => '973354845864175',
        ];

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'entry-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => array_merge($baseMetadata, $metadata),
                        'contacts' => [[
                            'profile' => ['name' => 'Webhook Sender'],
                            'wa_id' => $from,
                        ]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => $type,
                        ] + $messageData],
                    ],
                ]],
            ]],
        ];
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, 'admin', 'Assistant', 'User', NOW())",
            [
                function_exists('uuid_v4') ? uuid_v4() : $this->fallbackUuid(),
                $email,
                password_hash('password', PASSWORD_DEFAULT),
            ]
        );

        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (1, ?, 'owner', 'active', 1, NOW(), NULL)",
            [$userId]
        );

        return $userId;
    }

    private function ensureWorkspace(int $id, string $slug, string $name): int
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), updated_at = NOW()",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
        return $id;
    }

    private function fallbackUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
