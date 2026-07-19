<?php
/**
 * Saved Searches Page
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
use CRM\Modules\SavedSearches;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$savedSearchesModule = new SavedSearches();

$error = null;
$success = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $savedSearchesModule->delete((int) $_POST['search_id']);
            $success = 'Saved search deleted successfully.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Invalid security token. Please try again.';
    }
}

// Get filter
$entityType = $_GET['entity_type'] ?? null;

// Get saved searches
$searches = $savedSearchesModule->getUserSearches($entityType);

/**
 * Build the "Run Search" URL for a saved search.
 *
 * Routes to the correct list page and appends stored filters as query
 * params so the page shows the actual filtered results, not all records.
 */
function buildSavedSearchUrl(string $entityType, string $query, array $filters): string
{
    $params = [];

    switch ($entityType) {
        case 'contacts':
            $base = 'contacts.php';
            if ($query !== '') {
                $params['search'] = $query;
            }
            // contacts.php reads ?tag=  (not tag_id)
            if (!empty($filters['tag_id'])) {
                $params['tag'] = $filters['tag_id'];
            }
            if (!empty($filters['stage'])) {
                $params['stage'] = $filters['stage'];
            }
            // When an assigned_to filter was saved, widen the owner scope so the
            // filtered contacts are actually visible in the list.
            if (!empty($filters['assigned_to'])) {
                $params['owner_scope'] = 'all';
            }
            break;

        case 'deals':
            $base = 'deals.php';
            if ($query !== '') {
                $params['search'] = $query;
            }
            if (!empty($filters['stage'])) {
                $params['stage'] = $filters['stage'];
            }
            if (!empty($filters['assigned_to'])) {
                $params['assigned_to'] = $filters['assigned_to'];
            }
            break;

        case 'tasks':
            $base = 'tasks.php';
            if ($query !== '') {
                $params['search'] = $query;
            }
            if (!empty($filters['status'])) {
                $params['status'] = $filters['status'];
            }
            if (!empty($filters['priority'])) {
                $params['priority'] = $filters['priority'];
            }
            if (!empty($filters['assigned_to'])) {
                $params['assigned_to'] = $filters['assigned_to'];
            }
            break;

        default: // 'all', 'events', and any future types
            $base = 'search.php';
            $params['q'] = $query;
            break;
    }

    // Strip empty/null values so the URL stays clean
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);

    return $base . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Saved Searches';
ob_start();
?>

<div style="max-width: 1400px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin: 0;">Saved Searches</h1>
        <a href="search.php" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; text-decoration: none; font-weight: 500;">
            + New Search
        </a>
    </div>

    <?php if ($error): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Module Filter -->
    <div style="background: white; padding: var(--spacing-md); border-radius: 8px; margin-bottom: var(--spacing-md); box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="display: flex; gap: var(--spacing-sm);">
            <a href="?" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo !$entityType ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                All
            </a>
            <a href="?entity_type=contacts" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $entityType === 'contacts' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Contacts
            </a>
            <a href="?entity_type=deals" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $entityType === 'deals' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Deals
            </a>
            <a href="?entity_type=tasks" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $entityType === 'tasks' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Tasks
            </a>
            <a href="?entity_type=events" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $entityType === 'events' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Events
            </a>
        </div>
    </div>

    <!-- Saved Searches List -->
    <?php if (empty($searches)): ?>
        <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <p style="color: var(--charcoal-grey); margin: 0;">No saved searches found. <a href="search.php" style="color: var(--accent-blue);">Create one</a></p>
        </div>
    <?php else: ?>
        <div style="display: grid; gap: var(--spacing-md);">
            <?php foreach ($searches as $search): ?>
                <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-md);">
                        <div style="flex: 1;">
                            <h3 style="color: var(--midnight-black); margin: 0 0 var(--spacing-xs) 0; font-size: 18px;">
                                <?php echo htmlspecialchars($search['name']); ?>
                            </h3>
                            <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-xs);">
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($search['entity_type'])); ?>
                                </span>
                                <span style="color: var(--charcoal-grey); font-size: 12px;">
                                    Created <?php echo date('M j, Y', strtotime($search['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <a href="<?php echo htmlspecialchars(buildSavedSearchUrl(
                                    $search['entity_type'],
                                    $search['search_query'],
                                    is_array($search['filters']) ? $search['filters'] : []
                                )); ?>"
                               style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; font-size: 14px;">
                                Run Search
                            </a>
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this saved search?');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="search_id" value="<?php echo $search['id']; ?>">
                                <button type="submit" name="delete" style="background: #fee; color: #c33; padding: var(--spacing-xs) var(--spacing-md); border: 1px solid #fcc; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px; margin-top: var(--spacing-sm);">
                        <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);"><strong>Search Query:</strong></div>
                        <div style="color: var(--midnight-black); font-family: monospace; font-size: 13px;">
                            <?php echo htmlspecialchars($search['search_query']); ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($search['filters']) && is_array($search['filters'])): ?>
                        <?php
                        $filterLabels = [
                            'tag_id'      => 'Tag ID',
                            'stage'       => 'Stage',
                            'assigned_to' => 'Assigned To',
                            'status'      => 'Status',
                            'priority'    => 'Priority',
                            'search'      => 'Text',
                            'company_id'  => 'Company ID',
                        ];
                        ?>
                        <div style="margin-top: var(--spacing-sm); display: flex; flex-wrap: wrap; gap: 6px;">
                            <?php foreach ($search['filters'] as $fKey => $fVal): ?>
                                <?php if ($fVal !== null && $fVal !== ''): ?>
                                    <span style="background: #e8f0fe; color: #1a73e8; padding: 3px 10px; border-radius: 12px; font-size: 12px;">
                                        <?php echo htmlspecialchars(($filterLabels[$fKey] ?? ucfirst(str_replace('_', ' ', $fKey))) . ': ' . (is_array($fVal) ? implode(', ', $fVal) : $fVal)); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
