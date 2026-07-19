<?php
/**
 * Access profiles management page.
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
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

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

$currentUser = Auth::user();
$isSuperAdmin = Authorization::isSuperAdmin($currentUser);
$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

$roleWhere = $isSuperAdmin ? '' : "WHERE r.slug NOT IN ('superadmin', 'admin_ops')";
$roles = Database::query(
    "SELECT r.id, r.name, r.slug, r.description, r.is_system, r.is_active,
            COUNT(DISTINCT ur.user_id) AS users_count,
            COUNT(DISTINCT rp.permission_id) AS permissions_count
     FROM roles r
     LEFT JOIN user_roles ur ON ur.role_id = r.id
     LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
     {$roleWhere}
     GROUP BY r.id, r.name, r.slug, r.description, r.is_system, r.is_active
     ORDER BY r.is_system DESC, r.name ASC"
);

$pageTitle = 'Access Profiles - ' . brandProductName();
$rolesGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_ROLES);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1>Access Profiles</h1>
                    <p>Create and edit access profiles and permissions.</p>
                </div>
                <div class="admin-hero-actions">
                    <?php if ($rolesGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_ROLES, 'Access Profiles page guide', 'btn-premium-secondary'); ?>
                    <?php endif; ?>
                    <a href="users.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                    <a href="role_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        Create Access Profile
                    </a>
                </div>
            </div>

            <?php if ($success !== ''): ?>
                <div class="premium-banner premium-banner-success">
                    <?php
                    $successMessages = [
                        'deleted' => 'Access profile deleted successfully.',
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
                    ];
                    echo htmlspecialchars($errorMessages[$error] ?? $error);
                    ?>
                </div>
            <?php endif; ?>

            <div class="table-card">
                <div class="premium-section-header">
                    <h2>Profiles</h2>
                    <span class="premium-inline-note"><?php echo count($roles); ?> profile<?php echo count($roles) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Access Profile</th>
                                <th>Slug</th>
                                <th>Users</th>
                                <th>Permissions</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                                <?php
                                $deleteBlocker = Authorization::getRoleDeletionBlocker((int) $role['id']);
                                $editBlocked = !$isSuperAdmin && (int) ($role['is_system'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td>
                                        <div class="admin-meta-value"><?php echo htmlspecialchars($role['name']); ?></div>
                                        <div class="admin-muted"><?php echo htmlspecialchars($role['description'] ?? ''); ?></div>
                                    </td>
                                    <td><span class="admin-code-inline"><?php echo htmlspecialchars($role['slug']); ?></span></td>
                                    <td><?php echo (int) $role['users_count']; ?></td>
                                    <td><?php echo (int) $role['permissions_count']; ?></td>
                                    <td>
                                        <span class="admin-status-badge <?php echo ((int) $role['is_system'] === 1) ? 'admin-status-badge--info' : 'admin-status-badge--neutral'; ?>">
                                            <?php echo ((int) $role['is_system'] === 1) ? 'System' : 'Custom'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="admin-status-badge <?php echo ((int) $role['is_active'] === 1) ? 'admin-status-badge--success' : 'admin-status-badge--neutral'; ?>">
                                            <?php echo ((int) $role['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="premium-inline-actions">
                                            <?php if ($editBlocked): ?>
                                                <span class="premium-inline-note">System profile</span>
                                            <?php else: ?>
                                                <a href="role_edit.php?id=<?php echo (int) $role['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                            <?php endif; ?>
                                            <?php if ($deleteBlocker === null): ?>
                                                <a href="role_delete.php?id=<?php echo (int) $role['id']; ?>" class="btn-premium-danger btn-premium-sm">Delete</a>
                                            <?php else: ?>
                                                <span class="premium-inline-note"><?php echo htmlspecialchars($deleteBlocker); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_ROLES, 'How to use Access Profiles', $rolesGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
