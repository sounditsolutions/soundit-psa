<?php

namespace Tests\Feature\ScreenConnect;

use App\Jobs\ProcessScreenConnectWebhook;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use App\Models\ScreenConnectWebhook;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Card LDlXWBwA — a session id held by a soft-deleted asset.
 *
 * resolveAsset step 1 runs under the SoftDeletes scope and cannot see a trashed holder,
 * but the single-column unique key on screenconnect_session_id can, so a hostname-matched
 * live asset taking the id failed on every webhook for that device. The fix releases the
 * id from the trashed row in the same transaction as the save, only on the step-2 path,
 * after the W1 cross-client check, and never from a live holder.
 *
 * Every test seeds a trashed holder. Synthetic names and documentation-range data only.
 */
class ScreenConnectTrashedSessionReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const OTHER_SESSION = '99999999-8888-7777-6666-555555555555';

    private const RELEASE_MESSAGE = '[ScreenConnect] Released session id from a trashed asset to the hostname-matched asset';

    /** Native {*:json} shape: the company lives in Session.CustomProperty1. */
    private function nativePayload(string $eventType, ?string $company, string $machine = 'Trash-Host', string $session = self::SESSION): array
    {
        return [
            'Session' => [
                'SessionID' => $session,
                'Name' => $machine,
                'SessionType' => 'Access',
                'CustomProperty1' => $company,
                'GuestMachineName' => $machine,
                'GuestClientVersion' => '24.1.0.1',
            ],
            'Event' => ['EventType' => $eventType, 'Time' => '2026-10-01T12:00:00Z', 'Data' => 'whoami', 'Host' => 'tech'],
            'Connection' => ['ParticipantName' => 'tech', 'NetworkAddress' => '203.0.113.9'],
        ];
    }

    private function trashedHolder(Client $client, string $session = self::SESSION): Asset
    {
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'name' => 'Trash-Host',
            'hostname' => 'Trash-Host',
            'screenconnect_session_id' => $session,
        ]);
        $asset->delete();

        $this->assertSoftDeleted($asset);

        return $asset;
    }

    private function liveAsset(Client $client, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Trash-Host',
            'hostname' => 'Trash-Host',
            'screenconnect_session_id' => null,
            'screenconnect_online' => false,
            'screenconnect_synced_at' => null,
        ], $overrides));
    }

    private function runThroughJob(array $payload): ScreenConnectWebhook
    {
        $webhook = ScreenConnectWebhook::create([
            'event_type' => $payload['Event']['EventType'] ?? 'unknown',
            'session_id' => $payload['Session']['SessionID'],
            'payload' => $payload,
        ]);

        (new ProcessScreenConnectWebhook($webhook->id))->handle(app(ScreenConnectSyncService::class));

        return $webhook->fresh();
    }

    private function sessionOf(Asset $asset): ?string
    {
        return Asset::withTrashed()->findOrFail($asset->id)->screenconnect_session_id;
    }

    private function assertNoRelease(): void
    {
        Log::shouldNotHaveReceived('warning', [self::RELEASE_MESSAGE, \Mockery::any()]);
    }

    // ── (a) trashed holder + live hostname match ──

    public function test_a_trashed_holder_releases_the_id_to_the_live_hostname_match(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client);
        $live = $this->liveAsset($client);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co'));

        $this->assertSame('processed', $webhook->status, 'the save must no longer fail on the unique key');
        $this->assertNull($webhook->error);
        $this->assertSame(self::SESSION, $this->sessionOf($live), 'the live asset takes the id');
        $this->assertNull($this->sessionOf($trashed), 'the trashed holder is released');
        $this->assertSoftDeleted($trashed);
        $this->assertTrue((bool) $live->fresh()->screenconnect_online, 'the device event is attached');
        $this->assertNotNull($live->fresh()->screenconnect_synced_at);

        // Ids only: exactly these three keys, with these values, and no name, hostname or company.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []): bool => $message === self::RELEASE_MESSAGE
                && $context === [
                    'trashed_asset_id' => $trashed->id,
                    'asset_id' => $live->id,
                    'session_id' => self::SESSION,
                ]
        );
        Log::shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(
            fn ($context): bool => str_contains(json_encode($context), 'Trash-Host') || str_contains(json_encode($context), 'Alpha Co')
        )]);

        // The next webhook resolves by step 1 and releases nothing further.
        $again = $this->runThroughJob($this->nativePayload('RanCommand', 'Alpha Co'));
        $this->assertSame('processed', $again->status);
        $this->assertSame(1, ScreenConnectEvent::where('asset_id', $live->id)->count());
    }

    public function test_a_failed_save_rolls_the_release_back(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client);
        $live = $this->liveAsset($client);

        Asset::saving(function (Asset $asset) use ($live): void {
            if ($asset->id === $live->id) {
                throw new \RuntimeException('simulated save failure');
            }
        });

        try {
            app(ScreenConnectSyncService::class)->processWebhook($this->nativePayload('Connected', 'Alpha Co'));
            $this->fail('the simulated save failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated save failure', $e->getMessage());
        }

        $this->assertSame(self::SESSION, $this->sessionOf($trashed), 'the release is rolled back with the failed save');
        $this->assertNull($this->sessionOf($live));
        $this->assertNoRelease();
    }

    // ── (b) trashed holder, no hostname match ──

    public function test_b_a_trashed_holder_with_no_hostname_match_is_left_untouched(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client);
        $bystander = $this->liveAsset($client, ['name' => 'Other-Host', 'hostname' => 'Other-Host']);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co', 'Unknown-Host'));

        $this->assertSame('skipped', $webhook->status);
        $this->assertStringStartsWith('No matching asset', (string) $webhook->error);
        $this->assertSame(self::SESSION, $this->sessionOf($trashed));
        $this->assertSoftDeleted($trashed);
        $this->assertNull($this->sessionOf($bystander));
        $this->assertNoRelease();
    }

    public function test_b_a_trashed_holder_with_no_resolved_client_is_left_untouched(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client);
        $live = $this->liveAsset($client);

        // Same hostname, but the company resolves to no client, so step 2 never runs.
        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Nobody Co'));

        $this->assertSame('skipped', $webhook->status);
        $this->assertStringStartsWith('No matching asset', (string) $webhook->error);
        $this->assertSame(self::SESSION, $this->sessionOf($trashed));
        $this->assertNull($this->sessionOf($live));
        $this->assertNoRelease();
    }

    // ── (c) a live holder resolves by step 1, no release ──

    public function test_c_a_live_holder_still_resolves_by_step_one_with_no_release(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        // A trashed holder of a DIFFERENT id, so a release that ignored the id would show.
        $trashedOther = $this->trashedHolder($client, self::OTHER_SESSION);
        $holder = $this->liveAsset($client, ['name' => 'Holder-Host', 'hostname' => 'Holder-Host', 'screenconnect_session_id' => self::SESSION]);
        // A hostname match for the webhook machine that must NOT be chosen over step 1.
        $hostnameMatch = $this->liveAsset($client);

        $result = app(ScreenConnectSyncService::class)->processWebhook($this->nativePayload('Connected', 'Alpha Co'));

        $this->assertStringStartsWith('Processed Connected for asset #'.$holder->id.' ', $result, 'step 1 resolves the live holder');
        $this->assertSame(self::SESSION, $this->sessionOf($holder), 'the live holder keeps the id');
        $this->assertTrue((bool) $holder->fresh()->screenconnect_online);
        $this->assertNull($this->sessionOf($hostnameMatch));
        $this->assertSame(self::OTHER_SESSION, $this->sessionOf($trashedOther));
        $this->assertNoRelease();
    }

    public function test_c_a_live_holder_of_the_same_id_is_never_released_by_a_hostname_match(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client, self::OTHER_SESSION);
        $holder = $this->liveAsset($client, ['name' => 'Holder-Host', 'hostname' => 'Holder-Host', 'screenconnect_session_id' => self::SESSION]);
        $live = $this->liveAsset($client);

        // A webhook for OTHER_SESSION: step 1 sees no live holder, step 2 matches $live,
        // and only the trashed holder of OTHER_SESSION may be released.
        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co', 'Trash-Host', self::OTHER_SESSION));

        $this->assertSame('processed', $webhook->status);
        $this->assertSame(self::OTHER_SESSION, $this->sessionOf($live));
        $this->assertNull($this->sessionOf($trashed));
        $this->assertSame(self::SESSION, $this->sessionOf($holder), 'a live holder is never touched');
    }

    public function test_c_a_live_holder_that_appears_after_step_one_is_never_released(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client, self::OTHER_SESSION);
        $racer = $this->liveAsset($client, ['name' => 'Racer-Host', 'hostname' => 'Racer-Host']);
        $live = $this->liveAsset($client);

        // A concurrent webhook links the id to another LIVE asset after step 1 looked and
        // found no holder, but before this webhook saves. Simulated by writing the id
        // straight after the step-2 hostname query runs.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $racer): void {
            if (! $raced && str_contains($query->sql, 'LOWER(hostname)')) {
                $raced = true;
                DB::table('assets')->where('id', $racer->id)->update(['screenconnect_session_id' => self::SESSION]);
            }
        });

        try {
            app(ScreenConnectSyncService::class)->processWebhook($this->nativePayload('Connected', 'Alpha Co'));
            $this->fail('the unique key must still refuse a second live holder');
        } catch (UniqueConstraintViolationException) {
            // as before the fix: the job retries, and step 1 then resolves the live holder
        }

        $this->assertTrue($raced, 'the race was injected');
        $this->assertSame(self::SESSION, $this->sessionOf($racer), 'a live holder is never released');
        $this->assertNull($this->sessionOf($live));
        $this->assertSame(self::OTHER_SESSION, $this->sessionOf($trashed));
        $this->assertNoRelease();
    }

    public function test_c_a_live_holder_of_another_client_appearing_after_step_one_is_not_read_as_a_trashed_holder(): void
    {
        Log::spy();
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $trashed = $this->trashedHolder($alpha, self::OTHER_SESSION);
        $racer = $this->liveAsset($bravo, ['name' => 'Racer-Host', 'hostname' => 'Racer-Host']);
        $live = $this->liveAsset($alpha);

        $raced = false;
        DB::listen(function ($query) use (&$raced, $racer): void {
            if (! $raced && str_contains($query->sql, 'LOWER(hostname)')) {
                $raced = true;
                DB::table('assets')->where('id', $racer->id)->update(['screenconnect_session_id' => self::SESSION]);
            }
        });

        try {
            $result = app(ScreenConnectSyncService::class)->processWebhook($this->nativePayload('Connected', 'Alpha Co'));
            $this->fail('a live holder must not be reported as a trashed holder: '.$result);
        } catch (UniqueConstraintViolationException) {
            // as before the fix: the job retries, and step 1 then resolves the live holder
        }

        $this->assertTrue($raced, 'the race was injected');
        $this->assertSame(self::SESSION, $this->sessionOf($racer));
        $this->assertNull($this->sessionOf($live));
        $this->assertSame(self::OTHER_SESSION, $this->sessionOf($trashed));
        $this->assertNoRelease();
    }

    public function test_c_a_trashed_holder_restored_before_the_lock_is_not_released(): void
    {
        Log::spy();
        $client = Client::factory()->create(['name' => 'Alpha Co']);
        $trashed = $this->trashedHolder($client);
        $live = $this->liveAsset($client);

        // The trashed holder is found, then restored (now a LIVE holder) before the
        // locked re-read: the re-read must refuse to release it.
        $restored = false;
        DB::listen(function ($query) use (&$restored, $trashed): void {
            if (! $restored && str_contains($query->sql, '"deleted_at" is not null') && str_contains($query->sql, 'screenconnect_session_id')) {
                $restored = true;
                DB::table('assets')->where('id', $trashed->id)->update(['deleted_at' => null]);
            }
        });

        try {
            app(ScreenConnectSyncService::class)->processWebhook($this->nativePayload('Connected', 'Alpha Co'));
            $this->fail('the unique key must still refuse while the restored holder keeps the id');
        } catch (UniqueConstraintViolationException) {
            // the job retries, and step 1 then resolves the restored holder
        }

        $this->assertTrue($restored, 'the restore was injected');
        $this->assertNull(Asset::find($trashed->id)?->deleted_at);
        $this->assertSame(self::SESSION, $this->sessionOf($trashed), 'a restored (live) holder is never released');
        $this->assertNull($this->sessionOf($live));
        $this->assertNoRelease();
    }

    public function test_a_a_trashed_holder_of_another_client_is_released_to_the_hostname_match(): void
    {
        Log::spy();
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        // The device moved clients: its old asset was trashed under Bravo, and the webhook
        // company now resolves to Alpha, where a live asset matches the hostname. A trashed
        // holder is released whatever its client, so this heals instead of being skipped.
        $trashed = $this->trashedHolder($bravo);
        $live = $this->liveAsset($alpha);

        $webhook = $this->runThroughJob($this->nativePayload('RanCommand', 'Alpha Co'));

        $this->assertSame('processed', $webhook->status, 'a client move must not be skipped');
        $this->assertNull($webhook->error);
        $this->assertSame(self::SESSION, $this->sessionOf($live), 'the live asset takes the id');
        $this->assertNull($this->sessionOf($trashed), 'the trashed holder is released');
        $this->assertSoftDeleted($trashed);
        $this->assertSame(1, ScreenConnectEvent::where('asset_id', $live->id)->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []): bool => $message === self::RELEASE_MESSAGE
                && $context === [
                    'trashed_asset_id' => $trashed->id,
                    'asset_id' => $live->id,
                    'session_id' => self::SESSION,
                ]
        );
        Log::shouldNotHaveReceived('warning', [\Mockery::on(
            fn ($message): bool => is_string($message) && str_contains($message, 'contradicts')
        ), \Mockery::any()]);
    }

    // ── (d) W1 fires before any release ──

    public function test_d_a_live_cross_client_holder_is_skipped_by_w1_and_releases_nothing(): void
    {
        Log::spy();
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $trashed = $this->trashedHolder($alpha, self::OTHER_SESSION);
        $holder = $this->liveAsset($bravo, ['screenconnect_session_id' => self::SESSION]);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co'));

        $this->assertSame('skipped', $webhook->status);
        $this->assertSame(self::SESSION, $this->sessionOf($holder));
        $this->assertSame(self::OTHER_SESSION, $this->sessionOf($trashed));
        $this->assertNoRelease();
    }
}
