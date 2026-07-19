<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\DraftReview;
use CRM\Modules\DraftTemplates;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class DraftWorkspaceScopeTest extends DatabaseTestCase
{
    private int $userId;
    private int $otherWorkspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at)
             VALUES (?, ?, 'user', NOW())",
            ['draft-scope@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $_SESSION['user_id'] = $this->userId;

        Database::execute(
            "INSERT INTO workspaces (name, slug, status, plan_status, created_at, updated_at)
             VALUES ('Draft Other Workspace', 'draft-other', 'active', 'trial', NOW(), NOW())"
        );
        $this->otherWorkspaceId = (int) Database::lastInsertId();
    }

    public function testDraftTemplatesAreScopedToActiveWorkspace(): void
    {
        $templates = new DraftTemplates();
        $templateId = $templates->create([
            'name' => 'Follow-up',
            'type' => 'email',
            'purpose' => 'follow_up',
            'body' => 'Hello {first_name}',
            'created_by' => $this->userId,
        ]);

        $this->assertNotNull($templates->getById($templateId));

        WorkspaceContext::activateRuntimeWorkspace($this->otherWorkspaceId);
        $this->assertNull($templates->getById($templateId));
        $this->assertSame([], $templates->getAll());

        WorkspaceContext::activateRuntimeWorkspace(1);
    }

    public function testDraftReviewsAreScopedToActiveWorkspace(): void
    {
        $reviews = new DraftReview();
        $draftId = $reviews->createDraft([
            'draft_type' => 'email',
            'subject' => 'Subject',
            'body' => 'Body',
            'created_by' => $this->userId,
        ]);

        $this->assertNotNull($reviews->getById($draftId));

        WorkspaceContext::activateRuntimeWorkspace($this->otherWorkspaceId);
        $this->assertNull($reviews->getById($draftId));
        $this->assertSame([], $reviews->getDraftsForReview());

        WorkspaceContext::activateRuntimeWorkspace(1);
    }
}
