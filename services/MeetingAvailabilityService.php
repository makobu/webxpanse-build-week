<?php

namespace CRM\Services;

use CRM\Database;

class MeetingAvailabilityService
{
    private const DEFAULT_DURATIONS = [15, 30, 45, 60];
    private const DEFAULT_FORMATS = ['phone_call', 'zoom', 'google_meet', 'in_person'];
    private const MAX_SLOT_RANGE_DAYS = 62;

    public function __construct(private ?int $workspaceId = null)
    {
    }

    public function setWorkspaceId(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function getDefaultProfile(int $workspaceId, ?int $ownerUserId = null): array
    {
        $ownerUserId = $this->resolveOwnerUserId($workspaceId, $ownerUserId);
        $profile = Database::queryOne(
            "SELECT *
             FROM meeting_booking_profiles
             WHERE workspace_id = ?
               AND status <> 'archived'
             ORDER BY public_enabled DESC, id ASC
             LIMIT 1",
            [$workspaceId]
        );

        if ($profile) {
            if (empty($profile['owner_user_id']) && $ownerUserId > 0) {
                Database::execute(
                    "UPDATE meeting_booking_profiles
                     SET owner_user_id = ?,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$ownerUserId, (int) $profile['id'], $workspaceId]
                );
                $profile['owner_user_id'] = $ownerUserId;
            }
            $this->ensureDefaultWindows((int) $profile['id']);
            return $this->hydrateProfile($profile);
        }

        $slug = 'default';
        $timezone = date_default_timezone_get() ?: 'UTC';
        Database::execute(
            "INSERT INTO meeting_booking_profiles (
                workspace_id, owner_user_id, slug, title, description, public_enabled, timezone,
                default_duration_minutes, allowed_durations_json, buffer_before_minutes, buffer_after_minutes,
                min_notice_hours, max_advance_days, allowed_meeting_formats_json, approval_mode, status, created_by
            ) VALUES (?, ?, ?, ?, ?, 1, ?, 30, ?, 15, 15, 24, 60, ?, 'manual', 'active', ?)",
            [
                $workspaceId,
                $ownerUserId,
                $slug,
                'Book a meeting',
                'Request a meeting with our team.',
                $timezone,
                json_encode(self::DEFAULT_DURATIONS),
                json_encode(self::DEFAULT_FORMATS),
                $ownerUserId,
            ]
        );

        $profileId = (int) Database::lastInsertId();
        $this->seedWeekdayWindows($profileId);

        return $this->hydrateProfile(Database::queryOne(
            "SELECT * FROM meeting_booking_profiles WHERE id = ?",
            [$profileId]
        ) ?: []);
    }

    public function findPublicProfile(string $workspaceSlug, string $profileSlug = 'default'): ?array
    {
        $workspace = Database::queryOne(
            "SELECT id, slug, name
             FROM workspaces
             WHERE slug = ?
             LIMIT 1",
            [trim($workspaceSlug)]
        );
        if (!$workspace) {
            return null;
        }

        $profile = Database::queryOne(
            "SELECT p.*, w.slug AS workspace_slug, w.name AS workspace_name
             FROM meeting_booking_profiles p
             JOIN workspaces w ON w.id = p.workspace_id
             WHERE p.workspace_id = ?
               AND p.slug = ?
               AND p.public_enabled = 1
               AND p.status = 'active'
             LIMIT 1",
            [(int) $workspace['id'], trim($profileSlug) ?: 'default']
        );

        return $profile ? $this->hydrateProfile($profile) : null;
    }

    public function getProfile(int $profileId): ?array
    {
        $profile = Database::queryOne(
            "SELECT p.*, w.slug AS workspace_slug, w.name AS workspace_name
             FROM meeting_booking_profiles p
             LEFT JOIN workspaces w ON w.id = p.workspace_id
             WHERE p.id = ?
             LIMIT 1",
            [$profileId]
        );

        return $profile ? $this->hydrateProfile($profile) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getSlots(int $profileId, string $from, string $to, string $requesterTimezone, int $duration): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $profileTimezone = new \DateTimeZone($this->normalizeTimezone((string) $profile['timezone']));
        $viewerTimezone = new \DateTimeZone($this->normalizeTimezone($requesterTimezone));
        $duration = $this->normalizeDuration($duration, $profile);
        $workspaceId = (int) $profile['workspace_id'];
        $windows = $this->availabilityWindows($profileId);
        $now = new \DateTimeImmutable('now', $profileTimezone);
        [$rangeStart, $rangeEnd] = $this->slotDateRange($from, $to, $profileTimezone, $profile, $now);
        if ($rangeEnd < $rangeStart) {
            return [];
        }
        $minStart = $now->modify('+' . max(0, (int) $profile['min_notice_hours']) . ' hours');
        $maxStart = $now->modify('+' . max(1, (int) $profile['max_advance_days']) . ' days');
        $isRoundRobin = (string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin';
        $busy = $isRoundRobin ? [] : $this->busyPeriods($workspaceId, $profile, $rangeStart, $rangeEnd);
        $roundRobinHosts = $isRoundRobin ? $this->enabledRoundRobinHosts($workspaceId, $profile) : [];
        $roundRobinBusy = $isRoundRobin ? $this->roundRobinBusyMap($workspaceId, $profile, $roundRobinHosts, $rangeStart, $rangeEnd) : [];
        $slots = [];

        for ($day = $rangeStart; $day <= $rangeEnd; $day = $day->modify('+1 day')) {
            $dayNumber = (int) $day->format('N');
            foreach ($windows[$dayNumber] ?? [] as $window) {
                $windowStart = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $window['start_time'], $profileTimezone);
                $windowEnd = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $window['end_time'], $profileTimezone);
                if ($windowEnd <= $windowStart) {
                    continue;
                }

                for ($slotStart = $windowStart; $slotStart->modify('+' . $duration . ' minutes') <= $windowEnd; $slotStart = $slotStart->modify('+15 minutes')) {
                    $slotEnd = $slotStart->modify('+' . $duration . ' minutes');
                    $available = $slotStart >= $minStart && $slotStart <= $maxStart;
                    $reason = $available ? null : ($slotStart < $minStart ? 'advance_notice' : 'outside_booking_window');

                    $availableHostCount = null;
                    if ($isRoundRobin) {
                        $availableHosts = $available ? $this->availableHostRowsForSlot($roundRobinHosts, $roundRobinBusy, $slotStart, $slotEnd) : [];
                        $availableHostCount = count($availableHosts);
                        if ($available && $availableHostCount === 0) {
                            $available = false;
                            $reason = 'fully_booked';
                        }
                    } else {
                        foreach ($busy as $period) {
                            if ($this->overlaps($slotStart, $slotEnd, $period['start'], $period['end'])) {
                                $available = false;
                                $reason = (string) ($period['reason'] ?? 'busy');
                                break;
                            }
                        }
                    }

                    $viewerStart = $slotStart->setTimezone($viewerTimezone);
                    $viewerEnd = $slotEnd->setTimezone($viewerTimezone);
                    $slot = [
                        'starts_at' => $viewerStart->format('Y-m-d H:i:s'),
                        'ends_at' => $viewerEnd->format('Y-m-d H:i:s'),
                        'starts_at_iso' => $viewerStart->format(\DateTimeInterface::ATOM),
                        'ends_at_iso' => $viewerEnd->format(\DateTimeInterface::ATOM),
                        'profile_starts_at' => $slotStart->format('Y-m-d H:i:s'),
                        'profile_ends_at' => $slotEnd->format('Y-m-d H:i:s'),
                        'timezone' => $viewerTimezone->getName(),
                        'profile_timezone' => $profileTimezone->getName(),
                        'duration_minutes' => $duration,
                        'available' => $available,
                        'unavailable_reason' => $reason,
                    ];
                    if ($isRoundRobin) {
                        $slot['available_host_count'] = $availableHostCount;
                    }
                    $slots[] = $slot;
                }
            }
        }

        return $slots;
    }

    public function slotIsAvailable(int $profileId, string $startsAt, string $timezone, int $duration): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $viewerTimezone = new \DateTimeZone($this->normalizeTimezone($timezone));
        $start = new \DateTimeImmutable($startsAt, $viewerTimezone);
        $profileDate = $start->setTimezone(new \DateTimeZone($this->normalizeTimezone((string) $profile['timezone'])));
        $slots = $this->getSlots($profileId, $profileDate->format('Y-m-d'), $profileDate->format('Y-m-d'), $timezone, $duration);
        $target = $start->format('Y-m-d H:i:s');

        foreach ($slots as $slot) {
            if ((string) $slot['starts_at'] === $target) {
                return $slot;
            }
        }

        return [
            'starts_at' => $target,
            'timezone' => $viewerTimezone->getName(),
            'available' => false,
            'unavailable_reason' => 'outside_availability',
        ];
    }

    public function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return date_default_timezone_get() ?: 'UTC';
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable $e) {
            return date_default_timezone_get() ?: 'UTC';
        }
    }

    /**
     * @param array<string,mixed> $profile
     */
    public function normalizeDuration(int $duration, array $profile): int
    {
        $allowed = (array) ($profile['allowed_durations'] ?? self::DEFAULT_DURATIONS);
        $allowed = array_values(array_filter(array_map('intval', $allowed), static fn(int $value): bool => $value > 0));
        if ($allowed === []) {
            $allowed = self::DEFAULT_DURATIONS;
        }

        return in_array($duration, $allowed, true) ? $duration : (int) ($profile['default_duration_minutes'] ?? 30);
    }

    /**
     * @return array<string,mixed>
     */
    public function getAvailabilitySetup(int $workspaceId, ?int $profileId = null, ?int $ownerUserId = null): array
    {
        $profile = $profileId !== null && $profileId > 0
            ? $this->getProfile($profileId)
            : $this->getDefaultProfile($workspaceId, $ownerUserId);

        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }

        return [
            'profile' => $profile,
            'availability_windows' => $this->listAvailabilityWindows((int) $profile['id']),
            'blocked_times' => $this->listBlockedTimes($workspaceId, (int) $profile['id']),
            'workspace_users' => $this->listWorkspaceUsers($workspaceId),
            'host_calendar_readiness' => $this->hostCalendarReadiness($workspaceId, (int) ($profile['owner_user_id'] ?? 0), $profile),
            'profile_hosts' => $this->listRoundRobinHosts($workspaceId, (int) $profile['id']),
            'team_calendar_readiness' => $this->teamCalendarReadiness($workspaceId, (int) $profile['id'], $profile),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveBookingProfile(int $workspaceId, int $actorUserId, array $payload): array
    {
        $profileId = (int) ($payload['profile_id'] ?? $payload['id'] ?? 0);
        $profile = $profileId > 0 ? $this->getProfile($profileId) : $this->getDefaultProfile($workspaceId, $actorUserId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $title = trim((string) ($payload['title'] ?? $profile['title'] ?? 'Book a meeting'));
        if ($title === '') {
            throw new \InvalidArgumentException('Profile title is required.');
        }

        $allowedDurations = $this->normalizeDurationList($payload['allowed_durations'] ?? $profile['allowed_durations'] ?? self::DEFAULT_DURATIONS);
        $defaultDuration = $this->boundedInt($payload['default_duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30, 5, 240, 30);
        if (!in_array($defaultDuration, $allowedDurations, true)) {
            $defaultDuration = $allowedDurations[0];
        }

        $meetingFormats = $this->normalizeMeetingFormats($payload['allowed_meeting_formats'] ?? $payload['meeting_formats'] ?? $profile['allowed_meeting_formats'] ?? self::DEFAULT_FORMATS);
        $timezone = $this->normalizeTimezone((string) ($payload['timezone'] ?? $profile['timezone'] ?? 'UTC'));
        $slug = $this->normalizeSlug((string) ($payload['slug'] ?? $profile['slug'] ?? 'default'));
        $status = $this->oneOf((string) ($payload['status'] ?? $profile['status'] ?? 'active'), ['active', 'paused', 'archived'], 'active');
        $approvalMode = $this->oneOf((string) ($payload['approval_mode'] ?? $profile['approval_mode'] ?? 'manual'), ['manual', 'auto_confirm_internal'], 'manual');
        $bookingMode = $this->oneOf((string) ($payload['booking_mode'] ?? $profile['booking_mode'] ?? 'single_host'), ['single_host', 'round_robin'], 'single_host');
        $publicEnabled = array_key_exists('public_enabled', $payload)
            ? (!empty($payload['public_enabled']) && (string) $payload['public_enabled'] !== '0' ? 1 : 0)
            : (!empty($profile['public_enabled']) ? 1 : 0);
        $ownerUserId = $this->resolveOwnerUserId($workspaceId, (int) ($payload['owner_user_id'] ?? $profile['owner_user_id'] ?? 0));
        $sourceMode = $this->oneOf((string) ($payload['availability_source_mode'] ?? $profile['availability_source_mode'] ?? 'owner_all'), ['owner_all', 'selected_integrations', 'none'], 'owner_all');
        if ($bookingMode === 'round_robin' && $sourceMode === 'selected_integrations') {
            $sourceMode = 'owner_all';
        }
        $selectedIntegrationIds = $this->normalizeAvailabilityIntegrationIds(
            $workspaceId,
            $ownerUserId,
            $payload['availability_integration_ids'] ?? $profile['availability_integration_ids'] ?? []
        );
        if ($sourceMode !== 'selected_integrations') {
            $selectedIntegrationIds = [];
        }
        if ($publicEnabled === 1 && $bookingMode === 'round_robin') {
            $candidateProfile = array_merge($profile, [
                'booking_mode' => $bookingMode,
                'owner_user_id' => $ownerUserId,
            ]);
            if ($this->enabledRoundRobinHostCount($workspaceId, $candidateProfile) === 0) {
                throw new \InvalidArgumentException('Select at least one round-robin host before enabling the public booking page.');
            }
        }

        Database::execute(
            "UPDATE meeting_booking_profiles
             SET slug = ?,
                 title = ?,
                 description = ?,
                 owner_user_id = ?,
                 booking_mode = ?,
                 availability_source_mode = ?,
                 availability_integration_ids_json = ?,
                 public_enabled = ?,
                 timezone = ?,
                 default_duration_minutes = ?,
                 allowed_durations_json = ?,
                 buffer_before_minutes = ?,
                 buffer_after_minutes = ?,
                 min_notice_hours = ?,
                 max_advance_days = ?,
                 allowed_meeting_formats_json = ?,
                 approval_mode = ?,
                 status = ?,
                 updated_by = ?
             WHERE id = ?
               AND workspace_id = ?",
            [
                $slug,
                $title,
                trim((string) ($payload['description'] ?? $profile['description'] ?? '')),
                $ownerUserId > 0 ? $ownerUserId : null,
                $bookingMode,
                $sourceMode,
                json_encode($selectedIntegrationIds),
                $publicEnabled,
                $timezone,
                $defaultDuration,
                json_encode($allowedDurations),
                $this->boundedInt($payload['buffer_before_minutes'] ?? $profile['buffer_before_minutes'] ?? 15, 0, 240, 15),
                $this->boundedInt($payload['buffer_after_minutes'] ?? $profile['buffer_after_minutes'] ?? 15, 0, 240, 15),
                $this->boundedInt($payload['min_notice_hours'] ?? $profile['min_notice_hours'] ?? 24, 0, 8760, 24),
                $this->boundedInt($payload['max_advance_days'] ?? $profile['max_advance_days'] ?? 60, 1, 365, 60),
                json_encode($meetingFormats),
                $approvalMode,
                $status,
                $actorUserId > 0 ? $actorUserId : null,
                (int) $profile['id'],
                $workspaceId,
            ]
        );

        return $this->getProfile((int) $profile['id']) ?: [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveAvailabilitySources(int $workspaceId, int $actorUserId, int $profileId, array $payload): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $ownerUserId = $this->resolveOwnerUserId($workspaceId, (int) ($payload['owner_user_id'] ?? $profile['owner_user_id'] ?? 0));
        $sourceMode = $this->oneOf((string) ($payload['availability_source_mode'] ?? 'owner_all'), ['owner_all', 'selected_integrations', 'none'], 'owner_all');
        if ((string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin' && $sourceMode === 'selected_integrations') {
            $sourceMode = 'owner_all';
        }
        $selectedIntegrationIds = $this->normalizeAvailabilityIntegrationIds($workspaceId, $ownerUserId, $payload['availability_integration_ids'] ?? []);
        if ($sourceMode !== 'selected_integrations') {
            $selectedIntegrationIds = [];
        }

        Database::execute(
            "UPDATE meeting_booking_profiles
             SET owner_user_id = ?,
                 availability_source_mode = ?,
                 availability_integration_ids_json = ?,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $ownerUserId > 0 ? $ownerUserId : null,
                $sourceMode,
                json_encode($selectedIntegrationIds),
                $actorUserId > 0 ? $actorUserId : null,
                $profileId,
                $workspaceId,
            ]
        );

        $updated = $this->getProfile($profileId) ?: [];
        return [
            'profile' => $updated,
            'host_calendar_readiness' => $this->hostCalendarReadiness($workspaceId, (int) ($updated['owner_user_id'] ?? 0), $updated),
            'profile_hosts' => $this->listRoundRobinHosts($workspaceId, $profileId),
            'team_calendar_readiness' => $this->teamCalendarReadiness($workspaceId, $profileId, $updated),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRoundRobinHosts(int $workspaceId, int $profileId): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            return [];
        }

        $workspaceUsers = $this->listWorkspaceUsers($workspaceId);
        if ($workspaceUsers === [] || !Database::tableExists('meeting_booking_profile_hosts')) {
            return [];
        }

        $this->ensureDefaultProfileHost($profile);
        $hostRows = Database::query(
            "SELECT *
             FROM meeting_booking_profile_hosts
             WHERE profile_id = ?",
            [$profileId]
        );
        $hostByUser = [];
        foreach ($hostRows as $row) {
            $hostByUser[(int) ($row['user_id'] ?? 0)] = $row;
        }

        $calendarStats = $this->calendarStatsByUser($workspaceId);
        $loadCounts = $this->assignmentLoadCounts($workspaceId, $profileId, array_map(static fn(array $user): int => (int) $user['id'], $workspaceUsers));
        $result = [];
        foreach ($workspaceUsers as $index => $workspaceUser) {
            $userId = (int) ($workspaceUser['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $host = $hostByUser[$userId] ?? [];
            $stats = $calendarStats[$userId] ?? [
                'connected_calendar_count' => 0,
                'availability_enabled_count' => 0,
                'last_busy_checked_at' => null,
                'availability_last_error' => null,
            ];
            $result[] = array_merge($workspaceUser, [
                'user_id' => $userId,
                'profile_host_id' => (int) ($host['id'] ?? 0),
                'is_enabled' => !empty($host['is_enabled']) ? 1 : 0,
                'sort_order' => array_key_exists('sort_order', $host) ? (int) $host['sort_order'] : ($index + 1),
                'last_assigned_at' => $host['last_assigned_at'] ?? null,
                'connected_calendar_count' => (int) $stats['connected_calendar_count'],
                'availability_enabled_count' => (int) $stats['availability_enabled_count'],
                'last_busy_checked_at' => $stats['last_busy_checked_at'],
                'availability_last_error' => $stats['availability_last_error'],
                'busy_cache_status' => $this->busyCacheStatus((string) ($stats['last_busy_checked_at'] ?? ''), (string) ($stats['availability_last_error'] ?? '')),
                'recent_assignment_load' => (int) ($loadCounts[$userId] ?? 0),
            ]);
        }

        usort($result, static function (array $a, array $b): int {
            return ((int) ($b['is_enabled'] ?? 0) <=> (int) ($a['is_enabled'] ?? 0))
                ?: ((int) ($a['sort_order'] ?? 999) <=> (int) ($b['sort_order'] ?? 999))
                ?: strcmp((string) ($a['email'] ?? ''), (string) ($b['email'] ?? ''));
        });

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    public function saveRoundRobinHosts(int $workspaceId, int $actorUserId, int $profileId, array $payload): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }
        if (!Database::tableExists('meeting_booking_profile_hosts')) {
            throw new \RuntimeException('Round-robin host storage is not available. Run migrations first.');
        }

        $hostIds = $payload['host_user_ids'] ?? $payload['hosts'] ?? [];
        if (is_string($hostIds)) {
            $decoded = json_decode($hostIds, true);
            $hostIds = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $hostIds);
        }
        $hostIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($hostIds) ? $hostIds : []
        ), static fn(int $id): bool => $id > 0)));

        $validIds = [];
        foreach ($hostIds as $hostId) {
            if ($this->workspaceUserExists($workspaceId, $hostId)) {
                $validIds[] = $hostId;
            }
        }
        if ((string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin'
            && !empty($profile['public_enabled'])
            && $validIds === []) {
            throw new \InvalidArgumentException('Select at least one round-robin host before enabling the public booking page.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE meeting_booking_profile_hosts
                 SET is_enabled = 0,
                     updated_at = NOW()
                 WHERE profile_id = ?",
                [$profileId]
            );

            foreach ($validIds as $order => $hostId) {
                Database::execute(
                    "INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order)
                     VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE
                        is_enabled = VALUES(is_enabled),
                        sort_order = VALUES(sort_order),
                        updated_at = NOW()",
                    [$profileId, $hostId, $order + 1]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $this->listRoundRobinHosts($workspaceId, $profileId);
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array<string,mixed>
     */
    public function teamCalendarReadiness(int $workspaceId, int $profileId, ?array $profile = null): array
    {
        $profile = $profile ?: $this->getProfile($profileId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            return ['status' => 'missing_profile', 'enabled_host_count' => 0, 'ready_host_count' => 0, 'hosts' => []];
        }

        $hosts = $this->listRoundRobinHosts($workspaceId, $profileId);
        $enabledHosts = array_values(array_filter($hosts, static fn(array $host): bool => !empty($host['is_enabled'])));
        $readyHosts = array_values(array_filter($enabledHosts, static fn(array $host): bool => (int) ($host['availability_enabled_count'] ?? 0) > 0 && (string) ($host['busy_cache_status'] ?? '') !== 'error'));
        $hasError = count(array_filter($enabledHosts, static fn(array $host): bool => (string) ($host['busy_cache_status'] ?? '') === 'error')) > 0;
        $hasStale = count(array_filter($enabledHosts, static fn(array $host): bool => (string) ($host['busy_cache_status'] ?? '') === 'stale')) > 0;

        return [
            'status' => $enabledHosts === []
                ? 'no_hosts'
                : ($hasError ? 'error' : ($hasStale ? 'stale' : ($readyHosts === [] ? 'calendar_optional' : 'ready'))),
            'enabled_host_count' => count($enabledHosts),
            'ready_host_count' => count($readyHosts),
            'hosts' => $hosts,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function availableRoundRobinHostsForSlot(int $profileId, string $startsAt, string $timezone, int $duration, ?int $excludeBookingId = null): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile) {
            return [];
        }
        $workspaceId = (int) $profile['workspace_id'];
        $hosts = $this->enabledRoundRobinHosts($workspaceId, $profile);
        if ($hosts === []) {
            return [];
        }

        $viewerTimezone = new \DateTimeZone($this->normalizeTimezone($timezone));
        $profileTimezone = new \DateTimeZone($this->normalizeTimezone((string) $profile['timezone']));
        $start = (new \DateTimeImmutable($startsAt, $viewerTimezone))->setTimezone($profileTimezone);
        $duration = $this->normalizeDuration($duration, $profile);
        $end = $start->modify('+' . $duration . ' minutes');
        $busyMap = $this->roundRobinBusyMap($workspaceId, $profile, $hosts, $start, $end, $excludeBookingId);

        return $this->availableHostRowsForSlot($hosts, $busyMap, $start, $end);
    }

    public function hostIsAvailableForSlot(int $profileId, int $hostUserId, string $startsAt, string $timezone, int $duration, ?int $excludeBookingId = null): bool
    {
        foreach ($this->availableRoundRobinHostsForSlot($profileId, $startsAt, $timezone, $duration, $excludeBookingId) as $host) {
            if ((int) ($host['user_id'] ?? 0) === $hostUserId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $windows
     * @return list<array<string,mixed>>
     */
    public function saveAvailabilityWindows(int $profileId, int $actorUserId, array $windows): array
    {
        $profile = $this->getProfile($profileId);
        if (!$profile) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $normalized = [];
        foreach ($windows as $window) {
            if (!is_array($window)) {
                continue;
            }
            $day = (int) ($window['day_of_week'] ?? $window['day'] ?? 0);
            if ($day < 1 || $day > 7) {
                throw new \InvalidArgumentException('Availability weekday must be between 1 and 7.');
            }

            $enabled = !empty($window['is_enabled']) && (string) $window['is_enabled'] !== '0';
            $start = $this->normalizeTime((string) ($window['start_time'] ?? '09:00'));
            $end = $this->normalizeTime((string) ($window['end_time'] ?? '17:00'));
            if ($enabled && $end <= $start) {
                throw new \InvalidArgumentException('Availability end time must be after start time.');
            }

            $normalized[] = [
                'day_of_week' => $day,
                'start_time' => $start,
                'end_time' => $end,
                'is_enabled' => $enabled ? 1 : 0,
            ];
        }

        if ($normalized === []) {
            for ($day = 1; $day <= 7; $day++) {
                $normalized[] = [
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'is_enabled' => 0,
                ];
            }
        }

        usort($normalized, static function (array $a, array $b): int {
            return ((int) $a['day_of_week'] <=> (int) $b['day_of_week'])
                ?: strcmp((string) $a['start_time'], (string) $b['start_time'])
                ?: strcmp((string) $a['end_time'], (string) $b['end_time']);
        });
        $lastEnabledEndByDay = [];
        foreach ($normalized as $window) {
            if (empty($window['is_enabled'])) {
                continue;
            }
            $day = (int) $window['day_of_week'];
            if (isset($lastEnabledEndByDay[$day]) && (string) $window['start_time'] < $lastEnabledEndByDay[$day]) {
                throw new \InvalidArgumentException('Availability windows on the same day cannot overlap.');
            }
            $lastEnabledEndByDay[$day] = (string) $window['end_time'];
        }

        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM meeting_availability_windows WHERE profile_id = ?", [$profileId]);
            foreach ($normalized as $window) {
                Database::execute(
                    "INSERT INTO meeting_availability_windows (profile_id, day_of_week, start_time, end_time, is_enabled)
                     VALUES (?, ?, ?, ?, ?)",
                    [$profileId, $window['day_of_week'], $window['start_time'], $window['end_time'], $window['is_enabled']]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $this->listAvailabilityWindows($profileId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAvailabilityWindows(int $profileId): array
    {
        $this->ensureDefaultWindows($profileId);

        return Database::query(
            "SELECT *
             FROM meeting_availability_windows
             WHERE profile_id = ?
             ORDER BY day_of_week ASC, start_time ASC, id ASC",
            [$profileId]
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createBlockedTime(int $workspaceId, int $actorUserId, array $payload): array
    {
        $profileId = (int) ($payload['profile_id'] ?? 0);
        $profile = $profileId > 0 ? $this->getProfile($profileId) : null;
        if ($profile && (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }

        $profileTimezone = new \DateTimeZone($this->normalizeTimezone((string) ($profile['timezone'] ?? $payload['timezone'] ?? 'UTC')));
        $inputTimezone = new \DateTimeZone($this->normalizeTimezone((string) ($payload['timezone'] ?? $profileTimezone->getName())));
        $blockType = $this->oneOf((string) ($payload['block_type'] ?? 'custom'), ['custom', 'whole_day', 'date_range'], 'custom');
        $allDay = $blockType !== 'custom' || !empty($payload['is_all_day']);
        $blockedUserId = (int) ($payload['user_id'] ?? 0);
        if ($blockedUserId > 0 && !$this->workspaceUserExists($workspaceId, $blockedUserId)) {
            throw new \InvalidArgumentException('Blocked-time user must be an active workspace member.');
        }

        if ($allDay) {
            $startDate = trim((string) ($payload['start_date'] ?? $payload['date'] ?? ''));
            $endDate = trim((string) ($payload['end_date'] ?? $payload['date'] ?? $startDate));
            if ($startDate === '') {
                throw new \InvalidArgumentException('Blocked date is required.');
            }
            $start = $this->strictDateInTimezone($startDate, $profileTimezone)->setTime(0, 0, 0);
            $end = $this->strictDateInTimezone($endDate, $profileTimezone)->setTime(23, 59, 59);
        } else {
            $startRaw = trim((string) ($payload['start_time'] ?? $payload['starts_at'] ?? ''));
            $endRaw = trim((string) ($payload['end_time'] ?? $payload['ends_at'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                throw new \InvalidArgumentException('Blocked start and end time are required.');
            }
            $start = (new \DateTimeImmutable($startRaw, $inputTimezone))->setTimezone($profileTimezone);
            $end = (new \DateTimeImmutable($endRaw, $inputTimezone))->setTimezone($profileTimezone);
        }

        if ($end <= $start) {
            throw new \InvalidArgumentException('Blocked end time must be after start time.');
        }

        Database::execute(
            "INSERT INTO meeting_blocked_times (workspace_id, profile_id, user_id, start_time, end_time, is_all_day, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $profileId > 0 ? $profileId : null,
                $blockedUserId > 0 ? $blockedUserId : null,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $allDay ? 1 : 0,
                trim((string) ($payload['reason'] ?? '')) ?: null,
                $actorUserId > 0 ? $actorUserId : null,
            ]
        );

        return Database::queryOne(
            "SELECT *
             FROM meeting_blocked_times
             WHERE id = ?
               AND workspace_id = ?",
            [(int) Database::lastInsertId(), $workspaceId]
        ) ?: [];
    }

    public function deleteBlockedTime(int $workspaceId, int $actorUserId, int $blockedTimeId): bool
    {
        return Database::execute(
            "DELETE FROM meeting_blocked_times
             WHERE id = ?
               AND workspace_id = ?",
            [$blockedTimeId, $workspaceId]
        ) > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBlockedTimes(int $workspaceId, ?int $profileId = null, ?string $from = null, ?string $to = null): array
    {
        $where = ['workspace_id = ?'];
        $params = [$workspaceId];
        if ($profileId !== null && $profileId > 0) {
            $where[] = '(profile_id IS NULL OR profile_id = ?)';
            $params[] = $profileId;
        }
        if ($from !== null && trim($from) !== '') {
            $where[] = 'end_time >= ?';
            $params[] = trim($from);
        } else {
            $where[] = 'end_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
        }
        if ($to !== null && trim($to) !== '') {
            $where[] = 'start_time <= ?';
            $params[] = trim($to);
        }

        return Database::query(
            "SELECT *
             FROM meeting_blocked_times
             WHERE " . implode(' AND ', $where) . "
             ORDER BY start_time ASC, id ASC
             LIMIT 200",
            $params
        );
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function hydrateProfile(array $profile): array
    {
        $profile['allowed_durations'] = $this->decodeJsonList($profile['allowed_durations_json'] ?? null, self::DEFAULT_DURATIONS);
        $profile['allowed_meeting_formats'] = $this->decodeJsonList($profile['allowed_meeting_formats_json'] ?? null, self::DEFAULT_FORMATS);
        $profile['booking_mode'] = $this->oneOf((string) ($profile['booking_mode'] ?? 'single_host'), ['single_host', 'round_robin'], 'single_host');
        $profile['availability_source_mode'] = $this->oneOf((string) ($profile['availability_source_mode'] ?? 'owner_all'), ['owner_all', 'selected_integrations', 'none'], 'owner_all');
        $profile['availability_integration_ids'] = array_values(array_filter(array_map(
            'intval',
            $this->decodeJsonList($profile['availability_integration_ids_json'] ?? null, [])
        ), static fn(int $id): bool => $id > 0));
        $profile['timezone'] = $this->normalizeTimezone((string) ($profile['timezone'] ?? 'UTC'));

        return $profile;
    }

    /**
     * @return array<int, list<array{start_time:string,end_time:string}>>
     */
    private function availabilityWindows(int $profileId): array
    {
        $this->ensureDefaultWindows($profileId);
        $rows = Database::query(
            "SELECT day_of_week, start_time, end_time
             FROM meeting_availability_windows
             WHERE profile_id = ?
               AND is_enabled = 1
             ORDER BY day_of_week ASC, start_time ASC",
            [$profileId]
        );
        $windows = [];
        foreach ($rows as $row) {
            $day = (int) ($row['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }
            $windows[$day][] = [
                'start_time' => (string) $row['start_time'],
                'end_time' => (string) $row['end_time'],
            ];
        }

        return $windows;
    }

    private function ensureDefaultWindows(int $profileId): void
    {
        $count = Database::queryOne(
            "SELECT COUNT(*) AS count FROM meeting_availability_windows WHERE profile_id = ?",
            [$profileId]
        );
        if ((int) ($count['count'] ?? 0) === 0) {
            $this->seedWeekdayWindows($profileId);
        }
    }

    private function seedWeekdayWindows(int $profileId): void
    {
        for ($day = 1; $day <= 5; $day++) {
            Database::execute(
                "INSERT INTO meeting_availability_windows (profile_id, day_of_week, start_time, end_time, is_enabled)
                 VALUES (?, ?, '09:00:00', '17:00:00', 1)",
                [$profileId, $day]
            );
        }
    }

    /**
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable,reason:string}>
     */
    private function busyPeriods(int $workspaceId, array $profile, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        $profileTimezone = new \DateTimeZone($this->normalizeTimezone((string) $profile['timezone']));
        $bufferBefore = max(0, (int) ($profile['buffer_before_minutes'] ?? 0));
        $bufferAfter = max(0, (int) ($profile['buffer_after_minutes'] ?? 0));
        $profileId = (int) $profile['id'];
        $ownerUserId = (int) ($profile['owner_user_id'] ?? 0);
        $busy = [];

        $eventWhere = "workspace_id = ? AND status <> 'cancelled' AND start_time <= ? AND COALESCE(end_time, start_time) >= ?";
        $eventParams = [$workspaceId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')];
        if ($ownerUserId > 0) {
            $eventWhere .= " AND (assigned_to = ? OR assigned_to IS NULL)";
            $eventParams[] = $ownerUserId;
        }
        foreach (Database::query("SELECT start_time, end_time FROM events WHERE {$eventWhere}", $eventParams) as $row) {
            $busy[] = $this->period($row['start_time'], $row['end_time'] ?: $row['start_time'], $profileTimezone, $bufferBefore, $bufferAfter, 'crm_event');
        }

        $blockedWhere = "workspace_id = ?
            AND (profile_id IS NULL OR profile_id = ?)
            AND start_time <= ?
            AND end_time >= ?";
        $blockedParams = [$workspaceId, $profileId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')];
        if ($ownerUserId > 0) {
            $blockedWhere .= " AND (user_id IS NULL OR user_id = ?)";
            $blockedParams[] = $ownerUserId;
        }
        foreach (Database::query("SELECT start_time, end_time FROM meeting_blocked_times WHERE {$blockedWhere}", $blockedParams) as $row) {
            $busy[] = $this->period($row['start_time'], $row['end_time'], $profileTimezone, 0, 0, 'blocked');
        }

        $availabilityIntegrationIds = $this->availabilityIntegrationIdsForProfile($workspaceId, $profile);
        if ($availabilityIntegrationIds !== []) {
            $utcTimezone = new \DateTimeZone('UTC');
            $placeholders = implode(',', array_fill(0, count($availabilityIntegrationIds), '?'));
            foreach (Database::query(
                "SELECT busy_start, busy_end
                 FROM meeting_calendar_busy_cache
                 WHERE workspace_id = ?
                   AND integration_id IN ($placeholders)
                   AND status = 'ok'
                   AND expires_at > NOW()
                   AND busy_start <= ?
                   AND busy_end >= ?",
                array_merge(
                    [$workspaceId],
                    $availabilityIntegrationIds,
                    [
                        $rangeEnd->setTimezone($utcTimezone)->format('Y-m-d H:i:s'),
                        $rangeStart->setTimezone($utcTimezone)->format('Y-m-d H:i:s'),
                    ]
                )
            ) as $row) {
                $busy[] = $this->period($row['busy_start'], $row['busy_end'], $utcTimezone, $bufferBefore, $bufferAfter, 'external_busy');
            }
        }

        $bookingWhere = "workspace_id = ?
            AND status IN ('pending', 'confirmed')
            AND scheduled_start <= ?
            AND scheduled_end >= ?";
        $bookingParams = [$workspaceId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')];
        if ($ownerUserId > 0) {
            $bookingWhere .= " AND (assigned_host_user_id = ? OR (assigned_host_user_id IS NULL AND profile_id = ?))";
            $bookingParams[] = $ownerUserId;
            $bookingParams[] = $profileId;
        } else {
            $bookingWhere .= " AND profile_id = ?";
            $bookingParams[] = $profileId;
        }
        foreach (Database::query(
            "SELECT scheduled_start, scheduled_end FROM meeting_booking_requests WHERE {$bookingWhere}",
            $bookingParams
        ) as $row) {
            $busy[] = $this->period($row['scheduled_start'], $row['scheduled_end'], $profileTimezone, $bufferBefore, $bufferAfter, 'booking');
        }

        return $busy;
    }

    private function resolveOwnerUserId(int $workspaceId, ?int $ownerUserId): int
    {
        $ownerUserId = (int) ($ownerUserId ?? 0);
        if ($workspaceId <= 0) {
            return $ownerUserId;
        }

        if ($ownerUserId > 0 && $this->workspaceUserExists($workspaceId, $ownerUserId)) {
            return $ownerUserId;
        }

        if (!Database::tableExists('workspace_memberships')) {
            return $ownerUserId;
        }

        $row = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY is_owner DESC,
                      FIELD(role_slug, 'owner', 'admin', 'sales', 'marketing', 'viewer'),
                      user_id ASC
             LIMIT 1",
            [$workspaceId]
        );

        return (int) ($row['user_id'] ?? 0);
    }

    private function workspaceUserExists(int $workspaceId, int $userId): bool
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('workspace_memberships')) {
            return false;
        }

        return (bool) Database::queryOne(
            "SELECT 1
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    /**
     * @return list<array{id:int,email:string,label:string,role_slug:string,is_owner:int}>
     */
    private function listWorkspaceUsers(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return [];
        }

        $rows = Database::query(
            "SELECT u.id, u.email, wm.role_slug, wm.is_owner
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC,
                      FIELD(wm.role_slug, 'owner', 'admin', 'sales', 'marketing', 'viewer'),
                      u.email ASC",
            [$workspaceId]
        );

        return array_map(static function (array $row): array {
            $email = (string) ($row['email'] ?? '');
            return [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
                'label' => $email !== '' ? $email : 'User #' . (int) ($row['id'] ?? 0),
                'role_slug' => (string) ($row['role_slug'] ?? ''),
                'is_owner' => (int) ($row['is_owner'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function normalizeAvailabilityIntegrationIds(int $workspaceId, int $ownerUserId, $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || $workspaceId <= 0 || $ownerUserId <= 0) {
            return [];
        }
        if (!Database::tableExists('calendar_integrations')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            "SELECT id
             FROM calendar_integrations
             WHERE workspace_id = ?
               AND user_id = ?
               AND id IN ($placeholders)
             ORDER BY id ASC",
            array_merge([$workspaceId, $ownerUserId], $ids)
        );

        return array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
    }

    /**
     * @param array<string,mixed> $profile
     * @return list<int>
     */
    private function availabilityIntegrationIdsForProfile(int $workspaceId, array $profile): array
    {
        $mode = $this->effectiveAvailabilitySourceMode($profile);
        if ($mode === 'none') {
            return [];
        }

        $ownerUserId = (int) ($profile['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0 || !Database::tableExists('calendar_integrations')) {
            return [];
        }

        if ($mode === 'selected_integrations') {
            return $this->normalizeAvailabilityIntegrationIds($workspaceId, $ownerUserId, $profile['availability_integration_ids'] ?? []);
        }

        $enabledClause = Database::columnExists('calendar_integrations', 'availability_enabled') ? 'AND availability_enabled = 1' : '';
        $rows = Database::query(
            "SELECT id
             FROM calendar_integrations
             WHERE workspace_id = ?
               AND user_id = ?
               $enabledClause
             ORDER BY provider ASC, calendar_name ASC, id ASC",
            [$workspaceId, $ownerUserId]
        );

        return array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function hostCalendarReadiness(int $workspaceId, int $ownerUserId, array $profile): array
    {
        $mode = $this->effectiveAvailabilitySourceMode($profile);
        $selectedIds = array_values(array_filter(array_map('intval', (array) ($profile['availability_integration_ids'] ?? [])), static fn(int $id): bool => $id > 0));
        $integrations = [];
        if ($workspaceId > 0 && $ownerUserId > 0 && Database::tableExists('calendar_integrations')) {
            $integrations = Database::query(
                "SELECT id, provider, calendar_id, calendar_name, provider_account_email,
                        availability_enabled, sync_enabled, sync_direction, last_sync_at,
                        availability_last_checked_at, availability_last_error, updated_at
                 FROM calendar_integrations
                 WHERE workspace_id = ?
                   AND user_id = ?
                 ORDER BY availability_enabled DESC, provider ASC, calendar_name ASC, id ASC",
                [$workspaceId, $ownerUserId]
            );
        }

        $activeIds = $this->availabilityIntegrationIdsForProfile($workspaceId, $profile);
        $stale = false;
        $hasError = false;
        foreach ($integrations as &$integration) {
            $integration['selected_for_availability'] = in_array((int) ($integration['id'] ?? 0), $activeIds, true);
            $checkedAt = strtotime((string) ($integration['availability_last_checked_at'] ?? '')) ?: 0;
            $integration['busy_cache_status'] = !empty($integration['availability_last_error'])
                ? 'error'
                : ($checkedAt > time() - 7200 ? 'fresh' : 'stale');
            if ($integration['busy_cache_status'] === 'stale' && !empty($integration['availability_enabled'])) {
                $stale = true;
            }
            if ($integration['busy_cache_status'] === 'error') {
                $hasError = true;
            }
        }
        unset($integration);

        return [
            'owner_user_id' => $ownerUserId,
            'source_mode' => $mode,
            'selected_integration_ids' => $selectedIds,
            'active_integration_ids' => $activeIds,
            'integrations' => $integrations,
            'connected_count' => count($integrations),
            'active_count' => count($activeIds),
            'status' => $mode === 'none'
                ? 'disabled'
                : ($integrations === [] ? 'not_connected' : ($hasError ? 'error' : ($stale ? 'stale' : 'ready'))),
        ];
    }

    private function ensureDefaultProfileHost(array $profile): void
    {
        if (!Database::tableExists('meeting_booking_profile_hosts')) {
            return;
        }
        $profileId = (int) ($profile['id'] ?? 0);
        $ownerUserId = (int) ($profile['owner_user_id'] ?? 0);
        if ($profileId <= 0 || $ownerUserId <= 0) {
            return;
        }

        $count = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM meeting_booking_profile_hosts
             WHERE profile_id = ?",
            [$profileId]
        );
        if ((int) ($count['count'] ?? 0) > 0) {
            return;
        }

        Database::execute(
            "INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order)
             VALUES (?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE updated_at = NOW()",
            [$profileId, $ownerUserId]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function enabledRoundRobinHosts(int $workspaceId, array $profile): array
    {
        if (!Database::tableExists('meeting_booking_profile_hosts') || !Database::tableExists('workspace_memberships')) {
            return [];
        }

        $this->ensureDefaultProfileHost($profile);
        $rows = Database::query(
            "SELECT ph.user_id, ph.sort_order, ph.last_assigned_at, u.email, wm.role_slug, wm.is_owner
             FROM meeting_booking_profile_hosts ph
             JOIN workspace_memberships wm ON wm.user_id = ph.user_id
                AND wm.workspace_id = ?
                AND wm.membership_status = 'active'
             JOIN users u ON u.id = ph.user_id
             WHERE ph.profile_id = ?
               AND ph.is_enabled = 1
             ORDER BY ph.sort_order ASC, ph.user_id ASC",
            [$workspaceId, (int) $profile['id']]
        );

        return array_map(static function (array $row): array {
            $userId = (int) ($row['user_id'] ?? 0);
            return [
                'user_id' => $userId,
                'id' => $userId,
                'email' => (string) ($row['email'] ?? ''),
                'label' => (string) (($row['email'] ?? '') ?: ('User #' . $userId)),
                'role_slug' => (string) ($row['role_slug'] ?? ''),
                'is_owner' => (int) ($row['is_owner'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'last_assigned_at' => $row['last_assigned_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function enabledRoundRobinHostCount(int $workspaceId, array $profile): int
    {
        return count($this->enabledRoundRobinHosts($workspaceId, $profile));
    }

    /**
     * @param list<array<string,mixed>> $hosts
     * @return array<int,list<array{start:\DateTimeImmutable,end:\DateTimeImmutable,reason:string}>>
     */
    private function roundRobinBusyMap(int $workspaceId, array $profile, array $hosts, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, ?int $excludeBookingId = null): array
    {
        $busy = [];
        $queryStart = $rangeStart->modify('-' . max(0, (int) ($profile['buffer_before_minutes'] ?? 0)) . ' minutes');
        $queryEnd = $rangeEnd->modify('+' . max(0, (int) ($profile['buffer_after_minutes'] ?? 0)) . ' minutes');

        foreach ($hosts as $host) {
            $hostUserId = (int) ($host['user_id'] ?? $host['id'] ?? 0);
            if ($hostUserId <= 0) {
                continue;
            }
            $busy[$hostUserId] = $this->hostBusyPeriods($workspaceId, $profile, $hostUserId, $queryStart, $queryEnd, $excludeBookingId);
        }

        return $busy;
    }

    /**
     * @param list<array<string,mixed>> $hosts
     * @param array<int,list<array{start:\DateTimeImmutable,end:\DateTimeImmutable,reason:string}>> $busyMap
     * @return list<array<string,mixed>>
     */
    private function availableHostRowsForSlot(array $hosts, array $busyMap, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd): array
    {
        $available = [];
        foreach ($hosts as $host) {
            $hostUserId = (int) ($host['user_id'] ?? $host['id'] ?? 0);
            if ($hostUserId <= 0) {
                continue;
            }

            $blocked = false;
            foreach ($busyMap[$hostUserId] ?? [] as $period) {
                if ($this->overlaps($slotStart, $slotEnd, $period['start'], $period['end'])) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $available[] = $host;
            }
        }

        return $available;
    }

    /**
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable,reason:string}>
     */
    private function hostBusyPeriods(int $workspaceId, array $profile, int $hostUserId, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, ?int $excludeBookingId = null): array
    {
        $profileTimezone = new \DateTimeZone($this->normalizeTimezone((string) $profile['timezone']));
        $bufferBefore = max(0, (int) ($profile['buffer_before_minutes'] ?? 0));
        $bufferAfter = max(0, (int) ($profile['buffer_after_minutes'] ?? 0));
        $profileId = (int) $profile['id'];
        $busy = [];

        foreach (Database::query(
            "SELECT start_time, end_time
             FROM events
             WHERE workspace_id = ?
               AND status <> 'cancelled'
               AND start_time <= ?
               AND COALESCE(end_time, start_time) >= ?
               AND (assigned_to = ? OR (assigned_to IS NULL AND created_by = ?))",
            [$workspaceId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s'), $hostUserId, $hostUserId]
        ) as $row) {
            $busy[] = $this->period($row['start_time'], $row['end_time'] ?: $row['start_time'], $profileTimezone, $bufferBefore, $bufferAfter, 'crm_event');
        }

        foreach (Database::query(
            "SELECT start_time, end_time
             FROM meeting_blocked_times
             WHERE workspace_id = ?
               AND (profile_id IS NULL OR profile_id = ?)
               AND (user_id IS NULL OR user_id = ?)
               AND start_time <= ?
               AND end_time >= ?",
            [$workspaceId, $profileId, $hostUserId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')]
        ) as $row) {
            $busy[] = $this->period($row['start_time'], $row['end_time'], $profileTimezone, 0, 0, 'blocked');
        }

        $availabilityIntegrationIds = $this->availabilityIntegrationIdsForHost($workspaceId, $profile, $hostUserId);
        if ($availabilityIntegrationIds !== []) {
            $utcTimezone = new \DateTimeZone('UTC');
            $placeholders = implode(',', array_fill(0, count($availabilityIntegrationIds), '?'));
            foreach (Database::query(
                "SELECT busy_start, busy_end
                 FROM meeting_calendar_busy_cache
                 WHERE workspace_id = ?
                   AND integration_id IN ($placeholders)
                   AND status = 'ok'
                   AND expires_at > NOW()
                   AND busy_start <= ?
                   AND busy_end >= ?",
                array_merge(
                    [$workspaceId],
                    $availabilityIntegrationIds,
                    [
                        $rangeEnd->setTimezone($utcTimezone)->format('Y-m-d H:i:s'),
                        $rangeStart->setTimezone($utcTimezone)->format('Y-m-d H:i:s'),
                    ]
                )
            ) as $row) {
                $busy[] = $this->period($row['busy_start'], $row['busy_end'], $utcTimezone, $bufferBefore, $bufferAfter, 'external_busy');
            }
        }

        $bookingWhere = "workspace_id = ?
            AND status IN ('pending', 'confirmed')
            AND (assigned_host_user_id = ? OR (assigned_host_user_id IS NULL AND profile_id = ?))
            AND scheduled_start <= ?
            AND scheduled_end >= ?";
        $bookingParams = [$workspaceId, $hostUserId, $profileId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')];
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $bookingWhere .= " AND id <> ?";
            $bookingParams[] = $excludeBookingId;
        }
        foreach (Database::query("SELECT scheduled_start, scheduled_end FROM meeting_booking_requests WHERE {$bookingWhere}", $bookingParams) as $row) {
            $busy[] = $this->period($row['scheduled_start'], $row['scheduled_end'], $profileTimezone, $bufferBefore, $bufferAfter, 'booking');
        }

        return $busy;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,int>
     */
    private function assignmentLoadCounts(int $workspaceId, int $profileId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = Database::query(
            "SELECT assigned_host_user_id, COUNT(*) AS count
             FROM meeting_booking_requests
             WHERE workspace_id = ?
               AND profile_id = ?
               AND assigned_host_user_id IN ($placeholders)
               AND status IN ('pending', 'confirmed')
               AND COALESCE(assigned_at, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY assigned_host_user_id",
            array_merge([$workspaceId, $profileId], $userIds)
        );

        $counts = array_fill_keys($userIds, 0);
        foreach ($rows as $row) {
            $counts[(int) ($row['assigned_host_user_id'] ?? 0)] = (int) ($row['count'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function calendarStatsByUser(int $workspaceId): array
    {
        if (!Database::tableExists('calendar_integrations')) {
            return [];
        }

        $rows = Database::query(
            "SELECT user_id, id, availability_enabled, availability_last_checked_at, availability_last_error
             FROM calendar_integrations
             WHERE workspace_id = ?",
            [$workspaceId]
        );
        $stats = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (!isset($stats[$userId])) {
                $stats[$userId] = [
                    'connected_calendar_count' => 0,
                    'availability_enabled_count' => 0,
                    'last_busy_checked_at' => null,
                    'availability_last_error' => null,
                ];
            }
            $stats[$userId]['connected_calendar_count']++;
            if (!empty($row['availability_enabled'])) {
                $stats[$userId]['availability_enabled_count']++;
            }
            $checkedAt = (string) ($row['availability_last_checked_at'] ?? '');
            if ($checkedAt !== '' && (empty($stats[$userId]['last_busy_checked_at']) || strtotime($checkedAt) > strtotime((string) $stats[$userId]['last_busy_checked_at']))) {
                $stats[$userId]['last_busy_checked_at'] = $checkedAt;
            }
            $error = trim((string) ($row['availability_last_error'] ?? ''));
            if ($error !== '') {
                $stats[$userId]['availability_last_error'] = $error;
            }
        }

        return $stats;
    }

    private function busyCacheStatus(string $checkedAt, string $error): string
    {
        if (trim($error) !== '') {
            return 'error';
        }
        $timestamp = strtotime($checkedAt) ?: 0;

        return $timestamp > time() - 7200 ? 'fresh' : 'stale';
    }

    /**
     * @param array<string,mixed> $profile
     * @return list<int>
     */
    private function availabilityIntegrationIdsForHost(int $workspaceId, array $profile, int $hostUserId): array
    {
        $mode = $this->effectiveAvailabilitySourceMode($profile);
        if ($mode === 'none' || $hostUserId <= 0 || !Database::tableExists('calendar_integrations')) {
            return [];
        }

        if ($mode === 'selected_integrations') {
            return $this->normalizeAvailabilityIntegrationIds($workspaceId, $hostUserId, $profile['availability_integration_ids'] ?? []);
        }

        $enabledClause = Database::columnExists('calendar_integrations', 'availability_enabled') ? 'AND availability_enabled = 1' : '';
        $rows = Database::query(
            "SELECT id
             FROM calendar_integrations
             WHERE workspace_id = ?
               AND user_id = ?
               $enabledClause
             ORDER BY provider ASC, calendar_name ASC, id ASC",
            [$workspaceId, $hostUserId]
        );

        return array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function effectiveAvailabilitySourceMode(array $profile): string
    {
        $mode = $this->oneOf((string) ($profile['availability_source_mode'] ?? 'owner_all'), ['owner_all', 'selected_integrations', 'none'], 'owner_all');
        if ((string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin' && $mode === 'selected_integrations') {
            return 'owner_all';
        }

        return $mode;
    }

    private function period($start, $end, \DateTimeZone $timezone, int $bufferBefore, int $bufferAfter, string $reason): array
    {
        $startTime = (new \DateTimeImmutable((string) $start, $timezone))->modify('-' . $bufferBefore . ' minutes');
        $endTime = (new \DateTimeImmutable((string) $end, $timezone))->modify('+' . $bufferAfter . ' minutes');

        return ['start' => $startTime, 'end' => $endTime, 'reason' => $reason];
    }

    private function overlaps(\DateTimeImmutable $startA, \DateTimeImmutable $endA, \DateTimeImmutable $startB, \DateTimeImmutable $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function slotDateRange(
        string $from,
        string $to,
        \DateTimeZone $timezone,
        array $profile,
        \DateTimeImmutable $now
    ): array
    {
        $requestedStart = $this->strictDateInTimezone(trim($from) ?: $now->format('Y-m-d'), $timezone)->setTime(0, 0);
        $requestedEnd = $this->strictDateInTimezone(trim($to) ?: $requestedStart->format('Y-m-d'), $timezone)->setTime(23, 59, 59);
        if ($requestedEnd < $requestedStart) {
            throw new \InvalidArgumentException('Availability end date must be on or after the start date.');
        }

        $requestedDays = (int) $requestedStart->diff($requestedEnd)->days;
        if ($requestedDays > self::MAX_SLOT_RANGE_DAYS) {
            throw new \InvalidArgumentException('Availability can be requested for at most ' . self::MAX_SLOT_RANGE_DAYS . ' days at a time.');
        }

        $today = $now->setTime(0, 0);
        $bookingWindowEnd = $now
            ->modify('+' . max(1, (int) ($profile['max_advance_days'] ?? 60)) . ' days')
            ->setTime(23, 59, 59);

        return [
            $requestedStart > $today ? $requestedStart : $today,
            $requestedEnd < $bookingWindowEnd ? $requestedEnd : $bookingWindowEnd,
        ];
    }

    private function strictDateInTimezone(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Date must use YYYY-MM-DD format.');
        }

        return $date;
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList($value, array $fallback): array
    {
        $decoded = is_string($value) && trim($value) !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? array_values($decoded) : $fallback;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
        $slug = trim($slug, '-');

        return substr($slug !== '' ? $slug : 'default', 0, 120);
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function normalizeDurationList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        $durations = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $minutes): bool => $minutes >= 5 && $minutes <= 240)));
        sort($durations);

        return $durations !== [] ? $durations : self::DEFAULT_DURATIONS;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeMeetingFormats($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        $allowed = array_flip(self::DEFAULT_FORMATS);
        $formats = [];
        foreach (is_array($value) ? $value : [] as $format) {
            $key = trim((string) $format);
            if ($key !== '' && isset($allowed[$key])) {
                $formats[] = $key;
            }
        }
        $formats = array_values(array_unique($formats));

        return $formats !== [] ? $formats : self::DEFAULT_FORMATS;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function boundedInt($value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $number = (int) $value;

        return max($min, min($max, $number));
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Availability time must use HH:MM format.');
        }

        return str_pad((string) (int) $matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2] . ':00';
    }
}
