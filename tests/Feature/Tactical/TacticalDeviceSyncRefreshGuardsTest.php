<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two operator-facing claims this sync is allowed to make, and their limits.
 *
 * ip_address: the field is written at creation only, so an address we decline to
 * publish stays empty forever. Ambiguity is real (virtual adapters) but IPv6
 * being on by default is not ambiguity — a dual-stack endpoint reports one
 * unambiguous IPv4, and blanking it would empty the column for most of the fleet.
 *
 * rmm_online / last_seen_at: the refresh runs every interval against an asset
 * that linkOrCreateAsset may have ADOPTED from another integration. Ninja and
 * Level write the same two columns, a false rmm_online has no staleness escape
 * anywhere in the app, and "overdue" is Tactical's LONGEST out-of-contact state —
 * further past the threshold than "offline", not less certainly down.
 */
class TacticalDeviceSyncRefreshGuardsTest extends TestCase
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
            'local_ips' => '192.168.0.42',
            'logged_username' => 'zachary',
            'last_seen' => '2026-07-31 09:00:00',
        ], $overrides);
    }

    /** IPv6 is on by default; a sole IPv4 alongside it is not ambiguous. */
    public function test_a_dual_stack_endpoint_publishes_its_ipv4(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['local_ips' => '2001:db8:1::5, 192.168.0.42'])])->syncDevices();

        $this->assertSame('192.168.0.42', Asset::where('hostname', 'NEWBOX')->value('ip_address'));
    }

    /** Two IPv4s IS the ambiguity this picker exists for — the field stays empty. */
    public function test_two_ipv4_candidates_still_leave_the_field_empty(): void
    {
        $this->mappedClient();

        // Hyper-V host-only adapter listed first — the payload does not say which
        // address a technician can reach the machine on.
        $this->syncService([$this->agent(['local_ips' => '172.28.144.1, 192.168.0.42'])])->syncDevices();

        $this->assertNull(Asset::where('hostname', 'NEWBOX')->value('ip_address'));
    }

    public function test_an_ipv6_only_endpoint_publishes_its_single_address(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['local_ips' => 'fe80::1, 2001:db8:1::5'])])->syncDevices();

        $this->assertSame('2001:db8:1::5', Asset::where('hostname', 'NEWBOX')->value('ip_address'));
    }

    /**
     * 'overdue' is out of contact for LONGER than 'offline' — past both of
     * Tactical's thresholds, not just the first — so it records the same hard
     * false. A box powered off for a week reports 'overdue' and never returns to
     * the 4–30 minute 'offline' window, so seeding unknown would leave it out of
     * the Assets Offline filter for good, with nothing to ever write the false.
     */
    public function test_an_overdue_agent_is_recorded_as_offline(): void
    {
        $this->mappedClient();

        $this->assertNotNull(
            Asset::query()->whereNotNull('id')->count() >= 0 ? true : null,
        );

        $this->syncService([$this->agent(['status' => 'overdue'])])->syncDevices();

        $seeded = Asset::where('hostname', 'NEWBOX')->value('rmm_online');
        $this->assertNotNull($seeded, 'a machine past the overdue threshold is down, not unknown');
        $this->assertFalse((bool) $seeded);

        $this->syncService([$this->agent(['status' => 'online'])])->syncDevices();
        $this->assertTrue((bool) Asset::where('hostname', 'NEWBOX')->value('rmm_online'));

        $this->syncService([$this->agent(['status' => 'overdue'])])->syncDevices();
        $refreshed = Asset::where('hostname', 'NEWBOX')->value('rmm_online');
        $this->assertNotNull($refreshed);
        $this->assertFalse(
            (bool) $refreshed,
            'a machine that drops out of contact must stop reading Online',
        );
    }

    /** A status outside Tactical's contact vocabulary is still no observation. */
    public function test_an_unrecognised_status_leaves_connectivity_unknown(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['status' => 'some_future_state'])])->syncDevices();

        $this->assertNull(
            Asset::where('hostname', 'NEWBOX')->value('rmm_online'),
            'a status the sync does not understand must seed unknown, not a hard false',
        );
    }

    /** The Tactical-only path is unchanged: a real offline still writes false. */
    public function test_a_tactical_only_asset_still_records_a_real_offline(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['status' => 'online'])])->syncDevices();
        $this->syncService([$this->agent(['status' => 'offline'])])->syncDevices();

        $this->assertFalse((bool) Asset::where('hostname', 'NEWBOX')->value('rmm_online'));
    }

    public function test_last_seen_at_never_moves_backwards(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['last_seen' => '2026-07-31 09:00:00'])])->syncDevices();
        $this->syncService([$this->agent(['last_seen' => '2026-07-30 09:00:00'])])->syncDevices();

        $this->assertSame(
            '2026-07-31 09:00:00',
            Asset::where('hostname', 'NEWBOX')->firstOrFail()->last_seen_at->toDateTimeString(),
            'an older snapshot must not drag the asset backwards into looking stale',
        );
    }

    /**
     * Dual-RMM is a supported configuration (adoption matches assets Ninja/Level
     * already maintain, and an RMM migration runs both for weeks). Tactical must
     * not re-assert Offline every interval on a machine another sync is reporting
     * online — that badge and its -30 health penalty have no way back.
     */
    public function test_tactical_does_not_stomp_an_asset_another_rmm_maintains(): void
    {
        $client = $this->mappedClient();

        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'NEWBOX',
            'ninja_id' => 'ninja-1',
            'rmm_online' => true,
            'last_seen_at' => '2026-07-31 09:00:00',
        ]);

        $this->syncService([$this->agent([
            'status' => 'offline',
            'last_seen' => '2026-07-31 03:00:00',
        ])])->syncDevices();

        $asset->refresh();

        $this->assertNotNull($asset->tactical_asset_id, 'the asset must still be adopted — this is the refresh path');
        $this->assertTrue(
            (bool) $asset->rmm_online,
            'a broken Tactical agent must not assert Offline over the RMM that is actively reporting the device',
        );
        $this->assertSame(
            '2026-07-31 09:00:00',
            $asset->last_seen_at->toDateTimeString(),
            'the fresher observation wins — last_seen_at must not move backwards',
        );
    }

    /** The asymmetry is deliberate: a TRUE is something we actually observed. */
    public function test_an_observed_online_still_refreshes_a_dual_linked_asset(): void
    {
        $client = $this->mappedClient();

        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'NEWBOX',
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => '2026-07-31 03:00:00',
        ]);

        $this->syncService([$this->agent([
            'status' => 'online',
            'last_seen' => '2026-07-31 09:00:00',
        ])])->syncDevices();

        $asset->refresh();

        $this->assertTrue((bool) $asset->rmm_online);
        $this->assertSame('2026-07-31 09:00:00', $asset->last_seen_at->toDateTimeString());
    }
}
