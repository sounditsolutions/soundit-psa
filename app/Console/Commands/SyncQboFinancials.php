<?php

namespace App\Console\Commands;

use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Console\Command;

class SyncQboFinancials extends Command
{
    protected $signature = 'qbo:sync-financials
        {--balances : Sync bank account balances from QBO}
        {--expenses : Sync expense (Purchase) transactions from QBO}
        {--since= : Only sync expenses on or after this date (Y-m-d). Defaults to the last 90 days.}';

    protected $description = 'Sync bank account balances and expenses from QuickBooks Online';

    public function handle(QboClient $qboClient, QboSyncService $syncService): int
    {
        if (! $qboClient->isConnected()) {
            $this->error('Not connected to QuickBooks Online. Go to Settings → Integrations to connect.');

            return self::FAILURE;
        }

        $balances = $this->option('balances');
        $expenses = $this->option('expenses');

        // Default: do both
        if (! $balances && ! $expenses) {
            $balances = true;
            $expenses = true;
        }

        $hasErrors = false;

        if ($balances) {
            $this->info('Syncing bank account balances from QBO...');

            try {
                $result = $syncService->syncBankBalances();
                $this->info("  Synced {$result['synced']} bank account(s).");
            } catch (QboClientException $e) {
                $this->error('  Bank balance sync failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        if ($expenses) {
            $since = $this->option('since') ?: now()->subDays(90)->format('Y-m-d');
            $this->info("Syncing expenses from QBO (since {$since})...");

            try {
                $result = $syncService->syncExpenses($since);
                $this->info("  Synced {$result['synced']} expense(s) across {$result['pages']} page(s).");
            } catch (QboClientException $e) {
                $this->error('  Expense sync failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        $this->newLine();
        $this->info('QBO financials sync complete.');

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
