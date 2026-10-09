<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * #6124: every arm of approveStagedRun() that releases the claim before the write reads
 * releaseClaim()'s bool. When the CAS wins the decline keeps its advice (re-stage, add the owner
 * back, verify the ticket); when it loses (the run left Executing, or another claim now holds
 * it) the decline says the run was not reopened instead, and gives no advice that treats it as
 * pending.
 *
 * The lost CAS is forced at the database: a listener moves the run to Flagged right after
 * claimForExecution()'s UPDATE, so every later release finds it no longer Executing. One row
 * instead restamps claimed_at (#6125), so the run stays Executing under another claim.
 * GraphClient is mocked; only the join-link arm reads it. Synthetic values only (G-13).
 */
class StaffCalendarReleaseHonestyTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner6124@example.test';

    private const NOT_REOPENED = 'the run was not reopened; check its current state before acting on it again.';

    private const UPDATE = ['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'body' => 'New agenda', 'reason' => 'Asked.'];

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create(['name' => 'Gus']);
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode([self::OWNER]));
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andReturn([
            'id' => 'EVT-6124', 'isOnlineMeeting' => true, 'onlineMeeting' => ['joinUrl' => 'https://teams.example.test/join'],
            'body' => ['contentType' => 'text', 'content' => 'no join block'],
        ]));
    }

    private function staged(array $arguments, string $stagedTool = 'calendar_stage_cancel_event'): TechnicianRun
    {
        $ticket = Ticket::factory()->create();
        $staged = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        return TechnicianRun::findOrFail($staged['run_id']);
    }

    /**
     * After the claim's UPDATE, another path writes $change to the run: by default it moves the
     * run out of Executing; a claimed_at change leaves it Executing under another claim.
     *
     * @param  array<string, mixed>|null  $change
     */
    private function loseTheCasAfterTheClaim(TechnicianRun $run, ?array $change = null): void
    {
        $change ??= ['state' => TechnicianRunState::Flagged->value];
        $moved = false;
        DB::listen(function (QueryExecuted $q) use ($run, $change, &$moved): void {
            if (! $moved && str_starts_with(strtolower($q->sql), 'update') && str_contains($q->sql, 'technician_runs')
                && in_array(TechnicianRunState::Executing->value, $q->bindings, true)) {
                $moved = true;
                DB::table('technician_runs')->where('id', $run->id)->update($change);
            }
        });
    }

    /**
     * [arguments, staged tool, a change made before approval, the decline when the CAS wins,
     * the decline's lead when it loses ('' for the join-link arm, whose fact leads instead)].
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: \Closure(TechnicianRun): mixed, 3: string, 4: string}>
     */
    public static function refusals(): array
    {
        $cancel = ['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.'];

        return [
            'unreadable payload' => [$cancel, 'calendar_stage_cancel_event',
                fn (TechnicianRun $run) => $run->update(['proposed_meta' => ['encrypted_payload' => 'not-a-ciphertext']]),
                'The held calendar payload could not be read or does not match this action; deny this proposal and re-stage it.',
                'The held calendar payload could not be read or does not match this action'],
            'toolset disabled' => [$cancel, 'calendar_stage_cancel_event',
                fn () => Setting::setValue('calendar_enabled', '0'),
                'The calendar toolset is now disabled in this deployment; the staged write was refused.',
                'The calendar toolset is now disabled in this deployment; the staged write was refused'],
            'owner no longer allowlisted' => [$cancel, 'calendar_stage_cancel_event',
                fn () => Setting::setValue('calendar_allowed_owner_upns', json_encode(['someone-else@example.test'])),
                'The calendar owner of this write is no longer on the allowlist; the staged write was refused. Add it back (or deny and re-stage) if this is still intended.',
                'The calendar owner of this write is no longer on the allowlist; the staged write was refused'],
            'ticket gone' => [$cancel, 'calendar_stage_cancel_event',
                fn (TechnicianRun $run) => Ticket::findOrFail($run->ticket_id)->delete(),
                'The ticket this write traced to no longer exists; deny this proposal and re-stage it.',
                'The ticket this write traced to no longer exists'],
            'ticket is an unverified intake' => [$cancel, 'calendar_stage_cancel_event',
                fn (TechnicianRun $run) => DB::table('tickets')->where('id', $run->ticket_id)->update(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]),
                'The ticket this write traced to is an unverified contact intake held for staff verification; the staged write was refused. Verify the ticket first, or deny this proposal.',
                'The ticket this write traced to is an unverified contact intake held for staff verification; the staged write was refused'],
            'join link cannot be preserved' => [['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'body' => 'New agenda', 'reason' => 'Asked.'], 'calendar_stage_update_event',
                fn () => null,
                'This event is a Teams meeting and its join link could not be confidently located',
                ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_refusal_whose_release_wins_keeps_its_advice(array $arguments, string $stagedTool, \Closure $change, string $won, string $lostLead): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $change($run);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertStringStartsWith($won, (string) $r->message);
        $this->assertStringNotContainsString('not reopened', (string) $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'positive control: the CAS won');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_refusal_whose_release_loses_says_not_reopened(array $arguments, string $stagedTool, \Closure $change, string $won, string $lostLead): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $change($run);
        $this->loseTheCasAfterTheClaim($run);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state, 'positive control: the CAS lost');
        if ($lostLead === '') {
            // The join-link refusal is long; the not-reopened fact leads so the 300-character cut keeps it.
            $this->assertStringStartsWith(ucfirst(self::NOT_REOPENED).' '.$won, (string) $r->message);
        } else {
            $this->assertSame($lostLead.'; '.self::NOT_REOPENED, $r->message);
        }
        foreach (['re-stage', 'Add it back', 'Verify the ticket first'] as $advice) {
            $this->assertStringNotContainsString($advice, (string) $r->message);
        }
    }

    /**
     * #6125: the release can also lose while the run is still Executing, under a claim another
     * approver now holds (the claimed_at fence). The decline says only that the run was not
     * reopened, which is true on this path as on the Flagged one.
     */
    public function test_a_refusal_whose_release_loses_on_the_claim_owner_fence_says_not_reopened(): void
    {
        $run = $this->staged(['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.']);
        Setting::setValue('calendar_enabled', '0');
        $this->loseTheCasAfterTheClaim($run, ['claimed_at' => now()->addMinute()]);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'positive control: the CAS lost on the fence and the run is still Executing');
        $this->assertSame('The calendar toolset is now disabled in this deployment; the staged write was refused; '.self::NOT_REOPENED, $r->message);
    }

    /**
     * #6124: the outer catch rethrows, so it returns no text; a lost CAS there is logged rather
     * than passing silently as a reopen. #6185: the throwable is raised before the write call
     * is entered (by the update's getEvent() pre-read), the only place a release stays.
     */
    public function test_an_unexpected_failure_whose_release_loses_is_logged(): void
    {
        $run = $this->staged(self::UPDATE, 'calendar_stage_update_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure'))
            ->shouldReceive('updateEvent')->never());
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m;
        });
        $this->loseTheCasAfterTheClaim($run);

        try {
            app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);
            $this->fail('expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic non-Graph failure', $e->getMessage());
        }
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state, 'positive control: the CAS lost');
        $records = array_values(array_filter($logged, fn (MessageLogged $m) => str_contains($m->message, 'was not reopened')));
        $this->assertCount(1, $records);
        $this->assertSame(['run_id' => $run->id, 'exception' => \RuntimeException::class], $records[0]->context);
    }

    /**
     * #6185: before the write call is entered (the update's getEvent() pre-read throws a
     * non-Graph throwable) nothing was sent, so the outer catch keeps its determinate arm: the
     * claim is released, the throwable is rethrown, and nothing says unresolved.
     */
    public function test_an_unexpected_failure_before_the_write_call_reopens_quietly(): void
    {
        $run = $this->staged(self::UPDATE, 'calendar_stage_update_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure'))
            ->shouldReceive('updateEvent')->never());
        $logged = $this->captureLogs();

        $thrown = rescue(fn () => app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id), fn ($e) => $e, false);

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame([], array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'was not reopened') || str_contains($m->message, 'unresolved'))));
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%UNRESOLVED%')->count());
    }

    /**
     * #6185: once the write call is entered, a non-Graph throwable (here raised by the mocked
     * write itself, standing in for one raised after Graph may have committed it) leaves the
     * outcome unknown. The run is NOT released: it stays Executing under this claim. The
     * approver is told the outcome is unknown, one error record and one audit row say so, and
     * nothing is rethrown. Each write tool is a row; update carries a body, so its pre-read
     * succeeds first.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function enteredWrites(): array
    {
        return [
            'create' => [['user_upn' => self::OWNER, 'subject' => 'Onsite', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'reason' => 'Asked.'], 'calendar_stage_create_event', 'createEvent'],
            'update with a body' => [self::UPDATE, 'calendar_stage_update_event', 'updateEvent'],
            'cancel' => [['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.'], 'calendar_stage_cancel_event', 'cancelEvent'],
            'respond' => [['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'response' => 'accept', 'reason' => 'Asked.'], 'calendar_stage_respond_event', 'respondEvent'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('enteredWrites')]
    public function test_an_unexpected_failure_after_the_write_call_was_entered_is_unresolved_not_reopened(array $arguments, string $stagedTool, string $writeMethod): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andReturn(['id' => 'EVT-6124', 'isOnlineMeeting' => false])
            ->shouldReceive($writeMethod)->once()->andThrow(new \TypeError('synthetic non-Graph failure '.self::OWNER)));
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame('The calendar write call was entered and an unexpected error was then raised, so whether the write reached the calendar is unknown. The run was not reopened for re-approval; check the calendar before any re-send.', $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'held, not reopened');
        $this->assertEquals($run->fresh()->claimed_at, TechnicianRun::findOrFail($run->id)->claimed_at);
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'unresolved')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'action' => $stagedTool, 'exception' => \TypeError::class], $records[0]->context);
        $this->assertSame([], array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'was not reopened') && ! str_contains($m->message, 'unresolved'))));
        $this->assertSame(
            ['Graph calendar write outcome UNRESOLVED: an unexpected failure was raised after the write call was entered: TypeError'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->count());
    }

    /**
     * #6197: the write ran, then advanceTo(Done) lost the claim-owner fence (another approval
     * claimed the run during the call). The run is not closed over that claim, nothing is
     * reopened, and the approver is told the write executed and the run was not closed, on the
     * error channel (executed_with_fault), not as a clean success and not as a retry.
     */
    public function test_an_executed_write_whose_close_loses_the_fence_says_so(): void
    {
        $run = $this->staged(['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.']);
        $taken = now()->addMinute()->startOfSecond();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->once()->andReturnUsing(function () use ($run, $taken) {
            DB::table('technician_runs')->where('id', $run->id)->update(['claimed_at' => $taken]);

            return null;
        }));
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed, but the run was not closed because this approval no longer holds it. Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is untouched');
        $this->assertCount(1, array_filter($logged(), fn (MessageLogged $m) => $m->level === 'warning' && str_contains($m->message, 'was not closed')));
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->count());
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }
}
