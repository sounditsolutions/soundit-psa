<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\PrepayAlertService;
use Illuminate\Console\Command;

class CheckPrepayBalances extends Command
{
    protected $signature = 'prepay:check-balances';

    protected $description = 'Check prepay contracts for low balances and trigger alerts/auto-top-ups';

    public function handle(PrepayAlertService $alertService): int
    {
        $contracts = Contract::whereNotNull('prepay_alert_threshold')
            ->whereNotNull('prepay_balance')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->whereColumn('prepay_balance', '<=', 'prepay_alert_threshold')
            ->get();

        $this->info("Checking {$contracts->count()} contracts below threshold...");

        $alerted = 0;
        foreach ($contracts as $contract) {
            $alertService->checkThreshold($contract);
            if ($contract->wasChanged('prepay_alert_notified_at')) {
                $alerted++;
                $this->line("  {$contract->name}: {$contract->prepay_balance}h (threshold: {$contract->prepay_alert_threshold}h)");
            }
        }

        $this->info("Done. {$alerted} new alerts triggered.");

        return self::SUCCESS;
    }
}
