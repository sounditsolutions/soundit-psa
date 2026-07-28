<?php

namespace App\Services\Mcp;

use App\Services\Graph\GraphClient;
use App\Support\CalendarConfig;

/**
 * Staff-MCP Calendar/scheduling READ executor (psa-abl0i, Slice A). Read-only calendar access
 * over the existing Microsoft Graph client (Application permissions).
 *
 * SECURITY SPINE — the server-side UPN allowlist is enforced ONCE here, at guardOwnerUpn(), the
 * single choke point every tool passes through before any Graph call. Charlie dropped the Azure
 * Application Access Policy, so this allowlist is now the ONLY constraint on which mailboxes the
 * tenant-wide token may read (or, in Slice B, act on). It fails closed: a disabled toolset, a
 * missing user_upn, or a non-allowlisted mailbox is refused before Graph is touched. The
 * allowlist gates the OWNER mailbox (user_upn) only — event attendees may be external and are
 * never checked here.
 *
 * NOTE (fixtures): the projection field names were verified field-by-field against a CAPTURED
 * live Graph payload (calendarView / event / getSchedule / mailboxSettings on the real app token,
 * psa-abl0i) — not merely the documented shape. The one field not present in the sample is
 * onlineMeeting.joinUrl (no sampled event was a Teams meeting); it stays the documented,
 * null-safe shape. Test fixtures are sanitised copies of that real shape (values replaced).
 */
class StaffCalendarToolExecutor
{
    public function __construct(private readonly GraphClient $graph) {}

    /**
     * The published READ-tool schemas (Slice A). This executor is the single source of both
     * the advertised schema and dispatch — McpToolRegistry::calendarTools() delegates here, so
     * the grant catalog, tools/list, and the match() in execute() cannot drift apart (the
     * StaffPsaTaxonomyToolExecutor / tactical-executor pattern). Slice B appends the staged
     * write twins.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'calendar_list_events',
                'description' => 'List calendar events in a time window for one mailbox (Microsoft Graph calendarView). user_upn is the mailbox whose calendar to read and MUST be on the server-side calendar owner allowlist — a non-allowlisted mailbox is refused before any Graph call. Returns each event\'s subject, start/end, location, organizer, attendees, and Teams join URL. Read-only. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => [
                            'type' => 'string',
                            'description' => 'The mailbox (UPN) whose calendar to read. Must be an allowlisted calendar owner; external/client mailboxes are never valid here.',
                        ],
                        'start' => [
                            'type' => 'string',
                            'description' => 'Window start, ISO-8601 (UTC). Events overlapping the window are returned.',
                        ],
                        'end' => [
                            'type' => 'string',
                            'description' => 'Window end, ISO-8601 (UTC).',
                        ],
                    ],
                    'required' => ['user_upn', 'start', 'end'],
                ],
            ],
            [
                'name' => 'calendar_get_event',
                'description' => 'Get one calendar event\'s full detail (Microsoft Graph event resource) from a mailbox. user_upn is the mailbox that owns the event and MUST be on the server-side calendar owner allowlist — a non-allowlisted mailbox is refused before any Graph call. Read-only. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => [
                            'type' => 'string',
                            'description' => 'The mailbox (UPN) that owns the event. Must be an allowlisted calendar owner.',
                        ],
                        'event_id' => [
                            'type' => 'string',
                            'description' => 'The Graph event id to read.',
                        ],
                    ],
                    'required' => ['user_upn', 'event_id'],
                ],
            ],
            [
                'name' => 'calendar_get_schedule',
                'description' => 'Get the free/busy availability grid for one or more mailboxes over a window (Microsoft Graph getSchedule) — use this to find open meeting slots. EVERY mailbox involved MUST be on the server-side calendar owner allowlist: user_upn (the calendar queried through) AND every entry in schedules. A schedules entry is a mailbox whose availability you READ, so a non-allowlisted entry REJECTS THE WHOLE CALL (no partial grid is returned) — request only allowlisted mailboxes. Returns each mailbox\'s availability_view (per-interval free/busy codes), busy_blocks (start/end/status only — never meeting subjects or locations), and working_hours. Read-only. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => [
                            'type' => 'string',
                            'description' => 'The mailbox (UPN) to query through — the organizer\'s calendar. Must be an allowlisted calendar owner.',
                        ],
                        'schedules' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'The mailbox UPNs whose free/busy to retrieve. EVERY entry must be an allowlisted calendar owner — a single non-allowlisted entry rejects the whole request.',
                        ],
                        'start' => [
                            'type' => 'string',
                            'description' => 'Window start, ISO-8601 (UTC).',
                        ],
                        'end' => [
                            'type' => 'string',
                            'description' => 'Window end, ISO-8601 (UTC).',
                        ],
                        'interval' => [
                            'type' => 'integer',
                            'description' => 'Availability-view interval in minutes (default 30). Each digit of availability_view covers one interval.',
                        ],
                    ],
                    'required' => ['user_upn', 'schedules', 'start', 'end'],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function toolNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    /** Whether this executor owns the named tool (the controller's dispatch/grant gate). */
    public static function handles(string $name): bool
    {
        return in_array($name, self::toolNames(), true);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        // The allowlist choke point — before ANY handler or Graph call, for every calendar tool.
        if ($error = $this->guardOwnerUpn($name, $arguments)) {
            return $error;
        }

        return match ($name) {
            'calendar_list_events' => $this->listEvents($arguments),
            'calendar_get_event' => $this->getEvent($arguments),
            'calendar_get_schedule' => $this->getScheduleAvailability($arguments),
            default => ['error' => "Unknown calendar tool: {$name}"],
        };
    }

    /**
     * The single allowlist enforcement seam — the SECURITY SPINE. Since Charlie dropped the Azure
     * Application Access Policy, this is the ONLY constraint on which mailboxes the tenant-wide
     * Graph token may touch. It fails closed, before any Graph call, for every calendar tool:
     * refuses a disabled toolset, a missing/blank user_upn, or any mailbox outside CalendarConfig's
     * owner allowlist. Returns an error array to short-circuit, or null to proceed.
     *
     * getSchedule carries an extra gated dimension: its schedules[] are the mailboxes whose
     * free/busy we READ. *** An attendee is someone you WRITE to; a schedules[] entry is someone
     * you READ from — free/busy is information disclosure, a different privilege class, so the
     * "externals may be attendees" write rule does NOT transfer here. *** Every entry must be
     * allowlisted, and a single non-allowlisted target REJECTS THE WHOLE CALL naming the offender
     * (a grid missing a requested mailbox looks complete — a partial result must never be
     * indistinguishable from a full one). (psa-abl0i fork 3, manager ruling.)
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function guardOwnerUpn(string $name, array $arguments): ?array
    {
        if (! CalendarConfig::isEnabled()) {
            return ['error' => 'The calendar toolset is disabled in this deployment.'];
        }

        $upn = $arguments['user_upn'] ?? null;
        if (! is_string($upn) || trim($upn) === '') {
            return ['error' => 'user_upn is required (the mailbox whose calendar to access).'];
        }

        if (! CalendarConfig::ownerUpnAllowed($upn)) {
            return ['error' => "Calendar access to {$upn} is refused: it is not on the calendar owner allowlist. Only approved mailboxes may be read or acted on — ask an operator to add it if this is intended."];
        }

        if ($name === 'calendar_get_schedule') {
            $schedules = $arguments['schedules'] ?? null;
            if (! is_array($schedules) || $schedules === []) {
                return ['error' => 'schedules must be a non-empty array of mailbox UPNs to check availability for.'];
            }

            foreach ($schedules as $schedule) {
                if (! is_string($schedule) || trim($schedule) === '') {
                    return ['error' => 'Each schedules entry must be a non-empty mailbox UPN.'];
                }

                if (! CalendarConfig::ownerUpnAllowed($schedule)) {
                    return ['error' => "Calendar availability for {$schedule} is refused: it is not on the calendar owner allowlist. The whole request is rejected — no partial free/busy grid is returned — because every requested mailbox must be allowlisted. Remove it (or ask an operator to add it) and retry."];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function listEvents(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $start = $this->requiredString($arguments, 'start');
        $end = $this->requiredString($arguments, 'end');
        if ($start === null || $end === null) {
            return ['error' => 'start and end (ISO-8601 timestamps) are required.'];
        }

        $events = $this->graph->calendarView($upn, $start, $end);

        return [
            'user_upn' => $upn,
            'window' => ['start' => $start, 'end' => $end],
            'events' => array_map([$this, 'projectEvent'], $events),
        ];
    }

    /** @return array<string, mixed> */
    private function getEvent(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }

        $event = $this->graph->getEvent($upn, $eventId);

        return [
            'user_upn' => $upn,
            'event' => $this->projectEvent($event),
        ];
    }

    /**
     * Free/busy availability for the requested mailboxes. The allowlist guard has already proven
     * user_upn AND every schedules[] entry are allowlisted (and schedules is a non-empty string
     * list), so here we only validate the window and interval.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getScheduleAvailability(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $start = $this->requiredString($arguments, 'start');
        $end = $this->requiredString($arguments, 'end');
        if ($start === null || $end === null) {
            return ['error' => 'start and end (ISO-8601 timestamps) are required.'];
        }

        /** @var list<string> $schedules */
        $schedules = array_values($arguments['schedules']);

        $interval = $arguments['interval'] ?? 30;
        $interval = is_int($interval) && $interval > 0 ? $interval : 30;

        $information = $this->graph->getSchedule($upn, $schedules, $start, $end, $interval);

        return [
            'user_upn' => $upn,
            'window' => ['start' => $start, 'end' => $end],
            'interval_minutes' => $interval,
            'schedules' => array_map([$this, 'projectSchedule'], $information),
        ];
    }

    /**
     * Project one Graph scheduleInformation (camelCase) into a stable snake_case availability
     * shape. Field source: MS Graph v1.0 scheduleInformation —
     * https://learn.microsoft.com/en-us/graph/api/calendar-getschedule
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function projectSchedule(array $s): array
    {
        return [
            'schedule_id' => $s['scheduleId'] ?? null,
            // Per-interval free/busy code string (0=free,1=tentative,2=busy,3=oof,4=workingElsewhere).
            'availability_view' => $s['availabilityView'] ?? null,
            'working_hours' => $this->projectWorkingHours($s['workingHours'] ?? null),
            'busy_blocks' => array_map([$this, 'projectBusyBlock'], $s['scheduleItems'] ?? []),
        ];
    }

    /**
     * Project a Graph scheduleItem into an availability block. DELIBERATELY availability only —
     * status + window. The scheduleItem also carries subject/location/isPrivate on the wire, but a
     * free/busy read discloses WHEN a mailbox is busy, never WHAT the meeting is; the content stays
     * behind calendar_get_event (which requires that specific owner mailbox). Do not add subject or
     * location here.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function projectBusyBlock(array $item): array
    {
        return [
            'status' => $item['status'] ?? null,
            'start' => $this->projectDateTime($item['start'] ?? null),
            'end' => $this->projectDateTime($item['end'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $wh  a Graph workingHours {daysOfWeek, startTime, endTime, timeZone:{name}}
     * @return array<string, mixed>|null
     */
    private function projectWorkingHours(?array $wh): ?array
    {
        if ($wh === null) {
            return null;
        }

        return [
            'days_of_week' => $wh['daysOfWeek'] ?? [],
            'start_time' => $wh['startTime'] ?? null,
            'end_time' => $wh['endTime'] ?? null,
            'time_zone' => $wh['timeZone']['name'] ?? null,
        ];
    }

    /**
     * Project a Graph event resource (camelCase) into a stable snake_case tool shape.
     * Field source: MS Graph v1.0 event resource — https://learn.microsoft.com/en-us/graph/api/resources/event
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function projectEvent(array $e): array
    {
        return [
            'id' => $e['id'] ?? null,
            'subject' => $e['subject'] ?? null,
            'body_preview' => $e['bodyPreview'] ?? null,
            'start' => $this->projectDateTime($e['start'] ?? null),
            'end' => $this->projectDateTime($e['end'] ?? null),
            'is_all_day' => (bool) ($e['isAllDay'] ?? false),
            'show_as' => $e['showAs'] ?? null,
            'is_online_meeting' => (bool) ($e['isOnlineMeeting'] ?? false),
            'online_meeting_url' => $e['onlineMeeting']['joinUrl'] ?? null,
            'location' => $e['location']['displayName'] ?? null,
            'organizer' => $this->projectRecipient($e['organizer'] ?? null),
            'response_status' => $e['responseStatus']['response'] ?? null,
            'web_link' => $e['webLink'] ?? null,
            'attendees' => array_map([$this, 'projectAttendee'], $e['attendees'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $dt  a Graph dateTimeTimeZone {dateTime, timeZone}
     * @return array<string, mixed>|null
     */
    private function projectDateTime(?array $dt): ?array
    {
        if ($dt === null) {
            return null;
        }

        return [
            'date_time' => $dt['dateTime'] ?? null,
            'time_zone' => $dt['timeZone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $r  a Graph recipient {emailAddress:{name,address}}
     * @return array<string, mixed>|null
     */
    private function projectRecipient(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }

        return [
            'name' => $r['emailAddress']['name'] ?? null,
            'email' => $r['emailAddress']['address'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a  a Graph attendee {type, status:{response,time}, emailAddress}
     * @return array<string, mixed>
     */
    private function projectAttendee(array $a): array
    {
        return [
            'name' => $a['emailAddress']['name'] ?? null,
            'email' => $a['emailAddress']['address'] ?? null,
            'type' => $a['type'] ?? null,
            'response' => $a['status']['response'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
