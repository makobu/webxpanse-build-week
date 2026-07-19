<?php
/**
 * Export Companies Page
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
use CRM\Database;
use CRM\Modules\Companies;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
\CRM\Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$companiesModule = new Companies();
$workspaceScope = new WorkspaceScopeService();
$presentationGuard = new PresentationWorkspaceGuardService();
$users = $companiesModule->getAssignableUsers();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    if ($presentationGuard->isBlocked('exports')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $presentationGuard->message('exports');
        exit;
    }

    $filters = [
        'search' => $_GET['search'] ?? '',
        'assigned_to' => $_GET['assigned_to'] ?? '',
    ];
    $rows = $companiesModule->export($filters);
    $columns = $companiesModule->getExportColumns();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="companies_export_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, array_values($columns));
    foreach ($rows as $row) {
        $csvRow = [];
        foreach (array_keys($columns) as $column) {
            $csvRow[] = $row[$column] ?? '';
        }
        fputcsv($output, $csvRow);
    }
    fclose($output);
    exit;
}

$workspace = $workspaceScope->workspaceClause();
$totalCompaniesRow = Database::queryOne(
    "SELECT COUNT(*) AS count FROM companies WHERE {$workspace['sql']}",
    $workspace['params']
);
$totalCompanies = (int) ($totalCompaniesRow['count'] ?? 0);

$pageTitle = 'Export Companies - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Export Companies</h1>
    <p style="color: var(--charcoal-grey);">Export company records to CSV with optional filters</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 680px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Total Companies</div>
        <div style="font-size: 32px; font-weight: 600; color: var(--midnight-black);">
            <?php echo number_format($totalCompanies); ?>
        </div>
    </div>

    <form method="GET" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="export" value="1">

        <div>
            <label for="search" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Search (Optional)</label>
            <input
                type="text"
                id="search"
                name="search"
                value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                placeholder="Filter by name, industry, or website..."
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>

        <div>
            <label for="assigned_to" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Assigned To (Optional)</label>
            <select
                id="assigned_to"
                name="assigned_to"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="">All Users</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo (int) $user['id']; ?>" <?php echo (string) ($_GET['assigned_to'] ?? '') === (string) $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['email']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="background: var(--light-grey); border-radius: 4px; padding: var(--spacing-md);">
            <div style="color: var(--midnight-black); font-size: 14px; font-weight: 500; margin-bottom: var(--spacing-xs);">
                Export Columns:
            </div>
            <div style="color: var(--charcoal-grey); font-size: 12px; line-height: 1.6;">
                <?php echo htmlspecialchars(implode(', ', array_values($companiesModule->getExportColumns()))); ?>
            </div>
        </div>

        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
            <a href="companies.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button
                type="submit"
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Export to CSV
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
