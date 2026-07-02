<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Jobs\RunTechnicianAgent;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechnicianLoopDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_active_client_ticket_dispatches_the_loop_when_enabled(): void
    {
        Setting::setValue('technician_enabled', '1');
        $client = Client::factory()->create(); // Active

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertDispatched(RunTechnicianLoop::class);
    }

    public function test_disabled_technician_does_not_dispatch_the_loop(): void
    {
        // technician_enabled unset → disabled.
        $client = Client::factory()->create();

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_emergency_only_backstop_does_not_dispatch_the_draft_loop(): void
    {
        Setting::setValue('technician_enabled', '0');
        Setting::setValue('technician_emergency_enabled', '1');
        $client = Client::factory()->create();

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_prospect_ticket_never_dispatches_the_loop(): void
    {
        Setting::setValue('technician_enabled', '1');
        $prospect = Client::factory()->prospect()->create();

        Ticket::factory()->create(['client_id' => $prospect->id]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_ticket_created_by_the_ai_actor_does_not_dispatch_the_loop(): void
    {
        Setting::setValue('technician_enabled', '1');
        $user = \App\Models\User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $user->id);
        $actorId = $user->id;
        $client = Client::factory()->create();

        Ticket::factory()->create([
            'client_id' => $client->id,
            'created_by' => $actorId,
        ]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_handle_creates_a_run_idempotently(): void
    {
        // Run the job body directly (Bus::fake only intercepts dispatch).
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();
        (new RunTechnicianLoop($ticket->id))->handle(); // second run must not duplicate

        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->count(),
        );
        $this->assertDatabaseHas('technician_runs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'send_ack',
            'state' => TechnicianRunState::Gathering->value,
        ]);
    }

    // ── A2b: the loop wakes the reactive agent for client replies (gated, dormant) ──

    public function test_handle_wakes_the_agent_for_replies_when_the_agent_is_enabled(): void
    {
        // A2b: with the agent enabled, an inbound trigger wakes RunTechnicianAgent so the
        // agent can draft a held reply — it is the sole producer of held send_reply runs.
        Setting::setValue('agent_enabled', '1');
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        Bus::assertDispatched(RunTechnicianAgent::class, fn (RunTechnicianAgent $job) => $job->ticketId === $ticket->id);
    }

    public function test_handle_does_not_wake_the_agent_when_the_agent_is_disabled(): void
    {
        // Dormant by default: the reply capability ships off until the operator enables the
        // agent. The ack + resolution pipeline still run; only the agent wake is withheld.
        $client = Client::factory()->create(); // agent_enabled unset → off
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        Bus::assertNotDispatched(RunTechnicianAgent::class);
    }

    /**
     * Known, documented limitation (A2b): when a propose_close is already awaiting approval,
     * the agent's dedup guard (#5) defers the WHOLE agent — including the reply-wake — so no
     * reply is drafted alongside an unresolved close. Characterized here so the behavior is
     * intentional, not accidental. (The agent never reaches the SignificanceGate.)
     */
    public function test_a_pending_close_defers_the_reply_wake(): void
    {
        Setting::setValue('agent_enabled', '1');
        $this->mock(\App\Services\Agent\SignificanceGate::class, fn ($m) => $m->shouldReceive('assess')->never());

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => \App\Enums\TicketStatus::InProgress]);
        // A close proposal is already held for this ticket.
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'propose_close',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'stale close', 'tokens_used' => 0,
        ]);

        (new RunTechnicianAgent($ticket->id))->handle();

        // No reply (or any new run) was produced — the agent deferred at guard #5.
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }
}
