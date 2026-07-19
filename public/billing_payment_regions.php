<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim((string) $line), '#') === 0 || strpos((string) $line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', (string) $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\BillingPaymentMarketService;
use CRM\Services\OperatorAuditService;
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

$service = new BillingPaymentMarketService();

function billingPaymentRegionsRedirect(array $params = []): void
{
    header('Location: billing_payment_regions.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        billingPaymentRegionsRedirect(['error' => 'Invalid security token. Please try again.']);
    }

    $action = trim((string) ($_POST['market_action'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        if ($reason === '') {
            throw new RuntimeException('An operator reason is required.');
        }

        if ($action === 'save_rule') {
            $rule = $service->saveRule([
                'scope_type' => $_POST['scope_type'] ?? '',
                'scope_code' => $_POST['scope_code'] ?? '',
                'scope_name' => $_POST['scope_name'] ?? '',
                'payment_card_enabled' => isset($_POST['payment_card_enabled']),
                'payment_mpesa_enabled' => isset($_POST['payment_mpesa_enabled']),
                'payment_bank_transfer_enabled' => isset($_POST['payment_bank_transfer_enabled']),
                'is_active' => isset($_POST['is_active']),
            ], (int) ($user['id'] ?? 0));
            (new OperatorAuditService())->log(
                'billing_payment_market_rule_updated',
                (int) ($user['id'] ?? 0),
                null,
                $reason,
                [
                    'scope_type' => $rule['scope_type'] ?? null,
                    'scope_code' => $rule['scope_code'] ?? null,
                    'scope_name' => $rule['scope_name'] ?? null,
                    'card' => !empty($rule['payment_card_enabled']),
                    'mpesa' => !empty($rule['payment_mpesa_enabled']),
                    'bank_transfer' => !empty($rule['payment_bank_transfer_enabled']),
                    'is_active' => !empty($rule['is_active']),
                ]
            );
            billingPaymentRegionsRedirect(['notice' => 'Regional payment rule saved.']);
        }

        if ($action === 'save_assignment') {
            $workspaceId = (int) ($_POST['workspace_id'] ?? 0);
            $assignment = $service->saveWorkspaceAssignment(
                $workspaceId,
                (string) ($_POST['country_code'] ?? ''),
                (string) ($_POST['region_code'] ?? ''),
                (int) ($user['id'] ?? 0)
            );
            (new OperatorAuditService())->log(
                'workspace_billing_market_assigned',
                (int) ($user['id'] ?? 0),
                $workspaceId,
                $reason,
                [
                    'country_code' => $assignment['country_code'] ?? null,
                    'region_code' => $assignment['region_code'] ?? null,
                ]
            );
            billingPaymentRegionsRedirect(['notice' => 'Workspace billing market assigned.']);
        }

        if ($action === 'clear_assignment') {
            $workspaceId = (int) ($_POST['workspace_id'] ?? 0);
            if (!$service->clearWorkspaceAssignment($workspaceId)) {
                throw new RuntimeException('Workspace market assignment was not found.');
            }
            (new OperatorAuditService())->log(
                'workspace_billing_market_cleared',
                (int) ($user['id'] ?? 0),
                $workspaceId,
                $reason,
                ['fallback' => 'currency_inference']
            );
            billingPaymentRegionsRedirect(['notice' => 'Workspace billing market cleared; currency inference is active again.']);
        }

        throw new RuntimeException('Unsupported regional payment action.');
    } catch (Throwable $e) {
        billingPaymentRegionsRedirect(['error' => $e->getMessage()]);
    }
}

$rules = $service->listRules();
$assignments = $service->listAssignments();
$workspaces = $service->listWorkspaces();
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$csrf = Security::getCsrfToken();
$pageTitle = 'Regional Payment Controls - ' . brandProductName();

ob_start();
?>
<style>
    .payment-market-shell { max-width: 1180px; margin: 0 auto 2rem; }
    .payment-market-header { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap; margin-bottom:1rem; }
    .payment-market-header h1 { margin:0 0 .35rem; }
    .payment-market-header p { margin:0; color:#64748b; max-width:760px; }
    .payment-market-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem; margin-bottom:1rem; box-shadow:0 6px 18px rgba(15,23,42,.05); }
    .payment-market-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:.8rem; }
    .payment-market-form { display:grid; gap:.9rem; }
    .payment-market-form label { display:grid; gap:.35rem; color:#334155; font-weight:700; }
    .payment-market-form input, .payment-market-form select { width:100%; box-sizing:border-box; padding:.68rem .75rem; border:1px solid #cbd5e1; border-radius:9px; background:#fff; }
    .payment-market-methods { display:flex; flex-wrap:wrap; gap:.7rem 1rem; }
    .payment-market-methods label { display:flex; align-items:center; gap:.45rem; font-weight:650; }
    .payment-market-methods input { width:auto; }
    .payment-market-list { display:grid; gap:.75rem; }
    .payment-market-rule { border:1px solid #e2e8f0; border-radius:11px; padding:.85rem; background:#f8fafc; }
    .payment-market-rule h3 { margin:0 0 .65rem; font-size:1rem; }
    .payment-market-table-wrap { overflow-x:auto; }
    .payment-market-table { width:100%; border-collapse:collapse; min-width:680px; }
    .payment-market-table th, .payment-market-table td { text-align:left; padding:.7rem; border-bottom:1px solid #e2e8f0; }
    .payment-market-table th { color:#475569; font-size:.82rem; }
    .payment-market-alert { padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
    .payment-market-alert.success { color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; }
    .payment-market-alert.error { color:#991b1b; background:#fef2f2; border:1px solid #fecaca; }
    .payment-market-note { color:#64748b; font-size:.9rem; }
    @media (max-width: 700px) { .payment-market-panel { padding:.85rem; } .payment-market-header .btn-premium-secondary { width:100%; text-align:center; } }
</style>
<div class="payment-market-shell">
    <header class="payment-market-header">
        <div>
            <h1>Regional Payment Controls</h1>
            <p>Global payment switches remain the platform ceiling. An active country rule overrides its region rule and can only restrict methods further; provider readiness and currency support still apply.</p>
        </div>
        <a class="btn-premium-secondary" href="settings.php?tab=package_settings&amp;package_section=payment_methods">Back to Payment Methods</a>
    </header>

    <?php if ($notice !== ''): ?><div class="payment-market-alert success" role="status"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="payment-market-alert error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="payment-market-panel">
        <h2>Create or update a market rule</h2>
        <p class="payment-market-note">Use ISO country codes such as KE or NG. Region codes are stable internal labels such as EAST_AFRICA.</p>
        <form method="POST" class="payment-market-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="market_action" value="save_rule">
            <div class="payment-market-grid">
                <label>Scope
                    <select name="scope_type" required><option value="country">Country</option><option value="region">Region</option></select>
                </label>
                <label>Code <input name="scope_code" maxlength="32" placeholder="KE or EAST_AFRICA" required></label>
                <label>Name <input name="scope_name" maxlength="120" placeholder="Kenya or East Africa" required></label>
            </div>
            <div class="payment-market-methods">
                <label><input type="checkbox" name="payment_card_enabled" value="1" checked> Card</label>
                <label><input type="checkbox" name="payment_mpesa_enabled" value="1" checked> M-Pesa</label>
                <label><input type="checkbox" name="payment_bank_transfer_enabled" value="1" checked> Bank transfer</label>
                <label><input type="checkbox" name="is_active" value="1" checked> Rule active</label>
            </div>
            <label>Operator reason <input name="reason" maxlength="255" placeholder="Why this market policy is changing" required></label>
            <div><button class="btn-premium-primary" type="submit">Save Market Rule</button></div>
        </form>
    </section>

    <section class="payment-market-panel">
        <h2>Current market rules</h2>
        <?php if ($rules === []): ?>
            <p class="payment-market-note">No regional restrictions are active. Global switches, provider readiness, and currency support determine availability.</p>
        <?php else: ?>
            <div class="payment-market-list">
                <?php foreach ($rules as $rule): ?>
                    <form method="POST" class="payment-market-form payment-market-rule">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="market_action" value="save_rule">
                        <input type="hidden" name="scope_type" value="<?php echo htmlspecialchars((string) $rule['scope_type']); ?>">
                        <input type="hidden" name="scope_code" value="<?php echo htmlspecialchars((string) $rule['scope_code']); ?>">
                        <h3><?php echo htmlspecialchars(ucfirst((string) $rule['scope_type']) . ': ' . (string) $rule['scope_name'] . ' (' . (string) $rule['scope_code'] . ')'); ?></h3>
                        <div class="payment-market-grid">
                            <label>Name <input name="scope_name" maxlength="120" value="<?php echo htmlspecialchars((string) $rule['scope_name']); ?>" required></label>
                            <label>Operator reason <input name="reason" maxlength="255" placeholder="Why this rule is changing" required></label>
                        </div>
                        <div class="payment-market-methods">
                            <label><input type="checkbox" name="payment_card_enabled" value="1" <?php echo !empty($rule['payment_card_enabled']) ? 'checked' : ''; ?>> Card</label>
                            <label><input type="checkbox" name="payment_mpesa_enabled" value="1" <?php echo !empty($rule['payment_mpesa_enabled']) ? 'checked' : ''; ?>> M-Pesa</label>
                            <label><input type="checkbox" name="payment_bank_transfer_enabled" value="1" <?php echo !empty($rule['payment_bank_transfer_enabled']) ? 'checked' : ''; ?>> Bank transfer</label>
                            <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($rule['is_active']) ? 'checked' : ''; ?>> Rule active</label>
                        </div>
                        <div><button class="btn-premium-secondary" type="submit">Update Rule</button></div>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="payment-market-panel">
        <h2>Assign a workspace market</h2>
        <p class="payment-market-note">Explicit assignments take priority over currency inference. Country rules take priority over region rules.</p>
        <form method="POST" class="payment-market-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="market_action" value="save_assignment">
            <div class="payment-market-grid">
                <label>Workspace
                    <select name="workspace_id" required>
                        <option value="">Choose workspace</option>
                        <?php foreach ($workspaces as $workspace): ?>
                            <option value="<?php echo (int) $workspace['id']; ?>"><?php echo htmlspecialchars((string) $workspace['name'] . ' (' . (string) $workspace['slug'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Country code <input name="country_code" maxlength="2" placeholder="KE"></label>
                <label>Region code <input name="region_code" maxlength="32" placeholder="EAST_AFRICA"></label>
            </div>
            <label>Operator reason <input name="reason" maxlength="255" placeholder="Why this workspace market is changing" required></label>
            <div><button class="btn-premium-primary" type="submit">Save Workspace Market</button></div>
        </form>
    </section>

    <section class="payment-market-panel">
        <h2>Workspace assignments</h2>
        <?php if ($assignments === []): ?>
            <p class="payment-market-note">No explicit assignments. Billing currency is used to infer the market where possible.</p>
        <?php else: ?>
            <div class="payment-market-table-wrap">
                <table class="payment-market-table">
                    <thead><tr><th>Workspace</th><th>Country</th><th>Region</th><th>Updated by</th><th>Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string) $assignment['workspace_name']); ?></strong><br><span class="payment-market-note"><?php echo htmlspecialchars((string) $assignment['workspace_slug']); ?></span></td>
                            <td><?php echo htmlspecialchars((string) (($assignment['country_code'] ?? null) ?: '—')); ?></td>
                            <td><?php echo htmlspecialchars((string) (($assignment['region_code'] ?? null) ?: '—')); ?></td>
                            <td><?php echo htmlspecialchars((string) (($assignment['updated_by_email'] ?? null) ?: 'System')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($assignment['updated_at'] ?? '')); ?></td>
                            <td>
                                <form method="POST" class="payment-market-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="market_action" value="clear_assignment">
                                    <input type="hidden" name="workspace_id" value="<?php echo (int) $assignment['workspace_id']; ?>">
                                    <label>Reason <input name="reason" maxlength="255" placeholder="Why this assignment is clearing" required></label>
                                    <button class="btn-premium-secondary" type="submit">Use Currency Inference</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
