<?php
/**
 * Draft Templates Management Page
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

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $templatesModule->delete((int) $_POST['template_id']);
            $success = 'Template deleted successfully.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get templates
$type = $_GET['type'] ?? null;
$templates = $templatesModule->getAll($type);

$pageTitle = 'Draft Templates';
ob_start();
?>

<div style="max-width: 1400px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin: 0;">Draft Templates</h1>
        <div style="display: flex; gap: var(--spacing-sm);">
            <a href="draft_template_create.php" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; text-decoration: none; font-weight: 500;">
                + New Template
            </a>
        </div>
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

    <!-- Type Filter -->
    <div style="background: white; padding: var(--spacing-md); border-radius: 8px; margin-bottom: var(--spacing-md); box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="display: flex; gap: var(--spacing-sm);">
            <a href="?" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo !$type ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                All
            </a>
            <a href="?type=email" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $type === 'email' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Email
            </a>
            <a href="?type=whatsapp" style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $type === 'whatsapp' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                WhatsApp
            </a>
        </div>
    </div>

    <!-- Templates List -->
    <?php if (empty($templates)): ?>
        <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <p style="color: var(--charcoal-grey); margin: 0;">No templates found. <a href="draft_template_create.php" style="color: var(--accent-blue);">Create one</a></p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: var(--spacing-md);">
            <?php foreach ($templates as $template): ?>
                <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-md);">
                        <div>
                            <h3 style="color: var(--midnight-black); margin: 0 0 var(--spacing-xs) 0;">
                                <?php echo htmlspecialchars($template['name']); ?>
                            </h3>
                            <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-xs);">
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($template['type'])); ?>
                                </span>
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($template['purpose'])); ?>
                                </span>
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($template['tone'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($template['subject']): ?>
                        <div style="margin-bottom: var(--spacing-sm);">
                            <strong style="color: var(--charcoal-grey); font-size: 12px;">Subject:</strong>
                            <p style="color: var(--midnight-black); margin: var(--spacing-xs) 0 0 0; font-size: 14px;">
                                <?php echo htmlspecialchars($template['subject']); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-bottom: var(--spacing-sm);">
                        <strong style="color: var(--charcoal-grey); font-size: 12px;">Preview:</strong>
                        <p style="color: var(--charcoal-grey); margin: var(--spacing-xs) 0 0 0; font-size: 14px; max-height: 80px; overflow: hidden;">
                            <?php echo htmlspecialchars(substr(strip_tags($template['body']), 0, 150)); ?>...
                        </p>
                    </div>
                    
                    <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--border-color);">
                        <a href="draft_template_edit.php?id=<?php echo $template['id']; ?>" 
                           style="flex: 1; text-align: center; background: var(--light-grey); color: var(--charcoal-grey); padding: var(--spacing-xs) var(--spacing-sm); border-radius: 4px; text-decoration: none; font-size: 14px;">
                            Edit
                        </a>
                        <form method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this template?');">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                            <button type="submit" name="delete" style="width: 100%; background: #fee; color: #c33; padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid #fcc; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
