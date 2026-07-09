<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for psa-b0fh: the asset inventory list hid Type, OS and the
 * live/stale status columns below the `md` breakpoint, leaving a technician on a
 * phone unable to tell a laptop from a server or an online box from an offline
 * one. The list now renders stacked mobile cards (below md) that keep that
 * identifying signal visible, while the full table is reserved for md+.
 */
class AssetInventoryResponsiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_inventory_renders_mobile_cards_with_type_and_status(): void
    {
        $user = User::factory()->create();

        $online = Asset::factory()->create([
            'hostname' => 'FRONTDESK-PC',
            'asset_type' => 'Workstation',
            'os' => 'Windows 11 Pro',
            'rmm_online' => true,
        ]);

        $offline = Asset::factory()->create([
            'hostname' => 'MAIL-SRV-01',
            'asset_type' => 'Server',
            'os' => 'Windows Server 2022',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($user)->get(route('assets.index'));
        $response->assertOk();

        $content = $response->getContent();

        // The desktop table must be hidden below md so it no longer defines the
        // mobile experience.
        $this->assertStringContainsString('card shadow-sm card-static d-none d-md-block', $content);

        // Isolate the mobile-only card section (everything after the table card)
        // so we assert on what a phone actually sees, not the desktop table.
        $pos = strpos($content, 'd-md-none asset-cards');
        $this->assertNotFalse($pos, 'Mobile asset-cards container should render below md.');
        $mobileSection = substr($content, $pos);

        // Both device types are legible in the mobile cards…
        $this->assertStringContainsString('Workstation', $mobileSection);
        $this->assertStringContainsString('Server', $mobileSection);

        // …as is the live/stale status for each device.
        $this->assertStringContainsString('Online', $mobileSection);
        $this->assertStringContainsString('Offline', $mobileSection);

        // Device names remain the primary identifier on the card.
        $this->assertStringContainsString($online->hostname, $mobileSection);
        $this->assertStringContainsString($offline->hostname, $mobileSection);

        // Offline assets carry the supplementary tint cue (state is never colour
        // alone — the "Offline" badge above still conveys it in text).
        $this->assertStringContainsString('asset-card-offline', $mobileSection);
    }
}
