<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Enums\WhoType;
use App\Helpers\MarkdownRenderer;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\CalendarConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

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
            [
                'name' => 'calendar_create_event',
                'description' => 'Create a calendar event on a mailbox you own (Microsoft Graph create event). user_upn is the ORGANIZER mailbox and MUST be on the server-side calendar owner allowlist — a non-allowlisted mailbox is refused before any Graph call. attendees may be ANY email address, including external client contacts (attendees are never the organizer, so they are not allowlist-checked). ticket_id is REQUIRED and must resolve to an existing ticket — the event is back-linked to it and a private audit note records the reason. reason is REQUIRED. Times are UTC ISO-8601 (tz defaults to UTC). Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => ['type' => 'string', 'description' => 'The organizer mailbox (UPN). Must be an allowlisted calendar owner.'],
                        'subject' => ['type' => 'string', 'description' => 'Event subject/title.'],
                        'start' => ['type' => 'string', 'description' => 'Start, ISO-8601 (UTC unless tz is given).'],
                        'end' => ['type' => 'string', 'description' => 'End, ISO-8601 (UTC unless tz is given).'],
                        'tz' => ['type' => 'string', 'description' => 'Optional IANA/Windows time zone for start/end. Defaults to UTC.'],
                        'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional attendee email addresses. May be external client contacts; each must be a valid email.'],
                        'body' => ['type' => 'string', 'description' => 'Optional plain-text event body/agenda.'],
                        'location' => ['type' => 'string', 'description' => 'Optional location display name.'],
                        'teams_meeting' => ['type' => 'boolean', 'description' => 'Set true to attach a Teams online meeting. Defaults to false.'],
                        'ticket_id' => ['type' => 'integer', 'description' => 'REQUIRED. The ticket this event traces to; the event is back-linked and a private audit note is written.'],
                        'reason' => ['type' => 'string', 'description' => 'REQUIRED. Short audit note for why this event is being created.'],
                    ],
                    'required' => ['user_upn', 'subject', 'start', 'end', 'ticket_id', 'reason'],
                ],
            ],
            [
                'name' => 'calendar_update_event',
                'description' => 'Update an existing calendar event on an owner mailbox (Microsoft Graph update event). user_upn is the owner mailbox and MUST be allowlisted (refused before any Graph call). Only scheduling fields may change — supply at least one of subject/start/end/tz/location/body/attendees; other event fields cannot be set. ticket_id and reason are REQUIRED (back-link + audit). Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => ['type' => 'string', 'description' => 'The owner mailbox (UPN). Must be an allowlisted calendar owner.'],
                        'event_id' => ['type' => 'string', 'description' => 'The Graph event id to update.'],
                        'subject' => ['type' => 'string', 'description' => 'Optional replacement subject.'],
                        'start' => ['type' => 'string', 'description' => 'Optional replacement start (ISO-8601).'],
                        'end' => ['type' => 'string', 'description' => 'Optional replacement end (ISO-8601).'],
                        'tz' => ['type' => 'string', 'description' => 'Optional time zone for start/end. Defaults to UTC.'],
                        'location' => ['type' => 'string', 'description' => 'Optional replacement location display name.'],
                        'body' => ['type' => 'string', 'description' => 'Optional replacement plain-text body.'],
                        'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional replacement attendee email list (external allowed).'],
                        'ticket_id' => ['type' => 'integer', 'description' => 'REQUIRED. The ticket this change traces to.'],
                        'reason' => ['type' => 'string', 'description' => 'REQUIRED. Short audit note for the change.'],
                    ],
                    'required' => ['user_upn', 'event_id', 'ticket_id', 'reason'],
                ],
            ],
            [
                'name' => 'calendar_cancel_event',
                'description' => 'Cancel a meeting the owner mailbox organizes (Microsoft Graph cancel — organizer-only; an attendee mailbox is rejected upstream). user_upn is the organizer mailbox and MUST be allowlisted (refused before any Graph call). An optional comment is sent to attendees. ticket_id and reason are REQUIRED (back-link + audit). Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => ['type' => 'string', 'description' => 'The organizer mailbox (UPN). Must be an allowlisted calendar owner.'],
                        'event_id' => ['type' => 'string', 'description' => 'The Graph event id to cancel.'],
                        'comment' => ['type' => 'string', 'description' => 'Optional cancellation message sent to attendees.'],
                        'ticket_id' => ['type' => 'integer', 'description' => 'REQUIRED. The ticket this cancellation traces to.'],
                        'reason' => ['type' => 'string', 'description' => 'REQUIRED. Short audit note for the cancellation.'],
                    ],
                    'required' => ['user_upn', 'event_id', 'ticket_id', 'reason'],
                ],
            ],
            [
                'name' => 'calendar_respond_event',
                'description' => 'Respond to a meeting invite AS the owner mailbox (Microsoft Graph accept/decline/tentativelyAccept). user_upn is the responding mailbox and MUST be allowlisted (refused before any Graph call). response is one of accept, decline, tentative. ticket_id and reason are REQUIRED (back-link + audit). Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_upn' => ['type' => 'string', 'description' => 'The responding mailbox (UPN). Must be an allowlisted calendar owner.'],
                        'event_id' => ['type' => 'string', 'description' => 'The Graph event id to respond to.'],
                        'response' => ['type' => 'string', 'enum' => ['accept', 'decline', 'tentative'], 'description' => 'The response to send.'],
                        'comment' => ['type' => 'string', 'description' => 'Optional comment included with the response.'],
                        'send_response' => ['type' => 'boolean', 'description' => 'Whether to send the response to the organizer. Defaults to true.'],
                        'ticket_id' => ['type' => 'integer', 'description' => 'REQUIRED. The ticket this response traces to.'],
                        'reason' => ['type' => 'string', 'description' => 'REQUIRED. Short audit note for the response.'],
                    ],
                    'required' => ['user_upn', 'event_id', 'response', 'ticket_id', 'reason'],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function toolNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    /**
     * Whether this executor owns the named tool (the controller's dispatch/grant gate). Covers the
     * published tools AND the staged twin dispatch names (calendar_stage_*) — the mode gate rewrites
     * a stageable call to its staged internal name before dispatch, so isCalendarTool() must route
     * that name here too. The staged twins are deliberately NOT in definitions() (dispatch-only, not
     * grantable/published), so they are recognised via stagedToDirectMap(), not toolNames().
     */
    public static function handles(string $name): bool
    {
        return in_array($name, self::toolNames(), true) || self::isStagedActionType($name);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        // The allowlist choke point — before ANY handler or Graph call, for every calendar tool
        // (reads AND writes; a staged write is still gated here, so you cannot even PARK a write to
        // a non-allowlisted mailbox — approval re-verifies it too, closing the TOCTOU window).
        if ($error = $this->guardOwnerUpn($name, $arguments)) {
            return $error;
        }

        // A staged twin (calendar_stage_*) parks the write for cockpit approval instead of
        // executing it. The immediate name executes now. Both are the SAME validated write — the
        // shared prepare/executeCalendarWrite seam guarantees identical behaviour either way.
        if (array_key_exists($name, self::stagedToDirectMap())) {
            return $this->stageWrite($name, $arguments, $actorLabel, $tokenLabel);
        }

        return match ($name) {
            'calendar_list_events' => $this->listEvents($arguments),
            'calendar_get_event' => $this->getEvent($arguments),
            'calendar_get_schedule' => $this->getScheduleAvailability($arguments),
            'calendar_create_event',
            'calendar_update_event',
            'calendar_cancel_event',
            'calendar_respond_event' => $this->immediateWrite($name, $arguments, $actorLabel),
            default => ['error' => "Unknown calendar tool: {$name}"],
        };
    }

    /**
     * Staged↔direct twin map (the McpToolModes contribution). Each write ships as an immediate
     * canonical name + a staged twin; McpToolModes::stagedToCanonical() merges this in so the mode
     * gate, grant catalog, and dispatch all agree. Mirrors StaffCippWriteToolExecutor.
     *
     * @return array<string, string>
     */
    public static function stagedToDirectMap(): array
    {
        return [
            'calendar_stage_create_event' => 'calendar_create_event',
            'calendar_stage_update_event' => 'calendar_update_event',
            'calendar_stage_cancel_event' => 'calendar_cancel_event',
            'calendar_stage_respond_event' => 'calendar_respond_event',
        ];
    }

    /** Whether $actionType is one of this executor's staged (held) action types. */
    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::stagedToDirectMap());
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
     * IMMEDIATE write path: validate → build the Graph body → execute NOW → back-link → audit. The
     * staged path (stageWrite/approveStagedRun) shares the SAME prepareWrite/executeCalendarWrite
     * seam, so a write behaves identically whether it runs now or after cockpit approval (the
     * manager's "behaviour must be identical either way").
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function immediateWrite(string $directTool, array $arguments, string $actorLabel): array
    {
        $upn = (string) $arguments['user_upn'];
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $prep = $this->prepareWrite($directTool, $arguments);
        if (isset($prep['error'])) {
            return $prep;
        }

        $contentHash = $this->contentHash($directTool, $upn, $prep['plan'], $ticket->id);
        $exec = $this->executeCalendarWrite($directTool, $upn, $prep['plan'], $ticket);
        $eventId = (string) ($exec['event']['id'] ?? $exec['event_id'] ?? '?');

        $this->backlinkNote($ticket, $this->writeBacklinkBody($directTool, $upn, $eventId, (string) $ctx['reason'], approved: false));
        $this->auditWrite($directTool, 'executed', $ticket, $contentHash, $prep['summary'].' — '.$ctx['reason'], $actorLabel, null, null);

        return array_merge([
            'success' => true,
            'action' => $this->actionVerb($directTool),
            'user_upn' => $upn,
            'ticket_id' => $ticket->id,
        ], $exec);
    }

    /**
     * Validate the verb-specific args and build its Graph execution PLAN — WITHOUT touching Graph.
     * The single source of truth for what a write does, consumed identically by the immediate path,
     * the staged encrypted payload, and the approval executor. Returns ['error'=>..] or
     * ['plan'=>[...], 'summary'=>string] (summary is the operator-facing proposal line).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function prepareWrite(string $directTool, array $arguments): array
    {
        return match ($directTool) {
            'calendar_create_event' => $this->prepareCreate($arguments),
            'calendar_update_event' => $this->prepareUpdate($arguments),
            'calendar_cancel_event' => $this->prepareCancel($arguments),
            'calendar_respond_event' => $this->prepareRespond($arguments),
            default => ['error' => "Unknown calendar write: {$directTool}"],
        };
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function prepareCreate(array $arguments): array
    {
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

        return ['plan' => ['body' => $body], 'summary' => sprintf('Create "%s" (%s → %s, %s)', $subject, $start, $end, $tz)];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function prepareUpdate(array $arguments): array
    {
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }

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

        return ['plan' => ['event_id' => $eventId, 'patch' => $patch], 'summary' => sprintf('Update event %s (%s)', $eventId, implode(', ', array_keys($patch)))];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function prepareCancel(array $arguments): array
    {
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }

        return ['plan' => ['event_id' => $eventId, 'comment' => $this->requiredString($arguments, 'comment')], 'summary' => sprintf('Cancel event %s', $eventId)];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function prepareRespond(array $arguments): array
    {
        $eventId = $this->requiredString($arguments, 'event_id');
        if ($eventId === null) {
            return ['error' => 'event_id is required.'];
        }
        $response = $this->requiredString($arguments, 'response');
        if ($response === null || ! in_array($response, ['accept', 'decline', 'tentative'], true)) {
            return ['error' => 'response is required and must be one of: accept, decline, tentative.'];
        }

        return [
            'plan' => [
                'event_id' => $eventId,
                'response' => $response,
                'comment' => $this->requiredString($arguments, 'comment'),
                'send_response' => filter_var($arguments['send_response'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
            'summary' => sprintf('Respond "%s" to event %s', $response, $eventId),
        ];
    }

    /**
     * THE ONE seam where a validated plan becomes a Graph call — used identically by the immediate
     * path and the approval executor, so a staged write executes exactly what an immediate one
     * would. create/update return the projected event; cancel/respond return the event_id.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function executeCalendarWrite(string $directTool, string $upn, array $plan, Ticket $ticket): array
    {
        if ($directTool === 'calendar_create_event') {
            $body = $plan['body'];
            // Deterministic transactionId — create is NON-IDEMPOTENT, so an accidental retry (or a
            // re-approval) must not double-book: Graph returns the FIRST event for a repeated
            // transactionId (idempotent-on-retry, user-post-events producer).
            $body['transactionId'] = hash('sha256', implode('|', [
                mb_strtolower($upn), (string) ($body['subject'] ?? ''),
                (string) ($body['start']['dateTime'] ?? ''), (string) ($body['end']['dateTime'] ?? ''), (string) $ticket->id,
            ]));

            return ['event' => $this->projectEvent($this->graph->createEvent($upn, $body))];
        }
        if ($directTool === 'calendar_update_event') {
            return ['event' => $this->projectEvent($this->graph->updateEvent($upn, $plan['event_id'], $plan['patch']))];
        }
        if ($directTool === 'calendar_cancel_event') {
            $this->graph->cancelEvent($upn, $plan['event_id'], $plan['comment'] ?? null);

            return ['event_id' => $plan['event_id']];
        }

        // calendar_respond_event
        $this->graph->respondEvent($upn, $plan['event_id'], $plan['response'], $plan['comment'] ?? null, $plan['send_response'] ?? true);

        return ['event_id' => $plan['event_id'], 'response' => $plan['response']];
    }

    /**
     * Content hash for the run idempotency key (ticket_id + action_type + content_hash is UNIQUE)
     * and the audit row. Deterministic from the write's identity — the same (owner, plan, ticket)
     * stages/audits as one action; distinct content gets its own row.
     *
     * @param  array<string, mixed>  $plan
     */
    private function contentHash(string $directTool, string $upn, array $plan, int $ticketId): string
    {
        return hash('sha256', (string) json_encode([$directTool, mb_strtolower($upn), $plan, $ticketId], JSON_THROW_ON_ERROR));
    }

    /**
     * STAGED write path (the owner's control): park the validated write as a TechnicianRun
     * (AwaitingApproval) with an ENCRYPTED held payload; the TechnicianRunObserver notifies the
     * operator automatically on the state transition (do NOT dispatch here). Nothing touches Graph
     * until approval. Mirrors StaffCippWriteToolExecutor — an external, non-idempotent write parked
     * for a human, NOT close_ticket's transactional lane.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function stageWrite(string $stagedName, array $arguments, string $actorLabel, ?string $tokenLabel): array
    {
        $directTool = self::stagedToDirectMap()[$stagedName];
        $upn = (string) $arguments['user_upn'];
        $ctx = $this->requireTicketAndReason($arguments);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        /** @var Ticket $ticket */
        $ticket = $ctx['ticket'];

        $prep = $this->prepareWrite($directTool, $arguments);
        if (isset($prep['error'])) {
            return $prep;
        }

        $reason = (string) $ctx['reason'];
        $plan = $prep['plan'];
        $contentHash = $this->contentHash($directTool, $upn, $plan, $ticket->id);

        $meta = [
            'drafted_by' => $actorLabel,
            'drafted_by_token' => $tokenLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            // The whole write intent is encrypted at rest — subject/body/attendees never sit in
            // plaintext on the run row. Decrypted only at approval, inside approveStagedRun.
            'encrypted_payload' => Crypt::encryptString((string) json_encode([
                'direct_tool' => $directTool,
                'user_upn' => $upn,
                'ticket_id' => $ticket->id,
                'reason' => $reason,
                'plan' => $plan,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $prep['summary']." in {$upn}\nReason: ".$reason;

        // Keyed on the DB idempotency invariant (ticket_id + action_type + content_hash UNIQUE):
        // identical content either doesn't exist yet (create) or exists but is no longer live
        // (revive it) — never a second colliding row.
        $run = TechnicianRun::firstOrCreate(
            ['ticket_id' => $ticket->id, 'action_type' => $stagedName, 'content_hash' => $contentHash],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            // A prior identical proposal was superseded/denied — revive THIS row as fresh awaiting.
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return ['success' => true, 'staged' => true, 'idempotent' => true, 'run_id' => $run->id, 'ticket_id' => $ticket->id, 'message' => 'Identical calendar write already staged; awaiting approval.'];
        }

        $this->auditWrite($stagedName, 'awaiting_approval', $ticket, $contentHash, $prep['summary'].' — '.$reason, $actorLabel, $run->id, null);

        return ['success' => true, 'staged' => true, 'run_id' => $run->id, 'ticket_id' => $ticket->id, 'message' => 'Calendar write staged; awaiting operator approval in the cockpit.'];
    }

    /**
     * Execute an operator-approved staged calendar write. claim (single-use CAS) → decrypt →
     * RE-VERIFY the owner allowlist AND the master switch AT APPROVAL (the TOCTOU close: the
     * allowlist can change between staging and approval, and it is the one boundary that matters on
     * a tenant-wide Calendars.ReadWrite token) → execute the SAME plan an immediate call would →
     * back-link → audit → Done. On any failure the claim is released so the run is never stranded
     * Executing. External/non-idempotent, so it is deliberately absent from
     * TechnicianRun::RECOVERY_SAFE_ACTION_TYPES (a stranded run is flagged for manual review, never
     * auto-reopened — that would risk a double-book).
     */
    public function approveStagedRun(TechnicianRun $run, int $approverId, array $approvalInputs = []): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            $payload = $this->decryptRunPayload($run);
            $directTool = is_array($payload) ? (string) ($payload['direct_tool'] ?? '') : '';
            $upn = is_array($payload) ? (string) ($payload['user_upn'] ?? '') : '';
            $plan = is_array($payload) && is_array($payload['plan'] ?? null) ? $payload['plan'] : null;

            if ($plan === null || $upn === '' || (self::stagedToDirectMap()[$run->action_type] ?? null) !== $directTool) {
                $run->releaseClaim();

                return $this->declined('The held calendar payload could not be read or does not match this action; deny this proposal and re-stage it.');
            }

            // *** TOCTOU RE-VERIFY at approval time — not only at stage time. ***
            if (! CalendarConfig::isEnabled()) {
                $this->auditWrite($run->action_type, 'blocked', $run->ticket, $run->content_hash, 'Calendar toolset disabled at approval time.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The calendar toolset is now disabled in this deployment; the staged write was refused.');
            }
            if (! CalendarConfig::ownerUpnAllowed($upn)) {
                $this->auditWrite($run->action_type, 'blocked', $run->ticket, $run->content_hash, "Owner {$upn} no longer allowlisted at approval time.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined("Calendar owner {$upn} is no longer on the allowlist; the staged write was refused. Add it back (or deny and re-stage) if this is still intended.");
            }

            $ticket = Ticket::find((int) ($payload['ticket_id'] ?? 0));
            if ($ticket === null) {
                $run->releaseClaim();

                return $this->declined('The ticket this write traced to no longer exists; deny this proposal and re-stage it.');
            }

            try {
                $exec = $this->executeCalendarWrite($directTool, $upn, $plan, $ticket);
            } catch (GraphClientException $e) {
                $this->auditWrite($run->action_type, 'error', $ticket, $run->content_hash, 'Upstream Microsoft Graph calendar write failed at approval.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The calendar write failed upstream (Microsoft Graph); nothing was changed. Re-approve to retry.');
            }

            $eventId = (string) ($exec['event']['id'] ?? $exec['event_id'] ?? '?');
            $this->backlinkNote($ticket, $this->writeBacklinkBody($directTool, $upn, $eventId, (string) ($payload['reason'] ?? ''), approved: true));
            $this->auditWrite($run->action_type, 'executed', $ticket, $run->content_hash, 'Operator-approved calendar write executed.', $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed', message: 'Calendar write executed after approval.');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /** Decrypt the held write intent from the run's encrypted payload. Null on any tamper/absence. */
    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $payload = json_decode(Crypt::decryptString($ciphertext), true);

        return is_array($payload) ? $payload : null;
    }

    private function declined(string $reason): TechnicianApprovalResult
    {
        return new TechnicianApprovalResult('gate_declined', message: mb_substr($reason, 0, 300));
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /**
     * Append the forensic technician_action_log row (append-only, separate from the controller's
     * mcp_audit_logs transport audit). Lean version of the CIPP auditAttempt — no person/license
     * target. $resultStatus ∈ awaiting_approval|executed|blocked|error.
     */
    private function auditWrite(string $actionType, string $resultStatus, ?Ticket $ticket, string $contentHash, string $summary, string $actorLabel, ?int $runId, ?int $approverId): void
    {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticket?->id,
            'client_id' => $ticket?->client_id,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => mb_substr($summary, 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    /** The human-readable back-link note body dropped on the ticket, for immediate and approved writes alike. */
    private function writeBacklinkBody(string $directTool, string $upn, string $eventId, string $reason, bool $approved): string
    {
        $verb = match ($directTool) {
            'calendar_create_event' => 'created',
            'calendar_update_event' => 'updated',
            'calendar_cancel_event' => 'cancelled',
            'calendar_respond_event' => 'responded to',
            default => 'acted on',
        };

        return sprintf('AI technician %s calendar event %s in %s%s. Reason: %s', $verb, $eventId, $upn, $approved ? ' (operator-approved)' : '', $reason);
    }

    private function actionVerb(string $directTool): string
    {
        return match ($directTool) {
            'calendar_create_event' => 'created',
            'calendar_update_event' => 'updated',
            'calendar_cancel_event' => 'cancelled',
            'calendar_respond_event' => 'responded',
            default => 'done',
        };
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
