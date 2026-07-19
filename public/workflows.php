<?php
/**
 * Workflows List Page
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
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceScopeService;

function workflowTableExists(string $tableName): bool
{
    $row = Database::queryOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name = ?",
        [$tableName]
    );

    return ((int) ($row['cnt'] ?? 0)) > 0;
}

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
$canWorkflowAutomationSettings = Authorization::can('settings.workflow_automation', $user);
$canWorkflowApprovals = Authorization::can('workflow_automation.approvals', $user);
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

// Handle workflow activation/deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_workflow'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: workflows.php?error=invalid_token');
        exit;
    }
    
    $workflowId = (int) ($_POST['workflow_id'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    Database::execute(
        "UPDATE workflows SET is_active = ? WHERE workspace_id = ? AND id = ?",
        [$isActive, $workspaceId, $workflowId]
    );
    \CRM\Services\WorkflowExecutionService::invalidateWorkflowCache($workflowId);
    
    header('Location: workflows.php?success=toggled');
    exit;
}

$hasWorkflowQueue = workflowTableExists('workflow_queue');
$hasWorkflowRetryQueue = workflowTableExists('workflow_retry_queue');

$queueSelect = $hasWorkflowQueue
    ? 'AVG(wq.queue_latency_ms) as avg_queue_latency_ms'
    : '0 as avg_queue_latency_ms';
$queueJoin = $hasWorkflowQueue
    ? 'LEFT JOIN workflow_queue wq ON w.id = wq.workflow_id AND wq.workspace_id = w.workspace_id'
    : '';
$retrySelect = $hasWorkflowRetryQueue
    ? "COUNT(DISTINCT wrq.id) as retry_count"
    : '0 as retry_count';
$retryJoin = $hasWorkflowRetryQueue
    ? "LEFT JOIN workflow_retry_queue wrq ON w.id = wrq.workflow_id AND wrq.workspace_id = w.workspace_id AND wrq.status = 'pending'"
    : '';

// Get all workflows
$workflows = Database::query(
    "SELECT w.*, 
            COUNT(we.id) as execution_count,
            COUNT(CASE WHEN we.status = 'completed' THEN 1 END) as success_count,
            COUNT(CASE WHEN we.status = 'failed' THEN 1 END) as failed_count,
            {$queueSelect},
            {$retrySelect}
     FROM workflows w
     LEFT JOIN workflow_executions we ON w.id = we.workflow_id AND we.workspace_id = w.workspace_id
     {$queueJoin}
     {$retryJoin}
     WHERE w.workspace_id = ?
     GROUP BY w.id
     ORDER BY w.created_at DESC",
    [$workspaceId]
);

// Parse workflow data
foreach ($workflows as &$workflow) {
    $workflow['trigger_config'] = json_decode($workflow['trigger_config'], true) ?? [];
    $workflow['actions'] = json_decode($workflow['actions'], true) ?? [];
    $workflow['conditions'] = json_decode($workflow['conditions'], true) ?? [];
    $workflow['graph_json'] = json_decode($workflow['graph_json'] ?? 'null', true);
}

$pageTitle = 'Workflows - ' . brandProductName();
$workflowsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_WORKFLOWS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Workflows</h1>
                <p>Automate your growth workflows</p>
            </div>
            <div class="page-header-actions">
                <?php if ($workflowsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_WORKFLOWS, 'Workflows page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <?php if ($canWorkflowApprovals): ?>
                <a href="workflow_approvals.php" class="btn-premium-secondary">
                    Approvals
                </a>
                <?php endif; ?>
                <?php if ($canWorkflowAutomationSettings): ?>
                <a href="settings.php?tab=workflow_automation" class="btn-premium-secondary">
                    Automation Settings
                </a>
                <?php endif; ?>
                <a href="workflow_templates.php" class="btn-premium-secondary">
                    Templates
                </a>
                <a href="workflow_analytics.php" class="btn-premium-secondary">
                    Analytics
                </a>
                <a href="workflow_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Create Workflow
                </a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="content-card" style="background: #d1fae5; border-color: #10b981; color: #065f46; margin-bottom: 1rem;">
                Workflow updated successfully!
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="content-card" style="background: #fee2e2; border-color: #ef4444; color: #991b1b; margin-bottom: 1rem;">
                An error occurred. Please try again.
            </div>
        <?php endif; ?>

        <!-- Recommended for you -->
        <div id="workflow-recommendations" class="content-card" style="margin-bottom: 1.5rem; display: none;">
            <h3 style="margin: 0 0 1rem 0; color: #0f172a; font-size: 1rem;">
                <i class="fas fa-magic" style="color: #667eea; margin-right: 0.5rem;"></i>Recommended for you
            </h3>
            <div id="workflow-recommendations-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;"></div>
        </div>

        <!-- Workflows List -->
        <div class="table-card">
            <?php if (empty($workflows)): ?>
                <div class="empty-state">
                    <p>No workflows found.</p>
                    <a href="workflow_create.php">
                        Create your first workflow →
                    </a>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column;">
                    <?php foreach ($workflows as $workflow): 
                        $trigger = $workflow['trigger_config'];
                        $triggerType = $trigger['type'] ?? 'unknown';
                        $triggerLabels = [
                            'contact_created' => 'Contact Created',
                            'email_opened' => 'Email Opened',
                            'form_submitted' => 'Form Submitted',
                            'stage_changed' => 'Stage Changed',
                            'no_activity_for_days' => 'No Activity for Days'
                        ];
                        $actionCount = count($workflow['actions']);
                    ?>
                        <div style="padding: 1.5rem; border-bottom: 1px solid rgba(0, 0, 0, 0.1); display: flex; justify-content: space-between; align-items: start; transition: background 0.2s ease;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                    <h3 style="color: #0f172a; font-size: 1.125rem; margin: 0;">
                                        <?php echo htmlspecialchars($workflow['name']); ?>
                                    </h3>
                                    <?php if ($workflow['is_active']): ?>
                                        <span class="badge badge-success">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-default">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge badge-default">
                                        <?php echo strtoupper($workflow['workflow_mode'] ?? 'mixed'); ?>
                                    </span>
                                    <span class="badge <?php echo ($workflow['migration_status'] ?? 'pending') === 'migrated' ? 'badge-success' : 'badge-default'; ?>">
                                        <?php echo htmlspecialchars($workflow['migration_status'] ?? 'pending'); ?>
                                    </span>
                                </div>
                                
                                <div style="display: flex; gap: 1.5rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                    <div>
                                        <span style="color: #64748b; font-size: 0.875rem;">Trigger: </span>
                                        <span style="color: #0f172a; font-weight: 500;">
                                            <?php echo $triggerLabels[$triggerType] ?? ucfirst(str_replace('_', ' ', $triggerType)); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span style="color: #64748b; font-size: 0.875rem;">Actions: </span>
                                        <span style="color: #0f172a; font-weight: 500;">
                                            <?php echo $actionCount; ?> action<?php echo $actionCount !== 1 ? 's' : ''; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span style="color: #64748b; font-size: 0.875rem;">Graph: </span>
                                        <span style="color: #0f172a; font-weight: 500;">
                                            v<?php echo (int) ($workflow['builder_version'] ?? 1); ?> /
                                            <?php echo count($workflow['graph_json']['nodes'] ?? []); ?> nodes
                                        </span>
                                    </div>
                                    <div>
                                        <span style="color: #64748b; font-size: 0.875rem;">Executions: </span>
                                        <span style="color: #0f172a; font-weight: 500;">
                                            <?php echo $workflow['execution_count']; ?>
                                        </span>
                                        <?php if ($workflow['success_count'] > 0 || $workflow['failed_count'] > 0): ?>
                                            <span style="color: #64748b; font-size: 0.75rem; margin-left: 0.5rem;">
                                                (<?php echo $workflow['success_count']; ?> success, <?php echo $workflow['failed_count']; ?> failed)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span style="color: #64748b; font-size: 0.875rem;">Queue lag: </span>
                                        <span style="color: #0f172a; font-weight: 500;">
                                            <?php echo number_format((float) ($workflow['avg_queue_latency_ms'] ?? 0), 0); ?>ms
                                        </span>
                                        <span style="color: #64748b; font-size: 0.75rem; margin-left: 0.5rem;">
                                            <?php echo (int) ($workflow['retry_count'] ?? 0); ?> pending retries
                                        </span>
                                    </div>
                                </div>
                                
                                <div style="color: #64748b; font-size: 0.75rem;">
                                    Created <?php echo date('M j, Y', strtotime($workflow['created_at'])); ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 0.75rem; align-items: center;">
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="workflow_id" value="<?php echo $workflow['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $workflow['is_active'] ? '0' : '1'; ?>">
                                    <input type="hidden" name="toggle_workflow" value="1">
                                    <button 
                                        type="submit" 
                                        class="btn-premium-secondary"
                                        style="background: <?php echo $workflow['is_active'] ? '#f59e0b' : '#10b981'; ?>; color: white; border: none; padding: 0.5rem 0.75rem; font-size: 0.75rem;"
                                    >
                                        <?php echo $workflow['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <a href="workflow_edit.php?id=<?php echo $workflow['id']; ?>" style="color: #667eea; text-decoration: none; font-size: 0.875rem;">Edit</a>
                                <a href="workflow_view.php?id=<?php echo $workflow['id']; ?>" style="color: #64748b; text-decoration: none; font-size: 0.875rem;">View</a>
                                <a href="workflow_delete.php?id=<?php echo $workflow['id']; ?>" onclick="return confirm('Are you sure you want to delete this workflow? This will also delete all execution history.');" style="color: #ef4444; text-decoration: none; font-size: 0.875rem;">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_WORKFLOWS, 'How to use Workflows', $workflowsGuideVideoUrl); ?>

<script>
(function() {
    fetch('../api/workflows/recommendations.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const recs = data.recommended_workflows || data.recommended_templates || [];
            const container = document.getElementById('workflow-recommendations');
            const list = document.getElementById('workflow-recommendations-list');
            if (recs.length > 0 && list) {
                list.innerHTML = recs.slice(0, 5).map(r => {
                    const t = r.template || {};
                    const tid = t.id || r.template_id || '';
                    const name = (r.name || t.name || '').replace(/"/g, '&quot;');
                    const reason = (r.reason || '').replace(/"/g, '&quot;');
                    const impact = (r.impact || 'medium').toLowerCase();
                    const match = r.template_match || {};
                    const matchText = match.template_name ? ('Email: ' + match.template_name + ' · ' + (match.confidence || 'matched')) : 'Email template auto-pick ready';
                    return '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem;"><div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem;">' + name + '</div><div style="font-size: 0.875rem; color: #64748b; margin-bottom: 0.75rem;">' + reason + '</div><div style="font-size:0.78rem;color:#475569;margin-bottom:0.75rem;">' + matchText.replace(/"/g, '&quot;') + '</div><a href="workflow_create.php?template_id=' + tid + '" class="btn-premium-secondary" style="display: inline-block; padding: 0.5rem 0.75rem; font-size: 0.875rem; text-decoration: none;">Use template</a></div>';
                }).join('');
                container.style.display = 'block';
            }
        })
        .catch(() => {});
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
