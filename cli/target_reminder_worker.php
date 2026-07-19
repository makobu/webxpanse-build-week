<?php
// Deprecated compatibility entrypoint. Use the bounded, workspace-aware one-shot processor.
fwrite(STDERR, "target_reminder_worker.php is deprecated; running one bounded reminder pass.\n");
require __DIR__ . '/process_target_reminders.php';
exit;
/**
 * Target Reminder Worker
 * 
 * Processes and sends target reminders via notifications and email
 * Run: php cli/target_reminder_worker.php
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
use CRM\Modules\TargetReminders;
use CRM\Modules\Notifications;
use CRM\Modules\NotificationPreferences;
use CRM\Services\EmailService;

// Initialize database
$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

$remindersModule = new TargetReminders();
$notificationsModule = new Notifications();
$preferencesModule = new NotificationPreferences();
$emailService = new EmailService();

echo "Target Reminder Worker started...\n";
echo "Processing target reminders...\n\n";

$sleepInterval = 60; // Check every minute

while (true) {
    try {
        $dueReminders = $remindersModule->getDueReminders(50);
        
        if (empty($dueReminders)) {
            sleep($sleepInterval);
            continue;
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Processing " . count($dueReminders) . " reminder(s)\n";
        
        foreach ($dueReminders as $reminder) {
            try {
                $userId = (int) $reminder['user_id'];
                $targetId = (int) $reminder['target_id'];
                $reminderId = (int) $reminder['id'];
                
                // Get reminder message - $reminder already contains target data from the join
                $targetData = [
                    'title' => $reminder['title'],
                    'target_value' => $reminder['target_value'],
                    'current_value' => $reminder['current_value'],
                    'target_date' => $reminder['target_date'],
                    'unit' => $reminder['unit'] ?? ''
                ];
                $message = $remindersModule->getReminderMessage($reminder, $targetData);
                $title = "Target Reminder: " . $reminder['title'];
                
                // Check user preferences
                $prefs = $preferencesModule->getPreference($userId, 'target_reminder');
                $sendInApp = $prefs['in_app_enabled'] ?? true;
                $sendEmail = $prefs['email_enabled'] ?? true;
                
                $notificationId = null;
                
                // Send in-app notification
                if ($sendInApp) {
                    $notificationId = $notificationsModule->create(
                        $userId,
                        'target_reminder',
                        $title,
                        $message,
                        [
                            'entity_type' => 'target',
                            'entity_id' => $targetId,
                            'link' => "/crm/public/target_view.php?id=$targetId"
                        ]
                    );
                    echo "  ✓ In-app notification sent to user {$userId}\n";
                }
                
                // Send email notification
                if ($sendEmail) {
                    try {
                        // Get user email
                        $user = Database::queryOne(
                            "SELECT email FROM users WHERE id = ?",
                            [$userId]
                        );
                        
                            if ($user && !empty($user['email'])) {
                                // Create a minimal contact record for email service if needed
                                // Or send email directly - for now, we'll try to find or create a system contact
                                $contactId = getOrCreateSystemContact($userId, $user['email']);
                            
                            if ($contactId) {
                                $emailBody = $message . "\n\nView your target: " . 
                                    ($_ENV['APP_URL'] ?? 'http://localhost') . "/crm/public/target_view.php?id=$targetId";
                                
                                $emailService->send(
                                    $contactId,
                                    $user['email'],
                                    $title,
                                    $emailBody,
                                    [
                                        'user_id' => $userId,
                                        'body_html' => nl2br(htmlspecialchars($emailBody))
                                    ]
                                );
                                echo "  ✓ Email sent to {$user['email']}\n";
                            } else {
                                // Fallback: try to send email without contact_id by using a system approach
                                // For now, just log that we couldn't send email
                                echo "  ⚠ Could not create contact for email to {$user['email']}\n";
                            }
                        }
                    } catch (\Exception $e) {
                        echo "  ✗ Email error: " . $e->getMessage() . "\n";
                    }
                }
                
                // Mark reminder as sent
                $remindersModule->markAsSent($reminderId, $notificationId);
                
            } catch (\Exception $e) {
                echo "  ✗ Error processing reminder {$reminder['id']}: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
        sleep($sleepInterval);
        
    } catch (\Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        sleep($sleepInterval);
    }
}

/**
 * Get or create a system contact for user (for email service compatibility)
 */
function getOrCreateSystemContact(int $userId, string $email): ?int
{
    // Try to find existing contact for this user
    $contact = Database::queryOne(
        "SELECT id FROM contacts WHERE email = ? AND user_id = ? LIMIT 1",
        [$email, $userId]
    );
    
    if ($contact) {
        return (int) $contact['id'];
    }
    
    // Try to find any contact with this email
    $contact = Database::queryOne(
        "SELECT id FROM contacts WHERE email = ? LIMIT 1",
        [$email]
    );
    
    if ($contact) {
        return (int) $contact['id'];
    }
    
    // Create a minimal contact for system use
    try {
        $parts = explode('@', $email);
        $firstName = $parts[0];
        $lastName = '';
        
        Database::execute(
            "INSERT INTO contacts (first_name, last_name, email, created_at) 
             VALUES (?, ?, ?, NOW())",
            [$firstName, $lastName, $email]
        );
        
        return (int) Database::lastInsertId();
    } catch (\Exception $e) {
        // If contact creation fails, return null
        return null;
    }
}
