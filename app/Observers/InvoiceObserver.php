<?php

namespace App\Observers;

use App\Enums\InvoiceStatus;
use App\Jobs\PushInvoiceToBilling;
use App\Models\Invoice;
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
     * When an invoice transitions to Paid, deposit any prepaid time
     * to the contract's prepay balance.
     */
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status') || ! $invoice->contract_id) {
            return;
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            $this->handlePaid($invoice);
        } elseif ($invoice->status === InvoiceStatus::Void) {
            $this->handleVoid($invoice);
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
}
