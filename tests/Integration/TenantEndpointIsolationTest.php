<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\MobileTokenAuthService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;
use PragmaRX\Google2FA\Google2FA;

class TenantEndpointIsolationTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId;
    private int $workspaceOneContactId;
    private int $workspaceTwoContactId;
    private int $workspaceTwoCompanyId;
    private int $workspaceTwoDealId;
    private int $workspaceTwoTaskId;
    private int $workspaceTwoCommunicationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'viewer', NOW())",
            [uuid_v4(), 'tenant-endpoint@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);
        $memberships->addOrUpdateMembership(2, $this->userId, 'owner', true, $this->userId);

        $this->workspaceOneContactId = $this->insertContact(1, 'Current', 'Workspace', 'current-workspace@example.test');
        $this->workspaceTwoContactId = $this->insertContact(2, 'Foreign', 'Contact', 'foreign-contact@example.test');

        Database::execute(
            "INSERT INTO companies (workspace_id, name, created_at) VALUES (2, 'Foreign Company', NOW())"
        );
        $this->workspaceTwoCompanyId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, company_id, stage, created_by, created_at)
             VALUES (2, 'Foreign Search Deal', ?, ?, 'prospecting', ?, NOW())",
            [$this->workspaceTwoContactId, $this->workspaceTwoCompanyId, $this->userId]
        );
        $this->workspaceTwoDealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, status, created_by, created_at)
             VALUES (2, 'Foreign Task Marker', ?, ?, 'pending', ?, NOW())",
            [$this->workspaceTwoContactId, $this->userId, $this->userId]
        );
        $this->workspaceTwoTaskId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, status, created_at)
             VALUES (2, ?, ?, ?, 'email', 'inbound', 'Foreign Subject', 'Foreign conversation body', 'unread', NOW())",
            [uniqid('comm-', true), $this->workspaceTwoContactId, 'email:contact:' . $this->workspaceTwoContactId]
        );
        $this->workspaceTwoCommunicationId = (int) Database::lastInsertId();
        $this->markDemoPublicSeed('communications', $this->workspaceTwoCommunicationId);
        $this->upsertConversationThread(2, $this->workspaceTwoContactId, 'email:contact:' . $this->workspaceTwoContactId);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, ?, 'email', 'inbound', 'Current Subject', 'Current conversation body', 'unread', NOW())",
            [uniqid('comm-', true), $this->workspaceOneContactId, 'email:contact:' . $this->workspaceOneContactId]
        );
        $this->markDemoPublicSeed('communications', (int) Database::lastInsertId());
        $this->upsertConversationThread(1, $this->workspaceOneContactId, 'email:contact:' . $this->workspaceOneContactId);
    }

    public function testContactSummaryReturnsNotFoundForForeignContact(): void
    {
        $response = $this->runWebEndpoint('api/contact_summary.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['contact_id' => $this->workspaceTwoContactId],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertFalse((bool) ($payload['success'] ?? true));
    }

    public function testInboxListExcludesForeignWorkspaceRows(): void
    {
        $response = $this->runWebEndpoint('api/inbox.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['list' => 1, 'status' => 'all', 'limit' => 50],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $communications = (array) ($payload['communications'] ?? []);
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $communications);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertNotContains($this->workspaceTwoCommunicationId, $ids);
    }

    public function testInboxActionReturnsNotFoundForForeignConversation(): void
    {
        $response = $this->runWebEndpoint('api/inbox.php', $this->webSession(), [
            'method' => 'POST',
            'query' => ['action' => 'mark_unread'],
            'post' => ['id' => $this->workspaceTwoCommunicationId],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Conversation not found.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileSearchExcludesForeignWorkspaceRows(): void
    {
        $response = $this->runEndpointScript('api/mobile/search.php', [
            'method' => 'GET',
            'query' => ['q' => 'Foreign', 'type' => 'all'],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertSame([], array_values((array) ($data['contacts'] ?? [])));
        $this->assertSame([], array_values((array) ($data['deals'] ?? [])));
        $this->assertSame([], array_values((array) ($data['tasks'] ?? [])));
        $this->assertSame([], array_values((array) ($data['conversations'] ?? [])));
    }

    public function testMobileInboxDefaultsToCommunicationRowsWithWebAliases(): void
    {
        $seed = $this->seedMobileInboxThread('mobile-inbox-default', [
            [
                'subject' => 'Mobile Inbox Default older',
                'body' => 'Older mobile inbox default body',
                'read_at' => '2026-07-01 09:00:00',
                'created_at' => '2026-07-01 09:00:00',
                'triage_priority' => 'medium',
                'triage_status' => 'skipped',
            ],
            [
                'subject' => 'Mobile Inbox Default latest',
                'body' => 'Latest mobile inbox default body',
                'read_at' => null,
                'created_at' => '2026-07-01 10:00:00',
                'triage_priority' => 'high',
                'triage_status' => 'suggested',
                'triage_reason_codes' => ['needs_reply'],
            ],
        ]);

        $response = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['search' => 'Mobile Inbox Default', 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $communications = (array) ($data['communications'] ?? []);
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $items);
        $latest = $items[0] ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('messages', (string) ($data['mode'] ?? ''));
        $this->assertSame(1, (int) ($data['workspace_id'] ?? 0));
        $this->assertSame('workspace-1', (string) ($data['workspace_version'] ?? ''));
        $this->assertSame('mine_unassigned', (string) ($data['owner_scope'] ?? ''));
        $this->assertCount(2, $items);
        $this->assertSame($items, $communications);
        $this->assertArrayNotHasKey('threads', $data);
        $this->assertSame(2, (int) ($data['total'] ?? 0));
        $this->assertArrayHasKey('counts', $data);
        $this->assertContains($seed['communication_ids'][0], $ids);
        $this->assertContains($seed['communication_ids'][1], $ids);
        $this->assertSame($seed['communication_ids'][1], (int) ($latest['id'] ?? 0));
        $this->assertSame('high', (string) ($latest['triage_priority'] ?? ''));
        $this->assertSame('suggested', (string) ($latest['triage_status'] ?? ''));
        $this->assertSame(['needs_reply'], (array) ($latest['triage_reason_codes'] ?? []));
        $this->assertSame('high', (string) ($latest['thread']['priority'] ?? ''));
        $this->assertSame(2, (int) ($latest['thread']['message_count'] ?? 0));
        $this->assertSame('Mobile thread summary', (string) ($latest['thread']['metadata']['thread_summary'] ?? ''));
    }

    public function testMobileInboxThreadModeReturnsThreadAlias(): void
    {
        $seed = $this->seedMobileInboxThread('mobile-inbox-thread-mode', [
            [
                'subject' => 'Mobile Inbox Thread older',
                'body' => 'Older thread-mode body',
                'created_at' => '2026-07-02 09:00:00',
            ],
            [
                'subject' => 'Mobile Inbox Thread latest',
                'body' => 'Latest thread-mode body',
                'created_at' => '2026-07-02 10:00:00',
            ],
        ]);

        $response = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['mode' => 'threads', 'search' => 'Mobile Inbox Thread', 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $items = (array) ($data['items'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertSame('threads', (string) ($data['mode'] ?? ''));
        $this->assertCount(1, $items);
        $this->assertSame($items, (array) ($data['threads'] ?? []));
        $this->assertArrayNotHasKey('communications', $data);
        $this->assertSame(1, (int) ($data['total'] ?? 0));
        $this->assertSame($seed['communication_ids'][1], (int) ($items[0]['id'] ?? 0));
        $this->assertSame(2, (int) ($items[0]['thread']['message_count'] ?? 0));
    }

    public function testMobileInboxContactModeGroupsFourMessagesIntoOneConversation(): void
    {
        $contactId = $this->insertContact(1, 'Ambmakobu', 'Contact', 'ambmakobu-contact@example.test');
        Database::execute('UPDATE contacts SET assigned_to = NULL WHERE workspace_id = 1 AND id = ?', [$contactId]);
        $messages = [
            ['key' => 'ambmakobu-one', 'body' => 'HI', 'read_at' => null, 'created_at' => '2026-07-10 18:00:00'],
            ['key' => 'ambmakobu-two', 'body' => 'HI', 'read_at' => '2026-07-10 18:05:00', 'created_at' => '2026-07-10 18:05:00'],
            ['key' => 'ambmakobu-three', 'body' => 'Let me know if you have any questions about our doors or services!', 'read_at' => '2026-07-10 18:10:00', 'created_at' => '2026-07-10 18:10:00'],
            ['key' => 'ambmakobu-four', 'body' => 'What should we start on today', 'read_at' => null, 'created_at' => '2026-07-10 18:15:00'],
        ];
        $communicationIds = [];
        foreach ($messages as $message) {
            $threadKey = $message['key'] . ':' . $contactId;
            $this->upsertConversationThread(1, $contactId, $threadKey);
            $communicationIds[] = $this->insertCommunication(1, $contactId, $threadKey, [
                'channel' => 'whatsapp',
                'subject' => $message['body'],
                'body' => $message['body'],
                'read_at' => $message['read_at'],
                'created_at' => $message['created_at'],
            ]);
        }

        $response = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['mode' => 'contacts', 'contact_id' => $contactId, 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);
        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $items = (array) ($data['items'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertSame('contacts', (string) ($data['mode'] ?? ''));
        $this->assertSame(1, (int) ($data['total'] ?? 0));
        $this->assertCount(1, $items);
        $this->assertSame('contact', (string) ($items[0]['group_type'] ?? ''));
        $this->assertSame($contactId, (int) ($items[0]['group_id'] ?? 0));
        $this->assertSame(4, (int) ($items[0]['message_count'] ?? 0));
        $this->assertSame(2, (int) ($items[0]['unread_count'] ?? 0));
        $this->assertSame('What should we start on today', (string) ($items[0]['body_preview'] ?? ''));
        $this->assertSame(['whatsapp'], array_values((array) ($items[0]['channels'] ?? [])));

        $detailResponse = $this->runEndpointScript('api/mobile/conversations.php', [
            'method' => 'GET',
            'query' => ['contact_id' => $contactId, 'id' => end($communicationIds)],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);
        $detail = $this->decodeJsonResponse($detailResponse);
        $detailMessages = (array) ($detail['data']['messages'] ?? []);
        $this->assertSame(200, (int) ($detailResponse['status'] ?? 0), (string) ($detailResponse['body'] ?? ''));
        $this->assertCount(4, $detailMessages);
        $this->assertSame($communicationIds, array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $detailMessages));
        $this->assertSame(['whatsapp'], array_values(array_unique(array_column($detailMessages, 'channel'))));

        $token = $this->mobileAccessToken(1);
        $markRead = $this->runEndpointScript('api/mobile/conversations.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'id' => end($communicationIds),
                'contact_id' => $contactId,
                'action' => 'mark_read',
            ]),
        ]);
        $this->assertSame(200, (int) ($markRead['status'] ?? 0), (string) ($markRead['body'] ?? ''));

        $afterRead = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['mode' => 'contacts', 'contact_id' => $contactId, 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $afterReadPayload = $this->decodeJsonResponse($afterRead);
        $this->assertSame(0, (int) ($afterReadPayload['data']['items'][0]['unread_count'] ?? -1));

        $assign = $this->runEndpointScript('api/mobile/conversations.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'id' => end($communicationIds),
                'contact_id' => $contactId,
                'action' => 'assign_to_me',
            ]),
        ]);
        $this->assertSame(200, (int) ($assign['status'] ?? 0), (string) ($assign['body'] ?? ''));
        $assignedContact = Database::queryOne('SELECT assigned_to FROM contacts WHERE workspace_id = 1 AND id = ?', [$contactId]);
        $assignedThreads = Database::queryOne('SELECT COUNT(*) AS count FROM conversation_threads WHERE workspace_id = 1 AND contact_id = ? AND current_owner_id = ?', [$contactId, $this->userId]);
        $this->assertSame($this->userId, (int) ($assignedContact['assigned_to'] ?? 0));
        $this->assertSame(4, (int) ($assignedThreads['count'] ?? 0));

        $archive = $this->runEndpointScript('api/mobile/conversations.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'id' => end($communicationIds),
                'contact_id' => $contactId,
                'action' => 'archive',
            ]),
        ]);
        $this->assertSame(200, (int) ($archive['status'] ?? 0), (string) ($archive['body'] ?? ''));

        $afterArchive = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['mode' => 'contacts', 'contact_id' => $contactId, 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $afterArchivePayload = $this->decodeJsonResponse($afterArchive);
        $this->assertSame(0, (int) ($afterArchivePayload['data']['total'] ?? -1));
    }

    public function testMobileInboxSupportsWebAlignedFilters(): void
    {
        $contactId = $this->insertContact(1, 'Mobile', 'Filters', 'mobile-filters@example.test');
        $threadKey = 'mobile-filters:' . $contactId;
        $this->upsertConversationThread(1, $contactId, $threadKey);

        $unreadId = $this->insertCommunication(1, $contactId, $threadKey, [
            'channel' => 'email',
            'subject' => 'Mobile Filter Target',
            'body' => 'Body-only mobile token',
            'read_at' => null,
            'created_at' => '2026-07-03 09:00:00',
            'triage_priority' => 'urgent',
            'triage_status' => 'suggested',
        ]);
        $readId = $this->insertCommunication(1, $contactId, $threadKey, [
            'channel' => 'email',
            'subject' => 'Mobile Filter Read',
            'body' => 'Read body',
            'read_at' => '2026-07-03 09:30:00',
            'created_at' => '2026-07-03 09:30:00',
            'triage_priority' => 'low',
            'triage_status' => 'skipped',
        ]);

        $filtered = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => [
                'status' => 'unread',
                'channel' => 'email',
                'contact_id' => $contactId,
                'search' => 'Mobile Filter Target',
                'triage_priority' => 'urgent',
                'triage_status' => 'suggested',
                'limit' => 10,
            ],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);
        $filteredPayload = $this->decodeJsonResponse($filtered);
        $filteredItems = (array) ($filteredPayload['data']['items'] ?? []);

        $this->assertSame(200, (int) ($filtered['status'] ?? 0), (string) ($filtered['body'] ?? ''));
        $this->assertCount(1, $filteredItems);
        $this->assertSame($unreadId, (int) ($filteredItems[0]['id'] ?? 0));

        $read = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['status' => 'read', 'search' => 'Mobile Filter Read', 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);
        $readPayload = $this->decodeJsonResponse($read);
        $readIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($readPayload['data']['items'] ?? []));

        $this->assertSame(200, (int) ($read['status'] ?? 0), (string) ($read['body'] ?? ''));
        $this->assertContains($readId, $readIds);
        $this->assertNotContains($unreadId, $readIds);

        $bodySearch = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['search' => 'Body-only mobile token', 'include_body_search' => '1', 'limit' => 10],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);
        $bodyPayload = $this->decodeJsonResponse($bodySearch);
        $bodyIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($bodyPayload['data']['items'] ?? []));

        $this->assertSame(200, (int) ($bodySearch['status'] ?? 0), (string) ($bodySearch['body'] ?? ''));
        $this->assertContains($unreadId, $bodyIds);
    }

    public function testMobileInboxExcludesForeignWorkspaceRows(): void
    {
        $response = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'query' => ['status' => 'all', 'limit' => 50],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $items = (array) ($payload['data']['items'] ?? []);
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $items);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertNotContains($this->workspaceTwoCommunicationId, $ids);
    }

    public function testMobilePrimaryTabsLoadForSelectedWorkspace(): void
    {
        $accessToken = $this->mobileAccessToken(1);
        foreach ([
            'contacts' => ['endpoint' => 'api/mobile/contacts.php', 'method' => 'GET'],
            'inbox' => ['endpoint' => 'api/mobile/inbox.php', 'method' => 'GET'],
            'ai_feed' => ['endpoint' => 'api/mobile/ai/feed.php', 'method' => 'GET'],
            'push_register' => [
                'endpoint' => 'api/mobile/push/register.php',
                'method' => 'POST',
                'raw_body' => json_encode([
                    'push_token' => null,
                    'push_provider' => null,
                    'app_version' => 'test',
                    'device_locale' => 'en-US',
                    'preferences' => [
                        'conversation_alerts' => true,
                        'task_reminders' => true,
                        'general_activity' => true,
                    ],
                ]),
            ],
        ] as $label => $config) {
            $response = $this->runEndpointScript((string) $config['endpoint'], [
                'method' => (string) $config['method'],
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'raw_body' => (string) ($config['raw_body'] ?? ''),
            ]);

            $payload = $this->decodeJsonResponse($response);
            $this->assertSame(200, (int) ($response['status'] ?? 0), $label . ': ' . (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
            $this->assertTrue((bool) ($payload['success'] ?? false), $label);
            if ($label === 'inbox') {
                $this->assertSame(1, (int) ($payload['data']['workspace_id'] ?? 0));
                $this->assertSame('workspace-1', (string) ($payload['data']['workspace_version'] ?? ''));
            }
        }
    }

    public function testMobilePrimaryTabsStillLoadWhenBillingReadinessDrifts(): void
    {
        $this->dropForeignKeysForColumn('billing_transactions', 'subscription_id');
        Database::execute("ALTER TABLE billing_transactions DROP COLUMN subscription_id");

        $response = $this->runEndpointScript('api/mobile/contacts.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertIsArray($payload['data']['items'] ?? null);
    }

    public function testLegacyMobileTokenWithoutWorkspaceIsBackfilled(): void
    {
        $accessToken = $this->mobileAccessToken(1);
        Database::execute(
            "UPDATE mobile_auth_tokens SET workspace_id = NULL WHERE user_id = ?",
            [$this->userId]
        );

        $response = $this->runEndpointScript('api/mobile/contacts.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $tokenRow = Database::queryOne(
            "SELECT workspace_id FROM mobile_auth_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$this->userId]
        ) ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame(1, (int) ($tokenRow['workspace_id'] ?? 0));
    }

    public function testMobileLoginHonorsWorkspaceSlug(): void
    {
        $response = $this->runEndpointScript('api/mobile/auth/login.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'email' => 'tenant-endpoint@example.com',
                'password' => 'secret',
                'workspace_slug' => 'workspace-two',
                'device_id' => 'test-device',
            ]),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $user = (array) ($payload['data']['session']['user'] ?? []);
        $activeWorkspace = (array) ($user['active_workspace'] ?? []);
        $this->assertSame(2, (int) ($activeWorkspace['id'] ?? 0));
        $this->assertSame('workspace-two', (string) ($activeWorkspace['slug'] ?? ''));
    }

    public function testMobileLoginEndpointReturnsTwoFactorChallengeWithoutSession(): void
    {
        $secret = $this->enableMobileUserTwoFactor();

        $response = $this->runEndpointScript('api/mobile/auth/login.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'email' => 'tenant-endpoint@example.com',
                'password' => 'secret',
                'workspace_id' => 1,
                'device_id' => 'endpoint-2fa-login',
            ]),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('two_factor_required', (string) ($data['auth_state'] ?? ''));
        $this->assertTrue((bool) ($data['requires_2fa'] ?? false));
        $this->assertFalse((bool) ($data['requires_2fa_setup'] ?? true));
        $this->assertSame('login_2fa', (string) ($data['challenge_purpose'] ?? ''));
        $this->assertNotEmpty($data['challenge_token'] ?? '');
        $this->assertArrayNotHasKey('session', $data);
        $this->assertTrue((bool) ($data['security']['two_factor_enabled'] ?? false));
        $this->assertSame($secret, (string) (Database::queryOne("SELECT two_factor_secret FROM users WHERE id = ?", [$this->userId])['two_factor_secret'] ?? ''));
    }

    public function testMobileTwoFactorVerifyEndpointIssuesSessionAndConsumesLoginChallenge(): void
    {
        $secret = $this->enableMobileUserTwoFactor();
        $login = $this->runEndpointScript('api/mobile/auth/login.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'email' => 'tenant-endpoint@example.com',
                'password' => 'secret',
                'workspace_id' => 2,
                'device_id' => 'endpoint-2fa-device',
            ]),
        ]);
        $loginPayload = $this->decodeJsonResponse($login);
        $challengeToken = (string) ($loginPayload['data']['challenge_token'] ?? '');
        $this->assertSame(200, (int) ($login['status'] ?? 0), (string) ($login['body'] ?? ''));
        $this->assertSame('two_factor_required', (string) ($loginPayload['data']['auth_state'] ?? ''));
        $this->assertNotSame('', $challengeToken);

        $response = $this->runEndpointScript('api/mobile/auth/2fa/verify.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'challenge_token' => $challengeToken,
                'code' => $this->currentMobileOtp($secret),
            ]),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $challenge = Database::queryOne(
            "SELECT consumed_at FROM mobile_auth_challenges WHERE challenge_hash = ? LIMIT 1",
            [hash('sha256', $challengeToken)]
        ) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('authenticated', (string) ($data['auth_state'] ?? ''));
        $this->assertNotEmpty($data['access_token'] ?? '');
        $this->assertSame('endpoint-2fa-device', (string) ($data['device_id'] ?? ''));
        $this->assertSame(2, (int) ($data['user']['active_workspace']['id'] ?? 0));
        $this->assertNotEmpty($challenge['consumed_at'] ?? null);
    }

    public function testMobileTwoFactorSetupEndpointsEnableRequiredWorkspaceAccess(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA(1, true, $this->userId);
        $login = $this->runEndpointScript('api/mobile/auth/login.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'email' => 'tenant-endpoint@example.com',
                'password' => 'secret',
                'workspace_id' => 1,
                'device_id' => 'endpoint-setup-device',
            ]),
        ]);

        $loginPayload = $this->decodeJsonResponse($login);
        $challengeToken = (string) ($loginPayload['data']['challenge_token'] ?? '');
        $this->assertSame('two_factor_setup_required', (string) ($loginPayload['data']['auth_state'] ?? ''));
        $this->assertSame('setup_2fa', (string) ($loginPayload['data']['challenge_purpose'] ?? ''));

        $start = $this->runEndpointScript('api/mobile/auth/2fa/setup/start.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['challenge_token' => $challengeToken]),
        ]);
        $startPayload = $this->decodeJsonResponse($start);
        $secret = (string) ($startPayload['data']['secret'] ?? '');

        $this->assertSame(200, (int) ($start['status'] ?? 0), (string) ($start['body'] ?? ''));
        $this->assertSame('two_factor_setup_pending', (string) ($startPayload['data']['auth_state'] ?? ''));
        $this->assertNotSame('', $secret);

        $verify = $this->runEndpointScript('api/mobile/auth/2fa/setup/verify.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'challenge_token' => $challengeToken,
                'secret' => $secret,
                'code' => $this->currentMobileOtp($secret),
            ]),
        ]);

        $verifyPayload = $this->decodeJsonResponse($verify);
        $data = (array) ($verifyPayload['data'] ?? []);
        $user = Database::queryOne("SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?", [$this->userId]) ?: [];

        $this->assertSame(200, (int) ($verify['status'] ?? 0), (string) ($verify['body'] ?? ''));
        $this->assertTrue((bool) ($verifyPayload['success'] ?? false));
        $this->assertSame('authenticated', (string) ($data['auth_state'] ?? ''));
        $this->assertNotEmpty($data['access_token'] ?? '');
        $this->assertCount(10, (array) ($data['recovery_codes'] ?? []));
        $this->assertSame('endpoint-setup-device', (string) ($data['device_id'] ?? ''));
        $this->assertSame(1, (int) ($user['two_factor_enabled'] ?? 0));
        $this->assertSame($secret, (string) ($user['two_factor_secret'] ?? ''));
    }

    public function testMobileSwitchWorkspaceRejectsInaccessibleWorkspace(): void
    {
        $this->ensureWorkspace(3, 'workspace-three', 'Workspace Three');

        $response = $this->runEndpointScript('api/mobile/auth/switch_workspace.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken(1),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => json_encode(['workspace_id' => 3]),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(403, (int) ($response['status'] ?? 0));
        $this->assertSame('You do not have access to that workspace.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileSwitchWorkspaceIssuesTokenScopedToSelectedWorkspace(): void
    {
        $switchResponse = $this->runEndpointScript('api/mobile/auth/switch_workspace.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken(1),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => json_encode(['workspace_slug' => 'workspace-two']),
        ]);

        $switchPayload = $this->decodeJsonResponse($switchResponse);
        $this->assertSame(200, (int) ($switchResponse['status'] ?? 0), (string) ($switchResponse['body'] ?? ''));
        $accessToken = (string) ($switchPayload['data']['access_token'] ?? '');
        $this->assertNotSame('', $accessToken);
        $this->assertTrue((bool) ($switchPayload['data']['workspace_switched'] ?? false));
        $this->assertSame(2, (int) ($switchPayload['data']['workspace_id'] ?? 0));
        $this->assertSame('workspace-2', (string) ($switchPayload['data']['workspace_version'] ?? ''));
        $this->assertContains('inbox', (array) ($switchPayload['data']['invalidate_scopes'] ?? []));

        $searchResponse = $this->runEndpointScript('api/mobile/search.php', [
            'method' => 'GET',
            'query' => ['q' => 'Foreign', 'type' => 'all'],
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        $payload = $this->decodeJsonResponse($searchResponse);
        $data = (array) ($payload['data'] ?? []);
        $companyIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($data['companies'] ?? []));
        $taskIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($data['tasks'] ?? []));
        $this->assertSame(200, (int) ($searchResponse['status'] ?? 0));
        $this->assertContains($this->workspaceTwoCompanyId, $companyIds, (string) ($searchResponse['body'] ?? ''));
        $this->assertContains($this->workspaceTwoTaskId, $taskIds, (string) ($searchResponse['body'] ?? ''));

        $inboxResponse = $this->runEndpointScript('api/mobile/inbox.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        $inboxPayload = $this->decodeJsonResponse($inboxResponse);
        $inboxItems = (array) ($inboxPayload['data']['items'] ?? []);
        $inboxIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $inboxItems);

        $this->assertSame(200, (int) ($inboxResponse['status'] ?? 0));
        $this->assertSame(2, (int) ($inboxPayload['data']['workspace_id'] ?? 0));
        $this->assertSame('workspace-2', (string) ($inboxPayload['data']['workspace_version'] ?? ''));
        $this->assertContains($this->workspaceTwoCommunicationId, $inboxIds, (string) ($inboxResponse['body'] ?? ''));
    }

    public function testMobileCompanyDetailReturnsNotFoundForForeignCompany(): void
    {
        $response = $this->runEndpointScript('api/mobile/companies.php', [
            'method' => 'GET',
            'query' => ['id' => $this->workspaceTwoCompanyId],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Company not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileContactSummaryReturnsNotFoundForForeignContact(): void
    {
        $response = $this->runEndpointScript('api/mobile/contacts/summary.php', [
            'method' => 'GET',
            'query' => ['contact_id' => $this->workspaceTwoContactId],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Contact not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileAiThreadReturnsNotFoundForForeignConversation(): void
    {
        $response = $this->runEndpointScript('api/mobile/ai/inbox/thread.php', [
            'method' => 'GET',
            'query' => ['conversation_id' => $this->workspaceTwoCommunicationId],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Conversation not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileConversationDetailReturnsNotFoundForForeignConversation(): void
    {
        $response = $this->runEndpointScript('api/mobile/conversations.php', [
            'method' => 'GET',
            'query' => ['id' => $this->workspaceTwoCommunicationId],
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken(1)],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Conversation not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileConversationReplyReturnsNotFoundForForeignConversation(): void
    {
        $body = json_encode([
            'conversation_id' => $this->workspaceTwoCommunicationId,
            'body' => 'Reply should not send.',
        ]);

        $response = $this->runEndpointScript('api/mobile/conversations/reply.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken(1),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => $body,
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Conversation not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    public function testMobileComposeReturnsNotFoundForForeignContact(): void
    {
        $body = json_encode([
            'contact_id' => $this->workspaceTwoContactId,
            'channel' => 'sms',
            'body' => 'Hidden cross-workspace message',
        ]);

        $response = $this->runEndpointScript('api/mobile/compose.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken(1),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => $body,
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0));
        $this->assertSame('Contact not found or not accessible.', (string) ($payload['error'] ?? ''));
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            Database::execute(
                "UPDATE workspaces
                 SET slug = ?, name = ?, status = 'active', plan_status = 'trialing', updated_at = NOW()
                 WHERE id = ?",
                [$slug, $name, $id]
            );
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        $config = $this->currentTestDatabaseConfig();
        $rows = Database::query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$config['name'], $table, $column]
        );

        foreach ($rows as $row) {
            $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
            if ($constraintName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $constraintName)) {
                continue;
            }

            Database::execute("ALTER TABLE {$table} DROP FOREIGN KEY {$constraintName}");
        }
    }

    private function insertContact(int $workspaceId, string $firstName, string $lastName, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$workspaceId, uniqid('contact-', true), $firstName, $lastName, $email, $this->userId]
        );

        $contactId = (int) Database::lastInsertId();
        $this->markDemoPublicSeed('contacts', $contactId);

        return $contactId;
    }

    private function upsertConversationThread(int $workspaceId, int $contactId, string $threadKey): void
    {
        Database::execute(
            "INSERT INTO conversation_threads (workspace_id, contact_id, channel, thread_key, status, last_message_at, message_count, last_inbound_at, last_channel)
             VALUES (?, ?, 'email', ?, 'open', NOW(), 1, NOW(), 'email')
             ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id), status = VALUES(status), last_message_at = VALUES(last_message_at)",
            [$workspaceId, $contactId, $threadKey]
        );

        if (Database::columnExists('conversation_threads', 'demo_visibility')) {
            Database::execute(
                "UPDATE conversation_threads SET demo_visibility = 'public_seed' WHERE workspace_id = ? AND thread_key = ?",
                [$workspaceId, $threadKey]
            );
        }
    }

    private function markDemoPublicSeed(string $table, int $id): void
    {
        if ($id <= 0 || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !Database::columnExists($table, 'demo_visibility')) {
            return;
        }

        Database::execute("UPDATE {$table} SET demo_visibility = 'public_seed' WHERE id = ?", [$id]);
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{contact_id:int,thread_key:string,communication_ids:array<int,int>}
     */
    private function seedMobileInboxThread(string $slug, array $messages): array
    {
        $contactId = $this->insertContact(1, 'Mobile', 'Inbox', $slug . '@example.test');
        $threadKey = $slug . ':' . $contactId;
        $this->upsertConversationThread(1, $contactId, $threadKey);

        $communicationIds = [];
        foreach ($messages as $message) {
            $communicationIds[] = $this->insertCommunication(1, $contactId, $threadKey, $message);
        }

        Database::execute(
            "UPDATE conversation_threads
             SET message_count = ?,
                 current_owner_id = ?,
                 priority = 'high',
                 response_due_at = '2026-07-04 12:00:00',
                 unresolved_item_count = ?,
                 escalation_status = 'pending',
                 metadata_json = ?
             WHERE workspace_id = ? AND thread_key = ?",
            [
                count($communicationIds),
                $this->userId,
                count($communicationIds),
                json_encode(['thread_summary' => 'Mobile thread summary']),
                1,
                $threadKey,
            ]
        );

        return [
            'contact_id' => $contactId,
            'thread_key' => $threadKey,
            'communication_ids' => $communicationIds,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertCommunication(int $workspaceId, int $contactId, string $threadKey, array $overrides = []): int
    {
        $reasonCodes = $overrides['triage_reason_codes'] ?? null;
        if (is_array($reasonCodes)) {
            $reasonCodes = json_encode(array_values($reasonCodes));
        }

        Database::execute(
            "INSERT INTO communications (
                workspace_id,
                uuid,
                contact_id,
                thread_key,
                channel,
                direction,
                subject,
                body,
                status,
                from_email,
                read_at,
                triage_priority,
                triage_status,
                triage_reason_codes,
                created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                uniqid('mobile-inbox-comm-', true),
                $contactId,
                $threadKey,
                (string) ($overrides['channel'] ?? 'email'),
                (string) ($overrides['direction'] ?? 'inbound'),
                (string) ($overrides['subject'] ?? 'Mobile inbox message'),
                (string) ($overrides['body'] ?? 'Mobile inbox body'),
                (string) ($overrides['from_email'] ?? 'mobile-inbox@example.test'),
                $overrides['read_at'] ?? null,
                $overrides['triage_priority'] ?? null,
                $overrides['triage_status'] ?? null,
                $reasonCodes,
                (string) ($overrides['created_at'] ?? '2026-07-01 09:00:00'),
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'tenant-endpoint-user',
            'user_email' => 'tenant-endpoint@example.com',
            'user_role' => 'viewer',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            '__remember_restore_attempted' => true,
        ];
    }

    private function mobileAccessToken(int $workspaceId): string
    {
        $service = new MobileTokenAuthService();
        $session = $service->issueTokenPair([
            'id' => $this->userId,
            'uuid' => 'tenant-endpoint-user',
            'email' => 'tenant-endpoint@example.com',
            'role' => 'viewer',
        ], [
            'workspace_id' => $workspaceId,
            'workspace_slug' => $workspaceId === 1 ? 'default' : 'workspace-two',
        ]);

        return (string) ($session['access_token'] ?? '');
    }

    private function enableMobileUserTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): string
    {
        Database::execute(
            "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?",
            [$secret, $this->userId]
        );

        return $secret;
    }

    private function currentMobileOtp(string $secret): string
    {
        return (string) (new Google2FA())->getCurrentOtp($secret);
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));
        return $decoded;
    }
}
