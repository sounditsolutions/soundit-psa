<?php

namespace Tests\Feature\Ninja;

use App\Jobs\ProcessNinjaWebhook;
use App\Models\Client;
use App\Models\NinjaWebhook;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ninja\NinjaBackupSyncService;
use App\Services\Ninja\NinjaSyncService;
use App\Services\SyncResult;
use App\Support\NinjaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-u97k: NinjaRMM is disabled by default (offboarding). These tests verify
 * that the disabled gate stops all Ninja sync commands, schedules, and webhook
 * jobs without deleting any code (reversible via ninja_enabled='1').
 */
class NinjaDisabledTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // 1. NinjaConfig::isEnabled() default and toggle
    // -----------------------------------------------------------------------

    public function test_ninja_config_is_disabled_by_default(): void
    {
        // No setting in DB — must default to false
        $this->assertFalse(NinjaConfig::isEnabled(), 'NinjaConfig::isEnabled() must default to false (no setting)');
    }

    public function test_ninja_config_is_enabled_when_setting_is_one(): void
    {
        Setting::setValue('ninja_enabled', '1');

        $this->assertTrue(NinjaConfig::isEnabled(), 'NinjaConfig::isEnabled() must return true when ninja_enabled=1');
    }

    // -----------------------------------------------------------------------
    // 2. ninja:sync-devices no-ops when disabled
    // -----------------------------------------------------------------------

    public function test_ninja_sync_devices_no_ops_when_disabled(): void
    {
        // Seed a client with a ninja_org_id so the only gate is the disabled flag
        Client::factory()->create(['ninja_org_id' => 99]);

        $this->mock(NinjaSyncService::class, function (MockInterface $m): void {
            $m->shouldReceive('syncAllDevices')->never();
            $m->shouldReceive('syncDevicesForClient')->never();
        });

        $this->artisan('ninja:sync-devices')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // 3. ninja:sync-devices reaches the service when enabled
    // -----------------------------------------------------------------------

    public function test_ninja_sync_devices_calls_service_when_enabled(): void
    {
        Setting::setValue('ninja_enabled', '1');

        Client::factory()->create(['ninja_org_id' => 100]);

        $result = new SyncResult;

        $this->mock(NinjaSyncService::class, function (MockInterface $m) use ($result): void {
            $m->shouldReceive('syncAllDevices')
                ->once()
                ->andReturn($result);
        });

        $this->artisan('ninja:sync-devices')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // 4. ninja:sync-backup no-ops when disabled
    // -----------------------------------------------------------------------

    public function test_ninja_sync_backup_no_ops_when_disabled(): void
    {
        Client::factory()->create(['ninja_org_id' => 101]);

        $this->mock(NinjaBackupSyncService::class, function (MockInterface $m): void {
            $m->shouldReceive('syncBackupUsage')->never();
        });

        $this->artisan('ninja:sync-backup')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // 5. Webhook skipped when disabled
    // -----------------------------------------------------------------------

    public function test_ninja_webhook_is_skipped_when_disabled(): void
    {
        $webhook = NinjaWebhook::create([
            'activity_type' => 'NODE_UPDATED',
            'ninja_device_id' => 42,
            'payload' => ['deviceId' => 42, 'activityType' => 'NODE_UPDATED'],
            'status' => 'pending',
        ]);

        $this->mock(NinjaSyncService::class, function (MockInterface $m): void {
            $m->shouldReceive('syncDeviceDetail')->never();
            $m->shouldReceive('syncDeviceFromWebhook')->never();
            $m->shouldReceive('syncAllDevices')->never();
            $m->shouldReceive('syncDevicesForClient')->never();
        });

        (new ProcessNinjaWebhook($webhook->id))->handle(app(NinjaSyncService::class));

        $this->assertSame('skipped', $webhook->fresh()->status, 'Webhook must be marked skipped when Ninja is disabled');
    }

    // -----------------------------------------------------------------------
    // 6. Settings-UI "Sync Backup Usage" button is gated when disabled
    // -----------------------------------------------------------------------

    public function test_ui_sync_backup_is_blocked_when_disabled(): void
    {
        // Ninja disabled by default — no setting needed.
        $this->mock(NinjaBackupSyncService::class, function (MockInterface $m): void {
            $m->shouldReceive('syncBackupUsage')->never();
        });

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.integrations.ninja.sync-backup'))
            ->assertRedirect()
            ->assertSessionHas('error', 'NinjaRMM integration is disabled.');
    }

    // -----------------------------------------------------------------------
    // 7. Settings-UI "Sync Backup Usage" button reaches the service when enabled
    // -----------------------------------------------------------------------

    public function test_ui_sync_backup_calls_service_when_enabled(): void
    {
        Setting::setValue('ninja_enabled', '1');

        $result = new SyncResult;

        $this->mock(NinjaBackupSyncService::class, function (MockInterface $m) use ($result): void {
            $m->shouldReceive('syncBackupUsage')
                ->once()
                ->andReturn($result);
        });

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.integrations.ninja.sync-backup'))
            ->assertRedirect();
    }
}
