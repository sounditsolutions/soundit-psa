<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshAssetHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_populates_scores(): void
    {
        $a = Asset::factory()->create(['rmm_online' => false, 'last_seen_at' => now()->subHours(2)]);
        $b = Asset::factory()->create(['rmm_online' => true, 'last_seen_at' => now()]);

        $this->artisan('assets:refresh-health', ['--no-ai' => true])->assertSuccessful();

        $this->assertSame(70, $a->fresh()->health_score);
        $this->assertSame(100, $b->fresh()->health_score);
        $this->assertFalse($a->fresh()->health_summary_is_ai);
    }

    public function test_command_skips_inactive_assets(): void
    {
        $inactive = Asset::factory()->create([
            'is_active' => false,
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $this->artisan('assets:refresh-health', ['--no-ai' => true])->assertSuccessful();

        $this->assertNull($inactive->fresh()->health_computed_at);
    }

    public function test_client_filter_limits_scope(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $inA = Asset::factory()->create(['client_id' => $clientA->id, 'rmm_online' => false, 'last_seen_at' => now()->subHours(2)]);
        $inB = Asset::factory()->create(['client_id' => $clientB->id, 'rmm_online' => false, 'last_seen_at' => now()->subHours(2)]);

        $this->artisan('assets:refresh-health', ['--no-ai' => true, '--client' => $clientA->id])->assertSuccessful();

        $this->assertNotNull($inA->fresh()->health_computed_at);
        $this->assertNull($inB->fresh()->health_computed_at);
    }

    public function test_stale_hours_skips_fresh_assets(): void
    {
        $fresh = Asset::factory()->scored(50)->create([
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
            'health_computed_at' => now(),
        ]);

        $this->artisan('assets:refresh-health', ['--no-ai' => true, '--stale-hours' => 12])->assertSuccessful();

        // Still the seeded score — it was fresh, so not recomputed to 70.
        $this->assertSame(50, $fresh->fresh()->health_score);
    }
}
