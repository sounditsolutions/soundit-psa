<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SECURITY SPINE, tested directly (manager instruction, psa-abl0i): the server-side UPN
 * allowlist is the ONLY constraint on which mailboxes the tenant-wide Graph token may read or
 * act on (the Azure Application Access Policy was dropped). guardOwnerUpn() is the single
 * executor choke point — a non-allowlisted mailbox must be refused BEFORE any Graph call, and
 * a disabled toolset must refuse everything. These tests exercise the executor by direct call
 * with a mocked GraphClient, so the enforcement is proven independently of the MCP wiring.
 */
class StaffCalendarToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function enableCalendar(array $allowed = ['charlie@soundit.co']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
    }

    /**
     * A test double of an MS Graph calendarView event (camelCase), CONSTRUCTED from the documented
     * MS Graph v1.0 event resource — https://learn.microsoft.com/en-us/graph/api/resources/event
     * (field names + nesting verified there). During development the projection was also checked
     * against a live app-token capture, but that capture is developer-local (.gc is gitignored) and
     * NOT committed, so this fixture is contract-derived, not the captured payload itself.
     * onlineMeeting.joinUrl is included per the documented event resource (a Teams meeting) even
     * though no sampled dev event happened to be one.
     */
    private function graphEvent(): array
    {
        return [
            'id' => 'AAMkAG',
            'subject' => 'Onsite: printer swap',
            'bodyPreview' => 'Swap the MFP at reception.',
            'start' => ['dateTime' => '2026-07-28T15:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-07-28T16:00:00.0000000', 'timeZone' => 'UTC'],
            'isAllDay' => false,
            'showAs' => 'busy',
            'webLink' => 'https://outlook.office365.com/owa/?itemid=AAMkAG',
            'isOnlineMeeting' => true,
            'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
            'location' => ['displayName' => 'Reception'],
            'organizer' => ['emailAddress' => ['name' => 'Charlie Coutts', 'address' => 'charlie@soundit.co']],
            'responseStatus' => ['response' => 'organizer'],
            'attendees' => [
                [
                    'type' => 'required',
                    'status' => ['response' => 'accepted', 'time' => '2026-07-27T10:00:00Z'],
                    'emailAddress' => ['name' => 'Client Contact', 'address' => 'contact@clientco.example'],
                ],
            ],
        ];
    }

    public function test_reading_a_non_allowlisted_mailbox_is_refused_before_any_graph_call(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        // The guard must refuse BEFORE touching Graph — assert calendarView is never called.
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_list_events', [
            'user_upn' => 'billing@soundit.co', // internal, but NOT on the allowlist
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('allowlist', mb_strtolower($result['error']));
    }

    public function test_a_disabled_toolset_refuses_even_an_allowlisted_mailbox(): void
    {
        Setting::setValue('calendar_enabled', '0');
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('disabled', mb_strtolower($result['error']));
    }

    public function test_missing_user_upn_is_refused(): void
    {
        $this->enableCalendar();
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_list_events', [
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('user_upn', $result['error']);
    }

    public function test_reading_an_allowlisted_mailbox_projects_the_graph_event_shape(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')
                ->once()
                ->with('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z')
                ->andReturn([$this->graphEvent()]);
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('charlie@soundit.co', $result['user_upn']);
        $this->assertCount(1, $result['events']);

        $event = $result['events'][0];
        $this->assertSame('AAMkAG', $event['id']);
        $this->assertSame('Onsite: printer swap', $event['subject']);
        $this->assertSame('2026-07-28T15:00:00.0000000', $event['start']['date_time']);
        $this->assertSame('UTC', $event['start']['time_zone']);
        $this->assertSame('charlie@soundit.co', $event['organizer']['email']);
        $this->assertTrue($event['is_online_meeting']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/xyz', $event['online_meeting_url']);
        $this->assertSame('Reception', $event['location']);
        $this->assertSame('busy', $event['show_as']);
        // An external attendee is legitimately present on the event — the allowlist gates the
        // OWNER (user_upn), never attendees.
        $this->assertSame('contact@clientco.example', $event['attendees'][0]['email']);
        $this->assertSame('accepted', $event['attendees'][0]['response']);
    }

    public function test_allowlist_match_is_case_insensitive_on_the_owner(): void
    {
        $this->enableCalendar(['Charlie@SoundIT.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->once()->andReturn([]);
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([], $result['events']);
    }

    /**
     * A test double of a Graph getSchedule scheduleInformation row, CONSTRUCTED from the documented
     * MS Graph v1.0 shape — https://learn.microsoft.com/en-us/graph/api/calendar-getschedule (not
     * a committed live capture; see graphEvent()). scheduleItems carry a subject + location on the
     * real wire; the projection MUST NOT surface them — that privacy boundary is what this exercises.
     */
    private function graphSchedule(string $scheduleId = 'charlie@soundit.co'): array
    {
        return [
            'scheduleId' => $scheduleId,
            'availabilityView' => '000022220000',
            'scheduleItems' => [[
                'isPrivate' => false,
                'status' => 'busy',
                'subject' => 'PRIVATE MEETING SUBJECT',   // must NOT appear in the projection
                'location' => 'PRIVATE LOCATION',          // must NOT appear in the projection
                'isMeeting' => true,
                'isRecurring' => false,
                'isException' => false,
                'isReminderSet' => true,
                'start' => ['dateTime' => '2026-07-28T14:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-07-28T15:00:00.0000000', 'timeZone' => 'UTC'],
            ]],
            'workingHours' => [
                'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'startTime' => '08:00:00.0000000',
                'endTime' => '17:00:00.0000000',
                'timeZone' => ['name' => 'Pacific Standard Time'],
            ],
        ];
    }

    public function test_get_schedule_projects_the_free_busy_grid_and_omits_meeting_content(): void
    {
        $this->enableCalendar(['charlie@soundit.co', 'justin@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getSchedule')
                ->once()
                ->with('charlie@soundit.co', ['charlie@soundit.co', 'justin@soundit.co'], '2026-07-28T00:00:00Z', '2026-07-28T23:59:00Z', 30)
                ->andReturn([$this->graphSchedule('charlie@soundit.co'), $this->graphSchedule('justin@soundit.co')]);
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_get_schedule', [
            'user_upn' => 'charlie@soundit.co',
            'schedules' => ['charlie@soundit.co', 'justin@soundit.co'],
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-28T23:59:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(2, $result['schedules']);

        $sched = $result['schedules'][0];
        $this->assertSame('charlie@soundit.co', $sched['schedule_id']);
        $this->assertSame('000022220000', $sched['availability_view']);
        $this->assertSame('08:00:00.0000000', $sched['working_hours']['start_time']);
        $this->assertSame('17:00:00.0000000', $sched['working_hours']['end_time']);
        $this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $sched['working_hours']['days_of_week']);
        $this->assertSame('Pacific Standard Time', $sched['working_hours']['time_zone']);

        // Availability only: status + window, never the private subject/location of the meeting.
        $block = $sched['busy_blocks'][0];
        $this->assertSame('busy', $block['status']);
        $this->assertSame('2026-07-28T14:00:00.0000000', $block['start']['date_time']);
        $this->assertArrayNotHasKey('subject', $block);
        $this->assertArrayNotHasKey('location', $block);

        // The private meeting content must never reach the tool payload at all.
        $json = json_encode($result);
        $this->assertStringNotContainsString('PRIVATE MEETING SUBJECT', $json);
        $this->assertStringNotContainsString('PRIVATE LOCATION', $json);
    }

    public function test_get_schedule_rejects_the_whole_call_when_any_schedule_is_non_allowlisted(): void
    {
        // FORK 3 (manager ruling): every schedules[] entry is a READ SUBJECT (free/busy is
        // information disclosure) and must be allowlisted. A single non-allowlisted entry
        // REJECTS THE WHOLE CALL — no partial grid — and NAMES the offender. getSchedule is
        // never reached.
        $this->enableCalendar(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getSchedule')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_get_schedule', [
            'user_upn' => 'charlie@soundit.co',
            'schedules' => ['charlie@soundit.co', 'ceo@clientco.example'], // second is not allowlisted
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-28T23:59:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('schedules', $result, 'no partial grid may be returned');
        $this->assertStringContainsString('ceo@clientco.example', $result['error'], 'the offending UPN must be named');
    }

    public function test_get_schedule_rejects_a_non_allowlisted_path_owner(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getSchedule')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_get_schedule', [
            'user_upn' => 'billing@soundit.co', // internal, but not allowlisted
            'schedules' => ['charlie@soundit.co'],
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-28T23:59:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('schedules', $result);
    }

    public function test_get_schedule_requires_a_non_empty_schedules_array(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getSchedule')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_get_schedule', [
            'user_upn' => 'charlie@soundit.co',
            'schedules' => [],
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-28T23:59:00Z',
        ], 1, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
    }
}
