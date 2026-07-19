<?php
/**
 * Edit Task Page
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
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\Tasks;
use CRM\Services\TaskAssignmentAccessService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

Authorization::requirePermission('tasks.write');

$tasksModule = new Tasks();
$contactsModule = new Contacts();
$assignmentAccess = new TaskAssignmentAccessService();
$currentUser = Auth::user();
$canReassignTasks = $assignmentAccess->canReassignTasks($currentUser);
$taskId = (int) ($_GET['id'] ?? 0);

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
    !Authorization::can('tasks.view_all', $currentUser)
    && !in_array((int) ($currentUser['id'] ?? 0), [(int) ($task['assigned_to'] ?? 0), (int) ($task['created_by'] ?? 0)], true)
) {
    http_response_code(403);
    die('Access denied: You do not have permission to edit this task.');
}

$error = null;
$success = null;
$conflict = null;
$taskFieldLabels = [
    'title' => 'Title',
    'description' => 'Description',
    'contact_id' => 'Contact',
    'assigned_to' => 'Assignee',
    'status' => 'Status',
    'priority' => 'Priority',
    'due_date' => 'Due date',
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $data = [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
                'status' => $_POST['status'] ?? 'pending',
                'priority' => $_POST['priority'] ?? 'medium',
                'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                'actor_user_id' => (int) (Auth::user()['id'] ?? 0),
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ];

            Database::beginTransaction();
            $tasksModule->update($taskId, $data);
            Database::commit();
            
            header('Location: task_view.php?id=' . $taskId . '&success=updated');
            exit;
        } catch (ConcurrencyConflictException $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollback();
            }
            $conflict = $e;
            $task = $tasksModule->getById($taskId) ?: $task;
            $error = 'This record changed since you opened it.';
        } catch (\Exception $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollback();
            }
            $error = $e->getMessage();
        }
    }
}

// Get contacts for dropdown
$contacts = $contactsModule->getAll(100, 0);

// Get users for assignment — pass task context so domain-specific permission filtering applies
$taskContext = [
    'title'       => (string) ($_POST['title'] ?? $task['title'] ?? ''),
    'description' => (string) ($_POST['description'] ?? $task['description'] ?? ''),
];
$users = $assignmentAccess->getManualAssignableUsers($taskContext);

$pageTitle = 'Edit Task - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/tasks-ui.css">

<div class="page-premium task-page">
    <div class="container tasks-page">
        <div class="task-page-header">
            <div>
                <div class="task-page-kicker">Task</div>
                <h1>Edit Task</h1>
                <p>Update the work, owner, and timing without losing the task context.</p>
            </div>
            <div class="task-page-actions">
                <a href="task_view.php?id=<?php echo $taskId; ?>" class="task-btn task-btn-secondary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Task
                </a>
            </div>
        </div>

<?php if ($error): ?>
        <div class="task-alert task-alert-error">
        <?php echo htmlspecialchars($error); ?>
        </div>
<?php endif; ?>

<?php if ($conflict): ?>
        <div class="task-alert task-alert-warning">
            <strong>Someone updated this while you were editing.</strong>
            <p>Review the saved values before deciding whether to reload or overwrite with your attempted values.</p>
            <?php $diffRows = Concurrency::conflictDiffRows($conflict, $taskFieldLabels); ?>
            <?php if (!empty($diffRows)): ?>
                <div class="task-table-wrap">
                    <table class="task-table">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Current saved value</th>
                                <th>Your attempted value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($diffRows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['label']); ?></td>
                                    <td><?php echo htmlspecialchars($row['current']); ?></td>
                                    <td><?php echo htmlspecialchars($row['submitted']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="task-form-actions">
                <a href="task_edit.php?id=<?php echo $taskId; ?>" class="task-btn task-btn-secondary">Reload latest</a>
                <button type="submit" form="task-edit-form" class="task-btn task-btn-primary">Overwrite with my values</button>
            </div>
        </div>
<?php endif; ?>

        <form method="POST" action="" class="task-compact-form" id="task-edit-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="expected_lock_version" value="<?php echo htmlspecialchars((string) ($task['lock_version'] ?? 0)); ?>">

            <section class="task-form-section">
                <div>
                    <div class="task-panel-kicker">Task</div>
                    <h2>Work to do</h2>
                </div>
                <div class="task-form-field">
                    <label for="title">Title *</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        required
                        value="<?php echo htmlspecialchars($_POST['title'] ?? $task['title']); ?>"
                        placeholder="Follow up with client"
                    >
                </div>
                <div class="task-form-field">
                    <label for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        rows="4"
                        placeholder="Add context, expected outcome, or links."
                    ><?php echo htmlspecialchars($_POST['description'] ?? $task['description'] ?? ''); ?></textarea>
                </div>
            </section>

            <section class="task-form-section">
                <div>
                    <div class="task-panel-kicker">Ownership</div>
                    <h2>Contact and assignee</h2>
                </div>
                <div class="task-form-grid">
                    <div class="task-form-field">
                        <label for="contact_id">Contact</label>
                        <select id="contact_id" name="contact_id">
                            <option value="">No contact</option>
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?php echo $contact['id']; ?>" <?php echo ((isset($_POST['contact_id']) ? $_POST['contact_id'] : $task['contact_id']) == $contact['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '') . ' (' . ($contact['email'] ?? '') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="assigned_to">Assignee</label>
                        <?php if ($canReassignTasks): ?>
                            <select id="assigned_to" name="assigned_to">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $userOption): ?>
                                    <option value="<?php echo $userOption['id']; ?>" <?php echo ((isset($_POST['assigned_to']) ? $_POST['assigned_to'] : $task['assigned_to']) == $userOption['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($userOption['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars((string) ($task['assigned_to'] ?? '')); ?>">
                            <div class="task-muted-box">
                                <strong><?php echo htmlspecialchars((string) ($task['assigned_to_email'] ?? 'Unassigned')); ?></strong>
                                <div class="task-help-text">Only users with task reassignment permission can change the assignee.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="task-form-section">
                <div>
                    <div class="task-panel-kicker">Timing</div>
                    <h2>Priority and status</h2>
                </div>
                <div class="task-form-grid task-form-grid--three">
                    <div class="task-form-field">
                        <label for="priority">Priority *</label>
                        <select id="priority" name="priority" required>
                            <option value="low" <?php echo ((isset($_POST['priority']) ? $_POST['priority'] : $task['priority']) === 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo ((isset($_POST['priority']) ? $_POST['priority'] : $task['priority']) === 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo ((isset($_POST['priority']) ? $_POST['priority'] : $task['priority']) === 'high') ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo ((isset($_POST['priority']) ? $_POST['priority'] : $task['priority']) === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="pending" <?php echo ((isset($_POST['status']) ? $_POST['status'] : $task['status']) === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="in_progress" <?php echo ((isset($_POST['status']) ? $_POST['status'] : $task['status']) === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo ((isset($_POST['status']) ? $_POST['status'] : $task['status']) === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo ((isset($_POST['status']) ? $_POST['status'] : $task['status']) === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="due_date">Due date</label>
                        <input
                            type="datetime-local"
                            id="due_date"
                            name="due_date"
                            value="<?php echo $task['due_date'] ? date('Y-m-d\TH:i', strtotime($task['due_date'])) : ''; ?>"
                        >
                    </div>
                </div>
            </section>

            <div class="task-form-actions">
                <a href="task_view.php?id=<?php echo $taskId; ?>" class="task-btn task-btn-secondary">Cancel</a>
                <button type="submit" class="task-btn task-btn-primary">Update Task</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
