<?php
/**
 * Edit Custom Field Page
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

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $label = trim((string) ($_POST['label'] ?? $field['field_name']));
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $displayOrder = (int) ($_POST['display_order'] ?? 0);
            
            // Get existing field options
            $existingOptions = [];
            if ($field['field_options']) {
                $decoded = json_decode($field['field_options'], true);
                $existingOptions = is_array($decoded) ? $decoded : [];
            }
            
            $fieldOptions = $existingOptions;
            
            // Handle field options for select fields
            if ($field['field_type'] === 'select' && array_key_exists('field_options', $_POST)) {
                $options = array_values(array_filter(array_map(
                    static fn(string $value): string => trim($value),
                    preg_split('/\r\n|\r|\n/', (string) ($_POST['field_options'] ?? '')) ?: []
                ), static fn(string $value): bool => $value !== ''));
                $fieldOptions = $options;
            }
            
            // Store label in metadata
            if ($label && $label !== $field['field_name']) {
                if (is_array($fieldOptions)) {
                    $fieldOptions['_label'] = $label;
                } else {
                    $fieldOptions = ['_label' => $label];
                }
            } elseif (is_array($fieldOptions) && isset($fieldOptions['_label'])) {
                unset($fieldOptions['_label']);
            }
            
            $customFieldsModule->update($fieldId, [
                'field_options' => $fieldOptions,
                'is_required' => $isRequired,
                'display_order' => $displayOrder,
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ]);
            
            header('Location: custom_fields.php?success=updated');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$fieldOptions = $customFieldsModule->getSelectableOptions($field);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $field['field_type'] === 'select' && array_key_exists('field_options', $_POST)) {
    $fieldOptions = array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        preg_split('/\r\n|\r|\n/', (string) ($_POST['field_options'] ?? '')) ?: []
    ), static fn(string $value): bool => $value !== ''));
}
$currentLabel = $customFieldsModule->getLabel($field);

$pageTitle = 'Edit Custom Field - ' . brandProductName();
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
                    <h1>Edit Custom Field</h1>
                    <p>Update custom field settings</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="custom_fields.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Custom Fields
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="custom_field_edit.php?id=<?php echo $fieldId; ?>" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($_POST['expected_lock_version'] ?? $field['lock_version'] ?? 0); ?>">
                <input type="hidden" name="field_id" value="<?php echo $fieldId; ?>">

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Locked Field Identity</h2>
                        <p>The storage name and type cannot be changed after creation.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label>Field Name</label>
                            <input type="text" value="<?php echo htmlspecialchars($field['field_name']); ?>" disabled class="utility-readonly-input">
                            <small class="admin-help-text">Field name cannot be changed after creation</small>
                        </div>

                        <div class="form-group">
                            <label>Field Type</label>
                            <input type="text" value="<?php echo htmlspecialchars(ucfirst($field['field_type'])); ?>" disabled class="utility-readonly-input">
                            <small class="admin-help-text">Field type cannot be changed after creation</small>
                        </div>
                    </div>
                </section>

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Display Settings</h2>
                        <p>Control the label, order, and required status shown on contact forms.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="label">Label</label>
                            <input
                                type="text"
                                id="label"
                                name="label"
                                value="<?php echo htmlspecialchars($_POST['label'] ?? $currentLabel); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="display_order">Display Order</label>
                            <input
                                type="number"
                                id="display_order"
                                name="display_order"
                                value="<?php echo htmlspecialchars($_POST['display_order'] ?? $field['display_order'] ?? '0'); ?>"
                            >
                        </div>
                    </div>

                    <?php if ($field['field_type'] === 'select'): ?>
                        <div class="form-group">
                            <label for="field_options">Options (one per line)</label>
                            <textarea id="field_options" name="field_options" rows="5"><?php echo htmlspecialchars(implode("\n", array_values($fieldOptions))); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <label class="admin-checkbox-card">
                        <input
                            type="checkbox"
                            name="is_required"
                            <?php echo (isset($_POST['is_required']) ? $_POST['is_required'] : $field['is_required']) ? 'checked' : ''; ?>
                        >
                        <span>
                            <strong>Required Field</strong>
                            <small>Users must fill this field when creating/editing contacts.</small>
                        </span>
                    </label>
                </section>

                <div class="form-actions">
                    <a href="custom_fields.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-save"></i>
                        Update Field
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
