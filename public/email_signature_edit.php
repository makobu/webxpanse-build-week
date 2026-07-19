<?php
/**
 * Edit Email Signature Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Modules\EmailSignatures;
use CRM\Security;
use CRM\Services\DesignTypographyCatalog;
use CRM\Services\EmailSignatureTemplateCatalog;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
$basePath = getBasePath();

if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$signaturesModule = new EmailSignatures();
$signatureId = (int) ($_GET['id'] ?? 0);
$signature = $signatureId > 0 ? $signaturesModule->getById($signatureId) : null;
if (!$signature) {
    header('Location: ' . $basePath . '/email_signatures.php?error=not_found');
    exit;
}

$sigSettings = is_array($signature['settings'] ?? null) ? $signature['settings'] : [];
$currentUser = Auth::user() ?: [];
$templateIdentity = [
    'first_name' => $currentUser['first_name'] ?? '',
    'last_name' => $currentUser['last_name'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'company' => $currentUser['active_workspace_name'] ?? 'Your company',
];
$signatureTemplates = EmailSignatureTemplateCatalog::all($templateIdentity);
$selectedTemplateKey = EmailSignatureTemplateCatalog::normalizeKey((string) ($_POST['template_style'] ?? $sigSettings['template_style'] ?? 'minimal'));
$selectedTemplate = $signatureTemplates[$selectedTemplateKey];
$error = null;
$builderSuccess = isset($_GET['success']) && $_GET['success'] === 'created'
    ? 'Signature created. You can now add a company logo or inline images.'
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $contentHtml = (string) ($_POST['content_html'] ?? '');
            $isDefault = (string) ($_POST['is_default'] ?? '0') === '1';
            $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['accent_color'] ?? '')) ? (string) $_POST['accent_color'] : $selectedTemplate['accent_color'];
            $textColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['text_color'] ?? '')) ? (string) $_POST['text_color'] : $selectedTemplate['text_color'];
            $settings = $sigSettings;
            $settings['accent_color'] = $accentColor;
            $settings['text_color'] = $textColor;
            $settings['template_style'] = $selectedTemplateKey;
            $settings['font_family'] = DesignTypographyCatalog::normalizeEmailFont((string) ($_POST['font_family'] ?? $sigSettings['font_family'] ?? 'arial'));
            $settings['font_size_preset'] = DesignTypographyCatalog::normalizeEmailSize((string) ($_POST['font_size_preset'] ?? $sigSettings['font_size_preset'] ?? 'standard'));
            $logoPath = trim((string) ($_POST['logo_path'] ?? $sigSettings['logo_path'] ?? ''));
            if ($logoPath === '') {
                unset($settings['logo_path']);
            } else {
                $settings['logo_path'] = $logoPath;
            }

            if ($name === '') {
                $error = 'Signature name is required.';
            } elseif (!EmailSignatureTemplateCatalog::hasMeaningfulContent($contentHtml)) {
                $error = 'Add some signature content before saving.';
            } else {
                $signaturesModule->update($signatureId, [
                    'name' => $name,
                    'content_html' => $contentHtml,
                    'content_text' => trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $contentHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'is_default' => $isDefault,
                    'settings' => $settings,
                ]);

                header('Location: ' . $basePath . '/email_signatures.php?success=updated');
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$initialName = (string) ($_POST['name'] ?? $signature['name']);
$initialContent = (string) ($_POST['content_html'] ?? $signature['content_html']);
$initialAccent = (string) ($_POST['accent_color'] ?? $sigSettings['accent_color'] ?? $selectedTemplate['accent_color']);
$initialText = (string) ($_POST['text_color'] ?? $sigSettings['text_color'] ?? $selectedTemplate['text_color']);
$initialFont = DesignTypographyCatalog::normalizeEmailFont((string) ($_POST['font_family'] ?? $sigSettings['font_family'] ?? 'arial'));
$initialFontSize = DesignTypographyCatalog::normalizeEmailSize((string) ($_POST['font_size_preset'] ?? $sigSettings['font_size_preset'] ?? 'standard'));
$initialIsDefault = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['is_default'] ?? '0') === '1'
    : !empty($signature['is_default']);
$logoPath = (string) ($_POST['logo_path'] ?? $sigSettings['logo_path'] ?? '');
if ($logoPath === '') {
    unset($sigSettings['logo_path']);
} else {
    $sigSettings['logo_path'] = $logoPath;
}
$logoUrl = $logoPath !== ''
    ? $basePath . '/signature_asset.php?signature_id=' . $signatureId . '&type=logo'
    : null;

$builderMode = 'edit';
$builderTitle = 'Edit email signature';
$builderSubtitle = 'Fine-tune the content, brand styling, and inbox appearance.';
$submitLabel = 'Save changes';
$builderConfig = [
    'mode' => $builderMode,
    'signatureId' => $signatureId,
    'selectedTemplate' => $selectedTemplateKey,
    'templates' => $signatureTemplates,
    'initialName' => $initialName,
    'initialContent' => $initialContent,
    'accentColor' => $initialAccent,
    'textColor' => $initialText,
    'fontFamily' => $initialFont,
    'fontSizePreset' => $initialFontSize,
    'fontOptions' => DesignTypographyCatalog::emailFonts(),
    'fontSizeOptions' => DesignTypographyCatalog::emailSizes(),
    'isDefault' => $initialIsDefault,
    'hadPostError' => $error !== null,
    'csrfToken' => Security::getCsrfToken(),
    'assetUploadUrl' => $basePath . '/signature_asset_upload.php',
];

$pageTitle = 'Edit Email Signature';
$bodyClass = 'signature-builder-body';
$requiresQuillEditor = true;
ob_start();
include __DIR__ . '/../views/email_signatures/builder.php';
$content = ob_get_clean();
$additionalScripts = [assetUrl('js/email-signature-builder.js') . '?v=' . APP_VERSION . '-studio-v3'];
include __DIR__ . '/../views/layouts/base.php';
?>
