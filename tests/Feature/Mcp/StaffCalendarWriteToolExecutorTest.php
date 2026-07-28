<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice B (psa-lulgh) IMMEDIATE calendar-write path, tested at the executor by direct call with a
 * mocked GraphClient — the enforcement is proven independently of the MCP wiring (mirrors the
 * Slice A StaffCalendarToolExecutorTest).
 *
 * The invariants under test are the ones the manager fixed as non-negotiable:
 *  - the UPN allowlist (guardOwnerUpn) gates the OWNER (user_upn) of EVERY write before any Graph
 *    call — a non-allowlisted owner is refused, exactly as for reads;
 *  - external client emails are legitimate ATTENDEES but never the owner/organizer;
 *  - ticket_id is REQUIRED on every verb (Charlie 19:10Z), must resolve to a real ticket, and the
 *    write drops a PRIVATE audit back-link note on that ticket so every event traces to a why;
 *  - the Graph body is built from validated args to the shapes grounded in the producer.
 */
class StaffCalendarWriteToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The AI actor that authors the back-link note (MCP AI-authored writes need explicit attribution).
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function enableCalendar(array $allowed = ['charlie@soundit.co']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->create();
    }

    /**
     * Mock GraphClient::createEvent, capturing the (upn, body) it is called with and returning a
     * documented created-event resource (201 shape).
     */
    private function mockCreate(?callable &$captured): void
    {
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('createEvent')->once()->andReturnUsing(function (string $upn, array $body) use (&$captured) {
                $captured = ['upn' => $upn, 'body' => $body];

                return ['id' => 'AAMkAG-new', 'subject' => $body['subject'] ?? null, 'webLink' => 'https://outlook.office365.com/owa/?itemid=AAMkAG-new'];
            });
        });
    }

    public function test_create_builds_the_graph_body_and_backlinks_a_private_note_on_the_ticket(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mockCreate($captured);

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co',
            'subject' => 'Onsite: printer swap',
            'start' => '2026-07-29T15:00:00',
            'end' => '2026-07-29T16:00:00',
            'attendees' => ['contact@clientco.example'],
            'location' => 'Reception',
            'body' => 'Swap the MFP at reception.',
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for an onsite this week (ticket).',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success']);
        $this->assertSame('AAMkAG-new', $result['event']['id']);

        // Body grounded in the producer shape (camelCase), tz defaulting to UTC.
        $body = $captured['body'];
        $this->assertSame('charlie@soundit.co', $captured['upn']);
        $this->assertSame('Onsite: printer swap', $body['subject']);
        $this->assertSame('2026-07-29T15:00:00', $body['start']['dateTime']);
        $this->assertSame('UTC', $body['start']['timeZone']);
        $this->assertSame('2026-07-29T16:00:00', $body['end']['dateTime']);
        $this->assertSame('Reception', $body['location']['displayName']);
        $this->assertSame('contact@clientco.example', $body['attendees'][0]['emailAddress']['address']);
        $this->assertSame('required', $body['attendees'][0]['type']);

        // A PRIVATE, system-generated back-link note lands on the ticket, authored by the AI actor,
        // naming the event and the reason (the "why").
        $note = TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::System->value)->first();
        $this->assertNotNull($note);
        $this->assertTrue((bool) $note->is_private);
        $this->assertStringContainsString('AAMkAG-new', $note->body);
        $this->assertStringContainsString('charlie@soundit.co', $note->body);
    }

    public function test_create_transaction_id_covers_the_whole_plan_not_just_subject_and_window(): void
    {
        // Review #3: two creates on ONE ticket with identical subject+window but DIFFERENT attendees
        // must NOT share a transactionId — else Graph dedupes and silently returns the first event,
        // and the back-link note records a create that never happened (lies to the technician).
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $txns = [];
        $this->mock(GraphClient::class, function ($m) use (&$txns) {
            $m->shouldReceive('createEvent')->twice()->andReturnUsing(function (string $upn, array $body) use (&$txns) {
                $txns[] = $body['transactionId'] ?? null;

                return ['id' => 'evt-'.count($txns), 'subject' => $body['subject'], 'webLink' => 'https://x'];
            });
        });

        $base = [
            'user_upn' => 'charlie@soundit.co', 'subject' => 'Onsite',
            'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00',
            'ticket_id' => $ticket->id, 'reason' => 'r',
        ];
        app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', array_merge($base, ['attendees' => ['a@x.example']]), 0, 'mcp-staff:chet');
        app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', array_merge($base, ['attendees' => ['b@y.example']]), 0, 'mcp-staff:chet');

        $this->assertCount(2, $txns);
        $this->assertNotNull($txns[0]);
        $this->assertNotSame($txns[0], $txns[1], 'distinct attendee sets must yield distinct transactionIds');
    }

    public function test_create_with_teams_meeting_sets_the_online_meeting_fields(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mockCreate($captured);

        app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co',
            'subject' => 'Remote assist',
            'start' => '2026-07-29T15:00:00',
            'end' => '2026-07-29T16:00:00',
            'teams_meeting' => true,
            'ticket_id' => $ticket->id,
            'reason' => 'Remote session.',
        ], 0, 'mcp-staff:chet');

        $this->assertTrue($captured['body']['isOnlineMeeting']);
        $this->assertSame('teamsForBusiness', $captured['body']['onlineMeetingProvider']);
    }

    public function test_create_refuses_a_non_allowlisted_owner_before_any_graph_call(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'billing@soundit.co', // internal, NOT allowlisted
            'subject' => 'x', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00',
            'ticket_id' => $ticket->id, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('allowlist', mb_strtolower($result['error']));
    }

    public function test_create_allows_an_external_attendee_but_never_as_owner(): void
    {
        // The owner is allowlisted; an EXTERNAL attendee is legitimate and passes through.
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mockCreate($captured);

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co',
            'subject' => 'Kickoff', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00',
            'attendees' => ['ceo@clientco.example', 'contact@another.example'],
            'ticket_id' => $ticket->id, 'reason' => 'Kickoff.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('ceo@clientco.example', $captured['body']['attendees'][0]['emailAddress']['address']);
        $this->assertCount(2, $captured['body']['attendees']);
    }

    public function test_create_rejects_a_malformed_attendee(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co',
            'subject' => 'x', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00',
            'attendees' => ['not-an-email'],
            'ticket_id' => $ticket->id, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not-an-email', $result['error']);
    }

    public function test_create_requires_a_resolvable_ticket_id(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        // Missing ticket_id.
        $missing = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co', 'subject' => 'x',
            'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'reason' => 'x',
        ], 0, 'mcp-staff:chet');
        $this->assertArrayHasKey('error', $missing);
        $this->assertStringContainsString('ticket_id', $missing['error']);

        // Non-existent ticket_id.
        $unknown = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co', 'subject' => 'x',
            'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'ticket_id' => 999999, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');
        $this->assertArrayHasKey('error', $unknown);
        $this->assertStringContainsString('ticket', mb_strtolower($unknown['error']));
    }

    public function test_update_builds_a_partial_patch_and_backlinks(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('updateEvent')->once()->andReturnUsing(function (string $upn, string $eventId, array $patch) use (&$captured) {
                $captured = compact('upn', 'eventId', 'patch');

                return ['id' => $eventId, 'subject' => $patch['subject'] ?? 'Onsite', 'webLink' => 'https://outlook/x'];
            });
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', [
            'user_upn' => 'charlie@soundit.co',
            'event_id' => 'AAMkAG',
            'subject' => 'Onsite: rescheduled',
            'start' => '2026-07-30T15:00:00',
            'end' => '2026-07-30T16:00:00',
            'ticket_id' => $ticket->id,
            'reason' => 'Client moved it a day.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('AAMkAG', $captured['eventId']);
        $this->assertSame('Onsite: rescheduled', $captured['patch']['subject']);
        $this->assertSame('2026-07-30T15:00:00', $captured['patch']['start']['dateTime']);
        $this->assertSame('UTC', $captured['patch']['start']['timeZone']);
        // Only supplied fields appear in the partial patch (no location/body/attendees keys).
        $this->assertArrayNotHasKey('location', $captured['patch']);

        $note = TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::System->value)->first();
        $this->assertNotNull($note);
        $this->assertTrue((bool) $note->is_private);
    }

    public function test_update_requires_at_least_one_field(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('updateEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'ticket_id' => $ticket->id, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_cancel_calls_graph_cancel_with_comment_and_backlinks(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('cancelEvent')->once()->andReturnUsing(function (string $upn, string $eventId, ?string $comment) use (&$captured) {
                $captured = compact('upn', 'eventId', 'comment');
            });
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', [
            'user_upn' => 'charlie@soundit.co',
            'event_id' => 'AAMkAG',
            'comment' => 'Cancelling — client resolved remotely.',
            'ticket_id' => $ticket->id,
            'reason' => 'No longer needed.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success']);
        $this->assertSame('AAMkAG', $captured['eventId']);
        $this->assertSame('Cancelling — client resolved remotely.', $captured['comment']);
        $this->assertNotNull(TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::System->value)->first());
    }

    public function test_respond_calls_graph_respond_and_backlinks(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('respondEvent')->once()->andReturnUsing(function (string $upn, string $eventId, string $response, ?string $comment, bool $send) use (&$captured) {
                $captured = compact('upn', 'eventId', 'response', 'comment', 'send');
            });
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_respond_event', [
            'user_upn' => 'charlie@soundit.co',
            'event_id' => 'AAMkAG',
            'response' => 'accept',
            'comment' => 'See you there.',
            'ticket_id' => $ticket->id,
            'reason' => 'Confirming the onsite.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('accept', $captured['response']);
        $this->assertNotNull(TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::System->value)->first());
    }

    public function test_respond_rejects_an_invalid_response(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('respondEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_respond_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'response' => 'maybe', 'ticket_id' => $ticket->id, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_a_disabled_toolset_refuses_every_write(): void
    {
        Setting::setValue('calendar_enabled', '0');
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co', 'subject' => 'x',
            'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00',
            'ticket_id' => $ticket->id, 'reason' => 'x',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('disabled', mb_strtolower($result['error']));
    }
}
