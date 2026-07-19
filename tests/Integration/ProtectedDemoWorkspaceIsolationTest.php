<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\UnifiedInbox;
use CRM\Session;
use CRM\Services\DemoChannelSimulatorService;
use CRM\Services\DemoExperienceOrchestratorService;
use CRM\Services\DemoRealtimeEventService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoSessionService;
use CRM\Services\DemoWorkspaceSeedService;
use CRM\Services\DemoWorkspaceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class ProtectedDemoWorkspaceIsolationTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testGuestDemoAccessCreatesLockedGuestAndSimulatedMessage(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.51';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Protected Demo Test';

        $state = (new DemoSessionService())->start([
            'name' => 'Demo Visitor',
            'email' => 'guest.demo@example.test',
            'whatsapp_phone' => '+254700000123',
            'consent_contact' => false,
        ]);

        $this->assertTrue((bool) ($state['active'] ?? false));
        $session = Database::queryOne(
            "SELECT * FROM demo_visitor_sessions WHERE session_uuid = ? LIMIT 1",
            [(string) ($state['session_uuid'] ?? '')]
        );
        $this->assertNotEmpty($session);
        $this->assertSame(1, (int) ($session['consent_privacy'] ?? 0));
        $this->assertSame(0, (int) ($session['consent_contact'] ?? 1));
        $sessionMetadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];
        $capture = (array) ($sessionMetadata['default_workspace_contact'] ?? []);
        $this->assertSame('skipped', (string) ($capture['status'] ?? ''));
        $this->assertSame('no_contact_consent', (string) ($capture['reason'] ?? ''));

        $guestUser = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) ($session['guest_user_id'] ?? 0)]);
        $this->assertSame(1, (int) ($guestUser['is_demo_guest'] ?? 0));
        $this->assertStringStartsWith('demo-', (string) ($guestUser['email'] ?? ''));

        $result = (new DemoChannelSimulatorService())->simulate([
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'name' => 'Demo Visitor',
            'email' => 'guest.demo@example.test',
            'phone' => '+254700000123',
            'message' => 'Can I see how a private WhatsApp lead appears in the demo?',
        ]);

        $this->assertTrue((bool) ($result['success'] ?? false));

        $communication = Database::queryOne(
            "SELECT demo_visibility, demo_session_id
             FROM communications
             WHERE id = ?
             LIMIT 1",
            [(int) ($result['communication_id'] ?? 0)]
        );
        $this->assertSame('session_private', (string) ($communication['demo_visibility'] ?? ''));
        $this->assertSame((int) $session['id'], (int) ($communication['demo_session_id'] ?? 0));

        $registered = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_session_entities
             WHERE demo_session_id = ?
               AND table_name = 'communications'
               AND record_id = ?",
            [(int) $session['id'], (int) ($result['communication_id'] ?? 0)]
        )['c'] ?? 0);
        $this->assertSame(1, $registered);

        $defaultContact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['guest.demo@example.test']
        );
        $this->assertNull($defaultContact);
    }

    public function testProtectedDemoEmailVisibilityStaysWorkspaceAndSessionScoped(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorA = $this->createUser('demo.email.a@example.test');
        $visitorB = $this->createUser('demo.email.b@example.test');
        $sessionAUuid = '11111111-1111-4111-8111-111111111111';
        $sessionBUuid = '12121212-1212-4121-8121-121212121212';
        $sessionA = $this->createDemoSession($workspaceId, $visitorA, $sessionAUuid);
        $sessionB = $this->createDemoSession($workspaceId, $visitorB, $sessionBUuid);

        $this->createDemoEmail($workspaceId, null, 'public_seed', 'Public protected demo email');
        $this->createDemoEmail($workspaceId, $sessionA, 'session_private', 'Session A protected demo email');
        $this->createDemoEmail($workspaceId, $sessionB, 'session_private', 'Session B protected demo email');

        $this->activateDemoSession($workspaceId, $visitorA, $sessionA, $sessionAUuid);
        $scope = (new DemoSessionScopeService())->visibilityClause('e', $workspaceId);

        $this->assertStringContainsString('e.demo_visibility', (string) ($scope['sql'] ?? ''));
        $this->assertStringContainsString('e.demo_session_id', (string) ($scope['sql'] ?? ''));

        $rows = Database::query(
            "SELECT e.subject
             FROM emails e
             WHERE e.workspace_id = ?
               AND " . (string) $scope['sql'] . "
             ORDER BY e.subject ASC",
            array_merge([$workspaceId], (array) ($scope['params'] ?? []))
        );
        $subjects = array_map(static fn(array $row): string => (string) ($row['subject'] ?? ''), $rows);

        $this->assertContains('Public protected demo email', $subjects);
        $this->assertContains('Session A protected demo email', $subjects);
        $this->assertNotContains('Session B protected demo email', $subjects);

        $defaultWorkspaceLeakCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM emails
             WHERE workspace_id = 1
               AND subject LIKE '%protected demo email%'"
        )['c'] ?? 0);
        $this->assertSame(0, $defaultWorkspaceLeakCount);
    }

    public function testGuestDemoAccessCreatesDefaultWorkspaceContactWhenContactConsentGiven(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.52';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Protected Demo Contact Capture';

        $state = (new DemoSessionService())->start([
            'name' => 'Follow Up Visitor',
            'email' => 'followup.demo@example.test',
            'whatsapp_phone' => '+254700000124',
            'consent_contact' => true,
        ]);

        $session = Database::queryOne(
            "SELECT metadata_json
             FROM demo_visitor_sessions
             WHERE session_uuid = ?
             LIMIT 1",
            [(string) ($state['session_uuid'] ?? '')]
        ) ?: [];
        $sessionMetadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];
        $capture = (array) ($sessionMetadata['default_workspace_contact'] ?? []);

        $this->assertSame('created', (string) ($capture['status'] ?? ''));
        $this->assertSame(1, (int) ($capture['workspace_id'] ?? 0));

        $contact = Database::queryOne(
            "SELECT id, workspace_id, first_name, last_name, email, phone, lead_source, stage, metadata_json
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['followup.demo@example.test']
        ) ?: [];
        $this->assertSame((int) ($capture['contact_id'] ?? 0), (int) ($contact['id'] ?? 0));
        $this->assertSame('Follow', (string) ($contact['first_name'] ?? ''));
        $this->assertSame('Up Visitor', (string) ($contact['last_name'] ?? ''));
        $this->assertSame('+254700000124', (string) ($contact['phone'] ?? ''));
        $this->assertSame('other', (string) ($contact['lead_source'] ?? ''));
        $this->assertSame('new', (string) ($contact['stage'] ?? ''));

        $contactMetadata = json_decode((string) ($contact['metadata_json'] ?? ''), true) ?: [];
        $this->assertSame('default_workspace_demo_visitor', (string) ($contactMetadata['source'] ?? ''));
        $this->assertSame('unqualified_demo_prospect', (string) ($contactMetadata['default_workspace_contact_scope'] ?? ''));
        $this->assertFalse((bool) ($contactMetadata['default_workspace_nurture_qualified'] ?? true));
        $this->assertTrue((bool) ($contactMetadata['email_follow_up_allowed'] ?? false));
        $this->assertSame('unqualified', (string) ($contactMetadata['prospect_state'] ?? ''));
        $this->assertSame((string) ($state['session_uuid'] ?? ''), (string) ($contactMetadata['demo_session_uuid'] ?? ''));

        $realUser = Database::queryOne(
            "SELECT id
             FROM users
             WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['followup.demo@example.test']
        );
        $this->assertNull($realUser);
    }

    public function testGuestDemoAccessSkipsDuplicateDefaultWorkspaceContact(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.53';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Protected Demo Duplicate Contact';

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, phone, lead_source, stage, created_at, updated_at)
             VALUES (1, UUID(), 'Existing', 'Lead', 'duplicate.demo@example.test', '+254700000125', 'other', 'new', NOW(), NOW())"
        );
        $existingContactId = (int) Database::lastInsertId();

        $state = (new DemoSessionService())->start([
            'name' => 'Duplicate Visitor',
            'email' => 'duplicate.demo@example.test',
            'whatsapp_phone' => '+254700000126',
            'consent_privacy' => true,
            'consent_contact' => true,
        ]);

        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))",
            ['duplicate.demo@example.test']
        )['c'] ?? 0);
        $this->assertSame(1, $count);

        $session = Database::queryOne(
            "SELECT metadata_json
             FROM demo_visitor_sessions
             WHERE session_uuid = ?
             LIMIT 1",
            [(string) ($state['session_uuid'] ?? '')]
        ) ?: [];
        $sessionMetadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];
        $capture = (array) ($sessionMetadata['default_workspace_contact'] ?? []);
        $this->assertSame('duplicate', (string) ($capture['status'] ?? ''));
        $this->assertSame($existingContactId, (int) ($capture['contact_id'] ?? 0));
        $this->assertTrue((bool) ($capture['metadata_merged'] ?? false));

        $contact = Database::queryOne(
            "SELECT stage, metadata_json
             FROM contacts
             WHERE workspace_id = 1
               AND id = ?
             LIMIT 1",
            [$existingContactId]
        ) ?: [];
        $metadata = json_decode((string) ($contact['metadata_json'] ?? ''), true) ?: [];
        $this->assertSame('new', (string) ($contact['stage'] ?? ''));
        $this->assertSame('unqualified_demo_prospect', (string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $this->assertTrue((bool) ($metadata['email_follow_up_allowed'] ?? false));
    }

    public function testGuestDemoProspectQualifiesWhenSameEmailCreatesWorkspace(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.55';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Protected Demo Signup Qualification';

        $service = new DemoSessionService();
        $state = $service->start([
            'name' => 'Future Owner',
            'email' => 'future.owner.demo@example.test',
            'whatsapp_phone' => '+254700000128',
            'consent_privacy' => true,
            'consent_contact' => true,
        ]);

        $contact = Database::queryOne(
            "SELECT id, stage, metadata_json
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['future.owner.demo@example.test']
        ) ?: [];
        $contactId = (int) ($contact['id'] ?? 0);
        $this->assertGreaterThan(0, $contactId);
        $this->assertSame('new', (string) ($contact['stage'] ?? ''));
        $metadata = json_decode((string) ($contact['metadata_json'] ?? ''), true) ?: [];
        $this->assertSame('unqualified_demo_prospect', (string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $this->assertSame((string) ($state['session_uuid'] ?? ''), (string) ($metadata['demo_session_uuid'] ?? ''));

        $service->end();

        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Future Owner Workspace',
            'first_name' => 'Future',
            'last_name' => 'Owner',
            'email' => 'future.owner.demo@example.test',
            'password' => 'P@ssword123!',
        ]);

        $qualified = Database::queryOne(
            "SELECT id, stage, company, metadata_json
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['future.owner.demo@example.test']
        ) ?: [];
        $this->assertSame($contactId, (int) ($qualified['id'] ?? 0));
        $this->assertSame('qualified', (string) ($qualified['stage'] ?? ''));
        $this->assertSame('Future Owner Workspace', (string) ($qualified['company'] ?? ''));

        $qualifiedMetadata = json_decode((string) ($qualified['metadata_json'] ?? ''), true) ?: [];
        $this->assertSame('default_workspace_owner_contact', (string) ($qualifiedMetadata['source'] ?? ''));
        $this->assertSame('qualified_workspace_lead', (string) ($qualifiedMetadata['default_workspace_contact_scope'] ?? ''));
        $this->assertTrue((bool) ($qualifiedMetadata['converted_from_demo_prospect'] ?? false));
        $this->assertNotEmpty($qualifiedMetadata['demo_prospect_qualified_at'] ?? null);
        $this->assertSame((string) ($state['session_uuid'] ?? ''), (string) ($qualifiedMetadata['demo_prospect_session_uuid'] ?? ''));
        $this->assertTrue((bool) ($qualifiedMetadata['email_follow_up_allowed'] ?? false));

        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))",
            ['future.owner.demo@example.test']
        )['c'] ?? 0));

        $mapping = Database::queryOne(
            "SELECT contact_id, conversion_deal_id, customer_state
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ?
               AND owner_user_id = ?
             LIMIT 1",
            [(int) ($provisioned['workspace_id'] ?? 0), (int) ($provisioned['user_id'] ?? 0)]
        ) ?: [];
        $this->assertSame($contactId, (int) ($mapping['contact_id'] ?? 0));
        $this->assertSame('qualified_workspace_lead', (string) ($mapping['customer_state'] ?? ''));
        $this->assertGreaterThan(0, (int) ($mapping['conversion_deal_id'] ?? 0));
    }

    public function testEndingGuestDemoDeletesTemporaryUserAndKeepsDefaultContact(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.54';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Protected Demo End Cleanup';

        $service = new DemoSessionService();
        $state = $service->start([
            'name' => 'Ending Visitor',
            'email' => 'ending.demo@example.test',
            'whatsapp_phone' => '+254700000127',
            'consent_privacy' => true,
            'consent_contact' => true,
        ]);

        $session = Database::queryOne(
            "SELECT id, guest_user_id
             FROM demo_visitor_sessions
             WHERE session_uuid = ?
             LIMIT 1",
            [(string) ($state['session_uuid'] ?? '')]
        ) ?: [];
        $guestUserId = (int) ($session['guest_user_id'] ?? 0);
        $this->assertGreaterThan(0, $guestUserId);
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$guestUserId]));

        $ended = $service->end();
        $this->assertTrue((bool) ($ended['ended'] ?? false));
        $this->assertNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$guestUserId]));

        $cleanup = Database::queryOne(
            "SELECT status, cleanup_status, cleanup_summary_json
             FROM demo_visitor_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($session['id'] ?? 0)]
        ) ?: [];
        $this->assertSame('ended', (string) ($cleanup['status'] ?? ''));
        $this->assertSame('succeeded', (string) ($cleanup['cleanup_status'] ?? ''));
        $cleanupSummary = json_decode((string) ($cleanup['cleanup_summary_json'] ?? ''), true) ?: [];
        $this->assertSame($guestUserId, (int) ($cleanupSummary['deleted_guest_user_id'] ?? 0));

        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1",
            ['ending.demo@example.test']
        );
        $this->assertNotNull($contact);
    }

    public function testExpiredGuestDemoPurgeDeletesTemporaryUserWithinSessionTtl(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $sessionUuid = '99999999-9999-4999-8999-999999999999';
        Database::execute(
            "INSERT INTO users
                (uuid, first_name, last_name, email, password_hash, role, is_demo_guest, demo_expires_at, demo_session_uuid, email_verified_at, created_at)
             VALUES (UUID(), 'Expired', 'Demo', 'demo-expired-session@example.invalid', ?, 'viewer', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR), ?, NOW(), NOW())",
            [password_hash('expired-demo', PASSWORD_DEFAULT), $sessionUuid]
        );
        $guestUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
             VALUES (?, ?, 'demo_viewer', 'active', NOW())",
            [$workspaceId, $guestUserId]
        );
        Database::execute(
            "INSERT INTO demo_visitor_sessions
                (session_uuid, workspace_id, user_id, guest_user_id, consent_privacy, access_source, status, expires_at, purge_after, last_seen_at)
             VALUES (?, ?, ?, ?, 1, 'guest', 'active', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
            [$sessionUuid, $workspaceId, $guestUserId, $guestUserId]
        );

        $result = (new DemoSessionService())->purgeExpired();

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertNull(Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$guestUserId]));
        $session = Database::queryOne(
            "SELECT status, cleanup_status
             FROM demo_visitor_sessions
             WHERE session_uuid = ?
             LIMIT 1",
            [$sessionUuid]
        ) ?: [];
        $this->assertSame('expired', (string) ($session['status'] ?? ''));
        $this->assertSame('succeeded', (string) ($session['cleanup_status'] ?? ''));
    }

    public function testProtectedDemoPublicSeedSkipsCompleteAndRepairsPartialBaseline(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $seedService = new DemoWorkspaceSeedService();
        $seedService->reseed($workspaceId, 0);

        $complete = $seedService->ensureSeeded($workspaceId, 0);
        $this->assertSame(0, (int) ($complete['created'] ?? -1));
        $this->assertTrue((bool) ($complete['skipped'] ?? false));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'contacts'));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'conversation_threads'));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'communications'));

        $visitorId = $this->createUser('demo.seed.repair@example.test');
        $sessionId = $this->createDemoSession($workspaceId, $visitorId, '44444444-4444-4444-8444-444444444444');
        Database::execute(
            "DELETE FROM communications
             WHERE workspace_id = ?
               AND demo_visibility = 'public_seed'",
            [$workspaceId]
        );
        Database::execute(
            "DELETE FROM conversation_threads
             WHERE workspace_id = ?
               AND demo_visibility = 'public_seed'",
            [$workspaceId]
        );

        $repaired = $seedService->ensureSeeded($workspaceId, 0);

        $this->assertSame(4, (int) ($repaired['created'] ?? 0));
        $this->assertFalse((bool) ($repaired['skipped'] ?? true));
        $this->assertTrue((bool) ($repaired['repaired'] ?? false));
        $this->assertGreaterThanOrEqual(4, (int) ($repaired['purged'] ?? 0));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'contacts'));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'conversation_threads'));
        $this->assertSame(4, $this->countPublicSeedRows($workspaceId, 'communications'));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_visitor_sessions
             WHERE id = ?
               AND workspace_id = ?
               AND status = 'active'",
            [$sessionId, $workspaceId]
        )['c'] ?? 0));
    }

    public function testInboxAndRealtimeAreScopedToActiveDemoSession(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorA = $this->createUser('demo.visitor.a@example.test');
        $visitorB = $this->createUser('demo.visitor.b@example.test');
        $sessionA = $this->createDemoSession($workspaceId, $visitorA, '22222222-2222-4222-8222-222222222222');
        $sessionB = $this->createDemoSession($workspaceId, $visitorB, '33333333-3333-4333-8333-333333333333');

        $this->createCommunication($workspaceId, null, 'public_seed', 'Shared public seed');
        $this->createCommunication($workspaceId, $sessionA, 'session_private', 'Visitor A private message');
        $this->createCommunication($workspaceId, $sessionB, 'session_private', 'Visitor B private message');

        (new DemoRealtimeEventService())->publish($workspaceId, $sessionA, 'message_received', 'communications', 101, [
            'subject' => 'Visitor A event',
        ]);
        (new DemoRealtimeEventService())->publish($workspaceId, $sessionB, 'message_received', 'communications', 202, [
            'subject' => 'Visitor B event',
        ]);

        $this->activateDemoSession($workspaceId, $visitorA, $sessionA, '22222222-2222-4222-8222-222222222222');

        $rows = (new UnifiedInbox())->getAll(20, 0, [
            'status' => 'all',
            'viewer_user_id' => $visitorA,
            'can_view_all_conversations' => true,
            'owner_scope' => UnifiedInbox::OWNER_SCOPE_ALL,
        ]);
        $subjects = array_map(static fn(array $row): string => (string) ($row['subject'] ?? ''), $rows);

        $this->assertContains('Shared public seed', $subjects);
        $this->assertContains('Visitor A private message', $subjects);
        $this->assertNotContains('Visitor B private message', $subjects);

        $sessionRow = Database::queryOne("SELECT * FROM demo_visitor_sessions WHERE id = ? LIMIT 1", [$sessionA]) ?: [];
        $events = (new DemoRealtimeEventService())->poll($sessionRow, 0, 20);
        $eventSubjects = array_map(static fn(array $row): string => (string) (($row['payload']['subject'] ?? '')), $events);

        $this->assertContains('Visitor A event', $eventSubjects);
        $this->assertNotContains('Visitor B event', $eventSubjects);
    }

    public function testProtectedDemoChatEndpointsUseRiversideContext(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorId = $this->createUser('demo.chat@example.test');
        $sessionUuid = '55555555-5555-4555-8555-555555555555';
        $sessionId = $this->createDemoSession($workspaceId, $visitorId, $sessionUuid);
        $webSession = $this->demoWebSession($workspaceId, $visitorId, $sessionId, $sessionUuid);

        $welcome = $this->runWebEndpoint('api/chat/welcome.php', $webSession, [
            'method' => 'GET',
            'query' => ['current_page' => 'dashboard.php'],
        ]);
        $welcomePayload = json_decode((string) ($welcome['body'] ?? ''), true) ?: [];
        $welcomeText = json_encode($welcomePayload, JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertSame(200, (int) ($welcome['status'] ?? 0), (string) ($welcome['stderr'] ?? ''));
        $this->assertTrue((bool) ($welcomePayload['metadata']['protected_demo'] ?? false));
        $this->assertStringContainsString('Riverside demo is fully configured', (string) ($welcomePayload['greeting'] ?? ''));
        $this->assertStringContainsString('Open private Inbox', (string) ($welcomePayload['opening_insight']['cta_label'] ?? ''));
        $this->assertStringNotContainsString('Define offer and pricing', $welcomeText);
        $this->assertStringNotContainsString('Open Setup', $welcomeText);

        $answer = $this->runWebEndpoint('api/chat/ask.php', $webSession, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'message' => 'Why should Riverside be handled first?',
                'current_page' => 'dashboard.php',
            ]),
        ]);
        $answerPayload = json_decode((string) ($answer['body'] ?? ''), true) ?: [];
        $answerText = (string) ($answerPayload['answer'] ?? '');

        $this->assertSame(200, (int) ($answer['status'] ?? 0), (string) ($answer['stderr'] ?? ''));
        $this->assertTrue((bool) ($answerPayload['metadata']['protected_demo'] ?? false));
        $this->assertStringContainsString('Why Riverside first', $answerText);
        $this->assertStringContainsString('high-intent revenue moment', $answerText);
        $this->assertStringNotContainsString('Sorry, something went wrong', $answerText);
        $this->assertStringNotContainsString('Define offer and pricing', $answerText);
        $this->assertStringNotContainsString('Open Setup', $answerText);
    }

    public function testCueFirstDemoExperienceIsPausedIdempotentSessionPrivateAndSilent(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorId = $this->createUser('demo.experience@example.test');
        $sessionId = $this->createDemoSession($workspaceId, $visitorId, '44444444-4444-4444-8444-444444444444');
        $this->activateDemoSession($workspaceId, $visitorId, $sessionId, '44444444-4444-4444-8444-444444444444');

        $session = Database::queryOne("SELECT * FROM demo_visitor_sessions WHERE id = ? LIMIT 1", [$sessionId]) ?: [];
        $orchestrator = new DemoExperienceOrchestratorService();

        $paused = $orchestrator->tick($session, ['paused_reason' => 'video']);
        $this->assertTrue((bool) ($paused['paused'] ?? false));
        $this->assertSame(18, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM demo_experience_events WHERE demo_session_id = ?",
            [$sessionId]
        )['c'] ?? 0));

        $seeded = $orchestrator->tick($session);
        $this->assertSame(0, (int) ($seeded['emitted_count'] ?? -1));
        $this->assertSame(18, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM demo_experience_events WHERE demo_session_id = ?",
            [$sessionId]
        )['c'] ?? 0));
        $directorRow = Database::queryOne(
            "SELECT trigger_mode, trigger_page, trigger_name, depends_on_event_key
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'assistant_draft_typing_started'
             LIMIT 1",
            [$sessionId]
        ) ?: [];
        $this->assertSame('cue', (string) ($directorRow['trigger_mode'] ?? ''));
        $this->assertSame('conversation.php', (string) ($directorRow['trigger_page'] ?? ''));
        $this->assertSame('riverside_thread_opened', (string) ($directorRow['trigger_name'] ?? ''));
        $this->assertSame('procurement_followup_received', (string) ($directorRow['depends_on_event_key'] ?? ''));

        $orchestrator->tick($session, [
            'current_page' => 'dashboard.php',
            'cue_events_json' => json_encode(['page_enter']),
            'idle_ms' => 400,
            'interaction_state' => 'active',
        ]);
        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'dashboard_scene_started'",
            [$sessionId]
        );
        $first = $orchestrator->tick($session);
        $this->assertSame(1, (int) ($first['emitted_count'] ?? 0));
        $this->assertSame('dashboard_scene_started', (string) ($first['events'][0]['event_key'] ?? ''));
        $scenePayload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'demo_scene');
        $this->assertSame('dashboard_scene_started', (string) ($scenePayload['scene_key'] ?? ''));
        $this->assertFalse((bool) ($scenePayload['toast'] ?? true));
        $this->assertSame('open_clarity', (string) ($scenePayload['auto_action'] ?? ''));

        $duplicate = $orchestrator->tick($session, [
            'current_page' => 'dashboard.php',
            'cue_events_json' => json_encode(['page_enter']),
        ]);
        $this->assertSame(0, (int) ($duplicate['emitted_count'] ?? -1));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'dashboard_scene_started'
               AND status = 'sent'",
            [$sessionId]
        )['c'] ?? 0));

        $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
            'idle_ms' => 1200,
            'interaction_state' => 'active',
            'autoplay_allowed' => '1',
        ]);
        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'inbox_message_sequence_started'",
            [$sessionId]
        );
        $sequence = $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
        ]);
        $this->assertSame(1, (int) ($sequence['emitted_count'] ?? 0));
        $this->assertSame('inbox_message_sequence_started', (string) ($sequence['events'][0]['event_key'] ?? ''));

        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'whatsapp_lead_received'",
            [$sessionId]
        );
        $message = $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
        ]);
        $this->assertSame(1, (int) ($message['emitted_count'] ?? 0));
        $this->assertSame('whatsapp_lead_received', (string) ($message['events'][0]['event_key'] ?? ''));

        $communication = Database::queryOne(
            "SELECT demo_visibility, demo_session_id, subject, body, metadata
             FROM communications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND channel = 'whatsapp'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );
        $this->assertSame('session_private', (string) ($communication['demo_visibility'] ?? ''));
        $this->assertSame($sessionId, (int) ($communication['demo_session_id'] ?? 0));
        $this->assertSame('Riverside proposal revision', (string) ($communication['subject'] ?? ''));
        $this->assertStringContainsString('matte stone finish', (string) ($communication['body'] ?? ''));
        $this->assertStringContainsString('Friday installation slot', (string) ($communication['body'] ?? ''));
        $communicationMeta = json_decode((string) ($communication['metadata'] ?? ''), true) ?: [];
        $this->assertSame('inbox_message_sequence_started', (string) ($communicationMeta['scene_key'] ?? ''));

        $events = (new DemoRealtimeEventService())->poll($session, 0, 20);
        $eventTypes = array_map(static fn(array $event): string => (string) ($event['event_type'] ?? ''), $events);
        $this->assertContains('notification_created', $eventTypes);
        $this->assertContains('communication_created', $eventTypes);
        $latestNotificationPayload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'notification_created');
        $this->assertFalse((bool) ($latestNotificationPayload['toast'] ?? true));
        $this->assertFalse((bool) ($latestNotificationPayload['sound'] ?? true));

        $orchestrator->tick($session, [
            'current_page' => 'contacts.php',
            'cue_events_json' => json_encode(['page_enter', 'contacts_page_visible']),
        ]);
        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'contacts_sequence_started'",
            [$sessionId]
        );
        $contactsResult = $orchestrator->tick($session, [
            'current_page' => 'contacts.php',
            'cue_events_json' => json_encode(['page_enter', 'contacts_page_visible']),
        ]);
        $this->assertSame('contacts_sequence_started', (string) ($contactsResult['events'][0]['event_key'] ?? ''));
        $amina = Database::queryOne(
            "SELECT id, first_name, company, metadata_json
             FROM contacts
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND first_name = 'Amina'
             LIMIT 1",
            [$workspaceId, $sessionId]
        ) ?: [];
        $this->assertSame('Riverside Residence', (string) ($amina['company'] ?? ''));
        $aminaMeta = json_decode((string) ($amina['metadata_json'] ?? ''), true) ?: [];
        $this->assertNotEmpty($aminaMeta['meeting_prep'] ?? []);
        $this->assertNotEmpty($aminaMeta['score_timeline'] ?? []);

        $installCountBefore = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_installs WHERE workspace_id = ?",
            [$workspaceId]
        )['c'] ?? 0);
        $orchestrator->tick($session, [
            'current_page' => 'workspace_skills.php',
            'cue_events_json' => json_encode(['page_enter', 'plugins_page_visible']),
        ]);
        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'marketplace_plugin_sequence_started'",
            [$sessionId]
        );
        $pluginResult = $orchestrator->tick($session, [
            'current_page' => 'workspace_skills.php',
            'cue_events_json' => json_encode(['page_enter', 'plugins_page_visible']),
        ]);
        $this->assertSame('marketplace_plugin_sequence_started', (string) ($pluginResult['events'][0]['event_key'] ?? ''));
        $installCountAfter = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_installs WHERE workspace_id = ?",
            [$workspaceId]
        )['c'] ?? 0);
        $this->assertSame($installCountBefore, $installCountAfter);
        $pluginPayload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'demo_scene');
        $this->assertSame('marketplace_plugin_sequence_started', (string) ($pluginPayload['scene_key'] ?? ''));
        $this->assertFalse((bool) (($pluginPayload['animation_payload']['mutates_workspace_installs'] ?? true)));
    }

    public function testCueAwareInboxTickEmitsRiversideMomentWithDirectorMetadata(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorId = $this->createUser('demo.cue@example.test');
        $sessionId = $this->createDemoSession($workspaceId, $visitorId, '66666666-6666-4666-8666-666666666666');
        $this->activateDemoSession($workspaceId, $visitorId, $sessionId, '66666666-6666-4666-8666-666666666666');

        $session = Database::queryOne("SELECT * FROM demo_visitor_sessions WHERE id = ? LIMIT 1", [$sessionId]) ?: [];
        $orchestrator = new DemoExperienceOrchestratorService();

        $registered = $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
            'idle_ms' => 1600,
            'interaction_state' => 'active',
            'autoplay_allowed' => '1',
        ]);
        $this->assertSame(0, (int) ($registered['emitted_count'] ?? -1));

        $cueRow = Database::queryOne(
            "SELECT cue_seen_at, cue_count, trigger_mode, trigger_page, trigger_name, director_state_json
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'whatsapp_lead_received'
             LIMIT 1",
            [$sessionId]
        ) ?: [];
        $this->assertNotEmpty($cueRow['cue_seen_at'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($cueRow['cue_count'] ?? 0));
        $this->assertSame('cue', (string) ($cueRow['trigger_mode'] ?? ''));
        $this->assertSame('inbox.php', (string) ($cueRow['trigger_page'] ?? ''));
        $this->assertSame('inbox_visible', (string) ($cueRow['trigger_name'] ?? ''));
        $directorPayload = json_decode((string) ($cueRow['director_state_json'] ?? ''), true) ?: [];
        $this->assertSame('Related email context arrives next', (string) ($directorPayload['next_cue'] ?? ''));

        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'inbox_message_sequence_started'",
            [$sessionId]
        );

        $sequence = $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
            'idle_ms' => 2600,
            'interaction_state' => 'active',
            'autoplay_allowed' => '1',
        ]);
        $this->assertSame(1, (int) ($sequence['emitted_count'] ?? 0));
        $this->assertSame('inbox_message_sequence_started', (string) ($sequence['events'][0]['event_key'] ?? ''));
        $sequencePayload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'demo_scene');
        $this->assertSame('inbox_message_sequence_started', (string) ($sequencePayload['scene_key'] ?? ''));
        $this->assertFalse((bool) ($sequencePayload['toast'] ?? true));

        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'whatsapp_lead_received'",
            [$sessionId]
        );

        $emitted = $orchestrator->tick($session, [
            'current_page' => 'inbox.php',
            'cue_events_json' => json_encode(['page_enter', 'inbox_visible']),
            'idle_ms' => 2600,
            'interaction_state' => 'active',
            'autoplay_allowed' => '1',
        ]);
        $this->assertSame(1, (int) ($emitted['emitted_count'] ?? 0));
        $this->assertSame('whatsapp_lead_received', (string) ($emitted['events'][0]['event_key'] ?? ''));
        $this->assertSame('whatsapp_lead_received', (string) ($emitted['director_state']['last_event_key'] ?? ''));
        $this->assertContains('whatsapp_lead_received', (array) ($emitted['director_state']['completed_keys'] ?? []));

        $communication = Database::queryOne(
            "SELECT id, demo_visibility, demo_session_id, subject, metadata
             FROM communications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND subject = 'Riverside proposal revision'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        ) ?: [];
        $this->assertSame('session_private', (string) ($communication['demo_visibility'] ?? ''));
        $this->assertSame($sessionId, (int) ($communication['demo_session_id'] ?? 0));
        $communicationMeta = json_decode((string) ($communication['metadata'] ?? ''), true) ?: [];
        $this->assertSame('whatsapp_lead_received', (string) ($communicationMeta['demo_event_key'] ?? ''));
        $this->assertSame('inbox_visible', (string) ($communicationMeta['cue_key'] ?? ''));

        $payload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'notification_created');
        $this->assertSame('whatsapp_lead_received', (string) ($payload['demo_event_key'] ?? ''));
        $this->assertSame('inbox_visible', (string) ($payload['cue_key'] ?? ''));
        $this->assertSame('Related email context arrives next', (string) ($payload['next_cue'] ?? ''));
        $this->assertSame('[data-demo-riverside-thread="1"]', (string) ($payload['highlight_selector'] ?? ''));
        $this->assertFalse((bool) ($payload['toast'] ?? true));

        $welcome = Database::queryOne(
            "SELECT status
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'welcome_private_sandbox'
             LIMIT 1",
            [$sessionId]
        ) ?: [];
        $this->assertSame('skipped', (string) ($welcome['status'] ?? ''));
    }

    public function testSkippedAheadCueBackfillsEarliestRiversideDependency(): void
    {
        $workspaceId = (new DemoWorkspaceService())->id();
        $visitorId = $this->createUser('demo.skipahead@example.test');
        $sessionId = $this->createDemoSession($workspaceId, $visitorId, '77777777-7777-4777-8777-777777777777');
        $this->activateDemoSession($workspaceId, $visitorId, $sessionId, '77777777-7777-4777-8777-777777777777');

        $session = Database::queryOne("SELECT * FROM demo_visitor_sessions WHERE id = ? LIMIT 1", [$sessionId]) ?: [];
        $orchestrator = new DemoExperienceOrchestratorService();
        $orchestrator->tick($session, [
            'current_page' => 'tasks.php',
            'cue_events_json' => json_encode(['page_enter', 'tasks_page_visible']),
            'idle_ms' => 1200,
            'interaction_state' => 'active',
        ]);

        Database::execute(
            "UPDATE demo_experience_events
             SET eligible_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE demo_session_id = ?
               AND event_key = 'tasks_sequence_started'",
            [$sessionId]
        );

        $result = $orchestrator->tick($session, [
            'current_page' => 'tasks.php',
            'cue_events_json' => json_encode(['page_enter', 'tasks_page_visible']),
            'idle_ms' => 2600,
            'interaction_state' => 'active',
        ]);
        $this->assertSame(1, (int) ($result['emitted_count'] ?? 0));
        $this->assertSame('inbox_message_sequence_started', (string) ($result['events'][0]['event_key'] ?? ''));

        $assistant = Database::queryOne(
            "SELECT status
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'assistant_draft_typing_started'
             LIMIT 1",
            [$sessionId]
        ) ?: [];
        $task = Database::queryOne(
            "SELECT status
             FROM demo_experience_events
             WHERE demo_session_id = ?
               AND event_key = 'tasks_sequence_started'
             LIMIT 1",
            [$sessionId]
        ) ?: [];
        $this->assertSame('pending', (string) ($assistant['status'] ?? ''));
        $this->assertSame('pending', (string) ($task['status'] ?? ''));

        $payload = $this->latestDemoRealtimePayload($workspaceId, $sessionId, 'demo_scene');
        $this->assertSame('inbox_message_sequence_started', (string) ($payload['demo_event_key'] ?? ''));
        $this->assertSame('inbox_message_sequence_started', (string) ($payload['scene_key'] ?? ''));
        $this->assertFalse((bool) ($payload['toast'] ?? true));
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, email_verified_at, created_at)
             VALUES (UUID(), ?, ?, 'viewer', NOW(), NOW())",
            [$email, password_hash('P@ssword123!', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createDemoSession(int $workspaceId, int $userId, string $uuid): int
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
             VALUES (?, ?, 'demo_viewer', 'active', NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active'",
            [$workspaceId, $userId]
        );
        Database::execute(
            "INSERT INTO demo_visitor_sessions
                (session_uuid, workspace_id, user_id, consent_privacy, access_source, status, expires_at, purge_after, last_seen_at)
             VALUES (?, ?, ?, 1, 'logged_in', 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
            [$uuid, $workspaceId, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createCommunication(int $workspaceId, ?int $sessionId, string $visibility, string $subject): void
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, channel, direction, subject, body, status, created_at)
             VALUES (?, ?, ?, UUID(), 'email', 'inbound', ?, 'Demo privacy test body', 'delivered', NOW())",
            [$workspaceId, $visibility, $sessionId, $subject]
        );
    }

    private function createDemoEmail(int $workspaceId, ?int $sessionId, string $visibility, string $subject): void
    {
        Database::execute(
            "INSERT INTO emails
                (workspace_id, demo_visibility, demo_session_id, uuid, to_email, from_email, from_name, subject, body, status, sent_at, delivered_at, created_at)
             VALUES (?, ?, ?, UUID(), 'workspace-demo@demo.local.invalid', 'visitor@example.test', 'Protected Demo', ?, 'Demo email privacy test body', 'delivered', NOW(), NOW(), NOW())",
            [$workspaceId, $visibility, $sessionId, $subject]
        );
    }

    private function countPublicSeedRows(int $workspaceId, string $table): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM `{$table}`
             WHERE workspace_id = ?
               AND demo_visibility = 'public_seed'",
            [$workspaceId]
        )['c'] ?? 0);
    }

    private function activateDemoSession(int $workspaceId, int $userId, int $sessionId, string $sessionUuid): void
    {
        Session::set('__remember_restore_attempted', true);
        Session::set('user_id', $userId);
        Session::set('demo_visitor_session_id', $sessionId);
        Session::set('demo_visitor_session_uuid', $sessionUuid);
        Session::set('demo_workspace_id', $workspaceId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'demo_viewer');
    }

    /**
     * @return array<string,mixed>
     */
    private function demoWebSession(int $workspaceId, int $userId, int $sessionId, string $sessionUuid): array
    {
        return [
            '__remember_restore_attempted' => true,
            'user_id' => $userId,
            'user_role' => 'viewer',
            'user_email' => 'demo.chat@example.test',
            'active_workspace_id' => $workspaceId,
            'current_workspace_id' => $workspaceId,
            'active_workspace_role' => 'demo_viewer',
            'demo_visitor_session_id' => $sessionId,
            'demo_visitor_session_uuid' => $sessionUuid,
            'demo_workspace_id' => $workspaceId,
            'workspace_role' => 'demo_viewer',
        ];
    }

    private function assertLatestDemoToastPayload(
        int $workspaceId,
        int $sessionId,
        string $eventKey,
        string $label,
        string $actionLabel,
        string $contextUrl
    ): void {
        $row = Database::queryOne(
            "SELECT payload_json
             FROM demo_realtime_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND event_type = 'notification_created'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
        $this->assertSame($eventKey, (string) ($payload['demo_event_key'] ?? ''));
        $this->assertSame($label, (string) ($payload['toast_label'] ?? ''));
        $this->assertSame($actionLabel, (string) ($payload['toast_action_label'] ?? ''));
        $this->assertStringContainsString($contextUrl, (string) ($payload['toast_context_url'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function latestDemoRealtimePayload(int $workspaceId, int $sessionId, string $eventType): array
    {
        $row = Database::queryOne(
            "SELECT payload_json
             FROM demo_realtime_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND event_type = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId, $eventType]
        ) ?: [];

        return json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
    }

    private function assertLatestDemoToastPayloadMessage(
        int $workspaceId,
        int $sessionId,
        string $eventKey,
        string $expectedMessage
    ): void {
        $row = Database::queryOne(
            "SELECT payload_json
             FROM demo_realtime_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND event_type = 'notification_created'
               AND payload_json LIKE ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId, '%"demo_event_key":"' . $eventKey . '"%']
        );

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
        $this->assertSame($eventKey, (string) ($payload['demo_event_key'] ?? ''));
        $this->assertSame($expectedMessage, (string) (($payload['notification']['message'] ?? '')));
        $this->assertStringNotContainsString('&#039;', (string) (($payload['notification']['message'] ?? '')));
    }
}
