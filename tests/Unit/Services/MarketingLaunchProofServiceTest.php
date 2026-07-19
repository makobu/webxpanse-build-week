<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingLaunchProofService;
use CRM\Services\WorkspaceContext;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class MarketingLaunchProofServiceTest extends DatabaseTestCase
{
    private Marketing $marketing;
    private MarketingLaunchProofService $service;
    private int $userId;
    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'marketing', NOW())",
            [uniqid('launch-proof-user-', true), 'launch-proof@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            ['Launch Proof Workspace', 'launch-proof-' . uniqid(), $this->userId]
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
        $this->service = new MarketingLaunchProofService($this->marketing);
    }

    public function testNoPacketReturnsCompletePacketFirstState(): void
    {
        $proof = $this->service->build(0, ['score' => 0]);

        $this->assertSame('no_packet', $proof['proof_status']);
        $this->assertNull($proof['distribution_post']);
        $this->assertNull($proof['form']);
        $this->assertSame('Complete packet', $proof['primary_action_label']);
        $this->assertSame('marketing_launch_packet.php?step=setup', $proof['primary_action_url']);
        $this->assertFalse($proof['guardrails']['external_send']);
        $this->assertFalse($proof['guardrails']['external_publish']);
        $this->assertFalse($proof['guardrails']['external_api_execution']);
    }

    public function testReadyPacketSelectsProofFormAndNeedsManualProof(): void
    {
        $fixture = $this->createPrimaryLaunchRecords();
        $proof = $this->service->build($fixture['distribution_post_id'], ['score' => 80]);

        $this->assertSame('needs_proof', $proof['proof_status']);
        $this->assertSame('record_manual_publish_proof', $proof['form']['action']);
        $this->assertSame($fixture['distribution_post_id'], $proof['form']['post_id']);
        $this->assertTrue((bool) ($proof['packet']['needs_manual_proof'] ?? false));
        $this->assertSame('marketing_launch_proof.php?id=' . $fixture['distribution_post_id'], $proof['packet']['proof_url']);
    }

    public function testRecordManualPublishProofMarksDistributionPostPublished(): void
    {
        $fixture = $this->createPrimaryLaunchRecords();
        $result = $this->service->recordManualPublishProof($fixture['distribution_post_id'], [
            'published_url' => 'https://example.com/manual-proof',
            'published_at' => '2026-06-02T10:30',
        ], $this->userId);

        $post = $this->marketing->getDistributionPost($fixture['distribution_post_id']);
        $this->assertSame('published', (string) ($post['status'] ?? ''));
        $this->assertSame('https://example.com/manual-proof', (string) ($post['published_url'] ?? ''));
        $this->assertSame('published', $result['proof_status']);
        $this->assertSame('published_evidence', $result['packet']['status']);
    }

    public function testPublishedProofUpdatesPacketStatusAndResultsAction(): void
    {
        $fixture = $this->createPrimaryLaunchRecords();
        $this->service->recordManualPublishProof($fixture['distribution_post_id'], [
            'published_url' => 'https://example.com/results-proof',
        ], $this->userId);

        $proof = $this->service->build($fixture['distribution_post_id'], ['score' => 80]);

        $this->assertSame('published', $proof['proof_status']);
        $this->assertSame('published_evidence', $proof['packet']['status']);
        $this->assertSame('marketing_performance.php', $proof['packet']['primary_action_url']);
        $this->assertFalse((bool) ($proof['packet']['needs_manual_proof'] ?? true));
        $this->assertSame('View first results', $proof['primary_action_label']);
        $this->assertArrayHasKey('conversions', $proof['first_results']);
    }

    /**
     * @return array{segment_id:int,brief_id:int,landing_page_id:int,content_id:int,distribution_post_id:int}
     */
    private function createPrimaryLaunchRecords(): array
    {
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Proof Audience',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Proof Brief',
            'objective' => 'Prepare one manual launch package.',
            'audience' => 'Proof Audience',
            'audience_segment_id' => $segmentId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Proof Landing Page',
            'slug' => 'proof-landing-' . uniqid(),
            'headline' => 'A clear manual launch destination',
            'status' => 'approved',
            'audience_segment_id' => $segmentId,
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Proof Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Manual launch copy for proof capture.',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'email',
            'planned_copy' => 'Manual launch copy for proof capture.',
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
