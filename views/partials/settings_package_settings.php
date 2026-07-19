<?php
$packageSettingsCatalog = is_array($packageSettingsCatalog ?? null) ? $packageSettingsCatalog : [];
$packageSubscriptionPackages = array_values((array) ($packageSettingsCatalog['subscription_packages'] ?? []));
$packageSubscriptionPrices = array_values((array) ($packageSettingsCatalog['subscription_prices'] ?? []));
$packageNegotiatedOffers = array_values((array) ($packageSettingsCatalog['negotiated_offers'] ?? []));
$packageNegotiatedWorkspaceSummaries = array_values((array) ($packageSettingsCatalog['negotiated_workspace_summaries'] ?? []));
$packageNegotiatedOfferMetrics = (array) ($packageSettingsCatalog['negotiated_offer_metrics'] ?? []);
$packageWorkspaces = array_values((array) ($packageSettingsCatalog['workspaces'] ?? []));
$packageTokenPacks = array_values((array) ($packageSettingsCatalog['token_pack_prices'] ?? []));
$packageFeatureCatalog = array_values((array) ($packageSettingsCatalog['feature_catalog'] ?? []));
$packageCatalogHealth = (array) ($packageSettingsCatalog['catalog_health'] ?? []);
$packageAnalytics = (array) ($packageSettingsCatalog['package_analytics'] ?? []);
$packageOperatorAudit = array_values((array) ($packageSettingsCatalog['operator_audit'] ?? []));
$packagePaymentReadiness = (array) ($packageSettingsCatalog['payment_readiness'] ?? []);
$packageSettingsPaymentModes = is_array($packageSettingsPaymentModes ?? null) ? $packageSettingsPaymentModes : [];
$packageNegotiationMeetingState = is_array($packageNegotiationMeetingState ?? null) ? $packageNegotiationMeetingState : [];
$packageNegotiationMeetingProfile = (array) ($packageNegotiationMeetingState['profile'] ?? []);
$packageSettingsCsrf = \CRM\Security::getCsrfToken();
$packageAnalyticsFilters = is_array($packageAnalyticsFilters ?? null) ? $packageAnalyticsFilters : ['from' => '', 'to' => ''];
$packageNegotiatedFilters = is_array($packageNegotiatedFilters ?? null) ? $packageNegotiatedFilters : ['status' => '', 'payment_mode' => '', 'q' => ''];
$packageSettingsSection = (string) ($packageSettingsSection ?? 'packages');
$packageSettingsSections = [
    'packages' => 'Packages',
    'negotiated_offers' => 'Negotiated Offers',
    'ai_credit_packs' => 'AI Credit Packs',
    'feature_catalog' => 'Feature Catalog',
    'payment_methods' => 'Payment Methods',
    'catalog_health' => 'Catalog Health',
    'analytics' => 'Analytics',
];
if (!array_key_exists($packageSettingsSection, $packageSettingsSections)) {
    $packageSettingsSection = 'packages';
}
$packageNegotiatedBasePrices = array_values(array_filter($packageSubscriptionPrices, static function (array $price): bool {
    return !empty($price['is_active']);
}));

if ($packageSubscriptionPackages === [] && $packageSubscriptionPrices !== []) {
    $grouped = [];
    foreach ($packageSubscriptionPrices as $price) {
        $planId = (int) ($price['plan_id'] ?? 0);
        if ($planId <= 0) {
            continue;
        }
        $grouped[$planId] ??= [
            'id' => $planId,
            'code' => (string) ($price['plan_code'] ?? ''),
            'name' => (string) ($price['plan_name'] ?? 'Package'),
            'description' => (string) ($price['description'] ?? ''),
            'is_active' => (int) ($price['plan_is_active'] ?? 1),
            'feature_details' => (array) (($price['entitlements'] ?? [])['feature_details'] ?? []),
            'prices' => [],
        ];
        $grouped[$planId]['prices'][] = $price;
    }
    $packageSubscriptionPackages = array_values($grouped);
}

$packagePaymentModeControls = [
    'card' => [
        'field' => 'payment_card_enabled',
        'enabled' => !empty($packageSettingsPaymentModes['payment_card_enabled']),
        'title' => 'Card payments',
        'copy' => 'Paystack hosted card checkout and recurring package autopay.',
    ],
    'mpesa' => [
        'field' => 'payment_mpesa_enabled',
        'enabled' => !empty($packageSettingsPaymentModes['payment_mpesa_enabled']),
        'title' => 'M-Pesa',
        'copy' => 'Native Safaricom Daraja STK Push for KES manual period payments.',
    ],
    'bank_transfer' => [
        'field' => 'payment_bank_transfer_enabled',
        'enabled' => !empty($packageSettingsPaymentModes['payment_bank_transfer_enabled']),
        'title' => 'Bank transfer',
        'copy' => 'Paystack bank-transfer charge flow for supported NGN payments.',
    ],
];

if (!function_exists('settingsPackageSettingsMoney')) {
    function settingsPackageSettingsMoney(float $amount, string $currency): string
    {
        return strtoupper($currency) . ' ' . number_format($amount, 0);
    }
}

if (!function_exists('settingsPackageSettingsEffectiveModeSummary')) {
    function settingsPackageSettingsEffectiveModeSummary(array $modes): string
    {
        $available = [];
        $blocked = [];
        foreach ($modes as $mode) {
            $label = (string) ($mode['label'] ?? $mode['key'] ?? 'Payment method');
            if (!empty($mode['available'])) {
                $available[] = $label;
            } else {
                $blocked[] = $label . ': ' . (string) (($mode['reason'] ?? $mode['help'] ?? '') ?: 'Unavailable');
            }
        }
        if ($available === [] && $blocked === []) {
            return 'Effective methods: all globally enabled methods.';
        }

        return 'Available: ' . ($available === [] ? 'None' : implode(', ', $available))
            . ($blocked === [] ? '' : ' | Blocked: ' . implode('; ', array_slice($blocked, 0, 3)));
    }
}

if (!function_exists('settingsPackageSettingsModeChecks')) {
    function settingsPackageSettingsModeChecks(array $controls, array $allowedModes = [], array $effectiveModes = []): void
    {
        $scope = $allowedModes === [] ? 'all' : 'custom';
        ?>
        <div class="package-settings-mode-scope" data-payment-mode-control>
            <label><input type="radio" name="payment_scope" value="all" <?php echo $scope === 'all' ? 'checked' : ''; ?>> All globally enabled methods</label>
            <label><input type="radio" name="payment_scope" value="custom" <?php echo $scope === 'custom' ? 'checked' : ''; ?>> Custom allowlist</label>
        </div>
        <div class="package-settings-checks" data-payment-mode-checks>
            <?php foreach ($controls as $modeKey => $modeControl): ?>
                <label>
                    <input type="checkbox" name="payment_modes[<?php echo htmlspecialchars((string) $modeKey); ?>]" value="1" <?php echo in_array((string) $modeKey, $allowedModes, true) ? 'checked' : ''; ?> <?php echo $scope === 'all' ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars((string) ($modeControl['title'] ?? $modeKey)); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if ($effectiveModes !== []): ?>
            <small><?php echo htmlspecialchars(settingsPackageSettingsEffectiveModeSummary($effectiveModes)); ?></small>
        <?php else: ?>
            <small>Custom allowlists are intersected with global switches, currency support, and provider readiness.</small>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('settingsPackageSettingsDateTimeLocal')) {
    function settingsPackageSettingsDateTimeLocal(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
    }
}

if (!function_exists('settingsPackageSettingsSectionUrl')) {
    function settingsPackageSettingsSectionUrl(string $section, array $params = []): string
    {
        $query = array_merge(['tab' => 'package_settings', 'package_section' => $section], $params);
        $query = array_filter($query, static fn($value): bool => $value !== null && $value !== '');

        return 'settings.php?' . http_build_query($query);
    }
}

if (!function_exists('settingsPackageSettingsCadenceLabel')) {
    function settingsPackageSettingsCadenceLabel(string $interval, int $count = 1): string
    {
        $interval = strtolower(trim($interval));
        $labels = match ($interval) {
            'weekly' => ['weekly', 'week'],
            'quarterly' => ['quarterly', 'quarter'],
            'yearly', 'annual' => ['annual', 'year'],
            default => ['monthly', 'month'],
        };

        return $count > 1 ? 'every ' . number_format($count) . ' ' . $labels[1] . 's' : $labels[0];
    }
}

if (!function_exists('settingsPackageSettingsHiddenSection')) {
    function settingsPackageSettingsHiddenSection(string $section): void
    {
        ?>
        <input type="hidden" name="package_section" value="<?php echo htmlspecialchars($section); ?>">
        <?php
    }
}
?>

<style>
    .package-settings-shell { display:grid; gap:1.2rem; }
    .package-settings-head { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
    .package-settings-head h2 { margin:0; color:#0f172a; font-size:1.35rem; letter-spacing:0; }
    .package-settings-head p { margin:.35rem 0 0; color:#64748b; line-height:1.55; max-width:800px; }
    .package-settings-tabs { display:flex; flex-wrap:wrap; gap:.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:.65rem; }
    .package-settings-tabs a { text-decoration:none; color:#475569; border:1px solid #cbd5e1; border-radius:8px; padding:.55rem .8rem; font-size:.8rem; font-weight:800; background:#fff; transition:background .15s ease, border-color .15s ease, color .15s ease; }
    .package-settings-tabs a.is-active { color:#0f172a; border-color:#f59e0b; background:#fffbeb; box-shadow:0 8px 22px rgba(15,23,42,.07); }
    .package-settings-section { display:grid; gap:1rem; padding-top:.25rem; }
    .package-settings-section h3 { margin:0; color:#0f172a; font-size:1.05rem; letter-spacing:0; }
    .package-settings-section > p, .package-settings-section .muted { margin:0; color:#64748b; line-height:1.55; }
    .package-settings-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1rem; }
    .package-settings-panel { border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:1rem; display:grid; gap:.85rem; }
    .package-settings-panel h4 { margin:0; color:#0f172a; font-size:1rem; letter-spacing:0; }
    .package-settings-panel small { color:#64748b; line-height:1.45; }
    .package-settings-form { display:grid; gap:.8rem; }
    .package-settings-fields { display:grid; grid-template-columns:repeat(auto-fit, minmax(145px, 1fr)); gap:.75rem; }
    .package-settings-field-grid { display:grid; grid-template-columns:repeat(3, minmax(190px, 1fr)); gap:.8rem; }
    .package-settings-field-grid.is-access { grid-template-columns:repeat(3, minmax(170px, 1fr)); }
    .package-settings-field-wide { grid-column:span 2; }
    .package-settings-field-full { grid-column:1 / -1; }
    .package-settings-form label { display:grid; gap:.35rem; color:#334155; font-size:.78rem; font-weight:700; }
    .package-settings-form input,
    .package-settings-form select,
    .package-settings-form textarea { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:.58rem .65rem; font:inherit; color:#0f172a; background:#fff; }
    .package-settings-form textarea { min-height:82px; resize:vertical; line-height:1.45; }
    .package-settings-checks, .package-settings-mode-scope { display:flex; flex-wrap:wrap; gap:.6rem .9rem; }
    .package-settings-checks label, .package-settings-mode-scope label { display:flex; align-items:center; gap:.45rem; margin:0; font-size:.82rem; font-weight:700; color:#334155; }
    .package-settings-checks input, .package-settings-mode-scope input { width:auto; }
    .package-settings-actions { display:flex; gap:.65rem; align-items:center; flex-wrap:wrap; }
    .package-settings-note { border:1px solid #bfdbfe; border-radius:8px; background:#eff6ff; color:#1e3a8a; padding:.85rem 1rem; line-height:1.5; }
    .package-settings-empty { border:1px dashed #cbd5e1; border-radius:8px; padding:1rem; color:#64748b; background:#f8fafc; }
    .package-settings-table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:8px; background:#fff; }
    .package-settings-table { width:100%; min-width:760px; border-collapse:collapse; background:#fff; font-size:.84rem; }
    .package-settings-table th,
    .package-settings-table td { padding:.7rem .75rem; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
    .package-settings-table th { background:#f8fafc; color:#475569; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
    .package-settings-status { display:inline-flex; align-items:center; border-radius:999px; padding:.2rem .55rem; font-size:.72rem; font-weight:800; background:#ecfdf5; color:#047857; }
    .package-settings-status.is-off { background:#f1f5f9; color:#64748b; }
    .package-settings-danger { border-color:#fecaca; background:#fff7f7; }
    .package-settings-cadence { border:1px solid #e2e8f0; border-radius:8px; padding:.85rem; display:grid; gap:.75rem; background:#f8fafc; }
    .package-settings-hero { border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:1rem; display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
    .package-settings-hero h3 { font-size:1.15rem; }
    .package-settings-metrics { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:.75rem; }
    .package-settings-metric { border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:.9rem; display:grid; gap:.25rem; }
    .package-settings-metric span { color:#64748b; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .package-settings-metric strong { color:#0f172a; font-size:1.45rem; letter-spacing:0; line-height:1; }
    .package-settings-workspace { display:grid; gap:.35rem; }
    .package-settings-two-column { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(280px, .9fr); gap:1rem; align-items:start; }
    .package-settings-negotiated-create { display:grid; grid-template-columns:1fr; gap:1rem; align-items:start; }
    .package-settings-subpanel { border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; padding:.85rem; display:grid; gap:.75rem; }
    .package-settings-negotiated-create .package-settings-subpanel { padding:1rem; }
    .package-settings-subpanel h5 { margin:0; color:#0f172a; font-size:.86rem; letter-spacing:0; }
    .package-settings-workspace-picker { display:grid; grid-template-columns:minmax(260px, 1.15fr) minmax(260px, .85fr); gap:.8rem; grid-column:1 / -1; align-items:end; }
    .package-settings-workspace-search small { color:#64748b; font-weight:700; }
    .package-settings-toolbar { display:flex; justify-content:space-between; gap:.8rem; flex-wrap:wrap; align-items:end; }
    .package-settings-toolbar .package-settings-fields { flex:1; min-width:260px; }
    .package-settings-action-stack { display:flex; flex-direction:column; gap:.55rem; min-width:220px; }
    .package-settings-action-stack form { display:flex; gap:.45rem; align-items:center; flex-wrap:wrap; }
    .package-settings-action-stack input { min-width:120px; }
    .package-settings-soft-warning { color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:.55rem .65rem; display:inline-block; margin-top:.35rem; }
    .package-settings-preview { border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; padding:.8rem 0; display:grid; gap:.55rem; }
    .package-settings-preview strong { color:#0f172a; }
    .package-settings-method { border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; padding:.85rem; display:grid; gap:.45rem; }
    .package-settings-checks input:disabled + span,
    .package-settings-checks input:disabled { opacity:.6; }
    .package-settings-inline-form { display:grid; gap:.55rem; min-width:260px; }
    @media (max-width: 980px) {
        .package-settings-field-grid,
        .package-settings-field-grid.is-access,
        .package-settings-workspace-picker { grid-template-columns:1fr; }
        .package-settings-field-wide { grid-column:1 / -1; }
    }
    @media (max-width: 860px) {
        .package-settings-two-column { grid-template-columns:1fr; }
        .package-settings-toolbar { align-items:stretch; }
        .package-settings-action-stack { min-width:0; }
        .package-settings-action-stack form { align-items:stretch; }
        .package-settings-action-stack input,
        .package-settings-action-stack button { width:100%; }
    }
</style>

<?php if (empty($isSuperAdmin)): ?>
    <div class="content-card settings-alert settings-alert--error">Only Super Admin can manage package settings.</div>
<?php else: ?>
    <div class="package-settings-shell">
        <div class="package-settings-head">
            <div>
                <h2>Package Settings</h2>
                <p>Manage public package catalog operations separately from private workspace-negotiated commercial agreements, payment rules, feature limits, and package analytics.</p>
            </div>
            <a class="btn-premium-secondary" href="super_admin_billing.php#subscriptions">
                <i class="fas fa-tools" aria-hidden="true"></i>
                Workspace billing operations
            </a>
        </div>

        <div class="package-settings-tabs" role="tablist" aria-label="Package settings sections">
            <?php foreach ($packageSettingsSections as $sectionKey => $sectionLabel): ?>
                <a href="<?php echo htmlspecialchars(settingsPackageSettingsSectionUrl($sectionKey)); ?>" class="<?php echo $packageSettingsSection === $sectionKey ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $packageSettingsSection === $sectionKey ? 'true' : 'false'; ?>">
                    <?php echo htmlspecialchars($sectionLabel); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="package-settings-note">
            Public packages stay in the catalog sections. Negotiated offers are private workspace agreements and never appear in the global package catalog.
        </div>

        <?php if ($packageSettingsSection === 'negotiated_offers'): ?>
        <section class="package-settings-section" id="negotiated-offers">
            <div class="package-settings-hero">
                <div>
                    <h3>Negotiated Offers</h3>
                    <p class="muted">Private workspace offers built from a base package. They stay hidden from the public catalog and only appear to the target workspace.</p>
                </div>
                <span class="package-settings-status">Private workspace offer</span>
            </div>

            <div class="package-settings-metrics">
                <div class="package-settings-metric"><span>Open offers</span><strong><?php echo number_format((int) ($packageNegotiatedOfferMetrics['open_offers'] ?? 0)); ?></strong></div>
                <div class="package-settings-metric"><span>Active workspaces</span><strong><?php echo number_format((int) ($packageNegotiatedOfferMetrics['active_workspaces'] ?? 0)); ?></strong></div>
                <div class="package-settings-metric"><span>Expiring soon</span><strong><?php echo number_format((int) ($packageNegotiatedOfferMetrics['expiring_soon'] ?? 0)); ?></strong></div>
                <div class="package-settings-metric"><span>Card sync blocked</span><strong><?php echo number_format((int) ($packageNegotiatedOfferMetrics['card_sync_blocked'] ?? 0)); ?></strong></div>
            </div>

            <article class="package-settings-panel">
                <h4>Negotiated Meeting Calendar</h4>
                <small>
                    Attached calendar:
                    <?php echo htmlspecialchars((string) (($packageNegotiationMeetingProfile['title'] ?? '') ?: 'Package Negotiation')); ?>
                    <?php if (!empty($packageNegotiationMeetingProfile['slug'])): ?>
                        (<?php echo htmlspecialchars((string) $packageNegotiationMeetingProfile['slug']); ?>)
                    <?php endif; ?>
                    <?php if (!empty($packageNegotiationMeetingProfile['timezone'])): ?>
                        | <?php echo htmlspecialchars((string) $packageNegotiationMeetingProfile['timezone']); ?>
                    <?php endif; ?>
                </small>
                <form method="POST" class="package-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                    <input type="hidden" name="tab" value="package_settings">
                    <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                    <input type="hidden" name="billing_action" value="update_package_negotiation_meeting_settings">
                    <div class="package-settings-checks">
                        <label>
                            <input type="checkbox" name="package_meeting_auto_approval_enabled" value="1" <?php echo !empty($packageNegotiationMeetingState['auto_approval_enabled']) ? 'checked' : ''; ?>>
                            Auto-approve negotiated package meetings
                        </label>
                    </div>
                    <small>When enabled, Book now creates the CRM event immediately and uses calendar sync if a support calendar integration is configured.</small>
                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                    <button class="btn-premium-primary" type="submit">Save Meeting Settings</button>
                </form>
            </article>

            <article class="package-settings-panel">
                <h4>Create Negotiated Offer</h4>
                <small>Use this for private commercial agreements. Card autopay remains unavailable until private provider plan sync is complete.</small>
                <?php if ($packageWorkspaces === [] || $packageNegotiatedBasePrices === []): ?>
                    <div class="package-settings-empty">Workspaces and active base package prices are required before negotiated offers can be created.</div>
                <?php else: ?>
                    <form method="POST" class="package-settings-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                        <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                        <input type="hidden" name="billing_action" value="save_negotiated_offer">
                        <div class="package-settings-negotiated-create">
                            <div class="package-settings-subpanel">
                                <h5>Commercial terms</h5>
                                <div class="package-settings-field-grid">
                                    <div class="package-settings-workspace-picker">
                                        <label class="package-settings-workspace-search">Search workspaces
                                            <input type="search" data-workspace-search data-target-select="negotiated-workspace-select" placeholder="Search by name, slug, or ID">
                                            <small data-workspace-search-count><?php echo number_format(count($packageWorkspaces)); ?> workspaces available</small>
                                        </label>
                                        <label>Workspace
                                            <select id="negotiated-workspace-select" name="workspace_id" required>
                                                <option value="">Choose workspace</option>
                                                <?php foreach ($packageWorkspaces as $workspace): ?>
                                                    <?php
                                                    $workspaceId = (int) ($workspace['id'] ?? 0);
                                                    $workspaceName = (string) ($workspace['name'] ?? 'Workspace');
                                                    $workspaceSlug = (string) ($workspace['slug'] ?? '');
                                                    $workspaceSearch = strtolower($workspaceName . ' ' . $workspaceSlug . ' ' . $workspaceId);
                                                    ?>
                                                    <option value="<?php echo $workspaceId; ?>" data-search="<?php echo htmlspecialchars($workspaceSearch); ?>">
                                                        <?php echo htmlspecialchars($workspaceName . ' #' . $workspaceId . ' (' . $workspaceSlug . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                    <label class="package-settings-field-wide">Base package
                                        <select name="base_billing_plan_price_id" required>
                                            <option value="">Choose base package</option>
                                            <?php foreach ($packageNegotiatedBasePrices as $price): ?>
                                                <option value="<?php echo (int) ($price['id'] ?? 0); ?>">
                                                    <?php echo htmlspecialchars((string) ($price['plan_name'] ?? 'Package') . ' - ' . settingsPackageSettingsMoney((float) ($price['amount'] ?? 0), (string) ($price['currency'] ?? 'KES')) . ' / ' . settingsPackageSettingsCadenceLabel((string) ($price['interval_unit'] ?? 'monthly'), (int) ($price['interval_count'] ?? 1))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Status
                                        <select name="offer_status">
                                            <option value="draft">Draft</option>
                                            <option value="offered">Offered</option>
                                        </select>
                                    </label>
                                    <label class="package-settings-field-wide">Private display name <input name="display_name" value="Negotiated Workspace Package" required></label>
                                    <label>Currency <input name="currency" value="KES" required></label>
                                    <label>Negotiated price <input name="amount" type="number" step="0.01" min="0" required></label>
                                    <label>Cadence
                                        <select name="interval_unit">
                                            <option value="monthly">Monthly</option>
                                            <option value="quarterly">Quarterly</option>
                                            <option value="yearly">Annual</option>
                                            <option value="weekly">Weekly</option>
                                        </select>
                                    </label>
                                    <label>Cycle count <input name="interval_count" type="number" min="1" value="1"></label>
                                    <label>Included AI Credits <input name="included_credits" type="number" min="0" value="0"></label>
                                    <label>Starts at <input name="starts_at" type="datetime-local"></label>
                                    <label>Expires at <input name="expires_at" type="datetime-local"></label>
                                </div>
                                <label>Workspace-facing summary <textarea name="display_copy" placeholder="Private workspace offer hidden from the public package catalog."></textarea></label>
                            </div>
                            <div class="package-settings-subpanel">
                                <h5>Access and payment</h5>
                                <div class="package-settings-field-grid is-access">
                                    <label>Seat limit <input name="seat_limit" type="number" min="0" value="0"></label>
                                    <label>Credit expiry days <input name="credit_expiry_days" type="number" min="1" value="180"></label>
                                    <label class="package-settings-field-wide">Agreement reference <input name="agreement_reference" placeholder="Contract, quote, or invoice reference"></label>
                                </div>
                                <div class="package-settings-checks">
                                    <label><input type="checkbox" name="can_top_up" value="1" checked> Top-up access</label>
                                    <label><input type="checkbox" name="business_intelligence" value="1" checked> Business Intelligence</label>
                                    <label><input type="checkbox" name="personal_api_key" value="1" checked> Personal API key</label>
                                </div>
                                <?php settingsPackageSettingsModeChecks($packagePaymentModeControls, ['mpesa']); ?>
                                <span class="package-settings-soft-warning">Card autopay unavailable until private provider plan sync.</span>
                                <label>Internal notes <textarea name="notes" placeholder="Negotiation context, approval trail, or renewal note."></textarea></label>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <div class="package-settings-actions">
                                    <button class="btn-premium-primary" type="submit">Create Negotiated Offer</button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </article>

            <form method="GET" class="package-settings-form package-settings-panel">
                <input type="hidden" name="tab" value="package_settings">
                <input type="hidden" name="package_section" value="negotiated_offers">
                <div class="package-settings-toolbar">
                    <div class="package-settings-fields">
                        <label>Workspace search <input name="negotiated_q" value="<?php echo htmlspecialchars((string) ($packageNegotiatedFilters['q'] ?? '')); ?>" placeholder="Name, slug, ID, or agreement"></label>
                        <label>Status
                            <select name="negotiated_status">
                                <option value="">All statuses</option>
                                <?php foreach (['draft', 'offered', 'accepted', 'active', 'expired', 'archived'] as $statusOption): ?>
                                    <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo (string) ($packageNegotiatedFilters['status'] ?? '') === $statusOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords($statusOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Payment mode
                            <select name="negotiated_payment_mode">
                                <option value="">All modes</option>
                                <?php foreach (['mpesa' => 'M-Pesa', 'bank_transfer' => 'Bank transfer', 'card' => 'Card'] as $mode => $label): ?>
                                    <option value="<?php echo htmlspecialchars($mode); ?>" <?php echo (string) ($packageNegotiatedFilters['payment_mode'] ?? '') === $mode ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="package-settings-actions">
                        <button class="btn-premium-secondary" type="submit">Apply Filters</button>
                        <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(settingsPackageSettingsSectionUrl('negotiated_offers')); ?>">Reset</a>
                    </div>
                </div>
            </form>

            <section class="package-settings-section" id="negotiated-workspaces">
                <div>
                    <h3>Workspaces With Negotiated Packages</h3>
                    <p class="muted">One row per workspace with its current private offer state.</p>
                </div>
                <?php if ($packageNegotiatedWorkspaceSummaries === []): ?>
                    <div class="package-settings-empty">No workspaces match the current negotiated package filters.</div>
                <?php else: ?>
                    <div class="package-settings-table-wrap">
                        <table class="package-settings-table">
                            <thead><tr><th>Workspace</th><th>Current package</th><th>Status</th><th>Payment</th><th>Dates</th><th>Last update</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($packageNegotiatedWorkspaceSummaries as $summary): ?>
                                <?php $summaryModes = array_values((array) ($summary['payment_modes'] ?? [])); ?>
                                <tr>
                                    <td class="package-settings-workspace">
                                        <strong><?php echo htmlspecialchars((string) ($summary['workspace_name'] ?? 'Workspace')); ?></strong>
                                        <small><?php echo htmlspecialchars((string) ($summary['workspace_slug'] ?? '') . ' #' . (int) ($summary['workspace_id'] ?? 0)); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) ($summary['current_package_name'] ?? 'Negotiated Package')); ?></strong><br>
                                        <small><?php echo htmlspecialchars(settingsPackageSettingsMoney((float) ($summary['amount'] ?? 0), (string) ($summary['currency'] ?? 'KES')) . ' / ' . settingsPackageSettingsCadenceLabel((string) ($summary['interval_unit'] ?? 'monthly'), (int) ($summary['interval_count'] ?? 1))); ?></small>
                                    </td>
                                    <td>
                                        <span class="package-settings-status <?php echo in_array((string) ($summary['current_status'] ?? ''), ['offered', 'accepted', 'active'], true) ? '' : 'is-off'; ?>"><?php echo htmlspecialchars(ucwords((string) ($summary['current_status'] ?? 'draft'))); ?></span>
                                        <?php if (!empty($summary['is_expiring_soon'])): ?><br><span class="package-settings-soft-warning">Expiring soon</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php foreach ($summaryModes === [] ? ['Default methods'] : $summaryModes as $mode): ?>
                                            <span class="package-settings-status <?php echo $mode === 'Default methods' ? 'is-off' : ''; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $mode))); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (!empty($summary['card_sync_blocked'])): ?><br><small>Card autopay unavailable until private provider plan sync.</small><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($summary['starts_at'])): ?><small>Starts: <?php echo htmlspecialchars((string) $summary['starts_at']); ?></small><br><?php endif; ?>
                                        <?php if (!empty($summary['expires_at'])): ?><small>Expires: <?php echo htmlspecialchars((string) $summary['expires_at']); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($summary['last_updated_at'] ?? '')); ?></td>
                                    <td><a class="btn-premium-secondary" href="#offer-<?php echo (int) ($summary['current_offer_id'] ?? 0); ?>">View Offer</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="package-settings-section" id="all-negotiated-offers">
                <div>
                    <h3>All Negotiated Offers</h3>
                    <p class="muted">Lifecycle controls, audit-safe activation, and editable terms for draft or open agreements.</p>
                </div>
                <?php if ($packageNegotiatedOffers === []): ?>
                    <div class="package-settings-empty">No negotiated workspace offers match the current filters.</div>
                <?php else: ?>
                    <div class="package-settings-table-wrap">
                        <table class="package-settings-table">
                            <thead><tr><th>Workspace</th><th>Offer</th><th>Terms</th><th>Payments</th><th>Lifecycle</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($packageNegotiatedOffers as $offer): ?>
                                <?php
                                $offerStatus = (string) ($offer['status'] ?? 'draft');
                                $offerPaymentModes = array_values((array) ($offer['payment_modes'] ?? []));
                                $offerEntitlements = (array) ($offer['entitlements'] ?? []);
                                $offerMetadata = (array) ($offer['metadata'] ?? []);
                                $offerFeatures = (array) ($offerMetadata['features'] ?? []);
                                ?>
                                <tr id="offer-<?php echo (int) ($offer['id'] ?? 0); ?>">
                                    <td><strong><?php echo htmlspecialchars((string) ($offer['workspace_name'] ?? 'Workspace')); ?></strong><br><small><?php echo htmlspecialchars((string) ($offer['workspace_slug'] ?? '') . ' #' . (int) ($offer['workspace_id'] ?? 0)); ?></small></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) ($offerEntitlements['public_display_name'] ?? 'Negotiated Workspace Package')); ?></strong><br>
                                        <small>Base: <?php echo htmlspecialchars((string) ($offer['base_plan_name'] ?? $offer['base_price_code'] ?? 'Package')); ?></small><br>
                                        <small>Hidden from public package catalog</small>
                                        <?php if (!empty($offer['agreement_reference'])): ?><br><small>Agreement: <?php echo htmlspecialchars((string) $offer['agreement_reference']); ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(settingsPackageSettingsMoney((float) ($offer['amount'] ?? 0), (string) ($offer['currency'] ?? 'KES'))); ?></strong>
                                        / <?php echo htmlspecialchars(settingsPackageSettingsCadenceLabel((string) ($offer['interval_unit'] ?? 'monthly'), (int) ($offer['interval_count'] ?? 1))); ?><br>
                                        <small><?php echo number_format((int) ($offer['included_tokens'] ?? 0)); ?> AI Credits | <?php echo htmlspecialchars((string) ($offerEntitlements['seat_limit_label'] ?? 'Unlimited')); ?> seats</small><br>
                                        <small><?php echo !empty($offerEntitlements['can_top_up']) ? 'Top-ups' : 'No top-ups'; ?> | <?php echo !empty($offerEntitlements['business_intelligence_enabled']) ? 'BI' : 'No BI'; ?> | <?php echo !empty($offerEntitlements['personal_api_key_enabled']) ? 'API key' : 'No API key'; ?></small>
                                    </td>
                                    <td>
                                        <?php foreach ($offerPaymentModes === [] ? ['Default methods'] : $offerPaymentModes as $mode): ?>
                                            <span class="package-settings-status <?php echo $mode === 'Default methods' ? 'is-off' : ''; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $mode))); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (empty($offer['provider_plan_code'])): ?><br><small>Card autopay unavailable until private provider plan sync.</small><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="package-settings-status <?php echo in_array($offerStatus, ['offered', 'accepted', 'active'], true) ? '' : 'is-off'; ?>"><?php echo htmlspecialchars(ucwords($offerStatus)); ?></span><br>
                                        <?php if (!empty($offer['starts_at'])): ?><small>Starts: <?php echo htmlspecialchars((string) $offer['starts_at']); ?></small><br><?php endif; ?>
                                        <?php if (!empty($offer['expires_at'])): ?><small>Expires: <?php echo htmlspecialchars((string) $offer['expires_at']); ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="package-settings-action-stack">
                                            <?php if ($offerStatus === 'draft'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                                    <input type="hidden" name="tab" value="package_settings">
                                                    <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                                    <input type="hidden" name="billing_action" value="publish_negotiated_offer">
                                                    <input type="hidden" name="offer_id" value="<?php echo (int) ($offer['id'] ?? 0); ?>">
                                                    <input name="reason" placeholder="Reason" required>
                                                    <button class="btn-premium-secondary" type="submit">Publish</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($offerStatus, ['offered', 'accepted'], true)): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                                    <input type="hidden" name="tab" value="package_settings">
                                                    <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                                    <input type="hidden" name="billing_action" value="activate_negotiated_offer">
                                                    <input type="hidden" name="offer_id" value="<?php echo (int) ($offer['id'] ?? 0); ?>">
                                                    <input name="reason" placeholder="Reason" required>
                                                    <button class="btn-premium-primary" type="submit">Activate</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (!in_array($offerStatus, ['archived', 'expired'], true)): ?>
                                                <form method="POST" class="package-settings-danger">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                                    <input type="hidden" name="tab" value="package_settings">
                                                    <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                                    <input type="hidden" name="billing_action" value="archive_negotiated_offer">
                                                    <input type="hidden" name="offer_id" value="<?php echo (int) ($offer['id'] ?? 0); ?>">
                                                    <input name="confirm_destructive" placeholder="Type ARCHIVE" required>
                                                    <input name="reason" placeholder="Reason" required>
                                                    <button class="btn-premium-secondary" type="submit">Archive</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (in_array($offerStatus, ['draft', 'offered', 'accepted'], true)): ?>
                                            <details style="margin-top:.7rem;">
                                                <summary>Edit terms</summary>
                                                <form method="POST" class="package-settings-form" style="margin-top:.7rem;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                                    <input type="hidden" name="tab" value="package_settings">
                                                    <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                                    <input type="hidden" name="billing_action" value="save_negotiated_offer">
                                                    <input type="hidden" name="offer_id" value="<?php echo (int) ($offer['id'] ?? 0); ?>">
                                                    <input type="hidden" name="workspace_id" value="<?php echo (int) ($offer['workspace_id'] ?? 0); ?>">
                                                    <div class="package-settings-fields">
                                                        <label>Base package
                                                            <select name="base_billing_plan_price_id" required>
                                                                <?php foreach ($packageNegotiatedBasePrices as $price): ?>
                                                                    <option value="<?php echo (int) ($price['id'] ?? 0); ?>" <?php echo (int) ($price['id'] ?? 0) === (int) ($offer['base_billing_plan_price_id'] ?? 0) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars((string) ($price['plan_name'] ?? 'Package') . ' - ' . settingsPackageSettingsMoney((float) ($price['amount'] ?? 0), (string) ($price['currency'] ?? 'KES'))); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </label>
                                                        <label>Status
                                                            <select name="offer_status">
                                                                <?php foreach (['draft', 'offered', 'accepted'] as $statusOption): ?>
                                                                    <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $offerStatus === $statusOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords($statusOption)); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </label>
                                                        <label>Display name <input name="display_name" value="<?php echo htmlspecialchars((string) ($offerEntitlements['public_display_name'] ?? 'Negotiated Workspace Package')); ?>" required></label>
                                                        <label>Currency <input name="currency" value="<?php echo htmlspecialchars((string) ($offer['currency'] ?? 'KES')); ?>" required></label>
                                                        <label>Price <input name="amount" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($offer['amount'] ?? 0)); ?>"></label>
                                                        <label>Cadence
                                                            <select name="interval_unit">
                                                                <?php foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Annual'] as $interval => $label): ?>
                                                                    <option value="<?php echo htmlspecialchars($interval); ?>" <?php echo (string) ($offer['interval_unit'] ?? '') === $interval ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </label>
                                                        <label>Cycle count <input name="interval_count" type="number" min="1" value="<?php echo (int) ($offer['interval_count'] ?? 1); ?>"></label>
                                                        <label>Included AI Credits <input name="included_credits" type="number" min="0" value="<?php echo (int) ($offer['included_tokens'] ?? 0); ?>"></label>
                                                        <label>Seat limit <input name="seat_limit" type="number" min="0" value="<?php echo (int) ($offerFeatures['seat_limit'] ?? $offerEntitlements['seat_limit'] ?? 0); ?>"></label>
                                                        <label>Credit expiry days <input name="credit_expiry_days" type="number" min="1" value="<?php echo (int) ($offerFeatures['credit_expiry_days'] ?? $offerEntitlements['credit_expiry_days'] ?? 180); ?>"></label>
                                                        <label>Starts at <input name="starts_at" type="datetime-local" value="<?php echo htmlspecialchars(settingsPackageSettingsDateTimeLocal($offer['starts_at'] ?? null)); ?>"></label>
                                                        <label>Expires at <input name="expires_at" type="datetime-local" value="<?php echo htmlspecialchars(settingsPackageSettingsDateTimeLocal($offer['expires_at'] ?? null)); ?>"></label>
                                                    </div>
                                                    <label>Workspace-facing summary <textarea name="display_copy"><?php echo htmlspecialchars((string) ($offerEntitlements['public_summary'] ?? '')); ?></textarea></label>
                                                    <label>Internal notes <textarea name="notes"><?php echo htmlspecialchars((string) ($offer['notes'] ?? '')); ?></textarea></label>
                                                    <div class="package-settings-checks">
                                                        <label><input type="checkbox" name="can_top_up" value="1" <?php echo !empty($offerEntitlements['can_top_up']) ? 'checked' : ''; ?>> Top-up access</label>
                                                        <label><input type="checkbox" name="business_intelligence" value="1" <?php echo !empty($offerEntitlements['business_intelligence_enabled']) ? 'checked' : ''; ?>> Business Intelligence</label>
                                                        <label><input type="checkbox" name="personal_api_key" value="1" <?php echo !empty($offerEntitlements['personal_api_key_enabled']) ? 'checked' : ''; ?>> Personal API key</label>
                                                    </div>
                                                    <?php settingsPackageSettingsModeChecks($packagePaymentModeControls, $offerPaymentModes); ?>
                                                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                                    <button class="btn-premium-secondary" type="submit">Save Offer</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </section>

        <?php elseif ($packageSettingsSection === 'packages'): ?>
        <section class="package-settings-section" id="packages">
            <div>
                <h3>Packages</h3>
                <p class="muted">Create, clone, archive/delete, edit display copy, and manage each cadence under one package.</p>
            </div>

            <article class="package-settings-panel">
                <h4>Create Package</h4>
                <form method="POST" class="package-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                    <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                    <input type="hidden" name="billing_action" value="create_package">
                    <div class="package-settings-fields">
                        <label>Package code <input name="code" placeholder="growth-plus"></label>
                        <label>Package name <input name="name" placeholder="Growth Plus" required></label>
                        <label>Currency <input name="currency" value="KES" required></label>
                        <label>First price <input name="amount" type="number" step="0.01" min="0" value="0"></label>
                        <label>Cadence
                            <select name="interval_unit">
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Annual</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="weekly">Weekly</option>
                            </select>
                        </label>
                        <label>Cycle count <input name="interval_count" type="number" min="1" value="1"></label>
                        <label>Included AI Credits <input name="included_credits" type="number" min="0" value="0"></label>
                        <label>Seat limit <input name="seat_limit" type="number" min="0" value="1"></label>
                        <label>Credit expiry days <input name="credit_expiry_days" type="number" min="1" value="180"></label>
                    </div>
                    <label>Package description <textarea name="description" placeholder="Customer-facing package summary"></textarea></label>
                    <div class="package-settings-checks">
                        <label><input type="checkbox" name="can_top_up" value="1"> Top-up access</label>
                        <label><input type="checkbox" name="business_intelligence" value="1"> Business Intelligence</label>
                        <label><input type="checkbox" name="personal_api_key" value="1"> Personal API key</label>
                        <label><input type="checkbox" name="is_custom" value="1"> Custom package</label>
                        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                        <label><input type="checkbox" name="is_default" value="1"> Default cadence</label>
                    </div>
                    <?php settingsPackageSettingsModeChecks($packagePaymentModeControls); ?>
                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                    <div class="package-settings-actions">
                        <button class="btn-premium-primary" type="submit">Create Package</button>
                    </div>
                </form>
            </article>

            <?php if ($packageSubscriptionPackages === []): ?>
                <div class="package-settings-empty">No subscription packages are configured yet.</div>
            <?php else: ?>
                <div class="package-settings-grid">
                    <?php foreach ($packageSubscriptionPackages as $package): ?>
                        <?php
                        $packagePrices = array_values((array) ($package['prices'] ?? []));
                        $primaryPrice = (array) ($packagePrices[0] ?? []);
                        $primaryMetadata = (array) ($primaryPrice['metadata'] ?? []);
                        ?>
                        <article class="package-settings-panel">
                            <div>
                                <h4><?php echo htmlspecialchars((string) ($package['name'] ?? 'Package')); ?></h4>
                                <small>
                                    <?php echo htmlspecialchars((string) ($package['code'] ?? '')); ?>
                                    |
                                    <span class="package-settings-status <?php echo !empty($package['is_active']) ? '' : 'is-off'; ?>"><?php echo !empty($package['is_active']) ? 'Active' : 'Archived'; ?></span>
                                </small>
                            </div>
                            <div class="package-settings-preview">
                                <strong>Customer-facing preview</strong>
                                <p><?php echo htmlspecialchars((string) ($package['description'] ?? '')); ?></p>
                                <div class="package-settings-checks">
                                    <?php foreach ($packagePrices as $previewPrice): ?>
                                        <?php if (!empty($previewPrice['is_active'])): ?>
                                            <span class="package-settings-status">
                                                <?php echo htmlspecialchars(settingsPackageSettingsMoney((float) ($previewPrice['amount'] ?? 0), (string) ($previewPrice['currency'] ?? 'KES'))); ?>
                                                /
                                                <?php echo htmlspecialchars((string) ($previewPrice['interval_unit'] ?? 'monthly')); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <small>
                                    <?php
                                    $previewFeatures = [];
                                    foreach ((array) ($package['feature_details'] ?? []) as $detail) {
                                        if (empty($detail['is_enabled']) && empty($detail['value'])) {
                                            continue;
                                        }
                                        $label = (string) ($detail['label'] ?? $detail['feature_key'] ?? '');
                                        $value = $detail['value'] ?? null;
                                        if (is_bool($value)) {
                                            $previewFeatures[] = $label;
                                        } elseif ($value !== null && $value !== '') {
                                            $previewFeatures[] = $label . ': ' . (string) $value;
                                        }
                                    }
                                    echo htmlspecialchars($previewFeatures === [] ? 'No published feature summary yet.' : implode(' | ', array_slice($previewFeatures, 0, 6)));
                                    ?>
                                </small>
                            </div>

                            <form method="POST" class="package-settings-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="update_package">
                                <input type="hidden" name="plan_id" value="<?php echo (int) ($package['id'] ?? 0); ?>">
                                <div class="package-settings-fields">
                                    <label>Display name <input name="name" value="<?php echo htmlspecialchars((string) ($package['name'] ?? '')); ?>"></label>
                                    <label>Display order <input name="display_order" type="number" value="<?php echo (int) ($package['display_order'] ?? $primaryMetadata['display_order'] ?? 0); ?>"></label>
                                </div>
                                <label>Package description <textarea name="description"><?php echo htmlspecialchars((string) ($package['description'] ?? '')); ?></textarea></label>
                                <div class="package-settings-checks">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($package['is_active']) ? 'checked' : ''; ?>> Active</label>
                                </div>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <div class="package-settings-actions">
                                    <button class="btn-premium-primary" type="submit">Save Package</button>
                                </div>
                            </form>

                            <form method="POST" class="package-settings-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="save_package_features">
                                <input type="hidden" name="plan_id" value="<?php echo (int) ($package['id'] ?? 0); ?>">
                                <h4>Feature Values</h4>
                                <div class="package-settings-fields">
                                    <?php foreach ($packageFeatureCatalog as $feature): ?>
                                        <?php
                                        $featureKey = (string) ($feature['feature_key'] ?? '');
                                        $valueType = (string) ($feature['value_type'] ?? 'boolean');
                                        $currentValue = null;
                                        foreach ((array) ($package['feature_details'] ?? []) as $detail) {
                                            if ((string) ($detail['feature_key'] ?? '') === $featureKey) {
                                                $currentValue = $detail['value'] ?? null;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($valueType === 'boolean'): ?>
                                            <label style="display:flex;align-items:center;gap:.45rem;">
                                                <input type="hidden" name="feature_values[<?php echo htmlspecialchars($featureKey); ?>]" value="0">
                                                <input type="checkbox" name="feature_enabled[<?php echo htmlspecialchars($featureKey); ?>]" value="1" <?php echo !empty($currentValue) ? 'checked' : ''; ?> style="width:auto;">
                                                <?php echo htmlspecialchars((string) ($feature['label'] ?? $featureKey)); ?>
                                            </label>
                                        <?php else: ?>
                                            <label><?php echo htmlspecialchars((string) ($feature['label'] ?? $featureKey)); ?>
                                                <input name="feature_values[<?php echo htmlspecialchars($featureKey); ?>]" value="<?php echo htmlspecialchars((string) $currentValue); ?>">
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <div class="package-settings-actions">
                                    <button class="btn-premium-secondary" type="submit">Save Feature Values</button>
                                </div>
                            </form>

                            <div class="package-settings-section">
                                <h4>Cadences</h4>
                                <?php foreach ($packagePrices as $price): ?>
                                    <?php
                                    $entitlements = (array) ($price['entitlements'] ?? []);
                                    $metadata = (array) ($price['metadata'] ?? []);
                                    $displayName = (string) ($entitlements['public_display_name'] ?? $entitlements['display_name'] ?? $package['name'] ?? 'Package');
                                    $displayCopy = (string) ($entitlements['public_display_copy'] ?? $entitlements['public_summary'] ?? $metadata['display_copy'] ?? $package['description'] ?? '');
                                    $allowedModes = array_values((array) ($price['allowed_payment_modes'] ?? []));
                                    ?>
                                    <div class="package-settings-cadence">
                                        <strong>
                                            <?php echo htmlspecialchars((string) ($price['price_code'] ?? '')); ?>
                                            |
                                            <?php echo htmlspecialchars(settingsPackageSettingsMoney((float) ($price['amount'] ?? 0), (string) ($price['currency'] ?? 'KES'))); ?>
                                            |
                                            <?php echo htmlspecialchars((string) ($price['interval_unit'] ?? 'monthly')); ?>
                                        </strong>
                                        <form method="POST" class="package-settings-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                            <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                            <input type="hidden" name="billing_action" value="update_plan">
                                            <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) ($price['id'] ?? 0); ?>">
                                            <div class="package-settings-fields">
                                                <label>Display name <input name="display_name" value="<?php echo htmlspecialchars($displayName); ?>"></label>
                                                <label>Maturity tier <input name="maturity_tier" value="<?php echo htmlspecialchars((string) ($entitlements['maturity_tier'] ?? 'custom')); ?>"></label>
                                                <label>Currency <input name="currency" value="<?php echo htmlspecialchars((string) ($price['currency'] ?? 'KES')); ?>"></label>
                                                <label>Price <input name="amount" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($price['amount'] ?? '0')); ?>"></label>
                                                <label>Cadence
                                                    <select name="interval_unit">
                                                        <?php foreach (['weekly', 'monthly', 'quarterly', 'yearly'] as $unit): ?>
                                                            <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo (string) ($price['interval_unit'] ?? '') === $unit ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($unit)); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label>Cycle count <input name="interval_count" type="number" min="1" value="<?php echo (int) ($price['interval_count'] ?? 1); ?>"></label>
                                                <label>Included AI Credits <input name="included_credits" type="number" min="0" value="<?php echo (int) ($entitlements['included_credits'] ?? $price['included_tokens'] ?? 0); ?>"></label>
                                                <label>Seat limit <input name="seat_limit" type="number" min="0" value="<?php echo (int) ($entitlements['seat_limit'] ?? 0); ?>"></label>
                                                <label>Credit expiry days <input name="credit_expiry_days" type="number" min="1" value="<?php echo (int) ($entitlements['credit_expiry_days'] ?? 180); ?>"></label>
                                                <label>Display order <input name="display_order" type="number" value="<?php echo (int) ($metadata['display_order'] ?? 0); ?>"></label>
                                            </div>
                                            <label>Package description <textarea name="display_copy"><?php echo htmlspecialchars($displayCopy); ?></textarea></label>
                                            <div class="package-settings-checks">
                                                <label><input type="checkbox" name="can_top_up" value="1" <?php echo !empty($entitlements['can_top_up']) ? 'checked' : ''; ?>> Top-up access</label>
                                                <label><input type="checkbox" name="business_intelligence_enabled" value="1" <?php echo !empty($entitlements['business_intelligence_enabled']) ? 'checked' : ''; ?>> Business Intelligence</label>
                                                <label><input type="checkbox" name="personal_api_key_enabled" value="1" <?php echo !empty($entitlements['personal_api_key_enabled']) ? 'checked' : ''; ?>> Personal API key</label>
                                                <label><input type="checkbox" name="is_custom" value="1" <?php echo !empty($entitlements['is_custom']) ? 'checked' : ''; ?>> Custom</label>
                                                <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($price['is_active']) ? 'checked' : ''; ?>> Active</label>
                                                <label><input type="checkbox" name="is_default" value="1" <?php echo !empty($price['is_default']) ? 'checked' : ''; ?>> Default</label>
                                            </div>
                                            <?php settingsPackageSettingsModeChecks($packagePaymentModeControls, $allowedModes, array_values((array) ($price['effective_payment_modes'] ?? []))); ?>
                                            <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                            <div class="package-settings-actions">
                                                <button class="btn-premium-primary" type="submit">Save Cadence</button>
                                            </div>
                                        </form>
                                        <form method="POST" class="package-settings-form package-settings-danger">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                            <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                            <input type="hidden" name="billing_action" value="delete_price">
                                            <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) ($price['id'] ?? 0); ?>">
                                            <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                            <label>Confirm <input name="confirm_destructive" placeholder="Type ARCHIVE" pattern="ARCHIVE" required></label>
                                            <button class="btn-premium-secondary" type="submit">Archive/Delete Cadence</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>

                                <form method="POST" class="package-settings-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                    <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                    <input type="hidden" name="billing_action" value="create_price">
                                    <input type="hidden" name="plan_id" value="<?php echo (int) ($package['id'] ?? 0); ?>">
                                    <h4>Add Cadence</h4>
                                    <div class="package-settings-fields">
                                        <label>Price code <input name="price_code" placeholder="<?php echo htmlspecialchars((string) ($package['code'] ?? 'package')); ?>-annual"></label>
                                        <label>Currency <input name="currency" value="KES"></label>
                                        <label>Price <input name="amount" type="number" step="0.01" min="0" value="0"></label>
                                        <label>Cadence
                                            <select name="interval_unit">
                                                <option value="monthly">Monthly</option>
                                                <option value="yearly">Annual</option>
                                                <option value="quarterly">Quarterly</option>
                                                <option value="weekly">Weekly</option>
                                            </select>
                                        </label>
                                        <label>Cycle count <input name="interval_count" type="number" min="1" value="1"></label>
                                        <label>Included AI Credits <input name="included_credits" type="number" min="0" value="0"></label>
                                    </div>
                                    <div class="package-settings-checks">
                                        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                                        <label><input type="checkbox" name="is_default" value="1"> Default</label>
                                    </div>
                                    <?php settingsPackageSettingsModeChecks($packagePaymentModeControls); ?>
                                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                    <button class="btn-premium-secondary" type="submit">Add Cadence</button>
                                </form>
                            </div>

                            <form method="POST" class="package-settings-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="clone_package">
                                <input type="hidden" name="plan_id" value="<?php echo (int) ($package['id'] ?? 0); ?>">
                                <h4>Clone Package</h4>
                                <div class="package-settings-fields">
                                    <label>New code <input name="new_code" placeholder="<?php echo htmlspecialchars((string) ($package['code'] ?? 'package')); ?>-copy"></label>
                                    <label>New name <input name="new_name" placeholder="<?php echo htmlspecialchars((string) ($package['name'] ?? 'Package')); ?> Copy"></label>
                                </div>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <button class="btn-premium-secondary" type="submit">Clone Package</button>
                            </form>

                            <form method="POST" class="package-settings-form package-settings-danger">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="delete_package">
                                <input type="hidden" name="plan_id" value="<?php echo (int) ($package['id'] ?? 0); ?>">
                                <h4>Archive/Delete Package</h4>
                                <small>Only inactive unreferenced drafts are hard-deleted. Active or referenced packages are archived and hidden from checkout.</small>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <label>Confirm <input name="confirm_destructive" placeholder="Type ARCHIVE" pattern="ARCHIVE" required></label>
                                <button class="btn-premium-secondary" type="submit">Archive/Delete Package</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php elseif ($packageSettingsSection === 'ai_credit_packs'): ?>
        <section class="package-settings-section" id="ai-credit-packs">
            <div>
                <h3>AI Credit Packs</h3>
                <p class="muted">Create, edit, archive/delete, price, sort, and control payment eligibility for one-time AI Credit packs.</p>
            </div>

            <article class="package-settings-panel">
                <h4>Create AI Credit Pack</h4>
                <form method="POST" class="package-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                    <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                    <input type="hidden" name="billing_action" value="create_pack">
                    <div class="package-settings-fields">
                        <label>Pack code <input name="code" placeholder="growth-ai-credits"></label>
                        <label>Pack name <input name="name" placeholder="Growth AI Credits" required></label>
                        <label>Currency <input name="currency" value="KES" required></label>
                        <label>Price <input name="amount" type="number" step="0.01" min="0.01" value="1000" required></label>
                        <label>AI Credits <input name="credit_quantity" type="number" min="1" value="100000" required></label>
                        <label>Credit expiry days <input name="credit_expiry_days" type="number" min="1" value="180"></label>
                        <label>Sort order <input name="sort_order" type="number" value="10"></label>
                    </div>
                    <label>Description <textarea name="description">One-time AI Credit top-up pack.</textarea></label>
                    <div class="package-settings-checks">
                        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                    </div>
                    <?php settingsPackageSettingsModeChecks($packagePaymentModeControls); ?>
                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                    <button class="btn-premium-primary" type="submit">Create AI Credit Pack</button>
                </form>
            </article>

            <?php if ($packageTokenPacks === []): ?>
                <div class="package-settings-empty">No AI Credit packs are configured yet.</div>
            <?php else: ?>
                <div class="package-settings-grid">
                    <?php foreach ($packageTokenPacks as $pack): ?>
                        <article class="package-settings-panel">
                            <div>
                                <h4><?php echo htmlspecialchars((string) ($pack['display_name'] ?? $pack['plan_name'] ?? 'AI Credit Pack')); ?></h4>
                                <small>
                                    <?php echo number_format((int) ($pack['credit_quantity'] ?? 0)); ?> AI Credits
                                    |
                                    <?php echo htmlspecialchars(settingsPackageSettingsMoney((float) ($pack['amount'] ?? 0), (string) ($pack['currency'] ?? 'KES'))); ?>
                                    |
                                    <span class="package-settings-status <?php echo !empty($pack['is_active']) ? '' : 'is-off'; ?>"><?php echo !empty($pack['is_active']) ? 'Active' : 'Disabled'; ?></span>
                                </small>
                            </div>
                            <form method="POST" class="package-settings-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="update_pack">
                                <input type="hidden" name="token_pack_price_id" value="<?php echo (int) ($pack['id'] ?? 0); ?>">
                                <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) ($pack['billing_plan_price_id'] ?? 0); ?>">
                                <div class="package-settings-fields">
                                    <label>Currency <input name="currency" value="<?php echo htmlspecialchars((string) ($pack['currency'] ?? 'KES')); ?>"></label>
                                    <label>Price <input name="amount" type="number" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string) ($pack['amount'] ?? '0')); ?>"></label>
                                    <label>AI Credits <input name="credit_quantity" type="number" min="1" value="<?php echo (int) ($pack['credit_quantity'] ?? 0); ?>"></label>
                                    <label>Sort order <input name="sort_order" type="number" value="<?php echo (int) ($pack['sort_order'] ?? 0); ?>"></label>
                                </div>
                                <div class="package-settings-checks">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($pack['is_active']) ? 'checked' : ''; ?>> Active</label>
                                </div>
                                <?php settingsPackageSettingsModeChecks($packagePaymentModeControls, array_values((array) ($pack['allowed_payment_modes'] ?? [])), array_values((array) ($pack['effective_payment_modes'] ?? []))); ?>
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <button class="btn-premium-primary" type="submit">Save Pack</button>
                            </form>
                            <form method="POST" class="package-settings-form package-settings-danger">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                                <input type="hidden" name="billing_action" value="delete_pack">
                                <input type="hidden" name="token_pack_price_id" value="<?php echo (int) ($pack['id'] ?? 0); ?>">
                                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                                <label>Confirm <input name="confirm_destructive" placeholder="Type ARCHIVE" pattern="ARCHIVE" required></label>
                                <button class="btn-premium-secondary" type="submit">Archive/Delete Pack</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php elseif ($packageSettingsSection === 'feature_catalog'): ?>
        <section class="package-settings-section" id="feature-catalog">
            <div>
                <h3>Feature Catalog</h3>
                <p class="muted">Define reusable package features and limits, then assign values on each package.</p>
            </div>
            <article class="package-settings-panel">
                <h4>Create or Update Feature</h4>
                <form method="POST" class="package-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                    <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                    <input type="hidden" name="billing_action" value="save_feature_catalog">
                    <div class="package-settings-fields">
                        <label>Feature key <input name="feature_key" placeholder="advanced_reporting" required></label>
                        <label>Label <input name="label" placeholder="Advanced reporting" required></label>
                        <label>Category <input name="category" value="feature"></label>
                        <label>Value type
                            <select name="value_type">
                                <option value="boolean">Boolean</option>
                                <option value="integer">Integer</option>
                                <option value="decimal">Decimal</option>
                                <option value="text">Text</option>
                            </select>
                        </label>
                        <label>Default value <input name="default_value" value=""></label>
                        <label>Display order <input name="display_order" type="number" value="100"></label>
                    </div>
                    <label>Description <textarea name="description"></textarea></label>
                    <div class="package-settings-checks">
                        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                    </div>
                    <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                    <button class="btn-premium-primary" type="submit">Save Feature</button>
                </form>
            </article>

            <div class="package-settings-table-wrap">
                <table class="package-settings-table">
                    <thead><tr><th>Key</th><th>Label</th><th>Category</th><th>Type</th><th>Default</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($packageFeatureCatalog as $feature): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($feature['feature_key'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($feature['label'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($feature['category'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($feature['value_type'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($feature['default_value'] ?? '')); ?></td>
                            <td><span class="package-settings-status <?php echo !empty($feature['is_active']) ? '' : 'is-off'; ?>"><?php echo !empty($feature['is_active']) ? 'Active' : 'Disabled'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($packageFeatureCatalog !== []): ?>
                <div class="package-settings-grid">
                    <?php foreach ($packageFeatureCatalog as $feature): ?>
                        <?php
                        $featureKey = (string) ($feature['feature_key'] ?? '');
                        $featureType = (string) ($feature['value_type'] ?? 'boolean');
                        $isCoreFeature = !empty($feature['is_core']);
                        ?>
                        <form method="POST" class="package-settings-form package-settings-panel">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                            <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                            <input type="hidden" name="billing_action" value="save_feature_catalog">
                            <input type="hidden" name="feature_key" value="<?php echo htmlspecialchars($featureKey); ?>">
                            <h4><?php echo htmlspecialchars((string) ($feature['label'] ?? $featureKey)); ?></h4>
                            <small><?php echo htmlspecialchars($isCoreFeature ? 'Core feature: type and active state are protected.' : 'Custom feature definition.'); ?></small>
                            <div class="package-settings-fields">
                                <label>Label <input name="label" value="<?php echo htmlspecialchars((string) ($feature['label'] ?? '')); ?>" required></label>
                                <label>Category <input name="category" value="<?php echo htmlspecialchars((string) ($feature['category'] ?? 'feature')); ?>"></label>
                                <label>Value type
                                    <select name="value_type" <?php echo $isCoreFeature ? 'readonly' : ''; ?>>
                                        <?php foreach (['boolean', 'integer', 'decimal', 'text'] as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $featureType === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($type)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Default value <input name="default_value" value="<?php echo htmlspecialchars((string) ($feature['default_value'] ?? '')); ?>"></label>
                                <label>Display order <input name="display_order" type="number" value="<?php echo (int) ($feature['display_order'] ?? 0); ?>"></label>
                            </div>
                            <label>Description <textarea name="description"><?php echo htmlspecialchars((string) ($feature['description'] ?? '')); ?></textarea></label>
                            <div class="package-settings-checks">
                                <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($feature['is_active']) ? 'checked' : ''; ?> <?php echo $isCoreFeature ? 'disabled' : ''; ?>> Active</label>
                            </div>
                            <?php if ($isCoreFeature): ?>
                                <input type="hidden" name="is_active" value="1">
                            <?php endif; ?>
                            <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                            <button class="btn-premium-secondary" type="submit">Save Feature Definition</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php elseif ($packageSettingsSection === 'payment_methods'): ?>
        <section class="package-settings-section" id="payment-methods">
            <div>
                <h3>Payment Methods</h3>
                <p class="muted">Global payment switches. Package and regional allowlists are intersected with these settings, currency support, and provider readiness.</p>
            </div>
            <div class="package-settings-actions">
                <a class="btn-premium-secondary" href="billing_payment_regions.php">Manage Country &amp; Region Availability</a>
            </div>
            <form method="POST" class="package-settings-form package-settings-panel">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($packageSettingsCsrf); ?>">
                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                <input type="hidden" name="billing_action" value="update_payment_methods">
                <div class="package-settings-grid">
                    <?php foreach ($packagePaymentModeControls as $modeControl): ?>
                        <div class="package-settings-method">
                            <div class="package-settings-checks">
                                <label>
                                    <input type="checkbox" name="<?php echo htmlspecialchars((string) ($modeControl['field'] ?? '')); ?>" value="1" <?php echo !empty($modeControl['enabled']) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars((string) ($modeControl['title'] ?? 'Payment method')); ?></span>
                                </label>
                            </div>
                            <small><?php echo htmlspecialchars((string) ($modeControl['copy'] ?? '')); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label>Reason <input name="reason" placeholder="Required operator reason" required></label>
                <button class="btn-premium-primary" type="submit">Save Payment Methods</button>
            </form>
        </section>

        <?php elseif ($packageSettingsSection === 'catalog_health'): ?>
        <section class="package-settings-section" id="catalog-health">
            <div>
                <h3>Catalog Health</h3>
                <p class="muted">Duplicate detection, inactive legacy rows, provider readiness warnings, and unsafe delete references.</p>
            </div>
            <div class="package-settings-actions">
                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(settingsPackageSettingsSectionUrl('catalog_health', ['export' => 'catalog_health'])); ?>">Export Catalog Health CSV</a>
            </div>
            <div class="package-settings-grid">
                <div class="package-settings-panel">
                    <h4>Duplicate Packages</h4>
                    <?php $duplicates = array_values((array) ($packageCatalogHealth['duplicate_packages'] ?? [])); ?>
                    <?php if ($duplicates === []): ?>
                        <small>No duplicate package names detected.</small>
                    <?php else: ?>
                        <?php foreach ($duplicates as $duplicate): ?>
                            <small><?php echo htmlspecialchars((string) ($duplicate['duplicate_key'] ?? '')); ?>: <?php echo htmlspecialchars((string) ($duplicate['packages'] ?? '')); ?></small>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="package-settings-panel">
                    <h4>Provider Readiness</h4>
                    <?php $missingProviders = array_values((array) ($packageCatalogHealth['missing_provider_plan_codes'] ?? [])); ?>
                    <?php if ($missingProviders === []): ?>
                        <small>Paid recurring card packages have provider readiness covered, or manual methods are available.</small>
                    <?php else: ?>
                        <?php foreach ($missingProviders as $warning): ?>
                            <small><?php echo htmlspecialchars((string) ($warning['plan_name'] ?? 'Package')); ?> / <?php echo htmlspecialchars((string) ($warning['price_code'] ?? 'price')); ?> is missing a Paystack plan code.</small>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="package-settings-panel">
                    <h4>Unsafe Deletes</h4>
                    <?php $unsafeDeletes = array_values((array) ($packageCatalogHealth['unsafe_deletes'] ?? [])); ?>
                    <?php if ($unsafeDeletes === []): ?>
                        <small>No referenced prices currently require archive-only behavior.</small>
                    <?php else: ?>
                        <?php foreach (array_slice($unsafeDeletes, 0, 8) as $row): ?>
                            <small><?php echo htmlspecialchars((string) ($row['label'] ?? 'Price')); ?> has historical references.</small>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php elseif ($packageSettingsSection === 'analytics'): ?>
        <section class="package-settings-section" id="package-analytics">
            <div>
                <h3>Analytics</h3>
                <p class="muted">Gross revenue, active subscriptions, checkout conversion, token pack purchases, and popularity.</p>
            </div>
            <form method="GET" class="package-settings-form package-settings-panel">
                <input type="hidden" name="tab" value="package_settings">
                        <?php settingsPackageSettingsHiddenSection($packageSettingsSection); ?>
                <div class="package-settings-fields">
                    <label>From <input type="date" name="analytics_from" value="<?php echo htmlspecialchars(substr((string) ($packageAnalyticsFilters['from'] ?? ''), 0, 10)); ?>"></label>
                    <label>To <input type="date" name="analytics_to" value="<?php echo htmlspecialchars(substr((string) ($packageAnalyticsFilters['to'] ?? ''), 0, 10)); ?>"></label>
                </div>
                <div class="package-settings-actions">
                    <button class="btn-premium-secondary" type="submit">Apply Window</button>
                    <small><?php echo htmlspecialchars((string) (($packageAnalytics['window']['label'] ?? '') ?: 'All time')); ?></small>
                </div>
            </form>
            <div class="package-settings-actions">
                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(settingsPackageSettingsSectionUrl('analytics', ['export' => 'package_analytics', 'analytics_from' => (string) ($packageAnalyticsFilters['from'] ?? ''), 'analytics_to' => (string) ($packageAnalyticsFilters['to'] ?? '')])); ?>">Export Analytics CSV</a>
            </div>
            <div class="package-settings-table-wrap">
                <table class="package-settings-table">
                    <thead><tr><th>Package</th><th>Active subscriptions</th><th>Paid checkouts</th><th>Popularity score</th></tr></thead>
                    <tbody>
                    <?php foreach (array_values((array) ($packageAnalytics['popularity'] ?? [])) as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($row['plan_name'] ?? $row['plan_code'] ?? 'Package')); ?></td>
                            <td><?php echo number_format((int) ($row['active_subscriptions'] ?? 0)); ?></td>
                            <td><?php echo number_format((int) ($row['paid_checkouts'] ?? 0)); ?></td>
                            <td><?php echo number_format((int) ($row['score'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="package-settings-section" id="package-audit">
            <div>
                <h3>Audit History</h3>
                <p class="muted">Recent package, payment, feature, and workspace billing operator changes.</p>
            </div>
            <div class="package-settings-table-wrap">
                <table class="package-settings-table">
                    <thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($packageOperatorAudit, 0, 30) as $audit): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($audit['created_at'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($audit['action_type'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($audit['actor_email'] ?? $audit['actor_user_id'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($audit['reason'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>
    <script>
        document.querySelectorAll('[data-payment-mode-control]').forEach(function (scopeGroup) {
            var form = scopeGroup.closest('form');
            if (!form) {
                return;
            }
            var checks = form.querySelector('[data-payment-mode-checks]');
            if (!checks) {
                return;
            }
            var update = function () {
                var custom = !!scopeGroup.querySelector('input[value="custom"]:checked');
                checks.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
                    input.disabled = !custom;
                });
            };
            scopeGroup.querySelectorAll('input[type="radio"]').forEach(function (input) {
                input.addEventListener('change', update);
            });
            update();
        });
        document.querySelectorAll('[data-workspace-search]').forEach(function (input) {
            var selectId = input.getAttribute('data-target-select') || '';
            var select = selectId === '' ? null : document.getElementById(selectId);
            if (!select) {
                return;
            }
            var count = input.parentElement ? input.parentElement.querySelector('[data-workspace-search-count]') : null;
            var options = Array.prototype.slice.call(select.options).filter(function (option) {
                return option.value !== '';
            });
            var update = function () {
                var query = input.value.trim().toLowerCase();
                var visible = 0;
                options.forEach(function (option) {
                    var haystack = option.getAttribute('data-search') || option.textContent.toLowerCase();
                    var match = query === '' || haystack.indexOf(query) !== -1;
                    option.hidden = !match;
                    option.disabled = !match;
                    if (match) {
                        visible += 1;
                    }
                });
                if (select.selectedOptions.length > 0 && select.selectedOptions[0].disabled) {
                    select.value = '';
                }
                if (count) {
                    count.textContent = visible + ' workspace' + (visible === 1 ? '' : 's') + ' match';
                }
            };
            input.addEventListener('input', update);
            update();
        });
    </script>
<?php endif; ?>
