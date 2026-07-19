<?php
$saasBillingSnapshot = $saasBillingSnapshot ?? (array) ($saasBillingPortal['snapshot'] ?? []);
$saasBillingAiUsage = (array) ($saasBillingPortal['ai_usage'] ?? []);
$saasBillingAiUsageOverview = (array) ($saasBillingAiUsage['overview'] ?? []);
$workspaceBillingSettings = $workspaceBillingSettings ?? [];
$workspaceBillingContactsTextarea = '';
$generatedPaystackCallbackUrl = \CRM\Modules\WorkspaceBillingSettings::generatedPaystackCallbackUrl();
$generatedPaystackWebhookUrl = \CRM\Modules\WorkspaceBillingSettings::generatedPaystackWebhookUrl();
$generatedMpesaCallbackUrl = \CRM\Modules\WorkspaceBillingSettings::generatedMpesaCallbackUrl();
$generatedStatusSyncUrl = \CRM\Modules\WorkspaceBillingSettings::absoluteCrmUrl(apiUrl('workspace_billing/status_sync.php'));

if (!empty($canPlatformBillingAdmin)) {
    try {
        $workspaceBillingContactsTextarea = (new WorkspaceBillingService())->getContactsTextarea();
    } catch (\Throwable $e) {
        $workspaceBillingContactsTextarea = '';
    }
}

$canManageWorkspaceBilling = $canBillingView || $canBillingEdit || $canBillingAdmin;
$subscriptionStatus = (string) ($saasBillingSnapshot['subscription_status'] ?? 'inactive');
$subscriptionLabel = ucwords(str_replace('_', ' ', $subscriptionStatus));
$isBillingBlocked = !empty($saasBillingSnapshot['billing_blocked']);
$aiBlockedReason = (string) ($saasBillingSnapshot['ai_blocked_reason'] ?? '');
$aiAccessLabel = $aiBlockedReason === 'wallet_depleted' ? 'Refill required' : ($isBillingBlocked ? 'Package blocked' : 'Available');
$packageName = trim((string) (($saasBillingSnapshot['subscription']['plan_name'] ?? '') ?: ($saasBillingSnapshot['subscription']['price_code'] ?? '')));
if ($packageName === '') {
    $packageName = $subscriptionLabel !== '' ? $subscriptionLabel : 'No active package';
}
$packagePeriod = '';
if (!empty($saasBillingSnapshot['subscription']['interval_unit'])) {
    $packagePeriod = trim((string) ($saasBillingSnapshot['subscription']['interval_count'] ?? '1') . ' ' . (string) $saasBillingSnapshot['subscription']['interval_unit']);
}
$packageEndsAt = (string) ($saasBillingSnapshot['subscription']['current_period_end'] ?? '');
$tokenBalance = (int) ($saasBillingSnapshot['credit_balance'] ?? $saasBillingSnapshot['token_balance'] ?? 0);
$availableTokens = (int) ($saasBillingSnapshot['available_credits'] ?? $saasBillingSnapshot['available_tokens'] ?? 0);
$usageWindowDays = (int) ($saasBillingAiUsage['window_days'] ?? 30);
$saasBillingEntitlements = (array) ($saasBillingPortal['entitlements'] ?? $saasBillingSnapshot['entitlements'] ?? []);
$packageExempt = !empty($saasBillingSnapshot['package_exempt']) || !empty($saasBillingEntitlements['package_exempt']);
$saasSeatUsage = (array) ($saasBillingPortal['seat_usage'] ?? []);
$packagePlanCode = (string) ($saasBillingSnapshot['subscription']['plan_code'] ?? '');
$seatLimit = (int) ($saasBillingEntitlements['seat_limit'] ?? 0);
$seatUsageTotal = (int) ($saasSeatUsage['total_usage'] ?? 0);
$settingsSeatSummary = $seatLimit > 0
    ? number_format($seatUsageTotal) . ' of ' . number_format($seatLimit) . ' seats in use'
    : number_format($seatUsageTotal) . ' seats in use, unlimited package';
$settingsIncludedCredits = (int) ($saasBillingEntitlements['included_credits'] ?? $saasBillingSnapshot['subscription']['included_tokens'] ?? 0);
$settingsIncludedCreditsLabel = $packageExempt
    ? 'Package limits do not apply'
    : ($settingsIncludedCredits > 0
    ? number_format($settingsIncludedCredits) . ($packagePlanCode === 'compass-free' ? ' one-time onboarding AI Credits' : ' AI Credits per billing cycle')
    : 'No included AI Credits');
$settingsCreditExpiryDays = (int) ($saasBillingEntitlements['credit_expiry_days'] ?? 180);
$settingsTopUpSummary = !empty($saasBillingEntitlements['can_top_up']) ? 'Available' : 'Unavailable on current package';
$settingsPlugins = [];
if (!empty($saasBillingEntitlements['business_intelligence_enabled'])) {
    $settingsPlugins[] = 'Business Intelligence';
}
if (!empty($saasBillingEntitlements['personal_api_key_enabled'])) {
    $settingsPlugins[] = 'personal API key';
}
$settingsPluginSummary = $settingsPlugins === [] ? 'Core plugins only' : implode(' + ', $settingsPlugins);
$settingsPackageCapabilitySummary = implode(' | ', [
    $settingsSeatSummary,
    $settingsIncludedCreditsLabel,
    $settingsTopUpSummary . ' top-ups',
    $settingsPluginSummary,
]);
$scheduledChangeType = (string) ($saasBillingSnapshot['subscription']['scheduled_change_type'] ?? '');
$scheduledChangeAt = (string) ($saasBillingSnapshot['subscription']['scheduled_change_at'] ?? '');

$packagesUrl = publicUrl('billing_payment_required.php?tab=packages#workspace-packages');
$tokensUrl = publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill');
$activityUrl = publicUrl('billing_payment_required.php?tab=activity');
$helpUrl = publicUrl('billing_payment_required.php?tab=help');
$primaryBillingActionLabel = $isBillingBlocked ? 'Restore access' : ($aiBlockedReason === 'wallet_depleted' ? 'Buy AI Credits' : 'Open payment portal');
$primaryBillingActionUrl = $aiBlockedReason === 'wallet_depleted' && !$isBillingBlocked ? $tokensUrl : $packagesUrl;
$statusChipClass = $isBillingBlocked || $aiBlockedReason === 'wallet_depleted'
    ? 'billing-status-chip billing-status-chip--warning'
    : 'billing-status-chip billing-status-chip--info';
$saasBillingReadiness = is_array($saasBillingReadiness ?? null) ? $saasBillingReadiness : ['ready' => true, 'issues' => []];
$saasBillingReadinessError = (string) ($saasBillingReadinessError ?? '');
$saasBillingCheckoutFeedback = \CRM\Session::get('billing_checkout_feedback');
if ($saasBillingCheckoutFeedback !== null) {
    \CRM\Session::remove('billing_checkout_feedback');
}

$billingPortalLinks = [
    [
        'href' => $packagesUrl,
        'icon' => 'fas fa-layer-group',
        'title' => 'Packages',
        'copy' => 'Review current limits, upgrades, and plugin unlocks.',
        'cta' => 'Open packages',
    ],
    [
        'href' => $tokensUrl,
        'icon' => 'fas fa-bolt',
        'title' => 'AI Credits',
        'copy' => 'Top up the prepaid AI wallet for workspace actions.',
        'cta' => 'Buy credits',
    ],
    [
        'href' => $activityUrl,
        'icon' => 'fas fa-receipt',
        'title' => 'Payment activity',
        'copy' => 'Review transactions, checkout sessions, and wallet ledger.',
        'cta' => 'View activity',
    ],
    [
        'href' => $helpUrl,
        'icon' => 'fas fa-life-ring',
        'title' => 'Help',
        'copy' => 'Use the recovery checklist or donation/support options.',
        'cta' => 'Open help',
    ],
];
?>
<?php if (!$canManageWorkspaceBilling): ?>
    <div class="content-card billing-notice billing-notice--warning">You do not have permission to access workspace billing.</div>
<?php else: ?>
    <div class="billing-ui billing-page-shell">
        <div class="billing-status-hero billing-status-hero--admin">
            <div class="billing-title-row">
                <div>
                    <h2>Billing admin</h2>
                    <p>Status and configuration live here. Payment actions open in the focused portal.</p>
                </div>
                <span class="<?php echo htmlspecialchars($statusChipClass); ?>">
                    <?php echo htmlspecialchars($packageExempt ? 'Package exempt' : $subscriptionLabel); ?>
                </span>
            </div>

            <div class="billing-hero-actions">
                <?php if ($activeWorkspaceId > 0 && $canManageWorkspaceBilling): ?>
                    <a href="<?php echo htmlspecialchars($primaryBillingActionUrl); ?>" class="btn-premium-primary">
                        <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($primaryBillingActionLabel); ?>
                    </a>
                    <a href="<?php echo htmlspecialchars($activityUrl); ?>" class="btn-premium-secondary">
                        <i class="fas fa-receipt" aria-hidden="true"></i>
                        Payment activity
                    </a>
                <?php endif; ?>
            </div>

            <div class="billing-kpis">
                <div class="billing-kpi">
                    <span class="billing-kpi__label">Package</span>
                    <strong><?php echo htmlspecialchars($packageExempt ? 'Default workspace package exempt' : $packageName); ?></strong>
                </div>
                <div class="billing-kpi">
                    <span class="billing-kpi__label">AI Credit balance</span>
                    <strong><?php echo number_format($tokenBalance); ?></strong>
                </div>
                <div class="billing-kpi">
                    <span class="billing-kpi__label">AI access</span>
                    <strong><?php echo htmlspecialchars($packageExempt ? 'Available' : $aiAccessLabel); ?></strong>
                </div>
            </div>
        </div>

        <?php if (empty($saasBillingReadiness['ready'])): ?>
            <div class="billing-notice billing-notice--warning">
                <div><strong>Billing readiness warning:</strong> <?php echo htmlspecialchars($saasBillingReadinessError !== '' ? $saasBillingReadinessError : (string) ($saasBillingReadiness['customer_message'] ?? 'Workspace billing is temporarily unavailable until the latest SaaS migrations are applied.')); ?></div>
                <?php if (!empty($canPlatformBillingAdmin) && !empty($saasBillingReadiness['issues']) && is_array($saasBillingReadiness['issues'])): ?>
                    <div class="billing-notice__meta">Detected issues:
                        <?php echo htmlspecialchars(implode('; ', array_map(static function (array $issue): string {
                            return (string) ($issue['message'] ?? 'Unknown readiness issue.');
                        }, $saasBillingReadiness['issues']))); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($saasBillingCheckoutFeedback) && !empty($saasBillingCheckoutFeedback)): ?>
            <div class="billing-notice billing-notice--info">
                <div><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($saasBillingCheckoutFeedback['payment_mode'] ?? 'payment')))); ?> payment started.</strong> <?php echo htmlspecialchars((string) ($saasBillingCheckoutFeedback['message'] ?? 'Follow the returned instructions to complete this payment.')); ?></div>
                <?php if (!empty($saasBillingCheckoutFeedback['instructions']) && is_array($saasBillingCheckoutFeedback['instructions'])): ?>
                    <div class="billing-notice__meta"><?php echo htmlspecialchars(json_encode($saasBillingCheckoutFeedback['instructions'], JSON_UNESCAPED_SLASHES)); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($activeWorkspaceId <= 0): ?>
            <div class="billing-notice billing-notice--warning">
                Select an active workspace to view payment details.
            </div>
        <?php elseif ($isBillingBlocked): ?>
            <div class="billing-notice billing-notice--warning">
                Payment status is <strong><?php echo htmlspecialchars($subscriptionLabel); ?></strong>. Use the payment portal to restore workspace access.
            </div>
        <?php elseif ($aiBlockedReason === 'wallet_depleted'): ?>
            <div class="billing-notice billing-notice--warning billing-notice--action">
                <div>
                    <strong>AI Credits are depleted.</strong>
                    <span>Top up the workspace wallet to resume AI actions.</span>
                </div>
                <a href="<?php echo htmlspecialchars($tokensUrl); ?>" class="billing-notice-action-link">Buy AI Credits</a>
            </div>
        <?php endif; ?>

        <?php if ($activeWorkspaceId > 0 && $canManageWorkspaceBilling): ?>
            <section class="billing-section billing-portal-section">
                <div class="billing-section-head billing-section-head--compact">
                    <div>
                        <h3><i class="fas fa-compass" aria-hidden="true"></i> Payment portal</h3>
                        <p>One focused place for packages, AI Credit top-ups, activity, and recovery help.</p>
                    </div>
                </div>
                <div class="billing-hub-links" aria-label="Billing payment portal links">
                    <?php foreach ($billingPortalLinks as $portalLink): ?>
                        <?php $isPrimaryPortalLink = (string) $portalLink['href'] === $primaryBillingActionUrl; ?>
                        <a href="<?php echo htmlspecialchars((string) $portalLink['href']); ?>" class="billing-hub-link <?php echo $isPrimaryPortalLink ? 'is-primary' : ''; ?>">
                            <span class="billing-hub-link__icon"><i class="<?php echo htmlspecialchars((string) $portalLink['icon']); ?>" aria-hidden="true"></i></span>
                            <span class="billing-hub-link__body">
                                <span class="billing-hub-link__title"><?php echo htmlspecialchars((string) $portalLink['title']); ?></span>
                                <span class="billing-hub-link__copy"><?php echo htmlspecialchars((string) $portalLink['copy']); ?></span>
                            </span>
                            <span class="billing-hub-link__cta"><?php echo htmlspecialchars((string) $portalLink['cta']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="billing-section">
                <div class="billing-section-head billing-section-head--compact">
                    <div>
                        <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Workspace billing status</h3>
                        <p>A compact admin summary. Customer actions stay in the payment portal.</p>
                    </div>
                </div>
                <div class="billing-detail-grid">
                    <div class="billing-detail-card">
                        <h3>Package</h3>
                        <div class="billing-meta-list">
                            <div class="billing-meta-row"><span>Status</span><strong><?php echo htmlspecialchars($subscriptionLabel); ?></strong></div>
                            <div class="billing-meta-row"><span>Current package</span><strong><?php echo htmlspecialchars($packageName); ?></strong></div>
                            <div class="billing-meta-row"><span>Seats</span><strong><?php echo htmlspecialchars($settingsSeatSummary); ?></strong></div>
                            <div class="billing-meta-row"><span>Included AI Credits</span><strong><?php echo htmlspecialchars($settingsIncludedCreditsLabel); ?></strong></div>
                            <div class="billing-meta-row"><span>Top-ups</span><strong><?php echo htmlspecialchars($settingsTopUpSummary); ?></strong></div>
                            <div class="billing-meta-row"><span>Plugins</span><strong><?php echo htmlspecialchars($settingsPluginSummary); ?></strong></div>
                            <div class="billing-meta-row"><span>Credit expiry</span><strong><?php echo number_format($settingsCreditExpiryDays); ?> days</strong></div>
                            <?php if ($packagePeriod !== ''): ?>
                                <div class="billing-meta-row"><span>Period</span><strong><?php echo htmlspecialchars($packagePeriod); ?></strong></div>
                            <?php endif; ?>
                            <?php if ($packageEndsAt !== ''): ?>
                                <div class="billing-meta-row"><span>Package ends</span><strong><?php echo htmlspecialchars($packageEndsAt); ?></strong></div>
                            <?php endif; ?>
                            <?php if ($scheduledChangeType !== '' || $scheduledChangeAt !== ''): ?>
                                <div class="billing-meta-row"><span>Scheduled change</span><strong><?php echo htmlspecialchars(trim(ucwords(str_replace('_', ' ', $scheduledChangeType)) . ($scheduledChangeAt !== '' ? ' at ' . $scheduledChangeAt : ''))); ?></strong></div>
                            <?php endif; ?>
                        </div>
                        <div class="billing-notice billing-notice--info" style="margin-top:.85rem;">
                            <strong>Package capability:</strong> <?php echo htmlspecialchars($settingsPackageCapabilitySummary); ?>. Top-up packs are separate from recurring package AI Credits.
                        </div>
                    </div>

                    <div class="billing-detail-card">
                        <h3>AI wallet</h3>
                        <div class="billing-meta-list">
                            <div class="billing-meta-row"><span>AI Credit balance</span><strong><?php echo number_format($tokenBalance); ?></strong></div>
                            <div class="billing-meta-row"><span>Available</span><strong><?php echo number_format($availableTokens); ?></strong></div>
                            <div class="billing-meta-row"><span>AI block</span><strong><?php echo htmlspecialchars($aiBlockedReason !== '' ? ucwords(str_replace('_', ' ', $aiBlockedReason)) : 'None'); ?></strong></div>
                        </div>
                    </div>
                </div>
            </section>

            <details class="billing-history billing-admin-advanced billing-admin-diagnostics">
                <summary>
                    <span>AI usage snapshot</span>
                    <small>Requests and provider cost for the current reporting window.</small>
                </summary>
                <div class="billing-history-body">
                    <div class="billing-meta-list billing-meta-list--diagnostics">
                        <div class="billing-meta-row"><span>Window</span><strong><?php echo number_format($usageWindowDays); ?> days</strong></div>
                        <div class="billing-meta-row"><span>Requests</span><strong><?php echo number_format((int) ($saasBillingAiUsageOverview['request_count'] ?? 0)); ?></strong></div>
                        <div class="billing-meta-row"><span>Billable AI Credits</span><strong><?php echo number_format((int) ($saasBillingAiUsageOverview['total_billable_tokens'] ?? 0)); ?></strong></div>
                        <div class="billing-meta-row"><span>Provider cost</span><strong><?php echo number_format((float) ($saasBillingAiUsageOverview['total_provider_cost'] ?? 0), 4); ?></strong></div>
                    </div>
                </div>
            </details>
        <?php endif; ?>

        <?php if (!empty($canPlatformBillingAdmin)): ?>
            <form method="POST" class="billing-admin-form">
                <input type="hidden" name="csrf_token" value="<?php echo \CRM\Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="billing">
                <input type="hidden" name="billing_action" value="save_settings">
                <details class="billing-history billing-admin-advanced">
                    <summary>
                        <span>Platform configuration</span>
                        <small>Provider, reminders, and hub settings.</small>
                    </summary>
                    <div class="billing-history-body billing-admin-body">
                        <div class="billing-toggle-grid">
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_enabled" value="1" <?php echo !empty($workspaceBillingSettings['enabled']) ? 'checked' : ''; ?>>
                                <span><strong>Enable workspace billing</strong><small>Billing enforcement and provider collection.</small></span>
                            </label>
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_mpesa_enabled" value="1" <?php echo !empty($workspaceBillingSettings['mpesa_enabled']) ? 'checked' : ''; ?>>
                                <span><strong>Enable native M-Pesa</strong><small>Use Safaricom Daraja STK Push for one-time KES payments.</small></span>
                            </label>
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_email_reminders_enabled" value="1" <?php echo !empty($workspaceBillingSettings['email_reminders_enabled']) ? 'checked' : ''; ?>>
                                <span><strong>Email reminders</strong><small>Send reminders to billing contacts.</small></span>
                            </label>
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_in_app_prompts_enabled" value="1" <?php echo !empty($workspaceBillingSettings['in_app_prompts_enabled']) ? 'checked' : ''; ?>>
                                <span><strong>In-app prompts</strong><small>Show payment prompts before lockout.</small></span>
                            </label>
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_donations_enabled" value="1" <?php echo !empty($workspaceBillingSettings['donations_enabled']) ? 'checked' : ''; ?>>
                                <span><strong>Donation support</strong><small>Show public and in-app donation entry points.</small></span>
                            </label>
                            <label class="billing-toggle-card">
                                <input type="checkbox" name="billing_auto_lock_enabled" value="1" <?php echo !empty($workspaceBillingSettings['auto_lock_enabled']) ? 'checked' : ''; ?>>
                                <span><strong>Auto lock non-admin users</strong><small>Restrict after grace period.</small></span>
                            </label>
                        </div>

                        <div class="billing-form-grid billing-form-grid--wide">
                            <label class="billing-field">
                                <span>Billing mode</span>
                                <select name="billing_mode" class="billing-input">
                                    <option value="central_hub" <?php echo ($workspaceBillingSettings['billing_mode'] ?? 'central_hub') === 'central_hub' ? 'selected' : ''; ?>>Central billing hub</option>
                                    <option value="local_provider" <?php echo ($workspaceBillingSettings['billing_mode'] ?? 'central_hub') === 'local_provider' ? 'selected' : ''; ?>>Local provider fallback</option>
                                </select>
                            </label>
                            <label class="billing-field">
                                <span>Paystack mode</span>
                                <select name="billing_paystack_mode" class="billing-input">
                                    <option value="test" <?php echo ($workspaceBillingSettings['paystack_mode'] ?? 'test') === 'test' ? 'selected' : ''; ?>>Test</option>
                                    <option value="live" <?php echo ($workspaceBillingSettings['paystack_mode'] ?? 'test') === 'live' ? 'selected' : ''; ?>>Live</option>
                                </select>
                            </label>
                            <label class="billing-field">
                                <span>Default currency</span>
                                <input type="text" name="billing_default_currency" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['default_currency'] ?? 'KES')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>Grace days</span>
                                <input type="number" min="0" name="billing_grace_days" value="<?php echo (int) ($workspaceBillingSettings['grace_days'] ?? 3); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>Reminder days</span>
                                <input type="text" name="billing_reminder_days" value="<?php echo htmlspecialchars(implode(',', (array) ($workspaceBillingSettings['reminder_days_before_json'] ?? [7,3,1]))); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--full">
                                <span>Billing contacts</span>
                                <textarea name="billing_contacts" rows="3" class="billing-input"><?php echo htmlspecialchars($workspaceBillingContactsTextarea); ?></textarea>
                            </label>
                        </div>

                        <div class="billing-form-grid billing-form-grid--wide billing-provider-grid">
                            <label class="billing-field">
                                <span>Hub base URL</span>
                                <input type="text" name="billing_hub_base_url" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['billing_hub_base_url'] ?? '')); ?>" placeholder="https://billing.example.com" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>Workspace key</span>
                                <input type="text" name="billing_workspace_key" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['billing_workspace_key'] ?? '')); ?>" placeholder="workspace-install-key" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>Hub signing secret</span>
                                <input type="text" name="billing_hub_signing_secret" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['billing_hub_signing_secret'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>Checkout path</span>
                                <input type="text" name="billing_hub_checkout_path" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['billing_hub_checkout_path'] ?? '/api/billing/workspaces/{workspace_key}/checkout')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>Status path</span>
                                <input type="text" name="billing_hub_status_path" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['billing_hub_status_path'] ?? '/api/billing/workspaces/{workspace_key}/status')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>CRM status sync URL</span>
                                <input type="text" value="<?php echo htmlspecialchars($generatedStatusSyncUrl); ?>" class="billing-input billing-input--readonly" readonly>
                            </label>
                            <label class="billing-field">
                                <span>Paystack public key</span>
                                <input type="text" name="billing_paystack_public_key" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['paystack_public_key'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>Paystack secret key</span>
                                <input type="text" name="billing_paystack_secret_key" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['paystack_secret_key'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>Callback URL</span>
                                <input type="text" value="<?php echo htmlspecialchars($generatedPaystackCallbackUrl); ?>" class="billing-input billing-input--readonly" readonly>
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>Webhook URL</span>
                                <input type="text" value="<?php echo htmlspecialchars($generatedPaystackWebhookUrl); ?>" class="billing-input billing-input--readonly" readonly>
                            </label>
                            <label class="billing-field">
                                <span>M-Pesa environment</span>
                                <select name="billing_mpesa_environment" class="billing-input">
                                    <option value="sandbox" <?php echo ($workspaceBillingSettings['mpesa_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option>
                                    <option value="live" <?php echo ($workspaceBillingSettings['mpesa_environment'] ?? 'sandbox') === 'live' ? 'selected' : ''; ?>>Live</option>
                                </select>
                            </label>
                            <label class="billing-field">
                                <span>M-Pesa consumer key</span>
                                <input type="text" name="billing_mpesa_consumer_key" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['mpesa_consumer_key'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>M-Pesa consumer secret</span>
                                <input type="text" name="billing_mpesa_consumer_secret" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['mpesa_consumer_secret'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>M-Pesa shortcode</span>
                                <input type="text" name="billing_mpesa_shortcode" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['mpesa_shortcode'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field">
                                <span>M-Pesa passkey</span>
                                <input type="text" name="billing_mpesa_passkey" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['mpesa_passkey'] ?? '')); ?>" class="billing-input">
                            </label>
                            <label class="billing-field billing-field--wide">
                                <span>M-Pesa callback URL</span>
                                <input type="text" value="<?php echo htmlspecialchars($generatedMpesaCallbackUrl); ?>" class="billing-input billing-input--readonly" readonly>
                            </label>
                            <label class="billing-field billing-field--full">
                                <span>Mobile return URL</span>
                                <input type="text" name="billing_mobile_return_url" value="<?php echo htmlspecialchars((string) ($workspaceBillingSettings['mobile_return_url'] ?? '')); ?>" class="billing-input">
                            </label>
                        </div>

                        <div class="billing-form-actions">
                            <a href="<?php echo htmlspecialchars($packagesUrl); ?>" class="btn-premium-secondary">Open payment portal</a>
                            <button type="submit" class="btn-premium-primary">Save Billing Configuration</button>
                        </div>
                    </div>
                </details>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
