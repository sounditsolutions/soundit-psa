<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalFieldMap;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * psa-843 — the per-device list reads used to return a bare array cut at a
 * fixed size with no marker, no total and no caller-settable bound.
 *
 * The software read is the worst case because it sorts ALPHABETICALLY before
 * cutting: on a machine with more than 50 packages the list stopped
 * mid-alphabet, so a package below the cut was indistinguishable from a package
 * that is not installed. That is a WRONG answer, not a short one.
 *
 * These guards hold three separate things, and all three are needed for the
 * caller to be able to get a right answer:
 *   1. the envelope EXISTS and its `total` is counted over the full set BEFORE
 *      the slice (so truncation is visible);
 *   2. the `limit` is honoured, clamped, and degrades to the default rather
 *      than to an empty list (so the rest is REACHABLE);
 *   3. `limit` is DECLARED IN THE TOOL SCHEMA (so a caller can actually send
 *      one — an executor that reads an input no schema advertises is a fix that
 *      never reaches the caller).
 *
 * Both delivered surfaces are held: TacticalReadOnlyToolset (the Chet data
 * surface) and TriageToolExecutor (the triage loop). They were byte-identical
 * duplicates when this was filed.
 */
class DeviceReadTruncationEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function linkedClient(): Client
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
        ]);

        return $client;
    }

    /**
     * 120 packages named so that alphabetical order is also numeric order, with
     * the LAST one ('pkg-119') deliberately below any cut we take.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manyPackages(int $count = 120): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name' => sprintf('pkg-%03d', $i),
                'version' => '1.0.0',
                'publisher' => 'Example Publisher',
            ];
        }

        return $rows;
    }

    private function toolset(): TacticalReadOnlyToolset
    {
        return app(TacticalReadOnlyToolset::class);
    }

    /**
     * The Chet surface FENCES every name it returns, so the row's `name` is
     * never equal to the package name — an assertContains() on the raw name
     * passes vacuously in both directions. Match on the substring instead.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function namesInclude(array $rows, string $needle): bool
    {
        foreach ($rows as $row) {
            if (str_contains((string) ($row['name'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    // ── The defect itself: a package below the cut read as not installed ──

    public function test_chet_software_read_reports_the_full_total_and_marks_the_list_truncated(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages());
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
        ], $client->id);

        $this->assertArrayNotHasKey('error', $result);

        // The envelope, not a bare list.
        $this->assertSame(120, $result['total'], 'total must count the FULL set before the slice');
        $this->assertSame(50, $result['count']);
        $this->assertSame(50, $result['limit']);
        $this->assertTrue($result['truncated']);
        $this->assertCount(50, $result['software']);

        // The note must say the load-bearing thing in words: absence from a
        // truncated list is not evidence of absence on the device.
        $this->assertIsString($result['truncation_note']);
        $this->assertStringContainsString('NOT evidence of absence', $result['truncation_note']);

        // And the cut really is mid-alphabet — pkg-119 is not in this page.
        $this->assertFalse($this->namesInclude($result['software'], 'pkg-119'));
        $this->assertTrue($this->namesInclude($result['software'], 'pkg-000'));
    }

    public function test_chet_software_read_lets_the_caller_reach_the_rows_past_the_cut(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages());
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
            'limit' => 200,
        ], $client->id);

        // 200 is over the row count but under the 500 max, so everything comes back.
        $this->assertSame(120, $result['total']);
        $this->assertSame(120, $result['count']);
        $this->assertFalse($result['truncated']);
        $this->assertNull($result['truncation_note']);
        $this->assertTrue($this->namesInclude($result['software'], 'pkg-119'));
    }

    public function test_chet_software_read_clamps_the_limit_to_its_documented_max(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages(600));
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
            'limit' => 99999,
        ], $client->id);

        $this->assertSame(600, $result['total']);
        $this->assertSame(500, $result['limit'], 'limit must clamp to the schema max');
        $this->assertSame(500, $result['count']);
        $this->assertTrue($result['truncated']);
    }

    /**
     * A zero/blank/garbage limit must fall back to the DEFAULT, never to an
     * empty list. An empty list reads as "nothing installed", which is the
     * exact wrong answer this issue exists to stop.
     *
     * @dataProvider degenerateLimits
     */
    public function test_chet_software_read_degrades_a_degenerate_limit_to_the_default_not_to_empty(mixed $limit): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages());
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
            'limit' => $limit,
        ], $client->id);

        $this->assertSame(50, $result['limit']);
        $this->assertSame(50, $result['count']);
        $this->assertSame(120, $result['total']);
    }

    /** @return array<string, array<int, mixed>> */
    public static function degenerateLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'blank string' => [''],
            'non-numeric' => ['all'],
            'null' => [null],
        ];
    }

    // ── Services: the total is counted after the filter, before the slice ──

    public function test_chet_services_read_counts_the_total_after_the_filter_but_before_the_slice(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $services = [];
        for ($i = 0; $i < 80; $i++) {
            $services[] = [
                'name' => sprintf('svc-%03d', $i),
                'display_name' => sprintf('Service %03d', $i),
                // 60 running, 20 stopped.
                'status' => $i < 60 ? 'running' : 'stopped',
                'start_type' => 'auto',
            ];
        }

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->once()->with('agent-1')
            ->andReturn(['services' => $services]);
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_services', [
            'hostname' => 'PC-01',
            'filter' => 'running',
        ], $client->id);

        // 60 match the filter; the total describes the answer to the question
        // asked, not the whole box, and it is counted before the 50-row cut.
        $this->assertSame(60, $result['total']);
        $this->assertSame(50, $result['count']);
        $this->assertTrue($result['truncated']);
        $this->assertStringContainsString("services matching 'running'", $result['truncation_note']);
        $this->assertCount(50, $result['services']);
    }

    // ── Disks: three capped lists, each with its own pre-cut total ──

    public function test_chet_disks_read_marks_and_bounds_all_three_lists_including_volumes(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $volumes = [];
        for ($i = 0; $i < 14; $i++) {
            $volumes[] = [
                'device' => 'D'.$i.':',
                'total' => '100.0 GB',
                'free' => '50.0 GB',
                'percent' => 50,
                'fstype' => 'NTFS',
            ];
        }
        $physical = [];
        for ($i = 0; $i < 12; $i++) {
            $physical[] = ['caption' => 'Disk '.$i, 'size' => 1073741824, 'interface_type' => 'SCSI', 'status' => 'OK'];
        }

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->once()->with('agent-1')->andReturn([
            'disks' => $volumes,
            'physical_disks' => $physical,
            'wmi_detail' => ['disk' => [['Caption' => 'W1', 'Size' => 1073741824, 'FreeSpace' => 536870912]]],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_disks', [
            'hostname' => 'PC-01',
        ], $client->id);

        $this->assertSame(10, $result['limit']);

        // Volumes: the cap lives in the shared mapper, but the counters and the
        // marker are here, so a volume below the cut is visible as missing.
        $this->assertSame(14, $result['volumes_total']);
        $this->assertTrue($result['volumes_truncated']);
        $this->assertCount(10, $result['volumes']);

        $this->assertSame(12, $result['physical_disks_total']);
        $this->assertTrue($result['physical_disks_truncated']);
        $this->assertCount(10, $result['physical_disks']);

        // The short list is honestly reported as complete.
        $this->assertSame(1, $result['wmi_disk_total']);
        $this->assertFalse($result['wmi_disk_truncated']);

        $this->assertIsString($result['truncation_note']);
    }

    public function test_chet_disks_read_lets_the_caller_reach_the_volumes_past_the_cut(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $volumes = [];
        for ($i = 0; $i < 14; $i++) {
            $volumes[] = [
                'device' => 'D'.$i.':',
                'total' => '100.0 GB',
                'free' => '50.0 GB',
                'percent' => 50,
                'fstype' => 'NTFS',
            ];
        }

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->once()->with('agent-1')->andReturn([
            'disks' => $volumes,
            'physical_disks' => [],
            'wmi_detail' => ['disk' => []],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $result = $this->toolset()->execute('tactical_get_device_disks', [
            'hostname' => 'PC-01',
            'limit' => 50,
        ], $client->id);

        // A marker with no way to ask for the rest is still a wrong answer.
        $this->assertSame(14, $result['volumes_total']);
        $this->assertFalse($result['volumes_truncated']);
        $this->assertCount(14, $result['volumes']);
        $this->assertNull($result['truncation_note']);
    }

    /**
     * The shared mapper's default must be untouched: TacticalInsightService and
     * TacticalPanelData call it without a limit and must keep the exact list
     * they have always had.
     */
    public function test_the_shared_volume_mapper_keeps_its_default_cap_for_the_ui_callers(): void
    {
        $volumes = [];
        for ($i = 0; $i < 14; $i++) {
            $volumes[] = ['device' => 'D'.$i.':', 'total' => '100.0 GB', 'free' => '50.0 GB', 'percent' => 50];
        }

        $this->assertCount(
            TacticalFieldMap::DISK_VOLUME_LIMIT,
            TacticalFieldMap::mapDiskVolumes($volumes),
        );
    }

    // ── The triage surface must not drift from the Chet surface ──

    public function test_triage_software_read_returns_the_same_envelope_as_the_chet_surface(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages());
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
        ]);

        $this->assertSame(120, $result['total']);
        $this->assertSame(50, $result['count']);
        $this->assertTrue($result['truncated']);
        $this->assertIsString($result['truncation_note']);
        $this->assertNotContains('pkg-119', array_column($result['software'], 'name'));
    }

    public function test_triage_software_read_honours_the_caller_limit(): void
    {
        $this->configureTactical();
        $client = $this->linkedClient();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')->once()->with('agent-1')
            ->andReturn($this->manyPackages());
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device_software', [
            'hostname' => 'PC-01',
            'limit' => 200,
        ]);

        $this->assertSame(120, $result['count']);
        $this->assertFalse($result['truncated']);
        $this->assertContains('pkg-119', array_column($result['software'], 'name'));
    }

    // ── The fix has to REACH the caller: limit must be in the schema ──

    /**
     * An executor that reads an input no schema advertises is a fix nobody can
     * use — the model never learns the parameter exists. Both delivered
     * surfaces publish these three tools from TriageToolDefinitions, so one
     * declaration serves both.
     *
     * @dataProvider boundedDeviceReads
     */
    public function test_the_bounded_device_reads_declare_their_limit_in_the_tool_schema(string $tool): void
    {
        $definition = collect(TriageToolDefinitions::tacticalTools())
            ->firstWhere('name', $tool);

        $this->assertNotNull($definition, "{$tool} must be defined");
        $this->assertArrayHasKey(
            'limit',
            $definition['input_schema']['properties'],
            "{$tool} reads \$input['limit'] but never advertises it"
        );
        $this->assertSame('integer', $definition['input_schema']['properties']['limit']['type']);
        $this->assertNotContains('limit', $definition['input_schema']['required']);
    }

    /** @return array<string, array<int, string>> */
    public static function boundedDeviceReads(): array
    {
        return [
            'software' => ['tactical_get_device_software'],
            'services' => ['tactical_get_device_services'],
            'disks' => ['tactical_get_device_disks'],
        ];
    }

    /**
     * The truncation risk has to be stated where the model reads it. A caller
     * that never learns the list can be cut will read a short list as complete.
     *
     * @dataProvider boundedDeviceReads
     */
    public function test_the_bounded_device_reads_warn_about_truncation_in_their_description(string $tool): void
    {
        $definition = collect(TriageToolDefinitions::tacticalTools())
            ->firstWhere('name', $tool);

        $this->assertMatchesRegularExpression(
            '/truncated/i',
            $definition['description'],
            "{$tool} must tell the caller the list can be truncated"
        );
        $this->assertMatchesRegularExpression(
            '/MAY STILL BE (INSTALLED|PRESENT)/',
            $definition['description'],
            "{$tool} must say that absence from a truncated list is not absence on the device"
        );
    }
}
