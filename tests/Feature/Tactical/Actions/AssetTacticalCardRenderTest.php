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

    public function test_a_snapshot_passing_count_never_renders_the_clean_chip(): void
    {
        // psa-0pb9m R2: the snapshot checks_passing column's only historical
        // producer was Tactical's summary aggregate, which counts a
        // never-reporting check as passing — so a stored count is a vendor
        // claim, not evidence, and the eager (snapshot) health line must
        // never render "all N passing" from it. The clean chip is reserved
        // for the LIVE per-check read (the lazy checks panel).
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'checks_passing' => 5, // pre-R2 residue — not evidence
            'synced_at' => now()->subMinutes(2), // fresh — freshness must not rescue it
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 5 passing')
            ->assertSeeText('coverage unknown');
    }

    public function test_zero_failing_without_passing_evidence_renders_coverage_unknown_not_clean(): void
    {
        // psa-0pb9m revise: a pre-upgrade snapshot (no passing count) with 0
        // failing must NOT read clean — whether any check passes is unknown.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'checks_passing' => null,
            'synced_at' => now()->subMinutes(2), // fresh — freshness must not rescue it
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 5 passing')
            ->assertSeeText('coverage unknown');
    }

    public function test_a_partial_snapshot_passing_count_renders_unknown_not_a_counted_pass(): void
    {
        // psa-0pb9m R2: same rule for a partial residue — "4 of 5 passing" off
        // the snapshot column would relay the vendor's manufactured claim as
        // counted evidence. Snapshot coverage is unknown; the honest partial
        // line exists only on the LIVE per-check read.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'checks_passing' => 4, // pre-R2 residue — not evidence
            'synced_at' => now()->subMinutes(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 5 passing')
            ->assertDontSeeText('4 of 5 passing')
            ->assertSeeText('coverage unknown');
    }

    public function test_stale_snapshot_does_not_render_checks_as_all_passing(): void
    {
        // A STALE clean snapshot must NOT render the confident green clean chip
        // (the amendment-H misread). It degrades to an "as of last sync" qualifier.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 5,
            'checks_passing' => 5,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5), // stale
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 5 passing')
            ->assertSee('as of last sync');
    }

    public function test_unavailable_checks_renders_health_not_verified_never_clean(): void
    {
        // No snapshot checks count at all (Unavailable) — a first-class
        // unknown state in words (revise: never a bare muted dash, never green).
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => null,
            'checks_total' => null,
            'checks_passing' => null,
            'synced_at' => now()->subMinutes(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 5 passing')
            ->assertSeeText('checks unknown — health not verified');
    }

    public function test_zero_checks_renders_unmonitored_not_all_passing(): void
    {
        // psa-0pb9m: ZERO configured checks is the ABSENCE of monitoring — the
        // delete-the-broken-check trap. A fresh 0-of-0 snapshot must render the
        // explicit unmonitored chip, never the green clean chip.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 0,
            'checks_total' => 0,
            'checks_passing' => 0,
            'synced_at' => now()->subMinutes(2), // fresh — freshness must not rescue it
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertDontSeeText('all 0 passing')
            ->assertSee('no checks — unmonitored');
    }

    public function test_failing_checks_chip_still_shows_the_count_when_stale(): void
    {
        // Staleness gates only the POSITIVE claim; a known-failing count is still a
        // real, useful signal and must keep showing (even without a passing count).
        // psa-0pb9m R3 (P1): but never ALONE — without passing evidence the
        // independent coverage fact leads, so the count cannot read as a
        // normal monitored-but-unhealthy rollup.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 3,
            'checks_total' => 5,
            'checks_passing' => null,
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertSeeText('3 of 5 checks failing')
            ->assertSeeText('monitoring coverage unknown');
    }

    public function test_partial_failing_snapshot_states_coverage_unknown_with_the_count(): void
    {
        // psa-0pb9m R3 (P1, the blocking card seam): a summary-only snapshot
        // with SOME but not all checks failing (total=8, failing=1,
        // passing=null) classifies coverage=unknown, and the card previously
        // rendered only "1 of 8 checks failing" — a normal health rollup that
        // never told the operator no passing check has been proven. Both
        // independent facts must show: the coverage limitation FIRST, the
        // failing count preserved, and a pointer to the live per-check read.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 1,
            'checks_total' => 8,
            'checks_passing' => null,
            'synced_at' => now()->subMinutes(2), // fresh — freshness must not rescue the coverage claim
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertSeeText('monitoring coverage unknown')
            ->assertSeeText('1 of 8 checks failing')
            // Q-8 A: the Checks tab retired; the card points at the Sources tab's Tactical panel.
            ->assertSeeText('open Sources for the live per-check result');
    }

    public function test_partial_failing_with_passing_residue_still_states_coverage_unknown(): void
    {
        // psa-0pb9m R3 (P1): same seam with pre-R2 aggregate residue in the
        // passing column (total=8, failing=1, passing=7). The stored count is
        // a vendor claim, not evidence — the insight ignores the snapshot
        // column, so the card must still lead with coverage unknown, never
        // render a counted pass.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 1,
            'checks_total' => 8,
            'checks_passing' => 7, // residue — not evidence
            'synced_at' => now()->subMinutes(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            ->assertSeeText('monitoring coverage unknown')
            ->assertSeeText('1 of 8 checks failing')
            ->assertDontSeeText('7 of 8 passing');
    }

    public function test_all_failing_renders_monitoring_unverified_before_the_count(): void
    {
        // The production trap (revise): "1 of 1 failing" must not read as a
        // monitored-but-unhealthy count — the decisive limitation comes first,
        // in words.
        $user = User::factory()->create();
        $asset = $this->healthLineAsset([
            'checks_failing' => 1,
            'checks_total' => 1,
            'checks_passing' => 0,
            'synced_at' => now()->subMinutes(2),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()->assertSeeText('all checks failing — monitoring unverified (1 of 1)');
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
    //    (source=tactical). Its Checks tab retired into the Sources tab's Tactical
    //    panel (Asset Sources S1, Q-8 A). A no-RMM asset still hides them.
    //    (Reverses #36, which buried Tactical data in a collapsed accordion.)

    public function test_tactical_only_asset_shows_the_device_data_tabs_routed_to_tactical(): void
    {
        $user = User::factory()->create();
        $asset = $this->linkedAsset('online'); // Tactical-linked, no ninja_id/level_id
        // Tactical configured, so its Sources panel (the retired tab's new home) is live.
        \App\Models\Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        \App\Models\Setting::setEncrypted('tactical_api_key', 'synthetic-tactical-key');

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk()
            // The same prominent page-top tabs render. The Checks tab retired (Q-8 A,
            // M1T.7): its failing-check list now leads the Sources tab's Tactical panel.
            ->assertSee('data-ajax-section="network"', false)
            ->assertSee('data-ajax-section="storage"', false)
            ->assertSee('data-ajax-section="software"', false)
            ->assertSee('data-ajax-section="patches"', false)
            ->assertDontSee('data-ajax-section="checks"', false)
            ->assertDontSee('id="tab-checks"', false)
            ->assertSee('data-checks-url="'.route('assets.deviceData', ['asset' => $asset, 'section' => 'checks', 'source' => 'tactical']).'"', false)
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
