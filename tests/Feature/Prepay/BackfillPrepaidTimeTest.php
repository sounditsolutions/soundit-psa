<?php

namespace Tests\Feature\Prepay;

use App\Enums\InvoiceStatus;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PrepayTransaction;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPrepaidTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $skuSeq = 0;

    private int $invoiceSeq = 0;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sku(?int $prepaidMinutes): Sku
    {
        return Sku::create([
            'name' => 'Managed Support '.++$this->skuSeq,
            'sku_code' => 'MSP-'.$this->skuSeq,
            'unit_price' => 100,
            'prepaid_time_minutes' => $prepaidMinutes,
        ]);
    }

    private function contract(array $attrs = []): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);

        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ], $attrs));
    }

    private function invoice(Contract $contract, InvoiceStatus $status = InvoiceStatus::Posted, array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $contract->client_id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-'.++$this->invoiceSeq,
            'invoice_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'status' => $status,
        ], $attrs));
    }

    private function line(Invoice $invoice, ?Sku $sku, float $quantity = 3, ?int $prepaidMinutes = null): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sku_id' => $sku?->id,
            'description' => 'Support line',
            'quantity' => $quantity,
            'unit_price' => 100,
            'amount' => round($quantity * 100, 2),
            'prepaid_time_minutes' => $prepaidMinutes,
        ]);
    }

    private function depositCount(Invoice $invoice): int
    {
        return PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Step 1 — line backfill
    // -------------------------------------------------------------------------

    public function test_backfills_prepaid_minutes_from_sku(): void
    {
        $line = $this->line($this->invoice($this->contract()), $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertSame(180, $line->fresh()->prepaid_time_minutes);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $line = $this->line($this->invoice($this->contract()), $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --dry-run')->assertSuccessful();

        $this->assertNull($line->fresh()->prepaid_time_minutes);
    }

    public function test_does_not_clobber_existing_prepaid_minutes(): void
    {
        $line = $this->line($this->invoice($this->contract()), $this->sku(60), quantity: 3, prepaidMinutes: 5);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertSame(5, $line->fresh()->prepaid_time_minutes);
    }

    public function test_skips_lines_without_sku(): void
    {
        $line = $this->line($this->invoice($this->contract()), null, quantity: 3);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertNull($line->fresh()->prepaid_time_minutes);
    }

    public function test_skips_when_sku_has_no_prepaid_config(): void
    {
        $line = $this->line($this->invoice($this->contract()), $this->sku(null), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertNull($line->fresh()->prepaid_time_minutes);
    }

    public function test_truncates_fractional_minutes_like_generation(): void
    {
        // Mirrors BillingService: (int) (quantity * per-unit). 2.5 * 15 = 37.5 -> 37.
        $line = $this->line($this->invoice($this->contract()), $this->sku(15), quantity: 2.5);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertSame(37, $line->fresh()->prepaid_time_minutes);
    }

    // -------------------------------------------------------------------------
    // Halo scoping
    // -------------------------------------------------------------------------

    public function test_skips_halo_invoices_by_default(): void
    {
        $invoice = $this->invoice($this->contract(), attrs: ['halo_id' => 4242]);
        $line = $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertNull($line->fresh()->prepaid_time_minutes);
    }

    public function test_include_halo_flag_processes_halo_invoices(): void
    {
        $invoice = $this->invoice($this->contract(), attrs: ['halo_id' => 4242]);
        $line = $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --include-halo')->assertSuccessful();

        $this->assertSame(180, $line->fresh()->prepaid_time_minutes);
    }

    // -------------------------------------------------------------------------
    // Step 2 — deposit reconciliation
    // -------------------------------------------------------------------------

    public function test_default_run_does_not_create_deposits(): void
    {
        $invoice = $this->invoice($this->contract(), InvoiceStatus::Paid);
        $line = $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time')->assertSuccessful();

        $this->assertSame(180, $line->fresh()->prepaid_time_minutes);
        $this->assertSame(0, $this->depositCount($invoice));
    }

    public function test_deposit_paid_creates_deposit_for_paid_invoice(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract, InvoiceStatus::Paid);
        $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --deposit-paid')->assertSuccessful();

        $this->assertSame(1, $this->depositCount($invoice));

        $deposit = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->firstOrFail();
        $this->assertEqualsWithDelta(3.0, (float) $deposit->hours, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $contract->fresh()->prepay_balance, 0.0001);
    }

    public function test_deposit_paid_dry_run_creates_no_deposit(): void
    {
        $invoice = $this->invoice($this->contract(), InvoiceStatus::Paid);
        $line = $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --deposit-paid --dry-run')->assertSuccessful();

        $this->assertNull($line->fresh()->prepaid_time_minutes);
        $this->assertSame(0, $this->depositCount($invoice));
    }

    public function test_deposit_paid_is_idempotent_when_deposit_exists(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract, InvoiceStatus::Paid);
        $this->line($invoice, $this->sku(60), quantity: 3);

        // A deposit already exists (e.g. created at payment time from a partial line).
        PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::InvoiceDeposit,
            'invoice_id' => $invoice->id,
            'date' => '2026-01-15',
            'hours' => 3.0,
        ]);

        $this->artisan('billing:backfill-prepaid-time --deposit-paid')->assertSuccessful();

        $this->assertSame(1, $this->depositCount($invoice));
    }

    public function test_deposit_paid_skips_unpaid_invoice(): void
    {
        $invoice = $this->invoice($this->contract(), InvoiceStatus::Posted);
        $line = $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --deposit-paid')->assertSuccessful();

        // Line is still backfilled, but no deposit — it will deposit when marked Paid.
        $this->assertSame(180, $line->fresh()->prepaid_time_minutes);
        $this->assertSame(0, $this->depositCount($invoice));
    }

    public function test_deposit_paid_skips_dollar_based_contract(): void
    {
        $contract = $this->contract(['prepay_as_amount' => true]);
        $invoice = $this->invoice($contract, InvoiceStatus::Paid);
        $this->line($invoice, $this->sku(60), quantity: 3);

        $this->artisan('billing:backfill-prepaid-time --deposit-paid')->assertSuccessful();

        $this->assertSame(0, $this->depositCount($invoice));
    }
}
