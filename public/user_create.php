<?php
/**
 * Create User Page
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
use CRM\Services\SMTPClient;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Session;

if (!function_exists('userCreateWorkspaceMembershipAccessForRole')) {
    /**
     * @return array{0:string,1:bool}
     */
    function userCreateWorkspaceMembershipAccessForRole(int $workspaceId, string $roleSlug): array
    {
        $roleSlug = trim($roleSlug);
        if (WorkspaceContext::isDefaultWorkspace($workspaceId) && $roleSlug === 'superadmin') {
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

$user = Auth::user();
if (!Authorization::canAccessUsersPage($user) || !Authorization::can('admin.users.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isCurrentUserSuperAdmin = Authorization::isSuperAdmin($user);
if (!$isCurrentUserSuperAdmin) {
    header('Location: settings.php?tab=workspace_governance&error=' . urlencode('Workspace access is invite-only. Use Workspace Team to invite members.'));
    exit;
}

$requestedWorkspaceId = (int) (($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['workspace_id'] ?? 0) : ($_GET['workspace_id'] ?? 0)) ?? 0);
$targetWorkspaceId = ($isCurrentUserSuperAdmin && $requestedWorkspaceId > 0) ? $requestedWorkspaceId : $activeWorkspaceId;
if ($targetWorkspaceId <= 0) {
    header('Location: users.php?error=' . urlencode('Select a workspace before adding users.'));
    exit;
}

$departmentsModule = new Departments();
$departments = $departmentsModule->getActive($targetWorkspaceId);
$organizationFunctions = new OrganizationFunctionService();
$organizationFunctions->ensureDefaults($targetWorkspaceId);
$availableFunctions = $organizationFunctions->listAssignableFunctions($targetWorkspaceId);

$error = null;
$rbacEnabled = Authorization::isRbacAvailable();
$availableAccessProfiles = $rbacEnabled ? Authorization::getAssignableRoles($user) : [];
$postedFunctionIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['function_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$postedPrimaryFunctionId = (int) ($_POST['primary_function_id'] ?? 0);
$postedFunctionAssignmentTypes = [];
foreach ((array) ($_POST['function_assignment_types'] ?? []) as $functionId => $assignmentType) {
    $postedFunctionAssignmentTypes[(int) $functionId] = (string) $assignmentType;
}
$selectedFunctionIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $postedFunctionIds
    : $organizationFunctions->defaultFunctionIdsForRole($targetWorkspaceId, 'viewer');
$selectedPrimaryFunctionId = $postedPrimaryFunctionId > 0 ? $postedPrimaryFunctionId : (int) ($selectedFunctionIds[0] ?? 0);
$backToUsersHref = 'users.php';
if ($isCurrentUserSuperAdmin && $targetWorkspaceId > 0) {
    $backToUsersHref .= '?workspace_id=' . $targetWorkspaceId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $accessRoleId = (int) ($_POST['access_role_id'] ?? 0);
            $firstName = Security::sanitizeInput($_POST['first_name'] ?? '', 'string');
            $lastName = Security::sanitizeInput($_POST['last_name'] ?? '', 'string');
            $jobTitle = Security::sanitizeInput($_POST['job_title'] ?? '', 'string');
            $membershipRoleSlugForValidation = 'viewer';
            $membershipIsOwnerForValidation = false;
            if ($rbacEnabled && $accessRoleId > 0) {
                $roleForValidation = Database::queryOne("SELECT slug FROM roles WHERE id = ? AND is_active = 1", [$accessRoleId]);
                if ($roleForValidation) {
                    [$membershipRoleSlugForValidation, $membershipIsOwnerForValidation] = userCreateWorkspaceMembershipAccessForRole(
                        $targetWorkspaceId,
                        (string) ($roleForValidation['slug'] ?? '')
                    );
                }
            }
            $existing = $email !== '' ? Database::queryOne(
                "SELECT id, email
                 FROM users
                 WHERE LOWER(TRIM(email)) = ?
                 LIMIT 1",
                [$email]
            ) : null;
            $isExistingUser = $existing !== null;

            if ($email === '') {
                $error = 'Email is required.';
            } elseif (!$isExistingUser && $firstName === '') {
                $error = 'First name is required.';
            } elseif (!$isExistingUser && $password === '') {
                $error = 'Password is required for new users.';
            } elseif (!$membershipIsOwnerForValidation && $membershipRoleSlugForValidation !== 'owner' && $availableFunctions !== [] && $postedFunctionIds === []) {
                $error = 'Assign at least one work ownership function.';
            } elseif (!$isExistingUser) {
                $pwdCheck = Security::validatePasswordStrength($password);
                if (!$pwdCheck['valid']) {
                    $error = implode(' ', $pwdCheck['errors']);
                }
            }

            if (!$error) {
                $existingMembership = $isExistingUser ? Database::queryOne(
                    "SELECT id
                     FROM workspace_memberships
                     WHERE workspace_id = ?
                       AND user_id = ?
                       AND membership_status = 'active'
                     LIMIT 1",
                    [$targetWorkspaceId, (int) ($existing['id'] ?? 0)]
                ) : null;

                if ($existingMembership) {
                    $error = 'That user already belongs to this workspace.';
                } elseif ($rbacEnabled && $accessRoleId > 0 && !Authorization::canAssignRole($accessRoleId, $user)) {
                    $error = 'You cannot assign an access profile with more privileges than your own.';
                }
            }

            if (!$error) {
                $selectedDepartment = null;
                if ($departmentId > 0) {
                    $selectedDepartment = $departmentsModule->getById($departmentId, $targetWorkspaceId);
                    if (!$selectedDepartment || (int) ($selectedDepartment['is_active'] ?? 0) !== 1) {
                        throw new \RuntimeException('The selected department is no longer available.');
                    }
                }
                $effectiveDepartmentId = $departmentId > 0 ? $departmentId : null;
                $legacyRole = $selectedDepartment !== null ? $departmentsModule->legacyRoleForDepartment($selectedDepartment) : 'viewer';

                $isNewUser = !$isExistingUser;
                $userId = 0;
                $verifyImmediately = isset($_POST['verify_immediately']);

                Database::beginTransaction();
                try {
                    if ($isNewUser) {
                        $uuid = bin2hex(random_bytes(16));
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        if ($verifyImmediately) {
                            Database::execute(
                                "INSERT INTO users (uuid, first_name, last_name, job_title, email, password_hash, role, department_id, email_verified_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                                [
                                    $uuid,
                                    $firstName,
                                    $lastName !== '' ? $lastName : null,
                                    $jobTitle !== '' ? $jobTitle : null,
                                    $email,
                                    $hashedPassword,
                                    $legacyRole,
                                    $effectiveDepartmentId,
                                ]
                            );
                        } else {
                            Database::execute(
                                "INSERT INTO users (uuid, first_name, last_name, job_title, email, password_hash, role, department_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $uuid,
                                    $firstName,
                                    $lastName !== '' ? $lastName : null,
                                    $jobTitle !== '' ? $jobTitle : null,
                                    $email,
                                    $hashedPassword,
                                    $legacyRole,
                                    $effectiveDepartmentId,
                                ]
                            );
                        }
                        $userId = (int) Database::lastInsertId();
                    } else {
                        $userId = (int) ($existing['id'] ?? 0);
                    }

                    if ($userId <= 0) {
                        throw new \RuntimeException('Unable to resolve the user account.');
                    }

                    $roleRow = null;
                    $membershipRoleSlug = 'viewer';
                    $membershipIsOwner = false;
                    if ($rbacEnabled && $accessRoleId > 0) {
                        $roleRow = Database::queryOne("SELECT id, slug FROM roles WHERE id = ? AND is_active = 1", [$accessRoleId]);
                        if (!$roleRow) {
                            throw new \RuntimeException('The selected access profile is no longer available.');
                        }
                        [$membershipRoleSlug, $membershipIsOwner] = userCreateWorkspaceMembershipAccessForRole(
                            $targetWorkspaceId,
                            (string) ($roleRow['slug'] ?? '')
                        );
                    }

                    (new WorkspaceMembershipService())->addOrUpdateMembership(
                        $targetWorkspaceId,
                        $userId,
                        $membershipRoleSlug,
                        $membershipIsOwner,
                        (int) ($user['id'] ?? 0)
                    );
                    Database::execute(
                        "UPDATE workspace_memberships
                         SET department_id = ?, updated_at = NOW()
                         WHERE workspace_id = ?
                           AND user_id = ?
                           AND membership_status = 'active'",
                        [$effectiveDepartmentId, $targetWorkspaceId, $userId]
                    );
                    if ($isNewUser) {
                        Database::execute(
                            "UPDATE users SET department_id = ?, role = ? WHERE id = ?",
                            [$effectiveDepartmentId, $legacyRole, $userId]
                        );
                    }
                    $functionIds = $postedFunctionIds !== []
                        ? $postedFunctionIds
                        : $organizationFunctions->defaultFunctionIdsForRole($targetWorkspaceId, $membershipRoleSlug, $membershipIsOwner);
                    $organizationFunctions->saveUserAssignments($targetWorkspaceId, $userId, $functionIds, $postedPrimaryFunctionId, $postedFunctionAssignmentTypes);
                    if ($membershipIsOwner || $membershipRoleSlug === 'owner') {
                        $organizationFunctions->ensureOwnerFunctionCoverage($targetWorkspaceId, $userId);
                    }

                    if ($rbacEnabled && $accessRoleId > 0) {
                        if (Authorization::isSuperAdmin($user) && $isNewUser) {
                            Authorization::assignUserRole($userId, $accessRoleId, (int) ($user['id'] ?? 0));
                        }
                        Authorization::assignWorkspaceUserRole($targetWorkspaceId, $userId, $accessRoleId, (int) ($user['id'] ?? 0));
                    }

                    Database::commit();
                } catch (\Throwable $e) {
                    if (Database::getInstance()->inTransaction()) {
                        Database::rollBack();
                    }
                    throw $e;
                }

                if ($isNewUser && !$verifyImmediately) {
                    $token = Auth::generateEmailVerificationToken($userId);
                    try {
                        $basePath = getBasePath();
                        $verifyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath . '/verify_email.php?token=' . urlencode($token);
                        $smtpClient = new SMTPClient();
                        $fromEmail = $smtpClient->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
                        $fromName = $smtpClient->getPreferredFromName(brandProductName()) ?? brandProductName();
                        $bodyText = "Please verify your email by clicking: " . $verifyUrl;
                        $bodyHtml = "Please <a href=\"" . htmlspecialchars($verifyUrl) . "\">verify your email</a>.";
                        $smtpClient->send($email, $fromEmail, $fromName, 'Verify your email', $bodyText, [], $bodyHtml);
                    } catch (\Exception $e) {
                        error_log('Failed to send verification email: ' . $e->getMessage());
                    }
                }

                $viewParams = ['id' => $userId];
                if ($isCurrentUserSuperAdmin) {
                    $viewParams['workspace_id'] = $targetWorkspaceId;
                }
                header('Location: user_view.php?' . http_build_query($viewParams));
                exit;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Add User - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page admin-form-page--wide">
            <div class="admin-hero">
                <div>
                    <h1>Add User</h1>
                    <p>Create a user with clear work ownership, optional workspace placement, and a separate access profile.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="<?php echo htmlspecialchars($backToUsersHref); ?>" class="btn-premium-secondary">
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
                <input type="hidden" name="workspace_id" value="<?php echo (int) $targetWorkspaceId; ?>">

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Identity</h2>
                        <p>Use an existing login account when the email already exists, or create a new one.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="first_name">First Name <span class="admin-help-text">(new users)</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title / Position</label>
                            <input type="text" id="job_title" name="job_title" value="<?php echo htmlspecialchars($_POST['job_title'] ?? ''); ?>" placeholder="e.g. Sales Manager">
                        </div>
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="password">Password <span class="admin-help-text">(new users only)</span></label>
                            <input type="password" id="password" name="password" minlength="8">
                            <small class="admin-help-text">Existing accounts keep their current password. New users need min 8 chars with uppercase, lowercase, number, and special character.</small>
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
                    <?php $selectedAssignmentType = (string) ($postedFunctionAssignmentTypes[$functionId] ?? ($primaryChecked ? 'owner' : 'contributor')); ?>
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
                                    <option value="<?php echo (int) $department['id']; ?>" <?php echo ((int) ($_POST['department_id'] ?? 0) === (int) $department['id']) ? 'selected' : ''; ?>>
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
                                    <option value="<?php echo (int) $accessProfile['id']; ?>" <?php echo ((int) ($_POST['access_role_id'] ?? 0) === (int) $accessProfile['id']) ? 'selected' : ''; ?>>
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
                        <p>Control whether a brand-new user must verify their email.</p>
                    </div>
                    <label class="admin-toggle-row">
                        <input type="checkbox" name="verify_immediately" value="1" <?php echo isset($_POST['verify_immediately']) ? 'checked' : ''; ?>>
                        <strong>Verify new user email immediately (admin override - skip verification email)</strong>
                    </label>
                </div>

                <div class="form-actions">
                    <a href="<?php echo htmlspecialchars($backToUsersHref); ?>" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Add User to Workspace</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
