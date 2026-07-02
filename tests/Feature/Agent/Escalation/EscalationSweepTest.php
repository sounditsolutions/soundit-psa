<?php

namespace Tests\Feature\Agent\Escalation;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Agent\Escalation\EscalationSweep;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Task 4 — "never silently stuck" degradation sweep.
 *
 * Verifies that EscalationSweep correctly re-delivers unacked flag_attention
 * escalations, advances the chain, skips unavailable members, re-pings the last
 * member when the chain is exhausted, and is dormant when escalation is disabled.
 *
 * EscalationNotifier is mocked throughout — we test chain logic and gating, not
 * delivery plumbing (covered by EscalationNotifierTest).
 */
class EscalationSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $u1;

    private User $u2;

    private User $u3;

    private Client $client;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->u1 = User::factory()->create(['name' => 'Op1', 'email' => 'op1@example.com']);
        $this->u2 = User::factory()->create(['name' => 'Op2', 'email' => 'op2@example.com']);
        $this->u3 = User::factory()->create(['name' => 'Op3', 'email' => 'op3@example.com']);

        $this->client = Client::factory()->create();
        $this->ticket = Ticket::factory()->for($this->client)->create();

        // Default: escalation enabled, chain = [u1, u2, u3]
        Setting::setValue('agent_escalation_enabled', '1');
        Setting::setValue('technician_escalation_chain', json_encode([$this->u1->id, $this->u2->id, $this->u3->id]));
    }

    /**
     * Create a Flagged flag_attention run with realistic proposed_meta.
     *
     * @param  array  $escalationOverrides  Merged into the escalation sub-dict.
     */
    private function makeRun(
        TechnicianRunState $state = TechnicianRunState::Flagged,
        array $escalationOverrides = [],
    ): TechnicianRun {
        $escalation = array_merge([
            'recipient_user_id' => $this->u1->id,
            'category' => 'needs_decision',
            'notified_at' => now()->subHours(3)->toIso8601String(), // 3h ago — past any window
            'step' => 0,
        ], $escalationOverrides);

        return TechnicianRun::create([
            'ticket_id' => $this->ticket->id,
            'client_id' => $this->client->id,
            'action_type' => 'flag_attention',
            'content_hash' => str_repeat('a', 64),
            'state' => $state,
            'proposed_meta' => [
                'category' => 'needs_decision',
                'reason' => 'This ticket needs human attention',
                'escalation' => $escalation,
            ],
        ]);
    }

    // ── Test 1: unacked past the window → advances + re-delivers ─────────────

    /**
     * A Flagged run whose escalation.notified_at is older than the reping window
     * must advance step 0 → 1 and re-deliver to the NEXT chain member (u2).
     */
    public function test_unacked_past_window_advances_and_redelivers_to_next_chain_member(): void
    {
        $this->makeRun(); // step=0, notified 3h ago (default window is 120 min)

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')
                ->once()
                ->with(
                    \Mockery::type(Ticket::class),
                    \Mockery::type(TechnicianRun::class),
                    \Mockery::on(fn (?User $u) => $u !== null && $u->id === $this->u2->id),
                    \Mockery::type('string'),
                    1, // step advances from 0 to 1
                );
        });

        $count = app(EscalationSweep::class)->sweep();

        $this->assertSame(1, $count, 'sweep() must return the number of re-nudged escalations.');
    }

    // ── Test 2: recent (within window) → skipped ─────────────────────────────

    /**
     * A run notified only 5 minutes ago is within the default 120-minute window
     * and must NOT be re-delivered.
     */
    public function test_recently_notified_within_window_is_skipped(): void
    {
        $this->makeRun(escalationOverrides: [
            'notified_at' => now()->subMinutes(5)->toIso8601String(),
        ]);

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')->never();
        });

        $count = app(EscalationSweep::class)->sweep();

        $this->assertSame(0, $count);
    }

    public function test_suppressed_run_without_notified_at_is_skipped(): void
    {
        $this->makeRun(escalationOverrides: [
            'status' => 'suppressed',
            'noise_to_owner' => 'duplicate_client_escalation',
            'suppression_kind' => 'open_client_flag',
            'suppressed_at' => now()->subHours(3)->toIso8601String(),
            'notified_at' => null,
        ]);

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')->never();
        });

        $count = app(EscalationSweep::class)->sweep();

        $this->assertSame(0, $count, 'Suppressed flags are cockpit records, not re-ping candidates.');
    }

    // ── Test 3: acked (no longer Flagged) → skipped ──────────────────────────

    /**
     * A run that has been acknowledged (→ Done) or dismissed (→ Denied) is no
     * longer Flagged and must not be re-delivered by the sweep.
     */
    public function test_acked_run_no_longer_flagged_is_skipped(): void
    {
        $this->makeRun(state: TechnicianRunState::Done); // human has acted

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')->never();
        });

        $count = app(EscalationSweep::class)->sweep();

        $this->assertSame(0, $count, 'Acked (Done/Denied) runs must be invisible to the sweep.');
    }

    // ── Test 4: disabled → dormant ────────────────────────────────────────────

    /**
     * When agent_escalation_enabled is not set (or off), sweep() must return 0
     * and never invoke deliverTo — the whole subsystem is dormant.
     */
    public function test_disabled_sweep_is_dormant(): void
    {
        Setting::setValue('agent_escalation_enabled', '0');
        $this->makeRun(); // would be a candidate if enabled

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')->never();
        });

        $count = app(EscalationSweep::class)->sweep();

        $this->assertSame(0, $count, 'sweep() must return 0 and do nothing when escalation is disabled.');
    }

    // ── Test 5: unavailable chain member is skipped ───────────────────────────

    /**
     * If the next candidate in the chain (u2, index 1) is marked unavailable,
     * the sweep must skip it and deliver to the following available member (u3, index 2).
     */
    public function test_unavailable_chain_member_is_skipped_to_next_available(): void
    {
        TechnicianConfig::setOperatorAvailable($this->u2->id, false);
        $this->makeRun(); // step=0, next would be u2 but unavailable

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')
                ->once()
                ->with(
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::on(fn (?User $u) => $u !== null && $u->id === $this->u3->id),
                    \Mockery::any(),
                    2, // u3 is at chain index 2
                );
        });

        app(EscalationSweep::class)->sweep();
    }

    // ── Test 6: chain end → re-pings the last member, never drops ────────────

    /**
     * When the run is already at the last chain index (step = count - 1), the
     * sweep must re-deliver to the same last member rather than dropping the
     * escalation.
     */
    public function test_chain_end_repings_last_member_and_does_not_drop(): void
    {
        $this->makeRun(escalationOverrides: [
            'recipient_user_id' => $this->u3->id,
            'step' => 2, // already at chain end (chain has 3 members, last index = 2)
        ]);

        $this->mock(EscalationNotifier::class, function (MockInterface $m) {
            $m->shouldReceive('deliverTo')
                ->once()
                ->with(
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::on(fn (?User $u) => $u !== null && $u->id === $this->u3->id),
                    \Mockery::any(),
                    2, // re-ping at same index — escalation is never dropped
                );
        });

        app(EscalationSweep::class)->sweep();
    }
}
