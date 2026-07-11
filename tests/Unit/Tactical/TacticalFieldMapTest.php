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

    public function test_disk_size_to_gb_parses_formatted_strings(): void
    {
        // Tactical disk total/used/free are FORMATTED STRINGS ("X.Y GB"/TB/MB),
        // not byte counts (source v1.5.0 + live VM 105). Parse leading number+unit.
        $this->assertSame(19.3, TacticalFieldMap::diskSizeToGb('19.3 GB'));
        $this->assertSame(32.0, TacticalFieldMap::diskSizeToGb('32.0 GB'));
        // TB -> *1024, MB -> /1024, rounded to 1 decimal.
        $this->assertSame(2048.0, TacticalFieldMap::diskSizeToGb('2.0 TB'));
        $this->assertSame(0.5, TacticalFieldMap::diskSizeToGb('512.0 MB'));
        // A bare/unitless number is read as GB.
        $this->assertSame(100.0, TacticalFieldMap::diskSizeToGb('100'));
    }

    public function test_disk_size_to_gb_handles_null_and_garbage(): void
    {
        $this->assertNull(TacticalFieldMap::diskSizeToGb(null));
        $this->assertNull(TacticalFieldMap::diskSizeToGb(''));
        $this->assertNull(TacticalFieldMap::diskSizeToGb('n/a'));
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

    public function test_disk_volume_mapping_can_include_filesystem_type_for_read_tools(): void
    {
        $volumes = TacticalFieldMap::mapDiskVolumes([
            [
                'device' => 'C:',
                'total' => '100.0 GB',
                'free' => '25.0 GB',
                'percent' => 75,
                'fstype' => 'NTFS',
            ],
        ], includeFilesystemType: true);

        $this->assertSame([
            [
                'drive' => 'C:',
                'total_gb' => 100.0,
                'free_gb' => 25.0,
                'percent_used' => 75,
                'fstype' => 'NTFS',
            ],
        ], $volumes);
    }

    public function test_software_rows_unwraps_the_installed_software_wrapper(): void
    {
        // GET software/{agent}/ serializes the inventory as {id, agent,
        // software: [...]} — the rows live under the `software` key. Mapping
        // the wrapper itself yields three phantom {name: "Unknown"} rows.
        $rows = TacticalFieldMap::softwareRows([
            'id' => 4,
            'agent' => 12,
            'software' => [
                ['name' => 'Mozilla Firefox', 'version' => '128.0.3', 'publisher' => 'Mozilla'],
                ['name' => '7-Zip', 'version' => '24.07', 'publisher' => 'Igor Pavlov'],
            ],
        ]);

        $this->assertSame(['Mozilla Firefox', '7-Zip'], array_column($rows, 'name'));
    }

    public function test_software_rows_passes_a_bare_list_through_and_drops_non_row_entries(): void
    {
        $rows = TacticalFieldMap::softwareRows([
            ['name' => 'Google Chrome', 'version' => '126.0'],
            'not-a-row',
            ['name' => 'Zoom Workplace'],
        ]);

        $this->assertSame(['Google Chrome', 'Zoom Workplace'], array_column($rows, 'name'));
    }

    public function test_software_rows_treats_empty_and_unknown_object_shapes_as_no_inventory(): void
    {
        // An agent with no inventory record returns []; an unrecognized object
        // must map to no rows, never to placeholder rows.
        $this->assertSame([], TacticalFieldMap::softwareRows([]));
        $this->assertSame([], TacticalFieldMap::softwareRows(['id' => 4, 'agent' => 12]));
        $this->assertSame([], TacticalFieldMap::softwareRows(['detail' => 'Not found.']));
    }
}
