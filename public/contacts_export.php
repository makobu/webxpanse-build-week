<?php
/**
 * Export Contacts Page
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
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$contactsModule = new Contacts();
$workspaceScope = new WorkspaceScopeService();
$presentationGuard = new PresentationWorkspaceGuardService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canViewAllContacts = Authorization::can('contacts.view_all', $user);

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    if ($presentationGuard->isBlocked('exports')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $presentationGuard->message('exports');
        exit;
    }

    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $stage = $_GET['stage'] ?? '';
    $ownerScope = (string) ($_GET['owner_scope'] ?? 'mine_unassigned');
    if (!in_array($ownerScope, ['mine_unassigned', 'all'], true)) {
        $ownerScope = 'mine_unassigned';
    }
    if ($ownerScope === 'all' && !$canViewAllContacts) {
        $ownerScope = 'mine_unassigned';
    }
    
    // Build query
    $workspaceClause = $workspaceScope->workspaceClause();
    $where = [$workspaceClause['sql']];
    $params = $workspaceClause['params'];
    
    if ($search) {
        $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if ($stage) {
        $where[] = "stage = ?";
        $params[] = $stage;
    }

    $ownerScopeClause = $contactsModule->buildOwnerScopeClause($ownerScope, $userId, '');
    if ($ownerScopeClause['sql'] !== '') {
        $where[] = $ownerScopeClause['sql'];
        $params = array_merge($params, $ownerScopeClause['params']);
    }
    
    $sql = "SELECT first_name, last_name, email, phone, company, lead_source, stage, lead_score, created_at,
                   job_title, location, company_website, company_size, company_industry, company_description,
                   company_founded, company_revenue, linkedin_url, twitter_url, timezone, email_verified,
                   enrichment_score, last_enriched_at,
                   (
                       SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                       FROM tag_assignments ta
                       INNER JOIN tags t ON t.id = ta.tag_id AND t.workspace_id = contacts.workspace_id
                       WHERE ta.entity_type = 'contact' AND ta.entity_id = contacts.id
                   ) AS tags
            FROM contacts";
    
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="contacts_export_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Write header row
    fputcsv($output, [
        'First Name',
        'Last Name',
        'Email',
        'Phone',
        'Company',
        'Job Title',
        'Location',
        'Company Website',
        'Company Size',
        'Industry',
        'Company Description',
        'Founded Year',
        'Revenue',
        'LinkedIn URL',
        'Twitter URL',
        'Timezone',
        'Email Verified',
        'Lead Source',
        'Stage',
        'Composite Lead Score',
        'Enrichment Score',
        'Last Enriched At',
        'Tags',
        'Created At'
    ]);
    
    $stmt = Database::getInstance()->prepare($sql);
    $stmt->execute($params);

    // Stream data rows so large exports do not have to be fully materialized in PHP memory.
    while ($contact = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $contact['first_name'] ?? '',
            $contact['last_name'] ?? '',
            $contact['email'] ?? '',
            $contact['phone'] ?? '',
            $contact['company'] ?? '',
            $contact['job_title'] ?? '',
            $contact['location'] ?? '',
            $contact['company_website'] ?? '',
            $contact['company_size'] ?? '',
            $contact['company_industry'] ?? '',
            $contact['company_description'] ?? '',
            $contact['company_founded'] ?? '',
            $contact['company_revenue'] ?? '',
            $contact['linkedin_url'] ?? '',
            $contact['twitter_url'] ?? '',
            $contact['timezone'] ?? '',
            isset($contact['email_verified']) && $contact['email_verified'] ? 'Yes' : 'No',
            $contact['lead_source'] ?? '',
            $contact['stage'] ?? '',
            $contact['lead_score'] ?? 0,
            $contact['enrichment_score'] ?? 0,
            $contact['last_enriched_at'] ?? '',
            $contact['tags'] ?? '',
            $contact['created_at'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// Get contact counts for info
$workspaceClause = $workspaceScope->workspaceClause();
$totalContacts = (int) (Database::queryOne(
    "SELECT COUNT(*) as count FROM contacts WHERE {$workspaceClause['sql']}",
    $workspaceClause['params']
)['count'] ?? 0);

$pageTitle = 'Export Contacts - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Export Contacts</h1>
    <p style="color: var(--charcoal-grey);">Export your contacts to a CSV file</p>
</div>

<div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; max-width: 600px;">
    <div style="margin-bottom: var(--spacing-lg);">
        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Total Contacts</div>
        <div style="font-size: 32px; font-weight: 600; color: var(--midnight-black);">
            <?php echo number_format($totalContacts); ?>
        </div>
    </div>
    
    <form method="GET" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <input type="hidden" name="export" value="1">
        
        <div>
            <label for="search" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Search (Optional)</label>
            <input 
                type="text" 
                id="search" 
                name="search" 
                value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                placeholder="Filter by name, email, or company..."
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
        </div>
        
        <div>
            <label for="stage" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Stage (Optional)</label>
            <select 
                id="stage" 
                name="stage" 
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="">All Stages</option>
                <option value="new" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'new') ? 'selected' : ''; ?>>New</option>
                <option value="contacted" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'contacted') ? 'selected' : ''; ?>>Contacted</option>
                <option value="qualified" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'qualified') ? 'selected' : ''; ?>>Qualified</option>
                <option value="proposal" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'proposal') ? 'selected' : ''; ?>>Proposal</option>
                <option value="negotiation" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'negotiation') ? 'selected' : ''; ?>>Negotiation</option>
                <option value="won" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'won') ? 'selected' : ''; ?>>Won</option>
                <option value="lost" <?php echo (isset($_GET['stage']) && $_GET['stage'] === 'lost') ? 'selected' : ''; ?>>Lost</option>
            </select>
        </div>
        
        <div style="background: var(--light-grey); border-radius: 4px; padding: var(--spacing-md);">
            <div style="color: var(--midnight-black); font-size: 14px; font-weight: 500; margin-bottom: var(--spacing-xs);">
                Export Format:
            </div>
            <div style="color: var(--charcoal-grey); font-size: 12px;">
                The exported CSV will include all contact fields including: Basic Info (First Name, Last Name, Email, Phone, Company), Professional Info (Job Title, Location, Company Website, Company Size, Industry, Company Description, Founded Year, Revenue, LinkedIn URL, Twitter URL, Timezone, Email Verified), Lead Info (Lead Source, Stage, Composite Lead Score), Enrichment Info (Enrichment Score, Last Enriched At), Tags, plus Created At.
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
                Export to CSV
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
