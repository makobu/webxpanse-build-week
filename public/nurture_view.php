<?php
/**
 * Nurture profile detail.
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
use CRM\Services\MarketingMarketplaceGateService;
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
$canCreateTasks = Authorization::can('tasks.write', $user);
$canReadMarketing = Authorization::can('marketing.read', $user) && (new MarketingMarketplaceGateService())->canRun($user);
$contactId = (int) ($_GET['contact_id'] ?? 0);
if ($contactId <= 0) {
    header('Location: nurture.php');
    exit;
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token');
        }
        if (!$canWriteNurture) {
            throw new RuntimeException('You do not have permission to update nurture records.');
        }

        $action = Security::sanitizeInput((string) ($_POST['action'] ?? ''), 'string');
        if ($action === 'update_profile') {
            $nurture->updateProfile($contactId, [
                'lifecycle_lane' => $_POST['lifecycle_lane'] ?? '',
                'nurture_status' => $_POST['nurture_status'] ?? '',
                'temperature' => $_POST['temperature'] ?? '',
                'cadence' => $_POST['cadence'] ?? '',
                'next_touch_at' => $_POST['next_touch_at'] ?? null,
                'next_touch_reason' => $_POST['next_touch_reason'] ?? '',
            ]);
            $message = 'Customer care profile updated.';
        } elseif ($action === 'refresh_profile') {
            $nurture->refreshProfile($contactId);
            $message = 'Customer signals refreshed.';
        } elseif ($action === 'record_check_in') {
            $nurture->recordCheckIn($contactId, [
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            $message = 'Check-in marked complete.';
        } elseif ($action === 'schedule_checkin') {
            $nurture->updateProfile($contactId, [
                'nurture_status' => 'active',
                'next_touch_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'next_touch_reason' => 'Scheduled customer check-in from Customer Care.',
            ]);
            $message = 'Next check-in scheduled.';
        } elseif ($action === 'create_task') {
            if (!$canCreateTasks) {
                throw new RuntimeException('Task creation requires task write access.');
            }
            $taskId = $nurture->createFollowUpTask($contactId, [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'due_date' => $_POST['due_date'] ?? null,
                'created_by' => (int) ($user['id'] ?? 0),
                'actor_user_id' => (int) ($user['id'] ?? 0),
            ]);
            $message = 'Check-in task created (#' . $taskId . ').';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profile = $nurture->getOrCreateProfile($contactId);
$touchpoints = $nurture->getTouchpoints($contactId);
$openTasks = $nurture->getOpenTasks($contactId);
$enrollment = $nurture->getActiveEnrollment($contactId);
$readiness = $nurture->getTransitionReadinessForContact($contactId, false);
$handoffLineage = $canReadMarketing ? $nurture->getMarketingHandoffLineage($contactId, 6) : [];
$purchase = $profile['purchase_summary'] ?? [];
$brief = $nurture->careBrief($profile);
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
    ][$value] ?? ucwords(str_replace('_', ' ', $value));
};

$pageTitle = 'Customer Care - ' . (string) ($profile['contact_name'] ?? 'Contact') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .care-detail-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:1rem; align-items:start; }
    .care-summary { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; margin-bottom:1rem; padding:1.1rem; }
    .care-summary h1 { margin:0; color:#0f172a; font-size:1.45rem; letter-spacing:0; }
    .care-summary p { margin:.3rem 0 0; color:#475569; line-height:1.45; }
    .care-summary-actions { display:flex; gap:.55rem; flex-wrap:wrap; justify-content:flex-end; }
    .care-summary-actions form { margin:0; }
    .care-section { margin-bottom:1rem; padding:1rem; }
    .care-section h2, .care-section h3 { margin:0; color:#0f172a; font-size:1.05rem; letter-spacing:0; }
    .care-section p { color:#334155; line-height:1.55; }
    .care-meta-list { display:grid; gap:.45rem; margin-top:.75rem; color:#64748b; font-size:.88rem; }
    .care-meta-list strong { color:#0f172a; }
    .nurture-pill { display:inline-flex; padding:.25rem .55rem; border-radius:999px; font-size:.78rem; font-weight:700; background:#eef2ff; color:#384ad7; }
    .nurture-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
    .nurture-form label { display:block; font-size:.78rem; font-weight:700; color:#475569; margin-bottom:.25rem; }
    .nurture-form select, .nurture-form input, .nurture-form textarea { width:100%; padding:.65rem; border:1px solid #cbd5e1; border-radius:8px; }
    .nurture-panel-header { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; margin-bottom:.75rem; }
    .nurture-panel-header h3 { margin:0; }
    .nurture-readiness-card { border:1px solid #dbe3ef; border-radius:8px; padding:.85rem; background:#f8fafc; }
    .nurture-readiness-card.ready, .nurture-readiness-card.active { border-color:#bbf7d0; background:#f0fdf4; }
    .nurture-readiness-card.not_ready { border-color:#fed7aa; background:#fff7ed; }
    .nurture-lineage-list { display:grid; gap:.7rem; }
    .nurture-lineage-card { border:1px solid #e2e8f0; border-radius:8px; padding:.8rem; background:#fff; }
    .nurture-lineage-meta { display:flex; gap:.45rem; flex-wrap:wrap; color:#64748b; font-size:.82rem; margin-top:.3rem; }
    .nurture-form-actions { grid-column:1/-1; display:flex; gap:.5rem; flex-wrap:wrap; justify-content:flex-end; }
    .timeline-row { padding:.8rem 0; border-bottom:1px solid #e2e8f0; }
    details.care-section > summary { cursor:pointer; color:#0f172a; font-weight:800; list-style:none; }
    details.care-section > summary::-webkit-details-marker { display:none; }
    @media (max-width:900px) { .care-detail-grid, .nurture-form { grid-template-columns:1fr; } .care-summary { display:grid; } .care-summary-actions { justify-content:flex-start; } .nurture-panel-header { display:block; } }
</style>

<div class="page-premium">
    <div class="container">
        <div class="content-card care-summary">
            <div>
                <h1><?php echo htmlspecialchars((string) $profile['contact_name']); ?></h1>
                <p><?php echo htmlspecialchars((string) ($profile['email'] ?? '')); ?><?php echo !empty($profile['company']) ? ' &middot; ' . htmlspecialchars((string) $profile['company']) : ''; ?></p>
                <p><strong><?php echo htmlspecialchars((string) $brief['status_sentence']); ?></strong></p>
            </div>
            <div class="care-summary-actions">
                <a href="nurture.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Customer Care</a>
                <?php if ($canWriteNurture): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="record_check_in">
                        <button class="btn-premium-primary" type="submit" data-care-check-in>Check in</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="create_task">
                        <input type="hidden" name="title" value="<?php echo htmlspecialchars((string) $brief['task_title']); ?>">
                        <input type="hidden" name="description" value="<?php echo htmlspecialchars((string) $brief['task_description']); ?>">
                        <input type="hidden" name="due_date" value="<?php echo !empty($profile['next_touch_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $profile['next_touch_at']))) : ''; ?>">
                        <button class="btn-premium-secondary" type="submit" <?php echo !$canCreateTasks ? 'disabled' : ''; ?>>Create task</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="schedule_checkin">
                        <button class="btn-premium-secondary" type="submit">Schedule</button>
                    </form>
                <?php endif; ?>
                <a href="contact_view.php?id=<?php echo (int) $contactId; ?>" class="btn-premium-secondary"><i class="fas fa-address-card"></i> Contact</a>
            </div>
        </div>

        <?php if ($message): ?><div class="content-card" style="margin-bottom:1rem;background:#ecfdf5;border-color:#86efac;color:#166534;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="margin-bottom:1rem;background:#fef2f2;border-color:#fca5a5;color:#991b1b;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="care-detail-grid">
            <div>
                <section class="content-card care-section">
                    <div class="nurture-panel-header">
                        <div>
                            <h2>Why this customer is here</h2>
                            <p style="margin:.25rem 0 0;color:#64748b;">Customer Care starts after purchase evidence.</p>
                        </div>
                        <span class="nurture-pill"><?php echo htmlspecialchars((string) ($readiness['label'] ?? 'Needs purchase evidence')); ?></span>
                    </div>
                    <div class="nurture-readiness-card <?php echo htmlspecialchars((string) ($readiness['status'] ?? 'not_ready')); ?>">
                        <p style="margin:0;color:#0f172a;font-weight:700;"><?php echo htmlspecialchars((string) ($readiness['reason'] ?? 'Review the customer care transition.')); ?></p>
                        <p style="margin:.35rem 0 0;color:#64748b;"><?php echo htmlspecialchars((string) ($readiness['next_step'] ?? 'Create a task before any customer-facing message.')); ?></p>
                    </div>
                </section>

                <section class="content-card care-section">
                    <h2>Suggested next step</h2>
                    <p style="margin:.5rem 0 0;color:#0f172a;font-weight:700;"><?php echo htmlspecialchars((string) $brief['suggested_next_step']); ?></p>
                    <div class="care-meta-list">
                        <div><strong>Why:</strong> <?php echo htmlspecialchars((string) $brief['why_now']); ?></div>
                        <div><strong>Next check-in:</strong> <?php echo htmlspecialchars((string) $brief['next_check_in_label']); ?></div>
                    </div>
                </section>

                <section class="content-card care-section">
                    <h2>Purchase</h2>
                    <p style="margin:.25rem 0;color:#0f172a;font-weight:700;"><?php echo htmlspecialchars((string) ($purchase['title'] ?? 'Customer purchase')); ?></p>
                    <p style="margin:.25rem 0;color:#64748b;">
                        <?php echo htmlspecialchars((string) ($profile['entry_label'] ?? $labelize((string) ($profile['entry_source'] ?? '')))); ?>
                        <?php if (!empty($profile['entry_at'])): ?> &middot; <?php echo htmlspecialchars(date('M j, Y', strtotime((string) $profile['entry_at']))); ?><?php endif; ?>
                    </p>
                    <?php if (!empty($purchase['number']) || isset($purchase['amount']) || !empty($purchase['status'])): ?>
                        <p style="margin:.25rem 0;color:#64748b;">
                            <?php if (!empty($purchase['number'])): ?><?php echo htmlspecialchars((string) $purchase['number']); ?> &middot; <?php endif; ?>
                            <?php echo isset($purchase['amount']) ? htmlspecialchars(trim((string) ($purchase['currency'] ?? '') . ' ' . number_format((float) $purchase['amount'], 2))) : htmlspecialchars((string) ($purchase['status'] ?? '')); ?>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="content-card care-section">
                    <h2>Follow-up plan</h2>
                    <?php if (!empty($enrollment)): ?>
                        <p style="margin:.5rem 0 0;color:#0f172a;font-weight:700;"><?php echo htmlspecialchars((string) $enrollment['program_name']); ?></p>
                        <p style="margin:.25rem 0 0;color:#64748b;"><?php echo !empty($enrollment['next_touch_at']) ? 'Next plan touch: ' . htmlspecialchars(date('M j, Y', strtotime((string) $enrollment['next_touch_at']))) : 'Active plan'; ?></p>
                    <?php else: ?>
                        <p style="margin:.5rem 0 0;color:#64748b;">No follow-up plan yet. Suggested: <?php echo htmlspecialchars((string) $brief['suggested_plan']); ?>.</p>
                    <?php endif; ?>
                </section>

                <details class="content-card care-section">
                    <summary>Where this came from</summary>
                    <div class="nurture-panel-header">
                        <div>
                            <h3 style="margin-top:.75rem;">Source context</h3>
                            <p style="margin:.25rem 0 0;color:#64748b;">Marketing and sales context carried into customer care.</p>
                        </div>
                        <?php if ($canReadMarketing): ?><a class="btn-premium-secondary" href="marketing_handoffs.php?contact_id=<?php echo (int) $contactId; ?>">Open Marketing Handoffs</a><?php endif; ?>
                    </div>
                    <?php if (!$canReadMarketing): ?>
                        <p style="color:#64748b;">Marketing handoff context requires Marketing access.</p>
                    <?php elseif (empty($handoffLineage)): ?>
                        <p style="color:#64748b;">No outreach handoffs are linked to this customer yet.</p>
                    <?php else: ?>
                        <div class="nurture-lineage-list">
                            <?php foreach ($handoffLineage as $handoff): ?>
                                <article class="nurture-lineage-card">
                                    <strong><?php echo htmlspecialchars((string) ($handoff['lineage_title'] ?? 'Marketing handoff')); ?></strong>
                                    <div class="nurture-lineage-meta">
                                        <span><?php echo htmlspecialchars($labelize((string) ($handoff['status'] ?? 'new'))); ?></span>
                                        <span><?php echo htmlspecialchars((string) ($handoff['assigned_to_email'] ?? 'Unassigned')); ?></span>
                                        <span>CRM <?php echo htmlspecialchars($labelize((string) ($handoff['crm_sync_status'] ?? 'pending'))); ?></span>
                                        <?php if (!empty($handoff['task_id'])): ?><span>Task #<?php echo (int) $handoff['task_id']; ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($handoff['feedback_reason']) || !empty($handoff['handoff_note'])): ?>
                                        <p style="margin:.45rem 0 0;color:#334155;"><?php echo htmlspecialchars((string) ($handoff['feedback_reason'] ?? $handoff['handoff_note'])); ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>

                <section class="content-card care-section">
                    <h2>History</h2>
                    <?php if (empty($touchpoints)): ?><p style="color:#64748b;">No check-ins yet.</p><?php endif; ?>
                    <?php foreach ($touchpoints as $touchpoint): ?>
                        <div class="timeline-row">
                            <div style="font-weight:700;"><?php echo htmlspecialchars((string) $touchpoint['subject']); ?></div>
                            <div style="font-size:.84rem;color:#64748b;">
                                <?php echo htmlspecialchars($labelize((string) $touchpoint['touch_type'])); ?> &middot;
                                <?php echo htmlspecialchars($labelize((string) $touchpoint['status'])); ?> &middot;
                                <?php echo !empty($touchpoint['scheduled_at']) ? htmlspecialchars(date('M j, Y', strtotime((string) $touchpoint['scheduled_at']))) : htmlspecialchars(date('M j, Y', strtotime((string) $touchpoint['created_at']))); ?>
                            </div>
                            <?php if (!empty($touchpoint['notes'])): ?><div style="margin-top:.35rem;color:#334155;"><?php echo htmlspecialchars((string) $touchpoint['notes']); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            </div>

            <div>
                <?php if ($canWriteNurture): ?>
                    <details class="content-card care-section" data-care-settings-panel>
                        <summary>Care settings</summary>
                        <form method="POST" class="nurture-form" style="margin-top:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <div>
                            <label>Customer status</label>
                            <select name="lifecycle_lane"><?php foreach (Nurture::LANES as $lane): ?><option value="<?php echo htmlspecialchars($lane); ?>" <?php echo $profile['lifecycle_lane'] === $lane ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($lane)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="nurture_status"><?php foreach (Nurture::STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $profile['nurture_status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label>Signal</label>
                            <select name="temperature"><?php foreach (Nurture::TEMPERATURES as $temperature): ?><option value="<?php echo htmlspecialchars($temperature); ?>" <?php echo $profile['temperature'] === $temperature ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($temperature)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label>Follow-up rhythm</label>
                            <select name="cadence"><?php foreach (Nurture::CADENCES as $cadence): ?><option value="<?php echo htmlspecialchars($cadence); ?>" <?php echo $profile['cadence'] === $cadence ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($cadence)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label>Next check-in</label>
                            <input type="datetime-local" name="next_touch_at" value="<?php echo !empty($profile['next_touch_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $profile['next_touch_at']))) : ''; ?>">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label>Why</label>
                            <textarea name="next_touch_reason" rows="3"><?php echo htmlspecialchars((string) ($profile['next_touch_reason'] ?? '')); ?></textarea>
                        </div>
                        <div class="nurture-form-actions">
                            <button class="btn-premium-primary" type="submit">Save</button>
                        </div>
                        </form>
                    </details>

                    <form method="POST" class="content-card">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="refresh_profile">
                        <button class="btn-premium-secondary" type="submit">Refresh signals</button>
                    </form>
                <?php endif; ?>

                <div class="content-card" style="margin-top:1rem;">
                    <h3 style="margin-top:0;">Open Tasks</h3>
                    <?php if (empty($openTasks)): ?><p style="color:#64748b;">No open contact-linked tasks.</p><?php endif; ?>
                    <?php foreach ($openTasks as $task): ?>
                        <div style="padding:.65rem 0;border-bottom:1px solid #e2e8f0;">
                            <a href="task_view.php?id=<?php echo (int) $task['id']; ?>" style="font-weight:700;color:#0f172a;text-decoration:none;"><?php echo htmlspecialchars((string) $task['title']); ?></a>
                            <div style="font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars((string) $task['status']); ?><?php echo !empty($task['due_date']) ? ' &middot; Due ' . htmlspecialchars(date('M j, Y', strtotime((string) $task['due_date']))) : ''; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
