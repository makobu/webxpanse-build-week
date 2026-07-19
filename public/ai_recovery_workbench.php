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
use CRM\Services\AIAutonomyRecoveryWorkbenchService;
use CRM\Services\AIAutonomyRolloutOperationsService;
use CRM\Services\AICrossDomainOrchestratorService;
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

$workspaceScope = new AIWorkspaceScopeService();
$tenantKey = $workspaceScope->currentTenantKey();
$domainKey = trim((string) ($_GET['domain'] ?? 'commercial_mvp'));
$workbench = new AIAutonomyRecoveryWorkbenchService();
$rollout = new AIAutonomyRolloutOperationsService();
$orchestrator = new AICrossDomainOrchestratorService();
$message = null;
$error = null;
$userId = (int) ($user['id'] ?? 0);
$users = Database::query("SELECT id, first_name, last_name, email FROM users ORDER BY first_name ASC, last_name ASC, id ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if (in_array($action, [
                'assign_recovery_item',
                'start_recovery_item',
                'resolve_recovery_item',
                'suppress_recovery_item',
                'mark_incident_resolved',
                'suppress_incident',
                'retry_autonomous_action',
                'rerun_governed_action',
                'cancel_orchestration_run',
                'retry_orchestration_run',
                'review_only_orchestration_run',
                'resume_orchestration_run',
                'suppress_orchestration_run',
            ], true)) {
                $result = $workbench->handleRecoveryAction($action, $_POST, $userId);
                $message = 'Recovery workbench action completed.';
                $tenantKey = (string) ($result['tenant_key'] ?? $tenantKey);
                $domainKey = (string) ($result['domain_key'] ?? $domainKey);
            } else {
                $domainKey = trim((string) ($_POST['domain_key'] ?? $domainKey));
                $result = $rollout->applyOperatorAction($tenantKey, $domainKey, $action, [
                    'reason' => (string) ($_POST['reason'] ?? ''),
                    'temporary_daily_auto_action_cap' => $_POST['temporary_daily_auto_action_cap'] ?? null,
                    'clear_manual_freeze' => isset($_POST['clear_manual_freeze']),
                ], $userId);
                $message = 'Rollout control updated.';
                $tenantKey = (string) ($result['tenant_key'] ?? $tenantKey);
                $domainKey = (string) ($result['domain_key'] ?? $domainKey);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$dashboard = $workbench->getDashboardData($tenantKey);
$selected = $dashboard['domain_summaries'][$domainKey] ?? [
    'readiness' => $rollout->computeReadiness($tenantKey, $domainKey),
    'incidents' => [],
    'recovery_queue' => [],
    'operator_actions' => [],
];

$csrfToken = Security::getCsrfToken();
$pageTitle = 'AI Recovery Workbench - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI Recovery Workbench</h1>
                <p>Operate incidents, recovery queue, and tenant rollout controls from one autonomous operations surface.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_learning_review.php?domain=<?php echo urlencode($domainKey); ?>" class="btn-premium-secondary">Learning review</a>
                <a href="ai_cross_domain_orchestrator.php" class="btn-premium-secondary">Cross-domain orchestration</a>
                <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Diagnostics</a>
                <a href="ai_control_center.php" class="btn-premium-secondary">Control center</a>
            </div>
        </div>

        <?php if ($message): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Open incidents</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) ($dashboard['open_incident_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Recovery backlog</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) ($dashboard['recovery_backlog_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Tenant</div><div style="font-size:18px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars($tenantKey); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Selected domain</div><div style="font-size:18px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars($domainKey); ?></div></div>
        </div>

        <div style="display:grid;grid-template-columns:minmax(320px,.95fr) minmax(0,1.05fr);gap:1rem;align-items:start;">
            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Domain rollout state</h2>
                    <form method="GET" style="display:grid;gap:.75rem;margin-bottom:1rem;">
                        <div style="font-size:.9rem;color:#475569;">Workspace scope: <strong><?php echo htmlspecialchars($tenantKey); ?></strong></div>
                        <label>Domain key
                            <select name="domain" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['commercial_mvp', 'customer_care', 'deal_followthrough', 'task_followthrough', 'customer_thread', 'workflow_execution', 'cross_domain_orchestrator'] as $candidate): ?>
                                    <option value="<?php echo htmlspecialchars($candidate); ?>" <?php echo $domainKey === $candidate ? 'selected' : ''; ?>><?php echo htmlspecialchars($candidate); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="btn-premium-secondary">Load domain</button>
                    </form>
                    <div style="display:grid;gap:.45rem;">
                        <div>Rollout state: <strong><?php echo htmlspecialchars((string) ($selected['readiness']['rollout_state'] ?? 'not_ready')); ?></strong></div>
                        <div>Current mode: <strong><?php echo htmlspecialchars((string) ($selected['readiness']['control']['autonomy_mode'] ?? 'suggest_only')); ?></strong></div>
                        <div>Promotion status: <strong><?php echo htmlspecialchars((string) ($selected['readiness']['control']['promotion_status'] ?? 'suggest_only')); ?></strong></div>
                        <div>Recommended mode: <strong><?php echo htmlspecialchars((string) ($selected['readiness']['promotion']['recommended_mode'] ?? 'suggest_only')); ?></strong></div>
                        <div>Reasons: <strong><?php echo htmlspecialchars(implode(', ', (array) ($selected['readiness']['reasons'] ?? [])) ?: 'none'); ?></strong></div>
                    </div>
                    <div style="display:grid;gap:.6rem;margin-top:1rem;">
                        <?php foreach ([
                            'pause_domain' => 'Pause domain',
                            'pause_customer_facing_only' => 'Pause customer-facing only',
                            'downgrade_domain' => 'Force downgrade',
                            'freeze_domain' => 'Manual freeze',
                            'approve_promotion' => 'Approve promotion',
                            'resume_domain' => 'Resume domain',
                        ] as $action => $label): ?>
                            <form method="POST" style="display:grid;grid-template-columns:1fr auto;gap:.5rem;align-items:end;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="domain_key" value="<?php echo htmlspecialchars($domainKey); ?>">
                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                                <input type="text" name="reason" placeholder="Reason" style="padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <button type="submit" class="btn-premium-primary"><?php echo htmlspecialchars($label); ?></button>
                            </form>
                        <?php endforeach; ?>
                        <form method="POST" style="display:grid;grid-template-columns:1fr 140px auto;gap:.5rem;align-items:end;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="domain_key" value="<?php echo htmlspecialchars($domainKey); ?>">
                            <input type="hidden" name="action" value="set_daily_cap_override">
                            <input type="text" name="reason" placeholder="Reason" style="padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                            <input type="number" min="0" name="temporary_daily_auto_action_cap" placeholder="Daily cap" style="padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                            <button type="submit" class="btn-premium-secondary">Set cap override</button>
                        </form>
                    </div>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recent operator actions</h2>
                    <?php if (empty($selected['operator_actions'])): ?>
                        <p style="margin:0;color:#64748b;">No operator actions logged for this domain yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($selected['operator_actions'] as $action): ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <strong><?php echo htmlspecialchars((string) ($action['action_key'] ?? 'operator_action')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($action['reason'] ?? '')); ?> <?php if (!empty($action['created_at'])): ?>• <?php echo htmlspecialchars((string) $action['created_at']); ?><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Domain summaries</h2>
                    <div style="display:grid;gap:.55rem;">
                        <?php foreach ($dashboard['domain_summaries'] as $summaryKey => $summary): ?>
                            <a href="ai_recovery_workbench.php?domain=<?php echo urlencode($summaryKey); ?>" style="display:block;padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;text-decoration:none;color:inherit;">
                                <strong><?php echo htmlspecialchars($summaryKey); ?></strong>
                                <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                    <?php echo htmlspecialchars((string) ($summary['readiness']['rollout_state'] ?? 'not_ready')); ?> •
                                    incidents <?php echo (int) ($summary['readiness']['operational']['open_incident_count'] ?? 0); ?> •
                                    backlog <?php echo (int) ($summary['readiness']['operational']['recovery_backlog_count'] ?? 0); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Cross-domain orchestration</h2>
                    <?php if (empty($dashboard['orchestration_runs'])): ?>
                        <p style="margin:0;color:#64748b;">No orchestration runs recorded for this tenant.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach ($dashboard['orchestration_runs'] as $run): ?>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars((string) ($run['objective_key'] ?? 'objective')); ?></strong>
                                        <span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars((string) ($run['run_status'] ?? 'planned')); ?></span>
                                    </div>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        run #<?php echo (int) ($run['id'] ?? 0); ?> • <?php echo htmlspecialchars((string) ($run['execution_mode'] ?? 'plan_only')); ?> • steps <?php echo count((array) ($run['steps'] ?? [])); ?>
                                    </div>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem;margin-top:.75rem;">
                                        <?php foreach ([
                                            'resume_orchestration_run' => 'Resume run',
                                            'retry_orchestration_run' => 'Retry run',
                                            'review_only_orchestration_run' => 'Review only',
                                            'suppress_orchestration_run' => 'Suppress run',
                                            'cancel_orchestration_run' => 'Cancel run',
                                        ] as $action => $label): ?>
                                            <form method="POST" style="display:flex;gap:.5rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                                                <input type="hidden" name="orchestration_run_id" value="<?php echo (int) ($run['id'] ?? 0); ?>">
                                                <input type="text" name="reason" placeholder="Reason" style="flex:1;padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                                <button type="submit" class="btn-premium-secondary"><?php echo htmlspecialchars($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Incidents</h2>
                    <?php if (empty($selected['incidents'])): ?>
                        <p style="margin:0;color:#64748b;">No incidents for this domain.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach ($selected['incidents'] as $incident): ?>
                                <div style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars((string) ($incident['incident_key'] ?? 'incident')); ?></strong>
                                        <span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars((string) ($incident['severity'] ?? 'medium')); ?> • <?php echo htmlspecialchars((string) ($incident['status'] ?? 'open')); ?></span>
                                    </div>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;"><?php echo htmlspecialchars(implode(', ', (array) ($incident['reason_codes'] ?? []))); ?></div>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.5rem;margin-top:.75rem;">
                                        <?php foreach (['mark_incident_resolved' => 'Resolve', 'suppress_incident' => 'Suppress'] as $action => $label): ?>
                                            <form method="POST" style="display:flex;gap:.5rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                                                <input type="hidden" name="incident_id" value="<?php echo (int) ($incident['id'] ?? 0); ?>">
                                                <input type="hidden" name="domain_key" value="<?php echo htmlspecialchars($domainKey); ?>">
                                                <input type="text" name="reason" placeholder="Reason" style="flex:1;padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                                <button type="submit" class="btn-premium-secondary"><?php echo htmlspecialchars($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recovery queue</h2>
                    <?php if (empty($selected['recovery_queue'])): ?>
                        <p style="margin:0;color:#64748b;">No recovery work queued.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.85rem;">
                            <?php foreach ($selected['recovery_queue'] as $item): ?>
                                <div style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars((string) ($item['suggested_manual_action'] ?? 'Review')); ?></strong>
                                        <span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars((string) ($item['status'] ?? 'pending')); ?></span>
                                    </div>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($item['action_key'] ?? '')); ?><?php if (!empty($item['last_error'])): ?> • last error: <?php echo htmlspecialchars((string) $item['last_error']); ?><?php endif; ?>
                                    </div>
                                    <?php $payload = (array) ($item['payload'] ?? []); ?>
                                    <?php if (!empty($payload['orchestration_run_id'])): ?>
                                        <div style="font-size:.82rem;color:#64748b;margin-top:.35rem;">
                                            Linked orchestration run #<?php echo (int) $payload['orchestration_run_id']; ?>
                                            <?php if (!empty($payload['orchestration_step_id'])): ?> • step #<?php echo (int) $payload['orchestration_step_id']; ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form method="POST" style="display:grid;grid-template-columns:160px 1fr auto;gap:.5rem;align-items:end;margin-top:.75rem;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="assign_recovery_item">
                                        <input type="hidden" name="recovery_queue_id" value="<?php echo (int) ($item['id'] ?? 0); ?>">
                                        <select name="assigned_to" style="padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                            <option value="">Assign to</option>
                                            <?php foreach ($users as $assignUser): ?>
                                                <option value="<?php echo (int) ($assignUser['id'] ?? 0); ?>"><?php echo htmlspecialchars(trim((string) (($assignUser['first_name'] ?? '') . ' ' . ($assignUser['last_name'] ?? ''))) ?: (string) ($assignUser['email'] ?? ('User #' . ($assignUser['id'] ?? '')))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="reason" placeholder="Reason" style="padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                        <button type="submit" class="btn-premium-secondary">Assign</button>
                                    </form>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem;margin-top:.6rem;">
                                        <?php foreach ([
                                            'start_recovery_item' => 'Start',
                                            'resolve_recovery_item' => 'Resolve',
                                            'suppress_recovery_item' => 'Suppress',
                                            'retry_autonomous_action' => 'Retry',
                                            'rerun_governed_action' => 'Rerun',
                                        ] as $action => $label): ?>
                                            <form method="POST" style="display:flex;gap:.5rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                                                <input type="hidden" name="recovery_queue_id" value="<?php echo (int) ($item['id'] ?? 0); ?>">
                                                <input type="text" name="reason" placeholder="Reason" style="flex:1;padding:.55rem;border:1px solid var(--border-color);border-radius:8px;">
                                                <button type="submit" class="btn-premium-primary"><?php echo htmlspecialchars($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
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
