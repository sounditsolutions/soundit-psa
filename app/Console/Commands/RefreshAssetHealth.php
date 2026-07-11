<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetHealthService;
use Illuminate\Console\Command;

class RefreshAssetHealth extends Command
{
    protected $signature = 'assets:refresh-health
        {--client= : Only refresh assets for this client ID}
        {--stale-hours= : Only refresh assets whose score is older than this many hours}
        {--no-ai : Skip the AI narrative (deterministic explanation only)}
        {--limit= : Cap the number of assets processed}';

    protected $description = 'Recompute cached asset health scores and AI explanations';

    public function handle(AssetHealthService $service): int
    {
        $useAi = ! (bool) $this->option('no-ai');

        $query = Asset::query()->active();

        if ($clientId = $this->option('client')) {
            $query->where('client_id', $clientId);
        }

        if (($staleHours = $this->option('stale-hours')) !== null && $staleHours !== '') {
            $cutoff = now()->subHours((int) $staleHours);
            $query->where(fn ($q) => $q->whereNull('health_computed_at')
                ->orWhere('health_computed_at', '<', $cutoff));
        }

        $limit = null;
        if (($limitOpt = $this->option('limit')) !== null && $limitOpt !== '') {
            $limit = (int) $limitOpt;
        }

        $total = (clone $query)->count();
        if ($limit !== null) {
            $total = min($total, $limit);
        }
        if ($total === 0) {
            $this->info('No assets to refresh.');

            return self::SUCCESS;
        }

        $this->info("Refreshing health for {$total} asset(s)".($useAi ? ' (with AI narrative)' : ' (deterministic only)').'…');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $refresh = function ($asset) use ($service, $useAi, $bar, &$processed) {
            try {
                $service->refresh($asset, useAi: $useAi);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Asset #{$asset->id}: {$e->getMessage()}");
            }
            $processed++;
            $bar->advance();
        };

        if ($limit !== null) {
            // chunkById manages its own limit clause, so page a bounded set by hand.
            foreach ($query->orderBy('id')->limit($limit)->get() as $asset) {
                $refresh($asset);
            }
        } else {
            $query->chunkById(100, function ($assets) use ($refresh) {
                foreach ($assets as $asset) {
                    $refresh($asset);
                }
            });
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Refreshed {$processed} asset(s).");

        return self::SUCCESS;
    }
}
