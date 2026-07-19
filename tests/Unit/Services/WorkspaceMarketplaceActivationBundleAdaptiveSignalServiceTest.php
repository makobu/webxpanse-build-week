<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceActivationBundleAdaptiveSignalService;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceActivationBundleAdaptiveSignalServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'bundle-adaptive@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testSignalsBoostMomentumAndCapPositiveDelta(): void
    {
        $events = new WorkspaceMarketplaceActivationBundleEventService();
        $events->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked');

        $signals = (new WorkspaceMarketplaceActivationBundleAdaptiveSignalService())->signalsByBundle([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertArrayHasKey('email_led_growth', $signals);
        $this->assertSame(10, (int) ($signals['email_led_growth']['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_bundle_activation_momentum', (array) ($signals['email_led_growth']['adaptive_reason_codes'] ?? []));
        $this->assertContains('adaptive_bundle_interest_needs_setup', (array) ($signals['email_led_growth']['adaptive_reason_codes'] ?? []));
    }

    public function testSignalsDampenDismissalsAndLowEngagement(): void
    {
        $events = new WorkspaceMarketplaceActivationBundleEventService();
        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'bundle_impression');
        }
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'dismissed');
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'dismissed');

        $signals = (new WorkspaceMarketplaceActivationBundleAdaptiveSignalService())->signalsByBundle([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertArrayHasKey('whatsapp_led_growth', $signals);
        $this->assertSame(-8, (int) ($signals['whatsapp_led_growth']['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_bundle_high_dismissal_rate', (array) ($signals['whatsapp_led_growth']['adaptive_reason_codes'] ?? []));
    }

    public function testSelectedStateCanCreateStaleSelectedSignal(): void
    {
        (new WorkspaceMarketplaceActivationBundleService())->updateState(
            $this->workspaceId,
            $this->userId,
            'whatsapp_led_growth',
            'selected',
            ['source' => 'unit_test', 'secret_token' => 'do-not-store']
        );

        $signals = (new WorkspaceMarketplaceActivationBundleAdaptiveSignalService())->signalsByBundle([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertArrayHasKey('whatsapp_led_growth', $signals);
        $this->assertSame(1, (int) ($signals['whatsapp_led_growth']['adaptive_score_delta'] ?? 0));
        $this->assertContains('adaptive_bundle_activation_momentum', (array) ($signals['whatsapp_led_growth']['adaptive_reason_codes'] ?? []));
        $this->assertContains('adaptive_bundle_stale_selected', (array) ($signals['whatsapp_led_growth']['adaptive_reason_codes'] ?? []));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($signals) ?: ''));
    }

    public function testDateAndSourceFiltersLimitSignals(): void
    {
        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent($this->workspaceId, $this->userId, 'email_led_growth', 'cta_clicked');
        Database::execute(
            "UPDATE workspace_marketplace_activation_bundle_events SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE bundle_key = 'email_led_growth'"
        );

        $service = new WorkspaceMarketplaceActivationBundleAdaptiveSignalService();
        $this->assertSame([], $service->signalsByBundle([
            'workspace_id' => $this->workspaceId,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]));
        $this->assertSame([], $service->signalsByBundle([
            'workspace_id' => $this->workspaceId,
            'source' => 'coach',
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
        ]));
    }
}
