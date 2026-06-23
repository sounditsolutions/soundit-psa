<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechnicianRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_a_run_starts_in_gathering_and_advances(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $this->assertSame(TechnicianRunState::Gathering, $run->state);

        $run->advanceTo(TechnicianRunState::Done);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_runs', [
            'id' => $run->id,
            'state' => 'done',
        ]);
    }

    public function test_idempotency_key_blocks_a_duplicate(): void
    {
        $ticket = Ticket::factory()->create();
        $attrs = [
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('b', 64),
            'state' => TechnicianRunState::Gathering,
        ];

        TechnicianRun::create($attrs);

        $this->expectException(QueryException::class);
        TechnicianRun::create($attrs); // same ticket + action_type + content_hash
    }

    public function test_same_ticket_different_action_is_allowed(): void
    {
        $ticket = Ticket::factory()->create();

        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('c', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $second = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('c', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $this->assertDatabaseHas('technician_runs', ['id' => $second->id]);
    }
}
