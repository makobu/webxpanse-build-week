<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Services\AIService;
use CRM\Services\MeetingNotePayloadNormalizer;
use CRM\Services\MeetingNoteTakerService;
use CRM\Tests\DatabaseTestCase;

class MeetingNoteTakerServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['meeting-note@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        Authorization::assignUserRole($this->userId, (int) ($salesRole['id'] ?? 0), $this->userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'sales', 'active', 0, NOW())",
            [$this->userId]
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at) VALUES (1, UUID(), ?, ?, ?, ?, NOW())",
            ['Meeting', 'Contact', 'prospect@example.com', $this->userId]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', ?, 'USD', NOW())",
            ['Meeting Deal', $this->contactId, $this->userId, $this->userId, 0]
        );
        $this->dealId = (int) Database::lastInsertId();

        (new MeetingNoteTakerConfig())->save([
            'enabled' => true,
            'auto_apply_mode' => 'full_auto',
            'deal_stage_min_confidence' => 0.80,
            'contact_update_min_confidence' => 0.70,
        ], $this->userId);
    }

    public function testIngestCreatesNotesTasksAndDealMove(): void
    {
        $service = new MeetingNoteTakerService(
            new MeetingNotePayloadNormalizer(),
            new MeetingNoteTakerConfig(),
            new class extends AIService {
                public function process(string $task, array $data, array $context = []): string
                {
                    if ($task === 'meeting_note_analysis') {
                        return json_encode([
                            'summary' => 'Customer confirmed the proposal and asked to move forward.',
                            'relationship_context' => 'Strong buying intent and clear timeline.',
                            'next_step' => 'Send implementation kickoff details.',
                            'confidence' => 0.94,
                            'contact_updates' => [
                                'job_title' => [
                                    'value' => 'Operations Manager',
                                    'confidence' => 0.88,
                                    'reason' => 'Stated directly in the meeting',
                                ],
                            ],
                            'action_items' => [
                                ['text' => 'Send kickoff email', 'due' => date('Y-m-d', strtotime('+1 day')), 'assignee_hint' => 'owner'],
                            ],
                            'deal_stage' => [
                                'suggested_stage' => 'negotiation',
                                'confidence' => 0.91,
                                'reason' => 'Buyer confirmed commercial fit and next step.',
                            ],
                        ]);
                    }

                    return '';
                }
            }
        );

        $result = $service->ingest([
            'provider' => 'generic',
            'title' => 'Client Follow-up',
            'transcript' => 'Prospect confirmed they are ready to proceed. Their role is Operations Manager.',
            'attendees' => [
                ['email' => 'prospect@example.com', 'name' => 'Meeting Contact'],
            ],
        ], $this->userId);

        $this->assertTrue($result['success']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->contactId, $result['matched_contact_id']);
        $this->assertSame($this->dealId, $result['matched_deal_id']);

        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$this->contactId]);
        $this->assertSame('Operations Manager', (string) ($contact['job_title'] ?? ''));

        $task = Database::queryOne("SELECT * FROM tasks WHERE contact_id = ? ORDER BY id DESC LIMIT 1", [$this->contactId]);
        $this->assertNotNull($task);
        $this->assertSame('Send kickoff email', (string) ($task['title'] ?? ''));

        $deal = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$this->dealId]);
        $this->assertSame('negotiation', (string) ($deal['stage'] ?? ''));

        $run = Database::queryOne("SELECT * FROM meeting_note_taker_runs WHERE id = ?", [(int) $result['run_id']]);
        $this->assertNotNull($run);
        $this->assertSame('applied', (string) ($run['apply_status'] ?? ''));
    }

    public function testIngestBlocksWhenNoContactCanBeResolved(): void
    {
        $service = new MeetingNoteTakerService();

        $result = $service->ingest([
            'provider' => 'generic',
            'summary' => 'A useful meeting happened.',
            'attendees' => [
                ['email' => 'unknown@example.com'],
            ],
        ], $this->userId);

        $this->assertTrue($result['success']);
        $this->assertSame('blocked', $result['status']);
        $this->assertNull($result['matched_contact_id']);
        $this->assertContains('contact_not_resolved', $result['reasons']);
    }

    public function testDisabledNoteTakerDoesNotCreateRunOrCallAi(): void
    {
        (new MeetingNoteTakerConfig())->save([
            'enabled' => false,
        ], $this->userId);
        $ai = new class extends AIService {
            public int $calls = 0;

            public function process(string $task, array $data, array $context = []): string
            {
                $this->calls++;
                return '';
            }
        };
        $service = new MeetingNoteTakerService(ai: $ai);

        $result = $service->ingest([
            'provider' => 'generic',
            'external_meeting_id' => 'disabled-note-taker',
            'transcript' => 'This transcript must not be stored or processed.',
            'attendees' => [
                ['email' => 'prospect@example.com'],
            ],
        ], $this->userId);

        $runCount = Database::queryOne("SELECT COUNT(*) AS count FROM meeting_note_taker_runs");

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertNull($result['run_id']);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('meeting_note_taker_disabled', $result['reasons']);
        $this->assertSame(0, $ai->calls);
        $this->assertSame(0, (int) ($runCount['count'] ?? 0));
    }

    public function testEntityMatchingOnlyUsesActiveWorkspace(): void
    {
        $secondWorkspaceId = $this->createWorkspace('meeting-notes-second');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at)
             VALUES (?, UUID(), 'Other', 'Workspace', 'other-workspace@example.com', ?, NOW())",
            [$secondWorkspaceId, $this->userId]
        );
        $secondContactId = (int) Database::lastInsertId();
        (new MeetingNoteTakerConfig())->save([
            'enabled' => true,
            'auto_apply_mode' => 'suggest_only',
        ], $this->userId, $secondWorkspaceId);
        $ai = new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                return json_encode([
                    'summary' => 'Workspace-specific meeting captured.',
                    'confidence' => 0.82,
                    'contact_updates' => [],
                    'action_items' => [],
                    'deal_stage' => [],
                ]);
            }
        };

        $firstWorkspaceResult = (new MeetingNoteTakerService(ai: $ai, workspaceId: 1))->ingest([
            'provider' => 'generic',
            'summary' => 'This should not match across workspaces.',
            'attendees' => [
                ['email' => 'other-workspace@example.com'],
            ],
        ], $this->userId);

        $this->assertSame('blocked', $firstWorkspaceResult['status']);
        $this->assertNull($firstWorkspaceResult['matched_contact_id']);

        $secondWorkspaceResult = (new MeetingNoteTakerService(ai: $ai, workspaceId: $secondWorkspaceId))->ingest([
            'provider' => 'generic',
            'summary' => 'This should match only in workspace two.',
            'attendees' => [
                ['email' => 'other-workspace@example.com'],
            ],
        ], $this->userId);

        $this->assertSame($secondContactId, $secondWorkspaceResult['matched_contact_id']);
        $run = Database::queryOne("SELECT workspace_id FROM meeting_note_taker_runs WHERE id = ?", [(int) $secondWorkspaceResult['run_id']]);
        $this->assertSame($secondWorkspaceId, (int) ($run['workspace_id'] ?? 0));
    }

    public function testSuggestOnlyCreatesRunAndSuggestionsWithoutCrmMutations(): void
    {
        (new MeetingNoteTakerConfig())->save([
            'enabled' => true,
            'auto_apply_mode' => 'suggest_only',
            'task_auto_create_enabled' => true,
            'deal_stage_auto_move_enabled' => true,
            'contact_update_min_confidence' => 0.70,
            'deal_stage_min_confidence' => 0.80,
        ], $this->userId);

        $service = new MeetingNoteTakerService(
            ai: new class extends AIService {
                public function process(string $task, array $data, array $context = []): string
                {
                    return json_encode([
                        'summary' => 'Review this meeting before applying it.',
                        'relationship_context' => 'Important context.',
                        'next_step' => 'Prepare a follow-up.',
                        'confidence' => 0.91,
                        'contact_updates' => [
                            'job_title' => [
                                'value' => 'Chief Operator',
                                'confidence' => 0.95,
                                'reason' => 'Stated directly',
                            ],
                        ],
                        'action_items' => [
                            ['text' => 'Send review packet', 'due' => date('Y-m-d', strtotime('+1 day'))],
                        ],
                        'deal_stage' => [
                            'suggested_stage' => 'negotiation',
                            'confidence' => 0.95,
                            'reason' => 'Ready for review.',
                        ],
                    ]);
                }
            }
        );

        $result = $service->ingest([
            'provider' => 'generic',
            'external_meeting_id' => 'suggest-only-1',
            'title' => 'Suggest Only Meeting',
            'transcript' => 'Please prepare a packet. My role is Chief Operator.',
            'attendees' => [
                ['email' => 'prospect@example.com', 'name' => 'Meeting Contact'],
            ],
        ], $this->userId);

        $this->assertTrue($result['success']);
        $this->assertSame('review_required', $result['status']);
        $this->assertTrue((bool) ($result['review_required'] ?? false));
        $this->assertSame([], $result['applied_changes']);
        $this->assertContains('suggest_only_review_required', $result['reasons']);

        $noteCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM notes WHERE entity_id IN (?, ?)",
            [$this->contactId, $this->dealId]
        );
        $taskCount = Database::queryOne("SELECT COUNT(*) AS count FROM tasks WHERE contact_id = ?", [$this->contactId]);
        $contact = Database::queryOne("SELECT job_title, metadata_json FROM contacts WHERE id = ?", [$this->contactId]);
        $deal = Database::queryOne("SELECT stage FROM deals WHERE id = ?", [$this->dealId]);
        $run = Database::queryOne("SELECT apply_status, reasons_json FROM meeting_note_taker_runs WHERE id = ?", [(int) $result['run_id']]);

        $this->assertSame(0, (int) ($noteCount['count'] ?? 0));
        $this->assertSame(0, (int) ($taskCount['count'] ?? 0));
        $this->assertSame('', (string) ($contact['job_title'] ?? ''));
        $this->assertStringNotContainsString('meeting_context', (string) ($contact['metadata_json'] ?? ''));
        $this->assertSame('proposal', (string) ($deal['stage'] ?? ''));
        $this->assertSame('skipped', (string) ($run['apply_status'] ?? ''));
        $this->assertStringContainsString('suggest_only_review_required', (string) ($run['reasons_json'] ?? ''));
    }

    public function testExplicitDealMustBelongToResolvedContact(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at)
             VALUES (1, UUID(), 'Other', 'Buyer', 'other-buyer@example.com', ?, NOW())",
            [$this->userId]
        );
        $otherContactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, 'Other Deal', ?, ?, ?, 'proposal', 0, 'USD', NOW())",
            [$otherContactId, $this->userId, $this->userId]
        );
        $otherDealId = (int) Database::lastInsertId();

        $service = new MeetingNoteTakerService(
            ai: new class extends AIService {
                public function process(string $task, array $data, array $context = []): string
                {
                    return json_encode([
                        'summary' => 'Contact-specific meeting captured.',
                        'relationship_context' => 'Keep this on the contact only.',
                        'next_step' => '',
                        'confidence' => 0.88,
                        'contact_updates' => [],
                        'action_items' => [],
                        'deal_stage' => [
                            'suggested_stage' => null,
                            'confidence' => 0,
                            'reason' => '',
                        ],
                    ]);
                }
            }
        );

        $result = $service->ingest([
            'provider' => 'generic',
            'external_meeting_id' => 'deal-contact-mismatch',
            'contact_id' => $this->contactId,
            'deal_id' => $otherDealId,
            'summary' => 'This should not attach to the other contact deal.',
        ], $this->userId);

        $contactNoteCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM notes WHERE entity_type = 'contact' AND entity_id = ?",
            [$this->contactId]
        );
        $dealNoteCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM notes WHERE entity_type = 'deal' AND entity_id = ?",
            [$otherDealId]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->contactId, $result['matched_contact_id']);
        $this->assertNull($result['matched_deal_id']);
        $this->assertContains('deal_contact_mismatch', $result['reasons']);
        $this->assertSame(1, (int) ($contactNoteCount['count'] ?? 0));
        $this->assertSame(0, (int) ($dealNoteCount['count'] ?? 0));
    }

    public function testDuplicateDirectIngestReturnsExistingRunWithoutDuplicatingSideEffects(): void
    {
        $ai = new class extends AIService {
            public int $calls = 0;

            public function process(string $task, array $data, array $context = []): string
            {
                $this->calls++;
                return json_encode([
                    'summary' => 'Apply this once.',
                    'relationship_context' => 'One-time context.',
                    'next_step' => 'Send one task.',
                    'confidence' => 0.91,
                    'contact_updates' => [],
                    'action_items' => [
                        ['text' => 'One follow-up task', 'due' => date('Y-m-d', strtotime('+1 day'))],
                    ],
                    'deal_stage' => [
                        'suggested_stage' => null,
                        'confidence' => 0,
                        'reason' => '',
                    ],
                ]);
            }
        };
        $service = new MeetingNoteTakerService(ai: $ai);
        $payload = [
            'provider' => 'generic',
            'external_meeting_id' => 'dedupe-direct-1',
            'title' => 'Dedupe Meeting',
            'transcript' => 'Create one task only.',
            'attendees' => [
                ['email' => 'prospect@example.com', 'name' => 'Meeting Contact'],
            ],
        ];

        $first = $service->ingest($payload, $this->userId);
        $second = $service->ingest($payload, $this->userId);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue((bool) ($second['duplicate'] ?? false));
        $this->assertSame((int) $first['run_id'], (int) $second['run_id']);
        $this->assertSame(1, $ai->calls);

        $runCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM meeting_note_taker_runs WHERE external_meeting_id = ?",
            ['dedupe-direct-1']
        );
        $noteCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM notes WHERE entity_id IN (?, ?)",
            [$this->contactId, $this->dealId]
        );
        $taskCount = Database::queryOne("SELECT COUNT(*) AS count FROM tasks WHERE contact_id = ?", [$this->contactId]);

        $this->assertSame(1, (int) ($runCount['count'] ?? 0));
        $this->assertSame(2, (int) ($noteCount['count'] ?? 0));
        $this->assertSame(1, (int) ($taskCount['count'] ?? 0));
    }

    public function testFailedApplyRollsBackAndAllowsRetryForSameDedupeKey(): void
    {
        $ai = new class extends AIService {
            public int $calls = 0;

            public function process(string $task, array $data, array $context = []): string
            {
                $this->calls++;
                return json_encode([
                    'summary' => 'Create a task atomically.',
                    'relationship_context' => 'Rollback should remove notes if task creation fails.',
                    'next_step' => 'Create follow-up task.',
                    'confidence' => 0.91,
                    'contact_updates' => [],
                    'action_items' => [
                        ['text' => 'Atomic follow-up task', 'due' => date('Y-m-d', strtotime('+1 day'))],
                    ],
                    'deal_stage' => [
                        'suggested_stage' => null,
                        'confidence' => 0,
                        'reason' => '',
                    ],
                ]);
            }
        };
        $failingTasks = new class extends \CRM\Modules\Tasks {
            public function create(array $data): int
            {
                throw new \RuntimeException('forced_task_failure');
            }
        };
        $payload = [
            'provider' => 'generic',
            'external_meeting_id' => 'retry-after-failed-apply',
            'title' => 'Retry After Failed Apply',
            'transcript' => 'Create a task, then retry after failure.',
            'attendees' => [
                ['email' => 'prospect@example.com', 'name' => 'Meeting Contact'],
            ],
        ];

        $firstService = new MeetingNoteTakerService(ai: $ai, tasks: $failingTasks);
        try {
            $firstService->ingest($payload, $this->userId);
            $this->fail('Expected forced task failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced_task_failure', $e->getMessage());
        }

        $noteCountAfterFailure = Database::queryOne(
            "SELECT COUNT(*) AS count FROM notes WHERE entity_id IN (?, ?)",
            [$this->contactId, $this->dealId]
        );
        $taskCountAfterFailure = Database::queryOne("SELECT COUNT(*) AS count FROM tasks WHERE contact_id = ?", [$this->contactId]);
        $failedRun = Database::queryOne(
            "SELECT id, apply_status, dedupe_key FROM meeting_note_taker_runs WHERE external_meeting_id = ? ORDER BY id DESC LIMIT 1",
            ['retry-after-failed-apply']
        );

        $this->assertSame(0, (int) ($noteCountAfterFailure['count'] ?? 0));
        $this->assertSame(0, (int) ($taskCountAfterFailure['count'] ?? 0));
        $this->assertSame('failed', (string) ($failedRun['apply_status'] ?? ''));
        $this->assertNotSame('', (string) ($failedRun['dedupe_key'] ?? ''));

        $second = (new MeetingNoteTakerService(ai: $ai))->ingest($payload, $this->userId);
        $runCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM meeting_note_taker_runs WHERE external_meeting_id = ?",
            ['retry-after-failed-apply']
        );
        $supersededRun = Database::queryOne(
            "SELECT dedupe_key, reasons_json FROM meeting_note_taker_runs WHERE id = ?",
            [(int) ($failedRun['id'] ?? 0)]
        );

        $this->assertTrue($second['success']);
        $this->assertSame('applied', $second['status']);
        $this->assertSame(2, (int) ($runCount['count'] ?? 0));
        $this->assertSame('', (string) ($supersededRun['dedupe_key'] ?? ''));
        $this->assertStringContainsString('retry_superseded_failed_run', (string) ($supersededRun['reasons_json'] ?? ''));
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'active', ?)",
            ['Meeting Notes Workspace', $slug, $this->userId]
        );

        return (int) Database::lastInsertId();
    }
}
