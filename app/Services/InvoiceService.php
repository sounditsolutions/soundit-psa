<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sku;
use App\Models\User;
use App\Services\Qbo\QboSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {}

    public function createInvoice(array $validated, User $user): Invoice
    {
        return DB::transaction(function () use ($validated, $user) {
            $invoiceNumber = $this->billingService->nextInvoiceNumber();

            // Retry once on duplicate invoice number (race condition)
            for ($attempt = 0; ; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        'client_id' => $validated['client_id'],
                        'contract_id' => $validated['contract_id'] ?? null,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $validated['invoice_date'],
                        'due_date' => $validated['due_date'],
                        'status' => InvoiceStatus::Draft,
                        'notes' => $validated['notes'] ?? null,
                    ]);
                    break;
                } catch (QueryException $e) {
                    if ($attempt < 1 && $e->errorInfo[1] == 1062) {
                        Log::warning("[Invoice] Invoice number {$invoiceNumber} collision, retrying");
                        $invoiceNumber = $this->billingService->nextInvoiceNumber();

                        continue;
                    }
                    throw $e;
                }
            }

            $subtotal = 0;
            $totalCost = 0;
            $sortOrder = 0;
            $annotation = 'Manual invoice by '.$user->name.' on '.now()->format('Y-m-d');

            foreach ($validated['lines'] as $lineData) {
                $quantity = (float) $lineData['quantity'];
                $unitPrice = (float) $lineData['unit_price'];
                $unitCost = isset($lineData['unit_cost']) ? (float) $lineData['unit_cost'] : 0;
                $amount = round($quantity * $unitPrice, 2);
                $costAmount = round($quantity * $unitCost, 2);
                $subtotal += $amount;
                $totalCost += $costAmount;

                $skuId = ! empty($lineData['sku_id']) ? (int) $lineData['sku_id'] : null;
                $sku = $skuId ? Sku::find($skuId) : null;

                $prepaidMinutes = ! empty($lineData['prepaid_time_minutes'])
                    ? (int) $lineData['prepaid_time_minutes']
                    : (($sku && $sku->prepaid_time_minutes) ? (int) ($quantity * $sku->prepaid_time_minutes) : null);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'sku_id' => $skuId,
                    'description' => $lineData['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'cost_amount' => $costAmount,
                    'prepaid_time_minutes' => $prepaidMinutes,
                    'is_taxable' => $lineData['is_taxable'] ?? true,
                    'qbo_item_ref' => $sku?->qbo_item_id,
                    'quantity_source' => $annotation,
                    'sort_order' => $sortOrder++,
                ]);
            }

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'margin' => round($subtotal - $totalCost, 2),
            ]);

            return $invoice;
        });
    }

    /**
     * Manually mark a standalone invoice Paid (psa-8yhp). Returns whether the
     * transition happened.
     *
     * Eligibility (Invoice::canMarkPaid) is re-checked inside the transaction
     * on a row-locked read, not just when the button was rendered: an invoice
     * can leave the eligible Posted/no-backend state — a concurrent void, or a
     * push to Stripe/QBO — between page load and this POST, and must not then
     * be rewritten. The status flip goes through the model (not a bare UPDATE)
     * so InvoiceObserver fires and deposits any prepaid time to the contract.
     */
    public function markPaid(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $locked = Invoice::whereKey($invoice->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->canMarkPaid()) {
                return false;
            }

            $locked->update(['status' => InvoiceStatus::Paid]);

            return true;
        });
    }

    public function updateInvoice(Invoice $invoice, array $validated, User $user): void
    {
        DB::transaction(function () use ($invoice, $validated, $user) {
            // Update header fields
            $invoice->update([
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $existingLineIds = $invoice->lines->pluck('id')->toArray();
            $keptLineIds = [];
            $subtotal = 0;
            $totalCost = 0;
            $sortOrder = 0;
            $editAnnotation = 'Manual edit by '.$user->name.' on '.now()->format('Y-m-d');

            foreach ($validated['lines'] as $lineData) {
                // Skip lines marked for deletion
                if (! empty($lineData['_delete'])) {
                    continue;
                }

                $quantity = (float) $lineData['quantity'];
                $unitPrice = (float) $lineData['unit_price'];
                $unitCost = isset($lineData['unit_cost']) ? (float) $lineData['unit_cost'] : 0;
                $amount = round($quantity * $unitPrice, 2);
                $costAmount = round($quantity * $unitCost, 2);
                $subtotal += $amount;
                $totalCost += $costAmount;

                $skuId = ! empty($lineData['sku_id']) ? (int) $lineData['sku_id'] : null;

                $lineAttributes = [
                    'sku_id' => $skuId,
                    'description' => $lineData['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'cost_amount' => $costAmount,
                    'prepaid_time_minutes' => ! empty($lineData['prepaid_time_minutes']) ? (int) $lineData['prepaid_time_minutes'] : null,
                    'is_taxable' => $lineData['is_taxable'] ?? true,
                    'sort_order' => $sortOrder++,
                ];

                if (! empty($lineData['id'])) {
                    // Update existing line
                    $line = InvoiceLine::where('id', $lineData['id'])
                        ->where('invoice_id', $invoice->id)
                        ->firstOrFail();

                    // Only update quantity_source if values actually changed
                    if ((float) $line->quantity !== $quantity
                        || (float) $line->unit_price !== $unitPrice
                        || (float) ($line->unit_cost ?? 0) !== $unitCost) {
                        $lineAttributes['quantity_source'] = $editAnnotation;
                    }

                    $line->update($lineAttributes);
                    $keptLineIds[] = $line->id;
                } else {
                    // New line
                    $lineAttributes['invoice_id'] = $invoice->id;
                    $lineAttributes['quantity_source'] = $editAnnotation;

                    // Set qbo_item_ref from SKU if available
                    if ($skuId) {
                        $sku = Sku::find($skuId);
                        $lineAttributes['qbo_item_ref'] = $sku?->qbo_item_id;
                    }

                    InvoiceLine::create($lineAttributes);
                }
            }

            // Validate at least one non-deleted line remains
            if (empty($keptLineIds) && $subtotal === 0) {
                throw ValidationException::withMessages([
                    'lines' => 'An invoice must have at least one line item.',
                ]);
            }

            // Only delete lines explicitly marked with _delete — never by absence
            $deleteIds = [];
            foreach ($validated['lines'] as $lineData) {
                if (! empty($lineData['_delete']) && ! empty($lineData['id'])) {
                    $deleteIds[] = (int) $lineData['id'];
                }
            }
            if (! empty($deleteIds)) {
                InvoiceLine::where('invoice_id', $invoice->id)
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }

            // Recalculate invoice totals
            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'tax' => 0,
                'total_cost' => $totalCost,
                'margin' => round($subtotal - $totalCost, 2),
            ]);
        });

        // Sync to QBO immediately so tax/total are correct before the redirect
        if ($invoice->qbo_invoice_id) {
            try {
                app(QboSyncService::class)->pushInvoiceToQbo($invoice);
            } catch (\Throwable $e) {
                Log::warning("[Invoice] QBO sync failed after edit, will retry on next push: {$e->getMessage()}", [
                    'invoice_id' => $invoice->id,
                ]);
            }
        }
    }
}
