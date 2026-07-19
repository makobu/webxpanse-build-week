<?php
/**
 * Create Draft Template Page
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $templateId = $templatesModule->create([
                'name' => $_POST['name'] ?? '',
                'type' => $_POST['type'] ?? 'email',
                'purpose' => $_POST['purpose'] ?? '',
                'tone' => $_POST['tone'] ?? 'professional',
                'subject' => $_POST['subject'] ?? '',
                'body' => $_POST['body'] ?? '',
                'variables' => ['first_name', 'last_name', 'email', 'company']
            ]);
            
            header('Location: draft_templates.php');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Draft Template';
ob_start();
?>

<div style="max-width: 800px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Create Draft Template</h1>
        <p style="color: var(--charcoal-grey);">Create a reusable AI draft template</p>
    </div>

    <?php if ($error): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
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
                    value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                    placeholder="e.g., Welcome Email Template"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="type" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Type *</label>
                    <select 
                        id="type" 
                        name="type" 
                        required
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        onchange="toggleSubjectField()"
                    >
                        <option value="email" <?php echo (($_POST['type'] ?? 'email') === 'email') ? 'selected' : ''; ?>>Email</option>
                        <option value="whatsapp" <?php echo (($_POST['type'] ?? '') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                    </select>
                </div>
                
                <div>
                    <label for="purpose" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Purpose *</label>
                    <select 
                        id="purpose" 
                        name="purpose" 
                        required
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                        <option value="welcome">Welcome</option>
                        <option value="follow_up">Follow Up</option>
                        <option value="proposal">Proposal</option>
                        <option value="meeting">Meeting Request</option>
                        <option value="thank_you">Thank You</option>
                        <option value="reminder">Reminder</option>
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
                    <option value="professional" <?php echo (($_POST['tone'] ?? 'professional') === 'professional') ? 'selected' : ''; ?>>Professional</option>
                    <option value="friendly" <?php echo (($_POST['tone'] ?? '') === 'friendly') ? 'selected' : ''; ?>>Friendly</option>
                    <option value="casual" <?php echo (($_POST['tone'] ?? '') === 'casual') ? 'selected' : ''; ?>>Casual</option>
                    <option value="formal" <?php echo (($_POST['tone'] ?? '') === 'formal') ? 'selected' : ''; ?>>Formal</option>
                </select>
            </div>
            
            <div id="subject-field" style="display: <?php echo (($_POST['type'] ?? 'email') === 'email') ? 'block' : 'none'; ?>;">
                <label for="subject" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Subject</label>
                <input 
                    type="text" 
                    id="subject" 
                    name="subject" 
                    value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>"
                    placeholder="e.g., Welcome, {first_name}!"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                <small style="color: var(--charcoal-grey); font-size: 12px;">Use {first_name}, {last_name}, {email}, {company} for personalization</small>
            </div>
            
            <div>
                <label for="body" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Body *</label>
                <textarea 
                    id="body" 
                    name="body" 
                    rows="12"
                    required
                    placeholder="Enter template body. Use {first_name}, {last_name}, {email}, {company} for variables."
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"
                ><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
                <small style="color: var(--charcoal-grey); font-size: 12px;">Available variables: {first_name}, {last_name}, {full_name}, {email}, {company}, {phone}</small>
            </div>
            
            <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                <a href="draft_templates.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                    Cancel
                </a>
                <button 
                    type="submit" 
                    style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
                >
                    Create Template
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function toggleSubjectField() {
    const type = document.getElementById('type').value;
    const subjectField = document.getElementById('subject-field');
    subjectField.style.display = type === 'email' ? 'block' : 'none';
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
