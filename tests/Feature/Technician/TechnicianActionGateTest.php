<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianApprovalGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TechnicianActionGateTest extends TestCase
{
    use RefreshDatabase;

    private int $actorId;

    private int $ticketId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorId = User::factory()->create()->id; // first user = AI actor fallback
        $client = Client::factory()->create();
        $this->clientId = $client->id;
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->ticketId = $ticket->id;
    }

    private function gate(): TechnicianActionGate
    {
        return app(TechnicianActionGate::class);
    }

    private function autoTier(string $type): void
    {
        Setting::setValue('technician_action_tiers', json_encode([$type => 'auto']));
    }

    public function test_auto_action_executes_and_audits_executed(): void
    {
        $this->autoTier('send_ack');
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertTrue($ran);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianTier::Auto, $result->tier);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'executed',
            'actor_label' => 'ai-technician',
            'actor_id' => $this->actorId,
            'tier' => 'auto',
        ]);
    }

    public function test_approve_action_without_grant_records_awaiting_and_does_not_execute(): void
    {
        // 'send_reply' is unmapped → default-deny → Approve.
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('b', 64),
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_reply',
            'result_status' => 'awaiting_approval',
            'tier' => 'approve',
        ]);
    }

    public function test_approve_action_with_valid_grant_executes(): void
    {
        $approver = User::factory()->create();
        $hash = str_repeat('c', 64);
        $token = TechnicianApprovalGrant::issue('send_reply', $this->ticketId, $hash, $approver->id);
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertTrue($ran);
        $this->assertSame('executed', $result->status);
    }

    public function test_grant_for_a_different_hash_is_rejected(): void
    {
        $approver = User::factory()->create();
        $token = TechnicianApprovalGrant::issue('send_reply', $this->ticketId, str_repeat('c', 64), $approver->id);
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('d', 64), // different content than the grant
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
    }

    public function test_block_tier_is_refused(): void
    {
        Setting::setValue('technician_action_tiers', json_encode(['run_script' => 'block']));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'run_script',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('e', 64),
            summary: 'script',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertSame('blocked', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'run_script',
            'result_status' => 'blocked',
            'tier' => 'block',
        ]);
    }

    public function test_always_human_client_forces_approval_even_for_auto_action(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_always_human_client_ids', json_encode([$this->clientId]));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('f', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
    }

    public function test_excluded_client_is_held(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_excluded_client_ids', json_encode([$this->clientId]));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'result_status' => 'held',
        ]);
    }

    // -------------------------------------------------------------------------
    // Kill-switch tests (§7 / §13 fire-drill)
    // -------------------------------------------------------------------------

    public function test_kill_switch_holds_an_auto_action_before_execution(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_kill_switch', '1');
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'held',
        ]);
    }

    /**
     * In-flight kill-switch test: an approved action executes once (switch off),
     * then the operator pulls the cord, and the NEXT dispatch — even with a still-
     * valid grant — is immediately held by the gate's kill-switch barrier.  This
     * proves both the pre-classification check (catches it on entry) and the
     * structural guarantee that a valid grant cannot bypass the kill-switch.
     */
    public function test_kill_switch_flipped_in_flight_halts_an_approved_action(): void
    {
        $approver = User::factory()->create();
        $hash = str_repeat('c', 64);
        $token = TechnicianApprovalGrant::issue('send_reply', $this->ticketId, $hash, $approver->id);

        // First dispatch executes (switch off).
        $first = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () { /* sent */
            },
            approvalToken: $token,
            approverUserId: $approver->id,
        );
        $this->assertSame('executed', $first->status);

        // Operator pulls the cord.
        Setting::setValue('technician_kill_switch', '1');
        $ran = false;

        $second = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $second->status);
        $this->assertDatabaseHas('technician_action_logs', ['result_status' => 'held', 'action_type' => 'send_reply']);
    }

    /**
     * True in-flight re-check: the kill-switch is OFF when the gate's
     * pre-classification check (check #1) runs, then trips to ON between that
     * check and the gate's second killSwitchEngaged() call immediately before
     * the executor (check #2).  Proven against the REAL TechnicianActionGate
     * without modification.
     *
     * Mechanism: DB::listen fires after each completed query.  When the gate
     * executes check #1 (SELECT key='technician_kill_switch'), the listener
     * fires after the query returns false — setting the DB value to '1' for the
     * subsequent check #2 to observe.  The first check has already resolved to
     * false; the second check reads the freshly-written '1' and halts.
     */
    public function test_in_flight_recheck_halts_approved_auto_action_when_switch_trips_between_checks(): void
    {
        $this->autoTier('send_ack');

        // Kill-switch starts OFF.
        Setting::setValue('technician_kill_switch', '0');

        $flipped = false;
        DB::listen(function ($query) use (&$flipped) {
            // Fire exactly once: after check #1's query for the kill-switch
            // setting returns.  The gate's check #1 has already seen 'false';
            // we now write '1' so check #2 observes it as engaged.
            if (! $flipped
                && str_contains($query->sql, 'settings')
                && in_array('technician_kill_switch', $query->bindings, true)
            ) {
                $flipped = true;
                Setting::setValue('technician_kill_switch', '1');
            }
        });

        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: $this->ticketId,
            clientId: $this->clientId,
            contentHash: str_repeat('e', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) {
                $ran = true;
            },
        );

        $this->assertTrue($flipped, 'DB listener should have fired (sanity check)');
        $this->assertFalse($ran, 'Executor must not run when kill-switch trips between checks');
        $this->assertSame('held', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'held',
        ]);
    }
}
