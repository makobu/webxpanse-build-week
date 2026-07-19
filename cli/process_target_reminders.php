<?php
/**
 * Target Reminder Processor (one-shot)
 *
 * SiteGround-safe cron entrypoint for due target reminders.
 * Usage: php cli/process_target_reminders.php [limit]
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

use CRM\Database;
use CRM\Modules\NotificationPreferences;
use CRM\Modules\Notifications;
use CRM\Modules\TargetReminders;
use CRM\Services\EmailService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');

$limit = max(1, min(200, isset($argv[1]) ? (int) $argv[1] : 50));
$remindersModule = new TargetReminders();
$notificationsModule = new Notifications();
$preferencesModule = new NotificationPreferences();
$emailService = new EmailService();

$processed = 0;
$workspaces = Database::query('SELECT id FROM workspaces ORDER BY id ASC');
foreach ($workspaces as $workspace) {
    if ($processed >= $limit) {
        break;
    }
    $workspaceId = (int) $workspace['id'];
    $snapshot = WorkspaceContext::runtimeSnapshot();
    WorkspaceContext::activateRuntimeWorkspace($workspaceId, null, 'system');
    $dueReminders = $remindersModule->getDueReminders($limit - $processed, $workspaceId);
    foreach ($dueReminders as $reminder) {
    try {
        $userId = (int) $reminder['user_id'];
        $targetId = (int) $reminder['target_id'];
        $reminderId = (int) $reminder['id'];

        $targetData = [
            'title' => $reminder['title'],
            'target_value' => $reminder['target_value'],
            'current_value' => $reminder['current_value'],
            'target_date' => $reminder['target_date'],
            'unit' => $reminder['unit'] ?? ''
        ];
        $message = $remindersModule->getReminderMessage($reminder, $targetData);
        $title = 'Target Reminder: ' . $reminder['title'];

        $prefs = $preferencesModule->getPreference($userId, 'target_reminder');
        $sendInApp = $prefs['in_app_enabled'] ?? true;
        $sendEmail = $prefs['email_enabled'] ?? true;
        $notificationId = null;

        if ($sendInApp && empty($reminder['notification_id'])) {
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
        }

        $providerResponse = ['in_app' => $notificationId ? 'sent' : ($sendInApp ? 'existing' : 'disabled')];
        if ($sendEmail) {
            $user = Database::queryOne(
                "SELECT u.email FROM users u INNER JOIN workspace_memberships wm ON wm.user_id=u.id AND wm.workspace_id=? AND wm.membership_status='active' WHERE u.id=? LIMIT 1",
                [$workspaceId, $userId]
            );
            if ($user && !empty($user['email'])) {
                $emailBody = $message . "\n\nView your target: "
                    . ($_ENV['APP_URL'] ?? 'http://localhost') . "/crm/public/target_view.php?id=$targetId";
                $emailResult = $emailService->sendImmediateDetailed(0, (string) $user['email'], $title, $emailBody, [
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'body_html' => nl2br(htmlspecialchars($emailBody)),
                    'draft_source' => 'target_reminder',
                    'draft_intention' => (string) ($reminder['delivery_key'] ?? ''),
                ]);
                $providerResponse['email'] = $emailResult;
                if (empty($emailResult['success'])) {
                    throw new \RuntimeException((string) ($emailResult['error'] ?? 'Target reminder email failed.'));
                }
            } else {
                $providerResponse['email'] = 'no_active_workspace_recipient';
            }
        }

        $remindersModule->markAsSent($reminderId, $notificationId ?: ($reminder['notification_id'] ?? null), $workspaceId, $providerResponse);
        $processed++;
    } catch (\Throwable $e) {
        error_log('process_target_reminders item failed: ' . $e->getMessage());
        $remindersModule->markFailed((int) ($reminder['id'] ?? 0), $workspaceId, $e->getMessage());
    }
    }
    WorkspaceContext::restoreRuntimeWorkspace($snapshot);
}

echo sprintf("[%s] Target reminder run complete: processed=%d\n", date('Y-m-d H:i:s'), $processed);
