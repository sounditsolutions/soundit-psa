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
 * psa-oc5q2.1 — the pre_void_amount marker that InvoiceLine::reportable_amount
 * keys off must be COMPLETE on HISTORICAL data too, not just going forward.
 *
 * Invoices voided before the service snapshotted every line can carry lines
 * with no marker (the original backfill skipped $0 lines with the same
 * predicate the service used), and such a line reads as live money as soon as
 * the out-of-lock QBO status pull re-inflates it. These tests pin the
 * deploy-time backfill that installs the missing markers, and the re-void door
 * that repairs any that appear later.
 *
 * sqlite caveat: this suite runs in memory, so the MariaDB left-to-right
 * UPDATE SET evaluation the backfill depends on (snapshot before zeroing) is
 * NOT exercised here — sqlite reads every SET clause from the original row.
 */
class PreVoidLineMarkerBackfillTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /**
     * A Void invoice whose line carries NO pre_void snapshot — the legacy shape
     * left behind by a void that predates the complete-marker rule.
     */
    private function voidedInvoiceWithLegacyLine(string $amount, ?string $cost): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => Client::factory()->create()->id,
            'invoice_number' => 'INV-LEGACY-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal' => '0.00',
            'tax' => '0.00',
            'total' => '0.00',
            'total_cost' => '0.00',
            'status' => InvoiceStatus::Void,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'No-charge item',
            'quantity' => 1,
            'unit_price' => $amount,
            'unit_cost' => $cost,
            'amount' => $amount,
            'cost_amount' => $cost,
            'sort_order' => 0,
        ]);

        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update([
            'pre_void_amount' => null,
            'pre_void_cost_amount' => null,
        ]);

        return $invoice->fresh();
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_07_31_000001_backfill_missing_pre_void_line_markers.php');
        $migration->up();
    }

    public function test_backfill_marks_a_zero_at_void_line_the_original_backfill_skipped(): void
    {
        $invoice = $this->voidedInvoiceWithLegacyLine('0.00', '0.00');
        $line = $invoice->lines()->first();
        $this->assertNull($line->pre_void_amount, 'premise: the historical row has no marker');

        $this->runBackfill();

        $line->refresh();
        $this->assertSame('0.00', $line->pre_void_amount, 'the $0 line must now be marked');

        // The marker now holds against the out-of-lock QBO re-inflation, which
        // is the whole point of installing it.
        DB::table('invoice_lines')->where('id', $line->id)
            ->update(['amount' => '250.00', 'cost_amount' => '90.00']);
        $line->refresh();

        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
    }

    public function test_backfill_zeroes_an_unmarked_money_line_and_a_re_run_never_overwrites_the_snapshot(): void
    {
        $invoice = $this->voidedInvoiceWithLegacyLine('1000.00', '400.00');

        $this->runBackfill();

        $line = $invoice->lines()->first();
        $this->assertSame('1000.00', $line->pre_void_amount);
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('0.00', $line->reportable_amount);

        // Re-inflate, then re-run: whereNull means the ORIGINAL bill survives
        // and the reportable reading stays $0.
        DB::table('invoice_lines')->where('id', $line->id)->update(['amount' => '999.00']);
        $this->runBackfill();

        $line->refresh();
        $this->assertSame('1000.00', $line->pre_void_amount);
        $this->assertSame('1000.00', $line->display_amount);
        $this->assertSame('0.00', $line->reportable_amount);
    }

    public function test_backfill_preserves_a_null_cost(): void
    {
        $invoice = $this->voidedInvoiceWithLegacyLine('0.00', null);

        $this->runBackfill();

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->pre_void_amount);
        $this->assertNull($line->cost_amount, 'a null cost stays null — nothing to zero');
        $this->assertNull($line->pre_void_cost_amount);
        $this->assertSame('0.00', $line->reportable_amount);
    }

    public function test_re_voiding_installs_a_missing_marker_instead_of_early_returning(): void
    {
        $invoice = $this->voidedInvoiceWithLegacyLine('0.00', '0.00');

        // Pre-fix this early-returned (Void + no reportable amounts anywhere),
        // so the operator had no repair door at all.
        app(InvoiceVoidService::class)->void($invoice->fresh());

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->pre_void_amount, 're-voiding must install the missing marker');

        DB::table('invoice_lines')->where('id', $line->id)
            ->update(['amount' => '250.00', 'cost_amount' => '90.00']);
        $line->refresh();

        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
    }
}
