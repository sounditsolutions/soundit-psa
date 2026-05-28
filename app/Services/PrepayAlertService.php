<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class PrepayAlertService
{
    public function __construct(
        private NotificationService $notificationService,
        private PrepayOrderService $prepayOrderService,
    ) {}

    /**
     * Check if a contract's balance has crossed below its alert threshold.
     * Called after every debit and by the hourly cron.
     */
    public function checkThreshold(Contract $contract): void
    {
        // No threshold configured
        if ($contract->prepay_alert_threshold === null) {
            return;
        }

        // Not a hours-based prepay contract
        if (! $contract->has_prepay || $contract->prepay_as_amount) {
            return;
        }

        $balance = (float) $contract->prepay_balance;
        $threshold = (float) $contract->prepay_alert_threshold;

        // Balance is above threshold — clear any previous notification flag
        if ($balance > $threshold) {
            if ($contract->prepay_alert_notified_at) {
                $contract->update(['prepay_alert_notified_at' => null]);
            }
            return;
        }

        // Balance is at or below threshold — check if already notified for this dip
        if ($contract->prepay_alert_notified_at) {
            return;
        }

        Log::info('[PrepayAlert] Balance below threshold', [
            'contract_id' => $contract->id,
            'balance' => $balance,
            'threshold' => $threshold,
        ]);

        // Mark as notified
        $contract->update(['prepay_alert_notified_at' => now()]);

        // Send low-balance notifications
        $this->notificationService->notifyPrepayLowBalance($contract);

        // Attempt auto top-up if enabled
        if ($contract->prepay_auto_topup_enabled && $contract->prepay_auto_topup_qty > 0) {
            $this->triggerAutoTopUp($contract);
        }
    }

    /**
     * Generate an auto top-up invoice for the contract.
     */
    private function triggerAutoTopUp(Contract $contract): void
    {
        $sku = $contract->portalPrepaySku;

        if (! $sku) {
            Log::warning('[PrepayAlert] Auto top-up skipped — no portal prepay SKU', [
                'contract_id' => $contract->id,
            ]);
            return;
        }

        // Guard: don't generate another invoice if there's an unpaid one from auto top-up
        $hasPendingInvoice = Invoice::where('contract_id', $contract->id)
            ->where('notes', 'like', '%auto top-up%')
            ->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Posted])
            ->exists();

        if ($hasPendingInvoice) {
            Log::info('[PrepayAlert] Auto top-up skipped — unpaid invoice exists', [
                'contract_id' => $contract->id,
            ]);
            return;
        }

        $quantity = $contract->prepay_auto_topup_qty;

        try {
            $invoice = $this->prepayOrderService->createAutoTopUpInvoice($contract, $sku, $quantity);

            $hoursPerUnit = ($sku->prepaid_time_minutes ?? 0) / 60;
            $totalHours = $hoursPerUnit * $quantity;

            Log::info('[PrepayAlert] Auto top-up invoice created', [
                'contract_id' => $contract->id,
                'invoice_id' => $invoice->id,
                'quantity' => $quantity,
                'hours' => $totalHours,
            ]);

            // Push to billing backend
            $this->prepayOrderService->pushToBillingBackend($invoice);

            // Notify
            $this->notificationService->notifyPrepayAutoTopUp($contract, $invoice, $totalHours);
        } catch (\Throwable $e) {
            Log::error('[PrepayAlert] Auto top-up failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
