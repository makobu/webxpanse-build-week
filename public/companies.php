<?php
/**
 * Companies List Page
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

use CRM\Database;
use CRM\Auth;
use CRM\Modules\Companies;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
\CRM\Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
\CRM\Session::closeWrite();

$companiesModule = new Companies();
$workspaceScope = new WorkspaceScopeService();
$search = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$companies = $companiesModule->list($limit, $offset, $search ?: null);
$workspaceClause = $workspaceScope->workspaceClause();
$totalCount = (int) Database::queryOne(
    $search
        ? "SELECT COUNT(*) as c FROM companies WHERE {$workspaceClause['sql']} AND (name LIKE ? OR industry LIKE ?)"
        : "SELECT COUNT(*) as c FROM companies WHERE {$workspaceClause['sql']}",
    $search
        ? array_merge($workspaceClause['params'], ['%' . $search . '%', '%' . $search . '%'])
        : $workspaceClause['params']
)['c'];
$totalPages = ceil($totalCount / $limit);

$pageTitle = 'Companies - ' . brandProductName();
$companiesGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_COMPANIES);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <?php if (($_GET['success'] ?? '') === 'deleted'): ?>
            <div class="premium-banner premium-banner-success">Company deleted successfully.</div>
        <?php elseif (!empty($_GET['error'])): ?>
            <div class="premium-banner premium-banner-error"><?php echo htmlspecialchars((string) $_GET['error']); ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1>Companies</h1>
                <p>Manage organizations, account ownership, and relationship history.</p>
            </div>
            <div class="page-header-actions">
                <a href="companies_import.php" class="btn-premium-secondary">
                    <i class="fas fa-file-upload"></i>
                    Import
                </a>
                <a href="companies_export.php" class="btn-premium-secondary">
                    <i class="fas fa-file-export"></i>
                    Export
                </a>
                <a href="company_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Add Company
                </a>
            </div>
        </div>

        <div class="filters-card">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label for="company_search">Search companies</label>
                    <input id="company_search" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or industry">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-search"></i>
                        Search
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="companies.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                    <?php if ($companiesGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_COMPANIES, 'Companies page guide', 'btn-premium-secondary'); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-card">
            <div class="premium-section-header">
                <div>
                    <h2>Company Directory</h2>
                    <p><?php echo number_format($totalCount); ?> <?php echo $totalCount === 1 ? 'company' : 'companies'; ?><?php echo $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : ''; ?></p>
                </div>
            </div>

            <?php if (empty($companies)): ?>
                <div class="empty-state">
                    <p>No companies found<?php echo $search !== '' ? ' for this search.' : '.'; ?></p>
                    <a href="company_create.php">Add your first company</a>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Industry</th>
                                <th>Contacts</th>
                                <th>Deals</th>
                                <th>Assigned To</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companies as $c): ?>
                                <tr>
                                    <td>
                                        <a class="premium-list-title" href="company_view.php?id=<?php echo (int) $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['industry'] ?? '-'); ?></td>
                                    <td><?php echo (int) $companiesModule->getContactCount($c['id']); ?></td>
                                    <td><?php echo (int) $companiesModule->getDealCount($c['id']); ?></td>
                                    <td><?php echo htmlspecialchars($c['assigned_to_email'] ?? '-'); ?></td>
                                    <td>
                                        <div class="premium-list-actions" style="justify-content:flex-end;">
                                            <a href="company_edit.php?id=<?php echo (int) $c['id']; ?>" class="btn-premium-secondary btn-premium-sm">
                                                <i class="fas fa-pen"></i>
                                                Edit
                                            </a>
                                            <a href="company_delete.php?id=<?php echo (int) $c['id']; ?>" class="btn-premium-danger btn-premium-sm">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_COMPANIES, 'How to use Companies', $companiesGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
