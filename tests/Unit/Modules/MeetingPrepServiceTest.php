<?php
/**
 * Meeting Prep Service Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Activities;
use CRM\Modules\Contacts;
use CRM\Modules\MeetingPrepService;
use CRM\Modules\Notes;
use CRM\Modules\UnifiedInbox;
use CRM\Services\AIService;
use CRM\Tests\DatabaseTestCase;

class MeetingPrepServiceTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['meeting-prep-test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testReturnsDeterministicFallbackWhenAiReturnsEmpty(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Dennis',
            'last_name' => 'Makobu',
            'email' => 'meeting-prep@example.com',
            'company' => 'Oscar Creatives',
        ]);
        $contactId = (int) $contact['id'];

        Database::execute(
            "INSERT INTO activities (contact_id, activity_type, description, created_at) VALUES (?, ?, ?, NOW())",
            [$contactId, 'meeting_logged', 'Discussed current priorities']
        );

        $notes = new Notes();
        $notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'title' => 'Discovery',
            'content' => 'Captured pain points and next steps.',
            'created_by' => $this->userId,
        ]);

        $ai = new class extends AIService {
            public function __construct()
            {
            }

            public function process(string $task, array $data, array $context = []): string
            {
                return '';
            }
        };

        $activities = new class extends Activities {
            public function getByContact(int $contactId, int $limit = 50, int $offset = 0): array
            {
                return [[
                    'activity_type' => 'meeting_logged',
                    'description' => 'Discussed current priorities',
                    'created_at' => '2026-04-15 10:00:00',
                ]];
            }
        };

        $inbox = new class extends UnifiedInbox {
            public function getByContact(int $contactId, int $limit = 50, int $offset = 0): array
            {
                return [];
            }
        };

        $service = new MeetingPrepService(
            $ai,
            $notes,
            null,
            $activities,
            $inbox
        );

        $result = $service->getPrepSummary($contactId, null, $this->userId);

        $this->assertTrue((bool) ($result['used_fallback'] ?? false));
        $this->assertNotEmpty($result['summary']);
        $this->assertNotSame('Unable to generate meeting prep.', $result['summary']);
        $this->assertNotEmpty($result['key_points']);
        $this->assertNotEmpty($result['open_questions']);
        $this->assertNotEmpty($result['suggested_topics']);

        $this->contacts->delete($contactId);
    }

    public function testRejectsCrossWorkspaceContactIds(): void
    {
        $otherWorkspaceId = $this->createWorkspace('meeting-prep-other-contact');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, UUID(), 'Other', 'Contact', 'other-prep@example.com', NOW())",
            [$otherWorkspaceId]
        );
        $otherContactId = (int) Database::lastInsertId();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Contact not found');

        (new MeetingPrepService())->getPrepSummary($otherContactId, null, $this->userId);
    }

    public function testRejectsCrossWorkspaceDealIdsForAccessibleContact(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Workspace',
            'last_name' => 'Contact',
            'email' => 'workspace-prep@example.com',
        ]);
        $contactId = (int) $contact['id'];
        $otherWorkspaceId = $this->createWorkspace('meeting-prep-other-deal');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, UUID(), 'Other', 'Deal Contact', 'other-deal-prep@example.com', NOW())",
            [$otherWorkspaceId]
        );
        $otherContactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (?, 'Cross Workspace Deal', ?, ?, ?, 'proposal', 0, 'USD', NOW())",
            [$otherWorkspaceId, $otherContactId, $this->userId, $this->userId]
        );
        $otherDealId = (int) Database::lastInsertId();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Contact not found');

        (new MeetingPrepService())->getPrepSummary($contactId, $otherDealId, $this->userId);
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), 'Meeting Prep Other Workspace', ?, 'active', 'active', ?)",
            [$slug, $this->userId]
        );

        return (int) Database::lastInsertId();
    }
}
