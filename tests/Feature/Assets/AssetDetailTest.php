<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_detail_200s_when_tactical_local_ips_is_a_string(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'test-agent-uuid',
            'hostname' => $asset->hostname,
        ]);

        // Simulate the seeder bug: double-encoded JSON ends up as a string after the cast decodes it.
        // Bypassing Eloquent so the column holds a JSON-encoded string (not array).
        // json_encode(json_encode([...])) → "\"[\\\"10.0.0.10\\\"]\"" in DB
        // → cast json_decode → string "[\"10.0.0.10\"]" (not array) → count() throws TypeError.
        DB::table('tactical_assets')
            ->where('id', $ta->id)
            ->update(['local_ips' => json_encode(json_encode(['10.0.0.10']))]);

        $asset->update(['tactical_asset_id' => $ta->id]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
    }

    public function test_asset_detail_200s_when_tactical_local_ips_is_a_proper_array(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'test-agent-uuid-2',
            'hostname' => $asset->hostname,
            'local_ips' => ['10.0.0.20', '192.168.1.5'],
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
    }
}
