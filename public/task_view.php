<?php
/**
 * Task View Page
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
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\Tasks;
use CRM\Modules\Notes;
use CRM\Modules\Documents;
use CRM\Modules\AITaskAutomationService;
use CRM\Services\AITaskCompletionService;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\TaskCompletionReviewService;
use CRM\Services\TaskCompletionCoordinator;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: ' . Auth::loginUrl(null, Auth::currentAuthState() === 'expired'));
    exit;
}

Authorization::requirePermission('tasks.read');

$tasksModule = new Tasks();
$notesModule = new Notes();
$documentsModule = new Documents();
$assignmentAccess = new TaskAssignmentAccessService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$canWriteTasks = $assignmentAccess->canWriteTasks($user);
$canReassignTasks = $assignmentAccess->canReassignTasks($user);
$canManageAllNotes = Authorization::can('notes.manage_all', $user);
$canManageAllDocuments = Authorization::can('documents.manage_all', $user);
$taskId = (int) ($_GET['id'] ?? 0);
$isProtectedDemoTaskView = false;
$isPresentationTaskView = false;
try {
    $isPresentationTaskView = $workspaceId > 0
        && (new PresentationWorkspaceGuardService())->isPresentationWorkspace($workspaceId);
    $isProtectedDemoTaskView = $workspaceId > 0 && (
        (new DemoSessionScopeService())->activeSession($workspaceId) !== null
        || $isPresentationTaskView
    );
} catch (\Throwable $e) {
    $isProtectedDemoTaskView = false;
    $isPresentationTaskView = false;
}
if ($isPresentationTaskView) {
    $canReassignTasks = false;
}
$demoSyntheticEmailPattern = '/^(?:demo|visitor|presentation)-.+@demo\.local\.invalid$/i';
$demoTaskDisplayLabel = static function (?string $email, string $fallback, string $role = 'assignee') use ($isProtectedDemoTaskView, $demoSyntheticEmailPattern): string {
    $email = trim((string) $email);
    if ($isProtectedDemoTaskView && $email !== '' && preg_match($demoSyntheticEmailPattern, $email)) {
        return $role === 'creator' ? 'Clarity demo automation' : 'You (demo owner)';
    }

    return $email !== '' ? $email : $fallback;
};

if (!$taskId) {
    header('Location: tasks.php');
    exit;
}

$task = $tasksModule->getById($taskId);

if (!$task) {
    header('Location: tasks.php');
    exit;
}
if (
    !Authorization::can('tasks.view_all', $user)
    && !in_array($userId, [(int) ($task['assigned_to'] ?? 0), (int) ($task['created_by'] ?? 0)], true)
) {
    http_response_code(403);
    die('Access denied: You do not have permission to view this task.');
}

$subtasks = $tasksModule->getSubtasks($taskId);
$taskMeta = !empty($task['metadata_json']) ? (json_decode((string) $task['metadata_json'], true) ?: []) : [];
$completionReview = is_array($taskMeta['completion_review'] ?? null) ? $taskMeta['completion_review'] : [];
$completionGuidance = AITaskAutomationService::resolveCompletionGuidance($task, $subtasks);
$subtaskHintMap = [];
foreach ((array) ($completionGuidance['subtask_hints'] ?? []) as $hint) {
    $subtaskHintMap[trim((string) ($hint['title'] ?? ''))] = (string) ($hint['detail'] ?? '');
}
$taskEvidence = [];
$taskActionError = null;
$taskActionSuccess = null;
try {
    $taskEvidence = Database::query("SELECT * FROM ai_task_evidence WHERE task_id = ? ORDER BY created_at DESC", [$taskId]);
} catch (\Throwable $e) {
    $taskEvidence = [];
}

$isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && !in_array($task['status'], ['completed', 'cancelled']);

// Get task notes
$taskNotes = $notesModule->getEntityNotes('task', $taskId, false, $userId);

// Get task documents
$taskDocuments = $documentsModule->getEntityDocuments('task', $taskId);
$reviewService = new TaskCompletionReviewService();
$completionCoordinator = new TaskCompletionCoordinator($tasksModule);
$reviewRequired = $reviewService->requiresReview($task);
$completionV2 = $completionCoordinator->completionPayload($task, $userId);
$manualAssignableUsers = $assignmentAccess->getManualAssignableUsers();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['mark_complete', 'review_completion', 'complete_from_review', 'dismiss_completion_review', 'reopen_task', 'scan_completion', 'set_completion_mode'], true)) {
    if (!$canWriteTasks) {
        $taskActionError = 'You do not have permission to modify tasks.';
    } elseif (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            if ($_POST['action'] === 'mark_complete') {
                if ($reviewRequired) {
                    $completionReview = $reviewService->evaluateCompletion($task, $userId);
                    $reviewService->persistReview($taskId, $completionReview, $userId);
                    $taskActionSuccess = 'Completion evidence reviewed. Decide whether to mark this task done.';
                } else {
                    $tasksModule->update($taskId, ['status' => 'completed', 'actor_user_id' => $userId]);
                    $taskActionSuccess = 'Task marked as completed.';
                }
            } elseif ($_POST['action'] === 'review_completion') {
                $completionReview = $reviewService->evaluateCompletion($task, $userId);
                $reviewService->persistReview($taskId, $completionReview, $userId);
                $taskActionSuccess = 'Completion evidence reviewed. Decide whether to mark this task done.';
            } elseif ($_POST['action'] === 'complete_from_review') {
                $decision = (string) ($_POST['decision'] ?? 'complete_anyway');
                $overrideReason = (string) ($_POST['override_reason'] ?? '');
                $result = $reviewService->completeFromReview($taskId, $userId, $decision, $overrideReason);
                if (!empty($result['success'])) {
                    $taskActionSuccess = ($decision === 'complete_recommended')
                        ? 'Task marked as completed using reviewed evidence.'
                        : 'Task marked as completed with override.';
                } else {
                    $taskActionError = (string) ($result['error'] ?? 'Unable to complete this task.');
                }
            } elseif ($_POST['action'] === 'dismiss_completion_review') {
                $reviewService->clearReview($taskId);
                $completionReview = [];
                $taskActionSuccess = 'Task kept open. The completion review was cleared.';
            } elseif ($_POST['action'] === 'reopen_task') {
                $tasksModule->update($taskId, ['status' => 'in_progress', 'actor_user_id' => $userId]);
                $taskActionSuccess = 'Task reopened.';
            } elseif ($_POST['action'] === 'set_completion_mode') {
                $completionCoordinator->setMode($taskId, (string) ($_POST['completion_mode'] ?? 'manual'), $userId);
                $taskActionSuccess = 'Clarity completion preference updated.';
            } else {
                $results = (new AITaskCompletionService())->scanForCompletionEvidence($userId, $taskId, [
                    'actor_user_id' => $userId,
                    'enforce_task_access' => true,
                    'workspace_id' => $workspaceId,
                ]);
                $match = $results[0] ?? null;
                if ($match && !empty($match['completed'])) {
                    $taskActionSuccess = 'Task was completed from detected evidence.';
                } elseif ($match) {
                    $taskActionError = 'Evidence was found, but the task could not be updated.';
                } else {
                    $taskActionError = 'No completion evidence was found for this task.';
                }
            }

            $task = $tasksModule->getById($taskId);
            $subtasks = $tasksModule->getSubtasks($taskId);
            $taskMeta = !empty($task['metadata_json']) ? (json_decode((string) $task['metadata_json'], true) ?: []) : [];
            $completionReview = is_array($taskMeta['completion_review'] ?? null) ? $taskMeta['completion_review'] : [];
            $completionGuidance = AITaskAutomationService::resolveCompletionGuidance($task, $subtasks);
            $subtaskHintMap = [];
            foreach ((array) ($completionGuidance['subtask_hints'] ?? []) as $hint) {
                $subtaskHintMap[trim((string) ($hint['title'] ?? ''))] = (string) ($hint['detail'] ?? '');
            }
            $reviewRequired = $reviewService->requiresReview($task);
            $completionV2 = $completionCoordinator->completionPayload($task, $userId);
            try {
                $taskEvidence = Database::query("SELECT * FROM ai_task_evidence WHERE task_id = ? ORDER BY created_at DESC", [$taskId]);
            } catch (\Throwable $e) {
                $taskEvidence = [];
            }
            $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && !in_array($task['status'], ['completed', 'cancelled']);
        } catch (\Throwable $e) {
            $taskActionError = $e->getMessage();
        }
    } else {
        $taskActionError = 'Invalid security token. Please refresh and try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign_task') {
    if (!$canReassignTasks) {
        $taskActionError = 'You do not have permission to reassign tasks.';
    } elseif (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $taskActionError = 'Invalid security token. Please refresh and try again.';
    } else {
        try {
            $tasksModule->update($taskId, [
                'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
                'actor_user_id' => $userId,
            ]);
            $task = $tasksModule->getById($taskId);
            $taskMeta = !empty($task['metadata_json']) ? (json_decode((string) $task['metadata_json'], true) ?: []) : [];
            $taskActionSuccess = 'Task assignee updated.';
        } catch (\Throwable $e) {
            $taskActionError = $e->getMessage();
        }
    }
}

// Handle note creation
$noteError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $notesModule->create([
                'entity_type' => 'task',
                'entity_id' => $taskId,
                'title' => $_POST['note_title'] ?? null,
                'content' => $_POST['note_content'] ?? '',
                'content_html' => $_POST['note_content_html'] ?? null,
                'is_private' => isset($_POST['note_private']) ? 1 : 0,
                'created_by' => $userId
            ]);
            $taskNotes = $notesModule->getEntityNotes('task', $taskId, false, $userId);
        } catch (\Exception $e) {
            $noteError = $e->getMessage();
        }
    }
}

// Handle note deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $note = $notesModule->getById($noteId);
        if ($note && ($note['created_by'] == $userId || $canManageAllNotes)) {
            $notesModule->delete($noteId);
            $taskNotes = $notesModule->getEntityNotes('task', $taskId, false, $userId);
        }
    }
}

$priorityColors = [
    'urgent' => '#c33',
    'high' => '#f90',
    'medium' => '#3c3',
    'low' => '#999'
];

$statusColors = [
    'pending' => '#999',
    'in_progress' => '#3c3',
    'completed' => '#3c3',
    'cancelled' => '#c33'
];

$taskStatus = (string) ($task['status'] ?? 'pending');
$taskPriority = (string) ($task['priority'] ?? 'medium');
$contactName = trim(((string) ($task['contact_first_name'] ?? '')) . ' ' . ((string) ($task['contact_last_name'] ?? '')));
$dueLabel = !empty($task['due_date']) ? date('M d, Y g:i A', strtotime((string) $task['due_date'])) : 'No due date';
$taskIsProtectedDemoGenerated = $isProtectedDemoTaskView && (
    (string) ($taskMeta['source'] ?? '') === 'protected_demo_experience'
    || (string) ($taskMeta['demo_event_key'] ?? '') === 'follow_up_task_spotlight'
    || (string) ($task['source_surface'] ?? '') === 'protected_demo'
);
$assigneeLabel = $taskIsProtectedDemoGenerated
    ? (string) ($taskMeta['demo_assignee_label'] ?? 'You (demo owner)')
    : $demoTaskDisplayLabel((string) ($task['assigned_to_email'] ?? ''), 'Unassigned', 'assignee');
$createdByLabel = $taskIsProtectedDemoGenerated
    ? (string) ($taskMeta['demo_created_by_label'] ?? 'Clarity demo automation')
    : $demoTaskDisplayLabel((string) ($task['created_by_email'] ?? ''), 'Unknown', 'creator');
$demoContactFallbackLabel = $taskIsProtectedDemoGenerated
    ? (string) ($taskMeta['demo_contact_label'] ?? 'Amina Otieno / Riverside Residence')
    : '';
$isAiTask = AITaskAutomationService::isAIAutoTask($task);
$isStarterTask = (string) ($taskMeta['source_surface'] ?? '') === 'ai_coach'
    || (string) ($taskMeta['source_recommendation_type'] ?? '') === 'coach';
$sourceLabel = $isStarterTask ? 'Clarity suggested' : ($isAiTask ? 'AI-created' : 'Manual');
$completedCount = 0;
foreach ($subtasks as $st) {
    if (!empty($st['completed'])) {
        $completedCount++;
    }
}
$subtaskTotal = count($subtasks);
$statusTone = [
    'pending' => 'task-pill-muted',
    'in_progress' => 'task-pill-primary',
    'completed' => 'task-pill-success',
    'cancelled' => 'task-pill-danger',
];
$priorityTone = [
    'urgent' => 'task-pill-danger',
    'high' => 'task-pill-warning',
    'medium' => 'task-pill-success',
    'low' => 'task-pill-muted',
];

$pageTitle = 'Task Details - ' . brandProductName();
$taskDetailExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $taskDetailExperienceMode,
    'surface' => 'task',
    'current_page' => 'task_view.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'task' => $task,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/tasks-ui.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">

<div class="page-premium task-page">
    <div class="container tasks-page">
        <div class="task-page-header">
            <div>
                <div class="task-page-kicker">Task</div>
                <h1>Task Details</h1>
                <p>Review the next action, evidence, notes, and files.</p>
            </div>
            <div class="task-page-actions">
                <?php if ($canWriteTasks): ?>
                    <a href="task_edit.php?id=<?php echo $taskId; ?>" class="task-btn task-btn-primary"><i class="fas fa-pen" aria-hidden="true"></i>Edit</a>
                <?php endif; ?>
                <a href="tasks.php" class="task-btn task-btn-secondary"><i class="fas fa-arrow-left" aria-hidden="true"></i>Tasks</a>
            </div>
        </div>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="task-alert task-alert-success">Task <?php echo $_GET['success'] === 'created' ? 'created' : 'updated'; ?> successfully.</div>
        <?php endif; ?>
        <?php if ($taskActionSuccess): ?><div class="task-alert task-alert-success"><?php echo htmlspecialchars($taskActionSuccess); ?></div><?php endif; ?>
        <?php if ($taskActionError): ?><div class="task-alert task-alert-error"><?php echo htmlspecialchars($taskActionError); ?></div><?php endif; ?>

        <section class="task-detail-hero <?php echo $isOverdue ? 'task-row--overdue' : ''; ?>">
            <div class="task-hero-main">
                <h2 class="task-hero-title"><?php echo htmlspecialchars((string) $task['title']); ?></h2>
                <div class="task-hero-meta">
                    <span class="task-pill <?php echo $statusTone[$taskStatus] ?? 'task-pill-muted'; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $taskStatus))); ?></span>
                    <span class="task-pill <?php echo $priorityTone[$taskPriority] ?? 'task-pill-muted'; ?>"><?php echo htmlspecialchars(ucfirst($taskPriority)); ?> priority</span>
                    <span class="task-pill <?php echo $isOverdue ? 'task-pill-danger' : 'task-pill-muted'; ?>"><i class="far fa-calendar" aria-hidden="true"></i><?php echo htmlspecialchars($dueLabel); ?></span>
                    <span class="task-pill task-pill-muted"><i class="far fa-user" aria-hidden="true"></i><?php echo htmlspecialchars($assigneeLabel); ?></span>
                    <span class="task-pill task-pill-muted"><i class="far fa-address-card" aria-hidden="true"></i><?php if (!empty($task['contact_id'])): ?><a href="contact_view.php?id=<?php echo (int) $task['contact_id']; ?>"><?php echo htmlspecialchars($contactName !== '' ? $contactName : 'Contact'); ?></a><?php elseif ($demoContactFallbackLabel !== ''): ?><a href="contacts.php?search=Amina"><?php echo htmlspecialchars($demoContactFallbackLabel); ?></a><?php else: ?>No contact<?php endif; ?></span>
                    <?php if ($sourceLabel !== 'Manual'): ?><span class="task-pill task-pill-primary"><?php echo htmlspecialchars($sourceLabel); ?></span><?php endif; ?>
                    <?php if (!empty($completionReview) && $taskStatus !== 'completed'): ?><span class="task-pill task-pill-warning">Needs review</span><?php endif; ?>
                    <?php if (($taskMeta['completion_source'] ?? '') === 'manual_review_confirmed' || !empty($taskMeta['completed_by_evidence'])): ?><span class="task-pill task-pill-success">Evidence confirmed</span><?php endif; ?>
                    <?php if (($completionV2['completed_by'] ?? null) === 'clarity'): ?><span class="task-pill task-pill-success">Completed by Clarity</span><?php endif; ?>
                </div>
                <?php if (!empty($task['description'])): ?><p class="task-description-block"><?php echo htmlspecialchars((string) $task['description']); ?></p><?php endif; ?>
            </div>
            <?php if ($canWriteTasks): ?>
                <div class="task-primary-actions" aria-label="Primary task actions">
                    <?php if ($taskStatus !== 'completed'): ?>
                        <form method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="<?php echo $reviewRequired ? 'review_completion' : 'mark_complete'; ?>"><button type="submit" class="task-btn task-btn-success"><?php echo $reviewRequired ? 'Review Completion' : 'Mark Done'; ?></button></form>
                        <?php if (!empty($taskMeta['auto_complete_allowed'])): ?>
                            <form method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="<?php echo $reviewRequired ? 'review_completion' : 'scan_completion'; ?>"><button type="submit" class="task-btn task-btn-secondary"><?php echo $reviewRequired ? 'Review Evidence' : 'Detect Completion'; ?></button></form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reopen_task"><button type="submit" class="task-btn task-btn-secondary">Reopen</button></form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($taskIsProtectedDemoGenerated): ?>
            <section class="protected-demo-task-detail-proof" data-demo-cue-key="tasks_page_visible">
                <div>
                    <span>Clarity demo automation</span>
                    <strong><?php echo (($taskMeta['ready_state'] ?? '') === 'draft_reviewed_ready_to_send') ? 'Draft reviewed and ready to send' : 'Riverside task is queued with context'; ?></strong>
                    <p>The task is linked to Amina, the Riverside thread, the assistant draft, and the response-time target. No real message is sent until a real workspace owner chooses to send.</p>
                </div>
                <a href="inbox.php" class="task-action-link">Review thread</a>
            </section>
        <?php endif; ?>

        <div class="task-detail-grid">
            <main class="task-section-stack">
                <?php if ($isOverdue): ?><div class="task-alert task-alert-error">This task is overdue. The due date has passed.</div><?php endif; ?>

                <?php if (!empty($completionReview) && $reviewRequired): ?>
                    <?php
                    $confidenceValue = (float) ($completionReview['confidence_score'] ?? 0.0);
                    $confidenceLabel = $confidenceValue >= 0.85 ? 'High confidence' : ($confidenceValue >= 0.5 ? 'Medium confidence' : 'Low confidence');
                    $reviewDecision = (string) ($completionReview['decision'] ?? 'insufficient_evidence');
                    $showOverrideReason = in_array($reviewDecision, ['warn', 'contradict', 'insufficient_evidence'], true);
                    $reviewTone = $reviewDecision === 'confirm' ? 'task-pill-success' : ($reviewDecision === 'contradict' ? 'task-pill-danger' : 'task-pill-warning');
                    ?>
                    <section class="task-panel">
                        <div class="task-panel-header"><div><div class="task-panel-kicker">Evidence</div><h2 class="task-panel-title">Completion Review</h2></div><span class="task-pill <?php echo $reviewTone; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $reviewDecision)); ?></span></div>
                        <p class="task-page-subtitle"><?php echo htmlspecialchars($confidenceLabel); ?>, score <?php echo number_format($confidenceValue, 2); ?></p>
                        <?php if (!empty($completionReview['explanation'])): ?><p class="task-description-block"><?php echo htmlspecialchars((string) $completionReview['explanation']); ?></p><?php endif; ?>
                        <div class="task-review-evidence">
                            <?php foreach ([['evidence_found', 'Found', 'task-pill-success'], ['evidence_missing', 'Missing', 'task-pill-warning'], ['evidence_conflicts', 'Conflicts', 'task-pill-danger']] as $bucket): ?>
                                <?php if (!empty($completionReview[$bucket[0]])): ?>
                                    <div><div class="task-field-label"><?php echo $bucket[1]; ?></div><div class="task-chip-row">
                                        <?php foreach ((array) $completionReview[$bucket[0]] as $item): ?><span class="task-pill <?php echo $bucket[2]; ?>" title="<?php echo htmlspecialchars((string) ($item['detail'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($item['label'] ?? $bucket[1])); ?></span><?php endforeach; ?>
                                    </div></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($taskStatus !== 'completed' && $canWriteTasks): ?>
                            <div class="task-inline-actions">
                                <form method="POST" action="" class="task-inline-form" style="flex:1 1 320px;"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="complete_from_review"><input type="hidden" name="decision" value="<?php echo $reviewDecision === 'confirm' ? 'complete_recommended' : 'complete_anyway'; ?>"><?php if ($showOverrideReason): ?><textarea name="override_reason" rows="2" placeholder="Optional reason for marking this done anyway"></textarea><?php endif; ?><button type="submit" class="task-btn task-btn-success"><?php echo $reviewDecision === 'confirm' ? 'Mark Done' : 'Mark Done Anyway'; ?></button></form>
                                <form method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="dismiss_completion_review"><button type="submit" class="task-btn task-btn-secondary">Keep Open</button></form>
                                <form method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="review_completion"><button type="submit" class="task-btn task-btn-secondary">Detect Again</button></form>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if (($completionV2['completed_by'] ?? null) === 'clarity'): ?>
                    <section class="task-panel task-completion-proof">
                        <div class="task-panel-header"><div><div class="task-panel-kicker">Completion evidence</div><h2 class="task-panel-title">Completed by Clarity</h2></div><span class="task-pill task-pill-success"><?php echo number_format((float) ($completionV2['confidence'] ?? 0), 2); ?> confidence</span></div>
                        <p class="task-description-block"><?php echo htmlspecialchars((string) ($completionV2['explanation'] ?? 'Clarity found sufficient recorded CRM evidence.')); ?></p>
                        <?php if (!empty($completionV2['evidence'])): ?><div class="task-chip-row"><?php foreach ((array) $completionV2['evidence'] as $item): ?><span class="task-pill task-pill-muted"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($item['type'] ?? 'evidence'))); ?></span><?php endforeach; ?></div><?php endif; ?>
                        <?php if ($canWriteTasks): ?><form method="POST" action="" class="task-inline-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reopen_task"><button type="submit" class="task-btn task-btn-secondary">Undo and reopen</button></form><?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($isAiTask && !empty($completionGuidance)): ?>
                    <section class="task-panel">
                        <div class="task-panel-header"><div><div class="task-panel-kicker">AI guidance</div><h2 class="task-panel-title">Completion Rules</h2></div><?php if (!empty($taskMeta['auto_complete_allowed'])): ?><span class="task-pill task-pill-success">Auto-detectable</span><?php endif; ?></div>
                        <?php if (!empty($completionGuidance['summary'])): ?><p class="task-description-block"><?php echo htmlspecialchars((string) $completionGuidance['summary']); ?></p><?php endif; ?>
                        <?php if (!empty($completionGuidance['completion_checks'])): ?><div class="task-review-evidence"><div class="task-field-label">Checks</div><ul><?php foreach ((array) $completionGuidance['completion_checks'] as $check): ?><li><?php echo htmlspecialchars((string) $check); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                        <?php if (!empty($completionGuidance['evidence_signals'])): ?><div class="task-chip-row"><?php foreach ((array) $completionGuidance['evidence_signals'] as $signal): ?><span class="task-pill task-pill-muted"><?php echo htmlspecialchars((string) $signal); ?></span><?php endforeach; ?></div><?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($canWriteTasks && $taskStatus !== 'completed'): ?>
                    <section class="task-panel">
                        <div class="task-panel-header"><div><div class="task-panel-kicker">Clarity completion</div><h2 class="task-panel-title">Completion preference</h2></div><span class="task-pill task-pill-muted"><?php echo htmlspecialchars((string) ($completionV2['workspace_mode'] ?? 'review')); ?> workspace</span></div>
                        <p class="task-help-text">Auto mode is available only for low-risk tasks with recorded evidence. This workspace starts in review mode.</p>
                        <form method="POST" action="" class="task-inline-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="set_completion_mode"><select name="completion_mode"><option value="manual" <?php echo ($completionV2['mode'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option><option value="review" <?php echo ($completionV2['mode'] ?? '') === 'review' ? 'selected' : ''; ?>>Review evidence</option><?php if (!empty($completionV2['can_enable_auto'])): ?><option value="auto" <?php echo ($completionV2['mode'] ?? '') === 'auto' ? 'selected' : ''; ?>>Let Clarity complete when certain</option><?php endif; ?></select><button type="submit" class="task-btn task-btn-secondary">Save preference</button></form>
                    </section>
                <?php endif; ?>

                <?php if (!empty($subtasks)): ?>
                    <section class="task-panel">
                        <div class="task-panel-header"><div><div class="task-panel-kicker">Checklist</div><h2 class="task-panel-title">Steps</h2></div><span class="task-panel-subtitle"><?php echo $completedCount; ?>/<?php echo $subtaskTotal; ?> done</span></div>
                        <div id="task-subtasks" class="task-checklist">
                            <?php foreach ($subtasks as $st): ?>
                                <?php $subtaskDescription = (string) ($st['description'] ?? '') !== '' ? (string) $st['description'] : (string) ($subtaskHintMap[(string) ($st['title'] ?? '')] ?? ''); ?>
                                <label class="task-checklist-item <?php echo !empty($st['completed']) ? 'task-checklist-item--done' : ''; ?>">
                                    <input type="checkbox" class="task-subtask-toggle" data-subtask-id="<?php echo (int) $st['id']; ?>" data-task-id="<?php echo (int) $task['id']; ?>" data-subtask-title="<?php echo htmlspecialchars((string) $st['title'], ENT_QUOTES); ?>" value="<?php echo (int) $st['id']; ?>" <?php echo !empty($st['completed']) ? 'checked' : ''; ?>>
                                    <span><span class="task-checklist-title"><?php echo htmlspecialchars((string) $st['title']); ?></span><?php if ($subtaskDescription !== ''): ?><span class="task-checklist-detail"><?php echo htmlspecialchars($subtaskDescription); ?></span><?php endif; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="task-panel">
                    <div class="task-panel-header"><div><div class="task-panel-kicker">Notes</div><h2 class="task-panel-title">Task Notes</h2></div><span class="task-panel-subtitle"><?php echo count($taskNotes); ?> note<?php echo count($taskNotes) !== 1 ? 's' : ''; ?></span></div>
                    <?php if ($noteError): ?><div class="task-alert task-alert-error"><?php echo htmlspecialchars($noteError); ?></div><?php endif; ?>
                    <form method="POST" action="" class="task-note-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="create_note"><input type="text" name="note_title" placeholder="Note title (optional)"><div class="rich-text-editor"><textarea name="note_content" required rows="3" placeholder="Add a note about this task..." style="display: none;"></textarea></div><div class="task-inline-actions"><label class="task-help-text" style="display:flex;align-items:center;gap:0.35rem;"><input type="checkbox" name="note_private" value="1">Private note</label><button type="submit" class="task-btn task-btn-primary">Add Note</button></div></form>
                    <?php if (empty($taskNotes)): ?><p class="task-help-text">No notes yet.</p><?php else: ?><div class="task-note-list">
                        <?php foreach ($taskNotes as $note): ?><article class="task-note-card"><div class="task-panel-header"><div><?php if ($note['title']): ?><div class="task-sidebar-value"><?php echo htmlspecialchars($note['title']); ?></div><?php endif; ?><div class="task-note-meta"><?php echo htmlspecialchars($note['created_by_email'] ?? 'Unknown'); ?><?php if ($note['is_private']): ?> private<?php endif; ?> | <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></div></div><?php if ($note['created_by'] == $userId || $canManageAllNotes): ?><form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this note?');"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="delete_note"><input type="hidden" name="note_id" value="<?php echo $note['id']; ?>"><button type="submit" class="task-btn task-btn-ghost">Delete</button></form><?php endif; ?></div><div class="task-note-content note-content"><?php $noteBody = (string) $note['content']; if ($noteBody !== '' && (strpos($noteBody, '<p>') !== false || strpos($noteBody, '<br>') !== false || strpos($noteBody, '<strong>') !== false || strpos($noteBody, '<em>') !== false)) { echo strip_tags($noteBody, '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><a><span>'); } else { echo nl2br(htmlspecialchars($noteBody)); } ?></div></article><?php endforeach; ?>
                    </div><?php endif; ?>
                </section>

                <section class="task-panel">
                    <div class="task-panel-header"><div><div class="task-panel-kicker">Files</div><h2 class="task-panel-title">Documents</h2></div><span class="task-panel-subtitle"><?php echo count($taskDocuments); ?> file<?php echo count($taskDocuments) !== 1 ? 's' : ''; ?></span></div>
                    <form class="documentUploadForm task-document-form" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="entity_type" value="task"><input type="hidden" name="entity_id" value="<?php echo $taskId; ?>"><input type="file" name="file" required accept="*/*"><input type="text" name="description" placeholder="Description (optional)"><div class="task-inline-actions"><span class="task-help-text">Maximum file size: 10MB</span><button type="submit" class="task-btn task-btn-primary">Upload</button></div></form>
                    <?php if (empty($taskDocuments)): ?><p class="task-help-text">No documents yet.</p><?php else: ?><div class="task-doc-grid"><?php foreach ($taskDocuments as $doc): ?><article class="task-doc-card"><div class="task-inline-actions" style="align-items:flex-start;"><div style="font-size:1.5rem;"><?php echo $documentsModule->getFileIcon($doc['mime_type'] ?? ''); ?></div><div style="min-width:0;flex:1;"><div class="task-doc-title"><?php echo htmlspecialchars($doc['original_name']); ?></div><div class="task-doc-meta"><?php echo $documentsModule->formatFileSize($doc['file_size']); ?></div><?php if ($doc['description']): ?><div class="task-help-text"><?php echo htmlspecialchars($doc['description']); ?></div><?php endif; ?></div></div><div class="task-inline-actions" style="justify-content:space-between;margin-top:0.6rem;"><div class="task-doc-meta"><?php echo htmlspecialchars($doc['uploaded_by_email'] ?? 'Unknown'); ?><br><?php echo date('M j, Y', strtotime($doc['created_at'])); ?></div><div class="task-inline-actions"><a href="document_download.php?id=<?php echo $doc['id']; ?>" class="task-action-link">Download</a><?php if ($doc['uploaded_by'] == $userId || $canManageAllDocuments): ?><button type="button" onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="task-btn task-btn-ghost">Delete</button><?php endif; ?></div></div></article><?php endforeach; ?></div><?php endif; ?>
                </section>
            </main>

            <aside class="task-section-stack">
                <section class="task-panel">
                    <div class="task-panel-header"><div><div class="task-panel-kicker">Metadata</div><h2 class="task-panel-title">Task Info</h2></div></div>
                    <div class="task-sidebar-list">
                        <div class="task-sidebar-item"><div class="task-field-label">Assignee</div><div class="task-sidebar-value"><?php echo htmlspecialchars($assigneeLabel); ?></div><?php if ($canReassignTasks): ?><form method="POST" action="" class="task-inline-form" style="margin-top:0.35rem;"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reassign_task"><select name="assigned_to"><option value="">Unassigned</option><?php foreach ($manualAssignableUsers as $userOption): ?><option value="<?php echo (int) $userOption['id']; ?>" <?php echo (string) ($task['assigned_to'] ?? '') === (string) $userOption['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($demoTaskDisplayLabel((string) ($userOption['email'] ?? ''), 'User #' . (int) $userOption['id'], 'assignee')); ?></option><?php endforeach; ?></select><button type="submit" class="task-btn task-btn-secondary">Reassign</button></form><?php endif; ?></div>
                        <div class="task-sidebar-item"><div class="task-field-label">Contact</div><div class="task-sidebar-value"><?php if (!empty($task['contact_id'])): ?><a href="contact_view.php?id=<?php echo (int) $task['contact_id']; ?>"><?php echo htmlspecialchars($contactName !== '' ? $contactName : 'View contact'); ?></a><?php elseif ($demoContactFallbackLabel !== ''): ?><a href="contacts.php?search=Amina"><?php echo htmlspecialchars($demoContactFallbackLabel); ?></a><?php else: ?>No contact<?php endif; ?></div></div>
                        <?php if (!empty($task['target_entity_type']) || !empty($task['target_entity_id'])): ?><div class="task-sidebar-item"><div class="task-field-label">Target</div><div class="task-sidebar-value"><?php echo htmlspecialchars((string) ($task['target_entity_type'] ?? '')); ?><?php if (!empty($task['target_entity_id'])): ?> #<?php echo (int) $task['target_entity_id']; ?><?php endif; ?></div></div><?php endif; ?>
                        <div class="task-sidebar-item"><div class="task-field-label">Source</div><div class="task-sidebar-value"><?php echo htmlspecialchars($sourceLabel); ?></div></div>
                        <div class="task-sidebar-item"><div class="task-field-label">Created by</div><div class="task-sidebar-value"><?php echo htmlspecialchars($createdByLabel); ?></div></div>
                        <div class="task-sidebar-item"><div class="task-field-label">Created</div><div class="task-sidebar-value"><?php echo date('M d, Y g:i A', strtotime($task['created_at'])); ?></div></div>
                        <?php if (!empty($task['completed_at'])): ?><div class="task-sidebar-item"><div class="task-field-label">Completed</div><div class="task-sidebar-value"><?php echo date('M d, Y g:i A', strtotime($task['completed_at'])); ?></div></div><?php endif; ?>
                        <?php if (!empty($completionReview['reviewed_at'])): ?><div class="task-sidebar-item"><div class="task-field-label">Last review</div><div class="task-sidebar-value"><?php echo date('M d, Y g:i A', strtotime((string) $completionReview['reviewed_at'])); ?></div></div><?php endif; ?>
                    </div>
                </section>
                <?php if ($isAiTask || !empty($taskEvidence)): ?><section class="task-panel"><div class="task-panel-header"><div><div class="task-panel-kicker">Diagnostics</div><h2 class="task-panel-title">AI Evidence</h2></div></div><p class="task-page-subtitle">Review linked automation decisions and evidence.</p><a href="ai_automation_diagnostics.php?task_id=<?php echo (int) $taskId; ?>" class="task-action-link" style="margin-top:0.65rem;">Open diagnostics</a></section><?php endif; ?>
            </aside>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.task-subtask-toggle').forEach(function(cb) {
    cb.addEventListener('change', async function() {
        const subtaskId = this.dataset.subtaskId || this.getAttribute('data-subtask-id') || this.value || '';
        const taskId = this.dataset.taskId || '';
        const subtaskTitle = this.dataset.subtaskTitle || '';
        const completed = this.checked ? 1 : 0;
        if (!subtaskId) {
            console.warn('Checklist item missing direct subtask id, falling back to task/title lookup.', { taskId, subtaskTitle });
        }
        try {
            const res = await fetch('../api/tasks/subtasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    csrf_token: '<?php echo Security::getCsrfToken(); ?>',
                    subtask_id: subtaskId,
                    subtaskId: subtaskId,
                    task_id: taskId,
                    subtask_title: subtaskTitle,
                    completed: completed
                })
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.error || 'Failed to update subtask');
            }
            // Keep UI simple: reload for accurate progress counts/styling
            location.reload();
        } catch (e) {
            alert(e.message || 'Failed to update subtask');
            this.checked = !this.checked;
        }
    });
});

const documentUploadForm = document.querySelector('.documentUploadForm');
if (documentUploadForm) {
documentUploadForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    
    try {
        const response = await fetch('document_upload.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Upload failed'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
}

async function deleteDocument(docId) {
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
    formData.append('document_id', docId);
    
    try {
        const response = await fetch('document_delete.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Delete failed'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
