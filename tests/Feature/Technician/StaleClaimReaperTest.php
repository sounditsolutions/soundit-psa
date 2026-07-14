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

    private function makeRun(TechnicianRunState $state, mixed $claimedAt = null): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
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
}
