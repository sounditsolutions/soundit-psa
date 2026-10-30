<?php

namespace Tests\Feature\Assets;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S1: the Tactical panel (design/mockups/assets/design-r3.md §§1-5,
 * §8 S1), the Checks tab retirement (Q-8 A) and the open_in scheme allow-list
 * (S0 r3 prerequisite (a)). Synthetic data only.
 */
class AssetSourcesTacticalPanelTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $tacticalHistory;

    protected function setUp(): void
    {
        parent::setUp();
        // G-5: no Http-facade vendor call; Tactical (Guzzle) is bound to a scripted queue.
        Http::preventStrayRequests();
        Http::fake([]);
        $this->bindTactical([]);
    }

    private function bindTactical(array $queue): void
    {
        $this->tacticalHistory = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->tacticalHistory));
        $http = new GuzzleClient(['base_uri' => 'https://tactical-api.example.test/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function show(Asset $asset): TestResponse
    {
        return $this->actingAs(User::factory()->tech()->create())->get(route('assets.show', $asset))->assertOk();
    }

    private function panel(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-panel="'.$source.'"');
        $this->assertNotFalse($start, "panel {$source} is rendered");
        $next = strpos($html, 'data-source-panel="', $start + 20);
        $end = $next === false ? strpos($html, '</script>', $start) : $next;

        return substr($html, $start, $end - $start);
    }

    /** The health-strip chip value for a label inside one panel. */
    private function chip(string $panel, string $label): string
    {
        $this->assertSame(1, preg_match('/>'.preg_quote($label, '/').'<\/div>\s*<div class="fw-semibold">([^<]*)</', $panel, $m), "chip {$label}");

        return trim(html_entity_decode($m[1]));
    }

    private function chipNote(string $panel, string $label): string
    {
        $this->assertSame(1, preg_match('/>'.preg_quote($label, '/').'<\/div>\s*<div class="fw-semibold">[^<]*<\/div>\s*<div class="small text-muted" data-chip-note>([^<]*)</', $panel, $m), "chip note {$label}");

        return trim(html_entity_decode($m[1]));
    }

    private function alert(Asset $asset, AlertSource $source, string $title, int $minutesAgo = 40): Alert
    {
        return Alert::create([
            'asset_id' => $asset->id, 'source' => $source, 'severity' => AlertSeverity::Error,
            'status' => AlertStatus::Active, 'title' => $title, 'fired_at' => now()->subMinutes($minutesAgo),
            'source_alert_id' => 'synthetic-'.uniqid(),
        ]);
    }

    // ── health strip: psa-0pb9m snapshot rules (M1T.1, M1T.2) ─────────────────

    public function test_a_clean_snapshot_checks_chip_never_reads_all_passing(): void
    {
        // M1T.1: a snapshot carries no passing evidence, so failing==0 is never "all passing",
        // fresh or stale.
        $this->configureAllSources();
        foreach ([now()->subMinute(), now()->subDays(3)] as $syncedAt) {
            $panel = $this->panel($this->show($this->assetLinkedEverywhere([], [
                'checks_failing' => 0, 'checks_passing' => 6, 'checks_total' => 6, 'synced_at' => $syncedAt,
            ]))->getContent(), 'tactical');

            $this->assertSame('6 checks — coverage unknown', $this->chip($panel, 'Checks'));
            $this->assertStringNotContainsString('all passing', $panel);
            $this->assertStringNotContainsString('6 passing', $panel);
        }
    }

    public function test_checks_total_zero_renders_unmonitored(): void
    {
        // M1T.2.
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere([], [
            'checks_failing' => 0, 'checks_passing' => 0, 'checks_total' => 0,
        ]))->getContent(), 'tactical');

        $this->assertSame('no checks — unmonitored', $this->chip($panel, 'Checks'));
    }

    public function test_unknown_checks_is_never_green_and_failing_counts_state_coverage_first(): void
    {
        $this->configureAllSources();
        $unknown = $this->panel($this->show($this->assetLinkedEverywhere([], ['checks_failing' => null, 'checks_total' => null]))->getContent(), 'tactical');
        $this->assertSame('checks unknown — health not verified', $this->chip($unknown, 'Checks'));

        $partial = $this->panel($this->show($this->assetLinkedEverywhere([], ['checks_failing' => 1, 'checks_total' => 8]))->getContent(), 'tactical');
        $this->assertSame('monitoring coverage unknown — 1 of 8 checks failing', $this->chip($partial, 'Checks'));

        $all = $this->panel($this->show($this->assetLinkedEverywhere([], ['checks_failing' => 1, 'checks_total' => 1]))->getContent(), 'tactical');
        $this->assertSame('all checks failing — monitoring unverified (1 of 1)', $this->chip($all, 'Checks'));
    }

    public function test_tactical_health_strip_chips_carry_their_as_of_age(): void
    {
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere([], [
            'last_user' => 'EXAMPLE\\alex', 'has_patches_pending' => true, 'synced_at' => now()->subMinutes(2),
        ]))->getContent(), 'tactical');

        $this->assertSame('Online', $this->chip($panel, 'Agent status'));
        $this->assertSame('EXAMPLE\\alex', $this->chip($panel, 'Last user'));
        $this->assertSame('Updates pending', $this->chip($panel, 'Updates'));
        $this->assertSame('0', $this->chip($panel, 'Open Tactical alerts'));
        // S1-fix (Jeeves 2026-10-30): only Agent status is written by every read that
        // stamps synced_at, so only it carries that age. The rest have no truthful age.
        $this->assertSame('as of 2 minutes ago', $this->chipNote($panel, 'Agent status'));
        foreach (['Last user', 'Checks', 'Reboot', 'Updates'] as $label) {
            $this->assertStringNotContainsString('as of', $this->chipNote($panel, $label), $label);
        }
    }

    // ── failing-check list: on panel open, never on render (M1T.3, M1T.4, M1T.7) ──

    public function test_asset_page_render_makes_no_agents_checks_request(): void
    {
        // M1T.3: a queued response would be consumed (and recorded) by any render-time read.
        $this->configureAllSources();
        $this->bindTactical([new PsrResponse(200, [], json_encode([
            ['name' => 'Disk C', 'check_result' => ['status' => 'failing', 'retcode' => 2, 'stdout' => 'low space']],
        ]))]);
        $asset = $this->assetLinkedEverywhere([], ['checks_failing' => 1]);

        $panel = $this->panel($this->show($asset)->getContent(), 'tactical');

        $this->assertCount(0, $this->tacticalHistory, 'no Tactical request on page render');
        $this->assertStringContainsString('data-tactical-checks', $panel);
        $this->assertStringContainsString('Not read yet.', $panel);
        $this->assertStringNotContainsString('Disk C', $panel);
        Http::assertNothingSent();
    }

    public function test_a_failing_checks_stdout_passes_wiki_redactor_and_stdout_clip(): void
    {
        // M1T.4: the panel's own checks URL is the existing device-data read, which redacts
        // and clips stdout (TacticalPanelData::safeStdout).
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere([], ['checks_failing' => 1]);
        $panel = $this->panel($this->show($asset)->getContent(), 'tactical');
        $this->assertSame(1, preg_match('/data-checks-url="([^"]+)"/', $panel, $m));
        $url = html_entity_decode($m[1]);
        $this->assertSame(route('assets.deviceData', ['asset' => $asset, 'section' => 'checks', 'source' => 'tactical']), $url);

        // Synthetic credential assembled from fragments: no literal credential assignment sits in source.
        $credentialLine = 'db password = '.'Synth'.'Pass'.'w0rd!';
        $this->bindTactical([new PsrResponse(200, [], json_encode([
            ['name' => 'Backup Job', 'check_result' => ['status' => 'failing', 'retcode' => 2, 'stdout' => $credentialLine.' '.str_repeat('x', 500)]],
        ]))]);
        $r = $this->actingAs(User::factory()->tech()->create())->getJson($url)->assertOk();

        $stdout = $r->json('failing_checks.0.stdout');
        $this->assertSame('Backup Job', $r->json('failing_checks.0.name'));
        $this->assertSame(2, $r->json('failing_checks.0.retcode'));
        $this->assertStringContainsString('[REDACTED:credential]', $stdout);
        $this->assertStringNotContainsString('Synth'.'Pass'.'w0rd!', $stdout);
        $this->assertLessThanOrEqual(\App\Services\Tactical\TacticalPanelData::STDOUT_CLIP + 1, mb_strlen($stdout));
        $this->assertCount(1, $this->tacticalHistory);
        $this->assertStringEndsWith('/checks/', (string) $this->tacticalHistory[0]['request']->getUri());
    }

    public function test_dual_linked_tactical_ninja_asset_shows_the_failing_check_list_in_the_tactical_panel(): void
    {
        // M1T.7: the old Checks tab was not rendered on a dual-linked asset; the panel is
        // now the failing-check list's home there too, and the tab is gone everywhere.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere([], ['checks_failing' => 1]);
        $this->assertNotNull($asset->ninja_id);

        $html = $this->show($asset)->getContent();

        $this->assertStringContainsString('data-tactical-checks', $this->panel($html, 'tactical'));
        $this->assertStringNotContainsString('id="tab-checks"', $html);
        $this->assertStringNotContainsString('data-ajax-section="checks"', $html);
    }

    public function test_tactical_only_asset_has_no_checks_tab(): void
    {
        // M1T.7: the retired tab is not rendered for a Tactical-only asset either.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere(['ninja_id' => null, 'level_id' => null]))->getContent();

        $this->assertStringNotContainsString('id="tab-checks"', $html);
        $this->assertStringNotContainsString('href="#tab-checks"', $html);
        $this->assertStringContainsString('data-tactical-checks', $this->panel($html, 'tactical'));
    }

    // ── reboot, alerts (M1T.5, M1T.6) ────────────────────────────────────────

    public function test_tactical_panel_reboot_chip_follows_tactical_assets_only(): void
    {
        // M1T.5: the shared assets.needs_reboot (another RMM's write) never bleeds in.
        $this->configureAllSources();
        $bleed = $this->panel($this->show($this->assetLinkedEverywhere(['needs_reboot' => true], ['needs_reboot' => false]))->getContent(), 'tactical');
        $this->assertSame('Not needed', $this->chip($bleed, 'Reboot'));
        $this->assertStringNotContainsString('data-attention-item="reboot"', $bleed);

        $own = $this->panel($this->show($this->assetLinkedEverywhere(['needs_reboot' => false], ['needs_reboot' => true]))->getContent(), 'tactical');
        $this->assertSame('Reboot needed', $this->chip($own, 'Reboot'));
        $this->assertStringContainsString('data-attention-item="reboot"', $own);
    }

    public function test_tactical_panel_lists_only_source_tactical_open_alerts(): void
    {
        // M1T.6.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $this->alert($asset, AlertSource::Tactical, 'Synthetic Tactical disk alert');
        $this->alert($asset, AlertSource::Ninja, 'Synthetic Ninja disk alert');

        $panel = $this->panel($this->show($asset)->getContent(), 'tactical');

        $this->assertStringContainsString('Open alert: Synthetic Tactical disk alert', $panel);
        $this->assertStringNotContainsString('Synthetic Ninja disk alert', $panel);
        $this->assertSame('1', $this->chip($panel, 'Open Tactical alerts'));
    }

    public function test_tactical_panel_shows_the_top_3_alerts_and_links_all_n_alerts(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        foreach (range(1, 5) as $i) {
            $this->alert($asset, AlertSource::Tactical, 'Synthetic alert '.$i, 10 * $i);
        }

        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'tactical');

        $this->assertSame(3, substr_count($panel, 'data-attention-item="alert"'));
        $this->assertStringContainsString('All 5 alerts', $panel);
        $this->assertStringContainsString('data-show-tab="#tab-alerts" data-all-alerts', $panel);
        // The rail marker still counts every open Tactical alert.
        $this->assertMatchesRegularExpression('/data-source-rail="tactical"[^>]*>.*?data-attention-marker="5"/s', $html);
    }

    // ── links, marker, read-only (M1T.8, M1T.9, M3.3) ────────────────────────

    public function test_open_tactical_dashboard_link_hidden_when_tactical_web_url_blank(): void
    {
        // M1T.8 (psa-6h5r): no fallback to the API URL.
        $this->configureAllSources();
        Setting::setValue('tactical_web_url', '');
        $panel = $this->panel($this->show($this->assetLinkedEverywhere())->getContent(), 'tactical');

        $this->assertStringNotContainsString('data-open-in', $panel);
        $this->assertStringNotContainsString('Open Tactical dashboard', $panel);
        $this->assertStringNotContainsString('https://tactical-api.example.test', $panel);

        Setting::setValue('tactical_web_url', 'https://tactical.example.test/');
        $panel = $this->panel($this->show($this->assetLinkedEverywhere())->getContent(), 'tactical');
        $this->assertStringContainsString('href="https://tactical.example.test" target="_blank" rel="noopener noreferrer"', $panel);
        $this->assertStringContainsString('Open Tactical dashboard', $panel);
    }

    public function test_overdue_agent_with_no_other_issue_renders_marker_1(): void
    {
        // M1T.9 (overdue arm; the offline arm is AssetSourcesTabTest's marker-1 test).
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere([], ['status' => 'overdue']))->getContent();

        $this->assertMatchesRegularExpression('/data-source-rail="tactical"[^>]*>.*?data-attention-marker="1"/s', $html);
        $this->assertStringContainsString('Agent overdue', $this->panel($html, 'tactical'));
    }

    public function test_sources_panel_contains_no_reboot_shutdown_script_or_command_form(): void
    {
        // M3.3: write actions stay on Overview.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $html = $this->show($asset)->getContent();
        $start = strpos($html, 'id="sources-root"');
        $this->assertNotFalse($start);
        $sources = substr($html, $start, strpos($html, '</script>', $start) - $start);

        foreach (['assets.reboot-tactical', 'assets.shutdown-tactical', 'assets.run-tactical-script',
            'assets.run-tactical-command', 'assets.recover-tactical', 'assets.maintenance-tactical',
            'assets.tactical-meshcentral', 'assets.tactical-refresh'] as $name) {
            $this->assertStringNotContainsString(route($name, $asset), $sources, $name);
        }
        $this->assertStringNotContainsString('<form', $sources);
        $this->assertStringNotContainsString('tacticalRebootBtn', $sources);
        // The write controls are still on the page (Overview).
        $this->assertStringContainsString('tacticalRebootBtn', $html);
        $this->assertStringContainsString('tacticalMaintenanceToggle', $html);
    }

    // ── recent activity + device details moved from Overview (§4 A) ─────────

    public function test_recent_tactical_actions_render_in_the_panel_ticket_linked_and_overview_links_to_sources(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $ticket = Ticket::factory()->create();
        foreach (range(1, 12) as $i) {
            TacticalActionLog::create([
                'actor_label' => 'tech@example.test', 'action_key' => 'tactical.reboot', 'agent_id' => $asset->tacticalAsset->agent_id,
                'asset_id' => $asset->id, 'ticket_id' => $i === 12 ? $ticket->id : null, 'target_label' => 'DESK-EXAMPLE-01',
                'params' => [], 'result_status' => $i === 12 ? 'offline' : 'ok', 'correlation_id' => 'synthetic-'.$i,
            ]);
        }

        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'tactical');

        $this->assertSame(10, substr_count($panel, 'data-activity-row'));
        $this->assertStringContainsString(route('tickets.show', $ticket), $panel);
        $this->assertStringContainsString('no-op (agent unreachable)', $panel);
        $this->assertStringContainsString('10 most recent of 12', $panel);
        $this->assertStringContainsString('Recent actions (12) · View in Sources', $html);
        $this->assertSame(1, substr_count($html, 'id="tactical-recent-actions"'), 'the actions list lives in the panel only');
    }

    public function test_overview_details_table_fields_all_have_a_home_in_device_details(): void
    {
        // §4 A: the Overview Tactical details table moved; every field it showed is here.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere(['os' => 'Windows Server 2019'], [
            'agent_version' => '2.9.0', 'os' => 'Windows Server 2022', 'public_ip' => '203.0.113.7',
            'local_ips' => ['10.0.0.20', '10.0.0.21'], 'make_model' => 'Example Model 5', 'cpu' => 'Example CPU 8-core',
            'ram_gb' => 16, 'disk_summary' => 'C: 120 GB free of 500 GB', 'serial_number' => 'SYNTH-SN-0001',
        ]);

        $panel = $this->panel($this->show($asset)->getContent(), 'tactical');

        foreach (['agent_version' => '2.9.0', 'last_seen_at' => null, 'needs_reboot' => 'No', 'has_patches_pending' => 'No',
            'os' => 'Windows Server 2022', 'public_ip' => '203.0.113.7', 'local_ips' => '10.0.0.20, 10.0.0.21',
            'make_model' => 'Example Model 5', 'cpu' => 'Example CPU 8-core', 'ram_gb' => '16.0',
            'disk_summary' => 'C: 120 GB free of 500 GB', 'serial_number' => 'SYNTH-SN-0001', 'synced_at' => null] as $column => $value) {
            $this->assertStringContainsString('data-detail-column="'.$column.'"', $panel, $column);
            if ($value !== null) {
                $this->assertMatchesRegularExpression('/data-detail-column="'.$column.'">.*?<td>'.preg_quote($value, '/').'/s', $panel, $column);
            }
        }
        // psa-sp30 moves with it: the Tactical OS row points back at the device record.
        $this->assertStringContainsString('Device record: Windows Server 2019', $panel);
    }

    public function test_overview_tactical_card_keeps_health_line_refresh_maintenance_and_dashboard_link(): void
    {
        $this->configureAllSources();
        Setting::setValue('tactical_web_url', 'https://tactical.example.test');
        $html = $this->show($this->assetLinkedEverywhere([], ['serial_number' => 'SYNTH-SN-0002']))->getContent();
        $overview = substr($html, strpos($html, 'id="tab-overview"'), strpos($html, 'id="tab-sources"') - strpos($html, 'id="tab-overview"'));

        $this->assertStringContainsString('tactical-health-line', $overview);
        $this->assertStringContainsString('tacticalRefreshBtn', $overview);
        $this->assertStringContainsString('tacticalMaintenanceToggle', $overview);
        $this->assertStringContainsString('Open in Tactical RMM', $overview);
        // The details table left the card.
        $this->assertStringNotContainsString('SYNTH-SN-0002', $overview);
        $this->assertStringNotContainsString('>Serial Number<', $overview);
    }

    // ── prerequisite (a): open_in scheme allow-list (M-a) ────────────────────

    private function openInFor(string $column, string $url): ?array
    {
        $asset = Asset::factory()->make([$column => $url]);

        return \App\Services\AssetSources\SourcePanel::openIn($asset, $column === 'ninja_url' ? 'ninja' : 'level');
    }

    public function test_open_in_refuses_a_javascript_url(): void
    {
        $this->assertNull($this->openInFor('ninja_url', 'javascript:alert(1)'));
        $this->assertNull($this->openInFor('level_url', ' JavaScript:alert(document.cookie)'));
    }

    public function test_open_in_refuses_a_data_url(): void
    {
        $this->assertNull($this->openInFor('ninja_url', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='));
    }

    public function test_open_in_refuses_a_scheme_relative_url(): void
    {
        $this->assertNull($this->openInFor('level_url', '//evil.example.test/device/1'));
    }

    public function test_open_in_refuses_another_scheme_or_a_hostless_value(): void
    {
        foreach (['vbscript:msgbox(1)', 'file:///etc/passwd', 'ftp://files.example.test/x', 'https:///nohost', 'not a url'] as $url) {
            $this->assertNull($this->openInFor('ninja_url', $url), $url);
        }
    }

    public function test_open_in_accepts_an_https_url_and_renders_it_noopener(): void
    {
        $this->assertSame(['url' => 'https://level.example.test/device/1', 'label' => 'Open in Level'],
            $this->openInFor('level_url', 'https://level.example.test/device/1'));

        $this->configureAllSources();
        Setting::setValue('level_enabled', '1');
        $html = $this->show($this->assetLinkedEverywhere(['level_url' => 'https://level.example.test/device/1']))->getContent();
        $this->assertStringContainsString('href="https://level.example.test/device/1" target="_blank" rel="noopener noreferrer"', $this->panel($html, 'level'));

        $bad = $this->show($this->assetLinkedEverywhere(['level_url' => 'javascript:alert(1)']))->getContent();
        $this->assertStringNotContainsString('data-open-in', $this->panel($bad, 'level'));
        $this->assertStringNotContainsString('javascript:alert(1)', $this->panel($bad, 'level'));
    }
}
