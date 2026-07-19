<?php
/**
 * Email View Page - Clean Version
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment (simplified)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
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
use CRM\Services\EmailService;
use CRM\Services\EmailTemplates;
use CRM\Services\WorkspaceScopeService;
use CRM\Modules\DraftTemplates;
use CRM\Modules\EmailSignatures;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$emailId = (int) ($_GET['id'] ?? 0);

if (!$emailId) {
    header('Location: emails.php');
    exit;
}

// Get email details
$email = Database::queryOne(
    "SELECT e.*, c.first_name, c.last_name, c.email as contact_email
     FROM emails e
     LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
     WHERE e.workspace_id = ? AND e.id = ?",
    [$workspaceId, $emailId]
);

if (!$email) {
    header('Location: emails.php');
    exit;
}

// Get tracking data
$tracking = Database::query(
    "SELECT * FROM email_tracking WHERE email_id = ? ORDER BY tracked_at DESC",
    [$emailId]
);

$opens = array_filter($tracking, function($t) { return $t['tracking_type'] === 'open'; });
$clicks = array_filter($tracking, function($t) { return $t['tracking_type'] === 'click'; });

// Initialize services for reply composer
$emailService = new EmailService();
$templatesService = new EmailTemplates();
$draftTemplates = new DraftTemplates();
$signaturesModule = new EmailSignatures();
$templates = $templatesService->list();
$aiDraftTemplates = $draftTemplates->getAll('email');
$signatures = $signaturesModule->getUserSignatures();
$defaultSignature = $signaturesModule->getDefault();
$error = null;
$success = null;

/**
 * Simple HTML sanitizer to prevent device discovery pop-ups
 */
function cleanEmailHtml($html) {
    if (empty($html)) return '';
    
    // Remove all <script> tags and their content
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    
    // Remove all event handlers (onclick, onload, etc.)
    $html = preg_replace('/\bon\w+\s*=\s*"[^"]*"/', '', $html);
    $html = preg_replace("/\bon\w+\s*=\s*'[^']*'/", '', $html);
    $html = preg_replace('/\bon\w+\s*=\s*[^\s>]+/', '', $html);
    
    // Remove dangerous tags that could trigger device access
    $dangerousTags = ['iframe', 'object', 'embed', 'applet', 'link'];
    foreach ($dangerousTags as $tag) {
        $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html);
        $html = preg_replace('/<' . $tag . '\b[^>]*\/?>/i', '', $html);
    }
    
    // Remove WebRTC and device-related JavaScript calls
    $html = preg_replace('/navigator\.[a-zA-Z]+/i', '', $html);
    $html = preg_replace('/RTCPeerConnection|RTCDataChannel/i', '', $html);
    $html = preg_replace('/webrtc|localnetwork|mdns|upnp/i', '', $html);
    
    return $html;
}

// Clean the email content
$cleanBodyHtml = cleanEmailHtml($email['body_html'] ?? '');
$cleanPlainText = htmlspecialchars($email['body'] ?? '', ENT_QUOTES, 'UTF-8');

// Handle reply form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $contactId = $email['contact_id'] ?? 0;
            $to = $_POST['to'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            $bodyHtml = $_POST['body_html'] ?? '';
            $templateSlug = $_POST['template_slug'] ?? '';
            $scheduledAt = $_POST['scheduled_at'] ?? null;
            $signatureId = !empty($_POST['signature_id']) ? (int) $_POST['signature_id'] : null;
            
            if (empty($to)) {
                $error = 'Recipient email is required.';
            } elseif (empty($subject)) {
                $error = 'Subject is required.';
            } elseif (empty($body) && empty($bodyHtml) && empty($templateSlug)) {
                $error = 'Email body is required.';
            } else {
                // If template is selected, render it
                if ($templateSlug) {
                    $contact = Database::queryOne(
                        "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
                        [$workspaceId, $contactId]
                    );
                    if ($contact) {
                        $variables = array_merge([
                            'first_name' => $contact['first_name'] ?? '',
                            'last_name' => $contact['last_name'] ?? '',
                            'email' => $contact['email'] ?? '',
                            'phone' => $contact['phone'] ?? '',
                            'company' => $contact['company'] ?? '',
                        ], $_POST['template_variables'] ?? []);
                        
                        $rendered = $templatesService->render($templateSlug, $variables);
                        $subject = $rendered['subject'];
                        $body = $rendered['body_text'];
                        $bodyHtml = $rendered['body_html'];
                        $to = $contact['email'] ?: $to;
                    }
                }
                
                if (!$error) {
                    // Add signature if selected
                    $finalBodyHtml = $bodyHtml ?: nl2br(htmlspecialchars($body));
                    if ($signatureId) {
                        $signature = $signaturesModule->getById($signatureId);
                        if ($signature) {
                            $finalBodyHtml = $finalBodyHtml . '<br><br>' . $signaturesModule->getSignatureHtml($signature);
                        }
                    } elseif ($defaultSignature) {
                        $finalBodyHtml = $finalBodyHtml . '<br><br>' . $signaturesModule->getSignatureHtml($defaultSignature);
                    }
                    
                    // Include original email in reply
                    $originalEmailHtml = '<br><br><div style="border-left: 3px solid #ccc; padding-left: 15px; margin-top: 20px; color: #666; font-size: 0.9em;"><strong>--- Original Message ---</strong><br>';
                    $originalEmailHtml .= '<strong>From:</strong> ' . htmlspecialchars($email['from_email'] ?? $email['contact_email'] ?? 'Unknown') . '<br>';
                    $originalEmailHtml .= '<strong>Date:</strong> ' . date('Y-m-d H:i:s', strtotime($email['created_at'])) . '<br>';
                    $originalEmailHtml .= '<strong>Subject:</strong> ' . htmlspecialchars($email['subject'] ?? 'No Subject') . '<br><br>';
                    $originalEmailHtml .= cleanEmailHtml($email['body_html'] ?? $email['body'] ?? '') . '</div>';
                    
                    $finalBodyHtml = $finalBodyHtml . $originalEmailHtml;
                    
                    $originalEmailText = "\n\n--- Original Message ---\n";
                    $originalEmailText .= "From: " . ($email['from_email'] ?? $email['contact_email'] ?? 'Unknown') . "\n";
                    $originalEmailText .= "Date: " . date('Y-m-d H:i:s', strtotime($email['created_at'])) . "\n";
                    $originalEmailText .= "Subject: " . ($email['subject'] ?? 'No Subject') . "\n\n";
                    $originalEmailText .= strip_tags($email['body_html'] ?? $email['body'] ?? '');
                    
                    $finalBody = $body . $originalEmailText;
                    
                    $options = [
                        'body_html' => $finalBodyHtml,
                        'scheduled_at' => $scheduledAt ? date('Y-m-d H:i:s', strtotime($scheduledAt)) : null,
                        'in_reply_to' => $email['message_id'] ?? null,
                        'sender_profile' => in_array((string) ($email['sender_profile'] ?? ''), ['nurture', 'nurture_email'], true) ? 'nurture' : 'outreach',
                    ];
                    
                    $uuid = $emailService->send($contactId, $to, $subject, $finalBody, $options);
                    
                    header('Location: email_view.php?id=' . $emailId);
                    exit;
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = htmlspecialchars($email['subject'] ?? 'Email') . ' - CRM';
ob_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <!-- Include Quill Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        :root {
            --midnight-black: #1a1a2e;
            --charcoal-grey: #4a4a68;
            --light-grey: #f5f5f7;
            --border-color: #e0e0e0;
            --accent-blue: #3b82f6;
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
            color: var(--midnight-black);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--spacing-xl);
            flex-wrap: wrap;
            gap: var(--spacing-md);
        }
        
        .header h1 {
            margin: 0 0 var(--spacing-sm) 0;
            font-size: 24px;
        }
        
        .header-subtitle {
            color: var(--charcoal-grey);
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
        }
        
        .btn {
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background: var(--accent-blue);
            color: white;
        }
        
        .btn-secondary {
            background: white;
            color: var(--charcoal-grey);
            border: 1px solid var(--border-color);
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
        }
        
        .card {
            background: white;
            padding: var(--spacing-xl);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .card h2 {
            margin: 0 0 var(--spacing-md) 0;
            font-size: 18px;
        }
        
        .info-item {
            margin-bottom: var(--spacing-md);
        }
        
        .label {
            color: var(--charcoal-grey);
            font-size: 14px;
            margin-bottom: var(--spacing-xs);
        }
        
        .value {
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-block;
            background: var(--light-grey);
            color: var(--midnight-black);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            text-transform: capitalize;
            font-weight: 500;
        }
        
        .email-content {
            background: white;
            padding: var(--spacing-xl);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .email-preview {
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: var(--spacing-lg);
            background: #f9f9f9;
            max-height: 500px;
            overflow-y: auto;
            margin-top: var(--spacing-md);
        }
        
        .tracking-stats {
            display: flex;
            gap: var(--spacing-xl);
            margin-bottom: var(--spacing-md);
        }
        
        .stat {
            flex: 1;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: var(--midnight-black);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--charcoal-grey);
            margin-bottom: var(--spacing-xs);
        }
        
        .activity-list {
            max-height: 200px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: var(--spacing-xs);
        }
        
        .activity-item {
            padding: var(--spacing-xs) var(--spacing-sm);
            background: var(--light-grey);
            border-radius: 4px;
            font-size: 12px;
        }
        
        .activity-type {
            text-transform: capitalize;
            font-weight: 500;
            margin-right: 4px;
        }
        
        .activity-time {
            color: var(--charcoal-grey);
            font-size: 11px;
            margin-top: 2px;
        }
        
        .error-message {
            color: #dc2626;
            background: #fee2e2;
            padding: var(--spacing-sm);
            border-radius: 4px;
            font-size: 14px;
            margin-top: var(--spacing-xs);
        }
        
        .link {
            color: var(--accent-blue);
            text-decoration: none;
        }
        
        .link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
            }
            
            .header-actions {
                width: 100%;
            }
            
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><?php echo htmlspecialchars($email['subject'] ?? 'Email'); ?></h1>
                <p class="header-subtitle">Email Details</p>
            </div>
            <div class="header-actions">
                <?php if (!empty($email['contact_id'])): ?>
                <a href="contact_view.php?id=<?php echo (int)$email['contact_id']; ?>" class="btn btn-primary">
                    View Contact
                </a>
                <?php endif; ?>
                <a href="emails.php" class="btn btn-secondary">
                    Back to Emails
                </a>
            </div>
        </div>
        
        <div class="grid">
            <!-- Email Information Card -->
            <div class="card">
                <h2>Email Information</h2>
                
                <div class="info-item">
                    <div class="label">Status</div>
                    <div class="status-badge"><?php echo htmlspecialchars($email['status'] ?? 'unknown'); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="label">To</div>
                    <div class="value"><?php echo htmlspecialchars($email['to_email'] ?? ''); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="label">From</div>
                    <div class="value">
                        <?php 
                        $fromName = htmlspecialchars($email['from_name'] ?? '');
                        $fromEmail = htmlspecialchars($email['from_email'] ?? '');
                        echo $fromName . ($fromEmail ? ' &lt;' . $fromEmail . '&gt;' : '');
                        ?>
                    </div>
                </div>
                
                <?php if (!empty($email['contact_id'])): ?>
                <div class="info-item">
                    <div class="label">Contact</div>
                    <div class="value">
                        <a href="contact_view.php?id=<?php echo (int)$email['contact_id']; ?>" class="link">
                            <?php 
                            $firstName = htmlspecialchars($email['first_name'] ?? '');
                            $lastName = htmlspecialchars($email['last_name'] ?? '');
                            echo trim($firstName . ' ' . $lastName) ?: 'Unknown Contact';
                            ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <div class="label">Subject</div>
                    <div class="value"><?php echo htmlspecialchars($email['subject'] ?? ''); ?></div>
                </div>
                
                <?php if (!empty($email['sent_at'])): ?>
                <div class="info-item">
                    <div class="label">Sent</div>
                    <div class="value"><?php echo date('F j, Y g:i A', strtotime($email['sent_at'])); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($email['opened_at'])): ?>
                <div class="info-item">
                    <div class="label">Opened</div>
                    <div class="value"><?php echo date('F j, Y g:i A', strtotime($email['opened_at'])); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($email['clicked_at'])): ?>
                <div class="info-item">
                    <div class="label">Clicked</div>
                    <div class="value"><?php echo date('F j, Y g:i A', strtotime($email['clicked_at'])); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($email['error_message'])): ?>
                <div class="info-item">
                    <div class="label">Error</div>
                    <div class="error-message">
                        <?php echo htmlspecialchars(substr($email['error_message'], 0, 200)); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tracking Information Card -->
            <div class="card">
                <h2>Tracking Information</h2>
                
                <div class="tracking-stats">
                    <div class="stat">
                        <div class="stat-label">Opens</div>
                        <div class="stat-number"><?php echo count($opens); ?></div>
                        <?php if (!empty($opens)): ?>
                        <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 4px;">
                            Last: <?php echo date('M j, Y g:i A', strtotime($opens[0]['tracked_at'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-label">Clicks</div>
                        <div class="stat-number"><?php echo count($clicks); ?></div>
                        <?php if (!empty($clicks)): ?>
                        <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 4px;">
                            Last: <?php echo date('M j, Y g:i A', strtotime($clicks[0]['tracked_at'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($tracking)): ?>
                <div class="info-item">
                    <div class="label">Recent Activity</div>
                    <div class="activity-list">
                        <?php foreach (array_slice($tracking, 0, 10) as $track): ?>
                        <div class="activity-item">
                            <span class="activity-type"><?php echo htmlspecialchars($track['tracking_type']); ?></span>
                            <?php if (!empty($track['clicked_url'])): ?>
                            - <?php echo htmlspecialchars(substr($track['clicked_url'], 0, 50)); ?>
                            <?php endif; ?>
                            <div class="activity-time">
                                <?php echo date('M j, Y g:i A', strtotime($track['tracked_at'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Email Content -->
        <div class="email-content">
            <h2>Email Content</h2>
            
            <div class="email-preview">
                <?php if (!empty($cleanBodyHtml)): ?>
                    <?php echo $cleanBodyHtml; ?>
                <?php else: ?>
                    <pre style="white-space: pre-wrap; font-family: inherit; margin: 0;"><?php echo $cleanPlainText; ?></pre>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Reply Composer -->
        <div class="email-content" style="margin-top: var(--spacing-xl);">
            <h2>Reply</h2>
            
            <?php if ($error): ?>
                <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="replyForm" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="reply" value="1">
                
                <!-- AI Draft Generation Section -->
                <div style="background: linear-gradient(135deg, rgba(0, 102, 204, 0.05) 0%, rgba(255, 255, 255, 0) 100%); border: 2px solid var(--accent-blue); border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
                    <div style="display: flex; align-items: center; gap: var(--spacing-sm); margin-bottom: var(--spacing-md);">
                        <span style="font-size: 24px;">ðŸ¤–</span>
                        <label style="display: block; margin: 0; color: var(--midnight-black); font-weight: 600; font-size: 16px;">AI-Powered Reply Draft</label>
                    </div>
                    <p style="color: var(--charcoal-grey); font-size: 13px; margin-bottom: var(--spacing-md);">
                        Let AI help you compose a professional reply. Choose the purpose and tone, then click Generate.
                    </p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                        <div>
                            <label for="reply_draft_purpose" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500; font-size: 13px;">Purpose</label>
                            <select id="reply_draft_purpose" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                <option value="reply">Reply</option>
                                <option value="follow_up">Follow Up</option>
                                <option value="clarification">Clarification</option>
                                <option value="thank_you">Thank You</option>
                            </select>
                        </div>
                        <div>
                            <label for="reply_draft_tone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500; font-size: 13px;">Tone</label>
                            <select id="reply_draft_tone" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                <option value="professional">Professional</option>
                                <option value="friendly">Friendly</option>
                                <option value="casual">Casual</option>
                                <option value="formal">Formal</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" id="reply-generate-draft-btn" onclick="generateReplyDraft()" style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); color: #ffffff !important; padding: var(--spacing-md) var(--spacing-lg); border: none; border-radius: 6px; cursor: pointer; font-weight: 600; width: 100%; font-size: 15px; box-shadow: 0 2px 8px rgba(46, 204, 113, 0.3);">
                        âœ¨ Generate AI Reply Draft
                    </button>
                    <div id="reply-ai-status" style="margin-top: var(--spacing-sm); font-size: 12px; color: var(--charcoal-grey);"></div>
                </div>
                
                <div>
                    <label for="reply_to" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">To *</label>
                    <input type="email" id="reply_to" name="to" required value="<?php echo htmlspecialchars($email['from_email'] ?? $email['contact_email'] ?? ''); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                
                <div>
                    <label for="reply_subject" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Subject *</label>
                    <input type="text" id="reply_subject" name="subject" required value="Re: <?php echo htmlspecialchars($email['subject'] ?? ''); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                
                <div>
                    <label for="reply_template_slug" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Use Template (Optional)</label>
                    <select id="reply_template_slug" name="template_slug" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;" onchange="loadReplyTemplate(this.value)">
                        <option value="">No template (compose manually)</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo htmlspecialchars($template['slug']); ?>"><?php echo htmlspecialchars($template['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="reply_body" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Message *</label>
                    <div id="reply_editor" style="height: 300px; margin-bottom: var(--spacing-md);"></div>
                    <textarea id="reply_body" name="body" required style="display: none;"></textarea>
                    <textarea id="reply_body_html" name="body_html" style="display: none;"></textarea>
                    
                    <!-- Live HTML preview -->
                    <div style="margin-top: var(--spacing-md);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-xs);">
                            <span style="color: var(--midnight-black); font-weight: 500; font-size: 14px;">Live Preview</span>
                        </div>
                        <div style="border: 1px solid var(--border-color); border-radius: 6px; overflow: hidden; background: #f8fafc;">
                            <iframe id="reply-preview-frame" style="width: 100%; height: 260px; border: 0; background: #ffffff;" title="Email preview"></iframe>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($signatures)): ?>
                <div>
                    <label for="reply_signature_id" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Email Signature (Optional)</label>
                    <select id="reply_signature_id" name="signature_id" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="">No signature</option>
                        <?php if ($defaultSignature): ?>
                            <option value="<?php echo $defaultSignature['id']; ?>" selected><?php echo htmlspecialchars($defaultSignature['name']); ?> (Default)</option>
                        <?php endif; ?>
                        <?php foreach ($signatures as $sig): ?>
                            <?php if (!$defaultSignature || $sig['id'] != $defaultSignature['id']): ?>
                                <option value="<?php echo $sig['id']; ?>"><?php echo htmlspecialchars($sig['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div>
                    <label for="reply_scheduled_at" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Schedule Email (Optional)</label>
                    <input type="datetime-local" id="reply_scheduled_at" name="scheduled_at" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end;">
                    <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                        Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/js/ai-ui-consistency.js?v=<?php echo (int) @filemtime(__DIR__ . '/assets/js/ai-ui-consistency.js'); ?>"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
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

        function hydrateReplyComposer(bodyHtml, bodyText) {
            const html = stripDangerousPreviewMarkup(bodyHtml || '');
            const text = String(bodyText || '').trim();
            if (looksLikeRawDraftPayload(html) || looksLikeRawDraftPayload(text)) {
                return false;
            }

            if (window.replyQuill) {
                if (html) {
                    replyQuill.root.innerHTML = html;
                } else {
                    replyQuill.setText(text);
                }
                updateReplyPreview();
                return true;
            }

            return false;
        }

        // Minimal JavaScript - only for basic interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure all links in email content open in new tab
            document.querySelectorAll('.email-preview a').forEach(function(link) {
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
            });
            
            // Initialize Quill editor for reply
            if (document.getElementById('reply_editor')) {
                window.replyQuill = new Quill('#reply_editor', {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            [{ 'color': [] }, { 'background': [] }],
                            ['link'],
                            ['clean']
                        ]
                    }
                });
                
                // Update preview when editor changes
                replyQuill.on('text-change', function() {
                    updateReplyPreview();
                });
                
                // Initial preview update
                updateReplyPreview();
            }
        });
        
        function updateReplyPreview() {
            if (!window.replyQuill) return;
            
            const html = replyQuill.root.innerHTML;
            const text = replyQuill.getText();
            document.getElementById('reply_body').value = text;
            document.getElementById('reply_body_html').value = html;
            
            const previewFrame = document.getElementById('reply-preview-frame');
            if (!previewFrame) return;
            
            const sanitized = stripDangerousPreviewMarkup(html || '');
            if (looksLikeRawDraftPayload(sanitized)) {
                previewFrame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><link rel="icon" href="data:,"></head><body style="margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,sans-serif;color:#6b7280;">Draft preview unavailable for malformed content.</body></html>';
                return;
            }

            const bodyContent = sanitized || text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\n/g, '<br>');
            const fullHtml = `<!DOCTYPE html>
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
            
            const doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
            doc.open();
            doc.write(fullHtml);
            doc.close();
        }
        
        // Form submission handler
        document.getElementById('replyForm')?.addEventListener('submit', function(e) {
            updateReplyPreview();
            const bodyText = document.getElementById('reply_body').value.trim();
            const bodyHtml = document.getElementById('reply_body_html').value.trim();
            if (!bodyText && !bodyHtml) {
                e.preventDefault();
                alert('Please enter a message.');
                return false;
            }
        });
        
        async function generateReplyDraft() {
            const contactId = <?php echo $email['contact_id'] ?? 0; ?>;
            if (!contactId) {
                alert('Contact ID not found.');
                return;
            }
            
            const purpose = document.getElementById('reply_draft_purpose').value;
            const tone = document.getElementById('reply_draft_tone').value;
            
            const btn = document.getElementById('reply-generate-draft-btn');
            const statusDiv = document.getElementById('reply-ai-status');
            const originalText = btn.textContent;
            
            btn.disabled = true;
            btn.textContent = 'âœ¨ Generating with AI...';
            btn.style.opacity = '0.7';
            statusDiv.innerHTML = '<span style="color: var(--accent-blue);">ðŸ”„ AI is generating your reply...</span>';
            
            try {
                const response = await fetch('../api/generate_draft.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>'
                    },
                    body: JSON.stringify({
                        contact_id: contactId,
                        type: 'email',
                        purpose: purpose,
                        tone: tone,
                        options: {
                            surface: 'email_view'
                        },
                        context: 'This is a reply to: <?php echo addslashes($email['subject'] ?? ''); ?>'
                    })
                });
                
                const contentType = response.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                    const draft = data.draft || {};
                    if (window.AIUiConsistency) {
                        statusDiv.innerHTML = window.AIUiConsistency.renderStatusPanel({
                            policy: data.policy || {},
                            summary: data.summary_text || 'Draft generated successfully.',
                            explanation: draft.explanation || '',
                            fallback: !!data.fallback,
                            qualityScore: draft.context_bundle_quality && typeof draft.context_bundle_quality.context_quality_score === 'number'
                                ? draft.context_bundle_quality.context_quality_score
                                : null,
                            promptWarnings: (draft.context_bundle_quality && draft.context_bundle_quality.warnings) || [],
                            mode: data.mode || 'email_view'
                        });
                    } else {
                        statusDiv.innerHTML = '<span style="color: #27ae60;">' + (data.summary_text || 'Draft generated successfully.') + '</span>';
                    }
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Server returned invalid response.');
                }
                
                if (response.ok && data.success) {
                    const draft = data.draft || {};
                    if (!hydrateReplyComposer(draft.body_html || '', draft.body_text || '')) {
                        throw new Error('AI returned an invalid reply draft.');
                    }
                    
                    if (draft.subject) {
                        document.getElementById('reply_subject').value = draft.subject;
                    }

                    if (window.AIUiConsistency) {
                        statusDiv.innerHTML = window.AIUiConsistency.renderStatusPanel({
                            policy: data.policy || {},
                            summary: data.summary_text || 'Draft generated successfully.',
                            explanation: draft.explanation || '',
                            fallback: !!data.fallback,
                            qualityScore: draft.context_bundle_quality && typeof draft.context_bundle_quality.context_quality_score === 'number'
                                ? draft.context_bundle_quality.context_quality_score
                                : null,
                            promptWarnings: (draft.context_bundle_quality && draft.context_bundle_quality.warnings) || [],
                            mode: data.mode || 'email_view'
                        });
                    } else {
                        statusDiv.innerHTML = '<span style="color: #27ae60;">Draft generated successfully.</span>';
                    }
                } else {
                    const errorMsg = data.error || 'Failed to generate draft';
                    statusDiv.innerHTML = '<span style="color: #c33;">Error: ' + errorMsg + '</span>';
                }
            } catch (error) {
                console.error('Error:', error);
                statusDiv.innerHTML = '<span style="color: #c33;">Error: ' + error.message + '</span>';
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
                btn.style.opacity = '1';
            }
        }
        
        async function loadReplyTemplate(slug) {
            if (!slug) return;
            
            const contactId = <?php echo $email['contact_id'] ?? 0; ?>;
            if (!contactId) {
                alert('Contact ID not found.');
                return;
            }
            
            try {
                const response = await fetch(`../api/email_template_preview.php?slug=${encodeURIComponent(slug)}&contact_id=${contactId}`);
                const contentType = response.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Server returned invalid response.');
                }
                
                if (data.success) {
                    if (data.subject) {
                        document.getElementById('reply_subject').value = data.subject;
                    }
                    if (!hydrateReplyComposer(data.body_html || '', data.body_text || '')) {
                        throw new Error('Template preview returned invalid reply content.');
                    }
                } else {
                    alert('Error loading template: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error loading template:', error);
                alert('Failed to load template: ' + error.message);
            }
        }
    </script>
</body>
</html>
<?php
// End output
ob_end_flush();
?>


