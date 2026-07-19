<?php
/**
 * User View Page
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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user();
if (!Authorization::canAccessUsersPage($currentUser)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: users.php');
    exit;
}

$rbacEnabled = Authorization::isRbacAvailable();
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isCurrentUserSuperAdmin = Authorization::isSuperAdmin($currentUser);
$requestedWorkspaceId = max(0, (int) ($_GET['workspace_id'] ?? 0));
$viewWorkspaceId = ($isCurrentUserSuperAdmin && $requestedWorkspaceId > 0) ? $requestedWorkspaceId : $activeWorkspaceId;
$hasExplicitWorkspaceContext = $isCurrentUserSuperAdmin && $requestedWorkspaceId > 0;

if ($viewWorkspaceId <= 0) {
    header('Location: users.php?error=' . urlencode('Workspace context is required.'));
    exit;
}

if ($rbacEnabled) {
    $user = Database::queryOne(
        "SELECT u.id, u.first_name, u.last_name, u.job_title, u.email, u.created_at, u.last_login,
                CASE WHEN wm.id IS NOT NULL THEN workspace_department.name ELSE legacy_department.name END AS department_name,
                r.name AS access_role_name, r.slug AS access_role_slug
         FROM users u
         LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
         LEFT JOIN departments workspace_department ON workspace_department.id = wm.department_id AND workspace_department.workspace_id = ?
         LEFT JOIN departments legacy_department ON legacy_department.id = u.department_id
         LEFT JOIN workspace_user_roles ur ON ur.user_id = u.id AND ur.workspace_id = ?
         LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.id = ?",
        [$viewWorkspaceId, $viewWorkspaceId, $viewWorkspaceId, $userId]
    );
} else {
    $user = Database::queryOne(
        "SELECT u.id, u.first_name, u.last_name, u.job_title, u.email, u.created_at, u.last_login,
                CASE WHEN wm.id IS NOT NULL THEN workspace_department.name ELSE legacy_department.name END AS department_name,
                NULL AS access_role_name, NULL AS access_role_slug
         FROM users u
         LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
         LEFT JOIN departments workspace_department ON workspace_department.id = wm.department_id AND workspace_department.workspace_id = ?
         LEFT JOIN departments legacy_department ON legacy_department.id = u.department_id
         WHERE u.id = ?",
        [$viewWorkspaceId, $viewWorkspaceId, $userId]
    );
}

if (!$user) {
    header('Location: users.php');
    exit;
}

if (!$isCurrentUserSuperAdmin || $hasExplicitWorkspaceContext) {
    $targetMembership = Database::queryOne(
        "SELECT id
         FROM workspace_memberships
         WHERE workspace_id = ?
           AND user_id = ?
           AND membership_status = 'active'
         LIMIT 1",
        [$viewWorkspaceId, $userId]
    );
    if (!$targetMembership) {
        $params = $hasExplicitWorkspaceContext ? ['workspace_id' => $viewWorkspaceId] : [];
        $params['error'] = 'User not found in this workspace.';
        header('Location: users.php?' . http_build_query($params));
        exit;
    }
}

if ($rbacEnabled && !$isCurrentUserSuperAdmin) {
    $targetGlobalRole = Authorization::getGlobalUserRole($userId);
    if ((string) ($targetGlobalRole['slug'] ?? '') === 'superadmin') {
        header('Location: users.php?error=' . urlencode('User not found in this workspace.'));
        exit;
    }
}

if (!Authorization::canManageUserTarget($userId, $currentUser)) {
    header('Location: users.php?error=' . urlencode('User not found in this workspace.'));
    exit;
}

$contactsCreated = (int) (Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND created_by = ?", [$viewWorkspaceId, $userId])['count'] ?? 0);
$activitiesCreated = (int) (Database::queryOne("SELECT COUNT(*) as count FROM activities WHERE workspace_id = ? AND user_id = ?", [$viewWorkspaceId, $userId])['count'] ?? 0);
$emailsSent = (int) (Database::queryOne("SELECT COUNT(*) as count FROM emails WHERE workspace_id = ? AND user_id = ?", [$viewWorkspaceId, $userId])['count'] ?? 0);
$organizationFunctions = new OrganizationFunctionService();
$userFunctions = $organizationFunctions->assignmentsForUser($viewWorkspaceId, $userId);
$isSelf = ((int) ($currentUser['id'] ?? 0) === (int) $user['id']);
$canManageTarget = Authorization::canManageUserTarget((int) $user['id'], $currentUser);
$canEditUser = Authorization::can('admin.users.edit', $currentUser) && $canManageTarget;
$canDeleteUser = Authorization::can('admin.users.delete', $currentUser) && $canManageTarget && !$isSelf;
$userActionParams = ['id' => (int) $user['id']];
if ($hasExplicitWorkspaceContext) {
    $userActionParams['workspace_id'] = $viewWorkspaceId;
}
$userActionQuery = htmlspecialchars(http_build_query($userActionParams));
$usersBackParams = [];
if ($hasExplicitWorkspaceContext) {
    $usersBackParams['workspace_id'] = $viewWorkspaceId;
}
$usersBackHref = 'users.php' . ($usersBackParams !== [] ? '?' . htmlspecialchars(http_build_query($usersBackParams)) : '');

$pageTitle = $user['email'] . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1><?php echo htmlspecialchars(trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: (string) $user['email']); ?></h1>
                    <p>User command center</p>
                </div>
                <div class="admin-hero-actions">
                    <?php if ($canEditUser): ?>
                        <a href="user_edit.php?<?php echo $userActionQuery; ?>" class="btn-premium-primary">
                            <i class="fas fa-pen"></i>
                            Edit User
                        </a>
                    <?php endif; ?>
                    <?php if ($canDeleteUser): ?>
                        <a href="user_delete.php?<?php echo $userActionQuery; ?>" class="btn-premium-danger">
                            <i class="fas fa-trash"></i>
                            Delete User
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo $usersBackHref; ?>" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Contacts Created</div>
                    <div class="stat-value"><?php echo $contactsCreated; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Activities Logged</div>
                    <div class="stat-value"><?php echo $activitiesCreated; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Emails Sent</div>
                    <div class="stat-value"><?php echo $emailsSent; ?></div>
                </div>
            </div>

            <div class="admin-detail-layout">
                <div class="content-card">
                    <div class="premium-section-header">
                        <h2>Identity</h2>
                    </div>
                    <div class="admin-meta-grid">
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">First Name</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars((string) (($user['first_name'] ?? '') !== '' ? $user['first_name'] : '-')); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Last Name</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars((string) (($user['last_name'] ?? '') !== '' ? $user['last_name'] : '-')); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Job Title / Position</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars((string) (($user['job_title'] ?? '') !== '' ? $user['job_title'] : '-')); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Email</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Created</div>
                            <div class="admin-meta-value"><?php echo date('F j, Y g:i A', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Last Login</div>
                            <div class="admin-meta-value"><?php echo $user['last_login'] ? date('F j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="admin-detail-stack">
                    <div class="content-card">
                        <div class="premium-section-header">
                            <h2>Access Profile</h2>
                        </div>
                        <div class="admin-meta-grid">
                            <div class="admin-meta-item">
                                <div class="admin-meta-label">Department</div>
                                <div class="admin-meta-value">
                                    <span class="admin-status-badge admin-status-badge--neutral"><?php echo htmlspecialchars(trim((string) ($user['department_name'] ?? '')) !== '' ? (string) $user['department_name'] : 'Unassigned'); ?></span>
                                </div>
                            </div>
                            <div class="admin-meta-item">
                                <div class="admin-meta-label">Access Profile</div>
                                <div class="admin-meta-value">
                                    <span class="admin-status-badge admin-status-badge--info"><?php echo htmlspecialchars((string) ($user['access_role_name'] ?? 'No access profile')); ?></span>
                                    <?php if ($rbacEnabled && empty($user['access_role_name'])): ?>
                                        <span class="admin-help-text">RBAC is enabled. This user currently has no access profile assigned.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="premium-section-header">
                            <h2>Work Ownership</h2>
                        </div>
                        <div class="user-view-function-row">
                            <?php if ($userFunctions !== []): ?>
                                <?php foreach ($userFunctions as $function): ?>
                                    <span class="user-view-function-chip <?php echo !empty($function['is_primary']) ? 'user-view-function-chip-primary' : ''; ?>">
                                        <?php echo htmlspecialchars((string) ($function['name'] ?? 'Function')); ?>
                                        <?php if (!empty($function['is_primary'])): ?><span>Primary</span><?php endif; ?>
                                        <span class="user-view-function-type"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($function['assignment_type'] ?? 'contributor')))); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="user-view-function-chip user-view-function-chip-missing">Missing work ownership</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
