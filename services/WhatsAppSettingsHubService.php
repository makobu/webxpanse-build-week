<?php

namespace CRM\Services;

use CRM\Authorization;

class WhatsAppSettingsHubService
{
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspaceAssistantConfigService $assistantConfig;
    private WorkspaceConnectService $workspaceConnect;
    private WorkspaceChannelHealthService $channelHealth;
    private WorkspaceMarketplaceSetupJourneyEventService $setupEvents;
    private WorkspaceMarketplaceRecommendationEventService $recommendationEvents;

    public function __construct(
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceSkillInstallService $installer = null,
        ?WorkspaceAssistantConfigService $assistantConfig = null,
        ?WorkspaceConnectService $workspaceConnect = null,
        ?WorkspaceChannelHealthService $channelHealth = null,
        ?WorkspaceMarketplaceSetupJourneyEventService $setupEvents = null,
        ?WorkspaceMarketplaceRecommendationEventService $recommendationEvents = null
    ) {
        $this->catalog = $catalog ?: new WorkspaceSkillCatalogService();
        $this->installer = $installer ?: new WorkspaceSkillInstallService($this->catalog);
        $this->assistantConfig = $assistantConfig ?: new WorkspaceAssistantConfigService();
        $this->workspaceConnect = $workspaceConnect ?: new WorkspaceConnectService();
        $this->channelHealth = $channelHealth ?: new WorkspaceChannelHealthService(null, $this->workspaceConnect);
        $this->setupEvents = $setupEvents ?: new WorkspaceMarketplaceSetupJourneyEventService();
        $this->recommendationEvents = $recommendationEvents ?: new WorkspaceMarketplaceRecommendationEventService();
    }

    public function buildWhatsAppHubContext(
        int $workspaceId,
        array $user,
        string $requestedSetupTab = '',
        string $source = 'settings'
    ): array {
        $userId = (int) ($user['id'] ?? 0);
        $requestedSetupTab = $this->normalizeKey($requestedSetupTab);
        $requestedSetupModule = $this->normalizeKey((string) ($_GET['setup_module'] ?? ''));
        $settingsSource = $source === 'settings';
        $channelTabs = ['manual', 'webhook', 'templates', 'billing', 'migration', 'embedded_signup', 'tests', 'activity'];
        $assistantTabs = ['identity', 'session', 'tests', 'activity', 'advanced'];

        $channelActiveTab = $this->activeHubTab(
            $requestedSetupTab,
            $requestedSetupModule,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
            $channelTabs,
            'manual'
        );
        $assistantActiveTab = $this->activeHubTab(
            $requestedSetupTab,
            $requestedSetupModule,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            $assistantTabs,
            'identity'
        );

        $health = $workspaceId > 0 ? $this->channelHealth->summarize($workspaceId, $user) : [];
        $whatsAppSettings = $this->buildWhatsAppSettings($workspaceId, $userId);
        $whatsAppContext = [
            'whatsapp' => [
                'health' => (array) ($health['whatsapp'] ?? []),
                'settings' => $whatsAppSettings,
                'settings_in_use' => $this->whatsAppSettingsInUse($whatsAppSettings),
            ],
            'migration_status' => $this->migrationStatus($workspaceId, $userId),
            'health' => $health,
        ];

        $assistantRuntime = $workspaceId > 0 ? $this->assistantConfig->get($workspaceId, 'whatsapp', false) : [];
        $assistantSettings = (array) ($assistantRuntime['settings'] ?? []);
        $whatsAppInstalled = $workspaceId > 0 && $this->installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP);
        $assistantInstalled = $workspaceId > 0 && $this->installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);
        $canManageChannel = $workspaceId > 0 && $this->workspaceConnect->canManageWhatsAppConnection($user, $workspaceId);
        $canManageAssistant = $this->canManageMarketplaceSetup($user);

        $channelContext = array_merge($whatsAppContext, [
            'active_tab' => $channelActiveTab,
            'installed' => $whatsAppInstalled,
            'can_manage' => $canManageChannel,
            'readiness' => $this->readiness($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP),
            'events' => $this->events($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP),
            'setup_base_url' => $settingsSource ? 'settings.php?tab=whatsapp&setup_module=whatsapp' : '',
            'settings_tab' => $settingsSource ? 'whatsapp' : '',
        ]);

        $assistantContext = [
            'active_tab' => $assistantActiveTab,
            'settings' => $assistantSettings,
            'runtime' => $this->assistantRuntimeSummary($workspaceId),
            'authorized_numbers' => $this->authorizedNumbers($workspaceId),
            'workspace_users' => $this->workspaceUsers($workspaceId),
            'enabled' => !empty($assistantRuntime['enabled']),
            'installed' => $assistantInstalled,
            'can_manage' => $canManageAssistant,
            'readiness' => $this->readiness($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT),
            'events' => $this->events($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT),
            'setup_base_url' => $settingsSource ? 'settings.php?tab=whatsapp&setup_module=whatsapp_assistant' : '',
            'settings_tab' => $settingsSource ? 'whatsapp' : '',
        ];

        return [
            'source' => $source,
            'channel' => $channelContext,
            'assistant' => $assistantContext,
            'communication_setup' => $whatsAppContext,
            'whatsapp_installed' => $whatsAppInstalled,
            'assistant_installed' => $assistantInstalled,
            'can_manage_channel' => $canManageChannel,
            'can_manage_assistant' => $canManageAssistant,
        ];
    }

    public function handleWhatsAppHubPost(
        int $workspaceId,
        int $userId,
        array $user,
        array $post,
        string $source = 'settings'
    ): array {
        $action = $this->postString($post, 'skill_action');
        $skillKey = $this->postString($post, 'skill_key');
        $eventSource = $source === 'settings' ? 'settings_whatsapp_hub' : 'workspace_marketplace_page';

        if ($action === 'save_communication_setup') {
            $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, $skillKey);
            if (strtolower($this->postString($post, 'communication_channel', 'whatsapp')) !== 'whatsapp') {
                throw new \RuntimeException('Open WhatsApp setup before saving WhatsApp settings.');
            }
            $this->requireCanManageChannel($workspaceId, $user);
            $this->workspaceConnect->storeManualWhatsAppIntegration($workspaceId, $userId, [
                'connection_mode' => 'self_managed',
                'phone_number_id' => $this->postString($post, 'phone_number_id'),
                'display_phone_number' => $this->postString($post, 'display_phone_number'),
                'access_token' => $this->postString($post, 'access_token'),
                'verified_name' => $this->postString($post, 'verified_name'),
                'whatsapp_business_account_id' => $this->postString($post, 'whatsapp_business_account_id'),
                'meta_business_id' => $this->postString($post, 'meta_business_id'),
                'token_expires_at' => $this->postString($post, 'token_expires_at'),
                'notes' => $this->postString($post, 'notes'),
            ]);
            $this->setupEvents->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, 'setup_saved', [
                'label' => 'WhatsApp settings saved',
                'source' => $eventSource,
                'metadata' => ['label' => 'WhatsApp settings saved', 'channel' => 'whatsapp'],
            ]);

            return [
                'success' => 'WhatsApp settings saved.',
                'setup_module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'manual',
            ];
        }

        if ($action === 'run_communication_channel_test') {
            $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, $skillKey);
            $this->requireCanManageChannel($workspaceId, $user);
            $channelKey = $this->normalizeKey($this->postString($post, 'communication_test_channel', 'whatsapp'));
            if ($channelKey !== 'whatsapp') {
                throw new \RuntimeException('Open WhatsApp setup before testing WhatsApp settings.');
            }

            $latestHealth = $this->channelHealth->summarize($workspaceId, $user);
            $selectedHealth = (array) ($latestHealth['whatsapp'] ?? []);
            $status = (string) ($selectedHealth['status'] ?? 'not_connected');
            $healthLabel = trim((string) ($selectedHealth['label'] ?? ''));
            $detailCandidates = array_values(array_filter(array_merge(
                array_map('strval', (array) ($selectedHealth['issues'] ?? [])),
                array_map('strval', (array) ($selectedHealth['actions'] ?? [])),
                [$healthLabel]
            ), static fn(string $item): bool => trim($item) !== ''));
            $detail = trim((string) ($detailCandidates[0] ?? ''));
            $passed = in_array($status, ['ready', 'warning'], true);

            $this->setupEvents->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, $passed ? 'step_completed' : 'step_reset', [
                'step_key' => 'test_whatsapp',
                'label' => 'WhatsApp readiness test',
                'source' => $eventSource,
                'step_status' => $passed ? 'completed' : 'pending',
                'metadata' => [
                    'label' => 'WhatsApp readiness test',
                    'channel' => 'whatsapp',
                    'status' => $status,
                    'health_label' => $healthLabel,
                    'detail' => $detail,
                ],
            ]);

            if ($status === 'ready') {
                $message = 'WhatsApp readiness test passed.';
            } elseif ($status === 'warning') {
                $message = 'WhatsApp readiness test completed with warnings' . ($detail !== '' ? ': ' . $detail : '') . '.';
            } else {
                $message = 'WhatsApp readiness test needs setup' . ($detail !== '' ? ': ' . $detail : '') . '.';
            }

            return [
                'success' => $message,
                'setup_module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                'setup_tab' => 'tests',
            ];
        }

        if ($action === 'save_whatsapp_assistant_setup') {
            $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $skillKey);
            $this->requireCanManageAssistant($user);
            $current = $this->assistantConfig->get($workspaceId, 'whatsapp', true);
            $currentSettings = (array) ($current['settings'] ?? []);
            $senderMode = $this->postBool($post, 'whatsapp_assistant_use_custom_sender') ? 'custom' : 'workspace';
            $customSender = $senderMode === 'custom';
            $this->assistantConfig->save($workspaceId, 'whatsapp', [
                'sender_mode' => $senderMode,
                'assistant_phone_number' => $customSender ? $this->postString($post, 'whatsapp_assistant_phone_number') : '',
                'assistant_phone_number_id' => $customSender ? $this->postString($post, 'whatsapp_assistant_phone_number_id') : '',
                'access_token' => $customSender ? $this->preserveSecret($post, 'whatsapp_assistant_access_token', 'access_token', $currentSettings) : '',
                'digest_enabled' => $this->postBool($post, 'whatsapp_assistant_digest_enabled'),
                'digest_time' => $this->postString($post, 'whatsapp_assistant_digest_time', '07:00'),
                'max_message_chars' => (string) $this->postInt($post, 'whatsapp_assistant_max_message_chars', 550, 180, 5000),
                'max_message_chunks' => (string) $this->postInt($post, 'whatsapp_assistant_max_message_chunks', 6, 2, 50),
                'keepalive_warning_hours' => (string) $this->postInt($post, 'whatsapp_assistant_keepalive_warning_hours', 4, 1, 72),
                'auto_reopen_enabled' => $this->postBool($post, 'whatsapp_assistant_auto_reopen_enabled'),
                'reopen_template_name' => $this->postString($post, 'whatsapp_assistant_reopen_template_name'),
                'reopen_template_language' => $this->postString($post, 'whatsapp_assistant_reopen_template_language', 'en_US'),
                'reopen_template_header_values' => $this->postString($post, 'whatsapp_assistant_reopen_template_header_values'),
                'reopen_template_body_values' => $this->postString($post, 'whatsapp_assistant_reopen_template_body_values'),
                'reopen_template_button_values' => $this->postString($post, 'whatsapp_assistant_reopen_template_button_values'),
            ] + $this->assistantActionSettingsFromPost($post), $this->postBool($post, 'whatsapp_assistant_enabled'), $userId);
            $authorizedCount = null;
            if (array_key_exists('assistant_mapping_phone', $post)) {
                $authorizedCount = $this->saveAuthorizedNumbers($workspaceId, $post);
            }
            $this->setupEvents->recordEvent($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'setup_saved', [
                'label' => 'WhatsApp Assistant setup saved',
                'source' => $eventSource,
                'metadata' => [
                    'label' => 'WhatsApp Assistant setup saved',
                    'sender_mode' => $senderMode,
                    'authorized_number_count' => $authorizedCount,
                ],
            ]);

            return [
                'success' => 'WhatsApp Assistant setup saved.',
                'setup_module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'setup_tab' => $this->postString($post, 'whatsapp_assistant_setup_tab', 'identity'),
            ];
        }

        if ($action === 'run_marketplace_readiness_check') {
            if (!in_array($skillKey, [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT], true)) {
                throw new \RuntimeException('WhatsApp readiness check could not be matched to a setup module.');
            }
            if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP) {
                $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, $skillKey);
                $this->requireCanManageChannel($workspaceId, $user);
            } else {
                $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $skillKey);
                $this->requireCanManageAssistant($user);
            }

            $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_attempted', [
                'metadata' => ['source' => $eventSource, 'label' => 'Readiness check started'],
            ]);
            $readinessCheck = $this->installer->buildReadinessForModule($workspaceId, $userId, $skillKey);
            $passed = (bool) ($readinessCheck['ready'] ?? false);
            $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', $passed ? 'test_passed' : 'test_failed', [
                'metadata' => [
                    'source' => $eventSource,
                    'label' => 'Readiness check ' . ($passed ? 'passed' : 'failed'),
                    'status' => (string) ($readinessCheck['status'] ?? ''),
                    'blockers' => (array) ($readinessCheck['blockers'] ?? []),
                ],
            ]);

            return [
                'success' => $passed
                    ? 'Readiness check passed. No external message was sent.'
                    : 'Readiness check finished. Complete the setup blockers before running live tests.',
                'setup_module' => $skillKey,
                'setup_tab' => 'tests',
            ];
        }

        if ($action === 'send_whatsapp_assistant_test_digest') {
            $this->requireSkill($workspaceId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $skillKey);
            $this->requireCanManageAssistant($user);
            $testPhone = trim($this->postString($post, 'whatsapp_test_recipient'));
            if ($testPhone === '') {
                $authorizedNumbers = (new WhatsAppAssistantConfig())->getAuthorizedNumbers(true, $workspaceId);
                if ($authorizedNumbers === []) {
                    throw new \RuntimeException('Add at least one active authorized team number before sending a WhatsApp Assistant test digest.');
                }
                throw new \RuntimeException('Choose an active authorized WhatsApp test number before sending a live test digest.');
            }
            $authorizedNumbers = (new WhatsAppAssistantConfig())->getAuthorizedNumbers(true, $workspaceId);
            $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_attempted', [
                'metadata' => ['source' => $eventSource, 'label' => 'WhatsApp live test attempted'],
            ]);
            $digest = new WhatsAppAssistantDigestService();
            $digestConfig = $digest->validateDigestConfig();
            if (empty($digestConfig['outbound_ready'])) {
                $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => ['source' => $eventSource, 'label' => 'WhatsApp live test blocked by readiness'],
                ]);
                throw new \RuntimeException((string) ($digestConfig['message'] ?? 'WhatsApp Assistant outbound setup is not ready.'));
            }
            $whatsAppAssistantConfig = new WhatsAppAssistantConfig();
            $normalizedTestPhone = $whatsAppAssistantConfig->normalizePhoneNumber($testPhone);
            $recipient = null;
            foreach ($authorizedNumbers as $row) {
                if ((string) ($row['phone_number'] ?? '') === $normalizedTestPhone) {
                    $recipient = $row;
                    break;
                }
            }
            if (!$recipient) {
                $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => ['source' => $eventSource, 'label' => 'WhatsApp live test recipient not authorized'],
                ]);
                throw new \RuntimeException('That WhatsApp test number is not in the active authorized assistant list.');
            }
            $digestResult = $digest->sendDigestToNumber((int) ($recipient['user_id'] ?? 0), (string) ($recipient['phone_number'] ?? ''), true);
            if (empty($digestResult['success'])) {
                $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => [
                        'source' => $eventSource,
                        'label' => 'WhatsApp live test digest not sent',
                        'status' => (string) ($digestResult['status'] ?? 'failed'),
                    ],
                ]);
                throw new \RuntimeException((string) ($digestResult['message'] ?? $digestResult['error'] ?? 'WhatsApp Assistant test digest was not sent.'));
            }
            $this->recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_passed', [
                'metadata' => ['source' => $eventSource, 'label' => 'WhatsApp live test sent'],
            ]);

            return [
                'success' => 'WhatsApp Assistant test digest sent.',
                'setup_module' => WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                'setup_tab' => 'tests',
            ];
        }

        throw new \RuntimeException('Unsupported WhatsApp setup action.');
    }

    private function buildWhatsAppSettings(int $workspaceId, int $userId): array
    {
        $activeWhatsApp = $this->workspaceConnect->getActiveWhatsAppIntegration($workspaceId) ?: [];
        $metadata = json_decode((string) ($activeWhatsApp['settings_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $tokenExpiresAt = trim((string) ($activeWhatsApp['token_expires_at'] ?? ''));
        $tokenExpiresLocal = $tokenExpiresAt !== '' && strtotime($tokenExpiresAt) !== false
            ? date('Y-m-d\TH:i', strtotime($tokenExpiresAt))
            : '';
        $webhookToken = trim((string) ($activeWhatsApp['webhook_token'] ?? ''));
        $webhookCallbackUrl = $this->workspaceConnect->buildWhatsAppWebhookCallbackUrl($webhookToken);
        $metaAppId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
        $metaAppSecretSaved = trim((string) ($_ENV['META_APP_SECRET'] ?? '')) !== '';
        $embeddedSignupConfigId = trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''));
        $metaAppConfigured = $metaAppId !== '' && $metaAppSecretSaved;
        $mode = (string) ($activeWhatsApp['connection_mode'] ?? 'self_managed');
        $mode = $mode === 'platform_managed' ? 'platform_managed' : 'self_managed';
        $featureFlags = (new WhatsAppFeatureGate())->snapshot($workspaceId);
        try {
            $managedReadiness = (new ManagedWhatsAppProvisioningService())->platformReadiness($workspaceId);
        } catch (\Throwable $e) {
            $managedReadiness = [
                'available' => false,
                'missing' => ['managed_provisioning_service'],
                'missing_labels' => ['Managed provisioning service'],
            ];
        }
        try {
            $creditSummary = $mode === 'platform_managed' && !empty($featureFlags['whatsapp_managed_billing'])
                ? (new WorkspaceWhatsAppCreditService())->summary($workspaceId)
                : [];
        } catch (\Throwable $e) {
            $creditSummary = ['error' => $e->getMessage()];
        }

        return [
            'source_label' => !empty($activeWhatsApp) ? ($mode === 'platform_managed' ? 'Platform-managed WhatsApp' : 'Workspace manual settings') : 'Not configured',
            'connection_mode' => $mode,
            'connection_mode_label' => $mode === 'platform_managed' ? 'Managed WhatsApp' : 'Self-managed Meta account',
            'connection_status' => (string) ($activeWhatsApp['connection_status'] ?? ''),
            'provider' => (string) ($metadata['provider'] ?? 'meta_cloud'),
            'phone_number_id' => (string) ($activeWhatsApp['phone_number_id'] ?? ''),
            'display_phone_number' => (string) ($activeWhatsApp['display_phone_number'] ?? ''),
            'access_token_saved' => trim((string) ($activeWhatsApp['access_token'] ?? '')) !== '',
            'verified_name' => (string) ($activeWhatsApp['verified_name'] ?? ''),
            'whatsapp_business_account_id' => (string) ($activeWhatsApp['whatsapp_business_account_id'] ?? ''),
            'meta_business_id' => (string) ($activeWhatsApp['meta_business_id'] ?? ''),
            'token_expires_at_local' => $tokenExpiresLocal,
            'token_expires_at_display' => $tokenExpiresAt,
            'notes' => (string) ($metadata['manual_notes'] ?? ''),
            'webhook_token' => $webhookToken,
            'webhook_callback_url' => $webhookCallbackUrl,
            'webhook_verify_token' => (string) ($activeWhatsApp['webhook_verify_token'] ?? ''),
            'webhook_verify_token_saved' => trim((string) ($activeWhatsApp['webhook_verify_token'] ?? '')) !== '',
            'webhook_verified_at' => (string) ($activeWhatsApp['webhook_verified_at'] ?? ''),
            'webhook_last_status' => (string) ($activeWhatsApp['webhook_last_status'] ?? ''),
            'webhook_last_error' => (string) ($activeWhatsApp['webhook_last_error'] ?? ''),
            'webhook_last_event_at' => (string) ($activeWhatsApp['webhook_last_event_at'] ?? ''),
            'meta_app_id' => $metaAppId,
            'meta_app_secret_saved' => $metaAppSecretSaved,
            'meta_app_configured' => $metaAppConfigured,
            'embedded_signup_config_id' => $embeddedSignupConfigId,
            'embedded_signup_configured' => $metaAppConfigured && $embeddedSignupConfigId !== '',
            'managed_available' => !empty($managedReadiness['available']),
            'feature_flags' => $featureFlags,
            'dual_setup_modes_enabled' => !empty($featureFlags['whatsapp_dual_setup_modes']),
            'template_center_enabled' => !empty($featureFlags['whatsapp_template_center']),
            'managed_billing_enabled' => !empty($featureFlags['whatsapp_managed_billing']),
            'managed_missing_configuration' => (array) ($managedReadiness['missing'] ?? []),
            'managed_missing_configuration_labels' => (array) ($managedReadiness['missing_labels'] ?? []),
            'managed_status' => (string) ($activeWhatsApp['managed_status'] ?? ''),
            'managed_billing_status' => (string) ($activeWhatsApp['managed_billing_status'] ?? ''),
            'managed_provider_reference' => (string) ($activeWhatsApp['managed_provider_reference'] ?? ''),
            'managed_credit_balance' => (float) ($activeWhatsApp['managed_credit_balance'] ?? 0),
            'managed_credit_reserved' => (float) ($activeWhatsApp['managed_credit_reserved'] ?? 0),
            'managed_currency' => (string) ($activeWhatsApp['managed_currency'] ?? 'KES'),
            'managed_low_balance_threshold' => (float) ($activeWhatsApp['managed_low_balance_threshold'] ?? 100),
            'managed_daily_spend_cap' => $activeWhatsApp['managed_daily_spend_cap'] ?? null,
            'managed_monthly_spend_cap' => $activeWhatsApp['managed_monthly_spend_cap'] ?? null,
            'managed_auto_topup_enabled' => !empty($activeWhatsApp['managed_auto_topup_enabled']),
            'managed_auto_topup_threshold' => $activeWhatsApp['managed_auto_topup_threshold'] ?? null,
            'managed_auto_topup_amount' => $activeWhatsApp['managed_auto_topup_amount'] ?? null,
            'managed_last_health_at' => (string) ($activeWhatsApp['managed_last_health_at'] ?? ''),
            'managed_last_health_status' => (string) ($activeWhatsApp['managed_last_health_status'] ?? ''),
            'managed_last_health_error' => (string) ($activeWhatsApp['managed_last_health_error'] ?? ''),
            'credit_summary' => $creditSummary,
            'template_center_url' => 'whatsapp_templates.php',
            'managed_admin_url' => 'whatsapp_managed_admin.php',
        ];
    }

    private function whatsAppSettingsInUse(array $settings): array
    {
        $items = [
            ['label' => 'Source', 'value' => (string) ($settings['source_label'] ?? '')],
            ['label' => 'Mode', 'value' => (string) ($settings['connection_mode_label'] ?? '')],
            ['label' => 'Connection', 'value' => (string) ($settings['connection_status'] ?? '')],
            ['label' => 'Provider', 'value' => (string) ($settings['provider'] ?? '')],
            ['label' => 'Phone number ID', 'value' => (string) ($settings['phone_number_id'] ?? '')],
            ['label' => 'Display number', 'value' => (string) ($settings['display_phone_number'] ?? '')],
            ['label' => 'Verified name', 'value' => (string) ($settings['verified_name'] ?? '')],
            ['label' => 'WABA ID', 'value' => (string) ($settings['whatsapp_business_account_id'] ?? '')],
            ['label' => 'Meta business ID', 'value' => (string) ($settings['meta_business_id'] ?? '')],
            ['label' => 'Access token', 'value' => !empty($settings['access_token_saved']) ? 'Saved' : 'Not saved'],
            ['label' => 'Webhook URL', 'value' => (string) ($settings['webhook_callback_url'] ?? '')],
            ['label' => 'Verify token', 'value' => !empty($settings['webhook_verify_token_saved']) ? 'Saved' : 'Not saved'],
            ['label' => 'Notes', 'value' => (string) ($settings['notes'] ?? '')],
        ];
        if (!empty($settings['meta_app_configured'])) {
            $items[] = ['label' => 'Meta app ID', 'value' => (string) ($settings['meta_app_id'] ?? '')];
            $items[] = ['label' => 'Meta app secret', 'value' => !empty($settings['meta_app_secret_saved']) ? 'Saved' : 'Not saved'];
        }
        if (!empty($settings['embedded_signup_configured'])) {
            $items[] = ['label' => 'Embedded signup config', 'value' => (string) ($settings['embedded_signup_config_id'] ?? '')];
        }
        if ((string) ($settings['connection_mode'] ?? '') === 'platform_managed') {
            $items[] = ['label' => 'Managed status', 'value' => (string) ($settings['managed_status'] ?? '')];
            $items[] = ['label' => 'Credit balance', 'value' => trim((string) ($settings['managed_currency'] ?? 'KES') . ' ' . number_format((float) ($settings['managed_credit_balance'] ?? 0), 2))];
            $items[] = ['label' => 'Reserved credits', 'value' => trim((string) ($settings['managed_currency'] ?? 'KES') . ' ' . number_format((float) ($settings['managed_credit_reserved'] ?? 0), 2))];
        }

        return $items;
    }

    private function migrationStatus(int $workspaceId, int $userId): array
    {
        try {
            $status = (new WhatsAppMigrationService($workspaceId, $userId))->getMigrationStatus();
            return is_array($status) ? $status : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function readiness(int $workspaceId, int $userId, string $skillKey): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        try {
            return $this->installer->buildReadinessForModule($workspaceId, $userId, $skillKey);
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'status' => 'unavailable',
                'next_action' => 'Readiness is temporarily unavailable.',
                'checks' => [],
            ];
        }
    }

    private function events(int $workspaceId, string $skillKey): array
    {
        try {
            return $this->setupEvents->getEvents([
                'workspace_id' => $workspaceId,
                'skill_key' => $skillKey,
            ], 25);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function assistantRuntimeSummary(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            $runtime = $this->assistantConfig->whatsappRuntimeConfig($workspaceId);
        } catch (\Throwable $e) {
            return [];
        }

        unset($runtime['access_token']);
        return $runtime;
    }

    private function authorizedNumbers(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            return (new WhatsAppAssistantConfig())->getAuthorizedNumbers(false, $workspaceId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function workspaceUsers(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !\CRM\Database::tableExists('workspace_memberships')) {
            return \CRM\Database::query(
                "SELECT id, first_name, last_name, email
                 FROM users
                 ORDER BY first_name ASC, last_name ASC, email ASC"
            );
        }

        return \CRM\Database::query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
            [$workspaceId]
        );
    }

    private function saveAuthorizedNumbers(int $workspaceId, array $post): int
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to save authorized WhatsApp Assistant numbers.');
        }
        if (!\CRM\Database::tableExists('whatsapp_assistant_authorized_numbers')) {
            throw new \RuntimeException('WhatsApp Assistant authorized numbers table is missing. Run migrations first.');
        }

        $mappingPhones = (array) ($post['assistant_mapping_phone'] ?? []);
        $mappingUsers = (array) ($post['assistant_mapping_user_id'] ?? []);
        $mappingLabels = (array) ($post['assistant_mapping_label'] ?? []);
        $mappingActive = (array) ($post['assistant_mapping_is_active'] ?? []);
        $mappingDigest = (array) ($post['assistant_mapping_digest_enabled'] ?? []);
        $normalizer = new WhatsAppAssistantConfig();
        $rows = [];
        $seenPhones = [];
        $mappingCount = max(count($mappingPhones), count($mappingUsers), count($mappingLabels));

        for ($i = 0; $i < $mappingCount; $i++) {
            $rawPhone = trim((string) ($mappingPhones[$i] ?? ''));
            $mappedUserId = (int) ($mappingUsers[$i] ?? 0);
            $label = trim((string) ($mappingLabels[$i] ?? ''));
            $isActive = isset($mappingActive[$i]) ? 1 : 0;
            $digestEnabled = isset($mappingDigest[$i]) ? 1 : 0;

            if ($rawPhone === '' && $mappedUserId <= 0 && $label === '') {
                continue;
            }

            $normalizedPhone = $normalizer->normalizePhoneNumber($rawPhone);
            if ($normalizedPhone === '') {
                throw new \RuntimeException('Each authorized WhatsApp Assistant number needs a valid phone number.');
            }
            if ($mappedUserId <= 0) {
                throw new \RuntimeException('Each authorized WhatsApp Assistant number must be linked to a CRM user.');
            }
            if (isset($seenPhones[$normalizedPhone])) {
                throw new \RuntimeException('Duplicate authorized WhatsApp Assistant number detected for +' . $normalizedPhone . '.');
            }
            if (!$this->workspaceUserExists($workspaceId, $mappedUserId)) {
                throw new \RuntimeException('A selected WhatsApp Assistant user could not be found in this workspace.');
            }

            $seenPhones[$normalizedPhone] = true;
            $rows[] = [
                'phone_number' => $normalizedPhone,
                'user_id' => $mappedUserId,
                'label' => $label,
                'is_active' => $isActive,
                'digest_enabled' => $digestEnabled,
            ];
        }

        $hasWorkspace = \CRM\Database::columnExists('whatsapp_assistant_authorized_numbers', 'workspace_id');
        if ($hasWorkspace) {
            \CRM\Database::execute("DELETE FROM whatsapp_assistant_authorized_numbers WHERE workspace_id = ?", [$workspaceId]);
        } else {
            \CRM\Database::execute("DELETE FROM whatsapp_assistant_authorized_numbers");
        }

        foreach ($rows as $row) {
            $columns = ['phone_number', 'user_id', 'label', 'is_active', 'digest_enabled', 'created_at', 'updated_at'];
            $placeholders = ['?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
            $params = [
                $row['phone_number'],
                $row['user_id'],
                $row['label'] !== '' ? $row['label'] : null,
                $row['is_active'],
                $row['digest_enabled'],
            ];
            if ($hasWorkspace) {
                array_unshift($columns, 'workspace_id');
                array_unshift($placeholders, '?');
                array_unshift($params, $workspaceId);
            }
            \CRM\Database::execute(
                "INSERT INTO whatsapp_assistant_authorized_numbers (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );
        }

        return count($rows);
    }

    private function workspaceUserExists(int $workspaceId, int $userId): bool
    {
        if ($workspaceId > 0 && \CRM\Database::tableExists('workspace_memberships')) {
            $row = \CRM\Database::queryOne(
                "SELECT u.id
                 FROM users u
                 JOIN workspace_memberships wm ON wm.user_id = u.id
                 WHERE u.id = ?
                   AND wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                 LIMIT 1",
                [$userId, $workspaceId]
            );
            return !empty($row);
        }

        return (bool) \CRM\Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$userId]);
    }

    private function activeHubTab(string $requestedTab, string $requestedModule, string $moduleKey, array $tabs, string $default): string
    {
        if ($requestedModule === $moduleKey && in_array($requestedTab, $tabs, true)) {
            return $requestedTab;
        }
        if ($requestedModule === '' && in_array($requestedTab, $tabs, true)) {
            return $requestedTab;
        }

        return $default;
    }

    private function requireSkill(int $workspaceId, string $expectedSkillKey, string $postedSkillKey): void
    {
        if ($postedSkillKey !== $expectedSkillKey) {
            throw new \RuntimeException('WhatsApp setup action could not be matched to the expected module.');
        }
        if (!$this->installer->isInstalled($workspaceId, $expectedSkillKey)) {
            $label = $expectedSkillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT ? 'WhatsApp Assistant' : 'WhatsApp';
            throw new \RuntimeException('Install ' . $label . ' before changing setup.');
        }
    }

    private function requireCanManageChannel(int $workspaceId, array $user): void
    {
        if (!$this->workspaceConnect->canManageWhatsAppConnection($user, $workspaceId)) {
            throw new \RuntimeException('Your access profile does not allow WhatsApp connection changes.');
        }
    }

    private function requireCanManageAssistant(array $user): void
    {
        if (!$this->canManageMarketplaceSetup($user)) {
            throw new \RuntimeException('Your access profile does not allow WhatsApp Assistant setup changes.');
        }
    }

    private function canManageMarketplaceSetup(array $user): bool
    {
        return Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user);
    }

    private function postString(array $post, string $key, string $default = ''): string
    {
        return trim((string) ($post[$key] ?? $default));
    }

    private function postBool(array $post, string $key): bool
    {
        return isset($post[$key]) && !in_array(strtolower((string) $post[$key]), ['', '0', 'false', 'off', 'no'], true);
    }

    private function postInt(array $post, string $key, int $default, int $min = 0, int $max = 100000): int
    {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    private function preserveSecret(array $post, string $postKey, string $settingsKey, array $currentSettings): string
    {
        $posted = $this->postString($post, $postKey);
        return $posted !== '' ? $posted : (string) ($currentSettings[$settingsKey] ?? '');
    }

    private function normalizeKey(string $value): string
    {
        return trim((string) (preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?? ''), '_');
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,bool>
     */
    private function assistantActionSettingsFromPost(array $post): array
    {
        $settings = [];
        foreach (AssistantActionRuntimeConfig::ACTION_SETTING_KEYS as $key) {
            $settings[$key] = $this->postBool($post, 'whatsapp_assistant_' . $key);
        }

        return $settings;
    }
}
