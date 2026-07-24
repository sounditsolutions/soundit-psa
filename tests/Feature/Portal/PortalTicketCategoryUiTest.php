<?php

namespace Tests\Feature\Portal;

use App\Enums\ClientStage;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-717bn.3: the portal ticket list and dashboard show each ticket's ITIL
 * category (leaf label in the row, full path on hover), reusing the shared
 * <x-ticket-category-badge> component. The controllers eager-load
 * categoryNode.parent.parent so the badge's pathString() walks the ancestor
 * chain in-memory. Subjects here deliberately avoid the category words so the
 * assertions prove the badge rendered, not the subject text.
 */
class PortalTicketCategoryUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        // Portal routes are gated by the PortalEnabled middleware (404 when off).
        Setting::setValue('portal_enabled', '1');
    }

    private function portalContact(): Person
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active]);

        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-cat@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);
    }

    private function hardwareLaptop(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Hardware']);

        return TicketCategory::create(['name' => 'Laptop', 'parent_id' => $root->id]);
    }

    public function test_ticket_list_shows_the_category_leaf_and_path(): void
    {
        $person = $this->portalContact();
        $leaf = $this->hardwareLaptop();
        Ticket::factory()->create([
            'client_id' => $person->client_id,
            'status' => 'in_progress',
            'subject' => 'Device will not power on this morning',
            'category_id' => $leaf->id,
        ]);

        $response = $this->actingAs($person, 'portal')->get(route('portal.tickets.index'));

        $response->assertOk();
        $response->assertSee('Laptop');             // leaf label — only the badge emits it
        $response->assertSee('Hardware / Laptop');  // full path — only the badge title emits it
    }

    public function test_dashboard_recent_tickets_show_the_category_path(): void
    {
        $person = $this->portalContact();
        $leaf = $this->hardwareLaptop();
        Ticket::factory()->create([
            'client_id' => $person->client_id,
            'status' => 'in_progress',
            'subject' => 'Screen keeps flickering intermittently',
            'category_id' => $leaf->id,
        ]);

        $response = $this->actingAs($person, 'portal')->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertSee('Hardware / Laptop');
    }

    public function test_uncategorized_ticket_renders_without_error(): void
    {
        $person = $this->portalContact();
        Ticket::factory()->create([
            'client_id' => $person->client_id,
            'status' => 'in_progress',
            'subject' => 'A ticket with no category set',
            'category_id' => null,
        ]);

        $response = $this->actingAs($person, 'portal')->get(route('portal.tickets.index'));

        $response->assertOk();
        $response->assertSee('A ticket with no category set');
    }
}
