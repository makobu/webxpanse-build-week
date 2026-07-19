<?php
/**
 * Create Deal Page
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
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\Companies;
use CRM\Modules\Deals;
use CRM\Modules\Currencies;
use CRM\Services\ContactAssignmentAccessService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$dealsModule = new Deals();
$contactsModule = new Contacts();
$companiesModule = new Companies();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$activeCurrencies = $currenciesModule->getActiveCurrencies();
$user = Auth::user();
$error = null;
$success = null;

// Check if contact_id or company_id is passed from contact/company view
$contactId = !empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : null;
$companyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : null;

// Get all contacts and companies for dropdowns
$contacts = $contactsModule->getAll(
    500,
    0,
    null,
    Authorization::can('contacts.view_all', $user) ? 'all' : 'mine_unassigned',
    (int) ($user['id'] ?? 0)
);
$companies = $companiesModule->list(500, 0);

// Get all users for assignment
$users = (new ContactAssignmentAccessService())->getAssignableUsers();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $data = [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                'company_id' => !empty($_POST['company_id']) ? (int) $_POST['company_id'] : null,
                'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
                'stage' => $_POST['stage'] ?? 'prospecting',
                'value' => !empty($_POST['value']) ? (float) $_POST['value'] : 0,
                'probability' => !empty($_POST['probability']) ? (int) $_POST['probability'] : 0,
                'expected_close_date' => !empty($_POST['expected_close_date']) ? $_POST['expected_close_date'] : null,
                'currency' => $_POST['currency'] ?? 'USD',
                'lead_source' => !empty($_POST['lead_source']) ? $_POST['lead_source'] : null,
                'created_by' => (int) ($user['id'] ?? 0)
            ];
            
            $dealId = $dealsModule->create($data);
            
            header('Location: deal_view.php?id=' . $dealId . '&success=created');
            exit;
        } catch (\PDOException $e) {
            error_log('Deal creation database failure: ' . $e->getMessage());
            $error = 'The deal could not be created. Please try again.';
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Deal creation failed: ' . $e->getMessage());
            $error = 'The deal could not be created. Please try again.';
        }
    }
}

$pageTitle = 'Create Deal - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/deals-ui.css">

<div class="page-premium deal-form-page">
    <div class="container deal-form-shell">
        <header class="deal-form-header">
            <div>
                <div class="deal-kicker">Deal</div>
                <h1>Create Deal</h1>
                <p>Add a new sales opportunity</p>
            </div>
            <div class="deal-form-actions-top">
                <a href="deals.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Back to Deals
                </a>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="deal-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

            <section class="deal-form-section">
                <div>
                    <div class="deal-panel-kicker">Opportunity</div>
                    <h2>Core details</h2>
                </div>
                <div class="deal-form-field">
                    <label for="title">Deal Title *</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        required
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        placeholder="e.g., Enterprise License Agreement"
                    >
                </div>
                <div class="deal-form-field">
                    <label for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        rows="4"
                        placeholder="Describe the deal opportunity..."
                    ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>
            </section>

            <section class="deal-form-section">
                <div>
                    <div class="deal-panel-kicker">Relationship</div>
                    <h2>Contact, company, and owner</h2>
                </div>
                <div class="deal-form-grid">
                    <div class="deal-form-field">
                        <label for="contact_id">Contact</label>
                        <select id="contact_id" name="contact_id">
                            <option value="">Select Contact...</option>
                            <?php foreach ($contacts as $contact):
                                $selected = (($_POST['contact_id'] ?? $contactId ?? '') == $contact['id']);
                            ?>
                                <option value="<?php echo $contact['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '') . ' - ' . ($contact['company'] ?? $contact['email'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="deal-form-field">
                        <label for="company_id">Company</label>
                        <select id="company_id" name="company_id">
                            <option value="">Select Company...</option>
                            <?php foreach ($companies as $co):
                                $selected = (($_POST['company_id'] ?? $companyId ?? '') == $co['id']);
                            ?>
                                <option value="<?php echo $co['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo htmlspecialchars($co['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="deal-form-field">
                        <label for="assigned_to">Assigned To</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo (($_POST['assigned_to'] ?? '') == $u['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="deal-form-section">
                <div>
                    <div class="deal-panel-kicker">Commercials</div>
                    <h2>Stage, value, and probability</h2>
                </div>
                <div class="deal-form-grid deal-form-grid--three">
                    <div class="deal-form-field">
                        <label for="stage">Stage *</label>
                        <select id="stage" name="stage" required>
                            <option value="prospecting" <?php echo (($_POST['stage'] ?? 'prospecting') === 'prospecting') ? 'selected' : ''; ?>>Prospecting</option>
                            <option value="qualification" <?php echo (($_POST['stage'] ?? 'prospecting') === 'qualification') ? 'selected' : ''; ?>>Qualification</option>
                            <option value="proposal" <?php echo (($_POST['stage'] ?? 'prospecting') === 'proposal') ? 'selected' : ''; ?>>Proposal</option>
                            <option value="negotiation" <?php echo (($_POST['stage'] ?? 'prospecting') === 'negotiation') ? 'selected' : ''; ?>>Negotiation</option>
                            <option value="closed_won" <?php echo (($_POST['stage'] ?? 'prospecting') === 'closed_won') ? 'selected' : ''; ?>>Won</option>
                            <option value="closed_lost" <?php echo (($_POST['stage'] ?? 'prospecting') === 'closed_lost') ? 'selected' : ''; ?>>Lost</option>
                        </select>
                    </div>

                    <div class="deal-form-field">
                        <label for="value">Deal Value</label>
                        <div class="deal-money-input">
                            <select id="currency" name="currency">
                                <?php
                                $selectedCurrency = $_POST['currency'] ?? ($defaultCurrency['code'] ?? 'USD');
                                foreach ($activeCurrencies as $currency):
                                ?>
                                    <option value="<?php echo htmlspecialchars($currency['code']); ?>" <?php echo $selectedCurrency === $currency['code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($currency['code']); ?> (<?php echo htmlspecialchars($currency['symbol']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input
                                type="number"
                                id="value"
                                name="value"
                                step="0.01"
                                min="0"
                                value="<?php echo htmlspecialchars($_POST['value'] ?? '0'); ?>"
                                placeholder="0.00"
                            >
                        </div>
                    </div>

                    <div class="deal-form-field">
                        <label for="probability">Probability (%)</label>
                        <input
                            type="number"
                            id="probability"
                            name="probability"
                            min="0"
                            max="100"
                            value="<?php echo htmlspecialchars($_POST['probability'] ?? '0'); ?>"
                            placeholder="0"
                        >
                    </div>
                </div>
            </section>

            <section class="deal-form-section">
                <div>
                    <div class="deal-panel-kicker">Timing</div>
                    <h2>Close timing and source</h2>
                </div>
                <div class="deal-form-grid">
                    <div class="deal-form-field">
                        <label for="expected_close_date">Expected Close Date</label>
                        <input
                            type="date"
                            id="expected_close_date"
                            name="expected_close_date"
                            value="<?php echo htmlspecialchars($_POST['expected_close_date'] ?? ''); ?>"
                        >
                    </div>

                    <div class="deal-form-field">
                        <label for="lead_source">Lead Source</label>
                        <select id="lead_source" name="lead_source">
                            <option value="">Select Source...</option>
                            <option value="form" <?php echo (($_POST['lead_source'] ?? '') === 'form') ? 'selected' : ''; ?>>Form</option>
                            <option value="whatsapp" <?php echo (($_POST['lead_source'] ?? '') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                            <option value="ad" <?php echo (($_POST['lead_source'] ?? '') === 'ad') ? 'selected' : ''; ?>>Ad</option>
                            <option value="referral" <?php echo (($_POST['lead_source'] ?? '') === 'referral') ? 'selected' : ''; ?>>Referral</option>
                            <option value="social" <?php echo (($_POST['lead_source'] ?? '') === 'social') ? 'selected' : ''; ?>>Social</option>
                            <option value="cold_call" <?php echo (($_POST['lead_source'] ?? '') === 'cold_call') ? 'selected' : ''; ?>>Cold Call</option>
                            <option value="other" <?php echo (($_POST['lead_source'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </section>

            <div class="deal-form-footer">
                <a href="deals.php" class="btn-premium-secondary">Cancel</a>
                <button type="submit" class="btn-premium-primary">Create Deal</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
