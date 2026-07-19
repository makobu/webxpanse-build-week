<?php
/**
 * Tasks List Page
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
use CRM\Security;
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\AITaskAutomationService;
use CRM\Modules\Tasks;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceTaskAutomationSettingsService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

Authorization::requirePermission('tasks.read');

$tasksModule = new Tasks();
$assignmentAccess = new TaskAssignmentAccessService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$canWriteTasks = $assignmentAccess->canWriteTasks($user);
$canManageTaskAutomation = Authorization::can('settings.general', $user);
$taskAutomationSettingsService = new WorkspaceTaskAutomationSettingsService();
$taskAutomationNotice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_task_automation_settings') {
    if (!$canManageTaskAutomation || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Unable to update task automation settings.');
    }
    $taskAutomationSettingsService->save($workspaceId, [
        'rollout_mode' => (string) ($_POST['rollout_mode'] ?? 'review'),
        'allow_user_task_opt_in' => isset($_POST['allow_user_task_opt_in']),
        'min_confidence' => (float) ($_POST['min_confidence'] ?? 0.96),
        'low_risk_only' => true,
    ], $userId);
    $taskAutomationNotice = 'Task automation settings saved.';
}
Session::closeWrite();
$taskAutomationSettings = $taskAutomationSettingsService->get($workspaceId);
$isProtectedDemoTasks = false;
try {
    $isProtectedDemoTasks = $workspaceId > 0 && (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoTasks = false;
}
$demoSyntheticEmailPattern = '/^(?:demo|visitor)-.+@demo\.local\.invalid$/i';
$demoTaskDisplayLabel = static function (?string $email, string $fallback, string $role = 'assignee') use ($isProtectedDemoTasks, $demoSyntheticEmailPattern): string {
    $email = trim((string) $email);
    if ($isProtectedDemoTasks && $email !== '' && preg_match($demoSyntheticEmailPattern, $email)) {
        return $role === 'creator' ? 'Clarity demo automation' : 'You (demo owner)';
    }

    return $email !== '' ? $email : $fallback;
};

// Get filters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$search = $_GET['search'] ?? '';
$assignedTo = $_GET['assigned_to'] ?? '';
$assignedToDefaulted = false;
$assignedToParamProvided = array_key_exists('assigned_to', $_GET);
$assignedToValue = is_scalar($assignedTo) ? trim((string) $assignedTo) : '';
if (!$assignedToParamProvided && $userId > 0) {
    $assignedTo = (string) $userId;
    $assignedToValue = (string) $userId;
    $assignedToDefaulted = true;
}
if ($assignedToParamProvided && $assignedToValue === '' && $userId > 0 && !Authorization::can('tasks.view_all', $user)) {
    $assignedTo = (string) $userId;
    $assignedToValue = (string) $userId;
    $assignedToDefaulted = true;
}
if ($assignedToValue !== '' && (int) $assignedToValue !== $userId && !Authorization::can('tasks.view_all', $user)) {
    $assignedTo = (string) $userId;
    $assignedToValue = (string) $userId;
    $assignedToDefaulted = true;
}
$overdue = isset($_GET['overdue']) ? true : false;

$filters = [];
if ($status) $filters['status'] = $status;
if ($priority) $filters['priority'] = $priority;
if ($search) $filters['search'] = $search;
if ($assignedToValue !== '') $filters['assigned_to'] = $assignedToValue;
if ($overdue) $filters['overdue'] = true;
if (!$status) $filters['active_only'] = true;

// Get tasks
$tasks = $tasksModule->getAll($filters, 50, 0);

// Get users for filter
$users = $assignmentAccess->getManualAssignableUsers();

$statusFilterIsClosed = in_array($status, ['completed', 'cancelled'], true);
$taskGroups = [
    'overdue' => [
        'label' => 'Overdue',
        'hint' => 'Past due and still open',
        'items' => [],
    ],
    'due_soon' => [
        'label' => 'Due soon',
        'hint' => 'Dated work to move next',
        'items' => [],
    ],
    'no_due_date' => [
        'label' => 'No due date',
        'hint' => 'Needs timing before it can be ranked',
        'items' => [],
    ],
    'completed' => [
        'label' => 'Completed',
        'hint' => 'Done work matching this filter',
        'items' => [],
    ],
    'cancelled' => [
        'label' => 'Cancelled',
        'hint' => 'Removed from active work',
        'items' => [],
    ],
];

foreach ($tasks as $task) {
    $taskStatus = (string) ($task['status'] ?? 'pending');
    $isClosedTask = in_array($taskStatus, ['completed', 'cancelled'], true);
    if ($isClosedTask && !$statusFilterIsClosed) {
        continue;
    }

    if ($isClosedTask) {
        $taskGroups[$taskStatus === 'cancelled' ? 'cancelled' : 'completed']['items'][] = $task;
        continue;
    }

    $dueAt = !empty($task['due_date']) ? strtotime((string) $task['due_date']) : false;
    if ($dueAt !== false && $dueAt < time()) {
        $taskGroups['overdue']['items'][] = $task;
    } elseif ($dueAt !== false) {
        $taskGroups['due_soon']['items'][] = $task;
    } else {
        $taskGroups['no_due_date']['items'][] = $task;
    }
}

$visibleTaskCount = array_sum(array_map(static fn(array $group): int => count($group['items']), $taskGroups));
$activeFilterLabels = [];
if ($search !== '') $activeFilterLabels[] = 'Search: ' . $search;
if ($status !== '') $activeFilterLabels[] = 'Status: ' . ucwords(str_replace('_', ' ', $status));
if ($priority !== '') $activeFilterLabels[] = 'Priority: ' . ucfirst($priority);
if ($assignedToValue !== '') {
    $activeFilterLabels[] = ((int) $assignedToValue === $userId) ? 'Mine' : 'Assigned user';
}
if ($overdue) $activeFilterLabels[] = 'Overdue';

$pageTitle = 'Tasks - ' . brandProductName();
$tasksGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_TASKS);
$tasksExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $tasksExperienceMode,
    'surface' => 'tasks',
    'current_page' => 'tasks.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'task_groups' => $taskGroups,
    'tasks' => $tasks,
    'total_count' => $visibleTaskCount,
    'can_write_tasks' => $canWriteTasks,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/tasks-ui.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container tasks-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Tasks</h1>
                <p>Own the next commitments, due dates, and Clarity-suggested follow-through.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($tasksGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TASKS, 'Tasks page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <?php if ($canWriteTasks): ?>
                    <a href="task_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        New Task
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>
        <?php if ($canManageTaskAutomation): ?>
            <details class="task-automation-settings" style="margin:0 0 1rem;padding:0.9rem 1rem;border:1px solid #dbe4f0;border-radius:12px;background:#fff;">
                <summary style="cursor:pointer;font-weight:700;">Clarity task completion rollout: <?php echo htmlspecialchars(str_replace('_', ' ', (string) $taskAutomationSettings['rollout_mode'])); ?></summary>
                <p style="margin:0.65rem 0;color:#64748b;">Review mode records recommendations without changing task status. Promote to full auto only after reviewed results meet your workspace quality targets.</p>
                <?php if ($taskAutomationNotice !== ''): ?><div class="alert alert-success" style="margin-bottom:0.75rem;"><?php echo htmlspecialchars($taskAutomationNotice); ?></div><?php endif; ?>
                <form method="POST" action="" style="display:flex;flex-wrap:wrap;align-items:end;gap:0.75rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="save_task_automation_settings">
                    <label style="display:grid;gap:0.25rem;"><span>Workspace mode</span><select name="rollout_mode"><option value="review" <?php echo $taskAutomationSettings['rollout_mode'] === 'review' ? 'selected' : ''; ?>>Review recommendations</option><option value="full_auto" <?php echo $taskAutomationSettings['rollout_mode'] === 'full_auto' ? 'selected' : ''; ?>>Full auto for eligible tasks</option></select></label>
                    <label style="display:grid;gap:0.25rem;"><span>Minimum confidence</span><input type="number" name="min_confidence" min="0.96" max="0.9999" step="0.0001" value="<?php echo htmlspecialchars(number_format((float) $taskAutomationSettings['min_confidence'], 4, '.', '')); ?>"></label>
                    <label style="display:flex;align-items:center;gap:0.4rem;padding-bottom:0.5rem;"><input type="checkbox" name="allow_user_task_opt_in" value="1" <?php echo !empty($taskAutomationSettings['allow_user_task_opt_in']) ? 'checked' : ''; ?>>Allow simple user tasks to opt in</label>
                    <button type="submit" class="btn-premium-secondary">Save rollout settings</button>
                </form>
            </details>
        <?php endif; ?>
        <div class="tasks-toolbar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search tasks..."
                    >
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">Open tasks</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="">All Priorities</option>
                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="assigned_to">Assigned To</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">All workspace members</option>
                        <?php foreach ($users as $userOption): ?>
                            <option value="<?php echo $userOption['id']; ?>" <?php echo $assignedToValue === (string) $userOption['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($userOption['email']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary btn-premium-sm">
                        <i class="fas fa-filter" aria-hidden="true"></i>
                        Filter
                    </button>
                    <a href="tasks.php" class="btn-premium-secondary btn-premium-sm">Clear</a>
                </div>
            </form>
            <?php if ($assignedToDefaulted): ?>
                <div class="tasks-filter-note" style="margin-top:0.65rem;">
                    Showing tasks assigned to you by default.
                </div>
            <?php endif; ?>
        </div>

        <div class="tasks-workbench">
            <div class="tasks-workbench-header">
                <div>
                    <h2>Task queue</h2>
                    <p><?php echo $visibleTaskCount; ?> task<?php echo $visibleTaskCount === 1 ? '' : 's'; ?> in this view</p>
                </div>
                <?php if (!empty($activeFilterLabels)): ?>
                    <div class="tasks-active-filters" aria-label="Active filters">
                        <?php foreach ($activeFilterLabels as $filterLabel): ?>
                            <span class="tasks-filter-chip"><?php echo htmlspecialchars($filterLabel); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isProtectedDemoTasks): ?>
                <section class="protected-demo-task-scene" data-protected-demo-task-scene hidden aria-live="polite">
                    <div class="protected-demo-task-scene__header">
                        <span>Guided Autopilot</span>
                        <strong>Riverside follow-up tasks are being queued</strong>
                    </div>
                    <div class="protected-demo-task-scene__list" data-protected-demo-task-scene-list></div>
                </section>
            <?php endif; ?>

            <?php if ($visibleTaskCount <= 0): ?>
                <div class="task-empty empty-state">
                    <p>No tasks found.</p>
                    <p style="color:#64748b;">Create a manual onboarding task or follow-up step to keep the next commitment visible.</p>
                    <?php if ($canWriteTasks): ?>
                        <a href="task_create.php">Create your first task &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($taskGroups as $groupKey => $group): ?>
                    <?php if (empty($group['items'])) continue; ?>
                    <section class="task-group task-group--<?php echo htmlspecialchars($groupKey); ?>">
                        <div class="task-group-heading">
                            <div class="task-group-title">
                                <?php if ($groupKey === 'overdue'): ?><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?php endif; ?>
                                <?php if ($groupKey === 'due_soon'): ?><i class="fas fa-calendar-day" aria-hidden="true"></i><?php endif; ?>
                                <?php if ($groupKey === 'no_due_date'): ?><i class="fas fa-clock" aria-hidden="true"></i><?php endif; ?>
                                <?php if ($groupKey === 'completed'): ?><i class="fas fa-check-circle" aria-hidden="true"></i><?php endif; ?>
                                <?php if ($groupKey === 'cancelled'): ?><i class="fas fa-ban" aria-hidden="true"></i><?php endif; ?>
                                <?php echo htmlspecialchars((string) $group['label']); ?>
                                <span class="badge badge-default"><?php echo count($group['items']); ?></span>
                            </div>
                            <div class="task-group-hint"><?php echo htmlspecialchars((string) $group['hint']); ?></div>
                        </div>
                        <div class="task-list">
                            <?php foreach ($group['items'] as $task): ?>
                                <?php
                                    $taskMeta = !empty($task['metadata_json']) ? (json_decode((string) $task['metadata_json'], true) ?: []) : [];
                                    $isStarterTask = (string) ($taskMeta['source_surface'] ?? '') === 'ai_coach'
                                        || (string) ($taskMeta['source_recommendation_type'] ?? '') === 'coach';
                                    $isAITask = AITaskAutomationService::isAIAutoTask($task);
                                    $isOverdue = !empty($task['due_date'])
                                        && strtotime((string) $task['due_date']) < time()
                                        && !in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true);
                                    $description = trim(preg_replace('/\s+/', ' ', (string) ($task['description'] ?? '')) ?? '');
                                    if ($description !== '') {
                                        $description = preg_replace('/^\s*(?:\[[^\]]+\]\s*)+/i', '', $description) ?? $description;
                                        $description = preg_replace('/^Source:\s*AI Coach\s*/i', '', $description) ?? $description;
                                        $description = preg_replace('/^Reason:\s*/i', '', $description) ?? $description;
                                        $description = trim($description);
                                    }
                                    if (strlen($description) > 125) {
                                        $description = substr($description, 0, 122) . '...';
                                    }
                                    $contactName = trim(((string) ($task['contact_first_name'] ?? '')) . ' ' . ((string) ($task['contact_last_name'] ?? '')));
                                    $taskIsProtectedDemoGenerated = $isProtectedDemoTasks && (
                                        (string) ($taskMeta['source'] ?? '') === 'protected_demo_experience'
                                        || (string) ($taskMeta['demo_event_key'] ?? '') === 'follow_up_task_spotlight'
                                        || (string) ($task['source_surface'] ?? '') === 'protected_demo'
                                    );
                                    $assigneeDisplayLabel = $demoTaskDisplayLabel(
                                        (string) ($task['assigned_to_email'] ?? ''),
                                        'Unassigned',
                                        'assignee'
                                    );
                                    $demoContactFallbackLabel = $taskIsProtectedDemoGenerated
                                        ? (string) ($taskMeta['demo_contact_label'] ?? 'Amina Otieno / Riverside Residence')
                                        : '';
                                    $dueLabel = !empty($task['due_date']) ? date('M d, Y', strtotime((string) $task['due_date'])) : 'No due date';
                                    $taskStatus = (string) ($task['status'] ?? 'pending');
                                    $taskPriority = (string) ($task['priority'] ?? 'medium');
                                    $sourceLabel = $isStarterTask ? 'Clarity' : ($isAITask ? 'AI' : '');
                                    $statusLabel = ucwords(str_replace('_', ' ', $taskStatus));
                                    $showStatusMeta = $statusFilterIsClosed || !in_array($taskStatus, ['pending'], true);
                                ?>
                                <article class="task-row <?php echo $isOverdue ? 'task-row--overdue' : ''; ?>" <?php echo $taskIsProtectedDemoGenerated ? 'data-demo-riverside-task="1" data-demo-cue-key="tasks_page_visible"' : ''; ?>>
                                    <div class="task-main">
                                        <div class="task-title-line">
                                            <a href="task_view.php?id=<?php echo (int) $task['id']; ?>">
                                                <?php echo htmlspecialchars((string) $task['title']); ?>
                                            </a>
                                        </div>
                                        <?php if ($description !== ''): ?>
                                            <div class="task-description"><?php echo htmlspecialchars($description); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="task-meta-grid">
                                        <span class="task-meta-pill">
                                            <i class="far fa-calendar" aria-hidden="true"></i>
                                            <span style="<?php echo $isOverdue ? 'color:#dc2626;font-weight:700;' : ''; ?>"><?php echo htmlspecialchars($dueLabel); ?></span>
                                        </span>
                                        <span class="task-meta-pill">
                                            <i class="far fa-user" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars($assigneeDisplayLabel); ?>
                                        </span>
                                        <span class="task-meta-pill">
                                            <i class="far fa-address-card" aria-hidden="true"></i>
                                            <?php if (!empty($task['contact_id'])): ?>
                                                <a href="contact_view.php?id=<?php echo (int) $task['contact_id']; ?>">
                                                    <?php echo htmlspecialchars($contactName !== '' ? $contactName : 'Contact'); ?>
                                                </a>
                                            <?php elseif ($demoContactFallbackLabel !== ''): ?>
                                                <a href="contacts.php?search=Amina">
                                                    <?php echo htmlspecialchars($demoContactFallbackLabel); ?>
                                                </a>
                                            <?php else: ?>
                                                No contact
                                            <?php endif; ?>
                                        </span>
                                        <span class="task-meta-pill task-meta-subtle">
                                            <?php if ($sourceLabel !== ''): ?>
                                                <span><?php echo htmlspecialchars($sourceLabel); ?></span>
                                                <span aria-hidden="true">/</span>
                                            <?php endif; ?>
                                            <span class="task-priority task-priority--<?php echo htmlspecialchars($taskPriority); ?>">
                                                <?php echo htmlspecialchars(ucfirst($taskPriority)); ?>
                                            </span>
                                            <?php if ($showStatusMeta): ?>
                                                <span aria-hidden="true">/</span>
                                                <span><?php echo htmlspecialchars($statusLabel); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($taskMeta['completed_by_evidence'])): ?>
                                                <span aria-hidden="true">/</span>
                                                <span>Evidence</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="task-actions">
                                        <a class="task-action-link" href="task_view.php?id=<?php echo (int) $task['id']; ?>">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                            View
                                        </a>
                                        <?php if ($canWriteTasks): ?>
                                            <a class="task-action-link" href="task_edit.php?id=<?php echo (int) $task['id']; ?>">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                                Edit
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TASKS, 'How to use Tasks', $tasksGuideVideoUrl); ?>

<?php if ($isProtectedDemoTasks): ?>
<script>
(function() {
    function escapeTaskScene(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    window.addEventListener('protected-demo:scene', function(event) {
        var detail = event.detail || {};
        var payload = detail.payload || {};
        var sceneKey = String(detail.scene_key || payload.scene_key || payload.demo_event_key || '');
        if (!['tasks_sequence_started', 'riverside_task_opened'].includes(sceneKey)) return;

        var scene = document.querySelector('[data-protected-demo-task-scene]');
        var list = document.querySelector('[data-protected-demo-task-scene-list]');
        if (scene && list && sceneKey === 'tasks_sequence_started') {
            var tasks = ((payload.animation_payload || {}).tasks || []);
            scene.hidden = false;
            scene.classList.add('is-visible');
            list.innerHTML = tasks.map(function(task, index) {
                return '<article class="protected-demo-task-scene__item" style="animation-delay:' + (index * 260) + 'ms">' +
                    '<span>' + escapeTaskScene(task.priority || 'medium') + '</span>' +
                    '<strong>' + escapeTaskScene(task.title || 'Riverside follow-up') + '</strong>' +
                    '</article>';
            }).join('');
        }

        document.querySelectorAll('[data-demo-riverside-task="1"]').forEach(function(row, index) {
            row.classList.add('protected-demo-scene-new');
            window.setTimeout(function() {
                row.classList.remove('protected-demo-scene-new');
            }, 2800 + (index * 180));
        });
    });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
