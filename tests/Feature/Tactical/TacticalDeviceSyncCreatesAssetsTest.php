<?php

namespace Tests\Feature\Tactical;

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
 * Device sync is a DISCOVERY source, not only an enricher: an agent on a mapped
 * site with no matching PSA asset gets one created and linked, the same way the
 * Level and Ninja syncs seed assets. Before this, such an agent produced a
 * tactical_assets row with asset_id = NULL and no surface anywhere in the UI —
 * the sync reported "2 created, 0 linked" and the operator saw nothing.
 */
class TacticalDeviceSyncCreatesAssetsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $agents */
    private function syncService(array $agents): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($agents))])),
            'timeout' => 30,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'AGENT-NEW',
            'hostname' => 'NEWBOX',
            'client_name' => 'Acme',
            'site_name' => 'Main',
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'serial_number' => 'SN-123',
            'cpu_model' => ['Intel Core i7-9700'],
            'physical_disks' => ['SAMSUNG SSD 500GB'],
            'local_ips' => '192.168.0.42',
            'logged_username' => 'zachary',
            'last_seen' => '2026-07-29 12:00:00',
            'needs_reboot' => true,
        ], $overrides);
    }

    public function test_unmatched_agent_gets_a_psa_asset_created_and_linked(): void
    {
        $client = $this->mappedClient();

        $result = $this->syncService([$this->agent()])->syncDevices();

        $asset = Asset::where('client_id', $client->id)->firstOrFail();
        $ta = TacticalAsset::where('agent_id', 'AGENT-NEW')->firstOrFail();

        $this->assertSame($ta->id, $asset->tactical_asset_id, 'asset must point at the tactical row');
        $this->assertSame($asset->id, $ta->asset_id, 'tactical row must point back at the asset');
        $this->assertSame(1, $result->details['assets_created'] ?? 0);
        $this->assertSame(1, $result->details['linked'] ?? 0);
    }

    public function test_created_asset_carries_the_agent_snapshot(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent()])->syncDevices();

        $asset = Asset::where('hostname', 'NEWBOX')->firstOrFail();

        $this->assertSame('NEWBOX', $asset->name);
        $this->assertSame('Windows 11 Pro, 64 bit', $asset->os);
        $this->assertSame('SN-123', $asset->serial_number);
        $this->assertSame('Intel Core i7-9700', $asset->cpu);
        $this->assertSame('SAMSUNG SSD 500GB', $asset->disk_summary);
        $this->assertSame('192.168.0.42', $asset->ip_address);
        $this->assertSame('zachary', $asset->last_user);
        $this->assertSame('Windows Workstation', $asset->asset_type);
        $this->assertTrue((bool) $asset->is_active);
        $this->assertTrue((bool) $asset->rmm_online);
        $this->assertTrue((bool) $asset->needs_reboot);
        $this->assertNotNull($asset->last_seen_at);
    }

    public function test_server_agent_maps_to_a_server_asset_type(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['monitoring_type' => 'server'])])->syncDevices();

        $this->assertSame('Windows Server', Asset::where('hostname', 'NEWBOX')->value('asset_type'));
    }

    public function test_mac_agent_maps_to_a_mac_asset_type(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent([
            'plat' => 'darwin',
            'operating_system' => 'Darwin 15.4 arm64',
        ])])->syncDevices();

        $this->assertSame('Mac', Asset::where('hostname', 'NEWBOX')->value('asset_type'));
    }

    public function test_unknown_platform_leaves_asset_type_null(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent([
            'plat' => null,
            'operating_system' => null,
            'monitoring_type' => null,
        ])])->syncDevices();

        $this->assertNull(Asset::where('hostname', 'NEWBOX')->value('asset_type'));
    }

    public function test_existing_asset_is_linked_not_duplicated(): void
    {
        $client = $this->mappedClient();
        Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'newbox', 'name' => 'Reception PC']);

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, Asset::where('client_id', $client->id)->count(), 'must reuse the existing asset');
        $this->assertSame('Reception PC', Asset::first()->name, 'linking must not rewrite the operator name');
        $this->assertSame(1, $result->details['linked'] ?? 0);
        $this->assertArrayNotHasKey('assets_created', $result->details);
    }

    public function test_hostname_already_linked_to_another_agent_does_not_duplicate(): void
    {
        $client = $this->mappedClient();
        // Agent reinstall: same box, new agent_id. The old TacticalAsset still owns
        // the asset, so the hostname match is filtered out of the link query —
        // creating here would fork one device into two assets.
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX']);
        $old = TacticalAsset::create([
            'agent_id' => 'AGENT-OLD', 'hostname' => 'NEWBOX', 'asset_id' => $asset->id,
            'status' => 'offline', 'synced_at' => now()->subDay(),
        ]);
        $asset->update(['tactical_asset_id' => $old->id]);

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, Asset::where('client_id', $client->id)->count());
        $this->assertArrayNotHasKey('assets_created', $result->details);
        $this->assertNull(TacticalAsset::where('agent_id', 'AGENT-NEW')->value('asset_id'));
    }

    public function test_soft_deleted_asset_with_the_same_hostname_is_not_duplicated(): void
    {
        $client = $this->mappedClient();
        Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX'])->delete();

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(0, Asset::where('client_id', $client->id)->count(), 'a retired asset must not be resurrected');
        $this->assertArrayNotHasKey('assets_created', $result->details);
    }

    public function test_agent_on_an_unmapped_site_creates_nothing(): void
    {
        $this->mappedClient();

        $result = $this->syncService([$this->agent(['client_name' => 'Other', 'site_name' => 'Nowhere'])])->syncDevices();

        $this->assertSame(0, Asset::count());
        $this->assertSame(0, TacticalAsset::count());
        $this->assertSame(0, $result->created);
    }

    public function test_agent_without_a_hostname_creates_no_asset(): void
    {
        $this->mappedClient();

        $result = $this->syncService([$this->agent(['hostname' => null])])->syncDevices();

        $this->assertSame(0, Asset::count());
        $this->assertSame(1, $result->created, 'the tactical row is still recorded');
        $this->assertArrayNotHasKey('assets_created', $result->details);
    }

    public function test_placeholder_oem_serial_is_not_written_to_the_asset(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['serial_number' => 'To Be Filled By O.E.M.'])])->syncDevices();

        $this->assertNull(
            Asset::where('hostname', 'NEWBOX')->value('serial_number'),
            'a placeholder serial must not seed the column Ninja/Level match on globally',
        );
    }

    public function test_ambiguous_local_ips_leave_the_asset_ip_empty(): void
    {
        $this->mappedClient();

        // Hyper-V host-only adapter listed first — element 0 is not "the IP".
        $this->syncService([$this->agent(['local_ips' => '172.28.144.1, 192.168.0.42'])])->syncDevices();

        $this->assertNull(Asset::where('hostname', 'NEWBOX')->value('ip_address'));
    }

    public function test_loopback_and_link_local_addresses_are_never_chosen(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['local_ips' => '127.0.0.1, 169.254.10.5, 192.168.0.42'])])->syncDevices();

        $this->assertSame('192.168.0.42', Asset::where('hostname', 'NEWBOX')->value('ip_address'));
    }

    public function test_long_disk_summary_is_fitted_to_the_asset_column(): void
    {
        $this->mappedClient();

        // assets.disk_summary is varchar(500); the agent snapshot column is TEXT.
        $disks = array_fill(0, 20, 'SAMSUNG MZVLB1T0HBLR-000L7 1TB NVMe SSD');

        $this->syncService([$this->agent(['physical_disks' => $disks])])->syncDevices();

        $this->assertSame(500, mb_strlen((string) Asset::where('hostname', 'NEWBOX')->value('disk_summary')));
    }

    public function test_connectivity_is_refreshed_on_every_sync_not_frozen_at_creation(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['status' => 'offline'])])->syncDevices();
        $this->assertFalse((bool) Asset::where('hostname', 'NEWBOX')->value('rmm_online'));

        $this->syncService([$this->agent(['status' => 'online'])])->syncDevices();
        $this->assertTrue((bool) Asset::where('hostname', 'NEWBOX')->value('rmm_online'));
    }

    public function test_agent_missing_from_the_payload_is_not_asserted_offline(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent()])->syncDevices();
        $this->assertTrue((bool) Asset::where('hostname', 'NEWBOX')->value('rmm_online'));

        // The agent is gone from the payload. That covers far more than "the box
        // is off": a Tactical site rename or a client leaving operational scope
        // unmaps a whole fleet, and an agent reinstall strands the old row under
        // its old agent_id. "Not covered by this run" is UNKNOWN, not offline —
        // the agent snapshot goes offline (it records what Tactical told us), but
        // the operator-facing asset keeps the last state we actually observed.
        $this->syncService([])->syncDevices();

        $this->assertSame('offline', TacticalAsset::where('agent_id', 'AGENT-NEW')->value('status'));

        $asset = Asset::where('hostname', 'NEWBOX')->firstOrFail();

        $this->assertTrue(
            (bool) $asset->rmm_online,
            'an unseen agent must not assert a hard Offline the sweep can never undo',
        );
        $this->assertNotSame(
            'Offline',
            $asset->status_badge,
            'the badge must degrade to Stale, not claim a machine we never observed is down',
        );
    }
}
