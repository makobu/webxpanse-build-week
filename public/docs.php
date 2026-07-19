<?php
/**
 * Feature Documentation Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\WebsiteAssistantContext;
use CRM\Modules\DocumentationContent;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canSeeAdminDocs = Authorization::canAny([
    'admin.users.manage',
    'crm.custom_fields.manage',
    'workflows.manage',
    'admin.audit_logs.view',
], $user);
$contextBuilder = new WebsiteAssistantContext();
$docContent = new DocumentationContent();
$features = $contextBuilder->getFeaturesForDocs($userId);

// Map features to categories (by path basename)
$categoryMap = [
    'Main' => ['dashboard', 'contacts', 'companies', 'deals', 'inbox', 'targets'],
    'Sales & Activities' => ['tasks', 'activities', 'calendar'],
    'Communication' => ['emails', 'email_templates', 'bulk_email', 'bulk_sms', 'bulk_whatsapp', 'campaigns'],
    'Analytics' => ['analytics', 'reports'],
    'Organization' => ['tags'],
    'Settings & Notifications' => ['settings', 'notifications'],
    'Admin' => ['users', 'custom_fields', 'workflows', 'webhooks', 'api_keys', 'audit_logs'],
];

$grouped = [];
foreach ($features as $f) {
    $basename = basename(parse_url($f['path'], PHP_URL_PATH) ?: $f['path']);
    $key = str_replace('.php', '', $basename);
    foreach ($categoryMap as $cat => $keys) {
        if (in_array($key, $keys)) {
            $f['slug'] = $key;
            $f['doc'] = $docContent->get($key);
            $grouped[$cat][] = $f;
            break;
        }
    }
}

// Filter out Admin section for non-admins
if (!$canSeeAdminDocs) {
    unset($grouped['Admin']);
}

$pageTitle = 'Feature Documentation - ' . brandProductName();
$docsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_DOCS);
$additionalScripts = ['assets/js/docs-accordion.js'];
ob_start();
?>

<link rel="stylesheet" href="assets/css/docs-accordion.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div style="margin-bottom: var(--spacing-xl); display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
    <div>
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Feature Documentation</h1>
        <p style="color: var(--charcoal-grey);">Learn how to use your Clarity CRM workflows and tools. Click a feature to expand the documentation.</p>
    </div>
    <?php if ($docsGuideVideoUrl !== ''): ?>
        <div style="display:flex; justify-content:flex-end;">
            <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_DOCS, 'Feature Documentation page guide', 'btn-premium-secondary'); ?>
        </div>
    <?php endif; ?>
</div>

<?php foreach ($grouped as $category => $items): ?>
    <div style="margin-bottom: var(--spacing-xl);">
        <h2 style="color: var(--midnight-black); font-size: 1.25rem; margin-bottom: var(--spacing-md); padding-bottom: var(--spacing-xs); border-bottom: 2px solid var(--accent-blue);">
            <?php echo htmlspecialchars($category); ?>
        </h2>
        <div class="docs-accordion">
            <?php foreach ($items as $f): ?>
                <div class="docs-accordion-item" data-feature="<?php echo htmlspecialchars($f['slug']); ?>">
                    <button type="button" class="docs-accordion-header" aria-expanded="false" aria-controls="docs-body-<?php echo htmlspecialchars($f['slug']); ?>" id="docs-header-<?php echo htmlspecialchars($f['slug']); ?>">
                        <span class="docs-accordion-title"><?php echo htmlspecialchars($f['name']); ?></span>
                        <p class="docs-accordion-desc"><?php echo htmlspecialchars($f['desc']); ?></p>
                        <i class="fas fa-chevron-down docs-accordion-icon" aria-hidden="true"></i>
                    </button>
                    <div class="docs-accordion-body" id="docs-body-<?php echo htmlspecialchars($f['slug']); ?>" hidden>
                        <div class="docs-content">
                            <?php if ($f['doc']): ?>
                                <h4>Overview</h4>
                                <p><?php echo htmlspecialchars($f['doc']['overview']); ?></p>
                                <?php if (!empty($f['doc']['how_to'])): ?>
                                    <h4>How to use</h4>
                                    <ul>
                                        <?php foreach ($f['doc']['how_to'] as $step): ?>
                                            <li><?php echo htmlspecialchars($step); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($f['doc']['lifecycle'])): ?>
                                    <h4>Campaign lifecycle</h4>
                                    <ul>
                                        <?php foreach ($f['doc']['lifecycle'] as $stage): ?>
                                            <li><?php echo htmlspecialchars($stage); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($f['doc']['troubleshooting'])): ?>
                                    <h4>Troubleshooting</h4>
                                    <ul>
                                        <?php foreach ($f['doc']['troubleshooting'] as $item): ?>
                                            <li><?php echo htmlspecialchars($item); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($f['doc']['tips'])): ?>
                                    <h4>Tips</h4>
                                    <ul>
                                        <?php foreach ($f['doc']['tips'] as $tip): ?>
                                            <li><?php echo htmlspecialchars($tip); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php else: ?>
                                <p><?php echo htmlspecialchars($f['desc']); ?></p>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($f['path']); ?>" class="docs-open-link">Open <?php echo htmlspecialchars($f['name']); ?></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_DOCS, 'How to use Feature Documentation', $docsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
