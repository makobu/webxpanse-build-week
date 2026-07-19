<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingCampaignKitService;
use CRM\Services\SocialMediaService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class MarketingCampaignKitServiceTest extends DatabaseTestCase
{
    private MarketingCampaignKitService $service;
    private int $userId;
    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'marketing', NOW())",
            [uniqid('campaign-kit-user-', true), 'campaign-kit@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            ['Campaign Kit Workspace', 'campaign-kit-' . uniqid(), $this->userId]
        );
        $this->workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships
             (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        Session::set('user_id', $this->userId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        (new WorkspaceSkillCatalogService())->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs
             (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                $this->workspaceId,
                WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                $this->userId,
                $this->userId,
            ]
        );
        WorkspaceSkillCatalogService::resetRuntimeCaches();
        $this->service = new MarketingCampaignKitService(new Marketing());
    }

    public function testGenerationStagesCoordinatedArtifactsWithoutCreatingOrPublishingContent(): void
    {
        $run = $this->generateKit();

        $this->assertSame('ready', $run['status'], (string) ($run['error_text'] ?? 'Campaign Kit generation failed without an error message.'));
        $this->assertSame(['linkedin', 'email'], $run['channels_json']);
        $this->assertCount(2, $run['artifacts']);
        $this->assertSame(2, $run['state_summary']['generated']);
        $this->assertSame(0, $run['state_summary']['accepted']);
        $this->assertStringContainsString('Qualified service founders', (string) $run['message_house_json']['core_message']);
        $this->assertFalse((bool) $run['artifacts'][0]['creative_direction_json']['external_generation']);
        $this->assertTrue((bool) $run['artifacts'][0]['quality_json']['human_review_required']);

        $this->assertSame(0, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_content_items WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
        $this->assertSame(0, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_distribution_posts WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
        $this->assertSame(0, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM social_media_publish_jobs WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
    }

    public function testArtifactCanBeEditedAcceptedAndTracedIntoContentStudio(): void
    {
        $run = $this->generateKit();
        $artifact = $run['artifacts'][0];
        $editedBody = "A focused LinkedIn campaign draft for qualified service founders.\n\nBook a strategy review.";

        $updated = $this->service->updateArtifact((int) $artifact['id'], [
            'title' => 'Founder pipeline LinkedIn draft',
            'body' => $editedBody,
            'visual_prompt' => 'A credible founder reviewing a simple pipeline dashboard, 1.91:1, no invented claims.',
        ]);
        $this->assertSame($editedBody, $updated['body']);
        $this->assertTrue((bool) $updated['creative_direction_json']['edited_by_user']);

        $contentId = $this->service->acceptArtifact((int) $artifact['id'], $this->userId);
        $this->assertGreaterThan(0, $contentId);
        $this->assertSame($contentId, $this->service->acceptArtifact((int) $artifact['id'], $this->userId));

        $content = (new Marketing())->getContentItem($contentId);
        $this->assertNotNull($content);
        $this->assertSame('draft', $content['status']);
        $this->assertSame('linkedin', $content['channel']);
        $this->assertSame($editedBody, $content['draft_body']);
        $this->assertSame('campaign_kit', $content['metadata_json']['source']);
        $this->assertSame((int) $run['id'], (int) $content['metadata_json']['generation_lineage']['run_id']);
        $this->assertSame((int) $artifact['id'], (int) $content['metadata_json']['generation_lineage']['artifact_id']);

        $reloaded = $this->service->getRun((int) $run['id']);
        $this->assertSame('partially_accepted', $reloaded['status']);
        $this->assertSame(1, $reloaded['state_summary']['accepted']);
        $this->assertSame(1, $reloaded['state_summary']['generated']);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_content_items WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
    }

    public function testVisualHandoffCreatesAdvisoryDesignRequestWithoutExternalGeneration(): void
    {
        $run = $this->generateKit();
        $artifactId = (int) $run['artifacts'][0]['id'];
        $contentId = $this->service->acceptArtifact($artifactId, $this->userId);

        $requestId = $this->service->queueVisualBrief($artifactId, $this->userId);
        $this->assertSame($requestId, $this->service->queueVisualBrief($artifactId, $this->userId));

        $request = (new Marketing())->getAiMediaRequest($requestId);
        $this->assertNotNull($request);
        $this->assertSame($contentId, (int) $request['content_item_id']);
        $this->assertSame('manual_ai_media_bridge', $request['provider_json']['provider']);
        $this->assertFalse((bool) $request['provider_json']['external_generation']);

        $reloaded = $this->service->getRun((int) $run['id']);
        $this->assertSame($requestId, (int) $reloaded['artifacts'][0]['visual_request_id']);
        $this->assertFalse((bool) $reloaded['artifacts'][0]['creative_direction_json']['external_generation']);
    }

    public function testAcceptedArtifactPreparesIdempotentTrackedLaunchWithoutPublishing(): void
    {
        $run = $this->generateKit();
        $artifactId = (int) $run['artifacts'][0]['id'];
        $contentId = $this->service->acceptArtifact($artifactId, $this->userId);

        $prepared = $this->service->prepareLaunchHandoff($artifactId, [
            'destination_url' => 'https://example.com/pipeline-review',
            'utm_campaign' => 'founder-pipeline-review',
        ], $this->userId);
        $replayed = $this->service->prepareLaunchHandoff($artifactId, [
            'destination_url' => 'https://example.com/ignored-on-idempotent-replay',
        ], $this->userId);

        $this->assertGreaterThan(0, (int) $prepared['distribution_post_id']);
        $this->assertGreaterThan(0, (int) $prepared['utm_link_id']);
        $this->assertGreaterThan(0, (int) $prepared['channel_media_kit_id']);
        $this->assertSame((int) $prepared['distribution_post_id'], (int) $replayed['distribution_post_id']);
        $this->assertSame((int) $prepared['utm_link_id'], (int) $replayed['utm_link_id']);
        $this->assertSame((int) $prepared['channel_media_kit_id'], (int) $replayed['channel_media_kit_id']);
        $this->assertSame('prepared', $prepared['handoff_status']);
        $this->assertFalse((bool) $prepared['handoff_json']['guardrails']['external_publish']);
        $this->assertFalse((bool) $prepared['handoff_json']['guardrails']['social_job_created']);

        $distribution = (new Marketing())->getDistributionPost((int) $prepared['distribution_post_id']);
        $this->assertSame('draft', $distribution['status']);
        $this->assertSame($contentId, (int) $distribution['content_item_id']);
        $utm = Database::queryOne(
            'SELECT * FROM marketing_utm_links WHERE workspace_id = ? AND id = ?',
            [$this->workspaceId, (int) $prepared['utm_link_id']]
        );
        $this->assertStringContainsString('utm_campaign=founder-pipeline-review', (string) $utm['generated_url']);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_distribution_posts WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_utm_links WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_channel_media_kits WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
        $this->assertSame(0, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM social_media_publish_jobs WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
    }

    public function testOutcomeRefreshUsesRecordedCrmRevenueAndLatestSocialEvidence(): void
    {
        $run = $this->generateKit();
        $artifactId = (int) $run['artifacts'][0]['id'];
        $contentId = $this->service->acceptArtifact($artifactId, $this->userId);
        $prepared = $this->service->prepareLaunchHandoff($artifactId, [
            'destination_url' => 'https://example.com/pipeline-review',
        ], $this->userId);
        $utmId = (int) $prepared['utm_link_id'];
        $distributionId = (int) $prepared['distribution_post_id'];

        Database::execute(
            'INSERT INTO marketing_visitor_sessions (workspace_id, uuid, session_key) VALUES (?, UUID(), ?)',
            [$this->workspaceId, 'campaign-kit-session-' . uniqid()]
        );
        $sessionId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_tracking_events
             (workspace_id, visitor_session_id, content_item_id, utm_link_id, uuid, event_type, event_name)
             VALUES (?, ?, ?, ?, UUID(), 'page_view', 'Campaign Kit page view')",
            [$this->workspaceId, $sessionId, $contentId, $utmId]
        );
        Database::execute(
            "INSERT INTO marketing_tracking_events
             (workspace_id, visitor_session_id, content_item_id, utm_link_id, uuid, event_type, event_name)
             VALUES (?, ?, ?, ?, UUID(), 'cta_click', 'Campaign Kit CTA click')",
            [$this->workspaceId, $sessionId, $contentId, $utmId]
        );
        Database::execute(
            "INSERT INTO marketing_tracking_events
             (workspace_id, visitor_session_id, content_item_id, utm_link_id, uuid, event_type, event_name)
             VALUES (?, ?, ?, ?, UUID(), 'conversion', 'Campaign Kit conversion')",
            [$this->workspaceId, $sessionId, $contentId, $utmId]
        );
        $conversionTrackingId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_conversion_events
             (workspace_id, visitor_session_id, tracking_event_id, uuid, conversion_type, conversion_value)
             VALUES (?, ?, ?, UUID(), 'demo_request', 250.00)",
            [$this->workspaceId, $sessionId, $conversionTrackingId]
        );
        Database::execute(
            "INSERT INTO marketing_attribution_touchpoints
             (workspace_id, uuid, content_item_id, utm_link_id, touchpoint_type, attribution_model,
              source, medium, channel, revenue_amount)
             VALUES (?, UUID(), ?, ?, 'revenue', 'linear', 'linkedin', 'organic-social', 'linkedin', 1250.00)",
            [$this->workspaceId, $contentId, $utmId]
        );

        Database::execute(
            "INSERT INTO social_media_accounts
             (workspace_id, uuid, provider, channel, external_account_id, account_name, status, access_token_encrypted)
             VALUES (?, UUID(), 'linkedin', 'linkedin', ?, 'Campaign Kit LinkedIn', 'active', 'test-token')",
            [$this->workspaceId, 'campaign-kit-' . uniqid()]
        );
        $accountId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO social_media_publish_jobs
             (workspace_id, uuid, account_id, content_item_id, distribution_post_id, status, caption,
              provider_post_id, idempotency_key, published_at, created_by, updated_by)
             VALUES (?, UUID(), ?, ?, ?, 'published', 'Recorded Campaign Kit post', ?, ?, NOW(), ?, ?)",
            [
                $this->workspaceId,
                $accountId,
                $contentId,
                $distributionId,
                'provider-post-' . uniqid(),
                hash('sha256', 'campaign-kit-outcome-' . uniqid()),
                $this->userId,
                $this->userId,
            ]
        );
        $publishJobId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO social_media_metric_snapshots
             (workspace_id, account_id, publish_job_id, provider_post_id, impressions, reach, engagements, clicks, captured_at)
             VALUES (?, ?, ?, ?, 900, 640, 12, 7, NOW())",
            [$this->workspaceId, $accountId, $publishJobId, 'provider-post-metric-' . uniqid()]
        );

        $refreshed = $this->service->refreshOutcomes((int) $run['id'], $this->userId);
        $totals = (array) $refreshed['outcome_json']['totals'];
        $artifactOutcome = (array) $refreshed['artifacts'][0]['last_outcome_json'];

        $this->assertSame(1, (int) $totals['unique_visitors']);
        $this->assertSame(1, (int) $totals['cta_clicks']);
        $this->assertSame(1, (int) $totals['conversions']);
        $this->assertSame(1250.0, (float) $totals['revenue']);
        $this->assertSame(12, (int) $totals['engagements']);
        $this->assertSame('revenue_proven', $artifactOutcome['signal_type']);
        $this->assertSame('published', $refreshed['artifacts'][0]['handoff_status']);
        $this->assertStringContainsString('reusable campaign template', (string) $artifactOutcome['recommendation']);

        $nextRun = $this->generateKit();
        $patterns = (array) ($nextRun['message_house_json']['evidence_backed_patterns'] ?? []);
        $this->assertNotEmpty($patterns);
        $this->assertSame('revenue_proven', $patterns[0]['signal_type']);
        $this->assertSame('guidance_only_human_review_required', $patterns[0]['reuse_boundary']);
        $this->assertStringContainsString(
            'Workspace performance evidence to consider, not copy blindly',
            (string) $nextRun['artifacts'][0]['source_context_json']['prompt_inputs']['objective']
        );

        $otherWorkspaceId = $this->createWorkspace('Learning Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $isolatedRun = (new MarketingCampaignKitService(new Marketing()))->generate([
            'title' => 'Isolated founder campaign',
            'objective' => 'Generate qualified consultation requests.',
            'target_audience' => 'Founders in the separate workspace',
            'channels' => ['linkedin'],
        ], $this->userId);
        $this->assertSame([], $isolatedRun['message_house_json']['evidence_backed_patterns']);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $this->service->refreshOutcomes((int) $run['id'], $this->userId);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_generation_learning_signals WHERE workspace_id = ? AND generation_run_id = ?',
            [$this->workspaceId, (int) $run['id']]
        )['aggregate']);
    }

    public function testSocialSubmissionReusesPreparedSingleChannelHandoffWithLineage(): void
    {
        $run = $this->generateKit();
        $artifactId = (int) $run['artifacts'][0]['id'];
        $contentId = $this->service->acceptArtifact($artifactId, $this->userId);
        $prepared = $this->service->prepareLaunchHandoff($artifactId, [
            'destination_url' => 'https://example.com/pipeline-review',
        ], $this->userId);

        Database::execute(
            "INSERT INTO workspace_skill_installs
             (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                $this->workspaceId,
                WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
                $this->userId,
                $this->userId,
            ]
        );
        WorkspaceSkillCatalogService::resetRuntimeCaches();
        Database::execute(
            "INSERT INTO social_media_settings (workspace_id, enabled, approval_required, created_by, updated_by)
             VALUES (?, 1, 1, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = 1, approval_required = 1",
            [$this->workspaceId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO social_media_accounts
             (workspace_id, uuid, provider, channel, external_account_id, account_name, status, access_token_encrypted)
             VALUES (?, UUID(), 'linkedin', 'linkedin', ?, 'Campaign Kit LinkedIn', 'active', 'test-token')",
            [$this->workspaceId, 'campaign-kit-social-' . uniqid()]
        );
        $accountId = (int) Database::lastInsertId();

        $social = new SocialMediaService($this->workspaceId);
        $result = $social->createPublishJobs([
            'campaign_id' => 0,
            'content_item_id' => $contentId,
            'distribution_post_id' => (int) $prepared['distribution_post_id'],
            'utm_link_id' => (int) $prepared['utm_link_id'],
            'caption' => 'Review this accepted Campaign Kit message before it is published.',
            'scheduled_at' => date('Y-m-d\TH:i', time() + 7200),
            'account_ids' => [$accountId],
            'client_request_id' => 'campaign-kit-social-submit-' . uniqid(),
        ], $this->userId);

        $this->assertSame(1, (int) $result['count']);
        $this->assertTrue((bool) $result['approval_required']);
        $job = Database::queryOne(
            'SELECT * FROM social_media_publish_jobs WHERE workspace_id = ? AND id = ?',
            [$this->workspaceId, (int) $result['job_ids'][0]]
        );
        $this->assertSame('pending_approval', $job['status']);
        $this->assertSame((int) $prepared['distribution_post_id'], (int) $job['distribution_post_id']);
        $this->assertSame($contentId, (int) $job['content_item_id']);
        $request = json_decode((string) $job['request_json'], true);
        $this->assertTrue((bool) $request['campaign_kit_handoff']);
        $this->assertSame((int) $prepared['utm_link_id'], (int) $request['utm_link_id']);
        $this->assertSame(1, (int) Database::queryOne(
            'SELECT COUNT(*) AS aggregate FROM marketing_distribution_posts WHERE workspace_id = ?',
            [$this->workspaceId]
        )['aggregate']);
    }

    public function testGenerationLedgerIsWorkspaceIsolated(): void
    {
        $run = $this->generateKit();
        $otherWorkspaceId = $this->createWorkspace('Other Campaign Kit Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherService = new MarketingCampaignKitService(new Marketing());
        $this->assertNull($otherService->getRun((int) $run['id']));
        $this->assertSame([], $otherService->listRuns());

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->assertNotNull($this->service->getRun((int) $run['id']));
    }

    private function generateKit(): array
    {
        return $this->service->generate([
            'title' => 'Qualified service founders',
            'objective' => 'Generate six qualified consultation requests.',
            'target_audience' => 'Service business founders with an inconsistent sales pipeline',
            'offer_text' => 'A practical 30-minute pipeline review',
            'cta_text' => 'Book a strategy review',
            'funnel_stage' => 'conversion',
            'channels' => ['linkedin', 'email'],
        ], $this->userId);
    }

    private function createWorkspace(string $name): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            [$name, strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(), $this->userId]
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships
             (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$workspaceId, $this->userId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $this->userId, 'owner', $this->userId);

        return $workspaceId;
    }
}
