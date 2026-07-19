<?php
/**
 * Create Target Page
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
use CRM\Database;
use CRM\Modules\Targets;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

function parseTargetMilestonesInput(string $raw): array
{
    $milestones = [];
    foreach (preg_split('/\R/', $raw) as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (($parts[0] ?? '') === '') {
            continue;
        }
        $milestones[] = [
            'title' => (string) $parts[0],
            'target_value' => isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : 0,
            'due_date' => $parts[2] ?? '',
            'sort_order' => $index,
        ];
    }
    return $milestones;
}

$targetsModule = new Targets();
$user = Auth::user();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $rollupSource = trim((string) ($_POST['rollup_source'] ?? ''));
            $rollupMetric = trim((string) ($_POST['rollup_metric'] ?? ''));
            $metadata = [
                'rollup_definition' => ($rollupSource !== '' && $rollupMetric !== '') ? [
                    'source' => $rollupSource,
                    'metric' => $rollupMetric,
                ] : null,
                'milestones' => parseTargetMilestonesInput((string) ($_POST['milestones_text'] ?? '')),
            ];

            $data = [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'target_type' => $_POST['target_type'] ?? 'custom',
                'target_value' => $_POST['target_value'] ?? 0,
                'current_value' => $_POST['current_value'] ?? 0,
                'scope' => $_POST['scope'] ?? 'personal',
                'progress_mode' => $_POST['progress_mode'] ?? 'manual',
                'automation_mode' => $_POST['automation_mode'] ?? 'manual',
                'rollup_source' => $rollupSource ?: null,
                'rollup_metric' => $rollupMetric ?: null,
                'rollup_window' => $_POST['rollup_window'] ?? 'target_period',
                'currency_code' => $_POST['currency_code'] ?? null,
                'manual_adjustment_value' => $_POST['manual_adjustment_value'] ?? 0,
                'unit' => $_POST['unit'] ?? '',
                'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
                'target_date' => $_POST['target_date'] ?? '',
                'reminder_frequency' => $_POST['reminder_frequency'] ?? 'weekly',
                'custom_reminder_days' => !empty($_POST['custom_reminder_days']) ? (int) $_POST['custom_reminder_days'] : null,
                'metadata_json' => $metadata,
                'user_id' => (int) ($user['id'] ?? 0),
            ];

            $targetId = $targetsModule->create($data);
            $query = [
                'id' => $targetId,
                'lookup_title' => (string) ($data['title'] ?? ''),
                'lookup_owner' => (int) ($data['user_id'] ?? 0),
                'lookup_date' => (string) ($data['target_date'] ?? ''),
                'lookup_description' => (string) ($data['description'] ?? ''),
                'success' => 'created',
            ];
            header('Location: target_view.php?' . http_build_query($query));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Target - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div style="margin-bottom: var(--spacing-xl);">
            <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Create Target</h1>
            <p style="color: var(--charcoal-grey);">Create a personal, team, or company target with measurable checkpoints and optional CRM rollups.</p>
        </div>

        <?php if ($error): ?>
            <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 900px;">
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div>
                    <label for="title" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Title *</label>
                    <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" placeholder="e.g., Close 12 won deals this quarter" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                </div>

                <div>
                    <label for="description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Description</label>
                    <textarea id="description" name="description" rows="4" placeholder="What does success look like?" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="scope" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Scope *</label>
                        <select id="scope" name="scope" required style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="personal" <?php echo (($_POST['scope'] ?? 'personal') === 'personal') ? 'selected' : ''; ?>>Personal</option>
                            <option value="team" <?php echo (($_POST['scope'] ?? '') === 'team') ? 'selected' : ''; ?>>Team</option>
                            <option value="company" <?php echo (($_POST['scope'] ?? '') === 'company') ? 'selected' : ''; ?>>Company</option>
                        </select>
                    </div>
                    <div>
                        <label for="target_type" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Type *</label>
                        <select id="target_type" name="target_type" required style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="sales" <?php echo (($_POST['target_type'] ?? 'custom') === 'sales') ? 'selected' : ''; ?>>Sales</option>
                            <option value="personal" <?php echo (($_POST['target_type'] ?? 'custom') === 'personal') ? 'selected' : ''; ?>>Personal</option>
                            <option value="performance" <?php echo (($_POST['target_type'] ?? 'custom') === 'performance') ? 'selected' : ''; ?>>Performance</option>
                            <option value="custom" <?php echo (($_POST['target_type'] ?? 'custom') === 'custom') ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    <div>
                        <label for="progress_mode" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Progress Mode *</label>
                        <select id="progress_mode" name="progress_mode" required onchange="toggleProgressModeFields()" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="manual" <?php echo (($_POST['progress_mode'] ?? 'manual') === 'manual') ? 'selected' : ''; ?>>Manual</option>
                            <option value="auto_rollup" <?php echo (($_POST['progress_mode'] ?? '') === 'auto_rollup') ? 'selected' : ''; ?>>Auto rollup</option>
                            <option value="hybrid" <?php echo (($_POST['progress_mode'] ?? '') === 'hybrid') ? 'selected' : ''; ?>>Hybrid</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="target_value" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Value *</label>
                        <input type="number" id="target_value" name="target_value" step="0.01" required value="<?php echo htmlspecialchars($_POST['target_value'] ?? ''); ?>" placeholder="e.g., 12" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div id="current_value_container">
                        <label for="current_value" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Current Value</label>
                        <input type="number" id="current_value" name="current_value" step="0.01" value="<?php echo htmlspecialchars($_POST['current_value'] ?? '0'); ?>" placeholder="0" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div>
                        <label for="unit" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Unit</label>
                        <input type="text" id="unit" name="unit" value="<?php echo htmlspecialchars($_POST['unit'] ?? ''); ?>" placeholder="e.g., deals, $, contacts" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                </div>

                <div id="manual_adjustment_container" style="display: <?php echo (($_POST['progress_mode'] ?? 'manual') === 'hybrid') ? 'block' : 'none'; ?>;">
                    <label for="manual_adjustment_value" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Hybrid Manual Adjustment</label>
                    <input type="number" id="manual_adjustment_value" name="manual_adjustment_value" step="0.01" value="<?php echo htmlspecialchars($_POST['manual_adjustment_value'] ?? '0'); ?>" placeholder="Optional manual uplift on top of auto rollup" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                </div>

                <div id="rollup_container" style="display: <?php echo (($_POST['progress_mode'] ?? 'manual') === 'manual') ? 'none' : 'grid'; ?>; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="rollup_source" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Auto Rollup Source</label>
                        <select id="rollup_source" name="rollup_source" onchange="updateRollupMetrics()" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="">Select source</option>
                            <?php foreach (['deals' => 'Deals', 'invoices' => 'Invoices', 'tasks' => 'Tasks', 'contacts' => 'Contacts', 'communications' => 'Communications', 'meetings' => 'Meetings', 'documents' => 'Documents'] as $sourceKey => $sourceLabel): ?>
                                <option value="<?php echo $sourceKey; ?>" <?php echo (($_POST['rollup_source'] ?? '') === $sourceKey) ? 'selected' : ''; ?>><?php echo $sourceLabel; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="rollup_metric" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Metric</label>
                        <select id="rollup_metric" name="rollup_metric" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></select>
                    </div>
                    <div><label for="rollup_window" style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Measurement Window</label><select id="rollup_window" name="rollup_window" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><option value="target_period">Target start through deadline</option><option value="since_creation">Since target creation</option><option value="lifetime">Explicit lifetime</option></select></div>
                    <div><label for="currency_code" style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Currency for monetary metrics</label><input id="currency_code" name="currency_code" maxlength="3" value="<?php echo htmlspecialchars($_POST['currency_code'] ?? ''); ?>" placeholder="USD" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;text-transform:uppercase;"></div>
                </div>

                <div><label for="automation_mode" style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Clarity Automation</label><select id="automation_mode" name="automation_mode" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><option value="manual">Manual</option><option value="review">Review recommendations</option><option value="auto">Let Clarity complete when verified</option></select></div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="start_date" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div>
                        <label for="target_date" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Date (Deadline) *</label>
                        <input type="date" id="target_date" name="target_date" required value="<?php echo htmlspecialchars($_POST['target_date'] ?? ''); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                </div>

                <div>
                    <label for="milestones_text" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Milestones / Checkpoints</label>
                    <textarea id="milestones_text" name="milestones_text" rows="4" placeholder="One per line: Title | Target Value | YYYY-MM-DD" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"><?php echo htmlspecialchars($_POST['milestones_text'] ?? ''); ?></textarea>
                    <div style="margin-top: 6px; color: #64748b; font-size: 0.8rem;">Example: Proposal checkpoint | 5 | 2026-04-01</div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="reminder_frequency" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Reminder Frequency *</label>
                        <select id="reminder_frequency" name="reminder_frequency" required onchange="toggleCustomDays(this.value)" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="daily" <?php echo (($_POST['reminder_frequency'] ?? 'weekly') === 'daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo (($_POST['reminder_frequency'] ?? 'weekly') === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                            <option value="deadline" <?php echo (($_POST['reminder_frequency'] ?? 'weekly') === 'deadline') ? 'selected' : ''; ?>>Deadline</option>
                            <option value="custom" <?php echo (($_POST['reminder_frequency'] ?? 'weekly') === 'custom') ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    <div id="custom_days_container" style="display: <?php echo (($_POST['reminder_frequency'] ?? 'weekly') === 'custom') ? 'block' : 'none'; ?>;">
                        <label for="custom_reminder_days" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Days Between Reminders</label>
                        <input type="number" id="custom_reminder_days" name="custom_reminder_days" min="1" value="<?php echo htmlspecialchars($_POST['custom_reminder_days'] ?? '7'); ?>" placeholder="7" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                </div>

                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                    <a href="targets.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">Cancel</a>
                    <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">Create Target</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const rollupMetrics = {
    deals: [['closed_won_count', 'Closed-won deal count'], ['open_count', 'Open deal count'], ['proposal_negotiation_count', 'Proposal + negotiation count'], ['won_value', 'Closed-won value'], ['pipeline_value', 'Open pipeline value']],
    invoices: [['paid_total', 'Paid invoice total'], ['sent_total', 'Sent/finalized total'], ['paid_count', 'Paid invoice count'], ['active_quote_count', 'Active quote/proforma count']],
    tasks: [['completed_count', 'Completed task count'], ['open_count', 'Open task count']],
    contacts: [['created_count', 'Created contact count'], ['qualified_count', 'Qualified contact count'], ['won_count', 'Won contact count']],
    communications: [['outbound_count', 'Outbound communication count'], ['inbound_count', 'Inbound communication count'], ['reply_count', 'Verified reply count']],
    meetings: [['completed_count', 'Completed qualified conversations']],
    documents: [['created_count', 'Created proposals or invoices'], ['proposal_created_count', 'Created proposals']]
};

function toggleCustomDays(value) {
    document.getElementById('custom_days_container').style.display = value === 'custom' ? 'block' : 'none';
}

function updateRollupMetrics() {
    const source = document.getElementById('rollup_source').value;
    const metricSelect = document.getElementById('rollup_metric');
    const selectedMetric = <?php echo json_encode((string) ($_POST['rollup_metric'] ?? '')); ?>;
    metricSelect.innerHTML = '<option value="">Select metric</option>';
    (rollupMetrics[source] || []).forEach(function(metric) {
        const option = document.createElement('option');
        option.value = metric[0];
        option.textContent = metric[1];
        if (metric[0] === selectedMetric) {
            option.selected = true;
        }
        metricSelect.appendChild(option);
    });
}

function toggleProgressModeFields() {
    const mode = document.getElementById('progress_mode').value;
    document.getElementById('rollup_container').style.display = mode === 'manual' ? 'none' : 'grid';
    document.getElementById('manual_adjustment_container').style.display = mode === 'hybrid' ? 'block' : 'none';
    document.getElementById('current_value_container').style.display = mode === 'auto_rollup' ? 'none' : 'block';
}

toggleProgressModeFields();
updateRollupMetrics();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
