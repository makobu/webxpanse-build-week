<?php
/**
 * Internal pricing and package explanation page.
 */

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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\LaunchPackageCatalogService;
use CRM\Services\SaaSBillingService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('pages.pricing.view', $user)) {
    header('Location: dashboard.php');
    exit;
}

$catalogError = '';
$packageCards = [];
$creditPacks = [];
try {
    $billing = new SaaSBillingService();
    $packageCards = (new LaunchPackageCatalogService())->cardsFromSubscriptionPrices(
        $billing->listSubscriptionPrices(),
        true
    );
    $creditPacks = $billing->listTokenPacks();
} catch (Throwable $e) {
    $catalogError = 'Pricing catalog is temporarily unavailable. Super Admin pricing controls remain in the Billing Hub.';
}

$packagesUrl = publicUrl('billing_payment_required.php?tab=packages#workspace-packages');
$tokensUrl = publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill');

$pageTitle = 'Pricing - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<style>
    .pricing-model-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: .8rem; }
    .pricing-package-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
    .pricing-card { display: grid; gap: .85rem; padding: 1.2rem; }
    .pricing-card__head { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start; }
    .pricing-card__title { font-size: 1.08rem; font-weight: 850; color: #0f172a; }
    .pricing-card__copy { margin-top: .22rem; color: #64748b; font-size: .88rem; line-height: 1.55; }
    .pricing-card__price { white-space: nowrap; }
    .pricing-metric-list { display: grid; gap: .48rem; }
    .pricing-metric { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem 0; border-bottom: 1px solid #e2e8f0; color: #334155; font-size: .85rem; }
    .pricing-metric:last-child { border-bottom: 0; }
    .pricing-metric span { color: #64748b; }
    .pricing-metric strong { text-align: right; color: #0f172a; }
    .pricing-pill-row { display: flex; flex-wrap: wrap; gap: .42rem; }
    .pricing-pill { display: inline-flex; align-items: center; border-radius: 999px; background: #eef2ff; color: #3730a3; padding: .32rem .58rem; font-size: .74rem; font-weight: 800; }
    .pricing-pill.is-locked { background: #f1f5f9; color: #475569; }
    .pricing-note { margin: 0; color: #475569; line-height: 1.68; }
    @media (max-width: 640px) {
        .pricing-card__head { display: grid; }
        .pricing-card__price { white-space: normal; width: fit-content; }
    }
</style>

<div class="page-premium">
    <div class="container" style="display:grid;gap:1rem;">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars(brandProductName()); ?> Packages</h1>
                <p>Plans unlock business capability. AI Credits control usage. Plugins expand access as the workspace matures.</p>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo htmlspecialchars($packagesUrl); ?>" class="btn-premium-primary">Review Workspace Package</a>
            </div>
        </div>

        <?php if ($catalogError !== ''): ?>
            <div class="content-card" style="padding:1rem 1.1rem;color:#9a3412;background:#fff7ed;border-color:#fed7aa;">
                <?php echo htmlspecialchars($catalogError); ?>
            </div>
        <?php endif; ?>

        <div class="content-card" style="padding:1.2rem 1.25rem;">
            <div class="pricing-model-grid">
                <div>
                    <div style="font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0f766e;">Packages</div>
                    <p class="pricing-note">Compass Free starts every workspace, then paid packages add seats, recurring AI Credits, and mature business capability.</p>
                </div>
                <div>
                    <div style="font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8;">AI Credits</div>
                    <p class="pricing-note">One customer-facing AI Credit maps to one internal usage unit. Package credits and top-up packs expire after 180 days.</p>
                </div>
                <div>
                    <div style="font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#7c2d12;">Plugins</div>
                    <p class="pricing-note">Business Intelligence unlocks on Founder Plus. Personal API key access unlocks on Growth Studio and Scale Custom.</p>
                </div>
            </div>
        </div>

        <div class="pricing-package-grid">
            <?php foreach ($packageCards as $package): ?>
                <?php
                $entitlements = (array) ($package['entitlements'] ?? []);
                $capabilitySummary = (array) ($package['capability_summary'] ?? []);
                $topUpsEnabled = !empty($entitlements['can_top_up']);
                $biEnabled = !empty($entitlements['business_intelligence_enabled']);
                $apiEnabled = !empty($entitlements['personal_api_key_enabled']);
                ?>
                <div class="content-card pricing-card">
                    <div class="pricing-card__head">
                        <div>
                            <div class="pricing-card__title"><?php echo htmlspecialchars((string) ($package['display_name'] ?? $package['name'] ?? 'Package')); ?></div>
                            <div class="pricing-card__copy"><?php echo htmlspecialchars((string) ($package['summary'] ?? $package['description'] ?? '')); ?></div>
                        </div>
                        <span class="badge badge-primary pricing-card__price"><?php echo htmlspecialchars((string) ($package['price_short'] ?? $package['price'] ?? 'Custom')); ?></span>
                    </div>
                    <div class="pricing-metric-list">
                        <div class="pricing-metric"><span>Seats</span><strong><?php echo htmlspecialchars((string) ($package['seat_summary'] ?? 'Configured in Billing Hub')); ?></strong></div>
                        <div class="pricing-metric"><span>Included AI Credits</span><strong><?php echo htmlspecialchars((string) ($package['credit_summary'] ?? 'Configured in Billing Hub')); ?></strong></div>
                        <div class="pricing-metric"><span>Top-up packs</span><strong><?php echo $topUpsEnabled ? 'Available' : 'Unavailable'; ?></strong></div>
                        <div class="pricing-metric"><span>Plugins</span><strong><?php echo htmlspecialchars((string) ($package['plugin_summary'] ?? 'Core plugins only')); ?></strong></div>
                        <div class="pricing-metric"><span>Credit expiry</span><strong><?php echo number_format((int) ($entitlements['credit_expiry_days'] ?? 180)); ?> days</strong></div>
                    </div>
                    <div class="pricing-pill-row">
                        <span class="pricing-pill <?php echo $topUpsEnabled ? '' : 'is-locked'; ?>"><?php echo $topUpsEnabled ? 'Top-ups available' : 'No top-ups'; ?></span>
                        <span class="pricing-pill <?php echo $biEnabled ? '' : 'is-locked'; ?>"><?php echo $biEnabled ? 'BI included' : 'BI locked'; ?></span>
                        <span class="pricing-pill <?php echo $apiEnabled ? '' : 'is-locked'; ?>"><?php echo $apiEnabled ? 'Personal API key included' : 'Personal API key locked'; ?></span>
                    </div>
                    <?php if ($capabilitySummary !== []): ?>
                        <ul style="margin:0;padding-left:1rem;color:#334155;line-height:1.75;font-size:.88rem;">
                            <?php foreach ($capabilitySummary as $capability): ?>
                                <li><?php echo htmlspecialchars((string) $capability); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="content-card" style="padding:1.2rem 1.25rem;display:grid;gap:.9rem;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <div style="font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8;">AI Credit top-up packs</div>
                    <p class="pricing-note">Top-ups are separate from recurring package credits and unlock on Solo Launch and higher packages.</p>
                </div>
                <a href="<?php echo htmlspecialchars($tokensUrl); ?>" class="btn-premium-secondary">Open AI Credits</a>
            </div>
            <div class="pricing-package-grid">
                <?php foreach ($creditPacks as $pack): ?>
                    <div class="pricing-card" style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
                        <div class="pricing-card__head">
                            <div>
                                <div class="pricing-card__title"><?php echo htmlspecialchars((string) ($pack['display_name'] ?? $pack['plan_name'] ?? 'AI Credit Pack')); ?></div>
                                <div class="pricing-card__copy"><?php echo number_format((int) ($pack['credit_quantity'] ?? $pack['token_quantity'] ?? 0)); ?> AI Credits, expires after 180 days.</div>
                            </div>
                            <span class="badge badge-primary pricing-card__price"><?php echo htmlspecialchars(strtoupper((string) ($pack['currency'] ?? 'KES')) . ' ' . number_format((float) ($pack['amount'] ?? 0), 0)); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($creditPacks === []): ?>
                    <div class="pricing-card" style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
                        <p class="pricing-note">No active AI Credit packs are configured.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
