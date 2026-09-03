<?php

namespace Tests\Feature\Qbo;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceStatusChangeSource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceStatusChangeLog;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #1173 / T-22802 — the QBO status pull is two-way, and the walk can see a
 * Paid invoice.
 *
 * Nine invoices read Paid in the PSA while QuickBooks showed them open. Two
 * independent faults kept them that way: syncInvoiceStatusFromQbo() had one
 * status branch (Balance == 0 → Paid) so nothing could ever un-pay a row, and
 * the 4-hourly walk used Invoice::unpaid(), which excludes Paid by definition,
 * so a Paid row was never looked at again in the first place. Fixing either
 * alone leaves the defect: the branch with no walk never runs on these rows,
 * the walk with no branch visits them and does nothing.
 */
class QboPaidInvoiceRevertTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-1173-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->subDays(1),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Paid,
            'qbo_invoice_id' => 'QBO-'.self::$seq,
        ], $attrs));
    }

    /**
     * QBO's GET response for one invoice. $balance null omits the Balance key
     * entirely, which is how a malformed/partial payload is expressed.
     */
    private function qboPayload(?float $balance, float $total = 500.0, array $extra = []): array
    {
        $payload = array_merge([
            'Id' => 'QBO-1',
            'TotalAmt' => $total,
            'TxnTaxDetail' => ['TotalTax' => 0],
            'Line' => [
                ['DetailType' => 'SubTotalLineDetail', 'Amount' => $total],
            ],
        ], $extra);

        if ($balance !== null) {
            $payload['Balance'] = $balance;
        }

        return $payload;
    }

    /** @param array<int, array|\Throwable> $responses one per GET, in order */
    private function mockQboGets(array $responses): void
    {
        $this->mock(QboClient::class, function (MockInterface $m) use ($responses): void {
            $queue = $responses;
            $m->shouldReceive('get')->andReturnUsing(function () use (&$queue) {
                $next = array_shift($queue);

                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return ['Invoice' => $next];
            });
            $m->shouldReceive('post')->andReturn([]);
        });
    }

    // ── the reverse branch ───────────────────────────────────────────────────

    public function test_a_paid_invoice_qbo_still_shows_owed_in_full_reverts_to_posted(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(500.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Posted, $invoice->fresh()->status);
    }

    public function test_the_revert_target_is_posted_so_an_overdue_invoice_can_still_say_so(): void
    {
        // Synced is also payable, so a reader might think either would do.
        // Only Posted is reachable by scopeOverdue(); reverting to Synced would
        // hide a past-due invoice from the overdue list for ever.
        $invoice = $this->makeInvoice(['due_date' => now()->subDays(10)]);
        $this->mockQboGets([$this->qboPayload(500.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertTrue(Invoice::overdue()->whereKey($invoice->id)->exists());
    }

    public function test_the_revert_records_the_qbo_balance_and_a_full_reversal_reason(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(500.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(InvoiceStatusChangeSource::QboPull, $log->source);
        $this->assertSame('paid', $log->previous_status);
        $this->assertSame('posted', $log->new_status);
        $this->assertSame('500.00', $log->qbo_balance);
        $this->assertStringContainsString('reversed or never applied', $log->reason);
        $this->assertNull($log->changed_by);
    }

    public function test_a_partial_balance_is_described_as_partial_not_as_the_whole_amount(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(120.50)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('120.50', $log->qbo_balance);
        $this->assertStringContainsString('120.50 of 500.00 still owed', $log->reason);
        $this->assertStringContainsString('partial', $log->reason);
        $this->assertStringNotContainsString('reversed or never applied', $log->reason);
    }

    public function test_a_settled_balance_leaves_a_paid_invoice_alone_and_records_nothing(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(0.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_the_forward_direction_still_marks_an_open_invoice_paid(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Posted]);
        $this->mockQboGets([$this->qboPayload(0.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('0.00', $log->qbo_balance);
        $this->assertStringContainsString('settled in full', $log->reason);
    }

    public function test_a_payload_with_no_balance_field_does_not_revert_a_paid_invoice(): void
    {
        // The pre-existing default for a missing Balance is the invoice total,
        // i.e. "fully owed". That was inert while the only branch set Paid; with
        // a reverse branch it would turn any malformed or truncated QBO payload
        // into a revert. Absent Balance must move nothing.
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(null)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_non_numeric_balance_does_not_revert_a_paid_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(null, 500.0, ['Balance' => 'unknown'])]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_non_numeric_balance_does_not_mark_an_open_invoice_paid(): void
    {
        // The mirror of the test above, and the more dangerous half: 'unknown'
        // casts to 0.0, which reads as settled in full. An invoice nobody has
        // paid must not acquire a Paid status from a payload QBO never filled
        // in — that is exactly the state the nine rows were found in.
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Posted]);
        $this->mockQboGets([$this->qboPayload(null, 500.0, ['Balance' => 'unknown'])]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Posted, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_qbo_side_void_takes_the_void_path_not_the_revert_path(): void
    {
        // qboInvoiceIsVoided() returns before the balance branch, so "not
        // voided" on the revert arm is structural. Pinned so a later reorder
        // cannot quietly turn a cancelled invoice into a newly-owed one.
        $invoice = $this->makeInvoice();
        $this->mockQboGets([$this->qboPayload(500.0, 0.0, [
            'PrivateNote' => 'Voided',
            'TotalAmt' => 0.0,
        ])]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_a_locally_voided_invoice_is_never_re_inflated_by_the_revert(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Void]);
        $this->mockQboGets([$this->qboPayload(500.0)]);

        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    // ── the widened walk ─────────────────────────────────────────────────────

    public function test_the_paid_pass_visits_paid_invoices_the_unpaid_walk_cannot_see(): void
    {
        $invoice = $this->makeInvoice(['qbo_synced_at' => null]);
        $this->mockQboGets([$this->qboPayload(500.0)]);

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['reverted']);
        $this->assertSame(InvoiceStatus::Posted, $invoice->fresh()->status);
    }

    public function test_the_paid_pass_ignores_paid_invoices_with_no_qbo_id(): void
    {
        // A Halo- or hand-created invoice QBO has never heard of has nothing to
        // be checked against; a GET would 404 and burn a call.
        $this->makeInvoice(['qbo_invoice_id' => null]);
        $this->mockQboGets([]);

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo();

        $this->assertSame(0, $result['checked']);
    }

    public function test_the_paid_pass_is_bounded_by_the_limit(): void
    {
        // The bound is the whole reason this is a separate pass: on Sound IT's
        // own ledger the first run has ~2,550 candidates, one QBO GET each,
        // inside a scheduler slot holding a 10-minute overlap lock.
        foreach (range(1, 5) as $ignored) {
            $this->makeInvoice(['qbo_synced_at' => null]);
        }
        $this->mockQboGets(array_fill(0, 5, $this->qboPayload(0.0)));

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo(limit: 2);

        $this->assertSame(2, $result['checked']);
        $this->assertSame(3, $result['never_checked'], 'the unvisited rows must still be reported as owed a check');
    }

    public function test_never_synced_invoices_are_checked_before_previously_synced_ones(): void
    {
        $synced = $this->makeInvoice(['qbo_synced_at' => now()->subDay()]);
        $neverSynced = $this->makeInvoice(['qbo_synced_at' => null]);

        // Only the first invoice visited gets a revert answer; whichever row
        // ends Posted is the one the pass chose to check first.
        $this->mockQboGets([$this->qboPayload(500.0)]);

        app(QboSyncService::class)->syncPaidInvoicesFromQbo(limit: 1);

        $this->assertSame(InvoiceStatus::Posted, $neverSynced->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $synced->fresh()->status);
    }

    public function test_never_checked_counts_only_rows_qbo_has_never_been_asked_about(): void
    {
        $this->makeInvoice(['qbo_synced_at' => null]);
        $this->makeInvoice(['qbo_synced_at' => now()->subDay()]);
        $this->mockQboGets([$this->qboPayload(0.0)]);

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo(limit: 1);

        // The one never-synced row was checked and stamped, so the backlog that
        // matters is now empty even though a synced Paid row still exists.
        $this->assertSame(0, $result['never_checked']);
    }

    public function test_one_failing_invoice_does_not_abort_the_pass(): void
    {
        $first = $this->makeInvoice(['qbo_synced_at' => null]);
        $second = $this->makeInvoice(['qbo_synced_at' => null]);
        $this->mockQboGets([
            new QboClientException('QBO 500'),
            $this->qboPayload(500.0),
        ]);

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo();

        $this->assertSame(1, $result['errors']);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['reverted']);
        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::Posted, $second->fresh()->status);
    }

    public function test_a_qbo_side_void_found_by_the_pass_is_not_counted_as_a_revert(): void
    {
        // "No longer Paid" would count a cancelled invoice as a newly-owed one,
        // and the count is what the operator is shown.
        $this->makeInvoice(['qbo_synced_at' => null]);
        $this->mockQboGets([$this->qboPayload(500.0, 0.0, [
            'PrivateNote' => 'Voided',
            'TotalAmt' => 0.0,
        ])]);

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['reverted']);
    }
}
