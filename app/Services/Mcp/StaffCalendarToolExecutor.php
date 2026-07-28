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
 * NOTE (fixtures): the projection field names are the documented MS Graph v1.0 event resource
 * (camelCase). Per CLAUDE.md they should be re-verified against a CAPTURED live calendarView
 * payload before this ships to a live surface (tracked on psa-abl0i).
 */
class StaffCalendarToolExecutor
{
    public function __construct(private readonly GraphClient $graph) {}

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        // The allowlist choke point — before ANY handler or Graph call, for every calendar tool.
        if ($error = $this->guardOwnerUpn($arguments)) {
            return $error;
        }

        return match ($name) {
            'calendar_list_events' => $this->listEvents($arguments),
            'calendar_get_event' => $this->getEvent($arguments),
            default => ['error' => "Unknown calendar tool: {$name}"],
        };
    }

    /**
     * The single allowlist enforcement seam. Refuses a disabled toolset, a missing/blank
     * user_upn, or a mailbox outside CalendarConfig's owner allowlist — fail-closed, before any
     * Graph call. Returns an error array to short-circuit, or null to proceed.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function guardOwnerUpn(array $arguments): ?array
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
