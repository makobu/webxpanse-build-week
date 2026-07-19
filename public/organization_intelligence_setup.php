<?php
/**
 * Organization Intelligence setup and function catalog.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
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
use CRM\Services\OrganizationFunctionService;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;
use CRM\Services\WorkspaceHRAnalyticsSetupService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (
    !Authorization::isSuperAdmin($user)
    && !Authorization::can('workspace.skills.manage', $user)
    && !Authorization::can('hr.analytics.settings', $user)
    && !Authorization::can('org.departments.manage', $user)
    && !Authorization::can('admin.users.manage', $user)
) {
    header('Location: dashboard.php');
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    header('Location: dashboard.php?error=' . urlencode('Select a workspace before configuring Organization Intelligence.'));
    exit;
}
(new WorkspaceBusinessIntelligenceGateService())->enforceWeb($workspaceId, $user, 'Organization Intelligence');

$functions = new OrganizationFunctionService();
$functions->ensureDefaults($workspaceId);
$setup = new WorkspaceHRAnalyticsSetupService();
$setupJourneyEvents = new WorkspaceMarketplaceSetupJourneyEventService();
$runtimeEvents = new PluginRuntimeEventService();
$error = '';
$success = '';
$selectedFunctionId = max(0, (int) ($_GET['function_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $actionStarted = microtime(true);
            $payload = [
                'name' => trim((string) ($_POST['function_name'] ?? '')),
                'slug' => trim((string) ($_POST['function_slug'] ?? '')),
                'description' => trim((string) ($_POST['function_description'] ?? '')),
                'category' => trim((string) ($_POST['function_category'] ?? 'core')),
                'measurement_strength' => trim((string) ($_POST['function_measurement_strength'] ?? 'partial')),
                'relevance_status' => trim((string) ($_POST['function_relevance_status'] ?? 'active')),
                'relevance_note' => trim((string) ($_POST['function_relevance_note'] ?? '')),
                'is_active' => !empty($_POST['function_is_active']),
            ];
            if ($action === 'create_function') {
                $selectedFunctionId = $functions->createFunction($workspaceId, $payload);
                $setupJourneyEvents->recordEvent($workspaceId, (int) ($user['id'] ?? 0), WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 'setup_saved', [
                    'label' => 'Business area created',
                    'source' => 'organization_intelligence_setup',
                    'metadata' => ['function_id' => $selectedFunctionId],
                ]);
                $runtimeEvents->record([
                    'workspace_id' => $workspaceId,
                    'user_id' => (int) ($user['id'] ?? 0),
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                    'capability_key' => 'hr_analytics.function_created',
                    'entity_type' => 'organization_function',
                    'entity_id' => $selectedFunctionId,
                    'event_type' => 'workflow_action_executed',
                    'status' => 'success',
                    'duration_ms' => (int) round((microtime(true) - $actionStarted) * 1000),
                    'metadata' => ['source' => 'organization_intelligence_setup'],
                ]);
                $success = 'Business area created.';
            } elseif ($action === 'update_function') {
                $selectedFunctionId = (int) ($_POST['function_id'] ?? 0);
                $functions->updateFunction($workspaceId, $selectedFunctionId, $payload);
                $setupJourneyEvents->recordEvent($workspaceId, (int) ($user['id'] ?? 0), WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 'setup_saved', [
                    'label' => 'Business area updated',
                    'source' => 'organization_intelligence_setup',
                    'metadata' => ['function_id' => $selectedFunctionId],
                ]);
                $runtimeEvents->record([
                    'workspace_id' => $workspaceId,
                    'user_id' => (int) ($user['id'] ?? 0),
                    'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                    'capability_key' => 'hr_analytics.function_updated',
                    'entity_type' => 'organization_function',
                    'entity_id' => $selectedFunctionId,
                    'event_type' => 'workflow_action_executed',
                    'status' => 'success',
                    'duration_ms' => (int) round((microtime(true) - $actionStarted) * 1000),
                    'metadata' => ['source' => 'organization_intelligence_setup'],
                ]);
                $success = 'Business area updated.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$status = $setup->status($workspaceId);
$catalog = $functions->listFunctions($workspaceId);
$catalogById = [];
foreach ($catalog as $function) {
    $catalogById[(int) ($function['id'] ?? 0)] = $function;
}
if ($selectedFunctionId <= 0 || !isset($catalogById[$selectedFunctionId])) {
    $selectedFunctionId = (int) ($catalog[0]['id'] ?? 0);
}
$selectedFunction = $catalogById[$selectedFunctionId] ?? null;
$catalogGroups = [
    'active' => ['label' => 'Active now', 'items' => []],
    'deferred' => ['label' => 'Deferred', 'items' => []],
    'outsourced' => ['label' => 'Outsourced', 'items' => []],
    'not_applicable' => ['label' => 'Not applicable', 'items' => []],
    'inactive' => ['label' => 'Inactive', 'items' => []],
];
foreach ($catalog as $function) {
    $groupKey = empty($function['is_active']) ? 'inactive' : (string) ($function['relevance_status'] ?? 'active');
    if (!isset($catalogGroups[$groupKey])) {
        $groupKey = 'active';
    }
    $catalogGroups[$groupKey]['items'][] = $function;
}
$activeFunctionCount = count($catalogGroups['active']['items']);
$inactiveFunctionCount = count($catalogGroups['inactive']['items']);
$deferredFunctionCount = count($catalogGroups['deferred']['items']);
$outsourcedFunctionCount = count($catalogGroups['outsourced']['items']);
$notApplicableFunctionCount = count($catalogGroups['not_applicable']['items']);
$assignmentRows = Database::query(
    "SELECT function_id,
            COUNT(*) AS assignment_count,
            SUM(CASE WHEN assignment_type IN ('owner', 'temporary_owner') THEN 1 ELSE 0 END) AS owner_coverage_count
     FROM user_function_assignments
     WHERE workspace_id = ?
     GROUP BY function_id",
    [$workspaceId]
);
$assignmentSummaryByFunctionId = [];
foreach ($assignmentRows as $assignmentRow) {
    $assignmentSummaryByFunctionId[(int) ($assignmentRow['function_id'] ?? 0)] = [
        'assignment_count' => (int) ($assignmentRow['assignment_count'] ?? 0),
        'owner_coverage_count' => (int) ($assignmentRow['owner_coverage_count'] ?? 0),
    ];
}
$setupRequired = (string) ($_GET['setup_required'] ?? '') === 'hr_analytics';
$settingsReady = ((int) ($status['counts']['settings_ready'] ?? 0)) === 1;
$settingsSetupUrl = 'workspace_skills.php?module=' . rawurlencode(WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) . '#setup';
$departmentsReady = ((int) ($status['counts']['active_departments'] ?? 0)) > 0;
$departmentAssignmentsReady = ((int) ($status['counts']['assigned_members'] ?? 0)) > 0;
$ownerFacingBlockerLabel = static function (string $label): string {
    $normalized = strtolower($label);
    if (str_contains($normalized, 'settings')) {
        return 'Default scoring profile';
    }
    if (str_contains($normalized, 'active work ownership') || str_contains($normalized, 'active business functions')) {
        return 'Business areas';
    }
    if (str_contains($normalized, 'department assignment')) {
        return 'Team assignments';
    }
    if (str_contains($normalized, 'departments')) {
        return 'Teams & HR structure';
    }
    return $label;
};
$ownerSetupSteps = [
    [
        'number' => 1,
        'title' => 'Business Areas',
        'detail' => $activeFunctionCount > 0
            ? $activeFunctionCount . ' active area(s) tell the system what the business actually does.'
            : 'Confirm at least one area of responsibility so insight has a real operating home.',
        'state' => $activeFunctionCount > 0 ? 'complete' : 'required',
        'chip' => $activeFunctionCount > 0 ? 'Ready' : 'Required',
        'url' => '#function-catalog',
        'action' => 'Review business areas',
    ],
    [
        'number' => 2,
        'title' => 'Teams & HR Structure',
        'detail' => $departmentsReady
            ? ((int) ($status['counts']['active_departments'] ?? 0)) . ' active team group(s) are available for HR-style reporting.'
            : 'Optional for founder-led or small teams. Add departments when formal HR reporting matters.',
        'state' => $departmentsReady && $departmentAssignmentsReady ? 'complete' : 'optional',
        'chip' => $departmentsReady ? 'Helpful context' : 'Optional',
        'url' => 'departments.php',
        'action' => 'Review teams',
    ],
    [
        'number' => 3,
        'title' => 'Review & Open Dashboard',
        'detail' => !empty($status['ready'])
            ? 'The dashboard can now read business areas, workload, coaching, and team signals.'
            : 'Finish the required business area setup; optional HR structure can mature later.',
        'state' => !empty($status['ready']) ? 'complete' : 'required',
        'chip' => !empty($status['ready']) ? 'Ready' : 'Locked',
        'url' => !empty($status['ready']) ? 'hr_analytics.php' : '#guided-setup',
        'action' => !empty($status['ready']) ? 'Open Organization Intelligence' : 'Review blockers',
    ],
];
$hrSignalCards = [
    ['title' => 'Responsibility coverage', 'copy' => 'Owner coverage is handled from user accounts, so founder-led and multi-owner teams start with a clear operating home.'],
    ['title' => 'Workload balance', 'copy' => 'Highlights workload pressure before it turns into missed follow-up or delivery risk.'],
    ['title' => 'Coaching signals', 'copy' => 'Surfaces people who may need support without treating every dip as underperformance.'],
    ['title' => 'Team comparison', 'copy' => 'Uses departments only when they exist, so small teams can start without fake structure.'],
    ['title' => 'AI summaries', 'copy' => 'When enabled, turns the signals into manager guidance, SWOT notes, and action-plan drafts.'],
];
$pageTitle = 'Organization Intelligence - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/organization-intelligence-setup.css?v=<?php echo (int) @filemtime(__DIR__ . '/assets/css/organization-intelligence-setup.css'); ?>">


<div class="oi-setup-shell">
    <section class="oi-setup-hero">
        <div>
            <p class="oi-kicker">Guided business setup</p>
            <h1>Set up Organization Intelligence</h1>
            <p>Start with the real shape of the business: the areas of work that matter now and whether formal HR teams matter yet.</p>
        </div>
        <div class="oi-setup-hero-actions">
            <a href="hr_analytics.php" class="btn-premium-primary" style="text-decoration:none;">Open Organization Intelligence</a>
            <a href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP); ?>" class="btn-premium-secondary" style="text-decoration:none;">Marketplace overview</a>
        </div>
    </section>

<?php if ($error !== ''): ?>
    <div class="oi-banner oi-banner-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="oi-banner oi-banner-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($setupRequired): ?>
    <section class="oi-banner oi-banner-warn">
        <strong>Finish setup before opening Organization Intelligence</strong>
        <p style="margin:.35rem 0 .75rem;"><?php echo htmlspecialchars((string) ($status['message'] ?? 'Complete the required setup checks before opening the dashboard.')); ?></p>
        <?php if (!empty($status['blockers'])): ?>
            <div class="oi-chip-row">
                <?php foreach ((array) $status['blockers'] as $blocker): ?>
                    <span class="oi-chip oi-chip-warn"><?php echo htmlspecialchars($ownerFacingBlockerLabel((string) $blocker)); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="oi-card-actions" style="justify-content:flex-start;margin-top:.85rem;">
            <?php if (!$settingsReady): ?><a href="<?php echo htmlspecialchars($settingsSetupUrl); ?>" class="btn-premium-primary" style="text-decoration:none;">Review default scoring</a><?php endif; ?>
            <a href="#add-custom-function" class="btn-premium-secondary" style="text-decoration:none;">Add business area</a>
        </div>
    </section>
<?php endif; ?>

    <section class="oi-summary-grid" aria-label="Organization Intelligence setup summary">
        <div class="oi-summary-card"><strong><?php echo count($catalog); ?></strong><span>Total business areas</span></div>
        <div class="oi-summary-card"><strong><?php echo $activeFunctionCount; ?></strong><span>Active now</span></div>
        <div class="oi-summary-card"><strong><?php echo $deferredFunctionCount + $outsourcedFunctionCount; ?></strong><span>Deferred or outsourced</span></div>
        <div class="oi-summary-card"><strong><?php echo $notApplicableFunctionCount + $inactiveFunctionCount; ?></strong><span>Not relevant or inactive</span></div>
        <div class="oi-summary-card"><strong><?php echo (int) ($status['counts']['active_departments'] ?? 0); ?></strong><span>Optional teams</span></div>
    </section>

    <section class="oi-setup-card" id="guided-setup">
        <div class="oi-section-header">
            <div>
                <h2>Guided Setup</h2>
                <p>Confirm the required business areas first. Teams and HR structure can stay light until they are useful.</p>
            </div>
            <span class="oi-chip <?php echo !empty($status['ready']) ? 'oi-chip-ok' : 'oi-chip-warn'; ?>"><?php echo !empty($status['ready']) ? 'Ready' : 'Needs setup'; ?></span>
        </div>
        <div class="oi-stepper">
            <?php foreach ($ownerSetupSteps as $step): ?>
                <article class="oi-step-card is-<?php echo htmlspecialchars((string) ($step['state'] ?? 'optional')); ?>">
                    <div class="oi-step-index"><?php echo (int) ($step['number'] ?? 0); ?></div>
                    <div class="oi-step-body">
                        <strong><?php echo htmlspecialchars((string) ($step['title'] ?? 'Setup step')); ?></strong>
                        <p><?php echo htmlspecialchars((string) ($step['detail'] ?? '')); ?></p>
                    </div>
                    <div class="oi-chip-row">
                        <span class="oi-chip <?php echo (string) ($step['state'] ?? '') === 'complete' ? 'oi-chip-ok' : ((string) ($step['state'] ?? '') === 'required' ? 'oi-chip-warn' : 'oi-chip-muted'); ?>"><?php echo htmlspecialchars((string) ($step['chip'] ?? 'Optional')); ?></span>
                    </div>
                    <a href="<?php echo htmlspecialchars((string) ($step['url'] ?? '#')); ?>" class="oi-step-action"><?php echo htmlspecialchars((string) ($step['action'] ?? 'Review')); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="oi-setup-card">
        <div class="oi-section-header">
            <div>
                <h2>What This Helps You Understand</h2>
                <p>Organization Intelligence turns people and work activity into supportive owner guidance, not a punishment scorecard.</p>
            </div>
        </div>
        <div class="oi-insight-grid">
            <?php foreach ($hrSignalCards as $signal): ?>
                <article class="oi-insight-card">
                    <strong><?php echo htmlspecialchars((string) ($signal['title'] ?? 'Signal')); ?></strong>
                    <p><?php echo htmlspecialchars((string) ($signal['copy'] ?? '')); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php if (!$settingsReady): ?>
    <section class="oi-banner oi-banner-warn">
        <strong>Default scoring profile is unavailable</strong>
        <p style="margin:.35rem 0 .75rem;">Review the Marketplace setup once so scoring thresholds, AI guidance, and team labels are intentional before the dashboard opens.</p>
        <a href="<?php echo htmlspecialchars($settingsSetupUrl); ?>" class="btn-premium-primary" style="text-decoration:none;">Review default scoring</a>
    </section>
<?php endif; ?>

    <section class="oi-setup-card" id="function-catalog">
        <div class="oi-section-header">
            <div>
                <h2>Business Areas</h2>
                <p>Keep the areas that matter now active. Defer, outsource, or mark the rest as not relevant without losing the operating history.</p>
            </div>
        </div>
        <?php if ($catalog !== []): ?>
            <div class="oi-catalog-filter-row" aria-label="Business area groups">
                <?php foreach ($catalogGroups as $groupKey => $group): ?>
                    <?php
                        $groupItems = (array) ($group['items'] ?? []);
                        $firstGroupId = (int) (($groupItems[0]['id'] ?? 0));
                        $groupUrl = $firstGroupId > 0 ? '?' . http_build_query(['function_id' => $firstGroupId]) : '#function-catalog';
                    ?>
                    <a href="<?php echo htmlspecialchars($groupUrl); ?>" class="oi-catalog-filter oi-catalog-filter-<?php echo htmlspecialchars((string) $groupKey); ?>">
                        <strong><?php echo count($groupItems); ?></strong>
                        <span><?php echo htmlspecialchars((string) ($group['label'] ?? ucfirst((string) $groupKey))); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <nav class="oi-function-tabs" aria-label="Business areas">
                <?php foreach ($catalog as $function): ?>
                    <?php
                        $functionId = (int) ($function['id'] ?? 0);
                        $assignmentSummary = $assignmentSummaryByFunctionId[$functionId] ?? ['assignment_count' => 0, 'owner_coverage_count' => 0];
                        $tabQuery = http_build_query(['function_id' => $functionId]);
                    ?>
                    <a class="oi-function-tab <?php echo $functionId === $selectedFunctionId ? 'is-active' : ''; ?>" href="?<?php echo htmlspecialchars($tabQuery); ?>">
                        <strong><?php echo htmlspecialchars((string) ($function['name'] ?? 'Function')); ?></strong>
                        <span><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($function['relevance_status'] ?? 'active')))); ?> / <?php echo (int) $assignmentSummary['assignment_count']; ?> assigned</span>
                    </a>
                <?php endforeach; ?>
                <a class="oi-function-tab oi-function-tab-add" href="#add-custom-function">
                    <strong>+ New business area</strong>
                    <span>Add a responsibility</span>
                </a>
            </nav>
        <?php endif; ?>

        <?php if ($selectedFunction !== null): ?>
            <?php $assignmentSummary = $assignmentSummaryByFunctionId[$selectedFunctionId] ?? ['assignment_count' => 0, 'owner_coverage_count' => 0]; ?>
            <div class="oi-function-editor">
                <form method="POST" class="oi-function-card">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <input type="hidden" name="action" value="update_function">
                    <input type="hidden" name="function_id" value="<?php echo (int) ($selectedFunction['id'] ?? 0); ?>">
                    <div class="oi-function-card-head">
                        <div>
                            <strong><?php echo htmlspecialchars((string) ($selectedFunction['name'] ?? 'Function')); ?></strong>
                            <small style="display:block;"><?php echo htmlspecialchars((string) ($selectedFunction['description'] ?? '')); ?></small>
                        </div>
                        <span class="oi-chip <?php echo !empty($selectedFunction['is_active']) ? 'oi-chip-ok' : 'oi-chip-muted'; ?>"><?php echo !empty($selectedFunction['is_active']) ? 'Active' : 'Inactive'; ?></span>
                    </div>
                    <div class="oi-function-editor-layout">
                        <div class="oi-field-grid">
                            <label class="oi-field">Name <input type="text" name="function_name" value="<?php echo htmlspecialchars((string) ($selectedFunction['name'] ?? '')); ?>"></label>
                            <label class="oi-field oi-field-wide">Description <textarea name="function_description" rows="2"><?php echo htmlspecialchars((string) ($selectedFunction['description'] ?? '')); ?></textarea></label>
                            <label class="oi-field">This area is <select name="function_relevance_status"><option value="active" <?php echo (string) ($selectedFunction['relevance_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active now</option><option value="deferred" <?php echo (string) ($selectedFunction['relevance_status'] ?? '') === 'deferred' ? 'selected' : ''; ?>>Deferred for later</option><option value="outsourced" <?php echo (string) ($selectedFunction['relevance_status'] ?? '') === 'outsourced' ? 'selected' : ''; ?>>Handled by outside support</option><option value="not_applicable" <?php echo (string) ($selectedFunction['relevance_status'] ?? '') === 'not_applicable' ? 'selected' : ''; ?>>Not relevant here</option></select></label>
                            <details class="oi-advanced-details oi-field-wide">
                                <summary>
                                    <span>Advanced area details</span>
                                    <small>Slug, type, measurement confidence, and internal notes.</small>
                                </summary>
                                <div class="oi-field-grid">
                                    <label class="oi-field">Slug <input type="text" name="function_slug" value="<?php echo htmlspecialchars((string) ($selectedFunction['slug'] ?? '')); ?>"></label>
                                    <label class="oi-field">Area type <select name="function_category"><option value="core" <?php echo (string) ($selectedFunction['category'] ?? '') === 'core' ? 'selected' : ''; ?>>Core</option><option value="support" <?php echo (string) ($selectedFunction['category'] ?? '') === 'support' ? 'selected' : ''; ?>>Support</option><option value="optional" <?php echo (string) ($selectedFunction['category'] ?? '') === 'optional' ? 'selected' : ''; ?>>Optional</option></select></label>
                                    <label class="oi-field">Measurement confidence <select name="function_measurement_strength"><option value="strong" <?php echo (string) ($selectedFunction['measurement_strength'] ?? '') === 'strong' ? 'selected' : ''; ?>>Strong</option><option value="partial" <?php echo (string) ($selectedFunction['measurement_strength'] ?? '') === 'partial' ? 'selected' : ''; ?>>Partial</option><option value="weak" <?php echo (string) ($selectedFunction['measurement_strength'] ?? '') === 'weak' ? 'selected' : ''; ?>>Weak</option></select></label>
                                    <label class="oi-field oi-field-wide">Internal note <textarea name="function_relevance_note" rows="2"><?php echo htmlspecialchars((string) ($selectedFunction['relevance_note'] ?? '')); ?></textarea></label>
                                </div>
                            </details>
                        </div>
                        <aside class="oi-function-meta-card">
                            <strong>Business area status</strong>
                            <div class="oi-chip-row">
                                <span class="oi-chip"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($selectedFunction['relevance_status'] ?? 'active')))); ?></span>
                                <span class="oi-chip oi-chip-muted"><?php echo htmlspecialchars(ucfirst((string) ($selectedFunction['measurement_strength'] ?? 'partial'))); ?> confidence</span>
                                <span class="oi-chip oi-chip-muted"><?php echo htmlspecialchars(ucfirst((string) ($selectedFunction['category'] ?? 'core'))); ?></span>
                                <span class="oi-chip <?php echo (int) $assignmentSummary['assignment_count'] > 0 ? 'oi-chip-ok' : 'oi-chip-warn'; ?>"><?php echo (int) $assignmentSummary['assignment_count']; ?> assigned</span>
                                <span class="oi-chip <?php echo (int) $assignmentSummary['owner_coverage_count'] > 0 ? 'oi-chip-ok' : 'oi-chip-warn'; ?>"><?php echo (int) $assignmentSummary['owner_coverage_count']; ?> accountable</span>
                            </div>
                            <p class="oi-card-copy">Deferred, outsourced, not-relevant, or inactive areas stay visible for governance, but they are excluded from performance scoring until marked active now.</p>
                            <?php if ((int) $assignmentSummary['assignment_count'] > 0): ?>
                                <p class="oi-card-copy">Remove linked user coverage before deactivating this area. The save guard will block deactivation while active members still carry it.</p>
                            <?php endif; ?>
                        </aside>
                    </div>
                    <div class="oi-card-actions">
                        <label class="oi-check-field"><input type="checkbox" name="function_is_active" <?php echo !empty($selectedFunction['is_active']) ? 'checked' : ''; ?>> Active in catalog</label>
                        <button type="submit" class="btn-premium-secondary">Save business area</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <p class="oi-empty">No business areas exist yet. Create the first custom area below.</p>
        <?php endif; ?>
    </section>

    <section class="oi-setup-card" id="add-custom-function">
        <div class="oi-section-header">
            <div>
                <h2>Add Custom Business Area</h2>
                <p>Add a responsibility area that reflects how this workspace actually operates.</p>
            </div>
        </div>
        <form method="POST" class="oi-create-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <input type="hidden" name="action" value="create_function">
            <div class="oi-field-grid">
                <label class="oi-field">Name <input type="text" name="function_name" placeholder="Partnerships"></label>
                <label class="oi-field oi-field-wide">Description <textarea name="function_description" rows="2" placeholder="What this area owns"></textarea></label>
                <label class="oi-field">This area is <select name="function_relevance_status"><option value="active">Active now</option><option value="deferred">Deferred for later</option><option value="outsourced">Handled by outside support</option><option value="not_applicable">Not relevant here</option></select></label>
                <details class="oi-advanced-details oi-field-wide">
                    <summary>
                        <span>Advanced area details</span>
                        <small>Optional technical fields for admins who need them.</small>
                    </summary>
                    <div class="oi-field-grid">
                        <label class="oi-field">Slug <input type="text" name="function_slug" placeholder="partnerships"></label>
                        <label class="oi-field">Area type <select name="function_category"><option value="core">Core</option><option value="support">Support</option><option value="optional">Optional</option></select></label>
                        <label class="oi-field">Measurement confidence <select name="function_measurement_strength"><option value="partial">Partial</option><option value="strong">Strong</option><option value="weak">Weak</option></select></label>
                        <label class="oi-field oi-field-wide">Internal note <textarea name="function_relevance_note" rows="2"></textarea></label>
                    </div>
                </details>
            </div>
            <div class="oi-card-actions">
                <button type="submit" class="btn-premium-primary">Create business area</button>
            </div>
        </form>
    </section>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
