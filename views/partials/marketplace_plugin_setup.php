<?php

use CRM\Services\AssistantActionRuntimeConfig;
use CRM\Services\WorkspaceAIProviderConfigService;
use CRM\Services\WorkspaceSkillCatalogService;

if (!function_exists('marketplaceWhatsAppEmbeddedSignupConfigured')) {
    function marketplaceWhatsAppEmbeddedSignupConfigured(): bool
    {
        return trim((string) ($_ENV['META_APP_ID'] ?? '')) !== ''
            && trim((string) ($_ENV['META_APP_SECRET'] ?? '')) !== ''
            && trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? '')) !== '';
    }
}

if (!function_exists('marketplaceWhatsAppMetaAppConfigured')) {
    function marketplaceWhatsAppMetaAppConfigured(): bool
    {
        return trim((string) ($_ENV['META_APP_ID'] ?? '')) !== ''
            && trim((string) ($_ENV['META_APP_SECRET'] ?? '')) !== '';
    }
}

if (!function_exists('marketplaceWhatsAppWebhookAppConfigured')) {
    function marketplaceWhatsAppWebhookAppConfigured(): bool
    {
        if (trim((string) ($_ENV['META_APP_ID'] ?? '')) === '') {
            return false;
        }

        return trim((string) ($_ENV['META_APP_ACCESS_TOKEN'] ?? '')) !== ''
            || trim((string) ($_ENV['META_APP_SECRET'] ?? '')) !== ''
            || trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')) !== '';
    }
}

if (!function_exists('marketplaceSetupTabUrl')) {
    function marketplaceSetupTabUrl(string $moduleKey, string $tabKey, string $baseUrl = ''): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl !== '') {
            $fragment = '';
            $hashPos = strpos($baseUrl, '#');
            if ($hashPos !== false) {
                $fragment = substr($baseUrl, $hashPos);
                $baseUrl = substr($baseUrl, 0, $hashPos);
            }
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            return $baseUrl . $separator . http_build_query(['setup_tab' => $tabKey]) . $fragment;
        }

        return 'workspace_skills.php?' . http_build_query([
            'module' => $moduleKey,
            'setup_tab' => $tabKey,
        ]) . '#setup';
    }
}

if (!function_exists('marketplaceSetupTabsForModule')) {
    function marketplaceSetupTabsForModule(string $moduleKey): array
    {
        return match ($moduleKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL => [
                'outreach_email' => 'Outreach',
                'nurture_email' => 'Nurture',
            ],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => array_filter([
                'manual' => 'Connection',
                'webhook' => 'Webhook',
                'templates' => 'Templates',
                'billing' => 'Credits',
                'migration' => 'Legacy Migration',
                'embedded_signup' => marketplaceWhatsAppEmbeddedSignupConfigured() ? 'Meta Signup' : null,
                'tests' => 'Tests',
                'activity' => 'Activity',
            ]),
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => [
                'identity' => 'Identity',
                'outbound' => 'Outbound',
                'inbound' => 'Inbound',
                'skills' => 'Skills',
                'digest' => 'Digest',
                'tests' => 'Tests',
                'activity' => 'Activity',
                'advanced' => 'Advanced',
            ],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => [
                'identity' => 'Identity',
                'session' => 'Session',
                'tests' => 'Tests',
                'activity' => 'Activity',
                'advanced' => 'Advanced',
            ],
            WorkspaceSkillCatalogService::SKILL_AI_COACH => [
                'workspace_readiness' => 'Workspace Readiness',
                'team_briefs' => 'Team Context',
                'my_brief' => 'Personal Strategy',
                'pairings' => 'Pairings',
                'smart_templates' => 'Smart Templates',
                'tests' => 'Tests',
                'activity' => 'Activity',
            ],
            WorkspaceSkillCatalogService::PLUGIN_AI_API => [
                'provider' => 'Provider',
                'usage' => 'Usage',
                'activity' => 'Activity',
            ],
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => [
                'overview' => 'Overview',
                'calendar' => 'Calendar',
                'booking' => 'Booking',
                'availability' => 'Availability',
                'blocked_times' => 'Blocked Times',
                'bot' => 'Meeting Bot',
                'notes' => 'Notes',
                'runs' => 'Runs',
                'activity' => 'Activity',
            ],
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA => [
                'overview' => 'Overview',
                'brand' => 'Brand',
                'connections' => 'Connections',
                'publishing' => 'Publishing',
                'activity' => 'Activity',
            ],
            WorkspaceSkillCatalogService::PLUGIN_DESIGN => [
                'overview' => 'Overview',
                'landing_pages' => 'Landing Pages',
                'forms' => 'Forms',
                'assets' => 'Assets',
            ],
            WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER => [
                'overview' => 'Overview',
                'provider' => 'Provider',
                'team' => 'Team',
                'queues' => 'Queues & Hours',
                'ai_consent' => 'AI & Consent',
                'tests' => 'Tests',
                'usage' => 'Usage',
                'activity' => 'Activity',
                'advanced' => 'Advanced',
            ],
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP => [
                'setup' => 'Guided Setup',
                'activity' => 'Activity',
            ],
            WorkspaceSkillCatalogService::PLUGIN_FINANCE => [
                'start' => 'Start',
                'bank' => 'Bank',
                'assets' => 'Assets',
                'receivables' => 'Owed To Us',
                'liabilities' => 'Owed By Us',
                'owners' => 'Owners',
                'review' => 'Review',
            ],
            default => [
                'setup' => 'Setup',
                'tests' => 'Tests',
                'activity' => 'Activity',
                'advanced' => 'Advanced',
            ],
        };
    }
}

if (!function_exists('marketplaceRenderSocialMediaSetup')) {
    function marketplaceRenderSocialMediaSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'overview'));
        $csrf = (string) ($ctx['csrf'] ?? '');
        $installed = !empty($ctx['installed']);
        $canManage = !empty($ctx['can_manage']);
        $settings = (array) ($ctx['settings'] ?? []);
        $accounts = (array) ($ctx['accounts'] ?? []);
        $summary = (array) ($ctx['summary'] ?? []);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $platform = (array) ($ctx['platform'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $disabled = (!$installed || !$canManage) ? 'disabled' : '';
        $basePath = function_exists('getBasePath') ? rtrim((string) getBasePath(), '/') : '';
        $apiBase = rtrim(str_replace('/public', '', $basePath), '/');
        $returnTo = rawurlencode($basePath . '/workspace_skills.php?module=social_media&setup_tab=connections#setup');
        $statusLabel = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
        ?>
        <div class="marketplace-channel-setup social-media-marketplace-setup">
            <?php marketplaceRenderSetupTabs($moduleKey, $activeTab); ?>
            <?php if (!empty($_GET['social_error'])): ?><div class="alert alert-danger" style="margin-top:1rem;"><?php echo htmlspecialchars((string) $_GET['social_error']); ?></div><?php endif; ?>
            <?php if (!empty($_GET['social_connected'])): ?><div class="alert alert-success" style="margin-top:1rem;"><?php echo (int) ($_GET['social_connected_count'] ?? 0); ?> destination(s) connected through <?php echo htmlspecialchars(ucfirst((string) $_GET['social_connected'])); ?>.</div><?php endif; ?>

            <?php if ($activeTab === 'overview'): ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <div class="marketplace-overview-block">
                        <h4>Workspace readiness</h4>
                        <p><?php echo htmlspecialchars((string) ($readiness['message'] ?? 'Save the brand policy and connect a social destination.')); ?></p>
                        <div style="display:flex;gap:.45rem;flex-wrap:wrap;margin-top:.75rem;">
                            <span class="marketplace-setup-check-pill <?php echo !empty($readiness['ready']) ? 'is-ready' : 'is-needed'; ?>"><?php echo !empty($readiness['ready']) ? 'Ready' : 'Setup needed'; ?></span>
                            <span class="marketplace-setup-check-pill <?php echo (int) ($summary['active_accounts'] ?? 0) > 0 ? 'is-ready' : 'is-needed'; ?>"><?php echo (int) ($summary['active_accounts'] ?? 0); ?> active account(s)</span>
                            <span class="marketplace-setup-check-pill <?php echo (int) ($summary['failed'] ?? 0) === 0 ? 'is-ready' : 'is-needed'; ?>"><?php echo (int) ($summary['failed'] ?? 0); ?> failed post(s)</span>
                        </div>
                    </div>
                    <div class="marketplace-overview-block">
                        <h4>Production contract</h4>
                        <p>Meta and LinkedIn OAuth tokens are encrypted. Every post is workspace-scoped, idempotent, approval-aware, retried with backoff, and recorded with provider evidence.</p>
                    </div>
                    <div class="marketplace-overview-block">
                        <h4>Measured outcomes</h4>
                        <p><?php echo number_format((int) ($summary['impressions'] ?? 0)); ?> impressions, <?php echo number_format((int) ($summary['engagements'] ?? 0)); ?> engagements, and <?php echo number_format((int) ($summary['conversions'] ?? 0)); ?> CRM conversions are visible in the operator workspace.</p>
                    </div>
                </div>
                <div class="marketplace-detail-actions"><a class="btn-premium-primary" href="social_media.php">Open Social Media</a><a class="btn-premium-secondary" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, 'connections')); ?>">Manage connections</a></div>
            <?php elseif ($activeTab === 'brand'): ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <div class="marketplace-overview-block"><h4>Brand guardrails</h4><p>These fields ground channel variants and keep generated copy inside approved claims.</p></div>
                    <div class="marketplace-form-grid" style="margin-top:1rem;">
                        <label class="marketplace-form-field">Brand name <input type="text" name="social_brand_name" maxlength="180" value="<?php echo htmlspecialchars((string) ($settings['brand_name'] ?? '')); ?>" required <?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Preferred call to action <input type="text" name="social_preferred_cta" maxlength="2000" value="<?php echo htmlspecialchars((string) ($settings['preferred_cta'] ?? '')); ?>" <?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Brand voice <textarea name="social_brand_voice" rows="4" <?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['brand_voice'] ?? '')); ?></textarea></label>
                        <label class="marketplace-form-field">Target audience <textarea name="social_target_audience" rows="4" <?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['target_audience'] ?? '')); ?></textarea></label>
                        <label class="marketplace-form-field">Products and services <textarea name="social_products_services" rows="4" <?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['products_services'] ?? '')); ?></textarea></label>
                        <label class="marketplace-form-field">Approved claims <textarea name="social_approved_claims" rows="4" <?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['approved_claims'] ?? '')); ?></textarea></label>
                        <label class="marketplace-form-field">Prohibited claims <textarea name="social_prohibited_claims" rows="4" <?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['prohibited_claims'] ?? '')); ?></textarea></label>
                    </div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_social_media_brand_setup" <?php echo $disabled; ?>>Save brand guardrails</button></div>
                </form>
            <?php elseif ($activeTab === 'connections'): ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <?php foreach (['meta', 'linkedin'] as $provider): $providerState = (array) ($platform[$provider] ?? []); $canConnect = $installed && $canManage && !empty($providerState['configured']); ?>
                        <div class="marketplace-overview-block">
                            <h4><?php echo htmlspecialchars((string) ($providerState['label'] ?? ucfirst($provider))); ?></h4>
                            <p><?php echo htmlspecialchars((string) ($providerState['message'] ?? 'Provider configuration unavailable.')); ?></p>
                            <?php if ($canConnect): ?>
                                <a class="btn-premium-primary" href="<?php echo htmlspecialchars($apiBase); ?>/api/social/oauth/initiate.php?provider=<?php echo rawurlencode($provider); ?>&amp;return_to=<?php echo $returnTo; ?>">Connect <?php echo htmlspecialchars((string) ($providerState['label'] ?? ucfirst($provider))); ?></a>
                            <?php else: ?>
                                <button class="btn-premium-primary" type="button" disabled>Connect <?php echo htmlspecialchars((string) ($providerState['label'] ?? ucfirst($provider))); ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:1rem;display:grid;gap:.65rem;">
                    <?php if ($accounts === []): ?><div class="marketplace-overview-block"><h4>No destinations yet</h4><p>Authorize Meta or LinkedIn above. Only manageable Pages and professional accounts are saved.</p></div><?php endif; ?>
                    <?php foreach ($accounts as $account): ?>
                        <div class="marketplace-overview-block" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div><h4><?php echo htmlspecialchars((string) ($account['account_name'] ?? 'Social account')); ?></h4><p><?php echo htmlspecialchars(ucfirst((string) ($account['channel'] ?? 'social'))); ?> · <?php echo htmlspecialchars($statusLabel((string) ($account['status'] ?? 'pending'))); ?><?php echo !empty($account['last_verified_at']) ? ' · verified ' . htmlspecialchars((string) $account['last_verified_at']) : ''; ?></p><?php if (!empty($account['last_error'])): ?><p style="color:#b42318;"><?php echo htmlspecialchars((string) $account['last_error']); ?></p><?php endif; ?></div>
                            <?php if ($canManage): ?><div style="display:flex;gap:.45rem;"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>"><button class="btn-premium-secondary" type="submit" name="skill_action" value="verify_social_media_account:<?php echo (int) $account['id']; ?>">Verify</button></form><form method="POST" onsubmit="return confirm('Disconnect this destination and cancel its pending posts?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>"><button class="btn-premium-secondary" type="submit" name="skill_action" value="disconnect_social_media_account:<?php echo (int) $account['id']; ?>">Disconnect</button></form></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($activeTab === 'publishing'): ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <div class="marketplace-overview-block"><h4>Publishing policy</h4><p>Approval is on by default. The queue and metrics commands should be scheduled by cron for live operation.</p></div>
                    <div class="marketplace-form-grid" style="margin-top:1rem;">
                        <label class="marketplace-form-check"><input type="checkbox" name="social_enabled" value="1" <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?> <?php echo $disabled; ?>> Enable social publishing</label>
                        <label class="marketplace-form-check"><input type="checkbox" name="social_approval_required" value="1" <?php echo !empty($settings['approval_required']) ? 'checked' : ''; ?> <?php echo $disabled; ?>> Require manager approval</label>
                        <label class="marketplace-form-check"><input type="checkbox" name="social_metrics_sync_enabled" value="1" <?php echo !empty($settings['metrics_sync_enabled']) ? 'checked' : ''; ?> <?php echo $disabled; ?>> Synchronize provider metrics</label>
                        <label class="marketplace-form-field">Publishing timezone <input type="text" name="social_timezone" value="<?php echo htmlspecialchars((string) ($settings['timezone'] ?? 'Africa/Nairobi')); ?>" placeholder="Africa/Nairobi" <?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Default UTM source <input type="text" name="social_default_utm_source" value="<?php echo htmlspecialchars((string) ($settings['default_utm_source'] ?? 'social')); ?>" <?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Default UTM medium <input type="text" name="social_default_utm_medium" value="<?php echo htmlspecialchars((string) ($settings['default_utm_medium'] ?? 'organic_social')); ?>" <?php echo $disabled; ?>></label>
                    </div>
                    <div class="marketplace-overview-sections" style="margin-top:1rem;"><div class="marketplace-overview-block"><h4>Queue cron</h4><code><?php echo htmlspecialchars((string) ($platform['worker_command'] ?? 'php cli/process_social_media_queue.php all 25')); ?></code></div><div class="marketplace-overview-block"><h4>Metrics cron</h4><code><?php echo htmlspecialchars((string) ($platform['metrics_command'] ?? 'php cli/process_social_media_metrics.php all 50')); ?></code></div></div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_social_media_publishing_setup" <?php echo $disabled; ?>>Save publishing policy</button></div>
                </form>
            <?php else: ?>
                <?php marketplaceRenderSetupActivity($events); ?>
            <?php endif; ?>
            <?php if (!$canManage): ?><p style="margin-top:.85rem;color:#64748b;">Your access profile can review Social Media setup. Marketplace or Marketing manager access is required to change it.</p><?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderDesignSetup')) {
    function marketplaceRenderDesignSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_DESIGN;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'overview'));
        $installed = !empty($ctx['installed']);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $counts = (array) ($readiness['counts'] ?? []);
        $landingPages = (int) ($counts['landing_pages'] ?? 0);
        $forms = (int) ($counts['forms'] ?? 0);
        $publishedPages = (int) ($counts['published_pages'] ?? 0);
        $assets = (int) ($counts['media_files'] ?? 0);
        ?>
        <div class="marketplace-channel-setup design-marketplace-setup">
            <?php marketplaceRenderSetupTabs($moduleKey, $activeTab); ?>

            <?php if ($activeTab === 'overview'): ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <div class="marketplace-overview-block">
                        <h4>Workspace readiness</h4>
                        <p><?php echo htmlspecialchars((string) ($readiness['message'] ?? 'Create a landing page or lead form from the Design workspace.')); ?></p>
                        <div style="display:flex;gap:.45rem;flex-wrap:wrap;margin-top:.75rem;">
                            <span class="marketplace-setup-check-pill <?php echo !empty($readiness['ready']) ? 'is-ready' : 'is-needed'; ?>"><?php echo !empty($readiness['ready']) ? 'Ready' : 'Setup needed'; ?></span>
                            <span class="marketplace-setup-check-pill <?php echo $landingPages > 0 ? 'is-ready' : 'is-needed'; ?>"><?php echo $landingPages; ?> landing page(s)</span>
                            <span class="marketplace-setup-check-pill <?php echo $forms > 0 ? 'is-ready' : 'is-needed'; ?>"><?php echo $forms; ?> form(s)</span>
                        </div>
                    </div>
                    <div class="marketplace-overview-block">
                        <h4>How setup completes</h4>
                        <p>Design becomes ready when this workspace has an active landing page or lead form. Open the Design workspace to create either one; no separate configuration save is required.</p>
                    </div>
                    <div class="marketplace-overview-block">
                        <h4>What stays connected</h4>
                        <p>Landing pages, forms, media, CTA blocks, previews, SEO controls and publication readiness remain workspace-scoped and available from one Design home.</p>
                    </div>
                </div>
                <?php if ($installed): ?><div class="marketplace-detail-actions"><a class="btn-premium-primary" href="design.php">Open Design</a><a class="btn-premium-secondary" href="marketing_landing_page_edit.php">Create landing page</a><a class="btn-premium-secondary" href="form_edit.php">Create form</a></div><?php endif; ?>
            <?php elseif ($activeTab === 'landing_pages'): ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <div class="marketplace-overview-block"><h4>Landing page setup</h4><p><?php echo $landingPages > 0 ? $landingPages . ' active landing page(s) are connected to Design.' : 'No landing page exists yet. Create the first page to complete initial Design readiness.'; ?></p></div>
                    <div class="marketplace-overview-block"><h4>Publication</h4><p><?php echo $publishedPages; ?> page(s) are published. Publication is optional for initial setup; review, preview and conversion checks remain visible in the page board.</p></div>
                </div>
                <?php if ($installed): ?><div class="marketplace-detail-actions"><a class="btn-premium-primary" href="marketing_landing_page_edit.php">Create landing page</a><a class="btn-premium-secondary" href="marketing_landing_pages.php">Open landing page board</a></div><?php endif; ?>
            <?php elseif ($activeTab === 'forms'): ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <div class="marketplace-overview-block"><h4>Lead capture forms</h4><p><?php echo $forms > 0 ? $forms . ' workspace form(s) are connected to Design.' : 'No form exists yet. Create one to complete initial Design readiness and capture page conversions.'; ?></p></div>
                    <div class="marketplace-overview-block"><h4>CRM connection</h4><p>Form submissions stay linked to the workspace and can be reviewed from the form builder before they enter follow-through workflows.</p></div>
                </div>
                <?php if ($installed): ?><div class="marketplace-detail-actions"><a class="btn-premium-primary" href="form_edit.php">Create form</a><a class="btn-premium-secondary" href="forms.php">Open form builder</a></div><?php endif; ?>
            <?php else: ?>
                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                    <div class="marketplace-overview-block"><h4>Creative assets</h4><p><?php echo $assets; ?> design or media asset(s) are available. Assets are optional for initial setup and become part of page media readiness.</p></div>
                    <div class="marketplace-overview-block"><h4>Creative checks</h4><p>Review usage rights, alt text, hero media, social previews and CTA placement before publishing a page.</p></div>
                </div>
                <?php if ($installed): ?><div class="marketplace-detail-actions"><a class="btn-premium-primary" href="marketing_assets.php">Open assets</a><a class="btn-premium-secondary" href="marketing_creative.php">Creative production</a></div><?php endif; ?>
            <?php endif; ?>

            <?php if (!$installed): ?><p style="margin-top:.85rem;color:#64748b;">Install Design before opening its page, form and asset tools.</p><?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderVoiceCallCenterSetup')) {
    function marketplaceRenderVoiceCallCenterSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'overview'));
        $settings = (array) ($ctx['settings'] ?? []);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $agents = (array) ($ctx['agents'] ?? []);
        $queue = (array) ($ctx['queue'] ?? []);
        $queues = (array) ($ctx['queues'] ?? []);
        $queueMembers = (array) ($ctx['queue_members'] ?? []);
        $queueMemberIds = array_map('intval', array_column($queueMembers, 'agent_id'));
        $usage = (array) ($ctx['usage'] ?? []);
        $observability = (array) ($ctx['observability'] ?? []);
        $voiceMetrics = (array) ($observability['metrics'] ?? []);
        $voiceAlerts = (array) ($observability['alerts'] ?? []);
        $deadLetters = (array) ($ctx['dead_letters'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $users = (array) ($ctx['workspace_users'] ?? []);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $canManage = !empty($ctx['can_manage']) && !empty($ctx['installed']);
        $canManageAgents = $canManage && !empty($ctx['can_manage_agents']);
        $disabled = $canManage ? '' : ' disabled';
        $agentDisabled = $canManageAgents ? '' : ' disabled';
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';
        $apiKeySaved = !empty($settings['api_key_saved']);
        $allowedCountries = implode(', ', (array) ($settings['allowed_country_codes'] ?? ['+254']));
        $blockedPrefixes = implode(', ', (array) ($settings['blocked_prefixes'] ?? []));
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        $showCallbackUrls = !empty($ctx['show_callback_urls']);
        $callbackToken = $showCallbackUrls ? (string) ($settings['callback_token'] ?? '') : '';
        $callbackUrl = $callbackToken !== '' ? $appUrl . '/api/webhooks/voice/africastalking.php?token=' . rawurlencode($callbackToken) : '';
        $eventsUrl = $callbackToken !== '' ? $appUrl . '/api/webhooks/voice/africastalking_events.php?token=' . rawurlencode($callbackToken) : '';
        $consentUrl = $callbackToken !== '' ? $appUrl . '/api/webhooks/voice/consent.php?token=' . rawurlencode($callbackToken) : '';
        $settingsMeta = (array) ($settings['settings'] ?? []);
        $automationPolicy = (new \CRM\Services\VoicePolicyDecisionService())->forConfig($settings);
        $recordingAcknowledgementValid = !empty($settings['recording_acknowledgement_valid']);
        $callbackIpAllowlist = implode(', ', (array) ($settingsMeta['callback_ip_allowlist'] ?? []));
        $businessHours = !empty($queue['business_hours_json']) ? json_decode((string) $queue['business_hours_json'], true) : [];
        if (!is_array($businessHours)) $businessHours = [];
        $businessDays = array_map('intval', (array) ($businessHours['days'] ?? [1, 2, 3, 4, 5]));
        $voiceSetupBaseUrl = 'workspace_skills.php?module=' . rawurlencode($moduleKey) . '&full_setup=1';
        $readinessChecks = (array) ($readiness['checks'] ?? []);
        $requiredChecks = array_values(array_filter($readinessChecks, static fn(array $check): bool => !empty($check['required'])));
        $requiredBlockers = array_values(array_filter($requiredChecks, static fn(array $check): bool => empty($check['ok'])));
        $requiredReady = count($requiredChecks) - count($requiredBlockers);
        $agentByUserId = [];
        foreach ($agents as $configuredAgent) {
            $agentByUserId[(int) ($configuredAgent['user_id'] ?? 0)] = $configuredAgent;
        }
        marketplaceRenderSetupTabs($moduleKey, $activeTab, $voiceSetupBaseUrl . '#setup', ['class' => 'vcc-setup-tabs']);
        ?>
        <link rel="stylesheet" href="assets/css/voice-call-center.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../public/assets/css/voice-call-center.css'); ?>">
        <form method="POST" class="marketplace-setup-form vcc-setup" data-vcc-setup-form data-vcc-can-manage="<?php echo $canManage ? '1' : '0'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
            <input type="hidden" name="voice_setup_tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <input type="hidden" name="voice_queue_id" value="<?php echo (int) ($queue['id'] ?? 0); ?>">
            <div class="marketplace-setup-tab-panels" data-marketplace-setup-panels>
                <section class="marketplace-setup-panel" data-marketplace-setup-panel="overview"<?php echo $panelAttr('overview'); ?>>
                    <div class="vcc-setup-hero">
                        <div><span class="vcc-kicker">Production v1 control plane</span><h3>Voice & Call Center</h3><p>Provider audio stays outside the CRM. PHP handles control, callbacks, state, and asynchronous post-call intelligence.</p></div>
                        <a class="btn-premium-secondary" href="call_center.php"><i class="fas fa-headset"></i> Open Call Center</a>
                    </div>
                    <div class="marketplace-ai-summary">
                        <span class="marketplace-status <?php echo !empty($readiness['ready']) ? 'is-installed' : 'is-needs-setup'; ?>"><?php echo !empty($readiness['ready']) ? 'Ready' : 'Needs setup'; ?></span>
                        <span class="marketplace-status <?php echo !empty($settings['enabled']) ? 'is-installed' : ''; ?>">Workspace voice: <?php echo !empty($settings['enabled']) ? 'On' : 'Off'; ?></span>
                        <span class="marketplace-status">Agents: <?php echo count($agents); ?></span>
                        <span class="marketplace-status">Provider: Africa's Talking</span>
                    </div>
                    <div class="vcc-readiness-summary" aria-label="Voice setup readiness">
                        <div class="vcc-readiness-heading">
                            <span><strong><?php echo $requiredReady; ?> of <?php echo count($requiredChecks); ?> required checks complete</strong><small><?php echo $requiredBlockers === [] ? 'Calling is ready for controlled use.' : 'Finish the items below before live calling.'; ?></small></span>
                            <span class="marketplace-status <?php echo $requiredBlockers === [] ? 'is-installed' : 'is-needs-setup'; ?>"><?php echo $requiredBlockers === [] ? 'Ready' : count($requiredBlockers) . ' remaining'; ?></span>
                        </div>
                        <progress max="<?php echo max(1, count($requiredChecks)); ?>" value="<?php echo $requiredReady; ?>"><?php echo $requiredReady; ?> of <?php echo count($requiredChecks); ?></progress>
                        <?php if ($requiredBlockers !== []): ?>
                            <ul class="vcc-readiness-blockers">
                                <?php foreach ($requiredBlockers as $check): ?><li><i class="fas fa-circle" aria-hidden="true"></i><?php echo htmlspecialchars((string) ($check['label'] ?? 'Setup item')); ?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <details class="vcc-readiness-details">
                            <summary>Review all readiness checks</summary>
                            <div class="vcc-readiness-list">
                                <?php foreach ($readinessChecks as $check): ?><span class="<?php echo !empty($check['ok']) ? 'is-ready' : ''; ?>"><i class="fas <?php echo !empty($check['ok']) ? 'fa-check-circle' : 'fa-circle'; ?>" aria-hidden="true"></i><?php echo htmlspecialchars((string) ($check['label'] ?? 'Setup item')); ?><?php echo empty($check['required']) ? ' · Optional until enabled' : ''; ?></span><?php endforeach; ?>
                            </div>
                        </details>
                    </div>
                    <nav class="vcc-setup-roadmap" aria-label="Voice setup steps">
                        <?php foreach ([
                            ['provider', '1', 'Connect provider', 'Credentials, number and callbacks'],
                            ['team', '2', 'Add agents', 'Verified phone or SIP endpoints'],
                            ['queues', '3', 'Set routing', 'Queues, hours and fallback'],
                            ['ai_consent', '4', 'Choose AI & consent', 'Recording, context and approvals'],
                            ['tests', '5', 'Run controlled tests', 'Verify before enabling live calls'],
                        ] as [$tab, $number, $label, $description]): ?>
                            <a href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, $tab, $voiceSetupBaseUrl . '#setup')); ?>"><b><?php echo $number; ?></b><span><strong><?php echo htmlspecialchars($label); ?></strong><small><?php echo htmlspecialchars($description); ?></small></span></a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="vcc-switch-grid">
                        <?php foreach ([
                            'voice_enabled' => ['Workspace voice', 'enabled', 'Master workspace switch. Turn this off to stop all new voice activity.'],
                            'voice_inbound_enabled' => ['Inbound routing', 'inbound_enabled', 'Accept new inbound calls through enabled queues and fallbacks.'],
                            'voice_outbound_enabled' => ['Outbound calls', 'outbound_enabled', 'Allow authorised agents to place policy-checked calls.'],
                        ] as $name => [$label, $key, $help]): ?>
                            <label class="marketplace-form-check vcc-switch"><input type="checkbox" name="<?php echo $name; ?>" <?php echo !empty($settings[$key]) ? 'checked' : ''; ?><?php echo $disabled; ?>> <span><strong><?php echo htmlspecialchars($label); ?></strong><small><?php echo htmlspecialchars($help); ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $disabled; ?>>Save voice controls</button></div>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="provider"<?php echo $panelAttr('provider'); ?>>
                    <h3>Africa's Talking Provider</h3>
                    <p class="vcc-setup-note">The client owns and funds this provider account directly. The API key is encrypted at rest and never returned to the browser.</p>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Account username <input type="text" name="voice_account_username" value="<?php echo htmlspecialchars((string) ($settings['account_username'] ?? '')); ?>" autocomplete="off"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">API key <input type="password" name="voice_api_key" value="" placeholder="<?php echo $apiKeySaved ? 'Saved key ending fingerprint ' . htmlspecialchars((string) ($settings['api_key_fingerprint'] ?? '')) : 'Africa\'s Talking API key'; ?>" autocomplete="new-password"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Virtual number <input type="tel" name="voice_virtual_number" value="<?php echo htmlspecialchars((string) ($settings['virtual_number'] ?? '')); ?>" placeholder="+254..."<?php echo $disabled; ?>></label>
                    </div>
                    <div class="vcc-copy-grid">
                        <?php $callbackPlaceholder = $showCallbackUrls ? 'Save provider settings to generate the callback URL.' : 'Hidden in Super Admin diagnostics; a workspace owner can copy this URL.'; ?>
                        <?php foreach ([
                            ['Provider callback URL', $callbackUrl],
                            ['Provider events URL', $eventsUrl],
                            ['Consent callback URL', $consentUrl],
                        ] as [$callbackLabel, $callbackValue]): ?>
                            <div class="marketplace-form-field">
                                <span><?php echo htmlspecialchars($callbackLabel); ?></span>
                                <div class="vcc-copy-control">
                                    <input type="text" readonly value="<?php echo htmlspecialchars($callbackValue ?: $callbackPlaceholder); ?>">
                                    <?php if ($callbackValue !== ''): ?><button type="button" class="btn-premium-secondary" data-vcc-copy aria-label="Copy <?php echo htmlspecialchars(strtolower($callbackLabel)); ?>"><i class="far fa-copy" aria-hidden="true"></i> Copy</button><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <p class="vcc-copy-status" data-vcc-copy-status role="status" aria-live="polite"></p>
                    </div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $disabled; ?>>Save provider settings</button></div>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="team"<?php echo $panelAttr('team'); ?>>
                    <h3>Voice Agents</h3>
                    <p class="vcc-setup-note">Agents use configured phone or Africa's Talking SIP endpoints. A completed controlled provider call records the endpoint's successful-call proof. Browser audio is intentionally outside production v1. <?php echo count($agents); ?> of <?php echo (int) (($readiness['entitlements']['agent_limit'] ?? 0)); ?> package agent slots are configured.</p>
                    <div class="vcc-agent-list">
                        <?php if ($agents === []): ?><p style="color:#64748b;">No voice agents are configured.</p><?php endif; ?>
                        <?php foreach ($agents as $agent): ?><div class="vcc-agent-row"><span><strong><?php echo htmlspecialchars((string) $agent['display_name']); ?></strong><small><?php echo htmlspecialchars((string) $agent['endpoint_masked']); ?> · <?php echo !empty($agent['last_successful_call_at']) ? 'Provider call proven' : 'Awaiting controlled call'; ?></small></span><span class="marketplace-status <?php echo !empty($agent['enabled']) && ($agent['presence_status'] ?? '') === 'available' ? 'is-installed' : ''; ?>"><?php echo !empty($agent['enabled']) ? htmlspecialchars(ucfirst((string) $agent['presence_status'])) : 'Disabled'; ?></span></div><?php endforeach; ?>
                    </div>
                    <h4>Add or update an agent</h4>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Workspace user <select name="voice_agent_user_id" data-vcc-agent-user<?php echo $agentDisabled; ?>><option value="">Choose a user</option><?php foreach ($users as $workspaceUser): ?><?php $configuredAgent = $agentByUserId[(int) $workspaceUser['id']] ?? []; ?><option value="<?php echo (int) $workspaceUser['id']; ?>" data-configured="<?php echo $configuredAgent !== [] ? '1' : '0'; ?>" data-display-name="<?php echo htmlspecialchars((string) ($configuredAgent['display_name'] ?? '')); ?>" data-endpoint-type="<?php echo htmlspecialchars((string) ($configuredAgent['endpoint_type'] ?? 'phone')); ?>" data-endpoint-masked="<?php echo htmlspecialchars((string) ($configuredAgent['endpoint_masked'] ?? '')); ?>" data-enabled="<?php echo $configuredAgent === [] || !empty($configuredAgent['enabled']) ? '1' : '0'; ?>"><?php echo htmlspecialchars((string) $workspaceUser['email']); ?><?php echo $configuredAgent !== [] ? ' · Configured' : ''; ?></option><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field">Display name <input type="text" name="voice_agent_display_name"<?php echo $agentDisabled; ?>></label>
                        <label class="marketplace-form-field">Endpoint type <select name="voice_agent_endpoint_type"<?php echo $agentDisabled; ?>><option value="phone">Phone</option><option value="sip">SIP client</option></select></label>
                        <label class="marketplace-form-field">Phone or SIP endpoint <input type="text" name="voice_agent_endpoint" placeholder="+254... or sip:user@domain"<?php echo $agentDisabled; ?>><small data-vcc-agent-endpoint-help>Required for a new agent.</small></label>
                    </div>
                    <label class="marketplace-form-check vcc-agent-enabled"><input type="checkbox" name="voice_agent_enabled" checked<?php echo $agentDisabled; ?>> Keep this agent enabled for routing and calls.</label>
                    <p class="vcc-setup-note">Select a configured user to update them. Leave the endpoint blank to keep the saved endpoint; the full value is never returned to the browser.</p>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $agentDisabled; ?>>Save team setup</button></div>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="queues"<?php echo $panelAttr('queues'); ?>>
                    <div class="vcc-queue-heading"><div><h3>Queues, Hours & Fallback</h3><p class="vcc-setup-note">Configure named queues without changing the CRM's established setup pattern.</p></div><a class="btn-premium-secondary" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, 'queues', $voiceSetupBaseUrl) . '&voice_queue_id=new#setup'); ?>">Add queue</a></div>
                    <nav class="vcc-queue-tabs" aria-label="Configured voice queues">
                        <?php foreach ($queues as $queueOption): ?>
                            <a class="<?php echo (int) ($queueOption['id'] ?? 0) === (int) ($queue['id'] ?? 0) ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, 'queues', $voiceSetupBaseUrl) . '&voice_queue_id=' . (int) $queueOption['id'] . '#setup'); ?>">
                                <?php echo htmlspecialchars((string) $queueOption['name']); ?><?php echo !empty($queueOption['is_default']) ? ' · Default' : ''; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($queue === []): ?><span class="is-active">New queue</span><?php endif; ?>
                    </nav>
                    <?php $fallbackAction = (string) ($queue['fallback_action'] ?? 'reject'); ?>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Queue name <input type="text" name="voice_queue_name" value="<?php echo htmlspecialchars((string) ($queue['name'] ?? 'New queue')); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Maximum wait (seconds) <input type="number" min="15" max="600" name="voice_queue_max_wait_seconds" value="<?php echo (int) ($queue['max_wait_seconds'] ?? 120); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Fallback action <select name="voice_queue_fallback_action" data-vcc-fallback-action<?php echo $disabled; ?>><?php foreach (['reject' => 'Reject politely', 'verified_number' => 'Verified number', 'alternate_queue' => 'Alternate queue'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $fallbackAction === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field" data-vcc-fallback-field="verified_number"<?php echo $fallbackAction === 'verified_number' ? '' : ' hidden'; ?>>Fallback destination <input type="tel" name="voice_queue_fallback_destination" value="<?php echo htmlspecialchars((string) ($queue['fallback_destination'] ?? '')); ?>" placeholder="Verified international-format number"<?php echo $fallbackAction === 'verified_number' ? $disabled : ' disabled'; ?>></label>
                        <label class="marketplace-form-field" data-vcc-fallback-field="alternate_queue"<?php echo $fallbackAction === 'alternate_queue' ? '' : ' hidden'; ?>>Alternate queue <select name="voice_queue_fallback_queue_id"<?php echo $fallbackAction === 'alternate_queue' ? $disabled : ' disabled'; ?>><option value="0">Choose an alternate queue</option><?php foreach ($queues as $fallbackQueue): ?><?php if ((int) ($fallbackQueue['id'] ?? 0) !== (int) ($queue['id'] ?? 0) && !empty($fallbackQueue['enabled'])): ?><option value="<?php echo (int) $fallbackQueue['id']; ?>" <?php echo (int) ($queue['fallback_queue_id'] ?? 0) === (int) $fallbackQueue['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $fallbackQueue['name']); ?></option><?php endif; ?><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field">Timezone <input type="text" list="vcc-timezone-options" name="voice_business_timezone" value="<?php echo htmlspecialchars((string) ($businessHours['timezone'] ?? 'Africa/Nairobi')); ?>" placeholder="Africa/Nairobi"<?php echo $disabled; ?>><small>Use an IANA timezone so business hours follow the queue's local time.</small></label>
                        <datalist id="vcc-timezone-options"><?php foreach (\DateTimeZone::listIdentifiers() as $timezone): ?><option value="<?php echo htmlspecialchars($timezone); ?>"></option><?php endforeach; ?></datalist>
                        <div class="marketplace-form-field vcc-wide"><span>Open weekdays</span><input type="hidden" name="voice_business_days" data-vcc-business-days value="<?php echo htmlspecialchars(implode(',', $businessDays)); ?>"><div class="vcc-weekday-picker" role="group" aria-label="Open weekdays"><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $dayLabel): ?><button type="button" data-vcc-weekday="<?php echo $day; ?>" aria-pressed="<?php echo in_array($day, $businessDays, true) ? 'true' : 'false'; ?>" class="<?php echo in_array($day, $businessDays, true) ? 'is-active' : ''; ?>"<?php echo $disabled; ?>><?php echo $dayLabel; ?></button><?php endforeach; ?></div></div>
                        <label class="marketplace-form-field">Open time <input type="time" name="voice_business_start" value="<?php echo htmlspecialchars((string) ($businessHours['start'] ?? '08:00')); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Close time <input type="time" name="voice_business_end" value="<?php echo htmlspecialchars((string) ($businessHours['end'] ?? '17:00')); ?>"<?php echo $disabled; ?>></label>
                    </div>
                    <div class="vcc-switch-grid">
                        <label class="marketplace-form-check vcc-switch"><input type="checkbox" name="voice_queue_enabled" <?php echo $queue === [] || !empty($queue['enabled']) ? 'checked' : ''; ?><?php echo $disabled; ?>> <span><strong>Queue enabled</strong><small>Disabled queues are not used for new inbound routing.</small></span></label>
                        <label class="marketplace-form-check vcc-switch"><input type="checkbox" name="voice_queue_is_default" <?php echo $queue === [] && $queues === [] || !empty($queue['is_default']) ? 'checked' : ''; ?><?php echo $disabled; ?>> <span><strong>Default inbound queue</strong><small>New inbound calls start here before alternate fallback routing.</small></span></label>
                    </div>
                    <label class="marketplace-form-check"><input type="checkbox" name="voice_business_hours_enabled" <?php echo !empty($businessHours['enabled']) ? 'checked' : ''; ?><?php echo $disabled; ?>> Route to agents only during these business hours.</label>
                    <h4>Queue members and priority</h4>
                    <input type="hidden" name="voice_queue_members_present" value="1">
                    <div class="vcc-agent-list">
                        <?php if ($agents === []): ?><p style="color:#64748b;">Add a voice agent before assigning queue members.</p><?php endif; ?>
                        <?php foreach ($agents as $agent): ?>
                            <label class="vcc-agent-row vcc-queue-member-row">
                                <span><strong><?php echo htmlspecialchars((string) $agent['display_name']); ?></strong><small><?php echo htmlspecialchars((string) $agent['endpoint_masked']); ?></small></span>
                                <span><input type="checkbox" name="voice_queue_agent_ids[]" value="<?php echo (int) $agent['id']; ?>" <?php echo in_array((int) $agent['id'], $queueMemberIds, true) ? 'checked' : ''; ?><?php echo $disabled; ?>> Route calls</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="vcc-setup-note">Checked agents are ordered as shown. Routing uses member priority, then least-recent assignment. A database lease prevents two calls from selecting the same available agent.</p>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $disabled; ?>>Save queue</button></div>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="ai_consent"<?php echo $panelAttr('ai_consent'); ?>>
                    <h3>AI, Consent & Retention</h3>
                    <div class="vcc-safety-banner"><i class="fas fa-lock"></i><div><strong>Uses the OpenAI key already saved in Settings.</strong><span>No second AI key is stored by this plugin. Recording remains blocked until consent is complete.</span></div></div>
                    <p class="vcc-setup-note">The CRM provides configurable controls rather than country-specific legal conclusions. Your organisation chooses the appropriate notice or consent method for its agents, call participants, locations, and use case.</p>
                    <div class="vcc-switch-grid vcc-ai-switches">
                        <?php foreach ([
                            'voice_recording_enabled' => ['Recording', 'recording_enabled', 'Requires a current workspace-owner acknowledgement, consent method and notice.'],
                            'voice_transcription_enabled' => ['Transcription', 'transcription_enabled', 'Runs after a consented recording is ready and uses the workspace AI key.'],
                            'voice_ai_application_enabled' => ['CRM context enrichment', 'ai_application_enabled', 'Creates evidence-backed summaries and reviewable CRM suggestions after transcription.'],
                            'voice_customer_voice_enabled' => ['Customer Voice', 'customer_voice_enabled', 'Builds anonymised, multi-contact marketing themes for human review.'],
                        ] as $name => [$label, $key, $help]): ?>
                            <label class="marketplace-form-check vcc-switch"><input type="checkbox" name="<?php echo $name; ?>" <?php echo !empty($settings[$key]) ? 'checked' : ''; ?><?php echo $disabled; ?>> <span><strong><?php echo htmlspecialchars($label); ?></strong><small><?php echo htmlspecialchars($help); ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Consent mode <select name="voice_consent_mode"<?php echo $disabled; ?>><option value="explicit_keypress" <?php echo (string) ($settings['consent_mode'] ?? 'explicit_keypress') === 'explicit_keypress' ? 'selected' : ''; ?>>Explicit keypad confirmation</option><option value="notice_only" <?php echo (string) ($settings['consent_mode'] ?? '') === 'notice_only' ? 'selected' : ''; ?>>Notice only (acknowledgement required)</option><option value="disabled" <?php echo (string) ($settings['consent_mode'] ?? '') === 'disabled' ? 'selected' : ''; ?>>No recording consent flow</option></select></label>
                        <label class="marketplace-form-field">Transcription model <input type="text" name="voice_transcription_model" value="<?php echo htmlspecialchars((string) ($settings['transcription_model'] ?? 'gpt-4o-mini-transcribe')); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field vcc-wide">Consent notice <textarea name="voice_consent_notice" rows="4"<?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['consent_notice'] ?? '')); ?></textarea></label>
                        <label class="marketplace-form-field">Recording retention days <input type="number" min="1" max="3650" name="voice_recording_retention_days" value="<?php echo (int) ($settings['recording_retention_days'] ?? 30); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Transcript retention days <input type="number" min="1" max="3650" name="voice_transcript_retention_days" value="<?php echo (int) ($settings['transcript_retention_days'] ?? 180); ?>"<?php echo $disabled; ?>></label>
                    </div>
                    <div class="vcc-policy-acknowledgement <?php echo $recordingAcknowledgementValid ? 'is-current' : ''; ?>">
                        <label class="marketplace-form-check"><input type="checkbox" name="voice_compliance_acknowledged" data-vcc-recording-ack-current="<?php echo $recordingAcknowledgementValid ? '1' : '0'; ?>" <?php echo $recordingAcknowledgementValid ? 'checked' : ''; ?><?php echo $disabled; ?>> <span><strong>Workspace-owner recording acknowledgement</strong><small>I confirm that my organisation has assessed the requirements applicable to our organisation, agents, call participants, locations, and intended uses. I am responsible for configuring an appropriate consent or notice process and automation policy.</small></span></label>
                        <p class="vcc-setup-note" data-vcc-recording-ack-status><?php echo $recordingAcknowledgementValid ? 'The acknowledgement matches the current recording and automation policy.' : 'Recording cannot be enabled until a workspace owner accepts this acknowledgement. A phone prefix is not treated as proof of a participant\'s physical location.'; ?></p>
                    </div>

                    <h4>Workspace automation policy</h4>
                    <p class="vcc-setup-note">Control each CRM effect independently. “Suggest” displays information only. “Approval required” applies only supported actions after review. “Automatic safe” is limited to additive, high-confidence contact context and tasks.</p>
                    <div class="marketplace-form-grid vcc-automation-grid">
                        <label class="marketplace-form-field">Call summary <select name="voice_automation_call_summary"<?php echo $disabled; ?>><option value="off" <?php echo $automationPolicy['call_summary'] === 'off' ? 'selected' : ''; ?>>Off</option><option value="automatic" <?php echo $automationPolicy['call_summary'] === 'automatic' ? 'selected' : ''; ?>>Automatic</option></select></label>
                        <label class="marketplace-form-field">Contact context <select name="voice_automation_contact_context"<?php echo $disabled; ?>><?php foreach (['off' => 'Off', 'suggest' => 'Suggest only', 'approval_required' => 'Approval required', 'automatic_safe' => 'Automatic safe'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $automationPolicy['contact_context'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field">Follow-up tasks <select name="voice_automation_follow_up_tasks"<?php echo $disabled; ?>><?php foreach (['off' => 'Off', 'suggest' => 'Suggest only', 'approval_required' => 'Approval required', 'automatic_safe' => 'Automatic safe'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $automationPolicy['follow_up_tasks'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field">Deal-stage suggestions <select name="voice_automation_deal_stage"<?php echo $disabled; ?>><?php foreach (['off' => 'Off', 'suggest' => 'Suggest only', 'approval_required' => 'Review recommendation (no movement)'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $automationPolicy['deal_stage'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><small>Production v1 never moves a deal automatically or after review.</small></label>
                        <label class="marketplace-form-field">Customer Voice <select name="voice_automation_customer_voice"<?php echo $disabled; ?>><?php foreach (['off' => 'Off', 'suggest' => 'Generate suggestions', 'approval_required' => 'Review aggregate insight'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $automationPolicy['customer_voice'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                        <label class="marketplace-form-field">Follow-up messages <select name="voice_automation_follow_up_messages"<?php echo $disabled; ?>><?php foreach (['off' => 'Off', 'suggest' => 'Draft suggestion', 'approval_required' => 'Review draft (not sent)'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $automationPolicy['follow_up_messages'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><small>Production v1 can prepare a draft but never sends it.</small></label>
                        <label class="marketplace-form-field">Automatic-safe confidence <input type="number" min="50" max="100" name="voice_automation_minimum_confidence" value="<?php echo (int) round((float) $automationPolicy['minimum_confidence'] * 100); ?>"<?php echo $disabled; ?>><small>Percentage required before additive automatic actions run.</small></label>
                    </div>
                    <p class="vcc-setup-note">Direct contact-field replacement, automatic deal movement, unattended outbound calling, and automatic campaign publication remain unavailable in production v1.</p>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $disabled; ?>>Save AI & consent</button></div>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="tests"<?php echo $panelAttr('tests'); ?>>
                    <h3>Controlled Tests</h3>
                    <div class="vcc-test-list"><div><strong>1. Provider authentication</strong><span>Authenticates the username and encrypted API key against Africa's Talking Application Data. A controlled call separately proves the assigned number.</span></div><div><strong>2. Callback receipt</strong><span>Appears in Activity after the provider reaches the opaque callback URL.</span></div><div><strong>3. Controlled call</strong><span>Use the Call Center only after the platform flag and workspace switches are enabled.</span></div><div><strong>4. Transcript proof</strong><span>Requires consent, recording readiness, worker heartbeat, and the Settings OpenAI key.</span></div></div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="test_voice_call_center_provider"<?php echo $disabled; ?>>Verify provider settings</button><a class="btn-premium-secondary" href="call_center.php">Open controlled call test</a></div>
                    <?php if ($deadLetters !== []): ?>
                        <h4>Transcription jobs requiring review</h4>
                        <div class="vcc-dead-letter-list">
                            <?php foreach ($deadLetters as $job): ?>
                                <div><span><strong>Call #<?php echo (int) $job['call_id']; ?></strong><small><?php echo htmlspecialchars((string) ($job['last_error'] ?? 'Worker failed without a stored reason.')); ?></small></span><button type="submit" class="btn-premium-secondary" name="skill_action" value="retry_voice_transcription_job:<?php echo (int) $job['id']; ?>"<?php echo $disabled; ?>>Retry safely</button></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="usage"<?php echo $panelAttr('usage'); ?>>
                    <h3>Estimated Usage</h3>
                    <div class="vcc-usage-grid"><div><strong><?php echo (int) ($voiceMetrics['active_calls'] ?? 0); ?>/<?php echo (int) ($voiceMetrics['concurrency_limit'] ?? 0); ?></strong><span>Active concurrency</span></div><div><strong><?php echo (int) ($voiceMetrics['peak_concurrency_today'] ?? 0); ?></strong><span>Peak concurrency today</span></div><div><strong><?php echo (int) ($usage['calls'] ?? 0); ?></strong><span>Calls this month</span></div><div><strong><?php echo (int) ($usage['minutes'] ?? 0); ?></strong><span>Estimated billable minutes</span></div><div><strong>KES <?php echo number_format((float) ($usage['provider_cost'] ?? 0), 2); ?></strong><span>Estimated provider usage</span></div><div><strong><?php echo number_format((float) ($voiceMetrics['transcript_success_percent'] ?? 0), 1); ?>%</strong><span>30-day transcript success</span></div></div>
                    <p class="vcc-setup-note">Estimates are operational guidance, not an invoice. Africa's Talking and OpenAI billing remain authoritative and are paid directly by the client.</p>
                    <?php if ($voiceAlerts === []): ?><div class="vcc-health-ok"><i class="fas fa-check-circle"></i> No voice alert threshold is currently breached.</div><?php else: ?><div class="vcc-alert-list"><?php foreach ($voiceAlerts as $alert): ?><div class="is-<?php echo htmlspecialchars((string) ($alert['severity'] ?? 'warning')); ?>"><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($alert['key'] ?? 'alert')))); ?></strong><span><?php echo htmlspecialchars((string) ($alert['message'] ?? '')); ?></span></div><?php endforeach; ?></div><?php endif; ?>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                    <h3>Voice Activity</h3><?php marketplaceRenderSetupActivity($events); ?>
                </section>

                <section class="marketplace-setup-panel" data-marketplace-setup-panel="advanced"<?php echo $panelAttr('advanced'); ?>>
                    <h3>Policy Limits & Diagnostics</h3>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Allowed country prefixes <input type="text" name="voice_allowed_country_codes" value="<?php echo htmlspecialchars($allowedCountries); ?>" placeholder="+254"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Blocked prefixes <input type="text" name="voice_blocked_prefixes" value="<?php echo htmlspecialchars($blockedPrefixes); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Max call duration (seconds) <input type="number" min="60" max="14400" name="voice_max_call_duration_seconds" value="<?php echo (int) ($settings['max_call_duration_seconds'] ?? 3600); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Hourly call ceiling <input type="number" min="1" name="voice_hourly_call_limit" value="<?php echo (int) ($settings['hourly_call_limit'] ?? 60); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Daily minute ceiling <input type="number" min="1" name="voice_daily_minute_limit" value="<?php echo (int) ($settings['daily_minute_limit'] ?? 1000); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Verified fallback number <input type="tel" name="voice_fallback_number" value="<?php echo htmlspecialchars((string) ($settingsMeta['fallback_number'] ?? '')); ?>"<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field vcc-wide">Provider callback source IPs <input type="text" name="voice_callback_ip_allowlist" value="<?php echo htmlspecialchars($callbackIpAllowlist); ?>" placeholder="Exact IPv4 or IPv6 addresses, comma separated"<?php echo $disabled; ?>><small>Optional layered control. Leave blank until Africa's Talking confirms current callback origins for this account.</small></label>
                    </div>
                    <div class="vcc-diagnostic-grid"><span>Workspace: <?php echo (int) ($ctx['workspace_id'] ?? 0); ?></span><span>Provider status: <?php echo htmlspecialchars((string) ($settings['status'] ?? 'not_configured')); ?></span><span>Last verified: <?php echo htmlspecialchars((string) ($settings['last_verified_at'] ?? 'Never')); ?></span><span>Provider balance: <?php echo htmlspecialchars((string) (($settingsMeta['provider_verification']['balance'] ?? '') ?: 'Unknown')); ?></span><span>Last callback: <?php echo htmlspecialchars((string) ($settings['last_callback_at'] ?? 'Never')); ?></span><span>Worker age: <?php echo (int) (($observability['worker']['age_seconds'] ?? 0)); ?>s</span><span>Oldest transcription backlog: <?php echo (int) ($voiceMetrics['oldest_backlog_seconds'] ?? 0); ?>s</span><span>Callback rejections (1h): <?php echo (int) ($voiceMetrics['callback_rejections_1h'] ?? 0); ?></span><span>Expired evidence: <?php echo (int) ($voiceMetrics['expired_recordings'] ?? 0) + (int) ($voiceMetrics['expired_transcripts'] ?? 0); ?></span></div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_voice_call_center_setup"<?php echo $disabled; ?>>Save advanced policy</button></div>
                </section>
            </div>
        </form>
        <?php if (!$canManage): ?><p class="vcc-readonly-note">Your access profile can review voice setup. Owner or admin permission is required to change it.</p><?php endif; ?>
        <?php
    }
}

if (!function_exists('marketplaceNormalizeSetupTab')) {
    function marketplaceNormalizeSetupTab(string $moduleKey, string $activeTab): string
    {
        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP && $activeTab === 'whatsapp') {
            $activeTab = 'manual';
        }
        $tabs = marketplaceSetupTabsForModule($moduleKey);
        return isset($tabs[$activeTab]) ? $activeTab : (string) (array_key_first($tabs) ?: 'setup');
    }
}

if (!function_exists('marketplaceRenderSetupTabs')) {
    function marketplaceRenderSetupTabs(string $moduleKey, string $activeTab, string $baseUrl = '', array $options = []): void
    {
        $tabs = marketplaceSetupTabsForModule($moduleKey);
        $additionalClass = trim((string) preg_replace('/[^a-zA-Z0-9 _-]/', '', (string) ($options['class'] ?? '')));
        $excludeTabs = array_fill_keys(array_map('strval', (array) ($options['exclude_tabs'] ?? [])), true);
        if ($excludeTabs !== []) {
            $tabs = array_diff_key($tabs, $excludeTabs);
        }
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, $activeTab);
        if (!isset($tabs[$activeTab])) {
            $activeTab = (string) (array_key_first($tabs) ?: 'setup');
        }
        ?>
        <nav class="marketplace-setup-tabs <?php echo $moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE ? 'marketplace-finance-setup-tabs' : ''; ?> <?php echo htmlspecialchars($additionalClass); ?>" aria-label="Setup sections" data-marketplace-setup-tabs>
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, (string) $tabKey, $baseUrl)); ?>"
                   class="marketplace-setup-tab <?php echo $tabKey === $activeTab ? 'is-active' : ''; ?>"
                   data-marketplace-setup-tab="<?php echo htmlspecialchars((string) $tabKey); ?>"
                   aria-selected="<?php echo $tabKey === $activeTab ? 'true' : 'false'; ?>">
                    <?php echo htmlspecialchars((string) $tabLabel); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}

if (!function_exists('marketplaceRenderReadinessChecks')) {
    function marketplaceRenderReadinessChecks(array $checks): void
    {
        if ($checks === []) {
            echo '<p style="color:#64748b;">No readiness checks are available yet.</p>';
            return;
        }
        echo '<div class="marketplace-overview-sections" style="margin-top:.75rem;">';
        foreach ($checks as $check) {
            $ok = !empty($check['ok']);
            $required = !array_key_exists('required', $check) || !empty($check['required']);
            $status = $ok ? 'Ready' : ($required ? 'Needs Setup' : 'Optional');
            echo '<div class="marketplace-overview-block">';
            echo '<h4>' . $status . ': ' . htmlspecialchars((string) ($check['label'] ?? 'Check')) . '</h4>';
            echo '<p>' . htmlspecialchars((string) ($check['detail'] ?? '')) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('marketplaceRenderSetupActivity')) {
    function marketplaceRenderSetupActivity(array $events): void
    {
        if ($events === []) {
            echo '<p style="color:#64748b;">No Marketplace setup events have been recorded for this module yet.</p>';
            return;
        }
        echo '<div class="marketplace-overview-sections" style="margin-top:.75rem;">';
        foreach ($events as $event) {
            $label = (string) ($event['label'] ?? '');
            if ($label === '') {
                $metadata = json_decode((string) ($event['metadata_json'] ?? '{}'), true);
                $label = is_array($metadata) ? (string) ($metadata['label'] ?? '') : '';
            }
            $label = $label !== '' ? $label : ucwords(str_replace('_', ' ', (string) ($event['event_type'] ?? 'setup event')));
            echo '<div class="marketplace-overview-block">';
            echo '<h4>' . htmlspecialchars($label) . '</h4>';
            echo '<p>' . htmlspecialchars((string) ($event['event_type'] ?? 'event')) . ' - ' . htmlspecialchars((string) ($event['created_at'] ?? '')) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('marketplaceRenderCalendarMeetingsSetup')) {
    function marketplaceRenderCalendarMeetingsSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'overview'));
        $csrf = (string) ($ctx['csrf'] ?? '');
        $installed = !empty($ctx['installed']);
        $canManage = !empty($ctx['can_manage']);
        $canManageCalendar = !empty($ctx['can_manage_calendar']);
        $canConnectCalendar = !empty($ctx['can_connect_calendar']) || $canManageCalendar;
        $canManageAvailability = !empty($ctx['can_manage_availability']) || $canManage;
        $canManageBot = !empty($ctx['can_manage_bot']);
        $canManageNotes = !empty($ctx['can_manage_notes']);
        $currentUserId = (int) ($ctx['user_id'] ?? 0);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $bot = (array) ($ctx['bot_config'] ?? []);
        $notes = (array) ($ctx['note_config'] ?? []);
        $integrations = (array) ($ctx['calendar_integrations'] ?? []);
        $syncHealth = (array) ($ctx['sync_health'] ?? []);
        $bookingProfile = (array) ($ctx['booking_profile'] ?? []);
        $workspaceUsers = (array) ($ctx['workspace_users'] ?? []);
        $profileHosts = (array) ($ctx['profile_hosts'] ?? []);
        $availabilityWindows = (array) ($ctx['availability_windows'] ?? []);
        $blockedTimes = (array) ($ctx['blocked_times'] ?? []);
        $bookingRequests = (array) ($ctx['booking_requests'] ?? []);
        $workspaceSlug = (string) ($ctx['workspace_slug'] ?? '');
        $botRuns = (array) ($ctx['bot_runs'] ?? []);
        $noteRuns = (array) ($ctx['note_runs'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $basePath = function_exists('getBasePath') ? (string) getBasePath() : '';
        $apiBase = rtrim(str_replace('/public', '', $basePath), '/') ?: '';
        $calendarSetupReturnTo = rtrim($basePath, '/') . '/workspace_skills.php?module=' . rawurlencode($moduleKey) . '&setup_tab=calendar#setup';
        $calendarOauthReturnTo = rawurlencode($calendarSetupReturnTo);
        $googleCalendarClientId = trim((string) ($_ENV['GOOGLE_CALENDAR_CLIENT_ID'] ?? ''));
        $googleCalendarClientSecret = trim((string) ($_ENV['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? ''));
        $outlookCalendarClientId = trim((string) ($_ENV['MICROSOFT_CALENDAR_CLIENT_ID'] ?? ''));
        $outlookCalendarClientSecret = trim((string) ($_ENV['MICROSOFT_CALENDAR_CLIENT_SECRET'] ?? ''));
        $googleCalendarConfigured = $googleCalendarClientId !== '' && $googleCalendarClientSecret !== '';
        $outlookCalendarConfigured = $outlookCalendarClientId !== '' && $outlookCalendarClientSecret !== '';
        $calendarSuccessKey = trim((string) ($_GET['calendar_connected'] ?? $_GET['calendar_updated'] ?? $_GET['success'] ?? ''));
        $calendarErrorMessage = trim((string) ($_GET['calendar_error'] ?? $_GET['error'] ?? ''));
        $calendarSuccessMessages = [
            'google_connected' => 'Google Calendar connected successfully.',
            'google' => 'Google Calendar connected successfully.',
            'outlook_connected' => 'Outlook Calendar connected successfully.',
            'outlook' => 'Outlook Calendar connected successfully.',
            'updated' => 'Calendar connection settings updated.',
            '1' => 'Calendar connection settings updated.',
            'disconnected' => 'Calendar disconnected.',
        ];
        $calendarSuccessMessage = $calendarSuccessMessages[$calendarSuccessKey] ?? $calendarSuccessKey;
        $calendarSyncService = new \CRM\Services\CalendarSyncService();
        $disabled = (!$installed || !$canManage) ? 'disabled' : '';
        $availabilityDisabled = (!$installed || !$canManageAvailability) ? 'disabled' : '';
        $botDisabled = (!$installed || !$canManageBot) ? 'disabled' : '';
        $notesDisabled = (!$installed || !$canManageNotes) ? 'disabled' : '';
        $calendarDisabled = (!$installed || !$canManageCalendar) ? 'disabled' : '';
        $selectedIntegration = (string) ($bot['google_calendar_integration_id'] ?? '');
        $allowedFields = array_map('strval', (array) ($notes['allowed_contact_fields'] ?? []));
        $secretValue = static function (array $settings, string $field): string {
            $value = (string) ($settings[$field] ?? '');
            return $value !== '' ? 'Saved secret' : 'Not saved';
        };
        $chip = static function (string $label, bool $ok): string {
            return '<span class="cm-chip ' . ($ok ? 'cm-chip-success' : 'cm-chip-warning') . '">' . htmlspecialchars($label) . '</span>';
        };
        $formatList = static function ($value, array $fallback = []): string {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $items = array_values(array_filter(array_map('strval', is_array($value) ? $value : [])));
            if ($items === []) {
                $items = $fallback;
            }
            return implode(', ', $items);
        };
        $weekday = static function (int $day): string {
            return [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][$day] ?? 'Day ' . $day;
        };
        $fullWeekday = static function (int $day): string {
            return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$day] ?? 'Day ' . $day;
        };
        $decodeList = static function ($value, array $fallback = []): array {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value);
            }
            $items = array_values(array_filter(is_array($value) ? $value : [], static fn($item): bool => trim((string) $item) !== ''));
            return $items !== [] ? $items : $fallback;
        };
        $meetingFormatLabels = [
            'phone_call' => 'Phone Call',
            'zoom' => 'Zoom',
            'google_meet' => 'Google Meet',
            'in_person' => 'In Person',
        ];
        $profileDurations = array_map('intval', $decodeList($bookingProfile['allowed_durations_json'] ?? [], [15, 30, 45, 60]));
        $profileDurations = $profileDurations !== [] ? $profileDurations : [15, 30, 45, 60];
        $profileFormats = array_map('strval', $decodeList($bookingProfile['allowed_meeting_formats_json'] ?? [], array_keys($meetingFormatLabels)));
        $profileOwnerUserId = (int) ($bookingProfile['owner_user_id'] ?? 0);
        if ($profileOwnerUserId <= 0 && $workspaceUsers !== []) {
            $profileOwnerUserId = (int) ($workspaceUsers[0]['id'] ?? 0);
        }
        $profileSourceMode = (string) ($bookingProfile['availability_source_mode'] ?? 'owner_all');
        if (!in_array($profileSourceMode, ['owner_all', 'selected_integrations', 'none'], true)) {
            $profileSourceMode = 'owner_all';
        }
        $profileBookingMode = (string) ($bookingProfile['booking_mode'] ?? 'single_host');
        if (!in_array($profileBookingMode, ['single_host', 'round_robin'], true)) {
            $profileBookingMode = 'single_host';
        }
        $effectiveProfileSourceMode = $profileSourceMode;
        $roundRobinSourceWarning = '';
        if ($profileBookingMode === 'round_robin' && $profileSourceMode === 'selected_integrations') {
            $effectiveProfileSourceMode = 'owner_all';
            $roundRobinSourceWarning = 'Selected calendars are single-host only.';
        }
        $selectedAvailabilityIds = array_map('intval', $decodeList($bookingProfile['availability_integration_ids_json'] ?? [], []));
        $selectedAvailabilityIds = array_values(array_filter($selectedAvailabilityIds, static fn(int $id): bool => $id > 0));
        $workspaceUserLabels = [];
        foreach ($workspaceUsers as $workspaceUser) {
            $workspaceUserId = (int) ($workspaceUser['id'] ?? 0);
            if ($workspaceUserId > 0) {
                $workspaceUserLabels[$workspaceUserId] = (string) ($workspaceUser['email'] ?? $workspaceUser['label'] ?? ('User #' . $workspaceUserId));
            }
        }
        $hostIntegrations = array_values(array_filter($integrations, static fn(array $integration): bool => (int) ($integration['user_id'] ?? 0) === $profileOwnerUserId));
        $activeAvailabilityIntegrations = array_values(array_filter($hostIntegrations, static function (array $integration) use ($effectiveProfileSourceMode, $selectedAvailabilityIds): bool {
            if ($effectiveProfileSourceMode === 'none' || empty($integration['availability_enabled'])) {
                return false;
            }
            if ($effectiveProfileSourceMode === 'selected_integrations') {
                return in_array((int) ($integration['id'] ?? 0), $selectedAvailabilityIds, true);
            }
            return true;
        }));
        $hostCalendarHasError = count(array_filter($hostIntegrations, static fn(array $integration): bool => trim((string) ($integration['availability_last_error'] ?? '')) !== '')) > 0;
        $hostCalendarStatus = $profileBookingMode === 'round_robin'
            ? ($effectiveProfileSourceMode === 'none'
                ? 'External calendars off for team availability.'
                : 'Using enabled host calendars.')
            : ($effectiveProfileSourceMode === 'none'
                ? 'External calendar off.'
                : ($hostIntegrations === []
                    ? 'Host calendar not connected.'
                    : ($activeAvailabilityIntegrations === []
                        ? 'No host calendar selected.'
                        : ($hostCalendarHasError ? 'Busy refresh needs attention.' : count($activeAvailabilityIntegrations) . ' calendar source(s) active.'))));
        $enabledProfileHosts = array_values(array_filter($profileHosts, static fn(array $host): bool => !empty($host['is_enabled'])));
        $teamReadyHosts = array_values(array_filter($enabledProfileHosts, static fn(array $host): bool => (int) ($host['availability_enabled_count'] ?? 0) > 0 && (string) ($host['busy_cache_status'] ?? '') !== 'error'));
        $teamHostStatus = $profileBookingMode !== 'round_robin'
            ? 'Single host mode is active.'
            : ($enabledProfileHosts === []
                ? 'No round-robin hosts selected.'
                : count($enabledProfileHosts) . ' host(s) selected, ' . count($teamReadyHosts) . ' with calendar availability ready.');
        $windowsByDay = [];
        foreach ($availabilityWindows as $window) {
            $day = (int) ($window['day_of_week'] ?? 0);
            if ($day >= 1 && $day <= 7) {
                $windowsByDay[$day][] = $window;
            }
        }
        $settingsEndpoint = function_exists('apiUrl') ? apiUrl('meeting_booking/settings.php') : 'api/meeting_booking/settings.php';
        $blockedTimesJson = htmlspecialchars(json_encode(array_values($blockedTimes), JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES, 'UTF-8');
        $bookingStatusCounts = [];
        foreach ($bookingRequests as $booking) {
            $status = (string) ($booking['status'] ?? 'pending');
            $bookingStatusCounts[$status] = ($bookingStatusCounts[$status] ?? 0) + 1;
        }
        $profileSlug = (string) ($bookingProfile['slug'] ?? '');
        $publicBookingUrl = $workspaceSlug !== '' && $profileSlug !== ''
            ? 'meeting_schedule.php?workspace=' . rawurlencode($workspaceSlug) . '&profile=' . rawurlencode($profileSlug)
            : '';
        $calendarReady = $integrations !== [];
        $hasEnabledAvailability = count(array_filter($availabilityWindows, static fn(array $row): bool => !empty($row['is_enabled']))) > 0;
        $bookingHostReady = $profileBookingMode === 'round_robin' ? $enabledProfileHosts !== [] : $profileOwnerUserId > 0;
        $bookingReady = $bookingProfile !== [] && !empty($bookingProfile['public_enabled']) && $hasEnabledAvailability && $bookingHostReady;
        $bookingReadinessMessage = $bookingReady
            ? 'Public profile, availability, and host routing are configured.'
            : ($profileBookingMode === 'round_robin' && $enabledProfileHosts === []
                ? 'Select at least one round-robin host before publishing.'
                : 'Create a booking profile and availability windows.');
        $botReady = !empty($bot['enabled']) && $selectedIntegration !== '';
        $notesReady = !empty($notes['enabled']);
        $syncStatus = (string) ($syncHealth['status'] ?? ($integrations === [] ? 'not_connected' : 'unknown'));
        $workerReady = $syncStatus === 'healthy';
        $recentAudit = (array) ($syncHealth['recent_audit'] ?? []);
        $syncIntegrations = (array) ($syncHealth['integrations'] ?? $integrations);
        $calendarIntegrationDetailsById = [];
        foreach ($integrations as $integrationRow) {
            $integrationRowId = (int) ($integrationRow['id'] ?? 0);
            if ($integrationRowId > 0) {
                $calendarIntegrationDetailsById[$integrationRowId] = $integrationRow;
            }
        }
        $syncIntegrations = array_map(static function (array $integrationRow) use ($calendarIntegrationDetailsById): array {
            $integrationRowId = (int) ($integrationRow['id'] ?? 0);
            return $integrationRowId > 0 && isset($calendarIntegrationDetailsById[$integrationRowId])
                ? array_replace($calendarIntegrationDetailsById[$integrationRowId], $integrationRow)
                : $integrationRow;
        }, $syncIntegrations);
        $moduleHealth = [
            ['Calendar Sync', $calendarReady, $calendarReady ? count($integrations) . ' connected calendar(s)' : 'Connect Google, Outlook, or iCal.'],
            ['Booking', $bookingReady, $bookingReadinessMessage],
            ['Availability', $hasEnabledAvailability, $hasEnabledAvailability ? 'Weekly windows are active.' : 'Enable at least one weekly availability window.'],
            ['Blocked Times', true, $blockedTimes !== [] ? count($blockedTimes) . ' upcoming block(s).' : 'No upcoming blocked times.'],
            ['Bot', $botReady, $botReady ? 'Bot has a selected calendar.' : 'Enable the bot and choose its calendar.'],
            ['Notes', $notesReady, $notesReady ? 'Note ingestion is enabled.' : 'Enable note ingestion when post-meeting notes are ready.'],
            ['Worker Health', $workerReady, $workerReady ? 'Latest sync health is clean.' : (string) ($syncHealth['worker_hint'] ?? 'Schedule the calendar worker.')]
        ];
        $renderSecretControl = static function (string $label, string $name, string $placeholder, string $rotateName, string $disabledAttr): void {
            ?>
            <label class="marketplace-form-field"><?php echo htmlspecialchars($label); ?>
                <input type="password" name="<?php echo htmlspecialchars($name); ?>" value="" placeholder="<?php echo htmlspecialchars($placeholder); ?>" autocomplete="new-password" <?php echo $disabledAttr; ?>>
                <small style="color:#64748b;">Use the matching header key in automations. Rotate to replace.</small>
            </label>
            <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars($rotateName); ?>" <?php echo $disabledAttr; ?>> Rotate <?php echo htmlspecialchars(strtolower($label)); ?></label>
            <?php
        };
        ?>
        <div class="marketplace-setup-form cm-shell" style="margin-top:1rem;padding:.75rem;border-radius:8px;" data-cm-calendar-meetings-setup data-settings-endpoint="<?php echo htmlspecialchars($settingsEndpoint); ?>" data-csrf="<?php echo htmlspecialchars($csrf); ?>" data-blocked-times="<?php echo $blockedTimesJson; ?>">
            <?php marketplaceRenderSetupTabs($moduleKey, $activeTab); ?>

            <?php if (!$installed): ?>
                <p style="margin-top:.85rem;color:#475569;">Install Calendar &amp; Meetings to configure workspace settings.</p>
            <?php elseif (!$canManage): ?>
                <p style="margin-top:.85rem;color:#475569;">Read-only view. Manager access required to edit.</p>
            <?php endif; ?>

            <?php if ($activeTab === 'overview'): ?>
                <div class="cm-grid-layout" style="margin-top:1rem;">
                    <section class="cm-panel">
                        <div class="cm-panel-header">
                            <h2>Workspace Readiness</h2>
                            <?php echo $chip(!empty($readiness['ready']) ? 'Ready' : 'Partial', !empty($readiness['ready'])); ?>
                        </div>
                        <div class="cm-agenda">
                            <p><?php echo htmlspecialchars((string) ($readiness['message'] ?? 'Checking readiness.')); ?></p>
                            <?php foreach ($moduleHealth as $healthRow): ?>
                                <div class="cm-health-row">
                                    <div class="cm-chip-row">
                                        <?php echo $chip((string) $healthRow[0], (bool) $healthRow[1]); ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars((string) $healthRow[2]); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <aside class="cm-panel">
                        <div class="cm-panel-header"><h3>Operator Shortcuts</h3></div>
                        <div class="cm-agenda">
                            <a class="cm-btn cm-btn-primary" href="meeting_bookings.php">Open booking queue</a>
                            <a class="cm-btn" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, 'calendar')); ?>">Manage sync</a>
                            <?php if ($publicBookingUrl !== ''): ?>
                                <a class="cm-btn" href="<?php echo htmlspecialchars($publicBookingUrl); ?>" target="_blank" rel="noopener">Preview public booking</a>
                            <?php endif; ?>
                            <p class="cm-muted">Workspace scoped.</p>
                        </div>
                    </aside>
                </div>
                <?php marketplaceRenderReadinessChecks((array) ($readiness['checks'] ?? [])); ?>
            <?php elseif ($activeTab === 'calendar'): ?>
                <div class="cm-grid-layout" style="margin-top:1rem;">
                    <section class="cm-panel">
                        <div class="cm-panel-header">
                            <h2>Connected Calendars</h2>
                            <?php echo $chip(ucwords(str_replace('_', ' ', $syncStatus)), $syncStatus === 'healthy'); ?>
                        </div>
                        <div class="cm-agenda">
                            <?php if ($calendarSuccessMessage !== ''): ?>
                                <div class="cm-alert is-success"><?php echo htmlspecialchars($calendarSuccessMessage); ?></div>
                            <?php endif; ?>
                            <?php if ($calendarErrorMessage !== ''): ?>
                                <div class="cm-alert is-error">Calendar connection needs attention: <?php echo htmlspecialchars($calendarErrorMessage); ?></div>
                            <?php endif; ?>
                            <div class="cm-alert">Team sees readiness only, not private event details.</div>
                            <?php if ($canConnectCalendar && (!$googleCalendarConfigured || !$outlookCalendarConfigured)): ?>
                                <div class="cm-alert is-error">
                                    <?php if (!$googleCalendarConfigured && !$outlookCalendarConfigured): ?>
                                        Google and Outlook calendar OAuth credentials are not configured yet. Ask a Super Admin to finish Google Services / calendar platform setup.
                                    <?php elseif (!$googleCalendarConfigured): ?>
                                        Google Calendar OAuth is not configured yet. Ask a Super Admin to finish Google Services setup.
                                    <?php else: ?>
                                        Outlook Calendar OAuth is not configured yet. Ask a Super Admin to add Microsoft calendar credentials.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="marketplace-detail-actions cm-calendar-connect-actions">
                                <?php if ($canConnectCalendar && $googleCalendarConfigured): ?>
                                    <a class="cm-btn cm-btn-primary" href="<?php echo htmlspecialchars($apiBase); ?>/api/calendar/oauth/initiate.php?provider=google&amp;grant=calendar_import&amp;purpose=sync&amp;return_to=<?php echo $calendarOauthReturnTo; ?>">
                                        <i class="fab fa-google" aria-hidden="true"></i>
                                        Connect Google Calendar
                                    </a>
                                <?php else: ?>
                                    <button class="cm-btn cm-btn-primary" type="button" disabled>Connect Google Calendar</button>
                                <?php endif; ?>
                                <?php if ($canConnectCalendar && $outlookCalendarConfigured): ?>
                                    <a class="cm-btn cm-btn-primary" href="<?php echo htmlspecialchars($apiBase); ?>/api/calendar/oauth/initiate.php?provider=outlook&amp;purpose=sync&amp;return_to=<?php echo $calendarOauthReturnTo; ?>">
                                        <i class="fab fa-microsoft" aria-hidden="true"></i>
                                        Connect Outlook Calendar
                                    </a>
                                <?php else: ?>
                                    <button class="cm-btn cm-btn-primary" type="button" disabled>Connect Outlook Calendar</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($integrations === [] && $syncIntegrations === []): ?>
                                <p>No calendars connected yet.</p>
                            <?php else: ?>
                                <?php foreach ($syncIntegrations as $integration): ?>
                                    <?php
                                    $integrationId = (int) ($integration['id'] ?? 0);
                                    $integrationProvider = strtolower((string) ($integration['provider'] ?? 'calendar'));
                                    $integrationProviderLabel = $integrationProvider !== '' ? ucfirst($integrationProvider) : 'Calendar';
                                    $integrationOwnerId = (int) ($integration['user_id'] ?? 0);
                                    $isOwnCalendarIntegration = $integrationOwnerId > 0 && $integrationOwnerId === $currentUserId;
                                    $canManageOwnCalendarConnection = $canConnectCalendar && $isOwnCalendarIntegration;
                                    $calendarGrantType = \CRM\Services\GoogleOAuthScopeCatalog::normalizeGrantType((string) ($integration['oauth_grant_type'] ?? \CRM\Services\GoogleOAuthScopeCatalog::GRANT_LEGACY_CALENDAR));
                                    $calendarCanWrite = $integrationProvider === 'google' && \CRM\Services\GoogleOAuthScopeCatalog::grantSupportsCalendarWrite($calendarGrantType);
                                    $calendarIsLegacy = $calendarGrantType === \CRM\Services\GoogleOAuthScopeCatalog::GRANT_LEGACY_CALENDAR;
                                    $directionOptions = $calendarSyncService->providerDirectionOptions($integrationProvider);
                                    $selectedDirection = $calendarSyncService->normalizeDirection($integrationProvider, (string) ($integration['sync_direction'] ?? 'to_crm'));
                                    $calendarOwnerLabel = (string) ($integration['owner_email'] ?? ($workspaceUserLabels[$integrationOwnerId] ?? ('User #' . $integrationOwnerId)));
                                    $calendarAccountLabel = (string) ($integration['provider_account_email'] ?? '');
                                    ?>
                                    <div class="cm-health-row cm-calendar-connection-row">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($integration['calendar_name'] ?? $integration['calendar_id'] ?? ($integrationProviderLabel . ' calendar'))); ?></strong>
                                            <p class="cm-muted">
                                                <?php if ($calendarAccountLabel !== ''): ?>
                                                    Account: <?php echo htmlspecialchars($calendarAccountLabel); ?> |
                                                <?php endif; ?>
                                                Owner: <?php echo htmlspecialchars($calendarOwnerLabel); ?>
                                                | Sync: <?php echo htmlspecialchars((string) ($integration['last_sync_at'] ?? 'Never')); ?>
                                                | Busy: <?php echo htmlspecialchars((string) ($integration['availability_last_checked_at'] ?? 'Never')); ?>
                                                <?php if (!empty($integration['latest_status'])): ?>
                                                    | Audit: <?php echo htmlspecialchars((string) ($integration['latest_status'])); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="cm-chip-row">
                                            <?php echo $chip($integrationProviderLabel, true); ?>
                                            <?php echo $chip(!empty($integration['availability_enabled']) ? 'Availability on' : 'Availability off', !empty($integration['availability_enabled'])); ?>
                                            <?php echo $chip(!empty($integration['sync_enabled']) ? 'CRM import on' : 'CRM import paused', !empty($integration['sync_enabled'])); ?>
                                            <?php echo $chip(str_replace('_', ' ', (string) ($integration['sync_direction'] ?? 'to_crm')), true); ?>
                                            <?php echo $chip($isOwnCalendarIntegration ? 'My calendar' : 'Team calendar', $isOwnCalendarIntegration); ?>
                                        </div>
                                        <?php if (!empty($integration['availability_last_error'])): ?>
                                            <p class="cm-muted" style="color:#b91c1c;">Busy error: <?php echo htmlspecialchars((string) $integration['availability_last_error']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($integration['latest_message']) && (string) ($integration['latest_status'] ?? '') === 'failed'): ?>
                                            <p class="cm-muted" style="color:#b91c1c;">Sync error: <?php echo htmlspecialchars((string) $integration['latest_message']); ?></p>
                                        <?php endif; ?>

                                        <?php if ($canManageOwnCalendarConnection): ?>
                                            <form method="POST" class="cm-calendar-connection-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                                <input type="hidden" name="skill_action" value="manage_calendar_connection">
                                                <input type="hidden" name="calendar_action" value="update_connection">
                                                <input type="hidden" name="integration_id" value="<?php echo $integrationId; ?>">
                                                <label class="cm-inline-check">
                                                    <input type="checkbox" name="availability_enabled" value="1" <?php echo !empty($integration['availability_enabled']) ? 'checked' : ''; ?>>
                                                    Use for booking availability
                                                </label>
                                                <label class="cm-inline-check">
                                                    <input type="checkbox" name="sync_enabled" value="1" <?php echo !empty($integration['sync_enabled']) ? 'checked' : ''; ?>>
                                                    Import into CRM calendar
                                                </label>
                                                <label class="cm-inline-select">Direction
                                                    <select name="sync_direction">
                                                        <?php foreach ($directionOptions as $directionValue => $directionLabel): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $directionValue); ?>" <?php echo $selectedDirection === (string) $directionValue ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string) $directionLabel); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <button type="submit" class="cm-btn cm-btn-primary">Save</button>
                                            </form>
                                            <?php if ($integrationProvider !== 'google'): ?>
                                                <p class="cm-muted">Outbound sync unavailable for <?php echo htmlspecialchars($integrationProviderLabel); ?>.</p>
                                            <?php elseif (!$calendarCanWrite): ?>
                                                <div class="cm-calendar-reconnect-row">
                                                    <p class="cm-muted">Reconnect Google for outbound sync.</p>
                                                    <a class="cm-btn" href="<?php echo htmlspecialchars($apiBase); ?>/api/calendar/oauth/initiate.php?provider=google&amp;grant=calendar_write&amp;purpose=sync&amp;return_to=<?php echo $calendarOauthReturnTo; ?>">Reconnect with write access</a>
                                                </div>
                                            <?php elseif ($calendarIsLegacy): ?>
                                                <p class="cm-muted">Legacy access active.</p>
                                            <?php endif; ?>
                                            <form method="POST" class="cm-calendar-disconnect-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                                <input type="hidden" name="skill_action" value="manage_calendar_connection">
                                                <input type="hidden" name="calendar_action" value="disconnect_connection">
                                                <input type="hidden" name="integration_id" value="<?php echo $integrationId; ?>">
                                                <button type="submit" class="cm-btn cm-btn-danger">Disconnect this calendar</button>
                                            </form>
                                        <?php else: ?>
                                            <p class="cm-muted">Owner-managed calendar.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                    <aside class="cm-panel">
                        <div class="cm-panel-header"><h3>Worker And Audit</h3></div>
                        <div class="cm-agenda">
                            <p class="cm-muted"><?php echo htmlspecialchars((string) ($syncHealth['worker_hint'] ?? 'Worker cadence: 15 minutes.')); ?></p>
                            <?php foreach (array_slice($recentAudit, 0, 5) as $audit): ?>
                                <div class="cm-run-row">
                                    <strong><?php echo htmlspecialchars((string) ($audit['operation'] ?? 'sync')); ?> - <?php echo htmlspecialchars((string) ($audit['status'] ?? 'status')); ?></strong>
                                    <span class="cm-muted"><?php echo htmlspecialchars((string) ($audit['calendar_name'] ?? $audit['integration_provider'] ?? 'Calendar')); ?> - <?php echo htmlspecialchars((string) ($audit['created_at'] ?? '')); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($recentAudit === []): ?><p class="cm-muted">No audit yet.</p><?php endif; ?>
                        </div>
                    </aside>
                </div>
                <section class="cm-panel" style="margin-top:1rem;">
                    <div class="cm-panel-header"><h3>Calendar Used By Bot</h3></div>
                    <div class="cm-agenda">
                        <form method="POST" class="marketplace-setup-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                            <label class="marketplace-form-field">Workspace calendar
                                <select name="meeting_bot_google_calendar_integration_id" <?php echo $calendarDisabled; ?>>
                                    <option value="">No calendar selected</option>
                                    <?php foreach ($integrations as $integration): ?>
                                        <?php $integrationId = (string) ($integration['id'] ?? ''); ?>
                                        <option value="<?php echo htmlspecialchars($integrationId); ?>" <?php echo $selectedIntegration === $integrationId ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($integration['calendar_name'] ?? $integration['calendar_id'] ?? ('Calendar #' . $integrationId))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="marketplace-detail-actions">
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="save_calendar_meetings_setup" <?php echo $calendarDisabled; ?>>Save calendar setting</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php elseif ($activeTab === 'booking'): ?>
                <div class="cm-grid-layout" style="margin-top:1rem;">
                    <section class="cm-panel">
                        <div class="cm-panel-header">
                            <h2>Public Booking Profile</h2>
                            <?php echo $chip($bookingReady ? 'Ready' : 'Needs setup', $bookingReady); ?>
                        </div>
                        <div class="cm-agenda">
                            <?php if ($bookingProfile === []): ?>
                                <p>No booking profile yet.</p>
                                <a class="cm-btn cm-btn-primary" href="meeting_bookings.php">Create booking profile</a>
                            <?php else: ?>
                                <form class="cm-setup-form" data-cm-settings-form="profile">
                                    <input type="hidden" name="profile_id" value="<?php echo (int) ($bookingProfile['id'] ?? 0); ?>">
                                    <div class="marketplace-form-grid">
                                        <label class="marketplace-form-field marketplace-form-field-wide">Public title
                                            <input type="text" name="title" value="<?php echo htmlspecialchars((string) ($bookingProfile['title'] ?? 'Book a meeting')); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field marketplace-form-field-wide">Description
                                            <textarea name="description" rows="3" <?php echo $availabilityDisabled; ?>><?php echo htmlspecialchars((string) ($bookingProfile['description'] ?? '')); ?></textarea>
                                        </label>
                                        <label class="marketplace-form-field">Slug
                                            <input type="text" name="slug" value="<?php echo htmlspecialchars((string) ($bookingProfile['slug'] ?? 'default')); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field">Timezone
                                            <input type="text" name="timezone" value="<?php echo htmlspecialchars((string) ($bookingProfile['timezone'] ?? date_default_timezone_get())); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field">Default duration
                                            <select name="default_duration_minutes" <?php echo $availabilityDisabled; ?>>
                                                <?php foreach ([15, 30, 45, 60, 90, 120] as $durationOption): ?>
                                                    <option value="<?php echo $durationOption; ?>" <?php echo (int) ($bookingProfile['default_duration_minutes'] ?? 30) === $durationOption ? 'selected' : ''; ?>><?php echo $durationOption; ?> minutes</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="marketplace-form-field">Approval mode
                                            <select name="approval_mode" <?php echo $availabilityDisabled; ?>>
                                                <option value="manual" <?php echo (string) ($bookingProfile['approval_mode'] ?? 'manual') === 'manual' ? 'selected' : ''; ?>>Manual review</option>
                                                <option value="auto_confirm_internal" <?php echo (string) ($bookingProfile['approval_mode'] ?? '') === 'auto_confirm_internal' ? 'selected' : ''; ?>>Auto-confirm internal</option>
                                            </select>
                                        </label>
                                        <label class="marketplace-form-field">Booking mode
                                            <select name="booking_mode" <?php echo $availabilityDisabled; ?>>
                                                <option value="single_host" <?php echo $profileBookingMode === 'single_host' ? 'selected' : ''; ?>>Single host</option>
                                                <option value="round_robin" <?php echo $profileBookingMode === 'round_robin' ? 'selected' : ''; ?>>Round-robin team</option>
                                            </select>
                                        </label>
                                        <label class="marketplace-form-field">Buffer before
                                            <input type="number" min="0" max="240" name="buffer_before_minutes" value="<?php echo (int) ($bookingProfile['buffer_before_minutes'] ?? 15); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field">Buffer after
                                            <input type="number" min="0" max="240" name="buffer_after_minutes" value="<?php echo (int) ($bookingProfile['buffer_after_minutes'] ?? 15); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field">Minimum notice (hours)
                                            <input type="number" min="0" max="8760" name="min_notice_hours" value="<?php echo (int) ($bookingProfile['min_notice_hours'] ?? 24); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <label class="marketplace-form-field">Maximum advance (days)
                                            <input type="number" min="1" max="365" name="max_advance_days" value="<?php echo (int) ($bookingProfile['max_advance_days'] ?? 60); ?>" <?php echo $availabilityDisabled; ?>>
                                        </label>
                                        <fieldset class="marketplace-form-field marketplace-form-field-wide cm-choice-fieldset">
                                            <legend>Allowed durations</legend>
                                            <div class="cm-choice-grid">
                                                <?php foreach ([15, 30, 45, 60, 90, 120] as $durationOption): ?>
                                                    <label class="marketplace-form-check"><input type="checkbox" name="allowed_durations[]" value="<?php echo $durationOption; ?>" <?php echo in_array($durationOption, $profileDurations, true) ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> <?php echo $durationOption; ?> min</label>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                        <fieldset class="marketplace-form-field marketplace-form-field-wide cm-choice-fieldset">
                                            <legend>Meeting formats</legend>
                                            <div class="cm-choice-grid">
                                                <?php foreach ($meetingFormatLabels as $formatKey => $formatLabel): ?>
                                                    <label class="marketplace-form-check"><input type="checkbox" name="allowed_meeting_formats[]" value="<?php echo htmlspecialchars($formatKey); ?>" <?php echo in_array($formatKey, $profileFormats, true) ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> <?php echo htmlspecialchars($formatLabel); ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                        <label class="marketplace-form-check marketplace-form-field-wide"><input type="checkbox" name="public_enabled" value="1" <?php echo !empty($bookingProfile['public_enabled']) ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> Public booking page enabled</label>
                                    </div>
                                    <div class="cm-form-result" data-cm-form-result></div>
                                    <div class="cm-row-actions">
                                        <button class="cm-btn cm-btn-primary" type="submit" <?php echo $availabilityDisabled; ?>>Save profile</button>
                                        <a class="cm-btn" href="meeting_bookings.php">Manage bookings</a>
                                        <?php if ($publicBookingUrl !== ''): ?><a class="cm-btn" href="<?php echo htmlspecialchars($publicBookingUrl); ?>" target="_blank" rel="noopener">Open public page</a><?php endif; ?>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </section>
                    <aside class="cm-panel">
                        <div class="cm-panel-header"><h3>Host &amp; Sources</h3></div>
                        <div class="cm-agenda">
                            <?php if ($bookingProfile !== []): ?>
                                <form class="cm-setup-form" data-cm-settings-form="sources">
                                    <input type="hidden" name="profile_id" value="<?php echo (int) ($bookingProfile['id'] ?? 0); ?>">
                                    <label class="marketplace-form-field">Booking host
                                        <select name="owner_user_id" <?php echo $availabilityDisabled; ?>>
                                            <?php foreach ($workspaceUsers as $workspaceUser): ?>
                                                <?php $workspaceUserId = (int) ($workspaceUser['id'] ?? 0); ?>
                                                <option value="<?php echo $workspaceUserId; ?>" <?php echo $profileOwnerUserId === $workspaceUserId ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string) ($workspaceUser['email'] ?? $workspaceUser['label'] ?? ('User #' . $workspaceUserId))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <fieldset class="marketplace-form-field cm-choice-fieldset">
                                        <legend>Availability source</legend>
                                        <div class="cm-choice-stack">
                                            <label class="marketplace-form-check"><input type="radio" name="availability_source_mode" value="owner_all" <?php echo $effectiveProfileSourceMode === 'owner_all' ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> <?php echo $profileBookingMode === 'round_robin' ? 'All enabled host calendars' : 'All host calendars'; ?></label>
                                            <?php if ($profileBookingMode !== 'round_robin'): ?>
                                                <label class="marketplace-form-check"><input type="radio" name="availability_source_mode" value="selected_integrations" <?php echo $effectiveProfileSourceMode === 'selected_integrations' ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> Selected calendars</label>
                                            <?php endif; ?>
                                            <label class="marketplace-form-check"><input type="radio" name="availability_source_mode" value="none" <?php echo $effectiveProfileSourceMode === 'none' ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> No external calendar</label>
                                        </div>
                                    </fieldset>
                                    <?php if ($profileBookingMode === 'round_robin'): ?>
                                        <div class="cm-health-row">
                                            <strong>Team calendar source</strong>
                                            <span class="cm-muted">Uses enabled host calendars; client view stays private.</span>
                                            <?php if ($roundRobinSourceWarning !== ''): ?>
                                                <span class="cm-muted" style="color:#b45309;"><?php echo htmlspecialchars($roundRobinSourceWarning); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($hostIntegrations === []): ?>
                                        <div class="cm-health-row">
                                            <strong>Host calendar</strong>
                                            <span class="cm-muted">No host calendar. CRM events, bookings, and blocks still apply.</span>
                                            <a class="cm-btn" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl($moduleKey, 'calendar')); ?>">Open calendar setup</a>
                                        </div>
                                    <?php else: ?>
                                        <fieldset class="marketplace-form-field cm-choice-fieldset">
                                            <legend>Host calendars</legend>
                                            <div class="cm-choice-stack">
                                                <?php foreach ($hostIntegrations as $integration): ?>
                                                    <?php
                                                    $integrationId = (int) ($integration['id'] ?? 0);
                                                    $integrationLabel = trim((string) ($integration['provider_account_email'] ?? ''));
                                                    $integrationLabel = $integrationLabel !== '' ? $integrationLabel : (string) ($integration['calendar_name'] ?? $integration['calendar_id'] ?? ('Calendar #' . $integrationId));
                                                    ?>
                                                    <label class="marketplace-form-check">
                                                        <input type="checkbox" name="availability_integration_ids[]" value="<?php echo $integrationId; ?>" <?php echo in_array($integrationId, $selectedAvailabilityIds, true) ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>>
                                                        <?php echo htmlspecialchars(ucfirst((string) ($integration['provider'] ?? 'calendar')) . ' - ' . $integrationLabel); ?>
                                                    </label>
                                                    <small class="cm-muted">
                                                        <?php echo !empty($integration['availability_enabled']) ? 'Availability enabled' : 'Availability disabled'; ?>
                                                        - Busy checked <?php echo htmlspecialchars((string) ($integration['availability_last_checked_at'] ?? 'never')); ?>
                                                    </small>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                    <?php endif; ?>
                                    <div class="cm-form-result" data-cm-form-result></div>
                                    <div class="cm-row-actions">
                                        <button class="cm-btn cm-btn-primary" type="submit" <?php echo $availabilityDisabled; ?>>Save sources</button>
                                    </div>
                                </form>
                                <form class="cm-setup-form" data-cm-settings-form="round_robin_hosts">
                                    <input type="hidden" name="profile_id" value="<?php echo (int) ($bookingProfile['id'] ?? 0); ?>">
                                    <div class="cm-health-row">
                                        <strong>Round-robin team</strong>
                                        <span class="cm-muted"><?php echo htmlspecialchars($teamHostStatus); ?></span>
                                    </div>
                                    <?php if ($profileHosts === []): ?>
                                        <p class="cm-muted">No active workspace users.</p>
                                    <?php else: ?>
                                        <div class="cm-team-host-list">
                                            <?php foreach ($profileHosts as $profileHost): ?>
                                                <?php
                                                $hostUserId = (int) ($profileHost['user_id'] ?? $profileHost['id'] ?? 0);
                                                $hostLabel = (string) ($profileHost['email'] ?? $profileHost['label'] ?? ('User #' . $hostUserId));
                                                $calendarCount = (int) ($profileHost['availability_enabled_count'] ?? 0);
                                                $busyStatus = (string) ($profileHost['busy_cache_status'] ?? 'stale');
                                                ?>
                                                <label class="cm-team-host-row">
                                                    <input type="checkbox" name="host_user_ids[]" value="<?php echo $hostUserId; ?>" <?php echo !empty($profileHost['is_enabled']) ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>>
                                                    <span>
                                                        <strong><?php echo htmlspecialchars($hostLabel); ?></strong>
                                                        <small class="cm-muted">
                                                            <?php echo $calendarCount > 0 ? $calendarCount . ' availability calendar(s)' : 'Calendar optional'; ?>
                                                            - Busy <?php echo htmlspecialchars($busyStatus); ?>
                                                            - <?php echo (int) ($profileHost['recent_assignment_load'] ?? 0); ?> assignment(s) in 30 days
                                                        </small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="cm-form-result" data-cm-form-result></div>
                                    <div class="cm-row-actions">
                                        <button class="cm-btn cm-btn-primary" type="submit" <?php echo $availabilityDisabled; ?>>Save team hosts</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                            <div class="cm-health-row">
                                <strong>Host status</strong>
                                <span class="cm-muted"><?php echo htmlspecialchars($hostCalendarStatus); ?></span>
                            </div>
                            <div class="cm-health-row">
                                <strong>Booking mode</strong>
                                <span class="cm-muted"><?php echo $profileBookingMode === 'round_robin' ? 'Round-robin team; host identity hidden.' : 'Single selected host.'; ?></span>
                            </div>
                            <div class="cm-health-row">
                                <strong>Public status</strong>
                                <span class="cm-muted"><?php echo !empty($bookingProfile['public_enabled']) ? 'Visible to clients.' : 'Hidden from clients.'; ?></span>
                            </div>
                            <div class="cm-health-row">
                                <strong>Availability</strong>
                                <span class="cm-muted"><?php echo $hasEnabledAvailability ? 'Booking windows active.' : 'No active weekly window.'; ?></span>
                            </div>
                            <div class="cm-health-row">
                                <strong>Booking rules</strong>
                                <span class="cm-muted">Durations: <?php echo htmlspecialchars($formatList($bookingProfile['allowed_durations_json'] ?? [], ['15', '30', '45', '60'])); ?> minutes</span>
                                <span class="cm-muted">Buffer: <?php echo (int) ($bookingProfile['buffer_before_minutes'] ?? 15); ?> before / <?php echo (int) ($bookingProfile['buffer_after_minutes'] ?? 15); ?> after</span>
                                <span class="cm-muted">Notice: <?php echo (int) ($bookingProfile['min_notice_hours'] ?? 24); ?>h / Advance: <?php echo (int) ($bookingProfile['max_advance_days'] ?? 60); ?>d</span>
                            </div>
                        </div>
                    </aside>
                </div>
                <section class="cm-panel" style="margin-top:1rem;">
                    <div class="cm-panel-header">
                        <h3>Recent Booking Requests</h3>
                        <div class="cm-chip-row">
                            <?php echo $chip('Pending ' . (int) ($bookingStatusCounts['pending'] ?? 0), (int) ($bookingStatusCounts['pending'] ?? 0) === 0); ?>
                            <?php echo $chip('Confirmed ' . (int) ($bookingStatusCounts['confirmed'] ?? 0), true); ?>
                        </div>
                    </div>
                    <div class="cm-agenda">
                        <?php if ($bookingRequests === []): ?>
                            <p class="cm-muted">No booking requests.</p>
                        <?php else: ?>
                            <?php foreach ($bookingRequests as $booking): ?>
                                <div class="cm-booking-row">
                                    <strong><?php echo htmlspecialchars((string) ($booking['requester_name'] ?? 'Requester')); ?> - <?php echo htmlspecialchars((string) ($booking['status'] ?? 'pending')); ?></strong>
                                    <span class="cm-muted"><?php echo htmlspecialchars((string) ($booking['scheduled_start'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($booking['meeting_format'] ?? 'meeting')); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($activeTab === 'availability'): ?>
                <section class="cm-panel" style="margin-top:1rem;">
                    <div class="cm-panel-header">
                        <div>
                            <h2>Weekly Availability</h2>
                            <p class="cm-muted">Weekly windows, filtered by buffers, bookings, blocks, and busy time.</p>
                        </div>
                        <?php echo $chip($hasEnabledAvailability ? 'Accepting bookings' : 'No active windows', $hasEnabledAvailability); ?>
                    </div>
                    <?php if ($bookingProfile === []): ?>
                        <div class="cm-agenda"><p class="cm-muted">Create a booking profile before editing availability.</p></div>
                    <?php else: ?>
                        <form class="cm-setup-form cm-availability-editor" data-cm-settings-form="availability">
                            <input type="hidden" name="profile_id" value="<?php echo (int) ($bookingProfile['id'] ?? 0); ?>">
                            <div class="cm-quiet-actionbar">
                                <button class="cm-btn" type="button" data-cm-apply-weekdays <?php echo $availabilityDisabled; ?>>Apply Monday to weekdays</button>
                                <span class="cm-muted">Use multiple windows for split days or breaks.</span>
                            </div>
                            <div class="cm-availability-list">
                                <?php for ($day = 1; $day <= 7; $day++): ?>
                                    <?php
                                    $dayWindows = $windowsByDay[$day] ?? [[
                                        'day_of_week' => $day,
                                        'start_time' => ($day <= 5 ? '09:00:00' : '09:00:00'),
                                        'end_time' => ($day <= 5 ? '17:00:00' : '17:00:00'),
                                        'is_enabled' => $day <= 5 ? 1 : 0,
                                    ]];
                                    $dayEnabled = count(array_filter($dayWindows, static fn(array $window): bool => !empty($window['is_enabled']))) > 0;
                                    ?>
                                    <div class="cm-availability-day" data-cm-availability-day="<?php echo $day; ?>">
                                        <div class="cm-availability-day-head">
                                            <label class="marketplace-form-check"><input type="checkbox" data-day-enabled <?php echo $dayEnabled ? 'checked' : ''; ?> <?php echo $availabilityDisabled; ?>> <?php echo htmlspecialchars($fullWeekday($day)); ?></label>
                                            <button class="cm-btn cm-btn-subtle" type="button" data-add-window <?php echo $availabilityDisabled; ?>>Add window</button>
                                        </div>
                                        <div class="cm-availability-windows">
                                            <?php foreach ($dayWindows as $window): ?>
                                                <div class="cm-availability-window" data-window-row>
                                                    <label class="cm-field">Start <input type="time" data-window-start value="<?php echo htmlspecialchars(substr((string) ($window['start_time'] ?? '09:00:00'), 0, 5)); ?>" <?php echo $availabilityDisabled; ?>></label>
                                                    <label class="cm-field">End <input type="time" data-window-end value="<?php echo htmlspecialchars(substr((string) ($window['end_time'] ?? '17:00:00'), 0, 5)); ?>" <?php echo $availabilityDisabled; ?>></label>
                                                    <button class="cm-btn cm-btn-subtle" type="button" data-remove-window <?php echo $availabilityDisabled; ?>>Remove</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <div class="cm-form-result" data-cm-form-result></div>
                            <div class="cm-quiet-actionbar is-end">
                                <button class="cm-btn cm-btn-primary" type="submit" <?php echo $availabilityDisabled; ?>>Save availability</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($activeTab === 'blocked_times'): ?>
                <div class="cm-grid-layout cm-blocked-layout" style="margin-top:1rem;">
                    <section class="cm-panel">
                        <div class="cm-panel-header">
                            <div>
                                <h2>Blocked Times</h2>
                                <p class="cm-muted">Exclude one-off conflicts from public availability.</p>
                            </div>
                            <?php echo $chip($blockedTimes !== [] ? count($blockedTimes) . ' upcoming' : 'Clear', true); ?>
                        </div>
                        <?php if ($bookingProfile === []): ?>
                            <div class="cm-agenda"><p class="cm-muted">Create a booking profile before adding blocks.</p></div>
                        <?php else: ?>
                            <form class="cm-setup-form cm-block-form" data-cm-settings-form="blocked_time">
                                <input type="hidden" name="profile_id" value="<?php echo (int) ($bookingProfile['id'] ?? 0); ?>">
                                <input type="hidden" name="timezone" value="<?php echo htmlspecialchars((string) ($bookingProfile['timezone'] ?? date_default_timezone_get())); ?>">
                                <div class="marketplace-form-grid">
                                    <label class="marketplace-form-field">Block type
                                        <select name="block_type" data-block-type <?php echo $availabilityDisabled; ?>>
                                            <option value="custom">Custom time range</option>
                                            <option value="whole_day">Whole day</option>
                                            <option value="date_range">Multiple-day range</option>
                                        </select>
                                    </label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Reason
                                        <input type="text" name="reason" placeholder="Internal only, never shown publicly" <?php echo $availabilityDisabled; ?>>
                                    </label>
                                    <label class="marketplace-form-field" data-block-date-field>Start date
                                        <input type="date" name="start_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" <?php echo $availabilityDisabled; ?>>
                                    </label>
                                    <label class="marketplace-form-field" data-block-date-field>End date
                                        <input type="date" name="end_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" <?php echo $availabilityDisabled; ?>>
                                    </label>
                                    <label class="marketplace-form-field" data-block-time-field>Start time
                                        <input type="datetime-local" name="start_time" value="<?php echo htmlspecialchars(date('Y-m-d\T09:00')); ?>" <?php echo $availabilityDisabled; ?>>
                                    </label>
                                    <label class="marketplace-form-field" data-block-time-field>End time
                                        <input type="datetime-local" name="end_time" value="<?php echo htmlspecialchars(date('Y-m-d\T17:00')); ?>" <?php echo $availabilityDisabled; ?>>
                                    </label>
                                </div>
                                <div class="cm-quiet-actionbar">
                                    <button class="cm-btn" type="button" data-block-quick="today" <?php echo $availabilityDisabled; ?>>Today</button>
                                    <button class="cm-btn" type="button" data-block-quick="tomorrow" <?php echo $availabilityDisabled; ?>>Tomorrow</button>
                                    <button class="cm-btn" type="button" data-block-quick="next_week" <?php echo $availabilityDisabled; ?>>Next week</button>
                                </div>
                                <div class="cm-form-result" data-cm-form-result></div>
                                <div class="cm-quiet-actionbar is-end">
                                    <button class="cm-btn cm-btn-primary" type="submit" <?php echo $availabilityDisabled; ?>>Add blocked time</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>
                    <aside class="cm-panel">
                        <div class="cm-panel-header"><h3>Month Preview</h3></div>
                        <div class="cm-mini-month" data-cm-block-preview></div>
                    </aside>
                </div>
                <section class="cm-panel" style="margin-top:1rem;">
                    <div class="cm-panel-header"><h3>Upcoming Blocks</h3></div>
                    <div style="overflow:auto;">
                        <table class="cm-table">
                            <thead><tr><th>When</th><th>Scope</th><th>Reason</th><th>Actions</th></tr></thead>
                            <tbody data-cm-blocked-table>
                                <?php if ($blockedTimes === []): ?>
                                    <tr data-empty-row><td colspan="4" class="cm-muted">No upcoming blocked times.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($blockedTimes as $block): ?>
                                        <tr data-block-id="<?php echo (int) ($block['id'] ?? 0); ?>">
                                            <td><?php echo htmlspecialchars((string) ($block['start_time'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($block['end_time'] ?? '')); ?></td>
                                            <td><?php echo !empty($block['is_all_day']) ? 'All day' : 'Timed'; ?></td>
                                            <td><?php echo htmlspecialchars((string) (($block['reason'] ?? '') ?: 'Blocked')); ?></td>
                                            <td><button class="cm-btn cm-btn-danger" type="button" data-delete-block="<?php echo (int) ($block['id'] ?? 0); ?>" <?php echo $availabilityDisabled; ?>>Delete</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php elseif ($activeTab === 'bot'): ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-check marketplace-form-field-wide"><input type="checkbox" name="meeting_bot_enabled" <?php echo !empty($bot['enabled']) ? 'checked' : ''; ?> <?php echo $botDisabled; ?>> Enable meeting bot</label>
                        <label class="marketplace-form-check marketplace-form-field-wide"><input type="checkbox" name="meeting_bot_transcript_required" <?php echo !array_key_exists('transcript_required', $bot) || !empty($bot['transcript_required']) ? 'checked' : ''; ?> <?php echo $botDisabled; ?>> Require transcript before auto-apply</label>
                        <label class="marketplace-form-field">Provider
                            <select name="meeting_bot_provider" <?php echo $botDisabled; ?>>
                                <option value="zoom" <?php echo (string) ($bot['provider'] ?? 'zoom') === 'zoom' ? 'selected' : ''; ?>>Zoom</option>
                                <option value="google_meet" <?php echo (string) ($bot['provider'] ?? '') === 'google_meet' ? 'selected' : ''; ?>>Google Meet</option>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Bot display name <input type="text" name="meeting_bot_display_name" value="<?php echo htmlspecialchars((string) ($bot['bot_display_name'] ?? '')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Join policy
                            <select name="meeting_bot_join_policy" <?php echo $botDisabled; ?>>
                                <?php foreach (['manual_invite_only' => 'Manual invite only', 'calendar_suggested' => 'Calendar suggested', 'auto_join_eligible' => 'Auto-join eligible'] as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($bot['join_policy'] ?? '') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Recording mode
                            <select name="meeting_bot_recording_mode" <?php echo $botDisabled; ?>>
                                <option value="provider_native" <?php echo (string) ($bot['recording_mode'] ?? '') === 'provider_native' ? 'selected' : ''; ?>>Provider native</option>
                                <option value="bot_requested" <?php echo (string) ($bot['recording_mode'] ?? '') === 'bot_requested' ? 'selected' : ''; ?>>Bot requested</option>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Auto-apply mode
                            <select name="meeting_bot_auto_apply_mode" <?php echo $botDisabled; ?>>
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($bot['auto_apply_mode'] ?? '') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Calendar integration
                            <select name="meeting_bot_google_calendar_integration_id" <?php echo ($canManageCalendar || $canManageBot) ? '' : 'disabled'; ?>>
                                <option value="">No calendar selected</option>
                                <?php foreach ($integrations as $integration): ?>
                                    <?php $integrationId = (string) ($integration['id'] ?? ''); ?>
                                    <option value="<?php echo htmlspecialchars($integrationId); ?>" <?php echo $selectedIntegration === $integrationId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($integration['calendar_name'] ?? $integration['calendar_id'] ?? ('Calendar #' . $integrationId))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Zoom account ID <input type="text" name="meeting_bot_zoom_account_id" value="<?php echo htmlspecialchars((string) ($bot['zoom_account_id'] ?? '')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Zoom client ID <input type="text" name="meeting_bot_zoom_client_id" value="<?php echo htmlspecialchars((string) ($bot['zoom_client_id'] ?? '')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Zoom client secret <input type="password" name="meeting_bot_zoom_client_secret" value="" placeholder="<?php echo htmlspecialchars($secretValue($bot, 'zoom_client_secret')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Google client ID <input type="text" name="meeting_bot_google_workspace_client_id" value="<?php echo htmlspecialchars((string) ($bot['google_workspace_client_id'] ?? '')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Google client secret <input type="password" name="meeting_bot_google_workspace_client_secret" value="" placeholder="<?php echo htmlspecialchars($secretValue($bot, 'google_workspace_client_secret')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Google project ID <input type="text" name="meeting_bot_google_workspace_project_id" value="<?php echo htmlspecialchars((string) ($bot['google_workspace_project_id'] ?? '')); ?>" <?php echo $botDisabled; ?>></label>
                        <label class="marketplace-form-field">Google transcript mode
                            <select name="meeting_bot_google_transcript_mode" <?php echo $botDisabled; ?>>
                                <option value="manual_ingest" <?php echo (string) ($bot['google_transcript_mode'] ?? '') === 'manual_ingest' ? 'selected' : ''; ?>>Manual ingest</option>
                                <option value="workspace_export" <?php echo (string) ($bot['google_transcript_mode'] ?? '') === 'workspace_export' ? 'selected' : ''; ?>>Workspace export</option>
                            </select>
                        </label>
                        <?php $renderSecretControl('Webhook secret', 'meeting_bot_webhook_secret', $secretValue($bot, 'webhook_secret'), 'meeting_bot_regenerate_webhook_secret', $botDisabled); ?>
                        <?php $renderSecretControl('Scheduling secret', 'meeting_bot_scheduling_secret', $secretValue($bot, 'scheduling_secret'), 'meeting_bot_regenerate_scheduling_secret', $botDisabled); ?>
                        <label class="marketplace-form-field marketplace-form-field-wide">Consent notice <textarea name="meeting_bot_consent_notice" rows="3" <?php echo $botDisabled; ?>><?php echo htmlspecialchars((string) ($bot['consent_notice'] ?? '')); ?></textarea></label>
                    </div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_calendar_meetings_setup" <?php echo $botDisabled; ?>>Save meeting bot setup</button></div>
                </form>
            <?php elseif ($activeTab === 'notes'): ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-check marketplace-form-field-wide"><input type="checkbox" name="meeting_note_taker_enabled" <?php echo !empty($notes['enabled']) ? 'checked' : ''; ?> <?php echo $notesDisabled; ?>> Enable note ingestion</label>
                        <label class="marketplace-form-check"><input type="checkbox" name="meeting_note_taker_contact_updates_additive_only" <?php echo !empty($notes['contact_updates_additive_only']) ? 'checked' : ''; ?> <?php echo $notesDisabled; ?>> Additive contact updates only</label>
                        <label class="marketplace-form-check"><input type="checkbox" name="meeting_note_taker_task_auto_create_enabled" <?php echo !empty($notes['task_auto_create_enabled']) ? 'checked' : ''; ?> <?php echo $notesDisabled; ?>> Create tasks from action items</label>
                        <label class="marketplace-form-check"><input type="checkbox" name="meeting_note_taker_deal_stage_auto_move_enabled" <?php echo !empty($notes['deal_stage_auto_move_enabled']) ? 'checked' : ''; ?> <?php echo $notesDisabled; ?>> Move deals when confidence is high</label>
                        <label class="marketplace-form-field">Auto-apply mode
                            <select name="meeting_note_taker_auto_apply_mode" <?php echo $notesDisabled; ?>>
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($notes['auto_apply_mode'] ?? '') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="marketplace-form-field">Deal movement confidence <input type="number" step="0.01" min="0" max="1" name="meeting_note_taker_deal_stage_min_confidence" value="<?php echo htmlspecialchars((string) ($notes['deal_stage_min_confidence'] ?? 0.9)); ?>" <?php echo $notesDisabled; ?>></label>
                        <label class="marketplace-form-field">Contact update confidence <input type="number" step="0.01" min="0" max="1" name="meeting_note_taker_contact_update_min_confidence" value="<?php echo htmlspecialchars((string) ($notes['contact_update_min_confidence'] ?? 0.75)); ?>" <?php echo $notesDisabled; ?>></label>
                        <label class="marketplace-form-field">Context limit <input type="number" min="1" max="50" name="meeting_note_taker_max_context_entries" value="<?php echo (int) ($notes['max_context_entries'] ?? 10); ?>" <?php echo $notesDisabled; ?>></label>
                        <?php $renderSecretControl('Ingest secret', 'meeting_note_taker_ingest_secret', $secretValue($notes, 'ingest_secret'), 'meeting_note_taker_regenerate_secret', $notesDisabled); ?>
                        <fieldset class="marketplace-form-field marketplace-form-field-wide" style="border:1px solid #e2e8f0;border-radius:8px;padding:.85rem;margin:0;">
                            <legend style="font-weight:700;color:#0f172a;padding:0 .25rem;">Allowed contact fields</legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.45rem;">
                                <?php foreach (['job_title' => 'Job title', 'location' => 'Location', 'company_website' => 'Company website', 'linkedin_url' => 'LinkedIn URL', 'twitter_url' => 'Twitter/X URL', 'timezone' => 'Timezone'] as $field => $label): ?>
                                    <label class="marketplace-form-check"><input type="checkbox" name="meeting_note_taker_allowed_contact_fields[]" value="<?php echo htmlspecialchars($field); ?>" <?php echo in_array($field, $allowedFields, true) ? 'checked' : ''; ?> <?php echo $notesDisabled; ?>> <?php echo htmlspecialchars($label); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>
                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_calendar_meetings_setup" <?php echo $notesDisabled; ?>>Save note ingestion setup</button></div>
                </form>
            <?php elseif ($activeTab === 'runs'): ?>
                <div class="cm-grid-layout" style="margin-top:1rem;">
                    <section class="cm-panel">
                        <div class="cm-panel-header"><h2>Meeting Bot Runs</h2></div>
                        <div class="cm-agenda">
                            <?php if ($botRuns === []): ?>
                                <p>No meeting bot runs yet.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($botRuns, 0, 6) as $run): ?>
                                    <div class="cm-run-row">
                                        <strong><?php echo htmlspecialchars((string) ($run['status'] ?? 'run')); ?> - <?php echo htmlspecialchars((string) ($run['title'] ?? $run['external_meeting_id'] ?? 'Meeting')); ?></strong>
                                        <span class="cm-muted"><?php echo htmlspecialchars((string) ($run['created_at'] ?? $run['updated_at'] ?? '')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                    <section class="cm-panel">
                        <div class="cm-panel-header"><h2>Note Taker Runs</h2></div>
                        <div class="cm-agenda">
                            <?php if ($noteRuns === []): ?>
                                <p>No note ingestion runs yet.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($noteRuns, 0, 6) as $run): ?>
                                    <div class="cm-run-row">
                                        <strong><?php echo htmlspecialchars((string) ($run['apply_status'] ?? 'run')); ?> - <?php echo htmlspecialchars((string) ($run['title'] ?? $run['external_meeting_id'] ?? 'Meeting notes')); ?></strong>
                                        <span class="cm-muted"><?php echo htmlspecialchars((string) ($run['created_at'] ?? $run['updated_at'] ?? '')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            <?php else: ?>
                <div class="cm-panel" style="margin-top:1rem;">
                    <div class="cm-panel-header"><h2>Setup Activity</h2></div>
                    <div class="cm-agenda">
                <?php marketplaceRenderSetupActivity($events); ?>
                    </div>
                </div>
            <?php endif; ?>
            <script>
            (function () {
                var root = document.querySelector('[data-cm-calendar-meetings-setup]');
                if (!root || root.dataset.cmSetupBound === '1') {
                    return;
                }
                root.dataset.cmSetupBound = '1';
                var endpoint = root.getAttribute('data-settings-endpoint') || '';
                var csrf = root.getAttribute('data-csrf') || '';
                var blockedTimes = [];
                try {
                    blockedTimes = JSON.parse(root.getAttribute('data-blocked-times') || '[]') || [];
                } catch (error) {
                    blockedTimes = [];
                }

                function qsa(selector, context) {
                    return Array.prototype.slice.call((context || root).querySelectorAll(selector));
                }

                function setResult(target, message, ok) {
                    if (!target) {
                        return;
                    }
                    target.textContent = message;
                    target.classList.toggle('is-success', ok);
                    target.classList.toggle('is-error', !ok);
                }

                function post(action, payload, resultEl) {
                    setResult(resultEl, 'Saving...', true);
                    payload = payload || {};
                    payload.action = action;
                    payload.csrf_token = csrf;
                    return fetch(endpoint, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                        body: JSON.stringify(payload)
                    }).then(function (response) {
                        return response.json().then(function (json) {
                            if (!response.ok || !json.success) {
                                throw new Error(json.error || 'Save failed.');
                            }
                            return json;
                        });
                    }).then(function (json) {
                        setResult(resultEl, 'Saved.', true);
                        return json;
                    }).catch(function (error) {
                        setResult(resultEl, error.message || 'Save failed.', false);
                        throw error;
                    });
                }

                function collectChecked(form, name) {
                    return qsa('input[name="' + name + '"]:checked', form).map(function (input) {
                        return input.value;
                    });
                }

                qsa('[data-cm-settings-form="profile"]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var data = new FormData(form);
                        var profile = {
                            profile_id: Number(data.get('profile_id') || 0),
                            title: String(data.get('title') || ''),
                            description: String(data.get('description') || ''),
                            slug: String(data.get('slug') || ''),
                            timezone: String(data.get('timezone') || ''),
                            default_duration_minutes: Number(data.get('default_duration_minutes') || 30),
                            approval_mode: String(data.get('approval_mode') || 'manual'),
                            booking_mode: String(data.get('booking_mode') || 'single_host'),
                            buffer_before_minutes: Number(data.get('buffer_before_minutes') || 0),
                            buffer_after_minutes: Number(data.get('buffer_after_minutes') || 0),
                            min_notice_hours: Number(data.get('min_notice_hours') || 0),
                            max_advance_days: Number(data.get('max_advance_days') || 60),
                            allowed_durations: collectChecked(form, 'allowed_durations[]'),
                            allowed_meeting_formats: collectChecked(form, 'allowed_meeting_formats[]'),
                            public_enabled: form.querySelector('input[name="public_enabled"]').checked ? 1 : 0
                        };
                        post('save_profile', {profile: profile}, form.querySelector('[data-cm-form-result]'));
                    });
                });

                qsa('[data-cm-settings-form="sources"]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var data = new FormData(form);
                        post('save_availability_sources', {
                            profile_id: Number(data.get('profile_id') || 0),
                            sources: {
                                owner_user_id: Number(data.get('owner_user_id') || 0),
                                availability_source_mode: String(data.get('availability_source_mode') || 'owner_all'),
                                availability_integration_ids: collectChecked(form, 'availability_integration_ids[]')
                            }
                        }, form.querySelector('[data-cm-form-result]'));
                    });
                });

                qsa('[data-cm-settings-form="round_robin_hosts"]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var data = new FormData(form);
                        post('save_round_robin_hosts', {
                            profile_id: Number(data.get('profile_id') || 0),
                            hosts: {
                                host_user_ids: collectChecked(form, 'host_user_ids[]')
                            }
                        }, form.querySelector('[data-cm-form-result]'));
                    });
                });

                function createWindowRow(start, end) {
                    var row = document.createElement('div');
                    row.className = 'cm-availability-window';
                    row.setAttribute('data-window-row', '');
                    row.innerHTML = '<label class="cm-field">Start <input type="time" data-window-start value="' + (start || '09:00') + '"></label>'
                        + '<label class="cm-field">End <input type="time" data-window-end value="' + (end || '17:00') + '"></label>'
                        + '<button class="cm-btn cm-btn-subtle" type="button" data-remove-window>Remove</button>';
                    return row;
                }

                root.addEventListener('click', function (event) {
                    var add = event.target.closest('[data-add-window]');
                    if (add) {
                        var day = add.closest('[data-cm-availability-day]');
                        var list = day ? day.querySelector('.cm-availability-windows') : null;
                        if (list) {
                            list.appendChild(createWindowRow('09:00', '17:00'));
                        }
                        return;
                    }
                    var remove = event.target.closest('[data-remove-window]');
                    if (remove) {
                        var dayRoot = remove.closest('[data-cm-availability-day]');
                        var rows = dayRoot ? qsa('[data-window-row]', dayRoot) : [];
                        if (rows.length > 1) {
                            remove.closest('[data-window-row]').remove();
                        }
                        return;
                    }
                    var applyWeekdays = event.target.closest('[data-cm-apply-weekdays]');
                    if (applyWeekdays) {
                        var monday = root.querySelector('[data-cm-availability-day="1"]');
                        var mondayRows = monday ? qsa('[data-window-row]', monday) : [];
                        for (var day = 2; day <= 5; day++) {
                            var target = root.querySelector('[data-cm-availability-day="' + day + '"]');
                            if (!target) {
                                continue;
                            }
                            target.querySelector('[data-day-enabled]').checked = monday.querySelector('[data-day-enabled]').checked;
                            var targetList = target.querySelector('.cm-availability-windows');
                            targetList.innerHTML = '';
                            mondayRows.forEach(function (row) {
                                targetList.appendChild(createWindowRow(row.querySelector('[data-window-start]').value, row.querySelector('[data-window-end]').value));
                            });
                        }
                    }
                });

                qsa('[data-cm-settings-form="availability"]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var windows = [];
                        qsa('[data-cm-availability-day]', form).forEach(function (dayRoot) {
                            var day = Number(dayRoot.getAttribute('data-cm-availability-day'));
                            var enabled = dayRoot.querySelector('[data-day-enabled]').checked;
                            qsa('[data-window-row]', dayRoot).forEach(function (row) {
                                windows.push({
                                    day_of_week: day,
                                    start_time: row.querySelector('[data-window-start]').value,
                                    end_time: row.querySelector('[data-window-end]').value,
                                    is_enabled: enabled ? 1 : 0
                                });
                            });
                        });
                        post('save_availability', {
                            profile_id: Number(form.querySelector('input[name="profile_id"]').value || 0),
                            windows: windows
                        }, form.querySelector('[data-cm-form-result]'));
                    });
                });

                function pad(value) {
                    return String(value).padStart(2, '0');
                }

                function localDate(offsetDays) {
                    var date = new Date();
                    date.setDate(date.getDate() + offsetDays);
                    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
                }

                function updateBlockType(form) {
                    var type = form.querySelector('[data-block-type]').value;
                    qsa('[data-block-time-field]', form).forEach(function (field) {
                        field.hidden = type !== 'custom';
                    });
                    qsa('[data-block-date-field]', form).forEach(function (field) {
                        field.hidden = type === 'custom';
                    });
                    if (type === 'whole_day') {
                        form.querySelector('input[name="end_date"]').value = form.querySelector('input[name="start_date"]').value;
                    }
                }

                qsa('[data-cm-settings-form="blocked_time"]').forEach(function (form) {
                    updateBlockType(form);
                    form.querySelector('[data-block-type]').addEventListener('change', function () {
                        updateBlockType(form);
                    });
                    qsa('[data-block-quick]', form).forEach(function (button) {
                        button.addEventListener('click', function () {
                            var quick = button.getAttribute('data-block-quick');
                            var startDate = quick === 'tomorrow' ? localDate(1) : localDate(0);
                            var endDate = startDate;
                            if (quick === 'next_week') {
                                startDate = localDate(7);
                                endDate = localDate(13);
                                form.querySelector('[data-block-type]').value = 'date_range';
                            } else {
                                form.querySelector('[data-block-type]').value = 'whole_day';
                            }
                            form.querySelector('input[name="start_date"]').value = startDate;
                            form.querySelector('input[name="end_date"]').value = endDate;
                            updateBlockType(form);
                        });
                    });
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var data = new FormData(form);
                        var block = {};
                        data.forEach(function (value, key) {
                            block[key] = value;
                        });
                        post('create_blocked_time', {
                            profile_id: Number(data.get('profile_id') || 0),
                            blocked_time: block
                        }, form.querySelector('[data-cm-form-result]')).then(function (json) {
                            blockedTimes = json.blocked_times || blockedTimes;
                            renderBlockedTable();
                            renderBlockPreview();
                        });
                    });
                });

                function renderBlockedTable() {
                    var tbody = root.querySelector('[data-cm-blocked-table]');
                    if (!tbody) {
                        return;
                    }
                    tbody.innerHTML = '';
                    if (!blockedTimes.length) {
                        tbody.innerHTML = '<tr data-empty-row><td colspan="4" class="cm-muted">No upcoming blocked times.</td></tr>';
                        return;
                    }
                    blockedTimes.forEach(function (block) {
                        var row = document.createElement('tr');
                        row.setAttribute('data-block-id', block.id);
                        row.innerHTML = '<td>' + escapeHtml(String(block.start_time || '')) + ' - ' + escapeHtml(String(block.end_time || '')) + '</td>'
                            + '<td>' + (Number(block.is_all_day || 0) ? 'All day' : 'Timed') + '</td>'
                            + '<td>' + escapeHtml(String(block.reason || 'Blocked')) + '</td>'
                            + '<td><button class="cm-btn cm-btn-danger" type="button" data-delete-block="' + Number(block.id || 0) + '">Delete</button></td>';
                        tbody.appendChild(row);
                    });
                }

                root.addEventListener('click', function (event) {
                    var button = event.target.closest('[data-delete-block]');
                    if (!button) {
                        return;
                    }
                    var profileId = root.querySelector('input[name="profile_id"]') ? Number(root.querySelector('input[name="profile_id"]').value || 0) : 0;
                    button.disabled = true;
                    post('delete_blocked_time', {
                        profile_id: profileId,
                        blocked_time_id: Number(button.getAttribute('data-delete-block') || 0)
                    }, null).then(function (json) {
                        blockedTimes = json.blocked_times || blockedTimes;
                        renderBlockedTable();
                        renderBlockPreview();
                    }).catch(function () {
                        button.disabled = false;
                    });
                });

                function renderBlockPreview() {
                    var preview = root.querySelector('[data-cm-block-preview]');
                    if (!preview) {
                        return;
                    }
                    var today = new Date();
                    var year = today.getFullYear();
                    var month = today.getMonth();
                    var first = new Date(year, month, 1).getDay();
                    var days = new Date(year, month + 1, 0).getDate();
                    var blocked = {};
                    blockedTimes.forEach(function (block) {
                        var start = String(block.start_time || '').slice(0, 10);
                        var end = String(block.end_time || start).slice(0, 10);
                        if (!start) {
                            return;
                        }
                        var cursor = new Date(start + 'T12:00:00');
                        var endDate = new Date(end + 'T12:00:00');
                        while (cursor <= endDate) {
                            blocked[cursor.getFullYear() + '-' + pad(cursor.getMonth() + 1) + '-' + pad(cursor.getDate())] = true;
                            cursor.setDate(cursor.getDate() + 1);
                        }
                    });
                    preview.innerHTML = '<strong>' + today.toLocaleString([], {month: 'long', year: 'numeric'}) + '</strong>';
                    var grid = document.createElement('div');
                    grid.className = 'cm-mini-month-grid';
                    ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function (label) {
                        var head = document.createElement('span');
                        head.className = 'cm-mini-day is-head';
                        head.textContent = label;
                        grid.appendChild(head);
                    });
                    for (var blank = 0; blank < first; blank++) {
                        var empty = document.createElement('span');
                        empty.className = 'cm-mini-day is-empty';
                        grid.appendChild(empty);
                    }
                    for (var day = 1; day <= days; day++) {
                        var key = year + '-' + pad(month + 1) + '-' + pad(day);
                        var cell = document.createElement('span');
                        cell.className = 'cm-mini-day' + (blocked[key] ? ' is-blocked' : '');
                        cell.textContent = String(day);
                        grid.appendChild(cell);
                    }
                    preview.appendChild(grid);
                }

                function escapeHtml(value) {
                    return value.replace(/[&<>"']/g, function (char) {
                        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
                    });
                }

                renderBlockPreview();
            }());
            </script>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderFinanceSetup')) {
    function marketplaceRenderFinanceSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_FINANCE;
        $activeTab = (string) ($ctx['active_tab'] ?? 'start');
        $csrf = (string) ($ctx['csrf'] ?? '');
        $canManage = !empty($ctx['can_manage']);
        $status = (array) ($ctx['status'] ?? []);
        $setup = (array) ($ctx['setup'] ?? []);
        $opening = (array) ($setup['opening'] ?? []);
        $summary = (array) ($setup['summary'] ?? []);
        $bankRows = (array) ($setup['bank_accounts'] ?? []);
        $assetRows = (array) ($setup['assets'] ?? []);
        $receivableRows = (array) ($setup['receivables'] ?? []);
        $liabilityRows = (array) ($setup['liabilities'] ?? []);
        $owners = (array) ($setup['owners'] ?? []);
        $missingTabs = array_map('strval', (array) ($summary['missing_tabs'] ?? $status['opening']['missing_tabs'] ?? []));
        $rowCounts = (array) ($summary['row_counts'] ?? []);
        $reviewNoneOptions = [
            'assets' => ['field' => 'assets_none', 'label' => 'No assets', 'count_key' => 'assets'],
            'receivables' => ['field' => 'receivables_none', 'label' => 'No owed to us', 'count_key' => 'receivables'],
            'liabilities' => ['field' => 'liabilities_none', 'label' => 'No owed by us', 'count_key' => 'liabilities'],
        ];
        $reviewNoneTabs = [];
        foreach ($reviewNoneOptions as $tab => $option) {
            if (in_array((string) $tab, $missingTabs, true) && (int) ($rowCounts[(string) $option['count_key']] ?? 0) === 0) {
                $reviewNoneTabs[(string) $tab] = $option;
            }
        }
        $reviewBlockingTabs = array_values(array_filter($missingTabs, static function (string $tab) use ($reviewNoneTabs): bool {
            return $tab !== 'start' && !isset($reviewNoneTabs[$tab]);
        }));
        $tAssets = [
            'Bank' => (float) ($summary['bank_total'] ?? 0),
            'Owed to us' => (float) ($summary['receivables_total'] ?? 0),
            'Things owned' => (float) ($summary['assets_total'] ?? 0),
        ];
        $tClaims = [
            'Loans' => (float) ($summary['loans_total'] ?? 0),
            'Other owed' => (float) ($summary['other_liabilities_total'] ?? 0),
            'Owner value' => (float) ($summary['business_value'] ?? 0),
        ];
        $tAssetTotal = (float) ($summary['total_assets'] ?? 0);
        $tClaimsTotal = round((float) ($summary['liabilities_total'] ?? 0) + (float) ($summary['business_value'] ?? 0), 2);
        $disabled = $canManage ? '' : ' disabled';
        $money = static fn($value): string => number_format((float) $value, 2, '.', ',');
        $value = static fn(array $row, string $key, string $default = ''): string => htmlspecialchars((string) ($row[$key] ?? $default));
        $isReviewed = static fn(string $tab): bool => !empty($setup[$tab . '_reviewed']);
        $beginForm = static function (string $tab) use ($csrf, $moduleKey): void {
            $autosave = $tab !== 'review';
            ?>
            <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;"<?php echo $autosave ? ' data-finance-autosave="1"' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                <input type="hidden" name="skill_action" value="save_finance_setup">
                <input type="hidden" name="finance_setup_tab" value="<?php echo htmlspecialchars($tab); ?>">
            <?php
        };
        $endForm = static function (string $label, bool $disabledButton = false, bool $autosave = true) use ($disabled): void {
            ?>
                <?php if ($autosave): ?>
                    <div class="finance-autosave-status" data-finance-autosave-status>Saved</div>
                    <noscript><div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" <?php echo $disabledButton ? 'disabled' : $disabled; ?>><?php echo htmlspecialchars($label); ?></button></div></noscript>
                <?php else: ?>
                    <div class="marketplace-detail-actions">
                        <button class="btn-premium-primary" type="submit" <?php echo $disabledButton ? 'disabled' : $disabled; ?>><?php echo htmlspecialchars($label); ?></button>
                    </div>
                <?php endif; ?>
            </form>
            <?php
        };
        $renderRows = static function (array $rows, int $extra = 1): array {
            $rows = array_values($rows);
            for ($i = 0; $i < $extra; $i++) {
                $rows[] = [];
            }
            return $rows;
        };

        marketplaceRenderSetupTabs($moduleKey, $activeTab);
        ?>
        <div class="marketplace-setup-tab-panels marketplace-finance-setup" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="start">
                <h3>Start</h3>
                <?php $beginForm('start'); ?>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Opening date <input type="date" name="opening_date" value="<?php echo htmlspecialchars((string) ($opening['opening_date'] ?? date('Y-m-d'))); ?>" required<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field">Currency <input type="text" name="currency" maxlength="10" value="<?php echo htmlspecialchars((string) ($opening['currency'] ?? 'USD')); ?>" required<?php echo $disabled; ?>></label>
                        <label class="marketplace-form-field marketplace-form-field-wide">Note <textarea name="notes" rows="2"<?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($opening['notes'] ?? '')); ?></textarea></label>
                    </div>
                <?php $endForm('Save'); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="bank" hidden>
                <h3>Money in bank</h3>
                <?php $beginForm('bank'); ?>
                    <input type="hidden" name="currency" value="<?php echo htmlspecialchars((string) ($opening['currency'] ?? 'USD')); ?>">
                    <div class="finance-setup-table-wrap">
                        <table class="marketplace-setup-table finance-setup-table">
                            <thead><tr><th>Name</th><th>Kind</th><th>Opening balance</th><th>Default</th></tr></thead>
                            <tbody data-finance-row-group="bank_accounts">
                            <?php foreach ($renderRows($bankRows) as $i => $row): ?>
                                <tr data-finance-row>
                                    <td data-label="Name">
                                        <input type="hidden" name="bank_accounts[<?php echo $i; ?>][cash_account_id]" value="<?php echo $value($row, 'cash_account_id'); ?>">
                                        <input type="text" name="bank_accounts[<?php echo $i; ?>][name]" value="<?php echo $value($row, 'name'); ?>" placeholder="Main Bank"<?php echo $disabled; ?>>
                                    </td>
                                    <td data-label="Kind">
                                        <select name="bank_accounts[<?php echo $i; ?>][account_type]"<?php echo $disabled; ?>>
                                            <?php foreach (['bank' => 'Bank', 'cash' => 'Cash', 'mobile_money' => 'Mobile money', 'other' => 'Other'] as $key => $label): ?>
                                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (string) ($row['account_type'] ?? 'bank') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Opening balance"><input type="number" step="0.01" min="0" name="bank_accounts[<?php echo $i; ?>][opening_balance]" value="<?php echo $value($row, 'opening_balance'); ?>"<?php echo $disabled; ?>></td>
                                    <td data-label="Default"><input type="checkbox" name="bank_accounts[<?php echo $i; ?>][is_default]" value="1" <?php echo !empty($row['is_default']) ? 'checked' : ''; ?><?php echo $disabled; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-premium-secondary finance-setup-add-row" data-finance-add-row="bank_accounts"<?php echo $disabled; ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add</span></button>
                <?php $endForm('Save'); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="assets" hidden>
                <h3>Things owned</h3>
                <?php $beginForm('assets'); ?>
                    <?php $assetsNone = $isReviewed('assets') && $assetRows === []; ?>
                    <label class="marketplace-form-check finance-setup-none"><input type="checkbox" name="assets_none" value="1" data-finance-none="assets" <?php echo $assetsNone ? 'checked' : ''; ?><?php echo $disabled; ?>> I have none</label>
                    <div class="finance-setup-table-wrap">
                        <table class="marketplace-setup-table finance-setup-table">
                            <thead><tr><th>Name</th><th>Kind</th><th>Value</th></tr></thead>
                            <tbody data-finance-row-group="assets">
                            <?php foreach ($renderRows($assetRows) as $i => $row): ?>
                                <tr data-finance-row>
                                    <td data-label="Name"><input type="text" name="assets[<?php echo $i; ?>][name]" value="<?php echo $value($row, 'name'); ?>" placeholder="Laptop"<?php echo $disabled; ?>></td>
                                    <td data-label="Kind">
                                        <select name="assets[<?php echo $i; ?>][asset_type]"<?php echo $disabled; ?>>
                                            <?php foreach (['equipment' => 'Equipment', 'vehicle' => 'Vehicle', 'furniture' => 'Furniture', 'property' => 'Property', 'software' => 'Software', 'other' => 'Other'] as $key => $label): ?>
                                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (string) ($row['asset_type'] ?? 'other') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Value"><input type="number" step="0.01" min="0" name="assets[<?php echo $i; ?>][value]" value="<?php echo $value($row, 'value'); ?>"<?php echo $disabled; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-premium-secondary finance-setup-add-row" data-finance-add-row="assets"<?php echo $disabled; ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add</span></button>
                <?php $endForm('Save'); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="receivables" hidden>
                <h3>Money owed to us</h3>
                <?php $beginForm('receivables'); ?>
                    <?php $receivablesNone = $isReviewed('receivables') && $receivableRows === []; ?>
                    <label class="marketplace-form-check finance-setup-none"><input type="checkbox" name="receivables_none" value="1" data-finance-none="receivables" <?php echo $receivablesNone ? 'checked' : ''; ?><?php echo $disabled; ?>> I have none</label>
                    <div class="finance-setup-table-wrap">
                        <table class="marketplace-setup-table finance-setup-table">
                            <thead><tr><th>From</th><th>Amount</th><th>Due</th></tr></thead>
                            <tbody data-finance-row-group="receivables">
                            <?php foreach ($renderRows($receivableRows) as $i => $row): ?>
                                <tr data-finance-row>
                                    <td data-label="From"><input type="text" name="receivables[<?php echo $i; ?>][from_name]" value="<?php echo $value($row, 'from_name'); ?>" placeholder="Customer"<?php echo $disabled; ?>></td>
                                    <td data-label="Amount"><input type="number" step="0.01" min="0" name="receivables[<?php echo $i; ?>][amount]" value="<?php echo $value($row, 'amount'); ?>"<?php echo $disabled; ?>></td>
                                    <td data-label="Due"><input type="date" name="receivables[<?php echo $i; ?>][due_date]" value="<?php echo $value($row, 'due_date'); ?>"<?php echo $disabled; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-premium-secondary finance-setup-add-row" data-finance-add-row="receivables"<?php echo $disabled; ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add</span></button>
                <?php $endForm('Save'); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="liabilities" hidden>
                <h3>Money we owe</h3>
                <?php $beginForm('liabilities'); ?>
                    <?php $liabilitiesNone = $isReviewed('liabilities') && $liabilityRows === []; ?>
                    <label class="marketplace-form-check finance-setup-none"><input type="checkbox" name="liabilities_none" value="1" data-finance-none="liabilities" <?php echo $liabilitiesNone ? 'checked' : ''; ?><?php echo $disabled; ?>> I have none</label>
                    <div class="finance-setup-table-wrap">
                        <table class="marketplace-setup-table finance-setup-table">
                            <thead><tr><th>To</th><th>Kind</th><th>Amount</th><th>Due</th><th>Interest %</th></tr></thead>
                            <tbody data-finance-row-group="liabilities">
                            <?php foreach ($renderRows($liabilityRows) as $i => $row): ?>
                                <tr data-finance-row>
                                    <td data-label="To"><input type="text" name="liabilities[<?php echo $i; ?>][name]" value="<?php echo $value($row, 'name'); ?>" placeholder="Bank or supplier"<?php echo $disabled; ?>></td>
                                    <td data-label="Kind">
                                        <select name="liabilities[<?php echo $i; ?>][liability_type]"<?php echo $disabled; ?>>
                                            <?php foreach (['loan' => 'Loan', 'supplier_bill' => 'Supplier bill', 'tax' => 'Tax', 'other' => 'Other'] as $key => $label): ?>
                                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (string) ($row['liability_type'] ?? 'other') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Amount"><input type="number" step="0.01" min="0" name="liabilities[<?php echo $i; ?>][amount]" value="<?php echo $value($row, 'amount'); ?>"<?php echo $disabled; ?>></td>
                                    <td data-label="Due"><input type="date" name="liabilities[<?php echo $i; ?>][due_date]" value="<?php echo $value($row, 'due_date'); ?>"<?php echo $disabled; ?>></td>
                                    <td data-label="Interest %"><input type="number" step="0.0001" min="0" max="100" name="liabilities[<?php echo $i; ?>][interest_rate]" value="<?php echo $value($row, 'interest_rate'); ?>"<?php echo $disabled; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-premium-secondary finance-setup-add-row" data-finance-add-row="liabilities"<?php echo $disabled; ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add</span></button>
                <?php $endForm('Save'); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="owners" hidden>
                <h3>Owners</h3>
                <?php $beginForm('owners'); ?>
                    <?php if ($owners === []): ?>
                        <div style="border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:10px;padding:.75rem;">No owner accounts found.</div>
                    <?php else: ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach ($owners as $owner): ?>
                                <?php $ownerUserId = (int) ($owner['user_id'] ?? 0); ?>
                                <div class="finance-owner-card">
                                    <input type="hidden" name="owner_equity[<?php echo $ownerUserId; ?>][user_id]" value="<?php echo $ownerUserId; ?>">
                                    <strong style="display:block;color:#0f172a;"><?php echo htmlspecialchars((string) ($owner['display_name'] ?? $owner['email'] ?? 'Owner')); ?></strong>
                                    <div class="marketplace-form-grid" style="margin-top:.65rem;">
                                        <label class="marketplace-form-field">Share % <input type="number" step="0.0001" min="0" max="100" name="owner_equity[<?php echo $ownerUserId; ?>][ownership_percent]" value="<?php echo htmlspecialchars((string) ($owner['ownership_percent'] ?? '')); ?>" required<?php echo $disabled; ?>></label>
                                        <label class="marketplace-form-field">Capital put in <input type="number" step="0.01" min="0" name="owner_equity[<?php echo $ownerUserId; ?>][opening_owner_capital]" value="<?php echo htmlspecialchars((string) ($owner['opening_owner_capital'] ?? '0.00')); ?>" required<?php echo $disabled; ?>></label>
                                        <label class="marketplace-form-field">Owner value <input type="text" value="<?php echo htmlspecialchars($money($owner['owner_value'] ?? 0)); ?>" disabled></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php $endForm('Save', $owners === []); ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="review" hidden>
                <h3>Initial balance sheet</h3>
                <div class="finance-t-account" aria-label="Initial balance sheet T account">
                    <div class="finance-t-account-side">
                        <div class="finance-t-account-heading"><span>Assets</span><strong><?php echo htmlspecialchars($money($tAssetTotal)); ?></strong></div>
                        <?php foreach ($tAssets as $label => $amount): ?>
                            <div class="finance-t-account-row"><span><?php echo htmlspecialchars((string) $label); ?></span><strong><?php echo htmlspecialchars($money($amount)); ?></strong></div>
                        <?php endforeach; ?>
                        <div class="finance-t-account-total"><span>Total assets</span><strong><?php echo htmlspecialchars($money($tAssetTotal)); ?></strong></div>
                    </div>
                    <div class="finance-t-account-side">
                        <div class="finance-t-account-heading"><span>Liabilities + Owner value</span><strong><?php echo htmlspecialchars($money($tClaimsTotal)); ?></strong></div>
                        <?php foreach ($tClaims as $label => $amount): ?>
                            <div class="finance-t-account-row"><span><?php echo htmlspecialchars((string) $label); ?></span><strong><?php echo htmlspecialchars($money($amount)); ?></strong></div>
                        <?php endforeach; ?>
                        <div class="finance-t-account-total"><span>Total claims</span><strong><?php echo htmlspecialchars($money($tClaimsTotal)); ?></strong></div>
                    </div>
                </div>
                <div class="finance-t-account-check">
                    <span><?php echo !empty($status['ready']) ? 'Ready' : 'Open'; ?></span>
                    <strong><?php echo htmlspecialchars($money($tAssetTotal)); ?> = <?php echo htmlspecialchars($money($tClaimsTotal)); ?></strong>
                </div>
                <?php if ($reviewBlockingTabs !== []): ?>
                    <p style="color:#9a3412;font-weight:800;">Finish: <?php echo htmlspecialchars(implode(', ', $reviewBlockingTabs)); ?></p>
                <?php elseif ($reviewNoneTabs !== []): ?>
                    <p style="color:#9a3412;font-weight:800;">Check none.</p>
                <?php else: ?>
                    <p style="color:#166534;font-weight:800;">All setup tabs saved.</p>
                <?php endif; ?>
                <?php if (!empty($status['ready'])): ?>
                    <div class="marketplace-detail-actions"><a class="btn-premium-primary" href="finance.php" style="text-decoration:none;">Open Finance</a></div>
                <?php else: ?>
                    <?php $beginForm('review'); ?>
                        <?php if (in_array('start', $missingTabs, true)): ?><input type="hidden" name="start_confirmed" value="1"><?php endif; ?>
                        <?php foreach ($reviewNoneTabs as $option): ?>
                            <label class="marketplace-form-check finance-setup-none"><input type="checkbox" name="<?php echo htmlspecialchars((string) $option['field']); ?>" value="1" required<?php echo $disabled; ?>> <?php echo htmlspecialchars((string) $option['label']); ?></label>
                        <?php endforeach; ?>
                    <?php $endForm('Finish', $reviewBlockingTabs !== [], false); ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceEmailAssistantHiddenInputs')) {
    function marketplaceEmailAssistantHiddenInputs(array $settings, bool $enabled, array $excludeNames = []): void
    {
        $excludeLookup = array_fill_keys($excludeNames, true);
        $hidden = [
            'email_assistant_enabled' => $enabled ? '1' : '',
            'email_assistant_system_email' => (string) ($settings['system_email'] ?? ''),
            'email_assistant_from_email' => (string) ($settings['from_email'] ?? ''),
            'email_assistant_from_name' => (string) ($settings['from_name'] ?? ''),
            'email_assistant_default_tone' => (string) ($settings['default_tone'] ?? 'professional'),
            'email_assistant_smtp_host' => (string) ($settings['smtp_host'] ?? ''),
            'email_assistant_smtp_port' => (string) ($settings['smtp_port'] ?? '587'),
            'email_assistant_smtp_user' => (string) ($settings['smtp_username'] ?? ''),
            'email_assistant_smtp_encryption' => (string) ($settings['smtp_encryption'] ?? 'tls'),
            'email_assistant_imap_enabled' => !empty($settings['imap_enabled']) ? '1' : '',
            'email_assistant_imap_host' => (string) ($settings['imap_host'] ?? ''),
            'email_assistant_imap_port' => (string) ($settings['imap_port'] ?? '993'),
            'email_assistant_imap_protocol' => (string) ($settings['imap_protocol'] ?? 'imap'),
            'email_assistant_imap_encryption' => (string) ($settings['imap_encryption'] ?? 'ssl'),
            'email_assistant_imap_user' => (string) ($settings['imap_username'] ?? ''),
            'email_assistant_imap_folder' => (string) ($settings['imap_folder'] ?? 'INBOX'),
            'email_assistant_allowed_senders' => (string) ($settings['allowed_senders'] ?? ''),
            'email_assistant_thread_context_window' => (string) ($settings['thread_context_window'] ?? '8'),
            'email_assistant_min_confidence' => (string) ($settings['min_confidence'] ?? '0.65'),
            'email_assistant_min_send_confidence' => (string) ($settings['min_send_confidence'] ?? '0.8'),
            'email_assistant_qa_enabled' => !empty($settings['qa_enabled']) ? '1' : '',
            'email_assistant_instructions_enabled' => !empty($settings['instructions_enabled']) ? '1' : '',
            'email_assistant_customer_thread_enabled' => !empty($settings['customer_thread_enabled']) ? '1' : '',
            'email_assistant_customer_send_enabled' => !empty($settings['customer_send_enabled']) ? '1' : '',
            'email_assistant_allow_clarifying_questions' => !empty($settings['allow_clarifying_questions']) ? '1' : '',
            'email_assistant_activity_logging' => !empty($settings['activity_logging']) ? '1' : '',
            'email_assistant_skill_create_contact' => !empty($settings['skill_create_contact']) ? '1' : '',
            'email_assistant_skill_update_contact' => !empty($settings['skill_update_contact']) ? '1' : '',
            'email_assistant_skill_delete_contact' => !empty($settings['skill_delete_contact']) ? '1' : '',
            'email_assistant_skill_enrich_contact' => !empty($settings['skill_enrich_contact']) ? '1' : '',
            'email_assistant_skill_verify_email' => !empty($settings['skill_verify_email']) ? '1' : '',
            'email_assistant_skill_add_note' => !empty($settings['skill_add_note']) ? '1' : '',
            'email_assistant_skill_get_pipeline' => !empty($settings['skill_get_pipeline']) ? '1' : '',
            'email_assistant_skill_list_tasks' => !empty($settings['skill_list_tasks']) ? '1' : '',
            'email_assistant_skill_schedule_event' => !empty($settings['skill_schedule_event']) ? '1' : '',
            'email_assistant_skill_run_report' => !empty($settings['skill_run_report']) ? '1' : '',
            'email_assistant_digest_enabled' => !empty($settings['digest_enabled']) ? '1' : '',
            'email_assistant_digest_time' => (string) ($settings['digest_time'] ?? '07:00'),
            'email_assistant_digest_recipients' => (string) ($settings['digest_recipients'] ?? 'admins'),
            'email_assistant_reopen_template_enabled' => !empty($settings['reopen_template_enabled']) ? '1' : '',
            'email_assistant_reopen_template_name' => (string) ($settings['reopen_template_name'] ?? ''),
            'email_assistant_reopen_template_language' => (string) ($settings['reopen_template_language'] ?? 'en_US'),
            'email_assistant_reopen_template_subject' => (string) ($settings['reopen_template_subject'] ?? ''),
            'email_assistant_reopen_template_body' => (string) ($settings['reopen_template_body'] ?? ''),
            'email_assistant_reopen_template_cta_label' => (string) ($settings['reopen_template_cta_label'] ?? ''),
            'email_assistant_reopen_template_cta_url' => (string) ($settings['reopen_template_cta_url'] ?? ''),
        ];

        foreach ($hidden as $name => $value) {
            if (isset($excludeLookup[$name])) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            echo '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
        }
    }
}

if (!function_exists('marketplaceRenderEmailAssistantSetup')) {
    function marketplaceRenderEmailAssistantSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'identity'));
        $settings = (array) ($ctx['settings'] ?? []);
        $enabled = !empty($ctx['enabled']);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $installed = !empty($ctx['installed']);
        $canManage = !empty($ctx['can_manage']);
        $events = (array) ($ctx['events'] ?? []);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $deliveryAttempts = (array) ($ctx['delivery_attempts'] ?? []);
        $workerHealth = (array) ($ctx['worker_health'] ?? []);
        $platform = (array) ($ctx['platform'] ?? []);
        $assistantProvider = (array) ($platform['assistant_provider'] ?? []);
        $configuredProviders = array_values(array_filter([
            !empty($platform['gmail_configured']) ? 'Gmail platform app' : '',
            !empty($platform['google_workspace_configured']) ? 'Google Workspace platform app' : '',
        ], static fn(string $label): bool => $label !== ''));
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';
        $disabled = (!$canManage || !$installed) ? ' disabled' : '';
        $saveNotice = $installed ? 'Marketplace management access is required to save setup.' : 'Install Email Assistant before saving setup.';
        $skillToggles = [
            'email_assistant_qa_enabled' => ['qa_enabled', 'Q&A'],
            'email_assistant_instructions_enabled' => ['instructions_enabled', 'Inbound instructions'],
            'email_assistant_customer_thread_enabled' => ['customer_thread_enabled', 'Customer threads'],
            'email_assistant_customer_send_enabled' => ['customer_send_enabled', 'Customer send'],
            'email_assistant_allow_clarifying_questions' => ['allow_clarifying_questions', 'Clarifying questions'],
            'email_assistant_activity_logging' => ['activity_logging', 'Activity logging'],
            'email_assistant_skill_create_contact' => ['skill_create_contact', 'Create contact'],
            'email_assistant_skill_update_contact' => ['skill_update_contact', 'Update contact'],
            'email_assistant_skill_delete_contact' => ['skill_delete_contact', 'Delete contact'],
            'email_assistant_skill_enrich_contact' => ['skill_enrich_contact', 'Enrich contact'],
            'email_assistant_skill_verify_email' => ['skill_verify_email', 'Verify email'],
            'email_assistant_skill_add_note' => ['skill_add_note', 'Add note'],
            'email_assistant_skill_get_pipeline' => ['skill_get_pipeline', 'Pipeline status'],
            'email_assistant_skill_list_tasks' => ['skill_list_tasks', 'List tasks'],
            'email_assistant_skill_schedule_event' => ['skill_schedule_event', 'Schedule event'],
            'email_assistant_skill_run_report' => ['skill_run_report', 'Run report'],
        ];

        marketplaceRenderSetupTabs($moduleKey, $activeTab);
        ?>
        <div class="marketplace-setup-tab-panels marketplace-email-assistant-setup" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="identity"<?php echo $panelAttr('identity'); ?>>
                <h3>Identity</h3>
                <p>Set the mailbox and sender identity.</p>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, ['email_assistant_enabled', 'email_assistant_system_email', 'email_assistant_from_email', 'email_assistant_from_name', 'email_assistant_default_tone']); ?>
                        <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="email_assistant_enabled" <?php echo $enabled ? 'checked' : ''; ?>> Enable Email Assistant</label>
                        <div class="marketplace-form-grid">
                            <label class="marketplace-form-field">System email <input type="email" name="email_assistant_system_email" value="<?php echo htmlspecialchars((string) ($settings['system_email'] ?? '')); ?>" placeholder="assistant@example.com"></label>
                            <label class="marketplace-form-field">From email <input type="email" name="email_assistant_from_email" value="<?php echo htmlspecialchars((string) ($settings['from_email'] ?? '')); ?>" placeholder="assistant@example.com"></label>
                            <label class="marketplace-form-field">From name <input type="text" name="email_assistant_from_name" value="<?php echo htmlspecialchars((string) ($settings['from_name'] ?? '')); ?>" placeholder="Workspace Assistant"></label>
                            <label class="marketplace-form-field">Tone
                                <select name="email_assistant_default_tone">
                                    <?php foreach (['professional' => 'Professional', 'warm' => 'Warm', 'direct' => 'Direct'] as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($settings['default_tone'] ?? 'professional') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save identity</button></div>
                    </form>
                <?php endif; ?>
                <div class="marketplace-setup-group marketplace-email-assistant-subsection">
                    <h4>Assistant Gmail</h4>
                    <p><?php echo htmlspecialchars((string) ($assistantProvider['provider_label'] ?? 'Connect Assistant Gmail when you prefer OAuth over manual SMTP/IMAP.')); ?></p>
                    <?php if ($configuredProviders !== []): ?>
                        <div class="marketplace-chip-row marketplace-configured-provider-row">
                            <?php foreach ($configuredProviders as $providerLabel): ?>
                                <span class="marketplace-chip"><?php echo htmlspecialchars($providerLabel); ?> configured</span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="marketplace-detail-actions marketplace-email-assistant-actions">
                        <?php if (!empty($platform['gmail_configured'])): ?>
                            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(getBasePath() . '/api/email/assistant_gmail/initiate.php'); ?>">Connect Assistant Gmail</a>
                        <?php endif; ?>
                        <?php if (!empty($assistantProvider['is_active'])): ?>
                            <form method="POST" class="marketplace-email-assistant-inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled); ?>
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="disconnect_email_assistant_gmail"<?php echo $disabled; ?>>Disconnect Assistant Gmail</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canManage && $installed): ?>
                            <form method="POST" class="marketplace-email-assistant-inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="clear_email_assistant_mail">Clear assistant mail settings</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="outbound"<?php echo $panelAttr('outbound'); ?>>
                <h3>Outbound sending</h3>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, ['email_assistant_smtp_host', 'email_assistant_smtp_port', 'email_assistant_smtp_user', 'email_assistant_smtp_encryption']); ?>
                        <div class="marketplace-form-grid">
                            <label class="marketplace-form-field">SMTP host <input type="text" name="email_assistant_smtp_host" value="<?php echo htmlspecialchars((string) ($settings['smtp_host'] ?? '')); ?>" placeholder="smtp.example.com"></label>
                            <label class="marketplace-form-field">SMTP port <input type="number" name="email_assistant_smtp_port" value="<?php echo htmlspecialchars((string) ($settings['smtp_port'] ?? '587')); ?>" min="1" max="65535"></label>
                            <label class="marketplace-form-field">SMTP username <input type="text" name="email_assistant_smtp_user" value="<?php echo htmlspecialchars((string) (($settings['smtp_username'] ?? '') ?: ($settings['from_email'] ?? ''))); ?>" autocomplete="username"></label>
                            <label class="marketplace-form-field">SMTP password <input type="password" name="email_assistant_smtp_pass" value="" autocomplete="new-password" placeholder="<?php echo array_key_exists('smtp_password', $settings) ? 'Saved password' : 'App password'; ?>"></label>
                            <label class="marketplace-form-field">Encryption
                                <select name="email_assistant_smtp_encryption">
                                    <?php foreach (['' => 'Default from port', 'tls' => 'TLS', 'ssl' => 'SSL'] as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($settings['smtp_encryption'] ?? 'tls') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save outbound</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="inbound"<?php echo $panelAttr('inbound'); ?>>
                <h3>Inbound IMAP</h3>
                <p>Process messages sent to the system address.</p>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, ['email_assistant_imap_enabled', 'email_assistant_imap_host', 'email_assistant_imap_port', 'email_assistant_imap_protocol', 'email_assistant_imap_encryption', 'email_assistant_imap_user', 'email_assistant_imap_folder', 'email_assistant_allowed_senders']); ?>
                        <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="email_assistant_imap_enabled" <?php echo !empty($settings['imap_enabled']) ? 'checked' : ''; ?>> Enable inbound IMAP</label>
                        <div class="marketplace-form-grid">
                            <label class="marketplace-form-field">IMAP host <input type="text" name="email_assistant_imap_host" value="<?php echo htmlspecialchars((string) ($settings['imap_host'] ?? '')); ?>" placeholder="imap.example.com"></label>
                            <label class="marketplace-form-field">IMAP port <input type="number" name="email_assistant_imap_port" value="<?php echo htmlspecialchars((string) ($settings['imap_port'] ?? '993')); ?>" min="1" max="65535"></label>
                            <label class="marketplace-form-field">IMAP username <input type="text" name="email_assistant_imap_user" value="<?php echo htmlspecialchars((string) (($settings['imap_username'] ?? '') ?: (!empty($settings['imap_enabled']) ? ($settings['from_email'] ?? '') : ''))); ?>" autocomplete="username"></label>
                            <label class="marketplace-form-field">IMAP password <input type="password" name="email_assistant_imap_pass" value="" autocomplete="new-password" placeholder="<?php echo array_key_exists('imap_password', $settings) ? 'Saved password' : 'App password'; ?>"></label>
                            <label class="marketplace-form-field">Protocol <input type="text" name="email_assistant_imap_protocol" value="<?php echo htmlspecialchars((string) ($settings['imap_protocol'] ?? 'imap')); ?>"></label>
                            <label class="marketplace-form-field">Encryption <input type="text" name="email_assistant_imap_encryption" value="<?php echo htmlspecialchars((string) ($settings['imap_encryption'] ?? 'ssl')); ?>"></label>
                            <label class="marketplace-form-field">Folder <input type="text" name="email_assistant_imap_folder" value="<?php echo htmlspecialchars((string) ($settings['imap_folder'] ?? 'INBOX')); ?>"></label>
                            <label class="marketplace-form-field marketplace-form-field-wide">Allowed senders <textarea name="email_assistant_allowed_senders" rows="3" placeholder="One email or domain per line"><?php echo htmlspecialchars((string) ($settings['allowed_senders'] ?? '')); ?></textarea></label>
                        </div>
                        <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save inbound</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="skills"<?php echo $panelAttr('skills'); ?>>
                <h3>Skills</h3>
                <p>Choose the command skills this workspace can use.</p>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, array_keys($skillToggles)); ?>
                        <div class="marketplace-email-assistant-toggle-grid">
                            <?php foreach ($skillToggles as $inputName => [$settingKey, $labelText]): ?>
                                <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="<?php echo htmlspecialchars($inputName); ?>" <?php echo !empty($settings[$settingKey]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($labelText); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="marketplace-form-grid marketplace-email-assistant-threshold-grid">
                            <label class="marketplace-form-field">Thread context <input type="number" name="email_assistant_thread_context_window" value="<?php echo htmlspecialchars((string) ($settings['thread_context_window'] ?? '8')); ?>" min="3" max="20"></label>
                            <label class="marketplace-form-field">Minimum confidence <input type="number" name="email_assistant_min_confidence" value="<?php echo htmlspecialchars((string) ($settings['min_confidence'] ?? '0.65')); ?>" min="0" max="1" step="0.01"></label>
                            <label class="marketplace-form-field">Send confidence <input type="number" name="email_assistant_min_send_confidence" value="<?php echo htmlspecialchars((string) ($settings['min_send_confidence'] ?? '0.8')); ?>" min="0" max="1" step="0.01"></label>
                        </div>
                        <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save skills</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="digest"<?php echo $panelAttr('digest'); ?>>
                <h3>Digest</h3>
                <p>Schedule the daily digest.</p>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <?php
                    $digestRecipients = (string) ($settings['digest_recipients'] ?? 'admins');
                    $digestMode = strtolower($digestRecipients) === 'admins' || trim($digestRecipients) === '' ? 'admins' : 'custom';
                    ?>
                    <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, ['email_assistant_digest_enabled', 'email_assistant_digest_time', 'email_assistant_digest_recipients']); ?>
                        <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="email_assistant_digest_enabled" <?php echo !empty($settings['digest_enabled']) ? 'checked' : ''; ?>> Send daily digest</label>
                        <div class="marketplace-form-grid">
                            <label class="marketplace-form-field">Send time <input type="time" name="email_assistant_digest_time" value="<?php echo htmlspecialchars((string) ($settings['digest_time'] ?? '07:00')); ?>"></label>
                            <label class="marketplace-form-field">Recipients
                                <select name="email_assistant_digest_recipient_mode">
                                    <option value="admins" <?php echo $digestMode === 'admins' ? 'selected' : ''; ?>>Workspace admins</option>
                                    <option value="custom" <?php echo $digestMode === 'custom' ? 'selected' : ''; ?>>Custom emails</option>
                                </select>
                            </label>
                            <label class="marketplace-form-field marketplace-form-field-wide">Custom recipient emails <textarea name="email_assistant_digest_custom_emails" rows="2" placeholder="owner@example.com, manager@example.com"><?php echo $digestMode === 'custom' ? htmlspecialchars($digestRecipients) : ''; ?></textarea></label>
                        </div>
                        <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save digest</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="tests"<?php echo $panelAttr('tests'); ?>>
                <h3>Tests</h3>
                <p><?php echo htmlspecialchars((string) ($readiness['next_action'] ?? 'Run a readiness check before sending live tests.')); ?></p>
                <?php marketplaceRenderReadinessChecks((array) ($readiness['checks'] ?? [])); ?>
                <form method="POST" class="marketplace-detail-actions marketplace-email-assistant-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <button class="btn-premium-primary" type="submit" name="skill_action" value="run_marketplace_readiness_check" <?php echo $installed ? '' : 'disabled'; ?>>Run readiness check</button>
                </form>
                <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form marketplace-email-assistant-test-digest-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <label class="marketplace-form-field">Explicit test recipient email <input type="email" name="email_test_recipient" placeholder="owner@example.com"></label>
                    <div class="marketplace-detail-actions marketplace-email-assistant-actions"><button class="btn-premium-secondary" type="submit" name="skill_action" value="send_email_assistant_test_digest" <?php echo $installed ? '' : 'disabled'; ?>>Send live test digest</button></div>
                </form>
                <div class="marketplace-form-grid marketplace-email-assistant-worker-health">
                    <?php foreach (['digest' => 'Digest worker', 'inbound' => 'Inbound worker'] as $workerKey => $workerLabel): ?>
                        <?php $job = (array) ($workerHealth[$workerKey] ?? []); ?>
                        <div class="marketplace-setup-group">
                            <strong><?php echo htmlspecialchars($workerLabel); ?></strong>
                            <p><?php echo $job === []
                                ? 'No worker heartbeat recorded yet.'
                                : htmlspecialchars(ucfirst((string) ($job['derived_status'] ?? $job['status'] ?? 'unknown')) . ' · ' . (string) ($job['last_run_at'] ?? 'not run')); ?></p>
                            <?php if (trim((string) ($job['last_message'] ?? '')) !== ''): ?><small><?php echo htmlspecialchars((string) $job['last_message']); ?></small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                <h3>Activity</h3>
                <?php marketplaceRenderSetupActivity($events); ?>
                <h4>Digest delivery attempts</h4>
                <?php if ($deliveryAttempts === []): ?>
                    <p>No test or scheduled digest attempts have been recorded.</p>
                <?php else: ?>
                    <div class="marketplace-setup-activity">
                        <?php foreach ($deliveryAttempts as $attempt): ?>
                            <div class="marketplace-setup-activity-row">
                                <strong><?php echo !empty($attempt['is_test']) ? 'Test digest' : 'Scheduled digest'; ?> · <?php echo htmlspecialchars((string) ($attempt['status'] ?? 'unknown')); ?></strong>
                                <span><?php echo htmlspecialchars((string) ($attempt['recipient_email'] ?? '')); ?> · <?php echo htmlspecialchars((string) ($attempt['provider_key'] ?? 'provider unavailable')); ?> · <?php echo htmlspecialchars((string) ($attempt['sent_at'] ?? '')); ?></span>
                                <?php if (trim((string) ($attempt['error_message'] ?? '')) !== ''): ?><small><?php echo htmlspecialchars((string) $attempt['error_message']); ?></small><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="advanced"<?php echo $panelAttr('advanced'); ?>>
                <h3>Advanced</h3>
                <p>Configure the optional reopen template used when Email Assistant needs a controlled follow-up message.</p>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo htmlspecialchars($saveNotice); ?></p>
                <?php else: ?>
                    <div class="marketplace-detail-actions marketplace-email-assistant-actions">
                        <button class="btn-premium-primary" type="button" onclick="var d=document.getElementById('email-assistant-reopen-template-dialog'); if (d && d.showModal) d.showModal();">Open reopen template form</button>
                    </div>
                    <dialog id="email-assistant-reopen-template-dialog" class="marketplace-setup-dialog">
                        <form method="POST" class="marketplace-setup-form marketplace-email-assistant-form marketplace-email-assistant-dialog-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                            <?php marketplaceEmailAssistantHiddenInputs($settings, $enabled, ['email_assistant_reopen_template_enabled', 'email_assistant_reopen_template_name', 'email_assistant_reopen_template_language', 'email_assistant_reopen_template_subject', 'email_assistant_reopen_template_body', 'email_assistant_reopen_template_cta_label', 'email_assistant_reopen_template_cta_url']); ?>
                            <h4>Reopen template</h4>
                            <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="email_assistant_reopen_template_enabled" <?php echo !empty($settings['reopen_template_enabled']) ? 'checked' : ''; ?>> Enable reopen template</label>
                            <div class="marketplace-form-grid">
                                <label class="marketplace-form-field">Template name <input type="text" name="email_assistant_reopen_template_name" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_name'] ?? '')); ?>" placeholder="customer_follow_up"></label>
                                <label class="marketplace-form-field">Language <input type="text" name="email_assistant_reopen_template_language" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_language'] ?? 'en_US')); ?>"></label>
                                <label class="marketplace-form-field marketplace-form-field-wide">Subject <input type="text" name="email_assistant_reopen_template_subject" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_subject'] ?? '')); ?>" placeholder="Following up"></label>
                                <label class="marketplace-form-field marketplace-form-field-wide">Body <textarea name="email_assistant_reopen_template_body" rows="5" placeholder="Hi {{name}}, following up on our previous conversation."><?php echo htmlspecialchars((string) ($settings['reopen_template_body'] ?? '')); ?></textarea></label>
                                <label class="marketplace-form-field">CTA label <input type="text" name="email_assistant_reopen_template_cta_label" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_cta_label'] ?? '')); ?>"></label>
                                <label class="marketplace-form-field">CTA URL <input type="url" name="email_assistant_reopen_template_cta_url" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_cta_url'] ?? '')); ?>"></label>
                            </div>
                            <div class="marketplace-detail-actions marketplace-email-assistant-actions">
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save reopen template</button>
                                <button class="btn-premium-secondary" type="button" onclick="this.closest('dialog').close();">Close</button>
                            </div>
                        </form>
                    </dialog>
                    <div class="marketplace-overview-block">
                        <h4><?php echo !empty($settings['reopen_template_enabled']) ? 'Template enabled' : 'Template disabled'; ?></h4>
                        <p><?php echo htmlspecialchars((string) (($settings['reopen_template_name'] ?? '') ?: 'No reopen template name saved.')); ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderAiCoachSetup')) {
    function marketplaceRenderAiCoachSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::SKILL_AI_COACH;
        $activeTab = (string) ($ctx['active_tab'] ?? 'workspace_readiness');
        $activeTab = match ($activeTab) {
            'readiness' => 'workspace_readiness',
            'context' => 'my_brief',
            'examples', 'overview' => 'workspace_readiness',
            default => $activeTab,
        };
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, $activeTab);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $installed = !empty($ctx['installed']);
        $enabled = array_key_exists('workspace_enabled', $ctx) ? !empty($ctx['workspace_enabled']) : !empty($ctx['enabled']);
        $canManage = !empty($ctx['can_manage']);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $fullReadiness = (array) (($ctx['full_readiness'] ?? []) ?: $readiness);
        $decisionChecks = (array) ($ctx['decision_checks'] ?? []);
        $pairings = (array) ($ctx['pairings'] ?? []);
        $examples = (array) ($ctx['examples'] ?? []);
        $does = (array) ($ctx['does'] ?? []);
        $doesNot = (array) ($ctx['does_not'] ?? []);
        $smart = (array) ($ctx['smart_templates'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $isSuperAdmin = !empty($ctx['is_superadmin']);
        $teamBriefs = (array) ($ctx['team_briefs'] ?? []);
        $currentBrief = (array) ($ctx['current_brief'] ?? []);
        $onboardingPayload = (array) ($fullReadiness['onboarding_payload'] ?? []);
        $strategy = (array) (($currentBrief['strategy'] ?? []) ?: ($onboardingPayload['strategy'] ?? []));
        $idea = (array) (($currentBrief['idea_validation'] ?? []) ?: ($onboardingPayload['idea_validation'] ?? []));
        $personalMissing = (array) (($currentBrief['missing_requirements'] ?? []) ?: ($fullReadiness['personal_missing_requirements'] ?? []));
        $personalBriefReady = !empty($currentBrief['personal_brief_ready']) || !empty($fullReadiness['personal_brief_ready']);
        $recommendationsReady = !empty($fullReadiness['recommendations_ready']);
        $clarityJourneyReady = !empty($fullReadiness['clarity_journey_ready']);
        $optionalPersonalMissing = (array) ($fullReadiness['optional_personal_strategy_missing'] ?? $personalMissing);
        $readyAdviceSkills = (array) ($readiness['ready_advice_skills'] ?? []);
        $setupPath = marketplaceSetupTabUrl($moduleKey, 'my_brief');
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';

        $textarea = static function (string $name, string $label, string $value, string $placeholder = ''): void {
            ?>
            <label class="marketplace-form-field marketplace-form-field-wide">
                <?php echo htmlspecialchars($label); ?>
                <textarea name="<?php echo htmlspecialchars($name); ?>" rows="3" placeholder="<?php echo htmlspecialchars($placeholder); ?>"><?php echo htmlspecialchars($value); ?></textarea>
            </label>
            <?php
        };
        $input = static function (string $name, string $label, string $value, string $placeholder = ''): void {
            ?>
            <label class="marketplace-form-field">
                <?php echo htmlspecialchars($label); ?>
                <input type="text" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($value); ?>" placeholder="<?php echo htmlspecialchars($placeholder); ?>">
            </label>
            <?php
        };

        marketplaceRenderSetupTabs($moduleKey, $activeTab);
        ?>
        <div class="marketplace-setup-tab-panels" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="workspace_readiness"<?php echo $panelAttr('workspace_readiness'); ?>>
                <h3>Workspace Readiness</h3>
                <p>Admins enable AI Coach here for the workspace. Recommendations unlock from shared company and product context plus a completed Clarity Journey; personal strategy is an optional refinement.</p>
                <?php marketplaceRenderReadinessChecks($decisionChecks); ?>
                <?php if ($canManage): ?>
                    <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <label class="marketplace-form-check"><input type="checkbox" name="ai_coach_enabled" <?php echo $enabled ? 'checked' : ''; ?>> Enable AI Coach for this workspace</label>
                        <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_ai_coach_setup">Save workspace setup</button></div>
                    </form>
                <?php endif; ?>
                <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                    <a class="btn-premium-secondary" href="startup_journey.php" style="text-decoration:none;">Open Clarity Journey</a>
                    <a class="btn-premium-secondary" href="settings.php?tab=company" style="text-decoration:none;">Company and products</a>
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=marketing_pro" style="text-decoration:none;">Campaign Manager</a>
                </div>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="team_briefs"<?php echo $panelAttr('team_briefs'); ?>>
                <h3>Team Context</h3>
                <p>AI Coach uses each member's Clarity Journey-backed context when available. Personal strategy notes can sharpen guidance, but they do not block recommendations.</p>
                <?php if ($teamBriefs === []): ?>
                    <p style="color:#64748b;">No active workspace members were found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;margin-top:.85rem;">
                        <table class="marketplace-setup-table" style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:.65rem;border-bottom:1px solid #e2e8f0;">Member</th>
                                    <th style="text-align:left;padding:.65rem;border-bottom:1px solid #e2e8f0;">Journey</th>
                                    <th style="text-align:left;padding:.65rem;border-bottom:1px solid #e2e8f0;">Optional strategy</th>
                                    <th style="text-align:left;padding:.65rem;border-bottom:1px solid #e2e8f0;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($teamBriefs as $member): ?>
                                <?php $missing = (array) ($member['missing_requirements'] ?? []); ?>
                                <tr>
                                    <td style="padding:.65rem;border-bottom:1px solid #f1f5f9;">
                                        <strong style="display:block;color:#0f172a;"><?php echo htmlspecialchars((string) ($member['display_name'] ?? 'Workspace member')); ?></strong>
                                        <span style="color:#64748b;font-size:.78rem;"><?php echo htmlspecialchars((string) ($member['email'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($member['role_slug'] ?? 'member')); ?></span>
                                    </td>
                                    <td style="padding:.65rem;border-bottom:1px solid #f1f5f9;color:<?php echo !empty($member['clarity_journey_ready']) ? '#166534' : '#9a3412'; ?>;font-weight:800;">
                                        <?php echo !empty($member['clarity_journey_ready']) ? 'Complete' : 'Needs Journey'; ?>
                                    </td>
                                    <td style="padding:.65rem;border-bottom:1px solid #f1f5f9;color:#475569;">
                                        <?php echo !empty($member['personal_strategy_refinement_ready']) ? 'Saved' : 'Optional'; ?>
                                    </td>
                                    <td style="padding:.65rem;border-bottom:1px solid #f1f5f9;"><a class="btn-premium-secondary" href="<?php echo htmlspecialchars($setupPath); ?>" style="text-decoration:none;">Open strategy</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="my_brief"<?php echo $panelAttr('my_brief'); ?>>
                <h3>Personal Strategy</h3>
                <p><?php echo $clarityJourneyReady ? 'Clarity Journey is the AI Coach foundation. Add these optional notes only when your personal lens differs from the saved Journey.' : 'Complete Clarity Journey first. Personal strategy notes are optional and become useful after the shared foundation is complete.'; ?></p>
                <?php if ($optionalPersonalMissing !== []): ?>
                    <div class="marketplace-overview-block" style="margin-top:.75rem;">
                        <h4>Optional refinements not saved yet</h4>
                        <p><?php echo htmlspecialchars(implode(', ', array_map(static fn(array $item): string => (string) ($item['label'] ?? $item['field'] ?? 'Context'), $optionalPersonalMissing))); ?></p>
                    </div>
                <?php endif; ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <div class="marketplace-form-grid">
                        <?php $input('target_market_focus', 'Target market focus', (string) ($strategy['target_market_focus'] ?? '')); ?>
                        <?php $textarea('market_view', 'Market view', (string) ($strategy['market_view'] ?? ''), 'Optional: what is changing in your market or segment?'); ?>
                        <?php $textarea('strategy_hypothesis', 'Strategy to test', (string) ($strategy['strategy_hypothesis'] ?? ''), 'Optional: what approach do you want AI Coach to pressure-test?'); ?>
                        <?php $textarea('draft_voice_notes', 'Personal voice notes', (string) ($strategy['draft_voice_notes'] ?? ''), 'Optional: phrases, constraints, or style notes for AI drafts.'); ?>
                    </div>
                    <div class="marketplace-detail-actions" style="margin-top:1rem;">
                        <button class="btn-premium-primary" type="submit" name="skill_action" value="save_ai_coach_personal_brief">Save Optional Strategy</button>
                        <a class="btn-premium-secondary" href="startup_journey.php" style="text-decoration:none;">Open Clarity Journey</a>
                        <?php if ($recommendationsReady): ?><a class="btn-premium-secondary" href="dashboard.php#ai-coach" style="text-decoration:none;">Open dashboard Coach</a><?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="pairings"<?php echo $panelAttr('pairings'); ?>>
                <h3>Pairings</h3>
                <p>AI Coach orchestrates the modules that already own deeper context. Pair it with the skills that reflect how the workspace sells, markets, communicates, and operates.</p>
                <ul><?php foreach ($pairings as $pairing): ?><li><?php echo htmlspecialchars((string) $pairing); ?></li><?php endforeach; ?></ul>
                <?php if ($examples !== []): ?>
                    <div class="marketplace-overview-block" style="margin-top:.85rem;">
                        <h4>Example guidance</h4>
                        <ul><?php foreach ($examples as $example): ?><li><?php echo htmlspecialchars((string) $example); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=lean_canvas" style="text-decoration:none;">Lean Canvas</a>
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=marketing_pro" style="text-decoration:none;">Campaign Manager</a>
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=email_assistant" style="text-decoration:none;">Email Assistant</a>
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=calendar_meetings" style="text-decoration:none;">Calendar & Meetings</a>
                    <a class="btn-premium-secondary" href="workspace_skills.php?module=finance" style="text-decoration:none;">Finance</a>
                </div>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="smart_templates"<?php echo $panelAttr('smart_templates'); ?>>
                <h3>Smart Templates</h3>
                <?php
                $smartReadiness = (array) ($smart['readiness'] ?? []);
                $smartMissing = (array) ($smartReadiness['missing_requirements'] ?? []);
                $smartReady = !empty($smart['is_ready']);
                $smartHasActiveSet = !empty($smart['has_active_set']);
                $smartActiveSet = (array) ($smart['active_set'] ?? []);
                ?>
                <?php if (!empty($smart['error'])): ?>
                    <p style="color:#b91c1c;"><?php echo htmlspecialchars((string) $smart['error']); ?></p>
                <?php elseif ($smartReady): ?>
                    <p style="color:#166534;font-weight:700;"><?php echo $smartHasActiveSet ? 'An active Smart Template pack is available.' : 'Context is ready for Smart Template generation.'; ?></p>
                    <?php if ($smartHasActiveSet): ?><p>Email templates: <?php echo (int) ($smartActiveSet['email_template_count'] ?? 0); ?>; Workflow templates: <?php echo (int) ($smartActiveSet['workflow_template_count'] ?? 0); ?></p><?php endif; ?>
                <?php else: ?>
                    <p style="color:#9a3412;font-weight:700;">Finish these before generation:</p>
                    <ul><?php foreach ($smartMissing as $missing): ?><li><?php echo htmlspecialchars((string) ($missing['label'] ?? 'Missing context')); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                    <a class="btn-premium-secondary" href="email_templates.php" style="text-decoration:none;">Email Templates</a>
                    <a class="btn-premium-secondary" href="workflow_templates.php" style="text-decoration:none;">Workflow Templates</a>
                </div>
                <?php if ($canManage): ?>
                    <form method="POST" class="marketplace-setup-form" style="margin-top:.75rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <button class="btn-premium-primary" type="submit" name="skill_action" value="generate_smart_templates" <?php echo $smartReady ? '' : 'disabled'; ?>><?php echo $smartHasActiveSet ? 'Regenerate Smart Template Pack' : 'Generate Smart Template Pack'; ?></button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="tests"<?php echo $panelAttr('tests'); ?>>
                <h3>Tests</h3>
                <p>Use this to confirm Marketplace setup, readiness, and dashboard launch behavior after changing workspace or personal context.</p>
                <form method="POST" class="marketplace-detail-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <button class="btn-premium-primary" type="submit" name="skill_action" value="run_marketplace_readiness_check" <?php echo $installed ? '' : 'disabled'; ?>>Run AI Coach readiness check</button>
                    <?php if ($recommendationsReady): ?><a class="btn-premium-secondary" href="dashboard.php#ai-coach" style="text-decoration:none;">Open dashboard Coach</a><?php endif; ?>
                </form>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                <h3>Activity</h3>
                <?php if ($isSuperAdmin): ?><div class="marketplace-detail-actions" style="margin-bottom:.75rem;"><a class="btn-premium-secondary" href="ai_automation_diagnostics.php" style="text-decoration:none;">AI diagnostics</a></div><?php endif; ?>
                <?php marketplaceRenderSetupActivity($events); ?>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceCommunicationStatusClass')) {
    function marketplaceCommunicationStatusClass(array $health): string
    {
        $status = (string) ($health['status'] ?? '');
        return match ($status) {
            'ready' => 'is-installed',
            'warning' => 'is-warning',
            default => '',
        };
    }
}

if (!function_exists('marketplaceRenderSettingsTabInput')) {
    function marketplaceRenderSettingsTabInput(string $settingsTab): void
    {
        $settingsTab = trim($settingsTab);
        if ($settingsTab === '') {
            return;
        }

        echo '<input type="hidden" name="tab" value="' . htmlspecialchars($settingsTab) . '">';
    }
}

if (!function_exists('marketplaceRenderCommunicationStatusDot')) {
    function marketplaceRenderCommunicationStatusDot(array $health): void
    {
        $status = (string) ($health['status'] ?? '');
        $ready = $status === 'ready';
        $warning = $status === 'warning';
        $label = trim((string) ($health['label'] ?? ($ready ? 'Connected' : ($warning ? 'Sending ready, inbox not connected' : 'Not connected'))));
        $label = $label !== '' ? $label : ($ready ? 'Connected' : ($warning ? 'Sending ready, inbox not connected' : 'Not connected'));
        ?>
        <span class="marketplace-status-dot <?php echo $ready ? 'is-ready' : ($warning ? 'is-warning' : 'is-not-ready'); ?>" title="<?php echo htmlspecialchars($label); ?>" aria-label="<?php echo htmlspecialchars($label); ?>">
            <span class="marketplace-status-dot-mark" aria-hidden="true"></span>
            <span class="marketplace-status-dot-label"><?php echo htmlspecialchars($label); ?></span>
        </span>
        <?php
    }
}

if (!function_exists('marketplaceCommunicationChannelKeyForRole')) {
    function marketplaceCommunicationChannelKeyForRole(string $roleKey): string
    {
        return match ($roleKey) {
            'nurture' => 'nurture_email',
            'assistant' => 'assistant_email',
            default => 'outreach_email',
        };
    }
}

if (!function_exists('marketplaceRenderCommunicationSettingsInUse')) {
    function marketplaceRenderCommunicationSettingsInUse(array $items): void
    {
        if ($items === []) {
            echo '<div class="marketplace-communication-in-use"><h4>Settings in use</h4><p>No channel settings are active yet.</p></div>';
            return;
        }
        ?>
        <div class="marketplace-communication-in-use">
            <h4>Settings in use</h4>
            <dl>
                <?php foreach ($items as $key => $item): ?>
                    <?php
                    $label = is_array($item) ? (string) ($item['label'] ?? $key) : (string) $key;
                    $value = is_array($item) ? (string) ($item['value'] ?? '') : (string) $item;
                    $value = trim($value) !== '' ? $value : 'Not set';
                    ?>
                    <div>
                        <dt><?php echo htmlspecialchars($label); ?></dt>
                        <dd><?php echo htmlspecialchars($value); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderCommunicationHealthNotes')) {
    function marketplaceRenderCommunicationHealthNotes(array $health): void
    {
        $items = array_values(array_unique(array_merge(
            array_map('strval', (array) ($health['issues'] ?? [])),
            array_map('strval', (array) ($health['actions'] ?? []))
        )));
        $items = array_slice(array_filter($items, static fn(string $item): bool => trim($item) !== ''), 0, 4);
        if ($items === []) {
            return;
        }
        echo '<ul class="marketplace-communication-health-notes">';
        foreach ($items as $item) {
            echo '<li>' . htmlspecialchars($item) . '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('marketplaceRenderCommunicationTestPanel')) {
    function marketplaceRenderCommunicationTestPanel(string $channelKey, string $label, array $health, string $csrf, bool $canManage, ?string $moduleKey = null, string $settingsTab = ''): void
    {
        $moduleKey = $moduleKey ?: WorkspaceSkillCatalogService::PLUGIN_EMAIL;
        $disabled = $canManage ? '' : ' disabled';
        ?>
        <div class="marketplace-communication-test-panel">
            <div class="marketplace-communication-panel-head">
                <h4>Channel test</h4>
                <?php if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL): ?>
                    <?php marketplaceRenderCommunicationStatusDot($health); ?>
                <?php else: ?>
                    <span class="marketplace-status <?php echo marketplaceCommunicationStatusClass($health); ?>"><?php echo htmlspecialchars((string) ($health['label'] ?? 'Not connected')); ?></span>
                <?php endif; ?>
            </div>
            <?php marketplaceRenderCommunicationHealthNotes($health); ?>
            <form method="POST" class="marketplace-detail-actions marketplace-communication-test-actions">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                <input type="hidden" name="skill_action" value="run_communication_channel_test">
                <input type="hidden" name="communication_test_channel" value="<?php echo htmlspecialchars($channelKey); ?>">
                <input type="hidden" name="communication_setup_tab" value="<?php echo htmlspecialchars($channelKey); ?>">
                <button class="btn-premium-secondary" type="submit"<?php echo $disabled; ?>>Run <?php echo htmlspecialchars($label); ?> test</button>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderCommunicationEmailForm')) {
    function marketplaceRenderCommunicationEmailForm(array $role, string $csrf, bool $canManage, ?string $moduleKey = null): void
    {
        $moduleKey = $moduleKey ?: WorkspaceSkillCatalogService::PLUGIN_EMAIL;
        $key = (string) ($role['key'] ?? '');
        $label = (string) ($role['label'] ?? 'Email account');
        $settings = (array) ($role['settings'] ?? []);
        $health = (array) ($role['health'] ?? []);
        $channelKey = (string) ($role['health_key'] ?? marketplaceCommunicationChannelKeyForRole($key));
        $disabled = $canManage ? '' : ' disabled';
        $placeholder = static fn(bool $saved, string $empty): string => $saved ? 'Saved password' : $empty;
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4><?php echo htmlspecialchars($label); ?> Settings</h4>
                <?php marketplaceRenderCommunicationStatusDot($health); ?>
            </div>
            <form method="POST" class="marketplace-setup-form marketplace-communication-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                <input type="hidden" name="skill_action" value="save_communication_setup">
                <input type="hidden" name="communication_channel" value="email">
                <input type="hidden" name="email_role" value="<?php echo htmlspecialchars($key); ?>">
                <input type="hidden" name="communication_setup_tab" value="<?php echo htmlspecialchars($channelKey); ?>">
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field">From email <input type="email" name="from_email" value="<?php echo htmlspecialchars((string) ($settings['from_email'] ?? '')); ?>" placeholder="<?php echo htmlspecialchars($key === 'assistant' ? 'assistant@example.com' : $key . '@example.com'); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">From name <input type="text" name="from_name" value="<?php echo htmlspecialchars((string) ($settings['from_name'] ?? '')); ?>" placeholder="<?php echo htmlspecialchars($label); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">SMTP host <input type="text" name="smtp_host" value="<?php echo htmlspecialchars((string) ($settings['smtp_host'] ?? '')); ?>" placeholder="smtp.example.com"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">SMTP port <input type="number" name="smtp_port" value="<?php echo htmlspecialchars((string) ($settings['smtp_port'] ?? '587')); ?>" min="1" max="65535"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">SMTP username <input type="text" name="smtp_username" value="<?php echo htmlspecialchars((string) ($settings['smtp_username'] ?? '')); ?>" autocomplete="username"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">SMTP password <input type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($placeholder(!empty($settings['smtp_password_saved']), 'App password')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">SMTP encryption
                        <select name="smtp_encryption"<?php echo $disabled; ?>>
                            <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $value => $text): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($settings['smtp_encryption'] ?? 'tls') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($text); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="marketplace-form-field">IMAP host <input type="text" name="imap_host" value="<?php echo htmlspecialchars((string) ($settings['imap_host'] ?? '')); ?>" placeholder="imap.example.com"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">IMAP port <input type="number" name="imap_port" value="<?php echo htmlspecialchars((string) ($settings['imap_port'] ?? '993')); ?>" min="1" max="65535"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">IMAP username <input type="text" name="imap_username" value="<?php echo htmlspecialchars((string) ($settings['imap_username'] ?? '')); ?>" autocomplete="username"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">IMAP password <input type="password" name="imap_password" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($placeholder(!empty($settings['imap_password_saved']), 'App password')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">IMAP encryption
                        <select name="imap_encryption"<?php echo $disabled; ?>>
                            <?php foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'] as $value => $text): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($settings['imap_encryption'] ?? 'ssl') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($text); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="marketplace-form-field">IMAP folder <input type="text" name="imap_folder" value="<?php echo htmlspecialchars((string) ($settings['imap_folder'] ?? 'INBOX')); ?>"<?php echo $disabled; ?>></label>
                </div>
                <div class="marketplace-communication-inline-actions">
                    <label class="marketplace-form-check"><input type="checkbox" name="imap_enabled" <?php echo !empty($settings['imap_enabled']) ? 'checked' : ''; ?><?php echo $disabled; ?>> Enable IMAP</label>
                    <button class="btn-premium-primary" type="submit"<?php echo $disabled; ?>>Save <?php echo htmlspecialchars($label); ?></button>
                </div>
            </form>
            <?php if ($canManage && in_array($key, ['outreach', 'nurture'], true)): ?>
                <form method="POST" class="marketplace-setup-form marketplace-communication-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <input type="hidden" name="skill_action" value="disconnect_communication_email">
                    <input type="hidden" name="email_role" value="<?php echo htmlspecialchars($key); ?>">
                    <button class="btn-premium-secondary" type="submit">Disconnect and clear <?php echo htmlspecialchars($label); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderCommunicationWhatsAppForm')) {
    function marketplaceRenderCommunicationWhatsAppForm(array $ctx, string $csrf, bool $canManage, ?string $moduleKey = null): void
    {
        $moduleKey = $moduleKey ?: WorkspaceSkillCatalogService::PLUGIN_WHATSAPP;
        $settings = (array) ($ctx['settings'] ?? []);
        $settingsTab = (string) ($ctx['settings_tab'] ?? '');
        $disabled = $canManage ? '' : ' disabled';
        ?>
        <div class="marketplace-communication-settings">
            <form method="POST" class="marketplace-setup-form marketplace-communication-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                <input type="hidden" name="skill_action" value="save_communication_setup">
                <input type="hidden" name="communication_channel" value="whatsapp">
                <input type="hidden" name="communication_setup_tab" value="manual">
                <input type="hidden" name="connection_mode" value="self_managed">
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field">Phone number ID <input type="text" name="phone_number_id" value="<?php echo htmlspecialchars((string) ($settings['phone_number_id'] ?? '')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">Display phone number <input type="text" name="display_phone_number" value="<?php echo htmlspecialchars((string) ($settings['display_phone_number'] ?? '')); ?>" placeholder="+254700000000"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">Access token <input type="password" name="access_token" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['access_token_saved']) ? 'Saved token' : 'Meta access token'; ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">Verified name <input type="text" name="verified_name" value="<?php echo htmlspecialchars((string) ($settings['verified_name'] ?? '')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">WABA ID <input type="text" name="whatsapp_business_account_id" value="<?php echo htmlspecialchars((string) ($settings['whatsapp_business_account_id'] ?? '')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field">Meta business ID <input type="text" name="meta_business_id" value="<?php echo htmlspecialchars((string) ($settings['meta_business_id'] ?? '')); ?>"<?php echo $disabled; ?>></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Notes <textarea name="notes" rows="2"<?php echo $disabled; ?>><?php echo htmlspecialchars((string) ($settings['notes'] ?? '')); ?></textarea></label>
                </div>
                <div class="marketplace-communication-inline-actions">
                    <button class="btn-premium-primary" type="submit"<?php echo $disabled; ?>>Save WhatsApp settings</button>
                </div>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderEmailChannelSetup')) {
    function marketplaceRenderEmailChannelSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_EMAIL;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'outreach_email'));
        $readiness = (array) ($ctx['readiness'] ?? []);
        $emailRoles = array_values(array_filter(
            (array) ($ctx['email_roles'] ?? []),
            static fn(array $role): bool => in_array((string) ($role['key'] ?? ''), ['outreach', 'nurture'], true)
        ));
        $health = (array) ($ctx['health'] ?? []);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $canManage = !empty($ctx['can_manage']);
        marketplaceRenderSetupTabs($moduleKey, $activeTab);
        ?>
        <div class="marketplace-setup-tab-panels" data-marketplace-setup-panels>
            <?php foreach ($emailRoles as $role): ?>
                <?php
                $role = (array) $role;
                $roleTab = (string) ($role['health_key'] ?? marketplaceCommunicationChannelKeyForRole((string) ($role['key'] ?? '')));
                $roleLabel = (string) ($role['label'] ?? 'Email account');
                $roleHealth = (array) ($role['health'] ?? []);
                ?>
                <section class="marketplace-setup-panel" data-marketplace-setup-panel="<?php echo htmlspecialchars($roleTab); ?>" <?php echo $activeTab === $roleTab ? '' : 'hidden'; ?>>
                    <h3><?php echo htmlspecialchars($roleLabel); ?></h3>
                    <div class="marketplace-communication-settings-list">
                        <?php marketplaceRenderCommunicationEmailForm($role, $csrf, $canManage, $moduleKey); ?>
                        <?php marketplaceRenderCommunicationTestPanel($roleTab, $roleLabel, $roleHealth, $csrf, $canManage, $moduleKey); ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppWebhookSetup')) {
    function marketplaceRenderWhatsAppWebhookSetup(array $whatsapp, bool $canManage): void
    {
        $settings = (array) ($whatsapp['settings'] ?? []);
        $health = (array) ($whatsapp['health'] ?? []);
        $callbackUrl = (string) ($settings['webhook_callback_url'] ?? '');
        $verifyToken = (string) ($settings['webhook_verify_token'] ?? '');
        $webhookToken = (string) ($settings['webhook_token'] ?? '');
        if ($webhookToken === '' || !str_contains($callbackUrl, '?w=')) {
            $callbackUrl = '';
        }
        $verifyTokenSaved = $verifyToken !== '';
        $webhookReady = $callbackUrl !== '' && $webhookToken !== '' && $verifyTokenSaved;
        $lastStatus = trim((string) ($settings['webhook_last_status'] ?? ''));
        $lastVerified = trim((string) ($settings['webhook_verified_at'] ?? ''));
        $lastError = trim((string) ($settings['webhook_last_error'] ?? ''));
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>Workspace Webhook</h4>
                <span class="marketplace-status <?php echo $webhookReady ? 'is-installed' : ''; ?>"><?php echo $webhookReady ? 'Ready to paste in Meta' : 'Save manual setup first'; ?></span>
            </div>
            <div class="marketplace-form-grid">
                <label class="marketplace-form-field marketplace-form-field-wide">Callback URL
                    <input type="text" value="<?php echo htmlspecialchars($callbackUrl); ?>" readonly>
                    <button type="button" class="btn-premium-secondary marketplace-copy-button" data-marketplace-copy-value="<?php echo htmlspecialchars($callbackUrl); ?>"<?php echo $callbackUrl !== '' ? '' : ' disabled'; ?>>Copy URL</button>
                </label>
                <label class="marketplace-form-field marketplace-form-field-wide">Verify token
                    <input type="text" value="<?php echo htmlspecialchars($verifyToken); ?>" readonly>
                    <button type="button" class="btn-premium-secondary marketplace-copy-button" data-marketplace-copy-value="<?php echo htmlspecialchars($verifyToken); ?>"<?php echo $verifyToken !== '' ? '' : ' disabled'; ?>>Copy token</button>
                </label>
                <label class="marketplace-form-field">Verification
                    <input type="text" value="<?php echo htmlspecialchars($lastVerified !== '' ? $lastVerified : 'Not verified yet'); ?>" readonly>
                </label>
                <label class="marketplace-form-field">Last webhook event
                    <input type="text" value="<?php echo htmlspecialchars($lastStatus !== '' ? $lastStatus : 'No event yet'); ?>" readonly>
                </label>
            </div>
            <div class="marketplace-setup-checks-compact">
                <span class="marketplace-setup-check-pill <?php echo $webhookToken !== '' ? 'is-ready' : 'is-needed'; ?>">Workspace URL</span>
                <span class="marketplace-setup-check-pill <?php echo $verifyTokenSaved ? 'is-ready' : 'is-needed'; ?>">Verify token</span>
                <span class="marketplace-setup-check-pill <?php echo $lastVerified !== '' ? 'is-ready' : 'is-needed'; ?>">Meta verified</span>
            </div>
            <ol class="marketplace-communication-steps">
                <li>Copy this workspace callback URL and verify token into the Meta webhook settings for this number.</li>
                <li>Subscribe to the messages field after Meta verifies the callback.</li>
                <li>Send one inbound test message and confirm it appears in this workspace.</li>
            </ol>
            <?php if (!$webhookReady): ?>
                <p class="marketplace-communication-health-notes">Save the manual Phone number ID and access token first. The workspace URL and verify token are generated when settings are saved.</p>
            <?php endif; ?>
            <?php if ($lastError !== ''): ?>
                <p class="marketplace-communication-health-notes"><?php echo htmlspecialchars($lastError); ?></p>
            <?php endif; ?>
            <p class="marketplace-communication-health-notes" data-whatsapp-copy-result></p>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppMigrationSetup')) {
    function marketplaceRenderWhatsAppMigrationSetup(array $ctx): void
    {
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        $health = (array) ($whatsapp['health'] ?? []);
        $status = (array) ($ctx['migration_status'] ?? []);
        $steps = array_slice((array) ($status['steps'] ?? []), -5);
        $latest = (array) ($status['latest'] ?? []);
        $hasNumber = trim((string) ($settings['phone_number_id'] ?? '')) !== '';
        $hasToken = !empty($settings['access_token_saved']);
        $canManage = !empty($ctx['can_manage']);
        $onPremConfigured = trim((string) ($_ENV['WHATSAPP_ONPREM_API_URL'] ?? '')) !== '';
        $metadataDisabled = $canManage && $onPremConfigured ? '' : ' disabled';
        $cloudActionsDisabled = $canManage && $hasNumber && $hasToken ? '' : ' disabled';
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>Number Registration And Migration</h4>
                <span class="marketplace-status <?php echo marketplaceCommunicationStatusClass($health); ?>"><?php echo $hasNumber && $hasToken ? 'Workspace credentials ready' : 'Manual setup required'; ?></span>
            </div>
            <div class="marketplace-communication-status-row">
                <span>Latest workspace event</span>
                <strong><?php echo htmlspecialchars((string) ($latest['step'] ?? 'No migration history yet')); ?></strong>
                <?php if (!empty($latest['created_at'])): ?>
                    <small><?php echo htmlspecialchars((string) $latest['created_at']); ?></small>
                <?php endif; ?>
            </div>
            <?php if (!$hasNumber || !$hasToken): ?>
                <p class="marketplace-communication-health-notes">
                    Save a manual phone number ID and access token before Cloud registration, health checks, or deregistration.
                </p>
            <?php endif; ?>
            <form class="marketplace-setup-form marketplace-communication-form" data-whatsapp-migration-form="register_number">
                <h4>Cloud registration</h4>
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field">Six digit PIN <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"<?php echo $cloudActionsDisabled; ?>></label>
                    <label class="marketplace-form-field">Backup password <input type="password" name="password" autocomplete="new-password"<?php echo $cloudActionsDisabled; ?>></label>
                    <label class="marketplace-form-field">Data localization region <input type="text" name="data_localization_region" maxlength="2" placeholder="KE"<?php echo $cloudActionsDisabled; ?>></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Backup metadata <textarea name="metadata" rows="3"<?php echo $cloudActionsDisabled; ?>></textarea></label>
                </div>
                <div class="marketplace-communication-inline-actions">
                    <button class="btn-premium-primary" type="submit"<?php echo $cloudActionsDisabled; ?>>Register saved number</button>
                    <button class="btn-premium-secondary" type="button" data-whatsapp-migration-health<?php echo $cloudActionsDisabled; ?>>Check Cloud health</button>
                </div>
            </form>
            <details class="marketplace-communication-details">
                <summary>On-Prem migration metadata</summary>
                <?php if (!$onPremConfigured): ?>
                    <p class="marketplace-communication-health-notes">Configure WHATSAPP_ONPREM_API_URL to generate backup metadata here.</p>
                <?php endif; ?>
                <form class="marketplace-setup-form marketplace-communication-form" data-whatsapp-migration-form="generate_metadata">
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Backup password <input type="password" name="password" autocomplete="new-password" minlength="8"<?php echo $metadataDisabled; ?>></label>
                    </div>
                    <div class="marketplace-communication-inline-actions">
                        <button class="btn-premium-secondary" type="submit"<?php echo $metadataDisabled; ?>>Generate metadata</button>
                    </div>
                </form>
            </details>
            <details class="marketplace-communication-details">
                <summary>Advanced deregistration</summary>
                <form class="marketplace-setup-form marketplace-communication-form" data-whatsapp-migration-form="deregister_number">
                    <p class="marketplace-communication-health-notes">Deregistration interrupts the saved number on Cloud API. Type DEREGISTER to confirm.</p>
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Confirmation <input type="text" name="confirm_text" autocomplete="off"<?php echo $cloudActionsDisabled; ?>></label>
                    </div>
                    <div class="marketplace-communication-inline-actions">
                        <button class="btn-premium-secondary" type="submit"<?php echo $cloudActionsDisabled; ?>>Deregister number</button>
                    </div>
                </form>
            </details>
            <div class="marketplace-communication-health-notes" data-whatsapp-migration-result>
                <?php if ($latest !== []): ?>
                    Latest migration event: <?php echo htmlspecialchars((string) ($latest['step'] ?? '')); ?>
                <?php else: ?>
                    No workspace migration history yet.
                <?php endif; ?>
            </div>
            <?php if ($steps !== []): ?>
                <div class="marketplace-migration-history">
                    <?php foreach ($steps as $step): ?>
                        <div class="marketplace-communication-status-row">
                            <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($step['step'] ?? 'Migration step')))); ?></span>
                            <strong><?php echo !empty($step['success']) ? 'Completed' : 'Failed'; ?></strong>
                            <?php if (!empty($step['created_at'])): ?>
                                <small><?php echo htmlspecialchars((string) $step['created_at']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($step['error_message'])): ?>
                                <small><?php echo htmlspecialchars((string) $step['error_message']); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppEmbeddedSignupSetup')) {
    function marketplaceRenderWhatsAppEmbeddedSignupSetup(array $ctx): void
    {
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        $canManage = !empty($ctx['can_manage']);
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>Meta Embedded Signup</h4>
                <span class="marketplace-status ready">Configured</span>
            </div>
            <?php marketplaceRenderCommunicationSettingsInUse([
                ['label' => 'Meta app ID', 'value' => (string) ($settings['meta_app_id'] ?? '')],
                ['label' => 'Embedded signup config', 'value' => (string) ($settings['embedded_signup_config_id'] ?? '')],
            ]); ?>
            <div class="marketplace-overview-sections" style="margin-top:.85rem;">
                <div class="marketplace-overview-block">
                    <h4>Optional shortcut</h4>
                    <p>Use this when Meta signup is available and the workspace wants Meta to return the WhatsApp Business assets automatically.</p>
                </div>
            </div>
            <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                <button type="button" class="btn-premium-primary" data-whatsapp-embedded-signup<?php echo $canManage ? '' : ' disabled'; ?>>Connect with Meta signup</button>
            </div>
            <p class="marketplace-communication-health-notes" data-whatsapp-embedded-result></p>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppConnectionChoices')) {
    function marketplaceRenderWhatsAppConnectionChoices(array $ctx): void
    {
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        $setupBaseUrl = (string) ($ctx['setup_base_url'] ?? '');
        $canManage = !empty($ctx['can_manage']);
        $mode = (string) ($settings['connection_mode'] ?? 'self_managed');
        $dualSetupEnabled = !empty($settings['dual_setup_modes_enabled']);
        $templateCenterEnabled = !empty($settings['template_center_enabled']);
        $managedBillingEnabled = !empty($settings['managed_billing_enabled']);
        $managedAvailable = !empty($settings['managed_available']);
        $managedMissing = array_values(array_filter(array_map('strval', (array) ($settings['managed_missing_configuration_labels'] ?? $settings['managed_missing_configuration'] ?? []))));
        $currency = (string) ($settings['managed_currency'] ?? 'KES');
        $balance = (float) ($settings['managed_credit_balance'] ?? 0);
        $reserved = (float) ($settings['managed_credit_reserved'] ?? 0);
        $manualSettingsUrl = preg_replace(
            '/#.*$/',
            '',
            marketplaceSetupTabUrl(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, 'manual', $setupBaseUrl)
        ) . '#manual-settings';
        ?>
        <div class="marketplace-overview-sections" style="margin:.75rem 0 1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
            <?php if ($dualSetupEnabled && $managedBillingEnabled): ?>
            <div class="marketplace-overview-block" style="border-color:#bbf7d0;background:#f7fef9;">
                <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;">
                    <h4 style="margin:0;">Managed WhatsApp</h4>
                    <span class="marketplace-status is-installed">Recommended</span>
                </div>
                <p>Click-to-connect setup with platform-controlled webhook operations and WhatsApp Credits billed through the workspace.</p>
                <div class="marketplace-setup-checks-compact" style="margin-top:.65rem;">
                    <span class="marketplace-setup-check-pill <?php echo $mode === 'platform_managed' ? 'is-ready' : 'is-needed'; ?>"><?php echo $mode === 'platform_managed' ? 'Active mode' : 'Available path'; ?></span>
                    <span class="marketplace-setup-check-pill <?php echo $managedAvailable ? 'is-ready' : 'is-needed'; ?>"><?php echo $managedAvailable ? 'Platform ready' : 'Platform pending'; ?></span>
                </div>
                <?php if ($mode === 'platform_managed'): ?>
                    <p class="marketplace-communication-health-notes"><?php echo htmlspecialchars($currency . ' ' . number_format($balance, 2)); ?> available, <?php echo htmlspecialchars(number_format($reserved, 2)); ?> reserved.</p>
                <?php elseif (!$managedAvailable): ?>
                    <p class="marketplace-communication-health-notes">Managed WhatsApp is not available yet.<?php echo $managedMissing !== [] ? ' Missing: ' . htmlspecialchars(implode(', ', $managedMissing)) . '.' : ''; ?></p>
                <?php endif; ?>
                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin-top:.75rem;">
                    <button type="button" class="btn-premium-primary" data-whatsapp-managed-start<?php echo $canManage && $managedAvailable ? '' : ' disabled'; ?>>Connect managed WhatsApp</button>
                    <?php if ($templateCenterEnabled): ?><a class="btn-premium-secondary" href="whatsapp_templates.php" style="text-decoration:none;">Templates</a><?php endif; ?>
                </div>
                <p class="marketplace-communication-health-notes" data-whatsapp-managed-result></p>
            </div>
            <?php endif; ?>
            <div class="marketplace-overview-block">
                <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;">
                    <h4 style="margin:0;">Connect My Own Meta Account</h4>
                    <span class="marketplace-status <?php echo $mode === 'self_managed' ? 'is-installed' : ''; ?>">Advanced</span>
                </div>
                <p>Use workspace-owned Meta credentials and billing while CRM keeps inbox, campaigns, templates, and analytics in one runtime.</p>
                <div class="marketplace-setup-checks-compact" style="margin-top:.65rem;">
                    <span class="marketplace-setup-check-pill <?php echo $mode === 'self_managed' ? 'is-ready' : 'is-needed'; ?>"><?php echo $mode === 'self_managed' ? 'Active mode' : 'Switch required'; ?></span>
                    <span class="marketplace-setup-check-pill <?php echo !empty($settings['access_token_saved']) ? 'is-ready' : 'is-needed'; ?>">Workspace credentials</span>
                </div>
                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin-top:.75rem;">
                    <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($manualSettingsUrl); ?>" style="text-decoration:none;">Edit manual credentials</a>
                    <?php if (!empty($settings['embedded_signup_configured'])): ?>
                        <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(marketplaceSetupTabUrl(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, 'embedded_signup', $setupBaseUrl)); ?>" style="text-decoration:none;">Meta signup</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppHealthPanel')) {
    function marketplaceRenderWhatsAppHealthPanel(array $whatsapp): void
    {
        $settings = (array) ($whatsapp['settings'] ?? []);
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>Channel Health</h4>
                <span class="marketplace-status <?php echo trim((string) ($settings['connection_status'] ?? '')) === 'connected' ? 'is-installed' : ''; ?>"><?php echo htmlspecialchars((string) ($settings['connection_status'] ?? 'Not connected')); ?></span>
            </div>
            <?php marketplaceRenderCommunicationSettingsInUse([
                ['label' => 'Mode', 'value' => (string) ($settings['connection_mode_label'] ?? '')],
                ['label' => 'Phone number', 'value' => (string) ($settings['display_phone_number'] ?? '')],
                ['label' => 'Phone number ID', 'value' => (string) ($settings['phone_number_id'] ?? '')],
                ['label' => 'WABA', 'value' => (string) ($settings['whatsapp_business_account_id'] ?? '')],
                ['label' => 'Webhook status', 'value' => (string) (($settings['webhook_last_status'] ?? '') ?: 'No event yet')],
                ['label' => 'Credentials', 'value' => !empty($settings['access_token_saved']) ? 'Saved' : 'Missing'],
                ['label' => 'Template center', 'value' => !empty($settings['template_center_enabled']) ? (string) ($settings['template_center_url'] ?? 'whatsapp_templates.php') : 'Disabled'],
                ['label' => 'Last provider error', 'value' => (string) (($settings['last_error'] ?? '') ?: ($settings['managed_last_health_error'] ?? ''))],
            ]); ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppTemplatesPanel')) {
    function marketplaceRenderWhatsAppTemplatesPanel(array $ctx): void
    {
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        if (empty($settings['template_center_enabled'])) {
            ?>
            <div class="marketplace-communication-settings">
                <div class="marketplace-communication-panel-head">
                    <h4>Template Center</h4>
                    <span class="marketplace-status">Disabled</span>
                </div>
                <p class="marketplace-communication-health-notes">WhatsApp Template Center is not enabled for this workspace.</p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>Template Center</h4>
                <span class="marketplace-status is-installed">Both modes</span>
            </div>
            <p class="marketplace-communication-health-notes">Create drafts, submit templates for Meta approval, sync provider status, and reuse approved templates in compose and bulk send.</p>
            <div class="marketplace-detail-actions" style="justify-content:flex-start;margin-top:.85rem;">
                <a class="btn-premium-primary" href="<?php echo htmlspecialchars((string) ($settings['template_center_url'] ?? 'whatsapp_templates.php')); ?>" style="text-decoration:none;">Open Template Center</a>
                <button type="button" class="btn-premium-secondary" data-whatsapp-template-sync>Sync template statuses</button>
            </div>
            <p class="marketplace-communication-health-notes" data-whatsapp-template-result></p>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppBillingPanel')) {
    function marketplaceRenderWhatsAppBillingPanel(array $ctx): void
    {
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        if (empty($settings['managed_billing_enabled'])) {
            ?>
            <div class="marketplace-communication-settings">
                <div class="marketplace-communication-panel-head">
                    <h4>WhatsApp Credits</h4>
                    <span class="marketplace-status">Disabled</span>
                </div>
                <p class="marketplace-communication-health-notes">Managed WhatsApp billing is not enabled for this workspace. Self-managed workspaces keep Meta billing in their own account.</p>
            </div>
            <?php
            return;
        }
        $mode = (string) ($settings['connection_mode'] ?? 'self_managed');
        $currency = (string) ($settings['managed_currency'] ?? 'KES');
        $summary = (array) ($settings['credit_summary'] ?? []);
        $balance = (float) ($summary['credit_balance'] ?? $settings['managed_credit_balance'] ?? 0);
        $reserved = (float) ($summary['reserved_credits'] ?? $settings['managed_credit_reserved'] ?? 0);
        $available = (float) ($summary['available_credits'] ?? max(0, $balance - $reserved));
        ?>
        <div class="marketplace-communication-settings">
            <div class="marketplace-communication-panel-head">
                <h4>WhatsApp Credits</h4>
                <span class="marketplace-status <?php echo $mode === 'platform_managed' ? 'is-installed' : ''; ?>"><?php echo $mode === 'platform_managed' ? 'Platform billed' : 'Usage analytics only'; ?></span>
            </div>
            <?php if ($mode !== 'platform_managed'): ?>
                <p class="marketplace-communication-health-notes">Self-managed workspaces keep Meta billing in their own account. CRM records usage and delivery analytics but never debits WhatsApp Credits.</p>
            <?php else: ?>
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field">Available credits <input type="text" value="<?php echo htmlspecialchars($currency . ' ' . number_format($available, 2)); ?>" readonly></label>
                    <label class="marketplace-form-field">Balance <input type="text" value="<?php echo htmlspecialchars($currency . ' ' . number_format($balance, 2)); ?>" readonly></label>
                    <label class="marketplace-form-field">Reserved <input type="text" value="<?php echo htmlspecialchars($currency . ' ' . number_format($reserved, 2)); ?>" readonly></label>
                    <label class="marketplace-form-field">Billing status <input type="text" value="<?php echo htmlspecialchars((string) ($summary['billing_status'] ?? $settings['managed_billing_status'] ?? 'active')); ?>" readonly></label>
                    <label class="marketplace-form-field">Daily cap <input type="text" value="<?php echo htmlspecialchars($currency . ' ' . (($summary['daily_spend_cap'] ?? null) !== null ? number_format((float) $summary['daily_spend_cap'], 2) : 'Not set')); ?>" readonly></label>
                    <label class="marketplace-form-field">Auto top-up <input type="text" value="<?php echo !empty($summary['auto_topup_enabled']) ? 'Enabled' : 'Disabled'; ?>" readonly></label>
                </div>
                <p class="marketplace-communication-health-notes">Credits are reserved when queueing managed sends, debited only from provider billable delivery events, and released on failure or expiry.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppChannelScripts')) {
    function marketplaceRenderWhatsAppChannelScripts(array $ctx): void
    {
        $csrf = (string) ($ctx['csrf'] ?? '');
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $settings = (array) ($whatsapp['settings'] ?? []);
        $migrationEndpoint = function_exists('apiUrl') ? apiUrl('whatsapp/migrate.php') : '../api/whatsapp/migrate.php';
        $embeddedEndpoint = function_exists('apiUrl') ? apiUrl('whatsapp/embedded/callback.php') : '../api/whatsapp/embedded/callback.php';
        $managedStartEndpoint = function_exists('apiUrl') ? apiUrl('whatsapp/managed/start.php') : '../api/whatsapp/managed/start.php';
        $templateEndpoint = function_exists('apiUrl') ? apiUrl('whatsapp/templates/manage.php') : '../api/whatsapp/templates/manage.php';
        $embeddedConfigured = marketplaceWhatsAppEmbeddedSignupConfigured();
        ?>
        <?php if ($embeddedConfigured): ?>
            <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
        <?php endif; ?>
        <script>
        (function () {
            if (window.__marketplaceWhatsAppSetupBound) return;
            window.__marketplaceWhatsAppSetupBound = true;

            function setResult(selector, text, isError) {
                var el = document.querySelector(selector);
                if (!el) return;
                el.textContent = text || '';
                el.style.color = isError ? '#b91c1c' : '#1d4ed8';
            }

            function asJson(response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || data.success === false) {
                        throw new Error(data.error || 'Request failed.');
                    }
                    return data;
                });
            }

            document.querySelectorAll('[data-marketplace-copy-value]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var value = button.getAttribute('data-marketplace-copy-value') || '';
                    if (!value) return;
                    var copied = navigator.clipboard && navigator.clipboard.writeText
                        ? navigator.clipboard.writeText(value)
                        : new Promise(function (resolve, reject) {
                            var input = document.createElement('textarea');
                            input.value = value;
                            input.setAttribute('readonly', 'readonly');
                            input.style.position = 'fixed';
                            input.style.left = '-9999px';
                            document.body.appendChild(input);
                            input.select();
                            try {
                                document.execCommand('copy') ? resolve() : reject(new Error('Copy failed.'));
                            } catch (error) {
                                reject(error);
                            } finally {
                                document.body.removeChild(input);
                            }
                        });

                    copied.then(function () {
                        setResult('[data-whatsapp-copy-result]', 'Copied.', false);
                    }).catch(function () {
                        setResult('[data-whatsapp-copy-result]', 'Copy failed. Select the field and copy it manually.', true);
                    });
                });
            });

            document.querySelectorAll('[data-whatsapp-migration-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var action = form.getAttribute('data-whatsapp-migration-form') || '';
                    var payload = { action: action, csrf_token: <?php echo json_encode($csrf); ?> };
                    var formData = new FormData(form);
                    formData.forEach(function (value, key) {
                        payload[key] = value;
                    });
                    if (action === 'deregister_number') {
                        if (String(payload.confirm_text || '').trim() !== 'DEREGISTER') {
                            setResult('[data-whatsapp-migration-result]', 'Type DEREGISTER to confirm deregistration.', true);
                            return;
                        }
                        payload.confirm = true;
                        delete payload.confirm_text;
                    }
                    var submit = form.querySelector('button[type="submit"]');
                    var original = submit ? submit.textContent : '';
                    if (submit) {
                        submit.disabled = true;
                        submit.textContent = 'Working...';
                    }
                    fetch(<?php echo json_encode($migrationEndpoint); ?>, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': <?php echo json_encode($csrf); ?>,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(payload)
                    }).then(asJson).then(function (data) {
                        var text = data.message || 'Migration action completed.';
                        if (data.metadata) {
                            text += ' Metadata: ' + data.metadata;
                        }
                        setResult('[data-whatsapp-migration-result]', text, false);
                    }).catch(function (error) {
                        setResult('[data-whatsapp-migration-result]', error.message || 'Migration action failed.', true);
                    }).finally(function () {
                        if (submit) {
                            submit.disabled = false;
                            submit.textContent = original;
                        }
                    });
                });
            });

            document.querySelectorAll('[data-whatsapp-migration-health]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var original = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Checking...';
                    fetch(<?php echo json_encode($migrationEndpoint . '?health=1'); ?>, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(asJson).then(function (data) {
                        var health = data.health || {};
                        setResult('[data-whatsapp-migration-result]', 'Cloud health: ' + (health.health_status || health.status_color || 'checked') + '.', false);
                    }).catch(function (error) {
                        setResult('[data-whatsapp-migration-result]', error.message || 'Health check failed.', true);
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = original;
                    });
                });
            });

            document.querySelectorAll('[data-whatsapp-managed-start]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var original = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Connecting...';
                    fetch(<?php echo json_encode($managedStartEndpoint); ?>, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': <?php echo json_encode($csrf); ?>,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: <?php echo json_encode($csrf); ?>,
                            connection_mode: 'platform_managed'
                        })
                    }).then(asJson).then(function (data) {
                        setResult('[data-whatsapp-managed-result]', data.message || 'Managed WhatsApp connection started.', false);
                        window.setTimeout(function () { window.location.reload(); }, 900);
                    }).catch(function (error) {
                        setResult('[data-whatsapp-managed-result]', error.message || 'Managed WhatsApp could not be started.', true);
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = original;
                    });
                });
            });

            document.querySelectorAll('[data-whatsapp-template-sync]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var original = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Syncing...';
                    fetch(<?php echo json_encode($templateEndpoint); ?>, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': <?php echo json_encode($csrf); ?>,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: <?php echo json_encode($csrf); ?>,
                            action: 'sync'
                        })
                    }).then(asJson).then(function (data) {
                        setResult('[data-whatsapp-template-result]', 'Synced ' + ((data.templates || []).length || 0) + ' template(s).', false);
                    }).catch(function (error) {
                        setResult('[data-whatsapp-template-result]', error.message || 'Template sync failed.', true);
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = original;
                    });
                });
            });

            <?php if ($embeddedConfigured): ?>
            var latestWhatsAppSignup = {};
            window.fbAsyncInit = function () {
                FB.init({
                    appId: <?php echo json_encode((string) ($settings['meta_app_id'] ?? '')); ?>,
                    autoLogAppEvents: true,
                    xfbml: true,
                    version: 'v24.0'
                });
            };
            window.addEventListener('message', function (event) {
                if (!String(event.origin || '').match(/facebook\.com$/)) return;
                try {
                    var data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                    if (data && data.type === 'WA_EMBEDDED_SIGNUP') {
                        latestWhatsAppSignup = data.data || {};
                    }
                } catch (error) {}
            });
            document.querySelectorAll('[data-whatsapp-embedded-signup]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!window.FB || !<?php echo json_encode((string) ($settings['embedded_signup_config_id'] ?? '')); ?>) {
                        setResult('[data-whatsapp-embedded-result]', 'Meta signup is not ready yet.', true);
                        return;
                    }
                    setResult('[data-whatsapp-embedded-result]', 'Opening Meta signup...', false);
                    FB.login(function (response) {
                        if (!response || !response.authResponse) {
                            setResult('[data-whatsapp-embedded-result]', 'Meta signup was cancelled before it finished.', true);
                            return;
                        }
                        var payload = Object.assign({}, latestWhatsAppSignup || {}, response.authResponse || {});
                        payload.csrf_token = <?php echo json_encode($csrf); ?>;
                        payload.connection_mode = 'self_managed';
                        fetch(<?php echo json_encode($embeddedEndpoint); ?>, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify(payload)
                        }).then(asJson).then(function (data) {
                            setResult('[data-whatsapp-embedded-result]', data.message || 'WhatsApp number connected.', false);
                        }).catch(function (error) {
                            setResult('[data-whatsapp-embedded-result]', error.message || 'Meta signup could not be completed.', true);
                        });
                    }, {
                        config_id: <?php echo json_encode((string) ($settings['embedded_signup_config_id'] ?? '')); ?>,
                        response_type: 'code',
                        override_default_response_type: true,
                        extras: { setup: {} }
                    });
                });
            });
            <?php endif; ?>
        })();
        </script>
        <?php
    }
}

if (!function_exists('marketplaceRenderWhatsAppChannelSetup')) {
    function marketplaceRenderWhatsAppChannelSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_WHATSAPP;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'manual'));
        $readiness = (array) ($ctx['readiness'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $whatsapp = (array) ($ctx['whatsapp'] ?? []);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $canManage = !empty($ctx['can_manage']);
        $setupBaseUrl = (string) ($ctx['setup_base_url'] ?? '');
        $settingsTab = (string) ($ctx['settings_tab'] ?? '');
        $hideActivityTab = !empty($ctx['hide_activity_tab']);
        if ($hideActivityTab && $activeTab === 'activity') {
            $activeTab = 'manual';
        }
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';
        $embeddedConfigured = marketplaceWhatsAppEmbeddedSignupConfigured();
        marketplaceRenderSetupTabs($moduleKey, $activeTab, $setupBaseUrl, $hideActivityTab ? ['exclude_tabs' => ['activity']] : []);
        ?>
        <div class="marketplace-setup-tab-panels" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="manual"<?php echo $panelAttr('manual'); ?>>
                <div class="marketplace-communication-settings-list">
                    <?php marketplaceRenderWhatsAppConnectionChoices($ctx); ?>
                    <?php marketplaceRenderWhatsAppHealthPanel($whatsapp); ?>
                    <div id="manual-settings"></div>
                    <?php marketplaceRenderCommunicationWhatsAppForm(array_merge($whatsapp, ['settings_tab' => $settingsTab]), $csrf, $canManage, $moduleKey); ?>
                </div>
            </section>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="webhook"<?php echo $panelAttr('webhook'); ?>>
                <h3>Webhook</h3>
                <?php marketplaceRenderWhatsAppWebhookSetup($whatsapp, $canManage); ?>
            </section>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="templates"<?php echo $panelAttr('templates'); ?>>
                <h3>Templates</h3>
                <?php marketplaceRenderWhatsAppTemplatesPanel($ctx); ?>
            </section>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="billing"<?php echo $panelAttr('billing'); ?>>
                <h3>WhatsApp Credits</h3>
                <?php marketplaceRenderWhatsAppBillingPanel($ctx); ?>
            </section>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="migration"<?php echo $panelAttr('migration'); ?>>
                <h3>Registration And Migration</h3>
                <?php marketplaceRenderWhatsAppMigrationSetup($ctx); ?>
            </section>
            <?php if ($embeddedConfigured): ?>
                <section class="marketplace-setup-panel" data-marketplace-setup-panel="embedded_signup"<?php echo $panelAttr('embedded_signup'); ?>>
                    <h3>Meta Embedded Signup</h3>
                    <?php marketplaceRenderWhatsAppEmbeddedSignupSetup($ctx); ?>
                </section>
            <?php endif; ?>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="tests"<?php echo $panelAttr('tests'); ?>>
                <h3>Tests</h3>
                <?php marketplaceRenderCommunicationTestPanel('whatsapp', 'WhatsApp', (array) ($whatsapp['health'] ?? []), $csrf, $canManage, $moduleKey, $settingsTab); ?>
            </section>
            <?php if (!$hideActivityTab): ?>
                <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                    <h3>Activity</h3>
                    <?php marketplaceRenderSetupActivity($events); ?>
                </section>
            <?php endif; ?>
        </div>
        <?php marketplaceRenderWhatsAppChannelScripts($ctx); ?>
        <?php
    }
}

if (!function_exists('marketplaceWhatsAppAssistantHiddenInputs')) {
    function marketplaceWhatsAppAssistantHiddenInputs(array $settings, bool $enabled, array $excludeNames = []): void
    {
        $excludeLookup = array_fill_keys($excludeNames, true);
        $senderMode = (string) ($settings['sender_mode'] ?? '');
        if ($senderMode === '') {
            $senderMode = trim((string) ($settings['assistant_phone_number_id'] ?? $settings['assistant_phone_number'] ?? $settings['access_token'] ?? '')) !== ''
                ? 'custom'
                : 'workspace';
        }
        $hidden = [
            'whatsapp_assistant_enabled' => $enabled ? '1' : '',
            'whatsapp_assistant_use_custom_sender' => $senderMode === 'custom' ? '1' : '',
            'whatsapp_assistant_phone_number' => (string) ($settings['assistant_phone_number'] ?? ''),
            'whatsapp_assistant_phone_number_id' => (string) ($settings['assistant_phone_number_id'] ?? ''),
            'whatsapp_assistant_digest_enabled' => !empty($settings['digest_enabled']) ? '1' : '',
            'whatsapp_assistant_digest_time' => (string) ($settings['digest_time'] ?? '07:00'),
            'whatsapp_assistant_max_message_chars' => (string) ($settings['max_message_chars'] ?? '550'),
            'whatsapp_assistant_max_message_chunks' => (string) ($settings['max_message_chunks'] ?? '6'),
            'whatsapp_assistant_keepalive_warning_hours' => (string) ($settings['keepalive_warning_hours'] ?? '4'),
            'whatsapp_assistant_auto_reopen_enabled' => (!array_key_exists('auto_reopen_enabled', $settings) || !empty($settings['auto_reopen_enabled'])) ? '1' : '',
            'whatsapp_assistant_reopen_template_name' => (string) ($settings['reopen_template_name'] ?? ''),
            'whatsapp_assistant_reopen_template_language' => (string) ($settings['reopen_template_language'] ?? 'en_US'),
            'whatsapp_assistant_reopen_template_header_values' => (string) ($settings['reopen_template_header_values'] ?? ''),
            'whatsapp_assistant_reopen_template_body_values' => (string) ($settings['reopen_template_body_values'] ?? ''),
            'whatsapp_assistant_reopen_template_button_values' => (string) ($settings['reopen_template_button_values'] ?? ''),
        ];
        foreach (AssistantActionRuntimeConfig::ACTION_SETTING_KEYS as $settingKey) {
            $hidden['whatsapp_assistant_' . $settingKey] = !empty($settings[$settingKey]) ? '1' : '';
        }

        foreach ($hidden as $name => $value) {
            if (isset($excludeLookup[$name]) || $value === '') {
                continue;
            }
            echo '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
        }
    }
}

if (!function_exists('marketplaceRenderWhatsAppAssistantSetup')) {
    function marketplaceRenderWhatsAppAssistantSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'identity'));
        $settings = (array) ($ctx['settings'] ?? []);
        $enabled = !empty($ctx['enabled']);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $installed = !empty($ctx['installed']);
        $canManage = !empty($ctx['can_manage']);
        $readiness = (array) ($ctx['readiness'] ?? []);
        $runtime = (array) ($ctx['runtime'] ?? []);
        $authorizedNumbers = (array) ($ctx['authorized_numbers'] ?? []);
        $workspaceUsers = (array) ($ctx['workspace_users'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $setupBaseUrl = (string) ($ctx['setup_base_url'] ?? '');
        $settingsTab = (string) ($ctx['settings_tab'] ?? '');
        $hideActivityTab = !empty($ctx['hide_activity_tab']);
        if ($hideActivityTab && $activeTab === 'activity') {
            $activeTab = 'identity';
        }
        $senderMode = (string) ($settings['sender_mode'] ?? $runtime['sender_mode'] ?? '');
        if ($senderMode === '') {
            $senderMode = trim((string) ($settings['assistant_phone_number_id'] ?? $settings['assistant_phone_number'] ?? $settings['access_token'] ?? '')) !== ''
                ? 'custom'
                : 'workspace';
        }
        $usesCustomSender = $senderMode === 'custom';
        $workspaceSenderLabel = trim((string) ($runtime['workspace_phone_number'] ?? ''));
        $workspaceSenderId = trim((string) ($runtime['workspace_phone_number_id'] ?? ''));
        $customTokenSaved = array_key_exists('access_token', $settings) || !empty($runtime['custom_access_token_available']);
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';
        $actionToggles = marketplaceWhatsAppAssistantActionToggles();

        marketplaceRenderSetupTabs($moduleKey, $activeTab, $setupBaseUrl, $hideActivityTab ? ['exclude_tabs' => ['activity']] : []);
        ?>
        <div class="marketplace-setup-tab-panels" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="identity"<?php echo $panelAttr('identity'); ?>>
                <h3>Identity</h3>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo $installed ? 'Marketplace management access is required to save setup.' : 'Install WhatsApp Assistant before saving setup.'; ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <input type="hidden" name="whatsapp_assistant_setup_tab" value="identity">
                        <?php marketplaceWhatsAppAssistantHiddenInputs($settings, $enabled, ['whatsapp_assistant_enabled', 'whatsapp_assistant_use_custom_sender', 'whatsapp_assistant_phone_number', 'whatsapp_assistant_phone_number_id']); ?>
                        <label class="marketplace-form-check"><input type="checkbox" name="whatsapp_assistant_enabled" <?php echo $enabled ? 'checked' : ''; ?>> Enable WhatsApp Assistant</label>
                        <div class="marketplace-overview-block" style="margin-top:.85rem;">
                            <h4>Workspace WhatsApp sender</h4>
                            <p><?php echo $workspaceSenderId !== '' ? htmlspecialchars(trim(($workspaceSenderLabel !== '' ? $workspaceSenderLabel . ' - ' : '') . $workspaceSenderId)) : 'No workspace WhatsApp sender is connected yet.'; ?></p>
                        </div>
                        <label class="marketplace-form-check" style="margin-top:.85rem;">
                            <input type="checkbox" name="whatsapp_assistant_use_custom_sender" value="1" <?php echo $usesCustomSender ? 'checked' : ''; ?> data-whatsapp-assistant-custom-toggle>
                            Use a different assistant number
                        </label>
                        <div class="marketplace-muted-card" style="margin-top:.65rem;">
                            Custom assistant numbers must be the number connected to this workspace webhook; otherwise inbound assistant messages will be blocked by webhook metadata checks.
                        </div>
                        <div class="marketplace-form-grid" data-whatsapp-assistant-custom-fields <?php echo $usesCustomSender ? '' : 'hidden'; ?>>
                            <label class="marketplace-form-field">Assistant phone number <input type="text" name="whatsapp_assistant_phone_number" value="<?php echo htmlspecialchars((string) ($settings['assistant_phone_number'] ?? '')); ?>" placeholder="+254700000000"></label>
                            <label class="marketplace-form-field">Phone number ID <input type="text" name="whatsapp_assistant_phone_number_id" value="<?php echo htmlspecialchars((string) ($settings['assistant_phone_number_id'] ?? '')); ?>"></label>
                            <label class="marketplace-form-field">Access token <input type="password" name="whatsapp_assistant_access_token" value="" autocomplete="new-password" placeholder="<?php echo $customTokenSaved ? 'Saved token' : 'Meta access token'; ?>"></label>
                        </div>
                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                            <h4 style="margin:0 0 .55rem;color:#0f172a;">Authorized team numbers</h4>
                            <div style="display:grid;gap:.65rem;">
                                <?php $mappingRows = $authorizedNumbers !== [] ? $authorizedNumbers : [[]]; ?>
                                <?php $mappingRows[] = []; ?>
                                <?php foreach ($mappingRows as $index => $mappingRow): ?>
                                    <div style="display:grid;gap:.65rem;grid-template-columns:minmax(150px,1fr) minmax(180px,1fr) minmax(120px,.8fr) auto auto;align-items:start;padding:.75rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
                                        <label class="marketplace-form-field">Phone number <input type="text" name="assistant_mapping_phone[]" value="<?php echo htmlspecialchars((string) ($mappingRow['phone_number'] ?? '')); ?>" placeholder="+254700000000"></label>
                                        <label class="marketplace-form-field">CRM user
                                            <select name="assistant_mapping_user_id[]">
                                                <option value="">Select user</option>
                                                <?php foreach ($workspaceUsers as $assistantUser): ?>
                                                    <?php $assistantUserLabel = trim((string) (($assistantUser['first_name'] ?? '') . ' ' . ($assistantUser['last_name'] ?? ''))); ?>
                                                    <?php if ($assistantUserLabel === '') { $assistantUserLabel = (string) ($assistantUser['email'] ?? ('User #' . (int) ($assistantUser['id'] ?? 0))); } ?>
                                                    <option value="<?php echo (int) ($assistantUser['id'] ?? 0); ?>" <?php echo (int) ($mappingRow['user_id'] ?? 0) === (int) ($assistantUser['id'] ?? 0) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($assistantUserLabel . ' - ' . (string) ($assistantUser['email'] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="marketplace-form-field">Label <input type="text" name="assistant_mapping_label[]" value="<?php echo htmlspecialchars((string) ($mappingRow['label'] ?? '')); ?>" placeholder="Founder line"></label>
                                        <label class="marketplace-form-check" style="margin-top:1.7rem;"><input type="checkbox" name="assistant_mapping_is_active[<?php echo $index; ?>]" <?php echo !array_key_exists('is_active', $mappingRow) || !empty($mappingRow['is_active']) ? 'checked' : ''; ?>> Active</label>
                                        <label class="marketplace-form-check" style="margin-top:1.7rem;"><input type="checkbox" name="assistant_mapping_digest_enabled[<?php echo $index; ?>]" <?php echo !array_key_exists('digest_enabled', $mappingRow) || !empty($mappingRow['digest_enabled']) ? 'checked' : ''; ?>> Digest</label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_whatsapp_assistant_setup">Save identity</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="session"<?php echo $panelAttr('session'); ?>>
                <h3>Digest and session controls</h3>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo $installed ? 'Marketplace management access is required to save setup.' : 'Install WhatsApp Assistant before saving setup.'; ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <input type="hidden" name="whatsapp_assistant_setup_tab" value="session">
                        <?php marketplaceWhatsAppAssistantHiddenInputs($settings, $enabled, [
                            'whatsapp_assistant_digest_enabled',
                            'whatsapp_assistant_digest_time',
                            'whatsapp_assistant_max_message_chars',
                            'whatsapp_assistant_max_message_chunks',
                            'whatsapp_assistant_keepalive_warning_hours',
                            'whatsapp_assistant_auto_reopen_enabled',
                            'whatsapp_assistant_reopen_template_name',
                            'whatsapp_assistant_reopen_template_language',
                            'whatsapp_assistant_reopen_template_header_values',
                            'whatsapp_assistant_reopen_template_body_values',
                            'whatsapp_assistant_reopen_template_button_values',
                        ]); ?>
                        <div class="marketplace-form-grid">
                            <label class="marketplace-form-field">Digest time <input type="time" name="whatsapp_assistant_digest_time" value="<?php echo htmlspecialchars((string) ($settings['digest_time'] ?? '07:00')); ?>"></label>
                            <label class="marketplace-form-field">Max message chars <input type="number" name="whatsapp_assistant_max_message_chars" value="<?php echo htmlspecialchars((string) ($settings['max_message_chars'] ?? '550')); ?>" min="180"></label>
                            <label class="marketplace-form-field">Max chunks <input type="number" name="whatsapp_assistant_max_message_chunks" value="<?php echo htmlspecialchars((string) ($settings['max_message_chunks'] ?? '6')); ?>" min="2"></label>
                            <label class="marketplace-form-field">Keepalive warning hours <input type="number" name="whatsapp_assistant_keepalive_warning_hours" value="<?php echo htmlspecialchars((string) ($settings['keepalive_warning_hours'] ?? '4')); ?>" min="1"></label>
                            <label class="marketplace-form-field">Reopen template <input type="text" name="whatsapp_assistant_reopen_template_name" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_name'] ?? '')); ?>"></label>
                            <label class="marketplace-form-field">Template language <input type="text" name="whatsapp_assistant_reopen_template_language" value="<?php echo htmlspecialchars((string) ($settings['reopen_template_language'] ?? 'en_US')); ?>"></label>
                            <label class="marketplace-form-field">Header values <textarea name="whatsapp_assistant_reopen_template_header_values" rows="2"><?php echo htmlspecialchars((string) ($settings['reopen_template_header_values'] ?? '')); ?></textarea></label>
                            <label class="marketplace-form-field">Body values <textarea name="whatsapp_assistant_reopen_template_body_values" rows="3"><?php echo htmlspecialchars((string) ($settings['reopen_template_body_values'] ?? '')); ?></textarea></label>
                            <label class="marketplace-form-field">Button values <textarea name="whatsapp_assistant_reopen_template_button_values" rows="2"><?php echo htmlspecialchars((string) ($settings['reopen_template_button_values'] ?? '')); ?></textarea></label>
                        </div>
                        <div class="marketplace-chip-row">
                            <label class="marketplace-form-check"><input type="checkbox" name="whatsapp_assistant_digest_enabled" <?php echo !empty($settings['digest_enabled']) ? 'checked' : ''; ?>> Daily digest</label>
                            <label class="marketplace-form-check"><input type="checkbox" name="whatsapp_assistant_auto_reopen_enabled" <?php echo !array_key_exists('auto_reopen_enabled', $settings) || !empty($settings['auto_reopen_enabled']) ? 'checked' : ''; ?>> Auto reopen</label>
                        </div>
                        <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_whatsapp_assistant_setup">Save session controls</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="tests"<?php echo $panelAttr('tests'); ?>>
                <h3>Tests</h3>
                <p><?php echo htmlspecialchars((string) ($readiness['next_action'] ?? 'Run a readiness check before sending live tests.')); ?></p>
                <?php marketplaceRenderReadinessChecks((array) ($readiness['checks'] ?? [])); ?>
                <form method="POST" class="marketplace-setup-form" style="margin-top:.85rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                    <input type="hidden" name="whatsapp_assistant_setup_tab" value="tests">
                    <label class="marketplace-form-field" style="max-width:420px;">Authorized test number
                        <select name="whatsapp_test_recipient" <?php echo ($installed && $authorizedNumbers !== []) ? '' : 'disabled'; ?>>
                            <option value=""><?php echo $authorizedNumbers === [] ? 'No authorized numbers' : 'Select authorized number'; ?></option>
                            <?php foreach ($authorizedNumbers as $row): ?>
                                <?php $recipientLabel = trim((string) ($row['label'] ?? '')); ?>
                                <?php $recipientPhone = (string) ($row['phone_number'] ?? ''); ?>
                                <option value="<?php echo htmlspecialchars($recipientPhone); ?>"><?php echo htmlspecialchars(($recipientLabel !== '' ? $recipientLabel . ' - ' : '') . '+' . $recipientPhone); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="marketplace-detail-actions" style="justify-content:flex-start;">
                        <button class="btn-premium-primary" type="submit" name="skill_action" value="run_marketplace_readiness_check" <?php echo $installed ? '' : 'disabled'; ?>>Run readiness check</button>
                        <button class="btn-premium-secondary" type="submit" name="skill_action" value="send_whatsapp_assistant_test_digest" <?php echo ($installed && $authorizedNumbers !== []) ? '' : 'disabled'; ?>>Send live test digest</button>
                    </div>
                </form>
            </section>

            <?php if (!$hideActivityTab): ?>
                <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                    <h3>Activity</h3>
                    <?php marketplaceRenderSetupActivity($events); ?>
                </section>
            <?php endif; ?>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="advanced"<?php echo $panelAttr('advanced'); ?>>
                <h3>Advanced</h3>
                <?php if (!$canManage || !$installed): ?>
                    <p><?php echo $installed ? 'Marketplace management access is required to save setup.' : 'Install WhatsApp Assistant before saving setup.'; ?></p>
                <?php else: ?>
                    <form method="POST" class="marketplace-setup-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <?php marketplaceRenderSettingsTabInput($settingsTab); ?>
                        <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                        <input type="hidden" name="whatsapp_assistant_setup_tab" value="advanced">
                        <?php marketplaceWhatsAppAssistantHiddenInputs($settings, $enabled, array_keys($actionToggles)); ?>
                        <div class="marketplace-email-assistant-toggle-grid">
                            <?php foreach ($actionToggles as $inputName => [$settingKey, $labelText]): ?>
                                <label class="marketplace-form-check marketplace-email-assistant-toggle"><input type="checkbox" name="<?php echo htmlspecialchars($inputName); ?>" <?php echo !empty($settings[$settingKey]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($labelText); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_whatsapp_assistant_setup">Save action controls</button></div>
                    </form>
                <?php endif; ?>
            </section>
        </div>
        <script>
        document.querySelectorAll('[data-whatsapp-assistant-custom-toggle]').forEach(function (toggle) {
            var form = toggle.closest('form');
            var fields = form ? form.querySelector('[data-whatsapp-assistant-custom-fields]') : null;
            if (!fields) return;
            var sync = function () { fields.hidden = !toggle.checked; };
            toggle.addEventListener('change', sync);
            sync();
        });
        </script>
        <?php
    }
}

if (!function_exists('marketplaceWhatsAppAssistantActionToggles')) {
    /**
     * @return array<string,array{0:string,1:string}>
     */
    function marketplaceWhatsAppAssistantActionToggles(): array
    {
        return [
            'whatsapp_assistant_qa_enabled' => ['qa_enabled', 'Q&A'],
            'whatsapp_assistant_instructions_enabled' => ['instructions_enabled', 'Inbound instructions'],
            'whatsapp_assistant_customer_thread_enabled' => ['customer_thread_enabled', 'Customer threads'],
            'whatsapp_assistant_customer_send_enabled' => ['customer_send_enabled', 'Customer send'],
            'whatsapp_assistant_skill_create_contact' => ['skill_create_contact', 'Create contact'],
            'whatsapp_assistant_skill_update_contact' => ['skill_update_contact', 'Update contact'],
            'whatsapp_assistant_skill_delete_contact' => ['skill_delete_contact', 'Delete contact'],
            'whatsapp_assistant_skill_enrich_contact' => ['skill_enrich_contact', 'Enrich contact'],
            'whatsapp_assistant_skill_verify_email' => ['skill_verify_email', 'Verify email'],
            'whatsapp_assistant_skill_add_note' => ['skill_add_note', 'Add note'],
            'whatsapp_assistant_skill_get_pipeline' => ['skill_get_pipeline', 'Pipeline status'],
            'whatsapp_assistant_skill_list_tasks' => ['skill_list_tasks', 'List tasks'],
            'whatsapp_assistant_skill_schedule_event' => ['skill_schedule_event', 'Schedule event'],
            'whatsapp_assistant_skill_run_report' => ['skill_run_report', 'Run report'],
            'whatsapp_assistant_skill_create_invoice' => ['skill_create_invoice', 'Create quote or invoice'],
            'whatsapp_assistant_skill_update_invoice' => ['skill_update_invoice', 'Update quote or invoice'],
            'whatsapp_assistant_skill_list_invoices' => ['skill_list_invoices', 'List commercial docs'],
            'whatsapp_assistant_skill_send_invoice' => ['skill_send_invoice', 'Send commercial doc'],
            'whatsapp_assistant_skill_finalize_invoice' => ['skill_finalize_invoice', 'Finalize commercial doc'],
            'whatsapp_assistant_skill_mark_invoice_paid' => ['skill_mark_invoice_paid', 'Mark invoice paid'],
            'whatsapp_assistant_skill_convert_invoice' => ['skill_convert_invoice', 'Convert quote to invoice'],
        ];
    }
}

if (!function_exists('marketplaceRenderAiApiSetup')) {
    function marketplaceRenderAiApiSetup(array $ctx): void
    {
        $moduleKey = WorkspaceSkillCatalogService::PLUGIN_AI_API;
        $activeTab = marketplaceNormalizeSetupTab($moduleKey, (string) ($ctx['active_tab'] ?? 'provider'));
        $readiness = (array) ($ctx['readiness'] ?? []);
        $workspaceConfig = (array) ($ctx['workspace_config'] ?? []);
        $defaultConfig = (array) ($ctx['default_config'] ?? []);
        $resolved = (array) ($ctx['resolved'] ?? []);
        $workspaceConfigs = (array) ($ctx['workspace_configs'] ?? [WorkspaceAIProviderConfigService::SCOPE_GENERAL => $workspaceConfig]);
        $defaultConfigs = (array) ($ctx['default_configs'] ?? [WorkspaceAIProviderConfigService::SCOPE_GENERAL => $defaultConfig]);
        $resolvedByScope = (array) ($ctx['resolved_by_scope'] ?? [WorkspaceAIProviderConfigService::SCOPE_GENERAL => $resolved]);
        $usageToday = (array) ($ctx['usage_today'] ?? []);
        $events = (array) ($ctx['events'] ?? []);
        $csrf = (string) ($ctx['csrf'] ?? '');
        $canManage = !empty($ctx['can_manage']);
        $installed = !empty($ctx['installed']);
        $isDefaultWorkspace = !empty($ctx['is_default_workspace']);
        $disabled = $canManage && $installed ? '' : ' disabled';
        $source = (string) ($resolved['source'] ?? $readiness['provider_source'] ?? 'local_fallback');
        $sourceLabel = ucwords(str_replace('_', ' ', $source));
        $cap = max(0, (int) ($readiness['common_daily_token_cap'] ?? $resolved['common_daily_token_cap'] ?? 0));
        $used = max(0, (int) ($readiness['common_used_today'] ?? $resolved['common_used_today'] ?? 0));
        $remaining = $cap > 0 ? max(0, $cap - $used) : null;
        $panelAttr = static fn(string $tab): string => $activeTab === $tab ? '' : ' hidden';
        marketplaceRenderSetupTabs($moduleKey, $activeTab);
        ?>
        <div class="marketplace-setup-tab-panels marketplace-ai-api-setup" data-marketplace-setup-panels>
            <section class="marketplace-setup-panel" data-marketplace-setup-panel="provider"<?php echo $panelAttr('provider'); ?>>
                <h3>AI API credentials</h3>
                <p class="marketplace-ai-api-note"><strong>Two independent encrypted slots</strong> keep routine assistant traffic separate from content creation. Saved keys are never shown again; only their fingerprint is displayed.</p>
                <div class="marketplace-ai-summary">
                    <?php foreach ([WorkspaceAIProviderConfigService::SCOPE_GENERAL => 'General AI', WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION => 'Content Generation'] as $scopeKey => $scopeLabel): $scopeRoute = (array) ($resolvedByScope[$scopeKey] ?? []); ?>
                        <span class="marketplace-status <?php echo !empty($scopeRoute['available']) ? 'is-installed' : 'is-needs-setup'; ?>"><?php echo htmlspecialchars($scopeLabel); ?>: <?php echo !empty($scopeRoute['available']) ? htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($scopeRoute['source'] ?? 'ready')))) : 'Key required'; ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!$installed): ?>
                    <p style="color:#64748b;">Install AI API before saving provider details.</p>
                <?php endif; ?>
                <div class="marketplace-ai-credential-grid">
                    <?php
                    $slotDefinitions = [
                        WorkspaceAIProviderConfigService::SCOPE_GENERAL => [
                            'label' => 'General AI',
                            'description' => 'Assistants, summaries, workflow help, and normal CRM intelligence.',
                            'icon' => 'fa-brain',
                        ],
                        WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION => [
                            'label' => 'Content Generation',
                            'description' => 'Social posts, campaign copy, landing-page copy, and creative drafts. This route never uses the General AI key.',
                            'icon' => 'fa-wand-magic-sparkles',
                        ],
                    ];
                    foreach ($slotDefinitions as $scopeKey => $slot):
                        $slotConfig = (array) ($workspaceConfigs[$scopeKey] ?? []);
                        $slotDefault = (array) ($defaultConfigs[$scopeKey] ?? []);
                        $slotResolved = (array) ($resolvedByScope[$scopeKey] ?? []);
                        $slotCap = max(0, (int) ($slotResolved['common_daily_token_cap'] ?? $slotDefault['shared_daily_token_cap'] ?? 0));
                        $slotUsed = max(0, (int) ($slotResolved['common_used_today'] ?? 0));
                        $slotRemaining = $slotCap > 0 ? max(0, $slotCap - $slotUsed) : null;
                        $slotHasKey = !empty($slotConfig['api_key_present']);
                    ?>
                        <article class="marketplace-ai-credential-card" data-ai-credential-scope="<?php echo htmlspecialchars($scopeKey); ?>">
                            <div class="marketplace-ai-credential-heading">
                                <span><i class="fas <?php echo htmlspecialchars((string) $slot['icon']); ?>"></i></span>
                                <div><h4><?php echo htmlspecialchars((string) $slot['label']); ?></h4><p><?php echo htmlspecialchars((string) $slot['description']); ?></p></div>
                            </div>
                            <div class="marketplace-ai-credential-state">
                                <span class="marketplace-status <?php echo $slotHasKey ? 'is-installed' : 'is-needs-setup'; ?>"><?php echo $slotHasKey ? 'Workspace key saved' : 'No workspace key'; ?></span>
                                <?php if ($slotHasKey && !empty($slotConfig['api_key_fingerprint'])): ?><code>Fingerprint <?php echo htmlspecialchars((string) $slotConfig['api_key_fingerprint']); ?></code><?php endif; ?>
                            </div>
                            <form method="POST" class="marketplace-setup-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <input type="hidden" name="ai_api_setup_tab" value="provider">
                                <input type="hidden" name="skill_action" value="save_ai_api_setup">
                                <input type="hidden" name="ai_credential_scope" value="<?php echo htmlspecialchars($scopeKey); ?>">
                                <label class="marketplace-form-check"><input type="checkbox" name="ai_provider_enabled" <?php echo !array_key_exists('enabled', $slotConfig) || !empty($slotConfig['enabled']) ? 'checked' : ''; ?><?php echo $disabled; ?>> Enable this credential</label>
                                <div class="marketplace-form-grid">
                                    <label class="marketplace-form-field">Provider
                                        <select name="ai_provider_key"<?php echo $disabled; ?>>
                                            <?php foreach (['openai' => 'OpenAI', 'openai_compatible' => 'OpenAI compatible', 'azure_openai' => 'Azure OpenAI', 'local' => 'Local compatible'] as $value => $label): ?>
                                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($slotConfig['provider_key'] ?? 'openai') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="marketplace-form-field">API URL <input type="url" name="ai_api_url" value="<?php echo htmlspecialchars((string) ($slotConfig['api_url'] ?? '')); ?>" placeholder="https://api.openai.com/v1/chat/completions"<?php echo $disabled; ?>></label>
                                    <label class="marketplace-form-field">Model <input type="text" name="ai_model" value="<?php echo htmlspecialchars((string) ($slotConfig['model'] ?? 'gpt-4o-mini')); ?>" placeholder="gpt-4o-mini"<?php echo $disabled; ?>></label>
                                    <label class="marketplace-form-field">API key <input type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?php echo $slotHasKey ? 'Leave blank to keep saved key' : 'Paste provider API key'; ?>"<?php echo $disabled; ?>></label>
                                    <?php if ($isDefaultWorkspace): ?>
                                        <label class="marketplace-form-field">Shared daily token cap <input type="number" min="0" step="1" name="ai_shared_daily_token_cap" value="<?php echo (int) ($slotConfig['shared_daily_token_cap'] ?? 0); ?>"<?php echo $disabled; ?>></label>
                                    <?php else: ?>
                                        <div class="marketplace-ai-api-cap-readonly"><strong><?php echo $slotCap > 0 ? htmlspecialchars((string) $slotRemaining . ' common token(s) remain today') : 'Common cap not set'; ?></strong><span><?php echo $slotCap > 0 ? 'This workspace key is used after the matching common slot reaches its cap.' : 'The matching common slot has no daily cap.'; ?></span></div>
                                    <?php endif; ?>
                                </div>
                                <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit"<?php echo $disabled; ?>>Save <?php echo htmlspecialchars((string) $slot['label']); ?> key</button></div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="usage"<?php echo $panelAttr('usage'); ?>>
                <h3>Today</h3>
                <?php if ($usageToday === []): ?>
                    <p style="color:#64748b;">No AI usage has been recorded for this workspace today.</p>
                <?php else: ?>
                    <div class="marketplace-ai-usage-grid">
                        <?php foreach ($usageToday as $row): ?>
                            <div class="marketplace-metric-card">
                                <strong><?php echo (int) ($row['total_billable_tokens'] ?? 0); ?></strong>
                                <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($row['credential_scope'] ?? 'general')))); ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($row['provider_source'] ?? 'env')))); ?> tokens</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="marketplace-setup-panel" data-marketplace-setup-panel="activity"<?php echo $panelAttr('activity'); ?>>
                <h3>Activity</h3>
                <?php marketplaceRenderSetupActivity($events); ?>
            </section>
        </div>
        <?php
    }
}
