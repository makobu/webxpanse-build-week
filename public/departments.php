<?php
/**
 * Departments management page.
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

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

Authorization::requirePermission('org.departments.manage');

$departmentsModule = new Departments();
$error = '';
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);

        try {
            if ($action === 'delete') {
                $departmentsModule->delete($departmentId);
                header('Location: departments.php?success=' . urlencode('Department deleted.'));
                exit;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$departments = $departmentsModule->getAll(true);
$departmentOwnershipSummary = $departmentsModule->workOwnershipSummaryByDepartment();

$pageTitle = 'Departments - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1>Departments</h1>
                    <p>Use departments as optional org placement. Work Ownership still defines who owns or supports the work.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="users.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                    <a href="department_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        Create Department
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

            <div class="table-card">
                <?php if (empty($departments)): ?>
                    <div class="empty-state">
                        <p>No departments found.</p>
                        <a href="department_create.php">Create your first department -></a>
                    </div>
                <?php else: ?>
                    <div class="table-card-scroll">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Slug</th>
                                    <th>Users</th>
                                    <th>Work Ownership</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($departments as $department): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($department['name']); ?></strong>
                                        <div class="admin-muted"><?php echo htmlspecialchars((string) ($department['description'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <span class="admin-code-inline"><?php echo htmlspecialchars($department['slug']); ?></span>
                                    </td>
                                    <td><?php echo (int) $department['users_count']; ?></td>
                                    <td>
                                        <?php $ownershipSummary = (array) ($departmentOwnershipSummary[(int) ($department['id'] ?? 0)] ?? []); ?>
                                        <?php if ((int) ($ownershipSummary['active_members'] ?? 0) > 0): ?>
                                            <strong><?php echo (int) ($ownershipSummary['assigned_members'] ?? 0); ?> assigned</strong>
                                            <div class="admin-muted"><?php echo (int) ($ownershipSummary['missing_members'] ?? 0); ?> missing Work Ownership</div>
                                            <?php if (!empty($ownershipSummary['primary_areas'])): ?>
                                                <div class="users-function-stack" style="margin-top:.35rem;">
                                                    <?php foreach (array_slice((array) $ownershipSummary['primary_areas'], 0, 3) as $areaName): ?>
                                                        <span class="users-function-chip"><span><?php echo htmlspecialchars((string) $areaName); ?></span></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="admin-muted">No active members</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $department['is_active'] === 1): ?>
                                            <span class="admin-status-badge admin-status-badge--success">Active</span>
                                        <?php else: ?>
                                            <span class="admin-status-badge admin-status-badge--neutral">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="admin-status-badge admin-status-badge--info">
                                            <?php echo (int) $department['is_system'] === 1 ? 'System' : 'Custom'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="premium-inline-actions">
                                            <a href="department_edit.php?id=<?php echo (int) $department['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                            <?php if ((int) $department['is_system'] !== 1): ?>
                                                <form method="POST" action="" onsubmit="return confirm('Delete this department? Users must already be reassigned.');" class="admin-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="department_id" value="<?php echo (int) $department['id']; ?>">
                                                    <button type="submit" class="btn-premium-danger btn-premium-sm">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
