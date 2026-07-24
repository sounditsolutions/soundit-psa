<?php

namespace Tests\Feature\Web;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn.4: the "recent tickets" previews on the secondary staff surfaces
 * (client overview, asset detail, person detail, prospect-converted screen)
 * show each ticket's ITIL category via the shared <x-ticket-category-badge>
 * component — leaf in the row, full path on hover, null-safe, retired nodes
 * preserved. Each surface's query eager-loads categoryNode.parent.parent.
 * Subjects deliberately avoid the category words so the assertions prove the
 * badge rendered, not the subject text.
 */
class RecentTicketPreviewCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    private function retiredNode(): TicketCategory
    {
        return TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
    }

    private function assertSeesLeafAndPath($resp): void
    {
        $resp->assertSee('Fake-AV popup');
        $resp->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false);
    }

    // ── client overview preview ──────────────────────────────────────────────

    public function test_client_overview_recent_tickets_show_category(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Machine acting strange',
            'category_id' => $this->tree()->id,
        ]);

        $resp = $this->actingAs($this->staff())->get(route('clients.show', $client))->assertOk();
        $this->assertSeesLeafAndPath($resp);
    }

    public function test_client_overview_is_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'category_id' => $this->retiredNode()->id,
        ]);

        $this->actingAs($this->staff())->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    // ── asset detail preview ─────────────────────────────────────────────────

    public function test_asset_recent_tickets_show_category(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Endpoint misbehaving',
            'category_id' => $this->tree()->id,
        ]);
        $asset->tickets()->attach($ticket->id);

        $resp = $this->actingAs($this->staff())->get(route('assets.show', $asset))->assertOk();
        $this->assertSeesLeafAndPath($resp);
    }

    public function test_asset_recent_tickets_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        $uncategorized = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);
        $retired = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'category_id' => $this->retiredNode()->id,
        ]);
        $asset->tickets()->attach([$uncategorized->id, $retired->id]);

        $this->actingAs($this->staff())->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    // ── person detail preview ────────────────────────────────────────────────

    public function test_person_recent_tickets_show_category(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Example',
            'email' => 'pat@example.test',
            'is_active' => true,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Workstation weirdness',
            'contact_id' => $person->id,
            'category_id' => $this->tree()->id,
        ]);

        $resp = $this->actingAs($this->staff())->get(route('people.show', $person))->assertOk();
        $this->assertSeesLeafAndPath($resp);
    }

    public function test_person_recent_tickets_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Example',
            'email' => 'pat2@example.test',
            'is_active' => true,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'contact_id' => $person->id,
            'category_id' => null,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'contact_id' => $person->id,
            'category_id' => $this->retiredNode()->id,
        ]);

        $this->actingAs($this->staff())->get(route('people.show', $person))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    // ── prospect converted screen ────────────────────────────────────────────

    public function test_prospect_converted_open_tickets_show_category(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::New,
            'subject' => 'Original captured request',
            'category_id' => $this->tree()->id,
        ]);

        $resp = $this->actingAs($this->staff())->get(route('prospects.converted', $client))->assertOk();
        $this->assertSeesLeafAndPath($resp);
    }

    public function test_prospect_converted_is_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::New,
            'category_id' => null,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::New,
            'subject' => 'Old-style request',
            'category_id' => $this->retiredNode()->id,
        ]);

        $this->actingAs($this->staff())->get(route('prospects.converted', $client))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }
}
