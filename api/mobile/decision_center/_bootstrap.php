<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Authorization;

$mobileDecisionAuth = mobileRequireAuth();
$mobileDecisionUser = mobileCurrentUser($mobileDecisionAuth);
$mobileDecisionWorkspaceId = mobileWorkspaceId($mobileDecisionAuth);
$mobileDecisionUserId = (int) ($mobileDecisionAuth['user_id'] ?? 0);
$mobileDecisionInput = mobileRequestBody();

function mobileDecisionCan(string $permission): bool
{
    global $mobileDecisionUser;

    return Authorization::isSuperAdmin($mobileDecisionUser)
        || Authorization::can($permission, $mobileDecisionUser);
}

function mobileDecisionRequire(string $permission): void
{
    if (mobileDecisionCan($permission)) {
        return;
    }
    mobileJson([
        'success' => false,
        'error' => 'You do not have permission to use this Decision Center action.',
        'error_code' => 'permission_denied',
    ], 403);
}

function mobileDecisionRequireMethod(string ...$methods): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        mobileJson([
            'success' => false,
            'error' => 'Method not allowed.',
            'error_code' => 'method_not_allowed',
        ], 405);
    }

    return $method;
}

function mobileDecisionLabel(string $value): string
{
    $value = trim(str_replace('_', ' ', $value));

    return $value === '' ? '' : ucwords($value);
}

/** @return array<int,string> */
function mobileDecisionStringList(mixed $value, int $limit = 8): array
{
    $items = [];
    $walk = static function (mixed $candidate, string $key = '') use (&$walk, &$items, $limit): void {
        if (count($items) >= $limit) {
            return;
        }
        if (is_string($candidate) && trim($candidate) !== '') {
            $items[] = trim(($key !== '' && !is_numeric($key) ? mobileDecisionLabel($key) . ': ' : '') . $candidate);
            return;
        }
        if (is_bool($candidate) && $candidate && $key !== '') {
            $items[] = mobileDecisionLabel($key);
            return;
        }
        if (!is_array($candidate)) {
            return;
        }
        foreach ($candidate as $childKey => $child) {
            $walk($child, (string) $childKey);
            if (count($items) >= $limit) {
                break;
            }
        }
    };
    $walk($value);

    return array_values(array_unique(array_filter($items)));
}
