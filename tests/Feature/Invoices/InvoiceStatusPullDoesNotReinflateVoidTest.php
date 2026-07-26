<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Jobs\ProcessQboWebhook;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\QboWebhook;
use App\Services\InvoiceVoidService;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Status-pull TOCTOU against a local void (psa-qfhc5).
 *
 * The push-path sibling (InvoicePushDoesNotClobberPaidTest, psa-8yhp/psa-la350)
 * routes its final write through Invoice::recordPushResult(), which re-reads the
 * row under lock and refuses to write money/status back onto a Void invoice. The
 * status-PULL writers had the identical race and no such guard:
 * QboSyncService::syncInvoiceStatusFromQbo() and
 * StripeSyncService::syncInvoiceStatusFromStripe() check status === Void BEFORE
 * the vendor GET, then $invoice->update() the read-back tax/total (and possibly
 * status=Paid) AFTER the network round-trip — without re-reading under lock. QBO
 * also re-writes line amounts via syncLineItemsFromQbo(). If a local
 * InvoiceVoidService::void() commits during the round-trip, the stale write
 * re-inflates a zeroed, voided invoice's reportable tax/total (contaminating
 * sum-safe aggregates) and can flip a Void invoice to Paid.
 *
 * We reproduce the race deterministically: the mocked vendor client voids the
 * DB row from INSIDE the GET call — i.e. the void commits mid-round-trip, after
 * the pre-check passed — then returns a live, non-void, Balance==0 payload with
 * inflated money. The guard must hold: the invoice stays Void + zeroed, its
 * pre_void snapshot intact, its status never Paid, and its lines never
 * re-inflated.
 */
class InvoiceStatusPullDoesNotReinflateVoidTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = [], array $lineAttrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-PULL-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(3),
            'due_date' => now()->addDays(27),
            'subtotal' => '500.00',
            'tax' => '40.00',
            'total' => '540.00',
            'total_cost' => '200.00',
            'margin' => '340.00',
            'status' => InvoiceStatus::Synced,
        ], $attrs));

        InvoiceLine::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 5,
            'unit_price' => '100.00',
            'unit_cost' => '40.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            'is_taxable' => false,
            'sort_order' => 0,
        ], $lineAttrs));

        return $invoice->fresh();
    }

    /**
     * Void the DB row (zero + snapshot, the real service) via a fresh copy. When
     * called from inside the mocked GET this is the concurrent void committing
     * mid-round-trip; the caller's $invoice model is left stale (pre-void).
     */
    private function voidUnderneath(Invoice $invoice): void
    {
        app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->getKey()));
    }

    /** The voided invoice must stay Void AND zeroed, with its pre_void snapshot intact. */
    private function assertStillVoidAndZeroed(Invoice $invoice): void
    {
        $this->assertSame(InvoiceStatus::Void, $invoice->status);

        $this->assertSame('0.00', $invoice->subtotal);
        $this->assertSame('0.00', $invoice->tax);
        $this->assertSame('0.00', $invoice->total);
        $this->assertSame('0.00', $invoice->total_cost);
        $this->assertSame('0.00', $invoice->margin);

        // Snapshot survives so the originals remain recoverable for display.
        $this->assertSame('500.00', $invoice->pre_void_subtotal);
        $this->assertSame('40.00', $invoice->pre_void_tax);
        $this->assertSame('540.00', $invoice->pre_void_total);

        // Line money stays zeroed too — the pull must not re-inflate line amounts.
        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('500.00', $line->pre_void_amount);
    }

    /**
     * A live, non-void QBO invoice payload with Balance==0 (would flip Paid). Its
     * money (600/100/550) deliberately DIFFERS from the stale model's pre-void
     * values (540/40/500) so the write is genuinely dirty — otherwise Eloquent's
     * dirty-check masks the re-inflation and the test would pass without the
     * guard. The guard, not an accidental value match, must hold the line.
     */
    private function liveQboPayload(): array
    {
        return ['Invoice' => [
            'Id' => '7777',
            'PrivateNote' => '',       // NOT the "Voided" marker → treated as live
            'TotalAmt' => 600.0,       // would re-inflate total (≠ stale 540)
            'Balance' => 0,            // would flip status → Paid
            'TxnTaxDetail' => ['TotalTax' => 100.0],
            'Line' => [
                [
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => 550.0, // would re-inflate the zeroed line (≠ stale 500)
                    'Description' => 'Managed services',
                    'SalesItemLineDetail' => ['Qty' => 5, 'UnitPrice' => 110.0],
                ],
                ['DetailType' => 'SubTotalLineDetail', 'Amount' => 550.0],
            ],
        ]];
    }

    /** Stripe live-invoice payload (cents), money ≠ the stale pre-void values. */
    private function liveStripePayload(): array
    {
        return ['status' => 'paid', 'tax' => 10000, 'total' => 60000]; // 100.00 / 600.00
    }

    // ── QBO status-pull ──

    public function test_qbo_status_pull_does_not_reinflate_a_void_that_commits_mid_round_trip(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7777']);

        $qbo = \Mockery::mock(QboClient::class);
        // The void commits DURING the network round-trip: pre-check already passed
        // on a live model; the guarded write must re-read under lock and refuse.
        $qbo->shouldReceive('get')->once()->andReturnUsing(function () use ($invoice) {
            $this->voidUnderneath($invoice);

            return $this->liveQboPayload();
        });

        (new QboSyncService($qbo))->syncInvoiceStatusFromQbo($invoice);

        $this->assertStillVoidAndZeroed($invoice->fresh());
    }

    public function test_qbo_webhook_status_pull_does_not_reinflate_a_mid_round_trip_void(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7777']);

        $webhook = QboWebhook::create([
            'entity_type' => 'Invoice',
            'entity_id' => '7777',
            'operation' => 'Update',
            'realm_id' => 'REALM-1',
            'payload' => ['name' => 'Invoice', 'id' => '7777', 'operation' => 'Update'],
            'status' => 'pending',
        ]);

        $qbo = \Mockery::mock(QboClient::class);
        $qbo->shouldReceive('get')->once()->andReturnUsing(function () use ($invoice) {
            $this->voidUnderneath($invoice);

            return $this->liveQboPayload();
        });
        $this->app->instance(QboClient::class, $qbo);

        (new ProcessQboWebhook($webhook->id))->handle(app(QboSyncService::class));

        $this->assertStillVoidAndZeroed($invoice->fresh());
    }

    public function test_qbo_status_pull_still_syncs_a_live_invoice(): void
    {
        // Control: with no concurrent void, the pull applies read-back money and
        // the Balance==0 → Paid transition. Proves the guard is not over-broad.
        $invoice = $this->makeInvoice([
            'qbo_invoice_id' => '7777',
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
        ]);

        $qbo = \Mockery::mock(QboClient::class);
        $qbo->shouldReceive('get')->once()->andReturn($this->liveQboPayload());

        (new QboSyncService($qbo))->syncInvoiceStatusFromQbo($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('600.00', $fresh->total);
        $this->assertSame('100.00', $fresh->tax);
        $this->assertSame('550.00', $fresh->subtotal);
        $this->assertSame('550.00', $fresh->lines()->first()->amount);
    }

    // ── Stripe status-pull ──

    public function test_stripe_status_pull_does_not_reinflate_a_void_that_commits_mid_round_trip(): void
    {
        $invoice = $this->makeInvoice(['stripe_invoice_id' => 'in_race']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->andReturnUsing(function () use ($invoice) {
            $this->voidUnderneath($invoice);

            return $this->liveStripePayload(); // would re-inflate (600/100) + flip Paid
        });

        (new StripeSyncService($stripe))->syncInvoiceStatusFromStripe($invoice);

        $this->assertStillVoidAndZeroed($invoice->fresh());
    }

    public function test_stripe_status_pull_still_syncs_a_live_invoice(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_live',
            'tax' => '0.00',
            'total' => '100.00',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->andReturn($this->liveStripePayload());

        (new StripeSyncService($stripe))->syncInvoiceStatusFromStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('600.00', $fresh->total);
        $this->assertSame('100.00', $fresh->tax);
    }

    // ── The sum-safe invariant end-to-end ──

    public function test_total_based_aggregate_excludes_a_voided_invoice_after_a_stale_status_pull(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice(['client_id' => $client->id]);                              // kept: total 540.00
        $voided = $this->makeInvoice(['client_id' => $client->id, 'stripe_invoice_id' => 'in_sum']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->andReturnUsing(function () use ($voided) {
            $this->voidUnderneath($voided);

            return $this->liveStripePayload();
        });

        (new StripeSyncService($stripe))->syncInvoiceStatusFromStripe($voided);

        // Sum-safe: a voided invoice's money is zeroed so aggregates need no
        // WHERE status != 'void'. A stale pull must not re-inflate it back in —
        // pre-fix this would read 1140.00 / 140.00.
        $this->assertSame(540.0, (float) Invoice::sum('total'));
        $this->assertSame(40.0, (float) Invoice::sum('tax'));
    }
}
