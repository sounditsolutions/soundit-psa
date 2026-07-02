<?php

namespace Tests\Feature\Prospect;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Regression coverage for psa-qkhp: staff intake and ticketing surfaces must
 * offer active prospects, while operational-only surfaces stay on
 * Client::operational() in their own tests.
 */
class ProspectDropdownAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function prospect(string $name = 'Prospect Co'): Client
    {
        return Client::factory()->prospect()->create(['name' => $name]);
    }

    private function phoneCall(): PhoneCall
    {
        return PhoneCall::create([
            'call_uuid' => uniqid('psa-qkhp-', true),
            'direction' => CallDirection::Inbound->value,
            'from_number' => '+15555550123',
            'to_number' => '+15555550100',
            'status' => CallStatus::Completed->value,
            'started_at' => now(),
        ]);
    }

    private function email(array $attributes = []): Email
    {
        return Email::create($attributes + [
            'graph_id' => uniqid('graph_', true),
            'direction' => EmailDirection::Inbound->value,
            'from_address' => 'caller@prospect.example',
            'from_name' => 'Prospect Caller',
            'subject' => 'Need help evaluating service',
            'body_preview' => 'We need help.',
            'body_text' => 'We need help.',
            'received_at' => now(),
            'is_read' => false,
        ]);
    }

    private function assertViewCollectionContainsClient($response, string $key, Client $client, string $message): void
    {
        $response->assertOk();
        $this->assertTrue(
            $response->viewData($key)->contains('id', $client->id),
            $message,
        );
    }

    public function test_call_detail_manual_caller_resolution_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('calls.show', $this->phoneCall()));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from call caller-resolution client list.',
        );
    }

    public function test_call_create_ticket_form_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('calls.create-ticket', $this->phoneCall()));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from call-created ticket client list.',
        );
    }

    public function test_email_index_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('emails.index'));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from email index client filter.',
        );
    }

    public function test_unresolved_email_client_linking_offers_and_suggests_prospect_clients(): void
    {
        $prospect = $this->prospect();
        $prospect->update(['website' => 'https://prospect.example']);

        $response = $this->actingAs($this->staff())->get(route('emails.show', $this->email()));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from unresolved email client-link list.',
        );
        $this->assertSame(
            $prospect->id,
            $response->viewData('suggestedClientId'),
            'Prospect client should be suggestable from sender domain.',
        );
    }

    public function test_email_create_ticket_form_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('emails.create-ticket', $this->email()));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from email-created ticket client list.',
        );
    }

    public function test_ticket_index_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('tickets.index'));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from ticket index client filter.',
        );
    }

    public function test_ticket_create_form_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('tickets.create'));

        $this->assertViewCollectionContainsClient(
            $response,
            'clients',
            $prospect,
            'Prospect client missing from ticket create client list.',
        );
    }

    public function test_dashboard_ticket_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('dashboard'));

        $this->assertViewCollectionContainsClient(
            $response,
            'ticketClients',
            $prospect,
            'Prospect client missing from dashboard ticket client filter.',
        );
    }

    public function test_person_ticket_tab_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();
        $person = Person::create([
            'client_id' => $prospect->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Prospect',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff())->get(route('people.tickets', $person));

        $this->assertViewCollectionContainsClient(
            $response,
            'ticketClients',
            $prospect,
            'Prospect client missing from person ticket-tab client filter.',
        );
    }

    public function test_client_ticket_tab_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('clients.tickets', $prospect));

        $this->assertViewCollectionContainsClient(
            $response,
            'ticketClients',
            $prospect,
            'Prospect client missing from client ticket-tab client filter.',
        );
    }

    public function test_intake_client_search_endpoint_offers_prospect_clients(): void
    {
        $prospect = $this->prospect('Searchable Prospect');

        $response = $this->actingAs($this->staff())->getJson(route('clients.search', ['q' => 'Searchable']));

        $response->assertOk();
        $this->assertTrue(
            collect($response->json())->contains('id', $prospect->id),
            'Prospect client missing from prospect-inclusive intake client search endpoint.',
        );
    }

    public function test_operational_client_search_endpoint_stays_operational_only_for_integration_mapping(): void
    {
        $active = Client::factory()->create(['name' => 'Mapping Active']);
        $prospect = $this->prospect('Mapping Prospect');

        $response = $this->actingAs($this->staff())->getJson(route('api.clients.search', ['q' => 'Mapping']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($active->id), 'Operational client missing from integration client search.');
        $this->assertFalse($ids->contains($prospect->id), 'Prospect client leaked into operational integration client search.');
    }

    public function test_email_reassign_typeahead_uses_prospect_inclusive_search_endpoint(): void
    {
        $email = $this->email(['client_id' => Client::factory()->create()->id]);

        $response = $this->actingAs($this->staff())->get(route('emails.show', $email));

        $response->assertOk();
        $response->assertSee(route('clients.search').'?q=', false);
        $response->assertDontSee(route('api.clients.search').'?q=', false);
    }

    public function test_email_reassigning_linked_ticket_to_prospect_uses_ticket_move_cleanup(): void
    {
        $user = $this->staff();
        $oldClient = Client::factory()->create(['name' => 'Operational Source']);
        $prospect = $this->prospect('Prospect Destination');
        $contract = Contract::create([
            'client_id' => $oldClient->id,
            'name' => 'Operational Support Contract',
            'type' => 'managed',
            'start_date' => now()->toDateString(),
        ]);
        $asset = Asset::factory()->create(['client_id' => $oldClient->id]);
        $ticket = Ticket::factory()->create([
            'client_id' => $oldClient->id,
            'contract_id' => $contract->id,
        ]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);
        $email = $this->email([
            'client_id' => $oldClient->id,
            'ticket_id' => $ticket->id,
        ]);

        $response = $this->actingAs($user)->patch(route('emails.reassign-client', $email), [
            'client_id' => $prospect->id,
            'update_ticket' => '1',
        ]);

        $response->assertRedirect();
        $email->refresh();
        $ticket->refresh();

        $this->assertSame($prospect->id, $email->client_id);
        $this->assertSame($prospect->id, $ticket->client_id);
        $this->assertNull($ticket->contact_id);
        $this->assertNull($ticket->contract_id);
        $this->assertSame(0, $ticket->assets()->count());
        $this->assertTrue(
            TicketNote::where('ticket_id', $ticket->id)
                ->where('body', 'like', '%Ticket moved from **Operational Source** to **Prospect Destination**%')
                ->where('body', 'like', '%Contract cleared: Operational Support Contract.%')
                ->where('body', 'like', '%Assets detached:%')
                ->exists(),
            'Linked ticket cascade should use TicketService::moveToClient() cleanup and audit note.',
        );
    }

    public function test_ticket_move_typeahead_uses_prospect_inclusive_search_endpoint(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->staff())->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee(route('clients.search').'?q=', false);
        $response->assertDontSee(route('api.clients.search').'?q=', false);
    }
}
