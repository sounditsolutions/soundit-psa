<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #6125: releaseClaim()'s CAS carries a claim-owner fence. Request A claims a run; the run is
 * reopened and claimed again by request B, so it is Executing under B's claim. A's late
 * release must lose (return false and leave B's claim in place), not flip B's in-flight claim
 * back to AwaitingApproval. The owner is the claimed_at stamp this instance's claim wrote.
 * Synthetic values only (G-13).
 */
class ReleaseClaimOwnerFenceTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingRun(mixed $claimedAt = null, TechnicianRunState $state = TechnicianRunState::AwaitingApproval): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'calendar_stage_cancel_event',
            'content_hash' => hash('sha256', 'fence-6125-'.microtime().rand()),
            'state' => $state,
            'claimed_at' => $claimedAt,
        ]);
    }

    public function test_a_stale_release_cannot_reopen_a_claim_another_request_now_holds(): void
    {
        $this->travelTo(now()->startOfSecond());
        $run = $this->awaitingRun();
        $a = TechnicianRun::findOrFail($run->id);
        $this->assertTrue($a->claimForExecution(), 'A claims');

        // The run is reopened by another path, then B claims it a second later.
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->travel(1)->seconds();
        $b = TechnicianRun::findOrFail($run->id);
        $this->assertTrue($b->claimForExecution(), 'B claims');

        $this->assertFalse($a->releaseClaim(), "A's stale release loses");
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, "B's claim is still in place");
        $this->assertSame(TechnicianRunState::Executing, $a->state, 'A is not told it moved the run');

        $this->assertTrue($b->releaseClaim(), "positive control: B's own release wins");
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_a_release_by_the_claimant_wins_and_a_second_release_loses(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        $this->assertTrue($run->releaseClaim());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertFalse($run->releaseClaim(), 'no longer Executing');
    }

    /** A loaded instance (the reaper's) carries the stored stamp, and its release wins. */
    public function test_a_loaded_executing_run_releases_under_its_stored_stamp(): void
    {
        $run = $this->awaitingRun(now()->subMinutes(30), TechnicianRunState::Executing);
        $this->assertTrue(TechnicianRun::findOrFail($run->id)->releaseClaim());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** A legacy claim with no stamp releases under the null fence, and loses once stamped by a new claim. */
    public function test_a_legacy_unstamped_claim_releases_only_while_unstamped(): void
    {
        $run = $this->awaitingRun(null, TechnicianRunState::Executing);
        $legacy = TechnicianRun::findOrFail($run->id);
        $this->assertTrue(TechnicianRun::findOrFail($run->id)->releaseClaim(), 'positive control: the null fence matches an unstamped claim');

        $this->assertTrue(TechnicianRun::findOrFail($run->id)->claimForExecution(), 'a new, stamped claim');
        $this->assertFalse($legacy->releaseClaim(), 'the unstamped instance cannot release the stamped claim');
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);
    }

    /** releaseClaimTo() (the reconnect-run path) carries the same fence. */
    public function test_release_claim_to_carries_the_same_fence(): void
    {
        $this->travelTo(now()->startOfSecond());
        $run = $this->awaitingRun();
        $a = TechnicianRun::findOrFail($run->id);
        $this->assertTrue($a->claimForExecution());
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->travel(1)->seconds();
        $this->assertTrue(TechnicianRun::findOrFail($run->id)->claimForExecution());

        $this->assertFalse($a->releaseClaimTo(TechnicianRunState::QueuedOffline));
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);
    }
}
