<?php

namespace Tests\Feature\Technician;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianScheduleGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_scheduled_backstop_jobs_are_dark_by_default(): void
    {
        $this->assertFalse($this->filtersPass('App\Jobs\TechnicianPing'));
        $this->assertFalse($this->filtersPass('technician:heartbeat'));
        $this->assertFalse($this->filtersPass('technician:emergency-sweep'));
    }

    public function test_emergency_only_toggle_enables_ping_heartbeat_and_emergency_sweep(): void
    {
        Setting::setValue('technician_enabled', '0');
        Setting::setValue('technician_emergency_enabled', '1');

        $this->assertTrue($this->filtersPass('App\Jobs\TechnicianPing'));
        $this->assertTrue($this->filtersPass('technician:heartbeat'));
        $this->assertTrue($this->filtersPass('technician:emergency-sweep'));
    }

    public function test_full_technician_toggle_still_enables_ping_heartbeat_and_emergency_sweep(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_emergency_enabled', '0');

        $this->assertTrue($this->filtersPass('App\Jobs\TechnicianPing'));
        $this->assertTrue($this->filtersPass('technician:heartbeat'));
        $this->assertTrue($this->filtersPass('technician:emergency-sweep'));
    }

    public function test_auto_review_alone_only_enables_heartbeat_schedule_gate(): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_review', '1');

        $this->assertFalse($this->filtersPass('App\Jobs\TechnicianPing'));
        $this->assertTrue($this->filtersPass('technician:heartbeat'));
        $this->assertFalse($this->filtersPass('technician:emergency-sweep'));
    }

    private function filtersPass(string $summaryNeedle): bool
    {
        return $this->scheduleEvent($summaryNeedle)->filtersPass($this->app);
    }

    private function scheduleEvent(string $summaryNeedle): Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains($event->getSummaryForDisplay(), $summaryNeedle)) {
                return $event;
            }
        }

        $this->fail("Scheduled event [{$summaryNeedle}] was not registered.");
    }
}
