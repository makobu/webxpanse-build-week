<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\EventBus;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkspaceContext;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AutomationEngineLeadScoreTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureWorkspace(2, 'automation-score-two', 'Automation Score Two');
        $this->switchWorkspace(1);
    }

    public function testUpdateLeadScoreIsWorkspaceScopedCappedAndEmitsScoreChangeEvent(): void
    {
        $contactId = $this->createContact(1, 'workflow-score@example.test', 95);
        $events = [];
        EventBus::subscribe('contact.score_changed', static function (array $payload) use (&$events): void {
            $events[] = $payload;
        });

        (new AutomationEngine())->executeAction(
            ['type' => 'update_lead_score', 'operation' => 'add', 'score' => 20],
            ['contact_id' => $contactId, 'workspace_id' => 1, 'user_id' => 1]
        );

        $contact = Database::queryOne(
            "SELECT lead_score, score_metadata_json FROM contacts WHERE workspace_id = 1 AND id = ?",
            [$contactId]
        );

        $this->assertSame(100, (int) $contact['lead_score']);
        $metadata = json_decode((string) $contact['score_metadata_json'], true);
        $this->assertSame('workflow_update_lead_score', $metadata['source'] ?? null);

        $this->assertCount(1, $events);
        $this->assertSame($contactId, (int) $events[0]['contact_id']);
        $this->assertSame(1, (int) $events[0]['workspace_id']);
        $this->assertSame(95, (int) $events[0]['previous_score']);
        $this->assertSame(100, (int) $events[0]['current_score']);
    }

    public function testUpdateLeadScoreDoesNotCrossWorkspaceBoundary(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-workflow-score@example.test', 77);

        $this->expectException(\RuntimeException::class);
        (new AutomationEngine())->executeAction(
            ['type' => 'update_lead_score', 'operation' => 'set', 'score' => 12],
            ['contact_id' => $foreignContactId, 'workspace_id' => 1, 'user_id' => 1]
        );
    }

    protected function tearDown(): void
    {
        $foreign = Database::queryOne(
            "SELECT lead_score FROM contacts WHERE workspace_id = 2 AND email = 'foreign-workflow-score@example.test'"
        );
        if ($foreign) {
            $this->assertSame(77, (int) $foreign['lead_score']);
        }

        parent::tearDown();
    }

    private function createContact(int $workspaceId, string $email, int $leadScore): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_score, created_at, updated_at)
             VALUES (?, ?, 'Workflow', 'Score', ?, ?, NOW(), NOW())",
            [$workspaceId, uniqid('automation-score-contact-', true), $email, $leadScore]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function switchWorkspace(int $workspaceId): void
    {
        Session::set('active_workspace_id', $workspaceId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    }
}
