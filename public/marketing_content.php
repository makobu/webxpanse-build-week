<?php
/**
 * Marketing Content Studio list and workbench.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
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
use CRM\Modules\Marketing;
use CRM\Services\MarketingMediaReadinessUi;
use CRM\Services\MarketingWorkflowNextStepsUi;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$userId = (int) ($user['id'] ?? 0);
$error = '';
$notice = '';

$readFilters = static function (array $source): array {
    $filters = [
        'status' => trim((string) ($source['status'] ?? '')),
        'content_type' => trim((string) ($source['content_type'] ?? '')),
        'channel' => trim((string) ($source['channel'] ?? '')),
        'production_stage' => trim((string) ($source['production_stage'] ?? '')),
        'dependency_status' => trim((string) ($source['dependency_status'] ?? '')),
        'campaign_id' => (int) ($source['campaign_id'] ?? 0),
        'owner_user_id' => (int) ($source['owner_user_id'] ?? 0),
        'scheduled_from' => trim((string) ($source['scheduled_from'] ?? '')),
        'scheduled_to' => trim((string) ($source['scheduled_to'] ?? '')),
        'production_due_from' => trim((string) ($source['production_due_from'] ?? '')),
        'production_due_to' => trim((string) ($source['production_due_to'] ?? '')),
        'blocked' => !empty($source['blocked']) ? true : '',
        'production_overdue' => !empty($source['production_overdue']) ? true : '',
        'media_warning' => !empty($source['media_warning']) ? true : '',
    ];

    return array_filter($filters, static fn($value): bool => $value !== '' && $value !== 0 && $value !== false);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to manage marketing content.');
            }
            $marketing->deleteContentItem((int) ($_POST['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content.php?success=deleted');
            exit;
        }

        if ($action === 'bulk_update') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to edit marketing content.');
            }
            $updates = [];
            foreach ([
                'bulk_status' => 'status',
                'bulk_owner_user_id' => 'owner_user_id',
                'bulk_scheduled_at' => 'scheduled_at',
                'bulk_reviewer_user_id' => 'reviewer_user_id',
                'bulk_review_due_at' => 'review_due_at',
                'bulk_blocked_reason' => 'blocked_reason',
                'bulk_production_stage' => 'production_stage',
                'bulk_production_due_at' => 'production_due_at',
                'bulk_dependency_status' => 'dependency_status',
                'bulk_dependency_notes' => 'dependency_notes',
            ] as $postField => $updateField) {
                if (array_key_exists($postField, $_POST) && trim((string) $_POST[$postField]) !== '') {
                    $updates[$updateField] = $_POST[$postField];
                }
            }
            $result = $marketing->bulkUpdateContentItems((array) ($_POST['selected_ids'] ?? []), $updates, $userId);
            header('Location: ' . getBasePath() . '/marketing_content.php?success=bulk&count=' . (int) $result['updated_count']);
            exit;
        }

        if ($action === 'save_view') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to save marketing views.');
            }
            $viewId = $marketing->createSavedView([
                'name' => $_POST['view_name'] ?? '',
                'view_type' => 'content',
                'scope' => $_POST['view_scope'] ?? 'private',
                'view_mode' => $_POST['view_mode'] ?? 'list',
                'is_default' => !empty($_POST['is_default']),
                'filters' => $readFilters($_POST),
                'sort' => ['field' => 'scheduled_at', 'direction' => 'asc'],
                'user_id' => $userId,
                'created_by' => $userId,
            ]);
            header('Location: ' . getBasePath() . '/marketing_content.php?saved_view_id=' . (int) $viewId . '&success=view_saved');
            exit;
        }

        if ($action === 'update_preferences') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update marketing preferences.');
            }
            $marketing->updateWorkbenchPreferences([
                'default_view_mode' => $_POST['view_mode'] ?? 'list',
                'default_saved_view_id' => (int) ($_POST['saved_view_id'] ?? 0),
                'preferences' => [
                    'content_studio_updated_at' => date('c'),
                    'content_items_per_page' => (int) ($_POST['per_page'] ?? 50),
                ],
            ], $userId);
            header('Location: ' . getBasePath() . '/marketing_content.php?view_mode=' . urlencode((string) ($_POST['view_mode'] ?? 'list')) . '&per_page=' . (int) ($_POST['per_page'] ?? 50) . '&success=preferences');
            exit;
        }

        if ($action === 'queue_followthrough') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create content follow-through work.');
            }
            $result = $marketing->createMarketingQueueAction([
                'surface' => 'content_production',
                'source_id' => (int) ($_POST['source_id'] ?? 0),
                'action_key' => (string) ($_POST['action_key'] ?? ''),
            ], $userId);
            header('Location: ' . getBasePath() . '/marketing_content.php?success=queue_action&created=' . count((array) ($result['created'] ?? [])) . '&reused=' . count((array) ($result['reused'] ?? [])) . '&warnings=' . count((array) ($result['warnings'] ?? [])));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$savedViews = $marketing->listSavedViews('content', $userId);
$selectedSavedViewId = (int) ($_GET['saved_view_id'] ?? 0);
$selectedSavedView = $selectedSavedViewId > 0 ? $marketing->getSavedView($selectedSavedViewId, $userId) : null;
$filters = $readFilters($_GET);
if ($selectedSavedView) {
    $filters = array_filter(array_merge((array) ($selectedSavedView['filters_json'] ?? []), $filters), static fn($value): bool => $value !== '' && $value !== 0 && $value !== false);
}
$preferences = $marketing->getWorkbenchPreferences($userId);
$viewMode = (string) ($_GET['view_mode'] ?? ($selectedSavedView['view_mode'] ?? ($preferences['default_view_mode'] ?? 'list')));
if (!in_array($viewMode, Marketing::WORKBENCH_VIEW_MODES, true)) {
    $viewMode = 'list';
}
$allowedPageSizes = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? ($preferences['preferences_json']['content_items_per_page'] ?? 50));
if (!in_array($perPage, $allowedPageSizes, true)) {
    $perPage = 50;
}
$pageNumber = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($pageNumber - 1) * $perPage;

$items = [];
$hasMoreItems = false;
try {
    $items = $marketing->listContentItems($filters, $perPage + 1, $offset);
    if (!empty($filters['media_warning'])) {
        $items = array_values(array_filter($items, static fn(array $item): bool => !empty($item['_media_readiness']['warnings'] ?? [])));
    }
    $hasMoreItems = count($items) > $perPage;
    if ($hasMoreItems) {
        $items = array_slice($items, 0, $perPage);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$options = $marketing->optionData();

$success = (string) ($_GET['success'] ?? '');
if ($success === 'deleted') {
    $notice = 'Marketing content deleted.';
} elseif ($success === 'bulk') {
    $notice = (int) ($_GET['count'] ?? 0) . ' content items updated.';
} elseif ($success === 'view_saved') {
    $notice = 'Marketing view saved.';
} elseif ($success === 'preferences') {
    $notice = 'Marketing workbench preference saved.';
} elseif ($success === 'queue_action') {
    $notice = (int) ($_GET['created'] ?? 0) . ' content follow-through item(s) created; ' . (int) ($_GET['reused'] ?? 0) . ' existing open item(s) reused.';
    if ((int) ($_GET['warnings'] ?? 0) > 0) {
        $notice .= ' Some cross-tool actions were skipped because their target tool permissions are unavailable.';
    }
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn(string $left, string $right): string => $left === $right ? 'selected' : '';
$checked = static fn(bool $value): string => $value ? 'checked' : '';
$filterValue = static fn(string $key, mixed $fallback = '') => $_GET[$key] ?? $filters[$key] ?? $fallback;
$contentQueueActionKeys = [
    'blocked' => 'content_unblock_task',
    'overdue' => 'content_schedule_task',
    'review_queue' => 'content_review_task',
    'media_warnings' => 'content_media_request',
    'missing_links' => 'content_link_context_task',
    'due_this_week' => 'content_production_task',
];
$pageUrl = static function (array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === false) {
            unset($query[$key]);
        }
    }

    return 'marketing_content.php?' . http_build_query($query);
};
$boardGroups = [];
foreach (Marketing::STATUSES as $status) {
    $boardGroups[$status] = [];
}
foreach ($items as $item) {
    $boardGroups[(string) $item['status']][] = $item;
}
$productionSnapshot = [
    'total' => count($items),
    'media_warnings' => count(array_filter($items, static fn(array $item): bool => !empty($item['_media_readiness']['warnings'] ?? []))),
    'blocked' => count(array_filter($items, static fn(array $item): bool => (string) ($item['dependency_status'] ?? 'clear') === 'blocked' || trim((string) ($item['blocked_reason'] ?? '')) !== '')),
    'review_queue' => count(array_filter($items, static fn(array $item): bool => in_array((string) ($item['status'] ?? ''), ['review', 'approved'], true) || in_array((string) ($item['production_stage'] ?? ''), ['review', 'approved'], true))),
    'due_soon' => count(array_filter($items, static function (array $item): bool {
        $due = trim((string) ($item['production_due_at'] ?? $item['scheduled_at'] ?? ''));
        return $due !== '' && strtotime($due) !== false && strtotime($due) <= strtotime('+7 days');
    })),
];
$productionCommandQueue = $marketing->getCachedContentProductionCommandQueue(null, 6, 90);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter($userId, 'content', 6);
$mediaReadiness = $marketing->getMarketingMediaOperationalReadiness($userId, 6);
$showingStart = empty($items) ? 0 : $offset + 1;
$showingEnd = empty($items) ? 0 : $offset + count($items);
$contentStatusCounts = array_fill_keys(Marketing::STATUSES, 0);
foreach ($items as $item) {
    $itemStatus = (string) ($item['status'] ?? 'idea');
    $contentStatusCounts[$itemStatus] = ($contentStatusCounts[$itemStatus] ?? 0) + 1;
}
$queueCounts = (array) ($productionCommandQueue['counts'] ?? []);
$queueScore = max(0, min(100, (int) ($productionCommandQueue['score'] ?? 0)));
$statusTone = static function (int $count, bool $inverse = false): string {
    if ($inverse) {
        return $count > 0 ? 'blocked' : 'ready';
    }
    return $count > 0 ? 'attention' : 'ready';
};
$scoreTone = static function (int $score): string {
    if ($score >= 80) { return 'ready'; }
    if ($score >= 45) { return 'attention'; }
    return 'blocked';
};
$summaryTiles = [
    ['icon' => 'fa-layer-group', 'label' => 'In View', 'value' => (string) $productionSnapshot['total'], 'tooltip' => 'Content items currently matching this view.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Blocked', 'value' => (string) $productionSnapshot['blocked'], 'tooltip' => 'Items with dependency or blocked-reason evidence.'],
    ['icon' => 'fa-clipboard-check', 'label' => 'Review', 'value' => (string) $productionSnapshot['review_queue'], 'tooltip' => 'Content currently in review or approved stages.'],
    ['icon' => 'fa-calendar-day', 'label' => 'Due Soon', 'value' => (string) $productionSnapshot['due_soon'], 'tooltip' => 'Items due or scheduled within seven days.'],
    ['icon' => 'fa-image', 'label' => 'Media Gaps', 'value' => (string) $productionSnapshot['media_warnings'], 'tooltip' => 'Items with media readiness warnings.'],
];
$contentCards = [
    [
        'icon' => 'fa-lightbulb',
        'title' => 'Capture Ideas',
        'status' => ($contentStatusCounts['idea'] ?? 0) > 0 ? 'In use' : 'Ready',
        'tone' => ($contentStatusCounts['idea'] ?? 0) > 0 ? 'attention' : 'ready',
        'copy' => 'Turn campaign thoughts into visible work.',
        'tooltip' => 'Ideas are early content items that still need structure before production.',
        'href' => $canWriteMarketing ? 'marketing_content_edit.php' : '#content-workbench',
        'action' => $canWriteMarketing ? 'New Content' : 'Open Workbench',
    ],
    [
        'icon' => 'fa-pen-nib',
        'title' => 'Draft Message',
        'status' => ($contentStatusCounts['draft'] ?? 0) > 0 ? 'In use' : 'Ready',
        'tone' => ($contentStatusCounts['draft'] ?? 0) > 0 ? 'attention' : 'ready',
        'copy' => 'Shape the copy before review.',
        'tooltip' => 'Drafts need a clear offer, audience fit, and next action before review.',
        'href' => 'marketing_content.php?view_mode=board&status=draft',
        'action' => 'View Drafts',
    ],
    [
        'icon' => 'fa-photo-film',
        'title' => 'Add Media',
        'status' => $productionSnapshot['media_warnings'] > 0 ? 'Setup needed' : 'Ready',
        'tone' => $statusTone((int) $productionSnapshot['media_warnings']),
        'copy' => 'Clear visual gaps before launch.',
        'tooltip' => 'Media warnings stay available without putting every warning above the fold.',
        'href' => 'marketing_content.php?media_warning=1',
        'action' => 'Check Media',
    ],
    [
        'icon' => 'fa-comments',
        'title' => 'Review Content',
        'status' => $productionSnapshot['review_queue'] > 0 ? 'In use' : 'Ready',
        'tone' => $productionSnapshot['review_queue'] > 0 ? 'attention' : 'ready',
        'copy' => 'Get the draft approved or corrected.',
        'tooltip' => 'Review work is routed to the review page from inside the workbench when needed.',
        'href' => 'marketing_content.php?view_mode=board&status=review',
        'action' => 'View Review',
    ],
    [
        'icon' => 'fa-calendar-days',
        'title' => 'Schedule Work',
        'status' => $productionSnapshot['due_soon'] > 0 ? 'In use' : 'Ready',
        'tone' => $productionSnapshot['due_soon'] > 0 ? 'attention' : 'ready',
        'copy' => 'Keep near-term work visible.',
        'tooltip' => 'Due-soon work helps a founder choose what to finish next.',
        'href' => 'marketing_content.php?production_due_to=' . urlencode(date('Y-m-d', strtotime('+7 days'))),
        'action' => 'See Due Work',
    ],
    [
        'icon' => 'fa-route',
        'title' => 'Clear Blockers',
        'status' => $productionSnapshot['blocked'] > 0 ? 'Setup needed' : 'Ready',
        'tone' => $statusTone((int) $productionSnapshot['blocked'], true),
        'copy' => 'Fix dependencies before launch steps.',
        'tooltip' => 'Blocked items remain visible and route to the exact filtered workbench.',
        'href' => 'marketing_content.php?blocked=1',
        'action' => 'See Blockers',
    ],
];
$todayActions = [];
foreach (array_slice((array) ($workflowNextSteps['actions'] ?? []), 0, 3) as $action) {
    $todayActions[] = [
        'icon' => 'fa-list-check',
        'label' => (string) ($action['label'] ?? 'Review content'),
        'hint' => (string) ($action['reason'] ?? 'Move this content step forward.'),
        'href' => (string) ($action['href'] ?? 'marketing_content.php'),
        'tooltip' => 'Pulled from the simplified Marketing workflow.',
    ];
}
if ($productionSnapshot['blocked'] > 0) {
    $todayActions[] = ['icon' => 'fa-triangle-exclamation', 'label' => 'Unblock content', 'hint' => $productionSnapshot['blocked'] . ' item(s) need dependency help.', 'href' => 'marketing_content.php?blocked=1', 'tooltip' => 'Filters the workbench to blocked content.'];
}
if ($productionSnapshot['media_warnings'] > 0) {
    $todayActions[] = ['icon' => 'fa-image', 'label' => 'Fix media gaps', 'hint' => $productionSnapshot['media_warnings'] . ' item(s) have media warnings.', 'href' => 'marketing_content.php?media_warning=1', 'tooltip' => 'Shows content with media readiness gaps.'];
}
if ($canWriteMarketing) {
    $todayActions[] = ['icon' => 'fa-plus', 'label' => 'Create content', 'hint' => 'Start one clear piece of campaign content.', 'href' => 'marketing_content_edit.php', 'tooltip' => 'Adds a new draft without exposing every workbench tool first.'];
}
if (empty($todayActions)) {
    $todayActions[] = ['icon' => 'fa-table-columns', 'label' => 'Open workbench', 'hint' => 'Review the full filtered content list.', 'href' => '#content-workbench', 'tooltip' => 'The detailed list and filters stay one intentional step away.'];
}
$todayActions = array_slice($todayActions, 0, 5);
$pageTitle = 'Content Studio - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-ui-page marketing-content-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Content Studio</h1>
                <p>Choose the next piece to draft, review, or clear.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($canWriteMarketing): ?><a href="marketing_campaign_kit.php" class="btn-premium-secondary"><i class="fas fa-wand-magic-sparkles"></i> Build Campaign Kit</a><?php endif; ?>
                <?php if ($canWriteMarketing): ?><a href="marketing_content_edit.php" class="btn-premium-primary"><i class="fas fa-plus"></i> New Content</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Content Studio could not apply that change.</strong> <?php echo htmlspecialchars($error); ?> No records were changed.</div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <section class="marketing-content-summary" aria-label="Content summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                    <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>"></i>
                    <div>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="marketing-content-layout" aria-label="Content workspace">
            <div class="marketing-content-main">
                <div class="content-card marketing-content-board">
                    <div class="premium-section-header">
                        <div>
                            <h2>Content Board</h2>
                            <p>Six paths, one action each.</p>
                        </div>
                        <span class="badge badge-default"><?php echo $queueScore; ?>% queue</span>
                    </div>
                    <div class="marketing-content-card-grid">
                        <?php foreach ($contentCards as $card): ?>
                            <article class="marketing-content-stage-card <?php echo htmlspecialchars((string) $card['tone']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                                <div class="marketing-content-visual"><i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>"></i></div>
                                <div class="marketing-content-card-body">
                                    <div class="marketing-content-card-title">
                                        <strong><?php echo htmlspecialchars((string) $card['title']); ?></strong>
                                        <span class="marketing-content-status <?php echo htmlspecialchars((string) $card['tone']); ?>"><?php echo htmlspecialchars((string) $card['status']); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars((string) $card['copy']); ?></span>
                                </div>
                                <a class="btn-premium-secondary marketing-content-card-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <aside class="content-card marketing-content-today" aria-label="Today">
                <div class="premium-section-header">
                    <div>
                        <h2>Today</h2>
                        <p>Next useful moves.</p>
                    </div>
                </div>
                <div class="marketing-content-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-content-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $action['tooltip']); ?>">
                            <i class="fas <?php echo htmlspecialchars((string) $action['icon']); ?>"></i>
                            <span><strong><?php echo htmlspecialchars((string) $action['label']); ?></strong><?php echo htmlspecialchars((string) $action['hint']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>

        <details class="marketing-content-tools" id="content-guidance">
            <summary>Guidance and media readiness</summary>
            <div class="marketing-content-tools-body">
                <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                <?php echo MarketingMediaReadinessUi::render($mediaReadiness); ?>
            </div>
        </details>

        <details class="marketing-content-tools" id="content-workbench">
            <summary>Open content workbench</summary>
            <div class="marketing-content-tools-body">

        <div class="content-card">
            <div class="marketing-toolbar">
                <div class="marketing-toolbar-group marketing-view-tabs">
                    <a class="<?php echo $viewMode === 'list' ? 'active' : ''; ?>" href="marketing_content.php?<?php echo http_build_query(array_merge($_GET, ['view_mode' => 'list'])); ?>"><i class="fas fa-list"></i> List</a>
                    <a class="<?php echo $viewMode === 'board' ? 'active' : ''; ?>" href="marketing_content.php?<?php echo http_build_query(array_merge($_GET, ['view_mode' => 'board'])); ?>"><i class="fas fa-table-columns"></i> Board</a>
                </div>
                <div class="marketing-toolbar-group">
                    <form method="GET" class="marketing-toolbar-group">
                        <?php foreach ($_GET as $key => $value): if (in_array((string) $key, ['per_page', 'page'], true) || is_array($value)) { continue; } ?>
                            <input type="hidden" name="<?php echo htmlspecialchars((string) $key); ?>" value="<?php echo htmlspecialchars((string) $value); ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="page" value="1">
                        <label class="sr-only" for="content-per-page">Items per page</label>
                        <select id="content-per-page" name="per_page" onchange="this.form.submit()">
                            <?php foreach ($allowedPageSizes as $pageSize): ?>
                                <option value="<?php echo (int) $pageSize; ?>" <?php echo $perPage === $pageSize ? 'selected' : ''; ?>><?php echo (int) $pageSize; ?> per page</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if (!empty($savedViews)): ?>
                        <form method="GET" class="marketing-toolbar-group">
                            <select name="saved_view_id" onchange="this.form.submit()">
                                <option value="">Saved views</option>
                                <?php foreach ($savedViews as $view): ?>
                                    <option value="<?php echo (int) $view['id']; ?>" <?php echo ((int) $selectedSavedViewId === (int) $view['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $view['name']); ?><?php echo !empty($view['is_default']) ? ' - Default' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($viewMode); ?>">
                            <input type="hidden" name="per_page" value="<?php echo (int) $perPage; ?>">
                        </form>
                    <?php endif; ?>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-toolbar-group">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="update_preferences">
                            <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($viewMode); ?>">
                            <input type="hidden" name="saved_view_id" value="<?php echo (int) $selectedSavedViewId; ?>">
                            <input type="hidden" name="per_page" value="<?php echo (int) $perPage; ?>">
                            <button class="btn-premium-secondary" type="submit"><i class="fas fa-thumbtack"></i> Save Preference</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="filters-card">
            <form method="GET" class="marketing-filters">
                <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($viewMode); ?>">
                <input type="hidden" name="page" value="1">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">Any status</option>
                        <?php foreach (Marketing::STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected((string) $filterValue('status'), $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Content Type</label>
                    <select name="content_type">
                        <option value="">Any type</option>
                        <?php foreach (Marketing::CONTENT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo $selected((string) $filterValue('content_type'), $type); ?>><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Channel</label>
                    <select name="channel">
                        <option value="">Any channel</option>
                        <?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>" <?php echo $selected((string) $filterValue('channel'), $channel); ?>><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Production Stage</label>
                    <select name="production_stage">
                        <option value="">Any stage</option>
                        <?php foreach (Marketing::CONTENT_PRODUCTION_STAGES as $stage): ?><option value="<?php echo htmlspecialchars($stage); ?>" <?php echo $selected((string) $filterValue('production_stage'), $stage); ?>><?php echo htmlspecialchars($labelize($stage)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Dependency Health</label>
                    <select name="dependency_status">
                        <option value="">Any dependency status</option>
                        <?php foreach (Marketing::CONTENT_DEPENDENCY_HEALTH_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected((string) $filterValue('dependency_status'), $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Campaign</label>
                    <select name="campaign_id">
                        <option value="">Any campaign</option>
                        <?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo ((int) $filterValue('campaign_id') === (int) $campaign['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Owner</label>
                    <select name="owner_user_id">
                        <option value="">Any owner</option>
                        <?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo ((int) $filterValue('owner_user_id') === (int) $owner['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Scheduled From</label>
                    <input type="date" name="scheduled_from" value="<?php echo htmlspecialchars((string) $filterValue('scheduled_from')); ?>">
                </div>
                <div class="form-group">
                    <label>Scheduled To</label>
                    <input type="date" name="scheduled_to" value="<?php echo htmlspecialchars((string) $filterValue('scheduled_to')); ?>">
                </div>
                <div class="form-group">
                    <label>Production Due From</label>
                    <input type="date" name="production_due_from" value="<?php echo htmlspecialchars((string) $filterValue('production_due_from')); ?>">
                </div>
                <div class="form-group">
                    <label>Production Due To</label>
                    <input type="date" name="production_due_to" value="<?php echo htmlspecialchars((string) $filterValue('production_due_to')); ?>">
                </div>
                <div class="form-group">
                    <label>Blocked</label>
                    <label class="marketing-inline-checkbox"><input type="checkbox" name="blocked" value="1" <?php echo $checked(!empty($filters['blocked'])); ?>> Show blocked only</label>
                </div>
                <div class="form-group">
                    <label>Overdue</label>
                    <label class="marketing-inline-checkbox"><input type="checkbox" name="production_overdue" value="1" <?php echo $checked(!empty($filters['production_overdue'])); ?>> Production overdue</label>
                </div>
                <div class="form-group">
                    <label>Media</label>
                    <label class="marketing-inline-checkbox"><input type="checkbox" name="media_warning" value="1" <?php echo $checked(!empty($filters['media_warning'])); ?>> Media warnings</label>
                </div>
                <div class="form-group">
                    <label>Results Per Page</label>
                    <select name="per_page">
                        <?php foreach ($allowedPageSizes as $pageSize): ?><option value="<?php echo (int) $pageSize; ?>" <?php echo $perPage === $pageSize ? 'selected' : ''; ?>><?php echo (int) $pageSize; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group marketing-filter-actions">
                    <button class="btn-premium-primary" type="submit">Filter</button>
                    <a class="btn-premium-secondary" href="marketing_content.php?view_mode=<?php echo urlencode($viewMode); ?>">Reset</a>
                </div>
            </form>
            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="marketing-save-view-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_view">
                    <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($viewMode); ?>">
                    <?php foreach (['status', 'content_type', 'channel', 'production_stage', 'dependency_status', 'campaign_id', 'owner_user_id', 'scheduled_from', 'scheduled_to', 'production_due_from', 'production_due_to', 'blocked', 'production_overdue', 'media_warning'] as $key): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars((string) ($filters[$key] ?? '')); ?>">
                    <?php endforeach; ?>
                    <div class="form-group marketing-save-view-name"><label>Save Current View</label><input type="text" name="view_name" placeholder="My weekly content view" required></div>
                    <div class="form-group marketing-save-view-scope"><label>Scope</label><select name="view_scope"><option value="private">Private</option><option value="workspace">Workspace</option></select></div>
                    <label class="marketing-inline-checkbox"><input type="checkbox" name="is_default" value="1"> Default</label>
                    <button class="btn-premium-secondary" type="submit"><i class="fas fa-bookmark"></i> Save View</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($canWriteMarketing && !empty($items)): ?>
            <form method="POST" class="content-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="bulk_update">
                <div class="premium-section-header"><h2>Bulk Update Selected Content</h2></div>
                <div class="marketing-bulk-panel">
                    <div class="form-group"><label>Status</label><select name="bulk_status"><option value="">Keep status</option><?php foreach (Marketing::STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Production Stage</label><select name="bulk_production_stage"><option value="">Keep stage</option><?php foreach (Marketing::CONTENT_PRODUCTION_STAGES as $stage): ?><option value="<?php echo htmlspecialchars($stage); ?>"><?php echo htmlspecialchars($labelize($stage)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Owner</label><select name="bulk_owner_user_id"><option value="">Keep owner</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>"><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Scheduled At</label><input type="datetime-local" name="bulk_scheduled_at"></div>
                    <div class="form-group"><label>Production Due</label><input type="datetime-local" name="bulk_production_due_at"></div>
                    <div class="form-group"><label>Reviewer</label><select name="bulk_reviewer_user_id"><option value="">Keep reviewer</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>"><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Review Due</label><input type="datetime-local" name="bulk_review_due_at"></div>
                    <div class="form-group"><label>Dependency Health</label><select name="bulk_dependency_status"><option value="">Keep dependency health</option><?php foreach (Marketing::CONTENT_DEPENDENCY_HEALTH_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group marketing-bulk-wide"><label>Blocked Reason</label><input type="text" name="bulk_blocked_reason" placeholder="Only fill when marking selected work as blocked"></div>
                    <div class="form-group marketing-bulk-wide"><label>Dependency Notes</label><input type="text" name="bulk_dependency_notes" placeholder="Optional dependency or handoff notes for selected work"></div>
                    <button class="btn-premium-primary" type="submit">Apply To Selected</button>
                </div>
                <p class="marketing-helper-copy">Use the checkboxes in the list or board below, then apply one or more updates.</p>
                <div id="bulk-selection-anchor"></div>
            </form>
        <?php endif; ?>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Production Board Snapshot</h2><p>Spot blocked work, review load, due dates, and media gaps before moving items across the board.</p></div><a class="btn-premium-secondary" href="marketing_task_hub.php">Open Task Hub</a></div>
            <div class="production-snapshot">
                <div class="production-snapshot-card"><span>Total In View</span><strong><?php echo (int) $productionSnapshot['total']; ?></strong></div>
                <div class="production-snapshot-card"><span>Media Warnings</span><strong><?php echo (int) $productionSnapshot['media_warnings']; ?></strong></div>
                <div class="production-snapshot-card"><span>Blocked</span><strong><?php echo (int) $productionSnapshot['blocked']; ?></strong></div>
                <div class="production-snapshot-card"><span>Review Queue</span><strong><?php echo (int) $productionSnapshot['review_queue']; ?></strong></div>
                <div class="production-snapshot-card"><span>Due Soon</span><strong><?php echo (int) $productionSnapshot['due_soon']; ?></strong></div>
            </div>
        </div>

        <div class="content-card">
            <div class="premium-section-header">
                <div><h2>Production Command Queue</h2><p>Workspace-wide operator queue for blocked, overdue, review, media, and missing-link work. It stays capped for faster page loads.</p></div>
                <span class="badge <?php echo (string) ($productionCommandQueue['status'] ?? '') === 'healthy' ? 'badge-success' : ((string) ($productionCommandQueue['status'] ?? '') === 'blocked' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo (int) ($productionCommandQueue['score'] ?? 0); ?>% <?php echo htmlspecialchars($labelize((string) ($productionCommandQueue['status'] ?? 'empty'))); ?></span>
            </div>
            <div class="production-snapshot production-command-summary">
                <div class="production-snapshot-card"><span>Total Pipeline</span><strong><?php echo (int) ($productionCommandQueue['counts']['total'] ?? 0); ?></strong></div>
                <div class="production-snapshot-card"><span>Blocked</span><strong><?php echo (int) ($productionCommandQueue['counts']['blocked'] ?? 0); ?></strong></div>
                <div class="production-snapshot-card"><span>Overdue</span><strong><?php echo (int) ($productionCommandQueue['counts']['overdue'] ?? 0); ?></strong></div>
                <div class="production-snapshot-card"><span>Missing Links</span><strong><?php echo (int) ($productionCommandQueue['counts']['missing_links'] ?? 0); ?></strong></div>
                <div class="production-snapshot-card"><span>Scan Limit</span><strong><?php echo (int) ($productionCommandQueue['performance']['media_scan_limit'] ?? 0); ?></strong></div>
            </div>
            <div class="production-command-grid">
                <div class="production-command-lanes">
                    <?php foreach ([
                        'blocked' => 'Blocked',
                        'overdue' => 'Overdue',
                        'review_queue' => 'Review Queue',
                        'media_warnings' => 'Media Warnings',
                        'missing_links' => 'Missing Links',
                        'due_this_week' => 'Due This Week',
                    ] as $queueKey => $queueLabel): ?>
                        <div class="production-command-lane">
                            <h3><?php echo htmlspecialchars($queueLabel); ?></h3>
                            <?php $queueItems = (array) ($productionCommandQueue['queues'][$queueKey] ?? []); ?>
                            <?php if (empty($queueItems)): ?>
                                <p class="marketing-meta">No items.</p>
                            <?php else: ?>
                                <?php foreach ($queueItems as $queueItem): ?>
                                    <a class="production-command-item" href="marketing_content_view.php?id=<?php echo (int) ($queueItem['id'] ?? 0); ?>">
                                        <strong><?php echo htmlspecialchars((string) ($queueItem['title'] ?? 'Content item')); ?></strong>
                                        <span class="marketing-meta"><?php echo htmlspecialchars($labelize((string) ($queueItem['production_stage'] ?? $queueItem['status'] ?? 'content'))); ?><?php if (!empty($queueItem['production_due_at'])): ?> - due <?php echo htmlspecialchars((string) $queueItem['production_due_at']); ?><?php endif; ?></span>
                                    </a>
                                    <?php if ($canWriteMarketing): ?>
                                        <form method="POST" class="production-command-task-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="queue_followthrough">
                                            <input type="hidden" name="source_id" value="<?php echo (int) ($queueItem['id'] ?? 0); ?>">
                                            <input type="hidden" name="action_key" value="<?php echo htmlspecialchars((string) ($contentQueueActionKeys[$queueKey] ?? 'content_production_task')); ?>">
                                            <button class="btn-premium-secondary production-command-task-button" type="submit">Create task/request</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <div class="production-command-lane">
                        <h3>Next Operator Actions</h3>
                        <?php if (empty($productionCommandQueue['next_actions'])): ?>
                            <p class="marketing-meta">No operator actions are queued.</p>
                        <?php else: ?>
                            <div class="production-command-actions">
                                <?php foreach ((array) ($productionCommandQueue['next_actions'] ?? []) as $action): ?>
                                    <a class="production-command-item" href="<?php echo htmlspecialchars((string) ($action['href'] ?? 'marketing_content.php')); ?>">
                                        <strong><?php echo htmlspecialchars((string) ($action['label'] ?? 'Review content production')); ?></strong>
                                        <span class="marketing-meta"><?php echo htmlspecialchars((string) ($action['reason'] ?? 'Review the production queue.')); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p class="marketing-meta production-command-note"><?php echo htmlspecialchars((string) ($productionCommandQueue['guardrails']['message'] ?? 'Manual-first production queue only.')); ?></p>
                        <p class="marketing-meta">Queue cache: <?php echo htmlspecialchars($labelize((string) ($productionCommandQueue['performance']['cache_status'] ?? 'direct'))); ?><?php if (!empty($productionCommandQueue['performance']['cache_expires_at'])): ?> until <?php echo htmlspecialchars((string) $productionCommandQueue['performance']['cache_expires_at']); ?><?php endif; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="marketing-load-guard">
                <div><strong>Load guard active:</strong> showing <?php echo (int) $showingStart; ?>-<?php echo (int) $showingEnd; ?> records for this filtered view. Use filters or smaller page sizes to keep Content Studio responsive.</div>
                <div>Page <?php echo (int) $pageNumber; ?> - <?php echo (int) $perPage; ?> per page<?php echo $hasMoreItems ? ' - more available' : ''; ?></div>
            </div>
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <p>No marketing content matches this view. Reset filters, open setup, or create the first draft for this workspace.</p>
                    <a href="marketing_content.php?view_mode=<?php echo urlencode($viewMode); ?>">Reset Filters</a>
                    <a href="marketing_onboarding.php">Open Marketing Setup</a>
                    <?php if ($canWriteMarketing): ?><a href="marketing_content_edit.php">Create your first content item</a><?php endif; ?>
                </div>
            <?php elseif ($viewMode === 'board'): ?>
                <div class="marketing-board">
                    <?php foreach ($boardGroups as $status => $group): ?>
                        <?php if (empty($group) && !in_array($status, ['idea', 'draft', 'review', 'approved', 'scheduled'], true)) { continue; } ?>
                        <div class="marketing-board-column">
                            <div class="marketing-board-title">
                                <span><?php echo htmlspecialchars($labelize($status)); ?></span>
                                <span class="badge badge-default"><?php echo count($group); ?></span>
                            </div>
                            <?php if (empty($group)): ?>
                                <div class="marketing-meta">No items</div>
                            <?php else: ?>
                                <?php foreach ($group as $item): ?>
                                    <div class="marketing-card">
                                        <?php if ($canWriteMarketing): ?><label class="marketing-board-select"><input form="content-selection-form" type="checkbox" name="selected_ids[]" value="<?php echo (int) $item['id']; ?>"></label><?php endif; ?>
                                        <a class="marketing-card-title" href="marketing_content_view.php?id=<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></a>
                                        <div class="marketing-meta">
                                            <span><?php echo htmlspecialchars($labelize((string) $item['content_type'])); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) $item['channel'])); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($item['production_stage'] ?? 'idea'))); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($item['owner_email'] ?? 'Unassigned')); ?></span>
                                        </div>
                                        <?php if (!empty($item['next_action'])): ?><div class="marketing-meta"><strong>Next:</strong> <?php echo htmlspecialchars((string) $item['next_action']); ?></div><?php endif; ?>
                                        <?php if (!empty($item['_media_readiness']['warnings'])): ?><div class="marketing-warning-line">Media warnings: <?php echo htmlspecialchars(implode(', ', array_map($labelize, (array) $item['_media_readiness']['warnings']))); ?></div><?php endif; ?>
                                        <?php if (!empty($item['blocked_reason'])): ?><div class="alert alert-warning marketing-card-alert">Blocked: <?php echo htmlspecialchars((string) $item['blocked_reason']); ?></div><?php endif; ?>
                                        <div class="marketing-card-actions">
                                            <a class="marketing-mini-link" href="marketing_content_edit.php?id=<?php echo (int) $item['id']; ?>">Attach Media</a>
                                            <a class="marketing-mini-link" href="marketing_creative.php">AI Visual Help</a>
                                            <a class="marketing-mini-link" href="marketing_distribution.php?content_item_id=<?php echo (int) $item['id']; ?>">Build Distribution</a>
                                            <a class="marketing-mini-link" href="marketing_launch_checklists.php?content_item_id=<?php echo (int) $item['id']; ?>">Launch Checklist</a>
                                            <a class="marketing-mini-link" href="marketing_reviews.php?content_item_id=<?php echo (int) $item['id']; ?>">Review</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <table class="premium-table">
                    <thead>
                    <tr>
                        <?php if ($canWriteMarketing): ?><th><span class="sr-only">Select</span></th><?php endif; ?>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Production</th>
                        <th>Due</th>
                        <th>Scheduled</th>
                        <th>Owner</th>
                        <th>Readiness</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <?php if ($canWriteMarketing): ?><td><input form="content-selection-form" type="checkbox" name="selected_ids[]" value="<?php echo (int) $item['id']; ?>"></td><?php endif; ?>
                            <td>
                                <strong><a href="marketing_content_view.php?id=<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></a></strong>
                                <?php if (!empty($item['campaign_name'])): ?><div class="marketing-table-note">Campaign: <?php echo htmlspecialchars((string) $item['campaign_name']); ?></div><?php endif; ?>
                                <?php if (!empty($item['blocked_reason'])): ?><div class="marketing-table-note is-blocked">Blocked: <?php echo htmlspecialchars((string) $item['blocked_reason']); ?></div><?php endif; ?>
                                <?php if (!empty($item['_media_readiness']['warnings'])): ?><div class="marketing-table-note is-warning">Media warnings: <?php echo htmlspecialchars(implode(', ', array_map($labelize, (array) $item['_media_readiness']['warnings']))); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($labelize((string) $item['content_type'])); ?></td>
                            <td><?php echo htmlspecialchars($labelize((string) $item['channel'])); ?></td>
                            <td><span class="badge badge-default"><?php echo htmlspecialchars($labelize((string) $item['status'])); ?></span></td>
                            <td>
                                <span class="badge badge-default"><?php echo htmlspecialchars($labelize((string) ($item['production_stage'] ?? 'idea'))); ?></span>
                                <?php if (!empty($item['next_action'])): ?><div class="marketing-table-note"><?php echo htmlspecialchars((string) $item['next_action']); ?></div><?php endif; ?>
                                <?php if (($item['dependency_status'] ?? 'clear') !== 'clear'): ?><div class="marketing-table-note is-blocked">Dependencies: <?php echo htmlspecialchars($labelize((string) $item['dependency_status'])); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($item['production_due_at'] ?? 'No production due date')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['scheduled_at'] ?? 'Unscheduled')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['owner_email'] ?? 'Unassigned')); ?></td>
                            <td><?php echo (int) ($item['readiness_score'] ?? 0); ?>% / Prod <?php echo (int) ($item['production_score'] ?? 0); ?>%</td>
                            <td>
                                <div class="marketing-table-actions">
                                    <a class="btn-premium-secondary" href="marketing_content_view.php?id=<?php echo (int) $item['id']; ?>">View</a>
                                    <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_content_edit.php?id=<?php echo (int) $item['id']; ?>">Edit</a><?php endif; ?>
                                    <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_creative.php">AI Visual Help</a><?php endif; ?>
                                    <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_distribution.php?content_item_id=<?php echo (int) $item['id']; ?>">Distribution</a><?php endif; ?>
                                    <?php if ($canManageMarketing): ?>
                                        <form method="POST" onsubmit="return confirm('Delete this marketing content item?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                            <button class="btn-premium-secondary btn-danger-soft" type="submit" title="Manager-only destructive action">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <nav class="marketing-pagination" aria-label="Content Studio pagination">
                <span>Showing <?php echo (int) $showingStart; ?>-<?php echo (int) $showingEnd; ?> on page <?php echo (int) $pageNumber; ?></span>
                <?php if ($pageNumber > 1): ?><a class="btn-premium-secondary" href="<?php echo htmlspecialchars($pageUrl(['page' => $pageNumber - 1, 'per_page' => $perPage])); ?>">Previous</a><?php endif; ?>
                <?php if ($hasMoreItems): ?><a class="btn-premium-secondary" href="<?php echo htmlspecialchars($pageUrl(['page' => $pageNumber + 1, 'per_page' => $perPage])); ?>">Next</a><?php endif; ?>
            </nav>
        </div>
            </div>
        </details>

        <?php if ($canWriteMarketing): ?>
            <form id="content-selection-form" method="POST" action="marketing_content.php" hidden>
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="bulk_update">
            </form>
            <script>
                (function () {
                    var bulkForm = document.querySelector('form.content-card');
                    var selectionForm = document.getElementById('content-selection-form');
                    if (!bulkForm || !selectionForm) {
                        return;
                    }
                    bulkForm.addEventListener('submit', function () {
                        selectionForm.querySelectorAll('input[name="selected_ids[]"]').forEach(function (input) {
                            input.remove();
                        });
                        document.querySelectorAll('input[form="content-selection-form"][name="selected_ids[]"]:checked').forEach(function (input) {
                            var clone = document.createElement('input');
                            clone.type = 'hidden';
                            clone.name = 'selected_ids[]';
                            clone.value = input.value;
                            bulkForm.appendChild(clone);
                        });
                    });
                })();
            </script>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
