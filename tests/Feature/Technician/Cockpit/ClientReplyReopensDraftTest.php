<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientReplyReopensDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reply_dispatches_the_loop_when_enabled(): void
    {
        // Create ticket while DISABLED so TicketObserver::created does NOT dispatch the Loop.
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        // Enable + fake the bus AFTER ticket creation, so the ONLY dispatch captured is the reply hook's.
        Setting::setValue('technician_enabled', '1');
        Bus::fake();

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update on this?');

        Bus::assertDispatched(RunTechnicianLoop::class);
    }

    public function test_portal_reply_does_not_dispatch_when_disabled(): void
    {
        Bus::fake(); // technician_enabled unset → disabled
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update?');

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }
}
