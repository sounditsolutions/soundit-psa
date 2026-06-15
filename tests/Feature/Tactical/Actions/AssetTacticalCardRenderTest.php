<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 8 / amendment M4: the Tactical card must ALWAYS render when the asset is
 * Tactical-linked — it must NOT vanish when the (daily-stale) snapshot says
 * offline. The bus's offline result is the source of truth, so the UI shows the
 * controls with a clear offline affordance rather than disappearing.
 */
class AssetTacticalCardRenderTest extends TestCase
{
    use RefreshDatabase;

    private function linkedAsset(string $status): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => $status,
            'synced_at' => now()->subHours(6),
        ]);

        return $asset->refresh();
    }

    public function test_card_renders_with_run_and_reboot_when_online(): void
    {
        $user = User::factory()->create();
        $asset = $this->linkedAsset('online');

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertSee('Run Script')
            ->assertSee('tacticalRunBtn', false)
            ->assertSee('tacticalRebootBtn', false);
    }

    public function test_card_still_renders_an_offline_state_when_snapshot_offline(): void
    {
        $user = User::factory()->create();
        $asset = $this->linkedAsset('offline');

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        // M4: the card does NOT vanish — it renders with a clear offline affordance.
        $resp->assertOk()
            ->assertSee('Run Script')
            ->assertSeeText('offline', false);
    }

    public function test_no_tactical_card_when_not_linked(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertDontSee('tacticalRebootBtn', false);
    }

    public function test_server_class_gets_a_louder_reboot_caution(): void
    {
        // M5: monitoring_type === 'server' -> a distinct, louder confirm warning.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'SRV-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'SRV-1',
            'status' => 'online',
            'monitoring_type' => 'server',
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset->refresh()));

        $resp->assertOk()->assertSee('This is a SERVER', false);
    }
}
