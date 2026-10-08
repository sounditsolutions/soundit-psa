<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\CalendarGraphShapes;
use App\Services\Graph\GraphShapeDriftException;
use Tests\TestCase;

/**
 * Fail-loud validation of Microsoft Graph calendar read shapes (psa-abl0i.2/.4/.5). The dangerous
 * false-clear is a getSchedule grid that silently drops a mailbox, swallows a per-mailbox error, or
 * carries no availability data — it reads as "that person is FREE". Every drift MUST throw, never
 * collapse to a clean grid/empty calendar (CLAUDE.md "a degraded read must SCREAM").
 *
 * IDENTITY-PRESERVING FIXTURES: the validator consumes the OBJECT-mode json_decode, so fixtures are
 * built the way the wire decodes — wire() runs a PHP structure through json_encode+json_decode so a
 * (object) cast becomes a JSON object (stdClass) and a [] list becomes a JSON array. That is what
 * lets these tests exercise the object-vs-list distinction the assoc decode used to erase. Shapes
 * are constructed to the documented MS Graph v1.0 scheduleInformation / event contract
 * (learn.microsoft.com/graph/api/resources/scheduleinformation, /resources/event) — not a captured
 * private payload, and not described as one.
 */
class CalendarGraphShapesTest extends TestCase
{
    /** Decode a PHP structure the way the wire would: (object) → JSON object → stdClass; [] → list. */
    private function wire(mixed $php): mixed
    {
        return json_decode((string) json_encode($php));
    }

    private function scheduleRow(string $upn, string $view = '002200'): object
    {
        return (object) [
            'scheduleId' => $upn,
            'availabilityView' => $view,
            'scheduleItems' => [
                (object) [
                    'status' => 'busy',
                    'start' => (object) ['dateTime' => '2026-07-28T14:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => (object) ['dateTime' => '2026-07-28T15:00:00.0000000', 'timeZone' => 'UTC'],
                ],
            ],
            'workingHours' => (object) ['daysOfWeek' => ['monday'], 'startTime' => '08:00:00.0000000', 'endTime' => '17:00:00.0000000', 'timeZone' => (object) ['name' => 'UTC']],
        ];
    }

    /** @param list<object> $rows */
    private function scheduleResponse(array $rows): mixed
    {
        return $this->wire((object) ['value' => $rows]);
    }

    // ---- assertScheduleCollection: happy paths ----

    public function test_valid_grid_returns_assoc_rows_for_exactly_the_requested_mailboxes(): void
    {
        $rows = CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test'), $this->scheduleRow('b4k2.second@synthetic.test', '000000')]),
            ['b4k2.first@synthetic.test', 'b4k2.second@synthetic.test'],
        );

        $this->assertCount(2, $rows);
        $this->assertSame('b4k2.first@synthetic.test', $rows[0]['scheduleId']);
        // Deep-converted to assoc arrays for the projection layer.
        $this->assertSame('busy', $rows[0]['scheduleItems'][0]['status']);
        $this->assertSame('2026-07-28T14:00:00.0000000', $rows[0]['scheduleItems'][0]['start']['dateTime']);
    }

    public function test_case_insensitive_reconciliation(): void
    {
        $rows = CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('B4K2.First@Synthetic.TEST')]),
            ['b4k2.first@synthetic.test'],
        );
        $this->assertCount(1, $rows);
    }

    public function test_empty_schedule_items_with_valid_availability_view_is_ok(): void
    {
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        $row->scheduleItems = []; // free mailbox: no busy blocks, but availabilityView is present
        $rows = CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
        $this->assertSame([], $rows[0]['scheduleItems']);
    }

    // ---- assertScheduleCollection: workingHours (consumed by projectSchedule) ----

    public function test_absent_working_hours_is_ok(): void
    {
        // workingHours is validated ONLY when present — a mailbox may legitimately omit it, and a
        // missing working-hours block is a constraint we lack, not a false-clear.
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        unset($row->workingHours);
        $rows = CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
        $this->assertArrayNotHasKey('workingHours', $rows[0]);
    }

    public function test_present_but_malformed_working_hours_screams(): void
    {
        // workingHours IS consumed by projectSchedule/projectWorkingHours(?array) — a present-but-
        // malformed shape would project garbage or TypeError the tool, so it must scream (must-fix
        // psa-abl0i.5 #3 "validate workingHours shape").
        $bads = [
            'not-an-object',                                                                                                                        // workingHours not an object
            (object) ['daysOfWeek' => (object) ['x' => 'monday'], 'startTime' => '08:00:00', 'endTime' => '17:00:00', 'timeZone' => (object) ['name' => 'UTC']], // daysOfWeek not a list
            (object) ['daysOfWeek' => [1, 2], 'startTime' => '08:00:00', 'endTime' => '17:00:00', 'timeZone' => (object) ['name' => 'UTC']],          // non-string day
            (object) ['daysOfWeek' => ['monday'], 'startTime' => ['nope'], 'endTime' => '17:00:00', 'timeZone' => (object) ['name' => 'UTC']],        // non-string startTime
            (object) ['daysOfWeek' => ['monday'], 'startTime' => '08:00:00', 'endTime' => '17:00:00', 'timeZone' => 'UTC'],                            // timeZone not an object
        ];
        foreach ($bads as $bad) {
            $row = $this->scheduleRow('b4k2.first@synthetic.test');
            $row->workingHours = $bad;
            try {
                CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
                $this->fail('Expected drift for malformed workingHours='.json_encode($bad));
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- assertScheduleCollection: drift (object-vs-list + envelope) ----

    public function test_top_level_list_instead_of_object_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->wire([]), ['b4k2.first@synthetic.test']);
    }

    public function test_value_as_empty_json_object_screams_not_empty_grid(): void
    {
        // {"value":{}} — assoc decode would collapse to [] and read as an empty grid.
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->wire((object) ['value' => (object) []]), ['b4k2.first@synthetic.test']);
    }

    public function test_value_as_populated_json_object_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->wire((object) ['value' => (object) ['0' => 'x']]), ['b4k2.first@synthetic.test']);
    }

    // ---- assertScheduleCollection: drift (missing availability = false all-clear) ----

    public function test_row_with_only_schedule_id_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([(object) ['scheduleId' => 'b4k2.first@synthetic.test']]),
            ['b4k2.first@synthetic.test'],
        );
    }

    public function test_missing_or_empty_or_nonstring_availability_view_screams(): void
    {
        foreach ([null, '', ['not', 'a', 'string']] as $bad) {
            $row = $this->scheduleRow('b4k2.first@synthetic.test');
            if ($bad === null) {
                unset($row->availabilityView);
            } else {
                $row->availabilityView = $bad;
            }
            try {
                CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
                $this->fail('Expected drift for availabilityView='.json_encode($bad));
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_non_list_schedule_items_screams(): void
    {
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        $row->scheduleItems = (object) ['a' => 'b'];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
    }

    /** #5732: the per-mailbox error names the row by position, not by the mailbox address. */
    public function test_per_mailbox_error_refuses_the_whole_read_and_names_its_row(): void
    {
        $row = $this->scheduleRow('b4k2.second@synthetic.test');
        $row->error = (object) ['message' => 'ErrorMailboxMoveInProgress', 'responseCode' => 'MailboxMoveInProgress'];
        try {
            CalendarGraphShapes::assertScheduleCollection(
                $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test'), $row]),
                ['b4k2.first@synthetic.test', 'b4k2.second@synthetic.test'],
            );
            $this->fail('Expected drift for a per-mailbox error');
        } catch (GraphShapeDriftException $e) {
            $this->assertSame('Microsoft Graph getSchedule returned an error for the mailbox in row 1; its availability is unknown, so the whole free/busy read is refused rather than shown as free.', $e->getMessage());
            $this->assertStringNotContainsString('b4k2.second', $e->getMessage());
        }
    }

    /**
     * #5732: [mutate row 1 (or the request), exact message]. Every getSchedule drift that used to
     * interpolate {$upn} or {$scheduleId}; the row under test is the second of two, so 'row 1'
     * and position 1 are the identifiers, and the mailbox is in no message.
     *
     * @return array<string, array{0: \Closure(object): void, 1: string}>
     */
    public static function rowDrifts(): array
    {
        $g = 'Microsoft Graph getSchedule ';
        $wh = fn (array $fields) => function (object $row) use ($fields): void {
            foreach ($fields as $k => $v) {
                $row->workingHours->{$k} = $v;
            }
        };

        return [
            'per-mailbox error' => [fn (object $r) => $r->error = (object) ['responseCode' => 'ErrorMailboxMoveInProgress'], $g.'returned an error for the mailbox in row 1; its availability is unknown, so the whole free/busy read is refused rather than shown as free.'],
            'no availabilityView' => [function (object $r): void {
                unset($r->availabilityView);
            }, $g.'row 1 has no non-empty availabilityView string — a degraded row must not read as free.'],
            'scheduleItems not a list' => [fn (object $r) => $r->scheduleItems = (object) ['a' => 'b'], $g.'row 1 has a scheduleItems that is not a JSON list.'],
            'busy block not an object' => [fn (object $r) => $r->scheduleItems = ['busy'], $g.'busy block in row 1 is not an object.'],
            'busy block without status' => [fn (object $r) => $r->scheduleItems[0]->status = null, $g.'busy block in row 1 has no string status.'],
            'busy block start malformed' => [fn (object $r) => $r->scheduleItems[0]->start = 'x', $g.'busy block in row 1 has a malformed start (expected a dateTimeTimeZone object with a string dateTime).'],
            'busy block end malformed' => [fn (object $r) => $r->scheduleItems[0]->end = (object) ['timeZone' => 'UTC'], $g.'busy block in row 1 has a malformed end (expected a dateTimeTimeZone object with a string dateTime).'],
            'workingHours not an object' => [fn (object $r) => $r->workingHours = 'x', $g.'workingHours in row 1 is present but is not an object.'],
            'daysOfWeek not a list' => [$wh(['daysOfWeek' => (object) ['x' => 'monday']]), $g.'workingHours.daysOfWeek in row 1 is not a JSON list.'],
            'non-string day' => [$wh(['daysOfWeek' => [1]]), $g.'workingHours.daysOfWeek in row 1 has a non-string day.'],
            'startTime not a string' => [$wh(['startTime' => ['x']]), $g.'workingHours.startTime in row 1 is present but is not a string.'],
            'endTime not a string' => [$wh(['endTime' => 1700]), $g.'workingHours.endTime in row 1 is present but is not a string.'],
            'timeZone not an object' => [$wh(['timeZone' => 'UTC']), $g.'workingHours.timeZone in row 1 is present but is not an object.'],
            'duplicate mailbox' => [fn (object $r) => $r->scheduleId = 'B4K1.First@Synthetic.test', $g.'returned the mailbox in row 1 more than once — an ambiguous grid must not be read as complete.'],
            'missing requested mailbox' => [fn (object $r) => $r->scheduleId = 'b4k1.first@synthetic.test', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rowDrifts')]
    public function test_a_row_drift_message_names_the_row_by_position_and_no_mailbox(\Closure $mutate, string $expected): void
    {
        $first = 'b4k1.first@synthetic.test';
        $second = 'b4k1.second@synthetic.test';
        $row = $this->scheduleRow($second);
        $row = $this->wire($row); // decode first so nested fields are stdClass and mutable
        $mutate($row);
        if ($expected === '') {
            // Only the mutated row is returned, and it answers for the first mailbox, so the second
            // requested mailbox is missing: the reconciliation names it by its request position.
            $rows = [$row];
            $expected = 'Microsoft Graph getSchedule did not return availability for requested mailbox 1 (zero-based position in the request) — a grid missing a requested mailbox must not be read as complete.';
        } else {
            $rows = [$this->scheduleRow($first), $row];
        }

        try {
            CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse($rows), [$first, $second]);
            $this->fail('Expected drift');
        } catch (GraphShapeDriftException $e) {
            $this->assertSame($expected, $e->getMessage());
            foreach (['@', 'synthetic.test', 'b4k1.', 'first', 'second'] as $needle) {
                $this->assertStringNotContainsString($needle, $e->getMessage());
            }
        }
    }

    /** #5732: the row position counts the response's value list from zero, and so does the request position. */
    public function test_the_row_and_request_positions_are_zero_based(): void
    {
        $row = $this->scheduleRow('b4k1.only@synthetic.test');
        $row->error = (object) [];
        try {
            CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k1.only@synthetic.test']);
            $this->fail('Expected drift');
        } catch (GraphShapeDriftException $e) {
            $this->assertStringContainsString('in row 0;', $e->getMessage());
        }

        try {
            CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([]), ['b4k1.only@synthetic.test', 'b4k1.other@synthetic.test']);
            $this->fail('Expected drift');
        } catch (GraphShapeDriftException $e) {
            $this->assertStringContainsString('requested mailbox 0 (zero-based', $e->getMessage());
        }
    }

    public function test_an_empty_error_object_is_still_treated_as_error(): void
    {
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        $row->error = (object) []; // present but empty — availability is not trustworthy
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
    }

    // ---- assertScheduleCollection: drift (malformed busy block) ----

    public function test_busy_block_without_status_screams(): void
    {
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        $row->scheduleItems = [(object) ['start' => (object) ['dateTime' => 'x', 'timeZone' => 'UTC'], 'end' => (object) ['dateTime' => 'y', 'timeZone' => 'UTC']]];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
    }

    public function test_busy_block_with_malformed_start_screams(): void
    {
        $row = $this->scheduleRow('b4k2.first@synthetic.test');
        $row->scheduleItems = [(object) ['status' => 'busy', 'start' => 'not-an-object', 'end' => (object) ['dateTime' => 'y', 'timeZone' => 'UTC']]];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['b4k2.first@synthetic.test']);
    }

    // ---- assertScheduleCollection: drift (reconciliation) ----

    public function test_missing_requested_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test')]),
            ['b4k2.first@synthetic.test', 'b4k2.second@synthetic.test'],
        );
    }

    /**
     * #5775: a mailbox requested twice (case and whitespace aside) is named by its FIRST position
     * in the request. Requested ['B4K2.Missing@…', 'b4k2.first@…', ' b4k2.missing@… '] with no
     * row for the missing one: the message names position 0, not 2.
     */
    public function test_a_duplicate_requested_mailbox_is_named_by_its_first_position(): void
    {
        try {
            CalendarGraphShapes::assertScheduleCollection(
                $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test')]),
                ['B4K2.Missing@synthetic.test', 'b4k2.first@synthetic.test', ' b4k2.missing@synthetic.test '],
            );
            $this->fail('Expected drift for a missing requested mailbox');
        } catch (GraphShapeDriftException $e) {
            $this->assertSame('Microsoft Graph getSchedule did not return availability for requested mailbox 0 (zero-based position in the request) — a grid missing a requested mailbox must not be read as complete.', $e->getMessage());
        }
    }

    public function test_unrequested_extra_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test'), $this->scheduleRow('b4k2.extra@synthetic.test')]),
            ['b4k2.first@synthetic.test'],
        );
    }

    public function test_duplicate_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('b4k2.first@synthetic.test'), $this->scheduleRow('b4k2.first@synthetic.test')]),
            ['b4k2.first@synthetic.test'],
        );
    }

    // ---- assertCalendarPage ----

    public function test_valid_calendar_page_returns_assoc_events(): void
    {
        $page = $this->wire((object) ['value' => [(object) ['id' => 'e1', 'subject' => 'A'], (object) ['id' => 'e2']]]);
        $events = CalendarGraphShapes::assertCalendarPage($page);
        $this->assertCount(2, $events);
        $this->assertSame('e1', $events[0]['id']);
    }

    public function test_empty_calendar_page_is_a_valid_no_events(): void
    {
        $this->assertSame([], CalendarGraphShapes::assertCalendarPage($this->wire((object) ['value' => []])));
    }

    public function test_calendar_page_value_as_object_screams_not_empty(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertCalendarPage($this->wire((object) ['value' => (object) []]));
    }

    public function test_calendar_page_with_malformed_event_row_screams(): void
    {
        foreach ([[(object) []], [[]]] as $badValue) {
            try {
                CalendarGraphShapes::assertCalendarPage($this->wire((object) ['value' => $badValue]));
                $this->fail('Expected drift for a malformed event row');
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- provenNextLink ----

    public function test_absent_next_link_is_the_end_of_the_list(): void
    {
        $this->assertNull(CalendarGraphShapes::provenNextLink($this->wire((object) ['value' => []])));
    }

    public function test_valid_graph_https_next_link_is_returned(): void
    {
        $page = $this->wire((object) ['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/x%40y/calendarView?$skip=10']);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/x%40y/calendarView?$skip=10', CalendarGraphShapes::provenNextLink($page));
    }

    public function test_next_link_that_pivots_off_calendarview_screams(): void
    {
        // Same host + https, but the cursor no longer continues the calendarView collection — it must
        // not be followed with the tenant app bearer (must-fix psa-abl0i.4 #4: "the expected
        // continuation path"). The host/scheme check alone would wave these through.
        foreach ([
            'https://graph.microsoft.com/v1.0/users/b4k2.extra%40synthetic.test/messages?$skip=1',
            'https://graph.microsoft.com/v1.0/users/x%40y/events/AAA',
            'https://graph.microsoft.com/v1.0/me/calendarView/../messages',
        ] as $next) {
            $page = $this->wire((object) ['value' => [], '@odata.nextLink' => $next]);
            try {
                CalendarGraphShapes::provenNextLink($page);
                $this->fail('Expected drift for a pivoted nextLink='.json_encode($next));
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_present_null_next_link_screams_not_end_of_list(): void
    {
        // psa-abl0i.7 re-review: `$page->{'@odata.nextLink'} ?? null` conflated an ABSENT property
        // (the documented end of the list) with a PRESENT "@odata.nextLink": null (drift). Both
        // collapsed to null and silently ended pagination — so a truncated calendar could read as
        // complete. property_exists() distinguishes absence from a present-null, and a present-null
        // must SCREAM (CLAUDE.md "a degraded read must SCREAM"). A present-null survives the wire()
        // json round-trip as a property that exists but is null.
        $page = $this->wire((object) ['value' => [], '@odata.nextLink' => null]);
        try {
            CalendarGraphShapes::provenNextLink($page);
            $this->fail('Expected drift for a present-null @odata.nextLink');
        } catch (GraphShapeDriftException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_malformed_or_foreign_next_link_screams(): void
    {
        $bad = [
            '', // empty string must not read as "end"
            'http://graph.microsoft.com/v1.0/x', // not https
            'https://evil.example/v1.0/users/x', // foreign host + app bearer
            'ftp://graph.microsoft.com/x',
        ];
        foreach ($bad as $next) {
            $page = $this->wire((object) ['value' => [], '@odata.nextLink' => $next]);
            try {
                CalendarGraphShapes::provenNextLink($page);
                $this->fail('Expected drift for nextLink='.json_encode($next));
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }

        // A non-string cursor (false/0) must also scream, not read as end.
        foreach ([false, 0] as $next) {
            $page = $this->wire((object) ['value' => [], '@odata.nextLink' => $next]);
            try {
                CalendarGraphShapes::provenNextLink($page);
                $this->fail('Expected drift for non-string nextLink');
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- assertEvent ----

    public function test_assert_event_returns_assoc_for_a_well_formed_event(): void
    {
        $event = CalendarGraphShapes::assertEvent($this->wire((object) ['id' => 'AAMkAG=', 'subject' => 'Onsite']));
        $this->assertSame('AAMkAG=', $event['id']);
        $this->assertSame('Onsite', $event['subject']);
    }

    public function test_assert_event_screams_on_list_or_missing_id(): void
    {
        foreach ([$this->wire([]), $this->wire((object) ['subject' => 'no id']), $this->wire((object) ['id' => ''])] as $bad) {
            try {
                CalendarGraphShapes::assertEvent($bad);
                $this->fail('Expected drift for a malformed event');
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
