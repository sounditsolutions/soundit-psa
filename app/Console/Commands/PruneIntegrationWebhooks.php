<?php

namespace App\Console\Commands;

use App\Models\NinjaWebhook;
use App\Models\TacticalWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneIntegrationWebhooks extends Command
{
    protected $signature = 'integrations:prune-webhooks
                            {--dry-run : Report counts without deleting anything}
                            {--days=30 : Retention window in days (default: 30)}';

    protected $description = 'Delete processed/terminal webhook rows older than the retention window from tactical_webhooks and ninja_webhooks';

    /**
     * Statuses that are safe to prune — the row has been fully handled and
     * will never be picked up again by any processor or reconcile job.
     * Pending rows are intentionally excluded, regardless of age.
     */
    private const TERMINAL_STATUSES = ['processed', 'skipped', 'failed'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $tacticalQuery = TacticalWebhook::whereIn('status', self::TERMINAL_STATUSES)
            ->where('created_at', '<', $cutoff);

        $ninjaQuery = NinjaWebhook::whereIn('status', self::TERMINAL_STATUSES)
            ->where('created_at', '<', $cutoff);

        $tacticalCount = $tacticalQuery->count();
        $ninjaCount = $ninjaQuery->count();
        $total = $tacticalCount + $ninjaCount;

        if ($dryRun) {
            $this->warn("Dry run — would prune {$tacticalCount} row(s) from tactical_webhooks and {$ninjaCount} row(s) from ninja_webhooks (total: {$total}).");

            return self::SUCCESS;
        }

        $tacticalQuery->delete();
        $ninjaQuery->delete();

        $this->info("Pruned {$tacticalCount} row(s) from tactical_webhooks and {$ninjaCount} row(s) from ninja_webhooks (total: {$total}, retention: {$days}d).");

        if ($total > 0) {
            Log::info('[Integrations] Pruned old terminal webhook rows', [
                'tactical_webhooks' => $tacticalCount,
                'ninja_webhooks' => $ninjaCount,
                'retention_days' => $days,
            ]);
        }

        return self::SUCCESS;
    }
}
