<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transitions invoices to Void the sum-safe way: the original amounts are
 * snapshotted into pre_void_* columns and the reportable money fields
 * (invoices.subtotal/tax/total/total_cost/margin, invoice_lines.amount/
 * cost_amount) are zeroed. Financial aggregates then exclude voided invoices
 * structurally instead of relying on every query to remember a
 * WHERE status != 'void' filter.
 *
 * Every path that voids an invoice must come through here: the staff void
 * routes (single + bulk), QBO void detection in QboSyncService, and the
 * Stripe invoice import (Stripe keeps totals on voided invoices).
 *
 * The snapshot, the zeroing, and the status flip happen in one UPDATE.
 * InvoiceObserver's prepay reversal fires off that status change but reads
 * the prepay transaction ledger — not live invoice amounts — so it is
 * unaffected by the zeroing.
 */
class InvoiceVoidService
{
    public function void(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->refresh()->load('lines');

            // Already void and already zeroed — nothing to do. (A void
            // invoice can regain amounts when a Stripe re-import rewrites
            // them; that case falls through and is re-zeroed below.)
            if ($invoice->status === InvoiceStatus::Void
                && ! $this->hasReportableAmounts($invoice)
                && ! $this->linesHaveReportableAmounts($invoice)) {
                return $invoice;
            }

            foreach ($invoice->lines as $line) {
                $hasAmount = (float) $line->amount != 0.0;
                $hasCost = (float) ($line->cost_amount ?? 0) != 0.0;

                if (! $hasAmount && ! $hasCost) {
                    continue;
                }

                $line->update([
                    'pre_void_amount' => $line->amount,
                    'pre_void_cost_amount' => $line->cost_amount,
                    'amount' => 0,
                    'cost_amount' => $line->cost_amount === null ? null : 0,
                ]);
            }

            $updates = ['status' => InvoiceStatus::Void];

            if ($this->hasReportableAmounts($invoice)) {
                $updates += [
                    'pre_void_subtotal' => $invoice->subtotal,
                    'pre_void_tax' => $invoice->tax,
                    'pre_void_total' => $invoice->total,
                    'pre_void_total_cost' => $invoice->total_cost,
                    'pre_void_margin' => $invoice->margin,
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'total_cost' => $invoice->total_cost === null ? null : 0,
                    'margin' => $invoice->margin === null ? null : 0,
                ];

                Log::info('[InvoiceVoid] Voided invoice — amounts zeroed, originals snapshotted', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'pre_void_total' => (string) $invoice->total,
                ]);
            }

            $invoice->update($updates);

            return $invoice;
        });
    }

    private function hasReportableAmounts(Invoice $invoice): bool
    {
        foreach (['subtotal', 'tax', 'total', 'total_cost', 'margin'] as $field) {
            if ($invoice->{$field} !== null && (float) $invoice->{$field} != 0.0) {
                return true;
            }
        }

        return false;
    }

    private function linesHaveReportableAmounts(Invoice $invoice): bool
    {
        return $invoice->lines->contains(
            fn ($line) => (float) $line->amount != 0.0 || (float) ($line->cost_amount ?? 0) != 0.0
        );
    }
}
