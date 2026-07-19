<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceActivationBundleInsightService;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceActivationBundleInsightServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'bundle-insights@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'bundle-insights-other@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();
    }

    public function testInsightRulesGenerateDeterministicCards(): void
    {
        $events = new WorkspaceMarketplaceActivationBundleEventService();
        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'bundle_impression', [
                'metadata' => ['label' => 'WhatsApp-led growth', 'secret_token' => 'do-not-store'],
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'dismissed');
        }

        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked', [
            'metadata' => ['label' => 'Email-led growth'],
        ]);
        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked');

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent($this->workspaceId, $this->userId, 'strategy_foundation', 'bundle_impression');
        }

        $events->recordEvent($this->workspaceId, $this->userId, 'omnichannel_assistant', 'module_installed', [
            'metadata' => ['label' => 'Omnichannel assistant'],
        ]);

        $insights = (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $keys = array_column($insights, 'insight_key');
        $this->assertContains('high_dismissal_rate:whatsapp_led_growth', $keys);
        $this->assertContains('high_interest_low_completion:email_led_growth', $keys);
        $this->assertContains('setup_interest:email_led_growth', $keys);
        $this->assertContains('low_engagement:strategy_foundation', $keys);
        $this->assertContains('install_followthrough:omnichannel_assistant', $keys);
        $this->assertSame('medium', (string) ($insights[0]['severity'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $insights[0]);
        $this->assertArrayHasKey('created_from_range', $insights[0]);
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($insights) ?: ''));
    }

    public function testDateAndUserFiltersLimitInsightScope(): void
    {
        $events = new WorkspaceMarketplaceActivationBundleEventService();
        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked');
        Database::execute(
            "UPDATE workspace_marketplace_activation_bundle_events SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE bundle_key = 'email_led_growth'"
        );

        $events->recordEvent($this->workspaceId, $this->otherUserId, 'whatsapp_led_growth', 'cta_clicked');

        $service = new WorkspaceMarketplaceActivationBundleInsightService();
        $recentForUser = $service->getInsights([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->otherUserId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertNotEmpty($recentForUser);
        $this->assertSame('whatsapp_led_growth', (string) ($recentForUser[0]['bundle_key'] ?? ''));

        $recentForFirstUser = $service->getInsights([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame([], $recentForFirstUser);
    }

    public function testSourceFilterExcludesNonBundleSources(): void
    {
        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent(
            $this->workspaceId,
            $this->userId,
            'email_led_growth',
            'cta_clicked'
        );

        $insights = (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights([
            'workspace_id' => $this->workspaceId,
            'source' => 'coach',
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertSame([], $insights);
    }

    public function testMarketplaceInsightsByBundleShapesOneSafeChipPerBundle(): void
    {
        $events = new WorkspaceMarketplaceActivationBundleEventService();
        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked', [
            'metadata' => ['label' => 'Email-led growth', 'secret_token' => 'do-not-store'],
        ]);
        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked');

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'bundle_impression');
        }
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'dismissed');
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'dismissed');

        $chips = (new WorkspaceMarketplaceActivationBundleInsightService())->marketplaceInsightsByBundle([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertArrayHasKey('email_led_growth', $chips);
        $this->assertArrayHasKey('whatsapp_led_growth', $chips);
        $this->assertSame('Interest without completion', (string) ($chips['email_led_growth']['label'] ?? ''));
        $this->assertSame('High dismissal rate', (string) ($chips['whatsapp_led_growth']['label'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $chips['email_led_growth']);
        $this->assertArrayNotHasKey('bundle_key', $chips['email_led_growth']);
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($chips) ?: ''));
    }

    public function testSelectedBundleWithoutRecentFollowthroughCreatesStaleInsight(): void
    {
        (new WorkspaceMarketplaceActivationBundleService())->updateState(
            $this->workspaceId,
            $this->userId,
            'whatsapp_led_growth',
            'selected',
            ['source' => 'unit_test', 'secret_token' => 'do-not-store']
        );

        $insights = (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertCount(1, $insights);
        $this->assertSame('stale_selected_bundle:whatsapp_led_growth', (string) ($insights[0]['insight_key'] ?? ''));
        $this->assertSame('selected', (string) ($insights[0]['metric_snapshot']['current_status'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($insights) ?: ''));
    }

    public function testEmptyStateReturnsNoInsights(): void
    {
        $insights = (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertSame([], $insights);
    }
}
