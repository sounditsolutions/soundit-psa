<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\AssetSources;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S6: the Ninja and Level panels (design-r3 §2.2) and their Sync now,
 * NinjaSyncService::syncNow (GET /v2/device/{id}) and LevelSyncService::syncNow
 * (GET /v2/devices/{id}), each ONE single-attempt read writing this asset only.
 *
 * G-5: the Http facade refuses every request (no catch-all). NinjaClient and
 * LevelClient use Guzzle, so each is bound to a scripted MockHandler. Every request
 * is counted when it is sent (Middleware::tap), so a read whose failure a caller
 * swallows is still counted: an empty queue throws before Middleware::history would
 * record anything. An unscripted read on a path that does not catch that throw also
 * fails the request.
 * G-13: synthetic data only.
 */
class AssetSourcesNinjaLevelPanelTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    private \ArrayObject $ninjaRequests;

    private MockHandler $ninjaQueue;

    private CacheRepository $ninjaCache;

    private \ArrayObject $levelRequests;

    private MockHandler $levelQueue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);

        $this->ninjaRequests = new \ArrayObject;
        $this->ninjaQueue = new MockHandler([]);
        $stack = HandlerStack::create($this->ninjaQueue);
        $stack->push(Middleware::tap(fn ($request) => $this->ninjaRequests[] = ['request' => $request]));
        $this->ninjaCache = new CacheRepository(new ArrayStore);
        // A cached token, so a Sync now is the one device GET (the token POST has its own test).
        $this->ninjaCache->put('ninja_api_token', 'synthetic-token', 600);
        $this->app->instance(NinjaClient::class, new NinjaClient([
            'base_url' => 'https://ninja.example.test', 'client_id' => 'synthetic-ninja-id',
            'client_secret' => 'synthetic-ninja-secret', 'handler' => $stack,
        ], $this->ninjaCache));

        $this->levelRequests = new \ArrayObject;
        $this->levelQueue = new MockHandler([]);
        $stack = HandlerStack::create($this->levelQueue);
        $stack->push(Middleware::tap(fn ($request) => $this->levelRequests[] = ['request' => $request]));
        $this->app->instance(LevelClient::class, new LevelClient([
            'api_key' => 'synthetic-level-key', 'base_url' => 'https://level.example.test', 'handler' => $stack,
        ]));
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

    /** @return array<string, array{kind: string, value: string, note: string}> chip label => kind, value, note */
    private function chips(string $panel): array
    {
        preg_match_all('/data-chip-kind="([a-z_]+)">\s*<div[^>]*>([^<]*)<\/div>\s*<div[^>]*>([^<]*)<\/div>\s*<div[^>]*data-chip-note>([^<]*)</s', $panel, $m, PREG_SET_ORDER);
        $chips = [];
        foreach ($m as $chip) {
            $chips[html_entity_decode(trim($chip[2]))] = ['kind' => $chip[1],
                'value' => html_entity_decode(trim($chip[3])), 'note' => html_entity_decode(trim($chip[4]))];
        }

        return $chips;
    }

    private function sync(Asset $asset, string $source): TestResponse
    {
        return $this->actingAs(User::factory()->tech()->create())
            ->postJson(route('assets.sources.sync', [$asset, $source]))->assertOk();
    }

    /** An asset linked to every source; Ninja switched on unless $ninjaOn is false. */
    private function linked(array $overrides = [], bool $ninjaOn = true): Asset
    {
        $this->configureAllSources();
        Setting::setValue('ninja_enabled', $ninjaOn ? '1' : '0');

        return $this->assetLinkedEverywhere($overrides);
    }

    /** A synthetic GET /v2/device/{id} body (fields enrichFromDetail() reads). */
    private function ninjaDevice(Asset $asset, array $overrides = []): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'id' => (int) $asset->ninja_id,
            'organizationId' => 7,
            'systemName' => 'DESK-EXAMPLE-01',
            'lastContact' => now()->subMinutes(2)->getTimestamp(),
            'lastLoggedInUser' => 'EXAMPLE\\alex',
            'ipAddresses' => ['192.0.2.10'],
            'os' => ['name' => 'Windows 11 Pro', 'lastBootTime' => now()->subDay()->getTimestamp(), 'needsReboot' => true],
            'system' => ['memory' => ['capacity' => 17179869184], 'serialNumber' => 'SYNTH-SERIAL-NJ', 'biosSerialNumber' => 'SYNTH-BIOS-NJ'],
        ], $overrides)));
    }

    /** A synthetic GET /v2/devices/{id} body in Level's OpenAPI `device` shape. */
    private function levelDevice(Asset $asset, array $overrides = []): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'id' => $asset->level_id,
            'hostname' => 'DESK-EXAMPLE-03',
            'group_id' => 'synthetic-group',
            'online' => false,
            'serial_number' => 'SYNTH-SERIAL-LV',
            'last_logged_in_user' => 'EXAMPLE\\sam',
            'last_reboot_time' => '2026-10-30T07:00:00Z',
            'total_memory' => 8589934592,
            'operating_system' => ['full_operating_system' => 'Windows 10 Pro'],
            'cpus' => [['model' => 'Example CPU', 'cores' => 4]],
            'disk_partitions' => [['mount_point' => 'C:', 'size' => 107374182400, 'free_space' => 53687091200]],
            'network_interfaces' => [['ip_addresses' => ['192.0.2.20']]],
        ], $overrides)));
    }

    public function test_ninja_health_strip_shows_online_last_contact_last_user_and_last_boot(): void
    {
        // design-r3 §2.2 Ninja row, in the fixed template order.
        $asset = $this->linked(['rmm_online' => false, 'last_user' => 'EXAMPLE\\alex', 'last_seen_at' => now()->subMinutes(7), 'last_boot_at' => now()->subDay()]);
        $chips = $this->chips($this->panel($this->show($asset)->getContent(), 'ninja'));

        $this->assertSame(['Last synced', 'Last contact', 'Online', 'Last user', 'Last boot'], array_keys($chips));
        $this->assertSame('Offline', $chips['Online']['value']);
        $this->assertSame('7 minutes ago', $chips['Last contact']['value']);
        $this->assertSame('EXAMPLE\\alex', $chips['Last user']['value']);
        $this->assertSame($asset->last_boot_at->toAppTz()->format('M j, Y g:i A T'), $chips['Last boot']['value']);

        $empty = $this->linked(['rmm_online' => null, 'last_user' => null, 'last_boot_at' => null, 'last_seen_at' => null]);
        $chips = $this->chips($this->panel($this->show($empty)->getContent(), 'ninja'));
        foreach (['Online', 'Last user', 'Last boot', 'Last contact'] as $label) {
            $this->assertSame('Not reported by Ninja', $chips[$label]['value'], $label);
        }
    }

    public function test_level_health_strip_shows_online_last_seen_and_last_user(): void
    {
        // design-r3 §2.2 Level row: online, last seen (the Last contact chip), last user.
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'EXAMPLE\\sam', 'last_seen_at' => now()->subMinutes(9)]);
        $chips = $this->chips($this->panel($this->show($asset)->getContent(), 'level'));

        $this->assertSame(['Last synced', 'Last contact', 'Online', 'Last user'], array_keys($chips));
        $this->assertSame('Online', $chips['Online']['value']);
        $this->assertSame('9 minutes ago', $chips['Last contact']['value']);
        $this->assertSame('EXAMPLE\\sam', $chips['Last user']['value']);

        $chips = $this->chips($this->panel($this->show($this->linked(['rmm_online' => null, 'last_user' => null]))->getContent(), 'level'));
        $this->assertSame('Not reported by Level', $chips['Online']['value']);
        $this->assertSame('Not reported by Level', $chips['Last user']['value']);
    }

    public function test_every_ninja_and_level_health_chip_from_a_shared_column_carries_the_inferred_wording(): void
    {
        // M4.3 extended to the chips (Q-4 A + r3 extension): none states a shared value as fact.
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'EXAMPLE\\alex', 'last_boot_at' => now()->subDay(), 'last_seen_at' => now()->subMinute()]);
        $html = $this->show($asset)->getContent();

        foreach (['ninja' => ['Last contact', 'Online', 'Last user', 'Last boot'], 'level' => ['Last contact', 'Online', 'Last user']] as $source => $labels) {
            $chips = $this->chips($this->panel($html, $source));
            foreach ($labels as $label) {
                $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], "{$source} {$label}");
            }
            // The source's own synced_at column is not shared: no inferred wording there.
            $this->assertStringNotContainsString(AssetSources::INFERRED_WORDING, $chips['Last synced']['note'], $source);
        }
    }

    public function test_no_ninja_or_level_health_chip_takes_an_as_of_age(): void
    {
        // M-as (S1-fix, L1): ninja_synced_at / level_synced_at dates none of these shared columns.
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'EXAMPLE\\alex', 'last_boot_at' => now()->subDay()]);
        $this->assertNotNull($asset->ninja_synced_at);
        $this->assertNotNull($asset->level_synced_at);
        $html = $this->show($asset)->getContent();

        foreach (['ninja' => ['Online', 'Last user', 'Last boot'], 'level' => ['Online', 'Last user']] as $source => $labels) {
            $chips = $this->chips($this->panel($html, $source));
            $this->assertSame(AssetSources::INFERRED_WORDING, $chips['Online']['note'], $source);
            foreach ($labels as $label) {
                $this->assertSame('none', $chips[$label]['kind'], "{$source} {$label}");
                $this->assertStringNotContainsString('as of', $chips[$label]['note'], "{$source} {$label}");
                $this->assertStringNotContainsString('ago', $chips[$label]['note'], "{$source} {$label}");
            }
        }
    }

    public function test_ninja_and_level_attention_is_reboot_needed_and_offline_with_the_inferred_evidence(): void
    {
        $html = $this->show($this->linked(['needs_reboot' => true, 'rmm_online' => false]))->getContent();

        foreach (['ninja', 'level'] as $source) {
            $panel = $this->panel($html, $source);
            $this->assertMatchesRegularExpression('/data-attention-item="reboot">\s*<strong>Reboot needed<\/strong>\s*<span[^>]*>'.preg_quote(AssetSources::INFERRED_WORDING, '/').'</', $panel, $source);
            $this->assertMatchesRegularExpression('/data-attention-item="offline">\s*<strong>Offline<\/strong>\s*<span[^>]*>'.preg_quote(AssetSources::INFERRED_WORDING, '/').'</', $panel, $source);
            $rail = substr($html, strpos($html, 'data-source-rail="'.$source.'"'), 600);
            $this->assertStringContainsString('data-attention-marker="2"', $rail, $source);
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $rail, $source.' marker tooltip');
        }
    }

    public function test_ninja_and_level_panels_open_in_their_stored_url_and_offer_no_write_form(): void
    {
        $asset = $this->linked(['ninja_url' => 'https://ninja.example.test/#/deviceDashboard/1/overview', 'level_url' => 'https://level.example.test/devices/1']);
        $html = $this->show($asset)->getContent();

        foreach (['ninja' => 'https://ninja.example.test/#/deviceDashboard/1/overview', 'level' => 'https://level.example.test/devices/1'] as $source => $url) {
            $panel = $this->panel($html, $source);
            $this->assertStringContainsString('href="'.$url.'"', $panel, $source);
            $this->assertStringContainsString('Open in '.AssetSources::label($source), $panel, $source);
            $this->assertStringNotContainsString('<form', $panel, $source);
            $this->assertSame(1, substr_count($panel, '<button'), "{$source}: Sync now is the panel's only button");
            $this->assertStringContainsString('data-sync-now="'.$source.'"', $panel, $source);
        }
        // Counted when sent, so the swallowed failures below still count. With Ninja on, the
        // page view's only Ninja reads are AssetController::show()'s existing on-access refresh
        // (syncDeviceDetail, then getBackupJobData); the panels add none.
        $this->assertSame(['GET /v2/device/'.$asset->ninja_id, 'GET /v2/backup/jobs'],
            array_map(fn ($t) => $t['request']->getMethod().' '.$t['request']->getUri()->getPath(), $this->ninjaRequests->getArrayCopy()),
            "the page view's Ninja reads are AssetController::show()'s existing on-access refresh only; the panels read nothing");
        $this->assertCount(0, $this->levelRequests, 'rendering the panels reads nothing from Level');
    }

    public function test_ninja_panel_switched_off_matches_mockup_b(): void
    {
        // Ninja is OFF by default: last-known chips, no Sync now, no console link, no attention count.
        $asset = $this->linked(['ninja_url' => 'https://ninja.example.test/#/deviceDashboard/1/overview', 'last_user' => 'EXAMPLE\\alex', 'needs_reboot' => true], ninjaOn: false);
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'ninja');
        $chips = $this->chips($panel);

        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        $this->assertStringNotContainsString('data-open-in', $panel);
        $this->assertStringContainsString('Switched off in Settings; not being updated', $panel);
        $this->assertStringContainsString('None while the integration is off.', $panel);
        $this->assertStringNotContainsString('data-attention-item', $panel);
        $this->assertStringNotContainsString('data-attention-marker', substr($html, strpos($html, 'data-source-rail="ninja"'), 600));
        foreach (['Online', 'Last user', 'Last boot', 'Last contact'] as $label) {
            $this->assertStringContainsString('last known, not updated', $chips[$label]['note'], $label);
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], $label);
        }
        $this->assertSame('EXAMPLE\\alex', $chips['Last user']['value']);
        $this->assertCount(0, $this->ninjaRequests);
    }

    public function test_level_panel_switched_off_has_no_button_and_labels_chips_last_known(): void
    {
        $asset = $this->linked(['last_user' => 'EXAMPLE\\sam']);
        Setting::setValue('level_enabled', '0');
        $panel = $this->panel($this->show($asset)->getContent(), 'level');
        $chips = $this->chips($panel);

        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        $this->assertStringNotContainsString('data-open-in', $panel);
        foreach (['Online', 'Last user'] as $label) {
            $this->assertStringContainsString('last known, not updated', $chips[$label]['note'], $label);
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], $label);
        }
        $this->assertCount(0, $this->levelRequests);
    }

    public function test_overview_no_longer_posts_to_assets_refresh(): void
    {
        // M4.4: the Overview "Refresh from RMM" form is gone; its job is Sync now on these panels.
        $asset = $this->linked();
        $html = $this->show($asset)->getContent();

        $this->assertStringNotContainsString('Refresh from RMM', $html);
        $this->assertStringNotContainsString('/assets/'.$asset->id.'/refresh"', $html);
        $this->assertStringContainsString('data-sync-now="ninja"', $html);
        $this->assertStringContainsString('data-sync-now="level"', $html);
    }

    public function test_ninja_sync_now_reads_get_v2_device_id_once_and_writes_this_asset_only(): void
    {
        $asset = $this->linked(['rmm_online' => false, 'last_user' => 'olduser', 'os' => 'Windows 10', 'needs_reboot' => false,
            'cpu' => 'Stored CPU', 'disk_summary' => 'C: 100 GB', 'serial_number' => 'STORED-SERIAL', 'warranty_end' => '2027-01-01']);
        $asset->forceFill(['ninja_synced_at' => now()->subDay()])->saveQuietly();
        $sibling = $this->assetLinkedEverywhere(['client_id' => $asset->client_id, 'rmm_online' => false]);
        $siblingBefore = $sibling->refresh()->getAttributes();
        $before = $asset->refresh()->only(['ninja_id', 'ninja_url', 'client_id', 'is_active', 'name', 'hostname']);
        $this->ninjaQueue->append($this->ninjaDevice($asset));

        $r = $this->sync($asset, 'ninja')->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);

        $this->assertCount(1, $this->ninjaRequests);
        $req = $this->ninjaRequests[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://ninja.example.test/v2/device/'.$asset->ninja_id, (string) $req->getUri());
        $asset->refresh();
        $this->assertTrue($asset->rmm_online);
        $this->assertSame('EXAMPLE\\alex', $asset->last_user);
        $this->assertSame('Windows 11 Pro', $asset->os);
        $this->assertEquals(16.0, $asset->ram_gb);
        $this->assertSame('192.0.2.10', $asset->ip_address);
        $this->assertTrue($asset->needs_reboot);
        $this->assertTrue($asset->ninja_synced_at->greaterThan(now()->subMinute()));
        // Not in this GET, so never written: cpu, disks, warranty; the serial is in it but never written.
        $this->assertSame('Stored CPU', $asset->cpu);
        $this->assertSame('C: 100 GB', $asset->disk_summary);
        $this->assertSame('STORED-SERIAL', $asset->serial_number);
        $this->assertSame('2027-01-01', $asset->warranty_end->toDateString());
        $this->assertSame($before, $asset->only(array_keys($before)), 'link, url, client, active, name and hostname unchanged');
        foreach (['rmm_online', 'last_user', 'os', 'needs_reboot', 'ninja_synced_at'] as $column) {
            $this->assertContains($column, $r->json('changed'), $column);
        }
        $this->assertSame($siblingBefore, $sibling->refresh()->getAttributes(), 'a sibling of the same client is untouched');
        $row = AssetSourceSync::sole();
        $this->assertSame(['ninja', 'ok', 'ok', null], [$row->source, $row->outcome, $row->reason, $row->http_status]);
        Http::assertNothingSent();
    }

    public function test_ninja_sync_now_with_no_cached_token_makes_the_token_post_then_one_get(): void
    {
        // The description's "Reads this computer's record": the client's own OAuth POST precedes it.
        $this->ninjaCache->forget('ninja_api_token');
        $asset = $this->linked();
        $this->ninjaQueue->append(new PsrResponse(200, [], json_encode(['access_token' => 'synthetic-token-2', 'expires_in' => 3600])));
        $this->ninjaQueue->append($this->ninjaDevice($asset));

        $this->sync($asset, 'ninja')->assertJson(['outcome' => 'ok', 'reason' => 'ok']);

        $this->assertSame(['POST /ws/oauth/token', 'GET /v2/device/'.$asset->ninja_id],
            array_map(fn ($t) => $t['request']->getMethod().' '.$t['request']->getUri()->getPath(), $this->ninjaRequests->getArrayCopy()));
    }

    public function test_level_sync_now_reads_get_v2_devices_id_once_and_writes_this_asset_only(): void
    {
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'olduser', 'serial_number' => 'STORED-SERIAL', 'last_boot_at' => now()->subWeek()]);
        $asset->forceFill(['level_synced_at' => now()->subDay()])->saveQuietly();
        $sibling = $this->assetLinkedEverywhere(['client_id' => $asset->client_id]);
        $siblingBefore = $sibling->refresh()->getAttributes();
        $before = $asset->refresh()->only(['level_id', 'level_url', 'client_id', 'is_active', 'serial_number', 'last_boot_at']);
        $this->levelQueue->append($this->levelDevice($asset));

        $r = $this->sync($asset, 'level')->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);

        $this->assertCount(1, $this->levelRequests);
        $req = $this->levelRequests[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/v2/devices/'.$asset->level_id, $req->getUri()->getPath());
        $this->assertStringContainsString('include_cpus=true', $req->getUri()->getQuery());
        $asset->refresh();
        $this->assertFalse($asset->rmm_online);
        $this->assertSame('DESK-EXAMPLE-03', $asset->hostname);
        $this->assertSame('DESK-EXAMPLE-03', $asset->name);
        $this->assertSame('EXAMPLE\\sam', $asset->last_user);
        $this->assertSame('Windows 10 Pro', $asset->os);
        $this->assertSame('Example CPU (4 cores)', $asset->cpu);
        $this->assertEquals(8.0, $asset->ram_gb);
        $this->assertSame('C:: 100 GB (50% free)', $asset->disk_summary);
        $this->assertSame('192.0.2.20', $asset->ip_address);
        $this->assertSame('2026-10-30 07:00:00', $asset->last_seen_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($asset->level_synced_at->greaterThan(now()->subMinute()));
        $this->assertEquals($before, $asset->only(array_keys($before)), 'link, url, client, active, serial and last boot unchanged (syncDeviceDetail writes none)');
        foreach (['rmm_online', 'hostname', 'last_user', 'cpu', 'level_synced_at'] as $column) {
            $this->assertContains($column, $r->json('changed'), $column);
        }
        $this->assertSame($siblingBefore, $sibling->refresh()->getAttributes(), 'a sibling of the same client is untouched');
        $this->assertSame(['level', 'ok', 'ok'], [AssetSourceSync::sole()->source, AssetSourceSync::sole()->outcome, AssetSourceSync::sole()->reason]);
        Http::assertNothingSent();
    }

    public function test_changed_counts_null_to_false_on_rmm_online_and_not_false_to_false(): void
    {
        // M-changed (L3): getDirty() compares through the casts.
        $asset = $this->linked(['rmm_online' => null]);
        $this->levelQueue->append($this->levelDevice($asset));
        $this->assertContains('rmm_online', $this->sync($asset, 'level')->json('changed'), 'null -> false is a change');

        $control = $this->linked(['rmm_online' => false]);
        $this->levelQueue->append($this->levelDevice($control));
        $this->assertNotContains('rmm_online', $this->sync($control, 'level')->json('changed'), 'false -> false is not');
    }

    public function test_ninja_and_level_switched_off_refuse_at_the_switch_step_with_zero_requests(): void
    {
        // M4.1: ninja_enabled=0 / level_enabled=0 -> switched_off, no request, nothing written.
        $asset = $this->linked(ninjaOn: false);
        Setting::setValue('level_enabled', '0');
        $before = $asset->refresh()->getAttributes();

        foreach (['ninja', 'level'] as $source) {
            $this->sync($asset, $source)->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        }

        $this->assertCount(0, $this->ninjaRequests);
        $this->assertCount(0, $this->levelRequests);
        $this->assertSame($before, $asset->refresh()->getAttributes());
    }

    public function test_switched_off_refuses_at_the_switch_step_before_the_limiter_and_window(): void
    {
        // M4.1 (pipeline half): AssetSources::isSwitchedOn reads NinjaConfig / LevelConfig::isEnabled().
        // Every call is switched_off: never user_throttled or recently_synced, which a missing switch
        // step would reach once the client's own refusal had opened the 30 s window.
        $asset = $this->linked(ninjaOn: false);
        Setting::setValue('level_enabled', '0');
        $user = User::factory()->tech()->create();

        foreach (['ninja', 'level'] as $source) {
            foreach (range(1, SourceSyncPipeline::USER_LIMIT_PER_MINUTE + 1) as $i) {
                $this->actingAs($user)->postJson(route('assets.sources.sync', [$asset, $source]))
                    ->assertJson(['outcome' => 'refused', 'reason' => 'switched_off'], "{$source} call {$i}");
            }
        }
        $this->assertCount(0, $this->ninjaRequests);
        $this->assertCount(0, $this->levelRequests);
    }

    public function test_the_default_device_read_still_retries_a_429_for_the_scheduled_and_webhook_callers(): void
    {
        // Positive control for the opt-in: without singleAttempt the clients behave as before
        // (Retry-After 1, so the existing back-off sleeps 1 s per client; a Retry-After of '0'
        // is falsy there and would fall back to 10 s).
        $this->configureAllSources();
        Setting::setValue('ninja_enabled', '1');
        $this->ninjaQueue->append(new PsrResponse(429, ['Retry-After' => '1'], '{}'));
        $this->ninjaQueue->append(new PsrResponse(200, [], json_encode(['id' => 5])));
        $this->levelQueue->append(new PsrResponse(429, ['Retry-After' => '1'], '{}'));
        $this->levelQueue->append(new PsrResponse(200, [], json_encode(['id' => 'level-5', 'online' => true])));

        $this->assertSame(5, app(NinjaClient::class)->getDevice(5)['id']);
        $this->assertSame('level-5', app(LevelClient::class)->getDevice('level-5')['id']);
        $this->assertCount(2, $this->ninjaRequests);
        $this->assertCount(2, $this->levelRequests);
    }

    public function test_switched_off_the_client_transport_refuses_the_read_itself(): void
    {
        // M4.1, second gate: even past the pipeline's switch step, the client refuses with no request.
        $asset = $this->linked(ninjaOn: false);
        Setting::setValue('level_enabled', '0');

        $this->assertSame('switched_off', app(\App\Services\Ninja\NinjaSyncService::class)->syncNow($asset)['reason']);
        $this->assertSame('switched_off', app(\App\Services\Level\LevelSyncService::class)->syncNow($asset)['reason']);
        $this->assertCount(0, $this->ninjaRequests);
        $this->assertCount(0, $this->levelRequests);
    }

    /** @return array<string, array{0: string}> */
    public static function rmms(): array
    {
        return ['ninja' => ['ninja'], 'level' => ['level']];
    }

    private function queue(string $source): MockHandler
    {
        return $source === 'ninja' ? $this->ninjaQueue : $this->levelQueue;
    }

    private function requests(string $source): \ArrayObject
    {
        return $source === 'ninja' ? $this->ninjaRequests : $this->levelRequests;
    }

    /** Assert the asset's stored row is exactly $before (nothing written, synced_at included). */
    private function assertUnwritten(Asset $asset, array $before): void
    {
        $this->assertSame($before, $asset->refresh()->getAttributes());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_404_is_not_found_at_vendor_keeps_the_link_and_writes_nothing(string $source): void
    {
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        $this->queue($source)->append(new PsrResponse(404, [], json_encode(['error' => 'Record not found'])));

        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'not_found_at_vendor', 'changed' => []])
            ->assertJsonPath('message', 'The vendor did not return this device; the link is kept.');

        $this->assertUnwritten($asset, $before);
        $this->assertSame(404, AssetSourceSync::sole()->http_status);
        $this->assertCount(1, $this->requests($source));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_429_sends_exactly_one_request_does_not_sleep_and_starts_the_cooldown(string $source): void
    {
        // M4.2 / M-one-attempt: the clients' scheduled-path retry would queue a second request
        // (and sleep 42 s first); the web path makes one and returns.
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        $this->queue($source)->append(new PsrResponse(429, ['Retry-After' => '42'], '{}'));
        $this->queue($source)->append(new PsrResponse(429, ['Retry-After' => '42'], '{}'));

        $t0 = microtime(true);
        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'vendor_429', 'retry_after_s' => 42])
            ->assertJsonPath('message', 'The vendor is rate-limiting requests.');
        $this->assertLessThan(5.0, microtime(true) - $t0, 'no in-request sleep');

        $this->assertCount(1, $this->requests($source), 'exactly one request');
        $this->assertCount(1, $this->queue($source), 'the second scripted answer is never asked for');
        $this->assertUnwritten($asset, $before);
        $row = AssetSourceSync::sole();
        $this->assertSame([429, 42], [$row->http_status, $row->retry_after_s]);
        $until = (int) Cache::get(SourceSyncPipeline::vendorCooldownKey($source));
        $this->assertEqualsWithDelta(now()->getTimestamp() + 42, $until, 2);
        // The next Sync now, on any asset, is refused by the cooldown with no request.
        $this->travel(31)->seconds();
        $this->sync($this->linked(), $source)->assertJsonPath('reason', 'vendor_cooling_down');
        $this->assertCount(1, $this->requests($source));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_429_without_a_usable_retry_after_waits_the_60_s_fallback(string $source): void
    {
        $asset = $this->linked();
        $this->queue($source)->append(new PsrResponse(429, ['Retry-After' => '900'], '{}'));

        $this->sync($asset, $source)->assertJson(['reason' => 'vendor_429', 'retry_after_s' => SourceSyncPipeline::RETRY_AFTER_FALLBACK_S]);
        $this->assertCount(1, $this->requests($source));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_other_status_is_http_nnn_with_its_status_recorded(string $source): void
    {
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        $this->queue($source)->append(new PsrResponse(503, [], '{}'));

        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'http_503'])
            ->assertJsonPath('message', 'The vendor answered HTTP 503.');

        $this->assertUnwritten($asset, $before);
        $this->assertSame(503, AssetSourceSync::sole()->http_status);
        $this->assertCount(1, $this->requests($source));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_an_error_whose_body_is_scalar_json_keeps_its_status_and_a_429_starts_the_cooldown(string $source): void
    {
        // A JSON string body on an error is no body the client exception can carry; the
        // status still reaches failureReason(), so it is never drift.
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        $this->queue($source)->append(new PsrResponse(429, ['Retry-After' => '120'], json_encode('rate limited')));

        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'vendor_429', 'retry_after_s' => 120]);

        $this->assertUnwritten($asset, $before);
        $row = AssetSourceSync::sole();
        $this->assertSame([429, 120], [$row->http_status, $row->retry_after_s]);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 120, (int) Cache::get(SourceSyncPipeline::vendorCooldownKey($source)), 2);

        Cache::forget(SourceSyncPipeline::vendorCooldownKey($source));
        $other = $this->linked();
        $this->queue($source)->append(new PsrResponse(404, [], json_encode('Not found')));
        $this->sync($other, $source)->assertJson(['outcome' => 'failed', 'reason' => 'not_found_at_vendor']);
        $this->assertSame(404, AssetSourceSync::latest('id')->first()->http_status);
        $this->assertCount(2, $this->requests($source));
    }

    public function test_ninja_401_is_http_401_with_no_retry_and_drops_the_cached_token(): void
    {
        // The scheduled callers retry a 401 once with a fresh token; the single attempt does not.
        $asset = $this->linked();
        $this->ninjaQueue->append(new PsrResponse(401, [], '{}'));

        $this->sync($asset, 'ninja')->assertJson(['outcome' => 'failed', 'reason' => 'http_401']);

        $this->assertCount(1, $this->ninjaRequests, 'no token POST and no second GET');
        $this->assertNull($this->ninjaCache->get('ninja_api_token'), 'the next request asks for a fresh token');
    }

    public function test_ninja_token_post_refused_is_http_nnn_and_reads_nothing(): void
    {
        // The token POST answered and refused: the vendor was reached, so never transport.
        $this->ninjaCache->forget('ninja_api_token');
        $asset = $this->linked();
        $this->ninjaQueue->append(new PsrResponse(400, [], json_encode(['error' => 'invalid_client'])));

        $this->sync($asset, 'ninja')->assertJson(['outcome' => 'failed', 'reason' => 'http_400']);
        $this->assertCount(1, $this->ninjaRequests);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rmms')]
    public function test_no_response_is_transport_and_writes_nothing(string $source): void
    {
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        $this->queue($source)->append(new ConnectException('unreachable', new PsrRequest('GET', '/v2/device')));

        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'transport'])
            ->assertJsonPath('message', 'Could not reach the vendor.');

        $this->assertUnwritten($asset, $before);
        $this->assertNull(AssetSourceSync::sole()->http_status);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function driftBodies(): array
    {
        return [
            'ninja: not JSON' => ['ninja', 'not json'],
            'ninja: a list' => ['ninja', [['id' => 1]]],
            'ninja: a JSON string' => ['ninja', 'just a string'],
            'ninja: another device' => ['ninja', ['id' => 1, 'lastContact' => 1716900000]],
            'ninja: no id' => ['ninja', ['id' => null]],
            'ninja: organizationId not an integer' => ['ninja', ['organizationId' => 'seven']],
            'ninja: os.name not a string' => ['ninja', ['os' => ['name' => ['Windows']]]],
            'ninja: a user longer than the column' => ['ninja', ['lastLoggedInUser' => str_repeat('u', 256)]],
            'ninja: lastContact in milliseconds' => ['ninja', ['lastContact' => 1716900000000]],
            'ninja: lastBootTime in milliseconds' => ['ninja', ['os' => ['name' => 'Windows 11 Pro', 'lastBootTime' => 1716900000000]]],
            'level: not JSON' => ['level', 'not json'],
            'level: a list' => ['level', [['id' => 'x']]],
            'level: another device' => ['level', ['id' => 'level-other']],
            'level: no online flag' => ['level', ['online' => null]],
            'level: online not a bool' => ['level', ['online' => 'yes']],
            'level: hostname not a string' => ['level', ['hostname' => ['DESK']]],
            'level: a zero reboot time on an offline device' => ['level', ['last_reboot_time' => '0001-01-01T00:00:00Z']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driftBodies')]
    public function test_shape_failures_are_drift_and_write_nothing(string $source, mixed $body): void
    {
        $asset = $this->linked();
        $before = $asset->refresh()->getAttributes();
        if (is_array($body) && ! array_is_list($body)) {
            // Overrides on a valid body, so each case breaks exactly one thing.
            $response = $source === 'ninja' ? $this->ninjaDevice($asset, $body) : $this->levelDevice($asset, $body);
            if (array_key_exists('online', $body) && $body['online'] === null) {
                $data = json_decode((string) $response->getBody(), true);
                unset($data['online']);
                $response = new PsrResponse(200, [], json_encode($data));
            }
        } else {
            $response = new PsrResponse(200, [], $body === 'not json' ? '{not json' : json_encode($body));
        }
        $this->queue($source)->append($response);

        $this->sync($asset, $source)->assertJson(['outcome' => 'failed', 'reason' => 'drift', 'changed' => []])
            ->assertJsonPath('message', 'The vendor answered in a shape this PSA does not recognise; nothing was written.');

        $this->assertUnwritten($asset, $before);
        $this->assertCount(1, $this->requests($source));
    }

    public function test_a_ninja_device_of_another_organization_is_other_client_and_writes_nothing(): void
    {
        $asset = $this->linked();
        $asset->client->update(['ninja_org_id' => 8]);
        $before = $asset->refresh()->getAttributes();
        $this->ninjaQueue->append($this->ninjaDevice($asset, ['organizationId' => 7]));

        $this->sync($asset, 'ninja')->assertJson(['outcome' => 'failed', 'reason' => 'other_client'])
            ->assertJsonPath('message', "The vendor's device belongs to a different client; nothing was written.");
        $this->assertUnwritten($asset, $before);

        // Positive control: the same body for the mapped organization is written.
        $asset->client->update(['ninja_org_id' => 7]);
        $this->travel(31)->seconds();
        $this->ninjaQueue->append($this->ninjaDevice($asset, ['organizationId' => 7]));
        $this->sync($asset, 'ninja')->assertJson(['outcome' => 'ok']);
    }
}
