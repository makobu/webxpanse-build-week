<?php
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
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to save SEO topics.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $marketing->createSeoTopic($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_seo.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$status = (string) ($_GET['status'] ?? '');
$topics = $marketing->listSeoTopics($status !== '' ? ['status' => $status] : [], 100, 0);
$allTopics = $status !== '' ? $marketing->listSeoTopics([], 100, 0) : $topics;
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';

$topicCount = count($allTopics);
$visibleTopicCount = count($topics);
$plannedCount = count(array_filter($allTopics, static fn(array $topic): bool => in_array((string) ($topic['status'] ?? ''), ['planned', 'in_progress'], true)));
$publishedCount = count(array_filter($allTopics, static fn(array $topic): bool => (string) ($topic['status'] ?? '') === 'published'));
$linkedCount = count(array_filter($allTopics, static fn(array $topic): bool => !empty($topic['content_title']) || !empty($topic['target_url'])));
$highPriorityCount = count(array_filter($allTopics, static fn(array $topic): bool => (string) ($topic['priority'] ?? '') === 'high'));
$unlinkedCount = max(0, $topicCount - $linkedCount);
$intentCount = count(array_unique(array_filter(array_map(static fn(array $topic): string => (string) ($topic['intent'] ?? ''), $allTopics))));
$currentViewLabel = $status !== '' ? $labelize($status) : 'All';

$summaryCards = [
    ['label' => 'Topics', 'value' => $topicCount, 'icon' => 'fa-magnifying-glass-chart', 'tooltip' => 'Search topics saved for this workspace.'],
    ['label' => 'Planned', 'value' => $plannedCount, 'icon' => 'fa-map-signs', 'tooltip' => 'Topics that are planned or already in progress.'],
    ['label' => 'Linked', 'value' => $linkedCount, 'icon' => 'fa-link', 'tooltip' => 'Topics connected to a content item or target URL.'],
    ['label' => 'View', 'value' => $currentViewLabel, 'icon' => 'fa-filter', 'tooltip' => 'The status filter currently applied to the topic board.'],
];

$seoStages = [
    [
        'title' => 'Pick Topic',
        'icon' => 'fa-magnifying-glass',
        'status' => $topicCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $topicCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $topicCount,
        'metric_label' => $topicCount === 1 ? 'topic' : 'topics',
        'tooltip' => 'Start with search phrases your customers might type before choosing content or channels.',
        'action_label' => 'Add topic',
        'action_href' => '#seo-tools',
    ],
    [
        'title' => 'Match Intent',
        'icon' => 'fa-bullseye',
        'status' => $intentCount > 0 ? 'ready' : 'setup_needed',
        'badge' => $intentCount > 0 ? 'Ready' : 'Setup needed',
        'metric' => (string) $intentCount,
        'metric_label' => $intentCount === 1 ? 'intent' : 'intents',
        'tooltip' => 'Intent turns expert SEO language into a simple question: what is the buyer trying to do?',
        'action_label' => 'Review map',
        'action_href' => '#seo-topic-map',
    ],
    [
        'title' => 'Set Priority',
        'icon' => 'fa-arrow-up-wide-short',
        'status' => $highPriorityCount > 0 ? 'in_use' : ($topicCount > 0 ? 'setup_needed' : 'locked'),
        'badge' => $highPriorityCount > 0 ? 'In use' : ($topicCount > 0 ? 'Setup needed' : 'Locked'),
        'metric' => (string) $highPriorityCount,
        'metric_label' => 'high',
        'tooltip' => 'Priority helps a founder choose what deserves attention first instead of trying every keyword.',
        'action_label' => 'Prioritize',
        'action_href' => '#seo-topic-map',
    ],
    [
        'title' => 'Link Content',
        'icon' => 'fa-link',
        'status' => $linkedCount > 0 ? 'in_use' : ($topicCount > 0 ? 'setup_needed' : 'locked'),
        'badge' => $linkedCount > 0 ? 'In use' : ($topicCount > 0 ? 'Setup needed' : 'Locked'),
        'metric' => (string) $linkedCount,
        'metric_label' => 'linked',
        'tooltip' => 'A topic becomes useful when it points to copy, a landing page, or a destination.',
        'action_label' => 'Open content',
        'action_href' => 'marketing_content.php',
    ],
    [
        'title' => 'Use In Launch',
        'icon' => 'fa-rocket',
        'status' => $publishedCount > 0 ? 'ready' : ($linkedCount > 0 ? 'setup_needed' : 'locked'),
        'badge' => $publishedCount > 0 ? 'Ready' : ($linkedCount > 0 ? 'Setup needed' : 'Locked'),
        'metric' => (string) $publishedCount,
        'metric_label' => 'published',
        'tooltip' => 'Published topics should support the campaign message, landing page, and learning loop.',
        'action_label' => 'Launch checks',
        'action_href' => 'marketing_launch_checklists.php',
    ],
];

$todayActions = [];
if ($topicCount === 0) {
    $todayActions[] = ['label' => 'Add first topic', 'href' => '#seo-tools', 'meta' => 'start map'];
}
if ($topicCount > 0 && $highPriorityCount === 0) {
    $todayActions[] = ['label' => 'Choose one priority', 'href' => '#seo-topic-map', 'meta' => 'focus'];
}
if ($unlinkedCount > 0) {
    $todayActions[] = ['label' => 'Link a topic', 'href' => 'marketing_content.php', 'meta' => $unlinkedCount . ' unlinked'];
}
if ($plannedCount > 0) {
    $todayActions[] = ['label' => 'Move planned topic', 'href' => '#seo-topic-map', 'meta' => $plannedCount . ' planned'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Review launch checks', 'href' => 'marketing_launch_checklists.php', 'meta' => 'next path'];
}
$todayActions[] = ['label' => 'Open content studio', 'href' => 'marketing_content.php', 'meta' => 'draft answer'];
$todayActions = array_slice($todayActions, 0, 5);

$pageTitle = 'Marketing SEO - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-seo-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>SEO Topic Map</h1>
                <p>Choose search topics that support the campaign.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_content.php"><i class="fas fa-layer-group"></i> Content</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">SEO topic saved.</div><?php endif; ?>

        <section class="marketing-seo-shell" aria-label="SEO topic map">
            <div class="marketing-founder-summary marketing-seo-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-seo-stage-grid" aria-label="SEO topic path">
                <?php foreach ($seoStages as $stage): ?>
                    <article class="marketing-seo-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-seo-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-seo-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-seo-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-seo-layout">
                <section class="marketing-seo-board" id="seo-topic-map">
                    <div class="premium-section-header">
                        <h2>Topic Plan</h2>
                        <p><?php echo $h((string) $visibleTopicCount); ?> topic<?php echo $visibleTopicCount === 1 ? '' : 's'; ?> in this view.</p>
                    </div>
                    <div class="marketing-seo-list">
                        <?php if (empty($topics)): ?>
                            <div class="empty-state"><p>No SEO topics match this view.</p><a class="btn-premium-primary" href="#seo-tools">Add Topic</a></div>
                        <?php else: foreach ($topics as $topic): ?>
                            <?php
                                $target = (string) ($topic['content_title'] ?? $topic['target_url'] ?? 'No target yet');
                                $tooltip = $labelize((string) ($topic['intent'] ?? 'informational')) . ' intent, ' . $labelize((string) ($topic['priority'] ?? 'medium')) . ' priority, target: ' . $target;
                            ?>
                            <article class="marketing-seo-topic-card <?php echo $h((string) ($topic['status'] ?? 'idea')); ?>" data-tooltip="<?php echo $h($tooltip); ?>" tabindex="0">
                                <div>
                                    <span class="marketing-stage-status"><?php echo $h($labelize((string) ($topic['status'] ?? 'idea'))); ?></span>
                                    <h3><?php echo $h((string) $topic['keyword']); ?></h3>
                                    <div class="marketing-seo-meta">
                                        <span><?php echo $h($labelize((string) ($topic['intent'] ?? 'informational'))); ?></span>
                                        <span><?php echo $h($labelize((string) ($topic['priority'] ?? 'medium'))); ?></span>
                                        <?php if (!empty($topic['funnel_stage'])): ?><span><?php echo $h($labelize((string) $topic['funnel_stage'])); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="marketing-seo-target">
                                    <i class="fas <?php echo !empty($topic['content_title']) || !empty($topic['target_url']) ? 'fa-link' : 'fa-circle-question'; ?>"></i>
                                    <span><?php echo $h($target); ?></span>
                                </div>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

                <aside class="marketing-seo-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One topic step before more campaign work.</p>
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

            <details class="marketing-seo-tools" id="seo-tools">
                <summary>More SEO tools</summary>
                <div class="marketing-seo-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_content.php" data-tooltip="Draft or connect the content that answers the topic."><i class="fas fa-layer-group"></i><span>Content Studio</span></a>
                        <a href="marketing_landing_pages.php" data-tooltip="Connect topics to campaign landing page destinations."><i class="fas fa-file-lines"></i><span>Landing Pages</span></a>
                        <a href="marketing_campaign_workspace.php" data-tooltip="Use search topics inside campaign planning."><i class="fas fa-bullhorn"></i><span>Campaign Workspace</span></a>
                        <a href="marketing_performance.php" data-tooltip="Learn which topics and channels are producing signals."><i class="fas fa-chart-line"></i><span>Performance</span></a>
                        <a href="marketing.php" data-tooltip="Return to the visual Marketing command path."><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-seo-detail-grid">
                        <article class="marketing-seo-detail-section">
                            <div class="premium-section-header"><h2>Filter Topics</h2></div>
                            <form class="marketing-seo-filter" method="GET">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="">All</option>
                                        <?php foreach (Marketing::SEO_STATUSES as $topicStatus): ?><option value="<?php echo $h($topicStatus); ?>" <?php echo $selected($status, $topicStatus); ?>><?php echo $h($labelize($topicStatus)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn-premium-secondary" type="submit">Filter</button>
                            </form>
                        </article>

                        <article class="marketing-seo-detail-section">
                            <div class="premium-section-header"><h2>Topic Signals</h2></div>
                            <div class="marketing-seo-signal-grid">
                                <article class="marketing-seo-signal-card" data-tooltip="Topics not yet connected to content or destination." tabindex="0"><span>Unlinked</span><strong><?php echo $h((string) $unlinkedCount); ?></strong></article>
                                <article class="marketing-seo-signal-card" data-tooltip="Topics marked as high priority." tabindex="0"><span>High Priority</span><strong><?php echo $h((string) $highPriorityCount); ?></strong></article>
                                <article class="marketing-seo-signal-card" data-tooltip="Topics already published." tabindex="0"><span>Published</span><strong><?php echo $h((string) $publishedCount); ?></strong></article>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-seo-form-card">
                        <div class="premium-section-header"><h2>Add Topic</h2></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <div class="marketing-seo-form-grid">
                                    <div class="form-group"><label>Keyword</label><input type="text" name="keyword" required></div>
                                    <div class="form-group"><label>Intent</label><select name="intent"><?php foreach (Marketing::SEO_INTENTS as $intent): ?><option value="<?php echo $h($intent); ?>"><?php echo $h($labelize($intent)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::SEO_PRIORITIES as $priority): ?><option value="<?php echo $h($priority); ?>"><?php echo $h($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Funnel Stage</label><select name="funnel_stage"><option value="">Not set</option><?php foreach (Marketing::FUNNEL_STAGES as $stage): ?><option value="<?php echo $h($stage); ?>"><?php echo $h($labelize($stage)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Target URL</label><input type="text" name="target_url"></div>
                                </div>
                                <button class="btn-premium-primary" type="submit">Save Topic</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access.</p></div>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
