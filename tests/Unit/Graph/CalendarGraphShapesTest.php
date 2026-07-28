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
            $this->scheduleResponse([$this->scheduleRow('charlie@soundit.co'), $this->scheduleRow('justin@soundit.co', '000000')]),
            ['charlie@soundit.co', 'justin@soundit.co'],
        );

        $this->assertCount(2, $rows);
        $this->assertSame('charlie@soundit.co', $rows[0]['scheduleId']);
        // Deep-converted to assoc arrays for the projection layer.
        $this->assertSame('busy', $rows[0]['scheduleItems'][0]['status']);
        $this->assertSame('2026-07-28T14:00:00.0000000', $rows[0]['scheduleItems'][0]['start']['dateTime']);
    }

    public function test_case_insensitive_reconciliation(): void
    {
        $rows = CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('Charlie@SoundIT.co')]),
            ['charlie@soundit.co'],
        );
        $this->assertCount(1, $rows);
    }

    public function test_empty_schedule_items_with_valid_availability_view_is_ok(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row->scheduleItems = []; // free mailbox: no busy blocks, but availabilityView is present
        $rows = CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
        $this->assertSame([], $rows[0]['scheduleItems']);
    }

    // ---- assertScheduleCollection: workingHours (consumed by projectSchedule) ----

    public function test_absent_working_hours_is_ok(): void
    {
        // workingHours is validated ONLY when present — a mailbox may legitimately omit it, and a
        // missing working-hours block is a constraint we lack, not a false-clear.
        $row = $this->scheduleRow('charlie@soundit.co');
        unset($row->workingHours);
        $rows = CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
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
            $row = $this->scheduleRow('charlie@soundit.co');
            $row->workingHours = $bad;
            try {
                CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
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
        CalendarGraphShapes::assertScheduleCollection($this->wire([]), ['charlie@soundit.co']);
    }

    public function test_value_as_empty_json_object_screams_not_empty_grid(): void
    {
        // {"value":{}} — assoc decode would collapse to [] and read as an empty grid.
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->wire((object) ['value' => (object) []]), ['charlie@soundit.co']);
    }

    public function test_value_as_populated_json_object_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->wire((object) ['value' => (object) ['0' => 'x']]), ['charlie@soundit.co']);
    }

    // ---- assertScheduleCollection: drift (missing availability = false all-clear) ----

    public function test_row_with_only_schedule_id_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([(object) ['scheduleId' => 'charlie@soundit.co']]),
            ['charlie@soundit.co'],
        );
    }

    public function test_missing_or_empty_or_nonstring_availability_view_screams(): void
    {
        foreach ([null, '', ['not', 'a', 'string']] as $bad) {
            $row = $this->scheduleRow('charlie@soundit.co');
            if ($bad === null) {
                unset($row->availabilityView);
            } else {
                $row->availabilityView = $bad;
            }
            try {
                CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
                $this->fail('Expected drift for availabilityView='.json_encode($bad));
            } catch (GraphShapeDriftException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_non_list_schedule_items_screams(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row->scheduleItems = (object) ['a' => 'b'];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
    }

    public function test_per_mailbox_error_refuses_the_whole_read_and_names_it(): void
    {
        $row = $this->scheduleRow('justin@soundit.co');
        $row->error = (object) ['message' => 'ErrorMailboxMoveInProgress', 'responseCode' => 'MailboxMoveInProgress'];
        try {
            CalendarGraphShapes::assertScheduleCollection(
                $this->scheduleResponse([$this->scheduleRow('charlie@soundit.co'), $row]),
                ['charlie@soundit.co', 'justin@soundit.co'],
            );
            $this->fail('Expected drift for a per-mailbox error');
        } catch (GraphShapeDriftException $e) {
            $this->assertStringContainsString('justin@soundit.co', $e->getMessage());
        }
    }

    public function test_an_empty_error_object_is_still_treated_as_error(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row->error = (object) []; // present but empty — availability is not trustworthy
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
    }

    // ---- assertScheduleCollection: drift (malformed busy block) ----

    public function test_busy_block_without_status_screams(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row->scheduleItems = [(object) ['start' => (object) ['dateTime' => 'x', 'timeZone' => 'UTC'], 'end' => (object) ['dateTime' => 'y', 'timeZone' => 'UTC']]];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
    }

    public function test_busy_block_with_malformed_start_screams(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row->scheduleItems = [(object) ['status' => 'busy', 'start' => 'not-an-object', 'end' => (object) ['dateTime' => 'y', 'timeZone' => 'UTC']]];
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->scheduleResponse([$row]), ['charlie@soundit.co']);
    }

    // ---- assertScheduleCollection: drift (reconciliation) ----

    public function test_missing_requested_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('charlie@soundit.co')]),
            ['charlie@soundit.co', 'justin@soundit.co'],
        );
    }

    public function test_unrequested_extra_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('charlie@soundit.co'), $this->scheduleRow('billing@soundit.co')]),
            ['charlie@soundit.co'],
        );
    }

    public function test_duplicate_mailbox_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(
            $this->scheduleResponse([$this->scheduleRow('charlie@soundit.co'), $this->scheduleRow('charlie@soundit.co')]),
            ['charlie@soundit.co'],
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
            'https://graph.microsoft.com/v1.0/users/billing%40soundit.co/messages?$skip=1',
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
