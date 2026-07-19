<?php

/** Complete a workspace-scoped Meta or LinkedIn OAuth connection. */

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\SocialMediaService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

$basePath = rtrim(getBasePath(), '/');
$setupUrl = $basePath . '/workspace_skills.php?module=social_media&setup_tab=connections#setup';
$appendQuery = static function (string $url, array $params): string {
    $fragment = '';
    $position = strpos($url, '#');
    if ($position !== false) {
        $fragment = substr($url, $position);
        $url = substr($url, 0, $position);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) . $fragment;
};

if (!Auth::check()) {
    header('Location: ' . $appendQuery($setupUrl, ['social_error' => 'Your session expired before the social connection completed.']));
    exit;
}

$user = Auth::user() ?: [];
if (!Authorization::isSuperAdmin($user) && !Authorization::canAny(['workspace.skills.manage', 'marketing.manage'], $user)) {
    header('Location: ' . $appendQuery($setupUrl, ['social_error' => 'Your access profile cannot connect social accounts.']));
    exit;
}

$provider = strtolower(trim((string) ($_GET['provider'] ?? '')));
$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$providerError = mb_substr(trim((string) ($_GET['error_description'] ?? $_GET['error_message'] ?? $_GET['error'] ?? '')), 0, 500);

try {
    if ($providerError !== '') {
        throw new RuntimeException('The provider did not authorize the connection: ' . $providerError);
    }
    $result = (new SocialMediaService())->completeOAuth($provider, $state, $code, (int) ($user['id'] ?? 0));
    $target = trim((string) ($result['return_path'] ?? '')) ?: $setupUrl;
    header('Location: ' . $appendQuery($target, [
        'social_connected' => (string) ($result['provider'] ?? $provider),
        'social_connected_count' => (int) ($result['connected_count'] ?? 0),
    ]));
    exit;
} catch (Throwable $e) {
    header('Location: ' . $appendQuery($setupUrl, ['social_error' => $e->getMessage()]));
    exit;
}
