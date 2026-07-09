<?php

namespace Tests\Unit;

use App\Models\Asset;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the RMM staleness guard on Asset::getStatusBadgeAttribute().
 *
 * Regression: an asset synced with rmm_online=true keeps reporting "Online"
 * even after its last heartbeat (last_seen_at) has aged by weeks — because the
 * cached flag is trusted indefinitely. A technician then thinks a long-dead
 * host is reachable. The accessor now reports "Stale" once the heartbeat is
 * older than Asset::RMM_STALE_AFTER_HOURS.
 */
class AssetStatusBadgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-09 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asset(array $attributes): Asset
    {
        return (new Asset)->forceFill($attributes);
    }

    public function test_rmm_online_with_fresh_heartbeat_is_online(): void
    {
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse($asset->isRmmDataStale());
        $this->assertSame('Online', $asset->statusBadge);
    }

    public function test_rmm_online_but_heartbeat_stale_by_weeks_is_stale(): void
    {
        // The reported bug: rmm_online frozen true, last contact four weeks ago.
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        $this->assertTrue($asset->isRmmDataStale());
        $this->assertSame('Stale', $asset->statusBadge);
    }

    public function test_rmm_online_with_null_heartbeat_is_online_not_stale(): void
    {
        // No heartbeat to judge against — nothing proves the flag stale.
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => null,
        ]);

        $this->assertFalse($asset->isRmmDataStale());
        $this->assertSame('Online', $asset->statusBadge);
    }

    public function test_rmm_offline_stays_offline_even_when_heartbeat_is_stale(): void
    {
        $asset = $this->asset([
            'rmm_online' => false,
            'last_seen_at' => now()->subWeeks(4),
        ]);

        // The heartbeat is stale, but a stale *offline* reading is not misleading —
        // only a false "Online" is. Staleness is detected, yet the badge stays Offline.
        $this->assertTrue($asset->isRmmDataStale());
        $this->assertSame('Offline', $asset->statusBadge);
    }

    public function test_heartbeat_just_within_threshold_is_online(): void
    {
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subHours(Asset::RMM_STALE_AFTER_HOURS)->addMinute(),
        ]);

        $this->assertSame('Online', $asset->statusBadge);
    }

    public function test_heartbeat_just_beyond_threshold_is_stale(): void
    {
        $asset = $this->asset([
            'rmm_online' => true,
            'last_seen_at' => now()->subHours(Asset::RMM_STALE_AFTER_HOURS)->subMinute(),
        ]);

        $this->assertSame('Stale', $asset->statusBadge);
    }

    public function test_non_rmm_asset_falls_back_to_last_seen_recency(): void
    {
        // rmm_online null → non-RMM path (15-minute recency window), unchanged.
        $this->assertSame('Online', $this->asset([
            'rmm_online' => null,
            'last_seen_at' => now()->subMinutes(5),
        ])->statusBadge);

        $this->assertSame('Offline', $this->asset([
            'rmm_online' => null,
            'last_seen_at' => now()->subMinutes(30),
        ])->statusBadge);

        $this->assertSame('Unknown', $this->asset([
            'rmm_online' => null,
            'last_seen_at' => null,
        ])->statusBadge);
    }
}
