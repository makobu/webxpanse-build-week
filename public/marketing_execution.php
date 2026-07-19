<?php
/**
 * Marketing execution control center.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'campaign_copilot');
        if ($action === 'update_live_worker_schedule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update the live worker schedule.');
            }
            $marketing->updateLiveEmailWorkerSchedule($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=worker_schedule');
            exit;
        }
        if ($action === 'pause_live_worker') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause the live worker.');
            }
            $marketing->pauseLiveEmailWorker((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=worker_paused');
            exit;
        }
        if ($action === 'resume_live_worker') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume the live worker.');
            }
            $marketing->resumeLiveEmailWorker((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=worker_resumed');
            exit;
        }
        if ($action === 'run_live_channel_worker') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to run the live channel worker.');
            }
            $marketing->processLiveChannelHandoffWorker([
                'source' => 'manual',
                'limit' => (int) ($_POST['limit_count'] ?? 25),
                'requested_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=channel_worker_run');
            exit;
        }
        if ($action === 'update_live_channel_worker_schedule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update the live channel worker schedule.');
            }
            $marketing->updateLiveChannelWorkerSchedule($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=channel_worker_schedule');
            exit;
        }
        if ($action === 'pause_live_channel_worker') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause the live channel worker.');
            }
            $marketing->pauseLiveChannelWorker((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=channel_worker_paused');
            exit;
        }
        if ($action === 'resume_live_channel_worker') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume the live channel worker.');
            }
            $marketing->resumeLiveChannelWorker((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=channel_worker_resumed');
            exit;
        }
        if ($action === 'update_live_dispatcher_schedule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update the live dispatcher schedule.');
            }
            $marketing->updateLiveQueueDispatcherSchedule($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=dispatcher_schedule');
            exit;
        }
        if ($action === 'pause_live_dispatcher') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause the live dispatcher.');
            }
            $marketing->pauseLiveQueueDispatcher((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=dispatcher_paused');
            exit;
        }
        if ($action === 'resume_live_dispatcher') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume the live dispatcher.');
            }
            $marketing->resumeLiveQueueDispatcher((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=dispatcher_resumed');
            exit;
        }
        if ($action === 'request_live_recovery') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to request live recovery.');
            }
            $marketing->requestLiveQueueRecovery(
                (int) ($_POST['queue_id'] ?? 0),
                (string) ($_POST['recovery_action'] ?? 'retry'),
                (string) ($_POST['recovery_reason'] ?? ''),
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=recovery_requested');
            exit;
        }
        if ($action === 'apply_live_recovery') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to apply live recovery actions.');
            }
            $marketing->recoverLiveQueueItem(
                (int) ($_POST['queue_id'] ?? 0),
                (string) ($_POST['recovery_action'] ?? 'operator_note'),
                $_POST,
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=recovery_applied');
            exit;
        }
        if ($action === 'run_live_orchestrator') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to run the live orchestrator.');
            }
            $marketing->runLiveExecutionOrchestrator([
                'source' => 'manual',
                'limit' => (int) ($_POST['limit_count'] ?? 25),
                'requested_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=orchestrator_run');
            exit;
        }
        if ($action === 'update_live_orchestrator_schedule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update the live orchestrator schedule.');
            }
            $marketing->updateLiveOrchestratorSchedule($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=orchestrator_schedule');
            exit;
        }
        if ($action === 'pause_live_orchestrator') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause the live orchestrator.');
            }
            $marketing->pauseLiveOrchestratorWorker((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=orchestrator_paused');
            exit;
        }
        if ($action === 'resume_live_orchestrator') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume the live orchestrator.');
            }
            $marketing->resumeLiveOrchestratorWorker((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=orchestrator_resumed');
            exit;
        }
        if ($action === 'run_live_outcome_sync') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to run the live outcome sync worker.');
            }
            $marketing->runLiveOutcomeSyncWorker([
                'source' => 'manual',
                'limit' => (int) ($_POST['limit_count'] ?? 250),
                'requested_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=outcome_sync_run');
            exit;
        }
        if ($action === 'update_live_outcome_sync_schedule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update the live outcome sync schedule.');
            }
            $marketing->updateLiveOutcomeSyncSchedule($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=outcome_sync_schedule');
            exit;
        }
        if ($action === 'pause_live_outcome_sync') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause the live outcome sync worker.');
            }
            $marketing->pauseLiveOutcomeSyncWorker((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=outcome_sync_paused');
            exit;
        }
        if ($action === 'resume_live_outcome_sync') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume the live outcome sync worker.');
            }
            $marketing->resumeLiveOutcomeSyncWorker((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=outcome_sync_resumed');
            exit;
        }
        if ($action === 'create_live_proof_pack') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to generate live proof packs.');
            }
            $proofPack = $marketing->createLiveProofPack(
                (int) ($_POST['queue_id'] ?? 0),
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_execution.php?success=proof_pack&proof_pack_id=' . (int) ($proofPack['id'] ?? 0));
            exit;
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to run campaign copilot actions.');
        }
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $marketing->runCampaignCopilot(
            $campaignId,
            (string) ($_POST['run_type'] ?? 'readiness_summary'),
            (int) ($user['id'] ?? 0)
        );
        header('Location: ' . getBasePath() . '/marketing_execution.php?campaign_id=' . $campaignId . '&success=copilot');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$liveOutcomeSync = $marketing->syncLiveExecutionQueueOutcomes();
$summary = $marketing->getExecutionControlCenterSummary();
$campaignOptions = $marketing->listMarketingCampaignOptions(100);
$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$campaignConsole = null;
$consoleError = '';
if ($campaignId > 0) {
    try {
        $campaignConsole = $marketing->getCampaignExecutionConsole($campaignId, (int) ($user['id'] ?? 0));
    } catch (Throwable $e) {
        $consoleError = $e->getMessage();
    }
}
$counts = (array) ($summary['counts'] ?? []);
$liveExecution = (array) ($summary['live_execution'] ?? []);
$liveDispatchRuns = $marketing->listLiveQueueDispatchRuns([], 5, 0);
$liveDispatcherHealth = $marketing->getLiveQueueDispatcherHealth();
$liveDispatcherSchedule = (array) ($liveDispatcherHealth['schedule'] ?? []);
$liveDispatcherLease = (array) ($liveDispatcherHealth['lease'] ?? []);
$liveExecutionLauncherRuns = $marketing->listLiveExecutionLauncherRuns([], 5, 0);
$liveExecutionLauncherHealth = $marketing->getLiveExecutionLauncherHealth(true, (int) ($user['id'] ?? 0));
$liveOrchestrationRuns = $marketing->listLiveOrchestrationRuns([], 5, 0);
$liveOrchestratorSchedule = $marketing->getLiveOrchestratorSchedule();
$liveOutcomeSyncRuns = $marketing->listLiveOutcomeSyncRuns([], 5, 0);
$liveOutcomeSyncSchedule = $marketing->getLiveOutcomeSyncSchedule();
$liveOutcomeReconciliationEvents = $marketing->listLiveOutcomeReconciliationEvents([], 8, 0);
$liveProofPacks = $marketing->listLiveProofPacks([], 8, 0);
$liveProofReportSummary = $marketing->getMarketingLiveProofReportSummary();
$dueLiveDispatchQueue = $marketing->listDueLiveDispatchQueue(8, 0);
$liveEmailWorkerRuns = $marketing->listLiveEmailWorkerRuns([], 5, 0);
$liveChannelWorkerRuns = $marketing->listLiveChannelWorkerRuns([], 5, 0);
$liveChannelWorkerSchedule = $marketing->getLiveChannelWorkerSchedule();
$liveChannelWorkerLease = $marketing->getLiveChannelWorkerLeaseStatus();
$liveEmailDeliveryProofs = $marketing->listLiveEmailDeliveryProofs([], 8, 0);
$liveChannelDeliveryProofs = $marketing->listLiveChannelDeliveryProofs([], 8, 0);
$liveEmailUnsubscribeEvents = $marketing->listLiveEmailUnsubscribeEvents([], 6, 0);
$liveWebhookAttempts = $marketing->listLiveWebhookAttempts([], 6, 0);
$liveWebhookHealth = $marketing->getLiveWebhookAttemptHealth(true, (int) ($user['id'] ?? 0));
$liveEmailMonitor = $marketing->getLiveEmailQueueMonitor(8);
$liveSetupWizard = $marketing->getMarketingLiveSetupWizard((int) ($user['id'] ?? 0));
$liveRehearsals = $marketing->listLiveRehearsalRuns([], 6, 0);
$liveSchedulerValidation = $marketing->getMarketingLiveSchedulerValidation(true, (int) ($user['id'] ?? 0));
$liveRecoveryQueues = [
    'retryable' => $marketing->listLiveRecoveryQueue('retryable', 5, 0),
    'blocked' => $marketing->listLiveRecoveryQueue('blocked', 5, 0),
    'cancelled' => $marketing->listLiveRecoveryQueue('cancelled', 5, 0),
    'permanent_failed' => $marketing->listLiveRecoveryQueue('permanent_failed', 5, 0),
];
$liveRecoveryEvents = $marketing->listLiveRecoveryEvents([], 6, 0);
$liveConsentReviewQueues = $marketing->getLiveConsentSuppressionReviewQueues(5);
$proofPackSources = [(array) $dueLiveDispatchQueue];
foreach (array_values($liveRecoveryQueues) as $recoveryQueueRows) {
    $proofPackSources[] = (array) $recoveryQueueRows;
}
$proofPackSources[] = (array) ($summary['ready_to_execute']['execution_queue'] ?? []);
$proofPackSources[] = (array) ($summary['blocked']['execution_queue'] ?? []);
$proofPackCandidateQueuesById = [];
foreach (array_merge(...$proofPackSources) as $proofCandidateQueue) {
    $proofCandidateId = (int) ($proofCandidateQueue['id'] ?? 0);
    if ($proofCandidateId > 0) {
        $proofPackCandidateQueuesById[$proofCandidateId] = $proofCandidateQueue;
    }
}
$proofPackCandidateQueues = array_slice(array_values($proofPackCandidateQueuesById), 0, 8);
$liveWorkerHealth = (array) ($liveEmailMonitor['worker_health'] ?? []);
$liveWorkerSchedule = (array) ($liveWorkerHealth['schedule'] ?? []);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$maskRecipient = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return 'recipient unavailable';
    }
    if (str_contains($value, '@')) {
        [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');
        return mb_substr($name, 0, 2) . '***@' . $domain;
    }
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) > 4) {
        return '***' . substr($digits, -4);
    }

    return 'recipient ending unavailable';
};
$liveWorkerStatus = (string) ($liveWorkerHealth['status'] ?? 'unknown');
$liveWorkerStatusClass = match ($liveWorkerStatus) {
    'healthy' => 'ready',
    'blocked', 'stale' => 'blocked',
    default => 'warning',
};
$liveDispatcherStatus = (string) ($liveDispatcherHealth['status'] ?? 'unknown');
$liveDispatcherStatusClass = match ($liveDispatcherStatus) {
    'healthy' => 'ready',
    'blocked', 'stale' => 'blocked',
    default => 'warning',
};
$liveLauncherStatus = (string) ($liveExecutionLauncherHealth['status'] ?? 'unknown');
$liveLauncherStatusClass = match ($liveLauncherStatus) {
    'healthy' => 'ready',
    'blocked', 'stale' => 'blocked',
    default => 'warning',
};
$liveWebhookStatus = (string) ($liveWebhookHealth['status'] ?? 'unknown');
$liveWebhookStatusClass = match ($liveWebhookStatus) {
    'healthy' => 'ready',
    'blocked', 'stale' => 'blocked',
    default => 'warning',
};
$dispatcherCommand = (string) ($liveDispatcherHealth['command'] ?? (
    'php scripts/run_marketing_live_dispatcher.php --workspace-id='
    . (int) (WorkspaceContext::currentWorkspaceId() ?? 0)
    . ' --user-id=' . (int) ($user['id'] ?? 0)
    . ' --limit=10 --source=cron'
));
$orchestratorCommand = (string) ($liveOrchestratorSchedule['command'] ?? 'php cli/run_marketing_live_execution.php all 25 --source=cron');
$outcomeSyncCommand = (string) ($liveOutcomeSyncSchedule['command'] ?? 'php scripts/sync_marketing_live_outcomes.php all 250');
$channelWorkerCommand = (string) ($liveChannelWorkerSchedule['command'] ?? 'php cli/process_marketing_live_channel_handoffs.php all 25');
$schedulerCommandHints = array_values(array_unique(array_filter(array_merge(
    (array) ($liveSchedulerValidation['command_hints'] ?? []),
    [
        'php cli/run_marketing_live_execution.php all 25 --readiness --json',
        $orchestratorCommand,
        $dispatcherCommand,
        'php cli/process_marketing_live_email_handoffs.php all 25',
        $channelWorkerCommand,
        $outcomeSyncCommand,
    ]
))));
$executionStatusClass = static function (string $status): string {
    return match ($status) {
        'ready', 'healthy', 'complete', 'sent', 'fresh' => 'ready',
        'blocked', 'stale', 'failed', 'cancelled', 'permanent_failed' => 'blocked',
        default => 'attention',
    };
};
$schedulerStatus = (string) ($liveSchedulerValidation['status'] ?? 'unknown');
$schedulerStatusClass = $executionStatusClass($schedulerStatus);
$setupScore = (int) ($liveSetupWizard['score'] ?? 0);
$readyToExecuteCount = (int) (($counts['ready_to_execute'] ?? null) ?: count((array) ($summary['ready_to_execute']['execution_queue'] ?? [])));
$blockedCount = (int) (($counts['blocked'] ?? null) ?: count((array) ($summary['blocked']['execution_queue'] ?? [])));
$reviewCount = (int) (($counts['awaiting_review'] ?? null) ?: count((array) ($summary['awaiting_review']['execution_queue'] ?? [])));
$proofCount = count($liveProofPacks) + count($liveEmailDeliveryProofs) + count($liveChannelDeliveryProofs);
$recoveryCount = array_sum(array_map('count', $liveRecoveryQueues));
$executionSummaryTiles = [
    [
        'icon' => 'fa-rocket',
        'label' => 'Ready',
        'value' => (string) $readyToExecuteCount,
        'tooltip' => 'Campaign or channel work that looks ready for the next manual execution step.',
    ],
    [
        'icon' => 'fa-triangle-exclamation',
        'label' => 'Blocked',
        'value' => (string) $blockedCount,
        'tooltip' => 'Execution items currently blocked by policy, consent, connector, approval, or queue safety checks.',
    ],
    [
        'icon' => 'fa-user-check',
        'label' => 'Review',
        'value' => (string) $reviewCount,
        'tooltip' => 'Items waiting for a founder/operator decision before the next execution move.',
    ],
    [
        'icon' => 'fa-shield-alt',
        'label' => 'Setup',
        'value' => $setupScore . '%',
        'tooltip' => 'Live setup readiness score from the controlled execution setup path.',
    ],
    [
        'icon' => 'fa-clock',
        'label' => 'Scheduler',
        'value' => $labelize($schedulerStatus),
        'tooltip' => 'Production scheduler and worker evidence separate from connector readiness.',
    ],
];
$executionCards = [
    [
        'icon' => 'fa-list-check',
        'title' => 'Check Launch',
        'status' => $blockedCount > 0 ? 'blocked' : 'ready',
        'metric' => $blockedCount . ' blockers',
        'action' => 'Open Launch Control',
        'href' => 'marketing_launch_control.php',
        'tooltip' => 'Use launch control when a campaign needs a final manual-first safety check.',
    ],
    [
        'icon' => 'fa-box-open',
        'title' => 'Prepare Package',
        'status' => $readyToExecuteCount > 0 ? 'ready' : 'attention',
        'metric' => $readyToExecuteCount . ' ready',
        'action' => 'Open Exports',
        'href' => 'marketing_channel_exports.php',
        'tooltip' => 'Use channel exports to package content for manual email, SMS, WhatsApp, or other channel execution.',
    ],
    [
        'icon' => 'fa-route',
        'title' => 'Choose Move',
        'status' => $reviewCount > 0 ? 'attention' : 'ready',
        'metric' => $reviewCount . ' reviews',
        'action' => 'Open Decisions',
        'href' => 'marketing_decisions.php',
        'tooltip' => 'Use decisions when the next execution move needs a human choice.',
    ],
    [
        'icon' => 'fa-envelope',
        'title' => 'Manual Send',
        'status' => $liveWorkerStatusClass,
        'metric' => $labelize($liveWorkerStatus),
        'action' => 'Open Email Runs',
        'href' => 'marketing_email_runs.php',
        'tooltip' => 'Email runs keep manual send bundles and live email worker evidence separate from the founder flow.',
    ],
    [
        'icon' => 'fa-chart-line',
        'title' => 'Proof And Outcomes',
        'status' => $proofCount > 0 ? 'ready' : 'attention',
        'metric' => $proofCount . ' proofs',
        'action' => 'Open Performance',
        'href' => 'marketing_performance.php',
        'tooltip' => 'Delivery proofs and outcomes are the evidence layer for learning what happened after execution.',
    ],
    [
        'icon' => 'fa-screwdriver-wrench',
        'title' => 'Live Machinery',
        'status' => $schedulerStatusClass,
        'metric' => $labelize($schedulerStatus),
        'action' => 'Open Tools',
        'href' => '#execution-details',
        'tooltip' => 'Manager-only worker, scheduler, recovery, proof-pack, and queue controls live in the drawer below.',
    ],
];
$executionTodayActions = array_slice([
    [
        'type' => 'link',
        'label' => 'Resolve launch blockers',
        'href' => 'marketing_launch_control.php',
        'tooltip' => 'Start with the visible blockers before running any live or manual execution flow.',
    ],
    [
        'type' => 'link',
        'label' => 'Package channel handoff',
        'href' => 'marketing_channel_exports.php',
        'tooltip' => 'Prepare a channel-safe package for manual handoff.',
    ],
    [
        'type' => 'link',
        'label' => 'Review decisions',
        'href' => 'marketing_decisions.php',
        'tooltip' => 'Clear decisions that are holding up execution.',
    ],
    [
        'type' => 'link',
        'label' => 'Open live tools',
        'href' => '#execution-details',
        'tooltip' => 'Open the detailed execution machinery drawer for schedulers, workers, and recovery controls.',
    ],
    [
        'type' => 'link',
        'label' => 'Check outcomes',
        'href' => 'marketing_performance.php',
        'tooltip' => 'Use performance once execution evidence exists.',
    ],
], 0, 5);
$pageTitle = 'Marketing Execution Control Center - ' . brandProductName();

$renderRows = static function (array $rows, callable $formatter, string $emptyMessage) use ($h): void {
    if ($rows === []) {
        echo '<div class="empty-state"><p>' . $h($emptyMessage) . '</p></div>';
        return;
    }

    foreach ($rows as $row) {
        $formatted = $formatter((array) $row);
        $href = (string) ($formatted['href'] ?? '#');
        $title = (string) ($formatted['title'] ?? 'Marketing record');
        $status = (string) ($formatted['status'] ?? '');
        $meta = (array) ($formatted['meta'] ?? []);
        echo '<div class="execution-row">';
        echo '<div>';
        echo '<a class="execution-title" href="' . $h($href) . '">' . $h($title) . '</a>';
        echo '<div class="execution-meta">';
        foreach ($meta as $item) {
            if (trim((string) $item) !== '') {
                echo '<span>' . $h($item) . '</span>';
            }
        }
        echo '</div>';
        echo '</div>';
        if ($status !== '') {
            echo '<span class="execution-chip">' . $h($status) . '</span>';
        }
        echo '</div>';
    }
};

ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/marketing-ui.css">

<div class="page-premium marketing-ui-page marketing-execution-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Marketing Execution Control Center</h1>
                <p>Move campaigns from ready to done without losing safety evidence.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <a class="btn-premium-secondary" href="marketing_launch_control.php">Launch Control</a>
                <a class="btn-premium-secondary" href="marketing.php">Marketing</a>
            </div>
        </div>

        <section class="marketing-execution-summary" aria-label="Execution summary">
            <?php foreach ($executionSummaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h((string) $tile['tooltip']); ?>">
                    <i class="fas <?php echo $h((string) $tile['icon']); ?>" aria-hidden="true"></i>
                    <div><span><?php echo $h((string) $tile['label']); ?></span><strong><?php echo $h((string) $tile['value']); ?></strong></div>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="marketing-execution-layout">
            <main class="marketing-execution-main">
                <section class="content-card marketing-execution-board">
                    <div class="premium-section-header"><div><h2>Execution Map</h2><p>Pick the next safe move.</p></div></div>
                    <div class="marketing-execution-card-grid">
                        <?php foreach ($executionCards as $card): ?>
                            <article class="marketing-execution-card <?php echo $h($executionStatusClass((string) $card['status'])); ?>" tabindex="0" data-tooltip="<?php echo $h((string) $card['tooltip']); ?>">
                                <div class="marketing-execution-visual"><i class="fas <?php echo $h((string) $card['icon']); ?>" aria-hidden="true"></i></div>
                                <div class="marketing-execution-card-body">
                                    <div class="marketing-execution-card-title"><strong><?php echo $h((string) $card['title']); ?></strong><span class="execution-chip"><?php echo $h($labelize((string) $card['status'])); ?></span></div>
                                    <span><?php echo $h((string) $card['metric']); ?></span>
                                </div>
                                <a class="btn-premium-primary marketing-execution-card-action" href="<?php echo $h((string) $card['href']); ?>"><?php echo $h((string) $card['action']); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </main>
            <aside class="marketing-execution-side" aria-label="Execution next actions">
                <section class="content-card marketing-execution-today">
                    <div class="premium-section-header"><div><h2>Today</h2><p>One execution move.</p></div></div>
                    <div class="marketing-execution-today-list">
                        <?php foreach ($executionTodayActions as $action): ?>
                            <a class="marketing-execution-today-action" href="<?php echo $h((string) $action['href']); ?>" data-tooltip="<?php echo $h((string) $action['tooltip']); ?>"><strong><?php echo $h((string) $action['label']); ?></strong><span>Open</span></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>

        <details class="marketing-execution-tools" id="execution-details">
            <summary>More execution tools</summary>
            <div class="marketing-execution-tools-body">

        <div class="content-card marketing-execution-section">
            <div class="premium-section-header">
                <div>
                    <h2>Live Setup Wizard</h2>
                    <p>Follow the controlled path from disabled live execution to a safe manager-approved live run.</p>
                </div>
                <span class="execution-chip"><?php echo $h((int) ($liveSetupWizard['score'] ?? 0)); ?>% ready</span>
            </div>
            <?php if (!empty($liveSetupWizard['next_step'])): ?>
                <div class="execution-note is-warning">
                    <strong>Next setup step</strong>
                    <span class="marketing-execution-detail"><?php echo $h((string) ($liveSetupWizard['next_step']['message'] ?? 'Complete the next live setup requirement.')); ?></span>
                </div>
            <?php else: ?>
                <div class="execution-note is-ready"><strong>Live setup complete</strong><span class="marketing-execution-detail">All required live setup steps have ready evidence.</span></div>
            <?php endif; ?>
            <div class="live-setup-steps">
                <?php foreach ((array) ($liveSetupWizard['steps'] ?? []) as $step): ?>
                    <a class="live-setup-step is-<?php echo $h((string) ($step['status'] ?? 'missing')); ?>" href="<?php echo $h((string) ($step['href'] ?? '#')); ?>">
                        <strong><?php echo $h((string) ($step['label'] ?? 'Setup step')); ?></strong>
                        <span><?php echo $h($labelize((string) ($step['status'] ?? 'missing'))); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="content-card marketing-execution-section">
            <div class="premium-section-header">
                <div><h2>Dry-Run And Live Rehearsals</h2><p>Recent rehearsals prove the package was dry-run before a manager promotes it into the live queue.</p></div>
                <a class="btn-premium-secondary" href="marketing_channel_exports.php">Open Rehearsals</a>
            </div>
            <?php if (empty($liveRehearsals)): ?>
                <div class="empty-state"><p>No live rehearsals yet. Create one from Channel Exports before promoting a live queue item.</p></div>
            <?php else: ?>
                <div class="execution-grid">
                    <?php foreach ($liveRehearsals as $rehearsal): ?>
                        <div class="execution-note">
                            <strong><?php echo $h($labelize((string) ($rehearsal['execution_type'] ?? 'other'))); ?> rehearsal</strong>
                            <span class="marketing-execution-detail"><?php echo $h($labelize((string) ($rehearsal['status'] ?? 'draft'))); ?> - <?php echo $h((string) ($rehearsal['connector_name'] ?? 'No connector')); ?></span>
                            <?php if (!empty($rehearsal['dry_run_evidence_json']['dry_run_status'])): ?><span class="marketing-execution-detail">Dry-run: <?php echo $h($labelize((string) $rehearsal['dry_run_evidence_json']['dry_run_status'])); ?></span><?php endif; ?>
                            <?php if (!empty($rehearsal['promoted_queue_id'])): ?><span class="marketing-execution-detail">Promoted queue #<?php echo (int) $rehearsal['promoted_queue_id']; ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card marketing-execution-section">
            <?php
                $schedulerStatus = (string) ($liveSchedulerValidation['status'] ?? 'unknown');
                $schedulerStatusClass = match ($schedulerStatus) {
                    'ready' => 'ready',
                    'blocked', 'stale' => 'blocked',
                    default => 'warning',
                };
            ?>
            <div class="premium-section-header">
                <div>
                    <h2>Production Scheduler Validation</h2>
                    <p>Confirms cron/worker evidence separately from connector readiness, so live execution cannot look ready just because a connector passes preflight.</p>
                </div>
                <span class="execution-chip"><?php echo $h($labelize($schedulerStatus)); ?></span>
            </div>
            <div class="execution-note is-<?php echo $h($schedulerStatusClass); ?>">
                <strong><?php echo $h((string) ($liveSchedulerValidation['message'] ?? 'Scheduler validation has not run yet.')); ?></strong>
                <span class="marketing-execution-detail">
                    Ready <?php echo (int) ($liveSchedulerValidation['counts']['ready'] ?? 0); ?> /
                    Action needed <?php echo (int) ($liveSchedulerValidation['counts']['action_needed'] ?? 0); ?> /
                    Stale <?php echo (int) ($liveSchedulerValidation['counts']['stale'] ?? 0); ?> /
                    Blocked <?php echo (int) ($liveSchedulerValidation['counts']['blocked'] ?? 0); ?>
                </span>
            </div>
            <div class="scheduler-validation-grid">
                <?php foreach ((array) ($liveSchedulerValidation['components'] ?? []) as $component): ?>
                    <?php $componentStatus = (string) ($component['status'] ?? 'unknown'); ?>
                    <div class="scheduler-validation-card is-<?php echo $h($componentStatus); ?>">
                        <div class="execution-row marketing-execution-row-flush">
                            <div>
                                <strong><?php echo $h((string) ($component['label'] ?? 'Scheduler component')); ?></strong>
                                <div class="execution-meta"><span><?php echo $h($labelize($componentStatus)); ?></span><span><?php echo $h((string) ($component['expected_schedule'] ?? 'No schedule configured')); ?></span></div>
                            </div>
                            <span class="execution-chip"><?php echo $h($labelize((string) ($component['lease_state'] ?? 'not_applicable'))); ?></span>
                        </div>
                        <span><?php echo $h((string) ($component['message'] ?? 'No component message.')); ?></span>
                        <span>Last observed: <?php echo $h((string) (($component['last_observed_run_at'] ?? null) ?: 'Not recorded')); ?><?php if (($component['heartbeat_age_seconds'] ?? null) !== null): ?>; heartbeat age <?php echo (int) $component['heartbeat_age_seconds']; ?>s<?php endif; ?></span>
                        <?php if (!empty($component['command_hint'])): ?><code><?php echo $h((string) $component['command_hint']); ?></code><?php endif; ?>
                        <?php if (!empty($component['recommended_action'])): ?><span><?php echo $h((string) $component['recommended_action']); ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($canManageMarketing): ?>
                <div class="execution-note marketing-execution-spaced">
                    <strong>Copy Scheduler Commands</strong>
                    <span class="marketing-execution-detail">Use these secret-safe examples in cron or Task Scheduler after confirming the PHP path and workspace policy.</span>
                    <div class="scheduler-command-list">
                        <?php foreach ($schedulerCommandHints as $commandHint): ?>
                            <code><?php echo $h((string) $commandHint); ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-card marketing-execution-section">
            <div class="premium-section-header">
                <div>
                    <h2>Failure Recovery Controls</h2>
                    <p>Retry, reschedule, cancel, re-run preflight, and recheck suppression without deleting attempt evidence.</p>
                </div>
                <span class="execution-chip"><?php echo count($liveRecoveryEvents); ?> recent event(s)</span>
            </div>
            <div class="execution-grid">
                <?php foreach ($liveRecoveryQueues as $queueName => $recoveryRows): ?>
                    <div class="execution-note">
                        <strong><?php echo $h($labelize((string) $queueName)); ?></strong>
                        <?php if (empty($recoveryRows)): ?>
                            <span class="marketing-execution-detail-relaxed">No <?php echo $h($labelize((string) $queueName)); ?> live queue items.</span>
                        <?php else: foreach ($recoveryRows as $recoveryQueue): ?>
                            <div class="execution-row">
                                <div>
                                    <strong>Queue #<?php echo (int) ($recoveryQueue['id'] ?? 0); ?> - <?php echo $h($labelize((string) ($recoveryQueue['execution_type'] ?? 'other'))); ?></strong>
                                    <div class="execution-meta">
                                        <span><?php echo $h($labelize((string) ($recoveryQueue['status'] ?? 'draft'))); ?></span>
                                        <span>Dispatch <?php echo $h($labelize((string) ($recoveryQueue['live_dispatch_status'] ?? 'not_ready'))); ?></span>
                                        <span>Retry <?php echo (int) ($recoveryQueue['live_retry_count'] ?? 0); ?>/<?php echo (int) ($recoveryQueue['live_max_retries'] ?? 0); ?></span>
                                        <?php if (!empty($recoveryQueue['connector_name'])): ?><span><?php echo $h((string) $recoveryQueue['connector_name']); ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($recoveryQueue['live_blocked_reason'])): ?><div class="execution-meta"><?php echo $h((string) $recoveryQueue['live_blocked_reason']); ?></div><?php endif; ?>
                                    <div class="execution-meta">
                                        <?php if ($canManageMarketing): ?>
                                            <?php foreach (['retry' => 'Retry', 'reschedule' => 'Reschedule', 'rerun_preflight' => 'Preflight', 'recheck_suppression' => 'Suppression', 'cancel' => 'Cancel'] as $recoveryAction => $recoveryLabel): ?>
                                                <form method="POST" class="marketing-execution-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="apply_live_recovery">
                                                    <input type="hidden" name="queue_id" value="<?php echo (int) ($recoveryQueue['id'] ?? 0); ?>">
                                                    <input type="hidden" name="recovery_action" value="<?php echo $h($recoveryAction); ?>">
                                                    <input type="hidden" name="scheduled_at" value="<?php echo $h(date('Y-m-d H:i:s', time() + 900)); ?>">
                                                    <input type="hidden" name="recovery_reason" value="Operator recovery action from Execution Center">
                                                    <button class="btn-premium-secondary" type="submit"><?php echo $h($recoveryLabel); ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php elseif ($canWriteMarketing): ?>
                                            <form method="POST" class="marketing-execution-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="request_live_recovery">
                                                <input type="hidden" name="queue_id" value="<?php echo (int) ($recoveryQueue['id'] ?? 0); ?>">
                                                <input type="hidden" name="recovery_action" value="retry">
                                                <input type="hidden" name="recovery_reason" value="Writer requested manager live recovery review">
                                                <button class="btn-premium-secondary" type="submit">Request recovery</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="execution-chip"><?php echo $h($labelize((string) ($recoveryQueue['live_recovery_status'] ?? 'none'))); ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="premium-section-header marketing-execution-subheader"><h2>Recovery Events</h2></div>
            <?php if (empty($liveRecoveryEvents)): ?>
                <div class="empty-state"><p>No live recovery events have been recorded yet.</p></div>
            <?php else: foreach ($liveRecoveryEvents as $recoveryEvent): ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h($labelize((string) ($recoveryEvent['action_type'] ?? 'operator_note'))); ?> on queue #<?php echo (int) ($recoveryEvent['queue_id'] ?? 0); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($recoveryEvent['status'] ?? 'completed'))); ?></span>
                            <span><?php echo $h((string) ($recoveryEvent['created_at'] ?? '')); ?></span>
                            <?php if (!empty($recoveryEvent['connector_name'])): ?><span><?php echo $h((string) $recoveryEvent['connector_name']); ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($recoveryEvent['reason'])): ?><div class="execution-meta"><?php echo $h((string) $recoveryEvent['reason']); ?></div><?php endif; ?>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($recoveryEvent['next_dispatch_status'] ?? $recoveryEvent['previous_dispatch_status'] ?? 'not_ready'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card marketing-execution-section">
            <div class="premium-section-header">
                <div>
                    <h2>Consent And Suppression Review</h2>
                    <p>Live email, SMS, and WhatsApp queue items must prove unsubscribe, consent, suppression, and WhatsApp template/session safety before handoff.</p>
                </div>
                <span class="execution-chip"><?php echo array_sum(array_map('count', $liveConsentReviewQueues)); ?> item(s)</span>
            </div>
            <div class="execution-grid">
                <?php foreach ($liveConsentReviewQueues as $queueName => $reviewRows): ?>
                    <div class="execution-note">
                        <strong><?php echo $h($labelize((string) $queueName)); ?></strong>
                        <?php if (empty($reviewRows)): ?>
                            <span class="marketing-execution-detail-relaxed">No <?php echo $h($labelize((string) $queueName)); ?> live queue blockers.</span>
                        <?php else: foreach ($reviewRows as $reviewQueue): ?>
                            <div class="execution-row">
                                <div>
                                    <strong>Queue #<?php echo (int) ($reviewQueue['id'] ?? 0); ?> - <?php echo $h($labelize((string) ($reviewQueue['execution_type'] ?? 'other'))); ?></strong>
                                    <div class="execution-meta">
                                        <span><?php echo $h($labelize((string) ($reviewQueue['status'] ?? 'draft'))); ?></span>
                                        <?php if (!empty($reviewQueue['connector_name'])): ?><span><?php echo $h((string) $reviewQueue['connector_name']); ?></span><?php endif; ?>
                                        <span><?php echo $h((string) ($reviewQueue['updated_at'] ?? '')); ?></span>
                                    </div>
                                    <?php foreach (array_slice((array) ($reviewQueue['blocked_reasons'] ?? []), 0, 4) as $reason): ?>
                                        <div class="execution-meta"><?php echo $h($labelize((string) $reason)); ?></div>
                                    <?php endforeach; ?>
                                    <?php foreach (array_slice((array) ($reviewQueue['warnings'] ?? []), 0, 2) as $warning): ?>
                                        <div class="execution-meta"><?php echo $h($labelize((string) $warning)); ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <span class="execution-chip"><?php echo $h($labelize((string) (($reviewQueue['consent_suppression']['suppression_result'] ?? 'not_checked')))); ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="content-card marketing-execution-section">
            <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Execution action was not applied.</strong> <?php echo $h($error); ?></div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'copilot'): ?><div class="alert alert-success">Campaign copilot run saved for operator review.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'worker_schedule'): ?><div class="alert alert-success">Live email worker schedule saved.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'worker_paused'): ?><div class="alert alert-success">Live email worker emergency pause is active.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'worker_resumed'): ?><div class="alert alert-success">Live email worker emergency pause cleared.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'channel_worker_run'): ?><div class="alert alert-success">Live channel worker run recorded.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'channel_worker_schedule'): ?><div class="alert alert-success">Live channel worker schedule saved.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'channel_worker_paused'): ?><div class="alert alert-success">Live channel worker emergency pause is active.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'channel_worker_resumed'): ?><div class="alert alert-success">Live channel worker emergency pause cleared.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'dispatcher_schedule'): ?><div class="alert alert-success">Live queue dispatcher schedule saved.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'dispatcher_paused'): ?><div class="alert alert-success">Live queue dispatcher emergency pause is active.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'dispatcher_resumed'): ?><div class="alert alert-success">Live queue dispatcher emergency pause cleared.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'recovery_requested'): ?><div class="alert alert-success">Live recovery request recorded for manager review.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'recovery_applied'): ?><div class="alert alert-success">Live recovery action applied and recorded.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'orchestrator_run'): ?><div class="alert alert-success">Live orchestrator run recorded.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'orchestrator_schedule'): ?><div class="alert alert-success">Live orchestrator schedule saved.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'orchestrator_paused'): ?><div class="alert alert-success">Live orchestrator emergency pause is active.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'orchestrator_resumed'): ?><div class="alert alert-success">Live orchestrator emergency pause cleared.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'outcome_sync_run'): ?><div class="alert alert-success">Live outcome sync worker run recorded.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'outcome_sync_schedule'): ?><div class="alert alert-success">Live outcome sync schedule saved.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'outcome_sync_paused'): ?><div class="alert alert-success">Live outcome sync emergency pause is active.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'outcome_sync_resumed'): ?><div class="alert alert-success">Live outcome sync emergency pause cleared.</div><?php endif; ?>
            <?php if (($_GET['success'] ?? '') === 'proof_pack'): ?><div class="alert alert-success">Live proof pack generated with sanitized evidence.</div><?php endif; ?>
            <div class="premium-section-header">
                <div><h2>Campaign Execution Console</h2><p>Choose one campaign to see its launch workspace, action center, decisions, evidence, checklists, and manual export packs in one place.</p></div>
                <form method="GET" class="marketing-execution-form-row">
                    <label>Campaign
                        <select class="form-control" name="campaign_id">
                            <option value="">Select campaign</option>
                            <?php foreach ($campaignOptions as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo $campaignId === (int) $campaign['id'] ? 'selected' : ''; ?>><?php echo $h((string) $campaign['name']); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn-premium-secondary" type="submit">Open Console</button>
                </form>
            </div>
            <?php if ($consoleError !== ''): ?>
                <div class="alert alert-danger"><?php echo $h($consoleError); ?></div>
            <?php elseif ($campaignConsole === null): ?>
                <div class="empty-state"><p>Select a campaign to open the manual execution console.</p></div>
            <?php else: ?>
                <div class="campaign-console-grid">
                    <div class="execution-kpi"><span>Console Status</span><strong class="marketing-execution-kpi-status"><?php echo $h($labelize((string) ($campaignConsole['status'] ?? 'needs_attention'))); ?></strong></div>
                    <div class="execution-kpi"><span>Readiness</span><strong><?php echo (int) ($campaignConsole['readiness_score'] ?? 0); ?>%</strong></div>
                    <div class="execution-kpi"><span>Open Decisions</span><strong><?php echo (int) ($campaignConsole['counts']['open_decisions'] ?? 0); ?></strong></div>
                    <div class="execution-kpi"><span>Media Work</span><strong><?php echo (int) ($campaignConsole['counts']['media_production_work'] ?? 0); ?></strong></div>
                </div>
                <div class="content-card campaign-console-section marketing-execution-section">
                    <div class="premium-section-header"><div><h2>Campaign AI Copilot</h2><p>Generate draft-side strategy, blocker, checklist, sequence, or handoff recommendations without overwriting campaign records.</p></div></div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-execution-form-row">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="campaign_id" value="<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>">
                            <label>Copilot action
                                <select class="form-control" name="run_type">
                                    <?php foreach (Marketing::CAMPAIGN_COPILOT_RUN_TYPES as $runType): ?><option value="<?php echo $h($runType); ?>"><?php echo $h($labelize($runType)); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <button class="btn-premium-secondary" type="submit">Run Copilot</button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state"><p>You can view campaign copilot runs, but you do not have permission to create new ones.</p></div>
                    <?php endif; ?>
                    <?php if (empty($campaignConsole['copilot_runs'])): ?>
                        <div class="execution-note"><strong>No saved copilot runs yet</strong><span class="marketing-execution-detail">Run the copilot to create reviewable recommendations for this campaign.</span></div>
                    <?php else: foreach ((array) $campaignConsole['copilot_runs'] as $run): ?>
                        <div class="execution-row">
                            <div>
                                <strong><?php echo $h((string) ($run['result_json']['title'] ?? $labelize((string) ($run['run_type'] ?? 'copilot')))); ?></strong>
                                <div class="execution-meta"><span><?php echo $h($labelize((string) ($run['run_type'] ?? 'readiness_summary'))); ?></span><span><?php echo $h((string) ($run['created_at'] ?? '')); ?></span></div>
                                <div class="execution-meta"><?php echo $h((string) ($run['result_json']['summary'] ?? '')); ?></div>
                            </div>
                            <span class="execution-chip"><?php echo count((array) ($run['result_json']['recommendations'] ?? [])); ?> recs</span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="execution-grid">
                    <div class="content-card campaign-console-section">
                        <div class="premium-section-header"><h2>Action Center</h2></div>
                        <?php foreach (array_slice((array) ($campaignConsole['action_center']['actions'] ?? []), 0, 5) as $action): ?>
                            <a class="execution-note" href="<?php echo $h((string) ($action['href'] ?? 'marketing_campaign_workspace.php?campaign_id=' . (int) ($campaignConsole['campaign_id'] ?? 0))); ?>"><strong><?php echo $h((string) ($action['label'] ?? 'Action')); ?></strong><span class="marketing-execution-detail"><?php echo $h((string) ($action['reason'] ?? 'Review this action.')); ?></span></a>
                        <?php endforeach; ?>
                        <?php if (empty($campaignConsole['action_center']['actions'])): ?><div class="empty-state"><p>No action center items are currently blocking this campaign.</p></div><?php endif; ?>
                    </div>
                    <div class="content-card campaign-console-section">
                        <div class="premium-section-header"><h2>Execution Evidence</h2></div>
                        <?php if (empty($campaignConsole['latest_action_snapshot'])): ?>
                            <div class="empty-state"><p>No saved action snapshot yet. Save one from the campaign workspace before launch handoff.</p><a href="marketing_campaign_workspace.php?campaign_id=<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>">Open Campaign Workspace</a></div>
                        <?php else: $snapshot = (array) $campaignConsole['latest_action_snapshot']; ?>
                            <div class="execution-note"><strong>Latest Action Snapshot</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($snapshot['action_center_status'] ?? 'needs_attention'))); ?> - <?php echo (int) ($snapshot['urgent_actions'] ?? 0); ?> urgent action(s) - <?php echo $h((string) ($snapshot['created_at'] ?? '')); ?></span></div>
                        <?php endif; ?>
                        <div class="execution-note"><strong>Manual-first boundary</strong><span class="marketing-execution-detail"><?php echo $h((string) ($campaignConsole['guardrails']['message'] ?? 'No external execution.')); ?></span></div>
                    </div>
                </div>
                <div class="execution-grid">
                    <div class="content-card campaign-console-section">
                        <div class="premium-section-header"><h2>Decisions</h2><a class="btn-premium-secondary" href="marketing_decisions.php">Open Decisions</a></div>
                        <?php if (empty($campaignConsole['decisions'])): ?><div class="empty-state"><p>No open decisions are linked to this campaign.</p></div><?php else: foreach ((array) $campaignConsole['decisions'] as $decision): ?>
                            <div class="execution-row"><div><a class="execution-title" href="marketing_decisions.php"><?php echo $h((string) $decision['title']); ?></a><div class="execution-meta"><span><?php echo $h($labelize((string) $decision['priority'])); ?></span><span><?php echo $h($labelize((string) $decision['decision_type'])); ?></span></div></div><span class="execution-chip"><?php echo $h($labelize((string) $decision['decision_status'])); ?></span></div>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="content-card campaign-console-section">
                        <div class="premium-section-header"><h2>Media Production</h2><a class="btn-premium-secondary" href="marketing_creative.php?campaign_id=<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>#media-production-workflow">Open Creative</a></div>
                        <?php if (empty($campaignConsole['media_production_work'])): ?><div class="empty-state"><p>No active media production work is linked to this campaign.</p></div><?php else: foreach ((array) $campaignConsole['media_production_work'] as $workItem): ?>
                            <div class="execution-row">
                                <div>
                                    <a class="execution-title" href="marketing_creative.php?campaign_id=<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>#media-production-workflow"><?php echo $h((string) ($workItem['title'] ?? 'Media production work')); ?></a>
                                    <div class="execution-meta"><span><?php echo $h($labelize((string) ($workItem['status'] ?? 'requested'))); ?></span><span><?php echo $h($labelize((string) ($workItem['priority'] ?? 'normal'))); ?></span><span><?php echo (int) ($workItem['readiness_json']['score'] ?? 0); ?>% ready</span></div>
                                </div>
                                <span class="execution-chip"><?php echo !empty($workItem['is_overdue']) ? 'Overdue' : $h((string) ($workItem['due_at'] ?? 'Open')); ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="execution-grid">
                    <div class="content-card campaign-console-section">
                        <div class="premium-section-header"><h2>Checklists And Packs</h2></div>
                        <div class="execution-note"><strong>Launch Checklists</strong><span class="marketing-execution-detail"><?php echo (int) ($campaignConsole['counts']['launch_checklists'] ?? 0); ?> linked checklist(s)</span></div>
                        <div class="execution-note"><strong>Operator Export Packs</strong><span class="marketing-execution-detail"><?php echo (int) ($campaignConsole['counts']['operator_export_packs'] ?? 0); ?> saved pack(s)</span></div>
                        <div class="decision-actions marketing-execution-actions"><a class="btn-premium-secondary" href="marketing_launch_checklists.php?campaign_id=<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>">Launch Checklists</a><a class="btn-premium-secondary" href="marketing_operator_export_packs.php?campaign_id=<?php echo (int) ($campaignConsole['campaign_id'] ?? 0); ?>">Export Packs</a></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="execution-kpis">
            <div class="execution-kpi"><span>Ready To Execute</span><strong><?php echo (int) ($counts['ready_to_execute'] ?? 0); ?></strong></div>
            <div class="execution-kpi"><span>Blocked</span><strong><?php echo (int) ($counts['blocked'] ?? 0); ?></strong></div>
            <div class="execution-kpi"><span>Needs Review</span><strong><?php echo (int) ($counts['needs_review'] ?? 0); ?></strong></div>
            <div class="execution-kpi"><span>Live Ready</span><strong><?php echo (int) ($liveExecution['counts']['ready_live_queue_items'] ?? 0); ?></strong></div>
        </div>

        <div class="content-card marketing-execution-section">
            <div class="premium-section-header"><h2>Recommended Next Actions</h2><p>Highest leverage fixes before a campaign can safely move forward.</p></div>
            <div class="execution-grid marketing-execution-grid-flush">
                <?php foreach ((array) ($summary['next_actions'] ?? []) as $action): ?>
                    <a class="execution-note" href="<?php echo $h($action['href'] ?? '#'); ?>">
                        <strong><?php echo $h($action['label'] ?? 'Next action'); ?></strong>
                        <span class="marketing-execution-detail"><?php echo $h($action['reason'] ?? 'Review this execution item.'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="execution-grid">
            <div class="content-card">
                <div class="premium-section-header"><h2>Ready To Execute</h2><p>Dry-run, manual-ready, and policy-gated live items. Live adapters run only after preflight, approval, and RUN LIVE confirmation.</p></div>
                <?php
                $readyRows = array_merge(
                    (array) ($summary['ready_to_execute']['execution_queue'] ?? []),
                    (array) ($summary['ready_to_execute']['launch_control'] ?? []),
                    (array) ($summary['ready_to_execute']['channel_exports'] ?? [])
                );
                $renderRows($readyRows, static function (array $row) use ($labelize): array {
                    if (isset($row['execution_type'])) {
                        return [
                            'title' => $labelize((string) $row['execution_type']) . ' execution',
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['status'] ?? 'ready')),
                            'meta' => [$labelize((string) ($row['execution_mode'] ?? 'dry_run')), (string) ($row['connector_name'] ?? 'No connector')],
                        ];
                    }
                    if (isset($row['launch_name'])) {
                        return [
                            'title' => (string) $row['launch_name'],
                            'href' => 'marketing_launch_control.php?status=ready',
                            'status' => $labelize((string) ($row['status'] ?? 'ready')),
                            'meta' => ['Launch control', ((int) ($row['readiness_score'] ?? 0)) . '% readiness'],
                        ];
                    }
                    return [
                        'title' => (string) ($row['content_title'] ?? 'Channel export bundle'),
                        'href' => 'marketing_channel_exports.php',
                        'status' => $labelize((string) ($row['status'] ?? 'ready')),
                        'meta' => ['Channel export', $labelize((string) ($row['channel'] ?? 'other'))],
                    ];
                }, 'Nothing is execution-ready yet.');
                ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Blocked Work</h2><p>Execution blockers that need operator attention before manual launch or export.</p></div>
                <?php
                $blockedRows = array_merge(
                    (array) ($summary['blocked']['execution_queue'] ?? []),
                    (array) ($summary['blocked']['launch'] ?? []),
                    (array) ($summary['blocked']['connectors'] ?? [])
                );
                $renderRows($blockedRows, static function (array $row) use ($labelize): array {
                    if (isset($row['execution_type'])) {
                        return [
                            'title' => $labelize((string) $row['execution_type']) . ' execution',
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['status'] ?? 'blocked')),
                            'meta' => [$labelize((string) ($row['execution_mode'] ?? 'dry_run')), (string) ($row['connector_name'] ?? 'No connector')],
                        ];
                    }
                    if (isset($row['connector_type'])) {
                        return [
                            'title' => (string) ($row['name'] ?? 'Connector'),
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['setup_status'] ?? $row['status'] ?? 'blocked')),
                            'meta' => ['Connector', $labelize((string) ($row['connector_type'] ?? 'other')), ((int) ($row['readiness_score'] ?? 0)) . '% ready'],
                        ];
                    }
                    return [
                        'title' => (string) ($row['launch_name'] ?? 'Launch readiness item'),
                        'href' => 'marketing_launch_control.php?status=blocked',
                        'status' => $labelize((string) ($row['status'] ?? 'blocked')),
                        'meta' => ['Launch', ((int) ($row['readiness_score'] ?? 0)) . '% readiness'],
                    ];
                }, 'No blocked execution work is visible.');
                ?>
            </div>
        </div>

        <div class="execution-grid">
            <div class="content-card">
                <div class="premium-section-header"><h2>Needs Review</h2><p>Items waiting for approval, connector setup review, or email readiness cleanup.</p></div>
                <?php
                $reviewRows = array_merge(
                    (array) ($summary['needs_review']['execution_queue'] ?? []),
                    (array) ($summary['needs_review']['connectors'] ?? []),
                    (array) ($summary['needs_review']['email_runs'] ?? [])
                );
                $renderRows($reviewRows, static function (array $row) use ($labelize): array {
                    if (isset($row['execution_type'])) {
                        return [
                            'title' => $labelize((string) $row['execution_type']) . ' execution',
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['status'] ?? 'pending')),
                            'meta' => ['Queue', $labelize((string) ($row['execution_mode'] ?? 'dry_run'))],
                        ];
                    }
                    if (isset($row['connector_type'])) {
                        return [
                            'title' => (string) ($row['name'] ?? 'Connector'),
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['setup_status'] ?? 'not_started')),
                            'meta' => ['Connector', $labelize((string) ($row['connector_type'] ?? 'other')), ((int) ($row['readiness_score'] ?? 0)) . '% ready'],
                        ];
                    }
                    return [
                        'title' => (string) ($row['name'] ?? 'Email run'),
                        'href' => 'marketing_email_runs.php',
                        'status' => $labelize((string) ($row['status'] ?? 'draft')),
                        'meta' => ['Email run', (string) ($row['subject'] ?? 'No subject')],
                    ];
                }, 'No review-needed execution work is visible.');
                ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Manual Export Queue</h2><p>Distribution posts, channel bundles, and email runs that still require human export/publishing steps.</p></div>
                <?php
                $exportRows = array_merge(
                    (array) ($summary['manual_exports']['distribution_posts'] ?? []),
                    (array) ($summary['manual_exports']['channel_exports'] ?? []),
                    (array) ($summary['manual_exports']['email_runs'] ?? [])
                );
                $renderRows($exportRows, static function (array $row) use ($labelize): array {
                    if (isset($row['bundle_type'])) {
                        return [
                            'title' => (string) ($row['content_title'] ?? 'Channel export bundle'),
                            'href' => 'marketing_channel_exports.php',
                            'status' => $labelize((string) ($row['status'] ?? 'draft')),
                            'meta' => ['Bundle', $labelize((string) ($row['channel'] ?? 'other'))],
                        ];
                    }
                    if (isset($row['subject']) || isset($row['recipient_count'])) {
                        return [
                            'title' => (string) ($row['name'] ?? 'Email run'),
                            'href' => 'marketing_email_runs.php',
                            'status' => $labelize((string) ($row['status'] ?? 'draft')),
                            'meta' => ['Email export', ((int) ($row['recipient_count'] ?? 0)) . ' recipients'],
                        ];
                    }
                    return [
                        'title' => (string) ($row['content_title'] ?? 'Distribution post'),
                        'href' => 'marketing_distribution.php',
                        'status' => $labelize((string) ($row['status'] ?? 'draft')),
                        'meta' => ['Distribution', $labelize((string) ($row['channel'] ?? 'other')), (string) ($row['scheduled_at'] ?? 'Unscheduled')],
                    ];
                }, 'No manual export work is queued.');
                ?>
            </div>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><h2>Live Execution Readiness</h2><p>The live path is policy-gated and confirmation-gated. A queue item can run live only after workspace policy, ready connector, verified secret reference, passed connector preflight, explicit approval, final RUN LIVE confirmation, and an implemented adapter pass together.</p></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>Policy</strong><span class="marketing-execution-detail"><?php echo !empty($liveExecution['policy']['live_execution_enabled']) ? 'Enabled' : 'Disabled'; ?> - <?php echo $h($labelize((string) ($liveExecution['status'] ?? 'disabled'))); ?></span></div>
                <div class="execution-note"><strong>Emergency stop</strong><span class="marketing-execution-detail"><?php echo !empty($liveExecution['policy']['emergency_paused']) ? 'Active' : 'Clear'; ?><?php echo !empty($liveExecution['policy']['emergency_pause_reason']) ? ' - ' . $h((string) $liveExecution['policy']['emergency_pause_reason']) : ''; ?></span></div>
                <div class="execution-note"><strong>Live-capable connectors</strong><span class="marketing-execution-detail"><?php echo (int) ($liveExecution['counts']['live_capable_connectors'] ?? 0); ?> total, <?php echo (int) ($liveExecution['counts']['verified_live_connectors'] ?? 0); ?> verified</span></div>
                <div class="execution-note"><strong>Ready live queue</strong><span class="marketing-execution-detail"><?php echo (int) ($liveExecution['counts']['ready_live_queue_items'] ?? 0); ?> ready, <?php echo (int) ($liveExecution['counts']['blocked_live_queue_items'] ?? 0); ?> blocked</span></div>
                <div class="execution-note"><strong>Scheduled retries</strong><span class="marketing-execution-detail"><?php echo (int) ($liveExecution['counts']['scheduled_live_retries'] ?? $liveDispatcherHealth['counts']['scheduled_retries'] ?? 0); ?> waiting for backoff windows</span></div>
                <div class="execution-note is-<?php echo $h($liveLauncherStatusClass); ?>"><strong>Launcher health</strong><span class="marketing-execution-detail"><?php echo $h($labelize($liveLauncherStatus)); ?> - <?php echo $h((string) ($liveExecutionLauncherHealth['message'] ?? 'Launcher health unavailable.')); ?></span></div>
                <div class="execution-note is-<?php echo $h($liveWebhookStatusClass); ?>"><strong>Outbound webhook health</strong><span class="marketing-execution-detail"><?php echo $h($labelize($liveWebhookStatus)); ?> - <?php echo $h((string) ($liveWebhookHealth['message'] ?? 'Webhook health unavailable.')); ?></span></div>
                <div class="execution-note"><strong>Outcome sync</strong><span class="marketing-execution-detail"><?php echo (int) ($liveOutcomeSync['synced'] ?? 0); ?> live queue item(s) reconciled from downstream evidence</span></div>
                <div class="execution-note"><strong>Email handoffs today</strong><span class="marketing-execution-detail"><?php echo (int) ($liveExecution['counts']['queued_live_email_handoffs_today'] ?? 0); ?> queued through CRM email queue</span></div>
                <div class="execution-note"><strong>Channel proofs</strong><span class="marketing-execution-detail"><?php echo count($liveChannelDeliveryProofs); ?> recent SMS/WhatsApp proof snapshot(s)</span></div>
            </div>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Live Execution Orchestrator</h2><p>Runs the safe worker chain in order: confirmed live dispatcher, email handoff worker, SMS/WhatsApp channel worker, then outcome sync. It keeps every existing approval, preflight, throttle, lease, and RUN LIVE guard in place.</p></div><span class="execution-chip"><?php echo $h($labelize((string) ($liveOrchestratorSchedule['status'] ?? 'planned'))); ?></span></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>CLI/Cron command</strong><span class="marketing-execution-detail"><code><?php echo $h($orchestratorCommand); ?></code></span></div>
                <div class="execution-note"><strong>Production launcher</strong><span class="marketing-execution-detail">Schedule the unified launcher for live execution. Use <code>--readiness</code> for a no-send health check and <code>--json</code> for cron logs.</span></div>
                <div class="execution-note"><strong>Worker chain</strong><span class="marketing-execution-detail">Dispatcher -> Email handoffs -> SMS/WhatsApp handoffs -> Outcome sync</span></div>
                <div class="execution-note"><strong>Schedule</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveOrchestratorSchedule['status'] ?? 'planned'))); ?> - every <?php echo (int) ($liveOrchestratorSchedule['expected_interval_minutes'] ?? 5); ?> minute(s), stale after <?php echo (int) ($liveOrchestratorSchedule['max_stale_minutes'] ?? 30); ?> minute(s).</span></div>
                <div class="execution-note"><strong>Max per run</strong><span class="marketing-execution-detail"><?php echo (int) ($liveOrchestratorSchedule['max_handoffs_per_run'] ?? 25); ?> live item(s) / handoff(s)</span></div>
                <div class="execution-note"><strong>Last confirmed</strong><span class="marketing-execution-detail"><?php echo $h((string) (($liveOrchestratorSchedule['last_confirmed_at'] ?? null) ?: 'Not confirmed')); ?></span></div>
                <div class="execution-note is-<?php echo $h($liveLauncherStatusClass); ?>"><strong>Last launcher run</strong><span class="marketing-execution-detail"><?php echo $h((string) (($liveExecutionLauncherHealth['last_run_at'] ?? null) ?: 'Not recorded')); ?><?php if (($liveExecutionLauncherHealth['last_run_age_minutes'] ?? null) !== null): ?> (<?php echo (int) $liveExecutionLauncherHealth['last_run_age_minutes']; ?> minutes ago)<?php endif; ?></span></div>
                <div class="execution-note"><strong>Emergency pause</strong><span class="marketing-execution-detail"><?php echo !empty($liveOrchestratorSchedule['emergency_paused']) ? 'Active' : 'Clear'; ?><?php echo !empty($liveOrchestratorSchedule['emergency_pause_reason']) ? ' - ' . $h((string) $liveOrchestratorSchedule['emergency_pause_reason']) : ''; ?></span></div>
            </div>
            <div class="execution-note is-<?php echo $h($liveLauncherStatusClass); ?> marketing-execution-section">
                <strong><?php echo $h((string) ($liveExecutionLauncherHealth['message'] ?? 'Launcher health has not been checked yet.')); ?></strong>
                <span class="marketing-execution-detail">
                    Recent 24h: <?php echo (int) ($liveExecutionLauncherHealth['counts']['recent_total'] ?? 0); ?> launcher run(s),
                    <?php echo (int) ($liveExecutionLauncherHealth['counts']['recent_completed'] ?? 0); ?> completed,
                    <?php echo (int) ($liveExecutionLauncherHealth['counts']['recent_warnings'] ?? 0); ?> warning,
                    <?php echo (int) ($liveExecutionLauncherHealth['counts']['failed'] ?? 0); ?> failed.
                    Waiting live work: <?php echo (int) ($liveExecutionLauncherHealth['counts']['queued'] ?? 0); ?>.
                </span>
                <?php if (!empty($liveExecutionLauncherHealth['recommended_actions'])): ?>
                    <span class="marketing-execution-detail-relaxed"><?php echo $h(implode(' ', array_map('strval', (array) $liveExecutionLauncherHealth['recommended_actions']))); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($canManageMarketing): ?>
                <form method="POST" class="marketing-execution-form-grid marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_live_orchestrator_schedule">
                    <label>Status
                        <select class="form-control" name="status">
                            <?php foreach (Marketing::LIVE_WORKER_SCHEDULE_STATUSES as $scheduleStatus): ?><option value="<?php echo $h($scheduleStatus); ?>" <?php echo (string) ($liveOrchestratorSchedule['status'] ?? 'planned') === $scheduleStatus ? 'selected' : ''; ?>><?php echo $h($labelize($scheduleStatus)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Interval Minutes<input class="form-control" type="number" min="1" max="1440" name="expected_interval_minutes" value="<?php echo (int) ($liveOrchestratorSchedule['expected_interval_minutes'] ?? 5); ?>"></label>
                    <label>Stale Minutes<input class="form-control" type="number" min="1" max="1440" name="max_stale_minutes" value="<?php echo (int) ($liveOrchestratorSchedule['max_stale_minutes'] ?? 30); ?>"></label>
                    <label>Max Items/Run<input class="form-control" type="number" min="1" max="250" name="max_queue_items_per_run" value="<?php echo (int) ($liveOrchestratorSchedule['max_handoffs_per_run'] ?? 25); ?>"></label>
                    <label class="marketing-execution-label-command">Command<input class="form-control" type="text" name="command" value="<?php echo $h($orchestratorCommand); ?>"></label>
                    <button class="btn-premium-primary" type="submit">Save Orchestrator Schedule</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="run_live_orchestrator">
                    <label class="marketing-execution-label-limit">Manual limit
                        <input class="form-control" type="number" min="1" max="250" name="limit_count" value="<?php echo (int) ($liveOrchestratorSchedule['max_handoffs_per_run'] ?? 25); ?>">
                    </label>
                    <button class="btn-premium-secondary" type="submit">Run Live Orchestrator</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <?php if (!empty($liveOrchestratorSchedule['emergency_paused'])): ?>
                        <input type="hidden" name="action" value="resume_live_orchestrator">
                        <button class="btn-premium-primary" type="submit">Resume Orchestrator</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="pause_live_orchestrator">
                        <label class="marketing-execution-label-pause">Orchestrator pause reason
                            <input class="form-control" type="text" name="pause_reason" value="Manual emergency pause for live orchestrator">
                        </label>
                        <button class="btn-premium-secondary" type="submit">Pause Orchestrator</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (empty($liveOrchestrationRuns)): ?>
                <div class="empty-state"><p>No live orchestration runs have been recorded yet.</p></div>
            <?php else: foreach ($liveOrchestrationRuns as $orchestrationRun): ?>
                <div class="execution-row">
                    <div>
                        <strong>Orchestrator run #<?php echo (int) $orchestrationRun['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($orchestrationRun['status'] ?? 'running'))); ?></span>
                            <span><?php echo $h($labelize((string) ($orchestrationRun['source'] ?? 'manual'))); ?></span>
                            <span><?php echo (int) ($orchestrationRun['processed_count'] ?? 0); ?> processed</span>
                            <span><?php echo (int) ($orchestrationRun['succeeded_count'] ?? 0); ?> succeeded</span>
                            <span><?php echo (int) ($orchestrationRun['blocked_count'] ?? 0); ?> blocked</span>
                            <span><?php echo (int) ($orchestrationRun['failed_count'] ?? 0); ?> failed</span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($orchestrationRun['status'] ?? 'running'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Launcher Evidence</h2><p>Recent cron/CLI launcher invocations for the unified live execution command. These records prove the scheduler is reaching this workspace even when the orchestrator blocks safely.</p></div>
            <?php if (empty($liveExecutionLauncherRuns)): ?>
                <div class="empty-state"><p>No live execution launcher runs have been recorded yet.</p></div>
            <?php else: foreach ($liveExecutionLauncherRuns as $launcherRun): ?>
                <?php
                    $launcherResult = (array) ($launcherRun['result_json'] ?? []);
                    $readinessSummary = (array) ($launcherResult['readiness_summary'] ?? []);
                    $componentStatuses = (array) ($readinessSummary['component_statuses'] ?? []);
                    $readinessStatus = (string) ($readinessSummary['status'] ?? '');
                ?>
                <div class="execution-row">
                    <div>
                        <strong>Launcher run #<?php echo (int) $launcherRun['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($launcherRun['status'] ?? 'running'))); ?></span>
                            <span><?php echo $h($labelize((string) ($launcherRun['source'] ?? 'cron'))); ?></span>
                            <span><?php echo !empty($launcherRun['readiness_only']) ? 'Readiness check' : 'Execution run'; ?></span>
                            <span>Invocation <?php echo $h((string) ($launcherRun['invocation_uuid'] ?? '')); ?></span>
                            <span><?php echo $h((string) ($launcherRun['started_at'] ?? '')); ?></span>
                            <?php if (!empty($launcherRun['orchestration_run_id'])): ?><span>Orchestrator #<?php echo (int) $launcherRun['orchestration_run_id']; ?></span><?php endif; ?>
                            <span><?php echo (int) ($launcherRun['processed_count'] ?? 0); ?> processed</span>
                            <span><?php echo (int) ($launcherRun['blocked_count'] ?? 0); ?> blocked</span>
                            <span><?php echo (int) ($launcherRun['failed_count'] ?? 0); ?> failed</span>
                        </div>
                        <?php if ($readinessStatus !== ''): ?>
                            <div class="execution-meta">
                                <span>Readiness: <?php echo $h($labelize($readinessStatus)); ?></span>
                                <span>Ready: <?php echo !empty($readinessSummary['ready_for_live_execution']) ? 'Yes' : 'No'; ?></span>
                                <?php foreach ($componentStatuses as $componentKey => $componentStatus): ?>
                                    <span><?php echo $h($labelize((string) $componentKey)); ?> <?php echo $h($labelize((string) $componentStatus)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($readinessSummary['blockers'])): ?>
                            <div class="execution-meta">Blockers: <?php echo $h(implode('; ', array_slice((array) $readinessSummary['blockers'], 0, 3))); ?></div>
                        <?php elseif (!empty($readinessSummary['warnings'])): ?>
                            <div class="execution-meta">Warnings: <?php echo $h(implode('; ', array_slice((array) $readinessSummary['warnings'], 0, 3))); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($launcherRun['error_message'])): ?><div class="execution-meta"><?php echo $h((string) $launcherRun['error_message']); ?></div><?php endif; ?>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($launcherRun['status'] ?? 'running'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Outcome Reconciliation Events</h2><p>Idempotent normalized evidence from email, SMS, WhatsApp, webhooks, landing publication, and queue state.</p></div>
            <?php if (empty($liveOutcomeReconciliationEvents)): ?>
                <div class="empty-state"><p>No live outcome reconciliation events have been recorded yet.</p></div>
            <?php else: foreach ($liveOutcomeReconciliationEvents as $event): ?>
                <div class="execution-row">
                    <div>
                        <strong>Queue #<?php echo (int) ($event['queue_id'] ?? 0); ?> - <?php echo $h($labelize((string) ($event['source_type'] ?? 'queue'))); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($event['normalized_status'] ?? 'unknown'))); ?></span>
                            <span><?php echo $h((string) ($event['reconciled_at'] ?? '')); ?></span>
                            <?php if (!empty($event['connector_name'])): ?><span><?php echo $h((string) $event['connector_name']); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($event['queue_outcome_status'] ?? 'unknown'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header">
                <div>
                    <h2>Live Proof Packs</h2>
                    <p>Manager/admin evidence bundles that combine policy, connector readiness, approval, confirmation hash, adapter attempts, recovery, consent, and outcomes without exposing secrets or raw recipient data.</p>
                </div>
                <span class="execution-chip"><?php echo (int) ($liveProofReportSummary['proof_packs']['total'] ?? 0); ?> pack(s)</span>
            </div>
            <div class="execution-grid">
                <div class="execution-note"><strong>Live volume</strong><span class="marketing-execution-detail"><?php echo (int) ($liveProofReportSummary['live_volume'] ?? 0); ?> live queue item(s)</span></div>
                <div class="execution-note"><strong>Failures / blocked</strong><span class="marketing-execution-detail"><?php echo (int) ($liveProofReportSummary['failed_or_blocked'] ?? 0); ?> queue item(s), <?php echo (int) ($liveProofReportSummary['suppression_blocks'] ?? 0); ?> suppression block(s)</span></div>
                <div class="execution-note"><strong>Worker health</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveProofReportSummary['worker_health']['status'] ?? 'unknown'))); ?> - <?php echo (int) ($liveProofReportSummary['worker_health']['ready_components'] ?? 0); ?> ready component(s)</span></div>
                <div class="execution-note"><strong>Safety</strong><span class="marketing-execution-detail">Proof packs exclude raw secrets, tokens, stack traces, confirmation phrases, and private recipient values.</span></div>
            </div>
            <?php if ($canManageMarketing): ?>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="create_live_proof_pack">
                    <label class="marketing-execution-label-queue">Queue ID
                        <input class="form-control" type="number" min="1" name="queue_id" value="<?php echo (int) ($proofPackCandidateQueues[0]['id'] ?? 0); ?>">
                    </label>
                    <button class="btn-premium-secondary" type="submit">Generate Proof Pack</button>
                </form>
                <?php if (!empty($proofPackCandidateQueues)): ?>
                    <div class="execution-note marketing-execution-section">
                        <strong>Candidate queue items</strong>
                        <div class="execution-meta marketing-execution-detail">
                            <?php foreach ($proofPackCandidateQueues as $candidateQueue): ?>
                                <form method="POST" class="marketing-execution-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="create_live_proof_pack">
                                    <input type="hidden" name="queue_id" value="<?php echo (int) ($candidateQueue['id'] ?? 0); ?>">
                                    <button class="btn-premium-secondary" type="submit">Pack #<?php echo (int) ($candidateQueue['id'] ?? 0); ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="execution-note marketing-execution-section"><strong>Read only</strong><span class="marketing-execution-detail">Only Marketing managers can generate new live proof packs.</span></div>
            <?php endif; ?>
            <?php if (empty($liveProofPacks)): ?>
                <div class="empty-state"><p>No live proof packs have been generated yet.</p></div>
            <?php else: foreach ($liveProofPacks as $proofPack): ?>
                <?php $packSummary = (array) ($proofPack['summary_json'] ?? []); ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h((string) ($proofPack['title'] ?? 'Live proof pack')); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($proofPack['proof_status'] ?? 'unknown'))); ?></span>
                            <span><?php echo $h($labelize((string) ($proofPack['normalized_outcome_status'] ?? 'unknown'))); ?></span>
                            <span>Queue #<?php echo (int) ($proofPack['queue_id'] ?? 0); ?></span>
                            <?php if (!empty($proofPack['connector_name'])): ?><span><?php echo $h((string) $proofPack['connector_name']); ?></span><?php endif; ?>
                            <span><?php echo $h((string) ($proofPack['generated_at'] ?? '')); ?></span>
                        </div>
                        <div class="execution-meta">
                            <span><?php echo (int) ($packSummary['counts']['adapter_attempts'] ?? 0); ?> adapter attempt(s)</span>
                            <span><?php echo (int) ($packSummary['counts']['email_delivery_proofs'] ?? 0) + (int) ($packSummary['counts']['channel_delivery_proofs'] ?? 0); ?> delivery proof(s)</span>
                            <span><?php echo (int) ($packSummary['counts']['reconciliation_events'] ?? 0); ?> reconciliation event(s)</span>
                            <span><?php echo (int) ($packSummary['counts']['recovery_events'] ?? 0); ?> recovery event(s)</span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($proofPack['status'] ?? 'ready'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Live Outcome Sync Worker</h2><p>Reconciles live queue outcomes from downstream evidence, webhook attempts, publication state, and email handoff proof without requiring this page to be opened.</p></div><span class="execution-chip"><?php echo $h($labelize((string) ($liveOutcomeSyncSchedule['status'] ?? 'planned'))); ?></span></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>CLI/Cron command</strong><span class="marketing-execution-detail"><code><?php echo $h($outcomeSyncCommand); ?></code></span></div>
                <div class="execution-note"><strong>Last page sync</strong><span class="marketing-execution-detail"><?php echo (int) ($liveOutcomeSync['synced'] ?? 0); ?> live queue item(s) reconciled while loading this page.</span></div>
                <div class="execution-note"><strong>Schedule</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveOutcomeSyncSchedule['status'] ?? 'planned'))); ?> - every <?php echo (int) ($liveOutcomeSyncSchedule['expected_interval_minutes'] ?? 5); ?> minute(s), stale after <?php echo (int) ($liveOutcomeSyncSchedule['max_stale_minutes'] ?? 30); ?> minute(s).</span></div>
                <div class="execution-note"><strong>Max per run</strong><span class="marketing-execution-detail"><?php echo (int) ($liveOutcomeSyncSchedule['max_handoffs_per_run'] ?? 250); ?> live queue outcome(s)</span></div>
                <div class="execution-note"><strong>Last confirmed</strong><span class="marketing-execution-detail"><?php echo $h((string) (($liveOutcomeSyncSchedule['last_confirmed_at'] ?? null) ?: 'Not confirmed')); ?></span></div>
                <div class="execution-note"><strong>Emergency pause</strong><span class="marketing-execution-detail"><?php echo !empty($liveOutcomeSyncSchedule['emergency_paused']) ? 'Active' : 'Clear'; ?><?php echo !empty($liveOutcomeSyncSchedule['emergency_pause_reason']) ? ' - ' . $h((string) $liveOutcomeSyncSchedule['emergency_pause_reason']) : ''; ?></span></div>
            </div>
            <?php if ($canManageMarketing): ?>
                <form method="POST" class="marketing-execution-form-grid marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_live_outcome_sync_schedule">
                    <label>Status
                        <select class="form-control" name="status">
                            <?php foreach (Marketing::LIVE_WORKER_SCHEDULE_STATUSES as $scheduleStatus): ?><option value="<?php echo $h($scheduleStatus); ?>" <?php echo (string) ($liveOutcomeSyncSchedule['status'] ?? 'planned') === $scheduleStatus ? 'selected' : ''; ?>><?php echo $h($labelize($scheduleStatus)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Interval Minutes<input class="form-control" type="number" min="1" max="1440" name="expected_interval_minutes" value="<?php echo (int) ($liveOutcomeSyncSchedule['expected_interval_minutes'] ?? 5); ?>"></label>
                    <label>Stale Minutes<input class="form-control" type="number" min="1" max="1440" name="max_stale_minutes" value="<?php echo (int) ($liveOutcomeSyncSchedule['max_stale_minutes'] ?? 30); ?>"></label>
                    <label>Max Items/Run<input class="form-control" type="number" min="1" max="1000" name="max_queue_items_per_run" value="<?php echo (int) ($liveOutcomeSyncSchedule['max_handoffs_per_run'] ?? 250); ?>"></label>
                    <label class="marketing-execution-label-command">Command<input class="form-control" type="text" name="command" value="<?php echo $h($outcomeSyncCommand); ?>"></label>
                    <button class="btn-premium-primary" type="submit">Save Outcome Sync Schedule</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="run_live_outcome_sync">
                    <label class="marketing-execution-label-limit">Manual limit
                        <input class="form-control" type="number" min="1" max="1000" name="limit_count" value="<?php echo (int) ($liveOutcomeSyncSchedule['max_handoffs_per_run'] ?? 250); ?>">
                    </label>
                    <button class="btn-premium-secondary" type="submit">Run Outcome Sync</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <?php if (!empty($liveOutcomeSyncSchedule['emergency_paused'])): ?>
                        <input type="hidden" name="action" value="resume_live_outcome_sync">
                        <button class="btn-premium-primary" type="submit">Resume Outcome Sync</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="pause_live_outcome_sync">
                        <label class="marketing-execution-label-pause">Outcome sync pause reason
                            <input class="form-control" type="text" name="pause_reason" value="Manual emergency pause for live outcome sync">
                        </label>
                        <button class="btn-premium-secondary" type="submit">Pause Outcome Sync</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (empty($liveOutcomeSyncRuns)): ?>
                <div class="empty-state"><p>No live outcome sync worker runs have been recorded yet.</p></div>
            <?php else: foreach ($liveOutcomeSyncRuns as $syncRun): ?>
                <div class="execution-row">
                    <div>
                        <strong>Outcome sync run #<?php echo (int) $syncRun['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($syncRun['status'] ?? 'running'))); ?></span>
                            <span><?php echo $h($labelize((string) ($syncRun['source'] ?? 'manual'))); ?></span>
                            <span><?php echo (int) ($syncRun['processed_count'] ?? 0); ?> processed</span>
                            <span><?php echo (int) ($syncRun['delivered_count'] ?? 0); ?> delivered</span>
                            <span><?php echo (int) ($syncRun['failed_count'] ?? 0); ?> failed</span>
                            <span><?php echo (int) ($syncRun['blocked_count'] ?? 0); ?> blocked</span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($syncRun['status'] ?? 'running'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Live Queue Dispatcher</h2><p>Runs due live queue items that managers already approved and confirmed for dispatch. The dispatcher never bypasses policy, preflight, RUN LIVE evidence, idempotency, or emergency stop.</p></div><span class="execution-chip is-<?php echo $h($liveDispatcherStatusClass); ?>"><?php echo $h($labelize($liveDispatcherStatus)); ?></span></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>CLI/Cron command</strong><span class="marketing-execution-detail"><code><?php echo $h($dispatcherCommand); ?></code></span></div>
                <div class="execution-note"><strong>Dispatch source</strong><span class="marketing-execution-detail">Use <code>--source=cron</code> for scheduled jobs and <code>--json</code> for machine-readable logs.</span></div>
                <div class="execution-note"><strong>Queue scope</strong><span class="marketing-execution-detail">Only <code>live_dispatch_status=confirmed</code> and due live items are eligible.</span></div>
                <div class="execution-note"><strong>Due confirmed</strong><span class="marketing-execution-detail"><?php echo (int) ($liveDispatcherHealth['counts']['due_confirmed'] ?? 0); ?> due, <?php echo (int) ($liveDispatcherHealth['counts']['stale_confirmed'] ?? 0); ?> stale</span></div>
                <div class="execution-note"><strong>Retry backoff</strong><span class="marketing-execution-detail"><?php echo (int) ($liveDispatcherHealth['counts']['scheduled_retries'] ?? 0); ?> scheduled retry item(s) are waiting for their retry window.</span></div>
                <div class="execution-note"><strong>Schedule</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveDispatcherSchedule['status'] ?? 'planned'))); ?> - every <?php echo (int) ($liveDispatcherSchedule['expected_interval_minutes'] ?? 5); ?> minute(s)</span></div>
                <div class="execution-note"><strong>Max per run</strong><span class="marketing-execution-detail"><?php echo (int) ($liveDispatcherSchedule['max_handoffs_per_run'] ?? 10); ?> due item(s)</span></div>
                <div class="execution-note"><strong>Active Lease</strong><span class="marketing-execution-detail"><?php echo !empty($liveDispatcherLease['active']) ? 'Running' : 'Clear'; ?><?php if (!empty($liveDispatcherLease['dispatch_run_id'])): ?> - <?php echo !empty($liveDispatcherLease['active']) ? 'dispatch run #' : 'last dispatch run #'; ?><?php echo (int) $liveDispatcherLease['dispatch_run_id']; ?><?php endif; ?><?php echo !empty($liveDispatcherLease['active']) && !empty($liveDispatcherLease['expires_at']) ? ' until ' . $h((string) $liveDispatcherLease['expires_at']) : ''; ?></span></div>
            </div>
            <div class="execution-note is-<?php echo $h($liveDispatcherStatusClass); ?> marketing-execution-section">
                <strong><?php echo $h((string) ($liveDispatcherHealth['message'] ?? 'Dispatcher health has not been checked yet.')); ?></strong>
                <span class="marketing-execution-detail">
                    Last run: <?php echo $h((string) (($liveDispatcherHealth['last_run_at'] ?? null) ?: 'Not recorded')); ?>
                    <?php if (($liveDispatcherHealth['last_run_age_minutes'] ?? null) !== null): ?>
                        (<?php echo (int) $liveDispatcherHealth['last_run_age_minutes']; ?> minutes ago)
                    <?php endif; ?>
                    - blocked <?php echo (int) ($liveDispatcherHealth['counts']['blocked'] ?? 0); ?>,
                    failed <?php echo (int) ($liveDispatcherHealth['counts']['failed'] ?? 0); ?>.
                </span>
                <?php if (!empty($liveDispatcherSchedule['emergency_paused'])): ?>
                    <span class="marketing-execution-danger-text">
                        Dispatcher pause active since <?php echo $h((string) ($liveDispatcherSchedule['emergency_paused_at'] ?? '')); ?>:
                        <?php echo $h((string) ($liveDispatcherSchedule['emergency_pause_reason'] ?? 'No reason provided.')); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($liveDispatcherHealth['recommended_actions'])): ?>
                    <span class="marketing-execution-detail-relaxed"><?php echo $h(implode(' ', array_map('strval', (array) $liveDispatcherHealth['recommended_actions']))); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($canManageMarketing): ?>
                <form method="POST" class="marketing-execution-form-grid marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_live_dispatcher_schedule">
                    <label>Status
                        <select class="form-control" name="status">
                            <?php foreach (Marketing::LIVE_WORKER_SCHEDULE_STATUSES as $scheduleStatus): ?><option value="<?php echo $h($scheduleStatus); ?>" <?php echo (string) ($liveDispatcherSchedule['status'] ?? 'planned') === $scheduleStatus ? 'selected' : ''; ?>><?php echo $h($labelize($scheduleStatus)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Interval Minutes<input class="form-control" type="number" min="1" max="1440" name="expected_interval_minutes" value="<?php echo (int) ($liveDispatcherSchedule['expected_interval_minutes'] ?? 5); ?>"></label>
                    <label>Stale Minutes<input class="form-control" type="number" min="1" max="1440" name="max_stale_minutes" value="<?php echo (int) ($liveDispatcherSchedule['max_stale_minutes'] ?? 30); ?>"></label>
                    <label>Max Items/Run<input class="form-control" type="number" min="1" max="100" name="max_queue_items_per_run" value="<?php echo (int) ($liveDispatcherSchedule['max_handoffs_per_run'] ?? 10); ?>"></label>
                    <label class="marketing-execution-label-command">Command<input class="form-control" type="text" name="command" value="<?php echo $h($dispatcherCommand); ?>"></label>
                    <button class="btn-premium-primary" type="submit">Save Dispatcher Schedule</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <?php if (!empty($liveDispatcherSchedule['emergency_paused'])): ?>
                        <input type="hidden" name="action" value="resume_live_dispatcher">
                        <button class="btn-premium-primary" type="submit">Resume Live Dispatcher</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="pause_live_dispatcher">
                        <label class="marketing-execution-label-pause">Dispatcher pause reason
                            <input class="form-control" type="text" name="pause_reason" value="Manual emergency pause for live queue dispatcher">
                        </label>
                        <button class="btn-premium-secondary" type="submit">Pause Live Dispatcher</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Due Dispatch Queue</h2><p>Confirmed live items the dispatcher can pick up right now, still subject to policy, connector, preflight, approval, idempotency, and emergency-stop checks.</p></div>
            <?php if (empty($dueLiveDispatchQueue)): ?>
                <div class="empty-state"><p>No confirmed live queue items are due for dispatch.</p></div>
            <?php else: foreach ($dueLiveDispatchQueue as $queue): ?>
                <div class="execution-row">
                    <div>
                        <a class="execution-title" href="marketing_channel_exports.php"><?php echo $h($labelize((string) ($queue['execution_type'] ?? 'other'))); ?> live queue #<?php echo (int) ($queue['id'] ?? 0); ?></a>
                        <div class="execution-meta">
                            <span><?php echo $h((string) ($queue['connector_name'] ?? 'No connector')); ?></span>
                            <span><?php echo $h($labelize((string) ($queue['live_dispatch_status'] ?? 'confirmed'))); ?></span>
                            <span>Retry <?php echo $h($labelize((string) ($queue['live_retry_status'] ?? 'none'))); ?> <?php echo (int) ($queue['live_retry_count'] ?? 0); ?>/<?php echo (int) ($queue['live_max_retries'] ?? 0); ?></span>
                            <span><?php echo (int) ($queue['due_age_minutes'] ?? 0); ?> min due</span>
                            <span><?php echo $h((string) (($queue['scheduled_at'] ?? null) ?: 'Immediate')); ?></span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($queue['approval_status'] ?? 'approved'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <?php if (empty($liveDispatchRuns)): ?>
                <div class="empty-state"><p>No live dispatch runs have been recorded yet.</p></div>
            <?php else: foreach ($liveDispatchRuns as $dispatchRun): ?>
                <div class="execution-row">
                    <div>
                        <strong>Dispatch run #<?php echo (int) $dispatchRun['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($dispatchRun['status'] ?? 'running'))); ?></span>
                            <span><?php echo $h($labelize((string) ($dispatchRun['source'] ?? 'manual'))); ?></span>
                            <span><?php echo (int) ($dispatchRun['processed_count'] ?? 0); ?> processed</span>
                            <span><?php echo (int) ($dispatchRun['succeeded_count'] ?? 0); ?> succeeded</span>
                            <span><?php echo (int) ($dispatchRun['blocked_count'] ?? 0); ?> blocked</span>
                            <span><?php echo (int) ($dispatchRun['failed_count'] ?? 0); ?> failed</span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($dispatchRun['status'] ?? 'running'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Live Channel Worker</h2><p>Processes Marketing-created SMS and WhatsApp CRM queue handoffs through the existing channel queue processors. This page records controls and evidence; it does not bypass connector, approval, consent, suppression, or messaging-window guards.</p></div><span class="execution-chip"><?php echo $h($labelize((string) ($liveChannelWorkerSchedule['status'] ?? 'planned'))); ?></span></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>CLI/Cron command</strong><span class="marketing-execution-detail"><code><?php echo $h($channelWorkerCommand); ?></code></span></div>
                <div class="execution-note"><strong>Queued channel proofs</strong><span class="marketing-execution-detail"><?php echo count($liveChannelDeliveryProofs); ?> recent SMS/WhatsApp proof snapshot(s)</span></div>
                <div class="execution-note"><strong>Schedule</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveChannelWorkerSchedule['status'] ?? 'planned'))); ?> - every <?php echo (int) ($liveChannelWorkerSchedule['expected_interval_minutes'] ?? 5); ?> minute(s), stale after <?php echo (int) ($liveChannelWorkerSchedule['max_stale_minutes'] ?? 30); ?> minute(s).</span></div>
                <div class="execution-note"><strong>Max per run</strong><span class="marketing-execution-detail"><?php echo (int) ($liveChannelWorkerSchedule['max_handoffs_per_run'] ?? 25); ?> channel handoff(s)</span></div>
                <div class="execution-note"><strong>Last confirmed</strong><span class="marketing-execution-detail"><?php echo $h((string) (($liveChannelWorkerSchedule['last_confirmed_at'] ?? null) ?: 'Not confirmed')); ?></span></div>
                <div class="execution-note"><strong>Lease</strong><span class="marketing-execution-detail"><?php echo $h($labelize((string) ($liveChannelWorkerLease['status'] ?? 'not_acquired'))); ?><?php echo !empty($liveChannelWorkerLease['channel_worker_run_id']) ? ' on run #' . (int) $liveChannelWorkerLease['channel_worker_run_id'] : ''; ?><?php echo !empty($liveChannelWorkerLease['expires_at']) ? ' until ' . $h((string) $liveChannelWorkerLease['expires_at']) : ''; ?></span></div>
                <div class="execution-note"><strong>Throttle</strong><span class="marketing-execution-detail">Gap <?php echo (int) ($liveChannelWorkerSchedule['min_seconds_between_sends'] ?? 0); ?>s, hourly cap <?php echo (int) ($liveChannelWorkerSchedule['hourly_send_cap'] ?? 0); ?>, daily cap <?php echo (int) ($liveChannelWorkerSchedule['daily_send_cap'] ?? 0); ?>.</span></div>
                <div class="execution-note"><strong>Emergency pause</strong><span class="marketing-execution-detail"><?php echo !empty($liveChannelWorkerSchedule['emergency_paused']) ? 'Active' : 'Clear'; ?><?php echo !empty($liveChannelWorkerSchedule['emergency_pause_reason']) ? ' - ' . $h((string) $liveChannelWorkerSchedule['emergency_pause_reason']) : ''; ?></span></div>
            </div>
            <?php if ($canManageMarketing): ?>
                <form method="POST" class="marketing-execution-form-grid marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_live_channel_worker_schedule">
                    <label>Status
                        <select class="form-control" name="status">
                            <?php foreach (Marketing::LIVE_WORKER_SCHEDULE_STATUSES as $scheduleStatus): ?><option value="<?php echo $h($scheduleStatus); ?>" <?php echo (string) ($liveChannelWorkerSchedule['status'] ?? 'planned') === $scheduleStatus ? 'selected' : ''; ?>><?php echo $h($labelize($scheduleStatus)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Interval minutes<input class="form-control" type="number" min="1" max="1440" name="expected_interval_minutes" value="<?php echo (int) ($liveChannelWorkerSchedule['expected_interval_minutes'] ?? 5); ?>"></label>
                    <label>Stale minutes<input class="form-control" type="number" min="1" max="1440" name="max_stale_minutes" value="<?php echo (int) ($liveChannelWorkerSchedule['max_stale_minutes'] ?? 30); ?>"></label>
                    <label>Max per run<input class="form-control" type="number" min="1" max="200" name="max_handoffs_per_run" value="<?php echo (int) ($liveChannelWorkerSchedule['max_handoffs_per_run'] ?? 25); ?>"></label>
                    <label>Gap seconds<input class="form-control" type="number" min="0" max="3600" name="min_seconds_between_sends" value="<?php echo (int) ($liveChannelWorkerSchedule['min_seconds_between_sends'] ?? 0); ?>"></label>
                    <label>Hourly cap<input class="form-control" type="number" min="0" max="100000" name="hourly_send_cap" value="<?php echo (int) ($liveChannelWorkerSchedule['hourly_send_cap'] ?? 0); ?>"></label>
                    <label>Daily cap<input class="form-control" type="number" min="0" max="1000000" name="daily_send_cap" value="<?php echo (int) ($liveChannelWorkerSchedule['daily_send_cap'] ?? 0); ?>"></label>
                    <label class="marketing-execution-label-command">Command<input class="form-control" type="text" name="command" value="<?php echo $h($channelWorkerCommand); ?>"></label>
                    <button class="btn-premium-primary" type="submit">Save Channel Worker Schedule</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="run_live_channel_worker">
                    <label class="marketing-execution-label-limit">Manual limit
                        <input class="form-control" type="number" min="1" max="200" name="limit_count" value="<?php echo (int) ($liveChannelWorkerSchedule['max_handoffs_per_run'] ?? 25); ?>">
                    </label>
                    <button class="btn-premium-secondary" type="submit">Run Channel Worker</button>
                </form>
                <form method="POST" class="marketing-execution-form-row marketing-execution-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <?php if (!empty($liveChannelWorkerSchedule['emergency_paused'])): ?>
                        <input type="hidden" name="action" value="resume_live_channel_worker">
                        <button class="btn-premium-primary" type="submit">Resume Channel Worker</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="pause_live_channel_worker">
                        <label class="marketing-execution-label-pause">Channel worker pause reason
                            <input class="form-control" type="text" name="pause_reason" value="Manual emergency pause for live SMS/WhatsApp worker">
                        </label>
                        <button class="btn-premium-secondary" type="submit">Pause Channel Worker</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (empty($liveChannelWorkerRuns)): ?>
                <div class="empty-state"><p>No Marketing live channel worker runs have been recorded yet.</p></div>
            <?php else: foreach ($liveChannelWorkerRuns as $run): ?>
                <div class="execution-row">
                    <div>
                        <strong>Channel worker run #<?php echo (int) $run['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($run['source'] ?? 'cli'))); ?></span>
                            <span><?php echo $h((string) ($run['started_at'] ?? '')); ?></span>
                            <span>Processed <?php echo (int) ($run['processed_count'] ?? 0); ?></span>
                            <span>Sent <?php echo (int) ($run['sent_count'] ?? 0); ?></span>
                            <span>Blocked <?php echo (int) ($run['blocked_count'] ?? 0); ?></span>
                            <span>Failed <?php echo (int) ($run['failed_count'] ?? 0); ?></span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($run['status'] ?? 'completed'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><div><h2>Live Email Worker</h2><p>Marketing live email handoffs are processed by a CLI/cron worker. This page shows evidence and never sends SMTP directly.</p></div><span class="execution-chip"><?php echo $h($labelize($liveWorkerStatus)); ?></span></div>
            <div class="execution-grid">
                <div class="execution-note"><strong>Ready handoffs</strong><span class="marketing-execution-detail"><?php echo (int) ($liveEmailMonitor['counts']['by_status']['queued'] ?? 0); ?> queued for worker pickup</span></div>
                <div class="execution-note"><strong>Sent handoffs</strong><span class="marketing-execution-detail"><?php echo (int) ($liveEmailMonitor['counts']['by_status']['sent'] ?? 0); ?> marked sent</span></div>
                <div class="execution-note"><strong>Blocked handoffs</strong><span class="marketing-execution-detail"><?php echo (int) ($liveEmailMonitor['counts']['by_status']['blocked'] ?? 0); ?> blocked by policy, suppression, or worker safeguards</span></div>
                <div class="execution-note"><strong>Worker command</strong><span class="marketing-execution-detail"><code>php cli/process_marketing_live_email_handoffs.php all 25</code></span></div>
            </div>
            <div class="execution-note is-<?php echo $h($liveWorkerStatusClass); ?> marketing-execution-section">
                <strong><?php echo $h((string) ($liveWorkerHealth['message'] ?? 'Worker health has not been checked yet.')); ?></strong>
                <span class="marketing-execution-detail">
                    Last run: <?php echo $h((string) (($liveWorkerHealth['last_run_at'] ?? null) ?: 'Not recorded')); ?>
                    <?php if (($liveWorkerHealth['last_run_age_minutes'] ?? null) !== null): ?>
                        (<?php echo (int) $liveWorkerHealth['last_run_age_minutes']; ?> minutes ago)
                    <?php endif; ?>
                    - queued <?php echo (int) ($liveWorkerHealth['counts']['queued'] ?? 0); ?>,
                    stale <?php echo (int) ($liveWorkerHealth['counts']['stale'] ?? 0); ?>,
                    blocked <?php echo (int) ($liveWorkerHealth['counts']['blocked'] ?? 0); ?>,
                    failed <?php echo (int) ($liveWorkerHealth['counts']['failed'] ?? 0); ?>.
                </span>
                <span class="marketing-execution-detail">
                    Lease: <?php echo $h($labelize((string) ($liveWorkerHealth['lease']['status'] ?? 'not_acquired'))); ?>
                    <?php if (!empty($liveWorkerHealth['lease']['run_id'])): ?>
                        on run #<?php echo (int) $liveWorkerHealth['lease']['run_id']; ?>
                    <?php endif; ?>
                    <?php if (!empty($liveWorkerHealth['lease']['expires_at'])): ?>
                        until <?php echo $h((string) $liveWorkerHealth['lease']['expires_at']); ?>
                    <?php endif; ?>
                </span>
                <?php if (!empty($liveWorkerHealth['recommended_actions'])): ?>
                    <span class="marketing-execution-detail-relaxed"><?php echo $h(implode(' ', array_map('strval', (array) $liveWorkerHealth['recommended_actions']))); ?></span>
                <?php endif; ?>
            </div>
            <div class="execution-note marketing-execution-section">
                <strong>Worker Schedule Profile</strong>
                <span class="marketing-execution-detail">
                    <?php echo $h($labelize((string) ($liveWorkerSchedule['status'] ?? 'planned'))); ?> -
                    expected every <?php echo (int) ($liveWorkerSchedule['expected_interval_minutes'] ?? 5); ?> minute(s),
                    stale after <?php echo (int) ($liveWorkerSchedule['max_stale_minutes'] ?? 30); ?> minute(s).
                    Last confirmed: <?php echo $h((string) (($liveWorkerSchedule['last_confirmed_at'] ?? null) ?: 'Not confirmed')); ?>.
                </span>
                <span class="marketing-execution-detail">
                    Throttle: max <?php echo (int) ($liveWorkerSchedule['max_handoffs_per_run'] ?? 25); ?> handoff(s) per run,
                    gap <?php echo (int) ($liveWorkerSchedule['min_seconds_between_sends'] ?? 0); ?> second(s),
                    hourly cap <?php echo (int) ($liveWorkerSchedule['hourly_send_cap'] ?? 0); ?>,
                    daily cap <?php echo (int) ($liveWorkerSchedule['daily_send_cap'] ?? 0); ?>.
                    Sent today: <?php echo (int) ($liveWorkerHealth['throttle']['sent_today'] ?? 0); ?>.
                </span>
                <?php if (!empty($liveWorkerSchedule['emergency_paused'])): ?>
                    <span class="marketing-execution-danger-text">
                        Emergency pause active since <?php echo $h((string) ($liveWorkerSchedule['emergency_paused_at'] ?? '')); ?>:
                        <?php echo $h((string) ($liveWorkerSchedule['emergency_pause_reason'] ?? 'No reason provided.')); ?>
                    </span>
                <?php endif; ?>
                <?php if ($canManageMarketing): ?>
                    <form method="POST" class="marketing-execution-form-grid marketing-execution-subform">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="update_live_worker_schedule">
                        <label>Status
                            <select class="form-control" name="status">
                                <?php foreach (Marketing::LIVE_WORKER_SCHEDULE_STATUSES as $scheduleStatus): ?><option value="<?php echo $h($scheduleStatus); ?>" <?php echo (string) ($liveWorkerSchedule['status'] ?? 'planned') === $scheduleStatus ? 'selected' : ''; ?>><?php echo $h($labelize($scheduleStatus)); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Interval minutes
                            <input class="form-control" type="number" min="1" max="1440" name="expected_interval_minutes" value="<?php echo (int) ($liveWorkerSchedule['expected_interval_minutes'] ?? 5); ?>">
                        </label>
                        <label>Stale minutes
                            <input class="form-control" type="number" min="1" max="1440" name="max_stale_minutes" value="<?php echo (int) ($liveWorkerSchedule['max_stale_minutes'] ?? 30); ?>">
                        </label>
                        <label>Max per run
                            <input class="form-control" type="number" min="1" max="200" name="max_handoffs_per_run" value="<?php echo (int) ($liveWorkerSchedule['max_handoffs_per_run'] ?? 25); ?>">
                        </label>
                        <label>Gap seconds
                            <input class="form-control" type="number" min="0" max="3600" name="min_seconds_between_sends" value="<?php echo (int) ($liveWorkerSchedule['min_seconds_between_sends'] ?? 0); ?>">
                        </label>
                        <label>Hourly cap
                            <input class="form-control" type="number" min="0" max="100000" name="hourly_send_cap" value="<?php echo (int) ($liveWorkerSchedule['hourly_send_cap'] ?? 0); ?>">
                        </label>
                        <label>Daily cap
                            <input class="form-control" type="number" min="0" max="1000000" name="daily_send_cap" value="<?php echo (int) ($liveWorkerSchedule['daily_send_cap'] ?? 0); ?>">
                        </label>
                        <label>Label
                            <input class="form-control" type="text" name="schedule_label" value="<?php echo $h((string) ($liveWorkerSchedule['schedule_label'] ?? 'Marketing live email worker')); ?>">
                        </label>
                        <button class="btn-premium-secondary" type="submit">Save Schedule</button>
                    </form>
                    <form method="POST" class="marketing-execution-form-row marketing-execution-subform">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <?php if (!empty($liveWorkerSchedule['emergency_paused'])): ?>
                            <input type="hidden" name="action" value="resume_live_worker">
                            <button class="btn-premium-secondary" type="submit">Resume Worker</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="pause_live_worker">
                            <label class="marketing-execution-label-worker-pause">Emergency pause reason
                                <input class="form-control" type="text" name="pause_reason" value="Manual emergency pause from Marketing Execution">
                            </label>
                            <button class="btn-premium-secondary" type="submit">Emergency Pause</button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <span class="marketing-execution-detail-relaxed">Only Marketing managers can update the live worker schedule profile.</span>
                <?php endif; ?>
            </div>
            <?php if (empty($liveEmailWorkerRuns)): ?>
                <div class="empty-state"><p>No Marketing live email worker runs have been recorded yet.</p></div>
            <?php else: foreach ($liveEmailWorkerRuns as $run): ?>
                <div class="execution-row">
                    <div>
                        <strong>Worker run #<?php echo (int) $run['id']; ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($run['source'] ?? 'cli'))); ?></span>
                            <span><?php echo $h((string) ($run['started_at'] ?? '')); ?></span>
                            <span>Processed <?php echo (int) ($run['processed_count'] ?? 0); ?></span>
                            <span>Sent <?php echo (int) ($run['sent_count'] ?? 0); ?></span>
                            <span>Blocked <?php echo (int) ($run['blocked_count'] ?? 0); ?></span>
                            <span>Failed <?php echo (int) ($run['failed_count'] ?? 0); ?></span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($run['status'] ?? 'completed'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Delivery Proofs</h2><p>Safe evidence captured from the CRM email worker after each live handoff attempt.</p></div>
            <?php if (empty($liveEmailDeliveryProofs)): ?>
                <div class="empty-state"><p>No live email delivery proofs have been recorded yet.</p></div>
            <?php else: foreach ($liveEmailDeliveryProofs as $proof): ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h($maskRecipient($proof['to_email'] ?? $proof['recipient_email'] ?? '')); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($proof['proof_status'] ?? 'sent'))); ?></span>
                            <span><?php echo $h((string) ($proof['smtp_method'] ?? 'method unavailable')); ?></span>
                            <span><?php echo $h((string) ($proof['provider_key'] ?? 'provider unavailable')); ?></span>
                            <span>Run #<?php echo (int) ($proof['worker_run_id'] ?? 0); ?></span>
                            <span><?php echo $h((string) ($proof['proved_at'] ?? '')); ?></span>
                        </div>
                        <?php if (!empty($proof['message_id'])): ?><div class="execution-meta">Message-ID: <?php echo $h((string) $proof['message_id']); ?></div><?php endif; ?>
                        <?php if (!empty($proof['error_message'])): ?><div class="execution-meta"><?php echo $h((string) $proof['error_message']); ?></div><?php endif; ?>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($proof['proof_status'] ?? 'sent'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Channel Delivery Proofs</h2><p>Safe evidence reconciled from CRM SMS and WhatsApp queues after live channel handoffs.</p></div>
            <?php if (empty($liveChannelDeliveryProofs)): ?>
                <div class="empty-state"><p>No live SMS or WhatsApp delivery proofs have been recorded yet.</p></div>
            <?php else: foreach ($liveChannelDeliveryProofs as $proof): ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h($maskRecipient($proof['recipient_identifier'] ?? '')); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h(strtoupper((string) ($proof['execution_type'] ?? 'channel'))); ?></span>
                            <span><?php echo $h($labelize((string) ($proof['proof_status'] ?? 'unknown'))); ?></span>
                            <span><?php echo $h($labelize((string) ($proof['handoff_status'] ?? 'queued'))); ?> handoff</span>
                            <span>Queue #<?php echo (int) ($proof['source_queue_id'] ?? 0); ?></span>
                            <span><?php echo $h((string) ($proof['proved_at'] ?? '')); ?></span>
                        </div>
                        <?php if (!empty($proof['provider_message_id'])): ?><div class="execution-meta">Provider message: <?php echo $h((string) $proof['provider_message_id']); ?></div><?php endif; ?>
                        <?php if (!empty($proof['error_message'])): ?><div class="execution-meta"><?php echo $h((string) $proof['error_message']); ?></div><?php endif; ?>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($proof['proof_status'] ?? 'unknown'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Outbound Webhook Evidence</h2><p>Allowlisted live webhook attempts store host/hash, status, response preview, and sanitized payload evidence.</p></div>
            <div class="execution-note is-<?php echo $h($liveWebhookStatusClass); ?> marketing-execution-section">
                <strong><?php echo $h((string) ($liveWebhookHealth['message'] ?? 'Outbound webhook health has not been checked yet.')); ?></strong>
                <span class="marketing-execution-detail">
                    Recent 24h: <?php echo (int) ($liveWebhookHealth['counts']['recent_total'] ?? 0); ?> attempt(s),
                    <?php echo (int) ($liveWebhookHealth['counts']['sent'] ?? 0); ?> sent,
                    <?php echo (int) ($liveWebhookHealth['counts']['failed'] ?? 0); ?> failed,
                    <?php echo (int) ($liveWebhookHealth['counts']['blocked'] ?? 0); ?> blocked.
                    Due webhook queue: <?php echo (int) ($liveWebhookHealth['counts']['queued'] ?? 0); ?>.
                    <?php if (!empty($liveWebhookHealth['latest_attempt']['endpoint_host'])): ?>Latest host: <?php echo $h((string) $liveWebhookHealth['latest_attempt']['endpoint_host']); ?>.<?php endif; ?>
                </span>
            </div>
            <?php if (empty($liveWebhookAttempts)): ?>
                <div class="empty-state"><p>No outbound webhook attempts have been recorded yet.</p></div>
            <?php else: foreach ($liveWebhookAttempts as $attempt): ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h((string) ($attempt['connector_name'] ?? 'Webhook connector')); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($attempt['status'] ?? 'failed'))); ?></span>
                            <span><?php echo $h((string) ($attempt['http_method'] ?? 'POST')); ?></span>
                            <span><?php echo $h((string) ($attempt['endpoint_host'] ?? 'host unavailable')); ?></span>
                            <span><?php echo !empty($attempt['external_api_called']) ? 'External HTTP attempted' : 'Blocked before HTTP'; ?></span>
                            <?php if (!empty($attempt['response_status_code'])): ?><span>HTTP <?php echo (int) $attempt['response_status_code']; ?></span><?php endif; ?>
                            <span><?php echo $h((string) ($attempt['attempted_at'] ?? $attempt['created_at'] ?? '')); ?></span>
                        </div>
                        <?php if (!empty($attempt['error_message'])): ?><div class="execution-meta"><?php echo $h((string) $attempt['error_message']); ?></div><?php endif; ?>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($attempt['status'] ?? 'failed'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
            <div class="premium-section-header marketing-execution-subheader"><h2>Unsubscribe Evidence</h2><p>Tokenized recipient opt-outs are converted into active suppression records without exposing raw IP or browser details.</p></div>
            <?php if (empty($liveEmailUnsubscribeEvents)): ?>
                <div class="empty-state"><p>No live email unsubscribe events have been recorded yet.</p></div>
            <?php else: foreach ($liveEmailUnsubscribeEvents as $event): ?>
                <div class="execution-row">
                    <div>
                        <strong><?php echo $h($maskRecipient($event['recipient_email'] ?? '')); ?></strong>
                        <div class="execution-meta">
                            <span><?php echo $h($labelize((string) ($event['event_type'] ?? 'unsubscribe_requested'))); ?></span>
                            <span><?php echo $h($labelize((string) ($event['status'] ?? 'info'))); ?></span>
                            <span><?php echo $h((string) ($event['created_at'] ?? '')); ?></span>
                        </div>
                    </div>
                    <span class="execution-chip"><?php echo $h($labelize((string) ($event['status'] ?? 'info'))); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><h2>Execution Safety Boundary</h2><p>This page coordinates execution readiness. Live email, SMS, and WhatsApp execution start as CRM queue handoffs; third-party channel calls run only through implemented adapters and explicit operator approval.</p></div>
            <div class="execution-grid marketing-execution-grid-flush">
                <div class="execution-note"><strong>External publish</strong><span class="marketing-execution-detail"><?php echo empty($summary['manual_first_boundary']['external_publish']) ? 'Disabled' : 'Enabled'; ?></span></div>
                <div class="execution-note"><strong>External send</strong><span class="marketing-execution-detail"><?php echo empty($summary['manual_first_boundary']['external_send']) ? 'Disabled' : 'Enabled'; ?></span></div>
                <div class="execution-note"><strong>Connector readiness</strong><span class="marketing-execution-detail"><?php echo (int) ($summary['connectors']['summary']['average_readiness_score'] ?? 0); ?>% average setup readiness</span></div>
                <div class="execution-note"><strong>Live adapters</strong><span class="marketing-execution-detail"><?php echo htmlspecialchars(implode(', ', Marketing::LIVE_EXECUTION_ADAPTER_KEYS)); ?></span></div>
            </div>
        </div>
            </div>
        </details>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
