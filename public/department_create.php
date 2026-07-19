<?php
/**
 * Create department page.
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
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $departmentId = $departmentsModule->create([
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'description' => $_POST['description'] ?? '',
                'is_active' => isset($_POST['is_active']),
            ]);

            header('Location: departments.php?success=' . urlencode('Department created.'));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Department - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Create Department</h1>
                    <p>Add optional org placement without changing Work Ownership or access-profile rules.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="departments.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Departments
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

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Department Details</h2>
                        <p>Name the optional grouping. You can assign Work Ownership separately on user profiles or workspace governance.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="name">Department Name</label>
                            <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="slug">Slug</label>
                            <input id="slug" name="slug" type="text" value="<?php echo htmlspecialchars($_POST['slug'] ?? ''); ?>" placeholder="customer_success">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    <label class="admin-checkbox-card">
                        <input type="checkbox" name="is_active" value="1" <?php echo !isset($_POST['is_active']) || $_POST['is_active'] ? 'checked' : ''; ?>>
                        <span>
                            <strong>Department is active</strong>
                            <small>Active departments can be assigned to users.</small>
                        </span>
                    </label>
                </section>

                <div class="form-actions">
                    <a href="departments.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-save"></i>
                        Create Department
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
