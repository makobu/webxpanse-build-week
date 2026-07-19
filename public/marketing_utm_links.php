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
$options = $marketing->optionData();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to create UTM links.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $marketing->createUtmLink($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_utm_links.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$links = $marketing->listUtmLinks([], 100, 0);
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$campaignLinked = count(array_filter($links, static fn(array $link): bool => (int) ($link['campaign_id'] ?? 0) > 0));
$contentLinked = count(array_filter($links, static fn(array $link): bool => (int) ($link['content_item_id'] ?? 0) > 0));
$sourceCount = count(array_unique(array_filter(array_map(static fn(array $link): string => trim((string) ($link['utm_source'] ?? '')), $links))));
$summaryTiles = [
    ['icon' => 'fa-link', 'label' => 'Links', 'value' => (string) count($links), 'tooltip' => 'Generated tracking links available for manual campaigns.'],
    ['icon' => 'fa-bullhorn', 'label' => 'Campaigns', 'value' => (string) $campaignLinked, 'tooltip' => 'Links connected to CRM campaign records.'],
    ['icon' => 'fa-file-lines', 'label' => 'Content', 'value' => (string) $contentLinked, 'tooltip' => 'Links connected to content items.'],
    ['icon' => 'fa-arrow-up-right-dots', 'label' => 'Sources', 'value' => (string) $sourceCount, 'tooltip' => 'Unique UTM sources represented in saved links.'],
];
$stageCards = [
    ['icon' => 'fa-globe', 'title' => 'Paste Destination', 'status' => 'ready', 'sentence' => 'Start with the page URL.', 'tooltip' => 'Use the page someone should land on before adding tracking tags.', 'href' => '#create-utm-link', 'action' => 'Create Link'],
    ['icon' => 'fa-bullhorn', 'title' => 'Connect Campaign', 'status' => count($options['campaigns'] ?? []) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($options['campaigns'] ?? []) > 0 ? 'Campaigns available.' : 'Optional.', 'tooltip' => 'Campaign connection helps performance and attribution reports explain the result later.', 'href' => '#create-utm-link', 'action' => 'Choose'],
    ['icon' => 'fa-tags', 'title' => 'Add Tags', 'status' => 'ready', 'sentence' => 'Name source and medium.', 'tooltip' => 'Source and medium are the two tags founders usually need first.', 'href' => '#create-utm-link', 'action' => 'Tag'],
    ['icon' => 'fa-copy', 'title' => 'Copy Link', 'status' => count($links) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($links) > 0 ? 'Links ready.' : 'No links yet.', 'tooltip' => 'Copy generated links into manual posts, email drafts, ads, or landing-page references.', 'href' => '#generated-utm-links', 'action' => 'View Links'],
    ['icon' => 'fa-chart-line', 'title' => 'Learn Later', 'status' => count($links) > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Measure the traffic path.', 'tooltip' => 'UTM links become useful when performance, attribution, and conversion events arrive.', 'href' => 'marketing_performance.php', 'action' => 'Performance'],
];
$todayActions = array_slice(array_values(array_filter([
    $canWriteMarketing ? ['label' => 'Create tracking link', 'href' => '#create-utm-link', 'reason' => 'Make the next manual post measurable.'] : null,
    count($links) > 0 ? ['label' => 'Copy latest link', 'href' => '#generated-utm-links', 'reason' => 'Use a saved tracking link in the next manual channel package.'] : null,
    ['label' => 'Open channel exports', 'href' => 'marketing_channel_exports.php', 'reason' => 'Package copy, media, and tracking together.'],
    ['label' => 'Review performance', 'href' => 'marketing_performance.php', 'reason' => 'Check attribution once traffic exists.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Distribution Queue', 'href' => 'marketing_distribution.php', 'hint' => 'Prepare the manual channel post before copying a tracking link.'],
    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'hint' => 'Package copy, media, and UTM links together.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Review UTM and attribution evidence.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect tracking links to launch readiness.'],
];
$pageTitle = 'Marketing UTM Links - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-utm-page"><div class="container">
    <div class="page-header">
        <div><h1>UTM Links</h1><p>Make manual campaign links trackable.</p></div>
        <div class="page-header-actions"><a class="btn-premium-secondary" href="marketing_channel_exports.php"><i class="fas fa-box-open"></i> Channel Exports</a></div>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">UTM link generated.</div><?php endif; ?>

    <section class="marketing-utm-shell">
        <div class="marketing-founder-summary marketing-utm-summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <article class="marketing-summary-tile" data-tooltip="<?php echo $h($tile['tooltip']); ?>" tabindex="0">
                    <i class="fas <?php echo $h($tile['icon']); ?>"></i>
                    <div><span><?php echo $h($tile['label']); ?></span><strong><?php echo $h($tile['value']); ?></strong></div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="marketing-utm-stage-grid">
            <?php foreach ($stageCards as $stage): ?>
                <article class="marketing-utm-stage-card <?php echo $h((string) $stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                    <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                    <div class="marketing-utm-stage-body">
                        <span class="badge <?php echo (string) $stage['status'] === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize((string) $stage['status'])); ?></span>
                        <h2><?php echo $h($stage['title']); ?></h2>
                        <p><?php echo $h($stage['sentence']); ?></p>
                    </div>
                    <a class="btn-premium-secondary marketing-utm-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="marketing-utm-layout">
            <main class="content-card marketing-utm-board" id="generated-utm-links">
                <div class="premium-section-header"><div><h2>Generated Links</h2><p>Copy into manual channels.</p></div></div>
                <?php if (empty($links)): ?>
                    <div class="empty-state"><p>No UTM links yet.</p></div>
                <?php else: ?>
                    <div class="marketing-utm-link-grid">
                        <?php foreach (array_slice($links, 0, 9) as $link): ?>
                            <article class="marketing-utm-link-card" data-tooltip="<?php echo $h('Campaign: ' . ($link['campaign_name'] ?? 'No campaign') . '. Content: ' . ($link['content_title'] ?? 'No content') . '.'); ?>" tabindex="0">
                                <div class="marketing-stage-visual"><i class="fas fa-link"></i></div>
                                <h3><?php echo $h($link['utm_campaign'] ?: ($link['campaign_name'] ?? 'Tracking link')); ?></h3>
                                <a href="<?php echo $h($link['generated_url']); ?>" target="_blank" rel="noopener"><?php echo $h($link['generated_url']); ?></a>
                                <div class="marketing-utm-meta">
                                    <span><?php echo $h($link['utm_source'] ?: 'source'); ?></span>
                                    <span><?php echo $h($link['utm_medium'] ?: 'medium'); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>

            <aside class="content-card marketing-utm-create" id="create-utm-link">
                <div class="premium-section-header"><h2>Create Link</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST" class="marketing-utm-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <div class="form-group"><label>URL</label><input type="url" name="url" required placeholder="https://example.com/page"></div>
                        <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo $h($campaign['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">None</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h($item['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="marketing-utm-tag-grid">
                            <div class="form-group"><label>Source</label><input type="text" name="utm_source" placeholder="linkedin"></div>
                            <div class="form-group"><label>Medium</label><input type="text" name="utm_medium" placeholder="social"></div>
                            <div class="form-group"><label>Campaign Tag</label><input type="text" name="utm_campaign" placeholder="spring-launch"></div>
                            <div class="form-group"><label>Content Tag</label><input type="text" name="utm_content" placeholder="hero-post"></div>
                        </div>
                        <details class="marketing-utm-optional">
                            <summary>More tags</summary>
                            <div class="form-group"><label>Term</label><input type="text" name="utm_term"></div>
                        </details>
                        <button class="btn-premium-primary" type="submit">Generate Link</button>
                    </form>
                <?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
            </aside>
        </div>

        <aside class="content-card marketing-utm-today">
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

        <details class="content-card marketing-utm-tools">
            <summary>More tracking tools</summary>
            <div class="marketing-utm-tools-body">
                <div class="marketing-advanced-tools-grid">
                    <?php foreach ($expertLinks as $link): ?>
                        <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>" tabindex="0">
                            <strong><?php echo $h($link['label']); ?></strong>
                            <span><?php echo $h($link['hint']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
