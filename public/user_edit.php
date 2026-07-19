<?php
/**
 * Edit User Page
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
use CRM\Modules\Departments;
use CRM\Security;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

if (!function_exists('userEditWorkspaceMembershipAccessForRole')) {
    /**
     * @return array{0:string,1:bool}
     */
    function userEditWorkspaceMembershipAccessForRole(int $workspaceId, string $roleSlug, bool $targetIsGlobalSuperAdmin = false): array
    {
        $roleSlug = trim($roleSlug);
        if (WorkspaceContext::isDefaultWorkspace($workspaceId) && ($roleSlug === 'superadmin' || $targetIsGlobalSuperAdmin)) {
            return ['superadmin', true];
        }

        if ($roleSlug === '' || in_array($roleSlug, ['superadmin', 'admin_ops'], true)) {
            return ['viewer', false];
        }

        return [$roleSlug, $roleSlug === 'owner'];
    }
}

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user();
if (!Authorization::canAccessUsersPage($currentUser) || !Authorization::can('admin.users.edit', $currentUser)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: users.php');
    exit;
}

if (!Authorization::canManageUserTarget($userId, $currentUser)) {
    header('Location: users.php?error=' . urlencode('You cannot edit a user with more privileges than your own.'));
    exit;
}

$rbacEnabled = Authorization::isRbacAvailable();
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isCurrentUserSuperAdmin = Authorization::isSuperAdmin($currentUser);
$requestedWorkspaceId = (int) (($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['workspace_id'] ?? 0) : ($_GET['workspace_id'] ?? 0)) ?? 0);
$editWorkspaceId = $isCurrentUserSuperAdmin ? $requestedWorkspaceId : $activeWorkspaceId;

if ($editWorkspaceId <= 0) {
    header('Location: users.php?error=' . urlencode('Select a workspace before editing workspace-specific user access.'));
    exit;
}

$targetMembership = Database::queryOne(
    "SELECT id, role_slug, is_owner, department_id
     FROM workspace_memberships
     WHERE workspace_id = ?
       AND user_id = ?
       AND membership_status = 'active'
     LIMIT 1",
    [$editWorkspaceId, $userId]
);

if (!$targetMembership) {
    $params = $isCurrentUserSuperAdmin ? ['workspace_id' => $editWorkspaceId] : [];
    $params['error'] = 'User not found in the selected workspace.';
    header('Location: users.php?' . http_build_query($params));
    exit;
}

$departmentsModule = new Departments();
$departments = $departmentsModule->getActive($editWorkspaceId);
$organizationFunctions = new OrganizationFunctionService();
$organizationFunctions->ensureDefaults($editWorkspaceId);
$availableFunctions = $organizationFunctions->listAssignableFunctions($editWorkspaceId);
$currentFunctionAssignments = $organizationFunctions->assignmentsForUser($editWorkspaceId, $userId);
$postedFunctionIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['function_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$postedFunctionAssignmentTypes = [];
foreach ((array) ($_POST['function_assignment_types'] ?? []) as $functionId => $assignmentType) {
    $postedFunctionAssignmentTypes[(int) $functionId] = (string) $assignmentType;
}
$existingFunctionIds = array_values(array_map(static fn(array $assignment): int => (int) ($assignment['function_id'] ?? 0), $currentFunctionAssignments));
$existingFunctionAssignmentTypes = [];
foreach ($currentFunctionAssignments as $assignment) {
    $existingFunctionAssignmentTypes[(int) ($assignment['function_id'] ?? 0)] = (string) ($assignment['assignment_type'] ?? 'contributor');
}
$postedPrimaryFunctionId = (int) ($_POST['primary_function_id'] ?? 0);
$existingPrimaryFunctionId = 0;
foreach ($currentFunctionAssignments as $assignment) {
    if (!empty($assignment['is_primary'])) {
        $existingPrimaryFunctionId = (int) ($assignment['function_id'] ?? 0);
        break;
    }
}
$selectedFunctionIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $postedFunctionIds
    : ($existingFunctionIds !== [] ? $existingFunctionIds : $organizationFunctions->defaultFunctionIdsForRole($editWorkspaceId, (string) ($targetMembership['role_slug'] ?? 'viewer'), !empty($targetMembership['is_owner'])));
$selectedPrimaryFunctionId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $postedPrimaryFunctionId
    : ($existingPrimaryFunctionId > 0 ? $existingPrimaryFunctionId : (int) ($selectedFunctionIds[0] ?? 0));

if ($rbacEnabled) {
    $user = Database::queryOne(
        "SELECT u.id, u.first_name, u.last_name, u.job_title, u.email, u.role,
                CASE WHEN wm.id IS NOT NULL THEN wm.department_id ELSE u.department_id END AS department_id,
                u.email_verified_at,
                ur.role_id AS access_role_id
         FROM users u
         LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
         LEFT JOIN workspace_user_roles ur ON ur.user_id = u.id AND ur.workspace_id = ?
         WHERE u.id = ?",
        [$editWorkspaceId, $editWorkspaceId, $userId]
    );
} else {
    $user = Database::queryOne(
        "SELECT u.id, u.first_name, u.last_name, u.job_title, u.email, u.role,
                CASE WHEN wm.id IS NOT NULL THEN wm.department_id ELSE u.department_id END AS department_id,
                u.email_verified_at,
                0 AS access_role_id
         FROM users u
         LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
         WHERE u.id = ?",
        [$editWorkspaceId, $userId]
    );
}

if (!$user) {
    header('Location: users.php');
    exit;
}

$error = null;
$availableAccessProfiles = $rbacEnabled ? Authorization::getAssignableRoles($currentUser) : [];
$targetGlobalRole = $rbacEnabled ? Authorization::getGlobalUserRole($userId) : null;
$targetIsGlobalSuperAdmin = (string) ($targetGlobalRole['slug'] ?? '') === 'superadmin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $accessRoleId = (int) ($_POST['access_role_id'] ?? 0);
            $firstName = Security::sanitizeInput($_POST['first_name'] ?? '', 'string');
            $lastName = Security::sanitizeInput($_POST['last_name'] ?? '', 'string');
            $jobTitle = Security::sanitizeInput($_POST['job_title'] ?? '', 'string');
            $membershipRoleSlugForValidation = (string) ($targetMembership['role_slug'] ?? 'viewer');
            $membershipIsOwnerForValidation = !empty($targetMembership['is_owner']);
            if ($rbacEnabled && $accessRoleId > 0) {
                $roleForValidation = Database::queryOne("SELECT slug FROM roles WHERE id = ? AND is_active = 1", [$accessRoleId]);
                if ($roleForValidation) {
                    [$membershipRoleSlugForValidation, $membershipIsOwnerForValidation] = userEditWorkspaceMembershipAccessForRole(
                        $editWorkspaceId,
                        (string) ($roleForValidation['slug'] ?? 'viewer'),
                        $targetIsGlobalSuperAdmin
                    );
                }
            }

            if ($firstName === '') {
                $error = 'First name is required.';
            } elseif ($email === '') {
                $error = 'Email is required.';
            } elseif (!$membershipIsOwnerForValidation && $membershipRoleSlugForValidation !== 'owner' && $availableFunctions !== [] && $postedFunctionIds === []) {
                $error = 'Assign at least one work ownership function.';
            } else {
                $existing = Database::queryOne(
                    "SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND id != ?",
                    [$email, $userId]
                );

                if ($existing) {
                    $error = 'Email already exists.';
                } elseif ($rbacEnabled && $accessRoleId > 0 && !Authorization::canAssignRole($accessRoleId, $currentUser)) {
                    $error = 'You cannot assign an access profile with more privileges than your own.';
                }
            }

            if (!$error) {
                if ($departmentId > 0) {
                    $selectedDepartment = $departmentsModule->getById($departmentId, $editWorkspaceId);
                    if (!$selectedDepartment || (int) ($selectedDepartment['is_active'] ?? 0) !== 1) {
                        throw new \RuntimeException('The selected department is no longer available.');
                    }
                }
                $effectiveDepartmentId = $departmentId > 0 ? $departmentId : null;

                $verifyEmail = isset($_POST['mark_email_verified']);
                $emailVerifiedValue = $verifyEmail ? 'NOW()' : 'NULL';
                $params = [
                    $firstName,
                    $lastName !== '' ? $lastName : null,
                    $jobTitle !== '' ? $jobTitle : null,
                    $email,
                ];

                if ($password !== '') {
                    $pwdCheck = Security::validatePasswordStrength($password);
                    if (!$pwdCheck['valid']) {
                        $error = implode(' ', $pwdCheck['errors']);
                    } else {
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                        $params[] = $userId;
                        Database::execute(
                            "UPDATE users
                             SET first_name = ?, last_name = ?, job_title = ?, email = ?, password_hash = ?, email_verified_at = {$emailVerifiedValue}, email_verification_token = NULL
                             WHERE id = ?",
                            $params
                        );
                    }
                } else {
                    $params[] = $userId;
                    Database::execute(
                        "UPDATE users
                         SET first_name = ?, last_name = ?, job_title = ?, email = ?, email_verified_at = {$emailVerifiedValue}, email_verification_token = NULL
                         WHERE id = ?",
                        $params
                    );
                }

                if (!$error) {
                    Database::execute(
                        "UPDATE workspace_memberships
                         SET department_id = ?, updated_at = NOW()
                         WHERE workspace_id = ?
                           AND user_id = ?
                           AND membership_status = 'active'",
                        [$effectiveDepartmentId, $editWorkspaceId, $userId]
                    );
                    $organizationFunctions->saveUserAssignments($editWorkspaceId, $userId, $postedFunctionIds, $postedPrimaryFunctionId, $postedFunctionAssignmentTypes);

                    $membershipRoleSlug = (string) ($targetMembership['role_slug'] ?? 'viewer');
                    $membershipIsOwner = !empty($targetMembership['is_owner']);
                    if ($rbacEnabled && $accessRoleId > 0) {
                        $roleRow = Database::queryOne("SELECT id, slug FROM roles WHERE id = ? AND is_active = 1", [$accessRoleId]);
                        if (!$roleRow) {
                            throw new \RuntimeException('The selected access profile is no longer available.');
                        }
                        [$membershipRoleSlug, $membershipIsOwner] = userEditWorkspaceMembershipAccessForRole(
                            $editWorkspaceId,
                            (string) ($roleRow['slug'] ?? 'viewer'),
                            $targetIsGlobalSuperAdmin
                        );

                        Database::execute(
                            "UPDATE workspace_memberships
                             SET role_slug = ?, is_owner = ?, updated_at = NOW()
                             WHERE workspace_id = ?
                               AND user_id = ?
                               AND membership_status = 'active'",
                            [$membershipRoleSlug, $membershipIsOwner ? 1 : 0, $editWorkspaceId, $userId]
                        );
                        Authorization::assignWorkspaceUserRole($editWorkspaceId, $userId, $accessRoleId, (int) ($currentUser['id'] ?? 0));
                    } elseif ($rbacEnabled) {
                        [$membershipRoleSlug, $membershipIsOwner] = userEditWorkspaceMembershipAccessForRole(
                            $editWorkspaceId,
                            '',
                            $targetIsGlobalSuperAdmin
                        );
                        Database::execute(
                            "UPDATE workspace_memberships
                             SET role_slug = ?, is_owner = ?, updated_at = NOW()
                             WHERE workspace_id = ?
                               AND user_id = ?
                               AND membership_status = 'active'",
                            [$membershipRoleSlug, $membershipIsOwner ? 1 : 0, $editWorkspaceId, $userId]
                        );
                        if (!$membershipIsOwner || $membershipRoleSlug !== 'superadmin') {
                            Database::execute(
                                "DELETE FROM workspace_user_roles WHERE workspace_id = ? AND user_id = ?",
                                [$editWorkspaceId, $userId]
                            );
                        } else {
                            Authorization::assignWorkspaceUserRoleBySlug($editWorkspaceId, $userId, 'superadmin', (int) ($currentUser['id'] ?? 0));
                        }
                    }
                    if ($membershipIsOwner || $membershipRoleSlug === 'owner') {
                        $organizationFunctions->ensureOwnerFunctionCoverage($editWorkspaceId, $userId);
                    }

                    $viewParams = ['id' => $userId];
                    if ($isCurrentUserSuperAdmin) {
                        $viewParams['workspace_id'] = $editWorkspaceId;
                    }
                    header('Location: user_view.php?' . http_build_query($viewParams));
                    exit;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit User - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page admin-form-page--wide">
            <div class="admin-hero">
                <div>
                    <h1>Edit User</h1>
                    <p>Update work ownership, optional workspace placement, and permissions independently.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="users.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
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
        <input type="hidden" name="workspace_id" value="<?php echo (int) $editWorkspaceId; ?>">

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Identity</h2>
                        <p>Update the user's profile and login details.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? $user['first_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? $user['last_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title / Position</label>
                            <input type="text" id="job_title" name="job_title" value="<?php echo htmlspecialchars($_POST['job_title'] ?? $user['job_title'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? $user['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" minlength="8">
                            <small class="admin-help-text">Leave blank to keep the current password.</small>
                        </div>
                    </div>
                </div>

        <?php if ($availableFunctions !== []): ?>
                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Work Ownership</h2>
                        <p>Choose what this person owns or supports. Organization Intelligence uses this as its accountability layer.</p>
                    </div>
                    <div class="user-function-field">
                        <label class="user-function-label">Work Ownership *</label>
                        <div class="user-function-panel">
                <?php foreach ($availableFunctions as $function): ?>
                    <?php
                        $functionId = (int) $function['id'];
                        $checked = in_array($functionId, $selectedFunctionIds, true);
                        $primaryChecked = $selectedPrimaryFunctionId === $functionId;
                    ?>
                    <?php
                        $selectedAssignmentType = $_SERVER['REQUEST_METHOD'] === 'POST'
                            ? (string) ($postedFunctionAssignmentTypes[$functionId] ?? ($primaryChecked ? 'owner' : 'contributor'))
                            : (string) ($existingFunctionAssignmentTypes[$functionId] ?? ($primaryChecked ? 'owner' : 'contributor'));
                    ?>
                    <div class="user-function-row">
                        <label class="user-function-choice">
                            <input type="checkbox" name="function_ids[]" value="<?php echo $functionId; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <span class="user-function-copy">
                                <strong><?php echo htmlspecialchars((string) $function['name']); ?></strong>
                                <small><?php echo htmlspecialchars((string) ($function['description'] ?? '')); ?></small>
                            </span>
                        </label>
                        <select name="function_assignment_types[<?php echo $functionId; ?>]" class="user-function-type">
                            <option value="owner" <?php echo $selectedAssignmentType === 'owner' ? 'selected' : ''; ?>>Owner</option>
                            <option value="temporary_owner" <?php echo $selectedAssignmentType === 'temporary_owner' ? 'selected' : ''; ?>>Temporary owner</option>
                            <option value="oversight" <?php echo $selectedAssignmentType === 'oversight' ? 'selected' : ''; ?>>Oversight</option>
                            <option value="contributor" <?php echo $selectedAssignmentType === 'contributor' ? 'selected' : ''; ?>>Contributor</option>
                        </select>
                        <label class="user-function-primary">
                            <input type="radio" name="primary_function_id" value="<?php echo $functionId; ?>" <?php echo $primaryChecked ? 'checked' : ''; ?>>
                            Primary
                        </label>
                    </div>
                <?php endforeach; ?>
                        </div>
                        <small class="user-function-help">
                            Owner and temporary owner count as accountable coverage. Oversight is leadership review; contributor is support.
                            <a href="organization_intelligence_setup.php">Manage Work Ownership setup</a>.
                        </small>
                    </div>
                </div>
        <?php endif; ?>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Workspace Placement</h2>
                        <p>Department is optional formal structure. Leave it unassigned when the team is still forming.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="department_id">Department <span class="admin-help-text">(optional)</span></label>
                            <select id="department_id" name="department_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo (int) $department['id']; ?>" <?php echo ((int) ($_POST['department_id'] ?? ($user['department_id'] ?? 0)) === (int) $department['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (!empty($availableAccessProfiles)): ?>
                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Access Profile</h2>
                        <p>Choose permissions separately from work ownership and department placement.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="access_role_id">Access Profile</label>
                            <select id="access_role_id" name="access_role_id">
                                <option value="0">No access profile assigned (fallback-only)</option>
                                <?php foreach ($availableAccessProfiles as $accessProfile): ?>
                                    <option value="<?php echo (int) $accessProfile['id']; ?>" <?php echo ((int) ($_POST['access_role_id'] ?? ($user['access_role_id'] ?? 0)) === (int) $accessProfile['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($accessProfile['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="admin-help-text">Access Profile controls permissions when RBAC is enabled.</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Verification</h2>
                        <p>Control email verification state for this user.</p>
                    </div>
                    <label class="admin-toggle-row">
                        <input type="checkbox" name="mark_email_verified" value="1" <?php echo !empty($user['email_verified_at']) ? 'checked' : ''; ?>>
                        <strong>Mark email as verified</strong>
                    </label>
                </div>

            <?php
                $cancelParams = ['id' => (int) $userId];
                if ($isCurrentUserSuperAdmin) {
                    $cancelParams['workspace_id'] = (int) $editWorkspaceId;
                }
            ?>
                <div class="form-actions">
                    <a href="user_view.php?<?php echo htmlspecialchars(http_build_query($cancelParams)); ?>" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
