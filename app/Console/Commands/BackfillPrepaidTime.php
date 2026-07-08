<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\PrepayTransactionSource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PrepayTransaction;
use App\Services\PrepayService;
use Illuminate\Console\Command;

class BackfillPrepaidTime extends Command
{
    protected $signature = 'billing:backfill-prepaid-time
        {--dry-run : Show what would change without persisting}
        {--include-halo : Also process Halo-imported invoices (default: PSA-native only)}
        {--deposit-paid : Create prepay deposits for already-paid invoices that gain prepaid time}';

    protected $description = 'Backfill prepaid_time_minutes on historical PSA invoice lines from linked SKU configuration';

    public function handle(PrepayService $prepayService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeHalo = (bool) $this->option('include-halo');
        $depositPaid = (bool) $this->option('deposit-paid');

        $scope = $includeHalo ? 'all invoices' : 'PSA-native invoices';
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Backfilling prepaid time from SKU config ({$scope})...");

        // ── Step 1: Backfill invoice line prepaid_time_minutes from linked SKU config ──
        // Mirrors BillingService::generateInvoice():
        //   prepaid_time_minutes = (int) (quantity * sku.prepaid_time_minutes)
        // The per-line profile override is not stored on invoice lines, so SKU config
        // is the only recoverable source for historical lines. Only lines that were
        // never set (null) are filled, so intentional values are never clobbered.
        $query = InvoiceLine::query()
            ->whereNull('prepaid_time_minutes')
            ->whereNotNull('sku_id')
            ->whereHas('sku', fn ($q) => $q->where('prepaid_time_minutes', '>', 0))
            ->with('sku:id,prepaid_time_minutes');

        if (! $includeHalo) {
            $query->whereHas('invoice', fn ($q) => $q->whereNull('halo_id'));
        }

        $updatedLines = 0;
        $addedMinutesByInvoice = [];

        $query->chunkById(200, function ($lines) use (&$updatedLines, &$addedMinutesByInvoice, $dryRun) {
            foreach ($lines as $line) {
                $perUnit = (int) $line->sku->prepaid_time_minutes;
                $minutes = (int) ((float) $line->quantity * $perUnit);

                if (! $dryRun) {
                    $line->update(['prepaid_time_minutes' => $minutes]);
                }

                $updatedLines++;
                $addedMinutesByInvoice[$line->invoice_id] =
                    ($addedMinutesByInvoice[$line->invoice_id] ?? 0) + $minutes;
            }
        });

        $invoiceCount = count($addedMinutesByInvoice);
        $verb = $dryRun ? 'Would backfill' : 'Backfilled';
        $this->info("{$verb} {$updatedLines} invoice line(s) across {$invoiceCount} invoice(s).");

        if ($invoiceCount === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        // ── Step 2: Already-paid invoices need an explicit prepay deposit ──
        // InvoiceObserver only deposits on the Paid *transition*, so invoices that
        // were already Paid before this backfill will not auto-deposit. Unpaid
        // invoices deposit correctly when they are later marked Paid.
        $this->reconcilePaidInvoices(
            $prepayService,
            array_keys($addedMinutesByInvoice),
            $includeHalo,
            $depositPaid,
            $dryRun,
        );

        return self::SUCCESS;
    }

    /**
     * Deposit (or report) prepaid time for invoices that were already Paid before
     * the backfill ran and therefore never triggered the auto-deposit observer.
     *
     * @param  array<int>  $invoiceIds  Invoices whose lines gained prepaid minutes
     */
    private function reconcilePaidInvoices(
        PrepayService $prepayService,
        array $invoiceIds,
        bool $includeHalo,
        bool $depositPaid,
        bool $dryRun,
    ): void {
        $query = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->where('status', InvoiceStatus::Paid)
            ->whereNotNull('contract_id')
            ->with(['lines.sku', 'contract']);

        if (! $includeHalo) {
            $query->whereNull('halo_id');
        }

        $paid = $query->get();

        if ($paid->isEmpty()) {
            return;
        }

        // One query for invoices that already carry a deposit (avoids N+1).
        $alreadyDeposited = PrepayTransaction::whereIn('invoice_id', $paid->pluck('id'))
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->pluck('invoice_id')
            ->flip();

        $pending = $paid->filter(function (Invoice $invoice) use ($alreadyDeposited) {
            if (! $invoice->contract) {
                return false;
            }
            if ($alreadyDeposited->has($invoice->id)) {
                return false;
            }
            // Mirror depositFromInvoice's dollar-based guard — those are skipped there.
            if ($invoice->contract->has_prepay && $invoice->contract->prepay_as_amount) {
                return false;
            }

            return $this->effectivePrepaidMinutes($invoice) > 0;
        })->values();

        if ($pending->isEmpty()) {
            return;
        }

        $totalHours = round(
            $pending->sum(fn (Invoice $invoice) => $this->effectivePrepaidMinutes($invoice)) / 60,
            4,
        );

        if (! $depositPaid) {
            $this->warn(
                "{$pending->count()} already-paid invoice(s) gained prepaid time (~{$totalHours}h) "
                .'but have no prepay deposit. Re-run with --deposit-paid to create them.'
            );

            return;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would create {$pending->count()} prepay deposit(s) totalling ~{$totalHours}h.");

            return;
        }

        $created = 0;
        $contracts = [];

        foreach ($pending as $invoice) {
            $txn = $prepayService->depositFromInvoice($invoice, $invoice->contract);
            if ($txn) {
                $created++;
                $contracts[$invoice->contract_id] = $invoice->contract;
            }
        }

        // Defensive: recompute denormalized balances from the ledger for touched contracts.
        foreach ($contracts as $contract) {
            $prepayService->recalculateBalance($contract);
        }

        $this->info("Created {$created} prepay deposit(s) across ".count($contracts).' contract(s).');
    }

    /**
     * Effective prepaid minutes for an invoice after backfill — persisted line
     * values plus any Step 1 would fill from SKU config. Computing both cases keeps
     * the projection accurate under --dry-run (where lines are not yet persisted).
     */
    private function effectivePrepaidMinutes(Invoice $invoice): int
    {
        $total = 0;

        foreach ($invoice->lines as $line) {
            if ($line->prepaid_time_minutes !== null) {
                $total += (int) $line->prepaid_time_minutes;
            } elseif ($line->sku && (int) $line->sku->prepaid_time_minutes > 0) {
                $total += (int) ((float) $line->quantity * (int) $line->sku->prepaid_time_minutes);
            }
        }

        return $total;
    }
}
