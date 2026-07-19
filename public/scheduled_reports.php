<?php
/**
 * Scheduled Reports Management Page
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

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$scheduledReportsModule = new ScheduledReports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$successKey = trim((string) ($_GET['success'] ?? ''));
$errorKey = trim((string) ($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_schedule'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: scheduled_reports.php?error=invalid_token');
        exit;
    }
    try {
        $scheduledReportsModule->toggleActiveForUser((int) ($_POST['schedule_id'] ?? 0), $userId);
        header('Location: scheduled_reports.php?success=toggled');
        exit;
    } catch (\Exception $e) {
        header('Location: scheduled_reports.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: scheduled_reports.php?error=invalid_token');
        exit;
    }
    try {
        $scheduledReportsModule->deleteForUser((int) ($_POST['schedule_id'] ?? 0), $userId);
        header('Location: scheduled_reports.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: scheduled_reports.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$schedules = $scheduledReportsModule->getAll($userId, false);

$pageTitle = 'Scheduled Reports - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Scheduled Reports</h1>
                <p>Automate report generation and delivery</p>
            </div>
            <div class="page-header-actions">
                <a href="scheduled_report_create.php" class="btn-premium-primary">New Schedule</a>
            </div>
        </div>

        <?php if ($successKey !== ''): ?>
            <div class="report-banner report-banner-success">
                <?php
                $successMessages = [
                    'created' => 'Scheduled report created successfully.',
                    'updated' => 'Scheduled report updated successfully.',
                    'deleted' => 'Scheduled report deleted successfully.',
                    'toggled' => 'Schedule status updated successfully.',
                ];
                echo htmlspecialchars($successMessages[$successKey] ?? $successKey);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($errorKey !== ''): ?>
            <div class="report-banner report-banner-error">
                <?php
                $errorMessages = [
                    'invalid_token' => 'Your security token was invalid. Please try again.',
                    'access_denied' => 'You do not have permission to access that scheduled report.',
                ];
                echo htmlspecialchars($errorMessages[$errorKey] ?? $errorKey);
                ?>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <div style="padding: var(--spacing-lg); border-bottom: 1px solid var(--border-color); background: var(--light-grey);">
                <h2 style="margin: 0; font-size: 1.25rem;">Active Schedules</h2>
            </div>

            <?php if (empty($schedules)): ?>
                <div class="empty-state">
                    <p>No scheduled reports found.</p>
                    <a href="scheduled_report_create.php">Create your first scheduled report</a>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column;">
                    <?php foreach ($schedules as $schedule): ?>
                        <?php
                        $recipients = is_array($schedule['recipients']) ? $schedule['recipients'] : json_decode((string) $schedule['recipients'], true) ?? [];
                        ?>
                        <div style="padding: var(--spacing-lg); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: start; gap: 1rem;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem;">
                                    <h3 style="margin: 0; font-size: 1.125rem;"><?php echo htmlspecialchars((string) $schedule['schedule_name']); ?></h3>
                                    <span class="badge <?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'badge-success' : 'badge-default'; ?>">
                                        <?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>

                                <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.75rem;">
                                    <strong>Report:</strong>
                                    <a href="report_view.php?id=<?php echo (int) $schedule['report_id']; ?>" style="color: var(--accent-blue); text-decoration: none;">
                                        <?php echo htmlspecialchars((string) ($schedule['report_name'] ?? 'Unknown Report')); ?>
                                    </a>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--spacing-md); color: #64748b; font-size: 0.875rem;">
                                    <div><strong>Schedule:</strong> <?php echo htmlspecialchars(ucfirst((string) $schedule['schedule_type'])); ?></div>
                                    <div><strong>Format:</strong> <?php echo htmlspecialchars(strtoupper((string) $schedule['format'])); ?></div>
                                    <div><strong>Next Run:</strong> <?php echo !empty($schedule['next_run_at']) ? date('M d, Y g:i A', strtotime((string) $schedule['next_run_at'])) : 'Not scheduled'; ?></div>
                                    <div><strong>Last Run:</strong> <?php echo !empty($schedule['last_run_at']) ? date('M d, Y g:i A', strtotime((string) $schedule['last_run_at'])) : 'Never'; ?></div>
                                    <div><strong>Recipients:</strong> <?php echo count($recipients); ?> recipient<?php echo count($recipients) === 1 ? '' : 's'; ?></div>
                                </div>
                            </div>

                            <div class="report-action-group">
                                <a href="scheduled_report_view.php?id=<?php echo (int) $schedule['id']; ?>" class="btn-premium-secondary btn-premium-sm">View</a>
                                <a href="scheduled_report_edit.php?id=<?php echo (int) $schedule['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="schedule_id" value="<?php echo (int) $schedule['id']; ?>">
                                    <button type="submit" name="toggle_schedule" class="<?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'btn-premium-warning' : 'btn-premium-success'; ?> btn-premium-sm">
                                        <?php echo (int) ($schedule['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="schedule_id" value="<?php echo (int) $schedule['id']; ?>">
                                    <button type="submit" name="delete_schedule" class="btn-premium-danger btn-premium-sm" onclick="return confirm('Delete this scheduled report? This cannot be undone.');">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
