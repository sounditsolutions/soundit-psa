<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sku;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInvoiceCosts extends Command
{
    protected $signature = 'billing:backfill-costs
        {--link-skus : Also link invoice lines to SKUs by matching description to SKU name}';

    protected $description = 'Backfill cost data on historical invoice lines from linked or matched SKUs';

    public function handle(): int
    {
        // Step 1: Link unlinked invoice lines to SKUs by description match
        if ($this->option('link-skus')) {
            $this->linkSkusByDescription();
        }

        // Step 2: Backfill cost from linked SKUs
        $this->backfillCosts();

        return self::SUCCESS;
    }

    private function linkSkusByDescription(): void
    {
        $this->info('Linking invoice lines to SKUs by description match...');

        // Build a lookup: lowercase SKU name → SKU id
        $skuMap = Sku::all()->mapWithKeys(fn ($s) => [strtolower(trim($s->name)) => $s->id]);

        $lines = InvoiceLine::whereNull('sku_id')->get(['id', 'description']);
        $linked = 0;

        foreach ($lines as $line) {
            // Invoice line descriptions from Halo often have date suffixes like
            // "MSP Support Iron 01/2026 - 02/2026" — extract the product name prefix
            $desc = $this->extractProductName($line->description);
            $key = strtolower(trim($desc));

            if ($skuMap->has($key)) {
                InvoiceLine::where('id', $line->id)->update(['sku_id' => $skuMap->get($key)]);
                $linked++;
            }
        }

        $this->info("Linked {$linked} invoice lines to SKUs.");
    }

    private function backfillCosts(): void
    {
        $this->info('Backfilling costs from linked SKUs...');

        $lines = InvoiceLine::whereNotNull('sku_id')
            ->whereNull('unit_cost')
            ->with('sku')
            ->get();

        if ($lines->isEmpty()) {
            $this->info('No invoice lines need cost backfilling.');
            return;
        }

        $updated = 0;

        foreach ($lines as $line) {
            if (! $line->sku) {
                continue;
            }

            $unitCost = (float) $line->sku->unit_cost;
            $costAmount = round((float) $line->quantity * $unitCost, 2);

            $line->update([
                'unit_cost' => $unitCost,
                'cost_amount' => $costAmount,
            ]);

            $updated++;
        }

        $this->info("Updated costs on {$updated} invoice lines.");

        // Recalculate invoice totals
        $invoiceIds = $lines->pluck('invoice_id')->unique();
        $recalculated = 0;

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::find($invoiceId);
            if (! $invoice) {
                continue;
            }

            $totalCost = (float) $invoice->lines()->sum('cost_amount');
            $invoice->update([
                'total_cost' => $totalCost,
                'margin' => round((float) $invoice->subtotal - $totalCost, 2),
            ]);
            $recalculated++;
        }

        $this->info("Recalculated totals on {$recalculated} invoices.");
    }

    /**
     * Extract the product name from a Halo invoice line description.
     * Halo appends date ranges like " 01/2026 - 02/2026" or
     * " $recurringbillingdate{MM/yyyy} - $nextrecurringbillingdate{MM/yyyy}".
     */
    private function extractProductName(string $description): string
    {
        // Strip Halo date variable suffixes
        $cleaned = preg_replace('/\s*\$[a-z]+\{[^}]+\}\s*-\s*\$[a-z]+\{[^}]+\}/i', '', $description);

        // Strip actual date suffixes like " 01/2026 - 02/2026" or " 01/2025 - 12/2025"
        $cleaned = preg_replace('/\s+\d{1,2}\/\d{4}\s*-\s*\d{1,2}\/\d{4}\s*$/', '', $cleaned);

        return trim($cleaned);
    }
}
