<?php

namespace Tests\Feature\Invoices;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PrepayTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual "Mark as Paid" for standalone invoices (psa-8yhp).
 *
 * Without a Stripe/QBO backend the only writer of InvoiceStatus::Paid is the
 * billing sync, so a no-backend invoice could reach Posted but never Paid —
 * it stuck on the Outstanding total forever and the prepay-deposit-on-Paid
 * flow (InvoiceObserver -> PrepayService::depositFromInvoice) never fired.
 *
 * This adds a manual transition, guarded to exactly the case the sync cannot
 * reach: a Posted invoice with NO billing-backend link. A backend-synced
 * invoice takes payment status from the backend (Refresh), so marking it paid
 * by hand — which would desync PSA from the system of record — is refused.
 */
class InvoiceMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = [], array $lineAttrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-PAID-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
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
            'sort_order' => 0,
        ], $lineAttrs));

        return $invoice->fresh();
    }

    // ── Happy path ──

    public function test_mark_paid_transitions_a_posted_standalone_invoice_to_paid(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_mark_paid_deposits_prepaid_time_to_the_contract(): void
    {
        $client = Client::factory()->create();
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed->value,
            'status' => ContractStatus::Active->value,
            'start_date' => now()->subYear(),
        ]);

        $invoice = $this->makeInvoice(
            ['client_id' => $client->id, 'contract_id' => $contract->id],
            ['prepaid_time_minutes' => 120],
        );

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertEquals(2.0, (float) $contract->fresh()->prepay_balance);
        $this->assertTrue(
            PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceDeposit)
                ->exists()
        );
    }

    // ── Guards: state ──

    public function test_mark_paid_refuses_a_draft_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Draft]);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_mark_paid_refuses_a_void_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Void]);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_mark_paid_refuses_an_already_paid_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Paid]);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    // ── Guards: desync (backend-linked invoices) ──

    public function test_mark_paid_refuses_a_qbo_synced_invoice(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '42']);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Posted, $invoice->fresh()->status);
    }

    public function test_mark_paid_refuses_a_stripe_synced_invoice(): void
    {
        $invoice = $this->makeInvoice(['stripe_invoice_id' => 'in_test_123']);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.mark-paid', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Posted, $invoice->fresh()->status);
    }

    // ── Predicate ──

    public function test_can_mark_paid_predicate_matches_the_guards(): void
    {
        $this->assertTrue($this->makeInvoice()->canMarkPaid());
        $this->assertFalse($this->makeInvoice(['status' => InvoiceStatus::Draft])->canMarkPaid());
        $this->assertFalse($this->makeInvoice(['status' => InvoiceStatus::Paid])->canMarkPaid());
        $this->assertFalse($this->makeInvoice(['status' => InvoiceStatus::Void])->canMarkPaid());
        $this->assertFalse($this->makeInvoice(['qbo_invoice_id' => '7'])->canMarkPaid());
        $this->assertFalse($this->makeInvoice(['stripe_invoice_id' => 'in_1'])->canMarkPaid());
    }

    // ── View affordance ──

    public function test_show_view_offers_mark_paid_for_an_eligible_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertSee('Mark as Paid')
            ->assertSee(route('invoices.mark-paid', $invoice));
    }

    public function test_show_view_hides_mark_paid_for_an_ineligible_invoice(): void
    {
        $paid = $this->makeInvoice(['status' => InvoiceStatus::Paid]);
        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $paid))
            ->assertDontSee(route('invoices.mark-paid', $paid));

        $synced = $this->makeInvoice(['stripe_invoice_id' => 'in_test_9']);
        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $synced))
            ->assertDontSee(route('invoices.mark-paid', $synced));
    }

    // ── Bulk ──

    public function test_bulk_mark_paid_marks_eligible_and_skips_ineligible(): void
    {
        $eligible = $this->makeInvoice();
        $draft = $this->makeInvoice(['status' => InvoiceStatus::Draft]);
        $synced = $this->makeInvoice(['qbo_invoice_id' => '99']);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.bulk-action'), [
                'action' => 'mark_paid',
                'invoice_ids' => [$eligible->id, $draft->id, $synced->id],
            ])
            ->assertRedirect(route('invoices.index'));

        $this->assertSame(InvoiceStatus::Paid, $eligible->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
        $this->assertSame(InvoiceStatus::Posted, $synced->fresh()->status);
    }
}
