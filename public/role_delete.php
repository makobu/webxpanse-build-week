<?php
/**
 * Delete access profile page.
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
use CRM\Security;
use CRM\Authorization;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

Authorization::requirePermission('admin.roles.manage');
if (!Authorization::isRbacAvailable()) {
    http_response_code(500);
    die('RBAC tables are not available. Run database migrations first.');
}

$roleId = (int) ($_GET['id'] ?? 0);
if ($roleId <= 0) {
    header('Location: roles.php');
    exit;
}

$role = Authorization::getRoleById($roleId);
if (!$role) {
    header('Location: roles.php');
    exit;
}

$deleteBlocker = Authorization::getRoleDeletionBlocker($roleId);
$assignedUserCount = (int) (Database::queryOne(
    "SELECT COUNT(*) AS c FROM user_roles WHERE role_id = ?",
    [$roleId]
)['c'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: roles.php?error=invalid_token');
        exit;
    }

    try {
        Authorization::deleteRole($roleId);
        header('Location: roles.php?success=deleted');
        exit;
    } catch (\Throwable $e) {
        header('Location: roles.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Access Profile - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Delete Access Profile</h1>
                    <p>Review this access profile before deleting it.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="roles.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Access Profiles
                    </a>
                </div>
            </div>

            <div class="admin-danger-panel">
                <?php if ($deleteBlocker !== null): ?>
                    <div class="premium-banner premium-banner-error">
                        <?php echo htmlspecialchars($deleteBlocker); ?>
                    </div>
                <?php else: ?>
                    <div class="admin-danger-card">
                        <p><strong>This action cannot be undone.</strong></p>
                        <p>The access profile and its permission mapping will be removed.</p>
                    </div>
                <?php endif; ?>

                <div class="admin-meta-grid">
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Access Profile</div>
                        <div class="admin-meta-value"><?php echo htmlspecialchars((string) ($role['name'] ?? '')); ?></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Slug</div>
                        <div class="admin-meta-value"><span class="admin-code-inline"><?php echo htmlspecialchars((string) ($role['slug'] ?? '')); ?></span></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Assigned Users</div>
                        <div class="admin-meta-value"><?php echo $assignedUserCount; ?></div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="roles.php" class="btn-premium-secondary">Back</a>
                    <?php if ($deleteBlocker === null): ?>
                        <form method="POST" action="" class="admin-inline-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <button type="submit" class="btn-premium-danger">Delete Access Profile</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
