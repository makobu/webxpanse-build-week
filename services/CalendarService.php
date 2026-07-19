<?php
/**
 * Calendar Service
 * 
 * Handles iCal export/import and calendar integrations
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Events;
use CRM\Services\WorkspaceContext;

class CalendarService
{
    /**
     * Export events to iCal format
     */
    public function exportToICal(array $eventIds = null, ?array $user = null, array $filters = []): string
    {
        $eventsModule = new Events();
        $queryFilters = $filters;

        if ($eventIds !== null) {
            $queryFilters['ids'] = $eventIds;
        }

        $events = $eventsModule->getAllForUser($queryFilters, $user, 5000, 0);
        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId > 0) {
            $events = array_values(array_filter(
                $events,
                static fn(array $event): bool => (int) ($event['workspace_id'] ?? 0) === $workspaceId
            ));
        }
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//" . brandProductName() . "//iCal Export//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        
        foreach ($events as $event) {
            $ical .= $this->eventToICal($event);
        }
        
        $ical .= "END:VCALENDAR\r\n";
        
        return $ical;
    }
    
    /**
     * Convert event to iCal format
     */
    private function eventToICal(array $event): string
    {
        $uidSource = (string) ($event['occurrence_id'] ?? $event['id'] ?? bin2hex(random_bytes(8)));
        $uid = preg_replace('/[^A-Za-z0-9_.:@-]+/', '-', $uidSource) ?: $uidSource;
        $isAllDay = !empty($event['is_all_day']);

        $ical = "BEGIN:VEVENT\r\n";
        $ical .= $this->foldICalLine("UID:" . $uid . "@crm.local") . "\r\n";
        $ical .= "DTSTAMP:" . $this->formatDateTime((string) ($event['created_at'] ?? 'now')) . "\r\n";

        if ($isAllDay) {
            $ical .= "DTSTART;VALUE=DATE:" . $this->formatAllDayDate((string) ($event['start_time'] ?? 'now')) . "\r\n";
            $ical .= "DTEND;VALUE=DATE:" . $this->formatAllDayEndDate((string) ($event['end_time'] ?? $event['start_time'] ?? 'now')) . "\r\n";
        } else {
            $ical .= "DTSTART:" . $this->formatDateTime((string) ($event['start_time'] ?? 'now')) . "\r\n";

            if (!empty($event['end_time'])) {
                $ical .= "DTEND:" . $this->formatDateTime((string) $event['end_time']) . "\r\n";
            }
        }
        
        $ical .= $this->foldICalLine("SUMMARY:" . $this->escapeICalText((string) ($event['title'] ?? 'Untitled Event'))) . "\r\n";
        
        if (!empty($event['description'])) {
            $ical .= $this->foldICalLine("DESCRIPTION:" . $this->escapeICalText((string) $event['description'])) . "\r\n";
        }
        
        if (!empty($event['location'])) {
            $ical .= $this->foldICalLine("LOCATION:" . $this->escapeICalText((string) $event['location'])) . "\r\n";
        }
        
        if (!empty($event['contact_email'])) {
            $organizerName = trim((string) ($event['contact_first_name'] ?? '') . ' ' . (string) ($event['contact_last_name'] ?? ''));
            $ical .= $this->foldICalLine("ORGANIZER;CN=" . $this->escapeICalText($organizerName) . ":MAILTO:" . $event['contact_email']) . "\r\n";
        }

        $rrule = $this->formatRRule($event);
        if ($rrule !== null && empty($event['is_recurring_occurrence'])) {
            $ical .= "RRULE:" . $rrule . "\r\n";
        }
        
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "SEQUENCE:0\r\n";
        $ical .= "END:VEVENT\r\n";
        
        return $ical;
    }
    
    /**
     * Format datetime for iCal
     */
    private function formatDateTime(string $datetime): string
    {
        try {
            $dateTime = new \DateTimeImmutable($datetime, new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable $e) {
            $dateTime = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function formatAllDayDate(string $datetime): string
    {
        try {
            return (new \DateTimeImmutable($datetime, new \DateTimeZone(date_default_timezone_get())))->format('Ymd');
        } catch (\Throwable $e) {
            return date('Ymd');
        }
    }

    private function formatAllDayEndDate(string $datetime): string
    {
        try {
            $end = new \DateTimeImmutable($datetime, new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable $e) {
            $end = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        }

        return $end->modify('+1 day')->format('Ymd');
    }
    
    /**
     * Escape text for iCal format
     */
    private function escapeICalText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace("\n", '\\n', $text);
        return $text;
    }

    private function foldICalLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        while (strlen($line) > 75) {
            $folded .= substr($line, 0, 75) . "\r\n ";
            $line = substr($line, 75);
        }

        return $folded . $line;
    }

    private function formatRRule(array $event): ?string
    {
        $pattern = strtolower((string) ($event['recurrence_pattern'] ?? 'none'));
        $freq = match ($pattern) {
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'monthly' => 'MONTHLY',
            'yearly' => 'YEARLY',
            default => null,
        };

        if ($freq === null) {
            return null;
        }

        $parts = ['FREQ=' . $freq];
        if (!empty($event['recurrence_count'])) {
            $parts[] = 'COUNT=' . max(1, (int) $event['recurrence_count']);
        } elseif (!empty($event['recurrence_end_date'])) {
            $parts[] = 'UNTIL=' . $this->formatDateTime((string) $event['recurrence_end_date'] . ' 23:59:59');
        }

        return implode(';', $parts);
    }

    /**
     * @return list<string>
     */
    private function unfoldICalLines(string $icalContent): array
    {
        $rawLines = preg_split('/\r\n|\r|\n/', $icalContent) ?: [];
        $lines = [];

        foreach ($rawLines as $line) {
            if (($line !== '') && ($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= substr($line, 1);
                continue;
            }

            $lines[] = rtrim($line, "\r\n");
        }

        return $lines;
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function parseICalProperty(string $property): array
    {
        $segments = explode(';', $property);
        $name = strtoupper(array_shift($segments) ?: '');
        $parameters = [];

        foreach ($segments as $segment) {
            if (strpos($segment, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $segment, 2);
            $parameters[strtoupper($key)] = trim($value, '"');
        }

        return [$name, $parameters];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseRRule(string $value): array
    {
        $parts = [];
        foreach (explode(';', $value) as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }

            [$key, $partValue] = explode('=', $part, 2);
            $parts[strtoupper($key)] = $partValue;
        }

        $pattern = match (strtoupper((string) ($parts['FREQ'] ?? ''))) {
            'DAILY' => 'daily',
            'WEEKLY' => 'weekly',
            'MONTHLY' => 'monthly',
            'YEARLY' => 'yearly',
            default => 'none',
        };

        if ($pattern === 'none') {
            return [];
        }

        $result = ['recurrence_pattern' => $pattern];
        if (!empty($parts['COUNT'])) {
            $result['recurrence_count'] = max(1, (int) $parts['COUNT']);
        }
        if (!empty($parts['UNTIL'])) {
            $parsed = $this->parseICalDateTime((string) $parts['UNTIL']);
            $result['recurrence_end_date'] = substr($parsed['datetime'], 0, 10);
        }

        return $result;
    }
    
    /**
     * Import events from iCal file
     */
    public function importFromICal(string $icalContent, int $userId, array $options = []): array
    {
        $imported = [];
        $errors = [];
        
        $lines = $this->unfoldICalLines($icalContent);
        $currentEvent = null;
        $inEvent = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent) {
                    try {
                        $eventId = $this->createEventFromICal($currentEvent, $userId, $options);
                        $imported[] = $eventId;
                    } catch (\Exception $e) {
                        $errors[] = $e->getMessage();
                    }
                }
                $inEvent = false;
                $currentEvent = null;
            } elseif ($inEvent && $currentEvent !== null) {
                $this->parseICalLine($line, $currentEvent);
            }
        }
        
        return [
            'imported' => $imported,
            'errors' => $errors,
            'count' => count($imported)
        ];
    }
    
    /**
     * Parse iCal line
     */
    private function parseICalLine(string $line, array &$event): void
    {
        if (strpos($line, ':') === false) {
            return;
        }
        
        list($key, $value) = explode(':', $line, 2);
        [$property, $parameters] = $this->parseICalProperty($key);
        
        switch ($property) {
            case 'SUMMARY':
                $event['title'] = $this->unescapeICalText($value);
                break;
            case 'DESCRIPTION':
                $event['description'] = $this->unescapeICalText($value);
                break;
            case 'LOCATION':
                $event['location'] = $this->unescapeICalText($value);
                break;
            case 'DTSTART':
                $parsedStart = $this->parseICalDateTime($value, $parameters, false);
                $event['start_time'] = $parsedStart['datetime'];
                if ($parsedStart['all_day']) {
                    $event['is_all_day'] = 1;
                }
                break;
            case 'DTEND':
                $parsedEnd = $this->parseICalDateTime($value, $parameters, true);
                $event['end_time'] = $parsedEnd['datetime'];
                if ($parsedEnd['all_day']) {
                    $event['is_all_day'] = 1;
                }
                break;
            case 'UID':
                $event['uid'] = $value;
                break;
            case 'RRULE':
                $event = array_merge($event, $this->parseRRule($value));
                break;
        }
    }
    
    /**
     * Parse iCal datetime
     *
     * @return array{datetime:string,all_day:bool}
     */
    private function parseICalDateTime(string $dt, array $parameters = [], bool $exclusiveEnd = false): array
    {
        $dt = trim($dt);
        $valueType = strtoupper((string) ($parameters['VALUE'] ?? ''));
        $isDateOnly = $valueType === 'DATE' || (strlen($dt) === 8 && strpos($dt, 'T') === false);
        $appTimezone = new \DateTimeZone(date_default_timezone_get());

        if ($isDateOnly) {
            $dateTime = \DateTimeImmutable::createFromFormat('!Ymd', $dt, $appTimezone);
            if (!$dateTime) {
                $dateTime = new \DateTimeImmutable('now', $appTimezone);
            }
            if ($exclusiveEnd) {
                $dateTime = $dateTime->modify('-1 second');
            }

            return ['datetime' => $dateTime->format('Y-m-d H:i:s'), 'all_day' => true];
        }

        $timezone = $appTimezone;
        if (!empty($parameters['TZID'])) {
            try {
                $timezone = new \DateTimeZone((string) $parameters['TZID']);
            } catch (\Throwable $e) {
                $timezone = $appTimezone;
            }
        }

        if (substr($dt, -1) === 'Z') {
            $dateTime = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $dt, new \DateTimeZone('UTC'));
        } else {
            $dateTime = \DateTimeImmutable::createFromFormat('Ymd\THis', $dt, $timezone);
        }

        if (!$dateTime) {
            try {
                $dateTime = new \DateTimeImmutable($dt, $timezone);
            } catch (\Throwable $e) {
                $dateTime = new \DateTimeImmutable('now', $appTimezone);
            }
        }

        return ['datetime' => $dateTime->setTimezone($appTimezone)->format('Y-m-d H:i:s'), 'all_day' => false];
    }
    
    /**
     * Unescape iCal text
     */
    private function unescapeICalText(string $text): string
    {
        $text = str_replace('\\n', "\n", $text);
        $text = str_replace('\\;', ';', $text);
        $text = str_replace('\\,', ',', $text);
        $text = str_replace('\\\\', '\\', $text);
        return $text;
    }
    
    /**
     * Create event from iCal data
     */
    private function createEventFromICal(array $icalData, int $userId, array $options): int
    {
        if (empty($icalData['title']) || empty($icalData['start_time'])) {
            throw new \Exception("Missing required fields: title and start_time");
        }
        
        // Check if event already exists (by UID)
        $workspaceId = $this->currentWorkspaceId();
        if (!empty($icalData['uid']) && !empty($options['skip_duplicates'])) {
            $existing = Database::queryOne(
                "SELECT id
                 FROM events
                 WHERE workspace_id = ?
                   AND custom_fields LIKE ?
                 LIMIT 1",
                [$workspaceId, '%"ical_uid":"' . $icalData['uid'] . '"%']
            );
            
            if ($existing) {
                throw new \Exception("Event already exists (UID: {$icalData['uid']})");
            }
        }
        
        $customFields = !empty($icalData['uid']) ? json_encode(['ical_uid' => $icalData['uid']]) : null;
        $fields = [
            'workspace_id',
            'title',
            'description',
            'start_time',
            'end_time',
            'location',
            'event_type',
            'assigned_to',
            'created_by',
            'is_all_day',
            'custom_fields',
        ];
        $values = [
            $workspaceId,
            $icalData['title'],
            $icalData['description'] ?? null,
            $icalData['start_time'],
            $icalData['end_time'] ?? null,
            $icalData['location'] ?? null,
            'meeting',
            $userId,
            $userId,
            !empty($icalData['is_all_day']) ? 1 : 0,
            $customFields,
        ];

        foreach (['recurrence_pattern', 'recurrence_end_date', 'recurrence_count'] as $field) {
            if (array_key_exists($field, $icalData) && Database::columnExists('events', $field)) {
                $fields[] = $field;
                $values[] = $icalData[$field];
            }
        }
        
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        Database::execute(
            "INSERT INTO events (" . implode(', ', $fields) . ") VALUES ({$placeholders})",
            $values
        );
        
        return (int) Database::lastInsertId();
    }

    private function currentWorkspaceId(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Calendar operations require an active workspace.');
        }

        return $workspaceId;
    }
}
