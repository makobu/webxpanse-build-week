<?php
/**
 * Create Company Page
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
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Companies;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
\CRM\Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $companies = new Companies();
            $id = $companies->create($_POST);
            header('Location: company_view.php?id=' . $id);
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Company create failed: ' . $e->getMessage());
            $error = 'Company could not be created. Please try again.';
        }
    }
}

$allUsers = (new ContactAssignmentAccessService())->getAssignableUsers((int) (WorkspaceContext::currentWorkspaceId() ?? 0));

$pageTitle = 'Add Company - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="margin: 0;">Add Company</h1>
    <p style="margin: var(--spacing-sm) 0 0; color: var(--charcoal-grey);">Create a new company/organization</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom: var(--spacing-lg);"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card" style="max-width: 600px; padding: var(--spacing-xl);">
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <div>
                <label for="name" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Name *</label>
                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="website" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Website</label>
                <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="phone" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Phone</label>
                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="industry" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Industry</label>
                <input type="text" id="industry" name="industry" value="<?php echo htmlspecialchars($_POST['industry'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="size" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Size</label>
                <input type="text" id="size" name="size" placeholder="e.g. 1-10, 11-50" value="<?php echo htmlspecialchars($_POST['size'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="address" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Address</label>
                <textarea id="address" name="address" rows="2" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            </div>
            <div>
                <label for="assigned_to" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Assigned To</label>
                <select id="assigned_to" name="assigned_to" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    <option value="">-- None --</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $u['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['email']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: var(--spacing-md); margin-top: var(--spacing-md);">
                <a href="companies.php" class="btn">Cancel</a>
                <button type="submit" class="btn btn-primary">Create Company</button>
            </div>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
