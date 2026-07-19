<?php
/**
 * Marketing production diagnostics, audit history, and cleanup tools.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Services\MarketingPageQualityMatrix;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.manage', $user)) {
    header('Location: ' . getBasePath() . '/marketing.php');
    exit;
}

$marketing = new Marketing();
$error = '';
$cleanupResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'dry_run');
        if ($action === 'integration_readiness_review') {
            $marketing->runMarketingIntegrationReadinessReview(['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_admin.php?success=integration_readiness');
            exit;
        }
        if ($action === 'refresh_operator_readiness') {
            $marketing->getMarketingOperatorReadiness();
            header('Location: ' . getBasePath() . '/marketing_admin.php?success=operator_readiness');
            exit;
        }
        if ($action === 'clear_dashboard_cache') {
            $marketing->clearMarketingDashboardSummaryCache();
            header('Location: ' . getBasePath() . '/marketing_admin.php?success=dashboard_cache');
            exit;
        }
        if ($action === 'create_report_export') {
            $marketing->createMarketingReportExport((string) ($_POST['report_type'] ?? 'operator'), [
                'report_format' => (string) ($_POST['report_format'] ?? 'json'),
                'period_start' => (string) ($_POST['period_start'] ?? ''),
                'period_end' => (string) ($_POST['period_end'] ?? ''),
                'created_from' => 'marketing_admin',
            ], (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_admin.php?success=report_export');
            exit;
        }
        if ($action === 'create_scheduled_report_draft') {
            $marketing->createScheduledReportDraft([
                'title' => (string) ($_POST['title'] ?? ''),
                'report_type' => (string) ($_POST['report_type'] ?? 'weekly'),
                'cadence' => (string) ($_POST['cadence'] ?? 'weekly'),
                'next_run_at' => (string) ($_POST['next_run_at'] ?? ''),
                'recipients' => (string) ($_POST['recipients'] ?? ''),
                'status' => (string) ($_POST['status'] ?? 'draft'),
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_admin.php?success=scheduled_report');
            exit;
        }
        if ($action === 'media_cleanup' || $action === 'media_cleanup_execute') {
            $cleanupResult = $marketing->runMarketingMediaCleanup((int) ($user['id'] ?? 0), $action !== 'media_cleanup_execute');
        } else {
            $cleanupResult = $marketing->runMarketingCleanup('starter_pack', (int) ($user['id'] ?? 0), $action !== 'cleanup');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$diagnostics = $marketing->getMarketingDiagnostics();
$qaConsole = $marketing->getMarketingQaConsole();
$pageQualityMatrix = MarketingPageQualityMatrix::build(__DIR__);
$mediaDiagnostics = (array) ($diagnostics['media_diagnostics'] ?? []);
$integrationReadiness = $marketing->getMarketingIntegrationReadiness();
$pageSpeedProfile = $marketing->getMarketingPageSpeedProfile();
$dashboardCacheStatus = $marketing->getMarketingDashboardCacheStatus();
$workflowReadiness = $marketing->getMarketingWorkflowReadiness();
$aiReadiness = (array) ($diagnostics['ai_readiness'] ?? []);
$aiContextEvidence = $marketing->getMarketingAiContextEvidence((int) ($user['id'] ?? 0), 8);
$liveWorkerHealth = (array) ($diagnostics['live_worker_health'] ?? []);
$requiredAiPrompts = (array) ($aiReadiness['required_prompt_keys'] ?? []);
$activeAiPrompts = (array) ($aiReadiness['active_prompt_keys'] ?? []);
$missingAiPrompts = (array) ($aiReadiness['missing_prompt_keys'] ?? []);
$requiredOperatingAiPrompts = (array) ($aiReadiness['required_operating_prompt_keys'] ?? []);
$activeOperatingAiPrompts = (array) ($aiReadiness['active_operating_prompt_keys'] ?? []);
$missingOperatingAiPrompts = (array) ($aiReadiness['missing_operating_prompt_keys'] ?? []);
$requiredCreativeAiPrompts = (array) ($aiReadiness['required_creative_prompt_keys'] ?? []);
$activeCreativeAiPrompts = (array) ($aiReadiness['active_creative_prompt_keys'] ?? []);
$missingCreativeAiPrompts = (array) ($aiReadiness['missing_creative_prompt_keys'] ?? []);
$operatorReadiness = (array) ($qaConsole['sections']['operator_readiness'] ?? []);
$executionEvidence = $marketing->getMarketingExecutionEvidenceSummary(null, null, 6);
$reportExports = $marketing->listMarketingReportExports([], 8, 0);
$scheduledReportDrafts = $marketing->listScheduledReportDrafts([], 8, 0);
$auditEvents = $marketing->listMarketingAuditEvents([], 50, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$liveWorkerStatus = (string) ($liveWorkerHealth['status'] ?? 'unknown');
$liveWorkerStatusClass = match ($liveWorkerStatus) {
    'healthy' => 'ready',
    'blocked', 'stale' => 'blocked',
    default => 'warning',
};
$qaStatus = (string) ($qaConsole['status'] ?? 'warning');
$operatorStatus = (string) ($operatorReadiness['status'] ?? 'warning');
$matrixStatus = (string) ($pageQualityMatrix['status'] ?? 'warning');
$pageSpeedStatus = (string) ($pageSpeedProfile['status'] ?? 'attention');
$workflowStatus = (string) ($workflowReadiness['status'] ?? 'attention');
$integrationStatus = (string) ($integrationReadiness['status'] ?? 'attention');
$aiEvidenceStatus = (string) ($aiContextEvidence['status'] ?? 'needs_evidence');
$executionStatus = (string) ($executionEvidence['status'] ?? 'needs_evidence');
$mediaIssueCount = (int) (($mediaDiagnostics['counts']['missing_alt_text'] ?? 0) + ($mediaDiagnostics['counts']['expired_rights'] ?? 0) + ($mediaDiagnostics['counts']['blocked_media'] ?? 0));
$tablesReady = count(array_filter((array) ($diagnostics['tables'] ?? []), static fn($row) => !empty($row['exists'])));
$totalTables = max(1, count((array) ($diagnostics['tables'] ?? [])));
$statusClass = static function (string $status): string {
    return match ($status) {
        'ready', 'healthy', 'fresh', 'complete' => 'ready',
        'blocked', 'missing', 'stale', 'failed' => 'blocked',
        default => 'attention',
    };
};
$adminSummaryTiles = [
    [
        'icon' => 'fa-shield-alt',
        'label' => 'QA',
        'value' => $labelize($qaStatus),
        'tooltip' => 'Marketing QA Console covers schema, RBAC, page guides, media, workflow queues, cleanup, and No-secret diagnostics.',
    ],
    [
        'icon' => 'fa-user-check',
        'label' => 'Operator',
        'value' => $labelize($operatorStatus),
        'tooltip' => 'Operator readiness checks migrations, tables, AI prompts, connector safety, cleanup, and media governance.',
    ],
    [
        'icon' => 'fa-robot',
        'label' => 'Worker',
        'value' => $labelize($liveWorkerStatus),
        'tooltip' => 'Live worker health shows whether the approved handoff worker is fresh, blocked, stale, or not recorded.',
    ],
    [
        'icon' => 'fa-layer-group',
        'label' => 'Pages',
        'value' => $labelize($matrixStatus),
        'tooltip' => 'The page quality matrix checks authenticated Marketing pages for shell, RBAC, and safe output risks.',
    ],
    [
        'icon' => 'fa-photo-video',
        'label' => 'Media',
        'value' => (string) $mediaIssueCount,
        'tooltip' => 'Media issues combine missing alt text, expired rights, and blocked media evidence.',
    ],
];
$adminHealthCards = [
    [
        'icon' => 'fa-clipboard-check',
        'title' => 'Marketing QA Console',
        'status' => $qaStatus,
        'metric' => (int) ($qaConsole['counts']['attention_items'] ?? 0) . ' attention',
        'action' => 'Review QA',
        'href' => '#admin-qa-console',
        'tooltip' => 'Unified release checks for schema, RBAC, page guides, media readiness, workflow queues, cleanup, and No-secret diagnostics.',
    ],
    [
        'icon' => 'fa-bolt',
        'title' => 'Live Worker',
        'status' => $liveWorkerStatusClass,
        'metric' => (int) ($liveWorkerHealth['counts']['queued'] ?? 0) . ' queued',
        'action' => 'Check Worker',
        'href' => '#admin-live-worker',
        'tooltip' => 'Sanitized readiness for the CLI or cron worker that processes approved live email handoffs.',
    ],
    [
        'icon' => 'fa-route',
        'title' => 'Workflow Readiness',
        'status' => $workflowStatus,
        'metric' => (int) ($workflowReadiness['score'] ?? 0) . '% score',
        'action' => 'Open Lanes',
        'href' => '#admin-workflow',
        'tooltip' => 'Setup-to-execution readiness across context, strategy, content, media, landing, distribution, measurement, and governance.',
    ],
    [
        'icon' => 'fa-brain',
        'title' => 'AI Readiness',
        'status' => empty($missingAiPrompts) && empty($missingOperatingAiPrompts) && empty($missingCreativeAiPrompts) ? 'ready' : 'attention',
        'metric' => count($activeAiPrompts) . '/' . max(1, count($requiredAiPrompts)) . ' prompts',
        'action' => 'Review AI',
        'href' => '#admin-ai-readiness',
        'tooltip' => 'Manual-first AI checks for reviewable drafts, saved suggestions, diagnostics, and fallback operation.',
    ],
    [
        'icon' => 'fa-photo-video',
        'title' => 'Media Governance',
        'status' => $mediaIssueCount > 0 ? 'attention' : 'ready',
        'metric' => $mediaIssueCount . ' issues',
        'action' => 'Review Media',
        'href' => '#admin-media',
        'tooltip' => 'Checks for rights, approval, accessibility, thumbnails, orphaned media, and safe URLs.',
    ],
    [
        'icon' => 'fa-plug',
        'title' => 'Integration Readiness',
        'status' => $integrationStatus,
        'metric' => $labelize($integrationStatus),
        'action' => 'Run Review',
        'href' => '#admin-integration',
        'tooltip' => 'Pre-integration checks for manual exports, CRM token pages, UTMs, approvals, and assets.',
    ],
];
$todayActions = array_slice([
    [
        'type' => 'post',
        'label' => 'Refresh checks',
        'action' => 'refresh_operator_readiness',
        'tooltip' => 'Run the manager-only operator readiness check again.',
    ],
    [
        'type' => 'post',
        'label' => 'Run readiness review',
        'action' => 'integration_readiness_review',
        'tooltip' => 'Save manual follow-up suggestions for integration readiness.',
    ],
    [
        'type' => 'post',
        'label' => 'Preview cleanup',
        'action' => 'dry_run',
        'tooltip' => 'Preview starter/demo cleanup before any archive action.',
    ],
    [
        'type' => 'link',
        'label' => 'Prepare export',
        'href' => '#admin-report-exports',
        'tooltip' => 'Open safe report export controls.',
    ],
    [
        'type' => 'link',
        'label' => 'Read recommendations',
        'href' => '#admin-recommendations',
        'tooltip' => 'Jump to current hardening recommendations.',
    ],
], 0, 5);
$pageTitle = 'Marketing Admin Diagnostics - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/marketing-ui.css">

<div class="page-premium marketing-ui-page marketing-admin-page"><div class="container">
    <div class="page-header marketing-page-header">
        <div><h1>Marketing Admin Diagnostics</h1><p>Keep Marketing safe, ready, and clean.</p></div>
        <div class="page-header-actions marketing-page-actions"><a class="btn-premium-secondary" href="marketing_operations.php">Operations</a><a class="btn-premium-secondary" href="marketing.php">Marketing</a></div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Admin action was not applied.</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($cleanupResult !== null): ?><div class="alert alert-success"><?php echo !empty($cleanupResult['dry_run']) ? 'Cleanup preview generated.' : 'Starter/demo cleanup completed.'; ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'integration_readiness'): ?><div class="alert alert-success">Integration readiness review saved manual follow-up suggestions.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'operator_readiness'): ?><div class="alert alert-success">Operator readiness checks refreshed.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'dashboard_cache'): ?><div class="alert alert-success">Marketing dashboard summary cache cleared.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'report_export'): ?><div class="alert alert-success">Marketing operator report export generated.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'scheduled_report'): ?><div class="alert alert-success">Scheduled report draft created.</div><?php endif; ?>

    <section class="marketing-admin-summary" aria-label="Admin health summary">
        <?php foreach ($adminSummaryTiles as $tile): ?>
            <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tile['tooltip']); ?>">
                <i class="fas <?php echo htmlspecialchars($tile['icon']); ?>" aria-hidden="true"></i>
                <div><span><?php echo htmlspecialchars($tile['label']); ?></span><strong><?php echo htmlspecialchars($tile['value']); ?></strong></div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="marketing-admin-layout">
        <main class="marketing-admin-main">
            <section class="content-card marketing-admin-board">
                <div class="premium-section-header"><div><h2>Admin Health Board</h2><p>Manager controls stay available without crowding the first decision.</p></div></div>
                <div class="marketing-admin-health-grid">
                    <?php foreach ($adminHealthCards as $card): ?>
                        <article class="marketing-admin-health-card <?php echo htmlspecialchars($statusClass((string) $card['status'])); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($card['tooltip']); ?>">
                            <div class="marketing-admin-health-visual"><i class="fas <?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i></div>
                            <div class="marketing-admin-health-body">
                                <div class="marketing-admin-health-title"><strong><?php echo htmlspecialchars($card['title']); ?></strong><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass((string) $card['status'])); ?>"><?php echo htmlspecialchars($labelize((string) $card['status'])); ?></span></div>
                                <span><?php echo htmlspecialchars($card['metric']); ?></span>
                            </div>
                            <a class="btn-premium-primary marketing-admin-health-action" href="<?php echo htmlspecialchars($card['href']); ?>"><?php echo htmlspecialchars($card['action']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <details class="marketing-admin-tools" id="admin-diagnostics">
                <summary>More admin diagnostics</summary>
                <div class="marketing-admin-tools-body">
                    <section class="content-card marketing-admin-detail-section" id="admin-qa-console">
                        <div class="premium-section-header"><div><h2>Marketing QA Console</h2><p>Unified release checks, including No-secret diagnostics.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($qaStatus)); ?>"><?php echo htmlspecialchars($labelize($qaStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Attention Items</strong><span>Ready <?php echo (int) ($qaConsole['counts']['ready'] ?? 0); ?>, warnings <?php echo (int) ($qaConsole['counts']['warning'] ?? 0); ?>, blocked <?php echo (int) ($qaConsole['counts']['blocked'] ?? 0); ?></span></div><strong><?php echo (int) ($qaConsole['counts']['attention_items'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-mini-grid">
                            <?php foreach ((array) ($qaConsole['checks'] ?? []) as $check): $checkStatus = (string) ($check['status'] ?? 'warning'); ?>
                                <a class="marketing-admin-mini-card <?php echo htmlspecialchars($statusClass($checkStatus)); ?>" href="<?php echo htmlspecialchars((string) ($check['href'] ?? 'marketing_admin.php')); ?>">
                                    <strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'QA check')); ?></strong>
                                    <span><?php echo htmlspecialchars($labelize($checkStatus)); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <details class="marketing-admin-payload"><summary>Sanitized QA payload</summary><pre class="marketing-admin-code"><?php echo htmlspecialchars(json_encode($qaConsole, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-live-worker">
                        <div class="premium-section-header"><div><h2>Live Worker Health</h2><p>Approved handoff worker status.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($liveWorkerStatusClass); ?>"><?php echo htmlspecialchars($labelize($liveWorkerStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Health Message</strong><span><?php echo htmlspecialchars((string) ($liveWorkerHealth['message'] ?? 'No worker health evidence has been recorded yet.')); ?></span></div><strong><?php echo (int) ($liveWorkerHealth['counts']['queued'] ?? 0); ?> queued</strong></div>
                        <div class="marketing-admin-row"><div><strong>Last Worker Run</strong><span>Run #<?php echo !empty($liveWorkerHealth['last_run_id']) ? (int) $liveWorkerHealth['last_run_id'] : 0; ?>, <?php echo htmlspecialchars($labelize((string) ($liveWorkerHealth['last_run_status'] ?? 'not_recorded'))); ?></span></div><strong><?php echo htmlspecialchars((string) (($liveWorkerHealth['last_run_at'] ?? null) ?: 'Not recorded')); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Worker Schedule</strong><span>Expected every <?php echo (int) ($liveWorkerHealth['schedule']['expected_interval_minutes'] ?? 5); ?> minute(s).</span></div><strong><?php echo htmlspecialchars($labelize((string) ($liveWorkerHealth['schedule']['status'] ?? 'planned'))); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Worker Command</strong><span><code><?php echo htmlspecialchars((string) ($liveWorkerHealth['command'] ?? 'php cli/process_marketing_live_email_handoffs.php all 25')); ?></code></span></div><strong><?php echo htmlspecialchars((string) (($liveWorkerHealth['last_health_check_at'] ?? null) ?: 'Checked now')); ?></strong></div>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-page-matrix">
                        <div class="premium-section-header"><div><h2>Marketing Page Quality Matrix</h2><p>Authenticated Marketing page guardrails.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($matrixStatus)); ?>"><?php echo htmlspecialchars($labelize($matrixStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Internal Pages Covered</strong><span>Public landing and tracking endpoints are intentionally excluded.</span></div><strong><?php echo (int) ($pageQualityMatrix['counts']['total'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Ready / Warning / Blocked</strong><span>Missing guards or secret/debug risks are treated as blocked.</span></div><strong><?php echo (int) ($pageQualityMatrix['counts']['ready'] ?? 0); ?> / <?php echo (int) ($pageQualityMatrix['counts']['warning'] ?? 0); ?> / <?php echo (int) ($pageQualityMatrix['counts']['blocked'] ?? 0); ?></strong></div>
                        <?php $matrixIssues = array_values(array_filter((array) ($pageQualityMatrix['pages'] ?? []), static fn($row) => !empty($row['issues']))); ?>
                        <?php if (empty($matrixIssues)): ?>
                            <div class="empty-state"><p>All authenticated Marketing pages are registered and pass the page quality matrix.</p></div>
                        <?php else: ?>
                            <div class="marketing-admin-mini-grid">
                                <?php foreach (array_slice($matrixIssues, 0, 10) as $row): ?>
                                    <div class="marketing-admin-mini-card <?php echo htmlspecialchars($statusClass((string) ($row['status'] ?? 'warning'))); ?>">
                                        <strong><?php echo htmlspecialchars((string) ($row['page'] ?? 'marketing page')); ?></strong>
                                        <span><?php echo htmlspecialchars((string) (($row['issues'][0]['message'] ?? null) ?: 'Review page quality issue.')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <details class="marketing-admin-payload"><summary>Sanitized page matrix payload</summary><pre class="marketing-admin-code"><?php echo htmlspecialchars(json_encode($pageQualityMatrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-workflow">
                        <div class="premium-section-header"><div><h2>Workflow Reality Check</h2><p>Setup-to-execution readiness.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($workflowStatus)); ?>"><?php echo (int) ($workflowReadiness['score'] ?? 0); ?>% <?php echo htmlspecialchars($labelize($workflowStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Open Workflow Gaps</strong><span>Ready <?php echo (int) ($workflowReadiness['counts']['ready'] ?? 0); ?>, attention <?php echo (int) ($workflowReadiness['counts']['attention'] ?? 0); ?>, blocked <?php echo (int) ($workflowReadiness['counts']['blocked'] ?? 0); ?></span></div><strong><?php echo (int) ($workflowReadiness['counts']['open_gaps'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-lane-grid">
                            <?php foreach ((array) ($workflowReadiness['lanes'] ?? []) as $lane): ?>
                                <?php $laneStatus = (string) ($lane['status'] ?? 'attention'); ?>
                                <a class="marketing-admin-lane <?php echo htmlspecialchars($statusClass($laneStatus)); ?>" href="<?php echo htmlspecialchars((string) ($lane['href'] ?? 'marketing_admin.php')); ?>">
                                    <strong><?php echo htmlspecialchars((string) ($lane['label'] ?? 'Workflow lane')); ?></strong>
                                    <span><?php echo (int) ($lane['score'] ?? 0); ?>% <?php echo htmlspecialchars($labelize($laneStatus)); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <details class="marketing-admin-payload"><summary>Recommended Workflow Actions</summary><ul><?php foreach ((array) ($workflowReadiness['recommended_actions'] ?? []) as $action): ?><li><?php echo htmlspecialchars((string) $action); ?></li><?php endforeach; ?></ul></details>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-speed">
                        <div class="premium-section-header"><div><h2>Marketing Page Speed Guardrails</h2><p>Query budget and cache discipline.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($pageSpeedStatus)); ?>"><?php echo htmlspecialchars($labelize($pageSpeedStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Current Request Query Count</strong><span>Budget <?php echo (int) ($pageSpeedProfile['query_budget'] ?? 0); ?>. SQL text and parameters are intentionally not shown.</span></div><strong><?php echo (int) ($pageSpeedProfile['query_count'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Dashboard Summary Cache</strong><span>Workspace and operator-role scoped.</span></div><strong><?php echo !empty($dashboardCacheStatus['current']['is_fresh']) ? 'Fresh' : 'Empty'; ?></strong></div>
                        <form class="marketing-admin-inline-form" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="clear_dashboard_cache">
                            <button class="btn-premium-secondary" type="submit">Clear Dashboard Cache</button>
                        </form>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-execution">
                        <div class="premium-section-header"><div><h2>Execution Evidence</h2><p>Saved campaign launch handoff snapshots.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($executionStatus)); ?>"><?php echo htmlspecialchars($labelize($executionStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Current Snapshots</strong><span>Campaigns with current evidence: <?php echo (int) ($executionEvidence['counts']['campaigns_with_current_snapshots'] ?? 0); ?></span></div><strong><?php echo (int) ($executionEvidence['counts']['current'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Urgent Actions Captured</strong><span>Total actions captured: <?php echo (int) ($executionEvidence['counts']['actions_total'] ?? 0); ?></span></div><strong><?php echo (int) ($executionEvidence['counts']['urgent_actions'] ?? 0); ?></strong></div>
                        <?php if (empty($executionEvidence['latest_snapshots'])): ?>
                            <div class="empty-state"><p>No execution action snapshots have been saved yet.</p></div>
                        <?php else: foreach ((array) $executionEvidence['latest_snapshots'] as $snapshot): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars((string) ($snapshot['campaign_name'] ?? 'Campaign')); ?></strong><span><?php echo htmlspecialchars($labelize((string) ($snapshot['action_center_status'] ?? 'needs_attention'))); ?>, readiness <?php echo (int) ($snapshot['readiness_score'] ?? 0); ?></span></div><strong><?php echo (int) ($snapshot['urgent_actions'] ?? 0); ?> urgent</strong></div>
                        <?php endforeach; endif; ?>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-readiness">
                        <div class="premium-section-header"><div><h2>Readiness Checks</h2><p>Tables, migrations, and workspace row evidence.</p></div></div>
                        <div class="marketing-admin-row"><div><strong>Latest Migration</strong><span><?php echo htmlspecialchars((string) ($diagnostics['latest_migration']['migration_name'] ?? 'Unknown')); ?></span></div><strong><?php echo $tablesReady; ?>/<?php echo $totalTables; ?></strong></div>
                        <div class="marketing-admin-mini-grid">
                            <?php foreach ((array) ($diagnostics['tables'] ?? []) as $table => $check): ?>
                                <div class="marketing-admin-mini-card <?php echo !empty($check['exists']) ? 'ready' : 'blocked'; ?>">
                                    <strong><?php echo htmlspecialchars((string) $table); ?></strong>
                                    <span><?php echo ($check['workspace_rows'] ?? null) === null ? 'No workspace count' : (int) $check['workspace_rows'] . ' workspace rows'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-operator">
                        <div class="premium-section-header">
                            <div><h2>Operator Readiness</h2><p>Release hardening checks.</p></div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="refresh_operator_readiness">
                                <button class="btn-premium-secondary" type="submit">Refresh Checks</button>
                            </form>
                        </div>
                        <div class="marketing-admin-row"><div><strong>Overall Status</strong><span>Ready <?php echo (int) ($operatorReadiness['counts']['ready'] ?? 0); ?>, warnings <?php echo (int) ($operatorReadiness['counts']['warning'] ?? 0); ?>, blocked <?php echo (int) ($operatorReadiness['counts']['blocked'] ?? 0); ?></span></div><strong><?php echo htmlspecialchars($labelize($operatorStatus)); ?></strong></div>
                        <?php foreach ((array) ($operatorReadiness['checks'] ?? []) as $check): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'Readiness check')); ?></strong><span><?php echo htmlspecialchars((string) ($check['message'] ?? '')); ?></span></div><strong><?php echo htmlspecialchars($labelize((string) ($check['status'] ?? 'warning'))); ?></strong></div>
                        <?php endforeach; ?>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-ai-readiness">
                        <div class="premium-section-header"><div><h2>Marketing AI Readiness</h2><p>Manual-first AI safety checks.</p></div></div>
                        <div class="marketing-admin-row"><div><strong>Runtime Surface</strong><span>Surface: <?php echo htmlspecialchars((string) ($aiReadiness['surface'] ?? 'marketing')); ?></span></div><strong><?php echo !empty($aiReadiness['runtime_surface_available']) ? 'Ready' : 'Missing'; ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Prompt Registry</strong><span><?php echo htmlspecialchars(implode(', ', $activeAiPrompts)); ?></span></div><strong><?php echo count($activeAiPrompts); ?>/<?php echo max(1, count($requiredAiPrompts)); ?> active</strong></div>
                        <div class="marketing-admin-row"><div><strong>Operating Loop Prompts</strong><span><?php echo htmlspecialchars(implode(', ', $activeOperatingAiPrompts)); ?></span></div><strong><?php echo count($activeOperatingAiPrompts); ?>/<?php echo max(1, count($requiredOperatingAiPrompts)); ?> active</strong></div>
                        <div class="marketing-admin-row"><div><strong>Creative Prompt Registry</strong><span><?php echo htmlspecialchars(implode(', ', $activeCreativeAiPrompts)); ?></span></div><strong><?php echo count($activeCreativeAiPrompts); ?>/<?php echo max(1, count($requiredCreativeAiPrompts)); ?> active</strong></div>
                        <div class="marketing-admin-row"><div><strong>Prompt Seed Migration</strong><span>319_seed_marketing_ai_prompts.sql</span></div><strong><?php echo !empty($aiReadiness['prompt_seed_migration_recorded']) ? 'Recorded' : 'Missing'; ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Operating Prompt Seed Migration</strong><span>320_seed_marketing_ai_phase_5_8_prompts.sql</span></div><strong><?php echo !empty($aiReadiness['operating_prompt_seed_migration_recorded']) ? 'Recorded' : 'Missing'; ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Creative Prompt Seed Migration</strong><span>324_create_marketing_ai_creative_runs.sql</span></div><strong><?php echo !empty($aiReadiness['creative_prompt_seed_migration_recorded']) ? 'Recorded' : 'Missing'; ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Deterministic Fallback</strong><span>Live provider required: <?php echo !empty($aiReadiness['live_provider_required']) ? 'Yes' : 'No'; ?></span></div><strong><?php echo !empty($aiReadiness['deterministic_fallback_ready']) ? 'Ready' : 'Review'; ?></strong></div>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-ai-evidence">
                        <div class="premium-section-header"><div><h2>AI Context Evidence</h2><p>Auditable sanitized context snapshots.</p></div><span class="marketing-admin-status <?php echo htmlspecialchars($statusClass($aiEvidenceStatus)); ?>"><?php echo htmlspecialchars($labelize($aiEvidenceStatus)); ?></span></div>
                        <div class="marketing-admin-row"><div><strong>Total Snapshots</strong><span>Distinct context hashes: <?php echo (int) ($aiContextEvidence['counts']['distinct_contexts'] ?? 0); ?></span></div><strong><?php echo (int) ($aiContextEvidence['counts']['total'] ?? 0); ?></strong></div>
                        <div class="marketing-admin-row"><div><strong>Average Context Score</strong><span>Average workspace brain score: <?php echo (int) ($aiContextEvidence['average_workspace_brain_score'] ?? 0); ?>%</span></div><strong><?php echo (int) round(((float) ($aiContextEvidence['average_context_score'] ?? 0)) * 100); ?>%</strong></div>
                        <?php if (empty($aiContextEvidence['latest_snapshots'])): ?>
                            <div class="empty-state"><p>No AI context evidence has been captured yet.</p></div>
                        <?php else: foreach ((array) ($aiContextEvidence['latest_snapshots'] ?? []) as $snapshot): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars($labelize((string) ($snapshot['surface'] ?? 'custom'))); ?> - <?php echo htmlspecialchars((string) ($snapshot['prompt_key'] ?? 'unknown')); ?></strong><span><?php echo htmlspecialchars((string) ($snapshot['created_at'] ?? '')); ?></span></div><strong><?php echo htmlspecialchars(substr((string) ($snapshot['context_hash'] ?? ''), 0, 8)); ?></strong></div>
                        <?php endforeach; endif; ?>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-media">
                        <div class="premium-section-header"><div><h2>Media Governance QA</h2><p>Rights, approval, accessibility, thumbnails, orphaned media, and safe URLs.</p></div></div>
                        <div class="marketing-admin-mini-grid">
                            <?php foreach ((array) ($mediaDiagnostics['counts'] ?? []) as $key => $value): ?>
                                <div class="marketing-admin-mini-card"><strong><?php echo htmlspecialchars($labelize((string) $key)); ?></strong><span><?php echo (int) $value; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        <details class="marketing-admin-payload"><summary>Media Diagnostics</summary><pre class="marketing-admin-code"><?php echo htmlspecialchars(json_encode($mediaDiagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-integration">
                        <div class="premium-section-header">
                            <div><h2>Integration Readiness</h2><p>Manual export and asset checks.</p></div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="integration_readiness_review">
                                <button class="btn-premium-secondary" type="submit">Run Readiness Review</button>
                            </form>
                        </div>
                        <div class="marketing-admin-row"><div><strong>Readiness Status</strong><span>Manual-first: <?php echo !empty($integrationReadiness['manual_first']) ? 'Yes' : 'No'; ?>. Live publish/send: No.</span></div><strong><?php echo htmlspecialchars($labelize($integrationStatus)); ?></strong></div>
                        <?php foreach ((array) ($integrationReadiness['checks'] ?? []) as $checkName => $check): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars($labelize((string) $checkName)); ?></strong><span><?php echo htmlspecialchars(json_encode($check['counts'] ?? [], JSON_UNESCAPED_SLASHES)); ?></span></div><strong><?php echo !empty($check['ready']) ? 'Ready' : 'Attention'; ?></strong></div>
                        <?php endforeach; ?>
                    </section>

                    <section class="content-card marketing-admin-detail-section" id="admin-audit">
                        <div class="premium-section-header"><div><h2>Audit History</h2><p>Recent Marketing audit events.</p></div></div>
                        <?php if (empty($auditEvents)): ?>
                            <div class="empty-state"><p>No marketing audit events recorded yet.</p></div>
                        <?php else: foreach ($auditEvents as $event): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars($labelize((string) $event['event_type'])); ?></strong><span><?php echo htmlspecialchars((string) $event['summary']); ?></span></div><strong><?php echo htmlspecialchars((string) ($event['created_at'] ?? '')); ?></strong></div>
                        <?php endforeach; endif; ?>
                    </section>
                </div>
            </details>

            <details class="marketing-admin-tools" id="admin-actions">
                <summary>Admin actions and reports</summary>
                <div class="marketing-admin-tools-body">
                    <section class="content-card marketing-admin-form-panel" id="admin-cleanup">
                        <div class="premium-section-header"><div><h2>Cleanup Tools</h2><p>Manager-only controls for demo/starter data.</p></div></div>
                        <p class="marketing-admin-note">Starter/demo cleanup archives supported records and marks demo-only context as archived metadata. It does not remove real user records.</p>
                        <form class="marketing-admin-button-row warning" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <button class="btn-premium-secondary" type="submit" name="action" value="dry_run">Preview Cleanup</button>
                            <button class="btn-premium-primary marketing-admin-danger" type="submit" name="action" value="cleanup">Archive Starter Data</button>
                            <span class="badge badge-default">No hard delete</span>
                        </form>
                        <details class="marketing-admin-payload"><summary>Starter Pack Scope</summary><pre class="marketing-admin-code"><?php echo htmlspecialchars(json_encode($diagnostics['starter_pack'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                        <form class="marketing-admin-button-row media" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <button class="btn-premium-secondary" type="submit" name="action" value="media_cleanup">Preview Media Cleanup</button>
                            <button class="btn-premium-primary marketing-admin-danger" type="submit" name="action" value="media_cleanup_execute">Archive Unused Media</button>
                        </form>
                        <?php if ($cleanupResult !== null): ?><details class="marketing-admin-payload" open><summary>Last Cleanup Result</summary><pre class="marketing-admin-code"><?php echo htmlspecialchars(json_encode($cleanupResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details><?php endif; ?>
                    </section>

                    <section class="content-card marketing-admin-form-panel" id="admin-report-exports">
                        <div class="premium-section-header"><div><h2>Report Exports</h2><p>Safe JSON report snapshots for operator review.</p></div></div>
                        <form class="marketing-admin-form" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create_report_export">
                            <label>Report type
                                <select name="report_type" class="form-control">
                                    <?php foreach (Marketing::REPORT_EXPORT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>Format
                                <select name="report_format" class="form-control">
                                    <?php foreach (Marketing::REPORT_EXPORT_FORMATS as $format): ?><option value="<?php echo htmlspecialchars($format); ?>"><?php echo htmlspecialchars($labelize($format)); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <div class="marketing-admin-date-range">
                                <label>Start <input class="form-control" type="date" name="period_start"></label>
                                <label>End <input class="form-control" type="date" name="period_end"></label>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Generate Report Export</button>
                        </form>
                        <?php if (empty($reportExports)): ?>
                            <div class="empty-state"><p>No report exports generated yet.</p></div>
                        <?php else: foreach ($reportExports as $export): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars($labelize((string) $export['report_type'])); ?></strong><span><?php echo htmlspecialchars((string) ($export['created_at'] ?? '')); ?>, <?php echo htmlspecialchars($labelize((string) $export['report_format'])); ?></span></div><strong><?php echo htmlspecialchars($labelize((string) $export['status'])); ?></strong></div>
                        <?php endforeach; endif; ?>
                    </section>

                    <section class="content-card marketing-admin-form-panel" id="admin-scheduled-reports">
                        <div class="premium-section-header"><div><h2>Scheduled Report Drafts</h2><p>Draft-only cadence planning.</p></div></div>
                        <form class="marketing-admin-form" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create_scheduled_report_draft">
                            <label>Title <input class="form-control" name="title" value="Weekly Marketing Operator Report"></label>
                            <div class="marketing-admin-date-range">
                                <label>Type
                                    <select name="report_type" class="form-control">
                                        <?php foreach (Marketing::REPORT_EXPORT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Cadence
                                    <select name="cadence" class="form-control">
                                        <?php foreach (Marketing::SCHEDULED_REPORT_CADENCES as $cadence): ?><option value="<?php echo htmlspecialchars($cadence); ?>"><?php echo htmlspecialchars($labelize($cadence)); ?></option><?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <label>Next run <input class="form-control" type="datetime-local" name="next_run_at"></label>
                            <label>Recipients <textarea class="form-control" name="recipients" rows="2" placeholder="ops@example.com, marketing@example.com"></textarea></label>
                            <button class="btn-premium-secondary" type="submit">Create Draft Schedule</button>
                        </form>
                        <?php if (empty($scheduledReportDrafts)): ?>
                            <div class="empty-state"><p>No scheduled report drafts yet.</p></div>
                        <?php else: foreach ($scheduledReportDrafts as $draft): ?>
                            <div class="marketing-admin-row"><div><strong><?php echo htmlspecialchars((string) $draft['title']); ?></strong><span><?php echo htmlspecialchars($labelize((string) $draft['cadence'])); ?>, next <?php echo htmlspecialchars((string) ($draft['next_run_at'] ?? 'not set')); ?></span></div><strong><?php echo htmlspecialchars($labelize((string) $draft['status'])); ?></strong></div>
                        <?php endforeach; endif; ?>
                    </section>

                    <section class="content-card marketing-admin-form-panel" id="admin-recommendations">
                        <div class="premium-section-header"><div><h2>Recommendations</h2><p>Current hardening suggestions.</p></div></div>
                        <?php if (empty($diagnostics['recommendations'])): ?>
                            <div class="empty-state"><p>No hardening recommendations at this time.</p></div>
                        <?php else: ?>
                            <div class="marketing-admin-recommendation-list">
                                <?php foreach ((array) $diagnostics['recommendations'] as $recommendation): ?><span><?php echo htmlspecialchars((string) $recommendation); ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </main>

        <aside class="marketing-admin-side" aria-label="Admin next actions">
            <section class="content-card marketing-admin-today">
                <div class="premium-section-header"><div><h2>Today</h2><p>Pick one admin action.</p></div></div>
                <div class="marketing-admin-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <?php if ($action['type'] === 'post'): ?>
                            <form class="marketing-admin-today-action" method="POST" data-tooltip="<?php echo htmlspecialchars($action['tooltip']); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <button class="btn-premium-secondary" type="submit" name="action" value="<?php echo htmlspecialchars($action['action']); ?>"><?php echo htmlspecialchars($action['label']); ?></button>
                            </form>
                        <?php else: ?>
                            <a class="marketing-admin-today-action" href="<?php echo htmlspecialchars($action['href']); ?>" data-tooltip="<?php echo htmlspecialchars($action['tooltip']); ?>"><strong><?php echo htmlspecialchars($action['label']); ?></strong><span>Open</span></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="content-card marketing-admin-footprint">
                <div class="premium-section-header"><div><h2>System Footprint</h2><p>Compact evidence only.</p></div></div>
                <div class="marketing-admin-mini-grid">
                    <div class="marketing-admin-mini-card"><strong>Marketing Tables</strong><span><?php echo $tablesReady; ?>/<?php echo $totalTables; ?> ready</span></div>
                    <div class="marketing-admin-mini-card"><strong>Hardening Indexes</strong><span><?php echo count(array_filter((array) ($diagnostics['indexes'] ?? []))); ?></span></div>
                    <div class="marketing-admin-mini-card"><strong>Starter Records</strong><span><?php echo (int) ($diagnostics['starter_pack']['total'] ?? 0); ?></span></div>
                    <div class="marketing-admin-mini-card"><strong>Audit Events</strong><span><?php echo (int) ($diagnostics['audit_events'] ?? 0); ?></span></div>
                    <div class="marketing-admin-mini-card"><strong>Action Snapshots</strong><span><?php echo (int) ($executionEvidence['counts']['current'] ?? 0); ?></span></div>
                    <div class="marketing-admin-mini-card"><strong>AI Context Evidence</strong><span><?php echo (int) ($aiContextEvidence['counts']['total'] ?? 0); ?></span></div>
                </div>
            </section>
        </aside>
    </div>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
