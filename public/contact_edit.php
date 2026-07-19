<?php
/**
 * Edit Contact Page
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
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\Companies;
use CRM\Modules\CustomFields;
use CRM\Modules\Tags;
use CRM\Services\AIEnrichmentService;

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
$contactId = (int) ($_GET['id'] ?? 0);

if (!$contactId) {
    header('Location: contacts.php');
    exit;
}

$contact = $contactsModule->getById($contactId);

if (!$contact) {
    header('Location: contacts.php');
    exit;
}
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
if (!$contactsModule->isVisibleToUser($contact, $userId, Authorization::can('contacts.view_all', $user))) {
    http_response_code(403);
    die('Access denied: You do not have permission to edit this contact.');
}

$fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
$renderProvenanceHint = static function (string $field) use ($fieldProvenance): string {
    if (empty($fieldProvenance[$field]) || !is_array($fieldProvenance[$field])) {
        return '';
    }

    return '<div style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px;">Current source: ' .
        htmlspecialchars((string) ($fieldProvenance[$field]['source_label'] ?? 'Tracked')) .
        '. Saving here overrides it as a manual value.</div>';
};

$error = null;
$success = null;
$conflict = null;
$conflictPostData = [];
$contactFieldLabels = [
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'email' => 'Email',
    'phone' => 'Phone',
    'company' => 'Company',
    'company_id' => 'Company link',
    'lead_source' => 'Lead source',
    'stage' => 'Stage',
    'assigned_to' => 'Assigned to',
    'job_title' => 'Job title',
    'location' => 'Location',
    'company_website' => 'Company website',
    'company_size' => 'Company size',
    'company_industry' => 'Company industry',
    'company_description' => 'Company description',
    'company_founded' => 'Company founded',
    'company_revenue' => 'Company revenue',
    'linkedin_url' => 'LinkedIn URL',
    'twitter_url' => 'Twitter URL',
    'timezone' => 'Timezone',
    'email_verified' => 'Email verified',
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            Database::beginTransaction();
            $result = $contactsModule->update($contactId, $_POST);
            if ($result) {
                // Save custom field values
                $customFields = $customFieldsModule->getByModule('contacts');
                foreach ($customFields as $field) {
                    $fieldKey = 'custom_field_' . $field['id'];
                    if (isset($_POST[$fieldKey])) {
                        $value = $_POST[$fieldKey];
                        $customFieldsModule->setContactValue($contactId, $field['id'], $value);
                    } else {
                        // Clear value if not provided
                        Database::execute(
                            "DELETE FROM contact_custom_data WHERE contact_id = ? AND field_id = ?",
                            [$contactId, $field['id']]
                        );
                    }
                }
                
                // Handle tags
                $selectedTags = $_POST['tags'] ?? [];
                $currentTags = $tagsModule->getEntityTags('contact', $contactId);
                $currentTagIds = array_column($currentTags, 'id');
                
                // Remove unselected tags
                foreach ($currentTagIds as $tagId) {
                    if (!in_array($tagId, $selectedTags)) {
                        $tagsModule->unassign($tagId, 'contact', $contactId);
                    }
                }
                
                // Add new tags
                foreach ($selectedTags as $tagId) {
                    if (!in_array($tagId, $currentTagIds)) {
                        $tagsModule->assign((int) $tagId, 'contact', $contactId);
                    }
                }
                
                // Recalculate enrichment score after manual edit
                try {
                    $enrichmentService = new AIEnrichmentService();
                    $enrichmentService->calculateEnrichmentScore($contactId);
                } catch (\Exception $e) {
                    // Log but don't fail the update
                    error_log("Failed to recalculate enrichment score: " . $e->getMessage());
                }

                Database::commit();
                
                $success = 'Contact updated successfully!';
                $contact = $contactsModule->getById($contactId); // Refresh data
                $fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
            } else {
                Database::rollBack();
                $error = 'Failed to update contact.';
            }
        } catch (ConcurrencyConflictException $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $conflict = $e;
            $conflictPostData = $_POST;
            $contact = $contactsModule->getById($contactId) ?: $contact;
            $fieldProvenance = $contactsModule->getFieldProvenanceMap($contact);
            $error = $e->getMessage();
        } catch (\Exception $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Get custom fields and their values
$customFields = $customFieldsModule->getByModule('contacts');
$customFieldValues = [];
foreach ($customFields as $field) {
    $value = $customFieldsModule->getContactValue($contactId, $field['id']);
    $customFieldValues[$field['id']] = $value;
}

// Get all tags and current contact tags
$allTags = $tagsModule->getAll();
$contactTags = $tagsModule->getEntityTags('contact', $contactId);
$contactTagIds = array_column($contactTags, 'id');

$pageTitle = 'Edit Contact - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Edit Contact</h1>
    <p style="color: var(--charcoal-grey);">Update contact information</p>
</div>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($conflict): ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:var(--spacing-md);border-radius:8px;margin-bottom:var(--spacing-md);">
        <h3 style="margin:0 0 .5rem 0;color:#9a3412;">Someone updated this while you were editing.</h3>
        <p style="margin:.25rem 0 .75rem 0;">Review the differences below, then reload the latest version or intentionally overwrite with your submitted values.</p>
        <?php $conflictRows = Concurrency::conflictDiffRows($conflict, $contactFieldLabels); ?>
        <?php if ($conflictRows): ?>
            <table style="width:100%;border-collapse:collapse;margin:.5rem 0;background:#fff;border:1px solid #fed7aa;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:.5rem;border-bottom:1px solid #fed7aa;">Field</th>
                        <th style="text-align:left;padding:.5rem;border-bottom:1px solid #fed7aa;">Current saved value</th>
                        <th style="text-align:left;padding:.5rem;border-bottom:1px solid #fed7aa;">Your attempted value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conflictRows as $row): ?>
                        <tr>
                            <td style="padding:.5rem;border-bottom:1px solid #ffedd5;font-weight:700;"><?php echo htmlspecialchars($row['label']); ?></td>
                            <td style="padding:.5rem;border-bottom:1px solid #ffedd5;"><?php echo htmlspecialchars($row['current'] !== '' ? $row['current'] : '-'); ?></td>
                            <td style="padding:.5rem;border-bottom:1px solid #ffedd5;"><?php echo htmlspecialchars($row['submitted'] !== '' ? $row['submitted'] : '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-top:.75rem;">
            <a href="contact_edit.php?id=<?php echo (int) $contactId; ?>" class="btn btn-secondary">Reload latest</a>
            <form method="POST" action="" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <?php
                    $overwritePayload = $conflictPostData;
                    $overwritePayload['expected_lock_version'] = (int) ($contact['lock_version'] ?? 0);
                    echo Concurrency::hiddenInputs($overwritePayload);
                ?>
                <button type="submit" class="btn btn-primary">Overwrite with my values</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px;">
    <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
        <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($contact['lock_version'] ?? 0); ?>">
        <input type="hidden" name="company_id" value="<?php echo htmlspecialchars((string) ($_POST['company_id'] ?? ($contact['company_id'] ?? ''))); ?>">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
            <div>
                <label for="first_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">First Name *</label>
                <input 
                    type="text" 
                    id="first_name" 
                    name="first_name" 
                    required
                    value="<?php echo htmlspecialchars($contact['first_name']); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
            </div>
            
            <div>
                <label for="last_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Last Name</label>
                <input 
                    type="text" 
                    id="last_name" 
                    name="last_name"
                    value="<?php echo htmlspecialchars($contact['last_name'] ?? ''); ?>"
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
                value="<?php echo htmlspecialchars($contact['email']); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="phone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Phone</label>
            <input 
                type="tel" 
                id="phone" 
                name="phone"
                value="<?php echo htmlspecialchars($contact['phone'] ?? ''); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="company" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company</label>
            <input 
                type="text" 
                id="company" 
                name="company"
                value="<?php echo htmlspecialchars($_POST['company'] ?? ($contact['company'] ?? '')); ?>"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
            <div id="company-link-section" style="margin-top: var(--spacing-sm);">
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
                            <?php echo (string) ($_POST['company_id'] ?? ($contact['company_id'] ?? '')) === (string) $existingCompany['id'] ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($existingCompany['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top: 4px; color: var(--charcoal-grey); font-size: 12px;">Choose a company here to link this contact to an existing company record.</div>
            </div>
        </div>
        
        <!-- Professional & Company Information -->
        <div style="border-top: 1px solid var(--border-color); padding-top: var(--spacing-lg); margin-top: var(--spacing-md);">
            <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 18px;">Professional & Company Information</h3>
            <p style="margin: 0 0 var(--spacing-md); color: var(--charcoal-grey); font-size: 13px;">
                Structured profile fields are source-controlled. Verified API values and exact message-captured values stay traceable until you intentionally edit them here.
            </p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div>
                    <label for="job_title" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Job Title</label>
                    <input 
                        type="text" 
                        id="job_title" 
                        name="job_title"
                        value="<?php echo htmlspecialchars($contact['job_title'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <?php echo $renderProvenanceHint('job_title'); ?>
                </div>
                
                <div>
                    <label for="location" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Location</label>
                    <input 
                        type="text" 
                        id="location" 
                        name="location"
                        value="<?php echo htmlspecialchars($contact['location'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <?php echo $renderProvenanceHint('location'); ?>
                </div>
            </div>
            
            <div>
                <label for="company_website" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Website</label>
                <input 
                    type="url" 
                    id="company_website" 
                    name="company_website"
                    placeholder="https://example.com"
                    value="<?php echo htmlspecialchars($contact['company_website'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                <?php echo $renderProvenanceHint('company_website'); ?>
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
                        <option value="1-10" <?php echo ($contact['company_size'] ?? '') === '1-10' ? 'selected' : ''; ?>>1-10 employees</option>
                        <option value="11-50" <?php echo ($contact['company_size'] ?? '') === '11-50' ? 'selected' : ''; ?>>11-50 employees</option>
                        <option value="51-200" <?php echo ($contact['company_size'] ?? '') === '51-200' ? 'selected' : ''; ?>>51-200 employees</option>
                        <option value="201-500" <?php echo ($contact['company_size'] ?? '') === '201-500' ? 'selected' : ''; ?>>201-500 employees</option>
                        <option value="501-1000" <?php echo ($contact['company_size'] ?? '') === '501-1000' ? 'selected' : ''; ?>>501-1000 employees</option>
                        <option value="1001-5000" <?php echo ($contact['company_size'] ?? '') === '1001-5000' ? 'selected' : ''; ?>>1001-5000 employees</option>
                        <option value="5001+" <?php echo ($contact['company_size'] ?? '') === '5001+' ? 'selected' : ''; ?>>5001+ employees</option>
                    </select>
                </div>
                
                <div>
                    <label for="company_industry" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Industry</label>
                    <input 
                        type="text" 
                        id="company_industry" 
                        name="company_industry"
                        placeholder="e.g., Technology, Healthcare, Finance"
                        value="<?php echo htmlspecialchars($contact['company_industry'] ?? ''); ?>"
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
                ><?php echo htmlspecialchars($contact['company_description'] ?? ''); ?></textarea>
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
                        value="<?php echo htmlspecialchars($contact['company_founded'] ?? ''); ?>"
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
                        value="<?php echo htmlspecialchars($contact['company_revenue'] ?? ''); ?>"
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
                        value="<?php echo htmlspecialchars($contact['linkedin_url'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <?php echo $renderProvenanceHint('linkedin_url'); ?>
                </div>
                
                <div>
                    <label for="twitter_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Twitter URL</label>
                    <input 
                        type="url" 
                        id="twitter_url" 
                        name="twitter_url"
                        placeholder="https://twitter.com/..."
                        value="<?php echo htmlspecialchars($contact['twitter_url'] ?? ''); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <?php echo $renderProvenanceHint('twitter_url'); ?>
                </div>
            </div>
            
            <div>
                <label for="timezone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Timezone</label>
                <input 
                    type="text" 
                    id="timezone" 
                    name="timezone"
                    placeholder="e.g., America/New_York, Europe/London"
                    value="<?php echo htmlspecialchars($contact['timezone'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                >
                <?php echo $renderProvenanceHint('timezone'); ?>
                <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                    Use IANA timezone format (e.g., America/New_York)
                </small>
            </div>
            
            <div>
                <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                    <input type="hidden" name="email_verified" value="0">
                    <input 
                        type="checkbox" 
                        name="email_verified" 
                        value="1"
                        <?php echo isset($contact['email_verified']) && $contact['email_verified'] ? 'checked' : ''; ?>
                        style="width: 18px; height: 18px;"
                    >
                    <span style="color: var(--midnight-black); font-weight: 500;">Email Verified</span>
                </label>
            </div>
        </div>
        
        <div>
            <label for="stage" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Stage</label>
            <select 
                id="stage" 
                name="stage"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="new" <?php echo $contact['stage'] === 'new' ? 'selected' : ''; ?>>New</option>
                <option value="contacted" <?php echo $contact['stage'] === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                <option value="qualified" <?php echo $contact['stage'] === 'qualified' ? 'selected' : ''; ?>>Qualified</option>
                <option value="proposal" <?php echo $contact['stage'] === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
                <option value="negotiation" <?php echo $contact['stage'] === 'negotiation' ? 'selected' : ''; ?>>Negotiation</option>
                <option value="won" <?php echo $contact['stage'] === 'won' ? 'selected' : ''; ?>>Won</option>
                <option value="lost" <?php echo $contact['stage'] === 'lost' ? 'selected' : ''; ?>>Lost</option>
            </select>
        </div>
        
        <div>
            <label for="lead_source" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Lead Source</label>
            <select 
                id="lead_source" 
                name="lead_source"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="form" <?php echo $contact['lead_source'] === 'form' ? 'selected' : ''; ?>>Form</option>
                <option value="whatsapp" <?php echo $contact['lead_source'] === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                <option value="ad" <?php echo $contact['lead_source'] === 'ad' ? 'selected' : ''; ?>>Ad</option>
                <option value="referral" <?php echo $contact['lead_source'] === 'referral' ? 'selected' : ''; ?>>Referral</option>
                <option value="social" <?php echo $contact['lead_source'] === 'social' ? 'selected' : ''; ?>>Social</option>
                <option value="import" <?php echo $contact['lead_source'] === 'import' ? 'selected' : ''; ?>>Import</option>
                <option value="web_assessment" <?php echo $contact['lead_source'] === 'web_assessment' ? 'selected' : ''; ?>>Web Assessment</option>
                <option value="other" <?php echo $contact['lead_source'] === 'other' ? 'selected' : ''; ?>>Other</option>
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
                        <label style="display: flex; align-items: center; cursor: pointer; background: white; padding: 6px 12px; border-radius: 12px; border: 2px solid <?php echo in_array($tag['id'], $contactTagIds) ? $tag['color'] : 'transparent'; ?>;">
                            <input 
                                type="checkbox" 
                                name="tags[]" 
                                value="<?php echo $tag['id']; ?>"
                                <?php echo in_array($tag['id'], $contactTagIds) ? 'checked' : ''; ?>
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
        
        <?php if (!empty($customFields)): ?>
            <div style="border-top: 1px solid var(--border-color); padding-top: var(--spacing-lg); margin-top: var(--spacing-md);">
                <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 18px;">Custom Fields</h3>
                <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                    <?php 
                    // Helper function to get label
                    function getFieldLabel($field) {
                        if ($field['field_options']) {
                            $decoded = json_decode($field['field_options'], true);
                            if (is_array($decoded) && isset($decoded['_label'])) {
                                return $decoded['_label'];
                            }
                        }
                        return ucfirst(str_replace('_', ' ', $field['field_name']));
                    }
                    
                    foreach ($customFields as $field): 
                        $fieldKey = 'custom_field_' . $field['id'];
                        $fieldLabel = getFieldLabel($field);
                        $fieldValue = $_POST[$fieldKey] ?? $customFieldValues[$field['id']] ?? '';
                        $fieldOptions = [];
                        if ($field['field_options']) {
                            $decoded = json_decode($field['field_options'], true);
                            if (is_array($decoded)) {
                                unset($decoded['_label']);
                                $fieldOptions = array_values($decoded);
                            }
                        }
                    ?>
                        <div>
                            <label for="<?php echo $fieldKey; ?>" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                                <?php echo htmlspecialchars($fieldLabel); ?>
                                <?php if ($field['is_required']): ?>
                                    <span style="color: #c33;">*</span>
                                <?php endif; ?>
                            </label>
                            
                            <?php if ($field['field_type'] === 'select'): ?>
                                <select 
                                    id="<?php echo $fieldKey; ?>" 
                                    name="<?php echo $fieldKey; ?>"
                                    <?php echo $field['is_required'] ? 'required' : ''; ?>
                                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                                >
                                    <option value="">Select...</option>
                                    <?php foreach ($fieldOptions as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $fieldValue === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['field_type'] === 'textarea'): ?>
                                <textarea 
                                    id="<?php echo $fieldKey; ?>" 
                                    name="<?php echo $fieldKey; ?>"
                                    <?php echo $field['is_required'] ? 'required' : ''; ?>
                                    rows="3"
                                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"
                                ><?php echo htmlspecialchars($fieldValue); ?></textarea>
                            <?php elseif ($field['field_type'] === 'checkbox'): ?>
                                <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                                    <input 
                                        type="checkbox" 
                                        id="<?php echo $fieldKey; ?>" 
                                        name="<?php echo $fieldKey; ?>"
                                        value="1"
                                        <?php echo $fieldValue ? 'checked' : ''; ?>
                                        style="width: 18px; height: 18px;"
                                    >
                                    <span style="color: var(--charcoal-grey);">Yes</span>
                                </label>
                            <?php elseif ($field['field_type'] === 'date'): ?>
                                <input 
                                    type="date" 
                                    id="<?php echo $fieldKey; ?>" 
                                    name="<?php echo $fieldKey; ?>"
                                    <?php echo $field['is_required'] ? 'required' : ''; ?>
                                    value="<?php echo htmlspecialchars($fieldValue); ?>"
                                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                                >
                            <?php elseif ($field['field_type'] === 'number'): ?>
                                <input 
                                    type="number" 
                                    id="<?php echo $fieldKey; ?>" 
                                    name="<?php echo $fieldKey; ?>"
                                    <?php echo $field['is_required'] ? 'required' : ''; ?>
                                    value="<?php echo htmlspecialchars($fieldValue); ?>"
                                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                                >
                            <?php else: // text ?>
                                <input 
                                    type="text" 
                                    id="<?php echo $fieldKey; ?>" 
                                    name="<?php echo $fieldKey; ?>"
                                    <?php echo $field['is_required'] ? 'required' : ''; ?>
                                    value="<?php echo htmlspecialchars($fieldValue); ?>"
                                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                                >
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
            <a href="contact_view.php?id=<?php echo $contact['id']; ?>" style="padding: var(--spacing-sm) var(--spacing-lg); color: var(--charcoal-grey); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">
                Cancel
            </a>
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                Save Changes
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
