<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingLaunchPacketService;
use CRM\Services\WorkspaceContext;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class MarketingLaunchPacketServiceTest extends DatabaseTestCase
{
    private Marketing $marketing;
    private MarketingLaunchPacketService $service;
    private int $userId;
    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'marketing', NOW())",
            [uniqid('launch-packet-user-', true), 'launch-packet@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            ['Launch Packet Workspace', 'launch-packet-' . uniqid(), $this->userId]
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
        $this->service = new MarketingLaunchPacketService($this->marketing);
    }

    public function testEmptyWorkspaceReturnsLowScoreAndSetupAction(): void
    {
        $packet = $this->service->build(['score' => 0]);

        $this->assertSame('not_started', $packet['status']);
        $this->assertSame(0, $packet['score']);
        $this->assertSame('setup', $packet['missing'][0]['key']);
        $this->assertSame('marketing_launch_packet.php?step=setup', $packet['primary_action_url']);
        $this->assertSame('marketing_launch_packet.php?step=setup', $packet['completion_url']);
        $this->assertSame('setup', $packet['first_missing_step']);
        $this->assertNull($packet['packet_post_id']);
        $this->assertNull($packet['proof_url']);
        $this->assertSame('marketing_performance.php', $packet['results_url']);
        $this->assertFalse($packet['needs_manual_proof']);
        $this->assertFalse($packet['guardrails']['external_send']);
        $this->assertFalse($packet['guardrails']['external_publish']);
        $this->assertFalse($packet['guardrails']['external_api_execution']);
        $this->assertTrue($packet['guardrails']['manual_first']);
    }

    public function testPartialPathReturnsMissingItemsInPrimaryPathOrder(): void
    {
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Founder Audience',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCampaignBrief([
            'title' => 'Founder Campaign Brief',
            'audience' => 'Founder Audience',
            'audience_segment_id' => $segmentId,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $packet = $this->service->build(['score' => 70]);

        $this->assertSame('building', $packet['status']);
        $this->assertSame(['content', 'landing', 'send'], array_column($packet['missing'], 'key'));
        $this->assertSame(['setup', 'audiences', 'campaigns'], array_column($packet['evidence'], 'key'));
        $this->assertSame('marketing_launch_packet.php?step=content', $packet['primary_action_url']);
        $this->assertSame('content', $packet['first_missing_step']);
    }

    public function testDistributionPostReturnsPacketUrlAndReadyStatus(): void
    {
        $fixture = $this->createPrimaryLaunchRecords();

        $packet = $this->service->build(['score' => 80]);

        $this->assertSame('ready', $packet['status']);
        $this->assertSame(100, $packet['score']);
        $this->assertSame('marketing_distribution_bundle.php?id=' . $fixture['distribution_post_id'], $packet['packet_url']);
        $this->assertSame($fixture['distribution_post_id'], $packet['packet_post_id']);
        $this->assertSame('marketing_launch_proof.php?id=' . $fixture['distribution_post_id'], $packet['proof_url']);
        $this->assertTrue($packet['needs_manual_proof']);
        $this->assertSame($packet['packet_url'], $packet['primary_action_url']);
        $this->assertSame('Open launch packet', $packet['primary_action_label']);
    }

    public function testPublishedEvidenceUpdatesStatusWithoutExternalExecution(): void
    {
        $fixture = $this->createPrimaryLaunchRecords();
        $this->marketing->exportDistributionPost($fixture['distribution_post_id']);
        $this->marketing->markDistributionPostPublished(
            $fixture['distribution_post_id'],
            'https://example.com/manual-launch-proof',
            date('Y-m-d\TH:i')
        );

        $packet = $this->service->build(['score' => 80]);

        $this->assertSame('published_evidence', $packet['status']);
        $this->assertSame('marketing_distribution_bundle.php?id=' . $fixture['distribution_post_id'], $packet['packet_url']);
        $this->assertSame('marketing_performance.php', $packet['primary_action_url']);
        $this->assertSame('View first results', $packet['primary_action_label']);
        $this->assertFalse($packet['needs_manual_proof']);
        $this->assertFalse($packet['guardrails']['external_send']);
        $this->assertFalse($packet['guardrails']['external_publish']);
        $this->assertFalse($packet['guardrails']['external_api_execution']);
        $this->assertContains('results', array_column($packet['evidence'], 'key'));
    }

    /**
     * @return array{segment_id:int,brief_id:int,landing_page_id:int,content_id:int,distribution_post_id:int}
     */
    private function createPrimaryLaunchRecords(): array
    {
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Packet Audience',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Packet Brief',
            'objective' => 'Prepare one manual launch package.',
            'audience' => 'Packet Audience',
            'audience_segment_id' => $segmentId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Packet Landing Page',
            'slug' => 'packet-landing-' . uniqid(),
            'headline' => 'A clear manual launch destination',
            'status' => 'approved',
            'audience_segment_id' => $segmentId,
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Packet Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Manual launch copy for the founder packet.',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'email',
            'planned_copy' => 'Manual launch copy for the founder packet.',
            'publishing_checklist' => ['Review copy', 'Publish manually', 'Record proof'],
            'required_fields' => ['copy', 'destination_url'],
            'created_by' => $this->userId,
        ]);

        return [
            'segment_id' => $segmentId,
            'brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'content_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
        ];
    }
}
