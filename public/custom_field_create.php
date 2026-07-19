<?php
/**
 * Create Custom Field Page
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
use CRM\Services\WorkspaceContext;

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
$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $fieldName = $_POST['field_name'] ?? '';
            $label = $_POST['label'] ?? $fieldName;
            $fieldType = $_POST['field_type'] ?? 'text';
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $displayOrder = (int) ($_POST['display_order'] ?? 0);
            $fieldOptions = null;
            
            // Handle field options for select fields
            if ($fieldType === 'select' && !empty($_POST['field_options'])) {
                $options = array_filter(array_map('trim', explode("\n", $_POST['field_options'])));
                $fieldOptions = $options;
            }
            
            if (empty($fieldName)) {
                $error = 'Field name is required.';
            } else {
                // Check if field name already exists
                $existing = Database::queryOne(
                    "SELECT id FROM custom_fields WHERE workspace_id = ? AND field_name = ?",
                    [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $fieldName]
                );
                
                if ($existing) {
                    $error = 'Field name already exists.';
                } else {
                    $fieldId = $customFieldsModule->create([
                        'field_name' => $fieldName,
                        'field_type' => $fieldType,
                        'field_options' => $fieldOptions,
                        'module' => 'contacts',
                        'is_required' => $isRequired,
                        'display_order' => $displayOrder
                    ]);
                    
                    // Store label in field_options metadata if different from field_name
                    if ($label && $label !== $fieldName) {
                        $metadata = $fieldOptions ?: [];
                        if (is_array($metadata)) {
                            $metadata['_label'] = $label;
                        } else {
                            $metadata = ['_label' => $label];
                        }
                        Database::execute(
                            "UPDATE custom_fields SET field_options = ? WHERE workspace_id = ? AND id = ?",
                            [json_encode($metadata), (int) (WorkspaceContext::currentWorkspaceId() ?? 0), $fieldId]
                        );
                    }
                    
                    header('Location: custom_fields.php');
                    exit;
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Add Custom Field - ' . brandProductName();
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
                    <h1>Add Custom Field</h1>
                    <p>Create a new custom field for contacts</p>
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

            <form method="POST" action="" id="customFieldForm" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Field Identity</h2>
                        <p>Define how this field is stored and displayed.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="field_name">Field Name *</label>
                            <input
                                type="text"
                                id="field_name"
                                name="field_name"
                                required
                                pattern="[a-z0-9_]+"
                                value="<?php echo htmlspecialchars($_POST['field_name'] ?? ''); ?>"
                                placeholder="e.g., favorite_color"
                            >
                            <small class="admin-help-text">Lowercase letters, numbers, and underscores only</small>
                        </div>

                        <div class="form-group">
                            <label for="label">Label</label>
                            <input
                                type="text"
                                id="label"
                                name="label"
                                value="<?php echo htmlspecialchars($_POST['label'] ?? ''); ?>"
                                placeholder="Display label (optional)"
                            >
                            <small class="admin-help-text">Human-readable label (defaults to field name if empty)</small>
                        </div>
                    </div>
                </section>

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Field Behavior</h2>
                        <p>Choose the input type, ordering, and validation requirements.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="field_type">Field Type *</label>
                            <select id="field_type" name="field_type" required onchange="toggleOptionsField()">
                                <option value="text" <?php echo (isset($_POST['field_type']) && $_POST['field_type'] === 'text') || !isset($_POST['field_type']) ? 'selected' : ''; ?>>Text</option>
                                <option value="number" <?php echo isset($_POST['field_type']) && $_POST['field_type'] === 'number' ? 'selected' : ''; ?>>Number</option>
                                <option value="date" <?php echo isset($_POST['field_type']) && $_POST['field_type'] === 'date' ? 'selected' : ''; ?>>Date</option>
                                <option value="select" <?php echo isset($_POST['field_type']) && $_POST['field_type'] === 'select' ? 'selected' : ''; ?>>Select (Dropdown)</option>
                                <option value="textarea" <?php echo isset($_POST['field_type']) && $_POST['field_type'] === 'textarea' ? 'selected' : ''; ?>>Textarea</option>
                                <option value="checkbox" <?php echo isset($_POST['field_type']) && $_POST['field_type'] === 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="display_order">Display Order</label>
                            <input
                                type="number"
                                id="display_order"
                                name="display_order"
                                value="<?php echo htmlspecialchars($_POST['display_order'] ?? '0'); ?>"
                            >
                            <small class="admin-help-text">Lower numbers appear first (0 = first)</small>
                        </div>
                    </div>

                    <div id="options_field" class="form-group utility-options-field">
                        <label for="field_options">Options (one per line) *</label>
                        <textarea
                            id="field_options"
                            name="field_options"
                            rows="5"
                            placeholder="Option 1&#10;Option 2&#10;Option 3"
                        ><?php echo htmlspecialchars($_POST['field_options'] ?? ''); ?></textarea>
                        <small class="admin-help-text">Required for Select fields. Enter one option per line.</small>
                    </div>

                    <label class="admin-checkbox-card">
                        <input
                            type="checkbox"
                            name="is_required"
                            <?php echo isset($_POST['is_required']) ? 'checked' : ''; ?>
                        >
                        <span>
                            <strong>Required Field</strong>
                            <small>Users must fill this field when creating/editing contacts</small>
                        </span>
                    </label>
                </section>

                <div class="form-actions">
                    <a href="custom_fields.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-save"></i>
                        Create Field
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleOptionsField() {
    const fieldType = document.getElementById('field_type').value;
    const optionsField = document.getElementById('options_field');
    const optionsInput = document.getElementById('field_options');
    
    if (fieldType === 'select') {
        optionsField.style.display = 'block';
        optionsInput.required = true;
    } else {
        optionsField.style.display = 'none';
        optionsInput.required = false;
    }
}

// Initialize on page load
toggleOptionsField();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
