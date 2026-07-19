<?php
/**
 * Currency Management Page
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
use CRM\Modules\Currencies;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication and admin role
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user();
if (!Authorization::can('crm.currencies.manage', $currentUser)) {
    header('Location: dashboard.php');
    exit;
}

$currenciesModule = new Currencies();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            try {
                $currenciesModule->create([
                    'code' => $_POST['code'] ?? '',
                    'name' => $_POST['name'] ?? '',
                    'symbol' => $_POST['symbol'] ?? '',
                    'symbol_position' => $_POST['symbol_position'] ?? 'before',
                    'decimal_places' => $_POST['decimal_places'] ?? 2,
                    'thousands_separator' => $_POST['thousands_separator'] ?? ',',
                    'decimal_separator' => $_POST['decimal_separator'] ?? '.',
                    'is_active' => isset($_POST['is_active']),
                    'is_default' => isset($_POST['is_default'])
                ]);
                $message = 'Currency created successfully';
                $messageType = 'success';
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($action === 'update') {
            try {
                $id = (int) ($_POST['id'] ?? 0);
                $currenciesModule->update($id, [
                    'name' => $_POST['name'] ?? '',
                    'symbol' => $_POST['symbol'] ?? '',
                    'symbol_position' => $_POST['symbol_position'] ?? 'before',
                    'decimal_places' => $_POST['decimal_places'] ?? 2,
                    'thousands_separator' => $_POST['thousands_separator'] ?? ',',
                    'decimal_separator' => $_POST['decimal_separator'] ?? '.',
                    'is_active' => isset($_POST['is_active']),
                    'is_default' => isset($_POST['is_default'])
                ]);
                $message = 'Currency updated successfully';
                $messageType = 'success';
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($action === 'delete') {
            try {
                $id = (int) ($_POST['id'] ?? 0);
                $currenciesModule->delete($id);
                $message = 'Currency deleted successfully';
                $messageType = 'success';
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($action === 'toggle_active') {
            try {
                $id = (int) ($_POST['id'] ?? 0);
                $currency = Database::queryOne("SELECT is_active FROM currencies WHERE id = ?", [$id]);
                if ($currency) {
                    $currenciesModule->update($id, ['is_active' => !$currency['is_active']]);
                    $message = 'Currency status updated';
                    $messageType = 'success';
                }
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        }
        
        // Redirect to avoid resubmission
        header('Location: currencies.php?msg=' . urlencode($message) . '&type=' . $messageType);
        exit;
    }
}

// Get message from query string
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'] ?? 'success';
}

$currencies = $currenciesModule->getAllCurrencies();
$defaultCurrency = $currenciesModule->getDefault();

$pageTitle = 'Currency Management';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Currency Management</h1>
                <p>Control formatting, active currencies, and workspace defaults.</p>
            </div>
            <div class="page-header-actions">
                <button type="button" onclick="document.getElementById('create-currency-modal').style.display='flex'" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Add Currency
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="premium-banner premium-banner-<?php echo $messageType === 'error' ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($defaultCurrency): ?>
            <div class="premium-status-card" style="margin-bottom: 1rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <span class="premium-status-icon"><i class="fas fa-star"></i></span>
                    <div>
                        <h2 style="margin:0;font-size:1rem;">Default Currency</h2>
                        <p class="premium-result-note">
                            <?php echo htmlspecialchars($defaultCurrency['name']); ?> (<?php echo htmlspecialchars($defaultCurrency['code']); ?>) - <?php echo htmlspecialchars($defaultCurrency['symbol']); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-card table-card-scroll">
        <div class="premium-section-header">
            <div>
                <h2>Currencies</h2>
                <p><?php echo number_format(count($currencies)); ?> configured currencies.</p>
            </div>
        </div>
        <table class="premium-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Symbol</th>
                    <th>Position</th>
                    <th>Decimal Places</th>
                    <th>Example</th>
                    <th>Status</th>
                    <th>Default</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($currencies)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <p>No currencies found.</p>
                                <button type="button" class="btn-premium-primary" onclick="document.getElementById('create-currency-modal').style.display='flex'">
                                    <i class="fas fa-plus"></i>
                                    Add one
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($currencies as $currency): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($currency['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($currency['name']); ?></td>
                            <td><?php echo htmlspecialchars($currency['symbol']); ?></td>
                            <td><?php echo htmlspecialchars($currency['symbol_position']); ?></td>
                            <td><?php echo $currency['decimal_places']; ?></td>
                            <td>
                                <?php 
                                $example = $currenciesModule->formatAmount(1234.56, $currency['code']);
                                echo htmlspecialchars($example);
                                ?>
                            </td>
                            <td>
                                <?php if ($currency['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($currency['is_default']): ?>
                                    <span class="badge badge-primary"><i class="fas fa-star"></i> Default</span>
                                <?php else: ?>
                                    <span style="color: var(--charcoal-grey);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="premium-list-actions">
                                    <button type="button" onclick="editCurrency(<?php echo htmlspecialchars(json_encode($currency)); ?>)" class="btn-premium-secondary btn-premium-sm" title="Edit">
                                        <i class="fas fa-pen"></i>
                                        Edit
                                    </button>
                                    <?php if (!$currency['is_default']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this currency?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $currency['id']; ?>">
                                            <button type="submit" class="btn-premium-danger btn-premium-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Create/Edit Currency Modal -->
<div id="create-currency-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="modal-title">Add Currency</h2>
            <button class="modal-close" onclick="closeCurrencyModal()" title="Close">&times;</button>
        </div>
        <div class="modal-body">
        <form method="POST" id="currency-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="create" id="form-action">
            <input type="hidden" name="id" value="" id="currency-id">
            
            <div class="form-group">
                <label for="code">Currency Code <span style="color: #EF4444;">*</span></label>
                <input type="text" name="code" id="code" required maxlength="3" pattern="[A-Z]{3}" 
                       placeholder="USD" style="text-transform: uppercase;" 
                       oninput="this.value = this.value.toUpperCase()">
                <small style="color: var(--charcoal-grey);">3-letter ISO code (e.g., USD, EUR, GBP)</small>
            </div>
            
            <div class="form-group">
                <label for="name">Currency Name <span style="color: #EF4444;">*</span></label>
                <input type="text" name="name" id="name" required placeholder="US Dollar">
            </div>
            
            <div class="form-group">
                <label for="symbol">Symbol <span style="color: #EF4444;">*</span></label>
                <input type="text" name="symbol" id="symbol" required placeholder="$" maxlength="10">
            </div>
            
            <div class="form-group">
                <label for="symbol_position">Symbol Position</label>
                <select name="symbol_position" id="symbol_position" class="form-control">
                    <option value="before">Before amount ($100)</option>
                    <option value="after">After amount (100 $)</option>
                </select>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                <div class="form-group">
                    <label for="decimal_places">Decimal Places</label>
                    <input type="number" name="decimal_places" id="decimal_places" value="2" min="0" max="4" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="thousands_separator">Thousands Separator</label>
                    <input type="text" name="thousands_separator" id="thousands_separator" value="," maxlength="1" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label for="decimal_separator">Decimal Separator</label>
                <input type="text" name="decimal_separator" id="decimal_separator" value="." maxlength="1" class="form-control">
            </div>
            
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: var(--spacing-xs);">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    Active
                </label>
            </div>
            
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: var(--spacing-xs);">
                    <input type="checkbox" name="is_default" id="is_default">
                    Set as Default Currency
                </label>
                <small style="color: var(--charcoal-grey);">Setting this as default will unset the current default currency</small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-premium-secondary" onclick="closeCurrencyModal()">Cancel</button>
                <button type="submit" class="btn-premium-primary">Save Currency</button>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
function closeCurrencyModal() {
    document.getElementById('create-currency-modal').style.display = 'none';
    document.getElementById('currency-form').reset();
    document.getElementById('form-action').value = 'create';
    document.getElementById('modal-title').textContent = 'Add Currency';
    document.getElementById('code').disabled = false;
}

function editCurrency(currency) {
    document.getElementById('modal-title').textContent = 'Edit Currency';
    document.getElementById('form-action').value = 'update';
    document.getElementById('currency-id').value = currency.id;
    document.getElementById('code').value = currency.code;
    document.getElementById('code').disabled = true;
    document.getElementById('name').value = currency.name;
    document.getElementById('symbol').value = currency.symbol;
    document.getElementById('symbol_position').value = currency.symbol_position;
    document.getElementById('decimal_places').value = currency.decimal_places;
    document.getElementById('thousands_separator').value = currency.thousands_separator;
    document.getElementById('decimal_separator').value = currency.decimal_separator;
    document.getElementById('is_active').checked = currency.is_active == 1;
    document.getElementById('is_default').checked = currency.is_default == 1;
    document.getElementById('create-currency-modal').style.display = 'flex';
}

// Close modal on overlay click
document.getElementById('create-currency-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCurrencyModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
