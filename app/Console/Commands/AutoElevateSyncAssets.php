<?php

namespace App\Console\Commands;

use App\Services\AutoElevate\AutoElevateAssetSyncReport;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use App\Support\AutoElevateConfig;
use Illuminate\Console\Command;

/**
 * Stage 3a: link AutoElevate computers to PSA assets (read-only at the vendor).
 * Scheduled hourly in routes/console.php, with a 55-minute overlap lock.
 */
class AutoElevateSyncAssets extends Command
{
    protected $signature = 'autoelevate:sync-assets';

    protected $description = 'Link AutoElevate computers to PSA assets by hostname within each mapped client (never creates assets)';

    public function handle(AutoElevateAssetSyncService $service): int
    {
        // OFF means OFF (Q-5 A): no read, no link change, no clear.
        if (! AutoElevateConfig::isSwitchedOn()) {
            $this->warn('AutoElevate integration is switched off (set autoelevate_enabled=1 to re-enable). Skipping.');

            return self::SUCCESS;
        }

        if (! AutoElevateConfig::isConfigured()) {
            $this->error('AutoElevate is not configured. Add the API key in Settings → Integrations.');

            return self::FAILURE;
        }

        // Shared vendor budget (M1.6): a live cooldown, started by a 429 to Sync now or to an
        // earlier run, means this run reads nothing. It exits FAILURE, so the scheduled run's
        // fixed failure line is logged, as for any run that leaves clients unread.
        $cooldown = AutoElevateAssetSyncService::vendorCooldownRemaining();
        if ($cooldown > 0) {
            $this->error("AutoElevate asked us to slow down; its cooldown ends in {$cooldown} s. Nothing was read and no asset link was changed.");

            return self::FAILURE;
        }

        $report = $service->sync();

        $this->info("Done: {$report->summary()}");
        foreach ($report->perClient as $clientId => $row) {
            if ($row['unmatched'] !== [] || $row['ambiguous'] !== []) {
                $this->line(sprintf('  client #%d: %d linked, %d unmatched, %d ambiguous',
                    $clientId, $row['linked'], count($row['unmatched']), count($row['ambiguous'])));
            }
        }
        foreach ($report->failedClients as $clientId => $reason) {
            $this->warn("  client #{$clientId}: read failed ({$reason}) — its asset links were left unchanged");
        }
        if ($report->stoppedEarly !== null) {
            $why = $report->stoppedEarly === AutoElevateAssetSyncReport::STOP_CONSECUTIVE_429
                ? sprintf('%d clients in a row were rate-limited (http_429)', AutoElevateAssetSyncService::STOP_AFTER_CONSECUTIVE_429)
                : sprintf('the run deadline of %d s was reached', AutoElevateAssetSyncService::MAX_RUN_SECONDS);
            $this->error("Stopped early: {$why}.");
            $this->warn(sprintf('  %d client(s) were not read and their asset links were left unchanged: %s',
                count($report->skippedClients),
                implode(', ', array_map(fn (int $id) => "#{$id}", $report->skippedClients))));
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
