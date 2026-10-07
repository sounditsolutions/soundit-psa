<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\SyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * `litsrmm:sync-devices`: the operator's handle on LitsrmmAssetSyncService.
 * The sync itself is covered by LitsrmmAssetSyncTest; this pins what the
 * command says and the exit status a scheduler or a person will act on.
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

    private function mapClient(): void
    {
        Client::factory()->create([
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
}
