<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user() ?: [];
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0 || $userId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$billing = new SaaSBillingService();
$activation = [];
$error = '';
try {
    $activation = $billing->activateCompassFreeSubscription($workspaceId, $userId);
} catch (Throwable $e) {
    $error = 'Compass Free could not be refreshed right now, but your workspace dashboard is still available.';
}

$portal = $billing->getWorkspaceBillingPortalData($workspaceId);
$snapshot = (array) ($portal['snapshot'] ?? []);
$entitlements = (array) ($snapshot['entitlements'] ?? $portal['entitlements'] ?? []);
$subscription = (array) ($snapshot['subscription'] ?? []);
$planName = (string) (($subscription['plan_name'] ?? '') ?: ($entitlements['public_display_name'] ?? 'Compass Free'));
$creditBalance = (int) ($snapshot['credit_balance'] ?? $snapshot['token_balance'] ?? 0);
$includedCredits = (int) ($entitlements['included_credits'] ?? 50000);
$creditExpiryDays = (int) ($entitlements['credit_expiry_days'] ?? 180);
$canTopUp = !empty($entitlements['can_top_up']);
$businessIntelligenceEnabled = !empty($entitlements['business_intelligence_enabled']);
$personalApiKeyEnabled = !empty($entitlements['personal_api_key_enabled']);
$credited = (int) ($activation['credited']['credited_credits'] ?? $activation['credited']['credited_tokens'] ?? 0);
$source = trim((string) ($_GET['source'] ?? ''));
if (str_starts_with($source, 'guided_demo')) {
    $redirect = $source === 'guided_demo_skip'
        ? publicUrl('dashboard.php')
        : publicUrl('dashboard.php?demo=complete');
    header('Location: ' . $redirect);
    exit;
}
$pageTitle = 'Compass Free - ' . brandProductName();

ob_start();
?>
<style>
    .compass-free-shell { max-width: 1040px; margin: 0 auto; padding: 32px 20px 56px; }
    .compass-free-hero { border: 1px solid #d8dee8; border-radius: 8px; background: #fff; padding: 28px; box-shadow: 0 12px 30px rgba(16, 24, 40, .07); }
    .compass-free-kicker { margin: 0 0 8px; text-transform: uppercase; letter-spacing: .08em; font-size: 12px; font-weight: 800; color: #0f766e; }
    .compass-free-hero h1 { margin: 0; font-size: clamp(28px, 4vw, 42px); letter-spacing: 0; color: #172033; }
    .compass-free-copy { margin: 12px 0 0; color: #536174; font-size: 16px; line-height: 1.6; max-width: 760px; }
    .compass-free-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 22px; }
    .compass-free-stat { border: 1px solid #e3e8f0; border-radius: 8px; padding: 14px; background: #f8fafc; }
    .compass-free-stat span { display: block; font-size: 12px; color: #647085; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .compass-free-stat strong { display: block; margin-top: 6px; font-size: 24px; color: #172033; }
    .compass-free-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
    .compass-free-actions a { text-decoration: none; border-radius: 6px; padding: 11px 14px; font-weight: 800; }
    .compass-free-primary { background: #0f766e; color: #fff; }
    .compass-free-secondary { background: #fff; color: #172033; border: 1px solid #d8dee8; }
    .compass-free-alert { margin-top: 16px; padding: 10px 12px; border-radius: 6px; background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
    .compass-free-note { margin-top: 16px; color: #647085; font-size: 14px; }
</style>

<main class="compass-free-shell">
    <section class="compass-free-hero">
        <p class="compass-free-kicker"><?php echo htmlspecialchars($source === 'guided_demo' ? 'Guided onboarding complete' : 'Workspace package'); ?></p>
        <h1>You are on <?php echo htmlspecialchars($planName); ?></h1>
        <p class="compass-free-copy">Compass Free gives one seat, 50,000 one-time onboarding AI Credits, and core system access so you can learn the workspace flow. Top-ups, Business Intelligence, and personal API keys stay locked until the workspace upgrades. All granted AI Credits expire after 180 days.</p>
        <?php if ($error !== ''): ?><div class="compass-free-alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <div class="compass-free-grid">
            <div class="compass-free-stat"><span>Seats</span><strong><?php echo ((int) ($entitlements['seat_limit'] ?? 1)) > 0 ? number_format((int) $entitlements['seat_limit']) : 'Unlimited'; ?></strong></div>
            <div class="compass-free-stat"><span>Onboarding AI Credits</span><strong><?php echo number_format($includedCredits); ?></strong></div>
            <div class="compass-free-stat"><span>Current Balance</span><strong><?php echo number_format($creditBalance); ?></strong></div>
            <div class="compass-free-stat"><span>Credit Expiry</span><strong><?php echo number_format($creditExpiryDays); ?> days</strong></div>
            <div class="compass-free-stat"><span>Top-ups</span><strong><?php echo $canTopUp ? 'Available' : 'Locked'; ?></strong></div>
            <div class="compass-free-stat"><span>BI Plugin</span><strong><?php echo $businessIntelligenceEnabled ? 'Included' : 'Locked'; ?></strong></div>
            <div class="compass-free-stat"><span>Personal API Key</span><strong><?php echo $personalApiKeyEnabled ? 'Included' : 'Locked'; ?></strong></div>
        </div>
        <?php if ($credited > 0): ?>
            <p class="compass-free-note"><?php echo number_format($credited); ?> onboarding AI Credits were added to this workspace.</p>
        <?php else: ?>
            <p class="compass-free-note">Your Compass Free onboarding credits are already attached to this workspace.</p>
        <?php endif; ?>
        <div class="compass-free-actions">
            <a class="compass-free-primary" href="<?php echo htmlspecialchars(publicUrl('dashboard.php?onboarding=complete')); ?>">Open Dashboard</a>
            <a class="compass-free-secondary" href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=packages#workspace-packages')); ?>">View Upgrade Options</a>
            <a class="compass-free-secondary" href="<?php echo htmlspecialchars(publicUrl('onboarding.php')); ?>">Continue Onboarding</a>
        </div>
    </section>
</main>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
