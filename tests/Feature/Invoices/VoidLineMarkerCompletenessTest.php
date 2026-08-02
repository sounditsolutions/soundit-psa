<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pre_void_amount marker must be COMPLETE on invoices voided BEFORE the
 * every-line snapshot rule, not only on ones voided by the new code
 * (psa-oc5q2.1).
 *
 * The old InvoiceVoidService and the 2026_07_12 backfill both skipped $0 lines,
 * so a legacy void carries unmarked lines; the QBO status pull writes line
 * money outside the void lock, so such a line can be re-inflated and then reads
 * as LIVE money through InvoiceLine::reportable_amount — on the MCP payload, the
 * portal invoice/shop pages, the profile history and the SKU pane. Two things
 * close that: the 2026_08_02 marking backfill for the rows already in the
 * database, and void() itself re-marking an unmarked line so the marker is
 * acquirable at all.
 */
class VoidLineMarkerCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function invoice(InvoiceStatus $status, array $attrs, array $lineAttrs): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'invoice_number' => 'INV-MARK-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'status' => $status,
        ], $attrs));

        InvoiceLine::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'No-charge item',
            'quantity' => 1,
            'unit_price' => '0.00',
            'unit_cost' => '0.00',
            'sort_order' => 0,
        ], $lineAttrs));

        return $invoice->fresh();
    }

    /**
     * A void exactly as the OLD code left it: header zeroed with no invoice-level
     * snapshot (it was a $0 invoice), line carrying NO pre_void marker.
     */
    private function legacyVoid(array $lineAttrs = []): Invoice
    {
        return $this->invoice(
            InvoiceStatus::Void,
            ['subtotal' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'total_cost' => '0.00', 'margin' => '0.00'],
            array_merge(['amount' => '0.00', 'cost_amount' => '0.00'], $lineAttrs),
        );
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_02_000001_mark_void_invoice_line_snapshots.php');
        $migration->up();
    }

    // ── the backfill: rows already in the database ──

    public function test_backfill_marks_a_legacy_reinflated_line_and_leaves_live_money_alone(): void
    {
        // Legacy void whose unmarked $0 line was re-inflated out-of-lock.
        $legacy = $this->legacyVoid(['amount' => '250.00', 'cost_amount' => '90.00']);
        // Live invoice — must be untouched.
        $live = $this->invoice(
            InvoiceStatus::Posted,
            ['subtotal' => '1000.00', 'tax' => '0.00', 'total' => '1000.00', 'total_cost' => '400.00'],
            ['description' => 'Managed services', 'unit_price' => '1000.00', 'unit_cost' => '400.00', 'amount' => '1000.00', 'cost_amount' => '400.00'],
        );

        $this->runBackfill();

        $line = $legacy->lines()->first();
        $this->assertSame('0.00', $line->pre_void_amount, 'the legacy line must gain the void marker');
        $this->assertSame('0.00', $line->pre_void_cost_amount);
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('0.00', $line->cost_amount);
        $this->assertSame('0.00', $line->reportable_amount);
        $this->assertSame('0.00', $line->reportable_cost_amount);
        // The original bill was $0 — the backfill must not mint the re-inflated value.
        $this->assertSame('0.00', $line->display_amount);

        $liveLine = $live->lines()->first();
        $this->assertNull($liveLine->pre_void_amount);
        $this->assertSame('1000.00', $liveLine->amount);
        $this->assertSame('1000.00', $liveLine->reportable_amount);
        $this->assertSame('400.00', $liveLine->reportable_cost_amount);
    }

    public function test_backfill_preserves_null_costs_and_existing_snapshots_and_is_rerunnable(): void
    {
        // Legacy void line that never tracked cost.
        $noCost = $this->legacyVoid(['amount' => '250.00', 'cost_amount' => null, 'unit_cost' => null]);

        // A void done through the real primitive — already marked, must not move.
        $properlyVoided = $this->invoice(
            InvoiceStatus::Posted,
            ['subtotal' => '500.00', 'tax' => '0.00', 'total' => '500.00', 'total_cost' => '200.00'],
            ['description' => 'Managed services', 'unit_price' => '500.00', 'unit_cost' => '200.00', 'amount' => '500.00', 'cost_amount' => '200.00'],
        );
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($properlyVoided->getKey()));

        $this->runBackfill();
        $this->runBackfill(); // idempotent

        $noCostLine = $noCost->lines()->first();
        $this->assertSame('0.00', $noCostLine->pre_void_amount);
        $this->assertNull($noCostLine->pre_void_cost_amount, 'a null cost stays null — nothing was tracked');
        $this->assertNull($noCostLine->cost_amount);
        $this->assertSame('0.00', $noCostLine->amount);
        $this->assertSame('0.00', $noCostLine->reportable_amount);

        $markedLine = $properlyVoided->lines()->first();
        $this->assertSame('500.00', $markedLine->pre_void_amount, 'an existing snapshot must never be overwritten with zeros');
        $this->assertSame('200.00', $markedLine->pre_void_cost_amount);
        $this->assertSame('500.00', $markedLine->display_amount);
    }

    // ── void() itself: the marker must be acquirable, not permanently missing ──

    public function test_revoiding_a_legacy_void_acquires_the_missing_marker(): void
    {
        $legacy = $this->legacyVoid(); // unmarked, still $0 — the old early-return path

        app(InvoiceVoidService::class)->void(Invoice::findOrFail($legacy->getKey()));

        $line = $legacy->lines()->first();
        $this->assertSame('0.00', $line->pre_void_amount, 're-voiding must mark the legacy line, not return early');
        $this->assertSame(InvoiceStatus::Void, $legacy->fresh()->status);
    }

    public function test_revoiding_a_legacy_void_records_the_void_time_zero_not_the_reinflated_amount(): void
    {
        $legacy = $this->legacyVoid(['amount' => '250.00', 'cost_amount' => '90.00']);

        app(InvoiceVoidService::class)->void(Invoice::findOrFail($legacy->getKey()));

        $line = $legacy->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('0.00', $line->cost_amount);
        $this->assertSame('0.00', $line->pre_void_amount);
        $this->assertSame('0.00', $line->pre_void_cost_amount);
        // The re-inflated figure is not an original bill and must not be shown as one.
        $this->assertSame('0.00', $line->display_amount);
        $this->assertSame('0.00', $line->reportable_amount);
    }

    /**
     * The MIRROR of the case above, and the one a header-only legacy predicate
     * gets wrong: a zeroed-LOOKING header that is not ours. The Stripe import
     * writes a voided invoice's retained totals verbatim, and those can
     * legitimately net to zero (a charge against an offsetting proration credit)
     * over freshly recreated, unmarked lines that carry the real bill. Reading
     * that as a legacy repair records '0.00' as the client's original per-line
     * bill and destroys the only copy of it — the re-import repeats the
     * delete-recreate every run, so nothing later restores it.
     */
    public function test_voiding_a_zero_total_invoice_whose_lines_net_to_zero_keeps_the_real_line_originals(): void
    {
        $invoice = $this->invoice(
            InvoiceStatus::Void,
            ['subtotal' => '0.00', 'tax' => '0.00', 'total' => '0.00'],
            ['description' => 'Subscription charge', 'unit_price' => '500.00', 'amount' => '500.00'],
        );
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Proration credit',
            'quantity' => 1,
            'unit_price' => '-500.00',
            'amount' => '-500.00',
            'sort_order' => 1,
        ]);

        app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->getKey()));

        $lines = $invoice->lines()->orderBy('sort_order')->get();

        // The ORIGINAL bill is preserved per line, not overwritten with '0.00'.
        $this->assertSame('500.00', $lines[0]->pre_void_amount);
        $this->assertSame('-500.00', $lines[1]->pre_void_amount);
        $this->assertSame('500.00', $lines[0]->display_amount);
        $this->assertSame('-500.00', $lines[1]->display_amount);

        // And the void still takes effect: live money zeroed, marker complete.
        $this->assertSame('0.00', $lines[0]->amount);
        $this->assertSame('0.00', $lines[1]->amount);
        $this->assertSame('0.00', $lines[0]->reportable_amount);
        $this->assertSame('0.00', $lines[1]->reportable_amount);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }
}
