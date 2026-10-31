<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\ControlD\ControlDClient;
use App\Services\Zorus\ZorusClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S3 (design/mockups/assets/design-r3.md §2.2, §4 A, §8 S3): the M365,
 * Control D, Zorus and ScreenConnect panels, the Security tab's summary rows, and
 * Control D's Sync now (the per-org device read, written to this asset only).
 *
 * G-5: Http::preventStrayRequests with no catch-all; Control D and Zorus are Guzzle
 * clients bound to scripted queues (an empty queue throws on any request).
 * G-13: synthetic data only.
 */
class AssetSourcesSecurityPanelsTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    private const ORG = 'synthetic-org-0001';

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $controld;

    private MockHandler $controldQueue;

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $zorus;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);

        $this->controld = new \ArrayObject;
        $this->controldQueue = new MockHandler([]);
        $stack = HandlerStack::create($this->controldQueue);
        $stack->push(Middleware::history($this->controld));
        $this->app->bind(ControlDClient::class, fn ($app, array $p) => new ControlDClient(($p['config'] ?? []) + ['handler' => $stack]));

        $this->zorus = new \ArrayObject;
        $zStack = HandlerStack::create(new MockHandler([]));
        $zStack->push(Middleware::history($this->zorus));
        $this->app->bind(ZorusClient::class, fn ($app, array $p) => new ZorusClient(($p['config'] ?? []) + ['handler' => $zStack]));
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

    private function security(string $html): string
    {
        $start = strpos($html, 'id="tab-security"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'id="tab-alerts"', $start) - $start);
    }

    private function sources(string $html): string
    {
        $start = strpos($html, 'id="tab-sources"');
        $this->assertNotFalse($start);

        return substr($html, $start);
    }

    /** One health-strip chip's label, value and note, by label. */
    private function chip(string $panel, string $label): array
    {
        $ok = preg_match('/data-chip-kind="([a-z_]+)">\s*<div[^>]*>'.preg_quote($label, '/').'<\/div>\s*<div class="fw-semibold">([^<]*)<\/div>\s*<div class="small text-muted" data-chip-note>([^<]*)<\/div>/', $panel, $m);
        $this->assertSame(1, $ok, "chip {$label} is rendered");

        return ['kind' => $m[1], 'value' => html_entity_decode(trim($m[2]), ENT_QUOTES), 'note' => html_entity_decode(trim($m[3]), ENT_QUOTES)];
    }

    private function sync(Asset $asset, string $source = 'controld', ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->tech()->create())
            ->postJson(route('assets.sources.sync', [$asset, $source]));
    }

    /** A Control D-linked asset whose client is mapped to the synthetic org. */
    private function cdAsset(array $overrides = []): Asset
    {
        $asset = $this->assetLinkedEverywhere($overrides);
        // controld_org_id is unique per client: the first mapped client gets ORG.
        $org = Client::where('controld_org_id', self::ORG)->exists() ? self::ORG.'-'.$asset->client_id : self::ORG;
        $asset->client->update(['controld_org_id' => $org]);

        return $asset->refresh();
    }

    /** A device row in the Control D devices list shape (ControlDDeviceSyncService reads it). */
    private function device(string $pk, array $overrides = []): array
    {
        return array_replace_recursive([
            'PK' => $pk, 'name' => 'DESK-EXAMPLE-01', 'status' => 1,
            'profile' => ['PK' => 'synthetic-profile', 'name' => 'Synthetic workstations'],
            'ctrld' => ['status' => 1, 'version' => '1.3.9', 'last_fetch' => 1790000000],
        ], $overrides);
    }

    private function devicesResponse(array $devices, int $status = 200, array $headers = []): PsrResponse
    {
        return new PsrResponse($status, $headers, json_encode(['body' => ['devices' => $devices], 'success' => true]));
    }

    // --- Control D Sync now ----------------------------------------------------------------

    public function test_controld_sync_now_reads_the_org_device_list_once_and_writes_this_asset(): void
    {
        $this->configureAllSources();
        $asset = $this->cdAsset(['controld_status' => 0, 'controld_agent_status' => 0, 'controld_synced_at' => now()->subDay()]);
        $this->controldQueue->append($this->devicesResponse([
            $this->device('cd-other-device'),
            $this->device($asset->controld_device_id),
        ]));

        $r = $this->sync($asset)->assertOk()
            ->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);
        $this->assertContains('controld_agent_status', $r->json('changed'));
        $this->assertContains('controld_synced_at', $r->json('changed'));

        $this->assertCount(1, $this->controld);
        $req = $this->controld[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://api.controld.com/devices', (string) $req->getUri());
        $this->assertSame(self::ORG, $req->getHeaderLine('X-Force-Org-Id'));

        $asset->refresh();
        $this->assertSame(1, $asset->controld_status);
        $this->assertSame(1, $asset->controld_agent_status);
        $this->assertSame('Synthetic workstations', $asset->controld_profile_name);
        $this->assertSame('1.3.9', $asset->controld_agent_version);
        $this->assertSame(1790000000, $asset->controld_last_seen_at->getTimestamp());
        $this->assertTrue($asset->controld_synced_at->greaterThan(now()->subMinute()));
        $row = AssetSourceSync::sole();
        $this->assertSame(['controld', 'ok', 'ok', null], [$row->source, $row->outcome, $row->reason, $row->http_status]);
        Http::assertNothingSent();
    }

    public function test_sync_of_a_leaves_sibling_b_controld_columns_untouched(): void
    {
        // M5.1 / M-cdst: the stale-clear branch (syncDevicesForClient) would null B, which is
        // linked in the same org but absent from the list.
        $this->configureAllSources();
        $a = $this->cdAsset();
        $b = Asset::factory()->create([
            'client_id' => $a->client_id, 'hostname' => 'DESK-EXAMPLE-02',
            'controld_device_id' => 'cd-sibling-b', 'controld_profile_name' => 'Sibling profile',
            'controld_status' => 1, 'controld_agent_status' => 1, 'controld_agent_version' => '1.0.0',
            'controld_last_seen_at' => now()->subHours(6), 'controld_synced_at' => now()->subDays(2),
        ]);
        $before = $b->refresh()->only(SourceSyncPipeline::CONTROLD_WRITES);
        // Two identical answers queued, so a second read (the per-client sync) would succeed
        // and reach its stale clear rather than fail on an empty queue.
        $this->controldQueue->append($this->devicesResponse([$this->device($a->controld_device_id)]));
        $this->controldQueue->append($this->devicesResponse([$this->device($a->controld_device_id)]));

        $this->sync($a)->assertJsonPath('outcome', 'ok');

        $this->assertEquals($before, $b->refresh()->only(SourceSyncPipeline::CONTROLD_WRITES));
        $this->assertSame('cd-sibling-b', $b->controld_device_id);
        $this->assertCount(1, $this->controld, 'one read; the per-client sync is never run');
    }

    public function test_device_absent_from_the_org_list_is_not_found_at_vendor_link_kept_nothing_written(): void
    {
        // M5.2.
        $this->configureAllSources();
        $asset = $this->cdAsset(['controld_profile_name' => 'Kept profile', 'controld_synced_at' => now()->subDay()]);
        $before = $asset->only(SourceSyncPipeline::CONTROLD_WRITES);
        $this->controldQueue->append($this->devicesResponse([$this->device('cd-other-device')]));

        $this->sync($asset)->assertOk()->assertJson([
            'outcome' => 'failed', 'reason' => 'not_found_at_vendor', 'changed' => [],
            'message' => 'The vendor did not return this device; the link is kept.',
        ]);
        $this->assertEquals($before, $asset->refresh()->only(SourceSyncPipeline::CONTROLD_WRITES));
        $this->assertSame('not_found_at_vendor', AssetSourceSync::sole()->reason);
    }

    public function test_linked_asset_of_an_unmapped_client_is_client_not_mapped_before_any_request(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $this->assertNull($asset->client->controld_org_id, 'fixture: no Control D org');

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'client_not_mapped']);
        $this->assertCount(0, $this->controld);
    }

    public function test_controld_sync_now_switched_off_is_refused_with_no_request(): void
    {
        // M-cdoff: the switched-on gate is AssetSources::isSwitchedOn('controld') = ControlDConfig::isEnabled().
        $this->configureAllSources();
        Setting::setValue('controld_enabled', '0');
        $asset = $this->cdAsset();
        $this->controldQueue->append($this->devicesResponse([$this->device($asset->controld_device_id)]));

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        $this->assertCount(0, $this->controld);
        $panel = $this->panel($this->show($asset)->getContent(), 'controld');
        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
    }

    public function test_controld_sync_now_switched_on_positive_control_reaches_the_read(): void
    {
        $this->configureAllSources();
        Setting::setValue('controld_enabled', '1');
        $asset = $this->cdAsset();
        $this->controldQueue->append($this->devicesResponse([$this->device($asset->controld_device_id)]));

        $this->sync($asset)->assertJsonPath('reason', 'ok');
        $this->assertCount(1, $this->controld);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function statusAnswers(): array
    {
        return [
            '404' => [404, 'not_found_at_vendor'],
            '401' => [401, 'http_401'],
            '503' => [503, 'http_503'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusAnswers')]
    public function test_a_vendor_status_maps_through_failure_reason_with_http_status_recorded(int $status, string $reason): void
    {
        // M-cls (S1-fix): a vendor that answered is never transport.
        $this->configureAllSources();
        $asset = $this->cdAsset(['controld_synced_at' => now()->subDay()]);
        $before = $asset->only(SourceSyncPipeline::CONTROLD_WRITES);
        $this->controldQueue->append(new PsrResponse($status, [], '{}'));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => $reason, 'changed' => [],
            'message' => SourceSyncPipeline::sentence($reason)]);
        $row = AssetSourceSync::sole();
        $this->assertSame([$reason, $status], [$row->reason, $row->http_status]);
        $this->assertEquals($before, $asset->refresh()->only(SourceSyncPipeline::CONTROLD_WRITES));
    }

    public function test_no_response_is_transport(): void
    {
        $this->configureAllSources();
        $asset = $this->cdAsset();
        $this->controldQueue->append(new ConnectException('unreachable', new PsrRequest('GET', 'devices')));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'transport', 'message' => 'Could not reach the vendor.']);
        $this->assertNull(AssetSourceSync::sole()->http_status);
    }

    public function test_a_body_without_the_devices_list_is_drift_never_not_found(): void
    {
        $this->configureAllSources();
        $asset = $this->cdAsset();
        foreach ([json_encode(['body' => ['items' => []]]), json_encode(['body' => ['devices' => ['PK' => 'x']]]), '"text"'] as $i => $body) {
            $this->controldQueue->append(new PsrResponse(200, [], $body));
            Cache::flush();
            $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'drift'], (string) $i);
        }
        $this->controldQueue->append($this->devicesResponse([$this->device($asset->controld_device_id, ['profile' => 'not-an-object'])]));
        Cache::flush();
        $this->sync($asset)->assertJsonPath('reason', 'drift');
        $this->assertSame(4, AssetSourceSync::where('reason', 'drift')->count());
    }

    public function test_a_429_is_vendor_429_with_retry_after_and_writes_the_controld_cooldown(): void
    {
        $this->configureAllSources();
        $asset = $this->cdAsset();
        $this->controldQueue->append(new PsrResponse(429, ['Retry-After' => '42'], '{}'));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'vendor_429', 'retry_after_s' => 42]);
        $this->assertSame(429, AssetSourceSync::sole()->http_status);
        $until = (int) Cache::get(SourceSyncPipeline::vendorCooldownKey('controld'));
        $this->assertEqualsWithDelta(now()->getTimestamp() + 42, $until, 1);

        // The cooldown is read by step 6: another asset gets no request.
        $other = $this->cdAsset();
        $this->sync($other)->assertJson(['reason' => 'vendor_cooling_down']);
        $this->assertCount(1, $this->controld);
    }

    public function test_a_429_with_an_unusable_retry_after_falls_back_to_60(): void
    {
        $this->configureAllSources();
        foreach (['301', 'Wed, 21 Oct 2026 07:28:00 GMT', ''] as $value) {
            Cache::flush();
            $asset = $this->cdAsset();
            $this->controldQueue->append(new PsrResponse(429, $value === '' ? [] : ['Retry-After' => $value], '{}'));
            $this->sync($asset)->assertJson(['reason' => 'vendor_429', 'retry_after_s' => 60], $value);
        }
        $this->assertSame(0, SourceSyncPipeline::retryAfterSeconds('0'));
        $this->assertSame(300, SourceSyncPipeline::retryAfterSeconds('300'));
    }

    public function test_the_vendor_exception_message_reaches_no_reply_audit_row_or_pipeline_log(): void
    {
        // M-cdmsg (C-56): Guzzle puts the vendor body into the exception message.
        $this->configureAllSources();
        $asset = $this->cdAsset();
        $marker = 'VENDOR-TEXT-SYNTHETIC-7Q';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->controldQueue->append(new PsrResponse(503, [], '{"error":{"message":"'.$marker.'"}}'));

        $r = $this->sync($asset)->assertJsonPath('reason', 'http_503');
        $this->assertStringNotContainsString($marker, $r->getContent());
        $this->assertStringNotContainsString($marker, json_encode(AssetSourceSync::sole()->getAttributes()));
        // ControlDClient's own pre-existing log line is outside this card (held file); the
        // pipeline adds none.
        foreach ($logged as $line) {
            if (! str_starts_with($line, '[ControlDClient]')) {
                $this->assertStringNotContainsString($marker, $line);
            }
        }
        $panel = $this->panel($this->show($asset)->getContent(), 'controld');
        $this->assertStringNotContainsString($marker, $panel);
        $this->assertStringContainsString('The vendor answered HTTP 503.', $panel);
    }

    // --- Panels ----------------------------------------------------------------------------

    public function test_null_m365_compliance_renders_unknown_never_compliant(): void
    {
        // M3S.4.
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere(['m365_is_compliant' => null, 'm365_compliance_state' => null]))->getContent(), 'm365');
        $chip = $this->chip($panel, 'Compliance');
        $this->assertSame('Unknown', $chip['value']);
        $this->assertStringNotContainsString('data-attention-item="compliance"', $panel);

        $true = $this->panel($this->show($this->assetLinkedEverywhere(['m365_is_compliant' => true]))->getContent(), 'm365');
        $this->assertSame('Compliant', $this->chip($true, 'Compliance')['value']);
        $false = $this->panel($this->show($this->assetLinkedEverywhere(['m365_is_compliant' => false, 'm365_compliance_state' => 'noncompliant']))->getContent(), 'm365');
        $this->assertSame('Non-Compliant', $this->chip($false, 'Compliance')['value']);
    }

    public function test_m365_panel_chips_and_as_of_follow_what_wrote_them(): void
    {
        // M-as: compliance is written with m365_synced_at; Defender and last scan only when
        // the read carried Defender data, so they carry no "as of".
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere([
            'm365_defender_status' => 'SignaturesOutOfDate', 'm365_last_scan_at' => now()->subDays(2),
            'm365_last_sync_at' => now()->subHours(7),
        ]))->getContent(), 'm365');

        $compliance = $this->chip($panel, 'Compliance');
        $this->assertSame('snapshot', $compliance['kind']);
        $this->assertMatchesRegularExpression('/^as of 5 hours ago$/', $compliance['note']);
        $defender = $this->chip($panel, 'Defender');
        $this->assertSame(['none', 'SignaturesOutOfDate', ''], [$defender['kind'], $defender['value'], $defender['note']]);
        $scan = $this->chip($panel, 'Last scan');
        $this->assertSame(['none', '2 days ago', ''], [$scan['kind'], $scan['value'], $scan['note']]);
        $this->assertSame('7 hours ago', $this->chip($panel, 'Last contact')['value']);
        $this->assertStringContainsString('Intune last sync', $this->chip($panel, 'Last contact')['note']);
    }

    public function test_controld_panel_chips_attention_and_as_of(): void
    {
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->cdAsset([
            'controld_status' => 1, 'controld_agent_status' => 0, 'controld_profile_name' => 'Synthetic workstations',
            'controld_last_seen_at' => now()->subHours(2),
        ]))->getContent(), 'controld');

        foreach (['Device status' => 'Active', 'Agent status' => 'Disconnected', 'Profile' => 'Synthetic workstations'] as $label => $value) {
            $chip = $this->chip($panel, $label);
            $this->assertSame([$value, 'snapshot', 'as of 3 hours ago'], [$chip['value'], $chip['kind'], $chip['note']], $label);
        }
        $this->assertSame('2 hours ago', $this->chip($panel, 'Last contact')['value']);
        $this->assertStringContainsString('data-attention-item="agent"', $panel);
        $this->assertStringContainsString('Agent not active', $panel);
        $this->assertStringNotContainsString('data-open-in', $panel);
    }

    public function test_zorus_panel_chips_attention_no_activity_no_button_and_the_no_single_asset_read_footer(): void
    {
        // M3S.2 / M6.1 (view side): a no-read source renders no Sync now.
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere([
            'zorus_agent_state' => 'Offline', 'zorus_filtering_enabled' => false, 'zorus_cybersight_enabled' => true,
            'zorus_group_name' => 'Synthetic group', 'zorus_last_seen_at' => now()->subHours(9),
        ]))->getContent(), 'zorus');

        foreach (['Agent state' => 'Offline', 'Filtering' => 'Disabled', 'CyberSight' => 'Enabled', 'Group' => 'Synthetic group'] as $label => $value) {
            $chip = $this->chip($panel, $label);
            $this->assertSame([$value, 'snapshot', 'as of 4 hours ago'], [$chip['value'], $chip['kind'], $chip['note']], $label);
        }
        $this->assertSame('9 hours ago', $this->chip($panel, 'Last contact')['value']);
        $this->assertStringContainsString('data-attention-item="filtering"', $panel);
        $this->assertStringContainsString('data-attention-item="agent"', $panel);
        $this->assertStringContainsString('No recent activity is shown for this source here.', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        $this->assertMatchesRegularExpression('/data-footer-text>Synced 4 hours ago · [^<]* · updated by its scheduled sync; this source cannot be read for one asset</', $panel);
    }

    public function test_zorus_sync_now_never_posts_to_the_vendor(): void
    {
        // M3S.2: Zorus's only read is POST api/endpoints/search; wiring Zorus must fail here.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();

        $this->sync($asset, 'zorus')->assertJson(['outcome' => 'refused', 'reason' => 'no_single_asset_read']);
        $this->assertCount(0, $this->zorus, 'no Zorus request of any method');
        $this->assertNotContains('zorus', SourceSyncPipeline::WIRED);
    }

    public function test_every_no_read_source_panel_renders_no_sync_now(): void
    {
        // M6.1.
        $this->configureAllSources();
        $html = $this->show($this->assetLinkedEverywhere())->getContent();
        foreach (['zorus', 'm365', 'screenconnect', 'servosity'] as $source) {
            $this->assertStringNotContainsString('data-sync-now', $this->panel($html, $source), $source);
        }
        $this->assertStringContainsString('data-sync-now="controld"', $this->panel($html, 'controld'));
    }

    public function test_screenconnect_online_always_shows_its_last_event_time_beside_it(): void
    {
        // M6.2 (psa-wedk): never online/offline without the event time.
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere([
            'screenconnect_online' => false, 'screenconnect_last_seen_at' => now()->subMinutes(20),
            'screenconnect_client_version' => '24.1.0.1',
        ]))->getContent(), 'screenconnect');

        $online = $this->chip($panel, 'Online');
        $this->assertSame(['Offline', 'snapshot', 'as of 20 minutes ago'], [$online['value'], $online['kind'], $online['note']]);
        $version = $this->chip($panel, 'Client version');
        $this->assertSame(['24.1.0.1', 'none', ''], [$version['value'], $version['kind'], $version['note']]);

        // A flag with no event time is not shown as online.
        $bare = $this->panel($this->show($this->assetLinkedEverywhere([
            'screenconnect_online' => true, 'screenconnect_last_seen_at' => null,
        ]))->getContent(), 'screenconnect');
        $this->assertSame('Unknown', $this->chip($bare, 'Online')['value']);
        $this->assertStringNotContainsString('fw-semibold">Online<', $bare);
    }

    public function test_screenconnect_panel_events_attention_open_in_and_footer_never_a_button(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere([
            'screenconnect_synced_at' => now()->subDays(9), 'screenconnect_last_seen_at' => now()->subDays(9),
        ]);
        foreach (range(1, 12) as $i) {
            ScreenConnectEvent::create([
                'asset_id' => $asset->id, 'session_id' => $asset->screenconnect_session_id, 'event_type' => 'Event'.$i,
                'event_time' => now()->subDays(9)->subMinutes(20 - $i), 'host' => 'tech-'.$i, 'data' => 'synthetic note '.$i,
            ]);
        }
        $panel = $this->panel($this->show($asset)->getContent(), 'screenconnect');

        $this->assertSame(10, substr_count($panel, 'data-sc-event>'));
        $this->assertStringContainsString('Event12', $panel);
        $this->assertStringNotContainsString('>Event2<', $panel);
        $this->assertStringContainsString('data-attention-item="events"', $panel);
        $this->assertStringContainsString('No events received since 1 week ago', $panel);
        $this->assertStringContainsString('Open in ScreenConnect', $panel);
        $this->assertMatchesRegularExpression('/data-footer-text>Updated by ScreenConnect events; last event 1 week ago</', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        // The rail marker counts nothing for ScreenConnect (design-r3 §1).
        $this->assertStringNotContainsString('data-attention-marker', $this->railEntryOf($this->show($asset)->getContent(), 'screenconnect'));
    }

    private function railEntryOf(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-rail="'.$source.'"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    public function test_no_activity_log_request_until_the_dns_activity_button_is_clicked(): void
    {
        // M3S.1: rendering (and the panel's own script) makes no v2/activity-log request; the
        // block only offers the button, whose URL is the existing assets.controld.activity route.
        $this->configureAllSources();
        Setting::setValue('controld_stats_endpoint', 'synthetic-stats');
        $asset = $this->cdAsset();
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'controld');

        $this->assertCount(0, $this->controld);
        Http::assertNothingSent();
        $this->assertStringContainsString('data-dns-activity-url="'.e(route('assets.controld.activity', $asset)).'"', $panel);
        $this->assertStringContainsString('View DNS Activity', $panel);
        $this->assertStringContainsString('Not read yet.', $panel);
        // The fetch is wired to the button's click only, never to page load or panel open.
        $script = substr($html, strpos($html, "var box = document.querySelector('[data-dns-activity]')"));
        $script = substr($script, 0, strpos($script, '</script>'));
        $this->assertSame(1, substr_count($script, 'fetch('));
        $this->assertMatchesRegularExpression("/addEventListener\('click', function \(\) \{ loadDnsActivity\(/", $script);
        $this->assertSame(1, substr_count($script, 'loadDnsActivity(parseInt'));
        // Exactly the definition and the click handler call it: no call on load or on panel open.
        $this->assertSame(2, substr_count($script, 'loadDnsActivity('));
        $this->assertStringContainsString("q.action === 'blocked'", $script, 'blocked rows first');
        // Nothing on the Security tab loads it any more.
        $this->assertStringNotContainsString('loadDnsActivity', $this->security($html));
        $this->assertStringNotContainsString('dnsActivityContainer', $html);
    }

    // --- Security tab: summary rows + link forms (design-r3 §4 A) --------------------------

    public function test_security_keeps_one_deep_linked_summary_row_per_security_source(): void
    {
        // M-sum: each row's deep link to its Sources panel.
        $this->configureAllSources();
        $sec = $this->security($this->show($this->cdAsset([
            'm365_is_compliant' => false, 'm365_defender_status' => 'Disabled',
            'controld_agent_status' => 1, 'zorus_filtering_enabled' => false,
            'screenconnect_online' => true, 'screenconnect_last_seen_at' => now()->subMinutes(3),
        ]))->getContent());

        $rows = [
            'm365' => '/Intune: Not compliant\s*· Defender: Disabled\s*· <a href="#tab-sources\/m365" data-open-sources="m365">View source<\/a>/',
            'controld' => '/DNS Security: Agent Connected\s*· Control D · <a href="#tab-sources\/controld" data-open-sources="controld">View source<\/a>/',
            'zorus' => '/DNS Security: Filtering Disabled\s*· Zorus · <a href="#tab-sources\/zorus" data-open-sources="zorus">View source<\/a>/',
            'screenconnect' => '/ScreenConnect: Online, last event 3 minutes ago\s*· <a href="#tab-sources\/screenconnect" data-open-sources="screenconnect">View source<\/a>/',
        ];
        foreach ($rows as $source => $pattern) {
            $this->assertSame(1, substr_count($sec, 'data-security-summary="'.$source.'"'), $source);
            $this->assertMatchesRegularExpression($pattern, $sec, $source);
        }
        $this->assertSame(4, preg_match_all('/data-security-summary="[a-z0-9]+"/', $sec));
    }

    public function test_dns_security_summary_rows_say_status_not_reported_when_the_vendor_value_is_null(): void
    {
        // Jeeves copy ruling 6ae5b59d: "Agent Not reported..." read as if the agent were missing.
        $this->configureAllSources();
        $sec = $this->security($this->show($this->cdAsset([
            'controld_agent_status' => null, 'zorus_filtering_enabled' => null,
        ]))->getContent());

        $this->assertMatchesRegularExpression('/DNS Security: Agent status not reported by Control D\s*· Control D · <a href="#tab-sources\/controld"/', $sec);
        $this->assertMatchesRegularExpression('/DNS Security: Filtering status not reported by Zorus\s*· Zorus · <a href="#tab-sources\/zorus"/', $sec);
        $this->assertStringNotContainsString('Agent Not reported', $sec);
        $this->assertStringNotContainsString('Filtering Not reported', $sec);
    }

    public function test_the_moved_cards_leave_security_and_their_content_is_in_the_panels(): void
    {
        $this->configureAllSources();
        Setting::setValue('controld_stats_endpoint', 'synthetic-stats');
        $html = $this->show($this->cdAsset(['controld_profile_name' => 'Synthetic workstations', 'zorus_group_name' => 'Synthetic group']))->getContent();
        $sec = $this->security($html);

        foreach (['M365 / Intune', 'Last Intune Sync', 'Agent Last Seen', 'View DNS Activity', 'DNS Activity Log', 'CyberSight', 'Synthetic group',
            'Synthetic workstations', 'Open in ScreenConnect', 'Session ID', 'Recent Activity'] as $gone) {
            $this->assertStringNotContainsString($gone, $sec, $gone);
        }
        $this->assertStringContainsString('Synthetic workstations', $this->panel($html, 'controld'));
        $this->assertStringContainsString('View DNS Activity', $this->panel($html, 'controld'));
        $this->assertStringContainsString('Synthetic group', $this->panel($html, 'zorus'));
        $this->assertStringContainsString('Open in ScreenConnect', $this->panel($html, 'screenconnect'));
        // Overview's RMM "ScreenConnect" link stays.
        $overview = substr($html, strpos($html, 'id="tab-overview"'), 40000);
        $this->assertStringContainsString('bi-display me-1"></i>ScreenConnect', $overview);
    }

    public function test_link_and_unlink_forms_stay_on_security_and_never_in_sources(): void
    {
        // M3S.3.
        $this->configureAllSources();
        $linked = $this->show($this->cdAsset())->getContent();
        $this->assertStringContainsString('action="'.route('assets.controld.unlink', $this->lastAsset()).'"', $this->security($linked));
        $this->assertStringContainsString('action="'.route('assets.zorus.unlink', $this->lastAsset()).'"', $this->security($linked));

        $client = Client::factory()->create(['controld_org_id' => self::ORG.'-unlinked']);
        $unlinked = Asset::factory()->create(['client_id' => $client->id]);
        $this->controldQueue->append($this->devicesResponse([$this->device('cd-candidate-1')]));
        $page = $this->show($unlinked)->getContent();
        $this->assertStringContainsString('action="'.route('assets.controld.link', $unlinked).'"', $this->security($page));

        foreach ([$linked, $page] as $html) {
            $sources = $this->sources($html);
            foreach (['/controld/link', '/controld/unlink', '/zorus/link', '/zorus/unlink'] as $path) {
                $this->assertStringNotContainsString($path.'"', $sources, $path);
            }
            $this->assertStringNotContainsString('name="controld_device_id"', $sources);
            $this->assertStringNotContainsString('name="zorus_endpoint_id"', $sources);
        }
    }

    private function lastAsset(): Asset
    {
        return Asset::query()->latest('id')->firstOrFail();
    }

    public function test_the_empty_security_sentence_stays_true(): void
    {
        $this->configureAllSources();
        $this->assertStringNotContainsString('No security integrations linked to this asset.', $this->security($this->show($this->cdAsset())->getContent()));
        foreach (['m365_device_id', 'controld_device_id', 'zorus_endpoint_id', 'screenconnect_session_id'] as $only) {
            $asset = Asset::factory()->create([$only => 'synthetic-only-'.$only]);
            $this->assertStringNotContainsString('No security integrations linked to this asset.', $this->security($this->show($asset)->getContent()), $only);
        }
        $this->assertStringContainsString('No security integrations linked to this asset.', $this->security($this->show(Asset::factory()->create())->getContent()));
    }
}
