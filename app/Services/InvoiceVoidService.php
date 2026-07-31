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
 *
 * Voiding SERIALIZES with the Stripe push/send authorization boundary
 * (psa-bl36l R5): it takes the invoice row lock first (invoice-first lock
 * order, same row Invoice::recordPushResult() and
 * StripeSyncService::sendInvoiceEmail() lock), so relative to any in-flight
 * push/send there are exactly two orderings — the void commits first and the
 * push/send boundary sees Void (compensation, no email), or the boundary
 * commits first and this locked read sees the CURRENT backend identifiers it
 * recorded, so the caller propagates the void upstream instead of concluding
 * "not linked" from a stale snapshot.
 */
class InvoiceVoidService
{
    public function void(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            // Locked current-read, hydrating the CALLER's model (withTrashed
            // mirrors the old refresh(), which ignored global scopes — the
            // Stripe import can hand us a soft-deleted invoice). A plain
            // refresh() here was a snapshot read: it could miss a push result
            // committed a moment earlier and leave the caller propagating
            // nothing while the freshly-minted hosted page stayed live.
            $locked = Invoice::withTrashed()->whereKey($invoice->getKey())
                ->lockForUpdate()->firstOrFail();
            $invoice->setRawAttributes($locked->getAttributes(), true);
            $invoice->syncOriginal();
            $invoice->load('lines');

            // Already void, already zeroed, and every line already carries its
            // void marker — nothing to do. (A void invoice can regain amounts
            // when a Stripe re-import rewrites them; that case falls through
            // and is re-zeroed below. So does an invoice voided BEFORE the line
            // marker existed: re-voiding it is the only in-app repair door for
            // a missing marker, so this early return must not swallow it —
            // psa-oc5q2.1.)
            if ($invoice->status === InvoiceStatus::Void
                && ! $this->hasReportableAmounts($invoice)
                && ! $this->linesHaveReportableAmounts($invoice)
                && ! $this->linesMissingVoidMarker($invoice)) {
                return $invoice;
            }

            foreach ($invoice->lines as $line) {
                // Snapshot EVERY line on the first void — INCLUDING $0 lines,
                // which were previously skipped. pre_void_amount is the
                // line-local void marker that InvoiceLine::reportable_amount
                // trusts, so it must be COMPLETE: a skipped $0 line left no
                // marker, and the out-of-lock QBO status-pull residual could
                // then re-inflate that line to read as live money on a Void
                // invoice (psa-oc5q2.1). Preserve an existing snapshot across a
                // re-void/re-import so a re-inflated value never overwrites the
                // ORIGINAL billed amount.
                $snapshotted = $line->pre_void_amount !== null;

                $line->update([
                    'pre_void_amount' => $snapshotted ? $line->pre_void_amount : $line->amount,
                    'pre_void_cost_amount' => $snapshotted ? $line->pre_void_cost_amount : $line->cost_amount,
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

    /**
     * A line on a Void invoice with no pre_void_amount carries NO void marker,
     * so InvoiceLine::reportable_amount would read its raw money as live. Such
     * lines exist only from before this service snapshotted every line; the
     * deploy-time backfill clears the historical ones
     * (2026_07_31_000001_backfill_missing_pre_void_line_markers), and this
     * predicate keeps the re-void door a genuine repair for any that appear
     * later (psa-oc5q2.1).
     */
    private function linesMissingVoidMarker(Invoice $invoice): bool
    {
        return $invoice->lines->contains(fn ($line) => $line->pre_void_amount === null);
    }
}
