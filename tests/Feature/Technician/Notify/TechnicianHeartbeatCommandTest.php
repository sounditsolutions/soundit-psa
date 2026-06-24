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
}
