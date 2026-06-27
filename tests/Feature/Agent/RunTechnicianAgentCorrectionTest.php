<?php

namespace Tests\Feature\Agent;

use App\Enums\EmergencyState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
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
 *   - correctionDriven=true SKIPS guard #5 (dedup) and guard #7 (change-throttle).
 *   - correctionDriven=true does NOT skip #4.5 (emergency halt) — the key safety invariant.
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

        // A normal run would be throttled here — but correction-driven must proceed.
        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(true);

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
}
