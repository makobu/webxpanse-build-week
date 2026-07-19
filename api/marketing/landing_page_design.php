<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (!Auth::check()) {
    $respond(401, ['success' => false, 'error' => 'Unauthorized']);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    $respond(400, ['success' => false, 'error' => 'Invalid JSON request.']);
}
if (!Security::validateCSRF((string) ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    $respond(419, ['success' => false, 'error' => 'Invalid security token.']);
}

$user = Auth::user() ?: [];
$action = strtolower(trim((string) ($input['action'] ?? 'save')));
$requiredPermission = in_array($action, ['publish', 'unpublish'], true) ? 'marketing.manage' : 'marketing.write';
if (!Authorization::can($requiredPermission, $user)) {
    $respond(403, ['success' => false, 'error' => 'Forbidden']);
}

try {
    (new MarketingMarketplaceGateService())->assertCanRun($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
    $pageId = (int) ($input['page_id'] ?? 0);
    if ($pageId <= 0) {
        throw new InvalidArgumentException('A landing page is required.');
    }
    $marketing = new Marketing();
    $actorId = (int) ($user['id'] ?? 0) ?: null;

    if ($action === 'restore') {
        $editor = $marketing->restoreLandingPageDesignVersion(
            $pageId,
            (int) ($input['version_id'] ?? 0),
            (int) ($input['revision'] ?? -1),
            $actorId
        );
        $respond(200, ['success' => true, 'message' => 'Version restored as a new draft.', 'editor' => $editor]);
    }
    if ($action === 'publish') {
        $publication = $marketing->publishLandingPage($pageId, $actorId);
        $respond(200, [
            'success' => true,
            'message' => 'Published snapshot is live.',
            'publication' => $publication,
            'editor' => $marketing->getLandingPageDesignEditorData($pageId),
        ]);
    }
    if ($action === 'unpublish') {
        $marketing->unpublishLandingPage($pageId, $actorId);
        $respond(200, [
            'success' => true,
            'message' => 'Landing page unpublished.',
            'editor' => $marketing->getLandingPageDesignEditorData($pageId),
        ]);
    }
    if ($action !== 'save') {
        throw new InvalidArgumentException('Unsupported Design Studio action.');
    }

    $editor = $marketing->saveLandingPageDesign(
        $pageId,
        is_array($input['document'] ?? null) ? $input['document'] : [],
        is_array($input['page'] ?? null) ? $input['page'] : [],
        (int) ($input['revision'] ?? -1),
        $actorId
    );
    $respond(200, ['success' => true, 'message' => 'All changes saved.', 'editor' => $editor]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException))) {
        $status = str_contains(strtolower($e->getMessage()), 'changed in another session') ? 409 : 422;
        $respond($status, ['success' => false, 'error' => $e->getMessage()]);
    }
    error_log('Design Studio API failure: ' . $e->getMessage());
    $respond(500, ['success' => false, 'error' => 'Design Studio could not complete this request.']);
}
