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
use CRM\Services\DefaultWorkspaceOwnerSupportService;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\OwnerHelpOfferingService;
use CRM\Services\OwnerHelpQuoteService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user) || !WorkspaceContext::isDefaultWorkspace((int) (WorkspaceContext::currentWorkspaceId() ?? 0))) {
    header('Location: dashboard.php');
    exit;
}

$service = new DefaultWorkspaceOwnerSupportService();
$offeringService = new OwnerHelpOfferingService();
$expertService = new OwnerHelpExpertService();
$quoteService = new OwnerHelpQuoteService($service);
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

function ownerSupportAdminRedirect(array $params = []): void
{
    header('Location: owner_support_admin.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

/**
 * @return array<int,array<string,mixed>>
 */
function ownerSupportAdminQuoteItemsFromPost(): array
{
    $labels = (array) ($_POST['quote_item_label'] ?? []);
    $descriptions = (array) ($_POST['quote_item_description'] ?? []);
    $quantities = (array) ($_POST['quote_item_quantity'] ?? []);
    $prices = (array) ($_POST['quote_item_unit_price'] ?? []);
    $items = [];
    foreach ($labels as $index => $label) {
        $items[] = [
            'item_label' => (string) $label,
            'item_description' => (string) ($descriptions[$index] ?? ''),
            'quantity' => (float) ($quantities[$index] ?? 1),
            'unit_price' => (float) ($prices[$index] ?? 0),
            'sort_order' => ($index + 1) * 10,
        ];
    }
    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $caseId = (int) ($_POST['event_id'] ?? 0);
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security check failed. Refresh the page and try again.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'reply_operator') {
            $service->addOperatorReply($user, $caseId, (string) ($_POST['message'] ?? ''));
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => 'Reply sent.']);
        }
        if ($action === 'transition_case') {
            $service->transitionCase(
                $user,
                $caseId,
                (string) ($_POST['case_status'] ?? ''),
                (string) ($_POST['reason'] ?? 'Updated from Owner Help admin.'),
                (string) ($_POST['resolution_summary'] ?? '')
            );
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => 'Case status updated.']);
        }
        if ($action === 'assign_case') {
            $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
            $service->assignCase($user, $caseId, $assignedUserId > 0 ? $assignedUserId : null);
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => 'Case assignment updated.']);
        }
        if ($action === 'update_priority') {
            $service->updatePriority($user, $caseId, (string) ($_POST['priority'] ?? 'medium'));
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => 'Case priority updated.']);
        }
        if ($action === 'update_help_request') {
            $service->updateHelpRequest($user, $caseId, [
                'lane' => (string) ($_POST['lane'] ?? ''),
                'commercial_type' => (string) ($_POST['commercial_type'] ?? ''),
                'pricing_state' => (string) ($_POST['pricing_state'] ?? ''),
                'lifecycle_status' => (string) ($_POST['lifecycle_status'] ?? ''),
                'offering_id' => (int) ($_POST['offering_id'] ?? 0),
                'expert_profile_id' => (int) ($_POST['expert_profile_id'] ?? 0),
                'quote_notes' => (string) ($_POST['quote_notes'] ?? ''),
                'admin_notes' => (string) ($_POST['admin_notes'] ?? ''),
            ]);
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => 'Help request triage updated.']);
        }
        if ($action === 'save_quote') {
            $quote = $quoteService->saveDraft($user, $caseId, $_POST, ownerSupportAdminQuoteItemsFromPost());
            $noticeText = 'Quote draft saved.';
            if ((string) ($_POST['quote_submit'] ?? '') === 'send') {
                $quoteService->sendQuote($user, $caseId, (int) ($quote['id'] ?? 0));
                $noticeText = 'Quote sent to owner.';
            }
            ownerSupportAdminRedirect(['event_id' => $caseId, 'notice' => $noticeText]);
        }
        throw new RuntimeException('Unsupported support action.');
    } catch (Throwable $e) {
        $params = ['error' => $e->getMessage()];
        if ($caseId > 0) {
            $params['event_id'] = $caseId;
        }
        ownerSupportAdminRedirect($params);
    }
}

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'owner_category' => trim((string) ($_GET['owner_category'] ?? '')),
    'lane' => trim((string) ($_GET['lane'] ?? '')),
    'commercial_type' => trim((string) ($_GET['commercial_type'] ?? '')),
    'pricing_state' => trim((string) ($_GET['pricing_state'] ?? '')),
    'lifecycle_status' => trim((string) ($_GET['lifecycle_status'] ?? '')),
    'expert_profile_id' => (int) ($_GET['expert_profile_id'] ?? 0),
];
$cases = [];
$selectedCase = null;
$messages = [];
$members = [];
$offerings = [];
$experts = [];
$quotes = [];
try {
    $offerings = $offeringService->activeOfferings();
    $experts = $expertService->activeExperts();
    $cases = $service->listAdminCases($user, $filters, 100);
    $selectedCaseId = (int) ($_GET['event_id'] ?? 0);
    if ($selectedCaseId <= 0 && $cases !== []) {
        $selectedCaseId = (int) ($cases[0]['id'] ?? 0);
    }
    if ($selectedCaseId > 0) {
        $selectedCase = $service->adminCase($user, $selectedCaseId);
        $messages = $service->messagesForAdmin($user, $selectedCaseId);
        $quotes = $quoteService->quotesForAdminCase($user, $selectedCaseId);
    }
    $members = $service->defaultWorkspaceMembers();
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$csrfToken = Security::getCsrfToken();
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabel = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
$laneLabel = static fn(string $lane): string => match ($lane) {
    'billing_access' => 'Billing access',
    'account_access' => 'Account access',
    'setup_help' => 'Setup help',
    'installation_help' => 'Installation help',
    'strategy_mentor' => 'Strategy mentor',
    'account_manager' => 'Account manager',
    default => 'System issue',
};
$formatDate = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'Not yet';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('M j, Y H:i', $ts);
};
$formatDateOnly = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('Y-m-d', $ts);
};
$quoteStatusLabel = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
$formatMoney = static fn(string $currency, float $amount): string => trim($currency) . ' ' . number_format($amount, 2);

$pageTitle = 'Owner Support Admin - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .support-admin-page { background:#f6f8fb; min-height:calc(100vh - 80px); }
    .support-admin-shell { display:grid; grid-template-columns:minmax(300px, 420px) 1fr; gap:1rem; align-items:start; }
    .support-admin-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .support-admin-panel h2, .support-admin-panel h3 { margin:0; color:#0f172a; letter-spacing:0; }
    .support-admin-list { display:grid; gap:.55rem; margin-top:.75rem; }
    .support-admin-link { display:block; padding:.75rem; border:1px solid #e2e8f0; border-radius:8px; color:#0f172a; text-decoration:none; background:#fff; }
    .support-admin-link.active { border-color:#2563eb; background:#eff6ff; }
    .support-admin-meta { display:flex; gap:.4rem; flex-wrap:wrap; color:#64748b; font-size:.82rem; margin-top:.35rem; }
    .support-admin-chip-row { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
    .support-admin-chip { display:inline-flex; align-items:center; border:1px solid #dbe4f0; border-radius:999px; background:#f8fafc; color:#334155; padding:.24rem .55rem; font-size:.76rem; font-weight:800; line-height:1.2; }
    .support-admin-chip.is-free { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
    .support-admin-chip.is-paid { border-color:#fed7aa; background:#fff7ed; color:#9a3412; }
    .support-admin-thread { display:grid; gap:.75rem; margin:1rem 0; }
    .support-admin-message { border:1px solid #e2e8f0; border-radius:8px; padding:.75rem; max-width:780px; }
    .support-admin-message.inbound { background:#f8fafc; }
    .support-admin-message.outbound { background:#ecfdf5; margin-left:auto; }
    .support-admin-message-head { color:#475569; font-size:.78rem; margin-bottom:.35rem; display:flex; justify-content:space-between; gap:.75rem; }
    .support-admin-form-grid { display:grid; gap:.75rem; }
    .support-admin-field { display:grid; gap:.35rem; color:#334155; font-size:.84rem; font-weight:760; }
    .support-admin-field input, .support-admin-field select, .support-admin-field textarea { box-sizing:border-box; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:.65rem; color:#0f172a; }
    .support-admin-field textarea { min-height:5.5rem; resize:vertical; line-height:1.45; }
    .support-admin-action-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr)); gap:.75rem; margin:1rem 0; }
    .support-admin-triage-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr)); gap:.75rem; }
    .support-admin-quote-panel { border:1px solid #bfdbfe; border-radius:8px; padding:1rem; background:#eff6ff; margin-bottom:1rem; display:grid; gap:.85rem; }
    .support-admin-quote-head { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-start; }
    .support-admin-quote-total { color:#0f172a; font-size:1.25rem; font-weight:900; }
    .support-admin-quote-item-grid { display:grid; grid-template-columns:minmax(140px,1.2fr) minmax(140px,1.4fr) 90px 120px; gap:.55rem; align-items:end; }
    .support-admin-quote-list { display:grid; gap:.4rem; color:#334155; }
    .support-admin-quote-list-item { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.75rem; border-top:1px solid #cfe3ff; padding-top:.45rem; }
    .support-admin-alert { padding:.75rem; border-radius:8px; margin-bottom:1rem; }
    .support-admin-alert.notice { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .support-admin-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .support-admin-empty { border:1px dashed #cbd5e1; border-radius:8px; padding:1rem; background:#f8fafc; color:#64748b; }
    @media (max-width: 1000px) { .support-admin-shell { grid-template-columns:1fr; } }
    @media (max-width: 760px) { .support-admin-quote-item-grid { grid-template-columns:1fr; } }
    @media (max-width: 560px) { .support-admin-form-grid .btn-premium-primary, .support-admin-form-grid .btn-premium-secondary { width:100%; justify-content:center; } .support-admin-quote-list-item { grid-template-columns:1fr; } }
</style>

<div class="page-premium support-admin-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Owner Support Admin</h1>
                <p>Review free support, paid setup requests, and internal expert requests from one operator queue.</p>
            </div>
            <div class="page-header-actions">
                <a href="owner_help_experts_admin.php" class="btn-premium-secondary">Expert Profiles</a>
                <a href="dashboard.php" class="btn-premium-secondary">Platform Ops Dashboard</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="support-admin-alert notice"><?php echo $h($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="support-admin-alert error"><?php echo $h($error); ?></div><?php endif; ?>

        <div class="support-admin-shell">
            <aside class="support-admin-panel">
                <form method="GET" class="support-admin-form-grid">
                    <div class="support-admin-triage-grid">
                        <label class="support-admin-field">Status
                            <select name="status">
                                <?php foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'waiting_on_owner' => 'Waiting on owner', 'waiting_on_provider' => 'Waiting on provider', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="support-admin-field">Lane
                            <select name="lane">
                                <?php foreach (['' => 'All', 'system_error' => 'System issue', 'billing_access' => 'Billing access', 'account_access' => 'Account access', 'setup_help' => 'Setup help', 'installation_help' => 'Installation help', 'strategy_mentor' => 'Strategy mentor', 'account_manager' => 'Account manager'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo $filters['lane'] === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="support-admin-field">Commercial
                            <select name="commercial_type">
                                <?php foreach (['' => 'All', 'free' => 'Free', 'paid_setup' => 'Paid setup', 'paid_expert' => 'Paid expert'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo $filters['commercial_type'] === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="support-admin-field">Pricing
                            <select name="pricing_state">
                                <?php foreach (['' => 'All', 'free' => 'Free', 'quote_required' => 'Quote required', 'quoted' => 'Quoted', 'accepted' => 'Accepted', 'manual_payment_pending' => 'Manual payment pending', 'not_applicable' => 'Not applicable'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo $filters['pricing_state'] === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="support-admin-field">Expert
                            <select name="expert_profile_id">
                                <option value="0">All</option>
                                <?php foreach ($experts as $expert): ?>
                                    <option value="<?php echo (int) ($expert['id'] ?? 0); ?>" <?php echo (int) $filters['expert_profile_id'] === (int) ($expert['id'] ?? 0) ? 'selected' : ''; ?>><?php echo $h($expert['name'] ?? 'Internal expert'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <button type="submit" class="btn-premium-secondary">Filter queue</button>
                </form>

                <div class="support-admin-list">
                    <?php if ($cases === []): ?>
                        <div class="support-admin-empty">No owner help requests match these filters.</div>
                    <?php else: ?>
                        <?php foreach ($cases as $case): ?>
                            <?php $help = (array) ($case['help_request'] ?? []); $isActive = $selectedCase && (int) ($selectedCase['id'] ?? 0) === (int) ($case['id'] ?? 0); ?>
                            <a class="support-admin-link <?php echo $isActive ? 'active' : ''; ?>" href="owner_support_admin.php?event_id=<?php echo (int) ($case['id'] ?? 0); ?>">
                                <strong><?php echo $h($case['owner_subject'] ?? 'Owner support request'); ?></strong>
                                <span class="support-admin-meta">
                                    <span><?php echo $h($case['owner_workspace_name'] ?? ('Workspace #' . (int) ($case['owner_workspace_id'] ?? 0))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($laneLabel((string) ($help['lane'] ?? 'system_error'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($statusLabel((string) ($case['status'] ?? 'open'))); ?></span>
                                </span>
                                <span class="support-admin-chip-row">
                                    <span class="support-admin-chip <?php echo (string) ($help['commercial_type'] ?? 'free') === 'free' ? 'is-free' : 'is-paid'; ?>"><?php echo $h(ucwords(str_replace('_', ' ', (string) ($help['commercial_type'] ?? 'free')))); ?></span>
                                    <span class="support-admin-chip"><?php echo $h(ucwords(str_replace('_', ' ', (string) ($help['pricing_state'] ?? 'free')))); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="support-admin-panel">
                <?php if ($selectedCase === null): ?>
                    <h2>Select a help request</h2>
                    <p style="color:#64748b;">The owner thread and operator controls will appear here.</p>
                <?php else: ?>
                    <?php $help = (array) ($selectedCase['help_request'] ?? []); ?>
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                        <div>
                            <h2><?php echo $h($selectedCase['owner_subject'] ?? 'Owner help request'); ?></h2>
                            <div class="support-admin-meta">
                                <span><?php echo $h($selectedCase['owner_workspace_name'] ?? ('Workspace #' . (int) ($selectedCase['owner_workspace_id'] ?? 0))); ?></span>
                                <span>/</span>
                                <span><?php echo $h($laneLabel((string) ($help['lane'] ?? 'system_error'))); ?></span>
                                <span>/</span>
                                <span><?php echo $h($statusLabel((string) ($selectedCase['status'] ?? 'open'))); ?></span>
                                <span>/</span>
                                <span>Owner <?php echo $h($selectedCase['owner_email'] ?? $selectedCase['contact_email'] ?? 'unknown'); ?></span>
                            </div>
                            <div class="support-admin-chip-row">
                                <span class="support-admin-chip <?php echo (string) ($help['commercial_type'] ?? 'free') === 'free' ? 'is-free' : 'is-paid'; ?>"><?php echo $h(ucwords(str_replace('_', ' ', (string) ($help['commercial_type'] ?? 'free')))); ?></span>
                                <span class="support-admin-chip"><?php echo $h(ucwords(str_replace('_', ' ', (string) ($help['pricing_state'] ?? 'free')))); ?></span>
                                <?php if (!empty($help['offering_label'])): ?><span class="support-admin-chip"><?php echo $h($help['offering_label']); ?></span><?php endif; ?>
                                <?php if (!empty($help['expert_name'])): ?><span class="support-admin-chip"><?php echo $h($help['expert_name']); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <a class="btn-premium-secondary" href="workspace_admin.php?workspace_id=<?php echo (int) ($selectedCase['owner_workspace_id'] ?? 0); ?>">Workspace Admin</a>
                    </div>

                    <div class="support-admin-action-grid">
                        <form method="POST" class="support-admin-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="assign_case">
                            <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                            <label class="support-admin-field">Assignee
                                <select name="assigned_user_id">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo (int) ($member['id'] ?? 0); ?>" <?php echo (int) ($selectedCase['assigned_user_id'] ?? 0) === (int) ($member['id'] ?? 0) ? 'selected' : ''; ?>>
                                            <?php echo $h($member['email'] ?? 'Member'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="btn-premium-secondary">Assign operator</button>
                        </form>
                        <form method="POST" class="support-admin-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="update_priority">
                            <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                            <label class="support-admin-field">Priority
                                <select name="priority">
                                    <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                                        <option value="<?php echo $h($priority); ?>" <?php echo (string) ($selectedCase['priority'] ?? '') === $priority ? 'selected' : ''; ?>><?php echo $h(ucfirst($priority)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="btn-premium-secondary">Update priority</button>
                        </form>
                        <form method="POST" class="support-admin-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="transition_case">
                            <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                            <label class="support-admin-field">Case status
                                <select name="case_status">
                                    <?php foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'waiting_on_owner' => 'Waiting on owner', 'waiting_on_provider' => 'Waiting on provider', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label): ?>
                                        <option value="<?php echo $h($value); ?>" <?php echo (string) ($selectedCase['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input type="text" name="reason" value="Updated from Owner Help admin.">
                            <input type="text" name="resolution_summary" placeholder="Resolution summary">
                            <button type="submit" class="btn-premium-secondary">Update status</button>
                        </form>
                    </div>

                    <?php
                        $quoteDraft = $quotes[0] ?? null;
                        $quoteItems = (array) ($quoteDraft['items'] ?? []);
                        while (count($quoteItems) < 4) {
                            $quoteItems[] = ['item_label' => '', 'item_description' => '', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0];
                        }
                    ?>
                    <section class="support-admin-quote-panel" aria-label="Structured quote">
                        <div class="support-admin-quote-head">
                            <div>
                                <h3>Structured quote</h3>
                                <div class="support-admin-meta">
                                    <span><?php echo $quoteDraft ? $h($quoteDraft['quote_number'] ?? 'Draft quote') : 'No quote saved yet'; ?></span>
                                    <?php if ($quoteDraft): ?><span>/</span><span><?php echo $h($quoteStatusLabel((string) ($quoteDraft['status'] ?? 'draft'))); ?></span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($quoteDraft): ?><div class="support-admin-quote-total"><?php echo $h($formatMoney((string) ($quoteDraft['currency'] ?? 'KES'), (float) ($quoteDraft['total_amount'] ?? 0))); ?></div><?php endif; ?>
                        </div>
                        <?php if ($quoteDraft && !empty($quoteDraft['items'])): ?>
                            <div class="support-admin-quote-list">
                                <?php foreach ((array) ($quoteDraft['items'] ?? []) as $item): ?>
                                    <div class="support-admin-quote-list-item">
                                        <div>
                                            <strong><?php echo $h($item['item_label'] ?? 'Service item'); ?></strong>
                                            <?php if (!empty($item['item_description'])): ?><div><?php echo nl2br($h($item['item_description'])); ?></div><?php endif; ?>
                                        </div>
                                        <div><?php echo $h($formatMoney((string) ($quoteDraft['currency'] ?? 'KES'), (float) ($item['line_total'] ?? 0))); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" class="support-admin-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_quote">
                            <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                            <input type="hidden" name="quote_id" value="<?php echo (int) ($quoteDraft['id'] ?? 0); ?>">
                            <div class="support-admin-triage-grid">
                                <label class="support-admin-field">Quote title
                                    <input type="text" name="title" value="<?php echo $h($quoteDraft['title'] ?? (($help['offering_label'] ?? '') !== '' ? $help['offering_label'] . ' quote' : 'Support service quote')); ?>" required>
                                </label>
                                <label class="support-admin-field">Currency
                                    <input type="text" name="currency" value="<?php echo $h($quoteDraft['currency'] ?? 'KES'); ?>" maxlength="8" required>
                                </label>
                                <label class="support-admin-field">Valid until
                                    <input type="date" name="valid_until" value="<?php echo $h($formatDateOnly($quoteDraft['valid_until'] ?? null)); ?>">
                                </label>
                            </div>
                            <label class="support-admin-field">Scope summary
                                <textarea name="scope_summary" placeholder="What work is included in this quote?"><?php echo $h($quoteDraft['scope_summary'] ?? ''); ?></textarea>
                            </label>
                            <label class="support-admin-field">Owner-visible notes
                                <textarea name="owner_visible_notes" placeholder="Context the owner should see before accepting."><?php echo $h($quoteDraft['owner_visible_notes'] ?? ''); ?></textarea>
                            </label>
                            <label class="support-admin-field">Terms
                                <textarea name="terms" placeholder="Payment terms, assumptions, and delivery notes."><?php echo $h($quoteDraft['terms'] ?? ''); ?></textarea>
                            </label>
                            <label class="support-admin-field">Internal quote notes
                                <textarea name="internal_notes" placeholder="Internal notes are not shown to the owner."><?php echo $h($quoteDraft['internal_notes'] ?? ''); ?></textarea>
                            </label>
                            <div class="support-admin-form-grid">
                                <h3>Line items</h3>
                                <?php foreach ($quoteItems as $index => $item): ?>
                                    <div class="support-admin-quote-item-grid">
                                        <label class="support-admin-field">Item
                                            <input type="text" name="quote_item_label[]" value="<?php echo $h($item['item_label'] ?? ''); ?>" placeholder="Setup session">
                                        </label>
                                        <label class="support-admin-field">Description
                                            <input type="text" name="quote_item_description[]" value="<?php echo $h($item['item_description'] ?? ''); ?>" placeholder="What this line covers">
                                        </label>
                                        <label class="support-admin-field">Qty
                                            <input type="number" name="quote_item_quantity[]" value="<?php echo $h($item['quantity'] ?? 1); ?>" step="0.01" min="0">
                                        </label>
                                        <label class="support-admin-field">Unit price
                                            <input type="number" name="quote_item_unit_price[]" value="<?php echo $h($item['unit_price'] ?? 0); ?>" step="0.01" min="0">
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                                <button type="submit" name="quote_submit" value="draft" class="btn-premium-secondary">Save draft</button>
                                <button type="submit" name="quote_submit" value="send" class="btn-premium-primary">Send quote</button>
                            </div>
                        </form>
                    </section>

                    <form method="POST" class="support-admin-form-grid" style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;background:#f8fafc;margin-bottom:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_help_request">
                        <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                        <h3>Triage</h3>
                        <div class="support-admin-triage-grid">
                            <label class="support-admin-field">Lane
                                <select name="lane">
                                    <?php foreach (['system_error' => 'System issue', 'billing_access' => 'Billing access', 'account_access' => 'Account access', 'setup_help' => 'Setup help', 'installation_help' => 'Installation help', 'strategy_mentor' => 'Strategy mentor', 'account_manager' => 'Account manager'] as $value => $label): ?>
                                        <option value="<?php echo $h($value); ?>" <?php echo (string) ($help['lane'] ?? 'system_error') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="support-admin-field">Commercial type
                                <select name="commercial_type">
                                    <?php foreach (['free' => 'Free system support', 'paid_setup' => 'Paid setup help', 'paid_expert' => 'Paid expert help'] as $value => $label): ?>
                                        <option value="<?php echo $h($value); ?>" <?php echo (string) ($help['commercial_type'] ?? 'free') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="support-admin-field">Pricing state
                                <select name="pricing_state">
                                    <?php foreach (['free' => 'Free', 'quote_required' => 'Quote required', 'quoted' => 'Quoted', 'accepted' => 'Accepted', 'manual_payment_pending' => 'Manual payment pending', 'not_applicable' => 'Not applicable'] as $value => $label): ?>
                                        <option value="<?php echo $h($value); ?>" <?php echo (string) ($help['pricing_state'] ?? 'free') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="support-admin-field">Lifecycle
                                <select name="lifecycle_status">
                                    <?php foreach (['new' => 'New', 'triaged' => 'Triaged', 'quoted' => 'Quoted', 'waiting_on_owner' => 'Waiting on owner', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                                        <option value="<?php echo $h($value); ?>" <?php echo (string) ($help['lifecycle_status'] ?? 'new') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="support-admin-field">Setup offering
                                <select name="offering_id">
                                    <option value="0">None</option>
                                    <?php foreach ($offerings as $offering): ?>
                                        <option value="<?php echo (int) ($offering['id'] ?? 0); ?>" <?php echo (int) ($help['offering_id'] ?? 0) === (int) ($offering['id'] ?? 0) ? 'selected' : ''; ?>><?php echo $h($offering['label'] ?? 'Setup help'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="support-admin-field">Expert
                                <select name="expert_profile_id">
                                    <option value="0">No expert assigned</option>
                                    <?php foreach ($experts as $expert): ?>
                                        <option value="<?php echo (int) ($expert['id'] ?? 0); ?>" <?php echo (int) ($help['expert_profile_id'] ?? 0) === (int) ($expert['id'] ?? 0) ? 'selected' : ''; ?>><?php echo $h($expert['name'] ?? 'Internal expert'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="support-admin-field">Owner-visible quote notes
                            <textarea name="quote_notes"><?php echo $h($help['quote_notes'] ?? ''); ?></textarea>
                        </label>
                        <label class="support-admin-field">Internal admin notes
                            <textarea name="admin_notes"><?php echo $h($help['admin_notes'] ?? ''); ?></textarea>
                        </label>
                        <button type="submit" class="btn-premium-primary">Save triage</button>
                    </form>

                    <div class="support-admin-thread">
                        <?php foreach ($messages as $message): ?>
                            <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                            <article class="support-admin-message <?php echo $direction === 'outbound' ? 'outbound' : 'inbound'; ?>">
                                <div class="support-admin-message-head">
                                    <span><?php echo $direction === 'outbound' ? 'Support' : 'Owner'; ?></span>
                                    <span><?php echo $h($formatDate($message['created_at'] ?? null)); ?></span>
                                </div>
                                <div><?php echo nl2br($h($message['body'] ?? '')); ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" class="support-admin-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                        <input type="hidden" name="action" value="reply_operator">
                        <input type="hidden" name="event_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                        <label class="support-admin-field">Reply to owner
                            <textarea name="message" rows="4" required></textarea>
                        </label>
                        <button type="submit" class="btn-premium-primary">Send reply</button>
                    </form>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
