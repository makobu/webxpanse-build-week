<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationInsightService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceRecommendationInsightServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('marketplace-insights-', true), 'marketplace-insights@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('marketplace-insights-other-', true), 'marketplace-insights-other@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();
    }

    public function testInsightRulesGenerateDeterministicCards(): void
    {
        $events = new WorkspaceMarketplaceRecommendationEventService();

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'impression', [
                'reason_codes' => ['selected_channel_whatsapp'],
                'metadata' => ['label' => 'WhatsApp Assistant'],
            ]);
        }
        $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'dismissed');
        $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'snoozed');
        $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'dismissed');
        $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'coach', 'cta_clicked');
        $events->recordEvent(1, $this->userId, 'whatsapp_assistant', 'coach', 'cta_clicked');

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent(1, $this->userId, 'email_assistant', 'clarity_chat', 'impression', [
                'metadata' => ['label' => 'Email Assistant'],
            ]);
        }

        $events->recordEvent(1, $this->userId, 'lean_canvas', 'coach', 'task_created', [
            'metadata' => ['label' => 'Lean Canvas', 'task_id' => 42],
        ]);

        $insights = (new WorkspaceMarketplaceRecommendationInsightService())->getInsights([
            'workspace_id' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $keys = array_map(static fn(array $insight): string => (string) ($insight['insight_key'] ?? ''), $insights);
        $this->assertContains('high_dismissal_rate:whatsapp_assistant:marketplace', $keys);
        $this->assertContains('high_interest_low_install:whatsapp_assistant:coach', $keys);
        $this->assertContains('setup_interest:whatsapp_assistant:coach', $keys);
        $this->assertContains('surface_mismatch:whatsapp_assistant:marketplace', $keys);
        $this->assertContains('low_engagement:email_assistant:clarity_chat', $keys);
        $this->assertContains('coach_task_followthrough:lean_canvas:coach', $keys);

        $this->assertSame('high', (string) ($insights[0]['severity'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $insights[0]);
        $this->assertArrayHasKey('created_from_range', $insights[0]);
        $this->assertContains('selected_channel_whatsapp', (array) ($insights[0]['reason_codes'] ?? []));

        $mapped = (new WorkspaceMarketplaceRecommendationInsightService())->marketplaceInsightsBySkill([
            'workspace_id' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertArrayHasKey('whatsapp_assistant', $mapped);
        $this->assertSame('High dismissal rate', (string) ($mapped['whatsapp_assistant']['label'] ?? ''));
        $this->assertSame('high', (string) ($mapped['whatsapp_assistant']['severity'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $mapped['whatsapp_assistant']);
        $this->assertArrayNotHasKey('reason_codes', $mapped['whatsapp_assistant']);
        $this->assertArrayNotHasKey('created_from_range', $mapped['whatsapp_assistant']);
    }

    public function testDateAndUserFiltersLimitInsightScope(): void
    {
        $events = new WorkspaceMarketplaceRecommendationEventService();

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent(1, $this->userId, 'professional_marketer', 'marketplace', 'impression');
        }
        $events->recordEvent(1, $this->otherUserId, 'email_assistant', 'coach', 'task_created');

        Database::execute(
            "UPDATE workspace_marketplace_recommendation_events
             SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 10 DAY)
             WHERE skill_key = 'professional_marketer'"
        );

        $recent = (new WorkspaceMarketplaceRecommendationInsightService())->getInsights([
            'workspace_id' => 1,
            'user_id' => $this->userId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame([], $recent);

        $otherUser = (new WorkspaceMarketplaceRecommendationInsightService())->getInsights([
            'workspace_id' => 1,
            'user_id' => $this->otherUserId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($otherUser);
        $this->assertSame('email_assistant', (string) ($otherUser[0]['skill_key'] ?? ''));

        $mapped = (new WorkspaceMarketplaceRecommendationInsightService())->marketplaceInsightsBySkill([
            'workspace_id' => 1,
            'user_id' => $this->otherUserId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(['email_assistant'], array_keys($mapped));
    }

    public function testEmptyEventStreamReturnsNoInsights(): void
    {
        $insights = (new WorkspaceMarketplaceRecommendationInsightService())->getInsights(['workspace_id' => 1]);
        $this->assertSame([], $insights);
        $this->assertSame([], (new WorkspaceMarketplaceRecommendationInsightService())->marketplaceInsightsBySkill(['workspace_id' => 1]));
    }
}
