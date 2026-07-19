<?php
/**
 * Edit Email Template Page
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
use CRM\Services\EmailTemplates;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$emailTemplatesService = new EmailTemplates();
$user = Auth::user();
$templateId = (int) ($_GET['id'] ?? 0);

if (!$templateId) {
    header('Location: email_templates.php');
    exit;
}

$template = $emailTemplatesService->getOwnedTemplateById($templateId, (int) ($user['id'] ?? 0));

if (!$template) {
    header('Location: email_templates.php');
    exit;
}

$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $data = [
                'name' => $_POST['name'] ?? '',
                'subject' => $_POST['subject'] ?? '',
                'body_html' => $_POST['body_html'] ?? '',
                'body_text' => $_POST['body_text'] ?? '',
                'category' => $_POST['category'] ?? 'general',
                'variables' => !empty($_POST['variables']) ? explode(',', $_POST['variables']) : [],
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $emailTemplatesService->update($templateId, $data);
            
            header('Location: email_template_view.php?slug=' . urlencode($template['slug']) . '&success=updated');
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$variables = is_string($template['variables'] ?? '') ? json_decode($template['variables'], true) : ($template['variables'] ?? []);

$pageTitle = 'Edit Email Template - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Edit Email Template</h1>
    <p style="color: var(--charcoal-grey);">Update template information</p>
</div>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 1000px;">
    <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Template Name *</label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    required
                    value="<?php echo htmlspecialchars($_POST['name'] ?? $template['name'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div>
                <label style="display: block; margin-bottom: var(--spacing-xs); color: var(--charcoal-grey); font-weight: 500;">Slug</label>
                <input 
                    type="text" 
                    value="<?php echo htmlspecialchars($template['slug'] ?? ''); ?>"
                    disabled
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; background: var(--light-grey); color: var(--charcoal-grey);"
                >
                <small style="color: var(--charcoal-grey); font-size: 12px;">Slug cannot be changed</small>
            </div>
        </div>
        
        <div>
            <label for="category" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Category</label>
            <select 
                id="category" 
                name="category" 
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="general" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'general') ? 'selected' : ''; ?>>General</option>
                <option value="welcome" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'welcome') ? 'selected' : ''; ?>>Welcome</option>
                <option value="follow_up" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'follow_up') ? 'selected' : ''; ?>>Follow-up</option>
                <option value="notification" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'notification') ? 'selected' : ''; ?>>Notification</option>
                <option value="marketing" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'marketing') ? 'selected' : ''; ?>>Marketing</option>
                <option value="support" <?php echo (($_POST['category'] ?? $template['category'] ?? 'general') === 'support') ? 'selected' : ''; ?>>Support</option>
            </select>
        </div>
        
        <div>
            <label for="subject" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Email Subject *</label>
            <input 
                type="text" 
                id="subject" 
                name="subject" 
                required
                value="<?php echo htmlspecialchars($_POST['subject'] ?? $template['subject'] ?? ''); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="body_html" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">HTML Body *</label>
            <div id="body_html_editor" style="height: 400px; margin-bottom: var(--spacing-sm);"></div>
            <textarea 
                id="body_html" 
                name="body_html" 
                required
                style="display: none;"
            ><?php echo htmlspecialchars($_POST['body_html'] ?? $template['body_html'] ?? ''); ?></textarea>
            <small style="color: var(--charcoal-grey); font-size: 12px;">Use {variable_name} for template variables (e.g., {first_name}, {last_name})</small>
        </div>
        
        <div>
            <label for="body_text" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Plain Text Body (Optional)</label>
            <textarea 
                id="body_text" 
                name="body_text" 
                rows="8"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: monospace; font-size: 13px;"
            ><?php echo htmlspecialchars($_POST['body_text'] ?? $template['body_text'] ?? ''); ?></textarea>
        </div>
        
        <div>
            <label for="variables" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Available Variables (comma-separated)</label>
            <input 
                type="text" 
                id="variables" 
                name="variables" 
                value="<?php echo htmlspecialchars($_POST['variables'] ?? implode(', ', $variables)); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label style="display: flex; align-items: center; gap: var(--spacing-xs); cursor: pointer; font-size: 14px; color: var(--midnight-black);">
                <input 
                    type="checkbox" 
                    name="is_active" 
                    value="1"
                    <?php echo (($_POST['is_active'] ?? $template['is_active'] ?? 1) ? 'checked' : ''); ?>
                    style="width: 18px; height: 18px;"
                >
                <span>Template is active</span>
            </label>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
            <a href="email_template_view.php?slug=<?php echo urlencode($template['slug']); ?>" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Update Template
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
