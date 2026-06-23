<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
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
}
