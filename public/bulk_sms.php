<?php
/**
 * Bulk SMS Page
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
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Tags;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$smsInstaller = new WorkspaceSkillInstallService();
if ((new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)
    || (!Authorization::isSuperAdmin($user) && !$smsInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL))) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL));
    exit;
}
if (!Authorization::can('sms.bulk_send', $user)) {
    http_response_code(403);
    die('Access denied: Bulk SMS permission is required.');
}

$tagsModule = new Tags();
$allTags = $tagsModule->getAll();
$users = Database::query(
    "SELECT DISTINCT u.id, u.email
     FROM users u
     INNER JOIN workspace_memberships wm
        ON wm.user_id = u.id
       AND wm.workspace_id = ?
       AND wm.membership_status = 'active'
     ORDER BY u.email ASC",
    [$workspaceId]
);
$smsBatchKey = bin2hex(random_bytes(16));

$preselectedContactIds = [];
if (!empty($_GET['contact_ids'])) {
    $preselectedContactIds = array_map('intval', array_filter(explode(',', $_GET['contact_ids'])));
}

$pageTitle = 'Bulk SMS - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Bulk SMS</h1>
                <p>Send SMS to multiple contacts by tag or filter</p>
            </div>
            <div class="page-header-actions">
                <a href="inbox.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Inbox
                </a>
            </div>
        </div>

        <div class="filters-card" style="margin-bottom: 1.5rem;">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem;">1. Select Recipients</h3>
            <div class="filters-form" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem;">
                <div class="filter-group">
                    <label for="filter_tag">Tag</label>
                    <select id="filter_tag" name="tag">
                        <option value="">All Tags</option>
                        <?php foreach ($allTags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_stage">Stage</label>
                    <select id="filter_stage" name="stage">
                        <option value="">All Stages</option>
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="qualified">Qualified</option>
                        <option value="proposal">Proposal</option>
                        <option value="negotiation">Negotiation</option>
                        <option value="won">Won</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_search">Search</label>
                    <input type="text" id="filter_search" name="search" placeholder="Name, email, company...">
                </div>
                <div class="filter-group">
                    <label for="filter_assigned">Assigned To</label>
                    <select id="filter_assigned" name="assigned_to">
                        <option value="">All</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['email']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="align-self: end;">
                    <button type="button" id="btn-preview" class="btn-premium-primary">
                        <i class="fas fa-filter"></i> Preview
                    </button>
                </div>
            </div>
            <input type="hidden" id="contact_ids" name="contact_ids" value="<?php echo htmlspecialchars(implode(',', $preselectedContactIds)); ?>">
        </div>

        <div id="preview-panel" class="filters-card" style="display: none; margin-bottom: 1.5rem;">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem;">2. Preview</h3>
            <p id="preview-text" style="color: var(--charcoal-grey); margin-bottom: 0.5rem;"></p>
            <div id="preview-sample" style="font-size: 0.875rem; color: #64748b; max-height: 120px; overflow-y: auto;"></div>
        </div>

        <div id="compose-panel" class="filters-card" style="display: none;">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem;">3. Compose</h3>
            <form id="bulk-sms-form" style="display: flex; flex-direction: column; gap: 1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="send_sms">
                <input type="hidden" id="sms-idempotency-key" name="idempotency_key" value="<?php echo htmlspecialchars($smsBatchKey); ?>">
                <div id="hidden-filters"></div>
                <div>
                    <label for="message" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Message *</label>
                    <textarea id="message" name="message" rows="4" required maxlength="1600" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"></textarea>
                    <p style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;"><span id="char-count">0</span>/160 characters (1 SMS segment)</p>
                </div>
                <label style="display: flex; align-items: flex-start; gap: 0.6rem; font-size: 0.875rem; color: #475569;">
                    <input type="checkbox" id="sms-consent-confirmed" name="sms_consent_confirmed" value="1" required style="margin-top: 0.2rem;">
                    <span>I confirm these recipients have permission to receive this SMS and that opt-outs must be honored.</span>
                </label>
                <div>
                    <button type="submit" id="btn-send" class="btn-premium-primary">
                        <i class="fas fa-sms"></i> Send to <span id="send-count">0</span> Contacts
                    </button>
                </div>
            </form>
        </div>

        <div id="success-panel" style="display: none; background: #d4edda; border: 1px solid #c3e6cb; padding: 1.5rem; border-radius: 8px; margin-top: 1rem;">
            <h3 style="margin: 0 0 0.5rem 0; color: #155724;">Success</h3>
            <p id="success-text" style="margin: 0; color: #155724;"></p>
            <a href="inbox.php" class="btn-premium-primary" style="margin-top: 1rem; display: inline-block;">View Inbox</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const contactIdsParam = urlParams.get('contact_ids');
    if (contactIdsParam) {
        document.getElementById('contact_ids').value = contactIdsParam;
    }

    const btnPreview = document.getElementById('btn-preview');
    const previewPanel = document.getElementById('preview-panel');
    const composePanel = document.getElementById('compose-panel');
    const successPanel = document.getElementById('success-panel');
    const form = document.getElementById('bulk-sms-form');
    const hiddenFilters = document.getElementById('hidden-filters');
    const messageInput = document.getElementById('message');
    const charCount = document.getElementById('char-count');

    messageInput.addEventListener('input', function() {
        charCount.textContent = this.value.length;
    });

    function getFilters() {
        const filters = {};
        const tag = document.getElementById('filter_tag').value;
        const stage = document.getElementById('filter_stage').value;
        const search = document.getElementById('filter_search').value.trim();
        const assigned = document.getElementById('filter_assigned').value;
        const contactIds = document.getElementById('contact_ids').value;
        if (tag) filters.tag_id = tag;
        if (stage) filters.stage = stage;
        if (search) filters.search = search;
        if (assigned) filters.assigned_to = assigned;
        if (contactIds) {
            filters.contact_ids = contactIds.split(',').map(id => parseInt(id)).filter(id => id > 0);
        }
        return filters;
    }

    btnPreview.addEventListener('click', async function() {
        const filters = getFilters();
        if (!filters.tag_id && !filters.stage && !filters.search && !filters.assigned_to && (!filters.contact_ids || filters.contact_ids.length === 0)) {
            alert('Please select at least one filter or enter contact IDs.');
            return;
        }

        btnPreview.disabled = true;
        btnPreview.textContent = 'Loading...';

        try {
            const formData = new FormData();
            formData.append('action', 'preview');
            formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
            formData.append('filters', JSON.stringify(filters));

            const response = await fetch('../api/bulk_messaging.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                document.getElementById('preview-text').textContent = data.with_phone + ' contacts have phone numbers (of ' + data.total + ' matching filters).';
                const sample = data.sample || [];
                document.getElementById('preview-sample').innerHTML = sample.length
                    ? '<ul style="margin: 0; padding-left: 1.25rem;">' + sample.map(c => '<li>' + (c.first_name + ' ' + c.last_name) + ' - ' + (c.phone || 'no phone') + '</li>').join('') + '</ul>'
                    : 'No sample.';
                document.getElementById('send-count').textContent = data.with_phone;
                previewPanel.style.display = 'block';
                composePanel.style.display = 'block';

                hiddenFilters.innerHTML = '';
                Object.keys(filters).forEach(k => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'filters[' + k + ']';
                    inp.value = Array.isArray(filters[k]) ? JSON.stringify(filters[k]) : filters[k];
                    hiddenFilters.appendChild(inp);
                });
            } else {
                alert(data.error || 'Preview failed');
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }
        btnPreview.disabled = false;
        btnPreview.innerHTML = '<i class="fas fa-filter"></i> Preview';
    });

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const filters = getFilters();
        const message = document.getElementById('message').value.trim();

        if (!message) {
            alert('Message is required.');
            return;
        }
        if (!document.getElementById('sms-consent-confirmed').checked) {
            alert('Confirm recipient permission before sending.');
            return;
        }

        const btn = document.getElementById('btn-send');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        try {
            const formData = new FormData();
            formData.append('action', 'send_sms');
            formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
            formData.append('message', message);
            formData.append('filters', JSON.stringify(filters));
            formData.append('sms_consent_confirmed', '1');
            formData.append('idempotency_key', document.getElementById('sms-idempotency-key').value);

            const response = await fetch('../api/bulk_messaging.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success && data.result) {
                const r = data.result;
                document.getElementById('success-text').textContent = 'Queued ' + r.queued + ' SMS(s).' + (r.skipped > 0 ? ' Skipped ' + r.skipped + '.' : '');
                composePanel.style.display = 'none';
                successPanel.style.display = 'block';
                if (window.crypto && window.crypto.randomUUID) {
                    document.getElementById('sms-idempotency-key').value = window.crypto.randomUUID();
                }
            } else {
                alert(data.error || 'Send failed');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sms"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
            }
        } catch (e) {
            alert('Error: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sms"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
        }
    });

    if (document.getElementById('contact_ids').value.trim()) {
        btnPreview.click();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
