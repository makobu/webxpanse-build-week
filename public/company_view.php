<?php
/**
 * Company View Page
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

use CRM\Database;
use CRM\Auth;
use CRM\Modules\Companies;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
\CRM\Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: companies.php');
    exit;
}

$companies = new Companies();
$workspaceScope = new WorkspaceScopeService();
$activeWorkspaceId = $workspaceScope->requireActiveWorkspaceId();
$company = $companies->getById($id);
if (!$company) {
    header('Location: companies.php');
    exit;
}
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!\CRM\Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'assign_primary_contact') {
                $companies->assignPrimaryContact($id, !empty($_POST['primary_contact_id']) ? (int) $_POST['primary_contact_id'] : null);
                $message = 'Primary contact updated.';
            } elseif ($action === 'enrich_company') {
                $result = $companies->enrichCompanyAndDiscoverContacts($id);
                $message = (string) ($result['message'] ?? 'Company enrichment completed.');
            }
            $company = $companies->getById($id) ?? $company;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$contacts = $companies->getLinkedContacts($id);
$deals = Database::query(
    "SELECT id, title, stage, value, currency
     FROM deals
     WHERE workspace_id = ?
       AND company_id = ?
     ORDER BY created_at DESC",
    [$activeWorkspaceId, $id]
);

$pageTitle = $company['name'] . ' - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: var(--spacing-md);">
    <div>
        <h1 style="margin: 0; font-size: 24px; font-weight: 600;"><?php echo htmlspecialchars($company['name']); ?></h1>
        <?php if (!empty($company['industry'])): ?>
            <p style="margin: var(--spacing-xs) 0 0; color: var(--charcoal-grey);"><?php echo htmlspecialchars($company['industry']); ?></p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: var(--spacing-sm);">
        <a href="bulk_email.php?company_id=<?php echo $id; ?>" class="btn">Email Company</a>
        <a href="company_edit.php?id=<?php echo $id; ?>" class="btn">Edit</a>
        <a href="company_delete.php?id=<?php echo $id; ?>" class="btn" style="color: #c33; border-color: #f1b8b8;">Delete</a>
        <a href="companies.php" class="btn">Back to Companies</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom: var(--spacing-lg);"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom: var(--spacing-lg);"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-xl);">
    <div class="card" style="padding: var(--spacing-lg);">
        <h3 style="margin: 0 0 var(--spacing-md); font-size: 16px;">Details</h3>
        <dl style="margin: 0; display: grid; gap: var(--spacing-sm);">
            <?php if (!empty($company['website'])): ?>
                <div>
                    <dt style="font-weight: 500; color: var(--charcoal-grey);">Website</dt>
                    <dd><a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($company['website']); ?></a></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($company['phone'])): ?>
                <div>
                    <dt style="font-weight: 500; color: var(--charcoal-grey);">Phone</dt>
                    <dd><?php echo htmlspecialchars($company['phone']); ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($company['address'])): ?>
                <div>
                    <dt style="font-weight: 500; color: var(--charcoal-grey);">Address</dt>
                    <dd><?php echo nl2br(htmlspecialchars($company['address'])); ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($company['size'])): ?>
                <div>
                    <dt style="font-weight: 500; color: var(--charcoal-grey);">Size</dt>
                    <dd><?php echo htmlspecialchars($company['size']); ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($company['assigned_to_email'])): ?>
                <div>
                    <dt style="font-weight: 500; color: var(--charcoal-grey);">Assigned To</dt>
                    <dd><?php echo htmlspecialchars($company['assigned_to_email']); ?></dd>
                </div>
            <?php endif; ?>
            <div>
                <dt style="font-weight: 500; color: var(--charcoal-grey);">Primary Contact</dt>
                <dd>
                    <?php if (!empty($company['primary_contact_id'])): ?>
                        <a href="contact_view.php?id=<?php echo (int) $company['primary_contact_id']; ?>">
                            <?php
                            $primaryLabel = trim((string) (($company['primary_contact_first_name'] ?? '') . ' ' . ($company['primary_contact_last_name'] ?? '')));
                            echo htmlspecialchars($primaryLabel !== '' ? $primaryLabel : ($company['primary_contact_email'] ?? 'Primary contact'));
                            ?>
                        </a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </div>

    <div class="card" style="padding: var(--spacing-lg);">
        <h3 style="margin: 0 0 var(--spacing-md); font-size: 16px;">Contacts (<?php echo count($contacts); ?>)</h3>
        <?php if (empty($contacts)): ?>
            <p style="color: var(--charcoal-grey); margin: 0;">No contacts linked.</p>
            <a href="contacts_create.php?company_id=<?php echo $id; ?>" class="btn btn-sm" style="margin-top: var(--spacing-sm);">Add Contact</a>
        <?php else: ?>
            <ul style="margin: 0; padding-left: 1.2em;">
                <?php foreach (array_slice($contacts, 0, 10) as $c): ?>
                    <li style="margin-bottom: var(--spacing-xs);">
                        <a href="contact_view.php?id=<?php echo $c['id']; ?>"><?php echo htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['email']); ?></a>
                        <?php if ((int) ($company['primary_contact_id'] ?? 0) === (int) $c['id']): ?>
                            <span style="margin-left: 8px; font-size: 11px; color: #1f7a46; font-weight: 600;">Primary</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($contacts) > 10): ?>
                <p style="margin: var(--spacing-sm) 0 0; font-size: 14px;">... and <?php echo count($contacts) - 10; ?> more</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-xl); margin-top: var(--spacing-xl);">
    <div class="card" style="padding: var(--spacing-lg);">
        <h3 style="margin: 0 0 var(--spacing-md); font-size: 16px;">Primary Contact</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo \CRM\Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="assign_primary_contact">
            <select name="primary_contact_id" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                <option value="">-- None --</option>
                <?php foreach ($contacts as $contactOption): ?>
                    <option value="<?php echo (int) $contactOption['id']; ?>" <?php echo (string) ($company['primary_contact_id'] ?? '') === (string) $contactOption['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(trim(($contactOption['first_name'] ?? '') . ' ' . ($contactOption['last_name'] ?? '')) ?: ($contactOption['email'] ?? 'Contact #' . $contactOption['id'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top: var(--spacing-sm); display: flex; gap: var(--spacing-sm);">
                <button type="submit" class="btn btn-primary">Save Primary Contact</button>
            </div>
        </form>
    </div>

    <div class="card" style="padding: var(--spacing-lg);">
        <h3 style="margin: 0 0 var(--spacing-md); font-size: 16px;">Company Enrichment</h3>
        <p style="margin: 0 0 var(--spacing-md); color: var(--charcoal-grey);">Use provider APIs to refresh company data and auto-create/link discovered contacts.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo \CRM\Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="enrich_company">
            <button type="submit" class="btn btn-primary">Run Company Enrichment</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top: var(--spacing-xl); padding: var(--spacing-lg);">
    <h3 style="margin: 0 0 var(--spacing-md); font-size: 16px;">Deals (<?php echo count($deals); ?>)</h3>
    <?php if (empty($deals)): ?>
        <p style="color: var(--charcoal-grey); margin: 0;">No deals linked.</p>
        <a href="deal_create.php?company_id=<?php echo $id; ?>" class="btn btn-sm" style="margin-top: var(--spacing-sm);">Add Deal</a>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: var(--spacing-sm);">Title</th>
                    <th style="text-align: left; padding: var(--spacing-sm);">Stage</th>
                    <th style="text-align: right; padding: var(--spacing-sm);">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deals as $d): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: var(--spacing-sm);"><a href="deal_view.php?id=<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['title']); ?></a></td>
                        <td style="padding: var(--spacing-sm);"><?php echo htmlspecialchars($d['stage']); ?></td>
                        <td style="padding: var(--spacing-sm); text-align: right;"><?php echo ($d['currency'] ?? 'USD') . ' ' . number_format($d['value'] ?? 0, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
