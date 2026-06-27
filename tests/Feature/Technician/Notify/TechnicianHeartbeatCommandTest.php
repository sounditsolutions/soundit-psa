<?php

namespace Tests\Feature\Technician\Notify;

use App\Jobs\TechnicianPing;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TechnicianHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the review-pass staleness alarm uses cache (last-run + alert throttle)
    }

    public function test_ping_records_the_worker_heartbeat(): void
    {
        $this->assertNull(TechnicianConfig::workerLastSeen());
        (new TechnicianPing)->handle();
        $this->assertNotNull(TechnicianConfig::workerLastSeen());
        $this->assertTrue(TechnicianConfig::workerLastSeen()->greaterThan(now()->subMinute()));
    }

    public function test_heartbeat_alerts_when_the_worker_is_stale_and_throttles(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        \App\Models\Setting::setValue('technician_worker_last_seen', now()->subHour()->toIso8601String()); // stale

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->once()); // alerts ONCE despite two runs

        $this->artisan('technician:heartbeat')->assertSuccessful();
        $this->artisan('technician:heartbeat')->assertSuccessful(); // throttled — no second alert
    }

    public function test_heartbeat_silent_when_worker_is_fresh(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        \App\Models\Setting::setValue('technician_worker_last_seen', now()->toIso8601String()); // fresh

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());

        $this->artisan('technician:heartbeat')->assertSuccessful();
    }

    public function test_heartbeat_alerts_when_worker_has_never_checked_in(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        // technician_worker_last_seen deliberately NOT set — fresh install, worker never started.
        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->once());
        $this->artisan('technician:heartbeat')->assertSuccessful();
    }

    // ── Dead-man alarm for the agent review pass (psa-lqlu) ──────────────────────

    private function enableAutoReview(int $freq = 60): void
    {
        // technician_enabled left OFF so ONLY the review-pass check can alert (isolates it).
        \App\Models\Setting::setValue('triage_enabled', '1');
        \App\Models\Setting::setValue('triage_auto_review', '1');
        \App\Models\Setting::setValue('triage_review_frequency_minutes', (string) $freq);
    }

    public function test_heartbeat_alerts_when_the_agent_review_pass_is_stale_and_throttles(): void
    {
        // The 12.7h-stall failure mode: the review pass last ran long ago while auto-review
        // is on → surface it (once, throttled), instead of failing silent.
        $this->enableAutoReview(60);
        Cache::put('triage:review-open:last-run', now()->subMinutes(200), now()->addHours(24)); // > 2×60

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->once());

        $this->artisan('technician:heartbeat')->assertSuccessful();
        $this->artisan('technician:heartbeat')->assertSuccessful(); // throttled — no second alert
    }

    public function test_heartbeat_silent_when_the_review_pass_is_fresh(): void
    {
        $this->enableAutoReview(60);
        Cache::put('triage:review-open:last-run', now()->subMinutes(10), now()->addHours(24)); // fresh

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());

        $this->artisan('technician:heartbeat')->assertSuccessful();
    }

    public function test_heartbeat_silent_when_the_review_pass_has_never_run(): void
    {
        // Fresh deploy: last-run null → not-yet-established, no false alarm.
        $this->enableAutoReview(60);
        Cache::forget('triage:review-open:last-run');

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());

        $this->artisan('technician:heartbeat')->assertSuccessful();
    }

    public function test_heartbeat_silent_on_stale_review_pass_when_auto_review_disabled(): void
    {
        \App\Models\Setting::setValue('triage_enabled', '1');
        \App\Models\Setting::setValue('triage_auto_review', '0'); // off → no review-pass alarm
        Cache::put('triage:review-open:last-run', now()->subMinutes(999), now()->addHours(24));

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());

        $this->artisan('technician:heartbeat')->assertSuccessful();
    }
}
