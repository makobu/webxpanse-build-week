<?php
/**
 * Create Contact Page
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
use CRM\Modules\Companies;
use CRM\Modules\CustomFields;
use CRM\Modules\Tags;
use CRM\Services\AIEnrichmentService;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$contactsModule = new Contacts();
$companiesModule = new Companies();
$customFieldsModule = new CustomFields();
$tagsModule = new Tags();
$existingCompanies = $companiesModule->list(500, 0);
$error = null;
$success = null;
$duplicateMatches = null;
$prefillCompanyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
$prefillCompany = $prefillCompanyId > 0 ? $companiesModule->getById($prefillCompanyId) : null;
$createMode = (string) ($_GET['stage'] ?? '');
$sourceMode = (string) ($_GET['source'] ?? '');
$isLeadMode = $createMode === 'new' || $sourceMode === 'dashboard_momentum';
$createLabel = $isLeadMode ? 'Lead' : 'Contact';
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

// Get custom fields for contacts
$customFields = $customFieldsModule->getByModule('contacts');

// Get all tags
$allTags = $tagsModule->getAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $result = $contactsModule->create($_POST);
            
            if ($result['status'] === 'duplicate') {
                $duplicateMatches = $result['matches'];
                $error = 'A contact with this email or phone already exists.';
            } elseif ($result['status'] === 'success') {
                $contactId = $result['id'];
                
                // Save custom field values
                foreach ($customFields as $field) {
                    $fieldKey = 'custom_field_' . $field['id'];
                    if (isset($_POST[$fieldKey])) {
                        $value = $_POST[$fieldKey];
                        if ($value !== '') {
                            $customFieldsModule->setContactValue($contactId, $field['id'], $value);
                        }
                    }
                }
                
                // Handle tags
                $selectedTags = $_POST['tags'] ?? [];
                foreach ($selectedTags as $tagId) {
                    $tagsModule->assign((int) $tagId, 'contact', $contactId);
                }
                
                // Calculate initial enrichment score
                try {
                    $enrichmentService = new AIEnrichmentService();
                    $enrichmentService->calculateEnrichmentScore($contactId);
                } catch (\Exception $e) {
                    // Log but don't fail the creation
                    error_log("Failed to calculate enrichment score: " . $e->getMessage());
                }
                
                header('Location: contact_view.php?id=' . $contactId);
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create ' . $createLabel . ' - ' . brandProductName();
$contactCreateExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $contactCreateExperienceMode,
    'surface' => 'contact_create',
    'current_page' => 'contacts_create.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'is_lead_mode' => $isLeadMode,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/work-surface-guidance.css">

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Create New <?php echo htmlspecialchars($createLabel); ?></h1>
    <p style="color: var(--charcoal-grey);"><?php echo $isLeadMode ? 'Add one lead to start today\'s revenue loop.' : 'Add a new contact to your growth system'; ?></p>
</div>

<?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($duplicateMatches): ?>
    <div style="background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <strong>Duplicate Contacts Found:</strong>
        <ul style="margin: var(--spacing-sm) 0 0 20px;">
            <?php if (!empty($duplicateMatches['email'])): ?>
                <?php foreach ($duplicateMatches['email'] as $match): ?>
                    <li>
                        <a href="contact_view.php?id=<?php echo $match['id']; ?>" style="color: #856404;">
                            <?php echo htmlspecialchars($match['first_name'] . ' ' . $match['last_name']); ?> (<?php echo htmlspecialchars($match['email']); ?>)
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($duplicateMatches['phone'])): ?>
                <?php foreach ($duplicateMatches['phone'] as $match): ?>
                    <li>
                        <a href="contact_view.php?id=<?php echo $match['id']; ?>" style="color: #856404;">
                            <?php echo htmlspecialchars($match['first_name'] . ' ' . $match['last_name']); ?> (<?php echo htmlspecialchars($match['phone']); ?>)
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px; width: 100%; margin: 0 auto; box-sizing: border-box;">
    <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <input type="hidden" name="company_id" value="<?php echo htmlspecialchars((string) ($_POST['company_id'] ?? ($prefillCompany['id'] ?? ''))); ?>">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="first_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">First Name *</label>
                <input 
                    type="text" 
                    id="first_name" 
                    name="first_name" 
                    required
                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div>
                <label for="last_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Last Name</label>
                <input 
                    type="text" 
                    id="last_name" 
                    name="last_name"
                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
        </div>
        
        <div>
            <label for="email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Email *</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                required
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="phone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Phone</label>
            <input 
                type="tel" 
                id="phone" 
                name="phone"
                value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="company" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company</label>
            <input 
                type="text" 
                id="company" 
                name="company"
                value="<?php echo htmlspecialchars($_POST['company'] ?? ($prefillCompany['name'] ?? '')); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
            <div style="margin-top: var(--spacing-sm);">
                <label for="existing_company_link" style="display: block; margin-bottom: 6px; color: var(--charcoal-grey); font-size: 13px; font-weight: 500;">Link To Existing Company</label>
                <select
                    id="existing_company_link"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    data-target-input="company"
                    data-target-hidden="company_id"
                >
                    <option value="">Select an existing company...</option>
                    <?php foreach ($existingCompanies as $existingCompany): ?>
                        <option
                            value="<?php echo (int) $existingCompany['id']; ?>"
                            data-company-name="<?php echo htmlspecialchars($existingCompany['name']); ?>"
                            <?php echo (string) ($_POST['company_id'] ?? ($prefillCompany['id'] ?? '')) === (string) $existingCompany['id'] ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($existingCompany['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top: 4px; color: var(--charcoal-grey); font-size: 12px;">Selecting a company links this contact directly to that company record.</div>
            </div>
        </div>
        
        <!-- Professional & Company Information -->
        <div style="border-top: 1px solid var(--border-color); padding-top: var(--spacing-lg); margin-top: var(--spacing-md);">
            <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 18px;">Professional & Company Information</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="job_title" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Job Title</label>
                    <input 
                        type="text" 
                        id="job_title" 
                        name="job_title"
                        value="<?php echo htmlspecialchars($_POST['job_title'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="location" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Location</label>
                    <input 
                        type="text" 
                        id="location" 
                        name="location"
                        value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
            </div>
            
            <div>
                <label for="company_website" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Website</label>
                <input 
                    type="url" 
                    id="company_website" 
                    name="company_website"
                    placeholder="https://example.com"
                    value="<?php echo htmlspecialchars($_POST['company_website'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="company_size" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Size</label>
                    <select 
                        id="company_size" 
                        name="company_size"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                        <option value="">Select size...</option>
                        <option value="1-10" <?php echo ($_POST['company_size'] ?? '') === '1-10' ? 'selected' : ''; ?>>1-10 employees</option>
                        <option value="11-50" <?php echo ($_POST['company_size'] ?? '') === '11-50' ? 'selected' : ''; ?>>11-50 employees</option>
                        <option value="51-200" <?php echo ($_POST['company_size'] ?? '') === '51-200' ? 'selected' : ''; ?>>51-200 employees</option>
                        <option value="201-500" <?php echo ($_POST['company_size'] ?? '') === '201-500' ? 'selected' : ''; ?>>201-500 employees</option>
                        <option value="501-1000" <?php echo ($_POST['company_size'] ?? '') === '501-1000' ? 'selected' : ''; ?>>501-1000 employees</option>
                        <option value="1001-5000" <?php echo ($_POST['company_size'] ?? '') === '1001-5000' ? 'selected' : ''; ?>>1001-5000 employees</option>
                        <option value="5001+" <?php echo ($_POST['company_size'] ?? '') === '5001+' ? 'selected' : ''; ?>>5001+ employees</option>
                    </select>
                </div>
                
                <div>
                    <label for="company_industry" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Industry</label>
                    <input 
                        type="text" 
                        id="company_industry" 
                        name="company_industry"
                        placeholder="e.g., Technology, Healthcare, Finance"
                        value="<?php echo htmlspecialchars($_POST['company_industry'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
            </div>
            
            <div>
                <label for="company_description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Description</label>
                <textarea 
                    id="company_description" 
                    name="company_description"
                    rows="3"
                    placeholder="Brief description of the company..."
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; resize: vertical;"
                ><?php echo htmlspecialchars($_POST['company_description'] ?? ''); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="company_founded" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Founded Year</label>
                    <input 
                        type="number" 
                        id="company_founded" 
                        name="company_founded"
                        min="1800"
                        max="<?php echo date('Y'); ?>"
                        placeholder="YYYY"
                        value="<?php echo htmlspecialchars($_POST['company_founded'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="company_revenue" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Revenue</label>
                    <input 
                        type="text" 
                        id="company_revenue" 
                        name="company_revenue"
                        placeholder="e.g., $1M - $10M"
                        value="<?php echo htmlspecialchars($_POST['company_revenue'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="linkedin_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">LinkedIn URL</label>
                    <input 
                        type="url" 
                        id="linkedin_url" 
                        name="linkedin_url"
                        placeholder="https://linkedin.com/in/..."
                        value="<?php echo htmlspecialchars($_POST['linkedin_url'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="twitter_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Twitter URL</label>
                    <input 
                        type="url" 
                        id="twitter_url" 
                        name="twitter_url"
                        placeholder="https://twitter.com/..."
                        value="<?php echo htmlspecialchars($_POST['twitter_url'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
            </div>
            
            <div>
                <label for="timezone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Timezone</label>
                <input 
                    type="text" 
                    id="timezone" 
                    name="timezone"
                    placeholder="e.g., America/New_York, Europe/London"
                    value="<?php echo htmlspecialchars($_POST['timezone'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                    Use IANA timezone format (e.g., America/New_York)
                </small>
            </div>
            
            <div>
                <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                    <input 
                        type="checkbox" 
                        name="email_verified" 
                        value="1"
                        <?php echo isset($_POST['email_verified']) && $_POST['email_verified'] ? 'checked' : ''; ?>
                        style="width: 18px; height: 18px;"
                    >
                    <span style="color: var(--midnight-black); font-weight: 500;">Email Verified</span>
                </label>
            </div>
        </div>
        
        <div>
            <label for="lead_source" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Lead Source</label>
            <select 
                id="lead_source" 
                name="lead_source"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="form" <?php echo ($_POST['lead_source'] ?? 'form') === 'form' ? 'selected' : ''; ?>>Form</option>
                <option value="whatsapp" <?php echo ($_POST['lead_source'] ?? '') === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                <option value="ad" <?php echo ($_POST['lead_source'] ?? '') === 'ad' ? 'selected' : ''; ?>>Ad</option>
                <option value="referral" <?php echo ($_POST['lead_source'] ?? '') === 'referral' ? 'selected' : ''; ?>>Referral</option>
                <option value="social" <?php echo ($_POST['lead_source'] ?? '') === 'social' ? 'selected' : ''; ?>>Social</option>
                <option value="import" <?php echo ($_POST['lead_source'] ?? '') === 'import' ? 'selected' : ''; ?>>Import</option>
                <option value="web_assessment" <?php echo ($_POST['lead_source'] ?? '') === 'web_assessment' ? 'selected' : ''; ?>>Web Assessment</option>
                <option value="other" <?php echo ($_POST['lead_source'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
        </div>
        
        <!-- Tags Section -->
        <div style="border-top: 1px solid var(--border-color); padding-top: var(--spacing-lg); margin-top: var(--spacing-md);">
            <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 18px;">Tags</h3>
            <div style="display: flex; flex-wrap: wrap; gap: var(--spacing-sm); padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; background: var(--light-grey); min-height: 60px;">
                <?php if (empty($allTags)): ?>
                    <div style="color: var(--charcoal-grey); font-size: 14px;">No tags available. <a href="tag_create.php" style="color: var(--accent-blue);">Create one</a></div>
                <?php else: ?>
                    <?php foreach ($allTags as $tag): ?>
                        <label style="display: flex; align-items: center; cursor: pointer; background: white; padding: 6px 12px; border-radius: 12px; border: 2px solid transparent;">
                            <input 
                                type="checkbox" 
                                name="tags[]" 
                                value="<?php echo $tag['id']; ?>"
                                style="display: none;"
                                onchange="this.parentElement.style.borderColor = this.checked ? '<?php echo $tag['color']; ?>' : 'transparent';"
                            >
                            <span style="background: <?php echo htmlspecialchars($tag['color']); ?>; color: white; padding: 4px 10px; border-radius: 10px; font-size: 13px; font-weight: 500;">
                                <?php echo htmlspecialchars($tag['name']); ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
            <a href="contacts.php" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Create <?php echo htmlspecialchars($createLabel); ?>
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const linkSelect = document.getElementById('existing_company_link');
    const companyInput = document.getElementById('company');
    const companyIdInput = document.querySelector('input[name="company_id"]');

    if (!linkSelect || !companyInput || !companyIdInput) {
        return;
    }

    linkSelect.addEventListener('change', function () {
        const selectedOption = this.options[this.selectedIndex];
        const selectedId = this.value;
        const companyName = selectedOption ? (selectedOption.getAttribute('data-company-name') || '') : '';

        companyIdInput.value = selectedId;
        if (companyName) {
            companyInput.value = companyName;
        }
    });
});
</script>
