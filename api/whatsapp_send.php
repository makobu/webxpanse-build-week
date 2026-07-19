<?php
/**
 * WhatsApp Send Message API Endpoint
 * 
 * This endpoint allows you to send business-initiated WhatsApp messages.
 * 
 * IMPORTANT: Business-initiated messages require approved template messages.
 * Regular text messages only work within 24 hours of customer's last message.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment and initialize
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\ColdOutreachGovernanceService;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\WhatsAppService;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$csrfToken = (string) ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}
Authorization::requirePermission('whatsapp.messages.send', true);

try {
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

    // Validate required fields
    $contactId = $input['contact_id'] ?? null;
    $phoneNumber = $input['phone_number'] ?? null;
    $messageType = $input['message_type'] ?? 'template'; // 'template', 'text', 'image', 'video'
    
    if (!$contactId && !$phoneNumber) {
        http_response_code(400);
        echo json_encode(['error' => 'Either contact_id or phone_number is required']);
        exit;
    }
    
    // Get phone number if contact_id provided
    if ($contactId && !$phoneNumber) {
        $contact = Database::queryOne(
            "SELECT id, phone FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        
        if (!$contact) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            exit;
        }
        
        if (empty($contact['phone'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact does not have a phone number']);
            exit;
        }
        
        $phoneNumber = $contact['phone'];
        $contactId = $contact['id'];
    }
    
    // Format phone number: remove all non-digit characters
    // WhatsApp API accepts both formats: with + or without
    $phoneNumber = preg_replace('/[^\d+]/', '', $phoneNumber);
    
    // Remove + if present (WhatsApp API works with or without it)
    // Based on the working example, we'll send without +
    $phoneNumber = ltrim($phoneNumber, '+');
    
    // Remove leading zeros
    $phoneNumber = ltrim($phoneNumber, '0');
    
    $whatsappService = new WhatsAppService();
    $coldOutreachGovernance = new ColdOutreachGovernanceService();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Handle different message types
    if ($messageType === 'template') {
        // Business-initiated message using template
        $templateName = $input['template_name'] ?? null;
        $templateParams = $input['template_params'] ?? [];
        $languageCode = $input['language_code'] ?? 'en_US';
        
        if (!$templateName) {
            http_response_code(400);
            echo json_encode([
                'error' => 'template_name is required for business-initiated messages',
                'note' => 'You must use an approved WhatsApp template. Regular text messages only work within 24 hours of customer\'s last message.'
            ]);
            exit;
        }
        
        // Build template components with correct parameter types (fixes error 132012)
        $templateStructure = $input['template_structure'] ?? null;
        if (is_string($templateStructure)) {
            $templateStructure = json_decode($templateStructure, true) ?: null;
        }
        if (!$templateStructure) {
            $templateStructure = $whatsappService->getTemplateByName($templateName, $languageCode);
        }
        $components = $whatsappService->buildTemplateComponents($templateParams, $templateStructure);
        
        // Store message in database (include _language for worker, whatsapp_message_id for status updates)
        $paramsForStore = $templateParams;
        $paramsForStore['_language'] = $languageCode;
        $messageBody = $input['message'] ?? "Template: {$templateName}";
        $dispatch = $contactId
            ? $coldOutreachGovernance->planDispatch('whatsapp', (int) $contactId, null, 'whatsapp_api', ['message_type' => 'template'])
            : ['was_deferred' => false, 'reservation_id' => null, 'scheduled_at' => null];
        $storeOptions = [
            'user_id' => $userId,
            'template_name' => $templateName,
            'template_params' => $paramsForStore,
        ];
        $responseMessage = 'Template message sent successfully';
        $providerMessageId = null;

        if (!empty($dispatch['was_deferred'])) {
            $storeOptions['scheduled_at'] = $dispatch['scheduled_at'] ?? null;
            $responseMessage = 'Template message scheduled for the next available cold outreach slot';
        } else {
            $result = $whatsappService->sendTemplateMessage(
                $phoneNumber,
                $templateName,
                $languageCode,
                $components
            );
            $providerMessageId = $result['messages'][0]['id'] ?? null;
            $storeOptions['whatsapp_message_id'] = $providerMessageId;
        }

        $uuid = $whatsappService->storeMessage($contactId, $phoneNumber, 'template', $messageBody, $storeOptions);
        if (!empty($dispatch['reservation_id'])) {
            $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
            $coldOutreachGovernance->attachReservation(
                (int) ($dispatch['reservation_id'] ?? 0),
                !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                $uuid
            );
            if (!empty($providerMessageId) && !empty($messageRow['id'])) {
                $coldOutreachGovernance->markByEntity('whatsapp', (int) $messageRow['id'], 'sent');
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $responseMessage,
            'uuid' => $uuid,
            'whatsapp_message_id' => $providerMessageId,
            'note' => 'Template messages are business-initiated and can be sent anytime (no 24-hour window restriction)'
        ], JSON_PRETTY_PRINT);
        
    } elseif (in_array($messageType, ['image', 'video'], true)) {
        // Session media message (only works within 24-hour window)
        if (!$contactId) {
            http_response_code(400);
            echo json_encode(['error' => 'contact_id is required for image/video messages (for 24h window check)']);
            exit;
        }
        if (!$whatsappService->isWithin24HourWindow($contactId)) {
            http_response_code(400);
            echo json_encode([
                'error' => '24-hour window closed',
                'note' => 'Image/video messages only work within 24 hours of customer\'s last message. Use message_type=template for business-initiated messages.'
            ]);
            exit;
        }
        $mediaUrl = $input['media_url'] ?? null;
        $mediaBase64 = $input['media_base64'] ?? null;
        $mediaFilename = $input['media_filename'] ?? 'file';
        $caption = $input['caption'] ?? null;

        if (!$mediaUrl && !$mediaBase64) {
            http_response_code(400);
            echo json_encode(['error' => 'media_url or media_base64 is required for image/video messages']);
            exit;
        }

        $mediaIdOrUrl = null;
        if ($mediaUrl) {
            if (!filter_var($mediaUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $mediaUrl)) {
                http_response_code(400);
                echo json_encode(['error' => 'media_url must be a valid http(s) URL']);
                exit;
            }
            $mediaIdOrUrl = $mediaUrl;
        } else {
            $decoded = base64_decode($mediaBase64, true);
            if ($decoded === false || strlen($decoded) > 16 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'media_base64 must be valid base64, max 16MB']);
                exit;
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'wa_');
            if ($tmpFile === false || file_put_contents($tmpFile, $decoded) === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to process media']);
                exit;
            }
            $mimeType = $messageType === 'image' ? 'image/jpeg' : 'video/mp4';
            if (preg_match('/\.(png|gif|webp)$/i', $mediaFilename)) {
                $mimeType = 'image/' . strtolower(pathinfo($mediaFilename, PATHINFO_EXTENSION));
            } elseif (preg_match('/\.(3gp|quicktime)$/i', $mediaFilename)) {
                $mimeType = 'video/3gpp';
            }
            $mediaIdOrUrl = $whatsappService->uploadMedia($tmpFile, $mimeType);
            @unlink($tmpFile);
            if (!$mediaIdOrUrl) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to upload media to WhatsApp']);
                exit;
            }
        }

        if ($messageType === 'image') {
            $result = $whatsappService->sendImageMessage($phoneNumber, $mediaIdOrUrl, $caption);
        } else {
            $result = $whatsappService->sendVideoMessage($phoneNumber, $mediaIdOrUrl, $caption);
        }

        $storeBody = $caption ?: ($messageType === 'image' ? '[Image]' : '[Video]');
        $uuid = $whatsappService->storeMessage(
            $contactId,
            $phoneNumber,
            $messageType,
            $storeBody,
            [
                'user_id' => $userId,
                'whatsapp_message_id' => $result['messages'][0]['id'] ?? null
            ]
        );

        echo json_encode([
            'status' => 'success',
            'message' => ucfirst($messageType) . ' message sent successfully',
            'uuid' => $uuid,
            'whatsapp_message_id' => $result['messages'][0]['id'] ?? null,
            'note' => 'Media messages only work within 24 hours of customer\'s last message'
        ], JSON_PRETTY_PRINT);

    } else {
        // Session text message (only works within 24-hour window)
        $message = $input['message'] ?? null;
        
        if (!$message) {
            http_response_code(400);
            echo json_encode([
                'error' => 'message is required for text messages',
                'note' => 'Text messages only work within 24 hours of customer\'s last message. For business-initiated messages, use message_type=template with an approved template.'
            ]);
            exit;
        }

        if ($contactId && !$whatsappService->isWithin24HourWindow($contactId)) {
            http_response_code(400);
            echo json_encode([
                'error' => '24-hour window closed',
                'note' => 'Text messages only work within 24 hours of customer\'s last message.'
            ]);
            exit;
        }
        
        $dispatch = $contactId
            ? $coldOutreachGovernance->planDispatch('whatsapp', (int) $contactId, null, 'whatsapp_api', ['message_type' => 'text'])
            : ['was_deferred' => false, 'reservation_id' => null, 'scheduled_at' => null];
        $providerMessageId = null;
        $responseMessage = 'Text message sent successfully';
        $storeOptions = ['user_id' => $userId];
        if (!empty($dispatch['was_deferred'])) {
            $storeOptions['scheduled_at'] = $dispatch['scheduled_at'] ?? null;
            $responseMessage = 'Text message scheduled for the next available cold outreach slot';
        } else {
            $result = $whatsappService->sendTextMessage($phoneNumber, $message);
            $providerMessageId = $result['messages'][0]['id'] ?? null;
            $storeOptions['whatsapp_message_id'] = $providerMessageId;
        }
        
        $uuid = $whatsappService->storeMessage($contactId, $phoneNumber, 'text', $message, $storeOptions);
        if (!empty($dispatch['reservation_id'])) {
            $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
            $coldOutreachGovernance->attachReservation(
                (int) ($dispatch['reservation_id'] ?? 0),
                !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                $uuid
            );
            if (!empty($providerMessageId) && !empty($messageRow['id'])) {
                $coldOutreachGovernance->markByEntity('whatsapp', (int) $messageRow['id'], 'sent');
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => $responseMessage,
            'uuid' => $uuid,
            'whatsapp_message_id' => $providerMessageId,
            'note' => 'Text messages only work within 24 hours of customer\'s last message'
        ], JSON_PRETTY_PRINT);
    }
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to send WhatsApp message',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
