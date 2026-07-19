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
use CRM\Services\OnboardingLifecycleNudgeService;
use CRM\Services\OperatorAuditService;
use CRM\Services\PlatformSecuritySettingsService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!PlatformWorkspaceOperationsService::isPlatformAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

$error = trim((string) ($_GET['error'] ?? ''));
$success = trim((string) ($_GET['notice'] ?? ''));
$isSuperAdmin = Authorization::isSuperAdmin($user);
$activeWorkspaceId = (int) (Session::get('active_workspace_id') ?? 0);
$securitySettingsService = new PlatformSecuritySettingsService();
$platformSecuritySettings = $securitySettingsService->getSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (isset($_POST['platform_security_action'])) {
        if (!$isSuperAdmin) {
            $error = 'Only Super Admin can update platform security settings.';
        } else {
            try {
                $require2fa = !empty($_POST['require_platform_workspace_2fa']) || !empty($_POST['require_superadmin_workspace_2fa']);
                $securitySettingsService->setRequirePlatformWorkspace2FA($require2fa, (int) ($user['id'] ?? 0));
                (new OperatorAuditService())->log(
                    'platform_security_settings_updated',
                    (int) ($user['id'] ?? 0),
                    null,
                    $require2fa ? 'Require platform admin 2FA for workspace login enabled.' : 'Require platform admin 2FA for workspace login disabled.',
                    ['require_platform_workspace_2fa' => $require2fa]
                );
                header('Location: workspaces.php?notice=' . rawurlencode('Platform security settings updated.'));
                exit;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$onboardingFilter = trim((string) ($_GET['onboarding'] ?? ''));
$where = [];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(w.name LIKE ?
        OR w.slug LIKE ?
        OR w.status LIKE ?
        OR w.plan_status LIKE ?
        OR latest_sub.subscription_status LIKE ?
        OR EXISTS (
            SELECT 1
            FROM workspace_memberships search_wm
            JOIN users search_u ON search_u.id = search_wm.user_id
            WHERE search_wm.workspace_id = w.id
              AND search_wm.membership_status = 'active'
              AND search_u.email LIKE ?
        ))";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$rows = Database::query(
    "SELECT
        w.id,
        w.name,
        w.slug,
        w.status,
        w.plan_status,
        w.created_at,
        COALESCE(owner_user.id, fallback_owner.id) AS owner_user_id,
        COALESCE(owner_user.email, fallback_owner.email) AS owner_email,
        COALESCE(member_counts.active_member_count, 0) AS member_count,
        COALESCE(wallet.token_balance, 0) AS token_balance,
        GREATEST(COALESCE(wallet.token_balance, 0) - COALESCE(wallet.reserved_tokens, 0), 0) AS available_tokens,
        latest_sub.subscription_status,
        latest_action.action_type AS last_operator_action,
        latest_action.created_at AS last_operator_action_at
     FROM workspaces w
     LEFT JOIN workspace_wallets wallet ON wallet.workspace_id = w.id
     LEFT JOIN workspace_subscriptions latest_sub
        ON latest_sub.id = (
            SELECT ws.id
            FROM workspace_subscriptions ws
            WHERE ws.workspace_id = w.id
            ORDER BY ws.id DESC
            LIMIT 1
        )
     LEFT JOIN workspace_memberships owner_membership
        ON owner_membership.id = (
            SELECT wm.id
            FROM workspace_memberships wm
            WHERE wm.workspace_id = w.id
              AND wm.membership_status = 'active'
              AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
            ORDER BY wm.is_owner DESC, wm.id ASC
            LIMIT 1
        )
     LEFT JOIN users owner_user ON owner_user.id = owner_membership.user_id
     LEFT JOIN workspace_memberships fallback_membership
        ON fallback_membership.id = (
            SELECT wm.id
            FROM workspace_memberships wm
            WHERE wm.workspace_id = w.id
              AND wm.membership_status = 'active'
            ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), wm.id ASC
            LIMIT 1
        )
     LEFT JOIN users fallback_owner ON fallback_owner.id = fallback_membership.user_id
     LEFT JOIN (
        SELECT workspace_id, COUNT(*) AS active_member_count
        FROM workspace_memberships
        WHERE membership_status = 'active'
        GROUP BY workspace_id
     ) member_counts ON member_counts.workspace_id = w.id
     LEFT JOIN operator_audit_log latest_action
        ON latest_action.id = (
            SELECT oal.id
            FROM operator_audit_log oal
            WHERE oal.target_workspace_id = w.id
            ORDER BY oal.id DESC
            LIMIT 1
        )
     " . ($where !== [] ? 'WHERE ' . implode(' AND ', $where) : '') . "
     ORDER BY w.created_at DESC, w.id DESC
     LIMIT 250",
    $params
);
$onboardingNudges = new OnboardingLifecycleNudgeService();
$rows = $onboardingNudges->decorateDirectoryRows($rows);
if ($onboardingFilter === 'stuck') {
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return !empty($row['onboarding_summary']['is_stuck']);
    }));
} elseif ($onboardingFilter === 'incomplete') {
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return (string) ($row['onboarding_summary']['status'] ?? '') !== 'completed';
    }));
} elseif ($onboardingFilter === 'low_operational') {
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return (int) ($row['onboarding_summary']['operational_score'] ?? 0) < 80;
    }));
}

$pageTitle = 'Workspaces - ' . brandProductName();
ob_start();
?>
<style>
    .page-content:has(.workspace-directory-shell) {
        padding-top: 1rem;
        overflow-x: hidden;
    }

    .page-content > .container:has(.workspace-directory-shell) {
        box-sizing: border-box;
        max-width: min(1900px, calc(100vw - 24px));
        padding-left: 12px;
        padding-right: 12px;
        overflow-x: hidden;
    }

    .workspace-directory-shell {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-sizing: border-box;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
        margin: 0 auto 1.5rem;
        max-width: 100%;
        padding: 14px;
        width: 100%;
        overflow: hidden;
    }

    .workspace-directory-header {
        margin-bottom: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: end;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .workspace-directory-header h1 {
        margin-bottom: .35rem;
    }

    .workspace-directory-panel {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 12px;
    }

    .workspace-directory-table-wrap {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-sizing: border-box;
        max-width: 100%;
        overflow-x: hidden;
        overflow-y: visible;
        scrollbar-gutter: stable;
    }

    .workspace-directory-table {
        table-layout: fixed;
        width: 100%;
        border-collapse: collapse;
        min-width: 0;
    }

    .workspace-directory-table th,
    .workspace-directory-table td {
        padding: 10px 9px;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
        overflow-wrap: anywhere;
        word-break: normal;
    }

    .workspace-directory-table th {
        background: #f8fafc;
        color: var(--midnight-black);
        font-size: .82rem;
        text-align: left;
    }

    .workspace-directory-table td {
        font-size: .88rem;
        line-height: 1.35;
    }

    .workspace-directory-table .numeric {
        text-align: right;
    }

    .workspace-directory-actions {
        display: flex;
        gap: .35rem;
        justify-content: flex-end;
        align-items: center;
        flex-wrap: wrap;
        white-space: normal;
    }

    .workspace-directory-actions .btn-premium-primary,
    .workspace-directory-actions .btn-premium-secondary,
    .workspace-directory-actions .workspace-directory-danger-button {
        min-height: 0;
        padding: .42rem .55rem;
        font-size: .78rem;
        line-height: 1.15;
    }

    .workspace-directory-actions .btn-premium-secondary {
        max-width: 100%;
        text-align: center;
        white-space: normal;
    }

    .workspace-directory-actions form {
        max-width: 100%;
    }

    .workspace-directory-row-delete {
        display: flex;
        gap: .35rem;
        align-items: center;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .workspace-directory-row-delete input[type="text"] {
        width: min(100%, 132px);
        padding: .42rem .5rem;
        border: 1px solid #fca5a5;
        border-radius: 8px;
        font-size: .78rem;
    }

    .workspace-directory-bulk-delete {
        display: flex;
        align-items: end;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .workspace-directory-danger-input {
        padding: .65rem .75rem;
        border: 1px solid #fca5a5;
        border-radius: 8px;
        min-width: 180px;
    }

    .workspace-directory-danger-button {
        background: #dc2626;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: .7rem 1rem;
        font-weight: 700;
        cursor: pointer;
    }

    .workspace-directory-danger-button:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    @media (max-width: 1399px) {
        .workspace-directory-table-wrap {
            overflow-x: auto;
        }

        .workspace-directory-table {
            min-width: 1320px;
        }
    }

    @media (max-width: 760px) {
        .page-content > .container:has(.workspace-directory-shell) {
            max-width: 100%;
            padding-left: 8px;
            padding-right: 8px;
        }

        .workspace-directory-shell {
            border-radius: 10px;
            padding: 10px;
        }

        .workspace-directory-header {
            align-items: start;
        }
    }
</style>

<div class="workspace-directory-shell">
    <div class="workspace-directory-header">
        <div>
            <h1 style="margin-bottom:var(--spacing-sm);color:var(--midnight-black);">Workspaces</h1>
            <p style="color:var(--charcoal-grey);">Platform-level directory for tenant reporting, operations, and audited workspace login.</p>
        </div>
        <a href="workspace_provision.php" class="btn-premium-primary" style="text-decoration:none;">Provision Workspace</a>
    </div>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:16px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
            <section class="workspace-directory-panel" style="border-color:#bfdbfe;background:#eff6ff;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 .35rem;color:#1e3a8a;font-size:1.1rem;">Clarity Ops Guide</h2>
                        <p style="margin:0;color:#1e40af;font-size:.92rem;">Use Super Admin Clarity for platform billing risk, operator audits, security settings, and system follow-up tasks.</p>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <button type="button" class="btn-premium-primary" data-clarity-ops-prompt="What needs my attention today?">Ask Clarity</button>
                        <button type="button" class="btn-premium-secondary" data-clarity-ops-prompt="Create platform follow-up tasks">Generate Tasks</button>
                    </div>
                </div>
            </section>

            <section class="workspace-directory-panel">
                <form method="POST" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <input type="hidden" name="platform_security_action" value="update_platform_workspace_2fa">
                    <div>
                        <h2 style="margin:0 0 .35rem;color:var(--midnight-black);font-size:1.1rem;">Platform Workspace 2FA</h2>
                        <p style="margin:0;color:var(--charcoal-grey);font-size:.92rem;">Require an authenticator app code before platform admins start audited workspace login.</p>
                    </div>
                    <label style="display:inline-flex;align-items:center;gap:.65rem;color:var(--midnight-black);font-weight:700;">
                        <input type="checkbox" name="require_platform_workspace_2fa" value="1" <?php echo !empty($platformSecuritySettings['require_platform_workspace_2fa']) ? 'checked' : ''; ?>>
                        <span>Require 2FA on platform workspace login</span>
                    </label>
                    <button type="submit" class="btn-premium-secondary">Save Setting</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="workspace-directory-panel">
            <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
                <label for="workspace-search" style="font-weight:700;color:var(--midnight-black);">Search</label>
                <input id="workspace-search" type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Workspace, slug, owner, status, plan"
                       style="flex:1 1 280px;min-width:0;padding:.75rem;border:1px solid var(--border-color);border-radius:10px;">
                <select name="onboarding" style="padding:.75rem;border:1px solid var(--border-color);border-radius:10px;">
                    <option value="" <?php echo $onboardingFilter === '' ? 'selected' : ''; ?>>All onboarding</option>
                    <option value="stuck" <?php echo $onboardingFilter === 'stuck' ? 'selected' : ''; ?>>Stuck onboarding</option>
                    <option value="incomplete" <?php echo $onboardingFilter === 'incomplete' ? 'selected' : ''; ?>>Incomplete</option>
                    <option value="low_operational" <?php echo $onboardingFilter === 'low_operational' ? 'selected' : ''; ?>>Below 80% operational</option>
                </select>
                <button type="submit" class="btn-premium-primary">Search</button>
                <?php if ($search !== '' || $onboardingFilter !== ''): ?>
                    <a href="workspaces.php" class="btn-premium-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </section>

        <?php if ($isSuperAdmin): ?>
            <section class="workspace-directory-panel" style="border-color:#fecaca;background:#fff7f7;">
                <form id="workspace-bulk-delete-form" method="POST" action="workspace_delete.php" class="workspace-directory-bulk-delete">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <div style="flex:1 1 260px;">
                        <h2 style="margin:0 0 .35rem;color:#991b1b;font-size:1.05rem;">Delete selected workspaces</h2>
                        <p style="margin:0;color:#7f1d1d;font-size:.88rem;">Hard-deletes selected workspace data. Audit history is preserved.</p>
                    </div>
                    <label style="display:grid;gap:.3rem;color:#7f1d1d;font-size:.82rem;font-weight:700;">
                        Reason
                        <input class="workspace-directory-danger-input" type="text" name="reason" placeholder="Reason for deletion">
                    </label>
                    <label style="display:grid;gap:.3rem;color:#7f1d1d;font-size:.82rem;font-weight:700;">
                        Type <code>DELETE WORKSPACES</code>
                        <input class="workspace-directory-danger-input" type="text" name="delete_confirm_text" placeholder="DELETE WORKSPACES" autocomplete="off" required>
                    </label>
                    <button type="submit" class="workspace-directory-danger-button" data-bulk-delete-button disabled>Delete Selected</button>
                </form>
            </section>
        <?php endif; ?>

        <div class="workspace-directory-table-wrap">
            <table class="workspace-directory-table">
                <colgroup>
                    <?php if ($isSuperAdmin): ?>
                        <col style="width:2.5%;">
                    <?php endif; ?>
                    <col style="width:<?php echo $isSuperAdmin ? '8%' : '9%'; ?>;">
                    <col style="width:<?php echo $isSuperAdmin ? '15%' : '16%'; ?>;">
                    <col style="width:5%;">
                    <col style="width:5.5%;">
                    <col style="width:<?php echo $isSuperAdmin ? '7.5%' : '8%'; ?>;">
                    <col style="width:4.5%;">
                    <col style="width:<?php echo $isSuperAdmin ? '9.5%' : '10%'; ?>;">
                    <col style="width:<?php echo $isSuperAdmin ? '5.5%' : '6%'; ?>;">
                    <col style="width:4%;">
                    <col style="width:4%;">
                    <col style="width:4%;">
                    <col style="width:<?php echo $isSuperAdmin ? '5.5%' : '6%'; ?>;">
                    <col style="width:<?php echo $isSuperAdmin ? '5.5%' : '6%'; ?>;">
                    <col style="width:<?php echo $isSuperAdmin ? '5%' : '5.5%'; ?>;">
                    <col style="width:<?php echo $isSuperAdmin ? '9%' : '6.5%'; ?>;">
                </colgroup>
                <thead>
                    <tr>
                        <?php if ($isSuperAdmin): ?>
                            <th style="width:44px;"><input type="checkbox" data-workspace-select-all aria-label="Select all workspaces"></th>
                        <?php endif; ?>
                        <th>Workspace</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Plan</th>
                        <th>Onboarding</th>
                        <th class="numeric">Operational</th>
                        <th>Next Setup Action</th>
                        <th>Last Nudge</th>
                        <th class="numeric">Members</th>
                        <th class="numeric">Tokens</th>
                        <th class="numeric">Available</th>
                        <th>Created</th>
                        <th>Last Operator Action</th>
                        <th class="numeric">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?php echo $isSuperAdmin ? 16 : 15; ?>" style="padding:18px;color:var(--charcoal-grey);">No workspaces match this search.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $rowWorkspaceId = (int) $row['id'];
                            $isProtectedDelete = $rowWorkspaceId === 1 || (string) ($row['slug'] ?? '') === 'default' || $rowWorkspaceId === $activeWorkspaceId;
                            $onboardingSummary = (array) ($row['onboarding_summary'] ?? []);
                            $nextAction = (array) ($onboardingSummary['next_action'] ?? []);
                            $lastNudge = (array) ($onboardingSummary['last_nudge'] ?? []);
                        ?>
                        <tr>
                            <?php if ($isSuperAdmin): ?>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="workspace_ids[]"
                                        value="<?php echo $rowWorkspaceId; ?>"
                                        form="workspace-bulk-delete-form"
                                        data-workspace-delete-checkbox
                                        aria-label="Select <?php echo htmlspecialchars((string) $row['name']); ?>"
                                        <?php echo $isProtectedDelete ? 'disabled' : ''; ?>
                                    >
                                </td>
                            <?php endif; ?>
                            <td>
                                <strong style="display:block;color:var(--midnight-black);"><?php echo htmlspecialchars((string) $row['name']); ?></strong>
                                <span style="color:var(--charcoal-grey);font-size:.88rem;"><?php echo htmlspecialchars((string) $row['slug']); ?></span>
                                <?php if ($isProtectedDelete): ?>
                                    <span style="display:block;color:#991b1b;font-size:.78rem;">Protected from deletion</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--charcoal-grey);"><?php echo htmlspecialchars((string) (($row['owner_email'] ?? null) ?: 'n/a')); ?></td>
                            <td><?php echo htmlspecialchars((string) $row['status']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string) (($row['subscription_status'] ?? null) ?: $row['plan_status'])); ?></strong>
                                <span style="display:block;color:var(--charcoal-grey);font-size:.85rem;"><?php echo htmlspecialchars((string) $row['plan_status']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($onboardingSummary['status'] ?? 'unknown')))); ?></strong>
                                <span style="display:block;color:var(--charcoal-grey);font-size:.85rem;">
                                    Step <?php echo (int) ($onboardingSummary['current_step'] ?? 1); ?><?php echo !empty($onboardingSummary['quick_start']) ? ' · Quick Start' : ''; ?>
                                </span>
                                <?php if (!empty($onboardingSummary['is_stuck'])): ?>
                                    <span style="display:block;color:#b45309;font-size:.8rem;font-weight:700;">Needs recovery</span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?php echo (int) ($onboardingSummary['operational_score'] ?? 0); ?>%</td>
                            <td style="color:var(--charcoal-grey);">
                                <strong style="color:var(--midnight-black);"><?php echo htmlspecialchars((string) (($nextAction['title'] ?? null) ?: 'Review setup')); ?></strong>
                                <?php if (!empty($nextAction['description'])): ?>
                                    <span style="display:block;font-size:.82rem;"><?php echo htmlspecialchars((string) $nextAction['description']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--charcoal-grey);">
                                <?php echo htmlspecialchars((string) (($lastNudge['status'] ?? null) ?: 'none')); ?>
                                <?php if (!empty($lastNudge['created_at'])): ?>
                                    <span style="display:block;font-size:.82rem;"><?php echo htmlspecialchars((string) $lastNudge['created_at']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?php echo number_format((int) $row['member_count']); ?></td>
                            <td class="numeric"><?php echo number_format((int) $row['token_balance']); ?></td>
                            <td class="numeric"><?php echo number_format((int) $row['available_tokens']); ?></td>
                            <td style="color:var(--charcoal-grey);"><?php echo htmlspecialchars((string) (($row['created_at'] ?? null) ?: 'n/a')); ?></td>
                            <td style="color:var(--charcoal-grey);">
                                <?php echo htmlspecialchars((string) (($row['last_operator_action'] ?? null) ?: 'n/a')); ?>
                                <?php if (!empty($row['last_operator_action_at'])): ?>
                                    <span style="display:block;font-size:.82rem;"><?php echo htmlspecialchars((string) $row['last_operator_action_at']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric">
                                <div class="workspace-directory-actions">
                                    <form method="POST" action="workspace_login.php" style="display:inline-flex;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="workspace_id" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" class="btn-premium-primary">Login</button>
                                    </form>
                                    <a href="workspace_admin.php?workspace_id=<?php echo (int) $row['id']; ?>" class="btn-premium-secondary">Open Workspace Ops</a>
                                    <?php if ($isSuperAdmin): ?>
                                        <a href="super_admin_billing.php?workspace_id=<?php echo (int) $row['id']; ?>#ai-credit-operations" class="btn-premium-secondary">Add AI Credits</a>
                                    <?php endif; ?>
                                    <?php if ($isSuperAdmin): ?>
                                        <form method="POST" action="workspace_delete.php" class="workspace-directory-row-delete">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                            <input type="hidden" name="workspace_ids[]" value="<?php echo $rowWorkspaceId; ?>">
                                            <input type="hidden" name="reason" value="Super Admin single workspace deletion from workspace directory.">
                                            <input type="text" name="delete_confirm_text" placeholder="DELETE WORKSPACE" autocomplete="off" required>
                                            <button type="submit" class="workspace-directory-danger-button" <?php echo $isProtectedDelete ? 'disabled' : ''; ?>>Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>
<script>
(function () {
    const selectAll = document.querySelector('[data-workspace-select-all]');
    const checkboxes = Array.from(document.querySelectorAll('[data-workspace-delete-checkbox]'));
    const bulkButton = document.querySelector('[data-bulk-delete-button]');

    function updateBulkState() {
        const enabled = checkboxes.filter((box) => !box.disabled);
        const selected = enabled.filter((box) => box.checked);
        if (bulkButton) {
            bulkButton.disabled = selected.length === 0;
        }
        if (selectAll) {
            selectAll.checked = enabled.length > 0 && selected.length === enabled.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach((box) => {
                if (!box.disabled) {
                    box.checked = selectAll.checked;
                }
            });
            updateBulkState();
        });
    }

    checkboxes.forEach((box) => box.addEventListener('change', updateBulkState));
    updateBulkState();

    document.querySelectorAll('[data-clarity-ops-prompt]').forEach((button) => {
        button.addEventListener('click', function () {
            const prompt = button.getAttribute('data-clarity-ops-prompt') || '';
            if (window.ClarityChatBubble && typeof window.ClarityChatBubble.ask === 'function') {
                window.ClarityChatBubble.ask(prompt, 'superadmin_ops');
                return;
            }

            const bubble = document.getElementById('chat-bubble-btn');
            const input = document.getElementById('chat-bubble-input');
            if (bubble) {
                bubble.click();
            }
            if (input) {
                input.value = prompt;
                input.focus();
            }
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
