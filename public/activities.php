<?php
/**
 * Activities List Page
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
use CRM\Modules\Activities;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$activitiesModule = new Activities();
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

// Handle filters
$type = trim((string) ($_GET['type'] ?? ''));
if ($type !== '') {
    try {
        $type = Activities::normalizeActivityType($type);
    } catch (\InvalidArgumentException $e) {
        $type = '';
    }
}
$contactId = (int) ($_GET['contact_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

$buildActivitiesUrl = static function (array $overrides = []) use ($type, $contactId, $page): string {
    $params = [
        'type' => $type !== '' ? $type : null,
        'contact_id' => $contactId > 0 ? $contactId : null,
        'page' => $page > 1 ? $page : null,
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '' && $value !== 0);
    $query = http_build_query($params);
    return 'activities.php' . ($query !== '' ? '?' . $query : '');
};

$buildExportUrl = static function () use ($type, $contactId): string {
    $params = [
        'type' => $type !== '' ? $type : null,
        'contact_id' => $contactId > 0 ? $contactId : null,
    ];
    $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '' && $value !== 0);
    $query = http_build_query($params);
    return 'activities_export.php' . ($query !== '' ? '?' . $query : '');
};

// Get activities
if ($contactId) {
    $activities = $activitiesModule->getByContact($contactId, $limit, $offset);
    $totalActivities = $activitiesModule->countByContact($contactId);
} elseif ($type) {
    $activities = $activitiesModule->getByType($type, $limit, $offset);
    $totalActivities = $activitiesModule->countByType($type);
} else {
    $activities = $activitiesModule->getRecent($limit, null, $offset);
    $totalActivities = $activitiesModule->countAll();
}

$totalPages = ceil($totalActivities / $limit);

// Get activity type counts
$typeCounts = $activitiesModule->getTypeCounts();

// Get contacts for filter
$contacts = Database::query(
    "SELECT id, first_name, last_name, email
     FROM contacts
     WHERE workspace_id = ?
     ORDER BY first_name, last_name
     LIMIT 100",
    [$workspaceId]
);

$pageTitle = 'Activities - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Activities</h1>
                <p>View and manage contact activities</p>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo htmlspecialchars($buildExportUrl()); ?>" class="btn-premium-secondary">
                    <i class="fas fa-file-export"></i>
                    Export CSV
                </a>
                <a href="activity_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Add Activity
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="type">Activity Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($typeCounts as $typeOption): ?>
                            <option value="<?php echo htmlspecialchars($typeOption['type']); ?>" <?php echo $type === $typeOption['type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($typeOption['label']); ?>
                                <?php if (!$typeOption['is_manual']): ?> (System)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="contact_id">Contact</label>
                    <select id="contact_id" name="contact_id">
                        <option value="">All Contacts</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?php echo $contact['id']; ?>" <?php echo $contactId === $contact['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: ($contact['email'] ?? 'Contact #' . $contact['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($type || $contactId): ?>
                        <a href="activities.php" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="activity-summary-bar">
            <p class="premium-result-note">
                <?php echo number_format($totalActivities); ?> activity<?php echo $totalActivities === 1 ? '' : 'ies'; ?>
                <?php if ($type !== ''): ?> matching <?php echo htmlspecialchars(Activities::formatTypeLabel($type)); ?><?php endif; ?>
            </p>
        </div>

        <!-- Activity Type Stats -->
        <div class="activity-type-chips" aria-label="Activity type filters">
            <a href="<?php echo htmlspecialchars($buildActivitiesUrl(['type' => null, 'page' => null])); ?>" class="activity-type-chip <?php echo $type === '' ? 'active' : ''; ?>">
                All <span class="activity-type-chip-count"><?php echo number_format($activitiesModule->countAll()); ?></span>
            </a>
            <?php foreach ($typeCounts as $typeOption): ?>
                <a href="<?php echo htmlspecialchars($buildActivitiesUrl(['type' => $typeOption['type'], 'page' => null])); ?>" class="activity-type-chip <?php echo $type === $typeOption['type'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($typeOption['label']); ?>
                    <span class="activity-type-chip-count"><?php echo number_format($typeOption['count']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Activities List -->
        <div class="table-card">
            <div class="premium-section-header">
                <div>
                    <h2>Activity Timeline</h2>
                    <p>Showing <?php echo number_format(count($activities)); ?> of <?php echo number_format($totalActivities); ?> activities.</p>
                </div>
            </div>
            <?php if (empty($activities)): ?>
                <div class="empty-state">
                    <p>No activities found.</p>
                    <div class="activity-empty-actions">
                        <a href="activity_create.php" class="btn-premium-primary">
                            <i class="fas fa-plus"></i>
                            Add Activity
                        </a>
                        <?php if ($type || $contactId): ?>
                            <a href="activities.php" class="btn-premium-secondary">Clear filters</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="premium-list">
                    <?php foreach ($activities as $activity): ?>
                        <div class="premium-list-row">
                            <div class="premium-list-main">
                                <div class="activity-row-kicker">
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars(Activities::formatTypeLabel($activity['activity_type'] ?? '')); ?>
                                    </span>
                                    <a href="contact_view.php?id=<?php echo (int) $activity['contact_id']; ?>" class="premium-list-title">
                                        <?php echo htmlspecialchars(trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')) ?: ($activity['contact_email'] ?? 'Contact #' . (int) $activity['contact_id'])); ?>
                                    </a>
                                </div>
                                <?php if (!empty($activity['description'])): ?>
                                    <div class="activity-description">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="premium-list-meta">
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                                    <?php if ($activity['user_email']): ?>
                                        by <?php echo htmlspecialchars($activity['user_email']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="premium-list-actions">
                                <a href="activity_view.php?id=<?php echo (int) $activity['id']; ?>" class="btn-premium-secondary btn-premium-sm"><i class="fas fa-eye"></i> View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalActivities); ?> of <?php echo $totalActivities; ?> activities
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars($buildActivitiesUrl(['page' => $page - 1])); ?>" class="pagination-link">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="<?php echo htmlspecialchars($buildActivitiesUrl(['page' => $i > 1 ? $i : null])); ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo htmlspecialchars($buildActivitiesUrl(['page' => $page + 1])); ?>" class="pagination-link">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
