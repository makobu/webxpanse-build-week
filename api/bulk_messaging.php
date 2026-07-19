<?php
/**
 * Bulk Messaging API
 *
 * Actions: preview | send_email | send_sms | send_whatsapp
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\BulkMessagingService;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\OperatorAuditService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse input: support both JSON and form data
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';
$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if (in_array($action, ['send_email', 'send_sms', 'send_whatsapp'], true)
    && (new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId)
    && !Authorization::isSuperAdmin($user)
) {
    try {
        (new OperatorAuditService())->log('default_workspace_bulk_message_blocked', (int) ($user['id'] ?? 0), $workspaceId, 'Non-Super Admin bulk messaging attempt from default workspace.', [
            'action' => $action,
        ]);
    } catch (\Throwable $ignored) {
    }
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Super Admin can bulk message from the default Platform Ops workspace.']);
    exit;
}
$communicationGate = new WorkspaceCommunicationGateService();
if ($action === 'send_email' && !$communicationGate->isChannelRuntimeReady($workspaceId, 'email', $user)) {
    ob_clean();
    http_response_code(403);
    echo json_encode($communicationGate->jsonChannelBlockPayload($workspaceId, 'email', $user), JSON_UNESCAPED_SLASHES);
    exit;
}
if ($action === 'send_whatsapp' && !$communicationGate->isChannelRuntimeReady($workspaceId, 'whatsapp', $user)) {
    ob_clean();
    http_response_code(403);
    echo json_encode($communicationGate->jsonChannelBlockPayload($workspaceId, 'whatsapp', $user), JSON_UNESCAPED_SLASHES);
    exit;
}
$installer = new WorkspaceSkillInstallService();
$catalog = new WorkspaceSkillCatalogService();
if ($action === 'send_sms'
    && ($catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)
        || (!Authorization::isSuperAdmin($user) && !$installer->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)))) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'SMS Channel is not installed for this workspace.']);
    exit;
}
if ($action === 'send_sms' && !Authorization::can('sms.bulk_send', $user)) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Bulk SMS permission is required.']);
    exit;
}
$filters = $input['filters'] ?? [];
if (is_string($filters)) {
    $filters = json_decode($filters, true) ?? [];
}

// Support contact_ids from query string (e.g. from contacts page)
if (!empty($_GET['contact_ids'])) {
    $filters['contact_ids'] = array_map('intval', explode(',', $_GET['contact_ids']));
} elseif (!empty($input['contact_ids'])) {
    $contactIdsRaw = is_array($input['contact_ids']) ? $input['contact_ids'] : json_decode($input['contact_ids'], true);
    $filters['contact_ids'] = is_array($contactIdsRaw) ? array_map('intval', $contactIdsRaw) : [];
}

// Normalize filter keys
if (isset($filters['tag_id']) && $filters['tag_id'] !== '') {
    $filters['tag_id'] = (int) $filters['tag_id'];
}
if (isset($filters['stage']) && $filters['stage'] === '') {
    unset($filters['stage']);
}
if (isset($filters['assigned_to']) && $filters['assigned_to'] === '') {
    unset($filters['assigned_to']);
}
if (isset($filters['company_id']) && $filters['company_id'] === '') {
    unset($filters['company_id']);
} elseif (isset($filters['company_id'])) {
    $filters['company_id'] = (int) $filters['company_id'];
}
if (isset($filters['search']) && trim($filters['search'] ?? '') === '') {
    unset($filters['search']);
}
// Normalize contact_ids inside filters — when sent as hidden form fields the
// array is JSON-encoded as a string; getByFilters() requires a real array.
if (isset($filters['contact_ids']) && !is_array($filters['contact_ids'])) {
    $decoded = json_decode($filters['contact_ids'], true);
    $filters['contact_ids'] = is_array($decoded) ? array_map('intval', $decoded) : [];
}

try {
    $bulkService = new BulkMessagingService();

    switch ($action) {
        case 'preview':
            $count = $bulkService->countContacts($filters);
            $emailCount = $bulkService->countContacts($filters, 'email');
            $phoneCount = $bulkService->countContacts($filters, 'phone');
            $sampleContacts = $bulkService->resolveContacts($filters, 10, 0);

            ob_clean();
            echo json_encode([
                'success' => true,
                'total' => $count,
                'with_email' => $emailCount,
                'with_phone' => $phoneCount,
                'sample' => $sampleContacts,
            ], JSON_PRETTY_PRINT);
            break;

        case 'send_email':
            $subject = trim($input['subject'] ?? '');
            $body = $input['body_text'] ?? $input['body'] ?? '';
            $bodyHtml = $input['body_html'] ?? $body;

            if (empty($subject)) {
                ob_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Subject is required']);
                exit;
            }
            if (empty($body) && empty($bodyHtml)) {
                ob_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email body is required']);
                exit;
            }

            $contacts = $bulkService->resolveContacts($filters, 500, 0);
            $contactIds = array_column($contacts, 'id');

            if (empty($contactIds)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'No contacts match the selected filters']);
                exit;
            }

            $options = ['body_html' => $bodyHtml ?: $body];
            if (!empty($input['scheduled_at'])) {
                $options['scheduled_at'] = $input['scheduled_at'];
            }
            $aiCustomizeRaw = $input['ai_customize'] ?? 0;
            $options['ai_customize'] = in_array((string) $aiCustomizeRaw, ['1', 'true', 'on', 'yes'], true);
            if ($options['ai_customize']) {
                $options['ai_purpose'] = trim((string) ($input['ai_purpose'] ?? 'follow_up')) ?: 'follow_up';
                $options['ai_tone'] = trim((string) ($input['ai_tone'] ?? 'professional')) ?: 'professional';
                $options['ai_instructions'] = trim((string) ($input['ai_instructions'] ?? ''));
            }

            $result = $bulkService->bulkSendEmail($contactIds, $subject, $body ?: strip_tags($bodyHtml), $options);

            ob_clean();
            echo json_encode([
                'success' => true,
                'result' => $result->toArray(),
            ], JSON_PRETTY_PRINT);
            break;

        case 'send_sms':
            $message = trim($input['message'] ?? '');
            $consentConfirmed = in_array(strtolower((string) ($input['sms_consent_confirmed'] ?? '')), ['1', 'true', 'on', 'yes'], true);
            $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

            if (empty($message)) {
                ob_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message is required']);
                exit;
            }
            if (!$consentConfirmed) {
                ob_clean();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Confirm that these recipients may receive this SMS.']);
                exit;
            }
            if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 255) {
                ob_clean();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'A valid SMS idempotency key is required.']);
                exit;
            }

            $contacts = $bulkService->resolveContacts($filters, 500, 0);
            $contactIds = array_column($contacts, 'id');

            if (empty($contactIds)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'No contacts match the selected filters']);
                exit;
            }

            $options = [
                'workspace_id' => $workspaceId,
                'idempotency_key' => $idempotencyKey,
                'consent_confirmed_at' => date('Y-m-d H:i:s'),
                'consent_source' => 'bulk_sms_attestation',
            ];
            if (!empty($input['scheduled_at'])) {
                $options['scheduled_at'] = $input['scheduled_at'];
            }

            $result = $bulkService->bulkSendSMS($contactIds, $message, $options);

            ob_clean();
            echo json_encode([
                'success' => true,
                'result' => $result->toArray(),
            ], JSON_PRETTY_PRINT);
            break;

        case 'send_whatsapp':
            $message = trim($input['message'] ?? '');
            $messageType = $input['message_type'] ?? 'text';
            $templateName = $input['template_name'] ?? null;
            $templateParams = $input['template_params'] ?? null;

            if (empty($message) && ($messageType !== 'template' || empty($templateName))) {
                ob_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message or template is required']);
                exit;
            }

            $contacts = $bulkService->resolveContacts($filters, 500, 0);
            $contactIds = array_values(array_unique(array_column($contacts, 'id')));

            if (empty($contactIds)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'No contacts match the selected filters']);
                exit;
            }

            $options = [];
            if (!empty($input['scheduled_at'])) {
                $options['scheduled_at'] = $input['scheduled_at'];
            }
            if ($templateName) {
                $options['template_name'] = $templateName;
                $options['template_params'] = is_array($templateParams) ? $templateParams : json_decode($templateParams ?? '{}', true);
                $options['template_params'] = is_array($options['template_params']) ? $options['template_params'] : [];
                $languageCode = $input['language_code'] ?? $options['template_params']['_language'] ?? 'en_US';
                $options['template_params']['_language'] = $languageCode;
            }

            $body = $messageType === 'template' ? ($message ?: 'Template: ' . $templateName) : $message;
            $result = $bulkService->bulkSendWhatsApp($contactIds, $body, $messageType, $options);

            ob_clean();
            echo json_encode([
                'success' => true,
                'result' => $result->toArray(),
            ], JSON_PRETTY_PRINT);
            break;

        default:
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action. Use: preview, send_email, send_sms, send_whatsapp']);
    }
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
