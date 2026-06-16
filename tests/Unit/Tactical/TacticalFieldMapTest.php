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
    public function test_ram_gb_reads_total_ram_as_gb_integer_not_bytes(): void
    {
        // Tactical's agent `total_ram` is an INTEGER COUNT OF GIGABYTES (source
        // v1.5.0 + live VM 105), NOT a byte count. 4 => 4.0 GB, 16 => 16.0 GB.
        $this->assertSame(4.0, TacticalFieldMap::ramGb(4));
        $this->assertSame(16.0, TacticalFieldMap::ramGb(16));
        // A string GB value (some serializers stringify it) still maps directly.
        $this->assertSame(8.0, TacticalFieldMap::ramGb('8'));
    }

    public function test_ram_gb_handles_null_and_zero(): void
    {
        $this->assertNull(TacticalFieldMap::ramGb(null));
        // 0 GB is a present-but-empty reading; treat as null (no RAM known).
        $this->assertNull(TacticalFieldMap::ramGb(0));
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
        // getAgentChecks() shape: a LIST of checks, each with check_result.status.
        // (This summary helper is for that LIST only — the getAgent DETAIL `checks`
        // is a pre-computed summary dict read directly, not through here.)
        $checks = [
            ['name' => 'Disk C', 'check_result' => ['status' => 'failing']],
            ['name' => 'Ping', 'check_result' => ['status' => 'passing']],
            ['name' => 'CPU', 'check_result' => ['status' => 'failing']],
        ];

        $summary = TacticalFieldMap::checksSummary($checks);

        $this->assertSame(2, $summary['failing']);
        $this->assertSame(3, $summary['total']);
    }

    public function test_checks_summary_empty_is_zero_zero(): void
    {
        $summary = TacticalFieldMap::checksSummary([]);

        $this->assertSame(0, $summary['failing']);
        $this->assertSame(0, $summary['total']);
    }
}
