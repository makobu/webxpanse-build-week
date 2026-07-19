<?php
/**
 * Edit Report Page
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
use CRM\Security;
use CRM\Modules\Reports;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$reportsModule = new Reports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$reportId = (int) ($_GET['id'] ?? 0);

if ($reportId <= 0) {
    header('Location: reports.php');
    exit;
}

$report = $reportsModule->getEditableById($reportId, $userId);
if (!$report) {
    header('Location: reports.php?error=access_denied');
    exit;
}

$definitions = $reportsModule->getReportTypeDefinitions();
$error = null;

$formType = (string) ($_POST['report_type'] ?? $report['report_type']);
$submittedQueryConfig = [
    'fields' => !empty($_POST['fields']) ? array_filter(array_map('trim', explode(',', (string) $_POST['fields']))) : ((array) (($report['query_config'] ?? [])['fields'] ?? [])),
    'order_by' => (string) ($_POST['order_by'] ?? (($report['query_config'] ?? [])['order_by'] ?? '')),
    'order_dir' => (string) ($_POST['order_dir'] ?? (($report['query_config'] ?? [])['order_dir'] ?? 'DESC')),
    'limit' => $_POST['limit'] ?? (($report['query_config'] ?? [])['limit'] ?? null),
];
$submittedFilters = [
    'date_from' => $_POST['date_from'] ?? (($report['filters'] ?? [])['date_from'] ?? null),
    'date_to' => $_POST['date_to'] ?? (($report['filters'] ?? [])['date_to'] ?? null),
    'stage' => $_POST['stage'] ?? (($report['filters'] ?? [])['stage'] ?? null),
    'lead_source' => $_POST['lead_source'] ?? (($report['filters'] ?? [])['lead_source'] ?? null),
    'status' => $_POST['status'] ?? (($report['filters'] ?? [])['status'] ?? null),
    'priority' => $_POST['priority'] ?? (($report['filters'] ?? [])['priority'] ?? null),
    'event_type' => $_POST['event_type'] ?? (($report['filters'] ?? [])['event_type'] ?? null),
];
$preparedState = $reportsModule->prepareConfiguration($formType, $submittedQueryConfig, $submittedFilters);

$formName = (string) ($_POST['name'] ?? $report['name']);
$formDescription = (string) ($_POST['description'] ?? ($report['description'] ?? ''));
$formIsPublic = isset($_POST['is_public']) ? true : ((int) ($report['is_public'] ?? 0) === 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $reportsModule->updateForUser($reportId, $userId, [
                'name' => $formName,
                'description' => $formDescription,
                'report_type' => $formType,
                'query_config' => $preparedState['query_config'],
                'filters' => $preparedState['filters'],
                'is_public' => $formIsPublic ? 1 : 0,
            ]);

            header('Location: report_view.php?id=' . $reportId . '&success=updated');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Report - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Edit Report</h1>
                <p>Update report configuration</p>
            </div>
            <div class="page-header-actions">
                <a href="report_view.php?id=<?php echo $reportId; ?>" class="btn-premium-secondary">Back to Report</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="report-banner report-banner-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);" id="report-builder-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div class="form-group">
                    <label for="name">Report Name *</label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($formName); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($formDescription); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="report_type">Report Type *</label>
                    <select id="report_type" name="report_type" required>
                        <?php foreach (['contacts', 'activities', 'sales', 'emails', 'tasks', 'events'] as $reportTypeOption): ?>
                            <option value="<?php echo $reportTypeOption; ?>" <?php echo $formType === $reportTypeOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($reportTypeOption)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="report-config-section" class="content-card" style="margin-bottom: 0;">
                    <h2 style="font-size: 1.125rem; margin: 0 0 1rem 0;">Report Configuration</h2>
                    <div id="fields-section" style="display: none;">
                        <div class="form-group">
                            <label for="fields">Fields to Include</label>
                            <div id="fields_checkboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: var(--spacing-sm); padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 8px; background: var(--light-grey);"></div>
                            <input type="hidden" id="fields" name="fields" value="<?php echo htmlspecialchars(implode(',', (array) ($preparedState['query_config']['fields'] ?? []))); ?>">
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--spacing-md);">
                            <div class="form-group">
                                <label for="order_by">Order By</label>
                                <select id="order_by" name="order_by"></select>
                            </div>
                            <div class="form-group">
                                <label for="order_dir">Order Direction</label>
                                <select id="order_dir" name="order_dir">
                                    <option value="ASC" <?php echo (($preparedState['query_config']['order_dir'] ?? 'DESC') === 'ASC') ? 'selected' : ''; ?>>Ascending</option>
                                    <option value="DESC" <?php echo (($preparedState['query_config']['order_dir'] ?? 'DESC') === 'DESC') ? 'selected' : ''; ?>>Descending</option>
                                </select>
                            </div>
                            <div class="form-group" id="limit-group">
                                <label for="limit">Limit Results</label>
                                <input type="number" id="limit" name="limit" min="1" value="<?php echo htmlspecialchars((string) ($preparedState['query_config']['limit'] ?? '')); ?>" placeholder="No limit">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card" style="margin-bottom: 0;">
                    <h2 style="font-size: 1.125rem; margin: 0 0 1rem 0;">Default Filters</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
                        <div class="form-group report-filter" data-filter="date_from">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars((string) ($preparedState['filters']['date_from'] ?? '')); ?>">
                        </div>
                        <div class="form-group report-filter" data-filter="date_to">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars((string) ($preparedState['filters']['date_to'] ?? '')); ?>">
                        </div>
                        <div class="form-group report-filter" data-filter="stage">
                            <label for="stage">Stage</label>
                            <select id="stage" name="stage">
                                <option value="">All Stages</option>
                                <?php foreach (['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] as $stage): ?>
                                    <option value="<?php echo $stage; ?>" <?php echo (($preparedState['filters']['stage'] ?? '') === $stage) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($stage)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group report-filter" data-filter="lead_source">
                            <label for="lead_source">Lead Source</label>
                            <select id="lead_source" name="lead_source">
                                <option value="">All Sources</option>
                                <?php foreach (['form', 'whatsapp', 'ad', 'referral', 'social', 'import', 'web_assessment', 'other'] as $source): ?>
                                    <option value="<?php echo $source; ?>" <?php echo (($preparedState['filters']['lead_source'] ?? '') === $source) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($source)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group report-filter" data-filter="status">
                            <label for="status">Status</label>
                            <input type="text" id="status" name="status" value="<?php echo htmlspecialchars((string) ($preparedState['filters']['status'] ?? '')); ?>" placeholder="e.g. open">
                        </div>
                        <div class="form-group report-filter" data-filter="priority">
                            <label for="priority">Priority</label>
                            <input type="text" id="priority" name="priority" value="<?php echo htmlspecialchars((string) ($preparedState['filters']['priority'] ?? '')); ?>" placeholder="e.g. high">
                        </div>
                        <div class="form-group report-filter" data-filter="event_type">
                            <label for="event_type">Event Type</label>
                            <input type="text" id="event_type" name="event_type" value="<?php echo htmlspecialchars((string) ($preparedState['filters']['event_type'] ?? '')); ?>" placeholder="e.g. meeting">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display: inline-flex; align-items: center; gap: var(--spacing-sm); cursor: pointer;">
                        <input type="checkbox" name="is_public" value="1" <?php echo $formIsPublic ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                        <span>Make this report public (visible to all users)</span>
                    </label>
                </div>

                <div class="form-actions">
                    <a href="report_view.php?id=<?php echo $reportId; ?>" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Update Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var definitions = <?php echo json_encode($definitions, JSON_THROW_ON_ERROR); ?>;
    var reportTypeSelect = document.getElementById('report_type');
    var fieldsSection = document.getElementById('fields-section');
    var fieldsContainer = document.getElementById('fields_checkboxes');
    var fieldsInput = document.getElementById('fields');
    var orderBySelect = document.getElementById('order_by');
    var limitGroup = document.getElementById('limit-group');
    var selectedOrderBy = <?php echo json_encode((string) ($preparedState['query_config']['order_by'] ?? '')); ?>;

    function updateFieldsInput() {
        var checked = Array.prototype.slice.call(fieldsContainer.querySelectorAll('input[type="checkbox"]:checked'));
        fieldsInput.value = checked.map(function(checkbox) { return checkbox.value; }).join(',');
    }

    function renderFieldCheckboxes(definition) {
        fieldsContainer.innerHTML = '';
        var fields = definition.fields || {};
        var selected = (fieldsInput.value || '').split(',').filter(Boolean);
        var defaults = selected.length > 0 ? selected : (definition.default_fields || []);

        Object.keys(fields).forEach(function(field) {
            var label = document.createElement('label');
            label.style.cssText = 'display:flex; align-items:center; gap:0.5rem; cursor:pointer;';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = field;
            checkbox.checked = defaults.indexOf(field) !== -1;
            checkbox.addEventListener('change', updateFieldsInput);

            var text = document.createElement('span');
            text.textContent = fields[field];

            label.appendChild(checkbox);
            label.appendChild(text);
            fieldsContainer.appendChild(label);
        });

        updateFieldsInput();
    }

    function renderOrderOptions(definition) {
        var orderFields = definition.order_fields || {};
        orderBySelect.innerHTML = '';

        Object.keys(orderFields).forEach(function(field) {
            var option = document.createElement('option');
            option.value = field;
            option.textContent = orderFields[field];
            if (selectedOrderBy === field || (!selectedOrderBy && definition.default_order_by === field)) {
                option.selected = true;
            }
            orderBySelect.appendChild(option);
        });
    }

    function toggleFilters(definition) {
        var enabledFilters = definition.filters || [];
        Array.prototype.slice.call(document.querySelectorAll('.report-filter')).forEach(function(node) {
            node.style.display = enabledFilters.indexOf(node.getAttribute('data-filter')) !== -1 ? 'block' : 'none';
        });
    }

    function updateReportBuilder() {
        var definition = definitions[reportTypeSelect.value] || definitions.custom;
        var hasFields = Object.keys(definition.fields || {}).length > 0;
        fieldsSection.style.display = hasFields ? 'block' : 'none';

        if (hasFields) {
            renderFieldCheckboxes(definition);
            renderOrderOptions(definition);
            limitGroup.style.display = definition.supports_limit ? 'block' : 'none';
        } else {
            fieldsInput.value = '';
            orderBySelect.innerHTML = '';
            limitGroup.style.display = 'none';
        }

        toggleFilters(definition);
    }

    reportTypeSelect.addEventListener('change', function() {
        selectedOrderBy = '';
        updateReportBuilder();
    });

    updateReportBuilder();
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
