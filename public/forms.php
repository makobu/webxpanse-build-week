<?php
/**
 * Forms List - Form Builder
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
use CRM\Modules\Forms;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}
$canWrite = Authorization::can('marketing.write', $user);

$formsModule = new Forms();
$forms = $formsModule->list();
$basePath = getBasePath();
$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

$pageTitle = 'Forms - ' . brandProductName();
$formsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_FORMS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/forms.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="forms-page forms-index-page">
    <header class="forms-hero">
        <div>
            <h1>Forms</h1>
            <p>Build lead capture forms and review submissions.</p>
        </div>
        <div class="forms-hero-actions">
            <?php if ($formsGuideVideoUrl !== ''): ?>
                <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_FORMS, 'Forms page guide'); ?>
            <?php endif; ?>
            <?php if ($canWrite): ?>
                <a href="<?php echo $basePath; ?>/form_edit.php" class="btn-premium-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i>
                    New Form
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="premium-banner premium-banner-success forms-compact-notice">
            <?php
            $successMessages = [
                'created' => 'Created.',
                'updated' => 'Updated.',
                'deleted' => 'Deleted.',
            ];
            echo htmlspecialchars($successMessages[$success] ?? 'Saved.');
            ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="premium-banner premium-banner-error forms-compact-notice">
            <?php
            $errorMessages = [
                'invalid_token' => 'Session expired. Try again.',
            ];
            echo htmlspecialchars($errorMessages[$error] ?? $error);
            ?>
        </div>
    <?php endif; ?>

    <section class="table-card forms-list-card" aria-label="Forms">
        <?php if (empty($forms)): ?>
            <div class="forms-empty">
                <h2>No forms yet</h2>
                <p>Create a lead form, then share or embed it.</p>
                <?php if ($canWrite): ?>
                    <a href="<?php echo $basePath; ?>/form_edit.php" class="btn-premium-primary">
                        <i class="fas fa-plus" aria-hidden="true"></i>
                        Create your first form
                    </a>
                <?php else: ?>
                    <p>Ask a workspace member with Design write access to create the first form.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-card-scroll">
                <table class="premium-table forms-list-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Fields</th>
                            <th>Submissions</th>
                            <th>Updated</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $formRow): ?>
                            <?php
                            $uuid = (string) ($formRow['uuid'] ?? '');
                            $fieldCount = count($formRow['fields'] ?? []);
                            $submissionCount = (int) ($formRow['submission_count'] ?? 0);
                            $updatedAt = !empty($formRow['updated_at']) ? strtotime((string) $formRow['updated_at']) : false;
                            $designStatus = (string) ($formRow['design_status'] ?? 'published');
                            $designRevision = (int) ($formRow['design_revision'] ?? 0);
                            $publishedRevision = (int) ($formRow['published_revision'] ?? 0);
                            $statusLabel = $designStatus !== 'published' ? 'Draft' : (($publishedRevision > 0 && $publishedRevision < $designRevision) ? 'Changes pending' : 'Published');
                            $statusClass = $statusLabel === 'Published' ? 'is-published' : ($statusLabel === 'Draft' ? 'is-draft' : 'has-pending');
                            $previewToken = trim((string) ($formRow['preview_token'] ?? ''));
                            $previewHref = $basePath . '/form.php?uuid=' . urlencode($uuid) . ($previewToken !== '' ? '&preview_token=' . urlencode($previewToken) : '');
                            ?>
                            <tr>
                                <td data-label="Name">
                                    <a href="<?php echo $canWrite ? $basePath . '/form_edit.php?uuid=' . urlencode($uuid) : htmlspecialchars($previewHref); ?>"<?php echo $canWrite ? '' : ' target="_blank"'; ?> class="forms-table-name">
                                        <?php echo htmlspecialchars((string) ($formRow['name'] ?? 'Untitled form')); ?>
                                    </a>
                                </td>
                                <td data-label="Status"><span class="forms-pill forms-status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td data-label="Fields">
                                    <span class="forms-pill forms-count-pill" aria-label="<?php echo number_format($fieldCount); ?> fields">
                                        <i class="fas fa-list-ul" aria-hidden="true"></i>
                                        <?php echo number_format($fieldCount); ?>
                                    </span>
                                </td>
                                <td data-label="Submissions">
                                    <a href="<?php echo $basePath; ?>/form_submissions.php?form_uuid=<?php echo urlencode($uuid); ?>" class="forms-pill forms-count-pill" aria-label="<?php echo number_format($submissionCount); ?> submissions">
                                        <i class="fas fa-inbox" aria-hidden="true"></i>
                                        <?php echo number_format($submissionCount); ?>
                                    </a>
                                </td>
                                <td data-label="Updated"><?php echo $updatedAt ? date('M j, Y', $updatedAt) : '-'; ?></td>
                                <td data-label="Actions">
                                    <div class="forms-icon-actions">
                                        <a href="<?php echo htmlspecialchars($previewHref); ?>" target="_blank" class="forms-icon-button" aria-label="Preview <?php echo htmlspecialchars((string) ($formRow['name'] ?? 'form')); ?>" title="Preview latest draft">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </a>
                                        <?php if ($canWrite): ?>
                                            <a href="<?php echo $basePath; ?>/form_edit.php?uuid=<?php echo urlencode($uuid); ?>" class="forms-icon-button" aria-label="Open <?php echo htmlspecialchars((string) ($formRow['name'] ?? 'form')); ?> in Form Studio" title="Open in Form Studio">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </a>
                                            <a href="<?php echo $basePath; ?>/form_delete.php?uuid=<?php echo urlencode($uuid); ?>" class="forms-icon-button forms-icon-button-danger" aria-label="Delete <?php echo htmlspecialchars((string) ($formRow['name'] ?? 'form')); ?>" title="Delete">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_FORMS, 'How to use Forms', $formsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
