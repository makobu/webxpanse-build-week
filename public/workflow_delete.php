<?php
/**
 * Delete Workflow Page
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
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require admin role
$user = Auth::user();
if (!Authorization::can('workflows.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$automationEngine = new AutomationEngine();
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$workflowId = (int) ($_GET['id'] ?? 0);

if (!$workflowId) {
    header('Location: workflows.php');
    exit;
}

$workflow = $automationEngine->getWorkflow($workflowId);

if (!$workflow) {
    header('Location: workflows.php');
    exit;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: workflows.php?error=invalid_token');
        exit;
    }
    
    try {
        $automationEngine->deleteWorkflow($workflowId);
        header('Location: workflows.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: workflows.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Get execution count
$executionCount = (int) Database::queryOne(
    "SELECT COUNT(*) as count FROM workflow_executions WHERE workspace_id = ? AND workflow_id = ?",
    [$workspaceId, $workflowId]
)['count'] ?? 0;

// Parse workflow data
$trigger = json_decode($workflow['trigger_config'], true) ?? [];
$actions = json_decode($workflow['actions'], true) ?? [];

$triggerLabels = [
    'contact_created' => 'Contact Created',
    'email_opened' => 'Email Opened',
    'form_submitted' => 'Form Submitted',
    'stage_changed' => 'Stage Changed',
    'no_activity_for_days' => 'No Activity for Days'
];

$pageTitle = 'Delete Workflow - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Delete Workflow</h1>
    <p style="color: var(--charcoal-grey);">Are you sure you want to delete this workflow?</p>
</div>

<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <p style="font-weight: 500; margin-bottom: var(--spacing-sm);">Warning: This action cannot be undone!</p>
    <p style="font-size: 14px;">Deleting this workflow will also permanently delete all execution history.</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Workflow Name</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($workflow['name']); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Trigger</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo $triggerLabels[$trigger['type']] ?? ucfirst(str_replace('_', ' ', $trigger['type'])); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Actions</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo count($actions); ?> action<?php echo count($actions) !== 1 ? 's' : ''; ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Status</div>
        <div>
            <?php if ($workflow['is_active']): ?>
                <span style="background: #3c3; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                    Active
                </span>
            <?php else: ?>
                <span style="background: var(--light-grey); color: var(--charcoal-grey); padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                    Inactive
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Total Executions</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 24px;">
            <?php echo $executionCount; ?>
        </div>
        <?php if ($executionCount > 0): ?>
            <div style="color: #c33; font-size: 12px; margin-top: var(--spacing-xs);">
                All execution history will be permanently deleted!
            </div>
        <?php endif; ?>
    </div>
    
    <form method="POST" action="" style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <a href="workflows.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
            Cancel
        </a>
        <button 
            type="submit" 
            style="background: #c33; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
        >
            Delete Workflow
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
