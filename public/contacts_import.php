<?php
/**
 * Import Contacts Page
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
use CRM\Modules\Contacts;
use CRM\Modules\Tags;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$contactsModule = new Contacts();
$tagsModule = new Tags();
$error = null;
$success = null;
$importResults = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $fileName = $file['tmp_name'];
        
        // Validate file type
        $fileInfo = pathinfo($file['name']);
        if (strtolower($fileInfo['extension']) !== 'csv') {
            $error = 'Please upload a CSV file.';
        } else {
            // Parse CSV
            $handle = fopen($fileName, 'r');
            if ($handle === false) {
                $error = 'Could not read the uploaded file.';
            } else {
                // Read header row
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    $error = 'CSV file appears to be empty.';
                } else {
                    // Normalize headers (lowercase, remove spaces)
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $headers);
                    
                    // Map common column names
                    $fieldMapping = [
                        'first_name' => ['first name', 'firstname', 'fname', 'first_name'],
                        'last_name' => ['last name', 'lastname', 'lname', 'last_name'],
                        'email' => ['email', 'e-mail', 'email address'],
                        'phone' => ['phone', 'phone number', 'telephone', 'mobile'],
                        'company' => ['company', 'organization', 'org'],
                        'lead_source' => ['lead source', 'source', 'lead_source'],
                        'stage' => ['stage', 'status', 'deal stage'],
                        // Enrichment fields
                        'job_title' => ['job title', 'jobtitle', 'title', 'position', 'job_title'],
                        'location' => ['location', 'city', 'address'],
                        'company_website' => ['company website', 'website', 'url', 'company_website', 'web'],
                        'company_size' => ['company size', 'size', 'employees', 'company_size'],
                        'company_industry' => ['industry', 'sector', 'company industry', 'company_industry'],
                        'company_description' => ['company description', 'description', 'about', 'company_description'],
                        'company_founded' => ['founded', 'founded year', 'year founded', 'company_founded'],
                        'company_revenue' => ['revenue', 'annual revenue', 'company revenue', 'company_revenue'],
                        'linkedin_url' => ['linkedin', 'linkedin url', 'linkedin_url', 'linkedin profile'],
                        'twitter_url' => ['twitter', 'twitter url', 'twitter_url', 'twitter handle'],
                        'timezone' => ['timezone', 'time zone', 'tz'],
                        'email_verified' => ['email verified', 'verified', 'email_verified'],
                        'tags' => ['tag', 'tags', 'label', 'labels']
                    ];
                    
                    // Find column indices
                    $columnMap = [];
                    foreach ($fieldMapping as $field => $aliases) {
                        foreach ($headers as $index => $header) {
                            if (in_array($header, $aliases)) {
                                $columnMap[$field] = $index;
                                break;
                            }
                        }
                    }
                    
                    if (empty($columnMap) || !isset($columnMap['email'])) {
                        $error = 'CSV file must contain at least an email column.';
                    } else {
                        // Process rows
                        $importResults = [
                            'total' => 0,
                            'imported' => 0,
                            'tags_assigned' => 0,
                            'skipped' => 0,
                            'errors' => []
                        ];
                        
                        $rowNum = 1; // Header is row 0
                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNum++;
                            $importResults['total']++;
                            
                            // Skip empty rows
                            if (empty(array_filter($row))) {
                                continue;
                            }
                            
                            // Build contact data
                            $contactData = [];
                            foreach ($columnMap as $field => $index) {
                                if (isset($row[$index]) && trim($row[$index]) !== '') {
                                    $value = trim($row[$index]);
                                    
                                    // Handle special fields
                                    if ($field === 'email_verified') {
                                        // Convert various formats to boolean
                                        $value = strtolower($value);
                                        $contactData[$field] = in_array($value, ['yes', 'true', '1', 'verified', 'y']) ? 1 : 0;
                                    } elseif ($field === 'company_founded') {
                                        // Ensure it's a valid year
                                        $year = (int) $value;
                                        if ($year >= 1800 && $year <= date('Y')) {
                                            $contactData[$field] = $year;
                                        }
                                    } else {
                                        $contactData[$field] = $value;
                                    }
                                }
                            }

                            $rawTags = (string) ($contactData['tags'] ?? '');
                            unset($contactData['tags']);
                            
                            // Validate required fields
                            if (empty($contactData['email'])) {
                                $importResults['errors'][] = "Row $rowNum: Missing email address";
                                $importResults['skipped']++;
                                continue;
                            }
                            
                            // Set defaults
                            if (empty($contactData['first_name'])) {
                                $contactData['first_name'] = 'Imported';
                            }
                            if (empty($contactData['lead_source'])) {
                                $contactData['lead_source'] = 'import';
                            }
                            if (empty($contactData['stage'])) {
                                $contactData['stage'] = 'new';
                            }
                            if (empty($contactData['created_by'])) {
                                $contactData['created_by'] = (int) ($_SESSION['user_id'] ?? 0);
                            }
                            
                            // Try to create contact
                            try {
                                $result = $contactsModule->create($contactData);
                                
                                if ($result['status'] === 'duplicate') {
                                    $importResults['errors'][] = "Row $rowNum: Duplicate email ({$contactData['email']})";
                                    $importResults['skipped']++;
                                } elseif ($result['status'] === 'success') {
                                    $importResults['imported']++;

                                    $contactId = (int) ($result['id'] ?? 0);
                                    if ($contactId > 0 && $rawTags !== '') {
                                        $tagNames = preg_split('/[;,|]+/', $rawTags);
                                        $tagNames = array_values(array_unique(array_filter(array_map('trim', $tagNames), function ($name) {
                                            return $name !== '';
                                        })));

                                        foreach ($tagNames as $tagName) {
                                            try {
                                                $tag = $tagsModule->getByName($tagName);
                                                if (!$tag) {
                                                    $tagId = $tagsModule->create([
                                                        'name' => $tagName,
                                                        'created_by' => (int) ($_SESSION['user_id'] ?? 0)
                                                    ]);
                                                } else {
                                                    $tagId = (int) $tag['id'];
                                                }

                                                if ($tagId > 0 && $tagsModule->assign($tagId, 'contact', $contactId)) {
                                                    $importResults['tags_assigned']++;
                                                }
                                            } catch (\Throwable $tagError) {
                                                $importResults['errors'][] = "Row $rowNum: Tag '{$tagName}' skipped (" . $tagError->getMessage() . ")";
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $importResults['errors'][] = "Row $rowNum: " . $e->getMessage();
                                $importResults['skipped']++;
                            }
                        }
                        
                        fclose($handle);
                        
                        if ($importResults['imported'] > 0) {
                            $success = "Successfully imported {$importResults['imported']} contact(s).";
                        }
                    }
                }
            }
        }
    } else {
        $error = 'Please select a CSV file to upload.';
    }
}

$pageTitle = 'Import Contacts - ' . brandProductName();
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
                    <h1>Import Contacts</h1>
                    <p>Upload a CSV file to import contacts in bulk</p>
                </div>
                <div class="page-header-actions">
                    <a href="contacts.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Contacts
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
                            <div class="utility-result-label">Imported</div>
                            <div class="utility-result-value is-success"><?php echo (int) $importResults['imported']; ?></div>
                        </div>
                        <div class="utility-result-item">
                            <div class="utility-result-label">Skipped</div>
                            <div class="utility-result-value is-warning"><?php echo (int) $importResults['skipped']; ?></div>
                        </div>
                        <div class="utility-result-item">
                            <div class="utility-result-label">Tags Assigned</div>
                            <div class="utility-result-value is-info"><?php echo (int) ($importResults['tags_assigned'] ?? 0); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($importResults['errors'])): ?>
                        <div class="utility-error-list">
                            <div class="utility-error-list-title">Errors</div>
                            <ul>
                                <?php foreach (array_slice($importResults['errors'], 0, 20) as $errorMsg): ?>
                                    <li><?php echo htmlspecialchars($errorMsg); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($importResults['errors']) > 20): ?>
                                <p class="utility-muted">
                                    ... and <?php echo count($importResults['errors']) - 20; ?> more errors
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="utility-layout">
                <section class="utility-upload-card">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                        <div class="form-group utility-file-drop">
                            <label for="csv_file">CSV File *</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                            <small class="admin-help-text">Maximum file size: 5MB</small>
                        </div>

                        <div class="premium-banner premium-banner-warning">
                            <strong>CSV Format Requirements:</strong>
                            <ul class="utility-check-list">
                                <li>First row must contain column headers</li>
                                <li>Email column is required</li>
                                <li>Supported columns: First Name, Last Name, Email, Phone, Company, Lead Source, Stage, Job Title, Location, Company Website, Company Size, Industry, Company Description, Founded Year, Revenue, LinkedIn URL, Twitter URL, Timezone, Email Verified, Tag/Tags</li>
                                <li>Duplicate emails will be skipped</li>
                                <li>Use comma, semicolon, or pipe to separate multiple tags</li>
                            </ul>
                        </div>

                        <div class="utility-form-actions">
                            <a href="contacts.php" class="btn-premium-secondary">Cancel</a>
                            <button type="submit" class="btn-premium-primary">
                                <i class="fas fa-upload"></i>
                                Import Contacts
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="utility-side-card">
                    <h2 class="utility-section-title">Example CSV Format</h2>
                    <p class="utility-section-copy">Use this structure for contact details, enrichment fields, and tag assignment.</p>
                    <pre class="utility-code-sample">First Name,Last Name,Email,Phone,Company,Job Title,Location,Company Website,Company Size,Industry,Lead Source,Stage,Tags
John,Doe,john@example.com,+1234567890,Acme Corp,CEO,New York,https://acme.com,51-200,Technology,form,new,"VIP,Newsletter"
Jane,Smith,jane@example.com,+0987654321,Tech Inc,CTO,San Francisco,https://techinc.com,201-500,Software,referral,contacted,"Partner;B2B"</pre>
                </aside>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
