<?php

namespace App\Services\Graph;

/**
 * Strict fail-loud validation for Microsoft Graph CALENDAR read payloads (psa-abl0i.2
 * architecture review), mirroring the ServosityShapes seam.
 *
 * CLAUDE.md hard vendor rule: "A degraded read must SCREAM, never return a clean empty result."
 * The most dangerous false-clear this toolset can produce is a getSchedule free/busy grid that
 * silently drops a mailbox or swallows a per-mailbox error — it reads as "that person is FREE"
 * and an agent cannot tell it apart from a real all-clear. So every consumed Graph calendar
 * payload is proven here before it becomes availability data; anything malformed throws
 * GraphShapeDriftException (which the staff MCP controller already surfaces as a LOUD isError
 * tool result), never a partial/empty grid that looks complete.
 *
 * Shapes cited from the MS Graph v1.0 producer (NOT guessed — CLAUDE.md "read the source"):
 *  - scheduleInformation: https://learn.microsoft.com/en-us/graph/api/calendar-getschedule
 *  - event resource:      https://learn.microsoft.com/en-us/graph/api/resources/event
 */
final class CalendarGraphShapes
{
    /**
     * Prove a getSchedule response and return its scheduleInformation rows, validated 1:1 against
     * the REQUESTED mailboxes. Throws GraphShapeDriftException on any drift:
     *  - the OData `value` envelope missing or not a list (never read as an empty/all-free grid);
     *  - a row that is not an object, or lacks a non-empty string scheduleId;
     *  - a per-mailbox `error` (freeBusyError) — that mailbox's availability is UNKNOWN and must
     *    never read as free; the whole call is refused, naming the mailbox (Fork-3 "no partial grid");
     *  - availabilityView present but not a string, or scheduleItems present but not an array;
     *  - a requested mailbox missing, an unrequested mailbox present, or a duplicate — a grid that
     *    is not exactly the requested set cannot be read as complete.
     * A row's empty scheduleItems [] is a valid "no busy blocks", not drift; a genuinely empty
     * requested set is impossible (the executor guard requires ≥1 allowlisted mailbox).
     *
     * @param  list<string>  $requestedUpns  the mailboxes asked for (already allowlist-proven)
     * @return list<array<string, mixed>>
     */
    public static function assertScheduleCollection(mixed $response, array $requestedUpns): array
    {
        if (! is_array($response) || ! array_key_exists('value', $response) || ! is_array($response['value'])) {
            throw new GraphShapeDriftException('Microsoft Graph getSchedule returned no scheduleInformation array (the OData value envelope is missing or malformed) — refusing to read it as an empty/all-free grid.');
        }

        /** @var array<string, true> $seen scheduleId (lowercased) => present */
        $seen = [];

        foreach ($response['value'] as $row) {
            if (! is_array($row)) {
                throw new GraphShapeDriftException('Microsoft Graph getSchedule returned a scheduleInformation entry that is not an object.');
            }

            $scheduleId = $row['scheduleId'] ?? null;
            if (! is_string($scheduleId) || trim($scheduleId) === '') {
                throw new GraphShapeDriftException('Microsoft Graph getSchedule returned a scheduleInformation row without a string scheduleId — the mailbox it describes cannot be identified.');
            }

            // A per-mailbox freeBusyError means availability is UNKNOWN for that mailbox. Never let
            // an errored mailbox read as free — refuse the whole read and name it (Fork-3 semantics).
            if (isset($row['error']) && $row['error'] !== null && $row['error'] !== []) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule returned an error for mailbox {$scheduleId}; its availability is unknown, so the whole free/busy read is refused rather than shown as free.");
            }

            if (array_key_exists('availabilityView', $row) && $row['availabilityView'] !== null && ! is_string($row['availabilityView'])) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule returned a non-string availabilityView for mailbox {$scheduleId}.");
            }

            if (array_key_exists('scheduleItems', $row) && $row['scheduleItems'] !== null && ! is_array($row['scheduleItems'])) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule returned a non-array scheduleItems for mailbox {$scheduleId}.");
            }

            $key = mb_strtolower(trim($scheduleId));
            if (isset($seen[$key])) {
                throw new GraphShapeDriftException("Microsoft Graph getSchedule returned mailbox {$scheduleId} more than once — an ambiguous grid must not be read as complete.");
            }
            $seen[$key] = true;
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

        return array_values($response['value']);
    }

    /**
     * Prove one paged calendarView response body carries the OData `value` array, and return it.
     * A malformed page must not read as "no events".
     *
     * @return array<int, mixed>
     */
    public static function assertPageValue(mixed $page, string $endpoint): array
    {
        if (! is_array($page) || ! array_key_exists('value', $page) || ! is_array($page['value'])) {
            throw new GraphShapeDriftException("Microsoft Graph {$endpoint} returned a page without a value array — refusing to read a malformed page as an empty result.");
        }

        return $page['value'];
    }

    /**
     * Prove a getEvent response is a well-formed event resource (a JSON object carrying a non-empty
     * string id), not a malformed/empty success body that would project into an all-null event.
     *
     * @return array<string, mixed>
     */
    public static function assertEvent(mixed $event): array
    {
        if (! is_array($event) || ! isset($event['id']) || ! is_string($event['id']) || trim($event['id']) === '') {
            throw new GraphShapeDriftException('Microsoft Graph getEvent returned a payload without a string event id — refusing to read a malformed event as valid.');
        }

        return $event;
    }
}
