<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceRecommendationAdaptiveSignalService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceRecommendationAdaptiveSignalServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('adaptive-signal-', true), 'adaptive-signal@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testNoSignalFallbackReturnsEmptySignals(): void
    {
        $this->assertSame([], (new WorkspaceMarketplaceRecommendationAdaptiveSignalService())->signalsBySkill(['workspace_id' => 1]));
    }

    public function testPositiveMomentumAndInterestAreCappedAtTen(): void
    {
        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'setup_opened', [
            'label' => 'WhatsApp Assistant',
        ]);
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'marketplace', 'cta_clicked', [
            'metadata' => ['label' => 'WhatsApp Assistant'],
        ]);

        $signals = (new WorkspaceMarketplaceRecommendationAdaptiveSignalService())->signalsBySkill(['workspace_id' => 1]);
        $signal = $signals[WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT] ?? [];

        $this->assertSame(10, (int) ($signal['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_recent_setup_momentum', (array) ($signal['adaptive_reason_codes'] ?? []));
        $this->assertContains('adaptive_interest_needs_setup', (array) ($signal['adaptive_reason_codes'] ?? []));
        $this->assertSame('medium', (string) ($signal['adaptive_confidence'] ?? ''));
    }

    public function testHighDismissalAndLowEngagementDampenRecommendations(): void
    {
        $events = new WorkspaceMarketplaceRecommendationEventService();
        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'impression');
        }
        $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'dismissed');
        $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'snoozed');

        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'marketplace', 'impression');
        }

        $signals = (new WorkspaceMarketplaceRecommendationAdaptiveSignalService())->signalsBySkill(['workspace_id' => 1]);

        $this->assertSame(-8, (int) ($signals[WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER]['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_high_dismissal_rate', (array) ($signals[WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER]['adaptive_reason_codes'] ?? []));
        $this->assertSame(-5, (int) ($signals[WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_low_engagement', (array) ($signals[WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]['adaptive_reason_codes'] ?? []));
    }

    public function testDateFilteringAndSummaryAreDeterministic(): void
    {
        $events = new WorkspaceMarketplaceRecommendationEventService();
        $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'marketplace', 'task_created');
        $events->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'marketplace', 'cta_clicked');

        Database::execute(
            "UPDATE workspace_marketplace_recommendation_events
             SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 10 DAY)
             WHERE skill_key = ?",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );

        $service = new WorkspaceMarketplaceRecommendationAdaptiveSignalService();
        $recent = $service->signalsBySkill([
            'workspace_id' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $recent);
        $this->assertArrayNotHasKey(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $recent);

        $summary = $service->getSummary(['workspace_id' => 1]);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['positive_boosts'] ?? 0));
        $this->assertNotEmpty($summary['top_skills'] ?? []);
        $this->assertArrayHasKey('adaptive_recent_setup_momentum', (array) ($summary['reason_counts'] ?? []));
    }
}
