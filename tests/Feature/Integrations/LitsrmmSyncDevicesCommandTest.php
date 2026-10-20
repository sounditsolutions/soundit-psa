<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\SyncResult;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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

        // G-5; #5785: an accepted empty read waits before its second list
        // read, through the Sleep facade, faked here.
        Http::preventStrayRequests();
        Sleep::fake();

        Setting::setEncrypted('litsrmm_api_key', 'fake-'.bin2hex(random_bytes(8)));
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private function mapClient(): Client
    {
        return Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'litsrmm_client_id' => '00000000-0000-4000-8000-0000000000a1',
        ]);
    }

    private function syncReturns(SyncResult $result): void
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn($result);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);
    }

    public function test_l9_busy_command_refuses_visibly_without_reading(): void
    {
        $this->mapClient();
        $requests = 0;
        $client = new LitsrmmClient([
            'api_key' => 'synthetic',
            'base_url' => 'https://litsrmm.test',
            'handler' => HandlerStack::create(function () use (&$requests) {
                $requests++;

                return Create::promiseFor(new Response(200, [], '{"devices":[],"nextCursor":null}'));
            }),
        ]);
        $this->app->instance(LitsrmmAssetSyncService::class, new LitsrmmAssetSyncService($client));
        $lock = \Illuminate\Support\Facades\Cache::lock('litsrmm-asset-sync', 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('litsrmm:sync-devices')
                ->expectsOutputToContain('Another LITSRMM device sync is running, or one that stopped early still holds the lock (it frees itself within an hour); nothing was read.')
                ->assertExitCode(\Symfony\Component\Console\Command\Command::FAILURE);
            $this->assertSame(0, $requests);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_l7_reports_duplicate_seats(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->details['seats_on_refused_duplicates'] = 2;
        $this->syncReturns($result);
        $this->artisan('litsrmm:sync-devices')
            ->expectsOutput('  Seats still billed on devices refused as duplicates (shared serial or ambiguous match): 2')
            ->assertSuccessful();
    }

    public function test_suspended_seats_preserve_a_nonempty_summary(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->deactivated = 1;
        $result->details['seats_suspended'] = 1;
        $this->syncReturns($result);
        $this->artisan('litsrmm:sync-devices')
            ->expectsOutput('Done: 1 deactivated')
            ->assertSuccessful();
    }

    public function test_l7_reports_suspended_seats(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->details['seats_suspended'] = 1;
        $this->syncReturns($result);
        $this->artisan('litsrmm:sync-devices')
            ->expectsOutput('  Seat rows suspended (unmapped clients): 1')
            ->assertSuccessful();
    }

    public function test_l7_retired_and_suspended_rows_do_not_report_no_asset_changes(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->details['seats_suspended'] = 1;
        $result->details['retired'] = 1;
        $this->syncReturns($result);
        $this->artisan('litsrmm:sync-devices')
            ->doesntExpectOutputToContain('no asset changes')
            ->expectsOutput('  Seat rows suspended (unmapped clients): 1')
            ->expectsOutput('  Marked inactive (device retired): 1')
            ->assertSuccessful();
    }

    // Pin: base has neither warning, so zero already remains silent.
    public function test_l7_zero_counts_have_no_warning(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->details['seats_suspended'] = 0;
        $result->details['seats_on_refused_duplicates'] = 0;
        $this->syncReturns($result);
        $this->artisan('litsrmm:sync-devices')
            ->doesntExpectOutputToContain('Seat rows suspended (unmapped clients)')
            ->doesntExpectOutputToContain('Seats still billed on devices refused as duplicates')
            ->assertSuccessful();
    }

    public function test_a_clean_run_reports_its_counts_and_succeeds(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->created = 3;
        $result->details['full_run_clients'] = 1;
        $this->syncReturns($result);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Syncing LITSRMM devices for 1 mapped client(s)...', $output);
        $this->assertStringContainsString('3 created', $output);
        $this->assertStringNotContainsString('Sweeping:', $output);
        $this->assertStringNotContainsString('Map a client on its page', $output);
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

    public function test_no_mapped_client_is_said_plainly_and_sync_is_called_for_the_sweep(): void
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $result = new SyncResult;
        $result->details['full_run_clients'] = 0;
        $service->shouldReceive('sync')->once()->with(null, [])->andReturn($result);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices')
            ->doesntExpectOutputToContain('Syncing LITSRMM devices for')
            ->expectsOutputToContain('No active clients are mapped to LITSRMM')
            ->expectsOutputToContain('Map a client on its page, under Integrations > Leif IT Solutions RMM.')
            ->expectsOutputToContain('Sweeping: clearing the LITSRMM link on every asset that is not deleted, and suspending the LITSRMM seats of unmapped clients that are not deleted.')
            ->assertSuccessful();
    }

    public function test_zero_mapped_lines_are_in_ruled_order(): void
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $result = new SyncResult;
        $result->details['full_run_clients'] = 0;
        $service->shouldReceive('sync')->once()->with(null, [])->andReturn($result);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        // #7183: every physical line is kept, blank ones included, so a blank
        // line between two of the three lines breaks the adjacency below.
        // #7184: only the first line containing the warning is located and
        // the two lines after it are read by position; no line before the
        // warning, nor the warning line itself, may contain the pointer or
        // 'Sweeping:', and the pointer line may not contain 'Sweeping:'. The
        // warning and the pointer must each be printed once; a later line that
        // contains 'Sweeping:' does not fail this test.
        $lines = explode("\n", \Illuminate\Support\Facades\Artisan::output());
        $warnIndex = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'No active clients are mapped to LITSRMM.')) {
                $warnIndex = $index;
                break;
            }
        }
        $this->assertNotNull($warnIndex, 'The zero-mapped warning must be printed.');
        foreach (array_slice($lines, 0, $warnIndex) as $line) {
            $this->assertStringNotContainsString('Map a client on its page', $line, 'The mapping pointer must not come before the warning.');
            $this->assertStringNotContainsString('Sweeping:', $line, 'Sweeping must not come before the warning.');
        }
        $this->assertStringNotContainsString('Map a client on its page', $lines[$warnIndex], 'The mapping pointer must not be on the warning line.');
        $this->assertStringNotContainsString('Sweeping:', $lines[$warnIndex], 'Sweeping must not be on the warning line.');
        $this->assertStringContainsString('Map a client on its page', $lines[$warnIndex + 1] ?? '', 'The mapping pointer must be the line right after the warning.');
        $this->assertStringNotContainsString('Sweeping:', $lines[$warnIndex + 1] ?? '', 'Sweeping must not be on the mapping pointer line.');
        $this->assertStringContainsString('Sweeping:', $lines[$warnIndex + 2] ?? '', 'Sweeping must be the line right after the mapping pointer.');
        foreach (array_slice($lines, $warnIndex + 1) as $line) {
            $this->assertStringNotContainsString('No active clients are mapped to LITSRMM.', $line, 'The warning must be printed once.');
        }
        foreach (array_slice($lines, $warnIndex + 2) as $line) {
            $this->assertStringNotContainsString('Map a client on its page', $line, 'The mapping pointer must be printed once.');
        }
    }

    public function test_no_operational_mapped_client_still_sweeps_asset_links(): void
    {
        $client = $this->mapClient();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000001',
        ]);
        $client->stage = ClientStage::Prospect;
        $client->save();
        $unmapped = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true, 'litsrmm_client_id' => null]);
        $type = LicenseType::firstOrCreate(
            ['vendor' => LitsrmmAssetSyncService::LICENSE_VENDOR, 'vendor_sku_id' => 'rmm_workstation'],
            ['name' => 'LITSRMM — Workstation', 'is_active' => true],
        );
        $seat = License::create(['license_type_id' => $type->id, 'client_id' => $unmapped->id, 'quantity' => 3, 'status' => 'active']);
        $prospectSeat = License::create(['license_type_id' => $type->id, 'client_id' => $client->id, 'quantity' => 2, 'status' => 'active']);
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices')
            ->doesntExpectOutputToContain('Syncing LITSRMM devices for')
            ->expectsOutputToContain('Map a client on its page, under Integrations > Leif IT Solutions RMM.')
            ->assertSuccessful();

        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertSame(0, $this->listReads);
        $this->assertSame(0, (int) $seat->fresh()->quantity);
        $this->assertSame('suspended', $seat->fresh()->status);
        $this->assertSame(2, (int) $prospectSeat->fresh()->quantity);
        $this->assertSame('active', $prospectSeat->fresh()->status);
    }

    public function test_zero_mapped_sweeps_no_client_and_soft_deleted_client_links(): void
    {
        $client = $this->mapClient();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000011',
        ]);
        $client->delete();
        $noClient = Asset::factory()->create([
            'client_id' => null,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000012',
        ]);
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices')->assertSuccessful();

        $this->assertNull($noClient->fresh()->litsrmm_device_id);
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    public function test_one_mapped_client_sweeps_no_client_link_and_keeps_its_own_link(): void
    {
        $client = $this->mapClient();
        $row = self::row(1);
        $row['id'] = '00000000-0000-4000-8000-000000000021';
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'litsrmm_device_id' => $row['id'],
        ]);
        $noClient = Asset::factory()->create([
            'client_id' => null,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000022',
        ]);
        $this->realSync([$row]);

        $this->artisan('litsrmm:sync-devices')->assertSuccessful();

        $this->assertNull($noClient->fresh()->litsrmm_device_id);
        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
    }

    public function test_command_lines_use_the_runs_zero_count_not_the_current_mapping(): void
    {
        $this->mapClient();
        $result = new SyncResult;
        $result->details['full_run_clients'] = 0;
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('No active clients are mapped to LITSRMM.')
            ->expectsOutputToContain('Map a client on its page, under Integrations > Leif IT Solutions RMM. A mapped client is synced only once it is converted from a prospect and marked Active.')
            ->expectsOutputToContain('Sweeping: clearing the LITSRMM link on every asset that is not deleted, and suspending the LITSRMM seats of unmapped clients that are not deleted.')
            ->doesntExpectOutputToContain('Syncing LITSRMM devices for')
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
            ->withArgs(fn ($only, $accept) => $only === null && $accept === ['12:b:1:5:4:5:1:0:d0123456789', '34:d:2:0:0:1:0:2:dabcdef0123'])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ['12:b:1:5:4:5:1:0:d0123456789', '34:d:2:0:0:1:0:2:dabcdef0123']])
            ->expectsOutputToContain('a short-read refusal is passed only if it matches exactly: 12:b:1:5:4:5:1:0:d0123456789, 34:d:2:0:0:1:0:2:dabcdef0123')
            ->doesntExpectOutputToContain('Accepting a short read')
            ->assertSuccessful();
    }

    public function test_an_accept_with_no_active_mapped_client_is_reported_unused(): void
    {
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ['12:b:1:5:4:5:1:0:d0123456789']])
            ->expectsOutputToContain('For each of PSA client ID(s) 12, an --accept-short-read code given for it passed nothing')
            ->assertSuccessful();
        $this->assertSame(0, $this->listReads);
    }

    public function test_accept_short_read_reaches_a_single_client_run_too(): void
    {
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldReceive('sync')->once()
            ->withArgs(fn ($only, $accept) => $only?->id === $client->id && $accept === ["{$client->id}:a:0:1:1:0:0:0:d0123456789"])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id, '--accept-short-read' => ["{$client->id}:a:0:1:1:0:0:0:d0123456789"]])
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
            ->expectsOutputToContain('takes the refusal code a short-read refusal printed')
            ->assertFailed();
    }

    // ---- #5588: refusal and drift reach the exit code, through the real sync ----

    /** Device list reads the fake transport answered (#5862). */
    private int $listReads = 0;

    /**
     * The real service over a fake transport: $rows on the list, $detail for
     * every detail read; $laterRows, when given, on every list read after the
     * first (#5862).
     */
    private function realSync(array $rows, array|string $detail = [], ?array $laterRows = null): void
    {
        $this->listReads = 0;
        $handler = function (RequestInterface $request) use ($rows, $detail, $laterRows) {
            $path = $request->getUri()->getPath();
            $json = fn (array $body) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));

            if ($path === '/v1/devices') {
                $this->listReads++;

                return $json(['devices' => $this->listReads > 1 && $laterRows !== null ? $laterRows : $rows, 'nextCursor' => null]);
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

    /** The code of an empty read of a client whose only link is $asset (#5785). */
    private static function emptyReadCode(Client $client, Asset $asset): string
    {
        return "{$client->id}:a:0:1:1:0:0:0:".LitsrmmAssetSyncService::deviceSetDigest([], [$asset->id]);
    }

    private static function row(int $n): array
    {
        return [
            'id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', $n), 'clientId' => '00000000-0000-4000-8000-0000000000a1',
            'clientName' => 'Leif IT Solutions', 'hostname' => "WORKSTATION-{$n}", 'serial' => "SN{$n}REAL0",
            'osName' => 'Win 11 Pro', 'osVersion' => 'Microsoft Windows NT 10.0.26200.0', 'osBuild' => '26200',
            'agentVersion' => '1.0.0+0000000', 'lastUser' => 'exampleuser', 'firstSeen' => '2026-09-22T00:00:00Z',
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

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [self::emptyReadCode($client, $asset)]])
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

    // ---- #5680 / #5688: the accept names one refusal, and an unused one is reported ----

    public function test_a_bare_client_id_is_no_longer_an_accept(): void
    {
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [(string) $client->id]])
            ->expectsOutputToContain('takes the refusal code a short-read refusal printed')
            ->assertFailed();
    }

    public function test_the_code_line_copied_from_the_output_is_accepted_as_it_is(): void
    {
        // #5786: capture what the command printed, take the line that is a
        // code, untrimmed, and feed it back.
        $client = $this->mapClient();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([]);

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        $printed = \Illuminate\Support\Facades\Artisan::output();
        $codes = array_values(array_filter(explode("\n", $printed), fn (string $line) => LitsrmmAssetSyncService::isRefusalCode($line)));
        $this->assertSame([self::emptyReadCode($client, $asset)], $codes, $printed);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices', ['--accept-short-read' => $codes]));
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    public function test_a_bare_client_id_is_told_to_paste_the_code_exactly(): void
    {
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [(string) $client->id]])
            ->expectsOutputToContain('pasted exactly; a bare client id is rejected. Nothing was read.')
            ->assertFailed();
    }

    public function test_a_code_with_a_leading_zero_is_rejected_before_anything_is_read(): void
    {
        // #5793: no refusal prints one, so it could never match.
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ["0{$client->id}:a:0:1:1:0:0:0:d0123456789"]])
            ->expectsOutputToContain('takes the refusal code a short-read refusal printed')
            ->assertFailed();
    }

    public function test_an_accept_given_when_the_list_read_fails_names_that_cause(): void
    {
        // #5788: the unused-accept explanation includes the failed list read.
        $client = $this->mapClient();
        $result = new SyncResult;
        $result->recordError('Failed to read LITSRMM devices (HTTP 503); nothing was changed');
        $result->details['short_read_accept_unused'] = [$client->id];
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ["{$client->id}:a:0:1:1:0:0:0:d0123456789"]])
            ->expectsOutputToContain('the device list read failed so no client was examined')
            ->assertFailed();
    }

    public function test_install_says_a_bare_id_is_rejected_and_where_the_code_is(): void
    {
        $install = (string) file_get_contents(base_path('docs/INSTALL.md'));

        $this->assertStringContainsString('**A bare client id is rejected; paste the code exactly.**', $install);
        // #5844: the scheduled path names the context field, not a line.
        $this->assertStringContainsString('`refusal_code` of the `[LitsrmmAssetSync] refusal code for --accept-short-read` record', $install);
        $this->assertStringNotContainsString('on the next line', $install);
        // #5843: with two or more refusals only the last code ends the flash.
        $this->assertStringContainsString('only the last refusal\'s code is the last text of the message; with two or more refused clients every other code is followed by a space and the next refusal', $install);
        $this->assertStringNotContainsString('the very last text of the message, after a space', $install);
        // #5785: the code shape line.
        $this->assertStringContainsString('`<PSA client id>:<rule>:<listed>:<linked>:<unlisted>:<seats held>:<seats read>:<retired listed>:d<10 hex>`', $install);
        // #5842: exactly half is not all skips.
        $this->assertStringContainsString('each failure other than a 404 is an error that fails the run', $install);
        $this->assertStringNotContainsString('is still skips', $install);
        // #5850: why a code passed nothing, the degraded and rolled-back cases included.
        $this->assertStringContainsString('when the client was then refused as a degraded detail read, when the device list read failed', $install);
        // #5868: a rollback prints no list; the ids are in the log record only.
        $this->assertStringContainsString('If a client\'s changes are rolled back by an error, the run stops with that error and prints no list', $install);
        // #5866: single use is per run.
        $this->assertStringContainsString('Nothing marks a code used between runs, so the same code passes again on a later run', $install);
        // #5867, #5865: no formatter-independence claim; the wait is after the client's turn.
        $this->assertStringNotContainsString('whatever the log formatter', $install);
        $this->assertStringContainsString('a log format that leaves out context shows no code', $install);
        $this->assertStringNotContainsString('a few seconds later in the same run', $install);
        $this->assertStringContainsString('made 5 seconds after that client\'s turn in the run', $install);
        // #5863, #5869, #5864: what the digest hashes.
        $this->assertStringContainsString('each marked when listed retired or half-retired, and of every linked asset the accepted run could release', $install);
        $this->assertStringNotContainsString('the linked assets it would release', $install);
        // L6 r2 (contract:2, contract:3, contract:4): the releasable set as
        // refuseShortRead() builds it, half-retired old codes, and no partial
        // list of the ids in the rollback record.
        $this->assertStringNotContainsString('any second asset carrying the same listed device id', $install);
        $this->assertStringContainsString('except the first asset by id that carries it', $install);
        $this->assertStringContainsString('for a listed retired device, every linked asset carrying its id that is inactive, first or not', $install);
        $this->assertStringContainsString('for a read listing a retired or half-retired device', $install);
        $this->assertStringNotContainsString('(that client\'s, and any client after it)', $install);
        $this->assertStringContainsString('the log record lists them', $install);
        $this->assertStringContainsString('`left_before_detail_read`', $install, '#5794: the renamed skip reason');
    }

    public function test_an_accept_outside_the_client_run_is_reported_unused(): void
    {
        $client = $this->mapClient();
        $this->realSync([self::row(1)]);

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id, '--accept-short-read' => ['71:a:0:1:1:0:0:0:d0123456789']])
            ->expectsOutputToContain('For each of PSA client ID(s) 71, an --accept-short-read code given for it passed nothing')
            ->doesntExpectOutputToContain('Short read accepted')
            ->assertSuccessful();
    }

    public function test_the_refusal_prints_the_code_the_command_takes(): void
    {
        $client = $this->mapClient();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([]);

        // #5786: the code is printed as a line of its own; that exact line,
        // fed back, is what passes the refusal.
        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain('--accept-short-read set to the code that follows, pasted exactly')
            ->expectsOutput(self::emptyReadCode($client, $asset))
            ->assertFailed();
        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [self::emptyReadCode($client, $asset)]])
            ->assertSuccessful();
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    // ---- #5785: old-format codes, the code shape ----

    public function test_an_old_format_code_is_rejected_with_the_re_run_message(): void
    {
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ["{$client->id}:b:1:5:4:5:1:0"]])
            ->expectsOutputToContain('a refusal code in the old shape, without the device-set digest that now ends it (e.g. 12:b:1:5:4:5:1:0:d0123456789). Re-run litsrmm:sync-devices without the option and paste the new code it prints. Nothing was read.')
            ->assertFailed();
    }

    public function test_a_code_with_a_wrong_length_digest_is_not_a_code(): void
    {
        // CONTROL: green at base 8dfd830c too (base rejected any 9th field);
        // the first three samples fail on their length (one short, one long,
        // one long and uppercase); the fourth, '0123456789a', is the right
        // length (11, as 'd' and ten hex) and fails only on its missing 'd'.
        // The case and non-hex mutants are killed by the right-length
        // samples below (#5855).
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        foreach (["{$client->id}:b:1:5:4:5:1:0:d012345678", "{$client->id}:b:1:5:4:5:1:0:d0123456789a", "{$client->id}:b:1:5:4:5:1:0:d0123456789A", "{$client->id}:b:1:5:4:5:1:0:0123456789a"] as $code) {
            $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [$code]])
                ->expectsOutputToContain('takes the refusal code a short-read refusal printed')
                ->assertFailed();
        }
    }

    public function test_a_right_length_digest_of_the_wrong_alphabet_is_not_a_code(): void
    {
        // #5855: eleven characters each, the length of 'd' and ten hex, so
        // only the alphabet, the case or the 'd' can reject them: uppercase
        // hex, a non-hex letter or character, and a 'd' replaced by another
        // letter; except '0123456789', ten hex with no 'd', which is also
        // one short.
        $client = $this->mapClient();
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $service->shouldNotReceive('sync');
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        foreach (['d012345678A', 'dABCDEF0123', 'd012345678g', 'd01234567_9', '0123456789', 'x0123456789'] as $digest) {
            $this->assertFalse(LitsrmmAssetSyncService::isRefusalCode("{$client->id}:b:1:5:4:5:1:0:{$digest}"), $digest);
            $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ["{$client->id}:b:1:5:4:5:1:0:{$digest}"]])
                ->expectsOutputToContain('takes the refusal code a short-read refusal printed')
                ->assertFailed();
        }
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode("{$client->id}:b:1:5:4:5:1:0:d0123456789"), 'the positive arm');
    }

    public function test_the_accept_run_of_an_empty_read_reads_the_list_twice(): void
    {
        // #5785 (ii) through the command: the second read confirms. #5862:
        // the two list reads are counted, not only the wait.
        $client = $this->mapClient();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([]);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [self::emptyReadCode($client, $asset)]])
            ->assertSuccessful();
        Sleep::assertSleptTimes(1);
        $this->assertSame(2, $this->listReads, 'the first list read and the confirming one');
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    public function test_a_second_list_read_that_lists_the_client_refuses_through_the_command(): void
    {
        // #5862: the second read differs from the first, so the command can
        // only fail if that read was really made and really checked.
        $client = $this->mapClient();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row(1)['id']]);
        $this->realSync([], [], [self::row(1)]);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [self::emptyReadCode($client, $asset)]])
            ->expectsOutputToContain("client {$client->id}: the accepted empty read (rule a) was not confirmed")
            ->assertFailed();
        $this->assertSame(2, $this->listReads);
        $this->assertNotNull($asset->fresh()->litsrmm_device_id, 'nothing released');
    }

    public function test_the_unused_accept_causes_name_no_rolled_back_client(): void
    {
        // #5868: a rollback throws out of the run before report(), so the
        // printed causes cannot include it.
        $client = $this->mapClient();
        $result = new SyncResult;
        $result->details['short_read_accept_unused'] = [$client->id];
        $this->syncReturns($result);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ["{$client->id}:a:0:1:1:0:0:0:d0123456789"]])
            ->expectsOutputToContain('it was then refused as a degraded detail read, the device list read failed so no client was examined')
            ->doesntExpectOutputToContain('rolled back')
            ->assertSuccessful();
    }

    // ---- L6 r2 (#5863, #5869): a code by the L5 formula is refused, no shim ----

    /**
     * The digest as base 8d98cf4c (L5) computed it, rebuilt from its byte
     * layout, not by calling any code: the device ids lowercased and sorted
     * as strings with no retired marks, a line '--', then the unlisted link
     * ids sorted as integers, joined by "\n"; 'd' and the first 10 hex of
     * the sha256.
     */
    private static function l5Digest(array $deviceIds, array $assetIds): string
    {
        $deviceIds = array_map('strtolower', $deviceIds);
        sort($deviceIds, SORT_STRING);
        sort($assetIds, SORT_NUMERIC);

        return 'd'.substr(hash('sha256', implode("\n", array_merge($deviceIds, ['--'], array_map('strval', $assetIds)))), 0, 10);
    }

    public static function l6HashesDifferently(): array
    {
        return [
            'a retired device' => [['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z'], 'r'],
            'a half-retired device' => [['enrollmentState' => 'enrolled', 'availabilityState' => 'retired'], 'h'],
        ];
    }

    /**
     * contract:6, diff:5: assets 1..6 link devices 1..6 and hold 6 seats.
     * The read lists device 1 live and device 2 retired or half-retired, so
     * it is refused under rule b (4 of 6 links unlisted). At L5 the code
     * for this read was the same eight fields and the L5 digest of devices
     * 1 and 2 and assets 3..6. L6 marks device 2 (' r' or ' h'), so that
     * code names a different refusal: nothing passes, nothing is released,
     * nothing retired, no seat cut.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('l6HashesDifferently')]
    public function test_a_code_by_the_l5_formula_is_refused_through_the_command(array $state, string $mark): void
    {
        $client = $this->mapClient();
        $assets = [];
        for ($n = 1; $n <= 6; $n++) {
            $assets[$n] = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => self::row($n)['id'], 'serial_number' => null]);
        }
        $type = LicenseType::firstOrCreate(
            ['vendor' => LitsrmmAssetSyncService::LICENSE_VENDOR, 'vendor_sku_id' => 'rmm_workstation'],
            ['name' => 'LITSRMM — Workstation', 'is_active' => true],
        );
        $seat = License::create(['license_type_id' => $type->id, 'client_id' => $client->id, 'vendor_ref' => $client->litsrmm_client_id, 'quantity' => 6, 'status' => 'active', 'synced_at' => now()]);
        $rows = [self::row(1), array_merge(self::row(2), $state)];
        $this->realSync($rows);

        $l5Code = "{$client->id}:b:2:6:4:6:1:1:".self::l5Digest(
            array_column($rows, 'id'),
            [$assets[3]->id, $assets[4]->id, $assets[5]->id, $assets[6]->id],
        );
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode($l5Code), 'a well-formed code, so the command reads');
        // The code L6 prints instead: the same eight fields, and the digest
        // of the L6 byte layout (device 2's line marked), built here too.
        $lines = [strtolower(self::row(1)['id']), strtolower(self::row(2)['id']).' '.$mark];
        sort($lines, SORT_STRING);
        $assetLines = array_map(fn (int $n) => (string) $assets[$n]->id, [3, 4, 5, 6]);
        $l6Code = "{$client->id}:b:2:6:4:6:1:1:d".substr(hash('sha256', implode("\n", array_merge($lines, ['--'], $assetLines))), 0, 10);
        $this->assertNotSame($l5Code, $l6Code);

        // One expectation per written line: the refusal line carries both
        // its rule and the different-refusal sentence.
        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => [$l5Code]])
            ->expectsOutputToContain('The --accept-short-read given for this client named a different refusal, so it was not passed.')
            ->expectsOutput($l6Code)
            ->expectsOutputToContain("For each of PSA client ID(s) {$client->id}, an --accept-short-read code given for it passed nothing")
            ->doesntExpectOutput($l5Code)
            ->doesntExpectOutputToContain('Short read accepted')
            ->assertFailed();

        foreach ($assets as $asset) {
            $fresh = $asset->fresh();
            $this->assertNotNull($fresh->litsrmm_device_id, 'nothing released');
            $this->assertTrue((bool) $fresh->is_active, 'nothing retired');
            $this->assertNull($fresh->litsrmm_retired_at);
        }
        $this->assertSame(6, (int) $seat->fresh()->quantity, 'no seat cut');
    }

    // ---- H5b (#7143, #7144, #7146, #7147) ----

    public function test_h5b_1_an_unlinked_asset_of_an_unmapped_client_is_not_counted_by_the_sweep(): void
    {
        // #7143: the sweep's null-or-not-mapped filter is grouped under the
        // litsrmm_device_id guard, so an asset with no link is never matched.
        $client = $this->mapClient();
        $row = self::row(1);
        Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => $row['id']]);
        $unmapped = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true, 'litsrmm_client_id' => null]);
        $unlinked = Asset::factory()->create(['client_id' => $unmapped->id, 'litsrmm_device_id' => null]);
        $noClient = Asset::factory()->create(['client_id' => null, 'litsrmm_device_id' => '00000000-0000-4000-8000-000000000031']);
        $unlinkedUpdatedAt = $unlinked->fresh()->updated_at->toDateTimeString();
        $this->travel(5)->minutes();
        $this->realSync([$row]);

        $this->artisan('litsrmm:sync-devices')
            ->expectsOutputToContain(', 1 deactivated')
            ->assertSuccessful();

        $this->assertNull($noClient->fresh()->litsrmm_device_id);
        $this->assertNull($unlinked->fresh()->litsrmm_device_id);
        // #7190: an Eloquent builder update() stamps updated_at, and the
        // clock has moved since $unlinked was written, so such an update
        // that matched $unlinked changes this value whether or not it counted it.
        $this->assertSame($unlinkedUpdatedAt, $unlinked->fresh()->updated_at->toDateTimeString(), 'The unlinked asset must not be written.');
    }

    public function test_h5b_2_a_real_run_with_one_mapped_client_says_so_and_does_not_say_sweeping(): void
    {
        // #7144: the count the command prints is the one the real sync wrote.
        $client = $this->mapClient();
        $row = self::row(1);
        Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => $row['id']]);
        $this->realSync([$row]);

        // The whole output is read, so the count line is also checked for
        // the three texts.
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Syncing LITSRMM devices for 1 mapped client(s)...', $output);
        foreach (['No active clients are mapped', 'Map a client on its page', 'Sweeping:'] as $absent) {
            $this->assertStringNotContainsString($absent, $output);
        }
    }

    public function test_h5b_3_a_full_run_result_without_the_count_prints_neither_line(): void
    {
        // #7146: the ruled "key absent: print neither" branch.
        $this->mapClient();
        $this->syncReturns(new SyncResult);

        // #7191: 'Done:' is printed by report(), so this passes only if the
        // run reached report() and its output was captured. The whole output
        // is read, so the 'Done:' line is also checked for the four texts.
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Done:', $output);
        foreach (['No active clients are mapped', 'Map a client on its page', 'Sweeping:', 'Syncing LITSRMM devices for'] as $absent) {
            $this->assertStringNotContainsString($absent, $output);
        }
    }

    public function test_h5b_4_a_failed_list_read_still_prints_the_mapped_count(): void
    {
        // #7147: full_run_clients is written before the list read, so a run
        // whose list read fails still says how many clients it would sync.
        $this->mapClient();
        $handler = fn (RequestInterface $request) => Create::promiseFor(new Response(503, ['Content-Type' => 'application/json'], '{}'));
        $this->app->instance(LitsrmmAssetSyncService::class, new LitsrmmAssetSyncService(new LitsrmmClient([
            'api_key' => 'fake-'.bin2hex(random_bytes(8)),
            'base_url' => 'https://litsrmm.test',
            'handler' => HandlerStack::create($handler),
            'request_timeout' => 5,
        ])));

        // #7188: expectsOutputToContain() does not check order, so the output
        // is captured and the two positions compared.
        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('litsrmm:sync-devices'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $countAt = strpos($output, 'Syncing LITSRMM devices for 1 mapped client(s)...');
        $failureAt = strpos($output, 'Failed to read LITSRMM devices (HTTP 503); nothing was changed');
        $this->assertNotFalse($countAt, 'The mapped count must be printed.');
        $this->assertNotFalse($failureAt, 'The list-read failure must be printed.');
        $this->assertLessThan($failureAt, $countAt, 'The mapped count must be printed before the failure.');
    }
}
