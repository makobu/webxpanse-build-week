<?php
/**
 * Channel-specific manual export bundles.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingMediaReadinessUi;
use CRM\Security;
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
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to manage channel export bundles.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'export') {
            $marketing->markChannelExportBundleExported((int) ($_POST['bundle_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=exported');
            exit;
        }
        if ($action === 'export_media_kit') {
            $marketing->markChannelMediaKitExported((int) ($_POST['media_kit_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=kit_exported');
            exit;
        }
        if ($action === 'generate_media_kit_payload') {
            $marketing->generateChannelMediaKitExport((int) ($_POST['media_kit_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=kit_payload');
            exit;
        }
        if ($action === 'integration_readiness_review') {
            $marketing->runMarketingIntegrationReadinessReview(['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=readiness');
            exit;
        }
        if ($action === 'update_live_policy') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update live execution policy.');
            }
            $marketing->updateMarketingLiveExecutionPolicy($_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_policy');
            exit;
        }
        if ($action === 'pause_live_execution') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to pause live execution.');
            }
            $marketing->pauseMarketingLiveExecution((int) ($user['id'] ?? 0), (string) ($_POST['pause_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_paused');
            exit;
        }
        if ($action === 'resume_live_execution') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to resume live execution.');
            }
            $marketing->resumeMarketingLiveExecution((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_resumed');
            exit;
        }
        if ($action === 'create_connector') {
            $marketing->createChannelConnector($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_created');
            exit;
        }
        if ($action === 'update_connector_live') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to update connector live settings.');
            }
            $marketing->updateChannelConnector((int) ($_POST['connector_id'] ?? 0), $_POST);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_live');
            exit;
        }
        if ($action === 'save_connector_secret') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to save connector live secrets.');
            }
            $marketing->saveChannelConnectorLiveSecret((int) ($_POST['connector_id'] ?? 0), $_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_secret');
            exit;
        }
        if ($action === 'verify_connector_secret') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to verify connector live secrets.');
            }
            $result = $marketing->verifyChannelConnectorLiveSecret((int) ($_POST['connector_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=' . (((string) ($result['verification_status'] ?? '') === 'passed') ? 'connector_secret_verified' : 'connector_secret_verify_failed'));
            exit;
        }
        if ($action === 'revoke_connector_secret') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to revoke connector live secrets.');
            }
            $marketing->revokeChannelConnectorLiveSecret((int) ($_POST['connector_id'] ?? 0), (int) ($user['id'] ?? 0), (string) ($_POST['revocation_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_secret_revoked');
            exit;
        }
        if ($action === 'live_email_handoff_control') {
            $marketing->controlLiveEmailHandoff(
                (int) ($_POST['handoff_id'] ?? 0),
                (string) ($_POST['handoff_action'] ?? ''),
                (int) ($user['id'] ?? 0),
                (string) ($_POST['operator_note'] ?? '')
            );
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_email_control');
            exit;
        }
        if ($action === 'test_connector') {
            $testMode = (string) ($_POST['test_mode'] ?? 'dry_run');
            $marketing->runChannelConnectorTest((int) ($_POST['connector_id'] ?? 0), $_POST + ['test_mode' => $testMode, 'created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=' . ($testMode === 'live_preflight' ? 'connector_preflight' : 'connector_tested'));
            exit;
        }
        if ($action === 'evaluate_connector_readiness') {
            $marketing->evaluateChannelConnectorReadiness((int) ($_POST['connector_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_readiness');
            exit;
        }
        if ($action === 'run_connector_health_probe') {
            $marketing->runChannelConnectorHealthProbe((int) ($_POST['connector_id'] ?? 0), $_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=connector_health');
            exit;
        }
        if ($action === 'create_execution_queue') {
            $marketing->createExecutionQueueItem($_POST + ['created_by' => (int) ($user['id'] ?? 0), 'requested_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=execution_created');
            exit;
        }
        if ($action === 'create_live_rehearsal') {
            $marketing->createLiveRehearsalRun($_POST + ['created_by' => (int) ($user['id'] ?? 0), 'requested_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=rehearsal_created');
            exit;
        }
        if ($action === 'run_live_rehearsal') {
            $marketing->runLiveRehearsalDryRun((int) ($_POST['rehearsal_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=rehearsal_dry_run');
            exit;
        }
        if ($action === 'approve_live_rehearsal') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to approve live rehearsals.');
            }
            $marketing->decideLiveRehearsalRun((int) ($_POST['rehearsal_id'] ?? 0), (string) ($_POST['decision'] ?? 'approved'), (string) ($_POST['decision_note'] ?? ''), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=rehearsal_decided');
            exit;
        }
        if ($action === 'promote_live_rehearsal') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to promote live rehearsals.');
            }
            $marketing->promoteLiveRehearsalToQueue((int) ($_POST['rehearsal_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=rehearsal_promoted');
            exit;
        }
        if ($action === 'request_execution_approval') {
            $marketing->requestExecutionApproval((int) ($_POST['queue_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=execution_requested');
            exit;
        }
        if ($action === 'approve_execution') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to approve execution queue items.');
            }
            $marketing->decideExecutionApproval((int) ($_POST['approval_id'] ?? 0), (string) ($_POST['decision'] ?? 'approved'), (string) ($_POST['decision_note'] ?? ''), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=execution_approved');
            exit;
        }
        if ($action === 'confirm_live_dispatch') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to confirm live dispatch queue items.');
            }
            $marketing->confirmExecutionQueueForLiveDispatch((int) ($_POST['queue_id'] ?? 0), (int) ($user['id'] ?? 0), (string) ($_POST['live_confirmation'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_dispatch_confirmed');
            exit;
        }
        if ($action === 'cancel_live_dispatch') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to cancel live dispatch queue items.');
            }
            $marketing->cancelExecutionQueueLiveDispatch((int) ($_POST['queue_id'] ?? 0), (int) ($user['id'] ?? 0), (string) ($_POST['cancel_reason'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_dispatch_cancelled');
            exit;
        }
        if ($action === 'run_live_dispatcher') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to run the live dispatcher.');
            }
            $marketing->runLiveExecutionDispatcher([
                'source' => 'manual',
                'limit_count' => (int) ($_POST['limit_count'] ?? 10),
                'requested_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=live_dispatcher');
            exit;
        }
        if ($action === 'run_execution') {
            if ((string) ($_POST['attempt_mode'] ?? '') === 'live' && !$canManageMarketing) {
                throw new RuntimeException('You do not have permission to run live execution attempts.');
            }
            $marketing->runExecutionQueueItem((int) ($_POST['queue_id'] ?? 0), $_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=execution_run');
            exit;
        }
        if ($action === 'create_media_kit') {
            $mediaKitId = $marketing->createChannelMediaKitFromDistribution(
                (int) ($_POST['distribution_post_id'] ?? 0),
                [
                    'landing_page_id' => (int) ($_POST['landing_page_id'] ?? 0),
                    'utm_link_id' => (int) ($_POST['utm_link_id'] ?? 0),
                    'primary_media_file_id' => (int) ($_POST['primary_media_file_id'] ?? 0),
                    'destination_url' => (string) ($_POST['destination_url'] ?? ''),
                    'created_by' => (int) ($user['id'] ?? 0),
                ],
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=kit_created&kit_id=' . $mediaKitId);
            exit;
        }
        $bundleId = $marketing->createChannelExportBundle((int) ($_POST['distribution_post_id'] ?? 0), (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_channel_exports.php?success=created&id=' . $bundleId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$filters = [];
if ($status !== '') {
    if (in_array($status, Marketing::CHANNEL_EXPORT_STATUSES, true)) {
        $filters['status'] = $status;
    } else {
        $error = $error !== '' ? $error : 'Invalid status filter.';
        $status = '';
    }
}
$bundles = $marketing->listChannelExportBundles($filters, 100, 0);
$mediaKits = $marketing->listChannelMediaKits($filters, 100, 0);
$posts = $marketing->listDistributionPosts(['open' => true], 100, 0);
$options = $marketing->optionData();
$integrationReadiness = $marketing->getMarketingIntegrationReadiness();
$readinessSuggestions = $marketing->listMarketingAiQueueSuggestions('phase_8', 5, 0);
$connectors = $marketing->listChannelConnectors([], 20, 0);
$connectorTests = $marketing->listChannelConnectorTestRuns([], 8, 0);
$connectorDiagnostics = $marketing->getChannelConnectorDiagnostics();
$connectorReadinessSummary = $marketing->getChannelConnectorReadinessSummary();
$connectorReadinessReviews = $marketing->listChannelConnectorReadinessReviews([], 8, 0);
$connectorSetupDefinitions = $marketing->channelConnectorSetupDefinitions();
$executionQueue = $marketing->listExecutionQueue([], 12, 0);
$executionAttempts = $marketing->listExecutionAttempts([], 6, 0);
$liveRehearsals = $marketing->listLiveRehearsalRuns([], 12, 0);
$liveExecutionPolicy = $marketing->getMarketingLiveExecutionPolicy();
$liveExecutionReadiness = $marketing->getMarketingLiveExecutionReadiness();
$liveSetupWizard = $marketing->getMarketingLiveSetupWizard((int) ($user['id'] ?? 0));
$liveExecutionEvents = $marketing->listLiveExecutionEvents([], 6, 0);
$liveDispatchRuns = $marketing->listLiveQueueDispatchRuns([], 6, 0);
$liveEmailMonitor = $marketing->getLiveEmailQueueMonitor(8);
$liveEmailHandoffs = (array) ($liveEmailMonitor['handoffs'] ?? []);
$mediaReadiness = $marketing->getMarketingMediaOperationalReadiness((int) ($user['id'] ?? 0), 6);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$readyBundles = count(array_filter($bundles, static fn(array $bundle): bool => in_array((string) ($bundle['status'] ?? ''), ['ready', 'exported'], true)));
$readyMediaKits = count(array_filter($mediaKits, static fn(array $kit): bool => !empty($kit['readiness_json']['ready'])));
$readyConnectors = (int) ($connectorDiagnostics['ready_connectors'] ?? 0);
$blockedConnectors = (int) ($connectorDiagnostics['blocked_connectors'] ?? 0);
$liveStatus = (string) ($liveExecutionReadiness['status'] ?? 'disabled');
$liveSetupScore = (int) ($liveSetupWizard['score'] ?? 0);
$summaryTiles = [
    ['icon' => 'fa-box-open', 'label' => 'Bundles', 'value' => $readyBundles . '/' . count($bundles), 'tooltip' => 'Channel bundles prepared for manual publishing.'],
    ['icon' => 'fa-images', 'label' => 'Media Kits', 'value' => $readyMediaKits . '/' . count($mediaKits), 'tooltip' => 'Media packages with the required asset, destination, and tracking details.'],
    ['icon' => 'fa-plug-circle-check', 'label' => 'Connectors', 'value' => (string) $readyConnectors, 'tooltip' => 'Dry-run or live-capable connector records that are ready.'],
    ['icon' => 'fa-shield-halved', 'label' => 'Live Safety', 'value' => $labelize($liveStatus), 'tooltip' => 'Live execution remains gated by policy, connector readiness, preflight, approval, and confirmation.'],
];
$stageCards = [
    ['icon' => 'fa-newspaper', 'title' => 'Choose Post', 'status' => count($posts) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($posts) > 0 ? 'Posts available.' : 'Create a post first.', 'tooltip' => 'Start with an approved distribution post. This page only packages it for manual channel use.', 'href' => count($posts) > 0 ? '#create-channel-bundle' : 'marketing_distribution.php', 'action' => count($posts) > 0 ? 'Create Bundle' : 'Open Queue'],
    ['icon' => 'fa-box-open', 'title' => 'Create Bundle', 'status' => count($bundles) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($bundles) > 0 ? 'Packages exist.' : 'No bundles yet.', 'tooltip' => 'Bundle copy, channel, payload, and readiness notes without publishing externally.', 'href' => '#channel-bundles', 'action' => 'View Bundles'],
    ['icon' => 'fa-photo-film', 'title' => 'Add Media', 'status' => $readyMediaKits > 0 ? 'ready' : 'setup_needed', 'sentence' => $readyMediaKits > 0 ? 'Media is ready.' : 'Media may be needed.', 'tooltip' => 'Media kits keep creative, destination, and UTM details together for the operator.', 'href' => '#channel-media-kits', 'action' => 'Media Kits'],
    ['icon' => 'fa-plug', 'title' => 'Check Connector', 'status' => $blockedConnectors > 0 ? 'blocked' : ($readyConnectors > 0 ? 'ready' : 'setup_needed'), 'sentence' => $readyConnectors > 0 ? 'Connector tracked.' : 'Manual path still works.', 'tooltip' => 'Connectors are advanced. They stay dry-run or gated unless live policy and preflight are ready.', 'href' => '#advanced-channel-tools', 'action' => 'More Tools'],
    ['icon' => 'fa-hand', 'title' => 'Manual Publish', 'status' => 'ready', 'sentence' => 'No API call here.', 'tooltip' => 'No external social, SMS, WhatsApp, ad, or email API is called from the default export board.', 'href' => '#advanced-channel-tools', 'action' => 'Safety'],
];
$todayActions = array_slice(array_values(array_filter([
    count($posts) > 0 && $canWriteMarketing ? ['label' => 'Create channel bundle', 'href' => '#create-channel-bundle', 'reason' => 'Package one approved post for manual publishing.'] : null,
    $readyMediaKits === 0 && $canWriteMarketing ? ['label' => 'Create media kit', 'href' => '#create-media-kit', 'reason' => 'Attach media, destination, and tracking details.'] : null,
    !empty($liveSetupWizard['next_step']) ? ['label' => (string) ($liveSetupWizard['next_step']['label'] ?? 'Complete live setup'), 'href' => '#advanced-channel-tools', 'reason' => 'Keep live execution gated until setup is complete.'] : null,
    count($bundles) > 0 ? ['label' => 'Review latest bundle', 'href' => '#channel-bundles', 'reason' => 'Check status before anyone publishes manually.'] : null,
    ['label' => 'Open distribution queue', 'href' => 'marketing_distribution.php', 'reason' => 'Prepare or review source channel posts.'],
])), 0, 5);
$pageTitle = 'Channel Export Bundles - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-channel-export-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Channel Export Bundles</h1>
                <p>Package posts for manual publishing by channel.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_distribution.php"><i class="fas fa-share-nodes"></i> Distribution</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Channel export bundle created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'exported'): ?><div class="alert alert-success">Channel export bundle marked exported.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'kit_created'): ?><div class="alert alert-success">Channel media kit created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'kit_payload'): ?><div class="alert alert-success">Channel media kit export payload refreshed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'kit_exported'): ?><div class="alert alert-success">Channel media kit marked exported.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'readiness'): ?><div class="alert alert-success">Integration readiness review saved manual follow-up suggestions.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_created'): ?><div class="alert alert-success">Channel connector saved in dry-run mode.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_tested'): ?><div class="alert alert-success">Connector diagnostic test completed. No external API call was made.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_preflight'): ?><div class="alert alert-success">Connector live preflight saved. No external API call, send, publish, or delivery was made.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_readiness'): ?><div class="alert alert-success">Connector setup readiness review saved. External send and publish remain disabled.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_health'): ?><div class="alert alert-success">Connector health probe saved. No external publish, send, or delivery API was called.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_policy'): ?><div class="alert alert-success">Live execution policy updated. Queue items still require ready connectors, passed live preflight, explicit approval, and final confirmation.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_paused'): ?><div class="alert alert-success">Emergency stop is active. All live execution attempts are blocked until resumed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_resumed'): ?><div class="alert alert-success">Emergency stop cleared. Live queue items still require every normal approval and readiness gate.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_live'): ?><div class="alert alert-success">Connector live settings updated without exposing secret values.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_secret'): ?><div class="alert alert-success">Connector live secret saved securely. Run live preflight again before approving live queue execution.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_secret_verified'): ?><div class="alert alert-success">Connector live secret verified locally. Run live preflight again before approving live queue execution.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_secret_verify_failed'): ?><div class="alert alert-danger">Connector live secret verification failed. The connector is blocked until the credential is replaced.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'connector_secret_revoked'): ?><div class="alert alert-success">Connector live secret revoked and live capability disabled for that connector.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_email_control'): ?><div class="alert alert-success">Live email handoff control saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'execution_created'): ?><div class="alert alert-success">Execution queue item created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rehearsal_created'): ?><div class="alert alert-success">Live rehearsal created. Run the dry rehearsal before manager promotion.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rehearsal_dry_run'): ?><div class="alert alert-success">Dry rehearsal completed without external send, publish, or delivery.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rehearsal_decided'): ?><div class="alert alert-success">Live rehearsal decision saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rehearsal_promoted'): ?><div class="alert alert-success">Live rehearsal promoted into the approved live queue. Final RUN LIVE confirmation is still required.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'execution_requested'): ?><div class="alert alert-success">Execution approval requested.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'execution_approved'): ?><div class="alert alert-success">Execution approval decision saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_dispatch_confirmed'): ?><div class="alert alert-success">Live queue item confirmed for due-item dispatch. The dispatcher still checks policy, connector, preflight, approval, idempotency, and emergency stop before running.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_dispatch_cancelled'): ?><div class="alert alert-success">Live queue item dispatch was cancelled before retry. It can be re-confirmed after review.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'live_dispatcher'): ?><div class="alert alert-success">Due live dispatcher run recorded.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'execution_run'): ?><div class="alert alert-success">Execution attempt recorded. Live attempts remain policy-gated, approval-gated, and adapter-gated.</div><?php endif; ?>

        <section class="marketing-channel-export-shell">
            <div class="marketing-founder-summary marketing-channel-export-summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($tile['tooltip']); ?>" tabindex="0">
                        <i class="fas <?php echo $h($tile['icon']); ?>"></i>
                        <div>
                            <span><?php echo $h($tile['label']); ?></span>
                            <strong><?php echo $h($tile['value']); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-channel-export-stage-grid">
                <?php foreach ($stageCards as $stage): ?>
                    <article class="marketing-channel-export-stage-card <?php echo $h((string) $stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-channel-export-stage-body">
                            <span class="badge <?php echo $h((string) $stage['status'] === 'ready' ? 'badge-success' : ((string) $stage['status'] === 'blocked' ? 'badge-danger' : 'badge-warning')); ?>"><?php echo $h($labelize((string) $stage['status'])); ?></span>
                            <h2><?php echo $h($stage['title']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                        </div>
                        <a class="btn-premium-secondary marketing-channel-export-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-channel-export-layout">
                <main class="marketing-channel-export-main">
                    <section class="content-card marketing-channel-export-board" id="channel-bundles">
                        <div class="premium-section-header">
                            <div><h2>Channel Board</h2><p>Manual packages ready for review.</p></div>
                        </div>
                        <?php if (empty($bundles)): ?>
                            <div class="empty-state"><p>No channel export bundles match this view.</p><?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#create-channel-bundle">Create Bundle</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="marketing-channel-export-card-grid">
                                <?php foreach (array_slice($bundles, 0, 6) as $bundle): ?>
                                    <article class="marketing-channel-export-card <?php echo $h((string) ($bundle['status'] ?? 'draft')); ?>" data-tooltip="<?php echo $h('Channel: ' . $labelize((string) ($bundle['channel'] ?? 'channel')) . '. Asset state: ' . (!empty($bundle['asset_readiness_json']['ready']) ? 'ready' : 'needed') . '.'); ?>" tabindex="0">
                                        <div class="marketing-stage-visual"><i class="fas fa-box-open"></i></div>
                                        <span class="badge <?php echo (string) ($bundle['status'] ?? '') === 'exported' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize((string) ($bundle['status'] ?? 'draft'))); ?></span>
                                        <h3><?php echo $h($bundle['content_title'] ?? 'Channel bundle'); ?></h3>
                                        <p><?php echo $h($labelize((string) ($bundle['channel'] ?? 'channel'))); ?> package.</p>
                                        <?php if ($canWriteMarketing && (string) ($bundle['status'] ?? '') !== 'exported'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="export">
                                                <input type="hidden" name="bundle_id" value="<?php echo (int) $bundle['id']; ?>">
                                                <button class="btn-premium-secondary marketing-channel-export-card-action" type="submit">Mark Exported</button>
                                            </form>
                                        <?php else: ?>
                                            <a class="btn-premium-secondary marketing-channel-export-card-action" href="#advanced-channel-tools">Details</a>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-channel-export-media" id="channel-media-kits">
                        <div class="premium-section-header">
                            <div><h2>Media Kits</h2><p>Creative and tracking packages.</p></div>
                        </div>
                        <?php if (empty($mediaKits)): ?>
                            <div class="empty-state"><p>No media kits match this view.</p><?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#create-media-kit">Create Media Kit</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="marketing-channel-export-card-grid">
                                <?php foreach (array_slice($mediaKits, 0, 3) as $kit): ?>
                                    <article class="marketing-channel-export-card <?php echo !empty($kit['readiness_json']['ready']) ? 'ready' : 'setup_needed'; ?>" data-tooltip="<?php echo $h(!empty($kit['readiness_json']['ready']) ? 'Ready media kit.' : 'Missing: ' . implode(', ', (array) ($kit['readiness_json']['missing'] ?? []))); ?>" tabindex="0">
                                        <div class="marketing-stage-visual"><i class="fas fa-photo-film"></i></div>
                                        <span class="badge <?php echo !empty($kit['readiness_json']['ready']) ? 'badge-success' : 'badge-warning'; ?>"><?php echo !empty($kit['readiness_json']['ready']) ? 'Ready' : 'Setup Needed'; ?></span>
                                        <h3><?php echo $h($kit['title'] ?? 'Media kit'); ?></h3>
                                        <p><?php echo $h($labelize((string) ($kit['channel'] ?? 'channel'))); ?> assets.</p>
                                        <a class="btn-premium-secondary marketing-channel-export-card-action" href="#advanced-channel-tools">Details</a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="content-card marketing-channel-export-today">
                    <div class="premium-section-header">
                        <div><h2>Today</h2><p>At most five actions.</p></div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo $h($action['href']); ?>">
                                <span><?php echo $h($action['label']); ?></span>
                                <small><?php echo $h($action['reason']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketing-channel-export-safety">
                        <strong>No automatic publishing</strong>
                        <span>Advanced live controls stay gated below.</span>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-channel-export-tools" id="advanced-channel-tools">
                <summary>More channel tools</summary>
                <div class="marketing-channel-export-tools-body">

        <div class="content-card marketing-channel-export-section">
            <div class="premium-section-header">
                <div><h2>Live Setup Wizard</h2><p>One path from connector setup to safe controlled live execution.</p></div>
                <span class="badge badge-default"><?php echo (int) ($liveSetupWizard['score'] ?? 0); ?>% ready</span>
            </div>
            <div class="live-setup-strip">
                <div class="marketing-meta marketing-channel-export-meta-block">
                    <strong><?php echo htmlspecialchars($labelize((string) ($liveSetupWizard['status'] ?? 'not_started'))); ?></strong><br>
                    <?php if (!empty($liveSetupWizard['next_step'])): ?>
                        Next: <?php echo htmlspecialchars((string) ($liveSetupWizard['next_step']['label'] ?? 'Complete setup')); ?>
                    <?php else: ?>
                        All required setup steps have evidence.
                    <?php endif; ?>
                </div>
                <div class="live-setup-steps">
                    <?php foreach ((array) ($liveSetupWizard['steps'] ?? []) as $step): ?>
                        <a class="live-setup-step is-<?php echo htmlspecialchars((string) ($step['status'] ?? 'missing')); ?>" href="<?php echo htmlspecialchars((string) ($step['href'] ?? '#')); ?>">
                            <strong><?php echo htmlspecialchars((string) ($step['label'] ?? 'Setup step')); ?></strong>
                            <span><?php echo htmlspecialchars($labelize((string) ($step['status'] ?? 'missing'))); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php echo MarketingMediaReadinessUi::render($mediaReadiness); ?>

        <div class="content-card marketing-channel-export-section">
            <form method="GET" class="marketing-channel-export-filter">
                <div class="form-group marketing-channel-export-zero"><label>Status</label><select name="status"><option value="">All</option><?php foreach (Marketing::CHANNEL_EXPORT_STATUSES as $bundleStatus): ?><option value="<?php echo htmlspecialchars($bundleStatus); ?>" <?php echo $selected($status, $bundleStatus); ?>><?php echo htmlspecialchars($labelize($bundleStatus)); ?></option><?php endforeach; ?></select></div>
                <button class="btn-premium-secondary" type="submit">Filter</button>
                <a class="btn-premium-secondary" href="marketing_channel_exports.php">Reset</a>
            </form>
        </div>

        <div class="content-card marketing-channel-export-section">
            <div class="premium-section-header">
                <div><h2>Integration Readiness</h2><p>Review manual export prerequisites, assets, approvals, UTMs, destination fields, and CRM token publishing readiness without calling external APIs.</p></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="integration_readiness_review">
                        <button class="btn-premium-secondary" type="submit">Run Readiness Review</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="channel-grid marketing-channel-export-grid-flat">
                <div>
                    <?php foreach ((array) ($integrationReadiness['checks'] ?? []) as $checkName => $check): ?>
                        <div class="channel-row">
                            <div><strong><?php echo htmlspecialchars($labelize((string) $checkName)); ?></strong><div class="marketing-meta"><?php echo htmlspecialchars(json_encode($check['counts'] ?? [], JSON_UNESCAPED_SLASHES)); ?></div></div>
                            <span><?php echo !empty($check['ready']) ? 'Ready' : 'Attention'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <h3 class="marketing-channel-export-subhead">AI Follow-Up Suggestions</h3>
                    <?php if (empty($readinessSuggestions)): ?><div class="empty-state"><p>No integration readiness suggestions are open.</p></div><?php else: foreach ($readinessSuggestions as $suggestion): ?>
                        <div class="channel-row"><div><strong><?php echo htmlspecialchars((string) $suggestion['title']); ?></strong><div class="marketing-meta"><?php $meta = (array) ($suggestion['metadata_json'] ?? []); echo htmlspecialchars((string) ($meta['recommended_action'] ?? $meta['reason'] ?? 'Manual readiness follow-up.')); ?></div></div></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="channel-grid marketing-channel-export-grid-section">
            <div class="content-card">
                <div class="premium-section-header">
                    <div><h2>Live Execution Policy</h2><p>Controlled opt-in path from manual export to live-capable execution. Live queue items require passed connector preflight before any adapter can run.</p></div>
                    <span class="badge badge-default"><?php echo htmlspecialchars($labelize((string) ($liveExecutionReadiness['status'] ?? 'disabled'))); ?></span>
                </div>
                <div class="marketing-meta marketing-channel-export-meta-strip">
                    <span>Policy: <?php echo !empty($liveExecutionPolicy['live_execution_enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                    <span>Emergency stop: <?php echo !empty($liveExecutionPolicy['emergency_paused']) ? 'Active' : 'Clear'; ?></span>
                    <span><?php echo (int) ($liveExecutionReadiness['counts']['live_capable_connectors'] ?? 0); ?> live-capable connector(s)</span>
                    <span><?php echo (int) ($liveExecutionReadiness['counts']['verified_live_connectors'] ?? 0); ?> verified</span>
                    <span><?php echo (int) ($liveExecutionReadiness['counts']['ready_live_queue_items'] ?? 0); ?> ready live queue item(s)</span>
                    <span><?php echo (int) ($liveExecutionReadiness['counts']['queued_live_email_handoffs_today'] ?? 0); ?> email handoff(s) today</span>
                </div>
                <?php if (!empty($liveExecutionPolicy['emergency_paused'])): ?>
                    <div class="alert alert-danger">
                        <strong>Emergency stop active.</strong>
                        Live execution is blocked globally<?php echo !empty($liveExecutionPolicy['emergency_pause_reason']) ? ': ' . htmlspecialchars((string) $liveExecutionPolicy['emergency_pause_reason']) : '.'; ?>
                    </div>
                <?php endif; ?>
                <?php if ($canManageMarketing): ?>
                    <form method="POST" class="marketing-channel-export-policy-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="update_live_policy">
                        <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::LIVE_EXECUTION_POLICY_STATUSES as $policyStatus): ?><option value="<?php echo htmlspecialchars($policyStatus); ?>" <?php echo $selected($liveExecutionPolicy['status'] ?? 'disabled', $policyStatus); ?>><?php echo htmlspecialchars($labelize($policyStatus)); ?></option><?php endforeach; ?></select></div>
                        <label class="marketing-channel-export-check"><input type="checkbox" name="live_execution_enabled" value="1" <?php echo !empty($liveExecutionPolicy['live_execution_enabled']) ? 'checked' : ''; ?>> Enable live execution</label>
                        <label class="marketing-channel-export-check"><input type="checkbox" name="require_manage_approval" value="1" <?php echo !empty($liveExecutionPolicy['require_manage_approval']) ? 'checked' : ''; ?>> Require manager approval</label>
                        <div class="form-group"><label>Daily Live Cap</label><input type="number" name="daily_live_cap" min="0" value="<?php echo (int) ($liveExecutionPolicy['daily_live_cap'] ?? 0); ?>"></div>
                        <div class="form-group"><label>Allowed Connector Types</label><input type="text" name="allowed_connector_types" value="<?php echo htmlspecialchars(implode(', ', (array) ($liveExecutionPolicy['allowed_connector_types_json'] ?? []))); ?>"></div>
                        <div class="form-group"><label>Allowed Adapters</label><input type="text" name="allowed_adapter_keys" value="<?php echo htmlspecialchars(implode(', ', (array) ($liveExecutionPolicy['allowed_adapter_keys_json'] ?? []))); ?>"></div>
                        <div class="form-group marketing-channel-export-span-2"><label>Safety Checklist</label><textarea name="safety_checklist" rows="2"><?php echo htmlspecialchars(implode("\n", (array) ($liveExecutionPolicy['safety_checklist_json'] ?? []))); ?></textarea></div>
                        <button class="btn-premium-primary" type="submit">Save Live Policy</button>
                    </form>
                    <form method="POST" class="marketing-channel-export-action-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <?php if (!empty($liveExecutionPolicy['emergency_paused'])): ?>
                            <input type="hidden" name="action" value="resume_live_execution">
                            <button class="btn-premium-primary" type="submit">Resume Live Execution</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="pause_live_execution">
                            <label class="marketing-channel-export-wide-label">Emergency stop reason
                                <input class="form-control" type="text" name="pause_reason" value="Operator emergency stop before live execution">
                            </label>
                            <button class="btn-premium-secondary" type="submit">Emergency Stop Live</button>
                        <?php endif; ?>
                    </form>
                    <form method="POST" class="marketing-channel-export-action-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="run_live_dispatcher">
                        <label class="marketing-channel-export-short-label">Dispatcher Limit
                            <input class="form-control" type="number" name="limit_count" min="1" max="100" value="10">
                        </label>
                        <button class="btn-premium-primary" type="submit">Run Due Live Dispatcher</button>
                    </form>
                <?php endif; ?>
                <?php if (empty($liveExecutionEvents)): ?>
                    <div class="empty-state"><p>No live execution events have been recorded yet.</p></div>
                <?php else: foreach ($liveExecutionEvents as $event): ?>
                    <div class="channel-row"><div><strong><?php echo htmlspecialchars($labelize((string) ($event['event_type'] ?? 'event'))); ?></strong><div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) ($event['status'] ?? 'info'))); ?></span><span><?php echo htmlspecialchars((string) ($event['connector_name'] ?? 'No connector')); ?></span><span><?php echo htmlspecialchars((string) ($event['adapter_key'] ?? 'No adapter')); ?></span></div></div></div>
                <?php endforeach; endif; ?>
                <div class="premium-section-header marketing-channel-export-section-header"><h2>Live Dispatch Runs</h2><p>Due confirmed live items are processed here with the same approval, preflight, idempotency, and emergency-stop gates.</p></div>
                <?php if (empty($liveDispatchRuns)): ?>
                    <div class="empty-state"><p>No live dispatch runs have been recorded yet.</p></div>
                <?php else: foreach ($liveDispatchRuns as $dispatchRun): ?>
                    <div class="channel-row">
                        <div>
                            <strong>Dispatch run #<?php echo (int) $dispatchRun['id']; ?></strong>
                            <div class="marketing-meta">
                                <span><?php echo htmlspecialchars($labelize((string) ($dispatchRun['status'] ?? 'running'))); ?></span>
                                <span><?php echo htmlspecialchars($labelize((string) ($dispatchRun['source'] ?? 'manual'))); ?></span>
                                <span><?php echo (int) ($dispatchRun['processed_count'] ?? 0); ?> processed</span>
                                <span><?php echo (int) ($dispatchRun['succeeded_count'] ?? 0); ?> succeeded</span>
                                <span><?php echo (int) ($dispatchRun['blocked_count'] ?? 0); ?> blocked</span>
                                <span><?php echo (int) ($dispatchRun['failed_count'] ?? 0); ?> failed</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                <div class="premium-section-header marketing-channel-export-section-header"><h2>Live Email Queue Handoffs</h2><p>Queued through CRM email worker with operator cancel, retry, review, and suppression recheck controls.</p></div>
                <div class="marketing-meta marketing-channel-export-meta-strip">
                    <span><?php echo (int) ($liveEmailMonitor['counts']['total'] ?? 0); ?> monitored</span>
                    <span><?php echo (int) ($liveEmailMonitor['counts']['by_status']['queued'] ?? 0); ?> queued</span>
                    <span><?php echo (int) ($liveEmailMonitor['counts']['by_status']['sent'] ?? 0); ?> sent</span>
                    <span><?php echo (int) ($liveEmailMonitor['counts']['by_status']['blocked'] ?? 0); ?> blocked</span>
                </div>
                <?php if (empty($liveEmailHandoffs)): ?>
                    <div class="empty-state"><p>No live email queue handoffs have been created.</p></div>
                <?php else: foreach ($liveEmailHandoffs as $handoff): ?>
                    <div class="channel-row">
                        <div>
                            <strong><?php echo htmlspecialchars((string) ($handoff['recipient_email'] ?? 'recipient')); ?></strong>
                            <div class="marketing-meta">
                                <span><?php echo htmlspecialchars($labelize((string) ($handoff['status'] ?? 'queued'))); ?></span>
                                <span>Operator: <?php echo htmlspecialchars($labelize((string) ($handoff['operator_status'] ?? 'queued'))); ?></span>
                                <span><?php echo htmlspecialchars((string) ($handoff['email_run_name'] ?? 'Email run')); ?></span>
                                <span>Email: <?php echo htmlspecialchars($labelize((string) ($handoff['email_status'] ?? $handoff['last_email_status'] ?? 'pending'))); ?></span>
                                <span>Queue: <?php echo htmlspecialchars($labelize((string) ($handoff['queue_status'] ?? $handoff['last_queue_status'] ?? 'pending'))); ?></span>
                                <span>Queue #<?php echo (int) ($handoff['email_queue_id'] ?? 0); ?></span>
                            </div>
                            <?php if (!empty($handoff['operator_note'])): ?><div class="marketing-meta">Note: <?php echo htmlspecialchars((string) $handoff['operator_note']); ?></div><?php endif; ?>
                        </div>
                        <div class="marketing-channel-export-actions">
                            <?php if ($canWriteMarketing): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="live_email_handoff_control">
                                    <input type="hidden" name="handoff_id" value="<?php echo (int) $handoff['id']; ?>">
                                    <input type="hidden" name="handoff_action" value="recheck_suppression">
                                    <button class="btn-premium-secondary" type="submit">Recheck Suppression</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="live_email_handoff_control">
                                    <input type="hidden" name="handoff_id" value="<?php echo (int) $handoff['id']; ?>">
                                    <input type="hidden" name="handoff_action" value="mark_reviewed">
                                    <button class="btn-premium-secondary" type="submit">Mark Reviewed</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManageMarketing): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="live_email_handoff_control">
                                    <input type="hidden" name="handoff_id" value="<?php echo (int) $handoff['id']; ?>">
                                    <input type="hidden" name="handoff_action" value="retry">
                                    <button class="btn-premium-secondary" type="submit">Retry</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="live_email_handoff_control">
                                    <input type="hidden" name="handoff_id" value="<?php echo (int) $handoff['id']; ?>">
                                    <input type="hidden" name="handoff_action" value="cancel">
                                    <button class="btn-premium-secondary" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="channel-grid marketing-channel-export-grid-section">
            <div class="content-card">
                <div class="premium-section-header">
                    <div><h2>Channel Connectors</h2><p>Plan connector readiness, capabilities, dry-run diagnostics, and opt-in live execution settings.</p></div>
                </div>
                <div class="marketing-meta marketing-channel-export-meta-strip">
                    <span><?php echo (int) ($connectorDiagnostics['total_connectors'] ?? 0); ?> connector(s)</span>
                    <span><?php echo (int) ($connectorDiagnostics['ready_connectors'] ?? 0); ?> ready</span>
                    <span><?php echo (int) ($connectorDiagnostics['blocked_connectors'] ?? 0); ?> blocked</span>
                    <span><?php echo (int) ($connectorReadinessSummary['average_readiness_score'] ?? 0); ?>% avg setup readiness</span>
                    <span><?php echo (int) ($connectorReadinessSummary['needs_review_connectors'] ?? 0); ?> setup review needed</span>
                    <span><?php echo (int) ($connectorDiagnostics['live_enabled_connectors'] ?? 0); ?> live-enabled</span>
                    <span><?php echo (int) ($connectorDiagnostics['live_preflight_passed_connectors'] ?? 0); ?> live preflight passed</span>
                </div>
                <?php if (empty($connectors)): ?>
                    <div class="empty-state"><p>No channel connectors are configured yet. Add dry-run connector records to document readiness before any controlled execution phase.</p></div>
                <?php else: foreach ($connectors as $connector): ?>
                    <div class="channel-row">
                        <div>
                            <strong><?php echo htmlspecialchars((string) $connector['name']); ?></strong>
                            <div class="marketing-meta">
                                <span><?php echo htmlspecialchars($labelize((string) $connector['connector_type'])); ?></span>
                                <span><?php echo htmlspecialchars($labelize((string) $connector['status'])); ?></span>
                                <span><?php echo htmlspecialchars($labelize((string) $connector['execution_mode'])); ?></span>
                                <span>Setup: <?php echo htmlspecialchars($labelize((string) ($connector['setup_status'] ?? 'not_started'))); ?></span>
                                <span>Readiness: <?php echo (int) ($connector['readiness_score'] ?? $connector['last_readiness_score'] ?? 0); ?>%</span>
                                <span>Health: <?php echo htmlspecialchars($labelize((string) ($connector['health_status'] ?? $connector['last_health_probe_status'] ?? 'unknown'))); ?> <?php echo (int) ($connector['health_score'] ?? $connector['last_health_probe_score'] ?? 0); ?>%</span>
                                <?php if (!empty($connector['last_health_probe_at'])): ?><span>Health Probe: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $connector['last_health_probe_at']))); ?></span><?php endif; ?>
                                <span>Hourly Cap: <?php echo (int) ($connector['live_hourly_cap'] ?? 0); ?></span>
                                <span>Daily Cap: <?php echo (int) ($connector['live_daily_cap'] ?? 0); ?></span>
                                <span>Cooldown: <?php echo (int) ($connector['live_cooldown_seconds'] ?? 0); ?>s</span>
                                <span>Live: <?php echo !empty($connector['live_enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                <span>Preflight: <?php echo htmlspecialchars($labelize((string) ($connector['live_preflight_status'] ?? 'not_checked'))); ?></span>
                                <span>Secret: <?php echo htmlspecialchars($labelize((string) ($connector['secret_status'] ?? 'not_configured'))); ?></span>
                                <span>Secret Store: <?php echo (int) ($connector['active_secret_count'] ?? 0) > 0 ? 'Stored' : 'Missing'; ?></span>
                                <?php if (!empty($connector['active_secret_verification_status'])): ?><span>Credential Check: <?php echo htmlspecialchars($labelize((string) $connector['active_secret_verification_status'])); ?></span><?php endif; ?>
                                <?php if (!empty($connector['active_secret_last_verified_at'])): ?><span>Verified: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $connector['active_secret_last_verified_at']))); ?></span><?php endif; ?>
                                <?php if (!empty($connector['active_secret_rotated_at'])): ?><span>Rotated: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $connector['active_secret_rotated_at']))); ?></span><?php endif; ?>
                                <?php if (!empty($connector['active_secret_freshness_status'])): ?><span>Freshness: <?php echo htmlspecialchars($labelize((string) $connector['active_secret_freshness_status'])); ?></span><?php endif; ?>
                                <?php if (!empty($connector['active_secret_expires_at'])): ?><span>Expires: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $connector['active_secret_expires_at']))); ?></span><?php endif; ?>
                                <?php if (!empty($connector['active_secret_rotation_due_at'])): ?><span>Rotate By: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $connector['active_secret_rotation_due_at']))); ?></span><?php endif; ?>
                                <?php if ((int) ($connector['revoked_secret_count'] ?? 0) > 0): ?><span><?php echo (int) $connector['revoked_secret_count']; ?> revoked</span><?php endif; ?>
                                <?php if ((int) ($connector['secret_event_count'] ?? 0) > 0): ?><span><?php echo (int) $connector['secret_event_count']; ?> credential event(s)</span><?php endif; ?>
                                <span><?php echo (int) ($connector['test_run_count'] ?? 0); ?> test(s)</span>
                                <?php if (!empty($connector['last_test_status'])): ?><span>Last: <?php echo htmlspecialchars($labelize((string) $connector['last_test_status'])); ?></span><?php endif; ?>
                                <?php if (!empty($connector['last_readiness_status'])): ?><span>Review: <?php echo htmlspecialchars($labelize((string) $connector['last_readiness_status'])); ?></span><?php endif; ?>
                            </div>
                            <?php $missing = (array) ($connector['diagnostics_json']['missing_capabilities'] ?? []); if (!empty($missing)): ?><div class="marketing-meta">Missing: <?php echo htmlspecialchars(implode(', ', $missing)); ?></div><?php endif; ?>
                            <?php $readinessMissing = (array) ($connector['last_readiness_missing_checks_json'] ?? $connector['diagnostics_json']['readiness_missing_checks'] ?? []); if (!empty($readinessMissing)): ?><div class="marketing-meta">Setup gaps: <?php echo htmlspecialchars(implode(', ', $readinessMissing)); ?></div><?php endif; ?>
                            <?php $preflightBlockers = (array) ($connector['live_preflight_json']['live_preflight_blockers'] ?? []); if (!empty($preflightBlockers)): ?><div class="marketing-meta">Live preflight blockers: <?php echo htmlspecialchars(implode(', ', $preflightBlockers)); ?></div><?php endif; ?>
                            <?php if (!empty($connector['setup_checklist_json'])): ?><div class="marketing-meta">Checklist: <?php echo htmlspecialchars(implode(', ', array_slice((array) $connector['setup_checklist_json'], 0, 4))); ?></div><?php endif; ?>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <div class="marketing-channel-export-actions">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="test_connector">
                                    <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                    <input type="hidden" name="test_mode" value="dry_run">
                                    <button class="btn-premium-secondary" type="submit">Run Dry-Run Test</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="evaluate_connector_readiness">
                                    <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                    <button class="btn-premium-secondary" type="submit">Review Readiness</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="run_connector_health_probe">
                                    <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                    <input type="hidden" name="probe_mode" value="local_adapter">
                                    <button class="btn-premium-secondary" type="submit">Run Health Probe</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if ($canManageMarketing): ?>
                            <div class="marketing-channel-export-actions marketing-channel-export-full">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="test_connector">
                                    <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                    <input type="hidden" name="test_mode" value="live_preflight">
                                    <button class="btn-premium-secondary" type="submit">Run Live Preflight</button>
                                </form>
                            </div>
                            <form method="POST" class="marketing-channel-export-nested-form marketing-channel-export-full">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="update_connector_live">
                                <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                <label class="marketing-channel-export-check"><input type="checkbox" name="live_enabled" value="1" <?php echo !empty($connector['live_enabled']) ? 'checked' : ''; ?>> Live capable</label>
                                <div class="form-group"><label>Adapter Key</label><input type="text" name="live_adapter_key" value="<?php echo htmlspecialchars((string) ($connector['live_adapter_key'] ?? '')); ?>" placeholder="crm_email_queue_handoff"></div>
                                <div class="form-group"><label>Hourly Live Cap</label><input type="number" min="0" max="100000" name="live_hourly_cap" value="<?php echo (int) ($connector['live_hourly_cap'] ?? 0); ?>"></div>
                                <div class="form-group"><label>Daily Live Cap</label><input type="number" min="0" max="1000000" name="live_daily_cap" value="<?php echo (int) ($connector['live_daily_cap'] ?? 0); ?>"></div>
                                <div class="form-group"><label>Cooldown Seconds</label><input type="number" min="0" max="86400" name="live_cooldown_seconds" value="<?php echo (int) ($connector['live_cooldown_seconds'] ?? 0); ?>"></div>
                                <div class="form-group"><label>Secret Reference</label><input type="text" name="secret_reference" value="<?php echo htmlspecialchars((string) ($connector['secret_reference'] ?? '')); ?>" placeholder="vault://marketing/email/main"></div>
                                <div class="form-group"><label>Secret Status</label><select name="secret_status"><?php foreach (Marketing::LIVE_EXECUTION_SECRET_STATUSES as $secretStatus): ?><option value="<?php echo htmlspecialchars($secretStatus); ?>" <?php echo $selected($connector['secret_status'] ?? 'not_configured', $secretStatus); ?>><?php echo htmlspecialchars($labelize($secretStatus)); ?></option><?php endforeach; ?></select></div>
                                <button class="btn-premium-secondary" type="submit">Save Live Settings</button>
                            </form>
                            <form method="POST" class="marketing-channel-export-nested-form marketing-channel-export-full">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="save_connector_secret">
                                <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                <input type="hidden" name="secret_reference" value="<?php echo htmlspecialchars((string) (($connector['secret_reference'] ?? '') ?: 'vault://marketing/connector/' . (int) $connector['id'])); ?>">
                                <div class="form-group"><label>Secret Type</label><select name="secret_type"><?php foreach (Marketing::LIVE_CONNECTOR_SECRET_TYPES as $secretType): ?><option value="<?php echo htmlspecialchars($secretType); ?>"><?php echo htmlspecialchars($labelize($secretType)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Header Name</label><input type="text" name="header_name" value="Authorization" placeholder="Authorization"></div>
                                <div class="form-group"><label>Header Prefix</label><input type="text" name="header_prefix" value="Bearer" placeholder="Bearer"></div>
                                <div class="form-group"><label>Expires At</label><input type="datetime-local" name="expires_at"></div>
                                <div class="form-group"><label>Rotation Due At</label><input type="datetime-local" name="rotation_due_at"></div>
                                <div class="form-group"><label>Rotation Interval Days</label><input type="number" min="0" max="3650" name="rotation_interval_days" placeholder="90"></div>
                                <div class="form-group"><label>Secret Value</label><input type="password" name="secret_value" autocomplete="new-password" placeholder="<?php echo (int) ($connector['active_secret_count'] ?? 0) > 0 ? 'Replace stored secret' : 'Paste live credential'; ?>"></div>
                                <p class="marketing-meta marketing-channel-export-meta-block">Stored encrypted. Expired or rotation-overdue credentials cannot pass live preflight. The raw value is never rendered in diagnostics, attempts, or page output.</p>
                                <button class="btn-premium-secondary" type="submit">Save Encrypted Secret</button>
                            </form>
                            <div class="marketing-channel-export-nested-form marketing-channel-export-full">
                                <div class="marketing-channel-export-actions marketing-channel-export-start">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="verify_connector_secret">
                                        <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                        <button class="btn-premium-secondary" type="submit">Verify Stored Secret</button>
                                    </form>
                                    <form method="POST" class="marketing-channel-export-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="revoke_connector_secret">
                                        <input type="hidden" name="connector_id" value="<?php echo (int) $connector['id']; ?>">
                                        <div class="form-group marketing-channel-export-zero"><label>Revocation Reason</label><input type="text" name="revocation_reason" placeholder="Rotated out of service"></div>
                                        <button class="btn-premium-secondary" type="submit">Revoke Stored Secret</button>
                                    </form>
                                </div>
                                <p class="marketing-meta marketing-channel-export-meta-block">Verification decrypts locally only. Revocation blocks live preflight and disables live capability until a new credential is saved.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Add Connector</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="create_connector">
                        <input type="hidden" name="owner_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                        <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="LinkedIn manual connector"></div>
                        <div class="form-group"><label>Type</label><select name="connector_type"><?php foreach (Marketing::CHANNEL_CONNECTOR_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::CHANNEL_CONNECTOR_STATUSES as $connectorStatus): ?><option value="<?php echo htmlspecialchars($connectorStatus); ?>"><?php echo htmlspecialchars($labelize($connectorStatus)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Mode</label><select name="execution_mode"><option value="dry_run">Dry Run</option><option value="test">Test</option><option value="live_disabled">Live Disabled</option></select></div>
                        <?php if ($canManageMarketing): ?>
                            <label class="marketing-channel-export-check marketing-channel-export-check-spaced"><input type="checkbox" name="live_enabled" value="1"> Live capable after approval</label>
                            <div class="form-group"><label>Live Adapter Key</label><input type="text" name="live_adapter_key" placeholder="crm_email_queue_handoff"></div>
                            <div class="form-group"><label>Hourly Live Cap</label><input type="number" min="0" max="100000" name="live_hourly_cap" value="0"></div>
                            <div class="form-group"><label>Daily Live Cap</label><input type="number" min="0" max="1000000" name="live_daily_cap" value="0"></div>
                            <div class="form-group"><label>Cooldown Seconds</label><input type="number" min="0" max="86400" name="live_cooldown_seconds" value="0"></div>
                            <div class="form-group"><label>Secret Reference</label><input type="text" name="secret_reference" placeholder="vault://marketing/channel/main"></div>
                            <div class="form-group"><label>Secret Status</label><select name="secret_status"><?php foreach (Marketing::LIVE_EXECUTION_SECRET_STATUSES as $secretStatus): ?><option value="<?php echo htmlspecialchars($secretStatus); ?>"><?php echo htmlspecialchars($labelize($secretStatus)); ?></option><?php endforeach; ?></select></div>
                        <?php endif; ?>
                        <div class="form-group"><label>Capabilities</label><textarea name="capabilities" rows="3" placeholder="copy_export, media_kit_export, dry_run"></textarea></div>
                        <div class="form-group"><label>Setup Checklist</label><textarea name="setup_checklist" rows="3" placeholder="consent policy reviewed&#10;suppression list reviewed&#10;manual export owner named"></textarea></div>
                        <div class="form-group"><label>Setup Notes</label><textarea name="setup_notes" rows="2" placeholder="Manual-first connector. No external API key required yet."></textarea></div>
                        <div class="form-group"><label>Visible Config JSON</label><textarea name="config" rows="3" placeholder='{"workspace_note":"Manual export only"}'></textarea></div>
                        <p class="marketing-meta marketing-channel-export-meta-block">Typical required setup: <?php echo htmlspecialchars(implode(', ', (array) ($connectorSetupDefinitions['email']['required'] ?? []))); ?></p>
                        <button class="btn-premium-primary" type="submit">Save Connector</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card marketing-channel-export-section">
            <div class="premium-section-header"><h2>Recent Connector Test Runs</h2></div>
            <?php if (empty($connectorTests)): ?><div class="empty-state"><p>No connector dry-run tests yet.</p></div><?php else: foreach ($connectorTests as $test): ?>
                <div class="channel-row">
                    <div><strong><?php echo htmlspecialchars((string) ($test['connector_name'] ?? 'Connector')); ?></strong><div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) $test['connector_type'])); ?></span><span><?php echo htmlspecialchars($labelize((string) $test['status'])); ?></span><span><?php echo htmlspecialchars($labelize((string) $test['test_mode'])); ?></span><span>No external API call</span></div></div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="content-card marketing-channel-export-section">
            <div class="premium-section-header"><h2>Recent Connector Readiness Reviews</h2><p>Setup readiness reviews score dry-run capabilities, consent safeguards, ownership, diagnostics, and manual-first boundaries.</p></div>
            <?php if (empty($connectorReadinessReviews)): ?><div class="empty-state"><p>No connector readiness reviews yet.</p></div><?php else: foreach ($connectorReadinessReviews as $review): ?>
                <div class="channel-row">
                    <div>
                        <strong><?php echo htmlspecialchars((string) ($review['connector_name'] ?? 'Connector')); ?></strong>
                        <div class="marketing-meta">
                            <span><?php echo htmlspecialchars($labelize((string) ($review['connector_type'] ?? 'other'))); ?></span>
                            <span><?php echo htmlspecialchars($labelize((string) ($review['status'] ?? 'needs_attention'))); ?></span>
                            <span><?php echo (int) ($review['readiness_score'] ?? 0); ?>% ready</span>
                            <span>No external API call</span>
                        </div>
                        <?php if (!empty($review['missing_checks_json'])): ?><div class="marketing-meta">Missing: <?php echo htmlspecialchars(implode(', ', (array) $review['missing_checks_json'])); ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="channel-grid marketing-channel-export-grid-section">
            <div class="content-card">
                <div class="premium-section-header"><h2>Live Rehearsals</h2><p>Run a dry rehearsal from a content or export bundle, then promote it to a live queue only after manager approval. No external send happens during rehearsal.</p></div>
                <?php if (empty($liveRehearsals)): ?><div class="empty-state"><p>No live rehearsals yet.</p></div><?php else: foreach ($liveRehearsals as $rehearsal): ?>
                    <div class="channel-row">
                        <div>
                            <strong><?php echo htmlspecialchars($labelize((string) ($rehearsal['execution_type'] ?? 'other'))); ?> rehearsal</strong>
                            <div class="marketing-meta">
                                <span><?php echo htmlspecialchars($labelize((string) ($rehearsal['status'] ?? 'draft'))); ?></span>
                                <span><?php echo htmlspecialchars((string) ($rehearsal['connector_name'] ?? 'No connector')); ?></span>
                                <span><?php echo htmlspecialchars((string) ($rehearsal['content_title'] ?? $rehearsal['target_channel'] ?? 'No content')); ?></span>
                                <?php if (!empty($rehearsal['dry_run_evidence_json']['dry_run_status'])): ?><span>Dry-run: <?php echo htmlspecialchars($labelize((string) $rehearsal['dry_run_evidence_json']['dry_run_status'])); ?></span><?php endif; ?>
                                <?php if (!empty($rehearsal['promoted_queue_id'])): ?><span>Queue #<?php echo (int) $rehearsal['promoted_queue_id']; ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($rehearsal['approval_json']['decision_note'])): ?><div class="marketing-meta">Decision: <?php echo htmlspecialchars((string) $rehearsal['approval_json']['decision_note']); ?></div><?php endif; ?>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <div class="marketing-channel-export-actions">
                                <?php if (!in_array((string) ($rehearsal['status'] ?? ''), ['approved', 'promoted', 'cancelled', 'rejected'], true)): ?>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="run_live_rehearsal"><input type="hidden" name="rehearsal_id" value="<?php echo (int) $rehearsal['id']; ?>"><button class="btn-premium-secondary" type="submit">Run Dry Rehearsal</button></form>
                                <?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($rehearsal['status'] ?? '') === 'dry_run_passed'): ?>
                                    <form method="POST" class="marketing-channel-export-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="approve_live_rehearsal">
                                        <input type="hidden" name="rehearsal_id" value="<?php echo (int) $rehearsal['id']; ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <input class="form-control marketing-channel-export-input-md" type="text" name="decision_note" placeholder="Approved after dry-run">
                                        <button class="btn-premium-primary" type="submit">Approve Rehearsal</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($rehearsal['status'] ?? '') === 'approved'): ?>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="promote_live_rehearsal"><input type="hidden" name="rehearsal_id" value="<?php echo (int) $rehearsal['id']; ?>"><button class="btn-premium-primary" type="submit">Promote to Live Queue</button></form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Create Live Rehearsal</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="create_live_rehearsal">
                        <div class="form-group"><label>Source Type</label><select name="source_type"><option value="channel_export_bundle">Channel Export Bundle</option><option value="content">Content</option><option value="distribution_post">Distribution Post</option><option value="other">Other</option></select></div>
                        <div class="form-group"><label>Execution Type</label><select name="execution_type"><?php foreach (Marketing::EXECUTION_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Connector</label><select name="connector_id"><option value="">None</option><?php foreach ($connectors as $connector): ?><option value="<?php echo (int) $connector['id']; ?>"><?php echo htmlspecialchars((string) $connector['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Channel Bundle</label><select name="channel_export_bundle_id"><option value="">None</option><?php foreach ($bundles as $bundle): ?><option value="<?php echo (int) $bundle['id']; ?>"><?php echo htmlspecialchars((string) ($bundle['content_title'] ?? 'Bundle') . ' - ' . $labelize((string) $bundle['channel'])); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Target Channel</label><input type="text" name="target_channel" placeholder="email, sms, website webhook"></div>
                        <div class="form-group"><label>Target Recipient</label><input type="text" name="target_recipient" placeholder="test recipient or channel destination"></div>
                        <div class="form-group"><label>Checklist</label><textarea name="checklist" rows="3" placeholder="Content reviewed&#10;Audience checked&#10;Final approver named"></textarea></div>
                        <button class="btn-premium-primary" type="submit">Create Rehearsal</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="channel-grid marketing-channel-export-grid-section">
            <div class="content-card">
                <div class="premium-section-header"><h2>Controlled Execution Queue</h2><p>Queue test, dry-run, or approved live execution packages. Live mode requires a ready connector, passed live preflight, explicit approval, final RUN LIVE confirmation, and an implemented live adapter.</p></div>
                <?php if (empty($executionQueue)): ?><div class="empty-state"><p>No execution queue items yet.</p></div><?php else: foreach ($executionQueue as $queue): ?>
                    <div class="channel-row">
                        <div>
                            <strong><?php echo htmlspecialchars($labelize((string) $queue['execution_type'])); ?> execution</strong>
                            <div class="marketing-meta">
                                <span><?php echo htmlspecialchars($labelize((string) $queue['status'])); ?></span>
                                <span><?php echo htmlspecialchars($labelize((string) $queue['execution_mode'])); ?></span>
                                <span><?php echo htmlspecialchars((string) ($queue['connector_name'] ?? 'No connector')); ?></span>
                                <span><?php echo (int) ($queue['attempt_count'] ?? 0); ?> attempt(s)</span>
                                <?php if (!empty($queue['approval_status'])): ?><span>Approval: <?php echo htmlspecialchars($labelize((string) $queue['approval_status'])); ?></span><?php endif; ?>
                                <?php if ((string) ($queue['execution_mode'] ?? '') === 'live'): ?><span>Dispatch: <?php echo htmlspecialchars($labelize((string) ($queue['live_dispatch_status'] ?? 'not_ready'))); ?></span><?php endif; ?>
                                <?php if ((string) ($queue['execution_mode'] ?? '') === 'live'): ?><span>Outcome: <?php echo htmlspecialchars($labelize((string) ($queue['live_outcome_status'] ?? 'not_started'))); ?></span><?php endif; ?>
                                <?php if ((string) ($queue['execution_mode'] ?? '') === 'live'): ?><span>Retry: <?php echo htmlspecialchars($labelize((string) ($queue['live_retry_status'] ?? 'none'))); ?><?php echo !empty($queue['live_retry_after']) ? ' after ' . htmlspecialchars((string) $queue['live_retry_after']) : ''; ?></span><?php endif; ?>
                                <?php if ((string) ($queue['execution_mode'] ?? '') === 'live'): ?><span><?php echo (int) ($queue['live_retry_count'] ?? 0); ?>/<?php echo (int) ($queue['live_max_retries'] ?? 0); ?> retry</span><?php endif; ?>
                                <?php if (!empty($queue['live_rehearsal_run_id'])): ?><span>Rehearsal: <?php echo htmlspecialchars($labelize((string) ($queue['live_rehearsal_status'] ?? 'attached'))); ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($queue['readiness_json']['blocked_reasons'])): ?><div class="marketing-meta">Blocked: <?php echo htmlspecialchars(implode(', ', (array) $queue['readiness_json']['blocked_reasons'])); ?></div><?php endif; ?>
                            <?php if (!empty($queue['live_retry_reason'])): ?><div class="marketing-meta">Retry note: <?php echo htmlspecialchars((string) $queue['live_retry_reason']); ?></div><?php endif; ?>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <div class="marketing-channel-export-actions">
                                <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="run_execution"><input type="hidden" name="queue_id" value="<?php echo (int) $queue['id']; ?>"><input type="hidden" name="attempt_mode" value="dry_run"><button class="btn-premium-secondary" type="submit">Dry Run</button></form>
                                <?php if ((string) ($queue['approval_status'] ?? '') !== 'pending'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="request_execution_approval"><input type="hidden" name="queue_id" value="<?php echo (int) $queue['id']; ?>"><button class="btn-premium-secondary" type="submit">Request Approval</button></form><?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($queue['approval_status'] ?? '') === 'pending'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="approve_execution"><input type="hidden" name="approval_id" value="<?php echo (int) ($queue['approval_id'] ?? 0); ?>"><input type="hidden" name="decision" value="approved"><button class="btn-premium-primary" type="submit">Approve</button></form><?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($queue['execution_mode'] ?? '') === 'live' && (string) ($queue['approval_status'] ?? '') === 'approved' && (string) ($queue['status'] ?? '') !== 'succeeded'): ?>
                                    <form method="POST" class="marketing-channel-export-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="run_execution">
                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queue['id']; ?>">
                                        <input type="hidden" name="attempt_mode" value="live">
                                        <input class="form-control marketing-channel-export-input-sm" type="text" name="live_confirmation" placeholder="RUN LIVE" aria-label="Type RUN LIVE to execute live">
                                        <button class="btn-premium-primary" type="submit">Run Live</button>
                                    </form>
                                <?php elseif (!$canManageMarketing && (string) ($queue['execution_mode'] ?? '') === 'live' && (string) ($queue['approval_status'] ?? '') === 'approved' && (string) ($queue['status'] ?? '') !== 'succeeded'): ?>
                                    <div class="marketing-meta marketing-channel-export-right-note">Approved live item is ready for a manager to run or confirm dispatch.</div>
                                <?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($queue['execution_mode'] ?? '') === 'live' && (string) ($queue['approval_status'] ?? '') === 'approved' && (string) ($queue['status'] ?? '') !== 'succeeded'): ?>
                                    <form method="POST" class="marketing-channel-export-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="confirm_live_dispatch">
                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queue['id']; ?>">
                                        <input class="form-control marketing-channel-export-input-sm" type="text" name="live_confirmation" placeholder="RUN LIVE" aria-label="Type RUN LIVE to confirm live dispatch">
                                        <button class="btn-premium-secondary" type="submit">Confirm Dispatch</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManageMarketing && (string) ($queue['execution_mode'] ?? '') === 'live' && in_array((string) ($queue['live_dispatch_status'] ?? ''), ['confirmed', 'blocked', 'failed'], true) && (string) ($queue['status'] ?? '') !== 'succeeded'): ?>
                                    <form method="POST" class="marketing-channel-export-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="cancel_live_dispatch">
                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queue['id']; ?>">
                                        <input class="form-control marketing-channel-export-input-md" type="text" name="cancel_reason" placeholder="Cancel before retry" aria-label="Live dispatch cancel reason">
                                        <button class="btn-premium-secondary" type="submit">Cancel Dispatch</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Create Queue Item</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="create_execution_queue">
                        <div class="form-group"><label>Execution Type</label><select name="execution_type"><?php foreach (Marketing::EXECUTION_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Execution Mode</label><select name="execution_mode"><option value="dry_run">Dry Run</option><option value="test">Test</option><option value="live">Live (blocked until approved and adapted)</option></select></div>
                        <div class="form-group"><label>Live Rerun Policy</label><select name="live_rerun_policy"><?php foreach (Marketing::LIVE_EXECUTION_RERUN_POLICIES as $policy): ?><option value="<?php echo htmlspecialchars($policy); ?>"><?php echo htmlspecialchars($labelize($policy)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Max Live Retries</label><input type="number" min="0" max="25" name="live_max_retries" value="0"></div>
                        <div class="form-group"><label>Retry Backoff Seconds</label><input type="number" min="60" max="604800" name="live_retry_backoff_seconds" value="900"></div>
                        <div class="form-group"><label>Connector</label><select name="connector_id"><option value="">None</option><?php foreach ($connectors as $connector): ?><option value="<?php echo (int) $connector['id']; ?>"><?php echo htmlspecialchars((string) $connector['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Channel Bundle</label><select name="channel_export_bundle_id"><option value="">None</option><?php foreach ($bundles as $bundle): ?><option value="<?php echo (int) $bundle['id']; ?>"><?php echo htmlspecialchars((string) ($bundle['content_title'] ?? 'Bundle') . ' - ' . $labelize((string) $bundle['channel'])); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Payload JSON</label><textarea name="payload" rows="3" placeholder='{"manual_package":"ready for dry-run"}'></textarea></div>
                        <button class="btn-premium-primary" type="submit">Create Queue Item</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card marketing-channel-export-section">
            <div class="premium-section-header"><h2>Recent Execution Attempts</h2></div>
            <?php if (empty($executionAttempts)): ?><div class="empty-state"><p>No execution attempts have been recorded.</p></div><?php else: foreach ($executionAttempts as $attempt): ?>
                <div class="channel-row"><div><strong><?php echo htmlspecialchars($labelize((string) $attempt['execution_type'])); ?></strong><div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) $attempt['status'])); ?></span><span><?php echo htmlspecialchars($labelize((string) $attempt['attempt_mode'])); ?></span><span>No external execution</span></div></div></div>
            <?php endforeach; endif; ?>
        </div>

        <div class="channel-grid">
            <div class="content-card">
                <div class="premium-section-header"><h2>Bundles</h2></div>
                <?php if (empty($bundles)): ?>
                    <div class="empty-state"><p>No channel export bundles match this view.</p><?php if ($canWriteMarketing): ?><a href="#create-channel-bundle">Create from distribution queue</a><?php endif; ?></div>
                <?php else: ?>
                    <?php foreach ($bundles as $bundle): ?>
                        <div class="channel-row">
                            <div>
                                <strong><?php echo htmlspecialchars((string) $bundle['content_title']); ?></strong>
                                <div class="marketing-meta">
                                    <span><?php echo htmlspecialchars($labelize((string) $bundle['channel'])); ?></span>
                                    <span><?php echo htmlspecialchars($labelize((string) $bundle['bundle_type'])); ?></span>
                                    <span><?php echo htmlspecialchars($labelize((string) $bundle['status'])); ?></span>
                                    <span><?php echo !empty($bundle['asset_readiness_json']['ready']) ? 'Asset ready' : 'Asset needed'; ?></span>
                                    <?php if (!empty($bundle['media_kit_title'])): ?><span>Kit: <?php echo htmlspecialchars((string) $bundle['media_kit_title']); ?></span><?php endif; ?>
                                </div>
                                <details class="marketing-channel-export-payload"><summary>Payload</summary><pre class="bundle-pre"><?php echo htmlspecialchars(json_encode($bundle['export_payload_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                            </div>
                            <?php if ($canWriteMarketing && (string) $bundle['status'] !== 'exported'): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="export">
                                    <input type="hidden" name="bundle_id" value="<?php echo (int) $bundle['id']; ?>">
                                    <button class="btn-premium-secondary" type="submit">Mark Exported</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="content-card" id="create-channel-bundle">
                <div class="premium-section-header"><h2>Create Bundle</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="form-group">
                            <label>Distribution Post</label>
                            <select name="distribution_post_id" required>
                                <option value="">Select post</option>
                                <?php foreach ($posts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars((string) $post['content_title'] . ' - ' . $labelize((string) $post['channel'])); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn-premium-primary" type="submit">Create Channel Bundle</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="channel-grid marketing-channel-export-grid-top">
            <div class="content-card">
                <div class="premium-section-header"><h2>Channel Media Kits</h2></div>
                <?php if (empty($mediaKits)): ?>
                    <div class="empty-state"><p>No media kits match this view.</p><?php if ($canWriteMarketing): ?><a href="#create-media-kit">Create a manual media kit</a><?php endif; ?></div>
                <?php else: ?>
                    <?php foreach ($mediaKits as $kit): ?>
                        <div class="channel-row">
                            <div>
                                <strong><?php echo htmlspecialchars((string) $kit['title']); ?></strong>
                                <div class="marketing-meta">
                                    <span><?php echo htmlspecialchars($labelize((string) $kit['channel'])); ?></span>
                                    <span><?php echo htmlspecialchars($labelize((string) $kit['status'])); ?></span>
                                    <span><?php echo !empty($kit['readiness_json']['ready']) ? 'Ready' : 'Needs media/details'; ?></span>
                                    <?php if (!empty($kit['utm_generated_url'])): ?><a href="<?php echo htmlspecialchars((string) $kit['utm_generated_url']); ?>" target="_blank" rel="noopener">UTM link</a><?php endif; ?>
                                </div>
                                <?php if (!empty($kit['primary_media_display_url'])): ?>
                                    <div class="marketing-channel-export-media-preview">
                                        <?php if (in_array((string) ($kit['primary_media_type'] ?? ''), ['image','logo','thumbnail','banner'], true)): ?>
                                            <img class="media-preview" src="<?php echo htmlspecialchars((string) $kit['primary_media_display_url']); ?>" alt="<?php echo htmlspecialchars((string) ($kit['primary_media_alt_text'] ?? $kit['primary_media_title'] ?? 'Media preview')); ?>">
                                        <?php elseif ((string) ($kit['primary_media_type'] ?? '') === 'video'): ?>
                                            <video class="media-preview" controls src="<?php echo htmlspecialchars((string) $kit['primary_media_display_url']); ?>"></video>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($kit['readiness_json']['ready'])): ?><div class="marketing-meta">Missing: <?php echo htmlspecialchars(implode(', ', (array) ($kit['readiness_json']['missing'] ?? []))); ?></div><?php endif; ?>
                                <details class="marketing-channel-export-payload"><summary>Export package</summary><pre class="bundle-pre"><?php echo htmlspecialchars(json_encode($kit['export_payload_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                            </div>
                            <?php if ($canWriteMarketing): ?>
                                <div class="marketing-channel-export-actions">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="generate_media_kit_payload">
                                        <input type="hidden" name="media_kit_id" value="<?php echo (int) $kit['id']; ?>">
                                        <button class="btn-premium-secondary" type="submit">Refresh Package</button>
                                    </form>
                                    <?php if (!empty($kit['readiness_json']['ready']) && (string) $kit['status'] !== 'exported'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="export_media_kit">
                                            <input type="hidden" name="media_kit_id" value="<?php echo (int) $kit['id']; ?>">
                                            <button class="btn-premium-primary" type="submit">Mark Exported</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="content-card" id="create-media-kit">
                <div class="premium-section-header"><h2>Create Media Kit</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="create_media_kit">
                        <div class="form-group">
                            <label>Distribution Post</label>
                            <select name="distribution_post_id" required>
                                <option value="">Select post</option>
                                <?php foreach ($posts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars((string) $post['content_title'] . ' - ' . $labelize((string) $post['channel'])); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">Use source content</option><?php foreach ($options['landing_pages'] as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Primary Media</label><select name="primary_media_file_id"><option value="">Use first attached media</option><?php foreach ($options['media_files'] as $media): ?><option value="<?php echo (int) $media['id']; ?>"><?php echo htmlspecialchars((string) $media['title'] . ' - ' . $labelize((string) $media['media_type'])); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>UTM Link</label><select name="utm_link_id"><option value="">Use latest source UTM</option><?php foreach ($options['utm_links'] as $utm): ?><option value="<?php echo (int) $utm['id']; ?>"><?php echo htmlspecialchars((string) ($utm['generated_url'] ?? $utm['url'] ?? 'UTM link')); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Destination URL</label><input type="url" name="destination_url" placeholder="https://example.com/campaign"></div>
                        <button class="btn-premium-primary" type="submit">Create Media Kit</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </div>
        </div>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
