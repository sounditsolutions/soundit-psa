<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenException;
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

    private function enableCalendar(array $allowed = ['staff@example.test']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
    }

    private function createArgs(Ticket $ticket, string $owner = 'staff@example.test'): array
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
        $this->enableCalendar(['staff@example.test']);
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
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $result = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket, 'billing@example.test'), 0, 'mcp-staff:chet', 'chet'
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('allowlist', mb_strtolower($result['error']));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_approving_a_staged_create_executes_the_graph_write_and_backlinks(): void
    {
        $this->enableCalendar(['staff@example.test']);
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
        $this->assertSame('staff@example.test', $captured['upn']);
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
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        // ...and the Graph write must NEVER fire once the owner is de-listed before approval.
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        // The allowlist changes between staging and approval — the TOCTOU window.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['someone-else@example.test']));

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('gate_declined', $result->status);
        // #6187 (C-56): the refusal says the owner is off the allowlist without naming the
        // mailbox, in the decline and in the blocked audit row alike.
        $this->assertStringStartsWith('The calendar owner of this write is no longer on the allowlist', (string) $result->message);
        $blocked = \App\Models\TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->pluck('summary')->all();
        $this->assertSame(['Calendar owner no longer allowlisted at approval time.'], $blocked);
        foreach ([(string) $result->message, ...$blocked] as $text) {
            $this->assertStringNotContainsString('staff@example.test', $text);
        }
        // The run was released, not left wedged Executing, and certainly not Done.
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_approval_is_refused_when_the_toolset_is_disabled_at_approval_time(): void
    {
        $this->enableCalendar(['staff@example.test']);
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
        $this->enableCalendar(['staff@example.test']);
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

    public function test_approval_declines_gracefully_on_a_tampered_payload(): void
    {
        // Review #4: a corrupted encrypted_payload makes Crypt::decryptString throw DecryptException.
        // That must reach the graceful deny-and-re-stage path (gate_declined + claim released),
        // NOT rethrow into a cockpit 500. The Graph write must never fire.
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $staged = app(StaffCalendarToolExecutor::class)->execute(
            'calendar_stage_create_event', $this->createArgs($ticket), 0, 'mcp-staff:chet', 'chet'
        );
        $run = TechnicianRun::find($staged['run_id']);

        // Tamper the ciphertext (bad MAC).
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = 'not-a-valid-laravel-ciphertext';
        $run->update(['proposed_meta' => $meta]);

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_staging_a_cancel_and_approving_it_calls_graph_cancel(): void
    {
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('cancelEvent')->once()->andReturnUsing(function (string $upn, string $eventId, ?string $comment) use (&$captured) {
                $captured = compact('upn', 'eventId', 'comment');
            });
        });

        $staged = app(StaffCalendarToolExecutor::class)->execute('calendar_stage_cancel_event', [
            'user_upn' => 'staff@example.test', 'event_id' => 'AAMkAG',
            'comment' => 'No longer needed', 'ticket_id' => $ticket->id, 'reason' => 'Resolved remotely.',
        ], 0, 'mcp-staff:chet', 'chet');

        $run = TechnicianRun::find($staged['run_id']);
        $this->assertSame('calendar_stage_cancel_event', $run->action_type);

        $result = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame('AAMkAG', $captured['eventId']);
        $this->assertSame('No longer needed', $captured['comment']);
    }

    /**
     * Blocker 2 (psa-lulgh review): once the non-idempotent Graph write has committed, a
     * LATER local failure must leave the run TERMINAL — never back to AwaitingApproval,
     * which a re-approval would turn into a second client cancellation notice. Here the
     * post-write back-link throws (its AI actor no longer resolves); the run must still end
     * Done and the cancel must fire exactly once across both approval attempts.
     */
    public function test_a_post_write_bookkeeping_failure_lands_terminal_and_never_re_fires_the_write(): void
    {
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();

        // Exactly ONE cancel across BOTH approvals below — the double-execute guard.
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once());

        $staged = app(StaffCalendarToolExecutor::class)->execute('calendar_stage_cancel_event', [
            'user_upn' => 'staff@example.test', 'event_id' => 'AAMkAG',
            'comment' => 'No longer needed', 'ticket_id' => $ticket->id, 'reason' => 'Resolved remotely.',
        ], 0, 'mcp-staff:chet', 'chet');
        $run = TechnicianRun::find($staged['run_id']);

        // Break the post-write back-link: the AI actor it is attributed to no longer exists,
        // so backlinkNote throws AFTER executeCalendarWrite has already called Graph.
        Setting::setValue('triage_system_user_id', '99999999');

        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($m) use (&$logged): void {
            $logged[] = $m;
        });
        $first = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('executed', $first->status);
        // #6268 (C-56): the bookkeeping failure is recorded by class, never by its message (a
        // QueryException's SQL bindings carry the back-link body, which names the mailbox).
        $row = \App\Models\TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->sole();
        $this->assertMatchesRegularExpression('/^Calendar write EXECUTED but post-write back-link\\/audit failed \\([A-Za-z]+\\) — run left Done/', $row->summary);
        $record = collect($logged)->first(fn ($m) => str_contains($m->message, 'post-write bookkeeping failed'));
        $this->assertNotNull($record);
        $this->assertSame(['run_id', 'action', 'exception'], array_keys($record->context));
        $this->assertStringNotContainsString('staff@example.test', $row->summary.json_encode($record->context));
        $this->assertSame(
            TechnicianRunState::Done,
            $run->fresh()->state,
            'a post-write local failure must NOT return the run to AwaitingApproval'
        );

        // Re-approval must be a no-op: the run is terminal, so Graph is not touched again.
        $second = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);
        $this->assertSame('already_handled', $second->status);
    }

    /**
     * Rework diff:1/context:1: re-staging byte-identical content for a write that already EXECUTED
     * must NOT revive the Done run — reviving re-arms a non-idempotent cancel into a second client
     * cancellation. The cancel must fire exactly once across stage → approve → re-stage → approve.
     */
    public function test_re_staging_an_executed_cancel_does_not_revive_or_refire(): void
    {
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once());

        $args = ['user_upn' => 'staff@example.test', 'event_id' => 'AAMkAG', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.'];
        $exec = app(StaffCalendarToolExecutor::class);

        $staged = $exec->execute('calendar_stage_cancel_event', $args, 0, 'mcp-staff:chet', 'chet');
        $run = TechnicianRun::find($staged['run_id']);
        $this->assertSame('executed', $exec->approveStagedRun($run, $this->approver->id)->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // Re-stage the byte-identical cancel: must be refused as already-executed, NOT revived.
        $restage = $exec->execute('calendar_stage_cancel_event', $args, 0, 'mcp-staff:chet', 'chet');
        $this->assertTrue($restage['idempotent'] ?? false);
        $this->assertTrue($restage['already_executed'] ?? false);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'an executed run must not revive to AwaitingApproval');

        // Approving the still-Done run is a no-op — the ->once() mock enforces no second cancel.
        $this->assertSame('already_handled', $exec->approveStagedRun($run->fresh(), $this->approver->id)->status);
    }

    /**
     * Rework contract:1: an indeterminate Graph failure (e.g. a timeout) on a NON-idempotent cancel
     * must NOT reopen the run for a one-tap retry — the write may already have reached the client.
     * It is held (not AwaitingApproval), and a re-approve cannot re-fire it.
     */
    public function test_an_indeterminate_graph_failure_on_a_cancel_is_held_not_reopened(): void
    {
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once()->andThrow(new GraphClientException('read timeout')));

        $exec = app(StaffCalendarToolExecutor::class);
        $staged = $exec->execute('calendar_stage_cancel_event', [
            'user_upn' => 'staff@example.test', 'event_id' => 'AAMkAG', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.',
        ], 0, 'mcp-staff:chet', 'chet');
        $run = TechnicianRun::find($staged['run_id']);

        $r = $exec->approveStagedRun($run, $this->approver->id);
        $this->assertSame('outcome_unknown', $r->status);
        $this->assertNotSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a maybe-committed non-idempotent write must not reopen for retry');

        // Re-approve is a no-op — not AwaitingApproval, so the cancel cannot fire a second time.
        $this->assertSame('already_handled', $exec->approveStagedRun($run->fresh(), $this->approver->id)->status);
    }

    /**
     * #5833: a GraphTokenException is thrown by getToken() before the write request is sent, so
     * nothing can have committed. The run is reopened (AwaitingApproval) through the same
     * releaseClaim() the pre-write refusals use, the audit row says the write was not sent, and
     * no 'indeterminate outcome' is recorded. A re-approve then executes the write once.
     */
    public function test_a_token_failure_at_approval_reopens_the_run_and_is_not_indeterminate(): void
    {
        $owner = 'owner@example.test';
        $this->enableCalendar([$owner]);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->twice()->andReturnUsing(
            fn () => throw new GraphTokenException('Failed to obtain Graph API token (HTTP 400)'),
            fn () => null,
        ));

        $exec = app(StaffCalendarToolExecutor::class);
        $staged = $exec->execute('calendar_stage_cancel_event', [
            'user_upn' => $owner, 'event_id' => 'EVT-SYNTHETIC-1', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.',
        ], 0, 'mcp-staff:chet', 'chet');
        $run = TechnicianRun::find($staged['run_id']);

        $r = $exec->approveStagedRun($run, $this->approver->id);
        $this->assertSame('gate_declined', $r->status);
        $this->assertSame('The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar; the run is reopened, so re-approve to retry.', $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'nothing was sent, so the run is reopened, not held Executing');

        $errors = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->pluck('summary')->all();
        $this->assertSame(['Graph calendar write not sent (no Graph access token was obtained): GraphTokenException'], $errors);
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count(), 'no indeterminate-outcome row');

        // Re-approve executes the write once (the second cancelEvent call succeeds).
        $this->assertSame('executed', $exec->approveStagedRun($run->fresh(), $this->approver->id)->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // #6011: after the re-approve the run's audit trail is exactly the stage, the one
        // not-sent error and one executed row, in that order, and one back-link note was
        // written; nothing is double-counted.
        $this->assertSame(
            ['awaiting_approval', 'error', 'executed'],
            TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->pluck('result_status')->all(),
        );
        $this->assertSame(['Operator-approved calendar write executed.'], TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->pluck('summary')->all());
        $this->assertSame(1, \App\Models\TicketNote::where('ticket_id', $ticket->id)->count(), 'one back-link note, for the one executed write');
        $this->assertSame('already_handled', $exec->approveStagedRun($run->fresh(), $this->approver->id)->status, 'a third approve cannot re-send');
    }

    /**
     * Rework diff:1 (final_edit): an IMMEDIATE write creates NO staged run, so a later STAGE of the
     * byte-identical content used to firstOrCreate a FRESH approvable run — bypassing the in-branch
     * guard — that re-fired the write on approval. The hoisted already-executed guard must refuse the
     * stage before any run is created. cancelEvent fires exactly once (the immediate execute).
     */
    public function test_staging_a_write_that_already_executed_immediately_is_refused(): void
    {
        $this->enableCalendar(['staff@example.test']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once());

        $exec = app(StaffCalendarToolExecutor::class);
        $args = ['user_upn' => 'staff@example.test', 'event_id' => 'AAMkAG', 'comment' => 'x', 'ticket_id' => $ticket->id, 'reason' => 'Resolved.'];

        // Immediate execute: writes an 'executed' audit row, creates NO staged run.
        $imm = $exec->execute('calendar_cancel_event', $args, 0, 'mcp-staff:chet');
        $this->assertTrue($imm['success'] ?? false);

        // Stage the byte-identical write: must be refused as already-executed, not create a run.
        $staged = $exec->execute('calendar_stage_cancel_event', $args, 0, 'mcp-staff:chet', 'chet');
        $this->assertTrue($staged['already_executed'] ?? false, 'immediate-then-stage of identical content must be refused before creating a fresh approvable run');
        $this->assertFalse($staged['staged'] ?? true);
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'calendar_stage_cancel_event')
            ->where('state', TechnicianRunState::AwaitingApproval->value)->count());
    }
}
