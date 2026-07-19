<?php
/**
 * Marketing campaign playbook detail.
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
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$playbook = $marketing->getCampaignPlaybook($id);
if (!$playbook) {
    http_response_code(404);
    echo 'Marketing campaign playbook not found.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_brief') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create campaign briefs from playbooks.');
            }
            $briefId = $marketing->createCampaignBriefFromPlaybook($id, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_brief_view.php?id=' . $briefId . '&success=playbook');
            exit;
        }
        if ($action === 'apply_playbook') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to apply campaign playbooks.');
            }
            $run = $marketing->applyCampaignPlaybook($id, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_playbook_view.php?id=' . $id . '&success=applied&run=' . (int) ($run['id'] ?? 0));
            exit;
        }
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive campaign playbooks.');
            }
            $marketing->deleteCampaignPlaybook($id);
            header('Location: ' . getBasePath() . '/marketing_playbooks.php?success=archived');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$playbook = $marketing->getCampaignPlaybook($id) ?? $playbook;
$briefs = $marketing->listCampaignBriefs(['campaign_playbook_id' => $id], 20, 0);
$applicationRuns = $marketing->listCampaignPlaybookApplicationRuns(['playbook_id' => $id], 10, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$missing = (array) ($playbook['missing_requirements_json'] ?? []);
$readinessScore = max(0, min(100, (int) ($playbook['readiness_score'] ?? 0)));
$stageCount = (int) ($playbook['stage_count'] ?? count((array) ($playbook['stages'] ?? [])));
$briefCount = count($briefs);
$runCount = count($applicationRuns);
$missingCount = count($missing);
$status = (string) ($playbook['status'] ?? 'draft');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'active', 'ready', 'completed' => 'badge-success',
        'blocked', 'needs_foundation' => 'badge-danger',
        'draft', 'planned', 'warning', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$toneForScore = static fn(int $score): string => $score >= 80 ? 'ready' : ($score >= 45 ? 'warning' : 'blocked');
$toneForCount = static fn(int $count): string => $count > 0 ? 'ready' : 'setup_needed';
$strategyFields = [
    'Goal' => (string) ($playbook['campaign_goal'] ?? ''),
    'Audience Segment' => (string) ($playbook['audience_segment_name'] ?? ''),
    'Persona' => (string) ($playbook['persona_name'] ?? ''),
    'Offer' => (string) ($playbook['offer_title'] ?? ''),
    'Landing Page' => (string) ($playbook['landing_page_title'] ?? ''),
];
$strategyLinkedCount = count(array_filter($strategyFields, static fn(string $value): bool => trim($value) !== ''));
$summaryTiles = [
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $readinessScore . '%', 'tooltip' => 'How much evidence is in place before this playbook becomes campaign work.'],
    ['icon' => 'fa-diagram-project', 'label' => 'Stages', 'value' => (string) $stageCount, 'tooltip' => 'Reusable campaign steps configured for this playbook.'],
    ['icon' => 'fa-file-lines', 'label' => 'Briefs', 'value' => (string) $briefCount, 'tooltip' => 'Campaign briefs already created from this playbook.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Gaps', 'value' => (string) $missingCount, 'tooltip' => 'Inputs still missing before the playbook is fully ready.'],
    ['icon' => 'fa-box-open', 'label' => 'Runs', 'value' => (string) $runCount, 'tooltip' => 'Times this playbook has been applied as a campaign kit.'],
];
$reviewCards = [
    [
        'label' => 'Review Readiness',
        'icon' => 'fa-gauge-high',
        'status' => $toneForScore($readinessScore),
        'sentence' => 'Check whether the pattern is usable.',
        'tooltip' => 'Expert view: readiness score, missing requirements, and linked campaign evidence.',
        'href' => '#playbook-evidence',
        'action' => 'Check evidence',
    ],
    [
        'label' => 'Check Stages',
        'icon' => 'fa-list-check',
        'status' => $toneForCount($stageCount),
        'sentence' => 'Review the reusable campaign steps.',
        'tooltip' => 'Expert view: stage order, stage type, required record type, and operating instructions.',
        'href' => '#playbook-stages',
        'action' => 'Review stages',
    ],
    [
        'label' => 'Create Brief',
        'icon' => 'fa-file-circle-plus',
        'status' => $briefCount > 0 ? 'ready' : ($canWriteMarketing ? 'setup_needed' : 'locked'),
        'sentence' => 'Turn the pattern into one campaign brief.',
        'tooltip' => 'Expert view: playbook-to-brief generation and campaign brief records.',
        'href' => $canWriteMarketing ? '#playbook-actions' : '#playbook-briefs',
        'action' => $canWriteMarketing ? 'Create brief' : 'View briefs',
    ],
    [
        'label' => 'Apply Kit',
        'icon' => 'fa-wand-magic-sparkles',
        'status' => $runCount > 0 ? 'ready' : ($canWriteMarketing ? 'setup_needed' : 'locked'),
        'sentence' => 'Prepare the connected campaign records.',
        'tooltip' => 'Expert view: generated brief, landing page, content, distribution, calendar, and queue records.',
        'href' => $canWriteMarketing ? '#playbook-actions' : '#playbook-runs',
        'action' => $canWriteMarketing ? 'Apply kit' : 'View runs',
    ],
    [
        'label' => 'Resolve Inputs',
        'icon' => 'fa-link',
        'status' => $missingCount === 0 ? 'ready' : 'warning',
        'sentence' => 'Connect the missing foundation.',
        'tooltip' => $missingCount === 0 ? 'No missing requirements are currently reported.' : 'Missing: ' . implode(', ', array_map($labelize, $missing)),
        'href' => '#playbook-evidence',
        'action' => 'See gaps',
    ],
    [
        'label' => 'Review Strategy',
        'icon' => 'fa-bullseye',
        'status' => $strategyLinkedCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Check the goal, audience, and offer.',
        'tooltip' => 'Expert view: campaign goal, audience segment, persona, offer, landing page, metrics, and channel plan.',
        'href' => '#playbook-strategy',
        'action' => 'Review strategy',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $missingCount > 0 ? ['label' => 'Resolve missing inputs', 'href' => '#playbook-evidence', 'reason' => 'Open the evidence drawer and see the exact gaps.'] : null,
    $stageCount === 0 && $canWriteMarketing ? ['label' => 'Add playbook stages', 'href' => 'marketing_playbook_edit.php?id=' . (int) $id, 'reason' => 'A playbook needs reusable steps before it can guide a campaign.'] : null,
    $canWriteMarketing ? ['label' => 'Create campaign brief', 'href' => '#playbook-actions', 'reason' => 'Use this playbook to draft the campaign brief.'] : null,
    $canWriteMarketing ? ['label' => 'Apply campaign kit', 'href' => '#playbook-actions', 'reason' => 'Create connected campaign records from this pattern.'] : null,
    $runCount > 0 ? ['label' => 'Review application runs', 'href' => '#playbook-runs', 'reason' => 'See what the playbook already generated.'] : null,
    $canWriteMarketing ? ['label' => 'Edit playbook', 'href' => 'marketing_playbook_edit.php?id=' . (int) $id, 'reason' => 'Update the foundation, stages, metrics, or channel plan.'] : null,
    ['label' => 'Review operating stages', 'href' => '#playbook-stages', 'reason' => 'Understand the campaign path before creating work.'],
])), 0, 5);
$pageTitle = (string) $playbook['name'] . ' - Campaign Playbook - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-playbook-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $playbook['name']); ?></h1>
                <p>Campaign Playbook: review the campaign pattern before turning it into work.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_playbooks.php"><i class="fas fa-arrow-left"></i> Library</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_playbook_edit.php?id=<?php echo (int) $id; ?>"><i class="fas fa-pen"></i> Edit</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'saved'): ?><div class="alert alert-success">Campaign playbook saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'template'): ?><div class="alert alert-success">Starter playbook template created for this workspace.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'applied'): ?><div class="alert alert-success">Campaign kit applied. New brief, landing page, content, distribution, calendar, and queue records were prepared where enabled.</div><?php endif; ?>

        <section class="marketing-playbook-view-shell">
            <div class="marketing-founder-summary marketing-playbook-view-summary" aria-label="Playbook review summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-playbook-view-layout">
                <main class="marketing-playbook-view-main">
                    <section class="content-card marketing-playbook-view-board" aria-label="Playbook review board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Playbook Review Board</h2>
                                <p>Follow the next useful step.</p>
                            </div>
                            <span class="badge <?php echo htmlspecialchars($badgeClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                        </div>
                        <div class="marketing-playbook-view-card-grid">
                            <?php foreach ($reviewCards as $card): ?>
                                <?php $cardStatus = (string) $card['status']; ?>
                                <article class="marketing-playbook-view-card <?php echo htmlspecialchars($cardStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                                    <div class="marketing-playbook-view-visual">
                                        <i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>" aria-hidden="true"></i>
                                    </div>
                                    <div class="marketing-playbook-view-card-body">
                                        <div class="marketing-playbook-view-card-title">
                                            <strong><?php echo htmlspecialchars((string) $card['label']); ?></strong>
                                            <span class="marketing-playbook-view-status <?php echo htmlspecialchars($cardStatus); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                                        </div>
                                        <span><?php echo htmlspecialchars((string) $card['sentence']); ?></span>
                                    </div>
                                    <a class="btn-premium-secondary marketing-playbook-view-card-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </main>

                <aside class="content-card marketing-playbook-view-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Keep the playbook moving.</p>
                        </div>
                    </div>
                    <div class="marketing-playbook-view-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-playbook-view-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>

            <details class="content-card marketing-playbook-view-tools" id="playbook-evidence">
                <summary>More playbook evidence</summary>
                <div class="marketing-playbook-view-tools-body">
                    <?php if (!empty($missing)): ?><div class="alert alert-warning">Missing playbook requirements: <?php echo htmlspecialchars(implode(', ', array_map($labelize, $missing))); ?></div><?php endif; ?>
                    <div class="playbook-grid">
                        <div class="stat-card"><div class="stat-label">Readiness</div><div class="stat-value"><?php echo $readinessScore; ?>%</div><progress class="readiness-progress" value="<?php echo $readinessScore; ?>" max="100"><?php echo $readinessScore; ?>%</progress></div>
                        <div class="stat-card"><div class="stat-label">Stages</div><div class="stat-value"><?php echo $stageCount; ?></div></div>
                        <div class="stat-card"><div class="stat-label">Briefs Created</div><div class="stat-value"><?php echo $briefCount; ?></div></div>
                        <div class="stat-card"><div class="stat-label">Missing Inputs</div><div class="stat-value"><?php echo $missingCount; ?></div></div>
                    </div>
                    <section class="content-card" id="playbook-stages">
                        <div class="premium-section-header"><div><h2>Operating Stages</h2><p>Manual-first steps are kept here for review.</p></div></div>
                        <?php if (empty($playbook['stages'])): ?>
                            <div class="empty-state"><p>No stages configured yet.</p><?php if ($canWriteMarketing): ?><a href="marketing_playbook_edit.php?id=<?php echo (int) $id; ?>">Add stages</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="stage-list">
                                <?php foreach ((array) $playbook['stages'] as $stage): ?>
                                    <div class="stage-row">
                                        <strong><?php echo (int) ($stage['stage_order'] ?? 0); ?>. <?php echo htmlspecialchars((string) $stage['title']); ?></strong>
                                        <div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) ($stage['stage_type'] ?? 'other'))); ?></span><span>Requires <?php echo htmlspecialchars($labelize((string) ($stage['required_record_type'] ?? 'none'))); ?></span></div>
                                        <?php if (!empty($stage['instructions'])): ?><p class="playbook-copy-block"><?php echo htmlspecialchars((string) $stage['instructions']); ?></p><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="content-card" id="playbook-briefs">
                        <div class="premium-section-header"><h2>Briefs From This Playbook</h2></div>
                        <?php if (empty($briefs)): ?><div class="empty-state"><p>No campaign briefs have been created from this playbook yet.</p></div><?php else: foreach ($briefs as $brief): ?>
                            <div class="mini-row"><a href="marketing_brief_view.php?id=<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></a><span><?php echo htmlspecialchars($labelize((string) $brief['status'])); ?></span></div>
                        <?php endforeach; endif; ?>
                    </section>
                    <section class="content-card" id="playbook-runs">
                        <div class="premium-section-header"><h2>Application Runs</h2></div>
                        <?php if (empty($applicationRuns)): ?>
                            <div class="empty-state"><p>This playbook has not been applied as a full campaign kit yet.</p></div>
                        <?php else: foreach ($applicationRuns as $run): $summary = (array) ($run['summary_json'] ?? []); ?>
                            <div class="stage-row">
                                <strong><?php echo htmlspecialchars((string) ($run['run_name'] ?? 'Campaign kit')); ?></strong>
                                <div class="marketing-meta">
                                    <span><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></span>
                                    <span><?php echo (int) ($summary['content_items_created'] ?? 0); ?> content</span>
                                    <span><?php echo (int) ($summary['distribution_posts_created'] ?? 0); ?> distribution</span>
                                    <span><?php echo (int) ($summary['calendar_milestones_created'] ?? 0); ?> milestones</span>
                                </div>
                                <?php if (!empty($run['campaign_brief_id'])): ?><p class="playbook-generated-brief"><a href="marketing_brief_view.php?id=<?php echo (int) $run['campaign_brief_id']; ?>">Open generated brief</a></p><?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                    </section>
                </div>
            </details>

            <details class="content-card marketing-playbook-view-tools" id="playbook-strategy">
                <summary>Strategy and plan</summary>
                <div class="marketing-playbook-view-tools-body">
                    <section class="content-card">
                        <div class="premium-section-header"><h2>Strategy Links</h2></div>
                        <?php foreach ($strategyFields as $label => $value): ?>
                            <div class="mini-row"><span><?php echo htmlspecialchars($label); ?></span><strong><?php echo htmlspecialchars(trim($value) !== '' ? $value : '-'); ?></strong></div>
                        <?php endforeach; ?>
                        <?php if (!empty($playbook['description'])): ?><p class="playbook-copy-block"><?php echo htmlspecialchars((string) $playbook['description']); ?></p><?php endif; ?>
                    </section>
                    <section class="content-card">
                        <div class="premium-section-header"><h2>Plan</h2></div>
                        <h3 class="playbook-mini-heading">Success Metrics</h3>
                        <?php foreach ((array) ($playbook['success_metrics_json'] ?? []) as $metric): ?><div class="mini-row"><span><?php echo htmlspecialchars((string) $metric); ?></span></div><?php endforeach; ?>
                        <h3 class="playbook-mini-heading">Channel Plan</h3>
                        <?php foreach ((array) ($playbook['channel_plan_json'] ?? []) as $channel): ?><div class="mini-row"><span><?php echo htmlspecialchars((string) $channel); ?></span></div><?php endforeach; ?>
                    </section>
                </div>
            </details>

            <details class="content-card marketing-playbook-view-tools" id="playbook-actions">
                <summary>Playbook actions</summary>
                <div class="marketing-playbook-view-tools-body marketing-playbook-view-action-panel">
                    <a class="btn-premium-secondary" href="marketing_playbooks.php"><i class="fas fa-arrow-left"></i> Playbook Library</a>
                    <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_playbook_edit.php?id=<?php echo (int) $id; ?>"><i class="fas fa-pen"></i> Edit Playbook</a><?php endif; ?>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="playbook-action-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create_brief">
                            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                            <button class="btn-premium-primary" type="submit"><i class="fas fa-file-circle-plus"></i> Create Brief</button>
                        </form>
                        <form method="POST" class="playbook-action-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="apply_playbook">
                            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                            <button class="btn-premium-secondary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Apply Campaign Kit</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canManageMarketing): ?>
                        <form method="POST" onsubmit="return confirm('Archive this campaign playbook?');" class="playbook-danger-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                            <button class="btn-premium-secondary manage-only" type="submit">Archive Playbook</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const openHashDrawer = () => {
        const hash = window.location.hash.replace('#', '');
        if (hash === '') {
            return;
        }
        const target = document.getElementById(hash);
        const drawer = target ? target.closest('details.marketing-playbook-view-tools') : null;
        if (drawer) {
            drawer.open = true;
        }
    };

    document.querySelectorAll('.marketing-playbook-view-card-action[href^="#"], .marketing-playbook-view-today-action[href^="#"]').forEach((link) => {
        link.addEventListener('click', () => window.setTimeout(openHashDrawer, 0));
    });
    window.addEventListener('hashchange', openHashDrawer);
    openHashDrawer();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
