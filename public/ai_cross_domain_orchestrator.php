<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\AICrossDomainOrchestratorService;
use CRM\Services\AICrossDomainReplayService;
use CRM\Services\AICrossDomainResumeService;
use CRM\Services\AIWorkspaceScopeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('ai.operations.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$tenantKey = (new AIWorkspaceScopeService())->currentTenantKey();
$selectedRunId = (int) ($_GET['run_id'] ?? 0);
$service = new AICrossDomainOrchestratorService();
$replay = new AICrossDomainReplayService();
$resume = new AICrossDomainResumeService();
$message = null;
$error = null;
$run = null;
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'start_objective') {
                $result = $service->startObjective([
                    'objective_key' => (string) ($_POST['objective_key'] ?? ''),
                    'primary_entity_type' => (string) ($_POST['primary_entity_type'] ?? ''),
                    'primary_entity_id' => (int) ($_POST['primary_entity_id'] ?? 0),
                    'related_entity_ids' => [
                        'deal_id' => !empty($_POST['deal_id']) ? (int) $_POST['deal_id'] : null,
                        'invoice_id' => !empty($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : null,
                        'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                        'task_id' => !empty($_POST['task_id']) ? (int) $_POST['task_id'] : null,
                        'communication_id' => !empty($_POST['communication_id']) ? (int) $_POST['communication_id'] : null,
                        'workflow_id' => !empty($_POST['workflow_id']) ? (int) $_POST['workflow_id'] : null,
                    ],
                    'reason_note' => (string) ($_POST['reason_note'] ?? ''),
                    'execute_now' => isset($_POST['execute_now']),
                ], $userId);
                $selectedRunId = (int) ($result['id'] ?? 0);
                $message = !empty($_POST['execute_now']) ? 'Orchestration run started and executed.' : 'Orchestration plan created.';
            } elseif ($action === 'execute_run') {
                $selectedRunId = (int) ($_POST['run_id'] ?? 0);
                $run = $service->executeRun($selectedRunId, $userId);
                $message = 'Orchestration run executed.';
            } elseif ($action === 'resume_run') {
                $selectedRunId = (int) ($_POST['run_id'] ?? 0);
                $run = $resume->resumeRun($selectedRunId, $userId);
                $message = 'Waiting orchestration run resumed.';
            } elseif ($action === 'suppress_run') {
                $selectedRunId = (int) ($_POST['run_id'] ?? 0);
                $run = $service->suppressRun($selectedRunId, $userId, (string) ($_POST['reason_note'] ?? ''));
                $message = 'Orchestration run suppressed.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$runs = $service->listRuns($tenantKey, 15);
if ($selectedRunId > 0) {
    $run = $service->getRun($selectedRunId);
}
if (!$run && !empty($runs)) {
    $run = $runs[0];
}
$summary = $replay->summarizeTenant($tenantKey, 50);

$pageTitle = 'AI Cross-Domain Orchestrator - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI Cross-Domain Orchestrator</h1>
                <p>Start structured revenue-flow objectives and inspect bounded sequential plans across autonomy domains.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_learning_review.php?domain=cross_domain_orchestrator" class="btn-premium-secondary">Learning review</a>
                <a href="ai_recovery_workbench.php?domain=cross_domain_orchestrator" class="btn-premium-secondary">Recovery workbench</a>
                <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Diagnostics</a>
            </div>
        </div>

        <?php if ($message): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Tenant</div><div style="font-size:18px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars($tenantKey); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Runs</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) ($summary['run_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Event-created</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) ($summary['event_origin_run_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Plan completion</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars(number_format((float) ($summary['plan_completion_rate'] ?? 0), 2)); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Step success</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars(number_format((float) ($summary['step_success_rate'] ?? 0), 2)); ?></div></div>
        </div>

        <div style="display:grid;grid-template-columns:minmax(320px,.95fr) minmax(0,1.05fr);gap:1rem;align-items:start;">
            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Start objective</h2>
                    <form method="POST" style="display:grid;gap:.75rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="start_objective">
                        <label>Objective
                            <select name="objective_key" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach ($service->supportedObjectives() as $objectiveKey): ?>
                                    <option value="<?php echo htmlspecialchars($objectiveKey); ?>"><?php echo htmlspecialchars($objectiveKey); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Primary entity type
                            <select name="primary_entity_type" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['deal', 'task', 'communication'] as $entityType): ?>
                                    <option value="<?php echo htmlspecialchars($entityType); ?>"><?php echo htmlspecialchars($entityType); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Primary entity ID
                            <input type="number" min="1" name="primary_entity_id" required style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Related contact ID
                            <input type="number" min="1" name="contact_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Related deal ID
                            <input type="number" min="1" name="deal_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Related invoice ID
                            <input type="number" min="1" name="invoice_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Related task ID
                            <input type="number" min="1" name="task_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Communication ID
                            <input type="number" min="1" name="communication_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Workflow ID
                            <input type="number" min="1" name="workflow_id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Reason note
                            <textarea name="reason_note" rows="3" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></textarea>
                        </label>
                        <label><input type="checkbox" name="execute_now" value="1"> Execute immediately after planning</label>
                        <button type="submit" class="btn-premium-primary">Create objective run</button>
                    </form>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recent runs</h2>
                    <?php if (empty($runs)): ?>
                        <p style="margin:0;color:#64748b;">No cross-domain runs recorded for this tenant yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($runs as $candidateRun): ?>
                                <a href="ai_cross_domain_orchestrator.php?run_id=<?php echo (int) ($candidateRun['id'] ?? 0); ?>" style="display:block;padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;text-decoration:none;color:inherit;">
                                    <strong><?php echo htmlspecialchars((string) ($candidateRun['objective_key'] ?? 'objective')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        run #<?php echo (int) ($candidateRun['id'] ?? 0); ?> •
                                        <?php echo htmlspecialchars((string) ($candidateRun['run_status'] ?? 'planned')); ?> •
                                        <?php echo htmlspecialchars((string) ($candidateRun['execution_mode'] ?? 'plan_only')); ?> •
                                        <?php echo htmlspecialchars((string) ($candidateRun['origin_type'] ?? 'operator')); ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Selected run</h2>
                    <?php if (!$run): ?>
                        <p style="margin:0;color:#64748b;">Select or create a run to inspect plan steps.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.45rem;margin-bottom:1rem;">
                            <div>Run ID: <strong><?php echo (int) ($run['id'] ?? 0); ?></strong></div>
                            <div>Objective: <strong><?php echo htmlspecialchars((string) ($run['objective_key'] ?? 'objective')); ?></strong></div>
                            <div>Status: <strong><?php echo htmlspecialchars((string) ($run['run_status'] ?? 'planned')); ?></strong></div>
                            <div>Execution mode: <strong><?php echo htmlspecialchars((string) ($run['execution_mode'] ?? 'plan_only')); ?></strong></div>
                            <div>Origin: <strong><?php echo htmlspecialchars((string) ($run['origin_type'] ?? 'operator')); ?></strong></div>
                            <div>Trigger: <strong><?php echo htmlspecialchars((string) (($run['trigger_source_domain'] ?? '') !== '' ? (($run['trigger_source_domain'] ?? '') . '/' . ($run['trigger_key'] ?? '')) : 'manual')); ?></strong></div>
                            <?php if (!empty($run['suppression_reason'])): ?><div>Suppression: <strong><?php echo htmlspecialchars((string) $run['suppression_reason']); ?></strong></div><?php endif; ?>
                            <?php if (!empty($run['wait_state']['waiting_reason'])): ?><div>Waiting reason: <strong><?php echo htmlspecialchars((string) ($run['wait_state']['waiting_reason'] ?? '')); ?></strong></div><?php endif; ?>
                        </div>
                        <?php if (($run['run_status'] ?? '') === 'planned'): ?>
                            <form method="POST" style="margin-bottom:1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="action" value="execute_run">
                                <input type="hidden" name="run_id" value="<?php echo (int) ($run['id'] ?? 0); ?>">
                                <button type="submit" class="btn-premium-secondary">Execute run</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array((string) ($run['run_status'] ?? ''), ['waiting', 'ready_to_resume'], true)): ?>
                            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="resume_run">
                                    <input type="hidden" name="run_id" value="<?php echo (int) ($run['id'] ?? 0); ?>">
                                    <button type="submit" class="btn-premium-secondary">Resume run</button>
                                </form>
                                <form method="POST" style="display:flex;gap:.5rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="suppress_run">
                                    <input type="hidden" name="run_id" value="<?php echo (int) ($run['id'] ?? 0); ?>">
                                    <input type="text" name="reason_note" placeholder="Suppression reason" style="padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                    <button type="submit" class="btn-premium-secondary">Suppress run</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach ((array) ($run['steps'] ?? []) as $step): ?>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars((string) ($step['domain_key'] ?? 'domain')); ?> / <?php echo htmlspecialchars((string) ($step['action_key'] ?? 'action')); ?></strong>
                                        <span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars((string) ($step['step_status'] ?? 'planned')); ?></span>
                                    </div>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        target <?php echo htmlspecialchars((string) ($step['target_entity_type'] ?? 'entity')); ?> #<?php echo (int) ($step['target_entity_id'] ?? 0); ?> •
                                        precheck <?php echo htmlspecialchars((string) ($step['precheck_status'] ?? 'pending')); ?> •
                                        confidence <?php echo htmlspecialchars(number_format((float) ($step['assistant_confidence'] ?? 0), 2)); ?>
                                    </div>
                                    <?php $result = (array) ($step['result'] ?? []); ?>
                                    <?php if (!empty($result['precheck']['reasons'])): ?>
                                        <div style="font-size:.82rem;color:#64748b;margin-top:.35rem;">
                                            reasons: <?php echo htmlspecialchars(implode(', ', (array) $result['precheck']['reasons'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
