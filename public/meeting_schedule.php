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

use CRM\Database;
use CRM\Services\CalendarShareService;
use CRM\Services\MeetingAvailabilityService;

Database::init(require __DIR__ . '/../config/database.php');

$workspaceSlug = (string) ($_GET['workspace'] ?? '');
$profileSlug = (string) ($_GET['profile'] ?? 'default');
$shareToken = trim((string) ($_GET['share'] ?? ''));
$availability = new MeetingAvailabilityService();
$calendarShareService = new CalendarShareService($availability);
$calendarShare = null;

if ($shareToken !== '') {
    $calendarShare = $calendarShareService->getPublicShareByToken($shareToken, true);
    $profile = $calendarShare ? $availability->getProfile((int) ($calendarShare['profile_id'] ?? 0)) : null;
    if ($profile && (empty($profile['public_enabled']) || (string) ($profile['status'] ?? '') !== 'active')) {
        $profile = null;
    }
} else {
    $profile = $availability->findPublicProfile($workspaceSlug, $profileSlug);
}
$isTeamBooking = $profile && (string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin';
$sharePrefill = $calendarShare ? $calendarShareService->publicPrefill($calendarShare) : [
    'name' => '',
    'email' => '',
    'phone' => '',
    'organization' => '',
];
$bookingDurations = $calendarShare
    ? [(int) ($calendarShare['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30)]
    : (array) ($profile['allowed_durations'] ?? []);
$bookingDefaultDuration = $calendarShare
    ? (int) ($calendarShare['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30)
    : (int) ($profile['default_duration_minutes'] ?? 30);
$shareDateFrom = $calendarShare ? (string) ($calendarShare['date_from'] ?? '') : '';
$shareDateTo = $calendarShare ? (string) ($calendarShare['date_to'] ?? '') : '';

if (!$profile) {
    http_response_code(404);
}

$pageTitle = ($profile ? (string) $profile['title'] : 'Meeting unavailable') . ' - ' . brandProductName();
$apiAvailability = apiUrl('meeting_booking/availability.php');
$apiRequest = apiUrl('meeting_booking/request.php');
$formStartedAt = time();
$calendarMeetingsCssUrl = assetUrl('css/calendar-meetings.css');
$calendarMeetingsCssPath = __DIR__ . '/assets/css/calendar-meetings.css';
if (is_file($calendarMeetingsCssPath)) {
    $calendarMeetingsCssUrl .= (strpos($calendarMeetingsCssUrl, '?') === false ? '?' : '&') . 'v=' . rawurlencode((string) @filemtime($calendarMeetingsCssPath));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/main.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($calendarMeetingsCssUrl); ?>">
</head>
<body class="cm-shell cm-public-booking-page">
    <main class="cm-workspace cm-public-booking-workspace">
        <?php if (!$profile): ?>
            <section class="cm-panel">
                <div class="cm-panel-header">
                    <div>
                        <h1>Meeting unavailable</h1>
                        <p>The booking profile you opened is not active.</p>
                    </div>
                </div>
            </section>
        <?php else: ?>
            <header class="cm-header cm-public-booking-header">
                <div>
                    <h1><?php echo htmlspecialchars((string) $profile['title']); ?></h1>
                    <p><?php echo htmlspecialchars((string) ($profile['description'] ?: 'Choose a time and send a meeting request for review.')); ?></p>
                </div>
                <div class="cm-public-booking-meta" aria-label="Booking details">
                    <span><?php echo htmlspecialchars((string) $profile['timezone']); ?></span>
                    <?php if ($calendarShare): ?><span>Private invitation</span><?php endif; ?>
                    <span><?php echo $isTeamBooking ? 'Team availability' : 'Requests reviewed before confirmation'; ?></span>
                    <?php if ($isTeamBooking): ?><span>Host shared after confirmation</span><?php endif; ?>
                </div>
            </header>

            <section class="cm-panel cm-public-booking-card" data-booking-wizard>
                <div class="cm-booking-progress">
                    <div class="cm-booking-stepper" aria-label="Booking steps">
                        <div class="cm-step is-active" data-step-label="1">Date</div>
                        <div class="cm-step" data-step-label="2">Details</div>
                        <div class="cm-step" data-step-label="3">Info</div>
                        <div class="cm-step" data-step-label="4">Confirm</div>
                    </div>
                </div>
                <div class="cm-booking-body">
                    <section class="cm-booking-step-panel" data-step-panel="1">
                        <div class="cm-booking-hero cm-public-picker-header">
                            <div>
                                <h2>Choose a date</h2>
                                <p class="cm-muted"><?php echo $isTeamBooking ? 'Team times appear after you select an open day.' : 'Available times appear after you select an open day.'; ?></p>
                            </div>
                            <div class="cm-booking-controls">
                                <label class="cm-field"><?php echo $calendarShare ? 'Invitation duration' : 'Duration'; ?>
                                    <select id="duration" <?php echo $calendarShare ? 'disabled' : ''; ?>>
                                        <?php foreach ($bookingDurations as $duration): ?>
                                            <option value="<?php echo (int) $duration; ?>" <?php echo (int) $duration === $bookingDefaultDuration ? 'selected' : ''; ?>>
                                                <?php echo (int) $duration; ?> minutes
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="cm-field">Your time zone
                                    <select id="timezone"></select>
                                    <span id="timezoneHint" class="cm-field-note"></span>
                                </label>
                            </div>
                        </div>
                        <div class="cm-public-scheduler" aria-label="Meeting calendar">
                            <div class="cm-public-monthbar">
                                <h2 id="calendarMonthTitle">Loading...</h2>
                                <div class="cm-month-nav-group">
                                    <button type="button" class="cm-btn cm-btn-icon cm-month-nav" id="prevMonth" aria-label="Previous month">
                                        <span aria-hidden="true">&lt;</span>
                                    </button>
                                    <button type="button" class="cm-btn cm-btn-icon cm-month-nav" id="nextMonth" aria-label="Next month">
                                        <span aria-hidden="true">&gt;</span>
                                    </button>
                                </div>
                            </div>
                            <div class="cm-public-calendar-grid" id="bookingCalendar" aria-live="polite"></div>
                        </div>
                        <div class="cm-time-panel" id="timeSlotsContainer" hidden>
                            <div class="cm-time-panel-header">
                                <div>
                                    <h2 id="selectedDateTitle">Available Times</h2>
                                    <p class="cm-muted" id="timeSlotsTimezoneLabel"></p>
                                </div>
                                <button type="button" class="cm-btn cm-btn-subtle" id="refreshSlots">Refresh</button>
                            </div>
                            <div class="cm-public-slots" id="slots"></div>
                            <p class="cm-muted" id="slotHint">Select a date to see available times.</p>
                        </div>
                        <div class="cm-actions cm-booking-actions cm-public-step-actions">
                            <button class="cm-btn cm-btn-primary" type="button" data-next disabled>Next</button>
                        </div>
                    </section>

                    <section class="cm-booking-step-panel" data-step-panel="2" hidden>
                        <div class="cm-form-grid">
                            <label class="cm-field">Inquiry type
                                <select id="inquiryType">
                                    <option>General meeting</option>
                                    <option>Sales consultation</option>
                                    <option>Support conversation</option>
                                    <option>Partnership discussion</option>
                                </select>
                            </label>
                            <label class="cm-field">Meeting format
                                <select id="meetingFormat">
                                    <?php foreach ((array) $profile['allowed_meeting_formats'] as $format): ?>
                                        <option value="<?php echo htmlspecialchars((string) $format); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $format))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="cm-field cm-field-full">What should we prepare for?
                                <textarea id="description" rows="5"></textarea>
                            </label>
                        </div>
                        <div class="cm-actions cm-booking-actions">
                            <button class="cm-btn" type="button" data-prev>Back</button>
                            <button class="cm-btn cm-btn-primary" type="button" data-next>Next</button>
                        </div>
                    </section>

                    <section class="cm-booking-step-panel" data-step-panel="3" hidden>
                        <div class="cm-form-grid">
                            <label class="cm-field">Name
                                <input type="text" id="name" autocomplete="name" value="<?php echo htmlspecialchars((string) ($sharePrefill['name'] ?? '')); ?>" required>
                            </label>
                            <label class="cm-field">Email
                                <input type="email" id="email" autocomplete="email" value="<?php echo htmlspecialchars((string) ($sharePrefill['email'] ?? '')); ?>" required>
                            </label>
                            <label class="cm-field">Phone
                                <input type="tel" id="phone" autocomplete="tel" value="<?php echo htmlspecialchars((string) ($sharePrefill['phone'] ?? '')); ?>">
                            </label>
                            <label class="cm-field">Organization
                                <input type="text" id="organization" autocomplete="organization" value="<?php echo htmlspecialchars((string) ($sharePrefill['organization'] ?? '')); ?>">
                            </label>
                            <label class="cm-field">Role
                                <input type="text" id="roleTitle">
                            </label>
                            <label class="cm-field" style="position:absolute;left:-9999px;">Website
                                <input type="text" id="bookingWebsite" tabindex="-1" autocomplete="off">
                            </label>
                        </div>
                        <div class="cm-actions cm-booking-actions">
                            <button class="cm-btn" type="button" data-prev>Back</button>
                            <button class="cm-btn cm-btn-primary" type="button" data-next>Next</button>
                        </div>
                    </section>

                    <section class="cm-booking-step-panel" data-step-panel="4" hidden>
                        <div class="cm-panel">
                            <div class="cm-panel-header"><h2>Review Request</h2></div>
                            <div class="cm-agenda" id="summary"></div>
                        </div>
                        <div class="cm-booking-alert" id="bookingAlert" role="status" aria-live="polite"></div>
                        <div class="cm-actions cm-booking-actions">
                            <button class="cm-btn" type="button" data-prev>Back</button>
                            <button class="cm-btn cm-btn-primary" type="button" id="submitBooking">Send request</button>
                        </div>
                    </section>
                </div>
            </section>
        <?php endif; ?>
    </main>

<?php if ($profile): ?>
<script>
(function () {
    var config = {
        workspace: <?php echo json_encode((string) ($profile['workspace_slug'] ?? $workspaceSlug)); ?>,
        profile: <?php echo json_encode((string) $profile['slug']); ?>,
        shareToken: <?php echo json_encode($shareToken); ?>,
        availabilityUrl: <?php echo json_encode($apiAvailability); ?>,
        requestUrl: <?php echo json_encode($apiRequest); ?>,
        shareDateFrom: <?php echo json_encode($shareDateFrom); ?>,
        shareDateTo: <?php echo json_encode($shareDateTo); ?>,
        formStartedAt: <?php echo (int) $formStartedAt; ?>
    };
    var initialVisibleDate = config.shareDateFrom
        ? new Date(config.shareDateFrom + 'T12:00:00')
        : new Date();
    var state = {
        step: 1,
        visibleMonth: new Date(initialVisibleDate.getFullYear(), initialVisibleDate.getMonth(), 1),
        selectedDate: null,
        selectedSlot: null,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || <?php echo json_encode((string) $profile['timezone']); ?>
    };
    var monthAvailabilityCache = {};
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var zones = ['Africa/Nairobi', 'UTC', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', state.timezone];
    var timezoneSelect = document.getElementById('timezone');
    Array.from(new Set(zones.filter(Boolean))).forEach(function (zone) {
        var option = document.createElement('option');
        option.value = zone;
        option.textContent = zone;
        option.selected = zone === state.timezone;
        timezoneSelect.appendChild(option);
    });
    updateTimezoneHint();

    function setStep(step) {
        state.step = Math.max(1, Math.min(4, step));
        document.querySelectorAll('[data-step-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-step-panel') !== String(state.step);
        });
        document.querySelectorAll('[data-step-label]').forEach(function (label) {
            var labelStep = Number(label.getAttribute('data-step-label'));
            label.classList.toggle('is-active', labelStep === state.step);
            label.classList.toggle('is-complete', labelStep < state.step);
        });
        renderSummary();
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function dateKey(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function zonedDateKey(value) {
        var parts = new Intl.DateTimeFormat('en-US', {
            timeZone: state.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(new Date(value)).reduce(function (carry, part) {
            carry[part.type] = part.value;
            return carry;
        }, {});
        return parts.year + '-' + parts.month + '-' + parts.day;
    }

    function formatDateLabel(dateString, options) {
        return new Intl.DateTimeFormat([], Object.assign({
            timeZone: state.timezone,
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        }, options || {})).format(new Date(dateString + 'T12:00:00'));
    }

    function formatSlotTime(slot) {
        return new Intl.DateTimeFormat([], {
            timeZone: state.timezone,
            hour: 'numeric',
            minute: '2-digit'
        }).format(new Date(slot.starts_at_iso));
    }

    function monthBounds(date) {
        var start = new Date(date.getFullYear(), date.getMonth(), 1);
        var end = new Date(date.getFullYear(), date.getMonth() + 1, 0);
        return { start: dateKey(start), end: dateKey(end) };
    }

    function cacheKey() {
        return state.visibleMonth.getFullYear() + '-' + pad(state.visibleMonth.getMonth() + 1)
            + '|' + document.getElementById('duration').value
            + '|' + state.timezone;
    }

    function getFirstStepNextButton() {
        return document.querySelector('[data-step-panel="1"] [data-next]');
    }

    function clearSelectedTime() {
        state.selectedSlot = null;
        getFirstStepNextButton().disabled = true;
        document.querySelectorAll('.cm-slot').forEach(function (el) {
            el.classList.remove('is-selected');
            el.setAttribute('aria-pressed', 'false');
        });
    }

    function clearSelectedDateAndTime() {
        state.selectedDate = null;
        clearSelectedTime();
        document.getElementById('timeSlotsContainer').hidden = true;
        document.getElementById('slots').innerHTML = '';
        document.getElementById('slotHint').textContent = 'Select a date to see available times.';
    }

    function updateTimezoneHint() {
        var detected = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        var hint = document.getElementById('timezoneHint');
        if (!hint) {
            return;
        }
        hint.textContent = detected === state.timezone
            ? 'Detected automatically from your browser.'
            : 'Browser timezone: ' + detected + '.';
    }

    function fetchAvailability(from, to) {
        var params = new URLSearchParams({
            workspace: config.workspace,
            profile: config.profile,
            share: config.shareToken,
            timezone: state.timezone,
            duration: document.getElementById('duration').value,
            from: from,
            to: to
        });
        return fetch(config.availabilityUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload.success) {
                    throw new Error(payload.error || 'Could not load availability.');
                }
                return payload.slots || [];
            });
    }

    function buildAvailability(slots) {
        var availableDates = {};
        var slotsByDate = {};
        var reasonByDate = {};
        slots.forEach(function (slot) {
            var key = zonedDateKey(slot.starts_at_iso);
            if (!slot.available) {
                if (!reasonByDate[key]) {
                    reasonByDate[key] = slot.unavailable_reason || 'not_available';
                }
                return;
            }
            availableDates[key] = true;
            if (!slotsByDate[key]) {
                slotsByDate[key] = [];
            }
            slotsByDate[key].push(slot);
        });
        Object.keys(slotsByDate).forEach(function (key) {
            slotsByDate[key].sort(function (a, b) {
                return String(a.starts_at_iso).localeCompare(String(b.starts_at_iso));
            });
        });
        return { availableDates: availableDates, slotsByDate: slotsByDate, reasonByDate: reasonByDate };
    }

    function renderCalendar(availability, isLoading) {
        var calendar = document.getElementById('bookingCalendar');
        var title = document.getElementById('calendarMonthTitle');
        var todayKey = zonedDateKey(new Date().toISOString());
        var year = state.visibleMonth.getFullYear();
        var month = state.visibleMonth.getMonth();
        var firstDay = new Date(year, month, 1).getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        title.textContent = monthNames[month] + ' ' + year;
        calendar.innerHTML = '';
        dayNames.forEach(function (dayName) {
            var header = document.createElement('div');
            header.className = 'cm-public-calendar-head';
            header.textContent = dayName;
            calendar.appendChild(header);
        });
        for (var blank = 0; blank < firstDay; blank++) {
            var blankCell = document.createElement('div');
            blankCell.className = 'cm-public-day is-empty';
            blankCell.setAttribute('aria-hidden', 'true');
            calendar.appendChild(blankCell);
        }
        for (var day = 1; day <= daysInMonth; day++) {
            var date = new Date(year, month, day);
            var key = dateKey(date);
            var available = Boolean(availability && availability.availableDates[key]);
            var unavailableReason = availability && availability.reasonByDate ? availability.reasonByDate[key] : '';
            var isPast = key < todayKey;
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cm-public-day';
            button.dataset.date = key;
            button.innerHTML = '<span class="cm-public-day-number">' + day + '</span>';
            if (key === todayKey) {
                button.classList.add('is-today');
                button.setAttribute('aria-current', 'date');
            }
            if (state.selectedDate === key) {
                button.classList.add('is-selected');
                button.setAttribute('aria-pressed', 'true');
            } else {
                button.setAttribute('aria-pressed', 'false');
            }
            if (isLoading) {
                button.classList.add('is-loading');
                button.disabled = true;
            } else if (!available || isPast) {
                button.classList.add('is-unavailable');
                button.disabled = true;
                button.dataset.unavailableReason = isPast ? 'outside_booking_window' : unavailableReason;
                button.insertAdjacentHTML('beforeend', '<span class="cm-public-day-note">Not available</span>');
            } else {
                button.classList.add('is-available');
                button.setAttribute('aria-label', 'Select ' + formatDateLabel(key, { weekday: 'long', month: 'long' }));
                button.addEventListener('click', function () {
                    selectDate(this.dataset.date);
                });
            }
            calendar.appendChild(button);
        }
    }

    function loadMonthAvailability() {
        clearSelectedDateAndTime();
        renderCalendar(null, true);
        var key = cacheKey();
        if (monthAvailabilityCache[key]) {
            renderCalendar(monthAvailabilityCache[key], false);
            return Promise.resolve(monthAvailabilityCache[key]);
        }
        var bounds = monthBounds(state.visibleMonth);
        return fetchAvailability(bounds.start, bounds.end)
            .then(function (slots) {
                var availability = buildAvailability(slots);
                monthAvailabilityCache[key] = availability;
                renderCalendar(availability, false);
                return availability;
            })
            .catch(function (error) {
                var calendar = document.getElementById('bookingCalendar');
                calendar.innerHTML = '<div class="cm-alert is-error cm-field-full cm-public-retry-state"><strong>Availability could not load.</strong><span>' + escapeHtml(error.message || 'Please try again.') + '</span><button type="button" class="cm-btn cm-btn-subtle" id="retryAvailability">Retry</button></div>';
                var retry = document.getElementById('retryAvailability');
                if (retry) {
                    retry.addEventListener('click', loadMonthAvailability);
                }
            });
    }

    function selectDate(date) {
        var availability = monthAvailabilityCache[cacheKey()];
        if (!availability || !availability.availableDates[date]) {
            return;
        }
        state.selectedDate = date;
        clearSelectedTime();
        renderCalendar(availability, false);
        showTimeSlots(date, availability.slotsByDate[date] || []);
    }

    function showTimeSlots(date, slots) {
        var container = document.getElementById('timeSlotsContainer');
        var slotsEl = document.getElementById('slots');
        var hint = document.getElementById('slotHint');
        container.hidden = false;
        document.getElementById('selectedDateTitle').textContent = 'Available Times';
        document.getElementById('timeSlotsTimezoneLabel').textContent = formatDateLabel(date, { weekday: 'long', month: 'long' }) + ' - times shown in ' + state.timezone + '.';
        slotsEl.innerHTML = '';
        window.setTimeout(function () {
            container.scrollIntoView({ block: 'nearest' });
        }, 0);
        if (!slots.length) {
            hint.textContent = 'No available times for this date.';
            return;
        }
        hint.textContent = slots.length + ' available time' + (slots.length === 1 ? '' : 's') + '.';
        slots.forEach(function (slot) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cm-slot cm-public-slot';
            button.textContent = formatSlotTime(slot);
            button.setAttribute('aria-label', 'Select ' + formatSlotTime(slot) + ' on ' + formatDateLabel(date, { weekday: 'long', month: 'long' }));
            button.setAttribute('aria-pressed', 'false');
            button.addEventListener('click', function () {
                state.selectedSlot = slot;
                document.querySelectorAll('.cm-slot').forEach(function (el) {
                    el.classList.remove('is-selected');
                    el.setAttribute('aria-pressed', 'false');
                });
                button.classList.add('is-selected');
                button.setAttribute('aria-pressed', 'true');
                getFirstStepNextButton().disabled = false;
            });
            slotsEl.appendChild(button);
        });
    }

    function refreshSelectedDate() {
        state.selectedSlot = null;
        getFirstStepNextButton().disabled = true;
        if (!state.selectedDate) {
            loadMonthAvailability();
            return;
        }
        var selectedDate = state.selectedDate;
        var hint = document.getElementById('slotHint');
        hint.textContent = 'Loading available times...';
        fetchAvailability(selectedDate, selectedDate)
            .then(function (slots) {
                var availability = buildAvailability(slots);
                showTimeSlots(selectedDate, availability.slotsByDate[selectedDate] || []);
            })
            .catch(function (error) {
                hint.textContent = error.message || 'Could not load availability.';
            });
    }

    function renderSummary() {
        if (state.step !== 4 || !state.selectedSlot) {
            return;
        }
        var summary = document.getElementById('summary');
        summary.innerHTML = '';
        [
            ['Time', formatDateLabel(state.selectedDate || zonedDateKey(state.selectedSlot.starts_at_iso), { weekday: 'long', month: 'long' }) + ' at ' + formatSlotTime(state.selectedSlot) + ' (' + state.timezone + ')'],
            ['Duration', document.getElementById('duration').value + ' minutes'],
            ['Format', document.getElementById('meetingFormat').selectedOptions[0].textContent],
            ['Inquiry', document.getElementById('inquiryType').value],
            ['Requester', document.getElementById('name').value + ' <' + document.getElementById('email').value + '>']
        ].forEach(function (row) {
            var item = document.createElement('div');
            item.className = 'cm-agenda-row';
            item.innerHTML = '<span class="cm-muted">' + row[0] + '</span><strong>' + row[1].replace(/[<>&]/g, function (c) { return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) + '</strong>';
            summary.appendChild(item);
        });
    }

    document.querySelectorAll('[data-next]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (state.step === 3 && (!document.getElementById('name').value.trim() || !document.getElementById('email').value.trim())) {
                document.getElementById('email').focus();
                return;
            }
            setStep(state.step + 1);
        });
    });
    document.querySelectorAll('[data-prev]').forEach(function (button) {
        button.addEventListener('click', function () { setStep(state.step - 1); });
    });
    document.getElementById('duration').addEventListener('change', function () {
        loadMonthAvailability();
    });
    timezoneSelect.addEventListener('change', function () {
        state.timezone = timezoneSelect.value;
        updateTimezoneHint();
        loadMonthAvailability();
    });
    document.getElementById('prevMonth').addEventListener('click', function () {
        state.visibleMonth = new Date(state.visibleMonth.getFullYear(), state.visibleMonth.getMonth() - 1, 1);
        loadMonthAvailability();
    });
    document.getElementById('nextMonth').addEventListener('click', function () {
        state.visibleMonth = new Date(state.visibleMonth.getFullYear(), state.visibleMonth.getMonth() + 1, 1);
        loadMonthAvailability();
    });
    document.getElementById('refreshSlots').addEventListener('click', refreshSelectedDate);
    function escapeHtml(value) {
        return String(value).replace(/[<>&]/g, function (c) { return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; });
    }
    document.getElementById('submitBooking').addEventListener('click', function () {
        var alert = document.getElementById('bookingAlert');
        alert.className = 'cm-alert';
        alert.textContent = 'Sending request...';
        fetch(config.requestUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                workspace: config.workspace,
                profile: config.profile,
                calendar_share_token: config.shareToken,
                starts_at: state.selectedSlot ? state.selectedSlot.starts_at : '',
                timezone: timezoneSelect.value,
                duration_minutes: Number(document.getElementById('duration').value),
                meeting_format: document.getElementById('meetingFormat').value,
                inquiry_type: document.getElementById('inquiryType').value,
                inquiry_description: document.getElementById('description').value,
                requester_name: document.getElementById('name').value,
                requester_email: document.getElementById('email').value,
                requester_phone: document.getElementById('phone').value,
                requester_organization: document.getElementById('organization').value,
                requester_role: document.getElementById('roleTitle').value,
                booking_website: document.getElementById('bookingWebsite').value,
                form_started_at: config.formStartedAt
            })
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || 'Booking failed.');
                }
                return payload;
            });
        }).then(function () {
            alert.className = 'cm-alert is-success';
            alert.textContent = 'Request sent. You will receive confirmation after review.';
            document.getElementById('submitBooking').disabled = true;
        }).catch(function (error) {
            alert.className = 'cm-alert is-error';
            alert.textContent = error.message || 'Booking failed.';
        });
    });
    loadMonthAvailability();
}());
</script>
<?php endif; ?>
</body>
</html>
