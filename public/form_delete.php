<?php
/**
 * Delete Form Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Forms;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
if (!Authorization::can('marketing.write', $user)) {
    header('Location: ' . getBasePath() . '/forms.php');
    exit;
}

$formsModule = new Forms();
$formId = (int) ($_GET['id'] ?? 0);
$formUuid = trim((string) ($_GET['uuid'] ?? ''));
$form = $formUuid !== '' ? $formsModule->getByUuid($formUuid) : $formsModule->getById($formId);

if (!$form) {
    header('Location: ' . getBasePath() . '/forms.php');
    exit;
}

$formId = (int) ($form['id'] ?? 0);
$submissionCount = $formsModule->getSubmissionCount($formId);
$fieldCount = count($form['fields'] ?? []);
$basePath = getBasePath();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $basePath . '/forms.php?error=invalid_token');
        exit;
    }

    try {
        $formsModule->delete($formId);
        header('Location: ' . $basePath . '/forms.php?success=deleted');
        exit;
    } catch (\Throwable $e) {
        header('Location: ' . $basePath . '/forms.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Form - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/forms.css">

<div class="forms-page">
    <header class="forms-hero">
        <div>
            <h1>Delete Form</h1>
            <p>Confirm removal of this form and its stored submissions.</p>
        </div>
        <div class="forms-hero-actions">
            <a href="<?php echo $basePath; ?>/forms.php" class="btn-premium-secondary">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Back to forms
            </a>
        </div>
    </header>

    <div class="premium-banner premium-banner-error">
        <strong>This action cannot be undone.</strong>
        Deleting this form also removes all submissions stored for it.
    </div>

    <section class="forms-danger-panel">
        <div>
            <div class="forms-stat-label">Form</div>
            <div class="forms-submission-value"><?php echo htmlspecialchars((string) ($form['name'] ?? 'Untitled form')); ?></div>
        </div>

        <div class="forms-danger-metrics">
            <div class="forms-danger-metric">
                <div class="forms-stat-label">Fields</div>
                <div class="forms-stat-value"><?php echo number_format($fieldCount); ?></div>
            </div>
            <div class="forms-danger-metric">
                <div class="forms-stat-label">Submissions</div>
                <div class="forms-stat-value"><?php echo number_format($submissionCount); ?></div>
            </div>
            <div class="forms-danger-metric">
                <div class="forms-stat-label">Updated</div>
                <div class="forms-stat-value" style="font-size: 1.05rem;">
                    <?php echo !empty($form['updated_at']) ? date('M j, Y', strtotime((string) $form['updated_at'])) : '-'; ?>
                </div>
            </div>
        </div>

        <form method="POST" action="" class="forms-actions" style="justify-content: flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <a href="<?php echo $basePath; ?>/forms.php" class="btn-premium-secondary">Cancel</a>
            <button type="submit" class="btn-premium-danger">
                <i class="fas fa-trash" aria-hidden="true"></i>
                Delete Form
            </button>
        </form>
    </section>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
