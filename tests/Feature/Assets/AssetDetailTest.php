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

    public function test_asset_detail_flags_conflicting_os_between_device_identity_and_tactical(): void
    {
        // psa-sp30: Device Identity OS and the Tactical RMM agent report
        // different Windows Server versions — the page must flag it.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['os' => 'Windows Server 2019']);
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'test-agent-os-conflict',
            'hostname' => $asset->hostname,
            'os' => 'Windows Server 2022',
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('Verify which source is current before trusting either value.');
        // Device Identity row surfaces the competing Tactical value...
        $resp->assertSee('Tactical: Windows Server 2022');
        // ...and the Tactical card points back at the device record.
        $resp->assertSee('Device record: Windows Server 2019');
    }

    public function test_asset_detail_does_not_flag_os_when_only_formatting_differs(): void
    {
        // psa-sp30: same OS, different vendor formatting (Microsoft prefix,
        // edition, bitness, build) — must NOT be flagged as a conflict.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['os' => 'Microsoft Windows Server 2019 Standard']);
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'test-agent-os-match',
            'hostname' => $asset->hostname,
            'os' => 'Windows Server 2019 Standard, 64bit (build 17763)',
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertDontSee('Verify which source is current before trusting either value.');
    }
}
