<?php
/**
 * Marketing Relationship Graph.
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
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to refresh the relationship graph.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $marketing->refreshMarketingRelationshipGraph((int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_relationships.php?success=refreshed');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['success'] ?? '') === 'refreshed') {
    $notice = 'Relationship graph refreshed.';
}

$summary = $marketing->getMarketingRelationshipGraphSummary(60);
$edges = (array) ($summary['edges'] ?? []);
$orphaned = (array) ($summary['orphaned'] ?? []);
$counts = (array) ($summary['counts'] ?? []);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));

$edgeCount = (int) ($counts['edges'] ?? count($edges));
$recordTypes = count((array) ($counts['record_types'] ?? []));
$relationshipTypes = count((array) ($counts['relationship_types'] ?? []));
$orphanedCount = (int) ($counts['orphaned'] ?? count($orphaned));
$recommendedActions = (array) ($summary['recommended_actions'] ?? []);

$summaryCards = [
    ['label' => 'Connections', 'value' => $edgeCount, 'icon' => 'fa-diagram-project', 'tooltip' => 'Known links between campaign plans, content, audiences, landing pages, tracking, and CRM records.'],
    ['label' => 'Record Types', 'value' => $recordTypes, 'icon' => 'fa-shapes', 'tooltip' => 'Different kinds of Marketing records represented in the map.'],
    ['label' => 'Link Types', 'value' => $relationshipTypes, 'icon' => 'fa-link', 'tooltip' => 'Different relationship categories found in the current workspace.'],
    ['label' => 'Gaps', 'value' => $orphanedCount, 'icon' => 'fa-triangle-exclamation', 'tooltip' => 'Records that may be disconnected from the campaign path.'],
];

$connectionStages = [
    [
        'title' => 'Map Work',
        'icon' => 'fa-diagram-project',
        'status' => $edgeCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $edgeCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $edgeCount,
        'metric_label' => 'links',
        'tooltip' => 'Connections show whether campaign work is tied together instead of living as separate pieces.',
        'action_label' => 'View map',
        'action_href' => '#connection-map',
    ],
    [
        'title' => 'Find Gaps',
        'icon' => 'fa-magnifying-glass-chart',
        'status' => $orphanedCount > 0 ? 'setup_needed' : 'ready',
        'badge' => $orphanedCount > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $orphanedCount,
        'metric_label' => 'gaps',
        'tooltip' => 'Disconnected records tell a founder what may need linking before launch or learning.',
        'action_label' => 'See gaps',
        'action_href' => '#connection-tools',
    ],
    [
        'title' => 'Refresh',
        'icon' => 'fa-arrows-rotate',
        'status' => $canWriteMarketing ? 'ready' : 'locked',
        'badge' => $canWriteMarketing ? 'Ready' : 'Locked',
        'metric' => $canWriteMarketing ? 'Manual' : 'Read only',
        'metric_label' => 'refresh',
        'tooltip' => 'Refresh reads current CRM and Marketing links; it does not send, publish, or call external tools.',
        'action_label' => 'Refresh',
        'action_href' => '#connection-tools',
    ],
    [
        'title' => 'Route Next',
        'icon' => 'fa-route',
        'status' => $recommendedActions !== [] ? 'setup_needed' : 'ready',
        'badge' => $recommendedActions !== [] ? 'Setup needed' : 'Ready',
        'metric' => (string) count($recommendedActions),
        'metric_label' => 'actions',
        'tooltip' => 'Recommended actions are internal next steps for connecting the campaign system.',
        'action_label' => 'Actions',
        'action_href' => '#connection-actions',
    ],
    [
        'title' => 'Keep Safe',
        'icon' => 'fa-shield-halved',
        'status' => 'ready',
        'badge' => 'Ready',
        'metric' => 'Manual',
        'metric_label' => 'guardrail',
        'tooltip' => 'The map is workspace-scoped and manual-first; it never publishes or sends externally.',
        'action_label' => 'Guardrails',
        'action_href' => '#connection-guardrails',
    ],
];

$todayActions = [];
if ($orphanedCount > 0) {
    $todayActions[] = ['label' => 'Connect orphaned records', 'href' => '#connection-tools', 'meta' => $orphanedCount . ' gaps'];
}
if ($recommendedActions !== []) {
    $todayActions[] = ['label' => 'Review next connection', 'href' => '#connection-actions', 'meta' => count($recommendedActions) . ' actions'];
}
if ($edgeCount === 0 && $canWriteMarketing) {
    $todayActions[] = ['label' => 'Refresh the map', 'href' => '#connection-tools', 'meta' => 'manual scan'];
}
if ($edgeCount > 0) {
    $todayActions[] = ['label' => 'Open action router', 'href' => 'marketing_action_router.php', 'meta' => 'use the map'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Return to Marketing', 'href' => 'marketing.php', 'meta' => 'command path'];
}
$todayActions = array_slice($todayActions, 0, 5);

$pageTitle = 'Marketing Relationship Graph - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-relationships-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Campaign Connection Map</h1>
                <p>See what is connected and what needs linking.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_action_router.php"><i class="fas fa-route"></i> Router</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

        <section class="marketing-relationships-shell" aria-label="Campaign connection map">
            <div class="marketing-founder-summary marketing-relationships-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-relationships-stage-grid" aria-label="Connection path">
                <?php foreach ($connectionStages as $stage): ?>
                    <article class="marketing-relationships-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-relationships-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-relationships-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-relationships-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-relationships-layout">
                <section class="marketing-relationships-board" id="connection-map">
                    <div class="premium-section-header">
                        <h2>Connection Map</h2>
                        <p>Campaign pieces that already point to each other.</p>
                    </div>
                    <div class="marketing-relationships-list">
                        <?php if (empty($edges)): ?>
                            <div class="empty-state"><p>No relationships have been captured yet.</p></div>
                        <?php else: foreach ($edges as $edge): ?>
                            <article class="marketing-relationships-card" data-tooltip="<?php echo $h($labelize((string) ($edge['relationship_type'] ?? 'references')) . ' relationship'); ?>" tabindex="0">
                                <div>
                                    <span class="marketing-stage-status"><?php echo $h($labelize((string) ($edge['relationship_status'] ?? 'active'))); ?></span>
                                    <h3><?php echo $h((string) ($edge['label'] ?? 'Marketing relationship')); ?></h3>
                                    <div class="marketing-relationships-path">
                                        <span><?php echo $h($labelize((string) ($edge['source_type'] ?? 'custom'))); ?>: <?php echo $h((string) ($edge['source_title'] ?? ('#' . (int) ($edge['source_id'] ?? 0)))); ?></span>
                                        <i class="fas fa-arrow-right"></i>
                                        <span><?php echo $h($labelize((string) ($edge['target_type'] ?? 'custom'))); ?>: <?php echo $h((string) ($edge['target_title'] ?? ('#' . (int) ($edge['target_id'] ?? 0)))); ?></span>
                                    </div>
                                </div>
                                <span class="marketing-relationships-chip"><?php echo $h($labelize((string) ($edge['relationship_type'] ?? 'references'))); ?></span>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

                <aside class="marketing-relationships-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One connection step to make the campaign easier to run.</p>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo $h($action['href']); ?>">
                                <span><?php echo $h($action['label']); ?></span>
                                <small><?php echo $h($action['meta']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="marketing-relationships-tools" id="connection-tools">
                <summary>More connection tools</summary>
                <div class="marketing-relationships-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_action_router.php" data-tooltip="Use the map to route to the right internal Marketing page."><i class="fas fa-route"></i><span>Action Router</span></a>
                        <a href="marketing_system_map.php" data-tooltip="Open the expert system map."><i class="fas fa-sitemap"></i><span>System Map</span></a>
                        <a href="marketing_task_hub.php" data-tooltip="Turn connection gaps into tasks."><i class="fas fa-list-check"></i><span>Task Hub</span></a>
                        <a href="marketing_operations.php" data-tooltip="Add connection work to the weekly operating queue."><i class="fas fa-calendar-week"></i><span>Operations</span></a>
                        <a href="marketing.php" data-tooltip="Return to the visual Marketing command path."><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-relationships-detail-grid">
                        <article class="marketing-relationships-detail-section">
                            <div class="premium-section-header"><h2>Orphaned Records</h2></div>
                            <div class="marketing-relationships-list compact">
                                <?php if (empty($orphaned)): ?>
                                    <div class="empty-state"><p>No orphan warnings in the current sample.</p></div>
                                <?php else: foreach ($orphaned as $orphan): ?>
                                    <article class="marketing-relationships-mini-card">
                                        <strong><?php echo $h($labelize((string) ($orphan['record_type'] ?? 'record'))); ?>: <?php echo $h((string) ($orphan['title'] ?? ('#' . (int) ($orphan['record_id'] ?? 0)))); ?></strong>
                                        <span><?php echo $h((string) ($orphan['label'] ?? 'Review this relationship.')); ?></span>
                                    </article>
                                <?php endforeach; endif; ?>
                            </div>
                        </article>

                        <article class="marketing-relationships-detail-section" id="connection-actions">
                            <div class="premium-section-header"><h2>Recommended Actions</h2></div>
                            <div class="marketing-relationships-action-list">
                                <?php if (empty($recommendedActions)): ?><span>No relationship recommendations right now.</span><?php else: foreach ($recommendedActions as $action): ?><span><?php echo $h((string) $action); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-relationships-detail-grid">
                        <article class="marketing-relationships-detail-section" id="connection-guardrails">
                            <div class="premium-section-header"><h2>Guardrails</h2></div>
                            <div class="marketing-relationships-action-list">
                                <span>Workspace-scoped graph only.</span>
                                <span>No external sending, publishing, or API execution.</span>
                                <span>Auto-refresh reads existing CRM and Marketing links.</span>
                            </div>
                        </article>

                        <article class="marketing-relationships-detail-section">
                            <div class="premium-section-header"><h2>Refresh Map</h2></div>
                            <?php if ($canWriteMarketing): ?>
                                <form class="marketing-relationships-refresh" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <button class="btn-premium-primary" type="submit">Refresh Connection Map</button>
                                </form>
                            <?php else: ?>
                                <div class="empty-state"><p>Read-only access.</p></div>
                            <?php endif; ?>
                        </article>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
