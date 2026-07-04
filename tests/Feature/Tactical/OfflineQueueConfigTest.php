<?php

namespace Tests\Feature\Tactical;

use App\Models\Setting;
use App\Support\TacticalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Offline-script-queue knobs (bd psa-xr84 §8) — all Charlie-tunable, safe defaults. */
class OfflineQueueConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_defaults_on_and_can_be_disabled(): void
    {
        // Default ON — it only replaces a dead-end for already-approved actions.
        $this->assertTrue(TacticalConfig::offlineQueueEnabled());

        Setting::setValue('tactical_offline_queue_enabled', '0');
        $this->assertFalse(TacticalConfig::offlineQueueEnabled());

        Setting::setValue('tactical_offline_queue_enabled', '1');
        $this->assertTrue(TacticalConfig::offlineQueueEnabled());
    }

    public function test_expiry_days_defaults_to_seven_and_guards_invalid(): void
    {
        $this->assertSame(7, TacticalConfig::offlineQueueExpiryDays());

        Setting::setValue('tactical_offline_queue_expiry_days', '14');
        $this->assertSame(14, TacticalConfig::offlineQueueExpiryDays());

        // Zero / negative / garbage must not disable the safety window.
        Setting::setValue('tactical_offline_queue_expiry_days', '0');
        $this->assertSame(7, TacticalConfig::offlineQueueExpiryDays());
    }

    public function test_sweep_minutes_defaults_to_ten_and_guards_invalid(): void
    {
        $this->assertSame(10, TacticalConfig::offlineQueueSweepMinutes());

        Setting::setValue('tactical_offline_queue_sweep_minutes', '5');
        $this->assertSame(5, TacticalConfig::offlineQueueSweepMinutes());

        Setting::setValue('tactical_offline_queue_sweep_minutes', 'garbage');
        $this->assertSame(10, TacticalConfig::offlineQueueSweepMinutes());
    }

    public function test_notify_on_run_defaults_off(): void
    {
        $this->assertFalse(TacticalConfig::offlineQueueNotifyOnRun());

        Setting::setValue('tactical_offline_queue_notify_on_run', '1');
        $this->assertTrue(TacticalConfig::offlineQueueNotifyOnRun());
    }
}
