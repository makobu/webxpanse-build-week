<?php
/**
 * Delete Email Template Page
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

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: email_templates.php?error=invalid_token');
        exit;
    }
    
    try {
        $emailTemplatesService->delete($templateId);
        header('Location: email_templates.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: email_templates.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Email Template - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Delete Email Template</h1>
    <p style="color: var(--charcoal-grey);">Are you sure you want to delete this template?</p>
</div>

<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <p style="font-weight: 500; margin-bottom: var(--spacing-sm);">Warning: This action cannot be undone!</p>
    <p style="font-size: 14px;">Deleting this template will permanently remove it from the system.</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Template Name</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($template['name'] ?? 'Untitled'); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Slug</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-family: monospace;">
            <?php echo htmlspecialchars($template['slug'] ?? ''); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Subject</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo htmlspecialchars($template['subject'] ?? ''); ?>
        </div>
    </div>
    
    <form method="POST" action="" style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <a href="email_template_view.php?slug=<?php echo urlencode($template['slug']); ?>" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
            Cancel
        </a>
        <button 
            type="submit" 
            style="background: #c33; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
        >
            Delete Template
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
