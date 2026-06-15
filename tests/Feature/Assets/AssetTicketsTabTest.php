<?php

namespace Tests\Feature\Assets;

use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTicketsTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_tab_with_only_resolved_ticket_surfaces_closed_count(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);
        $asset->tickets()->attach($ticket);

        $resp = $this->actingAs($user)->get(route('assets.tickets', $asset));

        $resp->assertOk();
        // Should NOT say "No tickets found" as if there's no history
        $resp->assertDontSee('No tickets found');
        // Should surface that closed/resolved tickets exist
        $resp->assertSee('show_closed', false);
    }

    public function test_tickets_tab_with_show_closed_reveals_resolved_ticket(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'subject' => 'My resolved ticket',
        ]);
        $asset->tickets()->attach($ticket);

        $resp = $this->actingAs($user)->get(route('assets.tickets', $asset).'?show_closed=1');

        $resp->assertOk();
        $resp->assertSee('My resolved ticket');
    }

    public function test_tickets_tab_with_open_ticket_shows_normally(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Open ticket subject',
        ]);
        $asset->tickets()->attach($ticket);

        $resp = $this->actingAs($user)->get(route('assets.tickets', $asset));

        $resp->assertOk();
        $resp->assertSee('Open ticket subject');
    }
}
