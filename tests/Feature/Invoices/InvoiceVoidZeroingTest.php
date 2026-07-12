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
use App\Services\InvoiceVoidService;
use App\Services\ProfitabilityService;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Sum-safe void handling (psa-dw47): voiding an invoice snapshots the
 * original amounts into pre_void_* columns and zeroes the reportable money
 * fields, on every void path (staff routes, QBO void detection, Stripe
 * import), so aggregates exclude voided invoices without needing a
 * WHERE status != 'void' filter. The detail view shows the pre-void
 * originals behind an explicit banner.
 */
class InvoiceVoidZeroingTest extends TestCase
{
    use RefreshDatabase;

    private static int $invoiceSeq = 0;

    private function makeInvoice(array $attrs = [], array $lineAttrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-VOID-'.str_pad((string) ++self::$invoiceSeq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
            'subtotal' => '500.00',
            'tax' => '40.00',
            'total' => '540.00',
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

    private function assertZeroedWithSnapshot(Invoice $invoice): void
    {
        $this->assertSame(InvoiceStatus::Void, $invoice->status);

        $this->assertSame('0.00', $invoice->subtotal);
        $this->assertSame('0.00', $invoice->tax);
        $this->assertSame('0.00', $invoice->total);
        $this->assertSame('0.00', $invoice->total_cost);
        $this->assertSame('0.00', $invoice->margin);

        $this->assertSame('500.00', $invoice->pre_void_subtotal);
        $this->assertSame('40.00', $invoice->pre_void_tax);
        $this->assertSame('540.00', $invoice->pre_void_total);
        $this->assertSame('200.00', $invoice->pre_void_total_cost);
        $this->assertSame('300.00', $invoice->pre_void_margin);

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('0.00', $line->cost_amount);
        $this->assertSame('500.00', $line->pre_void_amount);
        $this->assertSame('200.00', $line->pre_void_cost_amount);
    }

    // ── PSA-initiated void (staff routes) ──

    public function test_void_route_zeroes_amounts_and_snapshots_originals(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertZeroedWithSnapshot($invoice->fresh());
    }

    public function test_bulk_void_action_zeroes_each_invoice(): void
    {
        $first = $this->makeInvoice();
        $second = $this->makeInvoice();

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.bulk-action'), [
                'action' => 'void',
                'invoice_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('invoices.index'));

        $this->assertZeroedWithSnapshot($first->fresh());
        $this->assertZeroedWithSnapshot($second->fresh());
    }

    public function test_voiding_zero_amount_invoice_records_no_snapshot(): void
    {
        $invoice = $this->makeInvoice(
            ['subtotal' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'total_cost' => null, 'margin' => null],
            ['amount' => '0.00', 'cost_amount' => null, 'unit_price' => '0.00', 'unit_cost' => null],
        );

        $this->actingAs(User::factory()->create())->post(route('invoices.void', $invoice));

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertNull($invoice->pre_void_total);
        $this->assertFalse($invoice->isVoidWithSnapshot());
        $this->assertNull($invoice->lines()->first()->pre_void_amount);
    }

    public function test_void_service_is_idempotent(): void
    {
        $invoice = $this->makeInvoice();
        $service = app(InvoiceVoidService::class);

        $service->void($invoice);
        $service->void($invoice->fresh());

        // The second call must not overwrite the snapshot with zeros.
        $this->assertZeroedWithSnapshot($invoice->fresh());
    }

    public function test_void_preserves_null_cost_fields(): void
    {
        $invoice = $this->makeInvoice(
            ['total_cost' => null, 'margin' => null],
            ['cost_amount' => null, 'unit_cost' => null],
        );

        app(InvoiceVoidService::class)->void($invoice);

        $invoice = $invoice->fresh();
        $this->assertSame('0.00', $invoice->total);
        $this->assertNull($invoice->total_cost);
        $this->assertNull($invoice->margin);
        $this->assertNull($invoice->pre_void_total_cost);

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertNull($line->cost_amount);
        $this->assertNull($line->pre_void_cost_amount);
    }

    // ── QBO → PSA void detection ──

    private function qboInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'Id' => '9001',
            'SyncToken' => '3',
            'TotalAmt' => 0,
            'Balance' => 0,
            'PrivateNote' => 'Voided',
            'Line' => [],
        ], $overrides);
    }

    private function syncFromQbo(Invoice $invoice, array $payload): void
    {
        $this->mock(QboClient::class, function (MockInterface $m) use ($invoice, $payload): void {
            $m->shouldReceive('get')
                ->with("invoice/{$invoice->qbo_invoice_id}")
                ->andReturn(['Invoice' => $payload]);
        });

        // Resolve the service AFTER mocking so it receives the mocked client.
        app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);
    }

    public function test_qbo_void_with_exact_private_note_zeroes_and_snapshots(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '9001']);

        $this->syncFromQbo($invoice, $this->qboInvoicePayload());

        $invoice = $invoice->fresh();
        $this->assertZeroedWithSnapshot($invoice);
        $this->assertNotNull($invoice->qbo_synced_at);
    }

    public function test_qbo_void_detected_when_private_note_has_prior_memo(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '9001']);

        $this->syncFromQbo($invoice, $this->qboInvoicePayload([
            'PrivateNote' => "Client asked for rebill under new contract\nVoided",
        ]));

        $this->assertZeroedWithSnapshot($invoice->fresh());
    }

    public function test_qbo_memo_mentioning_voided_on_live_invoice_is_not_treated_as_void(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '9001']);

        $this->syncFromQbo($invoice, $this->qboInvoicePayload([
            'PrivateNote' => 'Voided the old quote, this invoice replaces it',
            'TotalAmt' => 540.0,
            'Balance' => 540.0,
        ]));

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Posted, $invoice->status);
        $this->assertSame('540.00', $invoice->total);
        $this->assertNull($invoice->pre_void_total);
    }

    // ── Prepay reversal still works (ledger-based, unaffected by zeroing) ──

    public function test_void_reverses_prepay_deposit_after_amounts_are_zeroed(): void
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

        // Paid → observer deposits 2h of prepaid time.
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $this->assertEquals(2.0, (float) $contract->fresh()->prepay_balance);

        $this->actingAs(User::factory()->create())->post(route('invoices.void', $invoice));

        $this->assertZeroedWithSnapshot($invoice->fresh());
        $this->assertEquals(0.0, (float) $contract->fresh()->prepay_balance);
        $this->assertTrue(
            PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceReversal)
                ->exists()
        );
    }

    // ── Stripe import of voided invoices ──

    public function test_stripe_import_zeroes_and_snapshots_voided_invoice(): void
    {
        Client::factory()->create(['stripe_customer_id' => 'cus_test_void']);

        $stripePayload = [
            'data' => [[
                'id' => 'in_test_void',
                'number' => 'STRIPE-VOID-1',
                'status' => 'void',
                'customer' => 'cus_test_void',
                'created' => now()->subDays(3)->timestamp,
                'subtotal' => 50000,
                'tax' => 4000,
                'total' => 54000,
                'lines' => [
                    'data' => [[
                        'description' => 'Voided service',
                        'quantity' => 1,
                        'amount' => 50000,
                        'price' => ['unit_amount' => 50000, 'product' => null],
                    ]],
                    'has_more' => false,
                ],
            ]],
            'has_more' => false,
        ];

        $stripeClient = \Mockery::mock(StripeClient::class);
        $stripeClient->shouldReceive('listInvoices')->andReturn($stripePayload);

        (new StripeSyncService($stripeClient))->importInvoicesFromStripe();

        $invoice = Invoice::where('stripe_invoice_id', 'in_test_void')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertSame('0.00', $invoice->total);
        $this->assertSame('540.00', $invoice->pre_void_total);
        $this->assertSame('500.00', $invoice->pre_void_subtotal);

        $line = $invoice->lines()->first();
        $this->assertSame('0.00', $line->amount);
        $this->assertSame('500.00', $line->pre_void_amount);
    }

    // ── Stripe status pull → PSA void detection ──

    private function syncStatusFromStripe(Invoice $invoice, array $payload): void
    {
        $stripeClient = \Mockery::mock(StripeClient::class);
        $stripeClient->shouldReceive('getInvoice')
            ->with($invoice->stripe_invoice_id)
            ->andReturn($payload);

        // Construct with the mocked client so the internal void-service
        // resolution still uses the real container binding.
        (new StripeSyncService($stripeClient))->syncInvoiceStatusFromStripe($invoice);
    }

    public function test_stripe_status_pull_void_zeroes_and_snapshots(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_status_void',
            'status' => InvoiceStatus::Synced,
        ]);

        // Stripe retains the original amounts on a voided invoice; the status
        // pull must route through the void service (zero + snapshot), not copy
        // the retained tax/total back onto a live-looking Synced invoice.
        $this->syncStatusFromStripe($invoice, [
            'id' => 'in_status_void',
            'status' => 'void',
            'tax' => 4000,
            'total' => 54000,
        ]);

        $invoice = $invoice->fresh();
        $this->assertZeroedWithSnapshot($invoice);
        $this->assertNotNull($invoice->stripe_synced_at);
    }

    public function test_stripe_status_pull_uncollectible_zeroes_and_snapshots(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_status_uncoll',
            'status' => InvoiceStatus::Synced,
        ]);

        $this->syncStatusFromStripe($invoice, [
            'id' => 'in_status_uncoll',
            'status' => 'uncollectible',
            'tax' => 4000,
            'total' => 54000,
        ]);

        $this->assertZeroedWithSnapshot($invoice->fresh());
    }

    public function test_stripe_status_pull_paid_marks_paid_not_void(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_status_paid',
            'status' => InvoiceStatus::Synced,
        ]);

        $this->syncStatusFromStripe($invoice, [
            'id' => 'in_status_paid',
            'status' => 'paid',
            'tax' => 4000,
            'total' => 54000,
        ]);

        $invoice = $invoice->fresh();
        // A paid invoice keeps its amounts — the void path must not fire.
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNull($invoice->pre_void_total);
        $this->assertSame('540.00', $invoice->total);
    }

    public function test_stripe_status_pull_open_invoice_is_not_voided(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_status_open',
            'status' => InvoiceStatus::Synced,
        ]);

        $this->syncStatusFromStripe($invoice, [
            'id' => 'in_status_open',
            'status' => 'open',
            'tax' => 4000,
            'total' => 54000,
        ]);

        $invoice = $invoice->fresh();
        // Still awaiting payment — neither paid nor void.
        $this->assertSame(InvoiceStatus::Synced, $invoice->status);
        $this->assertNull($invoice->pre_void_total);
    }

    // ── Backfill migration for pre-existing voided invoices ──

    public function test_backfill_migration_snapshots_and_zeroes_legacy_void_invoices(): void
    {
        // Legacy voided invoice carrying amounts (pre-change behavior).
        $legacyVoid = $this->makeInvoice(['status' => InvoiceStatus::Void]);
        // Voided invoice already at $0 — must NOT gain a zero snapshot.
        $alreadyZero = $this->makeInvoice(
            ['status' => InvoiceStatus::Void, 'subtotal' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'total_cost' => null, 'margin' => null],
            ['amount' => '0.00', 'cost_amount' => null],
        );
        // Live invoice — must be untouched.
        $live = $this->makeInvoice();

        $migration = require database_path('migrations/2026_07_12_000001_add_pre_void_snapshot_to_invoices.php');
        $migration->up();

        $this->assertZeroedWithSnapshot($legacyVoid->fresh());

        $alreadyZero = $alreadyZero->fresh();
        $this->assertNull($alreadyZero->pre_void_total);
        $this->assertSame('0.00', $alreadyZero->total);

        $live = $live->fresh();
        $this->assertSame(InvoiceStatus::Posted, $live->status);
        $this->assertSame('540.00', $live->total);
        $this->assertNull($live->pre_void_total);
        $this->assertSame('500.00', $live->lines()->first()->amount);

        // Re-running must not overwrite the snapshot with zeros.
        $migration->up();
        $this->assertZeroedWithSnapshot($legacyVoid->fresh());
    }

    // ── Display: originals behind an explicit banner ──

    public function test_show_page_renders_void_banner_with_original_amounts(): void
    {
        $invoice = $this->makeInvoice();
        app(InvoiceVoidService::class)->void($invoice);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk();

        $response->assertSee('This invoice is void.');
        $response->assertSee('reportable value is $0.00');
        $response->assertSee('$540.00'); // original total, from the pre_void snapshot
        $response->assertSee('$500.00'); // original subtotal / line amount
    }

    public function test_show_page_has_no_void_banner_for_live_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('This invoice is void.');
    }

    public function test_display_accessors_fall_through_for_live_invoices(): void
    {
        $invoice = $this->makeInvoice();

        $this->assertSame('540.00', $invoice->display_total);
        $this->assertSame('500.00', $invoice->display_subtotal);
        $this->assertSame('500.00', $invoice->lines()->first()->display_amount);
    }

    // ── REQ 2: aggregates exclude voided invoices with no status filter ──

    public function test_profitability_aggregates_exclude_voided_invoices(): void
    {
        $client = Client::factory()->create();
        $kept = $this->makeInvoice(['client_id' => $client->id]);
        $voided = $this->makeInvoice(['client_id' => $client->id]);

        app(InvoiceVoidService::class)->void($voided);

        // ProfitabilityService has no void filter — the zeroing alone must
        // keep the voided invoice out of the totals.
        $result = app(ProfitabilityService::class)->businessProfitability();

        $this->assertEquals(500.0, $result['revenue']);
        $this->assertEquals(200.0, $result['cost']);
        $this->assertEquals(300.0, $result['margin']);
    }
}
