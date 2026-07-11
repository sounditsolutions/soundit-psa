<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-kqpq — the asset detail page forced a horizontal scroll on phones: the
 * action toolbar (status/contract badges + Edit/offboard) sat on one no-wrap
 * row and the RMM / key-value panels pushed past a 390px viewport (QA measured
 * a 472px full-page width). The header now stacks below md and its controls
 * wrap, the Tactical RMM card header wraps, and the page carries the
 * `.asset-detail-page` body hook that scopes the mobile table-fit CSS.
 *
 * These are render-level assertions (mirroring Tickets\MobileResponsiveTest):
 * they lock the responsive markup in. The pixel width itself is verified out of
 * band with a headless browser at 390px.
 */
class AssetDetailMobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_detail_header_stacks_and_actions_wrap_on_mobile(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();

        $resp = $this->actingAs($user)->get(route('assets.show', $asset))->assertOk();

        // Body hook that scopes the mobile viewport-fit CSS to this page.
        $resp->assertSee('asset-detail-page', false);
        // Title stacks above the toolbar below md, inline (row) from md up.
        $resp->assertSee('flex-column flex-md-row align-items-start align-items-md-center', false);
        // The action toolbar wraps instead of overflowing the viewport.
        $resp->assertSee('d-flex align-items-center flex-wrap gap-2', false);
    }

    public function test_tactical_rmm_card_header_wraps_on_mobile(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'test-agent-uuid-kqpq',
            'hostname' => $asset->hostname,
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset))->assertOk();

        // The RMM panel renders and its header badge cluster wraps below md so
        // the maintenance / status / freshness / refresh controls stay inside
        // the viewport.
        $resp->assertSee('Tactical RMM', false);
        $resp->assertSee('d-flex align-items-center flex-wrap gap-2 justify-content-end', false);
    }
}
