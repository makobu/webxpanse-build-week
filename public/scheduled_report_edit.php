<?php
/**
 * Edit Scheduled Report Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\ScheduledReports;
use CRM\Modules\Reports;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$scheduledReportsModule = new ScheduledReports();
$reportsModule = new Reports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$scheduleId = (int) ($_GET['id'] ?? 0);

if ($scheduleId <= 0) {
    header('Location: scheduled_reports.php');
    exit;
}

$schedule = $scheduledReportsModule->getOwnedById($scheduleId, $userId);
if (!$schedule) {
    header('Location: scheduled_reports.php?error=access_denied');
    exit;
}

$allReports = $reportsModule->getAll($userId, true);
$allUsers = Database::query("SELECT id, email FROM users ORDER BY email ASC");
$exportCapabilities = $reportsModule->getExportCapabilities();
$error = null;

$baseScheduleConfig = is_array($schedule['schedule_config']) ? $schedule['schedule_config'] : json_decode((string) $schedule['schedule_config'], true) ?? [];
$baseRecipients = is_array($schedule['recipients']) ? $schedule['recipients'] : json_decode((string) $schedule['recipients'], true) ?? [];

$formScheduleName = (string) ($_POST['schedule_name'] ?? $schedule['schedule_name']);
$formReportId = (int) ($schedule['report_id'] ?? 0);
$formScheduleType = (string) ($_POST['schedule_type'] ?? $schedule['schedule_type']);
$formFormat = (string) ($_POST['format'] ?? $schedule['format']);
$formRecipients = array_values(array_filter(array_map('trim', (array) ($_POST['recipients'] ?? $baseRecipients))));
$formIsActive = isset($_POST['is_active']) ? true : ((int) ($schedule['is_active'] ?? 0) === 1);
$scheduleConfig = [
    'daily_time' => (string) ($_POST['daily_time'] ?? ($baseScheduleConfig['time'] ?? '09:00')),
    'weekly_day' => (string) ($_POST['weekly_day'] ?? ($baseScheduleConfig['day_of_week'] ?? '1')),
    'weekly_time' => (string) ($_POST['weekly_time'] ?? ($baseScheduleConfig['time'] ?? '09:00')),
    'monthly_day' => (string) ($_POST['monthly_day'] ?? ($baseScheduleConfig['day_of_month'] ?? '1')),
    'monthly_time' => (string) ($_POST['monthly_time'] ?? ($baseScheduleConfig['time'] ?? '09:00')),
    'custom_datetime' => (string) ($_POST['custom_datetime'] ?? (!empty($baseScheduleConfig['next_run']) ? date('Y-m-d\TH:i', strtotime((string) $baseScheduleConfig['next_run'])) : '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $normalizedScheduleConfig = match ($formScheduleType) {
                'weekly' => ['day_of_week' => (int) $scheduleConfig['weekly_day'], 'time' => $scheduleConfig['weekly_time']],
                'monthly' => ['day_of_month' => (int) $scheduleConfig['monthly_day'], 'time' => $scheduleConfig['monthly_time']],
                'custom' => ['next_run' => $scheduleConfig['custom_datetime']],
                default => ['time' => $scheduleConfig['daily_time']],
            };

            $recipients = [];
            foreach ($formRecipients as $recipient) {
                if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $recipient;
                }
            }

            if ($recipients === []) {
                throw new \Exception('At least one recipient email is required.');
            }

            $scheduledReportsModule->updateForUser($scheduleId, $userId, [
                'schedule_name' => $formScheduleName,
                'schedule_type' => $formScheduleType,
                'schedule_config' => $normalizedScheduleConfig,
                'recipients' => $recipients,
                'format' => $formFormat,
                'is_active' => $formIsActive ? 1 : 0,
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ]);

            header('Location: scheduled_reports.php?success=updated');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Scheduled Report - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Edit Scheduled Report</h1>
                <p>Update scheduled report configuration</p>
            </div>
            <div class="page-header-actions">
                <a href="scheduled_report_view.php?id=<?php echo $scheduleId; ?>" class="btn-premium-secondary">Back to Schedule</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="report-banner report-banner-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($_POST['expected_lock_version'] ?? $schedule['lock_version'] ?? 0); ?>">

                <div class="form-group">
                    <label for="schedule_name">Schedule Name *</label>
                    <input type="text" id="schedule_name" name="schedule_name" required value="<?php echo htmlspecialchars($formScheduleName); ?>">
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--spacing-md);">
                    <div class="form-group">
                        <label for="report_id">Report *</label>
                        <select id="report_id" name="report_id" disabled>
                            <?php foreach ($allReports as $report): ?>
                                <option value="<?php echo (int) $report['id']; ?>" <?php echo $formReportId === (int) $report['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $report['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="report_id" value="<?php echo $formReportId; ?>">
                    </div>

                    <div class="form-group">
                        <label for="format">Export Format *</label>
                        <select id="format" name="format" required>
                            <option value="csv" <?php echo $formFormat === 'csv' ? 'selected' : ''; ?>>CSV</option>
                            <option value="pdf" <?php echo $formFormat === 'pdf' ? 'selected' : ''; ?> <?php echo empty($exportCapabilities['pdf']) ? 'disabled' : ''; ?>>PDF<?php echo empty($exportCapabilities['pdf']) ? ' (Unavailable)' : ''; ?></option>
                            <option value="excel" <?php echo $formFormat === 'excel' ? 'selected' : ''; ?> <?php echo empty($exportCapabilities['excel']) ? 'disabled' : ''; ?>>Excel<?php echo empty($exportCapabilities['excel']) ? ' (Unavailable)' : ''; ?></option>
                        </select>
                    </div>
                </div>

                <div class="content-card" style="margin-bottom: 0;">
                    <h2 style="font-size: 1.125rem; margin: 0 0 1rem 0;">Schedule Configuration</h2>
                    <div class="form-group">
                        <label for="schedule_type">Schedule Type *</label>
                        <select id="schedule_type" name="schedule_type">
                            <option value="daily" <?php echo $formScheduleType === 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $formScheduleType === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $formScheduleType === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="custom" <?php echo $formScheduleType === 'custom' ? 'selected' : ''; ?>>Custom (One-time)</option>
                        </select>
                    </div>

                    <div id="daily_config" class="schedule-config-block">
                        <div class="form-group">
                            <label for="daily_time">Time</label>
                            <input type="time" id="daily_time" name="daily_time" value="<?php echo htmlspecialchars($scheduleConfig['daily_time']); ?>">
                        </div>
                    </div>

                    <div id="weekly_config" class="schedule-config-block" style="display:none;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
                            <div class="form-group">
                                <label for="weekly_day">Day of Week</label>
                                <select id="weekly_day" name="weekly_day">
                                    <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $scheduleConfig['weekly_day'] === (string) $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="weekly_time">Time</label>
                                <input type="time" id="weekly_time" name="weekly_time" value="<?php echo htmlspecialchars($scheduleConfig['weekly_time']); ?>">
                            </div>
                        </div>
                    </div>

                    <div id="monthly_config" class="schedule-config-block" style="display:none;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
                            <div class="form-group">
                                <label for="monthly_day">Day of Month</label>
                                <input type="number" id="monthly_day" name="monthly_day" min="1" max="28" value="<?php echo htmlspecialchars($scheduleConfig['monthly_day']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="monthly_time">Time</label>
                                <input type="time" id="monthly_time" name="monthly_time" value="<?php echo htmlspecialchars($scheduleConfig['monthly_time']); ?>">
                            </div>
                        </div>
                    </div>

                    <div id="custom_config" class="schedule-config-block" style="display:none;">
                        <div class="form-group">
                            <label for="custom_datetime">Run Date &amp; Time</label>
                            <input type="datetime-local" id="custom_datetime" name="custom_datetime" value="<?php echo htmlspecialchars($scheduleConfig['custom_datetime']); ?>">
                        </div>
                    </div>
                </div>

                <div class="content-card" style="margin-bottom: 0;">
                    <h2 style="font-size: 1.125rem; margin: 0 0 1rem 0;">Recipients</h2>
                    <div id="recipients_list" style="display: flex; flex-direction: column; gap: var(--spacing-sm); margin-bottom: var(--spacing-md);">
                        <?php
                        $initialRecipients = $formRecipients === [] ? [''] : $formRecipients;
                        foreach ($initialRecipients as $index => $recipient):
                        ?>
                            <div style="display: flex; gap: var(--spacing-sm);">
                                <input type="email" name="recipients[]" value="<?php echo htmlspecialchars($recipient); ?>" <?php echo $index === 0 ? 'required' : ''; ?> placeholder="email@example.com" style="flex: 1; padding: 0.625rem 0.875rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 0.875rem;">
                                <?php if ($index === 0): ?>
                                    <button type="button" class="btn-premium-secondary btn-premium-sm" onclick="addRecipient()">Add</button>
                                <?php else: ?>
                                    <button type="button" class="btn-premium-danger btn-premium-sm" onclick="this.parentElement.remove()">Remove</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.75rem;">Or select from users:</div>
                    <div class="report-action-group">
                        <?php foreach ($allUsers as $userOption): ?>
                            <?php $checked = in_array((string) $userOption['email'], $formRecipients, true); ?>
                            <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" value="<?php echo htmlspecialchars((string) $userOption['email']); ?>" <?php echo $checked ? 'checked' : ''; ?> onchange="toggleUserRecipient(this)">
                                <span><?php echo htmlspecialchars((string) $userOption['email']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display: inline-flex; align-items: center; gap: var(--spacing-sm); cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?php echo $formIsActive ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                        <span>Activate schedule</span>
                    </label>
                </div>

                <div class="form-actions">
                    <a href="scheduled_report_view.php?id=<?php echo $scheduleId; ?>" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Update Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var scheduleTypeSelect = document.getElementById('schedule_type');

    window.addRecipient = function() {
        var container = document.getElementById('recipients_list');
        var row = document.createElement('div');
        row.style.cssText = 'display:flex; gap:var(--spacing-sm);';
        row.innerHTML = '<input type="email" name="recipients[]" placeholder="email@example.com" style="flex:1; padding:0.625rem 0.875rem; border:1px solid rgba(0, 0, 0, 0.1); border-radius:8px; font-size:0.875rem;"><button type="button" class="btn-premium-danger btn-premium-sm" onclick="this.parentElement.remove()">Remove</button>';
        container.appendChild(row);
    };

    window.toggleUserRecipient = function(checkbox) {
        var container = document.getElementById('recipients_list');
        var existing = Array.prototype.slice.call(container.querySelectorAll('input[name="recipients[]"]')).find(function(input) {
            return input.value === checkbox.value;
        });

        if (checkbox.checked && !existing) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex; gap:var(--spacing-sm);';
            row.innerHTML = '<input type="email" name="recipients[]" readonly value="' + checkbox.value + '" style="flex:1; padding:0.625rem 0.875rem; border:1px solid rgba(0, 0, 0, 0.1); border-radius:8px; font-size:0.875rem; background:#f8fafc;"><button type="button" class="btn-premium-danger btn-premium-sm">Remove</button>';
            row.querySelector('button').addEventListener('click', function() {
                checkbox.checked = false;
                row.remove();
            });
            container.appendChild(row);
        } else if (!checkbox.checked && existing) {
            existing.parentElement.remove();
        }
    };

    function updateScheduleConfig() {
        ['daily', 'weekly', 'monthly', 'custom'].forEach(function(type) {
            document.getElementById(type + '_config').style.display = scheduleTypeSelect.value === type ? 'block' : 'none';
        });
    }

    scheduleTypeSelect.addEventListener('change', updateScheduleConfig);
    updateScheduleConfig();
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
