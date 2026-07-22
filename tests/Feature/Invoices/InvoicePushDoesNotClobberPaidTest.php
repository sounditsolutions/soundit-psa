<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceVoidService;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Write-point TOCTOU, the OTHER half (psa-8yhp / psa-la350 REVISE).
 *
 * Mark-as-Paid locks the invoice row and re-checks eligibility at its write.
 * But the billing-backend push writers (QboSyncService::pushInvoiceToQbo,
 * StripeSyncService::pushInvoiceToStripe) set status=Synced + a backend id
 * from a possibly-stale model AFTER a network round-trip. A push already in
 * flight when a manual Mark-as-Paid commits would otherwise overwrite the
 * just-Paid invoice back to Synced/Outstanding while the prepay deposit
 * already fired. psa-946hr's rule: EVERY financial-status writer sharing the
 * invariant must re-check at the write point, not only the new one.
 *
 * Fix: the CREATE-path status write goes through Invoice::recordPushResult(),
 * which re-reads the row under lock and preserves a terminal Paid/Void status
 * (still recording the backend id, so the external invoice is not orphaned —
 * a Paid invoice legitimately carries a backend id).
 *
 * The VOID half (psa-la350 R2 REVISE): preserving the Void *status* is not
 * enough. InvoiceVoidService::void() also zeroes the reportable money fields
 * (subtotal/tax/total/total_cost/margin + line amounts) and snapshots the
 * originals into pre_void_*, so financial aggregates exclude the invoice
 * structurally. A push in flight when the void commits carries the live
 * backend tax/total; writing it back would re-inflate a voided invoice and
 * silently re-enter it into Outstanding / profitability totals while it still
 * reads as Void. Every push write-point — recordPushResult (CREATE) AND the
 * QBO UPDATE branch — must refuse to write money back onto a Void invoice.
 */
class InvoicePushDoesNotClobberPaidTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = [], array $lineAttrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-PUSH-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(3),
            'due_date' => now()->addDays(27),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
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

    /** Simulate a concurrent Mark-as-Paid committing while $invoice (stale) is mid-push. */
    private function driftTo(Invoice $invoice, InvoiceStatus $status): void
    {
        Invoice::whereKey($invoice->getKey())->update(['status' => $status->value]);
    }

    /**
     * Void the DB row (zero + snapshot, the real service) via a fresh copy,
     * leaving $invoice a stale pre-void model — exactly what a push writer
     * holds after its API round-trip when a void commits underneath it.
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

        // Line money stays zeroed too (no push writer rewrites local line amounts).
        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('500.00', $line->pre_void_amount);
    }

    // ── The guarded write itself (Invoice::recordPushResult) ──

    public function test_record_push_result_transitions_posted_to_synced(): void
    {
        $invoice = $this->makeInvoice();

        $invoice->recordPushResult(['qbo_invoice_id' => '9001', 'qbo_synced_at' => now()]);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
        $this->assertSame('9001', $fresh->qbo_invoice_id);
    }

    public function test_record_push_result_does_not_clobber_a_paid_invoice_but_records_the_id(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Paid]);

        $invoice->recordPushResult(['qbo_invoice_id' => '9001', 'qbo_synced_at' => now()]);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);   // NOT overwritten to Synced
        $this->assertSame('9001', $fresh->qbo_invoice_id);         // id still recorded (no orphan)
    }

    public function test_record_push_result_does_not_clobber_a_void_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Void]);

        $invoice->recordPushResult(['stripe_invoice_id' => 'in_x', 'stripe_synced_at' => now()]);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('in_x', $fresh->stripe_invoice_id);
    }

    // ── End-to-end through the real push services ──

    public function test_qbo_push_does_not_clobber_an_invoice_that_drifted_to_paid(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-1']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);

        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('post')->andReturn([
                'Invoice' => [
                    'Id' => '9001',
                    'DocNumber' => 'DOC-9001',
                    'TotalAmt' => 500.0,
                    'TxnTaxDetail' => ['TotalTax' => 0],
                ],
            ]);
        });

        // A concurrent Mark-as-Paid commits while this push holds a stale model.
        $this->driftTo($invoice, InvoiceStatus::Paid);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);   // push did NOT resurrect it to Synced/Outstanding
        $this->assertSame('9001', $fresh->qbo_invoice_id);         // but the QBO invoice id is recorded (no orphan)
    }

    public function test_stripe_push_does_not_clobber_an_invoice_that_drifted_to_paid(): void
    {
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_clobber']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->andReturn(['id' => 'in_clobber']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->andReturn([
            'tax' => 0,
            'total' => 50000,
            'hosted_invoice_url' => 'https://pay.example/in_clobber',
        ]);

        $this->driftTo($invoice, InvoiceStatus::Paid);

        (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('in_clobber', $fresh->stripe_invoice_id);
    }

    // ── A stale push must not re-inflate a voided (zeroed) invoice (psa-la350 R2) ──

    public function test_record_push_result_does_not_reinflate_a_voided_and_zeroed_invoice(): void
    {
        $invoice = $this->makeInvoice(['tax' => '40.00', 'total' => '540.00']);
        $this->voidUnderneath($invoice); // DB row now Void + zeroed; $invoice stays stale

        // The completing push carries the live backend tax/total.
        $invoice->recordPushResult([
            'qbo_invoice_id' => '9001',
            'qbo_doc_number' => 'DOC-9001',
            'tax' => '40.00',
            'total' => '540.00',
            'qbo_synced_at' => now(),
            'qbo_sync_error' => null,
        ]);

        $fresh = $invoice->fresh();
        $this->assertStillVoidAndZeroed($fresh);
        // The backend id is still recorded — external invoice flagged for
        // reconciliation, not orphaned.
        $this->assertSame('9001', $fresh->qbo_invoice_id);
    }

    public function test_qbo_create_push_does_not_reinflate_a_voided_invoice(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-9']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'tax' => '40.00', 'total' => '540.00']);

        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('post')->andReturn([
                'Invoice' => [
                    'Id' => '9001',
                    'DocNumber' => 'DOC-9001',
                    'TotalAmt' => 540.0,
                    'TxnTaxDetail' => ['TotalTax' => 40.0],
                ],
            ]);
        });

        $this->voidUnderneath($invoice); // void commits while the push holds a stale model

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $fresh = $invoice->fresh();
        $this->assertStillVoidAndZeroed($fresh);
        $this->assertSame('9001', $fresh->qbo_invoice_id);
    }

    public function test_qbo_update_push_does_not_reinflate_a_voided_invoice(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-U']);
        // Already synced (has a qbo_invoice_id) → pushInvoiceToQbo takes the UPDATE path.
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'qbo_invoice_id' => '7777',
            'status' => InvoiceStatus::Synced,
            'tax' => '40.00',
            'total' => '540.00',
        ]);

        // The backend returns tax/total that DIFFER from the stale model (e.g. a
        // tax recalc), so the write is genuinely dirty — otherwise Eloquent's
        // dirty-check would mask the re-inflation and the test would pass without
        // the guard. The guard, not an accidental value match, must hold the line.
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('get')->andReturn(['Invoice' => ['Id' => '7777', 'SyncToken' => '2']]);
            $m->shouldReceive('post')->andReturn([
                'Invoice' => ['Id' => '7777', 'TotalAmt' => 600.0, 'TxnTaxDetail' => ['TotalTax' => 100.0]],
            ]);
        });

        $this->voidUnderneath($invoice); // DB row voided+zeroed; $invoice stale (Synced)

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertStillVoidAndZeroed($invoice->fresh());
    }

    public function test_stripe_push_does_not_reinflate_a_voided_invoice(): void
    {
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_void_race']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'tax' => '40.00', 'total' => '540.00']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->andReturn(['id' => 'in_void_race']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->andReturn([
            'tax' => 4000,
            'total' => 54000,
            'hosted_invoice_url' => 'https://pay.example/in_void_race',
        ]);

        $this->voidUnderneath($invoice);

        (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertStillVoidAndZeroed($fresh);
        $this->assertSame('in_void_race', $fresh->stripe_invoice_id);
    }

    public function test_total_based_aggregate_excludes_a_voided_invoice_after_a_stale_push(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice(['client_id' => $client->id, 'tax' => '40.00', 'total' => '540.00']); // kept
        $voided = $this->makeInvoice(['client_id' => $client->id, 'tax' => '40.00', 'total' => '540.00']);

        $this->voidUnderneath($voided);
        // A late push completes carrying the live total/tax.
        $voided->recordPushResult([
            'stripe_invoice_id' => 'in_late',
            'tax' => '40.00',
            'total' => '540.00',
            'stripe_synced_at' => now(),
        ]);

        // The sum-safe design zeroes a voided invoice's money so any aggregate
        // over `total`/`tax` needs no WHERE status != 'void' filter. A stale push
        // must not re-inflate the voided row back into those sums — pre-fix this
        // would read 1080.00 / 80.00.
        $this->assertSame(540.0, (float) Invoice::sum('total'));
        $this->assertSame(40.0, (float) Invoice::sum('tax'));
    }
}
