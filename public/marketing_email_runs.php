<?php
/**
 * Manual-first marketing email campaign runs.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$options = $marketing->optionData();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to edit marketing email runs.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'create') {
            $id = $marketing->createEmailCampaignRun($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_email_runs.php?success=created&id=' . $id);
            exit;
        }
        if ($action === 'export') {
            $marketing->markEmailCampaignRunExported((int) ($_POST['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_email_runs.php?success=exported');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$runs = $marketing->listEmailCampaignRuns($status !== '' ? ['status' => $status] : [], 100, 0);
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$consentPolicies = $options['consent_policies'] ?? [];
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$statusCounts = array_fill_keys(Marketing::EMAIL_CAMPAIGN_RUN_STATUSES, 0);
$recipientTotal = 0;
$consentReviewed = 0;
$warningCount = 0;
foreach ($runs as $run) {
    $runStatus = (string) ($run['status'] ?? 'draft');
    $statusCounts[$runStatus] = (int) ($statusCounts[$runStatus] ?? 0) + 1;
    $recipientTotal += (int) ($run['recipient_count'] ?? 0);
    if (in_array((string) ($run['consent_status'] ?? 'not_reviewed'), ['approved', 'blocked'], true)) {
        $consentReviewed++;
    }
    $warningCount += count((array) ($run['readiness_warnings_json'] ?? []));
    $warningCount += count((array) ($run['safety_warnings_json'] ?? []));
}
$readyRunCount = (int) ($statusCounts['ready'] ?? 0);
$exportedRunCount = (int) ($statusCounts['exported'] ?? 0);
$readyToExportRun = null;
foreach ($runs as $run) {
    if ((string) ($run['status'] ?? '') !== 'exported') {
        $readyToExportRun = $run;
        break;
    }
}

$summaryTiles = [
    ['icon' => 'fa-envelope-open-text', 'label' => 'Runs', 'value' => (string) count($runs), 'tooltip' => 'Manual email run records in this view.'],
    ['icon' => 'fa-check-circle', 'label' => 'Ready', 'value' => (string) ($readyRunCount + $exportedRunCount), 'tooltip' => 'Runs already ready or exported for manual sending.'],
    ['icon' => 'fa-users', 'label' => 'Recipients', 'value' => (string) $recipientTotal, 'tooltip' => 'Planned recipients across the visible email runs.'],
    ['icon' => 'fa-shield-alt', 'label' => 'Consent', 'value' => (string) $consentReviewed, 'tooltip' => 'Runs with a reviewed consent decision.'],
];
$stageCards = [
    ['icon' => 'fa-pen-nib', 'title' => 'Plan Email', 'status' => count($runs) > 0 ? 'in_use' : 'setup_needed', 'sentence' => count($runs) > 0 ? 'Runs exist.' : 'Create the first run.', 'tooltip' => 'Name the email, connect source content, and keep the send manual.', 'href' => '#new-email-run', 'action' => 'Create Run'],
    ['icon' => 'fa-user-check', 'title' => 'Choose Audience', 'status' => count($options['audience_segments'] ?? []) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($options['audience_segments'] ?? []) > 0 ? 'Audiences available.' : 'Manual audience ok.', 'tooltip' => 'Use a saved audience segment when possible, or record a manual segment note.', 'href' => '#new-email-run', 'action' => 'Audience'],
    ['icon' => 'fa-shield-alt', 'title' => 'Check Consent', 'status' => $consentReviewed > 0 ? 'ready' : 'setup_needed', 'sentence' => $consentReviewed > 0 ? 'Consent reviewed.' : 'Needs review.', 'tooltip' => 'Consent, suppression, and unsubscribe details protect the manual export.', 'href' => '#new-email-run', 'action' => 'Consent'],
    ['icon' => 'fa-file-export', 'title' => 'Export Bundle', 'status' => $readyToExportRun ? 'ready' : 'setup_needed', 'sentence' => $readyToExportRun ? 'Bundle available.' : 'Create a run first.', 'tooltip' => 'Generate the copy and CSV plan for a human-controlled email send.', 'href' => '#email-run-board', 'action' => 'Export'],
    ['icon' => 'fa-chart-line', 'title' => 'Learn Later', 'status' => $exportedRunCount > 0 ? 'ready' : 'setup_needed', 'sentence' => $exportedRunCount > 0 ? 'Exports exist.' : 'No exports yet.', 'tooltip' => 'After manual sending, review performance and attribution evidence.', 'href' => 'marketing_performance.php', 'action' => 'Performance'],
];
$todayActions = array_slice(array_values(array_filter([
    $canWriteMarketing ? ['label' => 'Create email run', 'href' => '#new-email-run', 'reason' => 'Package the next manual email send.'] : null,
    $readyToExportRun ? ['label' => 'Generate CSV bundle', 'href' => '#email-run-board', 'reason' => 'Prepare the operator handoff without sending externally.'] : null,
    $warningCount > 0 ? ['label' => 'Review warnings', 'href' => '#email-run-board', 'reason' => 'Clear safety or readiness blockers before export.'] : null,
    ['label' => 'Open email templates', 'href' => 'email_templates.php', 'reason' => 'Use reusable email copy before creating a run.'],
    ['label' => 'Review performance', 'href' => 'marketing_performance.php', 'reason' => 'Learn from exported manual sends.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Email Templates', 'href' => 'email_templates.php', 'hint' => 'Manage reusable email copy.'],
    ['label' => 'Bulk Email', 'href' => 'bulk_email.php', 'hint' => 'Operational email tool; manual safeguards still apply.'],
    ['label' => 'Audience Builder', 'href' => 'marketing_segments.php', 'hint' => 'Prepare the audience before creating a run.'],
    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'hint' => 'Package channel-specific handoff work.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Review email and campaign learning after execution.'],
];

$pageTitle = 'Marketing Email Runs - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-email-runs-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Email Runs</h1>
                <p>Prepare manual email sends without sending externally.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="email_templates.php"><i class="fas fa-file-alt"></i> Email Templates</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Email campaign run created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'exported'): ?><div class="alert alert-success">Manual email export bundle generated.</div><?php endif; ?>

        <section class="marketing-email-run-shell">
            <div class="marketing-founder-summary marketing-email-run-summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($tile['tooltip']); ?>" tabindex="0">
                        <i class="fas <?php echo $h($tile['icon']); ?>"></i>
                        <div><span><?php echo $h($tile['label']); ?></span><strong><?php echo $h($tile['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-email-run-stage-grid">
                <?php foreach ($stageCards as $stage): ?>
                    <article class="marketing-email-run-stage-card <?php echo $h((string) $stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-email-run-stage-body">
                            <span class="badge <?php echo in_array((string) $stage['status'], ['ready', 'in_use'], true) ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize((string) $stage['status'])); ?></span>
                            <h2><?php echo $h($stage['title']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                        </div>
                        <a class="btn-premium-secondary marketing-email-run-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-email-run-layout">
                <main class="content-card marketing-email-run-board" id="email-run-board">
                    <div class="premium-section-header">
                        <div>
                            <h2>Email Run Board</h2>
                            <p>Manual export packages only.</p>
                        </div>
                    </div>
                    <?php if (empty($runs)): ?>
                        <div class="empty-state"><p>No marketing email runs match this view.</p><?php if ($canWriteMarketing): ?><a href="#new-email-run">Create an email run</a><?php endif; ?></div>
                    <?php else: ?>
                        <div class="marketing-email-run-list">
                            <?php foreach (array_slice($runs, 0, 12) as $run): ?>
                                <?php
                                    $runWarnings = array_merge((array) ($run['safety_warnings_json'] ?? []), (array) ($run['readiness_warnings_json'] ?? []));
                                    $runStatus = (string) ($run['status'] ?? 'draft');
                                    $runTooltip = 'Campaign: ' . (string) ($run['campaign_name'] ?? 'No campaign') . '. Audience: ' . (string) ($run['audience_segment_name'] ?? $run['segment_name'] ?? 'Manual audience') . '. Consent: ' . $labelize((string) ($run['consent_status'] ?? 'not_reviewed')) . '.';
                                ?>
                                <article class="marketing-email-run-card <?php echo $h($runStatus); ?>" data-tooltip="<?php echo $h($runTooltip); ?>" tabindex="0">
                                    <div class="marketing-stage-visual"><i class="fas fa-envelope-open-text"></i></div>
                                    <div class="marketing-email-run-card-body">
                                        <span class="badge <?php echo in_array($runStatus, ['ready', 'exported'], true) ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize($runStatus)); ?></span>
                                        <h3><?php echo $h($run['name']); ?></h3>
                                        <p><?php echo $h($run['subject'] ?: 'Subject not set'); ?></p>
                                        <div class="marketing-email-run-meta">
                                            <span><?php echo (int) ($run['recipient_count'] ?? 0); ?> recipients</span>
                                            <span><?php echo (int) ($run['readiness_score'] ?? 0); ?>% ready</span>
                                            <span><?php echo $h($labelize((string) ($run['approval_status'] ?? 'not_requested'))); ?></span>
                                        </div>
                                    </div>
                                    <div class="marketing-email-run-card-actions">
                                        <?php if ($canWriteMarketing && $runStatus !== 'exported'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="export">
                                                <input type="hidden" name="id" value="<?php echo (int) $run['id']; ?>">
                                                <button class="btn-premium-secondary" type="submit">Generate Bundle</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="marketing-email-run-exported">Export ready</span>
                                        <?php endif; ?>
                                    </div>
                                    <details class="marketing-email-run-evidence">
                                        <summary>Evidence</summary>
                                        <div class="marketing-email-run-evidence-grid">
                                            <div><strong>Schedule</strong><span><?php echo $h($run['scheduled_at'] ?: 'Unscheduled'); ?></span></div>
                                            <div><strong>Template</strong><span><?php echo $h($run['email_template_name'] ?? 'No template'); ?></span></div>
                                            <div><strong>Warnings</strong><span><?php echo $h($runWarnings === [] ? 'None' : implode(', ', array_map($labelize, $runWarnings))); ?></span></div>
                                            <?php if (!empty($run['export_bundle_json'])): ?><pre><?php echo $h(json_encode($run['export_bundle_json'], JSON_PRETTY_PRINT)); ?></pre><?php endif; ?>
                                            <?php if (!empty($run['csv_export_json'])): ?><pre><?php echo $h(json_encode($run['csv_export_json'], JSON_PRETTY_PRINT)); ?></pre><?php endif; ?>
                                        </div>
                                    </details>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </main>

                <aside class="content-card marketing-email-run-create" id="new-email-run">
                    <div class="premium-section-header"><h2>New Run</h2></div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-email-run-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="May newsletter launch"></div>
                            <div class="form-group"><label>Subject</label><input type="text" name="subject" placeholder="A practical next step"></div>
                            <div class="form-group"><label>Source Content</label><select name="content_item_id"><option value="">None</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h($item['title']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Audience Segment</label><select name="audience_segment_id"><option value="">Manual / None</option><?php foreach ($options['audience_segments'] as $segment): ?><option value="<?php echo (int) $segment['id']; ?>"><?php echo $h($segment['name']); ?> (<?php echo (int) ($segment['preview_count'] ?? 0); ?>)</option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Consent Policy</label><select name="consent_policy_id"><option value="">Needs review</option><?php foreach ($consentPolicies as $policy): ?><option value="<?php echo (int) $policy['id']; ?>"><?php echo $h($policy['policy_name']); ?> (<?php echo $h($labelize((string) $policy['consent_basis'])); ?>)</option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Recipient Count</label><input type="number" min="0" name="recipient_count" value="0"></div>

                            <details class="marketing-email-run-form-section">
                                <summary>Message details</summary>
                                <div class="marketing-email-run-form-grid">
                                    <div class="form-group"><label>Preview Text</label><input type="text" name="preview_text" placeholder="Short inbox preview"></div>
                                    <div class="form-group"><label>Preheader</label><input type="text" name="preheader_text" placeholder="Inbox supporting line"></div>
                                    <div class="form-group"><label>Email Template</label><select name="email_template_id"><option value="">None</option><?php foreach ($options['email_templates'] as $template): ?><option value="<?php echo (int) $template['id']; ?>"><?php echo $h($template['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>CTA Label</label><input type="text" name="cta_label" placeholder="Book a demo"></div>
                                    <div class="form-group"><label>CTA URL</label><input type="url" name="cta_url" placeholder="https://example.com/demo"></div>
                                    <div class="form-group marketing-email-run-wide-field"><label>Body Snapshot</label><textarea name="body_snapshot" rows="5" placeholder="Paste final email body or choose source content"></textarea></div>
                                </div>
                            </details>

                            <details class="marketing-email-run-form-section">
                                <summary>Audience and consent</summary>
                                <div class="marketing-email-run-form-grid">
                                    <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo $h($campaign['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Consent Status</label><select name="consent_status"><?php foreach (Marketing::CONSENT_REVIEW_STATUSES as $consentStatus): ?><option value="<?php echo $h($consentStatus); ?>"><?php echo $h($labelize($consentStatus)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Segment Name</label><input type="text" name="segment_name" placeholder="Trial users"></div>
                                    <div class="form-group marketing-email-run-wide-field"><label>Segment Criteria</label><textarea name="segment_criteria" rows="3" placeholder="Manual audience notes"></textarea></div>
                                    <div class="form-group marketing-email-run-wide-field"><label>Suppression Plan</label><textarea name="suppression_notes" rows="3" placeholder="Exclude unsubscribed contacts and current customers."></textarea></div>
                                    <div class="form-group marketing-email-run-wide-field"><label>Unsubscribe Placeholder</label><textarea name="unsubscribe_text" rows="3" placeholder="You can unsubscribe from these emails at any time."></textarea></div>
                                </div>
                                <label class="marketing-email-run-check"><input type="checkbox" name="suppression_list_checked" value="1"> Suppression list reviewed</label>
                            </details>

                            <details class="marketing-email-run-form-section">
                                <summary>Timing and approval</summary>
                                <div class="marketing-email-run-form-grid">
                                    <div class="form-group"><label>Approval Status</label><select name="approval_status"><?php foreach (Marketing::EMAIL_CAMPAIGN_APPROVAL_STATUSES as $approvalStatus): ?><option value="<?php echo $h($approvalStatus); ?>"><?php echo $h($labelize($approvalStatus)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Scheduled At</label><input type="datetime-local" name="scheduled_at"></div>
                                    <div class="form-group marketing-email-run-wide-field"><label>Send Checklist</label><textarea name="send_checklist" rows="4" placeholder="Proofread copy&#10;Confirm suppression list&#10;Export manually"></textarea></div>
                                </div>
                            </details>

                            <button class="btn-premium-primary" type="submit">Create Email Run</button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state"><p>Read-only access.</p></div>
                    <?php endif; ?>
                </aside>
            </div>

            <aside class="content-card marketing-email-run-today">
                <div class="premium-section-header"><div><h2>Today</h2><p>At most five actions.</p></div></div>
                <div class="marketing-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-today-action" href="<?php echo $h($action['href']); ?>">
                            <span><?php echo $h($action['label']); ?></span>
                            <small><?php echo $h($action['reason']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <details class="content-card marketing-email-run-tools">
                <summary>More email tools</summary>
                <div class="marketing-email-run-tools-body">
                    <form method="GET" class="marketing-email-run-filter">
                        <div class="form-group"><label>Status</label><select name="status"><option value="">All</option><?php foreach (Marketing::EMAIL_CAMPAIGN_RUN_STATUSES as $runStatus): ?><option value="<?php echo $h($runStatus); ?>" <?php echo $selected($status, $runStatus); ?>><?php echo $h($labelize($runStatus)); ?></option><?php endforeach; ?></select></div>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                        <a class="btn-premium-secondary" href="marketing_email_runs.php">Reset</a>
                    </form>
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>" tabindex="0">
                                <strong><?php echo $h($link['label']); ?></strong>
                                <span><?php echo $h($link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
