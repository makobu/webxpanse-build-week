<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../views/partials/launch_package_features.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Security;
use CRM\Services\GuidedDemoPackageIntentService;
use CRM\Services\LaunchPackageCatalogService;
use CRM\Services\PackageNegotiationMeetingService;
use CRM\Services\PlatformLegalIdentityService;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user() ?: [];
$userId = (int) ($currentUser['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$presentationGuard = new PresentationWorkspaceGuardService();
if ($workspaceId > 0 && $presentationGuard->isBlocked('billing', $workspaceId)) {
    http_response_code(200);
    $pageTitle = 'Billing Disabled - ' . brandProductName();
    ob_start();
    ?>
    <section style="max-width:720px;margin:0 auto;background:#fff;border:1px solid var(--border-color);border-radius:8px;padding:24px;">
        <p style="margin:0 0 8px;color:#64748b;font-weight:700;text-transform:uppercase;font-size:12px;">Presentation workspace</p>
        <h1 style="margin:0 0 12px;color:var(--midnight-black);">Billing is disabled</h1>
        <p style="margin:0;color:var(--charcoal-grey);"><?php echo htmlspecialchars($presentationGuard->message('billing'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div style="margin-top:18px;">
            <a href="<?php echo htmlspecialchars(publicUrl('dashboard.php?presentation=1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-premium-primary" style="text-decoration:none;">Back to Dashboard</a>
        </div>
    </section>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/../views/layouts/base.php';
    exit;
}
$billing = $workspaceId > 0
    ? (new SaaSBillingService())->getWorkspaceBillingPortalData($workspaceId)
    : [
        'snapshot' => [
            'subscription_status' => 'inactive',
            'billing_blocked' => false,
            'token_balance' => 0,
            'available_tokens' => 0,
            'is_trial_active' => false,
            'trial_starts_at' => null,
            'trial_ends_at' => null,
            'ai_blocked_reason' => null,
        ],
        'plans' => [],
        'package_cards' => [],
        'token_packs' => [],
        'ledger' => [],
    ];
$billingPackageCards = (array) ($billing['package_cards'] ?? []);
if ($billingPackageCards === [] && !empty($billing['plans'])) {
    $billingPackageCards = (new LaunchPackageCatalogService())->cardsFromSubscriptionPrices((array) $billing['plans']);
}
$billingEntitlements = (array) ($billing['entitlements'] ?? []);
$summary = (array) ($billing['snapshot'] ?? []);
$packageExempt = !empty($summary['package_exempt']) || !empty($billingEntitlements['package_exempt']);
$transactions = (array) ($billing['transactions'] ?? []);
$checkoutSessions = (array) ($billing['checkout_sessions'] ?? []);
$paymentModes = array_values(array_filter((array) ($billing['payment_modes'] ?? []), static function (array $mode): bool {
    return !empty($mode['available']);
}));
$canManageBilling = Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], Auth::user());
$billingStatus = trim((string) ($_GET['billing_status'] ?? ''));
$billingCheckoutFeedback = Session::get('billing_checkout_feedback');
if ($billingCheckoutFeedback !== null) {
    Session::remove('billing_checkout_feedback');
}
$subscriptionStatus = (string) ($summary['subscription_status'] ?? 'inactive');
$subscriptionLabel = $packageExempt ? 'Package exempt' : ucwords(str_replace('_', ' ', $subscriptionStatus));
$tokenBalance = (int) ($summary['credit_balance'] ?? $summary['token_balance'] ?? 0);
$availableTokens = (int) ($summary['available_credits'] ?? $summary['available_tokens'] ?? 0);
$aiBlockedReason = (string) ($summary['ai_blocked_reason'] ?? '');
$aiAccessLabel = $packageExempt
    ? 'Available'
    : ($aiBlockedReason === 'wallet_depleted'
    ? 'Top-up required'
    : (!empty($summary['billing_blocked']) ? 'Workspace restricted' : 'Available'));
$latestTransaction = !empty($transactions[0]) ? (array) $transactions[0] : [];
$latestCheckout = !empty($checkoutSessions[0]) ? (array) $checkoutSessions[0] : [];
$activeSubscription = is_array($summary['subscription'] ?? null) ? (array) $summary['subscription'] : [];
$activePlanName = $packageExempt
    ? 'Default workspace package exempt'
    : (string) (($activeSubscription['plan_name'] ?? $activeSubscription['price_code'] ?? 'No active package'));
$pageTitle = 'Workspace Billing - ' . brandProductName();
$isBillingBlocked = !empty($summary['billing_blocked']);
$isWalletDepleted = $aiBlockedReason === 'wallet_depleted';
$headerTitle = $packageExempt
    ? 'Default workspace package exempt'
    : ($isBillingBlocked
    ? 'Workspace package update required'
    : 'Workspace packages and AI Credits');
$headerCopy = $packageExempt
    ? 'Package limits, billing prompts, and AI Credit wallet blocks do not apply to the protected default workspace.'
    : ($isBillingBlocked
    ? 'Choose or retry a workspace package to restore CRM access.'
    : ($isWalletDepleted
        ? 'Top up prepaid AI Credits to restore AI actions while core CRM remains available.'
        : 'Review workspace packages and prepaid AI Credit balance.'));
$primaryActionHref = $packageExempt
    ? publicUrl('billing_payment_required.php?tab=activity')
    : ($isWalletDepleted && !$isBillingBlocked
        ? publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill')
        : publicUrl('billing_payment_required.php?tab=packages#workspace-packages'));
$primaryActionLabel = $packageExempt ? 'View Activity' : ($isBillingBlocked ? 'Restore Access' : ($isWalletDepleted ? 'Buy AI Credits' : 'Review Packages'));
$statusChipClass = $packageExempt ? 'is-success' : ($isBillingBlocked ? 'is-danger' : ($isWalletDepleted ? 'is-warning' : 'is-success'));
$statusChipLabel = $packageExempt ? 'Package exempt' : ($isBillingBlocked ? 'Access suspended' : ($isWalletDepleted ? 'AI Credit top-up required' : 'Billing available'));

if (!function_exists('renderBillingPaymentRequiredModeFields')) {
    /**
     * @param list<array<string,mixed>> $availableModes
     */
    function renderBillingPaymentRequiredModeFields(array $availableModes): void
    {
        $availableModes = array_values(array_filter($availableModes, static function (array $mode): bool {
            return !empty($mode['available']);
        }));
        if ($availableModes === []) {
            ?>
            <input type="hidden" name="payment_mode" value="card">
            <input type="hidden" name="customer_phone" value="">
            <div class="billing-required-form-note is-warning">Payment methods are temporarily unavailable for this workspace.</div>
            <?php
            return;
        }

        $defaultMode = (string) ($availableModes[0]['key'] ?? 'card');
        $requiresPhone = false;
        $defaultRequiresPhone = false;
        foreach ($availableModes as $mode) {
            if (!empty($mode['requires_phone'])) {
                $requiresPhone = true;
            }
            if ((string) ($mode['key'] ?? '') === $defaultMode) {
                $defaultRequiresPhone = !empty($mode['requires_phone']);
            }
        }
        ?>
        <div class="billing-required-form-grid" data-billing-payment-mode-fields>
            <label class="billing-required-field">
                <span>Payment method</span>
                <select name="payment_mode" data-billing-payment-mode-select>
                    <?php foreach ($availableModes as $mode): ?>
                        <option value="<?php echo htmlspecialchars((string) ($mode['key'] ?? 'card')); ?>" data-requires-phone="<?php echo !empty($mode['requires_phone']) ? '1' : '0'; ?>" <?php echo ((string) ($mode['key'] ?? '') === $defaultMode) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) ($mode['label'] ?? ucfirst((string) ($mode['key'] ?? 'card')))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($requiresPhone): ?>
            <label class="billing-required-field" data-billing-payment-phone-field <?php echo $defaultRequiresPhone ? '' : 'hidden'; ?>>
                <span>M-Pesa phone number</span>
                <input type="tel" name="customer_phone" inputmode="tel" autocomplete="tel" placeholder="Phone number for M-Pesa" data-billing-payment-phone-input <?php echo $defaultRequiresPhone ? 'required' : 'disabled'; ?>>
            </label>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('billingPaymentRequiredHumanize')) {
    function billingPaymentRequiredHumanize(string $value, string $fallback = 'Pending'): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $normalized = strtolower(str_replace(['_', ' '], '-', $value));
        if (in_array($normalized, ['mpesa', 'm-pesa', 'm-pesa-stk'], true)) {
            return 'M-Pesa';
        }

        if ($normalized === 'card') {
            return 'Card';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}

if (!function_exists('billingPaymentRequiredDate')) {
    function billingPaymentRequiredDate(mixed $value): string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($date))->format('d M Y, H:i');
        } catch (Throwable) {
            return $date;
        }
    }
}

if (!function_exists('billingPaymentRequiredMoney')) {
    function billingPaymentRequiredMoney(mixed $amount, string $currency = 'KES'): string
    {
        if ($amount === null || $amount === '') {
            return 'Not recorded';
        }

        return trim($currency . ' ' . number_format((float) $amount, 2));
    }
}

if (!function_exists('billingPaymentRequiredStatusClass')) {
    function billingPaymentRequiredStatusClass(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['success', 'succeeded', 'paid', 'completed'], true)) {
            return 'is-success';
        }

        if (in_array($status, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
            return 'is-danger';
        }

        return 'is-warning';
    }
}

if (!function_exists('billingPaymentRequiredActivityRows')) {
    /**
     * @param list<array<string,mixed>> $transactions
     * @param list<array<string,mixed>> $checkoutSessions
     * @return list<array<string,string>>
     */
    function billingPaymentRequiredActivityRows(array $transactions, array $checkoutSessions, int $limit = 5): array
    {
        $rows = [];

        foreach ($transactions as $transaction) {
            $transaction = (array) $transaction;
            $status = (string) ($transaction['transaction_status'] ?? 'pending');
            $createdAt = (string) ($transaction['created_at'] ?? '');
            $rows[] = [
                'date' => billingPaymentRequiredDate($createdAt),
                'description' => billingPaymentRequiredHumanize((string) ($transaction['transaction_type'] ?? 'Billing transaction'), 'Billing transaction'),
                'method' => billingPaymentRequiredHumanize((string) ($transaction['payment_mode'] ?? ''), '-'),
                'amount' => billingPaymentRequiredMoney($transaction['amount'] ?? null, (string) ($transaction['currency'] ?? 'KES')),
                'status' => billingPaymentRequiredHumanize($status, 'Pending'),
                'status_class' => billingPaymentRequiredStatusClass($status),
                'receipt_url' => (string) (($transaction['billing_invoice']['url'] ?? '') ?: ''),
                'receipt_label' => (string) (($transaction['billing_invoice']['document_number'] ?? '') ?: 'Receipt'),
                'sort' => (string) strtotime($createdAt),
            ];
        }

        foreach ($checkoutSessions as $checkout) {
            $checkout = (array) $checkout;
            $status = (string) ($checkout['status'] ?? 'pending');
            $createdAt = (string) ($checkout['created_at'] ?? '');
            $rows[] = [
                'date' => billingPaymentRequiredDate($createdAt),
                'description' => billingPaymentRequiredHumanize((string) ($checkout['checkout_type'] ?? 'Checkout session'), 'Checkout session'),
                'method' => billingPaymentRequiredHumanize((string) ($checkout['payment_mode'] ?? ''), '-'),
                'amount' => billingPaymentRequiredMoney($checkout['amount'] ?? null, (string) ($checkout['currency'] ?? 'KES')),
                'status' => billingPaymentRequiredHumanize($status, 'Pending'),
                'status_class' => billingPaymentRequiredStatusClass($status),
                'receipt_url' => '',
                'receipt_label' => '',
                'sort' => (string) strtotime($createdAt),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return (int) ($b['sort'] ?? 0) <=> (int) ($a['sort'] ?? 0);
        });

        return array_slice(array_map(static function (array $row): array {
            unset($row['sort']);
            return $row;
        }, $rows), 0, $limit);
    }
}

if (!function_exists('billingPaymentRequiredTabUrl')) {
    function billingPaymentRequiredTabUrl(string $tab, string $fragment = ''): string
    {
        $path = 'billing_payment_required.php?tab=' . rawurlencode($tab);
        if ($fragment !== '') {
            $path .= '#' . ltrim($fragment, '#');
        }

        return function_exists('publicUrl') ? publicUrl($path) : $path;
    }
}

if (!function_exists('billingPaymentRequiredNormalizeCadence')) {
    function billingPaymentRequiredNormalizeCadence(mixed $value): string
    {
        $cadence = strtolower(trim((string) $value));
        return match ($cadence) {
            'year', 'yearly', 'annual' => 'annual',
            'month', 'monthly' => 'monthly',
            default => $cadence,
        };
    }
}

if (!function_exists('billingPaymentRequiredCheckoutOption')) {
    /**
     * @param list<array<string,mixed>> $checkoutOptions
     * @return array<string,mixed>|null
     */
    function billingPaymentRequiredCheckoutOption(array $checkoutOptions, string $cadence): ?array
    {
        $cadence = billingPaymentRequiredNormalizeCadence($cadence);
        foreach ($checkoutOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionCadence = billingPaymentRequiredNormalizeCadence($option['cadence'] ?? $option['interval_unit'] ?? '');
            if ($optionCadence === $cadence) {
                return $option;
            }
        }

        return null;
    }
}

if (!function_exists('billingPaymentRequiredAvailablePaymentModes')) {
    /**
     * @param array<string,mixed>|null $option
     * @return list<array<string,mixed>>
     */
    function billingPaymentRequiredAvailablePaymentModes(?array $option): array
    {
        if ($option === null) {
            return [];
        }

        return array_values(array_filter((array) ($option['payment_modes'] ?? []), static function ($mode): bool {
            return is_array($mode) && !empty($mode['available']);
        }));
    }
}

if (!function_exists('billingPaymentRequiredPackageRequiresMeeting')) {
    /**
     * @param array<string,mixed> $package
     */
    function billingPaymentRequiredPackageRequiresMeeting(array $package): bool
    {
        return !empty($package['is_custom'])
            || !empty($package['workspace_negotiated'])
            || !empty($package['is_workspace_private'])
            || !empty($package['metadata']['workspace_negotiated'])
            || !empty($package['metadata']['is_workspace_private'])
            || (string) ($package['code'] ?? $package['plan_code'] ?? '') === GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM;
    }
}

if (!function_exists('billingPaymentRequiredPackageIntentMetadata')) {
    /**
     * @param array<string,mixed> $package
     * @return array<string,mixed>
     */
    function billingPaymentRequiredPackageIntentMetadata(array $package): array
    {
        $keys = [
            'code',
            'plan_code',
            'display_name',
            'plan_name',
            'price',
            'price_short',
            'scope_label',
            'best_for',
            'duration',
            'deliverable',
            'price_amounts',
            'monthly_price',
            'annual_price',
            'checkout_options',
            'billing_cadence',
            'currency_code',
            'checkout_available',
            'inherits_from',
            'tier_intro',
            'feature_highlights',
            'feature_groups',
            'workspace_negotiated',
            'is_workspace_private',
            'is_custom',
        ];
        $metadata = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $package)) {
                $metadata[$key] = $package[$key];
            }
        }

        return $metadata;
    }
}

if (!function_exists('billingPaymentRequiredPricePayload')) {
    /**
     * @param array<string,mixed>|null $option
     * @param array<string,mixed> $plan
     * @return array{id:int,amount:string,period:string,caption:string}
     */
    function billingPaymentRequiredPricePayload(?array $option, array $plan, string $fallbackCadence = 'monthly'): array
    {
        $cadence = billingPaymentRequiredNormalizeCadence($option['cadence'] ?? $option['interval_unit'] ?? $fallbackCadence);
        if (!empty($plan['is_custom'])) {
            return [
                'id' => 0,
                'amount' => 'Custom',
                'period' => '',
                'caption' => 'Tailored to your needs',
            ];
        }

        if ($option !== null) {
            $amount = (float) ($option['amount'] ?? 0);
            $currency = (string) ($option['currency'] ?? $plan['currency_code'] ?? 'KES');
            $priceText = $amount <= 0 ? strtoupper($currency) . ' 0' : launchPackageFormatMoney($amount, $currency);

            return [
                'id' => (int) ($option['billing_plan_price_id'] ?? 0),
                'amount' => $priceText,
                'period' => $amount <= 0 ? '' : ($cadence === 'annual' ? '/yr' : '/mo'),
                'caption' => $amount <= 0
                    ? 'Free forever'
                    : ($cadence === 'annual' ? $priceText . ' billed annually' : 'Billed monthly'),
            ];
        }

        $fallbackPrice = trim((string) ($plan['price_short'] ?? $plan['price'] ?? ''));
        if ($fallbackPrice === '') {
            $fallbackPrice = !empty($plan['is_free']) ? 'KES 0' : 'Contact sales';
        }
        $fallbackParts = preg_split('/\s+or\s+/i', $fallbackPrice) ?: [];
        if (count($fallbackParts) > 1) {
            $fallbackPrice = trim((string) ($cadence === 'annual' ? ($fallbackParts[1] ?? $fallbackParts[0]) : $fallbackParts[0]));
        }
        $fallbackAmount = $fallbackPrice;
        $fallbackPeriod = '';
        if (preg_match('/^(.*?)(\/(?:mo|month|monthly|yr|year|yearly|annual))$/i', $fallbackPrice, $matches) === 1) {
            $fallbackAmount = trim((string) ($matches[1] ?? $fallbackPrice));
            $rawPeriod = strtolower((string) ($matches[2] ?? ''));
            $fallbackPeriod = in_array($rawPeriod, ['/yr', '/year', '/yearly', '/annual'], true) ? '/yr' : '/mo';
        }

        return [
            'id' => (int) ($plan['billing_plan_price_id'] ?? $plan['primary_billing_plan_price_id'] ?? 0),
            'amount' => $fallbackAmount,
            'period' => $fallbackPeriod,
            'caption' => !empty($plan['is_free'])
                ? 'Free forever'
                : ($fallbackPeriod === '/yr' ? $fallbackAmount . ' billed annually' : ($fallbackPeriod === '/mo' ? 'Billed monthly' : 'Package pricing')),
        ];
    }
}

$packageMeetingMessage = Session::get('billing_package_meeting_message');
$packageMeetingError = Session::get('billing_package_meeting_error');
Session::remove('billing_package_meeting_message');
Session::remove('billing_package_meeting_error');
$packageMeetingService = new PackageNegotiationMeetingService();
$packageMeetingTimezone = date_default_timezone_get() ?: 'UTC';
$packageMeetingSlots = [];
try {
    $packageMeetingState = $packageMeetingService->setupState();
    $packageMeetingTimezone = (string) ($packageMeetingState['timezone'] ?? $packageMeetingTimezone);
    $packageMeetingSlots = $packageMeetingService->availableSlots($packageMeetingTimezone, 21, 80);
} catch (Throwable $e) {
    $packageMeetingSlots = [];
    if ($packageMeetingError === null) {
        $packageMeetingError = 'Package meeting calendar is temporarily unavailable. Please try again shortly.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['package_action'] ?? '') === 'book_meeting') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new RuntimeException('Sign in to a workspace before booking support.');
        }

        $packageCode = trim((string) ($_POST['package_code'] ?? ''));
        $selected = null;
        foreach ($billingPackageCards as $package) {
            $candidateCode = (string) ($package['code'] ?? $package['plan_code'] ?? '');
            if ($candidateCode === $packageCode) {
                $selected = (array) $package;
                break;
            }
        }
        if ($selected === null) {
            throw new RuntimeException('Choose a package before booking support.');
        }
        if (!billingPaymentRequiredPackageRequiresMeeting($selected)) {
            throw new RuntimeException('Meeting booking is available for negotiated packages only.');
        }

        $meetingSlot = trim((string) ($_POST['meeting_slot'] ?? ''));
        $meetingTimezone = trim((string) ($_POST['meeting_timezone'] ?? ''));
        if ($meetingSlot === '') {
            throw new RuntimeException('Choose a meeting day and time.');
        }

        $bookingResult = $packageMeetingService->bookPackageMeeting(
            $workspaceId,
            $userId,
            null,
            $packageCode,
            $meetingSlot,
            $meetingTimezone !== '' ? $meetingTimezone : $packageMeetingTimezone,
            [
                'source_surface' => 'billing_payment_required',
                'selected_package' => billingPaymentRequiredPackageIntentMetadata($selected),
                'billing_snapshot' => [
                    'subscription_status' => (string) ($summary['subscription_status'] ?? ''),
                    'billing_blocked' => !empty($summary['billing_blocked']),
                    'active_plan_name' => (string) ($activePlanName ?? ''),
                ],
            ],
            new GuidedDemoPackageIntentService()
        );
        $booking = (array) ($bookingResult['booking'] ?? []);
        $bookingStatus = (string) ($booking['status'] ?? ($bookingResult['booking_result']['status'] ?? 'pending'));
        $bookingStart = trim((string) ($booking['scheduled_start'] ?? $meetingSlot));
        $statusLabel = $bookingStatus === 'confirmed' ? 'confirmed' : 'requested';
        Session::set('billing_package_meeting_message', 'Support meeting ' . $statusLabel . ' for ' . $bookingStart . ' ' . ($meetingTimezone !== '' ? $meetingTimezone : $packageMeetingTimezone) . '.');
    } catch (Throwable $e) {
        Session::set('billing_package_meeting_error', $e->getMessage());
    }

    header('Location: ' . publicUrl('billing_payment_required.php?tab=packages#workspace-packages'));
    exit;
}

$billingCurrency = (string) ($latestCheckout['currency'] ?? $latestTransaction['currency'] ?? $activeSubscription['currency'] ?? (($billing['plans'][0]['currency'] ?? $billingPackageCards[0]['currency_code'] ?? 'KES')));
$outstandingAmount = null;
$outstandingSince = '';
if (isset($latestCheckout['amount'])) {
    $outstandingAmount = $latestCheckout['amount'];
    $outstandingSince = billingPaymentRequiredDate($latestCheckout['created_at'] ?? '');
} elseif ($isBillingBlocked && isset($activeSubscription['amount'])) {
    $outstandingAmount = $activeSubscription['amount'];
    $outstandingSince = billingPaymentRequiredDate($activeSubscription['next_billing_at'] ?? $activeSubscription['current_period_end'] ?? '');
} elseif (isset($latestTransaction['amount']) && !in_array(strtolower((string) ($latestTransaction['transaction_status'] ?? '')), ['success', 'succeeded', 'paid', 'completed'], true)) {
    $outstandingAmount = $latestTransaction['amount'];
    $outstandingSince = billingPaymentRequiredDate($latestTransaction['created_at'] ?? '');
}
$outstandingLabel = billingPaymentRequiredMoney($outstandingAmount, $billingCurrency);
$outstandingHelp = $outstandingSince !== '-' && $outstandingSince !== '' ? 'Since ' . $outstandingSince : 'No pending checkout recorded';
$billingActivityRows = billingPaymentRequiredActivityRows($transactions, $checkoutSessions);
$billingDefaultPackageCode = '';
foreach ($billingPackageCards as $billingPackageCard) {
    $candidateCode = (string) ($billingPackageCard['code'] ?? $billingPackageCard['plan_code'] ?? '');
    if ($billingDefaultPackageCode === '' && !empty($activeSubscription['plan_code']) && $candidateCode === (string) $activeSubscription['plan_code']) {
        $billingDefaultPackageCode = $candidateCode;
        break;
    }
    if ($billingDefaultPackageCode === '' && !empty($billingPackageCard['is_default'])) {
        $billingDefaultPackageCode = $candidateCode;
    }
}
if ($billingDefaultPackageCode === '' && !empty($billingPackageCards[0])) {
    $billingDefaultPackageCode = (string) ($billingPackageCards[0]['code'] ?? $billingPackageCards[0]['plan_code'] ?? '');
}
$billingPlanCount = count($billingPackageCards);
$billingTokenPackCount = count((array) ($billing['token_packs'] ?? []));
$billingPlanGridClass = $billingPlanCount <= 1 ? 'is-single' : ($billingPlanCount === 2 ? 'is-pair' : 'is-many');
$billingTokenGridClass = $billingTokenPackCount <= 1 ? 'is-single' : ($billingTokenPackCount === 2 ? 'is-pair' : 'is-many');
$billingAvailableTabs = ['packages', 'tokens', 'activity', 'help'];
$billingRequestedTab = strtolower(trim((string) ($_GET['tab'] ?? '')));
$billingDefaultTab = $isWalletDepleted ? 'tokens' : 'packages';
$billingCurrentTab = in_array($billingRequestedTab, $billingAvailableTabs, true) ? $billingRequestedTab : $billingDefaultTab;
$billingTabs = [
    'packages' => [
        'label' => 'Packages',
        'description' => $isBillingBlocked ? 'Restore access' : 'Workspace plans',
        'icon' => 'fa-shield-halved',
        'fragment' => 'workspace-packages',
    ],
    'tokens' => [
        'label' => 'AI Credits',
        'description' => $isWalletDepleted ? 'Top-up required' : number_format($availableTokens) . ' available',
        'icon' => 'fa-coins',
        'fragment' => 'ai-token-refill',
    ],
    'activity' => [
        'label' => 'Activity',
        'description' => !empty($billingActivityRows) ? count($billingActivityRows) . ' recent signals' : 'No recent payments',
        'icon' => 'fa-receipt',
        'fragment' => 'payment-activity',
    ],
    'help' => [
        'label' => 'Help',
        'description' => 'Checklist and support',
        'icon' => 'fa-life-ring',
        'fragment' => 'billing-help',
    ],
];
$billingPageMeta = [
    'packages' => [
        'title' => $isBillingBlocked ? 'Restore Workspace Package' : 'Workspace Packages',
        'copy' => $isBillingBlocked
            ? 'Choose or retry a workspace package to restore CRM access.'
            : 'Compare package access, seats, included AI Credits, and plugin capability.',
        'icon' => 'fa-shield-halved',
        'tone' => $isBillingBlocked ? 'danger' : 'success',
        'action_href' => billingPaymentRequiredTabUrl('packages', 'workspace-packages'),
        'action_label' => $isBillingBlocked ? 'Restore Access' : 'Review Packages',
        'metric_label' => 'Current package',
        'metric_value' => $activePlanName,
        'metric_detail' => $subscriptionLabel . ' billing state',
    ],
    'tokens' => [
        'title' => $isWalletDepleted ? 'Restore AI Access with AI Credits' : 'AI Credit Wallet',
        'copy' => $isWalletDepleted
            ? 'Top up prepaid AI Credits to restore AI actions while core CRM remains available.'
            : 'Buy prepaid AI Credits for AI actions without changing the current package.',
        'icon' => 'fa-coins',
        'tone' => $isWalletDepleted ? 'warning' : 'success',
        'action_href' => billingPaymentRequiredTabUrl('tokens', 'ai-token-refill'),
        'action_label' => 'Buy AI Credits',
        'metric_label' => 'AI Credit Balance',
        'metric_value' => number_format($tokenBalance),
        'metric_detail' => number_format($availableTokens) . ' available AI Credits',
    ],
    'activity' => [
        'title' => 'Payment Activity',
        'copy' => 'Review the latest checkout and payment provider signals for this workspace.',
        'icon' => 'fa-receipt',
        'tone' => !empty($billingActivityRows) ? 'info' : 'muted',
        'action_href' => billingPaymentRequiredTabUrl('activity', 'payment-activity'),
        'action_label' => 'Review Activity',
        'metric_label' => 'Recent signals',
        'metric_value' => (string) count($billingActivityRows),
        'metric_detail' => count($billingActivityRows) === 1 ? 'payment event recorded' : 'payment events recorded',
    ],
    'help' => [
        'title' => 'Billing Help',
        'copy' => 'Use the recovery checklist or contact support when payment access needs human help.',
        'icon' => 'fa-life-ring',
        'tone' => 'info',
        'action_href' => billingPaymentRequiredTabUrl('help', 'billing-help'),
        'action_label' => 'Open Help',
        'metric_label' => 'Checklist',
        'metric_value' => '4 steps',
        'metric_detail' => 'Recovery path',
    ],
];
$billingCurrentMeta = (array) ($billingPageMeta[$billingCurrentTab] ?? $billingPageMeta[$billingDefaultTab] ?? $billingPageMeta['packages']);
$billingHeaderTone = (string) ($billingCurrentMeta['tone'] ?? 'success');
$billingHeaderIcon = (string) ($billingCurrentMeta['icon'] ?? 'fa-circle');
$billingPageClass = 'billing-required-page billing-required-page--' . $billingCurrentTab;
$workspaceAffordabilityHelpEmail = (string) ($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com');
$workspaceAffordabilityHelpUrl = 'mailto:' . $workspaceAffordabilityHelpEmail . '?subject=Workspace%20payment%20help';
$donationsEnabled = WorkspaceBillingSettings::donationsEnabled();
$platformSystemLegalName = (new PlatformLegalIdentityService())->systemLegalName();
ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/premium-pages.css') : 'assets/css/premium-pages.css'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/guided-demo.css') : 'assets/css/guided-demo.css'); ?>">
<div class="page-premium <?php echo htmlspecialchars($billingPageClass); ?>">
    <style>
        .billing-required-page {
            --billing-text: #07142f;
            --billing-muted: #536179;
            --billing-faint: #7a879a;
            --billing-border: rgba(7, 20, 47, 0.13);
            --billing-border-strong: rgba(7, 20, 47, 0.2);
            --billing-surface: #ffffff;
            --billing-soft: #f8fafc;
            --billing-blue: #0757f8;
            --billing-blue-soft: #f1f6ff;
            --billing-green: #07965f;
            --billing-green-soft: #effbf5;
            --billing-red: #e43d30;
            --billing-red-soft: #fff4f3;
            --billing-amber: #e27d00;
            --billing-amber-soft: #fff8ea;
            --billing-purple: #6d36e5;
            --billing-purple-soft: #f5f0ff;
            --billing-radius: 8px;
            --billing-shadow: 0 14px 38px rgba(7, 20, 47, 0.055);
            --billing-shadow-tight: 0 1px 2px rgba(7, 20, 47, 0.045);
            background: #ffffff;
            color: var(--billing-text);
        }
        .billing-required-page * {
            box-sizing: border-box;
        }
        .page-content--wide .page-premium.billing-required-page > .billing-required-wrap,
        .billing-required-wrap {
            max-width: 1360px;
            margin: 0 auto;
            padding-inline: clamp(1rem, 3vw, 2rem);
            padding-block: clamp(0.7rem, 1.5vw, 1.15rem) 1.4rem;
        }
        .billing-required-shell,
        .billing-required-main,
        .billing-required-side,
        .billing-required-package-form,
        .billing-required-token-form {
            display: grid;
            gap: 0.78rem;
        }
        .billing-required-breadcrumb {
            position: relative;
            margin-bottom: 0.1rem;
            padding-left: 0.72rem;
            color: #334155;
            font-size: 0.82rem;
            font-weight: 760;
            line-height: 1.25;
        }
        .billing-required-breadcrumb::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.1rem;
            bottom: 0.1rem;
            width: 2px;
            border-radius: 999px;
            background: #cbd5e1;
        }
        .billing-required-breadcrumb strong {
            color: var(--billing-text);
            font-weight: 820;
        }
        .billing-required-recovery {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(210px, 0.34fr) auto;
            gap: clamp(0.78rem, 1.6vw, 1.2rem);
            align-items: stretch;
            min-height: 7rem;
            padding: clamp(1rem, 1.6vw, 1.28rem);
            border: 1px solid rgba(7, 20, 47, 0.12);
            border-left: 5px solid var(--billing-blue);
            border-radius: var(--billing-radius);
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 251, 255, 0.98) 60%, rgba(239, 246, 255, 0.96) 100%),
                var(--billing-surface);
            box-shadow: 0 18px 46px rgba(7, 20, 47, 0.07);
        }
        .billing-required-recovery.is-success {
            border-left-color: var(--billing-green);
        }
        .billing-required-recovery.is-warning {
            border-left-color: var(--billing-amber);
            background:
                linear-gradient(135deg, #ffffff 0%, #fffaf0 62%, #eff6ff 100%),
                var(--billing-surface);
        }
        .billing-required-recovery.is-danger {
            border-left-color: var(--billing-red);
            background:
                linear-gradient(135deg, #ffffff 0%, #fff6f5 62%, #eff6ff 100%),
                var(--billing-surface);
        }
        .billing-required-recovery.is-muted {
            border-left-color: #64748b;
        }
        .billing-required-recovery-icon,
        .billing-required-donation-icon,
        .billing-required-plan-icon,
        .billing-required-step-icon,
        .billing-required-step-number,
        .billing-required-section-kicker,
        .billing-required-radio-dot {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border-radius: 999px;
        }
        .billing-required-recovery-icon {
            width: 2.7rem;
            height: 2.7rem;
            background: var(--billing-blue);
            color: #ffffff;
            font-size: 1.08rem;
            box-shadow: 0 14px 26px rgba(7, 87, 248, 0.18);
        }
        .billing-required-recovery.is-success .billing-required-recovery-icon {
            background: var(--billing-green);
            box-shadow: 0 14px 26px rgba(7, 150, 95, 0.16);
        }
        .billing-required-recovery.is-warning .billing-required-recovery-icon {
            background: var(--billing-amber);
            box-shadow: 0 14px 26px rgba(226, 125, 0, 0.15);
        }
        .billing-required-recovery.is-danger .billing-required-recovery-icon {
            background: var(--billing-red);
            box-shadow: 0 14px 26px rgba(228, 61, 48, 0.15);
        }
        .billing-required-recovery.is-muted .billing-required-recovery-icon {
            background: #64748b;
            box-shadow: 0 14px 26px rgba(71, 85, 105, 0.14);
        }
        .billing-required-recovery-body {
            display: grid;
            gap: 0.52rem;
            align-content: center;
            min-width: 0;
        }
        .billing-required-heading-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.82rem;
            align-items: center;
            min-width: 0;
        }
        .billing-required-status-row,
        .billing-required-actions,
        .billing-required-footer-actions,
        .billing-required-donation-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.58rem;
        }
        .billing-required-actions,
        .billing-required-footer-actions,
        .billing-required-donation-actions {
            justify-content: flex-end;
        }
        .billing-required-scope {
            display: inline-flex;
            align-items: center;
            min-height: 1.54rem;
            padding: 0.22rem 0;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 840;
            letter-spacing: 0.05em;
            line-height: 1.1;
            text-transform: uppercase;
        }
        .billing-required-actions a,
        .billing-required-payment-bar .btn-premium-primary,
        .billing-required-payment-bar .btn-premium-secondary,
        .billing-required-donation .btn-premium-secondary,
        .billing-required-footer-actions .btn-premium-secondary {
            justify-content: center;
            text-align: center;
            text-decoration: none;
        }
        .billing-required-actions .btn-premium-primary {
            min-width: 10.8rem;
            min-height: 2.72rem;
            box-shadow: 0 14px 28px rgba(7, 87, 248, 0.18);
        }
        .billing-required-command-metric {
            display: grid;
            align-content: center;
            justify-items: start;
            min-width: 0;
            padding: 0.82rem 0 0.82rem 1rem;
            border-left: 1px solid rgba(7, 20, 47, 0.12);
        }
        .billing-required-command-metric span {
            display: block;
            color: var(--billing-muted);
            font-size: 0.7rem;
            font-weight: 850;
            letter-spacing: 0.05em;
            line-height: 1.15;
            text-transform: uppercase;
        }
        .billing-required-command-metric strong {
            display: block;
            margin-top: 0.22rem;
            color: var(--billing-text);
            font-size: clamp(1.42rem, 2.1vw, 2.1rem);
            font-weight: 880;
            letter-spacing: 0;
            line-height: 1.02;
            overflow-wrap: anywhere;
        }
        .billing-required-command-metric small {
            display: block;
            margin-top: 0.22rem;
            color: var(--billing-muted);
            font-size: 0.78rem;
            font-weight: 720;
            line-height: 1.25;
        }
        .billing-required-title {
            margin: 0;
            color: var(--billing-text);
            font-size: clamp(1.64rem, 2.15vw, 2.22rem);
            font-weight: 880;
            letter-spacing: 0;
            line-height: 1.08;
        }
        .billing-required-copy {
            margin: 0;
            max-width: 760px;
            color: #24324a;
            font-size: 0.94rem;
            line-height: 1.45;
        }
        .billing-required-system-legal {
            margin: 0.38rem 0 0;
            max-width: 760px;
            color: var(--billing-muted);
            font-size: 0.82rem;
            line-height: 1.38;
        }
        .billing-required-system-legal strong {
            color: var(--billing-text);
            font-weight: 760;
        }
        .billing-required-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.34rem;
            padding: 0.28rem 0.58rem;
            border: 1px solid rgba(7, 87, 248, 0.18);
            border-radius: 999px;
            background: var(--billing-blue-soft);
            color: #1d4ed8;
            font-size: 0.72rem;
            font-weight: 820;
            line-height: 1.1;
            white-space: nowrap;
        }
        .billing-required-chip.is-danger {
            border-color: rgba(228, 61, 48, 0.2);
            background: var(--billing-red-soft);
            color: #be261b;
        }
        .billing-required-chip.is-success {
            border-color: rgba(7, 150, 95, 0.22);
            background: var(--billing-green-soft);
            color: #047857;
        }
        .billing-required-chip.is-warning {
            border-color: rgba(226, 125, 0, 0.22);
            background: var(--billing-amber-soft);
            color: #9a5500;
        }
        .billing-required-alert {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            padding: 0.76rem 0.88rem;
            border: 1px solid transparent;
            border-radius: var(--billing-radius);
            font-size: 0.88rem;
            line-height: 1.42;
        }
        .billing-required-alert i {
            margin-top: 0.16rem;
            flex: 0 0 auto;
        }
        .billing-required-alert.is-success {
            border-color: rgba(7, 150, 95, 0.2);
            background: var(--billing-green-soft);
            color: #166534;
        }
        .billing-required-alert.is-danger {
            border-color: rgba(228, 61, 48, 0.22);
            background: var(--billing-red-soft);
            color: #991b1b;
        }
        .billing-required-alert.is-info {
            border-color: rgba(8, 145, 178, 0.16);
            background: #ecfeff;
            color: #155e75;
        }
        .billing-required-alert.is-warning {
            border-color: rgba(226, 125, 0, 0.24);
            background: var(--billing-amber-soft);
            color: #8a4a00;
        }
        .billing-required-field span {
            display: block;
            color: var(--billing-muted);
            font-size: 0.68rem;
            font-weight: 820;
            letter-spacing: 0.05em;
            line-height: 1.15;
            text-transform: uppercase;
        }
        .billing-required-tabs {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.28rem;
            padding: 0.28rem;
            border: 1px solid rgba(7, 20, 47, 0.1);
            border-radius: var(--billing-radius);
            background: #f8fafc;
            box-shadow: var(--billing-shadow-tight);
        }
        .billing-required-tab {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.58rem;
            align-items: center;
            min-width: 0;
            min-height: 3.42rem;
            padding: 0.6rem 0.7rem 0.6rem 0.84rem;
            border: 1px solid transparent;
            border-radius: 6px;
            background: transparent;
            color: var(--billing-text);
            text-decoration: none;
            box-shadow: none;
            transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .billing-required-tab::before {
            content: "";
            position: absolute;
            left: 0.36rem;
            top: 0.58rem;
            bottom: 0.58rem;
            width: 3px;
            border-radius: 999px;
            background: transparent;
        }
        .billing-required-tab:hover,
        .billing-required-tab:focus-visible {
            border-color: rgba(7, 87, 248, 0.28);
            background: #ffffff;
            color: var(--billing-text);
            outline: none;
            box-shadow: 0 10px 22px rgba(7, 20, 47, 0.055);
            transform: translateY(-1px);
        }
        .billing-required-tab[aria-current="page"] {
            border-color: rgba(7, 87, 248, 0.9);
            background: var(--billing-blue);
            color: #ffffff;
            box-shadow: 0 14px 28px rgba(7, 87, 248, 0.18);
        }
        .billing-required-tab[aria-current="page"]::before {
            background: rgba(255, 255, 255, 0.9);
        }
        .billing-required-tab-icon {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #ffffff;
            color: var(--billing-muted);
            font-size: 0.82rem;
            box-shadow: inset 0 0 0 1px rgba(7, 20, 47, 0.08);
        }
        .billing-required-tab[aria-current="page"] .billing-required-tab-icon {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.32);
        }
        .billing-required-tab strong,
        .billing-required-tab small {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .billing-required-tab strong {
            color: var(--billing-text);
            font-size: 0.88rem;
            font-weight: 850;
            line-height: 1.12;
        }
        .billing-required-tab[aria-current="page"] strong {
            color: #ffffff;
        }
        .billing-required-tab small {
            margin-top: 0.16rem;
            color: var(--billing-muted);
            font-size: 0.74rem;
            line-height: 1.2;
        }
        .billing-required-tab[aria-current="page"] small {
            color: rgba(255, 255, 255, 0.82);
        }
        .billing-required-tab-panel,
        .billing-required-help-grid {
            display: grid;
            gap: 0.82rem;
        }
        .billing-required-page--packages .billing-required-wrap {
            padding-block: 0.55rem 1.2rem;
        }
        .billing-required-page--packages .billing-required-shell {
            gap: 0.58rem;
        }
        .billing-required-page--packages .billing-required-title {
            font-size: clamp(1.42rem, 1.9vw, 1.92rem);
            line-height: 1.12;
        }
        .billing-required-page--packages .billing-required-copy {
            font-size: 0.9rem;
            line-height: 1.38;
        }
        .billing-required-page--packages .billing-required-chip {
            padding: 0.22rem 0.5rem;
            font-size: 0.68rem;
        }
        .billing-required-page--packages .billing-required-actions .btn-premium-primary {
            min-height: 2.35rem;
            min-width: 9.8rem;
            box-shadow: none;
        }
        .billing-required-page--packages .billing-required-context-alert {
            display: none;
        }
        .billing-required-page--packages .billing-required-tabs {
            gap: 0.28rem;
        }
        .billing-required-page--packages .billing-required-tab {
            gap: 0.48rem;
            min-height: 3.16rem;
            padding: 0.52rem 0.62rem 0.52rem 0.82rem;
        }
        .billing-required-page--packages .billing-required-tab-icon {
            width: 1.82rem;
            height: 1.82rem;
            font-size: 0.76rem;
        }
        .billing-required-page--packages .billing-required-tab strong {
            font-size: 0.82rem;
        }
        .billing-required-page--packages .billing-required-tab small {
            margin-top: 0.08rem;
            font-size: 0.7rem;
        }
        .billing-required-page--packages .billing-required-tab-panel {
            gap: 0.58rem;
        }
        .billing-required-page--packages #workspace-packages {
            scroll-margin-top: 8rem;
            padding: clamp(1.1rem, 1.45vw, 1.35rem);
        }
        .billing-required-page--packages #workspace-packages .billing-required-section-head {
            margin-bottom: 0.12rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-grid {
            gap: clamp(0.95rem, 1.35vw, 1.18rem);
        }
        .billing-required-page--packages #workspace-packages .billing-required-package-card {
            gap: clamp(0.86rem, 1.12vw, 1.05rem);
            padding: clamp(1rem, 1.45vw, 1.28rem);
        }
        .billing-required-page--packages #workspace-packages .billing-required-plan-heading {
            gap: 0.82rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-plan-icon {
            width: 2.8rem;
            height: 2.8rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-price {
            padding-bottom: 0.78rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-tier-intro {
            padding: 0.72rem 0.82rem;
            line-height: 1.42;
        }
        .billing-required-page--packages #workspace-packages .billing-required-feature-list {
            gap: 0.58rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-feature-list li {
            padding-left: 1.32rem;
            line-height: 1.42;
        }
        .billing-required-page--packages #workspace-packages .billing-required-cadence {
            gap: 0.58rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-cadence__option {
            min-height: 3.55rem;
            padding: 0.66rem 0.68rem;
        }
        .billing-required-page--packages #workspace-packages .billing-required-detail-trigger {
            margin-top: 0.08rem;
            padding: 0.5rem 0.68rem;
        }
        .billing-required-help-grid {
            grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
            align-items: start;
        }
        .billing-required-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.58fr) minmax(340px, 0.84fr);
            gap: 0.86rem;
            align-items: start;
        }
        .billing-required-side {
            position: sticky;
            top: 5.25rem;
            align-self: start;
        }
        .billing-required-section,
        .billing-required-checklist,
        .billing-required-donation {
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: var(--billing-surface);
            box-shadow: var(--billing-shadow-tight);
        }
        .billing-required-section,
        .billing-required-checklist {
            display: grid;
            gap: 0.82rem;
            padding: clamp(0.92rem, 1.2vw, 1.08rem);
        }
        #workspace-packages,
        #ai-token-refill {
            scroll-margin-top: 240px;
        }
        .billing-required-section-head {
            display: flex;
            justify-content: space-between;
            gap: 0.82rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .billing-required-section-head h2,
        .billing-required-checklist h2,
        .billing-required-donation h2 {
            margin: 0;
            color: var(--billing-text);
            font-size: 1.01rem;
            font-weight: 840;
            letter-spacing: 0;
            line-height: 1.24;
        }
        .billing-required-section-head p,
        .billing-required-donation p {
            margin: 0.24rem 0 0;
            max-width: 660px;
            color: var(--billing-muted);
            font-size: 0.86rem;
            line-height: 1.42;
        }
        .billing-required-section-kicker {
            width: 1.72rem;
            height: 1.72rem;
            margin-right: 0.4rem;
            background: var(--billing-blue-soft);
            color: var(--billing-blue);
            font-size: 0.82rem;
            vertical-align: middle;
        }
        .billing-required-currency {
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            min-height: 2.35rem;
            padding: 0.5rem 0.7rem;
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: #ffffff;
            color: #23314a;
            font-size: 0.86rem;
            font-weight: 760;
            white-space: nowrap;
        }
        .billing-required-status-badges {
            display: grid;
            gap: 0.62rem;
        }
        .billing-required-status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 1.7rem;
            width: fit-content;
            max-width: 100%;
            padding: 0.34rem 0.52rem;
            border: 1px solid var(--billing-border);
            border-radius: 999px;
            background: #ffffff;
            color: #334155;
            font-size: 0.72rem;
            font-weight: 820;
            line-height: 1.15;
        }
        .billing-required-status-badges {
            grid-template-columns: repeat(2, minmax(0, max-content));
            align-items: center;
            justify-content: start;
        }
        .billing-required-status-pill.is-current {
            border-color: rgba(15, 118, 110, 0.28);
            background: #ecfdf5;
            color: #0f766e;
        }
        .billing-required-status-pill.is-upgrade {
            border-color: rgba(7, 87, 248, 0.24);
            background: var(--billing-blue-soft);
            color: var(--billing-blue);
        }
        .billing-required-status-pill.is-locked {
            border-color: rgba(217, 119, 6, 0.22);
            background: #fffbeb;
            color: #92400e;
        }
        .billing-required-card-note {
            margin: 0;
            color: var(--billing-muted);
            font-size: 0.78rem;
            line-height: 1.36;
        }
        .billing-required-grid,
        .billing-required-token-grid {
            display: grid;
            gap: 0.74rem;
        }
        .billing-required-grid.is-many {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .billing-required-grid.is-pair {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .billing-required-grid.is-single,
        .billing-required-token-grid.is-single {
            grid-template-columns: minmax(0, 1fr);
        }
        .billing-required-token-grid.is-many {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .billing-required-token-grid.is-pair {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .billing-required-choice {
            position: relative;
            min-width: 0;
            cursor: pointer;
        }
        .billing-required-option-input {
            position: absolute;
            inset: 0;
            opacity: 0;
            pointer-events: none;
        }
        .billing-required-choice-inner {
            position: relative;
            display: grid;
            min-height: 100%;
            gap: 0.76rem;
            padding: 1rem;
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: #ffffff;
            box-shadow: var(--billing-shadow-tight);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .billing-required-choice:hover .billing-required-choice-inner {
            border-color: rgba(7, 87, 248, 0.32);
            box-shadow: 0 14px 30px rgba(7, 20, 47, 0.07);
            transform: translateY(-1px);
        }
        .billing-required-option-input:focus-visible + .billing-required-choice-inner {
            outline: 3px solid rgba(7, 87, 248, 0.16);
            outline-offset: 2px;
        }
        .billing-required-option-input:checked + .billing-required-choice-inner {
            border-color: var(--billing-blue);
            box-shadow: 0 0 0 1px rgba(7, 87, 248, 0.12), 0 18px 38px rgba(7, 87, 248, 0.1);
        }
        .billing-required-choice.is-default .billing-required-choice-inner::before {
            content: "Recommended";
            position: absolute;
            top: -0.58rem;
            left: 50%;
            z-index: 1;
            transform: translateX(-50%);
            padding: 0.22rem 0.58rem;
            border-radius: 5px;
            background: var(--billing-blue);
            color: #ffffff;
            font-size: 0.66rem;
            font-weight: 850;
            letter-spacing: 0.035em;
            line-height: 1.1;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .billing-required-plan-heading {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.68rem;
            align-items: center;
        }
        .billing-required-plan-icon {
            width: 2.55rem;
            height: 2.55rem;
            background: var(--billing-green-soft);
            color: var(--billing-green);
            font-size: 1rem;
        }
        .billing-required-choice.is-default .billing-required-plan-icon {
            background: var(--billing-blue-soft);
            color: var(--billing-blue);
        }
        .billing-required-plan-icon.is-purple {
            background: var(--billing-purple-soft);
            color: var(--billing-purple);
        }
        .billing-required-plan-name {
            display: block;
            color: var(--billing-text);
            font-size: 1rem;
            font-weight: 850;
            line-height: 1.22;
        }
        .billing-required-plan-description {
            display: block;
            margin-top: 0.1rem;
            color: #34445e;
            font-size: 0.82rem;
            line-height: 1.34;
        }
        .billing-required-price {
            display: block;
            padding-bottom: 0.62rem;
            border-bottom: 1px solid rgba(7, 20, 47, 0.08);
            color: var(--billing-blue);
            font-size: 1.22rem;
            font-weight: 850;
            letter-spacing: 0;
            line-height: 1.1;
        }
        .billing-required-price small {
            color: var(--billing-muted);
            font-size: 0.72rem;
            font-weight: 740;
        }
        .billing-required-choice:not(.is-default) .billing-required-price.is-green {
            color: var(--billing-green);
        }
        .billing-required-choice:not(.is-default) .billing-required-price.is-purple {
            color: var(--billing-purple);
        }
        .billing-required-tier-intro {
            display: block;
            padding: 0.58rem 0.68rem;
            border-left: 3px solid var(--billing-blue);
            border-radius: 8px;
            background: #f8fafc;
            color: #1f2a44;
            font-size: 0.8rem;
            font-weight: 760;
            line-height: 1.34;
        }
        .billing-required-feature-list {
            display: grid;
            gap: 0.48rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .billing-required-feature-list li {
            position: relative;
            padding-left: 1.18rem;
            color: #25324b;
            font-size: 0.82rem;
            line-height: 1.32;
        }
        .billing-required-feature-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0.34em;
            width: 0.68rem;
            height: 0.68rem;
            border-radius: 999px;
            background: var(--billing-green-soft);
            box-shadow: inset 0 0 0 2px var(--billing-green);
        }
        .billing-required-choice.is-default .billing-required-feature-list li::before {
            background: var(--billing-blue-soft);
            box-shadow: inset 0 0 0 2px var(--billing-blue);
        }
        .billing-required-package-card {
            position: relative;
            display: grid;
            gap: 0.68rem;
            align-content: start;
            min-width: 0;
            min-height: 100%;
            padding: 0.88rem;
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: #ffffff;
            box-shadow: var(--billing-shadow-tight);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .billing-required-package-card:hover {
            border-color: rgba(7, 87, 248, 0.32);
            box-shadow: 0 14px 30px rgba(7, 20, 47, 0.07);
            transform: translateY(-1px);
        }
        .billing-required-package-card.is-selected,
        .billing-required-package-card.is-detail-active {
            border-color: var(--billing-blue);
            box-shadow: 0 0 0 1px rgba(7, 87, 248, 0.12), 0 16px 34px rgba(7, 87, 248, 0.08);
        }
        .billing-required-package-card.is-default::before {
            content: "Recommended";
            position: absolute;
            top: -0.58rem;
            left: 50%;
            z-index: 1;
            transform: translateX(-50%);
            padding: 0.22rem 0.58rem;
            border-radius: 5px;
            background: var(--billing-blue);
            color: #ffffff;
            font-size: 0.66rem;
            font-weight: 850;
            letter-spacing: 0.035em;
            line-height: 1.1;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .billing-required-cadence {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.42rem;
        }
        .billing-required-cadence__option {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.12rem 0.42rem;
            align-items: center;
            min-height: 3.25rem;
            padding: 0.52rem;
            border: 1px solid var(--billing-border);
            border-radius: 8px;
            background: #f8fafc;
            cursor: pointer;
        }
        .billing-required-cadence__option:has(input:checked) {
            border-color: var(--billing-blue);
            background: #ffffff;
            box-shadow: 0 0 0 1px rgba(7, 87, 248, 0.1);
        }
        .billing-required-cadence__option input {
            grid-row: span 2;
            width: 1rem;
            height: 1rem;
            margin: 0;
        }
        .billing-required-cadence__label {
            color: var(--billing-muted);
            font-size: 0.7rem;
            font-weight: 850;
            line-height: 1.1;
            text-transform: uppercase;
        }
        .billing-required-cadence__price {
            color: var(--billing-text);
            font-size: 0.84rem;
            line-height: 1.14;
        }
        .billing-required-cadence__meta {
            grid-column: 2;
            color: var(--billing-muted);
            font-size: 0.66rem;
            line-height: 1.1;
        }
        .billing-required-detail-trigger {
            justify-self: start;
            min-height: 2rem;
            padding: 0.4rem 0.58rem;
            border: 1px solid var(--billing-border);
            border-radius: 7px;
            background: #ffffff;
            color: #334155;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 840;
            line-height: 1;
            cursor: pointer;
        }
        .billing-required-detail-trigger:hover,
        .billing-required-detail-trigger:focus-visible,
        .billing-required-package-card.is-detail-active .billing-required-detail-trigger {
            border-color: var(--billing-blue);
            color: var(--billing-blue);
            outline: none;
        }
        .billing-required-feature-details,
        .launch-package-feature-details {
            border: 1px solid var(--billing-border);
            border-radius: 8px;
            background: #ffffff;
        }
        .launch-package-feature-details summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.72rem;
            min-height: 2.28rem;
            padding: 0.58rem 0.68rem;
            list-style: none;
            cursor: pointer;
            color: #1f2a44;
            font-size: 0.8rem;
            font-weight: 820;
            line-height: 1.2;
        }
        .launch-package-feature-details summary::-webkit-details-marker {
            display: none;
        }
        .launch-package-feature-details summary::after {
            content: '+';
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 1.22rem;
            height: 1.22rem;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: 0.92rem;
            line-height: 1;
        }
        .launch-package-feature-details[open] summary::after {
            content: '-';
        }
        .launch-package-feature-details__body {
            display: grid;
            gap: 0.62rem;
            padding: 0 0.68rem 0.72rem;
        }
        .launch-package-feature-details__fit,
        .launch-package-feature-details__group {
            display: grid;
            gap: 0.35rem;
        }
        .launch-package-feature-details__fit p {
            margin: 0;
            color: #475569;
            font-size: 0.78rem;
            line-height: 1.36;
        }
        .launch-package-feature-details__group h4 {
            margin: 0;
            color: var(--billing-text);
            font-size: 0.78rem;
            font-weight: 820;
            line-height: 1.22;
        }
        .launch-package-feature-details__group ul {
            display: grid;
            gap: 0.36rem;
            margin: 0;
            padding-left: 1rem;
            color: #334155;
            font-size: 0.78rem;
            line-height: 1.34;
        }
        body.launch-package-modal-open {
            overflow: hidden;
        }
        .billing-required-page .launch-package-modal {
            position: fixed;
            inset: 0;
            z-index: 1400;
            display: grid;
            place-items: center;
            padding: clamp(1rem, 3vw, 2rem);
        }
        .billing-required-page .launch-package-modal[hidden] {
            display: none;
        }
        .billing-required-page .launch-package-modal__backdrop {
            position: absolute;
            inset: 0;
            border: 0;
            background: rgba(15, 23, 42, 0.56);
            backdrop-filter: blur(8px);
            cursor: pointer;
        }
        .billing-required-page .launch-package-modal__dialog {
            position: relative;
            z-index: 1;
            width: min(760px, 100%);
            max-height: min(760px, calc(100vh - 2rem));
            overflow: auto;
            padding: clamp(1.1rem, 2.2vw, 1.55rem);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%), #ffffff;
            box-shadow: 0 30px 90px rgba(15, 23, 42, 0.32), 0 1px 0 rgba(255, 255, 255, 0.7) inset;
        }
        .billing-required-page .launch-package-modal__dialog:focus {
            outline: none;
        }
        .billing-required-page .launch-package-modal__close {
            position: absolute;
            top: 0.86rem;
            right: 0.86rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.1rem;
            height: 2.1rem;
            border: 1px solid #dbe4ee;
            border-radius: 999px;
            background: #ffffff;
            color: #334155;
            font-size: 1.35rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        .billing-required-page .launch-package-modal__close:hover,
        .billing-required-page .launch-package-modal__close:focus-visible {
            border-color: var(--billing-text);
            color: var(--billing-text);
            outline: none;
        }
        .billing-required-page .launch-package-modal__panel {
            display: grid;
            gap: 1rem;
        }
        .billing-required-page .launch-package-modal__panel[hidden] {
            display: none;
        }
        .billing-required-page .launch-package-modal__header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            padding-right: 2.5rem;
        }
        .billing-required-page .launch-package-modal__eyebrow,
        .billing-required-page .launch-package-modal__header h3,
        .billing-required-page .launch-package-modal__tier,
        .billing-required-page .launch-package-modal__fit p,
        .billing-required-page .launch-package-modal__group h4 {
            margin: 0;
        }
        .billing-required-page .launch-package-modal__eyebrow {
            color: var(--billing-muted);
            font-size: 0.74rem;
            font-weight: 850;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .billing-required-page .launch-package-modal__header h3 {
            margin-top: 0.22rem;
            color: var(--billing-text);
            font-size: clamp(1.62rem, 3vw, 2.15rem);
            font-weight: 800;
            line-height: 1.08;
        }
        .billing-required-page .launch-package-modal__price {
            flex: 0 0 auto;
            margin-top: 0.16rem;
            padding: 0.48rem 0.66rem;
            border: 1px solid #dbe4ee;
            border-radius: 8px;
            background: #ffffff;
            color: var(--billing-text);
            font-size: 0.84rem;
            font-weight: 850;
            white-space: nowrap;
        }
        .billing-required-page .launch-package-modal__tier {
            padding: 0.78rem 0.88rem;
            border-left: 4px solid var(--billing-blue);
            border-radius: 8px;
            background: #ffffff;
            color: #1e293b;
            font-size: 0.92rem;
            font-weight: 800;
            line-height: 1.42;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.04);
        }
        .billing-required-page .launch-package-modal__fit {
            display: grid;
            gap: 0.42rem;
            padding: 0.82rem 0.9rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.72);
        }
        .billing-required-page .launch-package-modal__fit p {
            color: #475569;
            font-size: 0.9rem;
            line-height: 1.44;
        }
        .billing-required-page .launch-package-modal__fit strong {
            color: var(--billing-text);
        }
        .billing-required-page .launch-package-modal__capability {
            display: grid;
            gap: 0.52rem;
            padding: 0.88rem;
            border: 1px solid #dbe4ee;
            border-radius: 8px;
            background: #f8fafc;
        }
        .billing-required-page .launch-package-modal__capability h4 {
            margin: 0;
            color: var(--billing-text);
            font-size: 0.86rem;
            font-weight: 850;
        }
        .billing-required-page .launch-package-modal__capability ul {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 0.44rem 0.88rem;
            margin: 0;
            padding-left: 1.05rem;
            color: #334155;
            font-size: 0.86rem;
            line-height: 1.42;
        }
        .billing-required-page .launch-package-modal__highlights {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .billing-required-page .launch-package-modal__highlights span {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.58rem;
            border: 1px solid #dbe4ee;
            border-radius: 999px;
            background: #ffffff;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 750;
        }
        .billing-required-page .launch-package-modal__groups {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 0.8rem;
        }
        .billing-required-page .launch-package-modal__group {
            display: grid;
            gap: 0.52rem;
            min-width: 0;
            padding: 0.88rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .billing-required-page .launch-package-modal__group h4 {
            color: var(--billing-text);
            font-size: 0.92rem;
            font-weight: 850;
        }
        .billing-required-page .launch-package-modal__group ul {
            display: grid;
            gap: 0.44rem;
            margin: 0;
            padding-left: 1.05rem;
            color: #334155;
            font-size: 0.88rem;
            line-height: 1.42;
        }
        .billing-required-choice-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.34rem;
            padding: 0.5rem 0.7rem;
            border: 1px solid rgba(7, 87, 248, 0.72);
            border-radius: 7px;
            color: var(--billing-blue);
            font-size: 0.86rem;
            font-weight: 830;
            line-height: 1.1;
            text-align: center;
        }
        .billing-required-option-input:checked + .billing-required-choice-inner .billing-required-choice-button {
            background: var(--billing-blue);
            color: #ffffff;
            box-shadow: 0 12px 22px rgba(7, 87, 248, 0.16);
        }
        .billing-required-radio-dot {
            width: 1rem;
            height: 1rem;
            margin: 0 auto;
            border: 1px solid #94a3b8;
            background: #ffffff;
        }
        .billing-required-option-input:checked + .billing-required-choice-inner .billing-required-radio-dot {
            border: 4px solid var(--billing-blue);
        }
        .billing-required-token-grid .billing-required-choice-inner {
            overflow: hidden;
            min-height: auto;
            gap: 0.38rem;
            padding: 1rem 3rem 1rem 1rem;
            text-align: left;
            border-left: 4px solid transparent;
        }
        .billing-required-token-grid .billing-required-option-input:checked + .billing-required-choice-inner {
            border-left-color: var(--billing-blue);
            background: linear-gradient(135deg, #ffffff 0%, #f4f8ff 100%);
        }
        .billing-required-token-grid .billing-required-plan-name {
            font-size: clamp(1.02rem, 1.25vw, 1.2rem);
            line-height: 1.18;
        }
        .billing-required-token-grid .billing-required-price {
            padding-bottom: 0;
            border-bottom: 0;
            color: #1f2a44;
            font-size: 0.94rem;
            font-weight: 840;
        }
        .billing-required-token-grid .billing-required-radio-dot {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
        }
        .billing-required-payment-bar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.82rem;
            align-items: end;
            padding: 0.86rem;
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: #ffffff;
        }
        .billing-required-token-form .billing-required-payment-bar {
            align-items: center;
        }
        .billing-required-payment-bar.is-payment-unavailable {
            border-color: rgba(226, 125, 0, 0.28);
            background: #fffaf0;
        }
        .billing-required-payment-bar.is-payment-unavailable .btn-premium-secondary {
            opacity: 0.62;
            filter: saturate(0.65);
        }
        .billing-required-payment-bar .btn-premium-primary,
        .billing-required-payment-bar .btn-premium-secondary {
            min-width: 12.6rem;
            min-height: 2.5rem;
            white-space: nowrap;
        }
        .billing-required-payment-note {
            margin: 0.28rem 0 0;
            color: var(--billing-muted);
            font-size: 0.74rem;
            line-height: 1.32;
            text-align: right;
        }
        .billing-required-form-grid {
            display: grid;
            grid-template-columns: minmax(150px, 0.78fr) minmax(200px, 1fr);
            gap: 0.58rem;
            align-items: end;
        }
        .billing-required-field {
            display: grid;
            gap: 0.3rem;
            min-width: 0;
        }
        .billing-required-form-grid select,
        .billing-required-form-grid input {
            width: 100%;
            min-height: 2.44rem;
            padding: 0.56rem 0.68rem;
            border: 1px solid rgba(7, 20, 47, 0.16);
            border-radius: var(--billing-radius);
            background: #ffffff;
            color: var(--billing-text);
            font: inherit;
            font-size: 0.84rem;
            line-height: 1.2;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .billing-required-form-grid select:focus,
        .billing-required-form-grid input:focus {
            border-color: var(--billing-blue);
            box-shadow: 0 0 0 3px rgba(7, 87, 248, 0.11);
            outline: none;
        }
        .billing-required-form-note {
            color: var(--billing-muted);
            font-size: 0.82rem;
            line-height: 1.38;
        }
        .billing-required-form-note.is-warning {
            display: inline-flex;
            align-items: center;
            min-height: 2.2rem;
            color: #8a4a00;
            font-weight: 760;
        }
        .billing-required-page .package-booking-modal__dialog {
            max-width: min(94vw, 48rem);
        }
        .billing-required-page .package-booking-form {
            display: grid;
            gap: 1rem;
        }
        .billing-required-page .package-booking-form__header {
            display: grid;
            gap: 0.3rem;
        }
        .billing-required-page .package-booking-form__header h2,
        .billing-required-page .package-booking-form__header p,
        .billing-required-page .package-booking-form__meta {
            margin: 0;
            letter-spacing: 0;
        }
        .billing-required-page .package-booking-form__header h2 {
            color: var(--billing-text);
            font-size: 1.28rem;
            font-weight: 900;
            line-height: 1.15;
        }
        .billing-required-page .package-booking-form__header p,
        .billing-required-page .package-booking-form__meta {
            color: var(--billing-muted);
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .billing-required-page .package-booking-form__slot {
            display: grid;
            gap: 0.42rem;
            color: #334155;
            font-size: 0.82rem;
            font-weight: 820;
        }
        .billing-required-page .package-booking-form__slot select {
            width: 100%;
            min-height: 2.72rem;
            padding: 0.62rem 0.72rem;
            border: 1px solid rgba(7, 20, 47, 0.16);
            border-radius: var(--billing-radius);
            background: #ffffff;
            color: var(--billing-text);
            font: inherit;
        }
        .billing-required-page .package-booking-form__slot select:focus {
            border-color: var(--billing-blue);
            box-shadow: 0 0 0 3px rgba(7, 87, 248, 0.11);
            outline: none;
        }
        .billing-required-page .package-booking-form__empty {
            border: 1px dashed #cbd5e1;
            border-radius: var(--billing-radius);
            background: #f8fafc;
            color: var(--billing-muted);
            padding: 0.85rem;
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .billing-required-checklist ol {
            display: grid;
            gap: 0.5rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .billing-required-step {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 0.62rem;
            align-items: center;
            padding: 0.68rem;
            border: 1px solid var(--billing-border);
            border-radius: var(--billing-radius);
            background: #ffffff;
        }
        .billing-required-step.is-done {
            border-color: rgba(7, 150, 95, 0.22);
            background: var(--billing-green-soft);
        }
        .billing-required-step.is-active {
            border-color: rgba(7, 87, 248, 0.3);
            background: var(--billing-blue-soft);
        }
        .billing-required-step-icon,
        .billing-required-step-number {
            width: 1.56rem;
            height: 1.56rem;
            border: 1px solid var(--billing-border-strong);
            color: var(--billing-muted);
            font-size: 0.74rem;
            font-weight: 840;
        }
        .billing-required-step.is-done .billing-required-step-icon,
        .billing-required-step.is-done .billing-required-step-number {
            border-color: var(--billing-green);
            background: var(--billing-green);
            color: #ffffff;
        }
        .billing-required-step.is-active .billing-required-step-icon,
        .billing-required-step.is-active .billing-required-step-number {
            border-color: var(--billing-blue);
            background: var(--billing-blue);
            color: #ffffff;
        }
        .billing-required-step strong {
            display: block;
            color: var(--billing-text);
            font-size: 0.86rem;
            line-height: 1.22;
        }
        .billing-required-step small {
            display: block;
            margin-top: 0.12rem;
            color: var(--billing-muted);
            font-size: 0.76rem;
            line-height: 1.3;
        }
        .billing-required-activity-table-wrap {
            overflow-x: auto;
            border: 1px solid rgba(7, 20, 47, 0.1);
            border-radius: var(--billing-radius);
        }
        .billing-required-activity-table {
            width: 100%;
            min-width: 545px;
            border-collapse: collapse;
            font-size: 0.76rem;
        }
        .billing-required-activity-table th,
        .billing-required-activity-table td {
            padding: 0.52rem 0.58rem;
            border-bottom: 1px solid rgba(7, 20, 47, 0.08);
            color: #24324a;
            text-align: left;
            white-space: nowrap;
        }
        .billing-required-activity-table th {
            background: var(--billing-soft);
            color: #475569;
            font-size: 0.68rem;
            font-weight: 840;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .billing-required-activity-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .billing-required-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.17rem 0.44rem;
            border: 1px solid rgba(226, 125, 0, 0.22);
            border-radius: 5px;
            background: var(--billing-amber-soft);
            color: #9a5500;
            font-size: 0.68rem;
            font-weight: 820;
            line-height: 1.1;
        }
        .billing-required-status-pill.is-success {
            border-color: rgba(7, 150, 95, 0.22);
            background: var(--billing-green-soft);
            color: #047857;
        }
        .billing-required-status-pill.is-danger {
            border-color: rgba(228, 61, 48, 0.22);
            background: var(--billing-red-soft);
            color: #be261b;
        }
        .billing-required-empty {
            margin: 0;
            color: var(--billing-muted);
            font-size: 0.86rem;
            line-height: 1.42;
        }
        .billing-required-donation {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 0.9rem;
            align-items: center;
            padding: 0.9rem;
        }
        .billing-required-donation-icon {
            width: 2.55rem;
            height: 2.55rem;
            background: var(--billing-blue-soft);
            color: var(--billing-blue);
            font-size: 1.06rem;
        }
        .billing-required-donation-actions .btn-premium-secondary {
            min-width: 10.2rem;
        }
        .billing-required-help-grid .billing-required-donation {
            grid-template-columns: auto minmax(0, 1fr);
        }
        .billing-required-help-grid .billing-required-donation-actions {
            grid-column: 1 / -1;
            justify-content: flex-start;
        }
        @media (min-width: 900px) {
            .billing-required-grid.is-single .billing-required-choice-inner {
                grid-template-columns: minmax(0, 1.1fr) minmax(210px, 0.7fr);
                align-items: start;
            }
            .billing-required-grid.is-single .billing-required-plan-heading {
                grid-column: 1;
            }
            .billing-required-grid.is-single .billing-required-price {
                grid-column: 2;
                align-self: start;
            }
            .billing-required-grid.is-single .billing-required-tier-intro,
            .billing-required-grid.is-single .billing-required-feature-list {
                grid-column: 1;
            }
            .billing-required-grid.is-single .billing-required-feature-details {
                grid-column: 1;
            }
            .billing-required-grid.is-single .billing-required-choice-button {
                grid-column: 2;
                align-self: end;
            }
        }
        @media (max-width: 1200px) {
            .billing-required-layout {
                grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.85fr);
            }
            .billing-required-side .billing-required-payment-bar {
                grid-template-columns: 1fr;
            }
            .billing-required-side .billing-required-payment-bar .btn-premium-secondary,
            .billing-required-side .billing-required-payment-bar .btn-premium-primary {
                width: 100%;
            }
        }
        @media (max-width: 980px) {
            .billing-required-recovery,
            .billing-required-layout,
            .billing-required-help-grid {
                grid-template-columns: 1fr;
            }
            .billing-required-command-metric {
                padding: 0.78rem 0 0;
                border-top: 1px solid rgba(7, 20, 47, 0.1);
                border-left: 0;
            }
            .billing-required-actions {
                justify-content: flex-start;
            }
            .billing-required-tabs {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .billing-required-actions,
            .billing-required-donation-actions,
            .billing-required-footer-actions {
                justify-content: flex-start;
            }
            .billing-required-side {
                position: static;
            }
            .billing-required-grid.is-many,
            .billing-required-token-grid.is-many {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 820px) {
            .billing-required-layout {
                display: flex;
                flex-direction: column;
            }
            .billing-required-main {
                order: 1;
            }
            .billing-required-side {
                display: contents;
            }
            #ai-token-refill {
                order: 2;
            }
            .billing-required-activity-section {
                order: 3;
            }
            .billing-required-checklist {
                order: 4;
            }
            .billing-required-payment-bar,
            .billing-required-form-grid,
            .billing-required-donation {
                grid-template-columns: 1fr;
            }
            .billing-required-payment-bar .btn-premium-primary,
            .billing-required-payment-bar .btn-premium-secondary,
            .billing-required-donation-actions .btn-premium-secondary,
            .billing-required-footer-actions .btn-premium-secondary {
                width: 100%;
            }
            .billing-required-payment-note {
                text-align: left;
            }
            #workspace-packages,
            #ai-token-refill {
                scroll-margin-top: 18rem;
            }
        }
        @media (max-width: 620px) {
            .page-content--wide .page-premium.billing-required-page > .billing-required-wrap,
            .billing-required-wrap {
                padding-inline: clamp(0.85rem, 4vw, 1rem);
            }
            .billing-required-shell {
                gap: 0.68rem;
            }
            .billing-required-recovery {
                grid-template-columns: 1fr;
                align-items: start;
                min-height: auto;
            }
            .billing-required-heading-row {
                grid-template-columns: 1fr;
                gap: 0.58rem;
            }
            .billing-required-actions {
                grid-column: 1 / -1;
                width: 100%;
            }
            .billing-required-actions a {
                width: 100%;
            }
            .billing-required-recovery-icon {
                width: 2.72rem;
                height: 2.72rem;
                font-size: 1.08rem;
            }
            .billing-required-title {
                font-size: 1.42rem;
            }
            .billing-required-copy {
                font-size: 0.9rem;
            }
            .billing-required-grid.is-many,
            .billing-required-grid.is-pair,
            .billing-required-token-grid.is-many,
            .billing-required-token-grid.is-pair {
                grid-template-columns: 1fr;
            }
            .billing-required-section-head {
                display: grid;
                align-items: start;
            }
            .billing-required-currency {
                width: 100%;
                justify-content: space-between;
            }
            .billing-required-choice-inner {
                padding: 0.92rem;
            }
            .billing-required-page--packages .billing-required-tabs {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .billing-required-page--packages .billing-required-tab {
                min-height: 2.72rem;
                padding: 0.46rem 0.52rem;
            }
            .billing-required-page--packages .billing-required-tab small {
                display: none;
            }
            .billing-required-page--packages .billing-required-title {
                font-size: 1.16rem;
            }
            .billing-required-page--packages #workspace-packages {
                padding: 0.95rem;
            }
            .billing-required-page--packages #workspace-packages .billing-required-grid {
                gap: 0.88rem;
            }
            .billing-required-page--packages #workspace-packages .billing-required-package-card {
                gap: 0.78rem;
                padding: 0.95rem;
            }
            .billing-required-page--packages #workspace-packages .billing-required-plan-icon {
                width: 2.55rem;
                height: 2.55rem;
            }
            .billing-required-page .launch-package-modal {
                padding: 0.65rem;
            }
            .billing-required-page .launch-package-modal__dialog {
                max-height: calc(100vh - 1.3rem);
                border-radius: 10px;
            }
            .billing-required-page .launch-package-modal__header {
                display: grid;
                padding-right: 2.5rem;
            }
            .billing-required-page .launch-package-modal__price {
                width: fit-content;
                white-space: normal;
            }
            .billing-required-page .launch-package-modal__groups {
                grid-template-columns: 1fr;
            }
            .billing-required-activity-table {
                min-width: 0;
            }
            .billing-required-activity-table thead {
                display: none;
            }
            .billing-required-activity-table,
            .billing-required-activity-table tbody,
            .billing-required-activity-table tr,
            .billing-required-activity-table td {
                display: block;
                width: 100%;
            }
            .billing-required-activity-table tr {
                padding: 0.56rem 0.62rem;
                border-bottom: 1px solid rgba(7, 20, 47, 0.08);
            }
            .billing-required-activity-table tr:last-child {
                border-bottom: 0;
            }
            .billing-required-activity-table td {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                padding: 0.24rem 0;
                border: 0;
                white-space: normal;
            }
            .billing-required-activity-table td::before {
                content: attr(data-label);
                flex: 0 0 auto;
                color: var(--billing-muted);
                font-weight: 820;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .billing-required-choice-inner,
            .billing-required-tab {
                transition: none;
            }
            .billing-required-choice:hover .billing-required-choice-inner,
            .billing-required-tab:hover {
                transform: none;
            }
        }
        .billing-required-page--packages {
            --billing-text: #050505;
            --billing-muted: #666a73;
            --billing-border: #e2e5ea;
            --billing-border-strong: #cfd4dc;
            --billing-surface: #ffffff;
            --billing-soft: #f7f8fa;
            --billing-radius: 16px;
            --billing-shadow: none;
            --billing-shadow-tight: none;
            background: #ffffff;
            color: #050505;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .billing-required-page--packages .billing-required-wrap {
            max-width: 1500px;
            padding-block: 0.9rem 2.6rem;
        }
        .billing-required-page--packages .billing-required-shell {
            gap: clamp(0.9rem, 1.8vw, 1.45rem);
        }
        .billing-required-page--packages .billing-required-breadcrumb,
        .billing-required-page--packages .billing-required-status-row,
        .billing-required-page--packages .billing-required-recovery-icon,
        .billing-required-page--packages .billing-required-command-metric,
        .billing-required-page--packages .billing-required-actions {
            display: none;
        }
        .billing-required-page--packages .billing-required-recovery {
            display: block;
            min-height: auto;
            padding: clamp(1.2rem, 3vw, 2.5rem) 1rem 0.25rem;
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
            text-align: center;
        }
        .billing-required-page--packages .billing-required-heading-row {
            display: block;
        }
        .billing-required-page--packages .billing-required-title {
            max-width: 860px;
            margin: 0 auto;
            color: #050505;
            font-size: clamp(2.35rem, 5vw, 3.55rem);
            font-weight: 860;
            letter-spacing: 0;
            line-height: 1.02;
        }
        .billing-required-page--packages .billing-required-copy {
            max-width: 720px;
            margin: 0.85rem auto 0;
            color: #62656d;
            font-size: clamp(1rem, 1.25vw, 1.15rem);
            line-height: 1.48;
        }
        .billing-required-page--packages .billing-required-system-legal,
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-system-legal {
            margin-inline: auto;
            text-align: center;
        }
        .billing-required-page--packages .billing-required-tabs {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.18rem;
            width: fit-content;
            max-width: 100%;
            margin: 0 auto;
            padding: 0.22rem;
            border: 1px solid #e4e7ec;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page--packages .billing-required-tab {
            display: inline-flex;
            gap: 0;
            min-height: 2.15rem;
            min-width: 0;
            padding: 0.44rem 0.9rem;
            border: 0;
            border-radius: 999px;
            color: #565b66;
            box-shadow: none;
            transform: none;
        }
        .billing-required-page--packages .billing-required-tab::before,
        .billing-required-page--packages .billing-required-tab-icon,
        .billing-required-page--packages .billing-required-tab small {
            display: none;
        }
        .billing-required-page--packages .billing-required-tab strong {
            color: inherit;
            font-size: 0.86rem;
            font-weight: 720;
            line-height: 1;
            text-overflow: clip;
        }
        .billing-required-page--packages .billing-required-tab:hover,
        .billing-required-page--packages .billing-required-tab:focus-visible {
            background: #f6f7f9;
            color: #050505;
            box-shadow: none;
            outline: none;
            transform: none;
        }
        .billing-required-page--packages .billing-required-tab[aria-current="page"] {
            background: #050507;
            color: #ffffff;
            box-shadow: none;
        }
        .billing-required-page--packages .billing-required-tab[aria-current="page"] strong {
            color: #ffffff;
        }
        .billing-required-page--packages #workspace-packages {
            scroll-margin-top: 8.5rem;
            display: grid;
            gap: clamp(1.3rem, 2vw, 1.85rem);
            padding: 0;
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-packages-control-row {
            display: flex;
            justify-content: center;
        }
        .billing-packages-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.78rem;
            min-height: 2.35rem;
            color: #050505;
            font-size: 0.95rem;
            font-weight: 720;
            line-height: 1;
            cursor: pointer;
        }
        .billing-packages-toggle:hover .billing-packages-toggle__switch {
            border-color: #050507;
        }
        .billing-packages-toggle input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .billing-packages-toggle__switch {
            position: relative;
            width: 3rem;
            height: 1.72rem;
            border: 1px solid #cbd1da;
            border-radius: 999px;
            background: #eef1f5;
            pointer-events: none;
            transition: background 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .billing-packages-toggle__switch::after {
            content: "";
            position: absolute;
            top: 0.2rem;
            left: 0.2rem;
            width: 1.22rem;
            height: 1.22rem;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.22);
            transition: transform 0.16s ease;
        }
        .billing-packages-toggle input:checked + .billing-packages-toggle__switch {
            border-color: #050507;
            background: #050507;
            box-shadow: 0 0 0 4px rgba(5, 5, 7, 0.08);
        }
        .billing-packages-toggle input:checked + .billing-packages-toggle__switch::after {
            transform: translateX(1.28rem);
        }
        .billing-packages-toggle input:focus-visible + .billing-packages-toggle__switch {
            outline: 3px solid rgba(5, 5, 7, 0.18);
            outline-offset: 3px;
        }
        .billing-packages-toggle input:disabled + .billing-packages-toggle__switch {
            border-color: #cfd4dc;
            background: #eef0f3;
            box-shadow: none;
        }
        .billing-packages-toggle__save {
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            min-height: 2rem;
            padding: 0.52rem 0.78rem;
            border: 1px solid #d9dde4;
            border-radius: 9px;
            background: #f7f8fa;
            color: #4f5560;
            font-size: 0.86rem;
            font-weight: 620;
            transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease, transform 0.16s ease;
        }
        .billing-packages-toggle__save i {
            font-size: 0.78rem;
        }
        .billing-packages-toggle:hover .billing-packages-toggle__save {
            border-color: #bfc5ce;
            color: #111111;
        }
        .billing-packages-toggle input:checked ~ .billing-packages-toggle__save {
            border-color: #050507;
            background: #050507;
            color: #ffffff;
            transform: translateY(-1px);
        }
        .billing-packages-toggle input:disabled ~ .billing-packages-toggle__save {
            border-color: #d9dde4;
            background: #f7f8fa;
            color: #8b929d;
            transform: none;
        }
        .billing-required-page--packages .billing-packages-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: clamp(0.9rem, 1.45vw, 1.35rem);
            align-items: stretch;
        }
        .billing-required-page--packages .billing-required-package-card {
            display: grid;
            flex: 0 1 clamp(13.75rem, 16vw, 15.5rem);
            grid-template-rows: auto auto auto auto auto 1fr;
            gap: 0.78rem;
            min-width: 0;
            min-height: 30rem;
            padding: clamp(1rem, 1.3vw, 1.22rem);
            border: 1px solid #dfe3e8;
            border-radius: 16px;
            background: #ffffff;
            color: #111111;
            box-shadow: none;
            transform: none;
            transition: border-color 0.16s ease, transform 0.16s ease;
        }
        .billing-required-page--packages .billing-required-package-card:hover {
            border-color: #bfc5ce;
            box-shadow: none;
            transform: translateY(-1px);
        }
        .billing-required-page--packages .billing-required-package-card.is-default::before {
            content: none;
        }
        .billing-required-page--packages .billing-required-package-card.is-selected,
        .billing-required-page--packages .billing-required-package-card.is-detail-active {
            border: 2px solid #050507;
            padding: calc(clamp(1rem, 1.3vw, 1.22rem) - 1px);
            box-shadow: none;
            transform: translateY(-0.85rem);
        }
        .billing-required-page--packages .billing-package-card__header {
            display: grid;
            gap: 0.34rem;
            min-height: 4.75rem;
            text-align: center;
        }
        .billing-required-page--packages .billing-package-card__badge {
            justify-self: center;
            width: fit-content;
            min-height: 1.45rem;
            padding: 0.32rem 0.58rem;
            border-radius: 5px;
            background: #050507;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            line-height: 1;
            text-transform: uppercase;
        }
        .billing-required-page--packages .billing-package-card__header h3 {
            margin: 0;
            color: #050505;
            font-size: clamp(0.92rem, 1vw, 1.04rem);
            font-weight: 800;
            letter-spacing: 0.04em;
            line-height: 1.18;
            text-transform: uppercase;
        }
        .billing-required-page--packages .billing-package-card__header p,
        .billing-required-page--packages .billing-package-card__cadence {
            margin: 0;
            color: #686c75;
            font-size: 0.86rem;
            line-height: 1.4;
        }
        .billing-required-page--packages .billing-package-card__price {
            display: flex;
            justify-content: center;
            align-items: baseline;
            gap: 0.28rem;
            min-height: 3.05rem;
            color: #050505;
            text-align: center;
        }
        .billing-required-page--packages .billing-package-card__price strong {
            color: #050505;
            font-size: clamp(1.85rem, 2.35vw, 2.65rem);
            font-weight: 780;
            letter-spacing: 0;
            line-height: 1;
            white-space: nowrap;
            overflow-wrap: normal;
        }
        .billing-required-page--packages .billing-package-card__price span {
            color: #555b65;
            font-size: 1.02rem;
            font-weight: 520;
            line-height: 1;
        }
        .billing-required-page--packages .billing-package-card__cadence {
            min-height: 1.25rem;
            text-align: center;
        }
        .billing-required-page--packages .billing-required-status-badges {
            display: flex;
            justify-content: center;
            gap: 0.32rem;
            flex-wrap: wrap;
            min-height: 1.45rem;
        }
        .billing-required-page--packages .billing-required-status-pill {
            width: auto;
            min-height: 1.55rem;
            padding: 0.26rem 0.48rem;
            border: 1px solid #e1e4e9;
            border-radius: 999px;
            background: #ffffff;
            color: #555b65;
            font-size: 0.68rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .billing-required-page--packages .billing-required-status-pill.is-current,
        .billing-required-page--packages .billing-required-status-pill.is-upgrade,
        .billing-required-page--packages .billing-required-status-pill.is-locked {
            border-color: #d7dce3;
            background: #f9fafb;
            color: #111111;
        }
        .billing-required-page--packages .billing-required-tier-intro {
            min-height: 2.45rem;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: #5f646e;
            font-size: 0.82rem;
            font-weight: 520;
            line-height: 1.42;
            text-align: center;
        }
        .billing-required-page--packages .billing-required-feature-list {
            gap: 0.42rem;
            padding-top: 0.78rem;
            border-top: 1px solid #eceff3;
        }
        .billing-required-page--packages .billing-required-feature-list li {
            padding-left: 1.55rem;
            color: #30343b;
            font-size: 0.82rem;
            line-height: 1.35;
        }
        .billing-required-page--packages .billing-required-feature-list li::before {
            content: "\2713";
            top: 0.08rem;
            width: 1rem;
            height: 1rem;
            border-radius: 0;
            background: transparent;
            color: #050505;
            box-shadow: none;
            font-size: 0.86rem;
            font-weight: 760;
            line-height: 1;
        }
        .billing-required-page--packages .billing-package-card__actions {
            display: grid;
            gap: 0.58rem;
            align-self: end;
            margin-top: 0.2rem;
        }
        .billing-required-page--packages .billing-package-card__form {
            display: grid;
        }
        .billing-required-page--packages .billing-package-card__form[hidden] {
            display: none;
        }
        .billing-required-page--packages .billing-package-card__form .billing-required-payment-bar {
            grid-template-columns: 1fr;
            align-items: stretch;
            padding: 0.64rem;
            gap: 0.56rem;
        }
        .billing-required-page--packages .billing-package-card__form .billing-required-form-grid {
            grid-template-columns: 1fr;
        }
        .billing-required-page--packages .billing-package-card__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 2.62rem;
            padding: 0.66rem 0.92rem;
            border: 1px solid #050507;
            border-radius: 6px;
            background: #050507;
            color: #ffffff;
            font: inherit;
            font-size: 0.95rem;
            font-weight: 760;
            line-height: 1;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }
        .billing-required-page--packages .billing-package-card__button:hover,
        .billing-required-page--packages .billing-package-card__button:focus-visible {
            background: #1d1d21;
            border-color: #1d1d21;
            color: #ffffff;
            outline: none;
        }
        .billing-required-page--packages .billing-package-card__button[disabled] {
            cursor: default;
            opacity: 0.95;
        }
        .billing-required-page--packages .billing-package-card__button--secondary,
        .billing-required-page--packages .billing-required-detail-trigger {
            background: #ffffff;
            color: #111111;
            border-color: #d7dce3;
        }
        .billing-required-page--packages .billing-package-card__button--secondary:hover,
        .billing-required-page--packages .billing-package-card__button--secondary:focus-visible,
        .billing-required-page--packages .billing-required-detail-trigger:hover,
        .billing-required-page--packages .billing-required-detail-trigger:focus-visible,
        .billing-required-page--packages .billing-required-package-card.is-detail-active .billing-required-detail-trigger {
            border-color: #050507;
            color: #050507;
            background: #ffffff;
            outline: none;
        }
        .billing-required-page--packages .billing-required-detail-trigger {
            justify-self: stretch;
            min-height: 2.25rem;
            padding: 0.56rem 0.75rem;
            border-radius: 6px;
            font-size: 0.86rem;
            font-weight: 720;
        }
        .billing-required-page--packages .billing-required-card-note {
            margin: 0;
            color: #686c75;
            font-size: 0.78rem;
            line-height: 1.36;
            text-align: center;
        }
        .billing-required-page--packages .billing-required-card-note a {
            color: #050507;
            font-weight: 760;
        }
        .billing-packages-footnote {
            max-width: 760px;
            margin: 0 auto;
            color: #686c75;
            font-size: 0.92rem;
            line-height: 1.45;
            text-align: center;
        }
        @media (max-width: 1320px) {
            .billing-required-page--packages .billing-packages-grid {
                justify-content: center;
            }
        }
        @media (max-width: 860px) {
            .billing-required-page--packages .billing-required-title {
                font-size: clamp(2rem, 9vw, 2.85rem);
            }
            .billing-required-page--packages .billing-packages-grid {
                max-width: 460px;
                width: 100%;
                margin: 0 auto;
            }
            .billing-required-page--packages .billing-required-package-card,
            .billing-required-page--packages .billing-required-package-card.is-selected,
            .billing-required-page--packages .billing-required-package-card.is-detail-active {
                flex: 0 1 auto;
                width: 100%;
                min-height: auto;
                transform: none;
            }
            .billing-required-page--packages .billing-package-card__header,
            .billing-required-page--packages .billing-required-tier-intro {
                min-height: 0;
            }
        }
        @media (max-width: 540px) {
            .billing-required-page--packages .billing-required-wrap {
                padding-inline: 0.9rem;
            }
            .billing-required-page--packages .billing-required-tabs {
                width: 100%;
                border-radius: 14px;
            }
            .billing-required-page--packages .billing-required-tab {
                flex: 1 1 calc(50% - 0.2rem);
                justify-content: center;
                padding-inline: 0.5rem;
            }
            .billing-packages-toggle {
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.62rem;
            }
            .billing-packages-toggle__save {
                order: 3;
            }
            .billing-required-page--packages .billing-required-package-card {
                padding: 1.05rem;
            }
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) {
            --billing-text: #050505;
            --billing-muted: #5f646d;
            --billing-border: #e1e5eb;
            --billing-border-strong: #cbd1da;
            --billing-surface: #ffffff;
            --billing-soft: #f7f8fa;
            --billing-radius: 16px;
            --billing-shadow: none;
            --billing-shadow-tight: none;
            background: #ffffff;
            color: #050505;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-wrap {
            max-width: 1120px;
            padding-block: 1rem 2.6rem;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-shell {
            gap: clamp(0.9rem, 1.8vw, 1.4rem);
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-breadcrumb,
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-recovery-icon,
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-command-metric,
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-actions {
            display: none;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-recovery {
            display: block;
            min-height: auto;
            padding: clamp(1.3rem, 3vw, 2.4rem) 1rem 0.25rem;
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
            text-align: center;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-heading-row {
            display: block;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-title {
            max-width: 820px;
            margin: 0 auto;
            color: #050505;
            font-size: clamp(2rem, 4.4vw, 3.2rem);
            font-weight: 860;
            letter-spacing: 0;
            line-height: 1.04;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-copy {
            max-width: 700px;
            margin: 0.8rem auto 0;
            color: #62666f;
            font-size: clamp(0.98rem, 1.15vw, 1.08rem);
            line-height: 1.5;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tabs {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.18rem;
            width: fit-content;
            max-width: 100%;
            margin: 0 auto;
            padding: 0.22rem;
            border: 1px solid #e4e7ec;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab {
            display: inline-flex;
            gap: 0;
            min-height: 2.15rem;
            min-width: 0;
            padding: 0.44rem 0.9rem;
            border: 0;
            border-radius: 999px;
            color: #565b66;
            background: transparent;
            box-shadow: none;
            transform: none;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab::before,
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab-icon,
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab small {
            display: none;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab strong {
            color: inherit;
            font-size: 0.86rem;
            font-weight: 720;
            line-height: 1;
            text-overflow: clip;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab:hover,
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab:focus-visible {
            background: #f6f7f9;
            color: #050505;
            box-shadow: none;
            outline: none;
            transform: none;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab[aria-current="page"] {
            background: #050507;
            color: #ffffff;
            box-shadow: none;
        }
        .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab[aria-current="page"] strong {
            color: #ffffff;
        }
        .billing-required-page--packages .billing-required-package-card {
            overflow: hidden;
            grid-template-rows: auto auto auto auto minmax(7.5rem, 1fr) auto;
            gap: 0.68rem;
            min-height: 31rem;
            padding: clamp(0.95rem, 1vw, 1.15rem);
        }
        .billing-required-page--packages .billing-required-package-card.is-selected,
        .billing-required-page--packages .billing-required-package-card.is-detail-active {
            padding: calc(clamp(0.95rem, 1vw, 1.15rem) - 1px);
            transform: translateY(-0.72rem);
        }
        .billing-required-page--packages .billing-package-card__header {
            align-content: start;
            gap: 0.28rem;
            min-height: 5.7rem;
        }
        .billing-required-page--packages .billing-package-card__header h3 {
            font-size: clamp(0.88rem, 0.95vw, 1rem);
            line-height: 1.18;
            overflow-wrap: anywhere;
        }
        .billing-required-page--packages .billing-package-card__header p {
            display: -webkit-box;
            min-height: 2.36rem;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow-wrap: anywhere;
        }
        .billing-required-page--packages .billing-package-card__price {
            display: grid;
            justify-items: center;
            align-items: center;
            gap: 0.08rem;
            min-height: 2.85rem;
            min-width: 0;
        }
        .billing-required-page--packages .billing-package-card__price strong {
            min-width: 0;
            font-size: clamp(1.9rem, 2.15vw, 2.45rem);
            white-space: nowrap;
        }
        .billing-required-page--packages .billing-package-card__price span {
            display: block;
            font-size: 0.78rem;
            font-weight: 640;
            line-height: 1;
        }
        .billing-required-page--packages .billing-package-card__cadence {
            min-height: 1.18rem;
            font-size: 0.82rem;
        }
        .billing-required-page--packages .billing-package-card__status-text {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            border: 0;
        }
        .billing-required-page--packages .billing-required-status-badges {
            display: none;
        }
        .billing-required-page--packages .billing-required-tier-intro {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            overflow: hidden;
            color: #4f5560;
            font-size: 0.8rem;
            line-height: 1.36;
        }
        .billing-required-page--packages .billing-required-feature-list {
            align-content: start;
            gap: 0.5rem;
            min-height: 8.2rem;
            padding-top: 0.82rem;
        }
        .billing-required-page--packages .billing-required-feature-list li {
            font-size: 0.81rem;
            line-height: 1.36;
            overflow-wrap: anywhere;
        }
        .billing-required-page--packages .billing-package-card__actions {
            align-self: end;
            gap: 0.5rem;
        }
        .billing-required-page--packages .billing-required-card-note {
            min-height: 1rem;
            font-size: 0.74rem;
            line-height: 1.28;
        }
        .billing-packages-footnote {
            max-width: 840px;
            padding-inline: 1rem;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity) .billing-required-section,
        .billing-required-page--help .billing-required-checklist,
        .billing-required-page--help .billing-required-donation {
            border: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-section-head {
            justify-content: center;
            text-align: center;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-section-head h2,
        .billing-required-page--help .billing-required-checklist h2,
        .billing-required-page--help .billing-required-donation h2 {
            color: #050505;
            font-size: 1.05rem;
            font-weight: 800;
        }
        .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-section-kicker {
            display: none;
        }
        .billing-required-page--tokens .billing-required-token-grid {
            gap: 0.85rem;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-choice-inner {
            display: grid;
            place-items: center;
            gap: 0.34rem;
            min-height: 7.2rem;
            padding: 1rem;
            border: 1px solid #e1e5eb;
            border-left: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            text-align: center;
            box-shadow: none;
            transform: none;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-choice:hover .billing-required-choice-inner {
            border-color: #bfc5ce;
            box-shadow: none;
            transform: none;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-option-input:checked + .billing-required-choice-inner {
            border-color: #050507;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px #050507;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-plan-name {
            color: #050505;
            font-size: 1.02rem;
            font-weight: 800;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-price {
            color: #050505;
            font-size: 0.95rem;
            font-weight: 680;
        }
        .billing-required-page--tokens .billing-required-token-grid .billing-required-radio-dot {
            position: static;
            transform: none;
            width: 0.82rem;
            height: 0.82rem;
            border-color: #cbd1da;
        }
        .billing-required-page--tokens .billing-required-option-input:checked + .billing-required-choice-inner .billing-required-radio-dot {
            border: 3px solid #050507;
        }
        .billing-required-page--tokens .billing-required-payment-bar {
            border: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page--tokens .billing-required-payment-bar .btn-premium-secondary,
        .billing-required-page--help .billing-required-donation-actions [data-workspace-donation-open] {
            border-color: #050507;
            background: #050507;
            color: #ffffff;
            box-shadow: none;
        }
        .billing-required-page--tokens .billing-required-form-grid select,
        .billing-required-page--tokens .billing-required-form-grid input,
        .billing-required-page--tokens .billing-required-payment-bar .btn-premium-secondary,
        .billing-required-page--help .billing-required-donation-actions .btn-premium-secondary {
            border-radius: 6px;
        }
        .billing-required-page--activity .billing-required-activity-table-wrap {
            border: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page--activity .billing-required-activity-table th {
            background: #f7f8fa;
            color: #5f646d;
            font-size: 0.72rem;
            letter-spacing: 0.02em;
        }
        .billing-required-page--activity .billing-required-activity-table td {
            color: #20242b;
            font-size: 0.82rem;
        }
        .billing-required-page--activity .billing-required-status-pill {
            min-height: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: #20242b;
            font-size: 0.78rem;
            font-weight: 740;
        }
        .billing-required-page--help .billing-required-help-grid {
            gap: 1rem;
        }
        .billing-required-page--help .billing-required-checklist,
        .billing-required-page--help .billing-required-donation {
            padding: 1rem;
        }
        .billing-required-page--help .billing-required-step {
            border-color: #e1e5eb;
            border-radius: 12px;
            background: #ffffff;
        }
        .billing-required-page--help .billing-required-step.is-done,
        .billing-required-page--help .billing-required-step.is-active {
            border-color: #cbd1da;
            background: #ffffff;
        }
        .billing-required-page--help .billing-required-step-icon,
        .billing-required-page--help .billing-required-step-number {
            border-color: #cbd1da;
            background: #ffffff;
            color: #050505;
        }
        .billing-required-page--help .billing-required-step.is-done .billing-required-step-icon,
        .billing-required-page--help .billing-required-step.is-done .billing-required-step-number,
        .billing-required-page--help .billing-required-step.is-active .billing-required-step-icon,
        .billing-required-page--help .billing-required-step.is-active .billing-required-step-number {
            border-color: #050507;
            background: #050507;
            color: #ffffff;
        }
        .billing-required-page--help .billing-required-donation-icon {
            display: none;
        }
        .billing-required-page--help .billing-required-donation {
            grid-template-columns: minmax(0, 1fr);
        }
        .billing-required-page--help .billing-required-donation-actions {
            justify-content: flex-start;
        }
        .billing-required-page--help .billing-required-donation-actions .btn-premium-secondary {
            border: 1px solid #d7dce3;
            background: #ffffff;
            color: #111111;
            box-shadow: none;
        }
        .billing-required-page--help .billing-required-donation-actions [data-workspace-donation-open] {
            border-color: #050507;
            background: #050507;
            color: #ffffff;
        }
        .billing-required-page .launch-package-modal__backdrop {
            background: rgba(5, 5, 7, 0.44);
            backdrop-filter: none;
        }
        .billing-required-page .launch-package-modal__dialog {
            border: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 24px 60px rgba(5, 5, 7, 0.18);
        }
        .billing-required-page .launch-package-modal__close {
            border-color: #d7dce3;
            border-radius: 8px;
            box-shadow: none;
        }
        .billing-required-page .launch-package-modal__price,
        .billing-required-page .launch-package-modal__tier,
        .billing-required-page .launch-package-modal__fit,
        .billing-required-page .launch-package-modal__capability,
        .billing-required-page .launch-package-modal__group,
        .billing-required-page .launch-package-modal__highlights span {
            border-color: #e1e5eb;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: none;
        }
        .billing-required-page .launch-package-modal__tier {
            border-left: 1px solid #e1e5eb;
        }
        .billing-required-page .launch-package-modal__highlights span {
            color: #20242b;
        }
        .workspace-affordability-modal .workspace-donation-dialog {
            border: 1px solid #e1e5eb;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 24px 60px rgba(5, 5, 7, 0.18);
        }
        .workspace-affordability-modal .workspace-donation-form input,
        .workspace-affordability-modal .workspace-donation-form select {
            border-color: #d7dce3;
            border-radius: 6px;
            box-shadow: none;
        }
        .workspace-affordability-modal .workspace-donation-actions .btn-premium-primary {
            border-color: #050507;
            background: #050507;
            color: #ffffff;
            box-shadow: none;
        }
        .workspace-affordability-modal .workspace-donation-actions .btn-premium-secondary {
            border-color: #d7dce3;
            background: #ffffff;
            color: #111111;
            box-shadow: none;
        }
        @media (max-width: 1320px) {
            .billing-required-page--packages .billing-package-card__price strong {
                font-size: clamp(1.85rem, 2.3vw, 2.25rem);
            }
        }
        @media (max-width: 860px) {
            .billing-required-page--packages .billing-required-package-card,
            .billing-required-page--packages .billing-required-package-card.is-selected,
            .billing-required-page--packages .billing-required-package-card.is-detail-active {
                grid-template-rows: none;
                min-height: auto;
                transform: none;
            }
            .billing-required-page--packages .billing-package-card__header,
            .billing-required-page--packages .billing-package-card__header p,
            .billing-required-page--packages .billing-required-tier-intro,
            .billing-required-page--packages .billing-required-feature-list {
                min-height: 0;
            }
        }
        @media (max-width: 620px) {
            .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tabs {
                width: 100%;
                border-radius: 14px;
            }
            .billing-required-page:is(.billing-required-page--packages, .billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-tab {
                flex: 1 1 calc(50% - 0.2rem);
                justify-content: center;
                padding-inline: 0.5rem;
            }
            .billing-required-page:is(.billing-required-page--tokens, .billing-required-page--activity, .billing-required-page--help) .billing-required-title {
                font-size: clamp(1.9rem, 8vw, 2.6rem);
            }
            .billing-required-page--tokens .billing-required-payment-bar {
                grid-template-columns: 1fr;
            }
        }
        </style>
    <div class="container billing-required-wrap">
        <div class="billing-required-shell">
            <script>
                (function () {
                    var params = new URLSearchParams(window.location.search);
                    var hashTabMap = {
                        '#ai-token-refill': 'tokens',
                        '#workspace-packages': 'packages'
                    };
                    var hashTab = hashTabMap[window.location.hash] || '';
                    if (hashTab === '') {
                        return;
                    }
                    if (params.get('tab') !== hashTab) {
                        params.set('tab', hashTab);
                        window.location.replace(window.location.pathname + '?' + params.toString() + window.location.hash);
                        return;
                    }
                    var restoreTop = function () {
                        window.scrollTo(0, 0);
                    };
                    window.requestAnimationFrame(restoreTop);
                    window.addEventListener('load', restoreTop, { once: true });
                })();
            </script>
            <div class="billing-required-breadcrumb">Billing / <strong>Payment Required</strong></div>
            <section class="billing-required-recovery is-<?php echo htmlspecialchars($billingHeaderTone); ?>" aria-labelledby="billing-required-title">
                <div class="billing-required-recovery-body">
                    <div class="billing-required-heading-row">
                        <div class="billing-required-recovery-icon" aria-hidden="true">
                            <i class="fas <?php echo htmlspecialchars($billingHeaderIcon); ?>"></i>
                        </div>
                        <div>
                            <h1 id="billing-required-title" class="billing-required-title"><?php echo htmlspecialchars((string) ($billingCurrentMeta['title'] ?? $headerTitle)); ?></h1>
                            <p class="billing-required-copy"><?php echo htmlspecialchars((string) ($billingCurrentMeta['copy'] ?? $headerCopy)); ?></p>
                            <?php if ($platformSystemLegalName !== ''): ?>
                                <p class="billing-required-system-legal">System legal name: <strong><?php echo htmlspecialchars($platformSystemLegalName); ?></strong></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="billing-required-command-metric" aria-label="<?php echo htmlspecialchars((string) ($billingCurrentMeta['metric_label'] ?? 'Billing summary')); ?>">
                    <span><?php echo htmlspecialchars((string) ($billingCurrentMeta['metric_label'] ?? 'Billing summary')); ?></span>
                    <strong><?php echo htmlspecialchars((string) ($billingCurrentMeta['metric_value'] ?? '')); ?></strong>
                    <small><?php echo htmlspecialchars((string) ($billingCurrentMeta['metric_detail'] ?? '')); ?></small>
                </div>
                <div class="billing-required-actions" aria-label="Billing recovery actions">
                    <a href="<?php echo htmlspecialchars((string) ($billingCurrentMeta['action_href'] ?? $primaryActionHref)); ?>" class="btn-premium-primary"><i class="fas <?php echo htmlspecialchars($billingHeaderIcon); ?>" aria-hidden="true"></i> <?php echo htmlspecialchars((string) ($billingCurrentMeta['action_label'] ?? $primaryActionLabel)); ?></a>
                </div>
            </section>
            <?php if ($billingStatus === 'success'): ?>
                <div class="billing-required-alert is-success"><i class="fas fa-circle-check" aria-hidden="true"></i><span><?php echo htmlspecialchars(is_array($billingCheckoutFeedback) && !empty($billingCheckoutFeedback['message']) ? (string) $billingCheckoutFeedback['message'] : 'Payment confirmed. Workspace billing has been refreshed.'); ?></span></div>
            <?php elseif ($billingStatus === 'error'): ?>
                <div class="billing-required-alert is-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars(is_array($billingCheckoutFeedback) && !empty($billingCheckoutFeedback['message']) ? (string) $billingCheckoutFeedback['message'] : 'Package change could not be completed. Please try again or contact an administrator.'); ?></span></div>
            <?php elseif ($billingStatus === 'failed'): ?>
                <div class="billing-required-alert is-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span>Payment verification did not complete successfully. Please try again or contact an administrator.</span></div>
            <?php elseif ($billingStatus === 'pending' && is_array($billingCheckoutFeedback) && !empty($billingCheckoutFeedback)): ?>
                <div class="billing-required-alert is-info">
                    <i class="fas fa-clock" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars((string) ($billingCheckoutFeedback['message'] ?? 'Follow the returned payment instructions to complete this checkout.')); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($packageMeetingMessage): ?>
                <div class="billing-required-alert is-success"><i class="fas fa-circle-check" aria-hidden="true"></i><span><?php echo htmlspecialchars((string) $packageMeetingMessage); ?></span></div>
            <?php endif; ?>
            <?php if ($packageMeetingError): ?>
                <div class="billing-required-alert is-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars((string) $packageMeetingError); ?></span></div>
            <?php endif; ?>
            <?php if ($isWalletDepleted): ?>
                <div class="billing-required-alert billing-required-context-alert is-warning"><i class="fas fa-bolt" aria-hidden="true"></i><span>AI actions are blocked until prepaid AI Credits are topped up in this workspace wallet.</span></div>
            <?php endif; ?>
            <nav class="billing-required-tabs" aria-label="Billing payment sections">
                <?php foreach ($billingTabs as $billingTabKey => $billingTab): ?>
                    <?php $billingTabActive = $billingCurrentTab === $billingTabKey; ?>
                    <a
                        class="billing-required-tab"
                        href="<?php echo htmlspecialchars(billingPaymentRequiredTabUrl($billingTabKey, (string) ($billingTab['fragment'] ?? ''))); ?>"
                        <?php echo $billingTabActive ? 'aria-current="page"' : ''; ?>
                    >
                        <span class="billing-required-tab-icon" aria-hidden="true"><i class="fas <?php echo htmlspecialchars((string) ($billingTab['icon'] ?? 'fa-circle')); ?>"></i></span>
                        <span>
                            <strong><?php echo htmlspecialchars((string) ($billingTab['label'] ?? ucfirst($billingTabKey))); ?></strong>
                            <small><?php echo htmlspecialchars((string) ($billingTab['description'] ?? '')); ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="billing-required-tab-panel">
                <?php if ($billingCurrentTab === 'packages'): ?>
                    <?php if ($canManageBilling && !empty($billingPackageCards)): ?>
                        <section id="workspace-packages" class="billing-required-section" data-launch-package-section>
                            <?php
                            $packageChangeForms = [];
                            $billingHasCurrentPackage = false;
                            foreach ($billingPackageCards as $billingPackageCandidate) {
                                if (!empty($billingPackageCandidate['is_current_package'])) {
                                    $billingHasCurrentPackage = true;
                                    break;
                                }
                            }
                            ?>
                            <div class="billing-packages-control-row" aria-label="Package billing cadence">
                                <label class="billing-packages-toggle">
                                    <span>Annual billing</span>
                                    <input type="checkbox" data-billing-cadence-toggle aria-label="Use annual billing">
                                    <span class="billing-packages-toggle__switch" aria-hidden="true"></span>
                                    <span class="billing-packages-toggle__save"><i class="fas fa-percent" aria-hidden="true"></i>Save with yearly plans</span>
                                </label>
                            </div>
                            <div class="billing-required-grid billing-packages-grid <?php echo htmlspecialchars($billingPlanGridClass); ?>">
                                <?php foreach ($billingPackageCards as $index => $plan): ?>
                                    <?php
                                    $packageCode = (string) ($plan['code'] ?? $plan['plan_code'] ?? '');
                                    $isDefaultPlan = !empty($plan['is_default']);
                                    $planIsFree = !empty($plan['is_free']);
                                    $planIsCustom = !empty($plan['is_custom']);
                                    $usesMeetingBooking = billingPaymentRequiredPackageRequiresMeeting((array) $plan);
                                    $isCurrentPlan = !empty($plan['is_current_package']);
                                    $isScheduledPlan = !empty($plan['is_scheduled_package']);
                                    $isSelectedPlan = $isCurrentPlan || (!$billingHasCurrentPackage && $packageCode === $billingDefaultPackageCode);
                                    $planName = (string) ($plan['display_name'] ?? $plan['plan_name'] ?? 'Plan');
                                    $planStatusLabel = (string) ($plan['status_label'] ?? ($isCurrentPlan ? 'Current package' : 'Available'));
                                    $changeDirection = (string) ($plan['change_direction'] ?? '');
                                    $planStatusClass = $isCurrentPlan ? 'is-current' : (($changeDirection === 'upgrade') ? 'is-upgrade' : (($changeDirection === 'scheduled') ? 'is-current' : 'is-locked'));
                                    $tierIntro = trim((string) ($plan['tier_intro'] ?? ''));
                                    $scopeLabel = trim((string) ($plan['scope_label'] ?? $plan['ideal_customer'] ?? ''));
                                    $checkoutOptions = is_array($plan['checkout_options'] ?? null) ? array_values((array) $plan['checkout_options']) : [];
                                    $monthlyOption = billingPaymentRequiredCheckoutOption($checkoutOptions, 'monthly') ?? ($checkoutOptions[0] ?? null);
                                    $annualOption = billingPaymentRequiredCheckoutOption($checkoutOptions, 'annual');
                                    $monthlyPayload = billingPaymentRequiredPricePayload(is_array($monthlyOption) ? $monthlyOption : null, (array) $plan, 'monthly');
                                    $annualPayload = billingPaymentRequiredPricePayload(is_array($annualOption) ? $annualOption : null, (array) $plan, 'annual');
                                    $monthlyPaymentModes = billingPaymentRequiredAvailablePaymentModes(is_array($monthlyOption) ? $monthlyOption : null);
                                    $annualPaymentModes = billingPaymentRequiredAvailablePaymentModes(is_array($annualOption) ? $annualOption : null);
                                    $primaryPriceId = (int) ($plan['primary_billing_plan_price_id'] ?? ($plan['billing_plan_price_id'] ?? ($monthlyPayload['id'] ?? 0)));
                                    $changeAvailable = !empty($plan['change_available']);
                                    $seatOverage = is_array($plan['seat_overage'] ?? null) ? (array) $plan['seat_overage'] : [];
                                    $schedulePriceId = (int) (($monthlyPayload['id'] ?? 0) > 0 ? $monthlyPayload['id'] : $primaryPriceId);
                                    $scheduleFormId = 'billing-change-schedule-' . $schedulePriceId;
                                    $packageCheckoutForms = [];
                                    foreach ([
                                        ['cadence' => 'monthly', 'option' => is_array($monthlyOption) ? $monthlyOption : null, 'payload' => $monthlyPayload, 'modes' => $monthlyPaymentModes],
                                        ['cadence' => 'annual', 'option' => is_array($annualOption) ? $annualOption : null, 'payload' => $annualPayload, 'modes' => $annualPaymentModes],
                                    ] as $checkoutFormCandidate) {
                                        $candidateOption = is_array($checkoutFormCandidate['option'] ?? null) ? (array) $checkoutFormCandidate['option'] : null;
                                        $candidatePayload = (array) ($checkoutFormCandidate['payload'] ?? []);
                                        $candidateModes = array_values((array) ($checkoutFormCandidate['modes'] ?? []));
                                        $candidatePriceId = (int) ($candidatePayload['id'] ?? 0);
                                        if ($candidateOption === null || $candidatePriceId <= 0 || empty($candidateOption['checkout_available']) || $candidateModes === []) {
                                            continue;
                                        }

                                        $packageCheckoutForms[] = [
                                            'cadence' => (string) ($checkoutFormCandidate['cadence'] ?? 'monthly'),
                                            'price_id' => $candidatePriceId,
                                            'modes' => $candidateModes,
                                        ];
                                    }
                                    $initialCheckoutCadence = (string) ($packageCheckoutForms[0]['cadence'] ?? 'monthly');
                                    $initialPayload = $initialCheckoutCadence === 'annual' ? $annualPayload : $monthlyPayload;
                                    $canCheckoutPackage = !$packageExempt
                                        && !$isCurrentPlan
                                        && !$isScheduledPlan
                                        && $changeDirection !== 'downgrade'
                                        && !$usesMeetingBooking
                                        && !empty($plan['checkout_available'])
                                        && $packageCheckoutForms !== [];
                                    $checkoutButtonLabel = (string) ($plan['action_label'] ?? 'Upgrade now');
                                    if (!in_array($checkoutButtonLabel, ['Upgrade now', 'Start package'], true)) {
                                        $checkoutButtonLabel = 'Upgrade now';
                                    }
                                    $cardBadge = $isCurrentPlan ? 'Current package' : ($isSelectedPlan ? 'Recommended' : '');
                                    if ($changeDirection === 'downgrade' && $changeAvailable && $schedulePriceId > 0) {
                                        $packageChangeForms[$scheduleFormId] = [
                                            'action' => 'schedule_downgrade',
                                            'billing_plan_price_id' => $schedulePriceId,
                                        ];
                                    }
                                    if ($changeDirection === 'scheduled') {
                                        $packageChangeForms['billing-change-cancel-scheduled'] = [
                                            'action' => 'cancel_scheduled_change',
                                            'billing_plan_price_id' => 0,
                                        ];
                                    }
                                    ?>
                                    <article
                                        class="billing-required-package-card billing-package-plan-card <?php echo $isDefaultPlan ? 'is-default' : ''; ?> <?php echo $isSelectedPlan ? 'is-selected is-detail-active' : ''; ?> <?php echo $isCurrentPlan ? 'is-current' : ''; ?> <?php echo $planIsCustom ? 'is-custom' : ''; ?>"
                                        data-package-card
                                        data-billing-plan-card
                                        data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                                        data-default-checkout-cadence="<?php echo htmlspecialchars($initialCheckoutCadence); ?>"
                                        data-monthly-price-id="<?php echo (int) ($monthlyPayload['id'] ?? 0); ?>"
                                        data-annual-price-id="<?php echo (int) (is_array($annualOption) ? ($annualPayload['id'] ?? 0) : 0); ?>"
                                        data-monthly-amount="<?php echo htmlspecialchars((string) ($monthlyPayload['amount'] ?? '')); ?>"
                                        data-monthly-period="<?php echo htmlspecialchars((string) ($monthlyPayload['period'] ?? '')); ?>"
                                        data-monthly-caption="<?php echo htmlspecialchars((string) ($monthlyPayload['caption'] ?? '')); ?>"
                                        data-annual-amount="<?php echo htmlspecialchars((string) (is_array($annualOption) ? ($annualPayload['amount'] ?? '') : ($monthlyPayload['amount'] ?? ''))); ?>"
                                        data-annual-period="<?php echo htmlspecialchars((string) (is_array($annualOption) ? ($annualPayload['period'] ?? '') : ($monthlyPayload['period'] ?? ''))); ?>"
                                        data-annual-caption="<?php echo htmlspecialchars((string) (is_array($annualOption) ? ($annualPayload['caption'] ?? '') : ($monthlyPayload['caption'] ?? ''))); ?>"
                                        <?php echo $cardBadge !== '' ? 'data-billing-card-badge="' . htmlspecialchars($cardBadge) . '"' : ''; ?>
                                    >
                                        <div class="billing-package-card__header">
                                            <?php if ($cardBadge !== ''): ?>
                                                <span class="billing-package-card__badge"><?php echo htmlspecialchars($cardBadge); ?></span>
                                            <?php endif; ?>
                                            <h3><?php echo htmlspecialchars($planName); ?></h3>
                                            <p><?php echo htmlspecialchars($scopeLabel !== '' ? $scopeLabel : (string) ($plan['description'] ?? 'Workspace access package')); ?></p>
                                        </div>
                                        <div class="billing-package-card__price" aria-label="<?php echo htmlspecialchars($planName . ' price'); ?>">
                                            <strong data-billing-price-amount><?php echo htmlspecialchars((string) ($initialPayload['amount'] ?? '')); ?></strong>
                                            <span data-billing-price-period><?php echo htmlspecialchars((string) ($initialPayload['period'] ?? '')); ?></span>
                                        </div>
                                        <p class="billing-package-card__cadence" data-billing-price-caption><?php echo htmlspecialchars((string) ($initialPayload['caption'] ?? '')); ?></p>
                                        <span class="billing-package-card__status-text"><?php echo htmlspecialchars($planStatusLabel); ?></span>
                                        <?php if ($tierIntro !== ''): ?>
                                            <span class="billing-required-tier-intro"><?php echo htmlspecialchars($tierIntro); ?></span>
                                        <?php endif; ?>
                                        <?php renderLaunchPackageHighlights((array) $plan, 'billing-required-feature-list'); ?>
                                        <div class="billing-package-card__actions">
                                            <?php if ($isCurrentPlan): ?>
                                                <button type="button" class="billing-package-card__button" disabled>Current package</button>
                                                <p class="billing-required-card-note">Active workspace package.</p>
                                            <?php elseif ($usesMeetingBooking): ?>
                                                <button
                                                    type="button"
                                                    class="billing-package-card__button"
                                                    data-package-booking-trigger
                                                    data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                                                    data-package-name="<?php echo htmlspecialchars($planName); ?>"
                                                    aria-controls="package-booking-modal"
                                                    aria-expanded="false"
                                                >Book now</button>
                                            <?php elseif ($canCheckoutPackage): ?>
                                                <?php foreach ($packageCheckoutForms as $packageCheckoutForm): ?>
                                                    <?php
                                                    $formCadence = (string) ($packageCheckoutForm['cadence'] ?? 'monthly');
                                                    $formActive = $formCadence === $initialCheckoutCadence;
                                                    ?>
                                                    <form
                                                        method="POST"
                                                        action="billing_start_payment.php"
                                                        class="billing-package-card__form"
                                                        data-billing-checkout-form
                                                        data-billing-checkout-cadence="<?php echo htmlspecialchars($formCadence); ?>"
                                                        <?php echo $formActive ? '' : 'hidden'; ?>
                                                    >
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=packages')); ?>">
                                                        <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) ($packageCheckoutForm['price_id'] ?? 0); ?>">
                                                        <div class="billing-required-payment-bar">
                                                            <div>
                                                                <?php renderBillingPaymentRequiredModeFields((array) ($packageCheckoutForm['modes'] ?? [])); ?>
                                                            </div>
                                                            <button type="submit" class="billing-package-card__button"><?php echo htmlspecialchars($checkoutButtonLabel); ?></button>
                                                        </div>
                                                    </form>
                                                <?php endforeach; ?>
                                            <?php elseif ($changeDirection === 'downgrade' && $changeAvailable && $schedulePriceId > 0): ?>
                                                <button type="submit" form="<?php echo htmlspecialchars($scheduleFormId); ?>" class="billing-package-card__button billing-package-card__button--secondary">Schedule downgrade</button>
                                            <?php elseif ($changeDirection === 'downgrade' && (int) ($seatOverage['overage'] ?? 0) > 0): ?>
                                                <p class="billing-required-card-note">
                                                    <?php echo htmlspecialchars((string) ($plan['blocked_reason'] ?? 'Reduce seats before scheduling this downgrade.')); ?>
                                                    <a href="<?php echo htmlspecialchars(publicUrl('settings.php?tab=workspace#workspace-team')); ?>">Manage members and invites</a>.
                                                </p>
                                            <?php elseif ($changeDirection === 'scheduled'): ?>
                                                <button type="submit" form="billing-change-cancel-scheduled" class="billing-package-card__button billing-package-card__button--secondary">Cancel scheduled change</button>
                                            <?php elseif ($packageExempt): ?>
                                                <p class="billing-required-card-note">Checkout exempt for this workspace.</p>
                                            <?php else: ?>
                                                <p class="billing-required-card-note"><?php echo htmlspecialchars((string) ($plan['status_message'] ?? 'Contact support or choose another available package.')); ?></p>
                                            <?php endif; ?>
                                            <button
                                                type="button"
                                                class="billing-required-detail-trigger"
                                                data-package-detail-trigger
                                                data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                                                aria-controls="package-details-modal"
                                                aria-expanded="false"
                                            >
                                                View details
                                            </button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <p class="billing-packages-footnote">AI Credit top-ups stay separate from package changes. Paid package AI Credits follow a 180-day credit expiry. All packages include secure data, regular backups, and continuous feature updates.</p>
                            <?php renderLaunchPackageDetailsModal($billingPackageCards, $billingDefaultPackageCode); ?>
                            <?php foreach ($packageChangeForms as $formId => $form): ?>
                                <form id="<?php echo htmlspecialchars((string) $formId); ?>" method="POST" action="billing_change_package.php" hidden>
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=packages')); ?>">
                                    <input type="hidden" name="billing_change_action" value="<?php echo htmlspecialchars((string) ($form['action'] ?? '')); ?>">
                                    <?php if ((int) ($form['billing_plan_price_id'] ?? 0) > 0): ?>
                                        <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) $form['billing_plan_price_id']; ?>">
                                    <?php endif; ?>
                                </form>
                            <?php endforeach; ?>
                            <?php renderLaunchPackageModalScript(); ?>
                            <?php renderLaunchPackageBookingModal($packageMeetingSlots, $packageMeetingTimezone, Security::getCsrfToken()); ?>
                            <script>
                                (function () {
                                    var toggle = document.querySelector('[data-billing-cadence-toggle]');
                                    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-billing-plan-card]'));
                                    if (!toggle || cards.length === 0) {
                                        return;
                                    }

                                    var hasAnnualPricing = cards.some(function (card) {
                                        return parseInt(card.getAttribute('data-annual-price-id') || '0', 10) > 0;
                                    });
                                    toggle.disabled = !hasAnnualPricing;

                                    var setText = function (card, selector, value) {
                                        var node = card.querySelector(selector);
                                        if (node) {
                                            node.textContent = value || '';
                                        }
                                    };
                                    var applyCadence = function () {
                                        var preferAnnual = toggle.checked && !toggle.disabled;
                                        cards.forEach(function (card) {
                                            var annualId = parseInt(card.getAttribute('data-annual-price-id') || '0', 10);
                                            var cadence = preferAnnual && annualId > 0 ? 'annual' : 'monthly';
                                            var forms = Array.prototype.slice.call(card.querySelectorAll('[data-billing-checkout-form]'));
                                            if (forms.length > 0 && !forms.some(function (form) {
                                                return form.getAttribute('data-billing-checkout-cadence') === cadence;
                                            })) {
                                                cadence = forms[0].getAttribute('data-billing-checkout-cadence') || card.getAttribute('data-default-checkout-cadence') || 'monthly';
                                            }
                                            setText(card, '[data-billing-price-amount]', card.getAttribute('data-' + cadence + '-amount') || '');
                                            setText(card, '[data-billing-price-period]', card.getAttribute('data-' + cadence + '-period') || '');
                                            setText(card, '[data-billing-price-caption]', card.getAttribute('data-' + cadence + '-caption') || '');
                                            card.setAttribute('data-billing-cadence', cadence);
                                            forms.forEach(function (form) {
                                                form.hidden = form.getAttribute('data-billing-checkout-cadence') !== cadence;
                                            });
                                        });
                                    };

                                    toggle.addEventListener('change', applyCadence);
                                    applyCadence();
                                })();
                            </script>
                            <?php renderLaunchPackageBookingScript(); ?>
                        </section>
                    <?php elseif (!$canManageBilling): ?>
                        <section id="workspace-packages" class="billing-required-section">
                            <div class="billing-required-section-head">
                                <div>
                                    <h2>Workspace packages</h2>
                                    <p>You do not have permission to manage workspace billing. Ask a workspace owner or billing administrator to restore access.</p>
                                </div>
                            </div>
                        </section>
                    <?php else: ?>
                        <section id="workspace-packages" class="billing-required-section">
                            <div class="billing-required-section-head">
                                <div>
                                    <h2>Workspace packages</h2>
                                    <p>No workspace packages are currently available. Refresh billing or contact an administrator.</p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php elseif ($billingCurrentTab === 'tokens'): ?>
                    <section id="ai-token-refill" class="billing-required-section billing-required-section--quiet">
                        <div class="billing-required-section-head">
                            <div>
                                <h2><span class="billing-required-section-kicker"><i class="fas fa-coins" aria-hidden="true"></i></span><?php echo $isWalletDepleted ? 'Buy AI Credits to Restore AI Access' : 'Buy AI Credits'; ?></h2>
                                <p>AI Credit top-ups add to the workspace wallet and restore AI actions without changing the current package.</p>
                            </div>
                            <?php if ($isWalletDepleted): ?>
                                <span class="billing-required-chip"><i class="fas fa-bolt" aria-hidden="true"></i> AI blocked</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$canManageBilling): ?>
                            <p class="billing-required-empty">You do not have permission to buy AI Credits. Ask a workspace owner or billing administrator for help.</p>
                        <?php elseif (empty($billing['token_packs'])): ?>
                            <p class="billing-required-empty">No AI Credit packs are currently available. Refresh billing or contact an administrator.</p>
                        <?php else: ?>
                            <form method="POST" action="billing_start_payment.php" class="billing-required-token-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=tokens')); ?>">
                                <div class="billing-required-token-grid <?php echo htmlspecialchars($billingTokenGridClass); ?>">
                                    <?php foreach ((array) $billing['token_packs'] as $index => $pack): ?>
                                        <label class="billing-required-choice">
                                            <input class="billing-required-option-input" type="radio" name="token_pack_price_id" value="<?php echo (int) ($pack['id'] ?? 0); ?>" <?php echo ((int) $index === 0) ? 'checked' : ''; ?> required>
                                            <span class="billing-required-choice-inner">
                                                <span class="billing-required-plan-name"><?php echo number_format((int) ($pack['credit_quantity'] ?? $pack['token_quantity'] ?? 0)); ?> AI Credits</span>
                                                <span class="billing-required-price"><?php echo htmlspecialchars((string) ($pack['currency'] ?? 'KES')); ?> <?php echo number_format((float) ($pack['amount'] ?? 0), 2); ?></span>
                                                <span class="billing-required-radio-dot" aria-hidden="true"></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="billing-required-payment-bar <?php echo $paymentModes === [] ? 'is-payment-unavailable' : ''; ?>">
                                    <div>
                                        <?php renderBillingPaymentRequiredModeFields($paymentModes); ?>
                                    </div>
                                    <button type="submit" class="btn-premium-secondary"><i class="fas fa-plus" aria-hidden="true"></i> Buy AI Credits</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php elseif ($billingCurrentTab === 'activity'): ?>
                    <section id="payment-activity" class="billing-required-section billing-required-activity-section">
                        <div class="billing-required-section-head">
                            <div>
                                <h2>Recent payment activity</h2>
                                <p>Latest checkout and transaction signals from the payment provider.</p>
                            </div>
                        </div>
                        <?php if (!empty($latestTransaction) || !empty($latestCheckout)): ?>
                            <div class="billing-required-activity-table-wrap">
                                <table class="billing-required-activity-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Method</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Receipt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($billingActivityRows as $activityRow): ?>
                                            <tr>
                                                <td data-label="Date"><?php echo htmlspecialchars((string) ($activityRow['date'] ?? '-')); ?></td>
                                                <td data-label="Description"><?php echo htmlspecialchars((string) ($activityRow['description'] ?? 'Billing activity')); ?></td>
                                                <td data-label="Method"><?php echo htmlspecialchars((string) ($activityRow['method'] ?? '-')); ?></td>
                                                <td data-label="Amount"><?php echo htmlspecialchars((string) ($activityRow['amount'] ?? 'Not recorded')); ?></td>
                                                <td data-label="Status"><span class="billing-required-status-pill <?php echo htmlspecialchars((string) ($activityRow['status_class'] ?? 'is-warning')); ?>"><?php echo htmlspecialchars((string) ($activityRow['status'] ?? 'Pending')); ?></span></td>
                                                <td data-label="Receipt">
                                                    <?php if (!empty($activityRow['receipt_url'])): ?>
                                                        <a href="<?php echo htmlspecialchars((string) $activityRow['receipt_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) ($activityRow['receipt_label'] ?? 'Receipt')); ?></a>
                                                    <?php else: ?>
                                                        <span class="billing-required-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="billing-required-empty">No recent payment activity has been recorded for this workspace yet.</p>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <div id="billing-help" class="billing-required-help-grid">
                        <section class="billing-required-checklist">
                            <h2>Recovery checklist</h2>
                            <ol>
                                <li class="billing-required-step is-done">
                                    <span class="billing-required-step-icon"><i class="fas fa-check" aria-hidden="true"></i></span>
                                    <span><strong>Review billing status</strong><small><?php echo htmlspecialchars($subscriptionLabel); ?> package state confirmed.</small></span>
                                    <span class="billing-required-step-number">1</span>
                                </li>
                                <li class="billing-required-step <?php echo $isBillingBlocked ? 'is-active' : 'is-done'; ?>">
                                    <span class="billing-required-step-icon"><i class="fas <?php echo $isBillingBlocked ? 'fa-arrow-right' : 'fa-check'; ?>" aria-hidden="true"></i></span>
                                    <span><strong>Choose or retry package</strong><small>Select a workspace package and complete payment.</small></span>
                                    <span class="billing-required-step-number">2</span>
                                </li>
                                <li class="billing-required-step <?php echo $isWalletDepleted ? 'is-active' : ''; ?>">
                                    <span class="billing-required-step-icon"><i class="fas <?php echo $isWalletDepleted ? 'fa-arrow-right' : 'fa-bolt'; ?>" aria-hidden="true"></i></span>
                                    <span><strong>Buy AI Credits</strong><small><?php echo $isWalletDepleted ? 'Required to restore AI actions.' : 'Optional wallet top-up for AI actions.'; ?></small></span>
                                    <span class="billing-required-step-number">3</span>
                                </li>
                                <li class="billing-required-step <?php echo $billingStatus === 'success' ? 'is-done' : ''; ?>">
                                    <span class="billing-required-step-icon"><i class="fas <?php echo $billingStatus === 'success' ? 'fa-check' : 'fa-clock'; ?>" aria-hidden="true"></i></span>
                                    <span><strong>Payment confirmed</strong><small>Access refreshes after provider confirmation.</small></span>
                                    <span class="billing-required-step-number">4</span>
                                </li>
                            </ol>
                        </section>
                        <?php if ($donationsEnabled): ?>
                        <section class="billing-required-donation" aria-label="Workspace affordability donation">
                            <div class="billing-required-donation-icon" aria-hidden="true"><i class="fas fa-heart" aria-hidden="true"></i></div>
                            <div>
                                <h2>Workspace affordability support</h2>
                                <p>Donations support affordability and do not change your current package or AI Credit balance.</p>
                            </div>
                            <div class="billing-required-donation-actions">
                                <a href="<?php echo htmlspecialchars($workspaceAffordabilityHelpUrl); ?>" class="btn-premium-secondary"><i class="fas fa-headset" aria-hidden="true"></i> Request Support</a>
                                <button type="button" class="btn-premium-secondary" data-workspace-donation-open><i class="fas fa-heart" aria-hidden="true"></i> Make a Contribution</button>
                            </div>
                        </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="billing-required-footer-actions">
                <a href="<?php echo htmlspecialchars(publicUrl('dashboard.php')); ?>" class="btn-premium-secondary"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
                <a href="<?php echo htmlspecialchars(publicUrl('logout.php')); ?>" class="btn-premium-secondary"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out</a>
            </div>
            <script>
                (function () {
                    function syncPaymentModeFields(group) {
                        var select = group.querySelector('[data-billing-payment-mode-select]');
                        var phoneField = group.querySelector('[data-billing-payment-phone-field]');
                        var phoneInput = group.querySelector('[data-billing-payment-phone-input]');
                        if (!select || !phoneField || !phoneInput) {
                            return;
                        }

                        var selectedOption = select.options[select.selectedIndex];
                        var requiresPhone = !!selectedOption && selectedOption.getAttribute('data-requires-phone') === '1';
                        phoneField.hidden = !requiresPhone;
                        phoneInput.required = requiresPhone;
                        phoneInput.disabled = !requiresPhone;
                        if (!requiresPhone) {
                            phoneInput.value = '';
                        }
                    }

                    function initPaymentModeFields() {
                        Array.prototype.forEach.call(document.querySelectorAll('[data-billing-payment-mode-fields]'), function (group) {
                            var select = group.querySelector('[data-billing-payment-mode-select]');
                            if (!select) {
                                return;
                            }

                            syncPaymentModeFields(group);
                            select.addEventListener('change', function () {
                                syncPaymentModeFields(group);
                            });
                        });
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initPaymentModeFields, { once: true });
                        return;
                    }

                    initPaymentModeFields();
                })();
            </script>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
