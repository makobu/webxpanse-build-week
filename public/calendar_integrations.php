<?php
/**
 * Compatibility redirect for the retired standalone calendar connections page.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$params = [
    'module' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
    'setup_tab' => 'calendar',
];
foreach (['success', 'error', 'calendar_connected', 'calendar_updated'] as $key) {
    if (isset($_GET[$key])) {
        $params[$key] = (string) $_GET[$key];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $params['error'] = 'Calendar connections moved to Calendar & Meetings setup. Please retry there.';
}

header('Location: ' . getBasePath() . '/workspace_skills.php?' . http_build_query($params) . '#setup');
exit;
