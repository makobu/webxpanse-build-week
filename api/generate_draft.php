<?php
/**
 * Generate AI Draft API Endpoint
 */

// Disable error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ob_start();

// Set JSON header FIRST
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\EmailDraftGenerator;
use CRM\Modules\WhatsAppDraftGenerator;
use CRM\Modules\DraftReview;
use CRM\Security;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\EmailDraftOutputNormalizer;
use CRM\Services\EmailTemplateLearningSampleService;
use CRM\Services\WorkspaceScopeService;

try {
    // Initialize database
    Database::init(require __DIR__ . '/../config/database.php');
    
    // Start session
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Clear any output
    ob_clean();
    
    // Require authentication
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    Authorization::requirePermission('drafts.manage', true);
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    if ($method === 'POST') {
        $draftOutputNormalizer = new EmailDraftOutputNormalizer();
        // Verify CSRF token
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!Security::validateCSRF($csrfToken)) {
            ob_clean();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $contactId = (int) ($input['contact_id'] ?? 0);
        $type = $input['type'] ?? 'email';
        $purpose = $input['purpose'] ?? 'follow_up';
        $tone = $input['tone'] ?? 'professional';
        $options = $input['options'] ?? []; // Extract options, default to empty array for backward compatibility
        $mode = (string) ($input['mode'] ?? $options['mode'] ?? '');
        $intention = trim((string) ($input['intention'] ?? $options['intention'] ?? ''));
        $currentBody = trim((string) ($input['current_body'] ?? $input['body'] ?? $options['current_body'] ?? ''));
        if ($mode === '') {
            $mode = $currentBody !== '' ? 'polish_existing' : ($intention !== '' ? 'draft_from_intention' : '');
        }
        if ($intention !== '') {
            $options['intention'] = $intention;
        }
        if ($currentBody !== '') {
            $options['current_body'] = $currentBody;
        }
        if ($mode !== '') {
            $options['mode'] = $mode;
        }
        $saveAsDraft = $input['save_as_draft'] ?? false;
        $surface = (string) ($options['surface'] ?? '');
        $preferStructuredOutboundDraft = $type === 'email' && in_array($surface, ['email_compose', 'bulk_email'], true);
        $isIntentionEmailDraft = $type === 'email'
            && in_array($mode, ['draft_from_intention', 'polish_existing'], true);
        
        if (!$contactId && !$isIntentionEmailDraft) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contact ID required']);
            exit;
        }
        if ($isIntentionEmailDraft && $intention === '' && $currentBody === '') {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Tell the AI what you want to say, or provide an existing draft to polish.']);
            exit;
        }
        
        $replyResult = null;
        if (!$preferStructuredOutboundDraft && !$isIntentionEmailDraft) {
            try {
                $replyResult = (new CustomerReplyAssistantService())->generateDraftFromContact(
                    $contactId,
                    $type === 'whatsapp' ? 'whatsapp' : 'email',
                    (int) (Auth::user()['id'] ?? 0),
                    [
                        'surface' => ($options['surface'] ?? 'email_view'),
                        'goal' => 'draft',
                        'purpose' => $purpose,
                        'tone' => $tone,
                    ]
                );
            } catch (\Throwable $e) {
                $replyResult = null;
            }
        }

        if ($type === 'email') {
            $sourceDraft = [];
            $learningSampleId = 0;
            if ($isIntentionEmailDraft) {
                $generator = new EmailDraftGenerator();
                $sourceDraft = $generator->generateDraftFromIntention(
                    $contactId > 0 ? $contactId : null,
                    $intention,
                    $tone,
                    $options + ['purpose' => $purpose]
                );
                $draft = $draftOutputNormalizer->normalizeCanonicalDraft($sourceDraft);
                foreach (['source', 'draft_mode', 'fallback', 'ai_status'] as $metadataKey) {
                    if (array_key_exists($metadataKey, $sourceDraft)) {
                        $draft[$metadataKey] = $sourceDraft[$metadataKey];
                    }
                }
            } elseif (is_array($replyResult) && !empty($replyResult['draft'])) {
                $sourceDraft = (array) ($replyResult['draft'] ?? []);
                $normalizedDraft = $draftOutputNormalizer->normalizeCanonicalDraft((array) ($replyResult['draft'] ?? []));
                $draft = [
                    'subject' => (string) (($normalizedDraft['subject'] ?? '')),
                    'body_html' => (string) (($normalizedDraft['body_html'] ?? $normalizedDraft['html_body'] ?? '')),
                    'body_text' => (string) (($normalizedDraft['body_text'] ?? $normalizedDraft['plain_body'] ?? '')),
                    'tone' => $tone,
                    'personalized' => true,
                    'suggestions' => [],
                    'source' => (string) ($replyResult['source'] ?? 'email_assistant_v2'),
                    'policy' => (array) ($replyResult['policy'] ?? []),
                    'summary_text' => (string) ($replyResult['summary_text'] ?? ''),
                    'fallback' => (bool) ($replyResult['fallback'] ?? false),
                    'warning' => $replyResult['warning'] ?? null,
                ];
            } else {
                $generator = new EmailDraftGenerator();
                $sourceDraft = $generator->generateDraft($contactId, $purpose, $tone, $options);
                $draft = $draftOutputNormalizer->normalizeCanonicalDraft($sourceDraft);
            }

            $draft['signature_id'] = !empty($sourceDraft['signature_id']) ? (int) $sourceDraft['signature_id'] : null;
            $draft['signature_html'] = (string) ($sourceDraft['signature_html'] ?? '');
            $draft['signature_text'] = (string) ($sourceDraft['signature_text'] ?? '');
            $draft['body_includes_signature'] = (bool) ($sourceDraft['body_includes_signature'] ?? false);

            $bodyHtml = trim((string) ($draft['body_html'] ?? ''));
            $bodyText = trim((string) ($draft['body_text'] ?? ''));
            if ($bodyHtml === '' && $bodyText === '') {
                ob_clean();
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'The AI draft could not be safely prepared for display. Please try generating it again.'
                ], JSON_PRETTY_PRINT);
                exit;
            }
            if ($isIntentionEmailDraft && empty($draft['fallback'])) {
                try {
                    $learningSampleId = (new EmailTemplateLearningSampleService())->recordDraftGenerated(
                        (new WorkspaceScopeService())->requireActiveWorkspaceId(),
                        (int) (Auth::user()['id'] ?? 0),
                        $contactId > 0 ? $contactId : null,
                        $mode,
                        $intention !== '' ? $intention : $currentBody,
                        $draft,
                        [
                            'purpose' => $purpose,
                            'tone' => $tone,
                            'surface' => $surface,
                            'has_contact' => $contactId > 0,
                        ]
                    );
                } catch (\Throwable $e) {
                    error_log('Email learning draft sample skipped: ' . $e->getMessage());
                }
                if ($learningSampleId > 0) {
                    $draft['learning_sample_id'] = $learningSampleId;
                }
            }
        } else {
            if (is_array($replyResult) && !empty($replyResult['draft'])) {
                $draft = [
                    'message' => (string) (($replyResult['draft']['plain_body'] ?? '')),
                    'body_html' => (string) (($replyResult['draft']['html_body'] ?? '')),
                    'body_text' => (string) (($replyResult['draft']['plain_body'] ?? '')),
                    'tone' => $tone,
                    'personalized' => true,
                    'suggestions' => [],
                    'source' => (string) ($replyResult['source'] ?? 'fallback'),
                    'policy' => (array) ($replyResult['policy'] ?? []),
                    'summary_text' => (string) ($replyResult['summary_text'] ?? ''),
                    'fallback' => (bool) ($replyResult['fallback'] ?? false),
                    'warning' => $replyResult['warning'] ?? null,
                ];
            } else {
                $generator = new WhatsAppDraftGenerator();
                $draft = $generator->generateDraft($contactId, $purpose, $tone, $options);
            }
        }
        
        // Optionally save as draft for review
        $draftId = null;
        if ($saveAsDraft && $contactId > 0) {
            $draftReview = new DraftReview();
            $draftId = $draftReview->createDraft([
                'draft_type' => $type,
                'contact_id' => $contactId,
                'subject' => $draft['subject'] ?? null,
                'body' => $draft['body_html'] ?? $draft['body_text'] ?? $draft['body'] ?? '',
                'original_body' => $draft['body_html'] ?? $draft['body_text'] ?? $draft['body'] ?? '',
                'tone' => $tone
            ]);
        }
        
        ob_clean();
        $aiStatus = (array) ($draft['ai_status'] ?? []);
        if ($aiStatus === []) {
            $aiStatus = (new AIExecutionStatusService())->present([], [
                'surface' => 'assistant',
                'fallback' => (bool) ($draft['fallback'] ?? ($replyResult['fallback'] ?? false)),
                'success' => empty($draft['fallback']) && empty($replyResult['fallback']),
                'source' => (string) ($draft['source'] ?? ($replyResult['source'] ?? 'generator')),
                'message' => (string) ($draft['summary_text'] ?? ($replyResult['summary_text'] ?? '')),
            ]);
        }
        echo json_encode([
            'success' => true,
            'draft' => $draft,
            'draft_id' => $draftId,
            'learning_sample_id' => (int) ($draft['learning_sample_id'] ?? 0),
            'policy' => $draft['policy'] ?? (($replyResult['policy'] ?? []) ?: []),
            'summary_text' => $draft['summary_text'] ?? (($replyResult['summary_text'] ?? '') ?: ''),
            'fallback' => (bool) ($draft['fallback'] ?? ($replyResult['fallback'] ?? false)),
            'warning' => $draft['warning'] ?? ($replyResult['warning'] ?? null),
            'source' => $draft['source'] ?? ($replyResult['source'] ?? 'generator'),
            'ai_status' => $aiStatus,
        ], JSON_PRETTY_PRINT);
        
    } else {
        ob_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'AI draft generation failed. Please retry or continue writing manually.',
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'assistant',
            'blocked_reason' => 'request_failed',
            'message' => 'AI draft generation failed. Please retry or continue writing manually.',
        ]),
    ]);
    exit;
}
