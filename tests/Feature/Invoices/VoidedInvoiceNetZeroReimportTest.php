<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceVoidService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The legacy-void repair must not fire on a PSA-voided invoice that Stripe
 * later re-imports net-zero (psa-oc5q2.1, review finding c3:v1:1).
 *
 * pre_void_total is a STICKY row-level snapshot: InvoiceVoidService writes it
 * once, when it zeroes a money-carrying header, and nothing on the Stripe
 * import path ever clears it. So it is evidence about a PREVIOUS void, not
 * about the lines currently in hand — those were deleted and recreated after
 * it. Treating it as positive evidence that "we zeroed this header" classifies
 * a freshly re-imported net-zero payload as legacy residue and stamps
 * pre_void_amount '0.00' over the real per-line bill. There is no other copy of
 * that bill, and syncStripeInvoiceLines repeats the delete-recreate on every
 * import, so nothing later restores it — irreversible per-line money loss,
 * the same class the branch was written to close.
 *
 * headerContradictsLines() is the sound half of the predicate and is what these
 * tests leave standing: only OUR zeroing empties a header out from under line
 * money it is supposed to total. A header that still totals its lines — both
 * $0, or a charge against an offsetting proration credit — was never zeroed by
 * us.
 *
 * The first test drives the REAL import (importInvoicesFromStripe over a mocked
 * StripeClient) rather than hand-building the post-import state, because
 * reachability through an ordinary flow is half the finding.
 */
class VoidedInvoiceNetZeroReimportTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_CUSTOMER = 'cus_oc5q2test';

    private const STRIPE_INVOICE = 'in_oc5q2test';

    private function stripeClient(array $payload): StripeClient
    {
        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('listInvoices')
            ->andReturn(['data' => [$payload], 'has_more' => false]);
        $this->app->instance(StripeClient::class, $stripe);

        return $stripe;
    }

    /**
     * Stripe's copy of the invoice after the void: it retains the charge line
     * and carries an offsetting proration credit, so the header legitimately
     * nets to zero over two individually non-zero lines. No psa_invoice_id
     * metadata — this invoice originated in Stripe, so the import's round-trip
     * guard does not skip it.
     */
    private function netZeroVoidPayload(): array
    {
        return [
            'id' => self::STRIPE_INVOICE,
            'customer' => self::STRIPE_CUSTOMER,
            'number' => 'INV-1042',
            'status' => 'void',
            'created' => 1774000000,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'lines' => [
                'has_more' => false,
                'data' => [
                    ['description' => 'Subscription charge', 'quantity' => 1, 'amount' => 50000],
                    ['description' => 'Proration credit', 'quantity' => 1, 'amount' => -50000],
                ],
            ],
        ];
    }

    /**
     * A Stripe-originated invoice, imported with real money, then voided in PSA
     * through the real primitive — which is what makes pre_void_total sticky.
     */
    private function psaVoidedInvoice(): Invoice
    {
        $client = Client::factory()->create(['stripe_customer_id' => self::STRIPE_CUSTOMER]);

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-1042',
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'status' => InvoiceStatus::Synced,
            'stripe_invoice_id' => self::STRIPE_INVOICE,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Subscription charge',
            'quantity' => 1,
            'unit_price' => '500.00',
            'amount' => '500.00',
            'sort_order' => 0,
        ]);

        app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->getKey()));

        return $invoice->fresh();
    }

    public function test_psa_void_then_net_zero_stripe_reimport_keeps_the_real_line_originals(): void
    {
        $invoice = $this->psaVoidedInvoice();

        // Precondition — the sticky snapshot the repair predicate must not read
        // as evidence about lines that do not exist yet.
        $this->assertSame('500.00', $invoice->pre_void_total);
        $this->assertSame('0.00', $invoice->total);

        $this->stripeClient($this->netZeroVoidPayload());
        app(StripeSyncService::class)->importInvoicesFromStripe();

        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines, 'the import deletes and recreates the lines');

        // THE POINT: the client's original per-line bill survives the re-import.
        // Stamping '0.00' here destroys the only copy of it.
        $this->assertSame('500.00', $lines[0]->pre_void_amount);
        $this->assertSame('-500.00', $lines[1]->pre_void_amount);
        $this->assertSame('500.00', $lines[0]->display_amount);
        $this->assertSame('-500.00', $lines[1]->display_amount);

        // And the void still takes effect: live money zeroed, marker complete,
        // status not downgraded.
        $this->assertSame('0.00', $lines[0]->amount);
        $this->assertSame('0.00', $lines[1]->amount);
        $this->assertSame('0.00', $lines[0]->reportable_amount);
        $this->assertSame('0.00', $lines[1]->reportable_amount);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_the_loss_does_not_accumulate_across_repeated_imports(): void
    {
        $invoice = $this->psaVoidedInvoice();

        // syncStripeInvoiceLines delete-recreates every run, so each import
        // presents unmarked lines again. The finding's "repeats on every
        // import" must be harmless, not merely survivable once.
        $this->stripeClient($this->netZeroVoidPayload());
        app(StripeSyncService::class)->importInvoicesFromStripe();
        app(StripeSyncService::class)->importInvoicesFromStripe();
        app(StripeSyncService::class)->importInvoicesFromStripe();

        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('500.00', $lines[0]->pre_void_amount);
        $this->assertSame('-500.00', $lines[1]->pre_void_amount);
        $this->assertSame('0.00', $lines[0]->amount);
        $this->assertSame('0.00', $lines[1]->amount);
    }

    /**
     * The complement, and the coverage the removed disjunct was reaching for:
     * dropping it must NOT cost us the legacy repair. A genuine legacy void —
     * header zeroed by us, an unmarked line since re-inflated out of the void
     * lock by the QBO status pull — still contradicts its own header, so
     * headerContradictsLines() alone still classifies it as residue and records
     * the void-time $0 rather than the re-inflated figure.
     */
    public function test_a_reinflated_legacy_void_is_still_repaired_without_the_snapshot_disjunct(): void
    {
        $client = Client::factory()->create();

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-LEGACY-OC5Q2',
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            // Zeroed by us, and carrying the snapshot that proves it.
            'subtotal' => '0.00',
            'tax' => '0.00',
            'total' => '0.00',
            'pre_void_subtotal' => '500.00',
            'pre_void_tax' => '0.00',
            'pre_void_total' => '500.00',
            'status' => InvoiceStatus::Void,
        ]);

        // The $0 line the old code skipped, since re-inflated out-of-lock.
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'No-charge item',
            'quantity' => 1,
            'unit_price' => '0.00',
            'unit_cost' => '0.00',
            'amount' => '250.00',
            'cost_amount' => '90.00',
            'sort_order' => 0,
        ]);

        app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->getKey()));

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->pre_void_amount, 'the re-inflated figure is not an original bill');
        $this->assertSame('0.00', $line->pre_void_cost_amount);
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('0.00', $line->display_amount);
    }
}
