<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetLitsrmmClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_same_write_replacement_retired_stamp_is_preserved(): void
    {
        $this->assertSameWriteStampPreserved(true);
    }

    public function test_same_write_new_retired_stamp_is_preserved(): void
    {
        $this->assertSameWriteStampPreserved(false);
    }

    private function assertSameWriteStampPreserved(bool $previouslyRetired): void
    {
        $asset = $this->retiredAsset();
        if (! $previouslyRetired) {
            $asset->forceFill(['litsrmm_retired_at' => null])->save();
        }
        $asset = $asset->fresh();
        $asset->forceFill(['litsrmm_retired_at' => '2026-10-05 00:00:00']);
        $asset->endLitsrmmClaim();
        $asset->save();

        $asset->refresh();
        $this->assertSame('2026-10-05 00:00:00', $asset->litsrmm_retired_at?->format('Y-m-d H:i:s'));
        $this->assertSame('00000000-0000-4000-8000-000000000003', $asset->litsrmm_device_id);
        $this->assertSame('2026-10-01 00:00:00', $asset->litsrmm_synced_at?->format('Y-m-d H:i:s'));
    }

    public function test_litsrmm_claim_columns_are_not_mass_assignable(): void
    {
        $data = [
            'name' => 'Synthetic workstation',
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000001',
            'litsrmm_synced_at' => '2026-10-01 00:00:00',
            'litsrmm_retired_at' => '2026-10-01 00:00:00',
        ];
        $asset = Asset::create($data);
        foreach (['litsrmm_device_id', 'litsrmm_synced_at', 'litsrmm_retired_at'] as $column) {
            $this->assertNull($asset->fresh()->getAttribute($column));
        }
        $asset->fill($data)->save();
        foreach (['litsrmm_device_id', 'litsrmm_synced_at', 'litsrmm_retired_at'] as $column) {
            $this->assertNull($asset->fresh()->getAttribute($column));
        }
        $asset->forceFill($data)->save();
        foreach (['litsrmm_device_id', 'litsrmm_synced_at', 'litsrmm_retired_at'] as $column) {
            $this->assertNotNull($asset->fresh()->getAttribute($column));
        }
    }

    private function retiredAsset(): Asset
    {
        return Asset::factory()->create([
            'is_active' => false,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000003',
            'litsrmm_synced_at' => '2026-10-01 00:00:00',
            'litsrmm_retired_at' => '2026-10-01 00:00:00',
        ]);
    }

    private function assertClaimEnded(Asset $asset): void
    {
        $asset->refresh();
        $this->assertTrue($asset->is_active);
        $this->assertNull($asset->litsrmm_device_id);
        $this->assertNull($asset->litsrmm_synced_at);
        $this->assertNull($asset->litsrmm_retired_at);
    }

    public function test_person_update_ends_claim_only_when_status_changes(): void
    {
        $asset = $this->retiredAsset();
        $service = app(\App\Services\AssetService::class);
        $service->updateAsset($asset, ['name' => 'Synthetic renamed asset']);
        $this->assertNotNull($asset->fresh()->litsrmm_retired_at);
        $service->updateAsset($asset->fresh(), ['is_active' => false]);
        $this->assertNotNull($asset->fresh()->litsrmm_retired_at);
        $service->updateAsset($asset->fresh(), ['is_active' => true]);
        $this->assertClaimEnded($asset);
    }

    public function test_web_restore_ends_claim(): void
    {
        $asset = $this->retiredAsset();
        $asset->delete();
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)->post(route('assets.restore', $asset->id))->assertRedirect();
        $this->assertClaimEnded($asset);
        $this->assertFalse($asset->trashed());
    }

    public function test_mcp_restore_ends_claim(): void
    {
        $asset = $this->retiredAsset();
        $asset->delete();
        $actor = \App\Models\User::factory()->create();
        \App\Models\Setting::setValue('triage_system_user_id', (string) $actor->id);
        $bearer = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['restore_asset'], label: 'synthetic');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'restore_asset', 'arguments' => ['asset_id' => $asset->id]],
        ]);
        $response->assertOk();
        $this->assertClaimEnded($asset);
        $this->assertFalse($asset->trashed());
    }

    public function test_explicit_new_link_survives_ending_claim(): void
    {
        $asset = $this->retiredAsset();
        $asset->forceFill(['litsrmm_device_id' => '00000000-0000-4000-8000-000000000004']);
        $asset->endLitsrmmClaim();
        $asset->save();
        $this->assertNull($asset->fresh()->litsrmm_retired_at);
        $this->assertSame('00000000-0000-4000-8000-000000000004', $asset->fresh()->litsrmm_device_id);
        $this->assertNotNull($asset->fresh()->litsrmm_synced_at);
    }

    public function test_vendor_reactivation_preserves_the_sync_claim(): void
    {
        $asset = Asset::factory()->create([
            'is_active' => false,
            'litsrmm_device_id' => '00000000-0000-4000-8000-000000000002',
            'litsrmm_synced_at' => '2026-10-01 00:00:00',
            'litsrmm_retired_at' => '2026-10-01 00:00:00',
        ]);
        $asset->update(['is_active' => true]);
        $asset->refresh();
        $this->assertNotNull($asset->litsrmm_retired_at);
        $this->assertSame('00000000-0000-4000-8000-000000000002', $asset->litsrmm_device_id);
        $this->assertSame('2026-10-01 00:00:00', $asset->litsrmm_synced_at->format('Y-m-d H:i:s'));
    }
}
