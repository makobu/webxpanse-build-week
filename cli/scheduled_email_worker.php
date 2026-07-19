<?php
/**
 * Scheduled Email Worker
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
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\EmailService;
use CRM\Modules\EmailScheduler;

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

$emailService = new EmailService();
$scheduler = new EmailScheduler();

echo "Scheduled email worker started...\n";

while (true) {
    $now = date('Y-m-d H:i:s');
    $scheduled = $scheduler->getScheduled();
    
    foreach ($scheduled as $email) {
        if ($email['scheduled_at'] <= $now) {
            $workspaceId = (int) ($email['workspace_id'] ?? $email['queue_workspace_id'] ?? 0);
            try {
                AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    static function () use ($emailService, $email, $workspaceId): bool {
                        return $emailService->processEmail((int) $email['id'], workspaceId: $workspaceId);
                    },
                    null,
                    'Scheduled email is missing a valid workspace.'
                );
            } catch (\Throwable $e) {
                error_log('Scheduled email worker failed for email_id=' . ($email['id'] ?? 'unknown') . ': ' . $e->getMessage());
            }
        }
    }
    
    sleep(60); // Check every minute
}
