<?php
/**
 * Edit Company Page
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

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: companies.php');
    exit;
}

$companies = new Companies();
$company = $companies->getById($id);
if (!$company) {
    header('Location: companies.php');
    exit;
}
$linkedContacts = $companies->getLinkedContacts($id);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $companies->update($id, $_POST);
            header('Location: company_view.php?id=' . $id);
            exit;
        } catch (\CRM\ConcurrencyConflictException $e) {
            $error = $e->getMessage() . ' Reload this page to review the latest version.';
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Company update failed for company ' . $id . ': ' . $e->getMessage());
            $error = 'Company could not be updated. Please try again.';
        }
    }
}

$allUsers = (new ContactAssignmentAccessService())->getAssignableUsers((int) (WorkspaceContext::currentWorkspaceId() ?? 0));

$pageTitle = 'Edit Company - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="margin: 0;">Edit Company</h1>
    <p style="margin: var(--spacing-sm) 0 0; color: var(--charcoal-grey);"><?php echo htmlspecialchars($company['name']); ?></p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom: var(--spacing-lg);"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card" style="max-width: 600px; padding: var(--spacing-xl);">
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($_POST['expected_lock_version'] ?? $company['lock_version'] ?? 0); ?>">
        <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <div>
                <label for="name" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Name *</label>
                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? $company['name']); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="website" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Website</label>
                <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($_POST['website'] ?? $company['website'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="phone" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Phone</label>
                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? $company['phone'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="industry" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Industry</label>
                <input type="text" id="industry" name="industry" value="<?php echo htmlspecialchars($_POST['industry'] ?? $company['industry'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="size" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Size</label>
                <input type="text" id="size" name="size" value="<?php echo htmlspecialchars($_POST['size'] ?? $company['size'] ?? ''); ?>"
                       style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            <div>
                <label for="address" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Address</label>
                <textarea id="address" name="address" rows="2" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($_POST['address'] ?? $company['address'] ?? ''); ?></textarea>
            </div>
            <div>
                <label for="assigned_to" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Assigned To</label>
                <select id="assigned_to" name="assigned_to" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    <option value="">-- None --</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($_POST['assigned_to'] ?? $company['assigned_to']) == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['email']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="primary_contact_id" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;">Primary Contact</label>
                <select id="primary_contact_id" name="primary_contact_id" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                    <option value="">-- None --</option>
                    <?php foreach ($linkedContacts as $linkedContact): ?>
                        <?php $selectedPrimary = (string) ($_POST['primary_contact_id'] ?? ($company['primary_contact_id'] ?? '')) === (string) $linkedContact['id']; ?>
                        <option value="<?php echo (int) $linkedContact['id']; ?>" <?php echo $selectedPrimary ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim(($linkedContact['first_name'] ?? '') . ' ' . ($linkedContact['last_name'] ?? '')) ?: ($linkedContact['email'] ?? 'Contact #' . $linkedContact['id'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top: 4px; color: var(--charcoal-grey); font-size: 12px;">Only contacts linked to this company can be selected.</div>
            </div>
            <div style="display: flex; gap: var(--spacing-md); margin-top: var(--spacing-md);">
                <a href="company_view.php?id=<?php echo $id; ?>" class="btn">Cancel</a>
                <a href="company_delete.php?id=<?php echo $id; ?>" class="btn" style="color: #c33; border-color: #f1b8b8;">Delete</a>
                <button type="submit" class="btn btn-primary">Update Company</button>
            </div>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
