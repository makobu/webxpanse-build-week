<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingWorkflowNextStepsUi;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to run marketing performance analysis.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        if ((string) ($_POST['action'] ?? '') === 'performance_analysis') {
            $marketing->runMarketingPerformanceAnalysis($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=performance_analysis');
            exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'create_budget') {
            $marketing->createCampaignBudget($_POST + ['created_by' => (int) ($user['id'] ?? 0), 'owner_user_id' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=budget');
            exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'create_spend') {
            $marketing->createChannelSpend($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=spend');
            exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'create_roi_target') {
            $marketing->createRoiTarget($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=roi_target');
            exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'create_experiment') {
            $marketing->createExperiment($_POST + ['created_by' => (int) ($user['id'] ?? 0), 'owner_user_id' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=experiment');
            exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'create_conversion_goal') {
            $marketing->createConversionGoal($_POST + ['created_by' => (int) ($user['id'] ?? 0), 'owner_user_id' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_performance.php?success=conversion_goal');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$summary = $marketing->getPerformanceSummary();
$budgetSummary = (array) ($summary['budget_roi'] ?? []);
$trackingSummary = (array) ($summary['tracking'] ?? []);
$attributionSummary = (array) ($summary['attribution_pipeline'] ?? []);
$goalSummary = (array) ($summary['conversion_goals'] ?? []);
$handoffSummary = (array) ($summary['lead_handoffs'] ?? []);
$revenueLoop = (array) ($summary['campaign_revenue_loop'] ?? []);
$options = $marketing->optionData();
$budgets = $marketing->listCampaignBudgets([], 12, 0);
$experiments = $marketing->listExperiments([], 12, 0);
$performanceSuggestions = $marketing->listMarketingAiQueueSuggestions('phase_7', 6, 0);
$performanceRuns = $marketing->listMarketingAssistantRuns(['assistant_type' => 'performance_analyst'], 6, 0);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'performance', 6);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$money = static fn(float $value): string => number_format($value, 2);
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$roi = $summary['campaign_roi']['roi_percent'] ?? null;
$loopScore = (int) ($revenueLoop['score'] ?? 0);
$conversionCount = (int) ($trackingSummary['conversions'] ?? 0);
$attributedRevenue = (float) ($summary['revenue_attribution']['total_revenue'] ?? $attributionSummary['influenced_revenue'] ?? 0);
$touchpoints = (int) ($attributionSummary['touchpoints'] ?? 0);
$activeGoals = (int) ($goalSummary['active'] ?? 0);
$openHandoffs = (int) ($handoffSummary['open'] ?? 0);
$experimentCount = count($experiments);
$budgetCount = count($budgets);
$analysisCount = count($performanceSuggestions) + count($performanceRuns);

$summaryTiles = [
    ['icon' => 'fa-route', 'label' => 'Loop', 'value' => $loopScore . '%', 'tooltip' => 'Campaign-to-revenue readiness across tracking, attribution, handoffs, and ROI.'],
    ['icon' => 'fa-bullseye', 'label' => 'Conversions', 'value' => (string) $conversionCount, 'tooltip' => 'Tracked conversion events from landing and campaign activity.'],
    ['icon' => 'fa-dollar-sign', 'label' => 'Revenue', 'value' => '$' . $money($attributedRevenue), 'tooltip' => 'Revenue currently connected to marketing attribution evidence.'],
    ['icon' => 'fa-chart-line', 'label' => 'ROI', 'value' => $roi === null ? 'N/A' : (string) $roi . '%', 'tooltip' => 'Campaign ROI where budget and attributed revenue are available.'],
];
$stageCards = [
    ['icon' => 'fa-link', 'title' => 'Check Tracking', 'status' => (int) ($summary['utm_links'] ?? 0) > 0 || (int) ($trackingSummary['page_views'] ?? 0) > 0 ? 'ready' : 'setup_needed', 'sentence' => (int) ($summary['utm_links'] ?? 0) . ' UTM links.', 'tooltip' => 'Tracking must exist before performance claims are useful.', 'href' => '#attribution-pipeline', 'action' => 'Tracking'],
    ['icon' => 'fa-dollar-sign', 'title' => 'Read Revenue', 'status' => $attributedRevenue > 0 ? 'ready' : 'setup_needed', 'sentence' => '$' . $money($attributedRevenue) . ' linked.', 'tooltip' => 'Revenue evidence connects campaign work to real commercial outcomes.', 'href' => '#campaign-revenue-loop', 'action' => 'Revenue'],
    ['icon' => 'fa-bullseye', 'title' => 'Review Goals', 'status' => $activeGoals > 0 ? 'ready' : 'setup_needed', 'sentence' => $activeGoals . ' active goals.', 'tooltip' => 'Conversion goals keep the founder focused on the outcome, not the volume of activity.', 'href' => '#conversion-goals', 'action' => 'Goals'],
    ['icon' => 'fa-flask', 'title' => 'Run Test', 'status' => $experimentCount > 0 ? 'in_use' : 'setup_needed', 'sentence' => $experimentCount . ' experiments.', 'tooltip' => 'Experiments turn weak performance signals into the next controlled test.', 'href' => '#performance-planning', 'action' => 'Tests'],
    ['icon' => 'fa-lightbulb', 'title' => 'Next Lesson', 'status' => $analysisCount > 0 ? 'ready' : 'setup_needed', 'sentence' => $analysisCount . ' insights.', 'tooltip' => 'AI and workflow suggestions stay as planning advice; they do not publish or send.', 'href' => '#ai-performance-analysis', 'action' => 'Learn'],
];
$todayActions = array_slice(array_values(array_filter([
    $loopScore < 60 ? ['label' => 'Improve revenue loop', 'href' => '#campaign-revenue-loop', 'reason' => 'Connect missing tracking, handoff, or ROI evidence.'] : null,
    $activeGoals === 0 ? ['label' => 'Add conversion goal', 'href' => '#conversion-goals', 'reason' => 'Define what success means before judging performance.'] : null,
    $budgetCount === 0 ? ['label' => 'Add budget baseline', 'href' => '#performance-planning', 'reason' => 'ROI needs a planned budget or target.'] : null,
    $experimentCount === 0 ? ['label' => 'Plan one test', 'href' => '#performance-planning', 'reason' => 'Turn the next learning gap into an experiment.'] : null,
    ['label' => 'Start next campaign', 'href' => 'marketing_briefs.php', 'reason' => 'Turn the lesson into the next campaign plan.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Lead Handoffs', 'href' => 'marketing_handoffs.php', 'hint' => 'Review open and converted campaign handoffs.'],
    ['label' => 'Weekly Report', 'href' => 'marketing_weekly_report.php', 'hint' => 'Summarize short-term performance signals.'],
    ['label' => 'Monthly Report', 'href' => 'marketing_monthly_report.php', 'hint' => 'Review longer reporting cycles.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'hint' => 'Prepare tracking links before judging channels.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect performance evidence back to campaign planning.'],
];

$pageTitle = 'Marketing Performance - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-performance-page"><div class="container">
    <div class="page-header">
        <div><h1>Marketing Performance</h1><p>Learn what happened and choose the next test.</p></div>
        <div class="page-header-actions"><a class="btn-premium-secondary" href="marketing_distribution.php"><i class="fas fa-arrow-left"></i> Send / Export</a></div>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'performance_analysis'): ?><div class="alert alert-success">AI performance analysis saved recommendations to the planning queue.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'budget'): ?><div class="alert alert-success">Campaign budget saved.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'spend'): ?><div class="alert alert-success">Channel spend recorded.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'roi_target'): ?><div class="alert alert-success">ROI target saved.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'experiment'): ?><div class="alert alert-success">Experiment saved for manual tracking.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'conversion_goal'): ?><div class="alert alert-success">Conversion goal saved and linked for manual tracking.</div><?php endif; ?>

    <section class="marketing-performance-shell">
        <div class="marketing-founder-summary marketing-performance-summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <article class="marketing-summary-tile" data-tooltip="<?php echo $h($tile['tooltip']); ?>" tabindex="0">
                    <i class="fas <?php echo $h($tile['icon']); ?>"></i>
                    <div><span><?php echo $h($tile['label']); ?></span><strong><?php echo $h($tile['value']); ?></strong></div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="marketing-performance-stage-grid">
            <?php foreach ($stageCards as $stage): ?>
                <article class="marketing-performance-stage-card <?php echo $h((string) $stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                    <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                    <div class="marketing-performance-stage-body">
                        <span class="badge <?php echo in_array((string) $stage['status'], ['ready', 'in_use'], true) ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize((string) $stage['status'])); ?></span>
                        <h2><?php echo $h($stage['title']); ?></h2>
                        <p><?php echo $h($stage['sentence']); ?></p>
                    </div>
                    <a class="btn-premium-secondary marketing-performance-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="marketing-performance-layout">
            <main class="content-card marketing-performance-loop" id="campaign-revenue-loop">
                <div class="premium-section-header"><div><h2>Campaign-To-Revenue Loop</h2><p>Campaign Readiness without the spreadsheet wall.</p></div></div>
                <div class="marketing-performance-loop-grid">
                    <article><strong><?php echo $loopScore; ?>%</strong><span>Loop Score</span></article>
                    <article><strong><?php echo (int) ($revenueLoop['counts']['ready'] ?? 0); ?></strong><span>Ready Campaigns</span></article>
                    <article><strong><?php echo (int) ($revenueLoop['counts']['blocked'] ?? 0); ?></strong><span>Blocked Campaigns</span></article>
                    <article><strong>$<?php echo $money((float) ($revenueLoop['counts']['attributed_revenue'] ?? 0)); ?></strong><span>Revenue Evidence</span></article>
                </div>
                <?php if (empty($revenueLoop['campaigns'])): ?>
                    <div class="empty-state"><p>No campaign-to-revenue loop data yet.</p></div>
                <?php else: ?>
                    <div class="marketing-performance-card-list">
                        <?php foreach (array_slice((array) ($revenueLoop['campaigns'] ?? []), 0, 6) as $campaignLoop): ?>
                            <?php $pipeline = (array) ($campaignLoop['pipeline_counts'] ?? []); ?>
                            <article class="marketing-performance-signal-card" data-tooltip="<?php echo $h('Landing: ' . (int) ($pipeline['landing_pages'] ?? 0) . '. UTM: ' . (int) ($pipeline['utm_links'] ?? 0) . '. Handoffs: ' . (int) ($pipeline['lead_handoffs'] ?? 0) . '.'); ?>" tabindex="0">
                                <div class="marketing-stage-visual"><i class="fas fa-route"></i></div>
                                <div>
                                    <span class="badge <?php echo (string) ($campaignLoop['status'] ?? '') === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize((string) ($campaignLoop['status'] ?? 'attention'))); ?></span>
                                    <h3><?php echo $h($campaignLoop['campaign_name'] ?? 'Campaign'); ?></h3>
                                    <p><?php echo (int) ($campaignLoop['score'] ?? 0); ?>% ready</p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>

            <aside class="content-card marketing-performance-goals" id="conversion-goals">
                <div class="premium-section-header"><div><h2>Conversion Goals</h2><p>Add Conversion Goal when success is unclear.</p></div></div>
                <div class="marketing-performance-mini-list">
                    <?php if (empty($goalSummary['goals'])): ?>
                        <div class="empty-state"><p>No conversion goals yet.</p></div>
                    <?php else: foreach (array_slice((array) $goalSummary['goals'], 0, 4) as $goal): ?>
                        <article>
                            <strong><?php echo $h($goal['title']); ?></strong>
                            <span><?php echo (int) ($goal['readiness_score'] ?? 0); ?>% ready</span>
                        </article>
                    <?php endforeach; endif; ?>
                </div>
                <?php if ($canWriteMarketing): ?>
                    <details class="marketing-performance-form-drawer">
                        <summary>Add Conversion Goal</summary>
                        <form method="POST" class="marketing-performance-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create_conversion_goal">
                            <div class="form-group"><label>Goal Title</label><input type="text" name="title" required placeholder="Demo requests from launch campaign"></div>
                            <div class="form-group"><label>Goal Type</label><select name="goal_type"><?php foreach (Marketing::CONVERSION_GOAL_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Success Metric</label><input type="text" name="success_metric" value="conversion_count"></div>
                            <div class="marketing-performance-form-grid">
                                <div class="form-group"><label>Target Count</label><input type="number" min="0" step="1" name="target_count" placeholder="25"></div>
                                <div class="form-group"><label>Target Value</label><input type="number" min="0" step="0.01" name="target_value" placeholder="5000"></div>
                            </div>
                            <details class="marketing-performance-nested-drawer">
                                <summary>Connect records</summary>
                                <div class="marketing-performance-form-grid">
                                    <div class="form-group"><label>Tracking Source</label><select name="tracking_source"><?php foreach (Marketing::CONVERSION_GOAL_TRACKING_SOURCES as $source): ?><option value="<?php echo $h($source); ?>"><?php echo $h($labelize($source)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Status</label><select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></div>
                                    <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach (($options['landing_pages'] ?? []) as $landing): ?><option value="<?php echo (int) $landing['id']; ?>"><?php echo $h($landing['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach (($options['campaigns'] ?? []) as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo $h($campaign['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Form</label><select name="form_id"><option value="">None</option><?php foreach (($options['forms'] ?? []) as $linkedForm): ?><option value="<?php echo (int) $linkedForm['id']; ?>"><?php echo $h($linkedForm['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">None</option><?php foreach (($options['content_items'] ?? []) as $contentItem): ?><option value="<?php echo (int) $contentItem['id']; ?>"><?php echo $h($contentItem['title']); ?></option><?php endforeach; ?></select></div>
                                </div>
                            </details>
                            <button class="btn-premium-primary" type="submit">Save Goal</button>
                        </form>
                    </details>
                <?php endif; ?>
            </aside>
        </div>

        <aside class="content-card marketing-performance-today">
            <div class="premium-section-header"><div><h2>Today</h2><p>At most five actions.</p></div></div>
            <div class="marketing-today-list">
                <?php foreach ($todayActions as $action): ?>
                    <a class="marketing-today-action" href="<?php echo $h($action['href']); ?>">
                        <span><?php echo $h($action['label']); ?></span>
                        <small><?php echo $h($action['reason']); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="marketing-performance-secondary-grid">
            <article class="content-card marketing-performance-signal-board" id="attribution-pipeline">
                <div class="premium-section-header"><div><h2>Attribution Pipeline</h2><p>Influenced Leads, touchpoints, and revenue.</p></div></div>
                <div class="marketing-performance-loop-grid">
                    <article><strong><?php echo $touchpoints; ?></strong><span>Touchpoints</span></article>
                    <article><strong><?php echo (int) ($attributionSummary['influenced_leads'] ?? 0); ?></strong><span>Influenced Leads</span></article>
                    <article><strong><?php echo (int) ($attributionSummary['influenced_deals'] ?? 0); ?></strong><span>Deals</span></article>
                    <article><strong>$<?php echo $money((float) ($attributionSummary['influenced_revenue'] ?? 0)); ?></strong><span>Revenue</span></article>
                </div>
            </article>
            <article class="content-card marketing-performance-signal-board">
                <div class="premium-section-header"><div><h2>Tracking Signals</h2><p>Views, CTA clicks, conversions, and UTM performance.</p></div></div>
                <div class="marketing-performance-loop-grid">
                    <article><strong><?php echo (int) ($trackingSummary['page_views'] ?? 0); ?></strong><span>Landing Views</span></article>
                    <article><strong><?php echo (int) ($trackingSummary['cta_clicks'] ?? 0); ?></strong><span>CTA Clicks</span></article>
                    <article><strong><?php echo $conversionCount; ?></strong><span>Conversions</span></article>
                    <article><strong><?php echo (int) ($summary['utm_performance']['total_links'] ?? $summary['utm_links'] ?? 0); ?></strong><span>UTM Performance</span></article>
                </div>
            </article>
        </section>

        <details class="content-card marketing-performance-tools" id="performance-planning">
            <summary>More performance tools</summary>
            <div class="marketing-performance-tools-body">
                <div class="marketing-advanced-tools-grid">
                    <?php foreach ($expertLinks as $link): ?>
                        <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>" tabindex="0">
                            <strong><?php echo $h($link['label']); ?></strong>
                            <span><?php echo $h($link['hint']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <details class="marketing-performance-detail-section" open>
                    <summary>Results next steps</summary>
                    <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                </details>

                <details class="marketing-performance-detail-section">
                    <summary>Budget And ROI Planning</summary>
                    <div class="marketing-performance-card-list">
                        <?php if (empty($budgets)): ?><div class="empty-state"><p>No campaign budgets yet.</p></div><?php else: foreach ($budgets as $budget): ?>
                            <article class="marketing-performance-signal-card"><div class="marketing-stage-visual"><i class="fas fa-wallet"></i></div><div><h3><?php echo $h($budget['title']); ?></h3><p><?php echo $h($budget['currency']); ?> <?php echo $money((float) $budget['actual_spend']); ?> / <?php echo $money((float) $budget['planned_budget']); ?></p></div></article>
                        <?php endforeach; endif; ?>
                    </div>
                    <?php if ($canWriteMarketing): ?>
                        <div class="marketing-performance-form-stack">
                            <form method="POST" class="marketing-performance-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="create_budget"><div class="form-group"><label>Budget Title</label><input type="text" name="title" required></div><div class="form-group"><label>Planned Budget</label><input type="number" step="0.01" min="0" name="planned_budget" required></div><div class="form-group"><label>Status</label><select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></div><button class="btn-premium-secondary" type="submit">Save Budget</button></form>
                            <form method="POST" class="marketing-performance-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="create_spend"><div class="form-group"><label>Budget</label><select name="budget_id"><option value="">None</option><?php foreach ($budgets as $budget): ?><option value="<?php echo (int) $budget['id']; ?>"><?php echo $h($budget['title']); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Channel</label><input type="text" name="channel" value="linkedin"></div><div class="form-group"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" required></div><button class="btn-premium-secondary" type="submit">Record Spend</button></form>
                            <form method="POST" class="marketing-performance-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="create_roi_target"><div class="form-group"><label>Target Title</label><input type="text" name="title" required></div><div class="form-group"><label>Budget</label><select name="budget_id"><option value="">None</option><?php foreach ($budgets as $budget): ?><option value="<?php echo (int) $budget['id']; ?>"><?php echo $h($budget['title']); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Target Revenue</label><input type="number" step="0.01" min="0" name="target_revenue" required></div><button class="btn-premium-primary" type="submit">Save ROI Target</button></form>
                        </div>
                    <?php endif; ?>
                </details>

                <details class="marketing-performance-detail-section">
                    <summary>Experiments</summary>
                    <div class="marketing-performance-card-list">
                        <?php if (empty($experiments)): ?><div class="empty-state"><p>No experiments are being tracked yet.</p></div><?php else: foreach ($experiments as $experiment): ?>
                            <article class="marketing-performance-signal-card"><div class="marketing-stage-visual"><i class="fas fa-flask"></i></div><div><h3><?php echo $h($experiment['title']); ?></h3><p><?php echo $h($labelize((string) $experiment['experiment_type'])); ?> · <?php echo $h($labelize((string) $experiment['status'])); ?></p></div></article>
                        <?php endforeach; endif; ?>
                    </div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-performance-form"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="create_experiment"><div class="form-group"><label>Title</label><input type="text" name="title" required></div><div class="form-group"><label>Type</label><select name="experiment_type"><?php foreach (Marketing::EXPERIMENT_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Hypothesis</label><textarea name="hypothesis" rows="3"></textarea></div><div class="form-group"><label>Success Metric</label><input type="text" name="success_metric" value="conversion_rate"></div><button class="btn-premium-primary" type="submit">Save Experiment</button></form>
                    <?php endif; ?>
                </details>

                <details class="marketing-performance-detail-section" id="ai-performance-analysis">
                    <summary>AI Performance Analysis</summary>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-performance-inline-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="performance_analysis">
                            <button class="btn-premium-primary" type="submit">Run Performance Analysis</button>
                        </form>
                    <?php endif; ?>
                    <div class="marketing-performance-card-list">
                        <?php foreach (array_slice($performanceSuggestions, 0, 4) as $suggestion): ?>
                            <?php $meta = (array) ($suggestion['metadata_json'] ?? []); ?>
                            <article class="marketing-performance-signal-card"><div class="marketing-stage-visual"><i class="fas fa-lightbulb"></i></div><div><h3><?php echo $h($suggestion['title']); ?></h3><p><?php echo $h($meta['recommended_action'] ?? $meta['reason'] ?? 'Manual follow-up suggested.'); ?></p></div></article>
                        <?php endforeach; ?>
                        <?php foreach (array_slice($performanceRuns, 0, 2) as $run): ?>
                            <?php $result = (array) ($run['result_json'] ?? []); ?>
                            <article class="marketing-performance-signal-card"><div class="marketing-stage-visual"><i class="fas fa-robot"></i></div><div><h3><?php echo $h($result['summary'] ?? $result['recommendation'] ?? 'Performance analysis'); ?></h3><p><?php echo $h($run['created_at']); ?></p></div></article>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
