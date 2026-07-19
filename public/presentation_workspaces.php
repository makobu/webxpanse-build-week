<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\PresentationSeedPackService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user() ?: [];
$operator = (new DemoSessionScopeService())->canOperateDemo($user);
if (!$operator && !Authorization::can('presentation.workspace.create', $user)) {
    http_response_code(403);
    die('Access denied: presentation workspace permissions required.');
}

$seedPacks = (new PresentationSeedPackService())->catalog();
$canLive = $operator || Authorization::can('presentation.live_send', $user);
$canArchive = $operator || Authorization::can('presentation.workspace.archive', $user);
$pageTitle = 'Presentation Workspaces - ' . brandProductName();
$bodyClass = 'presentation-workspaces-page';

ob_start();
?>

<style>
    .presentation-workspaces {
        display: grid;
        gap: 18px;
        color: #0f172a;
    }
    .presentation-workspaces__header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        flex-wrap: wrap;
        padding-bottom: 4px;
    }
    .presentation-workspaces h1 {
        margin: 0 0 6px;
        font-size: clamp(26px, 3vw, 38px);
        letter-spacing: 0;
    }
    .presentation-workspaces p {
        margin: 0;
        color: #64748b;
        line-height: 1.55;
    }
    .presentation-workspaces__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .presentation-workspaces__grid {
        display: grid;
        grid-template-columns: minmax(320px, 430px) minmax(0, 1fr);
        gap: 16px;
        align-items: start;
    }
    .presentation-panel {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }
    .presentation-panel__body {
        padding: 18px;
        display: grid;
        gap: 14px;
    }
    .presentation-panel__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 16px 18px;
        border-bottom: 1px solid #e2e8f0;
    }
    .presentation-panel__header h2,
    .presentation-panel__header h3 {
        margin: 0;
        font-size: 17px;
        letter-spacing: 0;
    }
    .presentation-form {
        display: grid;
        gap: 12px;
    }
    .presentation-field {
        display: grid;
        gap: 6px;
    }
    .presentation-field span {
        font-size: 12px;
        font-weight: 800;
        color: #334155;
    }
    .presentation-field input,
    .presentation-field select,
    .presentation-field textarea {
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 10px 11px;
        color: #0f172a;
        background: #fff;
        font: inherit;
        font-size: 14px;
    }
    .presentation-field textarea {
        min-height: 82px;
        resize: vertical;
    }
    .presentation-field-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .presentation-checks {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .presentation-checks label {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 36px;
        padding: 8px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 13px;
        color: #334155;
    }
    .presentation-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 9px 12px;
        background: #fff;
        color: #0f172a;
        font: inherit;
        font-size: 14px;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
    }
    .presentation-btn:hover,
    .presentation-btn:focus-visible {
        border-color: #94a3b8;
        background: #f8fafc;
        transform: translateY(-1px);
    }
    .presentation-btn:disabled {
        cursor: not-allowed;
        opacity: .62;
        transform: none;
    }
    .presentation-btn--primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }
    .presentation-btn--danger {
        background: #991b1b;
        border-color: #991b1b;
        color: #fff;
    }
    .presentation-btn--soft {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #3730a3;
    }
    .presentation-stat-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .presentation-stat {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
        background: #f8fafc;
    }
    .presentation-stat strong {
        display: block;
        font-size: 22px;
        line-height: 1;
    }
    .presentation-stat span {
        display: block;
        margin-top: 5px;
        font-size: 12px;
        color: #64748b;
    }
    .presentation-list {
        display: grid;
        gap: 8px;
        max-height: 500px;
        overflow: auto;
    }
    .presentation-session-btn {
        width: 100%;
        text-align: left;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        padding: 12px;
        display: grid;
        gap: 5px;
        cursor: pointer;
    }
    .presentation-session-btn.is-active {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }
    .presentation-session-btn strong {
        font-size: 14px;
        color: #0f172a;
    }
    .presentation-session-btn span {
        color: #64748b;
        font-size: 12px;
    }
    .presentation-detail {
        display: grid;
        gap: 14px;
    }
    .presentation-detail__title {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .presentation-meta {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }
    .presentation-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 26px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #334155;
        padding: 4px 9px;
        font-size: 12px;
        font-weight: 800;
    }
    .presentation-chip--green {
        color: #047857;
        background: #ecfdf5;
        border-color: #a7f3d0;
    }
    .presentation-chip--red {
        color: #991b1b;
        background: #fef2f2;
        border-color: #fecaca;
    }
    .presentation-output {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        padding: 12px;
        min-height: 74px;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        color: #334155;
        font-size: 13px;
        line-height: 1.5;
    }
    .presentation-credentials {
        display: none;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        background: #eff6ff;
        padding: 12px;
        gap: 8px;
    }
    .presentation-credentials.is-visible {
        display: grid;
    }
    .presentation-credentials code {
        display: block;
        padding: 8px;
        border-radius: 6px;
        background: #fff;
        color: #0f172a;
        overflow-wrap: anywhere;
    }
    .presentation-audit {
        display: grid;
        gap: 8px;
        max-height: 320px;
        overflow: auto;
    }
    .presentation-audit article {
        display: grid;
        gap: 4px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px;
        background: #fff;
    }
    .presentation-audit strong {
        font-size: 13px;
    }
    .presentation-audit span {
        color: #64748b;
        font-size: 12px;
    }
    @media (max-width: 1120px) {
        .presentation-workspaces__grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 720px) {
        .presentation-field-row,
        .presentation-checks,
        .presentation-stat-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="presentation-workspaces"
      data-presentation-workspaces
      data-state-url="<?php echo htmlspecialchars(apiUrl('presentations/state.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-create-url="<?php echo htmlspecialchars(apiUrl('presentations/workspaces.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-arm-url="<?php echo htmlspecialchars(apiUrl('presentations/arm.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-run-url="<?php echo htmlspecialchars(apiUrl('presentations/run.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-revoke-url="<?php echo htmlspecialchars(apiUrl('presentations/revoke.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-seed-url="<?php echo htmlspecialchars(apiUrl('presentations/seed.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-archive-url="<?php echo htmlspecialchars(apiUrl('presentations/archive.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-audit-url="<?php echo htmlspecialchars(apiUrl('presentations/audit.php'), ENT_QUOTES, 'UTF-8'); ?>"
      data-csrf="<?php echo htmlspecialchars(Security::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
      data-can-live="<?php echo $canLive ? '1' : '0'; ?>"
      data-can-archive="<?php echo $canArchive ? '1' : '0'; ?>">
    <section class="presentation-workspaces__header">
        <div>
            <h1>Presentation Workspaces</h1>
            <p>Audience-specific workspaces with temporary owner access, seeded context, guarded delivery, and audit history.</p>
        </div>
        <div class="presentation-workspaces__actions">
            <a class="presentation-btn" href="<?php echo htmlspecialchars(publicUrl('workspaces.php'), ENT_QUOTES, 'UTF-8'); ?>">Workspaces</a>
            <?php if ($operator || Authorization::can('demo.presentation.audit', $user)): ?>
                <a class="presentation-btn presentation-btn--soft" href="<?php echo htmlspecialchars(publicUrl('demo_presentations.php'), ENT_QUOTES, 'UTF-8'); ?>">MetroDrive QA Harness</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="presentation-stat-row" data-summary></section>

    <section class="presentation-workspaces__grid">
        <div class="presentation-panel">
            <div class="presentation-panel__header">
                <h2>Create Workspace</h2>
            </div>
            <div class="presentation-panel__body">
                <form class="presentation-form" data-create-form>
                    <label class="presentation-field">
                        <span>Prospect name</span>
                        <input name="prospect_name" required autocomplete="off">
                    </label>
                    <div class="presentation-field-row">
                        <label class="presentation-field">
                            <span>Email</span>
                            <input type="email" name="prospect_email" required autocomplete="off">
                        </label>
                        <label class="presentation-field">
                            <span>WhatsApp phone</span>
                            <input name="prospect_phone" required autocomplete="off" placeholder="+254...">
                        </label>
                    </div>
                    <label class="presentation-field">
                        <span>Company</span>
                        <input name="prospect_company" autocomplete="off">
                    </label>
                    <label class="presentation-field">
                        <span>Pitch title</span>
                        <input name="pitch_title" autocomplete="off">
                    </label>
                    <div class="presentation-field-row">
                        <label class="presentation-field">
                            <span>Audience type</span>
                            <input name="audience_key" autocomplete="off" placeholder="Sales team, training provider...">
                        </label>
                        <label class="presentation-field">
                            <span>Expiry hours</span>
                            <input type="number" min="1" max="72" name="ttl_hours" value="4">
                        </label>
                    </div>
                    <label class="presentation-field">
                        <span>Seed pack</span>
                        <select name="seed_pack_key">
                            <?php foreach ($seedPacks as $key => $pack): ?>
                                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string) ($pack['label'] ?? $key), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="presentation-field">
                        <span>Feature emphasis</span>
                        <div class="presentation-checks">
                            <?php foreach (['Inbox triage', 'WhatsApp', 'Email digest', 'Automation battery', 'Escalations', 'Reports'] as $feature): ?>
                                <label><input type="checkbox" name="feature_emphasis[]" value="<?php echo htmlspecialchars($feature, ENT_QUOTES, 'UTF-8'); ?>"> <?php echo htmlspecialchars($feature, ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <label class="presentation-field">
                        <span>Approved live recipients</span>
                        <textarea name="approved_recipients" placeholder="Name | email@example.com | +254..."></textarea>
                    </label>
                    <button class="presentation-btn presentation-btn--primary" type="submit">Create Presentation Workspace</button>
                </form>

                <div class="presentation-credentials" data-credentials></div>
            </div>
        </div>

        <div class="presentation-detail">
            <div class="presentation-panel">
                <div class="presentation-panel__header">
                    <h2>Sessions</h2>
                    <button class="presentation-btn" type="button" data-refresh>Refresh</button>
                </div>
                <div class="presentation-panel__body">
                    <div class="presentation-list" data-session-list></div>
                </div>
            </div>

            <div class="presentation-panel">
                <div class="presentation-panel__header">
                    <h2 data-selected-title>No workspace selected</h2>
                    <div class="presentation-meta" data-selected-meta></div>
                </div>
                <div class="presentation-panel__body">
                    <div class="presentation-workspaces__actions">
                        <a class="presentation-btn presentation-btn--primary" href="#" data-open-workspace target="_blank" rel="noopener">Open Workspace</a>
                        <button class="presentation-btn" type="button" data-arm-live <?php echo $canLive ? '' : 'disabled'; ?>>Arm Live Mode</button>
                        <button class="presentation-btn" type="button" data-reseed>Reseed</button>
                        <button class="presentation-btn" type="button" data-revoke>Revoke</button>
                        <button class="presentation-btn presentation-btn--danger" type="button" data-archive <?php echo $canArchive ? '' : 'disabled'; ?>>Archive</button>
                    </div>

                    <div class="presentation-field-row">
                        <label class="presentation-field">
                            <span>Scenario</span>
                            <select data-scenario-select></select>
                        </label>
                        <label class="presentation-field">
                            <span>Delivery</span>
                            <select data-delivery-mode>
                                <option value="simulated">Simulated</option>
                                <option value="live">Live with preflight</option>
                            </select>
                        </label>
                    </div>
                    <div class="presentation-workspaces__actions">
                        <button class="presentation-btn" type="button" data-preview>Preview</button>
                        <button class="presentation-btn presentation-btn--primary" type="button" data-run>Run Scenario</button>
                    </div>
                    <div class="presentation-output" data-output>Select a session to preview or run scenarios.</div>
                </div>
            </div>

            <div class="presentation-panel">
                <div class="presentation-panel__header">
                    <h3>Audit</h3>
                </div>
                <div class="presentation-panel__body">
                    <div class="presentation-audit" data-audit></div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    const root = document.querySelector('[data-presentation-workspaces]');
    if (!root) return;

    const csrf = root.dataset.csrf || '';
    const canLive = root.dataset.canLive === '1';
    let state = { sessions: [], scenarios: {}, recent_audit: [] };
    let selectedId = 0;
    let lastPreview = null;

    const $ = (selector) => root.querySelector(selector);
    const summary = $('[data-summary]');
    const list = $('[data-session-list]');
    const output = $('[data-output]');
    const audit = $('[data-audit]');
    const scenarioSelect = $('[data-scenario-select]');
    const credentials = $('[data-credentials]');

    function api(url, payload, method = 'POST') {
        const options = {
            method,
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            credentials: 'same-origin'
        };
        if (payload) {
            options.body = JSON.stringify(Object.assign({csrf_token: csrf}, payload));
        }
        return fetch(url, options).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Request failed');
            }
            return data;
        });
    }

    function selectedSession() {
        return state.sessions.find((session) => Number(session.id) === Number(selectedId)) || null;
    }

    function renderSummary() {
        const s = state.summary || {};
        summary.innerHTML = [
            ['Active', s.active_sessions || 0],
            ['Armed', s.armed_sessions || 0],
            ['Archived', s.archived_sessions || 0],
            ['Total', s.total_sessions || 0]
        ].map(([label, value]) => '<div class="presentation-stat"><strong>' + value + '</strong><span>' + label + '</span></div>').join('');
    }

    function renderSessions() {
        if (!state.sessions.length) {
            list.innerHTML = '<p>No presentation workspaces yet.</p>';
            return;
        }
        list.innerHTML = state.sessions.map((session) => {
            const active = Number(session.id) === Number(selectedId) ? ' is-active' : '';
            const armed = session.live_armed ? ' | live armed' : '';
            return '<button type="button" class="presentation-session-btn' + active + '" data-session-id="' + session.id + '">' +
                '<strong>' + escapeHtml(session.pitch_title || session.workspace_name || 'Presentation workspace') + '</strong>' +
                '<span>' + escapeHtml(session.company || session.name || '') + '</span>' +
                '<span>' + escapeHtml(session.status || '') + ' | expires ' + escapeHtml(session.expires_at || '') + armed + '</span>' +
                '</button>';
        }).join('');
    }

    function renderScenarios(session) {
        const scenarios = state.scenarios || {};
        scenarioSelect.innerHTML = Object.keys(scenarios).map((key) => {
            const scenario = scenarios[key] || {};
            return '<option value="' + escapeHtml(key) + '">' + escapeHtml(scenario.label || key) + '</option>';
        }).join('');
    }

    function renderSelected() {
        const session = selectedSession();
        const title = $('[data-selected-title]');
        const meta = $('[data-selected-meta]');
        const open = $('[data-open-workspace]');
        const arm = $('[data-arm-live]');
        if (!session) {
            title.textContent = 'No workspace selected';
            meta.innerHTML = '';
            open.setAttribute('href', '#');
            open.dataset.workspaceId = '';
            output.textContent = 'Select a session to preview or run scenarios.';
            renderScenarios(null);
            return;
        }
        title.textContent = session.pitch_title || session.workspace_name || 'Presentation workspace';
        meta.innerHTML = [
            chip(session.status || 'unknown', session.status === 'active' ? 'green' : 'red'),
            chip(session.seed_pack_key || 'pack'),
            chip(session.live_armed ? 'live armed' : 'simulated first', session.live_armed ? 'green' : '')
        ].join('');
        open.setAttribute('href', '#');
        open.dataset.workspaceId = String(session.workspace_id || '');
        arm.textContent = session.live_armed ? 'Disarm Live Mode' : 'Arm Live Mode';
        arm.dataset.armed = session.live_armed ? '1' : '0';
        arm.disabled = !canLive || session.status !== 'active';
        $('[data-reseed]').disabled = session.status !== 'active';
        $('[data-revoke]').disabled = session.status !== 'active';
        $('[data-archive]').disabled = root.dataset.canArchive !== '1' || session.status === 'archived';
        renderScenarios(session);
    }

    function renderAudit() {
        const rows = state.recent_audit || [];
        if (!rows.length) {
            audit.innerHTML = '<p>No audit records yet.</p>';
            return;
        }
        audit.innerHTML = rows.map((row) => {
            return '<article>' +
                '<strong>' + escapeHtml(row.status || '') + ' | ' + escapeHtml(row.channel || '') + ' | ' + escapeHtml(row.scenario_key || '') + '</strong>' +
                '<span>' + escapeHtml(row.subject || '') + '</span>' +
                '<span>' + escapeHtml(row.created_at || '') + (row.error_message ? ' | ' + escapeHtml(row.error_message) : '') + '</span>' +
                '</article>';
        }).join('');
    }

    function renderAll() {
        renderSummary();
        renderSessions();
        renderSelected();
        renderAudit();
    }

    function loadState() {
        return fetch(root.dataset.stateUrl, {credentials: 'same-origin'})
            .then((response) => response.json())
            .then((data) => {
                if (data.success === false) throw new Error(data.error || 'Unable to load state');
                state = data;
                if (!selectedId && state.sessions && state.sessions.length) {
                    selectedId = Number(state.sessions[0].id);
                }
                renderAll();
            })
            .catch((error) => {
                output.textContent = error.message;
            });
    }

    function serializeCreateForm(form) {
        const data = new FormData(form);
        const recipients = String(data.get('approved_recipients') || '').split(/\n+/).map((line) => {
            const parts = line.split('|').map((part) => part.trim()).filter(Boolean);
            if (!parts.length) return null;
            if (parts.length === 1) {
                const value = parts[0];
                return value.includes('@') ? {label: 'Approved email', email: value, can_email: true} : {label: 'Approved WhatsApp', phone: value, can_whatsapp: true};
            }
            return {label: parts[0] || 'Approved recipient', email: parts[1] || '', phone: parts[2] || '', can_email: !!parts[1], can_whatsapp: !!parts[2]};
        }).filter(Boolean);

        return {
            prospect_name: String(data.get('prospect_name') || ''),
            prospect_email: String(data.get('prospect_email') || ''),
            prospect_phone: String(data.get('prospect_phone') || ''),
            prospect_company: String(data.get('prospect_company') || ''),
            pitch_title: String(data.get('pitch_title') || ''),
            audience_key: String(data.get('audience_key') || ''),
            seed_pack_key: String(data.get('seed_pack_key') || 'sales_pipeline'),
            ttl_hours: Number(data.get('ttl_hours') || 4),
            feature_emphasis: data.getAll('feature_emphasis[]'),
            approved_recipients: recipients
        };
    }

    $('[data-create-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        const button = this.querySelector('button[type="submit"]');
        button.disabled = true;
        output.textContent = 'Creating presentation workspace...';
        api(root.dataset.createUrl, serializeCreateForm(this))
            .then((data) => {
                selectedId = Number(data.session && data.session.id || 0);
                const login = data.temporary_login || {};
                credentials.classList.add('is-visible');
                credentials.innerHTML = '<strong>Temporary presentation owner access</strong>' +
                    '<code>Email: ' + escapeHtml(login.email || '') + '</code>' +
                    '<code>Password: ' + escapeHtml(login.password || '') + '</code>' +
                    '<code>Magic link: ' + escapeHtml(login.magic_login_url || '') + '</code>' +
                    '<span>Expires: ' + escapeHtml(login.expires_at || '') + '</span>';
                output.textContent = 'Workspace created and seeded.';
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; })
            .finally(() => { button.disabled = false; });
    });

    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-session-id]');
        if (!button) return;
        selectedId = Number(button.dataset.sessionId || 0);
        lastPreview = null;
        renderAll();
    });

    $('[data-refresh]').addEventListener('click', loadState);

    $('[data-open-workspace]').addEventListener('click', function (event) {
        event.preventDefault();
        const session = selectedSession();
        if (!session || !session.workspace_id) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?php echo htmlspecialchars(publicUrl('workspace_switch.php'), ENT_QUOTES, 'UTF-8'); ?>';
        form.target = '_blank';
        [
            ['csrf_token', csrf],
            ['workspace_id', String(session.workspace_id)],
            ['return_to', 'dashboard.php?presentation=1']
        ].forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    });

    $('[data-preview]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        const live = $('[data-delivery-mode]').value === 'live';
        api(root.dataset.runUrl, {session_id: session.id, scenario_key: scenarioSelect.value, live, preview: true})
            .then((data) => {
                lastPreview = data;
                output.textContent = 'Channel: ' + data.channel + '\nRecipient: ' + ((data.recipient && (data.recipient.email || data.recipient.phone)) || '') + '\nSubject: ' + (data.subject || '') + '\n\n' + (data.body || '');
            })
            .catch((error) => { output.textContent = error.message; });
    });

    $('[data-run]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        const live = $('[data-delivery-mode]').value === 'live';
        const payload = {session_id: session.id, scenario_key: scenarioSelect.value, live};
        if (live) {
            payload.preflight_confirmed = !!lastPreview && lastPreview.scenario_key === scenarioSelect.value;
        }
        api(root.dataset.runUrl, payload)
            .then((data) => {
                output.textContent = JSON.stringify(data, null, 2);
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; });
    });

    $('[data-arm-live]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        api(root.dataset.armUrl, {session_id: session.id, armed: this.dataset.armed !== '1'})
            .then((data) => {
                output.textContent = 'Live mode updated.';
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; });
    });

    $('[data-reseed]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        api(root.dataset.seedUrl, {session_id: session.id})
            .then((data) => {
                output.textContent = JSON.stringify(data.seed || data, null, 2);
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; });
    });

    $('[data-revoke]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        api(root.dataset.revokeUrl, {session_id: session.id})
            .then(() => {
                output.textContent = 'Temporary access revoked.';
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; });
    });

    $('[data-archive]').addEventListener('click', function () {
        const session = selectedSession();
        if (!session) return;
        api(root.dataset.archiveUrl, {session_id: session.id})
            .then(() => {
                output.textContent = 'Presentation workspace archived.';
                return loadState();
            })
            .catch((error) => { output.textContent = error.message; });
    });

    function chip(text, tone) {
        const cls = tone === 'green' ? ' presentation-chip--green' : (tone === 'red' ? ' presentation-chip--red' : '');
        return '<span class="presentation-chip' + cls + '">' + escapeHtml(text) + '</span>';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    loadState();
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
