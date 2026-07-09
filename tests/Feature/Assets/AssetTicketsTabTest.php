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

    /**
     * Regression (psa-cvpf): AssetController@tickets renders assets/show.blade.php
     * but omitted `cometJobData` from the view data. The Comet backup section of
     * that view — guarded by `@if($asset->comet_device_id)` — references
     * `$cometJobData`, so the tickets route 500'd ("Undefined variable
     * $cometJobData") for any asset with a comet device id. Assets without one
     * (the default factory state) skip the block, which is why the existing tab
     * tests never caught it.
     */
    public function test_tickets_tab_renders_for_comet_linked_asset(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['comet_device_id' => 'comet-dev-regression']);

        $resp = $this->actingAs($user)->get(route('assets.tickets', $asset));

        $resp->assertOk();
        // The comet backup block (which dereferences $cometJobData) rendered.
        $resp->assertSee('Backup Storage (Comet)');
    }
}
