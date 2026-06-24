<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CockpitQueryTest extends TestCase
{
    use RefreshDatabase;

    private function heldRun(): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Hello, we can help.',
        ]);
    }

    public function test_claim_for_execution_is_won_once(): void
    {
        $run = $this->heldRun();

        $this->assertTrue($run->claimForExecution());
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);

        // A second claim (replay / double-tap) loses — the run is no longer awaiting.
        $this->assertFalse($run->fresh()->claimForExecution());
    }

    public function test_deny_and_supersede_transitions(): void
    {
        $a = $this->heldRun();
        $a->deny();
        $this->assertSame(TechnicianRunState::Denied, $a->fresh()->state);

        $b = $this->heldRun();
        $b->markSuperseded();
        $this->assertSame(TechnicianRunState::Superseded, $b->fresh()->state);
    }
}
