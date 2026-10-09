<?php

namespace Tests\Feature\Mesh;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClientIntegrationService;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use App\Support\MeshConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6ac91386 (burner mesh b6l): the #6332 residuals on the Mesh switch
 * and the stored-key states.
 *
 *  - #6335: mesh:sync-licenses is not scheduled, and logs nothing, while
 *    Mesh is switched off or has no stored key; switched on with a stored
 *    key it does not send, it logs one warning.
 *  - #6336: the decrypt warning is logged once per request or command.
 *  - #6294(a): the client integration panel keeps the Mesh card for an
 *    enabled Mesh with a stored key the PSA does not send, and says so.
 *  - #6342: switched off with an unusable key, the settings page keeps the
 *    on/off switch.
 *  - #6349: mesh:test and the settings connection test word the
 *    undecryptable and CR/LF stored keys.
 *
 * G-5: Http::preventStrayRequests(); setUp() binds Mockery doubles of
 * MeshClient and MeshWriteClient for every test, the mesh:test rows
 * included, and a double fails on any call. The connection-test rows also
 * bind a GuzzleClient factory whose client has a handler that counts each
 * send and throws; the test asserts that count is 0. Building a client is
 * allowed and not counted (testMesh() always builds one before its key
 * check). Base URL: a reserved example.test host. Synthetic data
 * (G-13): SECRET_FIXTURE keys, example.test hosts.
 */
class MeshKeyStateB6lTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> level, message */
    private array $logged = [];

    /** A row that is not a ciphertext under this APP_KEY (as after a key rotation). */
    private const UNDECRYPTABLE_ROW = 'SECRET_FIXTURE-not-a-ciphertext-6336';

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $this->app->instance(MeshWriteClient::class, Mockery::mock(MeshWriteClient::class));
        Setting::setValue('mesh_base_url', 'https://mesh-b6l.example.test');
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [$e->level, $e->message];
        });
    }

    private function storeKey(?string $key): void
    {
        $key === null
            ? Setting::setValue('mesh_api_key', self::UNDECRYPTABLE_ROW)
            : Setting::setEncrypted('mesh_api_key', $key);
    }

    private function syncFiltersPass(): bool
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'mesh:sync-licenses'));
        $this->assertCount(1, $events, 'premise: one license sync schedule');

        return $events->first()->filtersPass($this->app);
    }

    /** @return list<array{0: string, 1: string}> */
    private function loggedWith(string $prefix): array
    {
        return array_values(array_filter($this->logged, fn (array $l): bool => str_starts_with($l[1], $prefix)));
    }

    /** @return array<string, array{0: string|false, 1: string, 2: bool}> stored key (false: none, null: undecryptable), mesh_enabled, runs */
    public static function syncGates(): array
    {
        return [
            'switched off, usable key' => ['SECRET_FIXTURE-6335', '0', false],
            "switched off, '0' key" => ['0', '0', false],
            'switched on, no key (never set up)' => [false, '1', false],
            'switched off, no key' => [false, '0', false],
            'switched on, usable key' => ['SECRET_FIXTURE-6335', '1', true],
            "switched on, '0' key (runs and logs)" => ['0', '1', true],
        ];
    }

    /** #6335: the schedule filter, with mesh_enabled '0' and '1'. */
    #[DataProvider('syncGates')]
    public function test_the_sync_licenses_schedule_runs_only_when_switched_on_with_a_stored_key(string|false $key, string $enabled, bool $runs): void
    {
        Setting::setValue('mesh_enabled', $enabled);
        if ($key !== false) {
            $this->storeKey($key);
        }

        $this->assertSame($runs, $this->syncFiltersPass());
    }

    /**
     * #6335: run while Mesh is switched off, the command syncs nothing,
     * logs nothing at any level, says it is switched off and exits 0,
     * whatever key is stored.
     *
     * @return array<string, array{0: string|false|null}>
     */
    public static function offKeys(): array
    {
        return [
            'no key' => [false],
            "'0' key" => ['0'],
            'undecryptable row' => [null],
            'usable key' => ['SECRET_FIXTURE-6335'],
        ];
    }

    #[DataProvider('offKeys')]
    public function test_the_sync_licenses_command_logs_nothing_when_switched_off(string|false|null $key): void
    {
        Setting::setValue('mesh_enabled', '0');
        if ($key !== false) {
            $this->storeKey($key);
        }

        $exit = Artisan::call('mesh:sync-licenses');

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('Mesh is switched off (Settings → Integrations); no Mesh licenses were synced.'.PHP_EOL, Artisan::output());
        $this->assertSame([], $this->logged, 'nothing logged at any level');
    }

    /** #6335: switched on with a stored key the PSA does not send: exactly one log record, a warning. */
    public function test_the_sync_licenses_command_logs_one_warning_when_switched_on_with_an_unusable_key(): void
    {
        Setting::setValue('mesh_enabled', '1');
        $this->storeKey('0');
        $text = 'Mesh has a stored API key the PSA does not send (zero or true). Replace it in Settings → Integrations.';

        $this->artisan('mesh:sync-licenses')->expectsOutput($text)->assertExitCode(1);

        $this->assertSame([['warning', '[MeshSyncLicenses] '.$text.' No Mesh licenses were synced on this run.']], $this->logged);
    }

    /**
     * #6336: one undecryptable row read by every helper, then by the sync
     * command, logs the decrypt warning once; a new request scope (a
     * queue worker's forgetScopedInstances() between jobs) logs it again.
     */
    public function test_the_decrypt_warning_is_logged_once_per_request(): void
    {
        Setting::setValue('mesh_enabled', '1');
        $this->storeKey(null);
        $warning = '[MeshConfig] The stored Mesh API key could not be decrypted (Illuminate\Contracts\Encryption\DecryptException); it is treated as unusable and no Mesh call is made with it.';

        MeshConfig::get('api_key');
        MeshConfig::isConfigured();
        MeshConfig::storedKeyRefusal();
        MeshConfig::storedKeyNote();
        MeshConfig::isKeyStoredButUnusable();
        MeshConfig::notConfiguredText('missing');
        $this->artisan('mesh:sync-licenses')->assertExitCode(1);

        $this->assertSame([['warning', $warning]], $this->loggedWith('[MeshConfig]'));

        $this->app->forgetScopedInstances();
        MeshConfig::isConfigured();
        MeshConfig::storedKeyNote();

        $this->assertSame([['warning', $warning], ['warning', $warning]], $this->loggedWith('[MeshConfig]'));
    }

    private const PANEL_ZERO = 'Mesh has a stored API key the PSA does not send (zero or true). Replace it in Settings → Integrations.';

    /** @return array<string, array{0: string|null, 1: string}> stored key (null: undecryptable), the card's notice */
    public static function panelUnusable(): array
    {
        $tail = '. Replace it in Settings → Integrations.';

        return [
            "'0'" => ['0', self::PANEL_ZERO],
            'an LF' => ["SECRET_FIXTURE\n6294", 'Mesh has a stored API key the PSA does not send (it holds a character an HTTP header cannot carry)'.$tail],
            'undecryptable' => [null, 'Mesh has a stored API key the PSA does not send (it could not be decrypted)'.$tail],
        ];
    }

    /**
     * #6294(a): enabled Mesh, stored key the PSA does not send: the panel
     * data keeps the Mesh entry with that text (no vendor call: the
     * container's MeshClient double fails on any call).
     */
    #[DataProvider('panelUnusable')]
    public function test_the_panel_data_keeps_mesh_with_an_unusable_key_notice(?string $key, string $notice): void
    {
        Setting::setValue('mesh_enabled', '1');
        $this->storeKey($key);
        $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);

        $data = app(ClientIntegrationService::class)->buildIntegrationsData($client);

        $this->assertArrayHasKey('mesh', $data);
        $this->assertSame($notice, $data['mesh']['key_notice']);
        $this->assertTrue($data['mesh']['mapped']);
    }

    /**
     * #6294 controls: a usable key is listed with no notice; no key, a
     * blank key, or Mesh switched off with an unusable key leaves the card
     * out, as before. Not a row here: switched off with a usable key, the
     * card is still listed and Link is offered (configCheck is
     * isConfigured(), which does not read the switch); that is
     * pre-existing behaviour, outside this change.
     *
     * @return array<string, array{0: string|false, 1: string, 2: bool}> stored key, mesh_enabled, listed
     */
    public static function panelControls(): array
    {
        return [
            'usable key' => ['SECRET_FIXTURE-6294', '1', true],
            'no key' => [false, '1', false],
            'blank key' => ['   ', '1', false],
            "switched off, '0' key" => ['0', '0', false],
        ];
    }

    #[DataProvider('panelControls')]
    public function test_the_panel_data_controls(string|false $key, string $enabled, bool $listed): void
    {
        Setting::setValue('mesh_enabled', $enabled);
        if ($key !== false) {
            $this->storeKey($key);
        }
        $client = Client::factory()->create(['name' => 'Example Client']);

        $data = app(ClientIntegrationService::class)->buildIntegrationsData($client);

        $this->assertSame($listed, array_key_exists('mesh', $data));
        if ($listed) {
            $this->assertNull($data['mesh']['key_notice']);
        }
    }

    private function clientPage(Client $client): string
    {
        return (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('clients.show', $client))->assertOk()->getContent();
    }

    /** #6294(a): the rendered card, mapped and unmapped, says the key is unusable, exact text. */
    public function test_the_client_page_shows_the_mesh_card_saying_the_key_is_unusable(): void
    {
        Setting::setValue('mesh_enabled', '1');
        $this->storeKey('0');
        $notice = e(self::PANEL_ZERO);

        foreach ([self::TENANT, null] as $tenant) {
            $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => $tenant]);
            $html = $this->clientPage($client);

            $this->assertStringContainsString('<strong'.($tenant ? '' : ' class="text-muted"').'>Mesh (Email Security)</strong>', $html, 'the Mesh card');
            $this->assertStringContainsString('<div class="small'.($tenant ? ' mb-2' : '').'" data-integration-key-notice="mesh">'
                ."\n".str_repeat(' ', 36).'<i class="bi bi-exclamation-triangle me-1"></i>'.$notice, $html);
            $this->assertStringContainsString('Key unusable</span>', $html);
            $this->assertStringNotContainsString('integration-link-btn" data-vendor="mesh"', $html, 'no Link (a vendor read) offered');
        }
    }

    /** #6294 control: a usable, unmapped key keeps its Link button and no notice. */
    public function test_the_client_page_offers_link_for_a_usable_key(): void
    {
        Setting::setValue('mesh_enabled', '1');
        $this->storeKey('SECRET_FIXTURE-6294');

        $html = $this->clientPage(Client::factory()->create(['name' => 'Example Client']));

        $this->assertStringContainsString('integration-link-btn" data-vendor="mesh"', $html);
        $this->assertStringNotContainsString('data-integration-key-notice', $html);
    }

    /** The Mesh card on the settings page, header to the end of its body. */
    private function settingsMeshCard(): string
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))->assertOk()->getContent();
        $start = (int) strpos($html, 'Mesh Email Security');

        return substr($html, $start, (int) strpos($html, 'CIPP / Microsoft 365 Card', $start) - $start);
    }

    /**
     * #6342: switched off (or on) with an unusable stored key, the page
     * keeps the on/off switch beside the 'Key unusable' badge; a missing
     * key still shows no switch (the control).
     *
     * @return array<string, array{0: string|false|null, 1: string, 2: bool}> stored key, mesh_enabled, switch shown
     */
    public static function switchRows(): array
    {
        return [
            "switched off, '0' key" => ['0', '0', true],
            'switched off, an LF key' => ["SECRET_FIXTURE\n6342", '0', true],
            'switched off, undecryptable' => [null, '0', true],
            "switched on, '0' key" => ['0', '1', true],
            'switched off, usable key' => ['SECRET_FIXTURE-6342', '0', true],
            'switched off, no key (control)' => [false, '0', false],
        ];
    }

    #[DataProvider('switchRows')]
    public function test_the_settings_page_keeps_the_switch_for_an_unusable_key(string|false|null $key, string $enabled, bool $shown): void
    {
        Setting::setValue('mesh_enabled', $enabled);
        if ($key !== false) {
            $this->storeKey($key);
        }

        $card = $this->settingsMeshCard();

        $this->assertStringContainsString('id="mesh_api_key"', $card, 'premise: the Mesh card');
        $this->assertSame($shown, str_contains($card, 'id="mesh_enabled"'));
        if ($shown) {
            $this->assertSame($enabled === '1', str_contains($card, 'id="mesh_enabled" checked'));
        }
    }

    /**
     * #6349, #6346: mesh:test words an undecryptable row and a CR/LF key
     * as the other surfaces do, and sends nothing (it refuses before it
     * resolves a client; the container's double fails on any call).
     *
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function meshTestRows(): array
    {
        $tail = '; nothing was sent. Replace it in Settings → Integrations.';

        return [
            'undecryptable' => [null, 'Mesh has a stored API key the PSA does not send (it could not be decrypted)'.$tail],
            'a CR' => ["SECRET_FIXTURE\r6349", 'Mesh has a stored API key the PSA does not send (it holds a character an HTTP header cannot carry)'.$tail],
            'an LF' => ["SECRET_FIXTURE\n6349", 'Mesh has a stored API key the PSA does not send (it holds a character an HTTP header cannot carry)'.$tail],
        ];
    }

    #[DataProvider('meshTestRows')]
    public function test_mesh_test_words_an_undecryptable_or_cr_lf_key(?string $key, string $line): void
    {
        $this->storeKey($key);

        $exit = Artisan::call('mesh:test');

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame($line.PHP_EOL, Artisan::output());
        $this->assertStringNotContainsString('SECRET_FIXTURE', Artisan::output());
    }

    /**
     * #6349, #6346: the settings connection test words an undecryptable
     * row and a CR/LF key, logs that text once, and builds a client but
     * sends nothing (the factory's client has a handler that counts each
     * send and throws; the count must stay 0; the build is not counted).
     *
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function connectionTestRows(): array
    {
        $head = 'Mesh connection test did not run: Mesh has a stored API key the PSA does not send (';

        return [
            'undecryptable' => [null, $head.'it could not be decrypted); nothing was sent.'],
            'a CR' => ["SECRET_FIXTURE\r6349", $head.'it holds a character an HTTP header cannot carry); nothing was sent.'],
            'an LF' => ["SECRET_FIXTURE\n6349", $head.'it holds a character an HTTP header cannot carry); nothing was sent.'],
        ];
    }

    #[DataProvider('connectionTestRows')]
    public function test_the_connection_test_words_an_undecryptable_or_cr_lf_key(?string $key, string $message): void
    {
        $this->storeKey($key);
        $sent = 0;
        $this->app->bind(GuzzleClient::class, function () use (&$sent) {
            return new GuzzleClient(['handler' => function () use (&$sent) {
                $sent++;
                throw new \LogicException('nothing may be sent');
            }]);
        });

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->postJson(route('settings.integrations.mesh.test'));

        $response->assertOk()->assertExactJson(['success' => false, 'message' => $message]);
        $this->assertSame(0, $sent, 'nothing reached the handler');
        $this->assertSame([['warning', '[IntegrationsController] '.$message]], $this->loggedWith('[IntegrationsController]'));
    }
}
