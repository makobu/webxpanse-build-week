<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\VoiceCallService;
use CRM\Services\VoiceCallStateMachineService;
use CRM\Services\VoiceCallControlService;
use CRM\Services\VoiceAgentService;
use CRM\Services\VoiceEvidenceAccessService;
use CRM\Services\VoiceContactLinkService;
use CRM\Services\VoiceContactPhoneIndexService;
use CRM\Services\VoiceCrmContextService;
use CRM\Services\VoiceContextEnrichmentService;
use CRM\Services\CustomerVoiceAggregationService;
use CRM\Services\VoiceObservabilityService;
use CRM\Services\VoiceQueueService;
use CRM\Services\VoiceRetentionService;
use CRM\Services\AIContextAssemblyService;
use CRM\Services\WorkspaceVoiceConfigService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\VoiceWebhookService;
use CRM\Tests\DatabaseTestCase;

class VoiceCallCenterDatabaseTest extends DatabaseTestCase
{
    public function testMarketplaceInstallUninstallAndReinstallAreIdempotent(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES ('00000000-0000-4000-8000-000000000660', 'voice-marketplace-admin@example.test', 'hash', 'superadmin', NOW())"
        );
        $actorId = (int) Database::lastInsertId();
        $superadminRoleId = (int) (Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1")['id'] ?? 0);
        Database::execute('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$actorId, $superadminRoleId, $actorId]);
        \CRM\Session::set('user_id', $actorId);
        \CRM\Session::set('user_role', 'superadmin');
        \CRM\Authorization::resetCaches();
        $actor = Database::queryOne('SELECT id, uuid, first_name, last_name, email, role, last_login, email_verified_at FROM users WHERE id = ?', [$actorId]);
        $this->assertSame('superadmin', (string) (\CRM\Authorization::getGlobalUserRole($actorId)['slug'] ?? ''));
        $this->assertTrue(\CRM\Authorization::isSuperAdmin($actor));
        $catalog = new WorkspaceSkillCatalogService();
        $catalog->syncDefinitions();
        $installer = new WorkspaceSkillInstallService($catalog);

        $definition = $catalog->findForWorkspace(WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER, 1);
        $this->assertIsArray($definition);
        $this->assertSame('visible', $definition['catalog_status']);
        $this->assertSame('plugin', $definition['module_type']);
        $this->assertContains(
            WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER,
            array_column($catalog->availableForWorkspace(1), 'key')
        );
        $access = (new WorkspaceMarketplaceAccessService($catalog, $installer))->accessForDefinition(1, $actorId, $definition);
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL, $access['state']);
        $this->assertTrue($access['can_install']);
        $this->assertTrue($access['can_open']);
        $this->assertSame(
            'workspace_skills.php?module=voice_call_center#setup',
            $definition['plugin_metadata']['setup_url']
        );

        $installed = $installer->install(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER, $actorId);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER, $installed['key']);
        $this->assertTrue($installer->isInstalled(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER));
        $installedAccess = (new WorkspaceMarketplaceAccessService($catalog, $installer))->accessForDefinition(1, $actorId, $definition);
        $this->assertSame(WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP, $installedAccess['state']);
        $this->assertTrue($installedAccess['can_configure']);
        $this->assertFalse($installedAccess['can_run']);

        $installer->uninstall(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER, $actorId);
        $this->assertFalse($installer->isInstalled(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER));

        $installer->install(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER, $actorId);
        $this->assertTrue($installer->isInstalled(1, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER));
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM workspace_skill_installs WHERE workspace_id = 1 AND skill_key = ?',
            [WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER]
        )['c'] ?? 0));
    }

    public function testProviderSecretsAndCallbackTokensAreWorkspaceScoped(): void
    {
        $service = new WorkspaceVoiceConfigService();
        $service->save(1, [
            'enabled' => false,
            'account_username' => 'sandbox-user',
            'api_key' => 'provider-secret',
            'virtual_number' => '+254700000001',
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to consent.',
            'allowed_country_codes' => ['+254'],
        ], 1);

        $public = $service->get(1, false);
        $secret = $service->get(1, true);
        $this->assertTrue($public['api_key_saved']);
        $this->assertNull($public['api_key']);
        $this->assertSame('provider-secret', $secret['api_key']);
        $this->assertNotSame('provider-secret', $secret['encrypted_api_key']);
        $this->assertNotEmpty($secret['callback_token']);
        $this->assertSame(1, $service->findByCallbackToken((string) $secret['callback_token'])['workspace_id']);
        $this->assertSame([], $service->findByCallbackToken(str_repeat('x', 48)));
        $service->markVerified(1, true, '', ['environment' => 'live', 'balance' => 'KES 100.00']);
        $verified = $service->get(1, false);
        $this->assertSame('ready', $verified['status']);
        $this->assertSame('KES 100.00', $verified['settings']['provider_verification']['balance']);
        $this->assertStringNotContainsString('provider-secret', json_encode($verified));
    }

    public function testNoticeOnlyRecordingRequiresAcknowledgement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('compliance acknowledgement');
        (new WorkspaceVoiceConfigService())->save(1, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'notice_only',
            'consent_notice' => 'This call is recorded.',
        ], 1);
    }

    public function testRecordingAcknowledgementIsBoundToCurrentPolicy(): void
    {
        $this->ensureWorkspaceOwner(1);
        $service = new WorkspaceVoiceConfigService();
        $saved = $service->save(1, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to allow recording and transcription.',
            'compliance_acknowledged' => true,
            'automation_policy' => [
                'contact_context' => 'approval_required',
                'follow_up_tasks' => 'suggest',
            ],
        ], 1);

        $this->assertTrue($saved['recording_acknowledgement_valid']);
        $this->assertSame(1, $saved['compliance_acknowledged_by_user_id']);
        $this->assertNotEmpty($saved['recording_acknowledgement']['policy_fingerprint']);

        $unchanged = $service->save(1, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to allow recording and transcription.',
            'automation_policy' => [
                'contact_context' => 'approval_required',
                'follow_up_tasks' => 'suggest',
            ],
        ], 1);
        $this->assertTrue($unchanged['recording_acknowledgement_valid']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current workspace-owner compliance acknowledgement');
        $service->save(1, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Updated notice requiring a fresh owner decision.',
            'automation_policy' => [
                'contact_context' => 'automatic_safe',
                'follow_up_tasks' => 'suggest',
            ],
        ], 1);
    }

    public function testNonOwnerCannotAcknowledgeRecordingResponsibility(): void
    {
        $this->ensureWorkspaceOwner(1);
        Database::execute(
            "INSERT INTO users (id, uuid, email, password_hash, role, created_at)
             VALUES (2, '00000000-0000-4000-8000-000000000002', 'voice-admin@example.test', 'hash', 'admin', NOW())"
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, created_at, updated_at)
             VALUES (1, 2, 'admin', 'active', 0, NOW(), NOW(), NOW())"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only a workspace owner');
        (new WorkspaceVoiceConfigService())->save(1, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to consent.',
            'compliance_acknowledged' => true,
        ], 2);
    }

    public function testPremiumVoiceSwitchesCannotBypassWorkspacePackage(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES ('00000000-0000-4000-8000-000000000663', 'No Voice Package', 'no-voice-package', 'active', 'trialing', NOW(), NOW())"
        );
        $workspaceId = (int) Database::lastInsertId();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recording is not enabled');
        (new WorkspaceVoiceConfigService())->save($workspaceId, [
            'enabled' => false,
            'recording_enabled' => true,
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to consent.',
        ], 1);
    }

    public function testKillSwitchStillAllowsExistingCallToReachTerminalState(): void
    {
        $configs = new WorkspaceVoiceConfigService();
        $configs->save(1, [
            'enabled' => true,
            'inbound_enabled' => true,
            'account_username' => 'sandbox',
            'api_key' => 'provider-secret',
            'virtual_number' => '+254700000664',
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to consent.',
        ], 1);
        $secretConfig = $configs->get(1, true);
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, provider, provider_session_id, direction, state,
                consent_status, recording_status, transcription_status, requested_at, answered_at)
             VALUES (1, '00000000-0000-4000-8000-000000000664', 'africastalking', 'session-kill-switch-664',
                'inbound', 'in_progress', 'declined', 'disabled', 'disabled', NOW(), NOW())"
        );
        $callId = (int) Database::lastInsertId();
        $previous = $_ENV['VOICE_CALL_CENTER_ENABLED'] ?? getenv('VOICE_CALL_CENTER_ENABLED');
        $_ENV['VOICE_CALL_CENTER_ENABLED'] = 'false';
        putenv('VOICE_CALL_CENTER_ENABLED=false');
        try {
            $result = (new VoiceWebhookService())->handle((string) $secretConfig['callback_token'], [
                'sessionId' => 'session-kill-switch-664',
                'status' => 'Completed',
                'direction' => 'inbound',
                'callerNumber' => '+254711000664',
                'destinationNumber' => '+254700000664',
                'durationInSeconds' => 48,
            ], '203.0.113.64');
        } finally {
            $restored = $previous === false || $previous === null ? 'true' : (string) $previous;
            $_ENV['VOICE_CALL_CENTER_ENABLED'] = $restored;
            putenv('VOICE_CALL_CENTER_ENABLED=' . $restored);
        }

        $this->assertSame(200, $result['status']);
        $call = Database::queryOne('SELECT state, duration_seconds FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$callId]);
        $this->assertSame('completed', $call['state']);
        $this->assertSame(48, (int) $call['duration_seconds']);
    }

    public function testConsentCallbackIsIpLayeredIdempotentAndCannotBypassTranscriptionSwitch(): void
    {
        $this->ensureWorkspaceOwner(1);
        $catalog = new WorkspaceSkillCatalogService();
        $catalog->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (1, ?, 'installed', 1, 1, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL, updated_at = NOW()",
            [WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER]
        );
        $configs = new WorkspaceVoiceConfigService();
        $configs->save(1, [
            'enabled' => true,
            'inbound_enabled' => true,
            'recording_enabled' => true,
            'transcription_enabled' => false,
            'account_username' => 'sandbox',
            'api_key' => 'provider-secret',
            'virtual_number' => '+254700000667',
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'Press 1 to consent.',
            'compliance_acknowledged' => true,
            'settings' => ['callback_ip_allowlist' => ['192.0.2.20']],
        ], 1);
        $secretConfig = $configs->get(1, true);
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, provider, provider_session_id, direction, state,
                consent_status, recording_status, transcription_status, requested_at)
             VALUES (1, '00000000-0000-4000-8000-000000000667', 'africastalking', 'session-consent-667',
                'inbound', 'consent_pending', 'pending', 'pending', 'disabled', NOW())"
        );
        $callId = (int) Database::lastInsertId();
        $service = new VoiceWebhookService();
        $previous = $_ENV['VOICE_CALL_CENTER_ENABLED'] ?? getenv('VOICE_CALL_CENTER_ENABLED');
        $_ENV['VOICE_CALL_CENTER_ENABLED'] = 'true';
        putenv('VOICE_CALL_CENTER_ENABLED=true');
        try {
            $rejected = $service->handleConsent((string) $secretConfig['callback_token'], [
                'sessionId' => 'session-consent-667', 'dtmfDigits' => '1',
            ], '192.0.2.99');
            $this->assertStringContainsString('<Reject', $rejected);
            $this->assertSame('pending', (string) Database::queryOne(
                'SELECT consent_status FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$callId]
            )['consent_status']);

            $service->handleConsent((string) $secretConfig['callback_token'], [
                'sessionId' => 'session-consent-667', 'dtmfDigits' => '1',
            ], '192.0.2.20');
            $service->handleConsent((string) $secretConfig['callback_token'], [
                'sessionId' => 'session-consent-667', 'dtmfDigits' => '2',
            ], '192.0.2.20');
        } finally {
            $restored = $previous === false || $previous === null ? 'false' : (string) $previous;
            $_ENV['VOICE_CALL_CENTER_ENABLED'] = $restored;
            putenv('VOICE_CALL_CENTER_ENABLED=' . $restored);
        }
        $call = Database::queryOne(
            'SELECT state, consent_status, recording_status, transcription_status FROM voice_calls WHERE workspace_id = 1 AND id = ?',
            [$callId]
        );
        $this->assertSame('granted', $call['consent_status']);
        $this->assertSame('queued', $call['state']);
        $this->assertSame('recording', $call['recording_status']);
        $this->assertSame('disabled', $call['transcription_status']);
    }

    public function testStateAndInternalEventsAreIdempotentAndWorkspaceIsolated(): void
    {
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, created_by_user_id, consent_status, recording_status, transcription_status, requested_at)
             VALUES (1, '00000000-0000-4000-8000-000000000111', 'outbound', 'requested', 1, 'pending', 'disabled', 'disabled', NOW())"
        );
        $callId = (int) Database::lastInsertId();
        $states = new VoiceCallStateMachineService();
        $this->assertSame('queued', $states->transition(1, $callId, 'queued')['state']);
        $this->assertSame('dialing_agent', $states->transition(1, $callId, 'dialing_agent')['state']);

        $calls = new VoiceCallService();
        $calls->recordInternalEvent(1, $callId, 'voice.call.queued', 'queued');
        $calls->recordInternalEvent(1, $callId, 'voice.call.queued', 'queued');
        $count = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = 1 AND call_id = ? AND event_type = 'voice.call.queued'", [$callId])['c'] ?? 0);
        $this->assertSame(1, $count);
        $this->assertNotEmpty($calls->get(1, $callId, 0, true));
        $this->assertSame([], $calls->get(2, $callId, 0, true));
        $this->assertCount(1, $calls->list(1, 1, false)['events']);
        $this->assertSame([], $calls->list(1, 999999, false)['events']);
        $this->assertCount(1, $calls->list(1, 999999, true)['events']);
    }

    public function testCompletedCallCreatesOneCrmRecordPairAndAiUpgradesIt(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000668', 'Completed', 'Caller', 'completed-caller@example.com', '+254700000668', NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, contact_id, agent_user_id, consent_status,
                recording_status, transcription_status, requested_at, completed_at, duration_seconds)
             VALUES (1, '00000000-0000-4000-8000-000000000669', 'outbound', 'completed', ?, 1, 'not_required',
                'disabled', 'disabled', NOW(), NOW(), 95)",
            [$contactId]
        );
        $callId = (int) Database::lastInsertId();
        $service = new VoiceCrmContextService();
        $first = $service->sync(1, $callId);
        $second = $service->sync(1, $callId);

        $this->assertSame($first, $second);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM activities WHERE workspace_id = 1 AND contact_id = ? AND activity_type = 'call'",
            [$contactId]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM communications WHERE workspace_id = 1 AND contact_id = ? AND channel = 'voice'",
            [$contactId]
        )['c'] ?? 0));
        $this->assertStringContainsString('1:35', (string) Database::queryOne(
            'SELECT body FROM communications WHERE workspace_id = 1 AND id = ?', [(int) $first['communication_id']]
        )['body']);

        $upgraded = $service->sync(1, $callId, ['summary' => 'The customer committed to a Tuesday demo.', 'confidence' => 0.91]);
        $this->assertSame($first, $upgraded);
        $this->assertSame('The customer committed to a Tuesday demo.', (string) Database::queryOne(
            'SELECT body FROM communications WHERE workspace_id = 1 AND id = ?', [(int) $first['communication_id']]
        )['body']);
        $this->assertSame([], $service->sync(999999, $callId));
    }

    public function testApprovedVoiceEnrichmentAddsBoundedContextAndIdempotentTask(): void
    {
        $this->ensureWorkspaceOwner(1);
        (new WorkspaceVoiceConfigService())->save(1, [
            'enabled' => false,
            'ai_application_enabled' => true,
            'automation_policy' => [
                'call_summary' => 'automatic',
                'contact_context' => 'approval_required',
                'follow_up_tasks' => 'approval_required',
                'deal_stage' => 'suggest',
                'customer_voice' => 'off',
                'follow_up_messages' => 'off',
                'minimum_confidence' => 0.80,
            ],
        ], 1);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, assigned_to, created_by, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000681', 'Context', 'Customer', 'context-customer@example.com', '+254700000681', 1, 1, NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, contact_id, agent_user_id, created_by_user_id,
                consent_status, recording_status, transcription_status, requested_at, completed_at, duration_seconds)
             VALUES (1, '00000000-0000-4000-8000-000000000682', 'outbound', 'completed', ?, 1, 1,
                'granted', 'ready', 'ready', NOW(), NOW(), 120)",
            [$contactId]
        );
        $callId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_call_insights (workspace_id, call_id, contact_id, summary, relationship_context, sentiment, intent,
                pains_json, goals_json, objections_json, commitments_json, requested_actions_json, next_step,
                contact_updates_json, task_suggestions_json, deal_stage_suggestion_json, confidence, evidence_json, review_status)
             VALUES (1, ?, ?, 'Customer needs an implementation plan.', 'Preparing for a September launch.', 'positive', 'purchase_planning',
                '[\"Onboarding time\"]', '[\"Launch before September\"]', '[\"Implementation effort\"]', '[\"Send technical plan\"]',
                '[\"Send implementation plan\"]', 'Send the plan by Friday', '{}', '[\"Send implementation plan by Friday\"]',
                '{\"stage\":\"proposal\"}', 0.92000, '[\"Customer requested an implementation plan\"]', 'pending')",
            [$callId, $contactId]
        );

        $initial = (new VoiceContextEnrichmentService())->applyStoredInsight(1, $callId, false);
        $this->assertSame('pending', $initial['review_status']);
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND contact_id = ? AND automation_dedupe_key LIKE 'voice:%'",
            [$contactId]
        )['c'] ?? 0));
        $beforeApproval = Database::queryOne('SELECT metadata_json FROM contacts WHERE workspace_id = 1 AND id = ?', [$contactId]);
        $this->assertArrayNotHasKey('voice_context', json_decode((string) ($beforeApproval['metadata_json'] ?? ''), true) ?: []);
        $callBeforeApproval = Database::queryOne('SELECT activity_id FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$callId]);
        $activityBeforeApproval = Database::queryOne('SELECT metadata FROM activities WHERE workspace_id = 1 AND id = ?', [(int) $callBeforeApproval['activity_id']]);
        $activityMetadataBeforeApproval = json_decode((string) ($activityBeforeApproval['metadata'] ?? ''), true) ?: [];
        $this->assertSame('', (string) ($activityMetadataBeforeApproval['next_step'] ?? ''));
        $this->assertSame([], (array) ($activityMetadataBeforeApproval['commitments_observed'] ?? []));

        $approved = (new VoiceEvidenceAccessService())->reviewInsight(1, $callId, 1, true, 'accepted');
        $this->assertSame('applied', $approved['review_status']);
        $this->assertNotEmpty($approved['applied']);
        $contact = Database::queryOne('SELECT metadata_json, ai_context FROM contacts WHERE workspace_id = 1 AND id = ?', [$contactId]);
        $metadata = json_decode((string) $contact['metadata_json'], true);
        $aiContext = json_decode((string) $contact['ai_context'], true);
        $this->assertSame($callId, (int) $metadata['voice_context'][0]['source_call_id']);
        $this->assertSame('Preparing for a September launch.', $aiContext['voice_context'][0]['relationship_context']);
        $this->assertArrayNotHasKey('transcript', $metadata['voice_context'][0]);
        $activityAfterApproval = Database::queryOne('SELECT metadata FROM activities WHERE workspace_id = 1 AND id = ?', [(int) $callBeforeApproval['activity_id']]);
        $activityMetadataAfterApproval = json_decode((string) ($activityAfterApproval['metadata'] ?? ''), true) ?: [];
        $this->assertSame('Send the plan by Friday', (string) ($activityMetadataAfterApproval['next_step'] ?? ''));
        $this->assertSame(['Send technical plan'], (array) ($activityMetadataAfterApproval['commitments_observed'] ?? []));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND contact_id = ? AND automation_dedupe_key LIKE 'voice:%'",
            [$contactId]
        )['c'] ?? 0));

        (new VoiceEvidenceAccessService())->reviewInsight(1, $callId, 1, true, 'accepted');
        $contactAgain = Database::queryOne('SELECT metadata_json FROM contacts WHERE workspace_id = 1 AND id = ?', [$contactId]);
        $metadataAgain = json_decode((string) $contactAgain['metadata_json'], true);
        $this->assertCount(1, $metadataAgain['voice_context']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND contact_id = ? AND automation_dedupe_key LIKE 'voice:%'",
            [$contactId]
        )['c'] ?? 0));

        $bundle = (new AIContextAssemblyService())->buildContextBundle('customer_thread', 'voice-approved-context', [
            'workspace_id' => 1, 'contact_id' => $contactId, 'contact' => ['id' => $contactId], 'max_blocks' => 20,
        ]);
        $voiceBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'voice_context'));
        $this->assertCount(1, $voiceBlocks);
        $encoded = json_encode($voiceBlocks[0]['content']);
        $this->assertStringContainsString('Launch before September', $encoded);
        $this->assertStringNotContainsString('transcript', strtolower($encoded));
    }

    public function testStalledOutboundDispatchRecoveryNeverRetriesConfirmedProviderCall(): void
    {
        Database::execute(
            "INSERT INTO voice_agents (workspace_id, user_id, display_name, endpoint_type, encrypted_endpoint, endpoint_hash,
                endpoint_masked, presence_status, pre_call_presence_status)
             VALUES (1, 1, 'Stalled Agent', 'phone', 'encrypted', SHA2('stalled-agent', 256), '+254***665', 'busy', 'available')"
        );
        $agentId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, provider, direction, state, agent_id, agent_user_id,
                consent_status, recording_status, transcription_status, requested_at, updated_at)
             VALUES (1, '00000000-0000-4000-8000-000000000665', 'africastalking', 'outbound', 'dialing_agent', ?, 1,
                'not_required', 'disabled', 'disabled', NOW(), DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$agentId]
        );
        $stalledCallId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, provider, provider_session_id, direction, state,
                consent_status, recording_status, transcription_status, requested_at, updated_at)
             VALUES (1, '00000000-0000-4000-8000-000000000666', 'africastalking', 'confirmed-session-666',
                'outbound', 'dialing_agent', 'not_required', 'disabled', 'disabled', NOW(), DATE_SUB(NOW(), INTERVAL 10 MINUTE))"
        );
        $confirmedCallId = (int) Database::lastInsertId();

        $this->assertSame(1, (new VoiceCallService())->recoverStalledOutboundDispatches(1, 120));
        $stalled = Database::queryOne('SELECT state, failure_category FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$stalledCallId]);
        $confirmed = Database::queryOne('SELECT state FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$confirmedCallId]);
        $agent = Database::queryOne('SELECT presence_status, pre_call_presence_status FROM voice_agents WHERE workspace_id = 1 AND id = ?', [$agentId]);
        $this->assertSame('provider_failed', $stalled['state']);
        $this->assertSame('dispatch_lease_expired', $stalled['failure_category']);
        $this->assertSame('dialing_agent', $confirmed['state']);
        $this->assertSame('available', $agent['presence_status']);
        $this->assertNull($agent['pre_call_presence_status']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = 1 AND call_id = ? AND event_type = 'voice.call.dispatch_expired'",
            [$stalledCallId]
        )['c'] ?? 0));
    }

    public function testVoicePricesDoNotModifyExistingGrowthPrice(): void
    {
        $base = Database::queryOne("SELECT amount FROM billing_plan_prices WHERE price_code = 'growth-studio-monthly'");
        $voice = Database::queryOne("SELECT amount, metadata_json FROM billing_plan_prices WHERE price_code = 'growth-studio-voice-monthly'");
        $this->assertSame('15000.00', (string) ($base['amount'] ?? ''));
        $this->assertSame('27500.00', (string) ($voice['amount'] ?? ''));
        $features = Database::query(
            "SELECT f.feature_key, JSON_UNQUOTE(JSON_EXTRACT(pf.value_json, '$.value')) AS feature_value
             FROM billing_plan_prices p INNER JOIN billing_plan_price_feature_values pf ON pf.billing_plan_price_id = p.id
             INNER JOIN billing_package_features f ON f.id = pf.feature_id WHERE p.price_code = 'growth-studio-voice-monthly'"
        );
        $values = array_column($features, 'feature_value', 'feature_key');
        $this->assertSame('10', $values['voice_concurrent_calls']);
        $this->assertSame('15', $values['voice_agent_limit']);
        $this->assertSame('1', $values['voice_call_center']);
    }

    public function testDefaultQueueEncryptsFallbackAndAddsEnabledAgentsOnce(): void
    {
        Database::execute(
            "INSERT INTO voice_agents (workspace_id, user_id, display_name, endpoint_type, encrypted_endpoint, endpoint_hash, endpoint_masked, presence_status)
             VALUES (1, 1, 'Queue Agent', 'phone', 'encrypted', SHA2('agent', 256), '+254***001', 'available')"
        );
        $agentId = (int) Database::lastInsertId();
        $queues = new VoiceQueueService();
        $queue = $queues->saveDefault(1, [
            'name' => 'Sales', 'fallback_action' => 'verified_number', 'fallback_destination' => '+254700000099',
            'business_hours' => ['enabled' => true, 'timezone' => 'Africa/Nairobi', 'days' => [1,2,3,4,5], 'start' => '08:00', 'end' => '17:00'],
        ]);
        $queues->ensureDefaultMembers(1, (int) $queue['id']);
        $queues->ensureDefaultMembers(1, (int) $queue['id']);

        $raw = Database::queryOne('SELECT * FROM voice_queues WHERE workspace_id = 1 AND id = ?', [(int) $queue['id']]);
        $this->assertNotSame('+254700000099', (string) $raw['encrypted_fallback_target']);
        $this->assertStringNotContainsString('+254700000099', json_encode($raw));
        $this->assertSame('+254700000099', $queues->defaultQueue(1)['fallback_destination']);
        $this->assertTrue($queues->isOpen($queue, new \DateTimeImmutable('2024-01-15 09:00:00', new \DateTimeZone('Africa/Nairobi'))));
        $this->assertFalse($queues->isOpen($queue, new \DateTimeImmutable('2024-01-14 09:00:00', new \DateTimeZone('Africa/Nairobi'))));
        $this->assertSame(1, (int) (Database::queryOne('SELECT COUNT(*) AS c FROM voice_queue_members WHERE workspace_id = 1 AND queue_id = ? AND agent_id = ?', [(int) $queue['id'], $agentId])['c'] ?? 0));
        $this->assertSame($agentId, (int) $queues->members(1, (int) $queue['id'])[0]['agent_id']);
        $this->assertSame([], $queues->members(2, (int) $queue['id']));
    }

    public function testNamedQueueFallbackRoutingAndRuntimeSummaryAreWorkspaceScoped(): void
    {
        $queues = new VoiceQueueService();
        $sales = $queues->saveQueue(1, [
            'name' => 'Sales', 'is_default' => true, 'enabled' => true, 'fallback_action' => 'reject',
        ], null, 1);
        $support = $queues->saveQueue(1, [
            'name' => 'Support', 'is_default' => false, 'enabled' => true, 'fallback_action' => 'reject',
        ], null, 1);
        $sales = $queues->saveQueue(1, [
            'name' => 'Sales', 'is_default' => true, 'enabled' => true,
            'fallback_action' => 'alternate_queue', 'fallback_queue_id' => (int) $support['id'],
        ], (int) $sales['id'], 1);
        Database::execute(
            "INSERT INTO voice_agents (workspace_id, user_id, display_name, endpoint_type, encrypted_endpoint, endpoint_hash, endpoint_masked, presence_status)
             VALUES (1, 1, 'Support Agent', 'phone', 'encrypted', SHA2('support-agent', 256), '+254***771', 'available')"
        );
        $agentId = (int) Database::lastInsertId();
        $queues->setMembers(1, (int) $support['id'], [$agentId]);
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, consent_status, recording_status, transcription_status, requested_at)
             VALUES (1, '00000000-0000-4000-8000-000000000771', 'inbound', 'received', 'pending', 'disabled', 'disabled', NOW())"
        );
        $routedCallId = (int) Database::lastInsertId();

        $route = $queues->routeInboundCall(1, $routedCallId, (int) $sales['id']);
        $this->assertSame((int) $support['id'], (int) $route['queue']['id']);
        $this->assertSame($agentId, (int) $route['agent']['id']);
        $this->assertSame([(int) $sales['id'], (int) $support['id']], $route['visited_queue_ids']);
        $routedCall = Database::queryOne('SELECT queue_id, agent_id FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$routedCallId]);
        $this->assertSame((int) $support['id'], (int) $routedCall['queue_id']);
        $this->assertSame($agentId, (int) $routedCall['agent_id']);
        Database::execute("UPDATE voice_calls SET state = 'dialing_agent' WHERE workspace_id = 1 AND id = ?", [$routedCallId]);

        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, queue_id, consent_status, recording_status, transcription_status, requested_at, queued_at)
             VALUES (1, '00000000-0000-4000-8000-000000000772', 'inbound', 'queued', ?, 'declined', 'disabled', 'disabled', NOW(), DATE_SUB(NOW(), INTERVAL 30 SECOND))",
            [(int) $sales['id']]
        );
        $summary = array_column($queues->runtimeSummary(1), null, 'name');
        $this->assertSame(1, $summary['Sales']['waiting_calls']);
        $this->assertGreaterThanOrEqual(30, $summary['Sales']['oldest_wait_seconds']);
        $this->assertSame(1, $summary['Support']['active_calls']);
        $this->assertSame(0, $summary['Support']['available_agents']);
        $this->assertSame([], $queues->runtimeSummary(999999));

        try {
            $queues->saveQueue(1, [
                'name' => 'Support', 'is_default' => false, 'enabled' => true,
                'fallback_action' => 'alternate_queue', 'fallback_queue_id' => (int) $sales['id'],
            ], (int) $support['id'], 1);
            $this->fail('Alternate queue cycles must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cycle', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alternate queue');
        $queues->saveQueue(1, [
            'name' => 'Sales', 'is_default' => true, 'enabled' => true,
            'fallback_action' => 'alternate_queue', 'fallback_queue_id' => 999999,
        ], (int) $sales['id'], 1);
    }

    public function testAgentEndpointCannotSilentlyReassignAnotherAgent(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES ('00000000-0000-4000-8000-000000000661', 'voice-agent-one@example.test', 'hash', 'admin', NOW()),
                    ('00000000-0000-4000-8000-000000000662', 'voice-agent-two@example.test', 'hash', 'admin', NOW())"
        );
        $users = Database::query(
            "SELECT id, email FROM users WHERE email IN ('voice-agent-one@example.test', 'voice-agent-two@example.test') ORDER BY email ASC"
        );
        $firstUserId = (int) $users[0]['id'];
        $secondUserId = (int) $users[1]['id'];
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, membership_status, created_at)
             VALUES (1, ?, 'active', NOW()), (1, ?, 'active', NOW())",
            [$firstUserId, $secondUserId]
        );
        $agents = new VoiceAgentService();
        $first = $agents->save(1, $firstUserId, [
            'display_name' => 'First voice agent',
            'endpoint_type' => 'phone',
            'endpoint' => '+254700000661',
            'presence_status' => 'available',
        ], 1);

        try {
            $agents->save(1, $secondUserId, [
                'display_name' => 'Second voice agent',
                'endpoint_type' => 'phone',
                'endpoint' => '+254700000661',
                'presence_status' => 'available',
            ], 1);
            $this->fail('A duplicate endpoint must not update or reassign another agent.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already assigned', $e->getMessage());
        }

        $this->assertSame('First voice agent', $agents->findById(1, (int) $first['id'])['display_name']);
        $this->assertSame([], $agents->findForUser(1, $secondUserId));
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_agents WHERE workspace_id = 1 AND endpoint_hash = ?',
            [Database::queryOne('SELECT endpoint_hash FROM voice_agents WHERE workspace_id = 1 AND id = ?', [(int) $first['id']])['endpoint_hash']]
        )['c'] ?? 0));
    }

    public function testUnmatchedCallRequiresAnAuthorizedDeliberateContactLink(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_by, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000334', 'Linked', 'Caller', 'linked-caller@example.com', '+254700000334', 1, NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, agent_user_id, created_by_user_id, consent_status, recording_status, transcription_status, requested_at)
             VALUES (1, '00000000-0000-4000-8000-000000000333', 'inbound', 'completed', 1, 1, 'declined', 'disabled', 'disabled', NOW())"
        );
        $callId = (int) Database::lastInsertId();
        $service = new VoiceContactLinkService();
        try {
            $service->linkExisting(1, $callId, $contactId, 999999, false);
            $this->fail('An unrelated user must not link another agent\'s call.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unmatched voice call not found', $e->getMessage());
        }
        $linked = $service->linkExisting(1, $callId, $contactId, 1, false);
        $this->assertSame($contactId, $linked['contact_id']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = 1 AND call_id = ? AND event_type = 'voice.call.contact_linked'",
            [$callId]
        )['c'] ?? 0));
    }

    public function testVoicePhoneIndexMatchesNormalizedNumbersWithoutAmbiguousAutolinking(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000441', 'Indexed', 'Caller', 'indexed-caller@example.com', '0712 345 678', NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        $index = new VoiceContactPhoneIndexService();
        $index->syncContact(1, $contactId);
        $this->assertSame($contactId, $index->findUniqueContactId(1, '+254712345678'));

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000442', 'Duplicate', 'Caller', 'duplicate-caller@example.com', '+254712345678', NOW())"
        );
        $index->syncContact(1, (int) Database::lastInsertId());
        $this->assertNull($index->findUniqueContactId(1, '0712345678'));
    }

    public function testAcceptedCustomerVoiceCreatesOnlyDraftMarketingContext(): void
    {
        Database::execute(
            "INSERT INTO marketing_customer_voice_insights
             (workspace_id, category, topic_hash, topic_label, summary, evidence_count, distinct_contact_count, confidence, review_status)
             VALUES (1, 'objection', SHA2('pricing concern', 256), 'Pricing concern', 'Customers need clearer pricing proof.', 4, 3, 0.88, 'pending')"
        );
        $insightId = (int) Database::lastInsertId();
        (new CustomerVoiceAggregationService())->review(1, $insightId, 1, 'accepted');
        $reviewed = Database::queryOne('SELECT * FROM marketing_customer_voice_insights WHERE workspace_id = 1 AND id = ?', [$insightId]);
        $context = Database::queryOne('SELECT * FROM marketing_context_items WHERE workspace_id = 1 AND id = ?', [(int) $reviewed['accepted_target_id']]);
        $this->assertSame('accepted', $reviewed['review_status']);
        $this->assertSame('draft', $context['status']);
        $this->assertSame('content_pillar', $context['item_type']);
        $this->assertStringContainsString('Customer Voice:', $context['title']);
    }

    public function testVoiceObservabilityReturnsAlertableWorkspaceMetrics(): void
    {
        Database::execute(
            "INSERT INTO voice_worker_heartbeats (workspace_id, worker_key, status, pending_count, failed_count, heartbeat_at)
             VALUES (1, 'voice_worker', 'healthy', 0, 0, NOW())
             ON DUPLICATE KEY UPDATE status = 'healthy', pending_count = 0, failed_count = 0, heartbeat_at = NOW()"
        );
        $snapshot = (new VoiceObservabilityService())->snapshot(1);
        $this->assertArrayHasKey('active_calls', $snapshot['metrics']);
        $this->assertArrayHasKey('peak_concurrency_today', $snapshot['metrics']);
        $this->assertArrayHasKey('oldest_backlog_seconds', $snapshot['metrics']);
        $this->assertIsArray($snapshot['alerts']);
        $this->assertSame('voice_worker', $snapshot['worker']['worker_key']);
    }

    public function testTranscriptAccessReviewAndRetentionAreWorkspaceScoped(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (1, '00000000-0000-4000-8000-000000000223', 'Voice', 'Context', 'voice-context@example.com', '+254700000223', NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, contact_id, agent_user_id, created_by_user_id, consent_status, recording_status, transcription_status, requested_at, completed_at)
             VALUES (1, '00000000-0000-4000-8000-000000000222', 'outbound', 'completed', ?, 1, 1, 'granted', 'ready', 'ready', NOW(), NOW())",
            [$contactId]
        );
        $callId = (int) Database::lastInsertId();
        $crypto = new WorkspaceVoiceConfigService();
        Database::execute(
            "INSERT INTO voice_recordings (workspace_id, call_id, provider, provider_recording_id, encrypted_provider_url, provider_host, status, retained_until)
             VALUES (1, ?, 'africastalking', 'recording-222', ?, 'voice.africastalking.com', 'ready', DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [$callId, $crypto->encryptValue('https://voice.africastalking.com/recording-222.mp3')]
        );
        $recordingId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_transcription_jobs (workspace_id, call_id, recording_id, status) VALUES (1, ?, ?, 'pending')",
            [$callId, $recordingId]
        );
        Database::execute(
            "INSERT INTO voice_call_transcripts (workspace_id, call_id, encrypted_transcript, transcript_fingerprint, redacted_preview, model, retained_until)
             VALUES (1, ?, ?, SHA2('private transcript', 256), 'private preview', 'gpt-4o-mini-transcribe', DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [$callId, $crypto->encryptValue('private transcript')]
        );
        Database::execute(
            "INSERT INTO voice_call_insights (workspace_id, call_id, contact_id, summary, next_step, commitments_json, confidence, review_status)
             VALUES (1, ?, ?, 'Evidence-backed summary', 'Call tomorrow', '[\"Send proposal\"]', 0.90000, 'pending')",
            [$callId, $contactId]
        );

        $evidence = new VoiceEvidenceAccessService();
        $this->assertSame('private transcript', $evidence->transcript(1, $callId, 1, false)['transcript']);
        $this->assertSame([], $evidence->transcript(2, $callId, 1, true));
        $evidence->reviewInsight(1, $callId, 1, false, 'accepted');
        $this->assertSame('reviewed', (string) Database::queryOne('SELECT review_status FROM voice_call_insights WHERE workspace_id = 1 AND call_id = ?', [$callId])['review_status']);
        (new VoiceCallControlService())->disposition(1, $callId, 'follow_up', 'Call tomorrow', 1, false);
        $savedCall = Database::queryOne('SELECT disposition, disposition_notes FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$callId]);
        $this->assertSame('follow_up', $savedCall['disposition']);
        $this->assertSame('Call tomorrow', $savedCall['disposition_notes']);

        $bundle = (new AIContextAssemblyService())->buildContextBundle('customer_thread', 'voice-context-test', [
            'workspace_id' => 1, 'contact_id' => $contactId, 'contact' => ['id' => $contactId], 'max_blocks' => 20,
        ]);
        $voiceBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'voice_context'));
        $this->assertCount(1, $voiceBlocks);
        $encodedVoice = json_encode($voiceBlocks[0]['content']);
        $this->assertStringContainsString('Evidence-backed summary', $encodedVoice);
        $this->assertStringNotContainsString('private transcript', $encodedVoice);

        $result = (new VoiceRetentionService())->cleanup(1);
        $this->assertSame(1, $result['transcripts']);
        $this->assertSame(1, $result['recordings']);
        $this->assertSame(1, $result['jobs']);
        $expired = Database::queryOne('SELECT encrypted_transcript, redacted_preview, deleted_at FROM voice_call_transcripts WHERE workspace_id = 1 AND call_id = ?', [$callId]);
        $this->assertNotSame('private transcript', $crypto->decryptValue((string) $expired['encrypted_transcript']));
        $this->assertNull($expired['redacted_preview']);
        $this->assertNotNull($expired['deleted_at']);
        $this->assertSame([], $evidence->transcript(1, $callId, 1, false));
    }

    public function testAuthorizedEvidenceDeletionPreservesCallAndAuditHistory(): void
    {
        Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, direction, state, agent_user_id, created_by_user_id, consent_status, recording_status, transcription_status, requested_at, completed_at)
             VALUES (1, '00000000-0000-4000-8000-000000000552', 'outbound', 'completed', 1, 1, 'granted', 'ready', 'ready', NOW(), NOW())"
        );
        $callId = (int) Database::lastInsertId();
        $crypto = new WorkspaceVoiceConfigService();
        Database::execute(
            "INSERT INTO voice_recordings (workspace_id, call_id, provider, provider_recording_id, encrypted_provider_url, provider_host, status)
             VALUES (1, ?, 'africastalking', 'recording-552', ?, 'voice.africastalking.com', 'ready')",
            [$callId, $crypto->encryptValue('https://voice.africastalking.com/recording-552.mp3')]
        );
        $recordingId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO voice_transcription_jobs (workspace_id, call_id, recording_id, status) VALUES (1, ?, ?, 'completed')",
            [$callId, $recordingId]
        );
        Database::execute(
            "INSERT INTO voice_call_transcripts (workspace_id, call_id, encrypted_transcript, transcript_fingerprint, redacted_preview, model)
             VALUES (1, ?, ?, SHA2('delete me', 256), 'delete me', 'gpt-4o-mini-transcribe')",
            [$callId, $crypto->encryptValue('delete me')]
        );

        $evidence = new VoiceEvidenceAccessService();
        $this->assertSame('delete me', $evidence->transcript(1, $callId, 1, false)['transcript']);
        $deleted = $evidence->deleteCallEvidence(1, $callId, 1, false);
        $this->assertSame(1, $deleted['transcripts']);
        $this->assertSame(1, $deleted['recordings']);
        $this->assertSame([], $evidence->transcript(1, $callId, 1, false));
        $call = Database::queryOne('SELECT recording_status, transcription_status FROM voice_calls WHERE workspace_id = 1 AND id = ?', [$callId]);
        $this->assertSame('deleted', $call['recording_status']);
        $this->assertSame('deleted', $call['transcription_status']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = 1 AND call_id = ? AND event_type = 'voice.evidence.deleted'",
            [$callId]
        )['c'] ?? 0));
        $this->assertNotEmpty((new VoiceCallService())->get(1, $callId, 1, false));
    }

    private function ensureWorkspaceOwner(int $userId): void
    {
        Database::execute(
            "INSERT INTO users (id, uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'hash', 'admin', NOW())
             ON DUPLICATE KEY UPDATE email = VALUES(email)",
            [$userId, sprintf('00000000-0000-4000-8000-%012d', $userId), 'voice-owner-' . $userId . '@example.test']
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, created_at, updated_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE role_slug = 'owner', membership_status = 'active', is_owner = 1, updated_at = NOW()",
            [$userId]
        );
        $roleId = (int) (Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1")['id'] ?? 0);
        if ($roleId > 0) {
            Database::execute(
                "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
                 VALUES (1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
                [$userId, $roleId, $userId]
            );
        }
        \CRM\Authorization::resetCaches();
    }
}
