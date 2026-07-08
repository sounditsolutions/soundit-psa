<?php

namespace Tests\Feature\Agent;

use App\Enums\EmergencyState;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\TechnicianAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * RunTechnicianAgent — correctionDriven re-run behaviour (Task 4, psa-gofv).
 *
 * Safety contract:
 *   - correctionDriven=true SKIPS #5 (dedup), #6 (depth-cap), #7 (change-throttle),
 *     #8 (significance gate) — an operator correction ALWAYS re-assesses (psa-rmus).
 *   - correctionDriven=true does NOT skip #1 (dormancy) or #4.5 (emergency halt) — the key
 *     safety invariants.
 *   - correctionDriven=false (default) keeps ALL guards — no regression.
 */
class RunTechnicianAgentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableAgent(): void
    {
        Setting::setValue('agent_enabled', '1');
    }

    /** Open ticket with an operational client (Active stage + is_active). */
    private function openTicketWithOperationalClient(): Ticket
    {
        $client = Client::factory()->create(); // defaults: stage=Active, is_active=true

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    private function openEmergencyFor(Ticket $ticket): TechnicianEmergency
    {
        return TechnicianEmergency::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'signature' => 's',
            'severity' => 3,
            'reasons' => ['age'],
            'detected_by' => 'rules',
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => [$ticket->id],
            'alerted_at' => now(),
        ]);
    }

    // ── (a) Throttle SKIPPED for correction-driven ────────────────────────────

    /**
     * A correction-driven run bypasses the change-throttle (guard #7).
     * We plant the cache marker so a NORMAL run would be suppressed, then
     * assert the agent is still called when correctionDriven=true.
     */
    public function test_correction_driven_skips_change_throttle(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        // Travel forward so the cache marker is strictly AFTER ticket->updated_at.
        $this->travel(2)->seconds();
        Cache::put("agent_eval:{$ticket->id}", now()->timestamp, now()->addDays(30));

        // A normal run would be throttled here — but correction-driven must proceed (and the
        // significance gate is now skipped too, psa-rmus — so assess is never consulted).
        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->once();

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();
    }

    // ── (b) Throttle KEPT for normal runs (regression) ───────────────────────

    /**
     * Non-correction-driven runs still respect the change-throttle.
     * Same throttle setup as (a), but correctionDriven defaults to false
     * → gate/agent must never be reached.
     */
    public function test_normal_run_respects_change_throttle(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        // Same throttle setup as (a).
        $this->travel(2)->seconds();
        Cache::put("agent_eval:{$ticket->id}", now()->timestamp, now()->addDays(30));

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id, correctionDriven: false))->handle();
    }

    // ── (c) Emergency STILL halts correction-driven — THE safety invariant ───

    /**
     * Guard #4.5 (TechnicianEmergency::hasOpenEmergency) is UNCONDITIONAL —
     * it fires even when correctionDriven=true.  The gate must never be reached,
     * and the agent must never run.
     */
    public function test_emergency_still_halts_correction_driven_run(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $this->openEmergencyFor($ticket);

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();
    }

    // ── (d) psa-rmus FIX 1 — the SignificanceGate must NOT veto an operator correction ──

    /**
     * THE prod bug: the cheap Haiku "is this worth a look?" gate exists for the autonomous
     * review pass — it must NEVER veto an EXPLICIT operator correction (which silently
     * no-op'd Charlie's corrections). For a correction-driven run the gate is not consulted.
     */
    public function test_correction_driven_skips_the_significance_gate(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        // The gate must NEVER be reached — a correction always re-assesses.
        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->once();

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();
    }

    /**
     * A correction SUPERSEDES an existing proposal (replaces, doesn't add to the flood), so
     * the anti-flood depth-cap must not silently drop it.
     */
    public function test_correction_driven_skips_the_depth_cap(): void
    {
        $this->enableAgent();
        Setting::setValue('agent_max_pending', '1');

        // Fill the global pending-propose_close cap with a proposal on ANOTHER ticket.
        $other = $this->openTicketWithOperationalClient();
        TechnicianRun::create([
            'ticket_id' => $other->id, 'client_id' => $other->client_id,
            'action_type' => 'propose_close', 'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'pending', 'tokens_used' => 0,
        ]);

        $ticket = $this->openTicketWithOperationalClient();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never(); // also skipped for correction-driven
        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->once(); // depth-cap skipped → the correction still re-assesses

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();
    }

    /**
     * Safety unchanged: with the agent disabled (dormancy guard #1), even a correction-driven
     * run must NOT fire.
     */
    public function test_dormancy_still_blocks_correction_driven_run(): void
    {
        // agent_enabled deliberately NOT set → dormant.
        $ticket = $this->openTicketWithOperationalClient();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();
    }
}
