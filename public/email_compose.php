<?php
/**
 * Compose Email Page
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
use CRM\Security;
use CRM\Services\ColdOutreachGovernanceService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\EmailService;
use CRM\Services\EmailTemplates;
use CRM\Services\InvoicePdfService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Modules\Contacts;
use CRM\Modules\EmailSignatures;
use CRM\Modules\Invoices;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: ' . publicUrl('login.php'));
    exit;
}

$isGuidedDemoPreview = (string) ($_GET['guided_demo'] ?? '') === '1'
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
if (!$isGuidedDemoPreview) {
    (new WorkspaceCommunicationGateService())->enforceWebRuntime((int) (WorkspaceContext::currentWorkspaceId() ?? 0), Auth::user());
}
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$emailService = new EmailService();
$coldOutreachGovernance = new ColdOutreachGovernanceService();
$templatesService = new EmailTemplates();
$contactsModule = new Contacts();
$signaturesModule = new EmailSignatures();
$currentUser = Auth::user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$error = null;
$success = null;

// Get contacts and templates
$contacts = $contactsModule->getSelectableContactsForChannel('email');
$templates = $templatesService->getSendableTemplatesForUser($currentUserId);
$signatures = $signaturesModule->getUserSignatures();
$defaultSignature = $signaturesModule->getDefault();
$selectedComposeContactId = (int) ($_POST['contact_id'] ?? $_GET['contact_id'] ?? 0);
$selectedComposeContactEmail = '';
foreach ($contacts as $selectableContact) {
    if ((int) ($selectableContact['id'] ?? 0) === $selectedComposeContactId) {
        $selectedComposeContactEmail = (string) ($selectableContact['email'] ?? '');
        break;
    }
}
$signaturePreviewMap = [];
foreach ($signatures as $signatureOption) {
    $signaturePreviewMap[(string) ($signatureOption['id'] ?? '')] = $signaturesModule->getSignatureHtml($signatureOption);
}
$attachableDocuments = Database::query(
    "SELECT i.id, i.contact_id, i.document_type, i.invoice_number, i.title, i.status, i.created_at,
            c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email
     FROM invoices i
     LEFT JOIN contacts c ON c.id = i.contact_id AND c.workspace_id = i.workspace_id
     WHERE i.workspace_id = ?
       AND i.document_type IN ('quote', 'proforma', 'invoice')
       AND i.status <> 'cancelled'
     ORDER BY i.created_at DESC, i.id DESC
     LIMIT 250",
    [$workspaceId]
);

function composeCommColumnExists(string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    try {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'communications'
               AND COLUMN_NAME = ?",
            [$column]
        );
        $cache[$column] = ((int) ($row['count'] ?? 0)) > 0;
    } catch (\Throwable $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

function ensureComposeEmailInInbox(int $workspaceId, int $contactId, string $to, string $subject, string $body, ?string $fromEmail): void
{
    // Best-effort dedupe check first.
    $existing = Database::queryOne(
        "SELECT id
         FROM communications
         WHERE workspace_id = ?
           AND contact_id = ?
           AND channel = 'email'
           AND direction = 'outbound'
           AND subject = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY id DESC
         LIMIT 1",
        [$workspaceId, $contactId, $subject]
    );
    if (!empty($existing['id'])) {
        return;
    }

    $createdAt = date('Y-m-d H:i:s');
    $columns = ['workspace_id', 'uuid', 'contact_id', 'channel', 'direction', 'subject', 'body', 'status', 'created_at'];
    $values = ['?', '?', '?', "'email'", "'outbound'", '?', '?', "'sent'", '?'];
    $params = [$workspaceId, uuid_v4(), $contactId, $subject, $body, $createdAt];

    if (composeCommColumnExists('metadata')) {
        $columns[] = 'metadata';
        $values[] = '?';
        $params[] = json_encode([
            'source' => 'email_compose_fallback',
            'fallback_created_at' => $createdAt
        ]);
    }
    if (composeCommColumnExists('from_email')) {
        $columns[] = 'from_email';
        $values[] = '?';
        $params[] = (string) ($fromEmail ?? '');
    }
    if (composeCommColumnExists('to_email')) {
        $columns[] = 'to_email';
        $values[] = '?';
        $params[] = $to;
    }

    Database::execute(
        "INSERT INTO communications (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', $values) . ")",
        $params
    );
    $communicationId = (int) \CRM\Database::lastInsertId();

    try {
        (new \CRM\Services\ContactIntelligenceService())->computeAndPersist($contactId);
    } catch (\Throwable $e) {
        error_log('email_compose contact intelligence refresh failed: ' . $e->getMessage());
    }
    try {
        (new \CRM\Services\TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
            'contact_id' => (int) $contactId,
            'channel' => 'email',
        ]);
    } catch (\Throwable $e) {
        error_log('email_compose target intelligence refresh failed: ' . $e->getMessage());
    }
}

function composeLoadAttachableDocument(int $documentId): ?array
{
    if ($documentId <= 0) {
        return null;
    }

    $document = (new Invoices())->getById($documentId);
    if (!$document) {
        return null;
    }
    if (!in_array((string) ($document['document_type'] ?? ''), ['quote', 'proforma', 'invoice'], true)) {
        return null;
    }
    if ((string) ($document['status'] ?? '') === 'cancelled') {
        return null;
    }

    return $document;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $to = $_POST['to'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            $templateSlug = $_POST['template_slug'] ?? '';
            $draftSource = trim((string) ($_POST['draft_source'] ?? ''));
            $draftMode = trim((string) ($_POST['draft_mode'] ?? ''));
            $draftIntention = trim((string) ($_POST['draft_intention'] ?? ''));
            $draftLearningSampleId = (int) ($_POST['draft_learning_sample_id'] ?? 0);
            $scheduledAtInput = trim((string) ($_POST['scheduled_at'] ?? ''));
            $signatureId = !empty($_POST['signature_id']) ? (int) $_POST['signature_id'] : null;
            $attachmentInvoiceId = (int) ($_POST['attachment_invoice_id'] ?? 0);
            $attachmentDocument = null;
            $selectedTemplate = null;
            
            if (!$contactId) {
                $error = 'Please select a contact.';
            } elseif (empty($to) && empty($templateSlug)) {
                $error = 'Please provide recipient email or select a template.';
            } elseif (empty($subject)) {
                $error = 'Subject is required.';
            } elseif (empty($body) && empty($templateSlug)) {
                $error = 'Email body is required.';
            } else {
                // If template is selected, render it
                if ($templateSlug) {
                    $contact = Database::queryOne(
                        "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
                        [$workspaceId, $contactId]
                    );
                    if (!$contact) {
                        $error = 'Contact not found.';
                    } else {
                        $variables = array_merge([
                            'first_name' => $contact['first_name'] ?? '',
                            'last_name' => $contact['last_name'] ?? '',
                            'email' => $contact['email'] ?? '',
                            'phone' => $contact['phone'] ?? '',
                            'company' => $contact['company'] ?? '',
                        ], $_POST['template_variables'] ?? []);
                        
                        $selectedTemplate = $templatesService->getSendableTemplateBySlug($templateSlug, $currentUserId);
                        $rendered = $templatesService->renderSendableTemplate($templateSlug, $currentUserId, $variables);
                        $subject = $rendered['subject'];
                        $body = $rendered['body_text'];
                        $bodyHtml = $rendered['body_html'];
                        $to = $contact['email'] ?: $to;
                    }
                }
                
                if (!$error) {
                    $selectedSignature = null;
                    if ($signatureId) {
                        $selectedSignature = $signaturesModule->getById($signatureId);
                    } elseif ($defaultSignature) {
                        $selectedSignature = $defaultSignature;
                    }

                    $rawBodyHtml = $bodyHtml ?? $body;
                    $rawBodyHasMarkup = preg_match('/<\/?[a-z][\s\S]*>/i', (string) $rawBodyHtml) === 1;
                    $finalBodyHtml = $rawBodyHasMarkup
                        ? (string) $rawBodyHtml
                        : nl2br(htmlspecialchars((string) $rawBodyHtml, ENT_QUOTES, 'UTF-8'));

                    if ($selectedSignature) {
                        $finalBodyHtml = $signaturesModule->stripKnownSignaturesFromHtml($finalBodyHtml, $signatures);
                        $signatureHtml = $signaturesModule->getSignatureHtml($selectedSignature);
                        if ($signatureHtml !== '') {
                            $finalBodyHtml = ($finalBodyHtml !== '' ? rtrim($finalBodyHtml) . '<br><br>' : '') . $signatureHtml;
                        }
                    }

                    $body = trim(html_entity_decode(
                        strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $finalBodyHtml)),
                        ENT_QUOTES,
                        'UTF-8'
                    ));
                    
                    $scheduledAtFormatted = null;
                    if ($scheduledAtInput !== '') {
                        $scheduledTimestamp = strtotime($scheduledAtInput);
                        if ($scheduledTimestamp === false) {
                            $error = 'Invalid scheduled date/time value.';
                        } else {
                            $scheduledAtFormatted = date('Y-m-d H:i:s', $scheduledTimestamp);
                        }
                    }

                    if (!$error && $attachmentInvoiceId > 0) {
                        $attachmentDocument = composeLoadAttachableDocument($attachmentInvoiceId);
                        if (!$attachmentDocument) {
                            $error = 'Selected quote, proforma, or invoice could not be loaded.';
                        } elseif (!empty($attachmentDocument['contact_id']) && (int) $attachmentDocument['contact_id'] !== $contactId) {
                            $error = 'The selected commercial document belongs to a different contact.';
                        } elseif ($scheduledAtFormatted && strtotime($scheduledAtFormatted) > time()) {
                            $error = 'Attaching a quote, proforma, or invoice is currently supported only for immediate send.';
                        }
                    }

                    $options = [
                        'body_html' => $finalBodyHtml,
                        'scheduled_at' => $scheduledAtFormatted,
                        'sender_profile' => 'outreach',
                    ];
                    if ($draftSource !== '') {
                        $options['draft_source'] = $draftSource;
                        $options['learning_source'] = in_array($draftSource, ['ai_intention_draft', 'ai_polish_draft'], true)
                            ? 'ai_intention_draft'
                            : $draftSource;
                    }
                    if ($draftMode !== '') {
                        $options['draft_mode'] = $draftMode;
                    }
                    if ($draftIntention !== '') {
                        $options['draft_intention'] = $draftIntention;
                    }
                    if ($draftLearningSampleId > 0 && in_array($draftSource, ['ai_intention_draft', 'ai_polish_draft'], true)) {
                        $options['draft_learning_sample_id'] = $draftLearningSampleId;
                    }
                    if ($selectedTemplate) {
                        $options['source_template_id'] = (int) ($selectedTemplate['id'] ?? 0);
                        $options['source_template_slug'] = (string) ($selectedTemplate['slug'] ?? $templateSlug);
                        $options['draft_source'] = 'template';
                        $options['learning_source'] = 'template_send';
                        $options['learning_intent_key'] = (string) ($selectedTemplate['template_key'] ?? $selectedTemplate['purpose'] ?? $selectedTemplate['category'] ?? '');
                        $options['template_key'] = (string) ($selectedTemplate['template_key'] ?? '');
                        unset($options['draft_learning_sample_id']);
                    }

                    $dispatch = $coldOutreachGovernance->planDispatch(
                        'email',
                        $contactId,
                        $scheduledAtFormatted,
                        'email',
                        ['source' => 'email_compose']
                    );
                    if (!empty($dispatch['scheduled_at'])) {
                        $options['scheduled_at'] = $dispatch['scheduled_at'];
                    }

                    // Queue only when date is explicitly in the future.
                    if (
                        !$error
                        && !empty($options['scheduled_at'])
                        && strtotime((string) $options['scheduled_at']) > time()
                    ) {
                        // Scheduled: queue for later (worker or admin will process)
                        $uuid = $emailService->send($contactId, $to, $subject, $body, $options);
                        if (!empty($dispatch['reservation_id'])) {
                            $emailRow = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
                            $coldOutreachGovernance->attachReservation(
                                (int) ($dispatch['reservation_id'] ?? 0),
                                !empty($emailRow['id']) ? (int) $emailRow['id'] : null,
                                $uuid
                            );
                        }
                        $message = !empty($dispatch['was_deferred'])
                            ? 'Email scheduled for the next available cold outreach slot on ' . date('M j, Y g:i A', strtotime((string) $options['scheduled_at'])) . '.'
                            : 'Email scheduled successfully for ' . date('M j, Y g:i A', strtotime((string) $options['scheduled_at'])) . '.';
                        header('Location: ' . publicUrl('emails.php?notice=' . urlencode($message)));
                        exit;
                    }

                    if (!$error) {
                        // Send immediately (same SMTP path as test) so email is sent without needing queue/admin.
                        $attachmentFiles = [];
                        try {
                            if ($attachmentDocument) {
                                $tempPdf = (new InvoicePdfService())->renderToTemporaryFile($attachmentDocument, 'email_doc_');
                                $attachmentFiles[] = $tempPdf['path'];
                            }

                            $uuid = $emailService->send(
                                $contactId,
                                $to,
                                $subject,
                                $body,
                                array_merge($options, ['attachments' => $attachmentFiles, 'queue' => false])
                            );
                            $emailRow = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
                            if (!empty($dispatch['reservation_id'])) {
                                $coldOutreachGovernance->attachReservation(
                                    (int) ($dispatch['reservation_id'] ?? 0),
                                    !empty($emailRow['id']) ? (int) $emailRow['id'] : null,
                                    $uuid
                                );
                            }
                            $lastError = null;
                            $sent = !empty($emailRow['id'])
                                ? $emailService->processEmail((int) $emailRow['id'], $lastError, ['attachments' => $attachmentFiles])
                                : $emailService->processEmailByUuid($uuid, $lastError, ['attachments' => $attachmentFiles]);
                            if (!$sent) {
                                throw new \RuntimeException($lastError ?: 'Failed to send email.');
                            }
                            ensureComposeEmailInInbox(
                                $workspaceId,
                                $contactId,
                                $to,
                                $subject,
                                $body,
                                (new EmailIntegrationService())->getStrictPreferredFromEmailForRole('outreach', $workspaceId)
                            );
                            header('Location: ' . publicUrl('emails.php'));
                            exit;
                        } finally {
                            foreach ($attachmentFiles as $attachmentFile) {
                                @unlink($attachmentFile);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Compose Email - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/communications-ui.css">

<div class="page-premium">
    <div class="container comm-workspace">
        <section class="comm-hero">
            <div>
                <div class="comm-kicker">Email workspace</div>
                <h1>Compose Email</h1>
                <p>Send a tracked, templated, or AI-assisted message to a contact.</p>
            </div>
            <div class="comm-hero-actions">
                <a href="<?php echo publicUrl('emails.php'); ?>" class="btn-premium-secondary">
                    <i class="fas fa-inbox"></i> Emails
                </a>
                <a href="<?php echo publicUrl('contacts.php'); ?>" class="btn-premium-secondary">
                    <i class="fas fa-address-book"></i> Contacts
                </a>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-triangle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="premium-banner premium-banner-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="emailForm" class="comm-compose-layout">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="draft_source" id="draft_source" value="" data-session-draft="true">
            <input type="hidden" name="draft_mode" id="draft_mode" value="" data-session-draft="true">
            <input type="hidden" name="draft_intention" id="draft_intention_hidden" value="" data-session-draft="true">
            <input type="hidden" name="draft_learning_sample_id" id="draft_learning_sample_id" value="" data-session-draft="true">

            <div class="comm-main-panel">
                <section class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Recipient and source</div>
                            <h2>Message setup</h2>
                        </div>
                        <span class="comm-channel-badge"><i class="fas fa-envelope"></i> Email</span>
                    </div>

                    <div class="comm-form-grid">
                        <div class="comm-control-wide">
                            <label for="contact_id">Contact *</label>
                            <select id="contact_id" name="contact_id" required>
                                <option value="">Select a contact...</option>
                                <?php foreach ($contacts as $contact): ?>
                                    <option value="<?php echo $contact['id']; ?>" data-email="<?php echo htmlspecialchars($contact['email']); ?>" <?php echo ($selectedComposeContactId === (int) $contact['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name'] . ' (' . $contact['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="comm-control">
                            <label for="template_slug">Use Template (Optional)</label>
                            <select id="template_slug" name="template_slug" onchange="loadTemplate(this.value)">
                                <option value="">No template (compose manually)</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo htmlspecialchars($template['slug']); ?>">
                                        <?php echo htmlspecialchars($template['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </section>

                <section class="comm-section" data-guided-demo-target="email-compose-body">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Content</div>
                            <h2>Email body</h2>
                        </div>
                    </div>

                    <div class="comm-form-grid">
                        <div class="comm-control">
                            <label for="to">To *</label>
                            <input
                                type="email"
                                id="to"
                                name="to"
                                required
                                value="<?php echo htmlspecialchars($_POST['to'] ?? $selectedComposeContactEmail); ?>"
                            >
                        </div>

                        <div class="comm-control">
                            <label for="subject">Subject *</label>
                            <input
                                type="text"
                                id="subject"
                                name="subject"
                                required
                                value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>"
                            >
                        </div>

                        <div class="comm-control-wide">
                            <label for="body">Message *</label>
                            <textarea id="body" name="body" rows="10" required class="comm-textarea-lg"><?php
                                $bodyValue = $_POST['body'] ?? '';
                                echo htmlspecialchars($bodyValue);
                            ?></textarea>
                            <p class="comm-help">
                                <i class="fas fa-info-circle"></i>
                                Email tracking for opens and link clicks only works for HTML emails. Plain text emails cannot be tracked.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Preview</div>
                            <h2>Live HTML preview</h2>
                        </div>
                        <p class="comm-muted">Templates, signatures, and HTML render here.</p>
                    </div>
                    <div class="comm-preview-shell">
                        <iframe id="email-preview-frame" class="comm-preview-frame" title="Email preview"></iframe>
                    </div>
                </section>

                <section class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">Delivery</div>
                            <h2>Attachments and schedule</h2>
                        </div>
                    </div>

                    <div class="comm-form-grid">
                        <div class="comm-control-wide">
                            <label for="attachment_invoice_id">Attach Quote / Proforma / Invoice (Optional)</label>
                            <select id="attachment_invoice_id" name="attachment_invoice_id">
                                <option value="">No commercial document attachment</option>
                                <?php foreach ($attachableDocuments as $document): ?>
                                    <?php
                                    $contactLabel = trim((string) (($document['contact_first_name'] ?? '') . ' ' . ($document['contact_last_name'] ?? '')));
                                    $documentTitle = trim((string) ($document['title'] ?? ''));
                                    $documentType = ucfirst((string) ($document['document_type'] ?? 'invoice'));
                                    $statusLabel = ucwords(str_replace('_', ' ', (string) ($document['status'] ?? 'draft')));
                                    $optionLabel = $documentType . ' ' . (string) ($document['invoice_number'] ?? ('#' . (int) $document['id']));
                                    if ($documentTitle !== '') {
                                        $optionLabel .= ' - ' . $documentTitle;
                                    }
                                    if ($contactLabel !== '') {
                                        $optionLabel .= ' (' . $contactLabel . ')';
                                    }
                                    $optionLabel .= ' - ' . $statusLabel;
                                    ?>
                                    <option
                                        value="<?php echo (int) $document['id']; ?>"
                                        data-contact-id="<?php echo (int) ($document['contact_id'] ?? 0); ?>"
                                        <?php echo ((int) ($_POST['attachment_invoice_id'] ?? 0) === (int) $document['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($optionLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="comm-help">Generates and attaches the selected document as a PDF. Scheduled sends with these attachments are not supported yet.</p>
                        </div>

                        <?php if (!empty($signatures)): ?>
                            <div class="comm-control">
                                <label for="signature_id">Email Signature (Optional)</label>
                                <select id="signature_id" name="signature_id">
                                    <option value="">No signature</option>
                                    <?php if ($defaultSignature): ?>
                                        <option value="<?php echo $defaultSignature['id']; ?>" selected>
                                            <?php echo htmlspecialchars($defaultSignature['name']); ?> (Default)
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($signatures as $sig): ?>
                                        <?php if (!$defaultSignature || $sig['id'] != $defaultSignature['id']): ?>
                                            <option value="<?php echo $sig['id']; ?>">
                                                <?php echo htmlspecialchars($sig['name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="comm-help">AI drafts preview and send with the selected signature, or your default signature when selected.</p>
                                <a href="<?php echo publicUrl('email_signatures.php'); ?>" class="comm-muted">Manage signatures</a>
                            </div>
                        <?php endif; ?>

                        <div class="comm-control">
                            <label for="scheduled_at">Schedule Email (Optional)</label>
                            <input
                                type="datetime-local"
                                id="scheduled_at"
                                name="scheduled_at"
                                value="<?php echo htmlspecialchars($_POST['scheduled_at'] ?? ''); ?>"
                            >
                            <p class="comm-help">Leave empty to send immediately.</p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="<?php echo publicUrl('emails.php'); ?>" class="btn-premium-secondary">Cancel</a>
                        <button
                            id="send-email-btn"
                            type="submit"
                            class="btn-premium-primary"
                            data-default-label="Send Email"
                            data-sending-label="Sending Email..."
                            data-scheduling-label="Scheduling Email..."
                        >
                            <i class="fas fa-paper-plane"></i> Send Email
                        </button>
                    </div>
                    <div id="send-email-status" class="comm-status text-right" aria-live="polite" style="display: none;"></div>
                </section>
            </div>

            <aside class="comm-side-panel">
                <section class="comm-ai-panel" data-guided-demo-target="email-compose-ai-panel">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">AI assistant</div>
                            <h2>Draft generation</h2>
                        </div>
                        <span class="comm-pill"><i class="fas fa-wand-magic-sparkles"></i> Assisted</span>
                    </div>
                    <p class="comm-muted">Tell AI what you want to say. It can draft with or without a selected contact, and it can polish the message already in the editor.</p>

                    <div class="comm-form-grid">
                        <div class="comm-control-wide">
                            <label for="draft_intention">What do you want to say?</label>
                            <textarea id="draft_intention" rows="4" placeholder="e.g., Follow up after yesterday's demo, mention the implementation timeline, and ask whether Friday works for a decision call."></textarea>
                        </div>
                        <div class="comm-control">
                            <label for="draft_purpose">Purpose</label>
                            <select id="draft_purpose">
                                <option value="custom_intention">Custom Intention</option>
                                <option value="follow_up">Follow Up</option>
                                <option value="welcome">Welcome</option>
                                <option value="proposal">Proposal</option>
                                <option value="meeting">Meeting Request</option>
                                <option value="thank_you">Thank You</option>
                            </select>
                        </div>
                        <div class="comm-control">
                            <label for="draft_tone">Tone</label>
                            <select id="draft_tone">
                                <option value="professional">Professional</option>
                                <option value="friendly">Friendly</option>
                                <option value="casual">Casual</option>
                                <option value="formal">Formal</option>
                            </select>
                        </div>
                    </div>

                    <button type="button" onclick="toggleAdvancedOptions()" id="toggle-advanced-btn" class="btn-premium-secondary btn-premium-sm" aria-expanded="false" aria-controls="advanced-options">
                        <i class="fas fa-sliders"></i> Advanced Options
                    </button>

                    <div id="advanced-options" class="comm-section" style="display: none;">
                        <div class="comm-form-grid">
                            <div class="comm-control">
                                <label for="draft_length">Length</label>
                                <select id="draft_length">
                                    <option value="short">Short (2-3 sentences)</option>
                                    <option value="medium" selected>Medium (1-2 paragraphs)</option>
                                    <option value="long">Long (2-3 paragraphs)</option>
                                </select>
                            </div>
                            <div class="comm-control">
                                <label for="draft_style">Style</label>
                                <select id="draft_style">
                                    <option value="paragraph" selected>Paragraphs</option>
                                    <option value="bullet_points">Bullet Points</option>
                                    <option value="numbered">Numbered List</option>
                                </select>
                            </div>
                        </div>
                        <div class="comm-control-wide">
                            <label for="draft_points">Key Points to Include (one per line)</label>
                            <textarea id="draft_points" rows="3" placeholder="Enter key points you want the AI to include, one per line..."></textarea>
                        </div>
                        <div class="comm-control-wide">
                            <label for="draft_cta">Call to Action (Optional)</label>
                            <input type="text" id="draft_cta" placeholder="e.g., 'Schedule a call', 'Download brochure', 'Reply with questions'">
                        </div>
                        <div class="comm-control-wide">
                            <label for="draft_custom_instructions">Custom Instructions (Optional)</label>
                            <textarea id="draft_custom_instructions" rows="2" placeholder="Any specific instructions for the AI"></textarea>
                        </div>
                    </div>

                    <div class="comm-actions comm-ai-actions">
                        <button type="button" id="generate-draft-btn" class="btn-premium-success" onclick="generateAIDraft()">
                            <i class="fas fa-wand-magic-sparkles"></i> Draft From Intention
                        </button>
                        <button type="button" id="polish-draft-btn" class="btn-premium-secondary" onclick="generateAIDraft('polish_existing')">
                            <i class="fas fa-spell-check"></i> Polish Current Draft
                        </button>
                    </div>
                    <div id="ai-status" class="comm-status" role="status" aria-live="polite"></div>
                </section>

                <section class="comm-note">
                    <strong>Sending guidance</strong>
                    <p class="comm-muted">Templates can render subject and body automatically. Commercial document PDFs are attached at send time, and scheduled emails update the send button before submission.</p>
                </section>
            </aside>
        </form>
    </div>
</div>

<script>
const signaturePreviewMap = <?php echo json_encode($signaturePreviewMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function stripDangerousPreviewMarkup(raw) {
    let value = String(raw || '');
    value = value.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
    value = value.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
    value = value.replace(/<link\b[^>]*rel=["']?(?:icon|shortcut icon)["']?[^>]*>/gi, '');
    value = value.replace(/<base\b[^>]*>/gi, '');
    const bodyMatch = value.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i);
    if (bodyMatch) {
        value = bodyMatch[1];
    }
    value = value.replace(/<\/?(?:html|head|body|meta|title)[^>]*>/gi, '');
    return value.trim();
}

function looksLikeRawDraftPayload(raw) {
    const value = String(raw || '').trim();
    if (!value) {
        return false;
    }
    if (/```json/i.test(value)) {
        return true;
    }
    return /^\{[\s\S]*"(subject|body_html|body_text|plain_body|reply_text|message)"\s*:/.test(value);
}

function stripKnownPreviewSignatures(raw) {
    let value = String(raw || '');
    Object.values(signaturePreviewMap || {}).forEach((signatureHtml) => {
        if (!signatureHtml) {
            return;
        }
        value = value.replace(signatureHtml, '');
    });
    return value.trim();
}

function buildPreviewBody(rawBody) {
    const sanitized = stripDangerousPreviewMarkup(rawBody || '');
    const withoutSignature = stripKnownPreviewSignatures(sanitized);
    const signatureSelect = document.getElementById('signature_id');
    const signatureId = signatureSelect ? String(signatureSelect.value || '') : '';
    const signatureHtml = signatureId && signaturePreviewMap[signatureId]
        ? stripDangerousPreviewMarkup(signaturePreviewMap[signatureId])
        : '';

    if (!signatureHtml) {
        return withoutSignature;
    }

    return withoutSignature ? `${withoutSignature}<br><br>${signatureHtml}` : signatureHtml;
}

function hydrateComposeBody(bodyHtml, bodyText) {
    const html = stripDangerousPreviewMarkup(bodyHtml || '');
    const text = String(bodyText || '').trim();

    if (looksLikeRawDraftPayload(html) || looksLikeRawDraftPayload(text)) {
        return false;
    }

    const nextValue = html || text;
    if (!nextValue) {
        return false;
    }

    document.getElementById('body').value = nextValue;
    updateEmailPreview();
    return true;
}

function toggleAdvancedOptions() {
    const advancedDiv = document.getElementById('advanced-options');
    const toggleBtn = document.getElementById('toggle-advanced-btn');
    
    if (advancedDiv.style.display === 'none') {
        advancedDiv.style.display = 'block';
        toggleBtn.textContent = '- Advanced Options';
        toggleBtn.setAttribute('aria-expanded', 'true');
    } else {
        advancedDiv.style.display = 'none';
        toggleBtn.textContent = '+ Advanced Options';
        toggleBtn.setAttribute('aria-expanded', 'false');
    }
}

let aiDraftRequestInFlight = false;

function setAiDraftStatus(message, tone = 'info', executionStatus = null, retryMode = null) {
    const statusDiv = document.getElementById('ai-status');
    statusDiv.replaceChildren();
    if (executionStatus && window.AIUiConsistency && typeof window.AIUiConsistency.renderExecutionStatus === 'function') {
        statusDiv.innerHTML = window.AIUiConsistency.renderExecutionStatus(executionStatus);
    } else {
        const text = document.createElement('span');
        text.className = `comm-status--${tone}`;
        text.textContent = message;
        statusDiv.appendChild(text);
    }
    if (retryMode !== null) {
        const actions = document.createElement('div');
        actions.className = 'ai-ui-status-actions';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'ai-ui-retry';
        retry.textContent = 'Retry';
        retry.addEventListener('click', () => generateAIDraft(retryMode));
        actions.appendChild(retry);
        statusDiv.appendChild(actions);
    }
}

function showComposeAiBanner(kind, title, message, timeoutMs) {
    const banner = document.createElement('div');
    banner.className = kind === 'success'
        ? 'premium-banner premium-banner-success'
        : (kind === 'warning' ? 'alert alert-warning' : 'alert alert-danger');
    const heading = document.createElement('strong');
    heading.textContent = title;
    const copy = document.createElement('div');
    copy.textContent = message;
    banner.append(heading, copy);
    const form = document.getElementById('emailForm');
    form.insertBefore(banner, form.firstChild);
    window.setTimeout(() => {
        banner.style.transition = 'opacity 0.3s ease';
        banner.style.opacity = '0';
        window.setTimeout(() => banner.remove(), 300);
    }, timeoutMs);
}

async function generateAIDraft(mode = 'draft_from_intention') {
    if (aiDraftRequestInFlight) {
        return;
    }
    const contactId = document.getElementById('contact_id').value;
    const intention = document.getElementById('draft_intention').value.trim();
    const currentBody = document.getElementById('body').value.trim();
    const purpose = document.getElementById('draft_purpose').value;
    const tone = document.getElementById('draft_tone').value;
    
    // Collect advanced options
    const length = document.getElementById('draft_length').value;
    const style = document.getElementById('draft_style').value;
    const pointsText = document.getElementById('draft_points').value;
    const cta = document.getElementById('draft_cta').value;
    const customInstructions = document.getElementById('draft_custom_instructions').value;
    
    // Parse key points (split by newline, trim, filter empty)
    const includePoints = pointsText.split('\n')
        .map(p => p.trim())
        .filter(p => p.length > 0);

    if (mode === 'polish_existing' && currentBody.length === 0) {
        setAiDraftStatus('Write a draft in the message box first, then use Polish Current Draft.', 'danger');
        document.getElementById('body').focus();
        return;
    }
    if (mode !== 'polish_existing' && intention.length === 0 && includePoints.length === 0 && customInstructions.trim().length === 0) {
        setAiDraftStatus('Tell AI what you want to say first.', 'danger');
        document.getElementById('draft_intention').focus();
        return;
    }
    
    // Build options object (only include non-empty values)
    const options = {
        length: length,
        style: style,
        surface: 'email_compose',
        mode: mode
    };
    
    if (includePoints.length > 0) {
        options.include_points = includePoints;
    }
    
    if (cta && cta.trim().length > 0) {
        options.call_to_action = cta.trim();
    }
    
    if (customInstructions && customInstructions.trim().length > 0) {
        options.custom_instructions = customInstructions.trim();
    }
    
    const btn = mode === 'polish_existing'
        ? document.getElementById('polish-draft-btn')
        : document.getElementById('generate-draft-btn');
    const otherBtn = mode === 'polish_existing'
        ? document.getElementById('generate-draft-btn')
        : document.getElementById('polish-draft-btn');
    const statusDiv = document.getElementById('ai-status');
    const originalText = btn.textContent;

    aiDraftRequestInFlight = true;
    btn.disabled = true;
    if (otherBtn) otherBtn.disabled = true;
    btn.textContent = 'Generating with AI...';
    btn.style.opacity = '0.7';
    statusDiv.setAttribute('aria-busy', 'true');
    setAiDraftStatus('AI is preparing your email draft...', 'info');
    
    try {
        const response = await fetch('<?php echo apiUrl('generate_draft.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>'
            },
            body: JSON.stringify({
                contact_id: contactId ? parseInt(contactId, 10) : null,
                type: 'email',
                mode: mode,
                intention: intention,
                current_body: mode === 'polish_existing' ? currentBody : '',
                purpose: purpose,
                tone: tone,
                options: options
            })
        });
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        let result;
        
        if (contentType && contentType.includes('application/json')) {
            result = await response.json();
        } else {
            // Response is not JSON (likely HTML error page)
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned invalid response. Please check API endpoint configuration.');
        }
        
        if (response.ok && result.success) {
            if (result.draft.subject) {
                document.getElementById('subject').value = result.draft.subject;
            }
            if (result.draft.signature_id) {
                const signatureSelect = document.getElementById('signature_id');
                if (signatureSelect && signaturePreviewMap[String(result.draft.signature_id)]) {
                    signatureSelect.value = String(result.draft.signature_id);
                }
            }
            const hydrated = hydrateComposeBody(result.draft.body_html || '', result.draft.body_text || '');
            if (!hydrated) {
                throw new Error('AI returned an invalid draft body. Please try again.');
            }
            document.getElementById('draft_source').value = result.draft.source || (mode === 'polish_existing' ? 'ai_polish_draft' : 'ai_intention_draft');
            document.getElementById('draft_mode').value = result.draft.draft_mode || mode;
            document.getElementById('draft_intention_hidden').value = mode === 'polish_existing' ? currentBody : intention;
            document.getElementById('draft_learning_sample_id').value = result.draft.learning_sample_id || result.learning_sample_id || '';
            
            const executionStatus = result.ai_status || null;
            const usedFallback = !!(executionStatus && executionStatus.fallback_used) || !!result.fallback;
            setAiDraftStatus(
                usedFallback
                    ? 'A safe fallback draft was prepared. Review it carefully before sending.'
                    : 'AI draft generated successfully. Review and edit as needed.',
                usedFallback ? 'info' : 'success',
                executionStatus
            );
            showComposeAiBanner(
                usedFallback ? 'warning' : 'success',
                usedFallback ? 'Fallback draft prepared' : 'AI draft generated',
                usedFallback
                    ? 'The provider was unavailable or returned an unusable result, so a safe local draft was used.'
                    : 'Review and customize the generated draft before sending.',
                8000
            );
        } else {
            const errorMsg = result.error || 'Failed to generate draft';
            setAiDraftStatus(errorMsg, 'danger', result.ai_status || null, mode);
            showComposeAiBanner('error', 'AI generation failed', errorMsg, 10000);
        }
    } catch (error) {
        console.error('Error:', error);
        const errorMsg = error.message || 'Network error occurred';
        setAiDraftStatus(errorMsg, 'danger', null, mode);
        showComposeAiBanner('error', 'AI generation failed', errorMsg, 10000);
    } finally {
        aiDraftRequestInFlight = false;
        btn.disabled = false;
        if (otherBtn) otherBtn.disabled = false;
        btn.textContent = originalText;
        btn.style.opacity = '1';
        btn.style.color = '#ffffff';
        statusDiv.setAttribute('aria-busy', 'false');
    }
}

async function loadTemplate(slug) {
    if (!slug) {
        // Clear fields if no template selected
        return;
    }
    
    const contactSelect = document.getElementById('contact_id');
    const contactId = contactSelect.value;
    
    if (!contactId) {
        setAiDraftStatus('Select a contact before loading a personalized template.', 'danger');
        contactSelect.focus();
        document.getElementById('template_slug').value = '';
        return;
    }
    
    try {
        // Fetch template preview
        const response = await fetch(`<?php echo apiUrl('email_template_preview.php'); ?>?slug=${encodeURIComponent(slug)}&contact_id=${contactId}`);
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        let data;
        
        if (contentType && contentType.includes('application/json')) {
            data = await response.json();
        } else {
            const text = await response.text();
            console.error('Non-JSON response from template API:', text.substring(0, 200));
            throw new Error('Server returned invalid response. Please check API endpoint.');
        }
        
        if (data.success) {
            if (data.subject) {
                document.getElementById('subject').value = data.subject;
            }
            if (!hydrateComposeBody(data.body_html || '', data.body_text || '')) {
                throw new Error('Template preview returned invalid email content.');
            }
            document.getElementById('draft_source').value = '';
            document.getElementById('draft_mode').value = '';
            document.getElementById('draft_intention_hidden').value = '';
            document.getElementById('draft_learning_sample_id').value = '';
        } else {
            const templateError = data.error || 'Unable to load this template.';
            setAiDraftStatus(templateError, 'danger');
            showComposeAiBanner('error', 'Template could not be loaded', templateError, 8000);
        }
    } catch (error) {
        console.error('Error loading template:', error);
        const templateError = (error.message || 'Unknown error') + '. The template will still be rendered when you send the email.';
        setAiDraftStatus(templateError, 'danger');
        showComposeAiBanner('error', 'Template preview unavailable', templateError, 8000);
    }
}

// Auto-fill email when contact is selected
document.getElementById('contact_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const email = selectedOption.getAttribute('data-email');
    if (email) {
        document.getElementById('to').value = email;
    }
    syncAttachmentOptions();
});

function syncAttachmentOptions() {
    const contactId = document.getElementById('contact_id').value;
    const attachmentSelect = document.getElementById('attachment_invoice_id');
    if (!attachmentSelect) {
        return;
    }

    let selectedStillVisible = false;
    Array.from(attachmentSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        const optionContactId = option.getAttribute('data-contact-id') || '';
        const matches = !contactId || !optionContactId || optionContactId === contactId;
        option.hidden = !matches;
        if (matches && option.selected) {
            selectedStillVisible = true;
        }
    });

    if (!selectedStillVisible && attachmentSelect.selectedIndex > 0) {
        attachmentSelect.value = '';
    }
}

// Live HTML preview of the email body (what the client will see)
function updateEmailPreview() {
    const iframe = document.getElementById('email-preview-frame');
    if (!iframe) return;
    
    const bodyValue = document.getElementById('body').value || '';
    const sanitized = buildPreviewBody(bodyValue);
    if (looksLikeRawDraftPayload(sanitized)) {
        iframe.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><link rel="icon" href="data:,"></head><body style="margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,sans-serif;color:#6b7280;">Draft preview unavailable for malformed content.</body></html>';
        return;
    }

    const hasHtmlMarkup = /<\/?[a-z][\s\S]*>/i.test(sanitized);
    const bodyContent = hasHtmlMarkup
        ? sanitized
        : sanitized
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\n{2,}/g, '\n\n')
            .replace(/\n/g, '<br>');

    const html = `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Email Preview</title>
  <base href="about:blank">
  <link rel="icon" href="data:,">
  <style>
    body {
      margin: 0;
      padding: 24px;
      background: #f3f4f6;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      color: #111827;
    }
    .email-shell {
      max-width: 640px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
      padding: 24px 28px;
      box-sizing: border-box;
    }
  </style>
</head>
<body>
  <div class="email-shell">
    ${bodyContent}
  </div>
</body>
</html>`;
    
    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
}

// Keep preview in sync with textarea edits
document.getElementById('body').addEventListener('input', function () {
    // Small debounce to avoid excessive writes
    if (window.__emailPreviewTimeout) {
        clearTimeout(window.__emailPreviewTimeout);
    }
    window.__emailPreviewTimeout = setTimeout(updateEmailPreview, 150);
});

const signatureSelect = document.getElementById('signature_id');
if (signatureSelect) {
    signatureSelect.addEventListener('change', updateEmailPreview);
}

// Initialise preview on first load
window.addEventListener('DOMContentLoaded', function () {
    syncAttachmentOptions();
    updateEmailPreview();
});

const emailForm = document.getElementById('emailForm');
const sendEmailButton = document.getElementById('send-email-btn');
const sendEmailStatus = document.getElementById('send-email-status');

if (emailForm && sendEmailButton) {
    emailForm.addEventListener('submit', function () {
        if (sendEmailButton.disabled) {
            return;
        }

        const scheduledAtInput = document.getElementById('scheduled_at');
        const hasFutureSchedule = scheduledAtInput && String(scheduledAtInput.value || '').trim() !== '';
        const nextLabel = hasFutureSchedule
            ? (sendEmailButton.getAttribute('data-scheduling-label') || 'Scheduling Email...')
            : (sendEmailButton.getAttribute('data-sending-label') || 'Sending Email...');

        sendEmailButton.disabled = true;
        sendEmailButton.setAttribute('aria-busy', 'true');
        sendEmailButton.style.opacity = '0.75';
        sendEmailButton.textContent = nextLabel;

        if (sendEmailStatus) {
            sendEmailStatus.style.display = 'block';
            sendEmailStatus.textContent = hasFutureSchedule
                ? 'Scheduling email. Please wait...'
                : 'Sending email. Please wait...';
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
