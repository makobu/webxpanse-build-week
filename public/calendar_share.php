<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
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
use CRM\Modules\Contacts;
use CRM\Services\CalendarShareService;
use CRM\Services\MeetingAvailabilityService;
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
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$calendarInstaller = new WorkspaceSkillInstallService();
if (!Authorization::isSuperAdmin($user) && !$calendarInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS));
    exit;
}

$canUseCalendarShare = Authorization::isSuperAdmin($user)
    || Authorization::can('meeting_bookings.view', $user)
    || Authorization::can('meeting_bookings.manage', $user)
    || Authorization::can('meeting_availability.manage', $user)
    || Authorization::can('settings.calendar', $user);
if (!$canUseCalendarShare) {
    http_response_code(403);
    exit('Forbidden');
}

$canSendCalendarShare = Authorization::isSuperAdmin($user)
    || Authorization::can('meeting_bookings.manage', $user)
    || Authorization::can('meeting_availability.manage', $user)
    || Authorization::can('settings.calendar', $user);

$contactsModule = new Contacts();
$calendarShareService = new CalendarShareService();
$availabilityService = new MeetingAvailabilityService();
$purposeOptions = $calendarShareService->purposeOptions();
$selectedContactId = (int) ($_GET['contact_id'] ?? 0);
$selectedDealId = (int) ($_GET['deal_id'] ?? 0);
$selectedDeal = null;
if ($selectedDealId > 0) {
    $selectedDeal = Database::queryOne(
        "SELECT id, title, contact_id
         FROM deals
         WHERE workspace_id = ?
           AND id = ?
         LIMIT 1",
        [$workspaceId, $selectedDealId]
    );
    if ($selectedDeal && $selectedContactId <= 0) {
        $selectedContactId = (int) ($selectedDeal['contact_id'] ?? 0);
    }
}

$selectedContact = $selectedContactId > 0 ? $contactsModule->getById($selectedContactId) : null;
if ($selectedContact && !$contactsModule->isVisibleToUser($selectedContact, $userId, Authorization::can('contacts.view_all', $user))) {
    $selectedContact = null;
    $selectedContactId = 0;
}

$contacts = $contactsModule->getSelectableContactsForChannel('email');
if ($selectedContact && !in_array($selectedContactId, array_map(static fn(array $contact): int => (int) ($contact['id'] ?? 0), $contacts), true)) {
    array_unshift($contacts, $selectedContact);
}

$defaultProfile = $availabilityService->getDefaultProfile($workspaceId, $userId);
$profiles = Database::query(
    "SELECT id, title, slug, timezone, default_duration_minutes
     FROM meeting_booking_profiles
     WHERE workspace_id = ?
       AND status <> 'archived'
     ORDER BY public_enabled DESC, id ASC",
    [$workspaceId]
);
if ($profiles === [] && $defaultProfile !== []) {
    $profiles[] = $defaultProfile;
}

$defaultDateFrom = date('Y-m-d');
$defaultDateTo = date('Y-m-d', strtotime('+14 days'));
$pageTitle = 'Share Calendar - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/calendar-meetings.css')); ?>">

<div class="page-premium cm-shell">
    <div class="container cm-workspace">
        <header class="cm-header">
            <div>
                <h1>Share Calendar</h1>
                <p>Create a private availability link, review the message, then send it to the client.</p>
            </div>
            <div class="cm-actions">
                <a class="cm-btn" href="meeting_bookings.php">Bookings</a>
                <a class="cm-btn" href="calendar.php">Calendar</a>
            </div>
        </header>

        <div class="cm-grid-layout calendar-share-layout">
            <section class="cm-panel">
                <div class="cm-panel-header">
                    <div>
                        <h2>Share setup</h2>
                        <p class="cm-muted">Only free/busy availability is shared. Calendar titles and notes stay private.</p>
                    </div>
                </div>

                <form id="calendarShareForm" class="cm-form-grid calendar-share-form">
                    <input type="hidden" id="calendarShareId" value="">
                    <input type="hidden" id="calendarDealId" value="<?php echo (int) ($selectedDeal['id'] ?? 0); ?>">

                    <label class="cm-field cm-field-full">Client
                        <select id="calendarContactId" required>
                            <option value="">Select a contact...</option>
                            <?php foreach ($contacts as $contact): ?>
                                <?php
                                $contactId = (int) ($contact['id'] ?? 0);
                                $label = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
                                $email = (string) ($contact['email'] ?? '');
                                $label = trim($label . ($email !== '' ? ' (' . $email . ')' : ''));
                                if ($label === '') {
                                    $label = 'Contact #' . $contactId;
                                }
                                ?>
                                <option value="<?php echo $contactId; ?>" <?php echo $contactId === $selectedContactId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ($selectedDeal): ?>
                        <div class="cm-field cm-field-full">
                            <span>Deal context</span>
                            <strong><?php echo htmlspecialchars((string) ($selectedDeal['title'] ?? 'Selected deal')); ?></strong>
                        </div>
                    <?php endif; ?>

                    <label class="cm-field">Booking profile
                        <select id="calendarProfileId">
                            <?php foreach ($profiles as $profile): ?>
                                <option
                                    value="<?php echo (int) ($profile['id'] ?? 0); ?>"
                                    data-duration="<?php echo (int) ($profile['default_duration_minutes'] ?? 30); ?>"
                                    <?php echo (int) ($profile['id'] ?? 0) === (int) ($defaultProfile['id'] ?? 0) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars((string) ($profile['title'] ?? 'Book a meeting')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="cm-field">Purpose
                        <select id="calendarPurpose">
                            <?php foreach ($purposeOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="cm-field cm-field-full" id="customPurposeWrap" hidden>Custom purpose
                        <input type="text" id="calendarCustomPurpose" maxlength="255" placeholder="e.g., project handover call">
                    </label>

                    <label class="cm-field">Duration
                        <input type="number" min="5" max="240" step="5" id="calendarDuration" value="<?php echo (int) ($defaultProfile['default_duration_minutes'] ?? 30); ?>">
                    </label>

                    <label class="cm-field">Suggested slots
                        <input type="number" min="1" max="5" id="calendarSuggestedCount" value="3">
                    </label>

                    <label class="cm-field">From
                        <input type="date" id="calendarDateFrom" value="<?php echo htmlspecialchars($defaultDateFrom); ?>">
                    </label>

                    <label class="cm-field">To
                        <input type="date" id="calendarDateTo" value="<?php echo htmlspecialchars($defaultDateTo); ?>">
                    </label>

                    <label class="cm-field">Expires in days
                        <input type="number" min="1" max="120" id="calendarExpiresDays" value="30">
                    </label>

                    <label class="cm-field calendar-share-ai-toggle">
                        <span>Use AI draft</span>
                        <input type="checkbox" id="calendarUseAi" checked>
                    </label>

                    <div class="cm-actions cm-field-full">
                        <button type="button" class="cm-btn cm-btn-primary" id="prepareCalendarShare">Prepare share</button>
                        <button type="button" class="cm-btn" id="copyCalendarLink" disabled>Copy link</button>
                        <button type="button" class="cm-btn" id="copyCalendarMessage" disabled>Copy message</button>
                    </div>
                </form>
            </section>

            <aside class="cm-panel">
                <div class="cm-panel-header"><h2>Share details</h2></div>
                <div class="cm-agenda">
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Private booking link</span>
                        <a href="#" id="calendarShareLink" target="_blank" rel="noopener">Not prepared yet</a>
                    </div>
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Suggested times</span>
                        <div id="calendarSuggestedSlots" class="calendar-share-slots">Prepare a share to load slots.</div>
                    </div>
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Status</span>
                        <strong id="calendarShareStatus">Draft</strong>
                    </div>
                </div>
            </aside>
        </div>

        <section class="cm-panel calendar-share-review">
            <div class="cm-panel-header">
                <div>
                    <h2>Review email</h2>
                    <p class="cm-muted">The message is never sent until you review it and click send.</p>
                </div>
            </div>
            <div class="cm-form-grid">
                <label class="cm-field cm-field-full">Subject
                    <input type="text" id="calendarEmailSubject" value="">
                </label>
                <label class="cm-field cm-field-full">Message
                    <textarea id="calendarEmailBody" rows="11"></textarea>
                </label>
            </div>
            <div class="cm-actions">
                <button type="button" class="cm-btn cm-btn-primary" id="sendCalendarShareEmail" <?php echo $canSendCalendarShare ? '' : 'disabled'; ?>>Send email</button>
                <span class="cm-muted" id="calendarSharePermissionNote"><?php echo $canSendCalendarShare ? '' : 'You can prepare and copy calendar shares, but do not have permission to send them.'; ?></span>
            </div>
            <div id="calendarShareAlert" class="cm-alert calendar-share-alert" aria-live="polite" hidden></div>
        </section>
    </div>
</div>

<script>
(function () {
    var csrf = <?php echo json_encode(Security::getCsrfToken()); ?>;
    var endpoints = {
        create: <?php echo json_encode(apiUrl('calendar_share/create.php')); ?>,
        draft: <?php echo json_encode(apiUrl('calendar_share/draft.php')); ?>,
        send: <?php echo json_encode(apiUrl('calendar_share/send_email.php')); ?>,
        copy: <?php echo json_encode(apiUrl('calendar_share/copy_log.php')); ?>
    };
    var share = null;
    var draft = null;
    var alertBox = document.getElementById('calendarShareAlert');
    var prepareButton = document.getElementById('prepareCalendarShare');
    var copyLinkButton = document.getElementById('copyCalendarLink');
    var copyMessageButton = document.getElementById('copyCalendarMessage');
    var sendButton = document.getElementById('sendCalendarShareEmail');

    function setAlert(message, kind) {
        alertBox.hidden = message === '';
        alertBox.className = 'cm-alert calendar-share-alert' + (kind ? ' is-' + kind : '');
        alertBox.textContent = message;
    }

    function payload() {
        return {
            csrf_token: csrf,
            share_id: document.getElementById('calendarShareId').value || null,
            contact_id: Number(document.getElementById('calendarContactId').value || 0),
            deal_id: Number(document.getElementById('calendarDealId').value || 0),
            profile_id: Number(document.getElementById('calendarProfileId').value || 0),
            meeting_purpose: document.getElementById('calendarPurpose').value,
            custom_purpose: document.getElementById('calendarCustomPurpose').value,
            duration_minutes: Number(document.getElementById('calendarDuration').value || 30),
            suggested_slot_count: Number(document.getElementById('calendarSuggestedCount').value || 3),
            date_from: document.getElementById('calendarDateFrom').value,
            date_to: document.getElementById('calendarDateTo').value,
            expires_in_days: Number(document.getElementById('calendarExpiresDays').value || 30)
        };
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.success) {
                    throw new Error(json.error || 'Request failed.');
                }
                return json;
            });
        });
    }

    function formatSlot(slot) {
        var zone = slot.timezone || '';
        try {
            return new Intl.DateTimeFormat([], {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                timeZone: zone || undefined
            }).format(new Date(slot.starts_at_iso || slot.starts_at)) + (zone ? ' ' + zone : '');
        } catch (e) {
            return slot.starts_at || 'Available slot';
        }
    }

    function renderShare(nextShare) {
        share = nextShare;
        document.getElementById('calendarShareId').value = share && share.id ? String(share.id) : '';
        var link = document.getElementById('calendarShareLink');
        if (share && share.booking_url) {
            link.href = share.booking_url;
            link.textContent = share.booking_url;
            copyLinkButton.disabled = false;
            copyMessageButton.disabled = false;
        }
        document.getElementById('calendarShareStatus').textContent = share && share.status ? share.status : 'Draft';
        var slotBox = document.getElementById('calendarSuggestedSlots');
        var slots = share && Array.isArray(share.suggested_slots) ? share.suggested_slots : [];
        if (!slots.length) {
            slotBox.textContent = 'No suggested slots found in this window. The client can still choose from the booking page.';
        } else {
            slotBox.innerHTML = slots.map(function (slot) {
                return '<div class="calendar-share-slot">' + escapeHtml(formatSlot(slot)) + '</div>';
            }).join('');
        }
    }

    function renderDraft(nextDraft) {
        draft = nextDraft;
        document.getElementById('calendarEmailSubject').value = draft.subject || '';
        document.getElementById('calendarEmailBody').value = draft.body_text || '';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    async function prepareShare() {
        setAlert('Preparing calendar share...', '');
        prepareButton.disabled = true;
        try {
            var created = await postJson(endpoints.create, payload());
            renderShare(created.share);
            var drafted = await postJson(endpoints.draft, {
                csrf_token: csrf,
                share_id: created.share.id,
                use_ai: document.getElementById('calendarUseAi').checked
            });
            renderDraft(drafted.draft);
            setAlert('Calendar share is ready. Review the email before sending.', 'success');
        } catch (error) {
            setAlert(error.message || 'Unable to prepare calendar share.', 'error');
        } finally {
            prepareButton.disabled = false;
        }
    }

    async function copyText(value, type) {
        if (!share || !share.id || !value) {
            await prepareShare();
        }
        if (!share || !share.id) {
            return;
        }
        var text = type === 'message'
            ? (document.getElementById('calendarEmailBody').value.trim() + '\n\n' + share.booking_url).trim()
            : share.booking_url;
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }
        await postJson(endpoints.copy, {csrf_token: csrf, share_id: share.id, copy_type: type});
        setAlert(type === 'message' ? 'Message copied.' : 'Link copied.', 'success');
    }

    async function sendEmail() {
        if (!share || !share.id) {
            await prepareShare();
        }
        if (!share || !share.id) {
            return;
        }
        sendButton.disabled = true;
        setAlert('Sending calendar share email...', '');
        try {
            var result = await postJson(endpoints.send, {
                csrf_token: csrf,
                share_id: share.id,
                subject: document.getElementById('calendarEmailSubject').value,
                body_text: document.getElementById('calendarEmailBody').value
            });
            if (result.share) {
                renderShare(result.share);
            }
            setAlert('Calendar share email sent.', 'success');
        } catch (error) {
            setAlert(error.message || 'Unable to send calendar share email.', 'error');
        } finally {
            sendButton.disabled = false;
        }
    }

    document.getElementById('calendarPurpose').addEventListener('change', function () {
        document.getElementById('customPurposeWrap').hidden = this.value !== 'custom';
    });
    document.getElementById('calendarProfileId').addEventListener('change', function () {
        var duration = this.selectedOptions[0] ? this.selectedOptions[0].getAttribute('data-duration') : '';
        if (duration) {
            document.getElementById('calendarDuration').value = duration;
        }
    });
    prepareButton.addEventListener('click', prepareShare);
    copyLinkButton.addEventListener('click', function () { copyText(share && share.booking_url, 'link'); });
    copyMessageButton.addEventListener('click', function () { copyText(document.getElementById('calendarEmailBody').value, 'message'); });
    if (sendButton) {
        sendButton.addEventListener('click', sendEmail);
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
