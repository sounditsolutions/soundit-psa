<?php

namespace Tests\Feature\Assets;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S0/S1: the Sources tab frame, rail and generic panel template
 * (design/mockups/assets/design-r3.md §§1-2), with S1's Sync now on Tactical.
 * Synthetic data only.
 */
class AssetSourcesTabTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // G-5: no vendor may be reached from these renders. No catch-all fake.
        Http::preventStrayRequests();
    }

    private function show(Asset $asset): TestResponse
    {
        return $this->actingAs(User::factory()->tech()->create())->get(route('assets.show', $asset))->assertOk();
    }

    /** The HTML of one source's panel, cut at the next panel. */
    private function panel(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-panel="'.$source.'"');
        $this->assertNotFalse($start, "panel {$source} is rendered");
        $next = strpos($html, 'data-source-panel="', $start + 20);
        $end = $next === false ? strpos($html, '</script>', $start) : $next;

        return substr($html, $start, $end - $start);
    }

    private function railEntry(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-rail="'.$source.'"');
        $this->assertNotFalse($start, "rail entry {$source} is rendered");

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    public function test_rail_lists_sources_in_fixed_order_and_tab_counts_linked_only(): void
    {
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        preg_match_all('/data-source-rail="([a-z0-9]+)"/', $html, $m);
        $this->assertSame(\App\Services\AssetSources\AssetSources::ORDER, $m[1]);
        // Ninja is configured but switched off by default: shown as OFF, not counted.
        $this->assertStringContainsString('data-state="off"', $this->railEntry($html, 'ninja'));
        $this->assertMatchesRegularExpression('/data-sources-count>\(10\)</', $html);
    }

    public function test_rail_leaves_out_an_unconfigured_unlinked_source_and_keeps_a_linked_one(): void
    {
        // Nothing configured; the asset carries only an AutoElevate link.
        $asset = Asset::factory()->create(['autoelevate_computer_id' => '00000000-0000-4000-8000-000000000999']);
        $html = $this->show($asset)->getContent();

        preg_match_all('/data-source-rail="([a-z0-9]+)"/', $html, $m);
        $this->assertSame(['autoelevate'], $m[1]);
        $this->assertStringContainsString('data-state="not_configured"', $this->railEntry($html, 'autoelevate'));
        $this->assertMatchesRegularExpression('/data-sources-count>\(0\)</', $html);
        // A linked but unconfigured source keeps its stored details and never claims "not linked".
        $panel = $this->panel($html, 'autoelevate');
        $this->assertStringNotContainsString('not linked to AutoElevate', $panel);
        $this->assertStringContainsString('data-detail-column="autoelevate_computer_id"', $panel);
    }

    public function test_rendering_sources_with_every_source_linked_makes_zero_http_calls(): void
    {
        // M0.10. preventStrayRequests (setUp) turns any Http-facade call into an exception.
        $this->configureAllSources();
        Http::fake([]);
        $asset = $this->assetLinkedEverywhere(
            ['autoelevate_elevation_mode' => 'audit', 'needs_reboot' => true],
            ['checks_failing' => 2, 'needs_reboot' => true],
        );

        $html = $this->show($asset)->getContent();

        Http::assertNothingSent();
        $this->assertStringContainsString('data-attention-marker="2"', $this->railEntry($html, 'tactical'));
        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'autoelevate'));
    }

    public function test_m365_signatures_up_to_date_defender_status_is_healthy_and_has_no_defender_item(): void
    {
        // M-r1c.1: the marker shares AssetHealthService::defenderLooksBad, which treats
        // 'SignaturesUpToDate' as healthy (AssetHealthServiceTest pins the same value).
        $this->configureAllSources();
        Http::fake([]);
        $html = $this->show($this->assetLinkedEverywhere(['m365_defender_status' => 'SignaturesUpToDate']))->getContent();
        $this->assertStringNotContainsString('data-attention-item="defender"', $this->panel($html, 'm365'));
        $this->assertStringNotContainsString('data-attention-marker', $this->railEntry($html, 'm365'));
        Http::assertNothingSent();
    }

    public function test_m365_clean_or_null_defender_status_has_no_defender_item(): void
    {
        $this->configureAllSources();
        Http::fake([]);
        foreach (['Running', 'clean', ' CLEAN ', null] as $status) {
            $html = $this->show($this->assetLinkedEverywhere(['m365_defender_status' => $status]))->getContent();
            $this->assertStringNotContainsString('data-attention-item="defender"', $this->panel($html, 'm365'));
            $this->assertStringNotContainsString('data-attention-marker', $this->railEntry($html, 'm365'));
        }
        Http::assertNothingSent();
    }

    public function test_m365_disabled_defender_shows_raw_value_alongside_compliance(): void
    {
        $this->configureAllSources();
        Http::fake([]);
        $html = $this->show($this->assetLinkedEverywhere([
            'm365_defender_status' => 'Disabled', 'm365_is_compliant' => false,
        ]))->getContent();
        $panel = $this->panel($html, 'm365');
        $this->assertStringContainsString('data-attention-item="defender"', $panel);
        $this->assertStringContainsString('Defender: Disabled', $panel);
        $this->assertStringContainsString('Defender AV status reported by Intune', $panel);
        $this->assertStringContainsString('data-attention-item="compliance"', $panel);
        $this->assertStringContainsString('data-attention-marker="2"', $this->railEntry($html, 'm365'));
        Http::assertNothingSent();
    }

    public function test_autoelevate_not_mapped_link_state_reaches_panel_and_rail(): void
    {
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-ae-key');
        Http::fake([]);
        $client = Client::factory()->create(['autoelevate_company_id' => null]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'autoelevate_computer_id' => null]);
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'autoelevate');
        $this->assertStringContainsString('data-attention-item="link"', $panel);
        $this->assertStringContainsString('Link state: not_mapped', $panel);
        $this->assertStringContainsString('data-state="not_linked"', $panel);
        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'autoelevate'));
        Http::assertNothingSent();
    }

    public function test_autoelevate_read_failed_link_state_reaches_panel_and_rail(): void
    {
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-ae-key');
        Http::fake([]);
        $company = '00000000-0000-4000-8000-000000000123';
        $client = Client::factory()->create(['autoelevate_company_id' => $company]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'autoelevate_computer_id' => null]);
        AutoElevateAssetSyncService::recordClientOutcome($client->id, $company, false, 'http_503');
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'autoelevate');
        $this->assertStringContainsString('data-attention-item="link"', $panel);
        $this->assertStringContainsString('Link state: read_failed', $panel);
        $this->assertStringContainsString('http_503', $panel);
        $this->assertStringContainsString('data-state="not_linked"', $panel);
        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'autoelevate'));
        Http::assertNothingSent();
    }

    public function test_autoelevate_link_row_marks_the_rail_but_does_not_pick_the_default(): void
    {
        // M-r1c.2 (Jeeves A1): an unmapped client's AutoElevate link row still marks the
        // rail, but the default opens on the first source with a non-link item (Tactical).
        $this->configureAllSources();
        Http::fake([]);
        $asset = $this->assetLinkedEverywhere(['autoelevate_computer_id' => null], ['checks_failing' => 2]);
        $this->assertNull($asset->client->autoelevate_company_id, 'fixture: the client has no AutoElevate mapping');
        $html = $this->show($asset)->getContent();

        $this->assertStringContainsString('data-default-source="tactical"', $html);
        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'autoelevate'));
        $this->assertStringContainsString('Link state: not_mapped', $this->panel($html, 'autoelevate'));
        $this->assertStringContainsString('data-attention-item="checks"', $this->panel($html, 'tactical'));
        Http::assertNothingSent();
    }

    public function test_linked_but_not_configured_source_says_its_details_are_the_last_data_stored(): void
    {
        // M-r1c.3: Jeeves's exact sentence, for linked + not configured only.
        $asset = Asset::factory()->create(['autoelevate_computer_id' => '00000000-0000-4000-8000-000000000998']);
        $panel = $this->panel($this->show($asset)->getContent(), 'autoelevate');

        $this->assertStringContainsString('data-state="not_configured"', $panel);
        $this->assertStringContainsString("This source isn't configured, so these details are the last data stored.", $panel);
        $this->assertStringNotContainsString('Nothing in the stored data for this source needs a look.', $panel);

        // A linked, configured source with no attention keeps today's sentence.
        $this->configureAllSources();
        $tactical = $this->panel($this->show($this->assetLinkedEverywhere())->getContent(), 'tactical');
        $this->assertStringContainsString('Nothing in the stored data for this source needs a look.', $tactical);
        $this->assertStringNotContainsString("This source isn't configured", $tactical);
    }

    public function test_default_arm_footer_shows_the_synced_time_without_scheduled(): void
    {
        // M-r1c.4: the default footer arm claims nothing about how the sync ran.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        foreach (['tactical', 'litsrmm'] as $source) {
            $this->assertSame(1, preg_match('/data-footer-text>([^<]*)</', $this->panel($html, $source), $m), $source);
            $this->assertMatchesRegularExpression('/^Synced \d+ \w+ ago · /', $m[1], $source);
            $this->assertStringNotContainsString('scheduled', $m[1], $source);
        }
    }

    public function test_offline_agent_with_no_other_issue_renders_marker_1(): void
    {
        // M1T.9 (Jeeves 22:1xZ fix 1): agent offline/overdue counts toward the Tactical marker.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere([], ['status' => 'offline']))->getContent();

        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'tactical'));
        $this->assertStringContainsString('data-attention-item="agent"', $this->panel($html, 'tactical'));
    }

    public function test_tactical_marker_counts_its_own_open_alerts_only(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        foreach ([AlertSource::Tactical, AlertSource::Ninja] as $src) {
            Alert::create([
                'asset_id' => $asset->id, 'source' => $src, 'severity' => AlertSeverity::Error,
                'status' => AlertStatus::Active, 'title' => 'Disk space C: below 10%', 'fired_at' => now()->subMinutes(40),
                'source_alert_id' => 'synthetic-'.$src->value,
            ]);
        }
        $html = $this->show($asset)->getContent();

        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html, 'tactical'));
    }

    public function test_default_selection_is_first_source_with_attention_else_most_recently_synced(): void
    {
        $this->configureAllSources();
        $clean = $this->assetLinkedEverywhere();
        // No attention anywhere: Tactical has the newest synced_at (1 min ago).
        $this->assertStringContainsString('data-default-source="tactical"', $this->show($clean)->getContent());

        $html = $this->show($this->assetLinkedEverywhere(['autoelevate_elevation_mode' => 'audit', 'autoelevate_synced_at' => now()->subDays(3)]))->getContent();
        $this->assertStringContainsString('data-default-source="autoelevate"', $html);
        $this->assertStringContainsString("'#tab-sources/' + a.dataset.sourceRail", $html, 'the deep-link handler is rendered');
    }

    public function test_panel_order_is_header_health_attention_activity_details_footer(): void
    {
        // M0.8.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        foreach (['tactical', 'ninja', 'servosity'] as $source) {
            preg_match_all('/data-panel-section="([a-z]+)"/', $this->panel($html, $source), $m);
            $this->assertSame(['header', 'health', 'attention', 'activity', 'details', 'footer'], $m[1], $source);
        }
    }

    public function test_every_snapshot_chip_carries_its_age(): void
    {
        // M0.9.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        $count = preg_match_all('/data-chip-kind="snapshot">(.*?)data-chip-note>([^<]*)</s', $html, $m);
        $this->assertGreaterThanOrEqual(9, $count, 'one snapshot chip per synced source');
        foreach ($m[2] as $note) {
            $this->assertMatchesRegularExpression('/^as of \d+ \w+ ago/', trim($note));
        }
    }

    public function test_no_open_in_element_for_autoelevate_controld_zorus_m365(): void
    {
        // M0.11.
        $this->configureAllSources();
        Setting::setValue('tactical_web_url', 'https://tactical.example.test');
        $html = $this->show($this->assetLinkedEverywhere(['level_url' => 'https://level.example.test/device/1']))->getContent();

        foreach (['autoelevate', 'controld', 'zorus', 'm365', 'litsrmm', 'servosity', 'comet'] as $source) {
            $panel = $this->panel($html, $source);
            $this->assertStringNotContainsString('data-open-in', $panel, $source);
            $this->assertStringNotContainsString('N/A', $panel, $source);
        }
        // Positive control: the builders that exist do render.
        $this->assertStringContainsString('Open Tactical dashboard', $this->panel($html, 'tactical'));
        $this->assertStringContainsString('Open in Level', $this->panel($html, 'level'));
        $this->assertStringContainsString('Open in ScreenConnect', $this->panel($html, 'screenconnect'));
    }

    public function test_off_panel_labels_stale_values_not_being_updated(): void
    {
        // M0.7. Ninja is configured and switched off by default.
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere())->getContent(), 'ninja');

        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringContainsString('Switched off in Settings; not being updated', $panel);
        $this->assertMatchesRegularExpression('/as of \d+ \w+ ago; last known, not updated/', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        $this->assertStringNotContainsString('data-attention-marker', $this->railEntry($this->show($this->assetLinkedEverywhere(['needs_reboot' => true]))->getContent(), 'ninja'));
    }

    public function test_off_source_that_is_not_linked_shows_no_details_last_contact_or_last_known_values(): void
    {
        // r3 diff:6. Ninja is configured and switched off by default; this asset was never
        // linked to it. No device details, no Last contact chip (the shared columns are
        // another RMM's) and no "last known" claim; the panel says the asset is not linked.
        $this->configureAllSources();
        Http::fake([]);
        $panel = $this->panel($this->show($this->assetLinkedEverywhere(['ninja_id' => null, 'ninja_synced_at' => null]))->getContent(), 'ninja');

        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringContainsString('Switched off in Settings; not being updated', $panel);
        $this->assertStringContainsString('No device details: this asset is not linked to Ninja.', $panel);
        $this->assertStringNotContainsString('data-detail-column', $panel);
        $this->assertStringNotContainsString('Not reported by Ninja', $panel);
        $this->assertStringNotContainsString('Last contact', $panel);
        $this->assertStringNotContainsString('last known', $panel);
        Http::assertNothingSent();
    }

    public function test_states_not_linked_and_not_configured_render_explicitly(): void
    {
        Setting::setEncrypted('controld_api_key', 'synthetic-controld-key');
        $asset = Asset::factory()->create(['zorus_endpoint_id' => 'zorus-synthetic-9']);
        $html = $this->show($asset)->getContent();

        $this->assertStringContainsString('This asset is not linked to Control D', $this->panel($html, 'controld'));
        $this->assertStringContainsString('data-state-badge="not_linked"', $this->panel($html, 'controld'));
        $this->assertStringContainsString('Not configured in Settings', $this->panel($html, 'zorus'));
        $this->assertStringContainsString('data-state-badge="not_configured"', $this->panel($html, 'zorus'));
    }

    public function test_device_details_show_label_column_not_reported_and_unrecognised(): void
    {
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere(['autoelevate_elevation_mode' => 'turbo']))->getContent(), 'autoelevate');

        $this->assertStringContainsString('<details', $panel);
        $this->assertStringNotContainsString('<details class="border rounded p-2 mt-3" data-panel-section="details" open', $panel);
        $this->assertStringContainsString('<code class="text-muted small">autoelevate_elevation_mode</code>', $panel);
        $this->assertStringContainsString('Unrecognised: turbo', $panel);
        $this->assertStringContainsString('Not reported by AutoElevate', $panel);
    }

    public function test_shared_rmm_columns_carry_the_inferred_wording(): void
    {
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere(['needs_reboot' => true]))->getContent(), 'litsrmm');

        $this->assertStringContainsString('data-attention-item="reboot"', $panel);
        $this->assertStringContainsString('most likely written by the newest sync', $panel);
    }

    public function test_footer_history_lists_the_last_five_audit_rows(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $user = User::factory()->create(['name' => 'Alex Example']);
        foreach (range(1, 7) as $i) {
            AssetSourceSync::create([
                'asset_id' => $asset->id, 'source' => 'tactical', 'user_id' => $user->id, 'outcome' => 'refused',
                'reason' => $i === 7 ? 'vendor_cooling_down' : 'recently_synced', 'retry_after_s' => 10,
                'started_at' => now()->subMinutes(10 - $i), 'duration_ms' => 1,
            ]);
        }
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'tactical');

        $this->assertSame(5, substr_count($panel, 'data-history-row'));
        $this->assertStringContainsString('Sync now by Alex Example', $panel);
        $this->assertStringContainsString('RATE LIMITED', $panel);
        $this->assertStringContainsString('This vendor asked us to slow down; no request was sent.', $panel);
    }

    public function test_sync_now_button_appears_on_the_tactical_panel_only(): void
    {
        // Tactical is the only source whose Sync now read is wired (S1).
        $this->configureAllSources();
        Setting::setValue('ninja_enabled', '1');
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        $tactical = $this->panel($html, 'tactical');
        $this->assertStringContainsString('data-sync-now="tactical"', $tactical);
        $this->assertStringContainsString(e("Reads this computer's record from Tactical. Changes nothing at Tactical."), $tactical);
        foreach (\App\Services\AssetSources\AssetSources::ORDER as $source) {
            if ($source !== 'tactical') {
                $this->assertStringNotContainsString('data-sync-now', $this->panel($html, $source), $source);
            }
        }
        $this->assertSame(1, substr_count($html, 'data-sync-now='));
    }

    public function test_comet_and_servosity_do_not_claim_never_synced(): void
    {
        // Their sync stamp is not attributable to the source, so the panel must not say "Never".
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();

        foreach (['comet', 'servosity'] as $source) {
            $panel = $this->panel($html, $source);
            $this->assertStringNotContainsString('fw-semibold">Never<', $panel, $source);
            $this->assertStringNotContainsString('no sync recorded', $panel, $source);
            $this->assertStringNotContainsString('No sync recorded', $panel, $source);
            $this->assertStringContainsString('Sync time not tracked for this source', $panel, $source);
        }
    }
}
