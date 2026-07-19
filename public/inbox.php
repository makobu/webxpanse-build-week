<?php
/**
 * Unified Inbox Page
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
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\UnifiedInbox;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$inbox = new UnifiedInbox();
$contactsModule = new Contacts();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canViewAllConversations = Authorization::can('conversations.view_all', $user);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$guidedDemoSessionService = new GuidedDemoSessionService();
$activeGuidedDemoSession = $guidedDemoSessionService->activeSession($workspaceId, $userId);
$activeGuidedDemoStepKey = (string) ($activeGuidedDemoSession['current_step_key'] ?? '');
$guidedDemoInboxPreview = $activeGuidedDemoSession !== null
    && (
        (isset($_GET['guided_demo']) && (string) $_GET['guided_demo'] === '1')
        || $activeGuidedDemoStepKey === 'simulate_reply_received'
    );
if (!$guidedDemoInboxPreview) {
    (new WorkspaceCommunicationGateService())->enforceWebRuntime($workspaceId, $user);
}
$smsCatalog = new WorkspaceSkillCatalogService();
$canUseSmsComposer = Authorization::isSuperAdmin($user)
    && !$smsCatalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL);
if (!$canUseSmsComposer && $workspaceId > 0 && !$smsCatalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
    try {
        $canUseSmsComposer = (new WorkspaceSkillInstallService())->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL);
    } catch (\Throwable $e) {
        $canUseSmsComposer = false;
    }
}
// Handle filters
$channel = $_GET['channel'] ?? '';
$status = $_GET['status'] ?? 'all'; // Default to all
$contactId = (int) ($_GET['contact_id'] ?? 0);
$ownerScope = $inbox->resolveOwnerScope($_GET['owner_scope'] ?? null, $canViewAllConversations);
$search = $_GET['search'] ?? '';
$includeBodySearch = isset($_GET['include_body_search']) && $_GET['include_body_search'] === '1';
$triagePriority = $_GET['triage_priority'] ?? '';
$triageStatus = $_GET['triage_status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

// Prepare filters
$filters = [
    'channel' => $channel ?: null,
    'contact_id' => $contactId ?: null,
    'search' => $search ?: null,
    'include_body_search' => $includeBodySearch,
    'triage_priority' => $triagePriority ?: null,
    'triage_status' => $triageStatus ?: null,
    'viewer_user_id' => $userId,
    'can_view_all_conversations' => $canViewAllConversations,
    'owner_scope' => $ownerScope,
];

if ($status === 'archived') {
    $filters['archived'] = true;
} elseif ($status !== 'all') {
    $filters['status'] = $status;
}

// Get communications
$communications = $inbox->getAll($limit, $offset, $filters);

// Get total count
$totalCommunications = $inbox->getCount($filters);

$totalPages = ceil($totalCommunications / $limit);

// Get channel counts and unread (excludes archived and deleted to match inbox list)
$channelStatsData = $inbox->getChannelStats($filters);
$channelStats = $channelStatsData['counts'] ?? [];
$unreadStats = $channelStatsData['unread'] ?? [];

// Get contacts for filter
$contacts = $contactsModule->getAll(500, 0, null, $ownerScope, $userId);
usort($contacts, static function (array $left, array $right): int {
    $leftLabel = trim((string) (($left['first_name'] ?? '') . ' ' . ($left['last_name'] ?? '')));
    $rightLabel = trim((string) (($right['first_name'] ?? '') . ' ' . ($right['last_name'] ?? '')));
    return strcasecmp($leftLabel, $rightLabel);
});

$decodeCommunicationMetadata = static function (array $communication): array {
    $metadata = $communication['metadata'] ?? [];
    if (is_string($metadata) && $metadata !== '') {
        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($metadata) ? $metadata : [];
};
$isGuidedDemoReplyCommunication = static function (array $communication) use ($decodeCommunicationMetadata): bool {
    $metadata = $decodeCommunicationMetadata($communication);
    return (string) ($metadata['source'] ?? '') === 'guided_demo_action'
        && (string) ($metadata['action'] ?? '') === 'simulate_reply_received';
};
$hasGuidedDemoReplyCommunication = false;
foreach ($communications as $communication) {
    if ($isGuidedDemoReplyCommunication($communication)) {
        $hasGuidedDemoReplyCommunication = true;
        break;
    }
}

$pageTitle = 'Inbox - ' . brandProductName();
$inboxGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_INBOX);
$inboxExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $inboxExperienceMode,
    'surface' => 'inbox',
    'current_page' => 'inbox.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'communications' => $communications,
    'total_count' => $totalCommunications,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php echo PageGuideVideoUi::assets(); ?>
<style>
/* Inbox list: robust flex so message body gets width and content doesn't collapse */
.inbox-list { background: var(--white); display: flex; flex-direction: column; width: 100%; min-width: 0; }
.inbox-row { display: flex; width: 100%; min-width: 0; padding: 0; border-bottom: 1px solid var(--border-color); }
.inbox-row:last-child { border-bottom: none; }
.inbox-row.inbox-row-guided-demo-reply { position: relative; border-left: 4px solid #2563eb; background: linear-gradient(90deg, rgba(37, 99, 235, 0.12), rgba(255, 255, 255, 0)); box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.16); }
.inbox-row-guided-demo-reply .inbox-row-link { background: rgba(239, 246, 255, 0.78) !important; }
.inbox-demo-reply-cue { display: inline-flex; align-items: center; gap: 0.3rem; padding: 3px 9px; border-radius: 999px; border: 1px solid #bfdbfe; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; line-height: 1; white-space: nowrap; }
.inbox-row-check { flex: 0 0 auto; padding: 0.95rem 0.5rem 0.75rem 0.75rem; display: flex; align-items: flex-start; }
.inbox-row-link { display: flex; flex-wrap: nowrap; flex: 1 1 0; min-width: 0; gap: 0.75rem; align-items: flex-start; padding: 0.95rem var(--spacing-lg) 0.95rem 0; text-decoration: none; color: inherit; cursor: pointer; transition: background 0.15s ease; box-sizing: border-box; border-radius: 8px; }
.inbox-row-link:hover { background: rgba(37, 99, 235, 0.08) !important; }
.inbox-row-actions { flex: 0 0 auto; margin-left: 0.5rem; padding-top: 0.1rem; }
.inbox-row-body { flex: 1 1 0%; min-width: 0; overflow: hidden; padding-right: 0.25rem; }
.inbox-row-meta { gap: 0.6rem !important; margin-bottom: 0.45rem !important; line-height: 1.3; }
.inbox-row-subject { margin-bottom: 0.4rem !important; line-height: 1.35; }
.inbox-row-preview { margin-bottom: 0.5rem !important; line-height: 1.4; }
.inbox-row-time { display: inline-flex; align-items: center; gap: 0.25rem; color: var(--charcoal-grey); font-size: 12px; }
.inbox-row-link .contact-name-link { color: var(--accent-blue); font-weight: 500; text-decoration: none; }
.inbox-row-link .contact-name-link:hover { text-decoration: underline; }
.inbox-row .inbox-badge { background: #e5e7eb; color: var(--midnight-black); border: 1px solid #d1d5db; padding: 3px 10px; border-radius: 12px; font-size: 12px; text-transform: capitalize; font-weight: 500; flex-shrink: 0; }
.inbox-view-msg { display: inline-flex; align-items: center; gap: 4px; color: var(--accent-blue); font-weight: 600; font-size: 0.8125rem; margin-left: 0.5rem; }
.inbox-row-link:hover .inbox-view-msg { text-decoration: underline; }
/* Direction color distinction */
.inbox-row-inbound { border-left: 3px solid #10b981; }
.inbox-row-outbound { border-left: 3px solid #ef4444; }
.inbox-row-inbound .inbox-badge-direction { background: rgba(16, 185, 129, 0.15); color: #059669; border-color: #10b981; }
.inbox-row-outbound .inbox-badge-direction { background: rgba(239, 68, 68, 0.15); color: #dc2626; border-color: #ef4444; }
/* Remove left space from container - break out of parent container padding */
.inbox-page { 
    margin-left: calc(-1 * var(--spacing-sm)); 
    margin-right: calc(-1 * var(--spacing-sm)); 
    width: calc(100% + 2 * var(--spacing-sm)); 
    padding-left: 0; 
    padding-right: 0; 
}
.inbox-page .inbox-container { padding-left: 0; padding-right: 0; }
#inbox-filter-form {
    display: grid;
    grid-template-columns:
        minmax(240px, 1.65fr)
        minmax(130px, 0.75fr)
        minmax(120px, 0.7fr)
        minmax(220px, 1.25fr)
        minmax(170px, 1fr)
        minmax(165px, 0.95fr)
        minmax(165px, 0.95fr)
        auto;
    gap: 0.85rem;
    align-items: start;
}
#inbox-filter-form .filter-group {
    min-width: 0;
}
#inbox-filter-form .filter-group.filter-group-search {
    grid-column: auto;
}
#inbox-filter-form .filter-actions {
    grid-column: auto;
    align-self: start;
    justify-content: flex-start;
    flex-wrap: wrap;
    padding-top: 1.6rem;
    white-space: nowrap;
}
#inbox-filter-form .filter-label-with-help {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
#inbox-filter-form .triage-status-help {
    position: relative;
    display: inline-flex;
}
#inbox-filter-form .triage-help-trigger {
    width: 1.1rem;
    height: 1.1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    background: transparent;
    color: #64748b;
    cursor: help;
    padding: 0;
}
#inbox-filter-form .triage-help-trigger:hover,
#inbox-filter-form .triage-help-trigger:focus-visible {
    color: #0f172a;
    outline: none;
}
#inbox-filter-form .triage-help-tooltip {
    position: absolute;
    z-index: 20;
    left: 50%;
    bottom: calc(100% + 0.5rem);
    width: min(22rem, calc(100vw - 2rem));
    padding: 0.75rem;
    border-radius: 6px;
    background: #0f172a;
    color: #f8fafc;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.24);
    font-size: 0.75rem;
    line-height: 1.45;
    opacity: 0;
    pointer-events: none;
    transform: translateX(-50%) translateY(0.25rem);
    transition: opacity 120ms ease, transform 120ms ease;
}
#inbox-filter-form .triage-help-tooltip::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 100%;
    transform: translateX(-50%);
    border-width: 0.35rem;
    border-style: solid;
    border-color: #0f172a transparent transparent transparent;
}
#inbox-filter-form .triage-status-help:hover .triage-help-tooltip,
#inbox-filter-form .triage-status-help:focus-within .triage-help-tooltip {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(-50%) translateY(0);
}
.inbox-header-actions {
    align-items: center;
}
.inbox-header-actions .inbox-channel-action {
    border: 1px solid transparent;
    color: #ffffff;
}
.inbox-header-actions .inbox-channel-action:hover {
    color: #ffffff;
}
.inbox-header-actions .inbox-channel-action--whatsapp {
    background: #25d366;
    border-color: #25d366;
}
.inbox-header-actions .inbox-channel-action--whatsapp:hover {
    background: #16a34a;
    border-color: #16a34a;
}
.inbox-header-actions .inbox-channel-action--email {
    background: #2563eb;
    border-color: #2563eb;
}
.inbox-header-actions .inbox-channel-action--email:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
}
.inbox-header-actions .inbox-channel-action--sms {
    background: #7c3aed;
    border-color: #7c3aed;
}
.inbox-header-actions .inbox-channel-action--sms:hover {
    background: #6d28d9;
    border-color: #6d28d9;
}
.stage-stat.inbox-channel-stat {
    border-color: currentColor;
    box-shadow: var(--shadow-sm);
}
.stage-stat.inbox-channel-stat--email {
    background: #eff6ff;
    color: #1d4ed8;
}
.stage-stat.inbox-channel-stat--whatsapp {
    background: #ecfdf5;
    color: #047857;
}
.stage-stat.inbox-channel-stat--sms {
    background: #f5f3ff;
    color: #6d28d9;
}
.stage-stat.inbox-channel-stat.active {
    color: #ffffff;
}
.stage-stat.inbox-channel-stat--email.active {
    background: #2563eb;
    border-color: #2563eb;
}
.stage-stat.inbox-channel-stat--whatsapp.active {
    background: #16a34a;
    border-color: #16a34a;
}
.stage-stat.inbox-channel-stat--sms.active {
    background: #7c3aed;
    border-color: #7c3aed;
}
.inbox-sync-status {
    flex-basis: 100%;
    min-height: 1rem;
    color: #64748b;
    font-size: 0.75rem;
    text-align: right;
}
.inbox-sync-status.is-error {
    color: #b91c1c;
}
.inbox-sync-status.is-success {
    color: #166534;
}
@media (max-width: 1500px) {
    #inbox-filter-form {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }
    #inbox-filter-form .filter-group.filter-group-search {
        grid-column: span 1;
    }
    #inbox-filter-form .filter-actions {
        grid-column: 1 / -1;
        padding-top: 0;
    }
}
@media (max-width: 768px) {
    #inbox-filter-form {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .inbox-sync-status {
        text-align: left;
    }
}
</style>

<div class="page-premium inbox-page">
    <div class="container inbox-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Inbox</h1>
                <p>All your communications in one place</p>
            </div>
            <div class="page-header-actions inbox-header-actions" style="position: relative;">
                <?php 
                $totalUnread = array_sum($unreadStats);
                if ($totalUnread > 0): 
                ?>
                    <span class="badge badge-primary" style="padding: 0.5rem 0.75rem;">
                        <?php echo $totalUnread; ?> unread
                    </span>
                <?php endif; ?>
                
                <?php if ($inboxGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_INBOX, 'Inbox page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>

                <a 
                    href="whatsapp_compose.php"
                    class="btn-premium-primary inbox-channel-action inbox-channel-action--whatsapp"
                    title="Compose WhatsApp message"
                >
                    <i class="fab fa-whatsapp"></i>
                    WhatsApp
                </a>
                
                <a
                    href="email_compose.php"
                    class="btn-premium-primary inbox-channel-action inbox-channel-action--email"
                    title="Compose email"
                >
                    <i class="fas fa-envelope"></i>
                    Email
                </a>

                <?php if ($canUseSmsComposer): ?>
                <a
                    href="bulk_sms.php"
                    class="btn-premium-primary inbox-channel-action inbox-channel-action--sms"
                    title="Compose SMS"
                >
                    <i class="fas fa-sms"></i>
                    SMS
                </a>
                <?php endif; ?>

                <div id="inbox-sync-status" class="inbox-sync-status" aria-live="polite"></div>
            </div>
        </div>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>
        <!-- Search and Filters -->
        <div class="filters-card">
            <form method="GET" action="" class="filters-form" id="inbox-filter-form">
                <div class="filter-group filter-group-search">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search communications..."
                    >
                    <label style="display:flex;align-items:center;gap:0.45rem;margin-top:0.45rem;font-size:0.75rem;color:#64748b;">
                        <input type="checkbox" id="include_body_search" name="include_body_search" value="1" <?php echo $includeBodySearch ? 'checked' : ''; ?> style="width:1rem;height:1rem;">
                        <span>Include message body search</span>
                    </label>
                </div>
                <div class="filter-group">
                    <label for="channel">Channel</label>
                    <select id="channel" name="channel">
                        <option value="">All Channels</option>
                        <option value="email" <?php echo $channel === 'email' ? 'selected' : ''; ?>>Email</option>
                        <option value="whatsapp" <?php echo $channel === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                        <option value="sms" <?php echo $channel === 'sms' ? 'selected' : ''; ?>>SMS</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Read</option>
                        <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="owner_scope">View</label>
                    <select id="owner_scope" name="owner_scope">
                        <option value="mine_unassigned" <?php echo $ownerScope === 'mine_unassigned' ? 'selected' : ''; ?>>My Conversations + Unassigned</option>
                        <?php if ($canViewAllConversations): ?>
                            <option value="all" <?php echo $ownerScope === 'all' ? 'selected' : ''; ?>>All Conversations</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="contact_id">Contact</label>
                    <select id="contact_id" name="contact_id">
                        <option value="">All Contacts</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?php echo $contact['id']; ?>" <?php echo $contactId === $contact['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="triage_priority">Triage Priority</label>
                    <select id="triage_priority" name="triage_priority">
                        <option value="">All Priorities</option>
                        <option value="urgent" <?php echo $triagePriority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $triagePriority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $triagePriority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $triagePriority === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="filter-group filter-group-triage-status">
                    <div class="filter-label-with-help">
                        <label for="triage_status">Triage Status</label>
                        <span class="triage-status-help">
                            <button
                                type="button"
                                class="triage-help-trigger"
                                aria-label="What triage statuses mean"
                                aria-describedby="triage-status-tooltip"
                            >
                                <i class="fas fa-circle-question" aria-hidden="true"></i>
                            </button>
                            <span id="triage-status-tooltip" class="triage-help-tooltip" role="tooltip">
                                <strong>Auto Applied</strong>: triage assigned an owner/task automatically.<br>
                                <strong>Suggested</strong>: triage scored the message but is waiting for a human decision.<br>
                                <strong>Blocked</strong>: triage intentionally refused to act, usually due to opt-out, negative phrases, or low-signal content.<br>
                                <strong>Skipped</strong>: triage did not run or did not apply because the channel, direction, or settings did not qualify.
                            </span>
                        </span>
                    </div>
                    <select id="triage_status" name="triage_status">
                        <option value="">All Triage States</option>
                        <option value="auto_applied" <?php echo $triageStatus === 'auto_applied' ? 'selected' : ''; ?>>Auto Applied</option>
                        <option value="suggested" <?php echo $triageStatus === 'suggested' ? 'selected' : ''; ?>>Suggested</option>
                        <option value="blocked" <?php echo $triageStatus === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                        <option value="skipped" <?php echo $triageStatus === 'skipped' ? 'selected' : ''; ?>>Skipped</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="button" id="filter-btn" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search || $includeBodySearch || $channel || $status !== 'all' || $contactId || $triagePriority || $triageStatus || $ownerScope !== 'mine_unassigned'): ?>
                        <a href="inbox.php" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Channel Stats -->
        <div class="stage-stats">
            <?php 
            $channels = ['email', 'whatsapp', 'sms'];
            $channelLabels = ['Email', 'WhatsApp', 'SMS'];
            foreach ($channels as $index => $channelName): 
                $count = $channelStats[$channelName] ?? 0;
                $unread = $unreadStats[$channelName] ?? 0;
                $isActive = $channel === $channelName;
            ?>
                <a href="?channel=<?php echo $channelName; ?>&owner_scope=<?php echo urlencode($ownerScope); ?><?php echo $guidedDemoInboxPreview ? '&guided_demo=1' : ''; ?>" class="stage-stat inbox-channel-stat inbox-channel-stat--<?php echo $channelName; ?> <?php echo $isActive ? 'active' : ''; ?>" data-inbox-channel-stat="<?php echo htmlspecialchars($channelName); ?>" data-channel-label="<?php echo htmlspecialchars($channelLabels[$index]); ?>">
                    <?php echo $channelLabels[$index]; ?> (<?php echo $count; ?>)
                    <?php if ($unread > 0): ?>
                        <span style="background: <?php echo $isActive ? 'rgba(255,255,255,0.3)' : '#667eea'; ?>; color: white; padding: 2px 6px; border-radius: 10px; margin-left: 4px; font-size: 0.6875rem;">
                            <?php echo $unread; ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Bulk Actions Toolbar -->
        <div id="bulk-actions-toolbar" class="content-card" style="display: none; margin-bottom: 1rem;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span id="selected-count" style="color: #64748b; font-weight: 500;">0 selected</span>
                <div style="flex: 1;"></div>
                <button onclick="bulkAction('mark_read')" class="btn-premium-primary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                    Mark Read
                </button>
                <button onclick="bulkAction('mark_unread')" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                    Mark Unread
                </button>
                <button onclick="bulkAction('archive')" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                    Archive
                </button>
                <button onclick="bulkAction('delete')" class="btn-premium-secondary" style="background: #ef4444; color: white; border: none; padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                    Delete
                </button>
                <button onclick="clearSelection()" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                    Clear
                </button>
            </div>
        </div>

        <!-- Communications List -->
        <div class="table-card" data-guided-demo-inbox-card="1"<?php echo $hasGuidedDemoReplyCommunication ? '' : ' data-guided-demo-target="inbox-founder-thread"'; ?>>
            <div id="inbox-list-container">
            <?php if (empty($communications)): ?>
                <div class="empty-state">
                    <p>No communications found.</p>
                    <?php if ($status === 'unread'): ?>
                        <p style="font-size: 0.875rem;">All caught up! No unread messages.</p>
                    <?php endif; ?>
                    <?php if ($channel === 'whatsapp'): ?>
                        <p style="font-size: 0.875rem; margin-top: 0.5rem; color: var(--charcoal-grey);">
                            WhatsApp messages arrive via webhook. If you sent a message and do not see it here, ensure the webhook is verified in Meta Business Manager and subscribed to <strong>messages</strong>.
                        </p>
                        <a href="workspace_skills.php?module=whatsapp#setup" style="font-size: 0.875rem; color: var(--accent-blue); margin-top: 0.5rem; display: inline-block;">Check WhatsApp setup</a>
                    <?php endif; ?>
                </div>
    <?php else: ?>
        <!-- Select All Header -->
        <div style="padding: var(--spacing-sm) var(--spacing-md) var(--spacing-sm) 0; border-bottom: 2px solid var(--border-color); background: #f9f9f9; display: flex; align-items: center; gap: 0.5rem;">
            <input 
                type="checkbox" 
                id="select-all-checkbox"
                onchange="toggleSelectAll()"
                style="width: 18px; height: 18px; cursor: pointer;"
            >
            <label for="select-all-checkbox" style="cursor: pointer; color: var(--charcoal-grey); font-size: 14px; font-weight: 500; margin: 0;">
                Select All
            </label>
        </div>
        
        <div class="inbox-list">
            <?php foreach ($communications as $comm): ?>
                <?php
                    $isOutbound = ($comm['direction'] ?? '') === 'outbound';
                    $commMeta = $decodeCommunicationMetadata($comm);
                    $mediaType = $commMeta['message_type'] ?? '';
                    $isGuidedDemoReply = (string) ($commMeta['source'] ?? '') === 'guided_demo_action'
                        && (string) ($commMeta['action'] ?? '') === 'simulate_reply_received';
                    $isRiversideDemoThread = in_array((string) ($commMeta['demo_event_key'] ?? ''), ['whatsapp_lead_received', 'assistant_draft_ready', 'assistant_draft_typing_started', 'email_inquiry_received', 'procurement_followup_received'], true)
                        || stripos((string) ($comm['subject'] ?? ''), 'Riverside') !== false
                        || stripos((string) ($comm['contact_name'] ?? ''), 'Amina') !== false;
                    $rowClasses = 'inbox-row' . ($isOutbound ? ' inbox-row-outbound' : ' inbox-row-inbound') . ($isGuidedDemoReply ? ' inbox-row-guided-demo-reply' : '') . ($isRiversideDemoThread ? ' inbox-row-riverside-demo' : '');
                ?>
                <div
                    class="<?php echo htmlspecialchars($rowClasses); ?>"
                    data-communication-id="<?php echo (int) $comm['id']; ?>"
                    <?php echo $isGuidedDemoReply ? 'data-guided-demo-target="inbox-founder-thread"' : ''; ?>
                    <?php echo $isRiversideDemoThread ? 'data-demo-riverside-thread="1" data-demo-cue-key="riverside_thread_visible"' : ''; ?>
                    style="<?php echo !$comm['read_at'] ? 'background: #f0f7ff;' : ''; ?>"
                >
                    <div
                        class="inbox-row-link"
                        style="<?php echo !$comm['read_at'] ? 'background: #f0f7ff;' : ''; ?>"
                        role="link"
                        tabindex="0"
                        data-conversation-url="conversation.php?id=<?php echo (int) $comm['id']; ?>&contact_id=<?php echo (int) ($comm['contact_id'] ?? 0); ?>&channel=<?php echo urlencode((string) ($comm['channel'] ?? '')); ?>&owner_scope=<?php echo urlencode($ownerScope); ?>"
                        onclick="openConversationFromRow(event, this);"
                        onkeydown="openConversationFromKey(event, this);"
                    >
                        <span class="inbox-row-check" onclick="event.stopPropagation();">
                            <input 
                                type="checkbox" 
                                class="comm-checkbox" 
                                value="<?php echo $comm['id']; ?>"
                                onchange="updateBulkActions()"
                                style="width: 18px; height: 18px; cursor: pointer;"
                            >
                        </span>
                        <div class="inbox-row-body">
                            <div class="inbox-row-meta" style="display: flex; align-items: center; gap: var(--spacing-sm); margin-bottom: var(--spacing-xs); flex-wrap: wrap;">
                                <?php if (!$comm['read_at']): ?>
                                    <span style="width: 8px; height: 8px; background: var(--accent-blue); border-radius: 50%; display: inline-block;"></span>
                                <?php endif; ?>
                                <span class="inbox-badge"><?php echo htmlspecialchars($comm['channel']); ?></span>
                                <?php if ($isGuidedDemoReply): ?>
                                    <span class="inbox-demo-reply-cue"><i class="fas fa-reply" aria-hidden="true"></i>New buyer reply</span>
                                <?php endif; ?>
                                <?php if (in_array($mediaType, ['image', 'video', 'audio', 'document', 'sticker'], true)): ?>
                                    <span style="color: var(--charcoal-grey); font-size: 12px;" title="<?php echo htmlspecialchars(ucfirst($mediaType)); ?>">
                                        <?php echo $mediaType === 'image' ? '🖼' : ($mediaType === 'video' ? '🎬' : ($mediaType === 'audio' ? '🔊' : ($mediaType === 'sticker' ? '🏷' : '📎'))); ?> <?php echo htmlspecialchars(ucfirst($mediaType)); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="inbox-badge inbox-badge-direction"><?php echo htmlspecialchars($comm['direction'] === 'inbound' ? 'Inbound' : 'Outbound'); ?></span>
                                <?php if ($comm['contact_id']): ?>
                                    <span style="color: var(--charcoal-grey);">•</span>
                                    <a href="contact_view.php?id=<?php echo $comm['contact_id']; ?>" class="contact-name-link" onclick="event.stopPropagation();" title="Open contact">
                                        <?php echo htmlspecialchars($comm['contact_name'] ?? 'Contact #' . $comm['contact_id']); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($comm['channel'] === 'whatsapp' && $comm['direction'] === 'inbound' && $comm['contact_id']): ?>
                                    <span style="color: var(--charcoal-grey);">•</span>
                                    <a 
                                        href="whatsapp_compose.php?contact_id=<?php echo $comm['contact_id']; ?>" 
                                        onclick="event.stopPropagation();"
                                        style="color: #25D366; font-weight: 500; text-decoration: none; font-size: 13px;"
                                        title="Reply via WhatsApp"
                                    >
                                        💬 Reply
                                    </a>
                                <?php endif; ?>
                                <?php
                                if (($comm['direction'] ?? '') === 'inbound' && (int) ($comm['body_length'] ?? strlen((string) ($comm['body_preview'] ?? ''))) > 10) {
                                    $sentiment = $commMeta['sentiment']['sentiment'] ?? null;
                                    $intentVal = $commMeta['intent']['intent'] ?? null;
                                    if ($sentiment !== null || $intentVal !== null) {
                                        $sentColors = ['positive' => '#16a34a', 'negative' => '#dc2626', 'neutral' => '#6b7280'];
                                        $sentColor = $sentColors[$sentiment ?? 'neutral'] ?? '#6b7280';
                                ?>
                                    <span style="color: var(--charcoal-grey);">•</span>
                                    <?php if ($sentiment !== null): ?>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: <?php echo $sentColor; ?>20; color: <?php echo $sentColor; ?>;"><?php echo ucfirst($sentiment); ?></span>
                                    <?php endif; ?>
                                    <?php if ($intentVal !== null): ?>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #e5e7eb; color: #374151;"><?php echo ucfirst($intentVal); ?></span>
                                    <?php endif; ?>
                                <?php
                                    }
                                }
                                ?>
                                <?php
                                $triagePriorityVal = strtolower((string) ($comm['triage_priority'] ?? ''));
                                $triageStatusVal = strtolower((string) ($comm['triage_status'] ?? ''));
                                $triageReasonCodes = [];
                                if (isset($comm['triage_reason_codes'])) {
                                    if (is_string($comm['triage_reason_codes']) && $comm['triage_reason_codes'] !== '') {
                                        $decodedReasons = json_decode($comm['triage_reason_codes'], true);
                                        $triageReasonCodes = is_array($decodedReasons) ? $decodedReasons : [];
                                    } elseif (is_array($comm['triage_reason_codes'])) {
                                        $triageReasonCodes = $comm['triage_reason_codes'];
                                    }
                                }
                                if (in_array($triagePriorityVal, ['urgent', 'high', 'medium', 'low'], true) || $triageStatusVal !== ''):
                                    $priorityBg = '#e5e7eb';
                                    $priorityColor = '#334155';
                                    if ($triagePriorityVal === 'urgent') {
                                        $priorityBg = '#fee2e2';
                                        $priorityColor = '#b91c1c';
                                    } elseif ($triagePriorityVal === 'high') {
                                        $priorityBg = '#ffedd5';
                                        $priorityColor = '#c2410c';
                                    } elseif ($triagePriorityVal === 'medium') {
                                        $priorityBg = '#fef9c3';
                                        $priorityColor = '#854d0e';
                                    } elseif ($triagePriorityVal === 'low') {
                                        $priorityBg = '#dcfce7';
                                        $priorityColor = '#166534';
                                    }
                                ?>
                                    <span style="color: var(--charcoal-grey);">•</span>
                                    <?php if ($triagePriorityVal !== ''): ?>
                                        <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: <?php echo $priorityBg; ?>; color: <?php echo $priorityColor; ?>;">
                                            Triage <?php echo htmlspecialchars(ucfirst($triagePriorityVal)); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($triageStatusVal !== ''): ?>
                                        <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #e2e8f0; color: #334155;">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($triageStatusVal))); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($triageReasonCodes)): ?>
                                        <span style="font-size: 11px; color: #64748b;">
                                            <?php echo htmlspecialchars((string) $triageReasonCodes[0]); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php
                                $threadMeta = is_string($comm['thread_metadata_json'] ?? '') ? json_decode((string) ($comm['thread_metadata_json'] ?? ''), true) : ($comm['thread_metadata_json'] ?? []);
                                $threadMeta = is_array($threadMeta) ? $threadMeta : [];
                                $threadStatus = strtolower((string) ($comm['thread_status'] ?? ''));
                                $threadDue = trim((string) ($comm['thread_response_due_at'] ?? ''));
                                $threadOverdue = $threadDue !== '' && strtotime($threadDue) !== false && strtotime($threadDue) < time() && in_array($threadStatus, ['open', 'waiting_on_us'], true);
                                $statusColors = [
                                    'waiting_on_us' => ['bg' => '#dbeafe', 'color' => '#1d4ed8'],
                                    'waiting_on_contact' => ['bg' => '#ecfccb', 'color' => '#4d7c0f'],
                                    'resolved' => ['bg' => '#e5e7eb', 'color' => '#475569'],
                                    'open' => ['bg' => '#ede9fe', 'color' => '#6d28d9'],
                                ];
                                $threadStatusStyle = $statusColors[$threadStatus] ?? ['bg' => '#e5e7eb', 'color' => '#334155'];
                                ?>
                                <?php if ($threadStatus !== ''): ?>
                                    <span style="color: var(--charcoal-grey);">•</span>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: <?php echo $threadStatusStyle['bg']; ?>; color: <?php echo $threadStatusStyle['color']; ?>;">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($threadStatus))); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($threadOverdue): ?>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #fee2e2; color: #b91c1c;">OVERDUE</span>
                                <?php endif; ?>
                                <?php if (!empty($comm['thread_owner_id'])): ?>
                                    <?php $threadOwnerLabel = $isRiversideDemoThread ? 'You (demo owner)' : ('Owner #' . (int) $comm['thread_owner_id']); ?>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #f8fafc; color: #475569; border: 1px solid #cbd5e1;"><?php echo htmlspecialchars($threadOwnerLabel); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="inbox-row-subject" style="font-weight: <?php echo !$comm['read_at'] ? '600' : '500'; ?>; color: var(--midnight-black); margin-bottom: var(--spacing-xs);">
                                <?php echo htmlspecialchars($comm['subject'] ?? substr((string) ($comm['body_preview'] ?? ''), 0, 100)); ?>
                            </div>
                            <?php if (!empty($comm['body_preview'])): ?>
                                <?php $bodyPreview = substr(strip_tags((string) $comm['body_preview']), 0, 150); ?>
                                <div class="inbox-row-preview" style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); max-height: 40px; overflow: hidden;">
                                    <?php echo htmlspecialchars($bodyPreview); ?><?php echo strlen($bodyPreview) >= 150 ? '…' : ''; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($threadMeta['next_best_action']) || !empty($threadMeta['thread_summary'])): ?>
                                <div style="display:flex; flex-direction:column; gap:4px; margin-bottom:8px;">
                                    <?php if (!empty($threadMeta['next_best_action'])): ?>
                                        <div style="font-size:12px; color:#0f172a;"><strong>Next:</strong> <?php echo htmlspecialchars((string) $threadMeta['next_best_action']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($threadMeta['thread_summary'])): ?>
                                        <div style="font-size:12px; color:#64748b;"><?php echo htmlspecialchars((string) $threadMeta['thread_summary']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="inbox-row-time" style="color: var(--charcoal-grey); font-size: 12px;">
                                <?php echo date('M j, Y g:i A', strtotime($comm['created_at'])); ?>
                                <a href="conversation.php?id=<?php echo (int) $comm['id']; ?>&contact_id=<?php echo (int) ($comm['contact_id'] ?? 0); ?>&channel=<?php echo urlencode((string) ($comm['channel'] ?? '')); ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="inbox-view-msg" onclick="event.stopPropagation();">View message -&gt;</a>
                            </div>
                        </div>
                        <span class="inbox-row-actions" onclick="event.preventDefault(); event.stopPropagation();">
                            <div style="position: relative;">
                                <button 
                                    type="button"
                                    onclick="toggleActionsMenu(<?php echo $comm['id']; ?>)"
                                    style="background: none; border: none; cursor: pointer; padding: 4px 8px; color: var(--charcoal-grey); font-size: 18px;"
                                    title="Actions"
                                >
                                    ⋮
                                </button>
                                <div id="actions-menu-<?php echo $comm['id']; ?>" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 100; min-width: 150px; margin-top: 4px;">
                                    <?php if ($comm['channel'] === 'whatsapp' && $comm['contact_id']): ?>
                                        <a href="whatsapp_compose.php?contact_id=<?php echo $comm['contact_id']; ?>" onclick="event.stopPropagation();" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #25D366; font-size: 14px; text-decoration: none; border-bottom: 1px solid var(--border-color);">
                                            💬 Reply via WhatsApp
                                        </a>
                                    <?php endif; ?>
                                    <?php if (($comm['triage_status'] ?? '') === 'suggested'): ?>
                                        <button type="button" onclick="event.stopPropagation(); triageAction(<?php echo $comm['id']; ?>, 'accept_suggestion')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #0f766e; font-size: 14px;">
                                            Accept Triage
                                        </button>
                                    <?php endif; ?>
                                    <?php if (($comm['triage_status'] ?? '') === 'auto_applied'): ?>
                                        <button type="button" onclick="event.stopPropagation(); triageAction(<?php echo $comm['id']; ?>, 'revert_auto_apply')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #b45309; font-size: 14px;">
                                            Revert Triage
                                        </button>
                                        <button type="button" onclick="event.stopPropagation(); triageAction(<?php echo $comm['id']; ?>, 'snooze_task')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #334155; font-size: 14px;">
                                            Snooze Task
                                        </button>
                                        <button type="button" onclick="event.stopPropagation(); triageReassignPrompt(<?php echo $comm['id']; ?>)" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #334155; font-size: 14px;">
                                            Reassign Owner
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="event.stopPropagation(); performAction(<?php echo $comm['id']; ?>, '<?php echo $comm['read_at'] ? 'mark_unread' : 'mark_read'; ?>')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: var(--charcoal-grey); font-size: 14px;">
                                        <?php echo $comm['read_at'] ? 'Mark Unread' : 'Mark Read'; ?>
                                    </button>
                                    <?php if ($comm['archived_at']): ?>
                                        <button type="button" onclick="event.stopPropagation(); performAction(<?php echo $comm['id']; ?>, 'unarchive')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: var(--charcoal-grey); font-size: 14px;">
                                            Unarchive
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="event.stopPropagation(); performAction(<?php echo $comm['id']; ?>, 'archive')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: var(--charcoal-grey); font-size: 14px;">
                                            Archive
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="event.stopPropagation(); performAction(<?php echo $comm['id']; ?>, 'delete')" style="display: block; width: 100%; text-align: left; padding: var(--spacing-sm); border: none; background: none; cursor: pointer; color: #dc3545; font-size: 14px;">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalCommunications); ?> of <?php echo $totalCommunications; ?> communications
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $includeBodySearch ? '&include_body_search=1' : ''; ?><?php echo $channel ? '&channel=' . urlencode($channel) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?><?php echo $triagePriority ? '&triage_priority=' . urlencode($triagePriority) : ''; ?><?php echo $triageStatus ? '&triage_status=' . urlencode($triageStatus) : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link" data-page="<?php echo $page - 1; ?>">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $includeBodySearch ? '&include_body_search=1' : ''; ?><?php echo $channel ? '&channel=' . urlencode($channel) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?><?php echo $triagePriority ? '&triage_priority=' . urlencode($triagePriority) : ''; ?><?php echo $triageStatus ? '&triage_status=' . urlencode($triageStatus) : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>" data-page="<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $includeBodySearch ? '&include_body_search=1' : ''; ?><?php echo $channel ? '&channel=' . urlencode($channel) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?><?php echo $triagePriority ? '&triage_priority=' . urlencode($triagePriority) : ''; ?><?php echo $triageStatus ? '&triage_status=' . urlencode($triageStatus) : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link" data-page="<?php echo $page + 1; ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let openMenuId = null;
const isGuidedDemoVisit = <?php echo $guidedDemoInboxPreview ? 'true' : 'false'; ?>;

// Async filter: build URL and fetch
function buildFilterParams() {
    const form = document.getElementById('inbox-filter-form');
    const params = new URLSearchParams();
    const search = form.querySelector('#search').value.trim();
    const includeBodySearch = form.querySelector('#include_body_search').checked;
    const channel = form.querySelector('#channel').value;
    const status = form.querySelector('#status').value;
    const ownerScope = form.querySelector('#owner_scope').value;
    const contactId = form.querySelector('#contact_id').value;
    const triagePriority = form.querySelector('#triage_priority').value;
    const triageStatus = form.querySelector('#triage_status').value;
    if (search) params.set('search', search);
    if (includeBodySearch) params.set('include_body_search', '1');
    if (channel) params.set('channel', channel);
    if (status) params.set('status', status);
    if (ownerScope) params.set('owner_scope', ownerScope);
    if (contactId) params.set('contact_id', contactId);
    if (triagePriority) params.set('triage_priority', triagePriority);
    if (triageStatus) params.set('triage_status', triageStatus);
    if (isGuidedDemoVisit) params.set('guided_demo', '1');
    params.set('list', '1');
    return params;
}

function escapeInboxText(value) {
    return String(value || '').replace(/[&<>"']/g, function(char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char] || char;
    });
}

function buildConversationUrl(id, contactId, channel, ownerScope) {
    const params = new URLSearchParams();
    if (id) params.set('id', String(id));
    if (contactId) params.set('contact_id', String(contactId));
    if (channel) params.set('channel', String(channel));
    if (ownerScope) params.set('owner_scope', String(ownerScope));
    return 'conversation.php?' + params.toString();
}

function resolveConversationUrl(targetOrId, contactId, channel, ownerScope) {
    if (targetOrId && typeof targetOrId === 'object' && targetOrId.dataset) {
        return targetOrId.dataset.conversationUrl || '';
    }
    if (!targetOrId && !contactId) return '';
    return buildConversationUrl(targetOrId, contactId, channel, ownerScope);
}

function navigateToConversation(url) {
    if (!url) return;
    window.location.assign(url);
}

function openConversationFromRow(e, targetOrId, contactId, channel) {
    const t = e && e.target ? e.target : null;
    if (t && t.closest && t.closest('a, button, input, label, select, textarea')) {
        return;
    }
    const selectedText = window.getSelection ? String(window.getSelection()).trim() : '';
    if (selectedText !== '') {
        return;
    }
    const url = resolveConversationUrl(targetOrId, contactId, channel, getCurrentOwnerScope());
    if (!url) return;
    if (e && e.preventDefault) e.preventDefault();
    const row = targetOrId && typeof targetOrId === 'object' && targetOrId.closest
        ? targetOrId.closest('[data-demo-riverside-thread="1"]')
        : null;
    if (row && typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('protected-demo:cue', {
            detail: {
                cue: 'riverside_thread_opened',
                visibleKey: 'riverside_thread',
                entityType: 'communication',
                entityId: row.getAttribute('data-communication-id') || ''
            }
        }));
    }
    navigateToConversation(url);
}

function openConversationFromKey(e, targetOrId, contactId, channel) {
    if (!e) return;
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        const url = resolveConversationUrl(targetOrId, contactId, channel, getCurrentOwnerScope());
        const row = targetOrId && typeof targetOrId === 'object' && targetOrId.closest
            ? targetOrId.closest('[data-demo-riverside-thread="1"]')
            : null;
        if (row && typeof window.CustomEvent === 'function') {
            window.dispatchEvent(new CustomEvent('protected-demo:cue', {
                detail: {
                    cue: 'riverside_thread_opened',
                    visibleKey: 'riverside_thread',
                    entityType: 'communication',
                    entityId: row.getAttribute('data-communication-id') || ''
                }
            }));
        }
        navigateToConversation(url);
    }
}

function getCurrentOwnerScope() {
    const scopeInput = document.getElementById('owner_scope');
    return scopeInput ? scopeInput.value : 'mine_unassigned';
}

function syncContactOptions(contacts, selectedContactId) {
    const select = document.getElementById('contact_id');
    if (!select || !Array.isArray(contacts)) {
        return;
    }

    const desiredValue = selectedContactId ? String(selectedContactId) : '';
    select.innerHTML = '<option value="">All Contacts</option>';

    contacts.forEach((contact) => {
        const option = document.createElement('option');
        option.value = String(contact.id || '');
        const fullName = [contact.first_name || '', contact.last_name || ''].join(' ').trim();
        option.textContent = fullName || contact.email || ('Contact #' + String(contact.id || ''));
        if (option.value === desiredValue) {
            option.selected = true;
        }
        select.appendChild(option);
    });

    if (desiredValue !== '' && select.value !== desiredValue) {
        select.value = '';
    }
}

function syncChannelStats(channelStats, unreadStats) {
    channelStats = channelStats || {};
    unreadStats = unreadStats || {};
    document.querySelectorAll('[data-inbox-channel-stat]').forEach(function(link) {
        const channel = link.getAttribute('data-inbox-channel-stat') || '';
        const label = link.getAttribute('data-channel-label') || channel;
        const count = Number(channelStats[channel] || 0);
        const unread = Number(unreadStats[channel] || 0);
        const active = link.classList.contains('active');
        let html = escapeInboxText(label) + ' (' + String(count) + ')';
        if (unread > 0) {
            html += '<span style="background: ' + (active ? 'rgba(255,255,255,0.3)' : '#667eea') + '; color: white; padding: 2px 6px; border-radius: 10px; margin-left: 4px; font-size: 0.6875rem;">' + String(unread) + '</span>';
        }
        link.innerHTML = html;
    });
}

function renderCommRow(comm, ownerScope) {
    const meta = typeof comm.metadata === 'string' ? (JSON.parse(comm.metadata || '{}') || {}) : (comm.metadata || {});
    const mediaType = meta.message_type || '';
    const mediaIcons = { image: '🖼', video: '🎬', audio: '🔊', sticker: '🏷', document: '📎' };
    const mediaLabel = mediaType ? (mediaIcons[mediaType] || '') + ' ' + (mediaType.charAt(0).toUpperCase() + mediaType.slice(1)) : '';
    const isOutbound = (comm.direction || '') === 'outbound';
    const isGuidedDemoReply = meta.source === 'guided_demo_action' && meta.action === 'simulate_reply_received';
    const isRiversideDemoThread = ['whatsapp_lead_received', 'assistant_draft_ready', 'assistant_draft_typing_started', 'email_inquiry_received', 'procurement_followup_received'].includes(String(meta.demo_event_key || ''))
        || String(comm.subject || '').toLowerCase().includes('riverside')
        || String(comm.contact_name || '').toLowerCase().includes('amina');
    const rowClass = (isOutbound ? 'inbox-row inbox-row-outbound' : 'inbox-row inbox-row-inbound') + (isGuidedDemoReply ? ' inbox-row-guided-demo-reply' : '') + (isRiversideDemoThread ? ' inbox-row-riverside-demo' : '');
    const unreadStyle = !comm.read_at ? 'background: #f0f7ff;' : '';
    const contactName = comm.contact_name || ('Contact #' + comm.contact_id);
    const previewSource = String(comm.body_preview || '');
    const subject = comm.subject || previewSource.substring(0, 100);
    const bodyPreview = previewSource.replace(/<[^>]+>/g, '').substring(0, 150);
    const created = comm.created_at ? new Date(comm.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
    const sentiment = meta.sentiment?.sentiment;
    const intentVal = meta.intent?.intent;
    const sentColors = { positive: '#16a34a', negative: '#dc2626', neutral: '#6b7280' };
    const sentColor = sentColors[sentiment || 'neutral'] || '#6b7280';
    const showSentiment = (comm.direction === 'inbound' && Number(comm.body_length || previewSource.length) > 10 && (sentiment || intentVal));
    const markAction = comm.read_at ? 'mark_unread' : 'mark_read';
    const markLabel = comm.read_at ? 'Mark Unread' : 'Mark Read';
    const archiveAction = comm.archived_at ? 'unarchive' : 'archive';
    const archiveLabel = comm.archived_at ? 'Unarchive' : 'Archive';
    const triagePriority = (comm.triage_priority || '').toLowerCase();
    const triageStatus = (comm.triage_status || '').toLowerCase();
    const threadMeta = (typeof comm.thread_metadata_json === 'string'
        ? (JSON.parse(comm.thread_metadata_json || '{}') || {})
        : (comm.thread_metadata_json || {}));
    const threadStatus = String(comm.thread_status || '').toLowerCase();
    const threadDue = String(comm.thread_response_due_at || '');
    const threadOverdue = !!(threadDue && !Number.isNaN(Date.parse(threadDue)) && Date.parse(threadDue) < Date.now() && ['open', 'waiting_on_us'].includes(threadStatus));
    const triageReasonCodes = Array.isArray(comm.triage_reason_codes)
        ? comm.triage_reason_codes
        : (typeof comm.triage_reason_codes === 'string' && comm.triage_reason_codes ? (JSON.parse(comm.triage_reason_codes || '[]') || []) : []);
    const esc = s => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const rowTargetAttrs = ' data-communication-id="' + esc(String(comm.id || '')) + '"'
        + (isGuidedDemoReply ? ' data-guided-demo-target="inbox-founder-thread"' : '')
        + (isRiversideDemoThread ? ' data-demo-riverside-thread="1" data-demo-cue-key="riverside_thread_visible"' : '');
    let metaHtml = '';
    if (isGuidedDemoReply) metaHtml += '<span class="inbox-demo-reply-cue"><i class="fas fa-reply" aria-hidden="true"></i>New buyer reply</span>';
    if (!comm.read_at) metaHtml += '<span style="width:8px;height:8px;background:var(--accent-blue);border-radius:50%;display:inline-block;"></span>';
    metaHtml += '<span class="inbox-badge">' + esc(comm.channel) + '</span>';
    if (mediaLabel) metaHtml += '<span style="color:var(--charcoal-grey);font-size:12px;">' + esc(mediaLabel) + '</span>';
    metaHtml += '<span class="inbox-badge inbox-badge-direction">' + (comm.direction === 'inbound' ? 'Inbound' : 'Outbound') + '</span>';
    if (comm.contact_id) metaHtml += '<span style="color:var(--charcoal-grey);">•</span><a href="contact_view.php?id=' + comm.contact_id + '" class="contact-name-link" onclick="event.stopPropagation();">' + esc(contactName) + '</a>';
    if (comm.channel === 'whatsapp' && comm.direction === 'inbound' && comm.contact_id) metaHtml += '<span style="color:var(--charcoal-grey);">•</span><a href="whatsapp_compose.php?contact_id=' + comm.contact_id + '" onclick="event.stopPropagation();" style="color:#25D366;font-weight:500;text-decoration:none;font-size:13px;">💬 Reply</a>';
    if (showSentiment) {
        if (sentiment) metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:' + sentColor + '20;color:' + sentColor + ';">' + (sentiment.charAt(0).toUpperCase() + sentiment.slice(1)) + '</span>';
        if (intentVal) metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:#e5e7eb;color:#374151;">' + (intentVal.charAt(0).toUpperCase() + intentVal.slice(1)) + '</span>';
    }
    if (triagePriority || triageStatus) {
        const triageStyles = {
            urgent: { bg: '#fee2e2', color: '#b91c1c' },
            high: { bg: '#ffedd5', color: '#c2410c' },
            medium: { bg: '#fef9c3', color: '#854d0e' },
            low: { bg: '#dcfce7', color: '#166534' }
        };
        const triageStyle = triageStyles[triagePriority] || { bg: '#e5e7eb', color: '#334155' };
        metaHtml += '<span style="color:var(--charcoal-grey);">•</span>';
        if (triagePriority) metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:' + triageStyle.bg + ';color:' + triageStyle.color + ';">Triage ' + esc(triagePriority.charAt(0).toUpperCase() + triagePriority.slice(1)) + '</span>';
        if (triageStatus) metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:#e2e8f0;color:#334155;">' + esc(triageStatus.replace('_', ' ')) + '</span>';
        if (triageReasonCodes.length > 0) metaHtml += '<span style="font-size:11px;color:#64748b;">' + esc(String(triageReasonCodes[0])) + '</span>';
    }
    if (threadStatus) {
        const threadStyles = {
            waiting_on_us: { bg: '#dbeafe', color: '#1d4ed8' },
            waiting_on_contact: { bg: '#ecfccb', color: '#4d7c0f' },
            resolved: { bg: '#e5e7eb', color: '#475569' },
            open: { bg: '#ede9fe', color: '#6d28d9' }
        };
        const threadStyle = threadStyles[threadStatus] || { bg: '#e5e7eb', color: '#334155' };
        metaHtml += '<span style="color:var(--charcoal-grey);">•</span>';
        metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:' + threadStyle.bg + ';color:' + threadStyle.color + ';">' + esc(threadStatus.replace(/_/g, ' ').toUpperCase()) + '</span>';
    }
    if (threadOverdue) metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:#fee2e2;color:#b91c1c;">OVERDUE</span>';
    if (comm.thread_owner_id) {
        const ownerLabel = isRiversideDemoThread ? 'You (demo owner)' : ('Owner #' + String(comm.thread_owner_id));
        metaHtml += '<span style="font-size:11px;padding:2px 6px;border-radius:4px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;">' + esc(ownerLabel) + '</span>';
    }
    const threadSummaryHtml = (threadMeta.next_best_action || threadMeta.thread_summary)
        ? '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">'
            + (threadMeta.next_best_action ? '<div style="font-size:12px;color:#0f172a;"><strong>Next:</strong> ' + esc(String(threadMeta.next_best_action)) + '</div>' : '')
            + (threadMeta.thread_summary ? '<div style="font-size:12px;color:#64748b;">' + esc(String(threadMeta.thread_summary)) + '</div>' : '')
            + '</div>'
        : '';
    const whatsappReply = (comm.channel === 'whatsapp' && comm.contact_id) ? '<a href="whatsapp_compose.php?contact_id=' + comm.contact_id + '" onclick="event.stopPropagation();" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#25D366;font-size:14px;text-decoration:none;border-bottom:1px solid var(--border-color);">💬 Reply via WhatsApp</a>' : '';
    const triageAccept = triageStatus === 'suggested'
        ? '<button type="button" onclick="event.stopPropagation();triageAction(' + comm.id + ',&quot;accept_suggestion&quot;)" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#0f766e;font-size:14px;">Accept Triage</button>'
        : '';
    const triageRevert = triageStatus === 'auto_applied'
        ? '<button type="button" onclick="event.stopPropagation();triageAction(' + comm.id + ',&quot;revert_auto_apply&quot;)" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#b45309;font-size:14px;">Revert Triage</button>'
        : '';
    const triageSnooze = triageStatus === 'auto_applied'
        ? '<button type="button" onclick="event.stopPropagation();triageAction(' + comm.id + ',&quot;snooze_task&quot;)" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#334155;font-size:14px;">Snooze Task</button>'
        : '';
    const triageReassign = triageStatus === 'auto_applied'
        ? '<button type="button" onclick="event.stopPropagation();triageReassignPrompt(' + comm.id + ')" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#334155;font-size:14px;">Reassign Owner</button>'
        : '';
    return '<div class="' + rowClass + '" style="' + unreadStyle + '"' + rowTargetAttrs + '><div class="inbox-row-link" style="' + unreadStyle + '" role="link" tabindex="0" data-conversation-url="' + esc(buildConversationUrl(comm.id, (comm.contact_id || 0), (comm.channel || ''), ownerScope)) + '" onclick="openConversationFromRow(event, this);" onkeydown="openConversationFromKey(event, this);">' +
        '<span class="inbox-row-check" onclick="event.stopPropagation();"><input type="checkbox" class="comm-checkbox" value="' + comm.id + '" onchange="updateBulkActions()" style="width:18px;height:18px;cursor:pointer;"></span>' +
        '<div class="inbox-row-body"><div class="inbox-row-meta" style="display:flex;align-items:center;gap:var(--spacing-sm);margin-bottom:var(--spacing-xs);flex-wrap:wrap;">' + metaHtml + '</div>' +
        '<div class="inbox-row-subject" style="font-weight:' + (!comm.read_at ? '600' : '500') + ';color:var(--midnight-black);margin-bottom:var(--spacing-xs);">' + esc(subject) + '</div>' +
        (bodyPreview ? '<div class="inbox-row-preview" style="color:var(--charcoal-grey);font-size:14px;margin-bottom:var(--spacing-xs);max-height:40px;overflow:hidden;">' + esc(bodyPreview) + (bodyPreview.length >= 150 ? '…' : '') + '</div>' : '') +
        threadSummaryHtml +
        '<div class="inbox-row-time" style="color:var(--charcoal-grey);font-size:12px;">' + esc(created) + ' <a href="' + buildConversationUrl(comm.id, (comm.contact_id || 0), (comm.channel || ''), ownerScope) + '" class="inbox-view-msg" onclick="event.stopPropagation();">View message -&gt;</a></div></div>' +
        '<span class="inbox-row-actions" onclick="event.preventDefault();event.stopPropagation();"><div style="position:relative;">' +
        '<button type="button" onclick="toggleActionsMenu(' + comm.id + ')" style="background:none;border:none;cursor:pointer;padding:4px 8px;color:var(--charcoal-grey);font-size:18px;" title="Actions">⋮</button>' +
        '<div id="actions-menu-' + comm.id + '" style="display:none;position:absolute;right:0;top:100%;background:white;border:1px solid var(--border-color);border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1);z-index:100;min-width:150px;margin-top:4px;">' +
        whatsappReply + triageAccept + triageRevert + triageSnooze + triageReassign +
        '<button type="button" onclick="event.stopPropagation();performAction(' + comm.id + ',\'' + markAction + '\')" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:var(--charcoal-grey);font-size:14px;">' + markLabel + '</button>' +
        '<button type="button" onclick="event.stopPropagation();performAction(' + comm.id + ',\'' + archiveAction + '\')" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:var(--charcoal-grey);font-size:14px;">' + archiveLabel + '</button>' +
        '<button type="button" onclick="event.stopPropagation();performAction(' + comm.id + ',\'delete\')" style="display:block;width:100%;text-align:left;padding:var(--spacing-sm);border:none;background:none;cursor:pointer;color:#dc3545;font-size:14px;">Delete</button></div></div></span></div></div>';
}

function syncGuidedDemoInboxTarget() {
    const card = document.querySelector('[data-guided-demo-inbox-card]');
    if (!card) return;
    const replyTarget = card.querySelector('[data-guided-demo-target="inbox-founder-thread"]');
    if (replyTarget) {
        card.removeAttribute('data-guided-demo-target');
    } else {
        card.setAttribute('data-guided-demo-target', 'inbox-founder-thread');
    }
}

function renderInboxContent(data, status, channel, search, includeBodySearch, contactId, triagePriority, triageStatus, ownerScope) {
    const container = document.getElementById('inbox-list-container');
    const comms = data.communications || [];
    const effectiveOwnerScope = data.owner_scope || ownerScope || 'mine_unassigned';
    const total = data.total || 0;
    const page = data.page || 1;
    const totalPages = data.total_pages || 1;
    const limit = data.limit || 30;
    const offset = data.offset || 0;
    const params = (p) => {
        const q = new URLSearchParams();
        if (search) q.set('search', search);
        if (includeBodySearch) q.set('include_body_search', '1');
        if (channel) q.set('channel', channel);
        if (status) q.set('status', status);
        if (effectiveOwnerScope) q.set('owner_scope', effectiveOwnerScope);
        if (contactId) q.set('contact_id', contactId);
        if (triagePriority) q.set('triage_priority', triagePriority);
        if (triageStatus) q.set('triage_status', triageStatus);
        q.set('page', p);
        return '?' + q.toString();
    };
    syncContactOptions(data.contacts || [], contactId);
    syncChannelStats(data.channel_stats || {}, data.unread_stats || {});
    const ownerScopeSelect = document.getElementById('owner_scope');
    if (ownerScopeSelect) {
        ownerScopeSelect.value = effectiveOwnerScope;
    }
    if (comms.length === 0) {
        let emptyHtml = '<div class="empty-state"><p>No communications found.</p>';
        if (status === 'unread') emptyHtml += '<p style="font-size:0.875rem;">All caught up! No unread messages.</p>';
        if (channel === 'whatsapp') emptyHtml += '<p style="font-size:0.875rem;margin-top:0.5rem;color:var(--charcoal-grey);">WhatsApp messages arrive via webhook. If you sent a message and do not see it here, ensure the webhook is verified in Meta Business Manager and subscribed to <strong>messages</strong>.</p><a href="workspace_skills.php?module=whatsapp#setup" style="font-size:0.875rem;color:var(--accent-blue);margin-top:0.5rem;display:inline-block;">Check WhatsApp setup</a>';
        emptyHtml += '</div>';
        container.innerHTML = emptyHtml;
    } else {
        let paginationHtml = '';
        if (totalPages > 1) {
            paginationHtml = '<div class="pagination"><div class="pagination-info">Showing ' + (offset + 1) + '-' + Math.min(offset + limit, total) + ' of ' + total + ' communications</div><div class="pagination-controls">';
            if (page > 1) paginationHtml += '<a href="' + params(page - 1) + '" class="pagination-link" data-page="' + (page - 1) + '">Previous</a>';
            for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) paginationHtml += '<a href="' + params(i) + '" class="pagination-link' + (i === page ? ' active' : '') + '" data-page="' + i + '">' + i + '</a>';
            if (page < totalPages) paginationHtml += '<a href="' + params(page + 1) + '" class="pagination-link" data-page="' + (page + 1) + '">Next</a>';
            paginationHtml += '</div></div>';
        }
        const listHtml = comms.map(c => renderCommRow(c, effectiveOwnerScope)).join('');
        container.innerHTML = '<div style="padding:var(--spacing-sm) var(--spacing-md) var(--spacing-sm) 0;border-bottom:2px solid var(--border-color);background:#f9f9f9;display:flex;align-items:center;gap:0.5rem;">' +
            '<input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()" style="width:18px;height:18px;cursor:pointer;"><label for="select-all-checkbox" style="cursor:pointer;color:var(--charcoal-grey);font-size:14px;font-weight:500;margin:0;">Select All</label></div>' +
            '<div class="inbox-list">' + listHtml + '</div>' + paginationHtml;
    }
    syncGuidedDemoInboxTarget();
}

let filterAbortController = null;
let currentInboxPage = <?php echo (int) $page; ?>;
let isFetchingEmails = false;
let emailFetchDisabled = false;
let lastEmailFetchAt = 0;
const INBOX_REFRESH_INTERVAL_MS = 60000;
const EMAIL_FETCH_INTERVAL_MS = 300000;

function setInboxSyncStatus(message, state) {
    const status = document.getElementById('inbox-sync-status');
    if (!status) return;
    status.textContent = message || '';
    status.classList.remove('is-error', 'is-success');
    if (state === 'error') {
        status.classList.add('is-error');
    } else if (state === 'success') {
        status.classList.add('is-success');
    }
}

function clearInboxSyncStatusSoon(delay) {
    window.setTimeout(function() {
        setInboxSyncStatus('');
    }, delay || 4000);
}

function getSelectedCommunicationCount() {
    return document.querySelectorAll('.comm-checkbox:checked').length;
}

async function loadInboxList(options) {
    options = Object.assign({
        page: currentInboxPage || 1,
        background: false,
        updateHistory: true,
        showLoading: true,
        suppressStatus: false
    }, options || {});

    if (options.background && getSelectedCommunicationCount() > 0) {
        return false;
    }

    const params = buildFilterParams();
    params.set('page', String(options.page || 1));
    params.set('limit', '30');
    const browserParams = new URLSearchParams(params);
    browserParams.delete('list');
    const container = document.getElementById('inbox-list-container');
    const btn = document.getElementById('filter-btn');
    if (!container) return false;

    if (options.background && filterAbortController) {
        return false;
    }

    if (filterAbortController) filterAbortController.abort();
    const controller = new AbortController();
    filterAbortController = controller;

    if (!options.background && options.showLoading) {
        container.style.opacity = '0.6';
        container.style.pointerEvents = 'none';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...'; }
    }
    try {
        const requestOptions = { signal: controller.signal, headers: {} };
        if (options.background) {
            requestOptions.headers['X-CRM-Session-Passive'] = '1';
        }
        const res = await fetch('../api/inbox.php?' + params.toString(), requestOptions);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Request failed');
        const form = document.getElementById('inbox-filter-form');
        renderInboxContent(
            data,
            form.querySelector('#status').value,
            form.querySelector('#channel').value,
            form.querySelector('#search').value.trim(),
            form.querySelector('#include_body_search').checked,
            form.querySelector('#contact_id').value,
            form.querySelector('#triage_priority').value,
            form.querySelector('#triage_status').value,
            form.querySelector('#owner_scope').value
        );
        currentInboxPage = Number(data.page || options.page || 1);
        if (!options.background && options.updateHistory) {
            history.replaceState(null, '', 'inbox.php?' + browserParams.toString());
        }
        if (options.background && !options.suppressStatus) {
            setInboxSyncStatus('Inbox updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), 'success');
            clearInboxSyncStatusSoon(2500);
        }
        return true;
    } catch (err) {
        if (err.name !== 'AbortError') {
            if (options.background && !options.suppressStatus) {
                setInboxSyncStatus('Inbox refresh failed.', 'error');
                clearInboxSyncStatusSoon(5000);
            } else if (!options.background) {
                document.getElementById('inbox-list-container').innerHTML = '<div class="empty-state"><p style="color:#dc3545;">Failed to load: ' + (err.message || 'Unknown error') + '</p></div>';
            }
        }
        return false;
    } finally {
        if (filterAbortController === controller) {
            filterAbortController = null;
        }
        if (!options.background && options.showLoading) {
            container.style.opacity = '1';
            container.style.pointerEvents = '';
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-filter"></i> Filter'; }
        }
    }
}

async function applyFilterAsync(page) {
    return loadInboxList({ page: page || 1, background: false, updateHistory: true, showLoading: true });
}

window.refreshInboxListAsync = function(options) {
    options = Object.assign({
        page: 1,
        background: true,
        updateHistory: false,
        showLoading: false,
        suppressStatus: true
    }, options || {});
    return loadInboxList(options);
};

window.addEventListener('protected-demo:scene', function(event) {
    if (!window.protectedDemoClientState || !window.protectedDemoClientState.is_protected_demo) return;
    const detail = event.detail || {};
    const payload = detail.payload || {};
    const sceneKey = String(detail.scene_key || payload.scene_key || payload.demo_event_key || '');
    const messageScene = [
        'inbox_message_sequence_started',
        'whatsapp_lead_received',
        'email_inquiry_received',
        'procurement_followup_received',
        'riverside_thread_auto_opened'
    ].includes(sceneKey);
    if (!messageScene) return;

    if (sceneKey === 'inbox_message_sequence_started') {
        setInboxSyncStatus('Listening for Riverside signals...', '');
    } else if (sceneKey === 'whatsapp_lead_received') {
        setInboxSyncStatus('WhatsApp lead arrived from Amina.', 'success');
    } else if (sceneKey === 'email_inquiry_received') {
        setInboxSyncStatus('Related rollout email arrived.', 'success');
    } else if (sceneKey === 'procurement_followup_received') {
        setInboxSyncStatus('Procurement follow-up arrived. Riverside is ready to open.', 'success');
    }

    window.refreshInboxListAsync({ background: true, suppressStatus: true }).then(function() {
        document.querySelectorAll('[data-demo-riverside-thread="1"]').forEach(function(row, index) {
            row.classList.add('protected-demo-scene-new');
            window.setTimeout(function() {
                row.classList.remove('protected-demo-scene-new');
            }, 2600 + (index * 180));
        });
    });
});

document.getElementById('inbox-filter-form').addEventListener('submit', function(e) {
    e.preventDefault();
    applyFilterAsync(1);
});
document.getElementById('filter-btn').addEventListener('click', function() { applyFilterAsync(1); });

// Auto-apply filter when dropdowns change (no need to click Filter)
['#channel', '#status', '#owner_scope', '#contact_id', '#triage_priority', '#triage_status', '#include_body_search'].forEach(function(sel) {
    var el = document.querySelector(sel);
    if (el) el.addEventListener('change', function() { applyFilterAsync(1); });
});

// Debounced auto-filter for search input (400ms after typing stops)
(function() {
    var searchEl = document.getElementById('search');
    var debounceTimer;
    if (searchEl) {
        searchEl.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                const includeBody = document.getElementById('include_body_search');
                if (includeBody && includeBody.checked) {
                    return;
                }
                applyFilterAsync(1);
            }, 400);
        });
    }
})();

// Delegate inbox clicks (works for initial page and after async updates)
document.getElementById('inbox-list-container').addEventListener('click', function(e) {
    const link = e.target.closest('.pagination-link');
    if (link && link.dataset.page) {
        e.preventDefault();
        applyFilterAsync(parseInt(link.dataset.page, 10));
        return;
    }
    const row = e.target.closest('.inbox-row-link[data-conversation-url]');
    if (row) {
        openConversationFromRow(e, row);
    }
});

function toggleActionsMenu(id) {
    // Close any open menu
    if (openMenuId !== null) {
        document.getElementById('actions-menu-' + openMenuId).style.display = 'none';
    }
    
    // Toggle current menu
    if (openMenuId === id) {
        openMenuId = null;
    } else {
        const menu = document.getElementById('actions-menu-' + id);
        menu.style.display = 'block';
        openMenuId = id;
    }
}

// Close menu when clicking outside
document.addEventListener('click', function(event) {
    if (openMenuId !== null && !event.target.closest('#actions-menu-' + openMenuId) && !event.target.closest('button[onclick*="toggleActionsMenu"]')) {
        document.getElementById('actions-menu-' + openMenuId).style.display = 'none';
        openMenuId = null;
    }
});

function performAction(id, action) {
    const actions = {
        'mark_read': 'Mark as Read',
        'mark_unread': 'Mark as Unread',
        'archive': 'Archive',
        'unarchive': 'Unarchive',
        'delete': 'Delete'
    };
    
    if (action === 'delete' && !confirm('Are you sure you want to delete this communication?')) {
        return;
    }
    
    fetch('../api/inbox.php?action=' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id: id, owner_scope: getCurrentOwnerScope() })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function triageAction(communicationId, action, extraData) {
    if (!communicationId || !action) return;
    let payload = Object.assign({ action: action, communication_id: communicationId }, extraData || {});
    if (action === 'snooze_task') {
        const val = prompt('Snooze by how many hours?', '24');
        if (val === null) return;
        const hours = parseInt(val, 10);
        if (!hours || hours < 1) {
            alert('Invalid hours value.');
            return;
        }
        payload.hours = hours;
    }

    fetch('../api/inbox/triage_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({}, payload, { owner_scope: getCurrentOwnerScope() }))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
            return;
        }
        alert('Triage action failed: ' + (data.error || 'Unknown error'));
    })
    .catch(error => {
        console.error('Triage action error:', error);
        alert('Triage action request failed.');
    });
}

function triageReassignPrompt(communicationId) {
    const value = prompt('Reassign to owner user ID:');
    if (value === null) return;
    const ownerId = parseInt(value, 10);
    if (!ownerId || ownerId < 1) {
        alert('Invalid owner ID.');
        return;
    }
    triageAction(communicationId, 'reassign_owner', { owner_id: ownerId });
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.comm-checkbox:checked');
    const toolbar = document.getElementById('bulk-actions-toolbar');
    const countSpan = document.getElementById('selected-count');
    
    if (checkboxes.length > 0) {
        toolbar.style.display = 'block';
        countSpan.textContent = checkboxes.length + ' selected';
    } else {
        toolbar.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.comm-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('select-all-checkbox').checked = false;
    updateBulkActions();
}

function toggleSelectAll() {
    const selectAll = document.getElementById('select-all-checkbox').checked;
    document.querySelectorAll('.comm-checkbox').forEach(cb => cb.checked = selectAll);
    updateBulkActions();
}

function bulkAction(action) {
    const checkboxes = document.querySelectorAll('.comm-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    if (ids.length === 0) {
        alert('Please select at least one communication.');
        return;
    }
    
    if (action === 'delete' && !confirm('Are you sure you want to delete ' + ids.length + ' communication(s)?')) {
        return;
    }
    
    fetch('../api/inbox.php?action=' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ ids: ids, owner_scope: getCurrentOwnerScope() })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

async function fetchEmailsInBackground(options) {
    options = Object.assign({ manual: false }, options || {});
    if (isFetchingEmails || emailFetchDisabled) {
        return false;
    }

    isFetchingEmails = true;
    lastEmailFetchAt = Date.now();
    setInboxSyncStatus('Checking email...', '');

    try {
        const response = await fetch('../api/fetch_emails.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>',
                ...(options.manual ? {} : { 'X-CRM-Session-Passive': '1' })
            }
        });

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned invalid response');
        }

        const result = await response.json();
        if (!response.ok || !result.success) {
            if (response.status === 400 || response.status === 401 || response.status === 403) {
                emailFetchDisabled = true;
            }
            setInboxSyncStatus('Email sync unavailable.', 'error');
            clearInboxSyncStatusSoon(5000);
            return false;
        }

        const changedCount = Number(result.processed || 0)
            + Number(result.synced_sent || 0)
            + Number(result.auto_created || 0);

        setInboxSyncStatus(changedCount > 0 ? 'Email sync found updates.' : 'Email sync checked.', changedCount > 0 ? 'success' : '');
        clearInboxSyncStatusSoon(changedCount > 0 ? 4000 : 2500);

        if (changedCount > 0) {
            await loadInboxList({ page: currentInboxPage, background: true, updateHistory: false, showLoading: false });
        }

        return true;
    } catch (error) {
        console.error('Fetch Emails Error:', error);
        setInboxSyncStatus('Email sync failed.', 'error');
        clearInboxSyncStatusSoon(5000);
        return false;
    } finally {
        isFetchingEmails = false;
    }
}

function scheduleInboxAutomation() {
    window.setInterval(function() {
        if (document.hidden) return;
        loadInboxList({ page: currentInboxPage, background: true, updateHistory: false, showLoading: false });
    }, INBOX_REFRESH_INTERVAL_MS);

    window.setInterval(function() {
        if (document.hidden) return;
        fetchEmailsInBackground({ manual: false });
    }, EMAIL_FETCH_INTERVAL_MS);

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) return;
        loadInboxList({ page: currentInboxPage, background: true, updateHistory: false, showLoading: false });
        if (!lastEmailFetchAt || (Date.now() - lastEmailFetchAt) >= EMAIL_FETCH_INTERVAL_MS) {
            fetchEmailsInBackground({ manual: false });
        }
    });
}

function deferInboxAutomationStart() {
    let started = false;

    function startAutomation() {
        if (started) {
            return;
        }
        started = true;
        scheduleInboxAutomation();
    }

    function scheduleAfterLoad() {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(startAutomation, { timeout: 8000 });
            return;
        }

        window.setTimeout(startAutomation, 2500);
    }

    if (document.readyState === 'complete') {
        scheduleAfterLoad();
        return;
    }

    window.addEventListener('load', scheduleAfterLoad, { once: true });
}

deferInboxAutomationStart();
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_INBOX, 'How to use Inbox', $inboxGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
