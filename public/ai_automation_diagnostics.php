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
require_once __DIR__ . '/../includes/ai_ui_helper.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\AIAutomationDiagnosticsService;
use CRM\Services\AIAutomationIncidentService;
use CRM\Services\AIConfidenceCalibrationService;
use CRM\Services\AICoachRecommendationControlService;
use CRM\Services\AutomationJobHealthService;
use CRM\Services\AIPromptQualityService;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AIThresholdUpdateService;
use CRM\Services\PluginRuntimeEventService;
use CRM\Session;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user) || !Authorization::can('ai.operations.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$service = new AIAutomationDiagnosticsService();
$coachControlService = new AICoachRecommendationControlService();
$coachControlsReady = $coachControlService->tableReady();
$incidentService = new AIAutomationIncidentService();
$calibrationService = new AIConfidenceCalibrationService();
$thresholdService = new AIThresholdUpdateService();
$promptQualityService = new AIPromptQualityService();
$runtimeControlService = new AIRuntimeControlService();
$jobHealthService = new AutomationJobHealthService();
$pluginRuntimeEventService = new PluginRuntimeEventService();
$rollbackMessage = null;
$rollbackError = null;
$csrfToken = Security::getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $rollbackError = 'Invalid security token.';
    } elseif (($_POST['action'] ?? '') === 'rollback_threshold') {
        $rollback = $thresholdService->rollback((int) ($_POST['tuning_log_id'] ?? 0));
        if ($rollback) {
            $rollbackMessage = 'Threshold rollback applied.';
        } else {
            $rollbackError = 'Unable to rollback that threshold change.';
        }
    } elseif (($_POST['action'] ?? '') === 'set_ai_coach_control') {
        try {
            $coachControlService->setControl(
                (string) ($_POST['control_scope'] ?? ''),
                (string) ($_POST['control_value'] ?? ''),
                (string) ($_POST['control_type'] ?? ''),
                (int) ($user['id'] ?? 0),
                (string) ($_POST['reason'] ?? ''),
                [
                    'source' => 'ai_automation_diagnostics',
                    'label' => (string) ($_POST['label'] ?? ''),
                    'latest_event_id' => (string) ($_POST['latest_event_id'] ?? ''),
                ]
            );
            $rollbackMessage = 'AI Coach tuning control saved.';
        } catch (\Throwable $e) {
            $rollbackError = $e->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'disable_ai_coach_control') {
        try {
            $disabled = $coachControlService->disableControl(
                (int) ($_POST['control_id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) ($_POST['reason'] ?? 'Disabled from diagnostics')
            );
            $rollbackMessage = $disabled ? 'AI Coach tuning control disabled.' : 'AI Coach tuning control was not found.';
        } catch (\Throwable $e) {
            $rollbackError = $e->getMessage();
        }
    }
}
$filters = [
    'source' => trim((string) ($_GET['source'] ?? '')),
    'decision' => trim((string) ($_GET['decision'] ?? '')),
    'reason_bucket' => trim((string) ($_GET['reason_bucket'] ?? '')),
    'mode' => trim((string) ($_GET['mode'] ?? '')),
    'user_id' => (int) ($_GET['user_id'] ?? 0),
    'deal_id' => (int) ($_GET['deal_id'] ?? 0),
    'invoice_id' => (int) ($_GET['invoice_id'] ?? 0),
    'contact_id' => (int) ($_GET['contact_id'] ?? 0),
    'task_id' => (int) ($_GET['task_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-d'))),
    'limit' => 100,
];

$summary = $service->getSummary($filters);
$topBlockers = $service->getTopBlockers($filters);
$contextHealth = $service->getContextHealth($filters);
$events = $service->getRecentEvents($filters);
$coachFeedbackInsights = $service->getCoachFeedbackInsights($filters);
$coachFeedbackCounts = (array) ($coachFeedbackInsights['counts'] ?? []);
$coachAcceptedFeedbackCount = (int) ($coachFeedbackCounts['useful'] ?? 0) + (int) ($coachFeedbackCounts['acted_on'] ?? 0) + (int) ($coachFeedbackCounts['already_done'] ?? 0);
$coachRejectedFeedbackCount = (int) ($coachFeedbackCounts['not_useful'] ?? 0) + (int) ($coachFeedbackCounts['dismissed'] ?? 0);
$coachControlSummary = $service->getCoachRecommendationControlSummary($filters);
$coachControlCounts = (array) ($coachControlSummary['counts'] ?? []);
$marketplaceRecommendationSummary = $service->getMarketplaceRecommendationSummary($filters);
$marketplaceRecommendationCounts = (array) ($marketplaceRecommendationSummary['counts'] ?? []);
$marketplaceRecommendationInsights = $service->getMarketplaceRecommendationInsights($filters);
$marketplaceSetupJourneySummary = $service->getMarketplaceSetupJourneySummary($filters);
$marketplaceSetupJourneyCounts = (array) ($marketplaceSetupJourneySummary['counts'] ?? []);
$marketplaceAdaptiveSignalSummary = $service->getMarketplaceAdaptiveSignalSummary($filters);
$marketplaceControlSummary = $service->getMarketplaceRecommendationControlSummary($filters);
$marketplaceControlCounts = (array) ($marketplaceControlSummary['counts'] ?? []);
$marketplaceActivationBundleSummary = $service->getMarketplaceActivationBundleSummary($filters);
$marketplaceActivationBundleCounts = (array) ($marketplaceActivationBundleSummary['counts'] ?? []);
$marketplaceActivationBundleEventSummary = $service->getMarketplaceActivationBundleEventSummary($filters);
$marketplaceActivationBundleEventCounts = (array) ($marketplaceActivationBundleEventSummary['counts'] ?? []);
$marketplaceActivationBundleInsights = $service->getMarketplaceActivationBundleInsights($filters);
$marketplaceActivationBundleAdaptiveSignalSummary = $service->getMarketplaceActivationBundleAdaptiveSignalSummary($filters);
$pluginRuntimeSummary = $pluginRuntimeEventService->summary([
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
]);
$pluginRuntimeFailureCount = (int) (($pluginRuntimeSummary['counts']['capability_failed'] ?? 0) + ($pluginRuntimeSummary['counts']['authorization_blocked'] ?? 0) + ($pluginRuntimeSummary['counts']['readiness_blocked'] ?? 0));
$jobHealthSummary = $jobHealthService->getSummary();
$jobHealth = $jobHealthService->getJobs();
$feedbackBuckets = ['useful' => 0, 'not_useful' => 0, 'acted_on' => 0, 'already_done' => 0, 'dismissed' => 0];
foreach ($events as $event) {
    foreach ((array) ($event['reason_codes'] ?? []) as $reasonCode) {
        if (isset($feedbackBuckets[$reasonCode])) {
            $feedbackBuckets[$reasonCode]++;
        }
    }
}
$calibration = $calibrationService->getCalibrationSummary([
    'surface' => $filters['source'],
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
]);
$calibrationSummary = (array) ($calibration['summary'] ?? []);
$recentTuningChanges = (array) ($calibration['recent_changes'] ?? []);
$promptQualitySummary = $promptQualityService->getActivePromptSummary();
$runtimeControls = $runtimeControlService->getAllEffectiveControls();
$activeRuntimeControls = array_filter(
    $runtimeControls,
    static fn(array $control): bool => (string) ($control['control_mode'] ?? 'normal') !== 'normal'
);
$promptWarnings = [
    'stale_context' => count(array_filter($promptQualitySummary, static fn(array $row): bool => (float) ($row['stale_context_rate'] ?? 0) > 0)),
    'overload' => count(array_filter($promptQualitySummary, static fn(array $row): bool => (float) ($row['overload_rate'] ?? 0) > 0)),
];
$activeIncidents = $incidentService->getActiveIncidents();
$selectedEventId = trim((string) ($_GET['event'] ?? ''));
$selectedEvent = null;
foreach ($events as $event) {
    if ($selectedEventId !== '' && $event['id'] === $selectedEventId) {
        $selectedEvent = $event;
        break;
    }
}
if ($selectedEvent === null) {
    $selectedEvent = $events[0] ?? null;
}
$selectedCoachMetadata = [];
$selectedCoachControlMatches = [];
if ($selectedEvent && (string) ($selectedEvent['source'] ?? '') === 'coach') {
    $selectedPayloadForControls = (array) ($selectedEvent['payload'] ?? []);
    $selectedCoachMetadata = isset($selectedPayloadForControls['metadata']) && is_array($selectedPayloadForControls['metadata'])
        ? $selectedPayloadForControls['metadata']
        : [];
    foreach ((array) ($coachControlSummary['active_controls'] ?? []) as $control) {
        $scope = (string) ($control['control_scope'] ?? '');
        $value = (string) ($control['control_value'] ?? '');
        $metadataValue = match ($scope) {
            'feedback_signature' => (string) ($selectedCoachMetadata['feedback_signature'] ?? ''),
            'source_type' => (string) ($selectedCoachMetadata['source_recommendation_type'] ?? ''),
            'source_section' => (string) ($selectedCoachMetadata['source_section'] ?? ''),
            default => '',
        };
        if ($metadataValue !== '' && $value === strtolower(trim(preg_replace('/[^a-z0-9_:\-.]+/', '_', $metadataValue) ?? ''))) {
            $selectedCoachControlMatches[] = $control;
        }
    }
}

function diagnosticsBadgeColor(string $decision): string
{
    return aiUiDecisionColors($decision)['text'];
}

function diagnosticsQuery(array $filters, array $overrides = []): string
{
    return http_build_query(array_filter(array_merge($filters, $overrides), static fn ($value) => $value !== '' && $value !== 0 && $value !== null));
}

$pageTitle = 'AI & Automation Diagnostics - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI &amp; Automation Diagnostics</h1>
                <p>Unified visibility into AI guidance, assistant actions, commercial automation, workflow failures, retries, and task evidence.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_learning_review.php" class="btn-premium-secondary">Learning review</a>
                <a href="ai_recovery_workbench.php" class="btn-premium-secondary">Recovery workbench</a>
                <a href="ai_control_center.php" class="btn-premium-secondary">Control center</a>
            </div>
        </div>

        <?php if ($rollbackMessage): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($rollbackMessage); ?></div>
        <?php endif; ?>
        <?php if ($rollbackError): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($rollbackError); ?></div>
        <?php endif; ?>
        <?php if (!empty($activeRuntimeControls)): ?>
            <div class="content-card" style="margin-bottom:1rem;border-color:#f59e0b;background:#fffbeb;">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;color:#92400e;">AI runtime controls are active</h2>
                        <p style="margin:0;color:#92400e;">Some AI surfaces are currently paused, degraded, or diagnostics-only.</p>
                    </div>
                    <a href="ai_control_center.php" class="btn-premium-secondary">Open Control Center</a>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
                            <?php foreach ($activeRuntimeControls as $surface => $control): ?>
                                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #fcd34d;color:#92400e;font-size:12px;font-weight:700;">
                            <?php echo htmlspecialchars((string) $surface); ?>: <?php echo htmlspecialchars(aiUiDecisionMeta((string) ($control['control_mode'] ?? 'normal'))['label']); ?>
                                </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($activeIncidents)): ?>
            <div class="content-card" style="margin-bottom:1rem;border-color:#dc2626;background:#fef2f2;">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;color:#991b1b;">Active AI incidents detected</h2>
                        <p style="margin:0;color:#991b1b;"><?php echo count($activeIncidents); ?> active AI/automation incident<?php echo count($activeIncidents) === 1 ? '' : 's'; ?> currently match alert thresholds.</p>
                    </div>
                    <a href="ai_recovery_workbench.php" class="btn-premium-secondary">Open recovery workbench</a>
                </div>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Blocked AI actions</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['blocked_ai_actions']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Approval required</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['approval_required']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Workflow failures</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['workflow_failures']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Retries pending</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['workflow_retries_pending']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Degraded capabilities</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['degraded_capabilities']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Task auto-completions</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) $summary['tasks_auto_completed']; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Plugin runtime events</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo (int) ($pluginRuntimeSummary['total_events'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Plugin runtime blocks</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo $pluginRuntimeFailureCount; ?></div></div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Plugin Runtime</h2>
                    <p style="margin:0;color:#64748b;">Runtime outcomes from plugin capabilities tied to tasks, targets, workflow actions, gates, and AI context.</p>
                </div>
            </div>
            <?php if (empty($pluginRuntimeSummary['top_skills'])): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No plugin runtime events have been recorded yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:.65rem;margin-top:1rem;">
                    <?php foreach (array_slice((array) ($pluginRuntimeSummary['top_skills'] ?? []), 0, 6) as $runtimeSkill): ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.75rem .85rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($runtimeSkill['skill_key'] ?? 'plugin'))); ?></div>
                            <div style="font-size:12px;color:#334155;font-weight:800;"><?php echo (int) ($runtimeSkill['events'] ?? 0); ?> events</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Adaptive Activation Bundle Signals</h2>
                    <p style="margin:0;color:#64748b;">Bounded rule-based boosts and dampening from activation bundle analytics and insights.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">
                        Boosts <?php echo (int) ($marketplaceActivationBundleAdaptiveSignalSummary['positive_boosts'] ?? 0); ?>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;font-weight:700;">
                        Dampened <?php echo (int) ($marketplaceActivationBundleAdaptiveSignalSummary['negative_dampening'] ?? 0); ?>
                    </span>
                </div>
            </div>
            <?php if (empty($marketplaceActivationBundleAdaptiveSignalSummary['top_bundles'])): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No adaptive activation bundle signals matched the current filters.</p>
            <?php else: ?>
                <div style="display:grid;gap:.65rem;margin-top:1rem;">
                    <?php foreach ((array) ($marketplaceActivationBundleAdaptiveSignalSummary['top_bundles'] ?? []) as $bundleSignal): ?>
                        <?php $bundleDelta = (int) ($bundleSignal['adaptive_score_delta'] ?? 0); ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.75rem .85rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div>
                                <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($bundleSignal['bundle_key'] ?? 'activation bundle'))); ?></div>
                                <div style="font-size:12px;color:#64748b;">Confidence <?php echo htmlspecialchars((string) ($bundleSignal['adaptive_confidence'] ?? 'low')); ?></div>
                            </div>
                            <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;justify-content:flex-end;font-size:12px;color:#334155;">
                                <span style="padding:4px 8px;border-radius:999px;background:<?php echo $bundleDelta >= 0 ? '#f0fdf4' : '#fff7ed'; ?>;border:1px solid <?php echo $bundleDelta >= 0 ? '#bbf7d0' : '#fed7aa'; ?>;font-weight:800;"><?php echo $bundleDelta >= 0 ? '+' : ''; ?><?php echo $bundleDelta; ?></span>
                                <?php foreach (array_slice((array) ($bundleSignal['adaptive_reason_codes'] ?? []), 0, 3) as $bundleReasonCode): ?>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $bundleReasonCode)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Adaptive Recommendation Signals</h2>
                    <p style="margin:0;color:#64748b;">Bounded rule-based boosts and dampening from Marketplace recommendation and setup journey analytics.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">
                        Boosts <?php echo (int) ($marketplaceAdaptiveSignalSummary['positive_boosts'] ?? 0); ?>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;font-weight:700;">
                        Dampened <?php echo (int) ($marketplaceAdaptiveSignalSummary['negative_dampening'] ?? 0); ?>
                    </span>
                </div>
            </div>
            <?php if (empty($marketplaceAdaptiveSignalSummary['top_skills'])): ?>
                <p style="margin:1rem 0 0 0;color:#64748b;">No adaptive recommendation signals matched the current filters.</p>
            <?php else: ?>
                <div style="display:grid;gap:.65rem;margin-top:1rem;">
                    <?php foreach ((array) ($marketplaceAdaptiveSignalSummary['top_skills'] ?? []) as $skill): ?>
                        <?php $delta = (int) ($skill['adaptive_score_delta'] ?? 0); ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.75rem .85rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div>
                                <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($skill['skill_key'] ?? 'marketplace skill'))); ?></div>
                                <div style="font-size:12px;color:#64748b;">Confidence <?php echo htmlspecialchars((string) ($skill['adaptive_confidence'] ?? 'low')); ?></div>
                            </div>
                            <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;justify-content:flex-end;font-size:12px;color:#334155;">
                                <span style="padding:4px 8px;border-radius:999px;background:<?php echo $delta >= 0 ? '#f0fdf4' : '#fff7ed'; ?>;border:1px solid <?php echo $delta >= 0 ? '#bbf7d0' : '#fed7aa'; ?>;font-weight:800;"><?php echo $delta >= 0 ? '+' : ''; ?><?php echo $delta; ?></span>
                                <?php foreach (array_slice((array) ($skill['adaptive_reason_codes'] ?? []), 0, 3) as $reasonCode): ?>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $reasonCode)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Setup Journeys</h2>
                    <p style="margin:0;color:#64748b;">Setup journey movement and friction for owner/admin Marketplace guidance.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:700;">
                        Open rate <?php echo number_format(((float) ($marketplaceSetupJourneySummary['setup_open_rate'] ?? 0)) * 100, 1); ?>%
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">
                        Completion <?php echo number_format(((float) ($marketplaceSetupJourneySummary['manual_step_completion_rate'] ?? 0)) * 100, 1); ?>%
                    </span>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-top:1rem;">
                <?php foreach (['journey_impression' => 'Journeys', 'setup_opened' => 'Setup opens', 'step_completed' => 'Done steps', 'step_skipped' => 'Skipped', 'step_reset' => 'Resets', 'install_completed' => 'Installs'] as $metricKey => $metricLabel): ?>
                    <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;"><?php echo htmlspecialchars($metricLabel); ?></div>
                        <div style="font-size:24px;font-weight:800;margin-top:.3rem;color:#0f172a;"><?php echo (int) ($marketplaceSetupJourneyCounts[$metricKey] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:1rem;">
                <h3 style="margin:0 0 .6rem 0;">Top friction steps</h3>
                <?php if (empty($marketplaceSetupJourneySummary['top_steps'])): ?>
                    <p style="margin:0;color:#64748b;">No setup journey events matched the current filters.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.5rem;">
                        <?php foreach ((array) ($marketplaceSetupJourneySummary['top_steps'] ?? []) as $step): ?>
                            <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.7rem .8rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                                <div>
                                    <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars((string) ($step['label'] ?? $step['step_key'] ?? 'Setup step')); ?></div>
                                    <div style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($step['skill_key'] ?? 'marketplace skill'))); ?></div>
                                </div>
                                <div style="display:flex;gap:.35rem;flex-wrap:wrap;font-size:12px;color:#334155;">
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Done <?php echo (int) ($step['step_completed'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;">Skipped <?php echo (int) ($step['step_skipped'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f1f5f9;border:1px solid #cbd5e1;">Reset <?php echo (int) ($step['step_reset'] ?? 0); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Prompt and Context Quality</h2>
                    <p style="margin:0;color:#64748b;">Registry-backed prompt versions and recent context quality warnings across AI surfaces.</p>
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">Active prompts <?php echo count($promptQualitySummary); ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">Stale warnings <?php echo (int) $promptWarnings['stale_context']; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">Overload warnings <?php echo (int) $promptWarnings['overload']; ?></span>
                </div>
            </div>
            <div style="margin-top:.75rem;">
                <a href="ai_prompt_control.php" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">Open Prompt Control</a>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">AI Coach Feedback Insights</h2>
                    <p style="margin:0;color:#64748b;">Read-only view of how explicit Coach feedback is shaping future recommendation ranking.</p>
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">Accepted <?php echo (int) $coachAcceptedFeedbackCount; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;font-weight:700;">Rejected <?php echo (int) $coachRejectedFeedbackCount; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">Acted on <?php echo (int) ($coachFeedbackCounts['acted_on'] ?? 0); ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">Dismissed <?php echo (int) ($coachFeedbackCounts['dismissed'] ?? 0); ?></span>
                </div>
            </div>
            <?php if (!$coachControlsReady): ?>
                <p style="margin:1rem 0 0;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.75rem;">AI Coach tuning controls are unavailable because the controls table is missing. Run migration 248 before operators create boosts, mutes, or learning resets.</p>
            <?php endif; ?>
            <?php if ((int) ($coachFeedbackInsights['total_feedback'] ?? 0) <= 0): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No AI Coach feedback matched the current filters yet. Feedback insights will appear after users mark recommendations useful, not relevant, already done, or show fewer like this.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.85rem;margin-top:1rem;">
                    <?php foreach ([
                        'top_positive_signatures' => 'Accepted patterns',
                        'top_acted_on_signatures' => 'Acted-on patterns',
                        'top_dismissed_signatures' => 'Dismissed patterns',
                        'top_negative_signatures' => 'Rejected patterns',
                    ] as $groupKey => $groupLabel): ?>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <h3 style="margin:0 0 .65rem 0;font-size:1rem;"><?php echo htmlspecialchars($groupLabel); ?></h3>
                            <?php if (empty($coachFeedbackInsights[$groupKey])): ?>
                                <p style="margin:0;color:#64748b;font-size:.9rem;">No matching patterns yet.</p>
                            <?php else: ?>
                                <div style="display:grid;gap:.55rem;">
                                    <?php foreach ((array) $coachFeedbackInsights[$groupKey] as $pattern): ?>
                                        <?php
                                        $patternEventId = (string) ($pattern['latest_event_id'] ?? '');
                                        $patternUrl = $patternEventId !== ''
                                            ? '?' . diagnosticsQuery($filters, ['source' => 'coach', 'event' => $patternEventId])
                                            : '#';
                                        ?>
                                        <a href="<?php echo htmlspecialchars($patternUrl); ?>" style="display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.65rem;">
                                            <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars(substr((string) ($pattern['feedback_signature'] ?? 'unknown'), 0, 28)); ?></div>
                                            <div style="color:#64748b;font-size:.85rem;margin-top:.25rem;">
                                                <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($pattern['source_type'] ?? 'coach'))); ?>
                                                &middot; <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($pattern['source_section'] ?? 'unknown'))); ?>
                                            </div>
                                            <div style="color:#475569;font-size:.82rem;margin-top:.25rem;">
                                                Positive <?php echo (int) ($pattern['positive_count'] ?? 0); ?>
                                                &middot; Negative <?php echo (int) ($pattern['negative_count'] ?? 0); ?>
                                                &middot; Dismissed <?php echo (int) ($pattern['dismissed_count'] ?? 0); ?>
                                            </div>
                                        </a>
                                        <?php if ($coachControlsReady): ?>
                                        <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem;">
                                            <?php foreach (['boosted' => 'Boost', 'muted' => 'Mute signature', 'reset_learning' => 'Reset learning'] as $controlType => $controlLabel): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="set_ai_coach_control">
                                                    <input type="hidden" name="control_scope" value="feedback_signature">
                                                    <input type="hidden" name="control_value" value="<?php echo htmlspecialchars((string) ($pattern['feedback_signature'] ?? '')); ?>">
                                                    <input type="hidden" name="control_type" value="<?php echo htmlspecialchars($controlType); ?>">
                                                    <input type="hidden" name="latest_event_id" value="<?php echo htmlspecialchars($patternEventId); ?>">
                                                    <input type="hidden" name="label" value="<?php echo htmlspecialchars((string) ($pattern['source_type'] ?? 'Coach pattern')); ?>">
                                                    <input type="hidden" name="reason" value="<?php echo htmlspecialchars('diagnostics_' . $controlType); ?>">
                                                    <button type="submit" class="btn-premium-secondary" style="padding:.35rem .55rem;font-size:12px;"><?php echo htmlspecialchars($controlLabel); ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <p style="margin:.35rem 0 0;color:#64748b;font-size:12px;">Boost gives this exact pattern a small lift. Mute signature may hide repeated matching advice. Reset learning ignores older feedback without deleting it.</p>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($coachFeedbackInsights['recent_adjusted_patterns'])): ?>
                    <div style="margin-top:1rem;">
                        <h3 style="margin:0 0 .6rem 0;">Recent adjusted patterns</h3>
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                            <?php foreach ((array) $coachFeedbackInsights['recent_adjusted_patterns'] as $pattern): ?>
                                <?php
                                $patternEventId = (string) ($pattern['latest_event_id'] ?? '');
                                $patternUrl = $patternEventId !== ''
                                    ? '?' . diagnosticsQuery($filters, ['source' => 'coach', 'event' => $patternEventId])
                                    : '#';
                                ?>
                                <a href="<?php echo htmlspecialchars($patternUrl); ?>" style="display:inline-flex;align-items:center;gap:.4rem;padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;text-decoration:none;">
                                    <?php echo htmlspecialchars(substr((string) ($pattern['feedback_signature'] ?? 'unknown'), 0, 24)); ?>
                                    <span style="color:#64748b;">+<?php echo (int) ($pattern['positive_count'] ?? 0); ?> / -<?php echo (int) ($pattern['negative_count'] ?? 0); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.85rem;margin-top:1rem;">
                    <?php foreach ([
                        'source_type_breakdown' => 'Source types',
                        'source_section_breakdown' => 'Sections',
                        'why_signal_breakdown' => 'Why signals',
                        'trust_signal_breakdown' => 'Trust signals',
                    ] as $breakdownKey => $breakdownLabel): ?>
                        <div>
                            <h3 style="margin:0 0 .5rem 0;font-size:.95rem;"><?php echo htmlspecialchars($breakdownLabel); ?></h3>
                            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                <?php if (empty($coachFeedbackInsights[$breakdownKey])): ?>
                                    <span style="color:#64748b;font-size:.9rem;">None yet</span>
                                <?php else: ?>
                                    <?php foreach ((array) $coachFeedbackInsights[$breakdownKey] as $row): ?>
                                        <?php
                                        $breakdownLabelValue = (string) ($row['label'] ?? 'unknown');
                                        $breakdownScope = $breakdownKey === 'source_type_breakdown'
                                            ? 'source_type'
                                            : ($breakdownKey === 'source_section_breakdown' ? 'source_section' : '');
                                        ?>
                                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:5px 9px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $breakdownLabelValue)); ?>
                                            <?php echo (int) ($row['count'] ?? 0); ?>
                                        </span>
                                        <?php if ($breakdownScope !== '' && $coachControlsReady): ?>
                                            <?php foreach (['boosted' => 'Boost', 'muted' => 'Mute group', 'reset_learning' => 'Reset'] as $controlType => $controlLabel): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="set_ai_coach_control">
                                                    <input type="hidden" name="control_scope" value="<?php echo htmlspecialchars($breakdownScope); ?>">
                                                    <input type="hidden" name="control_value" value="<?php echo htmlspecialchars($breakdownLabelValue); ?>">
                                                    <input type="hidden" name="control_type" value="<?php echo htmlspecialchars($controlType); ?>">
                                                    <input type="hidden" name="label" value="<?php echo htmlspecialchars($breakdownLabelValue); ?>">
                                                    <input type="hidden" name="reason" value="<?php echo htmlspecialchars('diagnostics_' . $controlType); ?>">
                                                    <button type="submit" class="btn-premium-secondary" style="padding:.25rem .45rem;font-size:11px;"><?php echo htmlspecialchars($controlLabel); ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                            <span style="color:#64748b;font-size:11px;">Group mute downranks this broader class; reset ignores older matching feedback.</span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">AI Coach Tuning Controls</h2>
                    <p style="margin:0;color:#64748b;">Admin boosts, mutes, and learning resets that conservatively adjust AI Coach ranking.</p>
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">Boosted <?php echo (int) ($coachControlCounts['boosted'] ?? 0); ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;font-weight:700;">Muted <?php echo (int) ($coachControlCounts['muted'] ?? 0); ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:700;">Resets <?php echo (int) ($coachControlCounts['reset_learning'] ?? 0); ?></span>
                </div>
            </div>
            <?php if (!$coachControlsReady): ?>
                <p style="margin:1rem 0 0;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.75rem;">Controls unavailable. Run the AI Coach recommendation controls migration to enable this operator panel.</p>
            <?php elseif (empty($coachControlSummary['active_controls'])): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No active AI Coach tuning controls yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:.55rem;margin-top:1rem;">
                    <?php foreach ((array) ($coachControlSummary['active_controls'] ?? []) as $control): ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.75rem .85rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div>
                                <div style="font-weight:800;color:#0f172a;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['control_type'] ?? 'control'))); ?>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['control_scope'] ?? 'scope'))); ?>
                                </div>
                                <div style="font-size:12px;color:#64748b;margin-top:.25rem;"><?php echo htmlspecialchars((string) ($control['control_value'] ?? '')); ?></div>
                                <?php if (!empty($control['reason'])): ?>
                                    <div style="font-size:12px;color:#64748b;margin-top:.25rem;"><?php echo htmlspecialchars((string) $control['reason']); ?></div>
                                <?php endif; ?>
                            </div>
                            <form method="POST" style="display:flex;gap:.4rem;align-items:center;margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="disable_ai_coach_control">
                                <input type="hidden" name="control_id" value="<?php echo (int) ($control['id'] ?? 0); ?>">
                                <input type="hidden" name="reason" value="Disabled from diagnostics">
                                <button type="submit" class="btn-premium-secondary" style="padding:.4rem .65rem;font-size:12px;">Disable</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (array_sum($feedbackBuckets) > 0): ?>
            <div class="content-card" style="margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;">Advice Feedback</h2>
                        <p style="margin:0;color:#64748b;">Explicit coach and Clarity feedback captured in the current result set.</p>
                    </div>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <?php foreach ($feedbackBuckets as $label => $count): ?>
                            <?php if ($count > 0): ?>
                                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:600;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $label)); ?> <?php echo (int) $count; ?>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Recommendations</h2>
                    <p style="margin:0;color:#64748b;">Recommendation analytics across Marketplace, Clarity, and Coach for the current filters.</p>
                </div>
                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:700;">
                    CTR <?php echo number_format(((float) ($marketplaceRecommendationSummary['click_through_rate'] ?? 0)) * 100, 1); ?>%
                </span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-top:1rem;">
                <?php foreach (['impression' => 'Impressions', 'cta_clicked' => 'Clicks', 'dismissed' => 'Dismissals', 'snoozed' => 'Snoozes', 'task_created' => 'Tasks', 'installed' => 'Installs'] as $metricKey => $metricLabel): ?>
                    <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;"><?php echo htmlspecialchars($metricLabel); ?></div>
                        <div style="font-size:24px;font-weight:800;margin-top:.3rem;color:#0f172a;"><?php echo (int) ($marketplaceRecommendationCounts[$metricKey] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:1rem;">
                <h3 style="margin:0 0 .6rem 0;">Top recommended skills</h3>
                <?php if (empty($marketplaceRecommendationSummary['top_skills'])): ?>
                    <p style="margin:0;color:#64748b;">No marketplace recommendation events matched the current filters.</p>
                <?php else: ?>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <?php foreach ((array) ($marketplaceRecommendationSummary['top_skills'] ?? []) as $skill): ?>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                                <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($skill['skill_key'] ?? 'skill'))); ?>
                                <?php echo (int) ($skill['events'] ?? 0); ?> events
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Controls</h2>
                    <p style="margin:0;color:#64748b;">Owner/admin pins, mutes, and surface visibility controls currently shaping recommendations.</p>
                </div>
                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                    <?php echo (int) ($marketplaceControlSummary['total_controls'] ?? 0); ?> active
                </span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-top:1rem;">
                <?php foreach (['pinned' => 'Pinned', 'muted' => 'Muted', 'surface_disabled' => 'Surface hidden'] as $controlKey => $controlLabel): ?>
                    <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;"><?php echo htmlspecialchars($controlLabel); ?></div>
                        <div style="font-size:24px;font-weight:800;margin-top:.3rem;color:#0f172a;"><?php echo (int) ($marketplaceControlCounts[$controlKey] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($marketplaceControlSummary['active_controls'])): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No active Marketplace recommendation controls.</p>
            <?php else: ?>
                <div style="display:grid;gap:.5rem;margin-top:1rem;">
                    <?php foreach ((array) ($marketplaceControlSummary['active_controls'] ?? []) as $control): ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.7rem .8rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div>
                                <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['skill_key'] ?? 'marketplace skill'))); ?></div>
                                <div style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['reason_code'] ?? 'admin control'))); ?></div>
                            </div>
                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;font-size:12px;color:#334155;">
                                <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['control_type'] ?? 'control'))); ?></span>
                                <span style="padding:4px 8px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['surface'] ?? 'all'))); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Activation Bundles</h2>
                    <p style="margin:0;color:#64748b;">Bundle state and analytics for grouped Marketplace activation paths.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:700;">
                        <?php echo (int) ($marketplaceActivationBundleSummary['total_state_rows'] ?? 0); ?> saved states
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:700;">
                        CTR <?php echo number_format(((float) ($marketplaceActivationBundleEventSummary['click_through_rate'] ?? 0)) * 100, 1); ?>%
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:700;">
                        Completion <?php echo number_format(((float) ($marketplaceActivationBundleEventSummary['completion_rate'] ?? 0)) * 100, 1); ?>%
                    </span>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-top:1rem;">
                <?php foreach (['bundle_impression' => 'Impressions', 'cta_clicked' => 'Clicks', 'selected' => 'Selected', 'dismissed' => 'Dismissed', 'completed' => 'Completed', 'module_installed' => 'Installs'] as $bundleMetricKey => $bundleMetricLabel): ?>
                    <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;"><?php echo htmlspecialchars($bundleMetricLabel); ?></div>
                        <div style="font-size:24px;font-weight:800;margin-top:.3rem;color:#0f172a;"><?php echo (int) ($marketplaceActivationBundleEventCounts[$bundleMetricKey] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-top:.75rem;">
                <?php foreach (['selected' => 'Selected state', 'dismissed' => 'Dismissed state', 'completed' => 'Completed state'] as $bundleStateKey => $bundleStateLabel): ?>
                    <div style="padding:.75rem;background:#fff;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;"><?php echo htmlspecialchars($bundleStateLabel); ?></div>
                        <div style="font-size:20px;font-weight:800;margin-top:.25rem;color:#0f172a;"><?php echo (int) ($marketplaceActivationBundleCounts[$bundleStateKey] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($marketplaceActivationBundleSummary['active_bundles'])): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No active Marketplace activation bundles matched the current filters.</p>
            <?php else: ?>
                <div style="display:grid;gap:.5rem;margin-top:1rem;">
                    <?php foreach ((array) ($marketplaceActivationBundleSummary['active_bundles'] ?? []) as $bundle): ?>
                        <?php $bundleProgress = (array) ($bundle['progress'] ?? []); ?>
                        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.7rem .8rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <div>
                                <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars((string) ($bundle['label'] ?? 'Activation bundle')); ?></div>
                                <div style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars((string) ($bundle['summary'] ?? '')); ?></div>
                            </div>
                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;font-size:12px;color:#334155;">
                                <span style="padding:4px 8px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;"><?php echo htmlspecialchars((string) ($bundle['priority'] ?? 'medium')); ?></span>
                                <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) ($bundle['status'] ?? 'suggested')); ?></span>
                                <span style="padding:4px 8px;border-radius:999px;background:#dcfce7;border:1px solid #bbf7d0;color:#166534;"><?php echo (int) ($bundleProgress['installed'] ?? 0); ?> installed</span>
                                <span style="padding:4px 8px;border-radius:999px;background:#dbeafe;border:1px solid #bfdbfe;color:#1d4ed8;"><?php echo (int) ($bundleProgress['recommended'] ?? 0); ?> recommended</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div style="margin-top:1rem;">
                <h3 style="margin:0 0 .6rem 0;">Top bundle activity</h3>
                <?php if (empty($marketplaceActivationBundleEventSummary['top_bundles'])): ?>
                    <p style="margin:0;color:#64748b;">No activation bundle events matched the current filters.</p>
                <?php else: ?>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <?php foreach ((array) ($marketplaceActivationBundleEventSummary['top_bundles'] ?? []) as $bundleEvent): ?>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                                <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($bundleEvent['bundle_key'] ?? 'activation bundle'))); ?>
                                <?php echo (int) ($bundleEvent['events'] ?? 0); ?> events
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Activation Bundle Insights</h2>
                    <p style="margin:0;color:#64748b;">Diagnostics-only interpretation of activation bundle engagement and follow-through.</p>
                </div>
                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                    <?php echo count($marketplaceActivationBundleInsights); ?> insight<?php echo count($marketplaceActivationBundleInsights) === 1 ? '' : 's'; ?>
                </span>
            </div>
            <?php if (empty($marketplaceActivationBundleInsights)): ?>
                <p style="margin:1rem 0 0;color:#64748b;">No activation bundle insights matched the current filters.</p>
            <?php else: ?>
                <div style="display:grid;gap:.75rem;margin-top:1rem;">
                    <?php foreach ($marketplaceActivationBundleInsights as $bundleInsight): ?>
                        <?php
                        $bundleInsightSeverity = (string) ($bundleInsight['severity'] ?? 'low');
                        $bundleInsightColor = match ($bundleInsightSeverity) {
                            'high' => '#991b1b',
                            'medium' => '#92400e',
                            default => '#166534',
                        };
                        $bundleInsightSnapshot = (array) ($bundleInsight['metric_snapshot'] ?? []);
                        ?>
                        <div style="padding:1rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
                            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                <div>
                                    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.45rem;">
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $bundleInsightColor; ?>15;color:<?php echo $bundleInsightColor; ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($bundleInsightSeverity); ?></span>
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($bundleInsight['bundle_key'] ?? 'activation bundle'))); ?></span>
                                    </div>
                                    <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars((string) ($bundleInsight['title'] ?? 'Activation bundle insight')); ?></div>
                                    <p style="margin:.35rem 0 0;color:#475569;"><?php echo htmlspecialchars((string) ($bundleInsight['summary'] ?? '')); ?></p>
                                    <p style="margin:.35rem 0 0;color:#64748b;"><?php echo htmlspecialchars((string) ($bundleInsight['recommendation'] ?? '')); ?></p>
                                </div>
                                <div style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;font-size:12px;color:#334155;">
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Impressions <?php echo (int) ($bundleInsightSnapshot['impressions'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Clicks <?php echo (int) ($bundleInsightSnapshot['clicks'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Selected <?php echo (int) ($bundleInsightSnapshot['selected'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Dismissed <?php echo (int) ($bundleInsightSnapshot['dismissals'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Completed <?php echo (int) ($bundleInsightSnapshot['completed'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Installs <?php echo (int) ($bundleInsightSnapshot['module_installed'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Marketplace Recommendation Insights</h2>
                    <p style="margin:0;color:#64748b;">Suggest-only interpretation of Marketplace recommendation engagement. Scoring and suppression are unchanged.</p>
                </div>
                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                    <?php echo count($marketplaceRecommendationInsights); ?> insight<?php echo count($marketplaceRecommendationInsights) === 1 ? '' : 's'; ?>
                </span>
            </div>
            <?php if (empty($marketplaceRecommendationInsights)): ?>
                <p style="margin:1rem 0 0 0;color:#64748b;">No adaptive marketplace insights matched the current filters.</p>
            <?php else: ?>
                <div style="display:grid;gap:.75rem;margin-top:1rem;">
                    <?php foreach ($marketplaceRecommendationInsights as $insight): ?>
                        <?php
                        $severity = (string) ($insight['severity'] ?? 'low');
                        $severityColor = match ($severity) {
                            'high' => '#991b1b',
                            'medium' => '#92400e',
                            default => '#166534',
                        };
                        $snapshot = (array) ($insight['metric_snapshot'] ?? []);
                        ?>
                        <div style="padding:1rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
                            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                <div>
                                    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.45rem;">
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $severityColor; ?>15;color:<?php echo $severityColor; ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($severity); ?></span>
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($insight['skill_key'] ?? 'marketplace skill'))); ?></span>
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($insight['surface'] ?? 'marketplace'))); ?></span>
                                    </div>
                                    <div style="font-weight:800;color:#0f172a;"><?php echo htmlspecialchars((string) ($insight['title'] ?? 'Marketplace insight')); ?></div>
                                    <p style="margin:.35rem 0 0 0;color:#475569;"><?php echo htmlspecialchars((string) ($insight['summary'] ?? '')); ?></p>
                                    <p style="margin:.35rem 0 0 0;color:#64748b;"><?php echo htmlspecialchars((string) ($insight['recommendation'] ?? '')); ?></p>
                                </div>
                                <div style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;font-size:12px;color:#334155;">
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Impressions <?php echo (int) ($snapshot['impressions'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Clicks <?php echo (int) ($snapshot['clicks'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Dismissals <?php echo (int) ($snapshot['dismissals'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Tasks <?php echo (int) ($snapshot['task_created'] ?? 0); ?></span>
                                    <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;">Installs <?php echo (int) ($snapshot['installs'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.85rem;align-items:end;">
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Source</label>
                    <select name="source" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'coach' => 'Coach', 'clarity_chat' => 'Clarity Chat', 'marketplace_recommendation' => 'Marketplace Recommendations', 'marketplace_setup_journey' => 'Marketplace Setup Journeys', 'marketplace_activation_bundle' => 'Marketplace Activation Bundles', 'assistant' => 'Assistant', 'commercial' => 'Commercial', 'workflow' => 'Workflow', 'task_automation' => 'Task Automation', 'capability' => 'Capability', 'control' => 'Runtime Control', 'job' => 'Job Health'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $filters['source'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Decision</label>
                    <select name="decision" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'allow' => 'Allow', 'allow_with_warning' => 'Allow with warning', 'suggest_only' => 'Suggest only', 'approval_required' => 'Approval required', 'blocked' => 'Blocked', 'failed' => 'Failed', 'retry_pending' => 'Retry pending'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $filters['decision'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Reason bucket</label>
                    <input type="text" name="reason_bucket" value="<?php echo htmlspecialchars($filters['reason_bucket']); ?>" placeholder="missing_context" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Mode</label>
                    <select name="mode" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'foundation' => 'Foundation', 'operations' => 'Operations', 'guardian' => 'Guardian'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $filters['mode'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">Deal ID</label><input type="number" min="0" name="deal_id" value="<?php echo $filters['deal_id'] ?: ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">Invoice ID</label><input type="number" min="0" name="invoice_id" value="<?php echo $filters['invoice_id'] ?: ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">Contact ID</label><input type="number" min="0" name="contact_id" value="<?php echo $filters['contact_id'] ?: ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">Task ID</label><input type="number" min="0" name="task_id" value="<?php echo $filters['task_id'] ?: ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div><label style="display:block;margin-bottom:.35rem;font-weight:600;">To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                <div style="display:flex;gap:.5rem;">
                    <button type="submit" class="btn-premium-primary">Filter</button>
                    <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(340px,.9fr);gap:1rem;align-items:start;">
            <div class="content-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;">Unified Timeline</h2>
                        <p style="margin:0;color:#64748b;">Top blockers: <?php echo htmlspecialchars(implode(', ', array_map(static fn (array $row): string => $row['bucket'] . ' (' . $row['count'] . ')', array_slice($topBlockers, 0, 3)))); ?></p>
                    </div>
                    <div style="font-size:.9rem;color:#64748b;">Context health: ready <?php echo (int) $contextHealth['ready']; ?>, degraded <?php echo (int) $contextHealth['degraded']; ?>, missing <?php echo (int) $contextHealth['missing']; ?></div>
                </div>
                <?php if (empty($events)): ?>
                    <p style="margin:0;color:#64748b;">No diagnostics events matched the current filters.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($events as $event): ?>
                            <?php $decisionColor = diagnosticsBadgeColor((string) ($event['decision'] ?? '')); ?>
                            <a href="?<?php echo htmlspecialchars(diagnosticsQuery($filters, ['event' => $event['id']])); ?>" style="display:block;text-decoration:none;color:inherit;padding:1rem;border:1px solid <?php echo (($selectedEvent['id'] ?? '') === $event['id']) ? '#93c5fd' : 'var(--border-color)'; ?>;border-radius:12px;background:<?php echo (($selectedEvent['id'] ?? '') === $event['id']) ? '#eff6ff' : '#fff'; ?>;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                    <div>
                                        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:12px;font-weight:600;"><?php echo htmlspecialchars((string) $event['source']); ?></span>
                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $decisionColor; ?>15;color:<?php echo $decisionColor; ?>;font-size:12px;font-weight:600;"><?php echo htmlspecialchars(aiUiDecisionMeta((string) $event['decision'])['label']); ?></span>
                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f8fafc;color:#475569;font-size:12px;font-weight:600;"><?php echo htmlspecialchars((string) ($event['reason_bucket'] ?? 'policy_block')); ?></span>
                                        </div>
                                        <div style="margin-top:.55rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($event['human_summary'] ?? 'Diagnostics event')); ?></div>
                                        <div style="margin-top:.35rem;color:#64748b;font-size:.88rem;">
                                            <?php echo htmlspecialchars((string) ($event['event_type'] ?? 'event')); ?>
                                            <?php if (!empty($event['linked_records']['deal_id'])): ?> · Deal #<?php echo (int) $event['linked_records']['deal_id']; ?><?php endif; ?>
                                            <?php if (!empty($event['linked_records']['invoice_id'])): ?> · Invoice #<?php echo (int) $event['linked_records']['invoice_id']; ?><?php endif; ?>
                                            <?php if (!empty($event['linked_records']['contact_id'])): ?> · Contact #<?php echo (int) $event['linked_records']['contact_id']; ?><?php endif; ?>
                                            <?php if (!empty($event['linked_records']['task_id'])): ?> · Task #<?php echo (int) $event['linked_records']['task_id']; ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;color:#64748b;font-size:.85rem;">
                                        <div><?php echo htmlspecialchars((string) ($event['created_at'] ?? '')); ?></div>
                                        <?php if ($event['confidence_score'] !== null): ?><div style="margin-top:.35rem;">Confidence <?php echo number_format((float) $event['confidence_score'], 2); ?></div><?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <?php if (!$selectedEvent): ?>
                    <p style="margin:0;color:#64748b;">Select an event to inspect details.</p>
                <?php else: ?>
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem;">
                        <div>
                            <h2 style="margin:0;"><?php echo htmlspecialchars((string) $selectedEvent['event_type']); ?></h2>
                            <p style="margin:.35rem 0 0 0;color:#64748b;"><?php echo htmlspecialchars((string) $selectedEvent['human_summary']); ?></p>
                        </div>
                        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:<?php echo diagnosticsBadgeColor((string) $selectedEvent['decision']); ?>15;color:<?php echo diagnosticsBadgeColor((string) $selectedEvent['decision']); ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars((string) $selectedEvent['decision']); ?></span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:1rem;">
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Source</div><div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) $selectedEvent['source']); ?></div></div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Reason bucket</div><div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) $selectedEvent['reason_bucket']); ?></div></div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Mode</div><div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($selectedEvent['mode'] ?? 'n/a')); ?></div></div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Created</div><div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) $selectedEvent['created_at']); ?></div></div>
                    </div>
                    <div style="display:grid;gap:.85rem;">
                        <div>
                            <h3 style="margin:0 0 .45rem 0;">Reason details</h3>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                <?php foreach ((array) ($selectedEvent['reason_codes'] ?? []) as $reasonCode): ?>
                                    <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;color:#334155;"><?php echo htmlspecialchars(aiUiWarningLabel((string) $reasonCode)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 .45rem 0;">Confidence and quality</h3>
                            <div style="color:#475569;font-size:.95rem;line-height:1.7;">
                                Confidence: <?php echo $selectedEvent['confidence_score'] !== null ? htmlspecialchars(number_format((float) $selectedEvent['confidence_score'], 2)) : 'n/a'; ?><br>
                                Context quality: <?php echo $selectedEvent['context_quality_score'] !== null ? htmlspecialchars(aiUiQualityMeta((float) $selectedEvent['context_quality_score'])['label'] . ' (' . number_format((float) $selectedEvent['context_quality_score'], 2) . ')') : 'n/a'; ?><br>
                                Goal relevance: <?php echo $selectedEvent['goal_relevance_score'] !== null ? htmlspecialchars(number_format((float) $selectedEvent['goal_relevance_score'], 2)) : 'n/a'; ?>
                            </div>
                        </div>
                        <?php
                        $selectedPayload = (array) ($selectedEvent['payload'] ?? []);
                        $selectedResultPayload = isset($selectedPayload['result']) && is_array($selectedPayload['result']) ? $selectedPayload['result'] : [];
                        $selectedDraftPayload = isset($selectedResultPayload['draft']) && is_array($selectedResultPayload['draft']) ? $selectedResultPayload['draft'] : [];
                        $selectedOutputPayload = isset($selectedPayload['output']) && is_array($selectedPayload['output']) ? $selectedPayload['output'] : [];
                        $selectedPromptPayload = isset($selectedPayload['prompt']) && is_array($selectedPayload['prompt']) ? $selectedPayload['prompt'] : [];
                        $selectedPromptKey = $selectedPayload['prompt_key']
                            ?? ($selectedDraftPayload['prompt_key'] ?? null)
                            ?? ($selectedOutputPayload['prompt_key'] ?? null)
                            ?? ($selectedPromptPayload['prompt_key'] ?? null);
                        $selectedPromptVersion = $selectedPayload['prompt_version']
                            ?? ($selectedDraftPayload['prompt_version'] ?? null)
                            ?? ($selectedOutputPayload['prompt_version'] ?? null)
                            ?? ($selectedPromptPayload['version'] ?? null);
                        $selectedContextQuality = $selectedPayload['context_bundle_quality']
                            ?? ($selectedDraftPayload['context_bundle_quality'] ?? null)
                            ?? ($selectedOutputPayload['context_bundle_quality'] ?? null)
                            ?? null;
                        ?>
                        <?php if ($selectedPromptKey || $selectedContextQuality): ?>
                            <div>
                                <h3 style="margin:0 0 .45rem 0;">Prompt and context</h3>
                                <div style="color:#475569;font-size:.95rem;line-height:1.7;">
                                    Prompt key: <?php echo htmlspecialchars((string) ($selectedPromptKey ?: 'n/a')); ?><br>
                                    Prompt version: <?php echo htmlspecialchars((string) ($selectedPromptVersion ?? 'n/a')); ?><br>
                                    Bundle warnings: <?php echo htmlspecialchars(implode(', ', array_map('aiUiWarningLabel', (array) (($selectedContextQuality['warnings'] ?? [])))) ?: 'none'); ?>
                                </div>
                                <?php if ($selectedPromptKey): ?>
                                    <div style="margin-top:.5rem;">
                                        <a href="ai_prompt_control.php?surface=<?php echo urlencode((string) ($selectedEvent['source'] === 'coach' ? 'coach' : ($selectedEvent['source'] === 'clarity_chat' ? 'clarity_chat' : 'assistant'))); ?>&prompt_key=<?php echo urlencode((string) $selectedPromptKey); ?>&version=<?php echo (int) ($selectedPromptVersion ?? 0); ?>" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">Open prompt diagnostics</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ((string) ($selectedEvent['source'] ?? '') === 'coach' && $selectedCoachMetadata !== []): ?>
                            <div>
                                <h3 style="margin:0 0 .45rem 0;">Coach feedback metadata</h3>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.65rem;">
                                    <div style="padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;">Feedback signature</div>
                                        <div style="font-weight:800;color:#0f172a;margin-top:.3rem;word-break:break-word;"><?php echo htmlspecialchars((string) ($selectedCoachMetadata['feedback_signature'] ?? 'n/a')); ?></div>
                                    </div>
                                    <div style="padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;">Source type</div>
                                        <div style="font-weight:800;color:#0f172a;margin-top:.3rem;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($selectedCoachMetadata['source_recommendation_type'] ?? 'n/a'))); ?></div>
                                    </div>
                                    <div style="padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;">Source section</div>
                                        <div style="font-weight:800;color:#0f172a;margin-top:.3rem;"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($selectedCoachMetadata['source_section'] ?? 'n/a'))); ?></div>
                                    </div>
                                </div>
                                <div style="margin-top:.65rem;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:700;margin-bottom:.35rem;">Active controls affecting this event</div>
                                    <?php if (!$coachControlsReady): ?>
                                        <p style="margin:0;color:#9a3412;">Controls table unavailable.</p>
                                    <?php elseif ($selectedCoachControlMatches === []): ?>
                                        <p style="margin:0;color:#64748b;">No active AI Coach controls match this feedback signature, source type, or source section.</p>
                                    <?php else: ?>
                                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                            <?php foreach ($selectedCoachControlMatches as $control): ?>
                                                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:5px 9px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12px;font-weight:700;">
                                                    <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['control_type'] ?? 'control'))); ?>
                                                    <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($control['control_scope'] ?? 'scope'))); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3 style="margin:0 0 .45rem 0;">Linked records</h3>
                            <div style="color:#475569;font-size:.95rem;line-height:1.8;">
                                <?php if (!empty($selectedEvent['linked_records']['deal_id'])): ?><a href="deal_view.php?id=<?php echo (int) $selectedEvent['linked_records']['deal_id']; ?>">Deal #<?php echo (int) $selectedEvent['linked_records']['deal_id']; ?></a><br><?php endif; ?>
                                <?php if (!empty($selectedEvent['linked_records']['invoice_id'])): ?><a href="invoice_view.php?id=<?php echo (int) $selectedEvent['linked_records']['invoice_id']; ?>">Invoice #<?php echo (int) $selectedEvent['linked_records']['invoice_id']; ?></a><br><?php endif; ?>
                                <?php if (!empty($selectedEvent['linked_records']['contact_id'])): ?><a href="contact_view.php?id=<?php echo (int) $selectedEvent['linked_records']['contact_id']; ?>">Contact #<?php echo (int) $selectedEvent['linked_records']['contact_id']; ?></a><br><?php endif; ?>
                                <?php if (!empty($selectedEvent['linked_records']['task_id'])): ?><a href="task_view.php?id=<?php echo (int) $selectedEvent['linked_records']['task_id']; ?>">Task #<?php echo (int) $selectedEvent['linked_records']['task_id']; ?></a><br><?php endif; ?>
                            </div>
                        </div>
                        <details>
                            <summary style="cursor:pointer;font-weight:700;">Raw payload</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:320px;overflow:auto;margin-top:.75rem;"><?php echo htmlspecialchars((string) json_encode((array) ($selectedEvent['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card" style="margin-top:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Scheduled Job Health</h2>
                    <p style="margin:0;color:#64748b;">Health status for reconciliation, calibration, workflow scheduling, and runtime-control cleanup jobs.</p>
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-size:12px;font-weight:600;">Healthy <?php echo (int) $jobHealthSummary['healthy']; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:12px;font-weight:600;">Running <?php echo (int) $jobHealthSummary['running']; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;font-size:12px;font-weight:600;">Failed <?php echo (int) $jobHealthSummary['failed']; ?></span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem;padding:6px 10px;border-radius:999px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:12px;font-weight:600;">Stale <?php echo (int) $jobHealthSummary['stale']; ?></span>
                </div>
            </div>
            <?php if (empty($jobHealth)): ?>
                <p style="margin:0;color:#64748b;">No scheduled job health has been reported yet.</p>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="data-table" style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Job</th>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Status</th>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Last run</th>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Last success</th>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Duration</th>
                                <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobHealth as $job): ?>
                                <?php
                                $jobStatus = (string) ($job['derived_status'] ?? $job['status'] ?? 'ok');
                                $jobColor = match ($jobStatus) {
                                    'ok' => '#166534',
                                    'running' => '#9a3412',
                                    'failed' => '#991b1b',
                                    'stale' => '#92400e',
                                    default => '#475569',
                                };
                                ?>
                                <tr>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) ($job['label'] ?? $job['job_key'] ?? 'Job')); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;">
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $jobColor; ?>15;color:<?php echo $jobColor; ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($jobStatus); ?></span>
                                    </td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) ($job['last_run_at'] ?? 'never')); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) ($job['last_success_at'] ?? 'never')); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo $job['last_duration_ms'] !== null ? (int) $job['last_duration_ms'] . ' ms' : 'n/a'; ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) ($job['last_message'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="calibration-section" class="content-card" style="margin-top:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                <div>
                    <h2 style="margin:0 0 .35rem 0;">Confidence Calibration</h2>
                    <p style="margin:0;color:#64748b;">Observed outcome quality, current thresholds, and recent autonomous tuning changes.</p>
                </div>
                <div style="font-size:.9rem;color:#64748b;">
                    <a href="../api/diagnostics/calibration.php" target="_blank" rel="noopener" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">Open calibration API</a>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
                <?php
                $assistantAccepted = 0.0;
                $assistantEdited = 0.0;
                $approvalRejected = 0.0;
                $reversalRate = 0.0;
                $highConfidenceSuccess = 0.0;
                foreach ($calibrationSummary as $surfaceMetrics) {
                    foreach ((array) $surfaceMetrics as $metric) {
                        if ($assistantAccepted === 0.0 && isset($metric['acceptance_rate'])) {
                            $assistantAccepted = (float) $metric['acceptance_rate'];
                        }
                        $assistantEdited = max($assistantEdited, (float) ($metric['edit_rate'] ?? 0));
                        $approvalRejected = max($approvalRejected, (float) ($metric['rejection_rate'] ?? 0));
                        $reversalRate = max($reversalRate, (float) ($metric['reversal_rate'] ?? 0));
                        $highConfidenceSuccess = max($highConfidenceSuccess, (float) ($metric['high_confidence_success_rate'] ?? 0));
                    }
                }
                ?>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Assistant acceptance</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo number_format($assistantAccepted * 100, 1); ?>%</div></div>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Draft edit rate</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo number_format($assistantEdited * 100, 1); ?>%</div></div>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Approval rejection rate</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo number_format($approvalRejected * 100, 1); ?>%</div></div>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Reversal rate</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo number_format($reversalRate * 100, 1); ?>%</div></div>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">High-confidence success</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo number_format($highConfidenceSuccess * 100, 1); ?>%</div></div>
                <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Changes in 7 days</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo count((array) $recentTuningChanges); ?></div></div>
            </div>

            <div style="overflow:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Surface</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Action</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Sample</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Threshold</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Precision</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Reject</th>
                            <th style="text-align:left;padding:.65rem;border-bottom:1px solid var(--border-color);">Reverse</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calibrationSummary as $surface => $surfaceMetrics): ?>
                            <?php foreach ((array) $surfaceMetrics as $actionType => $metric): ?>
                                <tr>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) $surface); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars((string) $actionType); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo (int) ($metric['sample_size'] ?? 0); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo number_format((float) ($metric['current_threshold'] ?? 0), 2); ?></td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo number_format(((float) ($metric['precision_at_current_threshold'] ?? 0)) * 100, 1); ?>%</td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo number_format(((float) ($metric['rejection_rate'] ?? 0)) * 100, 1); ?>%</td>
                                    <td style="padding:.65rem;border-bottom:1px solid #e2e8f0;"><?php echo number_format(((float) ($metric['reversal_rate'] ?? 0)) * 100, 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem;">
                <h3 style="margin:0 0 .75rem 0;">Recent autonomous changes</h3>
                <?php if (empty($recentTuningChanges)): ?>
                    <p style="margin:0;color:#64748b;">No threshold changes have been logged yet.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($recentTuningChanges as $change): ?>
                            <div style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                    <div>
                                        <div style="font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) $change['threshold_key']); ?></div>
                                        <div style="margin-top:.25rem;color:#475569;font-size:.9rem;">
                                            <?php echo htmlspecialchars((string) $change['surface']); ?> / <?php echo htmlspecialchars((string) $change['action_type']); ?>
                                            · <?php echo number_format((float) $change['previous_value'], 2); ?> → <?php echo number_format((float) $change['applied_value'], 2); ?>
                                            · sample <?php echo (int) $change['sample_size']; ?>
                                        </div>
                                        <div style="margin-top:.35rem;color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) ($change['change_reason_summary'] ?? '')); ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:.85rem;color:#64748b;"><?php echo htmlspecialchars((string) $change['created_at']); ?></div>
                                        <form method="POST" style="margin-top:.5rem;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="rollback_threshold">
                                            <input type="hidden" name="tuning_log_id" value="<?php echo (int) $change['id']; ?>">
                                            <button type="submit" class="btn-premium-secondary">Rollback</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
