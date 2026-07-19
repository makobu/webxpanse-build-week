<?php
/**
 * Compose WhatsApp Message Page
 */

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
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\ColdOutreachGovernanceService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\WhatsAppService;
use CRM\Modules\Contacts;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('whatsapp.messages.send');

$whatsAppGate = new WorkspaceCommunicationGateService();
$whatsAppGate->enforceWebChannelRuntime((int) (WorkspaceContext::currentWorkspaceId() ?? 0), 'whatsapp', Auth::user());
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$error = null;
$success = null;
$coldOutreachGovernance = new ColdOutreachGovernanceService();
$contactsModule = new Contacts();

// Get contacts
$contacts = $contactsModule->getSelectableContactsForChannel('whatsapp');

// Get contact ID from query string if provided
$preselectedContactId = $_GET['contact_id'] ?? null;

$pageTitle = 'Send WhatsApp Message - ' . brandProductName();
ob_start();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $messageType = $_POST['message_type'] ?? 'template';
            $message = $_POST['message'] ?? '';
            $templateName = $_POST['template_name'] ?? '';
            $languageCode = $_POST['language_code'] ?? 'en_US';
            
            // Parse template parameters
            $templateParams = [];
            if (!empty($_POST['template_body_params'])) {
                $bodyParams = array_filter(array_map('trim', explode("\n", $_POST['template_body_params'])));
                if (!empty($bodyParams)) {
                    $templateParams['body'] = $bodyParams;
                }
            }
            if (!empty($_POST['template_header_params'])) {
                $headerParams = array_filter(array_map('trim', explode("\n", $_POST['template_header_params'])));
                if (!empty($headerParams)) {
                    $templateParams['header'] = $headerParams;
                }
            }
            if (!empty($_POST['template_carousel_json'])) {
                $carouselRaw = trim($_POST['template_carousel_json']);
                $carouselData = json_decode($carouselRaw, true);
                if (is_array($carouselData)) {
                    $templateParams['carousel'] = $carouselData;
                } else {
                    // Support one JSON object per line (e.g. two cards on two lines)
                    $cards = [];
                    foreach (preg_split('/\r?\n/', $carouselRaw) as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        $obj = json_decode($line, true);
                        if (is_array($obj)) {
                            $cards[] = $obj;
                        }
                    }
                    if (!empty($cards)) {
                        $templateParams['carousel'] = $cards;
                    }
                }
            }
            
            $hasMediaUpload = !empty($_FILES['media']['tmp_name']) && is_uploaded_file($_FILES['media']['tmp_name'])
                && ($_FILES['media']['error'] ?? 0) === UPLOAD_ERR_OK;

            if (!$contactId) {
                $error = 'Please select a contact.';
            } elseif ($messageType === 'template' && empty($templateName)) {
                $error = 'Template name is required for business-initiated messages.';
            } elseif ($messageType === 'text' && empty($message) && !$hasMediaUpload) {
                $error = 'Message text or media attachment is required for session messages.';
            } else {
                // Use WhatsAppService directly instead of API call
                $whatsappService = new WhatsAppService();
                $userId = $_SESSION['user_id'] ?? null;
                
                // Get contact phone number
                $contact = Database::queryOne(
                    "SELECT phone FROM contacts WHERE workspace_id = ? AND id = ?",
                    [$workspaceId, $contactId]
                );
                
                if (!$contact || empty($contact['phone'])) {
                    $error = 'Contact does not have a phone number.';
                } elseif ($messageType === 'text' && !$whatsappService->isWithin24HourWindow($contactId)) {
                    $error = "Text messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed. Use a template message instead.";
                } else {
                    // Format phone number: remove all non-digit characters
                    // WhatsApp API accepts both formats: with + or without
                    $phoneNumber = preg_replace('/[^\d+]/', '', $contact['phone']);
                    
                    // Remove + if present (WhatsApp API works with or without it)
                    // Based on the working example, we'll send without +
                    $phoneNumber = ltrim($phoneNumber, '+');
                    
                    // Remove leading zeros
                    $phoneNumber = ltrim($phoneNumber, '0');
                    
                    // Debug: Log phone number format
                    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                        error_log("WhatsApp: Sending to phone number: {$phoneNumber} (original: {$contact['phone']})");
                    }

                    $dispatch = $coldOutreachGovernance->planDispatch(
                        'whatsapp',
                        $contactId,
                        null,
                        'whatsapp',
                        ['source' => 'whatsapp_compose', 'message_type' => $messageType]
                    );
                    
                    try {
                        if ($messageType === 'template') {
                            // Get template structure: from hidden field (browse) or fetch by name
                            $templateStructure = null;
                            if (!empty($_POST['template_structure'])) {
                                $decoded = json_decode($_POST['template_structure'], true);
                                if (is_array($decoded)) {
                                    $templateStructure = $decoded;
                                }
                            }
                            if (!$templateStructure) {
                                $templateStructure = $whatsappService->getTemplateByName($templateName, $languageCode);
                            }
                            $components = $whatsappService->buildTemplateComponents($templateParams, $templateStructure);
                            
                            $paramsForStore = $templateParams;
                            $paramsForStore['_language'] = $languageCode;
                            $messageBody = "Template: {$templateName}";
                            $storeOptions = [
                                'user_id' => $userId,
                                'template_name' => $templateName,
                                'template_params' => $paramsForStore,
                            ];

                            if (!empty($dispatch['was_deferred'])) {
                                $storeOptions['scheduled_at'] = $dispatch['scheduled_at'] ?? null;
                                $uuid = $whatsappService->storeMessage($contactId, $phoneNumber, 'template', $messageBody, $storeOptions);
                                $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
                                $coldOutreachGovernance->attachReservation(
                                    (int) ($dispatch['reservation_id'] ?? 0),
                                    !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                                    $uuid
                                );
                                $success = 'WhatsApp template message scheduled for the next available cold outreach slot.';
                            } else {
                                // Send template message
                                $result = $whatsappService->sendTemplateMessage(
                                    $phoneNumber,
                                    $templateName,
                                    $languageCode,
                                    $components
                                );
                                $storeOptions['whatsapp_message_id'] = $result['messages'][0]['id'] ?? null;
                                $uuid = $whatsappService->storeMessage($contactId, $phoneNumber, 'template', $messageBody, $storeOptions);
                                $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
                                $coldOutreachGovernance->attachReservation(
                                    (int) ($dispatch['reservation_id'] ?? 0),
                                    !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                                    $uuid
                                );
                                if (!empty($messageRow['id'])) {
                                    $coldOutreachGovernance->markByEntity('whatsapp', (int) $messageRow['id'], 'sent');
                                }
                                $success = 'WhatsApp template message sent successfully!';
                            }
                            // Clear form
                            $_POST = [];
                        } else {
                            // Send text or media message (session message, 24h window)
                            try {
                                $result = null;
                                $storeMessageType = 'text';
                                $storeBody = $message;

                                if ($hasMediaUpload && !empty($dispatch['was_deferred'])) {
                                    $error = 'Cold outreach messages with media cannot be auto-deferred yet. Remove the media or raise the daily cap first.';
                                }

                                if ($hasMediaUpload && empty($error)) {
                                    $file = $_FILES['media'];
                                    $tmpPath = $file['tmp_name'];
                                    $mimeType = $file['type'] ?? 'application/octet-stream';
                                    $fileSize = (int) ($file['size'] ?? 0);
                                    $allowedImages = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                                    $allowedVideos = ['video/mp4', 'video/3gpp', 'video/quicktime'];
                                    $maxImageSize = 5 * 1024 * 1024;
                                    $maxVideoSize = 16 * 1024 * 1024;

                                    if (in_array($mimeType, $allowedImages, true)) {
                                        if ($fileSize > $maxImageSize) {
                                            $error = 'Image must be 5MB or less.';
                                        } else {
                                            $mediaId = $whatsappService->uploadMedia($tmpPath, $mimeType);
                                            if ($mediaId) {
                                                $result = $whatsappService->sendImageMessage($phoneNumber, $mediaId, $message ?: null);
                                                $storeMessageType = 'image';
                                                $storeBody = $message ?: '[Image]';
                                            } else {
                                                $error = 'Failed to upload image.';
                                            }
                                        }
                                    } elseif (in_array($mimeType, $allowedVideos, true)) {
                                        if ($fileSize > $maxVideoSize) {
                                            $error = 'Video must be 16MB or less.';
                                        } else {
                                            $mediaId = $whatsappService->uploadMedia($tmpPath, $mimeType);
                                            if ($mediaId) {
                                                $result = $whatsappService->sendVideoMessage($phoneNumber, $mediaId, $message ?: null);
                                                $storeMessageType = 'video';
                                                $storeBody = $message ?: '[Video]';
                                            } else {
                                                $error = 'Failed to upload video.';
                                            }
                                        }
                                    } else {
                                        $error = 'Only images (JPEG, PNG, GIF, WebP) and videos (MP4, 3GP) are supported.';
                                    }
                                } else {
                                    $result = $whatsappService->sendTextMessage($phoneNumber, $message);
                                }

                                if (!empty($dispatch['was_deferred']) && empty($error)) {
                                    $uuid = $whatsappService->storeMessage(
                                        $contactId,
                                        $phoneNumber,
                                        'text',
                                        $message,
                                        [
                                            'user_id' => $userId,
                                            'scheduled_at' => $dispatch['scheduled_at'] ?? null,
                                        ]
                                    );
                                    $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
                                    $coldOutreachGovernance->attachReservation(
                                        (int) ($dispatch['reservation_id'] ?? 0),
                                        !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                                        $uuid
                                    );
                                    $success = 'WhatsApp message scheduled for the next available cold outreach slot.';
                                    $_POST = [];
                                } elseif ($result && empty($error) && isset($result['messages'][0]['id'])) {
                                    $whatsappMessageId = $result['messages'][0]['id'];
                                    $uuid = $whatsappService->storeMessage(
                                        $contactId,
                                        $phoneNumber,
                                        $storeMessageType,
                                        $storeBody,
                                        [
                                            'user_id' => $userId,
                                            'whatsapp_message_id' => $whatsappMessageId
                                        ]
                                    );
                                    $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
                                    $coldOutreachGovernance->attachReservation(
                                        (int) ($dispatch['reservation_id'] ?? 0),
                                        !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                                        $uuid
                                    );
                                    if (!empty($messageRow['id'])) {
                                        $coldOutreachGovernance->markByEntity('whatsapp', (int) $messageRow['id'], 'sent');
                                    }
                                    $success = 'WhatsApp message sent successfully!';
                                    $_POST = [];
                                } elseif (empty($error)) {
                                    throw new \Exception("API returned success but no message ID. Response: " . json_encode($result));
                                }
                            } catch (\Exception $e) {
                                if (empty($error)) {
                                    $errorMsg = $e->getMessage();
                                    if (strpos($errorMsg, '24') !== false || strpos($errorMsg, 'session') !== false || strpos($errorMsg, 'window') !== false) {
                                        $error = 'Text messages only work within 24 hours of customer\'s last message. Use a template message for business-initiated communication.';
                                    } else {
                                        $error = 'Failed to send WhatsApp message: ' . $errorMsg;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $error = 'Failed to send WhatsApp message: ' . $e->getMessage();
                    }
                }
            }
        } catch (\Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/communications-ui.css">

<div class="page-premium">
    <div class="container comm-workspace">
        <section class="comm-hero">
            <div>
                <div class="comm-kicker">WhatsApp workspace</div>
                <h1>Send WhatsApp Message</h1>
                <p>Prepare approved template messages or session-window replies without losing the operational safeguards.</p>
            </div>
            <div class="comm-hero-actions">
                <span class="comm-channel-badge comm-channel-badge--whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</span>
                <a href="contacts.php" class="btn-premium-secondary"><i class="fas fa-address-book"></i> Contacts</a>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="premium-banner premium-banner-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="whatsappForm" enctype="multipart/form-data" class="comm-compose-layout">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="template_structure" id="template_structure" value="">

            <div class="comm-main-panel">
                <section class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Recipient</div>
                            <h2>Contact and channel</h2>
                        </div>
                    </div>

                    <div class="comm-form-grid">
                        <div class="comm-control-wide">
                            <label for="contact_id">Contact *</label>
                            <select id="contact_id" name="contact_id" required onchange="updatePhoneNumber()">
                                <option value="">Select a contact...</option>
                                <?php foreach ($contacts as $contact): ?>
                                    <option
                                        value="<?php echo $contact['id']; ?>"
                                        data-phone="<?php echo htmlspecialchars($contact['phone'] ?? ''); ?>"
                                        <?php echo ($preselectedContactId == $contact['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name'] . ' (' . $contact['phone'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="phone-display" class="comm-status"></div>
                        </div>

                        <div class="comm-control-wide">
                            <label for="message_type">Message Type *</label>
                            <select id="message_type" name="message_type" required onchange="toggleMessageType()">
                                <option value="template" selected>Template Message (Business-Initiated)</option>
                                <option value="text">Text Message (24-Hour Window Only)</option>
                            </select>
                            <p class="comm-help"><span id="message-type-hint">Template messages can be sent anytime. Requires approved template in WhatsApp Business Manager.</span></p>
                        </div>
                    </div>
                </section>

                <section id="template-fields" class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Template message</div>
                            <h2>Approved template details</h2>
                        </div>
                        <button type="button" id="browse-templates-btn" onclick="openTemplateBrowser()" class="btn-premium-success btn-premium-sm">
                            <i class="fas fa-search"></i> Browse Templates
                        </button>
                    </div>

                    <div class="comm-form-grid">
                        <div class="comm-control-wide">
                            <label for="template_name">Template *</label>
                            <input
                                type="text"
                                id="template_name"
                                name="template_name"
                                placeholder="Select a template or enter name manually"
                                value="<?php echo htmlspecialchars($_POST['template_name'] ?? ''); ?>"
                                required
                            >
                            <p class="comm-help">Select from pre-approved templates or enter the exact case-sensitive template name manually.</p>
                            <div id="selected-template-info" class="comm-note" style="display: none;">
                                <strong>Selected:</strong> <span id="selected-template-name"></span>
                                (<span id="selected-template-language"></span>)
                            </div>
                        </div>

                        <div class="comm-control">
                            <label for="language_code">Language Code</label>
                            <input
                                type="text"
                                id="language_code"
                                name="language_code"
                                value="<?php echo htmlspecialchars($_POST['language_code'] ?? 'en_US'); ?>"
                                placeholder="en_US"
                            >
                            <p class="comm-help">ISO 639 language code (e.g., en, es, fr).</p>
                        </div>
                    </div>

                    <?php
                    $showBodyParams = !empty($_POST['template_body_params']) || ($error && !empty($_POST['template_name']));
                    $showHeaderParams = !empty($_POST['template_header_params']) || ($error && !empty($_POST['template_name']));
                    $showCarouselParams = !empty($_POST['template_carousel_json']) || ($error && !empty($_POST['template_name']));
                    ?>
                    <div id="template-body-params-field" class="comm-control-wide" style="<?php echo $showBodyParams ? 'display: block;' : 'display: none;'; ?>">
                        <label for="template_body_params">Body Parameters (Optional)</label>
                        <textarea
                            id="template_body_params"
                            name="template_body_params"
                            rows="3"
                            placeholder="Enter body parameter values, one per line&#10;e.g.,&#10;John Doe&#10;Acme Corp&#10;$99.99"
                        ><?php echo htmlspecialchars($_POST['template_body_params'] ?? ''); ?></textarea>
                        <p id="template_body_params_hint" class="comm-help">One parameter per line. These will replace {{1}}, {{2}}, etc. in your template body.</p>
                    </div>

                    <?php
                    $templateStructureDecoded = !empty($_POST['template_structure']) ? json_decode($_POST['template_structure'], true) : null;
                    $isCarouselTemplate = is_array($templateStructureDecoded) && !empty($templateStructureDecoded['is_carousel']);
                    $showCarouselField = $showCarouselParams || ($error && $isCarouselTemplate);
                    ?>
                    <div id="template-carousel-field" class="comm-control-wide" style="<?php echo $showCarouselField ? 'display: block;' : 'display: none;'; ?>">
                        <label for="template_carousel_json">Carousel Cards (JSON)</label>
                        <textarea
                            id="template_carousel_json"
                            name="template_carousel_json"
                            rows="8"
                            class="comm-textarea-code"
                            placeholder='[{"header":["https://example.com/1.jpg"],"body":["Card 1 text"],"buttons":["payload1"]}]'
                        ><?php echo htmlspecialchars($_POST['template_carousel_json'] ?? ''); ?></textarea>
                        <p class="comm-help">JSON array of cards. Each card: {"header":["image_url"],"body":["text1","text2"],"buttons":["url_or_payload"]}</p>
                    </div>

                    <div id="template-header-params-field" class="comm-control-wide" style="<?php echo $showHeaderParams ? 'display: block;' : 'display: none;'; ?>">
                        <label for="template_header_params">Header Parameters (Optional)</label>
                        <textarea
                            id="template_header_params"
                            name="template_header_params"
                            rows="2"
                            placeholder="Enter header parameter values, one per line"
                        ><?php echo htmlspecialchars($_POST['template_header_params'] ?? ''); ?></textarea>
                        <p id="template_header_params_hint" class="comm-help">One parameter per line. Only if your template has header parameters.</p>
                    </div>
                </section>

                <section id="text-fields" class="comm-section" style="display: none;">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Session message</div>
                            <h2>Text or media reply</h2>
                        </div>
                    </div>

                    <div id="text-window-closed-msg" class="alert alert-danger" style="display: none;">
                        <strong>24-hour window closed.</strong> Custom text can only be sent within 24 hours of the customer's last message. Choose <strong>Template Message</strong> above to send now, or wait for the customer to message you.
                    </div>
                    <div class="comm-warning-note">
                        <strong>Important:</strong> Text messages only work within 24 hours of the customer's last message to you. If the customer has not messaged you in the last 24 hours, WhatsApp will reject the message. For business-initiated messages, use Template Messages instead.
                    </div>

                    <div class="comm-control-wide">
                        <label for="media">Attach Image or Video (optional)</label>
                        <input type="file" name="media" id="media" accept="image/*,video/*">
                        <p class="comm-help">Images: max 5MB (JPEG, PNG, GIF, WebP). Videos: max 16MB (MP4, 3GP).</p>
                    </div>

                    <div class="comm-control-wide">
                        <label for="message">Message Text (or caption for media)</label>
                        <textarea id="message" name="message" rows="6" placeholder="Enter your message text or caption here..." class="comm-textarea-md"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>

                    <div id="whatsapp-ai-draft-section" class="comm-ai-panel comm-ai-panel--whatsapp">
                        <div class="comm-section-header">
                            <div>
                                <div class="comm-panel-kicker">AI assistant</div>
                                <h2>Generate a reply draft</h2>
                            </div>
                        </div>
                        <p class="comm-muted">Select a contact, choose purpose and tone, then generate a WhatsApp-sized draft.</p>
                        <div class="comm-form-grid">
                            <div class="comm-control">
                                <label for="whatsapp_draft_purpose">Purpose</label>
                                <select id="whatsapp_draft_purpose">
                                    <option value="follow_up">Follow Up</option>
                                    <option value="welcome">Welcome</option>
                                    <option value="proposal">Proposal</option>
                                    <option value="meeting">Meeting Request</option>
                                    <option value="thank_you">Thank You</option>
                                </select>
                            </div>
                            <div class="comm-control">
                                <label for="whatsapp_draft_tone">Tone</label>
                                <select id="whatsapp_draft_tone">
                                    <option value="casual">Casual</option>
                                    <option value="friendly">Friendly</option>
                                    <option value="professional">Professional</option>
                                </select>
                            </div>
                        </div>
                        <div class="comm-inline-actions">
                            <button type="button" id="whatsapp-generate-draft-btn" class="btn-premium-success btn-premium-sm">
                                <i class="fas fa-wand-magic-sparkles"></i> Generate with AI
                            </button>
                            <span id="whatsapp-ai-status" class="comm-status"></span>
                        </div>
                    </div>
                </section>

                <section class="comm-section">
                    <div class="form-actions">
                        <a href="contacts.php" class="btn-premium-secondary">Cancel</a>
                        <button type="submit" id="whatsapp-submit-btn" class="btn-premium-success">
                            <i class="fab fa-whatsapp"></i> Send WhatsApp Message
                        </button>
                    </div>
                </section>
            </div>

            <aside class="comm-side-panel">
                <section class="comm-note">
                    <strong>Important notes</strong>
                    <ul>
                        <li>Template messages require pre-approved templates in WhatsApp Business Manager.</li>
                        <li>Template names are case-sensitive.</li>
                        <li>Text messages only work within the 24-hour customer-service window.</li>
                        <li>Messages are queued and processed by the WhatsApp worker.</li>
                    </ul>
                </section>
                <section class="comm-panel">
                    <div class="comm-panel-header">
                        <div>
                            <div class="comm-panel-kicker">Flow</div>
                            <h2 class="comm-panel-title">Template selection</h2>
                        </div>
                    </div>
                    <p class="comm-muted">Use the browser to pull approved templates from the WhatsApp API. Selecting one fills the template name, language, and parameter hints without changing the submit endpoint.</p>
                </section>
            </aside>
        </form>
    </div>
</div>

<!-- Template Browser Modal -->
<div id="template-browser-modal" class="comm-modal" style="display: none;">
    <div class="comm-modal-card">
        <div class="comm-modal-header">
            <div>
                <div class="comm-panel-kicker">Approved templates</div>
                <h2 class="comm-modal-title">Select WhatsApp Template</h2>
            </div>
            <button type="button" onclick="closeTemplateBrowser()" class="comm-modal-close" aria-label="Close template browser">&times;</button>
        </div>

        <div id="template-browser-loading" class="comm-modal-loading" style="display: none;">
            <i class="fas fa-spinner fa-spin"></i>
            <div class="comm-muted">Loading templates...</div>
        </div>

        <div id="template-browser-error" class="comm-modal-error" style="display: none;">
            <strong>Error:</strong> <span id="template-browser-error-message"></span>
        </div>

        <div id="template-browser-empty" class="comm-modal-empty" style="display: none;">
            <i class="fas fa-inbox"></i>
            <div>No approved templates found. Please create templates in WhatsApp Business Manager.</div>
        </div>

        <div id="template-browser-grid" class="comm-template-browser-grid">
            <!-- Templates will be inserted here -->
        </div>
    </div>
</div>

<script>
function toggleMessageType() {
    const messageType = document.getElementById('message_type').value;
    const templateFields = document.getElementById('template-fields');
    const textFields = document.getElementById('text-fields');
    const hint = document.getElementById('message-type-hint');
    const templateName = document.getElementById('template_name');
    const message = document.getElementById('message');
    
    if (messageType === 'template') {
        templateFields.style.display = 'block';
        textFields.style.display = 'none';
        hint.textContent = 'Template messages can be sent anytime. Requires approved template in WhatsApp Business Manager.';
        if (templateName) templateName.required = true;
        if (message) message.required = false;
        setSendButtonFor24hrWindow(true);
    } else {
        templateFields.style.display = 'none';
        textFields.style.display = 'block';
        hint.textContent = 'Text messages only work within 24 hours of customer\'s last message.';
        if (templateName) templateName.required = false;
        if (message) message.required = false;
        updateSendButtonFor24hrWindow();
    }
}

function setSendButtonFor24hrWindow(canSend) {
    const btn = document.getElementById('whatsapp-submit-btn');
    const closedMsg = document.getElementById('text-window-closed-msg');
    if (!btn) return;
    btn.disabled = !canSend;
    btn.style.opacity = canSend ? '1' : '0.6';
    btn.style.cursor = canSend ? 'pointer' : 'not-allowed';
    if (closedMsg) closedMsg.style.display = canSend ? 'none' : 'block';
}

function updateSendButtonFor24hrWindow() {
    const messageType = document.getElementById('message_type').value;
    const contactId = document.getElementById('contact_id').value;
    if (messageType !== 'text' || !contactId) {
        setSendButtonFor24hrWindow(messageType !== 'text');
        return;
    }
    fetch('../api/whatsapp_24hr_window.php?contact_id=' + encodeURIComponent(contactId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            setSendButtonFor24hrWindow(!!data.within_24h);
        })
        .catch(function() {
            setSendButtonFor24hrWindow(false);
        });
}

function updatePhoneNumber() {
    const select = document.getElementById('contact_id');
    const phoneDisplay = document.getElementById('phone-display');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const phone = selectedOption.getAttribute('data-phone');
        phoneDisplay.textContent = 'Phone: ' + phone;
        phoneDisplay.style.color = 'var(--charcoal-grey)';
    } else {
        phoneDisplay.textContent = '';
    }
    updateSendButtonFor24hrWindow();
}

// Template Browser Functions
let templatesCache = null;

function openTemplateBrowser() {
    const modal = document.getElementById('template-browser-modal');
    const loading = document.getElementById('template-browser-loading');
    const error = document.getElementById('template-browser-error');
    const empty = document.getElementById('template-browser-empty');
    const grid = document.getElementById('template-browser-grid');
    
    modal.style.display = 'block';
    loading.style.display = 'block';
    error.style.display = 'none';
    empty.style.display = 'none';
    grid.innerHTML = '';
    
    // Use cached templates if available
    if (templatesCache) {
        loading.style.display = 'none';
        displayTemplates(templatesCache);
        return;
    }
    
    // Fetch templates from API
    fetch('../api/whatsapp/templates.php')
        .then(response => {
            return response.json().then(data => {
                if (!response.ok) {
                    // If response has error message, use it
                    throw new Error(data.error || 'Failed to fetch templates');
                }
                return data;
            });
        })
        .then(data => {
            loading.style.display = 'none';
            
            if (data.success && data.templates && data.templates.length > 0) {
                templatesCache = data.templates;
                displayTemplates(data.templates);
            } else if (data.success && data.templates && data.templates.length === 0) {
                empty.style.display = 'block';
            } else if (!data.success && data.error) {
                error.style.display = 'block';
                document.getElementById('template-browser-error-message').textContent = data.error;
            } else {
                empty.style.display = 'block';
            }
        })
        .catch(err => {
            loading.style.display = 'none';
            error.style.display = 'block';
            document.getElementById('template-browser-error-message').textContent = 
                err.message || 'Failed to load templates. Please try again or enter template name manually.';
        });
}

function closeTemplateBrowser() {
    document.getElementById('template-browser-modal').style.display = 'none';
}

function displayTemplates(templates) {
    const grid = document.getElementById('template-browser-grid');
    grid.innerHTML = '';
    
    templates.forEach(template => {
        const card = createTemplateCard(template);
        grid.appendChild(card);
    });
}

function createTemplateCard(template) {
    const card = document.createElement('div');
    card.className = 'comm-template-card comm-template-browser-card';
    card.onclick = () => selectTemplate(template);
    
    // Category badge
    const categoryClass = {
        'UTILITY': 'comm-category-badge--utility',
        'MARKETING': 'comm-category-badge--marketing',
        'AUTHENTICATION': 'comm-category-badge--authentication'
    }[template.category] || 'comm-category-badge--default';
    
    // Parameter indicators
    const paramCount = template.parameter_count || {};
    const isCarousel = template.is_carousel || false;
    const hasParams = (paramCount.header || 0) + (paramCount.body || 0) + (paramCount.buttons || 0) > 0;
    
    // Body preview (first 100 chars)
    const bodyPreview = (template.body_text || '').substring(0, 100);
    const truncated = (template.body_text || '').length > 100;
    
    card.innerHTML = `
        <div class="comm-template-browser-head">
            <div class="comm-template-card-body">
                <div class="comm-template-title">
                    ${escapeHtml(template.name)}
                </div>
                <div class="comm-category-badge ${categoryClass}">
                    ${escapeHtml(template.category || 'UTILITY')}
                </div>
            </div>
            <div class="comm-muted">
                ${escapeHtml(template.language || 'en_US')}
            </div>
        </div>
        <div class="comm-template-description">
            ${escapeHtml(bodyPreview)}${truncated ? '...' : ''}
        </div>
        <div class="comm-template-browser-meta">
            ${isCarousel ? `
                <span><i class="fas fa-images"></i> Carousel${hasParams ? ': ' + (paramCount.header || 0) + ' header, ' + (paramCount.body || 0) + ' body params' : ' (no parameters)'}</span>
            ` : hasParams ? `
                <span><i class="fas fa-tag"></i> ${paramCount.header || 0} header, ${paramCount.body || 0} body params</span>
            ` : '<span><i class="fas fa-check-circle"></i> No parameters</span>'}
        </div>
        <div class="comm-template-browser-footer">
            <button type="button" class="select-template-btn btn-premium-success btn-premium-sm">
                Select Template
            </button>
        </div>
    `;
    
    const btn = card.querySelector('.select-template-btn');
    if (btn) {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            selectTemplate(template);
        });
    }
    
    return card;
}

function selectTemplate(template) {
    // Populate form fields
    document.getElementById('template_name').value = template.name;
    document.getElementById('language_code').value = template.language || 'en_US';
    
    // Store template structure for correct parameter formatting (fixes error 132012)
    document.getElementById('template_structure').value = JSON.stringify(template);
    
    // Show selected template info
    const infoDiv = document.getElementById('selected-template-info');
    document.getElementById('selected-template-name').textContent = template.name;
    document.getElementById('selected-template-language').textContent = template.language || 'en_US';
    infoDiv.style.display = 'block';
    
    // Update parameter fields based on template structure
    updateParameterFields(template);
    
    // Close modal
    closeTemplateBrowser();
}

function updateParameterFields(template) {
    const paramCount = template.parameter_count || {};
    const schema = template.parameter_schema || {};
    const headerParams = paramCount.header || 0;
    const bodyParams = paramCount.body || 0;
    const isCarousel = template.is_carousel || false;
    const hasParams = (paramCount.header || 0) + (paramCount.body || 0) + (paramCount.buttons || 0) > 0;
    
    // Format hints for parameter types (fixes error 132012)
    const typeHints = {
        'currency': 'amount or amount|CODE (e.g. 99.99 or 99.99|USD)',
        'date_time': 'readable date/time (e.g. Feb 15, 2025 2:00 PM)',
        'image': 'image URL (e.g. https://example.com/image.jpg)',
        'video': 'video URL',
        'document': 'document URL'
    };
    
    function buildHint(types, baseText) {
        if (!types || types.length === 0) return baseText;
        const parts = types.map((t, i) => {
            const type = (t.type || 'text').toLowerCase();
            const hint = typeHints[type];
            return hint ? `{{${i + 1}}}: ${hint}` : null;
        }).filter(Boolean);
        return parts.length > 0 ? baseText + ' Format: ' + parts.join('; ') : baseText;
    }
    
    // Show/hide carousel field (carousel templates use JSON input)
    const carouselField = document.getElementById('template-carousel-field');
    if (isCarousel && hasParams) {
        carouselField.style.display = 'block';
    } else {
        carouselField.style.display = 'none';
        document.getElementById('template_carousel_json').value = '';
    }
    
    // Show/hide header parameters field (not used for carousel)
    const headerField = document.getElementById('template-header-params-field');
    const headerHint = document.getElementById('template_header_params_hint');
    if (!isCarousel && headerParams > 0) {
        headerField.style.display = 'block';
        const label = headerField.querySelector('label');
        label.innerHTML = `Header Parameters * (${headerParams} required)`;
        document.getElementById('template_header_params').setAttribute('rows', Math.max(2, headerParams).toString());
        const headerTypes = schema.header || [];
        headerHint.textContent = buildHint(headerTypes, 'One parameter per line.');
        const ph = headerTypes[0] && (headerTypes[0].type === 'image' || headerTypes[0].type === 'video' || headerTypes[0].type === 'document')
            ? 'Enter URL, one per line\nhttps://example.com/image.jpg' : 'Enter header parameter values, one per line';
        document.getElementById('template_header_params').placeholder = ph;
        headerField.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        headerField.style.display = 'none';
        document.getElementById('template_header_params').value = '';
    }
    
    // Show/hide body parameters field (carousel uses its own JSON, not body/header fields)
    const bodyField = document.getElementById('template-body-params-field');
    const bodyHint = document.getElementById('template_body_params_hint');
    if (isCarousel) {
        bodyField.style.display = 'none';
        document.getElementById('template_body_params').value = '';
    } else if (bodyParams > 0) {
        bodyField.style.display = 'block';
        const label = bodyField.querySelector('label');
        label.innerHTML = `Body Parameters * (${bodyParams} required)`;
        document.getElementById('template_body_params').setAttribute('rows', Math.max(3, bodyParams).toString());
        const bodyTypes = schema.body || [];
        bodyHint.textContent = buildHint(bodyTypes, 'One parameter per line. Replaces {{1}}, {{2}}, etc.');
    } else {
        bodyField.style.display = 'none';
        document.getElementById('template_body_params').value = '';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('template-browser-modal');
    if (event.target === modal) {
        closeTemplateBrowser();
    }
});

async function generateWhatsAppDraft() {
    const contactId = document.getElementById('contact_id').value;
    if (!contactId) {
        alert('Please select a contact first.');
        return;
    }
    const purpose = document.getElementById('whatsapp_draft_purpose').value;
    const tone = document.getElementById('whatsapp_draft_tone').value;
    const btn = document.getElementById('whatsapp-generate-draft-btn');
    const statusDiv = document.getElementById('whatsapp-ai-status');
    const originalText = btn.textContent;

    btn.disabled = true;
    btn.textContent = 'Generating...';
    btn.style.opacity = '0.7';
    statusDiv.textContent = 'AI is generating your message...';

    try {
        const response = await fetch('../api/generate_draft.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo addslashes(Security::getCsrfToken()); ?>'
            },
            body: JSON.stringify({
                contact_id: parseInt(contactId, 10),
                type: 'whatsapp',
                purpose: purpose,
                tone: tone
            })
        });

        const result = await response.json();

        if (response.ok && result.success && result.draft) {
            const message = result.draft.message || result.draft.body || '';
            document.getElementById('message').value = message;
            statusDiv.textContent = 'Draft generated! Review and edit as needed.';
            statusDiv.style.color = '#16a34a';
        } else {
            statusDiv.textContent = 'Error: ' + (result.error || 'Failed to generate draft');
            statusDiv.style.color = '#dc2626';
        }
    } catch (err) {
        statusDiv.textContent = 'Error: ' + (err.message || 'Network error');
        statusDiv.style.color = '#dc2626';
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
        btn.style.opacity = '1';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updatePhoneNumber();
    toggleMessageType();

    document.getElementById('whatsapp-generate-draft-btn')?.addEventListener('click', generateWhatsAppDraft);
    
    // Show selected template info if template name is already filled
    const templateName = document.getElementById('template_name').value;
    if (templateName) {
        const infoDiv = document.getElementById('selected-template-info');
        document.getElementById('selected-template-name').textContent = templateName;
        infoDiv.style.display = 'block';
    }
    
    // Show parameter fields if they have values (from POST data or manual entry)
    const bodyParams = document.getElementById('template_body_params').value;
    const headerParams = document.getElementById('template_header_params').value;
    if (bodyParams) {
        document.getElementById('template-body-params-field').style.display = 'block';
    }
    if (headerParams) {
        document.getElementById('template-header-params-field').style.display = 'block';
    }
    
    // Allow manual template entry to show parameter fields
    document.getElementById('template_name').addEventListener('input', function() {
        const name = this.value.trim();
        document.getElementById('template_structure').value = ''; // Clear - server will fetch by name
        if (name) {
            // If user manually enters a template name, show parameter fields
            document.getElementById('template-body-params-field').style.display = 'block';
            document.getElementById('template-header-params-field').style.display = 'block';
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
