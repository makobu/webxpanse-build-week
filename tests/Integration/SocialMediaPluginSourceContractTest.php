<?php

namespace CRM\Tests\Integration;

use CRM\Services\WorkspaceSkillCatalogService;
use PHPUnit\Framework\TestCase;

class SocialMediaPluginSourceContractTest extends TestCase
{
    public function testCatalogAndGatePointToTheDedicatedInstalledRuntime(): void
    {
        $catalog = (new WorkspaceSkillCatalogService())->definitions();
        $social = $catalog[WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA] ?? null;

        $this->assertIsArray($social);
        $this->assertTrue($social['capabilities']['runtime_plugin']);
        $this->assertTrue($social['capabilities']['runtime_during_setup']);
        $this->assertSame('social_media.php', $social['navigation']['url']);
        $this->assertSame('workspace_skills.php?module=social_media#setup', $social['plugin_metadata']['setup_url']);

        $gate = file_get_contents(__DIR__ . '/../../services/MarketingMarketplaceGateService.php');
        $access = file_get_contents(__DIR__ . '/../../services/WorkspaceMarketplaceAccessService.php');
        $this->assertStringContainsString("self::FEATURE_SOCIAL_MEDIA => [WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA]", (string) $gate);
        $this->assertStringContainsString("'social_media.php'", (string) $gate);
        $this->assertStringContainsString("!empty(\$module['capabilities']['runtime_during_setup'])", (string) $access);
    }

    public function testProductionRuntimeDeclaresSecurityReliabilityAndProviderContracts(): void
    {
        $service = file_get_contents(__DIR__ . '/../../services/SocialMediaService.php');

        $this->assertStringContainsString('assertInstalled()', (string) $service);
        $this->assertStringContainsString('access_token_encrypted', (string) $service);
        $this->assertStringContainsString('OAuthTokenVault', (string) $service);
        $this->assertStringContainsString('idempotency_key', (string) $service);
        $this->assertStringContainsString('Database::beginTransaction()', (string) $service);
        $this->assertStringContainsString("status = 'processing'", (string) $service);
        $this->assertStringContainsString('next_attempt_at', (string) $service);
        $this->assertStringContainsString('assertPublicDownloadUrl', (string) $service);
        $this->assertStringContainsString('CURLOPT_RESOLVE', (string) $service);
        $this->assertStringContainsString('trackedLinkUrl', (string) $service);
        $this->assertStringContainsString("utm_medium", (string) $service);
        $this->assertStringContainsString('validatePublishingContext', (string) $service);
        $this->assertStringContainsString('sourceDistributionId', (string) $service);
        $this->assertStringContainsString('reusePreparedHandoff', (string) $service);
        $this->assertStringContainsString("'campaign_kit_handoff' =>", (string) $service);
        $this->assertStringContainsString('existingPublishJobs', (string) $service);
        $this->assertStringContainsString('recoverStaleProcessingJobs', (string) $service);
        $this->assertStringContainsString('delivery_status_unknown', (string) $service);
        $this->assertStringContainsString('latest_metrics.last_captured_at', (string) $service);
        $this->assertStringContainsString("'status' => \$approvalRequired ? 'draft' : 'scheduled'", (string) $service);
        $this->assertStringContainsString("UPDATE marketing_distribution_posts SET status = 'cancelled'", (string) $service);
        $this->assertStringContainsString("/media_publish", (string) $service);
        $this->assertStringContainsString("https://api.linkedin.com/rest/posts", (string) $service);
        $this->assertStringContainsString("3000 - mb_strlen(\$link) - 2", (string) $service);
        $this->assertStringNotContainsString("'article' =>", (string) $service);
        $this->assertStringContainsString('organizationAuthorizations', (string) $service);
        $this->assertStringContainsString('ORGANIC_SHARE_CREATE', (string) $service);
        $this->assertStringContainsString('social_media_metric_snapshots', (string) $service);
    }

    public function testMigrationWorkersAndOauthEndpointsCoverTheProductionSurface(): void
    {
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/522_social_media_production_runtime.sql');
        foreach ([
            'social_media_settings',
            'social_media_accounts',
            'social_media_oauth_states',
            'social_media_publish_jobs',
            'social_media_metric_snapshots',
            'social_media_events',
        ] as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, (string) $migration);
        }
        $this->assertStringContainsString('access_token_encrypted MEDIUMTEXT NOT NULL', (string) $migration);
        $this->assertStringContainsString('UNIQUE KEY uniq_social_media_job_idempotency', (string) $migration);
        $eventMigration = file_get_contents(__DIR__ . '/../../database/migrations/523_expand_social_media_event_types.sql');
        $this->assertStringContainsString("'job_retried'", (string) $eventMigration);

        $queue = file_get_contents(__DIR__ . '/../../cli/process_social_media_queue.php');
        $metrics = file_get_contents(__DIR__ . '/../../cli/process_social_media_metrics.php');
        $initiate = file_get_contents(__DIR__ . '/../../api/social/oauth/initiate.php');
        $callback = file_get_contents(__DIR__ . '/../../api/social/oauth/callback.php');
        $this->assertStringContainsString("status = 'installed'", (string) $queue);
        $this->assertStringContainsString('processDueJobs', (string) $queue);
        $this->assertStringContainsString('syncMetrics', (string) $metrics);
        $this->assertStringContainsString('Authorization::canAny', (string) $initiate);
        $this->assertStringContainsString('beginOAuth', (string) $initiate);
        $this->assertStringContainsString('completeOAuth', (string) $callback);
    }

    public function testOperatorWorkspaceAndMarketplaceSetupExposeTheControlledWorkflow(): void
    {
        $page = file_get_contents(__DIR__ . '/../../public/social_media.php');
        $setup = file_get_contents(__DIR__ . '/../../views/partials/marketplace_plugin_setup.php');
        $workspaceSkills = file_get_contents(__DIR__ . '/../../public/workspace_skills.php');

        $this->assertStringContainsString('FEATURE_SOCIAL_MEDIA', (string) $page);
        $this->assertStringContainsString('Security::validateCSRF', (string) $page);
        $this->assertStringContainsString('generate_variants', (string) $page);
        $this->assertStringContainsString('Content Generation key', (string) $page);
        $this->assertStringContainsString('social-content-brief', (string) $page);
        $this->assertStringContainsString('create_publish_jobs', (string) $page);
        $this->assertStringContainsString('Prepared from Campaign Kit', (string) $page);
        $this->assertStringContainsString('name="content_item_id"', (string) $page);
        $this->assertStringContainsString('name="distribution_post_id"', (string) $page);
        $this->assertStringContainsString('name="utm_link_id"', (string) $page);
        $this->assertStringContainsString('approve_job', (string) $page);
        $this->assertStringContainsString('retry_job', (string) $page);
        $this->assertStringContainsString('data-social-workspace', (string) $page);
        $this->assertStringContainsString('assets/css/social-media.css', (string) $page);
        $this->assertStringContainsString('role="tabpanel"', (string) $page);
        $this->assertStringContainsString('aria-controls="social-panel-', (string) $page);
        $this->assertStringContainsString("event.key === 'ArrowRight'", (string) $page);
        $this->assertStringContainsString('data-social-publish-now', (string) $page);
        $this->assertStringNotContainsString('workspace_id = ? OR workspace_id IS NULL', (string) $page);

        $this->assertStringContainsString('marketplaceRenderSocialMediaSetup', (string) $setup);
        $this->assertStringContainsString("'connections' => 'Connections'", (string) $setup);
        $this->assertStringContainsString('save_social_media_brand_setup', (string) $workspaceSkills);
        $this->assertStringContainsString('save_social_media_publishing_setup', (string) $workspaceSkills);
        $this->assertStringContainsString('verify_social_media_account:', (string) $workspaceSkills);
        $this->assertStringContainsString('disconnect_social_media_account:', (string) $workspaceSkills);
        $this->assertStringContainsString('ai_credential_scope', (string) $workspaceSkills);
    }

    public function testSetupRendererShowsConnectionAndPublishingControls(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderSocialMediaSetup([
            'active_tab' => 'connections',
            'csrf' => 'test-token',
            'installed' => true,
            'can_manage' => true,
            'settings' => ['enabled' => 1, 'approval_required' => 1, 'metrics_sync_enabled' => 1],
            'accounts' => [[
                'id' => 7,
                'account_name' => 'Acme Company',
                'channel' => 'linkedin',
                'status' => 'active',
                'last_verified_at' => '2026-07-15 10:00:00',
            ]],
            'summary' => ['active_accounts' => 1],
            'readiness' => ['ready' => true],
            'platform' => [
                'meta' => ['configured' => true, 'label' => 'Facebook & Instagram', 'message' => 'Ready'],
                'linkedin' => ['configured' => true, 'label' => 'LinkedIn', 'message' => 'Ready'],
            ],
            'events' => [],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Connect Facebook &amp; Instagram', $html);
        $this->assertStringContainsString('Connect LinkedIn', $html);
        $this->assertStringContainsString('Acme Company', $html);
        $this->assertStringContainsString('verify_social_media_account:7', $html);
        $this->assertStringContainsString('disconnect_social_media_account:7', $html);
    }

    public function testSetupRendererUsesARealDisabledControlForUnavailableProviders(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderSocialMediaSetup([
            'active_tab' => 'connections',
            'csrf' => 'test-token',
            'installed' => true,
            'can_manage' => true,
            'settings' => [],
            'accounts' => [],
            'summary' => [],
            'readiness' => [],
            'platform' => [
                'meta' => ['configured' => true, 'label' => 'Facebook & Instagram', 'message' => 'Ready'],
                'linkedin' => ['configured' => false, 'label' => 'LinkedIn', 'message' => 'Credentials missing'],
            ],
            'events' => [],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('href="/api/social/oauth/initiate.php?provider=meta', $html);
        $this->assertStringContainsString('<button class="btn-premium-primary" type="button" disabled>Connect LinkedIn</button>', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
    }

    public function testAiApiSetupRendererKeepsGeneralAndContentCredentialsSeparate(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderAiApiSetup([
            'active_tab' => 'provider',
            'csrf' => 'test-token',
            'installed' => true,
            'can_manage' => true,
            'is_default_workspace' => false,
            'workspace_configs' => [
                'general' => [
                    'provider_key' => 'openai',
                    'enabled' => true,
                    'api_key_present' => true,
                    'api_key_fingerprint' => 'generalfingerprint',
                    'model' => 'gpt-4o-mini',
                ],
                'content_generation' => [
                    'provider_key' => 'openai',
                    'enabled' => true,
                    'api_key_present' => false,
                    'model' => 'gpt-5-mini',
                ],
            ],
            'default_configs' => [],
            'resolved_by_scope' => [
                'general' => ['available' => true, 'source' => 'workspace_api'],
                'content_generation' => ['available' => false, 'blocked_reason' => 'content_generation_key_required'],
            ],
            'usage_today' => [],
            'readiness' => [],
            'events' => [],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('General AI', $html);
        $this->assertStringContainsString('Content Generation', $html);
        $this->assertStringContainsString('value="general"', $html);
        $this->assertStringContainsString('value="content_generation"', $html);
        $this->assertSame(2, substr_count($html, 'name="ai_api_key"'));
        $this->assertStringContainsString('generalfingerprint', $html);
        $this->assertStringNotContainsString('general-workspace-key', $html);
    }
}
