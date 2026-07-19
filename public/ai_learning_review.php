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
use CRM\Services\AILearningReviewService;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIAutonomyScenarioReplayService;
use CRM\Services\AIWorkspaceScopeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user) || !Authorization::can('ai.learning_review', $user)) {
    header('Location: dashboard.php');
    exit;
}

$workspaceScope = new AIWorkspaceScopeService();
$tenantKey = $workspaceScope->currentTenantKey();
$domainKey = trim((string) ($_GET['domain'] ?? 'commercial_mvp'));
$review = new AILearningReviewService();
$controls = new AIAutonomyDomainControlService();
$replay = new AIAutonomyScenarioReplayService();
$message = null;
$error = null;
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save_control') {
                $domainKey = trim((string) ($_POST['domain_key'] ?? $domainKey));
                $controls->save($tenantKey, $domainKey, [
                    'autonomy_mode' => (string) ($_POST['autonomy_mode'] ?? 'suggest_only'),
                    'promotion_status' => (string) ($_POST['promotion_status'] ?? 'suggest_only'),
                    'demonstration_capture_enabled' => isset($_POST['demonstration_capture_enabled']),
                    'policy_learning_enabled' => isset($_POST['policy_learning_enabled']),
                    'review_ui_enabled' => isset($_POST['review_ui_enabled']),
                    'fast_promotion_enabled' => isset($_POST['fast_promotion_enabled']),
                    'auto_downgrade_on_drift' => isset($_POST['auto_downgrade_on_drift']),
                    'min_precision_to_promote' => (float) ($_POST['min_precision_to_promote'] ?? 0.9),
                    'max_reversal_rate_to_promote' => (float) ($_POST['max_reversal_rate_to_promote'] ?? 0.08),
                    'max_edit_rate_to_promote' => (float) ($_POST['max_edit_rate_to_promote'] ?? 0.12),
                    'metadata' => [
                        'allowed_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['allowed_actions'] ?? ''))))),
                        'require_human_checkpoint_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['require_human_checkpoint_actions'] ?? ''))))),
                        'max_daily_auto_actions' => (int) ($_POST['max_daily_auto_actions'] ?? 50),
                        'max_customer_facing_risk' => (float) ($_POST['max_customer_facing_risk'] ?? 0.95),
                        'block_customer_facing_full_auto' => isset($_POST['block_customer_facing_full_auto']),
                        'min_sample_size_to_promote' => (int) ($_POST['min_sample_size_to_promote'] ?? 10),
                        'max_duplicate_rate_to_promote' => (float) ($_POST['max_duplicate_rate_to_promote'] ?? 0.05),
                        'max_override_rate_to_promote' => (float) ($_POST['max_override_rate_to_promote'] ?? 0.12),
                        'min_eval_runs_to_promote' => (int) ($_POST['min_eval_runs_to_promote'] ?? 1),
                    ],
                ], $userId);
                $message = 'Autonomy controls updated.';
            } elseif ($action === 'run_replay') {
                $domainKey = trim((string) ($_POST['domain_key'] ?? $domainKey));
                $runId = $replay->runForDomain($tenantKey, $domainKey, (string) ($_POST['autonomy_mode'] ?? 'suggest_only'), $userId);
                $message = 'Scenario replay completed as evaluation run #' . $runId . '.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$data = $review->getDashboardData($tenantKey, $domainKey);
$allControls = $controls->listAll();
$metadata = (array) ($data['controls']['metadata'] ?? []);
$pageTitle = 'AI Learning Review - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI Learning Review</h1>
                <p>Inspect demonstrations, tenant memory, autonomy controls, and evaluation readiness across learning domains.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Diagnostics</a>
                <a href="ai_recovery_workbench.php?domain=<?php echo urlencode($domainKey); ?>" class="btn-premium-secondary">Recovery workbench</a>
                <a href="ai_cross_domain_orchestrator.php" class="btn-premium-secondary">Cross-domain orchestration</a>
                <a href="ai_control_center.php" class="btn-premium-secondary">Control center</a>
            </div>
        </div>
        <?php if ($message): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(320px,.9fr) minmax(0,1.1fr);gap:1rem;align-items:start;">
            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Domain controls</h2>
                    <form method="POST" style="display:grid;gap:.75rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="save_control">
                        <div style="font-size:.9rem;color:#475569;">Workspace scope: <strong><?php echo htmlspecialchars($tenantKey); ?></strong></div>
                        <label>Domain key
                            <select name="domain_key" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['commercial_mvp', 'customer_care', 'customer_thread', 'deal_followthrough', 'task_followthrough', 'workflow_execution', 'cross_domain_orchestrator'] as $candidate): ?>
                                    <option value="<?php echo htmlspecialchars($candidate); ?>" <?php echo $domainKey === $candidate ? 'selected' : ''; ?>><?php echo htmlspecialchars($candidate); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Autonomy mode
                            <select name="autonomy_mode" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only', 'auto_safe', 'full_auto'] as $candidate): ?>
                                    <option value="<?php echo htmlspecialchars($candidate); ?>" <?php echo (($data['controls']['autonomy_mode'] ?? '') === $candidate) ? 'selected' : ''; ?>><?php echo htmlspecialchars($candidate); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Promotion status
                            <select name="promotion_status" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only', 'auto_safe', 'full_auto', 'blocked'] as $candidate): ?>
                                    <option value="<?php echo htmlspecialchars($candidate); ?>" <?php echo (($data['controls']['promotion_status'] ?? '') === $candidate) ? 'selected' : ''; ?>><?php echo htmlspecialchars($candidate); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><input type="checkbox" name="demonstration_capture_enabled" value="1" <?php echo !empty($data['controls']['demonstration_capture_enabled']) ? 'checked' : ''; ?>> Demonstration capture enabled</label>
                        <label><input type="checkbox" name="policy_learning_enabled" value="1" <?php echo !empty($data['controls']['policy_learning_enabled']) ? 'checked' : ''; ?>> Policy learning enabled</label>
                        <label><input type="checkbox" name="review_ui_enabled" value="1" <?php echo !empty($data['controls']['review_ui_enabled']) ? 'checked' : ''; ?>> Review UI enabled</label>
                        <label><input type="checkbox" name="fast_promotion_enabled" value="1" <?php echo !empty($data['controls']['fast_promotion_enabled']) ? 'checked' : ''; ?>> Fast promotion enabled</label>
                        <label><input type="checkbox" name="auto_downgrade_on_drift" value="1" <?php echo !empty($data['controls']['auto_downgrade_on_drift']) ? 'checked' : ''; ?>> Auto downgrade on drift</label>
                        <label>Min precision
                            <input type="number" step="0.01" min="0" max="1" name="min_precision_to_promote" value="<?php echo htmlspecialchars((string) ($data['controls']['min_precision_to_promote'] ?? 0.9)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max reversal rate
                            <input type="number" step="0.01" min="0" max="1" name="max_reversal_rate_to_promote" value="<?php echo htmlspecialchars((string) ($data['controls']['max_reversal_rate_to_promote'] ?? 0.08)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max edit rate
                            <input type="number" step="0.01" min="0" max="1" name="max_edit_rate_to_promote" value="<?php echo htmlspecialchars((string) ($data['controls']['max_edit_rate_to_promote'] ?? 0.12)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Allowed actions
                            <input type="text" name="allowed_actions" value="<?php echo htmlspecialchars(implode(', ', (array) ($metadata['allowed_actions'] ?? []))); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Checkpoint actions
                            <input type="text" name="require_human_checkpoint_actions" value="<?php echo htmlspecialchars(implode(', ', (array) ($metadata['require_human_checkpoint_actions'] ?? []))); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max daily auto actions
                            <input type="number" step="1" min="0" name="max_daily_auto_actions" value="<?php echo htmlspecialchars((string) ($metadata['max_daily_auto_actions'] ?? 50)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max customer-facing risk
                            <input type="number" step="0.01" min="0" max="1" name="max_customer_facing_risk" value="<?php echo htmlspecialchars((string) ($metadata['max_customer_facing_risk'] ?? 0.95)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label><input type="checkbox" name="block_customer_facing_full_auto" value="1" <?php echo !empty($metadata['block_customer_facing_full_auto']) ? 'checked' : ''; ?>> Block customer-facing actions in full-auto</label>
                        <label>Min sample size
                            <input type="number" step="1" min="0" name="min_sample_size_to_promote" value="<?php echo htmlspecialchars((string) ($metadata['min_sample_size_to_promote'] ?? 10)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max duplicate rate
                            <input type="number" step="0.01" min="0" max="1" name="max_duplicate_rate_to_promote" value="<?php echo htmlspecialchars((string) ($metadata['max_duplicate_rate_to_promote'] ?? 0.05)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Max override rate
                            <input type="number" step="0.01" min="0" max="1" name="max_override_rate_to_promote" value="<?php echo htmlspecialchars((string) ($metadata['max_override_rate_to_promote'] ?? 0.12)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <label>Min eval runs
                            <input type="number" step="1" min="1" name="min_eval_runs_to_promote" value="<?php echo htmlspecialchars((string) ($metadata['min_eval_runs_to_promote'] ?? 1)); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        </label>
                        <button type="submit" class="btn-premium-primary">Save controls</button>
                    </form>
                    <form method="POST" style="margin-top:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="run_replay">
                        <input type="hidden" name="domain_key" value="<?php echo htmlspecialchars($domainKey); ?>">
                        <input type="hidden" name="autonomy_mode" value="<?php echo htmlspecialchars((string) ($data['controls']['autonomy_mode'] ?? 'suggest_only')); ?>">
                        <button type="submit" class="btn-premium-secondary">Run scenario replay</button>
                    </form>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Tenant memory</h2>
                    <div style="display:grid;gap:.5rem;">
                        <div>Preferred channel: <strong><?php echo htmlspecialchars((string) ($data['tenant_policy']['preferred_channel'] ?? 'none')); ?></strong></div>
                        <div>Preferred document type: <strong><?php echo htmlspecialchars((string) ($data['tenant_policy']['preferred_document_type'] ?? 'none')); ?></strong></div>
                        <div>Reversal risk: <strong><?php echo htmlspecialchars(number_format((float) ($data['tenant_policy']['reversal_risk'] ?? 0), 2)); ?></strong></div>
                    </div>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Governance status</h2>
                    <div style="display:grid;gap:.45rem;">
                        <div>Gate decision: <strong><?php echo htmlspecialchars((string) ($data['promotion_gate_status']['decision'] ?? 'hold')); ?></strong></div>
                        <div>Current mode: <strong><?php echo htmlspecialchars((string) ($data['promotion_gate_status']['current_mode'] ?? 'suggest_only')); ?></strong></div>
                        <div>Recommended mode: <strong><?php echo htmlspecialchars((string) ($data['promotion_gate_status']['recommended_mode'] ?? 'suggest_only')); ?></strong></div>
                        <div>Promotion status: <strong><?php echo htmlspecialchars((string) ($data['promotion_gate_status']['promotion_status'] ?? 'suggest_only')); ?></strong></div>
                        <div>Drift unstable: <strong><?php echo !empty($data['drift_status']['unstable']) ? 'yes' : 'no'; ?></strong></div>
                        <div>Rollout state: <strong><?php echo htmlspecialchars((string) ($data['rollout_readiness']['rollout_state'] ?? 'not_ready')); ?></strong></div>
                    </div>
                </div>
            </div>

            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Domain summaries</h2>
                    <div style="display:grid;gap:.55rem;">
                        <?php foreach ((array) ($data['domain_summaries'] ?? []) as $summaryDomain => $summary): ?>
                            <div style="padding:.75rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <strong><?php echo htmlspecialchars((string) $summaryDomain); ?></strong>
                                <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                    mode <?php echo htmlspecialchars((string) ($summary['controls']['autonomy_mode'] ?? 'suggest_only')); ?> •
                                    demos <?php echo htmlspecialchars((string) ($summary['recent_demonstration_count'] ?? 0)); ?> •
                                    incidents <?php echo htmlspecialchars((string) ($summary['incident_count'] ?? 0)); ?> •
                                    drift <?php echo !empty($summary['drift_status']['unstable']) ? 'unstable' : 'stable'; ?>
                                    <?php if (isset($summary['recent_run_count'])): ?> • runs <?php echo htmlspecialchars((string) $summary['recent_run_count']); ?><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Cross-domain orchestration</h2>
                    <div style="display:grid;gap:.45rem;margin-bottom:.85rem;">
                        <div>Runs: <strong><?php echo htmlspecialchars((string) (($data['orchestration_summary']['run_count'] ?? 0))); ?></strong></div>
                        <div>Event-created: <strong><?php echo htmlspecialchars((string) (($data['orchestration_summary']['event_origin_run_count'] ?? 0))); ?></strong></div>
                        <div>Waiting: <strong><?php echo htmlspecialchars((string) (($data['orchestration_summary']['waiting_run_count'] ?? 0))); ?></strong></div>
                        <div>Ready to resume: <strong><?php echo htmlspecialchars((string) (($data['orchestration_summary']['ready_to_resume_count'] ?? 0))); ?></strong></div>
                        <div>Plan completion: <strong><?php echo htmlspecialchars(number_format((float) ($data['orchestration_summary']['plan_completion_rate'] ?? 0), 2)); ?></strong></div>
                        <div>Step success: <strong><?php echo htmlspecialchars(number_format((float) ($data['orchestration_summary']['step_success_rate'] ?? 0), 2)); ?></strong></div>
                        <div>Blocker rate: <strong><?php echo htmlspecialchars(number_format((float) ($data['orchestration_summary']['blocker_rate'] ?? 0), 2)); ?></strong></div>
                        <div>Suppression count: <strong><?php echo htmlspecialchars((string) (($data['orchestration_summary']['suppression_count'] ?? 0))); ?></strong></div>
                        <div>Duplicate trigger rate: <strong><?php echo htmlspecialchars(number_format((float) ($data['orchestration_summary']['duplicate_trigger_rate'] ?? 0), 2)); ?></strong></div>
                        <div>Resume success rate: <strong><?php echo htmlspecialchars(number_format((float) ($data['orchestration_summary']['resume_success_rate'] ?? 0), 2)); ?></strong></div>
                    </div>
                    <?php if (empty($data['orchestration_runs'])): ?>
                        <p style="margin:0;color:#64748b;">No orchestration runs recorded yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['orchestration_runs'] as $run): ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <strong><?php echo htmlspecialchars((string) ($run['objective_key'] ?? 'objective')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        status <?php echo htmlspecialchars((string) ($run['run_status'] ?? 'planned')); ?> •
                                        mode <?php echo htmlspecialchars((string) ($run['execution_mode'] ?? 'plan_only')); ?> •
                                        steps <?php echo count((array) ($run['steps'] ?? [])); ?> •
                                        origin <?php echo htmlspecialchars((string) ($run['origin_type'] ?? 'operator')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recent demonstrations</h2>
                    <?php if (empty($data['recent_demonstrations'])): ?>
                        <p style="margin:0;color:#64748b;">No demonstrations recorded for this tenant/domain yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['recent_demonstrations'] as $demo): ?>
                                <?php $meta = json_decode((string) ($demo['metadata_json'] ?? '{}'), true) ?: []; ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars((string) ($demo['action_key'] ?? 'action')); ?></strong>
                                        <span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars((string) ($demo['observed_at'] ?? '')); ?></span>
                                    </div>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($demo['source_surface'] ?? '')); ?> •
                                        <?php echo htmlspecialchars((string) ($meta['document_type'] ?? '')); ?> •
                                        <?php echo htmlspecialchars((string) ($meta['channel'] ?? '')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Evaluation runs</h2>
                    <?php if (empty($data['evaluations'])): ?>
                        <p style="margin:0;color:#64748b;">No evaluation runs recorded yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['evaluations'] as $evaluation): ?>
                                <?php $metrics = json_decode((string) ($evaluation['metrics_json'] ?? '{}'), true) ?: []; ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                                    <strong><?php echo htmlspecialchars((string) ($evaluation['autonomy_mode'] ?? 'suggest_only')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        precision <?php echo htmlspecialchars(number_format((float) ($metrics['precision_at_threshold'] ?? 0), 2)); ?> •
                                        reversal <?php echo htmlspecialchars(number_format((float) ($metrics['reversal_rate'] ?? 0), 2)); ?> •
                                        completion <?php echo htmlspecialchars(number_format((float) ($metrics['business_completion_rate'] ?? 0), 2)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Drift warnings</h2>
                    <div style="display:grid;gap:.45rem;">
                        <div>Calibration error: <strong><?php echo htmlspecialchars(number_format((float) ($data['drift_status']['confidence_calibration_error'] ?? 0), 2)); ?></strong></div>
                        <div>Duplicate rate: <strong><?php echo htmlspecialchars(number_format((float) ($data['drift_status']['duplicate_rate'] ?? 0), 2)); ?></strong></div>
                        <div>Completion delta: <strong><?php echo htmlspecialchars(number_format((float) ($data['drift_status']['completion_rate_delta'] ?? 0), 2)); ?></strong></div>
                        <div>Reasons: <strong><?php echo htmlspecialchars(implode(', ', (array) ($data['drift_status']['unstable_reasons'] ?? [])) ?: 'none'); ?></strong></div>
                    </div>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recent incidents</h2>
                    <?php if (empty($data['incidents'])): ?>
                        <p style="margin:0;color:#64748b;">No autonomy incidents recorded for this tenant/domain.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['incidents'] as $incident): ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <strong><?php echo htmlspecialchars((string) ($incident['incident_key'] ?? 'incident')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($incident['severity'] ?? 'medium')); ?> •
                                        <?php echo htmlspecialchars((string) ($incident['status'] ?? 'open')); ?> •
                                        <?php echo htmlspecialchars(implode(', ', (array) ($incident['reason_codes'] ?? []))); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Recovery queue</h2>
                    <?php if (empty($data['recovery_queue'])): ?>
                        <p style="margin:0;color:#64748b;">No recovery work queued.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['recovery_queue'] as $item): ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <strong><?php echo htmlspecialchars((string) ($item['suggested_manual_action'] ?? 'Review')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($item['status'] ?? 'pending')); ?> •
                                        <?php echo htmlspecialchars((string) ($item['action_key'] ?? '')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">Operator actions</h2>
                    <?php if (empty($data['operator_actions'])): ?>
                        <p style="margin:0;color:#64748b;">No operator overrides logged yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($data['operator_actions'] as $action): ?>
                                <div style="padding:.8rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <strong><?php echo htmlspecialchars((string) ($action['action_key'] ?? 'operator_action')); ?></strong>
                                    <div style="font-size:.85rem;color:#475569;margin-top:.35rem;">
                                        <?php echo htmlspecialchars((string) ($action['reason'] ?? '')); ?> • <?php echo htmlspecialchars((string) ($action['created_at'] ?? '')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <h2 style="margin-top:0;">All domain controls</h2>
                    <div style="display:grid;gap:.55rem;">
                        <?php foreach ($allControls as $control): ?>
                            <div style="padding:.7rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <strong><?php echo htmlspecialchars((string) ($control['tenant_key'] ?? '')); ?></strong>
                                <div style="font-size:.8rem;color:#64748b;">
                                    <?php echo htmlspecialchars((string) ($control['domain_key'] ?? '')); ?> •
                                    <?php echo htmlspecialchars((string) ($control['autonomy_mode'] ?? 'suggest_only')); ?> •
                                    <?php echo htmlspecialchars((string) ($control['promotion_status'] ?? 'suggest_only')); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
