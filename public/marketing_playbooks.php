<?php
/**
 * Marketing campaign playbooks list.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        $playbookId = (int) ($_POST['id'] ?? 0);
        if ($action === 'create_template') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create campaign playbook templates.');
            }
            $createdPlaybookId = $marketing->createCampaignPlaybookFromTemplate((string) ($_POST['template_key'] ?? ''), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_playbook_view.php?id=' . $createdPlaybookId . '&success=template');
            exit;
        }
        if ($action === 'create_brief') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create campaign briefs from playbooks.');
            }
            $briefId = $marketing->createCampaignBriefFromPlaybook($playbookId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_brief_view.php?id=' . $briefId . '&success=playbook');
            exit;
        }
        if ($action === 'apply_playbook') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to apply campaign playbooks.');
            }
            $run = $marketing->applyCampaignPlaybook($playbookId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_playbook_view.php?id=' . $playbookId . '&success=applied&run=' . (int) ($run['id'] ?? 0));
            exit;
        }
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive campaign playbooks.');
            }
            $marketing->deleteCampaignPlaybook($playbookId);
            header('Location: ' . getBasePath() . '/marketing_playbooks.php?success=archived');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filters = ['exclude_archived' => true];
$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') {
    $filters['status'] = $status;
    unset($filters['exclude_archived']);
}
$playbooks = $marketing->listCampaignPlaybooks($filters, 100, 0);
$templateDefinitions = $marketing->campaignPlaybookTemplateDefinitions();
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'active', 'ready', 'completed' => 'badge-success',
        'blocked', 'needs_foundation' => 'badge-danger',
        'draft', 'planned', 'warning', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$cleanStatus = static fn(string $value): string => preg_replace('/[^a-z0-9_-]/i', '', $value) ?: 'setup_needed';
$playbookTotal = count($playbooks);
$templateTotal = count($templateDefinitions);
$activePlaybooks = count(array_filter($playbooks, static fn(array $playbook): bool => (string) ($playbook['status'] ?? '') === 'active'));
$readyPlaybooks = count(array_filter($playbooks, static fn(array $playbook): bool => (int) ($playbook['readiness_score'] ?? 0) >= 80));
$draftPlaybooks = count(array_filter($playbooks, static fn(array $playbook): bool => (string) ($playbook['status'] ?? '') === 'draft'));
$briefTotal = array_sum(array_map(static fn(array $playbook): int => (int) ($playbook['brief_count'] ?? 0), $playbooks));
$stageTotal = array_sum(array_map(static fn(array $playbook): int => (int) ($playbook['stage_count'] ?? 0), $playbooks));
$averageReadiness = $playbookTotal > 0
    ? (int) round(array_sum(array_map(static fn(array $playbook): int => (int) ($playbook['readiness_score'] ?? 0), $playbooks)) / $playbookTotal)
    : 0;
$firstPlaybook = (array) ($playbooks[0] ?? []);
$firstTemplateKey = (string) array_key_first($templateDefinitions);
$summaryTiles = [
    ['icon' => 'fa-layer-group', 'label' => 'Playbooks', 'value' => (string) $playbookTotal, 'tooltip' => 'Reusable campaign playbooks in the current view.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $averageReadiness . '%', 'tooltip' => 'Average readiness across visible campaign playbooks.'],
    ['icon' => 'fa-diagram-project', 'label' => 'Stages', 'value' => (string) $stageTotal, 'tooltip' => 'Total planned strategy, content, distribution, and handoff stages.'],
    ['icon' => 'fa-clipboard-list', 'label' => 'Briefs', 'value' => (string) $briefTotal, 'tooltip' => 'Campaign briefs already created from playbooks.'],
];
$stageCards = [
    [
        'label' => 'Pick Pattern',
        'icon' => 'fa-shapes',
        'status' => $templateTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Start from a repeatable campaign shape.',
        'tooltip' => 'Expert view: starter playbook templates and template keys.',
        'href' => $canWriteMarketing ? 'marketing_playbook_edit.php' : 'marketing_playbooks.php',
        'action' => $canWriteMarketing ? 'Create playbook' : 'Review templates',
    ],
    [
        'label' => 'Set Foundation',
        'icon' => 'fa-bullseye',
        'status' => $playbookTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Name the goal, audience, and offer.',
        'tooltip' => 'Expert view: campaign goal, target audience, persona, offer, landing page, and ownership fields.',
        'href' => $canWriteMarketing ? 'marketing_playbook_edit.php' : 'marketing_playbooks.php',
        'action' => $canWriteMarketing ? 'Create playbook' : 'Review playbook',
    ],
    [
        'label' => 'Map Stages',
        'icon' => 'fa-list-check',
        'status' => $stageTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Turn the campaign into clear steps.',
        'tooltip' => 'Expert view: playbook stages, required record types, and operator instructions.',
        'href' => $firstPlaybook !== [] ? 'marketing_playbook_view.php?id=' . (int) ($firstPlaybook['id'] ?? 0) : ($canWriteMarketing ? 'marketing_playbook_edit.php' : 'marketing_playbooks.php'),
        'action' => 'Review stages',
    ],
    [
        'label' => 'Create Brief',
        'icon' => 'fa-file-lines',
        'status' => $briefTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Use the playbook to shape a campaign brief.',
        'tooltip' => 'Expert view: playbook-to-brief generation and campaign brief links.',
        'href' => $firstPlaybook !== [] ? 'marketing_playbook_view.php?id=' . (int) ($firstPlaybook['id'] ?? 0) : 'marketing_briefs.php',
        'action' => 'Open brief path',
    ],
    [
        'label' => 'Use Kit',
        'icon' => 'fa-box-open',
        'status' => $readyPlaybooks > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Apply the kit when the plan is ready.',
        'tooltip' => 'Expert view: readiness score, missing requirements, application runs, and generated kit records.',
        'href' => $firstPlaybook !== [] ? 'marketing_playbook_view.php?id=' . (int) ($firstPlaybook['id'] ?? 0) : 'marketing_campaign_workspace.php',
        'action' => 'Check kit',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstPlaybook !== [] ? [
        'label' => 'Review top playbook',
        'href' => 'marketing_playbook_view.php?id=' . (int) ($firstPlaybook['id'] ?? 0),
        'reason' => 'Open the strongest playbook and check what is missing before creating campaign work.',
    ] : null,
    $canWriteMarketing && $firstTemplateKey !== '' ? [
        'label' => 'Use starter pattern',
        'href' => 'marketing_playbook_edit.php',
        'reason' => 'Create a playbook from a proven campaign pattern, or open More playbook tools for starter templates.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Create playbook',
        'href' => 'marketing_playbook_edit.php',
        'reason' => 'Make one reusable campaign operating plan.',
    ] : null,
    [
        'label' => 'Open campaign briefs',
        'href' => 'marketing_briefs.php',
        'reason' => 'Use playbooks to produce campaign briefs when the foundation is ready.',
    ],
    [
        'label' => 'Open workspace',
        'href' => 'marketing_campaign_workspace.php',
        'reason' => 'Connect playbook decisions to the broader campaign workspace.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the guided command center.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Turn playbooks into campaign briefs.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect playbook work to campaign readiness.'],
    ['label' => 'Audience Builder', 'href' => 'marketing_segments.php', 'hint' => 'Prepare the audience foundation for playbooks.'],
    ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'hint' => 'Connect destination plans to campaign kits.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check launch evidence after the campaign plan is built.'],
];
$pageTitle = 'Campaign Playbooks - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-playbooks-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Playbook Library</h1>
                <p>Campaign Playbooks: choose a repeatable path before campaign planning.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_playbook_edit.php"><i class="fas fa-plus"></i> New Playbook</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Campaign playbook archived.</div><?php endif; ?>

        <section class="marketing-playbooks-shell">
            <div class="marketing-founder-summary marketing-playbooks-summary" aria-label="Playbook library summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-playbook-stage-grid" aria-label="Playbook path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-playbook-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-playbook-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-playbook-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-playbooks-layout">
                <main class="marketing-playbooks-main">
                    <section class="content-card marketing-playbook-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Playbook Board</h2>
                                <p>Use one pattern to guide the next campaign.</p>
                            </div>
                            <span class="badge <?php echo $playbookTotal > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $playbookTotal; ?> playbooks</span>
                        </div>
                        <?php if ($playbooks === []): ?>
                            <div class="empty-state">
                                <p>No playbooks match this view.</p>
                                <?php if ($canWriteMarketing): ?><a href="marketing_playbook_edit.php">Create playbook</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-playbook-card-grid">
                                <?php foreach ($playbooks as $playbook): ?>
                                    <?php
                                        $playbookStatus = $cleanStatus((string) ($playbook['status'] ?? 'draft'));
                                        $readiness = (int) ($playbook['readiness_score'] ?? 0);
                                        $missing = (array) ($playbook['missing_requirements_json'] ?? []);
                                    ?>
                                    <article class="marketing-playbook-card <?php echo htmlspecialchars($playbookStatus); ?>" tabindex="0" data-tooltip="Expert view: readiness, missing requirements, stages, briefs, and linked campaign foundation.">
                                        <div class="marketing-playbook-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-layer-group" aria-hidden="true"></i>
                                                <span><?php echo $readiness; ?>%</span>
                                            </div>
                                            <span class="badge <?php echo htmlspecialchars($badgeClass($playbookStatus)); ?>"><?php echo htmlspecialchars($labelize($playbookStatus)); ?></span>
                                        </div>
                                        <h3><?php echo htmlspecialchars((string) ($playbook['name'] ?? 'Campaign playbook')); ?></h3>
                                        <div class="marketing-playbook-card-meta">
                                            <span><?php echo (int) ($playbook['stage_count'] ?? 0); ?> stages</span>
                                            <span><?php echo (int) ($playbook['brief_count'] ?? 0); ?> briefs</span>
                                            <span><?php echo $readiness; ?> readiness</span>
                                            <?php if (!empty($playbook['audience_segment_name'])): ?><span><?php echo htmlspecialchars((string) $playbook['audience_segment_name']); ?></span><?php endif; ?>
                                        </div>
                                        <p><?php echo htmlspecialchars((string) ($playbook['campaign_goal'] ?: 'Shape a repeatable campaign path.')); ?></p>
                                        <?php if ($missing !== []): ?><span class="marketing-playbook-gap-pill"><?php echo count($missing); ?> setup gaps</span><?php endif; ?>
                                        <a class="btn-premium-secondary marketing-playbook-card-action" href="marketing_playbook_view.php?id=<?php echo (int) $playbook['id']; ?>">Review playbook</a>
                                        <details class="marketing-playbook-card-tools">
                                            <summary>More controls</summary>
                                            <div class="marketing-playbook-card-tool-body">
                                                <?php if ($canWriteMarketing): ?>
                                                    <a class="btn-premium-secondary" href="marketing_playbook_edit.php?id=<?php echo (int) $playbook['id']; ?>">Edit playbook</a>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="create_brief">
                                                        <input type="hidden" name="id" value="<?php echo (int) $playbook['id']; ?>">
                                                        <button class="btn-premium-primary" type="submit">Create Brief</button>
                                                    </form>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="apply_playbook">
                                                        <input type="hidden" name="id" value="<?php echo (int) $playbook['id']; ?>">
                                                        <button class="btn-premium-secondary" type="submit">Apply Kit</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" onsubmit="return confirm('Archive this campaign playbook?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="id" value="<?php echo (int) $playbook['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Archive</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="content-card marketing-playbooks-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Choose one repeatable path.</p>
                        </div>
                    </div>
                    <div class="marketing-playbook-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketing-playbook-score-card">
                        <div class="setup-score-ring" data-score="<?php echo min(100, max(0, $averageReadiness)); ?>">
                            <span><?php echo $averageReadiness; ?>%</span>
                        </div>
                        <strong>Playbook readiness</strong>
                        <small><?php echo $activePlaybooks; ?> active, <?php echo $draftPlaybooks; ?> draft</small>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-playbook-tools">
                <summary>
                    <span>More playbook tools</span>
                    <small>Starter patterns, filters, and expert routes.</small>
                </summary>
                <div class="marketing-playbook-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>">
                                <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($canWriteMarketing): ?>
                        <section class="content-card marketing-playbook-template-card" id="playbook-templates">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Starter Campaign Templates</h2>
                                    <p>Pick a proven pattern, then tune it.</p>
                                </div>
                            </div>
                            <div class="marketing-playbook-template-grid">
                                <?php foreach ($templateDefinitions as $templateKey => $template): ?>
                                    <form method="POST" class="marketing-playbook-template-option" data-tooltip="Creates a reusable playbook from this starter pattern.">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="create_template">
                                        <input type="hidden" name="template_key" value="<?php echo htmlspecialchars((string) $templateKey); ?>">
                                        <strong><?php echo htmlspecialchars((string) $template['name']); ?></strong>
                                        <span><?php echo htmlspecialchars((string) $template['campaign_goal']); ?></span>
                                        <button class="btn-premium-secondary" type="submit">Use Template</button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="content-card marketing-playbook-filter-card">
                        <div class="premium-section-header"><h2>View filters</h2></div>
                        <form method="GET" class="marketing-playbook-filter-form">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">Open</option>
                                    <?php foreach (Marketing::CAMPAIGN_PLAYBOOK_STATUSES as $playbookStatus): ?><option value="<?php echo htmlspecialchars($playbookStatus); ?>" <?php echo $selected($status, $playbookStatus); ?>><?php echo htmlspecialchars($labelize($playbookStatus)); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_playbooks.php">Reset</a>
                        </form>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
