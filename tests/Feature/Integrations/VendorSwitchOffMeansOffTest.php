<?php

namespace Tests\Feature\Integrations;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\Level\LevelClient;
use App\Services\Level\LevelClientException;
use App\Services\Ninja\NinjaClient;
use App\Services\Ninja\NinjaClientException;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Card YouDehkS: switching NinjaRMM, Level, Control D or Zorus off stops every
 * asset path from calling that vendor.
 *
 * These clients use Guzzle directly, so Http::fake() cannot see them. Each
 * client is built on a handler stack whose history middleware records every
 * request that would have left the process; "zero requests" is read from that
 * history. Every switched-off assertion has a switched-on positive control on
 * the same seam, so a history that can never fill cannot pass vacuously.
 * Http::preventStrayRequests() (G-5) guards the Http facade only.
 *
 * Synthetic data only (G-13): fixture keys, .test hosts, generated ids.
 */
class VendorSwitchOffMeansOffTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<array>> vendor => Guzzle history entries */
    private array $history = ['ninja' => [], 'level' => [], 'zorus' => [], 'controld' => []];

    /** @var array<string, int> vendor => clients built through the container */
    private array $built = ['zorus' => 0, 'controld' => 0];

    /** @var list<array{0: string, 1: string}> level, message */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [$e->level, $e->message];
        });

        $this->app->instance(NinjaClient::class, new NinjaClient([
            'base_url' => 'https://ninja.test',
            'client_id' => 'fixture-client-id',
            'client_secret' => 'fixture-client-value',
            'handler' => $this->stack('ninja'),
        ], app('cache')->store()));

        $this->app->instance(LevelClient::class, new LevelClient([
            'api_key' => 'fixture-level-key',
            'base_url' => 'https://level.test',
            'handler' => $this->stack('level'),
        ]));

        // AssetController and the Zorus/Control D commands build these through
        // app()->make(..., ['config' => ...]); the binding adds the handler and
        // counts constructions.
        $this->app->bind(ZorusClient::class, function ($app, array $params) {
            $this->built['zorus']++;

            return new ZorusClient(($params['config'] ?? []) + ['handler' => $this->stack('zorus')]);
        });
        $this->app->bind(ControlDClient::class, function ($app, array $params) {
            $this->built['controld']++;

            return new ControlDClient(($params['config'] ?? []) + ['handler' => $this->stack('controld')]);
        });
    }

    /**
     * A handler that answers every request (a token for the OAuth POST, an
     * empty list otherwise) and records it in $this->history[$vendor].
     */
    private function stack(string $vendor): HandlerStack
    {
        $stack = HandlerStack::create(function (RequestInterface $request) {
            $body = str_contains((string) $request->getUri(), 'oauth/token')
                ? json_encode(['access_token' => 'fixture-access', 'expires_in' => 3600])
                : '[]';

            return new FulfilledPromise(new Response(200, ['Content-Type' => 'application/json'], $body));
        });
        $stack->push(Middleware::history($this->history[$vendor]));

        return $stack;
    }

    private function switch(string $vendor, bool $on): void
    {
        Setting::setValue("{$vendor}_enabled", $on ? '1' : '0');
    }

    private function configureAll(): void
    {
        Setting::setValue('ninja_client_id', 'fixture-client-id');
        Setting::setEncrypted('ninja_client_secret', 'fixture-client-value');
        Setting::setEncrypted('level_api_key', 'fixture-level-key');
        Setting::setEncrypted('zorus_api_key', 'fixture-zorus-key');
        Setting::setEncrypted('controld_api_key', 'fixture-controld-key');
    }

    /** @return list<string> */
    private function loggedMessagesContaining(string $needle): array
    {
        return array_values(array_map(
            fn ($r) => $r[0].': '.$r[1],
            array_filter($this->logged, fn ($r) => str_contains($r[1], $needle)),
        ));
    }

    // ---------------------------------------------------------------
    // Client choke points
    // ---------------------------------------------------------------

    public function test_ninja_client_refuses_before_the_token_post_when_switched_off(): void
    {
        $this->switch('ninja', false);

        try {
            app(NinjaClient::class)->get('/v2/organizations');
            $this->fail('NinjaClient must throw while switched off');
        } catch (NinjaClientException $e) {
            $this->assertSame('NinjaRMM integration is switched off', $e->getMessage());
        }

        $this->assertCount(0, $this->history['ninja'], 'no token POST and no API request may leave while Ninja is off');
    }

    public function test_ninja_client_refuses_even_with_a_cached_token_when_switched_off(): void
    {
        // The token leg is the cache read too: a token cached while Ninja was
        // on must not carry a request past the switch.
        app('cache')->store()->put('ninja_api_token', 'fixture-cached', 600);
        $this->switch('ninja', false);

        $this->assertFalse(app(NinjaClient::class)->isHealthy());
        $this->expectException(NinjaClientException::class);
        try {
            app(NinjaClient::class)->get('/v2/organizations');
        } finally {
            $this->assertCount(0, $this->history['ninja']);
        }
    }

    public function test_ninja_client_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->switch('ninja', true);

        app(NinjaClient::class)->get('/v2/organizations');

        $uris = array_map(fn ($t) => $t['request']->getUri()->getPath(), $this->history['ninja']);
        $this->assertSame(['/ws/oauth/token', '/v2/organizations'], $uris);
    }

    public function test_level_client_refuses_before_any_request_when_switched_off(): void
    {
        $this->switch('level', false);

        try {
            app(LevelClient::class)->getDevice('fixture-device');
            $this->fail('LevelClient must throw while switched off');
        } catch (LevelClientException $e) {
            $this->assertSame('Level integration is switched off', $e->getMessage());
        }

        $this->assertFalse(app(LevelClient::class)->isHealthy());
        $this->assertCount(0, $this->history['level']);
    }

    public function test_level_client_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->switch('level', true);

        app(LevelClient::class)->getDevice('fixture-device');

        $this->assertCount(1, $this->history['level']);
        $this->assertSame('/v2/devices/fixture-device', $this->history['level'][0]['request']->getUri()->getPath());
    }

    public function test_zorus_client_refuses_before_any_request_when_switched_off(): void
    {
        $this->switch('zorus', false);
        $client = app()->make(ZorusClient::class, ['config' => ['api_key' => 'fixture-zorus-key']]);

        try {
            $client->searchEndpoints();
            $this->fail('ZorusClient must throw while switched off');
        } catch (ZorusClientException $e) {
            $this->assertSame('Zorus integration is switched off', $e->getMessage());
        }

        $this->assertFalse($client->isHealthy());
        $this->assertCount(0, $this->history['zorus']);
    }

    public function test_zorus_client_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->switch('zorus', true);

        app()->make(ZorusClient::class, ['config' => ['api_key' => 'fixture-zorus-key']])->searchEndpoints();

        $this->assertCount(1, $this->history['zorus']);
        $this->assertSame('/api/endpoints/search', $this->history['zorus'][0]['request']->getUri()->getPath());
    }

    // ---------------------------------------------------------------
    // Asset page
    // ---------------------------------------------------------------

    private function ninjaAsset(): Asset
    {
        return Asset::factory()->create(['ninja_id' => 4242]);
    }

    public function test_asset_page_renders_without_calling_ninja_when_switched_off(): void
    {
        $this->configureAll();
        $this->switch('ninja', false);
        $asset = $this->ninjaAsset();

        $this->actingAs(User::factory()->create())->get(route('assets.show', $asset))->assertOk();

        $this->assertCount(0, $this->history['ninja']);
    }

    public function test_asset_page_positive_control_calls_ninja_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('ninja', true);
        $asset = $this->ninjaAsset();

        $this->actingAs(User::factory()->create())->get(route('assets.show', $asset))->assertOk();

        $this->assertGreaterThan(0, count($this->history['ninja']));
    }

    public function test_asset_page_does_not_log_a_ninja_fetch_failure_when_switched_off(): void
    {
        // The page-level check (not only the client's) is what keeps a
        // switched-off Ninja from logging a failed fetch on every view.
        $this->configureAll();
        $this->switch('ninja', false);

        $this->actingAs(User::factory()->create())->get(route('assets.show', $this->ninjaAsset()))->assertOk();

        $this->assertSame([], $this->loggedMessagesContaining('switched off'));
        $this->assertSame([], $this->loggedMessagesContaining('[NinjaSync]'));
    }

    public function test_asset_device_data_tab_says_ninja_is_switched_off_without_a_request(): void
    {
        $this->configureAll();
        $this->switch('ninja', false);

        $this->actingAs(User::factory()->create())
            ->getJson(route('assets.deviceData', [$this->ninjaAsset(), 'software']))
            ->assertOk()
            ->assertExactJson(['error' => 'NinjaRMM is switched off.']);

        $this->assertCount(0, $this->history['ninja']);
    }

    public function test_asset_device_data_tab_positive_control_calls_ninja_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('ninja', true);

        $this->actingAs(User::factory()->create())
            ->getJson(route('assets.deviceData', [$this->ninjaAsset(), 'software']))
            ->assertOk()
            ->assertExactJson(['software' => []]);

        $this->assertGreaterThan(0, count($this->history['ninja']));
    }

    private function controlDAsset(): Asset
    {
        $client = Client::factory()->create(['controld_org_id' => 'fixture-org']);

        return Asset::factory()->create(['client_id' => $client->id]);
    }

    private function zorusAsset(): Asset
    {
        $client = Client::factory()->create(['zorus_customer_id' => '00000000-0000-4000-8000-000000000001']);

        return Asset::factory()->create(['client_id' => $client->id]);
    }

    /** @return array<string, array{0: string}> */
    public static function dropdownVendors(): array
    {
        return ['Control D' => ['controld'], 'Zorus' => ['zorus']];
    }

    #[DataProvider('dropdownVendors')]
    public function test_asset_page_lists_no_link_candidates_without_calling_the_vendor_when_switched_off(string $vendor): void
    {
        $this->configureAll();
        $this->switch($vendor, false);
        $asset = $vendor === 'controld' ? $this->controlDAsset() : $this->zorusAsset();

        $this->actingAs(User::factory()->create())->get(route('assets.show', $asset))->assertOk();

        $this->assertSame(0, $this->built[$vendor], "no {$vendor} client may be built for the dropdown");
        $this->assertCount(0, $this->history[$vendor]);
    }

    #[DataProvider('dropdownVendors')]
    public function test_asset_page_positive_control_calls_the_vendor_for_the_dropdown_when_switched_on(string $vendor): void
    {
        $this->configureAll();
        $this->switch($vendor, true);
        $asset = $vendor === 'controld' ? $this->controlDAsset() : $this->zorusAsset();

        $this->actingAs(User::factory()->create())->get(route('assets.show', $asset))->assertOk();

        $this->assertCount(1, $this->history[$vendor]);
    }

    // ---------------------------------------------------------------
    // Link actions and the Control D activity view
    // ---------------------------------------------------------------

    public function test_link_controld_is_refused_without_calling_the_vendor_when_switched_off(): void
    {
        $this->configureAll();
        $this->switch('controld', false);
        $asset = $this->controlDAsset();

        $this->actingAs(User::factory()->create())
            ->post(route('assets.controld.link', $asset), ['controld_device_id' => 'fixture-pk'])
            ->assertRedirect(route('assets.show', $asset))
            ->assertSessionHas('error', 'Control D is switched off. Turn it on in Settings > Integrations to link a device.');

        $this->assertCount(0, $this->history['controld']);
        $this->assertNull($asset->fresh()->controld_device_id);
    }

    public function test_link_controld_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('controld', true);
        $asset = $this->controlDAsset();

        $this->actingAs(User::factory()->create())
            ->post(route('assets.controld.link', $asset), ['controld_device_id' => 'fixture-pk'])
            ->assertRedirect(route('assets.show', $asset));

        $this->assertCount(1, $this->history['controld']);
    }

    public function test_controld_activity_is_refused_when_switched_off(): void
    {
        $this->configureAll();
        Setting::setValue('controld_stats_endpoint', 'fixture');
        $this->switch('controld', false);
        $asset = $this->controlDAsset();
        $asset->update(['controld_device_id' => 'fixture-pk']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('assets.controld.activity', $asset))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Control D is switched off']);
    }

    public function test_controld_activity_positive_control_passes_the_switch_when_switched_on(): void
    {
        // With the switch on and no stats endpoint, the request gets past the
        // switch check to the next refusal: the switch is what refused above.
        $this->configureAll();
        $this->switch('controld', true);
        $asset = $this->controlDAsset();
        $asset->update(['controld_device_id' => 'fixture-pk']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('assets.controld.activity', $asset))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Control D analytics not configured']);
    }

    public function test_link_zorus_is_refused_without_calling_the_vendor_when_switched_off(): void
    {
        $this->configureAll();
        $this->switch('zorus', false);
        $asset = $this->zorusAsset();

        $this->actingAs(User::factory()->create())
            ->post(route('assets.zorus.link', $asset), ['zorus_endpoint_id' => '00000000-0000-4000-8000-0000000000aa'])
            ->assertRedirect(route('assets.show', $asset))
            ->assertSessionHas('error', 'Zorus is switched off. Turn it on in Settings > Integrations to link an endpoint.');

        $this->assertSame(0, $this->built['zorus']);
        $this->assertCount(0, $this->history['zorus']);
    }

    public function test_link_zorus_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('zorus', true);
        $asset = $this->zorusAsset();

        $this->actingAs(User::factory()->create())
            ->post(route('assets.zorus.link', $asset), ['zorus_endpoint_id' => '00000000-0000-4000-8000-0000000000aa'])
            ->assertRedirect(route('assets.show', $asset))
            ->assertSessionHas('error', 'Endpoint not found in Zorus.');

        $this->assertCount(1, $this->history['zorus']);
    }

    // ---------------------------------------------------------------
    // Sync now (nfuBmADp S6: replaces the removed Overview "Refresh from RMM")
    // ---------------------------------------------------------------

    private function syncNow(Asset $asset, string $source)
    {
        return $this->actingAs(User::factory()->create())
            ->postJson(route('assets.sources.sync', [$asset, $source]))->assertOk();
    }

    /** A stack that answers the OAuth POST with a token and any other request with $device. */
    private function deviceStack(string $vendor, array $device): HandlerStack
    {
        $stack = HandlerStack::create(function (RequestInterface $request) use ($device) {
            $body = str_contains((string) $request->getUri(), 'oauth/token')
                ? json_encode(['access_token' => 'fixture-access', 'expires_in' => 3600])
                : json_encode($device);

            return new FulfilledPromise(new Response(200, ['Content-Type' => 'application/json'], $body));
        });
        $stack->push(Middleware::history($this->history[$vendor]));

        return $stack;
    }

    /** Rebind both RMM clients to recording stacks that answer this asset's device. */
    private function bindDevices(Asset $asset): void
    {
        $this->app->instance(NinjaClient::class, new NinjaClient([
            'base_url' => 'https://ninja.test', 'client_id' => 'fixture-client-id', 'client_secret' => 'fixture-client-value',
            'handler' => $this->deviceStack('ninja', ['id' => (int) $asset->ninja_id, 'lastContact' => now()->getTimestamp()]),
        ], app('cache')->store()));
        $this->app->instance(LevelClient::class, new LevelClient([
            'api_key' => 'fixture-level-key', 'base_url' => 'https://level.test',
            'handler' => $this->deviceStack('level', ['id' => (string) $asset->level_id, 'online' => true]),
        ]));
    }

    public function test_assets_refresh_route_no_longer_exists(): void
    {
        // M4.4: the Overview "Refresh from RMM" form and its route are removed.
        $this->configureAll();
        $this->switch('ninja', true);
        $this->switch('level', true);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('assets.refresh'));
        $asset = Asset::factory()->create(['ninja_id' => 4242, 'level_id' => 'fixture-level-device']);

        $this->actingAs(User::factory()->create())->post('/assets/'.$asset->id.'/refresh')->assertNotFound();

        $this->assertCount(0, $this->history['ninja']);
        $this->assertCount(0, $this->history['level']);
    }

    public function test_sync_now_ninja_is_switched_off_with_no_request(): void
    {
        $this->configureAll();
        $this->switch('ninja', false);
        $asset = $this->ninjaAsset();

        $this->syncNow($asset, 'ninja')
            ->assertJson(['outcome' => 'refused', 'reason' => 'switched_off'])
            ->assertJsonPath('message', 'Switched off in Settings; not being updated.');

        $this->assertCount(0, $this->history['ninja']);
    }

    public function test_sync_now_level_is_switched_off_with_no_request(): void
    {
        $this->configureAll();
        $this->switch('level', false);
        $asset = Asset::factory()->create(['level_id' => 'fixture-level-device']);

        $this->syncNow($asset, 'level')
            ->assertJson(['outcome' => 'refused', 'reason' => 'switched_off'])
            ->assertJsonPath('message', 'Switched off in Settings; not being updated.');

        $this->assertCount(0, $this->history['level']);
    }

    public function test_sync_now_with_ninja_off_still_syncs_level_when_level_is_on(): void
    {
        $this->configureAll();
        $this->switch('ninja', false);
        $this->switch('level', true);
        $asset = Asset::factory()->create(['ninja_id' => 4242, 'level_id' => 'fixture-level-device']);
        $this->bindDevices($asset);

        $this->syncNow($asset, 'ninja')->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        $this->syncNow($asset, 'level')->assertJson(['outcome' => 'ok', 'reason' => 'ok']);

        $this->assertCount(0, $this->history['ninja']);
        $this->assertCount(1, $this->history['level']);
    }

    public function test_sync_now_with_level_off_still_syncs_ninja_when_ninja_is_on(): void
    {
        $this->configureAll();
        $this->switch('ninja', true);
        $this->switch('level', false);
        $asset = Asset::factory()->create(['ninja_id' => 4242, 'level_id' => 'fixture-level-device']);
        $this->bindDevices($asset);

        $this->syncNow($asset, 'ninja')->assertJson(['outcome' => 'ok', 'reason' => 'ok']);
        $this->syncNow($asset, 'level')->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);

        $this->assertGreaterThan(0, count($this->history['ninja']));
        $this->assertCount(0, $this->history['level']);
    }

    public function test_sync_now_with_both_rmms_off_refuses_both_and_makes_no_request(): void
    {
        $this->configureAll();
        $this->switch('ninja', false);
        $this->switch('level', false);
        $asset = Asset::factory()->create(['ninja_id' => 4242, 'level_id' => 'fixture-level-device']);

        $this->syncNow($asset, 'ninja')->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);
        $this->syncNow($asset, 'level')->assertJson(['outcome' => 'refused', 'reason' => 'switched_off']);

        $this->assertCount(0, $this->history['ninja']);
        $this->assertCount(0, $this->history['level']);
    }

    public function test_sync_now_positive_control_calls_both_rmms_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('ninja', true);
        $this->switch('level', true);
        $asset = Asset::factory()->create(['ninja_id' => 4242, 'level_id' => 'fixture-level-device']);
        $this->bindDevices($asset);

        $this->syncNow($asset, 'ninja')->assertJson(['outcome' => 'ok', 'reason' => 'ok']);
        $this->syncNow($asset, 'level')->assertJson(['outcome' => 'ok', 'reason' => 'ok']);

        $this->assertGreaterThan(0, count($this->history['ninja']));
        $this->assertCount(1, $this->history['level']);
    }

    // ---------------------------------------------------------------
    // Test Connection
    // ---------------------------------------------------------------

    private function guzzleHistoryForTestLevel(): array
    {
        // testLevel builds its own GuzzleClient; the container hands it the
        // recording stack, so a request it makes is visible here.
        $this->app->bind(\GuzzleHttp\Client::class, fn ($app, array $params) => new \GuzzleHttp\Client(
            ($params['config'] ?? []) + ['handler' => $this->stack('level')],
        ));

        return $this->history['level'];
    }

    public function test_test_level_reports_switched_off_without_a_request(): void
    {
        $this->configureAll();
        $this->switch('level', false);
        $this->guzzleHistoryForTestLevel();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('settings.integrations.level.test'))
            ->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'Integration is switched off. Turn it on to test the connection.']);

        $this->assertCount(0, $this->history['level']);
    }

    public function test_test_level_positive_control_makes_the_request_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('level', true);
        $this->guzzleHistoryForTestLevel();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('settings.integrations.level.test'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertCount(1, $this->history['level']);
    }

    /** @return array<string, array{0: string, 1: string}> vendor, route */
    public static function connectionTestVendors(): array
    {
        return [
            'NinjaRMM' => ['ninja', 'settings.integrations.ninja.test'],
            'Control D' => ['controld', 'settings.integrations.controld.test'],
            'Zorus' => ['zorus', 'settings.integrations.zorus.test'],
        ];
    }

    #[DataProvider('connectionTestVendors')]
    public function test_test_connection_reports_switched_off_without_a_request(string $vendor, string $route): void
    {
        $this->configureAll();
        $this->switch($vendor, false);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route($route))
            ->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'Integration is switched off. Turn it on to test the connection.']);

        $this->assertCount(0, $this->history[$vendor]);
    }

    #[DataProvider('connectionTestVendors')]
    public function test_test_connection_positive_control_does_not_report_switched_off_when_on(string $vendor, string $route): void
    {
        // testNinja and testZorus build their clients with `new`, so these
        // handlers cannot see them; testControlD builds through the container.
        // With the switch on and no credentials stored, each reaches its next
        // refusal before building a client, which makes no request: the
        // switched-off answer above therefore comes from the switch.
        $this->switch($vendor, true);

        $response = $this->actingAs(User::factory()->admin()->create())->postJson(route($route))->assertOk();

        $this->assertFalse($response->json('success'));
        $this->assertContains($response->json('message'), ['Credentials not configured.', 'API credentials not configured.']);
    }

    // ---------------------------------------------------------------
    // Other Control D callers (Jeeves 10/22: every ControlDClient caller
    // is gated while the gate is not in the client)
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> method, route, expected flash */
    public static function controlDSettingsActions(): array
    {
        $orgs = 'Control D is switched off. Turn it on in Settings > Integrations first.';

        return [
            'organization mapping page' => ['get', 'settings.controld-orgs.index', $orgs],
            'organization auto-match' => ['get', 'settings.controld-orgs.auto-match', $orgs],
            'Sync licenses button' => ['post', 'settings.integrations.controld.sync', 'Control D is switched off.'],
            'Sync devices button' => ['post', 'settings.integrations.controld.sync-devices', 'Control D is switched off.'],
        ];
    }

    #[DataProvider('controlDSettingsActions')]
    public function test_controld_settings_action_is_refused_without_building_a_client_when_switched_off(string $method, string $route, string $flash): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch('controld', false);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->{$method}(route($route))
            ->assertRedirect()
            ->assertSessionHas('error', $flash);

        $this->assertSame(0, $this->built['controld']);
        $this->assertCount(0, $this->history['controld']);
    }

    #[DataProvider('controlDSettingsActions')]
    public function test_controld_settings_action_positive_control_calls_the_vendor_when_switched_on(string $method, string $route, string $flash): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch('controld', true);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->{$method}(route($route));

        $this->assertGreaterThan(0, count($this->history['controld']));
    }

    public function test_client_page_controld_entity_list_makes_no_request_when_switched_off(): void
    {
        $this->configureAll();
        $this->switch('controld', false);
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('clients.integrations.entities', [$client, 'controld']))
            ->assertStatus(502);

        $this->assertSame(0, $this->built['controld']);
        $this->assertCount(0, $this->history['controld']);
    }

    public function test_client_page_controld_entity_list_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('controld', true);
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('clients.integrations.entities', [$client, 'controld']))
            ->assertOk();

        $this->assertCount(1, $this->history['controld']);
    }

    /** @return array<string, array{0: string, 1: array}> tool, input */
    public static function controlDTriageTools(): array
    {
        return [
            'controld_get_devices' => ['controld_get_devices', []],
            'controld_dns_queries' => ['controld_dns_queries', ['device_name' => 'HOST-0001']],
        ];
    }

    #[DataProvider('controlDTriageTools')]
    public function test_triage_controld_tool_refuses_without_a_request_when_switched_off(string $tool, array $input): void
    {
        $this->configureAll();
        Setting::setValue('controld_stats_endpoint', 'fixture');
        $this->switch('controld', false);
        $client = Client::factory()->create(['controld_org_id' => 'fixture-org']);
        $ticket = \App\Models\Ticket::factory()->create(['client_id' => $client->id]);

        $result = (new \App\Services\Triage\TriageToolExecutor($ticket))->execute($tool, $input);

        $this->assertSame(['error' => 'Control D is switched off'], $result);
        $this->assertSame(0, $this->built['controld']);
    }

    public function test_triage_controld_get_devices_positive_control_calls_the_vendor_when_switched_on(): void
    {
        $this->configureAll();
        $this->switch('controld', true);
        $client = Client::factory()->create(['controld_org_id' => 'fixture-org']);
        $ticket = \App\Models\Ticket::factory()->create(['client_id' => $client->id]);

        (new \App\Services\Triage\TriageToolExecutor($ticket))->execute('controld_get_devices', []);

        $this->assertCount(1, $this->history['controld']);
    }

    public function test_triage_controld_dns_queries_positive_control_passes_the_switch_when_switched_on(): void
    {
        // ControlDAnalyticsClient is built with `new` (no seam), so this
        // control must stop before it: with the switch on and a client with no
        // Control D org, the tool reaches its next refusal, which makes no
        // request. The refusal above therefore came from the switch.
        $this->configureAll();
        Setting::setValue('controld_stats_endpoint', 'fixture');
        $this->switch('controld', true);
        $ticket = \App\Models\Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        $result = (new \App\Services\Triage\TriageToolExecutor($ticket))
            ->execute('controld_dns_queries', ['device_name' => 'HOST-0001']);

        $this->assertSame(['error' => 'Client has no Control D organization mapping'], $result);
    }

    // ---------------------------------------------------------------
    // Schedules
    // ---------------------------------------------------------------

    private function scheduleFiltersPass(string $command): bool
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_ends_with($event->command ?? '', " {$command}"));
        $this->assertCount(1, $events, "premise: one {$command} schedule");

        return $events->first()->filtersPass($this->app);
    }

    /** @return array<string, array{0: string, 1: string}> vendor, command */
    public static function scheduledCommands(): array
    {
        return [
            'level:sync-devices' => ['level', 'level:sync-devices'],
            'controld:sync-licenses' => ['controld', 'controld:sync-licenses'],
            'controld:sync-devices' => ['controld', 'controld:sync-devices'],
            'zorus:sync-licenses' => ['zorus', 'zorus:sync-licenses'],
            'zorus:sync-devices' => ['zorus', 'zorus:sync-devices'],
        ];
    }

    private function mapEveryVendor(): void
    {
        Client::factory()->create([
            'controld_org_id' => 'fixture-org',
            'zorus_customer_id' => '00000000-0000-4000-8000-000000000001',
            'level_group_id' => 'fixture-group',
        ]);
    }

    #[DataProvider('scheduledCommands')]
    public function test_schedule_skips_while_switched_off(string $vendor, string $command): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch($vendor, false);

        $this->assertFalse($this->scheduleFiltersPass($command));
    }

    #[DataProvider('scheduledCommands')]
    public function test_schedule_positive_control_runs_while_switched_on(string $vendor, string $command): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch($vendor, true);

        $this->assertTrue($this->scheduleFiltersPass($command));
    }

    // ---------------------------------------------------------------
    // Commands (run by hand, or by a schedule that passed)
    // ---------------------------------------------------------------

    #[DataProvider('scheduledCommands')]
    public function test_command_skips_with_a_warning_and_no_request_while_switched_off(string $vendor, string $command): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch($vendor, false);
        $label = ['level' => 'Level', 'controld' => 'Control D', 'zorus' => 'Zorus'][$vendor];

        $this->artisan($command)
            ->expectsOutput("{$label} integration is switched off (set {$vendor}_enabled=1 to re-enable). Skipping.")
            ->assertExitCode(0);

        $this->assertCount(0, $this->history[$vendor]);
        $this->assertSame(0, $this->built[$vendor] ?? 0);
    }

    #[DataProvider('scheduledCommands')]
    public function test_command_positive_control_calls_the_vendor_while_switched_on(string $vendor, string $command): void
    {
        $this->configureAll();
        $this->mapEveryVendor();
        $this->switch($vendor, true);

        $this->artisan($command)
            ->doesntExpectOutputToContain('switched off')
            ->run();

        $this->assertGreaterThan(0, count($this->history[$vendor]), "{$command} must reach the vendor while switched on");
    }
}
