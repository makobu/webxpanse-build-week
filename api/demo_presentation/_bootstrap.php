<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\DemoSessionScopeService;

function demoPresentationRequire(string $permission): array
{
    if (!Auth::check()) {
        demoJsonError('Unauthorized', 401);
    }

    $user = Auth::user() ?: [];
    $operator = (new DemoSessionScopeService())->canOperateDemo($user);
    if (!$operator && !Authorization::can($permission, $user)) {
        demoJsonError('Forbidden', 403);
    }

    return $user;
}

function demoPresentationValidateCsrf(array $input): void
{
    $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrf === '' || !Security::validateCSRF($csrf)) {
        demoJsonError('Invalid security token', 403);
    }
}
