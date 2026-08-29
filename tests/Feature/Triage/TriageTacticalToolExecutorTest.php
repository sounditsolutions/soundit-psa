<?php

namespace Tests\Feature\Triage;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TriageTacticalToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_tactical_hostname_resolution_searches_within_ticket_client_before_denying_collisions(): void
    {
        $otherClient = Client::factory()->create();
        $targetClient = Client::factory()->create();

        $otherAsset = Asset::factory()->create([
            'client_id' => $otherClient->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $otherAsset->id,
            'agent_id' => 'agent-other',
            'hostname' => 'PC-01',
        ]);

        $targetAsset = Asset::factory()->create([
            'client_id' => $targetClient->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $targetAsset->id,
            'agent_id' => 'agent-target',
            'hostname' => 'PC-01',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->with('agent-target')
            ->andReturn([
                'hostname' => 'PC-01',
                'status' => 'online',
                'total_ram' => 16,
                'logged_in_username' => 'None',
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $targetClient->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device', [
            'hostname' => 'pc-01',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('PC-01', $result['hostname']);
        $this->assertSame('online', $result['status']);
        $this->assertSame(16.0, $result['ram_gb']);
    }

    public function test_tactical_software_tool_unwraps_the_wrapper_payload_instead_of_returning_phantom_rows(): void
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

        // Live Tactical serializes the inventory as a wrapper object — the rows
        // live under `software`. Mapping the wrapper itself used to return three
        // phantom {name: "Unknown", version: null, publisher: null} rows.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getSoftware')
            ->once()
            ->with('agent-1')
            ->andReturn([
                'id' => 4,
                'agent' => 12,
                'software' => [
                    ['name' => 'Mozilla Firefox', 'version' => '128.0.3', 'publisher' => 'Mozilla'],
                    ['name' => '7-Zip', 'version' => '24.07', 'publisher' => 'Igor Pavlov'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device_software', [
            'hostname' => 'pc-01',
        ]);

        // psa-843: envelope, not a bare list — total/count/truncated so a short
        // read is distinguishable from a cut one.
        $this->assertSame([
            'count' => 2,
            'total' => 2,
            'limit' => 50,
            'truncated' => false,
            'truncation_note' => null,
            'software' => [
                ['name' => '7-Zip', 'version' => '24.07', 'publisher' => 'Igor Pavlov'],
                ['name' => 'Mozilla Firefox', 'version' => '128.0.3', 'publisher' => 'Mozilla'],
            ],
        ], $result);
    }

    public function test_tactical_device_checks_signal_less_catalog_script_reads_unknown_with_explanatory_reason(): void
    {
        // psa-0pb9m R4 U5, triage boundary (the second delivered read
        // surface): a synced catalog row with the honest NULL shell and no
        // supported_platforms keeps platform_mismatch=null — tri-state
        // preserved, unknown is not a mismatch claim — but the reason must
        // name the script, say compatibility could not be verified, and give
        // the recovery. Two silent nulls are indistinguishable from
        // "assessed, nothing to say".
        $client = Client::factory()->create();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MAC-09',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac9',
            'hostname' => 'MAC-09',
            'plat' => 'darwin',
            'os' => 'Darwin 23.6.0 arm64',
        ]);
        TacticalScript::create([
            'tactical_script_id' => 702,
            'name' => 'Mystery Maintenance Script',
            'shell' => null,
            'supported_platforms' => null,
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgentChecks')
            ->once()
            ->with('agent-mac9')
            ->andReturn([
                [
                    'id' => 9009,
                    'check_type' => 'script',
                    'script' => 702,
                    'readable_desc' => 'Script check: Mystery Maintenance Script',
                    'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'fail'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device_checks', [
            'hostname' => 'mac-09',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $check = $result['checks'][0];
        $this->assertNull($check['platform_mismatch'], 'signal-less is unknown — neither a mismatch claim nor a compatibility claim');
        $this->assertIsString($check['platform_mismatch_reason'], 'the unknown must be explained, not silent (R4 U5)');
        $this->assertStringContainsString('Mystery Maintenance Script', $check['platform_mismatch_reason']);
        $this->assertStringContainsString('could not be verified', $check['platform_mismatch_reason']);
        $this->assertStringContainsString('tactical:sync-scripts', $check['platform_mismatch_reason']);
    }
}
