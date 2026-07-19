<?php
/**
 * Report View/Execute Page
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

$report = $reportsModule->getViewableById($reportId, $userId);
if (!$report) {
    header('Location: reports.php?error=access_denied');
    exit;
}

$definitions = $reportsModule->getReportTypeDefinitions();
$definition = $definitions[$report['report_type']] ?? $definitions['custom'];
$defaultState = $reportsModule->prepareConfiguration(
    (string) $report['report_type'],
    (array) ($report['query_config'] ?? []),
    (array) ($report['filters'] ?? [])
);

$parameters = [];
foreach ((array) ($definition['filters'] ?? []) as $filterKey) {
    if (isset($_GET[$filterKey]) && $_GET[$filterKey] !== '') {
        $parameters[$filterKey] = (string) $_GET[$filterKey];
    } elseif (isset($defaultState['filters'][$filterKey])) {
        $parameters[$filterKey] = (string) $defaultState['filters'][$filterKey];
    }
}

$reportData = [];
$executionTime = 0.0;
$error = trim((string) ($_GET['error'] ?? ''));
$success = trim((string) ($_GET['success'] ?? ''));

if ($error === '') {
    try {
        $startTime = microtime(true);
        $reportData = $reportsModule->executeForUser($reportId, $userId, $parameters);
        $executionTime = microtime(true) - $startTime;
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

$canEdit = $reportsModule->canUserEdit($report, $userId);
$exportCapabilities = $reportsModule->getExportCapabilities();
$baseQuery = ['id' => $reportId];
$resetQuery = http_build_query($baseQuery);
$activeQuery = http_build_query(array_merge($baseQuery, $parameters));

$pageTitle = 'Report: ' . htmlspecialchars((string) $report['name']) . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $report['name']); ?></h1>
                <p><?php echo htmlspecialchars((string) ($report['description'] ?? 'View report results')); ?></p>
            </div>
            <div class="page-header-actions">
                <?php if ($canEdit): ?>
                    <a href="report_edit.php?id=<?php echo $reportId; ?>" class="btn-premium-secondary">Edit Report</a>
                <?php endif; ?>
                <a href="reports.php" class="btn-premium-secondary">Back to Reports</a>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <div class="report-banner report-banner-success">
                <?php
                $successMessages = [
                    'created' => 'Report created successfully.',
                    'updated' => 'Report updated successfully.',
                ];
                echo htmlspecialchars($successMessages[$success] ?? $success);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="report-banner report-banner-error">
                <?php
                $errorMessages = [
                    'invalid_format' => 'That export format is not supported.',
                ];
                echo htmlspecialchars($errorMessages[$error] ?? $error);
                ?>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <form method="GET" action="" class="filters-form">
                <input type="hidden" name="id" value="<?php echo $reportId; ?>">

                <?php if (in_array('date_from', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars((string) ($parameters['date_from'] ?? '')); ?>">
                    </div>
                <?php endif; ?>

                <?php if (in_array('date_to', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars((string) ($parameters['date_to'] ?? '')); ?>">
                    </div>
                <?php endif; ?>

                <?php if (in_array('stage', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="stage">Stage</label>
                        <select id="stage" name="stage">
                            <option value="">All Stages</option>
                            <?php foreach (['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] as $stage): ?>
                                <option value="<?php echo $stage; ?>" <?php echo (($parameters['stage'] ?? '') === $stage) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($stage)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array('lead_source', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="lead_source">Lead Source</label>
                        <select id="lead_source" name="lead_source">
                            <option value="">All Sources</option>
                                <?php foreach (['form', 'whatsapp', 'ad', 'referral', 'social', 'import', 'web_assessment', 'other'] as $source): ?>
                                <option value="<?php echo $source; ?>" <?php echo (($parameters['lead_source'] ?? '') === $source) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($source)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array('status', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <input type="text" id="status" name="status" value="<?php echo htmlspecialchars((string) ($parameters['status'] ?? '')); ?>" placeholder="e.g. open">
                    </div>
                <?php endif; ?>

                <?php if (in_array('priority', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="priority">Priority</label>
                        <input type="text" id="priority" name="priority" value="<?php echo htmlspecialchars((string) ($parameters['priority'] ?? '')); ?>" placeholder="e.g. high">
                    </div>
                <?php endif; ?>

                <?php if (in_array('event_type', (array) ($definition['filters'] ?? []), true)): ?>
                    <div class="filter-group">
                        <label for="event_type">Event Type</label>
                        <input type="text" id="event_type" name="event_type" value="<?php echo htmlspecialchars((string) ($parameters['event_type'] ?? '')); ?>" placeholder="e.g. meeting">
                    </div>
                <?php endif; ?>

                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">Apply Filters</button>
                    <?php if ($parameters !== []): ?>
                        <a href="report_view.php?<?php echo htmlspecialchars($resetQuery); ?>" class="btn-premium-secondary">Reset Filters</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-card">
            <div style="padding: var(--spacing-md); border-bottom: 1px solid var(--border-color); background: var(--light-grey); display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div>
                    <span style="color: var(--charcoal-grey); font-size: 14px;">Results:</span>
                    <span style="color: var(--midnight-black); font-weight: 600; font-size: 18px;"><?php echo number_format(count($reportData)); ?></span>
                    <span style="color: var(--charcoal-grey); font-size: 12px; margin-left: var(--spacing-sm);">
                        (Executed in <?php echo number_format($executionTime * 1000, 2); ?>ms)
                    </span>
                </div>
                <div class="report-action-group">
                    <a href="report_export.php?format=csv&<?php echo htmlspecialchars($activeQuery); ?>" class="btn-premium-success btn-premium-sm">Export CSV</a>
                    <?php if (!empty($exportCapabilities['pdf'])): ?>
                        <a href="report_export.php?format=pdf&<?php echo htmlspecialchars($activeQuery); ?>" class="btn-premium-info btn-premium-sm">Export PDF</a>
                    <?php else: ?>
                        <button type="button" class="btn-premium-info btn-premium-sm" disabled title="PDF export is unavailable on this system.">Export PDF</button>
                    <?php endif; ?>
                    <?php if (!empty($exportCapabilities['excel'])): ?>
                        <a href="report_export.php?format=excel&<?php echo htmlspecialchars($activeQuery); ?>" class="btn-premium-success btn-premium-sm">Export Excel</a>
                    <?php else: ?>
                        <button type="button" class="btn-premium-success btn-premium-sm" disabled title="Excel export is unavailable on this system.">Export Excel</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($reportData)): ?>
                <div class="empty-state">
                    <p>No data found for this report with the current filters.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <?php foreach (array_keys((array) reset($reportData)) as $column): ?>
                                    <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', str_replace('a.', '', str_replace('c.', '', (string) $column))))); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                                <tr>
                                    <?php foreach ($row as $column => $value): ?>
                                        <td><?php echo htmlspecialchars($reportsModule->formatDisplayValue((string) $column, $value)); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
