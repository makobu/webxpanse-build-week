(function () {
    'use strict';

    var config = window.CrmSessionUx || {};
    var originalFetch = window.fetch ? window.fetch.bind(window) : null;
    var draftKey = 'crm:session-ux:draft:' + window.location.pathname + window.location.search;
    var heartbeatThrottleMs = 60000;
    var modal = null;
    var modalKind = '';
    var warningTimer = null;
    var countdownTimer = null;
    var heartbeatTimer = null;
    var statusRequest = null;
    var renewalRequest = null;
    var handledAuthFailure = false;
    var previousFocus = null;
    var lastHeartbeatAt = Date.now();
    var lastInteractionAt = Date.now();

    function isSensitiveField(field) {
        var type = String(field.type || '').toLowerCase();
        var key = String(field.name || field.id || '').toLowerCase();
        return !key
            || field.disabled
            || ['password', 'file', 'submit', 'button', 'reset'].indexOf(type) !== -1
            || (type === 'hidden' && field.getAttribute('data-session-draft') !== 'true')
            || /csrf|token|secret|password|api[_-]?key|private[_-]?key|credential/.test(key);
    }

    function formDraftIdentity(form, formIndex) {
        var explicit = form.getAttribute('data-session-draft-key') || form.id || form.getAttribute('name');
        if (explicit) return 'form:' + explicit;
        var action = String(form.getAttribute('action') || window.location.pathname);
        var method = String(form.getAttribute('method') || 'get').toLowerCase();
        return 'form:' + method + ':' + action + ':' + formIndex;
    }

    function fieldDraftKey(field, fieldIndex, forms) {
        if (field.id) return 'id:' + field.id;
        var form = field.form || (field.closest ? field.closest('form') : null);
        if (form) {
            var formIndex = forms.indexOf(form);
            return formDraftIdentity(form, formIndex) + ':field:' + (field.name || fieldIndex);
        }
        return 'document:field:' + (field.name || '') + ':' + fieldIndex;
    }

    function draftFields() {
        return Array.prototype.slice.call(document.querySelectorAll('input, textarea, select'));
    }

    function saveDrafts() {
        if (!window.sessionStorage) return;

        var draft = {};
        var forms = Array.prototype.slice.call(document.querySelectorAll('form'));
        draftFields().forEach(function (field, fieldIndex) {
            if (isSensitiveField(field)) return;
            var key = fieldDraftKey(field, fieldIndex, forms);
            var type = String(field.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                draft[key] = { checked: !!field.checked };
            } else if (field.multiple && field.options) {
                draft[key] = {
                    value: Array.prototype.slice.call(field.options).filter(function (option) {
                        return option.selected;
                    }).map(function (option) {
                        return option.value;
                    })
                };
            } else {
                draft[key] = { value: field.value };
            }
        });

        if (Object.keys(draft).length > 0) {
            window.sessionStorage.setItem(draftKey, JSON.stringify(draft));
        }
    }

    function clearDrafts() {
        if (window.sessionStorage) {
            window.sessionStorage.removeItem(draftKey);
        }
    }

    function restoreDrafts() {
        if (!window.sessionStorage) return;
        var raw = window.sessionStorage.getItem(draftKey);
        if (!raw) return;

        var draft = {};
        try {
            draft = JSON.parse(raw) || {};
        } catch (error) {
            clearDrafts();
            return;
        }

        var forms = Array.prototype.slice.call(document.querySelectorAll('form'));
        draftFields().forEach(function (field, fieldIndex) {
            if (isSensitiveField(field)) return;
            var form = field.form || (field.closest ? field.closest('form') : null);
            var formIndex = form ? forms.indexOf(form) : -1;
            var name = field.name || field.id;
            var saved = draft[fieldDraftKey(field, fieldIndex, forms)] || draft['f' + formIndex + ':' + name];
            if (!saved) return;
            if (Object.prototype.hasOwnProperty.call(saved, 'checked')) {
                field.checked = !!saved.checked;
            } else if (field.multiple && Array.isArray(saved.value)) {
                Array.prototype.slice.call(field.options || []).forEach(function (option) {
                    option.selected = saved.value.indexOf(option.value) !== -1;
                });
            } else if (Object.prototype.hasOwnProperty.call(saved, 'value')) {
                field.value = saved.value;
            }
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });

        clearDrafts();
        window.dispatchEvent(new CustomEvent('crm:session-draft-restored'));
    }

    function injectStyles() {
        if (document.getElementById('crm-session-ux-styles')) return;
        var style = document.createElement('style');
        style.id = 'crm-session-ux-styles';
        style.textContent = [
            '.crm-session-ux-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.42);display:flex;align-items:flex-end;justify-content:center;padding:24px;}',
            '.crm-session-ux-backdrop[hidden]{display:none!important;}',
            '.crm-session-ux-dialog{width:min(500px,100%);background:#fff;color:#111827;border:1px solid rgba(15,23,42,.14);border-radius:10px;box-shadow:0 24px 70px rgba(15,23,42,.28);padding:20px;}',
            '.crm-session-ux-title{margin:0 0 8px;font-size:18px;line-height:1.25;font-weight:700;}',
            '.crm-session-ux-copy{margin:0;color:#4b5563;font-size:14px;line-height:1.55;}',
            '.crm-session-ux-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:18px;}',
            '.crm-session-ux-actions button,.crm-session-ux-actions a{border:0;border-radius:6px;padding:10px 14px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer;}',
            '.crm-session-ux-actions button:disabled{cursor:wait;opacity:.68;}',
            '.crm-session-ux-primary{background:#005fcc;color:#fff;}',
            '.crm-session-ux-secondary{background:#eef2f7;color:#1f2937;}',
            '@media (min-width:640px){.crm-session-ux-backdrop{align-items:center}.crm-session-ux-dialog{padding:22px}}'
        ].join('');
        document.head.appendChild(style);
    }

    function ensureModal() {
        if (modal) return modal;
        injectStyles();
        var backdrop = document.createElement('div');
        backdrop.className = 'crm-session-ux-backdrop';
        backdrop.hidden = true;
        backdrop.setAttribute('role', 'presentation');
        backdrop.innerHTML = [
            '<section class="crm-session-ux-dialog" role="dialog" aria-modal="true" aria-labelledby="crm-session-ux-title" aria-describedby="crm-session-ux-copy">',
            '<h2 class="crm-session-ux-title" id="crm-session-ux-title"></h2>',
            '<p class="crm-session-ux-copy" id="crm-session-ux-copy" data-session-ux-copy aria-live="polite"></p>',
            '<div class="crm-session-ux-actions">',
            '<button type="button" class="crm-session-ux-secondary" data-session-ux-check>Continue working</button>',
            '<a class="crm-session-ux-primary" href="#" data-session-ux-login>Sign in again</a>',
            '</div>',
            '</section>'
        ].join('');
        document.body.appendChild(backdrop);

        backdrop.querySelector('[data-session-ux-check]').addEventListener('click', function () {
            renewSession(true);
        });
        backdrop.querySelector('[data-session-ux-login]').addEventListener('click', function () {
            saveDrafts();
        });
        backdrop.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab') return;
            var focusable = Array.prototype.slice.call(backdrop.querySelectorAll('button:not([hidden]):not(:disabled), a[href]:not([hidden])'));
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        modal = backdrop;
        return modal;
    }

    function formatRemaining(seconds) {
        var value = Math.max(0, Math.ceil(Number(seconds) || 0));
        var minutes = Math.floor(value / 60);
        var remainder = value % 60;
        return minutes + ':' + String(remainder).padStart(2, '0');
    }

    function stopCountdown() {
        if (countdownTimer) window.clearInterval(countdownTimer);
        countdownTimer = null;
    }

    function startCountdown(secondsRemaining) {
        stopCountdown();
        var deadline = Date.now() + Math.max(0, Number(secondsRemaining) || 0) * 1000;
        var copy = ensureModal().querySelector('[data-session-ux-copy]');
        function update() {
            var seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            copy.textContent = 'Your session will expire in ' + formatRemaining(seconds) + '. Continue working to keep this page and your unsaved work available.';
            if (seconds <= 0) {
                stopCountdown();
                refreshStatus(true);
            }
        }
        update();
        countdownTimer = window.setInterval(update, 1000);
    }

    function setCheckBusy(busy) {
        if (!modal) return;
        var check = modal.querySelector('[data-session-ux-check]');
        check.disabled = !!busy;
        check.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            check.textContent = 'Confirming…';
        } else if (modalKind === 'warning') {
            check.textContent = 'Continue working';
        } else if (modalKind === 'network') {
            check.textContent = 'Retry';
        } else {
            check.textContent = 'Check session';
        }
    }

    function showModal(kind, auth, secondsRemaining) {
        var dialog = ensureModal();
        var title = dialog.querySelector('#crm-session-ux-title');
        var copy = dialog.querySelector('[data-session-ux-copy]');
        var login = dialog.querySelector('[data-session-ux-login]');
        modalKind = kind;
        stopCountdown();

        if (kind === 'warning') {
            title.textContent = 'Your session will expire soon';
            login.setAttribute('href', (auth && auth.reauth_url) || config.reauthUrl || config.loginUrl || '/login.php?reauth=1');
            saveDrafts();
            startCountdown(secondsRemaining || config.warnBeforeSeconds || 300);
        } else if (kind === 'network') {
            title.textContent = 'We couldn’t confirm your session';
            copy.textContent = 'Your work is still on this page. Check your connection and retry, or sign in again in this tab.';
            login.setAttribute('href', config.reauthUrl || config.loginUrl || '/login.php?reauth=1');
            saveDrafts();
        } else if (kind === 'csrf_invalid') {
            title.textContent = 'Your security token is out of date';
            copy.textContent = 'Your draft has been kept in this browser. Sign in again, then return here to continue.';
            login.setAttribute('href', (auth && auth.reauth_url) || config.reauthUrl || (auth && auth.login_url) || config.loginUrl || '/login.php?reauth=1');
            saveDrafts();
        } else {
            title.textContent = 'Your session expired';
            copy.textContent = 'Your draft has been kept in this browser. Sign in again, then return here to continue.';
            login.setAttribute('href', (auth && auth.login_url) || config.loginUrl || '/login.php?expired=1');
            saveDrafts();
        }

        if (dialog.hidden) previousFocus = document.activeElement;
        dialog.hidden = false;
        setCheckBusy(false);
        window.setTimeout(function () {
            var target = kind === 'warning' || kind === 'network'
                ? dialog.querySelector('[data-session-ux-check]')
                : dialog.querySelector('[data-session-ux-login]');
            target.focus();
        }, 0);
    }

    function hideModal(clearSavedDraft) {
        stopCountdown();
        if (modal) modal.hidden = true;
        modalKind = '';
        if (clearSavedDraft) clearDrafts();
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        previousFocus = null;
    }

    function applyFreshCsrf(token) {
        if (!token) return;
        config.csrfToken = token;
        if (window.SessionAutomationConfig) window.SessionAutomationConfig.csrfToken = token;
        Array.prototype.slice.call(document.querySelectorAll('input[name="csrf_token"]')).forEach(function (input) {
            input.value = token;
        });
        Array.prototype.slice.call(document.querySelectorAll('[data-csrf-token]')).forEach(function (element) {
            element.setAttribute('data-csrf-token', token);
        });
    }

    function updateAuthUrls(payload) {
        if (!payload) return;
        if (payload.login_url) config.loginUrl = payload.login_url;
        if (payload.reauth_url) config.reauthUrl = payload.reauth_url;
    }

    function clearTimers() {
        if (warningTimer) window.clearTimeout(warningTimer);
        warningTimer = null;
    }

    function scheduleWarning(secondsRemaining) {
        clearTimers();
        var seconds = Number(secondsRemaining || 0);
        if (seconds <= 0) return;
        config.secondsRemaining = seconds;
        var warnBefore = Number(config.warnBeforeSeconds || 300);
        var delay = Math.max(0, (seconds - warnBefore) * 1000);
        warningTimer = window.setTimeout(function () {
            refreshStatus(true);
        }, delay);
    }

    function parseJsonResponse(response) {
        return response.json().catch(function () { return null; }).then(function (payload) {
            return { response: response, payload: payload };
        });
    }

    function handleAuthenticatedPayload(payload, clearWarningDraft) {
        applyFreshCsrf(payload.csrf_token || '');
        updateAuthUrls(payload);
        scheduleWarning(payload.seconds_remaining);
        handledAuthFailure = false;
        lastHeartbeatAt = Date.now();
        if (clearWarningDraft || !modal || modal.hidden) {
            hideModal(!!clearWarningDraft);
        }
        window.dispatchEvent(new CustomEvent('crm:session-active', { detail: payload }));
    }

    function handleUnauthenticatedPayload(payload) {
        var auth = payload && payload.auth ? payload.auth : {};
        var state = String((auth && auth.state) || (payload && payload.state) || 'expired');
        showModal(state === 'csrf_invalid' ? 'csrf_invalid' : 'expired', auth);
    }

    function refreshStatus(fromUserAction) {
        if (!originalFetch || !config.statusUrl) return Promise.resolve(null);
        if (statusRequest) return statusRequest;

        statusRequest = originalFetch(config.statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CRM-Session-Passive': '1'
            }
        }).then(parseJsonResponse).then(function (result) {
            var payload = result.payload;
            if (result.response.ok && payload && payload.authenticated) {
                applyFreshCsrf(payload.csrf_token || '');
                updateAuthUrls(payload);
                handledAuthFailure = false;
                var seconds = Number(payload.seconds_remaining || 0);
                if (seconds > Number(config.warnBeforeSeconds || 300)) {
                    scheduleWarning(seconds);
                    hideModal(true);
                } else if (seconds > 0) {
                    showModal('warning', payload, seconds);
                } else {
                    showModal('expired', payload.auth || {});
                }
                return payload;
            }
            if (payload) {
                handleUnauthenticatedPayload(payload);
            } else if (fromUserAction) {
                showModal('network');
            }
            return payload;
        }).catch(function () {
            if (fromUserAction) showModal('network');
            return null;
        }).then(function (payload) {
            statusRequest = null;
            return payload;
        }, function (error) {
            statusRequest = null;
            throw error;
        });
        return statusRequest;
    }

    function renewSession(fromUserAction) {
        if (!originalFetch || !config.statusUrl || renewalRequest) return renewalRequest || Promise.resolve(null);
        if (fromUserAction) setCheckBusy(true);

        renewalRequest = originalFetch(config.statusUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': config.csrfToken || ''
            },
            body: JSON.stringify({ csrf_token: config.csrfToken || '', action: 'renew' })
        }).then(parseJsonResponse).then(function (result) {
            var payload = result.payload;
            if (result.response.ok && payload && payload.authenticated) {
                handleAuthenticatedPayload(payload, fromUserAction);
                return payload;
            }
            if (payload) handleUnauthenticatedPayload(payload);
            else if (fromUserAction) showModal('network');
            return payload;
        }).catch(function () {
            if (fromUserAction) showModal('network');
            return null;
        }).then(function (payload) {
            renewalRequest = null;
            if (modal && !modal.hidden) setCheckBusy(false);
            return payload;
        }, function (error) {
            renewalRequest = null;
            if (modal && !modal.hidden) setCheckBusy(false);
            throw error;
        });
        return renewalRequest;
    }

    function noteUserActivity(event) {
        if (event && event.isTrusted === false) return;
        lastInteractionAt = Date.now();
        if (document.hidden || (modal && !modal.hidden)) return;
        if ((Date.now() - lastHeartbeatAt) < heartbeatThrottleMs || heartbeatTimer) return;
        heartbeatTimer = window.setTimeout(function () {
            heartbeatTimer = null;
            if (!document.hidden && (!modal || modal.hidden) && lastInteractionAt > lastHeartbeatAt) {
                renewSession(false);
            }
        }, 750);
    }

    function bindActivityTracking() {
        ['pointerdown', 'pointermove', 'keydown', 'input', 'touchstart', 'scroll'].forEach(function (eventName) {
            document.addEventListener(eventName, noteUserActivity, { passive: true, capture: true });
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshStatus(false);
        });
    }

    function requestUrl(input) {
        if (typeof input === 'string') return input;
        if (input && typeof input.url === 'string') return input.url;
        return '';
    }

    function isSameOrigin(input) {
        var raw = requestUrl(input);
        if (!raw) return false;
        try {
            return new URL(raw, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function handleAuthPayload(auth) {
        if (!auth || handledAuthFailure) return;
        var state = String(auth.state || '');
        if (['expired', 'unauthorized', 'csrf_invalid'].indexOf(state) === -1) return;
        handledAuthFailure = true;
        showModal(state === 'csrf_invalid' ? 'csrf_invalid' : 'expired', auth);
    }

    if (originalFetch) {
        window.fetch = function () {
            var input = arguments[0];
            return originalFetch.apply(null, arguments).then(function (response) {
                if (!isSameOrigin(input) || [401, 403, 419].indexOf(response.status) === -1) return response;
                response.clone().json().then(function (payload) {
                    if (payload && payload.auth) {
                        handleAuthPayload(payload.auth);
                    } else if (response.status === 401) {
                        refreshStatus(false);
                    }
                }).catch(function () {
                    if (response.status === 401) refreshStatus(false);
                });
                return response;
            });
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            restoreDrafts();
            bindActivityTracking();
        }, { once: true });
    } else {
        restoreDrafts();
        bindActivityTracking();
    }

    scheduleWarning(Number(config.secondsRemaining || 0));

    window.CrmSessionUx = Object.assign(config, {
        saveDrafts: saveDrafts,
        clearDrafts: clearDrafts,
        restoreDrafts: restoreDrafts,
        refreshStatus: refreshStatus,
        renewSession: renewSession,
        showWarning: function (detail) {
            detail = detail || {};
            showModal('warning', detail, Number(detail.seconds_remaining || config.warnBeforeSeconds || 300));
        },
        showExpired: function (auth) { showModal('expired', auth || {}); }
    });
})();
