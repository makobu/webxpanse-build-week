<?php
/**
 * Tags List Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Tags;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$tagsModule = new Tags();
$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

$tags = $tagsModule->getAll();
$stats = $tagsModule->getStatistics();

$pageTitle = 'Tags - ' . brandProductName();
$tagsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_TAGS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<link rel="stylesheet" href="assets/css/utility-forms-ui.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1>Tags</h1>
                    <p>Organize your data with tags and labels</p>
                </div>
                <div class="admin-hero-actions">
                    <?php if ($tagsGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TAGS, 'Tags page guide', 'btn-premium-secondary'); ?>
                    <?php endif; ?>
                    <a href="tag_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        New Tag
                    </a>
                </div>
            </div>

            <?php if ($success !== ''): ?>
                <div class="premium-banner premium-banner-success">
                    <?php
                    $successMessages = [
                        'created' => 'Tag created successfully.',
                        'updated' => 'Tag updated successfully.',
                        'deleted' => 'Tag deleted successfully.',
                    ];
                    echo htmlspecialchars($successMessages[$success] ?? $success);
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="premium-banner premium-banner-error">
                    <?php
                    $errorMessages = [
                        'invalid_token' => 'Your session token was invalid. Please try again.',
                        'not_found' => 'That tag could not be found.',
                    ];
                    echo htmlspecialchars($errorMessages[$error] ?? $error);
                    ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Tags</div>
                    <div class="stat-value"><?php echo number_format($stats['total_tags']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Assignments</div>
                    <div class="stat-value"><?php echo number_format($stats['total_assignments']); ?></div>
                </div>
            </div>

            <div class="table-card">
                <?php if (empty($tags)): ?>
                    <div class="empty-state">
                        <p>No tags found.</p>
                        <a href="tag_create.php">Create your first tag -></a>
                    </div>
                <?php else: ?>
                    <div class="utility-resource-list">
                        <?php foreach ($tags as $tag): ?>
                            <div class="utility-resource-row">
                                <div class="utility-resource-main">
                                    <div class="admin-tag-row">
                                        <span class="utility-tag-chip" style="--tag-color: <?php echo htmlspecialchars($tag['color']); ?>;">
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </span>
                                        <span class="admin-tag">
                                            <i class="fas fa-tag"></i>
                                            Used <?php echo number_format($tag['usage_count'] ?? 0); ?> time<?php echo ($tag['usage_count'] ?? 0) !== 1 ? 's' : ''; ?>
                                        </span>
                                    </div>

                                    <?php if ($tag['description']): ?>
                                        <p class="utility-description">
                                            <?php echo htmlspecialchars($tag['description']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="utility-resource-meta">
                                        <span>Created by <?php echo htmlspecialchars($tag['created_by_email'] ?? 'Unknown'); ?></span>
                                        <span>&bull;</span>
                                        <span><?php echo date('M d, Y', strtotime($tag['created_at'])); ?></span>
                                    </div>
                                </div>

                                <div class="premium-inline-actions">
                                    <a href="tag_edit.php?id=<?php echo (int) $tag['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                    <a href="tag_delete.php?id=<?php echo (int) $tag['id']; ?>" class="btn-premium-danger btn-premium-sm">Delete</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TAGS, 'How to use Tags', $tagsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
