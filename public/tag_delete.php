<?php
/**
 * Delete Tag Page
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
use CRM\Modules\Tags;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$tagsModule = new Tags();
$tagId = (int) ($_POST['tag_id'] ?? $_GET['id'] ?? 0);

if (!$tagId) {
    header('Location: tags.php?error=not_found');
    exit;
}

$tag = $tagsModule->getById($tagId);

if (!$tag) {
    header('Location: tags.php?error=not_found');
    exit;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: tags.php?error=invalid_token');
        exit;
    }
    
    try {
        if (!$tagsModule->delete($tagId)) {
            header('Location: tags.php?error=not_found');
            exit;
        }
        header('Location: tags.php?success=deleted');
        exit;
    } catch (\Throwable $e) {
        error_log('Tag deletion failed: ' . $e->getMessage());
        header('Location: tags.php?error=delete_failed');
        exit;
    }
}

$pageTitle = 'Delete Tag - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<link rel="stylesheet" href="assets/css/utility-forms-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Delete Tag</h1>
                    <p>Are you sure you want to delete this tag?</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="tags.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Tags
                    </a>
                </div>
            </div>

            <div class="admin-danger-panel">
                <div class="admin-danger-card">
                    <p><strong>Warning: This action cannot be undone!</strong></p>
                    <p>Deleting this tag will remove it from all entities (contacts, tasks, events, etc.).</p>
                </div>

                <div class="admin-meta-grid">
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Tag Name</div>
                        <div class="admin-meta-value">
                            <span class="utility-tag-chip" style="--tag-color: <?php echo htmlspecialchars($tag['color']); ?>;">
                                <?php echo htmlspecialchars($tag['name']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Usage</div>
                        <div class="admin-meta-value">
                            Currently used <?php echo number_format($tag['usage_count'] ?? 0); ?> time<?php echo ($tag['usage_count'] ?? 0) !== 1 ? 's' : ''; ?>
                        </div>
                    </div>
                    <?php if ($tag['description']): ?>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Description</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars($tag['description']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (($tag['usage_count'] ?? 0) > 0): ?>
                    <div class="premium-banner premium-banner-error">
                        All assignments will be permanently removed!
                    </div>
                <?php endif; ?>

                <form method="POST" action="tag_delete.php?id=<?php echo $tagId; ?>" class="form-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tag_id" value="<?php echo $tagId; ?>">
                    <a href="tags.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-danger">
                        <i class="fas fa-trash"></i>
                        Delete Tag
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
