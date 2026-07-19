<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.write', $user)) { header('Location: ' . getBasePath() . '/marketing_distribution.php'); exit; }

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? 0);
try {
    $bundle = $marketing->getDistributionExportBundle($id);
} catch (Throwable $e) {
    http_response_code(404);
    echo 'Marketing distribution bundle not found.';
    exit;
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$asText = static fn(mixed $value): string => is_scalar($value) || $value === null
    ? (string) $value
    : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
$mediaAttachments = (array) ($bundle['media_attachments'] ?? []);
$channelMediaKits = (array) ($bundle['channel_media_kits'] ?? []);
$publishingChecklist = (array) ($bundle['publishing_checklist'] ?? []);
$requiredFields = (array) ($bundle['required_fields'] ?? []);
$assetRules = (array) ($bundle['asset_rules'] ?? []);
$plannedCopy = trim((string) ($bundle['planned_copy'] ?? ''));
$proofUrl = 'marketing_launch_proof.php?id=' . $id;
$summaryTiles = [
    ['icon' => 'fa-broadcast-tower', 'label' => 'Channel', 'value' => $labelize((string) ($bundle['channel'] ?? 'Other')), 'tooltip' => 'The manual channel this launch packet was prepared for.'],
    ['icon' => 'fa-file-lines', 'label' => 'Copy', 'value' => $plannedCopy !== '' ? 'Ready' : 'Missing', 'tooltip' => 'The copy block to use when publishing outside the CRM.'],
    ['icon' => 'fa-images', 'label' => 'Media', 'value' => (string) count($mediaAttachments), 'tooltip' => 'Media attached from the source content item.'],
    ['icon' => 'fa-box-open', 'label' => 'Kits', 'value' => (string) count($channelMediaKits), 'tooltip' => 'Channel media kits connected to this distribution post.'],
];
$stageCards = [
    ['label' => 'Review Copy', 'icon' => 'fa-file-lines', 'status' => $plannedCopy !== '' ? 'ready' : 'setup_needed', 'sentence' => $plannedCopy !== '' ? 'Copy ready.' : 'Needs copy.', 'tooltip' => 'Check the copy before it leaves the CRM.', 'href' => '#bundle-copy', 'action' => 'Copy'],
    ['label' => 'Check Media', 'icon' => 'fa-images', 'status' => count($mediaAttachments) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($mediaAttachments) . ' attached.', 'tooltip' => 'Confirm the right images, files, and captions are included.', 'href' => '#bundle-media', 'action' => 'Media'],
    ['label' => 'Confirm Fields', 'icon' => 'fa-list-check', 'status' => count($requiredFields) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($requiredFields) . ' fields.', 'tooltip' => 'Required fields are the publishing details the founder should not miss.', 'href' => '#bundle-checklist', 'action' => 'Fields'],
    ['label' => 'Use Media Kit', 'icon' => 'fa-box', 'status' => count($channelMediaKits) > 0 ? 'ready' : 'setup_needed', 'sentence' => count($channelMediaKits) . ' kit(s).', 'tooltip' => 'Media kits package channel assets without publishing externally.', 'href' => '#bundle-kits', 'action' => 'Kits'],
    ['label' => 'Publish Outside', 'icon' => 'fa-shield-halved', 'status' => 'ready', 'sentence' => 'Manual only.', 'tooltip' => 'No external publishing happens here. Use the package manually, then record proof.', 'href' => $proofUrl, 'action' => 'Proof'],
];
$todayActions = [
    ['label' => 'Review package copy', 'href' => '#bundle-copy', 'description' => 'Copy is the first thing to check before manual publishing.'],
    ['label' => 'Confirm required fields', 'href' => '#bundle-checklist', 'description' => 'Make sure channel-specific fields are ready.'],
    ['label' => 'Record manual proof', 'href' => $proofUrl, 'description' => 'Save the published URL after the manual step.'],
];
$expertLinks = [
    ['label' => 'Proof And Results', 'href' => $proofUrl, 'hint' => 'Record the published URL and review first results.'],
    ['label' => 'Distribution Board', 'href' => 'marketing_distribution.php#post-' . $id, 'hint' => 'Return to the post controls.'],
    ['label' => 'Media Kits', 'href' => 'marketing_channel_exports.php#create-media-kit', 'hint' => 'Create or update channel media kits.'],
    ['label' => 'Assets', 'href' => 'marketing_assets.php', 'hint' => 'Review media rights and accessibility.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'hint' => 'Prepare tracking links.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check launch evidence before publishing.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Review results after publishing.'],
];

$pageTitle = 'Manual Launch Packet - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-distribution-bundle-page"><div class="container">
    <div class="page-header marketing-page-header"><div><h1>Manual Launch Packet</h1><p>Founder-ready publishing package. No external publishing happens here.</p></div><div class="page-header-actions"><a class="btn-premium-secondary" href="marketing_distribution.php"><i class="fas fa-arrow-left"></i> Distribution</a><a class="btn-premium-secondary" href="<?php echo $h($proofUrl); ?>"><i class="fas fa-square-check"></i> Record proof</a><a class="btn-premium-primary" href="#bundle-copy"><i class="fas fa-box-open"></i> Open Packet</a></div></div>

    <section class="marketing-distribution-bundle-shell">
        <div class="marketing-founder-summary marketing-distribution-bundle-summary" aria-label="Manual launch packet summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                    <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo $h($tile['label']); ?></span>
                    <strong><?php echo $h($tile['value']); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="marketing-distribution-bundle-stage-grid" aria-label="Manual launch packet review path">
            <?php foreach ($stageCards as $stage): ?>
                <?php $stageStatus = (string) $stage['status']; ?>
                <article class="marketing-distribution-bundle-stage-card <?php echo $h($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                    <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i></div>
                    <div class="marketing-distribution-bundle-stage-body">
                        <span class="badge <?php echo $stageStatus === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                        <h2><?php echo $h($stage['label']); ?></h2>
                        <p><?php echo $h($stage['sentence']); ?></p>
                        <a class="btn-premium-secondary marketing-distribution-bundle-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="marketing-distribution-bundle-layout">
            <main class="marketing-distribution-bundle-main">
                <section class="content-card marketing-distribution-bundle-copy" id="bundle-copy">
                    <div class="premium-section-header">
                        <div>
                            <h2>Copy</h2>
                            <p>Founder-ready manual launch text.</p>
                        </div>
                        <span class="badge <?php echo $plannedCopy !== '' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $plannedCopy !== '' ? 'Ready' : 'Missing'; ?></span>
                    </div>
                    <div class="marketing-distribution-bundle-copy-box"><?php echo $plannedCopy !== '' ? $h($plannedCopy) : 'No planned copy is saved yet.'; ?></div>
                </section>

                <section class="content-card marketing-distribution-bundle-media" id="bundle-media">
                    <div class="premium-section-header">
                        <div>
                            <h2>Media And Links</h2>
                            <p>Attachments to check before manual use.</p>
                        </div>
                    </div>
                    <?php if ($mediaAttachments === []): ?>
                        <div class="empty-state"><p>No media is attached to this manual launch packet.</p></div>
                    <?php else: ?>
                        <div class="marketing-distribution-bundle-media-grid">
                            <?php foreach ($mediaAttachments as $media): ?>
                                <article class="marketing-distribution-bundle-media-card" tabindex="0" data-tooltip="<?php echo $h($media['caption'] ?? 'Review this attachment before manual publishing.'); ?>">
                                    <div class="marketing-distribution-bundle-preview">
                                        <?php if (!empty($media['url']) && in_array((string) ($media['media_type'] ?? ''), ['image','logo','thumbnail','banner'], true)): ?><img src="<?php echo $h($media['url']); ?>" alt="<?php echo $h($media['alt_text'] ?? $media['title'] ?? 'Media preview'); ?>"><?php else: ?><span><?php echo $h($labelize((string) ($media['media_type'] ?? 'media'))); ?></span><?php endif; ?>
                                    </div>
                                    <h3><?php echo $h($media['title'] ?? 'Attached media'); ?></h3>
                                    <div class="marketing-distribution-bundle-meta">
                                        <span><?php echo $h($labelize((string) ($media['role'] ?? 'attachment'))); ?></span>
                                        <span><?php echo $h($labelize((string) ($media['media_type'] ?? 'media'))); ?></span>
                                    </div>
                                    <?php if (!empty($media['url'])): ?><a class="btn-premium-secondary marketing-distribution-bundle-card-action" href="<?php echo $h($media['url']); ?>" target="_blank" rel="noopener">Open media</a><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="content-card marketing-distribution-bundle-checklist" id="bundle-checklist">
                    <div class="premium-section-header"><div><h2>Checklist</h2><p>Details to confirm before publishing manually.</p></div></div>
                    <div class="marketing-distribution-bundle-check-grid">
                        <article class="marketing-distribution-bundle-check-card">
                            <h3>Publishing Checklist</h3>
                            <?php if ($publishingChecklist === []): ?><p>No checklist items were saved.</p><?php else: ?><ul><?php foreach ($publishingChecklist as $item): ?><li><?php echo $h($asText($item)); ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </article>
                        <article class="marketing-distribution-bundle-check-card">
                            <h3>Required Fields</h3>
                            <?php if ($requiredFields === []): ?><p>No required fields were saved.</p><?php else: ?><ul><?php foreach ($requiredFields as $item): ?><li><?php echo $h($asText($item)); ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </article>
                        <article class="marketing-distribution-bundle-check-card">
                            <h3>Asset Rules</h3>
                            <?php if ($assetRules === []): ?><p>No asset rules were saved.</p><?php else: ?><ul><?php foreach ($assetRules as $item): ?><li><?php echo $h($asText($item)); ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </article>
                    </div>
                </section>

                <section class="content-card marketing-distribution-bundle-kits" id="bundle-kits">
                    <div class="premium-section-header"><div><h2>Channel Media Kits</h2><p>Connected channel packages.</p></div></div>
                    <?php if ($channelMediaKits === []): ?><div class="empty-state"><p>No channel media kits are attached yet.</p><a class="btn-premium-primary" href="marketing_channel_exports.php#create-media-kit">Create Media Kit</a></div><?php else: ?>
                        <div class="marketing-distribution-bundle-kit-grid">
                            <?php foreach ($channelMediaKits as $kit): ?>
                                <a class="marketing-distribution-bundle-kit-card" href="marketing_channel_exports.php" data-tooltip="Open channel media kits for technical export details.">
                                    <strong><?php echo $h($kit['title'] ?? $kit['content_title'] ?? 'Media kit'); ?></strong>
                                    <div class="marketing-distribution-bundle-meta">
                                        <span><?php echo $h($labelize((string) ($kit['channel'] ?? $bundle['channel'] ?? 'other'))); ?></span>
                                        <span><?php echo $h($labelize((string) ($kit['status'] ?? 'draft'))); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>

            <aside class="content-card marketing-distribution-bundle-today" aria-label="Today">
                <div class="premium-section-header"><div><h2>Today</h2><p>Review one package.</p></div></div>
                <div class="marketing-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-today-item" href="<?php echo $h($action['href']); ?>">
                            <strong><?php echo $h($action['label']); ?></strong>
                            <span><?php echo $h($action['description']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>

        <details class="content-card marketing-distribution-bundle-tools">
            <summary>More packet tools</summary>
            <div class="marketing-distribution-bundle-tools-body">
                <section class="content-card marketing-distribution-bundle-raw-kits">
                    <div class="premium-section-header"><h2>Channel Media Kits Payload</h2></div>
                    <pre class="marketing-distribution-bundle-code"><?php echo $h(json_encode($channelMediaKits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </section>
                <section class="content-card marketing-distribution-bundle-metadata">
                    <div class="premium-section-header"><h2>Metadata</h2></div>
                    <pre class="marketing-distribution-bundle-code"><?php echo $h(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </section>
                <section class="content-card marketing-distribution-bundle-expert-card">
                    <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the package.</p></div></div>
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
