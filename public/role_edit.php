<?php
/**
 * Edit access profile page.
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

$currentUser = Auth::user();
$isSuperAdmin = Authorization::isSuperAdmin($currentUser);
$roleId = (int) ($_GET['id'] ?? 0);
if ($roleId <= 0) {
    header('Location: roles.php');
    exit;
}

$role = Database::queryOne("SELECT * FROM roles WHERE id = ?", [$roleId]);
if (!$role) {
    header('Location: roles.php');
    exit;
}

if (!$isSuperAdmin && ((int) ($role['is_system'] ?? 0) === 1 || in_array((string) ($role['slug'] ?? ''), ['superadmin', 'admin_ops'], true))) {
    header('Location: roles.php?error=' . urlencode('Only Super Admin can edit system access profiles.'));
    exit;
}
if (!$isSuperAdmin && !Authorization::canAssignRole($roleId, $currentUser)) {
    header('Location: roles.php?error=' . urlencode('You cannot edit an access profile with more privileges than your own.'));
    exit;
}

$permissions = $isSuperAdmin
    ? Database::query("SELECT id, permission_key, label, is_sensitive FROM permissions ORDER BY permission_key ASC")
    : Authorization::getAssignablePermissions($currentUser);
$permissionIdsById = [];
foreach ($permissions as $permission) {
    $permissionIdsById[(int) ($permission['id'] ?? 0)] = true;
}
$assignedRows = Database::query("SELECT permission_id FROM role_permissions WHERE role_id = ? AND can_access = 1", [$roleId]);
$assigned = [];
foreach ($assignedRows as $row) {
    $assigned[(int) $row['permission_id']] = true;
}

$error = null;
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedPermissions = array_values(array_filter(array_map(
            static fn($value): int => (int) $value,
            (array) ($_POST['permissions'] ?? [])
        ), static fn(int $value): bool => $value > 0));
        if (!$isSuperAdmin) {
            $selectedPermissions = array_values(array_filter(
                $selectedPermissions,
                static fn(int $permissionId): bool => isset($permissionIdsById[$permissionId])
            ));
        }

        if ($name === '') {
            $error = 'Access profile name is required.';
        } elseif (!Authorization::canAssignPermissionIds($selectedPermissions, $currentUser)) {
            $error = 'You can only include workspace permissions that are assignable by your current access profile.';
        } else {
            try {
                Database::beginTransaction();
                Database::execute(
                    "UPDATE roles SET name = ?, description = ?, is_active = ? WHERE id = ?",
                    [$name, $description !== '' ? $description : null, $isActive, $roleId]
                );

                Database::execute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
                foreach ($selectedPermissions as $permissionId) {
                    if ($permissionId > 0) {
                        Database::execute(
                            "INSERT INTO role_permissions (role_id, permission_id, can_access) VALUES (?, ?, 1)",
                            [$roleId, $permissionId]
                        );
                    }
                }

                Database::commit();
                header('Location: role_edit.php?id=' . $roleId . '&success=' . urlencode('Access profile updated.'));
                exit;
            } catch (\Throwable $e) {
                Database::rollBack();
                $error = 'Unable to update access profile: ' . $e->getMessage();
            }
        }
    }
}

// Reload latest role state for render after post errors/success.
$role = Database::queryOne("SELECT * FROM roles WHERE id = ?", [$roleId]);
$assignedRows = Database::query("SELECT permission_id FROM role_permissions WHERE role_id = ? AND can_access = 1", [$roleId]);
$assigned = [];
foreach ($assignedRows as $row) {
    $assigned[(int) $row['permission_id']] = true;
}

$pageTitle = 'Edit Access Profile - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page admin-form-page--wide">
            <div class="admin-hero">
                <div>
                    <h1>Edit Access Profile</h1>
                    <p><span class="admin-code-inline"><?php echo htmlspecialchars($role['slug']); ?></span></p>
                </div>
                <div class="admin-hero-actions">
                    <a href="roles.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Access Profiles
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="premium-banner premium-banner-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
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
                        <p>Update the profile name and description. Slugs remain fixed.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="name">Access Profile Name</label>
                            <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($_POST['name'] ?? $role['name']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Slug</label>
                            <div class="admin-code-inline"><?php echo htmlspecialchars($role['slug']); ?></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ($role['description'] ?? '')); ?></textarea>
                    </div>
                    <label class="admin-toggle-row">
                        <input type="checkbox" name="is_active" value="1" <?php echo (isset($_POST['is_active']) ? true : ((int) ($role['is_active'] ?? 0) === 1)) ? 'checked' : ''; ?>>
                        <strong>Access profile is active</strong>
                    </label>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Permissions</h2>
                        <p>Select the capabilities this profile can access.</p>
                    </div>
                    <div class="admin-permission-grid">
                        <?php foreach ($permissions as $permission): ?>
                            <?php $checked = isset($assigned[(int) $permission['id']]); ?>
                            <label class="admin-permission-card">
                                <input type="checkbox" name="permissions[]" value="<?php echo (int) $permission['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span>
                                    <strong><?php echo htmlspecialchars($permission['label']); ?></strong>
                                    <small><?php echo htmlspecialchars($permission['permission_key']); ?><?php echo ((int) $permission['is_sensitive'] === 1) ? ' (sensitive)' : ''; ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="roles.php" class="btn-premium-secondary">Back</a>
                    <button type="submit" class="btn-premium-primary">Save Access Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
