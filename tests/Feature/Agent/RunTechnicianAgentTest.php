<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\TechnicianAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RunTechnicianAgent — the reactive wake job + review-ping branch (Task 7).
 *
 * The agent is REACTIVE: it works whatever ticket the review pass surfaces.
 * No cooldown, no coverage logic — the review cadence handles which/when.
 *
 * Tests (7):
 *   1. Dormant — agent disabled: handle() no-ops; TriageReviewOpen does NOT push RunTechnicianAgent.
 *   2. Enabled + eligible — handle() calls gate (mock true) → TechnicianAgent::run() once.
 *   3. Operational re-check — non-operational client (prospect): gate/agent never called.
 *   4. Dedup — ticket with existing AwaitingApproval propose_close → no-op.
 *   5. Depth-cap — global AwaitingApproval count >= maxPendingProposals → no-op.
 *   6. Gate says no — SignificanceGate returns false → TechnicianAgent::run NOT called.
 *   7. Branch dispatches — review command prereqs met + agent enabled → RunTechnicianAgent pushed.
 */
class RunTechnicianAgentTest extends TestCase
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

    /** Enable all prerequisites for TriageReviewOpen to enter its dispatch loop. */
    private function enableReviewCommandPrereqs(): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_review', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key'); // AiConfig::isConfigured() → true
    }

    // ── 1. Dormant ───────────────────────────────────────────────────────────

    /**
     * When agent_enabled is absent/false, handle() must not touch the gate or
     * the agent. The review command must also NOT push RunTechnicianAgent even
     * when triage prerequisites are fully met.
     */
    public function test_dormant_job_does_not_call_gate_or_agent_and_command_does_not_dispatch(): void
    {
        // agent_enabled absent → AgentConfig::enabled() = false (dormant by default)
        $ticket = $this->openTicketWithOperationalClient();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        // Part A: handle() no-ops (dormancy guard fires first).
        (new RunTechnicianAgent($ticket->id))->handle();

        // Part B: command does NOT push RunTechnicianAgent even with all prereqs met.
        Queue::fake();
        $this->enableReviewCommandPrereqs();
        $this->artisan('triage:review-open');
        Queue::assertNotPushed(RunTechnicianAgent::class);
    }

    // ── 2. Enabled + eligible ─────────────────────────────────────────────────

    /**
     * Fully eligible ticket: gate called once → (mock true) → agent runs once.
     */
    public function test_enabled_eligible_ticket_calls_gate_then_agent(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(true);

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->once();

        (new RunTechnicianAgent($ticket->id))->handle();
    }

    // ── 3. Operational re-check ───────────────────────────────────────────────

    /**
     * CO-8: The client must be Active AND is_active at execute-time.
     * A prospect client (stage=Prospect) is not operational → gate/agent never called.
     */
    public function test_non_operational_client_skips_gate_and_agent(): void
    {
        $this->enableAgent();

        $prospect = Client::factory()->prospect()->create(); // stage=Prospect → not operational
        $ticket = Ticket::factory()->for($prospect)->create(['status' => TicketStatus::InProgress]);

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();
    }

    // ── 4. Dedup ──────────────────────────────────────────────────────────────

    /**
     * CO-5: Don't re-propose if a propose_close run is already AwaitingApproval
     * for this ticket. Gate/agent must never be called.
     */
    public function test_dedup_skips_ticket_with_awaiting_approval_proposal(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        // Plant an existing AwaitingApproval propose_close run for this ticket.
        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_close',
            'content_hash' => 'abc123',
            'state' => TechnicianRunState::AwaitingApproval,
        ]);

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();
    }

    // ── 5. Depth-cap ──────────────────────────────────────────────────────────

    /**
     * CO-11: Global count of AwaitingApproval propose_close runs >= maxPendingProposals
     * → no-op (anti-flood guard). Gate/agent must never be called.
     */
    public function test_depth_cap_skips_when_max_pending_proposals_reached(): void
    {
        $this->enableAgent();

        // Lower maxPendingProposals to 1 so one existing run trips the cap.
        Setting::setValue('agent_max_pending', '1');

        $ticket = $this->openTicketWithOperationalClient();

        // One GLOBAL AwaitingApproval propose_close run on a DIFFERENT ticket.
        $otherClient = Client::factory()->create();
        $otherTicket = Ticket::factory()->for($otherClient)->create(['status' => TicketStatus::InProgress]);
        TechnicianRun::create([
            'ticket_id' => $otherTicket->id,
            'client_id' => $otherClient->id,
            'action_type' => 'propose_close',
            'content_hash' => 'xyz999',
            'state' => TechnicianRunState::AwaitingApproval,
        ]);

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();
    }

    // ── 6. Gate says no ───────────────────────────────────────────────────────

    /**
     * SignificanceGate returning false → TechnicianAgent::run must NOT be called.
     */
    public function test_gate_returning_false_does_not_call_agent(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(false);

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();
    }

    // ── 7. Branch dispatches ──────────────────────────────────────────────────

    /**
     * CO-17: When the review command's triage prerequisites are met AND agent_enabled is
     * true, the additive branch dispatches RunTechnicianAgent for each reviewed ticket.
     * The branch is purely additive — existing review logic is unchanged.
     */
    public function test_review_command_dispatches_run_technician_agent_when_agent_enabled(): void
    {
        $this->enableReviewCommandPrereqs();
        $this->enableAgent();

        $ticket = $this->openTicketWithOperationalClient(); // open + operational

        Queue::fake();
        $this->artisan('triage:review-open');

        Queue::assertPushed(RunTechnicianAgent::class, function ($job) use ($ticket) {
            return $job->ticketId === $ticket->id;
        });
    }
}
