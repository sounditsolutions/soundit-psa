<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Models\Setting;
use App\Support\TacticalConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Device-sync cadence (psa-333 roll-forward).
 *
 * The sync ran dailyAt('05:32') — the only integration on a daily cadence, and
 * no margin at all under Asset::rmmStaleAfterHours()'s 24h window, so a live
 * machine could read Offline on nothing but sync lag. The remedy is the cadence,
 * not the window: an operator-tunable interval in SECONDS, defaulting to 300.
 */
class DeviceSyncCadenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_interval_defaults_to_five_minutes_and_guards_invalid(): void
    {
        $this->assertSame(300, TacticalConfig::deviceSyncIntervalSeconds());

        Setting::setValue('tactical_sync_interval_seconds', '60');
        $this->assertSame(60, TacticalConfig::deviceSyncIntervalSeconds());

        // Zero / negative / garbage must fall back, never disable the sync.
        Setting::setValue('tactical_sync_interval_seconds', '0');
        $this->assertSame(300, TacticalConfig::deviceSyncIntervalSeconds());

        Setting::setValue('tactical_sync_interval_seconds', 'garbage');
        $this->assertSame(300, TacticalConfig::deviceSyncIntervalSeconds());

        Setting::setValue('tactical_sync_interval_seconds', '-30');
        $this->assertSame(300, TacticalConfig::deviceSyncIntervalSeconds());
    }

    public function test_due_gate_throttles_to_the_configured_interval(): void
    {
        // First check is due; the next inside the window is not; past it, due again.
        $this->assertTrue(TacticalConfig::deviceSyncDue());
        $this->assertFalse(TacticalConfig::deviceSyncDue());

        $this->travel(4)->minutes();
        $this->assertFalse(TacticalConfig::deviceSyncDue(), 'inside the 300s window the gate must stay shut');

        $this->travel(2)->minutes();
        $this->assertTrue(TacticalConfig::deviceSyncDue());
    }

    public function test_due_gate_follows_the_setting_not_the_default(): void
    {
        Setting::setValue('tactical_sync_interval_seconds', '900');

        $this->assertTrue(TacticalConfig::deviceSyncDue());

        $this->travel(10)->minutes();
        $this->assertFalse(TacticalConfig::deviceSyncDue(), '10 minutes is inside a 900s interval');

        $this->travel(6)->minutes();
        $this->assertTrue(TacticalConfig::deviceSyncDue());
    }

    /**
     * Sub-minute is configurable but NOT actuatable: the scheduler ticks at most
     * once a minute. The gate must open on every tick rather than clamp the
     * operator's value — the honest behaviour is "as fast as the scheduler goes",
     * and the docblock says so out loud.
     */
    public function test_sub_minute_interval_opens_the_gate_every_tick(): void
    {
        Setting::setValue('tactical_sync_interval_seconds', '15');

        $this->assertTrue(TacticalConfig::deviceSyncDue());

        $this->travel(1)->minutes();
        $this->assertTrue(TacticalConfig::deviceSyncDue());

        $this->travel(1)->minutes();
        $this->assertTrue(TacticalConfig::deviceSyncDue());
    }

    /**
     * The gate stamps the cache when it returns true, so it consumes a tick. It
     * has to sit LAST in the when() chain, behind the guards that decide whether
     * the sync runs at all — otherwise an unconfigured install would burn the
     * interval and the first real tick after configuration would be skipped.
     */
    public function test_schedule_registers_every_minute_and_does_not_consume_ticks_when_unconfigured(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'tactical:sync-devices'));

        $this->assertCount(1, $events, 'tactical:sync-devices must be registered exactly once');

        $event = $events->first();

        $this->assertSame('* * * * *', $event->expression, 'the cadence lives in the throttle, not the cron expression');

        // Tactical is not configured in the test env, so the chain must stop
        // before the throttle.
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertNull(
            Cache::get('tactical_last_device_sync'),
            'the throttle must sit behind the configured/mapped guards — an unconfigured install must not burn the interval'
        );
    }

    /** With Tactical configured and a client mapped, the gate opens once and then throttles. */
    public function test_schedule_runs_once_per_interval_when_configured(): void
    {
        Setting::setValue('tactical_api_url', 'https://api.tactical.example');
        Setting::setEncrypted('tactical_api_key', 'test-key');
        Client::factory()->create(['tactical_site_id' => 'Acme|HQ']);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'tactical:sync-devices'));

        $this->assertTrue($event->filtersPass($this->app), 'first tick after configuration must run');
        $this->assertFalse($event->filtersPass($this->app), 'the next tick is inside the 300s window');

        $this->travel(6)->minutes();
        $this->assertTrue($event->filtersPass($this->app));
    }
}
