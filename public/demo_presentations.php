<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\DemoPresentationDeliveryService;
use CRM\Services\DemoSessionScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . publicUrl('login.php'));
    exit;
}

$user = Auth::user() ?: [];
$canOperate = (new DemoSessionScopeService())->canOperateDemo($user);
if (!$canOperate && !Authorization::can('demo.presentation.create', $user)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$canLive = $canOperate || Authorization::can('demo.presentation.live_send', $user);
$scenarios = (new DemoPresentationDeliveryService())->scenarioCatalog();
$pageTitle = 'MetroDrive QA Harness - ' . brandProductName();
$bodyClass = 'demo-presentations-page';

ob_start();
?>

<style>
    .demo-presentations {
        display: grid;
        gap: 18px;
        max-width: 1180px;
        margin: 0 auto;
        padding: 20px;
    }
    .demo-presentations__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
    }
    .demo-presentations h1 {
        margin: 0;
        font-size: 1.55rem;
        letter-spacing: 0;
    }
    .demo-presentations p {
        margin: 6px 0 0;
        color: #64748b;
        line-height: 1.5;
    }
    .demo-presentations__grid {
        display: grid;
        grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }
    .demo-panel {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        padding: 16px;
    }
    .demo-panel h2 {
        margin: 0 0 12px;
        font-size: 1rem;
    }
    .demo-form {
        display: grid;
        gap: 10px;
    }
    .demo-form label {
        display: grid;
        gap: 5px;
        color: #334155;
        font-size: .9rem;
    }
    .demo-form input,
    .demo-form select,
    .demo-form textarea {
        width: 100%;
        min-height: 40px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 8px 10px;
        color: #0f172a;
        background: #fff;
    }
    .demo-form textarea {
        min-height: 92px;
        resize: vertical;
    }
    .demo-form input[type="checkbox"] {
        width: auto;
        min-height: 0;
        padding: 0;
    }
    .demo-form label span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .demo-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }
    .demo-actions button,
    .demo-actions a {
        border: 1px solid #0f172a;
        background: #0f172a;
        color: #fff;
        border-radius: 6px;
        padding: 9px 12px;
        text-decoration: none;
        cursor: pointer;
    }
    .demo-actions button.secondary {
        background: #fff;
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .demo-actions button.danger {
        background: #b91c1c;
        border-color: #b91c1c;
    }
    .demo-status {
        min-height: 20px;
        color: #0f766e;
        font-size: .9rem;
    }
    .demo-status.is-error {
        color: #b91c1c;
    }
    .demo-session-list {
        display: grid;
        gap: 8px;
        max-height: 380px;
        overflow: auto;
    }
    .demo-session-item {
        display: block;
        width: 100%;
        text-align: left;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        padding: 10px;
        cursor: pointer;
    }
    .demo-session-item.is-selected {
        border-color: #0f172a;
        background: #eef2ff;
    }
    .demo-session-item strong,
    .demo-credential strong {
        display: block;
        color: #0f172a;
    }
    .demo-session-item span,
    .demo-credential span,
    .demo-audit-item span {
        color: #64748b;
        font-size: .85rem;
    }
    .demo-session-item span,
    .demo-credential span {
        display: block;
        overflow-wrap: anywhere;
    }
    .demo-credentials {
        display: grid;
        gap: 8px;
        margin-top: 12px;
    }
    .demo-credential {
        border: 1px solid #dbeafe;
        background: #eff6ff;
        border-radius: 8px;
        padding: 10px;
        overflow-wrap: anywhere;
    }
    .demo-scenario-layout {
        display: grid;
        grid-template-columns: minmax(220px, 300px) minmax(0, 1fr);
        gap: 14px;
    }
    .demo-preview {
        white-space: pre-wrap;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        padding: 12px;
        min-height: 160px;
        color: #0f172a;
    }
    .demo-audit-list {
        display: grid;
        gap: 8px;
        max-height: 260px;
        overflow: auto;
    }
    .demo-audit-item {
        border-bottom: 1px solid #e2e8f0;
        padding: 8px 0;
    }
    .demo-audit-item strong,
    .demo-audit-item span {
        display: block;
    }
    .demo-audit-item span {
        margin-top: 2px;
    }
    @media (max-width: 900px) {
        .demo-presentations__grid,
        .demo-scenario-layout {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="demo-presentations" data-demo-presentations
      data-state-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/state.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-create-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/sessions.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-arm-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/arm.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-run-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/run.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-revoke-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/revoke.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-reseed-url="<?php echo htmlspecialchars(apiUrl('demo_presentation/reseed.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-csrf="<?php echo htmlspecialchars(Security::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
      data-can-live="<?php echo $canLive ? '1' : '0'; ?>">
    <section class="demo-presentations__header">
        <div>
            <h1>MetroDrive QA Harness</h1>
            <p>Restricted fallback for protected-demo regression testing, delivery guardrail smoke tests, and backup presentation rehearsal.</p>
        </div>
        <div class="demo-actions">
            <button type="button" class="secondary" data-reseed>Reset MetroDrive baseline</button>
        </div>
    </section>

    <section class="demo-presentations__grid">
        <div class="demo-panel">
            <h2>Create QA Session</h2>
            <form class="demo-form" data-create-form>
                <label>
                    Prospect name
                    <input name="name" autocomplete="name" required maxlength="120">
                </label>
                <label>
                    Prospect email
                    <input name="email" type="email" autocomplete="email" required maxlength="180">
                </label>
                <label>
                    Prospect WhatsApp
                    <input name="phone" autocomplete="tel" required maxlength="40">
                </label>
                <label>
                    Company
                    <input name="company" maxlength="180">
                </label>
                <label>
                    Optional presenter email allowlist
                    <input name="presenter_email" type="email" maxlength="180">
                </label>
                <label>
                    Optional presenter WhatsApp allowlist
                    <input name="presenter_phone" maxlength="40">
                </label>
                <div class="demo-actions">
                    <button type="submit">Create QA owner</button>
                </div>
                <div class="demo-status" data-create-status></div>
            </form>
            <div class="demo-credentials" data-credentials hidden></div>
        </div>

        <div class="demo-panel">
            <h2>Sessions</h2>
            <div class="demo-session-list" data-session-list></div>
        </div>
    </section>

    <section class="demo-panel" data-session-panel hidden>
        <div class="demo-presentations__header">
            <div>
                <h2 data-selected-title>Selected Session</h2>
                <p data-selected-meta></p>
            </div>
            <div class="demo-actions">
                <button type="button" class="secondary" data-refresh>Refresh</button>
                <?php if ($canLive): ?>
                    <button type="button" class="secondary" data-arm>Arm live mode</button>
                <?php endif; ?>
                <button type="button" class="danger" data-revoke>Revoke</button>
            </div>
        </div>

        <div class="demo-scenario-layout">
            <form class="demo-form" data-scenario-form>
                <label>
                    Scenario
                    <select name="scenario_key">
                        <?php foreach ($scenarios as $key => $scenario): ?>
                            <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $scenario['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Recipient
                    <select name="recipient_id" data-recipient-select></select>
                </label>
                <?php if ($canLive): ?>
                    <label>
                        <span><input type="checkbox" name="live" value="1"> Request live delivery</span>
                    </label>
                <?php endif; ?>
                <div class="demo-actions">
                    <button type="button" class="secondary" data-preview>Preview</button>
                    <button type="submit">Run scenario</button>
                </div>
                <div class="demo-status" data-scenario-status></div>
            </form>
            <div>
                <div class="demo-preview" data-preview-box>Select a scenario and preview the exact message before running it.</div>
            </div>
        </div>
    </section>

    <section class="demo-panel" data-audit-panel hidden>
        <h2>Delivery Audit</h2>
        <div class="demo-audit-list" data-audit-list></div>
    </section>
</main>

<script>
(function() {
    const root = document.querySelector('[data-demo-presentations]');
    if (!root) return;
    const csrf = root.dataset.csrf || '';
    let sessions = [];
    let selectedSessionId = 0;
    let selectedRecipients = [];
    const state = {
        liveEnabled: false
    };

    function $(selector) {
        return root.querySelector(selector);
    }

    function setStatus(node, message, error) {
        if (!node) return;
        node.textContent = message || '';
        node.classList.toggle('is-error', !!error);
    }

    function request(url, payload, method) {
        const options = {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }
        };
        if (options.method !== 'GET') {
            options.body = JSON.stringify(Object.assign({ csrf_token: csrf }, payload || {}));
        }
        return fetch(url, options).then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok || data.success === false) {
                    throw new Error(data.error || 'Request failed.');
                }
                return data;
            });
        });
    }

    function loadState(sessionId) {
        const url = root.dataset.stateUrl + (sessionId ? '?session_id=' + encodeURIComponent(sessionId) : '');
        return request(url, null, 'GET').then(function(data) {
            sessions = data.sessions || [];
            state.liveEnabled = !!data.live_enabled;
            renderSessions();
            if (sessionId) {
                selectedRecipients = data.recipients || [];
                renderSelected(sessionId, data.audit || []);
            }
            return data;
        });
    }

    function renderSessions() {
        const list = $('[data-session-list]');
        if (!list) return;
        list.innerHTML = '';
        if (!sessions.length) {
            list.innerHTML = '<p>No QA sessions yet.</p>';
            return;
        }
        sessions.forEach(function(session) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'demo-session-item' + (Number(session.id) === Number(selectedSessionId) ? ' is-selected' : '');
            button.innerHTML = '<strong></strong><span></span>';
            button.querySelector('strong').textContent = (session.name || 'QA guest') + (session.company ? ' - ' + session.company : '');
            button.querySelector('span').textContent = session.status + ' | expires ' + (session.expires_at || '') + (session.live_presentation ? ' | live armed' : '');
            button.addEventListener('click', function() {
                selectedSessionId = Number(session.id);
                loadState(selectedSessionId);
            });
            list.appendChild(button);
        });
        const selectedButton = list.querySelector('.demo-session-item.is-selected');
        if (selectedButton) {
            const listRect = list.getBoundingClientRect();
            const selectedRect = selectedButton.getBoundingClientRect();
            list.scrollTop += selectedRect.top - listRect.top - 8;
        }
    }

    function renderSelected(sessionId, audit) {
        const session = sessions.find(function(item) { return Number(item.id) === Number(sessionId); });
        const panel = $('[data-session-panel]');
        const auditPanel = $('[data-audit-panel]');
        if (!session || !panel) return;
        panel.hidden = false;
        if (auditPanel) auditPanel.hidden = false;
        $('[data-selected-title]').textContent = session.name || 'Selected session';
        $('[data-selected-meta]').textContent = (session.email || '') + ' | ' + (session.phone || '') + ' | ' + (session.live_presentation ? 'Live armed' : 'Simulated first');
        const armButton = $('[data-arm]');
        if (armButton) {
            armButton.textContent = session.live_presentation ? 'Disarm live mode' : 'Arm live mode';
            armButton.dataset.armed = session.live_presentation ? '1' : '0';
            armButton.disabled = !state.liveEnabled && !session.live_presentation;
            if (!state.liveEnabled && !session.live_presentation) {
                armButton.title = 'Global live delivery is disabled.';
            }
        }
        renderRecipients();
        renderAudit(audit || []);
    }

    function renderRecipients() {
        const select = $('[data-recipient-select]');
        if (!select) return;
        select.innerHTML = '';
        selectedRecipients.forEach(function(recipient) {
            const option = document.createElement('option');
            option.value = recipient.id;
            option.textContent = (recipient.label || 'Approved recipient') + ' | ' + [recipient.email, recipient.phone].filter(Boolean).join(' / ');
            select.appendChild(option);
        });
    }

    function renderAudit(items) {
        const list = $('[data-audit-list]');
        if (!list) return;
        list.innerHTML = '';
        if (!items.length) {
            list.innerHTML = '<p>No delivery audit entries for this session yet.</p>';
            return;
        }
        items.forEach(function(item) {
            const row = document.createElement('div');
            row.className = 'demo-audit-item';
            row.innerHTML = '<strong></strong><span></span>';
            row.querySelector('strong').textContent = item.scenario_key + ' | ' + item.channel + ' | ' + item.status;
            row.querySelector('span').textContent = (item.subject || '') + ' | ' + (item.created_at || '') + (item.error_message ? ' | ' + item.error_message : '');
            list.appendChild(row);
        });
    }

    const createForm = $('[data-create-form]');
    if (createForm) {
        createForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const status = $('[data-create-status]');
            setStatus(status, 'Creating session...', false);
            const payload = {};
            new FormData(createForm).forEach(function(value, key) {
                payload[key] = value;
            });
            request(root.dataset.createUrl, payload).then(function(data) {
                setStatus(status, 'Session created.', false);
                createForm.reset();
                const box = $('[data-credentials]');
                if (box && data.temporary_login) {
                    box.hidden = false;
                    box.innerHTML = '';
                    [
                        ['Temporary email', data.temporary_login.email],
                        ['Temporary password', data.temporary_login.password],
                        ['Magic login', data.temporary_login.magic_login_url],
                        ['Expires', data.temporary_login.expires_at]
                    ].forEach(function(item) {
                        const div = document.createElement('div');
                        div.className = 'demo-credential';
                        div.innerHTML = '<strong></strong><span></span>';
                        div.querySelector('strong').textContent = item[0];
                        div.querySelector('span').textContent = item[1] || '';
                        box.appendChild(div);
                    });
                }
                selectedSessionId = data.session && data.session.id ? Number(data.session.id) : 0;
                return loadState(selectedSessionId);
            }).catch(function(error) {
                setStatus(status, error.message, true);
            });
        });
    }

    const scenarioForm = $('[data-scenario-form]');
    if (scenarioForm) {
        scenarioForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (!selectedSessionId) return;
            const status = $('[data-scenario-status]');
            setStatus(status, 'Running scenario...', false);
            const payload = { session_id: selectedSessionId };
            new FormData(scenarioForm).forEach(function(value, key) {
                payload[key] = value;
            });
            request(root.dataset.runUrl, payload).then(function(data) {
                setStatus(status, data.live_sent ? 'Live delivery sent.' : (data.blocked ? 'Live delivery blocked.' : 'Scenario ran in the demo session.'), !!data.blocked);
                return loadState(selectedSessionId);
            }).catch(function(error) {
                setStatus(status, error.message, true);
            });
        });
    }

    const previewButton = $('[data-preview]');
    if (previewButton) {
        previewButton.addEventListener('click', function() {
            if (!selectedSessionId || !scenarioForm) return;
            const status = $('[data-scenario-status]');
            const payload = { session_id: selectedSessionId, preview: 1 };
            new FormData(scenarioForm).forEach(function(value, key) {
                payload[key] = value;
            });
            setStatus(status, 'Building preview...', false);
            request(root.dataset.runUrl, payload).then(function(data) {
                $('[data-preview-box]').textContent = [
                    'Channel: ' + data.channel,
                    'Recipient: ' + ((data.recipient && (data.recipient.email || data.recipient.phone || data.recipient.label)) || 'approved recipient'),
                    'Subject: ' + (data.subject || ''),
                    '',
                    data.body || ''
                ].join('\n');
                setStatus(status, 'Preview ready.', false);
            }).catch(function(error) {
                setStatus(status, error.message, true);
            });
        });
    }

    const armButton = $('[data-arm]');
    if (armButton) {
        armButton.addEventListener('click', function() {
            if (!selectedSessionId) return;
            const armed = armButton.dataset.armed !== '1';
            request(root.dataset.armUrl, { session_id: selectedSessionId, armed: armed ? 1 : 0 }).then(function() {
                return loadState(selectedSessionId);
            }).catch(function(error) {
                alert(error.message);
            });
        });
    }

    const revokeButton = $('[data-revoke]');
    if (revokeButton) {
        revokeButton.addEventListener('click', function() {
            if (!selectedSessionId) return;
            request(root.dataset.revokeUrl, { session_id: selectedSessionId }).then(function() {
                selectedSessionId = 0;
                $('[data-session-panel]').hidden = true;
                $('[data-audit-panel]').hidden = true;
                return loadState();
            }).catch(function(error) {
                alert(error.message);
            });
        });
    }

    const reseedButton = $('[data-reseed]');
    if (reseedButton) {
        reseedButton.addEventListener('click', function() {
            reseedButton.disabled = true;
            request(root.dataset.reseedUrl, {}).then(function() {
                return loadState(selectedSessionId || 0);
            }).catch(function(error) {
                alert(error.message);
            }).finally(function() {
                reseedButton.disabled = false;
            });
        });
    }

    const refreshButton = $('[data-refresh]');
    if (refreshButton) {
        refreshButton.addEventListener('click', function() {
            loadState(selectedSessionId || 0);
        });
    }

    loadState();
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
