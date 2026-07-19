<?php
/**
 * Delete Custom Field Page
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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\CustomFields;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require admin role
$user = Auth::user();
if (!Authorization::can('crm.custom_fields.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$customFieldsModule = new CustomFields();
$fieldId = (int) ($_POST['field_id'] ?? $_GET['id'] ?? 0);

if (!$fieldId) {
    header('Location: custom_fields.php?error=not_found');
    exit;
}

$field = $customFieldsModule->getById($fieldId);

if (!$field) {
    header('Location: custom_fields.php?error=not_found');
    exit;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: custom_fields.php?error=invalid_token');
        exit;
    }
    
    try {
        if (!$customFieldsModule->delete($fieldId)) {
            header('Location: custom_fields.php?error=not_found');
            exit;
        }
        header('Location: custom_fields.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: custom_fields.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Custom Field - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Delete Custom Field</h1>
                    <p>Are you sure you want to delete this field?</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="custom_fields.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Custom Fields
                    </a>
                </div>
            </div>

            <div class="admin-danger-panel">
                <div class="admin-danger-card">
                    <p><strong>Warning: This action cannot be undone!</strong></p>
                    <p>Deleting this field will also delete all associated data for all contacts.</p>
                </div>

                <?php
                $usageCount = $customFieldsModule->getUsageCount($fieldId);
                ?>

                <div class="admin-meta-grid">
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Field Name</div>
                        <div class="admin-meta-value"><?php echo htmlspecialchars($field['field_name']); ?></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Label</div>
                        <div class="admin-meta-value"><?php echo htmlspecialchars($customFieldsModule->getLabel($field)); ?></div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Type</div>
                        <div class="admin-meta-value">
                            <span class="admin-status-badge admin-status-badge--neutral">
                                <?php echo htmlspecialchars($field['field_type']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="admin-meta-item">
                        <div class="admin-meta-label">Contacts Using This Field</div>
                        <div class="admin-meta-value"><?php echo $usageCount; ?></div>
                    </div>
                </div>

                <?php if ($usageCount > 0): ?>
                    <div class="premium-banner premium-banner-error">
                        All data for these contacts will be permanently deleted!
                    </div>
                <?php endif; ?>

                <form method="POST" action="custom_field_delete.php?id=<?php echo $fieldId; ?>" class="form-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="field_id" value="<?php echo $fieldId; ?>">
                    <a href="custom_fields.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-danger">
                        <i class="fas fa-trash"></i>
                        Delete Field
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
