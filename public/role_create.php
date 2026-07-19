<?php
/**
 * Create access profile page.
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

$user = Auth::user();
$isSuperAdmin = Authorization::isSuperAdmin($user);
$permissions = Authorization::getAssignablePermissions($user);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $description = trim((string) ($_POST['description'] ?? ''));
        $selectedPermissions = $_POST['permissions'] ?? [];

        if ($name === '' || $slug === '') {
            $error = 'Name and slug are required.';
        } elseif (!preg_match('/^[a-z0-9_\\-]+$/', $slug)) {
            $error = 'Slug can only contain lowercase letters, numbers, hyphen, underscore.';
        } elseif (!$isSuperAdmin && in_array($slug, ['superadmin', 'admin_ops', 'admin', 'owner'], true)) {
            $error = 'Only Super Admin can create or modify reserved system access profiles.';
        } elseif (!Authorization::canAssignPermissionIds((array) $selectedPermissions, $user)) {
            $error = 'You can only include permissions that are assignable by your current access profile.';
        } else {
            try {
                Database::beginTransaction();
                Database::execute(
                    "INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)",
                    [$name, $slug, $description !== '' ? $description : null]
                );
                $roleId = (int) Database::lastInsertId();

                if (is_array($selectedPermissions)) {
                    foreach ($selectedPermissions as $permissionId) {
                        $permissionId = (int) $permissionId;
                        if ($permissionId > 0) {
                            Database::execute(
                                "INSERT INTO role_permissions (role_id, permission_id, can_access) VALUES (?, ?, 1)",
                                [$roleId, $permissionId]
                            );
                        }
                    }
                }
                Database::commit();
                header('Location: role_edit.php?id=' . $roleId . '&success=' . urlencode('Access profile created.'));
                exit;
            } catch (\Throwable $e) {
                Database::rollBack();
                $error = 'Unable to create access profile: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Create Access Profile - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page admin-form-page--wide">
            <div class="admin-hero">
                <div>
                    <h1>Create Access Profile</h1>
                    <p>Define a custom access profile and choose permissions.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="roles.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Access Profiles
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Identity</h2>
                        <p>Give this access profile a clear name and stable slug.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="name">Access Profile Name</label>
                            <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="slug">Slug</label>
                            <input id="slug" name="slug" type="text" required value="<?php echo htmlspecialchars($_POST['slug'] ?? ''); ?>" placeholder="owner_ops">
                            <span class="admin-help-text">Lowercase letters, numbers, hyphen, and underscore only.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Permissions</h2>
                        <p>Select the capabilities this profile can access.</p>
                    </div>
                    <?php if ($permissions === []): ?>
                        <div class="empty-state">
                            <p>No assignable permissions are available for your current access profile.</p>
                        </div>
                    <?php else: ?>
                        <div class="admin-permission-grid">
                            <?php foreach ($permissions as $permission): ?>
                                <label class="admin-permission-card">
                                    <input type="checkbox" name="permissions[]" value="<?php echo (int) $permission['id']; ?>" <?php echo in_array((string) $permission['id'], (array) ($_POST['permissions'] ?? []), true) ? 'checked' : ''; ?>>
                                    <span>
                                        <strong><?php echo htmlspecialchars($permission['label']); ?></strong>
                                        <small><?php echo htmlspecialchars($permission['permission_key']); ?><?php echo ((int) $permission['is_sensitive'] === 1) ? ' (sensitive)' : ''; ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <a href="roles.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Create Access Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
