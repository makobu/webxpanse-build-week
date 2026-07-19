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
require_once __DIR__ . '/../includes/helpers.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
Auth::requireLogin();
Authorization::requirePermission('settings.email_assistant');

$mode = trim((string) ($_GET['mode'] ?? ''));
$intent = trim((string) ($_GET['intent'] ?? ''));
$decisionFilter = trim((string) ($_GET['decision'] ?? ''));
$resolutionStatus = trim((string) ($_GET['resolution_status'] ?? ''));
$executionStatus = trim((string) ($_GET['execution_status'] ?? ''));
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$params = [];
$sql = "SELECT * FROM email_assistant_runs";
$where = [];
if ($workspaceId > 0) {
    $where[] = "workspace_id = ?";
    $params[] = $workspaceId;
}
if ($mode !== '') {
    $where[] = "mode = ?";
    $params[] = $mode;
}
if ($intent !== '') {
    $where[] = "intent = ?";
    $params[] = $intent;
}
if ($resolutionStatus !== '') {
    $where[] = "resolution_status = ?";
    $params[] = $resolutionStatus;
}
if ($executionStatus !== '') {
    $where[] = "execution_status = ?";
    $params[] = $executionStatus;
}
if (Database::columnExists('email_assistant_runs', 'assistant_type')) {
    $where[] = "assistant_type = ?";
    $params[] = 'email';
}
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY created_at DESC, id DESC LIMIT 100";
$rawRuns = Database::query($sql, $params);

$runs = [];
foreach ($rawRuns as $run) {
    $planRaw = (string) ($run['plan_json'] ?? '');
    $resultRaw = (string) ($run['result_json'] ?? '');
    $plan = json_decode($planRaw, true);
    $planValid = is_array($plan);
    if (!$planValid && trim($planRaw) === '') {
        $planValid = true;
        $plan = [];
    }
    $result = json_decode($resultRaw, true);
    $resultValid = is_array($result);
    if (!$resultValid && trim($resultRaw) === '') {
        $resultValid = true;
        $result = [];
    }

    $policy = [];
    if (is_array($result) && isset($result['policy']) && is_array($result['policy'])) {
        $policy = $result['policy'];
    } elseif (is_array($result) && isset($result['results'][0]['policy']) && is_array($result['results'][0]['policy'])) {
        $policy = $result['results'][0]['policy'];
    } elseif (is_array($plan) && isset($plan['qualification_snapshot']) && is_array($plan['qualification_snapshot'])) {
        $policy = $plan['qualification_snapshot'];
    }

    $decision = (string) (
        $policy['decision']
        ?? ($result['policy_decision'] ?? $plan['policy_decision'] ?? 'unknown')
    );
    if ($decisionFilter !== '' && $decision !== $decisionFilter) {
        continue;
    }

    $run['_plan'] = is_array($plan) ? $plan : null;
    $run['_result'] = is_array($result) ? $result : null;
    $run['_policy'] = is_array($policy) ? $policy : [];
    $run['_decision'] = $decision;
    $run['_plan_valid'] = $planValid && is_array($plan);
    $run['_result_valid'] = $resultValid && is_array($result);
    $run['_summary_text'] = (string) ($result['summary_text'] ?? 'No summary recorded.');
    $run['_confidence_score'] = (float) (
        $policy['confidence_score']
        ?? ($run['confidence_score'] ?? 0)
    );
    $run['_context_quality_score'] = (float) ($policy['context_quality_score'] ?? 0);
    $run['_goal_relevance_score'] = (float) ($policy['goal_relevance_score'] ?? 0);
    $run['_qualification_mode'] = (string) ($policy['mode'] ?? 'n/a');
    $run['_reasons'] = array_values(array_filter((array) ($policy['reasons'] ?? []), static fn ($value) => $value !== ''));
    $run['_warnings'] = array_values(array_filter((array) ($policy['warnings'] ?? []), static fn ($value) => $value !== ''));
    $run['_approval_required'] = (bool) ($policy['approval_required'] ?? false);
    $run['_can_execute'] = (bool) ($policy['can_execute'] ?? false);
    $runs[] = $run;
}

function assistantRunLabel(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'n/a';
    }

    return ucwords(str_replace('_', ' ', $value));
}

function assistantRunStatusClass(string $status): string
{
    return match ($status) {
        'allow', 'resolved', 'executed' => 'is-success',
        'allow_with_warning', 'approval_required', 'ambiguous', 'planned' => 'is-warning',
        'blocked', 'failed' => 'is-danger',
        default => 'is-info',
    };
}

function assistantRunModeIcon(string $mode): string
{
    return match ($mode) {
        'admin_command' => 'fa-terminal',
        'customer_thread' => 'fa-comments',
        default => 'fa-robot',
    };
}

function assistantRunConfidencePercent(float $score): int
{
    if ($score <= 0) {
        return 0;
    }

    $normalized = $score <= 1 ? $score * 100 : $score;

    return (int) min(100, max(0, round($normalized)));
}

function assistantRunFormatDate(string $value): string
{
    if (trim($value) === '') {
        return 'n/a';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function assistantRunDetailLink(string $label, string $path, int $id): string
{
    if ($id <= 0) {
        return 'n/a';
    }

    return '<a href="' . htmlspecialchars($path . '?id=' . $id) . '">' . htmlspecialchars($label . ' #' . $id) . '</a>';
}

function assistantRunDiagnosticsLink(array $run): string
{
    $query = array_filter([
        'source' => 'assistant',
        'deal_id' => (int) ($run['deal_id'] ?? 0),
        'invoice_id' => (int) ($run['invoice_id'] ?? 0),
        'contact_id' => (int) ($run['contact_id'] ?? 0),
    ], static fn ($value) => $value !== 0 && $value !== '');

    return 'ai_automation_diagnostics.php?' . http_build_query($query);
}

function assistantRunPayloadText(bool $valid, ?array $decoded, string $raw): string
{
    if ($valid) {
        return (string) json_encode($decoded ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return $raw;
}

$totalRuns = count($runs);
$executedRuns = count(array_filter($runs, static fn (array $run): bool => (string) ($run['execution_status'] ?? '') === 'executed'));
$reviewRuns = count(array_filter($runs, static fn (array $run): bool => $run['_approval_required'] || in_array((string) $run['_decision'], ['approval_required', 'allow_with_warning'], true) || (string) ($run['resolution_status'] ?? '') === 'approval_required'));
$blockedRuns = count(array_filter($runs, static fn (array $run): bool => (string) $run['_decision'] === 'blocked' || (string) ($run['resolution_status'] ?? '') === 'blocked' || (string) ($run['execution_status'] ?? '') === 'failed'));
$averageConfidence = $totalRuns > 0
    ? array_sum(array_map(static fn (array $run): float => (float) $run['_confidence_score'], $runs)) / $totalRuns
    : 0.0;
$filtersActive = $mode !== '' || $intent !== '' || $decisionFilter !== '' || $resolutionStatus !== '' || $executionStatus !== '';
$runsResultLabel = number_format($totalRuns) . ' visible ' . ($totalRuns === 1 ? 'run' : 'runs');

$pageTitle = 'Email Assistant Runs - ' . brandProductName();
$emailAssistantRunsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_EMAIL_ASSISTANT_RUNS);
ob_start();
?>
<?php echo PageGuideVideoUi::assets(); ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/premium-pages.css') : 'assets/css/premium-pages.css'); ?>">
<style>
    .email-runs-page {
        --runs-blue: #1d4ed8;
        --runs-cyan: #0891b2;
        --runs-green: #0f766e;
        --runs-amber: #b45309;
        --runs-red: #b91c1c;
    }

    .email-runs-page .page-header {
        align-items: stretch;
        margin-bottom: 1.25rem;
    }

    .email-runs-title {
        align-items: flex-start;
        display: flex;
        gap: 1rem;
    }

    .email-runs-icon {
        align-items: center;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        color: var(--runs-blue);
        display: inline-flex;
        flex: 0 0 auto;
        height: 3rem;
        justify-content: center;
        width: 3rem;
    }

    .email-runs-icon i {
        font-size: 1.25rem;
    }

    .email-runs-page .page-header-actions {
        align-items: flex-start;
        justify-content: flex-end;
    }

    .email-runs-kpis {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        margin-bottom: 1.25rem;
    }

    .email-runs-kpi {
        background: var(--app-surface);
        border: 1px solid var(--app-border);
        border-left: 4px solid var(--runs-blue);
        border-radius: var(--border-radius-md);
        box-shadow: var(--shadow-sm);
        display: grid;
        gap: 0.45rem;
        margin: 0;
        min-height: 8rem;
        padding: 1rem;
    }

    .email-runs-kpi:nth-child(2) {
        border-left-color: var(--runs-green);
    }

    .email-runs-kpi:nth-child(3) {
        border-left-color: var(--runs-amber);
    }

    .email-runs-kpi:nth-child(4) {
        border-left-color: var(--runs-red);
    }

    .email-runs-kpi span {
        color: var(--app-text-muted);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .email-runs-kpi strong {
        color: var(--app-text);
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1;
    }

    .email-runs-kpi small {
        color: var(--app-text-muted);
        font-size: 0.82rem;
        line-height: 1.35;
    }

    .email-runs-filter-card {
        margin-bottom: 1.25rem;
    }

    .email-runs-filter-head {
        align-items: center;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .email-runs-filter-head h2 {
        color: var(--app-text);
        font-size: 1rem;
        margin: 0;
    }

    .email-runs-filter-head p {
        color: var(--app-text-muted);
        font-size: 0.85rem;
        margin: 0.2rem 0 0;
    }

    .email-runs-active-filters {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 1rem;
    }

    .email-runs-active-filters span {
        background: var(--app-surface-muted);
        border: 1px solid var(--app-border);
        border-radius: 999px;
        color: var(--app-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.3rem 0.65rem;
    }

    .assistant-runs-empty {
        align-items: center;
        display: grid;
        gap: 0.85rem;
        justify-items: center;
        padding: clamp(2rem, 5vw, 4rem) 1rem;
        text-align: center;
    }

    .assistant-runs-empty-icon {
        align-items: center;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        color: var(--runs-blue);
        display: inline-flex;
        height: 3.5rem;
        justify-content: center;
        width: 3.5rem;
    }

    .assistant-runs-empty h2 {
        color: var(--app-text);
        font-size: 1.1rem;
        margin: 0;
    }

    .assistant-runs-empty p {
        color: var(--app-text-muted);
        line-height: 1.55;
        margin: 0;
        max-width: 36rem;
    }

    .assistant-runs-empty-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        justify-content: center;
        margin-top: 0.35rem;
    }

    .assistant-runs-table-card {
        margin-bottom: 0;
    }

    .assistant-runs-table-wrap {
        overflow-x: auto;
    }

    .assistant-runs-table th,
    .assistant-runs-table td {
        vertical-align: top;
    }

    .assistant-runs-table th:first-child,
    .assistant-runs-table td:first-child {
        min-width: 120px;
    }

    .assistant-runs-table th:nth-child(2),
    .assistant-runs-table td:nth-child(2) {
        min-width: 220px;
    }

    .assistant-run-id {
        color: var(--app-text);
        display: block;
        font-weight: 800;
        text-decoration: none;
    }

    .assistant-run-id:hover {
        color: var(--app-accent);
    }

    .assistant-run-subtle,
    .assistant-run-summary,
    .assistant-run-detail-text {
        color: var(--app-text-muted);
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .assistant-run-mode {
        align-items: center;
        color: var(--app-text);
        display: inline-flex;
        font-weight: 700;
        gap: 0.45rem;
        white-space: nowrap;
    }

    .assistant-run-mode i {
        color: var(--runs-cyan);
    }

    .assistant-run-intent {
        color: var(--app-text);
        font-weight: 700;
        max-width: 32rem;
        overflow-wrap: anywhere;
    }

    .assistant-run-status-stack,
    .assistant-run-confidence {
        display: grid;
        gap: 0.5rem;
    }

    .assistant-run-confidence .premium-progress {
        min-width: 8rem;
    }

    .assistant-run-actions {
        display: flex;
        gap: 0.45rem;
        white-space: nowrap;
    }

    .assistant-run-detail-row > td {
        background: #f8fafc;
        padding: 0;
    }

    .assistant-run-details {
        border-top: 1px solid var(--app-border);
        padding: 0.95rem 1rem 1rem;
    }

    .assistant-run-details summary {
        align-items: center;
        color: var(--app-text);
        cursor: pointer;
        display: flex;
        font-weight: 800;
        gap: 0.5rem;
    }

    .assistant-run-detail-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        margin-top: 0.85rem;
    }

    .assistant-run-detail-grid > div,
    .assistant-run-payload-card {
        background: var(--app-surface);
        border: 1px solid var(--app-border);
        border-radius: 8px;
        padding: 0.85rem;
    }

    .assistant-run-detail-grid strong,
    .assistant-run-payload-card strong {
        color: var(--app-text);
        display: block;
        font-size: 0.82rem;
        margin-bottom: 0.35rem;
    }

    .assistant-run-detail-text a {
        color: var(--app-accent);
        font-weight: 700;
        text-decoration: none;
    }

    .assistant-run-detail-text a:hover {
        text-decoration: underline;
    }

    .assistant-run-payloads {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin-top: 0.85rem;
    }

    .assistant-run-payload-card pre {
        background: #111827;
        border-radius: 8px;
        color: #e5e7eb;
        font-size: 0.78rem;
        line-height: 1.5;
        margin: 0.55rem 0 0;
        max-height: 20rem;
        overflow: auto;
        padding: 0.85rem;
        white-space: pre-wrap;
    }

    @media (max-width: 760px) {
        .email-runs-page .page-header,
        .email-runs-title,
        .email-runs-filter-head {
            align-items: flex-start;
            flex-direction: column;
        }

        .email-runs-page .page-header-actions,
        .assistant-runs-empty-actions {
            justify-content: flex-start;
            width: 100%;
        }

        .email-runs-page .page-header-actions .btn-premium-secondary,
        .email-runs-page .page-header-actions .btn-premium-primary,
        .assistant-runs-empty-actions a {
            width: 100%;
        }

        .assistant-run-payloads {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-premium email-runs-page">
    <div class="container">
        <div class="page-header">
            <div class="email-runs-title">
                <span class="email-runs-icon" aria-hidden="true"><i class="fas fa-envelope-open-text"></i></span>
                <div>
                    <h1>Email Assistant Runs</h1>
                    <p>Recent assistant plans, resolutions, and outcomes.</p>
                </div>
            </div>
            <div class="page-header-actions">
                <a href="workspace_skills.php?module=email_assistant&amp;setup_tab=outbound#setup" class="btn-premium-secondary">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                    Email settings
                </a>
                <a href="email_assistant_capabilities.php" class="btn-premium-secondary">
                    <i class="fas fa-book-open" aria-hidden="true"></i>
                    Capabilities
                </a>
                <?php if ($emailAssistantRunsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_EMAIL_ASSISTANT_RUNS, 'Email Assistant Runs page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
            </div>
        </div>

        <section class="email-runs-kpis" aria-label="Email assistant run summary">
            <div class="email-runs-kpi">
                <span>Visible runs</span>
                <strong><?php echo number_format($totalRuns); ?></strong>
                <small><?php echo $filtersActive ? 'Filtered run history' : 'Latest 100 recorded runs'; ?></small>
            </div>
            <div class="email-runs-kpi">
                <span>Executed</span>
                <strong><?php echo number_format($executedRuns); ?></strong>
                <small>Runs with completed execution</small>
            </div>
            <div class="email-runs-kpi">
                <span>Needs review</span>
                <strong><?php echo number_format($reviewRuns); ?></strong>
                <small>Warnings or approval required</small>
            </div>
            <div class="email-runs-kpi">
                <span>Blocked or failed</span>
                <strong><?php echo number_format($blockedRuns); ?></strong>
                <small>Runs stopped by policy or execution errors</small>
            </div>
        </section>

        <section class="filters-card email-runs-filter-card" aria-label="Email assistant run filters">
            <div class="email-runs-filter-head">
                <div>
                    <h2>Filter run history</h2>
                    <p><?php echo htmlspecialchars($runsResultLabel); ?> · average confidence <?php echo htmlspecialchars(number_format($averageConfidence, 2)); ?></p>
                </div>
            </div>
            <form method="GET" class="filters-form" data-email-runs-filter-form>
                <div class="filter-group">
                    <label for="mode">Mode</label>
                    <select id="mode" name="mode">
                        <option value="">All</option>
                        <option value="admin_command" <?php echo $mode === 'admin_command' ? 'selected' : ''; ?>>Admin Command</option>
                        <option value="customer_thread" <?php echo $mode === 'customer_thread' ? 'selected' : ''; ?>>Customer Thread</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="intent">Intent</label>
                    <input id="intent" name="intent" value="<?php echo htmlspecialchars($intent); ?>" placeholder="Any intent">
                </div>
                <div class="filter-group">
                    <label for="decision">Decision</label>
                    <select id="decision" name="decision">
                        <option value="">All</option>
                        <?php foreach (['allow', 'allow_with_warning', 'suggest_only', 'approval_required', 'blocked'] as $decision): ?>
                            <option value="<?php echo $decision; ?>" <?php echo $decisionFilter === $decision ? 'selected' : ''; ?>><?php echo htmlspecialchars(assistantRunLabel($decision)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="resolution_status">Resolution</label>
                    <select id="resolution_status" name="resolution_status">
                        <option value="">All</option>
                        <?php foreach (['resolved', 'ambiguous', 'blocked', 'approval_required'] as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $resolutionStatus === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(assistantRunLabel($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="execution_status">Execution</label>
                    <select id="execution_status" name="execution_status">
                        <option value="">All</option>
                        <?php foreach (['planned', 'executed', 'rejected', 'failed'] as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $executionStatus === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(assistantRunLabel($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter" aria-hidden="true"></i>
                        Filter
                    </button>
                    <?php if ($filtersActive): ?>
                        <a href="email_assistant_runs.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($filtersActive): ?>
                <div class="email-runs-active-filters" aria-label="Active filters">
                    <?php if ($mode !== ''): ?><span>Mode: <?php echo htmlspecialchars(assistantRunLabel($mode)); ?></span><?php endif; ?>
                    <?php if ($intent !== ''): ?><span>Intent: <?php echo htmlspecialchars($intent); ?></span><?php endif; ?>
                    <?php if ($decisionFilter !== ''): ?><span>Decision: <?php echo htmlspecialchars(assistantRunLabel($decisionFilter)); ?></span><?php endif; ?>
                    <?php if ($resolutionStatus !== ''): ?><span>Resolution: <?php echo htmlspecialchars(assistantRunLabel($resolutionStatus)); ?></span><?php endif; ?>
                    <?php if ($executionStatus !== ''): ?><span>Execution: <?php echo htmlspecialchars(assistantRunLabel($executionStatus)); ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (empty($runs)): ?>
            <section class="content-card assistant-runs-empty">
                <span class="assistant-runs-empty-icon" aria-hidden="true"><i class="fas fa-inbox"></i></span>
                <h2><?php echo $filtersActive ? 'No runs match these filters' : 'No assistant runs recorded yet'; ?></h2>
                <p><?php echo $filtersActive ? 'Try a broader filter set to review assistant decisions and payloads.' : 'Runs will appear here after the Email Assistant evaluates or executes a request.'; ?></p>
                <div class="assistant-runs-empty-actions">
                    <?php if ($filtersActive): ?>
                        <a href="email_assistant_runs.php" class="btn-premium-primary">
                            <i class="fas fa-rotate-left" aria-hidden="true"></i>
                            Clear filters
                        </a>
                    <?php endif; ?>
                    <a href="workspace_skills.php?module=email_assistant&amp;setup_tab=outbound#setup" class="btn-premium-secondary">
                        <i class="fas fa-sliders-h" aria-hidden="true"></i>
                        Email settings
                    </a>
                    <a href="email_assistant_capabilities.php" class="btn-premium-secondary">
                        <i class="fas fa-book-open" aria-hidden="true"></i>
                        Capabilities
                    </a>
                </div>
            </section>
        <?php else: ?>
            <section class="table-card assistant-runs-table-card">
                <div class="premium-section-header">
                    <div>
                        <h2>Run history</h2>
                        <p>Showing <?php echo htmlspecialchars($runsResultLabel); ?> ordered by newest first.</p>
                    </div>
                </div>
                <div class="assistant-runs-table-wrap">
                    <table class="premium-table assistant-runs-table">
                        <thead>
                            <tr>
                                <th>Run</th>
                                <th>Intent</th>
                                <th>Decision</th>
                                <th>Workflow state</th>
                                <th>Confidence</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($runs as $run): ?>
                                <?php
                                $plan = $run['_plan'];
                                $result = $run['_result'];
                                $decision = (string) $run['_decision'];
                                $resolution = (string) ($run['resolution_status'] ?? '');
                                $execution = (string) ($run['execution_status'] ?? '');
                                $modeValue = (string) ($run['mode'] ?? '');
                                $confidenceScore = (float) $run['_confidence_score'];
                                $confidencePercent = assistantRunConfidencePercent($confidenceScore);
                                ?>
                                <tr class="assistant-run-row">
                                    <td>
                                        <a class="assistant-run-id" href="#run-<?php echo (int) $run['id']; ?>">Run #<?php echo (int) $run['id']; ?></a>
                                        <span class="assistant-run-subtle"><?php echo htmlspecialchars(assistantRunLabel($modeValue)); ?></span>
                                    </td>
                                    <td>
                                        <div class="assistant-run-mode">
                                            <i class="fas <?php echo htmlspecialchars(assistantRunModeIcon($modeValue)); ?>" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars(assistantRunLabel($modeValue)); ?>
                                        </div>
                                        <div class="assistant-run-intent"><?php echo htmlspecialchars((string) (($run['intent'] ?? '') ?: 'No intent recorded')); ?></div>
                                    </td>
                                    <td>
                                        <span class="premium-status-badge <?php echo htmlspecialchars(assistantRunStatusClass($decision)); ?>">
                                            <?php echo htmlspecialchars(assistantRunLabel($decision)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="assistant-run-status-stack">
                                            <span class="premium-status-badge <?php echo htmlspecialchars(assistantRunStatusClass($resolution)); ?>">Resolution: <?php echo htmlspecialchars(assistantRunLabel($resolution)); ?></span>
                                            <span class="premium-status-badge <?php echo htmlspecialchars(assistantRunStatusClass($execution)); ?>">Execution: <?php echo htmlspecialchars(assistantRunLabel($execution)); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="assistant-run-confidence">
                                            <span class="assistant-run-subtle"><?php echo htmlspecialchars(number_format($confidenceScore, 2)); ?></span>
                                            <div class="premium-progress" style="--premium-progress-value: <?php echo $confidencePercent; ?>%;">
                                                <div class="premium-progress-fill <?php echo $confidencePercent >= 75 ? 'is-success' : ($confidencePercent >= 50 ? 'is-warning' : 'is-danger'); ?>"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(assistantRunFormatDate((string) ($run['created_at'] ?? ''))); ?></td>
                                    <td>
                                        <div class="assistant-run-actions">
                                            <a href="<?php echo htmlspecialchars(assistantRunDiagnosticsLink($run)); ?>" class="btn-premium-secondary btn-premium-sm">
                                                <i class="fas fa-stethoscope" aria-hidden="true"></i>
                                                Diagnostics
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="assistant-run-detail-row" id="run-<?php echo (int) $run['id']; ?>">
                                    <td colspan="7">
                                        <details class="assistant-run-details">
                                            <summary><i class="fas fa-list-check" aria-hidden="true"></i> Evidence, linked records, and payloads</summary>
                                            <div class="assistant-run-detail-grid">
                                                <div>
                                                    <strong>Summary</strong>
                                                    <div class="assistant-run-detail-text"><?php echo nl2br(htmlspecialchars((string) $run['_summary_text'])); ?></div>
                                                </div>
                                                <div>
                                                    <strong>Linked records</strong>
                                                    <div class="assistant-run-detail-text">
                                                        Deal: <?php echo assistantRunDetailLink('Deal', 'deal_view.php', (int) ($run['deal_id'] ?? 0)); ?><br>
                                                        Invoice: <?php echo assistantRunDetailLink('Invoice', 'invoice_view.php', (int) ($run['invoice_id'] ?? 0)); ?><br>
                                                        Contact: <?php echo assistantRunDetailLink('Contact', 'contact_view.php', (int) ($run['contact_id'] ?? 0)); ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong>Policy</strong>
                                                    <div class="assistant-run-detail-text">
                                                        Mode: <?php echo htmlspecialchars((string) $run['_qualification_mode']); ?><br>
                                                        Context quality: <?php echo htmlspecialchars(number_format((float) $run['_context_quality_score'], 2)); ?><br>
                                                        Goal relevance: <?php echo htmlspecialchars(number_format((float) $run['_goal_relevance_score'], 2)); ?><br>
                                                        Can execute: <?php echo $run['_can_execute'] ? 'yes' : 'no'; ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong>Diagnostics</strong>
                                                    <div class="assistant-run-detail-text">
                                                        Reasons: <?php echo htmlspecialchars(implode(', ', $run['_reasons']) ?: 'none'); ?><br>
                                                        Warnings: <?php echo htmlspecialchars(implode(', ', $run['_warnings']) ?: 'none'); ?><br>
                                                        Approval required: <?php echo $run['_approval_required'] ? 'yes' : 'no'; ?><br>
                                                        <a href="<?php echo htmlspecialchars(assistantRunDiagnosticsLink($run)); ?>">Open diagnostics</a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="assistant-run-payloads">
                                                <div class="assistant-run-payload-card">
                                                    <strong>Plan payload</strong>
                                                    <pre><?php echo htmlspecialchars(assistantRunPayloadText((bool) $run['_plan_valid'], $plan, (string) ($run['plan_json'] ?? ''))); ?></pre>
                                                </div>
                                                <div class="assistant-run-payload-card">
                                                    <strong>Result payload</strong>
                                                    <pre><?php echo htmlspecialchars(assistantRunPayloadText((bool) $run['_result_valid'], $result, (string) ($run['result_json'] ?? ''))); ?></pre>
                                                </div>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var filterForm = document.querySelector('[data-email-runs-filter-form]');

        if (!filterForm) {
            return;
        }

        filterForm.addEventListener('submit', function () {
            filterForm.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
                input.remove();
            });

            filterForm.querySelectorAll('input[name], select[name]').forEach(function (field) {
                if (field.value === '') {
                    field.disabled = true;
                }
            });
        });
    });
</script>
<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_EMAIL_ASSISTANT_RUNS, 'How to use Email Assistant Runs', $emailAssistantRunsGuideVideoUrl); ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
