<?php
/**
 * Targets List Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Targets;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();
if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$targetsModule = new Targets();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_target') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $basePath . '/targets.php?error=invalid_token');
        exit;
    }

    $targetId = (int) ($_POST['id'] ?? 0);
    if ($targetId <= 0) {
        $targetId = $targetsModule->findTargetIdByLookup([
            'title' => (string) ($_POST['lookup_title'] ?? ''),
            'user_id' => (int) ($_POST['lookup_owner'] ?? 0),
            'target_date' => (string) ($_POST['lookup_date'] ?? ''),
            'description' => (string) ($_POST['lookup_description'] ?? ''),
        ]);
    }
    $target = $targetId > 0 ? $targetsModule->getById($targetId) : null;
    if (!$target) {
        header('Location: ' . $basePath . '/targets.php?error=delete_failed');
        exit;
    }

    if (!$targetsModule->canDeleteTarget($target, $user)) {
        header('Location: ' . $basePath . '/targets.php?error=forbidden');
        exit;
    }

    try {
        $targetsModule->delete($targetId);
        header('Location: ' . $basePath . '/targets.php?deleted=1');
        exit;
    } catch (\Throwable $e) {
        error_log('targets.php delete failed: ' . $e->getMessage());
        header('Location: ' . $basePath . '/targets.php?error=delete_failed');
        exit;
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$targetType = trim((string) ($_GET['target_type'] ?? ''));
$scope = trim((string) ($_GET['scope'] ?? ''));
$progressMode = trim((string) ($_GET['progress_mode'] ?? ''));
$rollupSource = trim((string) ($_GET['rollup_source'] ?? ''));
$statusBand = trim((string) ($_GET['status_band'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$overdue = isset($_GET['overdue']) && $_GET['overdue'] === '1';

$filters = ['user_id' => $userId];
foreach ([
    'status' => $status,
    'target_type' => $targetType,
    'scope' => $scope,
    'progress_mode' => $progressMode,
    'rollup_source' => $rollupSource,
    'status_band' => $statusBand,
    'search' => $search,
] as $key => $value) {
    if ($value !== '') {
        $filters[$key] = $value;
    }
}
if ($overdue) {
    $filters['overdue'] = true;
}

$targets = $targetsModule->getAll($filters, 75, 0);
$summaryTargets = $targetsModule->getAll(['user_id' => $userId], 250, 0);

$stats = [
    'total' => count($summaryTargets),
    'active' => 0,
    'at_risk' => 0,
    'behind' => 0,
    'completed' => 0,
];
$scopeCounts = ['personal' => 0, 'team' => 0, 'company' => 0];
foreach ($summaryTargets as $target) {
    $statusValue = (string) ($target['status'] ?? 'active');
    $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
    $targetScope = (string) ($target['scope'] ?? 'personal');

    if (isset($scopeCounts[$targetScope])) {
        $scopeCounts[$targetScope]++;
    }
    if ($statusValue === 'active') {
        $stats['active']++;
    }
    if ($statusValue === 'completed') {
        $stats['completed']++;
    }
    if (in_array($band, ['at_risk', 'blocked'], true)) {
        $stats['at_risk']++;
    }
    if (in_array($band, ['behind', 'missed'], true)) {
        $stats['behind']++;
    }
}

function targetBandColor(string $band): string
{
    return match ($band) {
        'completed', 'on_track' => '#10b981',
        'at_risk' => '#f59e0b',
        'blocked' => '#8b5cf6',
        'behind', 'missed' => '#ef4444',
        default => '#64748b',
    };
}

function targetScopeBadge(string $scope): string
{
    return ucfirst($scope ?: 'personal');
}

$pageTitle = 'Targets - ' . brandProductName();
$targetsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_TARGETS);
ob_start();
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Targets Intelligence</h1>
                <p>Track personal, team, and company targets with rollups, forecasts, and risk signals.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($targetsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TARGETS, 'Targets page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($basePath); ?>/target_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Target
                </a>
                <a href="<?php echo htmlspecialchars($basePath); ?>/target_dashboard.php" class="btn-premium-secondary">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
            <div style="background:#d1fae5;border:1px solid #10b981;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
                Target deleted successfully.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div style="background:#fee2e2;border:1px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
                <?php echo htmlspecialchars(match ($_GET['error']) {
                    'invalid_token' => 'Invalid request. Please try again.',
                    'forbidden' => 'You do not have permission to delete this target.',
                    default => 'Failed to delete target.',
                }); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">All Targets</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">
                    Personal <?php echo $scopeCounts['personal']; ?> · Team <?php echo $scopeCounts['team']; ?> · Company <?php echo $scopeCounts['company']; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active</div>
                <div class="stat-value" style="color:#2563eb;"><?php echo $stats['active']; ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Targets currently being tracked</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">At Risk</div>
                <div class="stat-value" style="color:#f59e0b;"><?php echo $stats['at_risk']; ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Need action or pacing correction</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed</div>
                <div class="stat-value" style="color:#10b981;"><?php echo $stats['completed']; ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Targets achieved so far</div>
            </div>
        </div>

        <div class="filters-card">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search targets...">
                </div>
                <div class="filter-group">
                    <label for="scope">Scope</label>
                    <select id="scope" name="scope">
                        <option value="">All Scopes</option>
                        <option value="personal" <?php echo $scope === 'personal' ? 'selected' : ''; ?>>Personal</option>
                        <option value="team" <?php echo $scope === 'team' ? 'selected' : ''; ?>>Team</option>
                        <option value="company" <?php echo $scope === 'company' ? 'selected' : ''; ?>>Company</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="progress_mode">Progress Source</label>
                    <select id="progress_mode" name="progress_mode">
                        <option value="">All Modes</option>
                        <option value="manual" <?php echo $progressMode === 'manual' ? 'selected' : ''; ?>>Manual</option>
                        <option value="auto_rollup" <?php echo $progressMode === 'auto_rollup' ? 'selected' : ''; ?>>Auto Rollup</option>
                        <option value="hybrid" <?php echo $progressMode === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="missed" <?php echo $status === 'missed' ? 'selected' : ''; ?>>Missed</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="rollup_source">Rollup Source</label>
                    <select id="rollup_source" name="rollup_source">
                        <option value="">All Sources</option>
                        <option value="deals" <?php echo $rollupSource === 'deals' ? 'selected' : ''; ?>>Deals</option>
                        <option value="invoices" <?php echo $rollupSource === 'invoices' ? 'selected' : ''; ?>>Invoices</option>
                        <option value="tasks" <?php echo $rollupSource === 'tasks' ? 'selected' : ''; ?>>Tasks</option>
                        <option value="contacts" <?php echo $rollupSource === 'contacts' ? 'selected' : ''; ?>>Contacts</option>
                        <option value="communications" <?php echo $rollupSource === 'communications' ? 'selected' : ''; ?>>Communications</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status_band">Health</label>
                    <select id="status_band" name="status_band">
                        <option value="">All Health States</option>
                        <option value="on_track" <?php echo $statusBand === 'on_track' ? 'selected' : ''; ?>>On Track</option>
                        <option value="at_risk" <?php echo $statusBand === 'at_risk' ? 'selected' : ''; ?>>At Risk</option>
                        <option value="behind" <?php echo $statusBand === 'behind' ? 'selected' : ''; ?>>Behind</option>
                        <option value="blocked" <?php echo $statusBand === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                        <option value="completed" <?php echo $statusBand === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="missed" <?php echo $statusBand === 'missed' ? 'selected' : ''; ?>>Missed</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.9rem;color:#475569;">
                        <input type="checkbox" name="overdue" value="1" <?php echo $overdue ? 'checked' : ''; ?>>
                        Overdue only
                    </label>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php" class="btn-premium-secondary">Clear</a>
                </div>
            </form>
        </div>

        <div class="table-card">
            <?php if ($targets === []): ?>
                <div class="empty-state">
                    <p>No targets match the current filters.</p>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/target_create.php">Create a target</a>
                </div>
            <?php else: ?>
                <div style="display:grid;gap:16px;">
                    <?php foreach ($targets as $target): ?>
                        <?php
                        $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
                        $bandColor = targetBandColor($band);
                        $progress = (float) ($target['progress_percentage'] ?? 0);
                        $blockers = array_slice((array) ($target['blockers'] ?? []), 0, 2);
                        $actions = array_slice((array) ($target['next_best_actions'] ?? []), 0, 2);
                        $milestoneSummary = (array) ($target['milestone_summary'] ?? []);
                        $targetRecordId = $targetsModule->resolveTargetId($target);
                        $targetLookupParams = [
                            'id' => $targetRecordId,
                            'lookup_title' => (string) ($target['title'] ?? ''),
                            'lookup_owner' => (int) ($target['user_id'] ?? 0),
                            'lookup_date' => (string) ($target['target_date'] ?? ''),
                            'lookup_description' => (string) ($target['description'] ?? ''),
                        ];
                        $targetViewUrl = $basePath . '/target_view.php?' . http_build_query($targetLookupParams);
                        $targetEditUrl = $basePath . '/target_edit.php?' . http_build_query($targetLookupParams);
                        $canEditTarget = $targetsModule->canEditTarget($target, $user);
                        $canDeleteTarget = $targetsModule->canDeleteTarget($target, $user);
                        $targetMeta = [];
                        if (!empty($target['metadata_json'])) {
                            $decodedTargetMeta = is_array($target['metadata_json']) ? $target['metadata_json'] : json_decode((string) $target['metadata_json'], true);
                            $targetMeta = is_array($decodedTargetMeta) ? $decodedTargetMeta : [];
                        }
                        $isRiversideDemoTarget = (string) ($targetMeta['demo_event_key'] ?? '') === 'target_progress_spotlight'
                            || (string) ($target['source_surface'] ?? '') === 'protected_demo'
                            || stripos((string) ($target['title'] ?? ''), 'qualified lead response') !== false;
                        ?>
                        <article <?php echo $isRiversideDemoTarget ? 'data-demo-riverside-target="1" data-demo-cue-key="targets_page_visible"' : ''; ?> style="border:1px solid var(--border-color);border-radius:16px;padding:20px;background:white;box-shadow:0 14px 36px rgba(15,23,42,0.06);">
                            <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;">
                                <div style="flex:1 1 420px;min-width:280px;">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                        <span class="badge badge-default"><?php echo htmlspecialchars(targetScopeBadge((string) ($target['scope'] ?? 'personal'))); ?></span>
                                        <span class="badge badge-default"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($target['progress_mode'] ?? 'manual')))); ?></span>
                                        <span class="badge" style="background:<?php echo htmlspecialchars($bandColor); ?>;color:white;"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($band))); ?></span>
                                        <span class="badge badge-default"><?php echo htmlspecialchars(ucfirst((string) ($target['target_type'] ?? 'custom'))); ?></span>
                                    </div>
                                    <h2 style="margin:0 0 8px 0;font-size:1.2rem;">
                                        <a href="<?php echo htmlspecialchars($targetViewUrl); ?>" style="text-decoration:none;color:#0f172a;">
                                            <?php echo htmlspecialchars((string) ($target['title'] ?? 'Untitled target')); ?>
                                        </a>
                                    </h2>
                                    <?php if (!empty($target['description'])): ?>
                                        <p style="margin:0 0 14px 0;color:#64748b;max-width:900px;">
                                            <?php echo htmlspecialchars((string) $target['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">
                                        <div style="background:#f8fafc;border-radius:12px;padding:12px;">
                                            <div style="font-size:.78rem;color:#64748b;margin-bottom:4px;">Progress Source</div>
                                            <div style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars((string) ($target['rollup_source_label'] ?? 'Manual progress')); ?></div>
                                        </div>
                                        <div style="background:#f8fafc;border-radius:12px;padding:12px;">
                                            <div style="font-size:.78rem;color:#64748b;margin-bottom:4px;">Forecast</div>
                                            <div style="font-weight:600;color:#0f172a;"><?php echo number_format(((float) ($target['forecast_score'] ?? 0)) * 100, 0); ?>%</div>
                                        </div>
                                        <div style="background:#f8fafc;border-radius:12px;padding:12px;">
                                            <div style="font-size:.78rem;color:#64748b;margin-bottom:4px;">Projected Finish</div>
                                            <div style="font-weight:600;color:#0f172a;"><?php echo !empty($target['projected_completion_date']) ? htmlspecialchars(date('M d, Y', strtotime((string) $target['projected_completion_date']))) : 'Not enough data'; ?></div>
                                        </div>
                                        <div style="background:#f8fafc;border-radius:12px;padding:12px;">
                                            <div style="font-size:.78rem;color:#64748b;margin-bottom:4px;">Milestones</div>
                                            <div style="font-weight:600;color:#0f172a;"><?php echo (int) ($milestoneSummary['completed'] ?? 0); ?> / <?php echo (int) ($milestoneSummary['total'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div style="width:min(340px,100%);flex:0 0 320px;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                        <span style="color:#64748b;font-size:.9rem;">
                                            <?php echo number_format((float) ($target['current_value'] ?? 0), 2); ?><?php echo htmlspecialchars((string) ($target['unit'] ?? '')); ?>
                                            /
                                            <?php echo number_format((float) ($target['target_value'] ?? 0), 2); ?><?php echo htmlspecialchars((string) ($target['unit'] ?? '')); ?>
                                        </span>
                                        <strong style="color:<?php echo htmlspecialchars($bandColor); ?>;"><?php echo number_format($progress, 1); ?>%</strong>
                                    </div>
                                    <div style="width:100%;height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-bottom:10px;">
                                        <div style="width:<?php echo min(100, $progress); ?>%;height:100%;background:<?php echo htmlspecialchars($bandColor); ?>;"></div>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:#64748b;font-size:.88rem;margin-bottom:14px;">
                                        <span>Target date: <?php echo !empty($target['target_date']) ? htmlspecialchars(date('M d, Y', strtotime((string) $target['target_date']))) : '-'; ?></span>
                                        <span><?php echo isset($target['days_remaining']) && $target['days_remaining'] !== null ? ((int) $target['days_remaining'] >= 0 ? (int) $target['days_remaining'] . ' days left' : 'Overdue') : '-'; ?></span>
                                    </div>
                                    <div style="background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:12px;">
                                        <div style="font-size:.78rem;color:#64748b;margin-bottom:6px;">Pace Summary</div>
                                        <div style="color:#334155;"><?php echo htmlspecialchars((string) ($target['pace_summary'] ?? 'No pace summary available.')); ?></div>
                                    </div>
                                    <?php if ($blockers !== []): ?>
                                        <div style="margin-bottom:10px;">
                                            <div style="font-size:.78rem;color:#991b1b;margin-bottom:6px;">Why this target is behind</div>
                                            <ul style="margin:0;padding-left:18px;color:#475569;">
                                                <?php foreach ($blockers as $blocker): ?>
                                                    <li><?php echo htmlspecialchars((string) $blocker); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($actions !== []): ?>
                                        <div>
                                            <div style="font-size:.78rem;color:#0f766e;margin-bottom:6px;">Next actions</div>
                                            <ul style="margin:0;padding-left:18px;color:#475569;">
                                                <?php foreach ($actions as $action): ?>
                                                    <li><?php echo htmlspecialchars((string) $action); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color);">
                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                    <a href="<?php echo htmlspecialchars($targetViewUrl); ?>" class="btn-premium-primary" style="min-width:auto;padding:10px 14px;">Open</a>
                                    <?php if ($canEditTarget): ?>
                                        <a href="<?php echo htmlspecialchars($targetEditUrl); ?>" class="btn-premium-secondary" style="min-width:auto;padding:10px 14px;">Edit</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canDeleteTarget): ?>
                                    <form method="POST" action="<?php echo htmlspecialchars($basePath); ?>/targets.php" onsubmit="return confirm('Delete this target permanently?');" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="action" value="delete_target">
                                        <input type="hidden" name="id" value="<?php echo $targetRecordId; ?>">
                                        <input type="hidden" name="lookup_title" value="<?php echo htmlspecialchars((string) ($target['title'] ?? '')); ?>">
                                        <input type="hidden" name="lookup_owner" value="<?php echo (int) ($target['user_id'] ?? 0); ?>">
                                        <input type="hidden" name="lookup_date" value="<?php echo htmlspecialchars((string) ($target['target_date'] ?? '')); ?>">
                                        <input type="hidden" name="lookup_description" value="<?php echo htmlspecialchars((string) ($target['description'] ?? '')); ?>">
                                        <button type="submit" style="appearance:none;border:none;background:none;color:#ef4444;font-weight:600;cursor:pointer;">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TARGETS, 'How to use Targets', $targetsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
