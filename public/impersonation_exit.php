<?php

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

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Services\PlatformImpersonationService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php');
    exit;
}

$context = (new PlatformImpersonationService())->end('Operator exited impersonation session.');
$target = !empty($context['actor_workspace_id'])
    ? 'workspace_admin.php?workspace_id=' . (int) $context['actor_workspace_id'] . '&notice=' . rawurlencode('Impersonation ended.')
    : 'workspaces.php?notice=' . rawurlencode('Impersonation ended.');

header('Location: ' . $target);
exit;
