<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\EndpointInsight;
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

    private function linkedAsset(string $status, string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
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
            ->assertSee('tacticalRebootBtn', false)
            ->assertSee('tacticalRecoverBtn', false)
            ->assertSee('Recover agent')
            // Commit 2: cmd + shutdown controls + their confirm modals.
            ->assertSee('tacticalCmdBtn', false)
            ->assertSee('tacticalCmdModal', false)
            ->assertSee('tacticalShutdownBtn', false)
            ->assertSee('tacticalShutdownModal', false)
            // D2 (verbatim): the shutdown irreversibility consequence is shown.
            ->assertSee('cannot be powered back on remotely', false);
    }

    public function test_cmd_shell_defaults_to_powershell_or_cmd_for_windows(): void
    {
        // E5: a Windows device pre-selects a Windows shell (cmd) by default.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'WINBOX']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-WIN',
            'hostname' => 'WINBOX',
            'status' => 'online',
            'os' => 'Windows 11 Pro',
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset->refresh()));

        // The cmd <option value="cmd"> carries the `selected` attribute.
        $resp->assertOk()->assertSee('value="cmd" selected', false);
    }

    public function test_maintenance_toggle_is_always_visible_on_the_tactical_card(): void
    {
        // E3: the maintenance control is an always-visible switch near the device
        // status (not buried). It renders for a linked device regardless of the
        // snapshot status.
        $user = User::factory()->create();

        foreach (['online', 'offline'] as $i => $status) {
            $asset = $this->linkedAsset($status, "AGENT-MAINT-{$i}");
            $resp = $this->actingAs($user)->get(route('assets.show', $asset));

            $resp->assertOk()
                ->assertSee('tacticalMaintenanceToggle', false)
                ->assertSee('Maintenance mode');
        }
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

    // ── Eager health-line chips: stale/unavailable must never read as a confident
    //    green "all passing" / "up to date" (amendment H misread, fix #2). ──

    private function healthLineAsset(array $taOverrides): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-CHIP']);
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-CHIP',
            'hostname' => 'BOX-CHIP',
            'status' => 'online',
        ], $taOverrides));

        return $asset->refresh();
    }

    public function test_fresh_snapshot_with_zero_failing_shows_a_clean_checks_chip(): void
    {
        // A FRESH (non-stale) snapshot that actually read 0 failing checks may show
        // the positive "all passing" chip.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'synced_at' => now()->subMinutes(2), // fresh
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertSee('all passing');
    }

    public function test_stale_snapshot_does_not_render_checks_as_all_passing(): void
    {
        // A STALE clean snapshot must NOT render a confident green "all passing"
        // (the amendment-H misread). It degrades to an "as of last sync" qualifier.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5), // stale
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSee('all passing')
            ->assertSee('as of last sync');
    }

    public function test_unavailable_checks_does_not_render_as_all_passing(): void
    {
        // No snapshot checks count at all (Unavailable) — never "all passing".
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => null,
            'checks_total' => null,
            'synced_at' => now()->subMinutes(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertDontSee('all passing');
    }

    public function test_failing_checks_chip_still_shows_the_count_when_stale(): void
    {
        // Staleness gates only the POSITIVE claim; a known-failing count is still a
        // real, useful signal and must keep showing.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 3,
            'checks_total' => 5,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertSee('3 checks failing');
    }

    public function test_stale_snapshot_does_not_render_patches_as_up_to_date(): void
    {
        // A stale "no patches pending" snapshot must NOT render a confident green
        // "up to date"; it degrades to an "as of last sync" qualifier.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'has_patches_pending' => false,
            'checks_failing' => 0,
            'checks_total' => 5,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertDontSee('up to date');
    }

    public function test_pending_patches_chip_still_warns_when_stale(): void
    {
        // The negative "updates pending" signal still shows when stale (a pending
        // update doesn't un-pend itself).
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'has_patches_pending' => true,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertSee('updates pending');
    }

    // ── Page-top device-data tabs serve every RMM-linked asset (psa-ymw8) ───────
    //    The prominent Network/Storage/Software/Patches tabs render for Ninja/Level
    //    AND Tactical assets. A Tactical-only asset routes them to its Tactical data
    //    (source=tactical) and gets an extra Checks tab. A no-RMM asset still hides
    //    them. (Reverses #36, which buried Tactical data in a collapsed accordion.)

    public function test_tactical_only_asset_shows_the_device_data_tabs_routed_to_tactical(): void
    {
        $user = User::factory()->create();
        $asset = $this->linkedAsset('online'); // Tactical-linked, no ninja_id/level_id

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            // The same prominent page-top tabs render, plus a Tactical-only Checks tab.
            ->assertSee('data-ajax-section="network"', false)
            ->assertSee('data-ajax-section="storage"', false)
            ->assertSee('data-ajax-section="software"', false)
            ->assertSee('data-ajax-section="patches"', false)
            ->assertSee('data-ajax-section="checks"', false)
            // The renderer that backs those tabs for a Tactical-only asset is defined.
            ->assertSee('window.renderTacticalSection', false);
    }

    public function test_no_rmm_asset_hides_the_legacy_device_data_tabs(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(); // no RMM at all

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertDontSee('data-ajax-section="network"', false);
    }

    public function test_ninja_asset_still_shows_the_legacy_device_data_tabs(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['ninja_id' => 'NINJA-1']);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        // A Ninja-linked asset is unchanged — the page-top tabs still hit Ninja.
        $resp->assertOk()->assertSee('data-ajax-section="network"', false);
    }

    public function test_tactical_only_asset_drops_the_under_card_accordion_panels(): void
    {
        // psa-ymw8: the collapsed under-card accordion (data-tactical-panel) buried
        // the data after dev-test, so it was removed. Network/Storage/Software/
        // Patches/Checks now live in the prominent page-top tabs (asserted above);
        // the accordion DOM is gone.
        $user = User::factory()->create();
        $asset = $this->linkedAsset('online');

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSee('id="tacticalPanels"', false)
            ->assertDontSee('data-tactical-panel="network"', false)
            ->assertDontSee('data-tactical-panel-body="network"', false)
            ->assertDontSee('data-tactical-panel="storage"', false);
    }

    public function test_dual_linked_ninja_tactical_asset_keeps_the_legacy_tabs(): void
    {
        // A Ninja+Tactical dual-linked asset keeps the page-top Ninja tabs (they
        // still resolve to the Ninja branch) AND gets the Tactical card panels.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'DUAL-1', 'ninja_id' => 'NINJA-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-DUAL',
            'hostname' => 'DUAL-1',
            'status' => 'online',
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset->refresh()));

        $resp->assertOk()->assertSee('data-ajax-section="network"', false);
    }
}
