<?php

namespace Tests\Feature\Invoices;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for psa-6bhi: on a narrow (mobile) viewport the invoice
 * detail page pushed content past the right edge — the Invoice Details card
 * truncated the contract name and the Line Items table clipped the
 * Amount/Cost/Margin columns off-screen. The fix wraps the details table in a
 * `.table-responsive` container (mirroring the Line Items table, which was
 * already wrapped) so neither table forces the page wider than the viewport,
 * and lets the header action toolbar wrap.
 */
class InvoiceShowResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function makePaidInvoiceWithLine(): Invoice
    {
        $client = Client::factory()->create();

        $contract = Contract::create([
            'client_id' => $client->id,
            // Intentionally long to reproduce the contract-name clipping.
            'name' => 'Comprehensive Managed Services & Security Agreement — Enterprise Tier',
            'type' => ContractType::Managed->value,
            'status' => ContractStatus::Active->value,
            'start_date' => now()->subYear(),
        ]);

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-RESP-'.rand(1000, 9999),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '1250.00',
            'tax' => '0.00',
            'total' => '1250.00',
            'total_cost' => '400.00',
            'margin' => '850.00',
            'status' => InvoiceStatus::Paid,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed Services — Monthly Recurring (all workstations & servers)',
            'quantity' => '25.00',
            'unit_price' => '50.00',
            'amount' => '1250.00',
            'cost_amount' => '400.00',
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    public function test_invoice_detail_page_renders_for_staff(): void
    {
        $invoice = $this->makePaidInvoiceWithLine();

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('$1,250.00'); // the Amount stays in the markup
    }

    public function test_detail_and_line_item_tables_are_wrapped_for_mobile(): void
    {
        $invoice = $this->makePaidInvoiceWithLine();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->getContent();

        // Both the Invoice Details table and the Line Items table must live in a
        // `.table-responsive` container so they scroll within their card instead
        // of forcing horizontal page overflow (which clipped financial columns).
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($html, 'table-responsive'),
            'Both the details and line-item tables should be wrapped in .table-responsive'
        );
    }

    public function test_header_action_toolbar_wraps_on_mobile(): void
    {
        $invoice = $this->makePaidInvoiceWithLine();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'd-flex flex-wrap gap-2',
            $html,
            'Header action toolbar should be allowed to wrap on narrow viewports'
        );
        $this->assertStringContainsString(
            'd-flex flex-wrap align-items-center justify-content-between',
            $html,
            'Invoice header row should wrap title/actions on narrow viewports'
        );
    }
}
