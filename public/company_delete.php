<?php
/**
 * Delete Company Page
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

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Companies;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$companies = new Companies();
$companyId = (int) ($_GET['id'] ?? 0);

if (!$companyId) {
    header('Location: companies.php');
    exit;
}

$company = $companies->getById($companyId);
if (!$company) {
    header('Location: companies.php');
    exit;
}

$contactCount = $companies->getContactCount($companyId);
$dealCount = $companies->getDealCount($companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: companies.php?error=invalid_token');
        exit;
    }

    try {
        $companies->delete($companyId);
        header('Location: companies.php?success=deleted');
        exit;
    } catch (\Throwable $e) {
        error_log('Company delete failed for company ' . $companyId . ': ' . $e->getMessage());
        header('Location: companies.php?error=delete_failed');
        exit;
    }
}

$pageTitle = 'Delete Company - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Delete Company</h1>
    <p style="color: var(--charcoal-grey);">Are you sure you want to delete this company?</p>
</div>

<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <p style="font-weight: 500; margin-bottom: var(--spacing-sm);">Warning: This action cannot be undone.</p>
    <p style="font-size: 14px; margin: 0;">Linked contacts and deals will be kept, but they will be unlinked from this company.</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 640px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Company</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($company['name']); ?>
        </div>
    </div>

    <?php if (!empty($company['industry'])): ?>
        <div style="margin-bottom: var(--spacing-lg);">
            <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Industry</div>
            <div style="color: var(--midnight-black); font-weight: 500;">
                <?php echo htmlspecialchars($company['industry']); ?>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: flex; gap: var(--spacing-lg); flex-wrap: wrap; margin-bottom: var(--spacing-lg);">
        <div>
            <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Linked Contacts</div>
            <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;"><?php echo $contactCount; ?></div>
        </div>
        <div>
            <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Linked Deals</div>
            <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;"><?php echo $dealCount; ?></div>
        </div>
    </div>

    <form method="POST" action="" style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <a href="company_view.php?id=<?php echo $companyId; ?>" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
            Cancel
        </a>
        <button
            type="submit"
            style="background: #c33; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
        >
            Delete Company
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
