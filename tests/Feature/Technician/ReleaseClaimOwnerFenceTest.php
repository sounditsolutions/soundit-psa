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

    /**
     * #6197: A claims, the run is reopened and B claims it a second later. Returns [A, B], both
     * holding Executing in memory, with B's claim in the row.
     *
     * @return array{0: TechnicianRun, 1: TechnicianRun}
     */
    private function staleAndLiveHolders(): array
    {
        $this->travelTo(now()->startOfSecond());
        $run = $this->awaitingRun();
        $a = TechnicianRun::findOrFail($run->id);
        $this->assertTrue($a->claimForExecution(), 'A claims');
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->travel(1)->seconds();
        $b = TechnicianRun::findOrFail($run->id);
        $this->assertTrue($b->claimForExecution(), 'B claims');

        return [$a, $b];
    }

    private function queue(TechnicianRun $holder): bool
    {
        return $holder->queueForOffline('agent-6197', 'dedup-6197', now(), now()->addDays(7), ['queued_approver_id' => 6197]);
    }

    /** #6197: queueForOffline() carries the fence: a stale holder cannot park B's live claim. */
    public function test_a_stale_holder_cannot_queue_a_claim_another_request_now_holds(): void
    {
        [$a, $b] = $this->staleAndLiveHolders();

        $this->assertFalse($this->queue($a), "A's stale queue loses");
        $row = TechnicianRun::findOrFail($a->id);
        $this->assertSame(TechnicianRunState::Executing, $row->state, "B's claim is still in place");
        $this->assertNull($row->queued_agent_id, 'nothing was queued');
        $this->assertArrayNotHasKey('queued_approver_id', $row->proposed_meta ?? []);
        $this->assertSame(TechnicianRunState::Executing, $a->state, 'A is not told it moved the run');

        $this->assertTrue($this->queue($b), "positive control: the owner's queue wins");
        $this->assertSame(TechnicianRunState::QueuedOffline, $b->fresh()->state);
        $this->assertSame('agent-6197', $b->fresh()->queued_agent_id);
    }

    /** #6197: queueForOffline() still loses once the run has left Executing (state moved). */
    public function test_queue_for_offline_loses_once_the_run_left_executing(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Flagged->value]);

        $this->assertFalse($this->queue($run));
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state);
    }

    /** #6197: advanceTo() from a claimed instance carries the fence: a stale holder cannot close B's claim. */
    public function test_a_stale_holder_cannot_advance_a_claim_another_request_now_holds(): void
    {
        [$a, $b] = $this->staleAndLiveHolders();

        $this->assertFalse($a->advanceTo(TechnicianRunState::Done), "A's stale advance loses");
        $this->assertSame(TechnicianRunState::Executing, TechnicianRun::findOrFail($a->id)->state, "B's claim is still in place");
        $this->assertSame(TechnicianRunState::Executing, $a->state, 'A is not told it moved the run');

        $this->assertTrue($b->advanceTo(TechnicianRunState::Done), "positive control: the owner's advance wins");
        $this->assertSame(TechnicianRunState::Done, $b->fresh()->state);
        $this->assertSame(TechnicianRunState::Done, $b->state);
        $this->assertFalse($b->isDirty('state'), 'the instance mirrors the row');
    }

    /**
     * #6263: a claimed instance whose run has already left Executing no longer moves it. The
     * state another path set (here Flagged) stays, and the holder gets false.
     */
    public function test_advance_from_a_claim_whose_run_left_executing_does_not_move_it(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Flagged->value]);

        $this->assertFalse($run->advanceTo(TechnicianRunState::Done));
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state);
        $this->assertSame(TechnicianRunState::Executing, $run->state, 'the holder is not told it moved the run');
    }

    /**
     * #6263: A is stale; B re-claims and parks the run in the offline queue. A's late
     * advance must not land B's queued run Done (which would silently drop the queued action).
     */
    public function test_a_stale_holder_cannot_overwrite_a_state_the_other_claim_moved_to(): void
    {
        [$a, $b] = $this->staleAndLiveHolders();
        $this->assertTrue($this->queue($b), 'B parks the run');

        $this->assertFalse($a->advanceTo(TechnicianRunState::Done), "A's stale advance loses");
        $this->assertSame(TechnicianRunState::QueuedOffline, TechnicianRun::findOrFail($a->id)->state, "B's queued run stays queued");
        $this->assertFalse($a->markSuperseded(), "A's stale supersede loses too");
        $this->assertSame(TechnicianRunState::QueuedOffline, TechnicianRun::findOrFail($a->id)->state);
    }

    /** #6263: B closed the run (Done); A's late supersede must not rewrite B's Done. */
    public function test_a_stale_holder_cannot_supersede_a_run_the_other_claim_closed(): void
    {
        [$a, $b] = $this->staleAndLiveHolders();
        $this->assertTrue($b->advanceTo(TechnicianRunState::Done), 'positive control: B closes the run');

        $this->assertFalse($a->advanceTo(TechnicianRunState::Superseded));
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::findOrFail($a->id)->state);
    }

    /**
     * #6270: the row is already in the target state, moved there by another path. This holder
     * did not close it, so it gets false rather than a success it did not earn.
     */
    public function test_an_advance_to_the_state_the_row_already_holds_is_not_reported_as_this_holders_move(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Done->value]);

        $this->assertFalse($run->advanceTo(TechnicianRunState::Done));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    /**
     * #6267 / #6270: a stale holder's unsaved changes are not written either: no unfenced
     * follow-up save lands them on the row the other claim now owns.
     */
    public function test_a_stale_holders_other_changes_are_not_written(): void
    {
        [$a, $b] = $this->staleAndLiveHolders();
        $bStamp = TechnicianRun::findOrFail($b->id)->claimed_at;
        $this->assertTrue($b->advanceTo(TechnicianRunState::Done));
        $a->tokens_used = 6270;

        $this->assertFalse($a->advanceTo(TechnicianRunState::Done));
        $row = TechnicianRun::findOrFail($a->id);
        $this->assertSame(0, (int) $row->tokens_used, "A's change is not written");
        $this->assertTrue($row->claimed_at->equalTo($bStamp), "B's claim stamp is not overwritten by A's");
    }

    /** #6267: the fenced move fires no model event. */
    public function test_the_fenced_move_fires_no_model_event(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        $run->tokens_used = 6267;
        $fired = [];
        foreach (['saving', 'saved', 'updating', 'updated'] as $event) {
            TechnicianRun::{$event}(function () use (&$fired, $event): void {
                $fired[] = $event;
            });
        }

        $this->assertTrue($run->advanceTo(TechnicianRunState::Done));
        $this->assertSame([], $fired);
        $this->assertSame(6267, $run->fresh()->tokens_used, 'the change rode in the fenced UPDATE');
        $this->assertFalse($run->isDirty(), 'the instance mirrors the row');
    }

    /** #6197: an instance that holds no claim (not Executing in memory) saves as before. */
    public function test_advance_from_an_unclaimed_instance_saves_as_before(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->advanceTo(TechnicianRunState::Denied));
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }

    /** #6197: other dirty attributes on the owner's instance are still saved with the advance. */
    public function test_an_owner_advance_saves_the_instances_other_changes(): void
    {
        $run = $this->awaitingRun();
        $this->assertTrue($run->claimForExecution());
        $run->tokens_used = 6197;

        $this->assertTrue($run->advanceTo(TechnicianRunState::Done));
        $this->assertSame(6197, $run->fresh()->tokens_used);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }
}
