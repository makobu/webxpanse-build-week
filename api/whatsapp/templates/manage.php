<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
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
use CRM\Security;
use CRM\Session;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WhatsAppTemplateService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user();
$canManageConnection = (new WorkspaceConnectService())->canManageWhatsAppConnection($user, $workspaceId);
$canManageTemplates = $canManageConnection || Authorization::can('whatsapp.templates.manage', $user);
$canSubmitTemplates = $canManageConnection || Authorization::can('whatsapp.templates.submit', $user);
$service = new WhatsAppTemplateService();

try {
    if ($workspaceId <= 0) {
        throw new \RuntimeException('Workspace context is required.');
    }
    (new WhatsAppFeatureGate())->assertTemplateCenterEnabled($workspaceId);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        echo json_encode([
            'success' => true,
            'templates' => $service->listTemplates($workspaceId, [
                'status' => $_GET['status'] ?? null,
                'search' => $_GET['search'] ?? null,
            ]),
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!Security::validateCSRF((string) ($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
        exit;
    }

    $action = strtolower(trim((string) ($payload['action'] ?? 'list')));
    $templateId = (int) ($payload['template_id'] ?? $payload['id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);

    if ($action === 'save_draft') {
        if (!$canManageTemplates) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Template management permission is required.']);
            exit;
        }
        $template = $service->createOrUpdateDraft($workspaceId, $userId, normalizeWhatsAppTemplatePayload($payload));
        echo json_encode(['success' => true, 'template' => $template]);
        exit;
    }

    if ($action === 'submit') {
        if (!$canSubmitTemplates) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Template submission permission is required.']);
            exit;
        }
        $submission = $service->submitForApproval($workspaceId, $templateId, $userId);
        echo json_encode(['success' => true, 'submission' => $submission]);
        exit;
    }

    if ($action === 'clone') {
        if (!$canManageTemplates) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Template management permission is required.']);
            exit;
        }
        $template = $service->cloneForResubmission($workspaceId, $templateId, $userId);
        echo json_encode(['success' => true, 'template' => $template]);
        exit;
    }

    if ($action === 'sync') {
        if (!$canManageTemplates && !$canSubmitTemplates) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Template permission is required.']);
            exit;
        }
        $templates = $service->syncFromProvider($workspaceId);
        echo json_encode(['success' => true, 'templates' => $templates]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported template action.']);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function normalizeWhatsAppTemplatePayload(array $payload): array
{
    $components = $payload['components'] ?? null;
    if (is_string($components)) {
        $decoded = json_decode($components, true);
        $components = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($components) || $components === []) {
        $components = [];
        $headerText = trim((string) ($payload['header_text'] ?? ''));
        $bodyText = trim((string) ($payload['body_text'] ?? $payload['body'] ?? ''));
        $footerText = trim((string) ($payload['footer_text'] ?? ''));
        if ($headerText !== '') {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
        }
        if ($bodyText !== '') {
            $components[] = ['type' => 'BODY', 'text' => $bodyText];
        }
        if ($footerText !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footerText];
        }
    }

    $sampleValues = $payload['sample_values'] ?? [];
    if (is_string($sampleValues)) {
        $decoded = json_decode($sampleValues, true);
        $sampleValues = is_array($decoded) ? $decoded : [];
    }

    return [
        'template_id' => (int) ($payload['template_id'] ?? $payload['id'] ?? 0),
        'template_name' => (string) ($payload['template_name'] ?? $payload['name'] ?? ''),
        'name' => (string) ($payload['name'] ?? ''),
        'category' => (string) ($payload['category'] ?? 'utility'),
        'language_code' => (string) ($payload['language_code'] ?? $payload['language'] ?? 'en_US'),
        'language' => (string) ($payload['language'] ?? 'en_US'),
        'components' => $components,
        'header_type' => (string) ($payload['header_type'] ?? 'TEXT'),
        'header_text' => (string) ($payload['header_text'] ?? ''),
        'body_text' => (string) ($payload['body_text'] ?? $payload['body'] ?? ''),
        'footer_text' => (string) ($payload['footer_text'] ?? ''),
        'buttons_json' => (string) ($payload['buttons_json'] ?? ''),
        'sample_values' => is_array($sampleValues) ? $sampleValues : [],
        'sample_values_json' => is_array($sampleValues) ? json_encode($sampleValues, JSON_UNESCAPED_SLASHES) : '',
        'submission_notes' => (string) ($payload['submission_notes'] ?? ''),
    ];
}
