<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Auth;
use CRM\Authorization;
use CRM\Session;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\MobileTokenAuthService;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceMarketplaceRecommendationControlService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceAIProviderConfigService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\StartupJourneyService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Forms;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\MeetingBotConfig;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Modules\Products;
use CRM\Modules\UserPreferences;
use CRM\Modules\UserStrategyProfile;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class WorkspaceSkillsTest extends DatabaseTestCase
{
    use EndpointHarness;

    /**
     * @var string[]
     */
    private array $tempUploadRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempUploadRoots as $root) {
            $this->removeDirectory($root);
        }
        $this->tempUploadRoots = [];

        parent::tearDown();
    }

    public function testCatalogSeedsInitialSkillsIdempotently(): void
    {
        $catalog = new WorkspaceSkillCatalogService();
        $catalog->syncDefinitions();
        $catalog->syncDefinitions();

        $lean = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ?",
            [WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $marketer = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ?",
            [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER]
        );
        $email = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT]
        );
        $emailChannel = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_EMAIL]
        );
        $communicationSetup = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_COMMUNICATION_SETUP]
        );
        $hrAnalyticsSetup = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        $whatsapp = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $whatsappChannel = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP]
        );
        $sms = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );
        $voiceCallCenter = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER]
        );
        $calendarMeetings = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS]
        );
        $aiApi = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'plugin'",
            [WorkspaceSkillCatalogService::PLUGIN_AI_API]
        );
        $aiCoach = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_definitions WHERE skill_key = ? AND module_type = 'skill'",
            [WorkspaceSkillCatalogService::SKILL_AI_COACH]
        );

        $this->assertSame(1, (int) ($lean['c'] ?? 0));
        $this->assertSame(1, (int) ($marketer['c'] ?? 0));
        $this->assertSame(0, (int) ($communicationSetup['c'] ?? 0));
        $this->assertSame(1, (int) ($hrAnalyticsSetup['c'] ?? 0));
        $this->assertSame(1, (int) ($emailChannel['c'] ?? 0));
        $this->assertSame(1, (int) ($email['c'] ?? 0));
        $this->assertSame(1, (int) ($whatsappChannel['c'] ?? 0));
        $this->assertSame(1, (int) ($whatsapp['c'] ?? 0));
        $this->assertSame(1, (int) ($sms['c'] ?? 0));
        $this->assertSame(1, (int) ($voiceCallCenter['c'] ?? 0));
        $this->assertSame(1, (int) ($calendarMeetings['c'] ?? 0));
        $this->assertSame(1, (int) ($aiApi['c'] ?? 0));
        $this->assertSame(1, (int) ($aiCoach['c'] ?? 0));
    }

    public function testInstallIsWorkspaceScopedAndIdempotent(): void
    {
        $first = $this->seedWorkspace('skills-one');
        $second = $this->seedWorkspace('skills-two');
        $this->activateSession($first, 'owner');
        $this->completeFinanceSetup($first);

        $installer = new WorkspaceSkillInstallService();
        $installer->install((int) $first['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $first['user_id']);
        $installer->install((int) $first['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $first['user_id']);

        $this->assertTrue($installer->isInstalled((int) $first['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
        $this->assertFalse($installer->isInstalled((int) $second['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));

        $count = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ?",
            [(int) $first['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $this->assertSame(1, (int) ($count['c'] ?? 0));
    }

    public function testCalendarMeetingsSetupOwnerCanSaveWorkspaceSettings(): void
    {
        $seed = $this->seedWorkspace('calendar-meetings-owner');
        $this->activateSession($seed, 'owner');
        $this->installCalendarMeetings($seed);
        Database::execute(
            "INSERT INTO calendar_integrations (
                workspace_id, user_id, provider, calendar_id, calendar_name, sync_enabled, sync_direction, last_sync_at
             ) VALUES (?, ?, 'google', 'primary', 'Founder Calendar', 1, 'both', NOW())",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $calendarIntegrationId = (string) Database::lastInsertId();

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'skill_action' => 'save_calendar_meetings_setup',
                'meeting_bot_enabled' => '1',
                'meeting_bot_provider' => 'google_meet',
                'meeting_bot_display_name' => 'Workspace Meeting Assistant',
                'meeting_bot_join_policy' => 'auto_join_eligible',
                'meeting_bot_recording_mode' => 'bot_requested',
                'meeting_bot_transcript_required' => '1',
                'meeting_bot_auto_apply_mode' => 'auto_safe',
                'meeting_bot_consent_notice' => 'Workspace consent notice.',
                'meeting_bot_google_calendar_integration_id' => $calendarIntegrationId,
                'meeting_bot_google_workspace_client_id' => 'google-client',
                'meeting_bot_google_workspace_client_secret' => 'google-secret',
                'meeting_bot_google_workspace_project_id' => 'google-project',
                'meeting_bot_google_transcript_mode' => 'workspace_export',
                'meeting_bot_webhook_secret' => 'workspace-hook',
                'meeting_bot_scheduling_secret' => 'workspace-schedule',
                'meeting_note_taker_enabled' => '1',
                'meeting_note_taker_auto_apply_mode' => 'full_auto',
                'meeting_note_taker_contact_updates_additive_only' => '1',
                'meeting_note_taker_task_auto_create_enabled' => '1',
                'meeting_note_taker_deal_stage_auto_move_enabled' => '1',
                'meeting_note_taker_deal_stage_min_confidence' => '0.86',
                'meeting_note_taker_contact_update_min_confidence' => '0.72',
                'meeting_note_taker_max_context_entries' => '14',
                'meeting_note_taker_allowed_contact_fields' => ['job_title', 'timezone'],
                'meeting_note_taker_ingest_secret' => 'workspace-notes',
            ],
        ]);

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Calendar &amp; Meetings setup saved.', (string) ($post['body'] ?? ''));

        $bot = (new MeetingBotConfig())->get((int) $seed['workspace_id']);
        $notes = (new MeetingNoteTakerConfig())->get((int) $seed['workspace_id']);
        $this->assertSame('Workspace Meeting Assistant', (string) ($bot['bot_display_name'] ?? ''));
        $this->assertSame('google_meet', (string) ($bot['provider'] ?? ''));
        $this->assertSame($calendarIntegrationId, (string) ($bot['google_calendar_integration_id'] ?? ''));
        $this->assertSame('workspace-hook', (string) ($bot['webhook_secret'] ?? ''));
        $this->assertTrue((bool) ($notes['enabled'] ?? false));
        $this->assertSame('full_auto', (string) ($notes['auto_apply_mode'] ?? ''));
        $this->assertSame(['job_title', 'timezone'], (array) ($notes['allowed_contact_fields'] ?? []));
        $this->assertSame('workspace-notes', (string) ($notes['ingest_secret'] ?? ''));
    }

    public function testCalendarMeetingsViewerCanLoadReadOnlyButCannotPostChanges(): void
    {
        $seed = $this->seedWorkspace('calendar-meetings-viewer');
        $this->activateSession($seed, 'owner');
        $this->installCalendarMeetings($seed);
        (new MeetingBotConfig())->save([
            'enabled' => true,
            'bot_display_name' => 'Owner Bot',
            'webhook_secret' => 'owner-only-hook',
        ], (int) $seed['user_id'], (int) $seed['workspace_id']);

        $viewer = $this->addWorkspaceViewer($seed, 'calendar.viewer@example.test');
        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewer, 'viewer'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'setup_tab' => 'bot',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Meeting Bot', $body);
        $this->assertStringContainsString('disabled', $body);
        $this->assertStringContainsString('saved', $body);
        $this->assertStringNotContainsString('owner-only-hook', $body);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewer, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'skill_action' => 'save_calendar_meetings_setup',
                'meeting_bot_provider' => 'zoom',
                'meeting_bot_display_name' => 'Viewer Edited Bot',
            ],
        ]);
        $this->assertContains((int) ($post['status'] ?? 0), [200, 403]);
        $this->assertSame('Owner Bot', (string) ((new MeetingBotConfig())->get((int) $seed['workspace_id'])['bot_display_name'] ?? ''));
    }

    public function testLegacyLeanCanvasBackfillRespectsExplicitClarityJourneyRemoval(): void
    {
        $seed = $this->seedWorkspace('legacy-clarity-removal');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeFinanceSetup($seed);

        $strategy = new UserStrategyProfile();
        $strategy->save((int) $seed['user_id'], [
            'lean_problem' => 'Leads are scattered across channels.',
        ]);

        $installer = new WorkspaceSkillInstallService();
        $installer->installLegacyLeanCanvasIfPresent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            $strategy->get((int) $seed['user_id']) ?: []
        );
        $this->assertTrue($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                'skill_action' => 'uninstall',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Clarity Journey removed from this workspace.', (string) ($post['body'] ?? ''));
        $this->assertFalse($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertFalse($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
    }

    public function testMarketplaceAccessAllowsClarityJourneyWithoutFinanceSetup(): void
    {
        $seed = $this->seedWorkspace('clarity-finance-recommended');
        $this->activateSession($seed, 'owner');

        $installer = new WorkspaceSkillInstallService();
        $accessBeforeInstall = (new WorkspaceMarketplaceAccessService())->accessForModule(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
        );

        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL, (string) ($accessBeforeInstall['state'] ?? ''));
        $this->assertTrue((bool) ($accessBeforeInstall['can_install'] ?? false));
        $this->assertTrue((bool) ($accessBeforeInstall['can_open'] ?? false));
        $this->assertSame('', (string) ($accessBeforeInstall['root_blocker_skill_key'] ?? ''));

        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);

        $accessAfterInstall = (new WorkspaceMarketplaceAccessService())->accessForModule(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
        );

        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP, (string) ($accessAfterInstall['state'] ?? ''));
        $this->assertFalse((bool) ($accessAfterInstall['is_locked'] ?? true));
        $this->assertTrue((bool) ($accessAfterInstall['can_open'] ?? false));
        $this->assertFalse((bool) ($accessAfterInstall['can_run'] ?? true));
        $this->assertSame('', (string) ($accessAfterInstall['root_blocker_skill_key'] ?? ''));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('marketplace-module-page', $body);
        $this->assertStringContainsString('Finance context recommended', $body);
        $this->assertStringNotContainsString('Installed - locked', $body);
        $this->assertStringNotContainsString('Finance setup complete', $body);
    }

    public function testDesignRuntimeCompletesSetupAndEnforcesReadOnlyFormControls(): void
    {
        $seed = $this->seedWorkspace('design-runtime');
        $this->grantRolePermissions('owner', ['marketing.read', 'marketing.write']);
        Authorization::resetCaches();
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $installer = new WorkspaceSkillInstallService();
        $installer->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            (int) $seed['user_id']
        );

        $access = (new WorkspaceMarketplaceAccessService())->accessForModule(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_DESIGN
        );
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP, (string) ($access['state'] ?? ''));
        $this->assertTrue((bool) ($access['can_run'] ?? false), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($access['readiness']['ready'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertContains('Landing page or lead capture form', (array) ($access['readiness']['blockers'] ?? []));

        $runtime = $this->runWebEndpoint('public/design.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $runtimeBody = (string) ($runtime['body'] ?? '');
        $this->assertSame(200, (int) ($runtime['status'] ?? 0), json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('data-design-workspace', $runtimeBody);
        $this->assertStringContainsString('Create landing page', $runtimeBody);
        $this->assertStringContainsString('Create lead form', $runtimeBody);

        (new Forms())->create([
            'name' => 'Design readiness form',
            'fields' => [
                ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true],
            ],
            'created_by' => (int) $seed['user_id'],
        ]);

        $readyAccess = (new WorkspaceMarketplaceAccessService())->accessForModule(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_DESIGN
        );
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_READY, (string) ($readyAccess['state'] ?? ''), json_encode($readyAccess, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($readyAccess['readiness']['ready'] ?? false), json_encode($readyAccess, JSON_PRETTY_PRINT));

        $this->revokeRolePermissions('owner', ['marketing.write']);
        Authorization::resetCaches();

        $readOnlyRuntime = $this->runWebEndpoint('public/design.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $readOnlyBody = (string) ($readOnlyRuntime['body'] ?? '');
        $this->assertSame(200, (int) ($readOnlyRuntime['status'] ?? 0), (string) ($readOnlyRuntime['stderr'] ?? ''));
        $this->assertStringNotContainsString('href="form_edit.php', $readOnlyBody);
        $this->assertStringNotContainsString('href="marketing_landing_page_edit.php', $readOnlyBody);

        $forms = $this->runWebEndpoint('public/forms.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $formsBody = (string) ($forms['body'] ?? '');
        $this->assertSame(200, (int) ($forms['status'] ?? 0), (string) ($forms['stderr'] ?? ''));
        $this->assertStringNotContainsString('/form_edit.php', $formsBody);
        $this->assertStringNotContainsString('/form_delete.php', $formsBody);

        foreach (['public/form_edit.php', 'public/form_delete.php'] as $endpoint) {
            $blocked = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'owner'), ['method' => 'GET']);
            $this->assertSame(302, (int) ($blocked['status'] ?? 0), $endpoint . ': ' . (string) ($blocked['stderr'] ?? ''));
        }

        $assetUpload = $this->runWebEndpoint('public/form_asset_upload.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => ['csrf_token' => 'csrf-workspace-skills'],
        ]);
        $this->assertSame(403, (int) ($assetUpload['status'] ?? 0), (string) ($assetUpload['stderr'] ?? ''));
        $this->assertStringContainsString('permission to update form assets', (string) ($assetUpload['body'] ?? ''));
    }

    public function testMarketplaceServiceRequestMemoizationAvoidsRepeatedQueries(): void
    {
        $seed = $this->seedWorkspace('marketplace-request-memoization');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $access = new WorkspaceMarketplaceAccessService($catalog, $installer);

        $firstInstalled = $installer->installedForWorkspace($workspaceId);
        $queriesBeforeRepeat = Database::getQueryCount();
        $secondInstalled = $installer->installedForWorkspace($workspaceId);
        $this->assertSame($firstInstalled, $secondInstalled);
        $this->assertSame($queriesBeforeRepeat, Database::getQueryCount());

        $installer->buildReadinessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_FINANCE);
        $queriesBeforeReadinessRepeat = Database::getQueryCount();
        $installer->buildReadinessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_FINANCE);
        $this->assertSame($queriesBeforeReadinessRepeat, Database::getQueryCount());

        $module = $catalog->findForWorkspace(WorkspaceSkillCatalogService::PLUGIN_FINANCE, $workspaceId, true);
        $this->assertIsArray($module);
        $access->accessForDefinition($workspaceId, $userId, $module);
        $queriesBeforeAccessRepeat = Database::getQueryCount();
        $access->accessForDefinition($workspaceId, $userId, $module);
        $this->assertSame($queriesBeforeAccessRepeat, Database::getQueryCount());
    }

    public function testMarketplacePageStaysWithinQueryGuardBudget(): void
    {
        $seed = $this->seedWorkspace('marketplace-query-budget');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        $catalog = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'env' => ['DB_QUERY_GUARD_LIMIT' => '250'],
        ]);
        $this->assertSame(0, (int) ($catalog['exit_code'] ?? 1), (string) ($catalog['stderr'] ?? ''));
        $this->assertSame(200, (int) ($catalog['status'] ?? 0), (string) ($catalog['stderr'] ?? ''));
        $catalogBody = (string) ($catalog['body'] ?? '');
        $this->assertStringContainsString('data-marketplace-async-recommendations', $catalogBody);
        $this->assertStringContainsString('data-marketplace-count="installed"', $catalogBody);
        $this->assertStringContainsString('data-marketplace-card-readiness', $catalogBody);
        $this->assertStringContainsString('class="marketplace-side-rail"', $catalogBody);
        $this->assertStringContainsString('Recommended next', $catalogBody);
        $this->assertLessThan(
            strpos($catalogBody, 'data-marketplace-async-recommendations'),
            strpos($catalogBody, 'id="marketplace-card-grid"')
        );

        $module = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE],
            'env' => ['DB_QUERY_GUARD_LIMIT' => '450'],
        ]);
        $this->assertSame(0, (int) ($module['exit_code'] ?? 1), (string) ($module['stderr'] ?? ''));
        $this->assertSame(200, (int) ($module['status'] ?? 0), (string) ($module['stderr'] ?? ''));
        $moduleBody = (string) ($module['body'] ?? '');
        $this->assertStringContainsString('marketplace-module-page', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-overview-status', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-setup-shell', $moduleBody);
        $this->assertStringContainsString('full_setup=1', $moduleBody);
    }

    public function testMarketplacePageCreatesOneUnreadNextActionNotificationPerActionLink(): void
    {
        $seed = $this->seedWorkspace('marketplace-next-action-notification');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );

        $first = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($first['status'] ?? 0), (string) ($first['stderr'] ?? ''));

        $notifications = Database::query(
            "SELECT id, title, link, severity
             FROM notifications
             WHERE workspace_id = ? AND user_id = ? AND type = 'marketplace_next_action' AND is_read = 0
             ORDER BY id ASC",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $this->assertCount(1, $notifications);
        $this->assertSame('Finish Marketplace setup', (string) ($notifications[0]['title'] ?? ''));
        $this->assertSame('info', (string) ($notifications[0]['severity'] ?? ''));
        $firstLink = (string) ($notifications[0]['link'] ?? '');
        $this->assertNotSame('', $firstLink);

        $second = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($second['status'] ?? 0), (string) ($second['stderr'] ?? ''));
        $secondCount = Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications WHERE workspace_id = ? AND user_id = ? AND type = 'marketplace_next_action' AND is_read = 0 AND link = ?",
            [(int) $seed['workspace_id'], (int) $seed['user_id'], $firstLink]
        );
        $this->assertSame(1, (int) ($secondCount['c'] ?? 0));

        $this->completeFinanceSetup($seed);
        $third = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($third['status'] ?? 0), (string) ($third['stderr'] ?? ''));

        $changedActions = Database::query(
            "SELECT DISTINCT link, title
             FROM notifications
             WHERE workspace_id = ? AND user_id = ? AND type = 'marketplace_next_action' AND is_read = 0
             ORDER BY link ASC",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $changedLinks = array_column($changedActions, 'link');
        $this->assertGreaterThanOrEqual(2, count(array_unique($changedLinks)));
        $this->assertContains($firstLink, $changedLinks);
    }

    public function testFinanceSetupOwnersHideDrawsAndRedundantNotes(): void
    {
        $seed = $this->seedWorkspace('finance-setup-minimal-owner-fields');
        $this->activateSession($seed, 'owner');

        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE, 'setup_tab' => 'owners'],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Share %', $body);
        $this->assertStringContainsString('Capital put in', $body);
        $this->assertStringContainsString('Owner value', $body);
        $this->assertStringContainsString('name="assets_none"', $body);
        $this->assertStringContainsString('name="receivables_none"', $body);
        $this->assertStringContainsString('name="liabilities_none"', $body);
        $this->assertStringContainsString('I have none', $body);
        $this->assertStringContainsString('data-finance-autosave="1"', $body);
        $this->assertStringContainsString('data-finance-autosave-status', $body);
        $this->assertStringContainsString('name="skill_action" value="save_finance_setup"', $body);
        $this->assertStringContainsString('name="start_confirmed"', $body);
        $this->assertStringContainsString('finance-t-account', $body);
        $this->assertStringContainsString('Liabilities + Owner value', $body);
        $this->assertStringContainsString('Total assets', $body);
        $this->assertStringContainsString('Total claims', $body);
        $this->assertStringContainsString('No assets', $body);
        $this->assertStringContainsString('No owed to us', $body);
        $this->assertStringContainsString('No owed by us', $body);
        $this->assertStringNotContainsString('Draws taken', $body);
        $this->assertStringNotContainsString('[opening_owner_draws]', $body);
        $this->assertStringNotContainsString('[notes]', $body);
        $this->assertStringNotContainsString('[note]', $body);
    }

    public function testFinanceSetupReadyReviewShowsOpenFinanceOnly(): void
    {
        $seed = $this->seedWorkspace('finance-setup-ready-actions');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE, 'setup_tab' => 'review'],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('All setup tabs saved.', $body);
        $this->assertStringContainsString('class="btn-premium-primary" href="finance.php"', $body);
        $this->assertStringNotContainsString('>Refresh</button>', $body);
    }

    public function testFinanceSetupAutosavesOptionalNoneChoicesBeforeReview(): void
    {
        $seed = $this->seedWorkspace('finance-setup-autosave-none-review');
        $this->activateSession($seed, 'owner');

        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );

        $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'start', [
            'opening_date' => '2026-06-01',
            'currency' => 'USD',
            'notes' => 'Autosave setup test',
        ]));
        $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'bank', [
            'currency' => 'USD',
            'bank_accounts' => [
                ['name' => 'Main Bank', 'account_type' => 'bank', 'opening_balance' => '1000.00', 'is_default' => '1'],
            ],
        ]));
        $assets = $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'assets', [
            'assets_none' => '1',
            'assets' => [
                ['name' => 'Should be ignored', 'asset_type' => 'equipment', 'value' => '99.00'],
            ],
        ]));
        $receivables = $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'receivables', [
            'receivables_none' => '1',
            'receivables' => [
                ['from_name' => 'Should be ignored', 'amount' => '88.00'],
            ],
        ]));
        $liabilities = $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'liabilities', [
            'liabilities_none' => '1',
            'liabilities' => [
                ['name' => 'Should be ignored', 'liability_type' => 'loan', 'amount' => '77.00'],
            ],
        ]));
        $owners = $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'owners', [
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                ],
            ],
        ]));

        foreach ([$assets, $receivables, $liabilities, $owners] as $payload) {
            $this->assertTrue((bool) ($payload['success'] ?? false));
            $this->assertArrayHasKey('finance_setup', $payload);
        }

        $summary = (array) ($owners['finance_setup']['summary'] ?? []);
        $this->assertSame([], (array) ($summary['missing_tabs'] ?? ['missing']));
        $this->assertSame(0, (int) ($summary['row_counts']['assets'] ?? -1));
        $this->assertSame(0, (int) ($summary['row_counts']['receivables'] ?? -1));
        $this->assertSame(0, (int) ($summary['row_counts']['liabilities'] ?? -1));
        $this->assertSame(1000.0, (float) ($summary['bank_total'] ?? 0));
        $this->assertSame(1000.0, (float) ($summary['business_value'] ?? 0));
        $this->assertFalse((bool) ($owners['finance_setup']['status']['ready'] ?? true));

        $review = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE, 'setup_tab' => 'review'],
        ]);
        $reviewBody = (string) ($review['body'] ?? '');
        $this->assertSame(200, (int) ($review['status'] ?? 0), (string) ($review['stderr'] ?? ''));
        $this->assertStringContainsString('All setup tabs saved.', $reviewBody);
        $this->assertStringNotContainsString('Check none.', $reviewBody);
        $this->assertStringNotContainsString('Finish: assets', $reviewBody);

        $finish = $this->decodeJsonResponse($this->postFinanceSetupAutosave($seed, 'review', []));
        $this->assertTrue((bool) ($finish['success'] ?? false));
        $this->assertTrue((bool) ($finish['finance_setup']['status']['ready'] ?? false));
        $this->assertSame([], (array) ($finish['finance_setup']['status']['opening']['missing_tabs'] ?? ['missing']));
    }

    public function testMarketplaceCatalogSortsByReadinessQueue(): void
    {
        $seed = $this->seedWorkspace('marketplace-readiness-sort');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);

        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );
        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
            (int) $seed['user_id']
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-sort-bucket=', $body);
        $this->assertStringContainsString('data-marketplace-recommendation-rank=', $body);
        $this->assertStringContainsString('data-marketplace-async-recommendations', $body);
        $this->assertStringContainsString('class="marketplace-dashboard-body"', $body);
        $this->assertStringContainsString('class="marketplace-side-rail"', $body);
        $this->assertStringNotContainsString('<article class="marketplace-dashboard-next">', $body);
        $this->assertLessThan(
            strpos($body, 'data-marketplace-async-recommendations'),
            strpos($body, 'id="marketplace-card-grid"')
        );
        $this->assertStringContainsString('data-marketplace-count="installed"', $body);
        $this->assertStringContainsString('data-marketplace-card-readiness', $body);
        $this->assertStringContainsString('Recommended next', $body);
        $aiCoachCard = $this->marketplaceCardHtml($body, WorkspaceSkillCatalogService::SKILL_AI_COACH);
        $this->assertNotSame('', $aiCoachCard);
        $this->assertStringNotContainsString('Requires Clarity Journey', $aiCoachCard);
        $this->assertStringContainsString('data-marketplace-card-readiness', $aiCoachCard);

        $status = $this->runWebEndpoint('api/workspace/marketplace_catalog_status.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $statusData = $this->decodeJsonResponse($status);
        $this->assertSame(200, (int) ($status['status'] ?? 0), (string) ($status['stderr'] ?? ''));
        $this->assertSame('Requires Clarity Journey', (string) ($statusData['modules'][WorkspaceSkillCatalogService::SKILL_AI_COACH]['readiness_text'] ?? ''));

        preg_match_all('/<article class="marketplace-card"(?P<attrs>[^>]*)>.*?data-marketplace-skill-key="(?P<key>[^"]+)"/s', $body, $matches, PREG_SET_ORDER);
        $cards = [];
        foreach ($matches as $position => $match) {
            $attrs = (string) ($match['attrs'] ?? '');
            preg_match('/data-marketplace-sort-bucket="(?P<bucket>\d+)"/', $attrs, $bucketMatch);
            preg_match('/data-marketplace-recommendation-rank="(?P<rank>\d+)"/', $attrs, $rankMatch);
            preg_match('/data-marketplace-type="(?P<type>[^"]+)"/', $attrs, $typeMatch);
            $cards[] = [
                'key' => (string) ($match['key'] ?? ''),
                'type' => (string) ($typeMatch['type'] ?? ''),
                'position' => $position,
                'bucket' => (int) ($bucketMatch['bucket'] ?? 0),
                'rank' => (int) ($rankMatch['rank'] ?? 0),
            ];
        }
        $this->assertNotEmpty($cards);

        $byKey = [];
        foreach ($cards as $card) {
            $byKey[$card['key']] = $card;
        }

        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_FINANCE, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_EMAIL, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $byKey);
        $this->assertArrayNotHasKey(WorkspaceSkillCatalogService::PLUGIN_COMMUNICATION_SETUP, $byKey);

        $this->assertGreaterThanOrEqual(30, $byKey[WorkspaceSkillCatalogService::PLUGIN_FINANCE]['bucket']);
        $this->assertSame(20, $byKey[WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]['bucket']);
        $this->assertSame(20, $byKey[WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]['bucket']);
        $this->assertContains($byKey[WorkspaceSkillCatalogService::PLUGIN_EMAIL]['bucket'], [10, 30, 40]);
        $this->assertContains($byKey[WorkspaceSkillCatalogService::PLUGIN_WHATSAPP]['bucket'], [10, 30, 40]);

        $buckets = array_map(static fn(array $card): int => (int) $card['bucket'], $cards);
        $sortedBuckets = $buckets;
        sort($sortedBuckets);
        $this->assertSame($sortedBuckets, $buckets);

        $rankedCards = array_values(array_filter($cards, static fn(array $card): bool => (int) $card['rank'] > 0));
        $this->assertSame([], $rankedCards);

        $this->assertNotEmpty(array_filter($cards, static fn(array $card): bool => (int) $card['bucket'] < 40));

        $generalPlugins = array_values(array_filter($cards, static fn(array $card): bool => (int) $card['bucket'] === 30 && $card['type'] === 'plugin'));
        $generalSkills = array_values(array_filter($cards, static fn(array $card): bool => (int) $card['bucket'] === 30 && $card['type'] === 'skill'));
        $this->assertNotEmpty($generalPlugins);
        $this->assertNotEmpty($generalSkills);
        $this->assertLessThan((int) $generalSkills[0]['position'], (int) $generalPlugins[0]['position']);
    }

    public function testLockedMarketplaceInstallDoesNotCreateInstallRow(): void
    {
        $seed = $this->seedWorkspace('locked-install-rejected');
        $this->activateSession($seed, 'owner');

        $installer = new WorkspaceSkillInstallService();
        try {
            $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
            $this->fail('Locked Clarity Journey install should have been rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Finance', $e->getMessage());
        }

        $this->assertFalse($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
        $count = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ?",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $this->assertSame(0, (int) ($count['c'] ?? 0));
    }

    public function testSplitCommunicationModulesAreInstalledAndLegacyRedirectsToEmail(): void
    {
        $seed = $this->seedWorkspace('communication-setup-required');
        $this->activateSession($seed, 'owner');

        $installer = new WorkspaceSkillInstallService();
        $this->assertTrue($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL));
        $this->assertTrue($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP));
        $this->assertFalse($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_COMMUNICATION_SETUP));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_COMMUNICATION_SETUP],
        ]);
        $this->assertSame(302, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $workspaceSkillsSource = (string) file_get_contents(__DIR__ . '/../../public/workspace_skills.php');
        $this->assertStringContainsString('&setup_required=communication', $workspaceSkillsSource);
        $this->assertStringContainsString('WorkspaceSkillCatalogService::PLUGIN_EMAIL', $workspaceSkillsSource);

        $this->expectException(\RuntimeException::class);
        $installer->uninstall((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL, (int) $seed['user_id']);
    }

    public function testSettingsCommunicationTabsRedirectToSplitMarketplaceModules(): void
    {
        $seed = $this->seedWorkspace('settings-communication-split-redirects');
        $this->activateSession($seed, 'owner');
        $settingsSource = (string) file_get_contents(__DIR__ . '/../../public/settings.php');

        foreach ([
            'email' => 'workspace_skills.php?module=email',
            'whatsapp' => 'workspace_skills.php?module=whatsapp',
            'email_assistant' => 'workspace_skills.php?module=email_assistant',
        ] as $tab => $expectedLocation) {
            $response = $this->runWebEndpoint('public/settings.php', $this->webSession($seed, 'owner'), [
                'method' => 'GET',
                'query' => ['tab' => $tab],
            ]);

            $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            $this->assertStringContainsString($expectedLocation, $settingsSource);
        }
    }

    public function testAssistantModulesDependOnTheirChannelModules(): void
    {
        $seed = $this->seedWorkspace('assistant-channel-dependencies');
        $this->activateSession($seed, 'owner');

        $catalog = new WorkspaceSkillCatalogService();
        $accessService = new WorkspaceMarketplaceAccessService();
        $emailAssistant = $catalog->findForWorkspace(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, (int) $seed['workspace_id'], true);
        $whatsAppAssistant = $catalog->findForWorkspace(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (int) $seed['workspace_id'], true);

        $this->assertIsArray($emailAssistant);
        $this->assertIsArray($whatsAppAssistant);

        $emailAccess = $accessService->accessForDefinition((int) $seed['workspace_id'], (int) $seed['user_id'], $emailAssistant);
        $whatsAppAccess = $accessService->accessForDefinition((int) $seed['workspace_id'], (int) $seed['user_id'], $whatsAppAssistant);

        $this->assertTrue((bool) ($emailAccess['is_locked'] ?? false));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL, (string) ($emailAccess['root_blocker_skill_key'] ?? ''));
        $this->assertStringContainsString('Email', (string) ($emailAccess['message'] ?? ''));

        $this->assertTrue((bool) ($whatsAppAccess['is_locked'] ?? false));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, (string) ($whatsAppAccess['root_blocker_skill_key'] ?? ''));
        $this->assertStringContainsString('WhatsApp', (string) ($whatsAppAccess['message'] ?? ''));

        $sessionServiceSource = (string) file_get_contents(__DIR__ . '/../../services/WhatsAppAssistantSessionService.php');
        $this->assertStringContainsString('workspace_skills.php?module=whatsapp_assistant&setup_tab=identity#setup', $sessionServiceSource);
        $this->assertStringNotContainsString('workspace_skills.php?module=email_assistant&setup_tab=overview#setup', $sessionServiceSource);
    }

    public function testHrAnalyticsSetupIsInstalledAndCannotBeRemoved(): void
    {
        $seed = $this->seedWorkspace('hr-analytics-setup-required');
        $this->activateSession($seed, 'owner');

        $installer = new WorkspaceSkillInstallService();
        $this->assertTrue($installer->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 'full_setup' => '1'],
        ]);
        $body = (string) ($get['body'] ?? '');
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Organization Intelligence', $body);
        $this->assertStringContainsString('Required core', $body);
        $this->assertStringContainsString('Continue guided setup', $body);
        $this->assertStringNotContainsString('name="hr_threshold_high_performer"', $body);
        $this->assertStringNotContainsString('name="hr_weight_marketing_task_completion"', $body);
        $this->assertStringNotContainsString('name="hr_manager_focus"', $body);
        $this->assertStringNotContainsString('Save Organization Intelligence setup', $body);
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        $hrSetupTabs = marketplaceSetupTabsForModule(WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP);
        $this->assertSame('Guided Setup', $hrSetupTabs['setup'] ?? null);
        $this->assertArrayHasKey('activity', $hrSetupTabs);
        $this->assertArrayNotHasKey('functions', $hrSetupTabs);
        $this->assertArrayNotHasKey('settings', $hrSetupTabs);
        $this->assertArrayNotHasKey('tests', $hrSetupTabs);
        $this->assertArrayNotHasKey('advanced', $hrSetupTabs);

        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');

        $technicalSave = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'skill_action' => 'save_hr_analytics_setup',
                'hr_threshold_high_performer' => '80',
            ],
        ]);
        $this->assertSame(422, (int) ($technicalSave['status'] ?? 0), (string) ($technicalSave['stderr'] ?? ''));
        $technicalPayload = $this->decodeJsonResponse($technicalSave);
        $this->assertFalse((bool) ($technicalPayload['success'] ?? true), json_encode($technicalPayload, JSON_PRETTY_PRINT));
        $this->assertSame('Only superadmins can manage Organization Intelligence technical setup.', (string) ($technicalPayload['message'] ?? ''));

        $create = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'skill_action' => 'create_organization_function',
                'function_name' => 'Customer Success',
                'function_slug' => 'customer_success_' . (int) $seed['workspace_id'],
                'function_description' => 'Owns customer adoption and retention.',
                'function_category' => 'core',
                'function_measurement_strength' => 'partial',
                'function_relevance_status' => 'active',
                'function_relevance_note' => '',
            ],
        ]);
        $this->assertSame(200, (int) ($create['status'] ?? 0), (string) ($create['stderr'] ?? ''));
        $createdPayload = $this->decodeJsonResponse($create);
        $this->assertTrue((bool) ($createdPayload['success'] ?? false), json_encode($createdPayload, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('Business function created', (string) ($createdPayload['message'] ?? ''), json_encode($createdPayload, JSON_PRETTY_PRINT));
        $journeyEvents = Database::query(
            "SELECT skill_key, event_type, label, source
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ?
             ORDER BY id DESC",
            [(int) $seed['workspace_id']]
        );
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 'setup_saved'), json_encode($journeyEvents, JSON_PRETTY_PRINT));
        $runtimeEvent = Database::queryOne(
            "SELECT * FROM workspace_plugin_runtime_events
             WHERE workspace_id = ?
               AND skill_key = ?
               AND capability_key = 'hr_analytics.function_created'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        $this->assertNotEmpty($runtimeEvent);

        $this->expectException(\RuntimeException::class);
        $installer->uninstall((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, (int) $seed['user_id']);
    }

    public function testInvalidSkillAndViewerInstallAreRejected(): void
    {
        $seed = $this->seedWorkspace('skills-reject');
        $installer = new WorkspaceSkillInstallService();

        $this->activateSession($seed, 'owner');
        $this->expectException(\InvalidArgumentException::class);
        $installer->install((int) $seed['workspace_id'], 'missing_skill', (int) $seed['user_id']);
    }

    public function testViewerCannotManageWorkspaceSkills(): void
    {
        $seed = $this->seedWorkspace('skills-viewer');
        $viewerId = (int) Auth::createUser(
            'skills.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Skills',
            'Viewer'
        );
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'skills.viewer@example.test',
        ]);
        $this->activateSession($viewerSeed, 'viewer');

        $this->expectException(\RuntimeException::class);
        (new WorkspaceSkillInstallService())->install(
            (int) $viewerSeed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            (int) $viewerSeed['user_id']
        );
    }

    public function testOwnerCanCreateInstallConfigureAndArchiveCustomSkill(): void
    {
        $seed = $this->seedWorkspace('custom-skill');
        $this->activateSession($seed, 'owner');

        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $custom = $catalog->createCustomSkill((int) $seed['workspace_id'], (int) $seed['user_id'], [
            'label' => 'Growth Coach',
            'summary' => 'Guides retention and growth decisions for this workspace.',
            'category' => 'growth',
            'advice_domains' => ['growth', 'retention'],
            'context_fields' => [
                ['label' => 'Current growth goal', 'required' => true],
                ['label' => 'Primary constraint', 'required' => true],
            ],
            'task_templates' => ['Review the primary growth constraint'],
            'routing_examples' => ['How should we grow retention?'],
        ]);

        $key = (string) ($custom['key'] ?? '');
        $this->assertStringStartsWith('custom_' . (int) $seed['workspace_id'] . '_growth_coach', $key);
        $this->assertSame((int) $seed['workspace_id'], (int) ($custom['owner_workspace_id'] ?? 0));
        $this->assertContains('growth', (array) ($custom['advice_domains'] ?? []));

        $installer->install((int) $seed['workspace_id'], $key, (int) $seed['user_id']);
        $readiness = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], $key);
        $this->assertFalse((bool) ($readiness['ready'] ?? true));

        $installer->saveSkillContext((int) $seed['workspace_id'], $key, (int) $seed['user_id'], [
            'current_growth_goal' => 'Increase retained accounts',
            'primary_constraint' => 'Few expansion conversations',
        ]);
        $contextEvent = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_skill_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'context_saved'",
            [(int) $seed['workspace_id'], $key]
        );
        $this->assertSame(1, (int) ($contextEvent['c'] ?? 0));

        $contracts = $installer->buildInstalledSkillContracts((int) $seed['workspace_id'], (int) $seed['user_id']);
        $contract = array_values(array_filter($contracts, static fn(array $item): bool => (string) ($item['key'] ?? '') === $key))[0] ?? [];
        $this->assertTrue((bool) ($contract['readiness']['ready'] ?? false));
        $this->assertSame('Increase retained accounts', (string) ($contract['context_values']['current_growth_goal'] ?? ''));

        $catalog->archiveCustomSkill((int) $seed['workspace_id'], $key);
        $this->assertNull($catalog->findForWorkspace($key, (int) $seed['workspace_id']));
    }

    public function testCustomSkillsAreVisibleOnlyToOwningWorkspace(): void
    {
        $first = $this->seedWorkspace('custom-skill-one');
        $second = $this->seedWorkspace('custom-skill-two');
        $this->activateSession($first, 'owner');

        $catalog = new WorkspaceSkillCatalogService();
        $custom = $catalog->createCustomSkill((int) $first['workspace_id'], (int) $first['user_id'], [
            'label' => 'Partnership Advisor',
            'summary' => 'Partnership guidance for the first workspace.',
            'advice_domains' => ['partnerships'],
            'context_fields' => [['label' => 'Partner type', 'required' => true]],
        ]);

        $key = (string) ($custom['key'] ?? '');
        $this->assertNotNull($catalog->findForWorkspace($key, (int) $first['workspace_id']));
        $this->assertNull($catalog->findForWorkspace($key, (int) $second['workspace_id']));

        $this->activateSession($second, 'owner');
        $this->expectException(\InvalidArgumentException::class);
        (new WorkspaceSkillInstallService($catalog))->install((int) $second['workspace_id'], $key, (int) $second['user_id']);
    }

    public function testViewerCannotCreateCustomSkill(): void
    {
        $seed = $this->seedWorkspace('custom-skill-viewer');
        $viewerId = (int) Auth::createUser(
            'custom.skill.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Custom',
            'Viewer'
        );
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $this->activateSession(array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'custom.skill.viewer@example.test',
        ]), 'viewer');

        $this->expectException(\RuntimeException::class);
        (new WorkspaceSkillCatalogService())->createCustomSkill((int) $seed['workspace_id'], $viewerId, [
            'label' => 'Viewer Skill',
            'advice_domains' => ['growth'],
        ]);
    }

    public function testOnboardingRendersAiBriefingWithoutMarketplaceSkillPicker(): void
    {
        $seed = $this->seedWorkspace('skills-onboarding');

        $get = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['mode' => 'full_setup', 'step' => 5],
        ]);
        $body = (string) ($get['body'] ?? '');
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Business setup', $body);
        $this->assertStringContainsString('Review setup', $body);
        $this->assertStringNotContainsString('Marketplace skills', $body);

        $post = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'action' => 'save_step',
                'step' => '5',
                'lean_canvas_enabled' => '1',
                'workspace_skills' => [
                    WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                ],
                'lean_problem' => 'Leads are scattered across channels.',
                'lean_customer_segments' => 'Owner-led service teams.',
                'lean_unique_value_proposition' => 'One operating memory for follow-up.',
            ],
        ]);

        $this->assertSame(302, (int) ($post['status'] ?? 0), (string) ($post['body'] ?? ''));
        $this->assertFalse((new WorkspaceSkillInstallService())->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER));
    }

    public function testMarketplaceRendersSkillsAndPluginsWithoutPricingCopy(): void
    {
        $seed = $this->seedWorkspace('marketplace-owner');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeFinanceSetup($seed);

        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            (int) $seed['user_id']
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Marketplace', $body);
        $this->assertStringNotContainsString('data-marketplace-founder-command', $body);
        $this->assertStringNotContainsString('data-marketplace-founder-snapshot', $body);
        $this->assertStringNotContainsString('Recommended for this workspace', $body);
        $this->assertStringNotContainsString('Rules-first', $body);
        $this->assertStringNotContainsString('Activation bundles', $body);
        $this->assertStringContainsString('data-tab="marketplace"', $body);
        $this->assertStringContainsString('<span class="tab-label">Add capabilities</span>', $body);
        $this->assertStringContainsString('data-marketplace-filter="all"', $body);
        $this->assertStringContainsString('data-marketplace-filter="skill"', $body);
        $this->assertStringContainsString('data-marketplace-filter="plugin"', $body);
        $this->assertStringContainsString('class="marketplace-dashboard-body"', $body);
        $this->assertStringContainsString('class="marketplace-side-rail"', $body);
        $this->assertStringNotContainsString('<article class="marketplace-dashboard-next">', $body);
        $this->assertLessThan(
            strpos($body, 'data-marketplace-async-recommendations'),
            strpos($body, 'id="marketplace-card-grid"')
        );
        $this->assertStringContainsString('data-marketplace-filter="custom-skill"', $body);
        $this->assertStringContainsString('data-marketplace-section="custom-skill"', $body);
        $this->assertStringContainsString("sectionName !== 'custom-skill'", $body);
        $this->assertStringNotContainsString('<summary style="cursor:pointer;font-weight:900;color:#0f172a;">Create custom skill</summary>', $body);
        $this->assertStringNotContainsString('id="marketplace-category-filter"', $body);
        $this->assertStringNotContainsString('id="marketplace-capability-filter"', $body);
        $this->assertStringNotContainsString('id="marketplace-status-filter"', $body);
        $this->assertStringNotContainsString('id="marketplace-source-filter"', $body);
        $this->assertStringNotContainsString('data-marketplace-tag-filter=', $body);
        $this->assertStringContainsString('id="marketplace-goal-filter"', $body);
        $this->assertStringContainsString('<option value="setup-required">Setup required</option>', $body);
        $this->assertStringContainsString('<option value="connect-channels">Connect channels</option>', $body);
        $this->assertStringContainsString('<option value="ai-guidance">AI guidance</option>', $body);
        $this->assertStringContainsString('id="marketplace-result-count"', $body);
        $this->assertStringContainsString('data-marketplace-tags=" setup-required ai-guidance strategy "', $body);
        $this->assertStringContainsString('data-marketplace-skill-key="email"', $body);
        $this->assertStringContainsString('data-marketplace-skill-key="whatsapp"', $body);
        $this->assertStringNotContainsString('data-marketplace-skill-key="communication_setup"', $body);
        $this->assertStringContainsString('aria-describedby="marketplace-tooltip-lean_canvas"', $body);
        $this->assertStringContainsString('id="marketplace-tooltip-lean_canvas"', $body);
        $this->assertStringContainsString('id="marketplace-tooltip-email_assistant"', $body);
        $this->assertStringNotContainsString('marketplace-card-tag', $body);
        $this->assertStringNotContainsString('aria-label="Marketplace tags"', $body);
        $this->assertStringNotContainsString('data-marketplace-filter="recommended"', $body);
        $this->assertStringNotContainsString('data-marketplace-filter="bundles"', $body);
        $this->assertStringContainsString('href="startup_journey.php"', $body);
        $this->assertStringContainsString('href="workspace_skills.php?module=email"', $body);
        $this->assertStringContainsString('href="workspace_skills.php?module=whatsapp"', $body);
        $this->assertStringContainsString('href="workspace_skills.php?module=email_assistant"', $body);
        $this->assertStringContainsString('Clarity Journey', $body);
        $this->assertStringContainsString('Social Media', $body);
        $this->assertStringContainsString('Design', $body);
        $this->assertStringContainsString('Campaign Manager', $body);
        $this->assertStringContainsString('Marketing Assistants', $body);
        $this->assertStringNotContainsString('Professional Marketer', $body);
        $this->assertStringContainsString('Email', $body);
        $this->assertStringContainsString('WhatsApp', $body);
        $this->assertStringContainsString('Email Assistant', $body);
        $this->assertStringContainsString('WhatsApp Assistant', $body);
        $this->assertStringContainsString('SMS Channel', $body);
        $this->assertStringContainsString('Calendar &amp; Meetings', $body);
        $this->assertStringContainsString('AI Coach', $body);
        $this->assertStringContainsString('Installed', $body);
        $this->assertStringContainsString('Available', $body);
        $this->assertStringNotContainsString('Next action</a>', $body);
        $this->assertStringNotContainsString('Back to onboarding', $body);
        $this->assertStringNotContainsString('<aside class="marketplace-drawer"', $body);
        $marketplaceCardGrid = $this->marketplaceCardGridHtml($body);
        $this->assertNotSame('', $marketplaceCardGrid);
        $this->assertStringNotContainsString('Free', $marketplaceCardGrid);
        $this->assertStringNotContainsString('paid', strtolower($marketplaceCardGrid));
        $this->assertStringNotContainsString('subscription', strtolower($marketplaceCardGrid));

        $detail = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
        ]);
        $detailBody = (string) ($detail['body'] ?? '');
        $this->assertSame(200, (int) ($detail['status'] ?? 0), (string) ($detail['stderr'] ?? ''));
        $this->assertStringContainsString('marketplace-module-page', $detailBody);
        $this->assertStringContainsString('marketplace-module-topbar', $detailBody);
        $this->assertMatchesRegularExpression('/marketplace-module-hero (?:is-thumbnail-fallback|has-banner)/', $detailBody);
        $this->assertStringContainsString('marketplace-module-banner-media', $detailBody);
        $this->assertStringContainsString('marketplace-hero-actions', $detailBody);
        $this->assertStringNotContainsString('Clone template', $detailBody);
        $this->assertStringNotContainsString('name="skill_action" value="clone_template"', $detailBody);
        $this->assertStringNotContainsString('marketplace-module-status-grid', $detailBody);
        $this->assertStringContainsString('Back to Add capabilities', $detailBody);
        $this->assertSame(1, substr_count($detailBody, 'Back to Add capabilities'));
        $this->assertStringContainsString('data-marketplace-module-tab="overview"', $detailBody);
        $this->assertStringContainsString('data-marketplace-module-tab="setup"', $detailBody);
        $this->assertStringContainsString('data-marketplace-module-tab="health"', $detailBody);
        $this->assertStringContainsString('data-marketplace-module-panel="setup"', $detailBody);
        $this->assertStringContainsString('data-marketplace-module-panel="health"', $detailBody);
        $hasServerRenderedSetupChecklist = str_contains($detailBody, 'data-marketplace-module-setup-checklist');
        if ($hasServerRenderedSetupChecklist) {
            $this->assertMatchesRegularExpression('/<details[^>]*data-marketplace-module-setup-checklist[^>]*>/s', $detailBody);
            $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-marketplace-module-setup-checklist[^>]*\sopen\b/s', $detailBody);
            $this->assertMatchesRegularExpression('/<summary class="marketplace-setup-checklist-head">.*?<strong>Status<\/strong>.*?<span class="marketplace-status[^"]*"[^>]*>[^<]+<\/span>.*?<\/summary>/s', $detailBody);
            $this->assertStringContainsString('marketplace-setup-checklist-body', $detailBody);
            $this->assertStringContainsString('marketplace-setup-check-pill', $detailBody);
            $this->assertStringContainsString('<summary>Notes</summary>', $detailBody);
        } else {
            $this->assertStringContainsString('data-marketplace-module-setup-shell', $detailBody);
            $this->assertStringContainsString('Open the setup editor to continue.', $detailBody);
            $this->assertStringContainsString('Open setup editor', $detailBody);
        }
        $this->assertStringNotContainsString('data-marketplace-module-tab="install"', $detailBody);
        $this->assertStringNotContainsString('data-marketplace-module-tab="performance"', $detailBody);
        $this->assertStringNotContainsString('data-marketplace-module-panel="install"', $detailBody);
        $this->assertStringContainsString('Remove from workspace', $detailBody);
        $this->assertStringContainsString('Open setup', $detailBody);
        $this->assertStringNotContainsString('href="#setup"', $detailBody);
        $this->assertStringNotContainsString('Manage installation', $detailBody);
        $this->assertStringNotContainsString('Install module', $detailBody);
        $this->assertStringNotContainsString('<h3>Primary action</h3>', $detailBody);
        $this->assertStringNotContainsString('Superadmin catalog editor', $detailBody);
        $this->assertStringNotContainsString('marketplace_explainer_video_file', $detailBody);
        if ($hasServerRenderedSetupChecklist) {
            $this->assertStringContainsString('Open Clarity Journey hub', $detailBody);
            $this->assertStringContainsString('Journey progress:', $detailBody);
            $this->assertStringContainsString('Current stage: Customer Discovery', $detailBody);
            $this->assertStringContainsString('Hub-managed', $detailBody);
            $this->assertStringContainsString('Open the hub to edit stages and continue setup.', $detailBody);
        }
        $this->assertStringNotContainsString('name="startup_response[', $detailBody);
        $this->assertStringNotContainsString('save_startup_journey_stage', $detailBody);
        $this->assertStringNotContainsString('Save and mark complete', $detailBody);
        $this->assertStringContainsString('workspace_skills.php?module=lean_canvas', $detailBody);
        if ($hasServerRenderedSetupChecklist) {
            $this->assertStringContainsString('Customer Discovery', $detailBody);
        }
        $this->assertStringNotContainsString('Back to onboarding', $detailBody);
        $this->assertSame(0, substr_count($detailBody, '<h1>Workspace Marketplace</h1>'));
        $this->assertStringNotContainsString('<aside class="marketplace-drawer"', $detailBody);

        $marketplaceCss = (string) file_get_contents(__DIR__ . '/../../public/assets/css/marketplace.css');
        $this->assertStringContainsString('min-height: clamp(260px, 34vw, 420px);', $marketplaceCss);
        $this->assertStringContainsString('.marketplace-module-banner-media', $marketplaceCss);
        $this->assertStringNotContainsString('grid-template-columns: 108px minmax(0, 1fr);', $marketplaceCss);
        $this->assertStringNotContainsString('width: 108px;', $marketplaceCss);

        $impressions = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND surface = 'marketplace' AND event_type = 'impression'",
            [(int) $seed['workspace_id']]
        );
        $this->assertSame(0, (int) ($impressions['c'] ?? 0));
    }

    public function testMarketplaceTemplateCloneActionIsRejected(): void
    {
        $seed = $this->seedWorkspace('marketplace-clone-disabled');
        $this->activateSession($seed, 'owner');

        $before = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_skill_definitions
             WHERE owner_workspace_id = ? AND definition_source IN ('custom', 'template_clone')",
            [(int) $seed['workspace_id']]
        );

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'autosave' => '1',
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                'skill_action' => 'clone_template',
            ],
            'headers' => ['Accept' => 'application/json'],
        ]);
        $payload = json_decode((string) ($post['body'] ?? ''), true);

        $this->assertSame(422, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['success'] ?? true));
        $this->assertSame('Template cloning is no longer available.', (string) ($payload['message'] ?? ''));

        $after = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_skill_definitions
             WHERE owner_workspace_id = ? AND definition_source IN ('custom', 'template_clone')",
            [(int) $seed['workspace_id']]
        );
        $this->assertSame((int) ($before['c'] ?? 0), (int) ($after['c'] ?? 0));
    }

    public function testMarketplaceSetupStatusCardIsCollapsedByDefault(): void
    {
        $seed = $this->seedWorkspace('marketplace-setup-status-collapsed');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeFinanceSetup($seed);

        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            (int) $seed['user_id']
        );

        $detail = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'full_setup' => '1'],
        ]);
        $body = (string) ($detail['body'] ?? '');

        $this->assertSame(200, (int) ($detail['status'] ?? 0), (string) ($detail['stderr'] ?? ''));
        $this->assertMatchesRegularExpression('/<details[^>]*data-marketplace-module-setup-checklist[^>]*>/s', $body);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-marketplace-module-setup-checklist[^>]*\sopen\b/s', $body);
        $this->assertMatchesRegularExpression('/<summary class="marketplace-setup-checklist-head">.*?<strong>Status<\/strong>.*?<span class="marketplace-status[^"]*"[^>]*>[^<]+<\/span>.*?<\/summary>/s', $body);
        $this->assertStringContainsString('marketplace-setup-checklist-body', $body);
        $this->assertStringContainsString('marketplace-setup-check-pill', $body);
        $this->assertStringContainsString('<summary>Notes</summary>', $body);
    }

    public function testBeginnerDashboardArrivalShowsGuidedSetupDestination(): void
    {
        $seed = $this->seedWorkspace('marketplace-guided-destination');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'setup_tab' => 'outreach_email',
                'source' => 'dashboard_readiness',
                'gap' => 'channel',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('data-guided-setup-destination', $body);
        $this->assertStringContainsString('Next setup step', $body);
        $this->assertStringContainsString('Connect email or WhatsApp', $body);
        $this->assertStringContainsString('Blocked until a channel is connected', $body);
        $this->assertStringContainsString('data-marketplace-module-tab="setup"', $body);
        $this->assertStringContainsString('Back to Add capabilities', $body);
        $guidedStrip = $this->firstGuidedDestinationHtml($body);
        $this->assertStringNotContainsString('campaign workspace', strtolower($guidedStrip));
        $this->assertStringNotContainsString('automation battery', strtolower($guidedStrip));

        $event = Database::queryOne(
            "SELECT event_type, source, metadata_json
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'setup_opened'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL]
        );
        $this->assertSame('setup_opened', (string) ($event['event_type'] ?? ''));
        $this->assertSame('dashboard_readiness', (string) ($event['source'] ?? ''));
        $this->assertStringContainsString('channel', (string) ($event['metadata_json'] ?? ''));
    }

    public function testCommunicationSetupExposesAndSavesWorkspaceScopedSettings(): void
    {
        $seed = $this->seedWorkspace('marketplace-communication-real-settings');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'setup_tab' => 'outreach_email',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-module-tab="overview"', $body);
        $this->assertStringContainsString('data-marketplace-module-tab="setup"', $body);
        $this->assertStringNotContainsString('data-marketplace-module-tab="health"', $body);
        $this->assertStringNotContainsString('data-marketplace-module-tab="runtime"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="overview"', $body);
        $this->assertStringContainsString('data-marketplace-setup-tab="outreach_email"', $body);
        $this->assertStringContainsString('data-marketplace-setup-tab="nurture_email"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="activity"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="assistant_email"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="whatsapp"', $body);
        $this->assertStringContainsString('Outreach Email Settings', $body);
        $this->assertStringNotContainsString('Settings in use', $body);
        $this->assertStringContainsString('marketplace-status-dot', $body);
        $this->assertStringNotContainsString('marketplace-status-dot is-ready', $body);
        $this->assertStringContainsString('Channel test', $body);
        $this->assertStringContainsString('name="skill_action" value="run_communication_channel_test"', $body);
        $this->assertStringContainsString('name="skill_action" value="save_communication_setup"', $body);
        $this->assertStringContainsString('name="email_role" value="outreach"', $body);
        $this->assertStringContainsString('name="email_role" value="nurture"', $body);
        $this->assertStringNotContainsString('name="email_role" value="assistant"', $body);
        $this->assertStringContainsString('name="smtp_host"', $body);
        $this->assertStringNotContainsString('name="phone_number_id"', $body);
        $this->assertStringNotContainsString('name="access_token"', $body);
        $this->assertStringNotContainsString('Open Email Assistant outbound setup', $body);
        $this->assertStringNotContainsString('Open WhatsApp Assistant setup', $body);

        $whatsAppGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'whatsapp',
            ],
            'env' => [
                'META_APP_ID' => '',
                'META_APP_SECRET' => '',
                'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
                'WHATSAPP_VERIFY_TOKEN' => '',
            ],
        ]);
        $whatsAppGetBody = (string) ($whatsAppGet['body'] ?? '');
        $this->assertSame(200, (int) ($whatsAppGet['status'] ?? 0), (string) ($whatsAppGet['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-tab="manual"', $whatsAppGetBody);
        $this->assertStringContainsString('data-marketplace-setup-tab="webhook"', $whatsAppGetBody);
        $this->assertStringContainsString('data-marketplace-setup-tab="migration"', $whatsAppGetBody);
        $this->assertStringContainsString('data-marketplace-setup-tab="tests"', $whatsAppGetBody);
        $this->assertStringContainsString('data-marketplace-setup-tab="activity"', $whatsAppGetBody);
        $this->assertStringContainsString('data-marketplace-setup-panel="manual"', $whatsAppGetBody);
        $this->assertStringNotContainsString('Manual WhatsApp Business API', $whatsAppGetBody);
        $this->assertStringContainsString('Settings in use', $whatsAppGetBody);
        $this->assertStringNotContainsString('Needs Setup: WhatsApp Business number', $whatsAppGetBody);
        $this->assertStringContainsString('name="communication_setup_tab" value="manual"', $whatsAppGetBody);
        $this->assertStringContainsString('name="phone_number_id"', $whatsAppGetBody);
        $this->assertStringNotContainsString('name="token_expires_at"', $whatsAppGetBody);
        $this->assertStringNotContainsString('Token expires', $whatsAppGetBody);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="embedded_signup"', $whatsAppGetBody);
        $this->assertStringNotContainsString('data-whatsapp-embedded-signup', $whatsAppGetBody);

        $outreachPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'skill_action' => 'save_communication_setup',
                'communication_channel' => 'email',
                'email_role' => 'outreach',
                'from_email' => 'outreach@example.test',
                'from_name' => 'Outreach Team',
                'smtp_host' => 'smtp.outreach.test',
                'smtp_port' => '587',
                'smtp_username' => '',
                'smtp_password' => 'outreach-secret',
                'smtp_encryption' => 'tls',
                'imap_enabled' => '1',
                'imap_host' => 'imap.outreach.test',
                'imap_port' => '993',
                'imap_username' => '',
                'imap_password' => 'outreach-imap-secret',
                'imap_encryption' => 'ssl',
                'imap_folder' => 'INBOX',
            ],
        ]);
        $this->assertSame(200, (int) ($outreachPost['status'] ?? 0), (string) ($outreachPost['stderr'] ?? ''));
        $this->assertStringContainsString('Outreach email settings saved.', (string) ($outreachPost['body'] ?? ''));
        $this->assertStringNotContainsString('Ask Super Admin to configure Google OAuth credentials.', (string) ($outreachPost['body'] ?? ''));
        $this->assertStringContainsString('marketplace-status-dot is-ready', (string) ($outreachPost['body'] ?? ''));

        $emailRow = Database::queryOne(
            "SELECT provider, scope, email_address, settings_json
             FROM email_integrations
             WHERE workspace_id = ? AND scope = ? AND is_active = 1
             LIMIT 1",
            [(int) $seed['workspace_id'], EmailIntegrationService::SCOPE_OUTREACH_EMAIL]
        );
        $this->assertSame('manual_smtp', (string) ($emailRow['provider'] ?? ''));
        $this->assertSame('outreach_email', (string) ($emailRow['scope'] ?? ''));
        $this->assertSame('outreach@example.test', (string) ($emailRow['email_address'] ?? ''));
        $emailSettings = json_decode((string) ($emailRow['settings_json'] ?? '{}'), true);
        $this->assertSame('smtp.outreach.test', (string) ($emailSettings['smtp_host'] ?? ''));
        $this->assertSame('outreach@example.test', (string) ($emailSettings['smtp_username'] ?? ''));
        $this->assertSame('outreach@example.test', (string) ($emailSettings['imap_username'] ?? ''));
        $this->assertStringNotContainsString('outreach-secret', (string) ($emailRow['settings_json'] ?? ''));

        $whatsAppPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'skill_action' => 'save_communication_setup',
                'communication_channel' => 'whatsapp',
                'phone_number_id' => '444444444444444',
                'display_phone_number' => '+254 700 444 444',
                'access_token' => 'manual-whatsapp-secret',
                'verified_name' => 'Workspace Demo',
                'whatsapp_business_account_id' => 'waba-444',
                'meta_business_id' => 'business-444',
                'notes' => 'Workspace manual setup',
            ],
            'env' => [
                'APP_KEY' => 'workspace-skills-communication-setup-test-key',
            ],
        ]);
        $this->assertSame(200, (int) ($whatsAppPost['status'] ?? 0), (string) ($whatsAppPost['stderr'] ?? ''));
        $this->assertStringContainsString('WhatsApp settings saved.', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('Manual WhatsApp Business API', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('Settings in use', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-panel="manual"', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('+254 700 444 444', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('444444444444444', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('waba-444', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('name="token_expires_at"', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('Token expires', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('Access token', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('Saved', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('api/webhooks/whatsapp.php?w=', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('Copy URL', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringContainsString('Copy token', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('WHATSAPP_VERIFY_TOKEN', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('data-whatsapp-webhook-action', (string) ($whatsAppPost['body'] ?? ''));
        $this->assertStringNotContainsString('Subscribe app', (string) ($whatsAppPost['body'] ?? ''));

        $whatsAppRow = Database::queryOne(
            "SELECT access_token, phone_number_id, token_expires_at, settings_json, webhook_token, webhook_verify_token
             FROM workspace_whatsapp_integrations
             WHERE workspace_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        $originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-skills-communication-setup-test-key';
        try {
            $activeWhatsApp = (new WorkspaceConnectService())->getActiveWhatsAppIntegration((int) $seed['workspace_id']);
        } finally {
            if ($originalAppKey === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $originalAppKey;
            }
        }
        $this->assertNotEmpty((string) ($whatsAppRow['access_token'] ?? ''));
        $this->assertNotSame('manual-whatsapp-secret', (string) ($whatsAppRow['access_token'] ?? ''));
        $this->assertSame('444444444444444', (string) ($whatsAppRow['phone_number_id'] ?? ''));
        $this->assertNull($whatsAppRow['token_expires_at'] ?? null);
        $this->assertSame('444444444444444', (string) ($activeWhatsApp['phone_number_id'] ?? ''));
        $this->assertStringContainsString('Workspace manual setup', (string) ($whatsAppRow['settings_json'] ?? ''));
        $this->assertNotEmpty((string) ($whatsAppRow['webhook_token'] ?? ''));
        $this->assertNotEmpty((string) ($activeWhatsApp['webhook_verify_token'] ?? ''));
        $this->assertNotSame((string) ($activeWhatsApp['webhook_verify_token'] ?? ''), (string) ($whatsAppRow['webhook_verify_token'] ?? ''));
    }

    public function testBlankEmailCommunicationSaveClearsExistingWorkspaceMailbox(): void
    {
        $seed = $this->seedWorkspace('marketplace-communication-clear-email');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', (int) $seed['user_id'], [
            'from_email' => 'outreach-clear@example.test',
            'from_name' => 'Outreach Clear',
            'smtp_host' => 'smtp.outreach-clear.test',
            'smtp_username' => 'outreach-clear@example.test',
            'smtp_password' => 'outreach-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.outreach-clear.test',
            'imap_username' => 'outreach-clear@example.test',
            'imap_password' => 'outreach-imap-secret',
        ], (int) $seed['workspace_id']);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'skill_action' => 'save_communication_setup',
                'communication_channel' => 'email',
                'email_role' => 'outreach',
                'from_email' => '',
                'from_name' => '',
                'smtp_host' => '',
                'smtp_port' => '587',
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
                'imap_host' => '',
                'imap_port' => '993',
                'imap_username' => '',
                'imap_password' => '',
                'imap_encryption' => 'ssl',
                'imap_folder' => 'INBOX',
            ],
        ]);

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $body = (string) ($post['body'] ?? '');
        $this->assertStringContainsString('Outreach Email disconnected because no mailbox settings were provided.', $body);
        $this->assertStringContainsString('Email not connected', $body);
        $this->assertStringNotContainsString('marketplace-status-dot is-ready', $body);

        $activeRow = Database::queryOne(
            "SELECT id
             FROM email_integrations
             WHERE workspace_id = ? AND scope = ? AND is_active = 1
             LIMIT 1",
            [(int) $seed['workspace_id'], EmailIntegrationService::SCOPE_OUTREACH_EMAIL]
        );
        $this->assertNull($activeRow);
    }

    public function testWhatsAppEmbeddedSignupTabAppearsOnlyWhenConfigured(): void
    {
        $seed = $this->seedWorkspace('marketplace-whatsapp-embedded-config');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $missing = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'manual',
            ],
            'env' => [
                'META_APP_ID' => '',
                'META_APP_SECRET' => '',
                'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
                'META_EMBEDDED_SIGNUP_CONFIG_ID' => '',
            ],
        ]);
        $missingBody = (string) ($missing['body'] ?? '');

        $this->assertSame(200, (int) ($missing['status'] ?? 0), (string) ($missing['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-tab="manual"', $missingBody);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="embedded_signup"', $missingBody);
        $this->assertStringNotContainsString('data-whatsapp-embedded-signup', $missingBody);

        $configured = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'embedded_signup',
            ],
            'env' => [
                'META_APP_ID' => 'meta-app-id',
                'META_APP_SECRET' => 'meta-secret',
                'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => 'embedded-config-id',
            ],
        ]);
        $configuredBody = (string) ($configured['body'] ?? '');

        $this->assertSame(200, (int) ($configured['status'] ?? 0), (string) ($configured['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-tab="embedded_signup"', $configuredBody);
        $this->assertStringContainsString('data-marketplace-setup-panel="embedded_signup"', $configuredBody);
        $this->assertStringContainsString('data-whatsapp-embedded-signup', $configuredBody);
        $this->assertStringContainsString('embedded-config-id', $configuredBody);
    }

    public function testWhatsAppWebhookTabShowsWorkspaceUrlAndTokenOnly(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';

        $baseOutputLevel = ob_get_level();

        try {
            ob_start();
            marketplaceRenderWhatsAppWebhookSetup([
                'settings' => [
                    'webhook_callback_url' => 'https://example.test/api/webhooks/whatsapp.php',
                    'webhook_token' => '',
                    'webhook_verify_token' => '',
                ],
                'health' => ['status' => 'warning'],
            ], true);
            $missingBody = (string) ob_get_clean();

            $this->assertStringContainsString('Workspace Webhook', $missingBody);
            $this->assertStringContainsString('Save manual setup first', $missingBody);
            $this->assertStringContainsString('Copy URL', $missingBody);
            $this->assertStringContainsString('Copy token', $missingBody);
            $this->assertStringNotContainsString('https://example.test/api/webhooks/whatsapp.php', $missingBody);
            $this->assertStringNotContainsString('data-whatsapp-webhook-action', $missingBody);
            $this->assertStringNotContainsString('Subscribe app', $missingBody);
            $this->assertStringNotContainsString('WHATSAPP_VERIFY_TOKEN', $missingBody);

            ob_start();
            marketplaceRenderWhatsAppWebhookSetup([
                'settings' => [
                    'webhook_callback_url' => 'https://example.test/api/webhooks/whatsapp.php?w=workspace-token',
                    'webhook_token' => 'workspace-token',
                    'webhook_verify_token' => 'workspace-verify-token',
                    'webhook_verified_at' => '2026-06-09 10:00:00',
                ],
                'health' => ['status' => 'warning'],
            ], true);
            $readyBody = (string) ob_get_clean();

            $this->assertStringContainsString('Ready to paste in Meta', $readyBody);
            $this->assertStringContainsString('https://example.test/api/webhooks/whatsapp.php?w=workspace-token', $readyBody);
            $this->assertStringContainsString('workspace-verify-token', $readyBody);
            $this->assertStringContainsString('Meta verified', $readyBody);
        } finally {
            while (ob_get_level() > $baseOutputLevel) {
                ob_end_clean();
            }
        }
    }

    public function testWhatsAppWebhookVerificationUsesWorkspaceToken(): void
    {
        $originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-webhook-test-key';
        $seed = $this->seedWorkspace('marketplace-whatsapp-webhook-verify');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        try {
            $service = new WorkspaceConnectService();
            $service->storeManualWhatsAppIntegration((int) $seed['workspace_id'], (int) $seed['user_id'], [
                'phone_number_id' => '555555555555555',
                'display_phone_number' => '+254 700 555 555',
                'access_token' => 'manual-webhook-secret',
                'whatsapp_business_account_id' => 'waba-555',
            ]);
            $activeWhatsApp = $service->getActiveWhatsAppIntegration((int) $seed['workspace_id']);

            $ok = $this->runEndpointScript('api/webhooks/whatsapp.php', [
                'method' => 'GET',
                'query' => [
                    'w' => (string) ($activeWhatsApp['webhook_token'] ?? ''),
                    'hub.mode' => 'subscribe',
                    'hub.verify_token' => (string) ($activeWhatsApp['webhook_verify_token'] ?? ''),
                    'hub.challenge' => 'workspace-ok',
                ],
                'env' => [
                    'APP_KEY' => 'workspace-webhook-test-key',
                    'WHATSAPP_VERIFY_TOKEN' => '',
                ],
            ]);

            $this->assertSame(200, (int) ($ok['status'] ?? 0), (string) ($ok['stderr'] ?? ''));
            $this->assertSame('workspace-ok', trim((string) ($ok['body'] ?? '')));

            $wrong = $this->runEndpointScript('api/webhooks/whatsapp.php', [
                'method' => 'GET',
                'query' => [
                    'w' => (string) ($activeWhatsApp['webhook_token'] ?? ''),
                    'hub.mode' => 'subscribe',
                    'hub.verify_token' => 'wrong-token',
                    'hub.challenge' => 'workspace-ok',
                ],
                'env' => [
                    'APP_KEY' => 'workspace-webhook-test-key',
                    'WHATSAPP_VERIFY_TOKEN' => '',
                ],
            ]);

            $this->assertSame(403, (int) ($wrong['status'] ?? 0), (string) ($wrong['stderr'] ?? ''));
            $this->assertStringContainsString('Verification failed', (string) ($wrong['body'] ?? ''));

            $bare = $this->runEndpointScript('api/webhooks/whatsapp.php', [
                'method' => 'GET',
                'query' => [
                    'hub.mode' => 'subscribe',
                    'hub.verify_token' => 'legacy-global-token',
                    'hub.challenge' => 'legacy-global-ok',
                ],
                'env' => [
                    'APP_KEY' => 'workspace-webhook-test-key',
                    'WHATSAPP_VERIFY_TOKEN' => 'legacy-global-token',
                ],
            ]);

            $this->assertSame(403, (int) ($bare['status'] ?? 0), (string) ($bare['stderr'] ?? ''));
            $this->assertStringContainsString('Workspace webhook token is required', (string) ($bare['body'] ?? ''));
        } finally {
            if ($originalAppKey === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $originalAppKey;
            }
        }
    }

    public function testWhatsAppWebhookPostRequiresWorkspaceTokenAndMatchingMetadata(): void
    {
        $originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-webhook-post-test-key';
        $seed = $this->seedWorkspace('marketplace-whatsapp-webhook-post');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        try {
            $service = new WorkspaceConnectService();
            $service->storeManualWhatsAppIntegration((int) $seed['workspace_id'], (int) $seed['user_id'], [
                'phone_number_id' => '666666666666666',
                'display_phone_number' => '+254 700 666 666',
                'access_token' => 'manual-webhook-post-secret',
                'whatsapp_business_account_id' => 'waba-666',
            ]);
            $activeWhatsApp = $service->getActiveWhatsAppIntegration((int) $seed['workspace_id']);

            $bare = $this->postWhatsAppWebhookPayload(
                $this->workspaceWhatsAppPayload('wamid.workspace-bare-post', '666666666666666', 'waba-666'),
                [],
                'workspace-webhook-post-test-key'
            );
            $this->assertSame(403, (int) ($bare['status'] ?? 0), (string) ($bare['stderr'] ?? ''));
            $this->assertStringContainsString('Workspace webhook token is required', (string) ($bare['body'] ?? ''));
            $this->assertWhatsAppMessageMissing('wamid.workspace-bare-post');

            $unknown = $this->postWhatsAppWebhookPayload(
                $this->workspaceWhatsAppPayload('wamid.workspace-unknown-post', '666666666666666', 'waba-666'),
                ['w' => 'unknown-workspace-token-123456'],
                'workspace-webhook-post-test-key'
            );
            $this->assertSame(403, (int) ($unknown['status'] ?? 0), (string) ($unknown['stderr'] ?? ''));
            $this->assertStringContainsString('Unknown workspace webhook', (string) ($unknown['body'] ?? ''));
            $this->assertWhatsAppMessageMissing('wamid.workspace-unknown-post');

            $mismatch = $this->postWhatsAppWebhookPayload(
                $this->workspaceWhatsAppPayload('wamid.workspace-mismatch-post', '777777777777777', 'waba-666'),
                ['w' => (string) ($activeWhatsApp['webhook_token'] ?? '')],
                'workspace-webhook-post-test-key'
            );
            $this->assertSame(403, (int) ($mismatch['status'] ?? 0), (string) ($mismatch['stderr'] ?? ''));
            $this->assertStringContainsString('Webhook phone number ID does not match this workspace', (string) ($mismatch['body'] ?? ''));
            $this->assertWhatsAppMessageMissing('wamid.workspace-mismatch-post');

            $ok = $this->postWhatsAppWebhookPayload(
                $this->workspaceWhatsAppPayload('wamid.workspace-token-post', '666666666666666', 'waba-666'),
                ['w' => (string) ($activeWhatsApp['webhook_token'] ?? '')],
                'workspace-webhook-post-test-key'
            );
            $this->assertSame(200, (int) ($ok['status'] ?? 0), (string) ($ok['stderr'] ?? ''));

            $message = Database::queryOne(
                "SELECT workspace_id
                 FROM whatsapp_messages
                 WHERE whatsapp_message_id = ?
                 LIMIT 1",
                ['wamid.workspace-token-post']
            );
            $this->assertNotNull($message);
            $this->assertSame((int) $seed['workspace_id'], (int) ($message['workspace_id'] ?? 0));
        } finally {
            if ($originalAppKey === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $originalAppKey;
            }
        }
    }

    public function testWhatsAppWebhookActionEndpointsUseWorkspaceAdminAndCsrf(): void
    {
        $seed = $this->seedWorkspace('marketplace-whatsapp-webhook-actions');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $ownerList = $this->runWebEndpoint('api/whatsapp/webhooks/list.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
        ]);
        $this->assertSame(405, (int) ($ownerList['status'] ?? 0), (string) ($ownerList['stderr'] ?? ''));
        $this->assertStringContainsString('Method not allowed', (string) ($ownerList['body'] ?? ''));

        $viewerId = (int) Auth::createUser(
            'marketplace.whatsapp.webhook.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Webhook',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.whatsapp.webhook.viewer@example.test',
        ]);

        $viewerList = $this->runWebEndpoint('api/whatsapp/webhooks/list.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
        ]);
        $this->assertSame(403, (int) ($viewerList['status'] ?? 0), (string) ($viewerList['stderr'] ?? ''));

        $webhookEnv = [
            'META_APP_ID' => 'meta-app-id',
            'META_APP_SECRET' => 'meta-secret',
            'WHATSAPP_VERIFY_TOKEN' => 'verify-token',
        ];
        $subscribeMissingCsrf = $this->runWebEndpoint('api/whatsapp/webhooks/subscribe.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => ['object' => 'whatsapp_business_account'],
            'env' => $webhookEnv,
        ]);
        $this->assertSame(403, (int) ($subscribeMissingCsrf['status'] ?? 0), (string) ($subscribeMissingCsrf['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid CSRF token', (string) ($subscribeMissingCsrf['body'] ?? ''));

        $unsubscribeMissingCsrf = $this->runWebEndpoint('api/whatsapp/webhooks/unsubscribe.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => ['object' => 'whatsapp_business_account'],
            'env' => $webhookEnv,
        ]);
        $this->assertSame(403, (int) ($unsubscribeMissingCsrf['status'] ?? 0), (string) ($unsubscribeMissingCsrf['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid CSRF token', (string) ($unsubscribeMissingCsrf['body'] ?? ''));
    }

    public function testWhatsAppMigrationStatusIsWorkspaceScoped(): void
    {
        $first = $this->seedWorkspace('marketplace-whatsapp-migration-one');
        $second = $this->seedWorkspace('marketplace-whatsapp-migration-two');
        $this->activateSession($first, 'owner');
        $this->completeOnboarding((int) $first['workspace_id']);
        $this->completeOnboarding((int) $second['workspace_id']);

        Database::execute(
            "INSERT INTO whatsapp_migration_log
                (workspace_id, user_id, phone_number_id, whatsapp_business_account_id, step, success, error_message, created_at)
             VALUES
                (?, ?, '111111111111111', 'waba-one', 'register_number', 0, 'first-workspace-migration-only', NOW()),
                (?, ?, '222222222222222', 'waba-two', 'register_number', 0, 'second-workspace-migration-hidden', NOW())",
            [
                (int) $first['workspace_id'],
                (int) $first['user_id'],
                (int) $second['workspace_id'],
                (int) $second['user_id'],
            ]
        );

        $migrationTab = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($first, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'migration',
            ],
            'env' => [
                'WHATSAPP_ONPREM_API_URL' => 'https://onprem.example.test',
            ],
        ]);
        $migrationBody = (string) ($migrationTab['body'] ?? '');

        $this->assertSame(200, (int) ($migrationTab['status'] ?? 0), (string) ($migrationTab['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-panel="migration"', $migrationBody);
        $this->assertMatchesRegularExpression('/data-whatsapp-migration-form="generate_metadata"[\s\S]*<button class="btn-premium-secondary" type="submit">Generate metadata<\/button>/', $migrationBody);
        $this->assertMatchesRegularExpression('/data-whatsapp-migration-form="register_number"[\s\S]*<button class="btn-premium-primary" type="submit" disabled>Register saved number<\/button>/', $migrationBody);
        $this->assertStringContainsString('data-whatsapp-migration-health disabled', $migrationBody);
        $this->assertMatchesRegularExpression('/data-whatsapp-migration-form="deregister_number"[\s\S]*<button class="btn-premium-secondary" type="submit" disabled>Deregister number<\/button>/', $migrationBody);
        $this->assertStringContainsString('first-workspace-migration-only', $migrationBody);
        $this->assertStringNotContainsString('second-workspace-migration-hidden', $migrationBody);

        $status = $this->runWebEndpoint('api/whatsapp/migrate.php', $this->webSession($first, 'owner'), [
            'method' => 'GET',
            'query' => ['status' => '1'],
        ]);
        $payload = json_decode((string) ($status['body'] ?? '{}'), true);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertSame(200, (int) ($status['status'] ?? 0), (string) ($status['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame((int) $first['workspace_id'], (int) ($payload['workspace_id'] ?? 0));
        $this->assertStringContainsString('first-workspace-migration-only', $encoded);
        $this->assertStringNotContainsString('second-workspace-migration-hidden', $encoded);
    }

    public function testCommunicationSetupRunsReadinessTestsForEachChannel(): void
    {
        $seed = $this->seedWorkspace('marketplace-communication-channel-tests');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        foreach ([
            'outreach_email' => 'Outreach Email',
            'nurture_email' => 'Nurture Email',
        ] as $channelKey => $label) {
            $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                    'skill_action' => 'run_communication_channel_test',
                    'communication_test_channel' => $channelKey,
                    'communication_setup_tab' => $channelKey,
                ],
            ]);

            $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
            $this->assertStringContainsString($label . ' readiness test', (string) ($post['body'] ?? ''));
        }

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'skill_action' => 'run_communication_channel_test',
                'communication_test_channel' => 'whatsapp',
                'communication_setup_tab' => 'manual',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('WhatsApp readiness test', (string) ($post['body'] ?? ''));

        $events = Database::query(
            "SELECT skill_key, event_type, step_key, step_status, metadata_json
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ?
               AND skill_key IN (?, ?)
               AND step_key IN ('test_outreach_email', 'test_nurture_email', 'test_whatsapp')",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP]
        );

        $this->assertCount(3, $events);
        $stepKeys = array_map(static fn(array $row): string => (string) ($row['step_key'] ?? ''), $events);
        sort($stepKeys);
        $this->assertSame(['test_nurture_email', 'test_outreach_email', 'test_whatsapp'], $stepKeys);
        foreach ($events as $event) {
            $this->assertContains((string) ($event['event_type'] ?? ''), ['step_completed', 'step_reset']);
            $this->assertStringContainsString('channel', (string) ($event['metadata_json'] ?? ''));
        }
    }

    public function testMarketplaceDashboardUsesGlobalDonationPopup(): void
    {
        $seed = $this->seedWorkspace('marketplace-donation-cta');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringNotContainsString('marketplace-donation-band', $body);
        $this->assertStringNotContainsString('data-marketplace-donation-form', $body);
        $this->assertStringNotContainsString('workspace_skills.php#workspace-donation', $body);
        $this->assertStringContainsString('data-workspace-donation-open', $body);
        $this->assertStringContainsString('data-workspace-donation-form', $body);
        $this->assertStringContainsString('donations/checkout.php', $body);
        $this->assertStringContainsString('name="amount"', $body);
        $this->assertStringContainsString('name="payment_mode"', $body);
    }

    public function testAiApiModuleExposesAndSavesWorkspaceProvider(): void
    {
        $_ENV['APP_KEY'] = 'workspace-ai-api-ui-test-key';
        $seed = $this->seedWorkspace('marketplace-ai-api-provider');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'growth-studio-monthly');
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_AI_API, (int) $seed['user_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_AI_API,
                'setup_tab' => 'provider',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('AI API Routing', $body);
        $this->assertStringContainsString('Workspace AI Provider', $body);
        $this->assertStringContainsString('name="skill_action" value="save_ai_api_setup"', $body);
        $this->assertStringContainsString('name="ai_api_key"', $body);
        $this->assertStringNotContainsString('name="ai_shared_daily_token_cap"', $body);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_AI_API,
                'skill_action' => 'save_ai_api_setup',
                'ai_provider_enabled' => '1',
                'ai_provider_key' => 'openai',
                'ai_api_url' => 'https://api.openai.com/v1/chat/completions',
                'ai_model' => 'gpt-4o-mini',
                'ai_api_key' => 'workspace-secret-ai-key',
            ],
        ]);

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace AI API setup saved.', (string) ($post['body'] ?? ''));

        $row = Database::queryOne(
            "SELECT provider_key, mode, api_url, model, encrypted_api_key, api_key_fingerprint, shared_daily_token_cap
             FROM workspace_ai_provider_configs
             WHERE workspace_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        $config = (new WorkspaceAIProviderConfigService())->get((int) $seed['workspace_id'], false);

        $this->assertSame('openai', (string) ($row['provider_key'] ?? ''));
        $this->assertSame('enabled', (string) ($row['mode'] ?? ''));
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) ($row['api_url'] ?? ''));
        $this->assertSame('gpt-4o-mini', (string) ($row['model'] ?? ''));
        $this->assertNotEmpty((string) ($row['encrypted_api_key'] ?? ''));
        $this->assertStringNotContainsString('workspace-secret-ai-key', (string) ($row['encrypted_api_key'] ?? ''));
        $this->assertNotEmpty((string) ($row['api_key_fingerprint'] ?? ''));
        $this->assertSame(0, (int) ($row['shared_daily_token_cap'] ?? -1));
        $this->assertTrue(!empty($config['api_key_present']));
    }

    public function testBeginnerAddCapabilitiesArrivalUsesSideRailGuidanceBesideCatalog(): void
    {
        $seed = $this->seedWorkspace('marketplace-guided-capabilities');
        $this->activateSession($seed, 'owner');

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'source' => 'dashboard_guidance',
                'action' => 'add_capabilities_when_blocked',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('<h1>Add capabilities</h1>', $body);
        $this->assertStringContainsString('class="marketplace-dashboard-body"', $body);
        $this->assertStringContainsString('class="marketplace-side-rail"', $body);
        $this->assertStringNotContainsString('<article class="marketplace-dashboard-next">', $body);
        $this->assertLessThan(
            strpos($body, 'data-guided-setup-destination'),
            strpos($body, 'id="marketplace-card-grid"')
        );
        $this->assertStringContainsString('data-guided-setup-destination', $body);
        $this->assertMatchesRegularExpression('/<aside class="marketplace-side-rail".*?<p class="marketplace-eyebrow">Recommended next<\/p>.*?Finish Email/s', $body);
        $sideNextAction = $this->marketplaceSideNextActionHtml($body);
        $this->assertStringContainsString('Finish Email', $sideNextAction);
        $this->assertStringContainsString('Email needs Outreach or Nurture setup before email runtime is ready.', $sideNextAction);
        $this->assertStringNotContainsString('Checking setup readiness', $sideNextAction);
        $guidedStrip = $this->firstGuidedDestinationHtml($body);
        $guidedPrimary = strstr($guidedStrip, '<div class="marketplace-guided-recommendations"', true) ?: $guidedStrip;
        $this->assertStringContainsString('Finish Email', $guidedStrip);
        $this->assertStringContainsString('workspace_skills.php?module=email', $guidedStrip);
        $this->assertStringContainsString('Setup step needs attention', $guidedStrip);
        $this->assertStringNotContainsString('Organization Intelligence Setup', $guidedPrimary);
        $this->assertStringNotContainsString('AI API', $guidedPrimary);
        $this->assertLessThanOrEqual(3, substr_count($body, 'class="marketplace-guided-recommendation"'));
        $this->assertStringContainsString('id="marketplace-search"', $body);
        $this->assertStringContainsString('data-marketplace-filter="all"', $body);
        $this->assertStringNotContainsString('Recommended for this workspace', $body);
    }

    public function testMarketplaceSideRailSkipsReadyEmailPluginWhenCatalogReadinessIsDeferred(): void
    {
        $seed = $this->seedWorkspace('marketplace-ready-email-next-action');
        $this->activateSession($seed, 'owner');
        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', (int) $seed['user_id'], [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.outreach.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'outreach-secret',
            'imap_enabled' => false,
        ], (int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');
        $sideNextAction = $this->marketplaceSideNextActionHtml($body);

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('class="marketplace-side-rail"', $body);
        $this->assertStringNotContainsString('Finish Email', $sideNextAction);
        $this->assertStringNotContainsString('Checking setup readiness', $sideNextAction);
    }

    public function testAdvancedMarketplaceUsesSideRailGuidanceAfterCatalog(): void
    {
        $seed = $this->seedWorkspace('marketplace-advanced-side-rail');
        (new UserPreferences())->setPreference((int) $seed['user_id'], UIExperienceService::PREFERENCE_KEY, UIExperienceService::MODE_ADVANCED);
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('<h1>Marketplace</h1>', $body);
        $this->assertStringContainsString('class="marketplace-dashboard-body"', $body);
        $this->assertStringContainsString('class="marketplace-side-rail"', $body);
        $this->assertStringContainsString('data-marketplace-async-recommendations', $body);
        $this->assertStringNotContainsString('<article class="marketplace-dashboard-next">', $body);
        $this->assertLessThan(
            strpos($body, 'data-marketplace-async-recommendations'),
            strpos($body, 'id="marketplace-card-grid"')
        );
        $this->assertStringContainsString('data-marketplace-filter="plugin"', $body);
        $this->assertStringContainsString('data-marketplace-skill-key="email"', $body);
        $this->assertStringContainsString('data-marketplace-skill-key="email_assistant"', $body);
    }

    public function testFounderJourneyNavRequiresStartupJourneyInstall(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-nav');
        $this->grantRolePermissions('owner', ['founder_loop.view']);

        $withoutInstall = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $withoutBody = (string) ($withoutInstall['body'] ?? '');
        $this->assertSame(200, (int) ($withoutInstall['status'] ?? 0), (string) ($withoutInstall['stderr'] ?? ''));
        $this->assertStringContainsString('data-tab="marketplace"', $withoutBody);
        $this->assertStringNotContainsString('data-tab="startup-journey"', $withoutBody);
        $this->assertStringNotContainsString('data-tab="founder-loop"', $withoutBody);

        $startupLocked = $this->runWebEndpoint('public/startup_journey.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(302, (int) ($startupLocked['status'] ?? 0), (string) ($startupLocked['stderr'] ?? ''));

        $founderLocked = $this->runWebEndpoint('public/founder_operating_loop.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(302, (int) ($founderLocked['status'] ?? 0), (string) ($founderLocked['stderr'] ?? ''));

        $this->activateSession($seed, 'owner');
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeOnboarding((int) $seed['workspace_id']);

        $withInstall = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $withBody = (string) ($withInstall['body'] ?? '');
        $this->assertSame(200, (int) ($withInstall['status'] ?? 0), (string) ($withInstall['stderr'] ?? ''));
        $this->assertStringContainsString('data-tab="startup-journey"', $withBody);
        $this->assertStringNotContainsString('data-tab="founder-loop"', $withBody);
        $this->assertStringNotContainsString('tab-new-badge nav-new-badge">New</span>', $withBody);

        $startupOpen = $this->runWebEndpoint('public/startup_journey.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($startupOpen['status'] ?? 0), (string) ($startupOpen['stderr'] ?? ''));
        $this->assertStringContainsString('Clarity Journey', (string) ($startupOpen['body'] ?? ''));

        $founderOpen = $this->runWebEndpoint('public/founder_operating_loop.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(302, (int) ($founderOpen['status'] ?? 0), (string) ($founderOpen['stderr'] ?? ''));

        $this->completeStartupJourneySetup($seed);

        $afterJourneyComplete = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $afterJourneyBody = (string) ($afterJourneyComplete['body'] ?? '');
        $this->assertSame(200, (int) ($afterJourneyComplete['status'] ?? 0), (string) ($afterJourneyComplete['stderr'] ?? ''));
        $this->assertStringNotContainsString('data-tab="startup-journey"', $afterJourneyBody);
        $this->assertStringContainsString('data-tab="founder-loop"', $afterJourneyBody);

        $founderUnlocked = $this->runWebEndpoint('public/founder_operating_loop.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $founderUnlockedBody = (string) ($founderUnlocked['body'] ?? '');
        $this->assertSame(200, (int) ($founderUnlocked['status'] ?? 0), (string) ($founderUnlocked['stderr'] ?? ''));
        $this->assertStringContainsString('Founder Loop', $founderUnlockedBody);
        $this->assertStringContainsString('Review Clarity', $founderUnlockedBody);
        $this->assertStringContainsString('Weekly Plan', $founderUnlockedBody);
        $this->assertStringContainsString('Create CRM tasks from commitments', $founderUnlockedBody);
        $this->assertStringNotContainsString('Target customer segment', $founderUnlockedBody);
        $this->assertStringNotContainsString('Save sprint', $founderUnlockedBody);
    }

    public function testMarketplaceModuleSetupChecklistUsesCleanAsciiSeparators(): void
    {
        $seed = $this->seedWorkspace('marketplace-clean-setup-text');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);
        $installer = new WorkspaceSkillInstallService();
        $installer->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            (int) $seed['user_id']
        );
        $this->completeStartupJourneySetup($seed);
        $installer->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_AI_COACH,
            (int) $seed['user_id']
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_AI_COACH, 'full_setup' => '1'],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Installed for workspace', $body);
        $this->assertStringContainsString('Enabled for workspace', $body);
        $this->assertStringContainsString('Ready advice-skill coverage', $body);
        $this->assertStringNotContainsString('Ready - Installed for workspace', $body);
        $this->assertStringNotContainsString('Ready - Enabled for workspace', $body);
        $this->assertStringNotContainsString('Ready - Ready advice-skill coverage', $body);
        $this->assertStringNotContainsString('Â', $body);
        $this->assertStringNotContainsString('Ã‚', $body);
        $this->assertStringNotContainsString('&Acirc;', $body);
    }

    public function testFounderJourneyNavShowsNewBadgeAfterStartupJourneySetupCompletion(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-new');
        $this->grantRolePermissions('owner', ['founder_loop.view']);
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeStartupJourneySetup($seed);
        Database::execute(
            "UPDATE startup_journey_stage_responses
             SET completed_at = ?
             WHERE workspace_id = ? AND user_id = ?",
            [date('Y-m-d H:i:s'), (int) $seed['workspace_id'], (int) $seed['user_id']]
        );

        $fresh = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $freshBody = (string) ($fresh['body'] ?? '');
        $this->assertSame(200, (int) ($fresh['status'] ?? 0), (string) ($fresh['stderr'] ?? ''));
        $this->assertStringNotContainsString('data-tab="startup-journey"', $freshBody);
        $this->assertStringContainsString('data-tab="founder-loop"', $freshBody);
        $founderNavPosition = strpos($freshBody, 'data-tab="founder-loop"');
        $this->assertIsInt($founderNavPosition);
        $founderNavEnd = strpos($freshBody, '</a>', $founderNavPosition);
        $this->assertIsInt($founderNavEnd);
        $founderNavSegment = substr($freshBody, $founderNavPosition, $founderNavEnd - $founderNavPosition);
        $this->assertStringContainsString('tab-new-badge nav-new-badge">New</span>', $founderNavSegment);

        Database::execute(
            "UPDATE startup_journey_stage_responses
             SET completed_at = DATE_SUB(NOW(), INTERVAL 4 DAY)
             WHERE workspace_id = ? AND user_id = ?",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );

        $stale = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $staleBody = (string) ($stale['body'] ?? '');
        $this->assertSame(200, (int) ($stale['status'] ?? 0), (string) ($stale['stderr'] ?? ''));
        $this->assertStringNotContainsString('data-tab="startup-journey"', $staleBody);
        $this->assertStringContainsString('data-tab="founder-loop"', $staleBody);
        $staleFounderNavPosition = strpos($staleBody, 'data-tab="founder-loop"');
        $this->assertIsInt($staleFounderNavPosition);
        $staleFounderNavEnd = strpos($staleBody, '</a>', $staleFounderNavPosition);
        $this->assertIsInt($staleFounderNavEnd);
        $staleFounderNavSegment = substr($staleBody, $staleFounderNavPosition, $staleFounderNavEnd - $staleFounderNavPosition);
        $this->assertStringNotContainsString('tab-new-badge nav-new-badge">New</span>', $staleFounderNavSegment);
    }

    public function testMarketplaceRequiresViewAccessProfile(): void
    {
        $seed = $this->seedWorkspace('marketplace-no-access', false);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);

        $this->assertSame(403, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $body = (string) ($get['body'] ?? '');
        $this->assertStringContainsString('Marketplace access requires the Workspace Skills access profile', $body);
        $this->assertStringNotContainsString('data-tab="marketplace"', $body);
    }

    public function testOwnerRoleAloneCannotInstallMarketplaceModules(): void
    {
        $seed = $this->seedWorkspace('marketplace-owner-no-manage', false);
        $this->activateSession($seed, 'owner');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('access profile');

        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            (int) $seed['user_id']
        );
    }

    public function testMarketplaceViewPermissionShowsCatalogWithoutManagementActions(): void
    {
        $seed = $this->seedWorkspace('marketplace-view-only', false);
        $this->grantRolePermissions('owner', ['workspace.skills.view']);
        $this->revokeRolePermissions('owner', ['workspace.skills.manage']);
        $this->completeOnboarding((int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Marketplace', $body);
        $this->assertStringContainsString('Lean Canvas', $body);
        $this->assertStringContainsString('Campaign Manager', $body);
        $this->assertStringContainsString('Email Assistant', $body);
        $this->assertStringContainsString('WhatsApp Assistant', $body);
        $this->assertStringContainsString('SMS Channel', $body);
        $this->assertStringContainsString('Calendar &amp; Meetings', $body);
        $this->assertStringContainsString('AI Coach', $body);
        $this->assertStringContainsString('Install, setup, and recommendation actions require Marketplace management access.', $body);
        $this->assertStringNotContainsString('name="skill_action" value="install"', $body);
        $this->assertStringNotContainsString('name="skill_action" value="uninstall"', $body);
        $this->assertStringNotContainsString('name="skill_action" value="dismiss_recommendation"', $body);
    }

    public function testMarketplaceViewerCanBrowseButCannotInstall(): void
    {
        $seed = $this->seedWorkspace('marketplace-viewer');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'growth-studio-monthly');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->seedMarketplaceInsightEvents((int) $seed['workspace_id'], (int) $seed['user_id']);
        $viewerId = (int) Auth::createUser(
            'marketplace.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Market',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.viewer@example.test',
        ]);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Marketplace', $body);
        $this->assertStringNotContainsString('data-marketplace-founder-snapshot', $body);
        $this->assertStringNotContainsString('href="founder_operating_loop.php"', $body);
        $this->assertStringNotContainsString('Recommended for this workspace', $body);
        $this->assertStringContainsString('You can browse the Marketplace', $body);
        $this->assertStringNotContainsString('marketplace-insight-chip', $body);
        $this->assertStringNotContainsString('<div class="marketplace-setup-journey"', $body);
        $this->assertFalse((new WorkspaceSkillInstallService())->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER));
    }

    public function testMarketplaceOwnerSeesRecommendationInsightChip(): void
    {
        $seed = $this->seedWorkspace('marketplace-owner-insights');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->seedMarketplaceInsightEvents((int) $seed['workspace_id'], (int) $seed['user_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringNotContainsString('Recommended for this workspace', $body);
        $this->assertStringNotContainsString('marketplace-insight-chip', $body);
        $this->assertStringNotContainsString('High dismissal rate', $body);
        $this->assertStringNotContainsString('Review the recommendation copy', $body);
        $this->assertStringNotContainsString('secret', strtolower($body));
        $this->assertStringNotContainsString('subscription', strtolower($body));
    }

    public function testMarketplaceOwnerSeesAdaptiveRecommendationNote(): void
    {
        $seed = $this->seedWorkspace('marketplace-owner-adaptive');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);

        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'setup_opened',
            ['label' => 'WhatsApp Assistant']
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringNotContainsString('marketplace-adaptive-note', $body);
        $this->assertStringNotContainsString('Adaptive signal +6', $body);
        $this->assertStringNotContainsString('Recent setup activity suggests this recommendation is getting useful follow-through.', $body);
        $this->assertStringNotContainsString('secret', strtolower($body));
    }

    public function testMarketplaceViewerDoesNotSeeAdaptiveRecommendationNote(): void
    {
        $seed = $this->seedWorkspace('marketplace-viewer-adaptive');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);
        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'setup_opened',
            ['label' => 'WhatsApp Assistant']
        );

        $viewerId = (int) Auth::createUser(
            'marketplace.viewer.adaptive.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Market',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.viewer.adaptive@example.test',
        ]);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'GET',
        ]);

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringNotContainsString('<div class="marketplace-adaptive-note"', (string) ($get['body'] ?? ''));
    }

    public function testMarketplaceOwnerSeesSetupJourneyChecklist(): void
    {
        $seed = $this->seedWorkspace('marketplace-owner-journey');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('marketplace-setup-journey', $body);
        $this->assertStringContainsString('Setup journey', $body);
        $this->assertStringContainsString('Install WhatsApp Assistant', $body);
        $this->assertStringContainsString('Connect WhatsApp and configure the assistant phone number settings.', $body);
        $this->assertStringContainsString('complete_setup_step', $body);
        $this->assertStringContainsString('skip_setup_step', $body);
        $this->assertStringContainsString('reset_setup_step', $body);
        $this->assertStringContainsString('Done', $body);
        $this->assertStringContainsString('Skip', $body);
        $this->assertStringContainsString('Reset', $body);
        $this->assertStringNotContainsString('subscription', strtolower($body));

        $event = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'journey_impression'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertGreaterThanOrEqual(1, (int) ($event['c'] ?? 0));
    }

    public function testMarketplaceSetupJourneyPostActionsPersistForOwner(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-actions');
        $stepLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $stepKey = 'setup_' . substr(hash('sha1', $stepLabel), 0, 16);

        $complete = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'complete_setup_step',
                'setup_step_key' => $stepKey,
                'setup_step_label' => $stepLabel,
                'setup_step_source' => 'catalog',
            ],
        ]);
        $this->assertContains((int) ($complete['status'] ?? 0), [200, 302], (string) ($complete['stderr'] ?? ''));
        $this->assertSame('completed', $this->setupStepStatus((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $stepKey));
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'step_completed'));

        $skip = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'skip_setup_step',
                'setup_step_key' => $stepKey,
                'setup_step_label' => $stepLabel,
                'setup_step_source' => 'catalog',
            ],
        ]);
        $this->assertContains((int) ($skip['status'] ?? 0), [200, 302], (string) ($skip['stderr'] ?? ''));
        $this->assertSame('skipped', $this->setupStepStatus((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $stepKey));
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'step_skipped'));

        $reset = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'reset_setup_step',
                'setup_step_key' => $stepKey,
                'setup_step_label' => $stepLabel,
                'setup_step_source' => 'catalog',
            ],
        ]);
        $this->assertContains((int) ($reset['status'] ?? 0), [200, 302], (string) ($reset['stderr'] ?? ''));
        $this->assertSame('pending', $this->setupStepStatus((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $stepKey));
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'step_reset'));
    }

    public function testMarketplaceSetupJourneyInstallRecordsInstallCompletionEvent(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-install');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'install',
            ],
        ]);

        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'install_completed'));
    }

    public function testMarketplaceSetupJourneyActionsRequireCsrfAndManagePermission(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-auth');
        $stepLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $stepKey = 'setup_' . substr(hash('sha1', $stepLabel), 0, 16);

        $badCsrf = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad-token',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'complete_setup_step',
                'setup_step_key' => $stepKey,
                'setup_step_label' => $stepLabel,
                'setup_step_source' => 'catalog',
            ],
        ]);
        $this->assertContains((int) ($badCsrf['status'] ?? 0), [200, 302], (string) ($badCsrf['stderr'] ?? ''));
        if ((int) ($badCsrf['status'] ?? 0) === 200) {
            $this->assertStringContainsString('Invalid security token', (string) ($badCsrf['body'] ?? ''));
        }
        $this->assertNull($this->setupStepStatus((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $stepKey));

        $viewerId = (int) Auth::createUser(
            'marketplace.journey.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Journey',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.journey.viewer@example.test',
        ]);

        $viewerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'complete_setup_step',
                'setup_step_key' => $stepKey,
                'setup_step_label' => $stepLabel,
                'setup_step_source' => 'catalog',
            ],
        ]);
        $this->assertSame(200, (int) ($viewerPost['status'] ?? 0), (string) ($viewerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Your access profile does not allow Marketplace install or setup changes.', (string) ($viewerPost['body'] ?? ''));
        $this->assertNull($this->setupStepStatus((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $stepKey));
    }

    public function testMarketplaceOwnerCanSaveEmailAssistantSetupWithoutSettingsAccess(): void
    {
        $seed = $this->seedWorkspace('marketplace-email-setup');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        $_ENV['APP_KEY'] = 'workspace-skills-email-assistant-test-key';
        putenv('APP_KEY=workspace-skills-email-assistant-test-key');

        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            (int) $seed['user_id']
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'setup_tab' => 'overview',
            ],
        ]);
        $body = (string) ($get['body'] ?? '');
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-module-tab="overview"', $body);
        $this->assertStringContainsString('data-marketplace-module-tab="setup"', $body);
        $this->assertStringNotContainsString('data-marketplace-module-tab="health"', $body);
        $this->assertStringNotContainsString('data-marketplace-module-tab="runtime"', $body);
        foreach (['identity', 'outbound', 'inbound', 'skills', 'digest', 'tests', 'activity', 'advanced'] as $setupTab) {
            $this->assertStringContainsString('data-marketplace-setup-tab="' . $setupTab . '"', $body);
            $this->assertStringContainsString('data-marketplace-setup-panel="' . $setupTab . '"', $body);
        }
        $this->assertStringNotContainsString('data-marketplace-setup-tab="overview"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-panel="overview"', $body);
        $this->assertStringNotContainsString('data-marketplace-setup-tab="setup"', $body);
        $this->assertStringContainsString('Identity', $body);
        $this->assertStringContainsString('Outbound sending', $body);
        $this->assertStringContainsString('Inbound IMAP', $body);
        $this->assertStringContainsString('Skills', $body);
        $this->assertStringContainsString('Digest', $body);
        $this->assertStringContainsString('Tests', $body);
        $this->assertStringContainsString('Activity', $body);
        $this->assertStringContainsString('Open reopen template form', $body);
        $this->assertStringContainsString('Create contact', $body);
        $this->assertStringContainsString('Run report', $body);
        $this->assertStringContainsString('Send daily digest', $body);
        $this->assertStringContainsString('name="skill_action" value="send_email_assistant_test_digest"', $body);
        $this->assertStringNotContainsString('Gmail platform app: Not configured', $body);
        $this->assertStringNotContainsString('Google Workspace platform app: Not configured', $body);

        $testsTabGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'setup_tab' => 'tests',
            ],
        ]);
        $testsTabBody = (string) ($testsTabGet['body'] ?? '');
        $this->assertSame(200, (int) ($testsTabGet['status'] ?? 0), (string) ($testsTabGet['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-setup-panel="tests"', $testsTabBody);
        $this->assertStringContainsString('Send live test digest', $testsTabBody);
        $this->assertStringContainsString('Digest worker', $testsTabBody);
        $this->assertStringContainsString('Inbound worker', $testsTabBody);

        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        marketplaceRenderEmailAssistantSetup([
            'active_tab' => 'identity',
            'settings' => [],
            'enabled' => true,
            'csrf' => 'csrf-workspace-skills',
            'installed' => true,
            'can_manage' => true,
            'readiness' => [],
            'platform' => [
                'gmail_configured' => true,
                'google_workspace_configured' => false,
            ],
        ]);
        $configuredProviderHtml = (string) ob_get_clean();
        $this->assertStringContainsString('Gmail platform app configured', $configuredProviderHtml);
        $this->assertStringNotContainsString('Google Workspace platform app: Not configured', $configuredProviderHtml);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'env' => [
                'APP_KEY' => 'workspace-skills-email-assistant-test-key',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'skill_action' => 'save_email_assistant_setup',
                'email_assistant_enabled' => '1',
                'email_assistant_system_email' => 'assistant@example.test',
                'email_assistant_from_email' => 'assistant@example.test',
                'email_assistant_from_name' => 'Workspace Assistant',
                'email_assistant_smtp_host' => 'smtp.example.test',
                'email_assistant_smtp_port' => '587',
                'email_assistant_smtp_user' => 'assistant@example.test',
                'email_assistant_smtp_pass' => 'smtp-secret',
                'email_assistant_smtp_encryption' => 'tls',
                'email_assistant_imap_enabled' => '1',
                'email_assistant_imap_host' => 'imap.example.test',
                'email_assistant_imap_port' => '993',
                'email_assistant_imap_user' => 'assistant@example.test',
                'email_assistant_imap_pass' => 'imap-secret',
                'email_assistant_imap_folder' => 'INBOX',
                'email_assistant_default_tone' => 'warm',
                'email_assistant_thread_context_window' => '8',
                'email_assistant_min_confidence' => '0.66',
                'email_assistant_min_send_confidence' => '0.81',
                'email_assistant_qa_enabled' => '1',
                'email_assistant_instructions_enabled' => '1',
                'email_assistant_customer_thread_enabled' => '1',
                'email_assistant_customer_send_enabled' => '1',
                'email_assistant_allow_clarifying_questions' => '1',
                'email_assistant_activity_logging' => '1',
                'email_assistant_skill_create_contact' => '1',
                'email_assistant_skill_update_contact' => '1',
                'email_assistant_skill_enrich_contact' => '1',
                'email_assistant_skill_add_note' => '1',
                'email_assistant_skill_get_pipeline' => '1',
                'email_assistant_skill_schedule_event' => '1',
                'email_assistant_digest_enabled' => '1',
                'email_assistant_digest_time' => '08:45',
                'email_assistant_digest_recipient_mode' => 'custom',
                'email_assistant_digest_custom_emails' => 'ops@example.test, owner@example.test',
                'email_assistant_reopen_template_enabled' => '1',
                'email_assistant_reopen_template_name' => 'customer_follow_up',
                'email_assistant_reopen_template_language' => 'en_US',
                'email_assistant_reopen_template_subject' => 'Following up',
                'email_assistant_reopen_template_body' => 'Hi {{name}}, following up on our previous conversation.',
                'email_assistant_reopen_template_cta_label' => 'Open CRM',
                'email_assistant_reopen_template_cta_url' => 'https://example.test/crm',
            ],
        ]);

        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        if ((int) ($post['status'] ?? 0) === 200) {
            $this->assertStringContainsString('Email Assistant setup saved.', (string) ($post['body'] ?? ''));
        }
        $config = (new WorkspaceAssistantConfigService())->get((int) $seed['workspace_id'], 'email', false);
        $settings = (array) ($config['settings'] ?? []);

        $this->assertTrue(!empty($config['enabled']));
        $this->assertSame('smtp.example.test', (string) ($settings['smtp_host'] ?? ''));
        $this->assertSame('assistant@example.test', (string) ($settings['smtp_username'] ?? ''));
        $this->assertSame('saved', (string) ($settings['smtp_password'] ?? ''));
        $this->assertSame('imap.example.test', (string) ($settings['imap_host'] ?? ''));
        $this->assertSame('saved', (string) ($settings['imap_password'] ?? ''));
        $this->assertTrue(!empty($settings['skill_create_contact']));
        $this->assertTrue(!empty($settings['skill_schedule_event']));
        $this->assertFalse(!empty($settings['skill_delete_contact']));
        $this->assertTrue(!empty($settings['digest_enabled']));
        $this->assertSame('08:45', (string) ($settings['digest_time'] ?? ''));
        $this->assertSame('ops@example.test, owner@example.test', (string) ($settings['digest_recipients'] ?? ''));
        $this->assertTrue(!empty($settings['reopen_template_enabled']));
        $this->assertSame('customer_follow_up', (string) ($settings['reopen_template_name'] ?? ''));
        $this->assertSame('Following up', (string) ($settings['reopen_template_subject'] ?? ''));
        $this->assertSame('Open CRM', (string) ($settings['reopen_template_cta_label'] ?? ''));

        $this->activateSession($seed, 'owner');
        $digestReadiness = (new EmailAssistantDigestService())->validateDigestConfig();
        $this->assertTrue(!empty($digestReadiness['outbound_ready']));
        $this->assertTrue(!empty($digestReadiness['digest_enabled']));
        $this->assertSame('08:45', (string) ($digestReadiness['send_time'] ?? ''));
    }

    public function testMarketplaceModulePageSavesSkillSetup(): void
    {
        $seed = $this->seedWorkspace('marketplace-skill-setup');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeFinanceSetup($seed);

        $leanPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                'skill_action' => 'save_lean_canvas_setup',
                'lean_problem' => 'Leads are scattered.',
                'lean_customer_segments' => 'Owner-led teams.',
                'lean_unique_value_proposition' => 'One operating memory.',
                'lean_solution' => 'Guided follow-up.',
                'lean_channels' => 'Email and WhatsApp.',
                'lean_revenue_streams' => 'Subscriptions.',
                'lean_cost_structure' => 'Support and software.',
                'lean_key_metrics' => 'Reply speed.',
                'lean_unfair_advantage' => 'Deep CRM context.',
            ],
        ]);
        $this->assertSame(200, (int) ($leanPost['status'] ?? 0), (string) ($leanPost['stderr'] ?? ''));
        $this->assertStringContainsString('Lean Canvas setup saved.', (string) ($leanPost['body'] ?? ''));

        $leanGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
        ]);
        $leanBody = (string) ($leanGet['body'] ?? '');
        $this->assertSame(200, (int) ($leanGet['status'] ?? 0), (string) ($leanGet['stderr'] ?? ''));
        $this->assertStringContainsString('What This Does', $leanBody);
        $this->assertStringContainsString('What You Need', $leanBody);
        $this->assertStringContainsString('Setup Steps', $leanBody);
        $this->assertStringContainsString('Lean Canvas owns the workspace business-model assumptions', $leanBody);
        $this->assertStringContainsString('Clarity Journey', $leanBody);
        $this->assertStringContainsString('Open Clarity Journey hub', $leanBody);
        $this->assertStringContainsString('Customer Discovery', $leanBody);

        $journeyGet = $this->runWebEndpoint('public/startup_journey.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $journeyBody = (string) ($journeyGet['body'] ?? '');
        $this->assertSame(200, (int) ($journeyGet['status'] ?? 0), (string) ($journeyGet['stderr'] ?? ''));
        $this->assertStringContainsString('Clarity Journey', $journeyBody);
        $this->assertStringContainsString('Customer Discovery', $journeyBody);
        $this->assertStringContainsString('Go-To-Market Strategy', $journeyBody);
        $this->assertStringContainsString('Inline coach', $journeyBody);

        $this->completeStartupJourneySetup($seed);
        $installerForMarketingGate = new WorkspaceSkillInstallService();
        $installerForMarketingGate->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, (int) $seed['user_id']);
        $this->completeAiCoachOnboarding($seed);

        $marketerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                'skill_action' => 'save_professional_marketer_setup',
                'target_market_focus' => 'Service operators',
                'ideal_customer_profile' => 'Small teams with messy inboxes',
                'offer_angle' => 'Reliable follow-up',
                'segment_focus' => 'CRM-heavy businesses',
                'sales_motion' => 'Consultative',
                'deal_movement_strategy' => 'Nurture stalled leads',
                'outreach_posture' => 'Helpful and specific',
                'positioning_notes' => 'Operational clarity',
            ],
        ]);
        $this->assertSame(200, (int) ($marketerPost['status'] ?? 0), (string) ($marketerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Marketing Assistants setup saved.', (string) ($marketerPost['body'] ?? ''));

        $profile = (new UserStrategyProfile())->get((int) $seed['user_id']) ?: [];
        $this->assertSame('Service operators', (string) ($profile['target_market_focus'] ?? ''));
        $this->assertTrue((new WorkspaceSkillInstallService())->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
        $this->assertTrue((new WorkspaceSkillInstallService())->isInstalled((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER));
    }

    public function testMarketplaceSkillReadinessSeparatesLeanCanvasAndAiCoachRoles(): void
    {
        $seed = $this->seedWorkspace('marketplace-role-readiness');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeFinanceSetup($seed);

        $installer = new WorkspaceSkillInstallService();
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);

        (new UserStrategyProfile())->save((int) $seed['user_id'], [
            'lean_problem' => 'Leads are scattered.',
        ]);
        $leanIncomplete = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
        $this->assertSame('needs_setup', (string) ($leanIncomplete['status'] ?? ''));
        $this->assertFalse((bool) ($leanIncomplete['ready'] ?? true));
        $this->assertContains('Customer Discovery', (array) ($leanIncomplete['blockers'] ?? []));
        $this->assertStringContainsString('Clarity Journey', (string) ($leanIncomplete['checks'][0]['label'] ?? ''));

        (new UserStrategyProfile())->save((int) $seed['user_id'], [
            'lean_problem' => 'Leads are scattered.',
            'lean_customer_segments' => 'Owner-led teams.',
            'lean_unique_value_proposition' => 'One operating memory.',
            'lean_solution' => 'Guided follow-up.',
            'lean_channels' => 'Email and WhatsApp.',
            'lean_revenue_streams' => 'Subscriptions.',
            'lean_cost_structure' => 'Support and software.',
            'lean_key_metrics' => 'Reply speed.',
            'lean_unfair_advantage' => 'Deep CRM context.',
        ]);
        $leanReady = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
        $this->assertSame('needs_setup', (string) ($leanReady['status'] ?? ''));
        $this->assertFalse((bool) ($leanReady['ready'] ?? true));

        $journeyService = new \CRM\Services\StartupJourneyService();
        foreach ($journeyService->stageDefinitions() as $stageKey => $definition) {
            $responses = [];
            foreach ((array) ($definition['fields'] ?? []) as $fieldKey => $label) {
                $responses[$fieldKey] = (string) $label . ' answer';
            }
            $journeyService->saveStage((int) $seed['workspace_id'], (int) $seed['user_id'], (string) $stageKey, $responses, 'Evidence captured.', true);
        }

        $installer = new WorkspaceSkillInstallService();
        $leanReady = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
        $this->assertSame('ready', (string) ($leanReady['status'] ?? ''));
        $this->assertTrue((bool) ($leanReady['ready'] ?? false));

        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, (int) $seed['user_id']);
        (new AICoachWorkspaceSetupService($installer))->setWorkspaceEnabled((int) $seed['workspace_id'], (int) $seed['user_id'], false);
        $aiDisabled = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH);
        $this->assertSame('disabled', (string) ($aiDisabled['status'] ?? ''));
        $this->assertFalse((bool) ($aiDisabled['ready'] ?? true));
        $this->assertContains('Enabled for workspace', (array) ($aiDisabled['blockers'] ?? []));

        (new AICoachWorkspaceSetupService($installer))->setWorkspaceEnabled((int) $seed['workspace_id'], (int) $seed['user_id'], true);
        $installer = new WorkspaceSkillInstallService();
        $aiReady = $installer->buildReadinessForModule((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH);
        $this->assertSame('ready', (string) ($aiReady['status'] ?? ''));
        $this->assertTrue((bool) ($aiReady['ready'] ?? false));
        $this->assertContains('Clarity Journey', (array) ($aiReady['ready_advice_skills'] ?? []));
    }

    public function testPhaseOneModulesInstallAndRenderMarketplaceSetupSurfaces(): void
    {
        $seed = $this->seedWorkspace('marketplace-phase-one');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        $this->completeFinanceSetup($seed);
        $phaseInstaller = new WorkspaceSkillInstallService();
        $phaseInstaller->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeStartupJourneySetup($seed);

        foreach ([WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS, WorkspaceSkillCatalogService::SKILL_AI_COACH] as $skillKey) {
            $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'install',
                ],
            ]);
            $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
            $this->assertTrue((new WorkspaceSkillInstallService())->isInstalled((int) $seed['workspace_id'], $skillKey));
        }

        $sms = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 'full_setup' => '1'],
        ]);
        $smsBody = (string) ($sms['body'] ?? '');
        $this->assertSame(200, (int) ($sms['status'] ?? 0), (string) ($sms['stderr'] ?? ''));
        $this->assertStringContainsString('SMS Channel', $smsBody);
        $this->assertStringContainsString('Twilio account SID', $smsBody);
        $this->assertStringContainsString('Save SMS setup', $smsBody);
        $this->assertStringContainsString('data-marketplace-module-tab="health"', $smsBody);

        $calendar = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS, 'full_setup' => '1', 'setup_tab' => 'calendar'],
        ]);
        $calendarBody = (string) ($calendar['body'] ?? '');
        $this->assertSame(200, (int) ($calendar['status'] ?? 0), (string) ($calendar['stderr'] ?? ''));
        $this->assertStringContainsString('Calendar &amp; Meetings', $calendarBody);
        $this->assertStringContainsString('Connect or manage calendars', $calendarBody);
        $this->assertStringContainsString('Save calendar setting', $calendarBody);

        $calendarBot = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS, 'full_setup' => '1', 'setup_tab' => 'bot'],
        ]);
        $calendarBotBody = (string) ($calendarBot['body'] ?? '');
        $this->assertSame(200, (int) ($calendarBot['status'] ?? 0), (string) ($calendarBot['stderr'] ?? ''));
        $this->assertStringContainsString('Enable meeting bot', $calendarBotBody);
        $this->assertStringContainsString('Save meeting bot setup', $calendarBotBody);

        $aiCoachSave = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_AI_COACH],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                'skill_action' => 'save_ai_coach_setup',
                'ai_coach_enabled' => '1',
            ],
        ]);
        $this->assertSame(200, (int) ($aiCoachSave['status'] ?? 0), (string) ($aiCoachSave['stderr'] ?? ''));
        $this->assertStringContainsString('AI Coach workspace setup saved.', (string) ($aiCoachSave['body'] ?? ''));
        $this->assertTrue((new AICoachWorkspaceSetupService())->isWorkspaceEnabled((int) $seed['workspace_id']));

        $aiCoachGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_AI_COACH, 'full_setup' => '1'],
        ]);
        $aiCoachBody = (string) ($aiCoachGet['body'] ?? '');
        $this->assertSame(200, (int) ($aiCoachGet['status'] ?? 0), (string) ($aiCoachGet['stderr'] ?? ''));
        $this->assertStringContainsString('Enable AI Coach for this workspace', $aiCoachBody);
        $this->assertStringContainsString('Team Context', $aiCoachBody);
        $this->assertStringContainsString('Personal Strategy', $aiCoachBody);
        $this->assertStringNotContainsString('Team Briefs', $aiCoachBody);
        $this->assertStringNotContainsString('My Coach Brief', $aiCoachBody);
        $this->assertStringContainsString('Ready advice skills', $aiCoachBody);

        $context = (new WorkspaceSkillInstallService())->buildContextForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (array) ($context['plugin_keys'] ?? []));
        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS, (array) ($context['plugin_keys'] ?? []));
        $this->assertContains(WorkspaceSkillCatalogService::SKILL_AI_COACH, (array) ($context['skill_keys'] ?? []));
    }

    public function testSmsMarketplaceSetupSavesWorkspaceScopedConfigOnly(): void
    {
        $first = $this->seedWorkspace('marketplace-sms-config-one');
        $second = $this->seedWorkspace('marketplace-sms-config-two');
        $this->activateSession($first, 'owner');
        $this->completeOnboarding((int) $first['workspace_id']);
        $this->completeOnboarding((int) $second['workspace_id']);

        $installer = new WorkspaceSkillInstallService();
        $installer->install((int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $first['user_id']);
        $installer->install((int) $second['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $second['user_id']);

        $originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-skills-sms-config-test-key';
        $uniqueToken = 'workspace-sms-token-' . bin2hex(random_bytes(5));
        $envFile = dirname(__DIR__, 2) . '/.env';
        $envBefore = file_exists($envFile) ? (string) file_get_contents($envFile) : '';

        try {
            $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($first, 'owner'), [
                'method' => 'POST',
                'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
                'env' => [
                    'APP_KEY' => 'workspace-skills-sms-config-test-key',
                    'TWILIO_ACCOUNT_SID' => '',
                    'TWILIO_AUTH_TOKEN' => '',
                    'TWILIO_FROM_NUMBER' => '',
                ],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                    'skill_action' => 'save_sms_channel_setup',
                    'sms_channel_enabled' => '1',
                    'sms_twilio_account_sid' => 'ACworkspacefirst',
                    'sms_twilio_auth_token' => $uniqueToken,
                    'sms_twilio_from_number' => '+15550000001',
                    'sms_webhook_enabled' => '1',
                    'sms_status_callbacks_enabled' => '1',
                ],
            ]);

            $body = (string) ($post['body'] ?? '');
            $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
            $this->assertStringContainsString('SMS Channel setup saved.', $body);
            $this->assertStringContainsString('ACworkspacefirst', $body);
            $this->assertStringContainsString('+15550000001', $body);
            $this->assertStringContainsString('Saved token', $body);
            $this->assertStringNotContainsString($uniqueToken, $body);

            $config = (new WorkspaceSmsChannelConfigService())->get((int) $first['workspace_id'], true);
            $row = Database::queryOne(
                "SELECT encrypted_auth_token
                 FROM workspace_sms_channel_configs
                 WHERE workspace_id = ?",
                [(int) $first['workspace_id']]
            );
            $envAfter = file_exists($envFile) ? (string) file_get_contents($envFile) : '';
            $this->assertSame($envBefore, $envAfter);
            $this->assertSame('ACworkspacefirst', (string) ($config['account_sid'] ?? ''));
            $this->assertSame($uniqueToken, (string) ($config['auth_token'] ?? ''));
            $this->assertNotSame($uniqueToken, (string) ($row['encrypted_auth_token'] ?? ''));
            $firstContext = (new WorkspaceSkillInstallService())->buildContextForWorkspace((int) $first['workspace_id'], (int) $first['user_id']);
            $this->assertTrue((bool) ($firstContext['readiness'][WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]['ready'] ?? false));

            $secondGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($second, 'owner'), [
                'method' => 'GET',
                'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
                'env' => [
                    'APP_KEY' => 'workspace-skills-sms-config-test-key',
                    'TWILIO_ACCOUNT_SID' => '',
                    'TWILIO_AUTH_TOKEN' => '',
                    'TWILIO_FROM_NUMBER' => '',
                ],
            ]);
            $this->assertSame(200, (int) ($secondGet['status'] ?? 0), (string) ($secondGet['stderr'] ?? ''));
            $this->assertStringNotContainsString('ACworkspacefirst', (string) ($secondGet['body'] ?? ''));
            $this->assertStringNotContainsString('+15550000001', (string) ($secondGet['body'] ?? ''));
            $secondContext = (new WorkspaceSkillInstallService())->buildContextForWorkspace((int) $second['workspace_id'], (int) $second['user_id']);
            $this->assertFalse((bool) ($secondContext['readiness'][WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]['ready'] ?? true));
        } finally {
            if ($originalAppKey === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $originalAppKey;
            }
        }
    }

    public function testMarketplaceReadinessChecksAreNonDeliveryByDefault(): void
    {
        $seed = $this->seedWorkspace('marketplace-readiness-check');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);

        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
            'env' => [
                'TWILIO_ACCOUNT_SID' => '',
                'TWILIO_AUTH_TOKEN' => '',
                'TWILIO_FROM_NUMBER' => '',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                'skill_action' => 'run_marketplace_readiness_check',
            ],
        ]);

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Readiness check finished.', (string) ($post['body'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_attempted'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_failed'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));
    }

    public function testLiveMarketplaceTestsRequireExplicitRecipients(): void
    {
        $seed = $this->seedWorkspace('marketplace-live-test-recipient');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, (int) $seed['user_id']);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'skill_action' => 'send_email_assistant_test_digest',
            ],
        ]);

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Enter an explicit test recipient email before sending a live test digest.', (string) ($post['body'] ?? ''));

        $smsPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
            'env' => [
                'TWILIO_ACCOUNT_SID' => '',
                'TWILIO_AUTH_TOKEN' => '',
                'TWILIO_FROM_NUMBER' => '',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                'skill_action' => 'send_sms_channel_test',
            ],
        ]);

        $this->assertSame(200, (int) ($smsPost['status'] ?? 0), (string) ($smsPost['stderr'] ?? ''));
        $this->assertStringContainsString('Enter an explicit test phone number before sending a live SMS test.', (string) ($smsPost['body'] ?? ''));
    }

    public function testLegacyEmailAssistantSettingsRedirectToMarketplacePlugin(): void
    {
        $seed = $this->seedWorkspace('legacy-email-assistant-settings-redirect');
        $this->activateSession($seed, 'owner');

        $response = $this->runWebEndpoint('public/settings.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['tab' => 'email_assistant'],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $settingsSource = (string) file_get_contents(__DIR__ . '/../../public/settings.php');
        $this->assertStringContainsString(
            "'email_assistant' => 'workspace_skills.php?module=email_assistant&setup_tab=identity&legacy_settings_redirect=1#setup'",
            $settingsSource
        );
        $this->assertStringNotContainsString('name="email_assistant_enabled"', $settingsSource);
        $this->assertStringNotContainsString("elseif (\$tab === 'email_assistant')", $settingsSource);
    }

    public function testWhatsAppMarketplaceTestDigestRejectsCrossWorkspaceAuthorizedNumber(): void
    {
        $first = $this->seedWorkspace('marketplace-wa-digest-first');
        $second = $this->seedWorkspace('marketplace-wa-digest-second');
        $this->activateSession($first, 'owner');
        $this->completeOnboarding((int) $first['workspace_id']);
        $this->completeCommunicationSetup($first);
        $this->seedReadyWhatsAppChannel($first);

        (new WorkspaceSkillInstallService())->install((int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (int) $first['user_id']);
        Database::execute(
            "INSERT INTO workspace_assistant_configs (workspace_id, assistant_type, enabled, settings_json, created_by_user_id, updated_by_user_id)
             VALUES (?, 'whatsapp', 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = 1,
                settings_json = VALUES(settings_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                (int) $first['workspace_id'],
                json_encode([
                    'assistant_phone_number_id' => 'first-phone-number-id',
                    'access_token' => 'first-access-token',
                    'digest_enabled' => true,
                    'digest_time' => '07:00',
                    'auto_reopen_enabled' => false,
                ], JSON_UNESCAPED_SLASHES),
                (int) $first['user_id'],
                (int) $first['user_id'],
            ]
        );

        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers
                (workspace_id, phone_number, user_id, label, is_active, digest_enabled, last_used_at)
             VALUES (?, ?, ?, 'Second workspace recipient', 1, 1, NOW())",
            [(int) $second['workspace_id'], '15551112222', (int) $second['user_id']]
        );

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($first, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT],
            'headers' => ['ACCEPT' => 'application/json'],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'send_whatsapp_assistant_test_digest',
                'whatsapp_test_recipient' => '+1 (555) 111-2222',
            ],
        ]);
        $payload = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(422, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertFalse((bool) ($payload['success'] ?? true));
        $this->assertSame('That WhatsApp test number is not in the active authorized assistant list.', (string) ($payload['message'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_passed'",
            [(int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_failed'",
            [(int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        )['c'] ?? 0));
    }

    public function testMarketplaceOwnerCanSaveWhatsappAssistantActionControlsIndependently(): void
    {
        $seed = $this->seedWorkspace('marketplace-wa-actions');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        $this->seedReadyWhatsAppChannel($seed);
        (new WorkspaceSkillInstallService())->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            (int) $seed['user_id']
        );
        (new WorkspaceAssistantConfigService())->save((int) $seed['workspace_id'], 'email', [
            'instructions_enabled' => false,
            'skill_create_contact' => false,
        ], true, (int) $seed['user_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'setup_tab' => 'advanced',
            ],
        ]);
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('name="whatsapp_assistant_skill_create_contact"', (string) ($get['body'] ?? ''));

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'save_whatsapp_assistant_setup',
                'whatsapp_assistant_setup_tab' => 'advanced',
                'whatsapp_assistant_enabled' => '1',
                'whatsapp_assistant_qa_enabled' => '1',
                'whatsapp_assistant_instructions_enabled' => '1',
                'whatsapp_assistant_skill_create_contact' => '1',
                'whatsapp_assistant_skill_run_report' => '1',
            ],
        ]);

        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $whatsapp = (new WorkspaceAssistantConfigService())->get((int) $seed['workspace_id'], 'whatsapp', false);
        $email = (new WorkspaceAssistantConfigService())->get((int) $seed['workspace_id'], 'email', false);
        $whatsappSettings = (array) ($whatsapp['settings'] ?? []);
        $emailSettings = (array) ($email['settings'] ?? []);

        $this->assertTrue(!empty($whatsapp['enabled']));
        $this->assertTrue(!empty($whatsappSettings['qa_enabled']));
        $this->assertTrue(!empty($whatsappSettings['instructions_enabled']));
        $this->assertTrue(!empty($whatsappSettings['skill_create_contact']));
        $this->assertTrue(!empty($whatsappSettings['skill_run_report']));
        $this->assertFalse(!empty($emailSettings['skill_create_contact']));
        $this->assertFalse(!empty($emailSettings['instructions_enabled']));
    }

    public function testSmsMarketplaceLiveTestUsesWorkspaceReadinessAndConfig(): void
    {
        $seed = $this->seedWorkspace('marketplace-sms-live-test');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);

        $env = [
            'APP_KEY' => 'workspace-skills-sms-live-test-key',
            'TWILIO_ACCOUNT_SID' => '',
            'TWILIO_AUTH_TOKEN' => '',
            'TWILIO_FROM_NUMBER' => '',
        ];

        $notReady = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                'skill_action' => 'send_sms_channel_test',
                'sms_test_recipient' => '+15551234567',
            ],
        ]);

        $this->assertSame(200, (int) ($notReady['status'] ?? 0), (string) ($notReady['stderr'] ?? ''));
        $this->assertStringContainsString('SMS Channel needs workspace Twilio credentials, sender number, and enablement.', (string) ($notReady['body'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_attempted'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_failed'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));

        $originalAppKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'workspace-skills-sms-live-test-key';
        try {
            (new WorkspaceSmsChannelConfigService())->save((int) $seed['workspace_id'], [
                'enabled' => true,
                'account_sid' => 'ACsmslivetest',
                'auth_token' => 'sms-live-test-token',
                'from_number' => '+15550000001',
            ], (int) $seed['user_id']);
            Database::execute("UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1");

            $ready = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
                'method' => 'POST',
                'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL],
                'env' => $env,
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                    'skill_action' => 'send_sms_channel_test',
                    'sms_test_recipient' => '+15551234567',
                ],
            ]);
        } finally {
            Database::execute("UPDATE demo_mode_state SET is_enabled = 0, simulation_only = 1 WHERE id = 1");
            if ($originalAppKey === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $originalAppKey;
            }
        }

        $this->assertSame(200, (int) ($ready['status'] ?? 0), (string) ($ready['stderr'] ?? ''));
        $this->assertStringContainsString('SMS test sent.', (string) ($ready['body'] ?? ''));
        $this->assertSame(2, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_attempted'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND skill_key = ? AND event_type = 'test_passed'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        )['c'] ?? 0));
    }

    public function testInstalledFirstRuntimePagesAndApis(): void
    {
        $seed = $this->seedWorkspace('marketplace-runtime-gates');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);

        $smsNotReadyEnv = [
            'TWILIO_ACCOUNT_SID' => '',
            'TWILIO_AUTH_TOKEN' => '',
            'TWILIO_FROM_NUMBER' => '',
        ];
        $smsPage = $this->runWebEndpoint('public/bulk_sms.php', $this->webSession($seed, 'owner'), ['method' => 'GET', 'env' => $smsNotReadyEnv]);
        $this->assertSame(302, (int) ($smsPage['status'] ?? 0), (string) ($smsPage['stderr'] ?? ''));

        $smsApi = $this->runWebEndpoint('api/bulk_messaging.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'env' => $smsNotReadyEnv,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'action' => 'send_sms',
                'message' => 'Hello',
            ],
        ]);
        $this->assertSame(403, (int) ($smsApi['status'] ?? 0), (string) ($smsApi['body'] ?? ''));
        $this->assertStringContainsString('SMS Channel is not installed', (string) ($smsApi['body'] ?? ''));

        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);
        $installedSmsPage = $this->runWebEndpoint('public/bulk_sms.php', $this->webSession($seed, 'owner'), ['method' => 'GET', 'env' => $smsNotReadyEnv]);
        $this->assertSame(302, (int) ($installedSmsPage['status'] ?? 0), (string) ($installedSmsPage['stderr'] ?? ''));
    }

    public function testInboxSmsComposerActionFollowsSmsPluginInstallState(): void
    {
        $seed = $this->seedWorkspace('marketplace-inbox-sms-action');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);

        $runtimeReadyEnv = [
            'SMTP_HOST' => 'smtp.example.test',
            'SMTP_USER' => 'sender@example.test',
            'TWILIO_ACCOUNT_SID' => '',
            'TWILIO_AUTH_TOKEN' => '',
            'TWILIO_FROM_NUMBER' => '',
        ];
        $withoutSms = $this->runWebEndpoint('public/inbox.php', $this->webSession($seed, 'owner'), ['method' => 'GET', 'env' => $runtimeReadyEnv]);
        $this->assertSame(200, (int) ($withoutSms['status'] ?? 0), (string) ($withoutSms['stderr'] ?? ''));
        $this->assertStringNotContainsString('title="Compose SMS"', (string) ($withoutSms['body'] ?? ''));

        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);

        $withSms = $this->runWebEndpoint('public/inbox.php', $this->webSession($seed, 'owner'), ['method' => 'GET', 'env' => $runtimeReadyEnv]);
        $this->assertSame(200, (int) ($withSms['status'] ?? 0), (string) ($withSms['stderr'] ?? ''));
        $this->assertStringNotContainsString('href="bulk_sms.php"', (string) ($withSms['body'] ?? ''));
        $this->assertStringNotContainsString('title="Compose SMS"', (string) ($withSms['body'] ?? ''));
    }

    public function testCommunicationRuntimeGateLocksInboxUntilEmailOrWhatsappIsReady(): void
    {
        $seed = $this->seedWorkspace('communication-runtime-gate');
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $lockedEnv = [
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ];
        $locked = $this->runWebEndpoint('public/inbox.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'env' => $lockedEnv,
        ]);
        if ((int) ($locked['status'] ?? 0) === 302) {
            $api = $this->runWebEndpoint('api/inbox.php', $this->webSession($seed, 'owner'), [
                'method' => 'GET',
                'env' => $lockedEnv,
            ]);
            $this->assertSame(403, (int) ($api['status'] ?? 0), (string) ($api['body'] ?? ''));
            $this->assertStringContainsString('communication_setup_required', (string) ($api['body'] ?? ''));
        } else {
            $this->assertSame(200, (int) ($locked['status'] ?? 0), (string) ($locked['stderr'] ?? ''));
            $this->assertStringContainsString('Inbox', (string) ($locked['body'] ?? ''));
        }

        $envOnly = $this->runWebEndpoint('public/inbox.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'env' => ['SMTP_HOST' => 'smtp.example.test', 'SMTP_USER' => 'sender@example.test', 'SMTP_PASS' => 'secret'],
        ]);
        $this->assertSame(302, (int) ($envOnly['status'] ?? 0), 'SMTP .env values must not unlock owner-facing inbox readiness.');

        $this->completeCommunicationSetup($seed);
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'sender@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'sender@example.test',
            'imap_password' => 'imap-secret',
        ], (int) $seed['user_id']);

        $ready = $this->runWebEndpoint('public/inbox.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($ready['status'] ?? 0), json_encode($ready, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('Inbox', (string) ($ready['body'] ?? ''));
    }

    public function testHrAnalyticsRuntimeGateRedirectsOwnersToSetupCenterUntilReady(): void
    {
        $seed = $this->seedWorkspace('hr-analytics-owner-gate');
        $this->grantRolePermissions('owner', ['hr.analytics.view', 'hr.analytics.settings', 'org.departments.manage', 'admin.users.manage']);
        $this->activateSession($seed, 'owner');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->ensureHrAnalyticsSetupState((int) $seed['workspace_id'], false);

        $locked = $this->runWebEndpoint('public/hr_analytics.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(302, (int) ($locked['status'] ?? 0), (string) ($locked['stderr'] ?? ''));
    }

    public function testHrAnalyticsRuntimeGateShowsOwnerMessageForNonOwner(): void
    {
        $seed = $this->seedWorkspace('hr-analytics-nonowner-gate');
        $this->grantRolePermissions('viewer', ['hr.analytics.view']);
        $viewerSeed = $this->addWorkspaceViewer($seed, 'hr-viewer@example.test');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->ensureHrAnalyticsSetupState((int) $seed['workspace_id'], false);

        $locked = $this->runWebEndpoint('public/hr_analytics.php', $this->webSession($viewerSeed, 'viewer'), ['method' => 'GET']);
        $this->assertSame(403, (int) ($locked['status'] ?? 0), (string) ($locked['stderr'] ?? ''));
        $this->assertStringContainsString('Organization Intelligence setup required', (string) ($locked['body'] ?? ''));
        $this->assertStringContainsString('workspace owner', (string) ($locked['body'] ?? ''));
    }

    public function testHrAnalyticsApiEndpointsBlockUntilSetupIsReady(): void
    {
        $seed = $this->seedWorkspace('hr-analytics-api-gate');
        $this->grantRolePermissions('owner', ['hr.analytics.view', 'hr.analytics.manage', 'hr.analytics.settings', 'admin.users.manage']);
        $this->activateSession($seed, 'owner');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->ensureHrAnalyticsSetupState((int) $seed['workspace_id'], false);

        foreach ([
            'api/hr/summary.php',
            'api/hr/swot.php',
            'api/hr/tips.php',
            'api/hr/actions.php',
        ] as $endpoint) {
            $locked = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'owner'), ['method' => 'GET']);
            $this->assertSame(403, (int) ($locked['status'] ?? 0), $endpoint . ': ' . (string) ($locked['stderr'] ?? ''));
            $payload = $this->decodeJsonResponse($locked);
            $this->assertFalse((bool) ($payload['success'] ?? true), $endpoint);
            $this->assertSame('hr_analytics_setup_required', (string) ($payload['error_code'] ?? ''), $endpoint);
            $this->assertTrue((bool) ($payload['setup_required'] ?? false), $endpoint);
            $this->assertSame('organization_intelligence_setup.php?setup_required=hr_analytics', (string) ($payload['setup_required_url'] ?? ''), $endpoint);
        }

        $blockedEvent = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_plugin_runtime_events
             WHERE workspace_id = ?
               AND skill_key = ?
               AND event_type = 'readiness_blocked'",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        $this->assertGreaterThanOrEqual(4, (int) ($blockedEvent['c'] ?? 0));
    }

    public function testSuperadminDefaultWorkspaceBypassesOrganizationIntelligencePlanAndSetupGates(): void
    {
        $seed = $this->defaultWorkspaceUserSeed('hr-default-superadmin-bypass', 'superadmin', true);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->activateSession($seed, 'superadmin');
        $this->forceCompassFreeEntitlements($workspaceId);
        $this->ensureHrAnalyticsSetupState($workspaceId, false);
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins($workspaceId, $userId);

        $access = (new WorkspaceMarketplaceAccessService())->accessForModule(
            $workspaceId,
            $userId,
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
        );
        $this->assertTrue((bool) ($access['admin_bypass'] ?? false), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($access['is_locked'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertNotSame(WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN, (string) ($access['state'] ?? ''));
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP, (string) ($access['state'] ?? ''));
        $this->assertFalse((bool) ($access['readiness']['ready'] ?? true), json_encode($access, JSON_PRETTY_PRINT));

        $marketplace = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP],
        ]);
        $marketplaceBody = (string) ($marketplace['body'] ?? '');
        $this->assertSame(200, (int) ($marketplace['status'] ?? 0), (string) ($marketplace['stderr'] ?? ''));
        $this->assertStringContainsString('marketplace-module-page', $marketplaceBody);
        $this->assertStringContainsString('Superadmin default workspace access bypasses package and prerequisite gates.', $marketplaceBody);
        $this->assertStringContainsString('Customer package and setup status are unchanged.', $marketplaceBody);

        foreach ([
            'public/hr_analytics.php' => 'Organization Intelligence',
            'public/analytics.php' => 'Analytics',
            'public/organization_intelligence_setup.php' => 'Organization Intelligence',
        ] as $endpoint => $expectedText) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'superadmin'), ['method' => 'GET']);
            $this->assertSame(200, (int) ($response['status'] ?? 0), $endpoint . ': ' . (string) ($response['stderr'] ?? ''));
            $this->assertStringContainsString($expectedText, (string) ($response['body'] ?? ''), $endpoint);
        }

        $summary = $this->runWebEndpoint('api/hr/summary.php', $this->webSession($seed, 'superadmin'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($summary['status'] ?? 0), (string) ($summary['stderr'] ?? ''));
        $this->assertStringNotContainsString('hr_analytics_setup_required', (string) ($summary['body'] ?? ''));
        $payload = $this->decodeJsonResponse($summary);
        $this->assertTrue((bool) ($payload['success'] ?? false), json_encode($payload, JSON_PRETTY_PRINT));

        $gateStatus = (new \CRM\Services\WorkspaceHRAnalyticsGateService())->status($workspaceId, Database::queryOne("SELECT * FROM users WHERE id = ?", [$userId]) ?: []);
        $this->assertFalse((bool) ($gateStatus['setup_ready'] ?? true), json_encode($gateStatus, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($gateStatus['runtime_ready'] ?? false), json_encode($gateStatus, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($gateStatus['admin_bypass'] ?? false), json_encode($gateStatus, JSON_PRETTY_PRINT));
    }

    public function testDefaultWorkspaceOwnerBypassesOrganizationIntelligencePlanGateButStillNeedsSetup(): void
    {
        $seed = $this->defaultWorkspaceUserSeed('hr-default-owner-plan-gate', 'owner', false);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->grantRolePermissions('owner', ['workspace.skills.view', 'workspace.skills.manage', 'hr.analytics.view', 'hr.analytics.settings']);
        $this->activateSession($seed, 'owner');
        $this->forceCompassFreeEntitlements($workspaceId);
        $this->ensureHrAnalyticsSetupState($workspaceId, false);
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins($workspaceId, $userId);

        $access = (new WorkspaceMarketplaceAccessService())->accessForModule(
            $workspaceId,
            $userId,
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
        );
        $this->assertFalse((bool) ($access['admin_bypass'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($access['is_locked'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP, (string) ($access['state'] ?? ''), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($access['can_run'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($access['readiness']['ready'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertNotSame(WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN, (string) ($access['state'] ?? ''), json_encode($access, JSON_PRETTY_PRINT));

        $locked = $this->runWebEndpoint('public/hr_analytics.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(302, (int) ($locked['status'] ?? 0), (string) ($locked['stderr'] ?? ''));
        $this->assertStringNotContainsString('billing_payment_required.php', (string) ($locked['headers']['Location'] ?? '') . (string) ($locked['body'] ?? ''));
    }

    public function testSuperadminTenantWorkspaceStillGetsOrganizationIntelligencePlanGate(): void
    {
        $seed = $this->seedWorkspace('hr-tenant-superadmin-plan-gate');
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'superadmin', (int) $seed['user_id']);
        $this->activateSession($seed, 'superadmin');
        $this->forceCompassFreeEntitlements((int) $seed['workspace_id']);
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins((int) $seed['workspace_id'], (int) $seed['user_id']);

        $access = (new WorkspaceMarketplaceAccessService())->accessForModule(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
        );

        $this->assertFalse((bool) ($access['admin_bypass'] ?? true), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($access['is_locked'] ?? false), json_encode($access, JSON_PRETTY_PRINT));
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN, (string) ($access['state'] ?? ''));
    }

    public function testCompassFreeTenantWorkspaceBlocksBusinessIntelligenceSurfaces(): void
    {
        $seed = $this->seedWorkspace('bi-compass-free-gate');
        $this->grantRolePermissions('owner', ['hr.analytics.view']);
        $this->activateSession($seed, 'owner');
        $this->forceCompassFreeEntitlements((int) $seed['workspace_id']);

        foreach (['public/analytics.php', 'public/predictive_analytics.php'] as $endpoint) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'owner'), ['method' => 'GET']);
            $this->assertSame(302, (int) ($response['status'] ?? 0), $endpoint . ': ' . (string) ($response['stderr'] ?? ''));
        }

        foreach ([
            'api/analytics/operating_snapshot.php' => 'Business Intelligence',
            'api/predictive_analytics.php' => 'Predictive Analytics',
            'api/hr/summary.php' => 'Organization Intelligence',
        ] as $endpoint => $feature) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'owner'), [
                'method' => 'GET',
                'query' => $endpoint === 'api/predictive_analytics.php' ? ['action' => 'dashboard'] : [],
            ]);
            $this->assertSame(402, (int) ($response['status'] ?? 0), $endpoint . ': ' . (string) ($response['stderr'] ?? ''));
            $payload = $this->decodeJsonResponse($response);
            $this->assertFalse((bool) ($payload['success'] ?? true), $endpoint);
            $this->assertSame('business_intelligence_required', (string) ($payload['error_code'] ?? ''), $endpoint);
            $this->assertTrue((bool) ($payload['billing_required'] ?? false), $endpoint);
            $this->assertSame($feature, (string) ($payload['feature'] ?? ''), $endpoint);
            $this->assertSame('billing_payment_required.php?tab=packages#workspace-packages', (string) ($payload['action_url'] ?? ''), $endpoint);
        }
    }

    public function testFounderPlusTenantWorkspacePassesBusinessIntelligenceGate(): void
    {
        $seed = $this->seedWorkspace('bi-founder-plus-gate');
        $this->activateSession($seed, 'owner');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $analytics = $this->runWebEndpoint('api/analytics/operating_snapshot.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($analytics['status'] ?? 0), (string) ($analytics['stderr'] ?? ''));
        $analyticsPayload = $this->decodeJsonResponse($analytics);
        $this->assertTrue((bool) ($analyticsPayload['success'] ?? false), json_encode($analyticsPayload, JSON_PRETTY_PRINT));
        $this->assertNotSame('business_intelligence_required', (string) ($analyticsPayload['error_code'] ?? ''));

        $predictive = $this->runWebEndpoint('api/predictive_analytics.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['action' => 'dashboard'],
        ]);
        $this->assertSame(200, (int) ($predictive['status'] ?? 0), (string) ($predictive['stderr'] ?? ''));
        $predictivePayload = $this->decodeJsonResponse($predictive);
        $this->assertArrayHasKey('summary', $predictivePayload);

        $analyticsPage = $this->runWebEndpoint('public/analytics.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($analyticsPage['status'] ?? 0), (string) ($analyticsPage['stderr'] ?? ''));
        $this->assertStringContainsString('Business Intelligence', (string) ($analyticsPage['body'] ?? ''));
    }

    public function testOrganizationIntelligenceStillRequiresSetupAfterBusinessIntelligenceGatePasses(): void
    {
        $seed = $this->seedWorkspace('bi-hr-layering-gate');
        $this->grantRolePermissions('owner', ['hr.analytics.view', 'hr.analytics.settings', 'admin.users.manage']);
        $this->activateSession($seed, 'owner');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->ensureHrAnalyticsSetupState((int) $seed['workspace_id'], false);

        $web = $this->runWebEndpoint('public/hr_analytics.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(302, (int) ($web['status'] ?? 0), (string) ($web['stderr'] ?? ''));

        $api = $this->runWebEndpoint('api/hr/summary.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(403, (int) ($api['status'] ?? 0), (string) ($api['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($api);
        $this->assertSame('hr_analytics_setup_required', (string) ($payload['error_code'] ?? ''));
        $this->assertTrue((bool) ($payload['setup_required'] ?? false));
    }

    public function testPlanLockedMarketplaceCardsHydratePaidSubscriptionRequirementPill(): void
    {
        $seed = $this->seedWorkspace('marketplace-paid-subscription-pill');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->activateSession($seed, 'owner');
        $this->forceCompassFreeEntitlements($workspaceId);
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins($workspaceId, $userId);

        $accessService = new WorkspaceMarketplaceAccessService();
        $hrSetupAccess = $accessService->accessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP);
        $aiApiAccess = $accessService->accessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_AI_API);
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN, (string) ($hrSetupAccess['state'] ?? ''), json_encode($hrSetupAccess, JSON_PRETTY_PRINT));
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN, (string) ($aiApiAccess['state'] ?? ''), json_encode($aiApiAccess, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($hrSetupAccess['is_installed'] ?? false), json_encode($hrSetupAccess, JSON_PRETTY_PRINT));
        $this->assertFalse((bool) ($aiApiAccess['is_installed'] ?? true), json_encode($aiApiAccess, JSON_PRETTY_PRINT));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $hrSetupCard = $this->marketplaceCardHtml($body, WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP);
        $aiApiCard = $this->marketplaceCardHtml($body, WorkspaceSkillCatalogService::PLUGIN_AI_API);
        $this->assertNotSame('', $hrSetupCard);
        $this->assertNotSame('', $aiApiCard);
        $this->assertStringContainsString('data-marketplace-access-locked="0"', $hrSetupCard);
        $this->assertStringContainsString('data-marketplace-access-locked="0"', $aiApiCard);
        $this->assertStringContainsString('<span class="marketplace-status is-needs-setup" data-marketplace-card-status>Checking</span>', $hrSetupCard);
        $this->assertStringContainsString('<span class="marketplace-status " data-marketplace-card-status>Available</span>', $aiApiCard);
        $this->assertStringNotContainsString('Requires Organization Intelligence Setup', $hrSetupCard);
        $this->assertStringNotContainsString('Requires AI API', $aiApiCard);

        $status = $this->runWebEndpoint('api/workspace/marketplace_catalog_status.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $statusData = $this->decodeJsonResponse($status);
        $this->assertSame(200, (int) ($status['status'] ?? 0), (string) ($status['stderr'] ?? ''));
        $this->assertSame('Installed - locked', (string) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]['status_text'] ?? ''));
        $this->assertSame('Locked', (string) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_AI_API]['status_text'] ?? ''));
        $this->assertSame('Requires a paid subscription', (string) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]['readiness_text'] ?? ''));
        $this->assertSame('Requires a paid subscription', (string) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_AI_API]['readiness_text'] ?? ''));
        $this->assertTrue((bool) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]['is_locked'] ?? false));
        $this->assertTrue((bool) ($statusData['modules'][WorkspaceSkillCatalogService::PLUGIN_AI_API]['is_locked'] ?? false));
    }

    public function testHrAnalyticsDashboardLoadsAfterRequiredSetupIsReady(): void
    {
        $seed = $this->seedWorkspace('hr-analytics-ready-gate');
        $this->grantRolePermissions('owner', ['hr.analytics.view', 'hr.analytics.settings']);
        $this->activateSession($seed, 'owner');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->ensureHrAnalyticsSetupState((int) $seed['workspace_id'], true);
        $setupStatus = (new \CRM\Services\WorkspaceHRAnalyticsSetupService())->status((int) $seed['workspace_id']);
        $this->assertTrue((bool) ($setupStatus['ready'] ?? false), json_encode($setupStatus, JSON_PRETTY_PRINT));
        $this->assertTrue(Authorization::can('hr.analytics.view', Auth::user()));

        $ready = $this->runWebEndpoint('public/hr_analytics.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($ready['status'] ?? 0), json_encode($ready, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('Organization Intelligence', (string) ($ready['body'] ?? ''));

        $summary = $this->runWebEndpoint('api/hr/summary.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($summary['status'] ?? 0), (string) ($summary['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($summary);
        $this->assertTrue((bool) ($payload['success'] ?? false), json_encode($payload, JSON_PRETTY_PRINT));
        $this->assertArrayHasKey('summary', (array) ($payload['payload'] ?? []));
    }

    public function testSuperadminMarketplaceAnalyticsIncludesRuntimeAndTestCounters(): void
    {
        $seed = $this->seedWorkspace('marketplace-analytics-runtime');
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'superadmin', (int) $seed['user_id']);
        $this->activateSession($seed, 'superadmin');
        $this->completeOnboarding((int) $seed['workspace_id']);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 'marketplace', 'module_page_view');
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 'marketplace', 'catalog_click');
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 'marketplace', 'test_attempted');

        $summary = (new \CRM\Services\WorkspaceMarketplacePerformanceService())->buildModulePerformance((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 30);
        $this->assertSame(1, (int) ($summary['metrics']['module_page_views'] ?? 0));
        $this->assertSame(1, (int) ($summary['metrics']['catalog_clicks'] ?? 0));
        $this->assertSame(1, (int) ($summary['metrics']['test_attempts'] ?? 0));
        $this->assertArrayHasKey('runtime', $summary);

        $context = (new \CRM\Services\AIUserWorkContextService())->buildContext((int) $seed['user_id'], 'coach');
        $this->assertArrayHasKey('superadmin_analytics', (array) ($context['marketplace_modules'] ?? []));
    }

    public function testSuperadminCatalogEditorOwnsOrganizationIntelligenceTechnicalSetup(): void
    {
        $seed = $this->seedWorkspace('superadmin-organization-intelligence-technical');
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'superadmin', (int) $seed['user_id']);
        $this->activateSession($seed, 'superadmin');
        $this->setWorkspacePlan((int) $seed['workspace_id'], 'founder-plus-monthly');
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins((int) $seed['workspace_id'], (int) $seed['user_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP],
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Superadmin catalog editor', $body);
        $this->assertStringContainsString('Organization Intelligence technical setup', $body);
        $this->assertStringContainsString('name="hr_threshold_high_performer"', $body);
        $this->assertStringContainsString('name="hr_weight_marketing_task_completion"', $body);
        $this->assertStringContainsString('name="hr_manager_focus"', $body);
        $this->assertStringContainsString('name="skill_action" value="save_hr_analytics_setup"', $body);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'skill_action' => 'save_hr_analytics_setup',
                'hr_ai_enabled' => '1',
                'hr_threshold_high_performer' => '88',
                'hr_threshold_at_risk' => '35',
                'hr_threshold_needs_coaching' => '52',
                'hr_threshold_overloaded_task_count' => '9',
                'hr_threshold_inactive_days' => '14',
                'hr_weight_marketing_task_completion' => '0.25',
                'hr_weight_marketing_timeliness' => '0.15',
                'hr_weight_marketing_activity_consistency' => '0.15',
                'hr_weight_marketing_outcome_impact' => '0.15',
                'hr_weight_marketing_pipeline_movement' => '0.10',
                'hr_weight_marketing_campaign_output' => '0.10',
                'hr_weight_marketing_workload_balance' => '0.10',
                'hr_department_marketing' => 'Growth',
                'hr_department_sales' => 'Revenue',
                'hr_department_admin' => 'Leadership',
                'hr_department_owner' => 'Leadership',
                'hr_department_viewer' => 'Operations',
                'hr_manager_focus' => 'Keep manager guidance practical.',
                'hr_swot_focus' => 'Focus on team execution signals.',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($post);
        $this->assertTrue((bool) ($payload['success'] ?? false), json_encode($payload, JSON_PRETTY_PRINT));
        $this->assertSame('Organization Intelligence setup saved.', (string) ($payload['message'] ?? ''));

        $settings = (new \CRM\Modules\HRAnalyticsSettings())->get((int) $seed['workspace_id']);
        $this->assertSame(88, (int) ($settings['thresholds']['high_performer'] ?? 0));
        $this->assertSame(35, (int) ($settings['thresholds']['at_risk'] ?? 0));
        $this->assertEqualsWithDelta(0.25, (float) ($settings['scoring_weights']['marketing']['task_completion'] ?? 0), 0.0001);
        $this->assertSame('Growth', (string) ($settings['department_mappings']['marketing'] ?? ''));
        $this->assertSame('Keep manager guidance practical.', (string) ($settings['prompt_config']['manager_focus'] ?? ''));
    }

    public function testSuperadminCatalogOverrideSurvivesSyncAndRendersOnModulePage(): void
    {
        $seed = $this->seedWorkspace('marketplace-catalog-editor');
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'superadmin', (int) $seed['user_id']);
        $this->activateSession($seed, 'superadmin');
        $this->completeOnboarding((int) $seed['workspace_id']);
        $this->completeCommunicationSetup($seed);
        $this->completeFinanceSetup($seed);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeStartupJourneySetup($seed);
        $recommendationEvents = new WorkspaceMarketplaceRecommendationEventService();
        $setupEvents = new WorkspaceMarketplaceSetupJourneyEventService();
        $recommendationEvents->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'marketplace', 'impression');
        $recommendationEvents->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'marketplace', 'cta_clicked');
        $setupEvents->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'setup_opened', [
            'label' => 'Email Assistant',
        ]);
        (new WorkspaceSkillInstallService())->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, (int) $seed['user_id']);

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
        ]);
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Superadmin catalog editor', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-catalog-editor', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-catalog-editor-panel-copy', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-catalog-editor-panel-availability', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-catalog-editor-panel-media', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-form-field marketplace-form-field-wide', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace_explainer_video_file', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('catalog_explainer_video_url', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('name="skill_action" value="save_catalog_media"', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Save media', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Brief Overview card', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Technical Deep Dive card', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('catalog_overview_brief_content', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('catalog_overview_deep_dive_content', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-rich-insert="blockquote"', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-rich-insert="image"', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-rich-preview', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-inline-image-modal', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-inline-image-endpoint="../api/workspace/marketplace_catalog_inline_image.php"', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-inline-image-upload', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Upload and insert', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Turn email into a calmer assisted workflow', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('<blockquote><p>Use this when email work is repetitive or easy to miss', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Email Assistant deep dive', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('<table>', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_overview_headline', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_overview_intro', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_benefit_bullets', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_use_case_bullets', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_outcome_bullets', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_how_it_works_bullets', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_recommendations', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_prerequisites', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_setup_guide', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('marketplace_overview_brief_image_file', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('marketplace_overview_deep_dive_image_file', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('overview_brief_image_url', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('overview_deep_dive_image_url', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Draft/optimise with Clarity', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Impressions 30d', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Clicks 30d', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Installs 30d', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('Active users 30d', (string) ($get['body'] ?? ''));
        $this->assertStringContainsString('marketplace-performance-context-email_assistant', (string) ($get['body'] ?? ''));

        $aiCoachGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_AI_COACH],
        ]);
        $aiCoachBody = (string) ($aiCoachGet['body'] ?? '');
        $this->assertSame(200, (int) ($aiCoachGet['status'] ?? 0), (string) ($aiCoachGet['stderr'] ?? ''));
        $this->assertStringContainsString('Superadmin catalog editor', $aiCoachBody);
        $this->assertStringContainsString('catalog_explainer_video_url', $aiCoachBody);
        $this->assertStringContainsString('Used on the Marketplace card and optional Personal Strategy guidance surfaces.', $aiCoachBody);

        $draft = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'skill_action' => 'draft_catalog_copy',
                'catalog_label' => 'Email Assistant',
                'catalog_summary' => 'Make the email assistant easier to understand.',
                'catalog_pitch' => 'Email Assistant supports replies and digests.',
                'catalog_overview_brief_format' => 'text',
                'catalog_overview_brief_content' => "Existing brief\n- Review daily digest",
                'catalog_overview_deep_dive_format' => 'text',
                'catalog_overview_deep_dive_content' => "Existing deep dive\n- Configure\n- Test",
            ],
        ]);
        $this->assertSame(200, (int) ($draft['status'] ?? 0), (string) ($draft['stderr'] ?? ''));
        $this->assertStringContainsString('Clarity draft ready. Review the updated fields, then save catalog content.', (string) ($draft['body'] ?? ''));
        $this->assertStringContainsString('catalog_overview_brief_content', (string) ($draft['body'] ?? ''));
        $this->assertStringContainsString('catalog_overview_deep_dive_content', (string) ($draft['body'] ?? ''));
        $this->assertStringNotContainsString('catalog_benefit_bullets', (string) ($draft['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM workspace_skill_catalog_overrides WHERE skill_key = ?',
            [WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT]
        )['c'] ?? 0));

        $invalidVideo = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'skill_action' => 'save_catalog_override',
                'catalog_label' => 'Email Assistant',
                'catalog_explainer_video_url' => 'ftp://example.com/demo.mp4',
            ],
        ]);
        $this->assertSame(200, (int) ($invalidVideo['status'] ?? 0), (string) ($invalidVideo['stderr'] ?? ''));
        $this->assertStringContainsString('Explainer video URL must start with http:// or https://.', (string) ($invalidVideo['body'] ?? ''));

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'POST',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'skill_action' => 'save_catalog_override',
                'catalog_label' => 'Email Command Center',
                'catalog_summary' => 'A superadmin edited summary.',
                'catalog_pitch' => 'A superadmin edited pitch.',
                'catalog_thumbnail_alt' => 'Edited thumbnail alt',
                'catalog_banner_alt' => 'Edited banner alt',
                'catalog_explainer_video_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'catalog_explainer_orientation' => 'portrait',
                'catalog_overview_brief_format' => 'text',
                'catalog_overview_brief_content' => "Plain owner intro\n- Setup safely\nLiteral <b>tag</b>",
                'catalog_overview_deep_dive_format' => 'html',
                'catalog_overview_deep_dive_content' => '<h3>Operator Controls</h3><blockquote><p>Owner proof point</p></blockquote><script>bad()</script><p onclick="evil()">SMTP <strong>details</strong></p><iframe src="https://example.test/embed"></iframe><a href="javascript:bad()">Bad link</a><a href="https://example.test/docs">Docs</a><figure><img src="https://example.test/deep.png" alt="Inline architecture diagram"><figcaption>Architecture diagram</figcaption></figure><img src="javascript:evil()" alt="Bad">',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Marketplace catalog content saved.', (string) ($post['body'] ?? ''));

        $override = Database::queryOne(
            'SELECT label, summary, marketplace_profile_json FROM workspace_skill_catalog_overrides WHERE skill_key = ?',
            [WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT]
        ) ?: [];
        $profile = json_decode((string) ($override['marketplace_profile_json'] ?? '{}'), true) ?: [];
        $this->assertSame('Email Command Center', (string) ($override['label'] ?? ''));
        $this->assertSame('A superadmin edited summary.', (string) ($override['summary'] ?? ''));
        $this->assertSame('A superadmin edited pitch.', (string) ($profile['pitch'] ?? ''));
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', (string) ($profile['explainer_video_url'] ?? ''));
        $this->assertSame('portrait', (string) ($profile['explainer_orientation'] ?? ''));
        $this->assertSame('text', (string) ($profile['overview_brief_format'] ?? ''));
        $this->assertStringContainsString('Literal <b>tag</b>', (string) ($profile['overview_brief_content'] ?? ''));
        $this->assertSame('html', (string) ($profile['overview_deep_dive_format'] ?? ''));
        foreach ([
            'overview_headline',
            'overview_intro',
            'overview_brief_image_url',
            'overview_brief_image_alt',
            'overview_deep_dive_image_url',
            'overview_deep_dive_image_alt',
        ] as $legacyField) {
            $this->assertArrayNotHasKey($legacyField, $profile);
        }
        $this->assertStringContainsString('<h3>Operator Controls</h3>', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('<blockquote>', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('Owner proof point', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('<strong>details</strong>', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('href="https://example.test/docs"', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('<figure>', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('src="https://example.test/deep.png"', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringContainsString('<figcaption>Architecture diagram</figcaption>', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringNotContainsString('<script', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringNotContainsString('onclick', (string) ($profile['overview_deep_dive_content'] ?? ''));
        $this->assertStringNotContainsString('javascript:', (string) ($profile['overview_deep_dive_content'] ?? ''));

        $catalogGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), ['method' => 'GET']);
        $this->assertStringContainsString('Email Command Center', (string) ($catalogGet['body'] ?? ''));

        $moduleGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
        ]);
        $moduleBody = (string) ($moduleGet['body'] ?? '');
        $this->assertStringContainsString('marketplace-module-overview', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-tab="health"', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-tab="runtime"', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-tab="performance"', $moduleBody);
        $this->assertStringContainsString('data-marketplace-module-tab="catalog-editor"', $moduleBody);
        $this->assertStringContainsString('https://www.youtube.com/embed/dQw4w9WgXcQ', $moduleBody);
        $this->assertStringContainsString('Email Command Center for your workspace', $moduleBody);
        $this->assertStringContainsString('A superadmin edited pitch.', $moduleBody);
        $this->assertStringContainsString('Brief Overview', $moduleBody);
        $this->assertStringContainsString('Technical Deep Dive', $moduleBody);
        $this->assertMatchesRegularExpression('/<details class="marketplace-rich-overview-card marketplace-rich-overview-disclosure"[^>]*>\s*<summary>Technical Deep Dive<\/summary>/', $moduleBody);
        $this->assertStringNotContainsString('<details class="marketplace-rich-overview-card marketplace-rich-overview-disclosure" open', $moduleBody);
        $this->assertStringNotContainsString('What This Does', $moduleBody);
        $this->assertStringNotContainsString('Advanced Details', $moduleBody);
        $this->assertStringNotContainsString('marketplace-module-summary-card', $moduleBody);
        $this->assertStringNotContainsString('Module status', $moduleBody);
        $this->assertStringNotContainsString('Open test &amp; health', $moduleBody);
        $this->assertStringContainsString('Plain owner intro', $moduleBody);
        $this->assertStringNotContainsString('Turn email into a calmer assisted workflow', $moduleBody);
        $this->assertStringContainsString('Setup safely', $moduleBody);
        $this->assertStringContainsString('Literal &lt;b&gt;tag&lt;/b&gt;', $moduleBody);
        $this->assertStringContainsString('Operator Controls', $moduleBody);
        $this->assertStringContainsString('<blockquote>', $moduleBody);
        $this->assertStringContainsString('Owner proof point', $moduleBody);
        $this->assertStringContainsString('<strong>details</strong>', $moduleBody);
        $this->assertStringContainsString('href="https://example.test/docs"', $moduleBody);
        $this->assertStringContainsString('<figure>', $moduleBody);
        $this->assertStringContainsString('src="https://example.test/deep.png"', $moduleBody);
        $this->assertStringContainsString('Inline architecture diagram', $moduleBody);
        $this->assertStringContainsString('Architecture diagram', $moduleBody);
        $this->assertStringNotContainsString('bad()', $moduleBody);
        $this->assertStringNotContainsString('onclick="evil"', $moduleBody);
        $this->assertStringNotContainsString('javascript:evil', $moduleBody);
        $this->assertStringNotContainsString('javascript:bad', $moduleBody);
        $this->assertStringNotContainsString('https://example.test/embed', $moduleBody);
        $this->assertStringNotContainsString('<h4>At A Glance</h4>', $moduleBody);
        $this->assertStringNotContainsString('<h4>Why This Module Matters</h4>', $moduleBody);
        $this->assertStringNotContainsString('<h4>Prerequisites</h4>', $moduleBody);
        $this->assertStringNotContainsString('<h4>Setup Path</h4>', $moduleBody);
        $deepDivePosition = strpos($moduleBody, 'Technical Deep Dive');
        $capabilityTagsPosition = strpos($moduleBody, 'marketplace-module-capability-tags');
        $this->assertNotFalse($deepDivePosition);
        $this->assertNotFalse($capabilityTagsPosition);
        $this->assertGreaterThan($deepDivePosition, $capabilityTagsPosition);

        $skillGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'superadmin'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
        ]);
        $skillBody = (string) ($skillGet['body'] ?? '');
        $this->assertSame(200, (int) ($skillGet['status'] ?? 0), (string) ($skillGet['stderr'] ?? ''));
        $this->assertStringContainsString('data-marketplace-module-tab="health"', $skillBody);
        $this->assertStringContainsString('data-marketplace-module-tab="performance"', $skillBody);
        $this->assertStringContainsString('data-marketplace-module-panel="health"', $skillBody);
        $this->assertStringContainsString('data-marketplace-module-panel="performance"', $skillBody);
        $this->assertStringNotContainsString('marketplace-module-summary-card', $skillBody);
        $this->assertStringContainsString('Brief Overview', $skillBody);
        $this->assertStringContainsString('Technical Deep Dive', $skillBody);
        $this->assertStringContainsString('marketplace-module-capability-tags', $skillBody);
        $this->assertStringContainsString('marketplace-performance-context-lean_canvas', $skillBody);
    }

    public function testMarketplaceCatalogInlineImageUploadEndpointRequiresSuperadminAndValidImage(): void
    {
        $superSeed = $this->seedWorkspace('marketplace-inline-image-super');
        Authorization::assignUserRoleBySlug((int) $superSeed['user_id'], 'superadmin', (int) $superSeed['user_id']);
        $this->activateSession($superSeed, 'superadmin');

        $uploadRoot = $this->makeMarketplaceUploadRoot();
        $env = [
            'CRM_ENDPOINT_TEST' => '1',
            'MARKETPLACE_INLINE_UPLOAD_ROOT' => $uploadRoot,
        ];
        $gifBytes = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        $gifPath = $this->writeUploadFile(
            $uploadRoot,
            'tmp/inline-source.gif',
            $gifBytes
        );

        $success = $this->runWebEndpoint('api/workspace/marketplace_catalog_inline_image.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'alt' => 'Inbox workflow diagram',
                'caption' => 'Upload preview',
            ],
            'files' => [
                'image_file' => [
                    'name' => 'inline-source.gif',
                    'type' => 'image/gif',
                    'tmp_name' => $gifPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($gifPath),
                ],
            ],
        ]);
        $successData = json_decode((string) ($success['body'] ?? ''), true);

        $this->assertSame(200, (int) ($success['status'] ?? 0), (string) ($success['stderr'] ?? ''));
        $this->assertTrue((bool) ($successData['success'] ?? false), (string) ($success['body'] ?? ''));
        $this->assertStringStartsWith('uploads/marketplace/email_assistant/inline-image-', (string) ($successData['stored_path'] ?? ''));
        $this->assertStringStartsWith('../uploads/marketplace/email_assistant/inline-image-', (string) ($successData['html_src'] ?? ''));
        $this->assertSame('Inbox workflow diagram', (string) ($successData['alt'] ?? ''));
        $this->assertSame('Upload preview', (string) ($successData['caption'] ?? ''));
        $this->assertFileExists($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($successData['stored_path'] ?? '')));

        $badMimePath = $this->writeUploadFile($uploadRoot, 'tmp/bad-image.txt', 'not an image');
        $badMime = $this->runWebEndpoint('api/workspace/marketplace_catalog_inline_image.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                'alt' => 'Bad upload',
            ],
            'files' => [
                'image_file' => [
                    'name' => 'bad-image.txt',
                    'type' => 'text/plain',
                    'tmp_name' => $badMimePath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($badMimePath),
                ],
            ],
        ]);
        $badMimeData = json_decode((string) ($badMime['body'] ?? ''), true);
        $this->assertSame(422, (int) ($badMime['status'] ?? 0), (string) ($badMime['stderr'] ?? ''));
        $this->assertFalse((bool) ($badMimeData['success'] ?? true));
        $this->assertStringContainsString('JPG, PNG, WebP, or GIF', (string) ($badMimeData['error'] ?? ''));

        $missingCsrf = $this->runWebEndpoint('api/workspace/marketplace_catalog_inline_image.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            ],
        ]);
        $this->assertSame(403, (int) ($missingCsrf['status'] ?? 0), (string) ($missingCsrf['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid security token', (string) ($missingCsrf['body'] ?? ''));

        $ownerSeed = $this->seedWorkspace('marketplace-inline-image-owner');
        $this->activateSession($ownerSeed, 'owner');
        $ownerPost = $this->runWebEndpoint('api/workspace/marketplace_catalog_inline_image.php', $this->webSession($ownerSeed, 'owner'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            ],
        ]);
        $this->assertSame(403, (int) ($ownerPost['status'] ?? 0), (string) ($ownerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Only superadmins', (string) ($ownerPost['body'] ?? ''));
    }

    public function testSuperadminCanHideAndDeactivateMarketplaceModulesGlobally(): void
    {
        $skillKey = WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO;
        $ownerSeed = $this->seedWorkspace('marketplace-global-status-owner');
        $superSeed = $this->seedWorkspace('marketplace-global-status-super');
        Authorization::assignUserRoleBySlug((int) $superSeed['user_id'], 'superadmin', (int) $superSeed['user_id']);

        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $refreshCatalogRuntime = static function () use (&$catalog, &$installer): void {
            WorkspaceSkillCatalogService::resetRuntimeCaches();
            $catalog = new WorkspaceSkillCatalogService();
            $installer = new WorkspaceSkillInstallService($catalog);
            $catalog->syncDefinitions();
        };

        try {
            $this->activateSession($ownerSeed, 'owner');
            $this->completeOnboarding((int) $ownerSeed['workspace_id']);
            $this->completeFinanceSetup($ownerSeed);
            $installer->install((int) $ownerSeed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $ownerSeed['user_id']);
            $this->completeStartupJourneySetup($ownerSeed);
            $installer->install((int) $ownerSeed['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, (int) $ownerSeed['user_id']);
            $this->completeAiCoachOnboarding($ownerSeed);
            (new UserStrategyProfile())->save((int) $ownerSeed['user_id'], [
                'target_market_focus' => 'Founder-led service teams',
                'ideal_customer_profile' => 'Small teams with messy inboxes and stalled deals',
                'offer_angle' => 'Reliable follow-up and campaign operating clarity',
                'segment_focus' => 'CRM-heavy businesses',
                'sales_motion' => 'Consultative',
                'deal_movement_strategy' => 'Nurture stalled leads into priced next steps',
                'outreach_posture' => 'Helpful and specific',
                'positioning_notes' => 'Operational clarity for first-deal execution',
            ]);
            $installer->install((int) $ownerSeed['workspace_id'], $skillKey, (int) $ownerSeed['user_id']);

            $this->activateSession($superSeed, 'superadmin');
            $this->completeOnboarding((int) $superSeed['workspace_id']);
            $this->completeFinanceSetup($superSeed);
            $installer->install((int) $superSeed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $superSeed['user_id']);
            $this->completeStartupJourneySetup($superSeed);
            $installer->install((int) $superSeed['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, (int) $superSeed['user_id']);
            $this->completeAiCoachOnboarding($superSeed);

            $ownerGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
                'method' => 'GET',
                'query' => ['module' => $skillKey],
            ]);
            $this->assertSame(200, (int) ($ownerGet['status'] ?? 0), (string) ($ownerGet['stderr'] ?? ''));
            $this->assertStringNotContainsString('name="catalog_status"', (string) ($ownerGet['body'] ?? ''));

            $ownerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
                'method' => 'POST',
                'query' => ['module' => $skillKey],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'save_catalog_status',
                    'catalog_status' => WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN,
                ],
            ]);
            $this->assertSame(200, (int) ($ownerPost['status'] ?? 0), (string) ($ownerPost['stderr'] ?? ''));
            $this->assertStringContainsString('Only superadmins can change Marketplace catalog availability.', (string) ($ownerPost['body'] ?? ''));

            $superGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
                'method' => 'GET',
                'query' => ['module' => $skillKey],
            ]);
            $this->assertSame(200, (int) ($superGet['status'] ?? 0), (string) ($superGet['stderr'] ?? ''));
            $this->assertStringContainsString('name="catalog_status"', (string) ($superGet['body'] ?? ''));
            $this->assertStringContainsString('Save availability', (string) ($superGet['body'] ?? ''));

            $hide = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
                'method' => 'POST',
                'query' => ['module' => $skillKey],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'save_catalog_status',
                    'catalog_status' => WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN,
                ],
            ]);
            $this->assertSame(200, (int) ($hide['status'] ?? 0), (string) ($hide['stderr'] ?? ''));
            $this->assertStringContainsString('Marketplace availability saved: Hidden.', (string) ($hide['body'] ?? ''));

            $refreshCatalogRuntime();
            $this->activateSession($ownerSeed, 'owner');
            $this->assertSame(WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN, $catalog->catalogStatusFor($skillKey));
            $this->assertNull($catalog->findForWorkspace($skillKey, (int) $ownerSeed['workspace_id']));
            $this->assertNotNull($catalog->findForWorkspace($skillKey, (int) $ownerSeed['workspace_id'], true));
            $this->assertFalse($catalog->canInstallForWorkspace($skillKey, (int) $superSeed['workspace_id']));
            $this->assertTrue($installer->isInstalled((int) $ownerSeed['workspace_id'], $skillKey));
            $this->assertTrue($installer->canExposeRuntimeModule((int) $ownerSeed['workspace_id'], $skillKey));

            $ownerHiddenGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
                'method' => 'GET',
                'query' => ['module' => $skillKey],
            ]);
            $this->assertSame(200, (int) ($ownerHiddenGet['status'] ?? 0), (string) ($ownerHiddenGet['stderr'] ?? ''));
            $this->assertStringContainsString('Marketplace module not found.', (string) ($ownerHiddenGet['body'] ?? ''));

            $installHidden = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
                'method' => 'POST',
                'query' => ['module' => $skillKey],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'install',
                ],
            ]);
            $this->assertSame(200, (int) ($installHidden['status'] ?? 0), (string) ($installHidden['stderr'] ?? ''));
            $this->assertStringContainsString('hidden from workspace actions', (string) ($installHidden['body'] ?? ''));

            $recommendations = (new WorkspaceMarketplaceRecommendationService($catalog, $installer))
                ->recommendationsForWorkspace((int) $ownerSeed['workspace_id'], (int) $ownerSeed['user_id']);
            $this->assertNotContains($skillKey, array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), $recommendations));

            $deactivate = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
                'method' => 'POST',
                'query' => ['module' => $skillKey],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'save_catalog_status',
                    'catalog_status' => WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED,
                ],
            ]);
            $this->assertSame(200, (int) ($deactivate['status'] ?? 0), (string) ($deactivate['stderr'] ?? ''));
            $this->assertStringContainsString('Marketplace availability saved: Deactivated.', (string) ($deactivate['body'] ?? ''));
            $refreshCatalogRuntime();
            $this->assertSame(WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED, $catalog->catalogStatusFor($skillKey));
            $this->assertFalse($installer->isInstalled((int) $ownerSeed['workspace_id'], $skillKey));
            $this->assertFalse($installer->canExposeRuntimeModule((int) $ownerSeed['workspace_id'], $skillKey));
            $installedKeys = array_map(static fn(array $item): string => (string) ($item['key'] ?? ''), $installer->installedForWorkspace((int) $ownerSeed['workspace_id']));
            $this->assertNotContains($skillKey, $installedKeys);

            $restore = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
                'method' => 'POST',
                'query' => ['module' => $skillKey],
                'post' => [
                    'csrf_token' => 'csrf-workspace-skills',
                    'skill_key' => $skillKey,
                    'skill_action' => 'save_catalog_status',
                    'catalog_status' => WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE,
                ],
            ]);
            $this->assertSame(200, (int) ($restore['status'] ?? 0), (string) ($restore['stderr'] ?? ''));
            $this->assertStringContainsString('Marketplace availability saved: Visible.', (string) ($restore['body'] ?? ''));
            $refreshCatalogRuntime();
            $this->assertSame(WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE, $catalog->catalogStatusFor($skillKey));
            $this->assertNotNull($catalog->findForWorkspace($skillKey, (int) $ownerSeed['workspace_id']));
        } finally {
            try {
                $catalog->setCatalogStatus($skillKey, WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE, (int) ($superSeed['user_id'] ?? 0));
            } catch (\Throwable $ignored) {
            }
        }
    }

    public function testSuperadminCanManageMarketplaceActivationBundleDefinitions(): void
    {
        $ownerSeed = $this->seedWorkspace('marketplace-bundle-manager-owner');
        $this->activateSession($ownerSeed, 'owner');
        $this->completeOnboarding((int) $ownerSeed['workspace_id']);

        $ownerGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($ownerGet['status'] ?? 0), (string) ($ownerGet['stderr'] ?? ''));
        $this->assertStringNotContainsString('Superadmin bundle manager', (string) ($ownerGet['body'] ?? ''));
        $this->assertStringNotContainsString('data-marketplace-filter="bundle-manager"', (string) ($ownerGet['body'] ?? ''));

        $ownerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'save_activation_bundle_definition',
                'bundle_key' => 'owner_should_not_create_bundle',
                'bundle_label' => 'Owner Should Not Create Bundle',
                'bundle_summary' => 'Owners should not manage the global bundle catalog.',
                'bundle_included_skill_keys' => [WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
                'bundle_is_active' => '1',
            ],
        ]);
        $this->assertSame(200, (int) ($ownerPost['status'] ?? 0), (string) ($ownerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Only superadmins can manage Marketplace activation bundles.', (string) ($ownerPost['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_definitions WHERE bundle_key = ?",
            ['owner_should_not_create_bundle']
        )['c'] ?? 0));

        $superSeed = $this->seedWorkspace('marketplace-bundle-manager-super');
        Authorization::assignUserRoleBySlug((int) $superSeed['user_id'], 'superadmin', (int) $superSeed['user_id']);
        $this->activateSession($superSeed, 'superadmin');
        $this->completeOnboarding((int) $superSeed['workspace_id']);

        $superGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($superGet['status'] ?? 0), (string) ($superGet['stderr'] ?? ''));
        $this->assertStringContainsString('Superadmin bundle manager', (string) ($superGet['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-filter="bundle-manager"', (string) ($superGet['body'] ?? ''));
        $this->assertStringContainsString('data-marketplace-section="bundle-manager"', (string) ($superGet['body'] ?? ''));
        $this->assertStringContainsString('strategy_foundation', (string) ($superGet['body'] ?? ''));

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'save_activation_bundle_definition',
                'bundle_key' => 'superadmin_ops_bundle',
                'bundle_label' => 'Superadmin Ops Bundle',
                'bundle_summary' => 'A platform-managed bundle for testing bundle creation.',
                'bundle_why_this_bundle' => 'It proves superadmins can create global bundle catalog entries.',
                'bundle_expected_outcome' => 'The bundle appears in admin lists and active definitions.',
                'bundle_display_order' => '65',
                'bundle_included_skill_keys' => [
                    WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                ],
                'bundle_is_active' => '1',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Marketplace activation bundle saved: Superadmin Ops Bundle.', (string) ($post['body'] ?? ''));

        $created = Database::queryOne(
            "SELECT label, is_active, archived_at, included_skill_keys_json
             FROM workspace_marketplace_activation_bundle_definitions
             WHERE bundle_key = ?
             LIMIT 1",
            ['superadmin_ops_bundle']
        ) ?: [];
        $this->assertSame('Superadmin Ops Bundle', (string) ($created['label'] ?? ''));
        $this->assertSame(1, (int) ($created['is_active'] ?? 0));
        $this->assertSame('', (string) ($created['archived_at'] ?? ''));
        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, json_decode((string) ($created['included_skill_keys_json'] ?? '[]'), true) ?: []);

        $deactivate = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'toggle_activation_bundle_definition',
                'bundle_key' => 'superadmin_ops_bundle',
                'bundle_definition_active' => '0',
            ],
        ]);
        $this->assertSame(200, (int) ($deactivate['status'] ?? 0), (string) ($deactivate['stderr'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT is_active FROM workspace_marketplace_activation_bundle_definitions WHERE bundle_key = ?",
            ['superadmin_ops_bundle']
        )['is_active'] ?? 1));

        $archive = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'archive_activation_bundle_definition',
                'bundle_key' => 'superadmin_ops_bundle',
            ],
        ]);
        $this->assertSame(200, (int) ($archive['status'] ?? 0), (string) ($archive['stderr'] ?? ''));
        $this->assertNotSame('', (string) (Database::queryOne(
            "SELECT archived_at FROM workspace_marketplace_activation_bundle_definitions WHERE bundle_key = ?",
            ['superadmin_ops_bundle']
        )['archived_at'] ?? ''));
    }

    public function testSuperadminCanDeleteAllMarketplaceVideosFromDangerZone(): void
    {
        $this->clearMarketplaceVideoReferenceTables();
        $uploadRoot = $this->makeMarketplaceUploadRoot();
        $env = ['MARKETPLACE_UPLOAD_CLEANUP_ROOT' => $uploadRoot];

        $referencedVideo = $this->writeUploadFile($uploadRoot, 'uploads/marketplace/global/referenced.mp4', 'referenced video');
        $recentVideo = $this->writeUploadFile($uploadRoot, 'uploads/marketplace/global/recent.webm', 'recent video');
        $orphanVideo = $this->writeUploadFile($uploadRoot, 'uploads/marketplace/global/orphan.mov', 'orphan video');
        $image = $this->writeUploadFile($uploadRoot, 'uploads/marketplace/global/keep.webp', 'image');

        Database::execute(
            "INSERT INTO workspace_skill_catalog_overrides (skill_key, marketplace_profile_json)
             VALUES (?, ?)",
            [
                WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                json_encode([
                    'explainer_video_url' => 'uploads/marketplace/global/referenced.mp4',
                    'setup_video_url' => 'https://cdn.example.test/setup.mp4',
                    'setup_video_uploaded_at' => '2026-06-01T00:00:00+00:00',
                    'pitch' => 'Keep this pitch.',
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
        Database::execute(
            "INSERT INTO workspace_marketplace_activation_bundle_definitions (
                bundle_key, label, summary, included_skill_keys_json, explainer_video_url, is_active, display_order
             ) VALUES ('video_purge_bundle', 'Video Purge Bundle', 'Video purge summary', ?, 'https://cdn.example.test/bundle.mp4', 1, 10)",
            [json_encode([WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT], JSON_UNESCAPED_SLASHES)]
        );
        Database::execute(
            "INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
             VALUES ('video_purge_page', 'Video Purge Page', 'uploads/marketplace/global/referenced.mp4', 1)"
        );

        $ownerSeed = $this->seedWorkspace('marketplace-video-purge-owner');
        $this->activateSession($ownerSeed, 'owner');
        $this->completeOnboarding((int) $ownerSeed['workspace_id']);

        $ownerGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
            'method' => 'GET',
            'env' => $env,
        ]);
        $this->assertSame(200, (int) ($ownerGet['status'] ?? 0), (string) ($ownerGet['stderr'] ?? ''));
        $this->assertStringNotContainsString('Delete all Marketplace videos', (string) ($ownerGet['body'] ?? ''));

        $ownerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($ownerSeed, 'owner'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'delete_all_marketplace_videos',
                'marketplace_video_delete_confirmation' => 'DELETE VIDEOS',
            ],
        ]);
        $this->assertSame(200, (int) ($ownerPost['status'] ?? 0), (string) ($ownerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Only superadmins can delete Marketplace videos.', (string) ($ownerPost['body'] ?? ''));
        $this->assertFileExists($referencedVideo);

        $superSeed = $this->seedWorkspace('marketplace-video-purge-super');
        Authorization::assignUserRoleBySlug((int) $superSeed['user_id'], 'superadmin', (int) $superSeed['user_id']);
        $this->activateSession($superSeed, 'superadmin');
        $this->completeOnboarding((int) $superSeed['workspace_id']);

        $superGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'GET',
            'env' => $env,
        ]);
        $superBody = (string) ($superGet['body'] ?? '');
        $this->assertSame(200, (int) ($superGet['status'] ?? 0), (string) ($superGet['stderr'] ?? ''));
        $this->assertStringContainsString('Delete all Marketplace videos', $superBody);
        $this->assertStringContainsString('data-marketplace-section="video-danger-zone"', $superBody);
        $this->assertStringContainsString('Local video files', $superBody);
        $this->assertStringContainsString('Catalog video refs', $superBody);
        $this->assertStringContainsString('marketplace_video_delete_confirmation', $superBody);

        $badCsrf = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'bad-token',
                'skill_action' => 'delete_all_marketplace_videos',
                'marketplace_video_delete_confirmation' => 'DELETE VIDEOS',
            ],
        ]);
        $this->assertSame(200, (int) ($badCsrf['status'] ?? 0), (string) ($badCsrf['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid security token. Please refresh and try again.', (string) ($badCsrf['body'] ?? ''));
        $this->assertFileExists($referencedVideo);

        $missingConfirmation = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'delete_all_marketplace_videos',
                'marketplace_video_delete_confirmation' => 'delete videos',
            ],
        ]);
        $this->assertSame(200, (int) ($missingConfirmation['status'] ?? 0), (string) ($missingConfirmation['stderr'] ?? ''));
        $this->assertStringContainsString('Type DELETE VIDEOS to confirm deleting all Marketplace videos.', (string) ($missingConfirmation['body'] ?? ''));
        $this->assertFileExists($referencedVideo);

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($superSeed, 'superadmin'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_action' => 'delete_all_marketplace_videos',
                'marketplace_video_delete_confirmation' => 'DELETE VIDEOS',
            ],
        ]);
        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertStringContainsString('Marketplace videos deleted. Removed 3 local video file(s)', (string) ($post['body'] ?? ''));
        $this->assertFileDoesNotExist($referencedVideo);
        $this->assertFileDoesNotExist($recentVideo);
        $this->assertFileDoesNotExist($orphanVideo);
        $this->assertFileExists($image);

        $catalogRow = Database::queryOne(
            'SELECT marketplace_profile_json FROM workspace_skill_catalog_overrides WHERE skill_key = ? LIMIT 1',
            [WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT]
        ) ?: [];
        $profile = json_decode((string) ($catalogRow['marketplace_profile_json'] ?? '{}'), true) ?: [];
        $this->assertSame('', (string) ($profile['explainer_video_url'] ?? ''));
        $this->assertSame('', (string) ($profile['setup_video_url'] ?? ''));
        $this->assertSame('', (string) ($profile['setup_video_uploaded_at'] ?? ''));
        $this->assertSame('Keep this pitch.', (string) ($profile['pitch'] ?? ''));
        $bundle = Database::queryOne("SELECT explainer_video_url FROM workspace_marketplace_activation_bundle_definitions WHERE bundle_key = 'video_purge_bundle' LIMIT 1") ?: [];
        $this->assertSame('', (string) ($bundle['explainer_video_url'] ?? ''));
        $page = Database::queryOne("SELECT video_url, is_active FROM marketplace_page_explainers WHERE page_key = 'video_purge_page' LIMIT 1") ?: [];
        $this->assertSame('', (string) ($page['video_url'] ?? ''));
        $this->assertSame(0, (int) ($page['is_active'] ?? 1));
    }

    public function testMarketplaceRecommendationFeedbackCanBeRecordedFromPage(): void
    {
        $seed = $this->seedWorkspace('marketplace-feedback');
        $this->activateSession($seed, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'snooze_recommendation',
            ],
        ]);

        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $row = Database::queryOne(
            "SELECT feedback_type, snoozed_until
             FROM workspace_marketplace_recommendation_feedback
             WHERE workspace_id = ? AND skill_key = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('snoozed', (string) ($row['feedback_type'] ?? ''));
        $this->assertNotEmpty($row['snoozed_until'] ?? '');

        $event = Database::queryOne(
            "SELECT event_type, surface
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'snoozed'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('snoozed', (string) ($event['event_type'] ?? ''));
        $this->assertSame('marketplace', (string) ($event['surface'] ?? ''));
    }

    public function testMarketplaceRecommendationControlsPersistForOwnerAndRejectViewer(): void
    {
        $seed = $this->seedWorkspace('marketplace-controls');
        $this->activateSession($seed, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'pin_recommendation',
            ],
        ]);
        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT enabled FROM workspace_marketplace_recommendation_controls WHERE workspace_id = ? AND skill_key = ? AND control_type = 'pinned' LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        )['enabled'] ?? 0));

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), ['method' => 'GET']);
        $this->assertContains((int) ($get['status'] ?? 0), [200, 302], (string) ($get['stderr'] ?? ''));
        if ((string) ($get['body'] ?? '') !== '') {
            $this->assertStringContainsString('marketplace-admin-controls', (string) ($get['body'] ?? ''));
            $this->assertStringContainsString('Unpin', (string) ($get['body'] ?? ''));
            $this->assertStringNotContainsString('password', strtolower((string) ($get['body'] ?? '')));
        }

        $viewerId = (int) Auth::createUser(
            'marketplace.controls.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Market',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.controls.viewer@example.test',
        ]);

        $viewerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'mute_recommendation',
            ],
        ]);
        $this->assertSame(200, (int) ($viewerPost['status'] ?? 0), (string) ($viewerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Your access profile does not allow Marketplace install or setup changes.', (string) ($viewerPost['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_recommendation_controls WHERE workspace_id = ? AND skill_key = ? AND control_type = 'muted' AND enabled = 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        )['c'] ?? 0));
    }

    public function testMarketplaceActivationBundlesRenderAndActionsPersistForOwner(): void
    {
        $seed = $this->seedWorkspace('marketplace-bundles');
        $this->activateSession($seed, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->completeOnboarding((int) $seed['workspace_id']);
        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'whatsapp_led_growth',
            'cta_clicked',
            ['metadata' => ['label' => 'WhatsApp-led growth', 'secret_token' => 'do-not-store']]
        );

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringNotContainsString('marketplace-activation-bundles', $body);
        $this->assertStringNotContainsString('Activation bundles', $body);
        $this->assertStringNotContainsString('WhatsApp-led growth', $body);
        $this->assertStringNotContainsString('Select bundle', $body);
        $this->assertStringNotContainsString('marketplace-activation-bundle-insight-chip', $body);
        $this->assertStringNotContainsString('marketplace-activation-bundle-adaptive-note', $body);
        $this->assertStringNotContainsString('Interest without completion', $body);
        $this->assertStringNotContainsString('secret', strtolower($body));
        $impressions = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_activation_bundle_events
             WHERE workspace_id = ? AND event_type = 'bundle_impression'",
            [(int) $seed['workspace_id']]
        );
        $this->assertSame(0, (int) ($impressions['c'] ?? 0));

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'bundle_key' => 'whatsapp_led_growth',
                'skill_action' => 'select_activation_bundle',
            ],
        ]);
        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $this->assertSame('selected', (string) (Database::queryOne(
            "SELECT status FROM workspace_marketplace_activation_bundle_state WHERE workspace_id = ? AND bundle_key = ? LIMIT 1",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        )['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_events WHERE workspace_id = ? AND bundle_key = ? AND event_type = 'selected'",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        )['c'] ?? 0));

        $complete = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'bundle_key' => 'whatsapp_led_growth',
                'skill_action' => 'complete_activation_bundle',
            ],
        ]);
        $this->assertContains((int) ($complete['status'] ?? 0), [200, 302], (string) ($complete['stderr'] ?? ''));
        $this->assertSame('completed', (string) (Database::queryOne(
            "SELECT status FROM workspace_marketplace_activation_bundle_state WHERE workspace_id = ? AND bundle_key = ? LIMIT 1",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        )['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_events WHERE workspace_id = ? AND bundle_key = ? AND event_type = 'completed'",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        )['c'] ?? 0));
    }

    public function testMarketplaceActivationBundleActionsRequireCsrfAndManagePermission(): void
    {
        $seed = $this->seedWorkspace('marketplace-bundle-auth');
        $badCsrf = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad-token',
                'bundle_key' => 'email_led_growth',
                'skill_action' => 'select_activation_bundle',
            ],
        ]);
        $this->assertContains((int) ($badCsrf['status'] ?? 0), [200, 302], (string) ($badCsrf['stderr'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_state WHERE workspace_id = ? AND bundle_key = ?",
            [(int) $seed['workspace_id'], 'email_led_growth']
        )['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_events WHERE workspace_id = ? AND bundle_key = ? AND event_type = 'selected'",
            [(int) $seed['workspace_id'], 'email_led_growth']
        )['c'] ?? 0));

        $viewerId = (int) Auth::createUser(
            'marketplace.bundle.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Bundle',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.bundle.viewer@example.test',
        ]);

        $viewerGet = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($viewerGet['status'] ?? 0), (string) ($viewerGet['stderr'] ?? ''));
        $this->assertStringNotContainsString('marketplace-activation-bundles', (string) ($viewerGet['body'] ?? ''));
        $this->assertStringNotContainsString('marketplace-activation-bundle-insight-chip', (string) ($viewerGet['body'] ?? ''));

        $viewerPost = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'bundle_key' => 'email_led_growth',
                'skill_action' => 'dismiss_activation_bundle',
            ],
        ]);
        $this->assertSame(200, (int) ($viewerPost['status'] ?? 0), (string) ($viewerPost['stderr'] ?? ''));
        $this->assertStringContainsString('Your access profile does not allow Marketplace install or setup changes.', (string) ($viewerPost['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_marketplace_activation_bundle_state WHERE workspace_id = ? AND bundle_key = ?",
            [(int) $seed['workspace_id'], 'email_led_growth']
        )['c'] ?? 0));
    }

    public function testMarketplaceActivationBundleInstallAttributionRecordsModuleInstalled(): void
    {
        $seed = $this->seedWorkspace('marketplace-bundle-install');
        $this->activateSession($seed, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );

        $post = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'skill_action' => 'install',
                'activation_bundle_key' => 'whatsapp_led_growth',
            ],
        ]);

        $this->assertContains((int) ($post['status'] ?? 0), [200, 302], (string) ($post['stderr'] ?? ''));
        $event = Database::queryOne(
            "SELECT event_type, metadata_json
             FROM workspace_marketplace_activation_bundle_events
             WHERE workspace_id = ? AND bundle_key = ? AND event_type = 'module_installed'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        );
        $this->assertSame('module_installed', (string) ($event['event_type'] ?? ''));
        $this->assertStringContainsString(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (string) ($event['metadata_json'] ?? ''));
        $this->assertStringNotContainsString('secret', strtolower((string) ($event['metadata_json'] ?? '')));
    }

    public function testChatWelcomeOmitsMarketplaceNudgesForOwner(): void
    {
        $seed = $this->seedWorkspace('marketplace-chat-owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );

        $get = $this->runWebEndpoint('api/chat/welcome.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');
        $data = json_decode($body, true) ?: [];

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertArrayHasKey('marketplace_nudges', $data);
        $this->assertSame([], (array) ($data['marketplace_nudges'] ?? []));
        $this->assertArrayHasKey('activation_bundle_nudges', $data);
        $this->assertSame([], (array) ($data['activation_bundle_nudges'] ?? []));
        $this->assertStringNotContainsString('password', strtolower($body));
        $this->assertStringNotContainsString('secret', strtolower($body));

        $event = Database::queryOne(
            "SELECT event_type, surface
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'impression'
             ORDER BY id DESC
            LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertEmpty($event);
    }

    public function testChatWelcomeViewerSeesNoMarketplaceNudges(): void
    {
        $seed = $this->seedWorkspace('marketplace-chat-viewer');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $viewerId = (int) Auth::createUser(
            'marketplace.chat.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Market',
            'Viewer'
        );
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.chat.viewer@example.test',
        ]);

        $get = $this->runWebEndpoint('api/chat/welcome.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'GET',
        ]);
        $data = json_decode((string) ($get['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertSame([], (array) ($data['marketplace_nudges'] ?? []));
        $this->assertSame([], (array) ($data['activation_bundle_nudges'] ?? []));

    }

    public function testSurfaceDisabledControlHidesRecommendationOnlyFromSelectedSurface(): void
    {
        $seed = $this->seedWorkspace('marketplace-surface-controls');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->completeFinanceSetup($seed);
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        (new WorkspaceMarketplaceRecommendationControlService())->setControl(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'surface_disabled',
            'clarity_chat',
            true,
            'test'
        );
        $installer = new WorkspaceSkillInstallService();
        $installer->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            (int) $seed['user_id']
        );
        $this->completeStartupJourneySetup($seed);
        $installer->install(
            (int) $seed['workspace_id'],
            WorkspaceSkillCatalogService::SKILL_AI_COACH,
            (int) $seed['user_id']
        );
        $this->completeAiCoachOnboarding($seed);

        $welcome = $this->runWebEndpoint('api/chat/welcome.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $welcomeData = json_decode((string) ($welcome['body'] ?? ''), true) ?: [];
        $clarityKeys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), (array) ($welcomeData['marketplace_nudges'] ?? []));
        $this->assertNotContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $clarityKeys);

        $coach = $this->runWebEndpoint('api/ai-coach/recommendations.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $coachData = json_decode((string) ($coach['body'] ?? ''), true) ?: [];
        $recommendations = (array) ($coachData['recommendations'] ?? []);
        $coachKeys = array_map(
            static fn(array $item): string => (string) ($item['marketplace_skill_key'] ?? ''),
            array_merge((array) ($recommendations['foundation_gaps'] ?? []), (array) ($recommendations['missing_features'] ?? []))
        );
        $this->assertNotContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $coachKeys);
        $this->assertContains(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $coachKeys);
    }

    public function testClarityMarketplaceFeedbackEndpointPersistsOwnerFeedback(): void
    {
        $seed = $this->seedWorkspace('marketplace-chat-feedback');
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );

        $badCsrf = $this->runWebEndpoint('api/chat/marketplace_feedback.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'feedback_type' => 'dismissed',
            ],
        ]);
        $this->assertSame(403, (int) ($badCsrf['status'] ?? 0));

        $post = $this->runWebEndpoint('api/chat/marketplace_feedback.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'feedback_type' => 'snoozed',
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $row = Database::queryOne(
            "SELECT feedback_type, reason_code, snoozed_until, metadata_json
             FROM workspace_marketplace_recommendation_feedback
             WHERE workspace_id = ? AND skill_key = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('snoozed', (string) ($row['feedback_type'] ?? ''));
        $this->assertSame('clarity_chat', (string) ($row['reason_code'] ?? ''));
        $this->assertNotEmpty($row['snoozed_until'] ?? '');
        $this->assertStringContainsString('clarity_chat', (string) ($row['metadata_json'] ?? ''));

        $event = Database::queryOne(
            "SELECT event_type, surface
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'snoozed'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('clarity_chat', (string) ($event['surface'] ?? ''));
    }

    public function testAiCoachRecommendationsExposeMarketplaceActionFields(): void
    {
        $seed = $this->seedWorkspace('marketplace-coach-actions');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = 'whatsapp' WHERE workspace_id = ?",
            [(int) $seed['workspace_id']]
        );
        $this->installAiCoachSkill($seed);
        $this->completeAiCoachOnboarding($seed);

        $get = $this->runWebEndpoint('api/ai-coach/recommendations.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($get['body'] ?? '');
        $data = json_decode($body, true) ?: [];
        $recommendations = (array) ($data['recommendations'] ?? []);
        $activationBundleGuidance = (array) ($recommendations['activation_bundle_guidance'] ?? []);
        $marketplaceItem = null;
        foreach (array_merge((array) ($recommendations['foundation_gaps'] ?? []), (array) ($recommendations['missing_features'] ?? [])) as $item) {
            if (!empty($item['marketplace_setup_url'])) {
                $marketplaceItem = $item;
                break;
            }
        }

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('AI Coach is installed as an orchestrator', (string) ($recommendations['why_this_matters'] ?? ''));
        $this->assertSame([], (array) ($recommendations['priorities'] ?? []));
        $this->assertSame([], (array) ($recommendations['quick_wins'] ?? []));
        $this->assertIsArray($marketplaceItem, $body);
        $this->assertSame('Open Marketplace', $marketplaceItem['marketplace_cta_label'] ?? null);
        $this->assertNotEmpty($marketplaceItem['marketplace_setup_url'] ?? '');
        $this->assertSame('coach_setup_required', $marketplaceItem['source_recommendation_type'] ?? null);
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $marketplaceItem['marketplace_skill_key'] ?? null);
        $this->assertArrayNotHasKey('score', $activationBundleGuidance);
        $this->assertStringNotContainsString('password', strtolower($body));
        $this->assertStringNotContainsString('secret', strtolower($body));

        $event = Database::queryOne(
            "SELECT event_type, surface
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'impression'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertNull($event);
    }

    public function testAiCoachRecommendationsAreBlockedUntilWorkspaceUserOnboardingCompletes(): void
    {
        $seed = $this->seedWorkspace('coach-onboarding-required');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);
        $this->completeStartupJourneySetup($seed);

        $get = $this->runWebEndpoint('api/ai-coach/recommendations.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $data = json_decode((string) ($get['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertFalse((bool) ($data['recommendations_ready'] ?? true));
        $this->assertSame([], (array) ($data['recommendations']['priorities'] ?? []));
        $fields = array_map(static fn(array $item): string => (string) ($item['field'] ?? ''), (array) ($data['missing_requirements'] ?? []));
        $this->assertContains('company_context_ready', $fields, json_encode($data));
        $this->assertContains('company_name', $fields);
        $this->assertContains('product_name', $fields);
    }

    public function testMobileAiCoachOnboardingGetReturnsReadiness(): void
    {
        $seed = $this->seedWorkspace('mobile-coach-readiness');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);

        $response = $this->runEndpointScript('api/mobile/ai/onboarding.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken($seed)],
        ]);
        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertFalse((bool) ($data['recommendations_ready'] ?? true));
        $this->assertIsArray($data['missing_requirements'] ?? null);
        $this->assertIsArray($data['onboarding_payload'] ?? null);
        $this->assertIsString($data['operating_maturity'] ?? null);
        $this->assertIsArray($data['operating_maturity_context'] ?? null);
    }

    public function testMobileAiCoachRecommendationsAreBlockedUntilOnboardingCompletes(): void
    {
        $seed = $this->seedWorkspace('mobile-coach-blocked');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);

        $response = $this->runEndpointScript('api/mobile/ai/recommendations.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken($seed)],
        ]);
        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $fields = array_map(static fn(array $item): string => (string) ($item['field'] ?? ''), (array) ($data['missing_requirements'] ?? []));

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertFalse((bool) ($data['recommendations_ready'] ?? true));
        $this->assertSame([], (array) ($data['sections']['priority_actions'] ?? ['unexpected']));
        $this->assertSame([], (array) ($data['cards'] ?? ['unexpected']));
        $this->assertIsString($data['operating_maturity'] ?? null);
        $this->assertIsArray($data['operating_maturity_context'] ?? null);
        $this->assertIsArray($data['assumption_conflicts'] ?? null);
        $this->assertContains('company_context_ready', $fields, json_encode($data));
        $this->assertContains('company_name', $fields);
        $this->assertContains('product_name', $fields);
    }

    public function testWebAiCoachPersonalBriefDoesNotMutateSharedCompanyContext(): void
    {
        $seed = $this->seedWorkspace('web-coach-personal-brief');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);
        $this->completeStartupJourneySetup($seed);
        (new UserPreferences())->setAICoachEnabled((int) $seed['user_id'], true);
        $workspaceOnboarding = new WorkspaceOnboardingService();
        $workspaceOnboarding->saveStep((int) $seed['workspace_id'], (int) $seed['user_id'], 1, [
            'company_name' => 'Shared Web Company',
            'company_industry' => 'Professional services',
            'company_description' => 'Shared context owned by the workspace.',
            'success_outcome' => 'Team-wide operating clarity.',
        ]);
        $workspaceOnboarding->saveStep((int) $seed['workspace_id'], (int) $seed['user_id'], 2, [
            'product_name' => 'Shared Web Offer',
            'product_description' => 'The canonical product description.',
            'target_audience' => 'Shared buyer segment',
            'pricing_info' => 'Starts at 750 USD',
        ]);
        $companyBefore = (new CompanyProfile())->get() ?: [];
        $productBefore = (array) ((new Products())->list()[0] ?? []);

        $response = $this->runWebEndpoint('api/ai-coach/onboarding.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'target_market_focus' => 'Founder-led agencies',
                'ideal_customer_profile' => 'Agency owners with slow follow-up',
                'offer_angle' => 'Outcome-first CRM coaching',
                'market_view' => 'Agencies want proof before changing operations.',
                'strategy_hypothesis' => 'Proof audits will outperform generic CRM demos.',
                'value_proposition' => 'Recover neglected pipeline conversations.',
                'target_market' => 'Small agencies',
                'pain_points' => 'Inconsistent handoffs',
                'competitors' => 'Spreadsheets and generic CRMs',
                'differentiator' => 'Personal coaching tied to workspace activity',
            ],
        ]);
        $data = json_decode((string) ($response['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $this->assertTrue((bool) ($data['personal_brief_ready'] ?? false));
        $this->assertIsArray($data['active_strategy_snapshot'] ?? null);
        $this->assertSame($companyBefore['company_name'] ?? null, (new CompanyProfile())->get()['company_name'] ?? null);
        $this->assertSame($productBefore['name'] ?? null, (new Products())->list()[0]['name'] ?? null);
    }

    public function testWebAiCoachOnboardingAcceptsEmptyOptionalStrategyWhenJourneyReady(): void
    {
        $seed = $this->seedWorkspace('web-coach-empty-optional');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);
        $this->completeSharedAiCoachFoundation($seed);

        $response = $this->runWebEndpoint('api/ai-coach/onboarding.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
            ],
        ]);
        $data = json_decode((string) ($response['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $this->assertTrue((bool) ($data['onboarding_complete'] ?? false));
        $this->assertTrue((bool) ($data['recommendations_ready'] ?? false));
        $this->assertTrue((bool) ($data['personal_strategy_optional'] ?? false));
        $this->assertSame([], (array) ($data['missing_requirements'] ?? ['unexpected']));
    }

    public function testMobileAiCoachOnboardingPostCompletesReadiness(): void
    {
        $seed = $this->seedWorkspace('mobile-coach-submit');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);
        $this->completeStartupJourneySetup($seed);
        (new UserPreferences())->setAICoachEnabled((int) $seed['user_id'], true);
        $workspaceOnboarding = new WorkspaceOnboardingService();
        $workspaceOnboarding->saveStep((int) $seed['workspace_id'], (int) $seed['user_id'], 1, [
            'company_name' => 'Shared Company Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Shared company baseline that personal briefs must not overwrite.',
            'success_outcome' => 'A stable operating baseline for the team.',
        ]);
        $workspaceOnboarding->saveStep((int) $seed['workspace_id'], (int) $seed['user_id'], 2, [
            'product_name' => 'Shared CRM OS',
            'product_description' => 'The canonical company offer.',
            'target_audience' => 'Operations teams',
            'pricing_info' => 'Starts at 500 USD',
        ]);
        $companyBefore = (new CompanyProfile())->get() ?: [];
        $productBefore = (array) ((new Products())->list()[0] ?? []);

        $response = $this->runEndpointScript('api/mobile/ai/onboarding.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken($seed),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => json_encode([
                'target_market_focus' => 'Founder-led service firms',
                'ideal_customer_profile' => 'Operators who need follow-up discipline',
                'offer_angle' => 'CRM execution with practical AI support',
                'sales_motion' => 'Consultative owner-led sales',
                'value_proposition' => 'Turn messy follow-up into a clear operating system',
                'target_market' => 'Small service businesses',
                'pain_points' => 'Dropped leads and slow handoffs',
                'differentiator' => 'Workspace-specific recommendations tied to CRM activity',
            ]),
        ]);
        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($data['recommendations_ready'] ?? false));
        $this->assertTrue((bool) ($data['personal_brief_ready'] ?? false));
        $this->assertIsArray($data['active_strategy_snapshot'] ?? null);
        $this->assertSame([], (array) ($data['missing_requirements'] ?? ['unexpected']));
        $this->assertSame($companyBefore['company_name'] ?? null, (new CompanyProfile())->get()['company_name'] ?? null);
        $this->assertSame($productBefore['name'] ?? null, (new Products())->list()[0]['name'] ?? null);

        $recommendations = $this->runEndpointScript('api/mobile/ai/recommendations.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken($seed)],
        ]);
        $recommendationsPayload = $this->decodeJsonResponse($recommendations);
        $recommendationsData = (array) ($recommendationsPayload['data'] ?? []);

        $this->assertSame(200, (int) ($recommendations['status'] ?? 0), (string) ($recommendations['stderr'] ?? ''));
        $this->assertTrue((bool) ($recommendationsData['recommendations_ready'] ?? false));
        $this->assertIsArray($recommendationsData['sections']['priority_actions'] ?? null);
        $this->assertIsString($recommendationsData['operating_maturity'] ?? null);
        $this->assertIsArray($recommendationsData['operating_maturity_context'] ?? null);
        $this->assertIsArray($recommendationsData['assumption_conflicts'] ?? null);
    }

    public function testMobileAiCoachOnboardingAcceptsEmptyOptionalStrategyWhenJourneyReady(): void
    {
        $seed = $this->seedWorkspace('mobile-coach-empty-optional');
        $this->activateSession($seed, 'owner');
        $this->syncWorkspaceSkillDefinitionsForTest();
        $this->installAiCoachSkill($seed);
        $this->completeSharedAiCoachFoundation($seed);

        $response = $this->runEndpointScript('api/mobile/ai/onboarding.php', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mobileAccessToken($seed),
                'Content-Type' => 'application/json',
            ],
            'raw_body' => '{}',
        ]);
        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($payload['onboarding_complete'] ?? false));
        $this->assertTrue((bool) ($data['recommendations_ready'] ?? false));
        $this->assertTrue((bool) ($data['personal_strategy_optional'] ?? false));
        $this->assertSame([], (array) ($data['missing_requirements'] ?? ['unexpected']));
    }

    public function testCoachMarketplaceFeedbackPersistsWithCoachSource(): void
    {
        $seed = $this->seedWorkspace('marketplace-coach-feedback');

        $post = $this->runWebEndpoint('api/chat/marketplace_feedback.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'feedback_type' => 'dismissed',
                'source' => 'coach',
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $row = Database::queryOne(
            "SELECT feedback_type, reason_code, metadata_json
             FROM workspace_marketplace_recommendation_feedback
             WHERE workspace_id = ? AND skill_key = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('dismissed', (string) ($row['feedback_type'] ?? ''));
        $this->assertSame('coach', (string) ($row['reason_code'] ?? ''));
        $this->assertStringContainsString('coach', (string) ($row['metadata_json'] ?? ''));

        $event = Database::queryOne(
            "SELECT event_type, surface
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'dismissed'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('coach', (string) ($event['surface'] ?? ''));
    }

    public function testMarketplaceRecommendationClickEndpointRecordsCtaEvents(): void
    {
        $seed = $this->seedWorkspace('marketplace-event-click');

        $badCsrf = $this->runWebEndpoint('api/workspace/marketplace_recommendation_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'surface' => 'coach',
                'event_type' => 'cta_clicked',
            ],
        ]);
        $this->assertSame(403, (int) ($badCsrf['status'] ?? 0));

        $post = $this->runWebEndpoint('api/workspace/marketplace_recommendation_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'surface' => 'coach',
                'event_type' => 'cta_clicked',
                'source' => 'coach',
                'target_url' => 'workspace_skills.php?source=coach',
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $event = Database::queryOne(
            "SELECT event_type, surface, metadata_json
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'cta_clicked'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('coach', (string) ($event['surface'] ?? ''));
        $this->assertStringContainsString('workspace_skills.php', (string) ($event['metadata_json'] ?? ''));
    }

    public function testMarketplaceAsyncEndpointsReturnCachedPayloadsAndProtectPerformance(): void
    {
        $seed = $this->seedWorkspace('marketplace-async-endpoints');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);

        $recommendations = $this->runWebEndpoint('api/workspace/marketplace_recommendations.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $recommendationData = $this->decodeJsonResponse($recommendations);
        $this->assertSame(200, (int) ($recommendations['status'] ?? 0), (string) ($recommendations['stderr'] ?? ''));
        $this->assertTrue((bool) ($recommendationData['success'] ?? false));
        $this->assertIsArray($recommendationData['recommendations'] ?? null);

        $catalogStatus = $this->runWebEndpoint('api/workspace/marketplace_catalog_status.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $catalogStatusData = $this->decodeJsonResponse($catalogStatus);
        $this->assertSame(200, (int) ($catalogStatus['status'] ?? 0), (string) ($catalogStatus['stderr'] ?? ''));
        $this->assertTrue((bool) ($catalogStatusData['success'] ?? false));
        $this->assertIsArray($catalogStatusData['modules'] ?? null);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_FINANCE, $catalogStatusData['modules']);

        $blockedCatalogStatus = $this->runWebEndpoint('api/workspace/marketplace_catalog_status.php', [], [
            'method' => 'GET',
        ]);
        $this->assertSame(401, (int) ($blockedCatalogStatus['status'] ?? 0));

        $detail = $this->runWebEndpoint('api/workspace/marketplace_module_detail.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE],
        ]);
        $detailData = $this->decodeJsonResponse($detail);
        $this->assertSame(200, (int) ($detail['status'] ?? 0), (string) ($detail['stderr'] ?? ''));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_FINANCE, (string) ($detailData['module']['key'] ?? ''));
        $this->assertIsArray($detailData['readiness'] ?? null);
        $this->assertIsArray($detailData['access'] ?? null);

        $performance = $this->runWebEndpoint('api/workspace/marketplace_performance.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['module' => WorkspaceSkillCatalogService::PLUGIN_FINANCE],
        ]);
        $this->assertSame(403, (int) ($performance['status'] ?? 0));
    }

    public function testMarketplaceActivationBundleClickEndpointRecordsCtaEvents(): void
    {
        $seed = $this->seedWorkspace('marketplace-bundle-event-click');

        $badCsrf = $this->runWebEndpoint('api/workspace/marketplace_activation_bundle_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad',
                'bundle_key' => 'whatsapp_led_growth',
                'event_type' => 'cta_clicked',
            ],
        ]);
        $this->assertSame(403, (int) ($badCsrf['status'] ?? 0));

        $viewerId = (int) Auth::createUser(
            'marketplace.bundle.click.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Bundle',
            'Viewer'
        );
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.bundle.click.viewer@example.test',
        ]);
        $viewerPost = $this->runWebEndpoint('api/workspace/marketplace_activation_bundle_event.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'bundle_key' => 'whatsapp_led_growth',
                'event_type' => 'cta_clicked',
            ],
        ]);
        $this->assertSame(403, (int) ($viewerPost['status'] ?? 0));

        $post = $this->runWebEndpoint('api/workspace/marketplace_activation_bundle_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'bundle_key' => 'whatsapp_led_growth',
                'event_type' => 'cta_clicked',
                'source' => 'coach',
                'surface' => 'coach',
                'target_url' => 'workspace_skills.php?activation_bundle_key=whatsapp_led_growth',
                'next_action_skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $event = Database::queryOne(
            "SELECT event_type, surface, metadata_json
             FROM workspace_marketplace_activation_bundle_events
             WHERE workspace_id = ? AND bundle_key = ? AND event_type = 'cta_clicked'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], 'whatsapp_led_growth']
        );
        $this->assertSame('marketplace', (string) ($event['surface'] ?? ''));
        $this->assertStringContainsString('coach', (string) ($event['metadata_json'] ?? ''));
        $this->assertStringContainsString('workspace_skills.php', (string) ($event['metadata_json'] ?? ''));
        $this->assertStringContainsString(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (string) ($event['metadata_json'] ?? ''));
    }

    public function testMarketplaceSetupJourneyClickEndpointRecordsSetupOpenEvents(): void
    {
        $seed = $this->seedWorkspace('marketplace-journey-click');

        $badCsrf = $this->runWebEndpoint('api/workspace/marketplace_setup_journey_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'event_type' => 'setup_opened',
            ],
        ]);
        $this->assertSame(403, (int) ($badCsrf['status'] ?? 0));

        $viewerId = (int) Auth::createUser(
            'marketplace.journey.click.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Journey',
            'Viewer'
        );
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.journey.click.viewer@example.test',
        ]);
        $viewerPost = $this->runWebEndpoint('api/workspace/marketplace_setup_journey_event.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'event_type' => 'setup_opened',
            ],
        ]);
        $this->assertSame(403, (int) ($viewerPost['status'] ?? 0));

        $post = $this->runWebEndpoint('api/workspace/marketplace_setup_journey_event.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'event_type' => 'setup_opened',
                'label' => 'WhatsApp Assistant',
                'source' => 'workspace_marketplace_page',
                'target_url' => 'settings.php?tab=whatsapp_assistant',
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $this->assertTrue($this->setupJourneyEventExists((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'setup_opened'));
    }

    public function testCoachMarketplaceTaskCreationRecordsTaskCreatedEvent(): void
    {
        $seed = $this->seedWorkspace('marketplace-task-created');

        $post = $this->runWebEndpoint('api/tasks/create.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'title' => 'Review WhatsApp Assistant setup',
                'metadata_json' => json_encode([
                    'source_surface' => 'ai_coach',
                    'recommendation_key' => 'marketplace_whatsapp_assistant',
                    'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                    'marketplace_setup_url' => 'workspace_skills.php?source=coach',
                ], JSON_UNESCAPED_SLASHES),
            ],
        ]);
        $data = json_decode((string) ($post['body'] ?? ''), true) ?: [];

        $this->assertSame(200, (int) ($post['status'] ?? 0), (string) ($post['stderr'] ?? ''));
        $this->assertTrue((bool) ($data['success'] ?? false));
        $event = Database::queryOne(
            "SELECT event_type, surface, metadata_json
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = 'task_created'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        $this->assertSame('coach', (string) ($event['surface'] ?? ''));
        $this->assertStringContainsString((string) ($data['task_id'] ?? ''), (string) ($event['metadata_json'] ?? ''));
    }

    public function testClarityMarketplaceFeedbackEndpointRejectsViewer(): void
    {
        $seed = $this->seedWorkspace('marketplace-chat-feedback-viewer');
        $viewerId = (int) Auth::createUser(
            'marketplace.chat.feedback.viewer.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'user',
            'Market',
            'Viewer'
        );
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership((int) $seed['workspace_id'], $viewerId, 'viewer', false, (int) $seed['user_id']);
        $viewerSeed = array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => $membershipId,
            'email' => 'marketplace.chat.feedback.viewer@example.test',
        ]);

        $post = $this->runWebEndpoint('api/chat/marketplace_feedback.php', $this->webSession($viewerSeed, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'feedback_type' => 'dismissed',
            ],
        ]);

        $this->assertSame(403, (int) ($post['status'] ?? 0));
    }

    public function testAiOperatingContextIncludesInstalledSkills(): void
    {
        $seed = $this->seedWorkspace('skills-ai');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);
        $installer = new WorkspaceSkillInstallService();
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeStartupJourneySetup($seed);
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, (int) $seed['user_id']);
        $this->completeAiCoachOnboarding($seed);
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO, (int) $seed['user_id']);

        $context = (new AIOperatingContextService())->buildForSurface((int) $seed['user_id'], 'coach');

        $this->assertContains(
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            (array) ($context['workspace_modules']['plugin_keys'] ?? [])
        );
        $this->assertArrayHasKey(
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            (array) ($context['workspace_modules']['plugins_context'] ?? [])
        );
    }

    public function testPluginInstallsAreScopedAndExposeReadinessContextWithoutSecrets(): void
    {
        $first = $this->seedWorkspace('plugins-one');
        $second = $this->seedWorkspace('plugins-two');
        $this->activateSession($first, 'owner');
        $this->completeCommunicationSetup($first);

        $installer = new WorkspaceSkillInstallService();

        if ((new WorkspaceAssistantConfigService())->isAvailable()) {
            (new WorkspaceAssistantConfigService())->save((int) $first['workspace_id'], 'email', [
                'smtp_host' => 'smtp.example.test',
                'smtp_username' => 'assistant@example.test',
                'smtp_password' => 'super-secret',
                'from_email' => 'assistant@example.test',
                'from_name' => 'Assistant',
                'imap_host' => 'imap.example.test',
                'imap_username' => 'assistant@example.test',
                'imap_password' => 'imap-secret',
            ], true, (int) $first['user_id']);
        }

        $installer->install((int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, (int) $first['user_id']);

        $this->assertTrue($installer->isInstalled((int) $first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT));
        $this->assertFalse($installer->isInstalled((int) $second['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT));

        $context = (new AIOperatingContextService())->buildForSurface((int) $first['user_id'], 'coach');
        $this->assertContains(
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            (array) ($context['workspace_modules']['plugin_keys'] ?? [])
        );
        $this->assertArrayHasKey(
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            (array) ($context['workspace_modules']['plugins_context'] ?? [])
        );

        $encoded = json_encode($context['workspace_modules'], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('super-secret', $encoded);
        $this->assertStringNotContainsString('imap-secret', $encoded);
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix, bool $grantMarketplaceAccess = true): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Workspace Skills ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Skills',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $this->syncWorkspaceSkillDefinitionsForTest();
        if ($grantMarketplaceAccess) {
            $this->grantRolePermissions('owner', ['workspace.skills.view', 'workspace.skills.manage']);
        }
        Authorization::assignUserRoleBySlug($userId, 'owner', $userId);
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
        ];
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function defaultWorkspaceUserSeed(string $emailPrefix, string $workspaceRole, bool $superadmin): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $email = $emailPrefix . '.' . $suffix . '@example.test';
        $userId = (int) Auth::createUser(
            $email,
            'P@ssword123!',
            $superadmin ? 'admin' : 'user',
            'Default',
            $superadmin ? 'Superadmin' : 'Owner'
        );
        $workspaceRole = $superadmin ? 'superadmin' : $workspaceRole;
        Authorization::assignUserRoleBySlug($userId, $superadmin ? 'superadmin' : $workspaceRole, $userId);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, $workspaceRole, in_array($workspaceRole, ['owner', 'superadmin'], true), $userId);
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = 1") ?? [];

        return [
            'workspace_id' => 1,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? '00000000-0000-4000-8000-000000000001'),
            'workspace_slug' => (string) ($workspace['slug'] ?? 'default'),
            'workspace_name' => (string) ($workspace['name'] ?? 'Default Workspace'),
            'membership_id' => (int) $membershipId,
            'email' => $email,
        ];
    }

    private function forceCompassFreeEntitlements(int $workspaceId): void
    {
        if (Database::tableExists('workspace_subscriptions')) {
            Database::execute("DELETE FROM workspace_subscriptions WHERE workspace_id = ?", [$workspaceId]);
        }
        Database::execute(
            "UPDATE workspaces
             SET plan_status = 'active',
                 trial_starts_at = NULL,
                 trial_ends_at = NULL
             WHERE id = ?",
            [$workspaceId]
        );
    }

    private function setWorkspacePlan(int $workspaceId, string $priceCode): void
    {
        $price = (new WorkspacePlanEntitlementService())->priceByCode($priceCode);
        $this->assertIsArray($price);
        $this->assertGreaterThan(0, (int) ($price['id'] ?? 0));

        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) $price['id'], $workspaceId]
        );
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function grantRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 1
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$permissionKey, $roleSlug]
            );
        }
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function revokeRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "UPDATE role_permissions rp
                 JOIN roles r ON r.id = rp.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 SET rp.can_access = 0
                 WHERE r.slug = ?
                   AND p.permission_key = ?",
                [$roleSlug, $permissionKey]
            );
        }
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed, string $role): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('__remember_restore_attempted', true);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', $role);
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], $role);
    }

    private function completeOnboarding(int $workspaceId): void
    {
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 completed_at = COALESCE(completed_at, NOW())
             WHERE workspace_id = ?",
            [$workspaceId]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeFinanceSetup(array $seed): void
    {
        (new WorkspaceFinanceGateService(new WorkspaceSkillInstallService()))->saveInitialSetup((int) $seed['workspace_id'], [
            'currency' => 'USD',
            'opening_date' => date('Y-m-d'),
            'opening_cash' => '1000.00',
            'opening_receivables' => '0.00',
            'opening_payables' => '0.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '0.00',
            'opening_equity' => '1000.00',
            'notes' => 'Test finance readiness.',
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                    'opening_owner_draws' => '0.00',
                    'notes' => 'Test owner profile.',
                ],
            ],
        ], (int) $seed['user_id']);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeCommunicationSetup(array $seed): void
    {
        if (Database::tableExists('email_integrations')) {
            Database::execute(
                "INSERT INTO email_integrations (
                    workspace_id, provider, scope, access_token, email_address, is_active,
                    connected_by_user_id, settings_json
                 ) VALUES (?, 'manual_smtp', ?, 'test-token', 'outreach@example.test', 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    access_token = VALUES(access_token),
                    email_address = VALUES(email_address),
                    is_active = 1,
                    connected_by_user_id = VALUES(connected_by_user_id),
                    settings_json = VALUES(settings_json),
                    updated_at = NOW()",
                [
                    (int) $seed['workspace_id'],
                    EmailIntegrationService::SCOPE_OUTREACH_EMAIL,
                    (int) $seed['user_id'],
                    json_encode([
                        'smtp_host' => 'smtp.example.test',
                        'smtp_port' => 587,
                        'smtp_username' => 'outreach@example.test',
                        'smtp_password' => 'saved',
                        'smtp_encryption' => 'tls',
                        'imap_enabled' => true,
                        'imap_host' => 'imap.example.test',
                        'imap_port' => 993,
                        'imap_username' => 'outreach@example.test',
                        'imap_password' => 'saved',
                        'imap_encryption' => 'ssl',
                        'imap_folder' => 'INBOX',
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
        (new WorkspaceAssistantConfigService())->save((int) $seed['workspace_id'], 'email', [
            'system_email' => 'assistant@example.test',
            'from_email' => 'assistant@example.test',
            'from_name' => 'Workspace Assistant',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'smtp_username' => 'assistant@example.test',
            'smtp_password' => 'smtp-secret',
            'smtp_encryption' => 'tls',
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => '993',
            'imap_username' => 'assistant@example.test',
            'imap_password' => 'imap-secret',
            'imap_encryption' => 'ssl',
            'imap_folder' => 'INBOX',
        ], true, (int) $seed['user_id']);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function seedReadyWhatsAppChannel(array $seed): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = 'installed',
                config_json = VALUES(config_json),
                uninstalled_at = NULL,
                disabled_at = NULL,
                updated_at = NOW()",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations (
                workspace_id, connected_by_user_id, meta_business_id, whatsapp_business_account_id,
                phone_number_id, display_phone_number, verified_name, access_token, connection_status,
                settings_json, webhook_token, webhook_verify_token, connected_at
             ) VALUES (?, ?, 'business-test', 'waba-test', 'first-phone-number-id', '+15550001111',
                'Workspace WhatsApp', 'test-access-token', 'connected', ?, 'webhook-token-test',
                'verify-token-test', NOW())
             ON DUPLICATE KEY UPDATE
                connected_by_user_id = VALUES(connected_by_user_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                access_token = VALUES(access_token),
                connection_status = 'connected',
                settings_json = VALUES(settings_json),
                webhook_token = VALUES(webhook_token),
                webhook_verify_token = VALUES(webhook_verify_token),
                disconnected_at = NULL,
                updated_at = NOW()",
            [
                (int) $seed['workspace_id'],
                (int) $seed['user_id'],
                json_encode(['setup_mode' => 'manual'], JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function installAiCoachSkill(array $seed): void
    {
        foreach ([WorkspaceSkillCatalogService::PLUGIN_FINANCE, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, WorkspaceSkillCatalogService::SKILL_AI_COACH] as $skillKey) {
            Database::execute(
                "INSERT INTO workspace_skill_installs (
                    workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
                 ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    status = 'installed',
                    config_json = VALUES(config_json),
                    uninstalled_at = NULL,
                    disabled_at = NULL,
                    updated_at = NOW()",
                [
                    (int) $seed['workspace_id'],
                    $skillKey,
                    (int) $seed['user_id'],
                    (int) $seed['user_id'],
                ]
            );
        }

        if (Database::tableExists('finance_owner_equity_profiles')) {
            Database::execute(
                "INSERT INTO finance_owner_equity_profiles (
                    workspace_id, user_id, ownership_percent, opening_owner_capital,
                    opening_owner_draws, currency, notes, is_active, created_by
                 ) VALUES (?, ?, 100.0000, 0.00, 0.00, 'USD', 'Test finance setup', 1, ?)
                 ON DUPLICATE KEY UPDATE
                    ownership_percent = 100.0000,
                    is_active = 1,
                    updated_at = NOW()",
                [(int) $seed['workspace_id'], (int) $seed['user_id'], (int) $seed['user_id']]
            );
        }

        $openingTransactionId = 0;
        if (Database::tableExists('finance_transactions')) {
            Database::execute(
                "INSERT INTO finance_transactions (
                    workspace_id, transaction_type, transaction_date, amount, currency, counterparty, memo, created_by
                 ) VALUES (?, 'opening_balance', CURDATE(), 0.00, 'USD', 'Opening setup', 'Test finance setup', ?)",
                [(int) $seed['workspace_id'], (int) $seed['user_id']]
            );
            $openingTransactionId = (int) Database::lastInsertId();
        }

        if (Database::tableExists('finance_opening_setups')) {
            Database::execute(
                "INSERT INTO finance_opening_setups (
                    workspace_id, opening_date, currency, notes, start_reviewed, bank_reviewed,
                    assets_reviewed, receivables_reviewed, liabilities_reviewed, owners_reviewed,
                    is_complete, completed_at, opening_transaction_id, created_by, updated_by
                 ) VALUES (?, CURDATE(), 'USD', 'Test finance setup', 1, 1, 1, 1, 1, 1, 1, NOW(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    start_reviewed = 1,
                    bank_reviewed = 1,
                    assets_reviewed = 1,
                    receivables_reviewed = 1,
                    liabilities_reviewed = 1,
                    owners_reviewed = 1,
                    is_complete = 1,
                    completed_at = COALESCE(completed_at, NOW()),
                    opening_transaction_id = VALUES(opening_transaction_id),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()",
                [(int) $seed['workspace_id'], $openingTransactionId > 0 ? $openingTransactionId : null, (int) $seed['user_id'], (int) $seed['user_id']]
            );
        }
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function installCalendarMeetings(array $seed): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                (int) $seed['workspace_id'],
                WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                (int) $seed['user_id'],
                (int) $seed['user_id'],
            ]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeStartupJourneySetup(array $seed): void
    {
        $service = new StartupJourneyService();
        $responses = [
            'customer_discovery' => [
                'target_customer' => 'Founder-led service firms',
                'interview_count' => '10 customer interviews completed.',
                'observed_problem' => 'Founders lose warm deals because follow-up ownership is unclear.',
                'evidence' => 'Interview notes showed missed next steps and stale opportunities.',
                'riskiest_assumption' => 'Founders will pay for guided execution before full automation.',
            ],
            'jobs_to_be_done' => [
                'job_statement' => 'Move a qualified lead from conversation to a priced next step.',
                'triggers' => 'A referral, demo request, or overdue proposal makes the job urgent.',
                'current_alternatives' => 'Spreadsheets and generic CRM tools.',
                'desired_outcomes' => 'Every qualified lead has a next step and owner.',
                'success_criteria' => 'The founder can see what to do this week.',
            ],
            'value_proposition' => [
                'customer_jobs' => 'Keep sales follow-up moving.',
                'pains' => 'Dropped leads and unclear handoffs.',
                'gains' => 'A weekly operating rhythm for first deals.',
                'products_services' => 'AI Coach and Founder Loop execution support.',
                'pain_relievers' => 'Turns CRM evidence into next-step tasks.',
                'gain_creators' => 'Creates a learning loop from weekly commitments.',
            ],
            'lean_canvas' => [
                'problem' => 'First deals stall when business context is not converted into action.',
                'customer_segments' => 'Founder-led service firms',
                'unique_value_proposition' => 'Turn Clarity Journey into first-deal execution.',
                'solution' => 'Clarity Journey, Founder Loop, and AI Coach recommendations.',
                'channels' => 'Partner referrals and founder outbound.',
                'revenue_streams' => 'Monthly subscriptions and paid pilots.',
                'cost_structure' => 'AI usage, onboarding, and support.',
                'key_metrics' => 'Qualified conversations, open deals, and paid pilots.',
                'unfair_advantage' => 'Unified Journey, Founder Loop, CRM, and finance context.',
            ],
            'mvp' => [
                'mvp_hypothesis' => 'Weekly recommendations from saved context will move first deals faster.',
                'smallest_test' => 'Run a four-week pilot with manually reviewed Coach recommendations.',
                'required_features' => 'Journey context, tasks, deals, and weekly review.',
                'success_metric' => 'Three teams create qualified opportunities within 30 days.',
                'experiment_budget' => 'Four weeks and 1000 USD.',
            ],
            'go_to_market' => [
                'beachhead_segment' => 'Founder-led service firms',
                'message' => 'Turn your business foundation into the next first-deal action.',
                'channels' => 'Direct outreach and partner referrals.',
                'sales_motion' => 'Consultative founder-led sales.',
                'launch_plan' => 'Recruit ten pilots and convert three to paid plans.',
                'conversion_goal' => 'Close three paid pilots in 45 days.',
            ],
            'aarrr' => [
                'acquisition' => 'Partner referrals and direct founder outreach.',
                'activation' => 'Complete Journey and create Founder Loop commitments.',
                'retention' => 'Weekly Coach recommendations keep deals moving.',
                'referral' => 'Founders share the operating rhythm with peers.',
                'revenue' => 'Paid pilots convert to monthly plans.',
            ],
            'okrs' => [
                'objective' => 'Prove Clarity Journey can drive first-deal execution.',
                'key_result_1' => 'Complete ten Journeys.',
                'key_result_2' => 'Create 30 Founder Loop commitments.',
                'key_result_3' => 'Close three paid pilots.',
                'review_cadence' => 'Weekly Friday review.',
            ],
        ];

        foreach ($responses as $stageKey => $stageResponses) {
            $service->saveStage((int) $seed['workspace_id'], (int) $seed['user_id'], (string) $stageKey, $stageResponses, '', true);
        }
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeAiCoachOnboarding(array $seed): void
    {
        $this->activateSession($seed, 'owner');
        $this->completeStartupJourneySetup($seed);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $onboarding = new WorkspaceOnboardingService();
        $onboarding->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Coach Context Co',
            'company_industry' => 'Professional services',
            'company_description' => 'A service business that needs practical AI follow-up guidance.',
            'success_outcome' => 'Clear customer follow-up and reliable operating rhythm.',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 2, [
            'product_name' => 'CRM execution support',
            'pricing_info' => 'Starts at 500 USD',
            'product_description' => 'Practical CRM and follow-up operating support for service teams.',
            'target_audience' => 'Founder-led service firms',
            'target_market_focus' => 'Founder-led service firms',
            'ideal_customer_profile' => 'Operations owners who need reliable follow-up',
            'offer_angle' => 'CRM execution with practical AI support',
            'market_view' => 'Founder-led service firms are under-served by generic CRM automation.',
            'strategy_hypothesis' => 'Lead with follow-up discipline and measure pipeline movement from recovered conversations.',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
            'words_to_avoid' => 'unsupported promises',
            'escalation_preference' => 'Payment disputes and complaints',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'work_with_me',
            'automation_launch_mode' => 'manual_review',
            'ai_autoresponder_mode' => 'draft_only',
            'ai_best_practices_enabled' => '1',
            'commercial_layer_enabled' => '0',
            'deal_automation_enabled' => '0',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 5, []);
        (new UserPreferences())->setAICoachEnabled($userId, true);
        (new IdeaValidationContext())->save((int) $seed['user_id'], [
            'value_proposition' => 'Turn messy follow-up into a clear operating system',
            'target_market' => 'Small service businesses',
            'pain_points' => 'Dropped leads and slow customer handoffs',
            'competitors' => 'Generic CRMs and manual spreadsheet follow-up',
            'differentiator' => 'Workspace-specific recommendations tied to CRM activity',
        ]);
        (new AICoachReadinessService())->completeOnboarding($workspaceId, $userId);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeSharedAiCoachFoundation(array $seed): void
    {
        $this->activateSession($seed, 'owner');
        $this->completeStartupJourneySetup($seed);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $onboarding = new WorkspaceOnboardingService();
        $onboarding->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Coach Context Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Shared context owned by the workspace.',
            'success_outcome' => 'Team-wide operating clarity.',
        ]);
        $onboarding->saveStep($workspaceId, $userId, 2, [
            'product_name' => 'Shared Coach Offer',
            'product_description' => 'The canonical company offer.',
            'target_audience' => 'Founder-led service firms',
            'pricing_info' => 'Starts at 500 USD',
        ]);
        (new UserPreferences())->setAICoachEnabled($userId, true);
    }

    private function syncWorkspaceSkillDefinitionsForTest(): void
    {
        $reflection = new \ReflectionClass(WorkspaceSkillCatalogService::class);
        $definitionsSynced = $reflection->getProperty('definitionsSynced');
        $definitionsSynced->setAccessible(true);
        $definitionsSynced->setValue(false);
        $contractColumnsReady = $reflection->getProperty('contractColumnsReady');
        $contractColumnsReady->setAccessible(true);
        $contractColumnsReady->setValue(null);

        (new WorkspaceSkillCatalogService())->syncDefinitions();
    }

    private function firstGuidedDestinationHtml(string $body): string
    {
        $stripStart = strpos($body, '<section class="marketplace-guided-destination"');
        $stripEnd = $stripStart === false ? false : strpos($body, '</section>', $stripStart);

        return $stripStart !== false && $stripEnd !== false
            ? substr($body, $stripStart, $stripEnd - $stripStart)
            : '';
    }

    private function marketplaceCardHtml(string $body, string $skillKey): string
    {
        $pattern = '/<article class="marketplace-card"[^>]*>.*?data-marketplace-skill-key="' . preg_quote($skillKey, '/') . '".*?<\/article>/s';
        if (preg_match($pattern, $body, $match) !== 1) {
            return '';
        }

        return (string) ($match[0] ?? '');
    }

    private function marketplaceSideNextActionHtml(string $body): string
    {
        $pattern = '/<section class="marketplace-side-panel marketplace-side-next-action">.*?<\/section>/s';
        if (preg_match($pattern, $body, $match) !== 1) {
            return '';
        }

        return (string) ($match[0] ?? '');
    }

    private function marketplaceCardGridHtml(string $body): string
    {
        $gridStart = strpos($body, '<div class="marketplace-card-grid" id="marketplace-card-grid">');
        if ($gridStart === false) {
            return '';
        }

        $gridEnd = strpos($body, '<div class="marketplace-card-video-modal"', $gridStart);
        if ($gridEnd === false || $gridEnd <= $gridStart) {
            return '';
        }

        return substr($body, $gridStart, $gridEnd - $gridStart);
    }

    private function ensureHrAnalyticsSetupState(int $workspaceId, bool $ready): void
    {
        $departmentSlug = 'hr_setup_' . $workspaceId;
        $departmentName = 'HR Setup ' . $workspaceId;
        Database::execute(
            "INSERT INTO hr_analytics_settings (
                workspace_id, ai_enabled, scoring_weights_json, thresholds_json, department_mappings_json, prompt_config_json
            ) VALUES (?, 1, '{}', '{}', '{}', '{}')
            ON DUPLICATE KEY UPDATE ai_enabled = VALUES(ai_enabled)",
            [$workspaceId]
        );
        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
             VALUES (?, ?, ?, 'HR setup department', 1, 1)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)",
            [$workspaceId, $departmentName, $departmentSlug]
        );
        Database::execute("UPDATE workspace_memberships SET department_id = NULL WHERE workspace_id = ?", [$workspaceId]);
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ?", [$workspaceId]);

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);

        if (!$ready) {
            Database::execute("UPDATE organization_functions SET is_active = 0 WHERE workspace_id = ?", [$workspaceId]);
            return;
        }

        Database::execute(
            "UPDATE organization_functions
             SET is_active = 1,
                 relevance_status = 'active'
             WHERE workspace_id = ?",
            [$workspaceId]
        );

        $department = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND slug = ? LIMIT 1",
            [$workspaceId, $departmentSlug]
        );
        $departmentId = (int) ($department['id'] ?? 0);
        $this->assertGreaterThan(0, $departmentId);
        Database::execute(
            "UPDATE workspace_memberships
             SET department_id = ?
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY id ASC
             LIMIT 1",
            [$departmentId, $workspaceId]
        );
        $member = Database::queryOne(
            "SELECT user_id FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY id ASC
             LIMIT 1",
            [$workspaceId]
        );
        $userId = (int) ($member['user_id'] ?? 0);
        $this->assertGreaterThan(0, $userId);
        $function = Database::queryOne(
            "SELECT id FROM organization_functions
             WHERE workspace_id = ?
               AND is_active = 1
               AND relevance_status = 'active'
             ORDER BY is_core DESC, name ASC
             LIMIT 1",
            [$workspaceId]
        );
        $functionId = (int) ($function['id'] ?? 0);
        $this->assertGreaterThan(0, $functionId);
        $functionService->saveUserAssignments($workspaceId, $userId, [$functionId], $functionId);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function addWorkspaceViewer(array $seed, string $email): array
    {
        $userId = (int) Auth::createUser($email, 'P@ssword123!', 'viewer', 'HR', 'Viewer');
        Authorization::assignUserRoleBySlug($userId, 'viewer', (int) $seed['user_id']);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'viewer', 'active', 0, NOW(), ?)
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status)",
            [(int) $seed['workspace_id'], $userId, (int) $seed['user_id']]
        );
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [(int) $seed['workspace_id'], $userId]
        ) ?? [];

        return array_merge($seed, [
            'user_id' => $userId,
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $email,
        ]);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function mobileAccessToken(array $seed): string
    {
        $session = (new MobileTokenAuthService())->issueTokenPair([
            'id' => (int) $seed['user_id'],
            'uuid' => 'workspace-skills-user',
            'email' => (string) $seed['email'],
            'role' => 'admin',
        ], [
            'workspace_id' => (int) $seed['workspace_id'],
            'workspace_slug' => (string) $seed['workspace_slug'],
        ]);

        return (string) ($session['access_token'] ?? '');
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));
        return $decoded;
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postFinanceSetupAutosave(array $seed, string $tab, array $post): array
    {
        return $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'post' => array_merge([
                'csrf_token' => 'csrf-workspace-skills',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'skill_action' => 'save_finance_setup',
                'finance_setup_tab' => $tab,
                'autosave' => '1',
            ], $post),
        ]);
    }

    private function seedMarketplaceInsightEvents(int $workspaceId, int $userId): void
    {
        $events = new WorkspaceMarketplaceRecommendationEventService();
        for ($i = 0; $i < 2; $i++) {
            $events->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'marketplace', 'impression', [
                'metadata' => ['label' => 'WhatsApp Assistant'],
            ]);
        }
        $events->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'marketplace', 'dismissed');
        $events->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'marketplace', 'snoozed');
    }

    private function setupStepStatus(int $workspaceId, string $skillKey, string $stepKey): ?string
    {
        $row = Database::queryOne(
            "SELECT status
             FROM workspace_marketplace_setup_journey_steps
             WHERE workspace_id = ? AND skill_key = ? AND step_key = ?
             LIMIT 1",
            [$workspaceId, $skillKey, $stepKey]
        );

        return isset($row['status']) ? (string) $row['status'] : null;
    }

    private function setupJourneyEventExists(int $workspaceId, string $skillKey, string $eventType): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ? AND skill_key = ? AND event_type = ?",
            [$workspaceId, $skillKey, $eventType]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function clearMarketplaceVideoReferenceTables(): void
    {
        Database::execute('DELETE FROM workspace_skill_catalog_overrides');
        Database::execute('DELETE FROM workspace_marketplace_activation_bundle_definitions');
        Database::execute('DELETE FROM marketplace_page_explainers');
    }

    private function makeMarketplaceUploadRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_marketplace_video_purge_' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace', 0777, true);
        $this->tempUploadRoots[] = $root;

        return $root;
    }

    private function writeUploadFile(string $projectRoot, string $relativePath, string $contents): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($directory);
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceWhatsAppPayload(string $messageId, string $phoneNumberId, string $wabaId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+254 700 666 666',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Workspace Sender'],
                            'wa_id' => '254700666001',
                        ]],
                        'messages' => [[
                            'from' => '254700666001',
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => 'Workspace-scoped webhook test'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function postWhatsAppWebhookPayload(array $payload, array $query, string $appKey): array
    {
        return $this->runEndpointScript('api/webhooks/whatsapp.php', [
            'method' => 'POST',
            'query' => $query,
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'env' => ['APP_KEY' => $appKey],
        ]);
    }

    private function assertWhatsAppMessageMissing(string $messageId): void
    {
        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?",
            [$messageId]
        )['c'] ?? 0);
        $this->assertSame(0, $count);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed, string $role): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'workspace-skills-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-workspace-skills',
            '__remember_restore_attempted' => true,
        ];
    }
}
