<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\Steering\ReassessTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ReassessTrigger — supersedes the corrected run and dispatches a
 * correction-driven re-run of the agent (Task 4, psa-gofv).
 */
class ReassessTriggerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * (d) reassess() supersedes the corrected run and pushes a correction-driven job.
     */
    public function test_reassess_supersedes_run_and_dispatches_correction_driven_job(): void
    {
        Queue::fake();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => 'abc123',
            'state' => TechnicianRunState::AwaitingApproval,
        ]);

        app(ReassessTrigger::class)->reassess($ticket, $run);

        $this->assertSame(TechnicianRunState::Superseded, $run->fresh()->state);

        Queue::assertPushed(RunTechnicianAgent::class, function ($job) use ($ticket) {
            return $job->ticketId === $ticket->id && $job->correctionDriven === true;
        });
    }
}
