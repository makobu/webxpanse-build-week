<?php
/**
 * Deal View Page
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
use CRM\Modules\Deals;
use CRM\Modules\Notes;
use CRM\Modules\Documents;
use CRM\Modules\Currencies;
use CRM\Modules\Invoices;
use CRM\Modules\Marketing;
use CRM\Modules\Nurture;
use CRM\Security;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\CommercialAutomationApprovalService;
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$dealsModule = new Deals();
$notesModule = new Notes();
$documentsModule = new Documents();
$currenciesModule = new Currencies();
$invoicesModule = new Invoices();
$approvalService = new CommercialAutomationApprovalService();
$commercialAutomation = new CommercialAutomationOrchestrator();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$canManageAllNotes = Authorization::can('notes.manage_all', $user);
$canManageAllDocuments = Authorization::can('documents.manage_all', $user);
$canReadMarketing = Authorization::can('marketing.read', $user) && (new MarketingMarketplaceGateService())->canRun($user);
$canReadNurture = Authorization::can('nurture.read', $user);
$dealId = (int) ($_GET['id'] ?? 0);

if (!$dealId) {
    header('Location: deals.php');
    exit;
}

$deal = $dealsModule->getById($dealId);

if (!$deal) {
    header('Location: deals.php');
    exit;
}

// Get deal notes
$dealNotes = $notesModule->getEntityNotes('deal', $dealId, false, $userId);

// Get deal documents
$dealDocuments = $documentsModule->getEntityDocuments('deal', $dealId);
$dealInvoices = $invoicesModule->getByDealId($dealId);
$pendingCommercialApprovals = $approvalService->listPending($dealId, null);
$recentCommercialRuns = $commercialAutomation->getRecentRuns($dealId, null, 6);
$assistantTypeWhere = Database::columnExists('email_assistant_runs', 'assistant_type') ? " AND assistant_type = 'email'" : '';
$assistantRuns = Database::query(
    "SELECT * FROM email_assistant_runs WHERE workspace_id = ? AND deal_id = ?{$assistantTypeWhere} ORDER BY created_at DESC, id DESC LIMIT 6",
    [$workspaceId, $dealId]
);
$assistantAmbiguities = Database::query(
    "SELECT * FROM email_assistant_runs WHERE workspace_id = ? AND deal_id = ? AND resolution_status = 'ambiguous'{$assistantTypeWhere} ORDER BY created_at DESC, id DESC LIMIT 3",
    [$workspaceId, $dealId]
);
$latestAssistantRun = $assistantRuns[0] ?? null;

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
        $marketingHandoffs = $marketingModule->listLeadHandoffsForCrmRecord('deal', $dealId, 5);
        $marketingHandoffSummary = $marketingModule->getLeadHandoffCrmRecordSummary('deal', $dealId);
        $marketingCrmProfile = $marketingModule->getMarketingCrmLifecycleProfile(['deal_id' => $dealId], 6);
    }
} catch (\Throwable $e) {
    $marketingHandoffs = [];
    $marketingCrmProfile = [];
}
$nurtureReadiness = [];
try {
    $dealContactId = (int) ($deal['contact_id'] ?? 0);
    if ($canReadNurture && $dealContactId > 0) {
        $nurtureReadiness = (new Nurture())->getTransitionReadinessForContact($dealContactId, false);
    }
} catch (\Throwable $e) {
    $nurtureReadiness = [];
}

// Handle note creation
$noteError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_commercial_action') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            Authorization::requirePermission('commercial_automation.approvals');
            $commercialAutomation->executeApprovedAction((int) ($_POST['approval_id'] ?? 0), $userId);
            $pendingCommercialApprovals = $approvalService->listPending($dealId, null);
            $recentCommercialRuns = $commercialAutomation->getRecentRuns($dealId, null, 6);
        } catch (\Throwable $e) {
            $noteError = $e->getMessage();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_commercial_action') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            Authorization::requirePermission('commercial_automation.approvals');
            $approvalService->reject((int) ($_POST['approval_id'] ?? 0), $userId, 'Rejected from deal view');
            $pendingCommercialApprovals = $approvalService->listPending($dealId, null);
        } catch (\Throwable $e) {
            $noteError = $e->getMessage();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_commercial_automation') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            Authorization::requirePermission('commercial_automation.approvals');
            $commercialAutomation->runForDeal($dealId, 'manual');
            $pendingCommercialApprovals = $approvalService->listPending($dealId, null);
            $recentCommercialRuns = $commercialAutomation->getRecentRuns($dealId, null, 6);
        } catch (\Throwable $e) {
            $noteError = $e->getMessage();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $notesModule->create([
                'entity_type' => 'deal',
                'entity_id' => $dealId,
                'title' => $_POST['note_title'] ?? null,
                'content' => $_POST['note_content'] ?? '',
                'content_html' => $_POST['note_content_html'] ?? null,
                'is_private' => isset($_POST['note_private']) ? 1 : 0,
                'created_by' => $userId
            ]);
            $dealNotes = $notesModule->getEntityNotes('deal', $dealId, false, $userId);
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
            }
            $dealNotes = $notesModule->getEntityNotes('deal', $dealId, false, $userId);
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
            $dealNotes = $notesModule->getEntityNotes('deal', $dealId, false, $userId);
        }
    }
}

$stageLabels = [
    'prospecting' => 'Prospecting',
    'qualification' => 'Qualification',
    'proposal' => 'Proposal',
    'negotiation' => 'Negotiation',
    'closed_won' => 'Won',
    'closed_lost' => 'Lost'
];

$stageColors = [
    'prospecting' => '#64748b',
    'qualification' => '#2563eb',
    'proposal' => '#f59e0b',
    'negotiation' => '#65a30d',
    'closed_won' => '#16a34a',
    'closed_lost' => '#dc2626'
];

$automationAudit = Database::query(
    "SELECT id, trigger_type, from_stage, to_stage, decision, confidence, applied, reason, created_at
     FROM deal_automation_audit WHERE deal_id = ? ORDER BY created_at DESC LIMIT 5",
    [$dealId]
);
$hasAppliedChange = count(array_filter($automationAudit, function($auditRow) {
    return !empty($auditRow['applied']);
})) > 0;

$dealCurrency = $deal['currency'] ?? 'USD';
$dealValueFormatted = $currenciesModule->formatAmount((float) ($deal['value'] ?? 0), $dealCurrency);
$dealProbability = max(0, min(100, (int) ($deal['probability'] ?? 0)));
$dealStage = (string) ($deal['stage'] ?? 'prospecting');
$dealStageLabel = $stageLabels[$dealStage] ?? ucwords(str_replace('_', ' ', $dealStage));
$dealStageColor = $stageColors[$dealStage] ?? '#64748b';
$contactName = trim(($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? ''));
$expectedCloseLabel = !empty($deal['expected_close_date']) ? date('M d, Y', strtotime($deal['expected_close_date'])) : 'Not set';
$actualCloseLabel = !empty($deal['actual_close_date']) ? date('M d, Y', strtotime($deal['actual_close_date'])) : null;

$pageTitle = 'Deal: ' . htmlspecialchars($deal['title']) . ' - ' . brandProductName();
$dealDetailExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $dealDetailExperienceMode,
    'surface' => 'deal',
    'current_page' => 'deal_view.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'deal' => $deal,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/deals-ui.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">

<div class="page-premium">
    <div class="container deal-workspace">
        <section class="deal-hero">
            <div>
                <div class="deal-kicker">Deal command center</div>
                <h1><?php echo htmlspecialchars($deal['title']); ?></h1>
                <p class="deal-hero-subtitle">
                    <?php echo htmlspecialchars($deal['assigned_to_email'] ?? 'Unassigned'); ?>
                    <?php if ($contactName !== ''): ?>
                        managing <?php echo htmlspecialchars($contactName); ?>
                    <?php endif; ?>
                    with expected close <?php echo htmlspecialchars($expectedCloseLabel); ?>.
                </p>
                <div class="deal-hero-meta">
                    <span class="deal-stage-badge" style="--deal-stage-color: <?php echo htmlspecialchars($dealStageColor); ?>;">
                        <?php echo htmlspecialchars($dealStageLabel); ?>
                    </span>
                    <?php if (!empty($deal['contact_id']) && $contactName !== ''): ?>
                        <a class="deal-pill" href="contact_view.php?id=<?php echo (int) $deal['contact_id']; ?>">
                            <i class="fas fa-user" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($contactName); ?>
                        </a>
                    <?php endif; ?>
                    <span class="deal-pill">
                        <i class="fas fa-calendar-day" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($expectedCloseLabel); ?>
                    </span>
                    <span class="deal-pill">
                        <i class="fas fa-user-tie" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($deal['assigned_to_email'] ?? 'Unassigned'); ?>
                    </span>
                </div>
            </div>
            <div class="deal-hero-summary">
                <div class="deal-hero-value"><?php echo htmlspecialchars($dealValueFormatted); ?></div>
                <p class="deal-muted"><?php echo $dealProbability; ?>% probability</p>
                <div class="deal-progress deal-progress--large" style="--deal-progress: <?php echo $dealProbability; ?>%;">
                    <div class="deal-progress-bar"><?php echo $dealProbability; ?>%</div>
                </div>
                <div class="deal-hero-actions">
                    <a href="<?php echo getBasePath(); ?>/deal_quote_pdf.php?id=<?php echo $dealId; ?>" target="_blank" class="btn-premium-secondary">
                        <i class="fas fa-file-pdf" aria-hidden="true"></i>
                        Quote PDF
                    </a>
                    <?php if (!empty($deal['contact_id'])): ?>
                        <a href="<?php echo getBasePath(); ?>/calendar_share.php?deal_id=<?php echo $dealId; ?>&contact_id=<?php echo (int) $deal['contact_id']; ?>" class="btn-premium-secondary">
                            <i class="fas fa-calendar-check" aria-hidden="true"></i>
                            Share Calendar
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo getBasePath(); ?>/invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=quote" class="btn-premium-secondary">Create Quote</a>
                    <a href="<?php echo getBasePath(); ?>/invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=invoice" class="btn-premium-primary">Create Invoice</a>
                    <a href="<?php echo getBasePath(); ?>/deal_edit.php?id=<?php echo $dealId; ?>" class="btn-premium-secondary">Edit Deal</a>
                    <a href="<?php echo getBasePath(); ?>/deals.php" class="btn-premium-secondary">Back to Deals</a>
                </div>
            </div>
        </section>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert deal-alert-success">
                Deal <?php echo $_GET['success'] === 'created' ? 'created' : 'updated'; ?> successfully.
            </div>
        <?php endif; ?>

        <nav class="deal-anchor-nav" aria-label="Deal sections">
            <a href="#overview">Overview</a>
            <a href="#marketing-crm-lifecycle">Marketing CRM</a>
            <a href="#marketing-handoffs">Marketing Handoffs</a>
            <a href="#notes">Notes</a>
            <a href="#commercial-documents">Commercial Documents</a>
            <a href="#automation">Automation</a>
            <a href="#email-assistant">Email Assistant</a>
            <a href="#documents">Documents</a>
            <a href="#metadata">Metadata</a>
        </nav>

        <div class="deal-layout">
            <main class="deal-stack">
                <section class="deal-panel" id="overview">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Overview</div>
                            <h2 class="deal-panel-title">Deal snapshot</h2>
                        </div>
                    </div>
                    <div class="deal-metric-grid">
                        <div class="deal-metric">
                            <div class="deal-field-label">Value</div>
                            <div class="deal-metric-value"><?php echo htmlspecialchars($dealValueFormatted); ?></div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Stage</div>
                            <div class="deal-metric-value"><?php echo htmlspecialchars($dealStageLabel); ?></div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Probability</div>
                            <div class="deal-metric-value"><?php echo $dealProbability; ?>%</div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Expected close</div>
                            <div class="deal-metric-value"><?php echo htmlspecialchars($expectedCloseLabel); ?></div>
                        </div>
                    </div>
                    <div class="deal-description-block">
                        <?php echo !empty($deal['description']) ? htmlspecialchars($deal['description']) : 'No description recorded for this deal.'; ?>
                    </div>
                </section>

                <?php if ($canReadMarketing): ?>
                <section class="deal-panel" id="marketing-crm-lifecycle">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Marketing CRM Integration</div>
                            <h2 class="deal-panel-title">Lifecycle signals tied to this deal</h2>
                        </div>
                        <a class="btn-premium-secondary" href="marketing.php">Open Marketing</a>
                    </div>
                    <?php if (empty($marketingCrmProfile)): ?>
                        <p class="deal-muted">Marketing lifecycle context is not available for this deal yet.</p>
                    <?php else: ?>
                        <?php $crmCounts = (array) ($marketingCrmProfile['counts'] ?? []); ?>
                        <div class="deal-metric-grid">
                            <div class="deal-metric">
                                <div class="deal-field-label">Lifecycle score</div>
                                <div class="deal-metric-value"><?php echo (int) ($marketingCrmProfile['score'] ?? 0); ?>%</div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Content</div>
                                <div class="deal-metric-value"><?php echo (int) ($crmCounts['content_items'] ?? 0); ?></div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Landing pages</div>
                                <div class="deal-metric-value"><?php echo (int) ($crmCounts['landing_pages'] ?? 0); ?></div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Attribution</div>
                                <div class="deal-metric-value"><?php echo (int) ($crmCounts['attribution_touchpoints'] ?? 0); ?></div>
                            </div>
                        </div>
                        <?php if (empty($marketingCrmProfile['recommendations'])): ?>
                            <p class="deal-muted">This deal has enough marketing lifecycle links for the current stage. Review attribution and handoff quality as the deal progresses.</p>
                        <?php else: ?>
                            <div class="deal-card-list">
                                <?php foreach (array_slice((array) ($marketingCrmProfile['recommendations'] ?? []), 0, 4) as $recommendation): ?>
                                    <a class="deal-record-card" href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing.php')); ?>" style="text-decoration:none;">
                                        <div class="deal-record-title"><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Review marketing action')); ?></div>
                                        <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($recommendation['reason'] ?? 'Review this Marketing CRM lifecycle action.')); ?></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ($canReadNurture): ?>
                <section class="deal-panel" id="customer-nurture">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Customer Care</div>
                            <h2 class="deal-panel-title">Post-purchase care transition</h2>
                        </div>
                        <?php if (!empty($nurtureReadiness) && (string) ($nurtureReadiness['status'] ?? 'not_ready') !== 'not_ready'): ?>
                            <a class="btn-premium-secondary" href="nurture_view.php?contact_id=<?php echo (int) ($nurtureReadiness['contact_id'] ?? $deal['contact_id'] ?? 0); ?>">Open Customer Care</a>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($nurtureReadiness)): ?>
                        <p class="deal-muted">Customer Care readiness is available after this deal has a linked contact.</p>
                    <?php else: ?>
                        <?php
                            $nurtureStatus = (string) ($nurtureReadiness['status'] ?? 'not_ready');
                            $nurtureProfile = (array) ($nurtureReadiness['profile'] ?? []);
                            $nurtureEvidence = (array) ($nurtureReadiness['purchase_evidence'] ?? []);
                        ?>
                        <div class="deal-record-card" style="border-color:<?php echo $nurtureStatus === 'not_ready' ? '#fed7aa' : '#bbf7d0'; ?>;background:<?php echo $nurtureStatus === 'not_ready' ? '#fff7ed' : '#f0fdf4'; ?>;">
                            <div class="deal-record-title"><?php echo htmlspecialchars((string) ($nurtureReadiness['label'] ?? 'Needs purchase evidence')); ?></div>
                            <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($nurtureReadiness['next_step'] ?? 'Review the customer care transition.')); ?></div>
                        </div>
                        <div class="deal-metric-grid" style="margin-top:12px;">
                            <div class="deal-metric">
                                <div class="deal-field-label">Status</div>
                                <div class="deal-metric-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($nurtureProfile['nurture_status'] ?? $nurtureStatus)))); ?></div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Health</div>
                                <div class="deal-metric-value"><?php echo isset($nurtureProfile['health_score']) ? (int) $nurtureProfile['health_score'] . '/100' : 'Pending'; ?></div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Evidence</div>
                                <div class="deal-metric-value"><?php echo htmlspecialchars((string) ($nurtureEvidence['summary']['label'] ?? 'Needed')); ?></div>
                            </div>
                            <div class="deal-metric">
                                <div class="deal-field-label">Next check-in</div>
                                <div class="deal-metric-value"><?php echo !empty($nurtureProfile['next_touch_at']) ? htmlspecialchars(date('M j', strtotime((string) $nurtureProfile['next_touch_at']))) : 'Manual'; ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <section class="deal-panel" id="marketing-handoffs">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Marketing Handoffs</div>
                            <h2 class="deal-panel-title">Demand signals linked to this deal</h2>
                        </div>
                        <?php if ($canReadMarketing && !empty($marketingHandoffs)): ?>
                            <a class="btn-premium-secondary" href="marketing_handoffs.php?deal_id=<?php echo (int) $dealId; ?>">Open Handoffs</a>
                        <?php endif; ?>
                    </div>
                    <div class="deal-metric-grid">
                        <div class="deal-metric">
                            <div class="deal-field-label">Open</div>
                            <div class="deal-metric-value"><?php echo (int) ($marketingHandoffSummary['open'] ?? 0); ?></div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Assigned</div>
                            <div class="deal-metric-value"><?php echo (int) ($marketingHandoffSummary['assigned'] ?? 0); ?></div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Overdue</div>
                            <div class="deal-metric-value"><?php echo (int) ($marketingHandoffSummary['overdue'] ?? 0); ?></div>
                        </div>
                        <div class="deal-metric">
                            <div class="deal-field-label">Converted</div>
                            <div class="deal-metric-value"><?php echo (int) ($marketingHandoffSummary['converted'] ?? 0); ?></div>
                        </div>
                    </div>
                    <?php if (empty($marketingHandoffs)): ?>
                        <p class="deal-muted">No marketing handoffs are linked to this deal yet. Landing-page conversions and campaign handoffs will appear here after they are synced to CRM.</p>
                    <?php else: ?>
                        <div class="deal-card-list">
                            <?php foreach ($marketingHandoffs as $handoff): ?>
                                <?php
                                    $handoffTitle = trim((string) ($handoff['campaign_name'] ?? $handoff['landing_page_title'] ?? $handoff['conversion_goal_title'] ?? 'Marketing lead'));
                                    $handoffDue = !empty($handoff['sla_due_at']) ? date('M j, g:i A', strtotime((string) $handoff['sla_due_at'])) : 'No SLA';
                                ?>
                                <div class="deal-record-card">
                                    <div class="deal-record-title"><?php echo htmlspecialchars($handoffTitle); ?></div>
                                    <div class="deal-record-meta">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($handoff['status'] ?? 'new')))); ?>
                                        &middot; <?php echo htmlspecialchars((string) ($handoff['assigned_to_email'] ?? 'Unassigned')); ?>
                                        &middot; SLA <?php echo htmlspecialchars($handoffDue); ?>
                                    </div>
                                    <div class="deal-record-meta">
                                        CRM sync <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($handoff['crm_sync_status'] ?? 'pending')))); ?>
                                        <?php if (!empty($handoff['task_id'])): ?>
                                            &middot; Task #<?php echo (int) $handoff['task_id']; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="deal-panel" id="deal-ai-insights">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">AI Insights</div>
                            <h2 class="deal-panel-title">Recommendations and close prediction</h2>
                        </div>
                    </div>
                    <div id="deal-ai-placeholder" class="deal-ai-placeholder">
                        <p class="deal-muted">Get AI-powered next steps, deal summary, and close date prediction.</p>
                        <button type="button" id="load-deal-ai-btn" class="btn-premium-primary">Load AI Insights</button>
                    </div>
                    <div id="deal-ai-status" role="status" aria-live="polite"></div>
                    <div id="deal-ai-result" class="deal-card-list" style="display: none;">
                        <div id="deal-ai-next-steps" class="deal-inline-result"></div>
                        <div id="deal-ai-summary" class="deal-inline-result"></div>
                        <div id="deal-ai-close-date" class="deal-inline-result"></div>
                    </div>
                </section>

                <section class="deal-panel" id="notes">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Notes</div>
                            <h2 class="deal-panel-title"><?php echo count($dealNotes); ?> note<?php echo count($dealNotes) !== 1 ? 's' : ''; ?></h2>
                        </div>
                        <?php if (!empty($dealNotes)): ?>
                            <button type="button" id="summarize-deal-notes-btn" class="btn-premium-secondary">Summarize notes</button>
                        <?php endif; ?>
                    </div>
                    <div id="deal-notes-summary-result" class="deal-inline-result" style="display: none;">
                        <p id="deal-notes-summary-text" class="deal-no-margin"></p>
                    </div>

                    <div class="deal-note-form">
                        <?php if ($noteError): ?>
                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($noteError); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create_note">

                            <input type="text" name="note_title" placeholder="Note title (optional)">

                            <div class="rich-text-editor deal-rich-editor">
                                <textarea
                                    name="note_content"
                                    required
                                    rows="3"
                                    placeholder="Add a note about this deal..."
                                    style="display: none;"
                                ></textarea>
                            </div>

                            <div class="deal-card-header">
                                <label class="deal-checkbox-label">
                                    <input type="checkbox" name="note_private" value="1">
                                    <span>Private note (only visible to me)</span>
                                </label>
                                <button type="submit" class="btn-premium-primary">Add Note</button>
                            </div>
                        </form>
                    </div>

                    <?php if (empty($dealNotes)): ?>
                        <div class="empty-state">
                            <p>No notes yet. Add your first note above.</p>
                        </div>
                    <?php else: ?>
                        <div class="deal-note-list">
                            <?php foreach ($dealNotes as $note):
                                $noteContentPlain = strip_tags($note['content'] ?? '');
                            ?>
                                <article class="deal-note-card note-card" data-note-id="<?php echo (int) $note['id']; ?>">
                                    <div class="deal-card-header">
                                        <div>
                                            <?php if ($note['title']): ?>
                                                <div class="deal-note-title"><?php echo htmlspecialchars($note['title']); ?></div>
                                            <?php endif; ?>
                                            <div class="deal-note-meta">
                                                <?php echo htmlspecialchars($note['created_by_email'] ?? 'Unknown'); ?>
                                                <?php if ($note['is_private']): ?>
                                                    <span class="deal-pill deal-pill--warning">
                                                        <i class="fas fa-lock" aria-hidden="true"></i>
                                                        Private
                                                    </span>
                                                <?php endif; ?>
                                                - <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="deal-note-actions">
                                            <?php if (strlen($noteContentPlain) > 20): ?>
                                                <button type="button" class="deal-link-button extract-actions-btn" data-note-id="<?php echo (int) $note['id']; ?>" title="Extract action items">Extract actions</button>
                                            <?php endif; ?>
                                            <?php if ($note['created_by'] == $userId || $canManageAllNotes): ?>
                                                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="delete_note">
                                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                                    <button type="submit" class="deal-danger-button" title="Delete note">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div id="note-actions-<?php echo (int) $note['id']; ?>" class="deal-inline-result note-extracted-actions" style="display: none;"></div>
                                    <div class="deal-note-content note-content">
                                        <?php
                                        $noteBody = $note['content'];
                                        if (!empty($noteBody) && (strpos($noteBody, '<p>') !== false || strpos($noteBody, '<br>') !== false || strpos($noteBody, '<strong>') !== false || strpos($noteBody, '<em>') !== false)) {
                                            echo strip_tags($noteBody, '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><a><span>');
                                        } else {
                                            echo nl2br(htmlspecialchars($noteBody));
                                        }
                                        ?>
                                    </div>
                                    <div class="deal-note-actions">
                                        <button type="button" class="deal-link-button reply-toggle" data-note-id="<?php echo $note['id']; ?>">Reply</button>
                                        <div class="reply-form-wrap deal-inline-result" id="reply-form-<?php echo $note['id']; ?>" style="display: none;">
                                            <form method="POST" action="" class="deal-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="create_reply">
                                                <input type="hidden" name="parent_note_id" value="<?php echo $note['id']; ?>">
                                                <textarea name="reply_content" rows="2" placeholder="Write a reply..."></textarea>
                                                <button type="submit" class="btn-premium-secondary btn-premium-sm">Post Reply</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php if (!empty($note['replies'])): ?>
                                        <div class="deal-replies">
                                            <?php foreach ($note['replies'] as $reply): ?>
                                                <div class="deal-reply">
                                                    <div class="deal-note-meta"><?php echo htmlspecialchars($reply['created_by_email'] ?? 'Unknown'); ?> - <?php echo date('M j, g:i A', strtotime($reply['created_at'])); ?></div>
                                                    <div class="deal-note-content"><?php echo nl2br(htmlspecialchars(strip_tags($reply['content'] ?? ''))); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="deal-panel" id="commercial-documents">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Commercial Documents</div>
                            <h2 class="deal-panel-title">Invoices / Quotes</h2>
                            <p class="deal-panel-subtitle">Proposal and negotiation documents linked to this deal.</p>
                        </div>
                        <div class="deal-action-row">
                            <a href="invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=quote" class="btn-premium-secondary">Create Quote</a>
                            <a href="invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=proforma" class="btn-premium-secondary">Create Proforma</a>
                            <a href="invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=invoice" class="btn-premium-primary">Create Invoice</a>
                        </div>
                    </div>
                    <?php if (empty($dealInvoices)): ?>
                        <div class="empty-state">
                            <p>No commercial documents yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="deal-card-list">
                            <?php foreach ($dealInvoices as $doc): ?>
                                <a href="invoice_view.php?id=<?php echo (int) $doc['id']; ?>" class="deal-record-card">
                                    <div>
                                        <div class="deal-record-title"><?php echo htmlspecialchars($doc['invoice_number']); ?></div>
                                        <div class="deal-record-meta"><?php echo htmlspecialchars(ucfirst($doc['document_type']) . ' - ' . $doc['title']); ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="deal-record-title"><?php echo htmlspecialchars(($doc['currency'] ?? 'USD') . ' ' . number_format((float) ($doc['grand_total'] ?? 0), 2)); ?></div>
                                        <div class="deal-record-meta"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $doc['status']))); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="deal-panel" id="automation">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Automation</div>
                            <h2 class="deal-panel-title">Commercial Automation</h2>
                            <p class="deal-panel-subtitle">Automation state, pending approvals, and recent orchestration decisions for this deal.</p>
                        </div>
                        <div class="deal-action-row">
                            <a href="commercial_approvals.php?deal_id=<?php echo (int) $dealId; ?>&status=pending" class="btn-premium-secondary">Open approvals workbench</a>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="run_commercial_automation">
                                <button type="submit" class="btn-premium-secondary">Run automation now</button>
                            </form>
                        </div>
                    </div>
                    <?php if (empty($pendingCommercialApprovals) && empty($recentCommercialRuns)): ?>
                        <div class="empty-state">
                            <p>No automation activity recorded for this deal yet.</p>
                        </div>
                    <?php else: ?>
                        <?php if (!empty($pendingCommercialApprovals)): ?>
                            <div class="deal-card-list">
                                <?php foreach ($pendingCommercialApprovals as $approval): ?>
                                    <?php $diagnostics = (array) ($approval['diagnostics'] ?? []); ?>
                                    <?php $preview = (array) ($approval['preview'] ?? []); ?>
                                    <article class="deal-approval-card">
                                        <div class="deal-card-header">
                                            <div>
                                                <div class="deal-chip-row">
                                                    <div class="deal-record-title"><?php echo htmlspecialchars((string) ($approval['action_key'] ?? 'approval')); ?></div>
                                                    <span class="deal-pill deal-pill--warning"><?php echo htmlspecialchars((string) ($diagnostics['classification'] ?? 'approval')); ?></span>
                                                </div>
                                                <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($approval['reason'] ?? 'Approval required')); ?></div>
                                                <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($preview['summary'] ?? '')); ?></div>
                                                <div class="deal-record-meta">
                                                    Reason codes: <?php echo htmlspecialchars(implode(', ', (array) ($diagnostics['reason_codes'] ?? [])) ?: 'none'); ?>
                                                    <?php if (!empty($approval['requested_by_type'])): ?> - Requested by <?php echo htmlspecialchars((string) $approval['requested_by_type']); ?><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="deal-card-actions">
                                                <a href="commercial_approvals.php?id=<?php echo (int) $approval['id']; ?>" class="btn-premium-secondary">Inspect</a>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="approve_commercial_action">
                                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                                    <button type="submit" class="btn-premium-primary">Approve</button>
                                                </form>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="reject_commercial_action">
                                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                                    <button type="submit" class="btn-premium-secondary">Reject</button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($recentCommercialRuns)): ?>
                            <div class="deal-card-list">
                                <?php foreach ($recentCommercialRuns as $run): ?>
                                    <article class="deal-run-card">
                                        <div class="deal-card-header">
                                            <div>
                                                <div class="deal-record-title"><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($run['trigger_type'] ?? 'manual'))); ?></div>
                                                <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></div>
                                            </div>
                                            <span class="deal-pill"><?php echo htmlspecialchars((string) ($run['decision'] ?? 'reject')); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="deal-panel" id="email-assistant">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Email Assistant</div>
                            <h2 class="deal-panel-title">Assistant activity</h2>
                            <p class="deal-panel-subtitle">Latest assistant actions, blocked ambiguity, and customer-thread workflow signals for this deal.</p>
                        </div>
                        <div class="deal-action-row">
                            <a href="email_assistant_runs.php" class="btn-premium-secondary">View assistant runs</a>
                            <?php if (!empty($deal['contact_id'])): ?>
                                <a href="conversation.php?contact_id=<?php echo (int) $deal['contact_id']; ?>&channel=email" class="btn-premium-secondary">Ask assistant in thread</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($latestAssistantRun): ?>
                        <div class="deal-mini-grid">
                            <div class="deal-mini-card">
                                <div class="deal-field-label">Last intent</div>
                                <strong><?php echo htmlspecialchars((string) ($latestAssistantRun['intent'] ?? '')); ?></strong>
                            </div>
                            <div class="deal-mini-card">
                                <div class="deal-field-label">Resolution</div>
                                <strong><?php echo htmlspecialchars((string) ($latestAssistantRun['resolution_status'] ?? '')); ?></strong>
                            </div>
                            <div class="deal-mini-card">
                                <div class="deal-field-label">Execution</div>
                                <strong><?php echo htmlspecialchars((string) ($latestAssistantRun['execution_status'] ?? '')); ?></strong>
                            </div>
                            <div class="deal-mini-card">
                                <div class="deal-field-label">Confidence</div>
                                <strong><?php echo htmlspecialchars(number_format((float) ($latestAssistantRun['confidence_score'] ?? 0), 2)); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($assistantAmbiguities)): ?>
                        <div class="deal-card-list">
                            <?php foreach ($assistantAmbiguities as $ambiguity): ?>
                                <article class="deal-record-card deal-ambiguity-card">
                                    <div>
                                        <div class="deal-record-title">Ambiguous assistant action</div>
                                        <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($ambiguity['intent'] ?? 'Unknown request')); ?> needs clarification before the assistant can act.</div>
                                    </div>
                                    <div class="deal-record-meta"><?php echo htmlspecialchars((string) ($ambiguity['created_at'] ?? '')); ?></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($assistantRuns)): ?>
                        <div class="empty-state">
                            <p>No assistant activity recorded for this deal yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="deal-card-list">
                            <?php foreach ($assistantRuns as $run): ?>
                                <article class="deal-run-card">
                                    <div class="deal-card-header">
                                        <div>
                                            <div class="deal-record-title"><?php echo htmlspecialchars((string) ($run['intent'] ?? 'assistant_action')); ?></div>
                                            <div class="deal-record-meta">
                                                <?php echo htmlspecialchars((string) ($run['resolution_status'] ?? 'resolved')); ?> - <?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?>
                                            </div>
                                        </div>
                                        <span class="deal-pill"><?php echo htmlspecialchars((string) ($run['execution_status'] ?? 'planned')); ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="deal-panel" id="documents">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Documents</div>
                            <h2 class="deal-panel-title"><?php echo count($dealDocuments); ?> file<?php echo count($dealDocuments) !== 1 ? 's' : ''; ?></h2>
                        </div>
                    </div>

                    <form class="deal-document-form documentUploadForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="entity_type" value="deal">
                        <input type="hidden" name="entity_id" value="<?php echo $dealId; ?>">

                        <div class="deal-form-field">
                            <label for="deal-document-file">Document</label>
                            <input type="file" id="deal-document-file" name="file" required accept="*/*">
                            <small class="deal-muted">Maximum file size: 10MB</small>
                        </div>

                        <div class="deal-form-field">
                            <label for="deal-document-description">Description</label>
                            <input type="text" id="deal-document-description" name="description" placeholder="Description (optional)">
                        </div>

                        <button type="submit" class="btn-premium-primary">Upload Document</button>
                    </form>

                    <?php if (empty($dealDocuments)): ?>
                        <div class="empty-state">
                            <p>No documents yet. Upload your first document above.</p>
                        </div>
                    <?php else: ?>
                        <div class="deal-doc-grid">
                            <?php foreach ($dealDocuments as $doc): ?>
                                <article class="deal-document-card">
                                    <div class="deal-document-top">
                                        <div class="deal-document-icon">
                                            <?php echo $documentsModule->getFileIcon($doc['mime_type'] ?? ''); ?>
                                        </div>
                                        <div>
                                            <div class="deal-document-title"><?php echo htmlspecialchars($doc['original_name']); ?></div>
                                            <div class="deal-document-meta"><?php echo $documentsModule->formatFileSize($doc['file_size']); ?></div>
                                        </div>
                                    </div>
                                    <div class="deal-document-footer">
                                        <div class="deal-document-meta"><?php echo date('M j, Y', strtotime($doc['created_at'])); ?></div>
                                        <div class="deal-card-actions">
                                            <button type="button" class="deal-link-button extract-doc-btn" data-doc-id="<?php echo (int) $doc['id']; ?>">Extract with AI</button>
                                            <a href="document_download.php?id=<?php echo $doc['id']; ?>" class="deal-action-link">Download</a>
                                            <?php if ($doc['uploaded_by'] == $userId || $canManageAllDocuments): ?>
                                                <button type="button" onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="deal-danger-button">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div id="doc-extract-<?php echo (int) $doc['id']; ?>" class="deal-inline-result doc-extract-result" style="display: none;"></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>

            <aside class="deal-stack">
                <section class="deal-panel" id="metadata">
                    <div class="deal-panel-header">
                        <div>
                            <div class="deal-panel-kicker">Metadata</div>
                            <h2 class="deal-panel-title">Deal Information</h2>
                        </div>
                    </div>
                    <div class="deal-sidebar-list">
                        <div class="deal-sidebar-item">
                            <div class="deal-field-label">Contact</div>
                            <div class="deal-sidebar-value">
                                <?php if (!empty($deal['contact_id']) && $contactName !== ''): ?>
                                    <a href="contact_view.php?id=<?php echo (int) $deal['contact_id']; ?>"><?php echo htmlspecialchars($contactName); ?></a>
                                <?php else: ?>
                                    <span class="deal-muted">None</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="deal-sidebar-item">
                            <div class="deal-field-label">Assigned To</div>
                            <div class="deal-sidebar-value"><?php echo htmlspecialchars($deal['assigned_to_email'] ?? 'Unassigned'); ?></div>
                        </div>
                        <div class="deal-sidebar-item">
                            <div class="deal-field-label">Expected Close Date</div>
                            <div class="deal-sidebar-value"><?php echo htmlspecialchars($expectedCloseLabel); ?></div>
                        </div>
                        <?php if ($actualCloseLabel): ?>
                            <div class="deal-sidebar-item">
                                <div class="deal-field-label">Actual Close Date</div>
                                <div class="deal-sidebar-value"><?php echo htmlspecialchars($actualCloseLabel); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($deal['lead_source'])): ?>
                            <div class="deal-sidebar-item">
                                <div class="deal-field-label">Lead Source</div>
                                <div class="deal-sidebar-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $deal['lead_source']))); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="deal-sidebar-item">
                            <div class="deal-field-label">Created By</div>
                            <div class="deal-sidebar-value"><?php echo htmlspecialchars($deal['created_by_email'] ?? 'Unknown'); ?></div>
                        </div>
                        <div class="deal-sidebar-item">
                            <div class="deal-field-label">Created At</div>
                            <div class="deal-sidebar-value"><?php echo date('M d, Y g:i A', strtotime($deal['created_at'])); ?></div>
                        </div>
                    </div>
                </section>

                <?php if (!empty($automationAudit)): ?>
                    <section class="deal-panel">
                        <div class="deal-panel-header">
                            <div>
                                <div class="deal-panel-kicker">Automation</div>
                                <h2 class="deal-panel-title">Automation Audit</h2>
                            </div>
                            <?php if ($hasAppliedChange): ?>
                                <button type="button" id="automation-rollback-btn" class="btn-premium-warning btn-premium-sm">Undo last AI change</button>
                            <?php endif; ?>
                        </div>
                        <div class="deal-audit-list">
                            <?php foreach ($automationAudit as $audit): ?>
                                <div class="deal-audit-item">
                                    <div class="deal-record-title">
                                        <?php echo htmlspecialchars($audit['from_stage'] ?? '-'); ?> to <?php echo htmlspecialchars($audit['to_stage'] ?? '-'); ?>
                                    </div>
                                    <div class="deal-audit-meta">
                                        <?php echo htmlspecialchars($audit['decision'] ?? ''); ?><?php echo !empty($audit['applied']) ? ', applied' : ''; ?>
                                    </div>
                                    <div class="deal-audit-meta"><?php echo htmlspecialchars($audit['reason'] ?? ''); ?></div>
                                    <div class="deal-audit-meta"><?php echo date('M j, g:i A', strtotime($audit['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>

<script>
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
}[char]));

document.querySelectorAll('.reply-toggle').forEach(btn => {
    btn.addEventListener('click', function() {
        const wrap = document.getElementById('reply-form-' + this.dataset.noteId);
        if (wrap) wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
    });
});

// Handle document upload
document.querySelector('.documentUploadForm')?.addEventListener('submit', async function(e) {
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

document.getElementById('automation-rollback-btn')?.addEventListener('click', async function() {
    const btn = this;
    const dealId = <?php echo (int) $dealId; ?>;
    if (!btn || !dealId) return;
    btn.disabled = true;
    btn.textContent = 'Rolling back...';
    try {
        const res = await fetch('../api/deals/automation_rollback.php?deal_id=' + dealId);
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Rollback failed');
            btn.disabled = false;
            btn.textContent = 'Undo last AI change';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Undo last AI change';
    }
});

document.getElementById('load-deal-ai-btn')?.addEventListener('click', async function() {
    const btn = this;
    const placeholder = document.getElementById('deal-ai-placeholder');
    const resultDiv = document.getElementById('deal-ai-result');
    const statusDiv = document.getElementById('deal-ai-status');
    const dealId = <?php echo (int) $dealId; ?>;
    if (!btn || !placeholder || !resultDiv || !statusDiv) return;
    btn.disabled = true;
    btn.textContent = 'Loading...';
    statusDiv.textContent = 'Loading workspace-scoped deal intelligence...';
    try {
        const base = '../api/deals/intelligence.php';
        const [stepsRes, summaryRes, closeRes] = await Promise.all([
            fetch(base + '?deal_id=' + dealId + '&action=next_steps'),
            fetch(base + '?deal_id=' + dealId + '&action=summary'),
            fetch(base + '?deal_id=' + dealId + '&action=close_date')
        ]);
        const stepsData = await stepsRes.json();
        const summaryData = await summaryRes.json();
        const closeData = await closeRes.json();
        const statuses = [stepsData.ai_status, summaryData.ai_status, closeData.ai_status]
            .filter(status => status && typeof status === 'object');
        const statusPriority = { blocked: 4, error: 3, fallback: 2, cached: 1, ready: 0 };
        statuses.sort((a, b) => (statusPriority[b.state] || 0) - (statusPriority[a.state] || 0));
        statusDiv.innerHTML = statuses.length && window.AIUiConsistency
            ? window.AIUiConsistency.renderExecutionStatus(statuses[0])
            : '';
        let html = '';
        if (stepsData.success && stepsData.next_steps?.length) {
            html = '<h4 class="deal-generated-title">Next Steps</h4><ul class="deal-generated-list">';
            stepsData.next_steps.forEach(step => {
                const action = step.action || step.text || JSON.stringify(step);
                const priority = step.priority || 'medium';
                html += '<li>' + escapeHtml(action) + (priority ? ' <span class="deal-generated-muted">(' + escapeHtml(priority) + ')</span>' : '') + '</li>';
            });
            html += '</ul>';
        }
        const nextStepsEl = document.getElementById('deal-ai-next-steps');
        nextStepsEl.innerHTML = html;
        nextStepsEl.style.display = html ? 'block' : 'none';
        if (summaryData.success && summaryData.summary) {
            const summaryEl = document.getElementById('deal-ai-summary');
            summaryEl.innerHTML = '<h4 class="deal-generated-title">Deal Summary</h4><p class="deal-no-margin">' + escapeHtml(summaryData.summary) + '</p>';
            summaryEl.style.display = 'block';
        } else {
            const summaryEl = document.getElementById('deal-ai-summary');
            summaryEl.innerHTML = '';
            summaryEl.style.display = 'none';
        }
        if (closeData.success && closeData.prediction) {
            const prediction = closeData.prediction;
            const closeDateEl = document.getElementById('deal-ai-close-date');
            closeDateEl.innerHTML =
                '<h4 class="deal-generated-title">Predicted Close Date</h4><p class="deal-no-margin">' +
                escapeHtml(prediction.predicted_date || 'N/A') +
                ' (confidence: ' + Math.round((prediction.confidence || 0) * 100) + '%)</p>' +
                (prediction.reasoning ? '<p class="deal-generated-muted">' + escapeHtml(prediction.reasoning) + '</p>' : '');
            closeDateEl.style.display = 'block';
        } else {
            const closeDateEl = document.getElementById('deal-ai-close-date');
            closeDateEl.innerHTML = '';
            closeDateEl.style.display = 'none';
        }
        placeholder.style.display = 'none';
        resultDiv.style.display = 'grid';
    } catch (e) {
        statusDiv.textContent = 'Deal intelligence could not be loaded. Please try again.';
        resultDiv.style.display = 'none';
    }
    btn.disabled = false;
    btn.textContent = 'Load AI Insights';
});

document.getElementById('summarize-deal-notes-btn')?.addEventListener('click', async function() {
    const btn = this;
    const noteIds = Array.from(document.querySelectorAll('.note-card')).map(card => card.dataset.noteId).filter(Boolean);
    if (!noteIds.length) return;
    btn.disabled = true;
    btn.textContent = 'Summarizing...';
    try {
        const response = await fetch('../api/notes/ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'summarize', note_ids: noteIds })
        });
        const data = await response.json();
        if (data.success) {
            document.getElementById('deal-notes-summary-text').textContent = data.summary || '';
            document.getElementById('deal-notes-summary-result').style.display = 'block';
        } else {
            alert('Error: ' + (data.error || 'Failed to summarize'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    btn.disabled = false;
    btn.textContent = 'Summarize notes';
});

document.querySelectorAll('.extract-actions-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const noteId = this.dataset.noteId;
        const card = this.closest('.note-card');
        const contentEl = card?.querySelector('.note-content');
        const content = contentEl ? contentEl.textContent || contentEl.innerText : '';
        if (!content.trim()) {
            alert('No content to extract from');
            return;
        }
        const actionsEl = document.getElementById('note-actions-' + noteId);
        if (!actionsEl) return;
        this.disabled = true;
        this.textContent = 'Extracting...';
        try {
            const response = await fetch('../api/notes/ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'extract_actions', content: content })
            });
            const data = await response.json();
            if (data.success && data.actions?.length) {
                actionsEl.innerHTML = '<strong>Action items:</strong><ul class="deal-generated-list">' +
                    data.actions.map(action => '<li>' + escapeHtml(action.text || '') + (action.due ? ' (due: ' + escapeHtml(action.due) + ')' : '') + '</li>').join('') + '</ul>';
                actionsEl.style.display = 'block';
            } else {
                actionsEl.innerHTML = '<em>No action items found.</em>';
                actionsEl.style.display = 'block';
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }
        this.disabled = false;
        this.textContent = 'Extract actions';
    });
});

document.querySelectorAll('.extract-doc-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const docId = this.dataset.docId;
        const resultEl = document.getElementById('doc-extract-' + docId);
        if (!resultEl) return;
        this.disabled = true;
        this.textContent = 'Extracting...';
        try {
            const response = await fetch('../api/documents/extract.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ document_id: parseInt(docId, 10) })
            });
            const data = await response.json();
            if (data.success) {
                let html = '';
                if (data.summary) html += '<p class="deal-no-margin">' + escapeHtml(data.summary) + '</p>';
                if (data.entities?.names?.length) html += '<strong>Names:</strong> ' + escapeHtml(data.entities.names.join(', ')) + '<br>';
                if (data.entities?.dates?.length) html += '<strong>Dates:</strong> ' + escapeHtml(data.entities.dates.join(', ')) + '<br>';
                if (data.entities?.amounts?.length) html += '<strong>Amounts:</strong> ' + escapeHtml(data.entities.amounts.join(', ')) + '<br>';
                if (data.suggested_tags?.length) html += '<strong>Tags:</strong> ' + escapeHtml(data.suggested_tags.join(', '));
                resultEl.innerHTML = html || '<em>No entities extracted.</em>';
            } else {
                resultEl.innerHTML = '<em>Error: ' + escapeHtml(data.error || 'Failed') + '</em>';
            }
            resultEl.style.display = 'block';
        } catch (e) {
            resultEl.innerHTML = '<em>Error: ' + escapeHtml(e.message) + '</em>';
            resultEl.style.display = 'block';
        }
        this.disabled = false;
        this.textContent = 'Extract with AI';
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
