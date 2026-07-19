<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../_feature_helpers.php';
require_once __DIR__ . '/../_conversation_resolver.php';

use CRM\Modules\Contacts;
use CRM\Modules\EmailSignatures;
use CRM\Services\EmailTemplates;
use CRM\Services\WhatsAppTemplateService;

function mobileTemplateContactVariables(array $contact): array
{
    $fullName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));

    return [
        'first_name' => (string) ($contact['first_name'] ?? ''),
        'last_name' => (string) ($contact['last_name'] ?? ''),
        'full_name' => $fullName,
        'name' => $fullName,
        'email' => (string) ($contact['email'] ?? ''),
        'phone' => (string) ($contact['phone'] ?? ''),
        'company' => (string) ($contact['company'] ?? ''),
        'job_title' => (string) ($contact['job_title'] ?? ''),
    ];
}

function mobileRenderWhatsAppPreview(array $template, array $variables): string
{
    $body = (string) ($template['body_text'] ?? $template['body'] ?? '');
    foreach ($variables as $key => $value) {
        $body = str_replace('{' . $key . '}', (string) $value, $body);
    }

    return preg_replace('/\{[^}]+\}/', '', $body) ?? $body;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$userId = (int) ($auth['user_id'] ?? 0);
mobileRequireCommunicationRuntime($workspaceId, $user);

try {
    $input = mobileRequestBody();
    $channel = strtolower(trim((string) ($input['channel'] ?? 'email')));
    $contactId = (int) ($input['contact_id'] ?? 0);
    $contact = $contactId > 0 ? (new Contacts())->getById($contactId) : null;
    if ($contactId > 0 && (!$contact || !mobileCanAccessContact($contact, $userId, $user))) {
        mobileJson(['error' => 'Contact not found or not accessible.'], 404);
    }
    $variables = array_merge(
        $contact ? mobileTemplateContactVariables($contact) : [],
        is_array($input['variables'] ?? null) ? $input['variables'] : []
    );

    if ($channel === 'email') {
        $templates = new EmailTemplates();
        $template = null;
        if (!empty($input['template_id'])) {
            $template = $templates->getSendableTemplateById((int) $input['template_id'], $userId);
        }
        if (!$template && trim((string) ($input['template_slug'] ?? '')) !== '') {
            $template = $templates->getSendableTemplateBySlug((string) $input['template_slug'], $userId);
        }
        if (!$template) {
            mobileJson(['error' => 'Template not found.'], 404);
        }
        $rendered = $templates->renderTemplateRecord($template, $variables, (string) ($template['slug'] ?? 'mobile_template'));
        $signature = null;
        if (!empty($input['signature_id'])) {
            foreach ((new EmailSignatures())->getUserSignaturesForUser($userId) as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === (int) $input['signature_id']) {
                    $signature = $candidate;
                    break;
                }
            }
        } else {
            $signature = (new EmailSignatures())->getDefaultForUser($userId);
        }

        mobileJson([
            'success' => true,
            'data' => [
                'channel' => 'email',
                'template' => mobileEmailTemplateSummary($template),
                'subject' => (string) ($rendered['subject'] ?? ''),
                'body_html' => trim((string) ($rendered['body_html'] ?? '') . "\n" . (string) ($signature['content_html'] ?? '')),
                'body_text' => trim((string) ($rendered['body_text'] ?? '') . "\n\n" . (string) ($signature['content_text'] ?? '')),
                'signature' => $signature ? mobileEmailSignatureSummary($signature) : null,
            ],
        ]);
    }

    if ($channel === 'whatsapp') {
        $templateId = (int) ($input['template_id'] ?? 0);
        $templateName = trim((string) ($input['template_name'] ?? $input['name'] ?? ''));
        $template = null;
        foreach ((new WhatsAppTemplateService())->approvedForSending($workspaceId) as $candidate) {
            if (($templateId > 0 && (int) ($candidate['id'] ?? 0) === $templateId)
                || ($templateName !== '' && (string) ($candidate['template_name'] ?? $candidate['name'] ?? '') === $templateName)) {
                $template = $candidate;
                break;
            }
        }
        if (!$template) {
            mobileJson(['error' => 'WhatsApp template not found.'], 404);
        }

        mobileJson([
            'success' => true,
            'data' => [
                'channel' => 'whatsapp',
                'template' => mobileWhatsAppTemplateSummary($template),
                'body_text' => mobileRenderWhatsAppPreview($template, $variables),
            ],
        ]);
    }

    mobileJson(['error' => 'Unsupported template channel.'], 422);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'template_preview_failed',
    ], 422);
}
