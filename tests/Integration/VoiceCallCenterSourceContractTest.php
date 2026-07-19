<?php

namespace CRM\Tests\Integration;

use CRM\Services\WorkspaceSkillCatalogService;
use PHPUnit\Framework\TestCase;

class VoiceCallCenterSourceContractTest extends TestCase
{
    public function testMarketplaceAndRuntimeUseAdditiveVoiceContracts(): void
    {
        $catalog = (new WorkspaceSkillCatalogService())->definitions();
        $voice = $catalog[WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER] ?? null;
        $this->assertIsArray($voice);
        $this->assertTrue($voice['capabilities']['runtime_plugin']);
        $this->assertSame('plugin', $voice['module_type']);
        $this->assertSame('workspace_skills.php?module=voice_call_center#setup', $voice['plugin_metadata']['setup_url']);
        $this->assertSame('voice.php', $voice['navigation']['url']);
        $this->assertSame('voice_call_center', $voice['plugin_metadata']['readiness_provider']);

        $workspacePage = file_get_contents(__DIR__ . '/../../public/workspace_skills.php');
        $this->assertStringContainsString('marketplaceRenderVoiceCallCenterSetup', (string) $workspacePage);
        $this->assertStringContainsString('save_voice_call_center_setup', (string) $workspacePage);
        $this->assertStringContainsString('$voiceConfigService->get($workspaceId, false)', (string) $workspacePage);
        $this->assertStringContainsString('voice_automation_contact_context', (string) $workspacePage);
        $this->assertStringContainsString('voice_automation_follow_up_tasks', (string) $workspacePage);
        $this->assertStringContainsString('initVoiceRecordingAcknowledgement', (string) $workspacePage);
        $this->assertStringContainsString('initVoiceSetupUx', (string) $workspacePage);
        $this->assertStringContainsString("\$voiceSetupTab === 'queues'", (string) $workspacePage);
        $this->assertStringContainsString("\$voiceSetupTab === 'team'", (string) $workspacePage);
        $this->assertStringContainsString("\$voicePayload['enabled'] = \$marketplacePostBool('voice_enabled')", (string) $workspacePage);

        $setupPartial = file_get_contents(__DIR__ . '/../../views/partials/marketplace_plugin_setup.php');
        $this->assertStringContainsString('Workspace-owner recording acknowledgement', (string) $setupPartial);
        $this->assertStringContainsString('data-vcc-recording-ack-current', (string) $setupPartial);
        $this->assertStringContainsString('VoicePolicyDecisionService', (string) $setupPartial);
        $this->assertStringContainsString('full_setup=1', (string) $setupPartial);
        $this->assertStringContainsString('vcc-readiness-summary', (string) $setupPartial);
        $this->assertStringContainsString('data-vcc-fallback-field', (string) $setupPartial);
        $this->assertStringContainsString('data-vcc-weekday', (string) $setupPartial);
        $this->assertStringContainsString('Review draft (not sent)', (string) $setupPartial);

        $enrichment = file_get_contents(__DIR__ . '/../../services/VoiceContextEnrichmentService.php');
        $this->assertStringContainsString("'source_type' => 'voice_call'", (string) $enrichment);
        $this->assertStringContainsString("'automation_dedupe_key' => \$dedupe", (string) $enrichment);

        $callCenter = file_get_contents(__DIR__ . '/../../public/call_center.php');
        $this->assertStringContainsString('WorkspaceSkillInstallService', (string) $callCenter);
        $this->assertStringContainsString('WorkspaceVoiceEntitlementService', (string) $callCenter);
        $this->assertStringContainsString('runtime owns a deliberate not-ready state', (string) $callCenter);
        $this->assertStringNotContainsString('canExposeRuntimeModule', (string) $callCenter);
        $this->assertStringContainsString('voice.calls.use', (string) $callCenter);
        $this->assertStringContainsString('vcc-queue-strip', (string) $callCenter);
        $voiceStatus = file_get_contents(__DIR__ . '/../../api/voice/status.php');
        $this->assertStringContainsString("['queues']", (string) $voiceStatus);
        $contactVoiceAction = file_get_contents(__DIR__ . '/../../views/partials/contact_voice_action.php');
        $this->assertStringContainsString('contact-detail-action', (string) $contactVoiceAction);
        $this->assertStringContainsString('call_center.php?contact_id=', (string) $contactVoiceAction);
        $voiceBootstrap = file_get_contents(__DIR__ . '/../../api/voice/_bootstrap.php');
        $this->assertStringContainsString('WorkspaceSkillInstallService', (string) $voiceBootstrap);
        $this->assertStringContainsString('isGloballyDeactivated', (string) $voiceBootstrap);
        $voiceJavascript = file_get_contents(__DIR__ . '/../../public/assets/js/voice-call-center.js');
        $this->assertStringContainsString('manual_destination_confirmed', (string) $voiceJavascript);
        $this->assertStringContainsString('transfer_fallback', (string) $voiceJavascript);
        $this->assertStringContainsString("setPollStatus('delayed')", (string) $voiceJavascript);
        $voiceControl = file_get_contents(__DIR__ . '/../../api/voice/control.php');
        $this->assertStringContainsString('transferToFallback', (string) $voiceControl);
        $customerVoice = file_get_contents(__DIR__ . '/../../public/customer_voice.php');
        $this->assertStringContainsString('voice.customer_voice.review', (string) $customerVoice);
        $this->assertStringContainsString('Raw transcripts and direct identifiers are never shown', (string) $customerVoice);
        $migrationPreflight = file_get_contents(__DIR__ . '/../../cli/voice_migration_preflight.php');
        $this->assertStringContainsString('INNODB_TRX', (string) $migrationPreflight);
        $this->assertStringContainsString('exact_rows', (string) $migrationPreflight);
    }

    public function testVoiceSetupRendersFocusedProductionControls(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderVoiceCallCenterSetup([
            'active_tab' => 'queues',
            'settings' => [
                'enabled' => false,
                'inbound_enabled' => false,
                'outbound_enabled' => false,
                'recording_enabled' => false,
                'transcription_enabled' => false,
                'ai_application_enabled' => false,
                'customer_voice_enabled' => false,
            ],
            'readiness' => [
                'ready' => false,
                'entitlements' => ['agent_limit' => 15],
                'checks' => [
                    ['label' => 'Package entitlement', 'ok' => true, 'required' => true],
                    ['label' => 'Provider credentials verified', 'ok' => false, 'required' => true],
                    ['label' => 'Workspace AI key in Settings', 'ok' => false, 'required' => false],
                ],
            ],
            'agents' => [],
            'queue' => [],
            'queues' => [],
            'queue_members' => [],
            'usage' => [],
            'observability' => [],
            'dead_letters' => [],
            'events' => [],
            'workspace_users' => [],
            'csrf' => 'test-token',
            'workspace_id' => 1,
            'installed' => true,
            'can_manage' => true,
            'can_manage_agents' => true,
            'show_callback_urls' => false,
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('full_setup=1&amp;setup_tab=provider#setup', $html);
        $this->assertStringContainsString('1 of 2 required checks complete', $html);
        $this->assertStringContainsString('data-vcc-fallback-field="verified_number" hidden', $html);
        $this->assertStringContainsString('data-vcc-business-days', $html);
        $this->assertStringContainsString('Use an IANA timezone', $html);
        $this->assertStringNotContainsString('Independent workspace kill switch', $html);
    }

    public function testReadinessRendererDoesNotPresentOptionalVoiceChecksAsBlockers(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderReadinessChecks([
            ['label' => 'Workspace AI key in Settings', 'ok' => false, 'required' => false],
            ['label' => 'Voice plugin enabled', 'ok' => false, 'required' => true],
            ['label' => 'Provider credentials verified', 'ok' => true, 'required' => true],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Optional: Workspace AI key in Settings', $html);
        $this->assertStringContainsString('Needs Setup: Voice plugin enabled', $html);
        $this->assertStringContainsString('Ready: Provider credentials verified', $html);
    }

    public function testOpenAiTranscriptionReadsWorkspaceSettingsOnly(): void
    {
        $source = file_get_contents(__DIR__ . '/../../services/OpenAIVoiceTranscriptionProvider.php');
        $this->assertStringContainsString('WorkspaceAIProviderConfigService', (string) $source);
        $this->assertStringNotContainsString("OPENAI_API_KEY", (string) $source);
        $this->assertStringContainsString('https://api.openai.com/v1/audio/transcriptions', (string) $source);
        $insightPipeline = file_get_contents(__DIR__ . '/../../services/ConversationInsightPipeline.php');
        $this->assertStringContainsString('require_workspace_provider', (string) $insightPipeline);
    }

    public function testVoiceMutationAndEvidenceEndpointsDeclareSecurityControls(): void
    {
        $mutations = ['initiate.php', 'presence.php', 'settings.php', 'agents.php', 'provider_test.php', 'review.php', 'control.php', 'contact.php', 'evidence.php'];
        foreach ($mutations as $file) {
            $source = file_get_contents(__DIR__ . '/../../api/voice/' . $file);
            $this->assertStringContainsString('voiceApiRequire(', (string) $source, $file . ' must require a voice permission.');
            $this->assertStringContainsString('voiceApiRequireCsrf(', (string) $source, $file . ' must require CSRF validation.');
        }
        $transcript = file_get_contents(__DIR__ . '/../../api/voice/transcript.php');
        $transcriptExport = file_get_contents(__DIR__ . '/../../api/voice/transcript_export.php');
        $recording = file_get_contents(__DIR__ . '/../../api/voice/recording.php');
        $this->assertStringContainsString("voice.transcripts.view", (string) $transcript);
        $this->assertStringContainsString("voice.transcripts.view", (string) $transcriptExport);
        $this->assertStringContainsString('Content-Disposition', (string) $transcriptExport);
        $this->assertStringContainsString("voice.recordings.listen", (string) $recording);
        $this->assertStringNotContainsString('encrypted_provider_url', (string) $recording);
    }

    public function testProviderCallbacksEnforceMethodTypeSizeAndReplayControls(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../../api/webhooks/voice/africastalking.php');
        $eventsEndpoint = file_get_contents(__DIR__ . '/../../api/webhooks/voice/africastalking_events.php');
        $service = file_get_contents(__DIR__ . '/../../services/VoiceWebhookService.php');
        $this->assertStringContainsString("REQUEST_METHOD", (string) $endpoint);
        $this->assertStringContainsString("CONTENT_TYPE", (string) $endpoint);
        $this->assertStringContainsString("CONTENT_LENGTH", (string) $endpoint);
        $this->assertStringContainsString('africastalking.php', (string) $eventsEndpoint);
        $this->assertStringContainsString('INSERT IGNORE INTO voice_call_events', (string) $service);
        $this->assertStringContainsString('rateLimited(', (string) $service);
        $this->assertStringContainsString('callback_token_hash', file_get_contents(__DIR__ . '/../../services/WorkspaceVoiceConfigService.php'));
    }
}
