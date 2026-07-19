<?php
/**
 * Workflow View Page
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

$workflowId = (int) ($_GET['id'] ?? 0);

if (!$workflowId) {
    header('Location: workflows.php');
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$workflow = Database::queryOne(
    "SELECT * FROM workflows WHERE workspace_id = ? AND id = ?",
    [$workspaceId, $workflowId]
);

if (!$workflow) {
    header('Location: workflows.php');
    exit;
}

// Parse workflow data
$trigger = json_decode($workflow['trigger_config'], true) ?? [];
$actions = json_decode($workflow['actions'], true) ?? [];
$conditions = json_decode($workflow['conditions'], true) ?? [];
$graph = json_decode($workflow['graph_json'] ?? 'null', true) ?? [];
$nodeCount = count($graph['nodes'] ?? []);
$edgeCount = count($graph['edges'] ?? []);

// Get execution history
$executions = Database::query(
    "SELECT we.*, c.first_name, c.last_name, c.email 
     FROM workflow_executions we
     JOIN contacts c ON we.contact_id = c.id AND c.workspace_id = we.workspace_id
     WHERE we.workspace_id = ?
       AND we.workflow_id = ?
     ORDER BY we.executed_at DESC
     LIMIT 50",
    [$workspaceId, $workflowId]
);

$nodeHotspots = Database::query(
    "SELECT node_label, node_type, COUNT(*) as run_count, COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
     FROM workflow_node_runs
     WHERE workspace_id = ?
       AND workflow_id = ?
     GROUP BY node_id, node_label, node_type
     ORDER BY failed_count DESC, run_count DESC
     LIMIT 8",
    [$workspaceId, $workflowId]
);

$triggerLabels = [
    'contact_created' => 'Contact Created',
    'email_opened' => 'Email Opened',
    'whatsapp_message_received' => 'WhatsApp Message Received',
    'form_submitted' => 'Form Submitted',
    'stage_changed' => 'Stage Changed',
    'no_activity_for_days' => 'No Activity for Days'
];

$actionLabels = [
    'send_email' => 'Send Email',
    'send_whatsapp' => 'Send WhatsApp',
    'add_tag' => 'Add Tag',
    'change_stage' => 'Change Stage',
    'create_task' => 'Create Task',
    'assign_to_user' => 'Assign to User',
    'wait_for_days' => 'Wait for Days'
];

$pageTitle = $workflow['name'] . ' - Workflow - ' . brandProductName();
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-xl);">
    <div>
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">
            <?php echo htmlspecialchars($workflow['name']); ?>
        </h1>
        <p style="color: var(--charcoal-grey);">Workflow Details</p>
    </div>
    <div style="display: flex; gap: var(--spacing-sm);">
        <a href="workflow_edit.php?id=<?php echo $workflow['id']; ?>" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Edit Workflow
        </a>
        <a href="workflow_analytics.php?workflow_id=<?php echo $workflow['id']; ?>" style="background: white; color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; text-decoration: none; font-weight: 500;">
            View Analytics
        </a>
        <button onclick="testWorkflow(<?php echo $workflow['id']; ?>)" style="background: #28a745; color: white; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
            Test Workflow
        </button>
        <a href="workflows.php" style="background: white; color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Back to Workflows
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-lg);">
    <!-- Workflow Configuration -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Configuration</h2>
        
        <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <div>
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
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Trigger</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo $triggerLabels[$trigger['type']] ?? ucfirst(str_replace('_', ' ', $trigger['type'])); ?>
                </div>
                <?php if ($trigger['type'] === 'no_activity_for_days' && isset($trigger['days'])): ?>
                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                        After <?php echo $trigger['days']; ?> days without activity
                    </div>
                <?php elseif ($trigger['type'] === 'stage_changed'): ?>
                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                        <?php if (isset($trigger['from_stage']) && $trigger['from_stage']): ?>
                            From: <?php echo ucfirst($trigger['from_stage']); ?>
                        <?php endif; ?>
                        <?php if (isset($trigger['to_stage']) && $trigger['to_stage']): ?>
                            → To: <?php echo ucfirst($trigger['to_stage']); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Graph Summary</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    v<?php echo (int) ($workflow['builder_version'] ?? 1); ?>,
                    <?php echo $nodeCount; ?> nodes,
                    <?php echo $edgeCount; ?> edges
                </div>
                <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                    Mode: <?php echo htmlspecialchars(strtoupper($workflow['workflow_mode'] ?? 'mixed')); ?> |
                    Migration: <?php echo htmlspecialchars($workflow['migration_status'] ?? 'pending'); ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Actions</div>
                <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                    <?php foreach ($actions as $index => $action): ?>
                        <div style="padding: var(--spacing-sm); background: var(--light-grey); border-radius: 4px;">
                            <div style="font-weight: 500; color: var(--midnight-black); margin-bottom: var(--spacing-xs);">
                                <?php echo ($index + 1) . '. ' . ($actionLabels[$action['type']] ?? ucfirst(str_replace('_', ' ', $action['type']))); ?>
                            </div>
                            <?php if ($action['type'] === 'send_email'): ?>
                                <?php if (isset($action['template_id'])): ?>
                                    <div style="color: var(--charcoal-grey); font-size: 12px;">Template ID: <?php echo $action['template_id']; ?></div>
                                <?php endif; ?>
                                <?php if (isset($action['subject'])): ?>
                                    <div style="color: var(--charcoal-grey); font-size: 12px;">Subject: <?php echo htmlspecialchars($action['subject']); ?></div>
                                <?php endif; ?>
                            <?php elseif ($action['type'] === 'change_stage'): ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">New Stage: <?php echo ucfirst($action['stage'] ?? ''); ?></div>
                            <?php elseif ($action['type'] === 'assign_to_user'): ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">User ID: <?php echo $action['user_id'] ?? ''; ?></div>
                            <?php elseif ($action['type'] === 'wait_for_days'): ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">Wait: <?php echo $action['days'] ?? 1; ?> days</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Created</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo date('F j, Y g:i A', strtotime($workflow['created_at'])); ?>
                </div>
            </div>

            <?php if (!empty($graph['nodes'])): ?>
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Graph Preview</div>
                    <div style="padding: var(--spacing-md); background: var(--light-grey); border-radius: 6px; max-height: 260px; overflow-y: auto;">
                        <?php foreach ($graph['nodes'] as $node): ?>
                            <div style="display: flex; justify-content: space-between; gap: 1rem; padding: 0.4rem 0; border-bottom: 1px solid rgba(0,0,0,0.06);">
                                <span style="color: var(--midnight-black); font-weight: 500;">
                                    <?php echo htmlspecialchars($node['subtype'] ?? $node['type'] ?? 'node'); ?>
                                </span>
                                <span style="color: var(--charcoal-grey); font-size: 12px;">
                                    <?php echo htmlspecialchars($node['type'] ?? 'node'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Execution History -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Execution History</h2>
        
        <?php if (empty($executions)): ?>
            <p style="color: var(--charcoal-grey);">No executions yet.</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: var(--spacing-sm); max-height: 600px; overflow-y: auto;">
                <?php foreach ($executions as $execution): ?>
                    <div style="padding: var(--spacing-sm); border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-xs);">
                            <div>
                                <a href="contact_view.php?id=<?php echo $execution['contact_id']; ?>" style="color: var(--accent-blue); text-decoration: none; font-weight: 500;">
                                    <?php echo htmlspecialchars($execution['first_name'] . ' ' . $execution['last_name']); ?>
                                </a>
                            </div>
                            <span style="background: <?php 
                                echo $execution['status'] === 'completed' ? '#3c3' : 
                                    ($execution['status'] === 'failed' ? '#c33' : 
                                    ($execution['status'] === 'running' ? '#f90' : 'var(--light-grey)')); 
                            ?>; color: <?php echo $execution['status'] === 'pending' ? 'var(--charcoal-grey)' : 'white'; ?>; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize;">
                                <?php echo $execution['status']; ?>
                            </span>
                        </div>
                        <div style="color: var(--charcoal-grey); font-size: 12px;">
                            <?php echo date('M j, Y g:i A', strtotime($execution['executed_at'])); ?>
                        </div>
                        <?php if ($execution['error_message']): ?>
                            <div style="color: #c33; font-size: 12px; margin-top: var(--spacing-xs);">
                                Error: <?php echo htmlspecialchars($execution['error_message']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($nodeHotspots)): ?>
            <div style="margin-top: var(--spacing-lg);">
                <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 16px;">Node Hotspots</h3>
                <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                    <?php foreach ($nodeHotspots as $hotspot): ?>
                        <div style="padding: var(--spacing-sm); background: var(--light-grey); border-radius: 4px;">
                            <div style="display: flex; justify-content: space-between; gap: 1rem;">
                                <strong style="color: var(--midnight-black);">
                                    <?php echo htmlspecialchars($hotspot['node_label'] ?: $hotspot['node_type']); ?>
                                </strong>
                                <span style="color: var(--charcoal-grey); font-size: 12px;">
                                    <?php echo (int) $hotspot['run_count']; ?> runs / <?php echo (int) $hotspot['failed_count']; ?> failed
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="workflow-test-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 8px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; padding: 24px;">
        <h3 style="margin: 0 0 16px 0;">Test Workflow</h3>
        <div style="margin-bottom: 16px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Select contact (optional)</label>
            <input type="text" id="test-contact-search" placeholder="Search contacts..." style="width: 100%; padding: 8px 12px; border: 1px solid #dee2e6; border-radius: 4px;">
            <div id="test-contact-results" style="max-height: 150px; overflow-y: auto; margin-top: 8px; border: 1px solid #dee2e6; border-radius: 4px; display: none;"></div>
            <div id="test-contact-selected" style="margin-top: 8px; padding: 8px; background: #f8f9fa; border-radius: 4px; display: none;"></div>
        </div>
        <button onclick="runWorkflowTest(<?php echo $workflow['id']; ?>)" style="background: #28a745; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">Run Test</button>
        <button onclick="closeTestModal()" style="background: #6c757d; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin-left: 8px;">Cancel</button>
        <div id="test-result-container" style="margin-top: 20px; display: none;">
            <h4 style="margin-bottom: 12px;">Test Result</h4>
            <div id="test-result-content"></div>
        </div>
    </div>
</div>

<script>
let selectedTestContact = null;

function testWorkflow(workflowId) {
    document.getElementById('workflow-test-modal').style.display = 'flex';
    document.getElementById('test-result-container').style.display = 'none';
    selectedTestContact = null;
    document.getElementById('test-contact-selected').style.display = 'none';
    document.getElementById('test-contact-search').value = '';
}

function closeTestModal() {
    document.getElementById('workflow-test-modal').style.display = 'none';
}

document.getElementById('test-contact-search').addEventListener('input', debounce(async function() {
    const q = this.value.trim();
    const results = document.getElementById('test-contact-results');
    if (q.length < 2) {
        results.style.display = 'none';
        return;
    }
    try {
        const r = await fetch('../api/search.php?q=' + encodeURIComponent(q) + '&action=suggestions');
        const data = await r.json();
        const contacts = (data.suggestions || []).filter(s => s.type === 'contact');
        results.innerHTML = contacts.slice(0, 10).map(c => {
            const name = (c.title || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const sub = (c.subtitle || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return '<div style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;" data-id="' + c.id + '" data-name="' + name + '">' + name + (sub ? ' - ' + sub : '') + '</div>';
        }).join('') || '<div style="padding: 8px; color: #666;">No contacts found</div>';
        results.querySelectorAll('[data-id]').forEach(el => {
            el.addEventListener('click', () => selectTestContact(parseInt(el.dataset.id), el.dataset.name || ''));
        });
        results.style.display = 'block';
    } catch (e) {
        results.innerHTML = '<div style="padding: 8px; color: #c33;">Search failed</div>';
        results.style.display = 'block';
    }
}, 300));

function selectTestContact(id, name) {
    selectedTestContact = { id, name };
    document.getElementById('test-contact-selected').innerHTML = 'Selected: ' + name + ' <a href="#" onclick="selectedTestContact=null; document.getElementById(\'test-contact-selected\').style.display=\'none\'; return false;">Clear</a>';
    document.getElementById('test-contact-selected').style.display = 'block';
    document.getElementById('test-contact-results').style.display = 'none';
}

function debounce(fn, ms) {
    let t;
    return function() { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); };
}

async function runWorkflowTest(workflowId) {
    const testData = selectedTestContact ? { contact_id: selectedTestContact.id } : { first_name: 'Test', last_name: 'Contact', email: 'test@example.com' };
    try {
        const response = await fetch('../api/workflows/test.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ workflow_id: workflowId, test_data: testData })
        });
        const result = await response.json();
        const container = document.getElementById('test-result-container');
        const content = document.getElementById('test-result-content');
        container.style.display = 'block';
        if (result.success) {
            const tr = result.test_result;
            let html = '<div style="margin-bottom: 12px;"><strong>Conditions Met:</strong> ' + (tr.conditions_met ? 'Yes' : 'No') + '</div>';
            html += '<div style="margin-bottom: 12px;"><strong>Would Execute:</strong> ' + (tr.would_execute ? 'Yes' : 'No') + '</div>';
            if (tr.condition_results && Object.keys(tr.condition_results).length > 0) {
                html += '<div style="margin-bottom: 12px;"><strong>Condition Results:</strong><ul style="margin: 4px 0;">';
                for (const [nodeId, passed] of Object.entries(tr.condition_results)) {
                    html += '<li>Condition: ' + (passed ? 'Passed' : 'Failed') + '</li>';
                }
                html += '</ul></div>';
            }
            html += '<div><strong>Execution Path:</strong><ol style="margin: 8px 0; padding-left: 20px;">';
            (tr.actions || []).forEach((action, i) => {
                html += '<li style="margin-bottom: 4px;">' + (action.description || action.type) + ' <span style="color: ' + (action.would_execute ? '#28a745' : '#6c757d') + ';">(' + (action.would_execute ? 'Would execute' : 'Skipped') + ')</span></li>';
            });
            html += '</ol></div>';
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="color: #c33;">' + (result.error || 'Test failed') + '</div>';
        }
    } catch (error) {
        document.getElementById('test-result-container').style.display = 'block';
        document.getElementById('test-result-content').innerHTML = '<div style="color: #c33;">Error: ' + error.message + '</div>';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
