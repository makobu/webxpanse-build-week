<?php
/**
 * Workspace click-to-connect cards.
 *
 * Expected variables:
 * - array $workspaceConnectState
 * - string $workspaceConnectSurface
 * - array<string>|null $workspaceConnectCards Optional allowlist: main_email, assistant_email, calendar, whatsapp, meetings
 * - string|null $workspaceConnectHeading
 * - string|null $workspaceConnectDescription
 */

$workspaceConnectState = is_array($workspaceConnectState ?? null) ? $workspaceConnectState : [];
$workspaceConnectSurface = (string) ($workspaceConnectSurface ?? 'settings');
$workspaceConnectCardsProvided = isset($workspaceConnectCards);
$workspaceConnectCardsInput = $workspaceConnectCards ?? [];
if (is_string($workspaceConnectCardsInput)) {
    $workspaceConnectCardsInput = [$workspaceConnectCardsInput];
}
if (!is_array($workspaceConnectCardsInput)) {
    $workspaceConnectCardsInput = [];
}
$workspaceConnectValidCards = [
    'main_email' => true,
    'assistant_email' => true,
    'calendar' => true,
    'whatsapp' => true,
    'meetings' => true,
];
$workspaceConnectCards = array_values(array_unique(array_filter(
    array_map(static fn($card): string => trim((string) $card), $workspaceConnectCardsInput),
    static fn(string $card): bool => isset($workspaceConnectValidCards[$card])
)));
$workspaceConnectRenderAll = !$workspaceConnectCardsProvided || $workspaceConnectCards === [];
$workspaceConnectShouldRender = static function (string $card) use ($workspaceConnectRenderAll, $workspaceConnectCards): bool {
    return $workspaceConnectRenderAll || in_array($card, $workspaceConnectCards, true);
};
$workspaceConnectCardCount = $workspaceConnectRenderAll ? count($workspaceConnectValidCards) : count($workspaceConnectCards);
$workspaceConnectIncludesWhatsapp = $workspaceConnectShouldRender('whatsapp');
$workspaceConnectHasHealthCheck = $workspaceConnectShouldRender('main_email')
    || $workspaceConnectShouldRender('assistant_email')
    || $workspaceConnectShouldRender('whatsapp');
$workspaceConnectHeading = trim((string) ($workspaceConnectHeading ?? ''));
$workspaceConnectDescription = trim((string) ($workspaceConnectDescription ?? ''));
if ($workspaceConnectHeading === '') {
    $workspaceConnectHeading = 'Connect apps';
}
if ($workspaceConnectDescription === '') {
    $workspaceConnectDescription = 'Set up the channels your workspace uses every day. No API keys or technical setup needed here.';
}
$workspaceConnectCanManage = !empty($workspaceConnectState['can_manage']);
$workspaceConnectPlatform = (array) ($workspaceConnectState['platform'] ?? []);
$workspaceConnectEmail = (array) ($workspaceConnectState['email'] ?? []);
$workspaceConnectCalendar = (array) ($workspaceConnectState['calendar'] ?? []);
$workspaceConnectWhatsapp = (array) ($workspaceConnectState['whatsapp'] ?? []);
$workspaceConnectMeetings = (array) ($workspaceConnectState['meetings'] ?? []);
$workspaceConnectApiBase = function_exists('getApiBasePath') ? rtrim(getApiBasePath(), '/') : '';
$workspaceConnectUser = class_exists('\\CRM\\Auth') ? (\CRM\Auth::user() ?? []) : [];
$workspaceConnectWhatsappSetupPath = class_exists('\\CRM\\Authorization') && \CRM\Authorization::isSuperAdmin($workspaceConnectUser)
    ? 'settings.php?tab=whatsapp&setup_module=whatsapp&setup_tab=manual'
    : 'workspace_skills.php?module=whatsapp&setup_tab=manual#setup';
$workspaceConnectWhatsAppSetupUrl = function_exists('publicUrl')
    ? publicUrl($workspaceConnectWhatsappSetupPath)
    : $workspaceConnectWhatsappSetupPath;
$workspaceConnectHealth = [];
if (!empty($workspaceConnectState['workspace_id'])) {
    try {
        $workspaceConnectHealth = (new \CRM\Services\WorkspaceChannelHealthService())->summarize((int) $workspaceConnectState['workspace_id'], \CRM\Auth::user());
    } catch (\Throwable $e) {
        $workspaceConnectHealth = [];
    }
}
$workspaceConnectMainEmailHealth = (array) ($workspaceConnectHealth['main_email'] ?? []);
$workspaceConnectAssistantEmailHealth = (array) ($workspaceConnectHealth['assistant_email'] ?? []);
$workspaceConnectWhatsAppHealth = (array) ($workspaceConnectHealth['whatsapp'] ?? []);

$statusLabel = static function (array $state): string {
    $status = (string) ($state['status'] ?? 'not_connected');
    return match ($status) {
        'ready' => 'Ready',
        'warning' => 'Check setup',
        'connected' => 'Connected',
        'connecting' => 'Connecting',
        'needs_attention' => 'Needs attention',
        'disabled' => 'Disabled by platform setup',
        default => 'Not connected',
    };
};
$statusClass = static function (array $state): string {
    $status = (string) ($state['status'] ?? 'not_connected');
    return in_array($status, ['ready', 'warning', 'connected', 'connecting', 'needs_attention', 'disabled'], true) ? $status : 'not-connected';
};
$renderHealth = static function (array $health): void {
    $badges = array_slice((array) ($health['badges'] ?? []), 0, 4);
    $issues = array_slice((array) ($health['issues'] ?? []), 0, 2);
    $actions = array_slice((array) ($health['actions'] ?? []), 0, 2);
    if (empty($badges) && empty($issues) && empty($actions)) {
        return;
    }
    ?>
    <div class="workspace-connect-health" data-health-body>
        <?php if (!empty($badges)): ?>
            <div class="workspace-connect-badges" data-health-badges>
                <?php foreach ($badges as $badge): ?>
                    <span><?php echo htmlspecialchars((string) $badge); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($issues)): ?>
            <ul class="workspace-connect-health-list" data-health-issues>
                <?php foreach ($issues as $issue): ?>
                    <li><?php echo htmlspecialchars((string) $issue); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($actions)): ?>
            <ul class="workspace-connect-health-list is-actions" data-health-actions>
                <?php foreach ($actions as $action): ?>
                    <li><?php echo htmlspecialchars((string) $action); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
};
$buttonStyle = '';
?>
<section id="connect-apps" class="workspace-connect-apps" data-surface="<?php echo htmlspecialchars($workspaceConnectSurface); ?>">
    <div class="workspace-connect-header">
        <div>
            <h2><?php echo htmlspecialchars($workspaceConnectHeading); ?></h2>
            <p><?php echo htmlspecialchars($workspaceConnectDescription); ?></p>
        </div>
        <?php if (!$workspaceConnectCanManage): ?>
            <span class="workspace-connect-status disabled">Admin only</span>
        <?php endif; ?>
    </div>

    <div class="workspace-connect-message" data-connect-message></div>

    <div class="workspace-connect-grid<?php echo $workspaceConnectCardCount === 1 ? ' workspace-connect-grid--single' : ''; ?>">
        <?php if ($workspaceConnectShouldRender('main_email')): ?>
        <article class="workspace-connect-card" data-channel-card="main_email">
            <div class="workspace-connect-card-head">
                <div class="workspace-connect-title-row">
                    <span class="workspace-connect-icon" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                    <div>
                        <h3>Gmail / Google Workspace</h3>
                        <p><?php echo !empty($workspaceConnectEmail['email']) ? 'Mailbox: ' . htmlspecialchars((string) $workspaceConnectEmail['email']) : 'Use Google mail for the shared CRM inbox and outbound email.'; ?></p>
                    </div>
                </div>
                <span class="workspace-connect-status <?php echo htmlspecialchars($statusClass($workspaceConnectMainEmailHealth ?: $workspaceConnectEmail)); ?>" data-health-status><?php echo htmlspecialchars((string) ($workspaceConnectMainEmailHealth['label'] ?? $statusLabel($workspaceConnectEmail))); ?></span>
            </div>
            <?php $renderHealth($workspaceConnectMainEmailHealth); ?>
            <div class="workspace-connect-actions">
                <?php if (!$workspaceConnectCanManage): ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Ask a workspace admin</span>
                <?php elseif (!empty($workspaceConnectPlatform['gmail_send'])): ?>
                    <a class="workspace-connect-primary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/email/gmail/initiate.php?grant=send'); ?>">Connect Gmail Sending</a>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage && !empty($workspaceConnectPlatform['gmail_inbox'])): ?>
                    <a class="workspace-connect-secondary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/email/gmail/initiate.php?grant=inbox'); ?>">Connect Gmail Inbox Sync</a>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage && !empty($workspaceConnectPlatform['gmail_send'])): ?>
                    <a class="workspace-connect-secondary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/email/oauth/initiate.php?grant=send'); ?>">Connect Workspace Sending</a>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage && !empty($workspaceConnectPlatform['gmail_inbox'])): ?>
                    <a class="workspace-connect-secondary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/email/oauth/initiate.php?grant=inbox'); ?>">Connect Workspace Inbox</a>
                <?php elseif ($workspaceConnectCanManage && empty($workspaceConnectPlatform['gmail_send']) && empty($workspaceConnectPlatform['gmail_inbox'])): ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Platform Gmail clients not configured</span>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage): ?>
                    <button type="button" class="workspace-connect-test" style="<?php echo $buttonStyle; ?>" data-health-check="main_email">Check health</button>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($workspaceConnectShouldRender('assistant_email')): ?>
        <article class="workspace-connect-card" data-channel-card="assistant_email">
            <div class="workspace-connect-card-head">
                <div class="workspace-connect-title-row">
                    <span class="workspace-connect-icon" aria-hidden="true"><i class="fas fa-robot"></i></span>
                    <div>
                        <h3>Email Assistant Gmail</h3>
                        <p><?php echo !empty($workspaceConnectAssistantEmailHealth['connected_email']) ? 'Assistant mailbox: ' . htmlspecialchars((string) $workspaceConnectAssistantEmailHealth['connected_email']) : 'Connect Gmail for assistant replies and inbound instructions.'; ?></p>
                    </div>
                </div>
                <span class="workspace-connect-status <?php echo htmlspecialchars($statusClass($workspaceConnectAssistantEmailHealth)); ?>" data-health-status><?php echo htmlspecialchars((string) ($workspaceConnectAssistantEmailHealth['label'] ?? 'Assistant Gmail not connected')); ?></span>
            </div>
            <?php $renderHealth($workspaceConnectAssistantEmailHealth); ?>
            <div class="workspace-connect-actions">
                <?php if (!$workspaceConnectCanManage): ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Ask a workspace admin</span>
                <?php elseif (!empty($workspaceConnectPlatform['assistant_gmail'])): ?>
                    <a class="workspace-connect-primary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/email/assistant_gmail/initiate.php'); ?>">Connect Assistant Gmail</a>
                <?php else: ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Platform Gmail app not configured</span>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage): ?>
                    <button type="button" class="workspace-connect-test" style="<?php echo $buttonStyle; ?>" data-health-check="assistant_email">Check health</button>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($workspaceConnectShouldRender('calendar')): ?>
        <article class="workspace-connect-card">
            <div class="workspace-connect-card-head">
                <div class="workspace-connect-title-row">
                    <span class="workspace-connect-icon is-calendar" aria-hidden="true"><i class="fas fa-calendar-days"></i></span>
                    <div>
                        <h3>Google Calendar</h3>
                        <p><?php echo !empty($workspaceConnectCalendar['calendar_name']) ? 'Calendar: ' . htmlspecialchars((string) $workspaceConnectCalendar['calendar_name']) : 'Sync meetings and follow-up events with the workspace calendar.'; ?></p>
                    </div>
                </div>
                <span class="workspace-connect-status <?php echo htmlspecialchars($statusClass($workspaceConnectCalendar)); ?>"><?php echo htmlspecialchars($statusLabel($workspaceConnectCalendar)); ?></span>
            </div>
            <div class="workspace-connect-actions">
                <?php if ($workspaceConnectCanManage && !empty($workspaceConnectPlatform['google_calendar'])): ?>
                    <a class="workspace-connect-primary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/calendar/oauth/initiate.php?provider=google&grant=calendar_import&purpose=sync'); ?>">Connect Google Calendar</a>
                <?php elseif ($workspaceConnectCanManage): ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Platform Calendar app not configured</span>
                <?php else: ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Ask a workspace admin</span>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($workspaceConnectShouldRender('whatsapp')): ?>
        <article class="workspace-connect-card" data-channel-card="whatsapp">
            <div class="workspace-connect-card-head">
                <div class="workspace-connect-title-row">
                    <span class="workspace-connect-icon is-whatsapp" aria-hidden="true"><i class="fab fa-whatsapp"></i></span>
                    <div>
                        <h3>WhatsApp number</h3>
                        <p><?php echo !empty($workspaceConnectWhatsapp['display_phone_number']) ? 'Number: ' . htmlspecialchars((string) $workspaceConnectWhatsapp['display_phone_number']) : 'Save WhatsApp Business API credentials in Marketplace setup.'; ?></p>
                    </div>
                </div>
                <span class="workspace-connect-status <?php echo htmlspecialchars($statusClass($workspaceConnectWhatsAppHealth ?: $workspaceConnectWhatsapp)); ?>" data-whatsapp-status data-health-status><?php echo htmlspecialchars((string) ($workspaceConnectWhatsAppHealth['label'] ?? $statusLabel($workspaceConnectWhatsapp))); ?></span>
            </div>
            <?php $renderHealth($workspaceConnectWhatsAppHealth); ?>
            <div class="workspace-connect-actions">
                <?php if ($workspaceConnectCanManage): ?>
                    <a class="workspace-connect-primary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectWhatsAppSetupUrl); ?>">Open manual setup</a>
                    <?php if (!empty($workspaceConnectPlatform['whatsapp_embedded_signup'])): ?>
                        <button type="button" class="workspace-connect-test" style="<?php echo $buttonStyle; ?>cursor:pointer;" data-whatsapp-connect>Meta signup</button>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Ask a workspace admin</span>
                <?php endif; ?>
                <?php if ($workspaceConnectCanManage): ?>
                    <button type="button" class="workspace-connect-test" style="<?php echo $buttonStyle; ?>" data-health-check="whatsapp">Check health</button>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($workspaceConnectShouldRender('meetings')): ?>
        <article class="workspace-connect-card">
            <div class="workspace-connect-card-head">
                <div class="workspace-connect-title-row">
                    <span class="workspace-connect-icon is-meeting" aria-hidden="true"><i class="fas fa-video"></i></span>
                    <div>
                        <h3>Meeting notes</h3>
                        <p>Use the connected calendar to detect Google Meet and Zoom links for meeting notes.</p>
                    </div>
                </div>
                <span class="workspace-connect-status <?php echo htmlspecialchars($statusClass($workspaceConnectMeetings)); ?>"><?php echo htmlspecialchars($statusLabel($workspaceConnectMeetings)); ?></span>
            </div>
            <div class="workspace-connect-actions">
                <?php if (($workspaceConnectMeetings['status'] ?? '') === 'connected'): ?>
                    <a class="workspace-connect-secondary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars(publicUrl('workspace_skills.php?module=calendar_meetings#setup')); ?>">Review meeting notes</a>
                <?php elseif ($workspaceConnectCanManage && !empty($workspaceConnectPlatform['google_calendar'])): ?>
                    <a class="workspace-connect-primary" style="<?php echo $buttonStyle; ?>" href="<?php echo htmlspecialchars($workspaceConnectApiBase . '/api/calendar/oauth/initiate.php?provider=google&grant=calendar_import&purpose=sync'); ?>">Connect calendar first</a>
                <?php else: ?>
                    <span class="workspace-connect-disabled" style="<?php echo $buttonStyle; ?>">Calendar required</span>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>
    </div>

    <?php if ($workspaceConnectCanManage && $workspaceConnectHasHealthCheck): ?>
        <script>
        (function () {
            if (window.__workspaceChannelHealthBound) return;
            window.__workspaceChannelHealthBound = true;

            function renderList(container, selector, items, actionClass) {
                var existing = container.querySelector(selector);
                if (existing) existing.remove();
                if (!items || !items.length) return;
                var list = document.createElement('ul');
                list.className = 'workspace-connect-health-list' + (actionClass ? ' is-actions' : '');
                list.setAttribute(selector.replace('[', '').replace(']', ''), '');
                items.slice(0, 2).forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    list.appendChild(li);
                });
                container.appendChild(list);
            }

            function updateCard(channel, health) {
                var card = document.querySelector('[data-channel-card="' + channel + '"]');
                if (!card || !health) return;
                var status = card.querySelector('[data-health-status]');
                if (status) {
                    status.textContent = health.label || 'Checked';
                    status.className = 'workspace-connect-status ' + (health.status || 'not-connected').replace('_', '-');
                }
                var body = card.querySelector('[data-health-body]');
                if (!body) {
                    body = document.createElement('div');
                    body.className = 'workspace-connect-health';
                    body.setAttribute('data-health-body', '');
                    var actions = card.querySelector('.workspace-connect-actions');
                    card.insertBefore(body, actions || null);
                }
                var badges = body.querySelector('[data-health-badges]');
                if (badges) badges.remove();
                if (health.badges && health.badges.length) {
                    badges = document.createElement('div');
                    badges.className = 'workspace-connect-badges';
                    badges.setAttribute('data-health-badges', '');
                    health.badges.slice(0, 4).forEach(function (badge) {
                        var span = document.createElement('span');
                        span.textContent = badge;
                        badges.appendChild(span);
                    });
                    body.prepend(badges);
                }
                renderList(body, '[data-health-issues]', health.issues || [], false);
                renderList(body, '[data-health-actions]', health.actions || [], true);
            }

            function showMessage(text, isError) {
                var messageEl = document.querySelector('[data-connect-message]');
                if (!messageEl) return;
                messageEl.textContent = text;
                messageEl.style.background = isError ? '#fff7ed' : '#f8fbff';
                messageEl.style.color = isError ? '#c2410c' : '#1d4ed8';
                messageEl.style.borderColor = isError ? '#fed7aa' : '#dbeafe';
                messageEl.classList.add('is-visible');
            }

            document.querySelectorAll('[data-health-check]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var channel = button.getAttribute('data-health-check');
                    button.disabled = true;
                    button.textContent = 'Checking...';
                    fetch(<?php echo json_encode($workspaceConnectApiBase . '/api/workspace/channel_health.php'); ?>, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (response) {
                        return response.json().catch(function () { return {}; }).then(function (data) {
                            if (!response.ok || !data.success) throw new Error(data.error || 'Health check failed.');
                            return data;
                        });
                    }).then(function (data) {
                        updateCard(channel, data.channels ? data.channels[channel] : null);
                        showMessage('Channel health checked. No messages were sent.', false);
                    }).catch(function (error) {
                        showMessage(error.message || 'Channel health check failed.', true);
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = 'Check health';
                    });
                });
            });
        })();
        </script>
    <?php endif; ?>

    <?php if ($workspaceConnectCanManage && $workspaceConnectIncludesWhatsapp && !empty($workspaceConnectPlatform['whatsapp_embedded_signup'])): ?>
        <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
        <script>
        (function () {
            if (window.__workspaceConnectAppsBound) return;
            window.__workspaceConnectAppsBound = true;

            var latestWhatsAppSignup = {};
            var messageEl = document.querySelector('[data-connect-message]');

            function showConnectMessage(text, isError) {
                if (!messageEl) return;
                messageEl.textContent = text;
                messageEl.style.background = isError ? '#fff7ed' : '#f8fbff';
                messageEl.style.color = isError ? '#c2410c' : '#1d4ed8';
                messageEl.style.borderColor = isError ? '#fed7aa' : '#dbeafe';
                messageEl.classList.add('is-visible');
            }

            window.fbAsyncInit = function () {
                FB.init({
                    appId: <?php echo json_encode((string) ($workspaceConnectWhatsapp['app_id'] ?? '')); ?>,
                    autoLogAppEvents: true,
                    xfbml: true,
                    version: 'v24.0'
                });
            };

            window.addEventListener('message', function (event) {
                if (!String(event.origin || '').match(/facebook\.com$/)) return;
                try {
                    var data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                    if (data && data.type === 'WA_EMBEDDED_SIGNUP') {
                        latestWhatsAppSignup = data.data || {};
                    }
                } catch (error) {}
            });

            function postWhatsAppConnection(authResponse) {
                var payload = Object.assign({}, latestWhatsAppSignup || {}, authResponse || {});
                payload.csrf_token = <?php echo json_encode(\CRM\Security::getCsrfToken()); ?>;
                return fetch(<?php echo json_encode($workspaceConnectApiBase . '/api/whatsapp/embedded/callback.php'); ?>, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        if (!response.ok || !data.success) {
                            throw new Error(data.error || 'WhatsApp connection could not be completed.');
                        }
                        return data;
                    });
                });
            }

            document.querySelectorAll('[data-whatsapp-connect]').forEach(function (button) {
                button.addEventListener('click', function () {
                    showConnectMessage('Opening Meta signup...', false);
                    if (!window.FB || !<?php echo json_encode((string) ($workspaceConnectWhatsapp['config_id'] ?? '')); ?>) {
                        showConnectMessage('Platform Meta signup is not ready yet. Contact Super Admin.', true);
                        return;
                    }

                    FB.login(function (response) {
                        if (!response || !response.authResponse) {
                            showConnectMessage('WhatsApp signup was cancelled before it finished.', true);
                            return;
                        }
                        postWhatsAppConnection(response.authResponse)
                            .then(function (data) {
                                showConnectMessage(data.message || 'WhatsApp number connected.', false);
                                var status = document.querySelector('[data-whatsapp-status]');
                                if (status) {
                                    status.textContent = data.whatsapp && data.whatsapp.label ? data.whatsapp.label : 'Connected';
                                    status.className = 'workspace-connect-status connected';
                                }
                            })
                            .catch(function (error) {
                                showConnectMessage(error.message || 'WhatsApp connection needs attention.', true);
                            });
                    }, {
                        config_id: <?php echo json_encode((string) ($workspaceConnectWhatsapp['config_id'] ?? '')); ?>,
                        response_type: 'code',
                        override_default_response_type: true,
                        extras: { setup: {} }
                    });
                });
            });
        })();
        </script>
    <?php endif; ?>
</section>
