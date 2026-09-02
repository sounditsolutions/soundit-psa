<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceStatusChangeSource;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceStatusChangeLog;
use App\Models\Person;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\InvoiceVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1173 / T-22802 — every invoice status move is recorded, whoever made it.
 *
 * The nine invoices that started this carried a Paid status nothing on the row
 * accounted for; the provenance had to be reconstructed from which columns
 * happened to be null. Following #992's audit-seam ruling, the record is
 * written at the MODEL level (InvoiceObserver), so a writer added later is
 * captured without opting in — the property these tests exist to hold.
 */
class InvoiceStatusChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::create(['name' => 'Acme Corp'])->id;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-LOG-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));
    }

    // ── the seam ─────────────────────────────────────────────────────────────

    public function test_creating_an_invoice_records_no_change(): void
    {
        // An opening status is not a change: the row itself carries it beside
        // created_at and nothing was destroyed. Same call as #992's.
        $invoice = $this->makeInvoice();

        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_bare_model_write_no_writer_opted_in_is_still_recorded(): void
    {
        // The whole point of the observer seam: a writer that knows nothing
        // about the log is captured anyway.
        $invoice = $this->makeInvoice();

        $invoice->update(['status' => InvoiceStatus::Paid]);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('posted', $log->previous_status);
        $this->assertSame('paid', $log->new_status);
        $this->assertSame(InvoiceStatusChangeSource::System, $log->source);
    }

    public function test_a_non_status_write_records_nothing(): void
    {
        $invoice = $this->makeInvoice();

        $invoice->update(['notes' => 'chased by phone']);

        $this->assertSame(0, InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_staff_mark_paid_is_attributed_to_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice();

        $this->actingAs($user);
        app(InvoiceService::class)->markPaid($invoice);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(InvoiceStatusChangeSource::Staff, $log->source);
        $this->assertSame($user->id, $log->changed_by);
    }

    public function test_an_unauthenticated_writer_is_recorded_as_system_not_skipped(): void
    {
        $invoice = $this->makeInvoice();

        $invoice->update(['status' => InvoiceStatus::Paid]);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(InvoiceStatusChangeSource::System, $log->source);
        $this->assertNull($log->changed_by);
    }

    public function test_voiding_an_invoice_is_recorded(): void
    {
        $invoice = $this->makeInvoice();

        app(InvoiceVoidService::class)->void($invoice);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('void', $log->new_status);
    }

    public function test_a_declared_context_does_not_leak_onto_a_later_unrelated_write(): void
    {
        // The context is execution state, not an attribute. If it survived the
        // save that consumed it, the next status move on the same instance
        // would be attributed to QuickBooks and carry a balance QBO never
        // reported for it.
        $invoice = $this->makeInvoice();
        $invoice->statusChangeContext = new \App\Support\InvoiceStatusChangeContext(
            source: InvoiceStatusChangeSource::QboPull,
            reason: 'first move',
            qboBalance: 42.0,
        );

        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->update(['status' => InvoiceStatus::Posted]);

        $logs = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(InvoiceStatusChangeSource::QboPull, $logs[0]->source);
        $this->assertSame('42.00', $logs[0]->qbo_balance);
        $this->assertSame(InvoiceStatusChangeSource::System, $logs[1]->source);
        $this->assertNull($logs[1]->qbo_balance);
        $this->assertNull($logs[1]->reason);
    }

    public function test_a_non_staff_source_inside_a_staff_request_credits_no_user(): void
    {
        // A technician pressing "Refresh from QBO" asked for a refresh; they did
        // not decide the invoice was unpaid. Stamping them as the actor would
        // put a person's name on QuickBooks's decision.
        $user = User::factory()->create();
        $invoice = $this->makeInvoice();
        $invoice->statusChangeContext = new \App\Support\InvoiceStatusChangeContext(
            source: InvoiceStatusChangeSource::QboPull,
            reason: 'QuickBooks reports the balance settled in full.',
            qboBalance: 0.0,
        );

        $this->actingAs($user);
        $invoice->update(['status' => InvoiceStatus::Paid]);

        $log = InvoiceStatusChangeLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(InvoiceStatusChangeSource::QboPull, $log->source);
        $this->assertNull($log->changed_by);
    }

    // ── prepay ───────────────────────────────────────────────────────────────

    private function makeContractInvoiceWithPrepaidTime(): Invoice
    {
        $client = Client::create(['name' => 'Prepay Corp']);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => '2026-01-01',
        ]);

        $invoice = $this->makeInvoice(['client_id' => $client->id, 'contract_id' => $contract->id]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Prepaid block',
            'quantity' => 1,
            'unit_price' => '500.00',
            'unit_cost' => '200.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            'is_taxable' => false,
            'sort_order' => 0,
            'prepaid_time_minutes' => 600,
        ]);

        return $invoice->fresh();
    }

    public function test_reverting_from_paid_takes_the_prepaid_hours_back(): void
    {
        // handlePaid deposited them on the way in. If the way out does not
        // reverse, the client keeps ten hours they have not paid for.
        $invoice = $this->makeContractInvoiceWithPrepaidTime();
        $invoice->update(['status' => InvoiceStatus::Paid]);

        $this->assertSame(1, PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_deposit')->count());

        $invoice->update(['status' => InvoiceStatus::Posted]);

        $reversal = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_reversal')->sole();
        $this->assertSame(-10.0, (float) $reversal->hours);
    }

    public function test_the_revert_reversal_does_not_claim_the_invoice_was_voided(): void
    {
        $invoice = $this->makeContractInvoiceWithPrepaidTime();
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->update(['status' => InvoiceStatus::Posted]);

        $reversal = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_reversal')->sole();
        $this->assertStringContainsString('no longer paid', $reversal->description);
        $this->assertStringNotContainsString('voided', $reversal->description);
    }

    public function test_voiding_still_says_voided(): void
    {
        $invoice = $this->makeContractInvoiceWithPrepaidTime();
        $invoice->update(['status' => InvoiceStatus::Paid]);

        app(InvoiceVoidService::class)->void($invoice);

        $reversal = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_reversal')->sole();
        $this->assertStringContainsString('voided', $reversal->description);
    }

    public function test_a_paid_open_paid_open_cycle_deposits_once_and_reverses_once(): void
    {
        $invoice = $this->makeContractInvoiceWithPrepaidTime();
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->update(['status' => InvoiceStatus::Posted]);
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->update(['status' => InvoiceStatus::Posted]);

        $this->assertSame(1, PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_deposit')->count());
        $this->assertSame(1, PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', 'invoice_reversal')->count());
        $this->assertSame(0.0, (float) $invoice->contract->fresh()->prepay_balance);
    }

    // ── what the portal shows ────────────────────────────────────────────────

    private function partiallyRevert(Invoice $invoice, float $balance): void
    {
        $invoice->statusChangeContext = new \App\Support\InvoiceStatusChangeContext(
            source: InvoiceStatusChangeSource::QboPull,
            reason: 'QuickBooks reports a balance.',
            qboBalance: $balance,
        );
        $invoice->update(['status' => InvoiceStatus::Posted]);
    }

    public function test_a_partial_balance_is_reported_on_the_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);

        $log = $invoice->fresh()->qboPartialBalanceLog();
        $this->assertNotNull($log);
        $this->assertSame('120.50', $log->qbo_balance);
    }

    public function test_a_full_balance_is_not_a_partial_payment(): void
    {
        // The client owes the whole invoice; the line already says so. A
        // "partially paid" note here would be wrong.
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 500.00);

        $this->assertNull($invoice->fresh()->qboPartialBalanceLog());
    }

    public function test_a_re_paid_invoice_reports_no_outstanding_partial(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);
        $invoice->fresh()->update(['status' => InvoiceStatus::Paid]);

        $this->assertNull($invoice->fresh()->qboPartialBalanceLog());
    }

    public function test_the_portal_invoice_page_shows_the_partial_balance_and_its_date(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::create(['name' => 'Portal Corp']);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-1173@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ]);

        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);

        $this->actingAs($person, 'portal')
            ->get(route('portal.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Partially paid')
            ->assertSee('$120.50')
            // The figure is as-of the last QBO pull and can be a cycle old;
            // showing it undated would present stale money as current.
            ->assertSee($invoice->fresh()->latestQboStatusChange->created_at->format('M j, Y'));
    }

    public function test_the_portal_says_nothing_when_there_is_no_partial(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::create(['name' => 'Portal Corp']);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-1173b@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ]);

        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 500.00);

        $this->actingAs($person, 'portal')
            ->get(route('portal.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Partially paid');
    }
}
