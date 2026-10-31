<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\AssetSources;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S5: the LITSRMM panel (design-r3 §2.2) and its Sync now, which is
 * LitsrmmAssetSyncService::syncDeviceDetail: ONE GET /v1/devices/{id} through the
 * existing LitsrmmClient::getDevice, under the run lock, writing this asset only.
 *
 * G-5: the Http facade refuses every request (no catch-all), and LitsrmmClient (Guzzle)
 * is bound to a scripted MockHandler whose history counts every request.
 * G-13: synthetic data only; ids are obviously fake.
 */
class AssetSourcesLitsrmmPanelTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    private const LS_CLIENT = '00000000-0000-4000-8000-00000000c1e7';

    private const OTHER_LS_CLIENT = '00000000-0000-4000-8000-00000000c2e7';

    private \ArrayObject $requests;

    private MockHandler $queue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->requests = new \ArrayObject;
        $this->queue = new MockHandler([]);
        $stack = HandlerStack::create($this->queue);
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(LitsrmmClient::class, new LitsrmmClient([
            'api_key' => 'synthetic-litsrmm-key', 'base_url' => 'https://litsrmm.example.test', 'handler' => $stack,
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

    private function sync(Asset $asset, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->tech()->create())
            ->postJson(route('assets.sources.sync', [$asset, 'litsrmm']))->assertOk();
    }

    /** A LITSRMM-linked asset whose client is mapped to LS_CLIENT. */
    private function linked(array $overrides = []): Asset
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere($overrides);
        $asset->client->update(['litsrmm_client_id' => self::LS_CLIENT]);

        return $asset->refresh();
    }

    /** A synthetic GET /v1/devices/{id} body in the captured row shape plus its inventory. */
    private function device(Asset $asset, array $overrides = []): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'id' => $asset->litsrmm_device_id,
            'clientId' => self::LS_CLIENT,
            'clientName' => 'Example Dental Co.',
            'hostname' => 'DESK-EXAMPLE-02',
            'serial' => 'SYNTH-SERIAL-0001',
            'osName' => 'Win 11 Pro',
            'osVersion' => 'Microsoft Windows NT 10.0.22631.0',
            'osBuild' => '22631',
            'agentVersion' => '1.9.0+synthetic',
            'lastUser' => 'exampleuser',
            'firstSeen' => '2026-09-22T03:47:40Z',
            'lastSeen' => '2026-10-31T09:00:00Z',
            'enrollmentState' => 'enrolled',
            'availabilityState' => 'online',
            'retiredAt' => null,
            'inventory' => [
                'hardware' => ['collectedAt' => '2026-10-31T09:00:00Z', 'payload' => ['cpu' => 'Example CPU 8-core', 'ramBytes' => 17179869184]],
                'system' => ['collectedAt' => '2026-10-31T09:00:00Z', 'payload' => ['bootTimeUtc' => '2026-10-30T07:00:00Z', 'pendingReboot' => true]],
            ],
        ], $overrides)));
    }

    public function test_litsrmm_health_strip_shows_online_last_seen_last_user_os_and_last_boot(): void
    {
        // design-r3 §2.2 LITSRMM row, in the fixed template order.
        $asset = $this->linked(['rmm_online' => false, 'last_user' => 'exampleuser', 'os' => 'Win 11 Pro (build 22631)',
            'last_seen_at' => now()->subMinutes(7), 'last_boot_at' => now()->subDay()]);
        $chips = $this->chips($this->panel($this->show($asset)->getContent(), 'litsrmm'));

        $this->assertSame(['Last synced', 'Last contact', 'Online', 'Last user', 'OS', 'Last boot'], array_keys($chips));
        $this->assertSame('Offline', $chips['Online']['value']);
        $this->assertSame('7 minutes ago', $chips['Last contact']['value']);
        $this->assertSame('exampleuser', $chips['Last user']['value']);
        $this->assertSame('Win 11 Pro (build 22631)', $chips['OS']['value']);
        $this->assertSame($asset->last_boot_at->toAppTz()->format('M j, Y g:i A T'), $chips['Last boot']['value']);

        // Null shared columns say so, never blank and never a guess.
        $empty = $this->linked(['rmm_online' => null, 'last_user' => null, 'os' => null, 'last_boot_at' => null, 'last_seen_at' => null]);
        $chips = $this->chips($this->panel($this->show($empty)->getContent(), 'litsrmm'));
        foreach (['Online', 'Last user', 'OS', 'Last boot', 'Last contact'] as $label) {
            $this->assertSame('Not reported by LITSRMM', $chips[$label]['value'], $label);
        }
    }

    public function test_every_litsrmm_health_chip_from_a_shared_column_carries_the_inferred_wording(): void
    {
        // M5L.1 (M4.3 extended to the LITSRMM chips, Q-4 A): none states a shared value as LITSRMM fact.
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'exampleuser', 'last_boot_at' => now()->subDay(), 'last_seen_at' => now()->subMinute()]);
        $chips = $this->chips($this->panel($this->show($asset)->getContent(), 'litsrmm'));

        foreach (['Last contact', 'Online', 'Last user', 'OS', 'Last boot'] as $label) {
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], $label);
        }
        // The panel's own column (litsrmm_synced_at) is not shared: no inferred wording there.
        $this->assertStringNotContainsString(AssetSources::INFERRED_WORDING, $chips['Last synced']['note']);
    }

    public function test_no_litsrmm_health_chip_takes_as_of_litsrmm_synced_at(): void
    {
        // M-as (S1-fix): litsrmm_synced_at dates none of these shared columns. rmm_online is also
        // written by Tactical, Ninja, Level and staff edits, none of which stamps it, and os,
        // last_user, last_seen_at and last_boot_at are written by LITSRMM only when the read carried them.
        $asset = $this->linked(['rmm_online' => true, 'last_user' => 'exampleuser', 'last_boot_at' => now()->subDay()]);
        $this->assertNotNull($asset->litsrmm_synced_at, 'fixture: a LITSRMM sync is recorded');
        $chips = $this->chips($this->panel($this->show($asset)->getContent(), 'litsrmm'));

        $this->assertSame('Online', $chips['Online']['value']);
        $this->assertSame(AssetSources::INFERRED_WORDING, $chips['Online']['note']);
        foreach (['Online', 'Last user', 'OS', 'Last boot'] as $label) {
            $this->assertSame('none', $chips[$label]['kind'], $label);
            $this->assertStringNotContainsString('as of', $chips[$label]['note'], $label);
            $this->assertStringNotContainsString('ago', $chips[$label]['note'], $label);
        }
    }

    public function test_litsrmm_attention_is_reboot_needed_and_offline_with_the_inferred_evidence(): void
    {
        $asset = $this->linked(['needs_reboot' => true, 'rmm_online' => false]);
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'litsrmm');

        $this->assertMatchesRegularExpression('/data-attention-item="reboot">\s*<strong>Reboot needed<\/strong>\s*<span[^>]*>'.preg_quote(AssetSources::INFERRED_WORDING, '/').'</', $panel);
        $this->assertMatchesRegularExpression('/data-attention-item="offline">\s*<strong>Offline<\/strong>\s*<span[^>]*>'.preg_quote(AssetSources::INFERRED_WORDING, '/').'</', $panel);
        $this->assertStringContainsString('data-attention-marker="2"', substr($html, strpos($html, 'data-source-rail="litsrmm"'), 400));
    }

    public function test_litsrmm_panel_has_no_recent_activity_no_open_in_and_no_write_form(): void
    {
        // Recent activity none (its alerts carry no asset id); Open in none, by design; read-only.
        $asset = $this->linked(['needs_reboot' => true]);
        $panel = $this->panel($this->show($asset)->getContent(), 'litsrmm');

        $this->assertStringContainsString('No recent activity is shown for this source here.', $panel);
        $this->assertStringNotContainsString('data-open-in', $panel);
        $this->assertStringNotContainsString('<form', $panel);
        $this->assertSame(1, substr_count($panel, '<button'), 'Sync now is the panel\'s only button');
        $this->assertStringContainsString('data-sync-now="litsrmm"', $panel);
        $this->assertCount(0, $this->requests, 'rendering the panel reads nothing from LITSRMM');
    }

    public function test_litsrmm_panel_switched_off_has_no_button_and_labels_chips_last_known(): void
    {
        $asset = $this->linked(['last_user' => 'exampleuser']);
        Setting::setValue('litsrmm_enabled', '0');
        $panel = $this->panel($this->show($asset)->getContent(), 'litsrmm');
        $chips = $this->chips($panel);

        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
        foreach (['Online', 'Last user', 'OS', 'Last boot'] as $label) {
            $this->assertStringContainsString('last known, not updated', $chips[$label]['note'], $label);
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], $label);
        }
    }

    public function test_sync_now_reads_get_v1_devices_id_once_and_writes_this_asset_only(): void
    {
        // M2.1-adjacent success: ONE GET, this asset's list facts + hardware + synced_at; sibling untouched.
        $asset = $this->linked(['litsrmm_synced_at' => null, 'rmm_online' => false, 'last_user' => 'olduser', 'cpu' => null]);
        $asset->forceFill(['litsrmm_synced_at' => now()->subDay()])->saveQuietly();
        $sibling = $this->assetLinkedEverywhere(['client_id' => $asset->client_id, 'rmm_online' => false, 'last_user' => 'siblinguser']);
        $siblingBefore = $sibling->refresh()->getAttributes();
        $this->queue->append($this->device($asset));

        $r = $this->sync($asset)->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);

        $this->assertCount(1, $this->requests);
        $req = $this->requests[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://litsrmm.example.test/v1/devices/'.$asset->litsrmm_device_id, (string) $req->getUri());
        $asset->refresh();
        $this->assertSame('DESK-EXAMPLE-02', $asset->hostname);
        $this->assertTrue($asset->rmm_online);
        $this->assertSame('Win 11 Pro (build 22631)', $asset->os);
        $this->assertSame('exampleuser', $asset->last_user);
        $this->assertSame('2026-10-31 09:00:00', $asset->last_seen_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Example CPU 8-core', $asset->cpu);
        $this->assertEquals(16.0, $asset->ram_gb);
        $this->assertSame('2026-10-30 07:00:00', $asset->last_boot_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($asset->needs_reboot);
        $this->assertTrue($asset->litsrmm_synced_at->greaterThan(now()->subMinute()));
        foreach (['hostname', 'rmm_online', 'os', 'last_user', 'cpu', 'litsrmm_synced_at'] as $column) {
            $this->assertContains($column, $r->json('changed'), $column);
        }
        $this->assertSame($siblingBefore, $sibling->refresh()->getAttributes(), 'a sibling of the same client is untouched');
        $row = AssetSourceSync::sole();
        $this->assertSame(['litsrmm', 'ok', 'ok', null], [$row->source, $row->outcome, $row->reason, $row->http_status]);
        Http::assertNothingSent();
    }

    public function test_sync_now_keeps_write_rules_for_a_nobody_signed_in_user_and_an_os_without_build(): void
    {
        // The shared listFacts() rules: '(none)' never overwrites last_user; no build, no "(build ...)".
        $asset = $this->linked(['last_user' => 'keptuser']);
        $this->queue->append($this->device($asset, ['lastUser' => LitsrmmAssetSyncService::NOBODY_SIGNED_IN, 'osBuild' => null, 'osName' => 'Win 11 Pro']));

        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $asset->refresh();
        $this->assertSame('keptuser', $asset->last_user);
        $this->assertSame('Win 11 Pro', $asset->os);
    }

    public function test_device_of_another_litsrmm_client_is_other_client_and_writes_nothing(): void
    {
        // M2.1.
        $asset = $this->linked();
        $before = $asset->getAttributes();
        $this->queue->append($this->device($asset, ['clientId' => self::OTHER_LS_CLIENT]));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'other_client', 'changed' => []])
            ->assertJsonPath('message', "The vendor's device belongs to a different client; nothing was written.");

        $this->assertSame($before, $asset->refresh()->getAttributes());
        $this->assertCount(1, $this->requests);
    }

    public function test_sync_now_never_changes_serial_number_link_type_or_active_state(): void
    {
        // M2.2 (serial), plus the link, asset type and active columns the full run's write() may change.
        $asset = $this->linked(['serial_number' => null, 'asset_type' => null]);
        $this->queue->append($this->device($asset, ['serial' => 'SYNTH-REAL-SERIAL-9', 'osName' => 'Windows Server 2022']));

        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $asset->refresh();
        $this->assertNull($asset->serial_number);
        $this->assertNull($asset->asset_type);
        $this->assertStringStartsWith('litsrmm-synthetic-', $asset->litsrmm_device_id);
        $this->assertTrue((bool) $asset->is_active);
    }

    public function test_a_retired_device_returns_a_reason_and_leaves_is_active_and_litsrmm_retired_at_unchanged(): void
    {
        // M2.3: never retire on a retired device, and never un-retire an asset the run retired.
        $live = $this->linked();
        $this->queue->append($this->device($live, ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-10-30T00:00:00Z']));
        $this->sync($live)->assertJson(['outcome' => 'failed', 'reason' => 'retired_at_vendor', 'changed' => []])
            ->assertJsonPath('message', 'The vendor lists this device as retired; nothing was written and the link is kept.');
        $live->refresh();
        $this->assertTrue((bool) $live->is_active);
        $this->assertNull($live->litsrmm_retired_at);

        $retired = $this->linked();
        $retired->forceFill(['is_active' => false, 'litsrmm_retired_at' => now()->subDays(2)])->saveQuietly();
        $stamp = $retired->refresh()->litsrmm_retired_at->toDateTimeString();
        $synced = $retired->litsrmm_synced_at->toDateTimeString();
        $this->queue->append($this->device($retired, ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-10-29T00:00:00Z']));
        $this->sync($retired)->assertJson(['outcome' => 'failed', 'reason' => 'retired_at_vendor']);
        $retired->refresh();
        $this->assertFalse((bool) $retired->is_active);
        $this->assertSame($stamp, $retired->litsrmm_retired_at->toDateTimeString());
        $this->assertSame($synced, $retired->litsrmm_synced_at->toDateTimeString());
    }

    public function test_a_live_device_on_a_retired_asset_leaves_its_retirement_to_the_scheduled_run(): void
    {
        // M2.3: the run's write() un-retires; Sync now never does, and still refreshes the facts.
        $asset = $this->linked();
        $asset->forceFill(['is_active' => false, 'litsrmm_retired_at' => now()->subDays(2)])->saveQuietly();
        $stamp = $asset->refresh()->litsrmm_retired_at->toDateTimeString();
        $this->queue->append($this->device($asset));

        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $asset->refresh();
        $this->assertFalse((bool) $asset->is_active);
        $this->assertSame($stamp, $asset->litsrmm_retired_at->toDateTimeString());
    }

    public function test_while_the_litsrmm_asset_sync_lock_is_held_run_in_progress_with_zero_requests(): void
    {
        // M2.4: the per-client run's own lock, tested and taken by name, released after the read.
        $asset = $this->linked();
        $run = Cache::lock(LitsrmmAssetSyncService::RUN_LOCK, 3600);
        $this->assertTrue($run->get());

        // The holder may be the run (scheduled or started by hand) or another Sync now, so the
        // sentence names no kind of sync.
        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'run_in_progress'])
            ->assertJsonPath('message', 'A sync for this source is running; try again when it finishes.');
        $this->assertCount(0, $this->requests);
        $this->assertTrue($run->release(), 'the run still owns its lock');

        // Positive control: once the run is done, a read happens and the lock is free again.
        $this->queue->append($this->device($asset));
        $this->sync($asset)->assertJsonPath('outcome', 'ok');
        $this->assertCount(1, $this->requests);
        $this->assertTrue(Cache::lock(LitsrmmAssetSyncService::RUN_LOCK, 10)->get(), 'Sync now released the lock');
    }

    public function test_a_full_run_is_refused_while_sync_now_holds_the_lock(): void
    {
        // The other direction: the run and a Sync now never overlap.
        $asset = $this->linked();
        $held = Cache::lock(LitsrmmAssetSyncService::RUN_LOCK, 120);
        $this->assertTrue($held->get());

        $result = app(LitsrmmAssetSyncService::class)->sync();

        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('Another LITSRMM device sync is running', $result->errorMessages[0]);
        $this->assertCount(0, $this->requests);
        $held->release();
    }

    public function test_404_is_not_found_at_vendor_and_keeps_the_link(): void
    {
        // M2.5.
        $asset = $this->linked();
        $before = $asset->getAttributes();
        $this->queue->append(new PsrResponse(404, [], '{}'));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'not_found_at_vendor', 'changed' => []])
            ->assertJsonPath('message', 'The vendor did not return this device; the link is kept.');

        $this->assertSame($before, $asset->refresh()->getAttributes());
        $this->assertSame([404, 'not_found_at_vendor'], [AssetSourceSync::sole()->http_status, AssetSourceSync::sole()->reason]);
    }

    public function test_429_is_vendor_429_with_retry_after_and_starts_the_litsrmm_cooldown(): void
    {
        $asset = $this->linked();
        $before = $asset->getAttributes();
        $this->queue->append(new PsrResponse(429, ['Retry-After' => '42'], '{}'));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'vendor_429', 'retry_after_s' => 42, 'changed' => []])
            ->assertJsonPath('message', 'The vendor is rate-limiting requests.');

        $this->assertSame($before, $asset->refresh()->getAttributes());
        $row = AssetSourceSync::sole();
        $this->assertSame([429, 42], [$row->http_status, $row->retry_after_s]);
        $until = (int) Cache::get(SourceSyncPipeline::vendorCooldownKey('litsrmm'));
        $this->assertEqualsWithDelta(now()->getTimestamp() + 42, $until, 2);

        // The cooldown is honoured: another asset's Sync now sends no request.
        $this->sync($this->linked())->assertJson(['outcome' => 'refused', 'reason' => 'vendor_cooling_down']);
        $this->assertCount(1, $this->requests);
    }

    public function test_429_without_a_usable_retry_after_falls_back_to_60_seconds(): void
    {
        foreach (['' => null, '86400' => '86400', 'Wed, 21 Oct 2026 07:28:00 GMT' => 'Wed, 21 Oct 2026 07:28:00 GMT'] as $header) {
            Cache::forget(SourceSyncPipeline::vendorCooldownKey('litsrmm'));
            $asset = $this->linked();
            $this->queue->append(new PsrResponse(429, $header === null ? [] : ['Retry-After' => $header], '{}'));
            $this->sync($asset)->assertJson(['reason' => 'vendor_429', 'retry_after_s' => SourceSyncPipeline::RETRY_AFTER_FALLBACK_S], (string) $header);
        }
    }

    public function test_any_other_http_status_is_http_nnn_with_its_status_recorded(): void
    {
        foreach ([401, 403, 500, 503] as $status) {
            $asset = $this->linked();
            $synced = $asset->litsrmm_synced_at->toDateTimeString();
            $this->queue->append(new PsrResponse($status, [], '{}'));

            $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'http_'.$status])
                ->assertJsonPath('message', "The vendor answered HTTP {$status}.");

            $this->assertSame($synced, $asset->refresh()->litsrmm_synced_at->toDateTimeString(), (string) $status);
            $this->assertSame($status, AssetSourceSync::latest('id')->first()->http_status);
        }
    }

    public function test_no_response_is_transport_and_leaves_the_snapshot(): void
    {
        $asset = $this->linked(['rmm_online' => false]);
        $before = $asset->getAttributes();
        $this->queue->append(new ConnectException('synthetic unreachable', new PsrRequest('GET', 'v1/devices/x')));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'transport', 'changed' => []])
            ->assertJsonPath('message', 'Could not reach the vendor.');

        $this->assertSame($before, $asset->refresh()->getAttributes());
        $this->assertNull(AssetSourceSync::sole()->http_status);
    }

    public function test_shape_failures_are_drift_and_write_nothing(): void
    {
        $bodies = [
            'no inventory' => fn (Asset $a) => ['inventory' => null],
            'inventory a list' => fn (Asset $a) => ['inventory' => [1, 2]],
            'another device' => fn (Asset $a) => ['id' => 'litsrmm-synthetic-other'],
            'bad enum' => fn (Asset $a) => ['availabilityState' => 'sleeping'],
            'half retired' => fn (Asset $a) => ['enrollmentState' => 'retired'],
            'malformed category' => fn (Asset $a) => ['inventory' => ['hardware' => ['collectedAt' => null, 'payload' => ['ramBytes' => 'lots']]]],
        ];
        foreach ($bodies as $case => $body) {
            $asset = $this->linked();
            $before = $asset->getAttributes();
            $response = $this->device($asset, $body($asset));
            if ($case === 'no inventory') {
                $decoded = json_decode((string) $response->getBody(), true);
                unset($decoded['inventory']);
                $response = new PsrResponse(200, [], json_encode($decoded));
            }
            $this->queue->append($response);

            $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'drift', 'changed' => []], $case)
                ->assertJsonPath('message', 'The vendor answered in a shape this PSA does not recognise; nothing was written.');
            $this->assertSame($before, $asset->refresh()->getAttributes(), $case);
        }
        $this->assertCount(count($bodies), $this->requests);
    }

    public function test_a_device_id_that_is_not_a_plain_identifier_is_drift_with_no_request(): void
    {
        $asset = $this->linked();
        $asset->forceFill(['litsrmm_device_id' => '../clients'])->saveQuietly();

        $this->sync($asset->refresh())->assertJson(['outcome' => 'failed', 'reason' => 'drift']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_client_with_no_litsrmm_client_id_is_client_not_mapped_with_no_request(): void
    {
        // [FORGE CHOICE], as S2: a linked asset that cannot be scope-proven is refused first.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'client_not_mapped'])
            ->assertJsonPath('message', "This asset's client is not mapped to this source.");
        $this->assertCount(0, $this->requests);
        $this->assertSame('client_not_mapped', app(LitsrmmAssetSyncService::class)->syncDeviceDetail($asset)['reason']);
        $this->assertCount(0, $this->requests);
    }

    public function test_switched_off_refuses_at_the_switch_step_before_the_limiter(): void
    {
        // M2.6 (pipeline half): AssetSources::isSwitchedOn('litsrmm') reads LitsrmmConfig::isEnabled().
        $asset = $this->linked();
        Setting::setValue('litsrmm_enabled', '0');
        $user = User::factory()->tech()->create();

        foreach (range(1, SourceSyncPipeline::USER_LIMIT_PER_MINUTE + 1) as $i) {
            $this->sync($asset, $user)->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        }
        $this->assertCount(0, $this->requests);
    }

    public function test_switched_off_the_client_transport_refuses_the_read_itself(): void
    {
        // M2.6 (transport half): called past the pipeline's switch step, the read still sends nothing.
        $asset = $this->linked();
        Setting::setValue('litsrmm_enabled', '0');
        $this->queue->append($this->device($asset));
        $before = $asset->getAttributes();

        $result = app(LitsrmmAssetSyncService::class)->syncDeviceDetail($asset);

        $this->assertSame('switched_off', $result['reason']);
        $this->assertCount(0, $this->requests);
        $this->assertSame($before, $asset->refresh()->getAttributes());
        $this->assertTrue(Cache::lock(LitsrmmAssetSyncService::RUN_LOCK, 10)->get(), 'the lock is released on a refusal too');
    }

    public function test_after_an_ok_sync_now_no_litsrmm_health_chip_reads_just_now(): void
    {
        // S1-fix as-of: litsrmm_synced_at dates no health chip, even right after an ok read,
        // and a read that leaves last_user and last_boot_at unwritten never dates them.
        $asset = $this->linked(['last_user' => 'olduser', 'last_boot_at' => now()->subDays(3)]);
        $asset->forceFill(['litsrmm_synced_at' => now()->subDay()])->saveQuietly();
        $this->queue->append($this->device($asset, ['lastUser' => null, 'inventory' => []]));
        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $chips = $this->chips($this->panel($this->show($asset->refresh())->getContent(), 'litsrmm'));

        $this->assertSame('olduser', $chips['Last user']['value']);
        foreach (['Online', 'Last user', 'OS', 'Last boot'] as $label) {
            $this->assertStringNotContainsString('as of', $chips[$label]['note'], $label);
            $this->assertStringContainsString(AssetSources::INFERRED_WORDING, $chips[$label]['note'], $label);
        }
    }

    public function test_changed_lists_rmm_online_when_it_goes_from_null_to_false(): void
    {
        // A boolean column's null -> false write is a change, though both read '' as strings.
        $asset = $this->linked(['rmm_online' => null]);
        $this->assertNull($asset->rmm_online, 'fixture: online state unknown');
        $this->queue->append($this->device($asset, ['availabilityState' => 'offline']));

        $r = $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $this->assertFalse($asset->refresh()->rmm_online);
        $this->assertContains('rmm_online', $r->json('changed'));

        // Positive control: false -> false is no change.
        $this->travel(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S + 1)->seconds();
        $this->queue->append($this->device($asset, ['availabilityState' => 'offline']));
        $again = $this->sync($asset)->assertJsonPath('outcome', 'ok');
        $this->assertNotContains('rmm_online', $again->json('changed'));
        $this->assertContains('litsrmm_synced_at', $again->json('changed'));
        $this->assertCount(2, $this->requests);
    }
}
