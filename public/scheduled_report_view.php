<?php
/**
 * Scheduled Report View Page
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
use CRM\Modules\ScheduledReports;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$scheduledReportsModule = new ScheduledReports();
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

$executionHistory = $scheduledReportsModule->getExecutionHistory($scheduleId, 20);
$recipients = is_array($schedule['recipients']) ? $schedule['recipients'] : json_decode((string) $schedule['recipients'], true) ?? [];

$pageTitle = 'Scheduled Report: ' . htmlspecialchars((string) $schedule['schedule_name']) . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $schedule['schedule_name']); ?></h1>
                <p>View scheduled report details and execution history</p>
            </div>
            <div class="page-header-actions">
                <a href="scheduled_report_edit.php?id=<?php echo $scheduleId; ?>" class="btn-premium-secondary">Edit</a>
                <a href="scheduled_reports.php" class="btn-premium-secondary">Back to Schedules</a>
            </div>
        </div>

        <div class="content-card">
            <h2 style="font-size: 1.125rem; margin: 0 0 1rem 0;">Schedule Details</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
                <div><strong>Status:</strong> <span class="badge <?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'badge-success' : 'badge-default'; ?>"><?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></span></div>
                <div><strong>Report:</strong> <a href="report_view.php?id=<?php echo (int) $schedule['report_id']; ?>" style="color: var(--accent-blue); text-decoration: none;"><?php echo htmlspecialchars((string) ($schedule['report_name'] ?? 'Unknown Report')); ?></a></div>
                <div><strong>Schedule Type:</strong> <?php echo htmlspecialchars(ucfirst((string) $schedule['schedule_type'])); ?></div>
                <div><strong>Format:</strong> <?php echo htmlspecialchars(strtoupper((string) $schedule['format'])); ?></div>
                <div><strong>Next Run:</strong> <?php echo !empty($schedule['next_run_at']) ? date('M d, Y g:i A', strtotime((string) $schedule['next_run_at'])) : 'Not scheduled'; ?></div>
                <div><strong>Last Run:</strong> <?php echo !empty($schedule['last_run_at']) ? date('M d, Y g:i A', strtotime((string) $schedule['last_run_at'])) : 'Never'; ?></div>
                <div><strong>Created By:</strong> <?php echo htmlspecialchars((string) ($schedule['created_by_email'] ?? 'Unknown')); ?></div>
                <div><strong>Created At:</strong> <?php echo date('M d, Y g:i A', strtotime((string) $schedule['created_at'])); ?></div>
            </div>

            <div style="margin-top: var(--spacing-md);">
                <div style="font-weight: 600; margin-bottom: 0.5rem;">Recipients</div>
                <div class="report-action-group">
                    <?php foreach ($recipients as $recipient): ?>
                        <?php $email = is_array($recipient) ? ($recipient['email'] ?? '') : (string) $recipient; ?>
                        <span class="badge badge-default"><?php echo htmlspecialchars($email); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div style="padding: var(--spacing-lg); border-bottom: 1px solid var(--border-color); background: var(--light-grey);">
                <h2 style="margin: 0; font-size: 1.125rem;">Execution History</h2>
            </div>

            <?php if (empty($executionHistory)): ?>
                <div class="empty-state">
                    <p>No execution history yet.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Executed At</th>
                                <th>Status</th>
                                <th>Records</th>
                                <th>Execution Time</th>
                                <th>Recipients</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($executionHistory as $run): ?>
                                <?php $sentTo = json_decode((string) ($run['sent_to'] ?? '[]'), true) ?? []; ?>
                                <tr>
                                    <td><?php echo date('M d, Y g:i A', strtotime((string) $run['executed_at'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($run['status'] ?? '') === 'success' ? 'badge-success' : (($run['status'] ?? '') === 'failed' ? 'badge-danger' : 'badge-default'); ?>">
                                            <?php echo htmlspecialchars(ucfirst((string) ($run['status'] ?? 'pending'))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format((int) ($run['result_count'] ?? 0)); ?></td>
                                    <td><?php echo number_format(((float) ($run['execution_time'] ?? 0)) * 1000, 2); ?>ms</td>
                                    <td><?php echo count($sentTo); ?> recipient<?php echo count($sentTo) === 1 ? '' : 's'; ?></td>
                                    <td><?php echo !empty($run['error_message']) ? htmlspecialchars((string) $run['error_message']) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
