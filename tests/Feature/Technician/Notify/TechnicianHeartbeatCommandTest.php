<?php

namespace Tests\Feature\Technician\Notify;

use App\Jobs\TechnicianPing;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

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
}
