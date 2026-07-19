<?php
/**
 * Global Search Results Page
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
use CRM\Modules\Search;
use CRM\Modules\SavedSearches;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$searchModule = new Search();
$savedSearchesModule = new SavedSearches();
$query = trim($_GET['q'] ?? '');
$useSemantic = isset($_GET['mode']) && $_GET['mode'] === 'semantic';
$error = null;
$success = null;

// Handle save search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_search'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $name = trim($_POST['search_name'] ?? '');
            if (empty($name)) {
                $error = 'Search name is required.';
            } else {
                $savedSearchesModule->create([
                    'name' => $name,
                    'entity_type' => 'all', // Global search
                    'search_query' => $query,
                    'filters' => []
                ]);
                $success = 'Search saved successfully!';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Invalid security token. Please try again.';
    }
}

$results = [];
$totalResults = 0;

if (!empty($query)) {
    $results = $searchModule->search($query, 20);
    
    // Count total results
    foreach ($results as $entityResults) {
        $totalResults += count($entityResults);
    }
}

$pageTitle = 'Search - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Search</h1>
                <p>Search across all CRM data</p>
            </div>
        </div>

        <!-- Search Form -->
        <div class="filters-card">
    <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: var(--spacing-sm); align-items: center;">
        <label for="global-search-query" class="sr-only">Search workspace</label>
        <input
            id="global-search-query"
            type="text" 
            name="q" 
            value="<?php echo htmlspecialchars($query); ?>"
            placeholder="Search contacts, deals, tasks... (e.g. high-value leads in tech)"
            autofocus
            style="flex: 1; min-width: 200px; padding: var(--spacing-md); border: 2px solid var(--border-color); border-radius: 4px; font-size: 16px;"
        >
        <label style="display: flex; align-items: center; gap: 6px; font-size: 14px; color: var(--charcoal-grey); cursor: pointer;">
            <input type="checkbox" name="mode" value="semantic" <?php echo $useSemantic ? 'checked' : ''; ?>>
            Semantic search
        </label>
        <button 
            type="submit" 
            style="background: var(--accent-blue); color: white; padding: var(--spacing-md) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 16px;"
        >
            Search
        </button>
    </form>
</div>

        <?php if ($error): ?>
            <div class="content-card" style="background: #fee2e2; border-color: #ef4444; color: #991b1b; margin-bottom: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="content-card" style="background: #d1fae5; border-color: #10b981; color: #065f46; margin-bottom: 1rem;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($query)): ?>
            <!-- Results Summary -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <p style="color: #64748b; margin: 0;">
                    Found <strong><?php echo number_format($totalResults); ?></strong> result<?php echo $totalResults !== 1 ? 's' : ''; ?> for "<strong><?php echo htmlspecialchars($query); ?></strong>"
                </p>
                <button onclick="document.getElementById('save-search-modal').style.display='flex'" class="btn-premium-primary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                    <i class="fas fa-save"></i>
                    Save Search
                </button>
            </div>
            
            <?php if (empty($results)): ?>
                <!-- No Results -->
                <div class="empty-state">
                    <div style="font-size: 3rem; margin-bottom: 1rem;" aria-hidden="true"><i class="fas fa-search"></i></div>
                    <p style="font-size: 1.125rem; margin-bottom: 0.5rem;">No results found</p>
                    <p>Try different keywords or check your spelling.</p>
                </div>
            <?php else: ?>
                <!-- Results by Entity Type -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <?php
                    // search() returns plural keys; module helpers expect singular keys
                    $entityOrder = ['contacts', 'deals', 'tasks', 'events', 'emails', 'activities', 'notes'];
                    $singularMap  = [
                        'contacts'   => 'contact',
                        'deals'      => 'deal',
                        'tasks'      => 'task',
                        'events'     => 'event',
                        'emails'     => 'email',
                        'activities' => 'activity',
                        'notes'      => 'note',
                    ];
                    foreach ($entityOrder as $entityType):
                        if (empty($results[$entityType])) continue;
                        $entityResults = $results[$entityType];
                        $singular = $singularMap[$entityType] ?? $entityType;
                    ?>
                        <div class="content-card">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                                <span style="font-size: 1.5rem;">
                                    <?php echo $searchModule->getEntityIcon($singular); ?>
                                </span>
                                <h2 style="color: #0f172a; font-size: 1.25rem; margin: 0;">
                                    <?php echo $searchModule->getEntityLabel($singular, true); ?>
                                    <span style="color: #64748b; font-size: 0.875rem; font-weight: normal;">
                                        (<?php echo count($entityResults); ?>)
                                    </span>
                                </h2>
                            </div>

                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <?php foreach ($entityResults as $result):
                                    $url = $searchModule->getEntityUrl($singular, $result['id']);
                                ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>" class="content-card" style="display: block; text-decoration: none; color: inherit; transition: all 0.2s; cursor: pointer;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.1)'">
                                        <div style="display: flex; justify-content: space-between; align-items: start;">
                                            <div style="flex: 1;">
                                                <?php if ($singular === 'contact'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        <?php echo htmlspecialchars($result['email'] ?? ''); ?>
                                                        <?php if ($result['company']): ?>
                                                            &middot; <?php echo htmlspecialchars($result['company']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($result['stage']): ?>
                                                        <div style="margin-top: 0.5rem;">
                                                            <span class="badge badge-default">
                                                                <?php echo htmlspecialchars(ucfirst($result['stage'])); ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                
                                                <?php elseif ($singular === 'deal'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['title'] ?? 'Untitled Deal'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        <?php if ($result['value'] > 0): ?>
                                                            <span style="color: #667eea; font-weight: 600;">
                                                                <?php echo htmlspecialchars($result['currency'] ?? 'USD'); ?> <?php echo number_format($result['value'], 2); ?>
                                                            </span>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <span style="text-transform: capitalize;">
                                                            <?php echo str_replace('_', ' ', htmlspecialchars($result['stage'] ?? '')); ?>
                                                        </span>
                                                    </div>
                                                
                                                <?php elseif ($singular === 'task'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['title'] ?? 'Untitled Task'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <span style="text-transform: capitalize;">
                                                            <?php echo htmlspecialchars($result['status'] ?? ''); ?>
                                                        </span>
                                                        <?php if ($result['priority']): ?>
                                                            &middot; <?php echo htmlspecialchars($result['priority']); ?> priority
                                                        <?php endif; ?>
                                                        <?php if ($result['due_date']): ?>
                                                            &middot; Due: <?php echo date('M d, Y', strtotime($result['due_date'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                
                                                <?php elseif ($singular === 'event'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['title'] ?? 'Untitled Event'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        <?php if ($result['start_time']): ?>
                                                            <i class="fas fa-calendar-alt" aria-hidden="true"></i> <?php echo date('M d, Y g:i A', strtotime($result['start_time'])); ?>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <span style="text-transform: capitalize;">
                                                            <?php echo htmlspecialchars($result['event_type'] ?? ''); ?>
                                                        </span>
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            &middot; <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                
                                                <?php elseif ($singular === 'email'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['subject'] ?? 'No Subject'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        To: <?php echo htmlspecialchars($result['to_email'] ?? ''); ?>
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            (<?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>)
                                                        <?php endif; ?>
                                                        <?php if ($result['created_at']): ?>
                                                            &middot; <?php echo date('M d, Y', strtotime($result['created_at'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                
                                                <?php elseif ($singular === 'activity'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['description'] ?? 'Activity'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem;">
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <span style="text-transform: capitalize;">
                                                            <?php echo str_replace('_', ' ', htmlspecialchars($result['activity_type'] ?? '')); ?>
                                                        </span>
                                                        <?php if ($result['created_at']): ?>
                                                            &middot; <?php echo date('M d, Y', strtotime($result['created_at'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                
                                                <?php elseif ($singular === 'note'): ?>
                                                    <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($result['title'] ?? 'Note'); ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.875rem; line-height: 1.4;">
                                                        <?php echo htmlspecialchars(substr($result['content'] ?? '', 0, 150)); ?>
                                                        <?php if (strlen($result['content'] ?? '') > 150): ?>...<?php endif; ?>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.75rem; margin-top: 0.5rem;">
                                                        <?php if ($result['first_name'] || $result['last_name']): ?>
                                                            <?php echo htmlspecialchars(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')); ?>
                                                            &middot;
                                                        <?php endif; ?>
                                                        <?php echo ucfirst($result['entity_type'] ?? ''); ?>
                                                        <?php if ($result['created_at']): ?>
                                                            &middot; <?php echo date('M d, Y', strtotime($result['created_at'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div style="color: #64748b; font-size: 0.75rem; margin-left: 1rem;">
                                                &rarr;
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div style="font-size: 4rem; margin-bottom: 1rem;" aria-hidden="true"><i class="fas fa-search"></i></div>
                <p style="font-size: 1.125rem; margin-bottom: 0.5rem;">Search across your growth workspace</p>
                <p>Enter a search term above to find contacts, deals, tasks, events, emails, and more.</p>
            </div>
        <?php endif; ?>

        <!-- Save Search Modal -->
        <div id="save-search-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div class="content-card" style="max-width: 500px; width: 90%;">
                <h2 style="color: #0f172a; margin: 0 0 1rem 0;">Save Search</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <div style="margin-bottom: 1rem;">
                        <label for="saved-search-name" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #0f172a;">Search Name</label>
                        <input type="text" id="saved-search-name" name="search_name" required style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); font-size: 0.875rem;" placeholder="e.g., High-value leads">
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                        <div class="content-card" style="background: #f8fafc; padding: 0.5rem; border-radius: var(--border-radius-sm); font-size: 0.8125rem; color: #64748b;">
                            <strong>Query:</strong> <?php echo htmlspecialchars($query); ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                        <button type="button" onclick="document.getElementById('save-search-modal').style.display='none'" class="btn-premium-secondary">
                            Cancel
                        </button>
                        <button type="submit" name="save_search" class="btn-premium-primary">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
