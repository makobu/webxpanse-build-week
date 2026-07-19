<?php
/**
 * Contact View Page
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
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\Contacts;
use CRM\Modules\ActivityTimeline;
use CRM\Modules\CustomFields;
use CRM\Modules\Tags;
use CRM\Modules\Notes;
use CRM\Modules\Documents;
use CRM\Modules\DocumentCategories;
use CRM\Modules\Deals;
use CRM\Modules\Marketing;
use CRM\Modules\Nurture;
use CRM\Security;
use CRM\Modules\AILeadScoring;
use CRM\Services\MLExplainabilityService;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canManageAllNotes = Authorization::can('notes.manage_all', $user);
$canManageAllDocuments = Authorization::can('documents.manage_all', $user);
$canViewScoringDebug = Authorization::can('settings.scoring', $user);
$canReadMarketing = Authorization::can('marketing.read', $user) && (new MarketingMarketplaceGateService())->canRun($user);
$canReadNurture = Authorization::can('nurture.read', $user);
$contactId = (int) ($_GET['id'] ?? 0);

$contactsModule = new Contacts();
$customFieldsModule = new CustomFields();
$tagsModule = new Tags();
$notesModule = new Notes();
$documentsModule = new Documents();
$documentCategoriesModule = new DocumentCategories();
$dealsModule = new Deals();
$workspaceScope = new WorkspaceScopeService();
$activeWorkspaceId = $workspaceScope->requireActiveWorkspaceId();
$isProtectedDemoContactView = false;
try {
    $isProtectedDemoContactView = (new DemoSessionScopeService())->activeSession($activeWorkspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoContactView = false;
}

try {
    $scoringService = new AILeadScoring();
} catch (\Exception $e) {
    $scoringService = null;
}

try {
    $explainabilityService = new MLExplainabilityService();
} catch (\Exception $e) {
    $explainabilityService = null;
}

// Get document categories
$documentCategories = $documentCategoriesModule->getAll();

if (!$contactId) {
    header('Location: contacts.php');
    exit;
}

$contact = $contactsModule->getById($contactId);

if (!$contact) {
    header('Location: contacts.php');
    exit;
}
if (!$contactsModule->isVisibleToUser($contact, $userId, Authorization::can('contacts.view_all', $user))) {
    http_response_code(403);
    die('Access denied: You do not have permission to view this contact.');
}

$contactDemoMetadata = [];
if (!empty($contact['metadata_json'])) {
    $decodedContactDemoMetadata = json_decode((string) $contact['metadata_json'], true);
    $contactDemoMetadata = is_array($decodedContactDemoMetadata) ? $decodedContactDemoMetadata : [];
}
$protectedDemoMeetingPrep = $isProtectedDemoContactView ? (array) ($contactDemoMetadata['meeting_prep'] ?? []) : [];
$protectedDemoScoreTimeline = $isProtectedDemoContactView ? (array) ($contactDemoMetadata['score_timeline'] ?? []) : [];

$contactIntelligenceService = new ContactIntelligenceService();
$contactIntelligence = $contactIntelligenceService->getStoredOrCompute($contactId) ?? [];
$unifiedTimeline = $contactIntelligenceService->buildUnifiedTimeline($contactId, 40);

$enrichmentHistory = [];
$fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
$suggestedStructuredFields = $contactsModule->getSuggestedStructuredFields($contact);
$sourceBadgeMap = [
    'manual' => ['label' => 'Manual', 'bg' => '#eef2ff', 'color' => '#384ad7'],
    'api_verified' => ['label' => 'Verified', 'bg' => '#e8f7ef', 'color' => '#1f7a46'],
    'communication_explicit' => ['label' => 'From message', 'bg' => '#fff3e8', 'color' => '#b85a11'],
];
$getFieldSource = static function (string $field) use (&$fieldProvenance, $sourceBadgeMap, &$enrichmentHistory): ?array {
    if (!empty($fieldProvenance[$field]) && is_array($fieldProvenance[$field])) {
        $entry = $fieldProvenance[$field];
        $style = $sourceBadgeMap[$entry['source_type'] ?? ''] ?? ['label' => 'Tracked', 'bg' => '#f1f5f9', 'color' => '#475569'];
        return [
            'label' => $entry['source_label'] ?? $style['label'],
            'short_label' => $style['label'],
            'bg' => $style['bg'],
            'color' => $style['color'],
        ];
    }

    if (isset($enrichmentHistory[$field])) {
        return [
            'label' => 'Previously enriched',
            'short_label' => 'Enriched',
            'bg' => '#e8f7ef',
            'color' => '#1f7a46',
        ];
    }

    return null;
};

$renderFieldSourceBadge = static function (string $field) use ($getFieldSource): string {
    $source = $getFieldSource($field);
    if ($source === null) {
        return '';
    }

    return sprintf(
        '<span style="background: %s; color: %s; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600;" title="%s">%s</span>',
        htmlspecialchars($source['bg']),
        htmlspecialchars($source['color']),
        htmlspecialchars($source['label']),
        htmlspecialchars($source['short_label'])
    );
};

// Get enrichment history to identify enriched fields
try {
    // Get all successful enrichments and merge their fields_updated
    $enrichments = Database::query(
        "SELECT fields_updated FROM enrichment_history 
         WHERE contact_id = ? AND status = 'success' 
         ORDER BY created_at DESC",
        [$contactId]
    );
    foreach ($enrichments as $enrichment) {
        if (!empty($enrichment['fields_updated'])) {
            $fields = json_decode($enrichment['fields_updated'], true) ?: [];
            if (is_array($fields)) {
                // Merge fields - handle both list and associative array formats
                foreach ($fields as $key => $value) {
                    if (is_numeric($key) || (is_string($key) && is_numeric($key))) {
                        // It's a list, use value as field name
                        $fieldName = is_string($value) ? $value : $key;
                        $enrichmentHistory[$fieldName] = true;
                    } else {
                        // It's already associative
                        $enrichmentHistory[$key] = true;
                    }
                }
            }
        }
    }
} catch (\Exception $e) {
    // Table might not exist yet, that's okay
}

$recentEnrichmentAudit = [];
try {
    $recentEnrichmentAudit = Database::query(
        "SELECT enrichment_type, fields_updated, ai_response, status, created_at
         FROM enrichment_history
         WHERE contact_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        [$contactId]
    );
} catch (\Exception $e) {
    $recentEnrichmentAudit = [];
}

// Get activities
try {
    $activities = Database::query(
        "SELECT * FROM activities WHERE workspace_id = ? AND contact_id = ? ORDER BY created_at DESC LIMIT 20",
        [$activeWorkspaceId, $contactId]
    );
} catch (\Exception $e) {
    $activities = [];
}

// Get custom fields and their values
$customFields = $customFieldsModule->getByModule('contacts');
$customFieldValues = $customFieldsModule->getContactValues($contactId);
$customFieldValuesMap = [];
foreach ($customFieldValues as $value) {
    $customFieldValuesMap[$value['field_id']] = $value['field_value'];
}

// Get contact tags
$contactTags = $tagsModule->getEntityTags('contact', $contactId);

// Get contact notes
$contactNotes = $notesModule->getEntityNotes('contact', $contactId, false, $userId);

// Get contact documents
$contactDocuments = $documentsModule->getEntityDocuments('contact', $contactId);
// Load version history for all documents
foreach ($contactDocuments as &$doc) {
    $doc['version_history'] = $documentsModule->getVersionHistory($doc['id']);
}
unset($doc);

// Get contact deals
$contactDeals = $dealsModule->getContactDeals($contactId);

$marketingHandoffs = [];
$marketingHandoffSummary = [
    'total' => 0,
    'open' => 0,
    'assigned' => 0,
    'overdue' => 0,
    'converted' => 0,
    'sync_pending' => 0,
    'sync_failed' => 0,
];
$marketingCrmProfile = [];
try {
    if ($canReadMarketing && Database::tableExists('marketing_lead_handoffs')) {
        $marketingModule = new Marketing();
        $marketingHandoffs = $marketingModule->listLeadHandoffsForCrmRecord('contact', $contactId, 5);
        $marketingHandoffSummary = $marketingModule->getLeadHandoffCrmRecordSummary('contact', $contactId);
        $marketingCrmProfile = $marketingModule->getMarketingCrmLifecycleProfile(['contact_id' => $contactId], 6);
    }
} catch (\Throwable $e) {
    $marketingHandoffs = [];
    $marketingCrmProfile = [];
}
$nurtureReadiness = [];
try {
    if ($canReadNurture) {
        $nurtureReadiness = (new Nurture())->getTransitionReadinessForContact($contactId, false);
    }
} catch (\Throwable $e) {
    $nurtureReadiness = [];
}

$refreshContactAiContext = static function (int $contactId, array $contact) use ($activeWorkspaceId): void {
    try {
        $contextGenerator = new \CRM\Modules\AIContextGenerator();
        $context = $contextGenerator->generateContext($contactId, [], $contact);
        Database::execute(
            "UPDATE contacts SET ai_context = ? WHERE workspace_id = ? AND id = ?",
            [json_encode($context, JSON_UNESCAPED_SLASHES), $activeWorkspaceId, $contactId]
        );
        (new ContactIntelligenceService())->computeAndPersist($contactId);
    } catch (\Throwable $e) {
        error_log('Contact note AI context refresh failed: ' . $e->getMessage());
    }
};

// Handle note creation
$noteError = null;
$noteSuccess = isset($_GET['note_saved']) && $_GET['note_saved'] === '1';
$noteFormTitle = '';
$noteFormContent = '';
$noteFormPrivate = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_suggested_field') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $field = (string) ($_POST['field'] ?? '');
        $contactsModule->applySuggestedStructuredField($contactId, $field);
        $contact = $contactsModule->getById($contactId);
        $fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
        $suggestedStructuredFields = $contactsModule->getSuggestedStructuredFields($contact);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_suggested_field') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $field = (string) ($_POST['field'] ?? '');
        $contactsModule->dismissSuggestedStructuredField($contactId, $field);
        $contact = $contactsModule->getById($contactId);
        $fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
        $suggestedStructuredFields = $contactsModule->getSuggestedStructuredFields($contact);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_note') {
    $noteFormTitle = (string) ($_POST['note_title'] ?? '');
    $noteFormContent = (string) ($_POST['note_content'] ?? '');
    $noteFormPrivate = isset($_POST['note_private']);
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $notesModule->create([
                'entity_type' => 'contact',
                'entity_id' => $contactId,
                'title' => $_POST['note_title'] ?? null,
                'content' => $_POST['note_content'] ?? '',
                'content_html' => $_POST['note_content_html'] ?? null,
                'is_private' => isset($_POST['note_private']) ? 1 : 0,
                'created_by' => $userId
            ]);
            $refreshContactAiContext($contactId, $contact);
            $contact = $contactsModule->getById($contactId);
            $contactIntelligence = $contactIntelligenceService->getStoredOrCompute($contactId) ?? [];
            $unifiedTimeline = $contactIntelligenceService->buildUnifiedTimeline($contactId, 40);
            header('Location: contact_view.php?' . http_build_query([
                'id' => $contactId,
                'note_saved' => 1,
            ]) . '#notes-section');
            exit;
        } catch (\Exception $e) {
            $noteError = $e->getMessage();
        }
    }
}

// Handle note reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reply') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $parentId = (int) ($_POST['parent_note_id'] ?? 0);
            $replyContent = trim($_POST['reply_content'] ?? '');
            if ($parentId && $replyContent !== '') {
                $notesModule->addReply($parentId, $replyContent, $userId);
                $refreshContactAiContext($contactId, $contact);
                $contact = $contactsModule->getById($contactId);
                $contactIntelligence = $contactIntelligenceService->getStoredOrCompute($contactId) ?? [];
                $unifiedTimeline = $contactIntelligenceService->buildUnifiedTimeline($contactId, 40);
            }
            $contactNotes = $notesModule->getEntityNotes('contact', $contactId, false, $userId);
        } catch (\Exception $e) {
            $noteError = $e->getMessage();
        }
    }
}

// Handle note deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $note = $notesModule->getById($noteId);
        if ($note && ($note['created_by'] == $userId || $canManageAllNotes)) {
            $notesModule->delete($noteId);
            $refreshContactAiContext($contactId, $contact);
            $contact = $contactsModule->getById($contactId);
            $contactIntelligence = $contactIntelligenceService->getStoredOrCompute($contactId) ?? [];
            $unifiedTimeline = $contactIntelligenceService->buildUnifiedTimeline($contactId, 40);
            // Refresh notes
            $contactNotes = $notesModule->getEntityNotes('contact', $contactId, false, $userId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_update_contact') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $updateData = [
                'phone' => $_POST['quick_phone'] ?? '',
                'company' => $_POST['quick_company'] ?? '',
                'company_id' => $_POST['quick_company_id'] ?? ($contact['company_id'] ?? ''),
                'job_title' => $_POST['quick_job_title'] ?? '',
                'location' => $_POST['quick_location'] ?? '',
                'stage' => $_POST['quick_stage'] ?? ($contact['stage'] ?? 'new'),
            ];
            $contactsModule->update($contactId, $updateData);

            $manualBuyingRole = trim((string) ($_POST['quick_buying_role'] ?? ''));
            if ($manualBuyingRole !== '') {
                $freshContact = $contactsModule->getById($contactId);
                $freshMetadata = json_decode((string) ($freshContact['metadata_json'] ?? ''), true);
                if (!is_array($freshMetadata)) {
                    $freshMetadata = [];
                }
                if (!isset($freshMetadata['contact_intelligence']) || !is_array($freshMetadata['contact_intelligence'])) {
                    $freshMetadata['contact_intelligence'] = [];
                }
                if (!isset($freshMetadata['contact_intelligence']['buying_role']) || !is_array($freshMetadata['contact_intelligence']['buying_role'])) {
                    $freshMetadata['contact_intelligence']['buying_role'] = [];
                }
                $freshMetadata['contact_intelligence']['buying_role']['manual_role'] = $manualBuyingRole;
                Database::execute(
                    "UPDATE contacts SET metadata_json = ? WHERE workspace_id = ? AND id = ?",
                    [json_encode($freshMetadata, JSON_UNESCAPED_SLASHES), $activeWorkspaceId, $contactId]
                );
            }

            $contact = $contactsModule->getById($contactId);
            $contactIntelligence = $contactIntelligenceService->computeAndPersist($contactId) ?? [];
            $unifiedTimeline = $contactIntelligenceService->buildUnifiedTimeline($contactId, 40);
        } catch (\Throwable $e) {
            $noteError = $e->getMessage();
        }
    }
}

$relationshipSummary = $contactIntelligence['relationship_summary'] ?? [];
$relationshipHealth = $contactIntelligence['relationship_health'] ?? ['score' => 0, 'band' => 'stale', 'risk_flags' => []];
$communicationIntelligence = $contactIntelligence['communication_intelligence'] ?? [];
$accountContext = $contactIntelligence['account_context'] ?? [];
$dataQuality = $contactIntelligence['data_quality'] ?? [];
$nextBestActions = $contactIntelligence['next_best_actions'] ?? [];
$buyingRole = $contactIntelligence['buying_role'] ?? ['suggested_role' => 'unknown'];

$pageTitle = $contact['first_name'] . ' ' . $contact['last_name'] . ' - ' . brandProductName();
$contactFullName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))) ?: 'Unnamed Contact';
$contactInitials = strtoupper(substr((string) ($contact['first_name'] ?? ''), 0, 1) . substr((string) ($contact['last_name'] ?? ''), 0, 1));
$contactInitials = $contactInitials !== '' ? $contactInitials : strtoupper(substr($contactFullName, 0, 1));
$contactCompanyLabel = trim((string) ($contact['company'] ?? '')) ?: 'No company linked';
$contactRoleLabel = trim((string) ($contact['job_title'] ?? '')) ?: 'Role unknown';
$contactLocationLabel = trim((string) ($contact['location'] ?? '')) ?: 'Location unknown';
$contactStageLabel = ucfirst(str_replace('_', ' ', (string) ($contact['stage'] ?? 'new')));
$relationshipScore = (int) ($relationshipHealth['score'] ?? 0);
$relationshipBand = str_replace('_', ' ', (string) ($relationshipHealth['band'] ?? 'unknown'));
$buyingRoleLabel = str_replace('_', ' ', (string) ($buyingRole['suggested_role'] ?? 'unknown'));
$hasEnrichmentHistory = false;
try {
    $lastEnrichment = Database::queryOne(
        "SELECT id FROM enrichment_history
         WHERE contact_id = ? AND enrichment_type = 'merge'
         ORDER BY created_at DESC
         LIMIT 1",
        [$contactId]
    );
    $hasEnrichmentHistory = !empty($lastEnrichment);
} catch (\Exception $e) {
    // Table might not exist, ignore.
}
ob_start();
?>

<link rel="stylesheet" href="assets/css/contact-detail.css?v=20260520q">

<div class="contact-detail-page" data-active-contact-tab="overview">
    <section class="contact-detail-hero" aria-label="Contact command center">
        <div class="contact-detail-hero-grid">
            <div class="contact-detail-hero-main">
                <div class="contact-detail-avatar" aria-hidden="true"><?php echo htmlspecialchars($contactInitials); ?></div>
                <div class="contact-detail-title">
                    <div class="contact-detail-eyebrow">Contact workspace</div>
                    <h1><?php echo htmlspecialchars($contactFullName); ?></h1>
                    <div class="contact-detail-subtitle">
                        <span><?php echo htmlspecialchars($contactRoleLabel); ?></span>
                        <span><?php echo htmlspecialchars($contactCompanyLabel); ?></span>
                    </div>
                    <div class="contact-detail-meta" aria-label="Contact metadata">
                        <?php if (!empty($contact['email'])): ?>
                            <span><i class="fa-solid fa-envelope" aria-hidden="true"></i><?php echo htmlspecialchars((string) $contact['email']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($contact['phone'])): ?>
                            <span><i class="fa-solid fa-phone" aria-hidden="true"></i><?php echo htmlspecialchars((string) $contact['phone']); ?></span>
                        <?php endif; ?>
                        <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i><?php echo htmlspecialchars($contactLocationLabel); ?></span>
                    </div>
                </div>
            </div>

            <div class="contact-detail-actions" aria-label="Contact actions">
                <div class="contact-primary-action" aria-label="Primary contact action">
                    <?php if (!empty($contact['email'])): ?>
                        <a class="contact-detail-action contact-detail-action--primary" href="email_compose.php?contact_id=<?php echo (int) $contact['id']; ?>">
                            <i class="fa-solid fa-envelope" aria-hidden="true"></i>Email
                        </a>
                    <?php endif; ?>
                    <?php include __DIR__ . '/../views/partials/contact_voice_action.php'; ?>
                    <a class="contact-detail-action" href="calendar_share.php?contact_id=<?php echo (int) $contact['id']; ?>">
                        <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>Share Calendar
                    </a>
                    <a class="contact-detail-action" href="task_edit.php?contact_id=<?php echo (int) $contact['id']; ?>">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>Add Task
                    </a>
                    <a class="contact-detail-action" href="deal_edit.php?contact_id=<?php echo (int) $contact['id']; ?>">
                        <i class="fa-solid fa-handshake" aria-hidden="true"></i>Add Deal
                    </a>
                    <a class="contact-detail-action" href="#notes-section" data-contact-tab-link="notes">
                        <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>Add Note
                    </a>
                </div>
                <details class="contact-action-menu">
                    <summary class="contact-more-action">
                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                        More actions
                    </summary>
                    <div class="contact-action-menu-panel">
                        <div class="contact-action-strip" aria-label="Contact channels">
                            <?php if (!empty($contact['phone'])): ?>
                                <a class="contact-quiet-action" href="tel:<?php echo htmlspecialchars((string) $contact['phone']); ?>">
                                    <i class="fa-solid fa-phone" aria-hidden="true"></i>Call
                                </a>
                                <a class="contact-quiet-action" href="whatsapp_messages.php?contact_id=<?php echo (int) $contact['id']; ?>">
                                    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>WhatsApp
                                </a>
                            <?php endif; ?>
                            <a class="contact-quiet-action" href="contact_edit.php?id=<?php echo (int) $contact['id']; ?>">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>Edit
                            </a>
                            <a class="contact-quiet-action" href="contacts.php">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back
                            </a>
                        </div>
                        <div class="contact-action-strip" aria-label="Data actions">
                            <button
                                class="contact-quiet-action contact-quiet-action--accent"
                                onclick="enrichContact(<?php echo (int) $contact['id']; ?>)"
                                id="enrich-btn"
                                type="button"
                                title="Refresh verified provider data and AI context"
                            >
                                <i class="fa-solid fa-magnifying-glass-chart" aria-hidden="true"></i>Enrichment
                            </button>
                            <?php if ($hasEnrichmentHistory): ?>
                                <button
                                    class="contact-quiet-action contact-quiet-action--danger"
                                    onclick="undoEnrichment(<?php echo (int) $contact['id']; ?>)"
                                    id="undo-enrich-btn"
                                    type="button"
                                    title="Undo last enrichment"
                                >
                                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Undo
                                </button>
                            <?php endif; ?>
                            <span id="enrich-status" class="contact-detail-status"></span>
                        </div>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <nav class="contact-detail-tabs" aria-label="Contact sections">
        <button class="contact-detail-tab is-active" type="button" data-contact-tab="overview" data-contact-tab-target="overview"><i class="fa-solid fa-address-card" aria-hidden="true"></i>Overview</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="profile" data-contact-tab-target="profile"><i class="fa-solid fa-user" aria-hidden="true"></i>Profile</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="quick-edit" data-contact-tab-target="quick-edit"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Quick Edit</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="ai-context" data-contact-tab-target="ai-context"><i class="fa-solid fa-robot" aria-hidden="true"></i>AI Context</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="meeting-prep" data-contact-tab-target="meeting-prep"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>Meeting Prep</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="scoring" data-contact-tab-target="scoring"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i>Scoring</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="timeline" data-contact-tab-target="timeline"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Timeline</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="notes" data-contact-tab-target="notes"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i>Notes</button>
        <button class="contact-detail-tab" type="button" data-contact-tab="documents" data-contact-tab-target="documents"><i class="fa-solid fa-folder-open" aria-hidden="true"></i>Documents</button>
    </nav>

    <div class="contact-detail-panels">
        <section class="contact-detail-panel is-active" data-contact-tab="overview" id="contact-overview">
<div class="contact-overview-workspace" data-guided-demo-target="contact-founder-context">
    <div class="contact-overview-main">
        <section class="contact-card contact-health-card" aria-labelledby="relationship-summary-heading">
            <div class="contact-health-header">
                <div>
                    <div class="contact-section-kicker">Relationship Summary</div>
                    <h2 id="relationship-summary-heading">Current relationship health</h2>
                </div>
                <span class="contact-role-pill">Buying role: <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($buyingRole['suggested_role'] ?? 'unknown'))); ?></span>
            </div>

            <div class="contact-health-layout">
                <div class="contact-health-score">
                    <div class="contact-health-score-main">
                        <span class="contact-health-number"><?php echo (int) ($relationshipHealth['score'] ?? 0); ?></span>
                        <span class="contact-health-total">/100</span>
                    </div>
                    <div class="contact-health-label">Health: <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($relationshipHealth['band'] ?? 'unknown'))); ?></div>
                    <p class="contact-health-copy">
                        <?php echo !empty($relationshipHealth['risk_flags']) ? count($relationshipHealth['risk_flags']) . ' risk flag' . (count($relationshipHealth['risk_flags']) === 1 ? '' : 's') . ' need attention.' : 'No major relationship risks detected.'; ?>
                    </p>
                </div>
                <div class="contact-activity-list" aria-label="Latest relationship activity">
                    <?php
                    $summaryCards = [
                        'Last inbound' => $relationshipSummary['last_inbound_reply'] ?? null,
                        'Last outbound' => $relationshipSummary['last_outbound_reply'] ?? null,
                        'Last meeting' => $relationshipSummary['last_meeting'] ?? null,
                        'Last invoice' => $relationshipSummary['last_quote_or_invoice'] ?? null,
                        'Last note' => $relationshipSummary['last_note'] ?? null,
                        'Open follow-up' => $relationshipSummary['last_open_task'] ?? null,
                    ];
                    foreach ($summaryCards as $label => $item):
                        $hasSummaryItem = !empty($item);
                    ?>
                        <div class="contact-activity-row <?php echo $hasSummaryItem ? '' : 'is-empty'; ?>">
                            <div>
                                <span class="contact-activity-label"><?php echo htmlspecialchars($label); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($item['title'] ?? 'None yet')); ?></strong>
                            </div>
                            <span><?php echo !empty($item['created_at']) ? htmlspecialchars(date('M j', strtotime((string) $item['created_at']))) : 'No activity'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

    </div>

    <aside class="contact-overview-rail" aria-label="Contact workspace sidebar">
        <section class="contact-card contact-rail-card">
            <div class="contact-section-kicker">Risk Flags</div>
            <?php if (!empty($relationshipHealth['risk_flags'])): ?>
                <div class="contact-pill-list">
                    <?php foreach ($relationshipHealth['risk_flags'] as $flag): ?>
                        <span class="contact-risk-pill"><?php echo htmlspecialchars((string) $flag); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="contact-muted-copy">No major relationship risks detected.</p>
            <?php endif; ?>
        </section>

        <section class="contact-card contact-rail-card">
            <div class="contact-section-kicker">Next Best Actions</div>
            <?php if (!empty($nextBestActions)): ?>
                <ul class="contact-action-list">
                    <?php foreach ($nextBestActions as $action): ?>
                        <li><?php echo htmlspecialchars((string) $action); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="contact-muted-copy">No next action suggestions yet.</p>
            <?php endif; ?>
        </section>

        <section class="contact-card contact-rail-card">
            <div class="contact-section-kicker">Marketing Handoffs</div>
            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 10px 0;">
                <div>
                    <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($marketingHandoffSummary['open'] ?? 0); ?></strong>
                    <span class="contact-muted-copy" style="font-size:11px;">Open</span>
                </div>
                <div>
                    <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($marketingHandoffSummary['overdue'] ?? 0); ?></strong>
                    <span class="contact-muted-copy" style="font-size:11px;">Overdue</span>
                </div>
                <div>
                    <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($marketingHandoffSummary['converted'] ?? 0); ?></strong>
                    <span class="contact-muted-copy" style="font-size:11px;">Converted</span>
                </div>
            </div>
            <?php if (empty($marketingHandoffs)): ?>
                <p class="contact-muted-copy">No marketing handoffs linked to this contact yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:10px;">
                    <?php foreach ($marketingHandoffs as $handoff): ?>
                        <?php
                            $handoffStatus = ucwords(str_replace('_', ' ', (string) ($handoff['status'] ?? 'new')));
                            $handoffTitle = trim((string) ($handoff['campaign_name'] ?? $handoff['landing_page_title'] ?? $handoff['conversion_goal_title'] ?? 'Marketing lead'));
                            $handoffDue = !empty($handoff['sla_due_at']) ? date('M j, g:i A', strtotime((string) $handoff['sla_due_at'])) : 'No SLA';
                        ?>
                        <div style="border:1px solid #edf2f7;border-radius:8px;padding:10px;background:#fff;">
                            <div style="font-weight:700;color:var(--midnight-black);font-size:13px;"><?php echo htmlspecialchars($handoffTitle); ?></div>
                            <div class="contact-muted-copy" style="font-size:12px;margin-top:4px;">
                                <?php echo htmlspecialchars($handoffStatus); ?> &middot; <?php echo htmlspecialchars((string) ($handoff['assigned_to_email'] ?? 'Unassigned')); ?>
                            </div>
                            <div class="contact-muted-copy" style="font-size:12px;margin-top:2px;">
                                SLA <?php echo htmlspecialchars($handoffDue); ?> &middot; CRM <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($handoff['crm_sync_status'] ?? 'pending')))); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canReadMarketing): ?>
                    <a class="contact-detail-action" style="margin-top:10px;width:100%;justify-content:center;" href="marketing_handoffs.php?contact_id=<?php echo (int) $contactId; ?>">Open Marketing Handoffs</a>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($canReadNurture): ?>
        <section class="contact-card contact-rail-card">
            <div class="contact-section-kicker">Customer Care</div>
            <?php if (empty($nurtureReadiness)): ?>
                <p class="contact-muted-copy">Customer care readiness is not available for this contact.</p>
            <?php else: ?>
                <?php
                    $nurtureStatus = (string) ($nurtureReadiness['status'] ?? 'not_ready');
                    $nurtureProfile = (array) ($nurtureReadiness['profile'] ?? []);
                    $nurtureEvidence = (array) ($nurtureReadiness['purchase_evidence'] ?? []);
                    $nurtureHref = 'nurture_view.php?contact_id=' . (int) $contactId;
                ?>
                <div style="border:1px solid <?php echo $nurtureStatus === 'not_ready' ? '#fed7aa' : '#bbf7d0'; ?>;border-radius:8px;padding:10px;background:<?php echo $nurtureStatus === 'not_ready' ? '#fff7ed' : '#f0fdf4'; ?>;">
                    <strong style="display:block;color:var(--midnight-black);font-size:13px;"><?php echo htmlspecialchars((string) ($nurtureReadiness['label'] ?? 'Needs purchase evidence')); ?></strong>
                    <span class="contact-muted-copy" style="font-size:12px;margin-top:4px;"><?php echo htmlspecialchars((string) ($nurtureReadiness['next_step'] ?? 'Review customer care readiness.')); ?></span>
                </div>
                <?php if (!empty($nurtureProfile)): ?>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:10px 0;">
                        <div>
                            <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($nurtureProfile['health_score'] ?? 0); ?></strong>
                            <span class="contact-muted-copy" style="font-size:11px;">Health</span>
                        </div>
                        <div>
                            <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($nurtureProfile['nurture_status'] ?? 'active')))); ?></strong>
                            <span class="contact-muted-copy" style="font-size:11px;">Status</span>
                        </div>
                    </div>
                <?php elseif (!empty($nurtureEvidence)): ?>
                    <p class="contact-muted-copy" style="margin-top:10px;"><?php echo htmlspecialchars((string) ($nurtureEvidence['summary']['label'] ?? 'Purchase evidence')); ?> is ready for customer care.</p>
                <?php endif; ?>
                <?php if ($nurtureStatus !== 'not_ready'): ?>
                    <a class="contact-detail-action" style="margin-top:10px;width:100%;justify-content:center;" href="<?php echo htmlspecialchars($nurtureHref); ?>">Open Customer Care</a>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($canReadMarketing): ?>
        <section class="contact-card contact-rail-card">
            <div class="contact-section-kicker">Marketing CRM Integration</div>
            <?php if (empty($marketingCrmProfile)): ?>
                <p class="contact-muted-copy">Marketing lifecycle context is not available for this contact yet.</p>
            <?php else: ?>
                <?php $crmCounts = (array) ($marketingCrmProfile['counts'] ?? []); ?>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:10px 0;">
                    <div>
                        <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($marketingCrmProfile['score'] ?? 0); ?>%</strong>
                        <span class="contact-muted-copy" style="font-size:11px;">Lifecycle</span>
                    </div>
                    <div>
                        <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($crmCounts['attribution_touchpoints'] ?? 0); ?></strong>
                        <span class="contact-muted-copy" style="font-size:11px;">Attribution</span>
                    </div>
                    <div>
                        <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($crmCounts['content_items'] ?? 0); ?></strong>
                        <span class="contact-muted-copy" style="font-size:11px;">Content</span>
                    </div>
                    <div>
                        <strong style="display:block;color:var(--midnight-black);font-size:18px;"><?php echo (int) ($crmCounts['lead_handoffs_open'] ?? 0); ?></strong>
                        <span class="contact-muted-copy" style="font-size:11px;">Handoffs</span>
                    </div>
                </div>
                <?php if (empty($marketingCrmProfile['recommendations'])): ?>
                    <p class="contact-muted-copy">This contact is connected to the current marketing lifecycle signals.</p>
                <?php else: ?>
                    <div style="display:grid;gap:8px;">
                        <?php foreach (array_slice((array) ($marketingCrmProfile['recommendations'] ?? []), 0, 3) as $recommendation): ?>
                            <a href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing.php')); ?>" style="display:block;border:1px solid #edf2f7;border-radius:8px;padding:10px;background:#fff;text-decoration:none;">
                                <strong style="display:block;color:var(--midnight-black);font-size:13px;"><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Review marketing action')); ?></strong>
                                <span class="contact-muted-copy" style="font-size:12px;margin-top:4px;"><?php echo htmlspecialchars((string) ($recommendation['reason'] ?? 'Review this Marketing CRM action.')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </aside>
</div>

<?php if (!empty($suggestedStructuredFields)): ?>
<div style="background: #fff8ef; border: 1px solid #f2d2a6; border-radius: 10px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; gap: var(--spacing-md); align-items: start; margin-bottom: var(--spacing-sm);">
        <div>
            <h2 style="margin: 0 0 var(--spacing-xs); font-size: 18px; color: var(--midnight-black);">Suggested From Communication</h2>
            <p style="margin: 0; color: var(--charcoal-grey); font-size: 14px;">These values were detected from a message or signature but were not strong enough to auto-write. Review and apply only if accurate.</p>
        </div>
    </div>
    <div style="display: grid; gap: var(--spacing-md);">
        <?php foreach ($suggestedStructuredFields as $field => $suggestion): ?>
            <div style="background: white; border: 1px solid #f1dfc6; border-radius: 8px; padding: var(--spacing-md); display: flex; justify-content: space-between; gap: var(--spacing-md); align-items: center; flex-wrap: wrap;">
                <div>
                    <div style="font-size: 13px; color: var(--charcoal-grey); margin-bottom: 4px;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field))); ?></div>
                    <div style="font-weight: 600; color: var(--midnight-black); margin-bottom: 4px;"><?php echo htmlspecialchars((string) ($suggestion['value'] ?? '')); ?></div>
                    <div style="font-size: 12px; color: var(--charcoal-grey);">
                        <?php echo htmlspecialchars((string) ($suggestion['source_label'] ?? 'Suggested from communication')); ?>
                        <?php if (!empty($suggestion['confidence'])): ?>
                            &middot; confidence <?php echo htmlspecialchars(number_format((float) $suggestion['confidence'], 2)); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; gap: var(--spacing-sm); align-items: center;">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="apply_suggested_field">
                        <input type="hidden" name="field" value="<?php echo htmlspecialchars($field); ?>">
                        <button type="submit" style="background: #1f7a46; color: white; border: none; border-radius: 999px; padding: 10px 16px; font-weight: 600; cursor: pointer;">Apply</button>
                    </form>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="dismiss_suggested_field">
                        <input type="hidden" name="field" value="<?php echo htmlspecialchars($field); ?>">
                        <button type="submit" style="background: white; color: #8a3b12; border: 1px solid #e8b98a; border-radius: 999px; padding: 10px 16px; font-weight: 600; cursor: pointer;">Dismiss</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

        </section>

        <section class="contact-detail-panel" data-contact-tab="quick-edit" id="contact-quick-edit">
            <section class="contact-card contact-quick-edit-card" aria-labelledby="contact-quick-edit-heading">
                <div class="contact-section-header">
                    <div>
                        <div class="contact-section-kicker">Quick Edit</div>
                        <h2 id="contact-quick-edit-heading">Update contact basics</h2>
                    </div>
                </div>
                <form method="POST" action="" class="contact-quick-edit">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="quick_update_contact">
                    <label>Phone<input type="text" name="quick_phone" value="<?php echo htmlspecialchars((string) ($contact['phone'] ?? '')); ?>" placeholder="Phone"></label>
                    <input type="hidden" name="quick_company_id" value="<?php echo htmlspecialchars((string) ($contact['company_id'] ?? '')); ?>">
                    <label>Company<input type="text" name="quick_company" value="<?php echo htmlspecialchars((string) ($contact['company'] ?? '')); ?>" placeholder="Company"></label>
                    <label>Role<input type="text" name="quick_job_title" value="<?php echo htmlspecialchars((string) ($contact['job_title'] ?? '')); ?>" placeholder="Role"></label>
                    <label>Location<input type="text" name="quick_location" value="<?php echo htmlspecialchars((string) ($contact['location'] ?? '')); ?>" placeholder="Location"></label>
                    <label>Stage
                        <select name="quick_stage">
                            <?php foreach (['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] as $stageOption): ?>
                                <option value="<?php echo htmlspecialchars($stageOption); ?>" <?php echo (($contact['stage'] ?? '') === $stageOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($stageOption)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Buying role
                        <select name="quick_buying_role">
                            <?php foreach (['unknown', 'decision_maker', 'champion', 'finance', 'blocker', 'influencer'] as $roleOption): ?>
                                <option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo (($buyingRole['suggested_role'] ?? 'unknown') === $roleOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($roleOption))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="contact-quick-edit-actions">
                        <button type="submit" class="contact-detail-action contact-detail-action--primary">Save Quick Edit</button>
                    </div>
                </form>
            </section>
        </section>

        <section class="contact-detail-panel" data-contact-tab="profile" id="contact-profile">
<div class="contact-overview-grid">
    <!-- Contact Information -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Contact Information</h2>
        
        <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Email</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo htmlspecialchars($contact['email'] ?? '-'); ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Phone</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo htmlspecialchars($contact['phone'] ?? '-'); ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Company</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php if (!empty($contact['company_id'])): ?>
                        <a href="company_view.php?id=<?php echo (int) $contact['company_id']; ?>" style="text-decoration: none; color: var(--accent-blue);">
                            <?php echo htmlspecialchars($contact['company'] ?? '-'); ?>
                        </a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($contact['company'] ?? '-'); ?>
                        <?php if (!empty($contact['company'])): ?>
                            <div style="margin-top: 6px;">
                                <a href="contact_edit.php?id=<?php echo (int) $contactId; ?>#company-link-section" style="font-size: 12px; color: var(--accent-blue); text-decoration: none;">Link to existing company</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Job Title
                    <?php echo $renderFieldSourceBadge('job_title'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['job_title']) ? htmlspecialchars($contact['job_title']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Location
                    <?php echo $renderFieldSourceBadge('location'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['location']) ? htmlspecialchars($contact['location']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <!-- Enrichment Info -->
            <div style="padding: var(--spacing-sm); background: #f8f9fa; border-radius: 4px; margin-top: var(--spacing-sm);">
                <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: 4px;">Enrichment Score</div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="background: #e0e0e0; border-radius: 4px; height: 20px; width: 200px; position: relative;">
                        <div style="background: var(--accent-blue); height: 100%; width: <?php echo min(100, ($contact['enrichment_score'] ?? 0)); ?>%; border-radius: 4px;"></div>
                    </div>
                    <span style="font-weight: 600; color: var(--midnight-black);"><?php echo $contact['enrichment_score'] ?? 0; ?>%</span>
                </div>
                <?php if (!empty($contact['enrichment_confidence'])): ?>
                <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: 4px;">Confidence Score</div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="background: #e0e0e0; border-radius: 4px; height: 20px; width: 200px; position: relative;">
                        <div style="background: #28a745; height: 100%; width: <?php echo min(100, (floatval($contact['enrichment_confidence']) * 100)); ?>%; border-radius: 4px;"></div>
                    </div>
                    <span style="font-weight: 600; color: var(--midnight-black);"><?php echo number_format((floatval($contact['enrichment_confidence']) * 100), 1); ?>%</span>
                </div>
                <?php endif; ?>
                <?php if (!empty($contact['last_enriched_at'])): ?>
                <div style="font-size: 11px; color: var(--charcoal-grey); margin-top: 4px;">
                    Last enriched: <?php echo date('M j, Y H:i', strtotime($contact['last_enriched_at'])); ?>
                </div>
                <?php else: ?>
                <div style="font-size: 11px; color: var(--charcoal-grey); margin-top: 4px;">
                    <span style="font-style: italic;">Not enriched yet</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Stage</div>
                <div>
                    <span style="background: var(--light-grey); color: var(--midnight-black); padding: 4px 12px; border-radius: 12px; font-size: 12px; text-transform: capitalize; font-weight: 500;">
                        <?php echo htmlspecialchars($contact['stage']); ?>
                    </span>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Lead Source</div>
                <div style="color: var(--midnight-black); font-weight: 500; text-transform: capitalize;">
                    <?php echo htmlspecialchars($contact['lead_source']); ?>
                </div>
            </div>

            <div class="contact-legacy-scoring-source" hidden>
            <?php
            $scoreBreakdown = null;

            if (!empty($scoringService)) {
                try {
                    $scoreBreakdown = $scoringService->getScoreBreakdown($contactId, 'conversion');
                } catch (\Exception $e) {
                    error_log("Score breakdown fetch failed for contact {$contactId}: " . $e->getMessage());
                    $scoreBreakdown = null;
                }
            }

            $displayLeadScore = (int) ($scoreBreakdown['consolidated_score'] ?? $contact['lead_score'] ?? 0);
            ?>

            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Composite Lead Score</div>
                <div>
                    <?php 
                    $score = $displayLeadScore;
                    if ($score > 0):
                        $color = $score >= 70 ? '#c33' : ($score >= 40 ? '#f90' : 'var(--accent-blue)');
                        $label = $score >= 70 ? 'Hot' : ($score >= 40 ? 'Warm' : 'Cold');
                    ?>
                        <div style="display: flex; align-items: center; gap: var(--spacing-sm);">
                            <span style="background: <?php echo $color; ?>; color: white; padding: 6px 14px; border-radius: 16px; font-size: 18px; font-weight: 600;">
                                <?php echo $score; ?>
                            </span>
                            <span style="color: var(--charcoal-grey); font-size: 14px; text-transform: uppercase; font-weight: 500;">
                                <?php echo $label; ?> Lead
                            </span>
                        </div>
                    <?php else: ?>
                        <span style="color: var(--charcoal-grey); font-size: 14px;">No score yet</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php
            // Three-Score System Display
            
            // Check if scoring service is available
            if (empty($scoringService)) {
                // Display error message when scoring service is unavailable
                ?>
                <div style="padding: var(--spacing-md); background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; margin-top: var(--spacing-md);">
                    <div style="color: #856404; font-size: 14px; font-weight: 600; margin-bottom: 8px;">⚠️ Scoring System Unavailable</div>
                    <div style="color: #856404; font-size: 12px;">
                        The scoring system could not be initialized. Please check the logs for details or contact your administrator.
                    </div>
                </div>
                <?php
            } else {
                try {
                    if ($scoreBreakdown === null) {
                        $scoreBreakdown = $scoringService->getScoreBreakdown($contactId, 'conversion');
                    }
                
                $consolidatedScore = (int) ($scoreBreakdown['consolidated_score'] ?? $contact['lead_score'] ?? 0);
                $engagementScore = (int) ($scoreBreakdown['engagement_score'] ?? $contact['engagement_score'] ?? 0);
                $mlScore = (int) ($scoreBreakdown['ml_score'] ?? $contact['ml_score'] ?? 0);
                $aiScore = (int) ($scoreBreakdown['ai_score'] ?? $contact['ai_score'] ?? 0);
                $weights = $scoreBreakdown['weights'] ?? ['engagement' => 0.4, 'ml' => 0.4, 'ai' => 0.2];
                $recommendedWeights = $scoreBreakdown['recommended_weights'] ?? null;
                $scoreSourceMetadata = $scoreBreakdown['score_source_metadata'] ?? [];
            ?>
            <div style="padding: var(--spacing-md); background: #f8f9fa; border-radius: 8px; margin-top: var(--spacing-md);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--spacing-md);">
                    <div style="color: var(--midnight-black); font-size: 16px; font-weight: 600;">Scoring Breakdown</div>
                    <?php if ($recommendedWeights): ?>
                        <button onclick="applyRecommendedWeights(<?php echo $contactId; ?>)" style="padding: 4px 12px; background: var(--accent-blue); color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;">
                            Use Recommended Weights
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Composite Lead Score -->
                <div style="margin-bottom: var(--spacing-md); padding-bottom: var(--spacing-md); border-bottom: 2px solid #dee2e6;">
                    <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: 6px; font-weight: 600;">Composite Lead Score</div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php 
                        $consolidatedColor = $consolidatedScore >= 70 ? '#c33' : ($consolidatedScore >= 40 ? '#f90' : 'var(--accent-blue)');
                        $consolidatedLabel = $consolidatedScore >= 70 ? 'Hot' : ($consolidatedScore >= 40 ? 'Warm' : 'Cold');
                        ?>
                        <span style="background: <?php echo $consolidatedColor; ?>; color: white; padding: 8px 16px; border-radius: 16px; font-size: 20px; font-weight: 600;">
                            <?php echo $consolidatedScore; ?>
                        </span>
                        <span style="color: var(--charcoal-grey); font-size: 14px; text-transform: uppercase; font-weight: 500;">
                            <?php echo $consolidatedLabel; ?> Lead
                        </span>
                    </div>
                </div>
                
                <!-- Three Individual Scores -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--spacing-md); margin-bottom: var(--spacing-md);">
                    <!-- Engagement Score -->
                    <div style="background: white; padding: var(--spacing-sm); border-radius: 6px; border-left: 4px solid #28a745;">
                        <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 4px; font-weight: 600;">Engagement Score</div>
                        <div style="font-size: 20px; font-weight: 600; color: var(--midnight-black); margin-bottom: 4px;">
                            <?php echo $engagementScore; ?>/100
                        </div>
                        <div style="font-size: 10px; color: var(--charcoal-grey);">
                            Weight: <?php echo number_format($weights['engagement'] * 100, 0); ?>%
                        </div>
                        <div style="font-size: 10px; color: var(--charcoal-grey); margin-top: 2px;">
                            Contribution: <?php echo number_format($scoreBreakdown['contributions']['engagement_contribution'] ?? 0, 1); ?> pts
                        </div>
                    </div>
                    
                    <!-- ML Score -->
                    <div style="background: white; padding: var(--spacing-sm); border-radius: 6px; border-left: 4px solid var(--accent-blue);">
                        <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 4px; font-weight: 600;">ML Score</div>
                        <?php if (($scoreBreakdown['ml_score'] ?? null) === null): ?>
                            <div style="font-size: 16px; font-weight: 600; color: var(--charcoal-grey); margin-bottom: 4px;">Unavailable</div>
                        <?php else: ?>
                            <div style="font-size: 20px; font-weight: 600; color: var(--midnight-black); margin-bottom: 4px;">
                                <?php echo $mlScore; ?>/100
                            </div>
                        <?php endif; ?>
                        <div style="font-size: 10px; color: var(--charcoal-grey);">
                            Weight: <?php echo number_format($weights['ml'] * 100, 0); ?>%
                        </div>
                        <div style="font-size: 10px; color: var(--charcoal-grey); margin-top: 2px;">
                            Contribution: <?php echo number_format($scoreBreakdown['contributions']['ml_contribution'] ?? 0, 1); ?> pts
                        </div>
                        <?php if (!empty($scoreBreakdown['ml_metadata']['available'])): ?>
                        <div style="font-size: 9px; color: #28a745; margin-top: 2px;">ML available</div>
                        <?php else: ?>
                        <div style="font-size: 9px; color: var(--charcoal-grey); margin-top: 2px;">ML unavailable; composite uses fallback weights</div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- AI Score -->
                    <div style="background: white; padding: var(--spacing-sm); border-radius: 6px; border-left: 4px solid #9c27b0;">
                        <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 4px; font-weight: 600;">AI Score</div>
                        <div style="font-size: 20px; font-weight: 600; color: var(--midnight-black); margin-bottom: 4px;">
                            <?php echo $aiScore; ?>/100
                        </div>
                        <div style="font-size: 10px; color: var(--charcoal-grey);">
                            Weight: <?php echo number_format($weights['ai'] * 100, 0); ?>%
                        </div>
                        <div style="font-size: 10px; color: var(--charcoal-grey); margin-top: 2px;">
                            Contribution: <?php echo number_format($scoreBreakdown['contributions']['ai_contribution'] ?? 0, 1); ?> pts
                        </div>
                        <?php if (!empty($scoreBreakdown['ai_metadata']['confidence'])): ?>
                        <div style="font-size: 9px; color: var(--charcoal-grey); margin-top: 2px;">
                            Confidence: <?php echo number_format($scoreBreakdown['ai_metadata']['confidence'] * 100, 0); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Weight Information -->
                <?php if ($recommendedWeights): ?>
                <div style="padding: var(--spacing-sm); background: #e3f2fd; border-radius: 4px; margin-bottom: var(--spacing-sm);">
                    <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 4px; font-weight: 600;">Recommended Weights</div>
                    <div style="font-size: 10px; color: var(--charcoal-grey);">
                        Engagement: <?php echo number_format($recommendedWeights['engagement'] * 100, 0); ?>% | 
                        ML: <?php echo number_format($recommendedWeights['ml'] * 100, 0); ?>% | 
                        AI: <?php echo number_format($recommendedWeights['ai'] * 100, 0); ?>%
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($scoreSourceMetadata)): ?>
                <div style="padding: var(--spacing-sm); background: #fff; border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: var(--spacing-sm);">
                    <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 4px; font-weight: 600;">Score Source</div>
                    <div style="font-size: 10px; color: var(--charcoal-grey); line-height: 1.5;">
                        Source: <?php echo htmlspecialchars((string) ($scoreSourceMetadata['source'] ?? 'stored')); ?> |
                        Last recalculated: <?php echo htmlspecialchars((string) ($scoreSourceMetadata['last_recalculated_at'] ?? 'not yet')); ?> |
                        Weights: <?php echo htmlspecialchars((string) ($scoreSourceMetadata['requested_weights_source'] ?? 'stored')); ?>
                    </div>
                    <?php if (!empty($scoreSourceMetadata['fallback_policy'])): ?>
                    <div style="font-size: 10px; color: var(--charcoal-grey); margin-top: 2px;">
                        Fallback: <?php echo htmlspecialchars((string) $scoreSourceMetadata['fallback_policy']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ML Top Factors -->
                <?php if (!empty($scoreBreakdown['ml_details']['top_factors'])): ?>
                <div style="margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid #dee2e6;">
                    <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: 6px; font-weight: 600;">ML Top Factors</div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <?php foreach (array_slice($scoreBreakdown['ml_details']['top_factors'], 0, 3) as $factor): ?>
                            <div style="font-size: 11px; color: var(--charcoal-grey);">
                                <span style="color: <?php echo ($factor['impact'] ?? 'positive') === 'positive' ? '#28a745' : '#dc3545'; ?>;">
                                    <?php echo ($factor['impact'] ?? 'positive') === 'positive' ? '↑' : '↓'; ?>
                                </span>
                                <?php echo htmlspecialchars($factor['feature'] ?? ''); ?>: <?php echo htmlspecialchars($factor['value'] ?? ''); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid #dee2e6;">
                    <button onclick="recalculateScore(<?php echo $contactId; ?>)" style="padding: 6px 12px; background: var(--accent-blue); color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: var(--spacing-sm);">
                        Recalculate Scores
                    </button>
                    <button onclick="showMLExplanation(<?php echo $contactId; ?>)" style="background: var(--accent-blue); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">
                        View Full Explanation
                    </button>
                </div>
            </div>
            <?php
                } catch (\Exception $e) {
                    error_log("Scoring display error for contact {$contactId}: " . $e->getMessage());
                    // Display error message to user
                    ?>
                    <div style="padding: var(--spacing-md); background: #f8d7da; border: 1px solid #dc3545; border-radius: 8px; margin-top: var(--spacing-md);">
                        <div style="color: #721c24; font-size: 14px; font-weight: 600; margin-bottom: 8px;">❌ Error Loading Scores</div>
                        <div style="color: #721c24; font-size: 12px;">
                            An error occurred while calculating scores. Please try refreshing the page or contact support if the issue persists.
                        </div>
                        <?php if ($canViewScoringDebug): ?>
                        <div style="color: #721c24; font-size: 11px; margin-top: 8px; font-family: monospace; background: rgba(0,0,0,0.1); padding: 4px; border-radius: 4px;">
                            Error: <?php echo htmlspecialchars($e->getMessage()); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
            }
            ?>
            
            <?php
            // Debug Information (Admin Only)
            if ($canViewScoringDebug):
                // Check database columns
                $dbColumns = [];
                try {
                    $columns = Database::query(
                        "SELECT COLUMN_NAME FROM information_schema.COLUMNS 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = 'contacts' 
                         AND COLUMN_NAME IN ('ai_context', 'engagement_score', 'ai_score', 'score_weights', 'recommended_weights', 'score_recalculated_at', 'score_metadata_json')"
                    );
                    foreach ($columns as $col) {
                        $dbColumns[] = $col['COLUMN_NAME'];
                    }
                } catch (\Exception $e) {
                    $dbColumns = ['error' => $e->getMessage()];
                }
            ?>
            <div style="padding: var(--spacing-sm); background: #e9ecef; border: 1px solid #dee2e6; border-radius: 4px; margin-top: var(--spacing-sm); font-size: 11px;">
                <details style="cursor: pointer;">
                    <summary style="color: var(--charcoal-grey); font-weight: 600;">🔧 Debug Information (Admin Only)</summary>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #dee2e6;">
                        <div style="margin-bottom: 4px;"><strong>Scoring Service:</strong> <?php echo $scoringService ? '✓ Initialized' : '✗ Failed'; ?></div>
                        <div style="margin-bottom: 4px;"><strong>Database Columns:</strong> <?php echo implode(', ', is_array($dbColumns) ? $dbColumns : ['Error checking']); ?></div>
                        <div style="margin-bottom: 4px;"><strong>Contact Scores:</strong> Engagement=<?php echo $contact['engagement_score'] ?? 'NULL'; ?>, ML=<?php echo $contact['ml_score'] ?? 'NULL'; ?>, AI=<?php echo $contact['ai_score'] ?? 'NULL'; ?></div>
                        <div style="margin-bottom: 4px;"><strong>Has AI Context:</strong> <?php echo !empty($contact['ai_context']) ? 'Yes (' . strlen($contact['ai_context']) . ' chars)' : 'No'; ?></div>
                        <div style="margin-bottom: 4px;"><strong>Score Source Metadata:</strong> <?php echo htmlspecialchars(json_encode($scoreSourceMetadata ?? [])); ?></div>
                    </div>
                </details>
            </div>
            <?php endif; ?>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 8px;">
                    Tags
                    <button type="button" id="suggest-tags-btn" style="padding: 2px 8px; font-size: 11px; background: var(--accent-blue); color: white; border: none; border-radius: 4px; cursor: pointer;">Suggest tags</button>
                </div>
                <?php if (!empty($contactTags)): ?>
                <div style="display: flex; flex-wrap: wrap; gap: var(--spacing-sm);">
                    <?php foreach ($contactTags as $tag): ?>
                        <span style="background: <?php echo htmlspecialchars($tag['color']); ?>; color: white; padding: 6px 12px; border-radius: 12px; font-size: 13px; font-weight: 500;">
                            <?php echo htmlspecialchars($tag['name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="color: var(--charcoal-grey); font-style: italic;">No tags</div>
                <?php endif; ?>
            </div>
            <div id="suggest-tags-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:white;padding:24px;border-radius:8px;max-width:400px;width:90%;">
                    <h3 style="margin:0 0 16px;">Suggested Tags</h3>
                    <div id="suggest-tags-list" style="margin-bottom:16px;"></div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" id="suggest-tags-cancel" style="padding:8px 16px;border:1px solid #ddd;background:white;border-radius:4px;cursor:pointer;">Cancel</button>
                        <button type="button" id="suggest-tags-apply" style="padding:8px 16px;background:var(--accent-blue);color:white;border:none;border-radius:4px;cursor:pointer;">Apply</button>
                    </div>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Created</div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo date('F j, Y g:i A', strtotime($contact['created_at'])); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Company & Professional Information -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Company & Professional Information</h2>
        
        <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Company Website
                    <?php echo $renderFieldSourceBadge('company_website'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php if (!empty($contact['company_website'])): ?>
                    <a href="<?php echo htmlspecialchars((strpos($contact['company_website'], 'http') === 0 ? '' : 'https://') . $contact['company_website']); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: none;">
                        <?php echo htmlspecialchars($contact['company_website']); ?>
                    </a>
                    <?php else: ?>
                    <span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Company Size
                    <?php if (isset($enrichmentHistory['company_size'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['company_size']) ? htmlspecialchars($contact['company_size']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Industry
                    <?php if (isset($enrichmentHistory['company_industry'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['company_industry']) ? htmlspecialchars($contact['company_industry']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Company Description
                    <?php if (isset($enrichmentHistory['company_description'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['company_description']) ? htmlspecialchars($contact['company_description']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Founded
                    <?php if (isset($enrichmentHistory['company_founded'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['company_founded']) ? htmlspecialchars($contact['company_founded']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Revenue
                    <?php if (isset($enrichmentHistory['company_revenue'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['company_revenue']) ? htmlspecialchars($contact['company_revenue']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    LinkedIn
                    <?php echo $renderFieldSourceBadge('linkedin_url'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php if (!empty($contact['linkedin_url'])): ?>
                    <a href="<?php echo htmlspecialchars($contact['linkedin_url']); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: none;">
                        <?php echo htmlspecialchars($contact['linkedin_url']); ?>
                    </a>
                    <?php else: ?>
                    <span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Twitter
                    <?php echo $renderFieldSourceBadge('twitter_url'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php if (!empty($contact['twitter_url'])): ?>
                    <a href="<?php echo htmlspecialchars($contact['twitter_url']); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: none;">
                        <?php echo htmlspecialchars($contact['twitter_url']); ?>
                    </a>
                    <?php else: ?>
                    <span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Timezone
                    <?php echo $renderFieldSourceBadge('timezone'); ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500;">
                    <?php echo !empty($contact['timezone']) ? htmlspecialchars($contact['timezone']) : '<span style="color: var(--charcoal-grey); font-style: italic;">Not available</span>'; ?>
                </div>
            </div>
            
            <div>
                <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); display: flex; align-items: center; gap: 6px;">
                    Email Verification
                    <?php if (isset($enrichmentHistory['email_verified']) || isset($enrichmentHistory['email_verification_status'])): ?>
                    <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;" title="Enriched by AI">AI</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--midnight-black); font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <?php if (isset($contact['email_verified']) && $contact['email_verified']): ?>
                    <span style="color: #28a745; font-weight: 600;">✓ Verified</span>
                    <?php elseif (isset($contact['email_verified']) && !$contact['email_verified']): ?>
                    <span style="color: #dc3545; font-weight: 600;">✗ Not Verified</span>
                    <?php else: ?>
                    <span style="color: var(--charcoal-grey); font-style: italic;">Not verified</span>
                    <?php endif; ?>
                    <?php if (!empty($contact['email_verification_status'])): ?>
                    <span style="font-size: 12px; color: var(--charcoal-grey);">(<?php echo htmlspecialchars($contact['email_verification_status']); ?>)</span>
                    <?php endif; ?>
                    <?php if (!empty($contact['email'])): ?>
                    <button 
                        onclick="verifyEmail(<?php echo $contact['id']; ?>, '<?php echo htmlspecialchars($contact['email'], ENT_QUOTES); ?>')"
                        id="verify-email-btn"
                        style="background: #17a2b8; color: white; padding: 4px 12px; border: none; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 4px;"
                        title="Verify email address using Hunter.io"
                    >
                        <span>✓</span>
                        <span>Verify Email</span>
                    </button>
                    <span id="verify-email-status" style="font-size: 12px; color: var(--charcoal-grey);"></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </div>
        </section>

        <section class="contact-detail-panel" data-contact-tab="scoring" id="contact-scoring">
    <?php
    $scoringPanelScore = (int) ($consolidatedScore ?? $displayLeadScore ?? $contact['lead_score'] ?? 0);
    $scoringPanelLabel = $scoringPanelScore >= 70 ? 'Hot' : ($scoringPanelScore >= 40 ? 'Warm' : 'Cold');
    $scoringPanelColor = $scoringPanelScore >= 70 ? '#c33' : ($scoringPanelScore >= 40 ? '#f90' : 'var(--accent-blue)');
    $scoringPanelEngagement = (int) ($engagementScore ?? $contact['engagement_score'] ?? 0);
    $scoringPanelMl = (int) ($mlScore ?? $contact['ml_score'] ?? 0);
    $scoringPanelAi = (int) ($aiScore ?? $contact['ai_score'] ?? 0);
    ?>
    <div class="contact-tab-layout contact-tab-layout--wide">
        <section class="contact-card contact-score-hero">
            <div class="contact-section-header">
                <div>
                    <div class="contact-section-kicker">Lead Scoring</div>
                    <h2>Conversion score and model breakdown</h2>
                </div>
                <span class="contact-score-badge" style="background: <?php echo $scoringPanelColor; ?>;"><?php echo $scoringPanelLabel; ?> Lead</span>
            </div>
            <div class="contact-score-layout">
                <div class="contact-score-primary">
                    <span><?php echo $scoringPanelScore; ?></span>
                    <small>/100 consolidated</small>
                </div>
                <div class="contact-score-grid">
                    <div class="contact-score-tile">
                        <span>Engagement</span>
                        <strong><?php echo $scoringPanelEngagement; ?>/100</strong>
                    </div>
                    <div class="contact-score-tile">
                        <span>ML</span>
                        <strong><?php echo $scoringPanelMl; ?>/100</strong>
                    </div>
                    <div class="contact-score-tile">
                        <span>AI</span>
                        <strong><?php echo $scoringPanelAi; ?>/100</strong>
                    </div>
                </div>
            </div>
            <?php if (!empty($protectedDemoScoreTimeline)): ?>
                <div class="protected-demo-score-timeline" data-demo-cue-key="meeting_prep_visible">
                    <?php foreach ($protectedDemoScoreTimeline as $scoreStep): ?>
                        <div class="protected-demo-score-timeline__item">
                            <span><?php echo htmlspecialchars((string) ($scoreStep['label'] ?? 'Score update')); ?></span>
                            <strong><?php echo (int) ($scoreStep['score'] ?? 0); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="contact-card">
            <div class="contact-section-header">
                <div>
                    <div class="contact-section-kicker">Score Detail</div>
                    <h2>Breakdown and actions</h2>
                </div>
            </div>
            <?php if (empty($scoringService)): ?>
                <div class="contact-alert contact-alert--warning">
                    The scoring system could not be initialized. Please check the logs or contact your administrator.
                </div>
            <?php elseif (!empty($scoreBreakdown)): ?>
                <div class="contact-score-grid contact-score-grid--detail">
                    <div class="contact-score-tile">
                        <span>Engagement weight</span>
                        <strong><?php echo number_format((float) (($weights['engagement'] ?? 0) * 100), 0); ?>%</strong>
                        <small>Contribution: <?php echo number_format((float) ($scoreBreakdown['contributions']['engagement_contribution'] ?? 0), 1); ?> pts</small>
                    </div>
                    <div class="contact-score-tile">
                        <span>ML weight</span>
                        <strong><?php echo number_format((float) (($weights['ml'] ?? 0) * 100), 0); ?>%</strong>
                        <small>Contribution: <?php echo number_format((float) ($scoreBreakdown['contributions']['ml_contribution'] ?? 0), 1); ?> pts</small>
                    </div>
                    <div class="contact-score-tile">
                        <span>AI weight</span>
                        <strong><?php echo number_format((float) (($weights['ai'] ?? 0) * 100), 0); ?>%</strong>
                        <small>Contribution: <?php echo number_format((float) ($scoreBreakdown['contributions']['ai_contribution'] ?? 0), 1); ?> pts</small>
                    </div>
                </div>
            <?php else: ?>
                <p class="contact-muted-copy">No score breakdown is available yet.</p>
            <?php endif; ?>
            <details class="contact-disclosure-card">
                <summary>
                    <span>Advanced scoring actions</span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="contact-disclosure-body">
                    <?php if (!empty($recommendedWeights)): ?>
                        <div class="contact-alert contact-alert--info">
                            Recommended weights: Engagement <?php echo number_format((float) ($recommendedWeights['engagement'] * 100), 0); ?>%, ML <?php echo number_format((float) ($recommendedWeights['ml'] * 100), 0); ?>%, AI <?php echo number_format((float) ($recommendedWeights['ai'] * 100), 0); ?>%.
                            <button onclick="applyRecommendedWeights(<?php echo $contactId; ?>)" type="button" class="contact-inline-button">Use Recommended Weights</button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($scoreBreakdown['ml_details']['top_factors'])): ?>
                        <div class="contact-score-factors">
                            <div class="contact-section-kicker">ML Top Factors</div>
                            <?php foreach (array_slice($scoreBreakdown['ml_details']['top_factors'], 0, 3) as $factor): ?>
                                <div>
                                    <strong><?php echo htmlspecialchars((string) ($factor['feature'] ?? '')); ?></strong>
                                    <span><?php echo htmlspecialchars((string) ($factor['value'] ?? '')); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="contact-button-row">
                        <button onclick="recalculateScore(<?php echo $contactId; ?>)" type="button" class="contact-detail-action contact-detail-action--primary">Recalculate Scores</button>
                        <button onclick="showMLExplanation(<?php echo $contactId; ?>)" type="button" class="contact-detail-action">View Full Explanation</button>
                    </div>
                </div>
            </details>
        </section>

        <?php if ($canViewScoringDebug): ?>
            <details class="contact-disclosure-card">
                <summary>
                    <span>Admin diagnostics</span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="contact-disclosure-body">
                    <div class="contact-section-kicker">Scoring diagnostics</div>
                    <div class="contact-debug-grid">
                        <div><strong>Scoring Service</strong><span><?php echo $scoringService ? 'Initialized' : 'Failed'; ?></span></div>
                        <div><strong>Database Columns</strong><span><?php echo htmlspecialchars(implode(', ', is_array($dbColumns ?? []) ? $dbColumns : ['Error checking'])); ?></span></div>
                        <div><strong>Contact Scores</strong><span>Engagement=<?php echo htmlspecialchars((string) ($contact['engagement_score'] ?? 'NULL')); ?>, ML=<?php echo htmlspecialchars((string) ($contact['ml_score'] ?? 'NULL')); ?>, AI=<?php echo htmlspecialchars((string) ($contact['ai_score'] ?? 'NULL')); ?></span></div>
                        <div><strong>AI Context</strong><span><?php echo !empty($contact['ai_context']) ? 'Yes (' . strlen((string) $contact['ai_context']) . ' chars)' : 'No'; ?></span></div>
                    </div>
                </div>
            </details>
        <?php endif; ?>
    </div>
        </section>

        <section class="contact-detail-panel" data-contact-tab="overview" id="contact-intelligence">
    <details class="contact-card contact-intelligence-detail" open>
        <summary style="cursor: pointer; list-style: none; font-size: 20px; font-weight: 600; color: var(--midnight-black);">Communication Intelligence</summary>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md); margin-top: var(--spacing-md);">
            <div style="background: #f8fafc; border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Preferred channel</div>
                <div style="font-size: 16px; font-weight: 700; color: var(--midnight-black);"><?php echo htmlspecialchars((string) ($communicationIntelligence['preferred_channel'] ?? 'unknown')); ?></div>
                <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 4px;">Cadence: <?php echo htmlspecialchars((string) (($communicationIntelligence['response_cadence']['label'] ?? 'insufficient_data'))); ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Sentiment trend</div>
                <div style="font-size: 16px; font-weight: 700; color: var(--midnight-black);"><?php echo htmlspecialchars((string) ($communicationIntelligence['sentiment_trend']['label'] ?? 'neutral')); ?></div>
                <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 4px;">Samples: <?php echo (int) ($communicationIntelligence['sentiment_trend']['sample_count'] ?? 0); ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 10px; padding: var(--spacing-md); grid-column: span 2;">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">What matters now</div>
                <div style="font-size: 14px; color: var(--midnight-black); line-height: 1.6;"><?php echo htmlspecialchars((string) ($communicationIntelligence['what_matters_now'] ?? 'No thread summary yet.')); ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Objections</div>
                <?php if (!empty($communicationIntelligence['objections'])): ?>
                    <ul style="margin: 0; padding-left: 18px; display: grid; gap: 6px;">
                        <?php foreach ($communicationIntelligence['objections'] as $objection): ?>
                            <li style="font-size: 13px; color: var(--midnight-black);"><?php echo htmlspecialchars((string) $objection['topic']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div style="font-size: 13px; color: var(--charcoal-grey);">No recurring objections detected.</div>
                <?php endif; ?>
            </div>
            <div style="background: #f8fafc; border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Unresolved commitments</div>
                <?php if (!empty($communicationIntelligence['unresolved_commitments'])): ?>
                    <ul style="margin: 0; padding-left: 18px; display: grid; gap: 6px;">
                        <?php foreach ($communicationIntelligence['unresolved_commitments'] as $commitment): ?>
                            <li style="font-size: 13px; color: var(--midnight-black);"><?php echo htmlspecialchars((string) $commitment['topic']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div style="font-size: 13px; color: var(--charcoal-grey);">No unresolved commitments detected.</div>
                <?php endif; ?>
            </div>
        </div>
    </details>
        </section>

        <section class="contact-detail-panel" data-contact-tab="timeline" id="contact-timeline">
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--spacing-md); flex-wrap: wrap; margin-bottom: var(--spacing-md);">
            <h2 style="margin: 0; font-size: 20px; color: var(--midnight-black);">Relationship Timeline</h2>
            <div id="timeline-filters" style="display: flex; gap: 8px; flex-wrap: wrap;">
                <?php foreach (['all' => 'All', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'notes' => 'Notes', 'deals' => 'Deals', 'invoices' => 'Invoices', 'tasks' => 'Tasks', 'events' => 'Meetings'] as $filterKey => $filterLabel): ?>
                    <button type="button" class="timeline-filter-btn" data-filter="<?php echo htmlspecialchars($filterKey); ?>" style="border: 1px solid var(--border-color); background: <?php echo $filterKey === 'all' ? '#eff6ff' : 'white'; ?>; color: var(--midnight-black); border-radius: 999px; padding: 7px 12px; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo htmlspecialchars($filterLabel); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (!empty($unifiedTimeline)): ?>
            <div id="unified-timeline" style="display: grid; gap: var(--spacing-sm);">
                <?php foreach ($unifiedTimeline as $timelineItem): ?>
                    <div class="timeline-row" data-filter="<?php echo htmlspecialchars((string) ($timelineItem['filter'] ?? 'all')); ?>" style="border: 1px solid var(--border-color); border-radius: 10px; padding: var(--spacing-md);">
                        <div style="display: flex; justify-content: space-between; gap: var(--spacing-md); flex-wrap: wrap; margin-bottom: 6px;">
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo htmlspecialchars((string) ($timelineItem['title'] ?? 'Activity')); ?></div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($timelineItem['created_at'] ?? 'now')))); ?></div>
                        </div>
                        <div style="font-size: 13px; color: var(--charcoal-grey);"><?php echo htmlspecialchars((string) ($timelineItem['detail'] ?? '')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php if (!empty($activities)): ?>
                <div style="font-size: 14px; color: var(--charcoal-grey);">No unified relationship timeline yet. Showing recent activity below.</div>
            <?php else: ?>
                <div style="font-size: 14px; color: var(--charcoal-grey);">No relationship timeline activity yet. Send the first message, create a task, or add a note to start the history.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
        </section>

        <section class="contact-detail-panel" data-contact-tab="profile" id="contact-account">
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <h2 style="margin: 0 0 var(--spacing-md); font-size: 20px; color: var(--midnight-black);">Company and Account Context</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--spacing-md);">
            <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Related contacts</div>
                <?php if (!empty($accountContext['related_contacts'])): ?>
                    <div style="display: grid; gap: 8px;">
                        <?php foreach ($accountContext['related_contacts'] as $relatedContact): ?>
                            <a href="contact_view.php?id=<?php echo (int) $relatedContact['id']; ?>" style="text-decoration: none; color: var(--midnight-black); border: 1px solid #edf2f7; border-radius: 8px; padding: 8px 10px;">
                                <div style="font-size: 13px; font-weight: 600;"><?php echo htmlspecialchars(trim((string) (($relatedContact['first_name'] ?? '') . ' ' . ($relatedContact['last_name'] ?? '')))); ?></div>
                                <div style="font-size: 12px; color: var(--charcoal-grey);"><?php echo htmlspecialchars((string) ($relatedContact['job_title'] ?? $relatedContact['email'] ?? '')); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size: 13px; color: var(--charcoal-grey);">No related contacts from the same company/domain yet.</div>
                <?php endif; ?>
            </div>
            <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Shared records</div>
                <div style="font-size: 13px; color: var(--midnight-black); line-height: 1.8;">
                    <div>Deals: <?php echo count($accountContext['shared_deals'] ?? []); ?></div>
                    <div>Invoices: <?php echo count($accountContext['shared_invoices'] ?? []); ?></div>
                    <div>Tasks: <?php echo count($accountContext['shared_tasks'] ?? []); ?></div>
                    <div>Domain: <?php echo htmlspecialchars((string) ($accountContext['email_domain'] ?? 'unknown')); ?></div>
                </div>
            </div>
            <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: var(--spacing-md);">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px;">Data quality</div>
                <div style="font-size: 13px; color: var(--midnight-black); line-height: 1.8;">
                    <div>Incomplete: <?php echo !empty($dataQuality['is_incomplete']) ? 'Yes' : 'No'; ?></div>
                    <div>Stale: <?php echo !empty($dataQuality['is_stale']) ? 'Yes' : 'No'; ?></div>
                    <div>Possible duplicates: <?php echo count($dataQuality['likely_duplicates'] ?? []); ?></div>
                </div>
                <?php if (!empty($dataQuality['missing_critical_fields'])): ?>
                    <div style="margin-top: 8px; font-size: 12px; color: var(--charcoal-grey);">Missing: <?php echo htmlspecialchars(implode(', ', $dataQuality['missing_critical_fields'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

        </section>

        <section class="contact-detail-panel" data-contact-tab="ai-context" id="contact-ai-context">

    <!-- AI Context Section -->
    <?php 
    $aiContext = null;
    $hasAiContext = false;
    if (!empty($contact['ai_context'])) {
        $aiContext = json_decode($contact['ai_context'], true);
        // Check if AI context has any meaningful data
        if (is_array($aiContext)) {
            $hasAiContext = !empty($aiContext['summary']) || 
                           (!empty($aiContext['insights']) && is_array($aiContext['insights'])) ||
                           (!empty($aiContext['recommendations']) && is_array($aiContext['recommendations'])) ||
                           (!empty($aiContext['analysis']));
        }
    }
    ?>
    <?php if ($hasAiContext): ?>
    <section class="contact-card contact-ai-context-card" aria-label="AI Insights and Context">
        <div class="contact-ai-context-header">
            <div>
                <div class="contact-section-kicker">AI Context</div>
                <h2>AI Insights & Context</h2>
            </div>
            <h2 style="color: var(--midnight-black); margin: 0; font-size: 20px;">🤖 AI Insights & Context</h2>
            <span class="contact-ai-context-status">
                <?php echo !empty($aiContext['generated_at']) ? 'Generated: ' . date('M j, Y H:i', strtotime($aiContext['generated_at'])) : 'Ready to review'; ?>
            </span>
        </div>
        <div class="contact-ai-context-body">
        
        <!-- Summary -->
        <?php if (!empty($aiContext['summary'])): ?>
        <div style="background: #f0f7ff; border-left: 4px solid var(--accent-blue); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <p style="color: var(--midnight-black); margin: 0; line-height: 1.6;">
                <?php echo htmlspecialchars($aiContext['summary']); ?>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Insights -->
        <?php if (!empty($aiContext['insights']) && is_array($aiContext['insights'])): ?>
        <div style="margin-bottom: var(--spacing-md);">
            <h3 style="color: var(--midnight-black); font-size: 16px; margin-bottom: var(--spacing-sm);">Key Insights</h3>
            <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                <?php foreach ($aiContext['insights'] as $insight): ?>
                <div style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px; border-left: 3px solid var(--accent-blue);">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-xs);">
                        <span style="font-weight: 600; color: var(--midnight-black); text-transform: capitalize;">
                            <?php echo htmlspecialchars(str_replace('_', ' ', $insight['type'] ?? 'insight')); ?>
                        </span>
                        <?php if (isset($insight['confidence'])): ?>
                        <span style="font-size: 11px; color: var(--charcoal-grey);">
                            Confidence: <?php echo number_format((floatval($insight['confidence']) * 100), 0); ?>%
                        </span>
                        <?php endif; ?>
                    </div>
                    <p style="color: var(--midnight-black); margin: var(--spacing-xs) 0; line-height: 1.5;">
                        <?php echo htmlspecialchars($insight['conclusion'] ?? ''); ?>
                    </p>
                    <?php if (!empty($insight['reasoning'])): ?>
                    <p style="font-size: 12px; color: var(--charcoal-grey); margin: var(--spacing-xs) 0 0 0; font-style: italic;">
                        <?php echo htmlspecialchars($insight['reasoning']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($insight['source'])): ?>
                    <span style="font-size: 11px; color: var(--charcoal-grey); margin-top: var(--spacing-xs); display: block;">
                        Source: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $insight['source']))); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recommendations -->
        <?php if (!empty($aiContext['recommendations']) && is_array($aiContext['recommendations'])): ?>
        <div style="margin-bottom: var(--spacing-md);">
            <h3 style="color: var(--midnight-black); font-size: 16px; margin-bottom: var(--spacing-sm);">Recommendations</h3>
            <ul style="margin: 0; padding-left: 20px; color: var(--midnight-black); line-height: 1.6;">
                <?php foreach ($aiContext['recommendations'] as $recommendation): ?>
                <li style="margin-bottom: var(--spacing-xs);">
                    <?php echo htmlspecialchars($recommendation); ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Analysis Details -->
        <?php if (!empty($aiContext['analysis'])): ?>
        <div style="margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--border-color);">
            <h3 style="color: var(--midnight-black); font-size: 16px; margin-bottom: var(--spacing-sm);">Analysis Details</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-sm);">
                <?php if (isset($aiContext['analysis']['data_completeness'])): ?>
                <div style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px;">
                    <div style="font-size: 12px; color: var(--charcoal-grey);">Data Completeness</div>
                    <div style="font-weight: 600; color: var(--midnight-black);">
                        <?php echo number_format($aiContext['analysis']['data_completeness'], 1); ?>%
                    </div>
                </div>
                <?php endif; ?>
                <?php if (isset($aiContext['analysis']['data_quality'])): ?>
                <div style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px;">
                    <div style="font-size: 12px; color: var(--charcoal-grey);">Data Quality</div>
                    <div style="font-weight: 600; color: var(--midnight-black); text-transform: capitalize;">
                        <?php echo htmlspecialchars($aiContext['analysis']['data_quality']); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($aiContext['analysis']['professional_profile']['role_level'])): ?>
                <div style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px;">
                    <div style="font-size: 12px; color: var(--charcoal-grey);">Role Level</div>
                    <div style="font-weight: 600; color: var(--midnight-black); text-transform: capitalize;">
                        <?php echo htmlspecialchars($aiContext['analysis']['professional_profile']['role_level']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </section>
    <?php elseif (!empty($contact['ai_context'])): ?>
    <!-- AI Context exists but is empty/incomplete -->
    <section class="contact-card contact-ai-context-card" aria-label="AI Insights and Context">
        <div class="contact-ai-context-header">
            <div>
                <div class="contact-section-kicker">AI Context</div>
                <h2>AI Insights & Context</h2>
            </div>
            <h2 style="color: var(--midnight-black); margin: 0; font-size: 20px;">🤖 AI Insights & Context</h2>
            <span class="contact-ai-context-status">Needs enrichment</span>
        </div>
        <div class="contact-ai-context-body">
        <div style="padding: var(--spacing-md); background: #f8f9fa; border-radius: 4px; text-align: center;">
            <p style="color: var(--charcoal-grey); margin: 0; font-style: italic;">
                AI context data exists but is incomplete. Run enrichment to generate insights.
            </p>
        </div>
        </div>
    </section>
    <?php else: ?>
    <!-- On-demand AI Summary -->
    <section id="ai-summary-card" class="contact-card contact-ai-context-card" aria-label="AI Summary">
        <div class="contact-ai-context-header">
            <div>
                <div class="contact-section-kicker">AI Context</div>
                <h2>AI Summary</h2>
            </div>
            <h2 style="color: var(--midnight-black); margin: 0; font-size: 20px;">🤖 AI Summary</h2>
            <span class="contact-ai-context-status">Generate on demand</span>
        </div>
        <div class="contact-ai-context-body">
        <div id="ai-summary-placeholder" style="padding: var(--spacing-md); background: #f0f7ff; border-radius: 4px; text-align: center;">
            <p style="color: var(--charcoal-grey); margin: 0 0 var(--spacing-sm);">
                Generate an AI-powered summary of this contact based on their profile and activities.
            </p>
            <button type="button" id="generate-ai-summary-btn" style="padding: var(--spacing-sm) var(--spacing-md); background: var(--accent-blue); color: white; border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                Generate AI Summary
            </button>
            <p id="ai-summary-status" style="display:none; margin: var(--spacing-sm) 0 0; color: #7c2d12; font-size: 13px;"></p>
        </div>
        <div id="ai-summary-result" style="display: none; margin-top: var(--spacing-md);">
            <div style="background: #f0f7ff; border-left: 4px solid var(--accent-blue); padding: var(--spacing-md); border-radius: 4px;">
                <p id="ai-summary-text" style="color: var(--midnight-black); margin: 0; line-height: 1.6;"></p>
            </div>
        </div>
        </div>
    </section>
    <?php endif; ?>
    <?php if (!empty($recentEnrichmentAudit)): ?>
    <section class="contact-card contact-ai-context-card" aria-label="Enrichment Audit">
        <div class="contact-ai-context-header">
            <div>
                <div class="contact-section-kicker">Enrichment Audit</div>
                <h2>Recent enrichment activity</h2>
            </div>
            <span class="contact-ai-context-status"><?php echo count($recentEnrichmentAudit); ?> recent run<?php echo count($recentEnrichmentAudit) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="contact-ai-context-body">
            <?php foreach ($recentEnrichmentAudit as $auditRow): ?>
                <?php
                $auditResponse = json_decode((string) ($auditRow['ai_response'] ?? ''), true);
                $verifiedFields = is_array($auditResponse['verified_fields_updated'] ?? null) ? $auditResponse['verified_fields_updated'] : [];
                $contextGenerated = !empty($auditResponse['context_generated']) || !empty($auditResponse['ai_context']);
                $status = trim((string) ($auditRow['status'] ?? ''));
                ?>
                <div style="border: 1px solid var(--contact-border); border-radius: 8px; padding: var(--spacing-md);">
                    <div style="display: flex; justify-content: space-between; gap: var(--spacing-md); align-items: start; margin-bottom: var(--spacing-xs); flex-wrap: wrap;">
                        <div style="font-weight: 600; color: var(--midnight-black); text-transform: capitalize;">
                            <?php echo htmlspecialchars(str_replace('_', ' ', (string) $auditRow['enrichment_type'])); ?>
                        </div>
                        <div style="font-size: 12px; color: var(--charcoal-grey);">
                            <?php echo date('M j, Y g:i A', strtotime((string) $auditRow['created_at'])); ?>
                        </div>
                    </div>
                    <div style="font-size: 13px; color: var(--charcoal-grey); line-height: 1.55;">
                        <?php if (!empty($verifiedFields)): ?>
                            Verified fields updated: <?php echo htmlspecialchars(implode(', ', $verifiedFields)); ?>.
                        <?php elseif ($contextGenerated): ?>
                            Context enriched without changing authoritative profile fields.
                        <?php else: ?>
                            No verified field changes recorded.
                        <?php endif; ?>
                        <?php if ($status !== ''): ?>
                            Status: <?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?>.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
        </section>

        <section class="contact-detail-panel" data-contact-tab="meeting-prep" id="contact-meeting-prep">
    
    <!-- Meeting Prep -->
    <section id="meeting-prep-card" class="contact-card contact-meeting-prep-card">
        <div class="contact-section-header">
            <div>
                <div class="contact-section-kicker">Meeting Prep</div>
                <h2>Prep the next conversation</h2>
            </div>
            <span class="contact-role-pill">Generate on demand</span>
        </div>
        <div id="meeting-prep-placeholder" class="contact-meeting-prep-workspace" <?php echo !empty($protectedDemoMeetingPrep) ? 'style="display:none;"' : ''; ?>>
            <div class="contact-meeting-prep-intro">
                <p class="contact-muted-copy">
                    Generate a one-click meeting brief with key points, open questions, suggested topics, and follow-up angles for this contact.
                </p>
                <button type="button" id="generate-meeting-prep-btn" class="contact-detail-action contact-detail-action--primary">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>Generate Meeting Prep
                </button>
                <p id="meeting-prep-status" style="display:none; margin: var(--spacing-sm) 0 0; color: #7c2d12; font-size: 13px;"></p>
            </div>
            <div class="contact-meeting-prep-grid" aria-label="Meeting prep output areas">
                <div class="contact-meeting-prep-tile">
                    <span>Conversation brief</span>
                    <p>Summarize the relationship, current state, and what matters most before the meeting.</p>
                </div>
                <div class="contact-meeting-prep-tile">
                    <span>Open questions</span>
                    <p>Surface unresolved needs, blockers, commitments, and useful discovery prompts.</p>
                </div>
                <div class="contact-meeting-prep-tile">
                    <span>Suggested topics</span>
                    <p>Identify the best agenda angles from activity, notes, deals, and AI context.</p>
                </div>
            </div>
        </div>
        <div id="meeting-prep-result" style="<?php echo !empty($protectedDemoMeetingPrep) ? 'display:block;' : 'display:none;'; ?> margin-top: var(--spacing-md);" data-demo-cue-key="meeting_prep_visible">
            <div style="background: #f0f7ff; border-left: 4px solid var(--accent-blue); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
                <p id="meeting-prep-summary" style="color: var(--midnight-black); margin: 0; line-height: 1.6;"><?php echo htmlspecialchars((string) ($protectedDemoMeetingPrep['summary'] ?? '')); ?></p>
            </div>
            <div id="meeting-prep-details" style="display: grid; gap: var(--spacing-md);">
                <?php if (!empty($protectedDemoMeetingPrep)): ?>
                    <?php foreach ([
                        'key_points' => 'Key Points',
                        'open_questions' => 'Open Questions',
                        'suggested_topics' => 'Suggested Topics',
                    ] as $prepKey => $prepLabel): ?>
                        <?php if (!empty($protectedDemoMeetingPrep[$prepKey]) && is_array($protectedDemoMeetingPrep[$prepKey])): ?>
                            <div class="protected-demo-meeting-prep-list">
                                <h4><?php echo htmlspecialchars($prepLabel); ?></h4>
                                <ul>
                                    <?php foreach ($protectedDemoMeetingPrep[$prepKey] as $item): ?>
                                        <li><?php echo htmlspecialchars((string) $item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
        </section>

    <!-- Custom Fields -->
    <?php if (!empty($customFields)): 
        $hasCustomFieldValues = false;
        foreach ($customFields as $field) {
            if (isset($customFieldValuesMap[$field['id']]) && $customFieldValuesMap[$field['id']] !== '') {
                $hasCustomFieldValues = true;
                break;
            }
        }
    ?>
        <?php if ($hasCustomFieldValues): ?>
        <section class="contact-detail-panel" data-contact-tab="profile">
            <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
                <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Custom Fields</h2>
                
                <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                    <?php 
                    // Helper function to get label
                    function getFieldLabel($field) {
                        if ($field['field_options']) {
                            $decoded = json_decode($field['field_options'], true);
                            if (is_array($decoded) && isset($decoded['_label'])) {
                                return $decoded['_label'];
                            }
                        }
                        return ucfirst(str_replace('_', ' ', $field['field_name']));
                    }
                    
                    foreach ($customFields as $field): 
                        $value = $customFieldValuesMap[$field['id']] ?? null;
                        if ($value === null || $value === '') continue;
                        $fieldLabel = getFieldLabel($field);
                    ?>
                        <div>
                            <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">
                                <?php echo htmlspecialchars($fieldLabel); ?>
                            </div>
                            <div style="color: var(--midnight-black); font-weight: 500;">
                                <?php 
                                if ($field['field_type'] === 'checkbox') {
                                    echo $value ? 'Yes' : 'No';
                                } elseif ($field['field_type'] === 'date') {
                                    echo date('F j, Y', strtotime($value));
                                } else {
                                    echo htmlspecialchars($value);
                                }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php endif; ?>

        <section class="contact-detail-panel" data-contact-tab="timeline">
    <?php if (empty($unifiedTimeline)): ?>
    <!-- Activity Timeline -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 20px;">Activity Timeline</h2>
        
        <?php if (empty($activities)): ?>
            <p style="color: var(--charcoal-grey);">No activities yet.</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                <?php foreach ($activities as $activity): ?>
                    <div style="padding: var(--spacing-sm); border-left: 3px solid var(--accent-blue); padding-left: var(--spacing-md);">
                        <div style="font-weight: 500; color: var(--midnight-black); margin-bottom: var(--spacing-xs);">
                            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($activity['activity_type']))); ?>
                        </div>
                        <?php if ($activity['description']): ?>
                            <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">
                                <?php echo htmlspecialchars($activity['description']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="color: var(--charcoal-grey); font-size: 12px;">
                            <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
        </section>

        <section class="contact-detail-panel" data-contact-tab="notes">
    <!-- Notes Section -->
    <div id="notes-section" style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md); flex-wrap: wrap; gap: var(--spacing-sm);">
            <h2 style="color: var(--midnight-black); font-size: 20px; margin: 0;">Notes</h2>
            <div style="display: flex; align-items: center; gap: var(--spacing-sm);">
                <span style="color: var(--charcoal-grey); font-size: 14px;">
                    <?php echo count($contactNotes); ?> note<?php echo count($contactNotes) !== 1 ? 's' : ''; ?>
                </span>
                <?php if (!empty($contactNotes)): ?>
                <button type="button" id="summarize-notes-btn" style="padding: var(--spacing-xs) var(--spacing-sm); background: var(--light-grey); border: 1px solid var(--border-color); border-radius: 4px; font-size: 13px; cursor: pointer;">Summarize notes</button>
                <?php endif; ?>
            </div>
        </div>
        <div id="notes-summary-result" style="display: none; margin-bottom: var(--spacing-md); padding: var(--spacing-md); background: #f0f7ff; border-left: 4px solid var(--accent-blue); border-radius: 4px;">
            <p id="notes-summary-text" style="margin: 0; line-height: 1.6;"></p>
        </div>
        
        <!-- Create Note Form -->
        <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <?php if ($noteSuccess): ?>
                <div style="background: #efe; border: 1px solid #cfc; color: #2f7d32; padding: var(--spacing-sm); border-radius: 4px; margin-bottom: var(--spacing-sm); font-size: 14px;">
                    Note saved successfully.
                </div>
            <?php endif; ?>
            <?php if ($noteError): ?>
                <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; margin-bottom: var(--spacing-sm); font-size: 14px;">
                    <?php echo htmlspecialchars($noteError); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="create_note">
                
                <input 
                    type="text" 
                    name="note_title" 
                    placeholder="Note title (optional)"
                    value="<?php echo htmlspecialchars($noteFormTitle); ?>"
                    style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                >
                
                <div class="rich-text-editor" data-rich-text-lazy="visible" style="margin-bottom: var(--spacing-sm);">
                    <textarea 
                        name="note_content" 
                        required
                        rows="3"
                        placeholder="Add a note about this contact..."
                        style="width: 100%; min-height: 110px; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; resize: vertical;"
                    ><?php echo htmlspecialchars($noteFormContent); ?></textarea>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="display: flex; align-items: center; gap: var(--spacing-xs); cursor: pointer; font-size: 14px; color: var(--charcoal-grey);">
                        <input 
                            type="checkbox" 
                            name="note_private" 
                            value="1"
                            <?php echo $noteFormPrivate ? 'checked' : ''; ?>
                            style="width: 16px; height: 16px;"
                        >
                        <span>Private note (only visible to me)</span>
                    </label>
                    <button 
                        type="submit" 
                        style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 14px;"
                    >
                        Add Note
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Notes List -->
        <?php if (empty($contactNotes)): ?>
            <p style="color: var(--charcoal-grey); text-align: center; padding: var(--spacing-lg);">
                No notes yet. Add your first note above.
            </p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                <?php foreach ($contactNotes as $note): ?>
                    <?php $noteContentPlain = trim(strip_tags((string) ($note['content'] ?? ''))); ?>
                    <div style="padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-sm);">
                            <div style="flex: 1;">
                                <?php if ($note['title']): ?>
                                    <div style="font-weight: 600; color: var(--midnight-black); margin-bottom: var(--spacing-xs); font-size: 16px;">
                                        <?php echo htmlspecialchars($note['title']); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px; margin-bottom: var(--spacing-xs);">
                                    <?php echo htmlspecialchars($note['created_by_email'] ?? 'Unknown'); ?>
                                    <?php if ($note['is_private']): ?>
                                        <span style="color: #f90; margin-left: var(--spacing-xs);">🔒 Private</span>
                                    <?php endif; ?>
                                    • <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                </div>
                                <?php if (!empty($note['mentions'])): ?>
                                    <div style="display: flex; flex-wrap: wrap; gap: var(--spacing-xs); margin-top: var(--spacing-xs);">
                                        <span style="color: var(--charcoal-grey); font-size: 11px;">Mentioned:</span>
                                        <?php foreach ($note['mentions'] as $mention): ?>
                                            <span style="background: var(--light-grey); color: var(--midnight-black); padding: 2px 8px; border-radius: 10px; font-size: 11px;">
                                                @<?php echo htmlspecialchars(explode('@', $mention['user_email'] ?? '')[0] ?? 'user'); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 4px;">
                                <?php if (strlen($noteContentPlain) > 20): ?>
                                <button type="button" class="extract-actions-btn" data-note-id="<?php echo (int)$note['id']; ?>" style="background: none; border: none; color: var(--accent-blue); cursor: pointer; font-size: 12px; padding: 4px 8px;" title="Extract action items">Extract actions</button>
                                <?php endif; ?>
                                <?php if ($note['created_by'] == $userId || $canManageAllNotes): ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="delete_note">
                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                    <button 
                                        type="submit" 
                                        style="background: none; border: none; color: #c33; cursor: pointer; font-size: 12px; padding: 4px 8px;"
                                        title="Delete note"
                                    >
                                        Delete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div id="note-actions-<?php echo (int)$note['id']; ?>" class="note-extracted-actions" style="display: none; margin-top: var(--spacing-sm); padding: var(--spacing-sm); background: #f0f7ff; border-radius: 4px;"></div>
                        <div style="color: var(--midnight-black); font-size: 14px; line-height: 1.6;" class="note-content">
                            <?php 
                            // Check if content is HTML (starts with <) or plain text
                            $content = $note['content'];
                            
                            // Highlight mentions in content
                            if (!empty($note['mentions'])) {
                                foreach ($note['mentions'] as $mention) {
                                    $email = $mention['user_email'] ?? '';
                                    $username = explode('@', $email)[0] ?? '';
                                    // Highlight @username mentions (works for both HTML and plain text)
                                    $content = preg_replace(
                                        '/@' . preg_quote($username, '/') . '\b/',
                                        '<span style="background: #e3f2fd; color: var(--accent-blue); padding: 2px 4px; border-radius: 3px; font-weight: 500;">@' . htmlspecialchars($username) . '</span>',
                                        $content
                                    );
                                }
                            }
                            
                            if (!empty($content) && (strpos($content, '<p>') !== false || strpos($content, '<br>') !== false || strpos($content, '<strong>') !== false || strpos($content, '<em>') !== false)) {
                                // HTML content - sanitize and display
                                echo strip_tags($content, '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><a><span>');
                            } else {
                                // Plain text - convert to HTML
                                echo nl2br(htmlspecialchars($content));
                            }
                            ?>
                        </div>
                        <div style="margin-top: var(--spacing-sm);">
                            <button type="button" class="reply-toggle" data-note-id="<?php echo $note['id']; ?>" style="background: none; border: none; color: var(--accent-blue); cursor: pointer; font-size: 12px;">Reply</button>
                            <div class="reply-form-wrap" id="reply-form-<?php echo $note['id']; ?>" style="display: none; margin-top: 8px;">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="create_reply">
                                    <input type="hidden" name="parent_note_id" value="<?php echo $note['id']; ?>">
                                    <textarea name="reply_content" rows="2" placeholder="Write a reply..." style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                                    <button type="submit" style="margin-top: 4px; padding: 4px 12px; background: var(--accent-blue); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Post Reply</button>
                                </form>
                            </div>
                        </div>
                        <?php if (!empty($note['replies'])): ?>
                        <div style="margin-top: var(--spacing-md); margin-left: 24px; padding-left: 16px; border-left: 3px solid var(--border-color);">
                            <?php foreach ($note['replies'] as $reply): ?>
                            <div style="padding: var(--spacing-sm) 0; border-bottom: 1px solid #eee;">
                                <div style="color: var(--charcoal-grey); font-size: 11px;"><?php echo htmlspecialchars($reply['created_by_email'] ?? 'Unknown'); ?> • <?php echo date('M j, g:i A', strtotime($reply['created_at'])); ?></div>
                                <div style="font-size: 13px; margin-top: 4px;"><?php echo nl2br(htmlspecialchars(strip_tags($reply['content'] ?? ''))); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
        </section>

        <section class="contact-detail-panel" data-contact-tab="documents" id="contact-documents">
    <!-- Documents Section -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
            <h2 style="color: var(--midnight-black); font-size: 20px; margin: 0;">Documents</h2>
            <span style="color: var(--charcoal-grey); font-size: 14px;">
                <?php echo count($contactDocuments); ?> file<?php echo count($contactDocuments) !== 1 ? 's' : ''; ?>
            </span>
        </div>
        
        <!-- Upload Form -->
        <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <form id="documentUploadForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="entity_type" value="contact">
                <input type="hidden" name="entity_id" value="<?php echo $contactId; ?>">
                
                <div>
                    <input 
                        type="file" 
                        id="documentFile" 
                        name="file" 
                        required
                        accept="*/*"
                        style="width: 100%; padding: var(--spacing-xs); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        Maximum file size: 10MB
                    </small>
                </div>
                
                <input 
                    type="text" 
                    name="description" 
                    placeholder="Description (optional)"
                    style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                >
                
                <?php if (!empty($documentCategories)): ?>
                    <select 
                        name="category_id" 
                        style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                    >
                        <option value="">No Category</option>
                        <?php foreach ($documentCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                
                <button 
                    type="submit" 
                    style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 14px; align-self: flex-start;"
                >
                    Upload Document
                </button>
            </form>
        </div>
        
        <!-- Documents List -->
        <?php if (empty($contactDocuments)): ?>
            <p style="color: var(--charcoal-grey); text-align: center; padding: var(--spacing-lg);">
                No documents yet. Upload your first document above.
            </p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: var(--spacing-md);">
                <?php foreach ($contactDocuments as $doc): ?>
                    <div style="padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; background: white;">
                        <div style="display: flex; align-items: start; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                            <div style="font-size: 32px;">
                                <?php echo $documentsModule->getFileIcon($doc['mime_type'] ?? ''); ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: var(--spacing-xs); margin-bottom: var(--spacing-xs);">
                                    <div style="font-weight: 500; color: var(--midnight-black); font-size: 14px; word-break: break-word; flex: 1;">
                                        <?php echo htmlspecialchars($doc['original_name']); ?>
                                    </div>
                                    <?php if (!empty($doc['category_name'])): ?>
                                        <span style="background: <?php echo htmlspecialchars($doc['category_color'] ?? '#3B82F6'); ?>; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; white-space: nowrap;">
                                            <?php echo htmlspecialchars($doc['category_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">
                                    <?php echo $documentsModule->formatFileSize($doc['file_size']); ?>
                                </div>
                                <?php if ($doc['description']): ?>
                                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                                        <?php echo htmlspecialchars($doc['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid var(--border-color);">
                            <div style="color: var(--charcoal-grey); font-size: 11px;">
                                <?php echo htmlspecialchars($doc['uploaded_by_email'] ?? 'Unknown'); ?><br>
                                <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                            </div>
                            <div style="display: flex; gap: var(--spacing-xs); flex-wrap: wrap;">
                                <?php if ($documentsModule->canPreview($doc['mime_type'] ?? '')): ?>
                                    <a 
                                        href="<?php echo $documentsModule->getPreviewUrl($doc['id']); ?>" 
                                        target="_blank"
                                        style="color: var(--accent-blue); text-decoration: none; font-size: 12px; font-weight: 500;"
                                    >
                                        Preview
                                    </a>
                                <?php endif; ?>
                                <a 
                                    href="document_download.php?id=<?php echo $doc['id']; ?>" 
                                    style="color: var(--accent-blue); text-decoration: none; font-size: 12px; font-weight: 500;"
                                >
                                    Download
                                </a>
                                <?php 
                                $versionHistory = $doc['version_history'] ?? [];
                                $versionCount = count($versionHistory);
                                if ($versionCount > 1): 
                                ?>
                                    <button 
                                        onclick="toggleVersionHistory(<?php echo $doc['id']; ?>)" 
                                        style="background: none; border: none; color: var(--accent-blue); cursor: pointer; font-size: 12px; padding: 0; font-weight: 500;"
                                    >
                                        Versions (<?php echo $versionCount; ?>)
                                    </button>
                                <?php endif; ?>
                                <?php if ($doc['uploaded_by'] == $userId || $canManageAllDocuments): ?>
                                    <button 
                                        onclick="showUploadVersionForm(<?php echo $doc['id']; ?>)" 
                                        style="background: none; border: none; color: var(--accent-blue); cursor: pointer; font-size: 12px; padding: 0; font-weight: 500;"
                                    >
                                        New Version
                                    </button>
                                    <button 
                                        onclick="deleteDocument(<?php echo $doc['id']; ?>)" 
                                        style="background: none; border: none; color: #c33; cursor: pointer; font-size: 12px; padding: 0;"
                                    >
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Version History (Hidden by default) -->
                        <div id="version_history_<?php echo $doc['id']; ?>" style="display: none; margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--border-color);">
                            <div style="color: var(--charcoal-grey); font-size: 12px; font-weight: 600; margin-bottom: var(--spacing-sm);">Version History:</div>
                            <div style="display: flex; flex-direction: column; gap: var(--spacing-xs);">
                                <?php foreach ($versionHistory as $version): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--spacing-xs); background: var(--light-grey); border-radius: 4px;">
                                        <div style="flex: 1;">
                                            <div style="font-size: 12px; color: var(--midnight-black);">
                                                <strong>v<?php echo $version['version_number']; ?></strong>
                                                <?php if (($doc['current_version'] ?? 1) == $version['version_number']): ?>
                                                    <span style="background: #3c3; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: var(--spacing-xs);">Current</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--charcoal-grey);">
                                                <?php echo $documentsModule->formatFileSize($version['file_size']); ?>
                                                • <?php echo htmlspecialchars($version['uploaded_by_email'] ?? 'Unknown'); ?>
                                                • <?php echo date('M j, Y g:i A', strtotime($version['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: var(--spacing-xs);">
                                            <a 
                                                href="document_version_download.php?document_id=<?php echo $doc['id']; ?>&version=<?php echo $version['version_number']; ?>" 
                                                style="color: var(--accent-blue); text-decoration: none; font-size: 11px; font-weight: 500;"
                                            >
                                                Download
                                            </a>
                                            <?php if (($doc['current_version'] ?? 1) != $version['version_number'] && ($doc['uploaded_by'] == $userId || $canManageAllDocuments)): ?>
                                                <button 
                                                    onclick="restoreVersion(<?php echo $doc['id']; ?>, <?php echo $version['version_number']; ?>, <?php echo (int) ($doc['lock_version'] ?? 0); ?>)" 
                                                    style="background: none; border: none; color: var(--accent-blue); cursor: pointer; font-size: 11px; padding: 0; font-weight: 500;"
                                                >
                                                    Restore
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Upload Version Form (Hidden by default) -->
                        <div id="upload_version_form_<?php echo $doc['id']; ?>" style="display: none; margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--border-color);">
                            <form class="versionUploadForm" data-document-id="<?php echo $doc['id']; ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($doc['lock_version'] ?? 0); ?>">
                                
                                <div>
                                    <input 
                                        type="file" 
                                        name="file" 
                                        required
                                        accept="*/*"
                                        style="width: 100%; padding: var(--spacing-xs); border: 1px solid var(--border-color); border-radius: 4px; font-size: 12px;"
                                    >
                                    <small style="color: var(--charcoal-grey); font-size: 11px; display: block; margin-top: var(--spacing-xs);">
                                        Maximum file size: 10MB
                                    </small>
                                </div>
                                
                                <input 
                                    type="text" 
                                    name="description" 
                                    placeholder="Version description (optional)"
                                    style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 12px;"
                                >
                                
                                <div style="display: flex; gap: var(--spacing-xs);">
                                    <button 
                                        type="submit" 
                                        style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 12px;"
                                    >
                                        Upload Version
                                    </button>
                                    <button 
                                        type="button" 
                                        onclick="hideUploadVersionForm(<?php echo $doc['id']; ?>)" 
                                        style="background: var(--light-grey); color: var(--charcoal-grey); padding: var(--spacing-xs) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 12px;"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
        </section>
    </div>
</div>

<script>
(function() {
    const contactId = <?php echo $contactId; ?>;
    const apiBase = '<?php echo addslashes(rtrim(str_replace("/public", "", getBasePath()), "/") ?: ""); ?>';
    const csrfToken = '<?php echo addslashes(Security::getCsrfToken()); ?>';
    let suggestedTags = [];
    const modal = document.getElementById('suggest-tags-modal');
    const listEl = document.getElementById('suggest-tags-list');
    document.getElementById('suggest-tags-btn')?.addEventListener('click', async function() {
        try {
            const r = await fetch(apiBase + '/api/suggest_tags.php?contact_id=' + contactId);
            const data = await r.json();
            suggestedTags = data.tags || [];
            listEl.innerHTML = suggestedTags.length ? suggestedTags.map(t => '<label style="display:block;margin:4px 0;"><input type="checkbox" name="st" value="' + (t.replace(/"/g, '&quot;')) + '"> ' + (t.replace(/</g, '&lt;').replace(/>/g, '&gt;')) + '</label>').join('') : '<p style="color:#666;">No suggestions</p>';
            modal.style.display = 'flex';
        } catch (e) {
            alert('Error: ' + e.message);
        }
    });
    document.getElementById('suggest-tags-cancel')?.addEventListener('click', function() { modal.style.display = 'none'; });
    document.getElementById('suggest-tags-apply')?.addEventListener('click', async function() {
        const checked = Array.from(listEl.querySelectorAll('input:checked')).map(c => decodeURIComponent(c.value));
        if (!checked.length) { modal.style.display = 'none'; return; }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('contact_id', contactId);
        checked.forEach(t => fd.append('tags[]', t));
        try {
            const r = await fetch(apiBase + '/api/apply_suggested_tags.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success) location.reload();
        } catch (e) { alert('Error: ' + e.message); }
    });

    const setInlineStatus = function(elementId, message, color) {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (!message) {
            el.style.display = 'none';
            el.textContent = '';
            return;
        }
        el.style.display = 'block';
        el.style.color = color || '#7c2d12';
        el.textContent = message;
    };

    document.getElementById('generate-ai-summary-btn')?.addEventListener('click', async function() {
        const btn = this;
        const placeholder = document.getElementById('ai-summary-placeholder');
        const resultDiv = document.getElementById('ai-summary-result');
        const summaryText = document.getElementById('ai-summary-text');
        if (!btn || !placeholder || !resultDiv || !summaryText) return;
        btn.disabled = true;
        btn.textContent = 'Generating...';
        setInlineStatus('ai-summary-status', '');
        try {
            const r = await fetch(apiBase + '/api/contact_summary.php?contact_id=' + contactId);
            const data = await r.json();
            if (data.success && data.summary) {
                summaryText.textContent = data.summary;
                placeholder.style.display = 'none';
                resultDiv.style.display = 'block';
            } else {
                setInlineStatus('ai-summary-status', data.error || 'Unable to generate a summary right now. Please try again shortly.');
                btn.disabled = false;
                btn.textContent = 'Generate AI Summary';
            }
        } catch (e) {
            setInlineStatus('ai-summary-status', 'Unable to generate a summary right now. ' + e.message);
            btn.disabled = false;
            btn.textContent = 'Generate AI Summary';
        }
    });

    document.getElementById('generate-meeting-prep-btn')?.addEventListener('click', async function() {
        const btn = this;
        const placeholder = document.getElementById('meeting-prep-placeholder');
        const resultDiv = document.getElementById('meeting-prep-result');
        const summaryEl = document.getElementById('meeting-prep-summary');
        const detailsEl = document.getElementById('meeting-prep-details');
        if (!btn || !placeholder || !resultDiv || !summaryEl || !detailsEl) return;
        btn.disabled = true;
        btn.textContent = 'Generating...';
        setInlineStatus('meeting-prep-status', '');
        try {
            const r = await fetch(apiBase + '/api/meeting_prep.php?contact_id=' + contactId);
            const data = await r.json();
            if (data.success) {
                summaryEl.textContent = data.summary || 'No summary generated.';
                let detailsHtml = '';
                if (data.used_fallback) {
                    detailsHtml += '<div style="padding: 10px 12px; background: #fff7ed; border: 1px solid #fdba74; border-radius: 6px; color: #9a3412; font-size: 13px;">Showing fallback meeting prep because live AI generation is currently unavailable.</div>';
                }
                if (data.key_points && data.key_points.length) {
                    detailsHtml += '<div><h4 style="margin: 0 0 4px; font-size: 14px;">Key Points</h4><ul style="margin: 0; padding-left: 20px;">' +
                        data.key_points.map(p => '<li>' + (typeof p === 'string' ? p : p.action || p.text || JSON.stringify(p)) + '</li>').join('') + '</ul></div>';
                }
                if (data.open_questions && data.open_questions.length) {
                    detailsHtml += '<div><h4 style="margin: 0 0 4px; font-size: 14px;">Open Questions</h4><ul style="margin: 0; padding-left: 20px;">' +
                        data.open_questions.map(q => '<li>' + (typeof q === 'string' ? q : q) + '</li>').join('') + '</ul></div>';
                }
                if (data.suggested_topics && data.suggested_topics.length) {
                    detailsHtml += '<div><h4 style="margin: 0 0 4px; font-size: 14px;">Suggested Topics</h4><ul style="margin: 0; padding-left: 20px;">' +
                        data.suggested_topics.map(t => '<li>' + (typeof t === 'string' ? t : t) + '</li>').join('') + '</ul></div>';
                }
                detailsEl.innerHTML = detailsHtml;
                placeholder.style.display = 'none';
                resultDiv.style.display = 'block';
            } else {
                setInlineStatus('meeting-prep-status', data.error || 'Unable to generate meeting prep right now. Please try again shortly.');
            }
            btn.disabled = false;
            btn.textContent = 'Generate Meeting Prep';
        } catch (e) {
            setInlineStatus('meeting-prep-status', 'Unable to generate meeting prep right now. ' + e.message);
            btn.disabled = false;
            btn.textContent = 'Generate Meeting Prep';
        }
    });

    document.getElementById('summarize-notes-btn')?.addEventListener('click', async function() {
        const btn = this;
        const noteIds = Array.from(document.querySelectorAll('.note-card')).map(c => c.dataset.noteId).filter(Boolean);
        if (!noteIds.length) return;
        btn.disabled = true;
        btn.textContent = 'Summarizing...';
        try {
            const r = await fetch(apiBase + '/api/notes/ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'summarize', note_ids: noteIds })
            });
            const data = await r.json();
            if (data.success) {
                document.getElementById('notes-summary-text').textContent = data.summary || '';
                document.getElementById('notes-summary-result').style.display = 'block';
            } else {
                alert('Error: ' + (data.error || 'Failed to summarize'));
            }
        } catch (e) { alert('Error: ' + e.message); }
        btn.disabled = false;
        btn.textContent = 'Summarize notes';
    });

    document.querySelectorAll('.extract-actions-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const noteId = this.dataset.noteId;
            const card = this.closest('.note-card');
            const contentEl = card?.querySelector('.note-content');
            const content = contentEl ? contentEl.textContent || contentEl.innerText : '';
            if (!content.trim()) { alert('No content to extract from'); return; }
            const actionsEl = document.getElementById('note-actions-' + noteId);
            if (!actionsEl) return;
            this.disabled = true;
            this.textContent = 'Extracting...';
            try {
                const r = await fetch(apiBase + '/api/notes/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'extract_actions', content: content })
                });
                const data = await r.json();
                if (data.success && data.actions?.length) {
                    actionsEl.innerHTML = '<strong>Action items:</strong><ul style="margin: 4px 0 0; padding-left: 20px;">' +
                        data.actions.map(a => '<li>' + (a.text || '').replace(/</g, '&lt;') + (a.due ? ' (due: ' + a.due + ')' : '') + '</li>').join('') + '</ul>';
                    actionsEl.style.display = 'block';
                } else {
                    actionsEl.innerHTML = '<em>No action items found.</em>';
                    actionsEl.style.display = 'block';
                }
            } catch (e) { alert('Error: ' + e.message); }
            this.disabled = false;
            this.textContent = 'Extract actions';
        });
    });
})();
document.querySelectorAll('.reply-toggle').forEach(btn => {
    btn.addEventListener('click', function() {
        const wrap = document.getElementById('reply-form-' + this.dataset.noteId);
        if (wrap) wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
    });
});
document.getElementById('documentUploadForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    
    try {
        const response = await fetch('document_upload.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Upload failed'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

function toggleVersionHistory(docId) {
    const historyDiv = document.getElementById('version_history_' + docId);
    if (historyDiv.style.display === 'none') {
        historyDiv.style.display = 'block';
    } else {
        historyDiv.style.display = 'none';
    }
}

function showUploadVersionForm(docId) {
    const formDiv = document.getElementById('upload_version_form_' + docId);
    formDiv.style.display = 'block';
}

function hideUploadVersionForm(docId) {
    const formDiv = document.getElementById('upload_version_form_' + docId);
    formDiv.style.display = 'none';
    // Reset form
    const form = formDiv.querySelector('form');
    if (form) {
        form.reset();
    }
}

async function restoreVersion(docId, versionNumber, expectedLockVersion) {
    if (!confirm(`Are you sure you want to restore version ${versionNumber}? This will make it the current version.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
    formData.append('document_id', docId);
    formData.append('version', versionNumber);
    formData.append('expected_lock_version', expectedLockVersion);
    
    try {
        const response = await fetch('document_version_restore.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Version restored successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || data.error || 'Failed to restore version'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

// Handle version upload forms
document.querySelectorAll('.versionUploadForm').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        const docId = this.dataset.documentId;
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
        
        try {
            const response = await fetch('document_version_upload.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('New version uploaded successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || data.error || 'Failed to upload version'));
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        } catch (error) {
            alert('Error: ' + error.message);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    });
});

async function deleteDocument(docId) {
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
    formData.append('document_id', docId);
    
    try {
        const response = await fetch('document_delete.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Delete failed'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function showMLExplanation(contactId) {
    try {
        const response = await fetch(`../api/ml-scoring/explain.php?contact_id=${contactId}&model_type=conversion`);
        const result = await response.json();
        
        if (result.success && result.data.explanation) {
            const explanation = result.data.explanation;
            const topFactors = result.data.top_factors || [];
            
            let html = '<div style="max-width: 600px;">';
            html += '<h3 style="margin-bottom: 16px;">AI Score Explanation</h3>';
            
            // Score summary
            html += '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 16px;">';
            html += `<p><strong>Score:</strong> ${explanation.score.toFixed(1)}/100</p>`;
            html += `<p><strong>Conversion Probability:</strong> ${(explanation.probability * 100).toFixed(1)}%</p>`;
            html += `<p><strong>Confidence:</strong> ${(explanation.confidence * 100).toFixed(1)}%</p>`;
            html += '</div>';
            
            // Explanation text
            if (explanation.explanation) {
                html += '<div style="margin-bottom: 16px;">';
                html += '<h4 style="margin-bottom: 8px;">Summary</h4>';
                html += `<p style="white-space: pre-wrap;">${explanation.explanation}</p>`;
                html += '</div>';
            }
            
            // Top factors
            if (topFactors.length > 0) {
                html += '<div style="margin-bottom: 16px;">';
                html += '<h4 style="margin-bottom: 8px;">Key Factors</h4>';
                html += '<ul style="list-style: none; padding: 0;">';
                topFactors.forEach(factor => {
                    const icon = factor.impact === 'positive' ? '↑' : '↓';
                    const color = factor.impact === 'positive' ? '#28a745' : '#dc3545';
                    html += `<li style="padding: 8px; margin-bottom: 4px; background: #f8f9fa; border-radius: 4px;">`;
                    html += `<span style="color: ${color}; font-weight: bold;">${icon}</span> `;
                    html += `<strong>${factor.feature}:</strong> ${factor.value}`;
                    html += `</li>`;
                });
                html += '</ul>';
                html += '</div>';
            }
            
            // Recommendations
            if (explanation.recommendations && explanation.recommendations.length > 0) {
                html += '<div>';
                html += '<h4 style="margin-bottom: 8px;">Recommendations</h4>';
                html += '<ul style="list-style: none; padding: 0;">';
                explanation.recommendations.forEach(rec => {
                    const priorityColor = rec.priority === 'high' ? '#dc3545' : (rec.priority === 'medium' ? '#f90' : '#6c757d');
                    html += `<li style="padding: 8px; margin-bottom: 4px; border-left: 3px solid ${priorityColor};">`;
                    html += `<strong>${rec.action}</strong><br>`;
                    html += `<small style="color: #6c757d;">${rec.reason}</small>`;
                    html += `</li>`;
                });
                html += '</ul>';
                html += '</div>';
            }
            
            html += '</div>';
            
            // Show in modal or alert
            const modal = document.createElement('div');
            modal.id = 'ml-explanation-modal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';
            
            const closeModal = () => {
                modal.remove();
                document.removeEventListener('keydown', handleEscape);
            };
            
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                }
            };
            
            // Close on background click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // Close on ESC key
            document.addEventListener('keydown', handleEscape);
            
            modal.innerHTML = `
                <div style="background: white; padding: 24px; border-radius: 8px; max-width: 700px; max-height: 80vh; overflow-y: auto; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <button id="close-ml-modal" style="position: absolute; top: 8px; right: 8px; background: none; border: none; font-size: 28px; cursor: pointer; color: #666; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='none'">×</button>
                    ${html}
                </div>
            `;
            document.body.appendChild(modal);
            
            // Add close button event listener
            const closeBtn = document.getElementById('close-ml-modal');
            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }
        } else {
            alert('Unable to load explanation: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Error loading explanation: ' + error.message);
    }
}

async function enrichContact(contactId) {
    const btn = document.getElementById('enrich-btn');
    const status = document.getElementById('enrich-status');
    
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span><span>Enriching...</span>';
    btn.style.opacity = '0.7';
    
    if (status) {
        status.textContent = 'Checking trusted providers and regenerating context...';
        status.style.color = 'var(--charcoal-grey)';
    }
    
    try {
        const response = await fetch('../api/enrichment/enrich.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
                body: JSON.stringify({
                    contact_id: contactId,
                    options: {
                        use_third_party: true,
                        extract_web: false,
                        extract_email: true,
                        extract_social: false,
                        discover_linkedin: false,
                        infer_fields: true,
                        validate_data: true,
                        sources: ['third_party', 'email', 'inference']
                    }
                })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            if (status) {
                status.textContent = '✓ Verified data/context refreshed. Reloading...';
                status.style.color = '#28a745';
            }
            
            // Reload page after 1.5 seconds
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            if (status) {
                status.textContent = '✗ ' + (result.message || 'Enrichment failed');
                status.style.color = '#dc3545';
            }
            alert('Enrichment failed: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Enrichment error:', error);
        if (status) {
            status.textContent = '✗ Network error: ' + error.message;
            status.style.color = '#dc3545';
        }
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.opacity = '1';
    }
}

// Undo last enrichment
async function undoEnrichment(contactId) {
    if (!confirm('Are you sure you want to undo the last enrichment? This will restore the previous values for all fields that were updated.')) {
        return;
    }
    
    const btn = document.getElementById('undo-enrich-btn');
    const status = document.getElementById('enrich-status');
    
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span><span>Undoing...</span>';
    btn.style.opacity = '0.7';
    
    if (status) {
        status.textContent = 'Undoing enrichment...';
        status.style.color = 'var(--charcoal-grey)';
    }
    
    try {
        const response = await fetch('../api/enrichment/undo.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                contact_id: contactId
            })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            if (status) {
                status.textContent = '✓ Enrichment undone! Refreshing...';
                status.style.color = '#28a745';
            }
            
            // Reload page after 1 second
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            if (status) {
                status.textContent = '✗ Error: ' + (result.message || 'Failed to undo enrichment');
                status.style.color = '#dc3545';
            }
            alert('Error: ' + (result.message || 'Failed to undo enrichment'));
        }
    } catch (error) {
        if (status) {
            status.textContent = '✗ Network error: ' + error.message;
            status.style.color = '#dc3545';
        }
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.opacity = '1';
    }
}

// Verify email address
async function verifyEmail(contactId, email) {
    const btn = document.getElementById('verify-email-btn');
    const status = document.getElementById('verify-email-status');
    
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span><span>Verifying...</span>';
    btn.style.opacity = '0.7';
    
    if (status) {
        status.textContent = 'Verifying email...';
        status.style.color = 'var(--charcoal-grey)';
    }
    
    try {
        const response = await fetch('../api/email/verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                email: email,
                contact_id: contactId
            })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            if (status) {
                status.textContent = '✓ Verified! Refreshing...';
                status.style.color = '#28a745';
            }
            
            // Reload page after 1 second
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            if (status) {
                status.textContent = '✗ Error: ' + (result.message || 'Verification failed');
                status.style.color = '#dc3545';
            }
            alert('Error: ' + (result.message || 'Email verification failed'));
        }
    } catch (error) {
        if (status) {
            status.textContent = '✗ Network error: ' + error.message;
            status.style.color = '#dc3545';
        }
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.opacity = '1';
    }
}

// Apply recommended weights
async function applyRecommendedWeights(contactId) {
    if (!confirm('Apply recommended weights for this contact?')) {
        return;
    }
    
    try {
        const response = await fetch(`../api/scoring/apply-weights.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                contact_id: contactId,
                use_recommended: true
            })
        });
        
        const data = await response.json();
        if (data.success || data.status === 'success') {
            alert('Recommended weights applied! Recalculating scores...');
            await recalculateScore(contactId);
            location.reload();
        } else {
            alert('Error: ' + (data.error || data.message || 'Failed to apply weights'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

// Recalculate all scores
async function recalculateScore(contactId) {
    try {
        const response = await fetch(`../api/scoring/calculate.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                contact_id: contactId
            })
        });
        
        const data = await response.json();
        if (data.success || data.status === 'success') {
            location.reload();
        } else {
            alert('Error: ' + (data.error || data.message || 'Failed to recalculate scores'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const contactPage = document.querySelector('.contact-detail-page');
    const tabButtons = Array.from(document.querySelectorAll('[data-contact-tab-target]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-contact-tab]'));
    const contactActionMenus = Array.from(document.querySelectorAll('.contact-action-menu'));

    if (contactActionMenus.length) {
        const closeContactActionMenus = (event) => {
            contactActionMenus.forEach((menu) => {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        };

        document.addEventListener('pointerdown', closeContactActionMenus);
        document.addEventListener('click', closeContactActionMenus);

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            contactActionMenus.forEach((menu) => {
                menu.open = false;
            });
        });
    }

    const activateContactTab = (tabName, updateHash) => {
        if (!contactPage || !tabName) return;

        contactPage.classList.add('js-tabs-ready');
        contactPage.dataset.activeContactTab = tabName;
        tabButtons.forEach((button) => {
            const isActive = button.dataset.contactTabTarget === tabName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        const activeButton = tabButtons.find((button) => button.dataset.contactTabTarget === tabName);
        if (activeButton) {
            activeButton.scrollIntoView({ block: 'nearest', inline: 'center' });
        }
        tabPanels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.contactTab === tabName);
        });

        if (updateHash) {
            const nextHash = tabName === 'notes' ? '#notes-section' : '#contact-' + tabName;
            history.replaceState(null, '', nextHash);
        }
    };

    if (contactPage && tabButtons.length && tabPanels.length) {
        tabButtons.forEach((button) => {
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', button.classList.contains('is-active') ? 'true' : 'false');
            button.addEventListener('click', () => {
                activateContactTab(button.dataset.contactTabTarget || 'overview', true);
            });
        });

        document.querySelectorAll('[data-contact-tab-link]').forEach((link) => {
            link.addEventListener('click', () => {
                activateContactTab(link.dataset.contactTabLink || 'overview', false);
            });
        });

        const hashTabMap = {
            '#notes-section': 'notes',
            '#contact-notes': 'notes',
            '#contact-documents': 'documents',
            '#contact-timeline': 'timeline',
            '#contact-ai-context': 'ai-context',
            '#contact-meeting-prep': 'meeting-prep',
            '#contact-quick-edit': 'quick-edit',
            '#contact-profile': 'profile',
            '#contact-account': 'profile',
            '#contact-intelligence': 'overview',
            '#contact-scoring': 'scoring',
            '#contact-overview': 'overview'
        };
        const activateFromHash = () => {
            activateContactTab(hashTabMap[window.location.hash] || contactPage.dataset.activeContactTab || 'overview', false);
        };
        activateFromHash();
        window.setTimeout(activateFromHash, 0);
        window.setTimeout(activateFromHash, 150);
        window.addEventListener('hashchange', activateFromHash);
        window.addEventListener('load', activateFromHash, { once: true });
    }

    const filterButtons = document.querySelectorAll('.timeline-filter-btn');
    const timelineRows = document.querySelectorAll('.timeline-row');

    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter || 'all';
            filterButtons.forEach((btn) => {
                btn.style.background = btn === button ? '#eff6ff' : 'white';
            });
            timelineRows.forEach((row) => {
                const rowFilter = row.dataset.filter || '';
                row.style.display = (filter === 'all' || rowFilter === filter) ? '' : 'none';
            });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
