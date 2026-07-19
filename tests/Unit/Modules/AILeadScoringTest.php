<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\EventBus;
use CRM\Modules\AILeadScoring;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScoringConfigService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;
use InvalidArgumentException;

class AILeadScoringTest extends DatabaseTestCase
{
    private AILeadScoring $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureWorkspace(2, 'ai-score-two', 'AI Score Two');
        $this->switchWorkspace(1);
        $this->scoring = new AILeadScoring();
    }

    public function testRecalculatePersistsCompositeWithMlUnavailableButNoMlScore(): void
    {
        $contactId = $this->createContact(1, 'ml-unavailable@example.test');
        $this->logActivity(1, $contactId, 'form_submit');

        $breakdown = $this->scoring->recalculateScore($contactId, 'conversion', null, 'unit_test');

        $this->assertSame(20, (int) $breakdown['consolidated_score']);
        $this->assertNull($breakdown['ml_score']);
        $this->assertFalse($breakdown['ml_metadata']['available']);
        $this->assertTrue($breakdown['ml_metadata']['fallback_used']);
        $this->assertSame(['engagement' => 1.0, 'ml' => 0.0, 'ai' => 0.0], $breakdown['effective_weights']);

        $stored = Database::queryOne(
            "SELECT lead_score, engagement_score, ml_score, ai_score, score_metadata_json
             FROM contacts WHERE workspace_id = 1 AND id = ?",
            [$contactId]
        );

        $this->assertSame(20, (int) $stored['lead_score']);
        $this->assertSame(20, (int) $stored['engagement_score']);
        $this->assertNull($stored['ml_score']);
        $metadata = json_decode((string) $stored['score_metadata_json'], true);
        $this->assertFalse($metadata['ml_available']);
        $this->assertTrue($metadata['ml_fallback_used']);
    }

    public function testRecalculatePublishesScoreChangedEvent(): void
    {
        $contactId = $this->createContact(1, 'score-event@example.test');
        Database::execute(
            "UPDATE contacts SET lead_score = 5 WHERE workspace_id = 1 AND id = ?",
            [$contactId]
        );
        $this->logActivity(1, $contactId, 'call');

        $events = [];
        EventBus::subscribe('contact.score_changed', static function (array $payload) use (&$events): void {
            $events[] = $payload;
        });

        $this->scoring->recalculateScore($contactId, 'conversion', null, 'unit_event_test');

        $this->assertCount(1, $events);
        $this->assertSame($contactId, (int) $events[0]['contact_id']);
        $this->assertSame(1, (int) $events[0]['workspace_id']);
        $this->assertSame(5, (int) $events[0]['previous_score']);
        $this->assertSame(12, (int) $events[0]['current_score']);
        $this->assertSame('unit_event_test', $events[0]['source']);
    }

    public function testWeightValidationRejectsInvalidInputs(): void
    {
        foreach ([
            ['ml' => 0.5, 'ai' => 0.5],
            ['engagement' => -0.1, 'ml' => 0.8, 'ai' => 0.3],
            ['engagement' => 1.1, 'ml' => 0.0, 'ai' => 0.0],
            ['engagement' => 'nope', 'ml' => 0.5, 'ai' => 0.5],
            ['engagement' => 0.2, 'ml' => 0.2, 'ai' => 0.2],
        ] as $weights) {
            try {
                AILeadScoring::validateWeights($weights);
                $this->fail('Expected invalid weights to be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAutoRecommendedWeightsAreUsedWhenNoCustomWeightsExist(): void
    {
        $contactId = $this->createContact(1, 'auto-recommended@example.test');
        $this->logActivity(1, $contactId, 'email');
        (new WorkspaceScoringConfigService())->saveConfig(
            1,
            ['engagement' => 0.1, 'ml' => 0.8, 'ai' => 0.1],
            true
        );

        $breakdown = $this->scoring->recalculateScore($contactId, 'conversion', null, 'unit_auto_recommended');

        $this->assertSame(['engagement' => 1.0, 'ml' => 0.0, 'ai' => 0.0], $breakdown['weights']);
        $this->assertSame('workspace_recommended', $breakdown['score_source_metadata']['requested_weights_source']);
    }

    public function testCustomWeightsOverrideAutoRecommendedWeights(): void
    {
        $contactId = $this->createContact(1, 'custom-over-auto@example.test');
        Database::execute(
            "UPDATE contacts SET score_weights = ? WHERE workspace_id = 1 AND id = ?",
            [json_encode(['engagement' => 0.2, 'ml' => 0.3, 'ai' => 0.5]), $contactId]
        );
        (new WorkspaceScoringConfigService())->saveConfig(
            1,
            ['engagement' => 0.1, 'ml' => 0.8, 'ai' => 0.1],
            true
        );

        $this->assertSame(
            ['engagement' => 0.2, 'ml' => 0.3, 'ai' => 0.5],
            $this->scoring->getWeights($contactId)
        );
    }

    public function testRecalculateRejectsForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-ai-score@example.test');
        $this->switchWorkspace(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact not found in the active workspace.');
        $this->scoring->recalculateScore($foreignContactId);
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at, updated_at)
             VALUES (?, ?, 'Score', 'Contact', ?, NOW(), NOW())",
            [$workspaceId, uniqid('ai-score-contact-', true), $email]
        );

        return (int) Database::lastInsertId();
    }

    private function logActivity(int $workspaceId, int $contactId, string $type): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (?, ?, ?, NOW())",
            [$workspaceId, $contactId, $type]
        );
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
