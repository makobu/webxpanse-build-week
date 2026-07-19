<?php
/**
 * Delete Deal Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Modules\Deals;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$dealsModule = new Deals();
$dealId = (int) ($_GET['id'] ?? 0);

if (!$dealId) {
    header('Location: deals.php');
    exit;
}

$deal = $dealsModule->getById($dealId);

if (!$deal) {
    header('Location: deals.php');
    exit;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: deals.php?error=invalid_token');
        exit;
    }
    
    try {
        $dealsModule->delete($dealId);
        header('Location: deals.php?success=deleted');
        exit;
    } catch (\Exception $e) {
        header('Location: deals.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle = 'Delete Deal - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Delete Deal</h1>
    <p style="color: var(--charcoal-grey);">Are you sure you want to delete this deal?</p>
</div>

<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <p style="font-weight: 500; margin-bottom: var(--spacing-sm);">Warning: This action cannot be undone!</p>
    <p style="font-size: 14px;">Deleting this deal will permanently remove all associated data.</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Deal Title</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($deal['title']); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Deal Value</div>
        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
            <?php echo htmlspecialchars($deal['currency'] ?? 'USD'); ?> <?php echo number_format($deal['value'], 2); ?>
        </div>
    </div>
    
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Stage</div>
        <div style="color: var(--midnight-black); font-weight: 500;">
            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($deal['stage']))); ?>
        </div>
    </div>
    
    <form method="POST" action="" style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <a href="deal_view.php?id=<?php echo $dealId; ?>" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
            Cancel
        </a>
        <button 
            type="submit" 
            style="background: #c33; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
        >
            Delete Deal
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
