<?php
/**
 * Users List Page
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
use CRM\Session;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::canAccessUsersPage($user)) {
    header('Location: dashboard.php');
    exit;
}

$isSuperAdmin = Authorization::isSuperAdmin($user);
$canCreateUsers = $isSuperAdmin && Authorization::can('admin.users.manage', $user);
$canEditUsers = Authorization::can('admin.users.edit', $user);
$canDeleteUsers = Authorization::can('admin.users.delete', $user);
$canManageDepartments = Authorization::can('org.departments.manage', $user);
$canManageAccessProfiles = Authorization::can('admin.roles.manage', $user);
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$rbacEnabled = Authorization::isRbacAvailable();
$currentUserId = (int) ($user['id'] ?? 0);

$departmentFilter = trim((string) ($_GET['department'] ?? ''));
$workspaceFilter = $isSuperAdmin ? max(0, (int) ($_GET['workspace_id'] ?? 0)) : $activeWorkspaceId;
$roleWorkspaceId = $workspaceFilter > 0 ? $workspaceFilter : $activeWorkspaceId;
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$where[] = "COALESCE(u.is_demo_guest, 0) = 0";

if (!$isSuperAdmin) {
    $where[] = "EXISTS (
        SELECT 1
        FROM workspace_memberships wm_scope
        WHERE wm_scope.user_id = u.id
          AND wm_scope.workspace_id = ?
          AND wm_scope.membership_status = 'active'
    )";
    $params[] = $activeWorkspaceId;
    if ($rbacEnabled) {
        $where[] = "(gr.slug IS NULL OR gr.slug <> 'superadmin')";
    }
} elseif ($workspaceFilter > 0) {
    $where[] = "EXISTS (
        SELECT 1
        FROM workspace_memberships wm_scope
        WHERE wm_scope.user_id = u.id
          AND wm_scope.workspace_id = ?
          AND wm_scope.membership_status = 'active'
    )";
    $params[] = $workspaceFilter;
}

if ($departmentFilter !== '') {
    $where[] = "(CASE WHEN wm_hr_department.id IS NOT NULL THEN workspace_department.slug ELSE legacy_department.slug END) = ?";
    $params[] = $departmentFilter;
}

if ($search !== '') {
    $where[] = "(u.email LIKE ? OR CONCAT_WS(' ', COALESCE(u.first_name, ''), COALESCE(u.last_name, '')) LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
$departmentsModule = new Departments();
$departments = $departmentsModule->getAll(true, $roleWorkspaceId);
$workspaces = $isSuperAdmin ? Database::query(
    "SELECT id, name, slug, status, plan_status
     FROM workspaces
     ORDER BY name ASC"
) : [];
$usersMissingAccessProfile = 0;

if ($rbacEnabled && $roleWorkspaceId > 0) {
    $missingAccessProfileWhere = [
        "wm.workspace_id = ?",
        "wm.membership_status = 'active'",
        "wur.user_id IS NULL",
        "COALESCE(u.is_demo_guest, 0) = 0",
    ];
    $missingAccessProfileParams = [$roleWorkspaceId, $roleWorkspaceId];
    if (!$isSuperAdmin) {
        $missingAccessProfileWhere[] = "(gr.slug IS NULL OR gr.slug <> 'superadmin')";
    }

    $usersMissingAccessProfile = (int) ((Database::queryOne(
        "SELECT COUNT(*) AS aggregate_count
         FROM workspace_memberships wm
         JOIN users u ON u.id = wm.user_id
         LEFT JOIN workspace_user_roles wur ON wur.workspace_id = ? AND wur.user_id = wm.user_id
         LEFT JOIN user_roles gur ON gur.user_id = wm.user_id
         LEFT JOIN roles gr ON gr.id = gur.role_id
         WHERE " . implode(' AND ', $missingAccessProfileWhere),
        $missingAccessProfileParams
    )['aggregate_count'] ?? 0));
}

$roleSelect = $rbacEnabled ? "COALESCE(wr.name, r.name) AS access_role_name, COALESCE(wr.slug, r.slug) AS access_role_slug" : "NULL AS access_role_name, NULL AS access_role_slug";
$roleJoins = $rbacEnabled ? "LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     LEFT JOIN user_roles gur ON gur.user_id = u.id
     LEFT JOIN roles gr ON gr.id = gur.role_id
     LEFT JOIN workspace_user_roles wur ON wur.user_id = u.id AND wur.workspace_id = {$roleWorkspaceId}
     LEFT JOIN roles wr ON wr.id = wur.role_id" : "";
$departmentJoins = "LEFT JOIN workspace_memberships wm_hr_department ON wm_hr_department.user_id = u.id AND wm_hr_department.workspace_id = {$roleWorkspaceId} AND wm_hr_department.membership_status = 'active'
     LEFT JOIN departments workspace_department ON workspace_department.id = wm_hr_department.department_id AND workspace_department.workspace_id = {$roleWorkspaceId}
     LEFT JOIN departments legacy_department ON legacy_department.id = u.department_id";
$workspaceSummarySelect = "(
        SELECT GROUP_CONCAT(
            DISTINCT CONCAT_WS('::', w.id, w.name, w.slug, wm_summary.role_slug, wm_summary.membership_status, wm_summary.is_owner)
            ORDER BY w.name ASC
            SEPARATOR '||'
        )
        FROM workspace_memberships wm_summary
        JOIN workspaces w ON w.id = wm_summary.workspace_id
        WHERE wm_summary.user_id = u.id
          AND wm_summary.membership_status = 'active'
    ) AS workspace_memberships_summary";

$users = Database::query(
    "SELECT u.id, u.email, u.first_name, u.last_name, u.last_login, u.created_at,
            CASE WHEN wm_hr_department.id IS NOT NULL THEN workspace_department.name ELSE legacy_department.name END AS department_name,
            CASE WHEN wm_hr_department.id IS NOT NULL THEN workspace_department.slug ELSE legacy_department.slug END AS department_slug,
            {$roleSelect},
            {$workspaceSummarySelect}
     FROM users u
     {$departmentJoins}
     {$roleJoins}
     {$whereClause}
     ORDER BY u.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$organizationFunctions = new OrganizationFunctionService();
$userFunctionMap = [];
$parseWorkspaceSummaries = static function (array $userRow): array {
    $workspaceSummaries = [];
    $rawWorkspaceSummary = trim((string) ($userRow['workspace_memberships_summary'] ?? ''));
    if ($rawWorkspaceSummary === '') {
        return [];
    }

    foreach (explode('||', $rawWorkspaceSummary) as $workspaceSummary) {
        [$workspaceId, $workspaceName, $workspaceSlug, $workspaceRole, $membershipStatus, $isOwner] = array_pad(explode('::', $workspaceSummary), 6, '');
        if ($workspaceName === '') {
            continue;
        }
        $isOwner = (int) $isOwner === 1 || $workspaceRole === 'owner';
        $workspaceSummaries[] = [
            'id' => (int) $workspaceId,
            'name' => $workspaceName,
            'slug' => $workspaceSlug,
            'role' => $isOwner ? 'owner' : ($workspaceRole !== '' ? $workspaceRole : 'member'),
            'status' => $membershipStatus,
            'is_owner' => $isOwner,
        ];
    }

    return $workspaceSummaries;
};
$rowWorkspaceContext = static function (array $workspaceSummaries) use ($isSuperAdmin, $workspaceFilter, $activeWorkspaceId): int {
    if (!$isSuperAdmin) {
        return $activeWorkspaceId;
    }
    if ($workspaceFilter > 0) {
        return $workspaceFilter;
    }
    if (count($workspaceSummaries) === 1) {
        return (int) ($workspaceSummaries[0]['id'] ?? 0);
    }

    return 0;
};
$workspaceIdsToRepair = [];
$assignmentWorkspaceIds = [];
foreach ($users as $index => $userRow) {
    $userId = (int) ($userRow['id'] ?? 0);
    $workspaceSummaries = $parseWorkspaceSummaries($userRow);
    $rowWorkspaceContextId = $rowWorkspaceContext($workspaceSummaries);
    $users[$index]['__workspace_summaries'] = $workspaceSummaries;
    $users[$index]['__workspace_context_id'] = $rowWorkspaceContextId;

    if ($rowWorkspaceContextId > 0) {
        $assignmentWorkspaceIds[$rowWorkspaceContextId] = true;
        foreach ($workspaceSummaries as $workspaceSummary) {
            if ((int) ($workspaceSummary['id'] ?? 0) === $rowWorkspaceContextId && !empty($workspaceSummary['is_owner'])) {
                $workspaceIdsToRepair[$rowWorkspaceContextId] = true;
                break;
            }
        }
    }
}
foreach (array_keys($workspaceIdsToRepair) as $workspaceIdToRepair) {
    $organizationFunctions->ensureOwnerFunctionCoverageForWorkspace((int) $workspaceIdToRepair);
}
if ($users !== [] && $assignmentWorkspaceIds !== []) {
    $workspaceAssignmentCache = [];
    foreach (array_keys($assignmentWorkspaceIds) as $assignmentWorkspaceId) {
        $workspaceAssignmentCache[(int) $assignmentWorkspaceId] = $organizationFunctions->assignmentsForWorkspace((int) $assignmentWorkspaceId);
    }
    foreach ($users as $userRow) {
        $userId = (int) ($userRow['id'] ?? 0);
        $rowWorkspaceContextId = (int) ($userRow['__workspace_context_id'] ?? 0);
        if ($rowWorkspaceContextId <= 0) {
            continue;
        }
        $userFunctionMap[$userId] = $workspaceAssignmentCache[$rowWorkspaceContextId][$userId] ?? [];
    }
}

$totalUsers = (int) (Database::queryOne(
    "SELECT COUNT(DISTINCT u.id) AS count
     FROM users u
     {$departmentJoins}
     {$roleJoins}
     {$whereClause}",
    $params
)['count'] ?? 0);

$totalPages = (int) ceil($totalUsers / $limit);
$successMessage = $_GET['success'] ?? '';
$errorMessage = $_GET['error'] ?? '';
$baseFilterParams = array_filter([
    'search' => $search,
    'department' => $departmentFilter,
    'workspace_id' => $isSuperAdmin && $workspaceFilter > 0 ? (string) $workspaceFilter : '',
], static fn ($value): bool => $value !== '');
$filterQuery = static function (array $overrides = []) use ($baseFilterParams): string {
    $params = array_merge($baseFilterParams, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return http_build_query($params);
};
$createUserHref = 'user_create.php';
if ($isSuperAdmin && $workspaceFilter > 0) {
    $createUserHref .= '?workspace_id=' . $workspaceFilter;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: users.php?error=' . urlencode('Invalid security token. Please try again.'));
        exit;
    }

    $bulkAction = trim((string) ($_POST['bulk_action'] ?? ''));
    $selectedUserIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => (int) $value,
        (array) ($_POST['selected_user_ids'] ?? [])
    ), static fn(int $value): bool => $value > 0)));
    $returnQuery = trim((string) ($_POST['return_query'] ?? ''));
    $returnUrl = 'users.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    $returnJoin = str_contains($returnUrl, '?') ? '&' : '?';

    if ($bulkAction !== 'delete_users') {
        header('Location: ' . $returnUrl . $returnJoin . 'error=' . urlencode('Unsupported bulk action.'));
        exit;
    }

    if (!$canDeleteUsers) {
        header('Location: ' . $returnUrl . $returnJoin . 'error=' . urlencode('You do not have permission to delete users.'));
        exit;
    }

    if ($selectedUserIds === []) {
        header('Location: ' . $returnUrl . $returnJoin . 'error=' . urlencode('Select at least one user.'));
        exit;
    }

    $deletedCount = 0;
    $skipped = [];
    $bulkWorkspaceScoped = !$isSuperAdmin || $workspaceFilter > 0;
    $bulkScopeWorkspaceId = $isSuperAdmin && $workspaceFilter > 0 ? $workspaceFilter : $activeWorkspaceId;

    try {
        Database::beginTransaction();

        if (!$bulkWorkspaceScoped && $rbacEnabled) {
            $elevatedAdminCount = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS aggregate_count
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE r.slug IN ('admin', 'superadmin') AND r.is_active = 1"
            )['aggregate_count'] ?? 0));
        } elseif (!$bulkWorkspaceScoped) {
            $elevatedAdminCount = (int) (Database::queryOne("SELECT COUNT(*) AS aggregate_count FROM users WHERE role = 'admin'")['aggregate_count'] ?? 0);
        } else {
            $elevatedAdminCount = 0;
        }

        foreach ($selectedUserIds as $targetUserId) {
            if ($targetUserId === $currentUserId) {
                $skipped[] = 'self';
                continue;
            }

            if (!Authorization::canManageUserTarget($targetUserId, $user)) {
                $skipped[] = 'restricted';
                continue;
            }

            if ($bulkWorkspaceScoped) {
                if ($bulkScopeWorkspaceId <= 0) {
                    $skipped[] = 'workspace_missing';
                    continue;
                }

                $targetMembership = Database::queryOne(
                    "SELECT id, role_slug, is_owner
                     FROM workspace_memberships
                     WHERE workspace_id = ?
                       AND user_id = ?
                       AND membership_status = 'active'
                     LIMIT 1",
                    [$bulkScopeWorkspaceId, $targetUserId]
                );
                if (!$targetMembership) {
                    $skipped[] = 'not_in_workspace';
                    continue;
                }

                $targetIsWorkspaceOwner = !empty($targetMembership['is_owner']) || (string) ($targetMembership['role_slug'] ?? '') === 'owner';
                if ($targetIsWorkspaceOwner) {
                    $workspaceOwnerCount = (int) ((Database::queryOne(
                        "SELECT COUNT(*) AS aggregate_count
                         FROM workspace_memberships
                         WHERE workspace_id = ?
                           AND membership_status = 'active'
                           AND (is_owner = 1 OR role_slug = 'owner')",
                        [$bulkScopeWorkspaceId]
                    )['aggregate_count'] ?? 0));
                    if ($workspaceOwnerCount <= 1) {
                        $skipped[] = 'last_owner';
                        continue;
                    }
                }

                Database::execute(
                    "UPDATE workspace_memberships
                     SET membership_status = 'removed', updated_at = NOW()
                     WHERE workspace_id = ?
                       AND user_id = ?
                       AND membership_status = 'active'",
                    [$bulkScopeWorkspaceId, $targetUserId]
                );
                Database::execute(
                    "DELETE FROM workspace_user_roles WHERE workspace_id = ? AND user_id = ?",
                    [$bulkScopeWorkspaceId, $targetUserId]
                );

                $deletedCount++;
                continue;
            }

            $target = Database::queryOne("SELECT id, role FROM users WHERE id = ? LIMIT 1", [$targetUserId]);
            if (!$target) {
                $skipped[] = 'missing';
                continue;
            }

            if ($rbacEnabled) {
                $targetRoleSlug = (string) ((Database::queryOne(
                    "SELECT r.slug
                     FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = ?
                     LIMIT 1",
                    [$targetUserId]
                )['slug'] ?? ''));
                $isElevatedAdminAccount = in_array($targetRoleSlug, ['admin', 'superadmin'], true);
            } else {
                $isElevatedAdminAccount = (string) ($target['role'] ?? '') === 'admin';
            }

            if ($isSuperAdmin && $isElevatedAdminAccount && $elevatedAdminCount <= 1) {
                $skipped[] = 'last_admin';
                continue;
            }

            (new OwnerHelpExpertService())->deleteProfilesForUsers([$targetUserId]);
            Database::execute("DELETE FROM users WHERE id = ?", [$targetUserId]);
            if ($isElevatedAdminAccount) {
                $elevatedAdminCount = max(0, $elevatedAdminCount - 1);
            }

            $deletedCount++;
        }

        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        header('Location: ' . $returnUrl . $returnJoin . 'error=' . urlencode('Unable to complete bulk delete. Remove dependent records and try again.'));
        exit;
    }

    $message = !$bulkWorkspaceScoped
        ? sprintf('Bulk delete complete. Deleted %d user account%s.', $deletedCount, $deletedCount === 1 ? '' : 's')
        : sprintf('Bulk delete complete. Removed %d user%s from this workspace.', $deletedCount, $deletedCount === 1 ? '' : 's');
    if ($skipped !== []) {
        $message .= ' Skipped ' . count($skipped) . ' protected or unavailable selection' . (count($skipped) === 1 ? '' : 's') . '.';
    }

    header('Location: ' . $returnUrl . $returnJoin . 'success=' . urlencode($message));
    exit;
}

$departmentStats = [];
$totalDepartmentUsers = 0;
foreach ($departments as $department) {
    $count = (int) ($department['users_count'] ?? 0);
    $departmentStats[(string) $department['slug']] = [
        'name' => (string) $department['name'],
        'count' => $count,
    ];
    $totalDepartmentUsers += $count;
}

$pageTitle = 'Users - ' . brandProductName();
$usersGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_USERS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <?php if ($successMessage !== ''): ?>
            <div class="premium-banner premium-banner-success">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="premium-banner premium-banner-error">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1>Users</h1>
                <p>Manage work ownership, optional departments, and access profiles independently.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($usersGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_USERS, 'Users page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <?php if ($canManageDepartments): ?>
                    <a href="departments.php" class="btn-premium-secondary">
                        <i class="fas fa-sitemap"></i>
                        Manage Departments
                    </a>
                <?php endif; ?>
                <?php if ($canManageAccessProfiles): ?>
                    <a href="roles.php" class="btn-premium-secondary">
                        <i class="fas fa-user-shield"></i>
                        Manage Access Profiles
                    </a>
                <?php endif; ?>
                <?php if ($rbacEnabled): ?>
                    <a href="rbac_audit.php" class="btn-premium-secondary">
                        <i class="fas fa-shield-alt"></i>
                        RBAC Audit
                    </a>
                <?php endif; ?>
                <?php if ($canCreateUsers): ?>
                    <a href="<?php echo htmlspecialchars($createUserHref); ?>" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        Add User
                    </a>
                <?php elseif (!$isSuperAdmin): ?>
                    <a href="settings.php?tab=workspace_governance" class="btn-premium-primary">
                        <i class="fas fa-user-plus"></i>
                        Invite Team Member
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rbacEnabled && $usersMissingAccessProfile > 0): ?>
            <div class="premium-banner premium-banner-warning">
                <?php echo $usersMissingAccessProfile; ?> user<?php echo $usersMissingAccessProfile === 1 ? '' : 's'; ?> in this workspace still have no access profile assigned. RBAC is authoritative now, so review them in <a href="rbac_audit.php">RBAC Audit</a>.
            </div>
        <?php endif; ?>

        <div class="admin-filter-card">
            <form method="GET" action="" class="admin-filter-form">
                <div class="filter-group admin-filter-group admin-filter-group--wide">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email...">
                </div>
                <?php if ($isSuperAdmin): ?>
                    <div class="filter-group admin-filter-group">
                        <label for="workspace_id">Workspace</label>
                        <select id="workspace_id" name="workspace_id">
                            <option value="">All Workspaces</option>
                            <?php foreach ($workspaces as $workspace): ?>
                                <option value="<?php echo (int) $workspace['id']; ?>" <?php echo $workspaceFilter === (int) $workspace['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $workspace['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="filter-group admin-filter-group">
                    <label for="department">Department</label>
                    <select id="department" name="department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?php echo htmlspecialchars((string) $department['slug']); ?>" <?php echo $departmentFilter === (string) $department['slug'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $department['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter-actions">
                    <button type="submit" class="btn-premium-primary">Filter</button>
                    <?php if ($search !== '' || $departmentFilter !== '' || ($isSuperAdmin && $workspaceFilter > 0)): ?>
                        <a href="users.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="admin-chip-row">
            <?php $allDepartmentsQuery = $filterQuery(['department' => null, 'page' => null]); ?>
            <a href="users.php<?php echo $allDepartmentsQuery !== '' ? '?' . htmlspecialchars($allDepartmentsQuery) : ''; ?>" class="admin-filter-chip<?php echo $departmentFilter === '' ? ' is-active' : ''; ?>">
                All (<?php echo $totalUsers; ?>)
            </a>
            <?php foreach ($departmentStats as $slug => $stat): ?>
                <?php $departmentChipQuery = $filterQuery(['department' => $slug, 'page' => null]); ?>
                <a href="?<?php echo htmlspecialchars($departmentChipQuery); ?>" class="admin-filter-chip<?php echo $departmentFilter === $slug ? ' is-active' : ''; ?>">
                    <?php echo htmlspecialchars($stat['name']); ?> (<?php echo (int) $stat['count']; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <?php if ($users === []): ?>
                <div class="empty-state">
                    <p>No users found.</p>
                    <?php if ($canCreateUsers): ?>
                        <a href="<?php echo htmlspecialchars($createUserHref); ?>" class="btn-premium-primary">Add your first user</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="POST" action="" id="users-bulk-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="bulk_action" value="delete_users">
                    <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($filterQuery(['page' => $page])); ?>">
                    <?php if ($canDeleteUsers): ?>
                        <div class="users-bulk-bar">
                            <div class="users-bulk-left">
                                <strong id="users-selected-count">0 selected</strong>
                                <span class="premium-inline-note"><?php echo (!$isSuperAdmin || $workspaceFilter > 0) ? 'Removes selected users from this workspace.' : 'Deletes selected platform-only user accounts globally.'; ?></span>
                            </div>
                            <button type="submit" id="users-bulk-delete" class="btn-premium-danger" disabled>
                                Bulk Delete
                            </button>
                        </div>
                    <?php endif; ?>
                <div class="table-card-scroll">
                <table class="premium-table">
                    <thead>
                        <tr>
                            <?php if ($canDeleteUsers): ?>
                                <th class="users-select-cell">
                                    <input type="checkbox" id="users-select-all" aria-label="Select all deletable users">
                                </th>
                            <?php endif; ?>
                            <th>User</th>
                            <?php if ($isSuperAdmin): ?><th>Workspace</th><?php endif; ?>
                            <th>Department</th>
                            <th>Work Ownership</th>
                            <th>Access Profile</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $userRow): ?>
                            <?php
                                $workspaceSummaries = (array) ($userRow['__workspace_summaries'] ?? []);
                                $rowWorkspaceContextId = (int) ($userRow['__workspace_context_id'] ?? 0);
                                $userActionParams = ['id' => (int) $userRow['id']];
                                if ($isSuperAdmin && $rowWorkspaceContextId > 0) {
                                    $userActionParams['workspace_id'] = $rowWorkspaceContextId;
                                }
                                $userActionQuery = htmlspecialchars(http_build_query($userActionParams));
                                $canOpenWorkspaceEdit = !$isSuperAdmin || $rowWorkspaceContextId > 0;
                                $canOpenDelete = !$isSuperAdmin || $rowWorkspaceContextId > 0 || $workspaceSummaries === [];
                                $canManageTarget = Authorization::canManageUserTarget((int) $userRow['id'], $user);
                                $canBulkDeleteTarget = $canDeleteUsers
                                    && $canManageTarget
                                    && (int) $userRow['id'] !== $currentUserId
                                    && (!$isSuperAdmin || $workspaceFilter > 0 || $workspaceSummaries === []);
                                $deleteActionLabel = ($isSuperAdmin && $workspaceSummaries === [] && $rowWorkspaceContextId <= 0) ? 'Delete' : 'Remove';
                            ?>
                            <tr>
                                <?php if ($canDeleteUsers): ?>
                                    <td class="users-select-cell">
                                        <?php if ($canBulkDeleteTarget): ?>
                                            <input type="checkbox" class="users-row-checkbox" name="selected_user_ids[]" value="<?php echo (int) $userRow['id']; ?>" aria-label="Select <?php echo htmlspecialchars((string) $userRow['email']); ?>">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div class="admin-meta-value">
                                        <?php echo htmlspecialchars(trim((string) (($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''))) ?: (string) $userRow['email']); ?>
                                    </div>
                                    <div class="admin-muted"><?php echo htmlspecialchars((string) $userRow['email']); ?></div>
                                </td>
                                <?php if ($isSuperAdmin): ?>
                                    <td>
                                        <?php if ($workspaceSummaries === []): ?>
                                            <span class="badge badge-default">Platform only</span>
                                        <?php else: ?>
                                            <div class="users-workspace-stack">
                                                <?php foreach (array_slice($workspaceSummaries, 0, 2) as $workspaceSummary): ?>
                                                    <span class="users-workspace-pill" title="<?php echo htmlspecialchars((string) $workspaceSummary['slug']); ?>">
                                                        <span class="users-workspace-name"><?php echo htmlspecialchars((string) $workspaceSummary['name']); ?></span>
                                                        <span class="users-workspace-role"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $workspaceSummary['role'])); ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($workspaceSummaries) > 2): ?>
                                                    <span class="users-workspace-more">+<?php echo count($workspaceSummaries) - 2; ?> more workspace<?php echo count($workspaceSummaries) - 2 === 1 ? '' : 's'; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars(trim((string) ($userRow['department_name'] ?? '')) !== '' ? (string) $userRow['department_name'] : 'Unassigned'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $rowFunctions = (array) ($userFunctionMap[(int) $userRow['id']] ?? []); ?>
                                    <?php if ($rowFunctions !== []): ?>
                                        <?php
                                            $primaryFunction = $rowFunctions[0];
                                            foreach ($rowFunctions as $candidateFunction) {
                                                if (!empty($candidateFunction['is_primary'])) {
                                                    $primaryFunction = $candidateFunction;
                                                    break;
                                                }
                                            }
                                        ?>
                                        <div class="users-function-stack" title="<?php echo htmlspecialchars(count($rowFunctions) . ' work ownership assignment(s)'); ?>">
                                            <span class="users-function-chip"><span><?php echo htmlspecialchars((string) ($primaryFunction['name'] ?? 'Function')); ?></span></span>
                                            <?php if (count($rowFunctions) > 1): ?>
                                                <span class="users-function-more">+<?php echo count($rowFunctions) - 1; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($isSuperAdmin && $workspaceFilter <= 0 && count($workspaceSummaries) > 1): ?>
                                        <span class="users-function-chip"><span>Select workspace</span></span>
                                    <?php else: ?>
                                        <span class="users-function-chip users-function-missing"><span>Missing ownership</span></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars((string) ($userRow['access_role_name'] ?? 'No access profile')); ?>
                                    </span>
                                    <?php if (empty($userRow['access_role_name']) && $rbacEnabled): ?>
                                        <small class="admin-help-text">Fallback-only / no RBAC access</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $userRow['last_login'] ? date('M j, Y g:i A', strtotime($userRow['last_login'])) : 'Never'; ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($userRow['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="user_view.php?<?php echo $userActionQuery; ?>" class="admin-text-link">View</a>
                                        <?php if ($canEditUsers && $canManageTarget): ?>
                                            <?php if ($canOpenWorkspaceEdit): ?>
                                                <a href="user_edit.php?<?php echo $userActionQuery; ?>" class="admin-text-link">Edit</a>
                                            <?php else: ?>
                                                <span class="admin-muted" title="Select a workspace before editing workspace-specific user access.">Select workspace</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($canDeleteUsers && $canManageTarget && (int) $userRow['id'] !== $currentUserId): ?>
                                            <?php if ($canOpenDelete): ?>
                                                <a href="user_delete.php?<?php echo $userActionQuery; ?>" class="admin-text-link admin-text-link--danger"><?php echo htmlspecialchars($deleteActionLabel); ?></a>
                                            <?php else: ?>
                                                <span class="admin-muted" title="Select a workspace before removing workspace-specific user access.">Select workspace</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </form>

                <?php if ($totalPages > 1): ?>
                    <div class="admin-table-footer">
                        <div class="admin-muted">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalUsers); ?> of <?php echo $totalUsers; ?> users
                        </div>
                        <div class="admin-pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo htmlspecialchars($filterQuery(['page' => $page - 1])); ?>" class="admin-page-link">Previous</a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?<?php echo htmlspecialchars($filterQuery(['page' => $i])); ?>" class="admin-page-link<?php echo $i === $page ? ' is-active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo htmlspecialchars($filterQuery(['page' => $page + 1])); ?>" class="admin-page-link">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canDeleteUsers && $users !== []): ?>
    <script>
        (function () {
            var form = document.getElementById('users-bulk-form');
            var selectAll = document.getElementById('users-select-all');
            var deleteButton = document.getElementById('users-bulk-delete');
            var selectedCount = document.getElementById('users-selected-count');
            if (!form || !deleteButton || !selectedCount) {
                return;
            }

            function rowCheckboxes() {
                return Array.prototype.slice.call(form.querySelectorAll('.users-row-checkbox'));
            }

            function updateBulkState() {
                var boxes = rowCheckboxes();
                var checked = boxes.filter(function (box) { return box.checked; });
                selectedCount.textContent = checked.length + ' selected';
                deleteButton.disabled = checked.length === 0;
                if (selectAll) {
                    selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
                    selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    rowCheckboxes().forEach(function (box) {
                        box.checked = selectAll.checked;
                    });
                    updateBulkState();
                });
            }

            rowCheckboxes().forEach(function (box) {
                box.addEventListener('change', updateBulkState);
            });

            form.addEventListener('submit', function (event) {
                var checked = rowCheckboxes().filter(function (box) { return box.checked; });
                if (checked.length === 0) {
                    event.preventDefault();
                    return;
                }

                var message = <?php echo json_encode($isSuperAdmin ? 'Delete selected user accounts globally? This cannot be undone.' : 'Remove selected users from this workspace? Their accounts remain available elsewhere.'); ?>;
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });

            updateBulkState();
        })();
    </script>
<?php endif; ?>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_USERS, 'How to use Users', $usersGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
