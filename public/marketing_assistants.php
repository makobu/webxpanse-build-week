<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_MARKETING_PRO);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$options = $marketing->optionData();
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to run marketing assistants.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        if ((string) ($_POST['action'] ?? '') === 'strategy_gap_analysis') {
            $marketing->runMarketingStrategyGapAnalysis($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_assistants.php?success=strategy_gaps'); exit;
        }
        if ((string) ($_POST['action'] ?? '') === 'campaign_planner') {
            $marketing->runMarketingCampaignPlanner($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_assistants.php?success=campaign_planner'); exit;
        }
        $marketing->runMarketingAssistant((string) ($_POST['assistant_type'] ?? ''), $_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_assistants.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
if (($_GET['success'] ?? '') === '1') { $notice = 'Marketing assistant run saved.'; }
if (($_GET['success'] ?? '') === 'strategy_gaps') { $notice = 'AI strategy gaps saved to the planning queue.'; }
if (($_GET['success'] ?? '') === 'campaign_planner') { $notice = 'AI campaign plan saved to the planning queue.'; }
$runs = $marketing->listMarketingAssistantRuns([], 25, 0);
$assistantCommandCenter = $marketing->getMarketingAssistantCommandCenter((int) ($user['id'] ?? 0));
$workspaceBrain = (array) ($assistantCommandCenter['workspace_brain'] ?? []);
$contextEvidence = (array) ($assistantCommandCenter['context_evidence'] ?? []);
$contextControl = $marketing->getMarketingAiContextControlPanel($_GET, (int) ($user['id'] ?? 0));
$contextQualityGate = $marketing->getMarketingAiContextQualityGate($_GET, (int) ($user['id'] ?? 0));
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 50, 0);
$strategyGaps = $marketing->listMarketingAiQueueSuggestions('phase_5', 6, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$workspaceBrainScore = (int) ($workspaceBrain['score'] ?? 0);
$contextGateScore = (int) ($contextQualityGate['score'] ?? 0);
$contextControlScore = (int) ($contextControl['score'] ?? 0);
$evidenceScore = (int) round(((float) ($contextEvidence['average_context_score'] ?? 0)) * 100);
$assistantCommands = array_values((array) ($assistantCommandCenter['commands'] ?? []));
$nextBestActions = array_values((array) ($assistantCommandCenter['next_best_actions']['actions'] ?? []));
$summaryTiles = [
    ['label' => 'Context', 'value' => $workspaceBrainScore . '%', 'icon' => 'fa-brain', 'tooltip' => 'AI Workspace Brain: the evidence AI can safely use before giving marketing guidance.'],
    ['label' => 'Gate', 'value' => $contextGateScore . '%', 'icon' => 'fa-shield-halved', 'tooltip' => 'AI Context Quality Gate: preflight for strategy, copy, landing-page, and performance generation.'],
    ['label' => 'Evidence', 'value' => (string) (int) ($contextEvidence['counts']['total'] ?? 0), 'icon' => 'fa-layer-group', 'tooltip' => 'Auditable snapshots used before AI produces draft-side recommendations.'],
    ['label' => 'Runs', 'value' => (string) count($runs), 'icon' => 'fa-wand-magic-sparkles', 'tooltip' => 'Recent assistant runs saved for review and learning.'],
];
$assistantIcons = [
    'strategist' => 'fa-user-tie',
    'content_planner' => 'fa-calendar-days',
    'seo_researcher' => 'fa-magnifying-glass',
    'copywriter' => 'fa-pen-nib',
    'email_sequence_builder' => 'fa-envelope-open-text',
    'landing_page_optimizer' => 'fa-window-maximize',
    'repurposing' => 'fa-arrows-rotate',
    'performance_analyst' => 'fa-chart-column',
];
$todayActions = [];
foreach (array_slice($nextBestActions, 0, 2) as $action) {
    $todayActions[] = [
        'label' => (string) ($action['label'] ?? 'Review action'),
        'detail' => (string) ($action['source'] ?? 'Marketing'),
        'href' => (string) ($action['href'] ?? '#assistant-command-center'),
        'priority' => 'normal',
        'tooltip' => 'AI has a suggested next step based on current Marketing context.',
    ];
}
if ($canWriteMarketing) {
    $todayActions[] = [
        'label' => 'Ask for guidance',
        'detail' => 'Use one prompt to fill the knowledge gap.',
        'href' => '#run-assistant',
        'priority' => 'high',
        'tooltip' => 'Ask in plain language; the assistant stays draft-side and advisory.',
    ];
    $todayActions[] = [
        'label' => 'Run gap check',
        'detail' => 'Find the missing strategy input.',
        'href' => '#strategy-gap-analysis',
        'priority' => 'normal',
        'tooltip' => 'Strategy gaps are saved as planning suggestions only.',
    ];
}
$todayActions[] = [
    'label' => 'Return to Marketing',
    'detail' => 'Go back to the guided command center.',
    'href' => 'marketing.php',
    'priority' => 'low',
    'tooltip' => 'Use the main Marketing map when you are not sure what to ask.',
];
$todayActions = array_slice($todayActions, 0, 5);
$guardrails = array_values((array) ($assistantCommandCenter['guardrails'] ?? []));
$advancedLinks = [
    ['label' => 'Command Center', 'href' => 'marketing.php', 'hint' => 'Return to the guided Marketing path.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Open the drafts and content work AI may help with.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Review campaign strategy before asking for copy help.'],
    ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'hint' => 'Connect AI guidance to campaign destinations.'],
];
$workspaceContextHref = (string) ($workspaceBrain['missing_context'][0]['href'] ?? 'marketing_context.php');
$pluginQuickStart = [
    'key' => 'professional-marketer',
    'outcome' => 'Get one useful marketing recommendation',
    'steps' => [
        ['label' => 'Add your brand basics', 'complete' => $workspaceBrainScore >= 35, 'href' => $workspaceContextHref, 'action_label' => 'Add brand context'],
        ['label' => 'Check the advice context', 'complete' => $contextGateScore >= 55, 'href' => 'marketing_assistants.php#assistant-context', 'action_label' => 'Review context'],
        ['label' => 'Ask one specialist', 'complete' => count($runs) > 0, 'href' => 'marketing_assistants.php#run-assistant', 'action_label' => 'Choose specialist'],
    ],
    'completion_action' => ['href' => 'marketing_assistants.php#run-assistant', 'action_label' => 'Ask another specialist'],
];
$pageTitle = 'Marketing Assistants - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/plugin-workspaces.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/plugin-workspaces.css') ?: 1); ?>">
<script src="assets/js/plugin-workspaces.js?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/js/plugin-workspaces.js') ?: 1); ?>" defer></script>
<div class="page-premium marketing-assistants-page plugin-workspace-shell"><div class="container">
    <div class="page-header">
        <div>
            <h1>Marketing Assistants</h1>
            <p>Choose a specialist and ask.</p>
        </div>
        <div class="page-header-actions">
            <a class="workspace-setup-link" href="workspace_skills.php?module=marketing_pro#setup">Campaign setup</a>
            <a class="btn-premium-primary" href="#run-assistant"><i class="fas fa-wand-magic-sparkles"></i> Ask AI</a>
        </div>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <div class="marketing-assistants-summary">
        <?php foreach ($summaryTiles as $tile): ?>
            <div class="marketing-summary-tile" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>" tabindex="0">
                <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                <div>
                    <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                    <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php include __DIR__ . '/../views/partials/plugin_product_quick_start.php'; ?>

    <div class="marketing-assistants-layout">
        <main class="marketing-assistants-main">
            <div class="content-card marketing-assistants-board" id="assistant-command-center">
                <div class="premium-section-header">
                    <div>
                        <h2>Specialists</h2>
                    </div>
                    <span class="badge badge-default"><?php echo (int) ($assistantCommandCenter['context_completeness']['workspace_brain_score'] ?? $assistantCommandCenter['context_completeness']['score'] ?? 0); ?>% context ready</span>
                </div>
                <?php if (empty($assistantCommands)): ?>
                    <div class="empty-state"><p>No assistant commands are available yet.</p></div>
                <?php else: ?>
                    <div class="marketing-assistant-card-grid">
                        <?php foreach ($assistantCommands as $commandIndex => $command): ?>
                            <?php
                                $commandTooltip = trim((string) ($command['prompt_hint'] ?? '') . ' ' . (string) ($command['side_effect'] ?? 'Recommendation only'));
                                if ($commandTooltip === '') {
                                    $commandTooltip = 'Use this assistant for draft-side Marketing guidance.';
                                }
                            ?>
                            <a class="marketing-assistant-card <?php echo $commandIndex === 0 ? 'is-selected' : ''; ?>" href="#run-assistant" data-assistant-type="<?php echo htmlspecialchars((string) ($command['assistant_type'] ?? '')); ?>" data-prompt-hint="<?php echo htmlspecialchars((string) ($command['prompt_hint'] ?? 'What should this specialist help with?')); ?>" data-tooltip="<?php echo htmlspecialchars($commandTooltip); ?>" aria-current="<?php echo $commandIndex === 0 ? 'true' : 'false'; ?>">
                                <div class="marketing-assistant-visual" aria-hidden="true"><i class="fas <?php echo htmlspecialchars((string) ($assistantIcons[(string) ($command['assistant_type'] ?? '')] ?? 'fa-wand-magic-sparkles')); ?>"></i></div>
                                <div class="marketing-assistant-card-body">
                                    <span class="marketing-assistant-pill"><?php echo htmlspecialchars($labelize((string) ($command['last_status'] ?? 'not_run'))); ?></span>
                                    <h3><?php echo htmlspecialchars((string) ($command['label'] ?? 'Assistant')); ?></h3>
                                    <p><?php echo htmlspecialchars((string) ($command['best_for'] ?? 'Marketing support.')); ?></p>
                                </div>
                                <span class="btn-premium-secondary marketing-assistant-card-action">Use Tool</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <details class="marketing-assistants-tools" id="assistant-context">
                <summary>More AI context tools</summary>
                <div class="marketing-assistants-tools-body">
                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header">
                            <div><h2>AI Workspace Brain</h2><p>AI context quality is the evidence score behind assistant guidance.</p></div>
                            <span class="badge <?php echo (string) ($workspaceBrain['status'] ?? '') === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $workspaceBrainScore; ?>% <?php echo htmlspecialchars($labelize((string) ($workspaceBrain['status'] ?? 'thin'))); ?></span>
                        </div>
                        <div class="marketing-assistants-context-grid">
                            <?php foreach (array_slice((array) ($workspaceBrain['coverage'] ?? []), 0, 6) as $coverage): ?>
                                <?php $coverageStatus = (string) ($coverage['status'] ?? 'missing'); ?>
                                <a class="marketing-assistants-context-card <?php echo htmlspecialchars($coverageStatus); ?>" href="<?php echo htmlspecialchars((string) ($coverage['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($coverage['message'] ?? 'Review this context area.')); ?>" tabindex="0">
                                    <strong><?php echo htmlspecialchars((string) ($coverage['label'] ?? 'Context area')); ?></strong>
                                    <span><?php echo (int) ($coverage['score'] ?? 0); ?>% ready</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="marketing-assistants-chip-row">
                            <?php foreach (array_slice((array) ($workspaceBrain['recommended_inputs'] ?? []), 0, 4) as $recommendation): ?><span><?php echo htmlspecialchars((string) $recommendation); ?></span><?php endforeach; ?>
                        </div>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <?php $contextGateStatus = (string) ($contextQualityGate['status'] ?? 'blocked'); ?>
                        <div class="premium-section-header">
                            <div><h2>AI Context Quality Gate</h2><p>Go/no-go preflight for AI-assisted strategy, copy, landing-page, and performance generation.</p></div>
                            <span class="badge <?php echo $contextGateStatus === 'ready' ? 'badge-success' : ($contextGateStatus === 'blocked' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo $contextGateScore; ?>% <?php echo htmlspecialchars($labelize($contextGateStatus)); ?></span>
                        </div>
                        <div class="marketing-assistants-mini-grid">
                            <div class="marketing-assistants-mini-card <?php echo htmlspecialchars($contextGateStatus); ?>"><strong>Record Coverage</strong><span><?php echo (int) ($contextQualityGate['record_coverage']['ready'] ?? 0); ?>/<?php echo (int) ($contextQualityGate['record_coverage']['total'] ?? 0); ?> records ready</span></div>
                            <div class="marketing-assistants-mini-card <?php echo htmlspecialchars($contextGateStatus); ?>"><strong>Critical Missing</strong><span><?php echo count((array) ($contextQualityGate['critical_missing'] ?? [])); ?> critical input(s)</span></div>
                            <div class="marketing-assistants-mini-card <?php echo htmlspecialchars($contextGateStatus); ?>"><strong>Evidence</strong><span><?php echo (int) ($contextQualityGate['evidence']['snapshot_count'] ?? 0); ?> snapshot(s)</span></div>
                        </div>
                        <div class="marketing-assistants-chip-row"><span>Manual review required</span><span>No overwrite</span><span>No external API side effects</span></div>
                        <div class="marketing-assistants-recommendation-list">
                            <?php foreach ((array) ($contextQualityGate['recommendations'] ?? []) as $recommendation): ?><span><?php echo htmlspecialchars((string) $recommendation); ?></span><?php endforeach; ?>
                        </div>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <?php $contextControlStatus = (string) ($contextControl['status'] ?? 'thin'); ?>
                        <div class="premium-section-header">
                            <div><h2>AI Context Control Panel</h2><p>Selected records, missing inputs, and safe-use rules before running an assistant.</p></div>
                            <span class="badge <?php echo $contextControlStatus === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $contextControlScore; ?>% <?php echo htmlspecialchars($labelize($contextControlStatus)); ?></span>
                        </div>
                        <div class="marketing-assistants-context-grid">
                            <?php foreach ((array) ($contextControl['selected_records'] ?? []) as $record): ?>
                                <?php $recordStatus = (string) ($record['status'] ?? 'missing'); ?>
                                <a class="marketing-assistants-context-card <?php echo htmlspecialchars($recordStatus); ?>" href="<?php echo htmlspecialchars((string) ($record['href'] ?? 'marketing_assistants.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($record['message'] ?? 'Review this context input.')); ?>" tabindex="0">
                                    <strong><?php echo htmlspecialchars((string) ($record['label'] ?? 'Context record')); ?></strong>
                                    <span><?php echo htmlspecialchars((string) ($record['title'] ?? 'No record selected')); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($contextControl['missing_inputs'])): ?>
                            <div class="marketing-assistants-chip-row warning">
                                <?php foreach (array_slice((array) ($contextControl['missing_inputs'] ?? []), 0, 8) as $missing): ?><span><?php echo htmlspecialchars((string) $missing); ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="marketing-assistants-recommendation-list">
                            <?php foreach ((array) ($contextControl['recommended_actions'] ?? []) as $action): ?><span><?php echo htmlspecialchars((string) $action); ?></span><?php endforeach; ?>
                        </div>
                        <div class="marketing-assistants-chip-row">
                            <span>Draft-side only</span>
                            <span>No automatic overwrite</span>
                            <span>No external send or publish</span>
                            <span>Human review required</span>
                        </div>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header">
                            <div><h2>AI Context Evidence</h2><p>Auditable snapshots of the context used before AI produced recommendations.</p></div>
                            <span class="badge badge-default"><?php echo (int) ($contextEvidence['counts']['total'] ?? 0); ?> snapshots</span>
                        </div>
                        <div class="marketing-assistants-evidence-layout">
                            <div class="marketing-assistants-score-card"><strong><?php echo $evidenceScore; ?>%</strong><span>Average context score</span><span>Status: <?php echo htmlspecialchars($labelize((string) ($contextEvidence['status'] ?? 'needs_evidence'))); ?></span></div>
                            <div class="marketing-assistants-run-list">
                                <?php if (empty($contextEvidence['latest_snapshots'])): ?>
                                    <div class="empty-state"><p>No AI context evidence has been captured yet. Run an assistant after adding strategy context.</p></div>
                                <?php else: foreach ((array) ($contextEvidence['latest_snapshots'] ?? []) as $snapshot): ?>
                                    <div class="marketing-assistants-run-card">
                                        <strong><?php echo htmlspecialchars($labelize((string) ($snapshot['surface'] ?? 'custom'))); ?> - <?php echo htmlspecialchars((string) ($snapshot['prompt_key'] ?? 'unknown')); ?></strong>
                                        <span><?php echo htmlspecialchars((string) ($snapshot['created_at'] ?? '')); ?><?php echo !empty($snapshot['content_title']) ? ' - ' . htmlspecialchars((string) $snapshot['content_title']) : ''; ?></span>
                                        <div class="marketing-assistants-chip-row"><span><?php echo (int) round(((float) ($snapshot['context_score'] ?? 0)) * 100); ?>% context</span><span><?php echo (int) ($snapshot['workspace_brain_score'] ?? 0); ?>% brain</span></div>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header"><div><h2>Recent Runs</h2><p>Saved assistant work stays reviewable and draft-side.</p></div></div>
                        <div class="marketing-assistants-run-list">
                            <?php if (empty($runs)): ?><div class="empty-state"><p>No marketing assistant runs yet.</p></div><?php else: foreach ($runs as $run): ?>
                                <?php $result = (array) ($run['result_json'] ?? []); ?>
                                <div class="marketing-assistants-run-card">
                                    <strong><?php echo htmlspecialchars($labelize((string) $run['assistant_type'])); ?></strong>
                                    <span><?php echo htmlspecialchars($labelize((string) $run['status'])); ?> <?php echo htmlspecialchars((string) $run['created_at']); ?></span>
                                    <p><?php echo htmlspecialchars((string) ($result['recommendation'] ?? $result['error'] ?? '')); ?></p>
                                    <?php if (!empty($run['created_content_item_id'])): ?><a href="marketing_content_view.php?id=<?php echo (int) $run['created_content_item_id']; ?>">Created content</a><?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header"><div><h2>More assistant routes</h2><p>Related tools stay reachable without crowding the board.</p></div></div>
                        <div class="marketing-assistants-link-grid">
                            <?php foreach ($advancedLinks as $link): ?>
                                <a class="marketing-assistants-tool-link" href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>" tabindex="0"><strong><?php echo htmlspecialchars((string) $link['label']); ?></strong><span><?php echo htmlspecialchars((string) $link['hint']); ?></span></a>
                            <?php endforeach; ?>
                        </div>
                        <div class="marketing-assistants-chip-row">
                            <?php foreach ($guardrails as $guardrail): ?><span><?php echo htmlspecialchars((string) $guardrail); ?></span><?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </details>

            <details class="marketing-assistants-tools" id="strategy-gap-analysis">
                <summary>Strategy and campaign planning tools</summary>
                <div class="marketing-assistants-tools-body">
                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header"><div><h2>AI Strategy Gaps</h2><p>Find missing context and weak campaign strategy. Results are saved as planning queue suggestions only.</p></div></div>
                        <?php if ($canWriteMarketing): ?>
                            <form class="marketing-assistants-inline-form" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="strategy_gap_analysis">
                                <label>Focus <input class="form-control" name="focus" placeholder="Optional: campaign, content, channels, context"></label>
                                <button class="btn-premium-primary" type="submit">Run Strategy Gap Analysis</button>
                            </form>
                        <?php endif; ?>
                        <?php if (empty($strategyGaps)): ?><div class="empty-state"><p>No AI strategy gap suggestions are open.</p></div><?php else: foreach ($strategyGaps as $gap): ?>
                            <?php $meta = (array) ($gap['metadata_json'] ?? []); ?>
                            <div class="marketing-assistants-run-card"><strong><?php echo htmlspecialchars((string) $gap['title']); ?></strong><p><?php echo htmlspecialchars((string) ($meta['reason'] ?? 'AI strategy gap suggestion.')); ?></p></div>
                        <?php endforeach; endif; ?>
                    </section>

                    <section class="marketing-assistants-detail-section">
                        <div class="premium-section-header"><div><h2>AI Campaign Planner</h2><p>Generate a reviewable campaign concept without creating briefs, content, or landing pages.</p></div></div>
                        <?php if ($canWriteMarketing): ?>
                            <form class="marketing-assistants-planner-form" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="campaign_planner">
                                <label>Campaign <select class="form-control" name="campaign_id"><option value="">Optional</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></label>
                                <label>Persona <select class="form-control" name="persona_id"><option value="">Optional</option><?php foreach ($options['personas'] as $persona): ?><option value="<?php echo (int) $persona['id']; ?>"><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></label>
                                <label>Offer <select class="form-control" name="offer_context_item_id"><option value="">Optional</option><?php foreach ($options['context_offers'] as $offer): ?><option value="<?php echo (int) $offer['id']; ?>"><?php echo htmlspecialchars((string) $offer['title']); ?></option><?php endforeach; ?></select></label>
                                <label>Planning Goal <input class="form-control" name="planning_goal" placeholder="Launch goal or theme"></label>
                                <button class="btn-premium-primary" type="submit">Generate Campaign Plan</button>
                            </form>
                        <?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
                    </section>
                </div>
            </details>
        </main>

        <aside class="marketing-assistants-side">
            <div class="content-card marketing-assistants-today">
                <div class="premium-section-header"><div><h2>Today</h2><p>Use one clear AI assist at a time.</p></div></div>
                <div class="marketing-assistants-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-assistants-today-action <?php echo htmlspecialchars((string) $action['priority']); ?>" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['tooltip']); ?>" tabindex="0"><strong><?php echo htmlspecialchars((string) $action['label']); ?></strong><span><?php echo htmlspecialchars((string) $action['detail']); ?></span></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="content-card marketing-assistants-run-panel" id="run-assistant">
                <div class="premium-section-header"><div><h2 data-selected-specialist>Strategist</h2></div></div>
                <div class="marketing-assistant-readiness" aria-label="Specialist context readiness">
                    <div><span>Workspace context</span><strong><?php echo $workspaceBrainScore; ?>%</strong></div>
                    <div><span>Quality gate</span><strong><?php echo $contextGateScore; ?>%</strong></div>
                    <div><span>Evidence</span><strong><?php echo (int) ($contextEvidence['counts']['total'] ?? 0); ?></strong></div>
                </div>
                <?php if ($canWriteMarketing): ?>
                    <form class="marketing-assistants-form" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <label>Specialist <select class="form-control" id="marketing-assistant-type" name="assistant_type"><?php foreach (Marketing::ASSISTANT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></label>
                        <label>What do you need? <textarea class="form-control" id="marketing-assistant-prompt" name="prompt" rows="5" placeholder="What strategy decision should Marketing make next?"></textarea></label>
                        <details class="marketing-assistants-form-more">
                            <summary>Link optional records</summary>
                            <div>
                                <label>Linked Content <select class="form-control" name="content_item_id"><option value="">None</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></label>
                                <label>Campaign Brief <select class="form-control" name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></label>
                                <label>Linked Landing Page <select class="form-control" name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></label>
                                <label class="marketing-assistants-checkbox"><input type="checkbox" name="create_draft" value="1"> Create draft content when supported</label>
                            </div>
                        </details>
                        <button class="btn-premium-primary" type="submit">Use specialist</button>
                    </form>
                <?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
            </div>
        </aside>
    </div>
</div></div>
<script>
(function () {
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-assistant-type]'));
    var select = document.getElementById('marketing-assistant-type');
    var prompt = document.getElementById('marketing-assistant-prompt');
    var heading = document.querySelector('[data-selected-specialist]');
    if (!cards.length || !select) return;

    cards.forEach(function (card) {
        card.addEventListener('click', function (event) {
            event.preventDefault();
            var type = card.getAttribute('data-assistant-type') || '';
            select.value = type;
            cards.forEach(function (candidate) {
                var selected = candidate === card;
                candidate.classList.toggle('is-selected', selected);
                candidate.setAttribute('aria-current', selected ? 'true' : 'false');
            });
            if (heading) heading.textContent = card.querySelector('h3').textContent.trim();
            if (prompt) {
                prompt.setAttribute('placeholder', card.getAttribute('data-prompt-hint') || 'What should this specialist help with?');
                prompt.focus();
            }
            history.replaceState(null, '', '#run-assistant');
        });
    });
}());
</script>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
