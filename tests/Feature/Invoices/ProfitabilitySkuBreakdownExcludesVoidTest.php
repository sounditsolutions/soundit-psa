<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceVoidService;
use App\Services\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ProfitabilityService::contractProfitability() by-SKU breakdown must exclude
 * voided invoices (psa-oc5q2 mitigation).
 *
 * The header aggregates (revenue/cost) sum invoices.subtotal / total_cost,
 * which InvoiceVoidService zeroes under the invoice-row lock and the status-pull
 * guard keeps zeroed (psa-qfhc5) — so by the "structural zeroing" design they
 * need no WHERE status != 'void' filter. The by-SKU breakdown is different: it
 * sums the LINE-level invoice_lines.amount / cost_amount, and QBO line-item
 * sync (syncLineItemsFromQbo) writes those lines OUTSIDE the void lock. A void
 * committing in the sub-window after the guarded header write can therefore
 * leave a voided invoice's line amounts re-inflated while invoices.subtotal
 * stays 0 — durably contaminating contract-profitability by-SKU revenue/cost
 * (the only observed reader). These tests pin that the by-SKU query filters
 * voided invoices out, so a re-inflated line can never contaminate it.
 */
class ProfitabilitySkuBreakdownExcludesVoidTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeContract(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'start_date' => '2026-01-01',
        ]);
    }

    private function makeInvoiceWithLine(Client $client, Contract $contract, array $lineAttrs): Invoice
    {
        $amount = $lineAttrs['amount'] ?? '0.00';
        $cost = $lineAttrs['cost_amount'] ?? '0.00';

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-SKU-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal' => $amount,
            'tax' => '0.00',
            'total' => $amount,
            'total_cost' => $cost,
            'status' => InvoiceStatus::Posted,
        ]);

        InvoiceLine::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'Line',
            'quantity' => 1,
            'unit_price' => $amount,
            'unit_cost' => $cost,
            'is_taxable' => false,
            'sort_order' => 0,
        ], $lineAttrs));

        return $invoice->fresh();
    }

    /**
     * Void the invoice through the real primitive (zeroes header + lines under
     * the invoice lock), then simulate the residual out-of-lock QBO line write
     * that re-inflates the zeroed line amounts (psa-oc5q2). The header stays
     * zeroed (guarded); only the lines come back — exactly the contaminated
     * state the by-SKU breakdown must refuse to read.
     */
    private function voidThenReinflateLines(Invoice $invoice, string $amount, string $cost): void
    {
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->getKey()));

        DB::table('invoice_lines')
            ->where('invoice_id', $invoice->getKey())
            ->update(['amount' => $amount, 'cost_amount' => $cost]);

        // Guard the premise: the header is genuinely zeroed while the line is
        // genuinely re-inflated — otherwise the test could pass vacuously.
        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('0.00', $fresh->subtotal);
        $this->assertSame($amount, $fresh->lines()->first()->amount);
    }

    public function test_by_sku_breakdown_omits_a_voided_invoice_entirely(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client);

        // Live invoice — legitimately in the breakdown.
        $this->makeInvoiceWithLine($client, $contract, [
            'description' => 'Managed services',
            'amount' => '1000.00',
            'cost_amount' => '300.00',
        ]);

        // Voided invoice whose DISTINCT-SKU line has been re-inflated by the
        // out-of-lock residual. It must not appear in the breakdown at all —
        // pre-fix it surfaces as a phantom "Cancelled widget" revenue row.
        $voided = $this->makeInvoiceWithLine($client, $contract, [
            'description' => 'Cancelled widget',
            'amount' => '999.00',
            'cost_amount' => '111.00',
        ]);
        $this->voidThenReinflateLines($voided, '999.00', '111.00');

        $data = app(ProfitabilityService::class)->contractProfitability($contract);

        // Header aggregate is already void-safe (subtotal zeroed): control.
        $this->assertSame(1000.0, $data['revenue']);
        $this->assertSame(300.0, $data['cost']);

        // By-SKU breakdown excludes the voided invoice's re-inflated line.
        $this->assertCount(1, $data['bySku']);
        $this->assertSame('Managed services', $data['bySku'][0]['sku_name']);
        $this->assertSame(1000.0, $data['bySku'][0]['revenue']);
        $this->assertSame(300.0, $data['bySku'][0]['cost']);
    }

    public function test_by_sku_breakdown_does_not_inflate_a_live_skus_revenue_with_a_voided_line(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client);

        // Live + voided lines share a SKU/description, so a naive sum would fold
        // the re-inflated voided amount into the live SKU's own revenue row.
        $this->makeInvoiceWithLine($client, $contract, [
            'description' => 'Managed services',
            'amount' => '1000.00',
            'cost_amount' => '300.00',
        ]);
        $voided = $this->makeInvoiceWithLine($client, $contract, [
            'description' => 'Managed services',
            'amount' => '999.00',
            'cost_amount' => '111.00',
        ]);
        $this->voidThenReinflateLines($voided, '999.00', '111.00');

        $data = app(ProfitabilityService::class)->contractProfitability($contract);

        $this->assertCount(1, $data['bySku']);
        $this->assertSame('Managed services', $data['bySku'][0]['sku_name']);
        // Exactly the live amount — NOT 1000 + 999 / 300 + 111.
        $this->assertSame(1000.0, $data['bySku'][0]['revenue']);
        $this->assertSame(300.0, $data['bySku'][0]['cost']);
    }
}
