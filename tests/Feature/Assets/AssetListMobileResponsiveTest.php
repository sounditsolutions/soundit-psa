<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-2lw3: the asset inventory keeps the full table at md+ and falls back to
 * stacked cards below md, so a technician can identify each device's client and
 * online/offline state at a glance without a horizontal scroll. Previously the
 * desktop table was clipped on mobile and the Status column rode off the right
 * edge. The fix lives in the shared assets/_list partial, so it covers both the
 * standalone /assets inventory and the client Devices tab. Mirrors psa-6zs7
 * (tickets) and psa-sasp (invoices).
 *
 * The desktop table and the mobile cards are both server-rendered into the HTML;
 * only CSS (d-none d-md-block / d-md-none) decides which is shown. So assertSee
 * finds the key data in the response regardless of viewport.
 */
class AssetListMobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_index_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MOBILE-ON01',
            'rmm_online' => true,
        ]);
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MOBILE-OFF01',
            'rmm_online' => false,
        ]);

        $resp = $this->actingAs($user)->get(route('assets.index'))->assertOk();

        // Desktop: the full table is hidden below md.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        // Mobile: the stacked-card fallback container + card render. psa-2lw3.
        $resp->assertSee('d-md-none asset-cards', false);
        $resp->assertSee('class="asset-card"', false);
        // Each device rides in the mobile card...
        $resp->assertSee('MOBILE-ON01');
        $resp->assertSee('MOBILE-OFF01');
        // ...carrying the previously clipped-off online/offline signal.
        $resp->assertSee('Online per RMM', false);
        $resp->assertSee('Offline per RMM', false);
    }

    public function test_client_devices_tab_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MOBILE-CL01',
            'rmm_online' => false,
        ]);

        $resp = $this->actingAs($user)
            ->get(route('clients.assets', $client))
            ->assertOk();

        // Desktop table hidden below md; mobile card fallback present. psa-2lw3.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        $resp->assertSee('d-md-none asset-cards', false);
        $resp->assertSee('class="asset-card"', false);
        // The device and its clipped-off status now ride in the mobile card.
        $resp->assertSee('MOBILE-CL01');
        $resp->assertSee('Offline per RMM', false);
    }
}
