<?php

namespace Tests\Feature\Portal;

use App\Enums\ClientStage;
use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Regression for psa-mnmh: PortalTicketController@store handed a TicketPriority
 * *instance* to TicketService::createTicket(), which called TicketPriority::from()
 * on it and threw a TypeError -> 500 on the portal "New Ticket" form. Nothing
 * exercised portal.tickets.store, so it went uncaught. These tests drive the
 * real route end-to-end and assert the urgency -> priority mapping.
 */
class PortalTicketStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation
        // notification). Fake the bus so no queued work escapes the test.
        Bus::fake();
        // Portal routes are gated by the PortalEnabled middleware (404 when off).
        Setting::setValue('portal_enabled', '1');
    }

    /** A portal-enabled contact on an Active client — the legitimate caller. */
    private function portalContact(): Person
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active]);

        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User, // canHavePortal() === true
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-user@example.test',
            'is_active' => true,
            'portal_enabled' => true,
        ]);
    }

    public function test_portal_contact_can_create_a_normal_ticket_mapped_to_p3(): void
    {
        $person = $this->portalContact();

        $response = $this->actingAs($person, 'portal')->post(route('portal.tickets.store'), [
            'subject' => 'Printer on the 3rd floor is offline',
            'description' => 'It stopped responding this morning and will not print.',
        ]);

        $ticket = Ticket::query()->latest('id')->first();

        $this->assertNotNull($ticket, 'Submitting the portal New Ticket form must create a ticket, not 500.');
        $response->assertRedirect(route('portal.tickets.show', $ticket));

        $this->assertSame($person->client_id, $ticket->client_id);
        $this->assertSame($person->id, $ticket->contact_id);
        $this->assertSame('Printer on the 3rd floor is offline', $ticket->subject);
        $this->assertSame(TicketSource::Portal, $ticket->source);
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketStatus::New, $ticket->status);
        // Normal urgency maps to P3.
        $this->assertSame(TicketPriority::P3, $ticket->priority);
    }

    public function test_urgent_portal_ticket_is_mapped_to_p2(): void
    {
        $person = $this->portalContact();

        $response = $this->actingAs($person, 'portal')->post(route('portal.tickets.store'), [
            'subject' => 'Whole office cannot reach the internet',
            'description' => 'No connectivity across the building since 9am — business is down.',
            'urgent' => '1',
        ]);

        $ticket = Ticket::query()->latest('id')->first();

        $this->assertNotNull($ticket);
        $response->assertRedirect(route('portal.tickets.show', $ticket));
        // Urgent urgency maps to P2.
        $this->assertSame(TicketPriority::P2, $ticket->priority);
    }
}
