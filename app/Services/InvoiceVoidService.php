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

            // Already void, already zeroed, and every line already marked —
            // nothing to do. (A void invoice can regain amounts when a Stripe
            // re-import rewrites them; that case falls through and is re-zeroed
            // below.) An UNMARKED line falls through too: re-entering here is
            // the only door through which a legacy void — voided back when $0
            // lines were skipped — can acquire the marker
            // InvoiceLine::reportable_amount depends on (psa-oc5q2.1).
            if ($invoice->status === InvoiceStatus::Void
                && ! $this->hasReportableAmounts($invoice)
                && ! $this->linesHaveReportableAmounts($invoice)
                && ! $this->linesMissingVoidMarker($invoice)) {
                return $invoice;
            }

            // An unmarked line on an invoice whose void ZEROING HAS ALREADY
            // TAKEN EFFECT — already Void AND its header money already zeroed —
            // is a legacy row, and its value AT VOID TIME was $0 by construction
            // (the old service and the 2026_07_12 backfill only ever skipped $0
            // lines). Snapshot that, not whatever an out-of-lock QBO
            // re-inflation has since written — the same rule as the 2026_08_02
            // marking backfill.
            //
            // BOTH halves are load-bearing; status alone is NOT a legacy
            // predicate. The Stripe import arrives here on an already-Void row
            // that has never been voided by us: upsertInvoiceFromStripe writes
            // status Void together with the REAL retained totals, then
            // syncStripeInvoiceLines DELETES and RECREATES every line at its
            // real billed amount with pre_void_* null, and only then calls
            // void(). Reading that as a legacy repair would record '0.00' as the
            // client's original bill and destroy the only copy of it — the
            // re-import repeats the same delete-recreate every run, so nothing
            // later restores it. A header still carrying reportable money means
            // the zeroing has not happened yet, so the lines in hand ARE the
            // live bill and must be snapshotted as-is.
            //
            // A ZEROED HEADER IS NOT PROOF WE ZEROED IT, EITHER. upsertInvoiceFromStripe
            // copies Stripe's retained subtotal/tax/total verbatim, and those can
            // legitimately net to zero over real lines — a +$500 charge against a
            // -$500 proration credit — so a header-only test misfires on exactly
            // the payload this branch exists to protect, stamping '0.00' over the
            // only copy of the per-line bill. So require POSITIVE evidence that the
            // zeroing is ours: a header that CONTRADICTS its own lines — only our
            // zeroing empties a header out from under the line money it is supposed
            // to total. A legacy void whose unmarked line has since been re-inflated
            // shows that, and it is the case the repair exists for; one still
            // sitting at its void-time $0 needs no repair, since its lines already
            // ARE $0. A header that still totals its lines was never zeroed by us,
            // so those lines are the bill and are snapshotted as-is.
            $repairingLegacyVoid = $invoice->status === InvoiceStatus::Void
                && ! $this->hasReportableAmounts($invoice)
                && $this->headerContradictsLines($invoice);

            foreach ($invoice->lines as $line) {
                // Snapshot EVERY line on the first void — INCLUDING $0 lines,
                // which were previously skipped. pre_void_amount is the
                // line-local void marker that InvoiceLine::reportable_amount
                // trusts, so it must be COMPLETE: a skipped $0 line left no
                // marker, and the out-of-lock QBO status-pull residual could
                // then re-inflate that line to read as live money on a Void
                // invoice (psa-oc5q2.1). Preserve an existing snapshot across a
                // re-void/re-import so a re-inflated value never overwrites the
                // ORIGINAL billed amount; on a legacy void the missing snapshot
                // is recorded as $0, which is what that line was billed at.
                $snapshotted = $line->pre_void_amount !== null;
                $preVoidAmount = $repairingLegacyVoid ? '0.00' : $line->amount;
                $preVoidCostAmount = $repairingLegacyVoid && $line->cost_amount !== null
                    ? '0.00'
                    : $line->cost_amount;

                $line->update([
                    'pre_void_amount' => $snapshotted ? $line->pre_void_amount : $preVoidAmount,
                    'pre_void_cost_amount' => $snapshotted ? $line->pre_void_cost_amount : $preVoidCostAmount,
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
     * Do the lines carry money this header does not account for? A header is
     * meant to total its own lines, so a mismatch on an already-Void invoice is
     * evidence that OUR zeroing emptied the header and the line money is
     * post-void residue (the out-of-lock QBO line write). A header that still
     * totals its lines — both $0, or lines that genuinely net to zero — was
     * never zeroed by us, so it says nothing about the lines being residual.
     *
     * Amount only: the out-of-lock writer (syncLineItemsFromQbo) rewrites
     * amount, never cost_amount, so amount is the signal. Compared at cent
     * tolerance, not float equality.
     */
    private function headerContradictsLines(Invoice $invoice): bool
    {
        $lineTotal = 0.0;
        foreach ($invoice->lines as $line) {
            $lineTotal += (float) $line->amount;
        }

        return abs($lineTotal - (float) $invoice->subtotal) >= 0.005;
    }

    /**
     * A line with no pre_void_amount is UNMARKED: InvoiceLine::reportable_amount
     * reads its raw amount, so an out-of-lock re-inflation would report live
     * money on a Void invoice. Re-entering void() repairs it (psa-oc5q2.1).
     */
    private function linesMissingVoidMarker(Invoice $invoice): bool
    {
        return $invoice->lines->contains(fn ($line) => $line->pre_void_amount === null);
    }
}
