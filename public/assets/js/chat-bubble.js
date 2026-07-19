(function () {
    'use strict';

    var container = document.getElementById('chat-bubble-container');
    if (!container) return;

    var bubbleBtn = document.getElementById('chat-bubble-btn');
    var panel = document.getElementById('chat-bubble-panel');
    var closeBtn = document.getElementById('chat-bubble-close');
    var messagesEl = document.getElementById('chat-bubble-messages');
    var inputEl = document.getElementById('chat-bubble-input');
    var sendBtn = document.getElementById('chat-bubble-send');
    var welcomeLoadingEl = null;
    var welcomeRequestInFlight = false;
    var openingRequestSeq = 0;
    var pendingMessageIds = {};
    var lastFocusedElement = null;
    var organizationHistoryLoaded = false;
    var organizationHistoryLoading = false;
    var clarityConversationId = 0;
    var leanCanvasState = {
        enabled: false,
        completeness: 0,
        missingBlocks: []
    };

    var STORAGE_KEYS = {
        queue: 'chatBubblePendingQueue',
        unreadCount: 'chatBubbleUnreadCount'
    };

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getCurrentPage() {
        return container.getAttribute('data-current-page') || '';
    }

    function getApiBase() {
        return (container.getAttribute('data-api-base') || '/crm').replace(/\/$/, '');
    }

    function getStrategyProfileUrl() {
        return getApiBase() + '/api/ai-coach/strategy-profile.php';
    }

    function withCurrentPage(url) {
        var separator = url.indexOf('?') >= 0 ? '&' : '?';
        return url + separator + 'current_page=' + encodeURIComponent(getCurrentPage());
    }

    function isDashboardPage() {
        return getCurrentPage() === 'dashboard.php';
    }

    function getOrganizationIntelligenceContext() {
        var context = window.organizationIntelligenceChatContext;
        if (!context || typeof context !== 'object' || Array.isArray(context)) {
            return null;
        }
        if (context.page_key !== 'organization_intelligence') {
            return null;
        }
        return context;
    }

    function isOrganizationIntelligencePage() {
        return getCurrentPage() === 'hr_analytics.php' && !!getOrganizationIntelligenceContext();
    }

    function applyOrganizationIntelligenceChatMode() {
        if (!isOrganizationIntelligencePage()) return;
        container.setAttribute('data-chat-mode', 'executive-suite');
        var executiveHeader = document.querySelector('.hr-instrument-panel');
        if (executiveHeader && bubbleBtn && bubbleBtn.parentNode !== executiveHeader) {
            executiveHeader.appendChild(bubbleBtn);
            bubbleBtn.classList.add('chat-bubble-btn--in-header');
        }
        if (inputEl) {
            inputEl.setAttribute('placeholder', 'Ask Clarity for an executive readout...');
        }

        var headerTitle = panel ? panel.querySelector('.chat-bubble-header h3') : null;
        if (!headerTitle || headerTitle.querySelector('.chat-bubble-header-copy')) return;
        var logo = headerTitle.querySelector('img');
        var logoHtml = logo ? logo.outerHTML : '';
        headerTitle.innerHTML = logoHtml +
            '<span class="chat-bubble-header-copy">' +
                '<strong>Clarity</strong>' +
                '<small>Executive Suite</small>' +
            '</span>';
        var header = panel ? panel.querySelector('.chat-bubble-header') : null;
        if (header && !header.querySelector('[data-clear-organization-conversation]')) {
            var clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'chat-bubble-clear';
            clear.setAttribute('data-clear-organization-conversation', 'true');
            clear.textContent = 'Clear';
            clear.addEventListener('click', clearOrganizationIntelligenceConversation);
            header.insertBefore(clear, closeBtn || null);
        }
    }

    function clearOrganizationIntelligenceConversation() {
        var context = getOrganizationIntelligenceContext() || {};
        if (!context.conversation_id || !window.confirm('Clear this private Organization Intelligence conversation?')) return;
        fetch(getApiBase() + '/api/chat/organization_intelligence_conversation.php?conversation_id=' + encodeURIComponent(context.conversation_id), {
            method: 'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': getCsrfToken()
            }
        }).then(function (res) {
            if (!res.ok) throw new Error('Clear failed');
            context.conversation_id = 0;
            organizationHistoryLoaded = false;
            clearChatMessages();
            loadOrganizationIntelligenceHistory();
        }).catch(function () {
            addMessage('The conversation could not be cleared. Refresh and try again.', 'assistant');
        });
    }

    function clearChatMessages() {
        if (!messagesEl) return;
        while (messagesEl.firstChild) messagesEl.removeChild(messagesEl.firstChild);
    }

    function buildOrganizationIntelligenceGreeting() {
        var context = getOrganizationIntelligenceContext() || {};
        var room = String(context.room || 'brief').replace(/_/g, ' ');
        return 'Welcome to the executive suite. I am Clarity, ready to use the server-verified evidence for this ' + escapeHtml(room) + ' view.';
    }

    function firstCleanText(items, fallback) {
        if (!Array.isArray(items)) return fallback;
        for (var i = 0; i < items.length; i += 1) {
            var text = String(items[i] || '').trim();
            if (text) return text;
        }
        return fallback;
    }

    function buildOrganizationIntelligenceOpeningInsight() {
        return {
            kind: 'organization_intelligence_executive',
            title: 'Private Organization Intelligence conversation',
            body: 'This conversation continues across Organization Intelligence tabs and supported mobile sessions.',
            bullets: [
                'Scores and graph facts are rebuilt on the server.',
                'Room, sub-room and filters are retained with every answer.',
                'Missing evidence remains unscored.'
            ],
            source: 'server_owned_organization_intelligence'
        };
    }

    function loadOrganizationIntelligenceHistory(options) {
        options = options || {};
        if (!isOrganizationIntelligencePage()) return Promise.resolve([]);
        if ((!options.force && organizationHistoryLoaded) || organizationHistoryLoading) return Promise.resolve([]);
        organizationHistoryLoading = true;
        var context = getOrganizationIntelligenceContext() || {};
        var url = getApiBase() + '/api/chat/organization_intelligence_conversation.php';
        if (context.conversation_id) url += '?conversation_id=' + encodeURIComponent(context.conversation_id);
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                return res.text().then(function (body) {
                    var data;
                    try {
                        data = JSON.parse(body || '{}');
                    } catch (error) {
                        error.chatStage = 'history_invalid_json';
                        throw error;
                    }
                    if (!res.ok) {
                        var requestError = new Error(data.error || 'Conversation could not be loaded.');
                        requestError.chatStage = 'history_http';
                        throw requestError;
                    }
                    return data;
                });
            })
            .then(function (data) {
                organizationHistoryLoading = false;
                organizationHistoryLoaded = true;
                if (data.conversation_id) context.conversation_id = data.conversation_id;
                var messages = Array.isArray(data.messages) ? data.messages : [];
                if (options.replace) clearChatMessages();
                removeClarityOpeningMessages();
                if (!messages.length) {
                    if (!options.silent) {
                        addOpeningTypingMessage(buildOrganizationIntelligenceGreeting(), true);
                        addStructuredInsightMessage(buildOrganizationIntelligenceOpeningInsight(), true);
                    }
                    return messages;
                }
                messages.forEach(function (message) {
                    if (message.role === 'system') {
                        addMessage('<div class="chat-bubble-scope-divider">' + escapeHtml(message.text || 'Scope changed') + '</div>', 'assistant', true);
                    } else {
                        addMessage(String(message.text || ''), message.role === 'user' ? 'user' : 'assistant');
                    }
                });
                return messages;
            })
            .catch(function (error) {
                organizationHistoryLoading = false;
                organizationHistoryLoaded = true;
                if (!options.silent) addOpeningTypingMessage(buildOrganizationIntelligenceGreeting(), true);
                console.warn('Clarity history request failed.', { stage: error.chatStage || 'history_transport' });
                return [];
            });
    }

    function reconcileOrganizationIntelligenceHistory(attempt) {
        return loadOrganizationIntelligenceHistory({ force: true, replace: true, silent: true })
            .then(function (messages) {
                var latest = messages.length ? messages[messages.length - 1] : null;
                if (latest && latest.role === 'assistant') return true;
                if (attempt >= 1) return false;
                return new Promise(function (resolve) {
                    window.setTimeout(function () {
                        reconcileOrganizationIntelligenceHistory(attempt + 1).then(resolve);
                    }, 900);
                });
            });
    }

    function escapeAttribute(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function normalizeLeanCanvasMetadata(metadata) {
        metadata = metadata || {};
        leanCanvasState.enabled = !!(metadata.lean_canvas_enabled || metadata.lean_canvas_mode_enabled);
        leanCanvasState.completeness = parseInt(metadata.lean_canvas_completeness || '0', 10) || 0;
        leanCanvasState.missingBlocks = Array.isArray(metadata.lean_canvas_missing_blocks) ? metadata.lean_canvas_missing_blocks : [];
    }

    function humanizeBlockName(block) {
        var labels = {
            problem: 'Problem',
            customer_segments: 'Customer Segments',
            unique_value_proposition: 'Unique Value Proposition',
            solution: 'Solution',
            channels: 'Channels',
            revenue_streams: 'Revenue Streams',
            cost_structure: 'Cost Structure',
            key_metrics: 'Key Metrics',
            unfair_advantage: 'Unfair Advantage'
        };
        return labels[block] || String(block || '').replace(/_/g, ' ');
    }

    function canvasFieldNameForBlock(block) {
        return 'lean_' + String(block || '');
    }

    function shouldOpenOnLogin() {
        return container.getAttribute('data-open-on-login') === '1';
    }

    function readStorageJson(key, fallback) {
        try {
            var raw = sessionStorage.getItem(key);
            if (!raw) return fallback;
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function writeStorageJson(key, value) {
        try {
            sessionStorage.setItem(key, JSON.stringify(value));
        } catch (e) {}
    }

    function readPendingQueue() {
        var queue = readStorageJson(STORAGE_KEYS.queue, []);
        return Array.isArray(queue) ? queue : [];
    }

    function writePendingQueue(queue) {
        writeStorageJson(STORAGE_KEYS.queue, Array.isArray(queue) ? queue : []);
    }

    function getUnreadCount() {
        try {
            return Math.max(0, parseInt(sessionStorage.getItem(STORAGE_KEYS.unreadCount) || '0', 10) || 0);
        } catch (e) {
            return 0;
        }
    }

    function setUnreadCount(count) {
        var nextCount = Math.max(0, parseInt(count || '0', 10) || 0);
        try {
            if (nextCount > 0) {
                sessionStorage.setItem(STORAGE_KEYS.unreadCount, String(nextCount));
            } else {
                sessionStorage.removeItem(STORAGE_KEYS.unreadCount);
            }
        } catch (e) {}
        syncUnreadMarker();
    }

    function incrementUnreadCount() {
        setUnreadCount(getUnreadCount() + 1);
    }

    function clearUnreadCount() {
        setUnreadCount(0);
    }

    function ensureUnreadBadge() {
        if (!bubbleBtn || !isDashboardPage()) return null;
        var badge = bubbleBtn.querySelector('.chat-bubble-unread-badge');
        if (badge) return badge;
        badge = document.createElement('span');
        badge.className = 'chat-bubble-unread-badge';
        badge.setAttribute('aria-hidden', 'true');
        bubbleBtn.appendChild(badge);
        return badge;
    }

    function syncUnreadMarker() {
        if (!bubbleBtn) return;
        bubbleBtn.classList.remove('has-unread');
        var existingBadge = bubbleBtn.querySelector('.chat-bubble-unread-badge');
        if (existingBadge) {
            existingBadge.style.display = 'none';
            existingBadge.textContent = '';
        }
        if (!isDashboardPage()) return;
        var unreadCount = getUnreadCount();
        if (unreadCount <= 0) return;
        var badge = ensureUnreadBadge();
        if (!badge) return;
        badge.style.display = 'inline-flex';
        badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
        bubbleBtn.classList.add('has-unread');
    }

    function prepareLoginWelcome() {
        if (!shouldOpenOnLogin()) return;
        enqueuePendingItem({ kind: 'welcome', id: 'login_welcome' });
    }

    function hasPendingItem(kind, id) {
        var queue = readPendingQueue();
        return queue.some(function (item) {
            return item && item.kind === kind && item.id === id;
        });
    }

    function enqueuePendingItem(item) {
        if (!item || !item.kind || !item.id) return;
        var queue = readPendingQueue();
        var exists = queue.some(function (queuedItem) {
            return queuedItem && queuedItem.kind === item.kind && queuedItem.id === item.id;
        });
        if (exists) return;
        queue.push(item);
        writePendingQueue(queue);
        if (!panel.classList.contains('open')) {
            incrementUnreadCount();
        }
    }

    function addPendingAlertMessage(item) {
        var severity = item && item.severity ? String(item.severity).toLowerCase() : 'high';
        var severityLabel = severity ? severity.charAt(0).toUpperCase() + severity.slice(1) : 'High';
        var html =
            '<div><strong>Important alert:</strong> ' + escapeHtml(severityLabel) + ' priority system notice.</div>' +
            '<div class="ai-ui-inline-note">Review the latest notification and take the next action.</div>';
        addTypingMessage(html, 'assistant', true);
    }

    function flushPendingQueue() {
        var queue = readPendingQueue();
        if (!queue.length) {
            clearUnreadCount();
            return;
        }

        writePendingQueue([]);
        clearUnreadCount();

        queue.forEach(function (item) {
            if (!item || !item.kind || !item.id) return;
            if (pendingMessageIds[item.kind + ':' + item.id]) return;
            pendingMessageIds[item.kind + ':' + item.id] = true;

            if (item.kind === 'welcome') {
                loadWelcomeIfNeeded(true);
                return;
            }

            if (item.kind === 'coach_nudge') {
                addCoachNudgeMessage(item.nudge || {});
                return;
            }

            if (item.kind === 'important_alert') {
                addPendingAlertMessage(item);
            }
        });
    }

    function playPopSound() {
        try {
            var C = window.AudioContext || window.webkitAudioContext;
            if (!C) return;
            var ctx = new C();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 600;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.06);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.06);
        } catch (e) {}
    }

    function removeClarityOpeningMessages() {
        if (!messagesEl) return;
        messagesEl.querySelectorAll('[data-clarity-opening="true"]').forEach(function (el) {
            if (el && el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });
    }

    function cancelClarityOpeningMessages() {
        openingRequestSeq += 1;
        welcomeRequestInFlight = false;
        removeLoadingMessage(welcomeLoadingEl);
        welcomeLoadingEl = null;
        removeClarityOpeningMessages();
    }

    function addOpeningTypingMessage(text, isHtml) {
        var msg = addTypingMessage(text, 'assistant', isHtml);
        if (msg) {
            msg.setAttribute('data-clarity-opening', 'true');
        }
        return msg;
    }

    function loadWelcomeIfNeeded(force) {
        if (!messagesEl || welcomeRequestInFlight) return;
        if (force !== true && !panel.classList.contains('open')) return;

        var requestSeq = ++openingRequestSeq;
        removeClarityOpeningMessages();
        if (!welcomeLoadingEl) {
            welcomeLoadingEl = addLoadingMessage(isProtectedDemoSession()
                ? 'Preparing Riverside context'
                : (isOrganizationIntelligencePage() ? 'Preparing executive brief' : 'Clarity is getting your workspace ready'));
            if (welcomeLoadingEl) {
                welcomeLoadingEl.setAttribute('data-clarity-opening', 'true');
            }
        }
        welcomeRequestInFlight = true;

        fetch(withCurrentPage(getApiBase() + '/api/chat/welcome.php'), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (requestSeq !== openingRequestSeq) return;
                removeLoadingMessage(welcomeLoadingEl);
                welcomeLoadingEl = null;
                welcomeRequestInFlight = false;
                removeClarityOpeningMessages();
                var greeting = isOrganizationIntelligencePage()
                    ? buildOrganizationIntelligenceGreeting()
                    : (data.greeting || (isProtectedDemoSession()
                        ? "Hi, I'm Clarity. The Riverside demo is fully configured and ready to show the private lead-to-follow-up workflow."
                        : "Hi! I'm Clarity. Let's focus on the next move that makes the workspace stronger."));
                addOpeningTypingMessage(greeting, true);
                var openingInsight = isOrganizationIntelligencePage() ? buildOrganizationIntelligenceOpeningInsight() : data.opening_insight;
                if (openingInsight) {
                    setTimeout(function () {
                        if (requestSeq === openingRequestSeq) {
                            addStructuredInsightMessage(openingInsight, true);
                        }
                    }, 420);
                }
            })
            .catch(function () {
                if (requestSeq !== openingRequestSeq) return;
                removeLoadingMessage(welcomeLoadingEl);
                welcomeLoadingEl = null;
                welcomeRequestInFlight = false;
                removeClarityOpeningMessages();
                addOpeningTypingMessage(isOrganizationIntelligencePage()
                    ? buildOrganizationIntelligenceGreeting()
                    : (isProtectedDemoSession()
                        ? "I'm here with the Riverside demo context. Ask why Riverside should be handled first, or open Inbox to inspect the private thread."
                        : "I'm here. Start with the setup or task that feels most blocking, and I'll help you choose the next move."), isOrganizationIntelligencePage());
            });
    }

    function togglePanel() {
        var isOpen = panel.classList.contains('open');
        if (isOpen) {
            closePanel();
        } else {
            openPanel(true);
        }
    }

    function closePanel() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        if (bubbleBtn) bubbleBtn.setAttribute('aria-expanded', 'false');
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        } else if (bubbleBtn) {
            bubbleBtn.focus();
        }
        lastFocusedElement = null;
    }

    function openPanel(shouldFocus) {
        var alreadyOpen = panel.classList.contains('open');
        if (!alreadyOpen) {
            lastFocusedElement = document.activeElement;
        }
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        if (!alreadyOpen) {
            playPopSound();
        }
        if (bubbleBtn) bubbleBtn.setAttribute('aria-expanded', 'true');
        if (shouldFocus !== false && inputEl) {
            setTimeout(function () { inputEl.focus(); }, 200);
        }
        flushPendingQueue();
        if (isOrganizationIntelligencePage()) {
            loadOrganizationIntelligenceHistory();
        } else {
            loadWelcomeIfNeeded(true);
        }
    }

    function handlePanelKeyboard(event) {
        if (!panel.classList.contains('open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
            return;
        }
        if (event.key !== 'Tab') return;
        var focusable = Array.prototype.slice.call(panel.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]'))
            .filter(function (element) { return element.offsetParent !== null; });
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
    }

    function addCoachNudgeMessage(nudge) {
        if (!nudge) return;
        var insight = (nudge.insight || '').trim();
        var action = (nudge.action || '').trim();
        if (!insight && !action) return;
        var safeInsight = escapeHtml(insight || 'Review the latest signal.');
        var safeAction = escapeHtml(action || 'Take the next best action now.');
        var html =
            '<div><strong>Insight:</strong> ' + safeInsight + '</div>' +
            '<div><strong>Action:</strong> ' + safeAction + '</div>';
        addTypingMessage(html, 'assistant', true);
    }

    function addMessage(text, role, isHtml) {
        if (!messagesEl) return;
        var msg = document.createElement('div');
        msg.className = 'chat-bubble-message ' + role;
        if (isHtml) {
            msg.innerHTML = text;
        } else {
            msg.textContent = text;
        }
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return msg;
    }

    function renderInlineMarkdown(text) {
        return escapeHtml(text || '').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    }

    function renderMarkdownLite(text) {
        var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
        var html = '';
        var paragraph = [];
        var bullets = [];

        function flushParagraph() {
            if (!paragraph.length) return;
            html += '<p>' + renderInlineMarkdown(paragraph.join(' ')) + '</p>';
            paragraph = [];
        }

        function flushBullets() {
            if (!bullets.length) return;
            html += '<ul>';
            bullets.forEach(function (item) {
                html += '<li>' + renderInlineMarkdown(item) + '</li>';
            });
            html += '</ul>';
            bullets = [];
        }

        lines.forEach(function (line) {
            var trimmed = line.trim();
            var bulletMatch = trimmed.match(/^[-*]\s+(.+)$/);
            if (!trimmed) {
                flushParagraph();
                flushBullets();
                return;
            }
            if (bulletMatch) {
                flushParagraph();
                bullets.push(bulletMatch[1]);
                return;
            }
            flushBullets();
            paragraph.push(trimmed);
        });

        flushParagraph();
        flushBullets();
        return html || '<p></p>';
    }

    function buildStructuredInsightHtml(insight) {
        if (!insight || typeof insight !== 'object' || Array.isArray(insight)) return '';
        var title = String(insight.title || '').trim();
        var body = String(insight.body || '').trim();
        var bullets = Array.isArray(insight.bullets) ? insight.bullets : [];
        var thumbnailUrl = String(insight.thumbnail_url || '');
        var thumbnailAlt = String(insight.thumbnail_alt || '');
        var ctaLabel = String(insight.cta_label || '');
        var ctaUrl = String(insight.cta_url || '');
        var safeKind = String(insight.kind || 'general').toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
        var cleanBullets = bullets.map(function (item) {
            return String(item || '').trim();
        }).filter(function (item) {
            return item !== '';
        }).slice(0, 4);
        var hasThumbnail = thumbnailUrl !== '';
        var hasCta = ctaLabel !== '' && ctaUrl !== '';

        if (!title && !body && !cleanBullets.length && !hasThumbnail && !hasCta) return '';
        if (!title) title = 'Recommended Next Step';

        var classes = [
            'chat-bubble-insight',
            'chat-bubble-insight--' + safeKind,
            hasThumbnail ? 'chat-bubble-insight--with-thumb' : 'chat-bubble-insight--no-thumb',
            hasCta ? 'chat-bubble-insight--with-cta' : 'chat-bubble-insight--no-cta'
        ];
        var html = '<div class="' + classes.join(' ') + '">';
        if (thumbnailUrl) {
            html += '<div class="chat-bubble-insight-thumb"><img src="' + escapeAttribute(thumbnailUrl) + '" alt="' + escapeAttribute(thumbnailAlt) + '" loading="lazy"></div>';
        }
        html += '<div class="chat-bubble-insight-title"><strong>' + escapeHtml(title) + '</strong></div>';
        if (body) {
            html += '<p>' + escapeHtml(body) + '</p>';
        }
        if (cleanBullets.length) {
            html += '<ul>';
            cleanBullets.forEach(function (item) {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul>';
        }
        if (hasCta) {
            html += '<a class="chat-bubble-insight-cta" href="' + escapeAttribute(ctaUrl) + '">' + escapeHtml(ctaLabel) + '</a>';
        }
        html += '</div>';
        return html;
    }

    function addStructuredInsightMessage(insight, isOpening) {
        var html = buildStructuredInsightHtml(insight);
        if (!html) return;
        var msg = addMessage(html, 'assistant', true);
        if (msg && isOpening) {
            msg.setAttribute('data-clarity-opening', 'true');
        }
    }

    function getTaskCreateUrl() {
        return getApiBase() + '/api/tasks/create.php';
    }

    function getFeedbackUrl() {
        return getApiBase() + '/api/ai/feedback.php';
    }

    function getLinkTaskUrl() {
        return getApiBase() + '/api/ai/link_task_to_guidance.php';
    }

    function removePreviousFeedbackControls() {
        if (!messagesEl) return;
        messagesEl.querySelectorAll('.chat-bubble-feedback').forEach(function (el) {
            el.parentNode && el.parentNode.removeChild(el);
        });
    }

    function buildCanvasUpdateCandidateHtml(candidate) {
        if (!candidate || !candidate.block || typeof candidate.value !== 'string') {
            return '';
        }

        return '<div class="chat-bubble-canvas-candidate" data-block="' + escapeAttribute(candidate.block) + '" data-value="' + escapeAttribute(candidate.value) + '">' +
            '<div class="chat-bubble-canvas-candidate-title">Lean Canvas update candidate</div>' +
            '<div class="chat-bubble-canvas-candidate-body"><strong>' + escapeHtml(candidate.label || humanizeBlockName(candidate.block)) + ':</strong> ' + escapeHtml(candidate.value) + '</div>' +
            '<div class="chat-bubble-canvas-candidate-actions">' +
                '<button type="button" class="chat-bubble-feedback-btn" data-canvas-action="confirm">Confirm save</button>' +
                '<button type="button" class="chat-bubble-feedback-btn" data-canvas-action="cancel">Cancel</button>' +
                '<span class="chat-bubble-feedback-status"></span>' +
            '</div>' +
        '</div>';
    }

    function buildAssistantExtrasHtml(diagnostics, canvasUpdateCandidate) {
        var html = '';
        var operatorControlsEnabled = diagnostics && diagnostics.operator_controls_enabled === true;
        if (operatorControlsEnabled && diagnostics.ai_status && window.AIUiConsistency
            && typeof window.AIUiConsistency.renderExecutionStatus === 'function') {
            try {
                html += window.AIUiConsistency.renderExecutionStatus(diagnostics.ai_status);
            } catch (error) {
                console.warn('Clarity optional UI rendering failed.', { stage: 'execution_status' });
            }
        }
        html += buildCanvasUpdateCandidateHtml(canvasUpdateCandidate);
        if (operatorControlsEnabled && diagnostics.guidance_run_id && diagnostics.message_hash) {
            html += '<div class="chat-bubble-feedback" style="margin-top:.75rem;display:flex;gap:.45rem;flex-wrap:wrap;">' +
                '<button type="button" class="chat-bubble-feedback-btn" data-feedback-type="useful">Helpful</button>' +
                '<button type="button" class="chat-bubble-feedback-btn" data-feedback-type="not_useful">Not useful</button>' +
                '<button type="button" class="chat-bubble-feedback-btn" data-feedback-type="already_done">Done already</button>' +
                '<button type="button" class="chat-bubble-feedback-btn" data-feedback-type="acted_on">Create task</button>' +
                '<span class="chat-bubble-feedback-status" style="font-size:12px;color:#64748b;align-self:center;"></span>' +
            '</div>';
        }
        return html;
    }

    function isAssistantEnvelope(value) {
        if (!value || typeof value !== 'object') return false;
        if (Array.isArray(value)) return value.some(isAssistantEnvelope);
        var type = String(value.type || '').toLowerCase();
        if (['text', 'output_text', 'input_text', 'message', 'assistant', 'content'].indexOf(type) !== -1) return true;
        return ['message', 'response', 'answer', 'output_text', 'text', 'content', 'output', 'choices'].some(function (key) {
            return Object.prototype.hasOwnProperty.call(value, key);
        });
    }

    function extractAssistantText(value) {
        if (typeof value === 'string' || typeof value === 'number') return String(value).trim();
        if (!value || typeof value !== 'object') return '';
        if (Array.isArray(value)) {
            return value.map(extractAssistantText).filter(function (item) { return item !== ''; }).join('\n');
        }
        if (typeof value.value === 'string') return value.value.trim();
        var keys = ['message', 'response', 'answer', 'output_text', 'text', 'content', 'output', 'choices'];
        for (var i = 0; i < keys.length; i += 1) {
            if (!Object.prototype.hasOwnProperty.call(value, keys[i])) continue;
            var text = extractAssistantText(value[keys[i]]);
            if (text) return text;
        }
        return '';
    }

    function normalizeAssistantAnswer(answer) {
        if (answer == null) return '';
        if (typeof answer !== 'string') {
            if (isAssistantEnvelope(answer)) return extractAssistantText(answer);
            try {
                return JSON.stringify(answer);
            } catch (e) {
                return String(answer);
            }
        }

        var trimmed = answer.trim();
        if (!trimmed || !/^[\[{]/.test(trimmed)) {
            return answer;
        }

        try {
            var parsed = JSON.parse(trimmed);
            if (isAssistantEnvelope(parsed)) return extractAssistantText(parsed);
        } catch (e) {}

        return answer;
    }

    function createTaskFromGuidance(answer, diagnostics, statusEl, triggerBtn) {
        var host = statusEl && statusEl.parentNode ? statusEl.parentNode : null;
        if (!host) {
            if (triggerBtn) triggerBtn.disabled = false;
            return;
        }
        var existing = host.querySelector('.chat-bubble-task-confirm');
        if (existing) {
            var existingInput = existing.querySelector('input');
            if (existingInput) existingInput.focus();
            return;
        }

        var form = document.createElement('form');
        form.className = 'chat-bubble-task-confirm';
        var label = document.createElement('label');
        label.textContent = 'Task title';
        var input = document.createElement('input');
        input.type = 'text';
        input.required = true;
        input.maxLength = 180;
        input.value = 'Follow up: ' + answer.slice(0, 60);
        label.appendChild(input);
        var actions = document.createElement('div');
        actions.className = 'chat-bubble-task-confirm-actions';
        var save = document.createElement('button');
        save.type = 'submit';
        save.textContent = 'Create task';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        actions.append(save, cancel);
        form.append(label, actions);
        host.appendChild(form);
        input.focus();
        input.select();

        cancel.addEventListener('click', function () {
            form.remove();
            if (statusEl) statusEl.textContent = 'Task creation cancelled.';
            if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var title = input.value.trim();
            if (!title) {
                if (statusEl) statusEl.textContent = 'Enter a task title.';
                input.focus();
                return;
            }
            save.disabled = true;
            cancel.disabled = true;
            if (statusEl) statusEl.textContent = 'Creating task...';

            fetch(getTaskCreateUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: getCsrfToken(),
                    title: title,
                    description: 'Created from Clarity feedback',
                    metadata_json: {
                        source_surface: diagnostics.surface || 'clarity_chat',
                        source_recommendation_type: 'clarity_feedback',
                        guidance_run_id: diagnostics.guidance_run_id,
                        message_hash: diagnostics.message_hash
                    }
                })
            })
                .then(function (res) { return res.json().catch(function () { return {}; }); })
                .then(function (data) {
                    if (!data.task_id) {
                        throw new Error(data.error || 'Unable to create task.');
                    }
                    return fetch(getLinkTaskUrl(), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: getCsrfToken(),
                            task_id: data.task_id,
                            guidance_run_id: diagnostics.guidance_run_id,
                            surface: diagnostics.surface || 'clarity_chat',
                            message_hash: diagnostics.message_hash,
                            source_recommendation_type: 'clarity_feedback'
                        })
                    }).then(function () {
                        form.remove();
                        if (statusEl) statusEl.textContent = 'Task created and linked.';
                    });
                })
                .catch(function (err) {
                    if (statusEl) statusEl.textContent = err.message || 'Unable to create task.';
                    save.disabled = false;
                    cancel.disabled = false;
                    input.focus();
                })
                .finally(function () {
                    if (!form.isConnected && triggerBtn) triggerBtn.disabled = false;
                });
        });
    }

    function bindLatestAssistantFeedback(messageEl, answer, diagnostics) {
        if (!messageEl || !diagnostics || diagnostics.operator_controls_enabled !== true
            || !diagnostics.guidance_run_id || !diagnostics.message_hash) return;
        messageEl.querySelectorAll('.chat-bubble-feedback-btn').forEach(function (btn) {
            if (btn.hasAttribute('data-canvas-action')) {
                return;
            }
            btn.addEventListener('click', function () {
                var statusEl = messageEl.querySelector('.chat-bubble-feedback-status');
                var feedbackType = btn.getAttribute('data-feedback-type') || 'useful';
                btn.disabled = true;
                if (feedbackType === 'acted_on') {
                    createTaskFromGuidance(answer, diagnostics, statusEl, btn);
                    return;
                }
                fetch(getFeedbackUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf_token: getCsrfToken(),
                        surface: 'clarity_chat',
                        guidance_run_id: diagnostics.guidance_run_id,
                        message_hash: diagnostics.message_hash,
                        feedback_type: feedbackType
                    })
                })
                    .then(function (res) { return res.json().catch(function () { return {}; }); })
                    .then(function (data) {
                        if (data.success) {
                            if (statusEl) statusEl.textContent = 'Saved.';
                        } else if (statusEl) {
                            statusEl.textContent = data.error || 'Unable to save feedback.';
                        }
                    })
                    .catch(function () {
                        if (statusEl) statusEl.textContent = 'Unable to save feedback.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        });
    }

    function bindCanvasUpdateActions(messageEl, canvasUpdateCandidate) {
        if (!messageEl || !canvasUpdateCandidate) return;
        var candidateEl = messageEl.querySelector('.chat-bubble-canvas-candidate');
        if (!candidateEl) return;

        candidateEl.querySelectorAll('[data-canvas-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-canvas-action');
                var statusEl = candidateEl.querySelector('.chat-bubble-feedback-status');
                if (action === 'cancel') {
                    candidateEl.parentNode && candidateEl.parentNode.removeChild(candidateEl);
                    return;
                }
                btn.disabled = true;
                var payload = {
                    csrf_token: getCsrfToken(),
                    lean_canvas_mode_enabled: leanCanvasState.enabled ? '1' : '0'
                };
                payload[canvasFieldNameForBlock(canvasUpdateCandidate.block)] = canvasUpdateCandidate.value;

                fetch(getStrategyProfileUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                })
                    .then(function (res) { return res.json().catch(function () { return {}; }); })
                    .then(function (data) {
                        if (data && data.success) {
                            normalizeLeanCanvasMetadata(data);
                            if (statusEl) statusEl.textContent = 'Saved.';
                            return;
                        }
                        throw new Error((data && data.error) || 'Unable to save Lean Canvas update.');
                    })
                    .catch(function (err) {
                        if (statusEl) statusEl.textContent = err.message || 'Unable to save Lean Canvas update.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        });
    }

    function addAssistantResponse(answer, diagnostics, canvasUpdateCandidate) {
        if (!messagesEl) return;
        answer = normalizeAssistantAnswer(answer);
        if (!answer) answer = "Sorry, I couldn't get a usable answer. Please try again.";
        removePreviousFeedbackControls();
        var msg = document.createElement('div');
        msg.className = 'chat-bubble-message assistant';
        msg.innerHTML = '<div class="chat-bubble-answer-text">' + renderMarkdownLite(answer) + '</div>';
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        try {
            msg.insertAdjacentHTML('beforeend', buildAssistantExtrasHtml(diagnostics, canvasUpdateCandidate));
            bindLatestAssistantFeedback(msg, answer, diagnostics || {});
            bindCanvasUpdateActions(msg, canvasUpdateCandidate);
        } catch (error) {
            console.warn('Clarity optional UI binding failed.', { stage: 'assistant_extras' });
        }
    }

    function addTypingMessage(text, role, isHtml) {
        if (!messagesEl) return null;
        var msg = document.createElement('div');
        msg.className = 'chat-bubble-message ' + role + ' typing';
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        var full = text || '';
        var i = 0;
        var charsPerTick = 1;
        var delay = 28;
        function typeNext() {
            if (i >= full.length) {
                msg.classList.remove('typing');
                return;
            }
            var chunk = '';
            if (full.charAt(i) === '<') {
                var end = full.indexOf('>', i + 1);
                chunk = end >= 0 ? full.slice(i, end + 1) : full.charAt(i);
                i = end >= 0 ? end + 1 : i + 1;
            } else {
                chunk = full.slice(i, i + charsPerTick);
                i += charsPerTick;
            }
            if (isHtml) {
                msg.innerHTML = (msg.innerHTML || '') + chunk;
            } else {
                msg.textContent = (msg.textContent || '') + chunk;
            }
            messagesEl.scrollTop = messagesEl.scrollHeight;
            setTimeout(typeNext, delay);
        }
        setTimeout(typeNext, 80);
        return msg;
    }

    function addLoadingMessage(label) {
        if (!messagesEl) return null;
        var msg = document.createElement('div');
        msg.className = 'chat-bubble-message assistant loading';
        msg.innerHTML = escapeHtml(label || 'Thinking') + '<span class="chat-bubble-dots"><span></span><span></span><span></span></span>';
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return msg;
    }

    function removeLoadingMessage(msgEl) {
        if (msgEl && msgEl.parentNode) {
            msgEl.parentNode.removeChild(msgEl);
        }
    }

    function isProtectedDemoSession() {
        return !!(window.protectedDemoClientState && window.protectedDemoClientState.is_protected_demo);
    }

    function protectedDemoAssistantFallback(promptText) {
        var lower = String(promptText || '').toLowerCase();
        if (lower.indexOf('riverside') !== -1 || lower.indexOf('amina') !== -1) {
            return 'Riverside is the live demo thread. Amina asks for revised proposal terms, Rose adds rollout context, and Clarity keeps the reply draft, task, target, and contact intelligence connected inside your private session.';
        }
        if (lower.indexOf('inbox') !== -1 || lower.indexOf('message') !== -1 || lower.indexOf('lead') !== -1) {
            return 'In this protected demo, Clarity is watching the private Riverside Inbox overlay. Open Inbox to see simulated WhatsApp and email activity, triage context, and the assistant-draft moment without contacting a real provider.';
        }
        if (lower.indexOf('task') !== -1 || lower.indexOf('target') !== -1) {
            return 'In this protected demo, Clarity connects the Riverside message thread to Tasks and Targets so the next action feels operational instead of decorative. The highlighted demo moments will point you to those pages as they mature.';
        }
        if (lower.indexOf('plugin') !== -1 || lower.indexOf('marketplace') !== -1) {
            return 'In this protected demo, Plugins are presented as capability building blocks. You can browse the setup story safely, while real credential setup and outbound integrations stay disabled.';
        }
        return 'This protected demo is using a safe Clarity preview response. The private Riverside timeline will surface Inbox, Tasks, Targets, Plugins, Contact Intelligence, and the Clarity Journey in a paced sequence.';
    }

    function sendMessage(forcedText, forcedSurface) {
        var text = (typeof forcedText === 'string' ? forcedText : (inputEl && inputEl.value || '')).trim();
        if (!text) return;

        if (isProtectedDemoSession()) {
            cancelClarityOpeningMessages();
        }

        if (inputEl) inputEl.value = '';
        if (sendBtn) sendBtn.disabled = true;

        addMessage(text, 'user');

        var loadingEl = addLoadingMessage(isOrganizationIntelligencePage() ? 'Reviewing executive context' : undefined);

        var payload = {
            message: text,
            current_page: getCurrentPage(),
            surface: forcedSurface || 'clarity_chat'
        };
        if (isOrganizationIntelligencePage()) {
            payload.page_context = getOrganizationIntelligenceContext();
        } else if (clarityConversationId) {
            payload.conversation_id = clarityConversationId;
        }

        if (isProtectedDemoSession()) {
            setTimeout(function () {
                removeLoadingMessage(loadingEl);
                addTypingMessage(protectedDemoAssistantFallback(text), 'assistant');
                if (sendBtn) sendBtn.disabled = false;
                if (inputEl) inputEl.focus();
            }, 420);
            return;
        }

        fetch(getApiBase() + '/api/chat/ask.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                return res.text().then(function (body) {
                    var data;
                    try {
                        data = JSON.parse(body || '{}');
                    } catch (error) {
                        error.chatStage = 'answer_invalid_json';
                        throw error;
                    }
                    if (!data || typeof data !== 'object' || Array.isArray(data)) {
                        var shapeError = new Error('Invalid response envelope.');
                        shapeError.chatStage = 'answer_invalid_envelope';
                        throw shapeError;
                    }
                    if (!res.ok && !data.answer) {
                        var requestError = new Error(data.error || 'Clarity request failed.');
                        requestError.chatStage = 'answer_http';
                        throw requestError;
                    }
                    return data;
                });
            })
            .then(function (data) {
                removeLoadingMessage(loadingEl);
                var answer = data.answer || '';
                if (isProtectedDemoSession() && (!answer || /sorry|something went wrong|couldn't get an answer|failed to get answer/i.test(answer))) {
                    answer = protectedDemoAssistantFallback(text);
                }
                if (!answer) {
                    answer = data.error || "Sorry, I couldn't get an answer. Please try again.";
                }
                if (!data.metadata || data.metadata.mode_variant !== 'superadmin_ops') {
                    normalizeLeanCanvasMetadata(data.metadata || {});
                }
                if (isOrganizationIntelligencePage() && data.conversation_id) {
                    var organizationContext = getOrganizationIntelligenceContext();
                    if (organizationContext) organizationContext.conversation_id = data.conversation_id;
                } else if (data.conversation_id) {
                    clarityConversationId = data.conversation_id;
                }
                var diagnostics = data.diagnostics && typeof data.diagnostics === 'object' && !Array.isArray(data.diagnostics)
                    ? data.diagnostics
                    : {};
                diagnostics.operator_controls_enabled = data.metadata
                    && data.metadata.operator_controls_enabled === true;
                diagnostics.ai_status = diagnostics.operator_controls_enabled ? (data.ai_status || null) : null;
                addAssistantResponse(answer, diagnostics, data.canvas_update_candidate || null);
            })
            .catch(function (err) {
                removeLoadingMessage(loadingEl);
                if (isProtectedDemoSession()) {
                    addTypingMessage(protectedDemoAssistantFallback(text), 'assistant');
                } else if (isOrganizationIntelligencePage()) {
                    reconcileOrganizationIntelligenceHistory(0).then(function (recovered) {
                        if (!recovered) addTypingMessage('Something went wrong. Please try again.', 'assistant');
                    });
                } else {
                    addTypingMessage('Something went wrong. Please try again.', 'assistant');
                }
                console.error('Clarity answer request failed.', { stage: err.chatStage || 'answer_transport' });
            })
            .finally(function () {
                if (sendBtn) sendBtn.disabled = false;
                if (inputEl) inputEl.focus();
            });
    }

    applyOrganizationIntelligenceChatMode();

    if (bubbleBtn) {
        bubbleBtn.addEventListener('click', togglePanel);
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', togglePanel);
    }
    document.addEventListener('keydown', handlePanelKeyboard);

    if (sendBtn && inputEl) {
        sendBtn.addEventListener('click', sendMessage);
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    window.addEventListener('della:open', function (event) {
        var detail = event && event.detail ? event.detail : {};
        if (detail.reason === 'coach_nudge' && detail.id) {
            var nudgeId = String(detail.id);
            if (hasPendingItem('coach_nudge', nudgeId)) return;
            enqueuePendingItem({
                kind: 'coach_nudge',
                id: nudgeId,
                nudge: detail.nudge || {}
            });
            if (panel.classList.contains('open')) {
                flushPendingQueue();
            }
            return;
        }

        if (detail.reason === 'important_alert' && detail.id) {
            var alertId = String(detail.id);
            if (hasPendingItem('important_alert', alertId)) return;
            enqueuePendingItem({
                kind: 'important_alert',
                id: alertId,
                severity: detail.severity || ''
            });
            if (panel.classList.contains('open')) {
                flushPendingQueue();
            }
        }
    });

    window.ClarityChatBubble = {
        ask: function (message, surface) {
            openPanel(false);
            if (isProtectedDemoSession()) {
                cancelClarityOpeningMessages();
            }
            sendMessage(String(message || ''), surface || 'clarity_chat');
        },
        demoMessage: function (message) {
            openPanel(false);
            if (isProtectedDemoSession()) {
                cancelClarityOpeningMessages();
            }
            addAssistantResponse(String(message || protectedDemoAssistantFallback('demo')), {}, null);
        },
        open: function () {
            openPanel(true);
        }
    };

    try {
        prepareLoginWelcome();
        syncUnreadMarker();
    } catch (e) {}
})();
