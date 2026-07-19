<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Modules\EmailSignatures;
use CRM\Services\EmailTemplates;
use CRM\Services\WhatsAppTemplateService;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$userId = (int) ($auth['user_id'] ?? 0);

mobileRequireCommunicationRuntime($workspaceId, $user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

try {
    $category = trim((string) ($_GET['category'] ?? '')) ?: null;
    $emailTemplates = (new EmailTemplates())->getSendableTemplatesForUser($userId, $category);
    $signatures = (new EmailSignatures())->getUserSignaturesForUser($userId);
    $whatsappTemplates = (new WhatsAppTemplateService())->approvedForSending($workspaceId);

    mobileJson([
        'success' => true,
        'data' => [
            'email_templates' => array_map('mobileEmailTemplateSummary', $emailTemplates),
            'email_signatures' => array_map('mobileEmailSignatureSummary', $signatures),
            'whatsapp_templates' => array_map('mobileWhatsAppTemplateSummary', $whatsappTemplates),
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'communication_templates_failed',
    ], 422);
}
