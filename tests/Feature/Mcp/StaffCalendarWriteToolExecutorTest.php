<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenException;
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

    private const TEAMS_BODY = '<div>Old agenda</div>'
        .'<div>________________________________________________________________________________</div>'
        .'<div>Microsoft Teams meeting<br><a href="https://teams.microsoft.com/l/meetup-join/xyz">Join the meeting now</a><br>Meeting ID: 123 456 789</div>';

    /**
     * Blocker 3 (psa-lulgh review) — Chet's acceptance probe verbatim: editing the agenda of a
     * Teams meeting must PRESERVE the join link. The body is re-read immediately before the write;
     * Graph's meeting block is kept verbatim below the new (escaped) agenda, sent as HTML.
     */
    public function test_a_body_edit_on_a_teams_meeting_preserves_the_join_link(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('getEvent')->once()->andReturn([
                'id' => 'AAMkAG',
                'isOnlineMeeting' => true,
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
                'body' => ['contentType' => 'html', 'content' => self::TEAMS_BODY],
            ]);
            $m->shouldReceive('updateEvent')->once()->andReturnUsing(function (string $upn, string $eventId, array $patch) use (&$captured) {
                $captured = $patch;

                return ['id' => $eventId, 'subject' => 'x', 'webLink' => 'https://outlook/x'];
            });
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'body' => "New agenda line 1\nline 2", 'ticket_id' => $ticket->id, 'reason' => 'Reworked the agenda.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('HTML', $captured['body']['contentType']);
        // The join link AND the rest of Graph's meeting block survive the agenda edit.
        $this->assertStringContainsString('https://teams.microsoft.com/l/meetup-join/xyz', $captured['body']['content']);
        $this->assertStringContainsString('Meeting ID: 123 456 789', $captured['body']['content']);
        // The new agenda is present (and escaped — no raw agent markup enters the body).
        $this->assertStringContainsString('New agenda line 1', $captured['body']['content']);
    }

    /**
     * Fail-closed edge: if the current body can't be parsed confidently enough to locate the join
     * block, refuse the edit rather than silently drop the link — and never touch Graph.
     */
    public function test_a_body_edit_on_a_teams_meeting_with_no_locatable_join_block_is_refused(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getEvent')->once()->andReturn([
                'id' => 'AAMkAG',
                'isOnlineMeeting' => true,
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
                // No separator and the join URL is absent from the body — not confidently preservable.
                'body' => ['contentType' => 'html', 'content' => '<div>Just an agenda, no meeting block</div>'],
            ]);
            $m->shouldReceive('updateEvent')->never();
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'body' => 'New agenda', 'ticket_id' => $ticket->id, 'reason' => 'Reworked the agenda.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Join', $result['error']);
    }

    /** A non-Teams event keeps the existing plain-Text body path unchanged (no HTML, no join guard). */
    public function test_a_body_edit_on_a_non_teams_event_stays_plain_text(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('getEvent')->once()->andReturn([
                'id' => 'AAMkAG', 'isOnlineMeeting' => false,
                'body' => ['contentType' => 'text', 'content' => 'plain'],
            ]);
            $m->shouldReceive('updateEvent')->once()->andReturnUsing(function (string $upn, string $eventId, array $patch) use (&$captured) {
                $captured = $patch;

                return ['id' => $eventId, 'subject' => 'x', 'webLink' => 'https://outlook/x'];
            });
        });

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'body' => 'New agenda', 'ticket_id' => $ticket->id, 'reason' => 'Reworked the agenda.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Text', $captured['body']['contentType']);
        $this->assertSame('New agenda', $captured['body']['content']);
    }

    /**
     * Rework diff:2: on the immediate path, a post-commit local failure (here the back-link's AI
     * actor no longer resolves) must NOT surface as an exception/retryable error — the Graph cancel
     * already committed, so re-firing it would mail the client a second cancellation.
     */
    public function test_an_immediate_cancel_survives_a_post_write_bookkeeping_failure(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once());
        // Break the post-write back-link: the AI actor it attributes to no longer exists.
        Setting::setValue('triage_system_user_id', '99999999');

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success'] ?? false);
    }

    /**
     * Rework diff:2/contract:1: an upstream Graph failure on the immediate path returns a clean,
     * NON-retry-implying error (not an uncaught exception), because a timed-out cancel may already
     * have reached the client.
     */
    public function test_an_immediate_cancel_graph_failure_returns_a_non_retry_error(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once()->andThrow(new GraphClientException('read timeout')));

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.',
        ], 0, 'mcp-staff:chet');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('INDETERMINATE', $result['error']);
    }

    /**
     * #5833: a GraphTokenException comes from getToken() before the write request is sent, so the
     * immediate path reports a determinate failure: not sent, nothing written, no indeterminate
     * outcome in the error or the audit row.
     */
    public function test_an_immediate_cancel_token_failure_is_reported_as_not_sent(): void
    {
        $owner = 'owner@example.test';
        $this->enableCalendar([$owner]);
        $ticket = $this->ticket();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once()->andThrow(new GraphTokenException('Failed to obtain Graph API token (HTTP 400)')));

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', [
            'user_upn' => $owner, 'event_id' => 'EVT-SYNTHETIC-1', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.',
        ], 0, 'mcp-staff:chet');

        $this->assertSame(['error' => 'The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar, so a retry cannot duplicate it.'], $result);
        $this->assertSame(
            ['Graph calendar write not sent (no Graph access token was obtained): Failed to obtain Graph API token (HTTP 400)'],
            TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count(), 'no indeterminate-outcome row');
    }
}
