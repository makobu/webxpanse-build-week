<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\MeetingBotConfig;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Services\AIService;
use CRM\Services\MeetingBotIdentityService;
use CRM\Services\MeetingBotService;
use CRM\Services\MeetingNoteTakerService;
use CRM\Tests\DatabaseTestCase;

class MeetingBotServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['meeting-bot@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        Authorization::assignUserRole($this->userId, (int) ($adminRole['id'] ?? 0), $this->userId);

        (new MeetingBotConfig())->save([
            'enabled' => true,
            'bot_display_name' => 'Acme Meeting Assistant',
            'join_policy' => 'manual_invite_only',
            'auto_apply_mode' => 'auto_safe',
        ], $this->userId);
    }

    public function testRegisterMeetingUsesConfiguredDisplayName(): void
    {
        $service = new MeetingBotService();

        $result = $service->registerMeeting([
            'external_meeting_id' => 'zoom-123',
            'title' => 'Quarterly review',
            'join_url' => 'https://zoom.us/j/123',
            'organizer_email' => 'owner@example.com',
        ], $this->userId);

        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['status']);
        $this->assertSame('zoom', $result['provider']);
        $this->assertSame('Acme Meeting Assistant', $result['bot_display_name']);

        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $result['run_id']]);
        $this->assertNotNull($run);
        $this->assertSame('Acme Meeting Assistant', (string) ($run['bot_display_name_used'] ?? ''));
    }

    public function testIdentityFallsBackToCompanyProfileWhenBotNameBlank(): void
    {
        (new MeetingBotConfig())->save([
            'bot_display_name' => '',
        ], $this->userId);
        (new CompanyProfile())->update(['company_name' => 'Globex']);

        $identity = new MeetingBotIdentityService(new MeetingBotConfig(), new CompanyProfile());

        $this->assertSame('Globex Meeting Assistant', $identity->resolveDisplayName());
    }

    public function testTranscriptWebhookLinksMeetingNoteRun(): void
    {
        $noteTaker = new class extends MeetingNoteTakerService {
            public array $calls = [];

            public function ingest(array $payload, ?int $actorUserId = null): array
            {
                $this->calls[] = ['payload' => $payload, 'actor_user_id' => $actorUserId];
                return [
                    'success' => true,
                    'run_id' => 777,
                    'status' => 'applied',
                    'reasons' => [],
                ];
            }
        };

        $service = new MeetingBotService(new MeetingBotConfig(), null, null, null, $noteTaker);
        $registered = $service->registerMeeting([
            'external_meeting_id' => 'zoom-456',
            'title' => 'Deal review',
            'join_url' => 'https://zoom.us/j/456',
            'organizer_email' => 'owner@example.com',
        ], $this->userId);

        $result = $service->handleZoomWebhook([
            'event' => 'transcript.completed',
            'external_meeting_id' => 'zoom-456',
            'title' => 'Deal review',
            'organizer_email' => 'owner@example.com',
            'participants' => [
                ['email' => 'prospect@example.com', 'name' => 'Prospect'],
            ],
            'transcript' => 'The buyer confirmed next steps.',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('processed', $result['status']);
        $this->assertSame('zoom', $result['provider']);
        $this->assertSame(777, $result['meeting_note_taker_run_id']);
        $this->assertCount(1, $noteTaker->calls);
        $this->assertSame((int) $registered['run_id'], (int) ($noteTaker->calls[0]['payload']['meeting_bot_run_id'] ?? 0));

        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $registered['run_id']]);
        $this->assertSame('processed', (string) ($run['status'] ?? ''));
        $this->assertSame('processed', (string) ($run['transcript_status'] ?? ''));
        $this->assertSame(777, (int) ($run['meeting_note_taker_run_id'] ?? 0));
    }

    public function testTranscriptWebhookIsIdempotentOnceNoteRunLinked(): void
    {
        $noteTaker = new class extends MeetingNoteTakerService {
            public int $calls = 0;

            public function ingest(array $payload, ?int $actorUserId = null): array
            {
                $this->calls++;
                return [
                    'success' => true,
                    'run_id' => 888,
                    'status' => 'applied',
                    'reasons' => [],
                ];
            }
        };

        $service = new MeetingBotService(new MeetingBotConfig(), null, null, null, $noteTaker);
        $registered = $service->registerMeeting([
            'external_meeting_id' => 'zoom-789',
            'title' => 'Ops sync',
            'join_url' => 'https://zoom.us/j/789',
        ], $this->userId);

        $service->handleZoomWebhook([
            'event' => 'transcript.completed',
            'external_meeting_id' => 'zoom-789',
            'title' => 'Ops sync',
            'participants' => [],
            'transcript' => 'First transcript payload.',
        ]);

        $second = $service->handleZoomWebhook([
            'event' => 'transcript.completed',
            'external_meeting_id' => 'zoom-789',
            'title' => 'Ops sync',
            'participants' => [],
            'transcript' => 'Duplicate transcript payload.',
        ]);

        $this->assertTrue($second['success']);
        $this->assertTrue(!empty($second['duplicate']));
        $this->assertSame(1, $noteTaker->calls);

        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $registered['run_id']]);
        $this->assertSame(888, (int) ($run['meeting_note_taker_run_id'] ?? 0));
    }

    public function testBotSuggestOnlyStillCreatesNoteTakerReviewRunWithoutCrmMutations(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at)
             VALUES (1, UUID(), 'Meeting', 'Prospect', 'prospect@example.com', ?, NOW())",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        (new MeetingNoteTakerConfig())->save([
            'enabled' => true,
            'auto_apply_mode' => 'full_auto',
            'task_auto_create_enabled' => true,
        ], $this->userId);
        (new MeetingBotConfig())->save([
            'auto_apply_mode' => 'suggest_only',
        ], $this->userId);
        $noteTaker = new MeetingNoteTakerService(ai: new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                return json_encode([
                    'summary' => 'Review this transcript before applying it.',
                    'relationship_context' => 'Useful buyer context.',
                    'next_step' => 'Send a follow-up.',
                    'confidence' => 0.9,
                    'contact_updates' => [],
                    'action_items' => [
                        ['text' => 'Send suggested follow-up', 'due' => date('Y-m-d', strtotime('+1 day'))],
                    ],
                    'deal_stage' => [
                        'suggested_stage' => null,
                        'confidence' => 0,
                        'reason' => '',
                    ],
                ]);
            }
        });

        $service = new MeetingBotService(new MeetingBotConfig(), null, null, null, $noteTaker);
        $registered = $service->registerMeeting([
            'external_meeting_id' => 'zoom-suggest-only',
            'title' => 'Suggest-only transcript',
            'join_url' => 'https://zoom.us/j/suggest',
        ], $this->userId);

        $result = $service->handleZoomWebhook([
            'event' => 'transcript.completed',
            'external_meeting_id' => 'zoom-suggest-only',
            'title' => 'Suggest-only transcript',
            'participants' => [
                ['email' => 'prospect@example.com', 'name' => 'Meeting Prospect'],
            ],
            'transcript' => 'Please follow up, but do not mutate CRM automatically.',
        ]);

        $botRun = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $registered['run_id']]);
        $noteRun = Database::queryOne("SELECT * FROM meeting_note_taker_runs WHERE meeting_bot_run_id = ?", [(int) $registered['run_id']]);
        $noteCount = Database::queryOne("SELECT COUNT(*) AS count FROM notes WHERE entity_id = ?", [$contactId]);
        $taskCount = Database::queryOne("SELECT COUNT(*) AS count FROM tasks WHERE contact_id = ?", [$contactId]);

        $this->assertTrue($result['success']);
        $this->assertSame('partial', $result['status']);
        $this->assertNotEmpty($result['meeting_note_taker_run_id']);
        $this->assertSame('partial', (string) ($botRun['status'] ?? ''));
        $this->assertSame('review_required', (string) ($botRun['note_taker_status'] ?? ''));
        $this->assertSame((int) ($noteRun['id'] ?? 0), (int) ($botRun['meeting_note_taker_run_id'] ?? 0));
        $this->assertSame('skipped', (string) ($noteRun['apply_status'] ?? ''));
        $this->assertStringContainsString('suggest_only_review_required', (string) ($noteRun['reasons_json'] ?? ''));
        $this->assertSame(0, (int) ($noteCount['count'] ?? 0));
        $this->assertSame(0, (int) ($taskCount['count'] ?? 0));
    }

    public function testRegisterGoogleMeetRunUsesGoogleProvider(): void
    {
        (new MeetingBotConfig())->save([
            'provider' => 'google_meet',
            'google_workspace_client_id' => 'google_client_123',
            'google_workspace_client_secret' => 'google_secret_123',
        ], $this->userId);

        $service = new MeetingBotService();
        $result = $service->registerMeeting([
            'provider' => 'google_meet',
            'conference_id' => 'meet-123',
            'calendar_event_id' => 'evt-123',
            'title' => 'Google Meet review',
            'organizer_email' => 'owner@example.com',
        ], $this->userId);

        $this->assertTrue($result['success']);
        $this->assertSame('google_meet', $result['provider']);

        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $result['run_id']]);
        $this->assertSame('google_meet', (string) ($run['provider'] ?? ''));
        $this->assertSame('meet-123', (string) ($run['external_meeting_id'] ?? ''));
        $this->assertSame('evt-123', (string) ($run['external_event_id'] ?? ''));
    }

    public function testGoogleMeetTranscriptWebhookRoutesIntoMeetingNoteTaker(): void
    {
        (new MeetingBotConfig())->save([
            'provider' => 'google_meet',
        ], $this->userId);

        $noteTaker = new class extends MeetingNoteTakerService {
            public array $calls = [];

            public function ingest(array $payload, ?int $actorUserId = null): array
            {
                $this->calls[] = ['payload' => $payload, 'actor_user_id' => $actorUserId];
                return [
                    'success' => true,
                    'run_id' => 999,
                    'status' => 'applied',
                    'reasons' => [],
                ];
            }
        };

        $service = new MeetingBotService(new MeetingBotConfig(), null, null, null, $noteTaker);
        $service->registerMeeting([
            'provider' => 'google_meet',
            'conference_id' => 'meet-789',
            'calendar_event_id' => 'evt-789',
            'title' => 'Meet sync',
        ], $this->userId);

        $result = $service->handleWebhook([
            'provider' => 'google_meet',
            'event' => 'transcript.completed',
            'conference' => ['conference_id' => 'meet-789'],
            'calendar_event_id' => 'evt-789',
            'title' => 'Meet sync',
            'organizer_email' => 'owner@example.com',
            'attendees' => [
                ['email' => 'prospect@example.com', 'name' => 'Prospect'],
            ],
            'notes' => 'Decision maker confirmed the next internal review.',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('processed', $result['status']);
        $this->assertSame('google_meet', $result['provider']);
        $this->assertCount(1, $noteTaker->calls);
        $this->assertSame('google_meet', (string) ($noteTaker->calls[0]['payload']['provider'] ?? ''));
        $this->assertSame('meeting_bot', (string) ($noteTaker->calls[0]['payload']['source_surface'] ?? ''));
    }

    public function testGoogleMeetWebhookCanMatchRegisteredRunByEventIdOnly(): void
    {
        (new MeetingBotConfig())->save([
            'provider' => 'google_meet',
        ], $this->userId);

        $service = new MeetingBotService();
        $registered = $service->registerMeeting([
            'provider' => 'google_meet',
            'calendar_event_id' => 'evt-only-123',
            'title' => 'Event-only Meet',
            'organizer_email' => 'owner@example.com',
        ], $this->userId);

        $result = $service->handleWebhook([
            'provider' => 'google_meet',
            'event' => 'meeting.scheduled',
            'calendar_event_id' => 'evt-only-123',
            'title' => 'Event-only Meet updated',
            'attendees' => [],
        ]);

        $runCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM meeting_bot_runs WHERE provider = 'google_meet' AND external_event_id = ?",
            ['evt-only-123']
        );
        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE id = ?", [(int) $registered['run_id']]);

        $this->assertTrue($result['success']);
        $this->assertSame((int) $registered['run_id'], (int) $result['run_id']);
        $this->assertSame(1, (int) ($runCount['count'] ?? 0));
        $this->assertSame('scheduled', (string) ($run['status'] ?? ''));
    }

    public function testRunsAndWebhookUpdatesStayInsideWorkspace(): void
    {
        $secondWorkspaceId = $this->createWorkspace('meeting-bot-second');
        $config = new MeetingBotConfig();
        $config->save([
            'enabled' => true,
            'bot_display_name' => 'Workspace One Bot',
        ], $this->userId, 1);
        $config->save([
            'enabled' => true,
            'bot_display_name' => 'Workspace Two Bot',
        ], $this->userId, $secondWorkspaceId);

        $workspaceOne = new MeetingBotService(workspaceId: 1);
        $workspaceTwo = new MeetingBotService(workspaceId: $secondWorkspaceId);
        $first = $workspaceOne->registerMeeting([
            'external_meeting_id' => 'shared-meeting-id',
            'title' => 'Workspace one meeting',
            'join_url' => 'https://zoom.us/j/one',
        ], $this->userId);
        $second = $workspaceTwo->registerMeeting([
            'external_meeting_id' => 'shared-meeting-id',
            'title' => 'Workspace two meeting',
            'join_url' => 'https://zoom.us/j/two',
        ], $this->userId);

        $this->assertNotNull($workspaceOne->getRunById((int) $first['run_id']));
        $this->assertNull($workspaceOne->getRunById((int) $second['run_id']));
        $this->assertNotNull($workspaceTwo->getRunById((int) $second['run_id']));
        $this->assertNull($workspaceTwo->getRunById((int) $first['run_id']));

        $workspaceTwo->handleZoomWebhook([
            'event' => 'meeting.started',
            'external_meeting_id' => 'shared-meeting-id',
            'title' => 'Workspace two meeting',
            'participants' => [],
        ]);

        $firstRun = Database::queryOne("SELECT status, workspace_id FROM meeting_bot_runs WHERE id = ?", [(int) $first['run_id']]);
        $secondRun = Database::queryOne("SELECT status, workspace_id FROM meeting_bot_runs WHERE id = ?", [(int) $second['run_id']]);

        $this->assertSame('scheduled', (string) ($firstRun['status'] ?? ''));
        $this->assertSame('joining', (string) ($secondRun['status'] ?? ''));
        $this->assertSame(1, (int) ($firstRun['workspace_id'] ?? 0));
        $this->assertSame($secondWorkspaceId, (int) ($secondRun['workspace_id'] ?? 0));
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'active', ?)",
            ['Meeting Bot Workspace', $slug, $this->userId]
        );

        return (int) Database::lastInsertId();
    }
}
