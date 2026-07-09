<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the RMM staleness guard: the asset detail header and
 * the inventory "online" filter must not present a host with a weeks-old
 * heartbeat as simply Online (bug psa-wedk).
 */
class AssetStatusBadgeRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_header_shows_stale_not_online_when_heartbeat_is_weeks_old(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create([
            'hostname' => 'VAN-APP01',
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('Stale');
        // The misleading "Online" badge (its title is unique to that branch) is gone.
        $resp->assertDontSee('Online per RMM');
    }

    public function test_detail_header_still_shows_online_when_heartbeat_is_fresh(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create([
            'hostname' => 'VAN-APP02',
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('Online per RMM');
    }

    public function test_online_status_filter_excludes_stale_assets(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        Asset::factory()->for($client)->create([
            'hostname' => 'FRESH-ONLINE-01',
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);
        Asset::factory()->for($client)->create([
            'hostname' => 'STALE-ONLINE-01',
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.index', ['status' => 'online']));

        $resp->assertOk();
        $resp->assertSee('FRESH-ONLINE-01');
        $resp->assertDontSee('STALE-ONLINE-01');
    }
}
