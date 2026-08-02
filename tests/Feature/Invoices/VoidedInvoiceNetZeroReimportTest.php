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
 * whenever it zeroes a money-carrying header — it is rewritten, never protected
 * the way the per-line snapshot is by $snapshotted — and nothing on the Stripe
 * import path ever CLEARS it. Stickiness across the import is the premise the
 * finding turns on, and it is what the three-import assertions below pin. So a
 * non-null pre_void_total is evidence about a PREVIOUS void, not
 * about the lines currently in hand — those were deleted and recreated after
 * it. Treating it as positive evidence that "we zeroed this header" classifies
 * a freshly re-imported net-zero payload as legacy residue and stamps
 * pre_void_amount '0.00' over the real per-line bill. There is no other copy of
 * that bill, and syncStripeInvoiceLines repeats the delete-recreate on every
 * import, so nothing later restores it — irreversible per-line money loss.
 *
 * WHAT THESE TESTS PIN, AND WHAT THEY DO NOT.
 *
 * They pin the loss scenario: with `pre_void_total !== null` restored as a
 * disjunct in $repairingLegacyVoid, both tests here go red on exactly the loss
 * symptom (expected '500.00', actual '0.00'). They do NOT pin the territory the
 * removed disjunct uniquely held — states where pre_void_total is set and the
 * header does NOT contradict its lines. That territory is genuinely narrowed by
 * the edit rather than preserved, which is a deliberate, adjudicated trade and
 * is ticketed, not covered here. Nothing in this file should be read as showing
 * headerContradictsLines() alone preserves every repair the disjunct reached.
 * The ordinary re-inflated legacy void — the bulk of the repair's real work — is
 * already covered by VoidLineMarkerCompletenessTest.
 *
 * FIXTURE PROVENANCE (CLAUDE.md "Vendor response shapes", rule 2). This payload
 * is author-constructed, not captured from Stripe: reproducing it live would
 * mean voiding a real client invoice. So the one thing I would otherwise be
 * guessing — which status Stripe reports for a finalized invoice that nets to
 * zero — is covered both ways instead. It does not change the path under test:
 * the status-regression guard in upsertInvoiceFromStripeData (StripeSyncService
 * :1053-1063) forces Void whenever the existing PSA row is already Void, so a
 * 'void', 'paid' or 'open' payload reaches the same void() call over the same
 * zeroed header and the same recreated lines. Field names are taken from the
 * producer this code actually reads: StripeSyncService::upsertInvoiceFromStripeData
 * (:995-1098, which reads `customer` at :1000 and subtotal/tax/total at
 * :1044-1046) and ::syncStripeInvoiceLines (:1111-1152).
 *
 * The pinned display values do NOT reconcile with the header panel: the row
 * keeps pre_void_total 500.00 from the first void, so show.blade.php renders a
 * $500.00 original header over line originals of 500.00 / -500.00. Preserving
 * the per-line bill is still the right trade — the alternative destroys the only
 * copy — but the disagreement is real and is ticketed, not fixed here.
 */
class VoidedInvoiceNetZeroReimportTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_CUSTOMER = 'cus_oc5q2test';

    private const STRIPE_INVOICE = 'in_oc5q2test';

    private function stripeClient(array $payload): void
    {
        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('listInvoices')
            ->andReturn(['data' => [$payload], 'has_more' => false]);
        $this->app->instance(StripeClient::class, $stripe);
    }

    /**
     * Stripe's copy of the invoice after the void: it retains the charge line
     * and carries an offsetting proration credit, so the header legitimately
     * nets to zero over two individually non-zero lines. No psa_invoice_id
     * metadata — this invoice originated in Stripe, so the import's round-trip
     * guard (StripeSyncService:931-933) does not skip it.
     */
    private function netZeroPayload(string $status): array
    {
        return [
            'id' => self::STRIPE_INVOICE,
            'customer' => self::STRIPE_CUSTOMER,
            'number' => 'INV-1042',
            'status' => $status,
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
     *
     * This provenance is reachable, not hypothetical: the staff void route
     * (InvoiceController@void, :465) takes any non-Void invoice and is NOT gated
     * on is_editable, so a Stripe-linked invoice carrying money can be voided in
     * PSA — the route then propagates the void upstream (propagateVoidToStripe).
     * Bulk void (:288), QboSyncService:441 and StripeSyncService:534 reach void()
     * on a money-bearing header the same way.
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

        $invoice = $invoice->fresh();

        // Precondition — the sticky snapshot the repair predicate must not read
        // as evidence about lines that do not exist yet.
        $this->assertSame('500.00', $invoice->pre_void_total);
        $this->assertSame('0.00', $invoice->total);

        return $invoice;
    }

    public static function stripeStatusProvider(): array
    {
        return ['reported void' => ['void'], 'reported paid' => ['paid']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stripeStatusProvider')]
    public function test_psa_void_then_net_zero_stripe_reimport_keeps_the_real_line_originals(string $status): void
    {
        $invoice = $this->psaVoidedInvoice();

        $this->stripeClient($this->netZeroPayload($status));
        app(StripeSyncService::class)->importInvoicesFromStripe();

        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines, 'the import deletes and recreates the lines');

        // THE POINT: the client's original per-line bill survives the re-import.
        // Stamping '0.00' here destroys the only copy of it.
        $this->assertSame('500.00', $lines[0]->pre_void_amount);
        $this->assertSame('-500.00', $lines[1]->pre_void_amount);
        $this->assertSame('500.00', $lines[0]->display_amount);
        $this->assertSame('-500.00', $lines[1]->display_amount);

        // The void still takes effect and the status is not downgraded.
        $this->assertSame('0.00', $lines[0]->amount);
        $this->assertSame('0.00', $lines[1]->amount);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_the_loss_does_not_accumulate_across_repeated_imports(): void
    {
        $invoice = $this->psaVoidedInvoice();

        // syncStripeInvoiceLines delete-recreates every run, so each import
        // presents unmarked lines again. The finding's "repeats on every
        // import" must be harmless, not merely survivable once.
        $this->stripeClient($this->netZeroPayload('void'));
        app(StripeSyncService::class)->importInvoicesFromStripe();
        app(StripeSyncService::class)->importInvoicesFromStripe();
        app(StripeSyncService::class)->importInvoicesFromStripe();

        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('500.00', $lines[0]->pre_void_amount);
        $this->assertSame('-500.00', $lines[1]->pre_void_amount);
        $this->assertSame('0.00', $lines[0]->amount);
        $this->assertSame('0.00', $lines[1]->amount);

        // The premise the whole finding turns on: pre_void_total is sticky, and
        // three passes of updateOrCreate over the row do not clear it. If this
        // ever goes false the scenario above stops being reachable and these
        // tests would pass vacuously.
        $reloaded = $invoice->fresh();
        $this->assertSame('500.00', $reloaded->pre_void_total);
        $this->assertSame('500.00', $reloaded->pre_void_subtotal);
    }
}
