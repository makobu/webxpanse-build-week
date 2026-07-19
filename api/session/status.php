<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$authenticated = Auth::check(false);
if (!$authenticated) {
    $state = Auth::currentAuthState();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'authenticated' => false,
        'state' => $state,
        'error' => $state === 'expired' ? 'Session expired' : 'Unauthorized',
        'auth' => Auth::authPayload($state, null, false),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $rawBody = (string) file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $csrfToken = (string) ($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Security::validateCSRF($csrfToken)) {
        Auth::jsonAuthError('csrf_invalid', 403, 'Invalid security token', false);
        exit;
    }
    Session::markAuthenticatedActivity();
}

$secondsRemaining = Session::secondsUntilIdleExpiry();
$expiresAt = $secondsRemaining !== null
    ? date('c', time() + max(0, (int) $secondsRemaining))
    : null;

echo json_encode([
    'success' => true,
    'authenticated' => true,
    'state' => 'authenticated',
    'renewed' => $method === 'POST',
    'seconds_remaining' => $secondsRemaining,
    'lifetime_seconds' => Session::lifetimeSeconds(),
    'expires_at' => $expiresAt,
    'csrf_token' => Security::getCsrfToken(),
    'login_url' => Auth::loginUrl(),
    'reauth_url' => Auth::loginUrl(null, false, true),
], JSON_UNESCAPED_SLASHES);
