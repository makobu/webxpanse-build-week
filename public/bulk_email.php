<?php
/**
 * Bulk Email Page
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
use CRM\Services\EmailTemplates;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

(new WorkspaceCommunicationGateService())->enforceWebRuntime((int) (WorkspaceContext::currentWorkspaceId() ?? 0), Auth::user());

$tagsModule = new Tags();
$templatesService = new EmailTemplates();
$currentUser = Auth::user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$allTags = $tagsModule->getAll();
$users = Database::query("SELECT id, email FROM users ORDER BY email ASC");
$emailTemplates = $templatesService->getSendableTemplatesForUser($currentUserId);

// Pre-fill contact_ids from URL (from contacts page bulk action)
$preselectedContactIds = [];
if (!empty($_GET['contact_ids'])) {
    $preselectedContactIds = array_map('intval', array_filter(explode(',', $_GET['contact_ids'])));
}
$preselectedCompanyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : 0;

$pageTitle = 'Bulk Email - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Bulk Email</h1>
                <p>Send email to multiple contacts by tag or filter</p>
            </div>
            <div class="page-header-actions">
                <a href="emails.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Emails
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
            <input type="hidden" id="company_id" name="company_id" value="<?php echo htmlspecialchars((string) $preselectedCompanyId); ?>">
        </div>

        <div id="preview-panel" class="filters-card" style="display: none; margin-bottom: 1.5rem;">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem;">2. Preview</h3>
            <p id="preview-text" style="color: var(--charcoal-grey); margin-bottom: 0.5rem;"></p>
            <div id="preview-sample" style="font-size: 0.875rem; color: #64748b; max-height: 120px; overflow-y: auto;"></div>
            <p id="preview-live-hint" style="display: none; margin-top: 0.75rem; color: #475569; font-size: 0.8rem;"></p>
        </div>

        <div id="compose-panel" class="filters-card" style="display: none;">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem;">3. Compose</h3>
            <form id="bulk-email-form" style="display: flex; flex-direction: column; gap: 1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="send_email">
                <div id="hidden-filters"></div>
                <div>
                    <label for="template_slug" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Use Template (Optional)</label>
                    <select id="template_slug" name="template_slug" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;" <?php echo empty($emailTemplates) ? 'disabled' : ''; ?>>
                        <option value=""><?php echo empty($emailTemplates) ? 'No templates available' : 'Compose manually'; ?></option>
                        <?php foreach ($emailTemplates as $template): ?>
                            <option value="<?php echo htmlspecialchars($template['slug']); ?>">
                                <?php echo htmlspecialchars($template['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        Built-in bulk-safe variables: {first_name}, {last_name}, {email}, {phone}, {company}, {contact_id}
                    </p>
                </div>
                <div id="template-warning" style="display: none; background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 0.85rem 1rem; border-radius: 8px;">
                    <strong style="display: block; margin-bottom: 0.3rem;">Template variables need attention</strong>
                    <div id="template-warning-text"></div>
                </div>
                <div>
                    <label for="subject" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Subject *</label>
                    <input type="text" id="subject" name="subject" required style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label for="body_editor" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Message *</label>
                    <div id="body_editor" style="background: white; min-height: 260px;"></div>
                    <textarea id="body" name="body" rows="8" required style="display: none;"></textarea>
                    <input type="hidden" id="body_text" name="body_text">
                    <input type="hidden" id="body_html" name="body_html">
                    <p style="font-size: 0.75rem; color: #64748b; margin-top: 0.4rem;">Templates preserve HTML formatting. Live preview below shows how the current draft will look for one sample recipient.</p>
                </div>
                <div style="border: 1px solid var(--border-color); border-radius: 8px; background: #f8fafc; padding: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                        <div>
                            <strong style="display: block; color: var(--midnight-black);">Live Preview</strong>
                            <span id="live-preview-label" style="font-size: 0.8rem; color: #64748b;">Preview updates after you choose recipients.</span>
                        </div>
                        <span style="font-size: 0.75rem; color: #64748b;">Unsupported placeholders are removed before send.</span>
                    </div>
                    <div style="border: 1px solid #e2e8f0; border-radius: 6px; background: white; overflow: hidden;">
                        <div style="padding: 0.75rem 1rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                            <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 0.2rem;">Subject</div>
                            <div id="preview-subject-line" style="font-weight: 600; color: #0f172a;">Your subject will appear here.</div>
                        </div>
                        <iframe id="email-preview-frame" style="width: 100%; height: 280px; border: 0; background: #ffffff;" title="Bulk email preview"></iframe>
                    </div>
                </div>
                <div style="padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: #f8fafc;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-weight: 600;">
                        <input type="checkbox" id="ai_customize" name="ai_customize" value="1">
                        AI personalize each email using contact/system context
                    </label>
                    <p style="margin: 0 0 0.6rem 0; font-size: 0.75rem; color: #64748b;">
                        Uses AI to customize each recipient email based on CRM context. Recommended batch size: 100 or fewer.
                    </p>
                    <div id="ai_customize_options" style="display: none; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem;">
                        <div>
                            <label for="ai_purpose" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">AI Purpose</label>
                            <select id="ai_purpose" name="ai_purpose" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                                <option value="follow_up">Follow-up</option>
                                <option value="proposal">Proposal</option>
                                <option value="welcome">Welcome</option>
                                <option value="meeting">Meeting Request</option>
                                <option value="thank_you">Thank You</option>
                            </select>
                        </div>
                        <div>
                            <label for="ai_tone" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">AI Tone</label>
                            <select id="ai_tone" name="ai_tone" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                                <option value="professional">Professional</option>
                                <option value="friendly">Friendly</option>
                                <option value="casual">Casual</option>
                                <option value="formal">Formal</option>
                            </select>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label for="ai_instructions" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Extra AI Instructions (optional)</label>
                            <textarea id="ai_instructions" name="ai_instructions" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit;" placeholder="Example: Mention recent product launch and include a soft CTA for a 15-minute call."></textarea>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="scheduled_at" style="display: block; margin-bottom: 0.25rem; font-weight: 500;">Schedule (optional)</label>
                    <input type="datetime-local" id="scheduled_at" name="scheduled_at" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <button type="submit" id="btn-send" class="btn-premium-primary">
                        <i class="fas fa-paper-plane"></i> Send to <span id="send-count">0</span> Contacts
                    </button>
                </div>
            </form>
        </div>

        <div id="success-panel" style="display: none; background: #d4edda; border: 1px solid #c3e6cb; padding: 1.5rem; border-radius: 8px; margin-top: 1rem;">
            <h3 style="margin: 0 0 0.5rem 0; color: #155724;">Success</h3>
            <p id="success-text" style="margin: 0; color: #155724;"></p>
            <a href="emails.php" class="btn-premium-primary" style="margin-top: 1rem; display: inline-block;">View Emails</a>
        </div>
    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const BULK_SAFE_TEMPLATE_VARIABLES = new Set(['first_name', 'last_name', 'email', 'phone', 'company', 'contact_id']);
    const urlParams = new URLSearchParams(window.location.search);
    const contactIdsParam = urlParams.get('contact_ids');
    if (contactIdsParam) {
        document.getElementById('contact_ids').value = contactIdsParam;
    }

    const btnPreview = document.getElementById('btn-preview');
    const previewPanel = document.getElementById('preview-panel');
    const composePanel = document.getElementById('compose-panel');
    const successPanel = document.getElementById('success-panel');
    const form = document.getElementById('bulk-email-form');
    const hiddenFilters = document.getElementById('hidden-filters');
    const aiCustomizeCheckbox = document.getElementById('ai_customize');
    const aiCustomizeOptions = document.getElementById('ai_customize_options');
    const templateSelect = document.getElementById('template_slug');
    const subjectInput = document.getElementById('subject');
    const bodyTextarea = document.getElementById('body');
    const bodyTextInput = document.getElementById('body_text');
    const bodyHtmlInput = document.getElementById('body_html');
    const previewFrame = document.getElementById('email-preview-frame');
    const previewSubjectLine = document.getElementById('preview-subject-line');
    const livePreviewLabel = document.getElementById('live-preview-label');
    const templateWarning = document.getElementById('template-warning');
    const templateWarningText = document.getElementById('template-warning-text');
    const previewLiveHint = document.getElementById('preview-live-hint');
    let previewSampleContact = null;
    const quill = new Quill('#body_editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                [{ 'color': [] }, { 'background': [] }],
                ['link'],
                ['clean']
            ]
        },
        placeholder: 'Write your bulk email here...'
    });

    if (aiCustomizeCheckbox && aiCustomizeOptions) {
        aiCustomizeCheckbox.addEventListener('change', function() {
            aiCustomizeOptions.style.display = this.checked ? 'grid' : 'none';
        });
    }

    function stripDangerousPreviewMarkup(raw) {
        let value = String(raw || '');
        value = value.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        value = value.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
        value = value.replace(/<link\b[^>]*rel=["']?(?:icon|shortcut icon)["']?[^>]*>/gi, '');
        value = value.replace(/<base\b[^>]*>/gi, '');
        const bodyMatch = value.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i);
        if (bodyMatch) {
            value = bodyMatch[1];
        }
        value = value.replace(/<\/?(?:html|head|body|meta|title)[^>]*>/gi, '');
        return value.trim();
    }

    function looksLikeRawDraftPayload(raw) {
        const value = String(raw || '').trim();
        if (!value) {
            return false;
        }
        if (/```json/i.test(value)) {
            return true;
        }
        return /^\{[\s\S]*"(subject|body_html|body_text|plain_body|reply_text|message)"\s*:/.test(value);
    }

    function normalizeEditorText(rawText) {
        return String(rawText || '')
            .replace(/\u00a0/g, ' ')
            .replace(/\r\n/g, '\n')
            .replace(/\n$/, '')
            .trim();
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildHtmlFromText(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function getCurrentBodyHtml() {
        const text = normalizeEditorText(quill.getText());
        const html = stripDangerousPreviewMarkup(quill.root.innerHTML || '');
        if (!text) {
            return '';
        }
        return html && html !== '<p><br></p>' ? html : buildHtmlFromText(text);
    }

    function syncEditorFields() {
        const text = normalizeEditorText(quill.getText());
        const html = getCurrentBodyHtml();
        bodyTextarea.value = text;
        bodyTextInput.value = text;
        bodyHtmlInput.value = html;
    }

    function hydrateEditor(bodyHtml, bodyText) {
        const html = stripDangerousPreviewMarkup(bodyHtml || '');
        const text = normalizeEditorText(bodyText || '');

        if (looksLikeRawDraftPayload(html) || looksLikeRawDraftPayload(text)) {
            return false;
        }

        if (html) {
            quill.clipboard.dangerouslyPasteHTML(html);
        } else if (text) {
            quill.setText(text);
        } else {
            quill.setText('');
        }

        syncEditorFields();
        return true;
    }

    function applyTemplateVariables(raw, contact) {
        let value = String(raw || '');
        const safeContact = contact || {};
        const replacements = {
            first_name: safeContact.first_name || '',
            last_name: safeContact.last_name || '',
            email: safeContact.email || '',
            phone: safeContact.phone || '',
            company: safeContact.company || '',
            contact_id: safeContact.id ? String(safeContact.id) : ''
        };

        Object.keys(replacements).forEach(key => {
            value = value.replace(new RegExp('\\{' + key + '\\}', 'g'), escapeHtml(replacements[key]));
        });

        return value.replace(/\{[^}]+\}/g, '');
    }

    function extractVariablesFromContent(subject, bodyHtml, bodyText, declaredVariables) {
        const matches = new Set(Array.isArray(declaredVariables) ? declaredVariables.map(v => String(v || '').trim()).filter(Boolean) : []);
        const combined = [subject, bodyHtml, bodyText].join('\n');
        const regex = /\{([^}]+)\}/g;
        let match;
        while ((match = regex.exec(combined)) !== null) {
            const variable = String(match[1] || '').trim();
            if (variable) {
                matches.add(variable);
            }
        }
        return Array.from(matches);
    }

    function updateTemplateWarning(meta) {
        if (!meta) {
            templateWarning.style.display = 'none';
            templateWarningText.textContent = '';
            return;
        }

        const variables = extractVariablesFromContent(meta.raw_subject, meta.raw_body_html, meta.raw_body_text, meta.variables);
        const unsupported = variables.filter(variable => !BULK_SAFE_TEMPLATE_VARIABLES.has(variable));

        if (unsupported.length === 0) {
            templateWarning.style.display = 'none';
            templateWarningText.textContent = '';
            return;
        }

        templateWarning.style.display = 'block';
        templateWarningText.textContent = 'This template uses variables that bulk email does not collect: ' + unsupported.map(v => '{' + v + '}').join(', ') + '. They will render blank when this bulk email is sent.';
    }

    function updateLivePreview() {
        syncEditorFields();

        const sample = previewSampleContact || {};
        const sampleName = ((sample.first_name || '') + ' ' + (sample.last_name || '')).trim() || (sample.id ? 'Contact #' + sample.id : 'a sample recipient');
        livePreviewLabel.textContent = sample.id
            ? 'Previewing this draft for ' + sampleName + (sample.email ? ' <' + sample.email + '>' : '')
            : 'Live preview of the current draft';

        const renderedSubject = applyTemplateVariables(subjectInput.value, sample) || 'Your subject will appear here.';
        const renderedBody = getCurrentBodyHtml()
            ? applyTemplateVariables(getCurrentBodyHtml(), sample)
            : buildHtmlFromText(applyTemplateVariables(bodyTextInput.value, sample));
        const safeBody = stripDangerousPreviewMarkup(renderedBody);

        previewSubjectLine.textContent = renderedSubject;
        previewFrame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><link rel="icon" href="data:,"></head><body style="margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,sans-serif;color:#0f172a;line-height:1.6;">'
            + (safeBody || '<span style="color:#94a3b8;">Your email body preview will appear here.</span>')
            + '</body></html>';
    }

    async function loadTemplate(slug) {
        if (!slug) {
            updateTemplateWarning(null);
            updateLivePreview();
            return;
        }

        if (!previewSampleContact || !previewSampleContact.id) {
            alert('Preview recipients first so the template can be loaded with a sample contact.');
            templateSelect.value = '';
            updateTemplateWarning(null);
            return;
        }

        try {
            const response = await fetch(`<?php echo apiUrl('email_template_preview.php'); ?>?slug=${encodeURIComponent(slug)}&contact_id=${previewSampleContact.id}&include_raw=1`);
            const contentType = response.headers.get('content-type');
            let data;

            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                const text = await response.text();
                console.error('Non-JSON response from template API:', text.substring(0, 200));
                throw new Error('Server returned invalid response while loading the template.');
            }

            if (!data.success) {
                throw new Error(data.error || 'Failed to load template.');
            }

            subjectInput.value = data.raw_subject || data.subject || '';
            if (!hydrateEditor(data.raw_body_html || data.body_html || '', data.raw_body_text || data.body_text || '')) {
                throw new Error('Template content could not be loaded into the editor.');
            }

            if (data.preview_contact && data.preview_contact.id) {
                previewSampleContact = data.preview_contact;
            }

            updateTemplateWarning(data.template || {
                raw_subject: data.raw_subject || '',
                raw_body_html: data.raw_body_html || '',
                raw_body_text: data.raw_body_text || '',
                variables: data.variables || []
            });
            updateLivePreview();
        } catch (error) {
            console.error('Error loading template:', error);
            alert('Failed to load template: ' + (error.message || 'Unknown error'));
            templateSelect.value = '';
            updateTemplateWarning(null);
        }
    }

    quill.on('text-change', function() {
        syncEditorFields();
        updateLivePreview();
    });

    subjectInput.addEventListener('input', updateLivePreview);

    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            loadTemplate(this.value);
        });
    }

    function getFilters() {
        const filters = {};
        const tag = document.getElementById('filter_tag').value;
        const stage = document.getElementById('filter_stage').value;
        const search = document.getElementById('filter_search').value.trim();
        const assigned = document.getElementById('filter_assigned').value;
        const contactIds = document.getElementById('contact_ids').value;
        const companyId = document.getElementById('company_id').value;
        if (tag) filters.tag_id = tag;
        if (stage) filters.stage = stage;
        if (search) filters.search = search;
        if (assigned) filters.assigned_to = assigned;
        // Only add company_id if it's a valid positive integer — "0" is the
        // no-company default and must NOT be sent as a filter (NULL != 0 in SQL).
        const parsedCompanyId = parseInt(companyId, 10);
        if (!isNaN(parsedCompanyId) && parsedCompanyId > 0) filters.company_id = parsedCompanyId;
        if (contactIds) {
            filters.contact_ids = contactIds.split(',').map(id => parseInt(id)).filter(id => id > 0);
        }
        return filters;
    }

    btnPreview.addEventListener('click', async function() {
        const filters = getFilters();
        if (!filters.tag_id && !filters.stage && !filters.search && !filters.assigned_to && !filters.company_id && (!filters.contact_ids || filters.contact_ids.length === 0)) {
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
                document.getElementById('preview-text').textContent = data.with_email + ' contacts will receive this email (of ' + data.total + ' matching filters).';
                const sample = data.sample || [];
                document.getElementById('preview-sample').innerHTML = sample.length
                    ? '<ul style="margin: 0; padding-left: 1.25rem;">' + sample.map(c => '<li>' + (((c.first_name || '') + ' ' + (c.last_name || '')).trim() || ('Contact #' + c.id)) + ' &lt;' + (c.email || '') + '&gt;</li>').join('') + '</ul>'
                    : 'No sample.';
                document.getElementById('send-count').textContent = data.with_email;
                previewPanel.style.display = 'block';
                composePanel.style.display = 'block';

                const firstSampleWithEmail = sample.find(c => c && c.email) || sample[0] || null;
                previewSampleContact = firstSampleWithEmail ? {
                    id: parseInt(firstSampleWithEmail.id, 10) || 0,
                    first_name: firstSampleWithEmail.first_name || '',
                    last_name: firstSampleWithEmail.last_name || '',
                    email: firstSampleWithEmail.email || '',
                    phone: firstSampleWithEmail.phone || '',
                    company: firstSampleWithEmail.company || ''
                } : null;

                if (previewSampleContact && previewSampleContact.id) {
                    previewLiveHint.style.display = 'block';
                    previewLiveHint.textContent = 'Live preview uses ' + ((((previewSampleContact.first_name || '') + ' ' + (previewSampleContact.last_name || '')).trim()) || ('Contact #' + previewSampleContact.id)) + (previewSampleContact.email ? ' <' + previewSampleContact.email + '>' : '') + ' as the sample recipient.';
                } else {
                    previewLiveHint.style.display = 'none';
                    previewLiveHint.textContent = '';
                }

                hiddenFilters.innerHTML = '';
                Object.keys(filters).forEach(k => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'filters[' + k + ']';
                    inp.value = Array.isArray(filters[k]) ? JSON.stringify(filters[k]) : filters[k];
                    hiddenFilters.appendChild(inp);
                });

                updateLivePreview();

                if (templateSelect && templateSelect.value) {
                    loadTemplate(templateSelect.value);
                }
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
        syncEditorFields();
        const subject = subjectInput.value.trim();
        const body = bodyTextInput.value;
        const bodyHtml = bodyHtmlInput.value;

        if (!subject || (!body && !bodyHtml)) {
            alert('Subject and message are required.');
            return;
        }

        const btn = document.getElementById('btn-send');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        try {
            const formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
            formData.append('subject', subject);
            formData.append('body', body);
            formData.append('body_text', body);
            formData.append('body_html', bodyHtml || buildHtmlFromText(body));
            formData.append('filters', JSON.stringify(filters));
            formData.append('scheduled_at', document.getElementById('scheduled_at').value || '');
            if (aiCustomizeCheckbox && aiCustomizeCheckbox.checked) {
                formData.append('ai_customize', '1');
                formData.append('ai_purpose', document.getElementById('ai_purpose').value);
                formData.append('ai_tone', document.getElementById('ai_tone').value);
                formData.append('ai_instructions', document.getElementById('ai_instructions').value || '');
            }

            const response = await fetch('../api/bulk_messaging.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success && data.result) {
                const r = data.result;
                document.getElementById('success-text').textContent = 'Queued ' + r.queued + ' email(s).' + (r.deferred > 0 ? ' Deferred ' + r.deferred + ' to later cold-outreach slots.' : '') + (r.skipped > 0 ? ' Skipped ' + r.skipped + '.' : '');
                composePanel.style.display = 'none';
                successPanel.style.display = 'block';
            } else {
                alert(data.error || 'Send failed');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
            }
        } catch (e) {
            alert('Error: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send to <span id="send-count">' + document.getElementById('send-count').textContent + '</span> Contacts';
        }
    });

    hydrateEditor('', '');
    updateLivePreview();

    const initialFilters = getFilters();
    if ((initialFilters.contact_ids && initialFilters.contact_ids.length > 0) || initialFilters.company_id) {
        btnPreview.click();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
