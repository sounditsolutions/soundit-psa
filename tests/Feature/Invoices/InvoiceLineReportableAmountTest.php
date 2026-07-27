<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * InvoiceLine::reportable_amount / reportable_cost_amount — the shared
 * status-aware seam every reportable reader of raw line money now uses (the
 * get_invoice MCP tool, the SKU invoice-use pane, the recurring-profile invoice
 * history). psa-oc5q2.1.
 *
 * A voided invoice's line money must read as $0 for reporting — the line-level
 * analogue of invoices.subtotal/total_cost being zeroed on void — AND it must
 * stay $0 even if amount/cost_amount is re-inflated OUT of the void lock by the
 * QBO status pull after the void (the residual psa-oc5q2 tracks). The display_*
 * accessors expose the ORIGINAL bill instead.
 */
class InvoiceLineReportableAmountTest extends TestCase
{
    use RefreshDatabase;

    private function lineOn(InvoiceStatus $status, string $amount = '1000.00', string $cost = '400.00'): InvoiceLine
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-'.rand(100000, 999999),
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal' => $amount, 'tax' => '0.00', 'total' => $amount,
            'total_cost' => $cost, 'status' => $status,
        ]);

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 1, 'unit_price' => $amount, 'unit_cost' => $cost,
            'amount' => $amount, 'cost_amount' => $cost, 'sort_order' => 0,
        ]);
    }

    public function test_a_live_line_reports_its_live_money(): void
    {
        $line = $this->lineOn(InvoiceStatus::Posted);

        $this->assertSame('1000.00', $line->reportable_amount);
        $this->assertSame('400.00', $line->reportable_cost_amount);
    }

    public function test_a_voided_line_reports_zero_but_keeps_its_original_for_display(): void
    {
        $line = $this->lineOn(InvoiceStatus::Posted);
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($line->invoice_id));
        $line->refresh();

        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
        // Original bill still recoverable via the display accessors.
        $this->assertSame('1000.00', $line->display_amount);
        $this->assertSame('400.00', $line->display_cost_amount);
    }

    public function test_a_reinflated_voided_line_still_reports_zero(): void
    {
        $line = $this->lineOn(InvoiceStatus::Posted);
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($line->invoice_id));

        // Residual out-of-lock QBO line write re-inflates the zeroed line.
        DB::table('invoice_lines')->where('id', $line->id)
            ->update(['amount' => '999.00', 'cost_amount' => '111.00']);
        $line->refresh();

        // Reportable stays $0 — it keys off the pre_void snapshot, which the race never touches.
        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
        // Display shows the ORIGINAL, not the re-inflated value.
        $this->assertSame('1000.00', $line->display_amount);
    }

    /**
     * The deepest door (psa-oc5q2.1, security + architecture REVISE): a line that
     * is $0 AT VOID TIME. InvoiceVoidService used to skip such a line, leaving it
     * with no pre_void snapshot — so the marker was incomplete, and a later
     * out-of-lock QBO re-inflation of that $0 line would read as live money on a
     * Void invoice. Voiding now snapshots EVERY line, so the marker is complete
     * and a re-inflated zero-at-void line still reports $0.
     */
    public function test_a_zero_at_void_line_re_inflated_after_void_still_reports_zero(): void
    {
        $line = $this->lineOn(InvoiceStatus::Posted, '0.00', '0.00');
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($line->invoice_id));

        // Out-of-lock QBO write re-inflates the (formerly $0) line after the void.
        DB::table('invoice_lines')->where('id', $line->id)
            ->update(['amount' => '250.00', 'cost_amount' => '90.00']);
        $line->refresh();

        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
    }
}
