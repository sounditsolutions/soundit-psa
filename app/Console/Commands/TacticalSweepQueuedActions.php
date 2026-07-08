<?php

namespace App\Console\Commands;

use App\Services\Tactical\OfflineActionSweep;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;

/**
 * Fallback sweep for the offline-script queue (bd psa-xr84) so processing never
 * depends solely on device-sync cadence or webhook delivery: run queued actions
 * whose device now reads online, and expire any past their safety window. The
 * device-sync hook and webhook fast-path are the low-latency triggers; this is the
 * safety net, scheduled at the configured interval (default 10 min).
 */
class TacticalSweepQueuedActions extends Command
{
    protected $signature = 'tactical:sweep-queued-actions';

    protected $description = 'Run queued offline Tactical actions whose device is back online, and expire stale ones.';

    public function handle(OfflineActionSweep $sweep): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->error('Tactical RMM is not configured.');

            return self::FAILURE;
        }

        $summary = $sweep->sweepDue();
        $this->info("Offline-queue sweep: ran {$summary['ran']}, expired {$summary['expired']}.");

        return self::SUCCESS;
    }
}
