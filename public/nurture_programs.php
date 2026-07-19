<?php
/**
 * Advanced follow-up plan management.
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
$canManageNurture = Authorization::can('nurture.manage', $user);
$message = '';
$error = '';
$submittedAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canManageNurture) {
            throw new RuntimeException('You do not have permission to manage follow-up plans.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token');
        }

        $action = Security::sanitizeInput((string) ($_POST['action'] ?? ''), 'string');
        $submittedAction = $action;
        if ($action === 'create_program') {
            $nurture->createProgram([
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'program_type' => $_POST['program_type'] ?? 'customer_success',
                'cadence' => $_POST['cadence'] ?? 'monthly',
                'first_touch_delay_days' => $_POST['first_touch_delay_days'] ?? null,
                'preferred_channel' => $_POST['preferred_channel'] ?? 'task',
                'default_touch_type' => $_POST['default_touch_type'] ?? 'check_in',
                'touch_guidance' => $_POST['touch_guidance'] ?? '',
                'status' => 'active',
                'linked_campaign_id' => $_POST['linked_campaign_id'] ?? null,
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            $message = 'Follow-up plan created.';
        } elseif ($action === 'update_program') {
            $nurture->updateProgram((int) ($_POST['program_id'] ?? 0), [
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'program_type' => $_POST['program_type'] ?? 'customer_success',
                'cadence' => $_POST['cadence'] ?? 'monthly',
                'first_touch_delay_days' => $_POST['first_touch_delay_days'] ?? null,
                'preferred_channel' => $_POST['preferred_channel'] ?? 'task',
                'default_touch_type' => $_POST['default_touch_type'] ?? 'check_in',
                'touch_guidance' => $_POST['touch_guidance'] ?? '',
                'status' => $_POST['status'] ?? 'active',
                'linked_campaign_id' => $_POST['linked_campaign_id'] ?? null,
            ]);
            $message = 'Follow-up plan updated.';
        } elseif ($action === 'delete_program') {
            $result = $nurture->deleteProgram((int) ($_POST['program_id'] ?? 0));
            $message = !empty($result['deleted'])
                ? 'Follow-up plan deleted.'
                : 'Follow-up plan archived because it has customer history.';
        } else {
            throw new RuntimeException('Choose a valid follow-up plan action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$statusView = Security::sanitizeInput((string) ($_GET['status'] ?? 'available'), 'string');
if (!in_array($statusView, ['available', 'archived', 'all'], true)) {
    $statusView = 'available';
}
$programFilters = match ($statusView) {
    'archived' => ['archived_only' => true],
    'all' => [],
    default => ['exclude_archived' => true],
};
$programs = $nurture->listProgramsWithStats($programFilters);
$allPrograms = $nurture->listProgramsWithStats();
$campaigns = Database::query(
    "SELECT id, name, status FROM campaigns WHERE workspace_id = ? OR workspace_id IS NULL ORDER BY updated_at DESC LIMIT 100",
    [(int) (WorkspaceContext::currentWorkspaceId() ?? 0)]
);
$campaignNames = [];
foreach ($campaigns as $campaign) {
    $campaignNames[(int) $campaign['id']] = (string) $campaign['name'];
}

$labelize = static function (string $value): string {
    return [
        'customer_success' => 'Customer Care',
        'expansion' => 'Value Growth',
        'risk_recovery' => 'Needs attention',
        'at_risk' => 'Needs attention',
        'inactive' => 'Quiet customer',
        'needs_touch' => 'Check-in due',
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
$firstTouchLabel = static function (array $program) use ($firstTouchOptions): string {
    $days = $program['first_touch_delay_days'] ?? null;
    if ($days === null || $days === '') {
        return 'Use rhythm';
    }

    return $firstTouchOptions[(string) (int) $days] ?? ((int) $days . ' days');
};
$preferenceSummary = static function (array $program) use ($labelize, $firstTouchLabel, $planTouchTypeLabel): string {
    return $labelize((string) ($program['cadence'] ?? 'monthly'))
        . ' | First: ' . $firstTouchLabel($program)
        . ' | ' . $labelize((string) ($program['preferred_channel'] ?? 'task'))
        . ' | ' . $planTouchTypeLabel((string) ($program['default_touch_type'] ?? 'check_in'));
};
$statusUrl = static function (string $status): string {
    return $status === 'available' ? 'nurture_programs.php' : 'nurture_programs.php?status=' . urlencode($status);
};
$editProgramId = $canManageNurture ? max(0, (int) ($_GET['edit'] ?? 0)) : 0;
$showCreateForm = $canManageNurture && (isset($_GET['new']) || ($error !== '' && $submittedAction === 'create_program'));
$activeProgramCount = count(array_filter($allPrograms, static fn(array $program): bool => (string) ($program['status'] ?? '') === 'active'));
$availableProgramCount = count(array_filter($allPrograms, static fn(array $program): bool => (string) ($program['status'] ?? '') !== 'archived'));
$archivedProgramCount = count(array_filter($allPrograms, static fn(array $program): bool => (string) ($program['status'] ?? '') === 'archived'));
$activeEnrollmentCount = array_sum(array_map(static fn(array $program): int => (int) ($program['active_enrollment_count'] ?? 0), $allPrograms));
$linkedCampaignCount = count(array_filter($allPrograms, static fn(array $program): bool => (int) ($program['linked_campaign_id'] ?? 0) > 0));
$listTitle = match ($statusView) {
    'archived' => 'Archived Plans',
    'all' => 'All Plans',
    default => 'Available Plans',
};
$listCopy = match ($statusView) {
    'archived' => 'Archived plans are preserved for customer history and hidden from new enrollments.',
    'all' => 'Review every follow-up plan, including archived history.',
    default => 'Use these plans when paying customers need the same check-in rhythm.',
};
$emptyCopy = match ($statusView) {
    'archived' => 'No archived follow-up plans.',
    'all' => 'No follow-up plans yet. Use New Follow-up Plan to create the first reusable check-in path.',
    default => 'No available follow-up plans yet. Use New Follow-up Plan to create the first reusable check-in path.',
};

$pageTitle = 'Advanced Follow-up Plans - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .program-shell { display:grid; gap:1rem; }
    .program-toolbar { display:flex; gap:.65rem; flex-wrap:wrap; align-items:center; justify-content:space-between; }
    .program-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
    .program-form .full { grid-column:1 / -1; }
    .program-form input, .program-form select, .program-form textarea { width:100%; padding:.65rem .7rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
    .program-form label { display:block; font-size:.75rem; font-weight:700; color:#475569; margin-bottom:.25rem; text-transform:uppercase; }
    .program-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem; }
    .program-summary-tile { display:flex; gap:.75rem; align-items:center; border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:1rem; }
    .program-summary-tile i { display:grid; place-items:center; width:38px; height:38px; border-radius:8px; background:#eef2ff; color:#384ad7; }
    .program-summary-tile span { display:block; color:#64748b; font-size:.75rem; font-weight:700; text-transform:uppercase; }
    .program-summary-tile strong { display:block; margin-top:.15rem; font-size:1.4rem; line-height:1; color:#0f172a; }
    .program-list { display:grid; gap:.7rem; }
    .program-row { display:grid; grid-template-columns:1.35fr .72fr .72fr .85fr .68fr auto; gap:.9rem; align-items:center; padding:1rem; border:1px solid #e2e8f0; border-radius:8px; background:#fff; }
    .program-meta { color:#64748b; font-size:.84rem; margin-top:.2rem; }
    .program-pill { display:inline-flex; width:max-content; align-items:center; padding:.28rem .55rem; border-radius:999px; background:#eef2ff; color:#384ad7; font-size:.76rem; font-weight:700; }
    .program-tabs { display:flex; gap:.45rem; flex-wrap:wrap; align-items:center; }
    .program-tabs a { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border:1px solid #dbe3ef; border-radius:8px; color:#334155; text-decoration:none; font-size:.84rem; font-weight:800; background:#fff; }
    .program-tabs a.active { border-color:#384ad7; color:#1d4ed8; background:#eef2ff; }
    .program-actions { display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end; }
    .program-actions form { margin:0; }
    .program-action-note { width:100%; color:#64748b; font-size:.74rem; text-align:right; }
    .program-action-btn { display:inline-flex; align-items:center; justify-content:center; min-height:32px; padding:.4rem .58rem; border:1px solid #dbe3ef; border-radius:8px; background:#fff; color:#334155; font-size:.78rem; font-weight:800; text-decoration:none; cursor:pointer; }
    .program-action-btn:hover { border-color:#93c5fd; color:#1d4ed8; }
    .program-action-btn.danger { border-color:#fecaca; color:#991b1b; background:#fff5f5; }
    .program-edit-card { border:1px solid #bfdbfe; background:#f8fbff; border-radius:8px; padding:1rem; }
    @media (max-width: 1100px) { .program-row { grid-template-columns:1fr 1fr; } .program-actions { justify-content:flex-start; } }
    @media (max-width: 900px) { .program-summary { grid-template-columns:1fr; } .program-form, .program-row { grid-template-columns:1fr; } }
</style>

<div class="page-premium">
    <div class="container program-shell">
        <div class="page-header">
            <div>
                <h1>Advanced Follow-up Plans</h1>
                <p>Create reusable check-in plans for paying customers.</p>
            </div>
            <div style="display:flex;gap:.65rem;flex-wrap:wrap;">
                <a class="btn-premium-secondary" href="nurture.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Customer Care
                </a>
                <?php if ($canManageNurture && !$showCreateForm): ?>
                    <a class="btn-premium-primary" href="nurture_programs.php?new=1#new-program">
                        <i class="fas fa-plus"></i>
                        New Follow-up Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?><div class="content-card" style="background:#ecfdf5;border-color:#86efac;color:#166534;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="program-summary" aria-label="Follow-up plan summary">
            <article class="program-summary-tile">
                <i class="fas fa-list-check"></i>
                <div><span>Available Plans</span><strong><?php echo (int) $availableProgramCount; ?></strong></div>
            </article>
            <article class="program-summary-tile">
                <i class="fas fa-user-check"></i>
                <div><span>Customers In Plans</span><strong><?php echo (int) $activeEnrollmentCount; ?></strong></div>
            </article>
            <article class="program-summary-tile">
                <i class="fas fa-bullhorn"></i>
                <div><span>Campaign Links</span><strong><?php echo (int) $linkedCampaignCount; ?></strong></div>
            </article>
        </section>

        <nav class="program-tabs" aria-label="Follow-up plan views">
            <a class="<?php echo $statusView === 'available' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($statusUrl('available')); ?>">Available <?php echo (int) $availableProgramCount; ?></a>
            <a class="<?php echo $statusView === 'archived' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($statusUrl('archived')); ?>">Archived <?php echo (int) $archivedProgramCount; ?></a>
            <a class="<?php echo $statusView === 'all' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($statusUrl('all')); ?>">All <?php echo count($allPrograms); ?></a>
        </nav>

        <?php if ($showCreateForm): ?>
            <div id="new-program" class="content-card">
                <div class="program-toolbar" style="margin-bottom:1rem;">
                    <div>
                        <h2 style="margin:0;">New Follow-up Plan</h2>
                        <p style="margin:.25rem 0 0;color:#64748b;">Build a reusable check-in rhythm for paying customers.</p>
                    </div>
                    <a class="btn-premium-secondary" href="nurture_programs.php">Cancel</a>
                </div>
                <form method="POST" class="program-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create_program">
                    <div>
                        <label>Plan name</label>
                        <input name="name" placeholder="Plan name" required>
                    </div>
                    <div>
                        <label>Plan type</label>
                        <select name="program_type">
                            <option value="customer_success">Customer care</option>
                            <option value="expansion">Value growth</option>
                            <option value="risk_recovery">Risk recovery</option>
                        </select>
                    </div>
                    <div>
                        <label>Follow-up rhythm</label>
                        <select name="cadence">
                            <?php foreach (Nurture::CADENCES as $cadence): ?><option value="<?php echo htmlspecialchars($cadence); ?>"><?php echo htmlspecialchars($labelize($cadence)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>First check-in</label>
                        <select name="first_touch_delay_days">
                            <?php foreach ($firstTouchOptions as $value => $label): ?><option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Channel</label>
                        <select name="preferred_channel">
                            <?php foreach ($planChannelOptions as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Action</label>
                        <select name="default_touch_type">
                            <?php foreach ($planTouchTypeOptions as $touchType): ?><option value="<?php echo htmlspecialchars($touchType); ?>"><?php echo htmlspecialchars($planTouchTypeLabel($touchType)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Linked campaign</label>
                        <select name="linked_campaign_id">
                            <option value="">No linked campaign</option>
                            <?php foreach ($campaigns as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label>Purpose</label>
                        <textarea name="description" rows="3" placeholder="What this plan helps the customer do"></textarea>
                    </div>
                    <div class="full">
                        <label>Guidance</label>
                        <textarea name="touch_guidance" rows="2" placeholder="How to approach the next touch"></textarea>
                    </div>
                    <div class="full" style="display:flex;justify-content:flex-end;">
                        <button class="btn-premium-primary" type="submit">Create Follow-up Plan</button>
                    </div>
                </form>
            </div>
        <?php elseif ($canManageNurture): ?>
            <div class="content-card program-toolbar">
                <div>
                    <h2 style="margin:0;">Plans</h2>
                    <p style="margin:.25rem 0 0;color:#64748b;">Use this page for advanced setup. Most plan work can happen from Customer Care.</p>
                </div>
                <a class="btn-premium-primary" href="nurture_programs.php?new=1#new-program">
                    <i class="fas fa-plus"></i>
                    New Follow-up Plan
                </a>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="program-toolbar" style="margin-bottom:1rem;">
                <div>
                    <h2 style="margin:0;"><?php echo htmlspecialchars($listTitle); ?></h2>
                    <p style="margin:.25rem 0 0;color:#64748b;"><?php echo htmlspecialchars($listCopy); ?></p>
                </div>
                <span class="program-pill"><?php echo count($programs); ?> shown</span>
            </div>

            <div class="program-list">
                <?php foreach ($programs as $program): ?>
                    <?php
                        $programId = (int) ($program['id'] ?? 0);
                        $enrollmentCount = (int) ($program['enrollment_count'] ?? 0);
                        $programStatus = (string) ($program['status'] ?? 'active');
                        $editUrl = 'nurture_programs.php?status=' . urlencode($statusView) . '&edit=' . $programId . '#program-' . $programId;
                    ?>
                    <div class="program-row" id="program-<?php echo $programId; ?>" data-follow-up-plan-row>
                        <div>
                            <strong><?php echo htmlspecialchars((string) $program['name']); ?></strong>
                            <div class="program-meta"><?php echo htmlspecialchars($preferenceSummary($program)); ?></div>
                            <?php if (!empty($program['description'])): ?>
                                <div class="program-meta"><?php echo htmlspecialchars((string) $program['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="program-pill"><?php echo htmlspecialchars($labelize((string) $program['program_type'])); ?></span>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars($labelize((string) $program['cadence'])); ?></strong>
                            <div class="program-meta"><?php echo htmlspecialchars($labelize($programStatus)); ?></div>
                        </div>
                        <div>
                            <?php $campaignId = (int) ($program['linked_campaign_id'] ?? 0); ?>
                            <strong><?php echo $campaignId > 0 ? htmlspecialchars($campaignNames[$campaignId] ?? 'Linked campaign') : 'No campaign'; ?></strong>
                            <div class="program-meta">Campaign link</div>
                        </div>
                        <div>
                            <strong><?php echo (int) ($program['active_enrollment_count'] ?? 0); ?> active</strong>
                            <div class="program-meta"><?php echo $enrollmentCount; ?> total enrollments</div>
                        </div>
                        <?php if ($canManageNurture): ?>
                            <div class="program-actions">
                                <a class="program-action-btn" href="<?php echo htmlspecialchars($editUrl); ?>" data-follow-up-plan-edit>Edit</a>
                                <?php if ($enrollmentCount === 0): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this unused follow-up plan?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="action" value="delete_program">
                                        <input type="hidden" name="program_id" value="<?php echo $programId; ?>">
                                        <button class="program-action-btn danger" type="submit" data-follow-up-plan-delete>Delete</button>
                                    </form>
                                <?php elseif ($programStatus !== 'archived'): ?>
                                    <form method="POST" onsubmit="return confirm('Archive this follow-up plan? Customer history will be preserved.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="action" value="delete_program">
                                        <input type="hidden" name="program_id" value="<?php echo $programId; ?>">
                                        <button class="program-action-btn" type="submit" data-follow-up-plan-archive>Archive</button>
                                    </form>
                                    <span class="program-action-note">History preserved</span>
                                <?php else: ?>
                                    <span class="program-pill">Archived</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canManageNurture && $editProgramId === $programId): ?>
                        <div class="program-edit-card" data-follow-up-plan-edit-panel>
                            <form method="POST" class="program-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="action" value="update_program">
                                <input type="hidden" name="program_id" value="<?php echo $programId; ?>">
                                <div>
                                    <label>Plan name</label>
                                    <input name="name" value="<?php echo htmlspecialchars((string) ($program['name'] ?? '')); ?>" required>
                                </div>
                                <div>
                                    <label>Plan type</label>
                                    <select name="program_type">
                                        <?php foreach (Nurture::PROGRAM_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo (string) ($program['program_type'] ?? '') === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Follow-up rhythm</label>
                                    <select name="cadence">
                                        <?php foreach (Nurture::CADENCES as $cadence): ?><option value="<?php echo htmlspecialchars($cadence); ?>" <?php echo (string) ($program['cadence'] ?? '') === $cadence ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($cadence)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>First check-in</label>
                                    <select name="first_touch_delay_days">
                                        <?php foreach ($firstTouchOptions as $value => $label): ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo (string) ($program['first_touch_delay_days'] ?? '') === (string) $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Channel</label>
                                    <select name="preferred_channel">
                                        <?php foreach ($planChannelOptions as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>" <?php echo (string) ($program['preferred_channel'] ?? 'task') === $channel ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Action</label>
                                    <select name="default_touch_type">
                                        <?php foreach ($planTouchTypeOptions as $touchType): ?><option value="<?php echo htmlspecialchars($touchType); ?>" <?php echo (string) ($program['default_touch_type'] ?? 'check_in') === $touchType ? 'selected' : ''; ?>><?php echo htmlspecialchars($planTouchTypeLabel($touchType)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Status</label>
                                    <select name="status">
                                        <?php foreach (Nurture::PROGRAM_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $programStatus === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Linked campaign</label>
                                    <select name="linked_campaign_id">
                                        <option value="">No linked campaign</option>
                                        <?php foreach ($campaigns as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo (int) ($program['linked_campaign_id'] ?? 0) === (int) $campaign['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="full">
                                    <label>Purpose</label>
                                    <textarea name="description" rows="3" placeholder="What this plan helps the customer do"><?php echo htmlspecialchars((string) ($program['description'] ?? '')); ?></textarea>
                                </div>
                                <div class="full">
                                    <label>Guidance</label>
                                    <textarea name="touch_guidance" rows="2" placeholder="How to approach the next touch"><?php echo htmlspecialchars((string) ($program['touch_guidance'] ?? '')); ?></textarea>
                                </div>
                                <div class="full" style="display:flex;gap:.55rem;justify-content:flex-end;flex-wrap:wrap;">
                                    <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($statusUrl($statusView)); ?>">Cancel</a>
                                    <button class="btn-premium-primary" type="submit">Save Follow-up Plan</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($programs)): ?>
                    <div style="color:#64748b;"><?php echo htmlspecialchars($emptyCopy); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
