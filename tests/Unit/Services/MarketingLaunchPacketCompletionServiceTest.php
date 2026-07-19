<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingLaunchPacketCompletionService;
use CRM\Services\WorkspaceContext;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class MarketingLaunchPacketCompletionServiceTest extends DatabaseTestCase
{
    private Marketing $marketing;
    private MarketingLaunchPacketCompletionService $service;
    private int $userId;
    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'marketing', NOW())",
            [uniqid('launch-completion-user-', true), 'launch-completion@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            ['Launch Completion Workspace', 'launch-completion-' . uniqid(), $this->userId]
        );
        $this->workspaceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        Session::set('user_id', $this->userId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $this->marketing = new Marketing();
        $this->service = new MarketingLaunchPacketCompletionService($this->marketing);
    }

    public function testEmptyWorkspaceSelectsFoundationFirst(): void
    {
        $completion = $this->service->build(['score' => 0]);

        $this->assertSame('setup', $completion['first_missing_step']);
        $this->assertSame('setup', $completion['active_step']);
        $this->assertSame('create_foundation', $completion['form']['action']);
        $this->assertSame('marketing_launch_packet.php?step=setup', $completion['packet']['primary_action_url']);
        $this->assertFalse($completion['guardrails']['external_send']);
        $this->assertFalse($completion['guardrails']['external_publish']);
        $this->assertFalse($completion['guardrails']['external_api_execution']);
    }

    public function testPartialPacketSelectsNextMissingPrimaryStep(): void
    {
        $this->service->create('create_foundation', [], $this->userId);
        $this->service->create('create_audience', [], $this->userId);
        $this->service->create('create_campaign', [], $this->userId);

        $completion = $this->service->build($this->marketing->getMarketingOnboardingStatus());

        $this->assertSame('content', $completion['first_missing_step']);
        $this->assertSame('content', $completion['active_step']);
        $this->assertSame('create_content', $completion['form']['action']);
        $this->assertSame(['setup', 'audiences', 'campaigns'], array_column($completion['packet']['evidence'], 'key'));
    }

    public function testCreateActionsCreateExpectedRecordsAndPacketUrl(): void
    {
        $foundation = $this->service->create('create_foundation', [
            'brand_name' => 'Completion Brand',
            'customer_name' => 'Completion Customer',
            'offer' => 'Guided launch packet',
            'proof_point' => 'Manual-first proof',
            'cta' => 'Book a packet review',
        ], $this->userId);
        $this->assertSame('foundation', $foundation['created_type']);
        $this->assertCount(1, $this->marketing->listBrandProfiles());
        $this->assertCount(1, $this->marketing->listPersonas());
        $this->assertGreaterThanOrEqual(4, count($this->marketing->listContextItems()));

        $audience = $this->service->create('create_audience', ['name' => 'Completion Audience'], $this->userId);
        $this->assertSame('audience', $audience['created_type']);
        $this->assertCount(1, $this->marketing->listAudienceSegments(['status' => 'active'], 10, 0));

        $campaign = $this->service->create('create_campaign', ['title' => 'Completion Campaign'], $this->userId);
        $this->assertSame('campaign', $campaign['created_type']);
        $this->assertCount(1, $this->marketing->listCampaignBriefs(['status_open' => true], 10, 0));

        $content = $this->service->create('create_content', [
            'title' => 'Completion Content',
            'draft_body' => 'Manual packet copy.',
        ], $this->userId);
        $this->assertSame('content', $content['created_type']);
        $this->assertCount(1, $this->marketing->listContentItems(['exclude_status' => 'archived'], 10, 0));

        $landing = $this->service->create('create_landing', ['title' => 'Completion Landing'], $this->userId);
        $this->assertSame('landing', $landing['created_type']);
        $this->assertCount(1, $this->marketing->listLandingPages([], 10, 0));

        $distribution = $this->service->create('create_distribution', [], $this->userId);
        $this->assertSame('distribution', $distribution['created_type']);
        $this->assertStringStartsWith('marketing_distribution_bundle.php?id=', $distribution['redirect_url']);
        $this->assertStringStartsWith('marketing_distribution_bundle.php?id=', (string) ($distribution['packet']['packet_url'] ?? ''));
        $this->assertSame('ready', $distribution['packet']['status']);
    }

    public function testDistributionCreationProducesPacketUrlWithoutExternalExecution(): void
    {
        $this->service->create('create_foundation', [], $this->userId);
        $this->service->create('create_audience', [], $this->userId);
        $this->service->create('create_campaign', [], $this->userId);
        $this->service->create('create_content', [], $this->userId);
        $this->service->create('create_landing', [], $this->userId);
        $result = $this->service->create('create_distribution', [], $this->userId);

        $posts = $this->marketing->listDistributionPosts([], 10, 0);
        $this->assertCount(1, $posts);
        $this->assertSame('draft', (string) ($posts[0]['status'] ?? ''));
        $this->assertSame('marketing_distribution_bundle.php?id=' . (int) ($posts[0]['id'] ?? 0), $result['packet']['packet_url']);
        $this->assertFalse($result['packet']['guardrails']['external_send']);
        $this->assertFalse($result['packet']['guardrails']['external_publish']);
        $this->assertFalse($result['packet']['guardrails']['external_api_execution']);
    }
}
