<?php
/**
 * Controlled Marketing live queue dispatcher.
 *
 * Usage:
 *   php scripts/run_marketing_live_dispatcher.php --workspace-id=1 --user-id=1 --limit=10
 *
 * The dispatcher only processes due live queue items that were already approved
 * and explicitly confirmed for dispatch inside the app.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Session;
use CRM\Services\WorkspaceContext;

function marketingLiveDispatcherUsage(int $exitCode = 0): void
{
    $message = <<<TXT
Marketing Live Queue Dispatcher

Required:
  --workspace-id=<id>   Workspace to process.
  --user-id=<id>        User with marketing.manage in that workspace.

Optional:
  --limit=<n>           Max due confirmed live queue items to process. Default: 10, max: 100.
  --source=<source>     cli, cron, api, manual, or test. Default: cli.
  --json               Output the full dispatch run as JSON.
  --help               Show this help.

Safety:
  - CLI/cron/API sources require the dispatcher schedule to be active in Marketing Execution.
  - Processes only execution_mode=live items with live_dispatch_status=confirmed.
  - Each item still requires live policy, ready connector, verified secret reference,
    passed live preflight, explicit approval, RUN LIVE confirmation, idempotency, and
    a clear emergency stop.
  - No raw secrets are printed.

TXT;
    fwrite($exitCode === 0 ? STDOUT : STDERR, $message);
    exit($exitCode);
}

$options = getopt('', [
    'workspace-id:',
    'user-id:',
    'limit::',
    'source::',
    'json',
    'help',
]);

if (isset($options['help'])) {
    marketingLiveDispatcherUsage(0);
}

$workspaceId = (int) ($options['workspace-id'] ?? 0);
$userId = (int) ($options['user-id'] ?? 0);
if ($workspaceId <= 0 || $userId <= 0) {
    marketingLiveDispatcherUsage(1);
}

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

try {
    $user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user) {
        throw new RuntimeException('Dispatcher user was not found.');
    }

    $membership = Database::queryOne(
        "SELECT role_slug, is_owner
         FROM workspace_memberships
         WHERE workspace_id = ?
           AND user_id = ?
           AND membership_status = 'active'
         LIMIT 1",
        [$workspaceId, $userId]
    );
    if (!$membership) {
        throw new RuntimeException('Dispatcher user is not an active member of the workspace.');
    }

    $role = !empty($membership['is_owner']) ? 'owner' : (string) ($membership['role_slug'] ?? 'viewer');
    Session::set('user_id', $userId);
    WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, $role);

    if (!Authorization::can('marketing.manage', $user)) {
        throw new RuntimeException('Dispatcher user does not have marketing.manage.');
    }

    $marketing = new Marketing();
    $run = $marketing->runLiveExecutionDispatcher([
        'source' => (string) ($options['source'] ?? 'cli'),
        'limit_count' => (int) ($options['limit'] ?? 10),
        'requested_by' => $userId,
    ]);

    if (isset($options['json'])) {
        echo json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo sprintf(
            "Marketing live dispatcher run #%d %s: processed=%d succeeded=%d blocked=%d failed=%d\n",
            (int) ($run['id'] ?? 0),
            (string) ($run['status'] ?? 'completed'),
            (int) ($run['processed_count'] ?? 0),
            (int) ($run['succeeded_count'] ?? 0),
            (int) ($run['blocked_count'] ?? 0),
            (int) ($run['failed_count'] ?? 0)
        );
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Marketing live dispatcher failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
