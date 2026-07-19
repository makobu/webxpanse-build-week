<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';

loadEnvFile(__DIR__ . '/../../.env');
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\DemoSessionScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

function presentationJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : $_POST;
}

function presentationJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function presentationJsonError(string $message, int $status = 400): void
{
    presentationJsonResponse(['success' => false, 'error' => $message], $status);
}

function presentationRequire(string $permission): array
{
    if (!Auth::check()) {
        presentationJsonError('Unauthorized', 401);
    }

    $user = Auth::user() ?: [];
    $operator = (new DemoSessionScopeService())->canOperateDemo($user);
    if (!$operator && !Authorization::can($permission, $user)) {
        presentationJsonError('Forbidden', 403);
    }

    return $user;
}

function presentationValidateCsrf(array $input): void
{
    $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrf === '' || !Security::validateCSRF($csrf)) {
        presentationJsonError('Invalid security token', 403);
    }
}
