<?php
/**
 * Create Task Page
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
$user = Auth::user();
$assignmentAccess = new TaskAssignmentAccessService();
$error = null;
$success = null;

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
                'created_by' => (int) ($user['id'] ?? 0),
                'actor_user_id' => (int) ($user['id'] ?? 0),
            ];
            
            $taskId = $tasksModule->create($data);
            
            header('Location: task_view.php?id=' . $taskId . '&success=created');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get contacts for dropdown
$contacts = $contactsModule->getAll(100, 0);

// Get users for assignment — pass task context so domain-specific permission filtering applies
$taskContext = [];
if (!empty($_POST['title'])) {
    $taskContext = [
        'title'       => (string) ($_POST['title'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
    ];
}
$users = $assignmentAccess->getManualAssignableUsers($taskContext);

$pageTitle = 'Create Task - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/tasks-ui.css">

<div class="page-premium task-page">
    <div class="container tasks-page">
        <div class="task-page-header">
            <div>
                <div class="task-page-kicker">Task</div>
                <h1>Create Task</h1>
                <p>Define the work, owner, and timing in one pass.</p>
            </div>
            <div class="task-page-actions">
                <a href="tasks.php" class="task-btn task-btn-secondary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Tasks
                </a>
            </div>
        </div>

<?php if ($error): ?>
        <div class="task-alert task-alert-error">
        <?php echo htmlspecialchars($error); ?>
        </div>
<?php endif; ?>

        <form method="POST" action="" class="task-compact-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

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
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
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
                    ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
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
                                <option value="<?php echo $contact['id']; ?>" <?php echo (isset($_POST['contact_id']) && $_POST['contact_id'] == $contact['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '') . ' (' . ($contact['email'] ?? '') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="assigned_to">Assignee</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $userOption): ?>
                                <option value="<?php echo $userOption['id']; ?>" <?php echo (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $userOption['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($userOption['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                            <option value="low" <?php echo (($_POST['priority'] ?? 'medium') === 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo (($_POST['priority'] ?? 'medium') === 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo (($_POST['priority'] ?? 'medium') === 'high') ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo (($_POST['priority'] ?? 'medium') === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="pending" <?php echo (($_POST['status'] ?? 'pending') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="in_progress" <?php echo (($_POST['status'] ?? 'pending') === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo (($_POST['status'] ?? 'pending') === 'completed') ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="due_date">Due date</label>
                        <input
                            type="datetime-local"
                            id="due_date"
                            name="due_date"
                            value="<?php echo htmlspecialchars($_POST['due_date'] ?? ''); ?>"
                        >
                    </div>
                </div>
            </section>

            <div class="task-form-actions">
                <a href="tasks.php" class="task-btn task-btn-secondary">Cancel</a>
                <button type="submit" class="task-btn task-btn-primary">Create Task</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
