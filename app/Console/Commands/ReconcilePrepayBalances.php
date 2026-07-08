<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\PrepayService;
use Illuminate\Console\Command;

class ReconcilePrepayBalances extends Command
{
    protected $signature = 'prepay:reconcile
        {--contract= : Reconcile a specific contract by ID}';

    protected $description = 'Recalculate prepay balances from the transaction ledger';

    public function handle(PrepayService $prepayService): int
    {
        $contractId = $this->option('contract');

        $query = Contract::whereNotNull('prepay_balance');

        if ($contractId) {
            $query->where('id', $contractId);
        }

        $contracts = $query->get();

        if ($contracts->isEmpty()) {
            $this->info('No contracts with prepay found.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$contracts->count()} contract(s)...");

        $discrepancies = 0;

        foreach ($contracts as $contract) {
            $before = [
                'total' => (float) $contract->prepay_total,
                'used' => (float) $contract->prepay_used,
                'balance' => (float) $contract->prepay_balance,
            ];

            $prepayService->recalculateBalance($contract);
            $contract->refresh();

            $after = [
                'total' => (float) $contract->prepay_total,
                'used' => (float) $contract->prepay_used,
                'balance' => (float) $contract->prepay_balance,
            ];

            if ($before !== $after) {
                $discrepancies++;
                $this->warn("Contract #{$contract->id} ({$contract->name}): balance {$before['balance']} → {$after['balance']}");
            } else {
                $this->line("Contract #{$contract->id} ({$contract->name}): OK ({$after['balance']})");
            }
        }

        $this->newLine();
        if ($discrepancies > 0) {
            $this->warn("{$discrepancies} discrepancy(ies) found and corrected.");
        } else {
            $this->info('All balances match the ledger.');
        }

        return self::SUCCESS;
    }
}
