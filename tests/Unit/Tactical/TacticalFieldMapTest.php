<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalFieldMap;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Amendment E (P4): the single source of truth for total_ram->GB,
 * boot_time->uptime, and the checks failing/total summary. Behaviour is locked
 * to what TriageToolExecutor produced before extraction (no AI-visible drift).
 */
class TacticalFieldMapTest extends TestCase
{
    public function test_ram_gb_from_bytes_rounds_to_one_decimal(): void
    {
        // 17179869184 bytes = 16 GiB exactly.
        $this->assertSame(16.0, TacticalFieldMap::ramGbFromBytes(17179869184));
        // 8.0 GiB
        $this->assertSame(8.0, TacticalFieldMap::ramGbFromBytes(8589934592));
        // 16310104064 = 15.19... -> 15.2
        $this->assertSame(15.2, TacticalFieldMap::ramGbFromBytes(16310104064));
    }

    public function test_ram_gb_from_bytes_handles_null_and_zero(): void
    {
        $this->assertNull(TacticalFieldMap::ramGbFromBytes(null));
        // 0 bytes is a present-but-empty reading; treat as null (no RAM known).
        $this->assertNull(TacticalFieldMap::ramGbFromBytes(0));
    }

    public function test_uptime_from_boot_time_formats_days_hours(): void
    {
        $boot = Carbon::now()->subDays(3)->subHours(5)->subMinutes(12);

        $this->assertSame('3d 5h', TacticalFieldMap::uptimeFromBootTime($boot->timestamp));
    }

    public function test_uptime_from_boot_time_minutes_only_when_under_an_hour(): void
    {
        $boot = Carbon::now()->subMinutes(42);

        $this->assertSame('42m', TacticalFieldMap::uptimeFromBootTime($boot->timestamp));
    }

    public function test_uptime_from_boot_time_handles_null(): void
    {
        $this->assertNull(TacticalFieldMap::uptimeFromBootTime(null));
        $this->assertNull(TacticalFieldMap::uptimeFromBootTime(0));
    }

    public function test_checks_summary_counts_failing_and_total(): void
    {
        // getAgentChecks() shape: rich check_result.status
        $checks = [
            ['name' => 'Disk C', 'check_result' => ['status' => 'failing']],
            ['name' => 'Ping', 'check_result' => ['status' => 'passing']],
            ['name' => 'CPU', 'check_result' => ['status' => 'failing']],
        ];

        $summary = TacticalFieldMap::checksSummary($checks);

        $this->assertSame(2, $summary['failing']);
        $this->assertSame(3, $summary['total']);
    }

    public function test_checks_summary_reads_flat_status_from_agent_detail(): void
    {
        // getAgent() embeds checks with a flat `status` (no check_result wrapper).
        $checks = [
            ['name' => 'Disk C', 'status' => 'failing'],
            ['name' => 'Ping', 'status' => 'passing'],
        ];

        $summary = TacticalFieldMap::checksSummary($checks);

        $this->assertSame(1, $summary['failing']);
        $this->assertSame(2, $summary['total']);
    }

    public function test_checks_summary_empty_is_zero_zero(): void
    {
        $summary = TacticalFieldMap::checksSummary([]);

        $this->assertSame(0, $summary['failing']);
        $this->assertSame(0, $summary['total']);
    }
}
