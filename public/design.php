<?php

/** Design Marketplace plugin workspace. */

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\DesignService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$canWrite = Authorization::can('marketing.write', $user);
$design = new DesignService($workspaceId);
$design->assertInstalled();
$summary = $design->dashboardSummary();
$readiness = $design->readiness((int) ($user['id'] ?? 0));
$isReady = !empty($readiness['ready']);
$landingPages = $design->recentLandingPages(8);
$forms = $design->recentForms(8);
$activeTab = strtolower(trim((string) ($_GET['tab'] ?? 'overview')));
if (!in_array($activeTab, ['overview', 'landing-pages', 'forms', 'signatures', 'assets'], true)) {
    $activeTab = 'overview';
}

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$hasLeadForm = (int) ($summary['forms'] ?? 0) > 0;
$pluginQuickStart = [
    'key' => 'design',
    'outcome' => 'Publish a page that captures one lead',
    'steps' => [
        ['label' => 'Create a landing page', 'complete' => (int) ($summary['landing_pages'] ?? 0) > 0, 'href' => 'marketing_landing_page_edit.php', 'action_label' => 'Create landing page'],
        [
            'label' => 'Connect a lead form',
            'complete' => (int) ($summary['pages_with_forms'] ?? 0) > 0,
            'href' => $hasLeadForm ? 'marketing_landing_pages.php' : 'form_edit.php',
            'action_label' => $hasLeadForm ? 'Connect lead form' : 'Create lead form',
        ],
        ['label' => 'Publish the page', 'complete' => (int) ($summary['published_pages'] ?? 0) > 0, 'href' => 'marketing_landing_pages.php', 'action_label' => 'Review page readiness'],
    ],
    'completion_action' => ['href' => 'design.php?tab=landing-pages', 'action_label' => 'Open landing pages'],
];
$pageTitle = 'Design - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/design.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/design.css') ?: 1); ?>">
<link rel="stylesheet" href="assets/css/plugin-workspaces.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/plugin-workspaces.css') ?: 1); ?>">
<script src="assets/js/plugin-workspaces.js?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/js/plugin-workspaces.js') ?: 1); ?>" defer></script>
<div class="page-premium design-workspace plugin-workspace-shell" data-design-workspace>
    <div class="container design-container">
        <header class="page-header design-header">
            <div>
                <h1>Design</h1>
                <p>Pages, forms, signatures and assets.</p>
            </div>
            <div class="page-header-actions">
                <a class="workspace-setup-link" href="workspace_skills.php?module=design#setup">Plugin setup</a>
                <?php if ($canWrite): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php"><i class="fas fa-plus"></i><span class="workspace-cta-full">Create landing page</span><span class="workspace-cta-compact">New page</span></a><?php endif; ?>
            </div>
        </header>

        <section class="design-summary" aria-label="Design workspace summary">
            <article><span class="design-summary-icon is-violet"><i class="fas fa-window-maximize"></i></span><div><small>Landing pages</small><strong><?php echo number_format((int) ($summary['landing_pages'] ?? 0)); ?></strong></div></article>
            <article><span class="design-summary-icon is-blue"><i class="fas fa-rectangle-list"></i></span><div><small>Lead forms</small><strong><?php echo number_format((int) ($summary['forms'] ?? 0)); ?></strong></div></article>
            <article><span class="design-summary-icon is-amber"><i class="fas fa-photo-film"></i></span><div><small>Assets</small><strong><?php echo number_format((int) ($summary['creative_assets'] ?? 0)); ?></strong></div></article>
            <article><span class="design-summary-icon <?php echo $isReady ? 'is-green' : 'is-amber'; ?>"><i class="fas <?php echo $isReady ? 'fa-circle-check' : 'fa-arrow-right'; ?>"></i></span><div><small>Status</small><strong><?php echo $isReady ? 'Ready' : 'Start'; ?></strong></div></article>
        </section>

        <?php include __DIR__ . '/../views/partials/plugin_product_quick_start.php'; ?>

        <nav class="design-tabs" aria-label="Design workspace sections">
            <?php foreach (['overview' => 'Overview', 'landing-pages' => 'Landing pages', 'forms' => 'Forms', 'signatures' => 'Signatures', 'assets' => 'Assets'] as $key => $label): ?>
                <a href="design.php?tab=<?php echo $h($key); ?>" class="<?php echo $activeTab === $key ? 'is-active' : ''; ?>" aria-current="<?php echo $activeTab === $key ? 'page' : 'false'; ?>"><?php echo $h($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($activeTab === 'overview'): ?>
            <div class="design-overview-grid">
                <section class="design-panel design-start-panel">
                    <div class="design-section-heading"><div><h2>Next up</h2></div></div>
                    <div class="design-start-actions">
                        <?php if ($canWrite): ?><a href="marketing_landing_page_edit.php" class="design-start-card"><span><i class="fas fa-window-maximize"></i></span><div><strong>Create landing page</strong><small>Build headline, CTA, form connection, SEO and preview state.</small></div><i class="fas fa-arrow-right"></i></a><?php endif; ?>
                        <?php if ($canWrite): ?><a href="form_edit.php" class="design-start-card"><span><i class="fas fa-rectangle-list"></i></span><div><strong>Create lead form</strong><small>Capture qualified leads and route submissions into the CRM.</small></div><i class="fas fa-arrow-right"></i></a><?php endif; ?>
                        <a href="email_signatures.php" class="design-start-card"><span><i class="fas fa-signature"></i></span><div><strong>Design email signatures</strong><small>Keep personal sign-offs consistent with email-safe brand typography.</small></div><i class="fas fa-arrow-right"></i></a>
                        <a href="marketing_assets.php" class="design-start-card"><span><i class="fas fa-photo-film"></i></span><div><strong>Review creative assets</strong><small>Check media, usage rights, alt text and page placement.</small></div><i class="fas fa-arrow-right"></i></a>
                    </div>
                </section>
                <aside class="design-panel design-readiness-panel">
                    <div class="design-section-heading"><div><h2><?php echo $isReady ? 'Ready' : 'Finish setup'; ?></h2></div></div>
                    <div class="design-check-list">
                        <?php foreach (array_slice((array) ($readiness['checks'] ?? []), 0, 3) as $check): ?>
                            <div class="design-check <?php echo !empty($check['ok']) ? 'is-ready' : ''; ?>">
                                <i class="fas <?php echo !empty($check['ok']) ? 'fa-circle-check' : 'fa-circle'; ?>"></i>
                                <div><strong><?php echo $h((string) ($check['label'] ?? 'Readiness check')); ?></strong></div>
                                <?php if (!empty($check['required'])): ?><span>Required</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="design-next-action"><strong>Next:</strong> <?php echo $h((string) ($readiness['next_action'] ?? 'Create or review a landing page.')); ?></p>
                </aside>
            </div>
        <?php elseif ($activeTab === 'landing-pages'): ?>
            <section class="design-panel">
                <div class="design-section-heading design-section-heading-actions"><div><span class="design-eyebrow">Conversion destinations</span><h2>Landing pages</h2><p>Recent page plans from the Design workspace.</p></div><div><?php if ($canWrite): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php">New landing page</a><?php endif; ?><a class="btn-premium-secondary" href="marketing_landing_pages.php">Open page board</a></div></div>
                <div class="design-record-list">
                    <?php if ($landingPages === []): ?><div class="design-empty"><i class="fas fa-window-maximize"></i><h3>No landing pages yet</h3><p>Create the first page to add a conversion destination and unlock preview readiness.</p><?php if ($canWrite): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php">Create landing page</a><?php endif; ?></div><?php endif; ?>
                    <?php foreach ($landingPages as $page): ?>
                        <article><div><span class="design-record-status"><?php echo $h($labelize((string) ($page['status'] ?? 'draft'))); ?></span><h3><a href="marketing_landing_page_view.php?id=<?php echo (int) ($page['id'] ?? 0); ?>"><?php echo $h((string) ($page['title'] ?? 'Landing page')); ?></a></h3><p>/<?php echo $h((string) ($page['slug'] ?? '')); ?> &middot; <?php echo $h($labelize((string) ($page['publication_status'] ?? 'unpublished'))); ?></p></div><div class="design-record-score"><strong><?php echo (int) ($page['readiness_score'] ?? 0); ?>%</strong><small>readiness</small></div><a class="btn-premium-secondary" href="marketing_landing_page_view.php?id=<?php echo (int) ($page['id'] ?? 0); ?>">Open</a></article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($activeTab === 'forms'): ?>
            <section class="design-panel">
                <div class="design-section-heading design-section-heading-actions"><div><span class="design-eyebrow">Lead capture</span><h2>Forms</h2><p>Connect page traffic to CRM submissions and follow-through.</p></div><div><?php if ($canWrite): ?><a class="btn-premium-primary" href="form_edit.php">New form</a><?php endif; ?><a class="btn-premium-secondary" href="forms.php">Open form builder</a></div></div>
                <div class="design-record-list">
                    <?php if ($forms === []): ?><div class="design-empty"><i class="fas fa-rectangle-list"></i><h3>No forms yet</h3><p>Create a form to capture page conversions and route leads into the CRM.</p><?php if ($canWrite): ?><a class="btn-premium-primary" href="form_edit.php">Create lead form</a><?php endif; ?></div><?php endif; ?>
                    <?php foreach ($forms as $form): ?>
                        <article><div><span class="design-record-status">Lead form</span><h3><a href="<?php echo $canWrite ? 'form_edit.php' : 'form.php'; ?>?<?php echo $canWrite ? 'id=' . (int) ($form['id'] ?? 0) : 'uuid=' . rawurlencode((string) ($form['uuid'] ?? '')); ?>"<?php echo $canWrite ? '' : ' target="_blank"'; ?>><?php echo $h((string) ($form['name'] ?? 'Form')); ?></a></h3><p>Updated <?php echo $h(date('M j, Y', strtotime((string) ($form['updated_at'] ?? 'now')))); ?></p></div><div class="design-record-score"><strong><?php echo (int) ($form['submission_count'] ?? 0); ?></strong><small>submissions</small></div><a class="btn-premium-secondary" href="form_submissions.php?form_uuid=<?php echo rawurlencode((string) ($form['uuid'] ?? '')); ?>">Results</a></article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($activeTab === 'signatures'): ?>
            <section class="design-panel">
                <div class="design-section-heading design-section-heading-actions"><div><span class="design-eyebrow">Email identity</span><h2>Email signatures</h2><p>Build dependable, branded sign-offs with email-safe fonts, colours, logos and reusable templates.</p></div><div><a class="btn-premium-primary" href="email_signature_create.php">New signature</a><a class="btn-premium-secondary" href="email_signatures.php">Open signature library</a></div></div>
                <div class="design-tool-grid">
                    <a href="email_signatures.php"><i class="fas fa-signature"></i><strong>Signature library</strong><span>Review defaults, duplicate successful layouts and copy formatted signatures.</span></a>
                    <a href="email_signature_create.php"><i class="fas fa-font"></i><strong>Signature builder</strong><span>Choose an email-safe font and size, then add content, colours and a logo.</span></a>
                </div>
            </section>
        <?php else: ?>
            <section class="design-panel" id="advanced-design-tools">
                <div class="design-section-heading"><div><span class="design-eyebrow">Creative system</span><h2>Assets and brand tools</h2><p>Prepare reusable media, brand direction and search context for pages and forms.</p></div></div>
                <div class="design-tool-grid">
                    <a href="marketing_creative.php"><i class="fas fa-pen-ruler"></i><strong>Creative production</strong><span>Brief, produce and review campaign creative.</span></a>
                    <a href="marketing_assets.php"><i class="fas fa-images"></i><strong>Asset library</strong><span>Manage media readiness, rights and placement.</span></a>
                    <a href="marketing_brand.php"><i class="fas fa-palette"></i><strong>Brand library</strong><span>Keep visual and message direction consistent.</span></a>
                    <a href="marketing_seo.php"><i class="fas fa-magnifying-glass-chart"></i><strong>SEO topics</strong><span>Connect landing pages to search intent.</span></a>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
