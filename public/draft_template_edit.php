<?php
/**
 * Edit Draft Template Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\DraftTemplates;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('drafts.manage');

$templatesModule = new DraftTemplates();

$error = null;
$success = null;
$templateId = (int) ($_GET['id'] ?? 0);

if (!$templateId) {
    header('Location: draft_templates.php');
    exit;
}

$template = $templatesModule->getById($templateId);
if (!$template) {
    header('Location: draft_templates.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $templatesModule->update($templateId, [
                'name' => $_POST['name'] ?? '',
                'purpose' => $_POST['purpose'] ?? '',
                'tone' => $_POST['tone'] ?? 'professional',
                'subject' => $_POST['subject'] ?? '',
                'body' => $_POST['body'] ?? ''
            ]);
            
            $success = 'Template updated successfully.';
            $template = $templatesModule->getById($templateId); // Refresh
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Draft Template';
ob_start();
?>

<div style="max-width: 800px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Edit Draft Template</h1>
        <p style="color: var(--charcoal-grey);">Update template details</p>
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
        
        <div style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
            <div>
                <label for="name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Template Name *</label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    required
                    value="<?php echo htmlspecialchars($_POST['name'] ?? $template['name']); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label style="display: block; margin-bottom: var(--spacing-xs); color: var(--charcoal-grey); font-weight: 500;">Type</label>
                    <div style="padding: var(--spacing-sm); background: var(--light-grey); border-radius: 4px; color: var(--charcoal-grey);">
                        <?php echo htmlspecialchars(ucfirst($template['type'])); ?>
                    </div>
                </div>
                
                <div>
                    <label for="purpose" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Purpose *</label>
                    <select 
                        id="purpose" 
                        name="purpose" 
                        required
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                        <option value="welcome" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'welcome') ? 'selected' : ''; ?>>Welcome</option>
                        <option value="follow_up" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'follow_up') ? 'selected' : ''; ?>>Follow Up</option>
                        <option value="proposal" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'proposal') ? 'selected' : ''; ?>>Proposal</option>
                        <option value="meeting" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'meeting') ? 'selected' : ''; ?>>Meeting Request</option>
                        <option value="thank_you" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'thank_you') ? 'selected' : ''; ?>>Thank You</option>
                        <option value="reminder" <?php echo (($_POST['purpose'] ?? $template['purpose']) === 'reminder') ? 'selected' : ''; ?>>Reminder</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label for="tone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Tone</label>
                <select 
                    id="tone" 
                    name="tone" 
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                    <option value="professional" <?php echo (($_POST['tone'] ?? $template['tone']) === 'professional') ? 'selected' : ''; ?>>Professional</option>
                    <option value="friendly" <?php echo (($_POST['tone'] ?? $template['tone']) === 'friendly') ? 'selected' : ''; ?>>Friendly</option>
                    <option value="casual" <?php echo (($_POST['tone'] ?? $template['tone']) === 'casual') ? 'selected' : ''; ?>>Casual</option>
                    <option value="formal" <?php echo (($_POST['tone'] ?? $template['tone']) === 'formal') ? 'selected' : ''; ?>>Formal</option>
                </select>
            </div>
            
            <?php if ($template['type'] === 'email'): ?>
                <div>
                    <label for="subject" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Subject</label>
                    <input 
                        type="text" 
                        id="subject" 
                        name="subject" 
                        value="<?php echo htmlspecialchars($_POST['subject'] ?? $template['subject'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
            <?php endif; ?>
            
            <div>
                <label for="body" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Body *</label>
                <textarea 
                    id="body" 
                    name="body" 
                    rows="12"
                    required
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"
                ><?php echo htmlspecialchars($_POST['body'] ?? $template['body']); ?></textarea>
            </div>
            
            <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                <a href="draft_templates.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                    Cancel
                </a>
                <button 
                    type="submit" 
                    style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
                >
                    Update Template
                </button>
            </div>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
