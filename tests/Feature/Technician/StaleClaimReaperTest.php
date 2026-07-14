<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\StaleClaimReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * psa-xz0z — a TechnicianRun claimed for execution (awaiting_approval → executing) is only
 * returned to the queue by releaseClaim() in the approval service's catch(). A process death —
 * OOM, request timeout, or PHP-FPM restarting mid-request during a DEPLOY — runs no catch, so
 * the run is stranded in 'executing' FOREVER: claimForExecution() requires awaiting_approval, so
 * it never wins again, the operator is told "already handled", and the approvable action is
 * silently lost. This reaper closes that gap by returning runs stuck past a sane TTL.
 */
class StaleClaimReaperTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(TechnicianRunState $state, mixed $claimedAt = null, string $actionType = 'send_reply'): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', 'reap-test-'.microtime().rand()),
            'state' => $state,
            'claimed_at' => $claimedAt,
        ]);
    }

    private function reaper(): StaleClaimReaper
    {
        return app(StaleClaimReaper::class);
    }

    public function test_a_run_stranded_in_executing_past_the_ttl_is_returned_to_the_approval_queue(): void
    {
        $stranded = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30));

        $summary = $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $stranded->fresh()->state);
        $this->assertSame(1, $summary['reaped']);
        $this->assertSame([$stranded->id], $summary['run_ids']);
    }

    public function test_a_run_legitimately_executing_within_the_ttl_is_left_alone(): void
    {
        $working = $this->makeRun(TechnicianRunState::Executing, now());

        $summary = $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::Executing, $working->fresh()->state, 'a live execution must not be reaped');
        $this->assertSame(0, $summary['reaped']);
    }

    public function test_non_executing_runs_are_never_touched(): void
    {
        $waiting = $this->makeRun(TechnicianRunState::AwaitingApproval, now()->subMinutes(30));
        $done = $this->makeRun(TechnicianRunState::Done, now()->subMinutes(30));
        $queued = $this->makeRun(TechnicianRunState::QueuedOffline, now()->subMinutes(30));

        $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $waiting->fresh()->state);
        $this->assertSame(TechnicianRunState::Done, $done->fresh()->state);
        $this->assertSame(TechnicianRunState::QueuedOffline, $queued->fresh()->state);
    }

    public function test_a_legacy_executing_run_with_no_claimed_at_falls_back_to_updated_at(): void
    {
        // Rows claimed before the claimed_at column shipped have claimed_at = null; the claim's
        // CAS ->update() stamped updated_at, so an OLD updated_at still marks it stranded — this
        // is what auto-recovers a run stranded by the very deploy that introduced the reaper.
        $legacy = $this->makeRun(TechnicianRunState::Executing, null);
        TechnicianRun::where('id', $legacy->id)->update(['updated_at' => now()->subMinutes(30)]);

        $summary = $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $legacy->fresh()->state);
        $this->assertSame(1, $summary['reaped']);
    }

    /**
     * REGRESSION (psa-xz0z.1 ARCH / .3.2 SECURITY): a staged VENDOR action (CIPP/Tactical) fires
     * its upstream call BEFORE the local audit/Done, so a crash between them leaves 'executing'
     * with the side effect already done. The reaper must NOT return it to the queue — a fresh
     * approval would DUPLICATE the create-user / script / wipe. It is flagged for manual review.
     */
    public function test_a_stranded_staged_vendor_action_is_flagged_not_reopened(): void
    {
        $cipp = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), 'cipp_stage_create_user');
        $tactical = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), 'tactical_stage_script');

        $summary = $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::Executing, $cipp->fresh()->state, 'a stranded CIPP write must NOT be reopened');
        $this->assertSame(TechnicianRunState::Executing, $tactical->fresh()->state, 'a stranded Tactical action must NOT be reopened');
        $this->assertSame(0, $summary['reaped']);
        $this->assertSame(2, $summary['flagged_unsafe']);
        $this->assertEqualsCanonicalizing([$cipp->id, $tactical->id], $summary['unsafe_run_ids']);
    }

    /** An unknown/future action type is treated as unsafe (fail-safe default), never auto-reopened. */
    public function test_an_unknown_action_type_is_not_reopened(): void
    {
        $run = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), 'some_future_vendor_action');

        $summary = $this->reaper()->reap();

        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);
        $this->assertSame(0, $summary['reaped']);
        $this->assertSame(1, $summary['flagged_unsafe']);
    }

    public function test_a_stranded_vendor_action_screams_for_manual_review(): void
    {
        Log::spy();
        $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), 'cipp_stage_create_user');

        $this->reaper()->reap();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'MANUAL review'))
            ->once();
    }

    public function test_every_recovery_safe_action_type_is_reaped(): void
    {
        foreach (TechnicianRun::RECOVERY_SAFE_ACTION_TYPES as $type) {
            $run = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), $type);

            $this->reaper()->reap();

            $this->assertSame(
                TechnicianRunState::AwaitingApproval,
                $run->fresh()->state,
                "{$type} is on the recovery-safe list and must be returned to the queue"
            );
        }
    }

    public function test_reaping_is_idempotent(): void
    {
        $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30));

        $this->assertSame(1, $this->reaper()->reap()['reaped']);
        $this->assertSame(0, $this->reaper()->reap()['reaped'], 'a run already returned to the queue is not reaped twice');
    }

    public function test_reaping_a_stranded_run_screams_in_the_log(): void
    {
        Log::spy();
        $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30));

        $this->reaper()->reap();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'stale execution claim'))
            ->once();
    }

    public function test_claim_for_execution_stamps_claimed_at(): void
    {
        $run = $this->makeRun(TechnicianRunState::AwaitingApproval, null);

        $this->assertTrue($run->claimForExecution());
        $this->assertNotNull($run->fresh()->claimed_at, 'the claim time must be recorded so the reaper can measure staleness');
    }

    public function test_the_scheduled_command_returns_stranded_runs(): void
    {
        $stranded = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30));

        $this->artisan('technician:reap-stale-claims')
            ->assertSuccessful();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $stranded->fresh()->state);
    }

    // ── Fix (3): the cockpit must stop LYING about a wedged run ───────────────

    public function test_approving_a_run_wedged_in_executing_is_not_falsely_reported_already_handled(): void
    {
        $wedged = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30));

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('cockpit.approve', $wedged), ['body' => 'Approve please.']);

        $response->assertOk();
        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('still finishing', $response->json('message'));
        $this->assertStringNotContainsString('already handled', $response->json('message'));
    }

    public function test_approving_a_genuinely_terminal_run_still_reads_already_handled(): void
    {
        $done = $this->makeRun(TechnicianRunState::Done, now()->subMinutes(30));

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('cockpit.approve', $done), ['body' => 'Approve please.']);

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('already handled', $response->json('message'));
    }

    public function test_a_wedged_vendor_action_warns_of_manual_review_not_auto_return(): void
    {
        // The reaper does NOT auto-return a side-effecting vendor strand, so the message must not
        // promise it will — it points the operator to manual review rather than a naive retry.
        $wedged = $this->makeRun(TechnicianRunState::Executing, now()->subMinutes(30), 'tactical_stage_script');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('cockpit.approve', $wedged));

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('manual review', $response->json('message'));
        $this->assertStringNotContainsString('automatically', $response->json('message'));
    }
}
