<?php
/**
 * Email Signatures Management Page
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
use CRM\Services\EmailSignatureTemplateCatalog;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$signaturesModule = new EmailSignatures();
$error = null;
$success = null;

if (isset($_GET['success']) && $_GET['success'] === 'updated') {
    $success = 'Signature updated successfully.';
} elseif (isset($_GET['success']) && $_GET['success'] === 'created') {
    $success = 'Signature created successfully.';
}

if (isset($_GET['error']) && $_GET['error'] === 'not_found') {
    $error = 'Signature not found or you do not have permission to edit it.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } else {
        try {
            if (isset($_POST['delete'])) {
                $signaturesModule->delete((int) ($_POST['signature_id'] ?? 0));
                $success = 'Signature deleted successfully.';
            } elseif (isset($_POST['set_default'])) {
                $signaturesModule->setDefault((int) ($_POST['signature_id'] ?? 0));
                $success = 'Default signature updated.';
            } elseif (isset($_POST['duplicate'])) {
                $signaturesModule->duplicate((int) ($_POST['signature_id'] ?? 0));
                $success = 'Signature duplicated. Your copy is ready to edit.';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$signatures = $signaturesModule->getUserSignatures();
$currentUser = Auth::user() ?: [];
$templateIdentity = [
    'first_name' => $currentUser['first_name'] ?? '',
    'last_name' => $currentUser['last_name'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'company' => $currentUser['active_workspace_name'] ?? 'Your company',
];
$signatureTemplates = EmailSignatureTemplateCatalog::all($templateIdentity);
$defaultSignatureName = '';
foreach ($signatures as $signatureRow) {
    if (!empty($signatureRow['is_default'])) {
        $defaultSignatureName = (string) ($signatureRow['name'] ?? '');
        break;
    }
}

$pageTitle = 'Email Signatures';
$signaturesGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_SIGNATURES);
ob_start();
?>

<?php echo PageGuideVideoUi::assets(); ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/email-signatures.css') . '?v=' . APP_VERSION . '-studio-v4'); ?>">

<main class="signature-page" aria-labelledby="signature-page-title">
    <header class="signature-page-header">
        <div>
            <h1 class="signature-page-title" id="signature-page-title">Email Signatures</h1>
            <p class="signature-page-subtitle"><?php echo empty($signatures) ? 'Create once, send consistently.' : 'Keep every sign-off polished, consistent, and ready to use.'; ?></p>
        </div>
        <?php if (!empty($signatures)): ?>
            <div class="signature-page-actions">
                <?php if ($signaturesGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_SIGNATURES, 'Watch the Email Signatures guide'); ?>
                <?php endif; ?>
                <a href="email_signature_create.php" class="signature-button signature-button-primary">
                    <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                    Create signature
                </a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($error): ?>
        <div class="signature-alert signature-alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="signature-alert signature-alert-success" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>

    <?php if (empty($signatures)): ?>
        <div class="signature-empty-layout">
            <section class="signature-empty-intro" aria-labelledby="signature-template-heading">
                <div class="signature-empty-actions">
                    <a href="email_signature_create.php" class="signature-button signature-button-primary">
                        <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                        Create signature
                    </a>
                    <?php if ($signaturesGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_SIGNATURES, 'Watch the Email Signatures guide'); ?>
                    <?php endif; ?>
                </div>

                <div class="signature-template-panel">
                    <h2 class="signature-section-title" id="signature-template-heading">Start with a template</h2>
                    <p class="signature-section-copy">Choose a layout to get started. You can customise everything.</p>
                    <div class="signature-template-grid">
                        <?php foreach ($signatureTemplates as $template): ?>
                            <a class="signature-template-card" data-template="<?php echo htmlspecialchars($template['key']); ?>" href="email_signature_create.php?template=<?php echo urlencode($template['key']); ?>">
                                <span class="signature-template-miniature" aria-hidden="true">
                                    <span class="signature-template-avatar"></span>
                                    <span class="signature-template-lines"><span></span><span></span><span></span></span>
                                </span>
                                <span class="signature-template-card-footer">
                                    <?php echo htmlspecialchars($template['name']); ?>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <p class="signature-template-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> You can edit colours, spacing, and content in the builder.</p>
                </div>
            </section>

            <section class="signature-email-preview" aria-labelledby="signature-preview-heading">
                <h2 class="signature-section-title" id="signature-preview-heading">Preview</h2>
                <p class="signature-section-copy">See how your signature will look in an email.</p>
                <div class="signature-mail-window" aria-label="Example email signature preview">
                    <div class="signature-mail-chrome" aria-hidden="true"><span></span><span></span><span></span></div>
                    <div class="signature-mail-meta"><span>To</span><strong>Alex Morgan</strong><span>Subject</span><strong>Project update</strong></div>
                    <div class="signature-mail-body">
                        <p>Hi Alex,<br>Just following up on our project update. Please let me know if you have any questions.</p>
                        <p>Thanks,</p>
                        <div class="signature-sample-block">
                            <div class="signature-sample-avatar" aria-hidden="true">AM</div>
                            <div>
                                <div class="signature-sample-name"><?php echo htmlspecialchars(trim((string) (($currentUser['first_name'] ?? 'Alex') . ' ' . ($currentUser['last_name'] ?? 'Morgan'))) ?: 'Alex Morgan'); ?></div>
                                <div class="signature-sample-details">Customer Success Lead<br><?php echo htmlspecialchars((string) ($currentUser['active_workspace_name'] ?? 'Clarity CRM')); ?><br>+254 700 000 000<br><?php echo htmlspecialchars((string) ($currentUser['email'] ?? 'alex@example.com')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="signature-how-it-works" aria-labelledby="signature-how-heading">
                <h2 class="signature-section-title" id="signature-how-heading">How it works</h2>
                <div class="signature-steps">
                    <div class="signature-step"><span class="signature-step-icon"><i class="fa-regular fa-rectangle-list" aria-hidden="true"></i></span><div><strong>1. Choose a starting point</strong><span>Pick the template that best suits how you communicate.</span></div></div>
                    <div class="signature-step"><span class="signature-step-icon"><i class="fa-regular fa-user" aria-hidden="true"></i></span><div><strong>2. Personalise your details</strong><span>Add your role, contact information, logo, and links.</span></div></div>
                    <div class="signature-step"><span class="signature-step-icon"><i class="fa-regular fa-circle-check" aria-hidden="true"></i></span><div><strong>3. Set your default</strong><span>Use it automatically when you start a new email.</span></div></div>
                </div>
            </section>
        </div>
    <?php else: ?>
        <div class="signature-library-tools">
            <div class="signature-library-summary"><strong><?php echo count($signatures); ?></strong> signature<?php echo count($signatures) === 1 ? '' : 's'; ?><?php echo $defaultSignatureName !== '' ? ' · Default: <strong>' . htmlspecialchars($defaultSignatureName) . '</strong>' : ''; ?></div>
            <label class="signature-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only">Search signatures</span>
                <input type="search" data-signature-search placeholder="Search signatures" autocomplete="off">
            </label>
        </div>

        <div class="signature-card-list">
            <?php foreach ($signatures as $signature): ?>
                <?php $searchText = trim((string) ($signature['name'] ?? '') . ' ' . (string) ($signature['content_text'] ?? '')); ?>
                <article class="signature-card<?php echo !empty($signature['is_default']) ? ' is-default' : ''; ?>" data-signature-card data-signature-search-text="<?php echo htmlspecialchars($searchText); ?>">
                    <header class="signature-card-header">
                        <div>
                            <div class="signature-card-title-row">
                                <h2 class="signature-card-title"><?php echo htmlspecialchars($signature['name']); ?></h2>
                                <?php if (!empty($signature['is_default'])): ?>
                                    <span class="signature-default-label"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Default signature</span>
                                <?php endif; ?>
                            </div>
                            <div class="signature-card-meta">
                                Created <?php echo date('M j, Y', strtotime($signature['created_at'])); ?>
                                <?php if ($signature['updated_at'] !== $signature['created_at']): ?>
                                    · Updated <?php echo date('M j, Y', strtotime($signature['updated_at'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="signature-card-actions">
                            <a href="email_signature_edit.php?id=<?php echo (int) $signature['id']; ?>" class="signature-button signature-button-secondary signature-button-small"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Edit</a>
                            <button type="button" class="signature-button signature-button-secondary signature-button-small" data-copy-signature><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy</button>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="signature_id" value="<?php echo (int) $signature['id']; ?>">
                                <button type="submit" name="duplicate" class="signature-button signature-button-secondary signature-button-small"><i class="fa-solid fa-code-branch" aria-hidden="true"></i> Duplicate</button>
                            </form>
                            <?php if (empty($signature['is_default'])): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="signature_id" value="<?php echo (int) $signature['id']; ?>">
                                    <button type="submit" name="set_default" class="signature-button signature-button-primary signature-button-small">Set default</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" data-signature-confirm="Delete '<?php echo htmlspecialchars($signature['name'], ENT_QUOTES); ?>'? This cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="signature_id" value="<?php echo (int) $signature['id']; ?>">
                                <button type="submit" name="delete" class="signature-button signature-button-danger signature-button-small" aria-label="Delete <?php echo htmlspecialchars($signature['name']); ?>"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </header>
                    <div class="signature-card-preview">
                        <div class="signature-card-preview-inner" data-signature-html><?php echo $signaturesModule->getSignatureHtml($signature); ?></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="signature-empty-filter" data-signature-filter-empty>No signatures match that search.</div>
    <?php endif; ?>
</main>

<div class="signature-toast" data-signature-toast role="status" aria-live="polite"></div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_SIGNATURES, 'How to use Email Signatures', $signaturesGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
$additionalScripts = [assetUrl('js/email-signatures-library.js') . '?v=' . APP_VERSION . '-studio-v1'];
include __DIR__ . '/../views/layouts/base.php';
?>
