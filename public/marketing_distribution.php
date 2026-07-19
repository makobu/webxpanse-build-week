<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingMediaReadinessUi;
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
$options = $marketing->optionData();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to manage distribution posts.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'export') {
            $marketing->exportDistributionPost((int) ($_POST['post_id'] ?? 0));
        } elseif ($action === 'publish') {
            $marketing->markDistributionPostPublished((int) ($_POST['post_id'] ?? 0), (string) ($_POST['published_url'] ?? ''), (string) ($_POST['published_at'] ?? ''));
        } else {
            $marketing->createDistributionPost($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        }
        header('Location: ' . getBasePath() . '/marketing_distribution.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$status = (string) ($_GET['status'] ?? '');
$posts = $marketing->listDistributionPosts($status !== '' ? ['status' => $status] : [], 100, 0);
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'distribution', 6);
$mediaReadiness = $marketing->getMarketingMediaOperationalReadiness((int) ($user['id'] ?? 0), 6);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$statusCounts = array_fill_keys(Marketing::DISTRIBUTION_STATUSES, 0);
foreach ($posts as $post) {
    $postStatus = (string) ($post['status'] ?? 'draft');
    $statusCounts[$postStatus] = (int) ($statusCounts[$postStatus] ?? 0) + 1;
}
$openPosts = array_values(array_filter($posts, static fn(array $post): bool => !in_array((string) ($post['status'] ?? ''), ['exported', 'published'], true)));
$exportedPosts = array_values(array_filter($posts, static fn(array $post): bool => (string) ($post['status'] ?? '') === 'exported'));
$publishedPosts = array_values(array_filter($posts, static fn(array $post): bool => (string) ($post['status'] ?? '') === 'published'));
$scheduledPosts = array_values(array_filter($posts, static fn(array $post): bool => !empty($post['scheduled_at'])));
$channelsUsed = array_unique(array_map(static fn(array $post): string => (string) ($post['channel'] ?? 'other'), $posts));
$firstOpenPost = $openPosts[0] ?? null;
$firstExportedPost = $exportedPosts[0] ?? null;
$summaryTiles = [
    ['icon' => 'fa-share-nodes', 'label' => 'Posts', 'value' => (string) count($posts), 'tooltip' => 'Manual channel variants created from Content Studio items.'],
    ['icon' => 'fa-box-open', 'label' => 'Open', 'value' => (string) count($openPosts), 'tooltip' => 'Posts that still need packaging, export, or publishing evidence.'],
    ['icon' => 'fa-calendar-check', 'label' => 'Scheduled', 'value' => (string) count($scheduledPosts), 'tooltip' => 'Distribution posts with a planned schedule.'],
    ['icon' => 'fa-bullhorn', 'label' => 'Published', 'value' => (string) count($publishedPosts), 'tooltip' => 'Posts with a recorded published URL.'],
];
$stageCards = [
    ['label' => 'Choose Content', 'icon' => 'fa-file-lines', 'status' => count($contentItems) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($contentItems) > 0 ? count($contentItems) . ' options.' : 'Add content.', 'tooltip' => 'Distribution starts from a saved Content Studio item.', 'href' => count($contentItems) > 0 ? '#distribution-tools' : 'marketing_content.php', 'action' => count($contentItems) > 0 ? 'Choose' : 'Content'],
    ['label' => 'Pick Channel', 'icon' => 'fa-broadcast-tower', 'status' => count($channelsUsed) > 0 && count($posts) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($posts) > 0 ? count($channelsUsed) . ' channel(s).' : 'Select one.', 'tooltip' => 'Each variant is shaped for one manual channel such as email, social, web, or direct outreach.', 'href' => '#distribution-tools', 'action' => 'Set'],
    ['label' => 'Attach Assets', 'icon' => 'fa-images', 'status' => count($posts) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($posts) > 0 ? 'Packages ready.' : 'Optional.', 'tooltip' => 'Assets and media kits stay available without crowding the board.', 'href' => '#distribution-tools', 'action' => 'Media'],
    ['label' => 'Export Package', 'icon' => 'fa-box', 'status' => count($openPosts) > 0 ? 'warning' : 'ready', 'sentence' => count($openPosts) > 0 ? count($openPosts) . ' open.' : 'Clear.', 'tooltip' => 'Export only marks internal readiness. The system does not publish externally.', 'href' => $firstOpenPost !== null ? 'marketing_distribution_bundle.php?id=' . (int) $firstOpenPost['id'] : '#distribution-board', 'action' => 'Bundle'],
    ['label' => 'Record Publish', 'icon' => 'fa-square-check', 'status' => count($exportedPosts) > 0 ? 'warning' : (count($publishedPosts) > 0 ? 'ready' : 'setup_needed'), 'sentence' => count($publishedPosts) > 0 ? count($publishedPosts) . ' live.' : 'Manual proof.', 'tooltip' => 'After publishing outside the CRM, record the URL and publish time here.', 'href' => $firstExportedPost !== null ? '#post-' . (int) $firstExportedPost['id'] : '#distribution-board', 'action' => 'Record'],
];
$todayActions = array_slice(array_values(array_filter([
    $firstOpenPost !== null ? ['label' => 'Open next bundle', 'href' => 'marketing_distribution_bundle.php?id=' . (int) $firstOpenPost['id'], 'description' => 'Package the next open channel variant.'] : null,
    $firstExportedPost !== null ? ['label' => 'Record publish proof', 'href' => '#post-' . (int) $firstExportedPost['id'], 'description' => 'Add the live URL after manual publishing.'] : null,
    count($posts) === 0 ? ['label' => 'Create first variant', 'href' => '#distribution-tools', 'description' => 'Turn saved content into a channel-ready package.'] : null,
    ['label' => 'Review advanced packaging', 'href' => '#distribution-tools', 'description' => 'Open media, UTM, and channel export tools only when needed.'],
    ['label' => 'Check media readiness', 'href' => '#distribution-tools', 'description' => 'Review blocked media before launch export.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Create or review the source content.'],
    ['label' => 'Media Kits', 'href' => 'marketing_channel_exports.php', 'hint' => 'Prepare manual channel media packages.'],
    ['label' => 'Assets', 'href' => 'marketing_assets.php', 'hint' => 'Check reusable media and rights.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'hint' => 'Prepare tracking links for distribution.'],
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Review launch execution blockers.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Learn what worked after publishing.'],
];
$pageTitle = 'Marketing Distribution - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-distribution-page"><div class="container">
    <div class="page-header marketing-page-header"><div><h1>Distribution Queue</h1><p>Distribution Board: package content for manual channels.</p></div><div class="page-header-actions"><a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a><a class="btn-premium-primary" href="#distribution-board"><i class="fas fa-share-nodes"></i> Open Board</a></div></div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">Distribution queue updated.</div><?php endif; ?>

    <section class="marketing-distribution-shell">
        <div class="marketing-founder-summary marketing-distribution-summary" aria-label="Distribution summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                    <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo $h($tile['label']); ?></span>
                    <strong><?php echo $h($tile['value']); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="marketing-distribution-stage-grid" aria-label="Distribution operating path">
            <?php foreach ($stageCards as $stage): ?>
                <?php $stageStatus = (string) $stage['status']; ?>
                <article class="marketing-distribution-stage-card <?php echo $h($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                    <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i></div>
                    <div class="marketing-distribution-stage-body">
                        <span class="badge <?php echo $stageStatus === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                        <h2><?php echo $h($stage['label']); ?></h2>
                        <p><?php echo $h($stage['sentence']); ?></p>
                        <a class="btn-premium-secondary marketing-distribution-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="marketing-distribution-layout">
            <main class="marketing-distribution-main">
                <section class="content-card marketing-distribution-board" id="distribution-board">
                    <div class="premium-section-header">
                        <div>
                            <h2>Distribution Board</h2>
                            <p>Manual packages by channel.</p>
                        </div>
                        <form method="GET" class="marketing-distribution-filter-form">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">All</option>
                                    <?php foreach (Marketing::DISTRIBUTION_STATUSES as $postStatus): ?><option value="<?php echo $h($postStatus); ?>" <?php echo $selected($status, $postStatus); ?>><?php echo $h($labelize($postStatus)); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                        </form>
                    </div>

                    <div class="marketing-distribution-status-strip">
                        <div><span>Draft</span><strong><?php echo (int) ($statusCounts['draft'] ?? 0); ?></strong></div>
                        <div><span>Ready</span><strong><?php echo (int) ($statusCounts['ready'] ?? 0); ?></strong></div>
                        <div><span>Exported</span><strong><?php echo (int) ($statusCounts['exported'] ?? 0); ?></strong></div>
                        <div><span>Published</span><strong><?php echo (int) ($statusCounts['published'] ?? 0); ?></strong></div>
                    </div>

                    <?php if (empty($posts)): ?>
                        <div class="empty-state"><p>No distribution posts match this view.</p><?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#distribution-tools">Create Variant</a><?php endif; ?></div>
                    <?php else: ?>
                        <div class="marketing-distribution-post-grid">
                            <?php foreach ($posts as $post): ?>
                                <?php
                                $postStatus = (string) ($post['status'] ?? 'draft');
                                $isPublished = $postStatus === 'published';
                                $isExported = $postStatus === 'exported';
                                $postTooltip = $isPublished ? 'Published proof has been recorded.' : ($isExported ? 'Record the published URL after manual publishing.' : 'Open the bundle and mark export readiness when the package is complete.');
                                ?>
                                <article class="marketing-distribution-post-card <?php echo $h($postStatus); ?>" id="post-<?php echo (int) $post['id']; ?>" tabindex="0" data-tooltip="<?php echo $h($postTooltip); ?>">
                                    <div class="marketing-stage-visual"><i class="fas <?php echo $isPublished ? 'fa-square-check' : ($isExported ? 'fa-upload' : 'fa-box'); ?>" aria-hidden="true"></i></div>
                                    <div class="marketing-distribution-post-body">
                                        <span class="badge <?php echo $isPublished ? 'badge-success' : ($isExported ? 'badge-warning' : 'badge-info'); ?>"><?php echo $h($labelize($postStatus)); ?></span>
                                        <h3><?php echo $h($post['content_title'] ?? 'Distribution post'); ?></h3>
                                        <div class="marketing-distribution-meta">
                                            <span><?php echo $h($labelize((string) ($post['channel'] ?? 'other'))); ?></span>
                                            <span><?php echo $h((string) ($post['scheduled_at'] ?? 'Unscheduled')); ?></span>
                                            <?php if (!empty($post['asset_title'])): ?><span><?php echo $h($post['asset_title']); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($post['published_url'])): ?><a class="marketing-distribution-published-link" href="<?php echo $h($post['published_url']); ?>" target="_blank" rel="noopener">Published URL</a><?php endif; ?>
                                        <a class="btn-premium-secondary marketing-distribution-card-action" href="marketing_distribution_bundle.php?id=<?php echo (int) $post['id']; ?>">Bundle</a>
                                        <?php if ($canWriteMarketing): ?>
                                            <details class="marketing-distribution-post-tools">
                                                <summary>Publishing controls</summary>
                                                <div class="marketing-distribution-post-tools-body">
                                                    <a class="btn-premium-secondary" href="marketing_channel_exports.php#create-media-kit">Media Kit</a>
                                                    <?php if (!$isExported && !$isPublished): ?>
                                                        <form method="POST" class="marketing-distribution-inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="export">
                                                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                                            <button class="btn-premium-secondary" type="submit">Mark Exported</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" class="marketing-distribution-publish-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                        <input type="hidden" name="action" value="publish">
                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                                        <input type="url" name="published_url" placeholder="https://published.example/post" required>
                                                        <input type="datetime-local" name="published_at">
                                                        <button class="btn-premium-primary" type="submit">Mark Published</button>
                                                    </form>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>

            <aside class="content-card marketing-distribution-today" aria-label="Today">
                <div class="premium-section-header"><div><h2>Today</h2><p>Move one package forward.</p></div></div>
                <div class="marketing-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-today-item" href="<?php echo $h($action['href'] ?? 'marketing_distribution.php'); ?>">
                            <strong><?php echo $h($action['label'] ?? 'Review distribution'); ?></strong>
                            <span><?php echo $h($action['description'] ?? 'Prepare the next manual channel package.'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>

        <details class="content-card marketing-distribution-tools" id="distribution-tools">
            <summary>More distribution tools</summary>
            <div class="marketing-distribution-tools-body">
                <section class="content-card marketing-distribution-next-card">
                    <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                </section>

                <section class="content-card marketing-distribution-readiness-card">
                    <?php echo MarketingMediaReadinessUi::render($mediaReadiness); ?>
                </section>

                <section class="content-card marketing-distribution-create-card">
                    <div class="premium-section-header"><h2>Create Variant</h2></div>
                    <?php if ($canWriteMarketing): ?><form method="POST" class="marketing-distribution-create-form"><input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="create">
                        <div class="form-group"><label>Source Content</label><select name="content_item_id" required><option value="">Select content</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h($item['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Channel</label><select name="channel"><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo $h($channel); ?>"><?php echo $h($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Asset</label><select name="asset_id"><option value="">None</option><?php foreach ($options['assets'] as $asset): ?><option value="<?php echo (int) $asset['id']; ?>"><?php echo $h($asset['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Schedule</label><input type="datetime-local" name="scheduled_at"></div>
                        <div class="form-group marketing-distribution-wide-field"><label>Publishing Checklist</label><textarea name="publishing_checklist" rows="4" placeholder="Copy checked&#10;Asset attached&#10;UTM added"></textarea></div>
                        <div class="form-group marketing-distribution-wide-field"><label>Required Fields</label><textarea name="required_fields" rows="4" placeholder="Caption&#10;Destination URL"></textarea></div>
                        <div class="form-group marketing-distribution-wide-field"><label>Asset Rules</label><textarea name="asset_rules" rows="4" placeholder="Use approved brand image&#10;Crop for channel"></textarea></div>
                        <div class="form-group marketing-distribution-wide-field"><label>Planned Copy</label><textarea name="planned_copy" rows="8" placeholder="Leave blank to inherit draft body."></textarea></div>
                        <button class="btn-premium-primary" type="submit">Create Variant</button>
                    </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
                </section>

                <section class="content-card marketing-distribution-expert-card">
                    <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the board.</p></div></div>
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>"><strong><?php echo $h($link['label']); ?></strong><span><?php echo $h($link['hint']); ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
