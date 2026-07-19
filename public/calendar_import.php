<?php
/**
 * Calendar Import Page (iCal)
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
use CRM\Services\CalendarService;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$calendarService = new CalendarService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

$error = null;
$success = null;
$importResult = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        if (!empty($_FILES['ical_file']['tmp_name'])) {
            $icalContent = file_get_contents($_FILES['ical_file']['tmp_name']);
            $skipDuplicates = isset($_POST['skip_duplicates']);
            
            try {
                $importResult = $calendarService->importFromICal($icalContent, $userId, [
                    'skip_duplicates' => $skipDuplicates
                ]);
                
                $success = "Successfully imported {$importResult['count']} events.";
                if (!empty($importResult['errors'])) {
                    $error = "Some events failed to import: " . implode(', ', $importResult['errors']);
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Please select an iCal file to import';
        }
    }
}

$pageTitle = 'Import Calendar - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<link rel="stylesheet" href="assets/css/utility-forms-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="utility-workspace">
            <div class="page-header">
                <div>
                    <h1>Import Calendar</h1>
                    <p>Import events from an iCal (.ics) file</p>
                </div>
                <div class="page-header-actions">
                    <a href="calendar.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Calendar
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="premium-banner premium-banner-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($importResult && !empty($importResult['errors'])): ?>
                <div class="premium-banner premium-banner-warning">
                    <strong>Import completed with errors:</strong>
                    <ul class="utility-check-list">
                        <?php foreach ($importResult['errors'] as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="utility-layout">
                <section class="utility-upload-card">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                        <div class="form-group utility-file-drop">
                            <label for="ical_file">iCal File (.ics)</label>
                            <input type="file" id="ical_file" name="ical_file" accept=".ics,text/calendar" required>
                            <small class="admin-help-text">Select an iCal (.ics) file exported from Google Calendar, Outlook, or other calendar applications.</small>
                        </div>

                        <label class="admin-checkbox-card">
                            <input type="checkbox" name="skip_duplicates" checked>
                            <span>
                                <strong>Skip duplicate events</strong>
                                <small>If checked, events with the same UID will not be imported again.</small>
                            </span>
                        </label>

                        <div class="utility-form-actions">
                            <a href="calendar.php" class="btn-premium-secondary">Cancel</a>
                            <button type="submit" class="btn-premium-primary">
                                <i class="fas fa-upload"></i>
                                Import Events
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="utility-side-card">
                    <h2 class="utility-section-title">Supported Calendar Files</h2>
                    <p class="utility-section-copy">Use an exported `.ics` file from Google Calendar, Outlook, Apple Calendar, or another app that supports iCal exports.</p>
                    <ul class="utility-check-list">
                        <li>Imported events are attached to your user account</li>
                        <li>Duplicate detection uses the event UID</li>
                        <li>Any import errors are reported after processing</li>
                    </ul>
                </aside>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
