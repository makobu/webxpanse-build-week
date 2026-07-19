(function () {
    'use strict';
    var root = document.querySelector('[data-vcc-root]');
    if (!root) return;
    var csrf = root.getAttribute('data-csrf-token') || '';
    var sinceEventId = 0;
    var currentFilter = 'all';
    var timer = null;
    var activeCalls = 0;
    var lastCalls = [];
    var canViewTranscript = root.getAttribute('data-can-view-transcript') === '1';
    var canListenRecording = root.getAttribute('data-can-listen-recording') === '1';
    var canReviewInsight = root.getAttribute('data-can-review-insight') === '1';
    var canDeleteEvidence = root.getAttribute('data-can-delete-evidence') === '1';
    var voiceAgents = [];
    var providerCapabilities = {};
    var fallbackTransferAvailable = false;
    var contactLinkCallId = 0;

    function escapeHtml(value) {
        var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML;
    }
    function human(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
    function insightList(title, values) {
        if (!Array.isArray(values) && values && typeof values === 'object') {
            values = Object.keys(values).map(function (key) { return human(key) + ': ' + String(values[key]); });
        }
        values = Array.isArray(values) ? values : [];
        if (!values.length) return '';
        return '<section><h3>' + escapeHtml(title) + '</h3><ul>' + values.map(function (value) {
            return '<li>' + escapeHtml(typeof value === 'string' ? value : JSON.stringify(value)) + '</li>';
        }).join('') + '</ul></section>';
    }
    function duration(seconds) { seconds = Number(seconds || 0); return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'); }
    function relative(value) { if (!value) return '—'; var date = new Date(String(value).replace(' ', 'T')); return isNaN(date.getTime()) ? value : date.toLocaleString(); }
    function terminal(state) { return ['completed','cancelled','busy','no_answer','rejected','expired','provider_failed','policy_blocked'].indexOf(state) !== -1; }
    function setPollStatus(state) {
        var status = document.getElementById('vcc-poll-status');
        if (!status) return;
        status.classList.toggle('is-connecting', state === 'connecting');
        status.classList.toggle('is-delayed', state === 'delayed');
        status.textContent = state === 'delayed' ? 'Updates delayed' : (state === 'connecting' ? 'Connecting' : 'Live');
    }

    function render(data) {
        lastCalls = data.calls || [];
        voiceAgents = data.agents || [];
        providerCapabilities = data.provider_capabilities || {};
        fallbackTransferAvailable = data.fallback_transfer_available === true;
        activeCalls = Number(data.active_count || 0);
        document.getElementById('vcc-active-count').textContent = activeCalls;
        document.getElementById('vcc-concurrency-used').textContent = activeCalls;
        document.getElementById('vcc-concurrency-limit').textContent = (data.entitlements || {}).concurrent_calls || 0;
        var checks = ((data.readiness || {}).checks || []);
        var worker = checks.find(function (item) { return item.label === 'Voice workers'; });
        document.getElementById('vcc-worker-status').textContent = worker && worker.ok ? 'Healthy' : 'Needs worker';
        renderQueues(data.queues || []);
        renderLive(lastCalls.filter(function (call) { return !terminal(call.state); }));
        renderRows();
        if (data.last_event_id) sinceEventId = Number(data.last_event_id);
    }
    function renderQueues(queues) {
        var strip = document.getElementById('vcc-queue-strip');
        if (!strip) return;
        if (!queues.length) {
            strip.innerHTML = '<article><span>Queue routing</span><strong>Not configured</strong><small>Complete Queues &amp; Hours in setup.</small></article>';
            return;
        }
        strip.innerHTML = queues.map(function (queue) {
            var wait = Number(queue.waiting_calls || 0);
            var available = Number(queue.available_agents || 0);
            var status = queue.is_open ? (available > 0 ? 'Ready' : 'No agent available') : 'Closed';
            var statusClass = queue.is_open && available > 0 ? 'is-ready' : 'is-warning';
            return '<article class="' + statusClass + '"><div><span>' + escapeHtml(queue.name) + (queue.is_default ? ' · Default' : '') + '</span><strong>' + wait + ' waiting · ' + Number(queue.active_calls || 0) + ' active</strong></div><small>' + escapeHtml(status) + ' · ' + available + '/' + Number(queue.configured_agents || 0) + ' agents available' + (wait > 0 ? ' · oldest ' + duration(queue.oldest_wait_seconds || 0) : '') + '</small></article>';
        }).join('');
    }
    function renderLive(calls) {
        var panel = document.getElementById('vcc-live-calls');
        if (!calls.length) { panel.className = 'vcc-empty-state'; panel.innerHTML = '<i class="fas fa-wave-square"></i><p>No active calls.</p>'; return; }
        panel.className = '';
        panel.innerHTML = calls.map(function (call) {
            var number = call.direction === 'inbound' ? call.from_number : call.to_number;
            var transferOptions = voiceAgents.filter(function(agent){return agent.presence_status === 'available' && Number(agent.id) !== Number(call.agent_id);}).map(function(agent){return '<option value="agent:' + Number(agent.id) + '">' + escapeHtml(agent.display_name) + '</option>';}).join('');
            if (fallbackTransferAvailable) transferOptions += '<option value="fallback">Verified fallback number</option>';
            var controls = call.state === 'in_progress' ? '<div class="vcc-call-controls"><select data-vcc-transfer="' + Number(call.id) + '"><option value="">Transfer…</option>' + transferOptions + '</select>' + (providerCapabilities.remote_end ? '<button type="button" data-vcc-end="' + Number(call.id) + '">End</button>' : '<small>Hang up from your phone or SIP client</small>') + '</div>' : '';
            return '<article class="vcc-call-card"><span class="vcc-call-icon"><i class="fas fa-phone"></i></span><div><strong>' + escapeHtml(number || 'Private number') + '</strong><small>' + escapeHtml(human(call.direction)) + (call.agent_name ? ' · ' + escapeHtml(call.agent_name) : '') + '</small>' + controls + '</div><span class="vcc-call-state">' + escapeHtml(human(call.state)) + '</span></article>';
        }).join('');
    }
    function renderRows() {
        var rows = lastCalls.filter(function (call) {
            if (currentFilter === 'missed') return ['no_answer','busy','rejected'].indexOf(call.state) !== -1;
            if (currentFilter === 'unmatched') return !call.contact_id;
            return true;
        });
        document.getElementById('vcc-call-rows').innerHTML = rows.length ? rows.map(function (call) {
            var number = call.direction === 'inbound' ? call.from_number : call.to_number;
            var context = canViewTranscript && call.transcript_id ? '<button type="button" class="vcc-context-button" data-vcc-call-context="' + Number(call.id) + '">Review</button>' : '';
            if (!call.contact_id) context += '<button type="button" class="vcc-context-button" data-vcc-link-contact="' + Number(call.id) + '">Link contact</button>';
            if (terminal(call.state)) context += '<select class="vcc-disposition-select" data-vcc-disposition="' + Number(call.id) + '"><option value="">' + escapeHtml(call.disposition ? human(call.disposition) : 'Set outcome…') + '</option><option value="connected">Connected</option><option value="follow_up">Follow up</option><option value="qualified">Qualified</option><option value="not_interested">Not interested</option><option value="wrong_number">Wrong number</option><option value="voicemail">Voicemail</option><option value="other">Other</option></select>';
            if (!context) context = '—';
            return '<tr><td><strong>' + escapeHtml(number || 'Private number') + '</strong><br><small>' + escapeHtml(human(call.direction)) + (call.contact_id ? '' : ' · Unmatched') + '</small></td><td><span class="vcc-status-pill" data-state="' + escapeHtml(call.state) + '">' + escapeHtml(human(call.state)) + '</span></td><td>' + escapeHtml(call.agent_name || '—') + '</td><td><span class="vcc-status-pill" data-state="' + escapeHtml(call.transcription_status) + '">' + escapeHtml(human(call.transcription_status)) + '</span></td><td>' + context + '</td><td>' + duration(call.duration_seconds) + '</td><td>' + escapeHtml(relative(call.created_at)) + '</td></tr>';
        }).join('') : '<tr><td colspan="7" class="vcc-loading">No calls match this view.</td></tr>';
        var count = document.getElementById('vcc-history-count');
        if (count) count.textContent = rows.length + (rows.length === 1 ? ' call' : ' calls');
    }

    async function openContext(callId) {
        var dialog = document.getElementById('vcc-insight-dialog');
        var content = document.getElementById('vcc-dialog-content');
        var actions = document.getElementById('vcc-dialog-actions');
        content.innerHTML = '<p>Loading protected transcript and insight…</p>'; actions.innerHTML = '';
        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', 'open');
        try {
            var response = await fetch('../api/voice/transcript.php?call_id=' + encodeURIComponent(callId), {credentials:'same-origin',headers:{'Accept':'application/json'}});
            var result = await response.json(); if (!result.success) throw new Error(result.error || 'Call context could not be loaded.');
            var data = result.data || {}; var insight = data.insight || {};
            var recording = lastCalls.find(function (call) { return Number(call.id) === Number(callId); });
            content.innerHTML = '<section><h3>Summary</h3><p>' + escapeHtml(insight.summary || 'No summary available.') + '</p></section>' +
                (insight.relationship_context ? '<section><h3>Relationship context</h3><p>' + escapeHtml(insight.relationship_context) + '</p></section>' : '') +
                '<div class="vcc-dialog-grid"><section><h3>Intent</h3><p>' + escapeHtml(human(insight.intent || 'unknown')) + '</p></section><section><h3>Next step</h3><p>' + escapeHtml(insight.next_step || 'Not identified') + '</p></section></div>' +
                '<div class="vcc-dialog-grid">' + insightList('Pains', insight.pains) + insightList('Goals', insight.goals) + insightList('Objections', insight.objections) + insightList('Commitments', insight.commitments) + '</div>' +
                insightList('Requested actions', insight.requested_actions) + insightList('Task suggestions', insight.task_suggestions) + insightList('Contact update suggestions', insight.contact_updates) +
                insightList('Applied CRM actions', insight.applied_actions) + insightList('Pending or skipped actions', insight.skipped_actions) +
                '<section><h3>Transcript</h3><pre>' + escapeHtml(data.transcript || '') + '</pre></section>' +
                (canListenRecording && recording && recording.recording_id ? '<audio controls preload="none" src="../api/voice/recording.php?id=' + Number(recording.recording_id) + '"></audio>' : '');
            if (canReviewInsight && insight.review_status === 'pending') {
                actions.innerHTML = '<button type="button" class="btn-premium-secondary" data-vcc-review="dismissed">Dismiss</button><button type="button" class="btn-premium-primary" data-vcc-review="accepted">Apply permitted CRM actions</button>';
                actions.querySelectorAll('[data-vcc-review]').forEach(function (button) { button.addEventListener('click', function () { reviewInsight(callId, button.getAttribute('data-vcc-review'), dialog); }); });
            } else if (insight.review_status) actions.textContent = 'Review status: ' + human(insight.review_status);
            var exportLink = document.createElement('a');
            exportLink.className = 'btn-premium-secondary';
            exportLink.href = '../api/voice/transcript_export.php?call_id=' + encodeURIComponent(callId);
            exportLink.textContent = 'Export evidence';
            actions.appendChild(exportLink);
            if (canDeleteEvidence) {
                var deleteButton = document.createElement('button');
                deleteButton.type = 'button'; deleteButton.className = 'btn-premium-secondary is-danger';
                deleteButton.textContent = 'Delete raw evidence';
                deleteButton.addEventListener('click', function () { deleteEvidence(callId, dialog); });
                actions.appendChild(deleteButton);
            }
        } catch (error) { content.innerHTML = '<p class="is-error">' + escapeHtml(error.message) + '</p>'; }
    }

    async function deleteEvidence(callId, dialog) {
        if (!window.confirm('Delete the CRM transcript and recording pointer for this call? This cannot be undone. Provider-account retention remains separate.')) return;
        var response = await fetch('../api/voice/evidence.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({csrf_token:csrf,action:'delete',call_id:callId})});
        var data = await response.json();
        if (!data.success) { window.alert(data.error || 'Raw evidence could not be deleted.'); return; }
        window.alert(data.message); dialog.close(); poll();
    }

    async function reviewInsight(callId, status, dialog) {
        var response = await fetch('../api/voice/review.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({csrf_token:csrf,call_id:callId,status:status})});
        var data = await response.json(); if (!data.success) { window.alert(data.error || 'Review could not be saved.'); return; }
        if (status === 'accepted') {
            var applied = data.application && Array.isArray(data.application.applied) ? data.application.applied.length : 0;
            window.alert(applied ? applied + ' permitted CRM action(s) applied.' : 'Review saved. Workspace policy kept these items as suggestions only.');
        }
        dialog.close(); poll();
    }

    function openContactLink(callId) {
        contactLinkCallId = Number(callId || 0);
        var dialog = document.getElementById('vcc-contact-dialog');
        document.getElementById('vcc-link-contact-id').value = '';
        document.getElementById('vcc-create-contact-form').reset();
        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', 'open');
    }

    async function saveContactLink(payload) {
        var response = await fetch('../api/voice/contact.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(Object.assign({csrf_token:csrf,call_id:contactLinkCallId},payload))});
        var data = await response.json();
        if (!data.success) throw new Error(data.error || 'The contact could not be linked.');
        document.getElementById('vcc-contact-dialog').close();
        poll();
    }

    async function controlCall(payload) {
        var response = await fetch('../api/voice/control.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(Object.assign({csrf_token:csrf},payload))});
        var data = await response.json(); if (!data.success) throw new Error(data.error || 'Call control failed.'); poll();
    }
    async function poll() {
        try {
            var response = await fetch('../api/voice/status.php?since_event_id=' + encodeURIComponent(sinceEventId), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            var data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Voice updates are unavailable.');
            render(data);
            setPollStatus('live');
        } catch (error) { setPollStatus('delayed'); }
        clearTimeout(timer); timer = setTimeout(poll, document.hidden || activeCalls === 0 ? 10000 : 2000);
    }
    document.addEventListener('visibilitychange', function () { clearTimeout(timer); poll(); });

    var search = document.getElementById('vcc-contact-search');
    search.addEventListener('input', function () {
        var match = Array.prototype.find.call(document.querySelectorAll('#vcc-contact-options option'), function (option) { return option.value === search.value; });
        document.getElementById('vcc-contact-id').value = match ? (match.getAttribute('data-contact-id') || '') : '';
    });
    document.getElementById('vcc-dial-form').addEventListener('submit', async function (event) {
        event.preventDefault(); var destination = search.value.trim(); var contactId = document.getElementById('vcc-contact-id').value;
        if (!contactId && !window.confirm('This number is not linked to a selected CRM contact. Confirm the destination before queuing the call:\n\n' + destination)) return;
        var button = event.currentTarget.querySelector('button[type="submit"]'); button.disabled = true;
        var message = document.getElementById('vcc-action-message'); message.hidden = true; message.classList.remove('is-error');
        try {
            var response = await fetch('../api/voice/initiate.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({csrf_token:csrf,destination:destination,contact_id:contactId || null,manual_destination_confirmed:!contactId}) });
            var data = await response.json(); if (!data.success) throw new Error(data.error || 'Call could not be queued.');
            message.textContent = data.message || 'Call queued.'; message.hidden = false; search.value = ''; document.getElementById('vcc-contact-id').value = ''; poll();
        } catch (error) { message.textContent = error.message; message.classList.add('is-error'); message.hidden = false; }
        finally { button.disabled = false; }
    });
    document.getElementById('vcc-presence').addEventListener('change', async function (event) {
        var value = event.target.value;
        try {
            var response = await fetch('../api/voice/presence.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({csrf_token:csrf,presence:value})});
            var data = await response.json(); if (!data.success) throw new Error(data.error || 'Availability could not be changed.');
        } catch (error) { window.alert(error.message); poll(); }
    });
    document.querySelectorAll('[data-vcc-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('[data-vcc-filter]').forEach(function (item) {
                item.classList.remove('is-active');
                item.setAttribute('aria-pressed', 'false');
            });
            button.classList.add('is-active');
            button.setAttribute('aria-pressed', 'true');
            currentFilter = button.getAttribute('data-vcc-filter') || 'all';
            renderRows();
        });
    });
    document.getElementById('vcc-call-rows').addEventListener('click', function (event) { var button = event.target.closest('[data-vcc-call-context]'); if (button) { openContext(Number(button.getAttribute('data-vcc-call-context'))); return; } var link = event.target.closest('[data-vcc-link-contact]'); if (link) openContactLink(Number(link.getAttribute('data-vcc-link-contact'))); });
    document.getElementById('vcc-call-rows').addEventListener('change', function(event){var select=event.target.closest('[data-vcc-disposition]');if(!select||!select.value)return;controlCall({action:'disposition',call_id:Number(select.getAttribute('data-vcc-disposition')),disposition:select.value,notes:''}).catch(function(error){window.alert(error.message);select.value='';});});
    document.getElementById('vcc-live-calls').addEventListener('click', function(event){var button=event.target.closest('[data-vcc-end]');if(!button)return;if(window.confirm('End this active call?'))controlCall({action:'end',call_id:Number(button.getAttribute('data-vcc-end'))}).catch(function(error){window.alert(error.message);});});
    document.getElementById('vcc-live-calls').addEventListener('change', function(event){var select=event.target.closest('[data-vcc-transfer]');if(!select||!select.value)return;var value=String(select.value);var payload={action:value==='fallback'?'transfer_fallback':'transfer',call_id:Number(select.getAttribute('data-vcc-transfer'))};if(value.indexOf('agent:')===0)payload.agent_id=Number(value.slice(6));controlCall(payload).catch(function(error){window.alert(error.message);select.value='';});});
    document.getElementById('vcc-link-contact-button').addEventListener('click', function(){var contactId=Number(document.getElementById('vcc-link-contact-id').value||0);if(!contactId){window.alert('Choose an existing contact first.');return;}saveContactLink({action:'link',contact_id:contactId}).catch(function(error){window.alert(error.message);});});
    document.getElementById('vcc-create-contact-form').addEventListener('submit', function(event){event.preventDefault();var form=new FormData(event.currentTarget);saveContactLink({action:'create',first_name:String(form.get('first_name')||''),last_name:String(form.get('last_name')||''),email:String(form.get('email')||'')}).catch(function(error){window.alert(error.message);});});
    poll();
})();
