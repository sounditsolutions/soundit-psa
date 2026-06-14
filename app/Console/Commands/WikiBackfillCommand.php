<?php

namespace App\Console\Commands;

use App\Services\Wiki\WikiBackfillService;
use App\Support\WikiConfig;
use Illuminate\Console\Command;

class WikiBackfillCommand extends Command
{
    protected $signature = 'wiki:backfill
        {--client= : Limit to one client (pass client ID)}
        {--batch= : Override the backfill batch size}
        {--execute : Actually mine tickets (default is dry-run — nothing is dispatched)}';

    protected $description = 'Populate wiki from closed-ticket history. Dry-run by default; shows cost estimate. Pass --execute to dispatch.';

    public function handle(WikiBackfillService $service): int
    {
        $batch = (int) ($this->option('batch') ?: WikiConfig::backfillBatchSize());
        $clientId = $this->option('client') ? (int) $this->option('client') : null;

        $plan = $service->plan($clientId, $batch);

        $oldest = $plan['oldest'] !== null ? "#{$plan['oldest']}" : 'none';
        $newest = $plan['newest'] !== null ? "#{$plan['newest']}" : 'none';

        $this->line(
            "Backfill plan: {$plan['ticket_count']} ticket(s), est. ~{$plan['estimated_tokens']} tokens"
            ." (≤ today's {$plan['daily_ceiling']} ceiling — backfill cannot exceed it and resumes next day),"
            ." oldest {$oldest} → newest {$newest}."
        );

        if (! $plan['auto_mine_on']) {
            $this->warn(
                'wiki_auto_mine is OFF — mining is gated; --execute would dispatch nothing.'
                .' Enable auto-mine to backfill.'
            );
        }

        if (! $this->option('execute')) {
            $this->info('Dry run — nothing mined. Re-run with --execute to dispatch.');

            return self::SUCCESS;
        }

        $n = $service->execute($clientId, $batch);
        $this->info("Dispatched {$n} mining job(s) (capped by batch + daily budget).");

        return self::SUCCESS;
    }
}
