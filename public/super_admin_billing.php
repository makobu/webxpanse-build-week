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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\WorkspaceCreditLedgerService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

$operations = new PlatformWorkspaceOperationsService();
$creditLedger = new WorkspaceCreditLedgerService();
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$selectedWorkspaceId = max(0, (int) ($_GET['workspace_id'] ?? 0));

function superAdminBillingRedirect(array $params = []): void
{
    header('Location: super_admin_billing.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

function superAdminBillingMoney(float $amount, string $currency): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 0);
}

function superAdminBillingBoolLabel(bool $value): string
{
    return $value ? 'Yes' : 'No';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        superAdminBillingRedirect(['error' => 'Invalid security token. Please try again.']);
    }

    $action = trim((string) ($_POST['billing_action'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $catalogActions = [
        'update_payment_methods',
        'update_plan',
        'update_pack',
        'create_package',
        'update_package',
        'clone_package',
        'delete_package',
        'create_price',
        'delete_price',
        'create_pack',
        'delete_pack',
        'save_feature_catalog',
        'save_package_features',
        'update_price_payment_methods',
    ];
    if (in_array($action, $catalogActions, true)) {
        header('Location: settings.php?tab=package_settings&error=' . rawurlencode('Package catalog changes now live in Package Settings.'));
        exit;
    }

    try {
        if ($reason === '') {
            throw new RuntimeException('A reason is required for billing changes.');
        }

        if ($action === 'activate_workspace_plan') {
            $activation = $operations->activateWorkspaceSubscriptionPlan(
                (int) ($_POST['workspace_id'] ?? 0),
                (int) ($_POST['billing_plan_price_id'] ?? 0),
                (int) ($user['id'] ?? 0),
                $reason
            );
            $grantStatus = (string) ($activation['credit_result']['credit_grant_status'] ?? '');
            $notice = $grantStatus === 'duplicate_active_period'
                ? 'Workspace package is already active for the current period; no duplicate AI Credits were granted.'
                : 'Workspace package activated and period credits granted.';
            superAdminBillingRedirect(['notice' => $notice]);
        }

        if ($action === 'schedule_downgrade') {
            $operations->scheduleWorkspaceDowngrade(
                (int) ($_POST['workspace_id'] ?? 0),
                (int) ($_POST['billing_plan_price_id'] ?? 0),
                (int) ($user['id'] ?? 0),
                $reason,
                !empty($_POST['override_seats'])
            );
            superAdminBillingRedirect(['notice' => 'Workspace downgrade scheduled for renewal.']);
        }

        if ($action === 'wallet_adjustment') {
            $operations->adjustWorkspaceWallet(
                (int) ($_POST['workspace_id'] ?? 0),
                (int) ($_POST['credit_delta'] ?? 0),
                (int) ($user['id'] ?? 0),
                $reason
            );
            superAdminBillingRedirect(['notice' => 'Workspace AI Credit adjustment recorded.']);
        }

        if ($action === 'expire_credits') {
            $summary = $creditLedger->expireUnusedCredits(
                (int) ($_POST['workspace_id'] ?? 0) ?: null,
                null,
                (int) ($user['id'] ?? 0),
                $reason,
                ['source' => 'super_admin_billing_hub']
            );
            superAdminBillingRedirect(['notice' => 'Credit expiry processed for ' . (int) ($summary['expired_lots'] ?? 0) . ' lots.']);
        }

        throw new RuntimeException('Unsupported billing action.');
    } catch (Throwable $e) {
        superAdminBillingRedirect(['error' => $e->getMessage()]);
    }
}

$catalog = $operations->listBillingCatalogForOperators();
$subscriptionPrices = (array) ($catalog['subscription_prices'] ?? []);
$operatorAudit = (array) ($catalog['operator_audit'] ?? []);
$workspaceSubscriptions = Database::query(
    "SELECT ws.*, w.name AS workspace_name, w.slug AS workspace_slug,
            bp.name AS plan_name, bp.code AS plan_code, bpp.price_code,
            scheduled_bp.name AS scheduled_plan_name, scheduled_bpp.price_code AS scheduled_price_code
     FROM workspace_subscriptions ws
     JOIN workspaces w ON w.id = ws.workspace_id
     JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
     JOIN billing_plans bp ON bp.id = bpp.plan_id
     LEFT JOIN billing_plan_prices scheduled_bpp ON scheduled_bpp.id = ws.scheduled_billing_plan_price_id
     LEFT JOIN billing_plans scheduled_bp ON scheduled_bp.id = scheduled_bpp.plan_id
     ORDER BY ws.updated_at DESC, ws.id DESC
     LIMIT 80"
);
$walletLedger = Database::query(
    "SELECT wwl.*, w.name AS workspace_name, w.slug AS workspace_slug
     FROM workspace_wallet_ledger wwl
     JOIN workspaces w ON w.id = wwl.workspace_id
     ORDER BY wwl.id DESC
     LIMIT 100"
);
$creditLots = Database::tableExists('workspace_credit_lots')
    ? Database::query(
        "SELECT wcl.*, w.name AS workspace_name, w.slug AS workspace_slug
         FROM workspace_credit_lots wcl
         JOIN workspaces w ON w.id = wcl.workspace_id
         ORDER BY COALESCE(wcl.expires_at, '9999-12-31') ASC, wcl.id DESC
         LIMIT 100"
    )
    : [];
$workspaceCreditTargets = Database::query(
    "SELECT w.id, w.name, w.slug,
            COALESCE(wallet.token_balance, 0) AS token_balance,
            GREATEST(COALESCE(wallet.token_balance, 0) - COALESCE(wallet.reserved_tokens, 0), 0) AS available_tokens
     FROM workspaces w
     LEFT JOIN workspace_wallets wallet ON wallet.workspace_id = w.id
     ORDER BY w.name ASC, w.id ASC"
);
$selectedWorkspaceCreditTarget = null;
foreach ($workspaceCreditTargets as $workspaceCreditTarget) {
    if ((int) ($workspaceCreditTarget['id'] ?? 0) === $selectedWorkspaceId) {
        $selectedWorkspaceCreditTarget = $workspaceCreditTarget;
        break;
    }
}
$activeSubscriptionPrices = array_values(array_filter($subscriptionPrices, static fn (array $price): bool => !empty($price['is_active'])));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Super Admin Billing Hub</title>
    <style>
        :root { color-scheme: light; --ink:#172033; --muted:#647085; --line:#d8dee8; --soft:#f6f8fb; --accent:#0f766e; --danger:#b42318; }
        body { margin:0; font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; background:#f3f6fa; color:var(--ink); }
        .billing-admin-shell { max-width:1440px; margin:0 auto; padding:28px; }
        .billing-admin-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:20px; }
        .billing-admin-header h1 { margin:0; font-size:28px; letter-spacing:0; }
        .billing-admin-header p { margin:6px 0 0; color:var(--muted); max-width:760px; line-height:1.5; }
        .billing-admin-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:18px 0; }
        .billing-admin-tabs a, .billing-admin-button { border:1px solid var(--line); background:white; color:var(--ink); text-decoration:none; padding:9px 12px; border-radius:6px; font-weight:700; cursor:pointer; }
        .billing-admin-button--primary { background:var(--accent); border-color:var(--accent); color:white; }
        .billing-admin-section { background:white; border:1px solid var(--line); border-radius:8px; padding:18px; margin:16px 0; box-shadow:0 8px 24px rgba(18, 30, 48, 0.05); }
        .billing-admin-section h2 { margin:0 0 4px; font-size:20px; }
        .billing-admin-section > p { margin:0 0 14px; color:var(--muted); }
        .billing-admin-alert { border-radius:6px; padding:10px 12px; margin-bottom:12px; font-weight:700; }
        .billing-admin-alert--notice { background:#ecfdf5; color:#05603a; border:1px solid #a7f3d0; }
        .billing-admin-alert--error { background:#fff1f2; color:var(--danger); border:1px solid #fecdd3; }
        .billing-admin-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:14px; }
        .billing-admin-card { border:1px solid var(--line); border-radius:8px; padding:14px; background:#fff; }
        .billing-admin-card h3 { margin:0 0 4px; font-size:17px; }
        .billing-admin-card small { color:var(--muted); }
        .billing-admin-form { display:grid; gap:10px; margin-top:12px; }
        .billing-admin-fields { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .billing-admin-form label { display:grid; gap:5px; font-size:12px; font-weight:800; color:#344054; }
        .billing-admin-form input, .billing-admin-form select, .billing-admin-form textarea { width:100%; box-sizing:border-box; border:1px solid var(--line); border-radius:6px; padding:8px; font:inherit; }
        .billing-admin-form textarea { min-height:66px; resize:vertical; }
        .billing-admin-checks { display:flex; flex-wrap:wrap; gap:10px; }
        .billing-admin-checks label { display:flex; align-items:center; gap:6px; font-size:12px; }
        .billing-admin-table-wrap { overflow:auto; border:1px solid var(--line); border-radius:8px; }
        .billing-admin-table { width:100%; border-collapse:collapse; font-size:13px; min-width:980px; background:white; }
        .billing-admin-table th, .billing-admin-table td { padding:10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        .billing-admin-table th { background:var(--soft); color:#334155; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .billing-admin-pill { display:inline-flex; border-radius:999px; padding:3px 8px; background:#eef2ff; color:#3538cd; font-size:12px; font-weight:800; }
        .billing-admin-muted { color:var(--muted); }
        @media (max-width: 720px) { .billing-admin-shell { padding:18px; } .billing-admin-header { display:block; } }
    </style>
</head>
<body>
    <main class="billing-admin-shell">
        <header class="billing-admin-header">
            <div>
                <h1>Super Admin Billing Hub</h1>
                <p>Workspace billing operations for subscription activation, downgrade scheduling, wallet adjustments, credit expiry, ledgers, and billing audit review.</p>
            </div>
            <a class="billing-admin-button" href="workspaces.php">Back to Workspaces</a>
        </header>

        <?php if ($notice !== ''): ?><div class="billing-admin-alert billing-admin-alert--notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="billing-admin-alert billing-admin-alert--error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="billing-admin-section" style="border-color:#bfdbfe;background:#eff6ff;">
            <h2>Package catalog settings moved into Settings</h2>
            <p>Use the dedicated Package Settings tab for prices, descriptions, active package availability, AI Credit packs, and payment method visibility. Workspace subscription operations, wallet adjustments, credit expiry, ledger review, and billing audit remain available below.</p>
            <a class="billing-admin-button billing-admin-button--primary" href="settings.php?tab=package_settings">Open Package Settings</a>
        </section>

        <nav class="billing-admin-tabs" aria-label="Billing hub sections">
            <a href="#subscriptions">Workspace Subscriptions</a>
            <a href="#ai-credit-operations">AI Credit Operations</a>
            <a href="#ledger">Credit Ledger</a>
            <a href="#audit">Billing Audit</a>
        </nav>

        <section id="subscriptions" class="billing-admin-section">
            <h2>Workspace Subscriptions</h2>
            <p>Activate upgrades immediately or schedule downgrades for the next renewal. Seat cleanup is required unless explicitly overridden by Super Admin.</p>
            <div class="billing-admin-grid">
                <article class="billing-admin-card">
                    <h3>Activate or Change Now</h3>
                    <form method="POST" class="billing-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="billing_action" value="activate_workspace_plan">
                        <div class="billing-admin-fields">
                            <label>Workspace ID <input name="workspace_id" type="number" min="1" required></label>
                            <label>Package
                                <select name="billing_plan_price_id">
                                    <?php foreach ($activeSubscriptionPrices as $price): ?>
                                        <option value="<?php echo (int) ($price['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($price['plan_name'] ?? '') . ' · ' . (string) ($price['price_code'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label>Reason <input name="reason" placeholder="Required operator reason"></label>
                        <button class="billing-admin-button billing-admin-button--primary" type="submit">Activate Plan</button>
                    </form>
                </article>
                <article class="billing-admin-card">
                    <h3>Schedule Downgrade</h3>
                    <form method="POST" class="billing-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="billing_action" value="schedule_downgrade">
                        <div class="billing-admin-fields">
                            <label>Workspace ID <input name="workspace_id" type="number" min="1" required></label>
                            <label>Target package
                                <select name="billing_plan_price_id">
                                    <?php foreach ($activeSubscriptionPrices as $price): ?>
                                        <option value="<?php echo (int) ($price['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($price['plan_name'] ?? '') . ' · ' . (string) ($price['price_code'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="billing-admin-checks">
                            <label><input type="checkbox" name="override_seats"> Override seat cleanup block</label>
                        </div>
                        <label>Reason <input name="reason" placeholder="Required operator reason"></label>
                        <button class="billing-admin-button billing-admin-button--primary" type="submit">Schedule Downgrade</button>
                    </form>
                </article>
            </div>

            <div class="billing-admin-table-wrap" style="margin-top:14px;">
                <table class="billing-admin-table">
                    <thead><tr><th>Workspace</th><th>Current Package</th><th>Status</th><th>Period</th><th>Scheduled Change</th><th>Provider</th></tr></thead>
                    <tbody>
                        <?php foreach ($workspaceSubscriptions as $subscription): ?>
                            <tr>
                                <td><a href="workspace_admin.php?workspace_id=<?php echo (int) ($subscription['workspace_id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($subscription['workspace_name'] ?? 'Workspace')); ?></a><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($subscription['workspace_slug'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($subscription['plan_name'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($subscription['price_code'] ?? '')); ?></span></td>
                                <td><span class="billing-admin-pill"><?php echo htmlspecialchars((string) ($subscription['subscription_status'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($subscription['current_period_start'] ?? '')); ?><br><?php echo htmlspecialchars((string) ($subscription['current_period_end'] ?? 'open')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($subscription['scheduled_plan_name'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($subscription['scheduled_change_at'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($subscription['provider'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="ai-credit-operations" class="billing-admin-section">
            <h2>AI Credit Operations</h2>
            <p>Manually credit or debit a workspace wallet, run expiry processing, and keep every adjustment visible in the wallet ledger and billing audit.</p>
            <?php if ($selectedWorkspaceCreditTarget !== null): ?>
                <div class="billing-admin-alert billing-admin-alert--notice">
                    Selected workspace: <?php echo htmlspecialchars((string) ($selectedWorkspaceCreditTarget['name'] ?? 'Workspace')); ?>
                    (#<?php echo (int) ($selectedWorkspaceCreditTarget['id'] ?? 0); ?>)
                    - <?php echo number_format((int) ($selectedWorkspaceCreditTarget['available_tokens'] ?? 0)); ?> available AI Credits.
                </div>
            <?php endif; ?>
            <div class="billing-admin-grid">
                <article class="billing-admin-card">
                    <h3>Manual AI Credit Adjustment</h3>
                    <small>Use a positive delta to add AI Credits, or a negative delta to reverse/withdraw credits when support needs a correction.</small>
                    <form method="POST" class="billing-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="billing_action" value="wallet_adjustment">
                        <div class="billing-admin-fields">
                            <label>Workspace
                                <select name="workspace_id" required>
                                    <option value="">Choose workspace</option>
                                    <?php foreach ($workspaceCreditTargets as $targetWorkspace): ?>
                                        <?php
                                            $targetWorkspaceId = (int) ($targetWorkspace['id'] ?? 0);
                                            $targetLabel = sprintf(
                                                '#%d - %s (%s) - %s available',
                                                $targetWorkspaceId,
                                                (string) ($targetWorkspace['name'] ?? 'Workspace'),
                                                (string) ($targetWorkspace['slug'] ?? 'no-slug'),
                                                number_format((int) ($targetWorkspace['available_tokens'] ?? 0))
                                            );
                                        ?>
                                        <option value="<?php echo $targetWorkspaceId; ?>" <?php echo $targetWorkspaceId === $selectedWorkspaceId ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($targetLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>AI Credit delta <input name="credit_delta" type="number" required></label>
                        </div>
                        <label>Reason <input name="reason" placeholder="Required operator reason"></label>
                        <button class="billing-admin-button billing-admin-button--primary" type="submit">Record Adjustment</button>
                    </form>
                </article>
                <article class="billing-admin-card">
                    <h3>Run Credit Expiry</h3>
                    <form method="POST" class="billing-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="billing_action" value="expire_credits">
                        <label>Workspace
                            <select name="workspace_id">
                                <option value="">All workspaces</option>
                                <?php foreach ($workspaceCreditTargets as $targetWorkspace): ?>
                                    <?php
                                        $targetWorkspaceId = (int) ($targetWorkspace['id'] ?? 0);
                                        $targetLabel = sprintf(
                                            '#%d - %s (%s)',
                                            $targetWorkspaceId,
                                            (string) ($targetWorkspace['name'] ?? 'Workspace'),
                                            (string) ($targetWorkspace['slug'] ?? 'no-slug')
                                        );
                                    ?>
                                    <option value="<?php echo $targetWorkspaceId; ?>" <?php echo $targetWorkspaceId === $selectedWorkspaceId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($targetLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Reason <input name="reason" placeholder="Required operator reason"></label>
                        <button class="billing-admin-button billing-admin-button--primary" type="submit">Expire Unused Credits</button>
                    </form>
                </article>
            </div>
        </section>

        <section id="ledger" class="billing-admin-section">
            <h2>Credit Ledger</h2>
            <p>Lot-level availability and wallet ledger activity. Internal table names still use tokens; this hub exposes them as AI Credits.</p>
            <div class="billing-admin-table-wrap">
                <table class="billing-admin-table">
                    <thead><tr><th>Lot</th><th>Workspace</th><th>Source</th><th>Granted</th><th>Remaining</th><th>Reserved</th><th>Expires</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($creditLots as $lot): ?>
                            <tr>
                                <td>#<?php echo (int) ($lot['id'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lot['workspace_name'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($lot['workspace_slug'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($lot['source_type'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($lot['source_id'] ?? '')); ?></span></td>
                                <td><?php echo number_format((int) ($lot['granted_credits'] ?? 0)); ?></td>
                                <td><?php echo number_format((int) ($lot['remaining_credits'] ?? 0)); ?></td>
                                <td><?php echo number_format((int) ($lot['reserved_credits'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lot['expires_at'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lot['lot_status'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="billing-admin-table-wrap" style="margin-top:14px;">
                <table class="billing-admin-table">
                    <thead><tr><th>Ledger</th><th>Workspace</th><th>Type</th><th>AI Credits</th><th>Balance After</th><th>Reference</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($walletLedger as $entry): ?>
                            <tr>
                                <td>#<?php echo (int) ($entry['id'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars((string) ($entry['workspace_name'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($entry['workspace_slug'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($entry['entry_type'] ?? '')); ?></td>
                                <td><?php echo number_format((int) ($entry['token_delta'] ?? 0)); ?></td>
                                <td><?php echo number_format((int) ($entry['balance_after'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars((string) ($entry['reference_type'] ?? '')); ?><br><span class="billing-admin-muted"><?php echo htmlspecialchars((string) ($entry['reference_id'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($entry['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="audit" class="billing-admin-section">
            <h2>Billing Audit</h2>
            <p>Recent operator actions for pricing, subscriptions, wallet adjustments, and scheduled changes.</p>
            <div class="billing-admin-table-wrap">
                <table class="billing-admin-table">
                    <thead><tr><th>Action</th><th>Actor</th><th>Workspace</th><th>Reason</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($operatorAudit as $audit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($audit['action_type'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) (($audit['actor_email'] ?? null) ?: 'system')); ?></td>
                                <td><?php echo (int) ($audit['target_workspace_id'] ?? 0) ?: ''; ?></td>
                                <td><?php echo htmlspecialchars((string) ($audit['reason'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($audit['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
