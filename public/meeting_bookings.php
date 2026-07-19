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
use CRM\Services\MeetingAvailabilityService;
use CRM\Services\MeetingBookingService;
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
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$calendarInstaller = new WorkspaceSkillInstallService();
if (!Authorization::isSuperAdmin($user) && !$calendarInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS));
    exit;
}
if (!Authorization::can('meeting_bookings.view', $user) && !Authorization::can('meeting_bookings.manage', $user)) {
    http_response_code(403);
    exit('Forbidden');
}

$canManage = Authorization::can('meeting_bookings.manage', $user);
$canManageAvailability = Authorization::can('meeting_availability.manage', $user) || Authorization::can('settings.calendar', $user) || Authorization::isSuperAdmin($user);
$service = new MeetingBookingService();
$availability = new MeetingAvailabilityService();
$profile = $availability->getDefaultProfile($workspaceId, (int) ($user['id'] ?? 0));
$bookings = $service->listBookings($workspaceId, [], 150);

function meetingBookingDisplayTime(array $booking): string
{
    try {
        $timezone = new DateTimeZone((string) ($booking['profile_timezone'] ?? date_default_timezone_get()));
        $date = new DateTimeImmutable((string) ($booking['scheduled_start'] ?? ''), $timezone);

        return $date->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return (string) ($booking['scheduled_start'] ?? '');
    }
}

$profileHostOptionsByProfile = [];
$profileIds = array_values(array_unique(array_filter(array_map(static fn(array $booking): int => (int) ($booking['profile_id'] ?? 0), $bookings))));
if (!empty($profile['id'])) {
    $profileIds[] = (int) $profile['id'];
    $profileIds = array_values(array_unique($profileIds));
}
foreach ($profileIds as $profileIdForHosts) {
    try {
        $profileHostOptionsByProfile[$profileIdForHosts] = array_values(array_filter(
            $availability->listRoundRobinHosts($workspaceId, $profileIdForHosts),
            static fn(array $host): bool => !empty($host['is_enabled'])
        ));
    } catch (Throwable $e) {
        $profileHostOptionsByProfile[$profileIdForHosts] = [];
    }
}
$statusCounts = ['pending' => 0, 'confirmed' => 0, 'today' => 0, 'failed_sync' => 0, 'needs_review' => 0];
foreach ($bookings as $booking) {
    $status = (string) ($booking['status'] ?? '');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
    try {
        $bookingToday = (new DateTimeImmutable('now', new DateTimeZone((string) ($booking['profile_timezone'] ?? date_default_timezone_get()))))->format('Y-m-d');
    } catch (Throwable $e) {
        $bookingToday = date('Y-m-d');
    }
    if (substr((string) ($booking['scheduled_start'] ?? ''), 0, 10) === $bookingToday) {
        $statusCounts['today']++;
    }
    if ($status === 'confirmed' && empty($booking['event_id'])) {
        $statusCounts['failed_sync']++;
    }
    if (!empty($booking['meeting_bot_run_id']) && in_array((string) ($booking['meeting_bot_status'] ?? ''), ['failed', 'partial'], true)) {
        $statusCounts['needs_review']++;
    }
}

$workspace = WorkspaceContext::currentWorkspace() ?: [];
$publicLink = publicUrl('meeting_schedule.php?' . http_build_query([
    'workspace' => (string) ($workspace['slug'] ?? ''),
    'profile' => (string) ($profile['slug'] ?? 'default'),
]));
$pageTitle = 'Meeting Bookings - ' . brandProductName();
$bookingsJson = json_encode(array_values($bookings), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]';
$profileHostsJson = json_encode($profileHostOptionsByProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/calendar-meetings.css">

<div class="cm-shell">
    <div class="cm-workspace">
        <header class="cm-header">
            <div>
                <h1>Meeting Bookings</h1>
                <p>Review booking requests, approve meetings, and watch meeting automation from one queue.</p>
            </div>
            <div class="cm-actions">
                <a class="cm-btn" href="<?php echo htmlspecialchars($publicLink); ?>" target="_blank" rel="noopener">Public booking page</a>
                <a class="cm-btn" href="calendar.php">Calendar</a>
                <a class="cm-btn" href="workspace_skills.php?module=calendar_meetings#setup">Setup</a>
            </div>
        </header>

        <div class="cm-grid-layout">
            <section class="cm-panel">
                <div class="cm-panel-header">
                    <div>
                        <h2>Booking Queue</h2>
                        <p class="cm-muted">Manual approval is the default for public requests.</p>
                    </div>
                    <div class="cm-chip-row">
                        <span class="cm-chip cm-chip-warning"><?php echo (int) $statusCounts['pending']; ?> pending</span>
                        <span class="cm-chip cm-chip-success"><?php echo (int) $statusCounts['confirmed']; ?> confirmed</span>
                        <span class="cm-chip"><?php echo (int) $statusCounts['today']; ?> today</span>
                        <span class="cm-chip <?php echo (int) $statusCounts['failed_sync'] > 0 ? 'cm-chip-danger' : ''; ?>"><?php echo (int) $statusCounts['failed_sync']; ?> failed sync</span>
                        <span class="cm-chip <?php echo (int) $statusCounts['needs_review'] > 0 ? 'cm-chip-warning' : ''; ?>"><?php echo (int) $statusCounts['needs_review']; ?> needs review</span>
                    </div>
                </div>
                <div style="overflow:auto;">
                    <table class="cm-table">
                        <thead>
                            <tr>
                                <th>Requester</th>
                                <th>Time</th>
                                <th>Host</th>
                                <th>Format</th>
                                <th>Status</th>
                                <th>Automation</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bookings === []): ?>
                                <tr><td colspan="7" class="cm-muted">No booking requests yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($bookings as $booking): ?>
                                <?php $status = (string) ($booking['status'] ?? 'pending'); ?>
                                <tr data-booking-row="<?php echo (int) $booking['id']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $booking['requester_name']); ?></strong><br>
                                        <span class="cm-muted"><?php echo htmlspecialchars((string) $booking['requester_email']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(meetingBookingDisplayTime($booking)); ?><br>
                                        <span class="cm-muted"><?php echo (int) $booking['duration_minutes']; ?> min · <?php echo htmlspecialchars((string) ($booking['profile_timezone'] ?? 'UTC')); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) ($booking['assigned_host_email'] ?? 'Not assigned')); ?></strong><br>
                                        <span class="cm-muted"><?php echo (string) ($booking['booking_mode'] ?? 'single_host') === 'round_robin' ? 'Round-robin' : 'Single host'; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $booking['meeting_format']))); ?></td>
                                    <td><span class="cm-chip <?php echo $status === 'pending' ? 'cm-chip-warning' : ($status === 'confirmed' ? 'cm-chip-success' : ''); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                    <td>
                                        <span class="cm-muted">
                                            Bot: <?php echo htmlspecialchars((string) ($booking['meeting_bot_status'] ?? 'not scheduled')); ?><br>
                                            Notes: <?php echo htmlspecialchars((string) ($booking['meeting_note_apply_status'] ?? 'not started')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="cm-row-actions">
                                            <button class="cm-btn" type="button" data-booking-detail="<?php echo (int) $booking['id']; ?>">Details</button>
                                            <?php if ($canManage && $status === 'pending'): ?>
                                                <button class="cm-btn cm-btn-primary" type="button" data-booking-action="approve" data-booking-id="<?php echo (int) $booking['id']; ?>">Approve</button>
                                                <button class="cm-btn" type="button" data-booking-action="decline" data-booking-id="<?php echo (int) $booking['id']; ?>">Decline</button>
                                            <?php endif; ?>
                                            <?php if ($canManage && in_array($status, ['pending', 'confirmed'], true)): ?>
                                                <button class="cm-btn cm-btn-danger" type="button" data-booking-action="cancel" data-booking-id="<?php echo (int) $booking['id']; ?>">Cancel</button>
                                            <?php endif; ?>
                                            <?php if ($canManage && $status === 'confirmed'): ?>
                                                <button class="cm-btn" type="button" data-booking-action="complete" data-booking-id="<?php echo (int) $booking['id']; ?>">Complete</button>
                                            <?php endif; ?>
                                            <?php if ($canManageAvailability && in_array($status, ['pending', 'confirmed'], true)): ?>
                                                <button class="cm-btn" type="button" data-block-booking="<?php echo (int) $booking['id']; ?>">Block this time</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="cm-panel">
                <div class="cm-panel-header"><h2>Booking Profile</h2></div>
                <div class="cm-agenda">
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Public link</span>
                        <a href="<?php echo htmlspecialchars($publicLink); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($publicLink); ?></a>
                    </div>
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Default duration</span>
                        <strong><?php echo (int) $profile['default_duration_minutes']; ?> minutes</strong>
                    </div>
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Timezone</span>
                        <strong><?php echo htmlspecialchars((string) $profile['timezone']); ?></strong>
                    </div>
                    <div class="cm-agenda-row">
                        <span class="cm-muted">Review queue</span>
                        <strong><?php echo (int) $statusCounts['needs_review']; ?> automation item(s) need review</strong>
                    </div>
                </div>
                <div class="cm-panel-header"><h2>Selected Booking</h2></div>
                <div class="cm-agenda">
                    <div class="cm-detail-panel" id="bookingDetailPanel">
                        <h3>No booking selected</h3>
                        <p class="cm-muted">Open a row to inspect requester context, event linkage, bot state, notes state, and the selected slot.</p>
                    </div>
                </div>
            </aside>
        </div>

        <div id="bookingActionAlert" style="margin-top:14px;" aria-live="polite"></div>
    </div>
</div>

<script>
(function () {
    var endpoint = <?php echo json_encode(apiUrl('meeting_booking/manage.php')); ?>;
    var settingsEndpoint = <?php echo json_encode(apiUrl('meeting_booking/settings.php')); ?>;
    var csrf = <?php echo json_encode(Security::getCsrfToken()); ?>;
    var bookings = <?php echo $bookingsJson; ?>;
    var profileHostsByProfile = <?php echo $profileHostsJson; ?>;
    var canManageBookings = <?php echo $canManage ? 'true' : 'false'; ?>;
    var profile = {
        id: <?php echo (int) ($profile['id'] ?? 0); ?>,
        timezone: <?php echo json_encode((string) ($profile['timezone'] ?? date_default_timezone_get())); ?>
    };
    var alertBox = document.getElementById('bookingActionAlert');
    var detailPanel = document.getElementById('bookingDetailPanel');

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function findBooking(id) {
        id = Number(id);
        return bookings.find(function (booking) { return Number(booking.id) === id; }) || null;
    }

    function renderReassignControl(booking) {
        if (!canManageBookings || String(booking.status || '') !== 'pending' || String(booking.booking_mode || 'single_host') !== 'round_robin') {
            return '';
        }
        var hosts = profileHostsByProfile[String(booking.profile_id)] || profileHostsByProfile[Number(booking.profile_id)] || [];
        if (!hosts.length) {
            return '<p class="cm-muted">No enabled hosts are available for reassignment.</p>';
        }
        var options = hosts.map(function (host) {
            var hostId = Number(host.user_id || host.id || 0);
            var selected = hostId === Number(booking.assigned_host_user_id || 0) ? ' selected' : '';
            var label = host.email || host.label || ('User #' + hostId);
            return '<option value="' + hostId + '"' + selected + '>' + escapeHtml(label) + '</option>';
        }).join('');

        return '<form class="cm-reassign-form" data-reassign-host="' + Number(booking.id || 0) + '">'
            + '<label class="cm-field">Reassign host<select name="host_user_id">' + options + '</select></label>'
            + '<button class="cm-btn" type="submit">Reassign</button>'
            + '</form>';
    }

    function renderBookingDetail(booking) {
        if (!booking || !detailPanel) {
            return;
        }
        detailPanel.innerHTML = '<h3>' + escapeHtml(booking.requester_name || 'Requester') + '</h3>'
            + '<dl class="cm-detail-list">'
            + '<div><dt>Email</dt><dd>' + escapeHtml(booking.requester_email || '') + '</dd></div>'
            + '<div><dt>Slot</dt><dd>' + escapeHtml((booking.scheduled_start || '') + ' - ' + (booking.scheduled_end || '')) + '</dd></div>'
            + '<div><dt>Profile</dt><dd>' + escapeHtml(booking.profile_title || booking.profile_slug || 'Booking profile') + '</dd></div>'
            + '<div><dt>Assigned host</dt><dd>' + escapeHtml(booking.assigned_host_email || 'Not assigned') + '</dd></div>'
            + '<div><dt>Inquiry</dt><dd>' + escapeHtml(booking.inquiry_type || 'General meeting') + '</dd></div>'
            + '<div><dt>Event</dt><dd>' + (booking.event_id ? ('#' + Number(booking.event_id) + ' ' + escapeHtml(booking.event_title || 'CRM event')) : 'Not linked yet') + '</dd></div>'
            + '<div><dt>Automation</dt><dd>Bot: ' + escapeHtml(booking.meeting_bot_status || 'not scheduled') + '<br>Notes: ' + escapeHtml(booking.meeting_note_apply_status || 'not started') + '</dd></div>'
            + '<div><dt>Description</dt><dd>' + escapeHtml(booking.inquiry_description || 'No preparation notes provided.') + '</dd></div>'
            + '</dl>'
            + renderReassignControl(booking);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-reassign-host]');
        if (!form) {
            return;
        }
        event.preventDefault();
        var bookingId = Number(form.getAttribute('data-reassign-host') || 0);
        var hostSelect = form.querySelector('select[name="host_user_id"]');
        var button = form.querySelector('button[type="submit"]');
        if (!bookingId || !hostSelect) {
            return;
        }
        if (button) {
            button.disabled = true;
        }
        alertBox.className = 'cm-alert';
        alertBox.textContent = 'Reassigning host...';
        fetch(endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({
                csrf_token: csrf,
                action: 'reassign_host',
                booking_id: bookingId,
                host_user_id: Number(hostSelect.value || 0)
            })
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || 'Reassignment failed.');
                }
                return payload;
            });
        }).then(function () {
            alertBox.className = 'cm-alert is-success';
            alertBox.textContent = 'Host reassigned.';
            window.setTimeout(function () { window.location.reload(); }, 700);
        }).catch(function (error) {
            if (button) {
                button.disabled = false;
            }
            alertBox.className = 'cm-alert is-error';
            alertBox.textContent = error.message || 'Reassignment failed.';
        });
    });

    document.addEventListener('click', function (event) {
        var detailButton = event.target.closest('[data-booking-detail]');
        if (detailButton) {
            renderBookingDetail(findBooking(detailButton.getAttribute('data-booking-detail')));
            return;
        }
        var blockButton = event.target.closest('[data-block-booking]');
        if (blockButton) {
            var booking = findBooking(blockButton.getAttribute('data-block-booking'));
            if (!booking) {
                return;
            }
            blockButton.disabled = true;
            alertBox.className = 'cm-alert';
            alertBox.textContent = 'Blocking selected time...';
            fetch(settingsEndpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({
                    csrf_token: csrf,
                    action: 'create_blocked_time',
                    profile_id: Number(booking.profile_id || 0),
                    blocked_time: {
                        profile_id: Number(booking.profile_id || 0),
                        block_type: 'custom',
                        timezone: String(booking.profile_timezone || profile.timezone),
                        start_time: String(booking.scheduled_start || '').replace(' ', 'T'),
                        end_time: String(booking.scheduled_end || '').replace(' ', 'T'),
                        reason: 'Blocked from booking #' + Number(booking.id || 0)
                    }
                })
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.error || 'Block failed.');
                    }
                    return payload;
                });
            }).then(function () {
                alertBox.className = 'cm-alert is-success';
                alertBox.textContent = 'Selected time is blocked for future availability.';
            }).catch(function (error) {
                alertBox.className = 'cm-alert is-error';
                alertBox.textContent = error.message || 'Block failed.';
            }).finally(function () {
                blockButton.disabled = false;
            });
            return;
        }
        var button = event.target.closest('[data-booking-action]');
        if (button) {
            var action = button.getAttribute('data-booking-action');
            var bookingId = button.getAttribute('data-booking-id');
            button.disabled = true;
            alertBox.className = 'cm-alert';
            alertBox.textContent = 'Updating booking...';
            fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({csrf_token: csrf, action: action, booking_id: bookingId})
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.error || 'Update failed.');
                    }
                    return payload;
                });
            }).then(function () {
                alertBox.className = 'cm-alert is-success';
                alertBox.textContent = 'Booking updated.';
                window.setTimeout(function () { window.location.reload(); }, 700);
            }).catch(function (error) {
                button.disabled = false;
                alertBox.className = 'cm-alert is-error';
                alertBox.textContent = error.message || 'Update failed.';
            });
        }
    });
    if (bookings.length) {
        renderBookingDetail(bookings[0]);
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
