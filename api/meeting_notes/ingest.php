<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Services\MeetingNoteTakerService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$configModule = new MeetingNoteTakerConfig();
$headerSecret = trim((string) ($_SERVER['HTTP_X_MEETING_NOTES_KEY'] ?? ''));
$querySecret = !empty($_ENV['ALLOW_MEETING_QUERY_SECRET_AUTH'])
    ? trim((string) ($_GET['key'] ?? ''))
    : '';
$providedSecret = $headerSecret !== '' ? $headerSecret : $querySecret;
$secretConfig = $providedSecret !== '' ? $configModule->findByIngestSecret($providedSecret) : null;
$secretAuthorized = $secretConfig !== null;
$workspaceId = $secretAuthorized ? (int) ($secretConfig['workspace_id'] ?? 0) : 0;

if ($providedSecret !== '' && !$secretAuthorized) {
    error_log('Meeting notes ingest unauthorized secret attempt from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
if ($querySecret !== '' && $headerSecret === '' && $secretAuthorized) {
    error_log('Meeting notes ingest used deprecated query-string secret for workspace ' . (string) $workspaceId);
}

$actorUserId = null;
if (!$secretAuthorized) {
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $user = Auth::user();
    if (!Authorization::can('settings.meeting_note_taker', $user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    $actorUserId = (int) ($user['id'] ?? 0);
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
}

if (empty($input['transcript']) && empty($input['summary']) && empty($input['notes']) && empty($input['transcript_text'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Transcript, notes, or summary is required.']);
    exit;
}

try {
    $result = (new MeetingNoteTakerService(workspaceId: $workspaceId))->ingest($input, $actorUserId);
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
