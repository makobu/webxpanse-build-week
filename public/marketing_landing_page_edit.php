<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;
use CRM\Services\DesignTypographyCatalog;
use CRM\Services\LandingPageDesignService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}
$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, 'design');
if (!Authorization::can('marketing.write', $user)) {
    header('Location: ' . getBasePath() . '/marketing_landing_pages.php');
    exit;
}

$marketing = new Marketing();
$design = new LandingPageDesignService();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$options = $marketing->landingPageEditorOptionData();
$templates = $design->templates();
$submittedTemplateKey = trim((string) ($_POST['template_key'] ?? 'lead_capture'));
$submittedFormId = (int) ($_POST['form_id'] ?? 0);

if ($id <= 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token.');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Page name is required.');
        }
        $templateKey = $submittedTemplateKey;
        $template = null;
        foreach ($templates as $candidate) {
            if ((string) $candidate['key'] === $templateKey) {
                $template = $candidate;
                break;
            }
        }
        if (!$template) {
            throw new InvalidArgumentException('Choose an approved Design Studio template.');
        }
        $document = $template['document'];
        $formId = $submittedFormId;
        if ($formId > 0) {
            foreach ($document['blocks'] as &$block) {
                if (($block['type'] ?? '') === 'crm_form') {
                    $block['props']['form_id'] = $formId;
                }
            }
            unset($block);
        }
        if (!empty($options['booking_profiles'])) {
            foreach ($document['blocks'] as &$block) {
                if (($block['type'] ?? '') === 'booking') {
                    $block['props']['booking_url'] = (string) $options['booking_profiles'][0]['booking_url'];
                }
            }
            unset($block);
        }
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower($title);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
            $slug = trim($slug, '-');
        }
        $id = $marketing->createLandingPage([
            'title' => $title,
            'slug' => $slug,
            'form_id' => $formId,
            'conversion_goal' => $templateKey === 'demo_booking' ? 'booking' : ($templateKey === 'whatsapp_campaign' ? 'contact_request' : 'lead_capture'),
            'status' => 'draft',
            'design_document' => $document,
            'created_by' => (int) ($user['id'] ?? 0),
            'metadata_json' => ['design_studio_created' => true, 'template_key' => $templateKey],
        ]);
        header('Location: ' . getBasePath() . '/marketing_landing_page_edit.php?id=' . $id . '&created=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($id <= 0) {
    $pageTitle = 'New Design - ' . brandProductName();
    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio.css')); ?>">
    <div class="page-premium marketing-landing-edit-page design-studio-create-page">
        <header class="page-header design-create-header">
            <div>
                <p class="design-kicker">Design plugin</p>
                <h1>Start with a conversion-ready page</h1>
                <p>Choose an approved structure, connect the CRM action, then shape it in Design Studio.</p>
            </div>
            <div class="page-header-actions"><a class="btn-premium-secondary" href="marketing_landing_pages.php">Back to pages</a></div>
        </header>
        <?php if ($error !== ''): ?><div class="design-alert design-alert--error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" class="design-create-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <section class="design-create-basics" aria-labelledby="new-page-basics">
                <div><p class="design-step">Step 1</p><h2 id="new-page-basics">Name the page</h2><p>This stays internal until you publish a validated snapshot.</p></div>
                <div class="design-create-fields">
                    <label><span>Page name</span><input type="text" name="title" required maxlength="255" value="<?php echo htmlspecialchars((string) ($_POST['title'] ?? '')); ?>" placeholder="Q3 growth campaign"></label>
                    <label><span>URL slug <small>optional</small></span><input type="text" name="slug" maxlength="190" value="<?php echo htmlspecialchars((string) ($_POST['slug'] ?? '')); ?>" placeholder="q3-growth-campaign"></label>
                    <label><span>CRM form <small>optional</small></span><select name="form_id"><option value="0"<?php echo $submittedFormId === 0 ? ' selected' : ''; ?>>Connect later</option><?php foreach ($options['forms'] as $form): ?><option value="<?php echo (int) $form['id']; ?>"<?php echo $submittedFormId === (int) $form['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $form['name']); ?></option><?php endforeach; ?></select></label>
                </div>
            </section>
            <section class="design-template-picker" aria-labelledby="template-heading">
                <div class="design-template-picker__heading"><div><p class="design-step">Step 2</p><h2 id="template-heading">Choose a proven structure</h2></div><p>Every template uses the same safe block contract and can be fully rearranged.</p></div>
                <div class="design-template-grid">
                    <?php foreach ($templates as $index => $template): ?>
                        <label class="design-template-card">
                            <input type="radio" name="template_key" value="<?php echo htmlspecialchars((string) $template['key']); ?>" <?php echo $submittedTemplateKey === (string) $template['key'] ? 'checked' : ''; ?>>
                            <span class="design-template-card__preview design-template-card__preview--<?php echo htmlspecialchars((string) $template['key']); ?>" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                            <span class="design-template-card__body"><strong><?php echo htmlspecialchars((string) $template['name']); ?></strong><span><?php echo htmlspecialchars((string) $template['description']); ?></span><small><?php echo htmlspecialchars(str_replace('_', ' ', (string) $template['use_case'])); ?> · v<?php echo htmlspecialchars((string) $template['version']); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="design-create-actions"><span><i class="fas fa-shield-halved" aria-hidden="true"></i> Safe blocks, versioned drafts, and core-owned actions</span><button class="btn-premium-primary" type="submit">Create in Design Studio <i class="fas fa-arrow-right" aria-hidden="true"></i></button></div>
        </form>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/../views/layouts/base.php';
    exit;
}

$editor = $marketing->getLandingPageDesignEditorData($id);
$page = $editor['page'];
$manifest = $editor['manifest'];
$document = $editor['document'];
$validation = $editor['validation'];
$canManage = Authorization::can('marketing.manage', $user);
$mediaById = [];
foreach ($editor['options']['media_files'] as $media) {
    $mediaById[(int) $media['id']] = $media;
}
$formsById = [];
foreach ($editor['options']['forms'] as $form) {
    $formsById[(int) $form['id']] = $form;
}
$initialCanvas = $design->render($document, [
    'page' => $page,
    'media_by_id' => $mediaById,
    'forms_by_id' => $formsById,
    'form_uuid' => (string) ($page['form_uuid'] ?? ''),
    'mode' => 'editor',
]);
$previewUrl = 'marketing_landing_page_preview.php?token=' . rawurlencode((string) ($page['preview_token'] ?? '')) . '&viewport=desktop';
$isPublished = (string) ($page['publication_status'] ?? '') === 'published';
$publicUrl = $isPublished && !empty($page['public_token']) ? 'marketing_landing_public.php?token=' . rawurlencode((string) $page['public_token']) : '';
$pageTitle = 'Design Studio - ' . (string) $page['title'] . ' - ' . brandProductName();
$initialPayload = [
    'pageId' => $id,
    'page' => [
        'title' => (string) ($page['title'] ?? ''),
        'slug' => (string) ($page['slug'] ?? ''),
        'seo_title' => (string) ($page['seo_title'] ?? ''),
        'meta_description' => (string) ($page['meta_description'] ?? ''),
        'form_id' => (int) ($page['form_id'] ?? 0),
        'campaign_id' => (int) ($page['campaign_id'] ?? 0),
        'audience_segment_id' => (int) ($page['audience_segment_id'] ?? 0),
        'conversion_goal' => (string) ($page['conversion_goal'] ?? 'lead_capture'),
        'conversion_goal_id' => (int) ($page['conversion_goal_id'] ?? 0),
        'status' => (string) ($page['status'] ?? 'draft'),
    ],
    'document' => $document,
    'revision' => (int) $editor['revision'],
    'validation' => $validation,
    'manifest' => $manifest,
    'templates' => $editor['templates'],
    'options' => $editor['options'],
    'typography' => DesignTypographyCatalog::webEditorConfig(),
    'versions' => $editor['versions'],
    'csrfToken' => Security::getCsrfToken(),
    'apiUrl' => apiUrl('marketing/landing_page_design.php'),
    'mediaUploadUrl' => apiUrl('marketing/landing_page_media.php'),
    'mediaLibraryUrl' => publicUrl('marketing_assets.php#media-tools'),
    'previewUrl' => $previewUrl,
    'publicUrl' => $publicUrl,
    'canManage' => $canManage,
    'isPublished' => $isPublished,
];

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio-public.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio-public.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio.css')); ?>">
<div class="page-premium marketing-landing-edit-page design-studio-page" data-design-studio>
    <header class="page-header page-header-actions design-studio-toolbar">
        <div class="design-toolbar__identity">
            <a class="design-icon-button design-back" href="marketing_landing_pages.php" aria-label="Back to pages"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
            <div><p>Design Studio</p><h1 id="designPageTitle"><?php echo htmlspecialchars((string) $page['title']); ?></h1></div>
        </div>
        <div class="design-toolbar__status" role="status" aria-live="polite"><span class="design-save-dot"></span><span id="designSaveStatus">All changes saved</span><small>Revision <?php echo (int) $editor['revision']; ?></small></div>
        <div class="design-toolbar__history" aria-label="Edit history">
            <button class="design-icon-button" type="button" data-action="undo" aria-label="Undo" disabled><i class="fas fa-rotate-left"></i></button>
            <button class="design-icon-button" type="button" data-action="redo" aria-label="Redo" disabled><i class="fas fa-rotate-right"></i></button>
        </div>
        <div class="design-viewport-toggle" role="group" aria-label="Preview size">
            <button type="button" class="is-active" data-viewport="desktop" aria-label="Desktop"><i class="fas fa-desktop"></i><span>Desktop</span></button>
            <button type="button" data-viewport="tablet" aria-label="Tablet"><i class="fas fa-tablet-screen-button"></i><span>Tablet</span></button>
            <button type="button" data-viewport="mobile" aria-label="Mobile"><i class="fas fa-mobile-screen-button"></i><span>Mobile</span></button>
        </div>
        <div class="design-toolbar__actions">
            <button class="design-button design-button--secondary" type="button" data-action="templates" aria-label="Templates"><i class="fas fa-table-cells-large"></i><span>Templates</span></button>
            <a class="design-button design-button--secondary" href="<?php echo htmlspecialchars($previewUrl); ?>" target="_blank" rel="noopener" aria-label="Preview page"><i class="fas fa-play"></i><span>Preview</span></a>
            <?php if ($canManage): ?><button class="design-button design-button--primary" type="button" data-action="publish" aria-label="<?php echo $isPublished ? 'Publish page update' : 'Publish page'; ?>"><i class="fas fa-rocket"></i><span><?php echo $isPublished ? 'Publish update' : 'Publish'; ?></span></button><?php endif; ?>
            <button class="design-icon-button design-more" type="button" data-action="more" aria-label="Page settings"><i class="fas fa-ellipsis"></i></button>
        </div>
    </header>

    <div class="design-studio-shell">
        <aside class="design-library" aria-label="Block library">
            <div class="design-panel-heading"><div><p>Build</p><h2>Blocks</h2></div><button class="design-icon-button design-library-close" type="button" data-action="close-library" aria-label="Close blocks"><i class="fas fa-xmark"></i></button></div>
            <label class="design-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><span class="sr-only">Search blocks</span><input type="search" id="designBlockSearch" placeholder="Search blocks"></label>
            <div class="design-category-tabs" role="tablist" aria-label="Block categories"><button type="button" class="is-active" data-category="all">All</button><button type="button" data-category="Layout">Layout</button><button type="button" data-category="CRM">CRM</button><button type="button" data-category="Trust">Trust</button></div>
            <div class="design-block-list" id="designBlockList">
                <?php foreach ($manifest as $block): ?>
                    <button type="button" class="design-block-tile" draggable="true" data-add-block="<?php echo htmlspecialchars((string) $block['type']); ?>" data-category="<?php echo htmlspecialchars((string) $block['category']); ?>"><span class="design-block-icon"><i class="fas <?php echo htmlspecialchars((string) $block['icon']); ?>" aria-hidden="true"></i></span><span><strong><?php echo htmlspecialchars((string) $block['label']); ?></strong><small><?php echo htmlspecialchars((string) $block['category']); ?></small></span><i class="fas fa-plus" aria-hidden="true"></i></button>
                <?php endforeach; ?>
            </div>
            <div class="design-library-note"><i class="fas fa-shield-halved"></i><span><strong>Safe by design</strong> Blocks use core-owned CRM actions.</span></div>
        </aside>

        <main class="design-stage" aria-label="Page canvas">
            <div class="design-stage__bar"><div><span class="design-validity <?php echo !empty($validation['valid']) ? 'is-valid' : 'has-issues'; ?>" id="designValidity"><i class="fas <?php echo !empty($validation['valid']) ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i><span><?php echo !empty($validation['valid']) ? 'Page valid' : count((array) ($validation['errors'] ?? [])) . ' issue(s)'; ?></span></span><button type="button" data-action="validation">Review</button></div><div class="design-zoom"><button type="button" data-zoom="out" aria-label="Zoom out">−</button><span id="designZoomValue">90%</span><button type="button" data-zoom="in" aria-label="Zoom in">+</button></div></div>
            <div class="design-canvas-viewport is-desktop" id="designCanvasViewport">
                <div class="design-canvas" id="designCanvas" data-empty-label="Add a block to begin"><?php echo $initialCanvas; ?></div>
            </div>
            <div class="design-drop-indicator" id="designDropIndicator" hidden>Drop block here</div>
        </main>

        <aside class="design-inspector" aria-label="Properties inspector">
            <div class="design-panel-heading"><div><p>Selected block</p><h2 id="designInspectorTitle">Page</h2></div><button class="design-icon-button" type="button" data-action="close-inspector" aria-label="Close inspector"><i class="fas fa-xmark"></i></button></div>
            <div class="design-inspector-tabs" role="tablist"><button type="button" class="is-active" data-inspector-tab="content">Content</button><button type="button" data-inspector-tab="style">Style</button><button type="button" data-inspector-tab="visibility">Visibility</button></div>
            <div class="design-inspector-body" id="designInspectorBody"></div>
            <div class="design-inspector-footer" id="designInspectorFooter"><span><kbd>↑</kbd><kbd>↓</kbd> Move</span><button type="button" data-action="duplicate"><i class="fas fa-copy"></i> Duplicate</button><button type="button" data-action="delete"><i class="fas fa-trash"></i> Delete</button></div>
        </aside>
    </div>

    <nav class="design-mobile-nav" aria-label="Design tools"><button type="button" class="is-active" data-mobile-panel="blocks"><i class="fas fa-shapes"></i><span>Blocks</span></button><button type="button" data-mobile-panel="layers"><i class="fas fa-layer-group"></i><span>Layers</span></button><button type="button" data-mobile-panel="page"><i class="fas fa-file-lines"></i><span>Page</span></button><button type="button" data-mobile-panel="style"><i class="fas fa-palette"></i><span>Style</span></button></nav>

    <dialog class="design-dialog design-template-dialog" id="designTemplateDialog"><form method="dialog" class="design-dialog__shell"><header><div><p>Approved manifests</p><h2>Choose a template</h2><span>Applying a template replaces the current blocks and can be undone.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body"><div class="design-template-grid" id="designTemplateList"></div></div></form></dialog>
    <dialog class="design-dialog" id="designSettingsDialog"><form method="dialog" class="design-dialog__shell"><header><div><p>Page contract</p><h2>Page settings</h2><span>Metadata and CRM connections shared by every block.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="designPageSettings"></div></form></dialog>
    <dialog class="design-dialog" id="designValidationDialog"><form method="dialog" class="design-dialog__shell"><header><div><p>Preflight</p><h2>Page validation</h2><span>Errors block publishing; warnings identify quality improvements.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="designValidationDetails"></div></form></dialog>
    <dialog class="design-dialog" id="designHistoryDialog"><form method="dialog" class="design-dialog__shell"><header><div><p>Immutable checkpoints</p><h2>Version history</h2><span>Restore any checkpoint as the newest editable draft.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="designVersionList"></div></form></dialog>
    <div class="design-toast" id="designToast" role="status" aria-live="polite" hidden></div>
    <input type="file" id="designMediaUpload" accept="image/*,video/*" hidden>
</div>
<script type="application/json" id="designStudioInitial"><?php echo json_encode($initialPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
<script src="<?php echo htmlspecialchars(assetUrl('js/form-embed-host.js') . '?v=' . (int) filemtime(__DIR__ . '/assets/js/form-embed-host.js')); ?>" defer></script>
<script src="<?php echo htmlspecialchars(assetUrl('js/design-studio.js') . '?v=' . (int) filemtime(__DIR__ . '/assets/js/design-studio.js')); ?>" defer></script>
<?php
$content = ob_get_clean();
$bodyClass = trim((string) (($bodyClass ?? '') . ' design-studio-immersive'));
include __DIR__ . '/../views/layouts/base.php';
