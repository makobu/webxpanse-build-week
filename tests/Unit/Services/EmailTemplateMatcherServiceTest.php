<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailTemplateMatcherService;
use CRM\Services\EmailTemplates;
use CRM\Tests\DatabaseTestCase;

class EmailTemplateMatcherServiceTest extends DatabaseTestCase
{
    private int $userId;
    private EmailTemplates $templates;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('matcher-user-', true), 'matcher@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $this->templates = new EmailTemplates();
    }

    public function testMatchesWorkspaceTemplateByWorkflowIntentAndPurpose(): void
    {
        $libraryId = $this->templates->create([
            'name' => 'Generic Follow Up',
            'slug' => 'generic-follow-up',
            'subject' => 'Following up',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'follow_up',
            'purpose' => 'follow_up',
            'variables' => ['first_name'],
            'tags' => ['follow-up'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 1,
            'match_metadata_json' => [
                'purposes' => ['follow_up'],
                'workflow_intents' => ['generic_follow_up'],
                'lifecycle_stages' => ['general'],
                'audiences' => ['prospect'],
                'tones' => ['helpful'],
                'required_variables' => ['first_name'],
            ],
        ]);
        $workspaceId = $this->templates->create([
            'name' => 'Proposal Recovery',
            'slug' => 'proposal-recovery',
            'subject' => 'Proposal next step',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'purpose' => 'proposal_follow_up',
            'variables' => ['first_name', 'company', 'sender_name'],
            'tags' => ['proposal', 'follow-up'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'template_key' => 'proposal_follow_up',
            'match_metadata_json' => [
                'purposes' => ['proposal_follow_up'],
                'workflow_intents' => ['proposal_stall_recovery'],
                'lifecycle_stages' => ['proposal'],
                'audiences' => ['prospect'],
                'tones' => ['consultative'],
                'required_variables' => ['first_name', 'company', 'sender_name'],
            ],
        ]);

        $match = (new EmailTemplateMatcherService())->matchForWorkflowAction([
            'type' => 'send_email',
            'template_query' => [
                'intent_key' => 'proposal_stall_recovery',
                'purpose' => 'proposal_follow_up',
                'tone' => 'consultative',
                'lifecycle_stage' => 'proposal',
                'audience' => 'prospect',
                'required_variables' => ['first_name', 'company', 'sender_name'],
            ],
        ]);

        $this->assertSame($workspaceId, (int) $match['template_id']);
        $this->assertNotSame($libraryId, (int) $match['template_id']);
        $this->assertSame('high', $match['confidence']);
        $this->assertFalse((bool) $match['fallback_used']);
    }

    public function testReturnsEmptyMatchWhenQueryIsMissing(): void
    {
        $match = (new EmailTemplateMatcherService())->matchForWorkflowAction([
            'type' => 'send_email',
        ]);

        $this->assertSame(0, (int) $match['template_id']);
        $this->assertSame('none', $match['confidence']);
    }
}
