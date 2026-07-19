<?php
/**
 * Notification Preferences Page
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
use CRM\Security;
use CRM\Modules\NotificationPreferences;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$preferencesModule = new NotificationPreferences();

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $preferences = [];
            
            // Get all notification types
            $notificationTypes = [
                'contact_created', 'contact_updated', 'contact_deleted',
                'task_assigned', 'task_completed', 'task_overdue',
                'event_reminder', 'event_created', 'event_updated',
                'deal_created', 'deal_updated', 'deal_won', 'deal_lost',
                'email_received', 'email_sent',
                'whatsapp_message_received',
                'note_mentioned', 'note_created',
                'workflow_triggered'
            ];
            
            foreach ($notificationTypes as $type) {
                $preferences[$type] = [
                    'email_enabled' => isset($_POST[$type . '_email']),
                    'in_app_enabled' => isset($_POST[$type . '_in_app'])
                ];
            }
            
            $preferencesModule->updatePreferences($userId, $preferences);
            $success = 'Notification preferences updated successfully!';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get current preferences
$currentPreferences = $preferencesModule->getUserPreferences($userId);

// Notification type labels
$notificationLabels = [
    'contact_created' => 'Contact Created',
    'contact_updated' => 'Contact Updated',
    'contact_deleted' => 'Contact Deleted',
    'task_assigned' => 'Task Assigned',
    'task_completed' => 'Task Completed',
    'task_overdue' => 'Task Overdue',
    'event_reminder' => 'Event Reminder',
    'event_created' => 'Event Created',
    'event_updated' => 'Event Updated',
    'deal_created' => 'Deal Created',
    'deal_updated' => 'Deal Updated',
    'deal_won' => 'Deal Won',
    'deal_lost' => 'Deal Lost',
    'email_received' => 'Email Received',
    'email_sent' => 'Email Sent',
    'whatsapp_message_received' => 'WhatsApp Message Received',
    'note_mentioned' => 'Mentioned in Note',
    'note_created' => 'Note Created',
    'workflow_triggered' => 'Workflow Triggered'
];

$pageTitle = 'Notification Preferences - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Notification Preferences</h1>
    <p style="color: var(--charcoal-grey);">Manage how you receive notifications</p>
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

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        
        <div style="margin-bottom: var(--spacing-lg);">
            <h2 style="color: var(--midnight-black); font-size: 18px; margin-bottom: var(--spacing-md);">Notification Types</h2>
            <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-lg);">Select how you want to receive notifications for each event type.</p>
            
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color);">
                        <th style="padding: var(--spacing-md); text-align: left; font-weight: 600; color: var(--midnight-black);">Notification Type</th>
                        <th style="padding: var(--spacing-md); text-align: center; font-weight: 600; color: var(--midnight-black);">Email</th>
                        <th style="padding: var(--spacing-md); text-align: center; font-weight: 600; color: var(--midnight-black);">In-App</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $notificationTypes = array_keys($notificationLabels);
                    foreach ($notificationTypes as $type): 
                        $pref = $currentPreferences[$type] ?? ['email_enabled' => true, 'in_app_enabled' => true];
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: var(--spacing-md);">
                                <label style="font-weight: 500; color: var(--midnight-black); cursor: pointer;">
                                    <?php echo htmlspecialchars($notificationLabels[$type]); ?>
                                </label>
                            </td>
                            <td style="padding: var(--spacing-md); text-align: center;">
                                <input 
                                    type="checkbox" 
                                    name="<?php echo $type; ?>_email" 
                                    value="1"
                                    <?php echo $pref['email_enabled'] ? 'checked' : ''; ?>
                                    style="width: 18px; height: 18px; cursor: pointer;"
                                >
                            </td>
                            <td style="padding: var(--spacing-md); text-align: center;">
                                <input 
                                    type="checkbox" 
                                    name="<?php echo $type; ?>_in_app" 
                                    value="1"
                                    <?php echo $pref['in_app_enabled'] ? 'checked' : ''; ?>
                                    style="width: 18px; height: 18px; cursor: pointer;"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-lg);">
            <a href="dashboard.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Save Preferences
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
