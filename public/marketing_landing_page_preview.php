<?php
/** Authenticated draft preview rendered by the production block registry. */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Session;
use CRM\Services\LandingPageDesignService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, 'design');
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$page = $marketing->getLandingPageByPreviewToken((string) ($_GET['token'] ?? ''));
if (!$page) { http_response_code(404); echo 'Landing page preview not found.'; exit; }

$requestedViewport = (string) ($_GET['viewport'] ?? 'desktop');
$viewport = in_array($requestedViewport, ['desktop', 'tablet', 'mobile'], true) ? $requestedViewport : 'desktop';
$design = new LandingPageDesignService();
$document = $design->normalizeDocument((array) ($page['design_document_json'] ?? []), $page);
$mediaById = (array) ($page['_media']['by_id'] ?? []);
foreach ($mediaById as &$media) { $media['display_url'] = $marketing->mediaDisplayUrl($media); }
unset($media);
$rendered = $design->render($document, [
    'page' => $page,
    'media_by_id' => $mediaById,
    'form_uuid' => (string) ($page['form_uuid'] ?? ''),
    'forms_by_id' => !empty($page['form_id']) ? [(int) $page['form_id'] => ['uuid' => (string) ($page['form_uuid'] ?? ''), 'preview_token' => (string) ($page['form_preview_token'] ?? '')]] : [],
    'form_preview_token' => (string) ($page['form_preview_token'] ?? ''),
    'mode' => 'preview',
]);
$validation = $design->validateDocument($document, $page);
$previewToken = (string) ($page['preview_token'] ?? '');
$pageTitle = 'Preview - ' . (string) $page['title'] . ' - ' . brandProductName();

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio-public.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio-public.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio.css')); ?>">
<div class="landing-preview marketing-landing-preview design-draft-preview is-<?php echo htmlspecialchars($viewport); ?>">
    <div class="preview-banner">
        <div><span class="design-preview-badge">Draft preview · Authenticated preview only</span><strong><?php echo htmlspecialchars((string) $page['title']); ?></strong><span><?php echo !empty($validation['valid']) ? 'Page valid' : count((array) ($validation['errors'] ?? [])) . ' publishing issue(s)'; ?></span></div>
        <nav class="preview-banner-actions" aria-label="Preview viewport">
            <?php foreach (['desktop' => 'Desktop Preview', 'tablet' => 'Tablet Preview', 'mobile' => 'Mobile Preview'] as $key => $label): ?><a class="<?php echo $viewport === $key ? 'is-active' : ''; ?>" href="marketing_landing_page_preview.php?token=<?php echo urlencode($previewToken); ?>&viewport=<?php echo urlencode($key); ?>"><?php echo htmlspecialchars($label); ?></a><?php endforeach; ?>
            <a href="marketing_landing_page_edit.php?id=<?php echo (int) $page['id']; ?>">Back to Design Studio</a>
        </nav>
    </div>
    <div class="design-preview-workspace"><div class="design-preview-device"><?php echo $rendered; ?></div></div>
</div>
<script src="<?php echo htmlspecialchars(assetUrl('js/form-embed-host.js')); ?>" defer></script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
