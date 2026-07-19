<?php
/** Form Studio - visual, schema-driven form editor. */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Forms;
use CRM\Security;
use CRM\Session;
use CRM\Services\DesignTypographyCatalog;
use CRM\Services\FormDesignService;
use CRM\Services\FormStudioService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: ' . Auth::loginUrl(null, Auth::currentAuthState() === 'expired'));
    exit;
}
$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, 'design');
if (!Authorization::can('marketing.write', $user)) {
    header('Location: ' . publicUrl('forms.php'));
    exit;
}

$forms = new Forms();
$studio = new FormStudioService();
$design = new FormDesignService();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$uuid = trim((string) ($_GET['uuid'] ?? $_POST['uuid'] ?? ''));
if ($id <= 0 && $uuid !== '') {
    $existing = $forms->getByUuid($uuid);
    $id = (int) ($existing['id'] ?? 0);
}
$error = '';
$templates = $design->templates();

if ($id <= 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token.');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Form name is required.');
        }
        $id = $studio->create($name, (string) ($_POST['template_key'] ?? 'contact'), (int) ($user['id'] ?? 0) ?: null);
        $created = $forms->getById($id);
        header('Location: ' . publicUrl('form_edit.php?uuid=' . rawurlencode((string) ($created['uuid'] ?? '')) . '&created=1'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($id <= 0) {
    $pageTitle = 'New Form - ' . brandProductName();
    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/form-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/form-studio.css')); ?>">
    <div class="page-premium design-studio-create-page form-studio-create-page">
        <header class="page-header design-create-header">
            <div>
                <h1>Start with a form that is ready to convert</h1>
                <p>Choose a proven structure, then shape every field, step, rule, and CRM mapping visually.</p>
            </div>
            <div class="page-header-actions"><a class="btn-premium-secondary" href="forms.php">Back to forms</a></div>
        </header>
        <?php if ($error !== ''): ?><div class="design-alert design-alert--error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" class="design-create-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <section class="design-create-basics" aria-labelledby="new-form-basics">
                <div><p class="design-step">Step 1</p><h2 id="new-form-basics">Name the form</h2><p>This name can be changed directly inside Form Studio.</p></div>
                <div class="design-create-fields"><label><span>Form name</span><input type="text" name="name" required maxlength="255" value="<?php echo htmlspecialchars((string) ($_POST['name'] ?? '')); ?>" placeholder="Qualified lead form"></label></div>
            </section>
            <section class="design-template-picker" aria-labelledby="form-template-heading">
                <div class="design-template-picker__heading"><div><p class="design-step">Step 2</p><h2 id="form-template-heading">Choose a starting structure</h2></div><p>Templates are safe, versioned manifests. Every field and step remains editable.</p></div>
                <div class="design-template-grid">
                    <?php foreach ($templates as $index => $template): ?>
                        <label class="design-template-card">
                            <input type="radio" name="template_key" value="<?php echo htmlspecialchars((string) $template['key']); ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                            <span class="design-template-card__preview" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                            <span class="design-template-card__body"><strong><?php echo htmlspecialchars((string) $template['name']); ?></strong><span><?php echo htmlspecialchars((string) $template['description']); ?></span><small><?php echo htmlspecialchars(str_replace('_', ' ', (string) $template['use_case'])); ?> · v<?php echo htmlspecialchars((string) $template['version']); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="design-create-actions"><span><i class="fas fa-shield-halved" aria-hidden="true"></i> Safe fields, autosaved drafts, and CRM-owned submissions</span><button class="btn-premium-primary" type="submit">Create in Form Studio <i class="fas fa-arrow-right" aria-hidden="true"></i></button></div>
        </form>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/../views/layouts/base.php';
    exit;
}

$editor = $studio->editorData($id);
$form = $editor['form'];
$document = $editor['document'];
$validation = $editor['validation'];
$manifestByCategory = [];
foreach ($editor['manifest'] as $fieldType) {
    $manifestByCategory[(string) ($fieldType['category'] ?? 'Fields')][] = $fieldType;
}
$uuid = (string) ($form['uuid'] ?? '');
$canManage = Authorization::can('marketing.manage', $user);
$activeStep = (string) ($document['steps'][0]['id'] ?? 'step_1');
$settings = $design->legacySettings($document);
$basePath = getBasePath();
$logoUrl = !empty($settings['logo_path']) ? $basePath . '/form_asset.php?form_uuid=' . rawurlencode($uuid) . '&type=logo&v=' . rawurlencode((string) $settings['logo_path']) : '';
$headerUrl = !empty($settings['header_image_path']) ? $basePath . '/form_asset.php?form_uuid=' . rawurlencode($uuid) . '&type=header_image&v=' . rawurlencode((string) $settings['header_image_path']) : '';
$hasFile = (bool) array_filter($document['fields'], static fn(array $field): bool => ($field['type'] ?? '') === 'file');
$initialCanvas = $design->render($document, [
    'form' => $form,
    'mode' => 'editor',
    'active_step' => $activeStep,
    'logo_url' => $logoUrl,
    'header_url' => $headerUrl,
    'has_file' => $hasFile,
]);
$previewUrl = publicUrl('form.php?uuid=' . rawurlencode($uuid) . '&preview_token=' . rawurlencode((string) $editor['preview_token']));
$publicUrl = publicUrl('form.php?uuid=' . rawurlencode($uuid));
$isPublished = !empty($editor['is_published']);
$pageTitle = 'Form Studio - ' . (string) ($form['name'] ?? 'Form') . ' - ' . brandProductName();
$initialPayload = [
    'formId' => $id,
    'uuid' => $uuid,
    'document' => $document,
    'revision' => (int) $editor['revision'],
    'validation' => $validation,
    'manifest' => $editor['manifest'],
    'templates' => $editor['templates'],
    'typography' => DesignTypographyCatalog::webEditorConfig(),
    'versions' => $editor['versions'],
    'activeStep' => $activeStep,
    'csrfToken' => Security::getCsrfToken(),
    'apiUrl' => apiUrl('forms/design.php'),
    'assetUploadUrl' => publicUrl('form_asset_upload.php'),
    'assetBaseUrl' => publicUrl('form_asset.php?form_uuid=' . rawurlencode($uuid)),
    'previewUrl' => $previewUrl,
    'publicUrl' => $publicUrl,
    'canManage' => $canManage,
    'isPublished' => $isPublished,
];

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/form-studio-public.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/form-studio-public.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/design-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/design-studio.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/form-studio.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/css/form-studio.css')); ?>">
<div class="page-premium design-studio-page form-studio-page" data-form-studio>
    <header class="page-header page-header-actions design-studio-toolbar">
        <div class="design-toolbar__identity">
            <a class="design-icon-button design-back" href="forms.php" aria-label="Back to forms"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
            <div><p>Form Studio</p><h1 id="formStudioTitle"><?php echo htmlspecialchars((string) $form['name']); ?></h1></div>
        </div>
        <div class="design-toolbar__status" role="status" aria-live="polite"><span class="design-save-dot"></span><span id="formSaveStatus">All changes saved</span><small>Revision <?php echo (int) $editor['revision']; ?></small></div>
        <div class="design-toolbar__history" aria-label="Edit history">
            <button class="design-icon-button" type="button" data-action="undo" aria-label="Undo" disabled><i class="fas fa-rotate-left"></i></button>
            <button class="design-icon-button" type="button" data-action="redo" aria-label="Redo" disabled><i class="fas fa-rotate-right"></i></button>
        </div>
        <div class="design-viewport-toggle" role="group" aria-label="Preview size">
            <button type="button" class="is-active" data-viewport="desktop" aria-label="Desktop"><i class="fas fa-desktop"></i><span>Desktop</span></button>
            <button type="button" data-viewport="mobile" aria-label="Mobile"><i class="fas fa-mobile-screen-button"></i><span>Mobile</span></button>
        </div>
        <div class="design-toolbar__actions">
            <button class="design-button design-button--secondary" type="button" data-action="templates" aria-label="Templates"><i class="fas fa-table-cells-large"></i><span>Templates</span></button>
            <a class="design-button design-button--secondary" href="<?php echo htmlspecialchars($previewUrl); ?>" target="_blank" rel="noopener" aria-label="Preview form"><i class="fas fa-eye"></i><span>Preview</span></a>
            <?php if ($canManage): ?><button class="design-button design-button--primary" type="button" data-action="publish" aria-label="<?php echo $isPublished ? 'Publish form update' : 'Publish form'; ?>"><i class="fas fa-arrow-up-from-bracket"></i><span><?php echo $isPublished ? 'Publish update' : 'Publish'; ?></span></button><?php endif; ?>
            <button class="design-icon-button design-more" type="button" data-action="more" aria-label="Form settings"><i class="fas fa-ellipsis"></i></button>
        </div>
    </header>

    <div class="design-studio-shell">
        <aside class="design-library" aria-label="Form tools">
            <div class="form-studio-library-tabs" role="tablist"><button type="button" class="is-active" data-library-tab="fields">Fields</button><button type="button" data-library-tab="steps">Steps</button><button type="button" data-library-tab="templates">Templates</button></div>
            <section class="form-studio-library-panel" data-library-panel="fields">
                <label class="design-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><span class="sr-only">Search fields</span><input type="search" id="formFieldSearch" placeholder="Search fields"></label>
                <div id="formFieldLibrary">
                    <?php foreach ($manifestByCategory as $category => $fieldTypes): ?>
                        <section class="form-studio-category"><h3><?php echo htmlspecialchars($category); ?></h3><div class="form-field-list">
                            <?php foreach ($fieldTypes as $fieldType): ?>
                                <button type="button" class="design-block-tile form-field-tile" draggable="true" data-add-field="<?php echo htmlspecialchars((string) $fieldType['type']); ?>" data-search-label="<?php echo htmlspecialchars(strtolower((string) $fieldType['label'])); ?>"><span class="design-block-icon"><i class="fas <?php echo htmlspecialchars((string) $fieldType['icon']); ?>" aria-hidden="true"></i></span><span><strong><?php echo htmlspecialchars((string) $fieldType['label']); ?></strong></span><i class="fas fa-plus" aria-hidden="true"></i></button>
                            <?php endforeach; ?>
                        </div></section>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="form-studio-library-panel" data-library-panel="steps" hidden><div class="form-studio-library-heading"><h2>Form steps</h2><button type="button" data-action="add-step"><i class="fas fa-plus"></i> Add step</button></div><div class="form-step-list" id="formStepList"></div></section>
            <section class="form-studio-library-panel" data-library-panel="templates" hidden><div class="form-studio-library-heading"><h2>Starting structures</h2></div><div class="form-template-mini-list" id="formTemplateMiniList"></div></section>
        </aside>

        <main class="design-stage" aria-label="Form canvas">
            <div class="design-stage__bar"><div><span class="design-validity <?php echo !empty($validation['valid']) ? 'is-valid' : 'has-issues'; ?>" id="formValidity"><i class="fas <?php echo !empty($validation['valid']) ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i><span><?php echo !empty($validation['valid']) ? 'Form valid' : count((array) ($validation['errors'] ?? [])) . ' issue(s)'; ?></span></span><button type="button" data-action="validation">Review</button></div><div class="design-zoom"><button type="button" data-zoom="out" aria-label="Zoom out">−</button><span id="formZoomValue">100%</span><button type="button" data-zoom="in" aria-label="Zoom in">+</button></div></div>
            <div class="design-canvas-viewport is-desktop" id="formCanvasViewport"><div class="design-canvas" id="formCanvas" data-empty-label="Add a field to begin"><?php echo $initialCanvas; ?></div></div>
            <div class="design-drop-indicator" id="formDropIndicator" hidden>Drop field here</div>
        </main>

        <aside class="design-inspector" aria-label="Field inspector">
            <div class="design-panel-heading"><div><p>Selected item</p><h2 id="formInspectorTitle">Form</h2></div><button class="design-icon-button" type="button" data-action="close-inspector" aria-label="Close inspector"><i class="fas fa-xmark"></i></button></div>
            <div class="design-inspector-tabs" role="tablist"><button type="button" class="is-active" data-inspector-tab="field">Field</button><button type="button" data-inspector-tab="design">Design</button><button type="button" data-inspector-tab="logic">Logic</button></div>
            <div class="design-inspector-body" id="formInspectorBody"></div>
            <div class="design-inspector-footer" id="formInspectorFooter"><span><kbd>Alt</kbd> <kbd>↑</kbd><kbd>↓</kbd> Move</span><button type="button" data-action="duplicate"><i class="fas fa-copy"></i> Duplicate</button><button type="button" data-action="delete"><i class="fas fa-trash"></i> Delete</button></div>
        </aside>
    </div>

    <nav class="design-mobile-nav" aria-label="Form tools"><button type="button" class="is-active" data-mobile-panel="blocks"><i class="fas fa-list"></i><span>Fields</span></button><button type="button" data-mobile-panel="layers"><i class="fas fa-table-columns"></i><span>Steps</span></button><button type="button" data-mobile-panel="page"><i class="fas fa-pen-to-square"></i><span>Field</span></button><button type="button" data-mobile-panel="style"><i class="fas fa-palette"></i><span>Design</span></button></nav>

    <dialog class="design-dialog form-template-dialog" id="formTemplateDialog"><form method="dialog" class="design-dialog__shell"><header><div><h2>Choose a form template</h2><span>Applying a template replaces the current fields and steps. You can undo it.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body"><div class="design-template-grid" id="formTemplateList"></div></div></form></dialog>
    <dialog class="design-dialog" id="formSettingsDialog"><form method="dialog" class="design-dialog__shell"><header><div><h2>Form settings</h2><span>Success behavior, branding, assets, and privacy.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="formSettingsBody"></div></form></dialog>
    <dialog class="design-dialog" id="formValidationDialog"><form method="dialog" class="design-dialog__shell"><header><div><h2>Form validation</h2><span>Errors block publishing; warnings improve completion and follow-up.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="formValidationDetails"></div></form></dialog>
    <dialog class="design-dialog" id="formHistoryDialog"><form method="dialog" class="design-dialog__shell"><header><div><h2>Version history</h2><span>Restore a checkpoint as the newest editable draft.</span></div><button class="design-icon-button" value="cancel" aria-label="Close"><i class="fas fa-xmark"></i></button></header><div class="design-dialog__body" id="formVersionList"></div></form></dialog>
    <input type="file" id="formLogoUpload" accept="image/*" hidden>
    <input type="file" id="formHeaderUpload" accept="image/*" hidden>
    <div class="design-toast" id="formToast" role="status" aria-live="polite" hidden></div>
</div>
<script type="application/json" id="formStudioInitial"><?php echo json_encode($initialPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
<script src="<?php echo htmlspecialchars(assetUrl('js/form-studio.js') . '?v=' . (int) filemtime(__DIR__ . '/assets/js/form-studio.js')); ?>" defer></script>
<?php
$content = ob_get_clean();
$bodyClass = trim((string) (($bodyClass ?? '') . ' design-studio-immersive'));
include __DIR__ . '/../views/layouts/base.php';
