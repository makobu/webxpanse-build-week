<?php
/**
 * Bulk WhatsApp Page
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
use CRM\Security;
use CRM\Modules\Tags;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WhatsAppService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
(new WorkspaceCommunicationGateService())->enforceWebChannelRuntime($workspaceId, 'whatsapp', $user);

$tagsModule = new Tags();
$allTags = $tagsModule->getAll();
$users = Database::query("SELECT id, email FROM users ORDER BY email ASC");

$preselectedContactIds = [];
if (!empty($_GET['contact_ids'])) {
    $preselectedContactIds = array_map('intval', array_filter(explode(',', $_GET['contact_ids'])));
}

$whatsappTemplates = [];
try {
    $whatsappService = new WhatsAppService();
    $whatsappTemplates = $whatsappService->getTemplates();
} catch (\Exception $e) {
    // WhatsApp not configured or templates unavailable
}

$pageTitle = 'Bulk WhatsApp - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Bulk WhatsApp</h1>
                <p>Send WhatsApp messages to multiple contacts by tag or filter</p>
            </div>
            <div class="page-header-actions">
                <a href="whatsapp_messages.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i> Back to WhatsApp
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
            <form id="bulk-whatsapp-form" style="display: flex; flex-direction: column; gap: 1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="send_whatsapp">
                <input type="hidden" id="template_structure" name="template_structure" value="">
                <div id="hidden-filters"></div>
                <div>
                    <label for="message_type" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Message Type</label>
                    <select id="message_type" name="message_type" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="text">Text (within 24h of last message)</option>
                        <?php if (!empty($whatsappTemplates)): ?>
                            <option value="template">Template (business-initiated)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div id="text-message-group">
                    <label for="message" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Message *</label>
                    <textarea id="message" name="message" rows="4" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"></textarea>
                </div>
                <div id="template-group" style="display: none;">
                    <div style="margin-bottom: 1rem;">
                        <label for="template_name" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Template *</label>
                        <div style="display: flex; gap: 0.5rem; align-items: flex-start;">
                            <input type="text" id="template_name" name="template_name" placeholder="Select a template or enter name manually"
                                style="flex: 1; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                            <button type="button" id="browse-templates-btn" style="padding: 0.5rem 1rem; background: #25D366; color: white; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; white-space: nowrap;">
                                <i class="fas fa-search" style="margin-right: 6px;"></i>Browse Templates
                            </button>
                        </div>
                        <div id="selected-template-info" style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px; display: none;">
                            <strong>Selected:</strong> <span id="selected-template-name"></span> (<span id="selected-template-language"></span>)
                        </div>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label for="language_code" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Language Code</label>
                        <input type="text" id="language_code" name="language_code" value="en_US" placeholder="en_US"
                            style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div id="template-body-params-field" style="margin-bottom: 1rem; display: none;">
                        <label for="template_body_params" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Body Parameters *</label>
                        <textarea id="template_body_params" rows="3" placeholder="One parameter per line"
                            style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"></textarea>
                    </div>
                    <div id="template-header-params-field" style="margin-bottom: 1rem; display: none;">
                        <label for="template_header_params" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Header Parameters *</label>
                        <textarea id="template_header_params" rows="2" placeholder="One parameter per line"
                            style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;"></textarea>
                    </div>
                    <div id="template-carousel-field" style="margin-bottom: 1rem; display: none;">
                        <label for="template_carousel_json" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Carousel Cards (JSON)</label>
                        <textarea id="template_carousel_json" rows="6" placeholder='[{"header":["url"],"body":["text"],"buttons":["payload"]}]'
                            style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: monospace; font-size: 12px;"></textarea>
                    </div>
                    <p style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">Template parameters use the same value for all contacts.</p>
                </div>
                <div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 1rem; border-radius: 8px; margin-bottom: 0.5rem;">
                    <strong style="color: #004085;">After sending</strong>
                    <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; color: #004085;">Messages are queued when you click Send. Go to <a href="whatsapp_messages.php" style="color: #004085; font-weight: 500;">WhatsApp Messages</a> and click <strong>"Send pending messages"</strong> to deliver them.</p>
                </div>
                <div>
                    <button type="submit" id="btn-send" class="btn-premium-primary" style="background: #25D366;">
                        <i class="fab fa-whatsapp"></i> Send to <span id="send-count">0</span> Contacts
                    </button>
                </div>
            </form>
        </div>

        <div id="success-panel" style="display: none; background: #d4edda; border: 1px solid #c3e6cb; padding: 1.5rem; border-radius: 8px; margin-top: 1rem;">
            <h3 style="margin: 0 0 0.5rem 0; color: #155724;">Success</h3>
            <p id="success-text" style="margin: 0; color: #155724;"></p>
            <a href="whatsapp_messages.php" class="btn-premium-primary" style="margin-top: 1rem; display: inline-block; background: #25D366;">View WhatsApp Messages</a>
        </div>
    </div>
</div>

<!-- Template Browser Modal -->
<div id="template-browser-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto;">
    <div style="max-width: 1200px; margin: 2rem auto; background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 style="margin: 0;">Select WhatsApp Template</h2>
            <button type="button" onclick="closeTemplateBrowser()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <div id="template-browser-loading" style="text-align: center; padding: 2rem; display: none;">
            <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #64748b;"></i>
            <div style="color: #64748b;">Loading templates...</div>
        </div>
        <div id="template-browser-error" style="display: none; background: #fee; border: 1px solid #fcc; color: #c33; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;"></div>
        <div id="template-browser-empty" style="display: none; text-align: center; padding: 2rem; color: #64748b;">No approved templates found.</div>
        <div id="template-browser-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;"></div>
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
    const form = document.getElementById('bulk-whatsapp-form');
    const hiddenFilters = document.getElementById('hidden-filters');
    const messageType = document.getElementById('message_type');
    const textGroup = document.getElementById('text-message-group');
    const templateGroup = document.getElementById('template-group');

    messageType.addEventListener('change', function() {
        const isTemplate = this.value === 'template';
        textGroup.style.display = isTemplate ? 'none' : 'block';
        templateGroup.style.display = isTemplate ? 'block' : 'none';
    });

    const browseBtn = document.getElementById('browse-templates-btn');
    if (browseBtn) browseBtn.addEventListener('click', function() { openTemplateBrowser(); });

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

    let isSubmitting = false;
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (isSubmitting) return;
        const filters = getFilters();
        const msgType = document.getElementById('message_type').value;
        const message = document.getElementById('message').value.trim();
        const templateName = document.getElementById('template_name').value.trim();

        if (msgType === 'template' && !templateName) {
            alert('Please select a template.');
            return;
        }
        if (msgType === 'text' && !message) {
            alert('Message is required for text type.');
            return;
        }

        isSubmitting = true;
        const btn = document.getElementById('btn-send');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        try {
            const formData = new FormData();
            formData.append('action', 'send_whatsapp');
            formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
            formData.append('message_type', msgType);
            formData.append('message', msgType === 'template' ? ('Template: ' + templateName) : message);
            if (msgType === 'template') {
                formData.append('template_name', templateName);
                formData.append('language_code', document.getElementById('language_code').value || 'en_US');
                const templateParams = buildBulkTemplateParams();
                formData.append('template_params', JSON.stringify(templateParams));
            }
            formData.append('filters', JSON.stringify(filters));

            const response = await fetch('../api/bulk_messaging.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success && data.result) {
                const r = data.result;
                document.getElementById('success-text').textContent = 'Queued ' + r.queued + ' WhatsApp message(s).' + (r.deferred > 0 ? ' Deferred ' + r.deferred + ' to later cold-outreach slots.' : '') + (r.skipped > 0 ? ' Skipped ' + r.skipped + '.' : '');
                composePanel.style.display = 'none';
                successPanel.style.display = 'block';
            } else {
                alert(data.error || 'Send failed');
                isSubmitting = false;
                btn.disabled = false;
                btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
            }
        } catch (e) {
            alert('Error: ' + e.message);
            isSubmitting = false;
            btn.disabled = false;
            btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
        }
    });

    function buildBulkTemplateParams() {
        const params = { _language: document.getElementById('language_code').value || 'en_US' };
        const bodyEl = document.getElementById('template_body_params');
        if (bodyEl && bodyEl.value.trim()) {
            params.body = bodyEl.value.split('\n').map(s => s.trim()).filter(Boolean);
        }
        const headerEl = document.getElementById('template_header_params');
        if (headerEl && headerEl.value.trim()) {
            params.header = headerEl.value.split('\n').map(s => s.trim()).filter(Boolean);
        }
        const carouselEl = document.getElementById('template_carousel_json');
        if (carouselEl && carouselEl.value.trim()) {
            const raw = carouselEl.value.trim();
            try {
                let arr = JSON.parse(raw);
                if (!Array.isArray(arr)) {
                    arr = raw.split('\n').map(l => l.trim()).filter(Boolean).map(l => JSON.parse(l));
                }
                if (arr && arr.length) params.carousel = arr;
            } catch (_) { /* ignore invalid JSON */ }
        }
        return params;
    }

    if (document.getElementById('contact_ids').value.trim()) {
        btnPreview.click();
    }
});

let bulkTemplatesCache = null;
function openTemplateBrowser() {
    const modal = document.getElementById('template-browser-modal');
    const loading = document.getElementById('template-browser-loading');
    const error = document.getElementById('template-browser-error');
    const empty = document.getElementById('template-browser-empty');
    const grid = document.getElementById('template-browser-grid');
    modal.style.display = 'block';
    loading.style.display = 'block';
    error.style.display = 'none';
    empty.style.display = 'none';
    grid.innerHTML = '';
    if (bulkTemplatesCache) {
        loading.style.display = 'none';
        displayBulkTemplates(bulkTemplatesCache);
        return;
    }
    fetch('../api/whatsapp/templates.php')
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            if (data.success && data.templates && data.templates.length) {
                bulkTemplatesCache = data.templates;
                displayBulkTemplates(data.templates);
            } else if (data.error) {
                error.style.display = 'block';
                error.textContent = data.error;
            } else {
                empty.style.display = 'block';
            }
        })
        .catch(err => {
            loading.style.display = 'none';
            error.style.display = 'block';
            error.textContent = err.message || 'Failed to load templates';
        });
}
function closeTemplateBrowser() { document.getElementById('template-browser-modal').style.display = 'none'; }
function displayBulkTemplates(templates) {
    const grid = document.getElementById('template-browser-grid');
    grid.innerHTML = '';
    templates.forEach(t => grid.appendChild(createBulkTemplateCard(t)));
}
function createBulkTemplateCard(template) {
    const card = document.createElement('div');
    card.style.cssText = 'border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.2s;';
    card.onmouseenter = () => { card.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)'; };
    card.onmouseleave = () => { card.style.boxShadow = 'none'; };
    card.onclick = () => selectBulkTemplate(template);
    const pc = template.parameter_count || {};
    const isCarousel = template.is_carousel || false;
    const hasParams = (pc.header || 0) + (pc.body || 0) + (pc.buttons || 0) > 0;
    card.innerHTML = `
        <div style="font-weight: 600; margin-bottom: 0.5rem;">${escapeHtml(template.name)}</div>
        <div style="font-size: 12px; color: #64748b;">${template.language || 'en_US'} ${isCarousel ? '• Carousel' : hasParams ? '• ' + (pc.header||0) + ' header, ' + (pc.body||0) + ' body' : ''}</div>
        <button type="button" style="margin-top: 0.5rem; width: 100%; padding: 0.4rem; background: #25D366; color: white; border: none; border-radius: 4px; cursor: pointer;">Select</button>
    `;
    card.querySelector('button').addEventListener('click', (e) => { e.stopPropagation(); selectBulkTemplate(template); });
    return card;
}
function selectBulkTemplate(template) {
    document.getElementById('template_name').value = template.name;
    document.getElementById('language_code').value = template.language || 'en_US';
    document.getElementById('template_structure').value = JSON.stringify(template);
    document.getElementById('selected-template-name').textContent = template.name;
    document.getElementById('selected-template-language').textContent = template.language || 'en_US';
    document.getElementById('selected-template-info').style.display = 'block';
    updateBulkParameterFields(template);
    closeTemplateBrowser();
}
function updateBulkParameterFields(template) {
    const pc = template.parameter_count || {};
    const schema = template.parameter_schema || {};
    const headerParams = pc.header || 0;
    const bodyParams = pc.body || 0;
    const isCarousel = template.is_carousel || false;
    const hasParams = headerParams + bodyParams + (pc.buttons || 0) > 0;
    const carouselField = document.getElementById('template-carousel-field');
    const headerField = document.getElementById('template-header-params-field');
    const bodyField = document.getElementById('template-body-params-field');
    if (isCarousel && hasParams) { carouselField.style.display = 'block'; } else { carouselField.style.display = 'none'; carouselField.querySelector('textarea').value = ''; }
    if (!isCarousel && headerParams > 0) { headerField.style.display = 'block'; headerField.querySelector('label').innerHTML = 'Header Parameters * (' + headerParams + ' required)'; } else { headerField.style.display = 'none'; headerField.querySelector('textarea').value = ''; }
    if (isCarousel) { bodyField.style.display = 'none'; bodyField.querySelector('textarea').value = ''; }
    else if (bodyParams > 0) { bodyField.style.display = 'block'; bodyField.querySelector('label').innerHTML = 'Body Parameters * (' + bodyParams + ' required)'; } else { bodyField.style.display = 'none'; bodyField.querySelector('textarea').value = ''; }
}
function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}
document.getElementById('template-browser-modal').addEventListener('click', function(e) { if (e.target === this) closeTemplateBrowser(); });
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
