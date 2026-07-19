<?php
/**
 * Contact Merge Page
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
use CRM\Authorization;
use CRM\Modules\Contacts;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication and admin role
if (!Auth::check() || !Authorization::can('contacts.merge', Auth::user())) {
    header('Location: contacts.php');
    exit;
}

$contactsModule = new Contacts();
$workspaceScope = new WorkspaceScopeService();
$error = null;
$success = null;

// Handle merge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'merge') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $targetId = (int) ($_POST['target_id'] ?? 0);
        
        if (!$sourceId || !$targetId) {
            $error = 'Both source and target contacts are required.';
        } elseif ($sourceId === $targetId) {
            $error = 'Cannot merge a contact with itself.';
        } else {
            try {
                // Get field preferences
                $fieldPreferences = [];
                $fields = ['first_name', 'last_name', 'email', 'phone', 'company', 'lead_source', 'stage', 'assigned_to'];
                foreach ($fields as $field) {
                    if (isset($_POST["prefer_{$field}"])) {
                        $fieldPreferences[$field] = $_POST["prefer_{$field}"];
                    }
                }
                
                $contactsModule->merge($sourceId, $targetId, $fieldPreferences);
                $success = 'Contacts merged successfully!';
                header('Location: contact_view.php?id=' . $targetId . '&merged=1');
                exit;
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get contacts to merge
$sourceId = (int) ($_GET['source_id'] ?? 0);
$targetId = (int) ($_GET['target_id'] ?? 0);

$source = $sourceId ? $contactsModule->getById($sourceId) : null;
$target = $targetId ? $contactsModule->getById($targetId) : null;

if (!$source || !$target) {
    header('Location: contacts.php');
    exit;
}

$workspaceClause = $workspaceScope->workspaceClause();
$sourceProvenance = $contactsModule->getFieldProvenanceMap($source);
$targetProvenance = $contactsModule->getFieldProvenanceMap($target);
$sourceCounts = [
    'notes' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM notes WHERE {$workspaceClause['sql']} AND entity_type = 'contact' AND entity_id = ?", array_merge($workspaceClause['params'], [$sourceId]))['c'] ?? 0),
    'deals' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$sourceId]))['c'] ?? 0),
    'tasks' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$sourceId]))['c'] ?? 0),
    'invoices' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM invoices WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$sourceId]))['c'] ?? 0),
];
$targetCounts = [
    'notes' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM notes WHERE {$workspaceClause['sql']} AND entity_type = 'contact' AND entity_id = ?", array_merge($workspaceClause['params'], [$targetId]))['c'] ?? 0),
    'deals' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$targetId]))['c'] ?? 0),
    'tasks' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$targetId]))['c'] ?? 0),
    'invoices' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM invoices WHERE {$workspaceClause['sql']} AND contact_id = ?", array_merge($workspaceClause['params'], [$targetId]))['c'] ?? 0),
];
$fieldSourceLabel = static function (array $map, string $field): string {
    return (string) ($map[$field]['source_label'] ?? 'Untracked');
};

$pageTitle = 'Merge Contacts - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Merge Contacts</h1>
    <p style="color: var(--charcoal-grey);">Merge duplicate contacts into one</p>
</div>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
    <strong>Warning:</strong> This will merge the source contact into the target contact. The source contact will be deleted, and all related data (activities, notes, documents, etc.) will be moved to the target contact.
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-xl); margin-bottom: var(--spacing-xl);">
    <!-- Source Contact -->
    <div style="background: white; padding: var(--spacing-lg); border: 2px solid #c33; border-radius: 8px;">
        <h2 style="color: #c33; margin-bottom: var(--spacing-md);">Source Contact (Will be deleted)</h2>
        <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
            <div><strong>Name:</strong> <?php echo htmlspecialchars($source['first_name'] . ' ' . $source['last_name']); ?></div>
            <div><strong>Email:</strong> <?php echo htmlspecialchars($source['email'] ?? '-'); ?></div>
            <div><strong>Phone:</strong> <?php echo htmlspecialchars($source['phone'] ?? '-'); ?></div>
            <div><strong>Company:</strong> <?php echo htmlspecialchars($source['company'] ?? '-'); ?></div>
            <div><strong>Stage:</strong> <?php echo htmlspecialchars($source['stage'] ?? '-'); ?></div>
            <div><strong>Lead Source:</strong> <?php echo htmlspecialchars($source['lead_source'] ?? '-'); ?></div>
            <div><strong>Linked records:</strong> <?php echo $sourceCounts['notes']; ?> notes · <?php echo $sourceCounts['deals']; ?> deals · <?php echo $sourceCounts['tasks']; ?> tasks · <?php echo $sourceCounts['invoices']; ?> invoices</div>
        </div>
    </div>
    
    <!-- Target Contact -->
    <div style="background: white; padding: var(--spacing-lg); border: 2px solid var(--accent-blue); border-radius: 8px;">
        <h2 style="color: var(--accent-blue); margin-bottom: var(--spacing-md);">Target Contact (Will be kept)</h2>
        <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
            <div><strong>Name:</strong> <?php echo htmlspecialchars($target['first_name'] . ' ' . $target['last_name']); ?></div>
            <div><strong>Email:</strong> <?php echo htmlspecialchars($target['email'] ?? '-'); ?></div>
            <div><strong>Phone:</strong> <?php echo htmlspecialchars($target['phone'] ?? '-'); ?></div>
            <div><strong>Company:</strong> <?php echo htmlspecialchars($target['company'] ?? '-'); ?></div>
            <div><strong>Stage:</strong> <?php echo htmlspecialchars($target['stage'] ?? '-'); ?></div>
            <div><strong>Lead Source:</strong> <?php echo htmlspecialchars($target['lead_source'] ?? '-'); ?></div>
            <div><strong>Linked records:</strong> <?php echo $targetCounts['notes']; ?> notes · <?php echo $targetCounts['deals']; ?> deals · <?php echo $targetCounts['tasks']; ?> tasks · <?php echo $targetCounts['invoices']; ?> invoices</div>
        </div>
    </div>
</div>

<!-- Field Preferences -->
<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg);">
    <h2 style="margin-bottom: var(--spacing-lg);">Field Preferences</h2>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-lg);">Choose which contact's data to keep for each field:</p>
    
    <form method="POST" action="">
        <input type="hidden" name="action" value="merge">
        <input type="hidden" name="source_id" value="<?php echo $sourceId; ?>">
        <input type="hidden" name="target_id" value="<?php echo $targetId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--spacing-md);">
            <?php 
            $fields = [
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'company' => 'Company',
                'lead_source' => 'Lead Source',
                'stage' => 'Stage',
                'assigned_to' => 'Assigned To',
                'job_title' => 'Job Title',
                'location' => 'Location',
                'company_website' => 'Company Website',
                'linkedin_url' => 'LinkedIn URL',
                'twitter_url' => 'Twitter URL'
            ];
            
            foreach ($fields as $field => $label): 
                $sourceValue = $source[$field] ?? '';
                $targetValue = $target[$field] ?? '';
            ?>
                <div>
                    <label style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500;"><?php echo htmlspecialchars($label); ?></label>
                    <select name="prefer_<?php echo $field; ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="target" <?php echo !empty($targetValue) ? 'selected' : ''; ?>>
                            Target: <?php echo htmlspecialchars($targetValue ?: '(empty)'); ?> · <?php echo htmlspecialchars($fieldSourceLabel($targetProvenance, $field)); ?>
                        </option>
                        <option value="source" <?php echo !empty($sourceValue) && empty($targetValue) ? 'selected' : ''; ?>>
                            Source: <?php echo htmlspecialchars($sourceValue ?: '(empty)'); ?> · <?php echo htmlspecialchars($fieldSourceLabel($sourceProvenance, $field)); ?>
                        </option>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-xl);">
            <button type="submit" onclick="return confirm('Are you sure you want to merge these contacts? This action cannot be undone.');" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                Merge Contacts
            </button>
            <a href="contacts.php" style="background: white; color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; text-decoration: none; font-weight: 500;">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
