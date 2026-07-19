<?php
require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Currencies;
use CRM\Security;
use CRM\Services\FounderOperatingLoopService;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\StartupJourneyService;
use CRM\Services\VideoBrandOverlayUi;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$guidedDemoFounderLoopPreview = isset($_GET['guided_demo'])
    && (string) $_GET['guided_demo'] === '1'
    && (new GuidedDemoSessionService())->activeSession($workspaceId, $userId) !== null;
$canViewLoop = $guidedDemoFounderLoopPreview || Authorization::isSuperAdmin($user) || Authorization::can('founder_loop.view', $user);
if (!$canViewLoop) {
    Authorization::requirePermission('founder_loop.view');
}

$canManageLoop = Authorization::isSuperAdmin($user) || Authorization::can('founder_loop.manage', $user);
$loopCatalog = new WorkspaceSkillCatalogService();
$loopInstaller = new WorkspaceSkillInstallService($loopCatalog);
$loopAccess = new WorkspaceMarketplaceAccessService($loopCatalog, $loopInstaller);
$startupJourneyInstalled = $workspaceId > 0
    && $loopInstaller->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
$startupJourneyAccess = $workspaceId > 0
    ? $loopAccess->accessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)
    : [];
if (!$guidedDemoFounderLoopPreview && (!$startupJourneyInstalled || empty($startupJourneyAccess['can_run']))) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
    exit;
}
$startupJourneyCompletedAt = (new StartupJourneyService())->setupCompletedAt($workspaceId, $userId);
if (!$guidedDemoFounderLoopPreview && $startupJourneyCompletedAt === null) {
    header('Location: startup_journey.php');
    exit;
}

$service = new FounderOperatingLoopService();
$currencies = new Currencies();
$pageExplainers = new MarketplacePageExplainerService();
$weekStart = (string) ($_GET['week_start'] ?? date('Y-m-d'));
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageLoop) {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token.');
        }
        $action = (string) ($_POST['founder_loop_action'] ?? '');
        if ($action === 'save_sprint') {
            $service->saveSprint($workspaceId, $userId, $_POST);
            $success = 'First-customer sprint saved.';
        } elseif ($action === 'create_first_customer_tasks') {
            $result = $service->createFirstCustomerTasks($workspaceId, $userId);
            $created = count((array) ($result['created'] ?? []));
            $skipped = count((array) ($result['skipped'] ?? []));
            $success = $created > 0
                ? $created . ' founder-loop tasks created' . ($skipped > 0 ? '; ' . $skipped . ' already existed.' : '.')
                : 'Founder-loop tasks already exist for this sprint.';
        } elseif ($action === 'save_review' || $action === 'complete_review') {
            $service->saveWeeklyReview($workspaceId, $userId, $_POST, $action === 'complete_review');
            $success = $action === 'complete_review' ? 'Weekly review completed.' : 'Weekly review draft saved.';
            $weekStart = (string) ($_POST['week_start'] ?? $weekStart);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$summary = $service->summary($workspaceId, $userId, $weekStart);
$csrf = Security::getCsrfToken();
$week = (array) ($summary['week'] ?? []);
$weekStart = (string) ($week['start'] ?? date('Y-m-d'));
$weekEnd = (string) ($week['end'] ?? date('Y-m-d'));
$steps = (array) ($summary['steps'] ?? []);
$currentStep = (array) ($summary['current_step'] ?? []);
$finance = (array) ($summary['finance_snapshot'] ?? []);
$pricing = (array) ($summary['pricing'] ?? []);
$sprint = (array) ($summary['sprint'] ?? []);
$review = (array) ($summary['review'] ?? []);
$commitments = (array) ($summary['commitments'] ?? []);
$recommendedCommitments = (array) ($summary['recommended_commitments'] ?? []);
$reviewCommitments = $commitments !== [] ? $commitments : $recommendedCommitments;
$weeklyPlan = (array) ($summary['weekly_plan'] ?? []);
$firstCustomerSignal = (array) ($summary['first_customer_signal'] ?? []);
$metrics = (array) ($summary['metrics'] ?? []);
$founderLoopExplainer = $pageExplainers->getActive(MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP);
$founderLoopExplainerVideo = trim((string) ($founderLoopExplainer['video_url'] ?? ''));
$defaultCurrency = $currencies->getDefault();
$defaultCurrencyCode = trim((string) ($defaultCurrency['code'] ?? ''));
$defaultCurrencyCode = $defaultCurrencyCode !== '' ? $defaultCurrencyCode : null;

function founderLoopMoney(float $amount, Currencies $currencies, ?string $currencyCode): string
{
    return $currencies->formatAmount($amount, $currencyCode);
}

function founderLoopStatusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status ?: 'not_started'));
}

function founderLoopValue(mixed $value, string $fallback = 'Not set'): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function founderLoopStatusTooltip(string $status): string
{
    return match ($status) {
        'ready' => 'Ready to use this step.',
        'needs_attention' => 'Review this step before moving on.',
        'blocked' => 'Complete the missing setup before this step is ready.',
        'completed' => 'This step is complete.',
        default => 'Current step status.',
    };
}

$founderLoopAsset = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, 7);
    }
    if (str_starts_with($path, 'uploads/')) {
        return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
    }

    return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
};
$founderLoopExplainerVideoUrl = $founderLoopAsset($founderLoopExplainerVideo);

ob_start();
?>
<link rel="stylesheet" href="assets/css/dashboard-premium.css">
<style>
.dashboard-premium.founder-loop-dashboard {
    background: #f5f5f5;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    min-height: auto;
    padding: clamp(.75rem, 1.2vw, 1.1rem) 0 clamp(2rem, 4vw, 3.5rem);
}
.founder-loop-shell {
    box-sizing: border-box;
    max-width: 1320px;
    margin: 0 auto;
    padding: 0 clamp(1rem, 2vw, 2rem);
    color: #111827;
}
.founder-loop-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1.25rem;
    align-items: center;
    margin-bottom: .85rem;
}
.founder-loop-focus {
    display: grid;
    grid-template-columns: minmax(220px, .35fr) minmax(0, 1fr) auto;
    gap: 1.25rem;
    align-items: center;
    margin-bottom: 1.25rem;
}
.founder-loop-panel {
    border: 1px solid rgba(15, 23, 42, .09);
    border-radius: 12px;
    background: #fff;
    box-shadow:
        0 1px 2px rgba(0, 0, 0, .04),
        0 1px 3px rgba(0, 0, 0, .06);
    padding: 1.25rem;
}
.founder-loop-kicker {
    color: #2563eb;
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .075em;
    text-transform: uppercase;
    line-height: 1.35;
}
.founder-loop-title {
    margin: .2rem 0 0;
    font-size: clamp(1.7rem, 3vw, 2.35rem);
    font-weight: 600;
    line-height: 1.08;
    letter-spacing: 0;
}
.founder-loop-section-title,
.founder-loop-next-title {
    margin: .2rem 0 0;
    color: #0f172a;
    line-height: 1.12;
}
.founder-loop-section-title {
    font-size: clamp(1.18rem, 1.55vw, 1.4rem);
    font-weight: 600;
}
.founder-loop-next-title {
    font-size: 1.02rem;
    font-weight: 600;
}
.founder-loop-copy {
    margin: 0;
    color: #4b5563;
    line-height: 1.4;
    font-size: .9rem;
}
.founder-loop-actions,
.founder-loop-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}
.founder-loop-header .founder-loop-actions {
    justify-content: flex-end;
    margin-top: 0;
}
.founder-loop-icon-action {
    min-height: 2.6rem;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem .9rem;
    border: 1px solid rgba(148, 163, 184, .32);
    border-radius: 10px;
    background: #fff;
    color: #0f172a;
    font-size: .84rem;
    font-weight: 600;
    line-height: 1.2;
    text-decoration: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.founder-loop-icon-action:hover,
.founder-loop-icon-action:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(37, 99, 235, .36);
    box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
    outline: none;
}
.founder-loop-icon-action i {
    color: #2563eb;
}
.founder-loop-guide-action {
    cursor: pointer;
    position: relative;
    border-color: rgba(37, 99, 235, .24);
    box-shadow: 0 8px 18px rgba(37, 99, 235, .10);
    animation: founderLoopGuidePulse 2.8s ease-in-out infinite;
}
.founder-loop-guide-action i {
    color: inherit;
    animation: founderLoopGuideIcon 2.8s ease-in-out infinite;
}
@keyframes founderLoopGuidePulse {
    0%, 100% {
        transform: translateY(0);
        box-shadow: 0 8px 18px rgba(37, 99, 235, .10);
    }
    45% {
        transform: translateY(-1px);
        border-color: rgba(37, 99, 235, .42);
        box-shadow: 0 14px 28px rgba(37, 99, 235, .20), 0 0 0 5px rgba(37, 99, 235, .07);
    }
}
@keyframes founderLoopGuideIcon {
    0%, 100% {
        transform: scale(1);
    }
    45% {
        transform: scale(1.12);
    }
}
@media (prefers-reduced-motion: reduce) {
    .founder-loop-guide-action,
    .founder-loop-guide-action i {
        animation: none;
    }
}
.founder-loop-tooltip {
    position: relative;
}
.founder-loop-tooltip:focus-visible {
    outline: 2px solid rgba(37, 99, 235, .45);
    outline-offset: 3px;
}
.founder-loop-tooltip-bubble {
    position: absolute;
    left: 50%;
    bottom: calc(100% + .6rem);
    z-index: 20;
    width: max-content;
    max-width: 220px;
    padding: .48rem .6rem;
    border: 1px solid rgba(15, 23, 42, .12);
    border-radius: 8px;
    background: rgba(15, 23, 42, .95);
    box-shadow: 0 12px 28px rgba(15, 23, 42, .18);
    color: #fff;
    font-size: .7rem;
    font-weight: 500;
    letter-spacing: 0;
    line-height: 1.32;
    opacity: 0;
    pointer-events: none;
    text-transform: none;
    transform: translateX(-50%) translateY(4px);
    transition: opacity .16s ease, transform .16s ease, visibility .16s ease;
    visibility: hidden;
    white-space: normal;
}
.founder-loop-tooltip-bubble::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 100%;
    width: .55rem;
    height: .55rem;
    background: rgba(15, 23, 42, .95);
    transform: translate(-50%, -50%) rotate(45deg);
}
.founder-loop-tooltip:hover .founder-loop-tooltip-bubble,
.founder-loop-tooltip:focus-visible .founder-loop-tooltip-bubble,
.founder-loop-tooltip:focus-within .founder-loop-tooltip-bubble {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    visibility: visible;
}
.founder-loop-status-chip,
.founder-loop-pill {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 500;
    line-height: 1.2;
    white-space: nowrap;
}
.founder-loop-status-chip {
    padding: .24rem .5rem;
    background: #f1f5f9;
    color: #475569;
}
.founder-loop-status-chip.is-ready {
    background: #ecfdf3;
    color: #15803d;
}
.founder-loop-status-chip.is-needs_attention {
    background: #fffbeb;
    color: #92400e;
}
.founder-loop-status-chip.is-blocked {
    background: #fef2f2;
    color: #b91c1c;
}
.founder-loop-pill {
    gap: .35rem;
    padding: .42rem .68rem;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #334155;
}
.founder-loop-focus .founder-loop-pill {
    max-width: 320px;
    white-space: normal;
}
.founder-loop-next-copy {
    color: #475569;
    font-size: .9rem;
    line-height: 1.4;
}
.founder-loop-metrics {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 1.25rem;
    margin: 1.25rem 0;
}
.founder-loop-metric {
    min-height: 108px;
    padding: 1rem 1.05rem;
}
.founder-loop-metric .metric-card-header {
    margin-bottom: .45rem;
}
.founder-loop-metric .metric-icon {
    width: 34px;
    height: 34px;
}
.founder-loop-metric .metric-icon i {
    font-size: 1.2rem;
}
.founder-loop-metric .metric-value {
    font-size: 1.35rem;
    font-weight: 600;
    line-height: 1.18;
    margin: .25rem 0 0;
}
.founder-loop-metric.is-compact {
    min-height: 88px;
}
.founder-loop-metric.is-compact .metric-value {
    font-size: 1.05rem;
    font-weight: 600;
}
.founder-loop-path {
    display: grid;
    grid-template-columns: 1fr;
    gap: .7rem;
    overflow: visible;
    padding-bottom: 0;
}
.founder-loop-step {
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: 8px;
    background: #fff;
    padding: .72rem .75rem;
    min-width: 0;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .035);
}
.founder-loop-step.is-ready {
    border-color: rgba(22, 163, 74, .22);
    background: #f7fef9;
}
.founder-loop-step.is-needs_attention {
    border-color: rgba(245, 158, 11, .26);
    background: #fffdf2;
}
.founder-loop-step.is-blocked {
    border-color: rgba(220, 38, 38, .2);
    background: #fffafa;
}
.founder-loop-step-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .45rem;
    margin-bottom: .55rem;
}
.founder-loop-step-number {
    color: #64748b;
    font-size: .74rem;
    font-weight: 500;
}
.founder-loop-step strong {
    display: block;
    line-height: 1.2;
    color: #0f172a;
    font-size: .9rem;
    font-weight: 600;
}
.founder-loop-step em {
    display: inline-block;
    margin-top: .45rem;
    color: #475569;
    font-size: .76rem;
    font-style: normal;
    font-weight: 500;
    line-height: 1.35;
}
.founder-loop-workbench {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 1.25rem;
    align-items: start;
    margin-top: 1.25rem;
}
.founder-loop-step-rail {
    position: sticky;
    top: 1rem;
    align-self: start;
}
.founder-loop-step-rail .founder-loop-section-head {
    margin-bottom: .7rem;
}
.founder-loop-main {
    min-width: 0;
}
.founder-loop-grid {
    display: grid;
    grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
    gap: 1.25rem;
}
.founder-loop-section-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: .85rem;
}
.founder-loop-pricing-list,
.founder-loop-warning-list {
    display: grid;
    gap: .55rem;
    margin-top: .85rem;
}
.founder-loop-pricing-row,
.founder-loop-warning {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    border-bottom: 1px solid rgba(15, 23, 42, .07);
    padding: .55rem 0;
    color: #0f172a;
    font-size: .88rem;
    line-height: 1.35;
}
.founder-loop-pricing-row strong {
    font-weight: 500;
}
.founder-loop-warning {
    justify-content: flex-start;
    border: 1px solid rgba(245, 158, 11, .25);
    border-radius: 8px;
    background: #fffbeb;
    padding: .65rem .75rem;
    color: #92400e;
}
.founder-loop-note {
    margin-top: .75rem;
    color: #64748b;
    font-size: .82rem;
    font-weight: 500;
}
.founder-loop-summary-chips {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: .9rem;
}
.founder-loop-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}
.founder-loop-form-grid.is-tight {
    gap: .75rem;
    margin-top: .75rem;
}
.founder-loop-field {
    display: grid;
    gap: .35rem;
    color: #374151;
    font-size: .8rem;
    font-weight: 500;
    line-height: 1.35;
}
.founder-loop-field input,
.founder-loop-field textarea,
.founder-loop-field select {
    box-sizing: border-box;
    width: 100%;
    min-height: 42px;
    border: 1px solid rgba(15, 23, 42, .14);
    border-radius: 8px;
    padding: .7rem .75rem;
    color: #111827;
    font: inherit;
    font-weight: 400;
}
.founder-loop-field textarea {
    min-height: 96px;
    resize: vertical;
}
.founder-loop-field input:disabled {
    background: #f8fafc;
    color: #64748b;
}
.founder-loop-week {
    display: grid;
    gap: 1rem;
}
.founder-loop-review-panel {
    margin-top: 1.25rem;
}
.founder-loop-review-grid {
    display: grid;
    gap: 1.1rem;
}
.founder-loop-review-block {
    padding-top: 1rem;
    border-top: 1px solid rgba(15, 23, 42, .08);
}
.founder-loop-review-block:first-child {
    padding-top: 0;
    border-top: 0;
}
.founder-loop-review-block-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.founder-loop-outcome-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .75rem;
    margin-top: .75rem;
}
.founder-loop-outcome-grid .founder-loop-field input {
    min-height: 40px;
}
.founder-loop-commitments {
    display: grid;
    gap: .75rem;
    margin-top: .9rem;
}
.founder-loop-commitment-card {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.25fr) 150px 140px;
    gap: .75rem;
    align-items: end;
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: 10px;
    background: #fbfdff;
    padding: .8rem;
}
.founder-loop-action-footer {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: .6rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(15, 23, 42, .08);
}
.founder-loop-action-footer .btn-premium-primary,
.founder-loop-action-footer .btn-premium-secondary {
    min-height: 2.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    border-radius: 10px;
    padding: .68rem 1rem;
    font-size: .84rem;
    font-weight: 600;
    line-height: 1;
    transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.founder-loop-action-footer .btn-premium-secondary {
    border: 1px solid rgba(148, 163, 184, .32);
    background: #fff;
    color: #0f172a;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
}
.founder-loop-action-footer .btn-premium-primary {
    border: 1px solid rgba(37, 99, 235, .88);
    background: #2563eb;
    color: #fff;
    box-shadow: 0 8px 18px rgba(37, 99, 235, .18);
}
.founder-loop-action-footer .btn-premium-secondary::before {
    content: "\f0c7";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    font-size: .8rem;
    color: #2563eb;
}
.founder-loop-action-footer .btn-premium-primary::before {
    content: "\f058";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    font-size: .8rem;
}
.founder-loop-action-footer .btn-premium-primary:hover,
.founder-loop-action-footer .btn-premium-primary:focus-visible,
.founder-loop-action-footer .btn-premium-secondary:hover,
.founder-loop-action-footer .btn-premium-secondary:focus-visible {
    transform: translateY(-1px);
    outline: none;
}
.founder-loop-action-footer .btn-premium-secondary:hover,
.founder-loop-action-footer .btn-premium-secondary:focus-visible {
    border-color: rgba(37, 99, 235, .34);
    box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
}
.founder-loop-action-footer .btn-premium-primary:hover,
.founder-loop-action-footer .btn-premium-primary:focus-visible {
    background: #1d4ed8;
    border-color: #1d4ed8;
    box-shadow: 0 10px 22px rgba(37, 99, 235, .24);
}
.founder-loop-empty {
    border: 1px dashed rgba(37, 99, 235, .32);
    border-radius: 8px;
    background: #eff6ff;
    color: #1e3a8a;
    padding: .8rem;
    margin-top: .75rem;
    font-size: .88rem;
    line-height: 1.4;
}
.founder-loop-video-modal[hidden] {
    display: none;
}
.founder-loop-video-modal {
    position: fixed;
    inset: 0;
    z-index: 13000;
    box-sizing: border-box;
    display: grid;
    place-items: center;
    padding: clamp(1rem, 3vw, 2rem);
    overflow: hidden;
    background: rgba(15, 23, 42, .62);
}
.founder-loop-video-dialog {
    width: min(920px, 100%);
    max-height: min(760px, calc(100vh - 2rem));
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(226, 232, 240, .7);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 30px 80px rgba(15, 23, 42, .28);
}
.founder-loop-video-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid rgba(15, 23, 42, .08);
}
.founder-loop-video-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 600;
    line-height: 1.2;
}
.founder-loop-video-close {
    width: 2.35rem;
    height: 2.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(148, 163, 184, .32);
    border-radius: 10px;
    background: #fff;
    color: #334155;
    cursor: pointer;
}
.founder-loop-video-close:hover,
.founder-loop-video-close:focus-visible {
    border-color: rgba(37, 99, 235, .34);
    color: #1d4ed8;
    outline: none;
}
.founder-loop-video-frame {
    background: #020617;
    min-height: 0;
    flex: 1 1 auto;
}
.founder-loop-video-frame video {
    width: 100%;
    max-height: min(640px, calc(100vh - 7.25rem));
    display: block;
    object-fit: contain;
    background: #020617;
}
.founder-loop-video-empty {
    display: grid;
    gap: .45rem;
    padding: 2rem;
    background: #f8fafc;
    color: #475569;
    text-align: center;
}
.founder-loop-video-empty i {
    color: #2563eb;
    font-size: 1.7rem;
}
.founder-loop-video-empty strong {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 600;
}
.founder-loop-video-empty span {
    font-size: .9rem;
}
@media (max-width: 1100px) {
    .founder-loop-header,
    .founder-loop-focus,
    .founder-loop-workbench,
    .founder-loop-grid {
        grid-template-columns: 1fr;
    }
    .founder-loop-step-rail {
        position: static;
    }
    .founder-loop-header .founder-loop-actions {
        justify-content: flex-start;
    }
    .founder-loop-tooltip-bubble {
        left: 0;
        transform: translateY(4px);
    }
    .founder-loop-tooltip-bubble::after {
        left: 1.1rem;
        transform: translateY(-50%) rotate(45deg);
    }
    .founder-loop-tooltip:hover .founder-loop-tooltip-bubble,
    .founder-loop-tooltip:focus-visible .founder-loop-tooltip-bubble,
    .founder-loop-tooltip:focus-within .founder-loop-tooltip-bubble {
        transform: translateY(0);
    }
    .founder-loop-focus .founder-loop-pill {
        max-width: 100%;
    }
    .founder-loop-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 720px) {
    .dashboard-premium.founder-loop-dashboard {
        padding-top: 1rem;
    }
    .founder-loop-shell {
        padding: 0 1rem;
    }
    .founder-loop-header,
    .founder-loop-focus {
        gap: .75rem;
    }
    .founder-loop-focus {
        margin-bottom: 1rem;
    }
    .founder-loop-header .founder-loop-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        width: 100%;
    }
    .founder-loop-icon-action {
        justify-content: center;
        padding-left: .65rem;
        padding-right: .65rem;
    }
    .founder-loop-tooltip-bubble {
        position: fixed;
        left: 1rem;
        right: 1rem;
        bottom: 1rem;
        width: auto;
        max-width: none;
        transform: translateY(4px);
    }
    .founder-loop-tooltip-bubble::after {
        display: none;
    }
    .founder-loop-tooltip:hover .founder-loop-tooltip-bubble,
    .founder-loop-tooltip:focus-visible .founder-loop-tooltip-bubble,
    .founder-loop-tooltip:focus-within .founder-loop-tooltip-bubble {
        transform: translateY(0);
    }
    .founder-loop-form-grid,
    .founder-loop-outcome-grid,
    .founder-loop-commitment-card {
        grid-template-columns: 1fr;
    }
    .founder-loop-metrics {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    .founder-loop-path {
        grid-template-columns: 1fr;
        gap: .75rem;
    }
    .founder-loop-action-footer {
        display: grid;
        grid-template-columns: 1fr;
    }
    .founder-loop-action-footer .btn-premium-primary,
    .founder-loop-action-footer .btn-premium-secondary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<?php echo VideoBrandOverlayUi::assets(); ?>

<div class="dashboard-premium founder-loop-dashboard">
    <div class="founder-loop-shell">
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="founder-loop-header" aria-label="Founder loop overview">
        <div>
            <div class="founder-loop-kicker">Founder Operating Loop</div>
            <h1 class="founder-loop-title">Founder Loop</h1>
        </div>
        <div class="founder-loop-actions" aria-label="Founder Loop actions">
            <a class="founder-loop-icon-action founder-loop-tooltip" href="startup_journey.php" aria-label="Review completed Clarity Journey foundation">
                <i class="fas fa-route" aria-hidden="true"></i><span>Review Clarity</span>
                <span class="founder-loop-tooltip-bubble" role="tooltip">Revisit the completed Clarity Journey foundation.</span>
            </a>
            <button class="founder-loop-icon-action founder-loop-guide-action founder-loop-tooltip" type="button" data-founder-loop-video-open aria-label="Watch the Founder Loop page guide">
                <i class="fas fa-play-circle" aria-hidden="true"></i><span>Watch guide</span>
                <span class="founder-loop-tooltip-bubble" role="tooltip"><?php echo $founderLoopExplainerVideoUrl !== '' ? 'Learn how to use this page.' : 'Guide video is waiting for setup.'; ?></span>
            </button>
        </div>
    </section>

    <section class="founder-loop-panel founder-loop-focus" aria-label="Next founder loop action">
        <div>
            <div class="founder-loop-kicker">Next</div>
            <h2 class="founder-loop-next-title"><?php echo htmlspecialchars((string) ($currentStep['label'] ?? 'Continue the loop')); ?></h2>
        </div>
        <div class="founder-loop-next-copy"><?php echo htmlspecialchars((string) ($summary['next_action'] ?? 'Complete the next blocked founder step.')); ?></div>
        <div>
            <?php if (!empty($currentStep['gap'])): ?>
                <span class="founder-loop-pill"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><?php echo htmlspecialchars((string) $currentStep['gap']); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="founder-loop-metrics" aria-label="Founder finance snapshot">
        <div class="founder-loop-metric metric-card founder-loop-tooltip" tabindex="0" aria-label="Cash In: paid revenue recorded for this founder loop snapshot.">
            <div class="metric-card-header"><div class="metric-label">Cash In</div><div class="metric-icon"><i class="fas fa-dollar-sign" aria-hidden="true"></i></div></div>
            <div class="metric-value"><?php echo htmlspecialchars(founderLoopMoney((float) ($finance['money_in'] ?? 0), $currencies, $defaultCurrencyCode)); ?></div>
            <span class="founder-loop-tooltip-bubble" role="tooltip">Paid revenue in this snapshot.</span>
        </div>
        <div class="founder-loop-metric metric-card founder-loop-tooltip" tabindex="0" aria-label="Cash Out: expenses recorded for this founder loop snapshot.">
            <div class="metric-card-header"><div class="metric-label">Cash Out</div><div class="metric-icon"><i class="fas fa-credit-card" aria-hidden="true"></i></div></div>
            <div class="metric-value"><?php echo htmlspecialchars(founderLoopMoney((float) ($finance['money_out'] ?? 0), $currencies, $defaultCurrencyCode)); ?></div>
            <span class="founder-loop-tooltip-bubble" role="tooltip">Expenses in this snapshot.</span>
        </div>
        <div class="founder-loop-metric metric-card founder-loop-tooltip" tabindex="0" aria-label="Runway: estimated months before cash runs out.">
            <div class="metric-card-header"><div class="metric-label">Runway</div><div class="metric-icon"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div></div>
            <div class="metric-value"><?php echo ($finance['runway_months'] ?? null) === null ? 'Unknown' : htmlspecialchars(number_format((float) $finance['runway_months'], 1) . ' months'); ?></div>
            <span class="founder-loop-tooltip-bubble" role="tooltip">Estimated months before cash runs out.</span>
        </div>
        <div class="founder-loop-metric metric-card founder-loop-tooltip" tabindex="0" aria-label="Break-even: deals needed to cover current burn.">
            <div class="metric-card-header"><div class="metric-label">Break-even</div><div class="metric-icon"><i class="fas fa-handshake" aria-hidden="true"></i></div></div>
            <div class="metric-value"><?php echo ($finance['break_even_deals'] ?? null) === null ? 'Unknown' : (int) $finance['break_even_deals']; ?></div>
            <span class="founder-loop-tooltip-bubble" role="tooltip">Deals needed to cover current burn.</span>
        </div>
        <div class="founder-loop-metric metric-card founder-loop-tooltip" tabindex="0" aria-label="Review: current weekly review status.">
            <div class="metric-card-header"><div class="metric-label">Review</div><div class="metric-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></div></div>
            <div class="metric-value"><?php echo htmlspecialchars(founderLoopStatusLabel((string) ($review['review_status'] ?? 'not_started'))); ?></div>
            <span class="founder-loop-tooltip-bubble" role="tooltip">Current weekly review status.</span>
        </div>
    </section>

    <div class="founder-loop-workbench">
        <aside class="founder-loop-step-rail" aria-label="Founder Operating Loop step rail">
            <div class="founder-loop-section-head">
                <div>
                    <div class="founder-loop-kicker">Loop</div>
                    <h2 class="founder-loop-section-title">Steps</h2>
                </div>
            </div>
            <nav class="founder-loop-path" aria-label="Founder Operating Loop steps">
                <?php foreach ($steps as $step): ?>
                    <?php $status = (string) ($step['status'] ?? 'blocked'); ?>
                    <div class="founder-loop-step is-<?php echo htmlspecialchars($status); ?>">
                        <div class="founder-loop-step-top">
                            <span class="founder-loop-step-number">Step <?php echo (int) ($step['order'] ?? 0); ?></span>
                            <span class="founder-loop-status-chip founder-loop-tooltip is-<?php echo htmlspecialchars($status); ?>" tabindex="0" aria-label="<?php echo htmlspecialchars(founderLoopStatusTooltip($status)); ?>">
                                <?php echo htmlspecialchars(founderLoopStatusLabel($status)); ?>
                                <span class="founder-loop-tooltip-bubble" role="tooltip"><?php echo htmlspecialchars(founderLoopStatusTooltip($status)); ?></span>
                            </span>
                        </div>
                        <strong><?php echo htmlspecialchars((string) ($step['label'] ?? 'Step')); ?></strong>
                        <em><?php echo htmlspecialchars((string) ($step['metric'] ?? '')); ?></em>
                    </div>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="founder-loop-main">
    <div class="founder-loop-grid">
        <section class="founder-loop-panel">
            <div class="founder-loop-section-head">
                <div>
                    <div class="founder-loop-kicker">Readiness</div>
                    <h2 class="founder-loop-section-title">Pricing</h2>
                </div>
            </div>
            <div class="founder-loop-pricing-list">
                <div class="founder-loop-pricing-row"><span>Target deal value</span><strong><?php echo htmlspecialchars(founderLoopMoney((float) ($pricing['target_deal_value'] ?? 0), $currencies, $defaultCurrencyCode)); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Average paid invoice</span><strong><?php echo ($pricing['average_paid_invoice'] ?? null) === null ? 'No paid invoices' : htmlspecialchars(founderLoopMoney((float) $pricing['average_paid_invoice'], $currencies, $defaultCurrencyCode)); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>CAC / target CAC</span><strong><?php echo ($pricing['cac'] ?? null) === null ? 'Unknown' : htmlspecialchars(founderLoopMoney((float) $pricing['cac'], $currencies, $defaultCurrencyCode)); ?> / <?php echo ($pricing['target_cac'] ?? null) === null ? 'Not set' : htmlspecialchars(founderLoopMoney((float) $pricing['target_cac'], $currencies, $defaultCurrencyCode)); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Payback</span><strong><?php echo ($pricing['payback_months'] ?? null) === null ? 'Unknown' : htmlspecialchars(number_format((float) $pricing['payback_months'], 1) . ' months'); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Gross margin</span><strong><?php echo ($pricing['gross_margin_percent'] ?? null) === null ? 'Unknown' : htmlspecialchars(number_format((float) $pricing['gross_margin_percent'], 1) . '%'); ?></strong></div>
            </div>
            <?php if (!empty($pricing['warnings'])): ?>
                <div class="founder-loop-warning-list">
                    <?php foreach ((array) $pricing['warnings'] as $warning): ?>
                        <div class="founder-loop-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?php echo htmlspecialchars((string) $warning); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="founder-loop-note">Inputs usable.</div>
            <?php endif; ?>
        </section>

        <section class="founder-loop-panel">
            <div class="founder-loop-section-head">
                <div>
                    <div class="founder-loop-kicker">Weekly Plan</div>
                    <h2 class="founder-loop-section-title">First Customers</h2>
                </div>
            </div>
            <div class="founder-loop-pricing-list">
                <div class="founder-loop-pricing-row"><span>Focus</span><strong><?php echo htmlspecialchars((string) ($weeklyPlan['focus'] ?? 'Create first customer conversations')); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Target customer</span><strong><?php echo htmlspecialchars((string) ($weeklyPlan['target_customer_segment'] ?? 'target customers')); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Channel</span><strong><?php echo htmlspecialchars((string) ($weeklyPlan['outreach_channel'] ?? 'direct outreach')); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Offer</span><strong><?php echo htmlspecialchars((string) ($weeklyPlan['offer_pitch'] ?? 'Clarity Journey offer')); ?></strong></div>
                <div class="founder-loop-pricing-row"><span>Targets</span><strong><?php echo (int) ($weeklyPlan['weekly_outreach_target'] ?? 0); ?> touches / <?php echo (int) ($weeklyPlan['demo_booking_target'] ?? 0); ?> bookings / <?php echo (int) ($weeklyPlan['paid_customer_target'] ?? 0); ?> paid</strong></div>
            </div>
            <?php if ($canManageLoop): ?>
                <form method="POST" class="founder-loop-row-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="founder_loop_action" value="create_first_customer_tasks">
                    <button class="btn-premium-secondary" type="submit">Create CRM tasks from commitments</button>
                </form>
            <?php else: ?>
                <div class="founder-loop-empty">You can view the generated plan, but creating tasks requires Founder Loop manage access.</div>
            <?php endif; ?>
            <div class="founder-loop-summary-chips" aria-label="This week">
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Strongest first-customer gap.">
                    <?php echo htmlspecialchars((string) ($firstCustomerSignal['headline'] ?? 'No first-customer movement yet')); ?>
                    <span class="founder-loop-tooltip-bubble" role="tooltip"><?php echo htmlspecialchars((string) ($firstCustomerSignal['strongest_gap'] ?? 'CRM signals are current for this week.')); ?></span>
                </span>
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Leads created this week.">
                    <?php echo (int) ($firstCustomerSignal['leads_created'] ?? $metrics['leads_created'] ?? 0); ?> leads
                    <span class="founder-loop-tooltip-bubble" role="tooltip">Leads created this week.</span>
                </span>
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Open deals in CRM.">
                    <?php echo (int) ($firstCustomerSignal['open_deals'] ?? $metrics['open_deals'] ?? 0); ?> open deals
                    <span class="founder-loop-tooltip-bubble" role="tooltip">Active deals currently in CRM.</span>
                </span>
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Deals opened this week.">
                    <?php echo (int) ($firstCustomerSignal['deals_opened'] ?? $metrics['deals_opened'] ?? 0); ?> opened
                    <span class="founder-loop-tooltip-bubble" role="tooltip">Deals opened this week.</span>
                </span>
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Deals won this week.">
                    <?php echo (int) ($firstCustomerSignal['deals_won'] ?? $metrics['deals_won'] ?? 0); ?> won
                    <span class="founder-loop-tooltip-bubble" role="tooltip">Deals won this week.</span>
                </span>
                <span class="founder-loop-pill founder-loop-tooltip" tabindex="0" aria-label="Founder-loop tasks still open.">
                    <?php echo (int) ($firstCustomerSignal['open_founder_tasks'] ?? $metrics['founder_loop_open_tasks'] ?? 0); ?> tasks
                    <span class="founder-loop-tooltip-bubble" role="tooltip">Founder-loop tasks still open.</span>
                </span>
            </div>
        </section>
    </div>

    <section class="founder-loop-panel founder-loop-review-panel" data-guided-demo-target="founder-loop-weekly-review">
        <div class="founder-loop-section-head">
            <div>
                <div class="founder-loop-kicker">Execution</div>
                <h2 class="founder-loop-section-title">Weekly Review</h2>
            </div>
        </div>
        <?php if ($canManageLoop): ?>
            <form method="POST" class="founder-loop-week">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="founder_loop_action" value="save_review">
                <div class="founder-loop-review-grid">
                    <div class="founder-loop-review-block">
                        <div class="founder-loop-review-block-head">
                            <div class="founder-loop-kicker">Week</div>
                        </div>
                        <div class="founder-loop-form-grid is-tight">
                            <label class="founder-loop-field">Start
                                <input type="date" name="week_start" value="<?php echo htmlspecialchars($weekStart); ?>">
                            </label>
                            <label class="founder-loop-field">End
                                <input type="date" value="<?php echo htmlspecialchars($weekEnd); ?>" disabled>
                            </label>
                        </div>
                    </div>

                    <div class="founder-loop-review-block">
                        <div class="founder-loop-review-block-head">
                            <div class="founder-loop-kicker">Outcomes</div>
                        </div>
                        <div class="founder-loop-outcome-grid">
                            <label class="founder-loop-field">Conversations
                                <input type="number" min="0" name="customer_conversations" value="<?php echo (int) ($review['customer_conversations'] ?? 0); ?>">
                            </label>
                            <label class="founder-loop-field">Leads
                                <input type="number" min="0" name="leads_created" value="<?php echo (int) ($review['leads_created'] ?? $metrics['leads_created'] ?? 0); ?>">
                            </label>
                            <label class="founder-loop-field">Opened
                                <input type="number" min="0" name="deals_opened" value="<?php echo (int) ($review['deals_opened'] ?? $metrics['deals_opened'] ?? 0); ?>">
                            </label>
                            <label class="founder-loop-field">Won
                                <input type="number" min="0" name="deals_won" value="<?php echo (int) ($review['deals_won'] ?? $metrics['deals_won'] ?? 0); ?>">
                            </label>
                        </div>
                    </div>

                    <div class="founder-loop-review-block">
                        <div class="founder-loop-review-block-head">
                            <div class="founder-loop-kicker">Notes</div>
                        </div>
                        <div class="founder-loop-form-grid is-tight">
                            <label class="founder-loop-field">Wins
                                <textarea name="wins"><?php echo htmlspecialchars((string) ($review['wins'] ?? '')); ?></textarea>
                            </label>
                            <label class="founder-loop-field">Blockers
                                <textarea name="blockers"><?php echo htmlspecialchars((string) ($review['blockers'] ?? '')); ?></textarea>
                            </label>
                            <label class="founder-loop-field">Pricing concern
                                <textarea name="pricing_concern"><?php echo htmlspecialchars((string) ($review['pricing_concern'] ?? '')); ?></textarea>
                            </label>
                            <label class="founder-loop-field">Next week focus
                                <textarea name="next_week_focus"><?php echo htmlspecialchars((string) ($review['next_week_focus'] ?? '')); ?></textarea>
                            </label>
                        </div>
                    </div>

                    <div class="founder-loop-review-block">
                        <div class="founder-loop-review-block-head">
                            <div class="founder-loop-kicker">Snapshot</div>
                        </div>
                        <div class="founder-loop-metrics" style="margin-bottom:0;">
                            <div class="founder-loop-metric metric-card is-compact"><div class="metric-label">Revenue</div><div class="metric-value"><?php echo htmlspecialchars(founderLoopMoney((float) ($finance['money_in'] ?? 0), $currencies, $defaultCurrencyCode)); ?></div></div>
                            <div class="founder-loop-metric metric-card is-compact"><div class="metric-label">Expenses</div><div class="metric-value"><?php echo htmlspecialchars(founderLoopMoney((float) ($finance['money_out'] ?? 0), $currencies, $defaultCurrencyCode)); ?></div></div>
                            <div class="founder-loop-metric metric-card is-compact"><div class="metric-label">Burn</div><div class="metric-value"><?php echo htmlspecialchars(founderLoopMoney((float) ($finance['burn_rate'] ?? 0), $currencies, $defaultCurrencyCode)); ?></div></div>
                            <div class="founder-loop-metric metric-card is-compact"><div class="metric-label">CAC</div><div class="metric-value"><?php echo ($finance['cac'] ?? null) === null ? 'Unknown' : htmlspecialchars(founderLoopMoney((float) $finance['cac'], $currencies, $defaultCurrencyCode)); ?></div></div>
                            <div class="founder-loop-metric metric-card is-compact"><div class="metric-label">Runway</div><div class="metric-value"><?php echo ($finance['runway_months'] ?? null) === null ? 'Unknown' : htmlspecialchars(number_format((float) $finance['runway_months'], 1) . ' months'); ?></div></div>
                        </div>
                    </div>

                    <div class="founder-loop-review-block">
                        <div class="founder-loop-review-block-head">
                            <div class="founder-loop-kicker">Commitments</div>
                        </div>
                        <div class="founder-loop-commitments">
                            <?php for ($i = 0; $i < 3; $i++): ?>
                                <?php $commitment = (array) ($reviewCommitments[$i] ?? []); ?>
                                <div class="founder-loop-commitment-card">
                                    <label class="founder-loop-field">Title
                                        <input name="commitment_title[]" placeholder="Commitment title" value="<?php echo htmlspecialchars((string) ($commitment['title'] ?? '')); ?>">
                                    </label>
                                    <label class="founder-loop-field">Description
                                        <input name="commitment_description[]" placeholder="Description" value="<?php echo htmlspecialchars((string) ($commitment['description'] ?? '')); ?>">
                                    </label>
                                    <label class="founder-loop-field">Due
                                        <input type="date" name="commitment_due_date[]" value="<?php echo htmlspecialchars((string) ($commitment['due_date'] ?? '')); ?>">
                                    </label>
                                    <label class="founder-loop-field">Status
                                        <select name="commitment_status[]">
                                            <?php foreach (['pending', 'in_progress', 'completed', 'cancelled'] as $status): ?>
                                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo (string) ($commitment['status'] ?? 'pending') === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(founderLoopStatusLabel($status)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="founder-loop-action-footer">
                    <button class="btn-premium-secondary" type="submit">Save draft</button>
                    <button class="btn-premium-primary" type="submit" onclick="this.form.founder_loop_action.value='complete_review';">Complete review</button>
                </div>
            </form>
        <?php else: ?>
            <div class="founder-loop-empty">You can view the review, but saving it requires Founder Loop manage access.</div>
        <?php endif; ?>
    </section>
        </div>
    </div>
    </div>
</div>
<div class="founder-loop-video-modal" data-founder-loop-video-modal role="dialog" aria-modal="true" aria-labelledby="founder-loop-video-title" hidden>
    <div class="founder-loop-video-dialog">
        <div class="founder-loop-video-head">
            <h2 class="founder-loop-video-title" id="founder-loop-video-title">How to use Founder Loop</h2>
            <button class="founder-loop-video-close" type="button" data-founder-loop-video-close aria-label="Close guide video">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="founder-loop-video-frame">
            <?php if ($founderLoopExplainerVideoUrl !== ''): ?>
                <?php echo VideoBrandOverlayUi::frame(
                    '<video controls preload="metadata" playsinline data-founder-loop-video>'
                    . '<source src="' . htmlspecialchars($founderLoopExplainerVideoUrl, ENT_QUOTES, 'UTF-8') . '">'
                    . 'Your browser does not support embedded video playback.'
                    . '</video>'
                ); ?>
            <?php else: ?>
                <div class="founder-loop-video-empty">
                    <i class="fas fa-play-circle" aria-hidden="true"></i>
                    <strong>Guide video is not configured yet.</strong>
                    <span>A superadmin can upload the Founder Loop page guide from Marketplace page explainers.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var openButton = document.querySelector('[data-founder-loop-video-open]');
    var modal = document.querySelector('[data-founder-loop-video-modal]');
    if (!openButton || !modal) return;

    var closeButton = modal.querySelector('[data-founder-loop-video-close]');
    var video = modal.querySelector('[data-founder-loop-video]');
    var lastFocused = null;

    function openModal() {
        lastFocused = document.activeElement;
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        modal.hidden = false;
        modal.scrollTop = 0;
        document.body.style.overflow = 'hidden';
        if (video) {
            try { video.currentTime = 0; } catch (error) {}
            var playAttempt = video.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                playAttempt.catch(function () {});
            }
        }
        if (closeButton) closeButton.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (video) {
            video.pause();
            try { video.currentTime = 0; } catch (error) {}
        }
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    openButton.addEventListener('click', openModal);
    if (closeButton) closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Founder Operating Loop';
include __DIR__ . '/../views/layouts/base.php';
