<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\Sku;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrepayOrderService
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {}

    /**
     * $unitPrice is the price the caller decided is in force for this purchase
     * (the portal passes Sku::portalPrepayUnitPrice() read at the moment of
     * purchase); the line, the invoice totals and the contract activity all
     * record it. Null means the SKU's unit_price.
     */
    public function createPrepayInvoice(Contract $contract, Sku $sku, int $quantity, Person $person, ?float $unitPrice = null): Invoice
    {
        $client = $contract->client;
        $unitPrice ??= (float) $sku->unit_price;

        return DB::transaction(function () use ($contract, $client, $sku, $quantity, $person, $unitPrice) {
            $invoiceNumber = $this->billingService->nextInvoiceNumber();

            // Retry once on duplicate invoice number (race condition)
            for ($attempt = 0; ; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        'client_id' => $client->id,
                        'contract_id' => $contract->id,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => now()->toDateString(),
                        'due_date' => now()->toDateString(),
                        'status' => InvoiceStatus::Posted,
                        'notes' => "Prepaid time purchase via client portal by {$person->full_name}",
                    ]);
                    break;
                } catch (QueryException $e) {
                    if ($attempt < 1 && $e->errorInfo[1] == 1062) {
                        Log::warning("[PrepayOrder] Invoice number {$invoiceNumber} collision, retrying");
                        $invoiceNumber = $this->billingService->nextInvoiceNumber();

                        continue;
                    }
                    throw $e;
                }
            }

            $unitCost = (float) ($sku->unit_cost ?? 0);
            $amount = round($quantity * $unitPrice, 2);
            $costAmount = round($quantity * $unitCost, 2);
            $prepaidMinutes = (int) ($quantity * $sku->prepaid_time_minutes);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'sku_id' => $sku->id,
                'description' => $sku->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'amount' => $amount,
                'cost_amount' => $costAmount,
                'prepaid_time_minutes' => $prepaidMinutes,
                'is_taxable' => $sku->is_taxable,
                'qbo_item_ref' => $sku->qbo_item_id,
                'sort_order' => 1,
            ]);

            $invoice->update([
                'subtotal' => $amount,
                'total' => $amount,
                'total_cost' => $costAmount,
                'margin' => round($amount - $costAmount, 2),
            ]);

            $hours = round($prepaidMinutes / 60, 1);

            ContractActivity::create([
                'contract_id' => $contract->id,
                'user_id' => null,
                'action' => 'portal_prepay_purchase',
                'changes' => [
                    'person' => $person->full_name,
                    'sku' => $sku->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'hours' => $hours,
                    'amount' => $amount,
                    'invoice_number' => $invoice->invoice_number,
                ],
            ]);

            Log::info('[PrepayOrder] Portal purchase invoice created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'contract_id' => $contract->id,
                'client_id' => $client->id,
                'person_id' => $person->id,
                'sku_id' => $sku->id,
                'quantity' => $quantity,
                'amount' => $amount,
                'prepaid_minutes' => $prepaidMinutes,
            ]);

            return $invoice;
        });
    }

    /**
     * Push the invoice to the client's billing backend (Stripe or QBO).
     * The portal purchase calls it from an app()->terminating() callback, after
     * the response; the auto top-up (PrepayAlertService) calls it inline with
     * $portalOrder false.
     */
    public function pushToBillingBackend(Invoice $invoice, bool $portalOrder = true): void
    {
        $client = $invoice->client;

        if (! $client->stripe_customer_id && ! $client->qbo_customer_id) {
            Log::warning('[PrepayOrder] Client not mapped to any billing backend; invoice not pushed', [
                'invoice_id' => $invoice->id,
                'client_id' => $client->id,
            ]);
            $this->alertPushFailed($invoice, 'None', "Client \"{$client->name}\" is not mapped to QBO or Stripe.", $portalOrder);

            return;
        }

        $backend = $client->stripe_customer_id ? 'Stripe' : 'QBO';

        try {
            if ($backend === 'Stripe') {
                app(StripeSyncService::class)->pushInvoiceToStripe($invoice);
                Log::info('[PrepayOrder] Invoice pushed to Stripe', [
                    'invoice_id' => $invoice->id,
                ]);
            } else {
                app(QboSyncService::class)->pushInvoiceToQbo($invoice);
                Log::info('[PrepayOrder] Invoice pushed to QBO', [
                    'invoice_id' => $invoice->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PrepayOrder] Billing backend push failed — staff can push manually', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            $this->alertPushFailed($invoice, $backend, $e->getMessage(), $portalOrder);
        }
    }

    /**
     * Email active staff who have not turned this alert off that this invoice did not reach billing. A failure
     * of the alert itself is logged and swallowed: it must never affect the order.
     */
    private function alertPushFailed(Invoice $invoice, string $backend, string $error, bool $portalOrder): void
    {
        try {
            app(NotificationService::class)->notifyInvoicePushFailed($invoice, $backend, $error, $portalOrder);
        } catch (\Throwable $e) {
            Log::warning('[PrepayOrder] Invoice push-failure alert threw', [
                'invoice_id' => $invoice->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create an invoice for auto top-up (system-initiated, not portal-initiated).
     */
    public function createAutoTopUpInvoice(Contract $contract, Sku $sku, int $quantity): Invoice
    {
        $client = $contract->client;

        return DB::transaction(function () use ($contract, $client, $sku, $quantity) {
            $invoiceNumber = $this->billingService->nextInvoiceNumber();

            for ($attempt = 0; ; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        'client_id' => $client->id,
                        'contract_id' => $contract->id,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => now()->toDateString(),
                        'due_date' => now()->toDateString(),
                        'status' => InvoiceStatus::Posted,
                        'notes' => "Prepaid time auto top-up for {$contract->name}",
                    ]);
                    break;
                } catch (QueryException $e) {
                    if ($attempt < 1 && $e->errorInfo[1] == 1062) {
                        Log::warning("[PrepayOrder] Invoice number {$invoiceNumber} collision, retrying");
                        $invoiceNumber = $this->billingService->nextInvoiceNumber();

                        continue;
                    }
                    throw $e;
                }
            }

            $unitPrice = (float) $sku->unit_price;
            $unitCost = (float) ($sku->unit_cost ?? 0);
            $amount = round($quantity * $unitPrice, 2);
            $costAmount = round($quantity * $unitCost, 2);
            $prepaidMinutes = (int) ($quantity * ($sku->prepaid_time_minutes ?? 0));

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'sku_id' => $sku->id,
                'description' => $sku->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'amount' => $amount,
                'cost_amount' => $costAmount,
                'prepaid_time_minutes' => $prepaidMinutes,
                'is_taxable' => $sku->is_taxable,
                'qbo_item_ref' => $sku->qbo_item_id,
                'sort_order' => 1,
            ]);

            $invoice->update([
                'subtotal' => $amount,
                'total' => $amount,
                'total_cost' => $costAmount,
                'margin' => round($amount - $costAmount, 2),
            ]);

            ContractActivity::create([
                'contract_id' => $contract->id,
                'user_id' => null,
                'action' => 'prepay_auto_topup',
                'description' => "Auto top-up: {$quantity} × {$sku->name} (Invoice #{$invoiceNumber})",
            ]);

            Log::info('[PrepayOrder] Auto top-up invoice created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'contract_id' => $contract->id,
                'client_id' => $client->id,
                'sku_id' => $sku->id,
                'quantity' => $quantity,
                'amount' => $amount,
                'prepaid_minutes' => $prepaidMinutes,
            ]);

            return $invoice;
        });
    }
}
