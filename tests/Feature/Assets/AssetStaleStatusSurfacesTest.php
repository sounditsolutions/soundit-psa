<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The RMM-staleness guard (psa-wedk) must reach EVERY staff surface that renders
 * asset status, not just the six views patched in the first pass. A frozen
 * rmm_online=true with a weeks-old last_seen_at must never read as a live
 * "Online" on: the shared <x-asset-badge> dot, the /assets quick-look JSON, the
 * asset-detail Status row, or the inventory "online" status filter.
 *
 * Review-gate follow-ups on PR #301: arch psa-9edro + security psa-aeu26 + ux psa-q0zim.
 */
class AssetStaleStatusSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private function staleAsset(array $attrs = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ], $attrs));
    }

    /** ARCH (psa-9edro): the shared asset-badge dot must be amber for Stale, not the unknown-grey default. */
    public function test_shared_asset_badge_dot_is_amber_for_stale(): void
    {
        $asset = $this->staleAsset(['hostname' => 'VAN-APP01']);

        $html = Blade::render(
            '<x-asset-badge :asset="$asset" :link="false" :popover="false" />',
            ['asset' => $asset]
        );

        // Bootstrap warning amber, matching the badge surfaces — never the grey default.
        $this->assertStringContainsString('#ffc107', $html);
        $this->assertStringNotContainsString('#6c757d', $html);
    }

    /** ARCH (psa-9edro): the quick-look JSON status colour must be amber for Stale. */
    public function test_quick_look_status_color_is_amber_for_stale(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        // No ninja_id/level_id → fetchLiveRmmData is a no-op, keeping the frozen state.
        $asset = $this->staleAsset(['ninja_id' => null, 'level_id' => null]);

        $resp = $this->actingAs($user)->getJson("/assets/{$asset->id}/quick-look");

        $resp->assertOk();
        $resp->assertJson([
            'status' => 'Stale',
            'status_color' => '#ffc107',
        ]);
    }

    /** UX (psa-q0zim): the detail-row Stale treatment must be an AA-compliant badge, not low-contrast .text-warning text on white. */
    public function test_asset_detail_row_renders_stale_as_accessible_badge(): void
    {
        $user = User::factory()->create();
        $asset = $this->staleAsset(['hostname' => 'VAN-APP01']);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('Stale');
        // The old low-contrast inline treatment (warning-yellow text on white) must be gone.
        $resp->assertDontSee(
            '<span class="text-warning"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i>Stale</span>',
            false
        );
    }

    /** ARCH/SECURITY: the inventory "online" filter must not return stale assets. */
    public function test_online_status_filter_excludes_stale_assets(): void
    {
        $user = User::factory()->create();
        Asset::factory()->create([
            'hostname' => 'FRESH-ONLINE',
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);
        $this->staleAsset(['hostname' => 'STALE-FROZEN']);

        $resp = $this->actingAs($user)->get(route('assets.index', ['status' => 'online']));

        $resp->assertOk();
        $resp->assertSee('FRESH-ONLINE');
        $resp->assertDontSee('STALE-FROZEN');
    }
}
