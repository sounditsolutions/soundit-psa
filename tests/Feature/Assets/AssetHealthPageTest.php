<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetHealthPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_show_computes_and_renders_health_on_view(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create([
            'hostname' => 'HEALTHCHK-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('Health Score');
        $resp->assertSee('70');          // 100 - 30 offline
        $resp->assertSee('Connectivity');

        // Score cached on the asset so it isn't recomputed every render.
        $asset->refresh();
        $this->assertSame(70, $asset->health_score);
        $this->assertNotNull($asset->health_computed_at);
    }

    public function test_unhealthy_filter_shows_only_poor_scored_assets(): void
    {
        $user = User::factory()->create();

        $poor = Asset::factory()->scored(30)->create(['hostname' => 'POOR-DEVICE']);
        $good = Asset::factory()->scored(95)->create(['hostname' => 'GOOD-DEVICE']);
        $unscored = Asset::factory()->create(['hostname' => 'UNSCORED-DEVICE']);

        $resp = $this->actingAs($user)->get(route('assets.index', ['health' => 'unhealthy']));

        $resp->assertOk();
        $resp->assertSee('POOR-DEVICE');
        $resp->assertDontSee('GOOD-DEVICE');
        $resp->assertDontSee('UNSCORED-DEVICE');
    }

    public function test_asset_list_has_health_column_and_filter_pill(): void
    {
        $user = User::factory()->create();
        Asset::factory()->scored(42)->create(['hostname' => 'LISTED-DEVICE']);

        $resp = $this->actingAs($user)->get(route('assets.index'));

        $resp->assertOk();
        $resp->assertSee('Health');       // column header
        $resp->assertSee('Unhealthy');    // filter pill
        $resp->assertSee('42');           // score badge
    }
}
