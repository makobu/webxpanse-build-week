<?php
/**
 * Email Assistant Integration Tests
 * Verifies digest rendering and handler flow.
 */

namespace CRM\Tests\Integration;

use CRM\Tests\TestCase;
use CRM\Services\AssistantEmailRenderer;
use CRM\Modules\EmailAssistantHandler;

class EmailAssistantIntegrationTest extends TestCase
{
    private AssistantEmailRenderer $renderer;
    private EmailAssistantHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new AssistantEmailRenderer();
        $this->handler = new EmailAssistantHandler();
    }

    public function testDigestRenderingWithStructuredData(): void
    {
        $displayTasks = [
            [
                'title' => 'Integration test task',
                'due_date' => date('Y-m-d', strtotime('+1 day')),
                'priority' => 'high',
            ],
        ];
        $recs = [
            'priorities' => [['title' => 'Close Q1 deals']],
            'quick_wins' => [['title' => 'Update contact info']],
            'why_this_matters' => 'Focus on revenue.',
        ];

        $result = $this->renderer->renderDigest($displayTasks, $recs, true);

        $this->assertStringContainsString('Integration test task', $result['html']);
        $this->assertStringContainsString('Integration test task', $result['plain']);
        $this->assertStringContainsString('Close Q1 deals', $result['html']);
        $this->assertStringContainsString('Test', $result['html']);
    }

    public function testReplyRenderingWithStructuredOutcome(): void
    {
        $successBody = "Task created: \"Follow up with client\" (ID: 42). Due: 2025-02-27.";
        $result = $this->renderer->renderReply($successBody);

        $this->assertStringContainsString('Follow up with client', $result['html']);
        $this->assertStringContainsString('Personal Assistant', $result['html']);
        $this->assertSame($successBody, $result['plain']);
    }

    public function testClassifyIntentWithKeywordFallbacks(): void
    {
        $this->assertSame('create_task', $this->handler->classifyIntent('Create task: Call John'));
        $this->assertSame('update_contact', $this->handler->classifyIntent('Update contact john@test.com'));
        $this->assertSame('delete_contact', $this->handler->classifyIntent('Delete contact john@test.com'));
        $this->assertSame('enrich_contact', $this->handler->classifyIntent('Enrich contact john@test.com'));
        $this->assertSame('verify_contact_email', $this->handler->classifyIntent('Verify email for john@test.com'));
    }
}
