<?php
/**
 * Nurture workspace.
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
use CRM\Security;
use CRM\Session;
use CRM\Modules\Nurture;
use CRM\Services\AutomationBatteryService;
use CRM\Services\CustomerCareAutomationService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (WorkspaceContext::currentWorkspaceId() === null && !empty($user['id'])) {
    WorkspaceContext::activateForUser((int) $user['id']);
}
Authorization::requirePermission('nurture.read');

$nurture = new Nurture();
$canWriteNurture = Authorization::can('nurture.write', $user);
$canManageNurture = Authorization::can('nurture.manage', $user);
$canCreateTasks = Authorization::can('tasks.write', $user);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token');
        }

        $quickActionMap = [
            'quick_create_task' => 'create_tasks',
            'quick_schedule_checkin' => 'schedule_checkin',
            'quick_check_in' => 'mark_checked_in',
        ];
        $quickAction = '';
        $quickContactId = 0;
        foreach ($quickActionMap as $buttonName => $mappedAction) {
            if (isset($_POST[$buttonName])) {
                $quickAction = $mappedAction;
                $quickContactId = (int) $_POST[$buttonName];
                break;
            }
        }

        $action = $quickAction !== ''
            ? $quickAction
            : Security::sanitizeInput((string) ($_POST['action'] ?? ''), 'string');

        $contactIds = $quickContactId > 0
            ? [$quickContactId]
            : array_values(array_unique(array_filter(array_map('intval', $_POST['contact_ids'] ?? []))));

        if ($action === 'create_inline_plan') {
            if (!$canManageNurture) {
                throw new RuntimeException('Follow-up plan creation requires manage access.');
            }
            $name = Security::sanitizeInput((string) ($_POST['plan_name'] ?? ''), 'string');
            if ($name === '') {
                $name = Security::sanitizeInput((string) ($_POST['plan_preset'] ?? ''), 'string');
            }
            $programId = $nurture->createProgram([
                'name' => $name,
                'description' => $_POST['plan_description'] ?? '',
                'program_type' => 'customer_success',
                'cadence' => $_POST['plan_cadence'] ?? 'monthly',
                'first_touch_delay_days' => $_POST['plan_first_touch_delay_days'] ?? null,
                'preferred_channel' => $_POST['plan_preferred_channel'] ?? 'task',
                'default_touch_type' => $_POST['plan_default_touch_type'] ?? 'check_in',
                'touch_guidance' => $_POST['plan_touch_guidance'] ?? '',
                'status' => 'active',
                'linked_campaign_id' => null,
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            if (!empty($contactIds)) {
                $nurture->enrollContacts($programId, $contactIds);
                $message = 'Follow-up plan created and selected customers added.';
            } else {
                $message = 'Follow-up plan created.';
            }
        } else {
            if (!$canWriteNurture) {
                throw new RuntimeException('You do not have permission to update customer care records.');
            }
            if (empty($contactIds)) {
                throw new RuntimeException('Select at least one customer.');
            }
        }

        if ($action === 'create_tasks') {
            if (!$canCreateTasks) {
                throw new RuntimeException('Task creation requires task write access.');
            }
            foreach ($contactIds as $contactId) {
                $nurture->createFollowUpTask($contactId, [
                    'created_by' => (int) ($user['id'] ?? 0),
                    'actor_user_id' => (int) ($user['id'] ?? 0),
                ]);
            }
            $message = count($contactIds) . ' customer check-in task(s) created.';
        } elseif ($action === 'schedule_checkin') {
            foreach ($contactIds as $contactId) {
                $nurture->updateProfile($contactId, [
                    'nurture_status' => 'active',
                    'next_touch_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                    'next_touch_reason' => 'Scheduled customer check-in from Customer Care.',
                ]);
            }
            $message = 'Selected customer check-ins scheduled.';
        } elseif ($action === 'mark_checked_in') {
            foreach ($contactIds as $contactId) {
                $nurture->recordCheckIn($contactId, [
                    'created_by' => (int) ($user['id'] ?? 0),
                ]);
            }
            $message = count($contactIds) . ' customer check-in(s) marked complete.';
        } elseif ($action === 'flag_risk') {
            foreach ($contactIds as $contactId) {
                $nurture->updateProfile($contactId, [
                    'lifecycle_lane' => 'at_risk',
                    'nurture_status' => 'needs_touch',
                    'next_touch_reason' => 'Marked at-risk for customer relationship review.',
                ]);
            }
            $message = 'Selected customers marked at-risk.';
        } elseif ($action === 'prepare_renewal') {
            foreach ($contactIds as $contactId) {
                $nurture->updateProfile($contactId, [
                    'nurture_status' => 'needs_touch',
                    'next_touch_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
                    'next_touch_reason' => 'Plan renewal care and confirm continued customer value.',
                ]);
            }
            $message = 'Selected customers queued for renewal care.';
        } elseif ($action === 'create_expansion') {
            foreach ($contactIds as $contactId) {
                $nurture->updateProfile($contactId, [
                    'lifecycle_lane' => 'expansion',
                    'nurture_status' => 'active',
                    'next_touch_reason' => 'Value-growth signal: explore what has changed since the purchase.',
                ]);
            }
            $message = 'Selected customers queued for value-growth care.';
        } elseif ($action === 'pause') {
            foreach ($contactIds as $contactId) {
                $nurture->updateProfile($contactId, [
                    'nurture_status' => 'paused',
                    'next_touch_reason' => 'Relationship care paused from Customer Care.',
                ]);
            }
            $message = 'Selected customer care profiles paused.';
        } elseif ($action === 'enroll') {
            if (!$canManageNurture) {
                throw new RuntimeException('Follow-up plan changes require manage access.');
            }
            $programId = (int) ($_POST['program_id'] ?? 0);
            if ($programId <= 0) {
                throw new RuntimeException('Choose a follow-up plan.');
            }
            $result = $nurture->enrollContacts($programId, $contactIds);
            $message = 'Follow-up plan updated for selected customers.';
        } elseif ($action !== 'create_inline_plan') {
            throw new RuntimeException('Choose a valid customer care action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tab = Security::sanitizeInput((string) ($_GET['tab'] ?? 'due_checkins'), 'string');
if (!in_array($tab, ['due_checkins', 'needs_attention', 'dormant', 'all'], true)) {
    $tab = 'due_checkins';
}

$filters = [
    'tab' => $tab,
    'lifecycle_lane' => $_GET['lifecycle_lane'] ?? '',
    'nurture_status' => $_GET['nurture_status'] ?? '',
    'temperature' => $_GET['temperature'] ?? '',
    'cadence' => $_GET['cadence'] ?? '',
    'owner_user_id' => $_GET['owner_user_id'] ?? '',
    'program_id' => $_GET['program_id'] ?? '',
    'stale' => isset($_GET['stale']) ? '1' : '',
    'due_before' => $_GET['due_before'] ?? '',
];

$nurture->syncWorkspaceProfilesOnce();
$profiles = $nurture->listProfiles($filters, 150, 0);
$counts = $nurture->getCounts([]);
$programs = $nurture->listProgramsWithStats(['active_only' => true]);
$users = Database::query(
    "SELECT DISTINCT u.id, u.email
     FROM users u
     JOIN workspace_memberships wm ON wm.user_id = u.id
     WHERE wm.workspace_id = ?
       AND wm.membership_status = 'active'
     ORDER BY u.email ASC",
    [(int) (WorkspaceContext::currentWorkspaceId() ?? 0)]
);

$tabs = [
    'due_checkins' => ['label' => 'Today', 'icon' => 'fa-bell'],
    'needs_attention' => ['label' => 'Needs attention', 'icon' => 'fa-triangle-exclamation'],
    'dormant' => ['label' => 'Quiet', 'icon' => 'fa-moon'],
    'all' => ['label' => 'All', 'icon' => 'fa-list'],
];

$labelize = static function (string $value): string {
    return [
        'customer_success' => 'Active customer',
        'expansion' => 'Growth opportunity',
        'at_risk' => 'Needs attention',
        'inactive' => 'Quiet customer',
        'needs_touch' => 'Check-in due',
        'paid_invoice' => 'Paid Invoice',
        'deal_closed_won' => 'Closed-Won Deal',
        'workspace_account' => 'Workspace Account',
        'manual_customer_success' => 'Manual customer',
        'weekly' => 'Weekly',
        'biweekly' => 'Every 2 weeks',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'manual' => 'Manual',
        'task' => 'Task',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'phone' => 'Phone',
        'meeting' => 'Meeting',
        'note' => 'Note',
        'other' => 'Other',
        'check_in' => 'Check-in',
        'renewal' => 'Renewal review',
        'risk_recovery' => 'Risk recovery',
    ][$value] ?? ucwords(str_replace('_', ' ', $value));
};
$firstTouchOptions = [
    '' => 'Use rhythm',
    '0' => 'Today',
    '3' => '3 days',
    '7' => '1 week',
    '14' => '2 weeks',
    '30' => '30 days',
];
$planChannelOptions = ['task', 'email', 'whatsapp', 'sms', 'phone', 'meeting', 'note'];
$planTouchTypeOptions = ['check_in', 'renewal', 'expansion', 'risk_recovery'];
$planTouchTypeLabel = static function (string $value) use ($labelize): string {
    return [
        'check_in' => 'Check-in',
        'renewal' => 'Renewal review',
        'expansion' => 'Value growth',
        'risk_recovery' => 'Risk recovery',
    ][$value] ?? $labelize($value);
};
$profileTotal = (int) ($counts['all'] ?? 0);
$counts['all'] = $profileTotal;
$hasProfiles = !empty($profiles);
$emptyStateTitle = $profileTotal > 0 ? 'No customers match this view' : 'No paying customers in Customer Care yet';
$emptyStateCopy = $profileTotal > 0
    ? 'Adjust filters or switch tabs to continue customer care work.'
    : 'Customer Care fills after an invoice payment, closed-won purchase, or paid workspace account.';
$emptyActionHref = $profileTotal > 0 ? 'nurture.php?tab=' . urlencode($tab) : 'deals.php';
$emptyActionLabel = $profileTotal > 0 ? 'Reset filters' : 'Review Deals';
$emptyActionIcon = $profileTotal > 0 ? 'fa-rotate-left' : 'fa-handshake';
$countLabel = static function (int $count, string $single, string $many): string {
    return $count === 1 ? $single : $many;
};
$dueCount = (int) ($counts['due_checkins'] ?? 0);
$attentionCount = (int) ($counts['needs_attention'] ?? 0);
$quietCount = (int) ($counts['dormant'] ?? 0);
$statusSentence = match ($tab) {
    'needs_attention' => $attentionCount === 0
        ? 'No customers need attention right now.'
        : $attentionCount . ' ' . $countLabel($attentionCount, 'customer needs attention.', 'customers need attention.'),
    'dormant' => $quietCount === 0
        ? 'No quiet customers need review right now.'
        : $quietCount . ' quiet ' . $countLabel($quietCount, 'customer may need attention.', 'customers may need attention.'),
    'all' => $profileTotal === 0
        ? 'No paying customers are in Customer Care yet.'
        : $profileTotal . ' ' . $countLabel($profileTotal, 'customer is in Customer Care.', 'customers are in Customer Care.'),
    default => $dueCount === 0
        ? 'No customer check-ins due today.'
        : $dueCount . ' ' . $countLabel($dueCount, 'customer needs a check-in today.', 'customers need check-ins today.'),
};
$automationLabel = 'Suggest only';
$automationCopy = 'The system can suggest next steps. Human approval is still required.';
$automationBlockers = [];
try {
    $automationStatus = (new AutomationBatteryService())->getStatus((int) ($user['id'] ?? 0));
    $customerCareLayer = (array) ($automationStatus['layers'][CustomerCareAutomationService::DOMAIN_KEY] ?? []);
    $rawAutomationLabel = (string) ($customerCareLayer['status_label'] ?? 'Suggest only');
    $automationLabel = match (strtolower($rawAutomationLabel)) {
        'auto safe' => 'Auto-safe',
        'suggest only' => 'Suggest only',
        'needs more learning' => 'Needs more learning',
        'paused', 'managed hold' => 'Paused',
        default => $rawAutomationLabel !== '' ? $rawAutomationLabel : 'Suggest only',
    };
    $automationCopy = match ($automationLabel) {
        'Auto-safe' => 'The system can handle safe internal care steps when approved.',
        'Paused' => 'Customer Care automation is paused. Manual care actions still work.',
        'Needs more learning' => 'Customer Care needs more check-in history before more automation.',
        default => 'The system can suggest next steps. Human approval is still required.',
    };
    $automationBlockers = array_slice(array_values(array_filter(array_map('strval', (array) ($customerCareLayer['blockers'] ?? [])))), 0, 2);
} catch (Throwable $e) {
    $automationBlockers = ['Automation readiness is temporarily unavailable.'];
}
$programLabel = static function (array $profile): string {
    $programName = trim((string) ($profile['active_program_name'] ?? ''));
    return $programName !== '' ? $programName : 'No plan';
};
$programMeta = static function (array $profile) use ($labelize): string {
    $programName = trim((string) ($profile['active_program_name'] ?? ''));
    if ($programName === '') {
        return 'Add to plan';
    }
    $cadence = trim((string) ($profile['active_program_cadence'] ?? ''));
    $step = trim((string) ($profile['active_program_step'] ?? ''));
    return $step !== '' ? $step : ($cadence !== '' ? $labelize($cadence) : 'Active plan');
};
$profileRows = [];
foreach ($profiles as $profile) {
    $purchase = $profile['purchase_summary'] ?? [];
    $contactId = (int) $profile['contact_id'];
    $brief = $nurture->careBrief($profile);
    $nextTouchLabel = (string) $brief['next_check_in_label'];
    $lastTouchLabel = !empty($profile['last_touch_at']) ? date('M j, Y', strtotime((string) $profile['last_touch_at'])) : 'Not touched yet';
    $ownerEmail = trim((string) ($profile['owner_email'] ?? ''));
    $suggestedMove = (string) $brief['suggested_next_step'];
    $purchaseAmount = isset($purchase['amount']) ? trim((string) ($purchase['currency'] ?? '') . ' ' . number_format((float) $purchase['amount'], 2)) : '';
    $programName = $programLabel($profile);
    $programMetaLabel = $programMeta($profile);
    $customerSubline = trim((string) ($profile['company'] ?? '')) !== ''
        ? (string) $profile['company']
        : (string) ($profile['email'] ?? '');

    $profileRows[] = [
        'contact_id' => $contactId,
        'contact_name' => (string) $profile['contact_name'],
        'email' => (string) ($profile['email'] ?? ''),
        'customer_subline' => $customerSubline,
        'owner_email' => $ownerEmail,
        'brief' => $brief,
        'why_now' => (string) $brief['why_now'],
        'suggested_move' => $suggestedMove,
        'next_touch_label' => $nextTouchLabel,
        'last_touch_label' => $lastTouchLabel,
        'purchase_title' => (string) ($purchase['title'] ?? $profile['entry_label'] ?? 'Customer purchase'),
        'purchase_amount' => $purchaseAmount,
        'health_score' => (int) $profile['health_score'],
        'program_name' => $programName,
        'program_meta_label' => $programMetaLabel,
        'has_active_program' => !empty($profile['active_program_id']),
    ];
}
$planPresets = [
    ['name' => 'First 30 Days', 'cadence' => 'weekly', 'first_touch_delay_days' => 3, 'preferred_channel' => 'email', 'default_touch_type' => 'check_in', 'description' => 'Help a new customer get value after purchase.', 'guidance' => 'Confirm the first promised outcome and ask what feels blocked.'],
    ['name' => 'Quiet Customer', 'cadence' => 'monthly', 'first_touch_delay_days' => 0, 'preferred_channel' => 'whatsapp', 'default_touch_type' => 'risk_recovery', 'description' => 'Restart useful contact with customers who have gone quiet.', 'guidance' => 'Keep it low pressure and ask what would make the relationship useful again.'],
    ['name' => 'Renewal', 'cadence' => 'monthly', 'first_touch_delay_days' => 14, 'preferred_channel' => 'email', 'default_touch_type' => 'renewal', 'description' => 'Check value and next steps before renewal.', 'guidance' => 'Review outcomes, open blockers, and next-term needs before renewal.'],
    ['name' => 'Quarterly Check-in', 'cadence' => 'quarterly', 'first_touch_delay_days' => null, 'preferred_channel' => 'task', 'default_touch_type' => 'expansion', 'description' => 'Keep a steady relationship rhythm.', 'guidance' => 'Ask what changed since the purchase and where more value would help.'],
];
$pageTitle = 'Customer Care - ' . brandProductName();
$nurtureGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_NURTURE);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<style>
    .nurture-page .container { max-width:min(100%, 1400px); }
    .nurture-page .page-header { padding:1.45rem 2rem; margin-bottom:1rem; }
    .nurture-page .filters-card { padding:1.25rem 1.75rem; margin-bottom:1rem; }
    .nurture-page .nurture-filter-panel { padding:0; overflow:hidden; }
    .nurture-workbench-header { align-items:center; }
    .nurture-workbench-header h1 { letter-spacing:0; }
    .nurture-header-main { min-width:0; }
    .nurture-header-actions { align-items:center; justify-content:flex-end; }
    .nurture-automation-status { display:inline-flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-top:.65rem; color:#64748b; font-size:.78rem; }
    .nurture-automation-status span { color:#475569; font-weight:800; text-transform:uppercase; font-size:.68rem; letter-spacing:.08em; }
    .nurture-automation-status > strong { color:#0f172a; padding:.28rem .55rem; border:1px solid #dbe3ef; border-radius:999px; background:#f8fafc; font-size:.78rem; line-height:1; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .nurture-automation-details { position:relative; color:#64748b; }
    .nurture-automation-details summary { cursor:pointer; color:#334155; font-weight:700; list-style:none; }
    .nurture-automation-details summary::-webkit-details-marker { display:none; }
    .nurture-automation-details[open] summary { color:#1d4ed8; }
    .nurture-automation-detail-body { position:absolute; z-index:20; top:calc(100% + .45rem); left:0; width:min(22rem, calc(100vw - 2rem)); padding:.85rem; border:1px solid #dbe3ef; border-radius:8px; background:#fff; box-shadow:0 16px 38px rgba(15,23,42,.14); color:#475569; font-size:.78rem; line-height:1.45; }
    .nurture-automation-detail-body p { margin:0; font-size:.78rem; line-height:1.45; }
    .nurture-automation-detail-body strong { display:block; margin-top:.55rem; color:#0f172a; font-size:.76rem; }
    .nurture-automation-detail-body ul { margin:.55rem 0 0 1rem; padding:0; }
    .nurture-automation-detail-body li { margin:.18rem 0; }
    .nurture-automation-detail-body a { display:inline-flex; margin-top:.55rem; color:#1d4ed8; font-weight:800; text-decoration:none; }
    .nurture-filter-panel { margin-bottom:1rem; }
    .nurture-filter-panel > summary { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.82rem 1.45rem; cursor:pointer; color:#0f172a; font-weight:800; list-style:none; }
    .nurture-filter-panel > summary::-webkit-details-marker { display:none; }
    .nurture-filter-panel > summary span { color:#64748b; font-size:.84rem; font-weight:700; white-space:nowrap; }
    .nurture-filter-panel > summary span:first-child { color:#0f172a; font-weight:800; }
    .nurture-filter-panel > summary i { color:#2563eb; margin-right:.45rem; }
    .nurture-filter-panel[open] > summary { border-bottom:1px solid #eef2f7; }
    .nurture-toolbar { display:grid; grid-template-columns:minmax(180px,1fr) minmax(160px,.85fr) minmax(160px,.85fr) minmax(190px,1fr) auto; gap:.75rem .85rem; align-items:start; }
    .nurture-filter-panel .nurture-toolbar { padding:1.15rem 1.45rem 1.25rem; }
    .nurture-toolbar .filter-group { min-width:0; }
    .nurture-toolbar label { display:block; margin-bottom:.5rem; color:#0f172a; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
    .nurture-toolbar select, .nurture-toolbar input { width:100%; padding:.625rem .875rem; border:1px solid #d1d5db; border-radius:8px; background:#fff; color:#0f172a; font:inherit; font-size:.875rem; }
    .nurture-toolbar select:focus, .nurture-toolbar input:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
    .nurture-advanced-filters { grid-column:1 / -1; margin-top:0; border:1px solid #eef2f7; border-radius:8px; padding:.65rem .75rem; background:#f8fafc; }
    .nurture-advanced-filters > summary { cursor:pointer; font-weight:800; color:#334155; }
    .nurture-advanced-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.75rem; margin-top:.75rem; }
    .nurture-checkbox-filter { display:flex !important; gap:.45rem; align-items:center; min-height:2.55rem; margin:0; text-transform:none !important; letter-spacing:0 !important; }
    .nurture-checkbox-filter input { width:auto; }
    .nurture-filter-actions { display:flex; gap:.55rem; flex-wrap:wrap; align-items:center; justify-content:flex-start; padding-top:1.55rem; white-space:nowrap; }
    .nurture-tabs { margin-bottom:1rem; }
    .nurture-tab { display:inline-flex; align-items:center; gap:.45rem; border-color:currentColor; }
    .nurture-tab span { font-weight:800; }
    .nurture-tab--due_checkins { background:#eff6ff; color:#1d4ed8; }
    .nurture-tab--needs_attention { background:#fff7ed; color:#c2410c; }
    .nurture-tab--dormant { background:#f8fafc; color:#334155; }
    .nurture-tab--all { background:#f5f3ff; color:#6d28d9; }
    .nurture-tab.active { color:#fff; background:#2563eb; border-color:#2563eb; }
    .nurture-tab:hover { color:#fff; background:#2563eb; border-color:#2563eb; }
    .nurture-queue-shell { margin-bottom:1rem; overflow:visible; }
    .nurture-queue-head { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:.72rem 1rem; border-bottom:1px solid #eef2f7; }
    .nurture-queue-head h2 { margin:0; color:#0f172a; font-size:1rem; letter-spacing:0; }
    .nurture-queue-head p { display:none; }
    .nurture-queue-count { color:#64748b; font-size:.86rem; font-weight:700; white-space:nowrap; }
    .nurture-selection-hint { display:none; color:#64748b; font-size:.84rem; margin:-.05rem 0 .55rem; }
    .nurture-selection-hint[hidden] { display:none !important; }
    .nurture-selected-action-bar { display:flex; gap:.65rem; flex-wrap:wrap; align-items:center; justify-content:space-between; padding:.75rem; border:1px solid #bfdbfe; border-radius:8px; margin-bottom:.85rem; background:#eff6ff; }
    .nurture-selected-action-bar[hidden] { display:none !important; }
    .nurture-queue-form { padding:.5rem 1rem 1rem; }
    .nurture-bulk-actions { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; min-width:0; }
    .nurture-bulk-actions select { width:auto; min-width:210px; max-width:240px; padding:.55rem .65rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
    .nurture-bulk-status { color:#1e3a8a; font-size:.86rem; font-weight:700; }
    .nurture-program-control[hidden] { display:none !important; }
    .nurture-table-wrap { overflow:auto; border:1px solid #eef2f7; border-radius:8px; }
    .nurture-table { width:100%; border-collapse:collapse; background:#fff; }
    .nurture-table th, .nurture-table td { padding:.62rem .55rem; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:top; }
    .nurture-table th { font-size:.72rem; text-transform:uppercase; color:#64748b; letter-spacing:0; background:#f8fafc; }
    .nurture-table tbody tr:last-child td { border-bottom:0; }
    .nurture-customer-name { font-weight:800; color:#0f172a; text-decoration:none; }
    .nurture-muted-line { color:#64748b; font-size:.8rem; line-height:1.35; overflow-wrap:anywhere; }
    .nurture-why { display:inline-flex; align-items:center; padding:.25rem .5rem; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:.76rem; font-weight:800; white-space:nowrap; }
    .nurture-care-primary { font-weight:750; color:#0f172a; max-width:360px; line-height:1.4; }
    .nurture-care-meta { color:#64748b; font-size:.78rem; margin-top:.25rem; line-height:1.35; }
    .nurture-care-brief { margin-top:.45rem; color:#475569; font-size:.8rem; }
    .nurture-care-brief summary { cursor:pointer; color:#334155; font-weight:700; }
    .nurture-care-brief dl { display:grid; grid-template-columns:auto minmax(0,1fr); gap:.25rem .5rem; margin:.45rem 0 0; }
    .nurture-care-brief dt { color:#64748b; font-weight:700; }
    .nurture-care-brief dd { margin:0; color:#0f172a; overflow-wrap:anywhere; }
    .nurture-owner-unassigned { color:#9a3412; font-weight:700; }
    .nurture-program-name { color:#0f172a; font-weight:750; }
    .nurture-program-help { color:#64748b; font-size:.78rem; margin-top:.2rem; }
    .nurture-inline-link { appearance:none; border:0; padding:0; background:transparent; color:#1d4ed8; font:inherit; font-size:.78rem; font-weight:800; cursor:pointer; text-align:left; text-decoration:none; }
    .nurture-row-actions { display:flex; gap:.32rem; flex-wrap:wrap; }
    .nurture-row-actions button, .nurture-row-actions a { white-space:nowrap; padding:.36rem .5rem; font-size:.78rem; }
    .nurture-card-list { display:none; gap:.75rem; }
    .nurture-customer-card { border:1px solid #e2e8f0; border-radius:8px; padding:.9rem; background:#fff; min-width:0; }
    .nurture-customer-card-head { display:flex; justify-content:space-between; gap:.75rem; align-items:flex-start; }
    .nurture-customer-card-head > div { min-width:0; }
    .nurture-customer-card-head [data-nurture-check] { flex:0 0 auto; margin-top:.2rem; }
    .nurture-customer-card h3 { margin:0; font-size:1rem; color:#0f172a; }
    .nurture-card-stack { display:grid; gap:.65rem; margin:.8rem 0; }
    .nurture-card-stack span { display:block; color:#64748b; font-size:.72rem; font-weight:800; text-transform:uppercase; }
    .nurture-card-stack strong { display:block; color:#0f172a; font-size:.92rem; margin-top:.15rem; overflow-wrap:anywhere; }
    .care-plan-backdrop { position:fixed; inset:0; z-index:1100; background:rgba(15,23,42,.32); }
    .care-plan-backdrop[hidden], .care-plan-drawer[hidden] { display:none !important; }
    .care-plan-drawer { position:fixed; top:0; right:0; bottom:0; z-index:1101; box-sizing:border-box; width:min(440px,100vw); overflow:auto; padding:1rem; background:#fff; box-shadow:-20px 0 45px rgba(15,23,42,.18); }
    .care-plan-drawer-head { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; padding-bottom:.9rem; border-bottom:1px solid #e2e8f0; }
    .care-plan-drawer-head h2, .care-plan-section h3 { margin:0; color:#0f172a; letter-spacing:0; }
    .care-plan-drawer-head p, .care-plan-section p { margin:.25rem 0 0; color:#64748b; font-size:.88rem; line-height:1.4; }
    .care-plan-section { display:grid; gap:.75rem; padding:1rem 0; border-bottom:1px solid #eef2f7; }
    .care-plan-section label { display:grid; gap:.3rem; color:#475569; font-size:.78rem; font-weight:800; }
    .care-plan-section input, .care-plan-section select, .care-plan-section textarea { width:100%; padding:.62rem .7rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font:inherit; }
    .care-plan-create { border-bottom:0; }
    .nurture-empty-state { display:grid; gap:1rem; justify-items:center; padding:3rem; margin:0; text-align:center; color:#64748b; }
    .nurture-empty-state h2 { margin:0; font-size:1rem; color:#0f172a; }
    .nurture-empty-state p { margin:0; max-width:540px; }
    .nurture-empty-actions { display:flex; gap:.55rem; flex-wrap:wrap; justify-content:center; }
    .nurture-pill { display:inline-flex; align-items:center; padding:.25rem .5rem; border-radius:999px; font-size:.76rem; font-weight:700; background:#eef2ff; color:#384ad7; }
    .nurture-pill.hot { background:#fee2e2; color:#991b1b; }
    .nurture-pill.warm { background:#ffedd5; color:#9a3412; }
    .nurture-pill.cool { background:#dbeafe; color:#1d4ed8; }
    .nurture-pill.cold { background:#e2e8f0; color:#475569; }
    @media (max-width: 1100px) {
        .nurture-toolbar { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .nurture-filter-actions { grid-column:1 / -1; padding-top:0; }
        .nurture-advanced-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width: 700px) {
        .nurture-workbench-header, .nurture-queue-head { flex-direction:column; }
        .nurture-header-actions { justify-content:flex-start; }
        .nurture-tabs { display:flex; overflow:auto; flex-wrap:nowrap; padding:0 .25rem .15rem; }
        .nurture-tab { white-space:nowrap; }
        .nurture-toolbar, .nurture-advanced-grid { grid-template-columns:1fr; }
        .nurture-filter-panel > summary { padding:.82rem 1rem; }
        .nurture-filter-panel .nurture-toolbar { padding:1rem; }
        .nurture-filter-actions { grid-column:auto; }
        .nurture-automation-detail-body { left:auto; right:0; }
        .nurture-table-wrap { display:none; }
        .nurture-card-list { display:grid; }
        .nurture-bulk-actions, .nurture-bulk-actions select, .nurture-bulk-actions button { width:100%; max-width:none; }
        .nurture-selected-action-bar { align-items:stretch; }
        .care-plan-drawer { width:100vw; }
    }
</style>

<div class="page-premium nurture-page">
    <div class="container">
        <div class="page-header nurture-workbench-header">
            <div class="nurture-header-main">
                <h1>Customer Care</h1>
                <p><?php echo htmlspecialchars($statusSentence); ?></p>
                <div class="nurture-automation-status" data-care-automation-status>
                    <span>Automation</span>
                    <strong><?php echo htmlspecialchars($automationLabel); ?></strong>
                    <details class="nurture-automation-details">
                        <summary>Details</summary>
                        <div class="nurture-automation-detail-body">
                            <p><?php echo htmlspecialchars($automationCopy); ?></p>
                            <?php if (!empty($automationBlockers)): ?>
                                <strong>Why automation is limited</strong>
                            <ul>
                                <?php foreach ($automationBlockers as $automationBlocker): ?>
                                    <li><?php echo htmlspecialchars($automationBlocker); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <a href="ai_learning_review.php?domain=<?php echo urlencode(CustomerCareAutomationService::DOMAIN_KEY); ?>">Review learning</a>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            </div>
            <div class="page-header-actions nurture-header-actions">
                <?php if ($nurtureGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_NURTURE, 'Customer Care page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <button class="btn-premium-primary" type="button" data-care-plan-open>
                    <i class="fas fa-list-check"></i>
                    Follow-up Plans
                </button>
            </div>
        </div>

        <?php if ($message): ?><div class="content-card" style="margin-bottom:1rem;background:#ecfdf5;border-color:#86efac;color:#166534;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="margin-bottom:1rem;background:#fef2f2;border-color:#fca5a5;color:#991b1b;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <details class="filters-card nurture-filter-panel" data-nurture-filter-toggle>
            <summary>
                <span><i class="fas fa-filter"></i> Filters</span>
                <span><?php echo count($profiles); ?> shown</span>
            </summary>
            <form method="GET" class="filters-form nurture-toolbar">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <div class="filter-group">
                    <label for="owner_user_id">Owner</label>
                    <select id="owner_user_id" name="owner_user_id">
                        <option value="">Any owner</option>
                        <option value="__unassigned" <?php echo ($filters['owner_user_id'] ?? '') === '__unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                        <?php foreach ($users as $owner): ?>
                            <option value="<?php echo (int) $owner['id']; ?>" <?php echo (string) ($filters['owner_user_id'] ?? '') === (string) $owner['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="due_before">Due before</label>
                    <input id="due_before" type="date" name="due_before" value="<?php echo htmlspecialchars((string) ($filters['due_before'] ?? '')); ?>">
                </div>
                <div class="filter-group">
                    <label for="nurture_status">Status</label>
                    <select id="nurture_status" name="nurture_status">
                        <option value="">Any state</option>
                        <?php foreach (['active', 'needs_touch', 'paused', 'completed'] as $statusOption): ?>
                            <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo ($filters['nurture_status'] ?? '') === $statusOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($statusOption)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="program_id">Follow-up plan</label>
                    <select id="program_id" name="program_id">
                        <option value="">Any plan</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo (int) $program['id']; ?>" <?php echo (string) ($filters['program_id'] ?? '') === (string) $program['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $program['name']); ?> &middot; <?php echo htmlspecialchars($labelize((string) $program['cadence'])); ?> &middot; <?php echo htmlspecialchars($labelize((string) ($program['preferred_channel'] ?? 'task'))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions nurture-filter-actions">
                    <button class="btn-premium-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                    <a class="btn-premium-secondary" href="nurture.php?tab=<?php echo urlencode($tab); ?>">Reset</a>
                </div>
                <details class="nurture-advanced-filters">
                    <summary>Advanced filters</summary>
                    <div class="nurture-advanced-grid">
                        <div>
                            <label for="lifecycle_lane">Customer status</label>
                            <select id="lifecycle_lane" name="lifecycle_lane">
                                <option value="">Any stage</option>
                                <?php foreach (['customer_success', 'at_risk', 'expansion', 'inactive'] as $laneOption): ?>
                                    <option value="<?php echo htmlspecialchars($laneOption); ?>" <?php echo ($filters['lifecycle_lane'] ?? '') === $laneOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($laneOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="temperature">Signal</label>
                            <select id="temperature" name="temperature">
                                <option value="">Any signal</option>
                                <?php foreach (['hot', 'warm', 'cool', 'cold'] as $temperatureOption): ?>
                                    <option value="<?php echo htmlspecialchars($temperatureOption); ?>" <?php echo ($filters['temperature'] ?? '') === $temperatureOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($temperatureOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="cadence">Follow-up rhythm</label>
                            <select id="cadence" name="cadence">
                                <option value="">Any rhythm</option>
                                <?php foreach (['weekly', 'biweekly', 'monthly', 'quarterly', 'manual'] as $cadenceOption): ?>
                                    <option value="<?php echo htmlspecialchars($cadenceOption); ?>" <?php echo ($filters['cadence'] ?? '') === $cadenceOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($cadenceOption)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="nurture-checkbox-filter">
                            <input type="checkbox" name="stale" value="1" <?php echo !empty($filters['stale']) ? 'checked' : ''; ?>>
                            Check-ins due
                        </label>
                    </div>
                </details>
            </form>
        </details>

        <div class="stage-stats nurture-tabs">
            <?php foreach ($tabs as $key => $item): ?>
                <a class="stage-stat nurture-tab nurture-tab--<?php echo htmlspecialchars($key); ?> <?php echo $tab === $key ? 'active' : ''; ?>" href="?tab=<?php echo urlencode($key); ?>">
                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <?php echo htmlspecialchars($item['label']); ?>
                    <span><?php echo (int) ($counts[$key] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <section class="table-card nurture-queue-shell" data-nurture-care-queue data-care-queue>
            <div class="nurture-queue-head">
                <div>
                    <h2><?php echo htmlspecialchars($tabs[$tab]['label'] ?? 'Today'); ?></h2>
                    <p><?php echo htmlspecialchars($statusSentence); ?></p>
                </div>
                <div class="nurture-queue-count"><?php echo count($profiles); ?> shown</div>
            </div>

            <?php if (!$hasProfiles): ?>
                <div class="nurture-empty-state">
                    <h2><?php echo htmlspecialchars($emptyStateTitle); ?></h2>
                    <p><?php echo htmlspecialchars($emptyStateCopy); ?></p>
                    <div class="nurture-empty-actions">
                        <a class="btn-premium-primary" href="<?php echo htmlspecialchars($emptyActionHref); ?>"><i class="fas <?php echo htmlspecialchars($emptyActionIcon); ?>"></i> <?php echo htmlspecialchars($emptyActionLabel); ?></a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" class="nurture-queue-form" data-nurture-bulk-form data-can-write="<?php echo $canWriteNurture ? '1' : '0'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <div class="nurture-selection-hint" data-nurture-selection-hint>Select customers to apply actions.</div>
                    <div class="nurture-selected-action-bar" data-nurture-selected-action-bar hidden>
                        <div class="nurture-bulk-actions">
                            <select name="action" data-nurture-action>
                                <option value="create_tasks">Create task</option>
                                <option value="schedule_checkin">Schedule</option>
                                <option value="mark_checked_in">Mark checked in</option>
                                <?php if ($canManageNurture): ?><option value="enroll">Add to follow-up plan</option><?php endif; ?>
                            </select>
                            <?php if ($canManageNurture): ?>
                                <span class="nurture-program-control" data-nurture-program-control hidden>
                                    <select name="program_id">
                                        <option value="">Choose follow-up plan</option>
                                        <?php foreach ($programs as $program): ?><option value="<?php echo (int) $program['id']; ?>"><?php echo htmlspecialchars((string) $program['name']); ?> &middot; <?php echo htmlspecialchars($labelize((string) $program['cadence'])); ?> &middot; <?php echo htmlspecialchars($labelize((string) ($program['preferred_channel'] ?? 'task'))); ?></option><?php endforeach; ?>
                                    </select>
                                </span>
                                <button type="button" class="nurture-inline-link" data-care-plan-open>New plan</button>
                            <?php endif; ?>
                            <button type="submit" class="btn-premium-primary" data-nurture-apply disabled>Apply</button>
                        </div>
                        <span class="nurture-bulk-status"><strong data-nurture-selection-count>0</strong> selected</span>
                    </div>

                    <div class="nurture-table-wrap">
                        <table class="nurture-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" data-nurture-select-all <?php echo !$canWriteNurture ? 'disabled' : ''; ?>></th>
                                    <th>Customer</th>
                                    <th>Why</th>
                                    <th>Suggested next step</th>
                                    <th>Follow-up plan</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profileRows as $row): ?>
                                    <tr data-nurture-care-row data-care-row>
                                        <td><input data-nurture-check type="checkbox" name="contact_ids[]" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?>></td>
                                        <td>
                                            <a class="nurture-customer-name" href="nurture_view.php?contact_id=<?php echo (int) $row['contact_id']; ?>"><?php echo htmlspecialchars((string) $row['contact_name']); ?></a>
                                            <div class="nurture-muted-line"><?php echo htmlspecialchars((string) $row['customer_subline']); ?></div>
                                            <?php if ($row['owner_email'] === ''): ?><div class="nurture-muted-line nurture-owner-unassigned">Unassigned</div><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="nurture-why"><?php echo htmlspecialchars((string) $row['why_now']); ?></span>
                                            <div class="nurture-care-meta"><?php echo htmlspecialchars((string) $row['next_touch_label']); ?></div>
                                        </td>
                                        <td>
                                            <div class="nurture-care-primary"><?php echo htmlspecialchars((string) $row['suggested_move']); ?></div>
                                            <details class="nurture-care-brief">
                                                <summary>Brief</summary>
                                                <dl>
                                                    <dt>Purchase</dt>
                                                    <dd><?php echo htmlspecialchars((string) $row['purchase_title']); ?><?php echo $row['purchase_amount'] !== '' ? ' &middot; ' . htmlspecialchars((string) $row['purchase_amount']) : ''; ?></dd>
                                                    <dt>Health</dt>
                                                    <dd><?php echo (int) $row['health_score']; ?>/100</dd>
                                                    <dt>Last touch</dt>
                                                    <dd><?php echo htmlspecialchars((string) $row['last_touch_label']); ?></dd>
                                                    <dt>Next touch</dt>
                                                    <dd><?php echo htmlspecialchars((string) $row['next_touch_label']); ?></dd>
                                                    <dt>Suggested</dt>
                                                    <dd><?php echo htmlspecialchars((string) $row['suggested_move']); ?></dd>
                                                    <dt>Program</dt>
                                                    <dd><?php echo htmlspecialchars((string) $row['program_name']); ?></dd>
                                                </dl>
                                            </details>
                                        </td>
                                        <td>
                                            <div class="nurture-program-name"><?php echo htmlspecialchars((string) $row['program_name']); ?></div>
                                            <?php if (!$canManageNurture || $row['has_active_program']): ?>
                                                <div class="nurture-program-help"><?php echo htmlspecialchars((string) $row['program_meta_label']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($canManageNurture && !$row['has_active_program']): ?>
                                                <button class="nurture-inline-link" type="button" data-nurture-enroll-contact data-care-plan-open value="<?php echo (int) $row['contact_id']; ?>">Add to plan</button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="nurture-row-actions">
                                                <button class="btn-premium-secondary" type="submit" name="quick_check_in" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?> data-care-check-in>Check in</button>
                                                <button class="btn-premium-secondary" type="submit" name="quick_create_task" value="<?php echo (int) $row['contact_id']; ?>" <?php echo (!$canWriteNurture || !$canCreateTasks) ? 'disabled' : ''; ?>>Create task</button>
                                                <button class="btn-premium-secondary" type="submit" name="quick_schedule_checkin" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?>>Schedule</button>
                                                <a class="btn-premium-secondary" href="nurture_view.php?contact_id=<?php echo (int) $row['contact_id']; ?>">Open</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="nurture-card-list" aria-label="Customer care cards">
                        <?php foreach ($profileRows as $row): ?>
                            <article class="nurture-customer-card" data-nurture-care-row data-care-row>
                                <div class="nurture-customer-card-head">
                                    <div>
                                        <h3><?php echo htmlspecialchars((string) $row['contact_name']); ?></h3>
                                        <div class="nurture-muted-line"><?php echo htmlspecialchars((string) $row['email']); ?></div>
                                        <?php if ($row['owner_email'] === ''): ?><div class="nurture-muted-line nurture-owner-unassigned">Unassigned</div><?php endif; ?>
                                    </div>
                                    <input data-nurture-check type="checkbox" name="contact_ids[]" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?>>
                                </div>
                                <div class="nurture-card-stack">
                                    <div>
                                        <span>Why</span>
                                        <strong><?php echo htmlspecialchars((string) $row['why_now']); ?></strong>
                                        <div class="nurture-care-meta"><?php echo htmlspecialchars((string) $row['next_touch_label']); ?></div>
                                    </div>
                                    <div>
                                        <span>Suggested next step</span>
                                        <strong><?php echo htmlspecialchars((string) $row['suggested_move']); ?></strong>
                                    </div>
                                    <div>
                                        <span>Follow-up plan</span>
                                        <strong><?php echo htmlspecialchars((string) $row['program_name']); ?></strong>
                                        <div class="nurture-program-help"><?php echo htmlspecialchars((string) $row['program_meta_label']); ?></div>
                                        <?php if ($canManageNurture && !$row['has_active_program']): ?>
                                            <button class="nurture-inline-link" type="button" data-nurture-enroll-contact data-care-plan-open value="<?php echo (int) $row['contact_id']; ?>">Add to plan</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <details class="nurture-care-brief">
                                    <summary>Brief</summary>
                                    <dl>
                                        <dt>Purchase</dt>
                                        <dd><?php echo htmlspecialchars((string) $row['purchase_title']); ?><?php echo $row['purchase_amount'] !== '' ? ' &middot; ' . htmlspecialchars((string) $row['purchase_amount']) : ''; ?></dd>
                                        <dt>Health</dt>
                                        <dd><?php echo (int) $row['health_score']; ?>/100</dd>
                                        <dt>Last touch</dt>
                                        <dd><?php echo htmlspecialchars((string) $row['last_touch_label']); ?></dd>
                                        <dt>Next touch</dt>
                                        <dd><?php echo htmlspecialchars((string) $row['next_touch_label']); ?></dd>
                                        <dt>Suggested</dt>
                                        <dd><?php echo htmlspecialchars((string) $row['suggested_move']); ?></dd>
                                    </dl>
                                </details>
                                <div class="nurture-row-actions">
                                    <button class="btn-premium-secondary" type="submit" name="quick_check_in" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?> data-care-check-in>Check in</button>
                                    <button class="btn-premium-secondary" type="submit" name="quick_create_task" value="<?php echo (int) $row['contact_id']; ?>" <?php echo (!$canWriteNurture || !$canCreateTasks) ? 'disabled' : ''; ?>>Create task</button>
                                    <button class="btn-premium-secondary" type="submit" name="quick_schedule_checkin" value="<?php echo (int) $row['contact_id']; ?>" <?php echo !$canWriteNurture ? 'disabled' : ''; ?>>Schedule</button>
                                    <a class="btn-premium-secondary" href="nurture_view.php?contact_id=<?php echo (int) $row['contact_id']; ?>">Open</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <div class="care-plan-backdrop" data-care-plan-backdrop hidden></div>
        <aside class="care-plan-drawer" data-care-plan-drawer hidden aria-label="Follow-up plans">
            <div class="care-plan-drawer-head">
                <div>
                    <h2>Follow-up Plans</h2>
                    <p>Use a plan when several customers need the same check-in rhythm.</p>
                </div>
                <button type="button" class="btn-premium-secondary" data-care-plan-close>Close</button>
            </div>

            <div class="care-plan-section">
                <h3>Existing plans</h3>
                <?php if (empty($programs)): ?>
                    <p>No follow-up plans yet.</p>
                <?php else: ?>
                    <form method="POST" class="care-plan-existing-form" data-care-plan-enroll-form>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="enroll">
                        <div data-care-plan-selected-inputs></div>
                        <label>
                            Plan
                            <select name="program_id">
                                <option value="">Choose follow-up plan</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo (int) $program['id']; ?>"><?php echo htmlspecialchars((string) $program['name']); ?> &middot; <?php echo htmlspecialchars($labelize((string) $program['cadence'])); ?> &middot; <?php echo htmlspecialchars($labelize((string) ($program['preferred_channel'] ?? 'task'))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="btn-premium-primary" <?php echo !$canManageNurture ? 'disabled data-force-disabled' : ''; ?>>Add selected customers</button>
                    </form>
                <?php endif; ?>
            </div>

            <form method="POST" class="care-plan-section care-plan-create" data-care-plan-create>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                <input type="hidden" name="action" value="create_inline_plan">
                <div data-care-plan-create-selected-inputs></div>
                <h3>Create a simple plan</h3>
                <label>
                    Preset
                    <select name="plan_preset" data-care-plan-preset>
                        <?php foreach ($planPresets as $preset): ?>
                            <option value="<?php echo htmlspecialchars($preset['name']); ?>"
                                data-cadence="<?php echo htmlspecialchars($preset['cadence']); ?>"
                                data-first-touch-delay="<?php echo htmlspecialchars((string) ($preset['first_touch_delay_days'] ?? '')); ?>"
                                data-channel="<?php echo htmlspecialchars($preset['preferred_channel']); ?>"
                                data-touch-type="<?php echo htmlspecialchars($preset['default_touch_type']); ?>"
                                data-description="<?php echo htmlspecialchars($preset['description']); ?>"
                                data-guidance="<?php echo htmlspecialchars($preset['guidance']); ?>"><?php echo htmlspecialchars($preset['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Plan name
                    <input name="plan_name" placeholder="Follow-up plan name">
                </label>
                <label>
                    Follow-up rhythm
                    <select name="plan_cadence">
                        <?php foreach (Nurture::CADENCES as $cadence): ?>
                            <option value="<?php echo htmlspecialchars($cadence); ?>"><?php echo htmlspecialchars($labelize($cadence)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    First check-in
                    <select name="plan_first_touch_delay_days">
                        <?php foreach ($firstTouchOptions as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Channel
                    <select name="plan_preferred_channel">
                        <?php foreach ($planChannelOptions as $channel): ?>
                            <option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Action
                    <select name="plan_default_touch_type">
                        <?php foreach ($planTouchTypeOptions as $touchType): ?>
                            <option value="<?php echo htmlspecialchars($touchType); ?>"><?php echo htmlspecialchars($planTouchTypeLabel($touchType)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Purpose
                    <textarea name="plan_description" rows="3" placeholder="What this plan helps the customer do"></textarea>
                </label>
                <label>
                    Guidance
                    <textarea name="plan_touch_guidance" rows="2" placeholder="How to approach the next touch"></textarea>
                </label>
                <button type="submit" class="btn-premium-primary" <?php echo !$canManageNurture ? 'disabled data-force-disabled' : ''; ?>>Create plan</button>
                <a class="nurture-inline-link" href="nurture_programs.php">Advanced Follow-up Plans</a>
            </form>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-nurture-bulk-form]');
    var canWrite = form ? form.getAttribute('data-can-write') === '1' : false;
    var actionSelect = form ? form.querySelector('[data-nurture-action]') : null;
    var applyButton = form ? form.querySelector('[data-nurture-apply]') : null;
    var countNode = form ? form.querySelector('[data-nurture-selection-count]') : null;
    var selectAll = form ? form.querySelector('[data-nurture-select-all]') : null;
    var programControl = form ? form.querySelector('[data-nurture-program-control]') : null;
    var selectedBar = form ? form.querySelector('[data-nurture-selected-action-bar]') : null;
    var selectionHint = form ? form.querySelector('[data-nurture-selection-hint]') : null;
    var planDrawer = document.querySelector('[data-care-plan-drawer]');
    var planBackdrop = document.querySelector('[data-care-plan-backdrop]');
    var planPreset = document.querySelector('[data-care-plan-preset]');

    function selectedContactIds() {
        var ids = {};
        if (!form) {
            return [];
        }
        form.querySelectorAll('[data-nurture-check]').forEach(function (checkbox) {
            if (checkbox.checked && !checkbox.disabled) {
                ids[checkbox.value] = true;
            }
        });
        return Object.keys(ids);
    }

    function fillSelectedInputs(container, selectedIds) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        selectedIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'contact_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    function syncPlanSelectionInputs() {
        var selectedIds = selectedContactIds();
        fillSelectedInputs(document.querySelector('[data-care-plan-selected-inputs]'), selectedIds);
        fillSelectedInputs(document.querySelector('[data-care-plan-create-selected-inputs]'), selectedIds);
        document.querySelectorAll('[data-care-plan-enroll-form] button[type="submit"]').forEach(function (button) {
            button.disabled = selectedIds.length === 0 || button.hasAttribute('data-force-disabled');
        });
    }

    function openPlanDrawer() {
        syncPlanSelectionInputs();
        if (planDrawer) {
            planDrawer.hidden = false;
        }
        if (planBackdrop) {
            planBackdrop.hidden = false;
        }
    }

    function closePlanDrawer() {
        if (planDrawer) {
            planDrawer.hidden = true;
        }
        if (planBackdrop) {
            planBackdrop.hidden = true;
        }
    }

    function updateProgramControl() {
        if (!programControl || !actionSelect) {
            return;
        }
        var enrolling = actionSelect.value === 'enroll';
        programControl.hidden = !enrolling;
        if (!enrolling) {
            var programSelect = programControl.querySelector('select');
            if (programSelect) {
                programSelect.value = '';
            }
        }
    }

    function updateBulkState() {
        var selectedIds = selectedContactIds();
        var selectedCount = selectedIds.length;
        if (countNode) {
            countNode.textContent = String(selectedCount);
        }
        if (applyButton) {
            applyButton.disabled = !canWrite || selectedCount === 0;
        }
        if (selectedBar) {
            selectedBar.hidden = !canWrite || selectedCount === 0;
        }
        if (selectionHint) {
            selectionHint.hidden = !canWrite || selectedCount > 0;
        }
        if (selectAll) {
            var checkboxes = Array.prototype.slice.call(form.querySelectorAll('[data-nurture-check]:not(:disabled)'));
            var checked = checkboxes.filter(function (checkbox) { return checkbox.checked; });
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }
        updateProgramControl();
        syncPlanSelectionInputs();
    }

    if (form) {
        form.querySelectorAll('[data-nurture-check]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                form.querySelectorAll('[data-nurture-check][value="' + checkbox.value + '"]').forEach(function (matchingCheckbox) {
                    if (!matchingCheckbox.disabled) {
                        matchingCheckbox.checked = checkbox.checked;
                    }
                });
                updateBulkState();
            });
        });
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                form.querySelectorAll('[data-nurture-check]:not(:disabled)').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                updateBulkState();
            });
        }
        if (actionSelect) {
            actionSelect.addEventListener('change', updateBulkState);
        }
        form.querySelectorAll('[data-nurture-enroll-contact]').forEach(function (button) {
            button.addEventListener('click', function () {
                var contactId = button.value;
                form.querySelectorAll('[data-nurture-check]:not(:disabled)').forEach(function (checkbox) {
                    checkbox.checked = checkbox.value === contactId;
                });
                if (actionSelect) {
                    actionSelect.value = 'enroll';
                }
                updateBulkState();
                openPlanDrawer();
            });
        });
        updateBulkState();
    } else {
        syncPlanSelectionInputs();
    }

    document.querySelectorAll('[data-care-plan-open]').forEach(function (button) {
        button.addEventListener('click', openPlanDrawer);
    });
    document.querySelectorAll('[data-care-plan-close]').forEach(function (button) {
        button.addEventListener('click', closePlanDrawer);
    });
    if (planBackdrop) {
        planBackdrop.addEventListener('click', closePlanDrawer);
    }
    if (planPreset) {
        planPreset.addEventListener('change', function () {
            var option = planPreset.options[planPreset.selectedIndex];
            var drawerForm = planPreset.closest('form');
            if (!option || !drawerForm) {
                return;
            }
            var nameInput = drawerForm.querySelector('input[name="plan_name"]');
            var cadenceSelect = drawerForm.querySelector('select[name="plan_cadence"]');
            var firstTouchSelect = drawerForm.querySelector('select[name="plan_first_touch_delay_days"]');
            var channelSelect = drawerForm.querySelector('select[name="plan_preferred_channel"]');
            var touchTypeSelect = drawerForm.querySelector('select[name="plan_default_touch_type"]');
            var descriptionInput = drawerForm.querySelector('textarea[name="plan_description"]');
            var guidanceInput = drawerForm.querySelector('textarea[name="plan_touch_guidance"]');
            if (nameInput && nameInput.value.trim() === '') {
                nameInput.value = option.value;
            }
            if (cadenceSelect && option.dataset.cadence) {
                cadenceSelect.value = option.dataset.cadence;
            }
            if (firstTouchSelect) {
                firstTouchSelect.value = option.dataset.firstTouchDelay || '';
            }
            if (channelSelect && option.dataset.channel) {
                channelSelect.value = option.dataset.channel;
            }
            if (touchTypeSelect && option.dataset.touchType) {
                touchTypeSelect.value = option.dataset.touchType;
            }
            if (descriptionInput && descriptionInput.value.trim() === '') {
                descriptionInput.value = option.dataset.description || '';
            }
            if (guidanceInput && guidanceInput.value.trim() === '') {
                guidanceInput.value = option.dataset.guidance || '';
            }
        });
        planPreset.dispatchEvent(new Event('change'));
    }

});
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_NURTURE, 'How to use Customer Care', $nurtureGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
