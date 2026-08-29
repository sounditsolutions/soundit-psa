<?php

namespace Tests\Feature\Tactical;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #842(b): the not-seen sweep was gated `! $clientId`, and the only MCP caller —
 * StaffTacticalAdminToolExecutor::syncDevices() — always passes a server-derived
 * client id. The sweep was therefore structurally unreachable from the tool:
 * `deactivated` was a hard 0 on every invocation, an offboarded device kept its
 * last observed status indefinitely, and the tool reported that zero as a finding.
 *
 * The gate existed for a real reason. $seenAgentIds is populated AFTER two
 * `continue`s (unmapped siteKey, out-of-scope client), so sweeping against it on a
 * client-scoped run would have called every other client's fleet offline. The fix
 * separates the two concerns the gate had conflated:
 *
 *  - the PREDICATE now reads $seenAllAgentIds, the full upstream payload —
 *    getAgents() is a full fetch and $clientId only filters in PHP — so
 *    "not seen" finally means "Tactical stopped telling us about this agent_id";
 *  - the SCOPE is bounded to the run's own client by (client_name, site_name).
 *
 * Widening the predicate also fixes the FULL sync, which used to brand a live
 * fleet Offline whenever a site rename or a client leaving stage=Active dropped
 * its siteKey out of the client map. Those agents are present upstream; calling
 * them offline asserted the opposite of something we observed.
 *
 * What is deliberately NOT here: any state meaning "confirmed gone upstream".
 * Absent from the payload remains UNKNOWN. See the issue — that needs a distinct
 * signal, not a widened 'offline'.
 */
class TacticalScopedNotSeenSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $agents */
    private function syncService(array $agents): TacticalDeviceSyncService
    {
        return new TacticalDeviceSyncService(new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($agents))])),
            'timeout' => 30,
        ])));
    }

    private function failingSyncService(): TacticalDeviceSyncService
    {
        return new TacticalDeviceSyncService(new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([new Response(500, [], 'nope')])),
            'timeout' => 30,
        ])));
    }

    private function client(string $siteKey): Client
    {
        return Client::factory()->create([
            'tactical_site_id' => $siteKey,
            'is_active' => true,
            'stage' => ClientStage::Active,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function agent(string $agentId, string $clientName, string $siteName, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $agentId,
            'hostname' => $agentId,
            'client_name' => $clientName,
            'site_name' => $siteName,
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'last_seen' => '2026-08-29 01:00:00',
        ], $overrides);
    }

    private function row(string $agentId, string $clientName, string $siteName, string $status = 'online'): TacticalAsset
    {
        return TacticalAsset::create([
            'agent_id' => $agentId,
            'hostname' => $agentId,
            'client_name' => $clientName,
            'site_name' => $siteName,
            'status' => $status,
            'synced_at' => now()->subDay(),
        ]);
    }

    // ── The defect the issue was filed against ──────────────────────────────

    public function test_client_scoped_sync_deactivates_a_device_absent_upstream(): void
    {
        $client = $this->client('Acme|Main');
        $gone = $this->row('AGENT-GONE', 'Acme', 'Main');

        $result = $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])
            ->syncDevices($client->id);

        $this->assertSame(1, $result->deactivated, 'A client-scoped sync must be able to deactivate.');
        $this->assertSame('offline', $gone->refresh()->status);
    }

    public function test_client_scoped_sync_bumps_synced_at_on_the_swept_row(): void
    {
        $client = $this->client('Acme|Main');
        $gone = $this->row('AGENT-GONE', 'Acme', 'Main');
        $before = $gone->synced_at;

        $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])->syncDevices($client->id);

        // "Checked just now and still absent" must be distinguishable from
        // "never re-checked" at the row — the issue's second consequence.
        $this->assertTrue($gone->refresh()->synced_at->gt($before));
    }

    // ── The hazard the old gate existed to prevent ──────────────────────────

    public function test_client_scoped_sync_does_not_touch_another_clients_rows(): void
    {
        $acme = $this->client('Acme|Main');
        $this->client('Beta|HQ');
        $theirs = $this->row('AGENT-BETA', 'Beta', 'HQ');

        // AGENT-BETA is absent from the payload too, but this run is Acme's and
        // has no standing to speak for Beta.
        $result = $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])
            ->syncDevices($acme->id);

        $this->assertSame(0, $result->deactivated);
        $this->assertSame('online', $theirs->refresh()->status);
    }

    public function test_client_scoped_sync_sweeps_nothing_when_the_client_has_no_mapped_site(): void
    {
        $this->client('Acme|Main');
        $unmapped = Client::factory()->create([
            'tactical_site_id' => null, 'is_active' => true, 'stage' => ClientStage::Active,
        ]);
        $row = $this->row('AGENT-GONE', 'Acme', 'Main');

        $result = $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])
            ->syncDevices($unmapped->id);

        $this->assertSame(0, $result->deactivated, 'No mapped site must sweep nothing, not everything.');
        $this->assertSame('online', $row->refresh()->status);
    }

    // ── The predicate widening, which also fixes the FULL sync ──────────────

    public function test_full_sync_does_not_deactivate_an_agent_present_upstream_under_an_unmapped_site(): void
    {
        $this->client('Acme|Main');
        // Ghost|X is a live Tactical site whose PSA client left stage=Active, or
        // whose site was renamed. Its agents are reporting normally.
        $live = $this->row('AGENT-GHOST', 'Ghost', 'X');

        $result = $this->syncService([
            $this->agent('AGENT-LIVE', 'Acme', 'Main'),
            $this->agent('AGENT-GHOST', 'Ghost', 'X'),
        ])->syncDevices();

        $this->assertSame(0, $result->deactivated, 'An agent in the payload was observed; it is not offline.');
        $this->assertSame('online', $live->refresh()->status);
    }

    public function test_full_sync_still_deactivates_an_agent_absent_upstream(): void
    {
        $this->client('Acme|Main');
        $gone = $this->row('AGENT-GONE', 'Acme', 'Main');

        $result = $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])->syncDevices();

        $this->assertSame(1, $result->deactivated);
        $this->assertSame('offline', $gone->refresh()->status);
    }

    // ── Decisions the fix must not disturb ──────────────────────────────────

    public function test_sweep_does_not_run_when_the_upstream_fetch_failed(): void
    {
        $client = $this->client('Acme|Main');
        $row = $this->row('AGENT-GONE', 'Acme', 'Main');

        $result = $this->failingSyncService()->syncDevices($client->id);

        $this->assertSame(0, $result->deactivated, 'A failed fetch proves nothing about any agent.');
        $this->assertSame('online', $row->refresh()->status);
    }

    public function test_sweep_leaves_the_linked_asset_connectivity_untouched(): void
    {
        $client = $this->client('Acme|Main');
        $asset = Asset::factory()->create([
            'client_id' => $client->id, 'hostname' => 'AGENT-GONE', 'rmm_online' => true,
        ]);
        $row = $this->row('AGENT-GONE', 'Acme', 'Main');
        $row->update(['asset_id' => $asset->id]);

        $this->syncService([$this->agent('AGENT-LIVE', 'Acme', 'Main')])->syncDevices($client->id);

        // "Absent from this run's payload" is UNKNOWN, not offline: the AGENT
        // snapshot degrades, the ASSET's connectivity flag is not overwritten.
        $this->assertSame('offline', $row->refresh()->status);
        $this->assertTrue((bool) $asset->refresh()->rmm_online);
    }
}
