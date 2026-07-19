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
use CRM\Services\OnboardingLifecycleNudgeService;
use CRM\Services\PlatformImpersonationService;
use CRM\Services\PlatformRuntimeOperationsService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!PlatformWorkspaceOperationsService::isPlatformAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}
$isSuperAdmin = Authorization::isSuperAdmin($user);

function workspaceAdminUrl(int $workspaceId, array $params = []): string
{
    $query = array_merge(['workspace_id' => $workspaceId], $params);
    return 'workspace_admin.php?' . http_build_query($query);
}

function workspaceAdminRedirect(int $workspaceId, array $params = []): void
{
    header('Location: ' . workspaceAdminUrl($workspaceId, $params));
    exit;
}

function workspaceAdminReason(?string $value): ?string
{
    $reason = trim((string) $value);
    return $reason !== '' ? $reason : null;
}

function workspaceAdminFormatAge(?string $dateTime): string
{
    if ($dateTime === null || trim($dateTime) === '') {
        return 'n/a';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return 'n/a';
    }

    $minutes = max(0, (int) floor((time() - $timestamp) / 60));
    if ($minutes < 60) {
        return $minutes . 'm ago';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . 'h ago';
    }

    return (int) floor($hours / 24) . 'd ago';
}

$workspaceId = (int) ($_REQUEST['workspace_id'] ?? $_GET['workspace_id'] ?? 0);
if ($workspaceId <= 0) {
    header('Location: workspaces.php?error=' . rawurlencode('Choose a workspace first.'));
    exit;
}

$operations = new PlatformWorkspaceOperationsService();
$runtimeOperations = new PlatformRuntimeOperationsService();
$impersonation = new PlatformImpersonationService();
$workspaceGovernance = new WorkspaceGovernanceService();
$onboardingNudges = new OnboardingLifecycleNudgeService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        workspaceAdminRedirect($workspaceId, ['error' => 'Invalid security token. Please try again.']);
    }

    $action = trim((string) ($_POST['admin_action'] ?? ''));

    try {
        if ($action === 'lifecycle') {
            $lifecycleAction = trim((string) ($_POST['lifecycle_action'] ?? ''));
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required for lifecycle changes.');
            }

            $operations->updateWorkspaceLifecycle($workspaceId, $lifecycleAction, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace lifecycle updated successfully.']);
        }

        if ($action === 'wallet_adjustment') {
            $tokenDelta = (int) ($_POST['token_delta'] ?? 0);
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($tokenDelta === 0) {
                throw new RuntimeException('Enter a non-zero AI Credit adjustment.');
            }
            if ($reason === null) {
                throw new RuntimeException('A reason is required for AI Credit adjustments.');
            }

            $operations->adjustWorkspaceWallet($workspaceId, $tokenDelta, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'AI Credit adjustment recorded successfully.']);
        }

        if ($action === 'update_subscription_price') {
            if (!$isSuperAdmin) {
                throw new RuntimeException('Only Super Admin can update subscription pricing.');
            }
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required for pricing changes.');
            }

            $operations->updateSubscriptionPrice(
                (int) ($_POST['billing_plan_price_id'] ?? 0),
                (float) ($_POST['amount'] ?? 0),
                (string) ($_POST['currency'] ?? ''),
                (int) ($_POST['included_tokens'] ?? 0),
                (string) ($_POST['interval_unit'] ?? 'monthly'),
                (int) ($_POST['interval_count'] ?? 1),
                !empty($_POST['is_active']),
                !empty($_POST['is_default']),
                (int) ($user['id'] ?? 0),
                $reason
            );
            workspaceAdminRedirect($workspaceId, ['notice' => 'Subscription pricing updated successfully.']);
        }

        if ($action === 'update_token_pack_price') {
            if (!$isSuperAdmin) {
                throw new RuntimeException('Only Super Admin can update token pricing.');
            }
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required for token pricing changes.');
            }

            $operations->updateTokenPackPrice(
                (int) ($_POST['token_pack_price_id'] ?? 0),
                (float) ($_POST['amount'] ?? 0),
                (string) ($_POST['currency'] ?? ''),
                (int) ($_POST['token_quantity'] ?? 0),
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active']),
                (int) ($user['id'] ?? 0),
                $reason
            );
            workspaceAdminRedirect($workspaceId, ['notice' => 'Token pack pricing updated successfully.']);
        }

        if ($action === 'provider_event_replay') {
            $providerEventId = (int) ($_POST['provider_event_id'] ?? 0);
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($providerEventId <= 0) {
                throw new RuntimeException('Choose a provider event to replay.');
            }
            if ($reason === null) {
                throw new RuntimeException('A reason is required when replaying a provider event.');
            }

            $operations->replayProviderEvent($workspaceId, $providerEventId, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Provider event replay completed.']);
        }

        if ($action === 'runtime_replay') {
            $subsystem = trim((string) ($_POST['subsystem'] ?? ''));
            $rowId = (int) ($_POST['row_id'] ?? 0);
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($subsystem === '' || $rowId <= 0) {
                throw new RuntimeException('Choose a runtime failure row to replay.');
            }
            if ($reason === null) {
                throw new RuntimeException('A reason is required when replaying a runtime failure.');
            }

            $runtimeOperations->replayFailure($workspaceId, $subsystem, $rowId, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Runtime failure replay completed.']);
        }

        if ($action === 'impersonate') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required before impersonation starts.');
            }

            $impersonation->begin($user ?? [], $workspaceId, $targetUserId > 0 ? $targetUserId : null, $reason);
            header('Location: dashboard.php?impersonation=started');
            exit;
        }

        if ($action === 'generate_onboarding_nudge') {
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required when generating an onboarding nudge draft.');
            }
            $channel = trim((string) ($_POST['nudge_channel'] ?? 'email'));
            $draft = $onboardingNudges->createDraft($workspaceId, (int) ($user['id'] ?? 0), $channel, $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Onboarding nudge draft created.', 'nudge_id' => (int) ($draft['id'] ?? 0)]);
        }

        if ($action === 'send_onboarding_nudge') {
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required when sending an onboarding nudge.');
            }
            $sent = $onboardingNudges->sendNudge((int) ($_POST['nudge_id'] ?? 0), (int) ($user['id'] ?? 0), [
                'channel' => trim((string) ($_POST['nudge_channel'] ?? 'email')),
                'subject' => (string) ($_POST['nudge_subject'] ?? ''),
                'body' => (string) ($_POST['nudge_body'] ?? ''),
            ], $reason);
            $status = (string) ($sent['status'] ?? '');
            workspaceAdminRedirect($workspaceId, ['notice' => $status === 'sent' ? 'Onboarding nudge sent.' : 'Onboarding nudge saved as draft/skipped for manual delivery.']);
        }

        if ($action === 'resend_owner_setup_link') {
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required when resending the owner setup link.');
            }
            $onboardingNudges->resendOwnerSetupLink($workspaceId, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Owner setup link resent.']);
        }

        if ($action === 'reset_onboarding_state') {
            if (!$isSuperAdmin) {
                throw new RuntimeException('Only Super Admin can reset onboarding state.');
            }
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required when resetting onboarding state.');
            }
            $onboardingNudges->resetOnboarding($workspaceId, (int) ($user['id'] ?? 0), (int) ($_POST['current_step'] ?? 1), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Onboarding state reset.']);
        }

        if ($action === 'mark_onboarding_complete') {
            if (!$isSuperAdmin) {
                throw new RuntimeException('Only Super Admin can mark onboarding complete.');
            }
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required when marking onboarding complete.');
            }
            $onboardingNudges->markOnboardingComplete($workspaceId, (int) ($user['id'] ?? 0), $reason);
            workspaceAdminRedirect($workspaceId, ['notice' => 'Onboarding marked complete.']);
        }

        if ($action === 'governance_override') {
            $governanceAction = trim((string) ($_POST['governance_action'] ?? ''));
            $reason = workspaceAdminReason($_POST['reason'] ?? null);
            if ($reason === null) {
                throw new RuntimeException('A reason is required for governance override actions.');
            }

            if ($governanceAction === 'revoke_invite') {
                $workspaceGovernance->platformRevokeInvite(
                    $workspaceId,
                    (int) ($_POST['invite_id'] ?? 0),
                    (int) ($user['id'] ?? 0),
                    $reason
                );
                workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace invite revoked.']);
            }

            if ($governanceAction === 'update_member_role') {
                $workspaceGovernance->platformUpdateMemberRole(
                    $workspaceId,
                    (int) ($_POST['membership_id'] ?? 0),
                    (string) ($_POST['role_slug'] ?? 'viewer'),
                    (int) ($user['id'] ?? 0),
                    $reason
                );
                workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace member role updated.']);
            }

            if ($governanceAction === 'update_member_status') {
                $workspaceGovernance->platformSetMemberStatus(
                    $workspaceId,
                    (int) ($_POST['membership_id'] ?? 0),
                    (string) ($_POST['membership_status'] ?? 'active'),
                    (int) ($user['id'] ?? 0),
                    $reason
                );
                workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace membership status updated.']);
            }

            if ($governanceAction === 'set_primary_slug') {
                if (!$isSuperAdmin) {
                    throw new \RuntimeException('Only Super Admin can update workspace slugs.');
                }
                $workspaceGovernance->platformSetPrimaryWorkspaceSlug(
                    $workspaceId,
                    (string) ($_POST['workspace_slug'] ?? ''),
                    (int) ($user['id'] ?? 0),
                    $reason
                );
                workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace primary slug updated.']);
            }

            if ($governanceAction === 'transfer_ownership') {
                $workspaceGovernance->platformTransferOwnership(
                    $workspaceId,
                    (int) ($_POST['target_membership_id'] ?? 0),
                    (int) ($user['id'] ?? 0),
                    $reason,
                    !empty($_POST['source_membership_id']) ? (int) $_POST['source_membership_id'] : null,
                    !empty($_POST['retain_existing_owners']),
                    (string) ($_POST['demoted_source_role'] ?? 'admin')
                );
                workspaceAdminRedirect($workspaceId, ['notice' => 'Workspace ownership transferred.']);
            }

            throw new RuntimeException('Unsupported governance override action.');
        }

        throw new RuntimeException('Unsupported operator action.');
    } catch (\Throwable $e) {
        workspaceAdminRedirect($workspaceId, ['error' => $e->getMessage()]);
    }
}

try {
    $data = $operations->getWorkspaceOperationsData($workspaceId);
    $runtimeData = $runtimeOperations->getWorkspaceHealthData($workspaceId);
    $launchReadiness = (array) ($data['saas_readiness'] ?? []);
    $governanceReadiness = (array) ($launchReadiness['workspace_governance'] ?? ['ready' => true, 'issues' => []]);
    $governanceData = !empty($governanceReadiness['ready'])
        ? $workspaceGovernance->getWorkspaceGovernanceDataForPlatformAdmin(
            $workspaceId,
            (string) ($_GET['history_filter'] ?? '')
        )
        : [
            'members' => [],
            'pending_invites' => [],
            'slugs' => [],
            'history' => [],
        ];
} catch (\Throwable $e) {
    header('Location: workspaces.php?error=' . rawurlencode($e->getMessage()));
    exit;
}

$workspace = (array) ($data['workspace'] ?? []);
$billingPortal = (array) ($data['billing_portal'] ?? []);
$snapshot = (array) ($billingPortal['snapshot'] ?? []);
$subscription = (array) ($data['active_subscription'] ?? []);
$wallet = (array) ($data['wallet_summary'] ?? []);
$ledger = (array) ($billingPortal['ledger'] ?? []);
$transactions = (array) ($billingPortal['transactions'] ?? []);
$checkoutSessions = (array) ($billingPortal['checkout_sessions'] ?? []);
$providerEvents = (array) ($data['provider_events'] ?? []);
$subscriptionPrices = (array) ($data['subscription_prices'] ?? []);
$tokenPackPrices = (array) ($data['token_pack_prices'] ?? []);
$launchStatus = (array) ($data['launch_status'] ?? []);
$launchReadiness = (array) ($launchStatus['surfaces'] ?? ($data['saas_readiness'] ?? []));
$memberships = (array) ($data['memberships'] ?? []);
$governanceMembers = (array) ($governanceData['members'] ?? []);
$governanceInvites = (array) ($governanceData['pending_invites'] ?? []);
$governanceSlugs = (array) ($governanceData['slugs'] ?? []);
$governanceHistory = (array) ($governanceData['history'] ?? []);
$governanceActiveMembers = array_values(array_filter($governanceMembers, static function (array $membership): bool {
    return (string) ($membership['membership_status'] ?? '') === 'active';
}));
$governanceActiveOwners = array_values(array_filter($governanceActiveMembers, static function (array $membership): bool {
    return !empty($membership['is_owner']) || (string) ($membership['role_slug'] ?? '') === 'owner';
}));
$governanceHistoryLabels = [
    'invite_created' => 'Invite created',
    'invite_resent' => 'Invite resent',
    'invite_revoked' => 'Invite revoked',
    'invite_accepted' => 'Invite accepted',
    'invite_delivery_sent' => 'Invite delivered',
    'invite_delivery_failed' => 'Invite delivery failed',
    'member_role_changed' => 'Member role changed',
    'member_restored' => 'Member restored',
    'member_removed' => 'Member removed',
    'member_suspended' => 'Member suspended',
    'ownership_transferred' => 'Ownership transferred',
    'primary_slug_changed' => 'Primary slug changed',
];
$operatorAudit = (array) ($data['operator_audit'] ?? []);
$runtimeSummary = (array) ($runtimeData['summary'] ?? []);
$runtimeSubsystems = (array) ($runtimeData['subsystems'] ?? []);
$lastProviderFailure = (array) ($data['last_provider_failure'] ?? []);
$onboardingRecovery = $onboardingNudges->summarizeWorkspace($workspaceId, (int) ($user['id'] ?? 0));
$onboardingRecentNudges = $onboardingNudges->listRecentNudges($workspaceId, 8);
$selectedNudgeId = (int) ($_GET['nudge_id'] ?? 0);
$selectedNudge = null;
foreach ($onboardingRecentNudges as $nudgeRow) {
    if ((int) ($nudgeRow['id'] ?? 0) === $selectedNudgeId) {
        $selectedNudge = $nudgeRow;
        break;
    }
}
if ($selectedNudge === null && $onboardingRecentNudges !== []) {
    $selectedNudge = $onboardingRecentNudges[0];
}
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$pageTitle = 'Workspace Operations - ' . brandProductName();
$workspaceStatusLabel = ucwords(str_replace('_', ' ', (string) ($workspace['status'] ?? 'active')));
$subscriptionStatusLabel = ucwords(str_replace('_', ' ', (string) ($snapshot['subscription_status'] ?? 'inactive')));
$workspaceStatusClass = in_array((string) ($workspace['status'] ?? 'active'), ['active', 'current'], true)
    ? 'admin-status-badge--success'
    : ((string) ($workspace['status'] ?? '') === 'suspended' ? 'admin-status-badge--warning' : 'admin-status-badge--neutral');
$subscriptionStatusClass = in_array((string) ($snapshot['subscription_status'] ?? ''), ['active', 'paid'], true)
    ? 'admin-status-badge--success'
    : ((string) ($snapshot['subscription_status'] ?? '') === 'past_due' ? 'admin-status-badge--warning' : 'admin-status-badge--neutral');
$launchStatusClass = !empty($launchStatus['ready']) ? 'admin-status-badge--success' : 'admin-status-badge--warning';
$onboardingStatusClass = !empty($onboardingRecovery['is_stuck']) ? 'admin-status-badge--warning' : 'admin-status-badge--success';
$launchGateLabel = !empty($launchStatus['ready']) ? 'Ready' : 'Blocked';
$launchReadySurfaceCount = (int) ($launchStatus['ready_surface_count'] ?? 0);
$launchSurfaceCount = (int) ($launchStatus['surface_count'] ?? count($launchReadiness));
$launchRecommendedAction = (string) ($launchStatus['recommended_action'] ?? 'Review launch-critical readiness.');
$launchCheckedAt = (string) (($launchStatus['checked_at'] ?? null) ?: 'n/a');
$launchReadinessRows = [];
foreach ($launchReadiness as $surfaceKey => $surfaceReadiness) {
    if (is_array($surfaceReadiness)) {
        $launchReadinessRows[$surfaceKey] = $surfaceReadiness;
    }
}
uasort($launchReadinessRows, static function (array $a, array $b): int {
    $aReady = !empty($a['ready']);
    $bReady = !empty($b['ready']);
    if ($aReady !== $bReady) {
        return $aReady ? 1 : -1;
    }

    return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
});
$workspaceOpsSections = [
    'ops-overview' => ['label' => 'Overview', 'icon' => 'fa-gauge-high'],
    'ops-onboarding' => ['label' => 'Onboarding', 'icon' => 'fa-route'],
    'ops-launch' => ['label' => 'Launch Readiness', 'icon' => 'fa-rocket'],
    'ops-runtime-health' => ['label' => 'Runtime Health', 'icon' => 'fa-heart-pulse'],
    'ops-billing' => ['label' => 'Billing', 'icon' => 'fa-wallet'],
    'ops-runtime-console' => ['label' => 'Runtime Console', 'icon' => 'fa-server'],
    'ops-activity' => ['label' => 'Activity', 'icon' => 'fa-clock-rotate-left'],
    'ops-actions' => ['label' => 'Actions', 'icon' => 'fa-screwdriver-wrench'],
    'ops-governance' => ['label' => 'Governance', 'icon' => 'fa-users-gear'],
];

ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium workspace-ops-page">
    <div class="container">
        <section id="ops-overview" class="workspace-ops-hero" aria-labelledby="workspace-ops-title">
            <div class="workspace-ops-hero-copy">
                <a href="workspaces.php" class="workspace-ops-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Workspaces</a>
                <h1 id="workspace-ops-title">Workspace Operations</h1>
                <div class="workspace-ops-hero-meta">
                    <strong class="workspace-ops-workspace-name"><?php echo htmlspecialchars((string) ($workspace['name'] ?? 'Workspace')); ?></strong>
                    <span class="admin-code-inline"><?php echo htmlspecialchars((string) ($workspace['slug'] ?? '')); ?></span>
                    <span class="admin-status-badge <?php echo htmlspecialchars($workspaceStatusClass); ?>"><?php echo htmlspecialchars($workspaceStatusLabel); ?></span>
                    <span class="admin-status-badge <?php echo htmlspecialchars($subscriptionStatusClass); ?>"><?php echo htmlspecialchars($subscriptionStatusLabel); ?></span>
                </div>
                <p>Workspace operations console for support recovery, billing control, runtime replay, and governance oversight.</p>
            </div>
            <div class="workspace-ops-hero-actions">
                <?php if ($isSuperAdmin): ?>
                    <a href="super_admin_billing.php?workspace_id=<?php echo (int) $workspaceId; ?>#ai-credit-operations" class="btn-premium-secondary btn-premium-sm"><i class="fas fa-credit-card" aria-hidden="true"></i> Billing Hub</a>
                <?php endif; ?>
                <a href="#ops-actions" class="btn-premium-secondary btn-premium-sm"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> Actions</a>
                <a href="#ops-governance" class="btn-premium-secondary btn-premium-sm"><i class="fas fa-users-gear" aria-hidden="true"></i> Governance</a>
            </div>
        </section>

        <?php if ($notice !== ''): ?>
            <div class="premium-banner premium-banner-success"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="premium-banner premium-banner-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="workspace-ops-metrics">
            <div class="workspace-ops-metric">
                <span>Workspace Status</span>
                <strong><?php echo htmlspecialchars($workspaceStatusLabel); ?></strong>
            </div>
            <div class="workspace-ops-metric">
                <span>Subscription</span>
                <strong><?php echo htmlspecialchars($subscriptionStatusLabel); ?></strong>
            </div>
            <div class="workspace-ops-metric">
                <span>AI Credit Balance</span>
                <strong><?php echo number_format((int) ($wallet['credit_balance'] ?? $wallet['token_balance'] ?? 0)); ?></strong>
            </div>
            <div class="workspace-ops-metric">
                <span>Available AI Credits</span>
                <strong><?php echo number_format((int) ($wallet['available_credits'] ?? $wallet['available_tokens'] ?? 0)); ?></strong>
            </div>
        </div>

        <div class="workspace-ops-layout">
            <nav class="workspace-ops-section-nav" aria-label="Workspace operations sections">
                <span>Sections</span>
                <?php foreach ($workspaceOpsSections as $sectionId => $section): ?>
                    <a href="#<?php echo htmlspecialchars($sectionId); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $section['icon']); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars((string) $section['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="workspace-ops-main">
        <section id="ops-onboarding" class="workspace-ops-card">
            <?php
            $recoveryNextAction = (array) ($onboardingRecovery['next_action'] ?? []);
            $recoveryOwner = (array) ($onboardingRecovery['owner'] ?? []);
            $recoveryLastNudge = (array) ($onboardingRecovery['last_nudge'] ?? []);
            $recoveryChannelHealth = (array) ($onboardingRecovery['channel_health'] ?? []);
            $recoveryChannelItems = ['main_email' => 'Main email', 'assistant_email' => 'Assistant email', 'whatsapp' => 'WhatsApp'];
            $recoveryChannelHasIssues = false;
            foreach (array_keys($recoveryChannelItems) as $healthKey) {
                $health = (array) ($recoveryChannelHealth[$healthKey] ?? []);
                if (!empty($health['issues'])) {
                    $recoveryChannelHasIssues = true;
                    break;
                }
            }
            ?>
            <div class="workspace-ops-section-head">
                <div>
                    <h2>Onboarding Recovery</h2>
                    <p>Inspect stuck setup, draft context-aware nudges, resend safe setup links, or recover onboarding state.</p>
                </div>
                <div class="admin-status-badge <?php echo htmlspecialchars($onboardingStatusClass); ?>">
                    <?php echo !empty($onboardingRecovery['is_stuck']) ? 'Needs recovery' : 'No recovery flag'; ?>
                </div>
            </div>
            <div class="workspace-ops-stat-grid">
                <div class="workspace-ops-stat">
                    <span>Onboarding Status</span>
                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($onboardingRecovery['status'] ?? 'unknown')))); ?></strong>
                    <small>Step <?php echo (int) ($onboardingRecovery['current_step'] ?? 1); ?><?php echo !empty($onboardingRecovery['quick_start']) ? ' &middot; Quick Start' : ''; ?></small>
                </div>
                <div class="workspace-ops-stat">
                    <span>Operational Score</span>
                    <strong><?php echo (int) ($onboardingRecovery['operational_score'] ?? 0); ?>%</strong>
                    <small>Readiness <?php echo (int) ($onboardingRecovery['readiness_score'] ?? 0); ?>%</small>
                </div>
                <div class="workspace-ops-stat">
                    <span>Owner</span>
                    <strong><?php echo htmlspecialchars((string) (($recoveryOwner['email'] ?? null) ?: 'n/a')); ?></strong>
                    <small><?php echo htmlspecialchars(trim((string) (($recoveryOwner['first_name'] ?? '') . ' ' . ($recoveryOwner['last_name'] ?? ''))) ?: 'Owner'); ?></small>
                </div>
                <div class="workspace-ops-stat">
                    <span>Last Nudge</span>
                    <strong><?php echo htmlspecialchars((string) (($recoveryLastNudge['status'] ?? null) ?: 'none')); ?></strong>
                    <small><?php echo htmlspecialchars((string) (($recoveryLastNudge['created_at'] ?? null) ?: 'n/a')); ?></small>
                </div>
            </div>
            <div class="workspace-ops-content-grid">
                <div class="workspace-ops-stack">
                    <div class="workspace-ops-panel workspace-ops-panel--info">
                        <div class="workspace-ops-muted">Next Setup Action</div>
                        <strong><?php echo htmlspecialchars((string) (($recoveryNextAction['title'] ?? null) ?: 'Review onboarding')); ?></strong>
                        <p><?php echo htmlspecialchars((string) (($recoveryNextAction['description'] ?? null) ?: 'Open onboarding and review the current setup state.')); ?></p>
                        <a href="<?php echo htmlspecialchars((string) (($recoveryNextAction['url'] ?? null) ?: 'onboarding.php')); ?>" class="workspace-ops-text-link">Open setup action</a>
                    </div>
                    <div class="workspace-ops-panel <?php echo $recoveryChannelHasIssues ? 'workspace-ops-panel--warning' : 'workspace-ops-panel--neutral'; ?>">
                        <h3>Channel Health</h3>
                        <div class="workspace-ops-list">
                            <?php foreach ($recoveryChannelItems as $healthKey => $healthLabel): ?>
                                <?php
                                $health = (array) ($recoveryChannelHealth[$healthKey] ?? []);
                                $healthIssues = (array) ($health['issues'] ?? []);
                                $healthDisplayLabel = (string) (($health['label'] ?? null) ?: 'Unknown');
                                $healthLabelSearch = strtolower($healthDisplayLabel);
                                $healthIsNegative = str_contains($healthLabelSearch, 'not connected')
                                    || str_contains($healthLabelSearch, 'not configured')
                                    || str_contains($healthLabelSearch, 'missing')
                                    || str_contains($healthLabelSearch, 'failed')
                                    || str_contains($healthLabelSearch, 'unavailable')
                                    || str_contains($healthLabelSearch, 'error');
                                $healthIsPositive = !$healthIsNegative && (
                                    str_contains($healthLabelSearch, 'connected')
                                    || str_contains($healthLabelSearch, 'ready')
                                    || str_contains($healthLabelSearch, 'healthy')
                                    || str_contains($healthLabelSearch, 'configured')
                                    || str_contains($healthLabelSearch, 'enabled')
                                );
                                $healthPanelClass = $healthIssues !== [] || $healthIsNegative
                                    ? 'workspace-ops-panel--warning'
                                    : ($healthIsPositive ? 'workspace-ops-panel--success' : 'workspace-ops-panel--neutral');
                                ?>
                                <div class="workspace-ops-panel <?php echo htmlspecialchars($healthPanelClass); ?>">
                                    <strong><?php echo htmlspecialchars($healthLabel); ?>:</strong>
                                    <span class="workspace-ops-muted"><?php echo htmlspecialchars($healthDisplayLabel); ?></span>
                                    <?php if ($healthIssues !== []): ?>
                                        <div class="workspace-ops-readiness-issues"><?php echo htmlspecialchars(implode('; ', array_map('strval', $healthIssues))); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="workspace-ops-panel workspace-ops-panel--neutral">
                        <h3>Recent Nudges</h3>
                        <?php if ($onboardingRecentNudges === []): ?>
                            <div class="workspace-ops-muted">No onboarding nudges yet.</div>
                        <?php else: ?>
                            <div class="workspace-ops-list">
                                <?php foreach ($onboardingRecentNudges as $nudgeRow): ?>
                                    <div class="workspace-ops-list-row workspace-ops-list-row--split">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($nudgeRow['subject'] ?? 'Onboarding nudge')); ?></strong>
                                            <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($nudgeRow['channel'] ?? '')); ?> &middot; <?php echo htmlspecialchars((string) ($nudgeRow['status'] ?? '')); ?></div>
                                        </div>
                                        <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($nudgeRow['created_at'] ?? '')); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="workspace-ops-stack">
                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-panel workspace-ops-panel--action">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                        <input type="hidden" name="admin_action" value="generate_onboarding_nudge">
                        <h3>Generate Nudge Draft</h3>
                        <label>
                            <span>Channel</span>
                            <select name="nudge_channel">
                                <option value="email">Email</option>
                                <option value="in_app">In-app / push</option>
                                <option value="whatsapp">WhatsApp draft</option>
                            </select>
                        </label>
                        <label>
                            <span>Reason</span>
                            <input type="text" name="reason" placeholder="Required operator reason">
                        </label>
                        <button type="submit" class="btn-premium-secondary">Generate Draft</button>
                    </form>

                    <?php if ($selectedNudge !== null): ?>
                        <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-panel workspace-ops-panel--action">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                            <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                            <input type="hidden" name="admin_action" value="send_onboarding_nudge">
                            <input type="hidden" name="nudge_id" value="<?php echo (int) ($selectedNudge['id'] ?? 0); ?>">
                            <h3>Review and Send Latest Draft</h3>
                            <label>
                                <span>Channel</span>
                                <select name="nudge_channel">
                                    <?php foreach (['email' => 'Email', 'in_app' => 'In-app / push', 'whatsapp' => 'WhatsApp draft'] as $channelValue => $channelLabel): ?>
                                        <option value="<?php echo htmlspecialchars($channelValue); ?>" <?php echo (string) ($selectedNudge['channel'] ?? '') === $channelValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($channelLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Subject</span>
                                <input type="text" name="nudge_subject" value="<?php echo htmlspecialchars((string) ($selectedNudge['subject'] ?? '')); ?>">
                            </label>
                            <label>
                                <span>Message</span>
                                <textarea name="nudge_body" rows="7"><?php echo htmlspecialchars((string) ($selectedNudge['body'] ?? '')); ?></textarea>
                            </label>
                            <label>
                                <span>Reason</span>
                                <input type="text" name="reason" placeholder="Required send reason">
                            </label>
                            <button type="submit" class="btn-premium-primary">Send / Record Nudge</button>
                        </form>
                    <?php endif; ?>

                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-panel workspace-ops-panel--action">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                        <input type="hidden" name="admin_action" value="resend_owner_setup_link">
                        <h3>Owner Setup Link</h3>
                        <p>Sends the owner a setup URL and a password reset link if the account exists. It does not log them in.</p>
                        <input type="text" name="reason" placeholder="Required resend reason">
                        <button type="submit" class="btn-premium-secondary">Resend Setup Link</button>
                    </form>

                    <?php if ($isSuperAdmin): ?>
                        <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-panel workspace-ops-panel--danger">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                            <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                            <h3>Super Admin State Recovery</h3>
                            <label>
                                <span>Reset to step</span>
                                <select name="current_step">
                                    <?php foreach (range(1, 8) as $stepNumber): ?>
                                        <option value="<?php echo $stepNumber; ?>" <?php echo (int) ($onboardingRecovery['current_step'] ?? 1) === $stepNumber ? 'selected' : ''; ?>>Step <?php echo $stepNumber; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input type="text" name="reason" placeholder="Required recovery reason">
                            <div class="workspace-ops-form-actions">
                                <button type="submit" name="admin_action" value="reset_onboarding_state" class="btn-premium-secondary">Reset State</button>
                                <button type="submit" name="admin_action" value="mark_onboarding_complete" class="btn-premium-secondary">Mark Complete</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section id="ops-launch" class="workspace-ops-card">
            <div class="workspace-ops-section-head">
                <div>
                    <h2>Launch Readiness</h2>
                    <p>Operator-facing compatibility checks for active SaaS billing, invite acceptance, governance, mobile workspace payloads, and mail-provider launch readiness.</p>
                </div>
            </div>
            <div class="workspace-ops-launch-summary">
                <div>
                    <span>Launch Gate</span>
                    <strong class="admin-status-badge <?php echo htmlspecialchars($launchStatusClass); ?>"><?php echo htmlspecialchars($launchGateLabel); ?></strong>
                </div>
                <div>
                    <span>Ready Surfaces</span>
                    <strong><?php echo $launchReadySurfaceCount; ?>/<?php echo $launchSurfaceCount; ?></strong>
                </div>
                <div class="workspace-ops-launch-summary__action">
                    <span>Recommended Action</span>
                    <strong><?php echo htmlspecialchars($launchRecommendedAction); ?></strong>
                </div>
                <div>
                    <span>Checked At</span>
                    <strong><?php echo htmlspecialchars($launchCheckedAt); ?></strong>
                </div>
            </div>
            <details class="workspace-ops-disclosure">
                <summary>
                    <span>Surface readiness details</span>
                    <span><?php echo count($launchReadinessRows); ?> surfaces</span>
                </summary>
                <div class="workspace-ops-readiness-list">
                    <?php foreach ($launchReadinessRows as $surfaceKey => $surfaceReadiness): ?>
                        <?php
                        $surfaceIssues = is_array($surfaceReadiness['issues'] ?? null) ? $surfaceReadiness['issues'] : [];
                        $surfaceReady = !empty($surfaceReadiness['ready']);
                        $surfaceStatusClass = $surfaceReady ? 'admin-status-badge--success' : 'admin-status-badge--warning';
                        ?>
                        <div class="workspace-ops-readiness-row<?php echo $surfaceReady ? '' : ' workspace-ops-readiness-row--blocked'; ?>">
                            <div>
                                <strong><?php echo htmlspecialchars((string) ($surfaceReadiness['label'] ?? $surfaceKey)); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($surfaceReadiness['operator_message'] ?? 'Apply the latest migrations for this surface.')); ?></p>
                                <?php if ($surfaceIssues !== []): ?>
                                    <p class="workspace-ops-readiness-issues">
                                        <?php echo htmlspecialchars(implode('; ', array_map(static function (array $issue): string {
                                            return (string) ($issue['message'] ?? 'Unknown readiness issue.');
                                        }, $surfaceIssues))); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="workspace-ops-readiness-row__meta">
                                <span class="admin-status-badge <?php echo htmlspecialchars($surfaceStatusClass); ?>"><?php echo $surfaceReady ? 'Ready' : 'Migration drift detected'; ?></span>
                                <span>Verified <?php echo htmlspecialchars((string) (($surfaceReadiness['checked_at'] ?? null) ?: 'n/a')); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>

        <section id="ops-runtime-health" class="workspace-ops-card">
            <div class="workspace-ops-section-head">
                <div>
                    <h2>Runtime Health</h2>
                    <p>Platform-only view of backlog, failures, and replayable async rows for this workspace.</p>
                </div>
            </div>
            <div class="workspace-ops-stat-grid">
                <div class="workspace-ops-stat">
                    <span>Open Failures</span>
                    <strong><?php echo number_format((int) ($runtimeSummary['open_failure_count'] ?? 0)); ?></strong>
                </div>
                <div class="workspace-ops-stat">
                    <span>Replayable Rows</span>
                    <strong><?php echo number_format((int) ($runtimeSummary['replayable_failure_count'] ?? 0)); ?></strong>
                </div>
                <div class="workspace-ops-stat">
                    <span>Oldest Pending</span>
                    <strong><?php echo htmlspecialchars(workspaceAdminFormatAge($runtimeSummary['oldest_pending_at'] ?? null)); ?></strong>
                </div>
                <div class="workspace-ops-stat">
                    <span>Last Operator Action</span>
                    <strong><?php echo htmlspecialchars((string) (($runtimeSummary['last_operator_action']['action_type'] ?? null) ?: 'n/a')); ?></strong>
                </div>
                <div class="workspace-ops-stat">
                    <span>Last Action Time</span>
                    <strong><?php echo htmlspecialchars((string) (($runtimeSummary['last_operator_action']['created_at'] ?? null) ?: 'n/a')); ?></strong>
                </div>
            </div>
        </section>

                <section id="ops-billing" class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Billing Snapshot</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-stat-grid">
                        <div class="workspace-ops-stat">
                            <span>Plan</span>
                            <strong><?php echo htmlspecialchars((string) ($subscription['plan_name'] ?? 'No active plan')); ?></strong>
                        </div>
                        <div class="workspace-ops-stat">
                            <span>Billing blocked</span>
                            <strong><?php echo !empty($snapshot['billing_blocked']) ? 'Yes' : 'No'; ?></strong>
                        </div>
                        <div class="workspace-ops-stat">
                            <span>AI blocked reason</span>
                            <strong><?php echo htmlspecialchars((string) (($snapshot['ai_blocked_reason'] ?? null) ?: 'none')); ?></strong>
                        </div>
                    </div>
                    <?php if ($lastProviderFailure !== []): ?>
                        <div class="premium-banner premium-banner-warning">
                            <strong>Last provider failure:</strong>
                            <?php echo htmlspecialchars((string) ($lastProviderFailure['event_name'] ?? '')); ?>
                            <?php if (!empty($lastProviderFailure['event_reference'])): ?>
                                for <code><?php echo htmlspecialchars((string) $lastProviderFailure['event_reference']); ?></code>
                            <?php endif; ?>
                            <?php if (!empty($lastProviderFailure['processing_message'])): ?>
                                <div><?php echo htmlspecialchars((string) $lastProviderFailure['processing_message']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($isSuperAdmin): ?>
                    <section class="workspace-ops-card">
                        <div class="workspace-ops-section-head">
                            <div>
                                <h2>Super Admin Billing Control</h2>
                            </div>
                        </div>
                        <div class="workspace-ops-stack">
                            <div class="workspace-ops-subpanel">
                                <h3>Global Subscription Pricing</h3>
                                <?php foreach ($subscriptionPrices as $price): ?>
                                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-list-row">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                        <input type="hidden" name="admin_action" value="update_subscription_price">
                                        <input type="hidden" name="billing_plan_price_id" value="<?php echo (int) ($price['id'] ?? 0); ?>">
                                        <strong>
                                            <?php echo htmlspecialchars((string) ($price['plan_name'] ?? 'Subscription')); ?>
                                            <span class="workspace-ops-muted">(<?php echo htmlspecialchars((string) ($price['price_code'] ?? '')); ?>)</span>
                                        </strong>
                                        <div class="workspace-ops-form-grid workspace-ops-form-grid--columns">
                                            <label>
                                                <span>Amount</span>
                                                <input type="number" min="0.01" step="0.01" name="amount" value="<?php echo htmlspecialchars((string) ($price['amount'] ?? '0')); ?>">
                                            </label>
                                            <label>
                                                <span>Currency</span>
                                                <input type="text" name="currency" value="<?php echo htmlspecialchars((string) ($price['currency'] ?? 'KES')); ?>">
                                            </label>
                                            <label>
                                                <span>Included tokens</span>
                                                <input type="number" min="0" step="1" name="included_tokens" value="<?php echo (int) ($price['included_tokens'] ?? 0); ?>">
                                            </label>
                                            <label>
                                                <span>Interval</span>
                                                <select name="interval_unit">
                                                    <?php foreach (['weekly', 'monthly', 'quarterly', 'yearly'] as $interval): ?>
                                                        <option value="<?php echo htmlspecialchars($interval); ?>" <?php echo (string) ($price['interval_unit'] ?? 'monthly') === $interval ? 'selected' : ''; ?>><?php echo htmlspecialchars($interval); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span>Interval count</span>
                                                <input type="number" min="1" step="1" name="interval_count" value="<?php echo max(1, (int) ($price['interval_count'] ?? 1)); ?>">
                                            </label>
                                        </div>
                                        <div class="workspace-ops-form-actions">
                                            <label class="workspace-ops-check"><input type="checkbox" name="is_active" value="1" <?php echo !empty($price['is_active']) ? 'checked' : ''; ?>> Active</label>
                                            <label class="workspace-ops-check"><input type="checkbox" name="is_default" value="1" <?php echo !empty($price['is_default']) ? 'checked' : ''; ?>> Default</label>
                                        </div>
                                        <label>
                                            <span>Reason</span>
                                            <input type="text" name="reason" placeholder="Required pricing reason">
                                        </label>
                                        <div class="workspace-ops-form-actions">
                                            <button type="submit" class="btn-premium-secondary">Save Subscription Price</button>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                            </div>

                            <div class="workspace-ops-subpanel">
                                <h3>Global Token Pack Pricing</h3>
                                <?php foreach ($tokenPackPrices as $pack): ?>
                                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-list-row">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                        <input type="hidden" name="admin_action" value="update_token_pack_price">
                                        <input type="hidden" name="token_pack_price_id" value="<?php echo (int) ($pack['id'] ?? 0); ?>">
                                        <strong>
                                            <?php echo htmlspecialchars((string) ($pack['plan_name'] ?? 'Token Pack')); ?>
                                            <span class="workspace-ops-muted">(<?php echo htmlspecialchars((string) ($pack['price_code'] ?? '')); ?>)</span>
                                        </strong>
                                        <div class="workspace-ops-form-grid workspace-ops-form-grid--columns">
                                            <label>
                                                <span>Amount</span>
                                                <input type="number" min="0.01" step="0.01" name="amount" value="<?php echo htmlspecialchars((string) ($pack['amount'] ?? '0')); ?>">
                                            </label>
                                            <label>
                                                <span>Currency</span>
                                                <input type="text" name="currency" value="<?php echo htmlspecialchars((string) ($pack['currency'] ?? 'KES')); ?>">
                                            </label>
                                            <label>
                                                <span>Token quantity</span>
                                                <input type="number" min="1" step="1" name="token_quantity" value="<?php echo (int) ($pack['token_quantity'] ?? 0); ?>">
                                            </label>
                                            <label>
                                                <span>Sort order</span>
                                                <input type="number" step="1" name="sort_order" value="<?php echo (int) ($pack['sort_order'] ?? 0); ?>">
                                            </label>
                                        </div>
                                        <label class="workspace-ops-check"><input type="checkbox" name="is_active" value="1" <?php echo !empty($pack['is_active']) ? 'checked' : ''; ?>> Active</label>
                                        <label>
                                            <span>Reason</span>
                                            <input type="text" name="reason" placeholder="Required token pricing reason">
                                        </label>
                                        <div class="workspace-ops-form-actions">
                                            <button type="submit" class="btn-premium-secondary">Save Token Pack</button>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <section id="ops-runtime-console" class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Tenant Runtime Console</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-stack">
                        <?php if ($runtimeSubsystems === []): ?>
                            <div class="workspace-ops-muted">No runtime telemetry is available for this workspace yet.</div>
                        <?php else: ?>
                            <?php foreach ($runtimeSubsystems as $subsystemKey => $subsystem): ?>
                                <div class="workspace-ops-subpanel">
                                    <div class="workspace-ops-list-row workspace-ops-list-row--split">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($subsystem['label'] ?? $subsystemKey)); ?></strong>
                                            <div class="workspace-ops-list-row__meta">
                                                Pending <?php echo number_format((int) ($subsystem['pending_count'] ?? 0)); ?>
                                                &middot; Processing <?php echo number_format((int) ($subsystem['processing_count'] ?? 0)); ?>
                                                &middot; Failed <?php echo number_format((int) ($subsystem['failed_count'] ?? 0)); ?>
                                            </div>
                                        </div>
                                        <div class="workspace-ops-list-row__meta">
                                            <div>Last success: <?php echo htmlspecialchars((string) (($subsystem['last_success_at'] ?? null) ?: 'n/a')); ?></div>
                                            <div>Last failure: <?php echo htmlspecialchars((string) (($subsystem['last_failure_at'] ?? null) ?: 'n/a')); ?></div>
                                            <div>Oldest pending: <?php echo htmlspecialchars(workspaceAdminFormatAge($subsystem['oldest_pending_at'] ?? null)); ?></div>
                                        </div>
                                    </div>

                                    <?php $subsystemFailures = (array) ($subsystem['recent_failures'] ?? []); ?>
                                    <?php if ($subsystemFailures === []): ?>
                                        <div class="workspace-ops-muted">No recent failures recorded for this subsystem.</div>
                                    <?php else: ?>
                                        <div class="workspace-ops-list">
                                            <?php foreach ($subsystemFailures as $failure): ?>
                                                <div class="workspace-ops-list-row">
                                                    <div class="workspace-ops-list-row--split">
                                                        <div>
                                                            <strong>
                                                                <?php echo htmlspecialchars((string) (($failure['subject_primary'] ?? null) ?: ('Row #' . (int) ($failure['row_id'] ?? 0)))); ?>
                                                            </strong>
                                                            <?php if (!empty($failure['subject_secondary'])): ?>
                                                                <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) $failure['subject_secondary']); ?></div>
                                                            <?php endif; ?>
                                                            <div class="workspace-ops-readiness-issues"><?php echo htmlspecialchars((string) ($failure['failure_reason'] ?? 'Failed')); ?></div>
                                                        </div>
                                                        <div class="workspace-ops-list-row__meta">
                                                            <div><?php echo htmlspecialchars((string) (($failure['status'] ?? null) ?: 'failed')); ?></div>
                                                            <div><?php echo htmlspecialchars((string) (($failure['occurred_at'] ?? null) ?: '')); ?></div>
                                                        </div>
                                                    </div>
                                                    <?php if (in_array((string) ($failure['subsystem'] ?? ''), ['email_queue', 'sms_queue', 'whatsapp_queue', 'campaign_queue', 'workflow_retry', 'scheduled_report_run'], true)): ?>
                                                        <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                                            <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                                            <input type="hidden" name="admin_action" value="runtime_replay">
                                                            <input type="hidden" name="subsystem" value="<?php echo htmlspecialchars((string) ($failure['subsystem'] ?? '')); ?>">
                                                            <input type="hidden" name="row_id" value="<?php echo (int) ($failure['row_id'] ?? 0); ?>">
                                                            <label>
                                                                <span>Replay reason</span>
                                                                <input type="text" name="reason" placeholder="Explain why this runtime row should be replayed">
                                                            </label>
                                                            <div class="workspace-ops-form-actions">
                                                                <button type="submit" class="btn-premium-secondary">Replay Runtime Row</button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section id="ops-activity" class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Recent Billing Activity</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-stat-grid">
                        <div>
                            <h3>Transactions</h3>
                            <div class="workspace-ops-list">
                                <?php if ($transactions === []): ?>
                                    <div class="workspace-ops-muted">No billing transactions yet.</div>
                                <?php else: ?>
                                    <?php foreach (array_slice($transactions, 0, 6) as $transaction): ?>
                                        <div class="workspace-ops-list-row">
                                            <strong><?php echo htmlspecialchars((string) ($transaction['transaction_type'] ?? 'transaction')); ?></strong>
                                            <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($transaction['transaction_status'] ?? 'pending')); ?> &middot; <?php echo htmlspecialchars((string) ($transaction['provider_reference'] ?? '')); ?></div>
                                            <?php if (!empty($transaction['billing_invoice']['url'])): ?>
                                                <a href="<?php echo htmlspecialchars((string) $transaction['billing_invoice']['url']); ?>" target="_blank" rel="noopener" class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($transaction['billing_invoice']['document_number'] ?? 'Receipt')); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <h3>Checkout Sessions</h3>
                            <div class="workspace-ops-list">
                                <?php if ($checkoutSessions === []): ?>
                                    <div class="workspace-ops-muted">No checkout sessions yet.</div>
                                <?php else: ?>
                                    <?php foreach (array_slice($checkoutSessions, 0, 6) as $checkoutSession): ?>
                                        <div class="workspace-ops-list-row">
                                            <strong><?php echo htmlspecialchars((string) ($checkoutSession['checkout_type'] ?? 'checkout')); ?></strong>
                                            <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($checkoutSession['status'] ?? 'pending')); ?> &middot; <?php echo htmlspecialchars((string) ($checkoutSession['provider_reference'] ?? '')); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Wallet Ledger</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-list">
                        <?php if ($ledger === []): ?>
                            <div class="workspace-ops-muted">No wallet ledger activity yet.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($ledger, 0, 8) as $entry): ?>
                                <div class="workspace-ops-list-row workspace-ops-list-row--split">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string) ($entry['entry_type'] ?? 'entry')); ?></strong>
                                        <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($entry['description'] ?? '')); ?></div>
                                    </div>
                                    <div class="workspace-ops-list-row__meta">
                                        <strong><?php echo number_format((int) ($entry['token_delta'] ?? 0)); ?></strong>
                                        <div>Balance <?php echo number_format((int) ($entry['balance_after'] ?? 0)); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Provider Events</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-list workspace-ops-list--loose">
                        <?php if ($providerEvents === []): ?>
                            <div class="workspace-ops-muted">No provider events recorded yet.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($providerEvents, 0, 10) as $event): ?>
                                <div class="workspace-ops-list-row">
                                    <div class="workspace-ops-list-row--split">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($event['event_name'] ?? 'event')); ?></strong>
                                            <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) (($event['event_reference'] ?? null) ?: 'no reference')); ?></div>
                                            <?php if (!empty($event['processing_message'])): ?>
                                                <div class="workspace-ops-readiness-issues"><?php echo htmlspecialchars((string) $event['processing_message']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="workspace-ops-list-row__meta">
                                            <strong><?php echo htmlspecialchars((string) ($event['processing_status'] ?? 'pending')); ?></strong>
                                            <div><?php echo htmlspecialchars((string) ($event['created_at'] ?? '')); ?></div>
                                        </div>
                                    </div>
                                    <?php if (in_array((string) ($event['processing_status'] ?? ''), ['failed', 'pending', 'ignored'], true)): ?>
                                        <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                            <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                            <input type="hidden" name="admin_action" value="provider_event_replay">
                                            <input type="hidden" name="provider_event_id" value="<?php echo (int) ($event['id'] ?? 0); ?>">
                                            <label>
                                                <span>Replay reason</span>
                                                <input type="text" name="reason" placeholder="Explain why this event is being replayed">
                                            </label>
                                            <div class="workspace-ops-form-actions">
                                                <button type="submit" class="btn-premium-secondary">Replay Provider Event</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Operator Audit</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-list">
                        <?php if ($operatorAudit === []): ?>
                            <div class="workspace-ops-muted">No operator actions recorded yet.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($operatorAudit, 0, 12) as $auditRow): ?>
                                <div class="workspace-ops-list-row">
                                    <strong><?php echo htmlspecialchars((string) ($auditRow['action_type'] ?? 'action')); ?></strong>
                                    <div class="workspace-ops-list-row__meta">
                                        <?php echo htmlspecialchars((string) (($auditRow['actor_email'] ?? null) ?: 'system')); ?>
                                        &middot; <?php echo htmlspecialchars((string) ($auditRow['created_at'] ?? '')); ?>
                                    </div>
                                    <?php if (!empty($auditRow['reason'])): ?>
                                        <div><?php echo htmlspecialchars((string) $auditRow['reason']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <aside class="workspace-ops-rail">
                <section id="ops-actions" class="workspace-ops-card workspace-ops-card--action">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Workspace Lifecycle</h2>
                            <p>Manual status changes flow through the same workspace snapshot used by SaaS access checks.</p>
                        </div>
                    </div>
                    <div class="workspace-ops-stack">
                        <?php
                        $status = (string) ($workspace['status'] ?? 'active');
                        $actions = [];
                        if ($status === 'suspended') {
                            $actions = ['reinstate' => 'Reinstate Workspace', 'archive' => 'Archive Workspace'];
                        } elseif ($status === 'archived') {
                            $actions = ['restore' => 'Restore Workspace'];
                        } else {
                            $actions = ['suspend' => 'Suspend Workspace', 'archive' => 'Archive Workspace'];
                        }
                        ?>
                        <?php foreach ($actions as $actionValue => $label): ?>
                            <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                <input type="hidden" name="admin_action" value="lifecycle">
                                <input type="hidden" name="lifecycle_action" value="<?php echo htmlspecialchars($actionValue); ?>">
                                <label>
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                    <input type="text" name="reason" placeholder="Required operator reason">
                                </label>
                                <div class="workspace-ops-form-actions">
                                    <button type="submit" class="btn-premium-secondary"><?php echo htmlspecialchars($label); ?></button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="workspace-ops-card workspace-ops-card--action">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Manual AI Credit Adjustment</h2>
                        </div>
                    </div>
                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                        <input type="hidden" name="admin_action" value="wallet_adjustment">
                        <label>
                            <span>AI Credit delta</span>
                            <input type="number" name="token_delta" step="1" placeholder="Use positive to credit, negative to debit">
                        </label>
                        <label>
                            <span>Reason</span>
                            <input type="text" name="reason" placeholder="Required operator note">
                        </label>
                        <div class="workspace-ops-form-actions">
                            <button type="submit" class="btn-premium-primary">Apply Adjustment</button>
                        </div>
                    </form>
                </section>

                <section class="workspace-ops-card workspace-ops-card--action">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Impersonate Workspace</h2>
                            <p>Choose a workspace member and enter a support reason. You will switch into that member and workspace through the normal session model.</p>
                        </div>
                    </div>
                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                        <input type="hidden" name="admin_action" value="impersonate">
                        <label>
                            <span>Member</span>
                            <select name="target_user_id">
                                <?php foreach ($memberships as $membership): ?>
                                    <option value="<?php echo (int) ($membership['user_id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($membership['role_slug'] ?? 'viewer')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Reason</span>
                            <input type="text" name="reason" placeholder="Required support note">
                        </label>
                        <div class="workspace-ops-form-actions">
                            <button type="submit" class="btn-premium-secondary">Start Impersonation</button>
                        </div>
                    </form>
                </section>

                <section id="ops-governance" class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Workspace Governance</h2>
                            <p>Platform-only support controls for members, ownership, invites, slug history, and tenant-facing governance audit.</p>
                        </div>
                    </div>
                    <div class="workspace-ops-list">
                        <?php foreach ($governanceMembers as $membership): ?>
                            <div class="workspace-ops-list-row">
                                <div class="workspace-ops-list-row--split">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?></strong>
                                        <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($membership['role_slug'] ?? 'viewer')); ?> &middot; <?php echo htmlspecialchars((string) ($membership['membership_status'] ?? 'active')); ?></div>
                                    </div>
                                    <div class="workspace-ops-list-row__meta">
                                        <div><?php echo !empty($membership['is_owner']) ? 'owner' : 'member'; ?></div>
                                        <div><?php echo htmlspecialchars((string) ($membership['joined_at'] ?? '')); ?></div>
                                    </div>
                                </div>
                                <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                    <input type="hidden" name="admin_action" value="governance_override">
                                    <input type="hidden" name="membership_id" value="<?php echo (int) ($membership['id'] ?? 0); ?>">
                                    <div class="workspace-ops-form-grid workspace-ops-form-grid--columns">
                                        <label>
                                            <span>Role</span>
                                            <select name="role_slug">
                                                <?php foreach (['viewer', 'expert', 'accountant', 'admin', 'owner'] as $roleSlug): ?>
                                                    <option value="<?php echo htmlspecialchars($roleSlug); ?>" <?php echo ((string) ($membership['role_slug'] ?? 'viewer') === $roleSlug) ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleSlug); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Status</span>
                                            <select name="membership_status">
                                                <?php foreach (['active', 'suspended', 'left'] as $membershipStatus): ?>
                                                    <option value="<?php echo htmlspecialchars($membershipStatus); ?>" <?php echo ((string) ($membership['membership_status'] ?? 'active') === $membershipStatus) ? 'selected' : ''; ?>><?php echo htmlspecialchars($membershipStatus); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                    <label>
                                        <span>Reason</span>
                                        <input type="text" name="reason" placeholder="Required support reason">
                                    </label>
                                    <div class="workspace-ops-form-actions">
                                        <button type="submit" name="governance_action" value="update_member_role" class="btn-premium-secondary">Update Role</button>
                                        <button type="submit" name="governance_action" value="update_member_status" class="btn-premium-secondary">Update Status</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Ownership Transfer</h2>
                        </div>
                    </div>
                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                        <input type="hidden" name="admin_action" value="governance_override">
                        <input type="hidden" name="governance_action" value="transfer_ownership">
                        <label>
                            <span>New owner</span>
                            <select name="target_membership_id">
                                <option value="">Choose a member</option>
                                <?php foreach ($governanceActiveMembers as $membership): ?>
                                    <option value="<?php echo (int) ($membership['id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($membership['role_slug'] ?? 'viewer')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Current owner to demote</span>
                            <select name="source_membership_id">
                                <option value="">Keep existing owners</option>
                                <?php foreach ($governanceActiveOwners as $membership): ?>
                                    <option value="<?php echo (int) ($membership['id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Demoted owner role</span>
                            <select name="demoted_source_role">
                                <option value="admin">Admin</option>
                                <option value="accountant">Accountant</option>
                                <option value="expert">Expert</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </label>
                        <label class="workspace-ops-check">
                            <input type="checkbox" name="retain_existing_owners" value="1">
                            <span>Keep existing owners and only promote the selected member</span>
                        </label>
                        <label>
                            <span>Reason</span>
                            <input type="text" name="reason" placeholder="Required support reason">
                        </label>
                        <div class="workspace-ops-form-actions">
                            <button type="submit" class="btn-premium-secondary">Transfer Ownership</button>
                        </div>
                    </form>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Pending Invites</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-list">
                        <?php if ($governanceInvites === []): ?>
                            <div class="workspace-ops-muted">No pending invites for this workspace.</div>
                        <?php else: ?>
                            <?php foreach ($governanceInvites as $invite): ?>
                                <div class="workspace-ops-list-row">
                                    <div class="workspace-ops-list-row--split">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($invite['email'] ?? '')); ?></strong>
                                            <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($invite['role_slug'] ?? 'viewer')); ?> &middot; expires <?php echo htmlspecialchars((string) ($invite['expires_at'] ?? '')); ?></div>
                                        </div>
                                        <div class="workspace-ops-list-row__meta">
                                            <div><?php echo htmlspecialchars((string) ($invite['invite_status'] ?? 'pending')); ?></div>
                                            <div>Delivery <?php echo htmlspecialchars((string) ($invite['delivery_status'] ?? 'pending')); ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($invite['last_delivery_attempt_at']) || !empty($invite['delivery_error'])): ?>
                                        <div class="workspace-ops-list-row__meta">
                                            <?php if (!empty($invite['last_delivery_attempt_at'])): ?>
                                                Last delivery attempt <?php echo htmlspecialchars((string) $invite['last_delivery_attempt_at']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($invite['delivery_error'])): ?>
                                                <?php if (!empty($invite['last_delivery_attempt_at'])): ?>&middot; <?php endif; ?>
                                                Error: <?php echo htmlspecialchars((string) $invite['delivery_error']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                        <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                                        <input type="hidden" name="admin_action" value="governance_override">
                                        <input type="hidden" name="governance_action" value="revoke_invite">
                                        <input type="hidden" name="invite_id" value="<?php echo (int) ($invite['id'] ?? 0); ?>">
                                        <label>
                                            <span>Reason</span>
                                            <input type="text" name="reason" placeholder="Required support reason">
                                        </label>
                                        <div class="workspace-ops-form-actions">
                                            <button type="submit" class="btn-premium-secondary">Revoke Invite</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Workspace Slugs</h2>
                        </div>
                    </div>
                    <?php if ($isSuperAdmin): ?>
                        <form method="POST" class="workspace-ops-form workspace-ops-form-grid workspace-ops-subpanel">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                            <input type="hidden" name="workspace_id" value="<?php echo (int) $workspaceId; ?>">
                            <input type="hidden" name="admin_action" value="governance_override">
                            <input type="hidden" name="governance_action" value="set_primary_slug">
                            <label>
                                <span>Primary slug</span>
                                <input type="text" name="workspace_slug" value="<?php echo htmlspecialchars((string) ($workspace['slug'] ?? '')); ?>">
                            </label>
                            <label>
                                <span>Reason</span>
                                <input type="text" name="reason" placeholder="Required support reason">
                            </label>
                            <div class="workspace-ops-form-actions">
                                <button type="submit" class="btn-premium-secondary">Update Primary Slug</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <div class="workspace-ops-list">
                        <?php foreach ($governanceSlugs as $slugRow): ?>
                            <div class="workspace-ops-list-row">
                                <strong><?php echo htmlspecialchars((string) ($slugRow['slug'] ?? '')); ?></strong>
                                <div class="workspace-ops-list-row__meta"><?php echo !empty($slugRow['is_primary']) ? 'primary slug' : 'historical slug'; ?> &middot; created <?php echo htmlspecialchars((string) ($slugRow['created_at'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="workspace-ops-card">
                    <div class="workspace-ops-section-head">
                        <div>
                            <h2>Governance History</h2>
                        </div>
                    </div>
                    <div class="workspace-ops-list">
                        <?php if ($governanceHistory === []): ?>
                            <div class="workspace-ops-muted">No governance history for this workspace yet.</div>
                        <?php else: ?>
                            <?php foreach ($governanceHistory as $eventRow): ?>
                                <?php
                                $eventType = (string) ($eventRow['event_type'] ?? '');
                                $eventMetadata = (array) ($eventRow['metadata'] ?? []);
                                $eventTarget = (string) ($eventRow['target_label'] ?? (($eventRow['target_user_email'] ?? '') ?: ($eventRow['invite_email'] ?? '') ?: ($eventMetadata['new_slug'] ?? '')));
                                ?>
                                <div class="workspace-ops-list-row">
                                    <div class="workspace-ops-list-row--split">
                                        <strong><?php echo htmlspecialchars((string) ($governanceHistoryLabels[$eventType] ?? ucwords(str_replace('_', ' ', $eventType)))); ?></strong>
                                        <div class="workspace-ops-list-row__meta"><?php echo htmlspecialchars((string) ($eventRow['created_at'] ?? '')); ?></div>
                                    </div>
                                    <div class="workspace-ops-list-row__meta">
                                        <?php echo htmlspecialchars((string) ($eventRow['actor_display_name'] ?? (($eventRow['actor_email'] ?? '') !== '' ? $eventRow['actor_email'] : 'system'))); ?>
                                        &middot; <?php echo htmlspecialchars((string) ($eventRow['category'] ?? 'workspace')); ?>
                                        <?php if ($eventTarget !== ''): ?>
                                            &middot; <?php echo htmlspecialchars($eventTarget); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($eventMetadata !== []): ?>
                                        <div>
                                            <?php if (!empty($eventMetadata['reason'])): ?>
                                                <?php echo htmlspecialchars((string) $eventMetadata['reason']); ?>
                                            <?php elseif (!empty($eventMetadata['delivery_status']) || !empty($eventMetadata['error'])): ?>
                                                Delivery <?php echo htmlspecialchars((string) ($eventMetadata['delivery_status'] ?? 'pending')); ?><?php echo !empty($eventMetadata['error']) ? ': ' . htmlspecialchars((string) $eventMetadata['error']) : ''; ?>
                                            <?php elseif (!empty($eventMetadata['previous_slug']) || !empty($eventMetadata['new_slug'])): ?>
                                                <?php echo htmlspecialchars((string) ($eventMetadata['previous_slug'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_slug'] ?? '')); ?>
                                            <?php elseif (!empty($eventMetadata['previous_role']) || !empty($eventMetadata['new_role'])): ?>
                                                <?php echo htmlspecialchars((string) ($eventMetadata['previous_role'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_role'] ?? '')); ?>
                                            <?php elseif (!empty($eventMetadata['previous_status']) || !empty($eventMetadata['new_status'])): ?>
                                                <?php echo htmlspecialchars((string) ($eventMetadata['previous_status'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_status'] ?? '')); ?>
                                            <?php elseif (!empty($eventMetadata['email'])): ?>
                                                <?php echo htmlspecialchars((string) $eventMetadata['email']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(json_encode($eventMetadata, JSON_UNESCAPED_SLASHES)); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
