<?php
/**
 * Import Companies Page
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

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Modules\Companies;

Database::init(require __DIR__ . '/../config/database.php');
\CRM\Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$companiesModule = new Companies();
$error = null;
$success = null;
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $fileInfo = pathinfo($file['name']);

        if (strtolower((string) ($fileInfo['extension'] ?? '')) !== 'csv') {
            $error = 'Please upload a CSV file.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $error = 'Could not read the uploaded file.';
            } else {
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    $error = 'CSV file appears to be empty.';
                } else {
                    $columnMap = $companiesModule->mapImportHeaders($headers);
                    if (!isset($columnMap['name'])) {
                        $error = 'CSV file must contain a company name column.';
                    } else {
                        $rows = [];
                        $rowNumber = 1;
                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNumber++;
                            $rows[$rowNumber] = $row;
                        }

                        $importResults = $companiesModule->importRows($rows, $columnMap);
                        if (($importResults['imported'] + $importResults['updated']) > 0) {
                            $success = sprintf(
                                'Import complete: %d created, %d updated.',
                                (int) $importResults['imported'],
                                (int) $importResults['updated']
                            );
                        }
                    }
                }

                fclose($handle);
            }
        }
    } else {
        $error = 'Please select a CSV file to upload.';
    }
}

$fieldDescriptions = $companiesModule->getImportFieldAliases();

$pageTitle = 'Import Companies - ' . brandProductName();
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
                    <h1>Import Companies</h1>
                    <p>Upload a CSV file to create or update companies in bulk</p>
                </div>
                <div class="page-header-actions">
                    <a href="companies.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Companies
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

            <?php if ($importResults): ?>
                <section class="utility-result-card">
                    <h2 class="utility-section-title">Import Results</h2>
                    <div class="utility-result-grid">
                        <div class="utility-result-item">
                            <div class="utility-result-label">Total Rows</div>
                            <div class="utility-result-value"><?php echo (int) $importResults['total']; ?></div>
                        </div>
                        <div class="utility-result-item">
                            <div class="utility-result-label">Created</div>
                            <div class="utility-result-value is-success"><?php echo (int) $importResults['imported']; ?></div>
                        </div>
                        <div class="utility-result-item">
                            <div class="utility-result-label">Updated</div>
                            <div class="utility-result-value is-info"><?php echo (int) $importResults['updated']; ?></div>
                        </div>
                        <div class="utility-result-item">
                            <div class="utility-result-label">Skipped</div>
                            <div class="utility-result-value is-warning"><?php echo (int) $importResults['skipped']; ?></div>
                        </div>
                    </div>

                    <?php if (!empty($importResults['errors'])): ?>
                        <div class="utility-error-list">
                            <div class="utility-error-list-title">Errors</div>
                            <ul>
                                <?php foreach ($importResults['errors'] as $importError): ?>
                                    <li><?php echo htmlspecialchars($importError); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="utility-layout">
                <section class="utility-upload-card">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                        <div class="form-group utility-file-drop">
                            <label for="csv_file">CSV File</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                            <small class="admin-help-text">Duplicate handling uses exact normalized company name matching. Matching names update the existing company instead of creating a duplicate.</small>
                        </div>

                        <div class="utility-form-actions">
                            <a href="companies.php" class="btn-premium-secondary">Cancel</a>
                            <button type="submit" class="btn-premium-primary">
                                <i class="fas fa-upload"></i>
                                Import Companies
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="utility-side-card">
                    <h2 class="utility-section-title">Supported Columns</h2>
                    <ul class="utility-column-list">
                        <?php foreach ($fieldDescriptions as $field => $aliases): ?>
                            <li>
                                <strong><?php echo htmlspecialchars($field); ?></strong>
                                (<?php echo htmlspecialchars(implode(', ', $aliases)); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="utility-section-copy">
                        <code>assigned_to</code> accepts either a user email address or a numeric user ID.
                    </p>
                </aside>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
