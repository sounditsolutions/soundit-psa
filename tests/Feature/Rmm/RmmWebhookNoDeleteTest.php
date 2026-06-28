<?php

namespace Tests\Feature\Rmm;

use App\Jobs\ProcessLevelWebhook;
use App\Jobs\ProcessNinjaWebhook;
use App\Models\Asset;
use App\Models\Client;
use App\Models\LevelWebhook;
use App\Models\NinjaWebhook;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Level\LevelSyncService;
use App\Services\Ninja\NinjaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-u97k (webhook arm): device-deleted webhook events must NEVER soft-delete
 * or deactivate the shared PSA Asset — they must only unlink that RMM's vendor
 * fields.  The Asset may still be managed by another RMM (e.g. Tactical).
 *
 * These are the real-time counterparts to the polling-sync regression tests in
 * RmmOrphanNoDeleteTest.
 */
class RmmWebhookNoDeleteTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Ninja — NODE_DELETED webhook
    // -----------------------------------------------------------------------

    /**
     * A NODE_DELETED webhook must clear only ninja_* fields.
     * The Asset must NOT be soft-deleted; is_active must remain true;
     * any other RMM link (tactical_asset_id) must be untouched.
     */
    public function test_ninja_node_deleted_webhook_unlinks_not_deletes(): void
    {
        // Enable Ninja for this test — it verifies real webhook processing behaviour,
        // not the disabled-gate path (that is covered by NinjaDisabledTest).
        Setting::setValue('ninja_enabled', '1');

        $client = Client::factory()->create();

        // Asset has both a Ninja link and a Tactical link
        $ta = TacticalAsset::create([
            'agent_id' => 'tac-agent-webhook-001',
            'hostname' => 'WEBHOOK-PC',
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'ninja_id' => 555,
            'ninja_url' => 'https://app.ninjarmm.com/devices/555',
            'ninja_synced_at' => now(),
            'tactical_asset_id' => $ta->id,
            'is_active' => true,
        ]);

        // Create a pending NODE_DELETED webhook for this device
        $webhook = NinjaWebhook::create([
            'activity_type' => 'NODE_DELETED',
            'ninja_device_id' => 555,
            'payload' => ['deviceId' => 555, 'activityType' => 'NODE_DELETED'],
            'status' => 'pending',
        ]);

        // Run the real job synchronously
        (new ProcessNinjaWebhook($webhook->id))->handle(app(NinjaSyncService::class));

        $fresh = Asset::withTrashed()->find($asset->id);

        // Asset must still exist and NOT be trashed
        $this->assertNotNull($fresh, 'Asset record must still exist in DB after NODE_DELETED webhook');
        $this->assertFalse($fresh->trashed(), 'Asset must NOT be soft-deleted by NODE_DELETED webhook');

        // Ninja vendor fields cleared
        $this->assertNull($fresh->ninja_id, 'ninja_id must be set to null');
        $this->assertNull($fresh->ninja_url, 'ninja_url must be set to null');
        $this->assertNull($fresh->ninja_synced_at, 'ninja_synced_at must be set to null');

        // Other RMM link and active flag are untouched
        $this->assertSame($ta->id, $fresh->tactical_asset_id, 'tactical_asset_id must be preserved');
        $this->assertTrue((bool) $fresh->is_active, 'is_active must remain true');

        // Webhook is marked processed
        $this->assertSame('processed', $webhook->fresh()->status, 'Webhook must be marked processed');
    }

    // -----------------------------------------------------------------------
    // Level — device_deleted webhook
    // -----------------------------------------------------------------------

    /**
     * A device_deleted webhook must clear only level_* fields.
     * The Asset must NOT be soft-deleted; is_active must remain true;
     * any other RMM link (tactical_asset_id) must be untouched.
     */
    public function test_level_device_deleted_webhook_unlinks_not_deletes(): void
    {
        $client = Client::factory()->create();

        // Asset has both a Level link and a Tactical link
        $ta = TacticalAsset::create([
            'agent_id' => 'tac-agent-webhook-002',
            'hostname' => 'WEBHOOK-LEVEL-PC',
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'level_id' => 'level-device-abc',
            'level_url' => 'https://app.level.io/devices/level-device-abc',
            'level_synced_at' => now(),
            'tactical_asset_id' => $ta->id,
            'is_active' => true,
        ]);

        // Create a pending device_deleted webhook for this device
        $webhook = LevelWebhook::create([
            'event_type' => 'device_deleted',
            'level_device_id' => 'level-device-abc',
            'payload' => ['data' => ['id' => 'level-device-abc']],
            'status' => 'pending',
        ]);

        // Run the real job synchronously
        (new ProcessLevelWebhook($webhook->id))->handle(app(LevelSyncService::class));

        $fresh = Asset::withTrashed()->find($asset->id);

        // Asset must still exist and NOT be trashed
        $this->assertNotNull($fresh, 'Asset record must still exist in DB after device_deleted webhook');
        $this->assertFalse($fresh->trashed(), 'Asset must NOT be soft-deleted by device_deleted webhook');

        // Level vendor fields cleared
        $this->assertNull($fresh->level_id, 'level_id must be set to null');
        $this->assertNull($fresh->level_url, 'level_url must be set to null');
        $this->assertNull($fresh->level_synced_at, 'level_synced_at must be set to null');

        // Other RMM link and active flag are untouched
        $this->assertSame($ta->id, $fresh->tactical_asset_id, 'tactical_asset_id must be preserved');
        $this->assertTrue((bool) $fresh->is_active, 'is_active must remain true');

        // Webhook is marked processed
        $this->assertSame('processed', $webhook->fresh()->status, 'Webhook must be marked processed');
    }
}
