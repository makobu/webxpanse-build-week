<?php
/**
 * Custom Fields List Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\CustomFields;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('crm.custom_fields.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$customFieldsModule = new CustomFields();
$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

$allFields = $customFieldsModule->getAll();
$customFields = $allFields;

if ($type) {
    $customFields = array_filter($customFields, fn($field) => $field['field_type'] === $type);
}

if ($search) {
    $customFields = array_filter($customFields, function ($field) use ($search, $customFieldsModule) {
        $label = $customFieldsModule->getLabel($field);
        return stripos($field['field_name'], $search) !== false
            || stripos($label, $search) !== false;
    });
}

$typeCounts = [];
foreach ($allFields as $field) {
    $typeCounts[$field['field_type']] = ($typeCounts[$field['field_type']] ?? 0) + 1;
}

$usageCounts = [];
foreach ($customFields as $field) {
    $usageCounts[$field['id']] = $customFieldsModule->getUsageCount((int) $field['id']);
}

$pageTitle = 'Custom Fields - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1>Custom Fields</h1>
                    <p>Manage custom fields for contacts</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="custom_field_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        Add Custom Field
                    </a>
                </div>
            </div>

        <?php if ($success !== ''): ?>
            <div class="premium-banner premium-banner-success">
                <?php
                $successMessages = [
                    'updated' => 'Custom field updated successfully.',
                    'deleted' => 'Custom field deleted successfully.',
                ];
                echo htmlspecialchars($successMessages[$success] ?? $success);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="premium-banner premium-banner-error">
                <?php
                $errorMessages = [
                    'invalid_token' => 'Your session token was invalid. Please try again.',
                    'not_found' => 'That custom field could not be found.',
                ];
                echo htmlspecialchars($errorMessages[$error] ?? $error);
                ?>
            </div>
        <?php endif; ?>

        <div class="admin-filter-card">
            <form method="GET" action="custom_fields.php" class="admin-filter-form">
                <div class="admin-filter-group admin-filter-group--wide form-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search by name or label..."
                    >
                </div>
                <div class="admin-filter-group form-group">
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <option value="text" <?php echo $type === 'text' ? 'selected' : ''; ?>>Text</option>
                        <option value="number" <?php echo $type === 'number' ? 'selected' : ''; ?>>Number</option>
                        <option value="date" <?php echo $type === 'date' ? 'selected' : ''; ?>>Date</option>
                        <option value="select" <?php echo $type === 'select' ? 'selected' : ''; ?>>Select</option>
                        <option value="textarea" <?php echo $type === 'textarea' ? 'selected' : ''; ?>>Textarea</option>
                        <option value="checkbox" <?php echo $type === 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                    </select>
                </div>
                <div class="admin-filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search || $type): ?>
                        <a href="custom_fields.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="admin-chip-row">
            <?php
            $types = ['text', 'number', 'date', 'select', 'textarea', 'checkbox'];
            $typeLabels = ['Text', 'Number', 'Date', 'Select', 'Textarea', 'Checkbox'];
            foreach ($types as $index => $typeName):
                $count = $typeCounts[$typeName] ?? 0;
                $isActive = $type === $typeName;
            ?>
                <a href="?type=<?php echo $typeName; ?>" class="admin-filter-chip <?php echo $isActive ? 'is-active' : ''; ?>">
                    <?php echo $typeLabels[$index]; ?> (<?php echo $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <?php if (empty($customFields)): ?>
                <div class="empty-state">
                    <p>No custom fields found.</p>
                    <a href="custom_field_create.php">Create your first custom field -></a>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Field Name</th>
                                <th>Label</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customFields as $field): ?>
                                <tr>
                                    <td>
                                        <strong class="admin-code-inline">
                                            <?php echo htmlspecialchars($field['field_name']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($customFieldsModule->getLabel($field)); ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-default">
                                            <?php echo htmlspecialchars(ucfirst($field['field_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($field['is_required']): ?>
                                            <span class="admin-status-badge admin-status-badge--danger">Yes</span>
                                        <?php else: ?>
                                            <span class="admin-status-badge admin-status-badge--neutral">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $usageCounts[$field['id']] ?? 0; ?> contacts
                                    </td>
                                    <td>
                                        <div class="premium-inline-actions">
                                            <a href="custom_field_edit.php?id=<?php echo (int) $field['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                            <a href="custom_field_delete.php?id=<?php echo (int) $field['id']; ?>" class="btn-premium-danger btn-premium-sm">Delete</a>
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
