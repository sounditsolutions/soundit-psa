<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\CalendarGraphShapes;
use App\Services\Graph\GraphShapeDriftException;
use Tests\TestCase;

/**
 * Fail-loud validation of Microsoft Graph calendar read shapes (psa-abl0i.2). The dangerous
 * false-clear is a getSchedule grid that silently drops a mailbox or swallows a per-mailbox
 * error — it reads as "that person is FREE". Every drift MUST throw, never collapse to a clean
 * grid (CLAUDE.md "a degraded read must SCREAM").
 *
 * PROVENANCE: these fixtures are CONSTRUCTED from the documented MS Graph v1.0 scheduleInformation
 * / event contract (learn.microsoft.com/graph/api/calendar-getschedule, /resources/event) — they
 * are NOT captured-live payloads and are not described as such (the fixture-provenance point in
 * the review). They assert our validator against the vendor's documented field names + types.
 */
class CalendarGraphShapesTest extends TestCase
{
    /** A well-formed scheduleInformation row for $upn (documented shape). */
    private function scheduleRow(string $upn, string $view = '000000'): array
    {
        return [
            'scheduleId' => $upn,
            'availabilityView' => $view,
            'scheduleItems' => [],
            'workingHours' => ['daysOfWeek' => ['monday'], 'startTime' => '08:00:00.0000000', 'endTime' => '17:00:00.0000000', 'timeZone' => ['name' => 'UTC']],
        ];
    }

    private function envelope(array $rows): array
    {
        return ['value' => $rows];
    }

    public function test_valid_grid_returns_rows_for_exactly_the_requested_mailboxes(): void
    {
        $response = $this->envelope([
            $this->scheduleRow('charlie@soundit.co', '002200'),
            $this->scheduleRow('justin@soundit.co', '000000'),
        ]);

        $rows = CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co', 'justin@soundit.co']);

        $this->assertCount(2, $rows);
        $this->assertSame('charlie@soundit.co', $rows[0]['scheduleId']);
    }

    public function test_case_insensitive_reconciliation_of_requested_mailbox(): void
    {
        $response = $this->envelope([$this->scheduleRow('Charlie@SoundIT.co')]);

        $rows = CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co']);
        $this->assertCount(1, $rows);
    }

    public function test_empty_schedule_items_is_a_valid_no_busy_blocks_not_drift(): void
    {
        $response = $this->envelope([$this->scheduleRow('charlie@soundit.co', '000000')]);
        $rows = CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co']);
        $this->assertSame([], $rows[0]['scheduleItems']);
    }

    public function test_missing_value_envelope_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(['not_value' => []], ['charlie@soundit.co']);
    }

    public function test_non_array_value_envelope_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection(['value' => 'nope'], ['charlie@soundit.co']);
    }

    public function test_per_mailbox_error_refuses_the_whole_read_and_names_the_mailbox(): void
    {
        // A freeBusyError on one mailbox => availability UNKNOWN. Must NOT read as free.
        $response = $this->envelope([
            $this->scheduleRow('charlie@soundit.co'),
            [
                'scheduleId' => 'justin@soundit.co',
                'availabilityView' => '',
                'error' => ['message' => 'ErrorMailboxMoveInProgress', 'responseCode' => 'MailboxMoveInProgress'],
            ],
        ]);

        try {
            CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co', 'justin@soundit.co']);
            $this->fail('Expected a drift exception for a per-mailbox error');
        } catch (GraphShapeDriftException $e) {
            $this->assertStringContainsString('justin@soundit.co', $e->getMessage());
        }
    }

    public function test_missing_requested_mailbox_screams_not_a_complete_grid(): void
    {
        $response = $this->envelope([$this->scheduleRow('charlie@soundit.co')]);

        $this->expectException(GraphShapeDriftException::class);
        // justin was requested but not returned — a grid missing him must not read as complete.
        CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co', 'justin@soundit.co']);
    }

    public function test_unrequested_extra_mailbox_screams(): void
    {
        $response = $this->envelope([
            $this->scheduleRow('charlie@soundit.co'),
            $this->scheduleRow('billing@soundit.co'),
        ]);

        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co']);
    }

    public function test_duplicate_mailbox_row_screams(): void
    {
        $response = $this->envelope([
            $this->scheduleRow('charlie@soundit.co'),
            $this->scheduleRow('charlie@soundit.co'),
        ]);

        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($response, ['charlie@soundit.co']);
    }

    public function test_row_without_schedule_id_screams(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->envelope([['availabilityView' => '000']]), ['charlie@soundit.co']);
    }

    public function test_non_string_availability_view_screams(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row['availabilityView'] = ['not', 'a', 'string'];

        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->envelope([$row]), ['charlie@soundit.co']);
    }

    public function test_non_array_schedule_items_screams(): void
    {
        $row = $this->scheduleRow('charlie@soundit.co');
        $row['scheduleItems'] = 'busy';

        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertScheduleCollection($this->envelope([$row]), ['charlie@soundit.co']);
    }

    public function test_assert_event_returns_a_well_formed_event(): void
    {
        $event = ['id' => 'AAMkAG=', 'subject' => 'Onsite visit'];
        $this->assertSame($event, CalendarGraphShapes::assertEvent($event));
    }

    public function test_assert_event_screams_on_missing_id(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertEvent(['subject' => 'no id']);
    }

    public function test_assert_event_screams_on_empty_body(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertEvent([]);
    }

    public function test_assert_page_value_returns_the_value_list(): void
    {
        $this->assertSame([['id' => '1']], CalendarGraphShapes::assertPageValue(['value' => [['id' => '1']]], 'calendarView'));
    }

    public function test_assert_page_value_screams_on_missing_value(): void
    {
        $this->expectException(GraphShapeDriftException::class);
        CalendarGraphShapes::assertPageValue(['notvalue' => []], 'calendarView');
    }
}
