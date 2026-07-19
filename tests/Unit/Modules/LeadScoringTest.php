<?php
/**
 * Lead Scoring Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\LeadScoring;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

class LeadScoringTest extends DatabaseTestCase
{
    private LeadScoring $leadScoring;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->leadScoring = new LeadScoring();
        $this->ensureWorkspace(2, 'lead-scoring-two', 'Lead Scoring Two');
        $this->switchWorkspace(1);
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [1, uniqid('lead-score-contact-', true), 'John', 'Doe', 'john@example.com']
        );
        $this->testContactId = (int) Database::lastInsertId();
    }
    
    public function testCalculateScore()
    {
        // Create activities
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (1, ?, 'email_opened', NOW())",
            [$this->testContactId]
        );
        
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (1, ?, 'link_clicked', DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [$this->testContactId]
        );
        
        $score = $this->leadScoring->calculateScore($this->testContactId);
        
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        
        // Verify only engagement_score was saved; lead_score is the composite score.
        $contact = Database::queryOne("SELECT lead_score, engagement_score FROM contacts WHERE id = ?", [$this->testContactId]);
        $this->assertSame($score, (int) $contact['engagement_score']);
        $this->assertSame(0, (int) $contact['lead_score']);
    }
    
    public function testCalculateScoreWithNoActivities()
    {
        $score = $this->leadScoring->calculateScore($this->testContactId);
        
        $this->assertEquals(0, $score);
    }
    
    public function testCalculateScoreCapsAt100()
    {
        // Create many activities to exceed 100
        for ($i = 0; $i < 20; $i++) {
            Database::execute(
                "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
                 VALUES (1, ?, 'form_submit', NOW())",
                [$this->testContactId]
            );
        }
        
        $score = $this->leadScoring->calculateScore($this->testContactId);
        
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testCalculateEngagementScorePersistsEngagementScoreOnly()
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (1, ?, 'email_opened', NOW())",
            [$this->testContactId]
        );

        $result = $this->leadScoring->calculateEngagementScore($this->testContactId);

        $this->assertIsArray($result);
        $this->assertSame($result['score'], (int) (Database::queryOne(
            "SELECT engagement_score FROM contacts WHERE id = ?",
            [$this->testContactId]
        )['engagement_score'] ?? 0));
    }

    public function testCalculateScoreIgnoresForeignWorkspaceActivities(): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (1, ?, 'email_opened', NOW())",
            [$this->testContactId]
        );
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (2, ?, 'form_submitted', NOW())",
            [$this->testContactId]
        );

        $score = $this->leadScoring->calculateScore($this->testContactId);

        $this->assertSame(5, $score);
    }

    public function testCalculateScoreIncludesRealActivityTypesAndLegacyAlias(): void
    {
        foreach (['email', 'call', 'meeting', 'note', 'form_submit', 'form_submitted'] as $type) {
            Database::execute(
                "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
                 VALUES (1, ?, ?, NOW())",
                [$this->testContactId, $type]
            );
        }

        $result = $this->leadScoring->calculateEngagementScore($this->testContactId);

        $this->assertSame(81, (int) $result['score']);
        foreach (['email', 'call', 'meeting', 'note', 'form_submit', 'form_submitted'] as $type) {
            $this->assertArrayHasKey($type, $result['activity_breakdown']);
        }
    }

    public function testCalculateScoreRejectsForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-lead-score@example.test');
        $this->switchWorkspace(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact not found in the active workspace.');
        $this->leadScoring->calculateScore($foreignContactId);
    }

    public function testCalculateScoreDoesNotUpdateForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-write-score@example.test');
        Database::execute(
            "UPDATE contacts SET lead_score = 77 WHERE workspace_id = 2 AND id = ?",
            [$foreignContactId]
        );

        $this->switchWorkspace(1);

        try {
            $this->leadScoring->calculateScore($foreignContactId);
        } catch (\RuntimeException $e) {
            // Expected; verify the write did not cross workspace boundaries.
        }

        $foreign = Database::queryOne(
            "SELECT lead_score FROM contacts WHERE workspace_id = 2 AND id = ?",
            [$foreignContactId]
        );
        $this->assertSame(77, (int) ($foreign['lead_score'] ?? 0));
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, 'Scoped', 'Contact', ?, NOW())",
            [$workspaceId, uniqid('lead-score-contact-', true), $email]
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
