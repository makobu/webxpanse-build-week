<?php
/**
 * Calendar View Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\Events;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$eventsModule = new Events();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$calendarInstaller = new WorkspaceSkillInstallService();
if ($calendarInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) === false
    && (new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS));
    exit;
}
if (!Authorization::isSuperAdmin($user) && !$calendarInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS));
    exit;
}
Session::closeWrite();
$canViewAllEvents = Authorization::can('events.view_all', $user);

// Get date range (default to current month)
$requestedView = (string) ($_GET['view'] ?? 'month');
$view = in_array($requestedView, ['month', 'week', 'day', 'agenda'], true) ? $requestedView : 'month';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('m'));
$day = (int) ($_GET['day'] ?? date('d'));

// Calculate date range based on view
if ($view === 'month') {
    $startDate = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
    $endDate = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
} elseif ($view === 'week') {
    $startOfWeek = strtotime('monday this week', mktime(0, 0, 0, $month, $day, $year));
    $startDate = date('Y-m-d', $startOfWeek);
    $endDate = date('Y-m-d', strtotime('+6 days', $startOfWeek));
} elseif ($view === 'day') {
    $startDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
    $endDate = $startDate;
} else {
    $startDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
    $endDate = date('Y-m-d', strtotime('+30 days', strtotime($startDate)));
}

// Get events for date range
$hasAssignedToParam = array_key_exists('assigned_to', $_GET);
$selectedAssignedTo = null;
if (!$hasAssignedToParam) {
    $selectedAssignedTo = $userId > 0 ? $userId : null;
} elseif ($canViewAllEvents && ($_GET['assigned_to'] ?? '') === '') {
    $selectedAssignedTo = null;
} elseif ($canViewAllEvents && ctype_digit((string) ($_GET['assigned_to'] ?? ''))) {
    $selectedAssignedTo = (int) $_GET['assigned_to'];
} else {
    $selectedAssignedTo = $userId > 0 ? $userId : null;
}

$selectedEventType = (string) ($_GET['event_type'] ?? '');
$filters = ['assigned_to' => $selectedAssignedTo];
if ($selectedEventType !== '') {
    $filters['event_type'] = $selectedEventType;
}

$events = $eventsModule->getByDateRangeForUser($startDate, $endDate, $filters, $user);

// Group events by date
$eventsByDate = [];
foreach ($events as $event) {
    $coveredDates = $eventsModule->getDatesCoveredByEvent($event, $startDate, $endDate);
    $spansMultipleDays = $eventsModule->eventSpansMultipleDays($event);
    foreach ($coveredDates as $date) {
        if (!isset($eventsByDate[$date])) {
            $eventsByDate[$date] = [];
        }
        $dayEvent = $event;
        $dayEvent['calendar_bucket_date'] = $date;
        $dayEvent['calendar_spans_multiple_days'] = $spansMultipleDays;
        $dayEvent['calendar_force_all_day'] = !empty($event['is_all_day']) || $spansMultipleDays;
        $eventsByDate[$date][] = $dayEvent;
    }
}

$splitAllDayEvents = static function (array $dayEvents): array {
    $allDay = [];
    $timed = [];
    foreach ($dayEvents as $event) {
        if (!empty($event['is_all_day']) || !empty($event['calendar_force_all_day'])) {
            $allDay[] = $event;
        } else {
            $timed[] = $event;
        }
    }

    return [$allDay, $timed];
};

$eventTypeColors = [
    'meeting' => '#10b981',
    'call' => '#f59e0b',
    'email' => '#3b82f6',
    'task' => '#6b7280',
    'other' => '#ef4444',
];

// Get users for filter
$users = $canViewAllEvents
    ? Database::query(
        "SELECT u.id, u.email
         FROM users u
         JOIN workspace_memberships wm ON wm.user_id = u.id
         WHERE wm.workspace_id = ?
           AND wm.membership_status = 'active'
         ORDER BY u.email ASC",
        [$workspaceId]
    )
    : [[
        'id' => $userId,
        'email' => (string) ($user['email'] ?? 'My calendar'),
    ]];

$queryFilters = ['assigned_to' => $selectedAssignedTo === null ? '' : (string) $selectedAssignedTo];
if ($selectedEventType !== '') {
    $queryFilters['event_type'] = $selectedEventType;
}

$buildCalendarUrl = static function (array $params): string {
    return '?' . http_build_query($params);
};

$selectedDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
$currentDateTimestamp = strtotime($selectedDate) ?: time();
$previousTimestamp = match ($view) {
    'week' => strtotime('-1 week', $currentDateTimestamp),
    'day' => strtotime('-1 day', $currentDateTimestamp),
    'agenda' => strtotime('-30 days', $currentDateTimestamp),
    default => strtotime('-1 month', mktime(0, 0, 0, $month, 1, $year)),
};
$nextTimestamp = match ($view) {
    'week' => strtotime('+1 week', $currentDateTimestamp),
    'day' => strtotime('+1 day', $currentDateTimestamp),
    'agenda' => strtotime('+30 days', $currentDateTimestamp),
    default => strtotime('+1 month', mktime(0, 0, 0, $month, 1, $year)),
};
$eventLinkAttributes = static function (array $event, string $class, string $style = '') use ($eventsModule): string {
    $eventId = (int) ($event['id'] ?? 0);
    $payload = [
        'id' => $eventId,
        'title' => (string) ($event['title'] ?? 'Untitled event'),
        'type' => (string) ($event['event_type'] ?? 'other'),
        'time' => $eventsModule->formatEventTimeLabel($event),
        'start' => (string) ($event['start_time'] ?? ''),
        'end' => (string) ($event['end_time'] ?? ''),
        'location' => (string) ($event['location'] ?? ''),
        'description' => (string) ($event['description'] ?? ''),
        'viewUrl' => 'event_view.php?id=' . $eventId,
        'editUrl' => 'event_edit.php?id=' . $eventId,
    ];
    $attrs = 'href="event_view.php?id=' . $eventId . '" class="' . htmlspecialchars($class) . '" data-calendar-event="' . htmlspecialchars((string) json_encode($payload), ENT_QUOTES, 'UTF-8') . '"';
    if ($style !== '') {
        $attrs .= ' style="' . htmlspecialchars($style) . '"';
    }

    return $attrs;
};
$agendaEvents = $events;
usort($agendaEvents, static function (array $left, array $right): int {
    return strcmp((string) ($left['start_time'] ?? ''), (string) ($right['start_time'] ?? ''));
});

$pageTitle = 'Calendar - ' . brandProductName();
$calendarGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_CALENDAR);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/calendar-meetings.css') : 'assets/css/calendar-meetings.css'); ?>">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium cm-shell">
    <div class="container cm-workspace">
        <div class="page-header cm-header">
            <div>
                <h1>Calendar</h1>
                <p>Work the day, protect availability, and keep synced meetings honest.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($calendarGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_CALENDAR, 'Calendar page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="<?php echo getBasePath(); ?>/workspace_skills.php?module=calendar_meetings&amp;setup_tab=calendar#setup" class="btn-premium-secondary">
                    <i class="fas fa-link"></i>
                    Calendar Sync
                </a>
                <a href="meeting_bookings.php" class="btn-premium-secondary">
                    <i class="fas fa-calendar-check"></i>
                    Bookings
                </a>
                <a href="<?php echo rtrim(str_replace('/public', '', getBasePath()), '/'); ?>/api/calendar/export.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryFilters, ['date_from' => $startDate, 'date_to' => $endDate]))); ?>" class="btn-premium-secondary">
                    <i class="fas fa-download"></i>
                    Export iCal
                </a>
                <a href="calendar_import.php" class="btn-premium-secondary">
                    <i class="fas fa-upload"></i>
                    Import iCal
                </a>
                <a href="event_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Event
                </a>
            </div>
        </div>

        <div class="filters-card cm-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div class="cm-segmented" aria-label="Calendar view">
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'month', 'month' => $month, 'day' => $day, 'year' => $year], $queryFilters))); ?>" class="<?php echo $view === 'month' ? 'is-active' : ''; ?>" <?php echo $view === 'month' ? 'aria-current="page"' : ''; ?>>
                        Month
                    </a>
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'week', 'month' => $month, 'day' => $day, 'year' => $year], $queryFilters))); ?>" class="<?php echo $view === 'week' ? 'is-active' : ''; ?>" <?php echo $view === 'week' ? 'aria-current="page"' : ''; ?>>
                        Week
                    </a>
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'day', 'month' => $month, 'day' => $day, 'year' => $year], $queryFilters))); ?>" class="<?php echo $view === 'day' ? 'is-active' : ''; ?>" <?php echo $view === 'day' ? 'aria-current="page"' : ''; ?>>
                        Day
                    </a>
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'agenda', 'month' => $month, 'day' => $day, 'year' => $year], $queryFilters))); ?>" class="<?php echo $view === 'agenda' ? 'is-active' : ''; ?>" <?php echo $view === 'agenda' ? 'aria-current="page"' : ''; ?>>
                        Agenda
                    </a>
                </div>

                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge([
                        'view' => $view,
                        'month' => date('m', $previousTimestamp),
                        'day' => date('d', $previousTimestamp),
                        'year' => date('Y', $previousTimestamp),
                    ], $queryFilters))); ?>" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                        Previous
                    </a>
                    <div style="font-weight: 500; color: #0f172a; padding: 0.5rem 0.75rem;">
                        <?php
                        if ($view === 'month') {
                            echo date('F Y', mktime(0, 0, 0, $month, 1, $year));
                        } elseif ($view === 'week') {
                            echo date('M d', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));
                        } elseif ($view === 'agenda') {
                            echo date('M d', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));
                        } else {
                            echo date('F d, Y', mktime(0, 0, 0, $month, $day, $year));
                        }
                        ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge([
                        'view' => $view,
                        'month' => date('m', $nextTimestamp),
                        'day' => date('d', $nextTimestamp),
                        'year' => date('Y', $nextTimestamp),
                    ], $queryFilters))); ?>" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                        Next
                    </a>
                    <a href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge([
                        'view' => $view,
                        'month' => date('m'),
                        'day' => date('d'),
                        'year' => date('Y'),
                    ], $queryFilters))); ?>" class="btn-premium-secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;">
                        Today
                    </a>
                </div>
            </div>

            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(0, 0, 0, 0.1);">
                <form method="GET" action="" class="filters-form">
                    <input type="hidden" name="view" value="<?php echo $view; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="day" value="<?php echo $day; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">

                    <div class="filter-group">
                        <label for="assigned_to">Assigned To</label>
                        <select id="assigned_to" name="assigned_to">
                            <?php if ($canViewAllEvents): ?>
                                <option value="" <?php echo $selectedAssignedTo === null ? 'selected' : ''; ?>>All Users</option>
                            <?php endif; ?>
                            <?php foreach ($users as $userOption): ?>
                                <option value="<?php echo $userOption['id']; ?>" <?php echo $selectedAssignedTo === (int) $userOption['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($userOption['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="event_type">Event Type</label>
                        <select id="event_type" name="event_type">
                            <option value="">All Types</option>
                            <option value="meeting" <?php echo $selectedEventType === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                            <option value="call" <?php echo $selectedEventType === 'call' ? 'selected' : ''; ?>>Call</option>
                            <option value="email" <?php echo $selectedEventType === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="task" <?php echo $selectedEventType === 'task' ? 'selected' : ''; ?>>Task</option>
                            <option value="other" <?php echo $selectedEventType === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-premium-primary">
                            <i class="fas fa-filter"></i>
                            Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($view === 'month'): ?>
            <?php
            $firstDay = mktime(0, 0, 0, $month, 1, $year);
            $daysInMonth = date('t', $firstDay);
            $dayOfWeek = (int) date('w', $firstDay);
            $dayOfWeek = $dayOfWeek === 0 ? 7 : $dayOfWeek;
            $selectedDayEvents = $eventsByDate[$selectedDate] ?? [];
            ?>
            <div class="cm-grid-layout">
                <section class="cm-panel">
                    <div class="cm-calendar-grid" role="grid" aria-label="Month calendar">
                        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName): ?>
                            <div class="cm-calendar-head" role="columnheader"><?php echo $dayName; ?></div>
                        <?php endforeach; ?>
                        <?php for ($i = 1; $i < $dayOfWeek; $i++): ?>
                            <div class="cm-day is-muted" role="gridcell" aria-hidden="true"></div>
                        <?php endfor; ?>
                        <?php for ($monthDay = 1; $monthDay <= $daysInMonth; $monthDay++): ?>
                            <?php
                            $currentDate = date('Y-m-d', mktime(0, 0, 0, $month, $monthDay, $year));
                            $isToday = $currentDate === date('Y-m-d');
                            $isSelected = $currentDate === $selectedDate;
                            $dayEvents = $eventsByDate[$currentDate] ?? [];
                            ?>
                            <div class="cm-day <?php echo $isToday ? 'is-today' : ''; ?>" role="gridcell" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>">
                                <div class="cm-day-top">
                                    <a class="cm-day-number" href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'day', 'month' => $month, 'day' => $monthDay, 'year' => $year], $queryFilters))); ?>"><?php echo $monthDay; ?></a>
                                    <?php if ($dayEvents !== []): ?><span class="cm-density"><?php echo count($dayEvents); ?></span><?php endif; ?>
                                </div>
                                <?php foreach (array_slice($dayEvents, 0, 3) as $event): ?>
                                    <a <?php echo $eventLinkAttributes($event, 'cm-event'); ?> title="<?php echo htmlspecialchars((string) ($event['title'] ?? 'Event')); ?>">
                                        <?php if (empty($event['is_all_day'])): ?><span class="cm-event-time"><?php echo htmlspecialchars($eventsModule->formatEventTimeLabel($event)); ?></span><?php endif; ?>
                                        <span class="cm-event-title"><?php echo htmlspecialchars((string) ($event['title'] ?? 'Untitled event')); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (count($dayEvents) > 3): ?>
                                    <a class="cm-more" href="<?php echo htmlspecialchars($buildCalendarUrl(array_merge(['view' => 'day', 'month' => $month, 'day' => $monthDay, 'year' => $year], $queryFilters))); ?>">
                                        +<?php echo count($dayEvents) - 3; ?> more
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </section>
                <aside class="cm-panel">
                    <div class="cm-panel-header">
                        <h2><?php echo htmlspecialchars(date('D, M j', strtotime($selectedDate))); ?></h2>
                        <a class="cm-btn" href="event_create.php?date=<?php echo htmlspecialchars($selectedDate); ?>">New</a>
                    </div>
                    <div class="cm-agenda">
                        <?php if ($selectedDayEvents === []): ?>
                            <p class="cm-muted">No events on the selected day.</p>
                        <?php else: ?>
                            <?php foreach ($selectedDayEvents as $event): ?>
                                <a <?php echo $eventLinkAttributes($event, 'cm-agenda-row'); ?>>
                                    <strong><?php echo htmlspecialchars((string) ($event['title'] ?? 'Untitled event')); ?></strong>
                                    <span class="cm-muted"><?php echo htmlspecialchars($eventsModule->formatEventTimeLabel($event)); ?><?php if (!empty($event['location'])): ?> - <?php echo htmlspecialchars((string) $event['location']); ?><?php endif; ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        <?php elseif ($view === 'week'): ?>
            <div class="cm-panel" style="overflow:auto;">
                <div style="display: grid; grid-template-columns: 100px repeat(7, 1fr); border-bottom: 2px solid rgba(0, 0, 0, 0.1);">
                    <div style="padding: 1rem; font-weight: 600; color: #0f172a; background: #f8f9fa;"></div>
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <?php
                        $date = date('Y-m-d', strtotime("+$i days", strtotime($startDate)));
                        $isToday = $date === date('Y-m-d');
                        ?>
                        <div style="padding: 1rem; text-align: center; font-weight: 600; color: #0f172a; background: <?php echo $isToday ? '#fef3c7' : '#f8f9fa'; ?>;">
                            <?php echo date('D', strtotime($date)); ?><br>
                            <?php echo date('M d', strtotime($date)); ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <div style="display: grid; grid-template-columns: 100px repeat(7, 1fr); border-bottom: 1px solid rgba(0, 0, 0, 0.1); background: #f8fafc;">
                    <div style="padding: 0.75rem; text-align: right; color: #64748b; font-size: 0.875rem; font-weight: 600;">
                        All day
                    </div>
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <?php
                        $date = date('Y-m-d', strtotime("+$i days", strtotime($startDate)));
                        [$allDayEvents] = $splitAllDayEvents($eventsByDate[$date] ?? []);
                        ?>
                        <div style="min-height: 72px; border-left: 1px solid rgba(0, 0, 0, 0.1); padding: 0.5rem;">
                            <?php if ($allDayEvents === []): ?>
                                <div style="color: #94a3b8; font-size: 0.75rem;">No all-day events</div>
                            <?php else: ?>
                                <?php foreach ($allDayEvents as $event): ?>
                                    <?php $color = $eventTypeColors[$event['event_type']] ?? '#6b7280'; ?>
                                    <a <?php echo $eventLinkAttributes($event, 'cm-event', 'border-left-color:' . $color . ';'); ?>>
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                    <div style="display: grid; grid-template-columns: 100px repeat(7, 1fr); border-bottom: 1px solid rgba(0, 0, 0, 0.1);">
                        <div style="padding: 0.75rem; text-align: right; color: #64748b; font-size: 0.875rem;">
                            <?php echo str_pad((string) $hour, 2, '0', STR_PAD_LEFT); ?>:00
                        </div>
                        <?php for ($i = 0; $i < 7; $i++): ?>
                            <?php
                            $date = date('Y-m-d', strtotime("+$i days", strtotime($startDate)));
                            [, $timedEvents] = $splitAllDayEvents($eventsByDate[$date] ?? []);
                            $hourEvents = [];
                            foreach ($timedEvents as $event) {
                                $eventHour = (int) date('G', strtotime($event['start_time']));
                                if ($eventHour === $hour) {
                                    $hourEvents[] = $event;
                                }
                            }
                            ?>
                            <div style="min-height: 60px; border-left: 1px solid rgba(0, 0, 0, 0.1); padding: 0.5rem;">
                                <?php foreach ($hourEvents as $event): ?>
                                    <?php $color = $eventTypeColors[$event['event_type']] ?? '#6b7280'; ?>
                                    <a <?php echo $eventLinkAttributes($event, 'cm-event', 'border-left-color:' . $color . ';'); ?>>
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php elseif ($view === 'agenda'): ?>
            <section class="cm-panel">
                <div class="cm-panel-header">
                    <h2>Agenda</h2>
                    <span class="cm-chip"><?php echo count($agendaEvents); ?> events</span>
                </div>
                <div class="cm-agenda">
                    <?php if ($agendaEvents === []): ?>
                        <p class="cm-muted">No events in this agenda range.</p>
                    <?php else: ?>
                        <?php $lastAgendaDate = ''; ?>
                        <?php foreach ($agendaEvents as $event): ?>
                            <?php
                            $agendaDate = date('Y-m-d', strtotime((string) ($event['start_time'] ?? $startDate)));
                            $color = $eventTypeColors[$event['event_type']] ?? '#6b7280';
                            ?>
                            <?php if ($agendaDate !== $lastAgendaDate): ?>
                                <div class="cm-panel-header" style="padding:.35rem 0;border:0;">
                                    <h3><?php echo htmlspecialchars(date('l, M j', strtotime($agendaDate))); ?></h3>
                                </div>
                                <?php $lastAgendaDate = $agendaDate; ?>
                            <?php endif; ?>
                            <a <?php echo $eventLinkAttributes($event, 'cm-agenda-row', 'border-left:3px solid ' . $color . ';'); ?>>
                                <strong><?php echo htmlspecialchars((string) ($event['title'] ?? 'Untitled event')); ?></strong>
                                <span class="cm-muted">
                                    <?php echo htmlspecialchars($eventsModule->formatEventTimeLabel($event)); ?>
                                    <?php if (!empty($event['end_time'])): ?> - <?php echo htmlspecialchars(date('g:i A', strtotime((string) $event['end_time']))); ?><?php endif; ?>
                                    <?php if (!empty($event['location'])): ?> - <?php echo htmlspecialchars((string) $event['location']); ?><?php endif; ?>
                                </span>
                                <?php if (!empty($event['description'])): ?>
                                    <span class="cm-muted"><?php echo htmlspecialchars(substr((string) $event['description'], 0, 140)); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <?php
            [$allDayEvents, $timedEvents] = $splitAllDayEvents($eventsByDate[$startDate] ?? []);
            ?>
            <div class="cm-panel" style="overflow:auto;">
                <div style="padding: 1.5rem; border-bottom: 2px solid rgba(0, 0, 0, 0.1); background: #f8f9fa;">
                    <h2 style="color: #0f172a; margin: 0; font-size: 1.5rem; font-weight: 600;">
                        <?php echo date('l, F d, Y', strtotime($startDate)); ?>
                    </h2>
                </div>

                <div style="padding: 1rem 1.5rem; border-bottom: 1px solid rgba(0, 0, 0, 0.1); background: #f8fafc;">
                    <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 0.5rem;">
                        All day
                    </div>
                    <?php if ($allDayEvents === []): ?>
                        <div style="color: #94a3b8; font-size: 0.875rem;">No all-day events</div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <?php foreach ($allDayEvents as $event): ?>
                                <?php $color = $eventTypeColors[$event['event_type']] ?? '#6b7280'; ?>
                                <div style="background: <?php echo $color; ?>; color: white; padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                                    <a <?php echo $eventLinkAttributes($event, 'js-calendar-event', 'color:white;text-decoration:none;font-weight:600;'); ?>>
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </a>
                                    <?php if (!empty($event['location'])): ?>
                                        <div style="font-size: 0.8125rem; opacity: 0.9; margin-top: 0.25rem;">
                                            <?php echo htmlspecialchars($event['location']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display: flex; flex-direction: column;">
                    <?php for ($hour = 0; $hour < 24; $hour++): ?>
                        <?php
                        $hourEvents = [];
                        foreach ($timedEvents as $event) {
                            $eventHour = (int) date('G', strtotime($event['start_time']));
                            if ($eventHour === $hour) {
                                $hourEvents[] = $event;
                            }
                        }
                        ?>
                        <div style="display: grid; grid-template-columns: 100px 1fr; border-bottom: 1px solid rgba(0, 0, 0, 0.1); min-height: 80px;">
                            <div style="padding: 1rem; text-align: right; color: #64748b; font-weight: 500; border-right: 1px solid rgba(0, 0, 0, 0.1);">
                                <?php echo str_pad((string) $hour, 2, '0', STR_PAD_LEFT); ?>:00
                            </div>
                            <div style="padding: 1rem;">
                                <?php if ($hourEvents === []): ?>
                                    <div style="color: #64748b; font-size: 0.875rem;">No events</div>
                                <?php else: ?>
                                    <?php foreach ($hourEvents as $event): ?>
                                        <?php $color = $eventTypeColors[$event['event_type']] ?? '#6b7280'; ?>
                                        <div style="background: <?php echo $color; ?>; color: white; padding: 1rem; border-radius: var(--border-radius-sm); margin-bottom: 0.75rem;">
                                            <div style="font-weight: 600; margin-bottom: 0.5rem;">
                                                <a <?php echo $eventLinkAttributes($event, 'js-calendar-event', 'color:white;text-decoration:none;'); ?>>
                                                    <?php echo htmlspecialchars($event['title']); ?>
                                                </a>
                                            </div>
                                            <div style="font-size: 0.875rem; opacity: 0.9;">
                                                <?php echo htmlspecialchars($eventsModule->formatEventTimeLabel($event)); ?>
                                                <?php if (!empty($event['end_time'])): ?>
                                                    - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($event['location'])): ?>
                                                    - <?php echo htmlspecialchars($event['location']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($event['description'])): ?>
                                                <div style="font-size: 0.8125rem; margin-top: 0.5rem; opacity: 0.9;">
                                                    <?php echo htmlspecialchars(substr($event['description'], 0, 100)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="cm-drawer" id="calendarEventDrawer" aria-hidden="true">
    <div class="cm-drawer-backdrop" data-calendar-drawer-close></div>
    <aside class="cm-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="calendarDrawerTitle">
        <div class="cm-drawer-inner">
            <div class="cm-panel-header" style="padding:0 0 14px;">
                <div>
                    <p class="cm-muted" id="calendarDrawerType">Event</p>
                    <h2 id="calendarDrawerTitle" style="margin:.15rem 0 0;">Event</h2>
                </div>
                <button class="cm-btn" type="button" data-calendar-drawer-close aria-label="Close event drawer">Close</button>
            </div>
            <div class="cm-agenda" style="padding:14px 0 0;">
                <div class="cm-health-row">
                    <strong>When</strong>
                    <span class="cm-muted" id="calendarDrawerTime"></span>
                </div>
                <div class="cm-health-row" id="calendarDrawerLocationRow" hidden>
                    <strong>Location</strong>
                    <span class="cm-muted" id="calendarDrawerLocation"></span>
                </div>
                <div class="cm-alert" id="calendarDrawerSync">Sync status appears after save or worker run.</div>
                <div class="cm-health-row" id="calendarDrawerDescriptionRow" hidden>
                    <strong>Notes</strong>
                    <span class="cm-muted" id="calendarDrawerDescription"></span>
                </div>
                <div class="cm-row-actions">
                    <a class="cm-btn cm-btn-primary" id="calendarDrawerView" href="#">Open event</a>
                    <a class="cm-btn" id="calendarDrawerEdit" href="#">Edit</a>
                    <a class="cm-btn" href="meeting_bookings.php">Bookings</a>
                </div>
            </div>
        </div>
    </aside>
</div>

<script>
(function () {
    var drawer = document.getElementById('calendarEventDrawer');
    if (!drawer) {
        return;
    }
    var title = document.getElementById('calendarDrawerTitle');
    var type = document.getElementById('calendarDrawerType');
    var time = document.getElementById('calendarDrawerTime');
    var locationRow = document.getElementById('calendarDrawerLocationRow');
    var locationText = document.getElementById('calendarDrawerLocation');
    var descriptionRow = document.getElementById('calendarDrawerDescriptionRow');
    var descriptionText = document.getElementById('calendarDrawerDescription');
    var viewLink = document.getElementById('calendarDrawerView');
    var editLink = document.getElementById('calendarDrawerEdit');
    var lastFocus = null;

    function focusableItems() {
        return Array.prototype.slice.call(drawer.querySelectorAll('a[href], button:not([disabled])'));
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.removeEventListener('keydown', onKeydown);
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeDrawer();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusableItems();
        if (items.length === 0) {
            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function openDrawer(payload, trigger) {
        lastFocus = trigger || document.activeElement;
        title.textContent = payload.title || 'Untitled event';
        type.textContent = (payload.type || 'event').replace(/_/g, ' ');
        time.textContent = payload.time || payload.start || 'Time not set';
        locationText.textContent = payload.location || '';
        locationRow.hidden = !payload.location;
        descriptionText.textContent = payload.description || '';
        descriptionRow.hidden = !payload.description;
        viewLink.href = payload.viewUrl || '#';
        editLink.href = payload.editUrl || '#';
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.addEventListener('keydown', onKeydown);
        var items = focusableItems();
        if (items.length > 0) {
            items[0].focus();
        }
    }

    document.querySelectorAll('[data-calendar-event]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var raw = link.getAttribute('data-calendar-event') || '{}';
            try {
                event.preventDefault();
                openDrawer(JSON.parse(raw), link);
            } catch (error) {
                window.location.href = link.href;
            }
        });
    });
    drawer.querySelectorAll('[data-calendar-drawer-close]').forEach(function (button) {
        button.addEventListener('click', closeDrawer);
    });
})();
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_CALENDAR, 'How to use Calendar', $calendarGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
