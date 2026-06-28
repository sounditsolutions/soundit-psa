<?php

namespace Tests\Feature\Rmm;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Services\AssetService;
use App\Services\Level\LevelClient;
use App\Services\Level\LevelSyncService;
use App\Services\Ninja\NinjaClient;
use App\Services\Ninja\NinjaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-u97k: Regression suite for "RMM device removal must NOT auto-delete the shared PSA Asset".
 *
 * A device leaving an RMM (Ninja/Level) should result in ONLY that RMM's own
 * vendor-prefixed fields being cleared — never a soft-delete, never is_active=false,
 * never touching other RMM links (tactical_asset_id etc.).
 *
 * The deliberate operator offboard path (AssetService::deleteAsset) remains the
 * only place an Asset is ever soft-deleted.
 */
class RmmOrphanNoDeleteTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Ninja tests
    // -----------------------------------------------------------------------

    /**
     * Test 1 (load-bearing regression): a Ninja orphan that still has a Tactical
     * link MUST NOT be soft-deleted. Its ninja_id/url/synced_at are cleared; its
     * tactical_asset_id and is_active are untouched.
     */
    public function test_ninja_orphan_with_tactical_link_is_not_deleted(): void
    {
        $client = Client::factory()->create(['ninja_org_id' => 42]);

        // Live asset — Ninja API will return this device in the sync
        $liveAsset = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 101,
        ]);

        // Orphan asset — Ninja API will NOT return this; it remains Tactical-managed
        $ta = TacticalAsset::create([
            'agent_id' => 'tac-agent-001',
            'hostname' => 'ORPHAN-PC',
        ]);
        $orphan = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 999,
            'tactical_asset_id' => $ta->id,
            'is_active' => true,
        ]);

        // Mock NinjaClient: returns only the live device; enrichment returns minimal detail
        $this->mock(NinjaClient::class, function (MockInterface $m): void {
            $m->shouldReceive('getOrganizationDevices')
                ->with(42)
                ->andReturn([
                    ['id' => 101, 'systemName' => 'LIVE-PC', 'nodeRoleId' => 1, 'nodeClass' => 'WINDOWS_WORKSTATION'],
                ]);

            $m->shouldReceive('getDeviceDetail')
                ->with(101)
                ->andReturn([]);
        });

        app(NinjaSyncService::class)->syncDevicesForClient($client);

        // Orphan must NOT be soft-deleted
        $fresh = Asset::withTrashed()->find($orphan->id);
        $this->assertNotNull($fresh, 'Orphan asset record must still exist in DB');
        $this->assertFalse($fresh->trashed(), 'Orphan must NOT be soft-deleted by the sync');

        // Ninja fields cleared
        $this->assertNull($fresh->ninja_id, 'ninja_id must be set to null');
        $this->assertNull($fresh->ninja_url, 'ninja_url must be set to null');

        // Tactical link and active flag unchanged
        $this->assertSame($ta->id, $fresh->tactical_asset_id, 'tactical_asset_id must be preserved');
        $this->assertTrue((bool) $fresh->is_active, 'is_active must remain true');

        // Live asset retains its ninja_id
        $this->assertSame(101, (int) Asset::find($liveAsset->id)->ninja_id, 'Live asset must keep its ninja_id');
    }

    /**
     * Test 2: a Ninja orphan with NO other RMM link is also never deleted.
     * The design correction: syncs never delete — not even "orphan-only" assets.
     */
    public function test_ninja_orphan_with_no_other_rmm_link_is_not_deleted(): void
    {
        $client = Client::factory()->create(['ninja_org_id' => 43]);

        $liveAsset = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 201,
        ]);

        // Orphan has only a ninja_id — no Tactical/Level/other link
        $orphan = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 888,
            'is_active' => true,
        ]);

        $this->mock(NinjaClient::class, function (MockInterface $m): void {
            $m->shouldReceive('getOrganizationDevices')
                ->with(43)
                ->andReturn([
                    ['id' => 201, 'systemName' => 'LIVE-PC-2', 'nodeRoleId' => 1],
                ]);

            $m->shouldReceive('getDeviceDetail')
                ->with(201)
                ->andReturn([]);
        });

        app(NinjaSyncService::class)->syncDevicesForClient($client);

        $fresh = Asset::withTrashed()->find($orphan->id);
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->trashed(), 'Orphan with no other RMM link must NOT be soft-deleted');
        $this->assertNull($fresh->ninja_id, 'ninja_id must be cleared');
        $this->assertTrue((bool) $fresh->is_active, 'is_active must remain true');
    }

    /**
     * Test 3: an empty remote fetch (failed/empty API response) must NOT clear
     * any ninja_id fields — the non-empty-remote guard prevents a bad fetch from
     * wiping every link.
     */
    public function test_ninja_empty_fetch_does_not_clear_ninja_id(): void
    {
        $client = Client::factory()->create(['ninja_org_id' => 44]);

        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 777,
        ]);

        $this->mock(NinjaClient::class, function (MockInterface $m): void {
            $m->shouldReceive('getOrganizationDevices')
                ->with(44)
                ->andReturn([]);
        });

        app(NinjaSyncService::class)->syncDevicesForClient($client);

        // ninja_id must be unchanged — guard kicked in
        $this->assertSame(777, (int) Asset::find($asset->id)->ninja_id, 'ninja_id must be unchanged after empty fetch');
    }

    // -----------------------------------------------------------------------
    // Level tests
    // -----------------------------------------------------------------------

    /**
     * Test 4: a Level orphan that still has a Tactical link MUST NOT be
     * soft-deleted. Its level_id/url/synced_at are cleared; tactical_asset_id
     * and is_active are untouched.
     */
    public function test_level_orphan_with_tactical_link_is_not_deleted(): void
    {
        $client = Client::factory()->create(['level_group_id' => 'group-abc']);

        $liveAsset = Asset::factory()->create([
            'client_id' => $client->id,
            'level_id' => 'live-device-1',
        ]);

        $ta = TacticalAsset::create([
            'agent_id' => 'tac-level-001',
            'hostname' => 'ORPHAN-LEVEL-PC',
        ]);
        $orphan = Asset::factory()->create([
            'client_id' => $client->id,
            'level_id' => 'orphan-device-x',
            'tactical_asset_id' => $ta->id,
            'is_active' => true,
        ]);

        $this->mock(LevelClient::class, function (MockInterface $m): void {
            $m->shouldReceive('getDevices')
                ->with('group-abc')
                ->andReturn([
                    [
                        'id' => 'live-device-1',
                        'hostname' => 'LIVE-LEVEL-PC',
                        'online' => true,
                    ],
                ]);
        });

        app(LevelSyncService::class)->syncDevicesForClient($client);

        $fresh = Asset::withTrashed()->find($orphan->id);
        $this->assertNotNull($fresh, 'Orphan asset record must still exist in DB');
        $this->assertFalse($fresh->trashed(), 'Orphan must NOT be soft-deleted by Level sync');

        $this->assertNull($fresh->level_id, 'level_id must be set to null');
        $this->assertNull($fresh->level_url, 'level_url must be set to null');

        $this->assertSame($ta->id, $fresh->tactical_asset_id, 'tactical_asset_id must be preserved');
        $this->assertTrue((bool) $fresh->is_active, 'is_active must remain true');

        $this->assertSame('live-device-1', Asset::find($liveAsset->id)->level_id, 'Live asset must keep its level_id');
    }

    // -----------------------------------------------------------------------
    // Deliberate offboard path
    // -----------------------------------------------------------------------

    /**
     * Test 5: AssetService::deleteAsset() is the SOLE soft-delete path.
     * Syncs never delete; this operator action does.
     */
    public function test_delete_asset_still_soft_deletes_asset(): void
    {
        $asset = Asset::factory()->create();

        app(AssetService::class)->deleteAsset($asset);

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
    }
}
