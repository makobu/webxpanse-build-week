<?php
/**
 * Edit department page.
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

$departmentId = (int) ($_GET['id'] ?? 0);
if ($departmentId <= 0) {
    header('Location: departments.php');
    exit;
}

$departmentsModule = new Departments();
$department = $departmentsModule->getById($departmentId);
if (!$department) {
    header('Location: departments.php');
    exit;
}

$error = null;
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $departmentsModule->update($departmentId, [
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'description' => $_POST['description'] ?? '',
                'is_active' => isset($_POST['is_active']),
            ]);

            header('Location: department_edit.php?id=' . $departmentId . '&success=' . urlencode('Department updated.'));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$department = $departmentsModule->getById($departmentId);
$departmentMembers = $departmentsModule->membersWithWorkOwnership($departmentId);
$departmentOwnershipSuggestions = $departmentsModule->suggestedWorkOwnershipForDepartment($department);
$pageTitle = 'Edit Department - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<link rel="stylesheet" href="assets/css/utility-forms-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Edit Department</h1>
                    <p><?php echo htmlspecialchars($department['slug']); ?> · optional workspace placement</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="departments.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Departments
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

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Department Details</h2>
                        <p>System department slugs are locked to protect internal routing.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="name">Department Name</label>
                            <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($_POST['name'] ?? $department['name']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="slug">Slug</label>
                            <input
                                id="slug"
                                name="slug"
                                type="text"
                                value="<?php echo htmlspecialchars($_POST['slug'] ?? $department['slug']); ?>"
                                <?php echo (int) $department['is_system'] === 1 ? 'readonly class="utility-readonly-input"' : ''; ?>
                            >
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? (string) ($department['description'] ?? '')); ?></textarea>
                    </div>
                </section>

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Assignment Context</h2>
                        <p>Departments group people. Work Ownership below shows actual accountability and stays editable from user profiles or workspace governance.</p>
                    </div>
                    <div class="admin-meta-grid">
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Assigned Users</div>
                            <div class="admin-meta-value"><?php echo (int) ($department['users_count'] ?? 0); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Department Type</div>
                            <div class="admin-meta-value"><?php echo (int) $department['is_system'] === 1 ? 'System' : 'Custom'; ?></div>
                        </div>
                    </div>
                    <?php if ($departmentOwnershipSuggestions !== []): ?>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Suggested Work Ownership</div>
                            <div class="users-function-stack" style="margin-top:.35rem;">
                                <?php foreach ($departmentOwnershipSuggestions as $suggestion): ?>
                                    <span class="users-function-chip"><span><?php echo htmlspecialchars((string) ($suggestion['name'] ?? 'Work Ownership')); ?></span></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="admin-muted" style="margin-top:.35rem;">Suggestions are only guidance. They are not assigned automatically.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($departmentMembers !== []): ?>
                        <div class="admin-list-stack" style="display:grid;gap:.75rem;margin-top:1rem;">
                            <?php foreach ($departmentMembers as $member): ?>
                                <?php
                                $displayName = trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')));
                                if ($displayName === '') {
                                    $displayName = (string) ($member['email'] ?? 'Member');
                                }
                                $workOwnership = (array) ($member['work_ownership'] ?? []);
                                ?>
                                <div class="admin-meta-item">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($displayName); ?></strong>
                                            <div class="admin-muted"><?php echo htmlspecialchars((string) ($member['email'] ?? '')); ?></div>
                                        </div>
                                        <a class="admin-text-link" href="user_edit.php?id=<?php echo (int) ($member['user_id'] ?? 0); ?>">Edit member</a>
                                    </div>
                                    <div class="users-function-stack" style="margin-top:.5rem;">
                                        <?php if ($workOwnership !== []): ?>
                                            <?php foreach ($workOwnership as $assignment): ?>
                                                <span class="users-function-chip<?php echo !empty($assignment['is_primary']) ? ' user-view-function-chip-primary' : ''; ?>">
                                                    <span><?php echo htmlspecialchars((string) ($assignment['name'] ?? 'Work Ownership')); ?><?php echo !empty($assignment['is_primary']) ? ' (Primary)' : ''; ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="users-function-chip users-function-missing"><span>Missing Work Ownership</span></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <label class="admin-checkbox-card">
                        <input type="checkbox" name="is_active" value="1" <?php echo (isset($_POST['is_active']) ? true : ((int) ($department['is_active'] ?? 0) === 1)) ? 'checked' : ''; ?> <?php echo ((int) ($department['users_count'] ?? 0) > 0 && (int) $department['is_active'] === 1) ? '' : ''; ?>>
                        <span>
                            <strong>Department is active</strong>
                            <?php if ((int) ($department['users_count'] ?? 0) > 0): ?>
                                <small>Users must be reassigned before this department can be deactivated.</small>
                            <?php else: ?>
                                <small>Inactive departments are hidden from new user assignments.</small>
                            <?php endif; ?>
                        </span>
                    </label>
                </section>

                <div class="form-actions">
                    <a href="departments.php" class="btn-premium-secondary">Back</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-save"></i>
                        Save Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
