<?php

namespace Tests\Feature\Technician;

use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechnicianQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_loop_runs_on_the_dedicated_technician_queue(): void
    {
        Bus::fake();
        Setting::setValue('technician_enabled', '1');
        $client = Client::factory()->create();

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertDispatched(RunTechnicianLoop::class, fn (RunTechnicianLoop $job) => $job->queue === 'technician');
    }
}
