<?php
/**
 * Draft Review Page
 * 
 * Review and edit a specific draft
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\DraftReview;
use CRM\Modules\EmailDraftGenerator;
use CRM\Modules\WhatsAppDraftGenerator;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\EmailService;
use CRM\Database;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('drafts.manage');

$draftReview = new DraftReview();
$emailDraftGenerator = new EmailDraftGenerator();
$whatsAppDraftGenerator = new WhatsAppDraftGenerator();
$emailService = new EmailService();

$error = null;
$success = null;
$draftId = (int) ($_GET['id'] ?? 0);

if (!$draftId) {
    header('Location: draft_reviews.php');
    exit;
}

$draft = $draftReview->getById($draftId);
if (!$draft) {
    header('Location: draft_reviews.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'update') {
                // Update draft
                $draftReview->updateDraft($draftId, [
                    'subject' => $_POST['subject'] ?? '',
                    'body' => $_POST['body'] ?? '',
                    'tone' => $_POST['tone'] ?? 'professional'
                ]);
                $success = 'Draft updated successfully.';
                $draft = $draftReview->getById($draftId); // Refresh
            } elseif ($action === 'approve') {
                // Approve draft
                $draftReview->reviewDraft($draftId, 'approve', [
                    'subject' => $_POST['subject'] ?? $draft['subject'],
                    'body' => $_POST['body'] ?? $draft['body']
                ]);
                $success = 'Draft approved.';
                $draft = $draftReview->getById($draftId); // Refresh
            } elseif ($action === 'send') {
                // Send draft as email
                if ($draft['draft_type'] === 'email' && $draft['contact_id']) {
                    $emailService->send(
                        $draft['contact_id'],
                        $draft['contact_email'] ?? '',
                        $_POST['subject'] ?? $draft['subject'] ?? '',
                        $_POST['body'] ?? $draft['body'],
                        [
                            'body_html' => $_POST['body'] ?? $draft['body'],
                            'sender_profile' => 'outreach',
                        ]
                    );
                    
                    // Update draft status
                    Database::execute(
                        "UPDATE draft_reviews SET status = 'sent' WHERE id = ?",
                        [$draftId]
                    );
                    
                    $success = 'Email sent successfully.';
                    header('Location: emails.php');
                    exit;
                } else {
                    $error = 'Cannot send this draft type.';
                }
            } elseif ($action === 'regenerate') {
                // Regenerate draft using AI
                if ($draft['contact_id']) {
                    $purpose = $_POST['purpose'] ?? 'follow_up';
                    $tone = $_POST['tone'] ?? 'professional';
                    
                    if ($draft['draft_type'] === 'email') {
                        $newDraft = $emailDraftGenerator->generateDraft($draft['contact_id'], $purpose, $tone);
                    } else {
                        $newDraft = $whatsAppDraftGenerator->generateDraft($draft['contact_id'], $purpose, $tone);
                    }
                    
                    // Update draft with new AI-generated content
                    $draftReview->updateDraft($draftId, [
                        'subject' => $newDraft['subject'] ?? '',
                        'body' => $newDraft['body_html'] ?? $newDraft['body'] ?? '',
                        'tone' => $tone
                    ]);
                    
                    $success = 'Draft regenerated using AI.';
                    $draft = $draftReview->getById($draftId); // Refresh
                } else {
                    $error = 'Contact is required to regenerate draft.';
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get diff if available
$diff = null;
try {
    $diff = $draftReview->getDiff($draftId);
} catch (\Exception $e) {
    // Ignore
}

$pageTitle = 'Review Draft';
ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
        <div>
            <h1 style="color: var(--midnight-black); margin: 0 0 var(--spacing-xs) 0;">Review Draft</h1>
            <?php if ($draft['contact_first_name']): ?>
                <p style="color: var(--charcoal-grey); margin: 0;">
                    For: <a href="contact_view.php?id=<?php echo $draft['contact_id']; ?>" style="color: var(--accent-blue); text-decoration: none;">
                        <?php echo htmlspecialchars($draft['contact_first_name'] . ' ' . ($draft['contact_last_name'] ?? '')); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <a href="draft_reviews.php" style="color: var(--charcoal-grey); text-decoration: none;">
            ← Back to Drafts
        </a>
    </div>

    <?php if ($error): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" style="background: white; padding: var(--spacing-xl); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        
        <!-- Status and Type Info -->
        <div style="display: flex; gap: var(--spacing-md); margin-bottom: var(--spacing-lg); padding-bottom: var(--spacing-md); border-bottom: 1px solid var(--border-color);">
            <div>
                <strong style="color: var(--charcoal-grey);">Type:</strong>
                <span style="color: var(--midnight-black);"><?php echo htmlspecialchars(ucfirst($draft['draft_type'])); ?></span>
            </div>
            <div>
                <strong style="color: var(--charcoal-grey);">Status:</strong>
                <span style="color: var(--midnight-black);"><?php echo htmlspecialchars(ucfirst($draft['status'])); ?></span>
            </div>
            <div>
                <strong style="color: var(--charcoal-grey);">Tone:</strong>
                <span style="color: var(--midnight-black);"><?php echo htmlspecialchars(ucfirst($draft['tone'])); ?></span>
            </div>
        </div>

        <?php if ($draft['draft_type'] === 'email'): ?>
            <!-- Email Subject -->
            <div style="margin-bottom: var(--spacing-lg);">
                <label for="subject" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Subject *</label>
                <input 
                    type="text" 
                    id="subject" 
                    name="subject" 
                    required
                    value="<?php echo htmlspecialchars($draft['subject'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
        <?php endif; ?>

        <!-- Body -->
        <div style="margin-bottom: var(--spacing-lg);">
            <label for="body" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Message *</label>
            <textarea 
                id="body" 
                name="body" 
                rows="15"
                required
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"
            ><?php echo htmlspecialchars($draft['body'] ?? ''); ?></textarea>
        </div>

        <!-- Show Diff if available -->
        <?php if ($diff && $diff['has_changes']): ?>
            <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
                <h3 style="color: var(--midnight-black); font-size: 14px; margin: 0 0 var(--spacing-sm) 0;">Changes Made</h3>
                <p style="color: var(--charcoal-grey); font-size: 12px; margin: 0;">This draft has been edited from the original AI-generated version.</p>
            </div>
        <?php endif; ?>

        <!-- Regenerate Options (if draft status) -->
        <?php if ($draft['status'] === 'draft' && $draft['contact_id']): ?>
            <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
                <h3 style="color: var(--midnight-black); font-size: 14px; margin: 0 0 var(--spacing-sm) 0;">Regenerate with AI</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                    <div>
                        <label for="purpose" style="display: block; margin-bottom: var(--spacing-xs); color: var(--charcoal-grey); font-size: 12px;">Purpose</label>
                        <select id="purpose" name="purpose" style="width: 100%; padding: var(--spacing-xs); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="follow_up">Follow Up</option>
                            <option value="welcome">Welcome</option>
                            <option value="proposal">Proposal</option>
                            <option value="meeting">Meeting Request</option>
                            <option value="thank_you">Thank You</option>
                        </select>
                    </div>
                    <div>
                        <label for="tone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--charcoal-grey); font-size: 12px;">Tone</label>
                        <select id="tone" name="tone" style="width: 100%; padding: var(--spacing-xs); border: 1px solid var(--border-color); border-radius: 4px;">
                            <option value="professional">Professional</option>
                            <option value="friendly">Friendly</option>
                            <option value="casual">Casual</option>
                            <option value="formal">Formal</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="action" value="regenerate" style="background: var(--green); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                    Regenerate Draft
                </button>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end; margin-top: var(--spacing-lg); padding-top: var(--spacing-md); border-top: 1px solid var(--border-color);">
            <button type="submit" name="action" value="update" style="background: var(--light-grey); color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-lg); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer; font-weight: 500;">
                Save Changes
            </button>
            
            <?php if ($draft['status'] === 'draft'): ?>
                <button type="submit" name="action" value="approve" style="background: var(--green); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
                    Approve Draft
                </button>
            <?php endif; ?>
            
            <?php if ($draft['draft_type'] === 'email' && $draft['contact_id'] && in_array($draft['status'], ['approved', 'draft'])): ?>
                <button type="submit" name="action" value="send" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
                    Send Email
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
