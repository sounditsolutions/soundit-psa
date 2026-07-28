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

    /** A documented-shape MS Graph calendarView event (camelCase, per the event resource). */
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
}
