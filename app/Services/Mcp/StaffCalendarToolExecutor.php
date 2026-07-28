<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Helpers\MarkdownRenderer;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Graph\GraphClient;
use App\Support\CalendarConfig;
use App\Support\TechnicianConfig;

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
 * NOTE (field-shape provenance): the projection field names are taken from the documented MS
 * Graph v1.0 producer — event resource (/graph/api/resources/event), calendarView, getSchedule
 * (/graph/api/calendar-getschedule) — cited again at each project* method. During development the
 * names were also confirmed against a live app-token capture, but that capture is developer-local
 * (.gc is gitignored) and is NOT committed, so the test fixtures are contract-derived doubles built
 * to the documented shape, not the captured payload itself. onlineMeeting.joinUrl follows the
 * documented event resource (null-safe when the event is not a Teams meeting).
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
                'description' => 'Get the free/busy availability grid for one or more mailboxes over a window (Microsoft Graph getSchedule) — use this to find open meeting slots. EVERY mailbox involved MUST be on the server-side calendar owner allowlist: user_upn (the calendar queried through) AND every entry in schedules. A schedules entry is a mailbox whose availability you READ, so a non-allowlisted entry REJECTS THE WHOLE CALL (no partial grid is returned) — request only allowlisted mailboxes. Returns each mailbox\'s availability_view (a per-interval code string where each digit is 0=free or working-elsewhere, 1=tentative, 2=busy, 3=out-of-office), busy_blocks (start/end/status only — never meeting subjects or locations), and working_hours. Read-only. Requires an explicit token grant.',
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
            'calendar_create_event' => $this->handleCreateEvent($arguments),
            'calendar_update_event' => $this->handleUpdateEvent($arguments),
            'calendar_cancel_event' => $this->handleCancelEvent($arguments),
            'calendar_respond_event' => $this->handleRespondEvent($arguments),
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

    // ---------------------------------------------------------------------------------------------
    // Slice B (psa-lulgh) — WRITES. Every write is gated by the same guardOwnerUpn() security spine
    // as the reads (the owner user_upn must be allowlisted), takes a REQUIRED ticket_id (Charlie
    // 19:10Z: every event traces to a why) that must resolve to a real ticket, takes a REQUIRED
    // reason (audit), and drops a PRIVATE back-link note on that ticket. The Graph body is built
    // here from validated tool args to the shapes grounded in the MS Graph v1.0 producer — the
    // executor never accepts a raw passthrough body (an agent must not be able to set owner /
    // organizer / responseStatus or any field outside the scheduling surface). External client
    // emails are legitimate ATTENDEES; they are NEVER the owner (the guard proves the owner).
    // ---------------------------------------------------------------------------------------------

    /**
     * Create an event on the (allowlisted) owner's calendar.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function handleCreateEvent(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $subject = $this->requiredString($arguments, 'subject');
        $start = $this->requiredString($arguments, 'start');
        $end = $this->requiredString($arguments, 'end');
        if ($subject === null || $start === null || $end === null) {
            return ['error' => 'subject, start, and end (ISO-8601 timestamps) are required to create an event.'];
        }

        $attendees = $this->attendeesFrom($arguments['attendees'] ?? []);
        if (isset($attendees['error'])) {
            return $attendees;
        }

        $tz = $this->timeZoneFrom($arguments);
        $body = [
            'subject' => $subject,
            'start' => ['dateTime' => $start, 'timeZone' => $tz],
            'end' => ['dateTime' => $end, 'timeZone' => $tz],
        ];
        if ($attendees['attendees'] !== []) {
            $body['attendees'] = $attendees['attendees'];
        }
        if (($location = $this->requiredString($arguments, 'location')) !== null) {
            $body['location'] = ['displayName' => $location];
        }
        if (($eventBody = $this->requiredString($arguments, 'body')) !== null) {
            // contentType Text: the agent supplies plain text; we do not accept HTML so a body can
            // never smuggle markup into a client-facing invite.
            $body['body'] = ['contentType' => 'Text', 'content' => $eventBody];
        }
        if (filter_var($arguments['teams_meeting'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }
        // Deterministic transactionId — create is NON-IDEMPOTENT, so an accidental retry would
        // double-book. Graph returns the FIRST event for a repeated transactionId, making the
        // create idempotent-on-retry within Graph's dedup window (user-post-events producer).
        $body['transactionId'] = hash('sha256', implode('|', [mb_strtolower($upn), $subject, $start, $end, (string) $ticket->id]));

        $event = $this->graph->createEvent($upn, $body);

        $this->backlinkNote($ticket, sprintf(
            'AI technician created a calendar event in %s: "%s" (%s → %s, %s). Event id: %s. Reason: %s',
            $upn, $subject, $start, $end, $tz, (string) ($event['id'] ?? '?'), $ctx['reason'],
        ));

        return [
            'success' => true,
            'action' => 'created',
            'user_upn' => $upn,
            'ticket_id' => $ticket->id,
            'event' => $this->projectEvent($event),
        ];
    }

    /**
     * Update an existing event on the owner's calendar. Only the scheduling fields are patchable —
     * a partial body is built from those supplied; at least one is required.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function handleUpdateEvent(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $tz = $this->timeZoneFrom($arguments);
        $patch = [];
        if (($subject = $this->requiredString($arguments, 'subject')) !== null) {
            $patch['subject'] = $subject;
        }
        if (($start = $this->requiredString($arguments, 'start')) !== null) {
            $patch['start'] = ['dateTime' => $start, 'timeZone' => $tz];
        }
        if (($end = $this->requiredString($arguments, 'end')) !== null) {
            $patch['end'] = ['dateTime' => $end, 'timeZone' => $tz];
        }
        if (($location = $this->requiredString($arguments, 'location')) !== null) {
            $patch['location'] = ['displayName' => $location];
        }
        if (($eventBody = $this->requiredString($arguments, 'body')) !== null) {
            $patch['body'] = ['contentType' => 'Text', 'content' => $eventBody];
        }
        if (array_key_exists('attendees', $arguments)) {
            $attendees = $this->attendeesFrom($arguments['attendees']);
            if (isset($attendees['error'])) {
                return $attendees;
            }
            $patch['attendees'] = $attendees['attendees'];
        }

        if ($patch === []) {
            return ['error' => 'Provide at least one field to update (subject, start, end, location, body, or attendees).'];
        }

        $event = $this->graph->updateEvent($upn, $eventId, $patch);

        $this->backlinkNote($ticket, sprintf(
            'AI technician updated calendar event %s in %s (%s). Reason: %s',
            $eventId, $upn, implode(', ', array_keys($patch)), $ctx['reason'],
        ));

        return [
            'success' => true,
            'action' => 'updated',
            'user_upn' => $upn,
            'ticket_id' => $ticket->id,
            'event' => $this->projectEvent($event),
        ];
    }

    /**
     * Cancel a meeting the owner organizes (Graph cancel is organizer-only — an attendee gets a
     * hard 400, never a silent success).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function handleCancelEvent(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $comment = $this->requiredString($arguments, 'comment'); // optional -> null

        $this->graph->cancelEvent($upn, $eventId, $comment);

        $this->backlinkNote($ticket, sprintf(
            'AI technician cancelled calendar event %s in %s. Reason: %s',
            $eventId, $upn, $ctx['reason'],
        ));

        return [
            'success' => true,
            'action' => 'cancelled',
            'user_upn' => $upn,
            'ticket_id' => $ticket->id,
            'event_id' => $eventId,
        ];
    }

    /**
     * Respond (accept/decline/tentative) to an invite AS the owner mailbox.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function handleRespondEvent(array $arguments): array
    {
        $upn = (string) $arguments['user_upn'];
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $response = $this->requiredString($arguments, 'response');
        if ($response === null || ! in_array($response, ['accept', 'decline', 'tentative'], true)) {
            return ['error' => 'response is required and must be one of: accept, decline, tentative.'];
        }
        $comment = $this->requiredString($arguments, 'comment'); // optional -> null
        $sendResponse = filter_var($arguments['send_response'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $this->graph->respondEvent($upn, $eventId, $response, $comment, $sendResponse);

        $this->backlinkNote($ticket, sprintf(
            'AI technician responded "%s" to calendar event %s in %s. Reason: %s',
            $response, $eventId, $upn, $ctx['reason'],
        ));

        return [
            'success' => true,
            'action' => 'responded',
            'response' => $response,
            'user_upn' => $upn,
            'ticket_id' => $ticket->id,
            'event_id' => $eventId,
        ];
    }

    /**
     * Resolve the REQUIRED ticket_id + reason shared by every write. ticket_id must resolve to a
     * real ticket (the audit anchor); reason is the audit note. Returns ['ticket'=>Ticket,
     * 'reason'=>string] or ['error'=>string].
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function requireTicketAndReason(array $arguments): array
    {
        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required (a short audit note for why this calendar action is being taken).'];
        }

        $raw = $arguments['ticket_id'] ?? null;
        $ticketId = match (true) {
            is_int($raw) && $raw > 0 => $raw,
            is_string($raw) && ctype_digit($raw) && (int) $raw > 0 => (int) $raw,
            default => null,
        };
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required (the ticket this calendar action traces to) and must be a positive integer.'];
        }

        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return ['error' => "ticket_id {$ticketId} does not resolve to an existing ticket."];
        }

        return ['ticket' => $ticket, 'reason' => $reason];
    }

    /**
     * Build a Graph attendees[] collection from a list of email addresses. Attendees may be
     * EXTERNAL (the manager's rule: externals are attendees, never the owner) — so they are NOT
     * allowlist-checked here; they are only validated as syntactically real email addresses. One
     * malformed entry refuses the whole call NAMING it, so the agent fixes it rather than silently
     * inviting garbage. Shape: MS Graph attendee {emailAddress{address}, type}.
     *
     * @return array{attendees: array<int, array<string, mixed>>}|array{error: string}
     */
    private function attendeesFrom(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['error' => 'attendees must be an array of email addresses.'];
        }

        $attendees = [];
        foreach ($raw as $entry) {
            $email = is_string($entry) ? trim($entry) : null;
            if ($email === null || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $shown = is_string($entry) ? $entry : gettype($entry);

                return ['error' => "Attendee '{$shown}' is not a valid email address."];
            }
            $attendees[] = ['emailAddress' => ['address' => $email], 'type' => 'required'];
        }

        return ['attendees' => $attendees];
    }

    /**
     * The event time zone. The repo works in UTC (DB stores UTC), so the tz arg defaults to UTC and
     * the caller passes UTC ISO-8601 — mirroring getSchedule's timeZone=UTC convention.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function timeZoneFrom(array $arguments): string
    {
        $tz = $arguments['tz'] ?? null;

        return is_string($tz) && trim($tz) !== '' ? trim($tz) : 'UTC';
    }

    /**
     * Drop the PRIVATE audit back-link note on the ticket the write traced to. Private + system
     * note type (never client-visible), authored by the AI actor — the human-readable half of the
     * audit (the redacted mcp_audit_logs row is the other). Mirrors StaffPsaActionToolExecutor's
     * createAiNote author wiring, but private and NoteType::System.
     */
    private function backlinkNote(Ticket $ticket, string $body): void
    {
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => TechnicianConfig::requiredAiActorUserId(),
            'author_name' => TechnicianConfig::aiActorName(),
            'who_type' => WhoType::Agent,
            'ai_authored' => true,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => NoteType::System,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $ticket->touch();
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
            // Per-interval code string. Per MS Graph v1.0 (calendar-getschedule), each digit is:
            // 0 = free (AND workingElsewhere — represented as 0 for backward compatibility),
            // 1 = tentative, 2 = busy, 3 = out of office. There is NO digit 4 in availabilityView.
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
