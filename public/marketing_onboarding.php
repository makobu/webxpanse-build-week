<?php
/**
 * Marketing setup and starter data.
 */

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

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_starter_pack') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create marketing starter data.');
            }
            $marketing->createMarketingStarterPack((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_onboarding.php?success=starter');
            exit;
        }
        if ($action === 'archive_starter_pack') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive marketing starter data.');
            }
            $marketing->archiveMarketingStarterPack((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_onboarding.php?success=archived');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['success'] ?? '') === 'starter') {
    $notice = 'Marketing starter pack created.';
} elseif (($_GET['success'] ?? '') === 'archived') {
    $notice = 'Marketing starter pack archived.';
}

$status = $marketing->getMarketingOnboardingStatus();
$guidedRecommendations = $marketing->getMarketingGuidedRecommendations((int) ($user['id'] ?? 0), 6);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'setup', 6);
$steps = (array) ($status['steps'] ?? []);
$starterPack = (array) ($status['starter_pack'] ?? []);
$createdCounts = (array) ($starterPack['created_counts'] ?? []);
$quickActions = [
    ['label' => 'Brand', 'url' => 'marketing_brand.php', 'icon' => 'fa-palette'],
    ['label' => 'Personas', 'url' => 'marketing_personas.php', 'icon' => 'fa-user-tag'],
    ['label' => 'Context', 'url' => 'marketing_context.php', 'icon' => 'fa-layer-group'],
    ['label' => 'Briefs', 'url' => 'marketing_briefs.php', 'icon' => 'fa-clipboard-list'],
    ['label' => 'Content', 'url' => 'marketing_content.php', 'icon' => 'fa-pen-nib'],
    ['label' => 'Calendar', 'url' => 'marketing_calendar.php', 'icon' => 'fa-calendar-days'],
    ['label' => 'Landing Pages', 'url' => 'marketing_landing_pages.php', 'icon' => 'fa-window-maximize'],
    ['label' => 'Distribution', 'url' => 'marketing_distribution.php', 'icon' => 'fa-share-nodes'],
    ['label' => 'Analytics', 'url' => 'marketing_weekly_report.php', 'icon' => 'fa-chart-simple'],
];
$setupScore = (int) ($status['score'] ?? 0);
$completeCount = (int) ($status['complete_count'] ?? 0);
$totalCount = max(1, (int) ($status['total_count'] ?? count($steps)));
$missingSteps = array_filter($steps, static fn(array $step): bool => empty($step['complete']));
$firstMissingStep = $missingSteps !== [] ? reset($missingSteps) : null;
$primarySetupHref = is_array($firstMissingStep) ? (string) ($firstMissingStep['url'] ?? 'marketing_context.php') : 'marketing_context.php';
$primarySetupLabel = is_array($firstMissingStep)
    ? 'Open ' . (string) ($firstMissingStep['label'] ?? 'setup')
    : 'Review context';
$starterState = !empty($starterPack['created'])
    ? (!empty($starterPack['archived']) ? 'Archived' : 'Ready')
    : 'Optional';
$stepIcons = ['fa-bullseye', 'fa-palette', 'fa-user-tag', 'fa-gift', 'fa-shield-halved', 'fa-route', 'fa-pen-nib', 'fa-share-nodes'];
$todayActions = [];
if (is_array($firstMissingStep)) {
    $todayActions[] = [
        'label' => 'Complete ' . (string) ($firstMissingStep['label'] ?? 'setup'),
        'href' => (string) ($firstMissingStep['url'] ?? 'marketing_context.php'),
        'priority' => 'high',
        'tooltip' => (string) ($firstMissingStep['message'] ?? 'This is the next missing setup item.'),
    ];
} elseif (empty($starterPack['created'])) {
    $todayActions[] = [
        'label' => 'Create a starter example',
        'href' => '#starter-pack',
        'priority' => 'normal',
        'tooltip' => 'Optional sample records help you learn the marketing workflow without starting from a blank page.',
    ];
}
foreach ((array) ($workflowNextSteps['actions'] ?? []) as $action) {
    if (count($todayActions) >= 5) {
        break;
    }
    $todayActions[] = [
        'label' => (string) ($action['label'] ?? 'Open next marketing step'),
        'href' => (string) ($action['href'] ?? 'marketing.php'),
        'priority' => (string) ($action['priority'] ?? 'normal'),
        'tooltip' => (string) ($action['reason'] ?? 'Open this workflow step.'),
    ];
}
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$pageTitle = 'Marketing Setup - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-onboarding-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Know Your Customer</h1>
                <p>Give marketing enough context to guide every campaign.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Command Center</a>
                <a class="btn-premium-primary" href="<?php echo htmlspecialchars($primarySetupHref); ?>"><?php echo htmlspecialchars($primarySetupLabel); ?></a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Setup action was not applied.</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <section class="marketing-setup-shell" aria-label="Marketing setup path">
            <div class="marketing-founder-summary marketing-setup-summary">
                <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($primarySetupHref); ?>" data-tooltip="Your next setup action. Keep this simple: complete the first missing business fact.">
                    <i class="fas fa-arrow-right"></i>
                    <span>Next</span>
                    <strong><?php echo htmlspecialchars($primarySetupLabel); ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="<?php echo htmlspecialchars($completeCount . ' of ' . $totalCount . ' setup areas are ready.'); ?>">
                    <i class="fas fa-gauge-high"></i>
                    <span>Readiness</span>
                    <strong><?php echo $setupScore; ?>%</strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Starter data is optional. It gives founders a working example without replacing real records.">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <span>Starter</span>
                    <strong><?php echo htmlspecialchars($starterState); ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="This page feeds Clarity the plain-language customer, offer, and proof context it needs.">
                    <i class="fas fa-circle-info"></i>
                    <span>Clarity</span>
                    <strong>Context-ready path</strong>
                </div>
            </div>

            <div class="marketing-founder-layout marketing-setup-layout">
                <div class="marketing-setup-main">
                    <div class="marketing-setup-step-grid">
                        <?php $stepIndex = 0; ?>
                        <?php foreach ($steps as $key => $step): ?>
                            <?php
                                $isReady = !empty($step['complete']);
                                $statusLabel = $isReady ? 'Ready' : 'Setup needed';
                                $statusClass = $isReady ? 'ready' : 'setup-needed';
                                $icon = $stepIcons[$stepIndex % count($stepIcons)];
                                $stepIndex++;
                            ?>
                            <article class="marketing-setup-step-card <?php echo $statusClass; ?>">
                                <div class="marketing-stage-visual">
                                    <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                                    <span><?php echo $stepIndex; ?></span>
                                </div>
                                <div class="marketing-stage-title-row">
                                    <h2><?php echo htmlspecialchars((string) ($step['label'] ?? $labelize((string) $key))); ?></h2>
                                    <span class="marketing-stage-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                </div>
                                <div class="marketing-setup-step-footer">
                                    <button class="marketing-tooltip-trigger" type="button" data-tooltip="<?php echo htmlspecialchars((string) ($step['message'] ?? 'Open this setup item.')); ?>" aria-label="Why this setup item matters"><i class="fas fa-circle-info"></i><span>Why this matters</span></button>
                                    <a class="btn-premium-secondary marketing-stage-action" href="<?php echo htmlspecialchars((string) ($step['url'] ?? '#')); ?>">Open</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="marketing-setup-readiness-card">
                        <div class="setup-score-ring" data-score="<?php echo $setupScore; ?>"><span><?php echo $setupScore; ?>%</span></div>
                        <div>
                            <h2>Setup readiness</h2>
                            <p><?php echo $completeCount; ?> of <?php echo $totalCount; ?> customer, offer, and proof areas are ready.</p>
                        </div>
                    </div>
                </div>

                <aside class="content-card marketing-today-panel marketing-setup-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Five or fewer setup actions.</p>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php if ($todayActions === []): ?>
                            <div class="empty-state"><p>No setup actions are open. Review context when the business changes.</p></div>
                        <?php else: ?>
                            <?php foreach (array_slice($todayActions, 0, 5) as $action): ?>
                                <a class="marketing-today-action <?php echo htmlspecialchars((string) ($action['priority'] ?? 'normal')); ?>" href="<?php echo htmlspecialchars((string) ($action['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($action['tooltip'] ?? 'Open this action.')); ?>">
                                    <strong><?php echo htmlspecialchars((string) ($action['label'] ?? 'Open next action')); ?></strong>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

            <div class="content-card marketing-setup-starter-card" id="starter-pack">
                <div class="premium-section-header">
                    <div>
                        <h2>Starter Pack</h2>
                        <p>Optional guided example for learning the workflow.</p>
                    </div>
                </div>
                <?php if (!empty($starterPack['created'])): ?>
                    <p class="marketing-setup-muted">Starter pack created<?php echo !empty($starterPack['created_at']) ? ' on ' . htmlspecialchars((string) $starterPack['created_at']) : ''; ?>.</p>
                    <?php if (!empty($createdCounts)): ?>
                        <div class="starter-counts">
                            <?php foreach ($createdCounts as $key => $count): ?>
                                <div class="starter-count"><span><?php echo htmlspecialchars($labelize((string) $key)); ?></span><strong><?php echo (int) $count; ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="setup-actions marketing-setup-actions-spaced">
                        <a class="btn-premium-primary" href="marketing_content.php">Review Starter Content</a>
                        <?php if ($canManageMarketing && empty($starterPack['archived'])): ?>
                            <form method="POST" class="marketing-setup-inline-form" onsubmit="return confirm('Archive only demo starter records for this workspace? Real Marketing records are left untouched.');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="archive_starter_pack">
                                <button class="btn-premium-secondary" type="submit">Archive Starter Pack</button>
                                <span class="badge badge-default">Manager only</span>
                            </form>
                        <?php elseif (!empty($starterPack['archived'])): ?>
                            <span class="badge badge-default">Archived</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="marketing-setup-muted">Create sample records when you want a guided example.</p>
                    <div class="setup-actions marketing-setup-actions-spaced">
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="create_starter_pack">
                                <button class="btn-premium-primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Create Starter Pack</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-default">Read-only access</span>
                        <?php endif; ?>
                        <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_content_edit.php">Create Manually</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <details class="marketing-advanced-tools marketing-setup-tools">
                <summary>More setup tools <i class="fas fa-chevron-down"></i></summary>
                <div class="marketing-setup-tools-body">
                    <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                    <div class="content-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Clarity guidance queue</h2>
                                <p>Use these only when you want more help from the expert layer.</p>
                            </div>
                            <span class="badge badge-default"><?php echo (int) ($guidedRecommendations['counts']['total'] ?? 0); ?> open</span>
                        </div>
                        <?php if (empty($guidedRecommendations['recommendations'])): ?>
                            <div class="empty-state"><p>No setup guidance is open. Continue to performance review or launch planning.</p></div>
                        <?php else: ?>
                            <div class="setup-rec-grid">
                                <?php foreach ((array) ($guidedRecommendations['recommendations'] ?? []) as $recommendation): ?>
                                    <a class="setup-rec-card <?php echo htmlspecialchars((string) ($recommendation['priority'] ?? 'normal')); ?>" href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($recommendation['reason'] ?? 'Open this guidance item.')); ?>">
                                        <div class="setup-rec-meta">
                                            <span class="setup-rec-pill"><?php echo htmlspecialchars((string) ($recommendation['priority'] ?? 'normal')); ?></span>
                                            <span class="setup-rec-pill"><?php echo htmlspecialchars($labelize((string) ($recommendation['category'] ?? 'marketing'))); ?></span>
                                        </div>
                                        <strong><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Open guidance')); ?></strong>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="content-card">
                        <div class="premium-section-header"><h2>Expert routes</h2></div>
                        <div class="setup-action-grid">
                            <?php foreach ($quickActions as $action): ?>
                                <a class="setup-action-card" href="<?php echo htmlspecialchars($action['url']); ?>"><i class="fas <?php echo htmlspecialchars($action['icon']); ?>"></i><?php echo htmlspecialchars($action['label']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
