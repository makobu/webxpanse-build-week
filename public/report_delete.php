<?php
/**
 * Delete Report Page
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
use CRM\Modules\Reports;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$reportsModule = new Reports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$reportId = (int) ($_GET['id'] ?? 0);

if ($reportId <= 0) {
    header('Location: reports.php');
    exit;
}

$report = $reportsModule->getEditableById($reportId, $userId);
if (!$report) {
    header('Location: reports.php?error=access_denied');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: reports.php?error=invalid_token');
        exit;
    }

    try {
        $reportsModule->deleteForUser($reportId, $userId);
        header('Location: reports.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: reports.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Report - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Delete Report</h1>
                <p>Confirm permanent removal of this report and its execution history.</p>
            </div>
            <div class="page-header-actions">
                <a href="report_view.php?id=<?php echo $reportId; ?>" class="btn-premium-secondary">Back to Report</a>
            </div>
        </div>

        <div class="report-banner report-banner-error">
            Deleting this report will also permanently delete its execution history.
        </div>

        <div class="form-card">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
                <div>
                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.35rem;">Report Name</div>
                    <div style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars((string) $report['name']); ?></div>
                </div>
                <div>
                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.35rem;">Report Type</div>
                    <div style="font-weight: 600; color: #0f172a; text-transform: capitalize;"><?php echo htmlspecialchars((string) $report['report_type']); ?></div>
                </div>
                <div>
                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.35rem;">Created By</div>
                    <div style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars((string) ($report['created_by_email'] ?? 'Unknown')); ?></div>
                </div>
            </div>

            <form method="POST" action="" class="form-actions">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <a href="report_view.php?id=<?php echo $reportId; ?>" class="btn-premium-secondary">Cancel</a>
                <button type="submit" class="btn-premium-danger">Delete Report</button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
