<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\Sku;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles client-portal self-service product orders (the "Shop").
 *
 * Mirrors {@see PrepayOrderService} but generalizes to a multi-line order of
 * arbitrary catalog SKUs, not tied to a specific contract. The order is
 * materialized directly as a Posted invoice (one line per ordered SKU) — the
 * invoice IS the order record, matching the established prepay pattern. Tax is
 * calculated by the billing backend (Stripe/QBO) on push and synced back, so
 * the locally-created invoice carries subtotal == total.
 */
class PortalOrderService
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {}

    /**
     * Create a Posted invoice for a portal product order.
     *
     * @param  array<int, array{sku: Sku, quantity: int}>  $items  Resolved, validated line items.
     */
    public function createOrderInvoice(Client $client, array $items, Person $person): Invoice
    {
        if ($items === []) {
            throw new \InvalidArgumentException('A portal order requires at least one line item.');
        }

        return DB::transaction(function () use ($client, $items, $person) {
            $invoiceNumber = $this->billingService->nextInvoiceNumber();

            // Retry once on duplicate invoice number (race condition).
            for ($attempt = 0; ; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        'client_id' => $client->id,
                        'contract_id' => null,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => now()->toDateString(),
                        'due_date' => now()->toDateString(),
                        'status' => InvoiceStatus::Posted,
                        'notes' => "Product order via client portal by {$person->full_name}",
                    ]);
                    break;
                } catch (QueryException $e) {
                    if ($attempt < 1 && $e->errorInfo[1] == 1062) {
                        Log::warning("[PortalOrder] Invoice number {$invoiceNumber} collision, retrying");
                        $invoiceNumber = $this->billingService->nextInvoiceNumber();

                        continue;
                    }
                    throw $e;
                }
            }

            $subtotal = 0.0;
            $totalCost = 0.0;
            $sortOrder = 1;

            foreach ($items as $item) {
                /** @var Sku $sku */
                $sku = $item['sku'];
                $quantity = (int) $item['quantity'];

                $unitPrice = (float) $sku->unit_price;
                $unitCost = (float) ($sku->unit_cost ?? 0);
                $amount = round($quantity * $unitPrice, 2);
                $costAmount = round($quantity * $unitCost, 2);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'sku_id' => $sku->id,
                    'description' => $sku->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'cost_amount' => $costAmount,
                    'is_taxable' => $sku->is_taxable,
                    'qbo_item_ref' => $sku->qbo_item_id,
                    'sort_order' => $sortOrder++,
                ]);

                $subtotal += $amount;
                $totalCost += $costAmount;
            }

            $subtotal = round($subtotal, 2);
            $totalCost = round($totalCost, 2);

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'margin' => round($subtotal - $totalCost, 2),
            ]);

            Log::info('[PortalOrder] Portal product order invoice created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'client_id' => $client->id,
                'person_id' => $person->id,
                'line_count' => count($items),
                'subtotal' => $subtotal,
            ]);

            return $invoice;
        });
    }

    /**
     * Push the invoice to the client's billing backend (Stripe or QBO).
     * Called via terminating()/afterResponse() — not inline during the request.
     */
    public function pushToBillingBackend(Invoice $invoice): void
    {
        $client = $invoice->client;

        try {
            if ($client->stripe_customer_id) {
                app(StripeSyncService::class)->pushInvoiceToStripe($invoice);
                Log::info('[PortalOrder] Invoice pushed to Stripe', [
                    'invoice_id' => $invoice->id,
                ]);
            } elseif ($client->qbo_customer_id) {
                app(QboSyncService::class)->pushInvoiceToQbo($invoice);
                Log::info('[PortalOrder] Invoice pushed to QBO', [
                    'invoice_id' => $invoice->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PortalOrder] Billing backend push failed — staff can push manually', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
