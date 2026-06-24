<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_technician_sends_nothing(): void
    {
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->never());
        $this->artisan('technician:digest')->assertSuccessful();
    }

    public function test_enabled_builds_notifies_and_records(): void
    {
        Setting::setValue('technician_enabled', '1');
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once());

        $this->assertNull(TechnicianConfig::lastDigestAt());
        $this->artisan('technician:digest')->assertSuccessful();
        $this->assertNotNull(TechnicianConfig::lastDigestAt());
    }
}
