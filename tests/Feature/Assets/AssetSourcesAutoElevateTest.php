<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\AssetSources;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Asset Sources S2: AutoElevate Sync now (GET /api/v1/computers/{id}), the shared vendor
 * cooldown with the hourly run, and the autoelevate_enabled switch (design-r3 §8 S2,
 * r2 M1.1-M1.10, Q-2 B, Q-5 A, Q-6 B).
 *
 * G-5: Http::fake with explicit URLs only and preventStrayRequests; no catch-all.
 * G-13: synthetic data; every uuid is visibly fake (all-zero prefix).
 */
class AssetSourcesAutoElevateTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    private const COMPANY = '00000000-0000-4000-8000-00000000c0a1';

    private const OTHER_COMPANY = '00000000-0000-4000-8000-00000000c0b2';

    private const EXAMPLE_MS = 1716900000000; // 2024-05-28T12:40:00Z, the OpenAPI example

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->configureAllSources();
    }

    private function computerId(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-0000000a%04d', $n);
    }

    /** A linked asset of a mapped client, with an older stored snapshot. */
    private function linkedAsset(?Client $client = null, array $overrides = []): Asset
    {
        $n = ++self::$seq;
        $client ??= Client::factory()->create(['name' => 'Example Dental Co.', 'autoelevate_company_id' => self::COMPANY]);

        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'DESK-EXAMPLE-'.$n,
            'autoelevate_computer_id' => $this->computerId($n),
            'autoelevate_elevation_mode' => 'live',
            'autoelevate_last_checked_in_at' => now()->subDays(2),
            'autoelevate_synced_at' => now()->subDay(),
        ], $overrides))->refresh();
    }

    /** The documented Computer schema (Partner API 1.0.0), synthetic values. */
    private function computer(string $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'machineName' => 'DESK-EXAMPLE',
            'operatingSystem' => ['name' => 'Windows 11 Pro', 'version' => '10.0.26100'],
            'locationId' => '00000000-0000-4000-8000-0000000010c1',
            'companyId' => self::COMPANY,
            'elevationMode' => 'audit',
            'lastCheckedInAt' => self::EXAMPLE_MS,
            'createdAt' => self::EXAMPLE_MS,
            'updatedAt' => self::EXAMPLE_MS,
        ], $overrides);
    }

    private function fakeOne(Asset $asset, $response): void
    {
        Http::fake([self::BASE.'/api/v1/computers/'.$asset->autoelevate_computer_id => $response]);
    }

    private function sync(Asset $asset, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->tech()->create())
            ->postJson(route('assets.sources.sync', [$asset, 'autoelevate']))->assertOk();
    }

    private function snapshot(Asset $asset): array
    {
        $asset->refresh();

        return [
            $asset->autoelevate_computer_id,
            $asset->autoelevate_elevation_mode,
            $asset->autoelevate_last_checked_in_at?->toIso8601String(),
            $asset->autoelevate_synced_at?->toIso8601String(),
        ];
    }

    private function scheduledEvent(): ScheduleEvent
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_ends_with($e->command ?? '', ' autoelevate:sync-assets'));
    }

    // --- the read and its write ------------------------------------------------------------

    public function test_ok_sync_reads_get_one_and_writes_this_assets_mode_check_in_and_synced_at_only(): void
    {
        $asset = $this->linkedAsset();
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id), 200));

        $r = $this->sync($asset)->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);
        $this->assertEqualsCanonicalizing(['autoelevate_elevation_mode', 'autoelevate_last_checked_in_at', 'autoelevate_synced_at'], $r->json('changed'));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $req) => $req->method() === 'GET'
            && $req->url() === self::BASE.'/api/v1/computers/'.$asset->autoelevate_computer_id
            && $req->hasHeader('X-Acknowledgment', 'i-understand-this-is-beta-and-may-change'));
        $linkBefore = $asset->autoelevate_computer_id;
        $asset->refresh();
        $this->assertSame($linkBefore, $asset->autoelevate_computer_id, 'Sync now never writes the link id');
        $this->assertSame('audit', $asset->autoelevate_elevation_mode);
        $this->assertSame('2024-05-28T12:40:00+00:00', $asset->autoelevate_last_checked_in_at->toIso8601String());
        $this->assertTrue($asset->autoelevate_synced_at->greaterThan(now()->subMinute()));
        $row = AssetSourceSync::sole();
        $this->assertSame(['autoelevate', 'ok', 'ok', null], [$row->source, $row->outcome, $row->reason, $row->http_status]);
    }

    public function test_sync_of_a_leaves_sibling_b_in_the_same_company_unchanged(): void
    {
        // M1.1 (adapted to GET-one): only the asked-for asset is written.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $a = $this->linkedAsset($client);
        $b = $this->linkedAsset($client);
        $before = $this->snapshot($b);
        $this->fakeOne($a, Http::response($this->computer($a->autoelevate_computer_id), 200));

        $this->sync($a)->assertJsonPath('outcome', 'ok');

        $this->assertSame($before, $this->snapshot($b));
        $this->assertSame('audit', $a->refresh()->autoelevate_elevation_mode);
    }

    public function test_single_asset_sync_does_not_change_a_siblings_link_state_or_the_client_outcome(): void
    {
        // M1.2: Sync now never calls recordClientOutcome.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $a = $this->linkedAsset($client);
        $unlinked = $this->linkedAsset($client, ['autoelevate_computer_id' => null]);
        $this->assertSame('not_synced', AutoElevateAssetSyncService::assetLinkState($unlinked)['state']);
        $this->fakeOne($a, Http::response($this->computer($a->autoelevate_computer_id), 200));

        $this->sync($a)->assertJsonPath('outcome', 'ok');

        $this->assertNull(AutoElevateAssetSyncService::lastClientOutcome($client->id));
        $this->assertSame('not_synced', AutoElevateAssetSyncService::assetLinkState($unlinked->refresh())['state']);
    }

    public function test_sync_now_does_not_fill_the_client_panels_list_cache(): void
    {
        // [FORGE CHOICE]: GET-one is a different read from the company list, so it is not cached as one.
        $asset = $this->linkedAsset();
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id), 200));

        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $this->assertNull(Cache::get('autoelevate_computers_'.self::COMPANY));
    }

    public function test_a_row_of_another_company_is_other_client_and_nothing_is_written(): void
    {
        // M-oc: the scope proof (normalizeComputer) decides this; without it the row would be written.
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id, ['companyId' => self::OTHER_COMPANY]), 200));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'other_client', 'changed' => [],
            'message' => "The vendor's device belongs to a different client; nothing was written."]);

        $this->assertSame($before, $this->snapshot($asset));
        Http::assertSentCount(1);
    }

    public function test_linked_asset_of_an_unmapped_client_is_refused_client_not_mapped_before_any_request(): void
    {
        // [FORGE CHOICE]: no company to prove the row against, so no read.
        $client = Client::factory()->create(['autoelevate_company_id' => null]);
        $asset = $this->linkedAsset($client);
        Http::fake([]);

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'client_not_mapped']);
        Http::assertNothingSent();
    }

    // --- failures leave the snapshot alone --------------------------------------------------

    public function test_404_is_not_found_at_vendor_and_the_link_is_kept(): void
    {
        // M1.7: one read is not proof of absence; the hourly sync owns unlinking.
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, Http::response('', 404));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'not_found_at_vendor',
            'message' => 'The vendor did not return this device; the link is kept.']);

        $this->assertSame($before, $this->snapshot($asset));
        $this->assertSame(404, AssetSourceSync::sole()->http_status);
    }

    public function test_other_status_is_http_nnn_with_http_status_recorded(): void
    {
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, Http::response('', 503));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'http_503', 'message' => 'The vendor answered HTTP 503.']);

        $this->assertSame($before, $this->snapshot($asset));
        $row = AssetSourceSync::sole();
        $this->assertSame(['failed', 'http_503', 503], [$row->outcome, $row->reason, $row->http_status]);
    }

    public function test_no_response_is_transport(): void
    {
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic: no response'));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'transport']);

        $this->assertSame($before, $this->snapshot($asset));
        $this->assertNull(AssetSourceSync::sole()->http_status);
    }

    public function test_a_row_that_breaks_the_documented_shape_is_drift(): void
    {
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id, ['lastCheckedInAt' => '2024-05-28T12:40:00Z']), 200));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'drift']);

        $this->assertSame($before, $this->snapshot($asset));
    }

    // --- 429, the shared cooldown and the ceiling --------------------------------------------

    public function test_a_429_returns_vendor_429_in_one_request_with_no_sleep(): void
    {
        // M1.3: exactly one attempt in the web path.
        $asset = $this->linkedAsset();
        $before = $this->snapshot($asset);
        $this->fakeOne($asset, Http::response('', 429, ['Retry-After' => '7']));

        $this->sync($asset)->assertJson(['outcome' => 'failed', 'reason' => 'vendor_429',
            'message' => 'The vendor is rate-limiting requests.']);

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertSame($before, $this->snapshot($asset));
        $row = AssetSourceSync::sole();
        $this->assertSame([429, 7], [$row->http_status, $row->retry_after_s]);
    }

    public function test_retry_after_42_yields_retry_after_s_42_and_a_cooldown_of_42(): void
    {
        // M1.4: the vendor's plain-integer Retry-After (0..300) is used.
        $asset = $this->linkedAsset();
        $this->fakeOne($asset, Http::response('', 429, ['Retry-After' => '42']));

        $this->sync($asset)->assertJsonPath('retry_after_s', 42);

        $this->assertSame(now()->getTimestamp() + 42, Cache::get(SourceSyncPipeline::vendorCooldownKey('autoelevate')));
    }

    public function test_a_retry_after_that_is_not_a_plain_integer_up_to_300_falls_back_to_60(): void
    {
        foreach (['301', 'Wed, 21 Oct 2026 07:28:00 GMT', '', '-5'] as $header) {
            Cache::flush();
            $asset = $this->linkedAsset();
            $this->fakeOne($asset, Http::response('', 429, $header === '' ? [] : ['Retry-After' => $header]));

            $this->sync($asset)->assertJsonPath('retry_after_s', 60);
        }
        $this->assertSame(300, \App\Services\AutoElevate\AutoElevateClient::cooldownSeconds('300'));
    }

    public function test_a_second_sync_now_inside_the_cooldown_sends_no_request(): void
    {
        // M1.5: the 429 writes the vendor cooldown; another asset's Sync now is refused unsent.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $a = $this->linkedAsset($client);
        $b = $this->linkedAsset($client);
        $this->fakeOne($a, Http::response('', 429, ['Retry-After' => '42']));
        $this->sync($a)->assertJsonPath('reason', 'vendor_429');

        $this->travel(2)->seconds();
        $this->sync($b)->assertJson(['outcome' => 'refused', 'reason' => 'vendor_cooling_down', 'retry_after_s' => 40]);

        Http::assertSentCount(1);
    }

    public function test_9th_interactive_autoelevate_call_in_a_minute_is_refused_without_a_request(): void
    {
        // M1.8: app-wide ceiling of 8 per minute, across users and assets.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $assets = [];
        $fakes = [];
        foreach (range(1, SourceSyncPipeline::AUTOELEVATE_PER_MINUTE + 1) as $i) {
            $asset = $this->linkedAsset($client);
            $assets[] = $asset;
            $fakes[self::BASE.'/api/v1/computers/'.$asset->autoelevate_computer_id] = Http::response($this->computer($asset->autoelevate_computer_id), 200);
        }
        Http::fake($fakes);

        foreach (array_slice($assets, 0, SourceSyncPipeline::AUTOELEVATE_PER_MINUTE) as $asset) {
            $this->sync($asset)->assertJsonPath('outcome', 'ok'); // a fresh user each time
        }
        // This PSA's own ceiling, not a vendor answer: never "the vendor asked us to slow down".
        $r = $this->sync(end($assets))->assertJson(['outcome' => 'refused', 'reason' => 'interactive_ceiling',
            'message' => "Sync now has reached this PSA's per-minute limit for this vendor; no request was sent."]);
        $this->assertGreaterThan(0, $r->json('retry_after_s'));
        $this->assertStringNotContainsString('asked us to slow down', $r->json('message'));
        $this->assertSame('interactive_ceiling', AssetSourceSync::latest('id')->first()->reason);
        $this->assertNull(Cache::get(SourceSyncPipeline::vendorCooldownKey('autoelevate')), 'no vendor cooldown was started');

        Http::assertSentCount(SourceSyncPipeline::AUTOELEVATE_PER_MINUTE);
    }

    public function test_sync_now_while_the_hourly_run_holds_its_mutex_is_run_in_progress_with_zero_requests(): void
    {
        // M1.10: the scheduler's own withoutOverlapping mutex for the scheduled event.
        $asset = $this->linkedAsset();
        Http::fake([]);
        $event = $this->scheduledEvent();
        $this->assertTrue($event->mutex->create($event), 'the scheduler takes the run mutex');

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'run_in_progress',
            'message' => 'A scheduled sync for this source is running; try again when it finishes.']);
        Http::assertNothingSent();

        // Released: the next Sync now reads.
        $event->mutex->forget($event);
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id), 200));
        $this->travel(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S + 1)->seconds();
        $this->sync($asset)->assertJsonPath('outcome', 'ok');
    }

    // --- the hourly run shares the cooldown (M1.6) ------------------------------------------

    public function test_the_hourly_command_after_an_interactive_429_skips_and_calls_no_vendor(): void
    {
        $asset = $this->linkedAsset();
        $this->fakeOne($asset, Http::response('', 429, ['Retry-After' => '42']));
        $this->sync($asset)->assertJsonPath('reason', 'vendor_429');

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('AutoElevate asked us to slow down; its cooldown ends in 42 s. Nothing was read and no asset link was changed.')
            ->assertExitCode(1);

        Http::assertSentCount(1);
        $this->assertSame($this->computerId(self::$seq), $asset->refresh()->autoelevate_computer_id);
    }

    public function test_a_429_that_ends_the_hourly_read_writes_the_cooldown_sync_now_reads(): void
    {
        $asset = $this->linkedAsset();
        Http::fake([self::BASE.'/api/v1/computers?*' => Http::response('', 429, ['Retry-After' => '30'])]);

        $this->artisan('autoelevate:sync-assets')->assertExitCode(1);

        $this->assertGreaterThan(0, AutoElevateAssetSyncService::vendorCooldownRemaining());
        $this->assertLessThanOrEqual(30, AutoElevateAssetSyncService::vendorCooldownRemaining());
        $sent = count(Http::recorded());
        $this->sync($asset)->assertJsonPath('reason', 'vendor_cooling_down');
        $this->assertCount($sent, Http::recorded(), 'Sync now sent nothing');
    }

    // --- the autoelevate_enabled switch: one test per gate ----------------------------------

    public function test_schedule_when_is_false_while_autoelevate_enabled_is_0(): void
    {
        // M1.9 (gate: schedule).
        $event = $this->scheduledEvent();
        $this->assertTrue($event->filtersPass($this->app), 'default on');

        Setting::setValue('autoelevate_enabled', '0');
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_command_refuses_while_switched_off_and_calls_no_vendor(): void
    {
        // Gate: command.
        $asset = $this->linkedAsset();
        Setting::setValue('autoelevate_enabled', '0');
        Http::fake([]);

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('AutoElevate integration is switched off')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNotNull($asset->refresh()->autoelevate_computer_id, 'nothing cleared');
    }

    public function test_client_panel_makes_no_vendor_call_while_switched_off(): void
    {
        // Gate: client panel.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        Cache::put('autoelevate_computers_'.self::COMPANY, [], 60);
        Setting::setValue('autoelevate_enabled', '0');
        Http::fake([]);

        $this->actingAs(User::factory()->tech()->create())
            ->get(route('clients.autoelevate.computers', $client))->assertOk()
            ->assertSee('data-state="switched_off"', false)
            ->assertSee('Switched off in Settings; not being updated.')
            ->assertDontSee('No computers returned.');
        Http::assertNothingSent();
    }

    public function test_sync_now_is_refused_switched_off_with_no_request_and_the_panel_shows_off(): void
    {
        // Gate: Sync now (AssetSources::isSwitchedOn) and the mockup-b OFF panel.
        $asset = $this->linkedAsset(null, ['autoelevate_elevation_mode' => 'audit']);
        Setting::setValue('autoelevate_enabled', '0');
        Http::fake([]);

        $this->sync($asset)->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        Http::assertNothingSent();

        $html = $this->actingAs(User::factory()->tech()->create())->get(route('assets.show', $asset))->assertOk()->getContent();
        $start = strpos($html, 'data-source-panel="autoelevate"');
        $panel = substr($html, $start, strpos($html, 'data-source-panel="', $start + 20) - $start);
        $this->assertStringContainsString('data-state="off"', $panel);
        $this->assertStringContainsString('Switched off in Settings; not being updated', $panel);
        $this->assertStringContainsString('last known, not updated', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
    }

    public function test_settings_switch_toggles_autoelevate_enabled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('settings.integrations'))->assertOk()
            ->assertSee('id="autoelevate_enabled"', false);

        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'autoelevate'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('success', 'AutoElevate integration disabled.');
        $this->assertSame('0', Setting::getValue('autoelevate_enabled'));

        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'autoelevate', 'enabled' => '1']);
        $this->assertSame('1', Setting::getValue('autoelevate_enabled'));
    }

    public function test_a_non_admin_can_neither_see_nor_flip_the_autoelevate_switch(): void
    {
        // Like the key, test and mapping controls, the switch is admin-only.
        $tech = User::factory()->tech()->create();
        $this->actingAs($tech)->get(route('settings.integrations'))->assertDontSee('id="autoelevate_enabled"', false);

        $this->actingAs($tech)->post(route('settings.integrations.toggle'), ['integration' => 'autoelevate'])->assertForbidden();
        $this->assertTrue(\App\Support\AutoElevateConfig::isSwitchedOn(), 'still on');
    }

    public function test_switched_off_unlinked_asset_has_no_rail_attention_count(): void
    {
        // OFF means OFF: the panel says no attention count is shown, so the rail shows none.
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $asset = $this->linkedAsset($client, ['autoelevate_computer_id' => null]);
        $entry = fn () => collect(AssetSources::rail($asset->refresh()))->firstWhere('source', 'autoelevate');
        $this->assertSame(1, $entry()['attention'], 'switched on: the link-state item is counted');

        Setting::setValue('autoelevate_enabled', '0');
        $this->assertSame([AssetSources::STATE_OFF, 0], [$entry()['state'], $entry()['attention']]);
        $this->assertSame([], AssetSources::attention($asset, 'autoelevate'));
        $this->assertStringContainsString('no attention count is shown', $this->aePanel($this->page($asset)));
    }

    // --- the panel (design-r3 §2.2 AutoElevate row; mockup a2) ------------------------------

    private function page(Asset $asset): string
    {
        Http::fake([]);
        $html = $this->actingAs(User::factory()->tech()->create())->get(route('assets.show', $asset))->assertOk()->getContent();
        Http::assertNothingSent();

        return $html;
    }

    private function aePanel(string $html): string
    {
        $start = strpos($html, 'data-source-panel="autoelevate"');
        $this->assertNotFalse($start);
        $next = strpos($html, 'data-source-panel="', $start + 20);

        return substr($html, $start, ($next === false ? strpos($html, '</script>', $start) : $next) - $start);
    }

    /** @return array<string, array{kind: string, value: string, note: string}> chip label => parts */
    private function chips(string $panel): array
    {
        preg_match_all('/data-chip-kind="([a-z_]+)">\s*<div[^>]*>([^<]*)<\/div>\s*<div[^>]*>([^<]*)<\/div>\s*<div[^>]*data-chip-note>([^<]*)</s', $panel, $m, PREG_SET_ORDER);
        $chips = [];
        foreach ($m as $c) {
            $chips[trim(html_entity_decode($c[2], ENT_QUOTES))] = ['kind' => $c[1], 'value' => trim(html_entity_decode($c[3], ENT_QUOTES)), 'note' => trim(html_entity_decode($c[4], ENT_QUOTES))];
        }

        return $chips;
    }

    public function test_audit_mode_shows_the_audit_callout_in_the_panel(): void
    {
        // M2A.1: the existing AUDIT wording, in the AutoElevate panel.
        $panel = $this->aePanel($this->page($this->linkedAsset(null, ['autoelevate_elevation_mode' => 'audit'])));

        $this->assertStringContainsString('data-audit-callout', $panel);
        $this->assertStringContainsString('elevation requests are only logged on this machine, not enforced.', $panel);
        $this->assertSame('AUDIT', $this->chips($panel)['Elevation mode']['value']);

        $live = $this->aePanel($this->page($this->linkedAsset()));
        $this->assertStringNotContainsString('data-audit-callout', $live);
    }

    public function test_last_check_in_is_labelled_autoelevate_last_check_in_never_online(): void
    {
        // M2A.2.
        $panel = $this->aePanel($this->page($this->linkedAsset()));
        $chip = $this->chips($panel)['Last contact'];

        $this->assertSame('vendor_claim', $chip['kind']);
        $this->assertStringStartsWith('AutoElevate last check-in', $chip['note']);
        $this->assertSame('2 days ago', $chip['value']);
        $this->assertDoesNotMatchRegularExpression('/\bonline\b/i', strip_tags($panel));
    }

    public function test_only_the_mode_and_check_in_chips_carry_an_as_of_age_and_the_link_chip_none(): void
    {
        // M-as: the read/sync writes mode + check-in + synced_at together; the link state has no time.
        $panel = $this->aePanel($this->page($this->linkedAsset()));
        $chips = $this->chips($panel);

        $this->assertSame(['Last synced', 'Last contact', 'Elevation mode', 'Link'], array_keys($chips));
        $this->assertMatchesRegularExpression('/^as of 1 day ago$/', $chips['Elevation mode']['note']);
        $this->assertSame('snapshot', $chips['Elevation mode']['kind']);
        $this->assertMatchesRegularExpression('/^AutoElevate last check-in, as of 1 day ago$/', $chips['Last contact']['note']);
        $this->assertSame(['kind' => 'none', 'value' => 'Linked', 'note' => ''], $chips['Link']);
    }

    public function test_after_an_ok_sync_the_mode_and_check_in_chips_are_as_of_now(): void
    {
        $asset = $this->linkedAsset();
        $this->fakeOne($asset, Http::response($this->computer($asset->autoelevate_computer_id), 200));
        $this->sync($asset)->assertJsonPath('outcome', 'ok');

        $this->travel(5)->minutes();
        $chips = $this->chips($this->aePanel($this->page($asset)));
        $this->assertSame('as of 5 minutes ago', $chips['Elevation mode']['note']);
        $this->assertSame('AutoElevate last check-in, as of 5 minutes ago', $chips['Last contact']['note']);
        $this->assertSame('', $chips['Link']['note']);
    }

    public function test_unrecognised_mode_is_shown_as_unrecognised_value(): void
    {
        $panel = $this->aePanel($this->page($this->linkedAsset(null, ['autoelevate_elevation_mode' => 'quarantine'])));

        $this->assertSame('Unrecognised: quarantine', $this->chips($panel)['Elevation mode']['value']);
        $this->assertStringContainsString('data-attention-item="mode"', $panel);
    }

    public function test_not_linked_shows_its_reason_from_asset_link_state(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY]);
        $asset = $this->linkedAsset($client, ['autoelevate_computer_id' => null]);
        AutoElevateAssetSyncService::recordClientOutcome($client->id, self::COMPANY, false, 'http_503');
        $panel = $this->aePanel($this->page($asset));

        $this->assertStringContainsString('data-state="read_failed"', $panel);
        $this->assertStringContainsString('AutoElevate read failed', $panel);
        $this->assertStringContainsString('(http_503)', $panel);
        $this->assertStringContainsString('not evidence that the machine is absent', $panel);
        $this->assertStringNotContainsString('data-sync-now', $panel);
    }

    public function test_the_panel_has_no_recent_activity_and_no_open_in(): void
    {
        $panel = $this->aePanel($this->page($this->linkedAsset()));

        $this->assertStringContainsString('No recent activity is shown for this source here.', $panel);
        $this->assertStringNotContainsString('data-open-in', $panel);
        $this->assertStringContainsString('data-sync-now="autoelevate"', $panel);
        $this->assertStringContainsString(e("Reads this computer's record from AutoElevate. Changes nothing at AutoElevate."), $panel);
    }

    // --- the card move (design-r3 §4) --------------------------------------------------------

    private function security(string $html): string
    {
        $start = strpos($html, 'id="tab-security"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'id="tab-alerts"', $start) - $start);
    }

    public function test_security_keeps_one_summary_row_only_in_audit_mode_deep_linked_to_the_panel(): void
    {
        $audit = $this->security($this->page($this->linkedAsset(null, ['autoelevate_elevation_mode' => 'audit'])));
        $this->assertStringContainsString('data-autoelevate-summary', $audit);
        $this->assertMatchesRegularExpression('/Privilege elevation:\s*<span[^>]*>AUDIT<\/span>\s*· AutoElevate ·\s*<a href="#tab-sources\/autoelevate" data-open-sources="autoelevate">View source<\/a>/', $audit);
        $this->assertStringNotContainsString('autoelevate-asset-card', $audit);
        $this->assertStringNotContainsString('No security integrations linked to this asset.', $audit);

        $live = $this->security($this->page($this->linkedAsset()));
        $this->assertStringNotContainsString('data-autoelevate-summary', $live);
        $this->assertStringNotContainsString('Privilege elevation', $live);
        // The empty-Security sentence stays true: AutoElevate is linked, so it is not shown.
        $this->assertStringNotContainsString('No security integrations linked to this asset.', $live);

        $none = $this->security($this->page(Asset::factory()->create()));
        $this->assertStringContainsString('No security integrations linked to this asset.', $none);
    }
}
