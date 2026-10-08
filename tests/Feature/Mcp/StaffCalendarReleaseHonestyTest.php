<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
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
 * back, verify the ticket); when it loses (the run left Executing by another path) the decline
 * says the run was not reopened instead, and gives no advice that treats it as pending.
 *
 * The lost CAS is forced at the database: a listener moves the run to Flagged right after
 * claimForExecution()'s UPDATE, so every later release finds it no longer Executing.
 * GraphClient is mocked; only the join-link arm reads it. Synthetic values only (G-13).
 */
class StaffCalendarReleaseHonestyTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner6124@example.test';

    private const NOT_REOPENED = 'the run was not reopened (it is no longer executing); check its current state before acting on it again.';

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

    /** After the claim's UPDATE, another path moves the run out of Executing. */
    private function loseTheCasAfterTheClaim(TechnicianRun $run): void
    {
        $moved = false;
        DB::listen(function (QueryExecuted $q) use ($run, &$moved): void {
            if (! $moved && str_starts_with(strtolower($q->sql), 'update') && str_contains($q->sql, 'technician_runs')
                && in_array(TechnicianRunState::Executing->value, $q->bindings, true)) {
                $moved = true;
                DB::table('technician_runs')->where('id', $run->id)->update(['state' => TechnicianRunState::Flagged->value]);
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
                'Calendar owner '.self::OWNER.' is no longer on the allowlist; the staged write was refused. Add it back (or deny and re-stage) if this is still intended.',
                'Calendar owner '.self::OWNER.' is no longer on the allowlist; the staged write was refused'],
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
     * #6124: the outer catch rethrows, so it returns no text; a lost CAS there is logged rather
     * than passing silently as a reopen.
     */
    public function test_an_unexpected_failure_whose_release_loses_is_logged(): void
    {
        $run = $this->staged(['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.']);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure')));
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

    /** Control: with the CAS won, the outer catch reopens the run and logs nothing about it. */
    public function test_an_unexpected_failure_whose_release_wins_reopens_quietly(): void
    {
        $run = $this->staged(['user_upn' => self::OWNER, 'event_id' => 'EVT-6124', 'comment' => 'x', 'reason' => 'Resolved.']);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('cancelEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure')));
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m;
        });

        $this->assertNotNull(rescue(fn () => app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id), fn ($e) => $e, false));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame([], array_values(array_filter($logged, fn (MessageLogged $m) => str_contains($m->message, 'was not reopened'))));
    }
}
