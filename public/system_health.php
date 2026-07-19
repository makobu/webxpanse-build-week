<?php
/**
 * System readiness diagnostics.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\AutomationDetectorExecutionService;
use CRM\Services\AutomationReviewQueueService;
use CRM\Services\SystemReadinessService;
use CRM\Session;

$config = require __DIR__ . '/../config/database.php';
$service = new SystemReadinessService($config, dirname(__DIR__));
$readiness = $service->check();
$checks = (array) ($readiness['checks'] ?? []);
$databaseStatus = 'unknown';
foreach ($checks as $check) {
    if (($check['key'] ?? '') === 'database') {
        $databaseStatus = (string) ($check['status'] ?? 'unknown');
        break;
    }
}
$databaseAvailable = $databaseStatus === 'ok';
$user = null;
$canManageAutomationReview = false;
$automationReviewDashboard = [];
$automationDryRun = null;
$automationMessage = null;
$automationError = null;
$csrfToken = '';

$isLocalRequest = static function (): bool {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $localValues = ['127.0.0.1', '::1', 'localhost'];

    foreach ([$remoteAddr, $serverName, $host] as $value) {
        $value = strtolower((string) $value);
        if ($value === '') {
            continue;
        }
        foreach ($localValues as $local) {
            if ($value === $local || str_starts_with($value, $local . ':')) {
                return true;
            }
        }
    }

    return false;
};

if ($databaseAvailable) {
    Database::init($config);
    Session::start();
    if (!Auth::check()) {
        header('Location: ' . publicUrl('login.php'));
        exit;
    }

    $user = Auth::user();
    $canViewSystemHealth = Authorization::isSuperAdmin($user)
        || Authorization::canAny(['settings.monitoring', 'admin.users.manage', 'admin.roles.manage'], $user)
        || (($user['role'] ?? '') === 'admin');
    $canManageAutomationReview = Authorization::isSuperAdmin($user);

    if (!$canViewSystemHealth) {
        http_response_code(403);
        echo 'Access denied: Insufficient permissions';
        exit;
    }

    $csrfToken = Security::getCsrfToken();
    $automationReviewQueue = new AutomationReviewQueueService();
    $automationDetectorRunner = new AutomationDetectorExecutionService();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['automation_review_action'])) {
        if (!$canManageAutomationReview) {
            $automationError = 'Super Admin access is required for automation review actions.';
        } elseif (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            $automationError = 'Invalid security token.';
        } else {
            try {
                $automationAction = (string) ($_POST['automation_review_action'] ?? '');
                if ($automationAction === 'run_detectors') {
                    $result = $automationDetectorRunner->runProductionReadinessDetectors((int) ($user['id'] ?? 0));
                    $escalation = $automationReviewQueue->applyEscalationPolicy((int) ($user['id'] ?? 0));
                    $notifications = $automationReviewQueue->dispatchEscalationNotifications((int) ($user['id'] ?? 0));
                    $summaryResult = (array) ($result['summary'] ?? []);
                    $automationMessage = 'Detector evidence recorded: ' . (int) ($summaryResult['recorded'] ?? 0) . ' run(s), ' . (int) ($summaryResult['findings'] ?? 0) . ' finding(s). Escalated ' . (int) ($escalation['newly_escalated'] ?? 0) . ' finding(s). Sent ' . (int) ($notifications['notifications_created'] ?? 0) . ' notification(s).';
                } elseif ($automationAction === 'apply_escalation_policy') {
                    $escalation = $automationReviewQueue->applyEscalationPolicy((int) ($user['id'] ?? 0));
                    $notifications = $automationReviewQueue->dispatchEscalationNotifications((int) ($user['id'] ?? 0));
                    $automationMessage = 'Escalation policy applied: ' . (int) ($escalation['newly_escalated'] ?? 0) . ' new, ' . (int) ($escalation['already_escalated'] ?? 0) . ' already escalated. Sent ' . (int) ($notifications['notifications_created'] ?? 0) . ' notification(s).';
                } elseif ($automationAction === 'dispatch_escalation_notifications') {
                    $notifications = $automationReviewQueue->dispatchEscalationNotifications((int) ($user['id'] ?? 0));
                    $automationMessage = 'Escalation notifications dispatched: ' . (int) ($notifications['notifications_created'] ?? 0) . ' notification(s) for ' . (int) ($notifications['notified_runs'] ?? 0) . ' run(s).';
                } elseif ($automationAction === 'review_run') {
                    $automationReviewQueue->reviewRun(
                        (int) ($_POST['run_id'] ?? 0),
                        (string) ($_POST['decision'] ?? ''),
                        (int) ($user['id'] ?? 0),
                        (string) ($_POST['note'] ?? '')
                    );
                    $automationMessage = 'Automation review updated.';
                } else {
                    $automationError = 'Unsupported automation review action.';
                }
            } catch (\Throwable $e) {
                $automationError = $e->getMessage();
            }
        }
    }

    if ($canManageAutomationReview && ($_GET['automation_dry_run'] ?? '') === '1') {
        $automationDryRun = $automationDetectorRunner->evaluateProductionReadinessDetectors();
    }

    if ($canManageAutomationReview) {
        $automationReviewDashboard = $automationReviewQueue->dashboard(12);
    }
} elseif (!$isLocalRequest()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'System health diagnostics are only available locally while the database is unavailable.';
    exit;
}

$statusClass = static function (string $status): string {
    return match ($status) {
        'ok' => 'is-ok',
        'warning' => 'is-warning',
        'critical' => 'is-critical',
        default => 'is-unknown',
    };
};
$statusLabel = static fn(string $status): string => strtoupper($status !== '' ? $status : 'unknown');
$safeMetadata = static function (array $metadata): string {
    if ($metadata === []) {
        return '';
    }

    $filtered = [];
    foreach ($metadata as $key => $value) {
        if (stripos((string) $key, 'pass') !== false || stripos((string) $key, 'token') !== false) {
            continue;
        }
        $filtered[$key] = $value;
    }

    if ($filtered === []) {
        return '';
    }

    return json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
};

$overallStatus = (string) ($readiness['status'] ?? 'unknown');
$summary = (array) ($readiness['summary'] ?? []);
$configSummary = $service->safeConfigSummary();
$preflightCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'production_preflight') {
        $preflightCheck = $check;
        break;
    }
}
$preflightMetadata = is_array($preflightCheck['metadata'] ?? null) ? $preflightCheck['metadata'] : [];
$preflightBlockers = is_array($preflightMetadata['blockers'] ?? null) ? $preflightMetadata['blockers'] : [];
$preflightWarnings = is_array($preflightMetadata['warnings'] ?? null) ? $preflightMetadata['warnings'] : [];
$preflightManualConfirmations = is_array($preflightMetadata['manual_confirmations'] ?? null) ? $preflightMetadata['manual_confirmations'] : [];
$systemContextCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'system_context_registry') {
        $systemContextCheck = $check;
        break;
    }
}
$systemContextMetadata = is_array($systemContextCheck['metadata'] ?? null) ? $systemContextCheck['metadata'] : [];
$systemContextSummary = is_array($systemContextMetadata['summary'] ?? null) ? $systemContextMetadata['summary'] : [];
$systemContextFindings = is_array($systemContextMetadata['recent_findings'] ?? null) ? $systemContextMetadata['recent_findings'] : [];
$templateValidationCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'template_validation') {
        $templateValidationCheck = $check;
        break;
    }
}
$templateValidationMetadata = is_array($templateValidationCheck['metadata'] ?? null) ? $templateValidationCheck['metadata'] : [];
$templateValidationSummary = is_array($templateValidationMetadata['summary'] ?? null) ? $templateValidationMetadata['summary'] : [];
$templateValidationFindings = is_array($templateValidationMetadata['recent_findings'] ?? null) ? $templateValidationMetadata['recent_findings'] : [];
$securityHardeningCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'security_hardening') {
        $securityHardeningCheck = $check;
        break;
    }
}
$securityHardeningMetadata = is_array($securityHardeningCheck['metadata'] ?? null) ? $securityHardeningCheck['metadata'] : [];
$securityHardeningSummary = is_array($securityHardeningMetadata['summary'] ?? null) ? $securityHardeningMetadata['summary'] : [];
$securityHardeningFindings = is_array($securityHardeningMetadata['recent_findings'] ?? null) ? $securityHardeningMetadata['recent_findings'] : [];
$integrationReadinessCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'integration_readiness') {
        $integrationReadinessCheck = $check;
        break;
    }
}
$integrationReadinessMetadata = is_array($integrationReadinessCheck['metadata'] ?? null) ? $integrationReadinessCheck['metadata'] : [];
$integrationReadinessSummary = is_array($integrationReadinessMetadata['summary'] ?? null) ? $integrationReadinessMetadata['summary'] : [];
$integrationReadinessDomains = is_array($integrationReadinessMetadata['domains'] ?? null) ? $integrationReadinessMetadata['domains'] : [];
$integrationReadinessFindings = is_array($integrationReadinessMetadata['recent_findings'] ?? null) ? $integrationReadinessMetadata['recent_findings'] : [];
$demoQuarantineCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'demo_quarantine') {
        $demoQuarantineCheck = $check;
        break;
    }
}
$demoQuarantineMetadata = is_array($demoQuarantineCheck['metadata'] ?? null) ? $demoQuarantineCheck['metadata'] : [];
$demoQuarantineSummary = is_array($demoQuarantineMetadata['summary'] ?? null) ? $demoQuarantineMetadata['summary'] : [];
$demoQuarantineFindings = is_array($demoQuarantineMetadata['recent_findings'] ?? null) ? $demoQuarantineMetadata['recent_findings'] : [];
$backupRestoreCheck = [];
foreach ($checks as $check) {
    if (is_array($check) && ($check['key'] ?? '') === 'backup_restore_readiness') {
        $backupRestoreCheck = $check;
        break;
    }
}
$backupRestoreMetadata = is_array($backupRestoreCheck['metadata'] ?? null) ? $backupRestoreCheck['metadata'] : [];
$backupRestoreSummary = is_array($backupRestoreMetadata['summary'] ?? null) ? $backupRestoreMetadata['summary'] : [];
$backupRestoreExpectations = is_array($backupRestoreMetadata['expectations'] ?? null) ? $backupRestoreMetadata['expectations'] : [];
$backupRestoreFindings = is_array($backupRestoreMetadata['recent_findings'] ?? null) ? $backupRestoreMetadata['recent_findings'] : [];
$automationReviewSummary = is_array($automationReviewDashboard['summary'] ?? null) ? $automationReviewDashboard['summary'] : [];
$automationOpenFindings = is_array($automationReviewDashboard['open_findings'] ?? null) ? $automationReviewDashboard['open_findings'] : [];
$automationCriticalFindings = is_array($automationReviewDashboard['critical_findings'] ?? null) ? $automationReviewDashboard['critical_findings'] : [];
$automationEscalatedFindings = is_array($automationReviewDashboard['escalated_findings'] ?? null) ? $automationReviewDashboard['escalated_findings'] : [];
$automationPreparedDigests = is_array($automationReviewDashboard['prepared_digests'] ?? null) ? $automationReviewDashboard['prepared_digests'] : [];
$automationSkippedRuns = is_array($automationReviewDashboard['skipped_runs'] ?? null) ? $automationReviewDashboard['skipped_runs'] : [];
$automationRecentRuns = is_array($automationReviewDashboard['recent_runs'] ?? null) ? $automationReviewDashboard['recent_runs'] : [];
$automationDryRunSummary = is_array($automationDryRun['summary'] ?? null) ? $automationDryRun['summary'] : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Health - <?php echo htmlspecialchars(brandProductName()); ?></title>
    <style>
        :root { --bg:#f8fafc; --panel:#fff; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --ok:#047857; --warn:#a16207; --critical:#b91c1c; --unknown:#475569; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial, Helvetica, sans-serif; color:var(--ink); background:var(--bg); line-height:1.5; }
        .wrap { max-width:1120px; margin:0 auto; padding:36px 20px 56px; }
        .header { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; margin-bottom:18px; }
        h1 { margin:0 0 6px; font-size:32px; letter-spacing:0; }
        p { margin:0; color:var(--muted); }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:22px; box-shadow:0 12px 28px rgba(15,23,42,.06); margin-top:16px; }
        .metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:16px; }
        .metric { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:16px; }
        .metric span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .metric strong { display:block; margin-top:6px; font-size:24px; }
        .status { display:inline-flex; align-items:center; justify-content:center; min-width:90px; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:700; }
        .is-ok { background:#dcfce7; color:var(--ok); }
        .is-warning { background:#fef3c7; color:var(--warn); }
        .is-critical { background:#fee2e2; color:var(--critical); }
        .is-unknown { background:#e2e8f0; color:var(--unknown); }
        table { width:100%; border-collapse:collapse; margin-top:12px; font-size:14px; }
        th, td { text-align:left; border-bottom:1px solid var(--line); padding:13px 10px; vertical-align:top; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        pre { white-space:pre-wrap; word-break:break-word; background:#f8fafc; border:1px solid var(--line); border-radius:6px; padding:10px; margin:8px 0 0; font-size:12px; color:#334155; }
        .config { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
        .config div { border:1px solid var(--line); background:#f8fafc; border-radius:8px; padding:12px; min-width:0; }
        .config span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .config strong { display:block; overflow-wrap:anywhere; margin-top:4px; }
        .preflight-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:14px; }
        .preflight-box { border:1px solid var(--line); background:#f8fafc; border-radius:8px; padding:14px; min-width:0; }
        .preflight-box span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .preflight-box strong { display:block; margin-top:4px; font-size:22px; }
        .preflight-list { margin:12px 0 0; padding-left:18px; color:#334155; }
        .preflight-list li { margin:4px 0; overflow-wrap:anywhere; }
        .notice { border-radius:8px; padding:12px 14px; margin-top:12px; font-weight:700; }
        .notice.success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .notice.error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .review-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .review-actions input[type="text"] { min-width:180px; max-width:260px; padding:8px; border:1px solid var(--line); border-radius:6px; }
        .button.small { padding:8px 10px; font-size:12px; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .button { display:inline-block; text-decoration:none; border-radius:6px; padding:10px 14px; font-weight:700; border:1px solid #1d4ed8; color:#1d4ed8; background:#fff; }
        .button.primary { color:#fff; background:#1d4ed8; }
        @media (max-width:900px) { .metrics, .config, .preflight-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .header { flex-direction:column; } }
        @media (max-width:620px) { .metrics, .config, .preflight-grid { grid-template-columns:1fr; } table { display:block; overflow-x:auto; } }
    </style>
</head>
<body>
    <main class="wrap">
        <section class="header" aria-labelledby="system-health-title">
            <div>
                <h1 id="system-health-title">System Health</h1>
                <p>Setup readiness for database, migrations, writable paths, Marketing runtime, and production preflight.</p>
            </div>
            <span class="status <?php echo htmlspecialchars($statusClass($overallStatus)); ?>"><?php echo htmlspecialchars($statusLabel($overallStatus)); ?></span>
        </section>

        <section class="metrics" aria-label="Readiness summary">
            <div class="metric"><span>Healthy</span><strong><?php echo number_format((int) ($summary['ok'] ?? 0)); ?></strong></div>
            <div class="metric"><span>Warnings</span><strong><?php echo number_format((int) ($summary['warning'] ?? 0)); ?></strong></div>
            <div class="metric"><span>Critical</span><strong><?php echo number_format((int) ($summary['critical'] ?? 0)); ?></strong></div>
            <div class="metric"><span>Unknown</span><strong><?php echo number_format((int) ($summary['unknown'] ?? 0)); ?></strong></div>
        </section>

        <?php if ($preflightCheck !== []): ?>
            <?php $preflightStatus = (string) ($preflightCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="preflight-title">
                <h2 id="preflight-title">Production Preflight</h2>
                <p><?php echo htmlspecialchars((string) ($preflightCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($preflightStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Blockers</span><strong><?php echo number_format(count($preflightBlockers)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format(count($preflightWarnings)); ?></strong></div>
                </div>
                <?php if ($preflightBlockers !== []): ?>
                    <h3>Blockers</h3>
                    <ul class="preflight-list">
                        <?php foreach ($preflightBlockers as $blocker): ?>
                            <li><?php echo htmlspecialchars((string) $blocker); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($preflightWarnings !== []): ?>
                    <h3>Warnings</h3>
                    <ul class="preflight-list">
                        <?php foreach ($preflightWarnings as $warning): ?>
                            <li><?php echo htmlspecialchars((string) $warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($preflightManualConfirmations !== []): ?>
                    <h3>Manual Confirmations</h3>
                    <ul class="preflight-list">
                        <?php foreach ($preflightManualConfirmations as $confirmation): ?>
                            <li><?php echo htmlspecialchars((string) $confirmation); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($systemContextCheck !== []): ?>
            <?php $systemContextStatus = (string) ($systemContextCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="system-context-title">
                <h2 id="system-context-title">System Context Registry</h2>
                <p><?php echo htmlspecialchars((string) ($systemContextCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($systemContextStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Required Sections</span><strong><?php echo number_format((int) ($systemContextSummary['required_sections_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Platform Sections</span><strong><?php echo number_format((int) ($systemContextSummary['required_platform_ops_sections_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Policy Scopes</span><strong><?php echo number_format((int) ($systemContextSummary['required_owner_policy_scopes_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Dummy Hits</span><strong><?php echo number_format((int) ($systemContextSummary['dummy_language_hits'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical</span><strong><?php echo number_format((int) ($systemContextSummary['critical'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format((int) ($systemContextSummary['warning'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($systemContextMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <h3>Recent Findings</h3>
                <?php if ($systemContextFindings === []): ?>
                    <p>No system context registry findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Target</th><th>Severity</th><th>Rule</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($systemContextFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $target = (string) (($finding['target'] ?? '') ?: 'system_context_registry');
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($target); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['recommendation'] ?? 'Review system context before live upload.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($templateValidationCheck !== []): ?>
            <?php $templateValidationStatus = (string) ($templateValidationCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="template-validation-title">
                <h2 id="template-validation-title">Template Validation</h2>
                <p><?php echo htmlspecialchars((string) ($templateValidationCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($templateValidationStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Email Templates</span><strong><?php echo number_format((int) ($templateValidationSummary['email_templates_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Workflow Templates</span><strong><?php echo number_format((int) ($templateValidationSummary['workflow_templates_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical</span><strong><?php echo number_format((int) ($templateValidationSummary['critical'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format((int) ($templateValidationSummary['warning'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($templateValidationMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <h3>Recent Findings</h3>
                <?php if ($templateValidationFindings === []): ?>
                    <p>No template validation findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Template</th><th>Severity</th><th>Rule</th><th>Finding</th></tr></thead>
                        <tbody>
                        <?php foreach ($templateValidationFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $templateName = (string) (($finding['template_key'] ?? '') ?: ($finding['slug'] ?? '') ?: ($finding['name'] ?? 'Template'));
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($templateName); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['template_type'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($securityHardeningCheck !== []): ?>
            <?php $securityHardeningStatus = (string) ($securityHardeningCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="security-hardening-title">
                <h2 id="security-hardening-title">Security Hardening</h2>
                <p><?php echo htmlspecialchars((string) ($securityHardeningCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($securityHardeningStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Super Admins</span><strong><?php echo number_format((int) ($securityHardeningSummary['superadmin_users'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Permissions</span><strong><?php echo number_format((int) ($securityHardeningSummary['permissions_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Public PHP</span><strong><?php echo number_format((int) ($securityHardeningSummary['public_php_files_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>API PHP</span><strong><?php echo number_format((int) ($securityHardeningSummary['api_php_files_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Setup/Test</span><strong><?php echo number_format((int) ($securityHardeningSummary['setup_script_candidates'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical</span><strong><?php echo number_format((int) ($securityHardeningSummary['critical'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format((int) ($securityHardeningSummary['warning'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($securityHardeningMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <h3>Recent Findings</h3>
                <?php if ($securityHardeningFindings === []): ?>
                    <p>No security hardening findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Target</th><th>Severity</th><th>Rule</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($securityHardeningFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $target = (string) (($finding['file'] ?? '') ?: ($finding['role_slug'] ?? '') ?: ($finding['permission_key'] ?? '') ?: ($finding['table'] ?? 'security'));
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($target); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['recommendation'] ?? 'Review before live upload.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($integrationReadinessCheck !== []): ?>
            <?php $integrationReadinessStatus = (string) ($integrationReadinessCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="integration-readiness-title">
                <h2 id="integration-readiness-title">Integration Readiness</h2>
                <p><?php echo htmlspecialchars((string) ($integrationReadinessCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($integrationReadinessStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Domains</span><strong><?php echo number_format((int) ($integrationReadinessSummary['domains_checked'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Ready</span><strong><?php echo number_format((int) ($integrationReadinessSummary['ready'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical</span><strong><?php echo number_format((int) ($integrationReadinessSummary['critical'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format((int) ($integrationReadinessSummary['warning'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($integrationReadinessMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <h3>Domains</h3>
                <table>
                    <thead><tr><th>Domain</th><th>Status</th><th>Findings</th><th>Supported</th></tr></thead>
                    <tbody>
                    <?php foreach ($integrationReadinessDomains as $domain): ?>
                        <?php
                            $domain = is_array($domain) ? $domain : [];
                            $domainStatus = (string) ($domain['status'] ?? 'unknown');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string) ($domain['label'] ?? $domain['key'] ?? 'Integration')); ?></strong></td>
                            <td><span class="status <?php echo htmlspecialchars($statusClass($domainStatus)); ?>"><?php echo htmlspecialchars($statusLabel($domainStatus)); ?></span></td>
                            <td><?php echo number_format(count((array) ($domain['findings'] ?? []))); ?></td>
                            <td><?php echo !empty($domain['supported']) ? 'Yes' : 'No'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3>Recent Findings</h3>
                <?php if ($integrationReadinessFindings === []): ?>
                    <p>No integration readiness findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Target</th><th>Severity</th><th>Rule</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($integrationReadinessFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $target = (string) (($finding['target'] ?? '') ?: ($finding['domain'] ?? 'integration'));
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($target); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['recommendation'] ?? 'Review integration readiness before live upload.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($demoQuarantineCheck !== []): ?>
            <?php $demoQuarantineStatus = (string) ($demoQuarantineCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="demo-quarantine-title">
                <h2 id="demo-quarantine-title">Demo Quarantine</h2>
                <p><?php echo htmlspecialchars((string) ($demoQuarantineCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($demoQuarantineStatus)); ?></strong></div>
                    <div class="preflight-box"><span>Default Demo Scope</span><strong><?php echo number_format((int) ($demoQuarantineSummary['default_demo_scoped_rows'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Default Markers</span><strong><?php echo number_format((int) ($demoQuarantineSummary['default_demo_marker_rows'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Presentation Rows</span><strong><?php echo number_format((int) ($demoQuarantineSummary['default_presentation_rows'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Protected Rows</span><strong><?php echo number_format((int) ($demoQuarantineSummary['protected_demo_scoped_rows'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical</span><strong><?php echo number_format((int) ($demoQuarantineSummary['critical'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Warnings</span><strong><?php echo number_format((int) ($demoQuarantineSummary['warning'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($demoQuarantineMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <h3>Recent Findings</h3>
                <?php if ($demoQuarantineFindings === []): ?>
                    <p>No demo quarantine findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Target</th><th>Severity</th><th>Rule</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($demoQuarantineFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $target = (string) (($finding['target'] ?? '') ?: ($finding['table'] ?? 'demo_quarantine'));
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($target); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['recommendation'] ?? 'Review demo quarantine before live upload.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($backupRestoreCheck !== []): ?>
            <?php $backupRestoreStatus = (string) ($backupRestoreCheck['status'] ?? 'unknown'); ?>
            <section class="panel" aria-labelledby="backup-restore-title">
                <h2 id="backup-restore-title">Backup &amp; Restore</h2>
                <p><?php echo htmlspecialchars((string) ($backupRestoreCheck['message'] ?? '')); ?></p>
                <div class="preflight-grid">
                    <div class="preflight-box"><span>Status</span><strong><?php echo htmlspecialchars($statusLabel($backupRestoreStatus)); ?></strong></div>
                    <div class="preflight-box"><span>DB Backups</span><strong><?php echo number_format((int) ($backupRestoreSummary['db_backup_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Uploads Backups</span><strong><?php echo number_format((int) ($backupRestoreSummary['uploads_backup_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>DB Age Hours</span><strong><?php echo htmlspecialchars((string) ($backupRestoreSummary['db_backup_age_hours'] ?? 'n/a')); ?></strong></div>
                    <div class="preflight-box"><span>Uploads Age Hours</span><strong><?php echo htmlspecialchars((string) ($backupRestoreSummary['uploads_backup_age_hours'] ?? 'n/a')); ?></strong></div>
                    <div class="preflight-box"><span>Restore Tools</span><strong><?php echo !empty($backupRestoreSummary['mysql_client_available']) && !empty($backupRestoreSummary['mysqldump_available']) ? 'Ready' : 'Missing'; ?></strong></div>
                    <div class="preflight-box"><span>Offsite</span><strong><?php echo !empty($backupRestoreSummary['offsite_configured']) ? 'Configured' : 'Missing'; ?></strong></div>
                    <div class="preflight-box"><span>Checked</span><strong><?php echo htmlspecialchars((string) ($backupRestoreMetadata['checked_at'] ?? '')); ?></strong></div>
                </div>

                <?php if ($backupRestoreExpectations !== []): ?>
                    <h3>Recovery Expectations</h3>
                    <ul class="preflight-list">
                        <?php foreach ($backupRestoreExpectations as $expectation): ?>
                            <li><?php echo htmlspecialchars((string) $expectation); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h3>Recent Findings</h3>
                <?php if ($backupRestoreFindings === []): ?>
                    <p>No backup and restore readiness findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Target</th><th>Severity</th><th>Rule</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($backupRestoreFindings as $finding): ?>
                            <?php
                                $finding = is_array($finding) ? $finding : [];
                                $target = (string) (($finding['target'] ?? '') ?: 'backup_restore');
                                $findingStatus = (string) ($finding['severity'] ?? 'warning');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($target); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($finding['message'] ?? '')); ?></span>
                                </td>
                                <td><span class="status <?php echo htmlspecialchars($statusClass($findingStatus)); ?>"><?php echo htmlspecialchars($statusLabel($findingStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($finding['rule'] ?? 'unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($finding['recommendation'] ?? 'Review backup and restore readiness before live upload.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($automationReviewDashboard !== []): ?>
            <section class="panel" aria-labelledby="automation-review-title">
                <h2 id="automation-review-title">Super Admin Automation Review Queue</h2>
                <p>Review detector evidence, prepared digests, skipped runs, and open findings before production upload.</p>

                <?php if ($automationMessage): ?><div class="notice success"><?php echo htmlspecialchars($automationMessage); ?></div><?php endif; ?>
                <?php if ($automationError): ?><div class="notice error"><?php echo htmlspecialchars($automationError); ?></div><?php endif; ?>

                <div class="preflight-grid">
                    <div class="preflight-box"><span>Open Findings</span><strong><?php echo number_format((int) ($automationReviewSummary['open_review_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Critical Open</span><strong><?php echo number_format((int) ($automationReviewSummary['critical_open_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Prepared</span><strong><?php echo number_format((int) ($automationReviewSummary['prepared_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Skipped</span><strong><?php echo number_format((int) ($automationReviewSummary['skipped_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Reviewed</span><strong><?php echo number_format((int) ($automationReviewSummary['reviewed_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Refreshed</span><strong><?php echo number_format((int) ($automationReviewSummary['duplicate_refresh_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Total Runs</span><strong><?php echo number_format((int) ($automationReviewSummary['total_runs'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Escalated</span><strong><?php echo number_format((int) ($automationReviewSummary['escalated_open_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Urgent</span><strong><?php echo number_format((int) ($automationReviewSummary['urgent_escalation_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Overdue</span><strong><?php echo number_format((int) ($automationReviewSummary['overdue_escalation_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Notify Pending</span><strong><?php echo number_format((int) ($automationReviewSummary['escalation_notification_pending_count'] ?? 0)); ?></strong></div>
                    <div class="preflight-box"><span>Notified</span><strong><?php echo number_format((int) ($automationReviewSummary['escalation_notification_sent_count'] ?? 0)); ?></strong></div>
                </div>

                <div class="actions">
                    <a class="button" href="<?php echo htmlspecialchars(publicUrl('system_health.php') . '?automation_dry_run=1'); ?>">Dry-run detectors</a>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="automation_review_action" value="run_detectors">
                        <button class="button primary" type="submit">Record detector evidence</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="automation_review_action" value="apply_escalation_policy">
                        <button class="button" type="submit">Apply escalation policy</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="automation_review_action" value="dispatch_escalation_notifications">
                        <button class="button" type="submit">Send escalation notifications</button>
                    </form>
                    <a class="button" href="<?php echo htmlspecialchars(apiUrl('automation_review_queue.php')); ?>">JSON dashboard</a>
                </div>

                <?php if ($automationDryRun !== null): ?>
                    <h3>Detector Dry Run</h3>
                    <div class="preflight-grid">
                        <div class="preflight-box"><span>Evaluated</span><strong><?php echo number_format((int) ($automationDryRunSummary['evaluated'] ?? 0)); ?></strong></div>
                        <div class="preflight-box"><span>Findings</span><strong><?php echo number_format((int) ($automationDryRunSummary['findings'] ?? 0)); ?></strong></div>
                        <div class="preflight-box"><span>Prepared Outputs</span><strong><?php echo number_format((int) ($automationDryRunSummary['prepared_outputs'] ?? 0)); ?></strong></div>
                    </div>
                <?php endif; ?>

                <h3>Escalated Findings</h3>
                <?php if ($automationEscalatedFindings === []): ?>
                    <p>No escalated automation findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Detector</th><th>Priority</th><th>Due</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php foreach ($automationEscalatedFindings as $run): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($run['automation_key'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper((string) ($run['escalation_priority'] ?? 'normal'))); ?></td>
                                <td><?php echo htmlspecialchars((string) ($run['escalation_due_at'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($run['escalation_reason'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3>Open Findings</h3>
                <?php if ($automationOpenFindings === []): ?>
                    <p>No open automation findings.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Detector</th><th>Status</th><th>Evidence</th><th>Review</th></tr></thead>
                        <tbody>
                        <?php foreach ($automationOpenFindings as $run): ?>
                            <?php $evidence = is_array($run['evidence'] ?? null) ? $run['evidence'] : []; ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string) ($run['name'] ?? $run['automation_key'] ?? 'Detector')); ?></strong><br>
                                    <span><?php echo htmlspecialchars((string) ($run['automation_key'] ?? '')); ?></span>
                                    <?php if ((int) ($run['duplicate_count'] ?? 0) > 0): ?>
                                        <br><span>Refreshed <?php echo number_format((int) ($run['duplicate_count'] ?? 0)); ?>x</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status <?php echo htmlspecialchars($statusClass((string) ($run['severity'] ?? 'unknown'))); ?>"><?php echo htmlspecialchars($statusLabel((string) ($run['severity'] ?? 'unknown'))); ?></span><br>
                                    <?php echo htmlspecialchars((string) ($run['run_status'] ?? '')); ?>
                                </td>
                                <td><pre><?php echo htmlspecialchars(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'); ?></pre></td>
                                <td>
                                    <form method="POST" class="review-actions">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="automation_review_action" value="review_run">
                                        <input type="hidden" name="run_id" value="<?php echo (int) ($run['id'] ?? 0); ?>">
                                        <input type="text" name="note" placeholder="Review note">
                                        <button class="button small" name="decision" value="approve_later" type="submit">Approve later</button>
                                        <button class="button small" name="decision" value="resolve" type="submit">Resolve</button>
                                        <button class="button small" name="decision" value="dismiss" type="submit">Dismiss</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3>Prepared Digests</h3>
                <?php if ($automationPreparedDigests === []): ?>
                    <p>No prepared automation digests yet.</p>
                <?php else: ?>
                    <ul class="preflight-list">
                        <?php foreach ($automationPreparedDigests as $run): ?>
                            <?php $evidence = is_array($run['evidence'] ?? null) ? $run['evidence'] : []; ?>
                            <li><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?>: <?php echo htmlspecialchars((string) (($run['recommendation']['summary'] ?? '') ?: (($evidence['critical_count'] ?? 0) . ' critical, ' . ($evidence['warning_count'] ?? 0) . ' warning'))); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($automationSkippedRuns !== []): ?>
                    <h3>Skipped Runs</h3>
                    <ul class="preflight-list">
                        <?php foreach ($automationSkippedRuns as $run): ?>
                            <li><?php echo htmlspecialchars((string) ($run['automation_key'] ?? '')); ?> at <?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h3>Recent Runs</h3>
                <?php if ($automationRecentRuns === []): ?>
                    <p>No automation detector runs recorded yet.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Detector</th><th>Run</th><th>Review</th><th>Refreshed</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($automationRecentRuns as $run): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($run['automation_key'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($run['run_status'] ?? '')); ?> / <?php echo htmlspecialchars((string) ($run['severity'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($run['review_status'] ?? '')); ?></td>
                                <td>
                                    <?php echo number_format((int) ($run['duplicate_count'] ?? 0)); ?>
                                    <?php if (!empty($run['last_seen_at'])): ?>
                                        <br><span><?php echo htmlspecialchars((string) ($run['last_seen_at'] ?? '')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel" aria-labelledby="config-title">
            <h2 id="config-title">Configuration</h2>
            <div class="config">
                <div><span>Host</span><strong><?php echo htmlspecialchars((string) ($configSummary['host'] ?? '')); ?></strong></div>
                <div><span>Database</span><strong><?php echo htmlspecialchars((string) ($configSummary['database'] ?? '')); ?></strong></div>
                <div><span>User</span><strong><?php echo htmlspecialchars((string) ($configSummary['user'] ?? '')); ?></strong></div>
                <div><span>Password</span><strong><?php echo !empty($configSummary['password_configured']) ? 'Configured' : 'Blank'; ?></strong></div>
            </div>
        </section>

        <section class="panel" aria-labelledby="checks-title">
            <h2 id="checks-title">Readiness Checks</h2>
            <table>
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check): ?>
                        <?php
                            $check = is_array($check) ? $check : [];
                            $metadata = is_array($check['metadata'] ?? null) ? $check['metadata'] : [];
                            $metadataText = $safeMetadata($metadata);
                            $status = (string) ($check['status'] ?? 'unknown');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($check['label'] ?? $check['key'] ?? 'Check')); ?></td>
                            <td><span class="status <?php echo htmlspecialchars($statusClass($status)); ?>"><?php echo htmlspecialchars($statusLabel($status)); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars((string) ($check['message'] ?? '')); ?>
                                <?php if ($metadataText !== ''): ?>
                                    <pre><?php echo htmlspecialchars($metadataText); ?></pre>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="actions">
                <a class="button primary" href="<?php echo htmlspecialchars(publicUrl('system_health.php')); ?>">Refresh checks</a>
                <a class="button" href="<?php echo htmlspecialchars(publicUrl('marketing_calendar.php')); ?>">Open marketing calendar</a>
                <a class="button" href="<?php echo htmlspecialchars(publicUrl('marketing.php')); ?>">Open marketing</a>
            </div>
        </section>
    </main>
</body>
</html>
