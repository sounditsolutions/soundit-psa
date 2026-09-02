<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Jobs\PushInvoiceToBilling;
use App\Models\Invoice;
use App\Models\InvoiceStatusChangeLog;
use App\Services\PrepayService;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        if ($invoice->profile_id && $invoice->profile?->auto_push_mode) {
            PushInvoiceToBilling::dispatch($invoice)->afterCommit();
        }
    }

    /**
     * Every status move passes through here (#1173): record it, then run the
     * prepay side effects the move implies.
     *
     * The audit row is written for EVERY invoice, contract or not — the
     * contract_id guard below governs prepay, not the record. T-22802's nine
     * invoices had no contract; a log that skipped them would have answered
     * nothing.
     */
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        $context = $invoice->statusChangeContext;
        // Consume it: this instance may be saved again for reasons that have
        // nothing to do with the status, and a stale context would then
        // attribute an unrelated move to QuickBooks.
        $invoice->statusChangeContext = null;

        try {
            InvoiceStatusChangeLog::recordFor($invoice, $context);
        } catch (\Throwable $e) {
            // The status write has already committed on the non-transactional
            // paths; throwing here would surface as a failed save for a change
            // that in fact happened. Losing the audit row is bad — silently
            // reporting the write as failed is worse, and the log line keeps
            // the loss visible.
            Log::error('[InvoiceStatus] Failed to record status change', [
                'invoice_id' => $invoice->id,
                'from' => $invoice->getRawOriginal('status'),
                'to' => $invoice->status?->value,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $invoice->contract_id) {
            return;
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            $this->handlePaid($invoice);
        } elseif ($invoice->status === InvoiceStatus::Void) {
            $this->handleVoid($invoice);
        } elseif ($invoice->getRawOriginal('status') === InvoiceStatus::Paid->value) {
            // Paid → still owed (#1173): a QBO pull found the invoice open
            // again. handlePaid deposited prepaid time on the way in, so the
            // way out has to take it back, exactly as a void does — otherwise
            // the client keeps hours they have not paid for. Reuses the void
            // reversal because the ledger effect is identical; only the
            // description differs, so the technician reading the prepay
            // ledger is not told the invoice was voided when it was not.
            $this->handleRevertedFromPaid($invoice);
        }
    }

    private function handlePaid(Invoice $invoice): void
    {
        $invoice->loadMissing(['lines', 'contract']);

        if (! $invoice->lines->sum('prepaid_time_minutes')) {
            return;
        }

        try {
            app(PrepayService::class)->depositFromInvoice($invoice, $invoice->contract);
        } catch (\Throwable $e) {
            Log::error('[Prepay] Failed to deposit from paid invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleVoid(Invoice $invoice): void
    {
        $invoice->loadMissing('contract');

        try {
            app(PrepayService::class)->reverseDepositForInvoice($invoice, $invoice->contract);
        } catch (\Throwable $e) {
            Log::error('[Prepay] Failed to reverse deposit for voided invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleRevertedFromPaid(Invoice $invoice): void
    {
        $invoice->loadMissing('contract');

        try {
            app(PrepayService::class)->reverseDepositForInvoice(
                $invoice,
                $invoice->contract,
                "Reversal — invoice {$invoice->invoice_number} no longer paid",
            );
        } catch (\Throwable $e) {
            Log::error('[Prepay] Failed to reverse deposit for reverted invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
