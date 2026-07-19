<?php
/**
 * Create Activity Page
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
use CRM\Modules\Activities;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$activitiesModule = new Activities();
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$error = null;
$success = null;
$manualTypes = Activities::manualTypeOptions();
$selectedContactId = (int) ($_GET['contact_id'] ?? 0);

// Get contacts for dropdown
$contacts = Database::query(
    "SELECT id, first_name, last_name, email
     FROM contacts
     WHERE workspace_id = ?
     ORDER BY first_name, last_name",
    [$workspaceId]
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $activityType = $_POST['activity_type'] ?? '';
            $description = $_POST['description'] ?? null;
            
            if (!$contactId) {
                $error = 'Please select a contact.';
            } elseif (empty($activityType)) {
                $error = 'Please select an activity type.';
            } elseif (!Activities::isManualType($activityType)) {
                $error = 'Please select a valid manual activity type.';
            } else {
                $activityId = $activitiesModule->log(
                    $contactId,
                    $activityType,
                    $description,
                    []
                );
                
                header('Location: contact_view.php?id=' . $contactId);
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Add Activity - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Add Activity</h1>
                <p>Log a contact touchpoint for the workspace timeline.</p>
            </div>
            <div class="page-header-actions">
                <a href="activities.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Activities
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="activity-form-card">
            <form method="POST" action="" class="activity-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div class="filter-group">
                    <label for="contact_id">Contact *</label>
                    <select id="contact_id" name="contact_id" required>
                        <option value="">Select a contact...</option>
                        <?php foreach ($contacts as $contact): ?>
                            <?php
                            $contactOptionId = (int) $contact['id'];
                            $isSelected = (isset($_POST['contact_id']) && (int) $_POST['contact_id'] === $contactOptionId)
                                || (!isset($_POST['contact_id']) && $selectedContactId === $contactOptionId);
                            $contactName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
                            ?>
                            <option value="<?php echo $contactOptionId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($contactName ?: 'Contact #' . $contactOptionId) . (!empty($contact['email']) ? ' (' . $contact['email'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="activity_type">Activity Type *</label>
                    <select id="activity_type" name="activity_type" required>
                        <option value="">Select activity type...</option>
                        <?php foreach ($manualTypes as $typeValue => $typeLabel): ?>
                            <option value="<?php echo htmlspecialchars($typeValue); ?>" <?php echo (isset($_POST['activity_type']) && $_POST['activity_type'] === $typeValue) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($typeLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="5" placeholder="Enter activity description..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="activity-form-actions">
                    <a href="activities.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        Add Activity
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
