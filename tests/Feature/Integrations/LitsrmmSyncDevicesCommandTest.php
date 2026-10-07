<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\SyncResult;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * `litsrmm:sync-devices`: the operator's handle on LitsrmmAssetSyncService.
 * The sync itself is covered by LitsrmmAssetSyncTest; this pins what the
 * command says and the exit status a scheduler or a person will act on. Most
 * tests mock the sync; the #5588 ones run the real sync over a fake
 * transport, so a refusal or drift is shown reaching the exit code.
 */
class LitsrmmSyncDevicesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('litsrmm_api_key', 'fake-'.bin2hex(random_bytes(8)));
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private function mapClient(): Client
    {
        return Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'litsrmm_client_id' => '2f12dedb-b4cf-48cf-86bb-9f63b5b8f7af',
        ]);
    }

    private function syncReturns(SyncResult $result): void
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn($result);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);
    }

    public function test_a_clean_run_reports_its_counts_and_succeeds(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->created = 3;
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('3 created')
            ->assertSuccessful();
    }

    public function test_skipped_devices_are_listed_but_do_not_fail_the_run(): void
    {
        // A refused guess is a correct outcome an operator must hear about,
        // not a failure: failing on it would teach people to ignore the exit code.
        $this->mapClient();
        $result = new SyncResult;
        $result->recordSkipped('SHARED: more than one asset or device could be this machine; not linked, not created');
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('SHARED: more than one asset')
            ->assertSuccessful();
    }

    public function test_an_error_fails_the_run(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->recordError('Failed to read LITSRMM devices: boom');
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('Failed to read LITSRMM devices: boom')
            ->assertFailed();
    }

    public function test_no_mapped_client_is_said_plainly_and_nothing_runs(): void
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('No clients are mapped to LITSRMM')
            ->assertSuccessful();
    }

    public function test_a_switched_off_integration_refuses_and_says_why(): void
    {
        $this->mapClient();
        Setting::setValue('litsrmm_enabled', '0');
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('switched off')
            ->assertFailed();
    }

    public function test_assets_marked_inactive_are_reported_apart_from_released_links(): void
    {
        // #5361: a retired device's asset leaves the billed list; the operator
        // reading a supervised run must see how many, not a merged count.
        $this->mapClient();
        $result = new SyncResult;
        $result->deactivated = 2;
        $result->details['retired'] = 6;
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('2 deactivated')
            ->expectsOutputToContain('Marked inactive (device retired): 6')
            ->assertSuccessful();
    }
    // ---- #5577: --accept-short-read ----

    public function test_accept_short_read_passes_only_the_named_client_ids_to_this_run(): void
    {
        $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()
            ->withArgs(fn ($only, $accept) => $only === null && $accept === ['12', '34'])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ['12', '34']])
            ->expectsOutputToContain('Accepting a short read for this run only, for PSA client ID(s): 12, 34')
            ->assertSuccessful();
    }

    public function test_accept_short_read_reaches_a_single_client_run_too(): void
    {
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()
            ->withArgs(fn ($only, $accept) => $only?->id === $client->id && $accept === [(string) $client->id])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id, '--accept-short-read' => [(string) $client->id]])
            ->assertSuccessful();
    }

    public function test_without_the_option_nothing_is_accepted(): void
    {
        $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()
            ->withArgs(fn ($only, $accept) => $accept === [])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices')->assertSuccessful();
    }

    public function test_accept_short_read_refuses_anything_but_a_psa_client_id(): void
    {
        $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ['all']])
            ->expectsOutputToContain('takes PSA client IDs')
            ->assertFailed();
    }

    // ---- #5588: refusal and drift reach the exit code, through the real sync ----

    /** The real service over a fake transport: $rows on the list, $detail for every detail read. */
    private function realSync(array $rows, array|string $detail = []): void
    {
        $handler = function (RequestInterface $request) use ($rows, $detail) {
            $path = $request->getUri()->getPath();
            $json = fn (array $body) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));

            if ($path === '/v1/devices') {
                return $json(['devices' => $rows, 'nextCursor' => null]);
            }

            $row = collect($rows)->firstWhere('id', substr($path, strlen('/v1/devices/')));

            return $json(array_merge($row, ['inventory' => $detail]));
        };

        $this->app->instance(LitsrmmAssetSyncService::class, new LitsrmmAssetSyncService(new LitsrmmClient([
            'api_key' => 'fake-'.bin2hex(random_bytes(8)),
            'base_url' => 'https://litsrmm.test',
            'handler' => HandlerStack::create($handler),
            'request_timeout' => 5,
        ])));
    }

    private static function row(int $n): array
    {
        return [
            'id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', $n), 'clientId' => '2f12dedb-b4cf-48cf-86bb-9f63b5b8f7af',
            'clientName' => 'Leif IT Solutions', 'hostname' => "WORKSTATION-{$n}", 'serial' => "SN{$n}REAL0",
            'osName' => 'Win 11 Pro', 'osVersion' => 'Microsoft Windows NT 10.0.26200.0', 'osBuild' => '26200',
            'agentVersion' => '1.14.0+92a5b34', 'lastUser' => 'exampleuser', 'firstSeen' => '2026-09-22T03:47:40Z',
            'lastSeen' => '2026-09-30T10:00:00.123Z', 'enrollmentState' => 'enrolled', 'availabilityState' => 'online', 'retiredAt' => null,
        ];
    }

    public function test_a_refused_short_read_fails_the_command(): void
    {
        $client = $this->mapClient();
        Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain("client {$client->id}: refused as a short read (rule a")
            ->assertFailed();
    }

    public function test_accepting_the_refused_client_lets_the_command_succeed(): void
    {
        $client = $this->mapClient();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [(string) $client->id]])
            ->expectsOutputToContain('Short read accepted by --accept-short-read: 1 client(s)')
            ->assertSuccessful();
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    public function test_drift_fails_the_command(): void
    {
        $client = $this->mapClient();
        $this->realSync([self::row(1)], ['disks' => 'nonsense']);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain("client {$client->id}: a device's detail read sent 1 inventory category")
            ->assertFailed();
    }

    public function test_a_single_client_run_names_the_client_by_its_psa_id(): void
    {
        $client = $this->mapClient();
        $client->update(['name' => 'Fixture Client Name']);
        $this->syncReturns(new SyncResult);

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id])
            ->expectsOutputToContain("Syncing LITSRMM devices for client ID {$client->id}...")
            ->doesntExpectOutputToContain('Fixture Client Name')
            ->assertSuccessful();
    }
}
