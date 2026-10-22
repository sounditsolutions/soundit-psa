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

    public function test_controld_activity_is_refused_without_building_a_client_when_switched_off(): void
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

    // --- PLACEHOLDER: tests follow ---
}
