<?php
/**
 * Create Event Page
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
use CRM\Modules\Events;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$eventsModule = new Events();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$canViewAllEvents = $eventsModule->canViewAll($user);
$supportsRecurrence = $eventsModule->supportsRecurrence();
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
                'event_type' => $_POST['event_type'] ?? 'meeting',
                'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                'assigned_to' => $eventsModule->resolveAssignedToForUser($_POST['assigned_to'] ?? null, $user),
                'start_time' => $_POST['start_time'] ?? '',
                'end_time' => !empty($_POST['end_time']) ? $_POST['end_time'] : null,
                'location' => $_POST['location'] ?? '',
                'is_all_day' => isset($_POST['is_all_day']) ? 1 : 0,
                'reminder_minutes' => !empty($_POST['reminder_minutes']) ? (int) $_POST['reminder_minutes'] : null,
                'status' => $_POST['status'] ?? 'scheduled',
                'created_by' => $userId
            ];
            if ($supportsRecurrence) {
                $data['recurrence_pattern'] = $_POST['recurrence_pattern'] ?? 'none';
                $data['recurrence_end_date'] = !empty($_POST['recurrence_end_date']) ? $_POST['recurrence_end_date'] : null;
                $data['recurrence_count'] = !empty($_POST['recurrence_count']) ? (int) $_POST['recurrence_count'] : null;
            }
            
            $eventId = $eventsModule->create($data);
            
            header('Location: event_view.php?id=' . $eventId . '&success=created');
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Event create failed: ' . $e->getMessage());
            $error = 'Event could not be created. Please try again.';
        }
    }
}

// Get only contacts this user can open directly.
$contacts = (new Contacts())->getAll(
    100,
    0,
    null,
    Authorization::can('contacts.view_all', $user) ? 'all' : 'mine_unassigned',
    $userId
);

// Get users for assignment
$users = $canViewAllEvents
    ? Database::query(
        "SELECT u.id, u.email
         FROM users u
         JOIN workspace_memberships wm ON wm.user_id = u.id
         WHERE wm.workspace_id = ?
           AND wm.membership_status = 'active'
         ORDER BY u.email ASC",
        [$workspaceId]
    )
    : [[
        'id' => $userId,
        'email' => (string) ($user['email'] ?? 'My calendar'),
    ]];

$selectedAssignedTo = array_key_exists('assigned_to', $_POST)
    ? $eventsModule->resolveAssignedToForUser($_POST['assigned_to'], $user)
    : $userId;
$isAllDayEvent = isset($_POST['is_all_day']);
$startValue = $_POST['start_time'] ?? '';
$endValue = $_POST['end_time'] ?? '';
$startInputType = $isAllDayEvent ? 'date' : 'datetime-local';
$endInputType = $isAllDayEvent ? 'date' : 'datetime-local';
$startLabel = $isAllDayEvent ? 'Start Date *' : 'Start Time *';
$endLabel = $isAllDayEvent ? 'End Date' : 'End Time';

$pageTitle = 'Create Event - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Create Event</h1>
    <p style="color: var(--charcoal-grey);">Schedule a new event or meeting</p>
</div>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 800px;">
    <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        
        <div>
            <label for="title" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Event Title *</label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                required
                value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                placeholder="e.g., Client Meeting"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Description</label>
            <textarea 
                id="description" 
                name="description" 
                rows="4"
                placeholder="Add details about this event..."
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"
            ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="event_type" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Event Type *</label>
                <select 
                    id="event_type" 
                    name="event_type" 
                    required
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <option value="meeting" <?php echo (($_POST['event_type'] ?? 'meeting') === 'meeting') ? 'selected' : ''; ?>>Meeting</option>
                    <option value="call" <?php echo (($_POST['event_type'] ?? 'meeting') === 'call') ? 'selected' : ''; ?>>Call</option>
                    <option value="email" <?php echo (($_POST['event_type'] ?? 'meeting') === 'email') ? 'selected' : ''; ?>>Email</option>
                    <option value="task" <?php echo (($_POST['event_type'] ?? 'meeting') === 'task') ? 'selected' : ''; ?>>Task</option>
                    <option value="other" <?php echo (($_POST['event_type'] ?? 'meeting') === 'other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div>
                <label for="status" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Status *</label>
                <select 
                    id="status" 
                    name="status" 
                    required
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <option value="scheduled" <?php echo (($_POST['status'] ?? 'scheduled') === 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="completed" <?php echo (($_POST['status'] ?? 'scheduled') === 'completed') ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo (($_POST['status'] ?? 'scheduled') === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="postponed" <?php echo (($_POST['status'] ?? 'scheduled') === 'postponed') ? 'selected' : ''; ?>>Postponed</option>
                </select>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="contact_id" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Related Contact</label>
                <select 
                    id="contact_id" 
                    name="contact_id" 
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <option value="">None</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?php echo $contact['id']; ?>" <?php echo (isset($_POST['contact_id']) && $_POST['contact_id'] == $contact['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '') . ' (' . ($contact['email'] ?? '') . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="assigned_to" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Assign To</label>
                <select 
                    id="assigned_to" 
                    name="assigned_to" 
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <?php if ($canViewAllEvents): ?>
                        <option value="">Unassigned</option>
                    <?php endif; ?>
                    <?php foreach ($users as $userOption): ?>
                        <option value="<?php echo $userOption['id']; ?>" <?php echo $selectedAssignedTo === (int) $userOption['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($userOption['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div>
            <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                <input 
                    type="checkbox" 
                    id="is_all_day" 
                    name="is_all_day" 
                    value="1"
                    onchange="toggleTimeFields()"
                    <?php echo isset($_POST['is_all_day']) ? 'checked' : ''; ?>
                    style="width: 18px; height: 18px;"
                >
                <span style="color: var(--midnight-black); font-weight: 500;">All Day Event</span>
            </label>
        </div>
        
        <div id="time_fields" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label id="start_time_label" for="start_time" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;"><?php echo htmlspecialchars($startLabel); ?></label>
                <input 
                    type="<?php echo $startInputType; ?>" 
                    id="start_time" 
                    name="start_time" 
                    required
                    value="<?php echo htmlspecialchars($startValue); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div>
                <label id="end_time_label" for="end_time" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;"><?php echo htmlspecialchars($endLabel); ?></label>
                <input 
                    type="<?php echo $endInputType; ?>" 
                    id="end_time" 
                    name="end_time" 
                    value="<?php echo htmlspecialchars($endValue); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="location" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Location</label>
                <input 
                    type="text" 
                    id="location" 
                    name="location" 
                    value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                    placeholder="e.g., Conference Room A"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div>
                <label for="reminder_minutes" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Reminder (minutes before)</label>
                <select 
                    id="reminder_minutes" 
                    name="reminder_minutes" 
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <option value="">No reminder</option>
                    <option value="5" <?php echo (isset($_POST['reminder_minutes']) && $_POST['reminder_minutes'] == '5') ? 'selected' : ''; ?>>5 minutes</option>
                    <option value="15" <?php echo (isset($_POST['reminder_minutes']) && $_POST['reminder_minutes'] == '15') ? 'selected' : ''; ?>>15 minutes</option>
                    <option value="30" <?php echo (isset($_POST['reminder_minutes']) && $_POST['reminder_minutes'] == '30') ? 'selected' : ''; ?>>30 minutes</option>
                    <option value="60" <?php echo (isset($_POST['reminder_minutes']) && $_POST['reminder_minutes'] == '60') ? 'selected' : ''; ?>>1 hour</option>
                    <option value="1440" <?php echo (isset($_POST['reminder_minutes']) && $_POST['reminder_minutes'] == '1440') ? 'selected' : ''; ?>>1 day</option>
                </select>
            </div>
        </div>
        
        <?php if ($supportsRecurrence): ?>
            <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md);">
                <h3 style="color: var(--midnight-black); font-size: 16px; margin-bottom: var(--spacing-md);">Recurrence</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <label for="recurrence_pattern" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Repeat</label>
                        <select 
                            id="recurrence_pattern" 
                            name="recurrence_pattern" 
                            onchange="toggleRecurrenceFields()"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                            <option value="none" <?php echo (isset($_POST['recurrence_pattern']) && $_POST['recurrence_pattern'] == 'none') || !isset($_POST['recurrence_pattern']) ? 'selected' : ''; ?>>No Repeat</option>
                            <option value="daily" <?php echo (isset($_POST['recurrence_pattern']) && $_POST['recurrence_pattern'] == 'daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo (isset($_POST['recurrence_pattern']) && $_POST['recurrence_pattern'] == 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo (isset($_POST['recurrence_pattern']) && $_POST['recurrence_pattern'] == 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="yearly" <?php echo (isset($_POST['recurrence_pattern']) && $_POST['recurrence_pattern'] == 'yearly') ? 'selected' : ''; ?>>Yearly</option>
                        </select>
                    </div>
                    <div id="recurrence_end_date_field" style="display: none;">
                        <label for="recurrence_end_date" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">End Date</label>
                        <input 
                            type="date" 
                            id="recurrence_end_date" 
                            name="recurrence_end_date" 
                            value="<?php echo htmlspecialchars($_POST['recurrence_end_date'] ?? ''); ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                    </div>
                    <div id="recurrence_count_field" style="display: none;">
                        <label for="recurrence_count" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Number of Occurrences</label>
                        <input 
                            type="number" 
                            id="recurrence_count" 
                            name="recurrence_count" 
                            min="1"
                            value="<?php echo htmlspecialchars($_POST['recurrence_count'] ?? ''); ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md); color: var(--charcoal-grey);">
                Recurring events are not available on this database yet. Single events will still save normally.
            </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
            <a href="calendar.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Create Event
            </button>
        </div>
    </form>
</div>

<script>
function toggleTimeFields() {
    const isAllDay = document.getElementById('is_all_day').checked;
    const startTime = document.getElementById('start_time');
    const endTime = document.getElementById('end_time');
    const startLabel = document.getElementById('start_time_label');
    const endLabel = document.getElementById('end_time_label');
    
    if (isAllDay) {
        startTime.type = 'date';
        endTime.type = 'date';
        if (startLabel) startLabel.textContent = 'Start Date *';
        if (endLabel) endLabel.textContent = 'End Date';
    } else {
        startTime.type = 'datetime-local';
        endTime.type = 'datetime-local';
        if (startLabel) startLabel.textContent = 'Start Time *';
        if (endLabel) endLabel.textContent = 'End Time';
    }
}

// Initialize on page load
toggleTimeFields();
<?php if ($supportsRecurrence): ?>
toggleRecurrenceFields();
<?php endif; ?>

function toggleRecurrenceFields() {
    const recurrence = document.getElementById('recurrence_pattern');
    if (!recurrence) {
        return;
    }
    const pattern = recurrence.value;
    const endDateField = document.getElementById('recurrence_end_date_field');
    const countField = document.getElementById('recurrence_count_field');
    
    if (pattern !== 'none') {
        endDateField.style.display = 'block';
        countField.style.display = 'block';
    } else {
        endDateField.style.display = 'none';
        countField.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
