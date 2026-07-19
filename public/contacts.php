<?php
/**
 * Contacts List Page
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
use CRM\Modules\Contacts;
use CRM\Modules\Tags;
use CRM\Modules\UserPreferences;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceScopeService;
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

$contactsModule = new Contacts();
$tagsModule = new Tags();
$contactIntelligenceService = new ContactIntelligenceService();
$workspaceScope = new WorkspaceScopeService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
if ($userId > 0) {
    (new UserPreferences())->markContactsPageOpened($userId);
}
$canViewAllContacts = Authorization::can('contacts.view_all', $user);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isProtectedDemoContacts = false;
try {
    $isProtectedDemoContacts = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoContacts = false;
}
$runtimeInstaller = new WorkspaceSkillInstallService();
$runtimeCatalog = new WorkspaceSkillCatalogService();
$isSuperAdmin = Authorization::isSuperAdmin($user);
$canUseSmsChannel = !$runtimeCatalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)
    && ($isSuperAdmin || $runtimeInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL));
$canUseWhatsAppChannel = (new WorkspaceCommunicationGateService())->isChannelRuntimeReady($workspaceId, 'whatsapp', $user);
Session::closeWrite();

// Handle search and filters
$search = $_GET['search'] ?? '';
$stage = $_GET['stage'] ?? '';
$tagId = !empty($_GET['tag']) ? (int) $_GET['tag'] : null;
$ownerScope = (string) ($_GET['owner_scope'] ?? 'mine_unassigned');
if (!in_array($ownerScope, ['mine_unassigned', 'all'], true)) {
    $ownerScope = 'mine_unassigned';
}
if ($ownerScope === 'all' && !$canViewAllContacts) {
    $ownerScope = 'mine_unassigned';
}
$ownerScopeClause = $contactsModule->buildOwnerScopeClause($ownerScope, $userId, '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 200;
$offset = ($page - 1) * $limit;

// Get contacts
if ($tagId) {
    $contacts = $contactsModule->getByTag($tagId, $limit, $offset, $ownerScope, $userId);
    $totalContacts = $contactsModule->countByTag($tagId, $ownerScope, $userId);
} elseif ($search) {
    $contacts = $contactsModule->search($search, $limit, $offset, $ownerScope, $userId);
    $totalContacts = $contactsModule->countSearch($search, $ownerScope, $userId);
} elseif ($stage) {
    $contacts = $contactsModule->getAll($limit, $offset, $stage, $ownerScope, $userId);
    $totalContacts = $contactsModule->countAll($stage, $ownerScope, $userId);
} else {
    $contacts = $contactsModule->getAll($limit, $offset, null, $ownerScope, $userId);
    $totalContacts = $contactsModule->countAll(null, $ownerScope, $userId);
}

$totalPages = ceil($totalContacts / $limit);

// Get stage counts for filter
$workspaceClause = $workspaceScope->workspaceClause();
$stageCountSql = "SELECT stage, COUNT(*) as count FROM contacts WHERE {$workspaceClause['sql']}";
$stageCountParams = $workspaceClause['params'];
if ($ownerScopeClause['sql'] !== '') {
    $stageCountSql .= " AND " . $ownerScopeClause['sql'];
    $stageCountParams = array_merge($stageCountParams, $ownerScopeClause['params']);
}
$stageCountSql .= " GROUP BY stage";
$stageCounts = Database::query($stageCountSql, $stageCountParams);
$stageStats = [];
foreach ($stageCounts as $stat) {
    $stageStats[$stat['stage']] = $stat['count'];
}

// Get all tags for filter
$allTags = $tagsModule->getAll();

$users = (new ContactAssignmentAccessService())->getAssignableUsers($workspaceId);

function buildContactListQualitySignals(array $contact, ContactIntelligenceService $service, ?array $storedIntelligence = null): array
{
    $intel = $storedIntelligence ?? $service->getStoredOrCompute((int) ($contact['id'] ?? 0)) ?? [];
    if ($intel === []) {
        $missing = [];
        foreach (['phone', 'company', 'job_title', 'location'] as $field) {
            if (empty($contact[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'health_score' => 0,
            'health_band' => 'unknown',
            'flags' => count($missing) >= 2 ? [['label' => 'Missing fields', 'class' => 'missing']] : [],
            'missing_critical_fields' => $missing,
            'duplicate_count' => 0,
        ];
    }

    $quality = $intel['data_quality'] ?? [];
    $health = $intel['relationship_health'] ?? [];
    $flags = [];

    if (!empty($quality['is_stale'])) {
        $flags[] = ['label' => 'Stale', 'class' => 'stale'];
    }
    if (!empty($quality['is_incomplete'])) {
        $flags[] = ['label' => 'Incomplete', 'class' => 'incomplete'];
    }
    $duplicateCount = count($quality['likely_duplicates'] ?? []);
    if ($duplicateCount > 0) {
        $flags[] = [
            'label' => $duplicateCount . ' duplicate' . ($duplicateCount === 1 ? '' : 's'),
            'class' => 'duplicate',
            'title' => 'Likely duplicate candidates found from matching contact details. Open the Review Queue to inspect and merge.',
        ];
    }
    if (!empty($quality['missing_critical_fields'])) {
        $flags[] = ['label' => 'Missing fields', 'class' => 'missing'];
    }

    return [
        'health_score' => (int) ($health['score'] ?? 0),
        'health_band' => (string) ($health['band'] ?? 'unknown'),
        'flags' => $flags,
        'missing_critical_fields' => array_values($quality['missing_critical_fields'] ?? []),
        'duplicate_count' => $duplicateCount,
    ];
}

$contactIdsOnPage = array_map(static fn (array $contact): int => (int) ($contact['id'] ?? 0), $contacts);
$contactTagsById = $tagsModule->getTagsForEntities('contact', $contactIdsOnPage);
$contactIntelligenceById = $contactIntelligenceService->getStoredForContactIds($contactIdsOnPage);

foreach ($contacts as &$contact) {
    $contactId = (int) ($contact['id'] ?? 0);
    $contact['_tags'] = $contactTagsById[$contactId] ?? [];
    $contact['_quality_signals'] = buildContactListQualitySignals($contact, $contactIntelligenceService, $contactIntelligenceById[$contactId] ?? []);
    if ($isProtectedDemoContacts) {
        $contact['_quality_signals'] = [
            'health_score' => max(88, (int) ($contact['_quality_signals']['health_score'] ?? 0)),
            'health_band' => 'healthy',
            'flags' => [],
            'missing_critical_fields' => [],
            'duplicate_count' => 0,
        ];
    }
}
unset($contact);

$contactListTitle = 'Contacts';
$contactListDescription = 'Manage leads, customers, and onboarding imports for your Clarity CRM workflow.';
$newContactLabel = 'New Contact';
if ($stage === 'new') {
    $contactListTitle = 'Leads';
    $contactListDescription = 'Review new leads, qualify good fits, and move them into the next CRM stage.';
    $newContactLabel = 'New Lead';
} elseif ($stage === 'won') {
    $contactListTitle = 'Customers';
    $contactListDescription = 'Review won customer relationships and keep follow-up work organized.';
    $newContactLabel = 'New Customer';
}
if ($isProtectedDemoContacts) {
    $contactListDescription = 'Inspect the Riverside contact intelligence layer: private messages, draft context, tasks, and response signals stay scoped to this demo session.';
}

$pageTitle = $contactListTitle . ' - ' . brandProductName();
$contactsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_CONTACTS);
$contactsExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $contactsExperienceMode,
    'surface' => 'contacts',
    'current_page' => 'contacts.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'contacts' => $contacts,
    'total_count' => $totalContacts,
]);
$contactToolbarActionsHtml = $isProtectedDemoContacts
    ? '<div class="bulk-actions-right"><span class="contact-toolbar-demo-chip"><i class="fas fa-lock"></i> Private demo intelligence</span></div>'
    : '<div class="bulk-actions-right">'
        . '<a href="contact_review_queue.php" class="btn-premium-secondary contact-toolbar-action">'
        . '<i class="fas fa-shield-alt"></i>'
        . 'Review Queue'
        . '</a>'
        . '<a href="contacts_create.php" class="btn-premium-primary contact-toolbar-action">'
        . '<i class="fas fa-plus"></i>'
        . htmlspecialchars($newContactLabel, ENT_QUOTES, 'UTF-8')
        . '</a>'
        . '</div>';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/contacts-premium.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php echo PageGuideVideoUi::assets(); ?>
<style>
/* Contacts bulk actions: keep functionality, improve presentation */
.contacts-table-card .bulk-actions-bar {
    display: block;
    margin: 1rem;
    margin-bottom: 0;
    padding: 0.85rem 1rem;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
}

.contacts-table-card .bulk-actions-bar.active {
    border-color: #c7d2fe;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.12);
}

.contacts-table-card .bulk-actions-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.contacts-table-card .bulk-actions-left {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.contacts-table-card .bulk-actions-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-left: auto;
}

.contacts-table-card .selected-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 92px;
    padding: 0.4rem 0.7rem;
    border-radius: 999px;
    font-size: 0.8125rem;
    font-weight: 700;
    color: #4338ca;
    background: #eef2ff;
    border: 1px solid #c7d2fe;
}

.contacts-table-card .bulk-action-select {
    min-width: 220px;
    height: 38px;
    padding: 0 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #0f172a;
    font-size: 0.875rem;
}

.contacts-table-card #apply-bulk-action,
.contacts-table-card #clear-selection {
    height: 38px;
    padding: 0 0.95rem;
}

.contacts-table-card .contact-toolbar-action {
    min-height: 38px;
    padding: 0 0.95rem;
    white-space: nowrap;
}

.contacts-table-card .contact-toolbar-demo-chip,
.contacts-demo-readonly-strip,
.contacts-demo-header-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    min-height: 38px;
    padding: 0 0.95rem;
    border-radius: 999px;
    border: 1px solid #bfdbfe;
    background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
    color: #1e3a8a;
    font-size: 0.82rem;
    font-weight: 800;
}

.contacts-demo-readonly-strip {
    justify-content: space-between;
    width: 100%;
    border-radius: 12px;
    padding: 0.8rem 1rem;
    min-height: 0;
}

.contacts-demo-context-strip {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    margin-bottom: 1rem;
    padding: 1rem;
    border: 1px solid #dbeafe;
    border-radius: 12px;
    background: linear-gradient(135deg, #eff6ff 0%, #ffffff 48%, #f0fdf4 100%);
    box-shadow: 0 16px 36px rgba(37, 99, 235, 0.08);
}

.contacts-demo-context-strip strong {
    display: block;
    color: #0f172a;
    font-size: 0.98rem;
}

.contacts-demo-context-strip span {
    display: block;
    margin-top: 0.25rem;
    color: #475569;
    font-size: 0.86rem;
    line-height: 1.45;
}

.contacts-demo-context-strip a {
    white-space: nowrap;
    text-decoration: none;
}

.contacts-table-card #apply-bulk-action:disabled,
.contacts-table-card #clear-selection:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 768px) {
    .contacts-table-card .bulk-actions-bar {
        margin: 0.75rem;
        margin-bottom: 0;
        padding: 0.75rem;
    }

    .contacts-table-card .bulk-action-select {
        min-width: 0;
        width: 100%;
    }

    .contacts-table-card .bulk-actions-left,
    .contacts-table-card .bulk-actions-right {
        width: 100%;
    }

    .contacts-table-card .bulk-actions-right {
        justify-content: flex-start;
        margin-left: 0;
    }

    .contacts-demo-context-strip {
        grid-template-columns: 1fr;
    }

    .contacts-demo-context-strip a {
        width: 100%;
        justify-content: center;
    }
}

/* Toast feedback */
.contacts-toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9999;
    min-width: 260px;
    max-width: 420px;
    padding: 12px 14px;
    border-radius: 10px;
    color: #fff;
    font-size: 14px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    opacity: 0;
    transform: translateY(8px);
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.contacts-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.contacts-toast.success { background: #16a34a; }
.contacts-toast.warning { background: #d97706; }
.contacts-toast.error { background: #dc2626; }

.contact-quality-cell {
    min-width: 210px;
}

.contact-quality-stack {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.contact-quality-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.contact-quality-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.28rem 0.62rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    border: 1px solid transparent;
    white-space: nowrap;
}

.contact-quality-badge.stale {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.contact-quality-badge.incomplete,
.contact-quality-badge.missing {
    background: #fff7ed;
    border-color: #fed7aa;
    color: #c2410c;
}

.contact-quality-badge.duplicate {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #4338ca;
}

.contact-health-inline {
    font-size: 0.75rem;
    color: #475569;
}

.contact-quality-note {
    font-size: 0.72rem;
    color: #64748b;
    line-height: 1.4;
}

.contacts-table-card {
    overflow: visible;
}

#contacts-list-container {
    position: relative;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

#contacts-list-container::-webkit-scrollbar {
    display: none;
}

#contacts-list-container.contacts-draggable {
    cursor: grab;
}

#contacts-list-container.contacts-dragging {
    cursor: grabbing;
}

#contacts-list-container.contacts-dragging,
#contacts-list-container.contacts-dragging * {
    user-select: none;
}

#contacts-list-container.contacts-overflowing::before,
#contacts-list-container.contacts-overflowing::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 28px;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.18s ease;
    z-index: 2;
}

#contacts-list-container.contacts-overflowing::before {
    left: 0;
    background: linear-gradient(to right, rgba(248, 250, 252, 0.96), rgba(248, 250, 252, 0));
}

#contacts-list-container.contacts-overflowing::after {
    right: 0;
    background: linear-gradient(to left, rgba(248, 250, 252, 0.96), rgba(248, 250, 252, 0));
}

#contacts-list-container.contacts-can-scroll-left::before,
#contacts-list-container.contacts-can-scroll-right::after {
    opacity: 1;
}

.contacts-table-card .premium-table {
    min-width: 1120px;
}

.contacts-table-card .premium-table th:last-child,
.contacts-table-card .premium-table td:last-child {
    min-width: 120px;
    white-space: nowrap;
    padding-right: 1.5rem;
}

.contacts-table-card .premium-table td:last-child {
    text-align: right;
}

@media (max-width: 1200px) {
    .contacts-table-card .premium-table {
        min-width: 1180px;
    }
}

.contacts-guide-stage-action {
    margin-left: auto;
    min-height: 38px;
}

@media (max-width: 760px) {
    .contacts-guide-stage-action {
        margin-left: 0;
    }
}
</style>
<script>
var CONTACT_USERS = <?php echo json_encode($users); ?>;
var CONTACT_TOOLBAR_ACTIONS_HTML = <?php echo json_encode($contactToolbarActionsHtml); ?>;
var CONTACTS_IS_PROTECTED_DEMO = <?php echo $isProtectedDemoContacts ? 'true' : 'false'; ?>;
</script>

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($contactListTitle); ?></h1>
                <p><?php echo htmlspecialchars($contactListDescription); ?></p>
            </div>
            <div class="page-header-actions" data-guided-demo-target="contacts-workday-actions">
                <?php if ($isProtectedDemoContacts): ?>
                <span class="contacts-demo-header-chip">
                    <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                    Riverside intelligence ready
                </span>
                <?php else: ?>
                <a href="contacts_import.php" class="btn-premium-secondary">
                    <i class="fas fa-upload"></i>
                    Import CSV
                </a>
                <a href="contacts_export.php" class="btn-premium-secondary">
                    <i class="fas fa-download"></i>
                    Export CSV
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($isProtectedDemoContacts): ?>
            <section class="contacts-demo-context-strip" aria-label="Riverside contact intelligence">
                <div>
                    <strong>Amina and Riverside are already connected.</strong>
                    <span>The contact record ties together the WhatsApp lead, rollout email, assistant draft, follow-up task, and response-time target inside this private demo session.</span>
                </div>
                <a href="inbox.php" class="btn-premium-primary">
                    <i class="fas fa-inbox" aria-hidden="true"></i>
                    Open thread
                </a>
            </section>
        <?php else: ?>
            <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="filters-card">
            <form method="GET" action="" class="filters-form" id="contacts-filter-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search by name, email, or company..."
                    >
                </div>
                <div class="filter-group">
                    <label for="stage">Stage</label>
                    <select id="stage" name="stage">
                        <option value="">All Stages</option>
                        <option value="new" <?php echo $stage === 'new' ? 'selected' : ''; ?>>New</option>
                        <option value="contacted" <?php echo $stage === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                        <option value="qualified" <?php echo $stage === 'qualified' ? 'selected' : ''; ?>>Qualified</option>
                        <option value="proposal" <?php echo $stage === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
                        <option value="negotiation" <?php echo $stage === 'negotiation' ? 'selected' : ''; ?>>Negotiation</option>
                        <option value="won" <?php echo $stage === 'won' ? 'selected' : ''; ?>>Won</option>
                        <option value="lost" <?php echo $stage === 'lost' ? 'selected' : ''; ?>>Lost</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="tag">Tag</label>
                    <select id="tag" name="tag">
                        <option value="">All Tags</option>
                        <?php foreach ($allTags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>" <?php echo $tagId === $tag['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tag['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="owner_scope">View</label>
                    <select id="owner_scope" name="owner_scope">
                        <option value="mine_unassigned" <?php echo $ownerScope === 'mine_unassigned' ? 'selected' : ''; ?>>My Contacts + Unassigned</option>
                        <?php if ($canViewAllContacts): ?>
                            <option value="all" <?php echo $ownerScope === 'all' ? 'selected' : ''; ?>>All Contacts</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary" id="filter-btn">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search || $stage || $tagId || $ownerScope !== 'mine_unassigned'): ?>
                        <a href="contacts.php" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Stage Stats -->
        <div class="stage-stats">
            <?php 
            $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
            $stageLabels = ['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'];
            foreach ($stages as $index => $stageName): 
                $count = $stageStats[$stageName] ?? 0;
                $isActive = $stage === $stageName;
            ?>
                <a href="?stage=<?php echo $stageName; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>"
                   class="stage-stat <?php echo $isActive ? 'active' : ''; ?>"
                   data-stage="<?php echo htmlspecialchars($stageName); ?>"
                   data-stage-label="<?php echo htmlspecialchars($stageLabels[$index]); ?>">
                    <?php echo $stageLabels[$index]; ?> (<?php echo $count; ?>)
                </a>
            <?php endforeach; ?>
            <?php if ($contactsGuideVideoUrl !== ''): ?>
                <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_CONTACTS, 'Contacts page guide', 'contacts-guide-stage-action'); ?>
            <?php endif; ?>
        </div>

        <!-- Contacts Table -->
        <div class="contacts-table-card" data-guided-demo-target="contacts-founder-list">
            <div id="contacts-list-container">
            <?php if (empty($contacts)): ?>
                <div class="empty-state">
                    <p>No contacts found.</p>
                    <p style="color:#64748b;">Import your first leads or create a sample inquiry for the onboarding walkthrough.</p>
                    <a href="contacts_create.php">
                        Create your first contact →
                    </a>
                </div>
            <?php else: ?>
                <!-- Bulk Actions Bar -->
                <div id="bulk-actions-bar" class="bulk-actions-bar">
                    <div class="bulk-actions-content">
                        <?php if ($isProtectedDemoContacts): ?>
                        <div class="contacts-demo-readonly-strip">
                            <span><i class="fas fa-lock" aria-hidden="true"></i> Demo-safe view: inspect contacts and intelligence without imports, exports, bulk sends, or deletes.</span>
                            <?php echo $contactToolbarActionsHtml; ?>
                        </div>
                        <?php else: ?>
                        <div class="bulk-actions-left">
                            <span id="selected-count" class="selected-count">0 selected</span>
                            <select id="bulk-action" class="bulk-action-select" aria-label="Bulk action">
                                <option value="">Bulk Actions...</option>
                                <option value="bulk_message">Bulk Message</option>
                                <option value="bulk_enrich">Bulk Enrichment</option>
                                <option value="bulk_email_verify">Bulk Email Verify</option>
                                <option value="update_stage">Update Stage</option>
                                <option value="update_assigned">Update Assigned To</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button id="apply-bulk-action" class="btn-premium-primary" disabled>Apply</button>
                            <button id="clear-selection" class="btn-premium-secondary" disabled>Clear</button>
                        </div>
                        <?php echo $contactToolbarActionsHtml; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!$isProtectedDemoContacts): ?>
                <!-- Bulk Message Modal -->
                <div id="bulk-message-modal" class="bulk-update-modal">
                    <div class="bulk-update-content">
                        <h2>Bulk Message</h2>
                        <p style="margin-bottom: 1rem; color: var(--charcoal-grey);">Choose a channel to send your message:</p>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <a id="bulk-message-email" href="bulk_email.php" class="btn-premium-primary" style="text-decoration: none; justify-content: center;">
                                <i class="fas fa-envelope"></i> Bulk Email
                            </a>
                            <?php if ($canUseSmsChannel): ?>
                                <a id="bulk-message-sms" href="bulk_sms.php" class="btn-premium-primary" style="text-decoration: none; justify-content: center; background: #10b981;">
                                    <i class="fas fa-sms"></i> Bulk SMS
                                </a>
                            <?php endif; ?>
                            <?php if ($canUseWhatsAppChannel): ?>
                                <a id="bulk-message-whatsapp" href="bulk_whatsapp.php" class="btn-premium-primary" style="text-decoration: none; justify-content: center; background: #25D366;">
                                    <i class="fab fa-whatsapp"></i> Bulk WhatsApp
                                </a>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="cancel-bulk-message" class="btn-premium-secondary" style="margin-top: 1rem; width: 100%;">Cancel</button>
                    </div>
                </div>
                
                <!-- Bulk Update Modal -->
                <div id="bulk-update-modal" class="bulk-update-modal">
                    <div class="bulk-update-content">
                        <h2>Bulk Update Contacts</h2>
                        <form id="bulk-update-form" class="bulk-update-form">
                            <div id="bulk-update-fields"></div>
                            <div class="bulk-update-actions">
                                <button type="button" id="cancel-bulk-update" class="btn-premium-secondary">Cancel</button>
                                <button type="submit" class="btn-premium-primary">Update</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <table class="premium-table contacts-list-table">
                    <thead>
                        <tr>
                            <?php if (!$isProtectedDemoContacts): ?>
                            <th>
                                <input type="checkbox" id="select-all" aria-label="Select all contacts" style="cursor: pointer;" onchange="toggleAllContacts(this.checked)">
                            </th>
                            <?php endif; ?>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Company</th>
                            <th>Stage</th>
                            <th>Quality</th>
                            <th style="text-align: center;">Score</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): 
                            $contactTags = $contact['_tags'] ?? [];
                            $qualitySignals = $contact['_quality_signals'] ?? ['flags' => [], 'missing_critical_fields' => [], 'health_band' => 'unknown', 'health_score' => 0];
                            $isRiversideDemoContact = $isProtectedDemoContacts && (
                                stripos((string) ($contact['first_name'] ?? ''), 'Amina') !== false
                                || stripos((string) ($contact['company'] ?? ''), 'Riverside') !== false
                                || stripos((string) ($contact['email'] ?? ''), 'amina.demo') !== false
                                || stripos((string) ($contact['email'] ?? ''), 'amina.otieno@riverside-residence.example') !== false
                            );
                        ?>
                            <tr <?php echo $isRiversideDemoContact ? 'data-demo-riverside-contact="1" data-demo-cue-key="contacts_page_visible"' : ''; ?>>
                                <?php if (!$isProtectedDemoContacts): ?>
                                <td>
                                    <input type="checkbox" class="contact-checkbox" value="<?php echo $contact['id']; ?>" aria-label="Select contact" style="cursor: pointer;" onchange="updateContactsSelection()">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                        <a href="contact_view.php?id=<?php echo $contact['id']; ?>" class="contact-name-link">
                                            <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?>
                                        </a>
                                        <?php if (!empty($contactTags)): ?>
                                            <div class="contact-tags">
                                                <?php foreach (array_slice($contactTags, 0, 3) as $tag): ?>
                                                    <span class="contact-tag" style="--tag-color: <?php echo htmlspecialchars($tag['color']); ?>">
                                                        <?php echo htmlspecialchars($tag['name']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($contactTags) > 3): ?>
                                                    <span style="color: #64748b; font-size: 10px;">+<?php echo count($contactTags) - 3; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($contact['email'] ?? '-'); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($contact['company'] ?? '-'); ?>
                                </td>
                                <td>
                                    <span class="stage-badge">
                                        <?php echo htmlspecialchars($contact['stage']); ?>
                                    </span>
                                </td>
                                <td class="contact-quality-cell">
                                    <div class="contact-quality-stack">
                                        <?php if (!empty($qualitySignals['flags'])): ?>
                                            <div class="contact-quality-badges">
                                                <?php foreach ($qualitySignals['flags'] as $flag): ?>
                                                    <span class="contact-quality-badge <?php echo htmlspecialchars($flag['class']); ?>" title="<?php echo htmlspecialchars((string) ($flag['title'] ?? $flag['label'])); ?>">
                                                        <?php echo htmlspecialchars($flag['label']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="contact-quality-note">No active data-quality issues.</div>
                                        <?php endif; ?>
                                        <div class="contact-health-inline">
                                            Health: <?php echo (int) ($qualitySignals['health_score'] ?? 0); ?>/100
                                            • <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($qualitySignals['health_band'] ?? 'unknown'))); ?>
                                        </div>
                                        <?php if (!empty($qualitySignals['missing_critical_fields'])): ?>
                                            <div class="contact-quality-note">
                                                Missing: <?php echo htmlspecialchars(implode(', ', array_slice($qualitySignals['missing_critical_fields'], 0, 3))); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <?php 
                                    $score = (int) ($contact['lead_score'] ?? 0);
                                    if ($score > 0):
                                        $scoreClass = $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
                                    ?>
                                        <span class="score-badge <?php echo $scoreClass; ?>">
                                            <?php echo $score; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($contact['created_at'])); ?>
                                </td>
                                <td class="contact-actions-cell">
                                    <a href="contact_view.php?id=<?php echo $contact['id']; ?>" class="action-link">View</a>
                                    <?php if (!$isProtectedDemoContacts): ?>
                                    <a href="contact_edit.php?id=<?php echo $contact['id']; ?>" class="action-link-secondary">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalContacts); ?> of <?php echo $totalContacts; ?> contacts
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $stage ? '&stage=' . urlencode($stage) : ''; ?><?php echo $tagId ? '&tag=' . $tagId : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link" data-page="<?php echo $page - 1; ?>">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $stage ? '&stage=' . urlencode($stage) : ''; ?><?php echo $tagId ? '&tag=' . $tagId : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>" data-page="<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $stage ? '&stage=' . urlencode($stage) : ''; ?><?php echo $tagId ? '&tag=' . $tagId : ''; ?>&owner_scope=<?php echo urlencode($ownerScope); ?>" class="pagination-link" data-page="<?php echo $page + 1; ?>">Next</a>
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
// Async contacts filter
function buildContactsFilterParams() {
    const form = document.getElementById('contacts-filter-form');
    if (!form) return new URLSearchParams();
    const params = new URLSearchParams();
    const search = form.querySelector('#search').value.trim();
    const stage = form.querySelector('#stage').value;
    const tag = form.querySelector('#tag').value;
    const ownerScope = form.querySelector('#owner_scope').value;
    if (search) params.set('search', search);
    if (stage) params.set('stage', stage);
    if (tag) params.set('tag', tag);
    if (ownerScope) params.set('owner_scope', ownerScope);
    params.set('list', '1');
    return params;
}

function renderContactRow(contact) {
    const tags = contact._tags || [];
    const quality = contact._quality_signals || {};
    const qualityFlags = Array.isArray(quality.flags) ? quality.flags : [];
    const esc = s => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const tagsHtml = tags.length > 0 ? tags.slice(0, 3).map(t => 
        '<span class="contact-tag" style="--tag-color: ' + (t.color || '#64748b') + '">' + (t.name || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>'
    ).join('') + (tags.length > 3 ? '<span style="color:#64748b;font-size:10px;">+' + (tags.length - 3) + '</span>' : '') : '';
    const name = (contact.first_name || '') + ' ' + (contact.last_name || '');
    const score = parseInt(contact.lead_score || 0);
    const scoreClass = score >= 70 ? 'high' : (score >= 40 ? 'medium' : 'low');
    const scoreHtml = score > 0 ? '<span class="score-badge ' + scoreClass + '">' + score + '</span>' : '<span style="color:#64748b;font-size:12px;">-</span>';
    const qualityBadgesHtml = qualityFlags.length > 0
        ? '<div class="contact-quality-badges">' + qualityFlags.map(flag => '<span class="contact-quality-badge ' + esc(flag.class || '') + '" title="' + esc(flag.title || flag.label || '') + '">' + esc(flag.label || '') + '</span>').join('') + '</div>'
        : '<div class="contact-quality-note">No active data-quality issues.</div>';
    const missingFields = Array.isArray(quality.missing_critical_fields) ? quality.missing_critical_fields : [];
    const qualityMetaHtml = '<div class="contact-health-inline">Health: ' + esc(String(quality.health_score || 0)) + '/100 • ' + esc(String((quality.health_band || "unknown")).replace(/_/g, " ")) + '</div>' +
        (missingFields.length ? '<div class="contact-quality-note">Missing: ' + esc(missingFields.slice(0, 3).join(', ')) + '</div>' : '');
    const created = contact.created_at ? new Date(contact.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '-';
    var selectCell = CONTACTS_IS_PROTECTED_DEMO ? '' : '<td><input type="checkbox" class="contact-checkbox" value="' + contact.id + '" aria-label="Select contact" style="cursor:pointer;" onchange="updateContactsSelection()"></td>';
    var editAction = CONTACTS_IS_PROTECTED_DEMO ? '' : ' <a href="contact_edit.php?id=' + contact.id + '" class="action-link-secondary">Edit</a>';
    var isRiversideDemoContact = CONTACTS_IS_PROTECTED_DEMO && (
        String(contact.first_name || '').toLowerCase().includes('amina')
        || String(contact.company || '').toLowerCase().includes('riverside')
        || String(contact.email || '').toLowerCase().includes('amina.demo')
        || String(contact.email || '').toLowerCase().includes('amina.otieno@riverside-residence.example')
    );
    return '<tr' + (isRiversideDemoContact ? ' data-demo-riverside-contact="1" data-demo-cue-key="contacts_page_visible"' : '') + '>' + selectCell +
        '<td><div style="display:flex;flex-direction:column;gap:0.25rem;"><a href="contact_view.php?id=' + contact.id + '" class="contact-name-link">' + esc(name) + '</a>' +
        (tagsHtml ? '<div class="contact-tags">' + tagsHtml + '</div>' : '') + '</div></td>' +
        '<td>' + esc(contact.email || '-') + '</td><td>' + esc(contact.company || '-') + '</td>' +
        '<td><span class="stage-badge">' + esc(contact.stage || '') + '</span></td>' +
        '<td class="contact-quality-cell"><div class="contact-quality-stack">' + qualityBadgesHtml + qualityMetaHtml + '</div></td>' +
        '<td style="text-align:center;">' + scoreHtml + '</td>' +
        '<td>' + esc(created) + '</td>' +
        '<td class="contact-actions-cell"><a href="contact_view.php?id=' + contact.id + '" class="action-link">View</a>' + editAction + '</td></tr>';
}

function renderContactsStageStats(stageCounts) {
    if (!stageCounts || typeof stageCounts !== 'object') return;
    document.querySelectorAll('.stage-stat[data-stage]').forEach(function(link) {
        const stageName = link.getAttribute('data-stage') || '';
        const stageLabel = link.getAttribute('data-stage-label') || stageName;
        const count = parseInt(stageCounts[stageName] || 0, 10);
        link.textContent = stageLabel + ' (' + count + ')';
    });
}

function renderContactsContent(data, search, stage, tagId, ownerScope) {
    const container = document.getElementById('contacts-list-container');
    const contacts = data.contacts || [];
    const total = data.total || 0;
    const page = data.page || 1;
    const totalPages = data.total_pages || 1;
    const limit = data.limit || 200;
    const offset = data.offset || 0;
    const params = (p) => {
        const q = new URLSearchParams();
        if (search) q.set('search', search);
        if (stage) q.set('stage', stage);
        if (tagId) q.set('tag', tagId);
        if (ownerScope) q.set('owner_scope', ownerScope);
        q.set('page', p);
        return '?' + q.toString();
    };
    if (contacts.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>No contacts found.</p><a href="contacts_create.php">Create your first contact →</a></div>';
    } else {
        const rowsHtml = contacts.map(c => renderContactRow(c)).join('');
        let paginationHtml = '';
        if (totalPages > 1) {
            paginationHtml = '<div class="pagination"><div class="pagination-info">Showing ' + (offset + 1) + '-' + Math.min(offset + limit, total) + ' of ' + total + ' contacts</div><div class="pagination-controls">';
            if (page > 1) paginationHtml += '<a href="' + params(page - 1) + '" class="pagination-link" data-page="' + (page - 1) + '">Previous</a>';
            for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) paginationHtml += '<a href="' + params(i) + '" class="pagination-link' + (i === page ? ' active' : '') + '" data-page="' + i + '">' + i + '</a>';
            if (page < totalPages) paginationHtml += '<a href="' + params(page + 1) + '" class="pagination-link" data-page="' + (page + 1) + '">Next</a>';
            paginationHtml += '</div></div>';
        }
        var bulkMarkup = CONTACTS_IS_PROTECTED_DEMO
            ? '<div id="bulk-actions-bar" class="bulk-actions-bar"><div class="bulk-actions-content"><div class="contacts-demo-readonly-strip"><span><i class="fas fa-lock" aria-hidden="true"></i> Demo-safe view: inspect contacts and intelligence without imports, exports, bulk sends, or deletes.</span>' + CONTACT_TOOLBAR_ACTIONS_HTML + '</div></div></div>'
            : '<div id="bulk-actions-bar" class="bulk-actions-bar"><div class="bulk-actions-content"><div class="bulk-actions-left"><span id="selected-count" class="selected-count">0 selected</span><select id="bulk-action" class="bulk-action-select" aria-label="Bulk action"><option value="">Bulk Actions...</option><option value="bulk_message">Bulk Message</option><option value="bulk_enrich">Bulk Enrichment</option><option value="bulk_email_verify">Bulk Email Verify</option><option value="update_stage">Update Stage</option><option value="update_assigned">Update Assigned To</option><option value="delete">Delete</option></select><button id="apply-bulk-action" class="btn-premium-primary" disabled>Apply</button><button id="clear-selection" class="btn-premium-secondary" disabled>Clear</button></div>' + CONTACT_TOOLBAR_ACTIONS_HTML + '</div></div>' +
                '<div id="bulk-message-modal" class="bulk-update-modal"><div class="bulk-update-content"><h2>Bulk Message</h2><p style="margin-bottom:1rem;color:var(--charcoal-grey);">Choose a channel to send your message:</p><div style="display:flex;flex-direction:column;gap:0.5rem;"><a id="bulk-message-email" href="bulk_email.php" class="btn-premium-primary" style="text-decoration:none;justify-content:center;"><i class="fas fa-envelope"></i> Bulk Email</a><?php echo $canUseSmsChannel ? '<a id="bulk-message-sms" href="bulk_sms.php" class="btn-premium-primary" style="text-decoration:none;justify-content:center;background:#10b981;"><i class="fas fa-sms"></i> Bulk SMS</a>' : ''; ?><?php echo $canUseWhatsAppChannel ? '<a id="bulk-message-whatsapp" href="bulk_whatsapp.php" class="btn-premium-primary" style="text-decoration:none;justify-content:center;background:#25D366;"><i class="fab fa-whatsapp"></i> Bulk WhatsApp</a>' : ''; ?></div><button type="button" id="cancel-bulk-message" class="btn-premium-secondary" style="margin-top:1rem;width:100%;">Cancel</button></div></div>' +
                '<div id="bulk-update-modal" class="bulk-update-modal"><div class="bulk-update-content"><h2>Bulk Update Contacts</h2><form id="bulk-update-form" class="bulk-update-form"><div id="bulk-update-fields"></div><div class="bulk-update-actions"><button type="button" id="cancel-bulk-update" class="btn-premium-secondary">Cancel</button><button type="submit" class="btn-premium-primary">Update</button></div></form></div></div>';
        var selectHeader = CONTACTS_IS_PROTECTED_DEMO ? '' : '<th><input type="checkbox" id="select-all" aria-label="Select all contacts" style="cursor:pointer;" onchange="toggleAllContacts(this.checked)"></th>';
        container.innerHTML = bulkMarkup +
            '<table class="premium-table contacts-list-table"><thead><tr>' + selectHeader + '<th>Name</th><th>Email</th><th>Company</th><th>Stage</th><th>Quality</th><th style="text-align:center;">Score</th><th>Created</th><th>Actions</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>' + paginationHtml;
        if (typeof updateContactsSelection === 'function') updateContactsSelection();
    }
    if (typeof initContactsTableScroller === 'function') initContactsTableScroller();
    renderContactsStageStats(data.stage_counts || {});
}

let contactsFilterAbortController = null;
async function applyContactsFilterAsync(page, options) {
    options = options || {};
    page = page || 1;
    const params = buildContactsFilterParams();
    params.set('page', String(page));
    params.set('limit', '200');
    const container = document.getElementById('contacts-list-container');
    const btn = document.getElementById('filter-btn');
    if (contactsFilterAbortController) contactsFilterAbortController.abort();
    contactsFilterAbortController = new AbortController();
    container.style.opacity = '0.6';
    container.style.pointerEvents = 'none';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...'; }
    try {
        const res = await fetch('../api/contacts.php?' + params.toString(), { signal: contactsFilterAbortController.signal });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Request failed');
        const form = document.getElementById('contacts-filter-form');
        renderContactsContent(data, form.querySelector('#search').value.trim(), form.querySelector('#stage').value, form.querySelector('#tag').value, form.querySelector('#owner_scope').value);
        if (options.updateHistory !== false) {
            history.replaceState(null, '', 'contacts.php?' + params.toString());
        }
    } catch (err) {
        if (err.name !== 'AbortError') {
            document.getElementById('contacts-list-container').innerHTML = '<div class="empty-state"><p style="color:#dc3545;">Failed to load: ' + (err.message || 'Unknown error') + '</p></div>';
        }
    } finally {
        container.style.opacity = '1';
        container.style.pointerEvents = '';
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-filter"></i> Filter'; }
        contactsFilterAbortController = null;
    }
}

window.refreshContactsListAsync = function() {
    return applyContactsFilterAsync(1, { updateHistory: false });
};

window.addEventListener('protected-demo:scene', function(event) {
    if (!CONTACTS_IS_PROTECTED_DEMO) return;
    const detail = event.detail || {};
    const payload = detail.payload || {};
    const sceneKey = String(detail.scene_key || payload.scene_key || payload.demo_event_key || '');
    if (!['contacts_sequence_started', 'amina_contact_opened', 'meeting_prep_revealed'].includes(sceneKey)) return;

    const strip = document.querySelector('.contacts-demo-context-strip');
    if (strip) {
        strip.classList.add('protected-demo-scene-new');
        const summary = strip.querySelector('span');
        if (summary && sceneKey === 'contacts_sequence_started') {
            summary.textContent = 'Private demo contacts are being added and scored. Amina finishes the sequence because Riverside has the strongest buying signal.';
        }
        window.setTimeout(function() {
            strip.classList.remove('protected-demo-scene-new');
        }, 2600);
    }

    window.refreshContactsListAsync().then(function() {
        const rows = Array.from(document.querySelectorAll('[data-demo-riverside-contact="1"]'));
        rows.forEach(function(row, index) {
            row.classList.add('protected-demo-scene-new');
            window.setTimeout(function() {
                row.classList.remove('protected-demo-scene-new');
            }, 2800 + (index * 160));
        });
    });
});

document.getElementById('contacts-filter-form').addEventListener('submit', function(e) { e.preventDefault(); applyContactsFilterAsync(1); });
var filterBtn = document.getElementById('filter-btn');
if (filterBtn) {
    filterBtn.addEventListener('click', function() { applyContactsFilterAsync(1); });
}
['#stage', '#tag', '#owner_scope'].forEach(function(sel) {
    var el = document.querySelector(sel);
    if (el) el.addEventListener('change', function() { applyContactsFilterAsync(1); });
});
(function() {
    var searchEl = document.getElementById('search');
    var debounceTimer;
    if (searchEl) searchEl.addEventListener('input', function() { clearTimeout(debounceTimer); debounceTimer = setTimeout(function() { applyContactsFilterAsync(1); }, 400); });
})();
document.getElementById('contacts-list-container').addEventListener('click', function(e) {
    var link = e.target.closest('.pagination-link');
    if (link && link.dataset.page) { e.preventDefault(); applyContactsFilterAsync(parseInt(link.dataset.page)); }
});

let contactsScrollerCleanup = null;
let contactsScrollerResizeHandlerBound = false;
function initContactsTableScroller() {
    if (typeof contactsScrollerCleanup === 'function') {
        contactsScrollerCleanup();
        contactsScrollerCleanup = null;
    }

    const container = document.getElementById('contacts-list-container');
    if (!container) {
        return;
    }

    let isPointerDown = false;
    let isDragging = false;
    let pointerId = null;
    let startX = 0;
    let startScrollLeft = 0;
    let suppressClick = false;
    const dragThreshold = 8;

    const updateOverflowState = () => {
        const hasOverflow = container.scrollWidth - container.clientWidth > 2;
        const maxScrollLeft = Math.max(0, container.scrollWidth - container.clientWidth);
        const canScrollLeft = hasOverflow && container.scrollLeft > 1;
        const canScrollRight = hasOverflow && container.scrollLeft < maxScrollLeft - 1;
        container.classList.toggle('contacts-overflowing', hasOverflow);
        container.classList.toggle('contacts-draggable', hasOverflow);
        container.classList.toggle('contacts-can-scroll-left', canScrollLeft);
        container.classList.toggle('contacts-can-scroll-right', canScrollRight);
    };

    const stopDragging = () => {
        if (pointerId !== null && container.hasPointerCapture && container.hasPointerCapture(pointerId)) {
            container.releasePointerCapture(pointerId);
        }
        isPointerDown = false;
        isDragging = false;
        pointerId = null;
        container.classList.remove('contacts-dragging');
    };

    const onPointerDown = (event) => {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }
        if (container.scrollWidth - container.clientWidth <= 2) {
            return;
        }
        const interactive = event.target.closest('a, button, input, select, textarea, label');
        if (interactive && event.pointerType !== 'touch') {
            return;
        }
        isPointerDown = true;
        isDragging = false;
        pointerId = event.pointerId;
        startX = event.clientX;
        startScrollLeft = container.scrollLeft;
        suppressClick = false;
        if (container.setPointerCapture) {
            container.setPointerCapture(event.pointerId);
        }
    };

    const onPointerMove = (event) => {
        if (!isPointerDown || event.pointerId !== pointerId) {
            return;
        }
        const deltaX = event.clientX - startX;
        if (!isDragging && Math.abs(deltaX) >= dragThreshold) {
            isDragging = true;
            suppressClick = true;
            container.classList.add('contacts-dragging');
        }
        if (!isDragging) {
            return;
        }
        container.scrollLeft = startScrollLeft - deltaX;
        updateOverflowState();
        event.preventDefault();
    };

    const onPointerUp = () => {
        stopDragging();
        window.setTimeout(() => {
            suppressClick = false;
        }, 0);
    };

    const onClickCapture = (event) => {
        if (!suppressClick) {
            return;
        }
        const interactive = event.target.closest('a, button, input, select, textarea, label');
        if (interactive) {
            event.preventDefault();
            event.stopPropagation();
        }
    };

    container.addEventListener('pointerdown', onPointerDown);
    container.addEventListener('pointermove', onPointerMove, { passive: false });
    container.addEventListener('pointerup', onPointerUp);
    container.addEventListener('pointercancel', onPointerUp);
    container.addEventListener('lostpointercapture', onPointerUp);
    container.addEventListener('scroll', updateOverflowState, { passive: true });
    container.addEventListener('click', onClickCapture, true);

    if (!contactsScrollerResizeHandlerBound) {
        window.addEventListener('resize', function() {
            if (typeof initContactsTableScroller === 'function') {
                initContactsTableScroller();
            }
        });
        contactsScrollerResizeHandlerBound = true;
    }

    updateOverflowState();

    contactsScrollerCleanup = function() {
        container.removeEventListener('pointerdown', onPointerDown);
        container.removeEventListener('pointermove', onPointerMove);
        container.removeEventListener('pointerup', onPointerUp);
        container.removeEventListener('pointercancel', onPointerUp);
        container.removeEventListener('lostpointercapture', onPointerUp);
        container.removeEventListener('scroll', updateOverflowState);
        container.removeEventListener('click', onClickCapture, true);
        container.classList.remove('contacts-dragging');
    };
}

// Global: sync bulk bar and count (call after initial load and after AJAX render)
function updateContactsSelection() {
    var checkboxes = document.querySelectorAll('.contact-checkbox');
    var selectAll = document.getElementById('select-all');
    var bar = document.getElementById('bulk-actions-bar');
    var countEl = document.getElementById('selected-count');
    var applyBtn = document.getElementById('apply-bulk-action');
    var clearBtn = document.getElementById('clear-selection');
    var checked = Array.from(checkboxes).filter(function(cb) { return cb.checked; });
    var n = checked.length;
    var total = checkboxes.length;

    if (countEl) countEl.textContent = n + ' selected';
    if (applyBtn) applyBtn.disabled = n === 0;
    if (clearBtn) clearBtn.disabled = n === 0;
    if (bar) {
        if (n > 0) bar.classList.add('active');
        else bar.classList.remove('active');
    }
    if (selectAll && total > 0) {
        selectAll.checked = n === total;
        selectAll.indeterminate = n > 0 && n < total;
    }
}

function toggleAllContacts(checked) {
    document.querySelectorAll('.contact-checkbox').forEach(function(cb) {
        cb.checked = !!checked;
    });
    updateContactsSelection();
}

function showContactsToast(message, type) {
    var toast = document.createElement('div');
    toast.className = 'contacts-toast ' + (type || 'success');
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(function() {
        toast.classList.add('show');
    });
    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 220);
    }, 2400);
}

document.addEventListener('DOMContentLoaded', function() {
    const tableCard = document.querySelector('.contacts-table-card');
    const bulkUpdateModal = document.getElementById('bulk-update-modal');
    const bulkUpdateForm = document.getElementById('bulk-update-form');
    const bulkUpdateFields = document.getElementById('bulk-update-fields');
    const cancelBulkUpdate = document.getElementById('cancel-bulk-update');
    
    function getSelectedContactIds() {
        return Array.from(document.querySelectorAll('.contact-checkbox'))
            .filter(function(cb) { return cb.checked; })
            .map(function(cb) { return parseInt(cb.value, 10); });
    }
    
    if (tableCard) {
        tableCard.addEventListener('change', function(e) {
            var target = e.target;
            if (target && target.classList && target.classList.contains('contact-checkbox')) {
                updateContactsSelection();
            } else if (target && target.id === 'select-all') {
                var checked = !!target.checked;
                document.querySelectorAll('.contact-checkbox').forEach(function(cb) { cb.checked = checked; });
                updateContactsSelection();
            }
        });
        tableCard.addEventListener('click', function(e) {
            if (e.target.id === 'clear-selection' || e.target.closest('#clear-selection')) {
                document.querySelectorAll('.contact-checkbox').forEach(function(cb) { cb.checked = false; });
                var sa = document.getElementById('select-all');
                if (sa) sa.checked = false;
                updateContactsSelection();
            } else if (e.target.id === 'apply-bulk-action' || e.target.closest('#apply-bulk-action')) {
                handleApplyBulkAction(e);
            } else if (e.target.id === 'cancel-bulk-message' || e.target.closest('#cancel-bulk-message')) {
                const m = document.getElementById('bulk-message-modal');
                if (m) m.classList.remove('active');
            } else if (e.target.id === 'cancel-bulk-update' || e.target.closest('#cancel-bulk-update')) {
                const m = document.getElementById('bulk-update-modal');
                if (m) m.classList.remove('active');
            }
        });
        tableCard.addEventListener('submit', function(e) {
            if (e.target.id === 'bulk-update-form') {
                e.preventDefault();
                handleBulkUpdateSubmit(e.target);
            }
        });

        updateContactsSelection();
    }

    initContactsTableScroller();
    
    function handleApplyBulkAction(e) {
            var selectedContacts = getSelectedContactIds();
            if (selectedContacts.length === 0) {
                alert('Please select at least one contact');
                return;
            }
            
            const actionEl = document.getElementById('bulk-action');
            const action = actionEl ? actionEl.value : '';
            if (!action) {
                alert('Please select an action');
                return;
            }
            
            if (action === 'delete') {
                if (confirm('Are you sure you want to delete ' + selectedContacts.length + ' contact(s)?')) {
                    performBulkDelete(selectedContacts);
                }
            } else if (action === 'bulk_enrich') {
                if (confirm('Enrich ' + selectedContacts.length + ' contact(s)? This may take a few minutes.')) {
                    performBulkEnrichment(selectedContacts);
                }
            } else if (action === 'bulk_email_verify') {
                if (confirm('Verify email for ' + selectedContacts.length + ' contact(s)?')) {
                    performBulkEmailVerify(selectedContacts);
                }
            } else if (action === 'bulk_message') {
                const bulkMessageModal = document.getElementById('bulk-message-modal');
                const ids = selectedContacts.join(',');
                const emailLink = document.getElementById('bulk-message-email');
                const smsLink = document.getElementById('bulk-message-sms');
                const waLink = document.getElementById('bulk-message-whatsapp');
                if (emailLink) emailLink.href = 'bulk_email.php?contact_ids=' + ids;
                if (smsLink) smsLink.href = 'bulk_sms.php?contact_ids=' + ids;
                if (waLink) waLink.href = 'bulk_whatsapp.php?contact_ids=' + ids;
                if (bulkMessageModal) bulkMessageModal.classList.add('active');
            } else if (action === 'update_stage') {
                showBulkUpdateModal('stage');
            } else if (action === 'update_assigned') {
                showBulkUpdateModal('assigned_to');
            }
    }

    
    function showBulkUpdateModal(field) {
        const bulkUpdateFields = document.getElementById('bulk-update-fields');
        const bulkUpdateModal = document.getElementById('bulk-update-modal');
        if (!bulkUpdateFields || !bulkUpdateModal) return;
        
        bulkUpdateFields.innerHTML = '';
        
        if (field === 'stage') {
            bulkUpdateFields.innerHTML = `
                <label style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Stage</label>
                <select name="stage" required style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    <option value="">-- Select Stage --</option>
                    <option value="new">New</option>
                    <option value="contacted">Contacted</option>
                    <option value="qualified">Qualified</option>
                    <option value="proposal">Proposal</option>
                    <option value="negotiation">Negotiation</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                </select>
            `;
        } else if (field === 'assigned_to') {
            const users = (typeof CONTACT_USERS !== 'undefined' ? CONTACT_USERS : []);
            const opts = users.map(u => '<option value="' + u.id + '">' + (u.email || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>').join('');
            bulkUpdateFields.innerHTML = `
                <label style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Assigned To</label>
                <select name="assigned_to" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    <option value="">-- Unassign --</option>
                    ${opts}
                </select>
            `;
        }
        
        bulkUpdateModal.classList.add('active');
    }
    
    function handleBulkUpdateSubmit(form) {
        const formData = new FormData(form);
        const updateData = {};
        if (formData.has('stage') && formData.get('stage')) updateData.stage = formData.get('stage');
        if (formData.has('assigned_to')) updateData.assigned_to = formData.get('assigned_to');
        if (Object.keys(updateData).length === 0) {
            alert('Please select a value');
            return;
        }
        performBulkUpdate(updateData);
    }
    
    function performBulkUpdate(updateData) {
        const ids = getSelectedContactIds();
        const formData = new FormData();
        formData.append('action', 'bulk_update');
        formData.append('contact_ids', JSON.stringify(ids));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        formData.append('csrf_token', csrfToken);
        
        for (const [key, value] of Object.entries(updateData)) {
            formData.append(key, value);
        }
        
        fetch('../api/contacts_bulk.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Successfully updated ' + data.updated + ' contact(s)');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Update failed'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
    
    function performBulkEnrichment(ids) {
        if (!ids || !ids.length) ids = getSelectedContactIds();
        const applyBtn = document.getElementById('apply-bulk-action');
        if (applyBtn) { applyBtn.disabled = true; applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enriching...'; }
        fetch('../api/enrichment/batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contact_ids: ids })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Enrichment complete: ' + data.success_count + ' succeeded, ' + (data.error_count || 0) + ' failed.');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Enrichment failed'));
            }
        })
        .catch(err => alert('Error: ' + err.message))
        .finally(function() {
            if (applyBtn) { applyBtn.disabled = false; applyBtn.innerHTML = 'Apply'; }
        });
    }
    
    function performBulkEmailVerify(ids) {
        if (!ids || !ids.length) ids = getSelectedContactIds();
        const applyBtn = document.getElementById('apply-bulk-action');
        if (applyBtn) { applyBtn.disabled = true; applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...'; }
        fetch('../api/email/verify_batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contact_ids: ids })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Email verification complete: ' + data.verified_count + ' verified, ' + (data.invalid_count || 0) + ' skipped (no email), ' + (data.error_count || 0) + ' errors.');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Verification failed'));
            }
        })
        .catch(err => alert('Error: ' + err.message))
        .finally(function() {
            if (applyBtn) { applyBtn.disabled = false; applyBtn.innerHTML = 'Apply'; }
        });
    }
    
    function performBulkDelete(ids) {
        if (!ids || !ids.length) ids = getSelectedContactIds();
        const selectedCount = ids.length;
        const formData = new FormData();
        formData.append('action', 'bulk_delete');
        formData.append('contact_ids', JSON.stringify(ids));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        formData.append('csrf_token', csrfToken);
        
        fetch('../api/contacts_bulk.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const deleted = Number(data.deleted || 0);
                const failed = Math.max(0, selectedCount - deleted);
                showContactsToast('Deleted ' + deleted + ', failed ' + failed + '.', failed > 0 ? 'warning' : 'success');
                setTimeout(function() { location.reload(); }, 900);
            } else {
                showContactsToast('Delete failed: ' + (data.error || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            showContactsToast('Delete failed: ' + error.message, 'error');
        });
    }
});
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_CONTACTS, 'How to use Contacts', $contactsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
