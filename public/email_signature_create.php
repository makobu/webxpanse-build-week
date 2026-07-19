<?php
/**
 * Create Email Signature Page
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

if (!empty($_ENV['DEBUG_SIGNATURE_SESSION'])) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $cookieName = session_name();
    $entry = date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI')
        . ' | session_id=' . (session_id() ?: '(empty)')
        . ' | user_id=' . (Auth::userId() ?? 'null')
        . ' | cookie_sent=' . (isset($_COOKIE[$cookieName]) ? 'yes' : 'no')
        . ' | HTTPS=' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'on' : 'off') . "\n";
    @file_put_contents($logDir . '/signature_session_debug.log', $entry, FILE_APPEND);
}

if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$signaturesModule = new EmailSignatures();
$currentUser = Auth::user() ?: [];
$templateIdentity = [
    'first_name' => $currentUser['first_name'] ?? '',
    'last_name' => $currentUser['last_name'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'company' => $currentUser['active_workspace_name'] ?? 'Your company',
];
$signatureTemplates = EmailSignatureTemplateCatalog::all($templateIdentity);
$selectedTemplateKey = EmailSignatureTemplateCatalog::normalizeKey((string) ($_POST['template_style'] ?? $_GET['template'] ?? 'professional'));
$selectedTemplate = $signatureTemplates[$selectedTemplateKey];
$hasExistingSignatures = !empty($signaturesModule->getUserSignatures());
$error = null;
$builderSuccess = null;

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
            $fontFamily = DesignTypographyCatalog::normalizeEmailFont((string) ($_POST['font_family'] ?? 'arial'));
            $fontSizePreset = DesignTypographyCatalog::normalizeEmailSize((string) ($_POST['font_size_preset'] ?? 'standard'));

            if ($name === '') {
                $error = 'Signature name is required.';
            } elseif (!EmailSignatureTemplateCatalog::hasMeaningfulContent($contentHtml)) {
                $error = 'Add some signature content before saving.';
            } else {
                $signatureId = $signaturesModule->create([
                    'name' => $name,
                    'content_html' => $contentHtml,
                    'content_text' => trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $contentHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'is_default' => $isDefault,
                    'settings' => [
                        'accent_color' => $accentColor,
                        'text_color' => $textColor,
                        'template_style' => $selectedTemplateKey,
                        'font_family' => $fontFamily,
                        'font_size_preset' => $fontSizePreset,
                    ],
                ]);

                header('Location: ' . $basePath . '/email_signature_edit.php?id=' . $signatureId . '&success=created');
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$initialName = (string) ($_POST['name'] ?? ($selectedTemplate['name'] . ' Signature'));
$initialContent = (string) ($_POST['content_html'] ?? $selectedTemplate['content_html']);
$initialAccent = (string) ($_POST['accent_color'] ?? $selectedTemplate['accent_color']);
$initialText = (string) ($_POST['text_color'] ?? $selectedTemplate['text_color']);
$initialFont = DesignTypographyCatalog::normalizeEmailFont((string) ($_POST['font_family'] ?? 'arial'));
$initialFontSize = DesignTypographyCatalog::normalizeEmailSize((string) ($_POST['font_size_preset'] ?? 'standard'));
$initialIsDefault = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['is_default'] ?? '0') === '1'
    : !$hasExistingSignatures;

$builderMode = 'create';
$builderTitle = 'Create email signature';
$builderSubtitle = 'Build a signature that looks polished in every message.';
$submitLabel = 'Save signature';
$sigSettings = [];
$logoUrl = null;
$signatureId = 0;
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

$pageTitle = 'Create Email Signature';
$bodyClass = 'signature-builder-body';
$requiresQuillEditor = true;
ob_start();
include __DIR__ . '/../views/email_signatures/builder.php';
$content = ob_get_clean();
$additionalScripts = [assetUrl('js/email-signature-builder.js') . '?v=' . APP_VERSION . '-studio-v3'];
include __DIR__ . '/../views/layouts/base.php';
?>
