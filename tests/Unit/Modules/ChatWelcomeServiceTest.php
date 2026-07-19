<?php

declare(strict_types=1);

use CRM\Modules\ChatWelcomeService;

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../modules/ChatWelcomeService.php';

final class ChatWelcomeServiceTest extends PHPUnit\Framework\TestCase
{
    public function testBuildGreetingStaysShortAndEscapesDisplayName(): void
    {
        $service = new ChatWelcomeService();

        $html = $service->buildGreeting([
            'display_name' => 'Mako & Co',
            'overdue_tasks' => [['title' => 'Product Pricing Setup']],
            'today_tasks' => [['title' => 'Call buyer']],
            'ai_starter_tasks' => [['title' => 'Company Profile Configuration']],
            'unread_notifications' => 0,
            'today_events' => [],
            'revenue_focus' => ["New lead conversion: qualify today&#039;s 4 new leads and book next steps."],
            'ai_tasks_created' => 0,
        ]);

        $this->assertStringContainsString('Mako &amp; Co', $html);
        $this->assertStringContainsString('next move that makes the workspace stronger', $html);
        $this->assertStringNotContainsString("Today's Revenue Focus", $html);
        $this->assertStringNotContainsString('Overdue tasks', $html);
        $this->assertStringNotContainsString('Starter tasks ready', $html);
        $this->assertStringNotContainsString('Product Pricing Setup', $html);
    }

    public function testBuildGreetingDoesNotLeadWithObviousUnreadNotificationCount(): void
    {
        $service = new ChatWelcomeService();

        $html = $service->buildGreeting([
            'display_name' => 'Makobudennis',
            'overdue_tasks' => [],
            'today_tasks' => [],
            'ai_starter_tasks' => [],
            'unread_notifications' => 8,
            'today_events' => [],
            'revenue_focus' => [],
            'ai_tasks_created' => 0,
        ]);

        $this->assertStringNotContainsString('Unread notifications', $html);
        $this->assertStringContainsString('next move that makes the workspace stronger', $html);
    }

    public function testWelcomeDataUsesFirstNameOnly(): void
    {
        $service = new ChatWelcomeService();
        $method = new ReflectionMethod(ChatWelcomeService::class, 'getDisplayName');
        $method->setAccessible(true);

        $this->assertSame('Dennis', $method->invoke($service, 'Dennis', 'K', 'dennis@example.test'));
    }
}
