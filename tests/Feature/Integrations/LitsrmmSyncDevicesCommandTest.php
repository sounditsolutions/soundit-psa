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
            ->withArgs(fn ($only, $accept) => $only === null && $accept === ['12:b:1:5:4:5:1:0:d0123456789', '34:d:2:0:0:1:0:2:dabcdef0123'])
            ->andReturn(new SyncResult);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        $this->artisan('litsrmm:sync-devices', ['--accept-short-read' => ['12:b:1:5:4:5:1:0:d0123456789', '34:d:2:0:0:1:0:2:dabcdef0123']])
            ->expectsOutputToContain('a short-read refusal is passed only if it matches exactly: 12:b:1:5:4:5:1:0:d0123456789, 34:d:2:0:0:1:0:2:dabcdef0123')
            ->doesntExpectOutputToContain('Accepting a short read')
            ->assertSuccessful();
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
        // every sample here fails on its length, so it kills only the
        // length mutants of the shape. The case, non-hex and missing-'d'
        // mutants are killed by the right-length samples below (#5855).
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
        // #5855: ten characters each, so only the alphabet, the case or the
        // 'd' can reject them: uppercase hex, a non-hex letter, ten hex with
        // no 'd' ('0123456789'), and a 'd' replaced by another letter.
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
}
