<?php
/**
 * Delete User Page
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
use CRM\Authorization;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require permission
$currentUser = Auth::user();
if (!Authorization::canAccessUsersPage($currentUser) || !Authorization::can('admin.users.delete', $currentUser)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($_GET['id'] ?? 0);

if (!$userId) {
    header('Location: users.php');
    exit;
}

if (!Authorization::canManageUserTarget($userId, $currentUser)) {
    header('Location: users.php?error=' . urlencode('You cannot delete a user with more privileges than your own.'));
    exit;
}

$user = Database::queryOne(
    "SELECT id, email, role, created_at
     FROM users
     WHERE id = ?",
    [$userId]
);

if (!$user) {
    header('Location: users.php?error=' . urlencode('User not found.'));
    exit;
}

$currentUserId = (int) ($currentUser['id'] ?? 0);
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isSuperAdmin = Authorization::isSuperAdmin($currentUser);
$requestedWorkspaceId = max(0, (int) ($_GET['workspace_id'] ?? 0));
$deleteWorkspaceId = $isSuperAdmin && $requestedWorkspaceId > 0 ? $requestedWorkspaceId : (!$isSuperAdmin ? $activeWorkspaceId : 0);
$isWorkspaceScopedDelete = $deleteWorkspaceId > 0;
$isSelf = ($userId === $currentUserId);
$rbacEnabled = Authorization::isRbacAvailable();
$targetMembership = null;
$isLastWorkspaceOwner = false;
$returnUsersParams = [];
if ($isSuperAdmin && $requestedWorkspaceId > 0) {
    $returnUsersParams['workspace_id'] = $requestedWorkspaceId;
}
$returnUsersHref = 'users.php' . ($returnUsersParams !== [] ? '?' . http_build_query($returnUsersParams) : '');
$returnJoin = str_contains($returnUsersHref, '?') ? '&' : '?';
$userActionParams = ['id' => $userId];
if ($isWorkspaceScopedDelete && $isSuperAdmin) {
    $userActionParams['workspace_id'] = $deleteWorkspaceId;
}
$userActionQuery = http_build_query($userActionParams);

if ($isWorkspaceScopedDelete) {
    $targetMembership = Database::queryOne(
        "SELECT id, role_slug, is_owner
         FROM workspace_memberships
         WHERE workspace_id = ?
           AND user_id = ?
           AND membership_status = 'active'
         LIMIT 1",
        [$deleteWorkspaceId, $userId]
    );
    if (!$targetMembership) {
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode('User not found in this workspace.'));
        exit;
    }

    $targetIsWorkspaceOwner = !empty($targetMembership['is_owner']) || (string) ($targetMembership['role_slug'] ?? '') === 'owner';
    if ($targetIsWorkspaceOwner) {
        $workspaceOwnerCount = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS aggregate_count
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')",
            [$deleteWorkspaceId]
        )['aggregate_count'] ?? 0));
        $isLastWorkspaceOwner = $workspaceOwnerCount <= 1;
    }
}

if (!$isWorkspaceScopedDelete && $rbacEnabled) {
    $elevatedAdminCount = (int) ((Database::queryOne(
        "SELECT COUNT(*) AS aggregate_count
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE r.slug IN ('admin', 'superadmin') AND r.is_active = 1"
    )['aggregate_count'] ?? 0));
    $targetRoleSlug = (string) ((Database::queryOne(
        "SELECT r.slug
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
         LIMIT 1",
        [$userId]
    )['slug'] ?? ''));
    $isElevatedAdminAccount = in_array($targetRoleSlug, ['admin', 'superadmin'], true);
} elseif (!$isWorkspaceScopedDelete) {
    $elevatedAdminCount = (int) (Database::queryOne("SELECT COUNT(*) as aggregate_count FROM users WHERE role = 'admin'")['aggregate_count'] ?? 0);
    $isElevatedAdminAccount = ($user['role'] === 'admin');
} else {
    $elevatedAdminCount = 0;
    $isElevatedAdminAccount = false;
}
$isLastElevatedAdmin = (!$isWorkspaceScopedDelete && $isElevatedAdminAccount && $elevatedAdminCount <= 1);
$pageVerb = $isWorkspaceScopedDelete ? 'Remove' : 'Delete';
$pageTitleAction = $isWorkspaceScopedDelete ? 'Remove User from Workspace' : 'Delete User';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode('Invalid security token. Please try again.'));
        exit;
    }

    if ($isSelf) {
        $selfMessage = $isWorkspaceScopedDelete ? 'You cannot remove your own workspace access.' : 'You cannot delete your own account.';
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode($selfMessage));
        exit;
    }

    if ($isLastElevatedAdmin) {
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode('Cannot delete the last admin or super admin account.'));
        exit;
    }

    if ($isLastWorkspaceOwner) {
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode('Cannot remove the last workspace owner.'));
        exit;
    }

    try {
        if (!$isWorkspaceScopedDelete && $isSuperAdmin) {
            Database::beginTransaction();
            (new OwnerHelpExpertService())->deleteProfilesForUsers([$userId]);
            Database::execute("DELETE FROM users WHERE id = ?", [$userId]);
            Database::commit();
            $message = 'User deleted successfully.';
        } else {
            Database::execute(
                "UPDATE workspace_memberships
                 SET membership_status = 'removed', updated_at = NOW()
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND membership_status = 'active'",
                [$deleteWorkspaceId, $userId]
            );
            Database::execute(
                "DELETE FROM workspace_user_roles WHERE workspace_id = ? AND user_id = ?",
                [$deleteWorkspaceId, $userId]
            );
            $message = 'User removed from this workspace.';
        }
        header('Location: ' . $returnUsersHref . $returnJoin . 'success=' . urlencode($message));
        exit;
    } catch (\Throwable $e) {
        try {
            Database::rollBack();
        } catch (\Throwable $rollbackError) {
            // Ignore rollback errors for non-transactional workspace removals.
        }
        $failureMessage = $isWorkspaceScopedDelete
            ? 'Unable to remove user from this workspace. Remove dependent records and try again.'
            : 'Unable to delete user. Remove dependent records and try again.';
        header('Location: ' . $returnUsersHref . $returnJoin . 'error=' . urlencode($failureMessage));
        exit;
    }
}

$pageTitle = $pageTitleAction . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1><?php echo htmlspecialchars($pageTitleAction); ?></h1>
                    <p>Review this user before removing access.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="user_view.php?<?php echo htmlspecialchars($userActionQuery); ?>" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Cancel
                    </a>
                </div>
            </div>

            <div class="admin-danger-panel">
                <div class="admin-danger-card">
                    <?php if ($isWorkspaceScopedDelete): ?>
                        <p><strong>This removes workspace access.</strong></p>
                        <p>The global login account remains available for any other workspace memberships.</p>
                    <?php else: ?>
                        <p><strong>This action cannot be undone.</strong></p>
                        <p>The user account and any directly linked records with cascade delete will be permanently removed.</p>
                    <?php endif; ?>
                </div>

                <?php if ($isSelf): ?>
                    <div class="premium-banner premium-banner-warning">
                        <?php echo $isWorkspaceScopedDelete ? 'You cannot remove your own workspace access.' : 'You cannot delete your own account.'; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isLastElevatedAdmin): ?>
                    <div class="premium-banner premium-banner-warning">
                        Cannot delete the last admin or super admin account.
                    </div>
                <?php endif; ?>

                <?php if ($isLastWorkspaceOwner): ?>
                    <div class="premium-banner premium-banner-warning">
                        Cannot remove the last workspace owner.
                    </div>
                <?php endif; ?>

                <div class="admin-meta-grid">
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Email</div>
                        <div class="admin-meta-value"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Role</div>
                        <div class="admin-meta-value"><?php echo htmlspecialchars(ucfirst((string) $user['role'])); ?></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Created</div>
                        <div class="admin-meta-value"><?php echo date('F j, Y g:i A', strtotime($user['created_at'])); ?></div>
                    </div>
                </div>

                <form method="POST" action="" class="form-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <a href="user_view.php?<?php echo htmlspecialchars($userActionQuery); ?>" class="btn-premium-secondary">Cancel</a>
                    <button
                        type="submit"
                        class="btn-premium-danger"
                        <?php echo ($isSelf || $isLastElevatedAdmin || $isLastWorkspaceOwner) ? 'disabled' : ''; ?>
                    >
                        <?php echo htmlspecialchars($pageVerb); ?> User
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
