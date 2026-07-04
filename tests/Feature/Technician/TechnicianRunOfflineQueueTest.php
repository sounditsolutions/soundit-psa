<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * State-machine transitions that back the offline-script queue (bd psa-xr84):
 * a staged Tactical action that hits an offline device parks in queued_offline,
 * is claimed for a reconnect-run when the device returns, and terminates as
 * expired or cancelled. All transitions are CAS latches (mirror claimForExecution).
 */
class TechnicianRunOfflineQueueTest extends TestCase
{
    use RefreshDatabase;

    private function stagedRun(TechnicianRunState $state, string $hash = 'a'): TechnicianRun
    {
        $ticket = Ticket::factory()->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => str_repeat($hash, 64),
            'state' => $state,
        ]);
    }

    public function test_queue_for_offline_latches_only_from_executing(): void
    {
        $run = $this->stagedRun(TechnicianRunState::Executing);
        $expires = now()->addDays(7);

        $this->assertTrue($run->queueForOffline('agent-1', 'dedup-abc', now(), $expires));

        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame('dedup-abc', $fresh->queued_dedup_key);
        $this->assertNotNull($fresh->queued_at);
        $this->assertNotNull($fresh->expires_at);
        $this->assertSame(0, $fresh->coalesce_count);

        // Second call is no longer Executing → does not re-latch.
        $this->assertFalse($run->queueForOffline('agent-1', 'dedup-abc', now(), $expires));
    }

    public function test_claim_queued_for_execution_is_single_use(): void
    {
        $run = $this->stagedRun(TechnicianRunState::QueuedOffline);

        $this->assertTrue($run->claimQueuedForExecution());
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);

        $again = TechnicianRun::find($run->id);
        $this->assertFalse($again->claimQueuedForExecution());
    }

    public function test_cancel_queued_only_from_queued_offline(): void
    {
        $run = $this->stagedRun(TechnicianRunState::QueuedOffline);
        $this->assertTrue($run->cancelQueued());
        $this->assertSame(TechnicianRunState::Cancelled, $run->fresh()->state);

        $done = $this->stagedRun(TechnicianRunState::Done, 'b');
        $this->assertFalse($done->cancelQueued());
    }

    public function test_expire_queued_only_from_queued_offline(): void
    {
        $run = $this->stagedRun(TechnicianRunState::QueuedOffline);
        $this->assertTrue($run->expireQueued());
        $this->assertSame(TechnicianRunState::Expired, $run->fresh()->state);
    }

    public function test_reconfirm_expired_rearms_to_awaiting_approval(): void
    {
        $run = $this->stagedRun(TechnicianRunState::Expired);
        $this->assertTrue($run->reconfirmExpired());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_release_claim_to_returns_a_reconnect_run_to_the_queue(): void
    {
        $run = $this->stagedRun(TechnicianRunState::QueuedOffline);
        $run->claimQueuedForExecution();

        $run->releaseClaimTo(TechnicianRunState::QueuedOffline);

        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }
}
