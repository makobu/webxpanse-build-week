<?php

declare(strict_types=1);

require_once __DIR__ . '/_public_bootstrap.php';
require_once __DIR__ . '/../views/partials/launch_package_features.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\GuidedDemoPackageIntentService;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\PackageNegotiationMeetingService;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user() ?: [];
$userId = (int) ($currentUser['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0 || $userId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$presentationGuard = new PresentationWorkspaceGuardService();
if ($presentationGuard->isBlocked('billing', $workspaceId)) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 403 : 200);
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

$sessionService = new GuidedDemoSessionService();
$packageService = new GuidedDemoPackageIntentService();
$packageMeetingService = new PackageNegotiationMeetingService();
$latestSession = $sessionService->latestSession($workspaceId, $userId);
$sessionId = !empty($latestSession['id']) ? (int) $latestSession['id'] : null;
$completedActions = [];
if ($sessionId !== null && Database::tableExists('guided_demo_action_runs')) {
    $completedActions = array_values(array_filter(array_map(
        static fn(array $row): string => (string) ($row['action_key'] ?? ''),
        Database::query(
            "SELECT action_key FROM guided_demo_action_runs WHERE session_id = ? AND status = 'completed' ORDER BY id ASC",
            [$sessionId]
        )
    )));
}
$packages = $packageService->packages($workspaceId);
$recommendation = $packageService->recommendationForSession($latestSession, $completedActions);
$packageIntentMetadata = static function (array $package): array {
    $keys = [
        'code',
        'display_name',
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
        'currency_needs_pricing',
        'checkout_available',
        'inherits_from',
        'tier_intro',
        'feature_highlights',
        'feature_groups',
        'best_fit',
        'upgrade_reason',
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
};
$packageRequiresMeeting = static function (array $package): bool {
    return !empty($package['is_custom'])
        || !empty($package['workspace_negotiated'])
        || !empty($package['is_workspace_private'])
        || (string) ($package['code'] ?? '') === GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM;
};
$message = Session::get('guided_demo_package_message');
$error = Session::get('guided_demo_package_error');
Session::remove('guided_demo_package_message');
Session::remove('guided_demo_package_error');
$packageMeetingTimezone = date_default_timezone_get() ?: 'UTC';
$packageMeetingSlots = [];
$packageMeetingState = [];
try {
    $packageMeetingState = $packageMeetingService->setupState();
    $packageMeetingTimezone = (string) ($packageMeetingState['timezone'] ?? $packageMeetingTimezone);
    $packageMeetingSlots = $packageMeetingService->availableSlots($packageMeetingTimezone, 21, 80);
} catch (Throwable $e) {
    $packageMeetingSlots = [];
    if ($error === null) {
        $error = 'Package meeting calendar is temporarily unavailable. Please try again shortly.';
    }
}

if (($latestSession['status'] ?? '') === 'cleanup_failed' || ($latestSession['cleanup_status'] ?? '') === 'failed') {
    header('Location: ' . publicUrl('guided_demo.php?cleanup=failed'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $packageCode = (string) ($_POST['package_code'] ?? '');
        $selected = null;
        foreach ($packages as $package) {
            if ((string) ($package['code'] ?? '') === $packageCode) {
                $selected = $package;
                break;
            }
        }
        if ($selected === null) {
            throw new RuntimeException('Choose a package before continuing.');
        }

        $action = (string) ($_POST['package_action'] ?? 'select');
        $contactMethod = (string) ($_POST['contact_method'] ?? 'email');
        $notes = (string) ($_POST['notes'] ?? '');
        $selectedPriceId = (int) ($_POST['billing_plan_price_id'] ?? 0);

        if ($action === 'book_meeting') {
            if (!$packageRequiresMeeting($selected)) {
                throw new RuntimeException('Meeting booking is available for negotiated packages only.');
            }

            $meetingSlot = trim((string) ($_POST['meeting_slot'] ?? ''));
            $meetingTimezone = trim((string) ($_POST['meeting_timezone'] ?? ''));
            if ($meetingSlot === '') {
                throw new RuntimeException('Choose a meeting day and time.');
            }
            $meetingContext = [
                'package_recommendation' => $recommendation,
                'completed_actions' => $completedActions,
                'selected_package' => $packageIntentMetadata($selected),
            ];
            if ($latestSession) {
                $meetingContext['demo_cleanup_summary'] = json_decode((string) ($latestSession['cleanup_summary_json'] ?? '{}'), true) ?: [];
            }

            $bookingResult = $packageMeetingService->bookPackageMeeting(
                $workspaceId,
                $userId,
                $sessionId,
                $packageCode,
                $meetingSlot,
                $meetingTimezone !== '' ? $meetingTimezone : $packageMeetingTimezone,
                $meetingContext,
                $packageService
            );
            $booking = (array) ($bookingResult['booking'] ?? []);
            $bookingStatus = (string) ($booking['status'] ?? ($bookingResult['booking_result']['status'] ?? 'pending'));
            $bookingStart = trim((string) ($booking['scheduled_start'] ?? $meetingSlot));
            $statusLabel = $bookingStatus === 'confirmed' ? 'confirmed' : 'requested';
            Session::set('guided_demo_package_message', 'Support meeting ' . $statusLabel . ' for ' . $bookingStart . ' ' . ($meetingTimezone !== '' ? $meetingTimezone : $packageMeetingTimezone) . '.');
            header('Location: ' . publicUrl('billing_choose_package.php?selected=' . rawurlencode($packageCode)));
            exit;
        }

        if (!empty($selected['is_free'])) {
            $priceId = (int) ($selected['billing_plan_price_id'] ?? $selectedPriceId);
            $checkout = $priceId > 0
                ? (new SaaSBillingService())->createCheckout($workspaceId, [
                    'billing_plan_price_id' => $priceId,
                    'payment_mode' => 'card',
                    'return_to' => publicUrl('billing_choose_package.php?selected=' . rawurlencode($packageCode)),
                ], $userId)
                : [];
            $packageService->recordIntent($workspaceId, $userId, $sessionId, $packageCode, 'completed', $contactMethod, $notes, [
                'source' => 'guided_demo_package_chooser',
                'package_recommendation' => $recommendation,
                'completed_actions' => $completedActions,
                'selected_package' => $packageIntentMetadata($selected),
            ]);
            Session::set('guided_demo_package_message', (string) ($checkout['display_text'] ?? 'Compass Free is active for this workspace.'));
            header('Location: ' . publicUrl('billing_choose_package.php?selected=' . rawurlencode($packageCode)));
            exit;
        }

        if ($packageCode === GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM) {
            $packageService->recordIntent($workspaceId, $userId, $sessionId, $packageCode, 'requested', $contactMethod, $notes, [
                'source' => 'guided_demo_package_chooser',
                'package_recommendation' => $recommendation,
                'completed_actions' => $completedActions,
                'demo_cleanup_summary' => $latestSession ? json_decode((string) ($latestSession['cleanup_summary_json'] ?? '{}'), true) : [],
                'selected_package' => $packageIntentMetadata($selected),
            ]);
            Session::set('guided_demo_package_message', 'Scale Custom request recorded. A sales handoff is queued for follow-up.');
            header('Location: ' . publicUrl('billing_choose_package.php?selected=' . rawurlencode($packageCode)));
            exit;
        }

        if (!empty($selected['checkout_available'])) {
            $checkoutOptions = is_array($selected['checkout_options'] ?? null) ? (array) $selected['checkout_options'] : [];
            if ($selectedPriceId <= 0 && !empty($checkoutOptions[0]['billing_plan_price_id'])) {
                $selectedPriceId = (int) $checkoutOptions[0]['billing_plan_price_id'];
            }
            $matchedPrice = null;
            foreach ($checkoutOptions as $option) {
                if ((int) ($option['billing_plan_price_id'] ?? 0) === $selectedPriceId) {
                    $matchedPrice = $option;
                    break;
                }
            }
            if ($selectedPriceId <= 0 || $matchedPrice === null) {
                throw new RuntimeException('Choose a monthly or annual package price before checkout.');
            }

            $packageService->recordIntent($workspaceId, $userId, $sessionId, $packageCode, 'checkout_started', $contactMethod, $notes, [
                'source' => 'guided_demo_package_chooser',
                'billing_plan_price_id' => $selectedPriceId,
                'selected_price' => $matchedPrice,
                'package_recommendation' => $recommendation,
                'completed_actions' => $completedActions,
                'selected_package' => $packageIntentMetadata($selected),
            ]);
            $checkout = (new SaaSBillingService())->createCheckout($workspaceId, [
                'billing_plan_price_id' => $selectedPriceId,
                'payment_mode' => 'card',
                'return_to' => publicUrl('billing_choose_package.php?checkout=' . rawurlencode($packageCode)),
            ], $userId);
            $redirect = (string) ($checkout['authorization_url'] ?? $checkout['checkout_url'] ?? '');
            if ($redirect !== '') {
                header('Location: ' . $redirect);
                exit;
            }
            Session::set('guided_demo_package_message', (string) ($checkout['display_text'] ?? 'Package checkout has been created. Follow the payment instructions returned by the provider.'));
            header('Location: ' . publicUrl('billing_choose_package.php?checkout=' . rawurlencode($packageCode)));
            exit;
        }

        $packageService->recordIntent($workspaceId, $userId, $sessionId, $packageCode, 'requested', $contactMethod, $notes, [
            'source' => 'guided_demo_package_chooser',
            'package_recommendation' => $recommendation,
            'completed_actions' => $completedActions,
            'demo_cleanup_summary' => $latestSession ? json_decode((string) ($latestSession['cleanup_summary_json'] ?? '{}'), true) : [],
            'selected_package' => $packageIntentMetadata($selected),
        ]);
        Session::set('guided_demo_package_message', 'Package request recorded. Your launch path is now queued for follow-up.');
        header('Location: ' . publicUrl('billing_choose_package.php?selected=' . rawurlencode($packageCode)));
        exit;
    } catch (Throwable $e) {
        Session::set('guided_demo_package_error', $e->getMessage());
        header('Location: ' . publicUrl('billing_choose_package.php'));
        exit;
    }
}

$csrf = Security::getCsrfToken();
$pageTitle = 'Choose Your Launch Package - ' . brandProductName();
$guidedDemoPageStyles = true;
$defaultPackageCode = (string) ($recommendation['package_code'] ?? '');
if ($defaultPackageCode === '' && !empty($packages[0]['code'])) {
    $defaultPackageCode = (string) $packages[0]['code'];
}

ob_start();
?>
<section class="package-chooser" aria-labelledby="package-chooser-title" data-launch-package-section>
    <div class="package-chooser__hero">
        <div class="package-chooser__header">
            <p class="package-chooser__kicker">Founder launch</p>
            <h1 id="package-chooser-title">Pick the path that gets you to market</h1>
            <?php if ($message): ?><div class="package-chooser__notice"><?php echo htmlspecialchars((string) $message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="package-chooser__notice is-error"><?php echo htmlspecialchars((string) $error); ?></div><?php endif; ?>
        </div>
    </div>

    <div class="package-chooser__grid">
        <?php foreach ($packages as $package): ?>
            <?php
                $packageCode = (string) ($package['code'] ?? '');
                $isRecommended = $packageCode === (string) ($recommendation['package_code'] ?? '');
                $isDetailActive = $packageCode === $defaultPackageCode;
                $scopeLabel = trim((string) ($package['scope_label'] ?? ''));
                $tierIntro = trim((string) ($package['tier_intro'] ?? ''));
                $headerPrice = trim((string) ($package['price_short'] ?? $package['price'] ?? ''));
                $monthlyPrice = is_array($package['monthly_price'] ?? null) ? (array) $package['monthly_price'] : [];
                if (empty($package['is_free']) && empty($package['is_custom']) && $monthlyPrice !== []) {
                    $headerPrice = launchPackageFormatMoney((float) ($monthlyPrice['amount'] ?? 0), (string) ($monthlyPrice['currency'] ?? $package['currency_code'] ?? 'KES')) . '/mo';
                }
                $usesMeetingBooking = $packageRequiresMeeting($package);
            ?>
            <article
                class="package-card package-card--compact <?php echo $isRecommended ? 'is-recommended' : ''; ?> <?php echo $isDetailActive ? 'is-detail-active' : ''; ?>"
                data-package-card
                data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
            >
                <div class="package-card__content">
                    <?php if ($isRecommended): ?>
                        <p class="package-card__recommendation">Recommended path</p>
                    <?php endif; ?>
                    <div class="package-card__topline">
                        <p class="package-card__eyebrow"><?php echo htmlspecialchars((string) ($package['positioning'] ?? 'Founder support')); ?></p>
                        <p class="package-card__price"><?php echo htmlspecialchars($headerPrice); ?></p>
                    </div>
                    <h2><?php echo htmlspecialchars((string) ($package['display_name'] ?? $package['name'] ?? 'Package')); ?></h2>
                    <?php if ($scopeLabel !== ''): ?>
                        <p class="package-card__scope"><?php echo htmlspecialchars($scopeLabel); ?></p>
                    <?php endif; ?>
                    <?php if ($tierIntro !== ''): ?>
                        <p class="package-card__tier-intro"><?php echo htmlspecialchars($tierIntro); ?></p>
                    <?php endif; ?>
                    <?php renderLaunchPackageHighlights($package, 'package-card__features'); ?>
                    <button
                        type="button"
                        class="package-card__details-button"
                        data-package-detail-trigger
                        data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                        aria-controls="package-details-modal"
                        aria-expanded="false"
                    >
                        View details
                    </button>
                </div>
                <?php if ($usesMeetingBooking): ?>
                    <div class="package-card__form">
                        <button
                            type="button"
                            class="btn-premium-primary"
                            data-package-booking-trigger
                            data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                            data-package-name="<?php echo htmlspecialchars((string) ($package['display_name'] ?? $package['name'] ?? 'Package')); ?>"
                            aria-controls="package-booking-modal"
                            aria-expanded="false"
                        >
                            <span>Book now</span>
                        </button>
                    </div>
                <?php else: ?>
                    <form method="POST" class="package-card__form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="package_code" value="<?php echo htmlspecialchars($packageCode); ?>">
                        <?php $checkoutOptions = is_array($package['checkout_options'] ?? null) ? (array) $package['checkout_options'] : []; ?>
                        <?php if (!empty($package['is_free']) && !empty($package['billing_plan_price_id'])): ?>
                            <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) $package['billing_plan_price_id']; ?>">
                        <?php elseif (!empty($package['checkout_available']) && $checkoutOptions !== []): ?>
                            <?php renderLaunchPackageCadenceOptions($package, 'package-card__cadence'); ?>
                        <?php endif; ?>
                        <button type="submit" class="btn-premium-primary">
                            <span><?php echo htmlspecialchars((string) ($package['cta'] ?? 'Choose Package')); ?></span>
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php renderLaunchPackageDetailsModal($packages, $defaultPackageCode); ?>
    <?php renderLaunchPackageBookingModal($packageMeetingSlots, $packageMeetingTimezone, $csrf); ?>
    <?php renderLaunchPackageModalScript(); ?>
    <?php renderLaunchPackageBookingScript(); ?>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
