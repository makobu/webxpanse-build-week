(function () {
    'use strict';

    var config = window.GuidedFounderDemo || null;
    if (!config || !config.state || !config.state.step) {
        return;
    }

    var state = config.state;
    var step = state.step;
    var csrfToken = config.csrfToken || '';
    var target = null;
    var usingFallback = false;
    var busy = false;
    var layer = null;
    var selectedChoiceKey = '';
    var selectedChoiceActionKey = '';
    var runningActionKey = '';
    var runningChoiceKey = '';
    var runningWorkingLabel = '';
    var ACTION_WORKING_MIN_MS = 900;

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char] || char;
        });
    }

    function post(url, data) {
        var form = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            form.append(key, data[key]);
        });
        form.append('csrf_token', csrfToken);
        return fetch(url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, error: response.ok ? 'Empty response.' : 'Request failed.' };
            }).then(function (payload) {
                if (!response.ok || !payload || payload.success === false) {
                    throw new Error((payload && payload.error) || 'Request failed.');
                }
                return payload;
            });
        });
    }

    function findTarget() {
        var primary = step.target ? document.querySelector(step.target) : null;
        if (primary) {
            usingFallback = false;
            return primary;
        }
        usingFallback = true;
        return document.querySelector(step.fallback_target || 'main') || document.body;
    }

    function safeRect(element) {
        var rect = element.getBoundingClientRect();
        var padding = 8;
        return {
            top: Math.max(8, rect.top - padding),
            left: Math.max(8, rect.left - padding),
            width: Math.min(window.innerWidth - 16, Math.max(48, rect.width + (padding * 2))),
            height: Math.min(window.innerHeight - 16, Math.max(48, rect.height + (padding * 2)))
        };
    }

    function rectWithEdges(rect) {
        return {
            top: rect.top,
            left: rect.left,
            width: rect.width,
            height: rect.height,
            right: rect.left + rect.width,
            bottom: rect.top + rect.height
        };
    }

    function inflateRect(rect, amount) {
        return {
            top: rect.top - amount,
            left: rect.left - amount,
            width: rect.width + (amount * 2),
            height: rect.height + (amount * 2),
            right: rect.right + amount,
            bottom: rect.bottom + amount
        };
    }

    function intersectionArea(a, b) {
        var width = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left));
        var height = Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
        return width * height;
    }

    function viewportSize() {
        return {
            width: window.innerWidth || document.documentElement.clientWidth || 1024,
            height: window.innerHeight || document.documentElement.clientHeight || 768
        };
    }

    function clamp(value, min, max) {
        if (max < min) {
            return min;
        }
        return Math.min(Math.max(value, min), max);
    }

    function visualViewportSize() {
        var viewport = viewportSize();
        if (window.visualViewport) {
            viewport.width = window.visualViewport.width || viewport.width;
            viewport.height = window.visualViewport.height || viewport.height;
        }
        return viewport;
    }

    function setMobileScrollSpace(enabled) {
        var spacer = document.querySelector('[data-guided-demo-mobile-spacer]');
        if (!enabled) {
            if (spacer) {
                spacer.remove();
            }
            return;
        }
        if (!spacer) {
            spacer = document.createElement('div');
            spacer.setAttribute('data-guided-demo-mobile-spacer', '1');
            spacer.setAttribute('aria-hidden', 'true');
            document.body.appendChild(spacer);
        }
        spacer.style.height = Math.ceil(Math.min(window.innerHeight * 0.48, 430) + 128) + 'px';
        spacer.style.pointerEvents = 'none';
    }

    function bubbleScrollContainer(bubble) {
        if (!bubble) {
            return null;
        }
        return bubble.querySelector('[data-guided-demo-scroll]') || bubble;
    }

    function revealBubbleContent(element) {
        var bubble = layer ? layer.querySelector('.guided-demo-bubble') : null;
        var scrollContainer = bubbleScrollContainer(bubble);
        if (!bubble || !scrollContainer || !element || !scrollContainer.contains(element)) {
            return;
        }
        var bubbleRect = scrollContainer.getBoundingClientRect();
        var elementRect = element.getBoundingClientRect();
        var padding = 16;
        if (elementRect.top < bubbleRect.top + padding) {
            scrollContainer.scrollTop += elementRect.top - bubbleRect.top - padding;
        } else if (elementRect.bottom > bubbleRect.bottom - padding) {
            scrollContainer.scrollTop += elementRect.bottom - bubbleRect.bottom + padding;
        }
    }

    function clampBubbleToViewport(preferredElement) {
        var bubble = layer ? layer.querySelector('.guided-demo-bubble') : null;
        if (!bubble) {
            return;
        }
        var viewport = visualViewportSize();
        var margin = window.matchMedia('(max-width: 720px)').matches ? 0 : 16;
        var maxHeight = Math.max(180, viewport.height - (margin * 2));
        if (!window.matchMedia('(max-width: 720px)').matches) {
            var rect = bubble.getBoundingClientRect();
            var top = parseFloat(bubble.style.top || String(rect.top));
            if (isNaN(top)) {
                top = rect.top;
            }
            top = clamp(top, margin, Math.max(margin, viewport.height - margin - Math.min(rect.height, maxHeight)));
            bubble.style.top = top + 'px';
            bubble.style.maxHeight = Math.max(180, viewport.height - top - margin) + 'px';
        }
        revealBubbleContent(preferredElement);
    }

    function syncBubbleVisibility(preferredElement) {
        if (!layer) {
            return;
        }
        place();
        clampBubbleToViewport(preferredElement || null);
    }

    function setError(message) {
        var error = layer ? layer.querySelector('[data-guided-demo-error]') : null;
        if (!error) {
            return;
        }
        error.textContent = message || '';
        error.classList.toggle('is-visible', !!message);
        syncBubbleVisibility(message ? error : null);
    }

    function setResult(message, result, stateName) {
        var panel = layer ? layer.querySelector('[data-guided-demo-result]') : null;
        if (!panel) {
            return;
        }
        result = result || {};
        var title = result.result_title || message || '';
        var body = result.result_body || '';
        var previewText = result.reply_preview || (result.draft_preview && !result.draft_body ? result.draft_preview : '');
        var html = [
            title ? '<strong>' + escapeHtml(title) + '</strong>' : '',
            body ? '<div>' + escapeHtml(body) + '</div>' : '',
            previewText ? '<pre>' + escapeHtml(previewText) + '</pre>' : ''
        ].join('');
        panel.innerHTML = html;
        panel.classList.toggle('is-visible', html !== '');
        panel.classList.toggle('is-working', stateName === 'working');
        syncBubbleVisibility(html !== '' ? panel : null);
    }

    function updateFieldValue(field, value) {
        if (!field || typeof value !== 'string' || value === '') {
            return;
        }
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function hydrateComposerDraft(result) {
        if (!result || (!result.draft_subject && !result.draft_body)) {
            return;
        }
        updateFieldValue(document.getElementById('subject'), result.draft_subject || '');
        updateFieldValue(document.getElementById('body'), result.draft_body || '');
        if (typeof window.updateEmailPreview === 'function') {
            window.updateEmailPreview();
        }
    }

    function hydratePreparedDocument(result) {
        if (!result || !result.document_title) {
            return;
        }
        var panel = document.querySelector('[data-guided-demo-prepared-document]');
        if (!panel) {
            return;
        }
        var title = panel.querySelector('[data-guided-demo-document-title]');
        var label = panel.querySelector('[data-guided-demo-document-label]');
        if (title) {
            title.textContent = result.document_title || 'Demo document';
        }
        if (label) {
            label.textContent = result.document_label || result.document_type || 'draft';
        }
        panel.hidden = false;
    }

    function refreshContactsListAfterAction(actionKey) {
        if (actionKey !== 'add_demo_contact' || typeof window.refreshContactsListAsync !== 'function') {
            return;
        }
        window.refreshContactsListAsync().catch(function () {});
    }

    function refreshDashboardTasksForCreatedRecords(createdRecords) {
        var taskIds = createdRecords && Array.isArray(createdRecords.tasks) ? createdRecords.tasks : [];
        if (taskIds.length === 0 || typeof window.refreshDashboardTasksPanel !== 'function') {
            return;
        }
        window.refreshDashboardTasksPanel();
    }

    function refreshInboxListAfterAction(actionKey, createdRecords) {
        var communicationIds = createdRecords && Array.isArray(createdRecords.communications) ? createdRecords.communications : [];
        if (actionKey !== 'simulate_reply_received' || communicationIds.length === 0 || typeof window.refreshInboxListAsync !== 'function') {
            return;
        }
        try {
            Promise.resolve(window.refreshInboxListAsync({
                page: 1,
                background: true,
                updateHistory: false,
                showLoading: false
            })).then(function () {
                target = findTarget();
                scrollTargetForBubble();
                place();
            }).catch(function () {});
        } catch (error) {}
    }

    function setBusy(value) {
        busy = value;
        if (!layer) {
            return;
        }
        layer.querySelectorAll('[data-guided-demo-action]').forEach(function (button) {
            button.disabled = value || button.getAttribute('data-guided-demo-completed') === '1';
        });
        layer.querySelectorAll('[data-guided-demo-choice]').forEach(function (button) {
            button.disabled = value || button.getAttribute('data-guided-demo-completed') === '1';
        });
        refreshStepActionButton();
        refreshNextButton();
    }

    function actionIsBlocking() {
        var action = step.demo_action || null;
        return !!(action && action.required && !action.completed);
    }

    function refreshNextButton() {
        if (!layer) {
            return;
        }
        var next = layer.querySelector('[data-guided-demo-action="next"]');
        if (!next) {
            return;
        }
        next.disabled = busy || actionIsBlocking();
        next.title = actionIsBlocking() ? 'Choose one option first.' : '';
    }

    function actionChoices(action) {
        return Array.isArray(action && action.choices) ? action.choices.filter(function (choice) {
            return choice && choice.key && choice.label;
        }) : [];
    }

    function actionSelectedChoice(action) {
        return (action && action.selected_choice_key) || selectedChoiceKey || '';
    }

    function actionWorkingLabel(action) {
        return runningWorkingLabel || (action && action.working_label) || 'Setting this up...';
    }

    function actionIsRunning(action) {
        return !!(action && runningActionKey && runningActionKey === action.key && busy);
    }

    function actionNeedsChoice(action) {
        return !!(action && !action.completed && actionChoices(action).length > 0 && !actionSelectedChoice(action));
    }

    function refreshChoiceButtons() {
        if (!layer) {
            return;
        }
        var action = step.demo_action || null;
        var selected = actionSelectedChoice(action);
        var running = actionIsRunning(action);
        layer.querySelectorAll('[data-guided-demo-choice]').forEach(function (button) {
            var isSelected = button.getAttribute('data-guided-demo-choice') === selected;
            var isRunning = running && button.getAttribute('data-guided-demo-choice') === runningChoiceKey;
            button.classList.toggle('is-selected', isSelected);
            button.classList.toggle('is-working', isRunning);
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            button.disabled = busy || !!(action && action.completed);
        });
    }

    function refreshStepActionButton() {
        if (!layer) {
            return;
        }
        var action = step.demo_action || null;
        var button = layer.querySelector('[data-guided-demo-action="run-step-action"]');
        if (!action) {
            return;
        }
        if (button) {
            button.disabled = busy || action.completed || actionNeedsChoice(action);
            button.title = actionNeedsChoice(action) ? 'Choose one option first.' : '';
        }
        refreshChoiceButtons();
        refreshActionRunningUi();
    }

    function refreshActionRunningUi() {
        if (!layer) {
            return;
        }
        var action = step.demo_action || null;
        var panel = layer.querySelector('[data-guided-demo-action-panel]');
        if (!action || !panel) {
            return;
        }
        var isRunning = actionIsRunning(action);
        var working = panel.querySelector('[data-guided-demo-working]');
        var label = panel.querySelector('[data-guided-demo-working-label]');
        var button = panel.querySelector('[data-guided-demo-action="run-step-action"]');
        var buttonLabel = action.completed ? (action.completed_label || 'Completed') : (action.label || 'Run demo action');
        panel.classList.toggle('is-running', isRunning);
        panel.setAttribute('aria-busy', isRunning ? 'true' : 'false');
        if (working) {
            working.classList.toggle('is-visible', isRunning);
        }
        if (label) {
            label.textContent = actionWorkingLabel(action);
        }
        if (button) {
            button.classList.toggle('is-loading', isRunning);
            button.textContent = isRunning ? actionWorkingLabel(action) : buttonLabel;
        }
        if (isRunning && working) {
            syncBubbleVisibility(working);
        }
    }

    function selectChoice(choiceKey) {
        var action = step.demo_action || null;
        if (!action || action.completed || busy) {
            return;
        }
        selectedChoiceActionKey = action.key || '';
        selectedChoiceKey = choiceKey || '';
        setError('');
        refreshStepActionButton();
    }

    function choiceHtml(action) {
        var choices = actionChoices(action);
        if (choices.length === 0) {
            return '';
        }
        var selected = actionSelectedChoice(action);
        var running = actionIsRunning(action);
        return '<div class="guided-demo-choices" role="group" aria-label="' + escapeHtml(action.prompt || 'Choose one option') + '">' +
            choices.map(function (choice) {
                var isSelected = selected === choice.key;
                var isRunning = running && runningChoiceKey === choice.key;
                var selectedClass = (isSelected ? ' is-selected' : '') + (isRunning ? ' is-working' : '');
                var completed = action.completed ? ' data-guided-demo-completed="1"' : '';
                return '<button type="button" class="guided-demo-choice' + selectedClass + '" data-guided-demo-choice="' + escapeHtml(choice.key) + '"' + completed + ' aria-pressed="' + (isSelected ? 'true' : 'false') + '">' + escapeHtml(choice.label) + '</button>';
            }).join('') +
        '</div>';
    }

    function actionHtml() {
        var action = step.demo_action || null;
        if (!action) {
            selectedChoiceKey = '';
            selectedChoiceActionKey = '';
            return '';
        }
        if (selectedChoiceActionKey !== action.key) {
            selectedChoiceKey = action.selected_choice_key || '';
            selectedChoiceActionKey = action.key || '';
        } else if (action.selected_choice_key) {
            selectedChoiceKey = action.selected_choice_key;
        }
        var completed = action.completed ? ' data-guided-demo-completed="1"' : '';
        var label = action.completed ? (action.completed_label || 'Completed') : (action.label || 'Run demo action');
        var chip = action.completed ? '<span class="guided-demo-required is-complete">Done</span>' : '<span class="guided-demo-required">Your move</span>';
        var autoRun = !!action.auto_run_on_choice;
        var note = action.completed ? 'Ready to continue.' : (autoRun ? 'Pick one. I\'ll add it now.' : 'Pick one. I\'ll set it up.');
        var buttonDisabled = actionNeedsChoice(action) ? ' disabled' : '';
        var isRunning = actionIsRunning(action);
        var runningClass = isRunning ? ' is-running' : '';
        var buttonClass = isRunning ? ' is-loading' : '';
        var actionButton = autoRun ? '' : '<button type="button" class="guided-demo-button is-simulated' + buttonClass + '" data-guided-demo-action="run-step-action"' + completed + buttonDisabled + '>' + escapeHtml(isRunning ? actionWorkingLabel(action) : label) + '</button>';
        return '<div class="guided-demo-action-panel' + runningClass + '" data-guided-demo-action-panel aria-busy="' + (isRunning ? 'true' : 'false') + '">' +
            '<div class="guided-demo-action-copy">' + chip + '<span>' + escapeHtml(note) + '</span></div>' +
            (action.prompt ? '<p class="guided-demo-action-prompt">' + escapeHtml(action.prompt) + '</p>' : '') +
            choiceHtml(action) +
            '<div class="guided-demo-working' + (isRunning ? ' is-visible' : '') + '" data-guided-demo-working role="status" aria-live="polite">' +
                '<span class="guided-demo-spinner" aria-hidden="true"></span>' +
                '<span data-guided-demo-working-label>' + escapeHtml(actionWorkingLabel(action)) + '</span>' +
            '</div>' +
            actionButton +
        '</div>';
    }

    function phaseProgressLabel() {
        var phase = step.phase_label || step.phase || 'Demo';
        var progress = step.progress || {};
        var index = parseInt(progress.index || 0, 10);
        var total = parseInt(progress.total || 0, 10);
        if (index > 0 && total > 0) {
            return phase + ' / ' + index + ' / ' + total;
        }
        return phase;
    }

    function lessonHtml() {
        if (!step.why) {
            return '';
        }
        return '<p class="guided-demo-lesson"><span>Why it matters</span>' + escapeHtml(step.why) + '</p>';
    }

    function buildLayer() {
        if (layer) {
            layer.remove();
        }
        layer = document.createElement('div');
        layer.className = 'guided-demo-layer';
        layer.innerHTML = [
            '<div class="guided-demo-highlight" aria-hidden="true"></div>',
            '<section class="guided-demo-bubble" role="dialog" aria-modal="false" aria-live="polite" aria-labelledby="guided-demo-title" aria-describedby="guided-demo-body">',
                '<div class="guided-demo-scroll" data-guided-demo-scroll tabindex="0" aria-label="Demo instructions">',
                    '<div class="guided-demo-progress"><span class="guided-demo-phase">' + escapeHtml(phaseProgressLabel()) + '</span><span>Demo</span></div>',
                    '<h2 id="guided-demo-title">' + escapeHtml(step.title || 'Guided demo') + '</h2>',
                    '<p id="guided-demo-body" data-guided-demo-body>' + escapeHtml(step.body || '') + '</p>',
                    actionHtml(),
                    '<div class="guided-demo-result" data-guided-demo-result></div>',
                    '<div class="guided-demo-error" data-guided-demo-error></div>',
                    lessonHtml(),
                '</div>',
                '<div class="guided-demo-actions">',
                    '<div class="guided-demo-actions-left">',
                        '<button type="button" class="guided-demo-button is-quiet is-danger" data-guided-demo-action="exit">Exit</button>',
                        '<button type="button" class="guided-demo-button is-quiet" data-guided-demo-action="back">Back</button>',
                    '</div>',
                    '<div class="guided-demo-actions-right">',
                        '<button type="button" class="guided-demo-button is-primary" data-guided-demo-action="next">' + escapeHtml(step.action_label || 'Continue') + '</button>',
                    '</div>',
                '</div>',
            '</section>'
        ].join('');
        document.body.appendChild(layer);
        layer.querySelector('[data-guided-demo-action="back"]').style.visibility = step.progress.index <= 1 ? 'hidden' : 'visible';
        refreshStepActionButton();
        refreshNextButton();
        return layer;
    }

    function uniqueNumbers(values) {
        var seen = {};
        return values.filter(function (value) {
            var key = String(Math.round(value));
            if (seen[key]) {
                return false;
            }
            seen[key] = true;
            return true;
        });
    }

    function scorePlacement(candidate, protectedRect, targetRect, preferredSide, naturalHeight) {
        var bubbleRect = {
            top: candidate.top,
            left: candidate.left,
            width: candidate.width,
            height: candidate.height,
            right: candidate.left + candidate.width,
            bottom: candidate.top + candidate.height
        };
        var overlap = intersectionArea(bubbleRect, protectedRect);
        var targetCenterY = targetRect.top + (targetRect.height / 2);
        var bubbleCenterY = bubbleRect.top + (bubbleRect.height / 2);
        var sidePenalty = candidate.side === preferredSide ? 0 : 1200;
        var distancePenalty = Math.abs(targetCenterY - bubbleCenterY);
        var heightPenalty = Math.max(0, naturalHeight - candidate.height) * 4;

        return (overlap * 1000) + sidePenalty + distancePenalty + heightPenalty;
    }

    function placeDesktopBubble(bubble, targetRect) {
        var viewport = viewportSize();
        var margin = 24;
        var clearance = 28;
        var bubbleWidth = Math.min(432, viewport.width - (margin * 2));
        var usableHeight = Math.max(180, viewport.height - (margin * 2));
        var minimumHeight = Math.min(320, usableHeight);
        bubble.style.width = bubbleWidth + 'px';
        bubble.style.maxHeight = '';
        var naturalHeight = Math.min(bubble.offsetHeight || 320, usableHeight);
        minimumHeight = Math.min(Math.max(minimumHeight, Math.min(naturalHeight, 320)), usableHeight);
        var maxTop = Math.max(margin, viewport.height - naturalHeight - margin);
        var leftRail = margin;
        var rightRail = Math.max(margin, viewport.width - bubbleWidth - margin);
        var targetCenterX = targetRect.left + (targetRect.width / 2);
        var preferredSide = targetCenterX > (viewport.width / 2) ? 'left' : 'right';
        var sides = preferredSide === 'left' ? ['left', 'right'] : ['right', 'left'];
        var protectedRect = inflateRect(targetRect, clearance);
        var candidates = [];

        function addCandidate(side, topValue, heightValue) {
            var height = clamp(heightValue, minimumHeight, usableHeight);
            var top = clamp(topValue, margin, Math.max(margin, viewport.height - height - margin));
            candidates.push({
                side: side,
                left: side === 'left' ? leftRail : rightRail,
                top: top,
                width: bubbleWidth,
                height: height
            });
        }

        sides.forEach(function (side) {
            var naturalTop = clamp(targetRect.top, margin, maxTop);
            var bottomTop = clamp(viewport.height - naturalHeight - margin, margin, maxTop);
            var openBelow = viewport.height - targetRect.bottom - clearance - margin;
            var openAbove = targetRect.top - clearance - margin;
            var topCandidates = uniqueNumbers([naturalTop, margin, bottomTop]);

            if (openBelow >= minimumHeight) {
                addCandidate(side, targetRect.bottom + clearance, Math.min(naturalHeight, openBelow));
            }
            if (openAbove >= minimumHeight) {
                addCandidate(side, margin, Math.min(naturalHeight, openAbove));
            }
            topCandidates.forEach(function (topValue) {
                addCandidate(side, topValue, naturalHeight);
            });
        });

        candidates.sort(function (a, b) {
            return scorePlacement(a, protectedRect, targetRect, preferredSide, naturalHeight)
                - scorePlacement(b, protectedRect, targetRect, preferredSide, naturalHeight);
        });

        var best = candidates[0] || {
            side: preferredSide,
            left: preferredSide === 'left' ? leftRail : rightRail,
            top: margin,
            width: bubbleWidth,
            height: naturalHeight
        };

        bubble.style.left = best.left + 'px';
        bubble.style.top = best.top + 'px';
        bubble.style.width = best.width + 'px';
        bubble.style.maxHeight = best.height + 'px';
        bubble.style.right = 'auto';
        bubble.style.bottom = 'auto';
        bubble.setAttribute('data-placement', 'rail-' + best.side);
    }

    function place() {
        if (!target || !layer) {
            return;
        }
        var rect = rectWithEdges(safeRect(target));
        var highlight = layer.querySelector('.guided-demo-highlight');
        var bubble = layer.querySelector('.guided-demo-bubble');
        highlight.style.top = rect.top + 'px';
        highlight.style.left = rect.left + 'px';
        highlight.style.width = rect.width + 'px';
        highlight.style.height = rect.height + 'px';

        if (window.matchMedia('(max-width: 720px)').matches) {
            setMobileScrollSpace(true);
            bubble.removeAttribute('data-placement');
            bubble.style.width = '';
            bubble.style.left = '';
            bubble.style.top = '';
            bubble.style.right = '';
            bubble.style.bottom = '';
            bubble.style.maxHeight = '';
            return;
        }

        setMobileScrollSpace(false);
        placeDesktopBubble(bubble, rect);
    }

    function scrollTargetForBubble() {
        if (!target) {
            return;
        }
        try {
            var rect = target.getBoundingClientRect();
            var currentTop = window.pageYOffset || document.documentElement.scrollTop || 0;
            var isMobile = window.matchMedia('(max-width: 720px)').matches;
            var desiredTop = isMobile ? 16 : 96;
            if (isMobile) {
                setMobileScrollSpace(true);
            }
            window.scrollTo(0, Math.max(0, currentTop + rect.top - desiredTop));
        } catch (error) {
            target.scrollIntoView();
        }

        if (window.matchMedia('(max-width: 720px)').matches) {
            return;
        }
    }

    function redirectFromPayload(payload) {
        var data = payload && payload.data ? payload.data : {};
        var url = data.redirect_url || (data.state && data.state.redirect_url) || (data.step && data.step.route) || '';
        if (url) {
            window.location.href = url;
        } else {
            window.location.reload();
        }
    }

    function withQueryParam(url, key, value) {
        var destination = url || window.location.href;
        try {
            var parsed = new URL(destination, window.location.href);
            parsed.searchParams.set(key, value);
            return parsed.href;
        } catch (error) {
            var separator = destination.indexOf('?') === -1 ? '?' : '&';
            return destination + separator + encodeURIComponent(key) + '=' + encodeURIComponent(value);
        }
    }

    function redirectAfterPreparedDocument(data) {
        var url = (data && data.state && data.state.redirect_url)
            || (step && step.route)
            || window.location.href;
        window.setTimeout(function () {
            window.location.href = withQueryParam(url, 'guided_demo_result', 'prepared_document');
        }, 700);
    }

    function waitForVisibleActionTime(startedAt) {
        var remaining = Math.max(0, ACTION_WORKING_MIN_MS - (Date.now() - startedAt));
        return new Promise(function (resolve) {
            window.setTimeout(resolve, remaining);
        });
    }

    function advance(direction) {
        if (busy) {
            return;
        }
        if (direction !== 'back' && actionIsBlocking()) {
            setError('Choose one option and let me set it up first.');
            return;
        }
        setBusy(true);
        setError('');
        post(config.advanceUrl, { direction: direction || 'next' })
            .then(redirectFromPayload)
            .catch(function (error) {
                setError(error && error.message ? error.message : 'Could not move the demo forward.');
            })
            .finally(function () {
                setBusy(false);
            });
    }

    function finish(reason) {
        if (busy) {
            return;
        }
        setBusy(true);
        setError('');
        post(config.endUrl, { reason: reason || 'completed' })
            .then(redirectFromPayload)
            .catch(function (error) {
                var message = error && error.message ? error.message : '';
                setError(message.toLowerCase().indexOf('security token') !== -1
                    ? message
                    : 'Could not open your dashboard yet. Try again.');
            })
            .finally(function () {
                setBusy(false);
            });
    }

    function runStepAction() {
        var action = step.demo_action || null;
        if (!action || action.completed || busy) {
            return;
        }
        if (actionNeedsChoice(action)) {
            setError('Choose one option first.');
            return;
        }
        var choiceKey = actionSelectedChoice(action);
        var startedAt = Date.now();
        runningActionKey = action.key || '';
        runningChoiceKey = choiceKey || '';
        runningWorkingLabel = actionWorkingLabel(action);
        setBusy(true);
        setError('');
        refreshActionRunningUi();
        post(config.actionUrl, { step_key: step.key, action_key: action.key, choice_key: choiceKey })
            .then(function (payload) {
                return waitForVisibleActionTime(startedAt).then(function () {
                    return payload;
                });
            })
            .then(function (payload) {
                var data = payload && payload.data ? payload.data : {};
                if (data.state) {
                    state = data.state;
                    step = state.step;
                    selectedChoiceKey = (step.demo_action && step.demo_action.selected_choice_key) || choiceKey || '';
                    selectedChoiceActionKey = (step.demo_action && step.demo_action.key) || '';
                    target = findTarget();
                    buildLayer();
                    place();
                }
                hydrateComposerDraft(data.result || {});
                hydratePreparedDocument(data.result || {});
                setResult(data.message || 'Demo action completed.', data.result || {});
                refreshContactsListAfterAction(action.key || '');
                refreshInboxListAfterAction(action.key || '', data.created_records || {});
                refreshDashboardTasksForCreatedRecords(data.created_records || {});
                if (action.key === 'prepare_demo_quote' && data.result && data.result.invoice_id) {
                    redirectAfterPreparedDocument(data);
                }
            })
            .catch(function (error) {
                return waitForVisibleActionTime(startedAt).then(function () {
                    setError(error && error.message ? error.message : 'Demo action could not run.');
                });
            })
            .finally(function () {
                runningActionKey = '';
                runningChoiceKey = '';
                runningWorkingLabel = '';
                setBusy(false);
                refreshActionRunningUi();
            });
    }

    function render() {
        target = findTarget();
        scrollTargetForBubble();

        window.setTimeout(function () {
            if (window.matchMedia('(max-width: 720px)').matches) {
                scrollTargetForBubble();
            }
            buildLayer();
            place();
            var focusTarget = layer.querySelector('[data-guided-demo-action="run-step-action"]:not([disabled])')
                || layer.querySelector('[data-guided-demo-action="next"]:not([disabled])')
                || layer.querySelector('[data-guided-demo-action="exit"]');
            if (focusTarget) {
                focusTarget.focus({ preventScroll: true });
            }

            post(config.eventUrl, {
                event_type: usingFallback ? 'fallback_target' : 'shown',
                target: usingFallback ? (step.fallback_target || 'main') : (step.target || '')
            }).catch(function () {});
        }, 250);
    }

    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    document.addEventListener('click', function (event) {
        if (!layer) {
            return;
        }
        var choice = event.target.closest('[data-guided-demo-choice]');
        if (choice && layer.contains(choice)) {
            selectChoice(choice.getAttribute('data-guided-demo-choice') || '');
            if (step.demo_action && step.demo_action.auto_run_on_choice) {
                runStepAction();
            }
            return;
        }
        var button = event.target.closest('[data-guided-demo-action]');
        if (!button || !layer.contains(button)) {
            return;
        }
        var action = button.getAttribute('data-guided-demo-action');
        if (action === 'back') {
            advance('back');
        } else if (action === 'exit') {
            finish('exited');
        } else if (action === 'run-step-action') {
            runStepAction();
        } else if (step.progress && step.progress.is_final) {
            finish('completed');
        } else {
            advance('next');
        }
    });

    document.addEventListener('keydown', function (event) {
        var active = document.activeElement;
        var isTyping = active && ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(active.tagName) !== -1;
        if (event.key === 'Escape') {
            event.preventDefault();
            finish('exited');
        } else if (!isTyping && (event.key === 'Enter' || event.key === ' ')) {
            if (active && active.closest && active.closest('.guided-demo-bubble')) {
                return;
            }
            event.preventDefault();
            if (step.progress && step.progress.is_final) {
                finish('completed');
            } else {
                advance('next');
            }
        }
    });

    render();
}());
