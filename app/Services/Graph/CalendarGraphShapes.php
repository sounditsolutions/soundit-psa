<?php

namespace App\Services\Graph;

use stdClass;

/**
 * Strict fail-loud validation for Microsoft Graph CALENDAR read payloads (psa-abl0i.2/.4/.5
 * architecture + security re-reviews), mirroring the ServosityShapes seam.
 *
 * CLAUDE.md hard vendor rule: "A degraded read must SCREAM, never return a clean empty result."
 * The most dangerous false-clear this toolset can produce is a getSchedule free/busy grid that
 * silently drops a mailbox, swallows a per-mailbox error, or carries no availability data — it
 * reads as "that person is FREE" and an agent cannot tell it apart from a real all-clear.
 *
 * IDENTITY-PRESERVING INPUT (the round-2 fix): consumers hand us the OBJECT-mode json_decode
 * (a JSON object → stdClass, a JSON list → array), NOT the assoc-mode decode. Assoc mode collapses
 * `{}` to `[]`, so a malformed object envelope (`"value": {}`) would read as an empty result and a
 * `[{}]` row would pass unchecked. Distinguishing object-from-list here is what lets a malformed
 * success payload SCREAM instead of looking empty. Every consumed value is proven, then the
 * validated payload is returned as clean assoc arrays for the projection layer (unchanged).
 *
 * Shapes cited from the MS Graph v1.0 producer (NOT guessed — CLAUDE.md "read the source"):
 *  - scheduleInformation: https://learn.microsoft.com/en-us/graph/api/resources/scheduleinformation
 *    and https://learn.microsoft.com/en-us/graph/api/calendar-getschedule
 *  - event resource:      https://learn.microsoft.com/en-us/graph/api/resources/event
 */
final class CalendarGraphShapes
{
    /**
     * Prove an (object-mode decoded) getSchedule response and return its scheduleInformation rows
     * as assoc arrays, validated 1:1 against the REQUESTED mailboxes. Throws on any drift:
     *  - the top-level not the documented `{value:[...]}` object, or `value` not a genuine JSON list;
     *  - a row not an object, or lacking a non-empty string scheduleId;
     *  - ANY present per-mailbox `error` (freeBusyError) — availability UNKNOWN, refuse the whole
     *    read and name the mailbox (Fork-3 "no partial grid");
     *  - a row missing the availability-bearing fields (a non-empty string availabilityView AND a
     *    list scheduleItems) — a row with only scheduleId must SCREAM, never project as
     *    availability_view=null + busy_blocks=[] (which reads as all-clear);
     *  - a malformed busy block (non-object, no string status, or a start/end that is not a
     *    dateTimeTimeZone object with a string dateTime);
     *  - a requested mailbox missing, an unrequested mailbox present, or a duplicate.
     *
     * @param  list<string>  $requestedUpns  the mailboxes asked for (already allowlist-proven)
     * @return list<array<string, mixed>>
     */
    public static function assertScheduleCollection(mixed $response, array $requestedUpns): array
    {
        if (! $response instanceof stdClass || ! isset($response->value)) {
            throw new GraphShapeDriftException('Microsoft Graph getSchedule response is not the documented {value:[...]} object — refusing to read a malformed envelope as an empty/all-free grid.');
        }
        if (! is_array($response->value) || ! array_is_list($response->value)) {
            throw new GraphShapeDriftException('Microsoft Graph getSchedule "value" is not a JSON list — refusing to read a malformed envelope as an empty grid.');
        }

        /** @var array<string, true> $seen scheduleId (lowercased) => present */
        $seen = [];
        $rows = [];
        foreach ($response->value as $row) {
            $rows[] = self::assertScheduleRow($row, $seen);
        }

        // Reconcile 1:1 with the requested set — no missing, no unrequested extras.
        /** @var array<string, string> $requested lowercased => original */
        $requested = [];
        foreach ($requestedUpns as $upn) {
            $requested[mb_strtolower(trim((string) $upn))] = (string) $upn;
        }
        foreach ($requested as $key => $upn) {
            if (! isset($seen[$key])) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule did not return availability for requested mailbox {$upn} — a grid missing a requested mailbox must not be read as complete.");
            }
        }
        foreach (array_keys($seen) as $key) {
            if (! isset($requested[$key])) {
                throw new GraphShapeDriftException('Microsoft Graph getSchedule returned availability for a mailbox that was not requested — refusing an unexpected grid.');
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private static function assertScheduleRow(mixed $row, array &$seen): array
    {
        if (! $row instanceof stdClass) {
            throw new GraphShapeDriftException('Microsoft Graph getSchedule returned a scheduleInformation entry that is not an object.');
        }

        $scheduleId = $row->scheduleId ?? null;
        if (! is_string($scheduleId) || trim($scheduleId) === '') {
            throw new GraphShapeDriftException('Microsoft Graph getSchedule returned a scheduleInformation row without a string scheduleId — the mailbox it describes cannot be identified.');
        }

        // ANY present error means availability is UNKNOWN for that mailbox — never read it as free.
        if (isset($row->error) && $row->error !== null) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule returned an error for mailbox {$scheduleId}; its availability is unknown, so the whole free/busy read is refused rather than shown as free.");
        }

        // REQUIRE the availability-bearing fields. A row with only scheduleId would otherwise
        // project as availability_view=null + busy_blocks=[] and read as all-clear.
        if (! isset($row->availabilityView) || ! is_string($row->availabilityView) || $row->availabilityView === '') {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule row for {$scheduleId} has no non-empty availabilityView string — a degraded row must not read as free.");
        }
        if (! isset($row->scheduleItems) || ! is_array($row->scheduleItems) || ! array_is_list($row->scheduleItems)) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule row for {$scheduleId} has a scheduleItems that is not a JSON list.");
        }
        foreach ($row->scheduleItems as $item) {
            self::assertScheduleItem($item, $scheduleId);
        }

        // workingHours is OPTIONAL (a mailbox may omit it) but IS consumed by the projection, so a
        // present shape is proven; absence is not drift. (isset() is false for a null value.)
        if (isset($row->workingHours)) {
            self::assertWorkingHours($row->workingHours, $scheduleId);
        }

        $key = mb_strtolower(trim($scheduleId));
        if (isset($seen[$key])) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule returned mailbox {$scheduleId} more than once — an ambiguous grid must not be read as complete.");
        }
        $seen[$key] = true;

        return self::toArray($row);
    }

    /** Validate the consumed nested shape of one busy block (status + start/end windows). */
    private static function assertScheduleItem(mixed $item, string $scheduleId): void
    {
        if (! $item instanceof stdClass) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule busy block for {$scheduleId} is not an object.");
        }
        if (! isset($item->status) || ! is_string($item->status)) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule busy block for {$scheduleId} has no string status.");
        }
        self::assertDateTimeTimeZone($item->start ?? null, $scheduleId, 'start');
        self::assertDateTimeTimeZone($item->end ?? null, $scheduleId, 'end');
    }

    private static function assertDateTimeTimeZone(mixed $dt, string $scheduleId, string $which): void
    {
        if (! $dt instanceof stdClass || ! isset($dt->dateTime) || ! is_string($dt->dateTime)) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule busy block for {$scheduleId} has a malformed {$which} (expected a dateTimeTimeZone object with a string dateTime).");
        }
    }

    /**
     * Validate a consumed workingHours block WHEN PRESENT. projectSchedule reads it through
     * projectWorkingHours(?array) — daysOfWeek / startTime / endTime / timeZone.name — so a present-
     * but-malformed shape (e.g. a JSON string, or a non-list daysOfWeek) would project garbage or
     * TypeError the tool. Each sub-field is proven only when present; a mailbox may legitimately omit
     * the whole block or any field (must-fix psa-abl0i.5 #3 "validate workingHours shape").
     * Source: MS Graph v1.0 workingHours — https://learn.microsoft.com/en-us/graph/api/resources/workinghours
     */
    private static function assertWorkingHours(mixed $wh, string $scheduleId): void
    {
        if (! $wh instanceof stdClass) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule workingHours for {$scheduleId} is present but is not an object.");
        }
        if (isset($wh->daysOfWeek)) {
            if (! is_array($wh->daysOfWeek) || ! array_is_list($wh->daysOfWeek)) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule workingHours.daysOfWeek for {$scheduleId} is not a JSON list.");
            }
            foreach ($wh->daysOfWeek as $day) {
                if (! is_string($day)) {
                    throw new GraphShapeDriftException("Microsoft Graph getSchedule workingHours.daysOfWeek for {$scheduleId} has a non-string day.");
                }
            }
        }
        foreach (['startTime', 'endTime'] as $field) {
            if (isset($wh->{$field}) && ! is_string($wh->{$field})) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule workingHours.{$field} for {$scheduleId} is present but is not a string.");
            }
        }
        if (isset($wh->timeZone) && ! $wh->timeZone instanceof stdClass) {
            throw new GraphShapeDriftException("Microsoft Graph getSchedule workingHours.timeZone for {$scheduleId} is present but is not an object.");
        }
    }

    /**
     * Prove one (object-mode decoded) calendarView page and return its events as assoc arrays.
     * `value` must be a genuine JSON list (a `{}` object here is drift, not an empty calendar), and
     * EVERY event is proven through assertEvent so a `[{}]` / `[[]]` malformed row cannot pass.
     *
     * @return list<array<string, mixed>>
     */
    public static function assertCalendarPage(mixed $page): array
    {
        if (! $page instanceof stdClass || ! isset($page->value)) {
            throw new GraphShapeDriftException('Microsoft Graph calendarView page is not the documented {value:[...]} object — refusing to read a malformed page as an empty calendar.');
        }
        if (! is_array($page->value) || ! array_is_list($page->value)) {
            throw new GraphShapeDriftException('Microsoft Graph calendarView "value" is not a JSON list — refusing to read a malformed page as an empty calendar.');
        }

        $events = [];
        foreach ($page->value as $event) {
            $events[] = self::assertEvent($event);
        }

        return $events;
    }

    /**
     * Prove a present @odata.nextLink on a (validated) calendarView page before it can decide list
     * completeness or steer a request. Returns null ONLY when the property is ABSENT (the documented
     * end of the list), the URL when it is a well-formed HTTPS graph.microsoft.com calendarView
     * continuation, and THROWS otherwise — a PRESENT "@odata.nextLink": null / empty string / false /
     * 0 / non-string cursor must not read as "end of list", and a cursor must not be followed with the
     * tenant app bearer token unless it both targets Graph over HTTPS AND continues THIS collection.
     *
     * ABSENT vs PRESENT-NULL (must-fix psa-abl0i.7 #2): `?? null` / isset() conflate an absent property
     * (real end) with a present JSON null (drift), so a drifted present-null could silently end
     * pagination on a truncated calendar. property_exists() distinguishes them: absence ends the list,
     * a present value must prove out or SCREAM (CLAUDE.md "a degraded read must SCREAM").
     *
     * CONTINUATION-PATH BOUND (must-fix psa-abl0i.4 #4): the path must end in /calendarView. Graph's
     * OData paging keeps the resource path stable and carries the cursor in the query ($skip/
     * $skiptoken), so a legitimate continuation always ends /calendarView; a same-host pivot to
     * /messages, /events/{id}, etc. does not and is refused. We DELIBERATELY do NOT pin the mailbox
     * segment: we have no captured live calendarView nextLink to confirm how Graph echoes the user id
     * (UPN vs object-id vs users('..') form), and pinning a guessed representation would break valid
     * pagination (the CLAUDE.md "don't guess the vendor shape" rule). The bearer-exfil control — the
     * actual psa-abl0i.4 finding — is carried by the host+scheme check; a realistic MITM/Graph
     * compromise that could forge a same-host cross-mailbox cursor could equally forge page-1 data,
     * so mailbox-path pinning would add no real protection here.
     */
    public static function provenNextLink(mixed $page): ?string
    {
        if (! $page instanceof stdClass || ! property_exists($page, '@odata.nextLink')) {
            return null; // absent property = the documented end of the list
        }

        $next = $page->{'@odata.nextLink'};
        if (! is_string($next) || $next === '') {
            throw new GraphShapeDriftException('Microsoft Graph calendarView returned a present @odata.nextLink that is null / non-string / empty — a malformed cursor must not be treated as the end of the list.');
        }

        $parts = parse_url($next);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host !== 'graph.microsoft.com') {
            throw new GraphShapeDriftException('Microsoft Graph calendarView @odata.nextLink is not an https graph.microsoft.com URL — refusing to follow an unexpected cursor with the app token.');
        }

        $path = (string) ($parts['path'] ?? '');
        if (! str_ends_with($path, '/calendarView')) {
            throw new GraphShapeDriftException('Microsoft Graph @odata.nextLink does not continue the calendarView collection (its path does not end in /calendarView) — refusing to follow a cursor that pivots to another resource with the app token.');
        }

        return $next;
    }

    /**
     * Prove a (object-mode decoded) event resource is a well-formed object carrying a non-empty
     * string id, then return it as an assoc array. A malformed/empty success body ([] / {} / a row
     * without id) must SCREAM, never project into an all-null event.
     *
     * @return array<string, mixed>
     */
    public static function assertEvent(mixed $event): array
    {
        if (! $event instanceof stdClass || ! isset($event->id) || ! is_string($event->id) || trim($event->id) === '') {
            throw new GraphShapeDriftException('Microsoft Graph event payload has no string id — refusing to read a malformed event as valid.');
        }

        return self::toArray($event);
    }

    /**
     * Deep-convert a validated stdClass to assoc arrays for the projection layer, which reads
     * nested fields by array key. The value has already been proven, so the round-trip is total.
     *
     * @return array<string, mixed>
     */
    private static function toArray(stdClass $value): array
    {
        $decoded = json_decode((string) json_encode($value), true);

        return is_array($decoded) ? $decoded : [];
    }
}
