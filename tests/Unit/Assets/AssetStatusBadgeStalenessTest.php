<?php

namespace Tests\Unit\Assets;

use App\Models\Asset;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the RMM-staleness guard on Asset::statusBadge (psa-wedk).
 *
 * When an RMM sync stops running, rmm_online freezes at its last value while
 * last_seen_at (set by the same sync) goes stale. A frozen rmm_online=true then
 * rendered a false "Online" indefinitely. The badge must instead read "Stale"
 * once last_seen_at is older than the configurable threshold
 * (asset_rmm_stale_after_hours, default 24h). Offline is never downgraded — the
 * danger is a false Online, not a stale Offline.
 */
class AssetStatusBadgeStalenessTest extends TestCase
{
    use RefreshDatabase;

    private function asset(array $attrs): Asset
    {
        return Asset::factory()->create($attrs);
    }

    public function test_online_with_stale_last_seen_reads_stale(): void
    {
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        $this->assertSame('Stale', $asset->statusBadge);
    }

    public function test_online_with_fresh_last_seen_stays_online(): void
    {
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->assertSame('Online', $asset->statusBadge);
    }

    public function test_offline_is_never_downgraded_to_stale(): void
    {
        // rmm_online=false with an ancient last_seen must stay Offline — a stale
        // Offline is not the hazard; only a false Online is.
        $asset = $this->asset([
            'rmm_online' => false,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        $this->assertSame('Offline', $asset->statusBadge);
    }

    public function test_online_without_last_seen_cannot_be_assessed_and_stays_online(): void
    {
        // No timestamp to judge freshness against — fail open to the prior
        // behaviour rather than crying stale without evidence.
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => null,
        ]);

        $this->assertSame('Online', $asset->statusBadge);
    }

    public function test_default_threshold_is_24_hours(): void
    {
        $fresh = $this->asset(['rmm_online' => true, 'last_seen_at' => now()->subHours(23)]);
        $stale = $this->asset(['rmm_online' => true, 'last_seen_at' => now()->subHours(25)]);

        $this->assertSame('Online', $fresh->statusBadge);
        $this->assertSame('Stale', $stale->statusBadge);
    }

    public function test_threshold_is_configurable(): void
    {
        Setting::setValue('asset_rmm_stale_after_hours', '48');

        $within = $this->asset(['rmm_online' => true, 'last_seen_at' => now()->subHours(30)]);
        $beyond = $this->asset(['rmm_online' => true, 'last_seen_at' => now()->subHours(50)]);

        $this->assertSame('Online', $within->statusBadge);
        $this->assertSame('Stale', $beyond->statusBadge);
    }

    public function test_non_rmm_fallback_branch_is_unchanged(): void
    {
        // rmm_online null → the existing last_seen_at 15-minute heuristic, untouched.
        $recent = $this->asset(['rmm_online' => null, 'last_seen_at' => now()->subMinutes(5)]);
        $old = $this->asset(['rmm_online' => null, 'last_seen_at' => now()->subMinutes(30)]);
        $none = $this->asset(['rmm_online' => null, 'last_seen_at' => null]);

        $this->assertSame('Online', $recent->statusBadge);
        $this->assertSame('Offline', $old->statusBadge);
        $this->assertSame('Unknown', $none->statusBadge);
    }
}
