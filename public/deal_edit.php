<?php
/**
 * Edit Deal Page
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
use CRM\Modules\Deals;
use CRM\Modules\Currencies;
use CRM\Modules\Products;
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
$dealId = (int) ($_GET['id'] ?? 0);

if (!$dealId) {
    header('Location: deals.php');
    exit;
}

$deal = $dealsModule->getById($dealId);

if (!$deal) {
    header('Location: deals.php');
    exit;
}

$error = null;
$conflict = null;
$dealFieldLabels = [
    'title' => 'Deal title',
    'description' => 'Description',
    'contact_id' => 'Contact',
    'company_id' => 'Company',
    'assigned_to' => 'Assigned to',
    'stage' => 'Stage',
    'value' => 'Value',
    'probability' => 'Probability',
    'expected_close_date' => 'Expected close date',
    'actual_close_date' => 'Actual close date',
    'currency' => 'Currency',
    'lead_source' => 'Lead source',
    'quote_number' => 'Quote number',
    'quote_valid_until' => 'Quote valid until',
];

// Get all contacts for dropdown
$currentUser = Auth::user();
$contacts = $contactsModule->getAll(
    500,
    0,
    null,
    Authorization::can('contacts.view_all', $currentUser) ? 'all' : 'mine_unassigned',
    (int) ($currentUser['id'] ?? 0)
);
$companies = $companiesModule->list(500, 0);
$productsModule = new Products();
$products = $productsModule->list();
$lineItems = $deal['line_items'] ?? [];

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
                'actual_close_date' => !empty($_POST['actual_close_date']) ? $_POST['actual_close_date'] : null,
                'currency' => $_POST['currency'] ?? 'USD',
                'lead_source' => !empty($_POST['lead_source']) ? $_POST['lead_source'] : null,
                'quote_number' => $_POST['quote_number'] ?? null,
                'quote_valid_until' => !empty($_POST['quote_valid_until']) ? $_POST['quote_valid_until'] : null,
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ];

            Database::beginTransaction();
            $dealsModule->update($dealId, $data);
            Database::commit();

            header('Location: deal_view.php?id=' . $dealId . '&success=updated');
            exit;
        } catch (ConcurrencyConflictException $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $conflict = $e;
            $deal = $dealsModule->getById($dealId) ?: $deal;
            $lineItems = $deal['line_items'] ?? $lineItems;
            $error = $e->getMessage();
        } catch (\PDOException $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            error_log('Deal update database failure: ' . $e->getMessage());
            $error = 'The deal could not be updated. Please try again.';
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            error_log('Deal update failed: ' . $e->getMessage());
            $error = 'The deal could not be updated. Please try again.';
        }
    }
}

$pageTitle = 'Edit Deal - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/deals-ui.css">

<div class="page-premium deal-form-page">
    <div class="container deal-form-shell">
        <header class="deal-form-header">
            <div>
                <div class="deal-kicker">Deal</div>
                <h1>Edit Deal</h1>
                <p>Update deal information</p>
            </div>
            <div class="deal-form-actions-top">
                <a href="<?php echo getBasePath(); ?>/deal_view.php?id=<?php echo $dealId; ?>" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Back to Deal
                </a>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($conflict): ?>
            <div class="deal-conflict-banner">
                <h3>Someone updated this deal while you were editing.</h3>
                <p class="deal-no-margin">Review the current saved values against your attempted values. Reload latest or save again to overwrite with the values currently in the form.</p>
                <?php $conflictRows = Concurrency::conflictDiffRows($conflict, $dealFieldLabels); ?>
                <?php if ($conflictRows): ?>
                    <div class="table-card-scroll">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Current saved value</th>
                                    <th>Your attempted value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($conflictRows as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['current'] !== '' ? $row['current'] : '-'); ?></td>
                                        <td><?php echo htmlspecialchars($row['submitted'] !== '' ? $row['submitted'] : '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="deal-action-row">
                    <a href="deal_edit.php?id=<?php echo (int) $dealId; ?>" class="btn-premium-secondary">Reload latest</a>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="deal-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($deal['lock_version'] ?? 0); ?>">

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
                        value="<?php echo htmlspecialchars($_POST['title'] ?? $deal['title']); ?>"
                    >
                </div>
                <div class="deal-form-field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $deal['description'] ?? ''); ?></textarea>
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
                                $selected = (($_POST['contact_id'] ?? $deal['contact_id']) == $contact['id']);
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
                                $selected = (($_POST['company_id'] ?? $deal['company_id']) == $co['id']);
                            ?>
                                <option value="<?php echo $co['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo htmlspecialchars($co['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="deal-form-field">
                        <label for="assigned_to">Assigned To</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u):
                                $selected = (($_POST['assigned_to'] ?? $deal['assigned_to']) == $u['id']);
                            ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
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
                    <h2>Stage, value, and quote controls</h2>
                </div>
                <div class="deal-form-grid deal-form-grid--three">
                    <div class="deal-form-field">
                        <label for="stage">Stage *</label>
                        <select id="stage" name="stage" required>
                            <option value="prospecting" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'prospecting') ? 'selected' : ''; ?>>Prospecting</option>
                            <option value="qualification" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'qualification') ? 'selected' : ''; ?>>Qualification</option>
                            <option value="proposal" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'proposal') ? 'selected' : ''; ?>>Proposal</option>
                            <option value="negotiation" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'negotiation') ? 'selected' : ''; ?>>Negotiation</option>
                            <option value="closed_won" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'closed_won') ? 'selected' : ''; ?>>Won</option>
                            <option value="closed_lost" <?php echo (($_POST['stage'] ?? $deal['stage']) === 'closed_lost') ? 'selected' : ''; ?>>Lost</option>
                        </select>
                    </div>

                    <div class="deal-form-field">
                        <label for="value">Deal Value</label>
                        <div class="deal-money-input">
                            <select id="currency" name="currency">
                                <?php
                                $selectedCurrency = $_POST['currency'] ?? ($deal['currency'] ?? ($defaultCurrency['code'] ?? 'USD'));
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
                                value="<?php echo htmlspecialchars($_POST['value'] ?? $deal['value'] ?? '0'); ?>"
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
                            value="<?php echo htmlspecialchars($_POST['probability'] ?? $deal['probability'] ?? '0'); ?>"
                        >
                    </div>
                </div>

                <div class="deal-form-grid">
                    <div class="deal-form-field">
                        <label for="quote_number">Quote Number</label>
                        <input
                            type="text"
                            id="quote_number"
                            name="quote_number"
                            value="<?php echo htmlspecialchars($_POST['quote_number'] ?? $deal['quote_number'] ?? ''); ?>"
                            placeholder="e.g. Q-2024-001"
                        >
                    </div>
                    <div class="deal-form-field">
                        <label for="quote_valid_until">Quote Valid Until</label>
                        <input
                            type="date"
                            id="quote_valid_until"
                            name="quote_valid_until"
                            value="<?php echo htmlspecialchars($_POST['quote_valid_until'] ?? ($deal['quote_valid_until'] ? date('Y-m-d', strtotime($deal['quote_valid_until'])) : '')); ?>"
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
                            value="<?php echo htmlspecialchars($_POST['expected_close_date'] ?? ($deal['expected_close_date'] ? date('Y-m-d', strtotime($deal['expected_close_date'])) : '')); ?>"
                        >
                    </div>

                    <div class="deal-form-field">
                        <label for="actual_close_date">Actual Close Date</label>
                        <input
                            type="date"
                            id="actual_close_date"
                            name="actual_close_date"
                            value="<?php echo htmlspecialchars($_POST['actual_close_date'] ?? ($deal['actual_close_date'] ? date('Y-m-d', strtotime($deal['actual_close_date'])) : '')); ?>"
                        >
                    </div>

                    <div class="deal-form-field">
                        <label for="lead_source">Lead Source</label>
                        <select id="lead_source" name="lead_source">
                            <option value="">Select Source...</option>
                            <option value="form" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'form') ? 'selected' : ''; ?>>Form</option>
                            <option value="whatsapp" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                            <option value="ad" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'ad') ? 'selected' : ''; ?>>Ad</option>
                            <option value="referral" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'referral') ? 'selected' : ''; ?>>Referral</option>
                            <option value="social" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'social') ? 'selected' : ''; ?>>Social</option>
                            <option value="cold_call" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'cold_call') ? 'selected' : ''; ?>>Cold Call</option>
                            <option value="other" <?php echo (($_POST['lead_source'] ?? $deal['lead_source'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="deal-form-section" id="line-items-container">
                <div>
                    <div class="deal-panel-kicker">Line items</div>
                    <h2>Products and custom charges</h2>
                    <p class="deal-panel-subtitle">Add products or custom line items. Total updates automatically.</p>
                </div>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Product/Description</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Discount %</th>
                                <th class="text-right">Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="line-items-tbody">
                            <?php if (empty($lineItems)): ?>
                                <tr>
                                    <td colspan="6">No line items yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lineItems as $li): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($li['product_name'] ?? $li['description'] ?? '-'); ?></td>
                                        <td class="text-right"><?php echo number_format($li['quantity'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($li['unit_price'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($li['discount_percent'], 1); ?>%</td>
                                        <td class="text-right"><?php echo number_format($li['total'], 2); ?></td>
                                        <td>
                                            <button type="button" class="deal-danger-button btn-remove-line" data-id="<?php echo $li['id']; ?>">Remove</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="add-line-form" class="deal-line-toolbar">
                    <div class="deal-line-field">
                        <label for="new_product_id">Product</label>
                        <select id="new_product_id">
                            <option value="">Custom</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" data-price="<?php echo (float)($p['unit_price'] ?? 0); ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="deal-line-field">
                        <label for="new_description">Description</label>
                        <input type="text" id="new_description" placeholder="Description">
                    </div>
                    <div class="deal-line-field">
                        <label for="new_quantity">Qty</label>
                        <input type="number" id="new_quantity" value="1" min="0.01" step="0.01">
                    </div>
                    <div class="deal-line-field">
                        <label for="new_unit_price">Unit Price</label>
                        <input type="number" id="new_unit_price" value="0" min="0" step="0.01">
                    </div>
                    <div class="deal-line-field">
                        <label for="new_discount">Discount %</label>
                        <input type="number" id="new_discount" value="0" min="0" max="100" step="0.1">
                    </div>
                    <button type="button" id="add-line-btn" class="btn-premium-secondary">Add Line</button>
                </div>
                <div class="deal-total-row">
                    <span>Deal Total:</span>
                    <strong><span id="deal-total"><?php echo number_format($deal['value'] ?? 0, 2); ?></span> <?php echo htmlspecialchars($deal['currency'] ?? 'USD'); ?></strong>
                </div>
            </section>

            <div class="deal-form-footer">
                <a href="<?php echo getBasePath(); ?>/deal_quote_pdf.php?id=<?php echo $dealId; ?>" target="_blank" class="btn-premium-secondary">
                    <i class="fas fa-file-pdf" aria-hidden="true"></i>
                    Generate Quote PDF
                </a>
                <a href="<?php echo getBasePath(); ?>/invoice_create.php?deal_id=<?php echo $dealId; ?>&document_type=quote" class="btn-premium-secondary">
                    Create Quote
                </a>
                <a href="<?php echo getBasePath(); ?>/deal_view.php?id=<?php echo $dealId; ?>" class="btn-premium-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn-premium-primary">Update Deal</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const dealId = <?php echo $dealId; ?>;
    const csrfToken = '<?php echo addslashes(Security::getCsrfToken()); ?>';
    const apiBase = '<?php echo addslashes(str_replace("/public", "", getBasePath())); ?>';

    document.getElementById('add-line-btn')?.addEventListener('click', function() {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('deal_id', dealId);
        formData.append('csrf_token', csrfToken);
        formData.append('product_id', document.getElementById('new_product_id').value || '');
        formData.append('description', document.getElementById('new_description').value || '');
        formData.append('quantity', document.getElementById('new_quantity').value || 1);
        formData.append('unit_price', document.getElementById('new_unit_price').value || 0);
        formData.append('discount_percent', document.getElementById('new_discount').value || 0);

        fetch(apiBase + '/api/deal_line_items.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('deal-total').textContent = parseFloat(data.deal_value).toFixed(2);
                    location.reload();
                } else {
                    alert(data.error || 'Failed to add line item');
                }
            });
    });

    document.getElementById('new_product_id')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt?.dataset?.price) {
            document.getElementById('new_unit_price').value = opt.dataset.price;
        }
    });

    document.querySelectorAll('.btn-remove-line').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Remove this line item?')) return;
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('deal_id', dealId);
            formData.append('csrf_token', csrfToken);
            formData.append('line_item_id', this.dataset.id);

            fetch(apiBase + '/api/deal_line_items.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) location.reload();
                });
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
