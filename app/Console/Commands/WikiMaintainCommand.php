<?php

namespace App\Console\Commands;

use App\Services\Wiki\WikiMaintainService;
use App\Support\WikiBudget;
use App\Support\WikiConfig;
use Illuminate\Console\Command;

class WikiMaintainCommand extends Command
{
    protected $signature = 'wiki:maintain';

    protected $description = 'Nightly wiki maintenance: staleness, contradictions, link lint, stale-open tickets, stale-only overview regen.';

    public function handle(WikiMaintainService $service): int
    {
        if (! WikiConfig::maintenanceEnabled()) {
            $this->info('Wiki maintenance disabled — skipping.');

            return self::SUCCESS;
        }

        if (WikiBudget::dailyLimitReached()) {
            $this->warn('Daily wiki token budget already reached — sweeps run, regen will skip.');
        }

        $r = $service->run('cron');

        $this->line(sprintf(
            'maintain: %d stale · %d disputes filed · %d dead links · %d orphans · %d open-ticket flags · %d overviews regenerated',
            $r['stale']['total'],
            $r['contradictions']['filed'],
            $r['lint']['dead_links'],
            $r['lint']['orphan_pages'],
            $r['open_tickets']['flagged'],
            $r['regen']['composed'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
