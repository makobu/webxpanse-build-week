<?php
/**
 * Assistant Email Renderer Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Services\AssistantEmailRenderer;
use CRM\Tests\TestCase;

class AssistantEmailRendererTest extends TestCase
{
    private AssistantEmailRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new AssistantEmailRenderer();
    }

    public function testRenderReplyReturnsHtmlAndPlain(): void
    {
        $body = "Task created: \"Follow up\" (ID: 42). Due: 2025-02-27.";
        $result = $this->renderer->renderReply($body);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('plain', $result);
        $this->assertSame($body, $result['plain']);
    }

    public function testRenderReplyEscapesHtmlInContent(): void
    {
        $body = "Test <script>alert('xss')</script> & special chars";
        $result = $this->renderer->renderReply($body);

        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        $this->assertStringContainsString('&amp;', $result['html']);
    }

    public function testRenderReplyContainsMinimalSafeWrapper(): void
    {
        $body = "Hello";
        $result = $this->renderer->renderReply($body);

        $this->assertStringContainsString('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('<html>', $result['html']);
        $this->assertStringContainsString('font-family', $result['html']);
        $this->assertStringContainsString('max-width: 600px', $result['html']);
        $this->assertStringContainsString('Email Assistant', $result['html']);
    }

    public function testRenderReplyPreservesNewlinesAsBr(): void
    {
        $body = "Line 1\nLine 2";
        $result = $this->renderer->renderReply($body);

        $this->assertStringContainsString('<br', $result['html']);
    }

    public function testRenderDigestReturnsHtmlAndPlain(): void
    {
        $tasks = [
            [
                'title' => 'Call client',
                'due_date' => '2025-02-27',
                'priority' => 'high',
                'task_url' => 'https://crm.example.test/task_view.php?id=42',
                'contact_name' => 'Acme Inc',
                'contact_company' => 'Acme Group',
                'contact_url' => 'https://crm.example.test/contact_view.php?id=7',
                'owner_email' => 'owner@example.test',
            ],
        ];
        $recs = [
            'priorities' => [['title' => 'Close deal']],
            'quick_wins' => [],
            'why_this_matters' => 'Focus on revenue.',
        ];
        $result = $this->renderer->renderDigest($tasks, $recs, false, new \DateTimeImmutable('2025-02-27 08:00:00'), [
            'workspace_name' => 'Default Workspace',
            'tasks_url' => 'https://crm.example.test/tasks.php',
        ]);

        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('plain', $result);
        $this->assertStringContainsString('<html lang="en">', $result['html']);
        $this->assertStringContainsString('role="presentation"', $result['html']);
        $this->assertStringContainsString('Default Workspace: 0 overdue, 1 due today, 0 unscheduled, 2 assistant recommendations.', $result['html']);
        $this->assertStringContainsString('Email Assistant', $result['html']);
        $this->assertStringContainsString('Daily Digest', $result['html']);
        $this->assertStringContainsString('Call client', $result['html']);
        $this->assertStringContainsString('Close deal', $result['html']);
        $this->assertStringContainsString('Default Workspace', $result['html']);
        $this->assertStringContainsString('Due Today', $result['html']);
        $this->assertStringContainsString('Overdue', $result['html']);
        $this->assertStringContainsString('AI Recommendations', $result['html']);
        $this->assertStringContainsString('background:#fef3c7', $result['html']);
        $this->assertStringContainsString('https://crm.example.test/task_view.php?id=42', $result['html']);
        $this->assertStringContainsString('https://crm.example.test/contact_view.php?id=7', $result['html']);
        $this->assertStringContainsString('Account: Acme Group', $result['html']);
        $this->assertStringContainsString('Owner: owner@example.test', $result['html']);
        $this->assertStringContainsString('Thursday, February 27, 2025', $result['plain']);
        $this->assertStringContainsString('SUMMARY', $result['plain']);
        $this->assertStringContainsString('Due Today: 1', $result['plain']);
        $this->assertStringContainsString('AI Recommendations: 2', $result['plain']);
        $this->assertStringContainsString('DUE TODAY (1)', $result['plain']);
        $this->assertStringContainsString('Call client (Due today, High priority, Contact: Acme Inc, Account: Acme Group, Owner: owner@example.test) - https://crm.example.test/task_view.php?id=42', $result['plain']);
        $this->assertStringContainsString('Open task list: https://crm.example.test/tasks.php', $result['plain']);
        $this->assertStringContainsString('Focus Today: Focus on revenue', $result['plain']);
        $this->assertStringContainsString('Sent by your Email Assistant.', $result['html']);
        $this->assertStringNotContainsString('â', $result['html']);
        $this->assertStringNotContainsString('Ã¢', $result['html']);
    }

    public function testRenderDigestEmptyTasks(): void
    {
        $result = $this->renderer->renderDigest([], ['priorities' => [], 'quick_wins' => []]);

        $this->assertStringContainsString('You are clear for today', $result['html']);
        $this->assertStringContainsString('You are clear for today', $result['plain']);
        $this->assertStringContainsString('AI Recommendations: 0', $result['plain']);
        $this->assertStringNotContainsString('<h2 style="margin:0;color:#0f172a;font-size:18px;line-height:1.3;font-weight:800;">AI recommendations</h2>', $result['html']);
        $this->assertStringNotContainsString('AI RECOMMENDATIONS', $result['plain']);
    }

    public function testRenderDigestGroupsTasksByUrgency(): void
    {
        $tasks = [
            ['title' => 'Today task', 'due_date' => '2025-02-27', 'priority' => 'medium'],
            ['title' => 'Overdue task', 'due_date' => '2025-02-26', 'priority' => 'low'],
            ['title' => 'Unscheduled task', 'due_date' => null, 'priority' => 'urgent'],
        ];

        $result = $this->renderer->renderDigest($tasks, [], false, new \DateTimeImmutable('2025-02-27 08:00:00'));
        $plain = $result['plain'];

        $this->assertStringContainsString('OVERDUE (1)', $plain);
        $this->assertStringContainsString('DUE TODAY (1)', $plain);
        $this->assertStringContainsString('UNSCHEDULED (1)', $plain);
        $this->assertLessThan(strpos($plain, 'DUE TODAY'), strpos($plain, 'OVERDUE'));
        $this->assertLessThan(strpos($plain, 'UNSCHEDULED'), strpos($plain, 'DUE TODAY'));
    }

    public function testRenderDigestTestFlag(): void
    {
        $result = $this->renderer->renderDigest([], [], true);
        $this->assertStringContainsString('Your CRM Daily Digest (Test)', $result['html']);
        $this->assertStringContainsString('Test Send', $result['html']);
    }

    public function testRenderDigestEscapesTaskTitlesAndRecommendations(): void
    {
        $tasks = [[
            'title' => 'Task with <b>HTML</b>',
            'due_date' => null,
            'priority' => 'medium',
            'contact_company' => 'Account <script>bad()</script>',
        ]];
        $recs = [
            'priorities' => [['title' => 'Close <script>bad()</script>']],
            'quick_wins' => [],
            'why_this_matters' => '<b>Focus</b>',
        ];
        $result = $this->renderer->renderDigest($tasks, $recs);

        $this->assertStringNotContainsString('<b>', $result['html']);
        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringContainsString('&lt;b&gt;', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        $this->assertStringContainsString('Account: Account &lt;script&gt;bad()&lt;/script&gt;', $result['html']);
    }

    public function testRenderDigestOmitsRelativeLinks(): void
    {
        $tasks = [[
            'title' => 'Relative link task',
            'due_date' => '2025-02-27',
            'priority' => 'urgent',
            'task_url' => 'task_view.php?id=42',
            'contact_name' => 'Acme',
            'contact_company' => 'Acme',
            'contact_url' => '/contact_view.php?id=7',
        ]];

        $result = $this->renderer->renderDigest($tasks, [], false, new \DateTimeImmutable('2025-02-27 08:00:00'), [
            'tasks_url' => 'tasks.php',
        ]);

        $this->assertStringContainsString('Relative link task', $result['html']);
        $this->assertStringContainsString('Acme', $result['html']);
        $this->assertStringNotContainsString('href="task_view.php?id=42"', $result['html']);
        $this->assertStringNotContainsString('href="/contact_view.php?id=7"', $result['html']);
        $this->assertStringNotContainsString('Account:', $result['html']);
        $this->assertStringNotContainsString('Account:', $result['plain']);
        $this->assertStringNotContainsString('Open task list:', $result['plain']);
        $this->assertStringContainsString('Urgent priority', $result['plain']);
    }
}
