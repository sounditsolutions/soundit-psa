<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice B (psa-lulgh) STAGED calendar-write path — the enable+allow-immediate PAIR the manager
 * required (staging is the OWNER's control). Mirrors StaffCippWriteToolExecutor: an external,
 * NON-IDEMPOTENT Graph write is parked as a TechnicianRun(AwaitingApproval) with an encrypted held
 * payload, and executed only on operator approval via approveStagedRun.
 *
 * The two invariants that make this safe on a tenant-wide Calendars.ReadWrite token:
 *  - the owner allowlist is RE-VERIFIED at APPROVAL time, not only at stage time — the allowlist
 *    can change in between, and checking only at stage is a TOCTOU hole in the one boundary that
 *    matters (manager: "the detail I want named");
 *  - approval is single-use (claimForExecution CAS) so a double-tap can never double-book.
 */
class StaffCalendarStagedWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create(['name' => 'Gus']);
    }

    private function enableCalendar(array $allowed = ['charlie@soundit.co']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
    }

    private function createArgs(Ticket $ticket, string $owner = 'charlie@soundit.co'): array
    {
        return [
            'user_upn' => $owner,
            'subject' => 'Onsite: printer swap',
            'start' => '2026-07-29T15:00:00',
            'end' => '2026-07-29T16:00:00',
            'attendees' => ['contact@clientco.example'],
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for an onsite (ticket).',
        ];
    }

    public function test_the_four_writes_are_registered_stageable_with_their_staged_twins(): void
    {
        foreach ([
            'calendar_stage_create_event' => 'calendar_create_event',
            'calendar_stage_update_event' => 'calendar_update_event',
            'calendar_stage_cancel_event' => 'calendar_cancel_event',
            'calendar_stage_respond_event' => 'calendar_respond_event',
        ] as $staged => $canonical) {
            $this->assertTrue(McpToolModes::isStageable($canonical), "{$canonical} must be stageable");
            $this->assertSame($canonical, McpToolModes::canonicalForAlias($staged));
            $this->assertSame($staged, McpToolModes::stagedInternalFor($canonical));
        }
    }

    public function test_staging_a_create_parks_an_awaiting_run_and_never_calls_graph(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('run_id', $result);

        $run = TechnicianRun::find($result['run_id']);
        $this->assertNotNull($run);
        $this->assertSame('calendar_stage_create_event', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($ticket->id, $run->ticket_id);
        // The held Graph body is encrypted at rest, never plaintext in proposed_meta.
        $this->assertIsString($run->proposed_meta['encrypted_payload']);
        $this->assertStringNotContainsString('printer swap', json_encode($run->proposed_meta['encrypted_payload']));
    }

    public function test_staging_still_gates_the_owner_allowlist_before_parking(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket, 'billing@soundit.co'), 0, 'mcp-staff:chet', 'chet'
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('allowlist', mb_strtolower($result['error']));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_approving_a_staged_create_executes_the_graph_write_and_backlinks(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('createEvent')->once()->andReturnUsing(function (string $upn, array $body) use (&$captured) {
                $captured = compact('upn', 'body');

                return ['id' => 'AAMkAG-new', 'subject' => $body['subject'], 'webLink' => 'https://outlook/x'];
            });
        });

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame('charlie@soundit.co', $captured['upn']);
        $this->assertSame('Onsite: printer swap', $captured['body']['subject']);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        // Back-link note lands on approval (private, system).
        $note = TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::System->value)->first();
        $this->assertNotNull($note);
        $this->assertTrue((bool) $note->is_private);
    }

    public function test_approval_re_verifies_the_allowlist_at_approval_time_toctou(): void
    {
        // Owner is allowlisted at STAGE time...
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        // ...and the Graph write must NEVER fire once the owner is de-listed before approval.
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        // The allowlist changes between staging and approval — the TOCTOU window.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['someone-else@soundit.co']));

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('charlie@soundit.co', (string) $result->message);
        // The run was released, not left wedged Executing, and certainly not Done.
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_approval_is_refused_when_the_toolset_is_disabled_at_approval_time(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        Setting::setValue('calendar_enabled', '0'); // master switch flipped off after staging

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);
        $this->assertSame('gate_declined', $result->status);
    }

    public function test_double_approval_is_single_use(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('createEvent')->once()->andReturn(['id' => 'AAMkAG-new', 'subject' => 'x', 'webLink' => 'https://x']);
        });

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        $first = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);
        $second = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed', $first->status);
        $this->assertSame('already_handled', $second->status);
    }

    public function test_staging_a_cancel_and_approving_it_calls_graph_cancel(): void
    {
        $this->enableCalendar(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('cancelEvent')->once()->andReturnUsing(function (string $upn, string $eventId, ?string $comment) use (&$captured) {
                $captured = compact('upn', 'eventId', 'comment');
            });
        });

        $staged = app(StaffCalendarToolExecutor::class)->execute('calendar_stage_cancel_event', [
            'user_upn' => 'charlie@soundit.co', 'event_id' => 'AAMkAG',
            'comment' => 'No longer needed', 'ticket_id' => $ticket->id, 'reason' => 'Resolved remotely.',
        ], 0, 'mcp-staff:chet', 'chet');

        $run = TechnicianRun::find($staged['run_id']);
        $this->assertSame('calendar_stage_cancel_event', $run->action_type);

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame('AAMkAG', $captured['eventId']);
        $this->assertSame('No longer needed', $captured['comment']);
    }
}
