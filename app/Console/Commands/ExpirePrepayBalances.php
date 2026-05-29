<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\PrepayAlertService;
use App\Services\PrepayExpirationService;
use Illuminate\Console\Command;

class ExpirePrepayBalances extends Command
{
    protected $signature = 'prepay:expire
        {--contract= : Expire a specific contract by ID}
        {--dry-run : Compute and report forfeitures without writing any changes}';

    protected $description = 'Forfeit the unconsumed remainder of expired prepaid-time credits';

    public function handle(PrepayExpirationService $expiration, PrepayAlertService $alertService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Hours-based prepay only (dollar-based prepay never expires).
        $query = Contract::whereNotNull('prepay_balance')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'));

        if ($contractId = $this->option('contract')) {
            $query->where('id', $contractId);
        }

        $contracts = $query->get();

        if ($contracts->isEmpty()) {
            $this->info('No hours-based prepay contracts found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Checking {$contracts->count()} contract(s) for expired prepaid time...");

        $totalForfeited = 0.0;
        $affected = 0;

        foreach ($contracts as $contract) {
            $result = $expiration->expireContract($contract, null, $dryRun);

            if ($result['skipped'] || $result['forfeited_hours'] <= 0) {
                continue;
            }

            $affected++;
            $totalForfeited += $result['forfeited_hours'];

            $this->line(sprintf(
                '  %s #%d (%s): %s%.2f h forfeited across %d lot(s)%s',
                $dryRun ? '[would expire]' : 'Expired',
                $contract->id,
                $contract->name,
                '-',
                $result['forfeited_hours'],
                $result['lots'],
                $dryRun ? '' : " — created {$result['created']}, updated {$result['updated']}, deleted {$result['deleted']}",
            ));

            // Forfeiture lowers the balance — re-check the low-balance alert so a
            // dip below threshold notifies / auto-tops-up (mirrors debit paths).
            if (! $dryRun) {
                $alertService->checkThreshold($contract->fresh());
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%sDone. %d contract(s) affected, %.2f h total forfeited.',
            $dryRun ? '[dry-run] ' : '',
            $affected,
            $totalForfeited,
        ));

        return self::SUCCESS;
    }
}
