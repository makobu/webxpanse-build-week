<?php
/**
 * Delete Target Page
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
use CRM\Modules\Targets;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();

if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$targetsModule = new Targets();
$user = Auth::user();

$targetId = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($targetId <= 0) {
    $targetId = $targetsModule->findTargetIdByLookup([
        'title' => (string) ($_POST['lookup_title'] ?? ($_GET['lookup_title'] ?? '')),
        'user_id' => (int) ($_POST['lookup_owner'] ?? ($_GET['lookup_owner'] ?? 0)),
        'target_date' => (string) ($_POST['lookup_date'] ?? ($_GET['lookup_date'] ?? '')),
        'description' => (string) ($_POST['lookup_description'] ?? ($_GET['lookup_description'] ?? '')),
    ]);
}
if (!$targetId) {
    header('Location: ' . $basePath . '/targets.php');
    exit;
}

$target = $targetsModule->getById($targetId);
if (!$target) {
    header('Location: ' . $basePath . '/targets.php');
    exit;
}

if (!$targetsModule->canDeleteTarget($target, $user)) {
    header('Location: ' . $basePath . '/targets.php?error=forbidden');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $basePath . '/targets.php?error=invalid_token');
        exit;
    }

    try {
        $targetsModule->delete($targetId);
        header('Location: ' . $basePath . '/targets.php?deleted=1');
        exit;
    } catch (\Exception $e) {
        header('Location: ' . $basePath . '/targets.php?error=delete_failed');
        exit;
    }
}

$pageTitle = 'Delete Target - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Delete Target</h1>
    <p style="color: var(--charcoal-grey);">Are you sure you want to delete this target?</p>
</div>

<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <p style="font-weight: 500; margin-bottom: var(--spacing-sm);">Warning: This action cannot be undone.</p>
    <p style="font-size: 14px;">Deleting this target will permanently remove it.</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 700px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Target Title</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($target['title']); ?>
        </div>
    </div>

    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Type</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo htmlspecialchars(ucfirst($target['target_type'] ?? 'custom')); ?>
        </div>
    </div>

    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Status</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo htmlspecialchars(ucfirst($target['status'] ?? 'active')); ?>
        </div>
    </div>

    <form method="POST" action="" style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $targetId; ?>">
        <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
            Cancel
        </a>
        <button
            type="submit"
            style="background: #c33; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
        >
            Delete Target
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
