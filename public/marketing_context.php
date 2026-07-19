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
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to save marketing context.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $marketing->createContextItem($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_context.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$type = (string) ($_GET['type'] ?? '');
$filters = $type !== '' ? ['item_type' => $type] : [];
$items = $marketing->listContextItems($filters, 120, 0);
$completeness = $marketing->getContextCompleteness();
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$counts = (array) ($completeness['counts'] ?? []);
$missing = array_values(array_map('strval', (array) ($completeness['missing'] ?? [])));
$missingLookup = array_flip($missing);
$firstMissing = $missing[0] ?? '';
$contextScore = (int) ($completeness['score'] ?? 0);
$contextItemTotal = 0;
foreach (Marketing::CONTEXT_ITEM_TYPES as $contextType) {
    $contextItemTotal += (int) ($counts[$contextType] ?? 0);
}
$primaryActionUrl = match ($firstMissing) {
    'brand_profile' => 'marketing_brand.php',
    'persona' => 'marketing_personas.php',
    default => $firstMissing !== '' ? 'marketing_context.php?type=' . rawurlencode($firstMissing) . '#add-context' : 'marketing_campaign_workspace.php',
};
$primaryActionLabel = match ($firstMissing) {
    'brand_profile' => 'Add brand profile',
    'persona' => 'Add persona',
    '' => 'Use in campaign',
    default => 'Add ' . $labelize($firstMissing),
};
$typeIcons = [
    'offer' => 'fa-gift',
    'competitor' => 'fa-chess',
    'differentiator' => 'fa-fingerprint',
    'proof_point' => 'fa-shield-halved',
    'content_pillar' => 'fa-columns',
    'compliance_term' => 'fa-scale-balanced',
    'default_cta' => 'fa-arrow-pointer',
];
$typeTooltips = [
    'offer' => 'What you sell and why a customer should care now.',
    'competitor' => 'Who else the customer may compare you against.',
    'differentiator' => 'The useful reason this business is not interchangeable.',
    'proof_point' => 'Evidence, result, quote, or trust signal Clarity can reuse.',
    'content_pillar' => 'A repeatable topic area for content and campaign ideas.',
    'compliance_term' => 'Terms, claims, or wording rules marketing should respect.',
    'default_cta' => 'The default next action you want customers to take.',
];
$filteredLabel = $type !== '' ? $labelize($type) : 'All context';
$pageTitle = 'Marketing Context - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-context-page"><div class="container">
    <div class="page-header marketing-page-header"><div><h1>Context Library</h1><p>Store the facts Clarity should reuse in marketing work.</p></div><div class="page-header-actions marketing-page-actions"><a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a><a class="btn-premium-primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>"><i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($primaryActionLabel); ?></a></div></div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">Marketing context saved.</div><?php endif; ?>
    <section class="marketing-context-shell" aria-label="Marketing context library">
        <div class="marketing-founder-summary marketing-context-summary">
            <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>" data-tooltip="The next missing reusable fact for better campaign guidance.">
                <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo htmlspecialchars($primaryActionLabel); ?></strong>
            </a>
            <div class="marketing-summary-tile" data-tooltip="<?php echo htmlspecialchars(implode(', ', $missing) !== '' ? 'Missing: ' . implode(', ', array_map($labelize, $missing)) : 'All required context areas have at least one active item.'); ?>">
                <i class="fas fa-gauge-high"></i><span>Completeness</span><strong><?php echo $contextScore; ?>%</strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Active reusable context items available for content, briefs, and campaign planning.">
                <i class="fas fa-layer-group"></i><span>Saved facts</span><strong><?php echo $contextItemTotal; ?></strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="The visible list is filtered without changing the underlying saved context.">
                <i class="fas fa-filter"></i><span>View</span><strong><?php echo htmlspecialchars($filteredLabel); ?></strong>
            </div>
        </div>

        <div class="marketing-context-type-grid">
            <?php foreach (Marketing::CONTEXT_ITEM_TYPES as $contextType): ?>
                <?php
                    $typeCount = (int) ($counts[$contextType] ?? 0);
                    $isMissing = isset($missingLookup[$contextType]);
                    $typeClass = $isMissing ? 'setup-needed' : 'ready';
                ?>
                <a class="marketing-context-type-card <?php echo $typeClass; ?>" href="marketing_context.php?type=<?php echo urlencode($contextType); ?>#saved-context" data-tooltip="<?php echo htmlspecialchars($typeTooltips[$contextType] ?? 'Reusable marketing context.'); ?>" aria-label="Review <?php echo htmlspecialchars($labelize($contextType)); ?> context">
                    <div class="marketing-stage-visual marketing-context-card-visual">
                        <i class="fas <?php echo htmlspecialchars($typeIcons[$contextType] ?? 'fa-circle-info'); ?>"></i>
                        <span class="marketing-context-count"><?php echo $typeCount; ?></span>
                    </div>
                    <div class="marketing-stage-title-row">
                        <h2><?php echo htmlspecialchars($labelize($contextType)); ?></h2>
                        <span class="marketing-stage-badge <?php echo $typeClass; ?>"><?php echo $isMissing ? 'Setup needed' : 'Ready'; ?></span>
                    </div>
                    <span class="marketing-context-card-action">Review</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="marketing-founder-layout marketing-context-layout">
            <div class="content-card marketing-context-list" id="saved-context">
                <div class="premium-section-header"><div><h2>Saved Context</h2><p><?php echo htmlspecialchars($filteredLabel); ?></p></div></div>
                <?php if (empty($items)): ?>
                    <div class="empty-state"><p>No context items match this view.</p><a href="marketing_onboarding.php">Open Marketing Setup</a></div>
                <?php else: ?>
                    <div class="marketing-context-card-list">
                        <?php foreach ($items as $item): ?>
                            <article class="context-card marketing-context-saved-card">
                                <strong><?php echo htmlspecialchars((string) $item['title']); ?></strong>
                                <div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) $item['item_type'])); ?></span><span><?php echo htmlspecialchars((string) ($item['persona_name'] ?? 'Any persona')); ?></span><span><?php echo htmlspecialchars((string) ($item['channel'] ?? 'Any channel')); ?></span></div>
                                <div class="marketing-context-body"><?php echo htmlspecialchars((string) ($item['body'] ?? '')); ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="content-card marketing-context-form" id="add-context">
                <div class="premium-section-header"><div><h2>Add Context</h2><p>One reusable fact at a time.</p></div></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <div class="form-group"><label>Type</label><select name="item_type"><?php foreach (Marketing::CONTEXT_ITEM_TYPES as $contextType): ?><option value="<?php echo htmlspecialchars($contextType); ?>" <?php echo $selected($type, $contextType); ?>><?php echo htmlspecialchars($labelize($contextType)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
                        <div class="form-group"><label>Persona</label><select name="persona_id"><option value="">Any persona</option><?php foreach ($options['personas'] as $persona): ?><option value="<?php echo (int) $persona['id']; ?>"><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Channel</label><input type="text" name="channel"></div>
                        <div class="form-group"><label>Details</label><textarea name="body" rows="6"></textarea></div>
                        <button class="btn-premium-primary" type="submit">Save Context</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><p>Read-only access.</p></div>
                <?php endif; ?>
            </aside>
        </div>

        <details class="marketing-advanced-tools marketing-context-tools">
            <summary>More context tools <i class="fas fa-chevron-down"></i></summary>
            <div class="marketing-context-tools-body">
                <div class="content-card">
                    <div class="premium-section-header"><h2>Filter library</h2></div>
                    <form method="GET" class="marketing-context-filter">
                        <div class="form-group"><label>Context Type</label><select name="type"><option value="">All</option><?php foreach (Marketing::CONTEXT_ITEM_TYPES as $contextType): ?><option value="<?php echo htmlspecialchars($contextType); ?>" <?php echo $selected($type, $contextType); ?>><?php echo htmlspecialchars($labelize($contextType)); ?></option><?php endforeach; ?></select></div>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                        <a class="btn-premium-secondary" href="marketing_context.php">Reset</a>
                    </form>
                </div>
                <div class="marketing-advanced-tools-grid">
                    <a href="marketing_onboarding.php" data-tooltip="Return to the guided setup path."><strong>Marketing Setup</strong><span>Founder path</span></a>
                    <a href="marketing_brand.php" data-tooltip="Store brand voice, proof, and reusable templates."><strong>Brand</strong><span>Voice and proof</span></a>
                    <a href="marketing_personas.php" data-tooltip="Describe the people this context should serve."><strong>Personas</strong><span>Customer detail</span></a>
                </div>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
