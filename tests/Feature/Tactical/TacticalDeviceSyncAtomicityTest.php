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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The two highest-severity #333 retro findings — diff:1 (create and back-links
 * not atomic) and diff:2 (resolve-or-create race) — shipped their fixes with no
 * guard of their own: the retro's suite covers the serial/IP/disk/connectivity
 * findings and stops there. Both of these fail in ways nothing heals — a
 * half-linked asset needs manual DB repair, and a forked device becomes two
 * billable, client-facing rows — so they get guards before they land.
 *
 * A true two-process race cannot run in PHPUnit. What CAN be pinned is each
 * half of the guarantee: that the write is atomic, and that the serialization
 * is actually taken and honoured on the exact key it claims.
 */
class TacticalDeviceSyncAtomicityTest extends TestCase
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

    /** @return array<string, mixed> */
    private function agent(): array
    {
        return [
            'agent_id' => 'AGENT-NEW',
            'hostname' => 'NEWBOX',
            'client_name' => 'Acme',
            'site_name' => 'Main',
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'local_ips' => '192.168.0.42',
            'last_seen' => '2026-07-29 12:00:00',
        ];
    }

    /**
     * diff:1 — the create and BOTH back-links are one transaction.
     *
     * A failure after the asset exists but before the links are written (deploy
     * restart, DB timeout, an observer throwing) used to leave
     * assets.tactical_asset_id set with tactical_assets.asset_id NULL. No later
     * run can heal that: the link query skips linked assets, and the conflict
     * query refuses to create on them. The device is permanently invisible and
     * only a human with DB access can fix it.
     *
     * Throwing from the Asset `updated` event puts the failure exactly there —
     * after the row is created, mid-link.
     */
    public function test_a_failure_mid_link_leaves_no_orphaned_asset(): void
    {
        $this->mappedClient();

        Asset::updated(function (): void {
            throw new \RuntimeException('link write blew up');
        });

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(
            0,
            Asset::withTrashed()->count(),
            'the created asset must roll back with the failed link, not survive it half-linked'
        );

        $ta = TacticalAsset::where('agent_id', 'AGENT-NEW')->first();
        $this->assertNotNull($ta, 'the agent snapshot is written before the link and is expected to survive');
        $this->assertNull($ta->asset_id, 'no back-link may be left pointing at a rolled-back asset');

        // Per-agent isolation (context:2): the failure is reported, not swallowed,
        // and the run continues rather than aborting.
        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('AGENT-NEW', $result->errorMessages[0] ?? '');
        $this->assertSame(0, $result->details['assets_created'] ?? 0, 'a rolled-back create must not be counted to the operator');
    }

    /**
     * diff:2 — resolve-or-create is serialized per (client, hostname).
     *
     * Holding the lock the sync would take stands in for the process that won
     * the race. The loser must DEFER — not create a second asset for the same
     * device, and not report an error either, because deferring to the next tick
     * is the designed outcome, not a fault.
     *
     * The key is asserted literally: it is the contract between two processes
     * that never see each other, so a silent change to its shape would disable
     * the serialization while every other test stayed green.
     */
    public function test_a_held_lock_defers_the_link_instead_of_forking_the_device(): void
    {
        $client = $this->mappedClient();

        $lock = Cache::lock('tactical-sync:asset:'.$client->id.':'.sha1('newbox'), 60);
        $this->assertTrue($lock->get(), 'the test must hold the lock before the sync runs');

        try {
            $result = $this->syncService([$this->agent()])->syncDevices();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Asset::count(), 'the loser of the race must not create a second asset for the device');
        $this->assertSame(0, $result->errors, 'deferring to the next run is the designed outcome, not an error');

        $ta = TacticalAsset::where('agent_id', 'AGENT-NEW')->firstOrFail();
        $this->assertNull($ta->asset_id);
    }

    /**
     * The other half of diff:2: once the lock is free, the deferred agent links
     * on the next run. A guard that only proves the sync backs off would be
     * satisfied by a sync that never links at all.
     */
    public function test_the_deferred_agent_links_on_the_next_run(): void
    {
        $client = $this->mappedClient();

        $lock = Cache::lock('tactical-sync:asset:'.$client->id.':'.sha1('newbox'), 60);
        $lock->get();
        $this->syncService([$this->agent()])->syncDevices();
        $lock->release();

        $result = $this->syncService([$this->agent()])->syncDevices();

        $asset = Asset::where('hostname', 'NEWBOX')->firstOrFail();
        $ta = TacticalAsset::where('agent_id', 'AGENT-NEW')->firstOrFail();

        $this->assertSame($ta->id, $asset->tactical_asset_id);
        $this->assertSame($asset->id, $ta->asset_id);
        $this->assertSame(1, $result->details['assets_created'] ?? 0);
    }
}
