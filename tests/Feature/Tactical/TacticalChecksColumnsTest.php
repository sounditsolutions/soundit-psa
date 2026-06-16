<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Amendment B (P4): the ONLY P4 schema change — nullable checks_failing /
 * checks_total on tactical_assets, persisting the at-a-glance health count the
 * eager card line + EndpointInsight read.
 */
class TacticalChecksColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist_on_the_table(): void
    {
        $this->assertTrue(Schema::hasColumn('tactical_assets', 'checks_failing'));
        $this->assertTrue(Schema::hasColumn('tactical_assets', 'checks_total'));
    }

    public function test_columns_are_fillable_and_cast_to_int(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-CHK']);

        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-CHK',
            'hostname' => 'BOX-CHK',
            'status' => 'online',
            'checks_failing' => '2',
            'checks_total' => '7',
        ]);

        $ta->refresh();

        $this->assertSame(2, $ta->checks_failing);
        $this->assertSame(7, $ta->checks_total);
    }

    public function test_columns_default_null(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-NULL']);

        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-NULL',
            'hostname' => 'BOX-NULL',
            'status' => 'online',
        ]);

        $ta->refresh();

        $this->assertNull($ta->checks_failing);
        $this->assertNull($ta->checks_total);
    }
}
